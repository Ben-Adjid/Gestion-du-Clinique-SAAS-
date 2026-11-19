<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationSection extends Model
{
    protected $fillable = ['consultation_id', 'section_number', 'title', 'content'];

    /** Clinical text is encrypted at rest (APP_KEY). */
    protected $casts = ['content' => 'encrypted'];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }
}
