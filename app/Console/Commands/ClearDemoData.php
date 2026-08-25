<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\AlertAttachment;
use App\Models\AlertFeedback;
use App\Models\AlertVersion;
use App\Models\AuditLog;
use App\Models\Directive;
use App\Models\DirectiveNote;
use App\Models\Inquiry;
use App\Models\InquiryEvent;
use App\Models\InquiryNote;
use App\Models\KpiActual;
use App\Models\KpiTarget;
use App\Models\MissionMinistryLink;
use App\Models\Notification;
use App\Models\PeriodicReport;
use App\Models\ReferralAttachment;
use App\Models\ReferralEntry;
use App\Models\ReportDataRow;
use App\Models\ReportSection;
use App\Models\User;
use Database\Seeders\Support\DemoManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reverses `php artisan db:seed --class=DemoDataSeeder` precisely: deletes
 * only the rows recorded in storage/app/demo-data/manifest.json, restores
 * mission_ministry_links.active_attache_user_id to what it was before
 * seeding, and removes the manifest. Never touches QA fixture accounts,
 * kpi_definitions, or anything not created by the demo seed.
 */
class ClearDemoData extends Command
{
    protected $signature = 'demo-data:clear {--force : Skip the confirmation prompt}';

    protected $description = 'Removes the realistic demo dataset seeded by php artisan db:seed --class=DemoDataSeeder';

    public function handle(): int
    {
        $manifest = DemoManifest::load();

        if ($manifest === null) {
            $this->info('No demo-data manifest found (storage/app/demo-data/manifest.json); nothing to clear.');

            return self::SUCCESS;
        }

        $tables = $manifest['tables'];
        $userCount = count($tables['users'] ?? []);
        $reportCount = count($tables['periodic_reports'] ?? []);

        if (! $this->option('force') && ! $this->confirm(
            "This will permanently delete the demo dataset seeded on {$manifest['seeded_at']} ({$userCount} users, {$reportCount} reports, and their alerts/inquiries/directives/KPI data). Continue?"
        )) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($tables, $manifest): void {
            $this->clearReports($tables['periodic_reports'] ?? []);
            $this->clearAlerts($tables['alerts'] ?? []);
            $this->clearInquiries($tables['inquiries'] ?? []);
            $this->clearDirectives($tables['directives'] ?? []);

            KpiActual::query()->withoutGlobalScopes()->whereIn('id', $tables['kpi_actuals'] ?? [])->delete();
            KpiTarget::query()->withoutGlobalScopes()->whereIn('id', $tables['kpi_targets'] ?? [])->delete();

            $userIds = $tables['users'] ?? [];
            Notification::query()->whereIn('recipient_user_id', $userIds)->delete();

            $allEntityIds = array_merge(
                $tables['periodic_reports'] ?? [],
                $tables['alerts'] ?? [],
                $tables['inquiries'] ?? [],
                $tables['directives'] ?? [],
                $tables['kpi_targets'] ?? [],
                $tables['kpi_actuals'] ?? [],
                $userIds,
            );
            AuditLog::query()
                ->where(fn ($query) => $query->whereIn('user_id', $userIds)->orWhereIn('affected_entity_id', $allEntityIds))
                ->delete();

            foreach ($manifest['mission_link_resets'] as $reset) {
                MissionMinistryLink::query()
                    ->where('mission_id', $reset['mission_id'])
                    ->update(['active_attache_user_id' => $reset['previous_active_attache_user_id']]);
            }

            User::query()->whereIn('id', $userIds)->forceDelete();
        });

        DemoManifest::delete();
        $this->info('Demo data cleared.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $reportIds
     */
    private function clearReports(array $reportIds): void
    {
        $sectionIds = ReportSection::query()->withoutGlobalScopes()->whereIn('periodic_report_id', $reportIds)->pluck('id');
        ReportDataRow::query()->whereIn('report_section_id', $sectionIds)->delete();
        ReportSection::query()->withoutGlobalScopes()->whereIn('periodic_report_id', $reportIds)->delete();
        PeriodicReport::query()->withoutGlobalScopes()->whereIn('id', $reportIds)->delete();
    }

    /**
     * @param  array<int, string>  $alertIds
     */
    private function clearAlerts(array $alertIds): void
    {
        AlertVersion::query()->whereIn('alert_id', $alertIds)->delete();
        AlertFeedback::query()->whereIn('alert_id', $alertIds)->delete();
        AlertAttachment::query()->whereIn('alert_id', $alertIds)->delete();
        Alert::query()->withoutGlobalScopes()->whereIn('id', $alertIds)->delete();
    }

    /**
     * @param  array<int, string>  $inquiryIds
     */
    private function clearInquiries(array $inquiryIds): void
    {
        $referralIds = ReferralEntry::query()->withoutGlobalScopes()->whereIn('inquiry_id', $inquiryIds)->pluck('id');
        ReferralAttachment::query()->whereIn('referral_entry_id', $referralIds)->delete();
        ReferralEntry::query()->withoutGlobalScopes()->whereIn('id', $referralIds)->delete();
        InquiryNote::query()->whereIn('inquiry_id', $inquiryIds)->delete();
        InquiryEvent::query()->whereIn('inquiry_id', $inquiryIds)->delete();

        // FR-INQ-019 cross-mission links point at each other; null out
        // before deleting so neither side's linked_inquiry_id FK dangles.
        Inquiry::query()->withoutGlobalScopes()->whereIn('id', $inquiryIds)->update(['linked_inquiry_id' => null]);
        Inquiry::query()->withoutGlobalScopes()->whereIn('id', $inquiryIds)->delete();
    }

    /**
     * @param  array<int, string>  $directiveIds
     */
    private function clearDirectives(array $directiveIds): void
    {
        DirectiveNote::query()->whereIn('directive_id', $directiveIds)->delete();
        Directive::query()->withoutGlobalScopes()->whereIn('id', $directiveIds)->delete();
    }
}
