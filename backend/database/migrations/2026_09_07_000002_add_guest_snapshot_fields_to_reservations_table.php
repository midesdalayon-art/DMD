<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('guest_first_name', 100)->nullable()->after('user_id');
            $table->string('guest_last_name', 100)->nullable()->after('guest_first_name');
            $table->string('guest_email')->nullable()->after('guest_last_name');
            $table->string('guest_phone', 30)->nullable()->after('guest_email');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'guest_first_name',
                'guest_last_name',
                'guest_email',
                'guest_phone',
            ]);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
};
