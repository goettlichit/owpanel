<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Package;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Packages with limits for a whole customer (E-125).
 */
class PackageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * @return array<string, string|int|null>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => ' Webspace S ', 'max_sites' => '1', 'storage_gb' => '5', 'max_databases' => '2',
            'max_cron_jobs' => '', 'min_cron_interval' => '5', 'daily_self_service' => '3',
        ], $overrides);
    }

    public function test_an_admin_creates_a_package_and_empty_limits_are_unlimited(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/packages')->assertOk()->assertSee('Noch keine Pakete.');

        $this->actingAs($admin)->post('/packages', $this->form())
            ->assertRedirect('/packages')->assertSessionHas('status', 'Das Paket Webspace S ist angelegt.');

        $package = Package::sole();
        $this->assertSame('Webspace S', $package->name);
        $this->assertSame([1, 5120, 2, null, 5, 3], array_map(fn ($limit) => $package->{$limit}, Package::LIMITS));
        $this->assertSame('package.created', AuditLog::where('subject_id', $package->id)->sole()->action);

        $this->actingAs($admin)->get('/packages')->assertOk()
            ->assertSee('Webspace S')->assertSee('5,0 GB')->assertSee('5 Min.')->assertSee('unbegrenzt');
    }

    public function test_storage_accepts_half_gigabytes_and_the_form_checks_its_input(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/packages', $this->form(['storage_gb' => '0.5']))->assertRedirect('/packages');
        $this->assertSame(512, Package::sole()->storage_mb);

        $this->actingAs($admin)->post('/packages', $this->form([
            'name' => 'Webspace S', 'max_sites' => '-1', 'storage_gb' => '1.25', 'min_cron_interval' => '0', 'daily_self_service' => 'viele',
        ]))->assertSessionHasErrors(['name', 'max_sites', 'storage_gb', 'min_cron_interval', 'daily_self_service']);
        $this->assertSame(1, Package::count());
    }

    public function test_an_admin_edits_a_package_and_the_audit_log_names_the_changed_limits(): void
    {
        $admin = $this->admin();
        $package = Package::factory()->create(['name' => 'Webspace S']);

        $this->actingAs($admin)->get("/packages/{$package->id}")->assertOk()->assertSee('value="5"', false);
        $this->actingAs($admin)->put("/packages/{$package->id}", $this->form(['name' => 'Webspace M', 'max_sites' => '3', 'storage_gb' => '5', 'max_databases' => '1', 'max_cron_jobs' => '1', 'daily_self_service' => '2']))
            ->assertRedirect("/packages/{$package->id}");

        $package->refresh();
        $this->assertSame(['Webspace M', 3], [$package->name, $package->max_sites]);
        $entry = AuditLog::where('action', 'package.updated')->sole();
        $this->assertSame(['name', 'max_sites'], $entry->context['changed']);
    }

    public function test_a_package_in_use_is_not_deleted(): void
    {
        $admin = $this->admin();
        $package = Package::factory()->create();
        $customer = Customer::factory()->create();
        $customer->package()->associate($package)->save();

        $this->actingAs($admin)->get("/packages/{$package->id}")->assertOk()
            ->assertSee($customer->display_number)->assertSee('Ein Paket, das Kunden haben, lässt sich nicht löschen.');
        $this->actingAs($admin)->delete("/packages/{$package->id}")->assertForbidden();
        $this->assertTrue($package->exists());

        $customer->package()->dissociate()->save();
        $this->actingAs($admin)->delete("/packages/{$package->id}")->assertRedirect('/packages');
        $this->assertSame(0, Package::count());
        $this->assertSame(1, AuditLog::where('action', 'package.deleted')->count());
    }

    public function test_a_package_is_assigned_on_the_customer_page_and_when_creating_a_customer(): void
    {
        $admin = $this->admin();
        $small = Package::factory()->create(['name' => 'Webspace S']);
        $large = Package::factory()->create(['name' => 'Webspace L']);

        $this->actingAs($admin)->post('/customers', [
            'first_name' => 'Anna', 'last_name' => 'Müller', 'email' => 'anna@example.com', 'locale' => 'de', 'package_id' => $small->id,
        ]);
        $customer = Customer::sole();
        $this->assertSame($small->id, $customer->package_id);

        $this->actingAs($admin)->patch("/customers/{$customer->id}/package", ['package_id' => $large->id])
            ->assertRedirect("/customers/{$customer->id}/package")->assertSessionHas('status', 'Der Kunde hat jetzt das Paket Webspace L.');
        $this->assertSame($large->id, $customer->refresh()->package_id);

        $this->actingAs($admin)->patch("/customers/{$customer->id}/package", ['package_id' => ''])
            ->assertSessionHas('status', 'Der Kunde hat jetzt kein Paket mehr.');
        $this->assertNull($customer->refresh()->package_id);

        $this->actingAs($admin)->patch("/customers/{$customer->id}/package", ['package_id' => '01JUNKNOWNPACKAGE0000000000'])
            ->assertSessionHasErrors('package_id');

        $changes = AuditLog::where('action', 'customer.package_changed')->orderBy('created_at')->orderBy('id')->get()->pluck('context');
        $this->assertSame([[null, 'Webspace S'], ['Webspace S', 'Webspace L'], ['Webspace L', null]],
            $changes->map(fn ($context) => [$context['old'], $context['new']])->all());
    }

    public function test_the_customer_page_counts_what_the_customer_has_against_the_package(): void
    {
        $admin = $this->admin();
        $package = Package::factory()->create(['max_sites' => 1, 'max_databases' => 1, 'max_cron_jobs' => null]);
        $customer = Customer::factory()->create();

        $this->actingAs($admin)->get("/customers/{$customer->id}/package")->assertOk()
            ->assertSee('ohne Paket')->assertSee('kann er deshalb selbst nichts anlegen');
        $this->actingAs($admin)->get('/customers')->assertOk()->assertSee('ohne Paket');

        $customer->package()->associate($package)->save();
        $first = Site::factory()->create(['customer_id' => $customer->id]);
        Database::factory()->create(['site_id' => $first->id]);
        $first->cronJobs()->create(['schedule' => '*/5 * * * *', 'command' => 'php cron.php']);

        $this->assertSame(['sites' => 1, 'databases' => 1, 'cron_jobs' => 1], $customer->usage());
        $this->assertSame([], $customer->exceededLimits());
        $this->actingAs($admin)->get("/customers/{$customer->id}/package")->assertOk()->assertDontSee('über der Grenze');

        // An administrator went beyond the limit: shown, nothing removed.
        $second = Site::factory()->create(['customer_id' => $customer->id]);
        Database::factory()->create(['site_id' => $second->id]);
        $this->assertSame(['max_sites', 'max_databases'], $customer->exceededLimits());
        $this->actingAs($admin)->get("/customers/{$customer->id}/package")->assertOk()
            ->assertSee('über der Grenze')->assertSee('Gelöscht wird nichts');

        // Websites of other customers do not count.
        Site::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->assertSame(2, $customer->usage()['sites']);
    }

    public function test_customers_cannot_see_or_change_packages(): void
    {
        $package = Package::factory()->create();
        $own = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $own->id]);

        $this->actingAs($user)->get('/packages')->assertForbidden();
        $this->actingAs($user)->get('/packages/create')->assertForbidden();
        $this->actingAs($user)->post('/packages', $this->form())->assertForbidden();
        $this->actingAs($user)->get("/packages/{$package->id}")->assertForbidden();
        $this->actingAs($user)->put("/packages/{$package->id}", $this->form())->assertForbidden();
        $this->actingAs($user)->delete("/packages/{$package->id}")->assertForbidden();
        $this->actingAs($user)->patch("/customers/{$own->id}/package", ['package_id' => $package->id])->assertForbidden();

        $this->assertNull($own->refresh()->package_id);
        $this->assertSame(1, Package::count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'package.%')->count());
    }
}
