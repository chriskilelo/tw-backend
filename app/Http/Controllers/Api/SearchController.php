<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-SEARCH-001 to 004 (Knowledge Search Engine). No dedicated policy: any
 * authenticated user may search within their own ministry-scoped access
 * boundary — the same open-to-any-authenticated-user pattern as
 * MeController/NotificationController — since SearchService's results are
 * already constrained by the ministry.scope middleware and each queried
 * model's global scope (CLAUDE.md Section 4, Rule 1).
 */
class SearchController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly SearchService $searchService) {}

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:1', 'max:255']]);

        return $this->respondWithData(
            $this->searchService->search($request->string('q')->value(), $request->user())
        );
    }

    public function countryProfile(Request $request, string $country): JsonResponse
    {
        return $this->respondWithData(
            $this->searchService->countryProfile($country, $request->user())
        );
    }
}
