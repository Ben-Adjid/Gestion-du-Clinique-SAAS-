<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('monthly_price', 12, 2);
            $table->decimal('yearly_price', 12, 2);
            $table->string('currency', 3)->default('XAF');
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_patients')->nullable();
            $table->json('modules');
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('clinic_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->unique()->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->enum('billing_cycle', ['MONTHLY', 'YEARLY'])->default('MONTHLY');
            $table->enum('status', ['TRIAL', 'ACTIVE', 'PAST_DUE', 'SUSPENDED', 'CANCELLED'])->default('TRIAL');
            $table->decimal('price', 12, 2);
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->date('trial_ends_at')->nullable();
            $table->unsignedSmallInteger('grace_days')->default(7);
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('saas_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('clinic_subscriptions')->cascadeOnDelete();
            $table->string('number')->unique();
            $table->string('plan_code', 30);
            $table->enum('billing_cycle', ['MONTHLY', 'YEARLY']);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('XAF');
            $table->enum('status', ['PENDING', 'PAID', 'OVERDUE', 'CANCELLED'])->default('PENDING');
            $table->date('due_date');
            $table->timestamp('issued_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'status']);
        });

        Schema::create('saas_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('saas_invoice_id')->constrained('saas_invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['ORANGE_MONEY', 'MOOV_MONEY', 'AIRTEL_MONEY', 'BANK_TRANSFER', 'CASH', 'CARD']);
            $table->string('reference', 120)->nullable();
            $table->date('paid_on');
            $table->enum('status', ['PENDING', 'CONFIRMED', 'REJECTED'])->default('PENDING');
            $table->foreignId('declared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['method', 'reference']);
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('description');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['clinic_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('saas_payments');
        Schema::dropIfExists('saas_invoices');
        Schema::dropIfExists('clinic_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
