<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\KpiDefinition;
use App\Models\KpiProfile;
use App\Models\MasterDataEntry;
use App\Models\MissionMinistryLink;
use App\Models\ReferralOrganisation;
use App\Models\ReportTemplateSection;
use App\Models\User;
use App\Services\AdministrationService;
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

    /**
     * Entity types a Ministry Administrator's audit view may include.
     *
     * @var array<int, string>
     */
    public const array ADMINISTRATIVE_ENTITY_TYPES = [
        User::class,
        MasterDataEntry::class,
        ReferralOrganisation::class,
        ReportTemplateSection::class,
        KpiDefinition::class,
        KpiProfile::class,
        MissionMinistryLink::class,
        ApprovalRequest::class,
        'ministry',
    ];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $actor = $request->user();

        $logs = AuditLog::query()
            // BR-002: deactivating a user never alters historical attribution,
            // so a soft-deleted actor must still resolve and be named here.
            ->with(['user' => fn ($query) => $query->withTrashed()])
            // FR-AUDIT-006 / BR-025: a Ministry Administrator sees only its own
            // department's administrative entries — never operational rows,
            // whose `changes` payloads carry alert/inquiry/report content.
            ->when(AdministrationService::isMinistryAdministrator($actor), fn ($query) => $query
                ->where('ministry_id', $actor->ministry_id)
                ->whereIn('affected_entity_type', self::ADMINISTRATIVE_ENTITY_TYPES))
            ->when($request->filled('ministry_id'), fn ($query) => $query->where('ministry_id', $request->string('ministry_id')))
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
