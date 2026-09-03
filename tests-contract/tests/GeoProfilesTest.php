<?php

/*
|--------------------------------------------------------------------------
| GeoProfiles contract tests
|--------------------------------------------------------------------------
|
| Locks down the `geoProfiles` module contract (`Component\GeoProfiles\
| Controller\GeoProfilesController` — registered as camelCase `geoProfiles`
| by the legacy Initializer, lowercased by ObjectDispatchController's own
| convention), run against the backend named by TDS_TEST_TARGET.
|
| `countries` is sent as a JSON body (Content-Type: application/json,
| ApiClient::post()'s default) - verified live against legacy port 8090
| that a form-urlencoded `countries[]=X&countries[]=Y` body does NOT
| populate `$params["countries"]` as an array the way a JSON body does
| (silently produces a profile with `countries: null`), so this suite
| only exercises the JSON-body path, matching how ApiClient always sends
| POST bodies.
|
*/

use Tests\Support\ApiClient;

beforeEach(function () {
    $this->api = new ApiClient();
    $loginResponse = $this->api->login();

    expect($loginResponse->getStatusCode())->toBe(200);
    $loginBody = ApiClient::json($loginResponse);
    expect($loginBody)->toBeArray()->and($loginBody['success'] ?? null)->toBeTrue();
});

function createGeoProfile(ApiClient $api, array $countries, ?string $name = null): array
{
    $name ??= 'CT geoprofile ' . bin2hex(random_bytes(4));
    $response = $api->post('geoProfiles.create', [], ['name' => $name, 'countries' => $countries]);
    expect($response->getStatusCode())->toBe(200);

    return ApiClient::json($response);
}

describe('geoProfiles.create / show / index', function () {
    test('create joins the countries array with spaces internally but always returns it as an array, with a real decorated name', function () {
        $profile = createGeoProfile($this->api, ['US', 'CA']);

        expect($profile)->toHaveKeys(['id', 'name', 'countries', 'decorated_countries']);
        expect($profile['countries'])->toBe(['US', 'CA']);
        expect($profile['decorated_countries'])->toBe('United States, Canada');

        try {
            $shown = ApiClient::json($this->api->get('geoProfiles.show', ['id' => $profile['id']]));
            // Key ORDER differs between legacy's create/show responses
            // (verified live against port 8090 - same keys/values, different
            // order) - a JSON object has no defined key order, so compare by
            // content (ksort) rather than requiring identical array order.
            ksort($shown);
            $expected = $profile;
            ksort($expected);
            expect($shown)->toBe($expected);

            $index = ApiClient::json($this->api->get('geoProfiles.index'));
            $ids = array_column($index, 'id');
            expect($ids)->toContain($profile['id']);
        } finally {
            $this->api->post('geoProfiles.delete', [], ['id' => $profile['id']]);
        }
    });

    test('listAsOptions returns the exact same shape as index', function () {
        $profile = createGeoProfile($this->api, ['GB']);

        try {
            $viaIndex = ApiClient::json($this->api->get('geoProfiles.index'));
            $viaOptions = ApiClient::json($this->api->get('geoProfiles.listAsOptions'));
            expect($viaOptions)->toBe($viaIndex);
        } finally {
            $this->api->post('geoProfiles.delete', [], ['id' => $profile['id']]);
        }
    });

    test('show/update/delete on a non-existent id are all a real 404, not a 200-with-null', function () {
        $missingId = 999999999;

        $show = $this->api->get('geoProfiles.show', ['id' => $missingId]);
        expect($show->getStatusCode())->toBe(404);

        $update = $this->api->post('geoProfiles.update', ['id' => $missingId], ['name' => 'x']);
        expect($update->getStatusCode())->toBe(404);

        $delete = $this->api->post('geoProfiles.delete', ['id' => $missingId]);
        expect($delete->getStatusCode())->toBe(404);
    });
});

describe('geoProfiles.update / delete', function () {
    test('update replaces name and countries, delete really removes it', function () {
        $profile = createGeoProfile($this->api, ['US']);

        $updateResponse = $this->api->post('geoProfiles.update', ['id' => $profile['id']], [
            'name' => 'Updated name',
            'countries' => ['DE', 'FR'],
        ]);
        expect($updateResponse->getStatusCode())->toBe(200);
        $updated = ApiClient::json($updateResponse);
        expect($updated['name'])->toBe('Updated name');
        expect($updated['countries'])->toBe(['DE', 'FR']);

        $deleteResponse = $this->api->post('geoProfiles.delete', [], ['id' => $profile['id']]);
        expect($deleteResponse->getStatusCode())->toBe(200);

        $afterDelete = $this->api->get('geoProfiles.show', ['id' => $profile['id']]);
        expect($afterDelete->getStatusCode())->toBe(404);
    });
});
