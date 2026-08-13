<?php

namespace App\Models\Concerns;

use App\Models\Scopes\MinistryScope;

/**
 * Marks a Layer 2 model as ministry-scoped (CLAUDE.md Section 4, Rule 1).
 *
 * By default assumes a direct `ministry_id` column. Models without one
 * (child tables reached only through a parent) must override
 * ministryScopeColumn() to return null and ministryScopeRelation() to
 * return the relation (dot-path for nested relations) that leads to a
 * ministry_id column.
 */
trait HasMinistryScope
{
    protected static function bootHasMinistryScope(): void
    {
        static::addGlobalScope(new MinistryScope);
    }

    public function ministryScopeColumn(): ?string
    {
        return 'ministry_id';
    }

    public function ministryScopeRelation(): ?string
    {
        return null;
    }
}
