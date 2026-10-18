<?php

namespace App\Services;

use App\Models\Clinic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything a patient received at the clinic and that has not been invoiced yet:
 * consultations, laboratory tests, imaging and pharmacy sales. Each act carries an
 * invoice reference ("consultation:12", "lab:5"…) so it can never be billed twice.
 */
final class BillableActs
{
    public const DEFAULT_TARIFFS = [
        'consultation_general' => 10000,
        'consultation_specialist' => 15000,
        'invoice_due_days' => 3,
    ];

    public static function tariffs(int $clinicId): array
    {
        $settings = Clinic::query()->whereKey($clinicId)->value('settings');
        $settings = is_array($settings) ? $settings : (json_decode((string) $settings, true) ?: []);

        return [...self::DEFAULT_TARIFFS, ...($settings['tariffs'] ?? [])];
    }

    /** References already on a non-cancelled invoice of the clinic. */
    public static function billedReferences(int $clinicId, array $references): array
    {
        if ($references === []) {
            return [];
        }

        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.clinic_id', $clinicId)
            ->whereNull('invoices.deleted_at')
            ->where('invoices.status', '!=', 'CANCELLED')
            ->whereIn('invoice_items.reference_id', $references)
            ->pluck('invoice_items.reference_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array{reference: string, patient_id: int, kind: string, date: string, description: string, amount: float}>
     */
    public static function pending(int $clinicId, ?int $patientId = null, int $days = 90): Collection
    {
        $since = now()->subDays($days);
        $tariffs = self::tariffs($clinicId);
        $acts = collect();

        $consultations = DB::table('consultations as c')
            ->join('users as d', 'd.id', '=', 'c.doctor_id')
            ->where('c.clinic_id', $clinicId)
            ->whereNull('c.deleted_at')
            ->where('c.consultation_datetime', '>=', $since)
            ->when($patientId, fn ($query) => $query->where('c.patient_id', $patientId))
            ->get(['c.id', 'c.patient_id', 'c.consultation_datetime', 'd.name as doctor', 'd.metadata']);
        foreach ($consultations as $consultation) {
            $specialty = json_decode((string) $consultation->metadata, true)['specialty'] ?? null;
            $specialist = $specialty !== null && $specialty !== 'Médecine générale';
            $acts->push([
                'reference' => 'consultation:'.$consultation->id,
                'patient_id' => (int) $consultation->patient_id,
                'kind' => 'CONSULTATION',
                'date' => (string) $consultation->consultation_datetime,
                'description' => 'Consultation '.($specialist ? "spécialisée ({$specialty})" : 'de médecine générale').' — '.$consultation->doctor,
                'amount' => (float) $tariffs[$specialist ? 'consultation_specialist' : 'consultation_general'],
            ]);
        }

        $labRequests = DB::table('lab_requests as r')
            ->join('lab_request_exams as re', 're.lab_request_id', '=', 'r.id')
            ->join('lab_exams as e', 'e.id', '=', 're.lab_exam_id')
            ->where('r.clinic_id', $clinicId)
            ->where('r.requested_at', '>=', $since)
            ->when($patientId, fn ($query) => $query->where('r.patient_id', $patientId))
            ->get(['r.id', 'r.patient_id', 'r.requested_at', 'e.name', 'e.price'])
            ->groupBy('id');
        foreach ($labRequests as $requestId => $exams) {
            $acts->push([
                'reference' => 'lab:'.$requestId,
                'patient_id' => (int) $exams[0]->patient_id,
                'kind' => 'LAB',
                'date' => (string) $exams[0]->requested_at,
                'description' => 'Laboratoire : '.$exams->pluck('name')->implode(', '),
                'amount' => (float) $exams->sum('price'),
            ]);
        }

        $imaging = DB::table('imaging_requests as r')
            ->join('imaging_exams as e', 'e.id', '=', 'r.exam_id')
            ->where('r.clinic_id', $clinicId)
            ->where('r.requested_at', '>=', $since)
            ->when($patientId, fn ($query) => $query->where('r.patient_id', $patientId))
            ->get(['r.id', 'r.patient_id', 'r.requested_at', 'e.name', 'e.price']);
        foreach ($imaging as $request) {
            $acts->push([
                'reference' => 'imaging:'.$request->id,
                'patient_id' => (int) $request->patient_id,
                'kind' => 'IMAGING',
                'date' => (string) $request->requested_at,
                'description' => 'Imagerie : '.$request->name,
                'amount' => (float) $request->price,
            ]);
        }

        $sales = DB::table('pharmacy_sales')
            ->where('clinic_id', $clinicId)
            ->where('sold_at', '>=', $since)
            ->where('total_amount', '>', 0)
            ->when($patientId, fn ($query) => $query->where('patient_id', $patientId))
            ->get(['id', 'patient_id', 'sold_at', 'type', 'prescription_id', 'total_amount']);
        $saleItems = DB::table('pharmacy_sales_items as i')
            ->join('medications as m', 'm.id', '=', 'i.medication_id')
            ->whereIn('i.sale_id', $sales->pluck('id'))
            ->get(['i.sale_id', 'm.name', 'i.quantity'])
            ->groupBy('sale_id');
        foreach ($sales as $sale) {
            $acts->push([
                'reference' => 'pharmacy_sale:'.$sale->id,
                'patient_id' => (int) $sale->patient_id,
                'kind' => 'PHARMACY',
                'date' => (string) $sale->sold_at,
                'description' => 'Pharmacie : '.$saleItems->get($sale->id, collect())->map(fn ($item) => "{$item->name} × {$item->quantity}")->implode(', '),
                'amount' => (float) $sale->total_amount,
            ]);
        }

        $billed = array_flip(self::billedReferences($clinicId, $acts->pluck('reference')->all()));
        // Legacy invoices billed dispensed prescriptions as "prescription:ID".
        $legacyPrescriptions = array_flip(self::billedReferences($clinicId, $sales->whereNotNull('prescription_id')->map(fn ($sale) => 'prescription:'.$sale->prescription_id)->all()));

        return $acts
            ->reject(fn (array $act) => isset($billed[$act['reference']]))
            ->reject(function (array $act) use ($sales, $legacyPrescriptions) {
                if ($act['kind'] !== 'PHARMACY') {
                    return false;
                }
                $sale = $sales->firstWhere('id', (int) substr($act['reference'], 14));

                return $sale?->prescription_id && isset($legacyPrescriptions['prescription:'.$sale->prescription_id]);
            })
            ->sortBy('date')
            ->values();
    }
}
