<?php

namespace Tests\Feature;

use App\Hetzner\HetznerCloud;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptRunner;
use App\Support\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeScriptRunner;
use Tests\TestCase;

/**
 * DNS records when a website is created and deleted (E-116).
 */
class SiteDnsLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        HetznerCloud::$pollMicroseconds = 0;
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public function addresses(string $host): array
            {
                return [];
            }

            public function nameservers(string $host): array
            {
                return [];
            }
        });
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    /**
     * Zone example.de with the given RRSets ("name type" => values).
     *
     * @param  array<string, list<string>>  $rrsets
     */
    private function fakeZone(array $rrsets): void
    {
        Setting::put(Setting::HETZNER_DNS_TOKEN, Crypt::encryptString(str_repeat('d', 64)));
        Http::fake(function (Request $request) use ($rrsets) {
            $url = urldecode($request->url());
            if (str_contains($url, '/zones?name=example.de')) {
                return Http::response(['zones' => [['id' => 42, 'name' => 'example.de', 'mode' => 'primary']]]);
            }
            if (str_contains($url, '/zones?')) {
                return Http::response(['zones' => []]);
            }
            if (str_contains($url, '/zones/actions/')) {
                return Http::response(['action' => ['id' => 7, 'status' => 'success']]);
            }
            if ($request->method() !== 'GET') {
                return Http::response(['action' => ['id' => 7, 'status' => 'running']], 201);
            }
            if (preg_match('#/zones/42/rrsets/([^/]+)/(A|AAAA)$#', $url, $m) && isset($rrsets[$m[1].' '.$m[2]])) {
                return Http::response(['rrset' => ['ttl' => 3600, 'records' => array_map(fn ($v) => ['value' => $v], $rrsets[$m[1].' '.$m[2]])]]);
            }

            return Http::response(['error' => ['code' => 'not_found']], 404);
        });
    }

    private function runner(string $line): FakeScriptRunner
    {
        $runner = new FakeScriptRunner([$line."\n"]);
        $this->app->instance(ScriptRunner::class, $runner);

        return $runner;
    }

    public function test_creating_a_website_creates_missing_records_first(): void
    {
        $runner = $this->runner('{"ok":true,"script":"site-create","domain":"example.de","user":"ow12345","uid":12345,"php":"8.5","tls":true,"www":true,"www_tls":true}');
        Server::factory()->create(['hosts_panel' => true, 'public_ipv4' => '203.0.113.10', 'public_ipv6' => '2001:db8::10']);
        // www AAAA points elsewhere and stays as it is.
        $this->fakeZone(['www AAAA' => ['2001:db8::99']]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/sites/create')->assertOk()->assertSee(__('sites.form.dns'));
        $this->actingAs($admin)->post('/sites', ['domain' => 'example.de', 'tls' => '1', 'www' => '1', 'dns' => '1'])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, '3 DNS-Einträge angelegt.') && str_contains($status, 'Ein vorhandener Eintrag zeigt woandershin'));

        $created = Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/zones/42/rrsets'))
            ->map(fn ($pair) => $pair[0]['name'].' '.$pair[0]['type'])->sort()->values()->all();
        $this->assertSame(['@ A', '@ AAAA', 'www A'], $created);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'set_records'));
        $this->assertSame(3, Site::sole()->dnsRecords()->count());
        $this->assertNotEmpty($runner->calls);
    }

    public function test_without_zone_or_option_the_website_is_still_created(): void
    {
        $this->runner('{"ok":true,"script":"site-create","domain":"other.de","user":"ow12345","uid":12345,"php":"8.5","tls":false}');
        Server::factory()->create(['hosts_panel' => true, 'public_ipv4' => '203.0.113.10', 'public_ipv6' => null]);
        $this->fakeZone([]);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sites', ['domain' => 'other.de', 'dns' => '1'])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, __('dns.create.no_zone')));
        $this->actingAs($admin)->post('/sites', ['domain' => 'third.example.de', 'dns' => '0']);

        $this->assertSame(2, Site::count());
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_option_is_only_offered_with_a_token(): void
    {
        Server::factory()->create();

        $this->actingAs($this->admin())->get('/sites/create')->assertOk()->assertDontSee(__('sites.form.dns'));
    }

    public function test_delete_page_asks_and_only_unchanged_panel_records_go(): void
    {
        $this->runner('{"ok":true,"script":"site-delete","user":"ow23456"}');
        $server = Server::factory()->create(['hosts_panel' => true, 'public_ipv4' => '203.0.113.10']);
        $site = Site::factory()->for($server)->create(['domain' => 'example.de', 'system_user' => 'ow23456']);
        $site->dnsRecords()->create(['zone_id' => '42', 'zone_name' => 'example.de', 'name' => '@', 'type' => 'A', 'value' => '203.0.113.10', 'ttl' => 3600]);
        $site->dnsRecords()->create(['zone_id' => '42', 'zone_name' => 'example.de', 'name' => 'www', 'type' => 'A', 'value' => '203.0.113.10', 'ttl' => 3600]);
        // @ A is unchanged, www A was changed by someone else.
        $this->fakeZone(['@ A' => ['203.0.113.10'], 'www A' => ['198.51.100.7']]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('sites.show', $site))->assertSee(route('sites.delete', $site));
        $this->actingAs($admin)->get(route('sites.delete', $site))->assertOk()
            ->assertSee(__('sites.delete.really', ['domain' => 'example.de']))
            ->assertSee(__('sites.delete.dns_question'))
            ->assertSee(__('sites.delete.dns_removable'))->assertSee(__('sites.delete.dns_kept'))->assertSee('198.51.100.7');

        $this->actingAs($admin)->delete(route('sites.destroy', $site), ['confirm_domain' => 'example.de', 'delete_dns' => '1'])->assertRedirect();

        $this->assertNull(Site::find($site->id));
        $deleted = Http::recorded(fn (Request $request) => $request->method() === 'DELETE')->map(fn ($pair) => urldecode($pair[0]->url()))->values()->all();
        $this->assertCount(1, $deleted);
        $this->assertStringEndsWith('/zones/42/rrsets/@/A', $deleted[0]);
        $this->assertSame(1, AuditLog::where('action', 'site.dns_record_deleted')->count());
        $this->assertSame(1, AuditLog::where('action', 'site.dns_record_kept')->count());
        $this->assertDatabaseCount('site_dns_records', 0);
    }

    public function test_without_the_tick_all_records_stay(): void
    {
        $this->runner('{"ok":true,"script":"site-delete","user":"ow23456"}');
        $server = Server::factory()->create(['hosts_panel' => true, 'public_ipv4' => '203.0.113.10']);
        $site = Site::factory()->for($server)->create(['domain' => 'example.de', 'system_user' => 'ow23456']);
        $site->dnsRecords()->create(['zone_id' => '42', 'zone_name' => 'example.de', 'name' => '@', 'type' => 'A', 'value' => '203.0.113.10', 'ttl' => 3600]);
        $this->fakeZone(['@ A' => ['203.0.113.10']]);

        $this->actingAs($this->admin())->delete(route('sites.destroy', $site), ['confirm_domain' => 'example.de'])->assertRedirect();

        $this->assertNull(Site::find($site->id));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_a_site_without_panel_records_says_so(): void
    {
        $site = Site::factory()->create(['domain' => 'example.de']);

        $this->actingAs($this->admin())->get(route('sites.delete', $site))->assertOk()
            ->assertSee(__('sites.delete.dns_none'))->assertDontSee(__('sites.delete.dns_question'));
    }

    public function test_dns_page_offers_the_certificate_once_dns_points_here(): void
    {
        $server = Server::factory()->create(['hosts_panel' => true, 'public_ipv4' => '203.0.113.10', 'public_ipv6' => null]);
        $site = Site::factory()->for($server)->create(['domain' => 'example.de', 'www' => false, 'tls' => false, 'wants_tls' => true]);
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public function addresses(string $host): array
            {
                return ['203.0.113.10'];
            }

            public function nameservers(string $host): array
            {
                return [];
            }
        });
        Http::fake();

        $this->actingAs($this->admin())->get(route('sites.dns', $site))->assertOk()
            ->assertSee(__('dns.certificate.title'))->assertSee(route('sites.certificate', $site));

        $site->forceFill(['tls' => true])->save();
        $this->actingAs($this->admin())->get(route('sites.dns', $site))->assertOk()->assertDontSee(__('dns.certificate.title'));
    }
}
