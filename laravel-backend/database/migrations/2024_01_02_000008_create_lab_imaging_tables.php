<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // LABORATORY
        Schema::create('lab_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->string('exam_type'); // NFS, CHIMIE, SEROLOGIE
            $table->string('name');
            $table->json('reference_values')->nullable();
            $table->timestamps();
        });

        Schema::create('lab_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->foreignId('doctor_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->onDelete('set null');
            $table->dateTime('requested_at');
            $table->enum('status', ['PENDING', 'RECEIVED', 'IN_PROGRESS', 'READY', 'SIGNED'])->default('PENDING');
            $table->timestamps();
            $table->index(['clinic_id', 'patient_id']);
        });

        Schema::create('lab_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('lab_requests')->onDelete('cascade');
            $table->string('sample_type');
            $table->dateTime('collected_at');
            $table->foreignId('collected_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('lab_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('lab_requests')->onDelete('cascade');
            $table->foreignId('exam_id')->constrained('lab_exams')->onDelete('cascade');
            $table->string('result_value');
            $table->string('unit')->nullable();
            $table->decimal('reference_min', 10, 2)->nullable();
            $table->decimal('reference_max', 10, 2)->nullable();
            $table->enum('status', ['DRAFT', 'SIGNED'])->default('DRAFT');
            $table->foreignId('validator_id')->nullable()->constrained('users');
            $table->dateTime('validated_at')->nullable();
            $table->timestamps();
        });

        // IMAGING
        Schema::create('imaging_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->string('exam_type'); // XRAY, ECHO, IRM, CT
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('imaging_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->foreignId('doctor_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('exam_id')->constrained('imaging_exams')->onDelete('cascade');
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->onDelete('set null');
            $table->dateTime('requested_at');
            $table->enum('status', ['PENDING', 'IN_PROGRESS', 'READY', 'SIGNED'])->default('PENDING');
            $table->timestamps();
            $table->index(['clinic_id', 'patient_id']);
        });

        Schema::create('imaging_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('imaging_requests')->onDelete('cascade');
            $table->enum('file_type', ['DICOM', 'JPG', 'PNG', 'PDF'])->default('DICOM');
            $table->text('file_path');
            $table->string('file_name');
            $table->integer('file_size');
            $table->dateTime('uploaded_at');
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('imaging_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('imaging_requests')->onDelete('cascade');
            $table->foreignId('radiologist_id')->constrained('users')->onDelete('cascade');
            $table->longText('report_text');
            $table->dateTime('signed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('imaging_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('imaging_files')->onDelete('cascade');
            $table->foreignId('radiologist_id')->constrained('users')->onDelete('cascade');
            $table->json('annotation_data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imaging_annotations');
        Schema::dropIfExists('imaging_reports');
        Schema::dropIfExists('imaging_files');
        Schema::dropIfExists('imaging_requests');
        Schema::dropIfExists('imaging_exams');
        Schema::dropIfExists('lab_results');
        Schema::dropIfExists('lab_samples');
        Schema::dropIfExists('lab_requests');
        Schema::dropIfExists('lab_exams');
    }
};
