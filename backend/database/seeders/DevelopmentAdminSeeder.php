<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevelopmentAdminSeeder extends Seeder
{
    private const EMAIL = 'admin@dmdresort.test';

    private const PASSWORD = 'AdminPassword1';

    /**
     * Seed a local development administrator account.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Development admin seeding is only allowed in local or testing environments.');

            return;
        }

        User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Development Admin',
                'first_name' => 'Development',
                'last_name' => 'Admin',
                'contact_number' => '09170000001',
                'password' => Hash::make(self::PASSWORD),
                'role' => User::ROLE_ADMIN,
            ],
        );

        $this->command?->info('Development admin account is ready: '.self::EMAIL);
    }
}
