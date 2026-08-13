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
        Schema::create('kpi_actuals', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('kpi_definition_id')->constrained('kpi_definitions');
            $table->string('period_label', 50);
            $table->date('period_start_date');
            $table->decimal('actual_value', 15, 2);
            $table->foreignUuid('entered_by_user_id')->nullable()->constrained('users');
            $table->string('calculation_type', 10);
            $table->timestamps();

            $table->index(['mission_id', 'kpi_definition_id', 'period_start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_actuals');
    }
};
