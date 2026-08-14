<?php

namespace App\Http\Controllers\Api\Directives;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Directives\StoreDirectiveNoteRequest;
use App\Http\Requests\Api\Directives\StoreDirectiveRequest;
use App\Http\Requests\Api\Directives\UpdateDirectiveStatusRequest;
use App\Http\Resources\DirectiveDetailResource;
use App\Http\Resources\DirectiveResource;
use App\Models\Directive;
use App\Services\DirectiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * API-001 Section 8 (Directive and Tasking Engine). Thin controller: every
 * mutation is delegated to DirectiveService (CLAUDE.md Section 11);
 * ministry scoping is applied automatically by the ministry.scope route
 * middleware plus the Directive model's global scope.
 */
class DirectiveController extends Controller
{
    use ApiResponds;

    private const array DETAIL_RELATIONS = ['mission', 'targetUser', 'issuedBy', 'notes.authoredBy'];

    public function __construct(private readonly DirectiveService $directiveService) {}

    /**
     * FR-DIR-005, FR-DIR-009: Ministry Attache sees only directives
     * targeting them; Ministry HQ Officer sees only directives they
     * issued; Ministry PS and Ministry HQ Director see every directive in
     * the ministry (read-only for Directors, enforced by BasePolicy).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Directive::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $directives = Directive::query()
            ->with(['mission', 'targetUser', 'issuedBy'])
            ->when($request->user()->role?->name === 'Ministry Attache', fn ($query) => $query->where('target_user_id', $request->user()->id))
            ->when($request->user()->role?->name === 'Ministry HQ Officer', fn ($query) => $query->where('issued_by_user_id', $request->user()->id))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('mission_id'), fn ($query) => $query->where('mission_id', $request->string('mission_id')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->string('date_to')))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return $this->respondWithData(
            DirectiveResource::collection($directives->items()),
            meta: [
                'current_page' => $directives->currentPage(),
                'per_page' => $directives->perPage(),
                'total' => $directives->total(),
                'last_page' => $directives->lastPage(),
            ],
        );
    }

    public function store(StoreDirectiveRequest $request): JsonResponse
    {
        Gate::authorize('create', Directive::class);

        $directive = $this->directiveService->issueDirective($request->validated(), $request->user());

        return $this->respondWithData(new DirectiveResource($directive->load(['mission', 'targetUser', 'issuedBy'])), 201);
    }

    public function show(Directive $directive): JsonResponse
    {
        Gate::authorize('view', $directive);

        return $this->respondWithData(new DirectiveDetailResource($directive->load(self::DETAIL_RELATIONS)));
    }

    public function updateStatus(UpdateDirectiveStatusRequest $request, Directive $directive): JsonResponse
    {
        Gate::authorize('transitionStatus', $directive);

        try {
            $this->directiveService->transitionStatus(
                $directive,
                $request->validated('status'),
                $request->user(),
                $request->validated('note'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new DirectiveDetailResource($directive->fresh(self::DETAIL_RELATIONS)));
    }

    public function storeNote(StoreDirectiveNoteRequest $request, Directive $directive): JsonResponse
    {
        Gate::authorize('addNote', $directive);

        $note = $this->directiveService->addNote($directive, $request->validated('content'), $request->user());

        return $this->respondWithData([
            'id' => $note->id,
            'content' => $note->content,
            'authored_by_user_id' => $note->authored_by_user_id,
            'created_at' => $note->created_at,
        ], 201);
    }

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewSummary', Directive::class);

        return $this->respondWithData($this->directiveService->getSummary($request->user()->ministry_id));
    }
}
