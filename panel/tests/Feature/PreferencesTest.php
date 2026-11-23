<?php

namespace Tests\Feature;

use App\Enums\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_switch_language_for_the_session(): void
    {
        $this->from('/login')
            ->post('/preferences/locale', ['locale' => 'en'])
            ->assertRedirect('/login')
            ->assertSessionHas('locale', 'en');

        $this->get('/login')->assertSee('<html lang="en"', false);
    }

    public function test_language_is_stored_on_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/preferences/locale', ['locale' => 'en']);

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_account_language_wins_over_session_and_browser(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->withSession(['locale' => 'de'])
            ->withHeader('Accept-Language', 'de')
            ->get('/my/sites')
            ->assertSee('<html lang="en"', false);
    }

    public function test_unknown_language_is_rejected(): void
    {
        $this->from('/login')
            ->post('/preferences/locale', ['locale' => 'xx'])
            ->assertSessionHasErrors('locale')
            ->assertSessionMissing('locale');
    }

    public function test_manipulated_session_value_is_ignored(): void
    {
        $this->withSession(['locale' => '../../etc/passwd', 'theme' => '"><script>'])
            ->get('/login')
            ->assertOk()
            ->assertSee('<html lang="de" data-theme-preference="system"', false);
    }

    public function test_theme_follows_the_system_by_default(): void
    {
        $this->get('/login')->assertSee('data-theme-preference="system"', false);
    }

    public function test_guest_can_choose_dark_mode_for_the_session(): void
    {
        $this->from('/login')
            ->post('/preferences/theme', ['theme' => 'dark'])
            ->assertSessionHas('theme', 'dark');

        $this->get('/login')->assertSee('data-theme-preference="dark"', false);
    }

    public function test_theme_is_stored_on_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/preferences/theme', ['theme' => 'light']);

        $this->assertSame(Theme::Light, $user->fresh()->theme);
        $this->actingAs($user->fresh())->get('/my/sites')->assertSee('data-theme-preference="light"', false);
    }

    public function test_unknown_theme_is_rejected(): void
    {
        $this->post('/preferences/theme', ['theme' => 'neon'])
            ->assertSessionHasErrors('theme');
    }
}
