<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Jobs\ReadLog;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Servers\StatusCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\ScriptedRunner;
use Tests\TestCase;

/**
 * Every website has a backend on its server and a gateway on the panel
 * server (E-195); the gateway pins the backend's certificate (E-186).
 */
class GatewayBackendTest extends TestCase
{
    use RefreshDatabase;

    private const CERT = "-----BEGIN CERTIFICATE-----\nMIIBpTCCAUugAwIBAgIUeas92\n-----END CERTIFICATE-----\n";

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_a_website_on_another_server_gets_its_backend_there_and_its_gateway_on_the_panel_server(): void
    {
        Queue::fake();
        $panel = Server::factory()->panel()->create(['name' => 'gw01']);
        $web2 = Server::factory()->create(['name' => 'web2', 'host' => '10.0.0.3', 'backend_cert' => self::CERT]);

        $this->actingAs($this->admin())->post('/sites', ['domain' => 'shop.example.de', 'tls' => '1', 'server' => $web2->id])->assertRedirect();

        $site = Site::sole();
        $backend = ServerTask::where('script', ServerScript::SiteCreate->value)->sole();
        $gateway = ServerTask::where('script', ServerScript::GatewaySite->value)->sole();
        $this->assertSame($web2->id, $backend->server_id);
        $this->assertSame(['--listen', '10.0.0.3'], array_slice($backend->arguments, 6, 2));
        $this->assertSame($panel->id, $gateway->server_id);
        $this->assertSame($backend->id, $gateway->after_task_id);
        $this->assertSame(
            ['--domain', 'shop.example.de', '--user', $site->system_user, '--backend-id', $web2->backendId(), '--backend', '10.0.0.3', '--backend-cert', '--tls'],
            array_slice($gateway->arguments, 0, 10),
        );
        // The certificate as the panel knows it when the task runs.
        $this->assertSame(self::CERT, $gateway->call()->stdin);
        $web2->forceFill(['backend_cert' => null])->save();
        $this->expectException(\RuntimeException::class);
        $gateway->fresh()->call();
    }

    public function test_websites_on_the_panel_server_need_no_certificate_from_the_panel(): void
    {
        Queue::fake();
        $panel = Server::factory()->panel()->create();

        $this->actingAs($this->admin())->post('/sites', ['domain' => 'shop.example.de'])->assertRedirect();

        $gateway = ServerTask::where('script', ServerScript::GatewaySite->value)->sole();
        $this->assertSame($panel->id, $gateway->server_id);
        $this->assertContains('127.0.0.1', $gateway->arguments);
        $this->assertNotContains('--backend-cert', $gateway->arguments);
        $this->assertNull($gateway->call()->stdin);
        $this->assertNotContains('--listen', ServerTask::where('script', ServerScript::SiteCreate->value)->sole()->arguments);
    }

    public function test_the_status_check_keeps_a_valid_backend_certificate_only(): void
    {
        $server = Server::factory()->create();
        $status = fn (mixed $cert) => [
            'hostname' => 'web2.example.de', 'os' => 'Debian GNU/Linux 13 (trixie)', 'kernel' => '6.12.48+deb13-amd64',
            'uptime' => 7200, 'cpu_cores' => 2, 'cpu' => 12.5, 'load' => [0.5, 0.4, 0.3],
            'memory' => ['total' => 4_000_000_000, 'used' => 1_000_000_000], 'swap' => ['total' => 0, 'used' => 0],
            'disk' => ['total' => 40_000_000_000, 'used' => 30_000_000_000], 'network' => ['rx' => 1, 'tx' => 1],
            'reboot_required' => false, 'backend_cert' => $cert,
        ];

        (new StatusCheck(new ScriptedRunner(['server-status' => $status(self::CERT)])))->run($server);
        $this->assertSame(self::CERT, $server->fresh()->backend_cert);

        // Anything else is ignored; the known certificate stays.
        foreach (["-----BEGIN CERTIFICATE-----\n\$(id)\n-----END CERTIFICATE-----\n", 'x', 42, null] as $bad) {
            (new StatusCheck(new ScriptedRunner(['server-status' => $status($bad)])))->run($server->fresh());
            $this->assertSame(self::CERT, $server->fresh()->backend_cert);
        }
    }

    public function test_the_access_log_is_read_at_the_gateway_the_error_log_where_the_website_runs(): void
    {
        $panel = Server::factory()->panel()->create();
        $web2 = Server::factory()->create(['host' => '10.0.0.3']);
        $site = Site::factory()->for($web2)->create();
        $runner = new class implements ScriptRunner
        {
            /** @var list<string> */
            public array $servers = [];

            public function run(Server $server, ScriptCall $call, callable $onOutput): ScriptResult
            {
                $this->servers[] = $server->id;

                return ScriptResult::fromOutput('site-logs', '{"ok":true,"script":"site-logs","lines":[]}'."\n", 0);
            }
        };

        (new ReadLog('a1', ReadLog::SITE, $site->id, 'access', 50))->handle($runner);
        (new ReadLog('a2', ReadLog::SITE, $site->id, 'error', 50))->handle($runner);

        $this->assertSame([$panel->id, $web2->id], $runner->servers);
    }
}
