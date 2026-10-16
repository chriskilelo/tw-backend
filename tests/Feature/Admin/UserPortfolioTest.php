<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Sdt\HqWorkspaceWorld;

/**
 * FR-SDT-012: an HQ Trade Officer's portfolio is department configuration,
 * set by the account's administrators (a System Administrator, or the
 * department's own Ministry Administrator) through GET/PUT
 * /users/{user}/portfolio, and read by the officer in their workspace.
 */
beforeEach(function () {
    Queue::fake();
    $this->world = HqWorkspaceWorld::create();
    $this->tradeAdministrator = HqWorkspaceWorld::user('Ministry Administrator', $this->world->trade);
    $this->miningAdministrator = HqWorkspaceWorld::user('Ministry Administrator', $this->world->mining);
    $this->systemAdministrator = HqWorkspaceWorld::user('System Administrator', null);
});

/**
 * @return array{mission_ids: array<int, string>, countries: array<int, string>, sectors: array<int, string>}
 */
function portfolioBody(array $overrides = []): array
{
    return ['mission_ids' => [], 'countries' => [], 'sectors' => [], ...$overrides];
}

it('lets the department\'s Ministry Administrator and a System Administrator configure a portfolio (TC-FR-SDT-012-PA)', function (string $administrator) {
    $world = $this->world;
    $actor = $this->{$administrator};

    $show = $this->actingAs($actor)->getJson("/api/v1/users/{$world->otherOfficer->id}/portfolio")->assertOk()->json('data');
    expect($show['portfolio'])->toBe(['mission_ids' => [], 'countries' => [], 'sectors' => []])
        ->and(collect($show['options']['missions'])->pluck('name')->all())->toBe(['Accra', 'Berlin', 'London'])
        ->and($show['options']['countries'])->toBe(['Germany', 'Ghana', 'United Kingdom']);

    $this->actingAs($actor)
        ->putJson("/api/v1/users/{$world->otherOfficer->id}/portfolio", portfolioBody([
            'mission_ids' => [$world->accra->id],
            'countries' => ['  Ghana ', 'ghana', 'Côte d’Ivoire'],
            'sectors' => ['Tea', 'cut  flowers', 'TEA'],
        ]))
        ->assertOk()
        ->assertJsonPath('data.portfolio', [
            'mission_ids' => [$world->accra->id],
            'countries' => ['Ghana', 'Côte d’Ivoire'],
            'sectors' => ['Tea', 'cut flowers'],
        ]);

    $audit = AuditLog::query()->where('action', 'user.portfolio.updated')->sole();
    expect($audit->user_id)->toBe($actor->id)
        ->and($audit->affected_entity_id)->toBe($world->otherOfficer->id)
        ->and($audit->ministry_id)->toBe($world->trade->id)
        ->and($audit->changes['before'])->toBeNull()
        ->and($audit->changes['after']['sectors'])->toBe(['Tea', 'cut flowers']);
})->with(['tradeAdministrator', 'systemAdministrator']);

it('clears the portfolio with three empty lists and writes no audit row for a no-op (TC-FR-SDT-012-PB)', function () {
    $world = $this->world;

    $this->actingAs($this->tradeAdministrator)->putJson("/api/v1/users/{$world->officer->id}/portfolio", portfolioBody())->assertOk();
    expect($world->officer->fresh()->portfolio)->toBeNull();

    $this->actingAs($this->tradeAdministrator)->putJson("/api/v1/users/{$world->officer->id}/portfolio", portfolioBody())->assertOk();
    expect(AuditLog::query()->where('action', 'user.portfolio.updated')->count())->toBe(1);
});

it('hides another department\'s officer behind a 404, before validating the body (TC-FR-SDT-012-PC)', function () {
    $world = $this->world;

    $this->actingAs($this->miningAdministrator)->getJson("/api/v1/users/{$world->officer->id}/portfolio")->assertNotFound();
    $this->actingAs($this->miningAdministrator)
        ->putJson("/api/v1/users/{$world->officer->id}/portfolio", portfolioBody(['mission_ids' => ['not-a-uuid']]))
        ->assertNotFound();
    $this->actingAs($this->miningAdministrator)
        ->putJson("/api/v1/users/{$world->officer->id}/portfolio", portfolioBody(['mission_ids' => [$world->berlin->id]]))
        ->assertNotFound();

    expect($world->officer->fresh()->portfolio['mission_ids'])->toBe([$world->berlin->id]);
});

it('refuses every non-administrator, the officer included, without confirming the account (TC-FR-SDT-012-PD)', function (string $roleName) {
    $world = $this->world;
    $actor = $world->member($roleName);

    $this->actingAs($actor)->getJson("/api/v1/users/{$world->officer->id}/portfolio")->assertNotFound();
    $this->actingAs($actor)->putJson("/api/v1/users/{$world->officer->id}/portfolio", portfolioBody(['sectors' => ['coffee']]))->assertNotFound();

    expect($world->officer->fresh()->portfolio['sectors'])->toBe(['tea']);
})->with(['Ministry HQ Officer', 'Ministry PS', 'Ministry HQ Director', 'Ministry Attache', 'HRM&D Officer', 'Head of Mission', 'MFA HQ Officer']);

it('refuses a Ministry Administrator managing a fellow administrator\'s or its own account (TC-FR-SDT-012-PI)', function () {
    $this->actingAs($this->tradeAdministrator)->getJson("/api/v1/users/{$this->tradeAdministrator->id}/portfolio")->assertForbidden();
});

it('keeps portfolios to HQ officer accounts (TC-FR-SDT-012-PE)', function () {
    $world = $this->world;

    $this->actingAs($this->tradeAdministrator)->getJson("/api/v1/users/{$world->director->id}/portfolio")->assertUnprocessable();
    $this->actingAs($this->tradeAdministrator)->putJson("/api/v1/users/{$world->berlinAttache->id}/portfolio", portfolioBody())->assertUnprocessable();
});

it('validates every list (TC-FR-SDT-012-PF)', function (array $body) {
    $this->actingAs($this->tradeAdministrator)
        ->putJson("/api/v1/users/{$this->world->otherOfficer->id}/portfolio", $body)
        ->assertUnprocessable();

    expect($this->world->otherOfficer->fresh()->portfolio)->toBeNull();
})->with([
    'missing lists' => [['mission_ids' => []]],
    'non-list' => [['mission_ids' => 'all', 'countries' => [], 'sectors' => []]],
    'malformed mission' => [portfolioBody(['mission_ids' => ['not-a-uuid']])],
    'unposted mission' => [portfolioBody(['mission_ids' => ['0199a000-0000-7000-8000-000000000000']])],
    'repeated mission' => [fn () => portfolioBody(['mission_ids' => [test()->world->berlin->id, test()->world->berlin->id]])],
    'markup in a country' => [portfolioBody(['countries' => ['<script>']])],
    'regex in a sector' => [portfolioBody(['sectors' => ['tea|coffee']])],
    'sector ending in punctuation' => [portfolioBody(['sectors' => ['tea-']])],
    'one-letter sector' => [portfolioBody(['sectors' => ['t']])],
    'overlong sector' => [portfolioBody(['sectors' => [str_repeat('a', 61)]])],
    'too many sectors' => [portfolioBody(['sectors' => array_map(fn (int $i): string => "sector {$i}", range(1, 31))])],
]);

it('refuses a mission posted only to another department (TC-FR-SDT-012-PG)', function () {
    $perth = HqWorkspaceWorld::linkedMission($this->world->mining, ['name' => 'Perth']);

    $this->actingAs($this->systemAdministrator)
        ->putJson("/api/v1/users/{$this->world->otherOfficer->id}/portfolio", portfolioBody(['mission_ids' => [$perth->id]]))
        ->assertUnprocessable();
});

it('drops the portfolio when the account stops being an HQ officer or changes department (TC-FR-SDT-012-PH)', function () {
    $world = $this->world;
    $director = HqWorkspaceWorld::role('Ministry HQ Director');

    $this->actingAs($this->tradeAdministrator)->patchJson("/api/v1/users/{$world->officer->id}", ['full_name' => 'Carol O. Officer'])->assertOk();
    expect($world->officer->fresh()->portfolio)->not->toBeNull();

    $this->actingAs($this->tradeAdministrator)->patchJson("/api/v1/users/{$world->officer->id}", ['role_id' => $director->id])->assertOk();
    expect($world->officer->fresh()->portfolio)->toBeNull();

    $moved = HqWorkspaceWorld::user('Ministry HQ Officer', $world->trade, null, ['portfolio' => ['mission_ids' => [$world->berlin->id], 'countries' => [], 'sectors' => []]]);
    $this->actingAs($this->systemAdministrator)->patchJson("/api/v1/users/{$moved->id}", ['ministry_id' => $world->mining->id])->assertOk();
    expect(User::query()->find($moved->id)->portfolio)->toBeNull();
});
