<?php

namespace Tests\Feature;

use App\Dns\SiteDns;
use App\Gateway\Ingress;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ingress addresses as a setting (E-162, E-193, M5-3).
 */
class IngressTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    private function quietDns(): void
    {
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public function __construct() {}

            public function addresses(string $host): array
            {
                return [];
            }

            public function nameservers(string $host): array
            {
                return [];
            }

            public function authoritative(string $host, int $type): ?array
            {
                return [];
            }
        });
    }

    public function test_without_a_setting_the_panel_server_addresses_apply(): void
    {
        $this->assertNull(Ingress::ipv4());
        $this->assertSame([], Ingress::addresses());

        Server::factory()->panel()->create(['public_ipv4' => '203.0.113.1', 'public_ipv6' => '2001:db8:0::1']);
        Server::factory()->create(['public_ipv4' => '203.0.113.20']);

        $this->assertSame('203.0.113.1', Ingress::ipv4());
        $this->assertSame('2001:db8::1', Ingress::ipv6());
        $this->assertFalse(Ingress::custom());

        Ingress::save('198.51.100.5', null);
        $this->assertSame(['198.51.100.5', '2001:db8::1'], Ingress::addresses());
        $this->assertTrue(Ingress::custom());
    }

    public function test_the_dns_of_a_website_points_to_the_ingress_not_to_its_server(): void
    {
        $this->quietDns();
        Server::factory()->panel()->create(['public_ipv4' => '203.0.113.1', 'public_ipv6' => null]);
        $web = Server::factory()->create(['public_ipv4' => '203.0.113.20', 'public_ipv6' => '2001:db8::20']);
        $site = Site::factory()->for($web)->create(['domain' => 'shop.example.de', 'www' => false]);

        $rows = app(SiteDns::class)->check($site, withZone: false)['rows'];
        $this->assertSame([['shop.example.de', 'A', '203.0.113.1']], array_map(fn ($row) => [$row['host'], $row['type'], $row['expected']], $rows));

        // The host name of a server still points to the server itself.
        $own = app(SiteDns::class)->checkHosts($web, ['web2.example.de'], 'web2.example.de', withZone: false)['rows'];
        $this->assertSame(['203.0.113.20', '2001:db8::20'], array_column($own, 'expected'));

        $this->actingAs($this->admin())->get(route('sites.dns', $site))->assertOk()
            ->assertSee('Gateway-Adressen')->assertSee('203.0.113.1')->assertDontSee('203.0.113.20');
    }

    public function test_an_admin_sets_and_clears_the_ingress_addresses(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
        Server::factory()->panel()->create(['public_ipv4' => '203.0.113.1', 'public_ipv6' => null]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('settings.gateway'))->assertOk()
            ->assertSee('Gateway-Adressen')->assertSee('Leer: 203.0.113.1 (Panel-Server)')->assertSee('Leer: keine');

        $this->actingAs($admin)->put(route('settings.gateway.update'), ['ingress_ipv4' => '198.51.100.5', 'ingress_ipv6' => '2A01:4F8:C0C:1::5'])
            ->assertRedirect(route('settings.gateway'))->assertSessionHas('status');
        $this->assertSame('198.51.100.5', Setting::get(Setting::INGRESS_IPV4));
        $this->assertSame('2a01:4f8:c0c:1::5', Ingress::ipv6());
        $audit = AuditLog::where('action', 'system.ingress_changed')->sole();
        $this->assertSame(['198.51.100.5', '2a01:4f8:c0c:1::5'], $audit->context['after']);

        $this->actingAs($admin)->put(route('settings.gateway.update'), ['ingress_ipv4' => '', 'ingress_ipv6' => ''])->assertRedirect();
        $this->assertNull(Setting::get(Setting::INGRESS_IPV4));
        $this->assertSame(['203.0.113.1'], Ingress::addresses());
    }

    public function test_only_public_addresses_of_the_right_family_are_accepted(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $admin = $this->admin();

        foreach ([
            ['ingress_ipv4' => '10.0.0.5'],
            ['ingress_ipv4' => '127.0.0.1'],
            ['ingress_ipv4' => '2001:db8::5'],
            ['ingress_ipv4' => 'gw01.example.de'],
            ['ingress_ipv6' => 'fd00::5'],
            ['ingress_ipv6' => '::1'],
            ['ingress_ipv6' => '198.51.100.5'],
        ] as $input) {
            $this->actingAs($admin)->from(route('settings.gateway'))->put(route('settings.gateway.update'), $input)
                ->assertSessionHasErrors(array_key_first($input));
        }
        $this->assertNull(Setting::get(Setting::INGRESS_IPV4));
        $this->assertNull(Setting::get(Setting::INGRESS_IPV6));
    }

    public function test_customers_cannot_see_or_change_the_ingress_addresses(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $customer = User::factory()->create(['customer_id' => Customer::factory()->create()->id, 'password_change_required' => false]);

        $this->actingAs($customer)->get(route('settings.gateway'))->assertForbidden();
        $this->actingAs($customer)->put(route('settings.gateway.update'), ['ingress_ipv4' => '198.51.100.5'])->assertForbidden();
        $this->assertNull(Setting::get(Setting::INGRESS_IPV4));
    }
}
