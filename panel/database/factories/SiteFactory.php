<?php

namespace Database\Factories;

use App\Enums\SiteStatus;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'domain' => fake()->unique()->domainWord().'.example.de',
            'type' => Site::TYPE_PHP,
            'system_user' => 'ow'.fake()->unique()->numberBetween(Site::USER_MIN, Site::USER_MAX),
            'php_version' => '8.5',
            'wants_tls' => true,
            'tls' => true,
            'status' => SiteStatus::Active,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => SiteStatus::Pending, 'php_version' => null, 'tls' => false]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => SiteStatus::Failed, 'tls' => false]);
    }

    public function withoutTls(): static
    {
        return $this->state(fn () => ['wants_tls' => false, 'tls' => false]);
    }
}
