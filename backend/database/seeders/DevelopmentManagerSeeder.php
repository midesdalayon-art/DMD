<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevelopmentManagerSeeder extends Seeder
{
    public const EMAIL = 'manager@dmdresort.test';

    public const PASSWORD = 'ManagerPassword1';

    /**
     * Seed a local development manager account.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Development manager seeding is only allowed in local or testing environments.');

            return;
        }

        User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Development Manager',
                'first_name' => 'Development',
                'last_name' => 'Manager',
                'contact_number' => '09170000002',
                'password' => Hash::make(self::PASSWORD),
                'role' => User::ROLE_MANAGER,
                'is_active' => true,
            ],
        );

        $this->command?->info('Development manager account is ready: '.self::EMAIL);
    }
}
