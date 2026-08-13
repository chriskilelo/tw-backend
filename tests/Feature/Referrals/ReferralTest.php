<?php

use App\Models\Inquiry;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\Notification;
use App\Models\ReferralEntry;
use App\Models\ReferralOrganisation;
use App\Models\Role;
use App\Models\User;

/**
 * CLAUDE.md Section 11, 14 / API-001 (Referral Register Engine), FR-REF-001
 * to 006, BR-021.
 */
function referralRole(string $name, string $layer = '2', string $scope = 'mission'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function referralMinistryAttache(Ministry $ministry, Mission $mission): User
{
    return User::factory()->create([
        'role_id' => referralRole('Ministry Attache')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
}

function referralMinistryHqOfficer(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => referralRole('Ministry HQ Officer', '2/3', 'ministry')->id,
        'ministry_id' => $ministry->id,
    ]);
}

it('lets a Ministry Attache record a referral entry linked to the inquiry (TC-FR-REF-002)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = referralMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
    $organisation = ReferralOrganisation::factory()->create(['ministry_id' => $ministry->id]);

    $response = $this->actingAs($attache)->postJson("/api/v1/inquiries/{$inquiry->id}/referrals", [
        'referral_organisation_id' => $organisation->id,
        'contact_person' => 'Jane Doe',
        'referral_date' => now()->toDateString(),
        'referral_method' => 'email',
        'remarks' => 'Buyer referred for export financing support.',
    ]);

    $response->assertCreated();
    expect($response->json('data.referral_organisation.id'))->toBe($organisation->id);

    $this->assertDatabaseHas('referral_entries', [
        'inquiry_id' => $inquiry->id,
        'referral_organisation_id' => $organisation->id,
        'created_by_user_id' => $attache->id,
        'contact_person' => 'Jane Doe',
    ]);
});

it('rejects recording a referral when the organisation id is missing', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = referralMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);

    $this->actingAs($attache)
        ->postJson("/api/v1/inquiries/{$inquiry->id}/referrals", ['referral_date' => now()->toDateString()])
        ->assertStatus(422);
});

it('rejects a non-attache role from recording a referral', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $hqOfficer = referralMinistryHqOfficer($ministry);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
    $organisation = ReferralOrganisation::factory()->create(['ministry_id' => $ministry->id]);

    $this->actingAs($hqOfficer)->postJson("/api/v1/inquiries/{$inquiry->id}/referrals", [
        'referral_organisation_id' => $organisation->id,
        'referral_date' => now()->toDateString(),
    ])->assertForbidden();
});

it('never sends any notification when a referral is recorded (BR-021, FR-REF-003)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = referralMinistryAttache($ministry, $mission);
    $inquiry = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
    $organisation = ReferralOrganisation::factory()->create(['ministry_id' => $ministry->id]);

    $this->actingAs($attache)->postJson("/api/v1/inquiries/{$inquiry->id}/referrals", [
        'referral_organisation_id' => $organisation->id,
        'referral_date' => now()->toDateString(),
    ])->assertCreated();

    expect(Notification::count())->toBe(0);
});

it('groups the referral summary by organisation, mission, country, sector and period (TC-FR-REF-005)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create(['host_country' => 'United Kingdom']);
    $hqOfficer = referralMinistryHqOfficer($ministry);

    $orgA = ReferralOrganisation::factory()->create(['ministry_id' => $ministry->id, 'name' => 'EPBA']);
    $orgB = ReferralOrganisation::factory()->create(['ministry_id' => $ministry->id, 'name' => 'KenTrade']);

    $inquiryA = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'product_or_sector' => 'Agriculture',
    ]);
    $inquiryB = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'product_or_sector' => 'Manufacturing',
    ]);

    ReferralEntry::factory()->create([
        'inquiry_id' => $inquiryA->id,
        'referral_organisation_id' => $orgA->id,
        'referral_date' => '2026-07-01',
    ]);
    ReferralEntry::factory()->create([
        'inquiry_id' => $inquiryB->id,
        'referral_organisation_id' => $orgA->id,
        'referral_date' => '2026-07-15',
    ]);
    ReferralEntry::factory()->create([
        'inquiry_id' => $inquiryA->id,
        'referral_organisation_id' => $orgB->id,
        'referral_date' => '2026-08-01',
    ]);

    $response = $this->actingAs($hqOfficer)->getJson('/api/v1/referrals/summary');

    $response->assertOk();
    $data = $response->json('data');

    expect($data['total'])->toBe(3)
        ->and($data['by_organisation'])->toMatchArray(['EPBA' => 2, 'KenTrade' => 1])
        ->and($data['by_mission'])->toMatchArray([$mission->name => 3])
        ->and($data['by_country'])->toMatchArray(['United Kingdom' => 3])
        ->and($data['by_sector'])->toMatchArray(['Agriculture' => 2, 'Manufacturing' => 1])
        ->and($data['by_period'])->toMatchArray(['2026-07' => 2, '2026-08' => 1]);
});

it('rejects a Ministry Attache from viewing the referral summary dashboard (FR-REF-005)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = referralMinistryAttache($ministry, $mission);

    $this->actingAs($attache)->getJson('/api/v1/referrals/summary')->assertForbidden();
});
