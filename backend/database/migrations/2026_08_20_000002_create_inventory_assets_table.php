<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_assets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('category', 80)->index();
            $table->string('asset_code', 80)->unique();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('condition', 40)->index();
            $table->string('status', 40)->index();
            $table->string('location_type', 40)->index();
            $table->foreignId('accommodation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_name', 160)->nullable();
            $table->date('acquisition_date')->nullable();
            $table->decimal('purchase_cost', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['location_type', 'location_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_assets');
    }
};
