<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->string('patient_unique_id')->unique(); // HARIRI-2024-000001
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['M', 'F', 'OTHER'])->nullable();
            $table->text('photo_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['clinic_id', 'patient_unique_id']);
            $table->index(['clinic_id', 'phone']);
        });

        Schema::create('patient_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->string('emergency_contact_name');
            $table->string('emergency_contact_phone');
            $table->string('relationship')->nullable();
            $table->timestamps();
        });

        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->string('allergen');
            $table->enum('severity', ['LOW', 'MEDIUM', 'HIGH'])->default('MEDIUM');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('patient_medical_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->string('condition');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('duplicate_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id_1')->constrained('patients')->onDelete('cascade');
            $table->foreignId('patient_id_2')->constrained('patients')->onDelete('cascade');
            $table->decimal('similarity_score', 3, 2); // 0.95 = 95%
            $table->enum('status', ['PENDING', 'CONFIRMED', 'MERGED', 'REJECTED'])->default('PENDING');
            $table->foreignId('admin_id')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_alerts');
        Schema::dropIfExists('patient_medical_history');
        Schema::dropIfExists('patient_allergies');
        Schema::dropIfExists('patient_contacts');
        Schema::dropIfExists('patients');
    }
};
