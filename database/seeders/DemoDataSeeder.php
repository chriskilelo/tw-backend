<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\AlertAttachment;
use App\Models\AlertFeedback;
use App\Models\AlertVersion;
use App\Models\Directive;
use App\Models\DirectiveNote;
use App\Models\Inquiry;
use App\Models\InquiryEvent;
use App\Models\InquiryNote;
use App\Models\KpiActual;
use App\Models\KpiTarget;
use App\Models\MasterDataEntry;
use App\Models\Ministry;
use App\Models\PeriodicReport;
use App\Models\ReferralAttachment;
use App\Models\ReferralEntry;
use App\Models\ReportDataRow;
use App\Models\ReportSection;
use App\Models\User;
use Database\Seeders\Support\DemoManifest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the full realistic demo dataset described in the project's
 * seeding plan: real-named attaches and HQ personnel, 153 fully-populated
 * quarterly reports, ~450 alerts, ~250 inquiries, 22 directives, and KPI
 * targets/actuals for all 11 active KPI definitions — spanning FY2024-25,
 * FY2025-26, and the in-progress FY2026-27.
 *
 * Run with: php artisan db:seed --class=DemoDataSeeder
 * Reverse with: php artisan demo-data:clear
 *
 * Step 1 purges confirmed junk from earlier load-testing/QA-fixture
 * seeding sessions (Stage3VolumeSeeder's Faker alerts/inquiries/KPI
 * actuals, and every existing periodic_report/directive — all 32/9
 * existing rows were verified to be Playwright/load-test fixtures, e.g.
 * "LOAD-TEST-REPORT-FORM" report labels and "...fixture directive, safe to
 * discard." directive text, none of them real data), plus leftover
 * alerts/inquiries/kpi_targets that real Playwright E2E runs created
 * through the QA fixture accounts over time (e.g. "Retailer inquiry
 * regarding bulk avocado supply" — generic E2E filler, not part of this
 * dataset's story). QA fixture *accounts themselves* (ps@, hom@,
 * sysadmin@, attache.london@, etc.) and kpi_definitions (the
 * /sdt/config/kpi-settings data) are never touched — only their incidental
 * leftover domain data, which those suites recreate fresh on every run
 * anyway.
 *
 * The entire run is wrapped in one DB transaction so a failure partway
 * through leaves the database exactly as it was found, and the manifest
 * (used by `demo-data:clear`) is only written after that transaction
 * commits successfully.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (DemoManifest::load() !== null) {
            $this->command?->warn(
                'A demo-data manifest already exists — run `php artisan demo-data:clear` first, then reseed. Aborting to avoid orphaning the previous run\'s mission_ministry_links reset data.'
            );

            return;
        }

        $ministry = Ministry::query()->where('name', 'State Department for Trade')->firstOrFail();
        $manifest = new DemoManifest;

        try {
            DB::transaction(function () use ($ministry, $manifest): void {
                $this->purgeJunk();
                $this->fixTemplateEffectiveDate($ministry->id);

                $roster = (new DemoRosterSeeder)->seed($manifest, $ministry->id);

                (new DemoReportSeeder)->seed($manifest, $roster);
                (new DemoAlertSeeder)->seed($manifest, $roster);
                (new DemoInquirySeeder)->seed($manifest, $roster);
                (new DemoDirectiveSeeder)->seed($manifest, $roster);
                (new DemoKpiSeeder)->seed($manifest, $roster, $ministry->id);
            });
        } finally {
            Carbon::setTestNow();
        }

        $manifest->save();

        $this->command?->info('Demo data seeded. Manifest written to '.DemoManifest::path());
    }

    /**
     * @var array<int, string>
     */
    private const array QA_FIXTURE_EMAILS = [
        'ps@tradewatch.go.ke', 'hom@tradewatch.go.ke', 'dhom@tradewatch.go.ke',
        'mfahq@tradewatch.go.ke', 'mfaps@tradewatch.go.ke', 'sysadmin@tradewatch.go.ke',
        'director@tradewatch.go.ke', 'hqdirector@tradewatch.go.ke',
        'attache.london@tradewatch.go.ke', 'synthetic.attache@tradewatch.go.ke',
    ];

    /**
     * Every existing periodic_report/directive was verified (see class
     * docblock) to be a QA/Playwright/load-test fixture — safe to remove
     * unconditionally, children first (no FK cascade is configured on
     * these tables).
     */
    private function purgeJunk(): void
    {
        $reportIds = PeriodicReport::query()->withoutGlobalScopes()->pluck('id');
        $sectionIds = ReportSection::query()->withoutGlobalScopes()->whereIn('periodic_report_id', $reportIds)->pluck('id');
        ReportDataRow::query()->whereIn('report_section_id', $sectionIds)->delete();
        ReportSection::query()->withoutGlobalScopes()->whereIn('periodic_report_id', $reportIds)->delete();
        PeriodicReport::query()->withoutGlobalScopes()->whereIn('id', $reportIds)->delete();

        $directiveIds = Directive::query()->withoutGlobalScopes()->pluck('id');
        DirectiveNote::query()->whereIn('directive_id', $directiveIds)->delete();
        Directive::query()->withoutGlobalScopes()->whereIn('id', $directiveIds)->delete();

        $loadtestUserIds = User::query()->where('email', 'like', 'loadtest.%')->pluck('id');
        $this->purgeAlertsFor($loadtestUserIds->all());
        $this->purgeInquiriesFor($loadtestUserIds->all());
        User::query()->whereIn('id', $loadtestUserIds)->delete();

        // QA fixture accounts are kept (Playwright/E2E suites depend on
        // them), but real E2E runs against them over time left behind
        // generic test-filler alerts/inquiries/a stray KPI target that
        // would otherwise sit mixed into this dataset's lists/dashboards.
        $qaUserIds = User::query()->whereIn('email', self::QA_FIXTURE_EMAILS)->pluck('id');
        $this->purgeAlertsFor($qaUserIds->all());
        $this->purgeInquiriesFor($qaUserIds->all());
        KpiTarget::query()->withoutGlobalScopes()->whereIn('set_by_user_id', $qaUserIds)->delete();

        KpiActual::query()->withoutGlobalScopes()
            ->where('calculation_type', 'manual')
            ->whereNull('entered_by_user_id')
            ->delete();

        MasterDataEntry::query()->withoutGlobalScopes()
            ->where('category', 'aie_budget_code')
            ->where('value', 'not like', '%—%')
            ->delete();
    }

    /**
     * @param  array<int, string>  $userIds
     */
    private function purgeAlertsFor(array $userIds): void
    {
        $alertIds = Alert::query()->withoutGlobalScopes()->whereIn('submitted_by_user_id', $userIds)->pluck('id');
        AlertVersion::query()->whereIn('alert_id', $alertIds)->delete();
        AlertFeedback::query()->whereIn('alert_id', $alertIds)->delete();
        AlertAttachment::query()->whereIn('alert_id', $alertIds)->delete();
        Alert::query()->withoutGlobalScopes()->whereIn('id', $alertIds)->delete();
    }

    /**
     * @param  array<int, string>  $userIds
     */
    private function purgeInquiriesFor(array $userIds): void
    {
        $inquiryIds = Inquiry::query()->withoutGlobalScopes()->whereIn('logged_by_user_id', $userIds)->pluck('id');
        $referralIds = ReferralEntry::query()->withoutGlobalScopes()->whereIn('inquiry_id', $inquiryIds)->pluck('id');
        ReferralAttachment::query()->whereIn('referral_entry_id', $referralIds)->delete();
        ReferralEntry::query()->withoutGlobalScopes()->whereIn('id', $referralIds)->delete();
        InquiryNote::query()->whereIn('inquiry_id', $inquiryIds)->delete();
        InquiryEvent::query()->whereIn('inquiry_id', $inquiryIds)->delete();
        Inquiry::query()->withoutGlobalScopes()->whereIn('id', $inquiryIds)->update(['linked_inquiry_id' => null]);
        Inquiry::query()->withoutGlobalScopes()->whereIn('id', $inquiryIds)->delete();
    }

    /**
     * ReportTemplateSectionSeeder defaulted effective_date to the seed
     * run's own date (documented there as a Sprint-0 placeholder). That
     * postdates every historical quarter this dataset covers (FY2024-25
     * onward), which would make ReportService::resolveActiveVersion() find
     * no active template for any backdated report. Moving it safely into
     * the past is necessary for report creation to work at all, not a
     * cosmetic change — and it only touches effective_date, nothing else
     * about the template.
     */
    private function fixTemplateEffectiveDate(string $ministryId): void
    {
        DB::table('report_template_sections')
            ->where('ministry_id', $ministryId)
            ->update(['effective_date' => '2020-01-01']);
    }
}
