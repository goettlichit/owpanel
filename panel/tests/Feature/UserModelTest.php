<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_ids_are_ulids(): void
    {
        $user = User::factory()->create();

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $user->id);
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $user->customer_id);
    }

    public function test_admin_cannot_belong_to_a_customer(): void
    {
        $this->expectException(InvalidArgumentException::class);

        User::factory()->admin()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    public function test_customer_user_needs_a_customer(): void
    {
        $this->expectException(InvalidArgumentException::class);

        User::factory()->create(['customer_id' => null]);
    }

    public function test_role_and_customer_cannot_be_mass_assigned(): void
    {
        $user = new User(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com', 'role' => 'admin', 'customer_id' => 'abc']);

        $this->assertNull($user->role);
        $this->assertNull($user->customer_id);
        $this->assertSame(Role::Customer, User::factory()->make()->role);
    }
}
