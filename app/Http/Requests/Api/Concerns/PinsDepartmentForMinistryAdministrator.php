<?php

namespace App\Http\Requests\Api\Concerns;

use App\Services\AdministrationService;
use Closure;

/**
 * ADR-006 / FR-MDATA-003: department configuration written by a Ministry
 * Administrator always belongs to its own department. When the request
 * omits ministry_id it is filled in; any other department is rejected, so
 * a Ministry Administrator can never create configuration elsewhere. A
 * System Administrator is unaffected and must still name the department.
 */
trait PinsDepartmentForMinistryAdministrator
{
    protected function prepareForValidation(): void
    {
        $actor = $this->user();

        if (AdministrationService::isMinistryAdministrator($actor) && ! $this->filled('ministry_id')) {
            $this->merge(['ministry_id' => $actor->ministry_id]);
        }
    }

    /**
     * @return array<int, mixed>
     */
    protected function departmentRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'uuid',
            'exists:ministries,id',
            function (string $attribute, mixed $value, Closure $fail): void {
                $actor = $this->user();

                if (AdministrationService::isMinistryAdministrator($actor) && $value !== $actor->ministry_id) {
                    $fail('A Ministry Administrator can only administer its own department.');
                }
            },
        ];
    }
}
