<?php

namespace App\Models\Concerns;

use RuntimeException;

/**
 * CLAUDE.md Section 4, Rule 3: audit_logs, inquiry_notes, directive_notes,
 * and alert_versions are immutable at the model layer. Any save() on an
 * already-persisted row is an update attempt and must be rejected, since
 * these tables have no updated_at column to begin with.
 */
trait IsAppendOnly
{
    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new RuntimeException(class_basename($this).' records are append-only and cannot be updated.');
        }

        return parent::save($options);
    }
}
