<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('doctor_id')->constrained('users')->onDelete('cascade');
            $table->integer('day_of_week'); // 0-6 (Monday-Sunday)
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('slot_duration'); // in minutes: 30, 45, 60
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->unique(['clinic_id', 'doctor_id', 'day_of_week']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->foreignId('doctor_id')->constrained('users')->onDelete('cascade');
            $table->dateTime('appointment_datetime');
            $table->enum('status', ['SCHEDULED', 'CANCELLED', 'COMPLETED', 'NO_SHOW'])->default('SCHEDULED');
            $table->text('cancellation_reason')->nullable();
            $table->boolean('reminder_sms_sent')->default(false);
            $table->boolean('reminder_email_sent')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['clinic_id', 'doctor_id', 'appointment_datetime']);
            $table->index(['clinic_id', 'patient_id']);
        });

        Schema::create('appointment_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->onDelete('cascade');
            $table->integer('queue_position');
            $table->enum('status', ['BOOKED', 'CALLED', 'COMPLETED', 'CANCELLED'])->default('BOOKED');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_slots');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('doctor_availability');
    }
};
