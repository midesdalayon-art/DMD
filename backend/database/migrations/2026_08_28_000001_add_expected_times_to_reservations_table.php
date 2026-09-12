<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->time('expected_arrival_time')->nullable()->after('preferred_arrival_time');
            $table->time('expected_departure_time')->nullable()->after('expected_arrival_time');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['expected_arrival_time', 'expected_departure_time']);
        });
    }
};
