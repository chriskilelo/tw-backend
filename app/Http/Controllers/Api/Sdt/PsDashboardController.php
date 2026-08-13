<?php

namespace App\Http\Controllers\Api\Sdt;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Ministry;
use App\Services\SdtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * FR-SDT-001: consolidated PS dashboard. Ministry PS only (Acting PS too,
 * via App\Policies\MinistryPolicy::viewPsDashboard()). Ministry scoping is
 * applied automatically by the ministry.scope route middleware plus the
 * Alert/Inquiry/Directive models' global scope (CLAUDE.md Section 4, Rule 1).
 */
class PsDashboardController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly SdtService $sdtService) {}

    public function show(): JsonResponse
    {
        Gate::authorize('viewPsDashboard', Ministry::class);

        return $this->respondWithData($this->sdtService->psDashboard());
    }
}
