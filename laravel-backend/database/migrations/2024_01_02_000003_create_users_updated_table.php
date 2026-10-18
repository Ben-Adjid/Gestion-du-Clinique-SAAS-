<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Update users table from Laravel default
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->onDelete('cascade');
            $table->foreignId('role_id')->nullable()->constrained('roles');
            $table->string('totp_secret')->nullable(); // 2FA
            $table->boolean('totp_enabled')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'SUSPENDED'])->default('ACTIVE');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('permission_id')->constrained()->onDelete('cascade');
            $table->foreignId('clinic_id')->constrained('clinics')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['user_id', 'permission_id', 'clinic_id']);
        });

        Schema::create('tokens_blacklist', function (Blueprint $table) {
            $table->id();
            $table->text('token');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index('expires_at');
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('ip_address');
            $table->boolean('success')->default(false);
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['email', 'ip_address', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('tokens_blacklist');
        Schema::dropIfExists('user_permissions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['clinic_id']);
            $table->dropForeign(['role_id']);
            $table->dropColumn(['phone', 'clinic_id', 'role_id', 'totp_secret', 'totp_enabled',
                               'status', 'last_login_at', 'last_login_ip', 'metadata']);
        });
    }
};
