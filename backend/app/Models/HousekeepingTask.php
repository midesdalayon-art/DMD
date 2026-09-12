<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HousekeepingTask extends Model
{
    public const TYPE_CLEANING = 'cleaning';
    public const TYPE_ROOM_PREPARATION = 'room_preparation';
    public const TYPE_INSPECTION = 'inspection';
    public const TYPE_MAINTENANCE = 'maintenance';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'accommodation_id',
        'employee_id',
        // Backwards-compatible request alias. It is mapped to employee_id below.
        'assigned_to',
        'assigned_staff_name',
        'created_by',
        'task_type',
        'priority',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'remarks',
        'maintenance_notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function taskTypes(): array
    {
        return [
            self::TYPE_CLEANING,
            self::TYPE_ROOM_PREPARATION,
            self::TYPE_INSPECTION,
            self::TYPE_MAINTENANCE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function priorities(): array
    {
        return [
            self::PRIORITY_LOW,
            self::PRIORITY_NORMAL,
            self::PRIORITY_HIGH,
            self::PRIORITY_URGENT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_ASSIGNED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function allowedStatusTransitions(): array
    {
        return [
            self::STATUS_PENDING => [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
            self::STATUS_ASSIGNED => [self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            self::STATUS_COMPLETED => [],
            self::STATUS_CANCELLED => [],
        ];
    }

    public function canTransitionTo(string $status): bool
    {
        if ($this->status === $status) {
            return true;
        }

        return in_array($status, self::allowedStatusTransitions()[$this->status] ?? [], true);
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function setAssignedToAttribute($value): void
    {
        $employeeId = Employee::query()->where('user_id', $value)->value('id');

        if (! $employeeId && $value) {
            $user = User::find($value);
            if ($user && in_array($user->normalizedRole(), Employee::staffRoles(), true)) {
                $employee = Employee::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'employee_code' => 'LEGACY-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                        'first_name' => $user->first_name ?: 'Legacy',
                        'last_name' => $user->last_name ?: 'Employee',
                        'position' => $user->normalizedRole() === User::ROLE_HOUSEKEEPING ? 'Cleaning Staff' : ucfirst(str_replace('_', ' ', $user->normalizedRole())),
                        'status' => Employee::STATUS_ACTIVE,
                    ],
                );
                $employeeId = $employee->id;
            }
        }

        $this->attributes['employee_id'] = $employeeId ?: $value;
    }

    public function getAssignedToAttribute(): mixed
    {
        return $this->attributes['employee_id'] ?? null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(HousekeepingTaskHistory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'accommodation_id' => $this->accommodation_id,
            'accommodation' => $this->accommodation?->publicData(),
            'employee_id' => $this->employee_id,
            // Keep the response alias while clients migrate to employee_id.
            'assigned_to' => $this->employee_id,
            'assigned_staff' => $this->assignedStaff?->publicData(),
            'assigned_employee' => $this->assignedStaff?->publicData(),
            'assigned_staff_name' => $this->assigned_staff_name,
            'task_type' => $this->task_type,
            'priority' => $this->priority,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'remarks' => $this->remarks,
            'maintenance_notes' => $this->maintenance_notes,
            'created_by' => $this->creator?->publicProfile(),
            'allowed_statuses' => self::allowedStatusTransitions()[$this->status] ?? [],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
