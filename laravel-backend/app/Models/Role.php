<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    /** French label of each role, shown to users. The stored display_name is kept as a fallback. */
    public const FRENCH_LABELS = [
        'SUPER_ADMIN' => 'Super administrateur',
        'ADMIN_CLINIC' => 'Responsable de clinique',
        'DOCTOR' => 'Médecin',
        'NURSE' => 'Infirmier / Infirmière',
        'CASHIER' => 'Caissier / Caissière',
        'PHARMACIST' => 'Pharmacien',
        'LABORATORY_TECHNICIAN' => 'Technicien de laboratoire',
        'RADIOLOGIST' => 'Radiologue',
        'HR_MANAGER' => 'Responsable RH',
        'RECEPTIONIST' => 'Réceptionniste',
        'PATIENT' => 'Patient',
    ];

    protected $fillable = ['name', 'display_name', 'description'];

    protected $appends = ['display_name'];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function getDisplayNameAttribute($value): ?string
    {
        return self::FRENCH_LABELS[$this->attributes['name'] ?? ''] ?? $value;
    }
}
