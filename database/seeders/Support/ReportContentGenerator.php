<?php

namespace Database\Seeders\Support;

/**
 * Composes fully-populated periodic report content (10 narrative sections
 * plus the 2 structured tables: Asset Register, AIE Allocations Analysis —
 * CLAUDE.md Section 8) from a MissionTradeProfiles entry, the mission's
 * host country, and the report's position in the 9-quarter timeline
 * (DemoDataSeeder::REPORT_QUARTERS). Every field is filled for every
 * report, regardless of the attache's performance tier — tier only affects
 * submission timing (DemoReportSeeder), never content completeness, per
 * the user's explicit instruction.
 */
final class ReportContentGenerator
{
    /**
     * @var array<int, string> Indexed 0-8, matching DemoDataSeeder::REPORT_QUARTERS.
     */
    private const FY_THEME = [
        0 => 'the FY2024/25 agricultural trade expansion push — deepening market access for Kenyan tea, coffee, and avocados',
        1 => 'the FY2024/25 agricultural trade expansion push — deepening market access for Kenyan tea, coffee, and avocados',
        2 => 'the FY2024/25 agricultural trade expansion push, moving into its second half with a sharper focus on compliance and certification',
        3 => 'the closing quarter of the FY2024/25 agricultural trade expansion push',
        4 => 'the FY2025/26 focus on digital economy partnerships, bilateral labour agreements, and manufacturing export growth',
        5 => 'the FY2025/26 focus on digital economy partnerships, bilateral labour agreements, and manufacturing export growth',
        6 => 'the FY2025/26 push, now consolidating digital economy and manufacturing gains into the second half of the year',
        7 => 'the closing quarter of the FY2025/26 digital economy, labour, and manufacturing push',
        8 => 'the opening quarter of FY2026/27, consolidating the prior two years\' gains',
    ];

    /**
     * @return array<string, string> Keyed by the exact section_title values
     *                               seeded by ReportTemplateSectionSeeder.
     */
    public static function narrativeSections(
        array $profile,
        string $missionName,
        string $hostCountry,
        int $quarterIndex,
        string $periodLabel,
        string $attacheName,
    ): array {
        $beat = $profile['beats'][$quarterIndex];
        $theme = self::FY_THEME[$quarterIndex];
        $exports = self::listJoin($profile['exports']);
        $imports = self::listJoin($profile['imports']);
        $agreements = self::listJoin($profile['agreements']);
        $orgs = self::listJoin($profile['orgs']);
        $sectors = self::listJoin($profile['sectors']);

        $growthPct = Determinism::seeded("{$missionName}-{$quarterIndex}-growth", 3, 19);
        $tradeValue = Determinism::seeded("{$missionName}-{$quarterIndex}-value", 4, 58) * self::tierMultiplier($profile['tier']);
        $inquiriesHandled = Determinism::seeded("{$missionName}-{$quarterIndex}-inq", 2, 11);
        $spotVisits = Determinism::seeded("{$missionName}-{$quarterIndex}-visits", 1, 4);

        return [
            'Introduction' => sprintf(
                'This report covers the activities of the Kenya Trade Office in %s (%s) for %s, submitted by %s under %s. '
                .'The mission continued to advance Kenya\'s commercial interests in %s. Key activity this quarter: %s '
                .'Objectives for the period centred on %s. '
                .'%s',
                $missionName,
                $hostCountry,
                $periodLabel,
                $attacheName,
                $theme,
                $hostCountry,
                $beat['text'],
                $sectors,
                $profile['challenge'] !== null
                    ? 'As in prior quarters, the mission\'s work continues to be shaped by '.$profile['challenge'].'.'
                    : 'No material operating constraints affected the mission\'s programme this quarter.'
            ),
            'Trade and Investment Inquiries and Forums Attended' => sprintf(
                'The mission handled %d trade and investment inquiries during %s, spanning %s. '
                .'%s '
                .'The Attache conducted %d field/market spot visit(s) in support of exporters and prospective investors during the period. '
                .'Engagement with %s remained the primary channel for forum participation and trade facilitation casework.',
                $inquiriesHandled,
                $periodLabel,
                $sectors,
                $beat['text'],
                $spotVisits,
                $orgs
            ),
            'Trade Performance Trends (Kenya vs. Accredited Countries)' => sprintf(
                'Kenya\'s principal exports to %s during %s were %s, with an estimated quarterly trade value in the range of USD %d million. '
                .'This represents approximately %d%% year-on-year movement relative to the comparable quarter, consistent with %s. '
                .'Kenya\'s principal imports from %s were %s. '
                .'The balance of trade remains structurally in %s\'s favour in manufactured and industrial categories, which the mission continues to monitor for diversification opportunities under %s.',
                $hostCountry,
                $periodLabel,
                $exports,
                $tradeValue,
                $growthPct,
                $theme,
                $hostCountry,
                $imports,
                $hostCountry,
                $agreements
            ),
            'Global Trade Performance Trends of Accredited Countries' => sprintf(
                '%s\'s broader trade profile during the period remained anchored in %s, which continue to shape the competitive landscape Kenyan exporters operate within. '
                .'The mission assesses continued potential for Kenyan exports in %s, subject to the market-access and compliance conditions addressed under the Trade Agreements section below. '
                .'No material shifts in %s\'s top trading-partner ranking were observed this quarter that would affect Kenya\'s relative market position.',
                $hostCountry,
                $imports,
                $exports,
                $hostCountry
            ),
            'Market Intelligence Survey on Kenyan Products' => sprintf(
                'Market intelligence gathered through the period\'s %d spot visit(s) and ongoing exporter contact confirms sustained demand for %s in %s. '
                .'Buyers and distributors engaged this quarter included %s. '
                .'%s '
                .'Exporter-reported challenges this quarter centred on %s.',
                $spotVisits,
                $exports,
                $hostCountry,
                $orgs,
                $beat['type'] === 'compliance' ? $beat['text'] : 'No significant market-access disruptions were reported for Kenyan products this quarter.',
                $profile['challenge'] ?? 'standard logistics, certification, and buyer-financing lead times typical of the '.$hostCountry.' market'
            ),
            'Trade Agreements of Accredited Countries' => sprintf(
                'The mission continued to monitor and support utilisation of %s. '
                .'%s '
                .'No provisions of these instruments were assessed to contravene Kenya\'s WTO commitments during the period; where implementation gaps were identified, these are addressed under the Recommendation section below.',
                $agreements,
                $beat['type'] === 'forum' || $beat['type'] === 'compliance' ? $beat['text'] : 'Utilisation levels among Kenyan exporters remained broadly stable relative to the prior quarter.'
            ),
            'Multilateral Trade Engagements' => sprintf(
                'During %s, the Attache engaged with %s on matters of shared multilateral interest. '
                .'%s '
                .'These engagements are assessed to have reinforced Kenya\'s standing within the relevant multilateral and regional trade architecture without material adverse outcomes for Kenyan interests.',
                $periodLabel,
                $orgs,
                $beat['text']
            ),
            'Conclusion' => sprintf(
                'The %s reporting period for the %s mission was defined principally by the following: %s '
                .'Trade flows in %s remained consistent with the mission\'s ongoing focus on %s, and no unresolved critical issues remain outstanding beyond those already flagged for HQ attention.',
                $periodLabel,
                $missionName,
                $beat['text'],
                $sectors,
                $theme
            ),
            'Recommendation' => sprintf(
                '%s '
                .'The mission recommends SDT HQ continue to prioritise %s as a channel for expanding Kenyan market access in %s.',
                self::recommendationLine($profile, $missionName),
                $agreements,
                $hostCountry
            ),
            'Reference' => sprintf(
                'SDT Quarterly Reporting Guidelines (FY cycle covering %s); briefing notes from %s; correspondence with %s trade desk, %s.',
                $periodLabel,
                $orgs,
                $hostCountry,
                $periodLabel
            ),
        ];
    }

    /**
     * @return array<int, array{'Item Number': int, 'Item Description': string, 'Serial Number': string, 'Status': string, 'Remarks': string}>
     */
    public static function assetRegisterRows(string $missionName, string $tier): array
    {
        $code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $missionName), 0, 3));
        $agingStatus = $tier === 'struggling' ? 'Needs Repair' : 'Fair';

        $items = [
            ['desc' => 'Office laptop computer (attache primary workstation)', 'status' => 'Good'],
            ['desc' => 'Official mission vehicle (Toyota Land Cruiser Prado)', 'status' => 'Good'],
            ['desc' => 'Multifunction printer/scanner', 'status' => $tier === 'struggling' ? $agingStatus : 'Good'],
            ['desc' => 'Meeting room projector and screen', 'status' => 'Fair'],
            ['desc' => 'Document safe / strong room cabinet', 'status' => 'Good'],
            ['desc' => 'Standby power generator', 'status' => $tier === 'struggling' ? $agingStatus : 'Good'],
            ['desc' => 'Office furniture set (desks, chairs, filing cabinets)', 'status' => 'Fair'],
        ];

        $rows = [];

        foreach ($items as $index => $item) {
            $itemNumber = $index + 1;
            $rows[] = [
                'Item Number' => $itemNumber,
                'Item Description' => $item['desc'],
                'Serial Number' => sprintf('TW-%s-%02d', $code, $itemNumber),
                'Status' => $item['status'],
                'Remarks' => $item['status'] === 'Needs Repair'
                    ? 'Flagged for maintenance; requisition pending HQ approval.'
                    : 'In active use, condition confirmed at quarterly stock check.',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $budgetCodeEntries  The real seeded master_data_entries
     *                                                 'aie_budget_code' values ("code — description"),
     *                                                 excluding the Bank Account Balance/TOTAL rows.
     * @return array<int, array{'Budget Code': string, 'Head Description': string, 'Quarter Allocation': float, 'Deficit/Surplus': float, 'Remarks': string}>
     */
    public static function aieAllocationRows(array $budgetCodeEntries, string $missionName, string $tier, int $quarterIndex): array
    {
        $baseAllocation = 180_000 * self::tierMultiplier($tier);
        $rows = [];
        $total = 0.0;
        $totalVariance = 0.0;

        foreach ($budgetCodeEntries as $entry) {
            /** @var array{code: string, description: string} $entry */
            $seed = "{$missionName}-{$quarterIndex}-{$entry['code']}";
            $allocation = round($baseAllocation * (Determinism::seeded($seed.'a', 40, 220) / 100), 2);
            $variance = round($allocation * (Determinism::seeded($seed.'v', -12, 9) / 100), 2);

            $total += $allocation;
            $totalVariance += $variance;

            $rows[] = [
                'Budget Code' => $entry['code'],
                'Head Description' => $entry['description'],
                'Quarter Allocation' => $allocation,
                'Deficit/Surplus' => $variance,
                'Remarks' => $variance < 0 ? 'Over-expenditure against AIE ceiling; supplementary request lodged with HQ.' : 'Within approved AIE ceiling for the quarter.',
            ];
        }

        $bankBalance = round($baseAllocation * (Determinism::seeded("{$missionName}-{$quarterIndex}-bank", 15, 60) / 100), 2);
        $rows[] = [
            'Budget Code' => 'N/A',
            'Head Description' => 'Bank Account Balance',
            'Quarter Allocation' => $bankBalance,
            'Deficit/Surplus' => 0.0,
            'Remarks' => 'Mission operating account balance as at quarter end.',
        ];
        $rows[] = [
            'Budget Code' => 'N/A',
            'Head Description' => 'TOTAL (auto-calculated row)',
            'Quarter Allocation' => round($total, 2),
            'Deficit/Surplus' => round($totalVariance, 2),
            'Remarks' => 'Sum of all AIE budget lines for the quarter.',
        ];

        return $rows;
    }

    private static function recommendationLine(array $profile, string $missionName): string
    {
        if ($profile['tier'] === 'struggling' && $profile['challenge'] !== null) {
            return "Given {$profile['challenge']}, the mission recommends HQ consider additional staffing or logistics support for {$missionName} to sustain current service levels into the next reporting period.";
        }

        if ($profile['tier'] === 'high') {
            return "Building on this quarter's momentum, the mission recommends HQ fast-track approval of the next scheduled trade delegation and maintain current resourcing levels for {$missionName}.";
        }

        return 'The mission recommends HQ maintain current engagement cadence with the organisations named above and continue routine monitoring of the sectors covered this quarter.';
    }

    private static function tierMultiplier(string $tier): float
    {
        return match ($tier) {
            'high' => 1.5,
            'struggling' => 0.65,
            default => 1.0,
        };
    }

    /**
     * @param  array<int, string>  $items
     */
    private static function listJoin(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
