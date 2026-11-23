<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Models\AuditLog;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notify\MessageText;
use App\Security\LoginWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PushReceiver;
use Tests\TestCase;

/**
 * Sign-ins from new devices and locked sign-ins (E-160).
 */
class LoginWatchTest extends TestCase
{
    use RefreshDatabase;

    private const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0';

    private function request(?string $token = null, string $agent = self::FIREFOX): Request
    {
        return Request::create('/login', 'POST', [], $token === null ? [] : [LoginWatch::COOKIE => $token], [], ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => '203.0.113.5']);
    }

    private function withDevice(User $user): User
    {
        $browser = new PushReceiver;
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.$user->getKey();
        $user->pushSubscriptions()->create([
            'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'public_key' => $browser->publicKey(), 'auth' => $browser->authSecret(), 'label' => 'Firefox · Linux',
        ]);

        return $user;
    }

    private function messages(): array
    {
        return collect(Queue::pushed(DeliverNotification::class))->map(fn ($job) => $job->messages[0])
            ->filter(fn ($message) => $message['event'] === 'new_device_login')->values()->all();
    }

    private function queuedToken(): string
    {
        $token = Cookie::queued(LoginWatch::COOKIE)?->getValue();
        $this->assertIsString($token);
        Cookie::unqueue(LoginWatch::COOKIE);

        return $token;
    }

    public function test_the_first_sign_in_only_learns_the_device(): void
    {
        Queue::fake();
        $admin = $this->withDevice(User::factory()->admin()->withTwoFactor()->create(['first_name' => 'Anna', 'last_name' => 'Muster', 'locale' => 'de']));

        LoginWatch::signedIn($admin, $this->request());
        $token = $this->queuedToken();
        $this->assertSame([], $this->messages());
        $this->assertCount(1, $admin->fresh()->known_devices);
        $this->assertArrayHasKey(hash('sha256', $token), $admin->fresh()->known_devices);

        // Same browser again: still nothing.
        LoginWatch::signedIn($admin->fresh(), $this->request($token));
        $this->queuedToken();
        $this->assertSame([], $this->messages());
    }

    public function test_an_unknown_browser_is_reported_once(): void
    {
        Queue::fake();
        $admin = $this->withDevice(User::factory()->admin()->withTwoFactor()->create(['first_name' => 'Anna', 'last_name' => 'Muster', 'locale' => 'de']));
        LoginWatch::signedIn($admin, $this->request());
        $this->queuedToken();

        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
        LoginWatch::signedIn($admin->fresh(), $this->request(null, $iphone));
        $token = $this->queuedToken();

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('Anna Muster hat sich von einem neuen Gerät angemeldet', MessageText::title($messages[0]));
        $this->assertStringContainsString('Safari · iPhone', MessageText::body($messages[0]));
        $this->assertStringContainsString('203.0.113.5', MessageText::body($messages[0]));

        LoginWatch::signedIn($admin->fresh(), $this->request($token, $iphone));
        $this->assertCount(1, $this->messages());
    }

    public function test_customers_are_not_watched_and_devices_are_capped(): void
    {
        Queue::fake();
        $customer = User::factory()->create();
        LoginWatch::signedIn($customer, $this->request());
        $this->assertNull($customer->fresh()->known_devices);

        $admin = User::factory()->admin()->withTwoFactor()->create();
        for ($i = 0; $i < LoginWatch::MAX_DEVICES + 5; $i++) {
            $this->travel(1)->minutes();
            LoginWatch::signedIn($admin->fresh(), $this->request());
            Cookie::unqueue(LoginWatch::COOKIE);
        }
        $this->assertCount(LoginWatch::MAX_DEVICES, $admin->fresh()->known_devices);
    }

    public function test_the_sign_in_event_sets_the_cookie(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        Auth::guard('web')->login($admin);

        $this->assertNotNull(Cookie::queued(LoginWatch::COOKIE));
        $this->assertNotNull($admin->fresh()->known_devices);
    }

    public function test_a_lockout_is_reported_for_existing_accounts_only_and_once_an_hour(): void
    {
        Queue::fake();
        $this->withDevice(User::factory()->admin()->withTwoFactor()->create(['email' => 'anna@example.de', 'locale' => 'de']));

        for ($i = 0; $i < 12; $i++) {
            $this->post('/login', ['email' => 'anna@example.de', 'password' => 'wrong-password-123']);
            $this->post('/login', ['email' => 'nobody@example.de', 'password' => 'wrong-password-123']);
        }

        $lockouts = collect(Queue::pushed(DeliverNotification::class))->map(fn ($job) => $job->messages[0])
            ->filter(fn ($message) => $message['event'] === 'login_lockout')->values();
        $this->assertCount(1, $lockouts);
        $this->assertSame('Viele Fehlversuche für anna@example.de', MessageText::title($lockouts[0]));
        // The audit log sees every lockout; before, Fortify never fired it.
        $this->assertTrue(AuditLog::where('action', 'auth.lockout')->exists());
    }
}
