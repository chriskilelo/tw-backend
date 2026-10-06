<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Policies\BasePolicy;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-SEARCH-001 to 004 (Knowledge Search Engine). No dedicated policy: any
 * authenticated user may search within their own ministry-scoped access
 * boundary — the same open-to-any-authenticated-user pattern as
 * MeController/NotificationController — since SearchService's results are
 * already constrained by the ministry.scope middleware and each queried
 * model's global scope (CLAUDE.md Section 4, Rule 1), plus visibleTo() for
 * the governance roles.
 *
 * The MFA roles are refused outright. Every search result carries a content
 * snippet and leads to the full record (FR-SEARCH-003), and those roles may
 * see neither (FR-MFA-001 AC2): the set of records they are authorised to
 * search is always empty, so a 403 says so instead of an endless "no
 * results".
 */
class SearchController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly SearchService $searchService) {}

    public function search(Request $request): JsonResponse
    {
        $this->refuseMfaRoles($request);

        $request->validate(['q' => ['required', 'string', 'min:1', 'max:255']]);

        return $this->respondWithData(
            $this->searchService->search($request->string('q')->value(), $request->user())
        );
    }

    public function countryProfile(Request $request, string $country): JsonResponse
    {
        $this->refuseMfaRoles($request);

        return $this->respondWithData(
            $this->searchService->countryProfile($country, $request->user())
        );
    }

    private function refuseMfaRoles(Request $request): void
    {
        abort_if(
            in_array($request->user()?->role?->name, BasePolicy::MFA_ROLES, true),
            403,
            'Knowledge search shows record content, which the MFA roles do not see (FR-MFA-001 AC2). Use the MFA awareness view.',
        );
    }
}
