<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalSignature extends Model
{
    protected $fillable = ['consultation_id', 'user_id', 'signature_hash', 'signed_at', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }
}
