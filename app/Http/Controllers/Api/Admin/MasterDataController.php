<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreMasterDataEntryRequest;
use App\Http\Requests\Api\Admin\UpdateMasterDataEntryRequest;
use App\Models\MasterDataEntry;
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

        $entries = MasterDataEntry::query()
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
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
