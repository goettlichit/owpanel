<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\CronJob;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Package;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * Databases and cron jobs in the customer area (E-124, E-144): the same
 * actions as the administration, only for the own websites and within the
 * package (E-139).
 */
class CustomerDatabasesCronTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Site $site;

    private Database $database;

    private Site $foreignSite;

    private Database $foreign;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/owpanel-transfers-'.bin2hex(random_bytes(4));
        config(['owpanel.transfers.path' => $this->directory]);

        $package = Package::factory()->create(['max_databases' => 2, 'max_cron_jobs' => 2, 'min_cron_interval' => 60]);
        $customer = Customer::factory()->create();
        $customer->package()->associate($package)->save();
        $this->user = User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);
        $this->site = Site::factory()->for($customer)->create(['system_user' => 'ow38217', 'domain' => 'meine.example']);
        $this->database = Database::factory()->create(['site_id' => $this->site->id, 'server_id' => $this->site->server_id, 'name' => 'ow38217_shop']);

        $this->foreignSite = Site::factory()->for(Customer::factory())->create(['system_user' => 'ow49999']);
        $this->foreign = Database::factory()->create(['site_id' => $this->foreignSite->id, 'server_id' => $this->foreignSite->server_id, 'name' => 'ow49999_blog']);

        $this->app->instance(ScriptRunner::class, new FakeScriptRunner(['{"ok":true}'."\n"]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function databaseRoutes(Site $site, Database $database): array
    {
        return [
            ['get', "/my/sites/{$site->id}/databases"],
            ['post', "/my/sites/{$site->id}/databases"],
            ['get', "/my/databases/{$database->id}"],
            ['get', "/my/databases/{$database->id}/password"],
            ['post', "/my/databases/{$database->id}/password"],
            ['delete', "/my/databases/{$database->id}"],
            ['get', "/my/databases/{$database->id}/transfer"],
            ['post', "/my/databases/{$database->id}/export"],
            ['post', "/my/databases/{$database->id}/import"],
            ['get', "/my/databases/{$database->id}/tables"],
            ['get', "/my/databases/{$database->id}/table"],
            ['get', "/my/databases/{$database->id}/sql"],
            ['post', "/my/databases/{$database->id}/sql"],
        ];
    }

    public function test_a_customer_reaches_no_database_or_cron_job_of_another_customer(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $job = $this->foreignSite->cronJobs()->create(['schedule' => '0 * * * *', 'command' => 'x', 'enabled' => true]);
        $export = ServerTask::factory()->create(['server_id' => $this->foreignSite->server_id, 'site_id' => $this->foreignSite->id, 'database_id' => $this->foreign->id, 'script' => ServerScript::DbExport, 'status' => TaskStatus::Succeeded]);

        foreach ($this->databaseRoutes($this->foreignSite, $this->foreign) as [$method, $uri]) {
            $this->actingAs($this->user)->{$method}($uri, ['suffix' => 'x', 'confirm_name' => 'ow49999_blog', 'sql' => 'SELECT 1'])->assertForbidden();
        }
        foreach ([['get', "/my/sites/{$this->foreignSite->id}/cron"], ['post', "/my/sites/{$this->foreignSite->id}/cron"],
            ['put', "/my/sites/{$this->foreignSite->id}/cron/{$job->id}"], ['delete', "/my/sites/{$this->foreignSite->id}/cron/{$job->id}"]] as [$method, $uri]) {
            $this->actingAs($this->user)->{$method}($uri, ['schedule' => '0 * * * *', 'command' => 'y', 'toggle' => '1'])->assertForbidden();
        }
        $this->actingAs($this->user)->get("/my/databases/exports/{$export->id}")->assertForbidden();

        // A foreign cron job through the own website.
        $this->actingAs($this->user)->delete("/my/sites/{$this->site->id}/cron/{$job->id}")->assertNotFound();

        $this->assertSame(0, ServerTask::where('user_id', $this->user->id)->count());
        $this->assertSame(1, CronJob::count());
        $this->assertNotNull($this->foreign->fresh());
    }

    public function test_the_administration_pages_stay_closed_and_admins_stay_out_of_my(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (["/sites/{$this->site->id}/databases", "/databases/{$this->database->id}", "/databases/{$this->database->id}/tables", "/sites/{$this->site->id}/cron"] as $uri) {
            $this->actingAs($this->user)->get($uri)->assertForbidden();
        }
        $admin = User::factory()->admin()->withTwoFactor()->create();
        foreach ($this->databaseRoutes($this->site, $this->database) as [$method, $uri]) {
            $this->actingAs($admin)->{$method}($uri)->assertForbidden();
        }
    }

    public function test_a_customer_creates_and_deletes_a_database_within_his_package(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/databases")->assertOk()
            ->assertSee('Ihr Paket: 1 von 2 Datenbanken.')
            ->assertSee(route('my.databases.show', $this->database), false)
            ->assertSee(route('my.databases.store', $this->site), false)
            ->assertDontSee('/tasks/');

        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/databases", ['suffix' => 'blog'])
            ->assertRedirect("/my/sites/{$this->site->id}/databases");
        $this->assertSame([ServerScript::DbCreate, $this->user->id], [ServerTask::sole()->script, ServerTask::sole()->user_id]);

        // The package allows two: the form stays, disabled, with the reason (E-146).
        ServerTask::query()->update(['status' => TaskStatus::Succeeded]);
        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/databases")->assertOk()
            ->assertSee('Ihr Paket erlaubt 2 Datenbanken, und alle sind belegt.')
            ->assertSee('<fieldset disabled', false);
        // A third is refused without a way around it.
        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/databases", ['suffix' => 'drei', 'exceed_limits' => '1'])
            ->assertSessionHas('error', 'Das Paket erlaubt höchstens 2 Datenbanken.')
            ->assertSessionMissing('limit_confirm');
        $this->assertSame(3, Database::count());

        $this->actingAs($this->user)->delete("/my/databases/{$this->database->id}", ['confirm_name' => 'ow38217_shop'])
            ->assertRedirect("/my/sites/{$this->site->id}/databases");
        $this->assertSame(1, AuditLog::where('action', 'database.deletion_started')->where('user_id', $this->user->id)->count());
    }

    public function test_the_database_page_shows_credentials_but_no_tasks(): void
    {
        ServerTask::factory()->create(['server_id' => $this->site->server_id, 'site_id' => $this->site->id, 'database_id' => $this->database->id, 'script' => ServerScript::DbCreate, 'status' => TaskStatus::Succeeded]);

        $this->actingAs($this->user)->get("/my/databases/{$this->database->id}")->assertOk()
            ->assertSee('ow38217_shop')
            ->assertSee(route('my.databases.tables', $this->database), false)
            ->assertSee(route('my.databases.transfer', $this->database), false)
            ->assertDontSee('/tasks/')
            ->assertDontSee(route('databases.retry', $this->database), false);

        $this->actingAs($this->user)->get("/my/databases/{$this->database->id}/password")->assertOk()->assertSee($this->database->password);
        $this->assertSame($this->user->id, AuditLog::where('action', 'database.password_revealed')->sole()->user_id);

        Queue::fake();
        $this->actingAs($this->user)->post("/my/databases/{$this->database->id}/password")
            ->assertRedirect("/my/databases/{$this->database->id}");
    }

    public function test_a_customer_exports_and_downloads_his_database(): void
    {
        $this->app->instance(ScriptRunner::class, new FakeScriptRunner(['{"ok":true,"script":"db-export","database":"ow38217_shop"}'."\n"], stdout: gzencode('dump')));

        $this->actingAs($this->user)->post("/my/databases/{$this->database->id}/export")
            ->assertRedirect("/my/databases/{$this->database->id}/transfer");
        $task = ServerTask::sole();

        $this->actingAs($this->user)->get("/my/databases/{$this->database->id}/transfer")->assertOk()
            ->assertSee(route('my.databases.download', $task), false)->assertDontSee('/tasks/');
        $this->actingAs($this->user)->get("/my/databases/exports/{$task->id}")->assertOk()->assertDownload();
    }

    public function test_cron_jobs_follow_the_package_and_a_job_beyond_it_can_only_be_deleted(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/cron")->assertOk()
            ->assertSee('Ihr Paket: 0 von 2 Cronjobs, kürzester Abstand 60 Min.')
            ->assertSee('stündlich')
            ->assertDontSee('alle 5 Minuten')
            ->assertSee(route('my.sites.cron.store', $this->site), false);

        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/cron", ['schedule' => '*/5 * * * *', 'command' => 'php a.php', 'exceed_limits' => '1'])
            ->assertSessionHas('error', 'Das Paket verlangt mindestens 60 Min. Abstand zwischen zwei Läufen eines Cronjobs.');
        $this->assertSame(0, CronJob::count());

        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/cron", ['schedule' => '0 * * * *', 'command' => 'php a.php'])
            ->assertRedirect("/my/sites/{$this->site->id}/cron");
        $this->assertSame(1, CronJob::count());
        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/cron")->assertOk()
            ->assertSee('value="0 * * * *"', false)
            ->assertDontSee('<fieldset disabled', false);

        // An administrator allowed one beyond the package.
        $job = $this->site->cronJobs()->create(['schedule' => '*/5 * * * *', 'command' => 'php b.php', 'enabled' => true]);
        // Two of two: no new job, the form says why (E-146).
        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/cron")->assertOk()
            ->assertSee('Ihr Paket erlaubt 2 Cronjobs, und alle sind belegt.')
            ->assertSee('<fieldset disabled', false);
        // Update and delete share the address; only the delete form is left.
        $page = $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/cron")->assertOk()->getContent();
        $this->assertSame(1, substr_count($page, route('my.sites.cron.destroy', [$this->site, $job])));
        $this->actingAs($this->user)->put("/my/sites/{$this->site->id}/cron/{$job->id}", ['toggle' => '1'])
            ->assertSessionHas('error', 'Dieser Cronjob liegt über der Grenze Ihres Pakets und lässt sich nur noch löschen.');
        $this->assertTrue($job->fresh()->enabled);
        $this->actingAs($this->user)->delete("/my/sites/{$this->site->id}/cron/{$job->id}")
            ->assertRedirect("/my/sites/{$this->site->id}/cron");
        $this->assertNull($job->fresh());
    }

    public function test_without_a_package_a_customer_creates_nothing(): void
    {
        Queue::fake();
        $this->site->customer->package()->dissociate()->save();

        $this->actingAs($this->user)->get("/my/sites/{$this->site->id}/cron")->assertOk()
            ->assertSee('Für Ihr Konto ist kein Paket gebucht.');
        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/cron", ['schedule' => '0 3 * * *', 'command' => 'php a.php'])
            ->assertSessionHas('error', 'Für Ihr Konto ist kein Paket gebucht. Bitte wenden Sie sich an uns.');
        $this->actingAs($this->user)->post("/my/sites/{$this->site->id}/databases", ['suffix' => 'neu'])
            ->assertSessionHas('error', 'Für Ihr Konto ist kein Paket gebucht. Bitte wenden Sie sich an uns.');

        $this->assertSame(0, CronJob::count());
        $this->assertSame(0, ServerTask::count());
    }
}
