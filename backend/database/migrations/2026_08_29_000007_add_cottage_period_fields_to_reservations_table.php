<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('cottage_period_type', 20)->nullable()->after('stay_days');
            $table->unsignedInteger('cottage_period_count')->nullable()->after('cottage_period_type');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['cottage_period_type', 'cottage_period_count']);
        });
    }
};
