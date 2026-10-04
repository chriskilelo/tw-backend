<?php

use App\Jobs\SendDirectiveDueReminder;
use App\Jobs\SendDirectiveStaleReminder;
use App\Models\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Directives\DirectiveWorld;

/**
 * FR-DIR-010 (stale flag: target AND issuer, open statuses, once per stale
 * episode) and FR-DIR-004 (reminders 7 and 3 days before the target date).
 */
beforeEach(function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(8, 15));
});

function staleNotifications(): int
{
    return Notification::query()->where('trigger_type', 'directive_stale')->count();
}

it('flags every open status once to the target and the issuer, and nothing finished (TC-FR-DIR-010-A)', function () {
    $world = DirectiveWorld::create();
    $staleSince = now()->subDays(15);

    foreach (['issued', 'acknowledged', 'in_progress'] as $status) {
        $world->directive(['status' => $status, 'last_progress_update_at' => $staleSince]);
    }
    foreach (['completed', 'closed', 'cancelled'] as $status) {
        $world->directive(['status' => $status, 'last_progress_update_at' => $staleSince]);
    }
    $world->directive(['status' => 'in_progress', 'last_progress_update_at' => now()->subDays(13)]);

    $this->artisan('directive:flag-stale')->expectsOutput('Stale directive reminders sent: 6.')->assertSuccessful();

    $recipients = Notification::query()->where('trigger_type', 'directive_stale')->pluck('recipient_user_id');
    expect($recipients->filter(fn ($id) => $id === $world->attache->id))->toHaveCount(3)
        ->and($recipients->filter(fn ($id) => $id === $world->officer->id))->toHaveCount(3);

    Queue::assertPushed(SendDirectiveStaleReminder::class, 6);
});

it('does not re-notify within the same stale episode, but does after new progress goes stale again (TC-FR-DIR-010-B)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => 'in_progress', 'last_progress_update_at' => now()->subDays(15)]);

    $this->artisan('directive:flag-stale')->assertSuccessful();
    $this->travelTo(now()->addDay());
    $this->artisan('directive:flag-stale')->expectsOutput('Stale directive reminders sent: 0.')->assertSuccessful();
    expect(staleNotifications())->toBe(2);

    $this->actingAs($world->officer)->postJson("/api/v1/directives/{$directive->id}/notes", ['content' => 'Any news?'])->assertCreated();
    $this->travelTo(now()->addDay());
    $this->artisan('directive:flag-stale')->assertSuccessful();
    expect(staleNotifications())->toBe(2);

    $this->actingAs($world->attache)->postJson("/api/v1/directives/{$directive->id}/notes", ['content' => 'Calls booked.'])->assertCreated();
    $this->artisan('directive:flag-stale')->assertSuccessful();
    expect(staleNotifications())->toBe(2);

    $this->travelTo(now()->addDays(15));
    $this->artisan('directive:flag-stale')->assertSuccessful();
    expect(staleNotifications())->toBe(4);
});

it('reminds the target 7 and 3 days before the target date, for open directives only (TC-FR-DIR-004)', function () {
    $world = DirectiveWorld::create();
    $inSeven = $world->directive(['status' => 'issued', 'target_completion_date' => '2026-10-08']);
    $inThree = $world->directive(['status' => 'in_progress', 'target_completion_date' => '2026-10-04']);
    $world->directive(['status' => 'in_progress', 'target_completion_date' => '2026-10-06']);
    $world->directive(['status' => 'in_progress', 'target_completion_date' => '2026-10-09']);
    $world->directive(['status' => 'in_progress', 'target_completion_date' => '2026-10-01']);
    $world->directive(['status' => 'completed', 'target_completion_date' => '2026-10-04']);
    $world->directive(['status' => 'cancelled', 'target_completion_date' => '2026-10-08']);
    $world->directive(['status' => 'acknowledged', 'target_completion_date' => null]);

    $this->artisan('directive:send-reminders')->expectsOutput('Directive due reminders sent: 2.')->assertSuccessful();

    $reminders = Notification::query()->where('trigger_type', 'directive_due_reminder')->get()->keyBy('link');
    expect($reminders)->toHaveCount(2)
        ->and($reminders["/directives/{$inSeven->id}"]->recipient_user_id)->toBe($world->attache->id)
        ->and($reminders["/directives/{$inSeven->id}"]->message)->toContain('is due in 7 days on 8 Oct 2026')
        ->and($reminders["/directives/{$inThree->id}"]->message)->toContain('is due in 3 days on 4 Oct 2026');

    Queue::assertPushed(SendDirectiveDueReminder::class, 2);
    Queue::assertPushed(SendDirectiveDueReminder::class, fn (SendDirectiveDueReminder $job) => $job->email === $world->attache->email);
});

it('does not repeat a reminder on a same-day re-run, and sends the 3-day one four days later (TC-FR-DIR-004-B)', function () {
    $world = DirectiveWorld::create();
    $world->directive(['status' => 'in_progress', 'target_completion_date' => '2026-10-08']);

    $this->artisan('directive:send-reminders')->assertSuccessful();
    $this->artisan('directive:send-reminders')->expectsOutput('Directive due reminders sent: 0.')->assertSuccessful();

    $this->travelTo(now()->addDays(4));
    $this->artisan('directive:send-reminders')->expectsOutput('Directive due reminders sent: 1.')->assertSuccessful();

    expect(Notification::query()->where('trigger_type', 'directive_due_reminder')->pluck('message')->all())
        ->sequence(
            fn ($message) => $message->toContain('due in 7 days'),
            fn ($message) => $message->toContain('due in 3 days'),
        );
});

it('respects an attache\'s email opt-out for due reminders while still writing the in-app notification (TC-FR-NOTIF-006-DIR)', function () {
    $world = DirectiveWorld::create();
    $world->attache->update(['email_notification_preferences' => ['directive_due_reminder' => false]]);
    $world->directive(['target_completion_date' => '2026-10-04']);

    $this->artisan('directive:send-reminders')->assertSuccessful();

    expect(Notification::query()->where('trigger_type', 'directive_due_reminder')->count())->toBe(1);
    Queue::assertNotPushed(SendDirectiveDueReminder::class);
});
