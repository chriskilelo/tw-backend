<?php

use App\Enums\ApprovalRequestStatus;
use App\Enums\UserStatus;
use App\Jobs\SendAccountActivationEmail;
use App\Models\Alert;
use App\Models\ApprovalRequest;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Support\Facades\Queue;

/**
 * FR-AUTH-022 (PS appointment/promotion), FR-AUTH-023 (PS deactivation and
 * succession), BR-026 (one PS per department), BR-027 (System Administrator
 * approval). ADR-006.
 */
function approvalRole(string $name, string $layer = '2', string $scope = 'ministry'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function approvalUser(string $roleName, ?Ministry $ministry = null, string $scope = 'ministry'): User
{
    return User::factory()->create([
        'role_id' => approvalRole($roleName, '2', $scope)->id,
        'ministry_id' => $ministry?->id,
    ]);
}

function approvalSystemAdministrator(): User
{
    return approvalUser('System Administrator', null, 'platform');
}

beforeEach(function () {
    Queue::fake();
    approvalRole('Ministry PS');
    $this->department = Ministry::factory()->create();
    $this->ministryAdministrator = approvalUser('Ministry Administrator', $this->department);
    $this->systemAdministrator = approvalSystemAdministrator();
});

it('creates no account until a PS appointment is approved (TC-FR-AUTH-022-A)', function () {
    $response = $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-appointment', [
        'full_name' => 'Regina Ombam',
        'email' => 'regina.ombam@example.test',
    ])->assertCreated()->assertJsonPath('data.status', 'pending');

    expect(User::where('email', 'regina.ombam@example.test')->exists())->toBeFalse();
    expect(Notification::where('recipient_user_id', $this->systemAdministrator->id)->where('trigger_type', 'approval_requested')->exists())->toBeTrue();

    $this->actingAs($this->systemAdministrator)->postJson("/api/v1/approval-requests/{$response->json('data.id')}/approve")
        ->assertOk()->assertJsonPath('data.status', 'approved');

    $ps = User::where('email', 'regina.ombam@example.test')->first();
    expect($ps->role->name)->toBe('Ministry PS');
    expect($ps->ministry_id)->toBe($this->department->id);
    expect($ps->status)->toBe(UserStatus::ActivationPending);
    Queue::assertPushed(SendAccountActivationEmail::class, fn ($job) => $job->email === 'regina.ombam@example.test');
    expect(Notification::where('recipient_user_id', $this->ministryAdministrator->id)->where('trigger_type', 'approval_decided')->exists())->toBeTrue();
});

it('promotes an existing department account on approval (TC-FR-AUTH-022-B)', function () {
    $officer = approvalUser('Ministry HQ Officer', $this->department);

    $id = $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-promotion', ['user_id' => $officer->id])
        ->assertCreated()->json('data.id');

    expect($officer->fresh()->role->name)->toBe('Ministry HQ Officer');

    $this->actingAs($this->systemAdministrator)->postJson("/api/v1/approval-requests/{$id}/approve")->assertOk();

    expect($officer->fresh()->role->name)->toBe('Ministry PS');
});

it('rejects an appointment while the department already has a PS (TC-FR-AUTH-022-C)', function () {
    approvalUser('Ministry PS', $this->department);

    $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-appointment', [
        'full_name' => 'Second PS',
        'email' => 'second.ps@example.test',
    ])->assertStatus(422);
});

it('re-checks the one-PS rule at approval time (TC-FR-AUTH-022-D)', function () {
    $id = $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-appointment', [
        'full_name' => 'Late PS',
        'email' => 'late.ps@example.test',
    ])->assertCreated()->json('data.id');

    // A PS is appointed directly by a System Administrator in the meantime.
    approvalUser('Ministry PS', $this->department);

    $this->actingAs($this->systemAdministrator)->postJson("/api/v1/approval-requests/{$id}/approve")->assertStatus(422);

    expect(ApprovalRequest::find($id)->status)->toBe(ApprovalRequestStatus::Pending);
    expect(User::where('email', 'late.ps@example.test')->exists())->toBeFalse();
});

it('deactivates the PS only once approved (TC-FR-AUTH-023-A)', function () {
    $ps = approvalUser('Ministry PS', $this->department);

    $id = $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-deactivation', ['user_id' => $ps->id])
        ->assertCreated()->json('data.id');

    expect($ps->fresh()->status)->toBe(UserStatus::Active);

    $this->actingAs($this->systemAdministrator)->postJson("/api/v1/approval-requests/{$id}/approve")->assertOk();

    expect(User::withTrashed()->find($ps->id)->status)->toBe(UserStatus::Deactivated);
});

it('swaps the PS in one step so alert routing is never left without a PS (TC-FR-AUTH-023-B)', function () {
    $outgoing = approvalUser('Ministry PS', $this->department);
    $incoming = approvalUser('Ministry HQ Officer', $this->department);

    $id = $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-succession', [
        'outgoing_user_id' => $outgoing->id,
        'incoming_user_id' => $incoming->id,
    ])->assertCreated()->json('data.id');

    $this->actingAs($this->systemAdministrator)->postJson("/api/v1/approval-requests/{$id}/approve")->assertOk();

    expect(User::withTrashed()->find($outgoing->id)->status)->toBe(UserStatus::Deactivated);
    expect($incoming->fresh()->role->name)->toBe('Ministry PS');

    $attache = approvalUser('Ministry Attache', $this->department, 'mission');
    $attache->forceFill(['mission_id' => Mission::factory()->create()->id])->save();

    $alert = app(AlertService::class)->submitAlert([
        'country' => 'Kenya',
        'intelligence_type' => 'opportunities',
    ], $attache);

    expect(Notification::where('recipient_user_id', $incoming->id)->where('trigger_type', 'alert_routed')->exists())->toBeTrue();
    expect($alert)->toBeInstanceOf(Alert::class);
});

it('can bring in a brand-new account as the successor (TC-FR-AUTH-023-C)', function () {
    $outgoing = approvalUser('Ministry PS', $this->department);

    $id = $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-succession', [
        'outgoing_user_id' => $outgoing->id,
        'incoming_full_name' => 'New Principal Secretary',
        'incoming_email' => 'new.ps@example.test',
    ])->assertCreated()->json('data.id');

    $this->actingAs($this->systemAdministrator)->postJson("/api/v1/approval-requests/{$id}/approve")->assertOk();

    expect(User::where('email', 'new.ps@example.test')->first()->role->name)->toBe('Ministry PS');
    Queue::assertPushed(SendAccountActivationEmail::class, fn ($job) => $job->email === 'new.ps@example.test');
});

it('rejects with a mandatory reason and changes nothing (TC-FR-AUTH-022-E)', function () {
    $officer = approvalUser('Ministry HQ Officer', $this->department);
    $id = $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-promotion', ['user_id' => $officer->id])->json('data.id');

    $this->actingAs($this->systemAdministrator)->postJson("/api/v1/approval-requests/{$id}/reject")->assertStatus(422);
    $this->actingAs($this->systemAdministrator)->postJson("/api/v1/approval-requests/{$id}/reject", ['reason' => 'Not the nominated candidate.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_reason', 'Not the nominated candidate.');

    expect($officer->fresh()->role->name)->toBe('Ministry HQ Officer');
    $this->actingAs($this->systemAdministrator)->postJson("/api/v1/approval-requests/{$id}/approve")->assertStatus(422);
});

it('allows one pending request per department, which the requester may cancel (TC-FR-AUTH-022-F)', function () {
    $officer = approvalUser('Ministry HQ Officer', $this->department);
    $id = $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-promotion', ['user_id' => $officer->id])->json('data.id');

    $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-appointment', [
        'full_name' => 'Competing PS',
        'email' => 'competing@example.test',
    ])->assertStatus(422);

    $this->actingAs($this->ministryAdministrator)->postJson("/api/v1/approval-requests/{$id}/cancel")
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-appointment', [
        'full_name' => 'Next PS',
        'email' => 'next.ps@example.test',
    ])->assertCreated();
});

it('lets only a System Administrator decide, and only a Ministry Administrator request (TC-FR-AUTH-022-G)', function () {
    $officer = approvalUser('Ministry HQ Officer', $this->department);
    $id = $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-promotion', ['user_id' => $officer->id])->json('data.id');

    $this->actingAs($this->ministryAdministrator)->postJson("/api/v1/approval-requests/{$id}/approve")->assertForbidden();
    $this->actingAs(approvalUser('Ministry PS', Ministry::factory()->create()))->postJson('/api/v1/approval-requests/ps-promotion', ['user_id' => $officer->id])->assertForbidden();
    $this->actingAs($this->systemAdministrator)->postJson('/api/v1/approval-requests/ps-promotion', ['user_id' => $officer->id])->assertForbidden();
});

it('keeps each department\'s requests invisible to other departments (TC-FR-AUTH-022-H)', function () {
    $officer = approvalUser('Ministry HQ Officer', $this->department);
    $id = $this->actingAs($this->ministryAdministrator)->postJson('/api/v1/approval-requests/ps-promotion', ['user_id' => $officer->id])->json('data.id');
    $otherAdministrator = approvalUser('Ministry Administrator', Ministry::factory()->create());

    $this->actingAs($otherAdministrator)->getJson("/api/v1/approval-requests/{$id}")->assertNotFound();
    expect($this->actingAs($otherAdministrator)->getJson('/api/v1/approval-requests')->json('data'))->toBe([]);
    $this->actingAs($otherAdministrator)->postJson('/api/v1/approval-requests/ps-promotion', ['user_id' => $officer->id])->assertNotFound();
    expect(collect($this->actingAs($this->systemAdministrator)->getJson('/api/v1/approval-requests')->json('data'))->pluck('id'))->toContain($id);
});
