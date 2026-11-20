<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The offline_queue table was created for an offline sync that was never built. Medical data must
 * never be queued or cached offline, so the unused table is removed rather than kept as a trap.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('offline_queue');
    }

    public function down(): void
    {
        Schema::create('offline_queue', function ($table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('action', ['CREATE', 'UPDATE', 'DELETE'])->default('CREATE');
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->json('payload');
            $table->enum('status', ['PENDING', 'SYNCED', 'FAILED'])->default('PENDING');
            $table->text('error_message')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('synced_at')->nullable();
            $table->index(['clinic_id', 'status', 'created_at']);
        });
    }
};
