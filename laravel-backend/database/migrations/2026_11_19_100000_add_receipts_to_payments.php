<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Official receipts for medical payments: a unique receipt number per clinic, the date the receipt
 * was e-mailed, the insurer when the care is covered, and more payment methods (Wave, Orange Money,
 * card, insurance). The payment method becomes a plain string so the list can evolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_method', 20)->default('CASH')->change();
            $table->string('receipt_number', 30)->nullable()->after('payment_reference');
            $table->string('insurer_name', 120)->nullable()->after('receipt_number');
            $table->timestamp('receipt_sent_at')->nullable()->after('insurer_name');
            $table->unique(['clinic_id', 'receipt_number']);
        });

        // Payments recorded before this migration get their receipt number now, in the order paid.
        $year = now()->year;
        $number = 0;
        DB::table('payments')->where('status', 'CONFIRMED')->orderBy('clinic_id')->orderBy('id')->chunkById(500, function ($payments) use (&$number, $year) {
            foreach ($payments as $payment) {
                DB::table('payments')->where('id', $payment->id)->update([
                    'receipt_number' => sprintf('REC-MED-%d-%04d', $year, ++$number),
                ]);
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'receipt_number']);
            $table->dropColumn(['receipt_number', 'insurer_name', 'receipt_sent_at']);
        });
    }
};
