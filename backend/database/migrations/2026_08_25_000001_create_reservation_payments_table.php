<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40)->index();
            $table->string('provider_reference', 120)->nullable()->index();
            $table->string('checkout_session_id', 120)->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->string('payment_id', 120)->nullable()->unique();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('PHP');
            $table->string('status', 40)->default('pending')->index();
            $table->string('payment_method', 40)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('raw_reference', 120)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['reservation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_payments');
    }
};
