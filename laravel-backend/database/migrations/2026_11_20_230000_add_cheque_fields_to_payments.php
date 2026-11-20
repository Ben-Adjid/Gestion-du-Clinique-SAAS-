<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cheques received at the cashier: who wrote it, which bank, and whether it was cashed or rejected. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('cheque_number', 50)->nullable()->index();
            $table->string('cheque_bank', 120)->nullable();
            $table->string('cheque_drawer', 150)->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('cheque_status', 12)->nullable()->index();
            $table->timestamp('cheque_status_changed_at')->nullable();
            $table->foreignId('cheque_status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cheque_status_note', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cheque_status_changed_by']);
            $table->dropColumn([
                'cheque_number', 'cheque_bank', 'cheque_drawer', 'cheque_date',
                'cheque_status', 'cheque_status_changed_at', 'cheque_status_changed_by', 'cheque_status_note',
            ]);
        });
    }
};
