<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laboratory catalogue: price, unit and reference range become real columns.
        Schema::table('lab_exams', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0)->after('name');
            $table->string('unit', 50)->nullable()->after('price');
            $table->decimal('reference_min', 10, 2)->nullable()->after('unit');
            $table->decimal('reference_max', 10, 2)->nullable()->after('reference_min');
            $table->string('sample_type', 100)->nullable()->after('reference_max');
            $table->boolean('is_active')->default(true)->after('sample_type');
        });
        DB::table('lab_exams')->orderBy('id')->each(function ($exam) {
            $reference = json_decode((string) $exam->reference_values, true) ?: [];
            DB::table('lab_exams')->where('id', $exam->id)->update([
                'price' => $reference['price'] ?? 0,
                'unit' => $reference['unit'] ?? null,
                'reference_min' => $reference['min'] ?? null,
                'reference_max' => $reference['max'] ?? null,
            ]);
        });

        Schema::table('imaging_exams', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0)->after('name');
            $table->boolean('is_active')->default(true)->after('price');
        });

        Schema::table('lab_requests', function (Blueprint $table) {
            $table->string('priority', 10)->default('ROUTINE')->after('status');
            $table->text('clinical_info')->nullable()->after('priority');
            $table->string('sample_number', 30)->nullable()->unique()->after('clinical_info');
        });
        Schema::table('lab_results', function (Blueprint $table) {
            $table->string('flag', 10)->nullable()->after('reference_max'); // LOW, NORMAL, HIGH, ABNORMAL
            $table->text('comment')->nullable()->after('flag');
            $table->foreignId('entered_by')->nullable()->after('comment')->constrained('users')->nullOnDelete();
            $table->unique(['request_id', 'exam_id']);
        });

        Schema::table('imaging_requests', function (Blueprint $table) {
            $table->string('priority', 10)->default('ROUTINE')->after('status');
            $table->text('clinical_info')->nullable()->after('priority');
        });

        // Pharmacy: partial dispensing, lot traceability and counter sales.
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->integer('dispensed_quantity')->default(0)->after('quantity');
        });
        DB::table('prescription_items')
            ->whereIn('prescription_id', DB::table('prescriptions')->where('status', 'DISPENSED')->select('id'))
            ->update(['dispensed_quantity' => DB::raw('quantity')]);

        Schema::table('pharmacy_movements', function (Blueprint $table) {
            $table->foreignId('lot_id')->nullable()->after('inventory_id')->constrained('pharmacy_lots')->nullOnDelete();
        });
        Schema::table('pharmacy_lots', function (Blueprint $table) {
            $table->dropUnique(['lot_number']);
            $table->decimal('unit_cost', 10, 2)->nullable()->after('quantity');
            $table->integer('initial_quantity')->nullable()->after('unit_cost');
            $table->unique(['inventory_id', 'lot_number']);
        });
        DB::table('pharmacy_lots')->update(['initial_quantity' => DB::raw('quantity')]);

        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->string('type', 20)->default('PRESCRIPTION')->after('prescription_id'); // PRESCRIPTION, COUNTER
            $table->foreignId('invoice_id')->nullable()->after('type')->constrained('invoices')->nullOnDelete();
        });
        Schema::table('pharmacy_sales_items', function (Blueprint $table) {
            $table->foreignId('prescription_item_id')->nullable()->after('medication_id')->constrained('prescription_items')->nullOnDelete();
        });

        // Cashier: invoices can be cancelled (never deleted once issued) and the day is closed.
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('status', ['DRAFT', 'ISSUED', 'PAID', 'OVERDUE', 'CANCELLED'])->default('DRAFT')->change();
            $table->foreignId('created_by')->nullable()->after('patient_id')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('paid_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
        });

        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('cashier_id')->constrained('users');
            $table->date('business_date');
            $table->unsignedInteger('payments_count')->default(0);
            $table->decimal('expected_cash', 12, 2)->default(0);
            $table->decimal('expected_mobile_money', 12, 2)->default(0);
            $table->decimal('expected_other', 12, 2)->default(0);
            $table->decimal('counted_cash', 12, 2);
            $table->decimal('difference', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->unique(['clinic_id', 'cashier_id', 'business_date']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_closing_id')->nullable()->after('status')->constrained('cash_closings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_closing_id'));
        Schema::dropIfExists('cash_closings');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
        Schema::table('pharmacy_sales_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('prescription_item_id'));
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn('type');
        });
        Schema::table('pharmacy_lots', function (Blueprint $table) {
            $table->dropUnique(['inventory_id', 'lot_number']);
            $table->dropColumn(['unit_cost', 'initial_quantity']);
            $table->unique('lot_number');
        });
        Schema::table('pharmacy_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('lot_id'));
        Schema::table('prescription_items', fn (Blueprint $table) => $table->dropColumn('dispensed_quantity'));
        Schema::table('imaging_requests', fn (Blueprint $table) => $table->dropColumn(['priority', 'clinical_info']));
        Schema::table('lab_results', function (Blueprint $table) {
            $table->dropUnique(['request_id', 'exam_id']);
            $table->dropConstrainedForeignId('entered_by');
            $table->dropColumn(['flag', 'comment']);
        });
        Schema::table('lab_requests', function (Blueprint $table) {
            $table->dropUnique(['sample_number']);
            $table->dropColumn(['priority', 'clinical_info', 'sample_number']);
        });
        Schema::table('imaging_exams', fn (Blueprint $table) => $table->dropColumn(['price', 'is_active']));
        Schema::table('lab_exams', fn (Blueprint $table) => $table->dropColumn(['price', 'unit', 'reference_min', 'reference_max', 'sample_type', 'is_active']));
    }
};
