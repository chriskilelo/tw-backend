<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\RejectApprovalRequestRequest;
use App\Http\Requests\Api\Admin\StorePsAppointmentRequest;
use App\Http\Requests\Api\Admin\StorePsDeactivationRequest;
use App\Http\Requests\Api\Admin\StorePsPromotionRequest;
use App\Http\Requests\Api\Admin\StorePsSuccessionRequest;
use App\Http\Resources\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\AdministrationService;
use App\Services\PsApprovalService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * FR-AUTH-022/023, BR-027: Principal Secretary approval requests. Business
 * rules live in App\Services\PsApprovalService; this controller authorises,
 * resolves the named accounts, and maps rule violations to 422.
 *
 * ApprovalRequest carries the ministry global scope and these routes run
 * without the ministry.scope middleware, so the scope falls back to the
 * signed-in user's own department: a Ministry Administrator can never bind
 * another department's request (404), while a System Administrator (no
 * ministry_id) sees all of them.
 */
class ApprovalRequestController extends Controller
{
    use ApiResponds;

    private const array RELATIONS = ['ministry', 'requestedBy', 'subjectUser', 'decidedBy'];

    public function __construct(private readonly PsApprovalService $psApprovalService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ApprovalRequest::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $approvalRequests = ApprovalRequest::query()
            ->with(self::RELATIONS)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('ministry_id'), fn ($query) => $query->where('ministry_id', $request->string('ministry_id')))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return $this->respondWithData(
            ApprovalRequestResource::collection($approvalRequests->items()),
            meta: [
                'current_page' => $approvalRequests->currentPage(),
                'per_page' => $approvalRequests->perPage(),
                'total' => $approvalRequests->total(),
                'last_page' => $approvalRequests->lastPage(),
            ],
        );
    }

    public function show(ApprovalRequest $approvalRequest): JsonResponse
    {
        Gate::authorize('view', $approvalRequest);

        return $this->respondWithData(new ApprovalRequestResource($approvalRequest->load(self::RELATIONS)));
    }

    public function storeAppointment(StorePsAppointmentRequest $request): JsonResponse
    {
        Gate::authorize('create', ApprovalRequest::class);

        return $this->submit(fn () => $this->psApprovalService->requestAppointment(
            $request->user(),
            $request->validated('full_name'),
            $request->validated('email'),
        ));
    }

    public function storePromotion(StorePsPromotionRequest $request): JsonResponse
    {
        Gate::authorize('create', ApprovalRequest::class);

        $subject = $this->departmentAccount($request->user(), $request->validated('user_id'));

        return $this->submit(fn () => $this->psApprovalService->requestPromotion($request->user(), $subject));
    }

    public function storeDeactivation(StorePsDeactivationRequest $request): JsonResponse
    {
        Gate::authorize('create', ApprovalRequest::class);

        $subject = $this->departmentAccount($request->user(), $request->validated('user_id'));

        return $this->submit(fn () => $this->psApprovalService->requestDeactivation($request->user(), $subject));
    }

    public function storeSuccession(StorePsSuccessionRequest $request): JsonResponse
    {
        Gate::authorize('create', ApprovalRequest::class);

        $outgoing = $this->departmentAccount($request->user(), $request->validated('outgoing_user_id'));
        $incoming = $request->filled('incoming_user_id')
            ? $this->departmentAccount($request->user(), $request->validated('incoming_user_id'))
            : null;
        $incomingPerson = $incoming === null
            ? ['full_name' => $request->validated('incoming_full_name'), 'email' => $request->validated('incoming_email')]
            : null;

        return $this->submit(fn () => $this->psApprovalService->requestSuccession($request->user(), $outgoing, $incoming, $incomingPerson));
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        Gate::authorize('decide', $approvalRequest);

        return $this->decide(fn () => $this->psApprovalService->approve($approvalRequest, $request->user(), $request->ip()));
    }

    public function reject(RejectApprovalRequestRequest $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        Gate::authorize('decide', $approvalRequest);

        return $this->decide(fn () => $this->psApprovalService->reject($approvalRequest, $request->user(), $request->validated('reason')));
    }

    public function cancel(Request $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        Gate::authorize('cancel', $approvalRequest);

        return $this->decide(fn () => $this->psApprovalService->cancel($approvalRequest, $request->user()));
    }

    /**
     * IDOR guard: an account outside the requester's department is reported
     * as not found (users carry no ministry global scope).
     */
    private function departmentAccount(User $requester, string $userId): User
    {
        $user = User::query()->find($userId);

        abort_unless($user !== null && AdministrationService::canAdministerMinistry($requester, $user->ministry_id), 404);

        return $user;
    }

    /**
     * @param  Closure(): ApprovalRequest  $action
     */
    private function submit(Closure $action): JsonResponse
    {
        try {
            $approvalRequest = $action();
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new ApprovalRequestResource($approvalRequest->load(self::RELATIONS)), 201);
    }

    /**
     * @param  Closure(): ApprovalRequest  $action
     */
    private function decide(Closure $action): JsonResponse
    {
        try {
            $approvalRequest = $action();
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new ApprovalRequestResource($approvalRequest->load(self::RELATIONS)));
    }
}
