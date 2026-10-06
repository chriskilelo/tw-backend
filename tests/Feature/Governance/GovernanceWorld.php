<?php

namespace Tests\Feature\Governance;

use App\Enums\PeriodicReportStatus;
use App\Models\Alert;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Models\Role;
use App\Models\User;
use App\Support\KpiPeriod;
use Illuminate\Support\Carbon;

/**
 * Shared fixture for the mission-governance suites (FR-HOM-001 to 003,
 * FR-MFA-001 to 003): two departments, Trade and Agriculture; London with
 * an attache from each (so the Head of Mission oversees two departments),
 * Berlin with a Trade attache and a vacant Agriculture post, and an inactive
 * mission. Records are created with explicit dates so quarter boundaries can
 * be tested; the suites freeze "today" at 2026-10-20 (Q2 2026 in progress,
 * Q1 2026 = July to September 2026 is the prior quarter). Not a test file
 * (Pest only collects *Test.php); autoloaded via the Tests\ namespace.
 */
final class GovernanceWorld
{
    public const string TODAY = '2026-10-20 10:00:00';

    public function __construct(
        public readonly Ministry $trade,
        public readonly Ministry $agriculture,
        public readonly Mission $london,
        public readonly Mission $berlin,
        public readonly Mission $closedMission,
        public readonly User $londonTradeAttache,
        public readonly User $londonAgricultureAttache,
        public readonly User $berlinTradeAttache,
    ) {}

    public static function create(): self
    {
        $trade = Ministry::factory()->create(['name' => 'State Department for Trade']);
        $agriculture = Ministry::factory()->create(['name' => 'State Department for Agriculture']);

        $london = Mission::factory()->create(['name' => 'London', 'city' => 'London', 'host_country' => 'United Kingdom']);
        $berlin = Mission::factory()->create(['name' => 'Berlin', 'city' => 'Berlin', 'host_country' => 'Germany']);
        $closedMission = Mission::factory()->create(['name' => 'Kinshasa', 'city' => 'Kinshasa', 'host_country' => 'DR Congo', 'active' => false]);

        $londonTrade = self::user('Ministry Attache', $trade, $london, ['full_name' => 'Purity Samanthe']);
        $londonAgriculture = self::user('Ministry Attache', $agriculture, $london, ['full_name' => 'Joseph Kamau']);
        $berlinTrade = self::user('Ministry Attache', $trade, $berlin, ['full_name' => 'Washington Mijele']);

        MissionMinistryLink::factory()->create(['mission_id' => $london->id, 'ministry_id' => $trade->id, 'active_attache_user_id' => $londonTrade->id]);
        MissionMinistryLink::factory()->create(['mission_id' => $london->id, 'ministry_id' => $agriculture->id, 'active_attache_user_id' => $londonAgriculture->id]);
        MissionMinistryLink::factory()->create(['mission_id' => $berlin->id, 'ministry_id' => $trade->id, 'active_attache_user_id' => $berlinTrade->id]);
        MissionMinistryLink::factory()->create(['mission_id' => $berlin->id, 'ministry_id' => $agriculture->id, 'active_attache_user_id' => null]);

        return new self($trade, $agriculture, $london, $berlin, $closedMission, $londonTrade, $londonAgriculture, $berlinTrade);
    }

    public static function role(string $name): Role
    {
        $scope = match ($name) {
            'System Administrator', 'MFA HQ Officer', 'MFA Principal Secretary' => 'platform',
            'Head of Mission', 'Deputy Head of Mission', 'Ministry Attache', 'Honorary Consul' => 'mission',
            default => 'ministry',
        };

        return Role::query()->firstOrCreate(['name' => $name], ['layer' => '1', 'scope' => $scope]);
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
            ...$attributes,
        ]);
    }

    /**
     * A user of any role, scoped the way its role is provisioned: the
     * mission-governance roles carry no department, the oversight pair
     * carries a mission (London unless another is given), a department
     * role carries Trade.
     */
    public function member(string $roleName, ?Mission $mission = null): User
    {
        return match ($roleName) {
            'Head of Mission', 'Deputy Head of Mission' => self::user($roleName, null, $mission ?? $this->london),
            'MFA HQ Officer', 'MFA Principal Secretary', 'System Administrator' => self::user($roleName, null),
            'Ministry Attache', 'Honorary Consul' => self::user($roleName, $this->trade, $mission ?? $this->london),
            default => self::user($roleName, $this->trade),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function alert(Mission $mission, Ministry $ministry, string $at, array $attributes = []): Alert
    {
        return Alert::factory()->create([
            'mission_id' => $mission->id,
            'ministry_id' => $ministry->id,
            'submitted_by_user_id' => $this->attacheFor($mission, $ministry)->id,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function inquiry(Mission $mission, Ministry $ministry, string $at, array $attributes = []): Inquiry
    {
        return Inquiry::factory()->create([
            'mission_id' => $mission->id,
            'ministry_id' => $ministry->id,
            'logged_by_user_id' => $this->attacheFor($mission, $ministry)->id,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
            ...$attributes,
        ]);
    }

    /**
     * A periodic report for a fiscal quarter: submitted at $submittedAt, or
     * a draft when that is null.
     */
    public function report(Mission $mission, Ministry $ministry, string $quarterLabel, ?string $submittedAt, bool $isLate = false): PeriodicReport
    {
        $quarter = KpiPeriod::fromLabel($quarterLabel);

        return PeriodicReport::factory()->create([
            'mission_id' => $mission->id,
            'ministry_id' => $ministry->id,
            'authored_by_user_id' => $this->attacheFor($mission, $ministry)->id,
            'reporting_period_label' => $quarterLabel,
            'period_start_date' => $quarter->start()->toDateString(),
            'period_end_date' => $quarter->end()->toDateString(),
            'status' => $submittedAt === null ? PeriodicReportStatus::Draft->value : PeriodicReportStatus::Submitted->value,
            'submitted_at' => $submittedAt === null ? null : Carbon::parse($submittedAt),
            'is_late' => $isLate,
            'created_at' => Carbon::parse($submittedAt ?? self::TODAY)->subDays(10),
        ]);
    }

    public function directive(Mission $mission, Ministry $ministry, string $at): Directive
    {
        return Directive::factory()->create([
            'mission_id' => $mission->id,
            'ministry_id' => $ministry->id,
            'target_user_id' => $this->attacheFor($mission, $ministry)->id,
            'status' => 'issued',
            'description' => 'Confidential HQ tasking: prepare the bilateral briefing.',
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);
    }

    private function attacheFor(Mission $mission, Ministry $ministry): User
    {
        return match (true) {
            $mission->is($this->london) && $ministry->is($this->trade) => $this->londonTradeAttache,
            $mission->is($this->london) && $ministry->is($this->agriculture) => $this->londonAgricultureAttache,
            $mission->is($this->berlin) && $ministry->is($this->trade) => $this->berlinTradeAttache,
            default => self::user('Ministry Attache', $ministry, $mission),
        };
    }
}
