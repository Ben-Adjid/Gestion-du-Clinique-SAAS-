<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'phone', 'clinic_id', 'role_id', 'totp_secret', 'totp_enabled', 'status', 'metadata'])]
#[Hidden(['password', 'remember_token', 'totp_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    private const PASSWORD_HISTORY_DEPTH = 5;

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'totp_enabled' => 'boolean',
        'metadata' => 'json',
        'last_login_at' => 'datetime',
    ];

    // Relationships
    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
                    ->withPivot('clinic_id');
    }

    /** Specialty and registration number of a doctor (absent for other roles). */
    public function doctorProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DoctorProfile::class, 'user_id');
    }

    public function patient()
    {
        return $this->hasOne(Patient::class);
    }

    public function requiresTotp(): bool
    {
        return in_array($this->role?->name, config('clinic.totp_required_roles'), true);
    }

    public function usedPasswordRecently(string $plainPassword): bool
    {
        return PasswordHistory::query()
            ->where('user_id', $this->id)
            ->latest('created_at')
            ->latest('id')
            ->limit(self::PASSWORD_HISTORY_DEPTH)
            ->pluck('password')
            ->push($this->password)
            ->contains(fn (string $hash) => Hash::check($plainPassword, $hash));
    }

    protected static function booted(): void
    {
        static::saved(function (User $user) {
            if ($user->wasRecentlyCreated || $user->wasChanged('password')) {
                PasswordHistory::create(['user_id' => $user->id, 'password' => $user->password]);
            }
        });
    }
}
