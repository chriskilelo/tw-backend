<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;
use App\Models\Role;
use App\Services\AdministrationService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edits profile and role (session 07 task 1). Mission/ministry scoping
 * requirements mirror StoreUserRequest, evaluated against the merged
 * result of the request payload and the existing user so a partial update
 * (e.g. role_id only) is still checked against the resulting state.
 *
 * ADR-006: the same tier-dependent checks as StoreUserRequest, plus BR-027 —
 * a Ministry Administrator cannot move an account into or out of the
 * Principal Secretary role; that is an approval request.
 */
class UpdateUserRequest extends FormRequest
{
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
            'full_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'role_id' => ['sometimes', 'uuid', Rule::exists('roles', 'id')],
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
                $user = $this->route('user');

                $roleId = $this->input('role_id', $user?->role_id);
                $role = $roleId ? Role::find($roleId) : null;

                if ($role === null) {
                    return;
                }

                $missionId = $this->has('mission_id') ? $this->input('mission_id') : $user?->mission_id;
                $ministryId = $this->has('ministry_id') ? $this->input('ministry_id') : $user?->ministry_id;
                $homeMinistryId = $this->has('home_ministry_id') ? $this->input('home_ministry_id') : $user?->home_ministry_id;
                $actor = $this->user();
                $roleChanges = $user !== null && $role->id !== $user->role_id;

                if (AdministrationService::isMinistryAdministrator($actor) && $roleChanges && $user->role?->name === AdministrationService::MINISTRY_PS) {
                    $validator->errors()->add('role_id', 'Removing a Principal Secretary requires System Administrator approval. Submit a PS deactivation or succession request instead (BR-027).');
                } elseif ($roleChanges) {
                    StoreUserRequest::validateAdministrativeScope($validator, $actor, $role, null, null);
                }

                if (AdministrationService::isMinistryAdministrator($actor) && $this->has('ministry_id') && $this->input('ministry_id') !== $actor->ministry_id) {
                    $validator->errors()->add('ministry_id', 'A Ministry Administrator cannot move an account out of its own department.');
                }

                if ($homeMinistryId !== null && $role->name !== AdministrationService::SYSTEM_ADMINISTRATOR) {
                    $validator->errors()->add('home_ministry_id', 'A home department can only be recorded for a System Administrator.');
                }

                // Only an explicitly requested department is refused: a stale one
                // already on the account is cleared by UserController::update().
                StoreUserRequest::validateNoDepartmentForGovernanceRole($validator, $role, $this->has('ministry_id') ? $this->input('ministry_id') : null);

                if (in_array($role->name, StoreUserRequest::MISSION_SCOPED_ROLES, true) && $missionId === null) {
                    $validator->errors()->add('mission_id', 'A mission assignment is required for this role.');
                }

                if (
                    ! in_array($role->name, [...StoreUserRequest::MISSION_SCOPED_ROLES, ...StoreUserRequest::PLATFORM_SCOPED_ROLES], true)
                    && $ministryId === null
                ) {
                    $validator->errors()->add('ministry_id', 'A ministry or department affiliation is required for this role.');
                }
            },
        ];
    }
}
