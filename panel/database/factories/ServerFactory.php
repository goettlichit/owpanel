<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'web'.fake()->unique()->numberBetween(1, 999),
            'host' => fake()->unique()->ipv4(),
            'port' => 22,
            'host_key' => self::hostKey(),
        ];
    }

    /**
     * A syntactically valid Ed25519 host key built from 32 random bytes.
     */
    public static function hostKey(?string $bytes = null): string
    {
        $bytes ??= random_bytes(32);

        return 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).$bytes);
    }
}
