<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_asset_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60)->index();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['inventory_asset_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_asset_histories');
    }
};
