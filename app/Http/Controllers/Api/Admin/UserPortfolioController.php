<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\UpdateUserPortfolioRequest;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\User;
use App\Services\AdministrationService;
use App\Services\AuditService;
use App\Support\HqPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * FR-SDT-012: an HQ Trade Officer's configured portfolio of missions,
 * countries and sectors, which their HQ workspace defaults to. Configured
 * by the account's administrators (UserPolicy::managePortfolio(): a System
 * Administrator, or the department's Ministry Administrator) as part of the
 * department's configuration (ADR-006); the officer reads it in the
 * workspace. It is a view default, not an access rule.
 *
 * The same IDOR guard as Admin\UserController: an account outside the
 * actor's department is a 404, never a 403. A portfolio exists only for a
 * Ministry HQ Officer account in a department (422 otherwise).
 */
class UserPortfolioController extends Controller
{
    use ApiResponds;

    public const string HQ_OFFICER = 'Ministry HQ Officer';

    public function __construct(private readonly AuditService $auditService) {}

    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless(AdministrationService::canAdministerAccount($request->user(), $user), 404);
        Gate::authorize('managePortfolio', $user);

        if (! $this->holdsPortfolio($user)) {
            return $this->notAnHqOfficer();
        }

        return $this->respondWithData($this->present($user));
    }

    public function update(UpdateUserPortfolioRequest $request, User $user): JsonResponse
    {
        abort_unless(AdministrationService::canAdministerAccount($request->user(), $user), 404);
        Gate::authorize('managePortfolio', $user);

        if (! $this->holdsPortfolio($user)) {
            return $this->notAnHqOfficer();
        }

        $before = HqPortfolio::forUser($user)->toStorage();
        $after = HqPortfolio::fromInput(
            $request->validated('mission_ids'),
            $request->validated('countries'),
            $request->validated('sectors'),
        )->toStorage();

        if ($before !== $after) {
            $user->forceFill(['portfolio' => $after])->save();

            $this->auditService->record(
                $request->user(),
                'user.portfolio.updated',
                User::class,
                $user->id,
                ['before' => $before, 'after' => $after],
                $request->ip(),
                $user->ministry_id,
            );
        }

        return $this->respondWithData($this->present($user->fresh()));
    }

    private function holdsPortfolio(User $user): bool
    {
        return $user->role?->name === self::HQ_OFFICER && $user->ministry_id !== null;
    }

    private function notAnHqOfficer(): JsonResponse
    {
        return $this->respondWithErrors(['A portfolio applies to Ministry HQ Officer accounts in a department only.'], 422);
    }

    /**
     * The stored portfolio and the choices for the editor: the missions
     * posted to the officer's department, and their host countries.
     *
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        $portfolio = HqPortfolio::forUser($user);
        $missions = Mission::query()
            ->whereIn('id', MissionMinistryLink::query()->where('ministry_id', $user->ministry_id)->select('mission_id'))
            ->orderBy('name')
            ->get();

        return [
            'user' => ['id' => $user->id, 'full_name' => $user->full_name],
            'portfolio' => [
                'mission_ids' => $portfolio->missionIds,
                'countries' => $portfolio->countries,
                'sectors' => $portfolio->sectors,
            ],
            'options' => [
                'missions' => $missions->map(fn (Mission $mission): array => [
                    'id' => $mission->id,
                    'name' => $mission->name,
                    'city' => $mission->city,
                    'host_country' => $mission->host_country,
                    'active' => (bool) $mission->active,
                ])->values()->all(),
                'countries' => $missions->pluck('host_country')->filter()->unique()->sort()->values()->all(),
            ],
        ];
    }
}
