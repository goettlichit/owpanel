<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Greeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GreetingTest extends TestCase
{
    use RefreshDatabase;

    public function test_greeting_follows_the_time_of_day_in_the_display_time_zone(): void
    {
        config(['owpanel.timezone' => 'Europe/Berlin']);
        $user = User::factory()->make(['first_name' => 'Alexander']);

        // Times in UTC; Berlin is one hour ahead in November.
        $this->assertSame('Guten Morgen, Alexander', Greeting::for($user, Carbon::parse('2026-11-09 06:30', 'UTC')));
        $this->assertSame('Guten Tag, Alexander', Greeting::for($user, Carbon::parse('2026-11-09 10:00', 'UTC')));
        $this->assertSame('Guten Abend, Alexander', Greeting::for($user, Carbon::parse('2026-11-09 17:00', 'UTC')));
        $this->assertSame('Guten Abend, Alexander', Greeting::for($user, Carbon::parse('2026-11-09 02:00', 'UTC')));
    }

    public function test_header_shows_greeting_full_name_and_email(): void
    {
        $user = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Beispiel', 'email' => 'maria@example.com']);

        $this->actingAs($user)->get('/my/sites')
            ->assertOk()
            ->assertSee(', Maria')
            ->assertSee('Maria Beispiel')
            ->assertSee('maria@example.com')
            ->assertSee('>MB<', false)
            ->assertSee('Open Web Panel')
            ->assertSee('favicon.svg');
    }
}
