<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_sign_in_with_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_account_without_password_cannot_sign_in_with_a_password(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $this->post('/login', ['email' => $user->email, 'password' => ''])->assertSessionHasErrors();
        $this->post('/login', ['email' => $user->email, 'password' => 'anything'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_failed_attempts_are_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429);

        $this->assertGuest();
    }

    public function test_registration_and_password_reset_are_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
    }

    public function test_user_can_sign_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_sign_in_and_failure_are_recorded_without_typed_credentials(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'secret-guess']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $failed = AuditLog::where('action', 'auth.failed')->sole();
        $this->assertNull($failed->user_id);
        $this->assertStringNotContainsString('nobody@example.com', json_encode($failed->getAttributes()));
        $this->assertStringNotContainsString('secret-guess', json_encode($failed->getAttributes()));

        $login = AuditLog::where('action', 'auth.login')->sole();
        $this->assertSame($user->id, $login->user_id);
        $this->assertSame($user->customer_id, $login->customer_id);
        $this->assertSame('127.0.0.1', $login->ip_address);
    }

    public function test_session_cookie_is_secure_and_encrypted(): void
    {
        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.encrypt'));
        $this->assertTrue(config('session.http_only'));
        $this->assertLessThanOrEqual(120, config('session.lifetime'));
    }
}
