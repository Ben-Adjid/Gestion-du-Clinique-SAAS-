<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Marks unpaid invoices as overdue once their payment term has passed and plans
 * up to three reminders, three days apart. The cashiers of each clinic receive a
 * daily summary of the invoices to follow up.
 */
final class InvoiceReminders
{
    public const MAX_REMINDERS = 3;
    public const DAYS_BETWEEN = 3;

    /** @return array{overdue: int, reminders: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $overdue = Invoice::query()
            ->where('status', 'ISSUED')
            ->where('due_date', '<', $now)
            ->update(['status' => 'OVERDUE', 'updated_at' => $now]);

        $reminders = 0;
        $byClinic = [];
        Invoice::query()
            ->where('status', 'OVERDUE')
            ->with('patient:id,first_name,last_name')
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($now, &$reminders, &$byClinic) {
                $last = DB::table('payment_reminders')->where('invoice_id', $invoice->id)->orderByDesc('reminder_number')->first();
                if ($last && ($last->reminder_number >= self::MAX_REMINDERS
                    || Carbon::parse($last->sent_at)->gt($now->copy()->subDays(self::DAYS_BETWEEN)))) {
                    return;
                }
                $number = ($last->reminder_number ?? 0) + 1;
                DB::table('payment_reminders')->insert([
                    'invoice_id' => $invoice->id,
                    'reminder_number' => $number,
                    'sent_at' => $now,
                    'next_reminder_date' => $number < self::MAX_REMINDERS ? $now->copy()->addDays(self::DAYS_BETWEEN) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $reminders++;
                $byClinic[$invoice->clinic_id][] = "{$invoice->invoice_number} ({$invoice->patient?->first_name} {$invoice->patient?->last_name}, relance {$number})";
            });

        foreach ($byClinic as $clinicId => $lines) {
            $recipients = User::query()
                ->where('clinic_id', $clinicId)
                ->where('status', 'ACTIVE')
                ->whereHas('role', fn ($query) => $query->whereIn('name', ['CASHIER', 'ADMIN_CLINIC']))
                ->pluck('id');
            foreach ($recipients as $userId) {
                UserNotification::create([
                    'clinic_id' => $clinicId,
                    'user_id' => $userId,
                    'type' => 'INVOICE_REMINDER',
                    'title' => count($lines).' facture(s) impayée(s) à relancer',
                    'message' => implode(' ; ', array_slice($lines, 0, 10)).(count($lines) > 10 ? ' ; …' : ''),
                ]);
            }
        }

        return ['overdue' => $overdue, 'reminders' => $reminders];
    }
}
