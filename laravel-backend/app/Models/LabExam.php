<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LabExam extends Model
{
    use BelongsToClinic;
    protected $fillable = [
        'clinic_id', 'exam_type', 'name', 'reference_values', 'price', 'unit',
        'reference_min', 'reference_max', 'sample_type', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'reference_values' => 'array',
            'price' => 'float',
            'reference_min' => 'float',
            'reference_max' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function requests(): BelongsToMany
    {
        return $this->belongsToMany(LabRequest::class, 'lab_request_exams', 'lab_exam_id', 'lab_request_id');
    }

    /** LOW / NORMAL / HIGH for numeric results with a reference range, null otherwise. */
    public static function flagFor(string $value, ?float $min, ?float $max): ?string
    {
        $number = self::numericValue($value);
        if ($number === null || ($min === null && $max === null)) {
            return null;
        }

        return match (true) {
            $min !== null && $number < $min => 'LOW',
            $max !== null && $number > $max => 'HIGH',
            default => 'NORMAL',
        };
    }

    /** Reads "12,5", "12.5 g/dL" or "1 250" as a number; text results return null. */
    public static function numericValue(string $value): ?float
    {
        $normalized = str_replace([' ', "\u{202F}", "\u{00A0}"], '', trim($value));
        if (!preg_match('/^-?\d+(?:[.,]\d+)?/', $normalized, $match)) {
            return null;
        }

        return (float) str_replace(',', '.', $match[0]);
    }
}
