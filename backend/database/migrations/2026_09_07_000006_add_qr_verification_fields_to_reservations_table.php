<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('qr_token_hash', 64)->nullable()->unique()->after('guest_confirmation_email_failed_at');
            $table->timestamp('qr_token_issued_at')->nullable()->after('qr_token_hash');
            $table->timestamp('qr_token_revoked_at')->nullable()->after('qr_token_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['qr_token_hash']);
            $table->dropColumn([
                'qr_token_hash',
                'qr_token_issued_at',
                'qr_token_revoked_at',
            ]);
        });
    }
};
