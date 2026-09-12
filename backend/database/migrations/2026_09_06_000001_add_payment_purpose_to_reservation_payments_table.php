<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_payments', function (Blueprint $table) {
            $table->string('purpose', 20)->default('full')->after('reservation_id');
            $table->index(['reservation_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('reservation_payments', function (Blueprint $table) {
            $table->dropIndex(['reservation_id', 'purpose']);
            $table->dropColumn('purpose');
        });
    }
};
