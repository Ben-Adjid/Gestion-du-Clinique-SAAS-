<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // INVOICING
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->string('invoice_number')->unique(); // FAC-2024-001
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->integer('tax_rate')->default(18); // 18%
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->enum('status', ['DRAFT', 'ISSUED', 'PAID', 'OVERDUE'])->default('DRAFT');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['clinic_id', 'patient_id']);
            $table->index(['status', 'due_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->string('description'); // CONSULTATION, EXAM, PHARMA
            $table->string('reference_id')->nullable(); // consultation_id, prescription_id
            $table->decimal('amount', 10, 2);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->enum('payment_method', ['CASH', 'MOBILE_MONEY', 'BANK_TRANSFER', 'STRIPE'])->default('CASH');
            $table->string('payment_reference')->nullable();
            $table->dateTime('payment_datetime');
            $table->foreignId('received_by')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['PENDING', 'CONFIRMED', 'FAILED'])->default('CONFIRMED');
            $table->timestamps();
            $table->index(['clinic_id', 'payment_datetime']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->string('method_name'); // Orange Money, Moov, etc.
            $table->json('config'); // API keys, account info
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['clinic_id', 'method_name']);
        });

        Schema::create('payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->integer('reminder_number');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('next_reminder_date')->nullable();
            $table->timestamps();
        });

        // RH & ATTENDANCE
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('position');
            $table->decimal('base_salary', 10, 2);
            $table->date('hire_date');
            $table->enum('status', ['ACTIVE', 'ON_LEAVE', 'SUSPENDED', 'TERMINATED'])->default('ACTIVE');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['clinic_id', 'user_id']);
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->dateTime('check_in_datetime')->nullable();
            $table->dateTime('check_out_datetime')->nullable();
            $table->enum('check_in_method', ['BADGE', 'BIOMETRIC', 'MOBILE', 'QR'])->nullable();
            $table->string('location_gps')->nullable(); // GPS coordinates
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'employee_id', 'check_in_datetime']);
        });

        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->enum('leave_type', ['VACATION', 'SICK', 'MATERNITY', 'UNPAID'])->default('VACATION');
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('days_count');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->text('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->integer('month'); // 1-12
            $table->integer('year');
            $table->decimal('base_salary', 10, 2);
            $table->decimal('overtime_hours', 10, 2)->default(0);
            $table->decimal('overtime_amount', 10, 2)->default(0);
            $table->decimal('cotisation_deduction', 10, 2)->default(0); // 10%
            $table->decimal('tax_deduction', 10, 2)->default(0); // 5%
            $table->decimal('other_deductions', 10, 2)->default(0);
            $table->decimal('net_salary', 10, 2);
            $table->dateTime('paid_datetime')->nullable();
            $table->enum('status', ['DRAFT', 'CALCULATED', 'APPROVED', 'PAID'])->default('DRAFT');
            $table->timestamps();
            $table->unique(['clinic_id', 'employee_id', 'month', 'year']);
        });

        Schema::create('payroll_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('payroll')->onDelete('cascade');
            $table->text('pdf_path');
            $table->dateTime('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_slips');
        Schema::dropIfExists('payroll');
        Schema::dropIfExists('leaves');
        Schema::dropIfExists('attendance');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('payment_reminders');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
