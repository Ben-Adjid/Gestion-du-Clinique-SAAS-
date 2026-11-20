<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Data taken over from another system: one row per import, and the id each source record received here. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('PENDING')->index(); // PENDING, CHECKED, IMPORTING, DONE, FAILED
            $table->string('file_path');
            $table->string('original_name', 255)->nullable();
            $table->json('report')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_import_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_import_id')->constrained()->cascadeOnDelete();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('target_id');
            $table->unique(['clinic_import_id', 'table_name', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_import_maps');
        Schema::dropIfExists('clinic_imports');
    }
};
