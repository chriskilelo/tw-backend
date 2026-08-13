<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-SDT-004 AC2: holds the standing role to revert an Acting PS assignee
 * to on deactivation. Null except for the one user (if any) currently
 * holding Acting PS authority within their ministry (App\Services\SdtService).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('acting_ps_original_role_id')->nullable()->after('role_id')->constrained('roles')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acting_ps_original_role_id');
        });
    }
};
