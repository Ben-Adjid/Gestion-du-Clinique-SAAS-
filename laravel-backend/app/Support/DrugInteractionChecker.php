<?php

namespace App\Support;

use App\Models\DrugInteraction;
use App\Models\PrescriptionItem;
use Illuminate\Support\Collection;

final class DrugInteractionChecker
{
    public const ACTIVE_TREATMENT_DAYS = 30;

    /**
     * @param  list<int>  $medicationIds  medications of the prescription being written
     * @return Collection<int, array{severity: string, description: string, drugs: list<string>, with_current_treatment: bool}>
     */
    public static function forPatient(int $patientId, array $medicationIds): Collection
    {
        $newIds = array_values(array_unique(array_map('intval', $medicationIds)));
        if ($newIds === []) {
            return collect();
        }

        $currentIds = PrescriptionItem::query()
            ->whereHas('prescription', fn ($prescriptions) => $prescriptions
                ->whereIn('status', ['SIGNED', 'DISPENSED'])
                ->where('prescribed_at', '>=', now()->subDays(self::ACTIVE_TREATMENT_DAYS))
                ->whereHas('consultation', fn ($consultations) => $consultations->where('patient_id', $patientId)))
            ->pluck('medication_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $allIds = array_values(array_unique([...$newIds, ...$currentIds]));

        return DrugInteraction::query()
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair->whereIn('drug_id_1', $newIds)->whereIn('drug_id_2', $allIds))
                ->orWhere(fn ($pair) => $pair->whereIn('drug_id_2', $newIds)->whereIn('drug_id_1', $allIds)))
            ->with(['firstDrug:id,name', 'secondDrug:id,name'])
            ->get()
            ->map(fn (DrugInteraction $interaction) => [
                'severity' => $interaction->severity,
                'description' => $interaction->description,
                'drugs' => [$interaction->firstDrug?->name, $interaction->secondDrug?->name],
                'with_current_treatment' => !(in_array((int) $interaction->drug_id_1, $newIds, true) && in_array((int) $interaction->drug_id_2, $newIds, true)),
            ])
            ->sortBy(fn (array $interaction) => ['HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2][$interaction['severity']])
            ->values();
    }
}
