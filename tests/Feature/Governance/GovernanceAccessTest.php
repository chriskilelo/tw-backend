<?php

use App\Models\AlertAttachment;
use App\Models\AuditLog;
use App\Models\ReferralAttachment;
use App\Models\ReferralEntry;
use App\Models\ReferralOrganisation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Feature\Governance\GovernanceWorld;

/**
 * Permission boundaries of the two governance modules and of everything a
 * governance account can reach outside them (BR-020, FR-AUTH-017,
 * FR-HOM-001 to 003, FR-MFA-001 to 003, NFR-SEC-006; DPIA Section 6).
 *
 * The four governance roles bypass ministry scoping, so every engine
 * endpoint must confine them itself: a Head or Deputy Head of Mission reads
 * their own mission's records in full and nothing else; the MFA roles read
 * no record content anywhere. Every denial must hold for a crafted request,
 * not just a hidden button.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse(GovernanceWorld::TODAY));

    $world = GovernanceWorld::create();
    $this->world = $world;

    $this->londonAlert = $world->alert($world->london, $world->agriculture, '2026-10-05 09:00:00', ['country' => 'United Kingdom', 'product_description' => 'Macadamia buyers in Leeds']);
    $this->berlinAlert = $world->alert($world->berlin, $world->trade, '2026-10-06 09:00:00', ['country' => 'Germany', 'product_description' => 'Macadamia buyers in Hamburg']);
    $this->londonInquiry = $world->inquiry($world->london, $world->trade, '2026-10-07 09:00:00', ['description' => 'Tea importer looking for macadamia suppliers']);
    $this->berlinInquiry = $world->inquiry($world->berlin, $world->trade, '2026-10-08 09:00:00', ['description' => 'Macadamia processing joint venture']);
    $this->londonReport = $world->report($world->london, $world->trade, 'Q1 2026', '2026-10-10 08:00:00');
    $this->berlinReport = $world->report($world->berlin, $world->trade, 'Q1 2026', '2026-10-09 08:00:00');
    $this->londonDraft = $world->report($world->london, $world->agriculture, 'Q1 2026', null);
});

/**
 * @return array<string, int> the status each governance endpoint must answer for a role
 */
function governanceEndpointExpectations(string $roleName): array
{
    $isOversight = in_array($roleName, ['Head of Mission', 'Deputy Head of Mission'], true);
    $isMfa = in_array($roleName, ['MFA HQ Officer', 'MFA Principal Secretary'], true);

    return [
        '/api/v1/mission-activity' => $isOversight ? 200 : 403,
        '/api/v1/mission-activity/summary' => $isOversight ? 200 : 403,
        '/api/v1/mfa-awareness' => $isMfa ? 200 : 403,
        '/api/v1/mfa-awareness/submissions' => $isMfa ? 200 : 403,
        '/api/v1/mfa-awareness/missions/{london}' => $isMfa ? 200 : 403,
        '/api/v1/mfa-awareness/national-overview' => $roleName === 'MFA Principal Secretary' ? 200 : 403,
    ];
}

// --- Who may open the two modules -------------------------------------------------

it('opens each governance endpoint only to the roles the requirements name (TC-FR-HOM-001-RBAC, TC-FR-MFA-001-RBAC)', function (string $roleName) {
    $user = $this->world->member($roleName);

    foreach (governanceEndpointExpectations($roleName) as $uri => $expected) {
        $uri = str_replace('{london}', $this->world->london->id, $uri);

        expect($this->actingAs($user)->getJson($uri)->status())->toBe($expected, "{$roleName} on {$uri}");
    }
})->with([
    'Head of Mission', 'Deputy Head of Mission', 'MFA HQ Officer', 'MFA Principal Secretary',
    'Ministry Attache', 'Ministry HQ Officer', 'Ministry HQ Director', 'Ministry PS', 'Acting PS',
    'Ministry Publishing Authority', 'HRM&D Officer', 'Designated Deputy', 'Honorary Consul',
    'System Administrator', 'Ministry Administrator',
]);

it('requires a session for every governance endpoint', function () {
    foreach (array_keys(governanceEndpointExpectations('Head of Mission')) as $uri) {
        $this->getJson(str_replace('{london}', $this->world->london->id, $uri))->assertUnauthorized();
    }
});

it('denies an oversight account that has no mission posting rather than guessing one (fail closed)', function () {
    $unposted = GovernanceWorld::user('Head of Mission', null, null);

    $this->actingAs($unposted)->getJson('/api/v1/mission-activity')->assertForbidden();
    $this->actingAs($unposted)->getJson('/api/v1/mission-activity/summary')->assertForbidden();
    $this->actingAs($unposted)->getJson('/api/v1/alerts')->assertOk()->assertJsonPath('meta.total', 0);
    $this->actingAs($unposted)->getJson("/api/v1/alerts/{$this->londonAlert->id}")->assertForbidden();
});

// --- Head / Deputy Head of Mission: their own mission, every department ----------

it('confines a Head of Mission to their own mission in the alert and inquiry engines (TC-FR-HOM-001-SCOPE-A)', function (string $roleName) {
    $user = $this->world->member($roleName);

    $alerts = collect($this->actingAs($user)->getJson('/api/v1/alerts?per_page=100')->assertOk()->json('data'))->pluck('id');
    $inquiries = collect($this->actingAs($user)->getJson('/api/v1/inquiries?per_page=100')->assertOk()->json('data'))->pluck('id');

    expect($alerts->all())->toBe([$this->londonAlert->id])
        ->and($inquiries->all())->toBe([$this->londonInquiry->id]);

    $this->actingAs($user)->getJson("/api/v1/alerts?mission_id={$this->world->berlin->id}")->assertOk()->assertJsonPath('meta.total', 0);
    $this->actingAs($user)->getJson("/api/v1/inquiries?mission_id={$this->world->berlin->id}")->assertOk()->assertJsonPath('meta.total', 0);
})->with(['Head of Mission', 'Deputy Head of Mission']);

it('opens an own-mission record in full but refuses another mission\'s, whatever id is sent (TC-FR-HOM-001-SCOPE-B)', function (string $roleName) {
    $user = $this->world->member($roleName);

    $this->actingAs($user)->getJson("/api/v1/alerts/{$this->londonAlert->id}")->assertOk()->assertJsonPath('data.id', $this->londonAlert->id);
    $this->actingAs($user)->getJson("/api/v1/inquiries/{$this->londonInquiry->id}")->assertOk()->assertJsonPath('data.id', $this->londonInquiry->id);
    $this->actingAs($user)->getJson("/api/v1/periodic-reports/{$this->londonReport->id}")->assertOk();

    $this->actingAs($user)->getJson("/api/v1/alerts/{$this->berlinAlert->id}")->assertForbidden();
    $this->actingAs($user)->getJson("/api/v1/inquiries/{$this->berlinInquiry->id}")->assertForbidden();
    $this->actingAs($user)->getJson("/api/v1/periodic-reports/{$this->berlinReport->id}")->assertForbidden();
    $this->actingAs($user)->getJson("/api/v1/periodic-reports/{$this->londonDraft->id}")->assertForbidden();
})->with(['Head of Mission', 'Deputy Head of Mission']);

it('limits a Head of Mission\'s knowledge search and country profile to their own mission (TC-FR-SEARCH-002-HOM)', function () {
    $user = $this->world->member('Head of Mission');

    $results = collect($this->actingAs($user)->getJson('/api/v1/search?q=macadamia')->assertOk()->json('data'));

    expect($results->pluck('id')->sort()->values()->all())->toBe(collect([$this->londonAlert->id, $this->londonInquiry->id])->sort()->values()->all())
        ->and($results->pluck('mission')->unique()->all())->toBe(['London']);

    $this->actingAs($user)->getJson('/api/v1/search/countries/Germany')->assertOk()->assertJsonPath('data.total_alerts', 0);
    $this->actingAs($user)->getJson('/api/v1/search/countries/United%20Kingdom')->assertOk()->assertJsonPath('data.total_alerts', 1);
});

it('issues attachment links for own-mission records only', function () {
    $user = $this->world->member('Head of Mission');
    $ownAttachment = AlertAttachment::factory()->create(['alert_id' => $this->londonAlert->id]);
    $foreignAttachment = AlertAttachment::factory()->create(['alert_id' => $this->berlinAlert->id]);
    [$ownReferral, $ownReferralAttachment] = governanceReferralWithAttachment($this->londonInquiry->id);
    [$foreignReferral, $foreignReferralAttachment] = governanceReferralWithAttachment($this->berlinInquiry->id);

    $this->actingAs($user)->getJson("/api/v1/alerts/{$this->londonAlert->id}/attachments/{$ownAttachment->id}")->assertOk()->assertJsonStructure(['data' => ['url', 'expires_at']]);
    $this->actingAs($user)->getJson("/api/v1/referrals/{$ownReferral->id}/attachments/{$ownReferralAttachment->id}")->assertOk();

    $this->actingAs($user)->getJson("/api/v1/alerts/{$this->berlinAlert->id}/attachments/{$foreignAttachment->id}")->assertForbidden();
    $this->actingAs($user)->getJson("/api/v1/referrals/{$foreignReferral->id}/attachments/{$foreignReferralAttachment->id}")->assertForbidden();
    // Pairing an own-mission alert with another alert's attachment is not a way in either.
    $this->actingAs($user)->getJson("/api/v1/alerts/{$this->londonAlert->id}/attachments/{$foreignAttachment->id}")->assertNotFound();
});

it('keeps every department at the mission in view even when the account was given a department (TC-FR-HOM-001-SCOPE-C)', function () {
    $user = GovernanceWorld::user('Head of Mission', $this->world->trade, $this->world->london);

    $this->actingAs($user)->getJson('/api/v1/alerts')->assertOk()->assertJsonPath('data.0.id', $this->londonAlert->id);
    $this->actingAs($user)->getJson("/api/v1/alerts/{$this->londonAlert->id}")->assertOk();
    $this->actingAs($user)->getJson("/api/v1/alerts/{$this->berlinAlert->id}")->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/mission-activity?per_page=100')->assertOk()->assertJsonPath('meta.total', 3);
});

it('records each time a Head of Mission opens a record or an attachment, and nothing for other roles (TC-DPIA-HOM-AUDIT)', function () {
    $user = $this->world->member('Head of Mission');
    $attachment = AlertAttachment::factory()->create(['alert_id' => $this->londonAlert->id]);
    [$referral, $referralAttachment] = governanceReferralWithAttachment($this->londonInquiry->id);

    $this->actingAs($user)->getJson('/api/v1/mission-activity')->assertOk();
    $this->actingAs($user)->getJson('/api/v1/mission-activity/summary')->assertOk();
    $this->actingAs($user)->getJson("/api/v1/alerts/{$this->londonAlert->id}")->assertOk();
    $this->actingAs($user)->getJson("/api/v1/inquiries/{$this->londonInquiry->id}")->assertOk();
    $this->actingAs($user)->getJson("/api/v1/periodic-reports/{$this->londonReport->id}")->assertOk();
    $this->actingAs($user)->getJson("/api/v1/alerts/{$this->londonAlert->id}/attachments/{$attachment->id}")->assertOk();
    $this->actingAs($user)->getJson("/api/v1/referrals/{$referral->id}/attachments/{$referralAttachment->id}")->assertOk();
    $this->actingAs($user)->getJson("/api/v1/alerts/{$this->berlinAlert->id}")->assertForbidden();

    $rows = AuditLog::query()->where('action', 'mission_oversight.record_accessed')->where('user_id', $user->id)->get();

    expect($rows->map(fn (AuditLog $row): string => class_basename($row->affected_entity_type))->sort()->values()->all())
        ->toBe(['Alert', 'AlertAttachment', 'Inquiry', 'PeriodicReport', 'ReferralAttachment'])
        ->and($rows->firstWhere('affected_entity_id', $this->londonAlert->id)->ministry_id)->toBe($this->world->agriculture->id)
        ->and($rows->firstWhere('affected_entity_id', $this->londonInquiry->id)->ministry_id)->toBe($this->world->trade->id);

    $this->actingAs($this->world->londonTradeAttache)->getJson("/api/v1/inquiries/{$this->londonInquiry->id}")->assertOk();
    expect(AuditLog::query()->where('action', 'mission_oversight.record_accessed')->count())->toBe(5);
});

// --- MFA roles: aggregate and metadata only, never content -------------------------

it('refuses the MFA roles every route to record content, whatever id is sent (TC-FR-MFA-001-AC2)', function (string $roleName) {
    $user = $this->world->member($roleName);
    $attachment = AlertAttachment::factory()->create(['alert_id' => $this->londonAlert->id]);
    [$referral, $referralAttachment] = governanceReferralWithAttachment($this->londonInquiry->id);

    foreach ([
        '/api/v1/alerts',
        "/api/v1/alerts/{$this->londonAlert->id}",
        "/api/v1/alerts/{$this->londonAlert->id}/attachments/{$attachment->id}",
        '/api/v1/inquiries',
        "/api/v1/inquiries/{$this->londonInquiry->id}",
        "/api/v1/referrals/{$referral->id}/attachments/{$referralAttachment->id}",
        '/api/v1/periodic-reports',
        "/api/v1/periodic-reports/{$this->londonReport->id}",
        '/api/v1/directives',
        '/api/v1/search?q=macadamia',
        '/api/v1/search/countries/Germany',
        '/api/v1/mission-activity',
    ] as $uri) {
        expect($this->actingAs($user)->getJson($uri)->status())->toBe(403, "{$roleName} on {$uri}");
    }

    expect(AuditLog::query()->where('user_id', $user->id)->count())->toBe(0);
})->with(['MFA HQ Officer', 'MFA Principal Secretary']);

// --- Account administration: governance accounts belong to no department ----------

it('keeps a governance account out of every Ministry Administrator\'s reach, even one given a department (TC-FR-AUTH-021-GOV)', function () {
    $administrator = $this->world->member('Ministry Administrator');
    $straysIntoTrade = GovernanceWorld::user('Head of Mission', $this->world->trade, $this->world->london);
    $base = "/api/v1/users/{$straysIntoTrade->id}";

    $listed = collect($this->actingAs($administrator)->getJson('/api/v1/users?per_page=100')->assertOk()->json('data'))->pluck('id');
    expect($listed)->toContain($this->world->londonTradeAttache->id)->not->toContain($straysIntoTrade->id);

    $this->actingAs($administrator)->getJson($base)->assertNotFound();
    $this->actingAs($administrator)->patchJson($base, ['full_name' => 'Renamed'])->assertNotFound();
    $this->actingAs($administrator)->postJson("{$base}/deactivate")->assertNotFound();
    $this->actingAs($administrator)->postJson("{$base}/reactivate")->assertNotFound();
    $this->actingAs($administrator)->postJson("{$base}/resend-activation")->assertNotFound();

    expect($straysIntoTrade->fresh()->full_name)->not->toBe('Renamed')
        ->and($straysIntoTrade->fresh()->status->value)->toBe('active');

    $this->actingAs($this->world->member('System Administrator'))->getJson($base)->assertOk();
});

it('never gives a governance account a department, and clears a stray one on the next edit (TC-FR-AUTH-001-GOV)', function () {
    $systemAdministrator = $this->world->member('System Administrator');
    $headOfMissionRole = GovernanceWorld::role('Head of Mission');

    $this->actingAs($systemAdministrator)->postJson('/api/v1/users', [
        'full_name' => 'New Head of Mission',
        'email' => 'new.hom@mfa.go.ke',
        'role_id' => $headOfMissionRole->id,
        'mission_id' => $this->world->berlin->id,
        'ministry_id' => $this->world->trade->id,
    ])->assertStatus(422);

    $this->actingAs($systemAdministrator)->postJson('/api/v1/users', [
        'full_name' => 'New Head of Mission',
        'email' => 'new.hom@mfa.go.ke',
        'role_id' => $headOfMissionRole->id,
        'mission_id' => $this->world->berlin->id,
    ])->assertCreated()->assertJsonPath('data.ministry', null);

    $stray = GovernanceWorld::user('Head of Mission', $this->world->trade, $this->world->london);

    $this->actingAs($systemAdministrator)->patchJson("/api/v1/users/{$stray->id}", ['ministry_id' => $this->world->agriculture->id])->assertStatus(422);
    $this->actingAs($systemAdministrator)->patchJson("/api/v1/users/{$stray->id}", ['full_name' => 'QA Head of Mission'])->assertOk();

    expect($stray->fresh()->ministry_id)->toBeNull()->and($stray->fresh()->full_name)->toBe('QA Head of Mission');
});

/**
 * @return array{0: ReferralEntry, 1: ReferralAttachment}
 */
function governanceReferralWithAttachment(string $inquiryId): array
{
    $referral = ReferralEntry::factory()->create([
        'inquiry_id' => $inquiryId,
        'referral_organisation_id' => ReferralOrganisation::factory(),
        'created_by_user_id' => User::factory(),
    ]);

    return [$referral, ReferralAttachment::factory()->create(['referral_entry_id' => $referral->id])];
}
