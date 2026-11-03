<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online subscription payments through Flutterwave v3. Each checkout attempt is one row,
 * identified by our own tx_ref; the Flutterwave transaction id is unique so that a
 * transaction can never be applied twice (webhook retries, redirect + webhook…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flutterwave_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subscription_id')->constrained('clinic_subscriptions')->cascadeOnDelete();
            // INVOICE: settles an existing subscription invoice. RENEWAL: buys a new period of a plan.
            $table->string('purpose', 10);
            $table->foreignId('saas_invoice_id')->nullable()->constrained('saas_invoices')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('billing_cycle', 10)->nullable();
            $table->string('tx_ref', 80)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('status', 12)->default('PENDING'); // PENDING, SUCCESSFUL, FAILED, CANCELLED
            $table->text('checkout_link')->nullable();
            $table->unsignedBigInteger('flw_transaction_id')->nullable()->unique();
            $table->string('flw_ref', 120)->nullable();
            $table->string('payment_type', 40)->nullable();
            $table->decimal('charged_amount', 12, 2)->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('verification')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'status']);
        });

        Schema::table('clinic_subscriptions', function (Blueprint $table) {
            $table->string('currency', 3)->default('XAF')->after('price');
            $table->unsignedBigInteger('flw_transaction_id')->nullable()->after('cancellation_reason');
            $table->string('flw_ref', 120)->nullable()->after('flw_transaction_id');
        });

        Schema::table('saas_payments', function (Blueprint $table) {
            $table->enum('method', ['ORANGE_MONEY', 'MOOV_MONEY', 'AIRTEL_MONEY', 'BANK_TRANSFER', 'CASH', 'CARD', 'FLUTTERWAVE'])->change();
            $table->foreignId('flutterwave_transaction_id')->nullable()->after('saas_invoice_id')->constrained('flutterwave_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('saas_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flutterwave_transaction_id');
        });
        Schema::table('saas_payments', function (Blueprint $table) {
            $table->enum('method', ['ORANGE_MONEY', 'MOOV_MONEY', 'AIRTEL_MONEY', 'BANK_TRANSFER', 'CASH', 'CARD'])->change();
        });
        Schema::table('clinic_subscriptions', fn (Blueprint $table) => $table->dropColumn(['currency', 'flw_transaction_id', 'flw_ref']));
        Schema::dropIfExists('flutterwave_transactions');
    }
};
