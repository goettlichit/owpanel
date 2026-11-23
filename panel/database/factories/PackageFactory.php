<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Webspace '.fake()->unique()->numberBetween(1, 99999),
            'max_sites' => 1,
            'storage_mb' => 5120,
            'max_databases' => 1,
            'max_cron_jobs' => 1,
            'min_cron_interval' => 5,
            'daily_self_service' => 2,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(array_fill_keys(Package::LIMITS, null));
    }
}
