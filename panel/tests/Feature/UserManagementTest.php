<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Panel accounts (E-081): administrators add and delete administrators; a
 * new account changes its start password, then sets up two-factor.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->admin()->withTwoFactor()->create($attributes);
    }

    private function confirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_the_pages_open_without_a_password_prompt(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/users')->assertOk();
        $this->actingAs($admin)->get('/users/create')->assertOk();
    }

    public function test_an_admin_lists_accounts(): void
    {
        $admin = $this->admin(['first_name' => 'Alexander', 'last_name' => 'Test', 'email' => 'admin@example.de']);
        $this->admin(['first_name' => 'Berta', 'last_name' => 'Beispiel', 'email' => 'berta@example.de']);

        $this->confirmed($admin)->get('/users')
            ->assertOk()
            ->assertSee('Alexander')
            ->assertSee('berta@example.de')
            ->assertSee('Administrator')
            ->assertSee('aktiv')
            ->assertSee(route('users.create'), false);
    }

    public function test_an_admin_adds_an_administrator_with_a_start_password_shown_once(): void
    {
        $admin = $this->admin();

        $response = $this->confirmed($admin)->post('/users', ['first_name' => 'Berta', 'last_name' => 'Beispiel', 'email' => 'Berta@Example.de']);
        $response->assertRedirect('/users');

        $user = User::where('email', 'berta@example.de')->sole();
        $this->assertSame(Role::Admin, $user->role);
        $this->assertTrue($user->password_change_required);
        $this->assertNull($user->two_factor_confirmed_at);

        $password = session('user.created')['password'];
        $this->assertSame(20, strlen($password));
        $this->assertTrue(Hash::check($password, $user->password));

        $this->get('/users')->assertSee($password)->assertSee('Berta Beispiel ist angelegt');
        $this->get('/users')->assertDontSee($password);

        $entry = AuditLog::where('action', 'user.created')->sole();
        $this->assertSame(['email' => 'berta@example.de', 'role' => 'admin'], $entry->context);
        $this->assertStringNotContainsString($password, json_encode($entry->toArray()));
    }

    public function test_the_form_checks_its_input(): void
    {
        $admin = $this->admin(['email' => 'admin@example.de']);

        $this->confirmed($admin)->post('/users', ['first_name' => '', 'last_name' => 'X', 'email' => 'no-mail'])
            ->assertSessionHasErrors(['first_name', 'email']);
        $this->confirmed($admin)->post('/users', ['first_name' => 'Doppelt', 'last_name' => 'X', 'email' => 'admin@example.de'])
            ->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
    }

    public function test_a_new_administrator_changes_the_password_first_then_sets_up_two_factor(): void
    {
        $user = User::factory()->admin()->create(['password' => 'Start-Passwort-123', 'password_change_required' => true]);

        $this->actingAs($user)->get('/')->assertRedirect('/account');
        $this->actingAs($user)->get('/servers')->assertRedirect('/account');
        $this->actingAs($user)->get('/account')->assertOk()->assertSee('Bitte ändern Sie zuerst Ihr Start-Passwort.', false);

        $this->actingAs($user)->put('/user/password', [
            'current_password' => 'Start-Passwort-123',
            'password' => 'Ein-neues-Passwort-456',
            'password_confirmation' => 'Ein-neues-Passwort-456',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->password_change_required);
        // Now two-factor is the next step.
        $this->actingAs($user->fresh())->get('/')->assertRedirect('/account/security');
    }

    public function test_an_admin_deletes_an_account_after_typing_its_email(): void
    {
        $admin = $this->admin();
        $other = $this->admin(['email' => 'berta@example.de']);

        $this->confirmed($admin)->delete("/users/{$other->id}", ['confirm_email' => 'falsch@example.de'])
            ->assertSessionHas('error', 'Die E-Mail-Adresse stimmt nicht überein.');
        $this->assertNotNull($other->fresh());

        $this->confirmed($admin)->delete("/users/{$other->id}", ['confirm_email' => 'berta@example.de'])
            ->assertRedirect('/users');
        $this->assertNull($other->fresh());
        $this->assertSame(1, AuditLog::where('action', 'user.deleted')->count());
    }

    public function test_nobody_deletes_their_own_account_or_the_last_administrator_with_two_factor(): void
    {
        $admin = $this->admin(['email' => 'admin@example.de']);
        $this->confirmed($admin)->delete("/users/{$admin->id}", ['confirm_email' => 'admin@example.de'])->assertForbidden();

        // A second admin without two-factor (signed in through Pocket ID)
        // cannot delete the only one with it.
        $new = User::factory()->admin()->create(['email' => 'neu@example.de']);
        $this->actingAs($new)->withSession(['auth.password_confirmed_at' => time(), 'auth.via' => 'oidc'])
            ->delete("/users/{$admin->id}", ['confirm_email' => 'admin@example.de'])->assertForbidden();
        $this->assertNotNull($admin->fresh());

        // The other way round is fine.
        $this->confirmed($admin)->delete("/users/{$new->id}", ['confirm_email' => 'neu@example.de'])->assertRedirect('/users');
    }

    public function test_customers_cannot_see_or_manage_accounts(): void
    {
        $customer = User::factory()->create(['email' => 'kunde@example.de']);
        $admin = $this->admin();

        $this->confirmed($customer)->get('/users')->assertForbidden();
        $this->confirmed($customer)->get('/users/create')->assertForbidden();
        $this->confirmed($customer)->post('/users', ['first_name' => 'Böse', 'last_name' => 'X', 'email' => 'boese@example.de'])->assertForbidden();
        $this->confirmed($customer)->delete("/users/{$admin->id}", ['confirm_email' => $admin->email])->assertForbidden();

        $this->assertSame(2, User::count());
    }

    public function test_the_overview_counts_websites_and_links_users(): void
    {
        $admin = $this->admin();
        $site = Site::factory()->create(['tls' => true]);
        Site::factory()->create(['tls' => false]);
        Database::factory()->create(['site_id' => $site->id]);

        $this->actingAs($admin)->get('/')
            ->assertOk()
            ->assertSee('Webseiten')
            ->assertSee('1 mit Zertifikat, 1 Datenbank')
            ->assertSee(route('users.index'), false)
            ->assertDontSee('Angelegte Kunden');
    }
}
