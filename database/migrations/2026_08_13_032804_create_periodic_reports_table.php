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
        Schema::create('periodic_reports', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('ministry_id')->constrained('ministries');
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->foreignUuid('authored_by_user_id')->constrained('users');
            $table->string('reporting_period_label', 50);
            $table->date('period_start_date');
            $table->date('period_end_date');
            $table->integer('template_version');
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_late')->default(false);
            $table->timestamps();

            $table->unique(['mission_id', 'ministry_id', 'period_start_date', 'period_end_date']);
            $table->index('status');
        });

        // search_vector is sourced from the child report_sections table (CLAUDE.md Section 6),
        // so unlike alerts/inquiries it cannot be a native Postgres STORED generated column
        // (those may only reference columns of the same row/table). Added as a plain nullable
        // tsvector column, kept in sync by ReportService whenever report_sections change.
        DB::statement('ALTER TABLE periodic_reports ADD COLUMN search_vector tsvector NULL');
        DB::statement('CREATE INDEX periodic_reports_search_vector_index ON periodic_reports USING GIN (search_vector)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('periodic_reports');
    }
};
