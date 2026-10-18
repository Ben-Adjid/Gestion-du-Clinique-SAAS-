<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medication;
use App\Models\PharmacyInventory;
use App\Models\PharmacyLot;
use App\Models\Prescription;
use App\Services\PharmacyStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PharmacyController extends Controller
{
    public const ADJUSTMENT_REASONS = [
        'EXPIRED' => 'Produit périmé détruit',
        'DAMAGED' => 'Produit abîmé ou perdu',
        'INVENTORY' => 'Correction après inventaire',
        'RETURN' => 'Retour fournisseur',
    ];

    /** Stock by medication with its lots, low-stock and expiry alerts. */
    public function stock(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'alert' => ['sometimes', 'nullable', Rule::in(['LOW', 'EXPIRING', 'EXPIRED'])],
        ]);
        $soon = today()->addDays(60);
        $search = trim((string) ($filters['search'] ?? ''));

        $items = PharmacyInventory::query()
            ->where('clinic_id', $clinicId)
            ->with(['medication:id,name,dosage,form,manufacturer,price', 'lots' => fn ($query) => $query->where('quantity', '>', 0)->orderBy('expiration_date')])
            ->whereHas('medication', fn ($query) => $query
                ->when($search !== '', fn ($inner) => $inner->where('name', 'like', "%{$search}%")))
            ->get()
            ->map(function (PharmacyInventory $inventory) use ($soon) {
                $lots = $inventory->lots;
                $expired = (int) $lots->filter(fn (PharmacyLot $lot) => $lot->expiration_date->lt(today()))->sum('quantity');
                $expiring = (int) $lots->filter(fn (PharmacyLot $lot) => $lot->expiration_date->between(today(), $soon))->sum('quantity');
                $available = (int) $inventory->quantity - $expired;

                return [
                    ...$inventory->toArray(),
                    'available_quantity' => $available,
                    'expired_quantity' => $expired,
                    'expiring_quantity' => $expiring,
                    'next_expiration' => $lots->first(fn (PharmacyLot $lot) => !$lot->expiration_date->lt(today()))?->expiration_date?->toDateString(),
                    'is_low' => $available <= $inventory->min_threshold,
                ];
            })
            ->filter(fn (array $item) => match ($filters['alert'] ?? null) {
                'LOW' => $item['is_low'],
                'EXPIRING' => $item['expiring_quantity'] > 0,
                'EXPIRED' => $item['expired_quantity'] > 0,
                default => true,
            })
            ->sortBy(fn (array $item) => mb_strtolower($item['medication']['name']))
            ->values();

        $all = PharmacyInventory::query()->where('clinic_id', $clinicId)->with('lots')->get();

        return response()->json([
            'data' => $items,
            'summary' => [
                'references' => $all->count(),
                'low' => $all->filter(fn ($inventory) => ($inventory->quantity - $inventory->lots->filter(fn ($lot) => $lot->quantity > 0 && $lot->expiration_date->lt(today()))->sum('quantity')) <= $inventory->min_threshold)->count(),
                'expiring' => $all->filter(fn ($inventory) => $inventory->lots->contains(fn ($lot) => $lot->quantity > 0 && $lot->expiration_date->between(today(), $soon)))->count(),
                'expired' => $all->filter(fn ($inventory) => $inventory->lots->contains(fn ($lot) => $lot->quantity > 0 && $lot->expiration_date->lt(today())))->count(),
                'stock_value' => round($all->sum(fn ($inventory) => $inventory->quantity * (float) $inventory->unit_cost), 2),
            ],
        ]);
    }

    public function addMedication(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate([
            ...$this->medicationRules($clinicId),
            'min_threshold' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $medication = DB::transaction(function () use ($clinicId, $validated) {
            $medication = Medication::create([...collect($validated)->except('min_threshold')->all(), 'clinic_id' => $clinicId]);
            PharmacyInventory::create([
                'clinic_id' => $clinicId,
                'medication_id' => $medication->id,
                'quantity' => 0,
                'unit_cost' => 0,
                'selling_price' => $validated['price'],
                'min_threshold' => $validated['min_threshold'],
            ]);

            return $medication;
        });

        return response()->json(['data' => $medication, 'message' => 'Médicament ajouté au catalogue. Enregistrez une réception pour le mettre en stock.'], 201);
    }

    public function updateMedication(Request $request, Medication $medication)
    {
        $clinicId = $this->clinicId($request);
        if ((int) $medication->clinic_id !== $clinicId) {
            return response()->json(['message' => 'Médicament introuvable.'], 404);
        }
        $rules = collect($this->medicationRules($clinicId, $medication->id))->map(fn (array $rule) => ['sometimes', ...$rule])->all();
        $validated = $request->validate([...$rules, 'min_threshold' => ['sometimes', 'integer', 'min:0', 'max:1000000']]);

        DB::transaction(function () use ($medication, $validated) {
            $medication->update(collect($validated)->except('min_threshold')->all());
            $inventory = PharmacyInventory::where('medication_id', $medication->id)->firstOrFail();
            $inventory->update(array_filter([
                'selling_price' => $validated['price'] ?? null,
                'min_threshold' => $validated['min_threshold'] ?? null,
            ], fn ($value) => $value !== null));
        });

        return response()->json(['data' => $medication->fresh(), 'message' => 'Médicament mis à jour.']);
    }

    public function receive(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate([
            'medication_id' => ['required', 'integer', Rule::exists('medications', 'id')->where('clinic_id', $clinicId)->whereNull('deleted_at')],
            'lot_number' => ['required', 'string', 'max:50'],
            'manufacturing_date' => ['nullable', 'date', 'before_or_equal:today'],
            'expiration_date' => ['required', 'date_format:Y-m-d', 'after:today'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], [
            'expiration_date.after' => 'Un lot déjà périmé ne peut pas entrer en stock.',
        ]);

        $lot = DB::transaction(function () use ($validated, $clinicId, $request) {
            $inventory = PharmacyInventory::query()
                ->where('clinic_id', $clinicId)
                ->where('medication_id', $validated['medication_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $validated['lot_number'] = strtoupper(trim($validated['lot_number']));
            $validated['reason'] = 'Réception fournisseur'.(!empty($validated['reference']) ? ' ('.$validated['reference'].')' : '');

            return PharmacyStock::receive($inventory, $validated, $request->user()->id);
        });

        return response()->json(['data' => $lot, 'message' => "Réception enregistrée : lot {$lot->lot_number}."], 201);
    }

    public function adjust(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate([
            'lot_id' => ['required', 'integer', 'exists:pharmacy_lots,id'],
            'quantity_change' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', Rule::in(array_keys(self::ADJUSTMENT_REASONS))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $lot = PharmacyLot::with('inventory')->findOrFail($validated['lot_id']);
        if ((int) $lot->inventory->clinic_id !== $clinicId) {
            return response()->json(['message' => 'Lot introuvable.'], 404);
        }
        if ($validated['reason'] === 'EXPIRED' && $validated['quantity_change'] > 0) {
            return response()->json(['message' => 'Une destruction de périmés retire du stock.'], 422);
        }

        DB::transaction(function () use ($lot, $validated, $request) {
            $inventory = PharmacyInventory::whereKey($lot->inventory_id)->lockForUpdate()->firstOrFail();
            $reason = self::ADJUSTMENT_REASONS[$validated['reason']].(!empty($validated['notes']) ? ' : '.$validated['notes'] : '');
            PharmacyStock::adjust($inventory, PharmacyLot::whereKey($lot->id)->lockForUpdate()->firstOrFail(), $validated['quantity_change'], $reason, $request->user()->id);
        });

        return response()->json(['data' => $lot->fresh(), 'message' => 'Ajustement enregistré.']);
    }

    public function movements(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $filters = $request->validate([
            'medication_id' => ['sometimes', 'nullable', 'integer'],
            'type' => ['sometimes', 'nullable', Rule::in(['IN', 'OUT', 'ADJUSTMENT'])],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $movements = DB::table('pharmacy_movements as m')
            ->join('pharmacy_inventory as i', 'i.id', '=', 'm.inventory_id')
            ->join('medications as med', 'med.id', '=', 'i.medication_id')
            ->leftJoin('pharmacy_lots as l', 'l.id', '=', 'm.lot_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.clinic_id', $clinicId)
            ->when($filters['medication_id'] ?? null, fn ($query, $id) => $query->where('i.medication_id', $id))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('m.type', $type))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('m.created_at', '>=', "{$from} 00:00:00"))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('m.created_at', '<=', "{$to} 23:59:59"))
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id')
            ->select(['m.id', 'm.type', 'm.quantity', 'm.reason', 'm.reference_id', 'm.created_at', 'med.name as medication', 'med.dosage', 'l.lot_number', 'u.name as user'])
            ->paginate(30);

        return response()->json($movements);
    }

    /** Prescriptions waiting at the pharmacy (signed, not yet fully dispensed). */
    public function queue(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $search = trim((string) $request->query('search', ''));

        $prescriptions = Prescription::query()
            ->where('clinic_id', $clinicId)
            ->where('status', 'SIGNED')
            ->with([
                'consultation:id,patient_id',
                'consultation.patient:id,first_name,last_name,patient_unique_id',
                'doctor:id,name',
                'items.medication:id,name,dosage,form',
            ])
            ->when($search !== '', fn ($query) => $query->whereHas('consultation.patient', fn ($patients) => $patients
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('patient_unique_id', 'like', "%{$search}%")))
            ->latest('prescribed_at')
            ->paginate(20);

        $inventories = PharmacyInventory::query()
            ->where('clinic_id', $clinicId)
            ->with('lots')
            ->get()
            ->keyBy('medication_id');
        $prescriptions->getCollection()->transform(function (Prescription $prescription) use ($inventories) {
            $prescription->items->each(function ($item) use ($inventories) {
                $inventory = $inventories->get($item->medication_id);
                $item->setAttribute('available_quantity', $inventory
                    ? (int) $inventory->lots->filter(fn ($lot) => $lot->quantity > 0 && !$lot->expiration_date->lt(today()))->sum('quantity')
                    : 0);
                $item->setAttribute('unit_price', (float) ($inventory?->selling_price ?? 0));
            });

            return $prescription;
        });

        return response()->json($prescriptions);
    }

    /**
     * Dispenses a signed prescription, entirely or partially (items[]). The prescription
     * is closed (DISPENSED) once every line has been fully delivered.
     */
    public function dispense(Request $request, Prescription $prescription)
    {
        $clinicId = $this->clinicId($request);
        if ((int) $prescription->clinic_id !== $clinicId) {
            return response()->json(['message' => 'Prescription not found.'], 404);
        }
        if ($request->user()->role?->name !== 'PHARMACIST') {
            return response()->json(['message' => 'Only pharmacy staff can dispense prescriptions.'], 403);
        }
        $validated = $request->validate([
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.prescription_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $sale = DB::transaction(function () use ($prescription, $validated, $request, $clinicId) {
            $locked = Prescription::query()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'SIGNED') {
                abort(422, 'Only signed prescriptions can be dispensed, and a prescription can only be dispensed once.');
            }
            $items = $locked->items()->with('medication')->get()->keyBy('id');
            if ($items->isEmpty()) {
                abort(422, 'This prescription contains no items.');
            }
            $requested = isset($validated['items'])
                ? collect($validated['items'])->mapWithKeys(fn (array $line) => [$line['prescription_item_id'] => (int) $line['quantity']])
                : $items->map(fn ($item) => $item->remaining_quantity);
            foreach ($requested as $itemId => $quantity) {
                $item = $items->get($itemId) ?? abort(422, 'Une ligne ne fait pas partie de cette ordonnance.');
                if ($quantity > $item->remaining_quantity) {
                    abort(422, "Quantité trop élevée pour {$item->medication->name} : il reste {$item->remaining_quantity} à délivrer.");
                }
            }
            if ($requested->sum() === 0) {
                abort(422, 'Indiquez au moins une quantité à délivrer.');
            }

            $saleId = DB::table('pharmacy_sales')->insertGetId([
                'clinic_id' => $clinicId,
                'patient_id' => $locked->consultation()->value('patient_id'),
                'prescription_id' => $locked->id,
                'type' => 'PRESCRIPTION',
                'dispensed_by' => $request->user()->id,
                'sold_at' => now(),
                'total_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $total = 0;
            foreach ($requested->filter() as $itemId => $quantity) {
                $item = $items->get($itemId);
                $inventory = PharmacyInventory::query()->where('clinic_id', $clinicId)->where('medication_id', $item->medication_id)->lockForUpdate()->first()
                    ?? abort(422, "{$item->medication->name} n’est pas référencé dans le stock.");
                PharmacyStock::takeOut($inventory, $quantity, 'Délivrance ordonnance', 'prescription:'.$locked->id, $request->user()->id);
                $line = round((float) $inventory->selling_price * $quantity, 2);
                $total += $line;
                DB::table('pharmacy_sales_items')->insert([
                    'sale_id' => $saleId,
                    'medication_id' => $item->medication_id,
                    'prescription_item_id' => $item->id,
                    'quantity' => $quantity,
                    'unit_price' => $inventory->selling_price,
                    'total_price' => $line,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $item->increment('dispensed_quantity', $quantity);
            }
            DB::table('pharmacy_sales')->where('id', $saleId)->update(['total_amount' => $total]);
            $complete = $locked->items()->whereColumn('dispensed_quantity', '<', 'quantity')->doesntExist();
            if ($complete) {
                $locked->update(['status' => 'DISPENSED']);
            }

            return ['id' => $saleId, 'total_amount' => $total, 'complete' => $complete];
        });

        return response()->json([
            'data' => $prescription->fresh()->load(['consultation.patient', 'doctor:id,name', 'items.medication']),
            'sale' => $sale,
            'message' => $sale['complete'] ? 'Ordonnance entièrement délivrée.' : 'Délivrance partielle enregistrée : le reste pourra être délivré plus tard.',
        ]);
    }

    /** Over-the-counter sale to a registered patient (billed at the cashier). */
    public function counterSale(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where('clinic_id', $clinicId)->whereNull('deleted_at')],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.medication_id' => ['required', 'integer', 'distinct', Rule::exists('medications', 'id')->where('clinic_id', $clinicId)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $sale = DB::transaction(function () use ($validated, $clinicId, $request) {
            $saleId = DB::table('pharmacy_sales')->insertGetId([
                'clinic_id' => $clinicId,
                'patient_id' => $validated['patient_id'],
                'type' => 'COUNTER',
                'dispensed_by' => $request->user()->id,
                'sold_at' => now(),
                'total_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $total = 0;
            foreach ($validated['items'] as $line) {
                $inventory = PharmacyInventory::query()->where('clinic_id', $clinicId)->where('medication_id', $line['medication_id'])->lockForUpdate()->firstOrFail();
                PharmacyStock::takeOut($inventory, (int) $line['quantity'], 'Vente au comptoir', 'sale:'.$saleId, $request->user()->id);
                $amount = round((float) $inventory->selling_price * $line['quantity'], 2);
                $total += $amount;
                DB::table('pharmacy_sales_items')->insert([
                    'sale_id' => $saleId,
                    'medication_id' => $line['medication_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $inventory->selling_price,
                    'total_price' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('pharmacy_sales')->where('id', $saleId)->update(['total_amount' => $total]);

            return ['id' => $saleId, 'total_amount' => $total];
        });

        return response()->json(['data' => $sale, 'message' => 'Vente enregistrée : le patient règle à la caisse.'], 201);
    }

    public function sales(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $sales = DB::table('pharmacy_sales as s')
            ->join('patients as p', 'p.id', '=', 's.patient_id')
            ->join('users as u', 'u.id', '=', 's.dispensed_by')
            ->where('s.clinic_id', $clinicId)
            ->when($request->query('date'), fn ($query, $date) => $query->whereDate('s.sold_at', $date))
            ->orderByDesc('s.sold_at')
            ->select(['s.id', 's.type', 's.prescription_id', 's.sold_at', 's.total_amount', 'p.first_name', 'p.last_name', 'p.patient_unique_id', 'u.name as dispensed_by'])
            ->paginate(30);
        $items = DB::table('pharmacy_sales_items as i')
            ->join('medications as m', 'm.id', '=', 'i.medication_id')
            ->whereIn('i.sale_id', collect($sales->items())->pluck('id'))
            ->get(['i.sale_id', 'm.name', 'm.dosage', 'i.quantity', 'i.unit_price', 'i.total_price'])
            ->groupBy('sale_id');
        $sales->getCollection()->transform(fn ($sale) => [...(array) $sale, 'items' => $items->get($sale->id, collect())->values()]);

        return response()->json($sales);
    }

    /** @return array<string, array<int, mixed>> */
    private function medicationRules(int $clinicId, ?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'dosage' => ['required', 'string', 'max:120', Rule::unique('medications')->where(fn ($query) => $query
                ->where('clinic_id', $clinicId)
                ->where('name', request('name'))
                ->where('form', request('form')))->ignore($ignoreId)],
            'form' => ['required', 'string', 'max:120'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function clinicId(Request $request): int
    {
        return (int) ($request->attributes->get('clinic_id') ?? $request->user()->clinic_id);
    }
}
