<?php

use App\Enums\UserStatus;
use App\Models\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Sdt\HqWorkspaceWorld;

/**
 * FR-INQ-009 ("Notify HQ on Configured Events") with FR-SDT-012/013: when
 * an attache logs the "HQ Notified" event, the HQ officers whose portfolio
 * covers the inquiry are told in-app (every active HQ officer of the
 * department when none does), and the inquiry joins their workspace queue.
 */
beforeEach(function () {
    Queue::fake();
    $this->world = HqWorkspaceWorld::create();
});

/**
 * @return array<int, string>
 */
function hqNotifiedRecipients(): array
{
    return Notification::query()->where('trigger_type', 'inquiry_hq_notified')->pluck('recipient_user_id')->all();
}

it('tells only the HQ officers whose portfolio covers the inquiry (TC-FR-INQ-009-A)', function () {
    $world = $this->world;
    $inquiry = $world->inquiry([], false);

    $this->actingAs($world->berlinAttache)
        ->postJson("/api/v1/inquiries/{$inquiry->id}/events", ['event_type' => 'hq_notified', 'note' => 'Large buyer'])
        ->assertCreated();

    $notification = Notification::query()->where('trigger_type', 'inquiry_hq_notified')->sole();
    expect($notification->recipient_user_id)->toBe($world->officer->id)
        ->and($notification->link)->toBe("/inquiries/{$inquiry->id}")
        ->and($notification->message)->toContain($inquiry->reference_number)
        ->and($notification->message)->toContain('Berlin');

    $rows = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/inquiries')->json('data');
    expect(collect($rows)->pluck('id')->all())->toBe([$inquiry->id])
        ->and($rows[0]['hq_notified']['note'])->toBe('Large buyer');
});

it('tells every active HQ officer of the department when no portfolio covers it (TC-FR-INQ-009-B)', function () {
    $world = $this->world;
    $inquiry = $world->inquiry(['mission_id' => $world->accra->id, 'logged_by_user_id' => $world->accraAttache->id], false);
    $inactive = HqWorkspaceWorld::user('Ministry HQ Officer', $world->trade, null, ['status' => UserStatus::Deactivated]);

    $this->actingAs($world->accraAttache)
        ->postJson("/api/v1/inquiries/{$inquiry->id}/events", ['event_type' => 'hq_notified'])
        ->assertCreated();

    expect(hqNotifiedRecipients())->toEqualCanonicalizing([$world->officer->id, $world->otherOfficer->id])
        ->not->toContain($inactive->id)
        ->not->toContain($world->miningOfficer->id);
});

it('sends nothing for any other operational event (TC-FR-INQ-009-C)', function () {
    $world = $this->world;
    $inquiry = $world->inquiry([], false);

    $this->actingAs($world->berlinAttache)
        ->postJson("/api/v1/inquiries/{$inquiry->id}/events", ['event_type' => 'feedback_received'])
        ->assertCreated();

    expect(hqNotifiedRecipients())->toBe([]);
});
