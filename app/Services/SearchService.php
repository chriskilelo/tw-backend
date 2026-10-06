<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Inquiry;
use App\Models\PeriodicReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * CLAUDE.md Section 11 / FR-SEARCH-001 to 004 (Knowledge Search Engine).
 * Queries the native Postgres tsvector/GIN indexes on alerts, inquiries,
 * and periodic_reports directly (TDD-ADR-007) rather than a separate
 * search service. Ministry scoping is enforced the same way as every other
 * Layer 2 read (CLAUDE.md Section 4, Rule 1): each model's HasMinistryScope
 * global scope, fed by the ministry.scope route middleware. Each query also
 * applies its model's visibleTo() scope, because the governance roles
 * bypass ministry scoping: a Head or Deputy Head of Mission finds their own
 * mission's records only (FR-SEARCH-002 AC1, "the user is authorised to
 * view"). The MFA roles never reach this service (SearchController).
 */
class SearchService
{
    private const int RESULTS_PER_TYPE = 25;

    /**
     * A report's narrative text, for ts_headline: every section's content
     * with Markdown emphasis, list, heading and table markers removed.
     */
    private const string REPORT_HEADLINE_SOURCE = "(SELECT coalesce(regexp_replace(string_agg(rs.content, ' '), '[*_#|`>]+', ' ', 'g'), '') FROM report_sections rs WHERE rs.periodic_report_id = periodic_reports.id)";

    /**
     * FR-SEARCH-001 to 003: full-text search across the three indexed
     * record types, merged into a single list and ranked by Postgres's
     * ts_rank relevance score (FR-SEARCH-002 AC1). Each result carries the
     * five data points FR-SEARCH-003 requires: type, title/summary,
     * mission, date, and a context snippet with the matched term
     * highlighted (via ts_headline). It also carries the record's workflow
     * `status` and a few type-specific display fields (an alert's country,
     * intelligence type and sector; an inquiry's sub-type), so the results
     * page can show and translate them. None of these fields is personal
     * data (LEG-001).
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, User $user): array
    {
        $results = [
            ...$this->searchAlerts($query, $user),
            ...$this->searchInquiries($query, $user),
            ...$this->searchPeriodicReports($query, $user),
        ];

        usort($results, fn (array $a, array $b) => $b['rank'] <=> $a['rank']);

        return $results;
    }

    /**
     * FR-SEARCH-004: consolidated intelligence for an accredited country,
     * ministry-scoped like every other read here. Scoped to alerts only —
     * unlike alerts.country, neither inquiries nor periodic_reports carries
     * a country column (CLAUDE.md Section 6; only reachable indirectly via
     * mission.host_country), so cross-engine aggregation for a country is
     * deferred rather than approximated with a mission-level proxy.
     *
     * @return array<string, mixed>
     */
    public function countryProfile(string $country, User $user): array
    {
        $alerts = Alert::query()
            ->visibleTo($user)
            ->with(['mission', 'submittedBy'])
            ->where('country', $country)
            ->orderByDesc('created_at')
            ->get();

        return [
            'country' => $country,
            'total_alerts' => $alerts->count(),
            'by_intelligence_type' => $alerts->groupBy('intelligence_type')->map->count(),
            'by_sector' => $alerts->whereNotNull('sector')->groupBy('sector')->map->count(),
            'recent_alerts' => $alerts->take(10)->map(fn (Alert $alert) => [
                'id' => $alert->id,
                'reference_number' => $alert->reference_number,
                'intelligence_type' => $alert->intelligence_type,
                'sector' => $alert->sector,
                'urgency' => $alert->urgency,
                'status' => $alert->status,
                'mission' => $alert->mission?->name,
                'submitted_by' => $alert->submittedBy?->full_name,
                'created_at' => $alert->created_at,
            ])->values(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchAlerts(string $query, User $user): array
    {
        return Alert::query()
            ->visibleTo($user)
            ->with('mission')
            ->tap(fn (Builder $builder) => $this->applyFullTextMatch(
                $builder,
                $query,
                "coalesce(country, '') || ' ' || coalesce(sector, '') || ' ' || coalesce(product_description, '') || ' ' || coalesce(intelligence_source, '')",
            ))
            ->orderByDesc('rank')
            ->limit(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (Alert $alert): array => [
                'type' => 'alert',
                'id' => $alert->id,
                'reference_number' => $alert->reference_number,
                'summary' => trim("{$alert->country} — {$alert->intelligence_type}"),
                'mission' => $alert->mission?->name,
                'mission_id' => $alert->mission_id,
                'date' => $alert->created_at,
                'snippet' => $alert->snippet,
                'rank' => (float) $alert->rank,
                'link' => "/alerts/{$alert->id}",
                'status' => $alert->status,
                'country' => $alert->country,
                'intelligence_type' => $alert->intelligence_type,
                'sector' => $alert->sector,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchInquiries(string $query, User $user): array
    {
        return Inquiry::query()
            ->visibleTo($user)
            ->with('mission')
            ->tap(fn (Builder $builder) => $this->applyFullTextMatch(
                $builder,
                $query,
                "coalesce(inquirer_name, '') || ' ' || coalesce(description, '') || ' ' || coalesce(product_or_sector, '')",
            ))
            ->orderByDesc('rank')
            ->limit(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (Inquiry $inquiry): array => [
                'type' => 'inquiry',
                'id' => $inquiry->id,
                'reference_number' => $inquiry->reference_number,
                'summary' => trim("{$inquiry->category} — {$inquiry->inquirer_name}"),
                'mission' => $inquiry->mission?->name,
                'mission_id' => $inquiry->mission_id,
                'date' => $inquiry->created_at,
                'snippet' => $inquiry->snippet,
                'rank' => (float) $inquiry->rank,
                'link' => "/inquiries/{$inquiry->id}",
                'status' => $inquiry->status,
                'sub_type' => $inquiry->sub_type,
            ])
            ->all();
    }

    /**
     * search_vector on periodic_reports is a plain column, not a generated
     * one: its source is the child report_sections/report_data_rows tables.
     * ReportService::submitReport() fills it at submission, so only
     * submitted reports are ever found (drafts stay the mission's own work
     * in progress, FR-RPT-017). PeriodicReport::visibleTo() applies the same
     * per-role rules as the report list — an attache sees their own
     * mission's reports only (BR-001). The snippet is cut from the report's
     * narrative text with its Markdown markers stripped.
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchPeriodicReports(string $query, User $user): array
    {
        return PeriodicReport::query()
            ->visibleTo($user)
            ->with('mission')
            ->tap(fn (Builder $builder) => $this->applyFullTextMatch($builder, $query, self::REPORT_HEADLINE_SOURCE))
            ->orderByDesc('rank')
            ->limit(self::RESULTS_PER_TYPE)
            ->get()
            ->map(fn (PeriodicReport $report): array => [
                'type' => 'periodic_report',
                'id' => $report->id,
                'reference_number' => $report->reporting_period_label,
                'summary' => "Report for {$report->reporting_period_label}",
                'mission' => $report->mission?->name,
                'mission_id' => $report->mission_id,
                'date' => $report->created_at,
                'snippet' => $report->snippet,
                'rank' => (float) $report->rank,
                'link' => "/reports/{$report->id}",
                'status' => $report->status,
            ])
            ->all();
    }

    /**
     * Filters to rows whose search_vector matches the query, and selects
     * two extra raw columns: `rank` (ts_rank relevance score, FR-SEARCH-002
     * AC1) and `snippet` (ts_headline context excerpt with the matched term
     * highlighted, FR-SEARCH-003). $headlineSource is the same expression
     * each table's search_vector is generated from (CLAUDE.md Section 6); for
     * periodic_reports, a subquery over its sections (REPORT_HEADLINE_SOURCE).
     */
    private function applyFullTextMatch(Builder $builder, string $query, ?string $headlineSource): void
    {
        $headlineSource ??= "''";

        $builder
            ->selectRaw('*')
            ->selectRaw("ts_rank(search_vector, plainto_tsquery('english', ?)) as rank", [$query])
            ->selectRaw(
                "ts_headline('english', {$headlineSource}, plainto_tsquery('english', ?), 'MaxFragments=1, MaxWords=30, MinWords=10') as snippet",
                [$query],
            )
            ->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$query]);
    }
}
