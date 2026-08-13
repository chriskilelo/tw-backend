<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;
use App\Models\Role;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * FR-AUTH-001: a System Administrator creates a new account by specifying
 * full name, email, role, and either a mission (Ministry Attache, Head of
 * Mission, Deputy Head of Mission — the mission-scoped Layer 1/2 roles per
 * CLAUDE.md Section 6 users table) or a ministry affiliation (every other
 * role except the platform-scoped roles, which carry neither).
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

                if (in_array($role->name, self::MISSION_SCOPED_ROLES, true) && ! $this->filled('mission_id')) {
                    $validator->errors()->add('mission_id', 'A mission assignment is required for this role.');
                }

                if (
                    ! in_array($role->name, [...self::MISSION_SCOPED_ROLES, ...self::PLATFORM_SCOPED_ROLES], true)
                    && ! $this->filled('ministry_id')
                ) {
                    $validator->errors()->add('ministry_id', 'A ministry or department affiliation is required for this role.');
                }
            },
        ];
    }
}
