<?php

use App\Models\Domain;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\DomainFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Domains compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=domains.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\DomainsController) through Laravel's internal
| HTTP testing helpers (getJson/postJson) — no external HTTP calls.
|
| Contract reference: docs/legacy-reference/frontend/api/10.7_domains.md.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function domainsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "domains.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Same indirection as tests/Feature/OffersTest.php::actingAsAdminForOffers()
 * / tests/Feature/LandingsTest.php::actingAsAdminForLandings() — duplicated
 * under a distinct name since Pest loads every test file into one process.
 */
function actingAsAdminForDomains(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForDomains($admin);
});

it('lists domains as a JSON array', function () {
    DomainFactory::new()->count(3)->create();

    $response = $this->getJson(domainsEndpoint('index'));

    $response->assertStatus(200);

    expect($response->json())->toBeArray()->and($response->json())->toHaveCount(3);
});

it('shows an active domain with every model field', function () {
    $domain = DomainFactory::new()->create();

    $response = $this->getJson(domainsEndpoint('show', ['id' => $domain->id]));

    $response->assertStatus(200);

    $data = $response->json();

    foreach ((new Domain)->getFillable() as $field) {
        expect($data)->toHaveKey($field);
    }
    expect($data)->toHaveKey('id');
    expect($data)->toHaveKey('campaigns_count');
});

// Literal legacy quirk, verified live against the real legacy backend
// directly (2026-09-03): `domains.show` NEVER 404s. A missing/nonexistent/
// inactive-state id all resolve `findActive()` to null, and legacy
// serializes that null anyway — HTTP 200, body missing id/name but still
// carrying the `extra()` fields (campaigns_count/default_campaign/
// error_solution). See DomainsController::showAction()'s own docblock.
it('returns the missing-domain quirk shape for show of an archived (state != active) domain', function () {
    // Legacy `findActive($id)` filters on the generic `state` column (NOT
    // `network_status`) — an archived domain hits the same quirk below
    // even though its network_status may still say "active".
    $domain = DomainFactory::new()->archived()->create();

    $response = $this->getJson(domainsEndpoint('show', ['id' => $domain->id]));

    $response->assertStatus(200);
    $body = $response->json();
    expect($body)->not->toHaveKey('id');
    expect($body)->not->toHaveKey('name');
    expect($body)->toHaveKeys(['campaigns_count', 'default_campaign', 'error_solution']);
});

it('returns the missing-domain quirk shape for show without a valid id', function () {
    $response = $this->getJson(domainsEndpoint('show'));

    $response->assertStatus(200);
    $body = $response->json();
    expect($body)->not->toHaveKey('id');
    expect($body)->not->toHaveKey('name');
    expect($body)->toHaveKeys(['campaigns_count', 'default_campaign', 'error_solution']);
});

it('returns the missing-domain quirk shape for show with a non-existent id', function () {
    $response = $this->getJson(domainsEndpoint('show', ['id' => 999999]));

    $response->assertStatus(200);
    $body = $response->json();
    expect($body)->not->toHaveKey('id');
    expect($body)->not->toHaveKey('name');
    expect($body)->toHaveKeys(['campaigns_count', 'default_campaign', 'error_solution']);
});

it('creates a domain given a valid name, forcing is_ssl false and network_status validating', function () {
    $response = $this->postJson(domainsEndpoint('create'), [
        'name' => 'example.com',
    ]);

    $response->assertStatus(200);

    // Legacy `createMultiple()` responds with an ARRAY of domains (one per
    // comma-separated name) — confirmed live against real legacy
    // (tests-contract/tests/DomainsTest.php, §10.7), holds even for the
    // single-domain case this port supports.
    $data = $response->json()[0];
    expect($data['name'])->toBe('example.com');
    expect($data['network_status'])->toBe('validating');
    expect($data['is_ssl'])->toBeFalse();

    $this->assertDatabaseHas('domains', [
        'name' => 'example.com',
        'network_status' => 'validating',
        'is_ssl' => 0,
    ]);
});

it('takes only the first comma-separated segment as the domain name on create', function () {
    $response = $this->postJson(domainsEndpoint('create'), [
        'name' => 'first.com,second.com',
    ]);

    $response->assertStatus(200);

    $data = $response->json()[0];
    expect($data['name'])->toBe('first.com');

    $this->assertDatabaseHas('domains', ['name' => 'first.com']);
    $this->assertDatabaseMissing('domains', ['name' => 'second.com']);
});

it('rejects domain creation without a name with a 406 and a name error', function () {
    $response = $this->postJson(domainsEndpoint('create'), []);

    $response->assertStatus(406);

    $data = $response->json();
    expect($data)->toHaveKey('name');
});

it('updates a domain that is not yet active', function () {
    $domain = DomainFactory::new()->validating()->create(['name' => 'old-name.com']);

    $response = $this->postJson(domainsEndpoint('update', ['id' => $domain->id]), [
        'name' => 'new-name.com',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('domains', [
        'id' => $domain->id,
        'name' => 'new-name.com',
    ]);
});

it('silently ignores a name change for an already-active domain', function () {
    $domain = DomainFactory::new()->create(['name' => 'active-domain.com', 'network_status' => 'active']);

    $response = $this->postJson(domainsEndpoint('update', ['id' => $domain->id]), [
        'name' => 'renamed.com',
        'notes' => 'updated notes',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('domains', [
        'id' => $domain->id,
        'name' => 'active-domain.com',
        'notes' => 'updated notes',
    ]);
});

it('ignores direct network_status/is_ssl edits through update', function () {
    $domain = DomainFactory::new()->validating()->create();

    $response = $this->postJson(domainsEndpoint('update', ['id' => $domain->id]), [
        'network_status' => 'active',
        'is_ssl' => true,
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('domains', [
        'id' => $domain->id,
        'network_status' => 'validating',
        'is_ssl' => 0,
    ]);
});

it('translates legacy redirect/is_robots_allowed fields on update', function () {
    $domain = DomainFactory::new()->validating()->create([
        'ssl_redirect' => false,
        'allow_indexing' => false,
    ]);

    $response = $this->postJson(domainsEndpoint('update', ['id' => $domain->id]), [
        'redirect' => 'https',
        'is_robots_allowed' => true,
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('domains', [
        'id' => $domain->id,
        'ssl_redirect' => 1,
        'allow_indexing' => 1,
    ]);
});

it('lists domains as options with a default entry by default', function () {
    DomainFactory::new()->count(2)->create();

    $response = $this->getJson(domainsEndpoint('listAsOptions'));

    $response->assertStatus(200);

    $data = $response->json();

    expect($data)->toHaveCount(3);
    expect($data[0]['value'])->toBeNull();
});

it('lists domains as options without a default entry when add_default=0', function () {
    DomainFactory::new()->count(2)->create();

    $response = $this->getJson(domainsEndpoint('listAsOptions', ['add_default' => '0']));

    $response->assertStatus(200);

    $data = $response->json();

    expect($data)->toHaveCount(2);
});

it('denies a guest (no current user) access to view a domain with a 403', function () {
    $domain = DomainFactory::new()->create();
    actingAsAdminForDomains(null);

    $response = $this->getJson(domainsEndpoint('show', ['id' => $domain->id]));

    $response->assertStatus(403);
    expect($response->json())->toHaveKey('error');
});

it('denies a guest (no current user) access to create a domain with a 403', function () {
    actingAsAdminForDomains(null);

    $response = $this->postJson(domainsEndpoint('create'), [
        'name' => 'guest-domain.com',
    ]);

    $response->assertStatus(403);

    $this->assertDatabaseMissing('domains', ['name' => 'guest-domain.com']);
});

it('denies a guest (no current user) access to update a domain with a 403', function () {
    $domain = DomainFactory::new()->create(['notes' => 'Original']);
    actingAsAdminForDomains(null);

    $response = $this->postJson(domainsEndpoint('update', ['id' => $domain->id]), [
        'notes' => 'Changed',
    ]);

    $response->assertStatus(403);
    $this->assertDatabaseHas('domains', ['id' => $domain->id, 'notes' => 'Original']);
});
