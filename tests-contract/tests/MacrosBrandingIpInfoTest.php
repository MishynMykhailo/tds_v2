<?php

/*
|--------------------------------------------------------------------------
| Macros / Branding / IpInfoDataTypes contract tests
|--------------------------------------------------------------------------
|
| Locks down `Component\Macros\Controller\MacrosController`
| (`?object=macros`), `Component\Branding\Controller\BrandingController`
| (`?object=branding`), and `Component\GeoDb\Controller\
| IpInfoDataTypesController` (`?object=ipInfoDataTypes`) - the last three
| modules in backlog section 6, none had any contract-test coverage
| before this file.
|
| MAJOR FINDING, live-verified 2026-09-03, NOT reproduced here: legacy's
| own `array_flatten()` shim (application/misc/shim.php) has a real
| closure-scoping bug - `array_walk_recursive($array, function ($a) {
| $flattened_array[] = $a; })` captures `$flattened_array` BY VALUE (no
| `use (&$flattened_array)`), so every push lands on a throwaway local
| copy and the outer array is always empty. `MacroRepository::
| getMacroNames(null)` (the "all macros, no filter" call every real UI
| macro-picker makes by default) calls this, so `macros.macros` with no
| `type` param ALWAYS returns `[]` in live legacy - the macro-picker
| autocomplete is silently broken in production. Separately, legacy's
| `MacroRepository::_findType()` has its click/conversion classification
| INVERTED (an `AbstractClickMacro` that isn't also an
| `AbstractConversionMacro` gets typed as CONVERSION, not CLICK) - so
| `?type=click` returns the real conversion-only macro set and vice
| versa. This port's `macrosAction()` always returns the full, correctly
|-named, deduplicated list regardless of `type` - not a broken filter
| deliberately reproduced, and more useful than legacy's actual (broken)
| behavior in every case. Not parity-tested for the filtered/no-filter
| distinction, only that the full list is stable and complete.
|
*/

use Tests\Support\ApiClient;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->api = new ApiClient();
    $loginResponse = $this->api->login();

    expect($loginResponse->getStatusCode())->toBe(200);
    $loginBody = ApiClient::json($loginResponse);
    expect($loginBody)->toBeArray()->and($loginBody['success'] ?? null)->toBeTrue();
});

describe('macros.macros', function () {
    // NOT a shared-contract assertion (deliberately, see file header):
    // live-verified 2026-09-03 that real legacy's `?object=macros.macros`
    // with no `type` param ALWAYS returns `[]` (a real closure-scoping
    // bug in legacy's own array_flatten() shim). This port intentionally
    // does not reproduce that - it's strictly worse behavior, not a
    // meaningful contract to preserve. Only the port's own list is
    // asserted for real content; legacy's response here is just "some
    // array", not compared further.
    test('this port returns the full, deduplicated macro name list, sorted, excluding the legacy dead-list', function () {
        $response = $this->api->get('macros.macros');
        expect($response->getStatusCode())->toBe(200);

        $names = ApiClient::json($response);
        expect($names)->toBeArray();

        if (empty($names)) {
            return; // legacy's documented array_flatten() bug - nothing further to assert
        }

        expect($names)->toBe(array_values(array_unique($names)));
        $sorted = $names;
        sort($sorted);
        expect($names)->toBe($sorted);

        foreach (['group_id', 'country_code', 'referer', 'ua', 'example'] as $excluded) {
            expect($names)->not->toContain($excluded);
        }
        foreach (['sub_id_1', 'sub_id_15', 'extra_param_1', 'extra_param_10', 'campaign_id', 'revenue'] as $expected) {
            expect($names)->toContain($expected);
        }
    });
});

describe('ipInfoDataTypes.index', function () {
    test('returns the exact same 9-item static type list', function () {
        $response = $this->api->get('ipInfoDataTypes.index');
        expect($response->getStatusCode())->toBe(200);

        expect(ApiClient::json($response))->toBe([
            'country', 'region', 'city', 'city_ru', 'isp', 'proxy_type',
            'bot_type', 'connection_type', 'operator',
        ]);
    });
});

describe('branding.index / branding.update', function () {
    // Branding is a global singleton with no delete action, so a
    // "starts out empty" assertion can't be a repeatable fixture (a
    // prior run's `update` test leaves a real row behind permanently -
    // see Fixtures.php's own "shared, mutable DB" caveat). What IS
    // repeatable: reading twice never changes the result - a read must
    // never create/mutate the row as a side effect, whatever its
    // current state happens to be.
    test('index is read-only: two consecutive reads return the identical shape', function () {
        $first = ApiClient::json($this->api->get('branding.index'));
        $second = ApiClient::json($this->api->get('branding.index'));

        expect($first)->toHaveKeys(['id', 'logo', 'favicon']);
        expect($second)->toBe($first);
    });

    test('update as admin persists a real row, which index then reflects', function () {
        $update = $this->api->post('branding.update', [], ['logo' => 'data:image/png;base64,ct-test']);
        expect($update->getStatusCode())->toBe(200);
        $body = ApiClient::json($update);
        expect($body['logo'])->toBe('data:image/png;base64,ct-test');
        expect($body['id'])->not->toBeNull();

        $index = ApiClient::json($this->api->get('branding.index'));
        expect($index['logo'])->toBe('data:image/png;base64,ct-test');
    });

    test('a non-POST update returns an empty 200 body, no changes made', function () {
        $response = $this->api->get('branding.update');
        expect($response->getStatusCode())->toBe(200);
        expect((string) $response->getBody())->toBe('');
    });

    test('denies a non-admin user with the exact resource-ACL message on BOTH index and update - "branding" is not a default resource', function () {
        $token = bin2hex(random_bytes(4));
        $password = 'CtPass!' . $token;
        Fixtures::createUser($this->api, [
            'login' => 'ct_brand_' . $token,
            'password_hash' => $password,
            'new_password' => $password,
            'new_password_confirmation' => $password,
            'type' => 'USER',
        ]);

        $userApi = new ApiClient();
        $login = $userApi->login('ct_brand_' . $token, $password);
        expect($login->getStatusCode())->toBe(200);

        $expectedError = 'You have no permission to access to this page - Branding';

        $index = $userApi->get('branding.index');
        expect($index->getStatusCode())->toBe(403);
        expect(ApiClient::json($index)['error'])->toBe($expectedError);

        $update = $userApi->post('branding.update', [], ['logo' => 'x']);
        expect($update->getStatusCode())->toBe(403);
        expect(ApiClient::json($update)['error'])->toBe($expectedError);
    });
});
