<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\Inquiry;
use App\Models\KpiDefinition;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\User;
use App\Services\KpiService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Session 40 (Stage 3 gate, final k6 load-test run): brings the dev
 * database up to the Stage 3 load-test data volume named in the session
 * task (500 alerts, 300 inquiries, 17 missions x 8 quarters of KPI actual
 * data), so tests/load/*.js exercise the same realistic result-set sizes
 * (list pagination, search index size, comparison matrix breadth) the
 * suite is meant to catch regressions in. Mirrors LoadTestAccountSeeder's
 * convention: not called from DatabaseSeeder::run(), invoked explicitly
 * (`php artisan db:seed --class=Stage3VolumeSeeder`) before running k6.
 * Idempotent: only tops up each table up to its target count rather than
 * inserting a fixed batch every run, so re-running after k6 itself has
 * created a handful of rows (e.g. any write-path script) does not runaway
 * grow the table on a second invocation.
 */
class Stage3VolumeSeeder extends Seeder
{
    private const int TARGET_ALERTS = 500;

    private const int TARGET_INQUIRIES = 300;

    private const int QUARTERS = 8;

    public function run(): void
    {
        $ministry = Ministry::query()->where('name', 'State Department for Trade')->firstOrFail();
        $missions = Mission::query()->orderBy('name')->get();
        $users = User::query()->where('email', 'like', 'loadtest.%')->orderBy('email')->get();

        $this->topUpAlerts($ministry->id, $missions, $users);
        $this->topUpInquiries($ministry->id, $missions, $users);
        $this->seedKpiActuals($ministry->id, $missions);
    }

    private function topUpAlerts(string $ministryId, $missions, $users): void
    {
        $existing = Alert::withoutGlobalScopes()->count();
        $need = max(0, self::TARGET_ALERTS - $existing);

        for ($i = 0; $i < $need; $i++) {
            $mission = $missions[$i % $missions->count()];
            $user = $users[$i % $users->count()];
            Alert::factory()->create([
                'ministry_id' => $ministryId,
                'mission_id' => $mission->id,
                'submitted_by_user_id' => $user->id,
            ]);
        }

        $this->command?->info("Alerts: added {$need}, total now ".Alert::withoutGlobalScopes()->count());
    }

    private function topUpInquiries(string $ministryId, $missions, $users): void
    {
        $existing = Inquiry::withoutGlobalScopes()->count();
        $need = max(0, self::TARGET_INQUIRIES - $existing);

        for ($i = 0; $i < $need; $i++) {
            $mission = $missions[$i % $missions->count()];
            $user = $users[$i % $users->count()];
            Inquiry::factory()->create([
                'ministry_id' => $ministryId,
                'mission_id' => $mission->id,
                'logged_by_user_id' => $user->id,
            ]);
        }

        $this->command?->info("Inquiries: added {$need}, total now ".Inquiry::withoutGlobalScopes()->count());
    }

    private function seedKpiActuals(string $ministryId, $missions): void
    {
        $kpi = KpiDefinition::withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->where('active', true)
            ->orderBy('name')
            ->first();

        if ($kpi === null) {
            $this->command?->warn('No active KPI definition found for KPI actuals seeding; skipped.');

            return;
        }

        $kpiService = app(KpiService::class);
        $quarters = $this->eightQuarters();
        $count = 0;

        foreach ($missions as $mission) {
            foreach ($quarters as $quarter) {
                $kpiService->recordActual($kpi, $mission, $quarter['label'], $quarter['start'], (float) random_int(1, 20), null, 'manual');
                $count++;
            }
        }

        $this->command?->info("KPI actuals: recorded {$count} ({$missions->count()} missions x ".count($quarters)." quarters) for '{$kpi->name}'");
    }

    /**
     * CLAUDE.md Section 8 fiscal calendar (Q1 Jul-Sep .. Q4 Apr-Jun),
     * matching KpiService's own quarterStart()/quarterLabel() convention.
     * Two consecutive fiscal years = 8 quarters.
     *
     * @return array<int, array{label: string, start: Carbon}>
     */
    private function eightQuarters(): array
    {
        $quarterMonths = [1 => 7, 2 => 10, 3 => 1, 4 => 4];
        $quarters = [];

        foreach ([2026, 2027] as $year) {
            foreach ($quarterMonths as $quarterNumber => $month) {
                $quarters[] = [
                    'label' => "Q{$quarterNumber} {$year}",
                    'start' => Carbon::create($year, $month, 1)->startOfDay(),
                ];
            }
        }

        return $quarters;
    }
}
