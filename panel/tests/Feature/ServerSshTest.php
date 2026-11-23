<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\ScriptedRunner;
use Tests\TestCase;

/**
 * SSH password login of a server (E-155).
 */
class ServerSshTest extends TestCase
{
    use RefreshDatabase;

    protected bool $renderDeferred = true;

    /**
     * @param  array<string, mixed>  $status
     */
    private function sshState(array $status): ScriptedRunner
    {
        $runner = new ScriptedRunner(['server-ssh' => $status + [
            'password_login' => true, 'managed' => false, 'allow_users' => '', 'allow_groups' => '', 'sftp_allowed' => true,
            'accounts' => [['user' => 'root', 'key' => true]],
        ]]);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_the_script_calls(): void
    {
        $this->assertSame(['--status'], ScriptCall::sshStatus()->arguments);
        $this->assertSame(['--password-login', 'off'], ScriptCall::sshPasswordLogin(false)->arguments);
        $this->assertSame(['--password-login', 'on'], ScriptCall::sshPasswordLogin(true)->arguments);
    }

    public function test_shows_the_state_and_turns_password_login_off(): void
    {
        $server = Server::factory()->create();
        $runner = $this->sshState([]);

        $this->actingAs($this->admin())->get("/servers/{$server->id}/ssh")->assertOk()
            ->assertSee('Administratoren können sich per SSH mit Passwort anmelden.')
            ->assertSee('root')->assertSee('vorhanden')
            ->assertSee('Passwort-Anmeldung abschalten');

        $this->actingAs($this->admin())->post("/servers/{$server->id}/ssh", ['password_login' => 'off'])
            ->assertRedirect("/servers/{$server->id}/ssh");
        $this->assertContains(['--password-login', 'off'], array_map(fn ($call) => $call->arguments, $runner->calls));
        $this->assertSame(ServerScript::ServerSsh, ServerTask::sole()->script);
        $this->assertFalse(AuditLog::where('action', 'server.ssh_password_login')->sole()->context['allowed']);
    }

    public function test_a_missing_key_disables_the_button(): void
    {
        $server = Server::factory()->create();
        $this->sshState(['accounts' => [['user' => 'root', 'key' => true], ['user' => 'alex', 'key' => false]]]);

        $this->actingAs($this->admin())->get("/servers/{$server->id}/ssh")->assertOk()
            ->assertSee('Du würdest dich aussperren.')
            ->assertSee('fehlt')
            ->assertSeeInOrder(['disabled', 'Passwort-Anmeldung abschalten'], false);
    }

    public function test_when_off_it_can_be_allowed_again_unless_set_outside(): void
    {
        $server = Server::factory()->create();
        $this->sshState(['password_login' => false, 'managed' => true]);
        $this->actingAs($this->admin())->get("/servers/{$server->id}/ssh")->assertOk()
            ->assertSee('nur mit Schlüssel an')->assertSee('Passwort-Anmeldung wieder erlauben');

        $this->sshState(['password_login' => false, 'managed' => false]);
        $this->actingAs($this->admin())->get("/servers/{$server->id}/ssh")->assertOk()
            ->assertSee('außerhalb des Panels')->assertDontSee('wieder erlauben');
    }

    public function test_one_change_at_a_time_and_only_for_admins(): void
    {
        $server = Server::factory()->create();
        $this->sshState([]);
        ServerTask::factory()->create(['server_id' => $server->id, 'script' => ServerScript::ServerSsh, 'status' => TaskStatus::Running]);
        $this->actingAs($this->admin())->post("/servers/{$server->id}/ssh", ['password_login' => 'off'])->assertSessionHas('error');
        $this->actingAs($this->admin())->post("/servers/{$server->id}/ssh", ['password_login' => 'maybe'])->assertSessionHasErrors('password_login');

        $user = User::factory()->create(['customer_id' => Customer::factory()->create()->id, 'password_change_required' => false]);
        $this->actingAs($user)->get("/servers/{$server->id}/ssh")->assertForbidden();
        $this->actingAs($user)->post("/servers/{$server->id}/ssh", ['password_login' => 'on'])->assertForbidden();
        $this->assertSame(1, ServerTask::count());
    }

    public function test_the_sftp_page_warns_admins_when_sshd_keeps_customers_out(): void
    {
        $site = Site::factory()->create(['sftp_enabled' => true]);
        $this->sshState(['allow_groups' => 'admins', 'sftp_allowed' => false]);

        $this->actingAs($this->admin())->get("/sites/{$site->id}/sftp")->assertOk()
            ->assertSee('owpanel-sftp ist nicht dabei');
        $this->actingAs($this->admin())->get("/servers/{$site->server_id}/ssh")->assertOk()
            ->assertSee('AllowGroups')->assertSee('admins');
    }
}
