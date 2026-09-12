<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'employee_code',
        'user_id',
        'fingerprint_id',
        'first_name',
        'last_name',
        'position',
        'phone',
        'date_hired',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_hired' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
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

    /** @return list<string> */
    public static function loginableUserRoles(): array
    {
        return [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_FRONT_DESK];
    }

    /**
     * Positions that may be linked to a system login. Operational staff do
     * not need and must not receive a login account to be assigned tasks.
     *
     * @return list<string>
     */
    public static function loginablePositions(): array
    {
        return ['Admin', 'Manager', 'Front Desk'];
    }

    /** @return list<string> */
    public static function positions(): array
    {
        return ['Cleaning Staff', 'Maintenance Staff', 'Utility Staff', 'Security Guard', 'Lifeguard', 'Other'];
    }

    public function getNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'user_id' => $this->user_id,
            'fingerprint_id' => $this->fingerprint_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => trim($this->first_name.' '.$this->last_name),
            'position' => $this->position,
            'phone' => $this->phone,
            'date_hired' => $this->date_hired?->toDateString(),
            'status' => $this->status,
            'system_account' => $this->user?->publicProfile(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
