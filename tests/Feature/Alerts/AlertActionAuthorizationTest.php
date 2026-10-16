<?php

use App\Enums\AlertStatus;
use App\Enums\UserStatus;
use App\Models\AlertFeedback;
use App\Models\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Sdt\HqWorkspaceWorld;

/**
 * FR-ALERT-007, 009, 010 and FR-SDT-002/013: who may acknowledge an alert,
 * post feedback on it, or receive it by delegation. These are the actions
 * the HQ workspace offers on its alert rows, so they are record-level: an
 * HQ officer acts only on an alert assigned to them (or as the active
 * designated deputy), an attache only on their own alert, and a delegate is
 * always an active director or HQ officer of the alert's own department.
 */
beforeEach(function () {
    Queue::fake();
    $this->world = HqWorkspaceWorld::create();
});

// --- Acknowledge (FR-ALERT-009) -----------------------------------------------

it('lets the assigned HQ officer acknowledge an alert, and nobody else at HQ level (TC-FR-ALERT-009-A)', function () {
    $world = $this->world;
    $alert = $world->assignedAlert();

    $this->actingAs($world->otherOfficer)->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertForbidden();
    $this->actingAs($world->director)->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertForbidden();
    $this->actingAs($world->berlinAttache)->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertForbidden();
    expect($alert->fresh()->status)->toBe(AlertStatus::Assigned);

    $this->actingAs($world->officer)->postJson("/api/v1/alerts/{$alert->id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.status', 'acknowledged');
});

it('refuses an HQ officer acknowledging a routed alert nobody assigned to them (TC-FR-ALERT-009-B)', function () {
    $alert = $this->world->alert();

    $this->actingAs($this->world->officer)->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertForbidden();
});

it('lets the PS acknowledge any alert of the department as primary recipient (TC-FR-ALERT-009-C)', function () {
    $alert = $this->world->alert();

    $this->actingAs($this->world->ps)->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertOk();
});

it('lets the department\'s active designated deputy acknowledge a routed alert, and only while active (TC-FR-ALERT-008-A)', function () {
    $world = $this->world;
    $alert = $world->alert();
    $world->trade->forceFill(['designated_deputy_user_id' => $world->otherOfficer->id, 'designated_deputy_active' => false])->save();

    $this->actingAs($world->otherOfficer)->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertForbidden();

    $world->trade->forceFill(['designated_deputy_active' => true])->save();
    $this->actingAs($world->otherOfficer)->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertOk();
});

it('acknowledges once: a repeat neither changes the alert nor notifies the attache again (TC-FR-ALERT-009-D)', function () {
    $world = $this->world;
    $alert = $world->assignedAlert();

    $this->actingAs($world->officer)->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertOk();
    $this->actingAs($world->officer)->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertOk()->assertJsonPath('data.status', 'acknowledged');

    expect(Notification::query()->where('recipient_user_id', $world->berlinAttache->id)->where('trigger_type', 'alert_acknowledged')->count())->toBe(1);
});

it('never lets an officer of another department reach the alert at all (TC-NFR-SEC-006-ALT)', function () {
    $alert = $this->world->assignedAlert();

    $this->actingAs($this->world->miningOfficer)->postJson("/api/v1/alerts/{$alert->id}/acknowledge")->assertNotFound();
    $this->actingAs($this->world->miningOfficer)->postJson("/api/v1/alerts/{$alert->id}/feedback", ['content' => 'x'])->assertNotFound();
});

// --- Feedback (FR-ALERT-010) ------------------------------------------------------

it('lets the assigned officer, the PS and the submitting attache post feedback, and refuses everyone else (TC-FR-ALERT-010-A)', function () {
    $world = $this->world;
    $alert = $world->assignedAlert();

    foreach ([$world->officer, $world->ps, $world->berlinAttache] as $poster) {
        $this->actingAs($poster)->postJson("/api/v1/alerts/{$alert->id}/feedback", ['content' => "From {$poster->full_name}"])->assertCreated();
    }

    foreach ([$world->otherOfficer, $world->director, $world->accraAttache] as $outsider) {
        $this->actingAs($outsider)->postJson("/api/v1/alerts/{$alert->id}/feedback", ['content' => 'Not mine'])->assertForbidden();
    }

    expect(AlertFeedback::query()->where('alert_id', $alert->id)->count())->toBe(3);
});

it('refuses feedback before validating it, and bounds its length (TC-FR-ALERT-010-B)', function () {
    $world = $this->world;
    $alert = $world->assignedAlert();

    $this->actingAs($world->otherOfficer)->postJson("/api/v1/alerts/{$alert->id}/feedback", ['content' => ''])->assertForbidden();
    $this->actingAs($world->officer)->postJson("/api/v1/alerts/{$alert->id}/feedback", ['content' => ''])->assertUnprocessable();
    $this->actingAs($world->officer)->postJson("/api/v1/alerts/{$alert->id}/feedback", ['content' => str_repeat('a', 2001)])->assertUnprocessable();
    $this->actingAs($world->officer)->postJson("/api/v1/alerts/{$alert->id}/feedback", ['content' => str_repeat('a', 2000)])->assertCreated();
});

// --- Delegation (FR-ALERT-007, FR-SDT-002) ----------------------------------------

it('delegates to an active HQ officer or director of the alert\'s own department (TC-FR-SDT-002-A)', function () {
    $world = $this->world;
    $alert = $world->alert();

    $this->actingAs($world->ps)
        ->postJson("/api/v1/alerts/{$alert->id}/delegate", ['delegate_user_ids' => [$world->officer->id, $world->director->id]])
        ->assertOk()
        ->assertJsonPath('data.status', 'assigned');

    expect($alert->fresh()->assigned_to_user_id)->toBe($world->officer->id);
});

it('refuses to delegate to an account outside the department, an attache, or a deactivated account (TC-FR-SDT-002-B)', function (string $who) {
    $world = $this->world;
    $alert = $world->alert();
    $world->otherOfficer->forceFill(['status' => UserStatus::Deactivated])->save();
    $delegate = match ($who) {
        'another department' => $world->miningOfficer,
        'an attache' => $world->berlinAttache,
        'a deactivated officer' => $world->otherOfficer,
        'the PS' => $world->ps,
    };

    $this->actingAs($world->ps)
        ->postJson("/api/v1/alerts/{$alert->id}/delegate", ['delegate_user_ids' => [$delegate->id]])
        ->assertUnprocessable();

    expect($alert->fresh()->assigned_to_user_id)->toBeNull();
})->with(['another department', 'an attache', 'a deactivated officer', 'the PS']);

it('refuses a duplicated or malformed delegate list (TC-FR-SDT-002-C)', function () {
    $world = $this->world;
    $alert = $world->alert();

    $this->actingAs($world->ps)->postJson("/api/v1/alerts/{$alert->id}/delegate", ['delegate_user_ids' => [$world->officer->id, $world->officer->id]])->assertUnprocessable();
    $this->actingAs($world->ps)->postJson("/api/v1/alerts/{$alert->id}/delegate", ['delegate_user_ids' => ['not-a-uuid']])->assertUnprocessable();
    $this->actingAs($world->ps)->postJson("/api/v1/alerts/{$alert->id}/delegate", ['delegate_user_ids' => []])->assertUnprocessable();
});

it('refuses a role that may not delegate before validating what it sent (TC-FR-ALERT-007-B)', function () {
    $alert = $this->world->alert();

    $this->actingAs($this->world->officer)
        ->postJson("/api/v1/alerts/{$alert->id}/delegate", ['delegate_user_ids' => ['not-a-uuid']])
        ->assertForbidden();
});

// --- What the alert detail offers each user ---------------------------------------

it('returns the requesting user\'s allowed actions with the alert detail (TC-FR-ALERT-009-E)', function (string $who, array $expected) {
    $world = $this->world;
    $alert = $world->assignedAlert();
    $viewer = match ($who) {
        'assigned officer' => $world->officer,
        'other officer' => $world->otherOfficer,
        'PS' => $world->ps,
        'submitting attache' => $world->berlinAttache,
        'other attache' => $world->accraAttache,
        'director' => $world->director,
    };

    $this->actingAs($viewer)->getJson("/api/v1/alerts/{$alert->id}")->assertOk()->assertJsonPath('data.allowed_actions', $expected);
})->with([
    ['assigned officer', ['edit' => false, 'delegate' => false, 'acknowledge' => true, 'post_feedback' => true]],
    ['other officer', ['edit' => false, 'delegate' => false, 'acknowledge' => false, 'post_feedback' => false]],
    ['PS', ['edit' => false, 'delegate' => true, 'acknowledge' => true, 'post_feedback' => true]],
    ['submitting attache', ['edit' => true, 'delegate' => false, 'acknowledge' => false, 'post_feedback' => true]],
    ['other attache', ['edit' => false, 'delegate' => false, 'acknowledge' => false, 'post_feedback' => false]],
    ['director', ['edit' => false, 'delegate' => false, 'acknowledge' => false, 'post_feedback' => false]],
]);

it('stops offering acknowledgement once the alert is acknowledged (TC-FR-ALERT-009-F)', function () {
    $alert = $this->world->assignedAlert(null, ['status' => AlertStatus::Acknowledged->value]);

    $this->actingAs($this->world->officer)->getJson("/api/v1/alerts/{$alert->id}")->assertOk()->assertJsonPath('data.allowed_actions.acknowledge', false);
});
