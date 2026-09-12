<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('housekeeping_tasks', 'assigned_staff_name')) {
                $table->string('assigned_staff_name', 120)->nullable()->after('assigned_to');
            }
        });

        DB::table('accommodations')
            ->where('housekeeping_status', 'clean')
            ->update(['housekeeping_status' => 'ready']);

        if (Schema::hasTable('users')) {
            DB::table('users')
                ->where('role', 'housekeeping_staff')
                ->update(['is_active' => false]);
        }
    }

    public function down(): void
    {
        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('housekeeping_tasks', 'assigned_staff_name')) {
                $table->dropColumn('assigned_staff_name');
            }
        });

        DB::table('accommodations')
            ->where('housekeeping_status', 'ready')
            ->update(['housekeeping_status' => 'clean']);
    }
};
