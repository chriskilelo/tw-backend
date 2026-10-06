<?php

namespace Tests\Feature\Reports;

use App\Enums\SectionType;
use App\Enums\UserStatus;
use App\Models\MasterDataEntry;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Models\ReportSection;
use App\Models\ReportTemplateSection;
use App\Models\Role;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Carbon;

/**
 * Shared fixture for the Periodic Report Engine suites: one ministry with a
 * four-section template shaped like SDT's (TW-URD-D Section 2) — two
 * narrative sections, an Asset Register with a selection column, and an AIE
 * Allocations table pre-populated from master data with an auto-calculated
 * total — and four linked missions: Berlin and Accra with an attache each,
 * Lusaka with a vacant post, and an inactive mission. A second ministry
 * with its own mission, attache and report exercises isolation. Not a test
 * file (Pest only collects *Test.php); autoloaded via the Tests\ namespace.
 */
final class ReportWorld
{
    public const string ASSETS = 'Asset Register of Trade Foreign Mission Office';

    public const string AIE = 'AIE Allocations Analysis';

    public const string INTRODUCTION = 'Introduction';

    public const string CONCLUSION = 'Conclusion';

    /**
     * @var array<int, string>
     */
    private const array PLATFORM_ROLES = ['System Administrator', 'MFA HQ Officer', 'MFA Principal Secretary'];

    /**
     * @var array<int, string>
     */
    private const array MISSION_ROLES = ['Head of Mission', 'Deputy Head of Mission', 'Honorary Consul'];

    public function __construct(
        public readonly Ministry $ministry,
        public readonly Mission $mission,
        public readonly Mission $otherMission,
        public readonly Mission $vacantMission,
        public readonly Mission $closedMission,
        public readonly User $attache,
        public readonly User $otherAttache,
        public readonly User $officer,
        public readonly User $director,
        public readonly User $ps,
        public readonly Ministry $foreignMinistry,
        public readonly User $foreignAttache,
    ) {}

    public static function create(): self
    {
        $ministry = Ministry::factory()->create(['name' => 'State Department for Trade (test)']);
        self::seedTemplate($ministry);
        self::seedBudgetCodes($ministry);

        $mission = self::linkedMission($ministry, ['name' => 'Berlin', 'city' => 'Berlin', 'host_country' => 'Germany']);
        $otherMission = self::linkedMission($ministry, ['name' => 'Accra', 'city' => 'Accra', 'host_country' => 'Ghana']);
        $vacantMission = self::linkedMission($ministry, ['name' => 'Lusaka', 'city' => 'Lusaka', 'host_country' => 'Zambia']);
        $closedMission = self::linkedMission($ministry, ['name' => 'Zz Closed Post', 'active' => false]);

        $attache = self::user('Ministry Attache', $ministry, $mission, ['full_name' => 'Amina Attache']);
        $otherAttache = self::user('Ministry Attache', $ministry, $otherMission, ['full_name' => 'Brian Attache']);
        self::post($ministry, $mission, $attache);
        self::post($ministry, $otherMission, $otherAttache);

        $foreignMinistry = Ministry::factory()->create(['name' => 'Foreign Department (test)']);
        self::seedTemplate($foreignMinistry);
        $foreignMission = self::linkedMission($foreignMinistry, ['name' => 'Cairo']);
        $foreignAttache = self::user('Ministry Attache', $foreignMinistry, $foreignMission, ['full_name' => 'Chidi Foreign']);
        self::post($foreignMinistry, $foreignMission, $foreignAttache);

        return new self(
            $ministry,
            $mission,
            $otherMission,
            $vacantMission,
            $closedMission,
            $attache,
            $otherAttache,
            self::user('Ministry HQ Officer', $ministry, null, ['full_name' => 'Carol Officer']),
            self::user('Ministry HQ Director', $ministry, null, ['full_name' => 'Frank Director']),
            self::user('Ministry PS', $ministry, null, ['full_name' => 'Esther PS']),
            $foreignMinistry,
            $foreignAttache,
        );
    }

    public static function role(string $name): Role
    {
        $scope = match (true) {
            in_array($name, self::PLATFORM_ROLES, true) => 'platform',
            in_array($name, self::MISSION_ROLES, true) || $name === 'Ministry Attache' => 'mission',
            default => 'ministry',
        };

        return Role::query()->firstOrCreate(['name' => $name], ['layer' => '2', 'scope' => $scope]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function user(string $roleName, ?Ministry $ministry, ?Mission $mission = null, array $attributes = []): User
    {
        return User::factory()->create([
            'role_id' => self::role($roleName)->id,
            'ministry_id' => $ministry?->id,
            'mission_id' => $mission?->id,
            'status' => UserStatus::Active,
            ...$attributes,
        ]);
    }

    /**
     * A user of $roleName placed the way that role is placed in production:
     * platform roles carry neither ministry nor mission, mission-governance
     * roles this world's Berlin mission, every other role this ministry.
     */
    public function member(string $roleName): User
    {
        return match (true) {
            $roleName === 'Ministry Attache' => $this->attache,
            $roleName === 'Ministry HQ Officer' => $this->officer,
            $roleName === 'Ministry PS' => $this->ps,
            $roleName === 'Ministry HQ Director' => $this->director,
            in_array($roleName, self::PLATFORM_ROLES, true) => self::user($roleName, null),
            in_array($roleName, self::MISSION_ROLES, true) => self::user($roleName, null, $this->mission),
            default => self::user($roleName, $this->ministry),
        };
    }

    /**
     * A draft report by $attache (default: Berlin's) for $label, created
     * through the service so its sections and pre-populated rows exist.
     */
    public function draft(string $label = 'Q1 2026', ?User $attache = null): PeriodicReport
    {
        $service = app(ReportService::class);
        $period = $service->periodForLabel($label);

        return $service->createDraftReport($attache ?? $this->attache, $period['label'], $period['start'], $period['end'])
            ->load(['sections.reportTemplateSection', 'sections.dataRows']);
    }

    /**
     * A report for $label submitted at $submittedAt (default: the day before
     * its deadline, so on time).
     */
    public function submitted(string $label = 'Q1 2026', ?User $attache = null, ?string $submittedAt = null): PeriodicReport
    {
        $report = $this->draft($label, $attache);
        $moment = $submittedAt === null ? $report->deadline()->subDay() : Carbon::parse($submittedAt);
        $now = Carbon::now();

        Carbon::setTestNow($moment);
        $submitted = app(ReportService::class)->submitReport($report, $attache ?? $this->attache, notify: false);
        Carbon::setTestNow($now);

        return $submitted->fresh(['sections.reportTemplateSection', 'sections.dataRows']);
    }

    public function section(PeriodicReport $report, string $title): ReportSection
    {
        return ReportSection::query()
            ->withoutGlobalScopes()
            ->with(['reportTemplateSection', 'dataRows'])
            ->where('periodic_report_id', $report->id)
            ->whereHas('reportTemplateSection', fn ($query) => $query->where('section_title', $title))
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function linkedMission(Ministry $ministry, array $attributes = []): Mission
    {
        $mission = Mission::factory()->create(['active' => true, ...$attributes]);

        MissionMinistryLink::factory()->create(['mission_id' => $mission->id, 'ministry_id' => $ministry->id]);

        return $mission;
    }

    private static function post(Ministry $ministry, Mission $mission, User $attache): void
    {
        MissionMinistryLink::query()
            ->where('ministry_id', $ministry->id)
            ->where('mission_id', $mission->id)
            ->update(['active_attache_user_id' => $attache->id]);
    }

    private static function seedTemplate(Ministry $ministry): void
    {
        $sections = [
            [self::INTRODUCTION, SectionType::Narrative, null, null],
            [self::ASSETS, SectionType::StructuredTable, [
                ['name' => 'Item Number', 'type' => 'integer', 'mandatory' => true],
                ['name' => 'Item Description', 'type' => 'text', 'mandatory' => true],
                ['name' => 'Status', 'type' => 'selection', 'mandatory' => true, 'options' => ['Serviceable', 'Needs Repair', 'Disposed']],
                ['name' => 'Remarks', 'type' => 'text', 'mandatory' => false],
            ], null],
            [self::AIE, SectionType::StructuredTable, [
                ['name' => 'Budget Code', 'type' => 'text', 'mandatory' => true],
                ['name' => 'Head Description', 'type' => 'text', 'mandatory' => true],
                ['name' => 'Quarter Allocation', 'type' => 'numeric', 'mandatory' => true],
                ['name' => 'Deficit/Surplus', 'type' => 'numeric', 'mandatory' => false],
                ['name' => 'Remarks', 'type' => 'text', 'mandatory' => false],
            ], [
                'prepopulate' => ['master_data_category' => 'aie_budget_code', 'label_columns' => ['Budget Code', 'Head Description']],
                'total' => [
                    'label' => 'TOTAL',
                    'label_column' => 'Head Description',
                    'sum_columns' => ['Quarter Allocation', 'Deficit/Surplus'],
                    'exclude_labels' => ['Bank Account Balance'],
                ],
            ]],
            [self::CONCLUSION, SectionType::Narrative, null, null],
        ];

        foreach ($sections as $index => [$title, $type, $schema, $config]) {
            ReportTemplateSection::factory()->create([
                'ministry_id' => $ministry->id,
                'version' => 1,
                'effective_date' => '2020-01-01',
                'section_order' => $index + 1,
                'section_title' => $title,
                'section_type' => $type->value,
                'column_schema' => $schema,
                'table_config' => $config,
                'guidance_text' => "Guidance for {$title}.",
            ]);
        }
    }

    private static function seedBudgetCodes(Ministry $ministry): void
    {
        $codes = [
            ['2110300 — Personal Allowances-FSA', true],
            ['2210100 — Utilities: Electricity and Water', true],
            ['N/A — Bank Account Balance', true],
            ['N/A — TOTAL (auto-calculated row)', true],
            ['9999999 — Retired budget line', false],
        ];

        foreach ($codes as $index => [$value, $active]) {
            MasterDataEntry::query()->create([
                'ministry_id' => $ministry->id,
                'category' => 'aie_budget_code',
                'value' => $value,
                'display_order' => $index + 1,
                'active' => $active,
            ]);
        }
    }
}
