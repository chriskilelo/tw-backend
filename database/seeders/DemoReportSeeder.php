<?php

namespace Database\Seeders;

use App\Enums\SectionType;
use App\Models\MasterDataEntry;
use App\Models\Mission;
use App\Services\ReportService;
use Database\Seeders\Support\DemoManifest;
use Database\Seeders\Support\Determinism;
use Database\Seeders\Support\FiscalQuarters;
use Database\Seeders\Support\MissionTradeProfiles;
use Database\Seeders\Support\ReportContentGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds up to 153 periodic reports (17 missions x 9 report-bearing
 * quarters) via the real ReportService, backdated with Carbon::setTestNow()
 * so created_at/submitted_at land in the correct historical quarter. Every
 * section of every report is fully populated regardless of the attache's
 * performance tier (per the user's explicit instruction) — tier only
 * affects submission timing: high performers always submit on time,
 * average performers occasionally slip, struggling performers slip often.
 *
 * Two deliberate gaps, not bugs: Kinshasa has no report at all for "Q4
 * 2025" (a real compliance failure during a security disruption, matching
 * its profile's beat for that quarter), and every struggling-tier mission
 * has no report yet for the current quarter "Q1 2026" (their Q1 2026 draft
 * genuinely hasn't been started as of the system's current date).
 */
class DemoReportSeeder extends Seeder
{
    /**
     * @var array<string, array<int, int>>
     */
    private const array SKIPPED_QUARTERS = [
        'Kinshasa' => [3],
    ];

    private const int CURRENT_QUARTER_INDEX = 8;

    public function seed(DemoManifest $manifest, array $roster): void
    {
        $reportService = app(ReportService::class);
        $quarters = FiscalQuarters::reportQuarters();
        $budgetCodes = $this->budgetCodeEntries();

        foreach (MissionTradeProfiles::all() as $missionName => $profile) {
            $mission = Mission::query()->where('name', $missionName)->firstOrFail();
            $attache = $roster['primary'][$missionName];

            foreach ($quarters as $index => $quarter) {
                if ($profile['tier'] === 'struggling' && $index === self::CURRENT_QUARTER_INDEX) {
                    continue;
                }

                if (in_array($index, self::SKIPPED_QUARTERS[$missionName] ?? [], true)) {
                    continue;
                }

                $this->seedOneReport($manifest, $reportService, $mission, $attache, $profile, $missionName, $index, $quarter, $budgetCodes);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array{label: string, fy: string, start: Carbon, end: Carbon, deadline: Carbon}  $quarter
     * @param  array<int, array{code: string, description: string}>  $budgetCodes
     */
    private function seedOneReport(
        DemoManifest $manifest,
        ReportService $reportService,
        Mission $mission,
        $attache,
        array $profile,
        string $missionName,
        int $index,
        array $quarter,
        array $budgetCodes,
    ): void {
        $draftMoment = $quarter['start']->copy()->addDays(Determinism::seeded("{$missionName}-{$index}-draftstart", 5, 35));
        Carbon::setTestNow($draftMoment);

        $report = $reportService->createDraftReport($attache, $quarter['label'], $quarter['start'], $quarter['end']);
        $manifest->add('periodic_reports', $report->id);

        $report->loadMissing('sections.reportTemplateSection');
        $sectionContent = ReportContentGenerator::narrativeSections(
            $profile,
            $missionName,
            $mission->host_country,
            $index,
            $quarter['label'],
            $attache->full_name,
        );

        foreach ($report->sections as $section) {
            $templateSection = $section->reportTemplateSection;

            if ($templateSection->section_type === SectionType::Narrative) {
                $reportService->saveSectionContent($section, $sectionContent[$templateSection->section_title]);

                continue;
            }

            $rows = match ($templateSection->section_title) {
                'Asset Register of Trade Foreign Mission Office' => ReportContentGenerator::assetRegisterRows($missionName, $profile['tier']),
                'AIE Allocations Analysis' => ReportContentGenerator::aieAllocationRows($budgetCodes, $missionName, $profile['tier'], $index),
                default => [],
            };

            foreach ($rows as $order => $rowData) {
                $reportService->addDataRow($section, $rowData, $order + 1);
            }
        }

        if ($index === self::CURRENT_QUARTER_INDEX) {
            Carbon::setTestNow();

            return;
        }

        $lateChance = match ($profile['tier']) {
            'struggling' => 45,
            'average' => 15,
            default => 0,
        };
        $isLate = Determinism::chance("{$missionName}-{$index}-late", $lateChance);

        $submitMoment = $isLate
            ? $quarter['deadline']->copy()->addDays(Determinism::seeded("{$missionName}-{$index}-latedays", 2, 21))
            : $quarter['deadline']->copy()->subDays(Determinism::seeded("{$missionName}-{$index}-earlydays", 1, 10));

        Carbon::setTestNow($submitMoment);
        $reportService->submitReport($report->fresh(), $attache);
        Carbon::setTestNow();
    }

    /**
     * The real seeded aie_budget_code master data (17 budget lines),
     * excluding the Bank Account Balance/TOTAL rows —
     * ReportContentGenerator::aieAllocationRows() appends those itself —
     * and excluding any row that doesn't match the "CODE — Description"
     * shape (defensive: DemoDataSeeder removes the one known stray Faker
     * row, but this guards against any other malformed entry too).
     *
     * @return array<int, array{code: string, description: string}>
     */
    private function budgetCodeEntries(): array
    {
        return MasterDataEntry::query()
            ->withoutGlobalScopes()
            ->where('category', 'aie_budget_code')
            ->orderBy('display_order')
            ->pluck('value')
            ->map(function (string $value): ?array {
                if (! str_contains($value, ' — ')) {
                    return null;
                }

                [$code, $description] = explode(' — ', $value, 2);

                if ($code === 'N/A') {
                    return null;
                }

                return ['code' => $code, 'description' => $description];
            })
            ->filter()
            ->values()
            ->all();
    }
}
