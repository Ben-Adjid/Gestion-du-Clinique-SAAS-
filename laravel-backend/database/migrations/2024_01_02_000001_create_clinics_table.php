<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('city');
            $table->string('country');
            $table->string('code')->unique(); // e.g., HARIRI
            $table->enum('subscription_plan', ['BASIC', 'PRO', 'ENTERPRISE'])->default('BASIC');
            $table->string('admin_email');
            $table->string('admin_phone')->nullable();
            $table->json('settings')->nullable(); // clinic configuration
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'SUSPENDED'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinics');
    }
};
