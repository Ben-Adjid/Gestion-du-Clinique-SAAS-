<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabSample extends Model
{
    protected $fillable = ['request_id', 'sample_type', 'collected_at', 'collected_by'];

    protected function casts(): array
    {
        return ['collected_at' => 'datetime'];
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
