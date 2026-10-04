<?php

namespace Tests\Feature\Directives;

use App\Enums\DirectiveStatus;
use App\Enums\UserStatus;
use App\Models\Directive;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\Role;
use App\Models\User;

/**
 * Shared fixture for the Directive and Tasking Engine suites: one ministry
 * with two linked, active missions, an attache posted at each, two HQ
 * officers, a PS and an HQ Director. Not a test file (Pest only collects
 * *Test.php), autoloaded through the Tests\ PSR-4 namespace.
 */
final class DirectiveWorld
{
    /**
     * Roles that carry no mission and no ministry (platform scoped).
     *
     * @var array<int, string>
     */
    private const array PLATFORM_ROLES = ['System Administrator', 'MFA HQ Officer', 'MFA Principal Secretary'];

    /**
     * Roles posted at a mission rather than a ministry.
     *
     * @var array<int, string>
     */
    private const array MISSION_ROLES = ['Head of Mission', 'Deputy Head of Mission', 'Honorary Consul'];

    public function __construct(
        public readonly Ministry $ministry,
        public readonly Mission $mission,
        public readonly Mission $otherMission,
        public readonly User $attache,
        public readonly User $otherAttache,
        public readonly User $officer,
        public readonly User $otherOfficer,
        public readonly User $ps,
        public readonly User $director,
    ) {}

    public static function create(): self
    {
        $ministry = Ministry::factory()->create();
        $mission = self::linkedMission($ministry, ['name' => 'Berlin']);
        $otherMission = self::linkedMission($ministry, ['name' => 'Accra']);

        return new self(
            $ministry,
            $mission,
            $otherMission,
            self::user('Ministry Attache', $ministry, $mission, ['full_name' => 'Amina Attache']),
            self::user('Ministry Attache', $ministry, $otherMission, ['full_name' => 'Brian Attache']),
            self::user('Ministry HQ Officer', $ministry, null, ['full_name' => 'Carol Officer']),
            self::user('Ministry HQ Officer', $ministry, null, ['full_name' => 'David Officer']),
            self::user('Ministry PS', $ministry, null, ['full_name' => 'Esther PS']),
            self::user('Ministry HQ Director', $ministry, null, ['full_name' => 'Frank Director']),
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
     * A user of $roleName placed the way that role is placed in production:
     * platform roles carry neither ministry nor mission, mission roles carry
     * this world's mission, every other role this world's ministry.
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
     * An issued directive from the officer to the attache at this world's
     * mission, fresh progress, no target date, unless overridden.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function directive(array $attributes = []): Directive
    {
        return Directive::factory()->create([
            'ministry_id' => $this->ministry->id,
            'mission_id' => $this->mission->id,
            'target_user_id' => $this->attache->id,
            'issued_by_user_id' => $this->officer->id,
            'type_category' => null,
            'target_completion_date' => null,
            'status' => DirectiveStatus::Issued->value,
            'last_progress_update_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function issueBody(array $overrides = []): array
    {
        return [
            'mission_id' => $this->mission->id,
            'target_user_id' => $this->attache->id,
            'description' => 'Compile the Q2 buyer-contact list for the Berlin food fair.',
            ...$overrides,
        ];
    }
}
