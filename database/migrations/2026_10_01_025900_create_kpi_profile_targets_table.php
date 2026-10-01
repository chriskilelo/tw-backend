<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-KPI-003: a KPI Profile carries a default target per KPI for each
 * performance cycle, which a mission-specific kpi_targets row overrides
 * without altering the profile. Versioned exactly like kpi_targets
 * (FR-KPI-004, BR-019): a revision inserts a new row and the latest row
 * for a profile, KPI and cycle wins; earlier rows are never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_profile_targets', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('kpi_profile_id')->constrained('kpi_profiles');
            $table->foreignUuid('kpi_definition_id')->constrained('kpi_definitions');
            $table->string('performance_cycle_label', 50);
            $table->date('cycle_start_date');
            $table->decimal('target_value', 15, 2);
            $table->text('note')->nullable();
            $table->foreignUuid('set_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['kpi_profile_id', 'kpi_definition_id', 'cycle_start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_profile_targets');
    }
};
