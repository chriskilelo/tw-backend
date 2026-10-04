<?php

namespace App\Http\Resources;

use App\Models\Directive;
use App\Models\DirectiveNote;
use App\Services\DirectiveService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /directives/{id} (and every mutation that returns the directive):
 * full detail, the derived flags (FR-DIR-003, FR-DIR-010), the dated status
 * history rebuilt from the audit trail, the notes oldest-first with their
 * kind, and allowed_actions for the REQUESTING user — computed from
 * DirectivePolicy and the state machine together, so the UI shows exactly
 * what the API accepts.
 *
 * @mixin Directive
 */
class DirectiveDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $directiveService = app(DirectiveService::class);

        return [
            'id' => $this->id,
            'type_category' => $this->type_category,
            'description' => $this->description,
            'status' => $this->status,
            'target_completion_date' => $this->target_completion_date,
            'completion_summary' => $this->completion_summary,
            'last_progress_update_at' => $this->last_progress_update_at,
            'is_overdue' => $this->isOverdue(),
            'is_stale' => $this->isStale(),
            'due_state' => $this->dueState(),
            'days_until_due' => $this->daysUntilDue(),
            'mission' => $this->whenLoaded('mission', fn () => [
                'id' => $this->mission->id,
                'name' => $this->mission->name,
            ]),
            'target_user' => $this->whenLoaded('targetUser', fn () => [
                'id' => $this->targetUser->id,
                'full_name' => $this->targetUser->full_name,
            ]),
            'issued_by' => $this->whenLoaded('issuedBy', fn () => [
                'id' => $this->issuedBy->id,
                'full_name' => $this->issuedBy->full_name,
            ]),
            'notes' => $this->whenLoaded('notes', fn () => $this->notes
                ->sortBy(fn (DirectiveNote $note) => $note->created_at?->getTimestamp())
                ->map(fn (DirectiveNote $note): array => self::presentNote($note, $this->resource))
                ->values()),
            'status_history' => $directiveService->statusHistory($this->resource),
            'allowed_actions' => $directiveService->allowedActions($this->resource, $request->user()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The embedded note shape, also returned by POST /directives/{id}/notes.
     * kind: progress (written by the target attache, FR-DIR-008), follow_up
     * (by the issuer, FR-DIR-011), or note (anyone else).
     *
     * @return array{id: string, content: string, kind: string, authored_by: array{id: string, full_name: string}|null, created_at: mixed}
     */
    public static function presentNote(DirectiveNote $note, Directive $directive): array
    {
        return [
            'id' => $note->id,
            'content' => $note->content,
            'kind' => match ($note->authored_by_user_id) {
                $directive->target_user_id => 'progress',
                $directive->issued_by_user_id => 'follow_up',
                default => 'note',
            },
            'authored_by' => $note->relationLoaded('authoredBy') && $note->authoredBy ? [
                'id' => $note->authoredBy->id,
                'full_name' => $note->authoredBy->full_name,
            ] : null,
            'created_at' => $note->created_at,
        ];
    }
}
