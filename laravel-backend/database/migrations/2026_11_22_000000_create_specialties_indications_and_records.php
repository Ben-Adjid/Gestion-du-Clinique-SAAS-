<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Medical specialties (catalogue shared by the platform), the head of a specialty, the surgical
 * indication that a head of service validates before any operation is scheduled, and the
 * specialty-specific records of a patient (dental, eye, ENT, maternity, generic).
 */
return new class extends Migration {
    /** code => [label, hospital service or null, record kind] */
    private const SPECIALTIES = [
        'MEDECINE_GENERALE' => ['Médecine générale', 'MEDECINE', 'GENERIC'],
        'MEDECINE_INTERNE' => ['Médecine interne', 'MEDECINE', 'GENERIC'],
        'CHIRURGIE_GENERALE' => ['Chirurgie générale', 'CHIRURGIE', 'GENERIC'],
        'GYNECO_OBSTETRIQUE' => ['Gynéco-Obstétrique', 'MATERNITE', 'MATERNITE'],
        'PEDIATRIE' => ['Pédiatrie', 'MEDECINE', 'GENERIC'],
        'OPHTALMOLOGIE' => ['Ophtalmologie', null, 'OPHTALMO'],
        'ORL' => ['ORL', null, 'ORL'],
        'ODONTOLOGIE' => ['Odontologie', null, 'DENTAL'],
        'DERMATOLOGIE' => ['Dermatologie', null, 'GENERIC'],
        'CARDIOLOGIE' => ['Cardiologie', 'MEDECINE', 'GENERIC'],
        'ANESTHESIE_REA' => ['Anesthésie-Réanimation', 'CHIRURGIE', 'GENERIC'],
        'URGENCES' => ['Urgences', 'URGENCES', 'GENERIC'],
        'LABORATOIRE' => ['Laboratoire', null, 'GENERIC'],
        'IMAGERIE' => ['Imagerie', null, 'GENERIC'],
    ];

    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('label', 80);
            $table->string('service', 30)->nullable(); // MEDECINE, CHIRURGIE, MATERNITE, URGENCES
            $table->string('record_kind', 20)->default('GENERIC'); // GENERIC, DENTAL, OPHTALMO, ORL, MATERNITE
            $table->timestamps();
        });

        $now = now();
        foreach (self::SPECIALTIES as $code => [$label, $service, $kind]) {
            DB::table('specialties')->insert(['code' => $code, 'label' => $label, 'service' => $service, 'record_kind' => $kind, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Existing doctors keep their specialty: the free-text label is matched to the catalogue.
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->foreignId('specialty_id')->nullable()->after('user_id')->constrained('specialties')->nullOnDelete();
            $table->boolean('is_head')->default(false)->after('employment');
        });
        $byLabel = DB::table('specialties')->pluck('id', 'label');
        foreach (DB::table('doctor_profiles')->get(['id', 'specialty']) as $profile) {
            $id = $byLabel[$profile->specialty] ?? $byLabel['Médecine générale'];
            DB::table('doctor_profiles')->where('id', $profile->id)->update(['specialty_id' => $id]);
        }

        Schema::create('surgical_indications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('specialty_id')->constrained('specialties')->restrictOnDelete();
            $table->string('operation_type', 150);
            $table->string('urgency', 20); // ELECTIVE, URGENT, EMERGENCY
            $table->text('motif'); // clinical indication, encrypted at rest
            $table->string('status', 20)->default('PENDING_HEAD'); // PENDING_HEAD, APPROVED, REFUSED
            $table->foreignId('head_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('head_decided_at')->nullable();
            $table->string('refusal_reason', 255)->nullable();
            $table->unsignedTinyInteger('asa')->nullable(); // ASA physical status 1 to 5
            $table->text('contraindications')->nullable(); // encrypted at rest
            $table->boolean('allergies_reviewed')->default(false);
            $table->foreignId('pre_anesthesia_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('pre_anesthesia_at')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'status']);
        });

        Schema::table('surgeries', function (Blueprint $table) {
            $table->foreignId('indication_id')->nullable()->after('patient_id')->constrained('surgical_indications')->nullOnDelete();
        });

        Schema::create('patient_specialty_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('specialty_id')->constrained('specialties')->restrictOnDelete();
            $table->text('data'); // specialty-specific measures, encrypted at rest
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamps();
            $table->index(['clinic_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_specialty_records');
        Schema::table('surgeries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('indication_id');
        });
        Schema::dropIfExists('surgical_indications');
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('specialty_id');
            $table->dropColumn('is_head');
        });
        Schema::dropIfExists('specialties');
    }
};
