<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FR-SEARCH-001: periodic_reports.search_vector has existed since the
 * schema was created (a plain column, because its source lives in the child
 * report_sections and report_data_rows tables) but nothing ever filled it,
 * so report text was never searchable. ReportService::submitReport() now
 * fills it at submission; this backfills every report already submitted.
 * Drafts stay unindexed: a report joins the searchable record only once it
 * is submitted (FR-RPT-017).
 *
 * The expression mirrors ReportService::SEARCH_VECTOR_SQL at the time of
 * writing; a migration is a point-in-time snapshot, so it is inlined.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE periodic_reports AS pr
            SET search_vector =
                setweight(to_tsvector('english', coalesce(m.name, '') || ' ' || coalesce(m.host_country, '') || ' ' || pr.reporting_period_label), 'A')
                || setweight(to_tsvector('english', coalesce((
                    SELECT string_agg(rs.content, ' ')
                    FROM report_sections rs
                    WHERE rs.periodic_report_id = pr.id
                ), '')), 'B')
                || setweight(to_tsvector('english', coalesce((
                    SELECT string_agg(cell.value, ' ')
                    FROM report_sections rs
                    JOIN report_data_rows rdr ON rdr.report_section_id = rs.id
                    CROSS JOIN LATERAL jsonb_each_text(
                        CASE WHEN jsonb_typeof(rdr.row_data) = 'object' THEN rdr.row_data ELSE '{}'::jsonb END
                    ) AS cell
                    WHERE rs.periodic_report_id = pr.id
                ), '')), 'C')
            FROM missions m
            WHERE m.id = pr.mission_id
              AND pr.status = 'submitted'
            SQL);
    }

    public function down(): void
    {
        DB::table('periodic_reports')->update(['search_vector' => null]);
    }
};
