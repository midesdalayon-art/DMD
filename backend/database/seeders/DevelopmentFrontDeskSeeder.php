<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevelopmentFrontDeskSeeder extends Seeder
{
    public const EMAIL = 'frontdesk@dmdresort.test';

    public const PASSWORD = 'FrontDeskPassword1';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'DMD Front Desk',
                'first_name' => 'DMD',
                'last_name' => 'Front Desk',
                'contact_number' => '09170000003',
                'password' => Hash::make(self::PASSWORD),
                'role' => User::ROLE_FRONT_DESK,
                'is_active' => true,
            ],
        );
    }
}