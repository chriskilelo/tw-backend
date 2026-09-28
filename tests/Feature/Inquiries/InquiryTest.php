<?php

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Models\InquiryNote;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\ReferralAttachment;
use App\Models\ReferralEntry;
use App\Models\ReferralOrganisation;
use App\Models\Role;
use App\Models\User;

/**
 * CLAUDE.md Section 11, 14 / API-001 Section 7 (Inquiry and Case Tracker
 * Engine).
 */
function inquiryRole(string $name, string $layer = '2', string $scope = 'mission'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function inquiryMinistryAttache(Ministry $ministry, Mission $mission): User
{
    return User::factory()->create([
        'role_id' => inquiryRole('Ministry Attache')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
}

it('lets a Ministry Attache log an inquiry with a generated reference number and status draft (TC-FR-INQ-002)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);

    $response = $this->actingAs($attache)->postJson('/api/v1/inquiries', [
        'category' => 'Buyer Seeking Supplier',
        'inquirer_name' => 'Jane Trader',
        'date_received' => now()->toDateString(),
    ]);

    $response->assertCreated();
    expect($response->json('data.reference_number'))->toStartWith('INQ-'.now()->format('Ym').'-');
    expect($response->json('data.status'))->toBe(InquiryStatus::Draft->value);

    $this->assertDatabaseHas('inquiries', [
        'logged_by_user_id' => $attache->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'status' => InquiryStatus::Draft->value,
    ]);
});

it('rejects logging an inquiry when a mandatory field is missing', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);

    $this->actingAs($attache)
        ->postJson('/api/v1/inquiries', ['category' => 'General Market Question'])
        ->assertStatus(422);
});

it('lets a Ministry Attache transition an inquiry through a valid status change (TC-FR-INQ-006)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'status' => InquiryStatus::Draft,
    ]);

    $response = $this->actingAs($attache)->patchJson("/api/v1/inquiries/{$inquiry->id}/status", [
        'status' => InquiryStatus::Received->value,
    ]);

    $response->assertOk();
    expect($response->json('data.status'))->toBe(InquiryStatus::Received->value);

    $this->assertDatabaseHas('inquiries', [
        'id' => $inquiry->id,
        'status' => InquiryStatus::Received->value,
    ]);

    $this->assertDatabaseHas('inquiry_events', [
        'inquiry_id' => $inquiry->id,
        'event_type' => 'status_changed',
    ]);
});

it('rejects an invalid status transition with 422 (TC-FR-INQ-006)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'status' => InquiryStatus::Draft,
    ]);

    $this->actingAs($attache)
        ->patchJson("/api/v1/inquiries/{$inquiry->id}/status", ['status' => InquiryStatus::Resolved->value])
        ->assertStatus(422);

    $this->assertDatabaseHas('inquiries', [
        'id' => $inquiry->id,
        'status' => InquiryStatus::Draft->value,
    ]);
});

it('rejects closing an inquiry without a resolution summary (TC-FR-INQ-012)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'status' => InquiryStatus::Resolved,
    ]);

    $this->actingAs($attache)
        ->postJson("/api/v1/inquiries/{$inquiry->id}/close", [])
        ->assertStatus(422);

    $this->assertDatabaseHas('inquiries', [
        'id' => $inquiry->id,
        'status' => InquiryStatus::Resolved->value,
        'closed_at' => null,
    ]);
});

it('closes a resolved inquiry with a resolution summary (TC-FR-INQ-012)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'status' => InquiryStatus::Resolved,
    ]);

    $response = $this->actingAs($attache)->postJson("/api/v1/inquiries/{$inquiry->id}/close", [
        'resolution_summary' => 'Buyer connected with supplier; deal facilitated.',
    ]);

    $response->assertOk();
    expect($response->json('data.status'))->toBe(InquiryStatus::Closed->value);
    expect($response->json('data.closed_at'))->not->toBeNull();

    $this->assertDatabaseHas('inquiries', [
        'id' => $inquiry->id,
        'status' => InquiryStatus::Closed->value,
        'resolution_summary' => 'Buyer connected with supplier; deal facilitated.',
    ]);
});

it('appends an immutable inquiry note (TC-FR-INQ-014)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);

    $response = $this->actingAs($attache)->postJson("/api/v1/inquiries/{$inquiry->id}/notes", [
        'content' => 'Followed up with the buyer by phone.',
    ]);

    $response->assertCreated();

    $this->assertDatabaseHas('inquiry_notes', [
        'inquiry_id' => $inquiry->id,
        'authored_by_user_id' => $attache->id,
        'content' => 'Followed up with the buyer by phone.',
    ]);

    $note = InquiryNote::where('inquiry_id', $inquiry->id)->firstOrFail();
    $note->content = 'Attempted edit';

    expect(fn () => $note->save())->toThrow(RuntimeException::class);
});

it('accepts sub_type dispute_or_complaint and follows the same workflow as a standard inquiry (TC-FR-INQ-022)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);

    $response = $this->actingAs($attache)->postJson('/api/v1/inquiries', [
        'category' => 'Disputes/Complaints',
        'sub_type' => 'dispute_or_complaint',
        'inquirer_name' => 'Aggrieved Importer',
        'date_received' => now()->toDateString(),
    ]);

    $response->assertCreated();
    expect($response->json('data.sub_type'))->toBe('dispute_or_complaint');

    $inquiry = Inquiry::findOrFail($response->json('data.id'));

    $this->actingAs($attache)
        ->patchJson("/api/v1/inquiries/{$inquiry->id}/status", ['status' => InquiryStatus::Received->value])
        ->assertOk()
        ->assertJsonPath('data.status', InquiryStatus::Received->value);
});

it('lets the owning attache edit an inquiry, including the high-value flag and type (TC-FR-INQ-004)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'inquirer_name' => 'Original Name',
    ]);

    $this->actingAs($attache)
        ->patchJson("/api/v1/inquiries/{$inquiry->id}", [
            'inquirer_name' => 'Corrected Name',
            'sub_type' => 'dispute_or_complaint',
            'high_value_flag' => true,
            'high_value_justification' => 'Forty tonnes a month on a two-year contract.',
        ])
        ->assertOk()
        ->assertJsonPath('data.inquirer_name', 'Corrected Name')
        ->assertJsonPath('data.sub_type', 'dispute_or_complaint')
        ->assertJsonPath('data.high_value_flag', true)
        ->assertJsonPath('data.high_value_justification', 'Forty tonnes a month on a two-year contract.');
});

it('rejects an edit from an attache at another mission (TC-FR-INQ-004-B)', function () {
    $ministry = Ministry::factory()->create();
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => Mission::factory()->create()->id,
    ]);
    $otherAttache = inquiryMinistryAttache($ministry, Mission::factory()->create());

    $this->actingAs($otherAttache)
        ->patchJson("/api/v1/inquiries/{$inquiry->id}", ['inquirer_name' => 'Hijacked'])
        ->assertForbidden();
});

it('logs an operational event on the timeline without changing the status (TC-FR-INQ-008)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'status' => InquiryStatus::InProgress->value,
    ]);

    $this->actingAs($attache)
        ->postJson("/api/v1/inquiries/{$inquiry->id}/events", [
            'event_type' => 'feedback_received',
            'note' => 'Buyer confirmed interest.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', InquiryStatus::InProgress->value)
        ->assertJsonPath('data.events.0.event_type', 'feedback_received')
        ->assertJsonPath('data.events.0.note', 'Buyer confirmed interest.');
});

it('refuses a client-submitted status_changed event (TC-FR-INQ-008-B)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);

    $this->actingAs($attache)
        ->postJson("/api/v1/inquiries/{$inquiry->id}/events", ['event_type' => 'status_changed'])
        ->assertStatus(422);
});

it('returns the inquiry referral history in date order with its attachments (TC-FR-REF-006)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = inquiryMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
    $organisation = ReferralOrganisation::factory()->create([
        'ministry_id' => $ministry->id,
        'name' => 'Kenya Investment Authority (KenInvest)',
    ]);

    $later = ReferralEntry::factory()->create([
        'inquiry_id' => $inquiry->id,
        'referral_organisation_id' => $organisation->id,
        'referral_date' => '2026-07-20',
        'created_by_user_id' => $attache->id,
    ]);
    $earlier = ReferralEntry::factory()->create([
        'inquiry_id' => $inquiry->id,
        'referral_organisation_id' => $organisation->id,
        'referral_date' => '2026-07-05',
        'created_by_user_id' => $attache->id,
    ]);
    ReferralAttachment::factory()->create([
        'referral_entry_id' => $earlier->id,
        'original_filename' => 'introduction-letter.pdf',
    ]);
    ReferralEntry::factory()->create([
        'inquiry_id' => Inquiry::factory()->create(['ministry_id' => $ministry->id])->id,
        'referral_organisation_id' => $organisation->id,
    ]);

    $response = $this->actingAs($attache)->getJson("/api/v1/inquiries/{$inquiry->id}");

    $response->assertOk()
        ->assertJsonCount(2, 'data.referrals')
        ->assertJsonPath('data.referrals.0.id', $earlier->id)
        ->assertJsonPath('data.referrals.1.id', $later->id)
        ->assertJsonPath('data.referrals.0.referral_organisation.name', 'Kenya Investment Authority (KenInvest)')
        ->assertJsonPath('data.referrals.0.created_by.id', $attache->id)
        ->assertJsonPath('data.referrals.0.attachments.0.original_filename', 'introduction-letter.pdf')
        ->assertJsonCount(0, 'data.referrals.1.attachments');
});
