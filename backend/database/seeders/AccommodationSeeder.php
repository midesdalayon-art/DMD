<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use Illuminate\Database\Seeder;

class AccommodationSeeder extends Seeder
{
    /**
     * Seed demo accommodation records for development.
     */
    public function run(): void
    {
        $records = [
            [
                'name' => 'Demo Resort Room',
                'slug' => 'demo-resort-room',
                'type' => Accommodation::TYPE_ROOM,
                'capacity' => 2,
                'price_per_night' => 2500,
                'description' => 'Demo room data for development only. Replace with verified DMD Resort room information before production use.',
                'status' => Accommodation::STATUS_AVAILABLE,
                'image_path' => null,
            ],
            [
                'name' => 'Demo Family Room',
                'slug' => 'demo-family-room',
                'type' => Accommodation::TYPE_ROOM,
                'capacity' => 5,
                'price_per_night' => 4200,
                'description' => 'Demo family accommodation data for development only. Replace with verified DMD Resort details when available.',
                'status' => Accommodation::STATUS_AVAILABLE,
                'image_path' => null,
            ],
            [
                'name' => 'Demo Garden Cottage',
                'slug' => 'demo-garden-cottage',
                'type' => Accommodation::TYPE_COTTAGE,
                'capacity' => 4,
                'price_per_night' => 3200,
                'description' => 'Demo cottage data for development only. This is not a verified real DMD Resort listing.',
                'status' => Accommodation::STATUS_AVAILABLE,
                'image_path' => null,
            ],
            [
                'name' => 'Demo Function Hall',
                'slug' => 'demo-function-hall',
                'type' => Accommodation::TYPE_FUNCTION_HALL,
                'capacity' => 80,
                'price_per_night' => 12000,
                'description' => 'Demo function hall data for development only. Replace with verified DMD Resort event space details before production use.',
                'status' => Accommodation::STATUS_AVAILABLE,
                'image_path' => null,
            ],
            [
                'name' => 'Exclusive Resort Rental',
                'slug' => 'exclusive-resort-rental',
                'type' => Accommodation::TYPE_EXCLUSIVE_RESORT,
                'capacity' => 120,
                'price_per_night' => 20000,
                'description' => 'Private resort rental for groups and special events. Replace with verified DMD Resort package details before production use.',
                'status' => Accommodation::STATUS_AVAILABLE,
                'image_path' => null,
            ],
        ];

        foreach ($records as $record) {
            Accommodation::updateOrCreate(
                ['slug' => $record['slug']],
                $record,
            );
        }
    }
}
