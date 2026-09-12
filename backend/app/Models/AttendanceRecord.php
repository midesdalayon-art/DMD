<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Employee;

class AttendanceRecord extends Model
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_LATE = 'late';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_INCOMPLETE = 'incomplete';

    public const METHOD_MANUAL = 'manual';
    public const METHOD_FINGERPRINT = 'fingerprint';
    public const METHOD_FACE = 'face';

    protected $fillable = [
        'employee_id',
        'user_id',
        'attendance_date',
        'time_in',
        'time_out',
        'status',
        'verification_method',
        'device_id',
        'remarks',
        'corrected_by',
        'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'corrected_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PRESENT,
            self::STATUS_LATE,
            self::STATUS_ABSENT,
            self::STATUS_INCOMPLETE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function verificationMethods(): array
    {
        return [
            self::METHOD_MANUAL,
            self::METHOD_FINGERPRINT,
            self::METHOD_FACE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function staffRoles(): array
    {
        return [
            User::ROLE_ADMIN,
            User::ROLE_MANAGER,
            User::ROLE_FRONT_DESK,
            User::ROLE_HOUSEKEEPING,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AttendanceRecordHistory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'user_id' => $this->user_id,
            'employee' => $this->employee?->publicData(),
            'staff' => $this->staff?->publicProfile(),
            'attendance_date' => $this->attendance_date?->toDateString(),
            'time_in' => $this->time_in,
            'time_out' => $this->time_out,
            'status' => $this->status,
            'verification_method' => $this->verification_method,
            'device_id' => $this->device_id,
            'remarks' => $this->remarks,
            'corrected_by' => $this->corrector?->publicProfile(),
            'corrected_at' => $this->corrected_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
