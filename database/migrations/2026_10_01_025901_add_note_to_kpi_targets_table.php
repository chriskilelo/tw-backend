<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-KPI-004/005: an optional reason recorded with a target version, so a
 * mid-cycle revision explains itself in the target history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_targets', function (Blueprint $table) {
            $table->text('note')->nullable()->after('target_value');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_targets', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
