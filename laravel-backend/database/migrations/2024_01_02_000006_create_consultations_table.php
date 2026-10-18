<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->foreignId('doctor_id')->constrained('users')->onDelete('cascade');
            $table->dateTime('consultation_datetime');
            $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'SIGNED'])->default('DRAFT');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['clinic_id', 'patient_id']);
        });

        Schema::create('consultation_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('consultations')->onDelete('cascade');
            $table->integer('section_number'); // 1-8
            $table->string('title'); // Motif, Antécédents, etc.
            $table->longText('content');
            $table->timestamps();
        });

        Schema::create('consultations_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('consultations')->onDelete('cascade');
            $table->string('cim10_code');
            $table->string('cim10_description');
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('consultation_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('consultations')->onDelete('cascade');
            $table->foreignId('assigned_to')->constrained('users')->onDelete('cascade');
            $table->string('task_description');
            $table->enum('status', ['PENDING', 'IN_PROGRESS', 'COMPLETED'])->default('PENDING');
            $table->dateTime('due_date')->nullable();
            $table->timestamps();
        });

        Schema::create('digital_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('consultations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('signature_hash');
            $table->dateTime('signed_at');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_signatures');
        Schema::dropIfExists('consultation_tasks');
        Schema::dropIfExists('consultations_diagnostics');
        Schema::dropIfExists('consultation_sections');
        Schema::dropIfExists('consultations');
    }
};
