<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_two_factor_must_enter_a_code(): void
    {
        $user = User::factory()->admin()->withTwoFactor()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/two-factor-challenge');
        $this->assertGuest();

        $this->get('/two-factor-challenge')->assertOk()->assertSee('Zwei-Faktor-Bestätigung');

        $this->post('/two-factor-challenge', ['code' => UserFactory::currentCode()])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $user = User::factory()->admin()->withTwoFactor()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_recovery_code_works_once(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->assertNotContains('recovery-code-1', $user->fresh()->recoveryCodes());
    }

    public function test_admin_without_two_factor_is_sent_to_the_setup(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')->assertRedirect('/account/security');
        $this->actingAs($admin)->get('/audit-log')->assertRedirect('/account/security');
    }

    public function test_customer_without_two_factor_can_use_the_panel(): void
    {
        $this->actingAs(User::factory()->create())->get('/my/sites')->assertOk();
    }

    public function test_admin_can_set_up_two_factor(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/account/security')->assertOk();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/account/security')
            ->assertOk()
            ->assertSee('Einrichtung starten');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication')
            ->assertRedirect();

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertFalse($admin->hasConfirmedTwoFactor());

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/account/security')
            ->assertSee('<svg', false);

        $secret = decrypt($admin->two_factor_secret);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/confirmed-two-factor-authentication', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertSessionHasNoErrors();

        $this->assertTrue($admin->fresh()->hasConfirmedTwoFactor());
        $this->actingAs($admin->fresh())->get('/')->assertOk();
    }

    public function test_admin_without_two_factor_reaches_the_setup_directly(): void
    {
        // Real flow without a prepared session: no password prompt (E-135).
        $admin = User::factory()->admin()->create();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $this->get('/')->assertRedirect('/account/security');
        $this->get('/account/security')->assertOk();
        $this->post('/user/two-factor-authentication')->assertRedirect();
        $this->get('/user/two-factor-qr-code')->assertOk();
    }

    public function test_admin_can_regenerate_recovery_codes(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-recovery-codes')
            ->assertSessionHas('status', 'recovery-codes-generated');

        $this->assertNotContains('recovery-code-1', $admin->fresh()->recoveryCodes());
    }

    public function test_admin_cannot_switch_off_two_factor(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete('/user/two-factor-authentication')
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->hasConfirmedTwoFactor());
    }

    public function test_customer_can_switch_off_two_factor(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete('/user/two-factor-authentication');

        $this->assertFalse($user->fresh()->hasConfirmedTwoFactor());
    }

    public function test_two_factor_secret_is_not_serialised(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
        $this->assertArrayNotHasKey('password', $user->toArray());
    }
}
