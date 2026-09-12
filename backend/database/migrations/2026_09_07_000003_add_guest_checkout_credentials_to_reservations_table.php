<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('guest_checkout_token_hash', 64)->nullable()->unique()->after('guest_phone');
            $table->timestamp('guest_checkout_token_expires_at')->nullable()->after('guest_checkout_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['guest_checkout_token_hash']);
            $table->dropColumn(['guest_checkout_token_hash', 'guest_checkout_token_expires_at']);
        });
    }
};
