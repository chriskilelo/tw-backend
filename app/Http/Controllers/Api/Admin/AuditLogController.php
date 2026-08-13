<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * FR-AUDIT-005: System Administrator only (AuditLogPolicy::viewAny).
 * Filterable by user_id, action, and a created_at date range (from/to);
 * unrecognised query parameters are ignored per CLAUDE.md Section 10.
 */
class AuditLogController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $logs = AuditLog::query()
            ->with('user')
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->string('user_id')))
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return $this->respondWithData(
            AuditLogResource::collection($logs->items()),
            meta: [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        );
    }
}
