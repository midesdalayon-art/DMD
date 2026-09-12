<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            $table->string('image_path', 2048);
            $table->boolean('is_primary')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['accommodation_id', 'sort_order']);
            $table->index(['accommodation_id', 'is_primary']);
        });

        DB::table('accommodations')
            ->whereNotNull('image_path')
            ->where('image_path', '<>', '')
            ->orderBy('id')
            ->chunkById(100, function ($accommodations) {
                foreach ($accommodations as $accommodation) {
                    DB::table('accommodation_images')->insert([
                        'accommodation_id' => $accommodation->id,
                        'image_path' => $accommodation->image_path,
                        'is_primary' => true,
                        'sort_order' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_images');
    }
};
