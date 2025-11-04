<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create comprehensive demo user with financial data
        $this->call(TestUserSeeder::class);

        // Optionally create additional simple test users
        // User::factory(10)->create();
    }
}
