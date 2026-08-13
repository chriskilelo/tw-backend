<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kpi_targets', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('kpi_definition_id')->constrained('kpi_definitions');
            $table->string('performance_cycle_label', 50);
            $table->date('cycle_start_date');
            $table->decimal('target_value', 15, 2);
            $table->foreignUuid('set_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['mission_id', 'kpi_definition_id', 'cycle_start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_targets');
    }
};
