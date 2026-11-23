<?php

namespace Tests\Feature;

use App\Customers\PackageLimits;
use App\Http\Controllers\Concerns\ChecksPackageLimits;
use App\Models\AuditLog;
use App\Models\CronJob;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Package;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * Package limits and who a website belongs to (E-125, E-139).
 */
class PackageLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ScriptRunner::class, new FakeScriptRunner(['{"ok":true}'."\n"]));
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * A customer with an hourly package, one cron job and one database.
     */
    private function customerSite(array $limits = []): Site
    {
        $package = Package::factory()->create($limits + ['min_cron_interval' => 60, 'max_cron_jobs' => 2, 'max_databases' => 1, 'max_sites' => 1]);
        $customer = Customer::factory()->create();
        $customer->package()->associate($package)->save();

        return Site::factory()->for($customer)->create(['system_user' => 'ow38217', 'domain' => 'shop.example.de']);
    }

    public function test_the_shortest_interval_of_a_schedule(): void
    {
        $cases = [
            '* * * * *' => 1, '*/5 * * * *' => 5, '0 * * * *' => 60, '0,30 * * * *' => 30, '5/10 * * * *' => 10,
            '0 3 * * *' => 1440, '0 23,1 * * *' => 120, '0 3 * * 1' => 10080, '0 3 * * 1-5' => 1440,
            '0 3 1,15 * 1' => 1440, '0 3 31 2 *' => null,
        ];
        foreach ($cases as $schedule => $minutes) {
            $this->assertSame($minutes, CronJob::shortestInterval($schedule), $schedule);
        }
    }

    public function test_an_admin_confirms_a_cron_job_beyond_the_package_and_it_is_audited(): void
    {
        $site = $this->customerSite();
        $admin = $this->admin();
        $form = ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run', 'description' => ''];

        $this->actingAs($admin)->from("/sites/{$site->id}/cron")->post("/sites/{$site->id}/cron", $form)
            ->assertRedirect("/sites/{$site->id}/cron")
            ->assertSessionHas('limit_confirm', fn (array $confirm) => $confirm['broken'] === ['Das Paket verlangt mindestens 60 Min. Abstand zwischen zwei Läufen eines Cronjobs.']
                && $confirm['input']['schedule'] === '*/5 * * * *' && $confirm['method'] === 'POST');
        $this->assertSame(0, CronJob::count());

        // The banner sends the same form again.
        $this->actingAs($admin)->withSession(['limit_confirm' => session('limit_confirm')])->get("/sites/{$site->id}/cron")->assertOk()
            ->assertSee('Grenze des Pakets überschritten')
            ->assertSee('name="exceed_limits"', false)
            ->assertSee('value="*/5 * * * *"', false)
            ->assertSee('alle 5 Minuten – über der Paketgrenze');

        $this->actingAs($admin)->post("/sites/{$site->id}/cron", $form + ['exceed_limits' => '1'])
            ->assertRedirect("/sites/{$site->id}/cron")->assertSessionHas('status');
        $this->assertSame('*/5 * * * *', CronJob::sole()->schedule);

        $entry = AuditLog::where('action', 'package.limit_exceeded')->sole();
        $this->assertSame($site->customer_id, $entry->subject_id);
        $this->assertSame(['domain' => 'shop.example.de', 'action' => 'cron_created', 'limits' => ['min_cron_interval']], $entry->context);

        $this->actingAs($admin)->get("/sites/{$site->id}/cron")->assertOk()->assertSee('über der Paketgrenze');
    }

    public function test_a_cron_job_within_the_package_needs_no_confirmation(): void
    {
        $site = $this->customerSite();

        $this->actingAs($this->admin())->post("/sites/{$site->id}/cron", ['schedule' => '0 * * * *', 'command' => 'php cron.php'])
            ->assertSessionHas('status')->assertSessionMissing('limit_confirm');
        $this->assertSame(1, CronJob::count());
        $this->assertSame(0, AuditLog::where('action', 'package.limit_exceeded')->count());
    }

    public function test_the_number_of_cron_jobs_counts_over_all_websites_of_the_customer(): void
    {
        $site = $this->customerSite();
        $other = Site::factory()->for($site->customer)->create();
        $other->cronJobs()->create(['schedule' => '0 * * * *', 'command' => 'a', 'enabled' => true]);
        $site->cronJobs()->create(['schedule' => '0 * * * *', 'command' => 'b', 'enabled' => true]);

        $this->actingAs($this->admin())->post("/sites/{$site->id}/cron", ['schedule' => '0 * * * *', 'command' => 'c'])
            ->assertSessionHas('limit_confirm', fn (array $confirm) => $confirm['broken'] === ['Das Paket erlaubt höchstens 2 Cronjobs.']);
        $this->assertSame(2, CronJob::count());
    }

    public function test_changing_the_schedule_is_checked_but_not_the_command(): void
    {
        $site = $this->customerSite();
        $job = $site->cronJobs()->create(['schedule' => '*/5 * * * *', 'command' => 'old', 'enabled' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->put("/sites/{$site->id}/cron/{$job->id}", ['edit_schedule' => '*/5 * * * *', 'edit_command' => 'new'])
            ->assertSessionMissing('limit_confirm');
        $this->assertSame('new', $job->fresh()->command);

        $job->update(['schedule' => '0 * * * *']);
        $this->actingAs($admin)->put("/sites/{$site->id}/cron/{$job->id}", ['edit_schedule' => '* * * * *', 'edit_command' => 'new'])
            ->assertSessionHas('limit_confirm', fn (array $confirm) => $confirm['method'] === 'PUT');
        $this->assertSame('0 * * * *', $job->fresh()->schedule);
    }

    public function test_websites_of_the_administration_and_unassigned_ones_have_no_limits(): void
    {
        $admin = $this->admin();
        $own = Site::factory()->create(['admin_owned' => true]);
        $loose = Site::factory()->create();

        $this->actingAs($admin)->get("/sites/{$own->id}/cron")->assertOk()
            ->assertDontSee('über der Paketgrenze')->assertDontSee('kein Paket gebucht');
        $this->actingAs($admin)->get("/sites/{$loose->id}/cron")->assertOk()
            ->assertSee('Die Webseite ist nicht zugeordnet, es ist kein Paket gebucht.');

        foreach ([$own, $loose] as $site) {
            $this->actingAs($admin)->post("/sites/{$site->id}/cron", ['schedule' => '* * * * *', 'command' => 'php x.php'])
                ->assertSessionHas('status')->assertSessionMissing('limit_confirm');
        }
        $this->assertSame(2, CronJob::count());
    }

    public function test_a_customer_without_package_gets_a_note(): void
    {
        $site = Site::factory()->for(Customer::factory())->create();

        $this->actingAs($this->admin())->get("/sites/{$site->id}/cron")->assertOk()
            ->assertSee('Für den Kunden ist kein Paket gebucht.');
    }

    public function test_databases_are_limited(): void
    {
        $site = $this->customerSite();
        Database::factory()->create(['site_id' => $site->id, 'server_id' => $site->server_id]);

        $this->actingAs($this->admin())->get("/sites/{$site->id}/databases")->assertOk()->assertSee('1 von 1 Datenbanken');
        $this->actingAs($this->admin())->post("/sites/{$site->id}/databases", ['suffix' => 'shop'])
            ->assertSessionHas('limit_confirm', fn (array $confirm) => $confirm['broken'] === ['Das Paket erlaubt höchstens 1 Datenbanken.']);
        $this->assertSame(1, Database::count());
    }

    public function test_a_new_website_for_a_customer_is_limited(): void
    {
        $site = $this->customerSite();
        Server::query()->where('id', '!=', $site->server_id)->delete();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sites', ['domain' => 'zwei.example.de', 'customer' => $site->customer_id])
            ->assertSessionHas('limit_confirm', fn (array $confirm) => $confirm['broken'] === ['Das Paket erlaubt höchstens 1 Webseiten.']);
        $this->assertSame(1, Site::count());

        $this->actingAs($admin)->post('/sites', ['domain' => 'zwei.example.de', 'customer' => $site->customer_id, 'exceed_limits' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame($site->customer_id, Site::where('domain', 'zwei.example.de')->sole()->customer_id);
    }

    public function test_a_website_is_assigned_to_the_administration_a_customer_or_nobody(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($admin)->get("/sites/{$site->id}/domain")->assertOk()
            ->assertSeeInOrder(['Nicht zugeordnet', 'Administration', 'Kunden']);

        $this->actingAs($admin)->patch("/sites/{$site->id}/customer", ['customer' => 'admin'])
            ->assertSessionHas('status', 'Die Webseite gehört jetzt der Administration.');
        $this->assertSame([null, true, 'admin'], [$site->fresh()->customer_id, $site->fresh()->admin_owned, $site->fresh()->owner()]);

        $this->actingAs($admin)->patch("/sites/{$site->id}/customer", ['customer' => $customer->id]);
        $this->assertSame([$customer->id, false], [$site->fresh()->customer_id, $site->fresh()->admin_owned]);

        $this->actingAs($admin)->patch("/sites/{$site->id}/customer", ['customer' => ''])
            ->assertSessionHas('status', 'Die Webseite ist jetzt nicht zugeordnet.');
        $this->assertSame('unassigned', $site->fresh()->owner());

        $this->actingAs($admin)->patch("/sites/{$site->id}/customer", ['customer' => 'nobody'])->assertSessionHasErrors('customer');

        $this->assertSame('admin', AuditLog::where('action', 'site.customer_assigned')->oldest('id')->first()->context['owner']);
    }

    public function test_moving_a_website_brings_its_cron_jobs_and_databases_along(): void
    {
        $target = $this->customerSite(['max_sites' => 5, 'max_cron_jobs' => 5]);
        $site = Site::factory()->create();
        $site->cronJobs()->create(['schedule' => '* * * * *', 'command' => 'a', 'enabled' => true]);
        Database::factory()->create(['site_id' => $target->id, 'server_id' => $target->server_id]);
        Database::factory()->create(['site_id' => $site->id, 'server_id' => $site->server_id]);

        $this->assertSame(['max_databases', 'min_cron_interval'], array_keys(PackageLimits::forSite($target->customer, $site)));
        // A website that already belongs to the customer counts nothing.
        $this->assertSame([], PackageLimits::forSite($target->customer, $target));

        $this->actingAs($this->admin())->patch("/sites/{$site->id}/customer", ['customer' => $target->customer_id])
            ->assertSessionHas('limit_confirm');
        $this->assertNull($site->fresh()->customer_id);
    }

    public function test_the_list_shows_administration_unassigned_and_customers_in_groups(): void
    {
        $customerSite = $this->customerSite();
        Site::factory()->create(['domain' => 'aaa.example.de', 'admin_owned' => true]);
        Site::factory()->create(['domain' => 'zzz.example.de']);

        $this->actingAs($this->admin())->get('/sites')->assertOk()
            ->assertSeeInOrder(['aaa.example.de', 'Administration', 'table-group-divider', 'zzz.example.de', 'Nicht zugeordnet', 'table-group-divider', 'shop.example.de', $customerSite->customer->display_name], false);
    }

    public function test_customers_are_refused_instead_of_asked(): void
    {
        $check = new class
        {
            use ChecksPackageLimits;

            public function run(Request $request, ?Customer $customer, array $broken)
            {
                return $this->checkPackageLimits($request, $customer, $broken, 'test');
            }
        };
        $customerUser = User::factory()->create();
        $request = Request::create('/x', 'POST', ['exceed_limits' => '1']);
        $request->setUserResolver(fn () => $customerUser);
        $customer = $this->customerSite()->customer;

        $this->assertNull($check->run($request, $customer, []));

        // Customers cannot confirm their way past a limit.
        $response = $check->run($request, $customer, ['max_cron_jobs' => 'Zu viele.']);
        $this->assertSame('Zu viele.', $response->getSession()?->get('error') ?? session('error'));
        $this->assertSame(0, AuditLog::where('action', 'package.limit_exceeded')->count());

        // Without a package a customer creates nothing.
        $customer->package()->dissociate()->save();
        $check->run($request, $customer->fresh(), []);
        $this->assertSame('Für Ihr Konto ist kein Paket gebucht. Bitte wenden Sie sich an uns.', session('error'));
    }

    public function test_schedule_allowed_follows_the_package(): void
    {
        $site = $this->customerSite();
        $job = $site->cronJobs()->create(['schedule' => '*/5 * * * *', 'command' => 'a', 'enabled' => true]);

        $this->assertFalse(PackageLimits::scheduleAllowed($site->customer->package, $job->schedule));
        $this->assertTrue(PackageLimits::scheduleAllowed(null, $job->schedule));
    }
}
