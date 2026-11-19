<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prescription extends Model
{
    use BelongsToClinic;
    use SoftDeletes;

    protected $fillable = ['clinic_id', 'consultation_id', 'doctor_id', 'prescribed_at', 'status', 'notes'];

    protected function casts(): array
    {
        return ['prescribed_at' => 'datetime', 'notes' => 'encrypted'];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }
}
