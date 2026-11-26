<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Jobs\RebuildMissingSites;
use App\Jobs\RunServerTask;
use App\Livewire\ServerSiteBackups;
use App\Models\Database;
use App\Models\PendingNotification;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Servers\StatusCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Websites that the panel knows but a server lost are built again from
 * their backups, one task per step (E-106).
 */
class RebuildTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS = [
        'ok' => true, 'script' => 'server-status', 'hostname' => 'web1.example.de', 'os' => 'Debian GNU/Linux 13 (trixie)',
        'kernel' => '6.12.48+deb13-amd64', 'uptime' => 7200, 'cpu_cores' => 2, 'cpu' => 1.5, 'load' => [0.1, 0.1, 0.1],
        'memory' => ['total' => 4_000_000_000, 'used' => 1_000_000_000], 'swap' => ['total' => 0, 'used' => 0],
        'disk' => ['total' => 40_000_000_000, 'used' => 3_000_000_000], 'network' => ['rx' => 1, 'tx' => 1],
        'reboot_required' => false,
    ];

    /**
     * @param  array<string, string>  $outputs  script => result line
     */
    private function runner(array $outputs = []): object
    {
        $runner = new class($outputs) implements ScriptRunner
        {
            /** @var list<ScriptCall> */
            public array $calls = [];

            /** @param array<string, string> $outputs */
            public function __construct(public array $outputs) {}

            public function run(Server $server, ScriptCall $call, callable $onOutput): ScriptResult
            {
                $this->calls[] = $call;
                $script = $call->script->value;
                $output = ($this->outputs[$script] ?? '{"ok":true,"script":"'.$script.'"}')."\n";
                $onOutput($output);

                return ScriptResult::fromOutput($script, $output, str_contains($output, '"ok":false') ? 1 : 0, false);
            }
        };
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * A server connected to its backup target whose last status check found
     * the given websites.
     *
     * @param  list<string>  $present
     */
    private function server(array $present = [], array $attributes = []): Server
    {
        return Server::factory()->create($attributes + [
            'backup_box_id' => 7, 'backup_host' => 'u123456.your-storagebox.de', 'backup_ready' => true,
            'present_sites' => $present, 'checked_at' => now(),
        ]);
    }

    /**
     * @param  list<ScriptCall>  $calls
     * @return list<string>
     */
    private static function steps(array $calls): array
    {
        return array_map(fn (ScriptCall $call) => trim($call->script->value.' '.implode(' ', $call->arguments)), $calls);
    }

    public function test_a_task_waits_for_the_one_before_it_and_is_skipped_when_that_one_failed(): void
    {
        Queue::fake();
        $server = Server::factory()->create();

        $first = ServerTask::start($server, ScriptCall::panelTest(), null);
        $second = ServerTask::start($server, ScriptCall::panelTest(), null, after: $first);
        $third = ServerTask::start($server, ScriptCall::panelTest(), null, after: $second);
        Queue::assertPushed(RunServerTask::class, 1);
        $this->assertSame($first->id, $second->after_task_id);

        // Success starts the next one only.
        $first->forceFill(['status' => TaskStatus::Succeeded, 'finished_at' => now()])->save();
        $first->releaseFollowers();
        Queue::assertPushed(RunServerTask::class, fn (RunServerTask $job) => $job->taskId === $second->id);
        Queue::assertPushed(RunServerTask::class, 2);

        // A failure skips everything after it, without running or reporting.
        $second->forceFill(['status' => TaskStatus::Failed, 'finished_at' => now()])->save();
        $second->releaseFollowers();
        $third->refresh();
        $this->assertSame(TaskStatus::Failed, $third->status);
        $this->assertSame(ServerTask::SKIPPED, $third->error);
        $this->assertNull($third->started_at);
        Queue::assertPushed(RunServerTask::class, 2);
        $this->assertSame(0, PendingNotification::count());

        // Added after the one before has ended: started or skipped at once.
        $late = ServerTask::start($server, ScriptCall::panelTest(), null, after: $first);
        Queue::assertPushed(RunServerTask::class, fn (RunServerTask $job) => $job->taskId === $late->id);
        $skipped = ServerTask::start($server, ScriptCall::panelTest(), null, after: $second);
        $this->assertSame(ServerTask::SKIPPED, $skipped->fresh()->error);

        $this->assertSame('Übersprungen, weil ein Schritt davor fehlgeschlagen ist.', $third->errorMessage());
    }

    public function test_the_status_check_records_the_websites_and_the_panel_tells_the_missing_ones(): void
    {
        $runner = $this->runner(['server-status' => json_encode(self::STATUS + ['sites' => ['ow38217', 'bad', 'ow38217']])]);
        $server = Server::factory()->create();
        $present = Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow38217']);
        $lost = Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow49545']);
        $creating = Site::factory()->pending()->create(['server_id' => $server->id, 'system_user' => 'ow26069']);

        app(StatusCheck::class)->run($server);
        $server->refresh();

        $this->assertSame(['ow38217'], $server->present_sites);
        $this->assertFalse($present->fresh()->missingOnServer());
        $this->assertTrue($lost->fresh()->missingOnServer());
        $this->assertFalse($creating->fresh()->missingOnServer());

        // Created after the check began: not missing, just not seen yet.
        $task = ServerTask::factory()->create(['server_id' => $server->id, 'site_id' => $lost->id, 'script' => ServerScript::SiteCreate->value, 'status' => TaskStatus::Succeeded->value]);
        $task->forceFill(['finished_at' => $server->checked_at->copy()->addSecond()])->save();
        $this->assertFalse($lost->fresh()->missingOnServer());
        $task->delete();

        // While a task of the website waits, it is not called missing.
        $open = ServerTask::factory()->create(['server_id' => $server->id, 'site_id' => $lost->id, 'status' => TaskStatus::Pending->value]);
        $this->assertFalse($lost->fresh()->missingOnServer());
        $open->delete();

        // An older server-status without the list: unknown, nothing missing.
        $runner->outputs['server-status'] = json_encode(self::STATUS);
        app(StatusCheck::class)->run($server);
        $this->assertNull($server->fresh()->present_sites);
        $this->assertFalse($lost->fresh()->missingOnServer());
    }

    public function test_rebuilding_a_site_runs_every_step_in_order(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $runner = $this->runner(['site-create' => '{"ok":true,"script":"site-create","tls":false}']);
        $server = $this->server();
        $site = Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow38217', 'domain' => 'shop.example.de', 'sftp_enabled' => true, 'sftp_password' => 'Secret-sftp-2026']);
        $database = Database::factory()->create(['site_id' => $site->id, 'name' => 'ow38217_shop']);
        $site->cronJobs()->create(['schedule' => '*/5 * * * *', 'command' => 'php cron.php', 'enabled' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->get("/sites/{$site->id}/backups")->assertOk()
            ->assertSee('Webseite fehlt auf dem Server')->assertSee('Aus neuester Sicherung neu aufbauen');

        $this->actingAs($admin)->post("/sites/{$site->id}/backups/rebuild")->assertSessionHasErrors('confirm');
        $this->assertSame([], $runner->calls);

        $response = $this->actingAs($admin)->post("/sites/{$site->id}/backups/rebuild", ['confirm' => '1']);
        $first = ServerTask::orderBy('created_at')->orderBy('id')->first();
        $response->assertRedirect("/sites/{$site->id}/backups");

        $this->assertSame([
            'site-create --domain shop.example.de --type php --user ow38217 --php 8.5 --php-memory 256 --php-upload 64 --php-time 60 --php-processes 10 --php-errors off',
            'db-create --name ow38217_shop',
            'site-restore --user ow38217 --snapshot latest --scope all',
            'site-cron --user ow38217',
            'site-sftp --user ow38217 --state on',
            'site-create --domain shop.example.de --type php --user ow38217 --tls',
        ], self::steps($runner->calls));
        // The database keeps its password; it goes on stdin only.
        $this->assertSame($database->password."\n", $runner->calls[1]->stdin);
        $this->assertSame(6, ServerTask::where('status', TaskStatus::Succeeded->value)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.rebuild_started']);

        // Each step names the one before it.
        $restore = ServerTask::where('script', ServerScript::SiteRestore->value)->sole();
        $this->actingAs($admin)->get(route('tasks.show', $restore))->assertOk()->assertSee('Vorheriger Schritt');
    }

    public function test_a_failed_step_skips_the_rest_of_the_website(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $runner = $this->runner(['db-create' => '{"ok":false,"script":"db-create","error":"mariadb_not_running"}']);
        $server = $this->server();
        $site = Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow38217']);
        $database = Database::factory()->create(['site_id' => $site->id, 'name' => 'ow38217_shop']);

        $this->actingAs($this->admin())->post("/sites/{$site->id}/backups/rebuild", ['confirm' => '1'])->assertRedirect();

        $this->assertSame(['site-create', 'db-create'], array_map(fn (ScriptCall $call) => $call->script->value, $runner->calls));
        $skipped = ServerTask::where('error', ServerTask::SKIPPED)->pluck('script')->map->value->all();
        $this->assertEqualsCanonicalizing(['site-restore', 'site-create'], $skipped);
        // The database does not exist on the server, as the panel now shows.
        $this->assertSame('failed', $database->fresh()->status->value);
    }

    public function test_an_older_backup_can_be_chosen_and_only_missing_sites_are_rebuilt(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $runner = $this->runner();
        $server = $this->server(['ow38217']);
        $present = Site::factory()->withoutTls()->create(['server_id' => $server->id, 'system_user' => 'ow38217']);
        $lost = Site::factory()->withoutTls()->create(['server_id' => $server->id, 'system_user' => 'ow49545']);
        $admin = $this->admin();

        // An existing website keeps the normal restore.
        $this->actingAs($admin)->get("/sites/{$present->id}/backups/9811f032")->assertOk()->assertDontSee('Aus dieser Sicherung neu aufbauen');
        $this->actingAs($admin)->post("/sites/{$present->id}/backups/9811f032/rebuild", ['confirm' => '1'])->assertSessionHas('error');
        $this->assertSame([], $runner->calls);

        $this->actingAs($admin)->get("/sites/{$lost->id}/backups/9811f032")->assertOk()->assertSee('Aus dieser Sicherung neu aufbauen');
        $this->actingAs($admin)->post("/sites/{$lost->id}/backups/9811f032/rebuild", ['confirm' => '1'])->assertRedirect();
        $this->assertContains('site-restore --user ow49545 --snapshot 9811f032 --scope all', self::steps($runner->calls));

        // Customers cannot.
        $this->actingAs(User::factory()->create())->post("/sites/{$lost->id}/backups/rebuild", ['confirm' => '1'])->assertForbidden();
    }

    public function test_the_server_puts_back_its_firewall_and_rebuilds_all_missing_sites(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $runner = $this->runner(['firewall-rules' => '{"ok":true,"script":"firewall-rules","rules":1,"firewall_confirm_required":true}']);
        $server = $this->server(['ow38217']);
        $server->firewallRules()->create(['protocol' => 'tcp', 'ports' => '8080', 'source' => null]);
        $server->fail2banIgnores()->create(['address' => '203.0.113.7']);
        Site::factory()->withoutTls()->create(['server_id' => $server->id, 'system_user' => 'ow38217', 'domain' => 'here.example.de']);
        Site::factory()->withoutTls()->create(['server_id' => $server->id, 'system_user' => 'ow49545', 'domain' => 'lost.example.de']);
        Site::factory()->withoutTls()->create(['server_id' => $server->id, 'system_user' => 'ow26069', 'domain' => 'gone.example.de']);
        $admin = $this->admin();

        $this->actingAs($admin)->get("/servers/{$server->id}/backups")->assertOk()
            ->assertSee('Fehlende Webseiten')->assertSee('lost.example.de')->assertSee('fehlt auf dem Server');

        $this->actingAs($admin)->post("/servers/{$server->id}/backups/rebuild")->assertSessionHasErrors('confirm');
        $this->actingAs($admin)->post("/servers/{$server->id}/backups/rebuild", ['confirm' => '1'])
            ->assertRedirect(route('servers.backups', $server))->assertSessionHas('status');

        $steps = self::steps($runner->calls);
        $this->assertSame(['firewall-rules', 'firewall-confirm', 'server-fail2ban --action ignore'], array_slice($steps, 0, 3));
        $this->assertContains('site-restore --user ow49545 --snapshot latest --scope all', $steps);
        $this->assertContains('site-restore --user ow26069 --snapshot latest --scope all', $steps);
        $this->assertNotContains('site-restore --user ow38217 --snapshot latest --scope all', $steps);
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.rebuild_started']);

        // Not without a connected backup target.
        $server->forceFill(['backup_ready' => false])->save();
        $this->actingAs($admin)->post("/servers/{$server->id}/backups/rebuild", ['confirm' => '1'])->assertSessionHas('error');
    }

    public function test_after_a_restore_the_installer_rebuilds_the_websites_once_the_backups_are_connected(): void
    {
        Queue::fake();
        $own = Server::factory()->create([
            'host' => '127.0.0.1', 'hosts_panel' => true, 'backup_box_id' => 7, 'backup_host' => 'u123456.your-storagebox.de', 'backup_ready' => false,
        ]);
        $site = Site::factory()->withoutTls()->create(['server_id' => $own->id, 'system_user' => 'ow49545']);

        $this->artisan('owpanel:reconnect-backups --rebuild-sites')->assertSuccessful();

        $setup = ServerTask::where('script', ServerScript::BackupSetup->value)->sole();
        Queue::assertPushed(RebuildMissingSites::class, fn (RebuildMissingSites $job) => $job->serverId === $own->id && $job->afterTaskId === $setup->id);

        // In the worker: the check finds no website, the rebuild waits for the setup.
        $this->runner(['server-status' => json_encode(self::STATUS + ['sites' => []])]);
        (new RebuildMissingSites($own->id, $setup->id))->handle(app(StatusCheck::class));

        $first = $site->tasks()->where('script', ServerScript::SiteCreate->value)->sole();
        $this->assertSame($setup->id, $first->after_task_id);
        $this->assertSame(TaskStatus::Pending, $first->status);
        Queue::assertPushed(RunServerTask::class, fn (RunServerTask $job) => $job->taskId === $setup->id);
        Queue::assertNotPushed(RunServerTask::class, fn (RunServerTask $job) => $job->taskId === $first->id);
    }

    public function test_scheduled_backups_leave_out_missing_sites_and_wait_for_open_tasks(): void
    {
        $runner = $this->runner();
        config(['owpanel.timezone' => 'UTC']);
        $this->travelTo(now()->setTime(3, 0));
        $server = $this->server(['ow38217', 'ow26069'], ['backup_time' => '02:30']);
        Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow49545']);
        $busy = Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow26069']);
        Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow38217']);
        ServerTask::factory()->create(['server_id' => $server->id, 'site_id' => $busy->id, 'script' => ServerScript::SiteCron->value, 'status' => TaskStatus::Running->value]);

        $this->artisan('owpanel:backups')->assertSuccessful();

        $this->assertSame(['site-backup'], array_map(fn (ScriptCall $call) => $call->script->value, $runner->calls));
        $this->assertSame('ow38217', $runner->calls[0]->arguments[1]);
    }

    public function test_all_websites_of_a_server_can_be_backed_up_at_once(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $runner = $this->runner();
        $server = $this->server(['ow38217', 'ow26069'], ['backup_interval' => 3, 'backup_time' => '02:30']);
        Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow38217']);
        Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow49545']);
        $busy = Site::factory()->create(['server_id' => $server->id, 'system_user' => 'ow26069']);
        Site::factory()->pending()->create(['server_id' => $server->id, 'system_user' => 'ow11111']);
        ServerTask::factory()->create(['server_id' => $server->id, 'site_id' => $busy->id, 'status' => TaskStatus::Running->value]);
        $admin = $this->admin();

        // Target on top, websites and panel left, the rules on the right.
        $page = $this->actingAs($admin)->get("/servers/{$server->id}/backups")->assertOk()
            ->assertSee('action="'.url("/servers/{$server->id}/backups/all").'"', false)
            ->assertSee('Ab 02:30 Uhr alle 3 Stunden sichert der Server alle Webseiten, bei denen die automatische Sicherung an ist.')
            ->assertSeeInOrder(['Jetzt sichern', 'Backup-Ziel', 'So wird gesichert', 'Webseiten auf diesem Server'])
            ->assertSeeInOrder(['Webseite', 'Automatische Sicherung', 'Art', 'Letzte Sicherung']);

        $this->actingAs($admin)->post("/servers/{$server->id}/backups/all")
            ->assertRedirect(route('servers.backups', $server))
            ->assertSessionHas('status', 'Sicherung von 1 Webseiten gestartet. 2 übersprungen, weil dort gerade eine Aufgabe läuft oder die Webseite auf dem Server fehlt.');
        $this->assertSame(['site-backup --user ow38217 --manual --keep-hourly 24 --keep-daily 7 --keep-weekly 4 --keep-monthly 6'], self::steps($runner->calls));

        $this->actingAs(User::factory()->create())->post("/servers/{$server->id}/backups/all")->assertForbidden();

        // The page shows the new backup and asks for updates while it waits
        // or runs; with the queue in the test it has already finished.
        $backup = ServerTask::where('script', ServerScript::SiteBackup->value)->sole();
        $backup->forceFill(['status' => TaskStatus::Running, 'finished_at' => null])->save();
        Livewire::actingAs($admin)->test(ServerSiteBackups::class, ['server' => $server])
            ->assertSeeHtml('wire:poll.3s')->assertSeeHtml('status-running');
        $backup->forceFill(['status' => TaskStatus::Succeeded, 'finished_at' => now()])->save();
        Livewire::actingAs($admin)->test(ServerSiteBackups::class, ['server' => $server])
            ->assertDontSeeHtml('wire:poll')->assertSeeHtml('status-succeeded')->assertSee('von Hand');
        Livewire::actingAs(User::factory()->create())->test(ServerSiteBackups::class, ['server' => $server])->assertForbidden();

        // Task lists update waiting and running tasks by themselves.
        $backup->forceFill(['status' => TaskStatus::Pending, 'finished_at' => null])->save();
        $this->actingAs($admin)->get(route('servers.tasks', $server))->assertOk()->assertSeeHtml('wire:poll.2s');

        // The website page names the interval too.
        $site = Site::where('system_user', 'ow38217')->sole();
        $this->actingAs($admin)->get("/sites/{$site->id}/backups")->assertOk()
            ->assertSee('Automatische Sicherung')
            ->assertSee('Ab 02:30 Uhr alle 3 Stunden sichert der Server die Dateien und alle Datenbanken dieser Webseite.')
            ->assertSee('die neueste je Stunde für');
    }

    public function test_back_up_now_includes_the_panel_on_its_server_and_needs_a_connection(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $runner = $this->runner();
        $own = $this->server(['ow38217'], ['host' => '127.0.0.1', 'hosts_panel' => true]);
        Site::factory()->create(['server_id' => $own->id, 'system_user' => 'ow38217']);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/servers/{$own->id}/backups/all")
            ->assertSessionHas('status', 'Sicherung von 1 Webseiten und des Panels gestartet.');
        $this->assertSame(['site-backup', 'panel-backup'], array_map(fn (ScriptCall $call) => $call->script->value, $runner->calls));

        // Not connected: no button, and the panel refuses.
        $own->forceFill(['backup_ready' => false])->save();
        $this->actingAs($admin)->get("/servers/{$own->id}/backups")->assertOk()
            ->assertDontSee('action="'.url("/servers/{$own->id}/backups/all").'"', false);
        $this->actingAs($admin)->post("/servers/{$own->id}/backups/all")->assertSessionHas('error');
        $this->assertCount(2, $runner->calls);
    }
}
