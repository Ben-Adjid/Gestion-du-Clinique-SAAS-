<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->string('status', 20)->default('PRESENT')->change();
            $table->unsignedSmallInteger('late_minutes')->default(0)->after('status');
        });

        Schema::create('absence_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('status', 10)->default('PENDING'); // PENDING, APPROVED, REJECTED
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index(['clinic_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_requests');
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn('late_minutes');
        });
    }
};
