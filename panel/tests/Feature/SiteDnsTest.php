<?php

namespace Tests\Feature;

use App\Dns\SiteDns;
use App\Models\Customer;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\DnsQuery;
use App\Support\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteDnsTest extends TestCase
{
    use RefreshDatabase;

    private function site(bool $www = true): Site
    {
        $server = Server::factory()->create(['hosts_panel' => true, 'public_ipv4' => '203.0.113.10', 'public_ipv6' => '2001:db8::10']);

        return Site::factory()->for($server)->create(['domain' => 'shop.example.de', 'www' => $www]);
    }

    /**
     * Name servers, their answers and the public resolver as given.
     *
     * @param  array<string, list<string>|null>  $authoritative  "host type" => answer
     * @param  array<string, list<string>>  $resolver
     */
    private function fakeDns(array $authoritative, array $resolver = []): void
    {
        $this->app->instance(DnsResolver::class, new class($authoritative, $resolver) extends DnsResolver
        {
            public function __construct(private array $answers, private array $public) {}

            public function addresses(string $host): array
            {
                return $this->public[$host] ?? [];
            }

            public function nameservers(string $host): array
            {
                return [['host' => 'hydrogen.ns.hetzner.com', 'ip' => '213.239.204.242']];
            }

            public function authoritative(string $host, int $type): ?array
            {
                return $this->answers[$host.' '.($type === DnsQuery::A ? 'A' : 'AAAA')] ?? [];
            }
        });
    }

    private function fakeHetzner(): void
    {
        Setting::put(Setting::HETZNER_TOKEN, Crypt::encryptString(str_repeat('t', 64)));
        Http::fake([
            'api.hetzner.cloud/v1/zones?name=shop.example.de' => Http::response(['zones' => []]),
            'api.hetzner.cloud/v1/zones?name=example.de' => Http::response(['zones' => [['id' => 42, 'name' => 'example.de', 'mode' => 'primary']]]),
            'api.hetzner.cloud/v1/zones/42/rrsets/shop/A' => Http::response(['rrset' => ['ttl' => 3600, 'records' => [['value' => '203.0.113.10']]]]),
            'api.hetzner.cloud/v1/zones/42/rrsets/shop/AAAA' => Http::response(['error' => ['code' => 'not_found']], 404),
            'api.hetzner.cloud/v1/zones/42/rrsets/www.shop/A' => Http::response(['rrset' => ['ttl' => null, 'records' => [['value' => '198.51.100.7']]]]),
            'api.hetzner.cloud/v1/zones/42/rrsets/www.shop/AAAA' => Http::response(['rrset' => ['ttl' => 300, 'records' => [['value' => '2001:0db8:0000::10']]]]),
        ]);
    }

    public function test_only_admins_see_the_page(): void
    {
        $site = $this->site();
        $this->fakeDns([]);

        $customer = Customer::factory()->create();
        $site->forceFill(['customer_id' => $customer->id])->save();
        $user = User::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)->get(route('sites.dns', $site))->assertForbidden();
        $this->actingAs($user)->post(route('sites.dns.check', $site))->assertForbidden();
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get(route('sites.show', $site))->assertSee(route('sites.dns', $site));
    }

    public function test_compares_zone_name_servers_and_resolver(): void
    {
        $site = $this->site();
        $this->fakeHetzner();
        $this->fakeDns([
            'shop.example.de A' => ['203.0.113.10'],
            'shop.example.de AAAA' => [],
            'www.shop.example.de A' => ['198.51.100.7'],
            'www.shop.example.de AAAA' => ['2001:db8::10'],
        ], [
            'shop.example.de' => ['203.0.113.10'],
            'www.shop.example.de' => ['198.51.100.7'],
        ]);

        $dns = app(SiteDns::class)->check($site->load('server'));

        $this->assertSame(['id' => '42', 'name' => 'example.de', 'mode' => 'primary'], $dns['zone']);
        $rows = collect($dns['rows'])->keyBy(fn ($row) => $row['host'].' '.$row['type']);
        $this->assertSame('shop', $rows['shop.example.de A']['name']);
        $this->assertSame('ok', $rows['shop.example.de A']['state']);
        $this->assertSame(3600, $rows['shop.example.de A']['ttl']);
        $this->assertSame([], $rows['shop.example.de AAAA']['zone']);
        $this->assertSame('missing', $rows['shop.example.de AAAA']['state']);
        $this->assertSame('wrong', $rows['www.shop.example.de A']['state']);
        $this->assertSame(['2001:db8::10'], $rows['www.shop.example.de AAAA']['zone']);
        $this->assertSame('waiting', $rows['www.shop.example.de AAAA']['state']);
        $this->assertSame('wrong', $dns['state']);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_page_works_without_token_for_any_zone(): void
    {
        $site = $this->site(www: false);
        $this->fakeDns(['shop.example.de A' => ['203.0.113.10'], 'shop.example.de AAAA' => ['2001:db8::10']],
            ['shop.example.de' => ['203.0.113.10', '2001:db8::10']]);
        Http::fake();

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get(route('sites.dns', $site))
            ->assertOk()
            ->assertDontSee('Hetzner')
            ->assertDontSee(__('dns.zone_missing'))
            ->assertSee(__('dns.overall.ok'))
            ->assertSee('hydrogen.ns.hetzner.com')
            ->assertDontSee('www.shop.example.de');
        Http::assertNothingSent();
    }

    public function test_zone_outside_the_project_is_named(): void
    {
        $site = $this->site(www: false);
        Setting::put(Setting::HETZNER_TOKEN, Crypt::encryptString(str_repeat('t', 64)));
        Http::fake(['api.hetzner.cloud/v1/zones*' => Http::response(['zones' => []])]);
        $this->fakeDns([]);

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get(route('sites.dns', $site))
            ->assertOk()
            ->assertDontSee(__('dns.fields.hetzner'))
            ->assertSee(__('dns.zone_missing'))
            ->assertSee(__('dns.states.missing'));
    }

    public function test_api_errors_are_shown_and_the_check_still_runs(): void
    {
        $site = $this->site(www: false);
        Setting::put(Setting::HETZNER_TOKEN, Crypt::encryptString(str_repeat('t', 64)));
        Http::fake(['api.hetzner.cloud/*' => Http::response(['error' => ['code' => 'unauthorized']], 401)]);
        $this->fakeDns(['shop.example.de A' => ['203.0.113.10'], 'shop.example.de AAAA' => ['2001:db8::10']]);

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get(route('sites.dns', $site))
            ->assertOk()
            ->assertSee(__('snapshots.errors.unauthorized'))
            ->assertSee(__('dns.states.waiting'));
    }

    public function test_check_asks_again(): void
    {
        $site = $this->site(www: false);
        $this->fakeDns([]);

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->post(route('sites.dns.check', $site))
            ->assertRedirect(route('sites.dns', $site))
            ->assertSessionHas('dns.fresh', true);
    }

    public function test_states(): void
    {
        $ip = '203.0.113.10';
        $this->assertSame('ok', SiteDns::state($ip, [$ip], [$ip], [$ip]));
        $this->assertSame('waiting', SiteDns::state($ip, [$ip], [$ip], ['198.51.100.7']));
        $this->assertSame('missing', SiteDns::state($ip, [], [], []));
        $this->assertSame('wrong', SiteDns::state($ip, [$ip, '198.51.100.7'], [$ip, '198.51.100.7'], [$ip]));
        $this->assertSame('wrong', SiteDns::state($ip, ['198.51.100.7'], [$ip], [$ip]));
        $this->assertSame('unknown', SiteDns::state($ip, null, null, []));
        $this->assertSame('ok', SiteDns::state($ip, null, null, [$ip]));
    }
}
