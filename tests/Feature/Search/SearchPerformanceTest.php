<?php

use App\Models\Ministry;
use App\Models\Mission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-SEARCH-001, TDD-ADR-007 / session task "Search re-verification at
 * Stage 3 data volume": re-verifies TC-FR-SEARCH-001 still holds under
 * roughly the platform's full projected Stage-3 volume (17 missions x 8
 * reporting periods = 136 periodic reports; 500 alerts; 300 inquiries),
 * asserting the search_vector GIN indexes (Sessions 2/15) keep median
 * response time under 500ms rather than degrading to a sequential scan.
 *
 * Rows are inserted via chunked DB::table()->insert() rather than
 * Model::factory()->create() in a loop — at this row count, going through
 * Eloquent (plus the ModelObserver audit-log write Session 9 registered on
 * Alert/Inquiry/PeriodicReport) would make the *seeding* step, not the
 * search query, the slow part of this test. alerts.search_vector and
 * inquiries.search_vector are native STORED generated columns (CLAUDE.md
 * Section 6), so they populate themselves on insert without being listed
 * here; periodic_reports.search_vector stays NULL either way (Session 2/15
 * note: no ReportService yet populates it from report_sections), which is
 * why this test's timed search only targets alerts/inquiries — the same
 * two tables TC-FR-SEARCH-001 already covers.
 */
it('keeps full-text search under 500ms median at Stage 3 data volume (TC-FR-SEARCH-001-PERF)', function () {
    $ministry = Ministry::factory()->create();
    $missions = Mission::factory()->count(17)->create();
    $attacheRole = Role::query()->firstOrCreate(['name' => 'Ministry Attache'], ['layer' => '2', 'scope' => 'mission']);

    $searchingMission = $missions->first();
    $attache = User::factory()->create([
        'role_id' => $attacheRole->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $searchingMission->id,
    ]);

    $now = now();
    $needleTerm = 'zephyrwoodcorridor';

    // 500 alerts, one of which is the searchable needle.
    $alertRows = [];
    for ($i = 0; $i < 500; $i++) {
        $alertRows[] = [
            'id' => (string) Str::uuid(),
            'ministry_id' => $ministry->id,
            'mission_id' => $missions->random()->id,
            'submitted_by_user_id' => $attache->id,
            'reference_number' => 'ALT-PERF-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'country' => $i === 0 ? "Trade corridor featuring {$needleTerm} exports" : fake()->country(),
            'sector' => fake()->word(),
            'product_category' => fake()->word(),
            'product_description' => fake()->sentence(),
            'intelligence_type' => fake()->randomElement(['opportunities', 'trade_barriers']),
            'intelligence_source' => fake()->company(),
            'urgency' => fake()->randomElement(['low', 'medium', 'high']),
            'confidence_rating' => fake()->randomElement(['low', 'medium', 'high']),
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    collect($alertRows)->chunk(200)->each(fn ($chunk) => DB::table('alerts')->insert($chunk->all()));

    // 300 inquiries.
    $inquiryRows = [];
    for ($i = 0; $i < 300; $i++) {
        $inquiryRows[] = [
            'id' => (string) Str::uuid(),
            'ministry_id' => $ministry->id,
            'mission_id' => $missions->random()->id,
            'logged_by_user_id' => $attache->id,
            'reference_number' => 'INQ-PERF-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'category' => 'General Market Question',
            'sub_type' => 'standard',
            'inquirer_name' => fake()->name(),
            'inquirer_organisation' => fake()->company(),
            'product_or_sector' => fake()->word(),
            'description' => fake()->paragraph(),
            'date_received' => $now->toDateString(),
            'status' => 'received',
            'high_value_flag' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    collect($inquiryRows)->chunk(200)->each(fn ($chunk) => DB::table('inquiries')->insert($chunk->all()));

    // 136 periodic reports (17 missions x 8 reporting periods).
    $reportRows = [];
    foreach ($missions as $mission) {
        for ($period = 0; $period < 8; $period++) {
            $start = $now->copy()->subMonths(($period + 1) * 3)->startOfMonth();
            $reportRows[] = [
                'id' => (string) Str::uuid(),
                'ministry_id' => $ministry->id,
                'mission_id' => $mission->id,
                'authored_by_user_id' => $attache->id,
                'reporting_period_label' => 'Q'.(($period % 4) + 1).' '.$start->format('Y'),
                'period_start_date' => $start->toDateString(),
                'period_end_date' => $start->copy()->addMonths(3)->subDay()->toDateString(),
                'template_version' => 1,
                'status' => 'draft',
                'is_late' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
    }
    collect($reportRows)->chunk(200)->each(fn ($chunk) => DB::table('periodic_reports')->insert($chunk->all()));

    expect(DB::table('alerts')->count())->toBeGreaterThanOrEqual(500);
    expect(DB::table('inquiries')->count())->toBeGreaterThanOrEqual(300);
    expect(DB::table('periodic_reports')->count())->toBeGreaterThanOrEqual(136);

    $durationsMs = [];
    $response = null;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $startedAt = microtime(true);
        $response = $this->actingAs($attache)->getJson("/api/v1/search?q={$needleTerm}");
        $durationsMs[] = (microtime(true) - $startedAt) * 1000;
    }

    $response->assertOk();
    $results = collect($response->json('data'));
    expect($results->pluck('type'))->toContain('alert');

    sort($durationsMs);
    $median = $durationsMs[(int) floor(count($durationsMs) / 2)];

    expect($median)->toBeLessThan(500.0);
});
