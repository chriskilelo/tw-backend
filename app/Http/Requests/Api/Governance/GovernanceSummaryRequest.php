<?php

namespace App\Http\Requests\Api\Governance;

use App\Http\Requests\Api\FormRequest;
use App\Support\KpiPeriod;
use Closure;

/**
 * The filters every governance summary accepts: a department (FR-HOM-001
 * AC3; TW-ARCH-001 Section 5.4, "Ministry filter") and a fiscal quarter
 * ("Q1 2026", CLAUDE.md Section 8). Each endpoint reads only the filters it
 * supports. A malformed value is a 422, never passed on to a query.
 * Authorisation stays with the controller (MissionPolicy).
 */
class GovernanceSummaryRequest extends FormRequest
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
            'ministry_id' => ['sometimes', 'nullable', 'uuid'],
            'period' => ['sometimes', 'nullable', 'string', self::quarterLabelRule()],
        ];
    }

    /**
     * A fiscal quarter label such as "Q1 2026" (Q1 = July to September,
     * labelled by its start month's calendar year).
     */
    public static function quarterLabelRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/^Q[1-4] \d{4}$/', $value) !== 1 || ! KpiPeriod::isLabel($value)) {
                $fail('The :attribute must be a fiscal quarter such as "Q1 2026".');
            }
        };
    }
}
