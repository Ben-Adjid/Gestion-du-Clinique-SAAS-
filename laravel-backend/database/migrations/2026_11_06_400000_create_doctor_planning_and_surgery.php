<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Specialty and employment status of a doctor (permanent staff or external consultant)
        Schema::create('doctor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('specialty', 80)->default('Médecine générale');
            $table->string('employment', 20)->default('PERMANENT'); // PERMANENT, CONSULTANT
            $table->timestamps();
            $table->index(['clinic_id', 'specialty']);
        });

        // Day-by-day presence of a doctor. A day with no row at all means "planning not published".
        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('status', 20); // PRESENT, ON_CALL (de garde), UNAVAILABLE
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['doctor_id', 'work_date']);
            $table->index(['clinic_id', 'work_date']);
        });

        Schema::create('surgeries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('operation_type', 150);
            $table->dateTime('scheduled_at');
            $table->string('room', 60)->nullable();
            $table->string('status', 20)->default('PLANNED'); // PLANNED, IN_PROGRESS, COMPLETED, CANCELLED
            $table->foreignId('surgeon_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('anesthetist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('report')->nullable();          // operative report, encrypted at rest
            $table->text('post_op_followup')->nullable(); // post-operative follow-up, encrypted at rest
            $table->dateTime('report_signed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['clinic_id', 'scheduled_at']);
            $table->index(['surgeon_id']);
            $table->index(['anesthetist_id']);
        });

        Schema::create('surgery_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgery_id')->constrained('surgeries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30)->default('OPERATING_NURSE');
            $table->timestamps();
            $table->unique(['surgery_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_staff');
        Schema::dropIfExists('surgeries');
        Schema::dropIfExists('doctor_schedules');
        Schema::dropIfExists('doctor_profiles');
    }
};
