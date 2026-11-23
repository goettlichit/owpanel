<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Intentionally empty: the panel never creates accounts with known
     * passwords. The first administrator is created with
     * `php artisan owpanel:create-admin`.
     */
    public function run(): void
    {
        //
    }
}
