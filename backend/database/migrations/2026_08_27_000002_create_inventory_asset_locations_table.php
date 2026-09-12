<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_asset_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_asset_id')->constrained()->cascadeOnDelete();
            $table->string('location_signature', 180);
            $table->string('location_type', 40)->index();
            $table->foreignId('accommodation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_name', 160)->nullable();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['inventory_asset_id', 'location_signature']);
            $table->index(['inventory_asset_id', 'location_type']);
        });

        $signature = function (string $locationType, mixed $accommodationId, mixed $locationName): string {
            return implode('|', [
                $locationType,
                $locationType === 'accommodation' ? (string) $accommodationId : '',
                $locationType === 'accommodation' ? '' : mb_strtolower(trim((string) $locationName)),
            ]);
        };

        DB::table('inventory_assets')->orderBy('id')->chunkById(100, function ($assets) use ($signature): void {
            foreach ($assets as $asset) {
                DB::table('inventory_asset_locations')->insert([
                    'inventory_asset_id' => $asset->id,
                    'location_signature' => $signature(
                        $asset->location_type,
                        $asset->accommodation_id,
                        $asset->location_name,
                    ),
                    'location_type' => $asset->location_type,
                    'accommodation_id' => $asset->accommodation_id,
                    'location_name' => $asset->location_name,
                    'quantity' => $asset->quantity,
                    'created_at' => $asset->created_at,
                    'updated_at' => $asset->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_asset_locations');
    }
};
