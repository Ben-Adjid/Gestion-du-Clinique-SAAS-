<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operation safety and billing: estimated duration, pre-operative checklist, informed consent
 * (one per operation, text encrypted at rest), and the invoice the operation gives rise to.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('surgeries', function (Blueprint $table) {
            $table->unsignedSmallInteger('estimated_minutes')->nullable()->after('scheduled_at');
            $table->json('preop_checklist')->nullable()->after('post_op_followup');
            $table->foreignId('invoice_id')->nullable()->after('report_signed_at')->constrained('invoices')->nullOnDelete();
        });

        Schema::create('surgical_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('surgery_id')->unique()->constrained('surgeries')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('signer_name', 120);
            $table->string('signer_relation', 20); // PATIENT, TUTEUR, PROCHE
            $table->text('information_text'); // what was explained to the patient, encrypted at rest
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('signed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_consents');
        Schema::table('surgeries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn(['estimated_minutes', 'preop_checklist']);
        });
    }
};
