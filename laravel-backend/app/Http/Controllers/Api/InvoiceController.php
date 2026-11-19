<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashClosing;
use App\Models\Clinic;
use App\Models\Invoice;
use App\Models\Payment;
use App\Jobs\SendReceiptByEmail;
use App\Services\BillableActs;
use App\Services\Receipts\ReceiptNumbering;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    private const RELATIONS = ['patient:id,first_name,last_name,patient_unique_id,phone'];

    public function index(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['data' => []]);
        }
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(['UNPAID', ...array_keys(Invoice::STATUS_LABELS)])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'patient_id' => ['sometimes', 'nullable', 'integer'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $invoices = Invoice::query()
            ->with(self::RELATIONS)
            ->withSum(['payments as paid_amount' => fn ($query) => $query->where('status', 'CONFIRMED')], 'amount')
            ->where('clinic_id', $clinicId)
            ->when(($filters['status'] ?? null) === 'UNPAID', fn ($query) => $query->whereIn('status', ['ISSUED', 'OVERDUE']))
            ->when(($filters['status'] ?? null) === 'OVERDUE', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('status', 'OVERDUE')
                ->orWhere(fn ($issued) => $issued->where('status', 'ISSUED')->where('due_date', '<', now()))))
            ->when(isset($filters['status']) && !in_array($filters['status'], ['UNPAID', 'OVERDUE'], true), fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['patient_id'] ?? null, fn ($query, $patientId) => $query->where('patient_id', $patientId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', "{$from} 00:00:00"))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', "{$to} 23:59:59"))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhereHas('patient', fn ($patients) => $patients
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('patient_unique_id', 'like', "%{$search}%"))))
            ->latest()
            ->paginate(25);
        $invoices->getCollection()->each(fn (Invoice $invoice) => $this->decorate($invoice));

        $open = Invoice::query()->where('clinic_id', $clinicId)->whereIn('status', ['ISSUED', 'OVERDUE']);
        $paidOnOpen = (float) Payment::query()
            ->where('clinic_id', $clinicId)
            ->where('status', 'CONFIRMED')
            ->whereIn('invoice_id', (clone $open)->select('id'))
            ->sum('amount');

        return response()->json([...$invoices->toArray(), 'summary' => [
            'outstanding_amount' => max(0, round((float) (clone $open)->sum('total_amount') - $paidOnOpen, 2)),
            'open_count' => (clone $open)->count(),
            'overdue_count' => (clone $open)->where(fn ($query) => $query->where('status', 'OVERDUE')->orWhere('due_date', '<', now()))->count(),
            'collected_today' => (float) Payment::query()->where('clinic_id', $clinicId)->where('status', 'CONFIRMED')->where('payment_datetime', '>=', today())->sum('amount'),
            'paid_count' => Invoice::query()->where('clinic_id', $clinicId)->where('status', 'PAID')->count(),
            'total_issued_amount' => (float) Invoice::query()->where('clinic_id', $clinicId)->whereIn('status', ['ISSUED', 'OVERDUE', 'PAID'])->sum('total_amount'),
        ]]);
    }

    /** Patients with services not invoiced yet, or the detail for one patient. */
    public function pending(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate(['patient_id' => ['sometimes', 'nullable', 'integer', Rule::exists('patients', 'id')->where('clinic_id', $clinicId)]]);
        $acts = BillableActs::pending($clinicId, $validated['patient_id'] ?? null);

        if (isset($validated['patient_id'])) {
            return response()->json(['data' => $acts]);
        }
        $patients = DB::table('patients')
            ->whereIn('id', $acts->pluck('patient_id')->unique())
            ->get(['id', 'first_name', 'last_name', 'patient_unique_id'])
            ->keyBy('id');

        return response()->json(['data' => $acts->groupBy('patient_id')->map(fn ($patientActs, $patientId) => [
            'patient' => $patients->get($patientId),
            'acts_count' => $patientActs->count(),
            'total' => round($patientActs->sum('amount'), 2),
            'last_date' => $patientActs->max('date'),
            'kinds' => $patientActs->pluck('kind')->unique()->values(),
        ])->sortByDesc('last_date')->values()]);
    }

    public function store(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['message' => 'Select a clinic before creating invoices.'], 422);
        }

        // Single-line invoices (description + subtotal) are still accepted.
        if (!$request->has('items') && $request->has('subtotal')) {
            $request->merge(['items' => [['description' => $request->input('description'), 'amount' => $request->input('subtotal')]]]);
        }
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where('clinic_id', $clinicId)->whereNull('deleted_at')],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'items.*.reference_id' => ['nullable', 'string', 'max:50', 'distinct'],
            'tax_rate' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'discount' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99'],
            'due_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'status' => ['sometimes', 'in:DRAFT,ISSUED'],
        ], ['items.required' => 'Ajoutez au moins une prestation.']);

        // References must be unbilled services of this patient.
        $references = collect($validated['items'])->pluck('reference_id')->filter()->values();
        if ($references->isNotEmpty()) {
            $pending = BillableActs::pending($clinicId, (int) $validated['patient_id'], 3650)->pluck('reference')->all();
            $invalid = $references->reject(fn (string $reference) => in_array($reference, $pending, true));
            if ($invalid->isNotEmpty()) {
                return response()->json([
                    'message' => 'Certaines prestations sont déjà facturées ou n’appartiennent pas à ce patient : '.$invalid->implode(', ').'.',
                ], 422);
            }
        }

        $invoice = DB::transaction(function () use ($clinicId, $validated, $request): Invoice {
            $clinic = Clinic::query()->whereKey($clinicId)->lockForUpdate()->firstOrFail();
            $year = now()->year;
            $prefix = "{$clinic->code}-{$year}-";
            $last = Invoice::withTrashed()
                ->where('clinic_id', $clinicId)
                ->where('invoice_number', 'like', $prefix.'%')
                ->pluck('invoice_number')
                ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
                ->max() ?? 0;
            $taxRate = $validated['tax_rate'] ?? 0;
            $subtotal = round(collect($validated['items'])->sum(fn (array $item) => (float) $item['amount']), 2);
            $discount = min(round((float) ($validated['discount'] ?? 0), 2), $subtotal);
            $taxAmount = round(($subtotal - $discount) * $taxRate / 100, 2);
            $status = $validated['status'] ?? 'ISSUED';
            $dueDays = (int) BillableActs::tariffs($clinicId)['invoice_due_days'];

            $invoice = Invoice::create([
                'clinic_id' => $clinicId,
                'patient_id' => $validated['patient_id'],
                'created_by' => $request->user()->id,
                'invoice_number' => $prefix.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'tax_rate' => $taxRate,
                'total_amount' => round($subtotal - $discount + $taxAmount, 2),
                'status' => $status,
                'issued_at' => $status === 'ISSUED' ? now() : null,
                'due_date' => isset($validated['due_date']) ? Carbon::parse($validated['due_date'])->endOfDay() : now()->addDays($dueDays)->endOfDay(),
            ]);
            foreach ($validated['items'] as $item) {
                $invoice->items()->create([
                    'description' => $item['description'],
                    'reference_id' => $item['reference_id'] ?? null,
                    'amount' => round((float) $item['amount'], 2),
                    'tax_amount' => round((float) $item['amount'] * $taxRate / 100, 2),
                ]);
            }
            if ($discount > 0) {
                $invoice->items()->create(['description' => 'Remise', 'amount' => -$discount, 'tax_amount' => 0]);
            }

            return $invoice;
        });

        return response()->json(['data' => $this->decorate($invoice->load([...self::RELATIONS, 'items'])), 'message' => "Facture {$invoice->invoice_number} émise."], 201);
    }

    public function show(Request $request, Invoice $invoice)
    {
        if (!$this->canAccessInvoice($request, $invoice)) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }
        $invoice->load([
            ...self::RELATIONS,
            'items',
            'payments' => fn ($query) => $query->orderBy('payment_datetime'),
            'payments.receivedBy:id,name',
            'creator:id,name',
            'clinic:id,name,city,country,admin_phone,admin_email',
        ])->loadSum(['payments as paid_amount' => fn ($query) => $query->where('status', 'CONFIRMED')], 'amount');

        return response()->json(['data' => $this->decorate($invoice)]);
    }

    public function update(Request $request, Invoice $invoice)
    {
        if (!$this->canAccessInvoice($request, $invoice)) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }
        if ($invoice->status !== 'DRAFT') {
            return response()->json(['message' => 'Only draft invoices can be edited.'], 422);
        }
        $validated = $request->validate([
            'due_date' => ['sometimes', 'required', 'date', 'after_or_equal:today'],
            'status' => ['sometimes', 'in:DRAFT,ISSUED'],
        ]);
        $changes = [];
        if (isset($validated['due_date'])) {
            $changes['due_date'] = Carbon::parse($validated['due_date'])->endOfDay();
        }
        if (($validated['status'] ?? null) === 'ISSUED') {
            $changes['status'] = 'ISSUED';
            $changes['issued_at'] = now();
        }
        $invoice->update($changes);

        return response()->json(['data' => $this->decorate($invoice->fresh()->load([...self::RELATIONS, 'items']))]);
    }

    /** An issued invoice is never deleted: it is cancelled with a reason, and its services become billable again. */
    public function cancel(Request $request, Invoice $invoice)
    {
        if (!$this->canAccessInvoice($request, $invoice)) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        if (!in_array($invoice->status, ['DRAFT', 'ISSUED', 'OVERDUE'], true)) {
            return response()->json(['message' => 'Seule une facture non payée peut être annulée.'], 422);
        }
        if ($invoice->payments()->where('status', 'CONFIRMED')->exists()) {
            return response()->json(['message' => 'Cette facture a déjà reçu un paiement : elle ne peut pas être annulée.'], 422);
        }
        $invoice->update([
            'status' => 'CANCELLED',
            'cancelled_at' => now(),
            'cancellation_reason' => $validated['reason'],
            'cancelled_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->decorate($invoice->fresh()->load([...self::RELATIONS, 'items'])), 'message' => 'Facture annulée.']);
    }

    public function recordPayment(Request $request, Invoice $invoice)
    {
        if (!$this->canAccessInvoice($request, $invoice)) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999.99'],
            'payment_method' => ['required', Rule::in(array_keys(Payment::METHOD_LABELS))],
            'payment_reference' => ['nullable', 'required_if:payment_method,'.implode(',', Payment::METHODS_WITH_REFERENCE), 'string', 'max:255'],
            'insurer_name' => ['nullable', 'required_if:payment_method,INSURANCE', 'string', 'max:120'],
        ], [
            'payment_reference.required_if' => 'La référence de la transaction est obligatoire pour ce mode de paiement.',
            'insurer_name.required_if' => 'Indiquez l’assureur qui prend en charge les soins.',
        ]);

        $closed = CashClosing::query()
            ->where('clinic_id', $invoice->clinic_id)
            ->where('cashier_id', $request->user()->id)
            ->whereDate('business_date', today())
            ->exists();
        if ($closed) {
            return response()->json(['message' => 'Votre caisse du jour est clôturée : aucun encaissement n’est plus possible aujourd’hui.'], 422);
        }

        $payment = DB::transaction(function () use ($invoice, $request, $validated): Payment {
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (!in_array($lockedInvoice->status, ['ISSUED', 'OVERDUE'], true)) {
                abort(422, 'Only issued invoices can receive payments.');
            }

            $paidAmount = (float) $lockedInvoice->payments()->where('status', 'CONFIRMED')->sum('amount');
            $remaining = round((float) $lockedInvoice->total_amount - $paidAmount, 2);
            $amount = round((float) $validated['amount'], 2);
            if ($amount > $remaining) {
                abort(422, 'Payment exceeds the remaining invoice balance.');
            }

            $payment = Payment::create([
                'clinic_id' => $lockedInvoice->clinic_id,
                'invoice_id' => $lockedInvoice->id,
                'amount' => $amount,
                'payment_method' => $validated['payment_method'],
                'payment_reference' => $validated['payment_reference'] ?? null,
                'insurer_name' => $validated['insurer_name'] ?? null,
                'receipt_number' => ReceiptNumbering::next($lockedInvoice->clinic_id),
                'payment_datetime' => now(),
                'received_by' => $request->user()->id,
                'status' => 'CONFIRMED',
            ]);

            if (round($paidAmount + $amount, 2) >= (float) $lockedInvoice->total_amount) {
                $lockedInvoice->update(['status' => 'PAID', 'paid_at' => now()]);
            }

            return $payment;
        });

        // The receipt is e-mailed in the background: a mail problem never blocks the cashier.
        SendReceiptByEmail::dispatch($payment->id);

        return response()->json([
            'data' => $payment,
            'invoice' => $this->decorate($invoice->fresh()->load(['payments', ...self::RELATIONS])->loadSum(['payments as paid_amount' => fn ($query) => $query->where('status', 'CONFIRMED')], 'amount')),
            'message' => 'Paiement enregistré.',
        ], 201);
    }

    public function destroy(Request $request, Invoice $invoice)
    {
        if (!$this->canAccessInvoice($request, $invoice)) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }
        if ($invoice->status !== 'DRAFT') {
            return response()->json(['message' => 'Only draft invoices can be deleted.'], 422);
        }
        $invoice->delete();

        return response()->noContent();
    }

    private function decorate(Invoice $invoice): Invoice
    {
        if ($invoice->status === 'ISSUED' && $invoice->due_date?->isPast()) {
            $invoice->setAttribute('status', 'OVERDUE');
        }
        $paid = match (true) {
            $invoice->paid_amount !== null => (float) $invoice->paid_amount,
            $invoice->relationLoaded('payments') => (float) $invoice->payments->where('status', 'CONFIRMED')->sum('amount'),
            default => 0.0,
        };
        $invoice->setAttribute('paid_amount', round($paid, 2));
        $invoice->setAttribute('outstanding_amount', $invoice->status === 'CANCELLED' ? 0 : max(0, round((float) $invoice->total_amount - $paid, 2)));
        $invoice->setAttribute('status_label', Invoice::STATUS_LABELS[$invoice->status] ?? $invoice->status);

        return $invoice;
    }

    private function canAccessInvoice(Request $request, Invoice $invoice): bool
    {
        return (int) $invoice->clinic_id === (int) $this->clinicId($request);
    }

    private function clinicId(Request $request): ?int
    {
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;

        return $clinicId === null ? null : (int) $clinicId;
    }
}
