<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms (salles) grouped by service, hospital admissions linked to a patient, a bed and a room,
 * and the history of bed-to-bed transfers. Existing beds are attached to a room per ward.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('service', 30); // MEDECINE, CHIRURGIE, MATERNITE, URGENCES
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->timestamps();
            $table->unique(['clinic_id', 'name']);
        });

        Schema::table('beds', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('clinic_id')->constrained('rooms')->nullOnDelete();
        });

        // Backfill: one room per existing ward, in the medicine service, sized to its beds.
        $wards = DB::table('beds')->select('clinic_id', 'ward', DB::raw('COUNT(*) as beds'))->groupBy('clinic_id', 'ward')->get();
        foreach ($wards as $ward) {
            $roomId = DB::table('rooms')->insertGetId([
                'clinic_id' => $ward->clinic_id, 'name' => $ward->ward, 'service' => 'MEDECINE',
                'capacity' => $ward->beds, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('beds')->where('clinic_id', $ward->clinic_id)->where('ward', $ward->ward)->update(['room_id' => $roomId]);
        }

        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('bed_id')->nullable()->constrained('beds')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('ADMIS'); // ADMIS, TRANSFERE, SORTI
            $table->timestamp('admitted_at');
            $table->timestamp('discharged_at')->nullable();
            $table->text('discharge_summary')->nullable(); // compte-rendu de sortie, chiffré à la lecture
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['clinic_id', 'status']);
            $table->index(['patient_id', 'status']);
        });

        Schema::create('admission_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('admission_id')->constrained('admissions')->cascadeOnDelete();
            $table->foreignId('from_bed_id')->nullable()->constrained('beds')->nullOnDelete();
            $table->foreignId('to_bed_id')->constrained('beds')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_transfers');
        Schema::dropIfExists('admissions');
        Schema::table('beds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });
        Schema::dropIfExists('rooms');
    }
};
