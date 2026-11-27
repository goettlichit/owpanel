<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Enums\TaskStatus;
use App\Models\Customer;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\SftpKey;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptRunner;
use App\Support\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * SFTP access of websites (E-089).
 */
class SiteSftpTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGqU+M1LdWzFc5Dk2pm3zjttZTNlymbzE7fmwbY3TCU7 anna@laptop';

    private const KEY_FINGERPRINT = 'SHA256:yMYPou2QD04nX2Ootph7KXrFFT+ITR0aZ72+6rwLe6I';

    private const ECDSA = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBOnmqvORnRRQJpb+nca84NOss+akkqCoq83LJYWPNqLkTy1XuiV4tQzsoOvuMAE7437fZ/P6+0xy9do4PXhG/S4=';

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function site(): Site
    {
        return Site::factory()->create(['system_user' => 'ow38217', 'domain' => 'shop.example.de']);
    }

    private function runner(): FakeScriptRunner
    {
        $runner = new FakeScriptRunner(['{"ok":true,"script":"site-sftp","enabled":true}'."\n"]);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    public function test_turning_on_creates_a_password_and_sends_it_on_stdin(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $admin = $this->admin();

        $this->actingAs($admin)->get("/sites/{$site->id}/sftp")->assertOk()->assertSee('ausgeschaltet')->assertSee('ow38217');

        $this->actingAs($admin)->post("/sites/{$site->id}/sftp/toggle")
            ->assertRedirect("/sites/{$site->id}/sftp")->assertSessionHas('status');

        $site->refresh();
        $this->assertTrue($site->sftp_enabled);
        $this->assertTrue(SftpKey::validPassword((string) $site->sftp_password));

        $task = ServerTask::sole();
        $this->assertSame(ServerScript::SiteSftp, $task->script);
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame(['--user', 'ow38217', '--state', 'on'], $runner->calls[0]->arguments);
        $this->assertSame("password\t{$site->sftp_password}\n", $runner->calls[0]->stdin);
        // The password is stored encrypted and never in the task.
        $this->assertStringNotContainsString($site->sftp_password, (string) $site->getRawOriginal('sftp_password'));
        $this->assertStringNotContainsString($site->sftp_password, json_encode($task->toArray()));

        $this->actingAs($admin)->get("/sites/{$site->id}/sftp")->assertSee('eingeschaltet')->assertDontSee($site->sftp_password);
    }

    public function test_the_password_is_shown_only_after_confirmation_and_audited(): void
    {
        $this->runner();
        $site = $this->site();
        $site->forceFill(['sftp_enabled' => true, 'sftp_password' => 'Abcdefgh12345678'])->save();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get("/sites/{$site->id}/sftp/password")->assertOk()->assertSee('Abcdefgh12345678');
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.sftp_password_revealed']);
    }

    public function test_keys_are_checked_stored_and_sent(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $site->forceFill(['sftp_enabled' => true])->save();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/sites/{$site->id}/sftp/keys", ['public_key' => '  '.self::KEY."\n"])->assertRedirect();
        $this->actingAs($admin)->post("/sites/{$site->id}/sftp/keys", ['public_key' => self::ECDSA, 'key_name' => 'Server'])->assertRedirect();

        $keys = $site->sftpKeys()->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['anna@laptop', 'Server'], $keys->pluck('name')->all());
        $this->assertSame(self::KEY_FINGERPRINT, $keys[0]->fingerprint);
        $this->assertSame("key\t".self::KEY."\nkey\t".self::ECDSA."\n", $runner->calls[1]->stdin);

        // The same key twice is refused.
        $this->actingAs($admin)->post("/sites/{$site->id}/sftp/keys", ['public_key' => self::KEY])->assertSessionHasErrors('public_key');

        $this->actingAs($admin)->delete("/sites/{$site->id}/sftp/keys/{$keys[0]->id}")->assertRedirect();
        $this->assertSame("key\t".self::ECDSA."\n", $runner->calls[2]->stdin);
        $this->assertCount(3, $runner->calls);
    }

    public function test_invalid_keys_are_refused(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $admin = $this->admin();
        [$type, $blob] = explode(' ', self::KEY);

        foreach ([
            'from="1.2.3.4" '.self::KEY,
            'command="id" '.self::KEY,
            'ssh-dss AAAAB3NzaC1kc3MAAACBAP',
            'ssh-rsa '.$blob,
            $type.' '.substr($blob, 0, -8),
            $type.' '.$blob." x\nssh-ed25519 ".$blob,
            '-----BEGIN OPENSSH PRIVATE KEY-----',
            'ssh-ed25519 AAAA$(id)',
        ] as $key) {
            $this->actingAs($admin)->post("/sites/{$site->id}/sftp/keys", ['public_key' => $key])->assertSessionHasErrors('public_key');
        }

        $this->assertSame(0, SftpKey::count());
        $this->assertSame([], $runner->calls);
    }

    public function test_turning_off_sends_no_credentials(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $site->forceFill(['sftp_enabled' => true, 'sftp_password' => 'Abcdefgh12345678'])->save();
        $site->sftpKeys()->create(['name' => 'a', 'public_key' => self::KEY, 'fingerprint' => self::KEY_FINGERPRINT]);

        $this->actingAs($this->admin())->post("/sites/{$site->id}/sftp/toggle")->assertRedirect();

        $this->assertFalse($site->fresh()->sftp_enabled);
        $this->assertSame(['--user', 'ow38217', '--state', 'off'], $runner->calls[0]->arguments);
        $this->assertNull($runner->calls[0]->stdin);
        // Password and keys stay for later.
        $this->assertSame('Abcdefgh12345678', $site->fresh()->sftp_password);
        $this->assertSame(1, $site->sftpKeys()->count());
    }

    public function test_changes_while_off_stay_in_the_panel(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/sites/{$site->id}/sftp/keys", ['public_key' => self::KEY])->assertRedirect();
        $this->actingAs($admin)->post("/sites/{$site->id}/sftp/password", ['action' => 'generate'])->assertRedirect();

        $this->assertSame([], $runner->calls);
        $this->assertNotNull($site->fresh()->sftp_password);
    }

    public function test_the_password_can_be_renewed_and_removed(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $site->forceFill(['sftp_enabled' => true, 'sftp_password' => 'Abcdefgh12345678'])->save();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/sites/{$site->id}/sftp/password", ['action' => 'generate'])->assertRedirect();
        $new = $site->fresh()->sftp_password;
        $this->assertNotSame('Abcdefgh12345678', $new);
        $this->assertSame("password\t{$new}\n", $runner->calls[0]->stdin);

        $this->actingAs($admin)->post("/sites/{$site->id}/sftp/password", ['action' => 'remove'])->assertRedirect();
        $this->assertNull($site->fresh()->sftp_password);
        $this->assertSame('', $runner->calls[1]->stdin);

        // On without password and key: the page warns.
        $this->actingAs($admin)->get("/sites/{$site->id}/sftp")->assertSee('Anmeldung nicht möglich');
    }

    public function test_access_of_a_site_that_is_not_set_up_waits_for_it(): void
    {
        $runner = $this->runner();
        $site = $this->site();
        $site->forceFill(['status' => SiteStatus::Pending])->save();

        $this->actingAs($this->admin())->post("/sites/{$site->id}/sftp/toggle")
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'folgt danach'));
        $this->assertSame([], $runner->calls);

        // The gateway ends the setup of a website (E-195).
        $create = ServerTask::factory()->create(['server_id' => $site->server_id, 'site_id' => $site->id, 'script' => ServerScript::GatewaySite, 'status' => TaskStatus::Succeeded, 'result' => ['ok' => true]]);
        $site->fresh()->taskFinished($create);
        $this->assertSame(1, ServerTask::where('script', ServerScript::SiteSftp->value)->count());
    }

    public function test_a_key_of_another_site_cannot_be_deleted_through_this_site(): void
    {
        $this->runner();
        $site = $this->site();
        $other = Site::factory()->create();
        $key = $other->sftpKeys()->create(['name' => 'a', 'public_key' => self::KEY, 'fingerprint' => self::KEY_FINGERPRINT]);

        $this->actingAs($this->admin())->delete("/sites/{$site->id}/sftp/keys/{$key->id}")->assertNotFound();
        $this->assertNotNull($key->fresh());
    }

    public function test_customers_cannot_manage_sftp(): void
    {
        $runner = $this->runner();
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $site = $this->site();
        $site->forceFill(['customer_id' => $customer->id, 'sftp_password' => 'Abcdefgh12345678'])->save();
        $key = $site->sftpKeys()->create(['name' => 'a', 'public_key' => self::KEY, 'fingerprint' => self::KEY_FINGERPRINT]);

        $this->actingAs($user)->get("/sites/{$site->id}/sftp")->assertForbidden();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get("/sites/{$site->id}/sftp/password")->assertForbidden();
        $this->actingAs($user)->post("/sites/{$site->id}/sftp/toggle")->assertForbidden();
        $this->actingAs($user)->post("/sites/{$site->id}/sftp/password", ['action' => 'remove'])->assertForbidden();
        $this->actingAs($user)->post("/sites/{$site->id}/sftp/keys", ['public_key' => self::ECDSA])->assertForbidden();
        $this->actingAs($user)->delete("/sites/{$site->id}/sftp/keys/{$key->id}")->assertForbidden();

        $this->assertSame([], $runner->calls);
        $this->assertFalse($site->fresh()->sftp_enabled);
        $this->assertNotNull($key->fresh());
    }

    public function test_the_call_checks_password_and_keys(): void
    {
        $this->assertSame('SHA256:VL7W2MwRWxYQj4icdGitKDm2JTrc/waCxTb3Zuqht44', SftpKey::parse(self::ECDSA)['fingerprint'] ?? null);

        $this->expectException(InvalidArgumentException::class);
        ScriptCall::siteSftp('ow38217', true, "short\npw");
    }

    public function test_administrators_see_how_to_work_as_the_website_user(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin())->get("/sites/{$site->id}/sftp")
            ->assertOk()
            ->assertSee('Als Webseiten-Benutzer arbeiten')
            ->assertSee('runuser -l ow38217 -s /bin/bash');
    }

    public function test_the_domain_is_shown_only_when_its_dns_points_to_the_server(): void
    {
        $site = $this->site();
        $site->server->forceFill(['public_ipv4' => '203.0.113.10', 'public_ipv6' => '2001:db8::10', 'hosts_panel' => true])->save();
        $answers = ['shop.example.de' => ['203.0.113.10']];
        $this->mock(DnsResolver::class, function ($mock) use (&$answers) {
            $mock->makePartial()->shouldReceive('addresses')->andReturnUsing(function (string $host) use (&$answers) {
                return $answers[$host] ?? [];
            });
        });

        // Points here: the domain, with the IP address as alternative.
        $this->actingAs($this->admin())->get("/sites/{$site->id}/sftp")->assertOk()
            ->assertSeeInOrder(['shop.example.de', 'oder die IP-Adresse 203.0.113.10'])
            ->assertDontSee('sobald ihr DNS');

        // Still at the old host, e.g. while moving: the server address first.
        foreach ([['198.51.100.7'], ['203.0.113.10', '2001:db8::99'], []] as $elsewhere) {
            $answers['shop.example.de'] = $elsewhere;
            $this->actingAs($this->admin())->get("/sites/{$site->id}/sftp")->assertOk()
                ->assertSeeInOrder(['203.0.113.10', '2001:db8::10', 'Die Domain shop.example.de geht auch, sobald ihr DNS auf diesen Server zeigt.']);
        }

        // Without a known server address the domain stays.
        $site->server->forceFill(['public_ipv4' => null, 'public_ipv6' => null])->save();
        $this->actingAs($this->admin())->get("/sites/{$site->id}/sftp")->assertOk()
            ->assertSee('shop.example.de')->assertDontSee('sobald ihr DNS');
    }

    public function test_behind_the_gateway_sftp_goes_to_the_host_name_of_the_server(): void
    {
        Server::factory()->panel()->create(['public_ipv4' => '203.0.113.1', 'public_ipv6' => '2001:db8::1']);
        $site = $this->site();
        $site->server->forceFill(['hostname' => 'web2.example.de', 'public_ipv4' => '203.0.113.20', 'public_ipv6' => null])->save();
        $this->mock(DnsResolver::class, function ($mock) {
            $mock->makePartial()->shouldReceive('addresses')->andReturn(['203.0.113.1']);
        });

        $this->actingAs($this->admin())->get("/sites/{$site->id}/sftp")->assertOk()
            ->assertSeeInOrder(['web2.example.de', 'oder die IP-Adresse 203.0.113.20'])
            ->assertDontSee('sobald ihr DNS');
    }
}
