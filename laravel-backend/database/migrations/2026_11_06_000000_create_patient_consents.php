<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('type', 40);
            $table->boolean('granted')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
            $table->unique(['patient_id', 'type']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
        Schema::dropIfExists('patient_consents');
    }
};
