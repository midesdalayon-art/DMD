<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('guest_access_token_hash', 64)->nullable()->unique()->after('guest_checkout_token_expires_at');
            $table->timestamp('guest_access_token_issued_at')->nullable()->after('guest_access_token_hash');
            $table->timestamp('guest_access_token_revoked_at')->nullable()->after('guest_access_token_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['guest_access_token_hash']);
            $table->dropColumn([
                'guest_access_token_hash',
                'guest_access_token_issued_at',
                'guest_access_token_revoked_at',
            ]);
        });
    }
};
