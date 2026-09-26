<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreMasterDataEntryRequest;
use App\Http\Requests\Api\Admin\UpdateMasterDataEntryRequest;
use App\Models\MasterDataEntry;
use App\Services\AdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * FR-MDATA-001, FR-MDATA-002 (API-001 Section 3, Master Data Service).
 * GET is available to any authenticated user; POST/PATCH are System
 * Administrator only, enforced via MasterDataPolicy.
 */
class MasterDataController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MasterDataEntry::class);

        $actor = $request->user();

        $entries = MasterDataEntry::query()
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            // ADR-006: a Ministry Administrator never sees another department's
            // lists — only its own and the platform-wide (no department) rows.
            ->when(AdministrationService::isMinistryAdministrator($actor), fn ($query) => $query->where(
                fn ($query) => $query->where('ministry_id', $actor->ministry_id)->orWhereNull('ministry_id'),
            ))
            ->when($request->filled('ministry_id'), fn ($query) => $query->where('ministry_id', $request->string('ministry_id')))
            ->when($request->filled('active'), fn ($query) => $query->where('active', $request->boolean('active')))
            ->orderBy('display_order')
            ->orderBy('value')
            ->get();

        return $this->respondWithData($entries->map(fn (MasterDataEntry $entry) => $this->present($entry)));
    }

    public function store(StoreMasterDataEntryRequest $request): JsonResponse
    {
        Gate::authorize('create', MasterDataEntry::class);

        $entry = MasterDataEntry::create($request->validated());

        return $this->respondWithData($this->present($entry), 201);
    }

    public function update(UpdateMasterDataEntryRequest $request, MasterDataEntry $masterDataEntry): JsonResponse
    {
        abort_unless(AdministrationService::canAdministerMinistry($request->user(), $masterDataEntry->ministry_id) || ! AdministrationService::isMinistryAdministrator($request->user()), 404);
        Gate::authorize('update', $masterDataEntry);

        $masterDataEntry->fill($request->validated())->save();

        return $this->respondWithData($this->present($masterDataEntry->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MasterDataEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'ministry_id' => $entry->ministry_id,
            'category' => $entry->category,
            'value' => $entry->value,
            'display_order' => $entry->display_order,
            'active' => $entry->active,
        ];
    }
}
