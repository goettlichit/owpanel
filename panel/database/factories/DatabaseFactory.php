<?php

namespace Database\Factories;

use App\Enums\SiteStatus;
use App\Models\Database;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Database>
 */
class DatabaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'server_id' => fn (array $attributes) => Site::find($attributes['site_id'])->server_id,
            'name' => fn (array $attributes) => Site::find($attributes['site_id'])->system_user.'_'.fake()->unique()->lexify('db????'),
            'password' => Database::generatePassword(),
            'status' => SiteStatus::Active,
        ];
    }
}
