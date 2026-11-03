<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\Patient;

/**
 * Patient file numbers: CODE-YEAR-000001, sequential per clinic and year, never reused
 * (archived files are counted). Must run inside a transaction: the clinic row is locked
 * so that concurrent registrations or imports cannot take the same number.
 */
final class PatientNumbering
{
    /** @return list<string> */
    public static function reserve(int $clinicId, int $count = 1): array
    {
        $clinic = Clinic::query()->whereKey($clinicId)->lockForUpdate()->firstOrFail();
        $prefix = "{$clinic->code}-".now()->year.'-';
        $last = Patient::withTrashed()
            ->withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->where('patient_unique_id', 'like', $prefix.'%')
            ->pluck('patient_unique_id')
            ->map(fn (string $id) => (int) substr($id, strlen($prefix)))
            ->max() ?? 0;

        return array_map(
            fn (int $sequence) => $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            range($last + 1, $last + $count),
        );
    }
}
