<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\User;
use Database\Factories\ServerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Crypt\PublicKeyLoader;
use Tests\TestCase;

class ServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_servers_and_the_test_button(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $server = Server::factory()->create(['name' => 'web1', 'host' => '127.0.0.1']);

        $this->actingAs($admin)->get('/servers')
            ->assertOk()
            ->assertSee('web1')
            ->assertSee('127.0.0.1')
            ->assertSee($server->host_key_fingerprint);

        $this->actingAs($admin)->get("/servers/{$server->id}")
            ->assertOk()
            ->assertSee('owpanel-agent')
            ->assertSee('Testskript starten')
            ->assertSee("/servers/{$server->id}/test");
    }

    public function test_the_tasks_of_a_server_have_their_own_page(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $server = Server::factory()->create();
        $other = Server::factory()->create();
        ServerTask::factory()->create(['server_id' => $server->id, 'script' => ServerScript::ServerStatus]);
        ServerTask::factory()->create(['server_id' => $other->id, 'script' => ServerScript::ServerReboot]);

        $this->actingAs($admin)->get("/servers/{$server->id}")
            ->assertSee('href="'.url("/servers/{$server->id}/tasks").'"', false)
            ->assertDontSee('Letzte Aufgaben');
        $this->actingAs($admin)->get("/servers/{$server->id}/tasks")
            ->assertOk()
            ->assertSee(__('tasks.scripts.server-status'))
            ->assertDontSee(__('tasks.scripts.server-reboot'));
        $this->actingAs(User::factory()->create())->get("/servers/{$server->id}/tasks")->assertForbidden();
    }

    public function test_admin_sees_servers_in_the_navigation_and_no_separate_tasks_area(): void
    {
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get('/')
            ->assertSee('href="'.url('/servers').'"', false)
            ->assertDontSee('href="'.url('/tasks').'"', false)
            ->assertDontSee('Aufgaben &amp; Logs', false);
    }

    public function test_customers_see_no_servers(): void
    {
        $customer = User::factory()->create();
        $server = Server::factory()->create();

        $this->actingAs($customer)->get('/')
            ->assertDontSee('href="'.url('/servers').'"', false);
        $this->actingAs($customer)->get('/servers')->assertForbidden();
        $this->actingAs($customer)->get("/servers/{$server->id}")->assertForbidden();
    }

    public function test_an_unknown_server_is_not_found(): void
    {
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get('/servers/01JAAAAAAAAAAAAAAAAAAAAAAA')
            ->assertNotFound();
    }

    public function test_add_server_reads_the_host_key_from_a_file(): void
    {
        $key = ServerFactory::hostKey(str_repeat('k', 32));
        $file = tempnam(sys_get_temp_dir(), 'hostkey');
        file_put_contents($file, $key." root@web1\n");

        $this->artisan('owpanel:add-server', [
            '--name' => 'web1',
            '--host' => '127.0.0.1',
            '--host-key-file' => $file,
        ])->assertSuccessful();

        $server = Server::sole();
        $this->assertSame($key, $server->host_key);
        $this->assertSame(22, $server->port);
        $this->assertSame('server.added', AuditLog::sole()->action);

        // The same host and port cannot be added twice.
        $this->artisan('owpanel:add-server', [
            '--name' => 'web1 again',
            '--host' => '127.0.0.1',
            '--host-key-file' => $file,
        ])->assertFailed();
        $this->assertSame(1, Server::count());

        unlink($file);
    }

    public function test_add_server_skip_existing_succeeds_only_with_the_same_host_key(): void
    {
        $key = ServerFactory::hostKey(str_repeat('k', 32));
        Server::factory()->create(['host' => '127.0.0.1', 'port' => 2222, 'host_key' => $key]);
        $same = tempnam(sys_get_temp_dir(), 'hostkey');
        file_put_contents($same, $key);
        $other = tempnam(sys_get_temp_dir(), 'hostkey');
        file_put_contents($other, ServerFactory::hostKey(str_repeat('o', 32)));

        $options = ['--name' => 'web1', '--host' => '127.0.0.1', '--port' => '2222', '--skip-existing' => true];
        $this->artisan('owpanel:add-server', [...$options, '--host-key-file' => $same])->assertSuccessful();
        $this->artisan('owpanel:add-server', [...$options, '--host-key-file' => $other])->assertFailed();

        $this->assertSame($key, Server::sole()->host_key);
        unlink($same);
        unlink($other);
    }

    public function test_the_panel_server_is_marked_by_a_column_not_by_its_address(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'hostkey');
        file_put_contents($file, ServerFactory::hostKey(str_repeat('m', 32)));
        $options = ['--name' => 'gw01', '--host' => '127.0.0.1', '--host-key-file' => $file, '--skip-existing' => true];

        // An older installation: the own server exists without the mark.
        $this->artisan('owpanel:add-server', $options)->assertSuccessful();
        $own = Server::sole();
        $this->assertFalse($own->hostsPanel());
        $this->assertNull(Server::panelHost());

        // The installer runs again with --panel and marks it.
        $this->artisan('owpanel:add-server', [...$options, '--panel' => true])->assertSuccessful();
        $this->assertTrue($own->fresh()->hostsPanel());
        $this->assertSame($own->id, Server::panelHost()?->id);

        // A second server cannot take the mark, whatever its address.
        $other = tempnam(sys_get_temp_dir(), 'hostkey');
        file_put_contents($other, ServerFactory::hostKey(str_repeat('n', 32)));
        $this->artisan('owpanel:add-server', ['--name' => 'web2', '--host' => '192.0.2.3', '--host-key-file' => $other, '--panel' => true])
            ->assertFailed();
        $this->artisan('owpanel:add-server', ['--name' => 'web2', '--host' => '192.0.2.3', '--host-key-file' => $other])
            ->assertSuccessful();
        $this->assertFalse(Server::where('name', 'web2')->sole()->hostsPanel());
        $this->assertSame($own->id, Server::panelHost()?->id);

        // The address alone means nothing any more.
        $this->assertFalse(Server::factory()->create(['host' => '::1'])->hostsPanel());

        unlink($file);
        unlink($other);
    }

    public function test_public_addresses_are_stored_shown_and_filled_in_later(): void
    {
        $key = ServerFactory::hostKey(str_repeat('p', 32));
        $file = tempnam(sys_get_temp_dir(), 'hostkey');
        file_put_contents($file, $key);
        $options = ['--name' => 'web1', '--host' => '127.0.0.1', '--host-key-file' => $file, '--skip-existing' => true];

        $this->artisan('owpanel:add-server', $options)->assertSuccessful();
        $this->assertNull(Server::sole()->public_ipv4);

        $this->artisan('owpanel:add-server', [...$options, '--public-ipv4' => '203.0.113.10', '--public-ipv6' => '2001:db8::10'])
            ->assertSuccessful();
        $server = Server::sole();
        $this->assertSame('203.0.113.10', $server->public_ipv4);
        $this->assertSame('2001:db8::10', $server->public_ipv6);

        $admin = User::factory()->admin()->withTwoFactor()->create();
        $this->actingAs($admin)->get('/servers')->assertSee('203.0.113.10')->assertSee('2001:db8::10');
        $this->actingAs($admin)->get("/servers/{$server->id}")->assertSee('203.0.113.10')->assertSee('127.0.0.1');

        $this->artisan('owpanel:add-server', [...$options, '--public-ipv4' => '2001:db8::10'])->assertFailed();
        unlink($file);
    }

    public function test_add_server_rejects_invalid_input(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'hostkey');
        file_put_contents($file, ServerFactory::hostKey());
        $rsa = tempnam(sys_get_temp_dir(), 'hostkey');
        file_put_contents($rsa, "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQ root@web1\n");

        foreach ([
            ['--name' => 'x', '--host' => 'bad host;id', '--host-key-file' => $file],
            ['--name' => 'x', '--host' => '127.0.0.1', '--port' => '70000', '--host-key-file' => $file],
            ['--name' => 'x', '--host' => '127.0.0.1', '--host-key-file' => $rsa],
            ['--name' => 'x', '--host' => '127.0.0.1', '--host-key-file' => '/nonexistent'],
            ['--host' => '127.0.0.1', '--host-key-file' => $file],
        ] as $options) {
            $this->artisan('owpanel:add-server', $options)->assertFailed();
        }

        $this->assertSame(0, Server::count());
        unlink($file);
        unlink($rsa);
    }

    public function test_ssh_key_is_created_once_and_readable_only_by_its_owner(): void
    {
        $dir = sys_get_temp_dir().'/owpanel-'.bin2hex(random_bytes(4));
        $path = $dir.'/ssh/id_ed25519';
        config(['owpanel.ssh.private_key' => $path]);

        $this->artisan('owpanel:ssh-key')
            ->expectsOutputToContain('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5')
            ->assertSuccessful();

        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms(dirname($path))), -4));
        $first = file_get_contents($path);
        $public = PublicKeyLoader::loadPrivateKey($first)->getPublicKey()->toString('OpenSSH', ['comment' => 'owpanel']);

        // A second run keeps the key and prints the same public key.
        $this->artisan('owpanel:ssh-key')
            ->expectsOutputToContain($public)
            ->assertSuccessful();
        $this->assertSame($first, file_get_contents($path));

        // setup-agent accepts exactly this form (server/lib/common.sh).
        $this->assertMatchesRegularExpression('#^ssh-ed25519 AAAAC3NzaC1lZDI1NTE5[A-Za-z0-9+/]{48} owpanel$#', $public);

        unlink($path);
        rmdir(dirname($path));
        rmdir($dir);
    }

    /**
     * The allowlist in the panel, the sudo rules and the scripts in
     * server/bin must name the same scripts (E-051).
     */
    public function test_allowlist_matches_the_sudo_rules_and_the_scripts(): void
    {
        $repository = dirname(base_path());
        $allowlist = array_map(fn (ServerScript $script) => $script->path(), ServerScript::cases());
        sort($allowlist);

        preg_match_all('#/opt/owpanel/bin/[a-z0-9-]+#', (string) file_get_contents($repository.'/server/templates/sudoers/owpanel-agent'), $matches);
        $sudo = $matches[0];
        sort($sudo);

        $scripts = array_map(fn (string $file) => ServerScript::DIRECTORY.'/'.basename($file), glob($repository.'/server/bin/*'));
        sort($scripts);

        $this->assertSame($sudo, $allowlist);
        $this->assertSame($scripts, $allowlist);
    }
}
