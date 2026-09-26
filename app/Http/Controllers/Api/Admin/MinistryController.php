<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreMinistryRequest;
use App\Models\Ministry;
use App\Services\AdministrationService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * ADR-006: the department registry. A System Administrator lists every
 * department and onboards new ones; a Ministry Administrator sees only its
 * own. Departments are never deleted (BR-003), so there is no destroy.
 */
class MinistryController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Ministry::class);

        $actor = $request->user();

        $ministries = Ministry::query()
            ->when(AdministrationService::isMinistryAdministrator($actor), fn ($query) => $query->whereKey($actor->ministry_id))
            ->orderBy('name')
            ->get(['id', 'name', 'active']);

        return $this->respondWithData($ministries->map(fn (Ministry $ministry) => $this->present($ministry)));
    }

    public function store(StoreMinistryRequest $request): JsonResponse
    {
        Gate::authorize('create', Ministry::class);

        $ministry = Ministry::create(['name' => $request->validated('name'), 'active' => true]);

        $this->auditService->record($request->user(), 'ministry.created', 'ministry', $ministry->id, ['name' => $ministry->name], $request->ip(), $ministry->id);

        return $this->respondWithData($this->present($ministry), 201);
    }

    /**
     * @return array{id: string, name: string, active: bool}
     */
    private function present(Ministry $ministry): array
    {
        return [
            'id' => $ministry->id,
            'name' => $ministry->name,
            'active' => (bool) $ministry->active,
        ];
    }
}
