<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationDiagnostic extends Model
{
    protected $table = 'consultations_diagnostics';

    protected $fillable = [
        'consultation_id',
        'cim10_code',
        'cim10_description',
        'is_primary',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'notes' => 'encrypted',
            'is_primary' => 'boolean',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }
}
