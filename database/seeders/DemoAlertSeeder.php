<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\Mission;
use App\Models\User;
use App\Services\AlertService;
use Database\Seeders\Support\DemoManifest;
use Database\Seeders\Support\Determinism;
use Database\Seeders\Support\FiscalQuarters;
use Database\Seeders\Support\MissionTradeProfiles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds ~450 market intelligence alerts (2-3 per mission per quarter, 1-2
 * for the still-open current quarter) via the real AlertService, so
 * reference-number sequencing, routing notifications, version snapshots
 * (on edit), and feedback threads all go through production code paths.
 * Content is grounded in each mission's MissionTradeProfiles fact sheet —
 * commodities, host country, real partner organisations — never generic
 * placeholder text.
 */
class DemoAlertSeeder extends Seeder
{
    private const int CURRENT_QUARTER_INDEX = 8;

    private const array OPPORTUNITY_TEMPLATES = [
        '%s has flagged rising buyer demand for Kenyan %s among %s importers, citing favourable pricing relative to competing origins.',
        'A %s reports a new sourcing opportunity for Kenyan %s, following a buyer delegation\'s positive assessment of current supply quality.',
        'Market feedback via %s indicates an opening for expanded Kenyan %s volumes into %s ahead of the coming season.',
        '%s has identified a prospective new buyer for Kenyan %s, pending standard due-diligence and sample approval.',
    ];

    private const array BARRIER_TEMPLATES = [
        '%s has introduced revised import documentation requirements affecting Kenyan %s consignments, effective this quarter.',
        'A %s reports increased inspection delays at the port of entry for Kenyan %s shipments, extending clearance times.',
        '%s has raised a technical standards query affecting Kenyan %s exports, requiring updated compliance documentation.',
        'Exporters report a tariff-classification dispute affecting Kenyan %s consignments transiting %s, currently under review.',
    ];

    private const array SOURCES = [
        'Mission field visit',
        'Buyer inquiry received directly at the mission',
        'Local trade press report',
        'Chamber of commerce briefing',
    ];

    public function seed(DemoManifest $manifest, array $roster): void
    {
        $alertService = app(AlertService::class);
        $quarters = FiscalQuarters::reportQuarters();

        foreach (MissionTradeProfiles::all() as $missionName => $profile) {
            $mission = Mission::query()->where('name', $missionName)->firstOrFail();
            $attaches = array_merge([$roster['primary'][$missionName]], $roster['junior'][$missionName]);

            foreach ($quarters as $index => $quarter) {
                $isCurrent = $index === self::CURRENT_QUARTER_INDEX;
                $baseCount = match ($profile['tier']) {
                    'high' => 3,
                    'struggling' => 2,
                    default => Determinism::seeded("{$missionName}-{$index}-altcount", 2, 3),
                };
                $count = $isCurrent ? max(1, intdiv($baseCount, 2)) : $baseCount;

                for ($n = 0; $n < $count; $n++) {
                    $this->seedOneAlert($manifest, $alertService, $roster, $mission, $profile, $missionName, $attaches, $index, $quarter, $n, $isCurrent);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<int, User>  $attaches
     * @param  array{label: string, fy: string, start: Carbon, end: Carbon, deadline: Carbon}  $quarter
     */
    private function seedOneAlert(
        DemoManifest $manifest,
        AlertService $alertService,
        array $roster,
        Mission $mission,
        array $profile,
        string $missionName,
        array $attaches,
        int $index,
        array $quarter,
        int $sequence,
        bool $isCurrent,
    ): void {
        $seedKey = "{$missionName}-{$index}-alert{$sequence}";
        $submitter = Determinism::pick($seedKey.'-submitter', $attaches);

        $windowEnd = $isCurrent ? FiscalQuarters::currentDateCap() : $quarter['end'];
        $span = max(1, $windowEnd->diffInDays($quarter['start']));
        $submitMoment = $quarter['start']->copy()->addDays(Determinism::seeded($seedKey.'-day', 0, (int) $span));
        Carbon::setTestNow($submitMoment);

        $beat = $profile['beats'][$index];
        $intelligenceType = $beat['type'] === 'compliance' && Determinism::chance($seedKey.'-barrier', 60)
            ? 'trade_barriers'
            : (Determinism::chance($seedKey.'-opp', 70) ? 'opportunities' : 'trade_barriers');

        $export = Determinism::pick($seedKey.'-export', $profile['exports']);
        $org = Determinism::pick($seedKey.'-org', $profile['orgs']);
        $sector = Determinism::pick($seedKey.'-sector', $profile['sectors']);
        $template = Determinism::pick(
            $seedKey.'-template',
            $intelligenceType === 'opportunities' ? self::OPPORTUNITY_TEMPLATES : self::BARRIER_TEMPLATES,
        );

        $alert = $alertService->submitAlert([
            'country' => $mission->host_country,
            'sector' => $sector,
            'product_category' => ucfirst($export),
            'product_description' => sprintf($template, $org, $export, $mission->host_country),
            'intelligence_type' => $intelligenceType,
            'intelligence_source' => Determinism::pick($seedKey.'-source', self::SOURCES),
            'urgency' => Determinism::pick($seedKey.'-urgency', ['Routine', 'Routine', 'Time-sensitive', 'Urgent']),
            'confidence_rating' => Determinism::pick($seedKey.'-confidence', ['High', 'Medium', 'Medium', 'Low']),
            'tags' => [$sector, $mission->host_country, $intelligenceType === 'opportunities' ? 'market-access' : 'compliance'],
        ], $submitter);

        $manifest->add('alerts', $alert->id);

        $this->progressAlert($manifest, $alertService, $roster, $alert, $seedKey, $submitMoment);

        Carbon::setTestNow();
    }

    private function progressAlert(
        DemoManifest $manifest,
        AlertService $alertService,
        array $roster,
        Alert $alert,
        string $seedKey,
        Carbon $submitMoment,
    ): void {
        $roll = Determinism::seeded($seedKey.'-progress', 0, 99);

        if ($roll >= 55) {
            return;
        }

        Carbon::setTestNow($submitMoment->copy()->addDays(Determinism::seeded($seedKey.'-delegdays', 1, 6)));
        $delegate = Determinism::chance($seedKey.'-officer1', 50) ? $roster['hq']['hq_officer_1'] : $roster['hq']['hq_officer_2'];
        $alertService->delegateAlert($alert, [$delegate->id], $roster['hq']['ps']);

        if ($roll < 20) {
            Carbon::setTestNow($submitMoment->copy()->addDays(Determinism::seeded($seedKey.'-ackdays', 7, 14)));
            $alertService->acknowledgeAlert($alert->fresh(), $delegate);
        }

        if (Determinism::chance($seedKey.'-feedback', 20)) {
            Carbon::setTestNow($submitMoment->copy()->addDays(Determinism::seeded($seedKey.'-feedbackdays', 8, 16)));
            $alertService->postFeedback(
                $alert->fresh(),
                'Reviewed and noted for the next Ministry PS briefing; no further action required at this time.',
                $delegate,
            );
        }
    }
}
