<?php

use App\Models\MasterDataEntry;
use App\Models\Ministry;
use App\Models\Role;
use App\Models\User;

/**
 * FR-MDATA-001, FR-MDATA-002 (API-001 Section 3, Master Data Service).
 */
function masterDataRole(string $name, string $layer = '2', string $scope = 'mission'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function masterDataSystemAdministrator(): User
{
    return User::factory()->create(['role_id' => masterDataRole('System Administrator', '1', 'platform')->id]);
}

function masterDataMinistryAttache(): User
{
    return User::factory()->create(['role_id' => masterDataRole('Ministry Attache')->id]);
}

it('returns the configured list of values for a given category (TC-FR-MDATA-001)', function () {
    $attache = masterDataMinistryAttache();

    MasterDataEntry::factory()->count(3)->create(['category' => 'inquiry_category']);
    MasterDataEntry::factory()->count(2)->create(['category' => 'directive_type']);

    $response = $this->actingAs($attache)->getJson('/api/v1/master-data?category=inquiry_category');

    $response->assertOk()->assertJsonCount(3, 'data');
    expect(collect($response->json('data'))->pluck('category')->unique()->all())->toBe(['inquiry_category']);
});

it('lets any authenticated user read master data, not only System Administrator', function () {
    $attache = masterDataMinistryAttache();
    MasterDataEntry::factory()->create(['category' => 'alert_intelligence_type']);

    $this->actingAs($attache)
        ->getJson('/api/v1/master-data?category=alert_intelligence_type')
        ->assertOk();
});

it('lets a System Administrator add a master data entry (FR-MDATA-002)', function () {
    $admin = masterDataSystemAdministrator();
    $ministry = Ministry::factory()->create();

    $response = $this->actingAs($admin)->postJson('/api/v1/master-data', [
        'ministry_id' => $ministry->id,
        'category' => 'content_category',
        'value' => 'Trade Shows',
        'display_order' => 1,
    ]);

    $response->assertCreated();
    $this->assertDatabaseHas('master_data_entries', [
        'ministry_id' => $ministry->id,
        'category' => 'content_category',
        'value' => 'Trade Shows',
    ]);
});

it('rejects a Ministry Attache from adding a master data entry', function () {
    $attache = masterDataMinistryAttache();

    $this->actingAs($attache)->postJson('/api/v1/master-data', [
        'category' => 'content_category',
        'value' => 'Trade Shows',
    ])->assertForbidden();
});

it('lets a System Administrator deactivate a master data entry without a deployment (FR-MDATA-002 AC1)', function () {
    $admin = masterDataSystemAdministrator();
    $entry = MasterDataEntry::factory()->create(['active' => true]);

    $response = $this->actingAs($admin)->patchJson("/api/v1/master-data/{$entry->id}", [
        'active' => false,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('master_data_entries', ['id' => $entry->id, 'active' => false]);
});

// --- FR-MDATA-003: department-scoped list administration (ADR-006) ---

function masterDataMinistryAdministrator(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => masterDataRole('Ministry Administrator', '1', 'ministry')->id,
        'ministry_id' => $ministry->id,
    ]);
}

it('shows a Ministry Administrator its own and platform-wide lists only (TC-FR-MDATA-003-A)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();
    $own = MasterDataEntry::factory()->create(['category' => 'inquiry_category', 'ministry_id' => $ownMinistry->id]);
    $platformWide = MasterDataEntry::factory()->create(['category' => 'inquiry_category', 'ministry_id' => null]);
    $foreign = MasterDataEntry::factory()->create(['category' => 'inquiry_category', 'ministry_id' => $otherMinistry->id]);

    $ids = collect($this->actingAs(masterDataMinistryAdministrator($ownMinistry))->getJson('/api/v1/master-data?category=inquiry_category')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($own->id)->toContain($platformWide->id)->not->toContain($foreign->id);
});

it('pins new entries to the Ministry Administrator\'s department (TC-FR-MDATA-003-B)', function () {
    $ownMinistry = Ministry::factory()->create();
    $administrator = masterDataMinistryAdministrator($ownMinistry);

    $this->actingAs($administrator)->postJson('/api/v1/sdt/config/alert-fields', ['value' => 'market_access'])
        ->assertCreated()->assertJsonPath('data.ministry_id', $ownMinistry->id);

    $this->actingAs($administrator)->postJson('/api/v1/sdt/config/alert-fields', [
        'value' => 'elsewhere',
        'ministry_id' => Ministry::factory()->create()->id,
    ])->assertStatus(422);
});

it('reports another department\'s or a platform-wide entry as not found for edits (TC-FR-MDATA-003-C)', function () {
    $administrator = masterDataMinistryAdministrator(Ministry::factory()->create());
    $foreign = MasterDataEntry::factory()->create(['category' => 'alert_intelligence_type', 'ministry_id' => Ministry::factory()->create()->id]);
    $platformWide = MasterDataEntry::factory()->create(['category' => 'inquiry_category', 'ministry_id' => null]);

    $this->actingAs($administrator)->patchJson("/api/v1/sdt/config/alert-fields/{$foreign->id}", ['value' => 'hijacked'])->assertNotFound();
    $this->actingAs($administrator)->patchJson("/api/v1/master-data/{$platformWide->id}", ['value' => 'hijacked'])->assertNotFound();

    expect($foreign->fresh()->value)->not->toBe('hijacked');
});
