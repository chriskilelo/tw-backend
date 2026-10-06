<?php

namespace App\Http\Controllers\Api\Inquiries;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Inquiries\CloseInquiryRequest;
use App\Http\Requests\Api\Inquiries\LinkInquiryRequest;
use App\Http\Requests\Api\Inquiries\StoreInquiryEventRequest;
use App\Http\Requests\Api\Inquiries\StoreInquiryNoteRequest;
use App\Http\Requests\Api\Inquiries\StoreInquiryRequest;
use App\Http\Requests\Api\Inquiries\UpdateInquiryRequest;
use App\Http\Requests\Api\Inquiries\UpdateInquiryStatusRequest;
use App\Http\Resources\InquiryDetailResource;
use App\Http\Resources\InquiryResource;
use App\Models\Inquiry;
use App\Services\AuditService;
use App\Services\InquiryMatchingService;
use App\Services\InquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * API-001 Section 7 (Inquiry and Case Tracker Engine). Thin controller:
 * every mutation is delegated to InquiryService (CLAUDE.md Section 11);
 * ministry scoping is applied automatically by the ministry.scope route
 * middleware plus the Inquiry model's global scope.
 */
class InquiryController extends Controller
{
    use ApiResponds;

    private const array DETAIL_RELATIONS = [
        'mission',
        'loggedBy',
        'notes.authoredBy',
        'events.loggedBy',
        'linkedInquiry.mission',
        'referralEntries.referralOrganisation',
        'referralEntries.createdBy',
        'referralEntries.attachments',
    ];

    public function __construct(
        private readonly InquiryService $inquiryService,
        private readonly InquiryMatchingService $inquiryMatchingService,
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Inquiry::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $inquiries = Inquiry::query()
            ->visibleTo($request->user())
            ->with(['mission', 'loggedBy'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('high_value_flag'), fn ($query) => $query->where('high_value_flag', $request->boolean('high_value_flag')))
            ->when($request->filled('mission_id'), fn ($query) => $query->where('mission_id', $request->string('mission_id')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('date_received', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('date_received', '<=', $request->string('date_to')))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return $this->respondWithData(
            InquiryResource::collection($inquiries->items()),
            meta: [
                'current_page' => $inquiries->currentPage(),
                'per_page' => $inquiries->perPage(),
                'total' => $inquiries->total(),
                'last_page' => $inquiries->lastPage(),
            ],
        );
    }

    public function store(StoreInquiryRequest $request): JsonResponse
    {
        Gate::authorize('create', Inquiry::class);

        $inquiry = $this->inquiryService->logInquiry($request->validated(), $request->user());

        return $this->respondWithData(new InquiryResource($inquiry->load(['mission', 'loggedBy'])), 201);
    }

    public function show(Request $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('view', $inquiry);

        $this->auditService->recordOversightAccess($request->user(), Inquiry::class, $inquiry->id, $inquiry->ministry_id, $request->ip());

        return $this->respondWithData(new InquiryDetailResource($inquiry->load(self::DETAIL_RELATIONS)));
    }

    public function update(UpdateInquiryRequest $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('update', $inquiry);

        $inquiry->forceFill($request->validated())->save();

        return $this->respondWithData(new InquiryDetailResource($inquiry->fresh(self::DETAIL_RELATIONS)));
    }

    public function updateStatus(UpdateInquiryStatusRequest $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('transitionStatus', $inquiry);

        try {
            $this->inquiryService->transitionStatus($inquiry, $request->validated('status'), $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new InquiryDetailResource($inquiry->fresh(self::DETAIL_RELATIONS)));
    }

    public function storeEvent(StoreInquiryEventRequest $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('logEvent', $inquiry);

        $this->inquiryService->logEvent(
            $inquiry,
            $request->validated('event_type'),
            $request->user(),
            $request->validated('note'),
        );

        return $this->respondWithData(new InquiryDetailResource($inquiry->fresh(self::DETAIL_RELATIONS)), 201);
    }

    public function storeNote(StoreInquiryNoteRequest $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('addNote', $inquiry);

        $note = $this->inquiryService->addNote($inquiry, $request->validated('content'), $request->user());

        return $this->respondWithData([
            'id' => $note->id,
            'content' => $note->content,
            'authored_by_user_id' => $note->authored_by_user_id,
            'created_at' => $note->created_at,
        ], 201);
    }

    public function close(CloseInquiryRequest $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('close', $inquiry);

        try {
            $this->inquiryService->closeInquiry($inquiry, $request->validated('resolution_summary'), $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new InquiryDetailResource($inquiry->fresh(self::DETAIL_RELATIONS)));
    }

    /**
     * FR-INQ-019. Omitting target_inquiry_id returns suggested cross-mission
     * matches (AC1, InquiryMatchingService::findMatches()) without
     * persisting anything; supplying it confirms and persists a symmetric
     * link (AC2, InquiryService::linkInquiry()) — one endpoint serving both
     * halves of the requirement, since API-001 Section 7 defines only the
     * single POST /inquiries/{id}/link route.
     */
    public function link(LinkInquiryRequest $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('link', $inquiry);

        $targetId = $request->validated('target_inquiry_id');

        if ($targetId === null) {
            $matches = $this->inquiryMatchingService->findMatches($inquiry);

            return $this->respondWithData(InquiryResource::collection($matches));
        }

        $target = Inquiry::findOrFail($targetId);

        try {
            $this->inquiryService->linkInquiry($inquiry, $target, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new InquiryDetailResource($inquiry->fresh(self::DETAIL_RELATIONS)));
    }
}
