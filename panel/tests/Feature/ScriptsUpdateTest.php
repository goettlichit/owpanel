<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Jobs\DeliverNotification;
use App\Jobs\RunServerTask;
use App\Models\PushSubscription;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\User;
use App\Notify\AlertChecker;
use App\Notify\Events;
use App\Notify\Notifier;
use App\Servers\ScriptCall;
use App\Servers\ScriptRunner;
use App\Servers\ScriptsArchive;
use App\Servers\ScriptsUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\ScriptedRunner;
use Tests\Support\PushReceiver;
use Tests\TestCase;

/**
 * The panel brings the scripts of its servers to its own version (E-167,
 * E-187) and holds changing tasks back meanwhile.
 */
class ScriptsUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = "-----BEGIN SSH SIGNATURE-----\nU1NIU0lHAAAAAQ==\n-----END SSH SIGNATURE-----\n";

    private string $dir;

    private Server $panel;

    private Server $web;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['owpanel.version' => '1.3.0']);
        $this->dir = sys_get_temp_dir().'/owpanel-release-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
        config(['owpanel.release_dir' => $this->dir]);
        $this->panel = Server::factory()->create(['host' => '127.0.0.1', 'scripts_version' => '1.3.0']);
        $this->web = Server::factory()->create(['name' => 'web2', 'scripts_version' => '1.2.0']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function archive(bool $signed = true): void
    {
        $version = config('owpanel.version');
        file_put_contents("{$this->dir}/owpanel-{$version}.tar.gz", 'archive');
        if ($signed) {
            file_put_contents("{$this->dir}/owpanel-{$version}.tar.gz.sig", self::SIGNATURE);
        }
    }

    private function runTask(ServerTask $task, ScriptedRunner $runner): ServerTask
    {
        (new RunServerTask($task->getKey()))->handle($runner);

        return $task->fresh();
    }

    private function scriptsTasks(Server $server): Collection
    {
        return $server->tasks()->where('script', ServerScript::ServerScriptsUpdate->value)->get();
    }

    public function test_changing_tasks_wait_while_the_scripts_differ(): void
    {
        $this->archive();
        $runner = new ScriptedRunner(['site-backup' => ['snapshot' => 'abcd1234']]);
        $change = ServerTask::start($this->web, ScriptCall::sshPasswordLogin(false), null);

        $change = $this->runTask($change, $runner);

        $this->assertSame(TaskStatus::Failed, $change->status);
        $this->assertSame('scripts_outdated', $change->error);
        $this->assertStringContainsString('(1.2.0)', (string) $change->output);
        $this->assertSame([], $runner->scripts());
        // Meanwhile the panel has started the update.
        $this->assertCount(1, $this->scriptsTasks($this->web));

        // Backups, reading and maintenance still run.
        $this->assertTrue(ServerScript::SiteBackup->runsWhenOutdated());
        $this->assertTrue(ServerScript::FirewallConfirm->runsWhenOutdated());
        $this->assertTrue(ServerScript::ServerReboot->runsWhenOutdated());
        $this->assertFalse(ServerScript::SiteCreate->runsWhenOutdated());
        $this->assertFalse(ServerScript::FirewallRules->runsWhenOutdated());
    }

    public function test_the_update_sends_signature_and_archive_and_counts_at_once(): void
    {
        $this->archive();
        $task = ScriptsUpdater::ensure($this->web);
        $this->assertNotNull($task);
        $this->assertNull($task->user_id);

        $runner = new ScriptedRunner(['server-scripts-update' => ['version' => '1.3.0', 'signed' => true]]);
        $task = $this->runTask($task, $runner);

        $call = $runner->calls[0];
        $this->assertSame(['--version', '1.3.0'], $call->arguments);
        $this->assertSame(self::SIGNATURE, $call->stdin);
        $this->assertSame("{$this->dir}/owpanel-1.3.0.tar.gz", $call->stdinFile);
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame('1.3.0', $this->web->fresh()->scripts_version);
        $this->assertFalse($this->web->fresh()->scriptsOutdated());
    }

    public function test_the_update_is_tried_at_most_every_15_minutes(): void
    {
        $this->archive();
        $first = ScriptsUpdater::ensure($this->web);
        $this->assertNull(ScriptsUpdater::ensure($this->web));

        $first->forceFill(['status' => TaskStatus::Failed, 'error' => 'invalid_signature', 'finished_at' => now()])->save();
        $this->travel(10)->minutes();
        $this->assertNull(ScriptsUpdater::ensure($this->web));
        $this->travel(6)->minutes();
        $this->assertNotNull(ScriptsUpdater::ensure($this->web));
        $this->assertCount(2, $this->scriptsTasks($this->web));

        // Current scripts and unknown versions need nothing.
        $this->assertNull(ScriptsUpdater::ensure($this->panel));
        $this->assertNull(ScriptsUpdater::ensure(Server::factory()->create(['scripts_version' => null])));
    }

    public function test_a_missing_release_archive_is_loaded_first(): void
    {
        $task = ScriptsUpdater::ensure($this->web);

        $fetch = $this->panel->tasks()->sole();
        $this->assertSame(ServerScript::PanelUpdate, $fetch->script);
        $this->assertSame(['--fetch-archive'], $fetch->arguments);
        $this->assertSame($fetch->id, $task->after_task_id);
        $this->assertSame(TaskStatus::Pending, $task->status);

        // A signature alone does not make an archive.
        file_put_contents("{$this->dir}/owpanel-1.3.0.tar.gz.sig", self::SIGNATURE);
        $this->assertFalse(ScriptsArchive::available());
    }

    public function test_test_builds_send_their_archive_unsigned_or_nothing(): void
    {
        config(['owpanel.version' => 'dev-0123456789ab']);
        $this->web->forceFill(['scripts_version' => 'dev-aaaaaaaaaaaa'])->save();
        $this->assertNull(ScriptsUpdater::ensure($this->web));
        $this->assertSame(0, ServerTask::count());

        $this->archive(signed: false);
        $task = ScriptsUpdater::ensure($this->web);
        $runner = new ScriptedRunner(['server-scripts-update' => ['version' => 'dev-0123456789ab', 'signed' => false]]);
        $this->runTask($task, $runner);
        $this->assertSame("unsigned\n", $runner->calls[0]->stdin);
        $this->assertSame(['--version', 'dev-0123456789ab'], $runner->calls[0]->arguments);
    }

    public function test_the_status_check_starts_the_update(): void
    {
        $this->archive();
        $this->panel->forceFill(['scripts_version' => null])->save();
        $this->app->instance(ScriptRunner::class, new ScriptedRunner(['server-status' => [
            'hostname' => 'web2.example.de', 'os' => 'Debian GNU/Linux 13 (trixie)', 'kernel' => '6.12.48+deb13-amd64',
            'uptime' => 7200, 'cpu_cores' => 2, 'cpu' => 12.5, 'load' => [0.5, 0.4, 0.3],
            'memory' => ['total' => 4_000_000_000, 'used' => 1_000_000_000], 'swap' => ['total' => 0, 'used' => 0],
            'disk' => ['total' => 40_000_000_000, 'used' => 30_000_000_000], 'network' => ['rx' => 1, 'tx' => 1],
            'reboot_required' => false, 'scripts_version' => '1.2.0',
        ]]));

        $this->artisan('owpanel:check-servers')->assertSuccessful();

        // Both servers answer 1.2.0 here; each gets one update.
        $this->assertCount(1, $this->scriptsTasks($this->web));
        $this->assertCount(1, $this->scriptsTasks($this->panel));
    }

    public function test_outdated_scripts_are_reported_after_half_an_hour_without_task_messages(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create(['locale' => 'de']);
        $browser = new PushReceiver;
        $admin->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-1',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://fcm.googleapis.com/fcm/send/device-1'),
            'public_key' => $browser->publicKey(), 'auth' => $browser->authSecret(), 'label' => 'Firefox · Linux',
        ]);
        $this->web->forceFill(['last_seen_at' => now(), 'checked_at' => now()])->save();
        $key = "scripts_outdated:{$this->web->id}";

        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen($key));

        $this->travel(31)->minutes();
        $this->web->forceFill(['last_seen_at' => now(), 'checked_at' => now()])->save();
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen($key));
        $pushed = collect(Queue::pushed(DeliverNotification::class))->filter(fn ($job) => $job->messages[0]['event'] === 'scripts_outdated');
        $this->assertCount(1, $pushed);

        // A failed update says nothing by itself.
        $failed = ServerTask::factory()->failed('invalid_signature')->create(['server_id' => $this->web->id, 'script' => ServerScript::ServerScriptsUpdate, 'arguments' => ['--version', '1.3.0']]);
        Events::taskFinished($failed);
        $this->assertCount(0, collect(Queue::pushed(DeliverNotification::class))->filter(fn ($job) => $job->messages[0]['event'] === 'task_failed'));

        $this->web->forceFill(['scripts_version' => '1.3.0'])->save();
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen($key));
    }

    public function test_the_state_page_says_the_panel_updates_the_scripts(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $this->actingAs($admin)->get(route('servers.show', $this->web))->assertOk()
            ->assertSee('1.2.0')
            ->assertSee(__('servers.system.scripts_differ_hint'));
    }
}
