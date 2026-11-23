<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_links_to_the_source_code_even_before_login(): void
    {
        $this->get('/login')
            ->assertSee('href="https://github.com/goettlichit/owpanel"', false)
            ->assertSee('Quellcode');
    }

    public function test_release_links_to_its_own_tag(): void
    {
        config(['owpanel.version' => '1.2.3']);

        $this->actingAs(User::factory()->create())
            ->get('/my/sites')
            ->assertSee('href="https://github.com/goettlichit/owpanel/tree/v1.2.3"', false)
            ->assertSee('Version 1.2.3');
    }

    public function test_pages_load_no_assets_from_other_hosts(): void
    {
        $user = User::factory()->admin()->withTwoFactor()->create();

        foreach ([$this->get('/login'), $this->actingAs($user)->get('/'), $this->actingAs($user)->get('/account')] as $response) {
            $response->assertOk();
            preg_match_all('/<(?:script|link|img|iframe)\b[^>]*\b(?:src|href)="([^"]+)"/i', $response->getContent(), $matches);

            $this->assertNotEmpty($matches[1]);

            foreach ($matches[1] as $url) {
                $host = parse_url($url, PHP_URL_HOST);
                $this->assertTrue(
                    $host === null || $host === parse_url(config('app.url'), PHP_URL_HOST),
                    "Asset from another host: {$url}",
                );
            }
        }
    }

    public function test_sign_in_pages_use_the_same_header_as_the_panel(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $header = substr($html, strpos($html, '<header'), strpos($html, '</header>') - strpos($html, '<header'));
        $this->assertStringContainsString('ow-topbar ow-topbar-shadow', $header);
        $this->assertStringContainsString('ow-brand', $header);
        $this->assertStringContainsString('Darstellung wechseln', $header);
        $this->assertStringNotContainsString('ow-greeting', $header);

        // Nothing of the header is repeated above the card.
        $this->assertSame(1, substr_count($html, 'class="ow-brand"'));
    }

    public function test_header_content_lines_up_with_the_centred_page(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/my/sites')->getContent();

        $this->assertSame(2, substr_count($html, 'class="ow-bar"'));
        $this->assertStringContainsString('ow-greeting', $html);
    }

    public function test_administrators_see_help_and_system_facts_in_the_footer(): void
    {
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get('/')
            ->assertSee('Über OWPanel')
            ->assertSee('href="https://owpanel.org/docs/"', false)
            ->assertSee('href="https://github.com/goettlichit/owpanel/blob/main/CHANGELOG.md"', false)
            ->assertSee('href="https://github.com/goettlichit/owpanel/issues"', false)
            ->assertSee(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION)
            ->assertSee('Europe/Berlin');
    }

    public function test_customers_and_guests_see_only_the_bottom_line(): void
    {
        foreach ([$this->get('/login'), $this->actingAs(User::factory()->create())->get('/my/sites')] as $response) {
            $response->assertSee('href="https://goettlich.it"', false)
                ->assertSee('Lizenz: AGPL-3.0-or-later')
                ->assertDontSee('ow-footer-columns', false)
                ->assertDontSee('Fehler melden');
        }
    }

    public function test_help_links_point_to_the_tag_of_a_release(): void
    {
        config(['owpanel.version' => '1.2.3', 'owpanel.homepage_url' => null]);

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get('/')
            ->assertSee('href="https://github.com/goettlichit/owpanel/tree/v1.2.3/docs"', false)
            ->assertSee('href="https://github.com/goettlichit/owpanel/blob/v1.2.3/CHANGELOG.md"', false);
    }

    public function test_owpanel_links_to_the_homepage_only_once_it_is_set(): void
    {
        config(['owpanel.homepage_url' => null]);
        $this->get('/login')->assertDontSee('href="https://owpanel.org"', false);

        config(['owpanel.homepage_url' => 'https://owpanel.org/']);

        $this->get('/login')
            ->assertSee('href="https://owpanel.org/"', false)
            ->assertSee('href="https://goettlich.it"', false);
    }

    public function test_the_documentation_link_leads_to_the_website_in_the_language_of_the_panel(): void
    {
        $user = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($user)->get('/')->assertSee('href="https://owpanel.org/docs/"', false);
        $user->forceFill(['locale' => 'en'])->save();
        $this->actingAs($user)->get('/')->assertSee('href="https://owpanel.org/en/docs/"', false);
    }

    public function test_customers_find_help_in_their_documentation_in_their_language(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get('/my/sites')
            ->assertSee('href="https://owpanel.org/docs/customer-start/"', false)
            ->assertSee('>Hilfe</a>', false);
        $customer->forceFill(['locale' => 'en'])->save();
        $this->actingAs($customer)->get('/my/sites')
            ->assertSee('href="https://owpanel.org/en/docs/customer-start/"', false);
    }

    public function test_an_own_help_page_replaces_the_customer_documentation(): void
    {
        Setting::put(Setting::HELP_URL, 'https://example.de/hilfe');

        $this->actingAs(User::factory()->create())->get('/my/sites')
            ->assertSee('href="https://example.de/hilfe"', false)
            ->assertDontSee('customer-start', false);
    }

    public function test_help_for_customers_is_missing_without_homepage_and_own_page_and_for_others(): void
    {
        $this->get('/login')->assertDontSee('customer-start', false);
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get('/')->assertDontSee('customer-start', false);

        config(['owpanel.homepage_url' => null]);
        $this->actingAs(User::factory()->create())->get('/my/sites')->assertDontSee('>Hilfe</a>', false);
    }

    public function test_external_footer_links_open_in_a_new_tab(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertMatchesRegularExpression('#<a href="https://goettlich.it"[^>]*target="_blank" rel="noopener noreferrer"#', $html);
    }
}
