<?php

namespace Tests\Feature;

use App\Customers\PackageLimits;
use App\Customers\StorageQuota;
use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Jobs\MeasureStorage;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Notify\AlertChecker;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * Storage of websites and customers (E-126, E-149).
 */
class StorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private const MIB = 1024 * 1024;

    /**
     * A runner that answers site-usage with the given websites and
     * site-quota with success.
     *
     * @param  list<array<string, mixed>>  $sites
     */
    private function runner(array $sites, bool $quota = true): ScriptRunner
    {
        return new class($sites, $quota) implements ScriptRunner
        {
            /** @var list<ScriptCall> */
            public array $calls = [];

            public function __construct(private array $sites, private bool $quota) {}

            public function run(Server $server, ScriptCall $call, callable $onOutput): ScriptResult
            {
                $this->calls[] = $call;
                $output = $call->script === ServerScript::SiteUsage
                    ? json_encode(['ok' => true, 'script' => 'site-usage', 'quota' => $this->quota, 'filesystem' => $this->quota ? 'ext4' : 'xfs', 'sites' => $this->sites])
                    : json_encode(['ok' => true, 'script' => $call->script->value]);
                $onOutput($output."\n");

                return ScriptResult::fromOutput($call->script->value, $output."\n", 0, false);
            }
        };
    }

    private function customer(?int $storageMb = 100): Customer
    {
        $package = Package::factory()->create(['storage_mb' => $storageMb, 'max_databases' => 5]);
        $customer = Customer::factory()->create(['company' => 'Bäckerei Huber']);
        $customer->package()->associate($package)->save();

        return $customer;
    }

    private function measured(Site $site, int $filesMib, int $dbMib): Site
    {
        $site->forceFill(['usage_files_bytes' => $filesMib * self::MIB, 'usage_db_bytes' => $dbMib * self::MIB, 'usage_measured_at' => now()])->save();

        return $site;
    }

    public function test_the_script_calls_check_their_arguments(): void
    {
        $this->assertSame(['--user', 'ow38217', '--kib', '2048'], ScriptCall::siteQuota('ow38217', 2048)->arguments);
        $this->assertSame([], ScriptCall::siteUsage()->arguments);
        foreach ([['ow10000', 1], ['ow38217', -1], ['root', 1], ['ow38217', 17_179_869_185]] as [$user, $kib]) {
            try {
                ScriptCall::siteQuota($user, $kib);
                $this->fail("{$user} {$kib}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_free_space_is_shared_and_the_limit_never_falls_below_the_files(): void
    {
        $customer = $this->customer(100);
        $shop = $this->measured(Site::factory()->for($customer)->create(), 30, 10);
        $blog = $this->measured(Site::factory()->for($customer)->create(), 20, 0);
        $customer->load('sites');

        $this->assertSame(60 * self::MIB, StorageQuota::usedBytes($customer));
        // Own files plus the 40 MiB the customer still has free.
        $this->assertSame(70 * 1024, StorageQuota::targetKib($shop->fresh()));
        $this->assertSame(60 * 1024, StorageQuota::targetKib($blog->fresh()));

        // Over the limit: only what the website has now, nothing more.
        $this->measured($shop, 95, 10);
        $this->assertSame(95 * 1024, StorageQuota::targetKib($shop->fresh()));
        $this->assertTrue(StorageQuota::full($customer->fresh()));

        // Without a limit, for the administration and unassigned websites: none.
        $this->assertSame(0, StorageQuota::targetKib($this->measured(Site::factory()->for($this->customer(null))->create(), 5, 0)));
        $this->assertSame(0, StorageQuota::targetKib($this->measured(Site::factory()->create(['admin_owned' => true]), 5, 0)));
        $this->assertSame(0, StorageQuota::targetKib($this->measured(Site::factory()->create(), 5, 0)));
    }

    public function test_the_job_stores_the_usage_and_sets_only_changed_limits(): void
    {
        $customer = $this->customer(100);
        $server = Server::factory()->create();
        $shop = Site::factory()->for($customer)->for($server)->create(['system_user' => 'ow38217']);
        $done = Site::factory()->for($customer)->for($server)->create(['system_user' => 'ow38218']);
        $own = Site::factory()->for($server)->create(['system_user' => 'ow38219', 'admin_owned' => true]);
        Site::factory()->create(['system_user' => 'ow49999']);

        $runner = $this->runner([
            ['user' => 'ow38217', 'files' => 30 * self::MIB, 'databases' => 10 * self::MIB, 'limit' => 0],
            // Already at its target: 20 MiB files + 40 MiB free.
            ['user' => 'ow38218', 'files' => 20 * self::MIB, 'databases' => 0, 'limit' => 60 * self::MIB],
            ['user' => 'ow38219', 'files' => 5 * self::MIB, 'databases' => 0, 'limit' => 0],
            // Another server's website and nonsense change nothing.
            ['user' => 'ow49999', 'files' => 1, 'databases' => 1, 'limit' => 0],
            ['user' => 'ow38217', 'files' => 'viel'],
        ]);

        (new MeasureStorage($server->id))->handle($runner);

        $this->assertSame([30 * self::MIB, 10 * self::MIB], [$shop->fresh()->usage_files_bytes, $shop->fresh()->usage_db_bytes]);
        $this->assertNotNull($own->fresh()->usage_measured_at);
        $this->assertTrue($server->fresh()->quota_active);
        $this->assertSame('ext4', $server->fresh()->storage_filesystem);
        // Only the shop gets a limit: 30 MiB files + 40 MiB free.
        $quotaCalls = array_values(array_filter($runner->calls, fn (ScriptCall $call) => $call->script === ServerScript::SiteQuota));
        $this->assertCount(1, $quotaCalls);
        $this->assertSame(['--user', 'ow38217', '--kib', (string) (70 * 1024)], $quotaCalls[0]->arguments);
        $this->assertSame(70 * 1024, $shop->fresh()->quota_kib);
        $this->assertSame(60 * 1024, $done->fresh()->quota_kib);
        $this->assertNull(Site::where('system_user', 'ow49999')->sole()->usage_measured_at);
    }

    public function test_without_quotas_or_while_a_task_runs_no_limit_is_set(): void
    {
        $customer = $this->customer(100);
        $site = Site::factory()->for($customer)->create(['system_user' => 'ow38217']);
        $entry = [['user' => 'ow38217', 'files' => 30 * self::MIB, 'databases' => 0, 'limit' => 0]];

        $runner = $this->runner($entry, quota: false);
        (new MeasureStorage($site->server_id))->handle($runner);
        $this->assertCount(1, $runner->calls);
        $this->assertFalse($site->server->fresh()->quota_active);
        $this->assertSame(30 * self::MIB, $site->fresh()->usage_files_bytes);

        // A restore lifts the limit on the server; the panel waits for it.
        ServerTask::factory()->create(['server_id' => $site->server_id, 'site_id' => $site->id, 'script' => ServerScript::SiteRestore, 'status' => TaskStatus::Running]);
        $runner = $this->runner($entry);
        (new MeasureStorage($site->server_id))->handle($runner);
        $this->assertCount(1, $runner->calls);
    }

    public function test_a_failed_measurement_changes_nothing(): void
    {
        $site = $this->measured(Site::factory()->create(['system_user' => 'ow38217']), 3, 0);
        $runner = new FakeScriptRunner(['{"ok":false,"script":"site-usage","error":"failed"}'."\n"], exitCode: 1);
        (new MeasureStorage($site->server_id))->handle($runner);
        $this->assertSame(3 * self::MIB, $site->fresh()->usage_files_bytes);

        $runner = new FakeScriptRunner(connectionError: 'unreachable');
        (new MeasureStorage($site->server_id))->handle($runner);
        $this->assertSame(3 * self::MIB, $site->fresh()->usage_files_bytes);
    }

    public function test_the_command_runs_every_server_with_websites(): void
    {
        Queue::fake();
        $with = Site::factory()->create()->server;
        Server::factory()->create();

        $this->artisan('owpanel:storage')->assertSuccessful();

        Queue::assertPushed(MeasureStorage::class, 1);
        Queue::assertPushed(MeasureStorage::class, fn (MeasureStorage $job) => $job->serverId === $with->id);
    }

    public function test_the_customer_sees_his_storage_and_not_that_of_others(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $customer = $this->customer(100);
        $user = User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);
        $site = $this->measured(Site::factory()->for($customer)->create(['domain' => 'meine.example']), 40, 10);
        $foreign = $this->measured(Site::factory()->for($this->customer(100))->create(['domain' => 'fremde.example']), 77, 0);

        $this->actingAs($user)->get('/my/sites')->assertOk()
            ->assertSee('Speicherplatz')
            ->assertSee('50,0 MB von 100,0 MB belegt')
            ->assertSee('50,0 MB frei')
            ->assertSee('Dateien 40,0 MB, Datenbanken 10,0 MB')
            ->assertSee('Dateien und Datenbanken aller Ihrer Webseiten zählen zusammen.')
            ->assertDontSee('fast voll')
            ->assertDontSee('fremde.example')
            ->assertDontSee('77,0 MB');

        $this->actingAs($user)->get("/my/sites/{$site->id}")->assertOk()->assertSee('50,0 MB');
        $this->actingAs($user)->get("/my/sites/{$foreign->id}")->assertForbidden();

        $this->measured($site, 85, 10);
        $this->actingAs($user)->get('/my/sites')->assertOk()->assertSee('fast voll')->assertSee('bg-orange', false);
        $this->measured($site, 95, 10);
        $this->actingAs($user)->get('/my/sites')->assertOk()->assertSee('Ihr Speicherplatz ist voll.')->assertSee('bg-red', false);
    }

    public function test_a_full_customer_creates_no_database_and_imports_nothing(): void
    {
        $directory = sys_get_temp_dir().'/owpanel-transfers-'.bin2hex(random_bytes(4));
        config(['owpanel.transfers.path' => $directory]);
        $this->app->instance(ScriptRunner::class, new FakeScriptRunner(['{"ok":true}'."\n"]));
        Queue::fake();
        $customer = $this->customer(100);
        $user = User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);
        $site = $this->measured(Site::factory()->for($customer)->create(['system_user' => 'ow38217']), 90, 20);
        $database = Database::factory()->create(['site_id' => $site->id, 'server_id' => $site->server_id, 'name' => 'ow38217_shop']);

        $this->assertArrayHasKey('storage_mb', PackageLimits::forDatabase($site));
        $this->actingAs($user)->get("/my/sites/{$site->id}/databases")->assertOk()
            ->assertSee('Der Speicherplatz Ihres Pakets ist aufgebraucht.')
            ->assertSee('<fieldset disabled', false);
        $this->actingAs($user)->post("/my/sites/{$site->id}/databases", ['suffix' => 'blog'])
            ->assertSessionHas('error', 'Der Speicherplatz des Pakets (100,0 MB) ist aufgebraucht.');

        $this->actingAs($user)->get("/my/databases/{$database->id}/transfer")->assertOk()
            ->assertSee('Der Speicherplatz Ihres Pakets ist aufgebraucht.');
        $this->actingAs($user)->post("/my/databases/{$database->id}/import", ['dump' => UploadedFile::fake()->createWithContent('a.sql', 'SELECT 1;'), 'confirm' => '1'])
            ->assertSessionHas('error');
        $this->assertSame(0, ServerTask::count());

        // The administration may go on after confirming.
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $this->actingAs($admin)->post("/sites/{$site->id}/databases", ['suffix' => 'blog'])->assertSessionHas('limit_confirm');
        File::deleteDirectory($directory);
    }

    public function test_the_administration_sees_storage_per_customer_and_website(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $customer = $this->customer(100);
        $site = $this->measured(Site::factory()->for($customer)->create(['domain' => 'shop.example']), 60, 15);
        $site->server->forceFill(['quota_active' => false, 'storage_filesystem' => 'xfs'])->save();
        $own = $this->measured(Site::factory()->create(['domain' => 'panel.example', 'admin_owned' => true]), 300, 0);

        $this->actingAs($admin)->get("/customers/{$customer->id}/package")->assertOk()
            ->assertSee('75,0 MB von 100,0 MB belegt')
            ->assertSeeInOrder(['shop.example', '60,0 MB', '15,0 MB', '75,0 MB'])
            ->assertSee('Auf diesem Server sind keine Quotas aktiv (xfs).')
            ->assertDontSee('panel.example');

        $this->actingAs($admin)->get('/sites')->assertOk()->assertSee('Belegt')->assertSee('300,0 MB');
        $this->actingAs($admin)->get("/sites/{$own->id}")->assertOk()->assertSee('Dateien 300,0 MB, Datenbanken 0 B')
            ->assertDontSee('keine Quotas aktiv');
        $this->actingAs($admin)->get("/sites/{$site->id}")->assertOk()->assertSee('keine Quotas aktiv');
    }

    public function test_a_full_customer_raises_a_notification_and_an_all_clear(): void
    {
        $customer = $this->customer(100);
        $site = $this->measured(Site::factory()->for($customer)->create(), 99, 5);
        User::factory()->admin()->withTwoFactor()->create();

        app(AlertChecker::class)->run();
        $alert = Alert::where('key', "storage_full:{$customer->id}")->sole();
        $this->assertNull($alert->resolved_at);

        $this->measured($site, 50, 5);
        app(AlertChecker::class)->run();
        $this->assertNotNull($alert->fresh()->resolved_at);
    }
}
