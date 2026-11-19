<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Proof that the clinic accepted the terms of use (CGU) and when, for the audit trail. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->string('terms_version', 20)->nullable()->after('status');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_version');
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn(['terms_version', 'terms_accepted_at']);
        });
    }
};
