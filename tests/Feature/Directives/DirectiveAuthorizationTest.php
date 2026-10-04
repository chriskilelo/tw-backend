<?php

use App\Models\Directive;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Directives\DirectiveWorld;

/**
 * URD Section 10.5 / API-001 Section 8: the role x endpoint authorization
 * matrix for the Directive and Tasking Engine. Only the six directive roles
 * may use the module; the four BR-020 read-only roles, the HRM&D Officer,
 * the Ministry Administrator and every other role are refused on every
 * endpoint, reads included (review gap 1: the list used to leak every
 * ministry's directives to the scope-bypassing governance roles).
 *
 * Every endpoint runs against a fresh directive issued by the world's HQ
 * officer to the world's attache, so the attache is always the target and
 * the officer always the issuer.
 */
beforeEach(function () {
    Queue::fake();
});

/**
 * @return array<string, array{0: string, 1: string, 2: Closure(DirectiveWorld, Directive): array<string, mixed>}>
 */
function directiveMatrixEndpoints(): array
{
    return [
        'list' => ['GET', '/api/v1/directives', fn () => []],
        'assignees' => ['GET', '/api/v1/directives/assignees', fn () => []],
        'summary' => ['GET', '/api/v1/directives/summary', fn () => []],
        'create' => ['POST', '/api/v1/directives', fn (DirectiveWorld $world) => $world->issueBody()],
        'show' => ['GET', '/api/v1/directives/{id}', fn () => []],
        'revise' => ['PATCH', '/api/v1/directives/{id}', fn () => ['target_completion_date' => now()->addDays(10)->toDateString()]],
        'status' => ['PATCH', '/api/v1/directives/{id}/status', fn () => ['status' => 'acknowledged']],
        'note' => ['POST', '/api/v1/directives/{id}/notes', fn () => ['content' => 'Met the buyers this morning.']],
    ];
}

it('applies the role x endpoint authorization matrix (TC-FR-DIR-005-M, TC-FR-DIR-009-M, TC-FR-AUTH-017-DIR)', function (string $roleName, array $expected) {
    $world = DirectiveWorld::create();
    $actor = $world->member($roleName);

    foreach (directiveMatrixEndpoints() as $endpoint => [$method, $uri, $body]) {
        $directive = $world->directive();

        $status = $this->actingAs($actor)
            ->json($method, str_replace('{id}', $directive->id, $uri), $body($world, $directive))
            ->status();

        expect($status)->toBe($expected[$endpoint], "{$roleName} -> {$endpoint}");
    }
})->with([
    'Ministry Attache (target)' => ['Ministry Attache', ['list' => 200, 'assignees' => 403, 'summary' => 403, 'create' => 403, 'show' => 200, 'revise' => 403, 'status' => 200, 'note' => 201]],
    'Ministry HQ Officer (issuer)' => ['Ministry HQ Officer', ['list' => 200, 'assignees' => 200, 'summary' => 403, 'create' => 201, 'show' => 200, 'revise' => 200, 'status' => 403, 'note' => 201]],
    'Ministry PS' => ['Ministry PS', ['list' => 200, 'assignees' => 200, 'summary' => 200, 'create' => 201, 'show' => 200, 'revise' => 403, 'status' => 403, 'note' => 403]],
    'Acting PS' => ['Acting PS', ['list' => 200, 'assignees' => 200, 'summary' => 200, 'create' => 201, 'show' => 200, 'revise' => 403, 'status' => 403, 'note' => 403]],
    'Ministry HQ Director' => ['Ministry HQ Director', ['list' => 200, 'assignees' => 403, 'summary' => 200, 'create' => 403, 'show' => 200, 'revise' => 403, 'status' => 403, 'note' => 403]],
    'System Administrator' => ['System Administrator', ['list' => 200, 'assignees' => 403, 'summary' => 403, 'create' => 403, 'show' => 200, 'revise' => 403, 'status' => 403, 'note' => 403]],
    ...collect([
        'Head of Mission',
        'Deputy Head of Mission',
        'MFA HQ Officer',
        'MFA Principal Secretary',
        'HRM&D Officer',
        'Ministry Administrator',
        'Designated Deputy',
        'Ministry Publishing Authority',
        'Honorary Consul',
    ])->mapWithKeys(fn (string $role): array => [$role => [$role, array_fill_keys(array_keys(directiveMatrixEndpoints()), 403)]])->all(),
]);

it('refuses a Ministry Attache and a Ministry HQ Officer who are not party to a directive (TC-FR-DIR-005-C)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive();

    foreach ([$world->otherAttache, $world->otherOfficer] as $outsider) {
        $this->actingAs($outsider)->getJson("/api/v1/directives/{$directive->id}")->assertForbidden();
        $this->actingAs($outsider)->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => 'acknowledged'])->assertForbidden();
        $this->actingAs($outsider)->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => 'cancelled', 'note' => 'Not mine'])->assertForbidden();
        $this->actingAs($outsider)->postJson("/api/v1/directives/{$directive->id}/notes", ['content' => 'Hello'])->assertForbidden();
        $this->actingAs($outsider)->patchJson("/api/v1/directives/{$directive->id}", ['type_category' => 'Hijack'])->assertForbidden();
    }

    expect($directive->fresh()->status->value)->toBe('issued')
        ->and($directive->fresh()->type_category)->toBeNull()
        ->and($directive->notes()->count())->toBe(0);
});

it('answers 403 before validation to a non-participant, so validation details never leak (TC-FR-DIR-013-C)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive();

    $this->actingAs($world->otherAttache)->patchJson("/api/v1/directives/{$directive->id}/status", [])->assertForbidden();
    $this->actingAs($world->otherAttache)->postJson("/api/v1/directives/{$directive->id}/notes", [])->assertForbidden();
    $this->actingAs($world->otherOfficer)->patchJson("/api/v1/directives/{$directive->id}", [])->assertForbidden();
    $this->actingAs($world->member('Ministry HQ Director'))->postJson('/api/v1/directives', [])->assertForbidden();
});

it('lets an Acting PS issue a directive and then withdraw and close the ones it issued (TC-FR-SDT-004-DIR)', function () {
    $world = DirectiveWorld::create();
    $actingPs = $world->member('Acting PS');

    $id = $this->actingAs($actingPs)->postJson('/api/v1/directives', $world->issueBody())->assertCreated()->json('data.id');

    $this->actingAs($actingPs)->patchJson("/api/v1/directives/{$id}/status", ['status' => 'cancelled', 'note' => 'Superseded by the PS brief.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $completed = $world->directive(['issued_by_user_id' => $actingPs->id, 'status' => 'completed', 'completion_summary' => 'Done.']);

    $this->actingAs($actingPs)->patchJson("/api/v1/directives/{$completed->id}/status", ['status' => 'closed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');
});

it('returns 404 for another ministry\'s directive on every per-directive endpoint (TC-NFR-SEC-006-DIR-A)', function () {
    $world = DirectiveWorld::create();
    $foreign = DirectiveWorld::create()->directive();

    foreach ([$world->attache, $world->officer, $world->ps, $world->director] as $actor) {
        $this->actingAs($actor)->getJson("/api/v1/directives/{$foreign->id}")->assertNotFound();
        $this->actingAs($actor)->patchJson("/api/v1/directives/{$foreign->id}", ['type_category' => 'X'])->assertNotFound();
        $this->actingAs($actor)->patchJson("/api/v1/directives/{$foreign->id}/status", ['status' => 'acknowledged'])->assertNotFound();
        $this->actingAs($actor)->postJson("/api/v1/directives/{$foreign->id}/notes", ['content' => 'X'])->assertNotFound();
    }

    expect($foreign->fresh()->status->value)->toBe('issued');
});
