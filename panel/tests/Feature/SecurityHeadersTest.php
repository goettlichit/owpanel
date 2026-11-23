<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Content Security Policy and HSTS of the panel (E-157).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_has_a_policy_without_inline_scripts_or_eval(): void
    {
        $response = $this->get('/login')->assertOk();
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        foreach (["object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }

        // Error pages outside the routes too.
        $this->assertNotNull($this->get('/does-not-exist')->assertNotFound()->headers->get('Content-Security-Policy'));
    }

    public function test_scripts_of_the_page_carry_the_nonce(): void
    {
        $user = User::factory()->admin()->withTwoFactor()->create();
        $response = $this->actingAs($user)->get('/')->assertOk();
        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $match);

        preg_match_all('/<script\b([^>]*)>/', $response->getContent(), $tags);
        $this->assertNotEmpty($tags[1]);
        foreach ($tags[1] as $attributes) {
            // Either a file of the panel or the nonce of this answer.
            $this->assertTrue(
                str_contains($attributes, 'nonce="'.$match[1].'"') || str_contains($attributes, 'src="'.rtrim(asset(''), '/').'/'),
                "Script without nonce: <script{$attributes}>",
            );
        }
        $this->assertStringNotContainsString(' x-data="{', $response->getContent());
    }

    public function test_hsts_only_over_https(): void
    {
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_forms_may_end_at_pocket_id_when_it_is_set_up(): void
    {
        Setting::put(Setting::OIDC_ISSUER, 'https://id.example.de/');

        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("form-action 'self' https://id.example.de;", $policy);
    }
}
