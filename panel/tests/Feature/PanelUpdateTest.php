<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Jobs\DeliverNotification;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PushSubscription;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Setting;
use App\Models\User;
use App\Notify\MessageText;
use App\Servers\ScriptRunner;
use App\Support\PanelRelease;
use App\Support\PanelUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\ScriptedRunner;
use Tests\Support\PushReceiver;
use Tests\TestCase;

/**
 * Updating the panel by hand and at night (E-185).
 */
class PanelUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Server $panel;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['owpanel.version' => '1.2.0', 'owpanel.source_url' => 'https://github.com/example/owpanel']);
        $this->panel = Server::factory()->create(['host' => '127.0.0.1']);
        $this->release('1.3.0');
    }

    private function release(string $version, ?string $published = null): void
    {
        Setting::put(Setting::PANEL_RELEASE, (string) json_encode([
            'version' => $version,
            'published_at' => $published ?? now()->subDays(3)->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
            'error' => null,
        ]));
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->withTwoFactor()->create(['locale' => 'de']);
        $browser = new PushReceiver;
        $admin->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-1',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://fcm.googleapis.com/fcm/send/device-1'),
            'public_key' => $browser->publicKey(),
            'auth' => $browser->authSecret(),
            'label' => 'Firefox · Linux',
        ]);

        return $admin;
    }

    private function pushed(string $event): Collection
    {
        return collect(Queue::pushed(DeliverNotification::class))
            ->filter(fn ($job) => $job->messages[0]['event'] === $event)->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function state(array $data): void
    {
        Setting::put(Setting::PANEL_UPDATE, (string) json_encode($data + [
            'version' => '1.3.0', 'from' => '1.2.0', 'auto' => false, 'started_at' => now()->subMinutes(2)->toIso8601String(),
        ]));
    }

    private function follow(?ScriptedRunner $runner = null): void
    {
        $this->app->instance(ScriptRunner::class, $runner ?? new ScriptedRunner);
        $this->artisan('owpanel:panel-update')->assertSuccessful();
    }

    public function test_the_admin_updates_the_panel_after_confirming(): void
    {
        $admin = $this->admin();
        $url = "/servers/{$this->panel->id}/updates";

        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('Änderungen von 1.3.0 gelesen, jetzt aktualisieren')
            ->assertSee('https://github.com/example/owpanel/releases/tag/v1.3.0', false);

        $this->actingAs($admin)->from($url)->post("{$url}/panel", ['version' => '1.3.0'])
            ->assertRedirect($url)->assertSessionHasErrors('confirm');
        $this->assertSame(0, ServerTask::count());

        $this->actingAs($admin)->from($url)->post("{$url}/panel", ['version' => '1.3.0', 'confirm' => '1'])
            ->assertRedirect($url)->assertSessionHas('success');

        $task = ServerTask::sole();
        $this->assertSame(ServerScript::PanelUpdate, $task->script);
        $this->assertSame(['--version', '1.3.0'], $task->arguments);
        $this->assertSame($this->panel->id, $task->server_id);
        $update = PanelUpdate::current();
        $this->assertSame('starting', $update['state']);
        $this->assertSame('1.2.0', $update['from']);
        $this->assertFalse($update['auto']);
        $this->assertSame($task->id, $update['task']);
        $this->assertTrue(AuditLog::where('action', 'server.panel_update_started')->exists());

        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('Update von 1.2.0 auf 1.3.0 läuft')
            ->assertDontSee('Änderungen von 1.3.0 gelesen, jetzt aktualisieren');
    }

    public function test_a_stale_page_a_running_update_and_test_builds_are_refused(): void
    {
        $admin = $this->admin();
        $url = "/servers/{$this->panel->id}/updates";

        $this->actingAs($admin)->from($url)->post("{$url}/panel", ['version' => '1.2.9', 'confirm' => '1'])
            ->assertSessionHas('error', 'Inzwischen gibt es eine andere neueste Version. Bitte die Seite prüfen und noch einmal bestätigen.');

        $this->state(['state' => 'running']);
        $this->actingAs($admin)->from($url)->post("{$url}/panel", ['version' => '1.3.0', 'confirm' => '1'])
            ->assertSessionHas('error', 'Es läuft bereits ein Update des Panels.');

        Setting::put(Setting::PANEL_UPDATE, null);
        config(['owpanel.version' => 'dev-099a9a2dfc84']);
        $this->actingAs($admin)->from($url)->post("{$url}/panel", ['version' => '1.3.0', 'confirm' => '1'])
            ->assertSessionHas('error', 'Es gibt keine neuere Version, oder eine Testversion ist installiert.');
        $this->assertSame(0, ServerTask::count());
    }

    public function test_customers_and_other_servers_cannot_update_the_panel(): void
    {
        $other = Server::factory()->create(['host' => '198.51.100.20']);
        $customer = User::factory()->create(['customer_id' => Customer::factory()->create()->id, 'password_change_required' => false]);

        $this->actingAs($customer)->post("/servers/{$this->panel->id}/updates/panel", ['version' => '1.3.0', 'confirm' => '1'])->assertForbidden();
        $this->actingAs($customer)->post("/servers/{$this->panel->id}/updates/panel-auto", ['enabled' => '1'])->assertForbidden();
        $admin = $this->admin();
        $this->actingAs($admin)->post("/servers/{$other->id}/updates/panel", ['version' => '1.3.0', 'confirm' => '1'])->assertNotFound();
        $this->actingAs($admin)->post("/servers/{$other->id}/updates/panel-auto", ['enabled' => '1'])->assertNotFound();
        $this->assertSame(0, ServerTask::count());
        $this->assertFalse(PanelUpdate::autoEnabled());
    }

    public function test_the_automatic_update_is_switched_on_and_off(): void
    {
        $admin = $this->admin();
        $url = "/servers/{$this->panel->id}/updates";

        $this->actingAs($admin)->post("{$url}/panel-auto", ['enabled' => '1'])->assertRedirect($url);
        $this->assertTrue(PanelUpdate::autoEnabled());
        $this->actingAs($admin)->post("{$url}/panel-auto", ['enabled' => '1']);
        $this->actingAs($admin)->post("{$url}/panel-auto", ['enabled' => '0']);
        $this->assertFalse(PanelUpdate::autoEnabled());
        $this->assertSame(2, AuditLog::where('action', 'server.panel_auto_update_changed')->count());
    }

    public function test_the_update_is_done_once_the_new_version_runs(): void
    {
        $this->admin();
        $this->state(['state' => 'running']);
        $this->follow();
        $this->assertSame('running', PanelUpdate::current()['state']);

        config(['owpanel.version' => '1.3.0']);
        $this->follow();

        $update = PanelUpdate::current();
        $this->assertSame('succeeded', $update['state']);
        $this->assertNotNull($update['finished_at']);
        $this->assertTrue(AuditLog::where('action', 'server.panel_updated')->exists());
        $this->assertCount(1, $this->pushed('panel_updated'));
        $this->assertSame('OWPanel 1.3.0 ist installiert', MessageText::title($this->pushed('panel_updated')[0]->messages[0]));

        // Nothing more once it has ended.
        $this->follow();
        $this->assertCount(1, $this->pushed('panel_updated'));
    }

    public function test_a_failed_installer_is_read_from_the_server(): void
    {
        $this->admin();
        $this->state(['state' => 'running']);
        $runner = new ScriptedRunner(['panel-update' => ['state' => 'failed', 'version' => '1.3.0', 'from' => '1.2.0', 'error' => 'install_failed']]);
        $this->follow($runner);

        $this->assertSame([['--status']], array_map(fn ($call) => $call->arguments, $runner->calls));
        $update = PanelUpdate::current();
        $this->assertSame('failed', $update['state']);
        $this->assertSame('install_failed', $update['error']);
        $this->assertTrue(AuditLog::where('action', 'server.panel_update_failed')->exists());
        $this->assertCount(1, $this->pushed('panel_update_failed'));
        $this->assertSame('Update auf OWPanel 1.3.0 fehlgeschlagen', MessageText::title($this->pushed('panel_update_failed')[0]->messages[0]));
    }

    public function test_the_task_decides_while_starting(): void
    {
        $this->admin();
        $task = ServerTask::factory()->create(['server_id' => $this->panel->id, 'script' => ServerScript::PanelUpdate, 'arguments' => ['--version', '1.3.0'], 'status' => TaskStatus::Running]);
        $this->state(['state' => 'starting', 'task' => $task->id]);
        $this->follow();
        $this->assertSame('starting', PanelUpdate::current()['state']);

        $task->forceFill(['status' => TaskStatus::Succeeded])->save();
        $runner = new ScriptedRunner(['panel-update' => ['state' => 'running', 'version' => '1.3.0']]);
        $this->follow($runner);
        $this->assertSame('running', PanelUpdate::current()['state']);
        $this->assertSame(1, count($runner->calls));

        $failed = ServerTask::factory()->failed('invalid_signature')->create(['server_id' => $this->panel->id, 'script' => ServerScript::PanelUpdate, 'arguments' => ['--version', '1.3.0']]);
        $this->state(['state' => 'starting', 'task' => $failed->id]);
        $this->follow();
        $update = PanelUpdate::current();
        $this->assertSame('failed', $update['state']);
        $this->assertSame('invalid_signature', $update['error']);
        // The failed task sends its own message.
        $this->assertCount(0, $this->pushed('panel_update_failed'));
    }

    public function test_a_finished_installer_counts_even_if_another_version_runs_now(): void
    {
        $this->state(['state' => 'running']);
        $answer = ['state' => 'succeeded', 'version' => '1.3.0', 'from' => '1.2.0', 'finished_at' => now()->subMinute()->toIso8601String()];
        $this->follow(new ScriptedRunner(['panel-update' => $answer]));
        $this->assertSame('running', PanelUpdate::current()['state']);

        $answer['finished_at'] = now()->subMinutes(10)->toIso8601String();
        $this->follow(new ScriptedRunner(['panel-update' => $answer]));
        $this->assertSame('succeeded', PanelUpdate::current()['state']);
    }

    public function test_an_update_that_does_not_end_fails_after_an_hour(): void
    {
        $this->state(['state' => 'running', 'started_at' => now()->subMinutes(61)->toIso8601String()]);
        $this->follow(new ScriptedRunner(['panel-update' => ['state' => 'running', 'version' => '1.3.0']]));

        $this->assertSame('failed', PanelUpdate::current()['state']);
        $this->assertSame('update_timeout', PanelUpdate::current()['error']);
    }

    public function test_the_automatic_update_takes_only_patch_versions_a_day_old(): void
    {
        $auto = fn () => $this->artisan('owpanel:panel-update', ['--auto' => true])->assertSuccessful();

        // Switched off.
        $this->release('1.2.1');
        $auto();
        $this->assertSame(0, ServerTask::count());

        PanelUpdate::setAuto(true);
        // A minor version, a pre-release, a release of today.
        foreach ([['1.3.0', null], ['1.2.1-rc.1', null], ['1.2.1', now()->subHours(3)->toIso8601String()]] as [$version, $published]) {
            $this->release($version, $published);
            $auto();
        }
        $this->assertSame(0, ServerTask::count());

        // Tasks running: next night.
        $this->release('1.2.1');
        $busy = ServerTask::factory()->create(['server_id' => $this->panel->id, 'status' => TaskStatus::Running]);
        $auto();
        $this->assertSame(1, ServerTask::count());
        $this->assertSame('postponed', PanelUpdate::current()['state']);
        $this->assertSame('tasks_running', PanelUpdate::current()['error']);

        $busy->forceFill(['status' => TaskStatus::Succeeded])->save();
        $auto();
        $task = ServerTask::where('script', ServerScript::PanelUpdate->value)->sole();
        $this->assertSame(['--version', '1.2.1'], $task->arguments);
        $this->assertNull($task->user_id);
        $this->assertTrue(PanelUpdate::current()['auto']);
        $this->assertSame('starting', PanelUpdate::current()['state']);
    }

    public function test_test_builds_are_never_updated_automatically(): void
    {
        config(['owpanel.version' => 'dev-099a9a2dfc84']);
        PanelUpdate::setAuto(true);
        $this->release('1.2.1');
        $this->artisan('owpanel:panel-update', ['--auto' => true])->assertSuccessful();
        $this->assertSame(0, ServerTask::count());
    }

    public function test_patch_versions_are_recognised(): void
    {
        $this->assertTrue(PanelRelease::isPatchOf('1.2.1', '1.2.0'));
        $this->assertTrue(PanelRelease::isPatchOf('1.2.10', '1.2.9'));
        $this->assertFalse(PanelRelease::isPatchOf('1.3.0', '1.2.9'));
        $this->assertFalse(PanelRelease::isPatchOf('2.2.1', '1.2.0'));
        $this->assertFalse(PanelRelease::isPatchOf('1.2.0', '1.2.0'));
        $this->assertFalse(PanelRelease::isPatchOf('1.2.1-rc.1', '1.2.0'));
        $this->assertFalse(PanelRelease::isPatchOf('1.2.1', 'dev-099a9a2dfc84'));
    }
}
