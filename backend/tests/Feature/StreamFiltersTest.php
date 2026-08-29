<?php

use App\Http\Controllers\Admin\StreamFiltersController;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| StreamFilters compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=streamFilters.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\StreamFiltersController).
|
| `object=streamFilters` is NOT a CRUD module — the only action is
| `filters`, returning the static filter-type catalogue used by the stream
| condition-builder UI (see docs/legacy-reference/frontend/api/
| 10.2_streams.md, "StreamFilters"). No ACL is involved (it's a pure
| reference-data lookup, same as legacy `FilterRepository::
| getFiltersAsOptions()` which takes no user/campaign argument).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function streamFiltersEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "streamFilters.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Same indirection as tests/Feature/StreamsTest.php::actingAsAdminForStreams()
 * — duplicated under a distinct name since Pest loads every test file into
 * one process.
 */
function actingAsAdminForStreamFilters(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

it('returns the filter-type catalogue as a JSON array', function () {
    $response = $this->getJson(streamFiltersEndpoint('filters'));

    $response->assertStatus(200);

    $data = $response->json();

    expect($data)->toBeArray()->not->toBeEmpty();

    foreach ($data as $entry) {
        expect($entry)->toHaveKeys(['value', 'tooltip', 'modes', 'group', 'template', 'header_template', 'defaults']);
        expect($entry['value'])->toBeString();
        expect($entry['group'])->toBeString();
    }
});

it('includes the well-known geo/device/parameter filter types by value', function () {
    $response = $this->getJson(streamFiltersEndpoint('filters'));

    $response->assertStatus(200);

    $values = array_column($response->json(), 'value');

    foreach (['country', 'region', 'city', 'language', 'browser', 'os', 'ip', 'isp', 'connection_type', 'device_type', 'uniqueness', 'interval', 'limit'] as $expected) {
        expect($values)->toContain($expected);
    }
});

it('gives the Limit filter a null modes toggle (no accept/reject)', function () {
    $response = $this->getJson(streamFiltersEndpoint('filters'));

    $entries = collect($response->json());
    $limit = $entries->firstWhere('value', 'limit');

    expect($limit)->not->toBeNull();
    expect($limit['modes'])->toBeNull();
});

it('gives the Uniqueness filter a "stream" default payload', function () {
    $response = $this->getJson(streamFiltersEndpoint('filters'));

    $entries = collect($response->json());
    $uniqueness = $entries->firstWhere('value', 'uniqueness');

    expect($uniqueness)->not->toBeNull();
    expect($uniqueness['defaults'])->toBe('stream');
});

it('does not require authentication (pure reference data, unlike streams/campaigns)', function () {
    actingAsAdminForStreamFilters(null);

    $response = $this->getJson(streamFiltersEndpoint('filters'));

    $response->assertStatus(200);
    expect($response->json())->toBeArray()->not->toBeEmpty();
});

it('exposes the same valid filter names via StreamFiltersController::validNames() used for stream-nested filter validation', function () {
    $names = StreamFiltersController::validNames();

    expect($names)->toBeArray()->not->toBeEmpty();
    expect($names)->toContain('country', 'uniqueness', 'uniqueness_cookie', 'uniqueness_ip', 'sub_id_1');
});
