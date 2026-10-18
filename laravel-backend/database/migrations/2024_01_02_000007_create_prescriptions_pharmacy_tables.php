<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->string('name');
            $table->string('dosage'); // e.g., 500mg
            $table->string('form'); // tablet, liquid, injection
            $table->string('manufacturer')->nullable();
            $table->decimal('price', 10, 2);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['clinic_id', 'name', 'dosage', 'form']);
        });

        Schema::create('drug_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drug_id_1')->constrained('medications', 'id')->onDelete('cascade');
            $table->foreignId('drug_id_2')->constrained('medications', 'id')->onDelete('cascade');
            $table->enum('severity', ['LOW', 'MEDIUM', 'HIGH'])->default('MEDIUM');
            $table->text('description');
            $table->timestamps();
            $table->unique(['drug_id_1', 'drug_id_2']);
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('consultation_id')->constrained('consultations')->onDelete('cascade');
            $table->foreignId('doctor_id')->constrained('users')->onDelete('cascade');
            $table->dateTime('prescribed_at');
            $table->enum('status', ['DRAFT', 'SIGNED', 'DISPENSED', 'CANCELLED'])->default('DRAFT');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['clinic_id', 'consultation_id']);
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->onDelete('cascade');
            $table->foreignId('medication_id')->constrained('medications')->onDelete('cascade');
            $table->string('dosage_prescribed');
            $table->string('frequency'); // e.g., 3x per day
            $table->integer('duration_days');
            $table->text('special_instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('pharmacy_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('medication_id')->constrained('medications')->onDelete('cascade');
            $table->integer('quantity');
            $table->decimal('unit_cost', 10, 2);
            $table->decimal('selling_price', 10, 2);
            $table->integer('min_threshold')->default(10); // alert when below
            $table->timestamps();
            $table->unique(['clinic_id', 'medication_id']);
            $table->index(['clinic_id']);
        });

        Schema::create('pharmacy_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained('pharmacy_inventory')->onDelete('cascade');
            $table->string('lot_number')->unique();
            $table->date('manufacturing_date');
            $table->date('expiration_date');
            $table->integer('quantity');
            $table->timestamps();
            $table->index('expiration_date');
        });

        Schema::create('pharmacy_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('inventory_id')->constrained('pharmacy_inventory')->onDelete('cascade');
            $table->enum('type', ['IN', 'OUT', 'ADJUSTMENT'])->default('OUT');
            $table->integer('quantity');
            $table->string('reason');
            $table->string('reference_id')->nullable(); // prescription_id, sale_id, etc.
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->index(['clinic_id', 'created_at']);
        });

        Schema::create('pharmacy_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->foreignId('prescription_id')->nullable()->constrained('prescriptions')->onDelete('set null');
            $table->foreignId('dispensed_by')->constrained('users')->onDelete('cascade');
            $table->dateTime('sold_at');
            $table->decimal('total_amount', 10, 2);
            $table->timestamps();
            $table->index(['clinic_id', 'sold_at']);
        });

        Schema::create('pharmacy_sales_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('pharmacy_sales')->onDelete('cascade');
            $table->foreignId('medication_id')->constrained('medications')->onDelete('cascade');
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_sales_items');
        Schema::dropIfExists('pharmacy_sales');
        Schema::dropIfExists('pharmacy_movements');
        Schema::dropIfExists('pharmacy_lots');
        Schema::dropIfExists('pharmacy_inventory');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('drug_interactions');
        Schema::dropIfExists('medications');
    }
};
