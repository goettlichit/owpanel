<?php

namespace Tests\Feature;

use App\Jobs\SendHeartbeat;
use App\Models\Setting;
use App\Models\User;
use App\Notify\AlertChecker;
use App\Notify\Notifier;
use App\Support\Heartbeat;
use App\Support\PublicUrl;
use App\Workers\PanelWorkers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Heartbeat to an outside monitoring (E-160).
 */
class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://hc-ping.com/0f3a6b2e-1111-4222-8333-944455556666';

    protected function setUp(): void
    {
        parent::setUp();
        PublicUrl::$resolver = fn (string $host) => match ($host) {
            'hc-ping.com' => ['198.51.100.7'],
            'intern.example.de' => ['10.0.0.5'],
            default => [],
        };
    }

    protected function tearDown(): void
    {
        PublicUrl::$resolver = null;
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_the_address_is_called_before_saving_and_stored_encrypted(): void
    {
        Http::fake(['hc-ping.com/*' => Http::response('OK')]);

        $this->actingAs($this->admin())->put(route('settings.heartbeat.update'), ['heartbeat_url' => self::URL])
            ->assertRedirect(route('settings.services'))->assertSessionHas('status');

        $this->assertSame(self::URL, Heartbeat::url());
        $this->assertStringNotContainsString('hc-ping', (string) Setting::get(Setting::HEARTBEAT_URL));
        $this->assertTrue(Heartbeat::state()['ok']);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL);
    }

    public function test_internal_plain_http_and_failing_addresses_are_refused(): void
    {
        Http::fake(['hc-ping.com/*' => Http::response('nope', 404)]);
        $admin = $this->admin();

        foreach (['http://hc-ping.com/x' => 'https', 'https://intern.example.de/ping' => 'internal', 'https://127.0.0.1/ping' => 'internal', 'https://unknown.invalid/' => 'resolve'] as $url => $reason) {
            $this->actingAs($admin)->put(route('settings.heartbeat.update'), ['heartbeat_url' => $url])
                ->assertSessionHasErrors(['heartbeat_url' => __('settings.heartbeat.failed', ['error' => __('settings.heartbeat.refused.'.$reason)])]);
        }
        $this->actingAs($admin)->put(route('settings.heartbeat.update'), ['heartbeat_url' => self::URL])
            ->assertSessionHasErrors('heartbeat_url');

        $this->assertNull(Heartbeat::url());
        Http::assertSentCount(1);
    }

    public function test_the_job_only_calls_while_every_panel_service_answers(): void
    {
        Http::fake(['hc-ping.com/*' => Http::response('OK')]);
        Heartbeat::save(self::URL);

        (new SendHeartbeat)->handle();
        Http::assertNothingSent();

        PanelWorkers::beat('scheduler');
        PanelWorkers::beat('default');
        PanelWorkers::beat('queries');
        (new SendHeartbeat)->handle();
        Http::assertSentCount(1);
    }

    public function test_the_scheduler_dispatches_only_with_an_address(): void
    {
        Queue::fake();
        $this->artisan('schedule:run');
        Queue::assertNotPushed(SendHeartbeat::class);

        Heartbeat::save(self::URL);
        $this->travelTo(now()->startOfHour());
        $this->artisan('schedule:run');
        Queue::assertPushed(SendHeartbeat::class);
    }

    public function test_a_failure_lasting_15_minutes_raises_and_success_resolves(): void
    {
        Queue::fake();
        Heartbeat::save(self::URL);
        Http::fake(['hc-ping.com/*' => Http::sequence()->push('down', 503)->push('down', 503)->push('OK')]);

        Heartbeat::ping();
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen('heartbeat_failed'));

        $this->travel(16)->minutes();
        Heartbeat::ping();
        (new AlertChecker)->run();
        $this->assertTrue(Notifier::isOpen('heartbeat_failed'));

        Heartbeat::ping();
        (new AlertChecker)->run();
        $this->assertFalse(Notifier::isOpen('heartbeat_failed'));
    }

    public function test_the_page_shows_the_state_and_the_address_can_be_removed(): void
    {
        Http::fake(['hc-ping.com/*' => Http::response('OK')]);
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('settings.heartbeat.update'), ['heartbeat_url' => self::URL]);

        $this->actingAs($admin)->get(route('settings.services'))
            ->assertSee(__('settings.heartbeat.last_ok'))->assertDontSee(self::URL);

        $this->actingAs($admin)->put(route('settings.heartbeat.update'), ['heartbeat_url_remove' => '1']);
        $this->assertNull(Heartbeat::url());
    }
}
