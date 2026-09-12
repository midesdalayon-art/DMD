<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('housekeeping_tasks') || ! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('housekeeping_tasks', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->after('accommodation_id');
            }
        });

        DB::table('housekeeping_tasks')->whereNotNull('assigned_to')->orderBy('id')->each(function (object $task): void {
            $employeeId = DB::table('employees')->where('user_id', $task->assigned_to)->value('id');

            if (! $employeeId) {
                $user = DB::table('users')->where('id', $task->assigned_to)->first();
                if ($user && in_array($user->role, [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_FRONT_DESK, User::ROLE_HOUSEKEEPING], true)) {
                    $employeeId = DB::table('employees')->insertGetId([
                        'employee_code' => 'MIG-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                        'user_id' => $user->id,
                        'first_name' => $user->first_name ?: 'Legacy',
                        'last_name' => $user->last_name ?: 'Employee',
                        'position' => $user->role === User::ROLE_HOUSEKEEPING ? 'Cleaning Staff' : ucfirst(str_replace('_', ' ', $user->role)),
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($employeeId) {
                DB::table('housekeeping_tasks')->where('id', $task->id)->update(['employee_id' => $employeeId]);
            }
        });

        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            $table->dropIndex('housekeeping_tasks_assigned_to_status_index');
            $table->dropForeign(['assigned_to']);
            $table->dropColumn('assigned_to');
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('housekeeping_tasks') || ! Schema::hasColumn('housekeeping_tasks', 'employee_id')) {
            return;
        }

        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('accommodation_id');
        });

        DB::table('housekeeping_tasks')->whereNotNull('employee_id')->orderBy('id')->each(function (object $task): void {
            $userId = DB::table('employees')->where('id', $task->employee_id)->value('user_id');
            if ($userId) {
                DB::table('housekeeping_tasks')->where('id', $task->id)->update(['assigned_to' => $userId]);
            }
        });

        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            $table->dropIndex('housekeeping_tasks_employee_id_status_index');
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->index(['assigned_to', 'status']);
        });
    }
};
