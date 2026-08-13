<?php

use App\Models\AuditLog;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('throws when an update is attempted on an existing audit log (TC-FR-AUDIT-003)', function () {
    $log = new AuditLog([
        'action' => 'alert.created',
        'affected_entity_type' => 'alert',
    ]);
    $log->id = (string) Str::uuid();
    $log->exists = true;

    expect(fn () => $log->save())->toThrow(RuntimeException::class);
});
