<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\ImagingRequest;
use App\Models\Invoice;
use App\Models\LabRequest;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\VitalSign;
use App\Support\ModuleAccess;
use Illuminate\Http\Request;

class PatientTimelineController extends Controller
{
    private const LIMIT = 100;

    private const STATUS_LABELS = [
        'DRAFT' => 'Brouillon', 'IN_PROGRESS' => 'En cours', 'COMPLETED' => 'Terminée', 'SIGNED' => 'Signée',
        'DISPENSED' => 'Délivrée', 'CANCELLED' => 'Annulée', 'PENDING' => 'En attente', 'RECEIVED' => 'Reçue',
        'READY' => 'Résultats prêts', 'ISSUED' => 'Émise', 'PAID' => 'Payée', 'OVERDUE' => 'Impayée',
    ];

    public function show(Request $request, Patient $patient)
    {
        $current = $request->attributes->get('clinic_id');
        if ($current !== null && (int) $current !== (int) $patient->clinic_id) {
            return response()->json(['message' => 'Patient not found.'], 404);
        }

        $events = collect()
            ->merge(Consultation::query()->where('patient_id', $patient->id)->with(['doctor:id,name', 'diagnostics'])->latest('consultation_datetime')->limit(self::LIMIT)->get()
                ->map(fn (Consultation $consultation) => $this->event('consultation', $consultation->id, $consultation->consultation_datetime,
                    'Consultation · '.($consultation->doctor?->name ?? 'Médecin'),
                    $consultation->diagnostics->firstWhere('is_primary', true)?->cim10_description,
                    $consultation->status)))
            ->merge(Prescription::query()->whereHas('consultation', fn ($query) => $query->where('patient_id', $patient->id))->withCount('items')->with('doctor:id,name')->latest('prescribed_at')->limit(self::LIMIT)->get()
                ->map(fn (Prescription $prescription) => $this->event('prescription', $prescription->id, $prescription->prescribed_at ?? $prescription->created_at,
                    'Ordonnance · '.($prescription->doctor?->name ?? 'Médecin'),
                    $prescription->items_count.' médicament(s)',
                    $prescription->status)))
            ->merge(LabRequest::query()->where('patient_id', $patient->id)->with('exams')->latest()->limit(self::LIMIT)->get()
                ->map(fn (LabRequest $request) => $this->event('lab', $request->id, $request->created_at,
                    'Analyse de laboratoire',
                    $request->exams->pluck('name')->join(', ') ?: null,
                    $request->status)))
            ->merge(ImagingRequest::query()->where('patient_id', $patient->id)->with('exam')->latest()->limit(self::LIMIT)->get()
                ->map(fn (ImagingRequest $request) => $this->event('imaging', $request->id, $request->created_at,
                    'Imagerie',
                    $request->exam?->name,
                    $request->status)))
            ->merge(VitalSign::query()->where('patient_id', $patient->id)->with('recorder:id,name')->latest('recorded_at')->limit(self::LIMIT)->get()
                ->map(fn (VitalSign $vital) => $this->event('vitals', $vital->id, $vital->recorded_at,
                    'Signes vitaux · '.($vital->recorder?->name ?? 'Soignant'),
                    $this->vitalSummary($vital),
                    null,
                    $vital->alerts)));

        if (in_array('read:invoice', ModuleAccess::effectivePermissions($request->user()), true)) {
            $events = $events->merge(Invoice::query()->where('patient_id', $patient->id)->latest()->limit(self::LIMIT)->get()
                ->map(fn (Invoice $invoice) => $this->event('invoice', $invoice->id, $invoice->issued_at ?? $invoice->created_at,
                    'Facture '.$invoice->invoice_number,
                    number_format((float) $invoice->total_amount, 0, ',', ' ').' FCFA',
                    $invoice->status)));
        }

        return response()->json([
            'data' => $events->sortByDesc('date')->values()->take(self::LIMIT * 2),
        ]);
    }

    private function event(string $type, int $id, $date, string $title, ?string $detail, ?string $status, array $alerts = []): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'date' => $date?->toIso8601String(),
            'title' => $title,
            'detail' => $detail,
            'status' => $status === null ? null : (self::STATUS_LABELS[$status] ?? $status),
            'alerts' => $alerts,
        ];
    }

    private function vitalSummary(VitalSign $vital): string
    {
        return collect([
            $vital->systolic_bp && $vital->diastolic_bp ? "TA {$vital->systolic_bp}/{$vital->diastolic_bp}" : null,
            $vital->heart_rate ? "FC {$vital->heart_rate}" : null,
            $vital->temperature ? "T° {$vital->temperature}" : null,
            $vital->spo2 ? "SpO2 {$vital->spo2}%" : null,
            $vital->respiratory_rate ? "FR {$vital->respiratory_rate}" : null,
            $vital->weight_kg ? "{$vital->weight_kg} kg" : null,
        ])->filter()->join(' · ');
    }
}
