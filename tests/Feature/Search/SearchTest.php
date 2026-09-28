<?php

use App\Models\Alert;
use App\Models\Inquiry;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\Role;
use App\Models\User;

/**
 * CLAUDE.md Section 11, 14 / API-001 (Knowledge Search Engine),
 * FR-SEARCH-001 to 004.
 */
function searchMinistryAttache(Ministry $ministry, Mission $mission): User
{
    return User::factory()->create([
        'role_id' => Role::query()->firstOrCreate(['name' => 'Ministry Attache'], ['layer' => '2', 'scope' => 'mission'])->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
}

it('returns alerts matching a full-text search term, scoped to the searching user\'s ministry (TC-FR-SEARCH-001)', function () {
    $ministryA = Ministry::factory()->create();
    $ministryB = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = searchMinistryAttache($ministryA, $mission);

    $matchingAlert = Alert::factory()->create([
        'ministry_id' => $ministryA->id,
        'mission_id' => $mission->id,
        'product_description' => 'Fresh avocado exports from the region',
    ]);

    Alert::factory()->create([
        'ministry_id' => $ministryA->id,
        'mission_id' => $mission->id,
        'product_description' => 'Unrelated coffee shipment',
    ]);

    Alert::factory()->create([
        'ministry_id' => $ministryB->id,
        'product_description' => 'Avocado trade barrier reported elsewhere',
    ]);

    $response = $this->actingAs($attache)->getJson('/api/v1/search?q=avocado');

    $response->assertOk();
    $results = collect($response->json('data'));

    expect($results)->toHaveCount(1);
    expect($results->first()['id'])->toBe($matchingAlert->id);
    expect($results->first()['type'])->toBe('alert');
    expect($results->first()['snippet'])->toContain('avocado');
});

it('returns each result\'s status and type-specific display fields (TC-FR-SEARCH-003)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = searchMinistryAttache($ministry, $mission);

    Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'country' => 'Germany',
        'sector' => 'Horticulture',
        'intelligence_type' => 'trade_barriers',
        'status' => 'assigned',
        'product_description' => 'New macadamia import quota announced',
    ]);

    Inquiry::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'sub_type' => 'dispute_or_complaint',
        'status' => 'in_progress',
        'description' => 'Buyer disputes the grade of a macadamia consignment',
    ]);

    $response = $this->actingAs($attache)->getJson('/api/v1/search?q=macadamia');

    $response->assertOk();
    $results = collect($response->json('data'))->keyBy('type');

    expect($results['alert'])->toMatchArray([
        'status' => 'assigned',
        'country' => 'Germany',
        'intelligence_type' => 'trade_barriers',
        'sector' => 'Horticulture',
    ]);
    expect($results['inquiry'])->toMatchArray([
        'status' => 'in_progress',
        'sub_type' => 'dispute_or_complaint',
    ]);
    expect($results['inquiry'])->not->toHaveKeys(['inquirer_email', 'inquirer_phone']);
});

it('rejects a search request with no query string', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = searchMinistryAttache($ministry, $mission);

    $this->actingAs($attache)->getJson('/api/v1/search')->assertStatus(422);
});

it('aggregates alert data for a country in the country intelligence profile (TC-FR-SEARCH-004)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = searchMinistryAttache($ministry, $mission);

    Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'country' => 'Kenya',
        'intelligence_type' => 'opportunities',
    ]);
    Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'country' => 'Kenya',
        'intelligence_type' => 'trade_barriers',
    ]);
    Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'country' => 'Uganda',
    ]);

    $response = $this->actingAs($attache)->getJson('/api/v1/search/countries/Kenya');

    $response->assertOk();
    expect($response->json('data.country'))->toBe('Kenya');
    expect($response->json('data.total_alerts'))->toBe(2);
    expect($response->json('data.by_intelligence_type.opportunities'))->toBe(1);
    expect($response->json('data.by_intelligence_type.trade_barriers'))->toBe(1);
});

it('never returns another ministry\'s alerts in the country intelligence profile (TC-NFR-SEC-006-B)', function () {
    $ministryA = Ministry::factory()->create();
    $ministryB = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = searchMinistryAttache($ministryA, $mission);

    Alert::factory()->create(['ministry_id' => $ministryA->id, 'country' => 'Kenya']);
    Alert::factory()->create(['ministry_id' => $ministryB->id, 'country' => 'Kenya']);

    $response = $this->actingAs($attache)->getJson('/api/v1/search/countries/Kenya');

    $response->assertOk();
    expect($response->json('data.total_alerts'))->toBe(1);
});
