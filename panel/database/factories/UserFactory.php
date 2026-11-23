<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Customer user by default; use admin() for administrators.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role' => Role::Customer,
            'customer_id' => Customer::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role' => Role::Admin,
            'customer_id' => null,
        ]);
    }

    /**
     * Confirmed two-factor authentication with a known secret.
     */
    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXP'): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function withoutPassword(): static
    {
        return $this->state(fn () => ['password' => null]);
    }

    /**
     * Current one-time code for the secret used in withTwoFactor().
     */
    public static function currentCode(string $secret = 'JBSWY3DPEHPK3PXP'): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }
}
