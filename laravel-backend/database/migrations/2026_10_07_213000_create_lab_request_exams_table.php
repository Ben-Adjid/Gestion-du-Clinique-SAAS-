<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_request_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_request_id')->constrained('lab_requests')->cascadeOnDelete();
            $table->foreignId('lab_exam_id')->constrained('lab_exams')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['lab_request_id', 'lab_exam_id']);
            $table->index(['lab_exam_id', 'lab_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_request_exams');
    }
};
