<?php

use App\Jobs\SendAccountActivationEmail;
use App\Jobs\SendDirectiveIssuedNotification;
use App\Mail\NotificationMail;
use App\Models\Mission;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('dispatches an account activation email when a user is created (TC-FR-NOTIF-005)', function () {
    Queue::fake();

    $adminRole = Role::factory()->create(['name' => 'System Administrator', 'layer' => '1', 'scope' => 'platform']);
    $admin = User::factory()->create(['role_id' => $adminRole->id]);
    $attacheRole = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $mission = Mission::factory()->create();

    $this->actingAs($admin)->postJson('/api/v1/users', [
        'full_name' => 'New Attache',
        'email' => 'new.attache@example.test',
        'role_id' => $attacheRole->id,
        'mission_id' => $mission->id,
    ])->assertCreated();

    Queue::assertPushed(SendAccountActivationEmail::class, fn ($job) => $job->email === 'new.attache@example.test');
});

it('writes a notification record for the recipient (TC-FR-NOTIF-001)', function () {
    Bus::fake();

    $recipient = User::factory()->create();

    app(NotificationService::class)->notify(
        $recipient,
        'directive_issued',
        'A new directive has been issued to you.',
        '/directives/123',
    );

    $this->assertDatabaseHas('notifications', [
        'recipient_user_id' => $recipient->id,
        'trigger_type' => 'directive_issued',
        'message' => 'A new directive has been issued to you.',
        'link' => '/directives/123',
    ]);

    Bus::assertDispatched(SendDirectiveIssuedNotification::class, fn ($job) => $job->email === $recipient->email);
});

it('marks a notification read (TC-FR-NOTIF-004)', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->create([
        'recipient_user_id' => $user->id,
        'read_at' => null,
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('data.id', $notification->id);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('does not dispatch mail when the recipient has opted out of this trigger type (TC-FR-NOTIF-006)', function () {
    Bus::fake();

    $recipient = User::factory()->create([
        'email_notification_preferences' => ['directive_issued' => false],
    ]);

    app(NotificationService::class)->notify($recipient, 'directive_issued', 'A new directive has been issued to you.');

    $this->assertDatabaseHas('notifications', [
        'recipient_user_id' => $recipient->id,
        'trigger_type' => 'directive_issued',
    ]);

    Bus::assertNotDispatched(SendDirectiveIssuedNotification::class);
});

it('sends the mapped notification mail with the given message and link', function () {
    Mail::fake();

    $job = new SendDirectiveIssuedNotification('attache@example.test', 'A new directive has been issued to you.', '/directives/123');
    $job->handle();

    Mail::assertQueued(NotificationMail::class, function ($mail) {
        return $mail->hasTo('attache@example.test')
            && $mail->message === 'A new directive has been issued to you.';
    });
});

it('rejects marking another user\'s notification read', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $notification = Notification::factory()->create(['recipient_user_id' => $owner->id]);

    $this->actingAs($other)
        ->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertNotFound();
});

it('marks all of the authenticated user\'s notifications read', function () {
    $user = User::factory()->create();
    Notification::factory()->count(3)->create(['recipient_user_id' => $user->id, 'read_at' => null]);

    $this->actingAs($user)
        ->postJson('/api/v1/notifications/mark-all-read')
        ->assertOk();

    expect(Notification::where('recipient_user_id', $user->id)->whereNull('read_at')->count())->toBe(0);
});

it('filters notifications by read status', function () {
    $user = User::factory()->create();
    Notification::factory()->create(['recipient_user_id' => $user->id, 'read_at' => now()]);
    Notification::factory()->create(['recipient_user_id' => $user->id, 'read_at' => null]);

    $response = $this->actingAs($user)->getJson('/api/v1/notifications?read=0');

    $response->assertOk();
    expect(collect($response->json('data')))->toHaveCount(1);
});
