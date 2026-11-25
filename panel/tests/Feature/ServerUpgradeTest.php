<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Setting;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptRunner;
use App\Support\PanelRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\ScriptedRunner;
use Tests\TestCase;

/**
 * Installing pending updates and the panel's own version (E-154).
 */
class ServerUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function serverWithUpdates(int $pending = 2): Server
    {
        return Server::factory()->create(['updates_checked_at' => now(), 'updates' => [
            'pending' => $pending, 'security' => 1, 'automatic' => true,
            'packages' => [['name' => 'openssl', 'from' => '3.5.1-1', 'to' => '3.5.1-1+deb13u1', 'security' => true], ['name' => 'tzdata', 'from' => '2026a-1', 'to' => '2026b-1', 'security' => false]],
            'runs' => [],
        ]]);
    }

    public function test_the_script_call_takes_one_mode(): void
    {
        $this->assertSame(['--install'], ScriptCall::serverUpgrade(true)->arguments);
        $this->assertSame(['--refresh'], ScriptCall::serverUpgrade(false)->arguments);
    }

    public function test_installing_runs_the_script_and_reads_the_state_again(): void
    {
        $server = $this->serverWithUpdates();
        $runner = new ScriptedRunner(['server-upgrade' => ['mode' => 'install', 'changed' => true, 'reboot_file' => false]]);
        $this->app->instance(ScriptRunner::class, $runner);

        $this->actingAs($this->admin())->get("/servers/{$server->id}/updates")->assertOk()
            ->assertSee('Alle installieren (2)')
            ->assertSee('data-ow-busy="Updates werden installiert …" data-ow-task="server-upgrade"', false);
        $this->actingAs($this->admin())->from("/servers/{$server->id}/updates")
            ->post("/servers/{$server->id}/updates/install")->assertRedirect("/servers/{$server->id}/updates");

        $this->assertSame(['--install'], $runner->calls[0]->arguments);
        $this->assertSame(TaskStatus::Succeeded, ServerTask::sole()->status);
        $this->assertNull($server->fresh()->updates_checked_at);
        $this->assertTrue(AuditLog::where('action', 'server.updates_installed')->exists());
    }

    public function test_nothing_to_install_or_already_running(): void
    {
        $server = $this->serverWithUpdates(0);
        $this->actingAs($this->admin())->post("/servers/{$server->id}/updates/install")->assertSessionHas('error');

        $server = $this->serverWithUpdates();
        ServerTask::factory()->create(['server_id' => $server->id, 'script' => ServerScript::ServerUpgrade, 'status' => TaskStatus::Running]);
        $this->actingAs($this->admin())->post("/servers/{$server->id}/updates/install")->assertSessionHas('error');
        $this->actingAs($this->admin())->post("/servers/{$server->id}/updates/check")->assertSessionHas('error');
        $this->assertSame(1, ServerTask::count());
    }

    public function test_customers_cannot_install(): void
    {
        $server = $this->serverWithUpdates();
        $user = User::factory()->create(['customer_id' => Customer::factory()->create()->id, 'password_change_required' => false]);

        $this->actingAs($user)->post("/servers/{$server->id}/updates/install")->assertForbidden();
        $this->actingAs($user)->post("/servers/{$server->id}/updates/release")->assertForbidden();
        $this->assertSame(0, ServerTask::count());
    }

    public function test_the_panel_server_shows_installed_and_newest_version(): void
    {
        config(['owpanel.version' => '1.2.0', 'owpanel.source_url' => 'https://github.com/example/owpanel']);
        Http::fake(['api.github.com/repos/example/owpanel/releases/latest' => Http::response(['tag_name' => 'v1.3.0'])]);
        $panel = Server::factory()->create(['host' => '127.0.0.1']);
        $other = Server::factory()->create(['host' => '198.51.100.20']);

        $this->actingAs($this->admin())->post("/servers/{$panel->id}/updates/release")->assertRedirect("/servers/{$panel->id}/updates");
        $this->assertSame('1.3.0', PanelRelease::latest()['version']);
        $this->assertTrue(PanelRelease::updateAvailable());

        $this->actingAs($this->admin())->get("/servers/{$panel->id}/updates")->assertOk()
            ->assertSee('OWPanel')->assertSee('1.2.0')->assertSee('1.3.0')
            ->assertSee('Neue Version verfügbar')
            ->assertSee('Änderungen von 1.3.0 gelesen, jetzt aktualisieren');
        $this->actingAs($this->admin())->get("/servers/{$other->id}/updates")->assertOk()->assertDontSee('Panel aktualisieren');
        $this->actingAs($this->admin())->post("/servers/{$other->id}/updates/release")->assertNotFound();
    }

    public function test_a_newest_version_older_than_the_installed_one_is_not_shown(): void
    {
        config(['owpanel.version' => '1.2.0', 'owpanel.source_url' => 'https://github.com/example/owpanel']);
        Setting::put(Setting::PANEL_RELEASE, (string) json_encode(['version' => '1.1.0', 'checked_at' => now()->subHour()->toIso8601String(), 'error' => null]));
        $panel = Server::factory()->create(['host' => '127.0.0.1']);

        $this->actingAs($this->admin())->get("/servers/{$panel->id}/updates")->assertOk()
            ->assertSee('Die letzte Prüfung war vor dem Update')
            ->assertDontSee('<code>1.1.0</code>', false)
            ->assertDontSee('Neue Version verfügbar');
    }

    public function test_a_private_repository_and_test_builds_are_said_so(): void
    {
        config(['owpanel.version' => 'dev-099a9a2dfc84', 'owpanel.source_url' => 'https://github.com/example/owpanel']);
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);
        Setting::put(Setting::PANEL_RELEASE, null);

        PanelRelease::check();

        $this->assertSame('not_found', PanelRelease::latest()['error']);
        $this->assertTrue(PanelRelease::isTestBuild());
        $this->assertFalse(PanelRelease::updateAvailable());
        $panel = Server::factory()->create(['host' => '127.0.0.1']);
        $this->actingAs($this->admin())->get("/servers/{$panel->id}/updates")->assertOk()
            ->assertSee('Testversion, kein Vergleich')
            ->assertSee('Solange das Repository privat ist');
    }
}
