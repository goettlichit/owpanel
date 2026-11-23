<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Every card has a coloured line; only warning and danger tint the header
 * (E-077).
 */
class CardStyleTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_get_a_line_by_tone_and_a_tinted_header_only_when_important(): void
    {
        $render = fn (string $tone) => Blade::render('<x-card title="T" '.$tone.'>x</x-card>');

        $this->assertStringContainsString('card-status-top bg-blue', $render(''));
        $this->assertStringContainsString('card-status-top bg-blue', $render('tone="info"'));
        $this->assertStringNotContainsString('ow-card-header-info', $render('tone="info"'));
        $this->assertStringContainsString('card-status-top bg-green', $render('tone="success"'));
        $this->assertStringNotContainsString('ow-card-header-success', $render('tone="success"'));
        $this->assertStringContainsString('card-status-top bg-orange', $render('tone="warning"'));
        $this->assertStringContainsString('ow-card-header-warning', $render('tone="warning"'));
        $this->assertStringContainsString('card-status-top bg-red', $render('tone="danger"'));
        $this->assertStringContainsString('ow-card-header-danger', $render('tone="danger"'));
        $this->assertStringContainsString('card-status-top bg-green', $render('accent="green"'));
        $this->assertStringContainsString('card-status-top bg-blue', $render('accent="pink"'));
    }

    public function test_every_card_on_the_main_pages_has_a_line(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);

        foreach (['/', '/sites', "/sites/{$site->id}", '/servers', "/servers/{$server->id}", "/servers/{$server->id}/updates", "/servers/{$server->id}/tasks", '/settings', '/settings/backup', '/account'] as $url) {
            $html = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get($url)->assertOk()->getContent();

            // Cards minus the left menu, compared with the coloured lines.
            $cards = preg_match_all('/class="[^"]*\bcard\b(?![-\w])[^"]*"/', $html) - substr_count($html, 'ow-sidebar card');
            $this->assertSame($cards, substr_count($html, 'card-status-top'), $url);
        }
    }

    public function test_the_welcome_card_comes_first_on_the_dashboard(): void
    {
        $html = $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get('/')->getContent();

        $this->assertLessThan(strpos($html, 'ow-stat'), strpos($html, __('dashboard.welcome.title')));
    }
}
