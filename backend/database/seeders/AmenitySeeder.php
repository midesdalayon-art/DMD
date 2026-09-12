<?php

namespace Database\Seeders;

use App\Models\Amenity;
use Illuminate\Database\Seeder;

class AmenitySeeder extends Seeder
{
    /**
     * Seed useful resort amenities without creating duplicate records.
     */
    public function run(): void
    {
        $amenities = [
            ['name' => 'Wi-Fi', 'icon' => 'wifi'],
            ['name' => 'Air Conditioning', 'icon' => 'snowflake'],
            ['name' => 'Private Bathroom', 'icon' => 'bath'],
            ['name' => 'Hot Shower', 'icon' => 'shower-head'],
            ['name' => 'Television', 'icon' => 'tv'],
            ['name' => 'Kitchen', 'icon' => 'utensils'],
            ['name' => 'Refrigerator', 'icon' => 'refrigerator'],
            ['name' => 'Electric Fan', 'icon' => 'fan'],
            ['name' => 'Towels', 'icon' => 'towel'],
            ['name' => 'Parking', 'icon' => 'parking-circle'],
            ['name' => 'Swimming Pool Access', 'icon' => 'waves'],
            ['name' => 'Balcony', 'icon' => 'panel-top'],
            ['name' => 'Dining Area', 'icon' => 'utensils-crossed'],
            ['name' => 'Tables & Chairs', 'icon' => 'armchair'],
            ['name' => 'Sound System', 'icon' => 'speaker'],
            ['name' => 'Projector', 'icon' => 'projector'],
        ];

        foreach ($amenities as $amenity) {
            $existing = Amenity::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($amenity['name'])])
                ->first();

            if ($existing) {
                $existing->update(['icon' => $amenity['icon']]);
                continue;
            }

            Amenity::create($amenity);
        }
    }
}
