<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;
use App\Models\Role;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Services\AdministrationService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * FR-AUTH-001: a System Administrator creates a new account by specifying
 * full name, email, role, and either a mission (Ministry Attache, Head of
 * Mission, Deputy Head of Mission — the mission-scoped Layer 1/2 roles per
 * CLAUDE.md Section 6 users table) or a ministry affiliation (every other
 * role except the platform-scoped roles, which carry neither).
 *
 * ADR-006 / FR-AUTH-021: a Ministry Administrator may only assign the roles
 * in AdministrationService::MINISTRY_ADMIN_ASSIGNABLE_ROLES, always within
 * its own department (the controller pins ministry_id); a new Principal
 * Secretary goes through an approval request instead (BR-027).
 * home_ministry_id is a display-only affiliation for System Administrators.
 */
class StoreUserRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    public const array MISSION_SCOPED_ROLES = ['Ministry Attache', 'Head of Mission', 'Deputy Head of Mission'];

    /**
     * @var array<int, string>
     */
    public const array PLATFORM_SCOPED_ROLES = ['System Administrator', 'MFA HQ Officer', 'MFA Principal Secretary'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role_id' => ['required', 'uuid', Rule::exists('roles', 'id')],
            'mission_id' => ['nullable', 'uuid', Rule::exists('missions', 'id')],
            'ministry_id' => ['nullable', 'uuid', Rule::exists('ministries', 'id')],
            'home_ministry_id' => ['nullable', 'uuid', Rule::exists('ministries', 'id')],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('role_id')) {
                    return;
                }

                $role = Role::find($this->input('role_id'));

                if ($role === null) {
                    return;
                }

                $actor = $this->user();
                $isMinistryAdministrator = AdministrationService::isMinistryAdministrator($actor);

                self::validateAdministrativeScope($validator, $actor, $role, $this->input('ministry_id'), $this->input('home_ministry_id'));

                if (in_array($role->name, self::MISSION_SCOPED_ROLES, true) && ! $this->filled('mission_id')) {
                    $validator->errors()->add('mission_id', 'A mission assignment is required for this role.');
                }

                if (
                    ! in_array($role->name, [...self::MISSION_SCOPED_ROLES, ...self::PLATFORM_SCOPED_ROLES], true)
                    && ! $this->filled('ministry_id')
                    && ! $isMinistryAdministrator
                ) {
                    $validator->errors()->add('ministry_id', 'A ministry or department affiliation is required for this role.');
                }
            },
        ];
    }

    /**
     * Shared with UpdateUserRequest: the tier-dependent checks on which role
     * and department an account may be given.
     */
    public static function validateAdministrativeScope(Validator $validator, ?User $actor, Role $role, ?string $ministryId, ?string $homeMinistryId): void
    {
        if (AdministrationService::isMinistryAdministrator($actor)) {
            if ($role->name === AdministrationService::MINISTRY_PS) {
                $validator->errors()->add('role_id', 'Appointing a Principal Secretary requires System Administrator approval. Submit a PS appointment or promotion request instead (BR-027).');
            } elseif (! AdministrationService::canAssignRole($actor, $role)) {
                $validator->errors()->add('role_id', 'A Ministry Administrator cannot assign this role.');
            }

            if ($ministryId !== null && $ministryId !== $actor->ministry_id) {
                $validator->errors()->add('ministry_id', 'A Ministry Administrator can only manage accounts in its own department.');
            }
        }

        if ($homeMinistryId !== null && $role->name !== AdministrationService::SYSTEM_ADMINISTRATOR) {
            $validator->errors()->add('home_ministry_id', 'A home department can only be recorded for a System Administrator.');
        }

        self::validateNoDepartmentForGovernanceRole($validator, $role, $ministryId);
    }

    /**
     * TW-ARCH-001 Section 8.1: the Head and Deputy Head of Mission and the MFA
     * roles are not ministry-specific. A department on such an account would
     * put it within that department's Ministry Administrator's reach.
     */
    public static function validateNoDepartmentForGovernanceRole(Validator $validator, Role $role, ?string $ministryId): void
    {
        if ($ministryId !== null && in_array($role->name, BasePolicy::READ_ONLY_ROLES, true)) {
            $validator->errors()->add('ministry_id', 'Head of Mission, Deputy Head of Mission and MFA accounts are not affiliated with a department.');
        }
    }
}
