<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Structural ministry isolation (CLAUDE.md Section 4, Rule 1; NFR-SEC-006).
 *
 * Applied via HasMinistryScope on every Layer 2 model. Models that carry a
 * direct ministry_id column are filtered on that column; models that only
 * reach a ministry through a parent relationship declare a relation path
 * instead, which is scoped via whereHas.
 */
class MinistryScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Prefer the value bound by App\Http\Middleware\MinistryScope, since
        // it also accounts for platform-scoped roles that bypass scoping by
        // role name (Session 5). Fall back to the Auth facade for contexts
        // the HTTP middleware never ran in, e.g. direct queries in tests.
        $ministryId = app()->bound('current_ministry_id')
            ? app('current_ministry_id')
            : Auth::user()?->ministry_id;

        if ($ministryId === null) {
            return;
        }

        if ($column = $model->ministryScopeColumn()) {
            $builder->where($model->qualifyColumn($column), $ministryId);

            return;
        }

        if ($relation = $model->ministryScopeRelation()) {
            $builder->whereHas($relation, function (Builder $query) use ($ministryId) {
                $query->where('ministry_id', $ministryId);
            });
        }
    }
}
