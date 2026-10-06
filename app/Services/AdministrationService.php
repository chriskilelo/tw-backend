<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Jobs\SendAccountActivationEmail;
use App\Models\Ministry;
use App\Models\Role;
use App\Models\User;
use App\Policies\BasePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use InvalidArgumentException;

/**
 * ADR-006: two-tier administration. A System Administrator administers the
 * whole platform; a Ministry Administrator administers exactly one
 * department (one `ministries` row) and never sees operational records
 * (BR-025). Every rule that depends on which tier an actor belongs to lives
 * here, so controllers, requests and services all apply the same answer.
 *
 * Seat rules (BR-026, BR-028, BR-029) must be checked inside the caller's
 * DB::transaction() after lockMinistry() / lockSystemAdministrators(), so two
 * concurrent requests can never both pass the same count.
 */
class AdministrationService
{
    public const string SYSTEM_ADMINISTRATOR = 'System Administrator';

    public const string MINISTRY_ADMINISTRATOR = 'Ministry Administrator';

    public const string MINISTRY_PS = 'Ministry PS';

    /**
     * BR-028: at most three Ministry Administrators per department.
     */
    public const int MAX_MINISTRY_ADMINISTRATORS = 3;

    /**
     * FR-AUTH-021: the only roles a Ministry Administrator may assign. Ministry
     * PS goes through the approval workflow (BR-027); Acting PS only through
     * its switch (FR-SDT-004); administrator and mission-governance roles are
     * reserved to the System Administrator.
     *
     * @var array<int, string>
     */
    public const array MINISTRY_ADMIN_ASSIGNABLE_ROLES = [
        'Ministry Attache',
        'Ministry HQ Officer',
        'Ministry HQ Director',
        'Ministry Publishing Authority',
        'HRM&D Officer',
        'Designated Deputy',
    ];

    public function __construct(private readonly AuditService $auditService) {}

    public static function isSystemAdministrator(?User $user): bool
    {
        return $user?->role?->name === self::SYSTEM_ADMINISTRATOR;
    }

    public static function isMinistryAdministrator(?User $user): bool
    {
        return $user?->role?->name === self::MINISTRY_ADMINISTRATOR;
    }

    /**
     * Whether $actor may administer records belonging to $ministryId. A null
     * $ministryId means a platform-wide record, which only a System
     * Administrator may administer.
     */
    public static function canAdministerMinistry(User $actor, ?string $ministryId): bool
    {
        if (self::isSystemAdministrator($actor)) {
            return true;
        }

        return self::isMinistryAdministrator($actor)
            && $ministryId !== null
            && $actor->ministry_id === $ministryId;
    }

    /**
     * The four BR-020 governance roles (Head and Deputy Head of Mission, MFA
     * HQ Officer, MFA Principal Secretary) are Ministry of Foreign and
     * Diaspora Affairs officers, never a department's accounts (TW-ARCH-001
     * Section 8.1, "Ministry-Specific: No"), and a Ministry Administrator may
     * not assign them (FR-AUTH-021 AC2).
     */
    public static function isMissionGovernanceAccount(?User $user): bool
    {
        return in_array($user?->role?->name, BasePolicy::READ_ONLY_ROLES, true);
    }

    /**
     * Whether $actor administers $target's account at all: every account for
     * a System Administrator; for a Ministry Administrator, its own
     * department's accounts other than a governance account, whatever that
     * account's ministry_id holds (FR-AUTH-021 AC2, BR-025).
     */
    public static function canAdministerAccount(User $actor, User $target): bool
    {
        if (self::isSystemAdministrator($actor)) {
            return true;
        }

        return self::canAdministerMinistry($actor, $target->ministry_id)
            && ! self::isMissionGovernanceAccount($target);
    }

    /**
     * The department a create/update request from $actor applies to: a
     * Ministry Administrator is always pinned to its own department (a
     * different, explicitly requested one is rejected rather than silently
     * rewritten); anyone else must name one.
     */
    public function resolveTargetMinistryId(User $actor, ?string $requestedMinistryId): string
    {
        if (self::isMinistryAdministrator($actor)) {
            if ($requestedMinistryId !== null && $requestedMinistryId !== $actor->ministry_id) {
                throw new InvalidArgumentException('A Ministry Administrator can only administer its own department.');
            }

            return $actor->ministry_id;
        }

        if ($requestedMinistryId === null) {
            throw new InvalidArgumentException('A ministry_id is required.');
        }

        return $requestedMinistryId;
    }

    /**
     * The ministry_id a list query from $actor must be restricted to, or null
     * when the actor may see every department (System Administrator).
     */
    public static function listingMinistryId(User $actor, ?string $requestedMinistryId): ?string
    {
        if (self::isMinistryAdministrator($actor)) {
            return $actor->ministry_id;
        }

        return $requestedMinistryId;
    }

    public static function canAssignRole(User $actor, Role $role): bool
    {
        if (self::isSystemAdministrator($actor)) {
            return true;
        }

        return self::isMinistryAdministrator($actor)
            && in_array($role->name, self::MINISTRY_ADMIN_ASSIGNABLE_ROLES, true);
    }

    /**
     * Row-locks the department so seat counts taken afterwards in the same
     * transaction are stable.
     */
    public function lockMinistry(string $ministryId): Ministry
    {
        return Ministry::query()->whereKey($ministryId)->lockForUpdate()->firstOrFail();
    }

    /**
     * BR-028. $excluding is the user being moved into the role, so an update
     * that leaves a sitting administrator in place is not counted twice.
     */
    public function assertMinistryAdministratorCapacity(string $ministryId, ?User $excluding = null): void
    {
        $count = $this->countHoldersInMinistry(self::MINISTRY_ADMINISTRATOR, $ministryId, $excluding);

        if ($count >= self::MAX_MINISTRY_ADMINISTRATORS) {
            throw new InvalidArgumentException('This department already has the maximum of '.self::MAX_MINISTRY_ADMINISTRATORS.' Ministry Administrators (BR-028).');
        }
    }

    /**
     * BR-026: at most one Ministry PS per department. Acting PS is a separate
     * role and is not counted.
     */
    public function assertPsVacancy(string $ministryId, ?User $excluding = null): void
    {
        if ($this->countHoldersInMinistry(self::MINISTRY_PS, $ministryId, $excluding) > 0) {
            throw new InvalidArgumentException('This department already has a Principal Secretary. Use a PS succession request to replace them (BR-026).');
        }
    }

    public function currentPs(string $ministryId): ?User
    {
        return User::query()
            ->where('ministry_id', $ministryId)
            ->whereHas('role', fn ($query) => $query->where('name', self::MINISTRY_PS))
            ->where('status', '!=', UserStatus::Deactivated)
            ->first();
    }

    /**
     * BR-029: the platform must never be left without an active System
     * Administrator. Called before deactivating or demoting one.
     */
    public function assertNotLastSystemAdministrator(User $user): void
    {
        if (! self::isSystemAdministrator($user)) {
            return;
        }

        $remaining = User::query()
            ->whereHas('role', fn ($query) => $query->where('name', self::SYSTEM_ADMINISTRATOR))
            ->where('status', UserStatus::Active)
            ->whereKeyNot($user->id)
            ->lockForUpdate()
            ->get(['id'])
            ->count(); // Postgres rejects FOR UPDATE on an aggregate, so count in PHP.

        if ($remaining === 0) {
            throw new InvalidArgumentException('The last active System Administrator cannot be deactivated or reassigned (BR-029).');
        }
    }

    /**
     * FR-AUTH-004, NFR-DATA-003: the single deactivation path shared by
     * Admin\UserController and PsApprovalService — status change, soft
     * delete (authorship is preserved, BR-002), session revocation, audit.
     */
    public function deactivateUser(User $user, ?User $actor, ?string $ipAddress = null): void
    {
        $user->forceFill(['status' => UserStatus::Deactivated])->save();
        $user->delete();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->auditService->record($actor, 'user.deactivated', User::class, $user->id, null, $ipAddress, $user->ministry_id);
    }

    /**
     * FR-AUTH-002: activation reuses the password-reset broker token (Session 7).
     * $createdBy, when given, signs the email (FR-AUTH-025).
     */
    public function sendActivationEmail(User $user, ?User $createdBy = null): void
    {
        $token = Password::broker('users')->createToken($user);

        SendAccountActivationEmail::dispatch($user->email, $token, $createdBy ? self::signOff($createdBy) : null);
    }

    /**
     * FR-AUTH-025: "Jane Doe — {display title}, {department}" for email
     * sign-offs. Falls back to the role name when no display title is set,
     * and to the platform name when the person has no department.
     */
    public static function signOff(User $user): string
    {
        $title = $user->role?->display_title ?? $user->role?->name;
        $department = $user->ministry?->name ?? $user->homeMinistry?->name ?? config('app.name');

        return trim("{$user->full_name} — {$title}, {$department}", ' —,');
    }

    private function countHoldersInMinistry(string $roleName, string $ministryId, ?User $excluding): int
    {
        return User::query()
            ->where('ministry_id', $ministryId)
            ->whereHas('role', fn ($query) => $query->where('name', $roleName))
            ->where('status', '!=', UserStatus::Deactivated)
            ->when($excluding !== null, fn ($query) => $query->whereKeyNot($excluding->id))
            ->count();
    }
}
