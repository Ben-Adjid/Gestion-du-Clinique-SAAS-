<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImagingReport extends Model
{
    protected $fillable = ['request_id', 'radiologist_id', 'report_text', 'signed_at'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ImagingRequest::class, 'request_id');
    }

    public function radiologist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'radiologist_id');
    }
}
