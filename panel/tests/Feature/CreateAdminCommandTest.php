<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_administrator(): void
    {
        $this->artisan('owpanel:create-admin', ['--first-name' => 'Ada', '--last-name' => 'Lovelace', '--email' => 'Ada@Example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'correct horse battery')
            ->expectsQuestion('Repeat password', 'correct horse battery')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame(Role::Admin, $user->role);
        $this->assertNull($user->customer_id);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('AL', $user->initials);
        $this->assertTrue(Hash::check('correct horse battery', $user->password));
        $this->assertSame('user.created', AuditLog::sole()->action);
    }

    public function test_requires_first_and_last_name(): void
    {
        $this->artisan('owpanel:create-admin', ['--first-name' => 'Ada', '--last-name' => '', '--email' => 'ada@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'correct horse battery')
            ->expectsQuestion('Repeat password', 'correct horse battery')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_rejects_short_passwords(): void
    {
        $this->artisan('owpanel:create-admin', ['--first-name' => 'Ada', '--last-name' => 'Lovelace', '--email' => 'ada@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'short')
            ->expectsQuestion('Repeat password', 'short')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_rejects_mismatched_passwords(): void
    {
        $this->artisan('owpanel:create-admin', ['--first-name' => 'Ada', '--last-name' => 'Lovelace', '--email' => 'ada@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'correct horse battery')
            ->expectsQuestion('Repeat password', 'correct horse battery!')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_skip_existing_keeps_an_existing_administrator(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ada@example.com', 'password' => 'original password']);

        $this->artisan('owpanel:create-admin', ['--first-name' => 'Ada', '--last-name' => 'Lovelace', '--email' => 'Ada@example.com', '--skip-existing' => true])
            ->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check('original password', $admin->fresh()->password));
    }

    public function test_skip_existing_does_not_accept_a_customer_with_that_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->artisan('owpanel:create-admin', ['--first-name' => 'Ada', '--last-name' => 'Lovelace', '--email' => 'ada@example.com', '--skip-existing' => true])
            ->assertFailed();

        $this->assertSame(Role::Customer, User::sole()->role);
    }
}
