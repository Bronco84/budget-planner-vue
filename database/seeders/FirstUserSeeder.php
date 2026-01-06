<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class FirstUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if user already exists
        if (User::where('email', 'admin@budget-planner.test')->exists()) {
            $this->command->info('User already exists!');
            return;
        }

        // Create the first user
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@budget-planner.test',
            'email_verified_at' => now(),
            'is_admin' => true,
            'password' => bcrypt('temporary-password-not-used'), // Required by database but not used
        ]);

        $this->command->info('First user created successfully!');
        $this->command->info('Email: admin@budget-planner.test');
        $this->command->info('');
        $this->command->info('Next steps:');
        $this->command->info('1. Visit http://localhost:8000/magic-link');
        $this->command->info('2. Enter: admin@budget-planner.test');
        $this->command->info('3. Check your email (or Mailpit) for the magic link');
        $this->command->info('4. Click the link to log in');
        $this->command->info('5. Visit /passkey/register to register your passkey');
    }
}
