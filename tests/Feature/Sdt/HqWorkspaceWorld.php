<?php

namespace Tests\Feature\Sdt;

use App\Enums\AlertStatus;
use App\Enums\DirectiveStatus;
use App\Enums\InquiryStatus;
use App\Enums\PeriodicReportStatus;
use App\Enums\UserStatus;
use App\Models\Alert;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\InquiryEvent;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Shared fixture for the HQ workspace suites (FR-SDT-012 to 015): the
 * Trade department with three posted missions — Berlin (Germany), Accra
 * (Ghana) and London (United Kingdom) — an attache at each of the first
 * two, two HQ officers (Carol's portfolio is Berlin plus the "tea" sector),
 * the PS and an HQ Director; and a second department (Mining) with its own
 * HQ officer and attache at Berlin, the cross-department control. Not a
 * test file (Pest only collects *Test.php).
 */
final class HqWorkspaceWorld
{
    /**
     * @var array<int, string>
     */
    private const array PLATFORM_ROLES = ['System Administrator', 'MFA HQ Officer', 'MFA Principal Secretary'];

    /**
     * @var array<int, string>
     */
    private const array MISSION_ROLES = ['Head of Mission', 'Deputy Head of Mission'];

    public function __construct(
        public readonly Ministry $trade,
        public readonly Ministry $mining,
        public readonly Mission $berlin,
        public readonly Mission $accra,
        public readonly Mission $london,
        public readonly User $berlinAttache,
        public readonly User $accraAttache,
        public readonly User $officer,
        public readonly User $otherOfficer,
        public readonly User $ps,
        public readonly User $director,
        public readonly User $miningOfficer,
        public readonly User $miningAttache,
    ) {}

    public static function create(): self
    {
        $trade = Ministry::factory()->create(['name' => 'Trade']);
        $mining = Ministry::factory()->create(['name' => 'Mining']);
        $berlin = self::linkedMission($trade, ['name' => 'Berlin', 'city' => 'Berlin', 'host_country' => 'Germany']);
        $accra = self::linkedMission($trade, ['name' => 'Accra', 'city' => 'Accra', 'host_country' => 'Ghana']);
        $london = self::linkedMission($trade, ['name' => 'London', 'city' => 'London', 'host_country' => 'United Kingdom']);
        MissionMinistryLink::factory()->create(['mission_id' => $berlin->id, 'ministry_id' => $mining->id]);

        return new self(
            $trade,
            $mining,
            $berlin,
            $accra,
            $london,
            self::user('Ministry Attache', $trade, $berlin, ['full_name' => 'Amina Attache']),
            self::user('Ministry Attache', $trade, $accra, ['full_name' => 'Brian Attache']),
            self::user('Ministry HQ Officer', $trade, null, [
                'full_name' => 'Carol Officer',
                'portfolio' => ['mission_ids' => [$berlin->id], 'countries' => [], 'sectors' => ['tea']],
            ]),
            self::user('Ministry HQ Officer', $trade, null, ['full_name' => 'David Officer']),
            self::user('Ministry PS', $trade, null, ['full_name' => 'Esther PS']),
            self::user('Ministry HQ Director', $trade, null, ['full_name' => 'Frank Director']),
            self::user('Ministry HQ Officer', $mining, null, ['full_name' => 'Grace Mining Officer']),
            self::user('Ministry Attache', $mining, $berlin, ['full_name' => 'Hassan Mining Attache']),
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
    public static function linkedMission(Ministry $ministry, array $attributes = []): Mission
    {
        $mission = Mission::factory()->create($attributes);
        MissionMinistryLink::factory()->create(['mission_id' => $mission->id, 'ministry_id' => $ministry->id]);

        return $mission;
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
     * A user of $roleName placed the way that role is placed in production.
     */
    public function member(string $roleName): User
    {
        return match (true) {
            $roleName === 'Ministry HQ Officer' => $this->officer,
            $roleName === 'Ministry PS' => $this->ps,
            $roleName === 'Ministry HQ Director' => $this->director,
            $roleName === 'Ministry Attache' => $this->berlinAttache,
            in_array($roleName, self::PLATFORM_ROLES, true) => self::user($roleName, null),
            in_array($roleName, self::MISSION_ROLES, true) => self::user($roleName, null, $this->berlin),
            default => self::user($roleName, $this->trade),
        };
    }

    /**
     * A Trade alert from the Berlin attache, routed but not delegated,
     * unless overridden.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function alert(array $attributes = []): Alert
    {
        return Alert::factory()->create([
            'ministry_id' => $this->trade->id,
            'mission_id' => $this->berlin->id,
            'submitted_by_user_id' => $this->berlinAttache->id,
            'country' => 'Germany',
            'sector' => null,
            'product_category' => null,
            'status' => AlertStatus::New->value,
            'assigned_to_user_id' => null,
            ...$attributes,
        ]);
    }

    /**
     * An alert delegated to $officer (Carol by default), not yet acknowledged.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function assignedAlert(?User $officer = null, array $attributes = []): Alert
    {
        return $this->alert([
            'assigned_to_user_id' => ($officer ?? $this->officer)->id,
            'status' => AlertStatus::Assigned->value,
            ...$attributes,
        ]);
    }

    /**
     * A received Trade inquiry at Berlin, with an "HQ Notified" event logged
     * at $notifiedAt unless $notified is false.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function inquiry(array $attributes = [], bool $notified = true, ?string $notifiedAt = null): Inquiry
    {
        $inquiry = Inquiry::factory()->create([
            'ministry_id' => $this->trade->id,
            'mission_id' => $this->berlin->id,
            'logged_by_user_id' => $this->berlinAttache->id,
            'status' => InquiryStatus::Received->value,
            'product_or_sector' => null,
            'date_received' => '2026-09-20',
            ...$attributes,
        ]);

        if ($notified) {
            $event = InquiryEvent::factory()->create([
                'inquiry_id' => $inquiry->id,
                'event_type' => 'hq_notified',
                'note' => 'For HQ awareness.',
                'logged_by_user_id' => $inquiry->logged_by_user_id,
            ]);

            if ($notifiedAt !== null) {
                $event->forceFill(['created_at' => Carbon::parse($notifiedAt)])->save();
            }
        }

        return $inquiry;
    }

    /**
     * An issued directive from Carol to the Berlin attache, fresh progress
     * and no target date, unless overridden.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function directive(array $attributes = []): Directive
    {
        return Directive::factory()->create([
            'ministry_id' => $this->trade->id,
            'mission_id' => $this->berlin->id,
            'target_user_id' => $this->berlinAttache->id,
            'issued_by_user_id' => $this->officer->id,
            'type_category' => null,
            'target_completion_date' => null,
            'status' => DirectiveStatus::Issued->value,
            'last_progress_update_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * A submitted, on-time Q1 2026 report from Berlin unless overridden.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function report(array $attributes = []): PeriodicReport
    {
        return PeriodicReport::factory()->create([
            'ministry_id' => $this->trade->id,
            'mission_id' => $this->berlin->id,
            'authored_by_user_id' => $this->berlinAttache->id,
            'reporting_period_label' => 'Q1 2026',
            'period_start_date' => '2026-07-01',
            'period_end_date' => '2026-09-30',
            'status' => PeriodicReportStatus::Submitted->value,
            'submitted_at' => '2026-10-10 09:00:00',
            'is_late' => false,
            ...$attributes,
        ]);
    }
}
