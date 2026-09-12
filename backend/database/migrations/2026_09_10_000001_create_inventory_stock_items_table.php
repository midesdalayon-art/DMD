<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('sku', 80)->unique();
            $table->string('category', 80);
            $table->string('unit', 40);
            $table->unsignedInteger('current_quantity')->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->string('location', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
            $table->index('current_quantity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_items');
    }
};

