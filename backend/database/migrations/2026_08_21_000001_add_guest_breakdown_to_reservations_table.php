<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedInteger('adults')->default(1)->after('guests');
            $table->unsignedInteger('children')->default(0)->after('adults');
            $table->unsignedInteger('infants')->default(0)->after('children');
        });

        DB::table('reservations')->update([
            'adults' => DB::raw('guests'),
            'children' => 0,
            'infants' => 0,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['adults', 'children', 'infants']);
        });
    }
};
