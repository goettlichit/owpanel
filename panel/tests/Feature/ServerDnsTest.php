<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Support\DnsQuery;
use App\Support\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DNS and reverse DNS of a server (E-153).
 */
class ServerDnsTest extends TestCase
{
    use RefreshDatabase;

    private function server(): Server
    {
        return Server::factory()->create([
            'hostname' => 'srv03.example.de', 'public_ipv4' => '203.0.113.10', 'public_ipv6' => '2001:db8::1', 'hetzner_server_id' => 77,
        ]);
    }

    /**
     * @param  array<string, list<string>>  $answers  "host type" => answer, also used for the resolver
     * @param  array<string, string>  $ptr
     */
    private function fakeDns(array $answers, array $ptr = []): void
    {
        $this->app->instance(DnsResolver::class, new class($answers, $ptr) extends DnsResolver
        {
            public function __construct(private array $answers, private array $reverse) {}

            public function addresses(string $host): array
            {
                return [...($this->answers[$host.' A'] ?? []), ...($this->answers[$host.' AAAA'] ?? [])];
            }

            public function nameservers(string $host): array
            {
                return [['host' => 'hydrogen.ns.hetzner.com', 'ip' => '213.239.204.242']];
            }

            public function authoritative(string $host, int $type): ?array
            {
                return $this->answers[$host.' '.($type === DnsQuery::A ? 'A' : 'AAAA')] ?? [];
            }

            public function ptr(string $ip): ?string
            {
                return $this->reverse[$ip] ?? null;
            }
        });
    }

    private function fakeHetzner(string $ipv4Ptr = 'static.10.113.0.203.clients.your-server.de'): void
    {
        Setting::put(Setting::HETZNER_TOKEN, Crypt::encryptString(str_repeat('t', 64)));
        Http::fake([
            'api.hetzner.cloud/v1/zones?name=srv03.example.de' => Http::response(['zones' => []]),
            'api.hetzner.cloud/v1/zones?name=example.de' => Http::response(['zones' => [['id' => 42, 'name' => 'example.de', 'mode' => 'primary']]]),
            'api.hetzner.cloud/v1/zones/42/rrsets/srv03/A' => Http::response(['rrset' => ['ttl' => 3600, 'records' => [['value' => '203.0.113.10']]]]),
            'api.hetzner.cloud/v1/zones/42/rrsets/srv03/AAAA' => Http::response(['error' => ['code' => 'not_found']], 404),
            'api.hetzner.cloud/v1/zones/42/rrsets*' => Http::response(['action' => ['id' => 7, 'status' => 'running']], 201),
            'api.hetzner.cloud/v1/zones/actions/7' => Http::response(['action' => ['id' => 7, 'status' => 'success']]),
            'api.hetzner.cloud/v1/servers/77/actions/change_dns_ptr' => Http::response(['action' => ['id' => 2, 'status' => 'running']], 201),
            'api.hetzner.cloud/v1/servers/77' => Http::response(['server' => ['public_net' => [
                'ipv4' => ['ip' => '203.0.113.10', 'dns_ptr' => $ipv4Ptr],
                'ipv6' => ['ip' => '2001:db8::/64', 'dns_ptr' => [['ip' => '2001:db8::1', 'dns_ptr' => 'srv03.example.de']]],
            ]]]),
        ]);
    }

    public function test_only_admins_see_the_page(): void
    {
        $server = $this->server();
        $this->fakeDns([]);
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id, 'password_change_required' => false]);

        $this->actingAs($user)->get("/servers/{$server->id}/dns")->assertForbidden();
        $this->actingAs($user)->post("/servers/{$server->id}/dns/ptr", ['ip' => '203.0.113.10', 'dns_ptr' => 'x.example.de'])->assertForbidden();
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get("/servers/{$server->id}")
            ->assertSee(route('servers.dns', $server));
    }

    public function test_shows_records_and_reverse_dns_from_hetzner(): void
    {
        $server = $this->server();
        $this->fakeHetzner();
        $this->fakeDns(['srv03.example.de A' => ['203.0.113.10'], 'srv03.example.de AAAA' => ['2001:db8::1']]);

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get("/servers/{$server->id}/dns")->assertOk()
            ->assertSee('srv03.example.de')
            ->assertSee('Einträge setzen')
            // One "current" column, always the same lines (E-153).
            ->assertSeeInOrder(['Hetzner-Zone:', '203.0.113.10', 'Nameserver:', '203.0.113.10', 'Resolver:', '203.0.113.10', 'TTL:', '3600 s'])
            ->assertSee('Anlegen')
            ->assertSee('static.10.113.0.203.clients.your-server.de')
            ->assertSee('anderer Name')
            ->assertSee('value="srv03.example.de"', false)
            ->assertSee('korrekt');
    }

    public function test_sets_the_missing_record_in_the_zone(): void
    {
        $server = $this->server();
        $this->fakeHetzner();
        $this->fakeDns(['srv03.example.de A' => ['203.0.113.10']]);

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->post("/servers/{$server->id}/dns/records", ['records' => ['srv03.example.de|AAAA'], 'ttl' => 3600])
            ->assertRedirect("/servers/{$server->id}/dns")->assertSessionMissing('error')->assertSessionHas('status');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'rrsets')
            && str_contains($request->body(), '2001:db8::1'));
        $this->assertSame('srv03.example.de', AuditLog::where('action', 'server.dns_record_set')->sole()->context['host']);
    }

    public function test_sets_and_resets_reverse_dns(): void
    {
        $server = $this->server();
        $this->fakeHetzner();
        $this->fakeDns([]);
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->post("/servers/{$server->id}/dns/ptr", ['ip' => '203.0.113.10', 'dns_ptr' => 'SRV03.example.de.'])
            ->assertRedirect("/servers/{$server->id}/dns")
            ->assertSessionHas('status', 'Reverse DNS für 203.0.113.10 ist jetzt srv03.example.de. Es kann einige Minuten dauern, bis alle Resolver das sehen.');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/servers/77/actions/change_dns_ptr')
            && $request['ip'] === '203.0.113.10' && $request['dns_ptr'] === 'srv03.example.de');

        $this->actingAs($admin)->post("/servers/{$server->id}/dns/ptr", ['ip' => '2001:db8::1', 'dns_ptr' => ''])->assertSessionHas('status');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/change_dns_ptr')
            && $request['ip'] === '2001:db8::1' && array_key_exists('dns_ptr', $request->data()) && $request['dns_ptr'] === null);
        $this->assertSame(2, AuditLog::where('action', 'server.dns_ptr_set')->count());
    }

    public function test_refuses_other_addresses_and_bad_names(): void
    {
        $server = $this->server();
        $this->fakeHetzner();
        $this->fakeDns([]);
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->post("/servers/{$server->id}/dns/ptr", ['ip' => '198.51.100.1', 'dns_ptr' => 'srv03.example.de'])->assertSessionHasErrors('ip');
        foreach (['srv03', 'srv 03.example.de', '-x.example.de', 'x.example.de;id'] as $name) {
            $this->actingAs($admin)->post("/servers/{$server->id}/dns/ptr", ['ip' => '203.0.113.10', 'dns_ptr' => $name])->assertSessionHasErrors('dns_ptr');
        }
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'change_dns_ptr'));
    }

    public function test_without_hetzner_it_only_shows_what_dns_says(): void
    {
        $server = $this->server();
        $server->forceFill(['hetzner_server_id' => null])->save();
        Http::fake();
        $this->fakeDns(['srv03.example.de A' => ['203.0.113.10']], ['203.0.113.10' => 'srv03.example.de']);

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get("/servers/{$server->id}/dns")->assertOk()
            ->assertSee('nicht über die Hetzner-API bekannt')
            ->assertDontSee('name="dns_ptr"', false);
        Http::assertNothingSent();
    }
}
