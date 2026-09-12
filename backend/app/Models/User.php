<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_FRONT_DESK = 'front_desk_staff';

    public const ROLE_HOUSEKEEPING = 'housekeeping_staff';

    public const ROLE_GUEST = 'guest';

    public const ROLE_UNKNOWN = 'unknown';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'google_id',
        'contact_number',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'email_verification_sent_at' => 'datetime',
            'email_verification_attempts' => 'integer',
            'email_verification_locked_at' => 'datetime',
            'registration_cancel_token_issued_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_ADMIN,
            self::ROLE_MANAGER,
            self::ROLE_FRONT_DESK,
            self::ROLE_GUEST,
        ];
    }

    public function normalizedRole(): string
    {
        return match (strtolower(trim((string) $this->role))) {
            'administrator', 'admin' => self::ROLE_ADMIN,
            'manager' => self::ROLE_MANAGER,
            'front desk staff', 'front-desk-staff', 'front_desk', 'frontdesk', 'frontdesk_staff', 'front_desk_staff' => self::ROLE_FRONT_DESK,
            'housekeeping staff', 'housekeeping-staff', 'housekeeping', 'housekeeping_staff' => self::ROLE_HOUSEKEEPING,
            'customer', 'guest', 'guest_customer', 'guest/customer' => self::ROLE_GUEST,
            default => self::ROLE_UNKNOWN,
        };
    }

    public function dashboardPath(): string
    {
        return match ($this->normalizedRole()) {
            self::ROLE_ADMIN => '/admin/dashboard',
            self::ROLE_MANAGER => '/manager/dashboard',
            self::ROLE_FRONT_DESK => '/frontdesk/dashboard',
            self::ROLE_GUEST => '/account',
            default => '/',
        };
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * @return array<string, string|int|null>
     */
    public function publicProfile(): array
    {
        $firstName = $this->first_name ?: trim(strtok($this->name, ' ') ?: $this->name);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $firstName,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'contact_number' => $this->contact_number,
            'role' => $this->normalizedRole(),
            'is_active' => $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'redirect_to' => $this->dashboardPath(),
        ];
    }
}
