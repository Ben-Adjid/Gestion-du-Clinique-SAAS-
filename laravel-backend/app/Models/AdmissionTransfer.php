<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Traceable move of an admitted patient from one bed to another. */
class AdmissionTransfer extends Model
{
    use BelongsToClinic;

    protected $fillable = ['clinic_id', 'admission_id', 'from_bed_id', 'to_bed_id', 'reason', 'transferred_by', 'transferred_at'];

    protected $casts = ['transferred_at' => 'datetime'];

    public function fromBed(): BelongsTo { return $this->belongsTo(Bed::class, 'from_bed_id'); }
    public function toBed(): BelongsTo { return $this->belongsTo(Bed::class, 'to_bed_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'transferred_by'); }
}
