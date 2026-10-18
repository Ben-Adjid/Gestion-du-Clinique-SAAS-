<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('national_id', 50)->nullable()->after('last_name');
            $table->string('marital_status', 20)->nullable()->after('gender');
            $table->string('profession', 120)->nullable()->after('marital_status');
            $table->string('address', 255)->nullable()->after('email');
            $table->string('city', 120)->nullable()->after('address');
            $table->string('blood_group', 5)->nullable()->after('city');
            $table->string('photo_path')->nullable()->after('photo_url');
            $table->index(['clinic_id', 'last_name', 'first_name']);
        });

        // Address and profession used to live in the JSON metadata column.
        DB::table('patients')->whereNotNull('metadata')->orderBy('id')->each(function ($patient) {
            $metadata = json_decode($patient->metadata, true) ?: [];
            DB::table('patients')->where('id', $patient->id)->update([
                'address' => $metadata['address'] ?? null,
                'profession' => $metadata['profession'] ?? null,
            ]);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('reason', 255)->nullable()->after('status');
            $table->boolean('is_walk_in')->default(false)->after('reason');
            $table->dateTime('checked_in_at')->nullable()->after('is_walk_in');
            $table->dateTime('called_at')->nullable()->after('checked_in_at');
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->foreignId('appointment_id')->nullable()->after('doctor_id')->constrained('appointments')->nullOnDelete();
        });

        Schema::create('cim10_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('label');
            $table->string('chapter', 120);
        });

        Schema::create('medical_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users');
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->string('number')->unique();
            $table->enum('type', ['WORK_STOPPAGE', 'SCHOOL_ABSENCE', 'FITNESS', 'GENERAL']);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('content');
            $table->string('signature_hash', 64);
            $table->timestamp('issued_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_certificates');
        Schema::dropIfExists('cim10_codes');
        Schema::table('consultations', fn (Blueprint $table) => $table->dropConstrainedForeignId('appointment_id'));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn(['reason', 'is_walk_in', 'checked_in_at', 'called_at']));
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['clinic_id', 'last_name', 'first_name']);
            $table->dropColumn(['national_id', 'marital_status', 'profession', 'address', 'city', 'blood_group', 'photo_path']);
        });
    }
};
