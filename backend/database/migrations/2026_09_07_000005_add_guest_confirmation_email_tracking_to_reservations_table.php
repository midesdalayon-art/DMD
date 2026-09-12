<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('guest_confirmation_email_status', 20)->nullable()->after('guest_access_token_revoked_at');
            $table->timestamp('guest_confirmation_email_sent_at')->nullable()->after('guest_confirmation_email_status');
            $table->timestamp('guest_confirmation_email_failed_at')->nullable()->after('guest_confirmation_email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn([
                'guest_confirmation_email_status',
                'guest_confirmation_email_sent_at',
                'guest_confirmation_email_failed_at',
            ]);
        });
    }
};
