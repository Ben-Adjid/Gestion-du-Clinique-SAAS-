<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationSection extends Model
{
    protected $fillable = ['consultation_id', 'section_number', 'title', 'content'];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }
}
