<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImagingFile extends Model
{
    protected $fillable = ['request_id', 'file_type', 'file_path', 'file_name', 'file_size', 'uploaded_at', 'uploaded_by'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
