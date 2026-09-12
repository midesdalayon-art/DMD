<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dateTimeTz('check_in_at')->nullable()->after('check_out');
            $table->dateTimeTz('check_out_at')->nullable()->after('check_in_at');
            $table->index(['accommodation_id', 'check_in_at', 'check_out_at'], 'reservations_room_time_index');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_room_time_index');
            $table->dropColumn(['check_in_at', 'check_out_at']);
        });
    }
};
