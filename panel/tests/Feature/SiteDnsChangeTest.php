<?php

namespace Tests\Feature;

use App\Dns\SiteDnsWriter;
use App\Hetzner\HetznerCloud;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Creating and correcting records in the Hetzner zone (E-116).
 */
class SiteDnsChangeTest extends TestCase
{
    use RefreshDatabase;

    private const DNS_TOKEN = 'zyxwvutsrqponmlkjihgfedcba9876543210ZYXWVUTSRQPONMLKJIHGFEDCBA9876';

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

    private function site(): Site
    {
        $server = Server::factory()->create(['hosts_panel' => true, 'public_ipv4' => '203.0.113.10', 'public_ipv6' => null]);

        return Site::factory()->for($server)->create(['domain' => 'example.de', 'www' => true]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    protected function tearDown(): void
    {
        SiteDnsWriter::$waitSeconds = 40;
        parent::tearDown();
    }

    /**
     * Zone example.de: the apex points elsewhere, www is missing.
     */
    private function fakeZone(int $writeStatus = 201, string $actionStatus = 'success'): void
    {
        Setting::put(Setting::HETZNER_DNS_TOKEN, Crypt::encryptString(self::DNS_TOKEN));
        $writes = $writeStatus === 403
            ? Http::response(['error' => ['code' => 'forbidden']], 403)
            : Http::response(['action' => ['id' => 7, 'status' => 'running']], $writeStatus);
        Http::fake(function (Request $request) use ($writes, $actionStatus) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/zones?name=example.de') => Http::response(['zones' => [['id' => 42, 'name' => 'example.de', 'mode' => 'primary']]]),
                str_contains($url, '/zones?') => Http::response(['zones' => []]),
                str_ends_with($url, '/zones/42/rrsets/@/A') => Http::response(['rrset' => ['ttl' => 7200, 'records' => [['value' => '198.51.100.7'], ['value' => '198.51.100.8']]]]),
                str_ends_with($url, '/zones/42/rrsets/www/A') => Http::response(['error' => ['code' => 'not_found']], 404),
                str_ends_with($url, '/zones/actions/7') => Http::response(['action' => ['id' => 7, 'status' => $actionStatus]]),
                $request->method() === 'POST' => $writes,
                default => Http::response(['error' => ['code' => 'not_found']], 404),
            };
        });
    }

    public function test_slow_hetzner_actions_end_the_request_in_time(): void
    {
        // Hetzner keeps every action running; the request must not wait
        // 30 seconds per record (504 from nginx), but end at the deadline.
        SiteDnsWriter::$waitSeconds = 0;
        $site = $this->site();
        $this->fakeZone(actionStatus: 'running');

        $this->actingAs($this->admin())->post(route('sites.dns.apply', $site), [
            'records' => ['example.de|A', 'www.example.de|A'], 'ttl' => 300, 'confirmed' => '1',
        ])->assertRedirect(route('sites.dns', $site))
            ->assertSessionHas('status', trans_choice('dns.change.done', 2, ['count' => 2]).' '.__('dns.change.pending'));

        // All three writes were sent: set_records and change_ttl for the
        // apex, the new record for www.
        $this->assertCount(3, Http::recorded(fn (Request $request) => $request->method() === 'POST'));
        $this->assertSame(2, $site->dnsRecords()->count());
    }

    public function test_the_page_offers_create_and_correct(): void
    {
        $site = $this->site();
        $this->fakeZone();

        $this->actingAs($this->admin())->get(route('sites.dns', $site))->assertOk()
            ->assertSee(__('dns.change.create'))->assertSee(__('dns.change.correct'))
            ->assertSee(__('dns.change.all', ['count' => 2]));
    }

    public function test_confirmation_shows_old_and_new_values_without_changing_anything(): void
    {
        $site = $this->site();
        $this->fakeZone();

        $this->actingAs($this->admin())->get(route('sites.dns.change', [$site, 'records' => ['example.de|A']]))->assertOk()
            ->assertSee('198.51.100.7, 198.51.100.8')->assertSee('203.0.113.10')
            ->assertSee(__('dns.change.overwrite'))->assertDontSee('www.example.de')
            ->assertSee('data-ow-busy="'.__('dns.change.working').'"', false)->assertSee(__('dns.change.working_note'));
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_records_are_created_and_corrected_after_confirmation(): void
    {
        $site = $this->site();
        $this->fakeZone();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('sites.dns.apply', $site), [
            'records' => ['example.de|A', 'www.example.de|A'], 'ttl' => 300, 'confirmed' => '1',
        ])->assertRedirect(route('sites.dns', $site))->assertSessionHas('status');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/zones/42/rrsets/@/A/actions/set_records')
            && $request['records'] === [['value' => '203.0.113.10']]
            && $request->hasHeader('Authorization', 'Bearer '.self::DNS_TOKEN));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/zones/42/rrsets/@/A/actions/change_ttl') && $request['ttl'] === 300);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/zones/42/rrsets')
            && $request['name'] === 'www' && $request['type'] === 'A' && $request['ttl'] === 300
            && $request['records'] === [['value' => '203.0.113.10']]);

        $this->assertSame(['@', 'www'], $site->dnsRecords()->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseHas('site_dns_records', ['site_id' => $site->id, 'name' => '@', 'type' => 'A', 'value' => '203.0.113.10', 'ttl' => 300, 'zone_id' => '42']);
        $this->assertSame(2, AuditLog::where('action', 'site.dns_record_set')->where('user_id', $admin->id)->count());
    }

    public function test_only_confirmed_and_offered_records_are_changed(): void
    {
        $site = $this->site();
        $this->fakeZone();

        $this->actingAs($this->admin())->post(route('sites.dns.apply', $site), ['records' => ['example.de|A'], 'ttl' => 300])
            ->assertSessionHasErrors('confirmed');
        $this->actingAs($this->admin())->post(route('sites.dns.apply', $site), ['records' => ['example.de|A'], 'ttl' => 42, 'confirmed' => '1'])
            ->assertSessionHasErrors('ttl');
        $this->actingAs($this->admin())->post(route('sites.dns.apply', $site), ['records' => ['other.de|A', 'example.de|MX'], 'ttl' => 300, 'confirmed' => '1'])
            ->assertSessionHas('status', trans_choice('dns.change.done', 0, ['count' => 0]));

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->assertDatabaseCount('site_dns_records', 0);
    }

    public function test_api_errors_stop_and_nothing_is_remembered(): void
    {
        $site = $this->site();
        $this->fakeZone(writeStatus: 403);

        $this->actingAs($this->admin())->post(route('sites.dns.apply', $site), [
            'records' => ['example.de|A'], 'ttl' => 3600, 'confirmed' => '1',
        ])->assertSessionHas('error', __('dns.change.failed', ['done' => 0, 'error' => __('dns.errors.forbidden')]));

        $this->assertDatabaseCount('site_dns_records', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'site.dns_record_set']);
    }

    public function test_without_zone_nothing_can_be_changed(): void
    {
        $site = $this->site();
        Http::fake();

        $this->actingAs($this->admin())->get(route('sites.dns', $site))->assertOk()
            ->assertDontSee(__('dns.change.create'))->assertDontSee(route('sites.dns.change', $site));
        $this->actingAs($this->admin())->get(route('sites.dns.change', $site))
            ->assertRedirect(route('sites.dns', $site))->assertSessionHas('status', __('dns.change.nothing'));
    }

    public function test_customers_cannot_change_dns(): void
    {
        $site = $this->site();
        $this->fakeZone();
        $customer = Customer::factory()->create();
        $site->forceFill(['customer_id' => $customer->id])->save();
        $user = User::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)->get(route('sites.dns.change', $site))->assertForbidden();
        $this->actingAs($user)->post(route('sites.dns.apply', $site), ['records' => ['example.de|A'], 'ttl' => 300, 'confirmed' => '1'])->assertForbidden();
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }
}
