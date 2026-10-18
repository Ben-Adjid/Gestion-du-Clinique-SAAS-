<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AUDIT & COMPLIANCE
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('action'); // CREATE, READ, UPDATE, DELETE
            $table->string('resource'); // PATIENT, CONSULTATION, etc.
            $table->bigInteger('resource_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');
            $table->index(['clinic_id', 'resource', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('sensitive_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->string('reason')->nullable();
            $table->string('purpose')->nullable();
            $table->dateTime('accessed_at');
            $table->timestamps();
            $table->index(['clinic_id', 'user_id', 'accessed_at']);
        });

        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('level', ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'])->default('INFO');
            $table->string('channel'); // laravel, auth, database, etc.
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at');
            $table->index(['level', 'created_at']);
        });

        // COMMUNICATION
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('recipient_address'); // email, phone, device_token
            $table->enum('type', ['SMS', 'EMAIL', 'PUSH', 'WHATSAPP'])->default('SMS');
            $table->string('subject')->nullable();
            $table->text('content');
            $table->enum('status', ['PENDING', 'SENT', 'FAILED', 'BOUNCED'])->default('PENDING');
            $table->dateTime('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->string('reference_id')->nullable(); // appointment_id, invoice_id, etc.
            $table->timestamps();
            $table->index(['clinic_id', 'status', 'created_at']);
        });

        Schema::create('communication_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->enum('type', ['SMS', 'EMAIL', 'PUSH', 'WHATSAPP'])->default('SMS');
            $table->string('name');
            $table->string('subject')->nullable();
            $table->text('body_template');
            $table->json('variables')->nullable(); // {patient_name}, {doctor_name}, etc.
            $table->enum('language', ['FR', 'AR'])->default('FR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['clinic_id', 'name', 'language']);
        });

        Schema::create('communication_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('notification_id')->constrained('notifications')->onDelete('cascade');
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->text('last_error')->nullable();
            $table->dateTime('next_retry_at')->nullable();
            $table->timestamps();
            $table->index(['next_retry_at']);
        });

        // CHATBOT
        Schema::create('chatbot_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('patient_id')->nullable()->constrained('patients')->onDelete('set null');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->enum('status', ['ACTIVE', 'ARCHIVED', 'CLOSED'])->default('ACTIVE');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'user_id', 'started_at']);
        });

        Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chatbot_conversations')->onDelete('cascade');
            $table->enum('role', ['USER', 'ASSISTANT'])->default('USER');
            $table->text('message_text');
            $table->json('references')->nullable(); // {patient_id, drug_id, cim10_code}
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('chatbot_context', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chatbot_conversations')->onDelete('cascade');
            $table->string('user_role'); // DOCTOR, NURSE, PATIENT, etc.
            $table->json('permissions')->nullable();
            $table->json('available_patients')->nullable();
            $table->timestamps();
        });

        // OFFLINE SYNC
        Schema::create('offline_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('action', ['CREATE', 'UPDATE', 'DELETE'])->default('CREATE');
            $table->string('resource_type'); // PATIENT, CONSULTATION, etc.
            $table->bigInteger('resource_id')->nullable();
            $table->json('payload');
            $table->enum('status', ['PENDING', 'SYNCED', 'FAILED'])->default('PENDING');
            $table->text('error_message')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('synced_at')->nullable();
            $table->index(['clinic_id', 'status', 'created_at']);
        });

        Schema::create('sync_timestamps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->string('resource_type');
            $table->dateTime('last_sync_datetime')->nullable();
            $table->timestamps();
            $table->unique(['clinic_id', 'resource_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_timestamps');
        Schema::dropIfExists('offline_queue');
        Schema::dropIfExists('chatbot_context');
        Schema::dropIfExists('chatbot_messages');
        Schema::dropIfExists('chatbot_conversations');
        Schema::dropIfExists('communication_queue');
        Schema::dropIfExists('communication_templates');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('system_logs');
        Schema::dropIfExists('sensitive_access_logs');
        Schema::dropIfExists('audit_logs');
    }
};
