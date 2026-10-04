<?php

namespace App\Http\Controllers\Api\Directives;

use App\Enums\DirectiveStatus;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Directives\ReviseDirectiveRequest;
use App\Http\Requests\Api\Directives\StoreDirectiveNoteRequest;
use App\Http\Requests\Api\Directives\StoreDirectiveRequest;
use App\Http\Requests\Api\Directives\UpdateDirectiveStatusRequest;
use App\Http\Resources\DirectiveDetailResource;
use App\Http\Resources\DirectiveResource;
use App\Models\Directive;
use App\Services\DirectiveService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * API-001 Section 8 / URD Section 10.5 (Directive and Tasking Engine).
 * Thin controller: every mutation is delegated to DirectiveService
 * (CLAUDE.md Section 11); ministry scoping is applied automatically by the
 * ministry.scope route middleware plus the Directive model's global scope,
 * so another department's directive is a 404 at route binding.
 */
class DirectiveController extends Controller
{
    use ApiResponds;

    /**
     * People on a directive stay named after their account is deactivated
     * (BR-002: deactivation never alters historical attribution).
     *
     * @var array<int, string>
     */
    private const array PEOPLE = ['targetUser', 'issuedBy'];

    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private const array SORTS = [
        '-created_at' => ['created_at', 'desc'],
        'created_at' => ['created_at', 'asc'],
        'target_completion_date' => ['target_completion_date', 'asc'],
        '-target_completion_date' => ['target_completion_date', 'desc'],
        '-last_progress_update_at' => ['last_progress_update_at', 'desc'],
        'last_progress_update_at' => ['last_progress_update_at', 'asc'],
    ];

    public function __construct(private readonly DirectiveService $directiveService) {}

    /**
     * FR-DIR-005, FR-DIR-009: a Ministry Attache sees only directives that
     * target them; a Ministry HQ Officer only the ones they issued; the PS,
     * Acting PS, HQ Director and System Administrator the whole ministry.
     * Filters are optional and an unrecognised or invalid value is ignored
     * (CLAUDE.md Section 10), never an error. Besides the four due states of
     * a directive still being worked, `due` accepts `completed` (completed
     * or closed, matching the FR-DIR-012 summary's completed count) and
     * `cancelled`, so every summary figure has a matching drill-down.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Directive::class);

        $user = $request->user();
        $today = Carbon::today()->toDateString();
        $approachingUntil = Carbon::today()->addDays(DirectiveService::APPROACHING_WINDOW_DAYS)->toDateString();
        $status = DirectiveStatus::tryFrom($this->stringParam($request, 'status'));
        $missionId = $this->uuidParam($request, 'mission_id');
        $issuerId = $this->uuidParam($request, 'issued_by_user_id');
        $dateFrom = DirectiveService::validDate($request->query('date_from'));
        $dateTo = DirectiveService::validDate($request->query('date_to'));
        $search = Str::limit(trim($this->stringParam($request, 'q')), 200, '');
        [$sortColumn, $sortDirection] = self::SORTS[$this->stringParam($request, 'sort')] ?? self::SORTS['-created_at'];

        $directives = Directive::query()
            ->with(['mission', ...$this->peopleWithTrashed()])
            ->when($user->role?->name === 'Ministry Attache', fn (Builder $query) => $query->where('target_user_id', $user->id))
            ->when($user->role?->name === 'Ministry HQ Officer', fn (Builder $query) => $query->where('issued_by_user_id', $user->id))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status->value))
            ->when($missionId !== null, fn (Builder $query) => $query->where('mission_id', $missionId))
            ->when($issuerId !== null, fn (Builder $query) => $query->where('issued_by_user_id', $issuerId))
            ->when($dateFrom !== null, fn (Builder $query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo !== null, fn (Builder $query) => $query->whereDate('created_at', '<=', $dateTo))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $pattern = '%'.addcslashes($search, '\\%_').'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('description', 'ilike', $pattern)
                    ->orWhere('type_category', 'ilike', $pattern));
            })
            ->when(in_array($request->query('stale'), ['1', 'true'], true), fn (Builder $query) => $query
                ->whereIn('status', DirectiveStatus::openValues())
                ->where('last_progress_update_at', '<', now()->subDays(DirectiveService::STALE_THRESHOLD_DAYS)))
            ->when(is_string($request->query('due')), fn (Builder $query) => match ($request->query('due')) {
                Directive::DUE_OVERDUE => $this->stillBeingWorked($query)->where('target_completion_date', '<', $today),
                Directive::DUE_APPROACHING => $this->stillBeingWorked($query)->whereBetween('target_completion_date', [$today, $approachingUntil]),
                Directive::DUE_NO_DATE => $this->stillBeingWorked($query)->whereNull('target_completion_date'),
                Directive::DUE_ON_TRACK => $this->stillBeingWorked($query)->where('target_completion_date', '>', $approachingUntil),
                Directive::DUE_COMPLETED => $query->whereIn('status', [DirectiveStatus::Completed->value, DirectiveStatus::Closed->value]),
                Directive::DUE_CANCELLED => $query->where('status', DirectiveStatus::Cancelled->value),
                default => $query,
            })
            ->orderByRaw("{$sortColumn} {$sortDirection} nulls last")
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate($this->perPage($request))
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

    /**
     * The issue form's mission and attache pickers (FR-DIR-002).
     */
    public function assignees(Request $request): JsonResponse
    {
        Gate::authorize('listAssignees', Directive::class);

        return $this->respondWithData($this->directiveService->listAssignees($request->user()));
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

        return $this->respondWithData(new DirectiveDetailResource($directive->load($this->detailRelations())));
    }

    /**
     * FR-DIR-003 "optional and revisable": target date and type category
     * only, by the issuer, while open.
     */
    public function revise(ReviseDirectiveRequest $request, Directive $directive): JsonResponse
    {
        Gate::authorize('revise', $directive);

        try {
            $this->directiveService->reviseDirective($directive, $request->validated(), $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new DirectiveDetailResource($directive->fresh($this->detailRelations())));
    }

    public function updateStatus(UpdateDirectiveStatusRequest $request, Directive $directive): JsonResponse
    {
        $status = $request->validated('status');

        Gate::authorize('transitionStatus', [$directive, $status]);

        try {
            $this->directiveService->transitionStatus($directive, $status, $request->user(), $request->validated('note'));
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new DirectiveDetailResource($directive->fresh($this->detailRelations())));
    }

    public function storeNote(StoreDirectiveNoteRequest $request, Directive $directive): JsonResponse
    {
        Gate::authorize('addNote', $directive);

        $note = $this->directiveService->addNote($directive, $request->validated('content'), $request->user());

        return $this->respondWithData(
            DirectiveDetailResource::presentNote($note->load(['authoredBy' => fn ($query) => $query->withTrashed()]), $directive),
            201,
        );
    }

    /**
     * FR-DIR-012: the director summary for the actor's own ministry.
     */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewSummary', Directive::class);

        return $this->respondWithData($this->directiveService->getSummary(
            $request->user()->ministry_id,
            $request->only(['date_from', 'date_to', 'mission_id', 'issued_by_user_id']),
        ));
    }

    /**
     * @return array<string, \Closure>
     */
    private function peopleWithTrashed(): array
    {
        return collect(self::PEOPLE)
            ->mapWithKeys(fn (string $relation): array => [$relation => fn ($query) => $query->withTrashed()])
            ->all();
    }

    /**
     * @return array<string|int, mixed>
     */
    private function detailRelations(): array
    {
        return [
            'mission',
            ...$this->peopleWithTrashed(),
            'notes.authoredBy' => fn ($query) => $query->withTrashed(),
        ];
    }

    /**
     * The due-state filters apply to directives still being worked, the
     * same set Directive::dueState() dates (everything not completed,
     * closed or cancelled).
     */
    private function stillBeingWorked(Builder $query): Builder
    {
        return $query->whereNotIn('status', DirectiveStatus::finishedValues());
    }

    /**
     * A scalar query value, or '' for a missing or array-valued one
     * (?status[]=x must be ignored like any other invalid filter, not 500).
     */
    private function stringParam(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }

    private function uuidParam(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function perPage(Request $request): int
    {
        $value = $request->query('per_page');

        if (! is_string($value) || ! is_numeric($value)) {
            return 25;
        }

        return max(1, min(100, (int) $value));
    }
}
