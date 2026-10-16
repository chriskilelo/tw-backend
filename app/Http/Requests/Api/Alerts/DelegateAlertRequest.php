<?php

namespace App\Http\Requests\Api\Alerts;

use App\Enums\UserStatus;
use App\Http\Requests\Api\FormRequest;
use App\Models\Alert;
use App\Models\User;
use App\Policies\AlertPolicy;
use Illuminate\Validation\Validator;

/**
 * FR-ALERT-007, FR-SDT-002: the primary recipient delegates an alert to one
 * or more HQ users, with an optional note. A delegate must be an active
 * director or HQ trade officer of the alert's own department
 * (AlertPolicy::DELEGATE_ROLES): the first delegate becomes the alert's
 * assignee and sees it in their HQ workspace (FR-SDT-013), so an account of
 * another department, a deactivated account or an attache is refused
 * rather than handed the alert. AlertPolicy::delegate() is checked before
 * validation, so a role that may not delegate gets 403 whatever it sends.
 */
class DelegateAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        $alert = $this->route('alert');

        return $alert instanceof Alert && ($this->user()?->can('delegate', $alert) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'delegate_user_ids' => ['required', 'array', 'min:1', 'max:20'],
            'delegate_user_ids.*' => ['string', 'uuid', 'distinct'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $alert = $this->route('alert');
                $ids = $this->input('delegate_user_ids');

                if (! $alert instanceof Alert || ! is_array($ids) || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $eligible = User::query()
                    ->whereIn('id', $ids)
                    ->where('ministry_id', $alert->ministry_id)
                    ->where('status', UserStatus::Active->value)
                    ->whereHas('role', fn ($query) => $query->whereIn('name', AlertPolicy::DELEGATE_ROLES))
                    ->count();

                if ($eligible !== count($ids)) {
                    $validator->errors()->add('delegate_user_ids', 'Each delegate must be an active director or HQ trade officer of this department.');
                }
            },
        ];
    }
}
