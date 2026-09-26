<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-AUTH-019 (Manage Profile Photo), BR-024: optional, self-uploaded profile photo.
 * Relative path within the 'uploads' disk (TDD-ADR-013), stored under a UUID filename
 * per the existing NFR-SEC-004 attachment-hardening pattern (Session 38), never the
 * client's original filename.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path', 500)->nullable()->after('email_notification_preferences');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
