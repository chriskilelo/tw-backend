<?php

use App\Models\Inquiry;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\Role;
use App\Models\User;

/**
 * CLAUDE.md Section 11, 14 / FR-INQ-019 (Match and Flag Cross-Mission
 * Inquiries): App\Services\InquiryMatchingService (suggested matches) and
 * InquiryService::linkInquiry() (confirmed symmetric link), wired together
 * behind POST /api/v1/inquiries/{id}/link.
 */
function inquiryMatchRole(string $name, string $layer = '2', string $scope = 'mission'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function inquiryMatchAttache(Ministry $ministry, Mission $mission): User
{
    return User::factory()->create([
        'role_id' => inquiryMatchRole('Ministry Attache')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
}

function inquiryMatchHqOfficer(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => inquiryMatchRole('Ministry HQ Officer', '2/3', 'ministry')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => null,
    ]);
}

it('lets a Ministry HQ Officer confirm a link between two cross-mission inquiries (TC-FR-INQ-019)', function () {
    $ministry = Ministry::factory()->create();
    $missionA = Mission::factory()->create();
    $missionB = Mission::factory()->create();
    $hqOfficer = inquiryMatchHqOfficer($ministry);

    $inquiryA = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $missionA->id,
        'product_or_sector' => 'Avocado exports',
    ]);
    $inquiryB = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $missionB->id,
        'product_or_sector' => 'Avocado importers seeking supply',
    ]);

    $response = $this->actingAs($hqOfficer)->postJson("/api/v1/inquiries/{$inquiryA->id}/link", [
        'target_inquiry_id' => $inquiryB->id,
    ]);

    $response->assertOk();
    expect($response->json('data.linked_inquiry.id'))->toBe($inquiryB->id);

    // FR-INQ-019 AC2: the linkage is visible on both records, without
    // reassigning either inquiry to a different mission.
    $this->assertDatabaseHas('inquiries', [
        'id' => $inquiryA->id,
        'linked_inquiry_id' => $inquiryB->id,
        'mission_id' => $missionA->id,
    ]);
    $this->assertDatabaseHas('inquiries', [
        'id' => $inquiryB->id,
        'linked_inquiry_id' => $inquiryA->id,
        'mission_id' => $missionB->id,
    ]);
});

it('surfaces suggested cross-mission matches when no target is given, excluding same-mission and unrelated inquiries (TC-FR-INQ-019-B)', function () {
    $ministry = Ministry::factory()->create();
    $missionA = Mission::factory()->create();
    $missionB = Mission::factory()->create();
    $hqOfficer = inquiryMatchHqOfficer($ministry);

    $source = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $missionA->id,
        'product_or_sector' => 'Macadamia nuts',
        'description' => 'Buyer seeking macadamia nut suppliers for bulk export.',
    ]);

    $crossMissionMatch = Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $missionB->id,
        'product_or_sector' => 'Macadamia nuts',
        'description' => 'Investor interested in macadamia nut sourcing.',
    ]);

    // Same mission as the source: excluded even though the text matches,
    // since FR-INQ-019 is cross-mission linking only.
    Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $missionA->id,
        'product_or_sector' => 'Macadamia nuts',
    ]);

    // Unrelated text in a different mission: no match expected.
    Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $missionB->id,
        'product_or_sector' => 'Textiles',
        'description' => 'General enquiry about textile export regulations.',
    ]);

    $response = $this->actingAs($hqOfficer)->postJson("/api/v1/inquiries/{$source->id}/link");

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($crossMissionMatch->id);
    expect($ids)->not->toContain($source->id);

    $this->assertDatabaseHas('inquiries', ['id' => $source->id, 'linked_inquiry_id' => null]);
});

it('rejects the link endpoint for a role other than Ministry HQ Officer (TC-FR-INQ-019-C)', function () {
    $ministry = Ministry::factory()->create();
    $missionA = Mission::factory()->create();
    $missionB = Mission::factory()->create();
    $attache = inquiryMatchAttache($ministry, $missionA);

    $inquiryA = Inquiry::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $missionA->id]);
    $inquiryB = Inquiry::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $missionB->id]);

    $this->actingAs($attache)
        ->postJson("/api/v1/inquiries/{$inquiryA->id}/link", ['target_inquiry_id' => $inquiryB->id])
        ->assertForbidden();
});
