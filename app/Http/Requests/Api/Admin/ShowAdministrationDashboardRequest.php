<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;

/**
 * GET /api/v1/admin/dashboard. `ministry` narrows a System Administrator's
 * view to one department; a Ministry Administrator is always pinned to its
 * own (AdministrationService::listingMinistryId()), so the value is ignored.
 */
class ShowAdministrationDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'ministry' => ['sometimes', 'nullable', 'uuid', 'exists:ministries,id'],
        ];
    }
}
