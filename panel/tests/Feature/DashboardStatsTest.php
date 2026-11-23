<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_admin_sees_key_figures_in_green_when_all_is_well(): void
    {
        $this->actingAs($this->admin())->get('/')
            ->assertOk()
            ->assertSee('Fehlversuche (24 h)')
            ->assertSee('Alle Administratoren nutzen Zwei-Faktor')
            ->assertSee('ow-stat-ok', false)
            ->assertDontSee('ow-stat-danger', false);
    }

    public function test_failed_sign_ins_turn_orange_and_lockouts_red(): void
    {
        $admin = $this->admin();
        Audit::record('auth.failed');

        $this->actingAs($admin)->get('/')->assertSee('ow-stat-warning', false);

        Audit::record('auth.lockout');

        $this->actingAs($admin)->get('/')
            ->assertSee('ow-stat-danger', false)
            ->assertSee('1 Sperre nach zu vielen Versuchen')
            ->assertSee('Prüfen');
    }

    public function test_admin_without_two_factor_needs_action(): void
    {
        $admin = $this->admin();
        User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')
            ->assertSee('ow-stat-danger', false)
            ->assertSee('Aktion nötig')
            ->assertSee('1 Administrator ohne Zwei-Faktor');
    }

    public function test_customers_do_not_see_key_figures(): void
    {
        $this->actingAs(User::factory()->create())->get('/my/sites')
            ->assertOk()
            ->assertDontSee('ow-stat', false)
            ->assertDontSee('Fehlversuche');
    }
}
