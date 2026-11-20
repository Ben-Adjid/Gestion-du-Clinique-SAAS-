<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\ImagingRequest;
use App\Models\Invoice;
use App\Models\LabRequest;
use App\Models\MedicalCertificate;
use App\Models\Patient;
use App\Models\Prescription;
use App\Services\PatientRights;
use Illuminate\Http\Request;

/**
 * Patient portal: everything is read from the patient record linked to the account,
 * and only finalised documents (signed, validated) are ever shown.
 */
class PortalController extends Controller
{
    private const CLINIC = 'clinic:id,name,city,country,admin_phone,admin_email';

    public function summary(Request $request)
    {
        $patient = $this->patient($request);

        $openInvoices = Invoice::query()->where('patient_id', $patient->id)->whereIn('status', ['ISSUED', 'OVERDUE'])
            ->withSum(['payments as paid' => fn ($query) => $query->where('status', 'CONFIRMED')], 'amount')->get();

        return response()->json(['data' => [
            'patient' => $patient->only(['id', 'first_name', 'last_name', 'patient_unique_id', 'date_of_birth', 'blood_group', 'phone', 'email']),
            'clinic' => $patient->clinic()->first(['id', 'name', 'city', 'admin_phone']),
            'upcoming_appointments' => Appointment::query()->where('patient_id', $patient->id)->where('status', 'SCHEDULED')
                ->where('appointment_datetime', '>=', now()->startOfDay())->with('doctor:id,name')->orderBy('appointment_datetime')->limit(5)->get(),
            'pending_requests' => \App\Models\AppointmentRequest::query()->where('patient_id', $patient->id)->where('status', 'PENDING')->count(),
            'balance_due' => round($openInvoices->sum(fn ($invoice) => (float) $invoice->total_amount - (float) ($invoice->paid ?? 0))),
            'counts' => [
                'consultations' => Consultation::query()->where('patient_id', $patient->id)->where('status', 'SIGNED')->count(),
                'prescriptions' => Prescription::query()->whereHas('consultation', fn ($query) => $query->where('patient_id', $patient->id))->whereIn('status', ['SIGNED', 'DISPENSED'])->count(),
                'lab' => LabRequest::query()->where('patient_id', $patient->id)->where('status', 'SIGNED')->count(),
                'imaging' => ImagingRequest::query()->where('patient_id', $patient->id)->where('status', 'SIGNED')->count(),
                'certificates' => MedicalCertificate::query()->where('patient_id', $patient->id)->whereNull('cancelled_at')->count(),
            ],
        ]]);
    }

    /** All finalised documents, most recent first. */
    public function documents(Request $request)
    {
        $patient = $this->patient($request);
        $documents = collect();

        Consultation::query()->where('patient_id', $patient->id)->where('status', 'SIGNED')->with(['doctor:id,name', 'diagnostics'])->latest('consultation_datetime')->limit(50)->get()
            ->each(fn ($consultation) => $documents->push([
                'type' => 'CONSULTATION', 'id' => $consultation->id, 'date' => $consultation->consultation_datetime,
                'title' => 'Compte rendu de consultation', 'author' => $consultation->doctor?->name,
                'summary' => $consultation->diagnostics->firstWhere('is_primary', true)?->cim10_description,
            ]));
        Prescription::query()->whereHas('consultation', fn ($query) => $query->where('patient_id', $patient->id))->whereIn('status', ['SIGNED', 'DISPENSED'])
            ->with(['doctor:id,name', 'items.medication:id,name'])->latest('prescribed_at')->limit(50)->get()
            ->each(fn ($prescription) => $documents->push([
                'type' => 'PRESCRIPTION', 'id' => $prescription->id, 'date' => $prescription->prescribed_at,
                'title' => 'Ordonnance', 'author' => $prescription->doctor?->name,
                'summary' => $prescription->items->pluck('medication.name')->implode(', '),
            ]));
        LabRequest::query()->where('patient_id', $patient->id)->where('status', 'SIGNED')->with(['doctor:id,name', 'exams:id,name', 'results'])->latest('requested_at')->limit(50)->get()
            ->each(fn ($labRequest) => $documents->push([
                'type' => 'LAB', 'id' => $labRequest->id, 'date' => $labRequest->results->max('validated_at') ?? $labRequest->requested_at,
                'title' => 'Résultats d’analyses', 'author' => $labRequest->doctor?->name,
                'summary' => $labRequest->exams->pluck('name')->implode(', '),
                'abnormal' => $labRequest->results->whereIn('flag', ['LOW', 'HIGH', 'ABNORMAL'])->count(),
            ]));
        ImagingRequest::query()->where('patient_id', $patient->id)->where('status', 'SIGNED')->with(['doctor:id,name', 'exam:id,name', 'reports:id,request_id,signed_at'])->latest('requested_at')->limit(50)->get()
            ->each(fn ($imaging) => $documents->push([
                'type' => 'IMAGING', 'id' => $imaging->id, 'date' => $imaging->reports->max('signed_at') ?? $imaging->requested_at,
                'title' => 'Compte rendu d’imagerie', 'author' => $imaging->doctor?->name, 'summary' => $imaging->exam?->name,
            ]));
        MedicalCertificate::query()->where('patient_id', $patient->id)->whereNull('cancelled_at')->with('doctor:id,name')->latest('issued_at')->limit(50)->get()
            ->each(fn ($certificate) => $documents->push([
                'type' => 'CERTIFICATE', 'id' => $certificate->id, 'date' => $certificate->issued_at,
                'title' => $certificate->type_label, 'author' => $certificate->doctor?->name,
                'summary' => $certificate->days ? "{$certificate->days} jour(s)" : null,
            ]));

        return response()->json(['data' => $documents->sortByDesc(fn (array $document) => (string) $document['date'])->values()]);
    }

    public function lab(Request $request, LabRequest $labRequest)
    {
        $patient = $this->patient($request);
        if ((int) $labRequest->patient_id !== (int) $patient->id || $labRequest->status !== 'SIGNED') {
            return response()->json(['message' => 'Document introuvable.'], 404);
        }

        return response()->json(['data' => $labRequest->load([
            'patient:id,first_name,last_name,patient_unique_id,date_of_birth,gender', 'doctor:id,name',
            'exams:id,exam_type,name,unit,reference_min,reference_max', 'results.exam:id,name', 'results.validator:id,name',
            'samples', self::CLINIC,
        ])]);
    }

    public function imaging(Request $request, ImagingRequest $imagingRequest)
    {
        $patient = $this->patient($request);
        if ((int) $imagingRequest->patient_id !== (int) $patient->id || $imagingRequest->status !== 'SIGNED') {
            return response()->json(['message' => 'Document introuvable.'], 404);
        }

        return response()->json(['data' => $imagingRequest->load([
            'patient:id,first_name,last_name,patient_unique_id,date_of_birth,gender', 'doctor:id,name', 'exam:id,exam_type,name',
            'reports.radiologist:id,name', 'files:id,request_id,file_type,file_name,file_size,uploaded_at', self::CLINIC,
        ])]);
    }

    public function invoices(Request $request)
    {
        $patient = $this->patient($request);
        $invoices = Invoice::query()->where('patient_id', $patient->id)->where('status', '!=', 'DRAFT')
            ->with(['items:id,invoice_id,description,amount', 'payments' => fn ($query) => $query->where('status', 'CONFIRMED')->select('id', 'invoice_id', 'amount', 'payment_method', 'receipt_number', 'payment_datetime', 'status')->latest('payment_datetime')])
            ->withSum(['payments as paid_amount' => fn ($query) => $query->where('status', 'CONFIRMED')], 'amount')
            ->latest()->paginate(20);
        $invoices->getCollection()->each(function (Invoice $invoice) {
            if ($invoice->status === 'ISSUED' && $invoice->due_date?->isPast()) {
                $invoice->setAttribute('status', 'OVERDUE');
            }
            $invoice->setAttribute('paid_amount', round((float) ($invoice->paid_amount ?? 0), 2));
            $invoice->setAttribute('outstanding_amount', $invoice->status === 'CANCELLED' ? 0 : max(0, round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2)));
        });

        return response()->json($invoices);
    }

    /** Official receipt of one of the patient's own confirmed payments (PDF). */
    public function receipt(Request $request, \App\Models\Payment $payment)
    {
        $patient = $this->patient($request);
        if ((int) $payment->invoice?->patient_id !== (int) $patient->id || $payment->status !== 'CONFIRMED') {
            return response()->json(['message' => 'Quittance introuvable.'], 404);
        }

        return response(\App\Services\Receipts\ReceiptPdf::render($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$payment->receipt_number.'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function invoice(Request $request, Invoice $invoice)
    {
        $patient = $this->patient($request);
        if ((int) $invoice->patient_id !== (int) $patient->id || $invoice->status === 'DRAFT') {
            return response()->json(['message' => 'Facture introuvable.'], 404);
        }
        $invoice->load(['patient:id,first_name,last_name,patient_unique_id', 'items', 'payments' => fn ($query) => $query->where('status', 'CONFIRMED'), 'payments.receivedBy:id,name', self::CLINIC])
            ->loadSum(['payments as paid_amount' => fn ($query) => $query->where('status', 'CONFIRMED')], 'amount');
        $invoice->setAttribute('paid_amount', round((float) ($invoice->paid_amount ?? 0), 2));
        $invoice->setAttribute('outstanding_amount', $invoice->status === 'CANCELLED' ? 0 : max(0, round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2)));

        return response()->json(['data' => $invoice]);
    }

    private function patient(Request $request): Patient
    {
        $patient = Patient::query()->where('user_id', $request->user()->id)->first();
        abort_if(!$patient, 404, 'Aucun dossier patient n’est lié à votre compte.');

        return $patient;
    }

    /** Consents and the rights the patient holds on the record. */
    public function rights(Request $request)
    {
        $patient = $this->patient($request);

        return response()->json(['data' => ['consents' => app(PatientRights::class)->consents($patient)]]);
    }

    public function updateConsents(Request $request)
    {
        $patient = $this->patient($request);
        if ($patient->anonymized_at !== null) {
            return response()->json(['message' => 'Ce dossier a été anonymisé.'], 422);
        }
        $validated = $request->validate(['consents' => ['required', 'array']]);
        app(PatientRights::class)->updateConsents($patient, PatientRightsController::validConsents($validated['consents']), $request->user()->id);

        return response()->json(['data' => ['consents' => app(PatientRights::class)->consents($patient)]]);
    }

    /** Copy of everything the clinic holds about the patient (right of access and portability). */
    public function export(Request $request)
    {
        $patient = $this->patient($request);

        return response()->json(app(PatientRights::class)->export($patient), 200, [
            'Content-Disposition' => 'attachment; filename="mon-dossier-'.$patient->patient_unique_id.'.json"',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
