<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Informed consent of the patient (or his guardian) before an operation. One per operation. */
class SurgicalConsent extends Model
{
    use BelongsToClinic;

    public const RELATIONS = ['PATIENT', 'TUTEUR', 'PROCHE'];

    protected $fillable = ['clinic_id', 'surgery_id', 'patient_id', 'signer_name', 'signer_relation', 'information_text', 'recorded_by', 'signed_at'];

    protected $casts = [
        'signed_at' => 'datetime',
        // What was explained to the patient is medical content: encrypted at rest.
        'information_text' => 'encrypted',
    ];

    public function surgery(): BelongsTo { return $this->belongsTo(Surgery::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
