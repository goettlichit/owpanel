<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Results of the security review (E-156): every sending route has a rate
 * limit, and private files have no route of their own.
 */
class RouteHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_sending_route_has_a_rate_limit(): void
    {
        // Each of these limits itself in the controller or middleware and
        // answers with a message instead of the bare 429 page.
        $ownLimit = ['settings.mail.test', 'password.email', 'password.update', 'livewire.update', 'default-livewire.update'];

        $missing = collect(Router::getRoutes()->getRoutes())
            ->reject(fn (Route $route) => array_diff($route->methods(), ['GET', 'HEAD']) === [])
            ->reject(fn (Route $route) => in_array($route->getName(), $ownLimit, true))
            ->reject(fn (Route $route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $missing);
    }

    public function test_private_files_are_not_served(): void
    {
        $this->assertFalse(Router::has('storage.local'));
        $this->get('/storage/anything')->assertNotFound();
    }

    public function test_account_changes_are_limited(): void
    {
        $user = User::factory()->admin()->withTwoFactor()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->put('/user/profile-information', ['first_name' => 'Anna', 'last_name' => 'Muster', 'email' => $user->email]);
        }

        $this->actingAs($user)->put('/user/profile-information', ['first_name' => 'Anna', 'last_name' => 'Muster', 'email' => $user->email])
            ->assertStatus(429);
    }
}
