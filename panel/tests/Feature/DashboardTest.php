<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    /**
     * Customers start in their own area (E-124).
     */
    public function test_customer_starts_in_the_customer_area(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertRedirect('/my/sites');
        $this->actingAs($user)->get('/my/sites')->assertOk()
            ->assertSee('Meine Webseiten')->assertDontSee(route('customers.index'));
    }

    public function test_login_page_renders_in_german_by_default(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('<html lang="de"', false)
            ->assertSee('Anmelden');
    }

    public function test_login_page_follows_the_browser_language(): void
    {
        $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')
            ->get('/login')
            ->assertSee('<html lang="en"', false)
            ->assertSee('Sign in');
    }

    public function test_regional_variant_and_order_of_preference_are_respected(): void
    {
        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9,de-AT;q=0.8,en;q=0.7')
            ->get('/login')
            ->assertSee('<html lang="de"', false);
    }

    public function test_unsupported_browser_language_falls_back_to_app_locale(): void
    {
        config(['app.locale' => 'en']);

        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
            ->get('/login')
            ->assertSee('<html lang="en"', false);
    }
}
