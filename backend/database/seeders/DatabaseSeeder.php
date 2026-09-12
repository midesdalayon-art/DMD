<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'first_name' => 'Test',
                'last_name' => 'User',
                'contact_number' => '09170000000',
                'password' => Hash::make('password'),
                'role' => User::ROLE_GUEST,
            ],
        );

        $this->call([
            AmenitySeeder::class,
            AccommodationSeeder::class,
            ChatbotSeeder::class,
        ]);
    }
}
