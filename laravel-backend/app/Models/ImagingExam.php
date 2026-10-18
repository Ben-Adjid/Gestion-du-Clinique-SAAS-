<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImagingExam extends Model
{
    use BelongsToClinic;
    protected $fillable = ['clinic_id', 'exam_type', 'name', 'price', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'float', 'is_active' => 'boolean'];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ImagingRequest::class, 'exam_id');
    }
}
