<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_page_shows_personal_details_and_password(): void
    {
        $this->actingAs(User::factory()->create(['first_name' => 'Maria']))
            ->get('/account')
            ->assertOk()
            ->assertSee('Persönliche Daten')
            ->assertSee('value="Maria"', false)
            ->assertSee('ow-card-header-warning', false)
            ->assertSee(route('user-password.update'))
            ->assertDontSee(route('two-factor.enable'));
    }

    public function test_two_factor_and_pocket_id_share_the_security_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/account/security')
            ->assertOk()
            ->assertSee('Anmeldung und Sicherheit')
            ->assertSee(route('two-factor.enable'))
            ->assertDontSee(route('user-password.update'));
    }

    public function test_user_can_change_first_and_last_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/account')
            ->put('/user/profile-information', ['first_name' => 'Maria', 'last_name' => 'Muster'])
            ->assertRedirect('/account')
            ->assertSessionHas('status', 'profile-information-updated');

        $this->assertSame('Maria Muster', $user->fresh()->name);
        $this->assertSame('account.profile_updated', AuditLog::sole()->action);
    }

    public function test_names_are_required(): void
    {
        $user = User::factory()->create(['first_name' => 'Maria']);

        $this->actingAs($user)
            ->put('/user/profile-information', ['first_name' => '', 'last_name' => 'Muster'])
            ->assertSessionHasErrorsIn('updateProfileInformation', 'first_name');

        $this->assertSame('Maria', $user->fresh()->first_name);
    }

    public function test_email_and_role_cannot_be_changed_through_the_profile(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);

        $this->actingAs($user)->put('/user/profile-information', [
            'first_name' => 'Maria',
            'last_name' => 'Muster',
            'email' => 'evil@example.com',
            'role' => 'admin',
        ]);

        $user->refresh();
        $this->assertSame('maria@example.com', $user->email);
        $this->assertTrue($user->isCustomer());
    }
}
