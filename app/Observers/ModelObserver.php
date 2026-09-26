<?php

namespace App\Observers;

use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * CLAUDE.md Section 15 / FR-AUDIT-001, 002: writes an audit_logs entry for
 * every create/update/delete on the models it is registered against
 * (AppServiceProvider::boot()). Resolves AuditService via app() rather than
 * a constructor dependency, since Eloquent boots observers into the
 * container very early and a constructor-injected service risks resolving
 * before the rest of the container is ready (session 09 task 3).
 */
class ModelObserver
{
    public function created(Model $model): void
    {
        $this->log($model, 'created', $this->redact($model, $model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->redact($model, $model->getChanges());
        unset($changes['updated_at']);

        if ($changes === []) {
            return;
        }

        $this->log($model, 'updated', $changes);
    }

    public function deleted(Model $model): void
    {
        $this->log($model, 'deleted', null);
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    private function log(Model $model, string $event, ?array $changes): void
    {
        $request = request();

        app(AuditService::class)->record(
            $request->user(),
            Str::snake(class_basename($model)).'.'.$event,
            $model::class,
            (string) $model->getKey(),
            $changes,
            $request->ip(),
            $this->ministryIdOf($model),
        );
    }

    /**
     * FR-AUDIT-006: the department the mutated record belongs to, when it
     * carries one directly; otherwise AuditService falls back to the actor's.
     */
    private function ministryIdOf(Model $model): ?string
    {
        return array_key_exists('ministry_id', $model->getAttributes()) ? $model->getAttribute('ministry_id') : null;
    }

    /**
     * Strips attributes the model itself marks $hidden (e.g. User's
     * password hash) before they ever reach the audit trail.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function redact(Model $model, array $attributes): array
    {
        return array_diff_key($attributes, array_flip($model->getHidden()));
    }
}
