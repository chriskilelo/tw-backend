<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-KPI-002 / CLAUDE.md Section 8 ("KPI Profiles by mission grouping"):
 * no table previously existed linking a KPI Profile to the missions it
 * applies to — kpi_profile_definitions only links a profile to the KPI
 * definitions it contains. Session 32 adds this pivot so
 * KpiService::assignProfileToMissions() has somewhere to persist the
 * assignment, following kpi_profile_definitions' own append-only,
 * created_at-only shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_profile_missions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('kpi_profile_id')->constrained('kpi_profiles');
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('assigned_by_user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['kpi_profile_id', 'mission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_profile_missions');
    }
};
