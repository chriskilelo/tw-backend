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
        Schema::create('kpi_profile_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('kpi_profile_id')->constrained('kpi_profiles');
            $table->foreignUuid('kpi_definition_id')->constrained('kpi_definitions');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['kpi_profile_id', 'kpi_definition_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_profile_definitions');
    }
};
