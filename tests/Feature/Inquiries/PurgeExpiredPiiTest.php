<?php

use App\Enums\InquiryStatus;
use App\Models\AuditLog;
use App\Models\Inquiry;
use App\Models\Ministry;
use App\Models\Mission;
use Illuminate\Support\Facades\Artisan;

/**
 * CLAUDE.md Section 11, 14 / session task "NFR-DATA-002: Data retention
 * for inquirer PII" — tw:purge-pii (App\Console\Commands\PurgeExpiredPii,
 * InquiryService::purgeExpiredPii()).
 */
it('redacts inquirer PII on closed inquiries older than 12 months and logs each purge to audit_logs (TC-NFR-DATA-002)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();

    $qualifying = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'status' => InquiryStatus::Closed->value,
        'closed_at' => now()->subMonths(13),
        'inquirer_name' => 'Jane Trader',
        'inquirer_email' => 'jane@example.com',
        'inquirer_phone' => '+254700000000',
        'inquirer_organisation' => 'Acme Exports',
    ]);

    $recentlyClosed = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'status' => InquiryStatus::Closed->value,
        'closed_at' => now()->subMonths(6),
        'inquirer_name' => 'John Buyer',
        'inquirer_email' => 'john@example.com',
    ]);

    $stillOpen = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'status' => InquiryStatus::InProgress->value,
        'closed_at' => null,
        'inquirer_name' => 'Open Case',
    ]);

    Artisan::call('tw:purge-pii');

    $qualifying->refresh();
    expect($qualifying->inquirer_name)->toBe('[redacted]');
    expect($qualifying->inquirer_email)->toBeNull();
    expect($qualifying->inquirer_phone)->toBeNull();
    // Non-PII fields are preserved for aggregate/statistical use.
    expect($qualifying->inquirer_organisation)->toBe('Acme Exports');

    $recentlyClosed->refresh();
    expect($recentlyClosed->inquirer_name)->toBe('John Buyer');

    $stillOpen->refresh();
    expect($stillOpen->inquirer_name)->toBe('Open Case');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'inquiry.pii_purged',
        'affected_entity_type' => Inquiry::class,
        'affected_entity_id' => $qualifying->id,
    ]);
});

it('is idempotent: re-running the purge does not re-touch an already redacted inquiry', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();

    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'status' => InquiryStatus::Closed->value,
        'closed_at' => now()->subMonths(24),
        'inquirer_name' => 'Jane Trader',
    ]);

    Artisan::call('tw:purge-pii');
    Artisan::call('tw:purge-pii');

    $auditCount = AuditLog::query()
        ->where('action', 'inquiry.pii_purged')
        ->where('affected_entity_id', $inquiry->id)
        ->count();

    expect($auditCount)->toBe(1);
});
