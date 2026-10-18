<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff file: not every employee has an application account (cleaners, guards…).
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('employee_number', 30)->nullable()->after('user_id');
            $table->string('department', 100)->nullable()->after('position');
            $table->string('contract_type', 20)->default('CDI')->after('department'); // CDI, CDD, STAGE, PRESTATAIRE
            $table->date('contract_end_date')->nullable()->after('contract_type');
            $table->string('phone', 30)->nullable()->after('contract_end_date');
            $table->string('national_id', 50)->nullable()->after('phone');
            $table->string('address')->nullable()->after('national_id');
            $table->string('cnps_number', 50)->nullable()->after('address');
            $table->string('payment_method', 20)->default('CASH')->after('cnps_number'); // CASH, MOBILE_MONEY, BANK_TRANSFER
            $table->string('payment_account', 100)->nullable()->after('payment_method');
            $table->string('emergency_contact')->nullable()->after('payment_account');
            $table->unsignedTinyInteger('annual_leave_days')->default(24)->after('emergency_contact');
            $table->date('end_date')->nullable()->after('hire_date');
            $table->unique(['clinic_id', 'employee_number']);
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->date('work_date')->nullable()->after('employee_id');
            $table->string('status', 10)->default('PRESENT')->after('check_out_datetime'); // PRESENT, LATE, ABSENT
            $table->foreignId('recorded_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
        });
        DB::table('attendance')->whereNotNull('check_in_datetime')->update(['work_date' => DB::raw('date(check_in_datetime)')]);
        Schema::table('attendance', fn (Blueprint $table) => $table->unique(['employee_id', 'work_date']));

        Schema::table('leaves', function (Blueprint $table) {
            $table->text('review_note')->nullable()->after('approved_at');
            $table->timestamp('cancelled_at')->nullable()->after('review_note');
        });

        Schema::table('payroll', function (Blueprint $table) {
            $table->decimal('worked_days', 5, 1)->default(0)->after('year');
            $table->decimal('unpaid_days', 5, 1)->default(0)->after('worked_days');
            $table->decimal('absence_deduction', 10, 2)->default(0)->after('overtime_amount');
            $table->decimal('bonus', 10, 2)->default(0)->after('absence_deduction');
            $table->decimal('gross_salary', 10, 2)->default(0)->after('bonus');
            $table->string('payment_method', 20)->nullable()->after('paid_datetime');
            $table->string('payment_reference', 100)->nullable()->after('payment_method');
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('paid_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['worked_days', 'unpaid_days', 'absence_deduction', 'bonus', 'gross_salary', 'payment_method', 'payment_reference', 'approved_at']);
        });
        Schema::table('leaves', fn (Blueprint $table) => $table->dropColumn(['review_note', 'cancelled_at']));
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'work_date']);
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn(['work_date', 'status']);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'employee_number']);
            $table->dropColumn(['employee_number', 'department', 'contract_type', 'contract_end_date', 'phone', 'national_id', 'address', 'cnps_number', 'payment_method', 'payment_account', 'emergency_contact', 'annual_leave_days', 'end_date']);
        });
    }
};
