<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * System -> Settings: links to imprint and privacy policy (E-091).
 */
class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_links_are_saved_and_shown_in_the_footer_also_on_the_login(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get('/settings')->assertOk()->assertSee('Impressum und Datenschutz');
        $this->actingAs($admin)->put('/settings', [
            'imprint_url' => 'https://example.de/impressum',
            'privacy_url' => 'https://example.de/datenschutz',
            'help_url' => 'https://example.de/hilfe',
        ])->assertRedirect('/settings')->assertSessionHas('status');

        $this->assertSame('https://example.de/impressum', Setting::get(Setting::IMPRINT_URL));
        $this->assertSame('https://example.de/hilfe', Setting::get(Setting::HELP_URL));
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.settings_changed']);

        $this->actingAs($admin)->get('/')->assertSee('href="https://example.de/impressum"', false);
        auth()->logout();
        $this->get('/login')
            ->assertSee('href="https://example.de/impressum"', false)
            ->assertSee('href="https://example.de/datenschutz"', false);
    }

    public function test_empty_links_are_not_shown(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        Setting::put(Setting::IMPRINT_URL, 'https://example.de/impressum');

        $this->actingAs($admin)->put('/settings', ['imprint_url' => '', 'privacy_url' => ''])->assertRedirect();

        $this->assertNull(Setting::get(Setting::IMPRINT_URL));
        $this->get('/login')->assertDontSee('Impressum</a>', false);
    }

    public function test_only_web_addresses_are_accepted(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();

        foreach (['javascript:alert(1)', 'ftp://example.de', 'example.de/impressum', 'data:text/html,x'] as $url) {
            $this->actingAs($admin)->put('/settings', ['imprint_url' => $url])->assertSessionHasErrors('imprint_url');
        }
        $this->assertNull(Setting::get(Setting::IMPRINT_URL));
    }

    public function test_customers_cannot_change_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/settings')->assertForbidden();
        $this->actingAs($user)->put('/settings', ['imprint_url' => 'https://evil.example'])->assertForbidden();
        $this->assertNull(Setting::get(Setting::IMPRINT_URL));
    }
}
