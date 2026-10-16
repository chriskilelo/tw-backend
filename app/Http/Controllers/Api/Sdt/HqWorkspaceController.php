<?php

namespace App\Http\Controllers\Api\Sdt;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Sdt\HqWorkspaceAlertsRequest;
use App\Http\Requests\Api\Sdt\HqWorkspaceDirectivesRequest;
use App\Http\Requests\Api\Sdt\HqWorkspaceInquiriesRequest;
use App\Http\Requests\Api\Sdt\HqWorkspaceOverviewRequest;
use App\Http\Requests\Api\Sdt\HqWorkspaceReportsRequest;
use App\Http\Requests\Api\Sdt\HqWorkspaceRequest;
use App\Services\HqWorkspaceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * FR-SDT-012 to 015: the HQ Trade Officer's workspace (App\Services\
 * HqWorkspaceService). Ministry HQ Officer only: every request class
 * authorises MinistryPolicy::viewHqWorkspace() before it validates, so any
 * other role gets 403 whatever it sends. The workspace only reads; acting on
 * a row (acknowledging an alert, closing a directive) goes through that
 * engine's own endpoint and policy.
 */
class HqWorkspaceController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly HqWorkspaceService $workspace) {}

    public function show(HqWorkspaceOverviewRequest $request): JsonResponse
    {
        return $this->respondWithData($this->workspace->overview($request->user()->loadMissing('ministry'), $request->filters()));
    }

    public function alerts(HqWorkspaceAlertsRequest $request): JsonResponse
    {
        return $this->respondWithPage($request, $this->workspace->alerts($request->user(), $request->filters()));
    }

    public function inquiries(HqWorkspaceInquiriesRequest $request): JsonResponse
    {
        return $this->respondWithPage($request, $this->workspace->inquiries($request->user(), $request->filters()));
    }

    public function directives(HqWorkspaceDirectivesRequest $request): JsonResponse
    {
        return $this->respondWithPage($request, $this->workspace->directives($request->user(), $request->filters()));
    }

    public function reports(HqWorkspaceReportsRequest $request): JsonResponse
    {
        return $this->respondWithPage($request, $this->workspace->reports($request->user(), $request->filters()));
    }

    /**
     * The page, with the scope actually applied in meta (a requested
     * "portfolio" falls back to "department" when none is configured).
     */
    private function respondWithPage(HqWorkspaceRequest $request, LengthAwarePaginator $page): JsonResponse
    {
        return $this->respondWithData($page->items(), meta: [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
            'scope' => $this->workspace->context($request->user(), $request->filters()['scope'])['scope'],
        ]);
    }
}
