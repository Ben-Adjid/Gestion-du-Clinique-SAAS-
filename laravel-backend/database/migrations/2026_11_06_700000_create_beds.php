<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('ward', 60);
            $table->string('status', 20)->default('AVAILABLE'); // AVAILABLE, OCCUPIED, MAINTENANCE
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->timestamp('admitted_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['clinic_id', 'code']);
            $table->index(['clinic_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beds');
    }
};
