<?php

namespace App\Services;

use App\Models\PharmacyInventory;
use App\Models\PharmacyLot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock movements are always recorded per lot. Outgoing stock follows FEFO
 * (first expired, first out) and expired lots are never dispensed.
 */
final class PharmacyStock
{
    /** Quantity that can still be dispensed (non-expired lots). */
    public static function available(PharmacyInventory $inventory): int
    {
        return (int) $inventory->lots()->where('quantity', '>', 0)->where('expiration_date', '>=', today())->sum('quantity');
    }

    /**
     * Takes $quantity units out of the inventory, lot by lot. Must run inside a transaction.
     *
     * @return list<array{lot_id: int, lot_number: string, quantity: int}>
     */
    public static function takeOut(PharmacyInventory $inventory, int $quantity, string $reason, ?string $reference, int $userId): array
    {
        $lots = $inventory->lots()
            ->where('quantity', '>', 0)
            ->where('expiration_date', '>=', today())
            ->orderBy('expiration_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($lots->sum('quantity') < $quantity) {
            $inventory->loadMissing('medication');
            throw ValidationException::withMessages([
                'stock' => "Stock insuffisant pour {$inventory->medication->name} {$inventory->medication->dosage} : {$lots->sum('quantity')} disponible(s), {$quantity} demandé(s).",
            ]);
        }

        $used = [];
        $remaining = $quantity;
        foreach ($lots as $lot) {
            if ($remaining === 0) {
                break;
            }
            $take = min($remaining, (int) $lot->quantity);
            $lot->decrement('quantity', $take);
            self::movement($inventory, $lot, 'OUT', $take, $reason, $reference, $userId);
            $used[] = ['lot_id' => $lot->id, 'lot_number' => $lot->lot_number, 'quantity' => $take];
            $remaining -= $take;
        }
        $inventory->decrement('quantity', $quantity);

        return $used;
    }

    public static function receive(PharmacyInventory $inventory, array $data, int $userId): PharmacyLot
    {
        $lot = $inventory->lots()->where('lot_number', $data['lot_number'])->lockForUpdate()->first();
        if ($lot && $lot->expiration_date->toDateString() !== $data['expiration_date']) {
            throw ValidationException::withMessages([
                'lot_number' => 'Ce numéro de lot existe déjà avec une autre date de péremption.',
            ]);
        }
        if ($lot) {
            $lot->increment('quantity', $data['quantity']);
            $lot->increment('initial_quantity', $data['quantity']);
        } else {
            $lot = $inventory->lots()->create([
                'lot_number' => $data['lot_number'],
                'manufacturing_date' => $data['manufacturing_date'] ?? today()->toDateString(),
                'expiration_date' => $data['expiration_date'],
                'quantity' => $data['quantity'],
                'initial_quantity' => $data['quantity'],
                'unit_cost' => $data['unit_cost'],
            ]);
        }
        $inventory->increment('quantity', $data['quantity']);
        $inventory->update(['unit_cost' => $data['unit_cost']]);
        self::movement($inventory, $lot, 'IN', (int) $data['quantity'], $data['reason'] ?? 'Réception fournisseur', $data['reference'] ?? null, $userId);

        return $lot;
    }

    public static function adjust(PharmacyInventory $inventory, PharmacyLot $lot, int $change, string $reason, int $userId): void
    {
        if ((int) $lot->quantity + $change < 0) {
            throw ValidationException::withMessages(['quantity_change' => "Le lot {$lot->lot_number} ne contient que {$lot->quantity} unité(s)."]);
        }
        $lot->increment('quantity', $change);
        $inventory->increment('quantity', $change);
        self::movement($inventory, $lot, 'ADJUSTMENT', $change, $reason, null, $userId);
    }

    private static function movement(PharmacyInventory $inventory, PharmacyLot $lot, string $type, int $quantity, string $reason, ?string $reference, int $userId): void
    {
        DB::table('pharmacy_movements')->insert([
            'clinic_id' => $inventory->clinic_id,
            'inventory_id' => $inventory->id,
            'lot_id' => $lot->id,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
            'reference_id' => $reference,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
