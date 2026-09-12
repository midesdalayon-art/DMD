<?php

use App\Models\Employee;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('user_id')->constrained('employees')->restrictOnDelete();
            $table->index(['employee_id', 'attendance_date']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });

        $staffUsers = User::query()
            ->whereIn('role', AttendanceRecord::staffRoles())
            ->orderBy('id')
            ->get();

        foreach ($staffUsers as $user) {
            $existing = Employee::query()->where('user_id', $user->id)->first();

            if (! $existing) {
                Employee::create([
                    'employee_code' => sprintf('EMP-%04d', $user->id),
                    'user_id' => $user->id,
                    'first_name' => $user->first_name ?: trim(strtok($user->name, ' ') ?: $user->name),
                    'last_name' => $user->last_name ?: trim(str_replace(($user->first_name ?: trim(strtok($user->name, ' ') ?: $user->name)).' ', '', $user->name)) ?: 'Staff',
                    'position' => match ($user->normalizedRole()) {
                        User::ROLE_ADMIN => 'Admin',
                        User::ROLE_MANAGER => 'Manager',
                        User::ROLE_FRONT_DESK => 'Front Desk',
                        User::ROLE_HOUSEKEEPING => 'Housekeeping Staff',
                        default => 'Staff',
                    },
                    'phone' => $user->contact_number,
                    'date_hired' => null,
                    'status' => $user->is_active ? Employee::STATUS_ACTIVE : Employee::STATUS_INACTIVE,
                ]);
            }
        }

        DB::table('attendance_records')
            ->orderBy('id')
            ->get()
            ->each(function ($record) {
                $employeeId = DB::table('employees')
                    ->where('user_id', $record->user_id)
                    ->value('id');

                if ($employeeId) {
                    DB::table('attendance_records')
                        ->where('id', $record->id)
                        ->update(['employee_id' => $employeeId]);
                }
            });

        $maxCode = (int) Employee::query()
            ->pluck('employee_code')
            ->map(function (string $code): int {
                preg_match('/(\d+)$/', $code, $matches);

                return isset($matches[1]) ? (int) $matches[1] : 0;
            })
            ->max();

        DB::table('employee_code_sequences')->updateOrInsert(
            ['prefix' => 'EMP'],
            [
                'last_number' => $maxCode,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'attendance_date']);
            $table->dropConstrainedForeignId('employee_id');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::dropIfExists('employees');
        Schema::dropIfExists('employee_code_sequences');
    }
};
