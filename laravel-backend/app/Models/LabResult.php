<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabResult extends Model
{
    protected $fillable = [
        'request_id',
        'exam_id',
        'result_value',
        'unit',
        'reference_min',
        'reference_max',
        'flag',
        'comment',
        'entered_by',
        'status',
        'validator_id',
        'validated_at',
    ];

    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
            'reference_min' => 'float',
            'reference_max' => 'float',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LabRequest::class, 'request_id');
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(LabExam::class);
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_id');
    }
}
