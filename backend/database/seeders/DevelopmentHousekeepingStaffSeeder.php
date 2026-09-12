<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;

class DevelopmentHousekeepingStaffSeeder extends Seeder
{
    public const EMAIL = 'housekeeping@dmdresort.test';
    public const PASSWORD = 'HousekeepingPassword1';

    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            return;
        }

        User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Development Housekeeping',
                'first_name' => 'Development',
                'last_name' => 'Housekeeping',
                'contact_number' => '09170000003',
                'password' => Hash::make(self::PASSWORD),
                'role' => User::ROLE_HOUSEKEEPING,
                'is_active' => true,
            ],
        );
    }
}
