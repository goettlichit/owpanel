<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerJoinToken;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A new website server joins with a one-time code over the private network
 * (E-166, E-196).
 */
class ServerJoinTest extends TestCase
{
    use RefreshDatabase;

    private const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGqU+M1LdWzFc5Dk2pm3zjttZTNlymbzE7fmwbY3TCU7';

    private const CERT = "-----BEGIN CERTIFICATE-----\nMIIBpTCCAUugAwIBAgIUeas92\n-----END CERTIFICATE-----\n";

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['owpanel.version' => '1.3.0']);
        $this->dir = sys_get_temp_dir().'/owpanel-join-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
        config(['owpanel.release_dir' => $this->dir, 'owpanel.ssh.public_key' => $this->dir.'/panel-ssh.pub']);
        File::put($this->dir.'/owpanel-1.3.0.tar.gz', 'archive');
        File::put($this->dir.'/owpanel-1.3.0.tar.gz.sig', "-----BEGIN SSH SIGNATURE-----\nabc\n-----END SSH SIGNATURE-----\n");
        File::put($this->dir.'/panel-ssh.pub', "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOe3rYOKp4b8QsHyUJ43Ef/SCOQbiGmFG5N/eCpW0hOp owpanel\n");
        Server::factory()->panel()->create(['name' => 'gw01', 'private_ipv4' => '10.0.0.2']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function code(string $name = 'web2'): string
    {
        return ServerJoinToken::issue($name, $this->admin())[1];
    }

    /**
     * @return array<string, string>
     */
    private function registration(array $override = []): array
    {
        return array_merge([
            'hostname' => 'web2.example.de',
            'private_ipv4' => '10.0.0.3',
            'public_ipv4' => '203.0.113.20',
            'public_ipv6' => '2001:db8::20',
            'ssh_port' => '22',
            'host_key' => self::HOST_KEY,
            'backend_cert' => self::CERT,
        ], $override);
    }

    public function test_only_administrators_create_a_code_and_it_is_shown_once(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/servers/join')->assertForbidden();
        $this->actingAs($customer)->post('/servers/join', ['name' => 'web2'])->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->get('/servers/join')->assertOk()->assertSee(__('servers.join.form_title'));
        $this->actingAs($admin)->post('/servers/join', ['name' => 'web2'])->assertRedirect('/servers/join')->assertSessionHas('join_code');
        $code = session('join_code');

        $token = ServerJoinToken::sole();
        $this->assertSame('web2', $token->name);
        $this->assertSame($admin->id, $token->user_id);
        $this->assertTrue(AuditLog::where('action', 'server.join_started')->exists());

        $this->actingAs($admin)->get('/servers/join')->assertOk()
            ->assertSee('bash get-owpanel --join localhost --via 10.0.0.2', false)->assertSee($code)
            ->assertDontSee(__('servers.join.test_build'));
        $this->assertSame(hash('sha256', $code), $token->token_hash);
        // Gone after one view.
        $this->actingAs($admin)->get('/servers/join')->assertDontSee($code);
    }

    public function test_a_test_build_shows_the_dev_option(): void
    {
        config(['owpanel.version' => 'dev-abc1234']);
        $admin = $this->admin();
        $this->actingAs($admin)->post('/servers/join', ['name' => 'web2']);
        $this->actingAs($admin)->get('/servers/join')->assertOk()
            ->assertSee('bash get-owpanel --dev --join localhost --via 10.0.0.2', false)
            ->assertSee(__('servers.join.test_build'));
    }

    public function test_a_server_name_must_be_new_and_simple(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/servers/join', ['name' => 'gw01'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/servers/join', ['name' => 'Web 2;id'])->assertSessionHasErrors('name');
        $this->assertSame(0, ServerJoinToken::count());
    }

    public function test_without_a_private_address_no_code_can_be_created(): void
    {
        Server::panelHost()->forceFill(['private_ipv4' => null])->save();
        $this->actingAs($this->admin())->get('/servers/join')->assertOk()->assertSee(__('servers.join.no_private'));
    }

    public function test_the_new_server_gets_what_it_needs_only_with_its_code(): void
    {
        $this->get('/join/info')->assertNotFound();
        $this->withToken('wrong-code-wrong-code-wrong-code-123')->get('/join/info')->assertNotFound();

        $code = $this->code();
        $info = $this->withToken($code)->get('/join/info')->assertOk();
        $this->assertStringContainsString("version=1.3.0\n", $info->getContent());
        $this->assertStringContainsString("name=web2\n", $info->getContent());
        $this->assertStringContainsString("ssh_key=ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOe3rYOKp4b8QsHyUJ43Ef/SCOQbiGmFG5N/eCpW0hOp owpanel\n", $info->getContent());
        $this->assertStringContainsString('sha256='.hash('sha256', 'archive')."\n", $info->getContent());
        $this->assertStringContainsString("signed=1\n", $info->getContent());

        $archive = $this->withToken($code)->get('/join/archive')->assertOk();
        $this->assertSame($this->dir.'/owpanel-1.3.0.tar.gz', $archive->baseResponse->getFile()->getPathname());
        $this->withToken($code)->get('/join/signature')->assertOk()->assertSee('BEGIN SSH SIGNATURE');
    }

    public function test_an_expired_code_does_not_work(): void
    {
        $code = $this->code();
        $this->travel(ServerJoinToken::MINUTES + 1)->minutes();
        $this->withToken($code)->get('/join/info')->assertNotFound();
        $this->withToken($code)->post('/join/register', $this->registration())->assertNotFound();
        $this->assertSame(1, Server::count());
    }

    public function test_registering_pins_the_host_key_and_confirms_the_firewall(): void
    {
        $code = $this->code();
        $response = $this->withToken($code)->post('/join/register', $this->registration())->assertOk();
        $this->assertStringContainsString("ok=1\n", $response->getContent());

        $server = Server::where('name', 'web2')->sole();
        $this->assertSame('10.0.0.3', $server->host);
        $this->assertSame('10.0.0.3', $server->private_ipv4);
        $this->assertSame(22, $server->port);
        $this->assertSame(self::HOST_KEY, $server->host_key);
        $this->assertSame('web2.example.de', $server->hostname);
        $this->assertSame('203.0.113.20', $server->public_ipv4);
        $this->assertSame(self::CERT, $server->backend_cert);
        $this->assertFalse($server->hostsPanel());

        $token = ServerJoinToken::sole();
        $this->assertNotNull($token->used_at);
        $this->assertSame($server->id, $token->server_id);
        $task = ServerTask::where('server_id', $server->id)->sole();
        $this->assertSame(ServerScript::FirewallConfirm, $task->script);
        $this->assertTrue(AuditLog::where('action', 'server.joined')->exists());

        // Used up.
        $this->withToken($code)->post('/join/register', $this->registration(['private_ipv4' => '10.0.0.4']))->assertNotFound();
        $this->withToken($code)->get('/join/info')->assertNotFound();
        $this->assertSame(2, Server::count());
    }

    public function test_the_same_server_may_join_again_after_an_aborted_run(): void
    {
        $this->withToken($this->code())->post('/join/register', $this->registration())->assertOk();
        $server = Server::where('host', '10.0.0.3')->sole();

        // A new code with another name; same machine, same host key.
        $this->withToken($this->code('web3'))->post('/join/register', $this->registration(['hostname' => 'web2b.example.de']))->assertOk();
        $this->assertSame(2, Server::count());
        $this->assertSame('web2', $server->fresh()->name);
        $this->assertSame('web2b.example.de', $server->fresh()->hostname);
        $this->assertSame(2, ServerTask::where('server_id', $server->id)->count());

        // Another machine on that address is refused.
        $other = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOe3rYOKp4b8QsHyUJ43Ef/SCOQbiGmFG5N/eCpW0hOp';
        $this->withToken($this->code('web4'))->post('/join/register', $this->registration(['host_key' => $other]))->assertStatus(409);
        $this->assertSame(self::HOST_KEY, $server->fresh()->host_key);
    }

    public function test_registering_checks_what_the_server_sends(): void
    {
        $code = $this->code();
        foreach ([
            ['private_ipv4' => '203.0.113.20'],
            ['private_ipv4' => '10.0.0.2'],
            ['hostname' => 'web2;id'],
            ['host_key' => 'ssh-rsa AAAA'],
            ['backend_cert' => "-----BEGIN CERTIFICATE-----\n\$(id)\n-----END CERTIFICATE-----\n"],
            ['ssh_port' => '0'],
        ] as $bad) {
            $this->withToken($code)->post('/join/register', $this->registration($bad))->assertStatus(isset($bad['private_ipv4']) && $bad['private_ipv4'] === '10.0.0.2' ? 409 : 422);
        }
        $this->assertSame(1, Server::count());
        $this->assertNull(ServerJoinToken::sole()->used_at);
    }

    public function test_the_join_routes_skip_the_csrf_check(): void
    {
        // Tests run without the CSRF check, so the route itself is checked.
        foreach (['join.info', 'join.archive', 'join.signature', 'join.register'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertContains(PreventRequestForgery::class, $route->excludedMiddleware(), $name);
        }
        // And it is the one the web group runs.
        $this->assertContains(PreventRequestForgery::class, app(Kernel::class)->getMiddlewareGroups()['web']);
    }

    public function test_the_join_routes_are_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->withToken('nope')->get('/join/info')->assertNotFound();
        }
        $this->withToken('nope')->get('/join/info')->assertStatus(429);
    }

    public function test_websites_on_a_joined_server_trust_the_gateway_by_its_private_address(): void
    {
        $web2 = Server::factory()->create(['name' => 'web2', 'host' => '10.0.0.3', 'private_ipv4' => '10.0.0.3', 'backend_cert' => self::CERT]);
        $this->actingAs($this->admin())->post('/sites', ['domain' => 'shop.example.de', 'tls' => '1', 'server' => $web2->id])->assertRedirect();

        $backend = ServerTask::where('script', ServerScript::SiteCreate->value)->sole();
        $this->assertSame($web2->id, $backend->server_id);
        $arguments = $backend->arguments;
        $this->assertSame('10.0.0.2', $arguments[array_search('--gateway', $arguments, true) + 1]);
        $this->assertSame('10.0.0.3', $arguments[array_search('--listen', $arguments, true) + 1]);
        $this->assertSame(1, Site::count());
    }
}
