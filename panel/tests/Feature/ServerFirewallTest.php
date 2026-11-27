<?php

namespace Tests\Feature;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Fail2banIgnore;
use App\Models\FirewallRule;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Servers\ScriptResult;
use App\Servers\ScriptRunner;
use App\Support\IpNetwork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Tests\TestCase;

/**
 * Firewall and fail2ban of a server (E-090).
 */
class ServerFirewallTest extends TestCase
{
    use RefreshDatabase;

    protected bool $renderDeferred = true;

    /**
     * Answers each script with its own output.
     *
     * @param  array<string, string>  $outputs  script => last output line
     */
    private function runner(array $outputs = []): ScriptRunner
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

            /** @return list<ScriptCall> */
            public function changes(): array
            {
                return array_values(array_filter($this->calls, fn (ScriptCall $call) => $call->arguments !== ['--action', 'status']));
            }
        };
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private const CONFIRM_REQUIRED = '{"ok":true,"script":"firewall-rules","rules":1,"firewall_confirm_required":true,"firewall_confirm_within":60}';

    public function test_the_page_shows_fixed_ports_bans_and_the_own_address(): void
    {
        $this->runner(['server-fail2ban' => '{"ok":true,"script":"server-fail2ban","banned":[{"jail":"sshd","ip":"127.0.0.1","until":"2026-11-11 18:50:58"},{"jail":"sshd","ip":"203.0.113.7","until":null},{"jail":"owpanel-hidden","ip":"198.51.100.9","until":null},{"jail":"x","ip":"bad"}],"ignore":[]}']);
        $server = Server::factory()->panel()->create(['port' => 2222]);

        $this->actingAs($this->admin())->get("/servers/{$server->id}/firewall")
            ->assertOk()
            ->assertSee('2222')
            ->assertSee('HTTP/3 über QUIC')
            ->assertDontSee(__('firewall.fixed.private_network'))
            ->assertSee('203.0.113.7')
            ->assertSee('2026-11-11 18:50:58')
            // Jails with a name of their own (E-174).
            ->assertSee('SSH-Anmeldung')
            ->assertSee('Versteckte Dateien')
            ->assertSee('198.51.100.9')
            ->assertSee('das bist du')
            ->assertDontSee('>bad<', false)
            ->assertSee('Meine aktuelle Adresse 127.0.0.1 eintragen');
    }

    public function test_a_website_server_shows_ssh_and_the_private_network_only(): void
    {
        $this->runner(['server-fail2ban' => '{"ok":true,"script":"server-fail2ban","banned":[],"ignore":[]}']);
        $server = Server::factory()->create(['port' => 22, 'private_ipv4' => '10.0.0.3']);

        $this->actingAs($this->admin())->get("/servers/{$server->id}/firewall")
            ->assertOk()
            ->assertSee(__('firewall.fixed.ssh'))
            ->assertSee(__('firewall.fixed.private_network'))
            ->assertSee(__('firewall.fixed.private'))
            ->assertDontSee(__('firewall.fixed.http3'))
            ->assertDontSee(__('firewall.fixed.http'));
    }

    public function test_a_rule_is_sent_as_a_whole_list_and_confirmed_right_after(): void
    {
        $runner = $this->runner(['firewall-rules' => self::CONFIRM_REQUIRED]);
        $server = Server::factory()->create();
        $server->firewallRules()->create(['protocol' => 'udp', 'ports' => '51820', 'source' => null]);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/servers/{$server->id}/firewall/rules", [
            'protocol' => 'both', 'ports' => ' 8000 - 8010 ', 'source' => '203.0.113.17/24', 'comment' => 'Büro',
        ])->assertRedirect("/servers/{$server->id}/firewall")->assertSessionHas('status');

        $rule = FirewallRule::where('protocol', 'both')->sole();
        $this->assertSame('8000-8010', $rule->ports);
        $this->assertSame('203.0.113.0/24', $rule->source);

        $changes = $runner->changes();
        $this->assertSame(ServerScript::FirewallRules, $changes[0]->script);
        $this->assertSame([], $changes[0]->arguments);
        $this->assertSame("udp\t51820\tany\ntcp\t8000-8010\t203.0.113.0/24\nudp\t8000-8010\t203.0.113.0/24\n", $changes[0]->stdin);
        $this->assertSame(ServerScript::FirewallConfirm, $changes[1]->script);
        $this->assertCount(2, $changes);

        $confirm = ServerTask::where('script', ServerScript::FirewallConfirm->value)->sole();
        $this->assertSame(TaskStatus::Succeeded, $confirm->status);
        $this->assertSame($admin->id, $confirm->user_id);
    }

    public function test_no_confirmation_when_nothing_changed_on_the_server(): void
    {
        $runner = $this->runner(['firewall-rules' => '{"ok":true,"script":"firewall-rules","rules":1,"firewall_confirm_required":false}']);
        $server = Server::factory()->create();

        $this->actingAs($this->admin())->post("/servers/{$server->id}/firewall/rules", ['protocol' => 'tcp', 'ports' => '3306', 'source' => '2001:DB8::1'])->assertRedirect();

        $this->assertSame("tcp\t3306\t2001:db8::1/128\n", $runner->changes()[0]->stdin);
        $this->assertCount(1, $runner->changes());
    }

    public function test_deleting_the_last_rule_sends_an_empty_list(): void
    {
        $runner = $this->runner(['firewall-rules' => self::CONFIRM_REQUIRED]);
        $server = Server::factory()->create();
        $rule = $server->firewallRules()->create(['protocol' => 'tcp', 'ports' => '3306']);

        $this->actingAs($this->admin())->delete("/servers/{$server->id}/firewall/rules/{$rule->id}")->assertRedirect();

        $this->assertSame('', $runner->changes()[0]->stdin);
        $this->assertSame(0, FirewallRule::count());
    }

    public function test_invalid_rules_are_refused(): void
    {
        $runner = $this->runner();
        $server = Server::factory()->create();
        $admin = $this->admin();

        foreach (['0', '65536', '90-80', '22,23', 'ssh', '1-2-3'] as $ports) {
            $this->actingAs($admin)->post("/servers/{$server->id}/firewall/rules", ['protocol' => 'tcp', 'ports' => $ports])->assertSessionHasErrors('ports');
        }
        foreach (['1.2.3.4/33', '999.1.1.1', 'example.de', '::ffff:1.2.3.4', '1.2.3.4; flush ruleset', '0.0.0.0/0'] as $source) {
            $this->actingAs($admin)->post("/servers/{$server->id}/firewall/rules", ['protocol' => 'tcp', 'ports' => '22', 'source' => $source])->assertSessionHasErrors('source');
        }
        $this->actingAs($admin)->post("/servers/{$server->id}/firewall/rules", ['protocol' => 'icmp', 'ports' => '22'])->assertSessionHasErrors('protocol');

        $this->assertSame(0, FirewallRule::count());
        $this->assertSame([], $runner->changes());
    }

    public function test_fail2ban_exceptions_are_sent_as_a_whole_list(): void
    {
        $runner = $this->runner();
        $server = Server::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/servers/{$server->id}/firewall/ignores", ['address' => '127.0.0.1', 'comment' => 'Eigene Adresse'])->assertRedirect();
        $this->actingAs($admin)->post("/servers/{$server->id}/firewall/ignores", ['address' => '2001:db8::7/64'])->assertRedirect();
        $this->actingAs($admin)->post("/servers/{$server->id}/firewall/ignores", ['address' => '127.0.0.1'])->assertSessionHasErrors('address');

        $changes = $runner->changes();
        $this->assertSame(['--action', 'ignore'], $changes[1]->arguments);
        $this->assertSame("127.0.0.1\n2001:db8::/64\n", $changes[1]->stdin);

        $this->actingAs($admin)->get("/servers/{$server->id}/firewall")
            ->assertSee('deine Adresse')
            ->assertDontSee('Meine aktuelle Adresse');

        $ignore = Fail2banIgnore::where('address', '127.0.0.1')->sole();
        $this->actingAs($admin)->delete("/servers/{$server->id}/firewall/ignores/{$ignore->id}")->assertRedirect();
        $this->assertSame("2001:db8::/64\n", $runner->changes()[2]->stdin);
        // The audit log does not keep the address.
        $this->assertDatabaseMissing('audit_logs', ['details' => json_encode(['address' => '127.0.0.1'])]);
    }

    public function test_an_address_can_be_unbanned(): void
    {
        $runner = $this->runner();
        $server = Server::factory()->create();

        $this->actingAs($this->admin())->post("/servers/{$server->id}/firewall/unban", ['ip' => '203.0.113.7'])
            ->assertRedirect("/servers/{$server->id}/firewall");
        $this->actingAs($this->admin())->post("/servers/{$server->id}/firewall/unban", ['ip' => '203.0.113.0/24'])
            ->assertSessionHas('error');

        $this->assertSame(['--action', 'unban', '--ip', '203.0.113.7'], $runner->changes()[0]->arguments);
        $this->assertCount(1, $runner->changes());
    }

    public function test_a_rolled_back_change_is_shown(): void
    {
        $this->runner(['firewall-rules' => self::CONFIRM_REQUIRED, 'firewall-confirm' => '{"ok":false,"script":"firewall-confirm","error":"firewall_busy"}']);
        $server = Server::factory()->create();

        $this->actingAs($this->admin())->post("/servers/{$server->id}/firewall/rules", ['protocol' => 'tcp', 'ports' => '3306'])->assertRedirect();

        $this->actingAs($this->admin())->get("/servers/{$server->id}/firewall")
            ->assertSee('Letzte Änderung nicht aktiv')
            ->assertSee('nach 60 Sekunden zurückgenommen');
    }

    public function test_rules_of_another_server_cannot_be_deleted_through_this_one(): void
    {
        $this->runner();
        $server = Server::factory()->create();
        $other = Server::factory()->create();
        $rule = $other->firewallRules()->create(['protocol' => 'tcp', 'ports' => '3306']);
        $ignore = $other->fail2banIgnores()->create(['address' => '203.0.113.5']);

        $this->actingAs($this->admin())->delete("/servers/{$server->id}/firewall/rules/{$rule->id}")->assertNotFound();
        $this->actingAs($this->admin())->delete("/servers/{$server->id}/firewall/ignores/{$ignore->id}")->assertNotFound();
        $this->assertNotNull($rule->fresh());
        $this->assertNotNull($ignore->fresh());
    }

    public function test_customers_cannot_touch_the_firewall(): void
    {
        $runner = $this->runner();
        $customer = User::factory()->create();
        $server = Server::factory()->create();
        $rule = $server->firewallRules()->create(['protocol' => 'tcp', 'ports' => '3306']);
        $ignore = $server->fail2banIgnores()->create(['address' => '203.0.113.5']);

        $this->actingAs($customer)->get("/servers/{$server->id}/firewall")->assertForbidden();
        $this->actingAs($customer)->post("/servers/{$server->id}/firewall/rules", ['protocol' => 'tcp', 'ports' => '22'])->assertForbidden();
        $this->actingAs($customer)->delete("/servers/{$server->id}/firewall/rules/{$rule->id}")->assertForbidden();
        $this->actingAs($customer)->post("/servers/{$server->id}/firewall/ignores", ['address' => '1.2.3.4'])->assertForbidden();
        $this->actingAs($customer)->delete("/servers/{$server->id}/firewall/ignores/{$ignore->id}")->assertForbidden();
        $this->actingAs($customer)->post("/servers/{$server->id}/firewall/unban", ['ip' => '1.2.3.4'])->assertForbidden();

        $this->assertSame([], $runner->calls);
        $this->assertSame(1, FirewallRule::count());
        $this->assertSame(1, Fail2banIgnore::count());
    }

    public function test_addresses_are_normalized(): void
    {
        $this->assertSame('203.0.113.0/24', IpNetwork::normalize('203.0.113.17/24'));
        $this->assertSame('2001:db8::/64', IpNetwork::normalize('2001:DB8::5/64'));
        $this->assertSame('203.0.113.5', IpNetwork::normalize(' 203.0.113.5 '));
        $this->assertNull(IpNetwork::normalize('203.0.113.5/0'));
        $this->assertTrue(IpNetwork::contains('203.0.113.0/24', '203.0.113.77'));
        $this->assertFalse(IpNetwork::contains('203.0.113.0/24', '2001:db8::1'));
        $this->assertSame('8000-8010', FirewallRule::normalizePorts('8000:8010'));
    }

    public function test_bans_and_blocklists_load_after_the_page(): void
    {
        $this->renderDeferred = false;
        SupportLazyLoading::$disableWhileTesting = false;
        $runner = $this->runner(['server-fail2ban' => '{"ok":true,"script":"server-fail2ban","banned":[{"jail":"sshd","ip":"203.0.113.7","until":null}],"ignore":[]}']);
        $server = Server::factory()->create();

        // The page itself does not wait for the server.
        $this->actingAs($this->admin())->get("/servers/{$server->id}/firewall")
            ->assertOk()
            ->assertSee(__('ui.loading_server'))
            ->assertDontSee('203.0.113.7');
        $this->assertSame([], $runner->calls);
    }
}
