<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashToastTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_appear_as_toasts_under_the_header(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->withSession(['status' => 'profile-information-updated'])
            ->get('/account')
            ->assertOk()
            ->getContent();

        $toasts = strpos($html, 'class="ow-toasts');
        $this->assertNotFalse($toasts);
        $this->assertGreaterThan(strpos($html, '</nav>'), $toasts);
        $this->assertLessThan(strpos($html, 'class="ow-body'), $toasts);
        $this->assertStringContainsString('alert-success alert-dismissible ow-toast', $html);
        $this->assertStringContainsString('data-ow-autohide', $html);
        $this->assertStringContainsString('data-bs-dismiss="alert"', $html);
    }

    public function test_warnings_and_errors_stay_until_closed(): void
    {
        $user = User::factory()->create();

        foreach (['warning' => 'alert-warning', 'error' => 'alert-danger'] as $key => $class) {
            $this->actingAs($user)
                ->withSession([$key => 'Something to read'])
                ->get('/account')
                ->assertSee($class.' alert-dismissible ow-toast', false)
                ->assertSee('Something to read')
                ->assertDontSee('data-ow-autohide', false);
        }
    }
}
