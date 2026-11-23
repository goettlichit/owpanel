<?php

namespace Tests\Feature;

use App\Models\User;
use App\Oidc\OidcSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OidcFake;
use Tests\TestCase;

/**
 * Pocket ID for customer accounts only after an administrator turned it on
 * (E-114); System -> Accounts.
 */
class OidcAccessTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIRMED = ['auth.password_confirmed_at' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        OidcSettings::save(OidcFake::ISSUER, OidcFake::CLIENT_ID, OidcFake::CLIENT_SECRET, 'goettlichIT ID');
    }

    /**
     * @return array<string, int>
     */
    private function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    public function test_existing_and_new_customers_start_without_pocket_id(): void
    {
        $customer = User::factory()->create()->fresh();

        $this->assertFalse($customer->oidc_allowed);
        $this->assertFalse($customer->mayUseOidc());
    }

    public function test_an_administrator_turns_pocket_id_on_for_a_customer(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $customer = User::factory()->create(['email' => 'kunde@example.test']);

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put("/users/{$customer->id}/oidc", ['allowed' => '1'])
            ->assertRedirect('/users')
            ->assertSessionHas('success', 'goettlichIT ID für kunde@example.test freigeschaltet. Der Kunde verknüpft sein Konto einmal selbst.');

        $this->assertTrue($customer->fresh()->mayUseOidc());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.oidc_allowed', 'subject_id' => $customer->id, 'user_id' => $admin->id]);
    }

    public function test_turning_it_off_removes_the_link(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $customer = User::factory()->create(['oidc_allowed' => true]);
        $customer->oidcIdentities()->create(['issuer' => OidcFake::ISSUER, 'subject' => 'user-1234']);

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put("/users/{$customer->id}/oidc", ['allowed' => '0'])
            ->assertRedirect('/users');

        $this->assertFalse($customer->fresh()->oidc_allowed);
        $this->assertSame(0, $customer->oidcIdentities()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.oidc_revoked', 'subject_id' => $customer->id]);
    }

    public function test_the_switch_needs_an_administrator_a_customer_target_and_a_recent_password(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $otherAdmin = User::factory()->admin()->withTwoFactor()->create();
        $customer = User::factory()->create();

        $this->actingAs($customer)->withSession($this->confirmed())
            ->put("/users/{$customer->id}/oidc", ['allowed' => '1'])->assertForbidden();
        $this->actingAs($admin)->withSession($this->confirmed())
            ->put("/users/{$otherAdmin->id}/oidc", ['allowed' => '1'])->assertForbidden();
        $this->assertFalse($customer->fresh()->oidc_allowed);
        $this->assertFalse($otherAdmin->fresh()->oidc_allowed);
    }

    public function test_the_account_list_shows_the_state_and_the_switch_for_customers_only(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $customer = User::factory()->create();

        // Customers are managed on their page "Access" (E-138).
        $page = $this->actingAs($admin)->withSession($this->confirmed())->get('/users')->assertOk()
            ->assertSee('goettlichIT ID')
            ->assertSee('Beim Kunden verwalten')
            ->assertSee(route('customers.access', $customer->customer_id))
            ->assertDontSee(route('users.oidc', $customer))
            ->assertDontSee(route('users.oidc', $admin));

        OidcSettings::disable(null);
        $this->actingAs($admin)->withSession($this->confirmed())->get('/users')->assertOk()
            ->assertDontSee(route('users.oidc', $customer));
    }
}
