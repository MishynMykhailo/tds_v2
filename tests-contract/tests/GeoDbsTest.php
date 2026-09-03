<?php

/*
|--------------------------------------------------------------------------
| GeoDbs contract tests
|--------------------------------------------------------------------------
|
| Locks down the `geoDbs` module contract (`Component\GeoDb\Controller\
| GeoDbsController`), run against the backend named by TDS_TEST_TARGET.
|
| `geoDbs.index` is a STATIC list of 15 known geo db types — id/name/type/
| data_types/is_recommended/setting_key/purchase_link never change and are
| compared field-by-field on both targets. `exists`/`path`/`time`/`error`/
| `update_available` are NOT compared: they depend on which files happen to
| be physically installed on each environment's disk (legacy's dev
| container ships a real ip2location_lite file + attempts a live network
| update-check against the provider; this port's dev tree may or may not
| have one at any given moment, and doesn't attempt any network call at
| all — see GeoDbsController's own class docblock, "real AUTOMATED
| download... deliberately OUT OF SCOPE"). Verified live (2026-09-03)
| that legacy's `error`/`update_available` split isn't a simple
| exists-vs-not switch either — `ip2location_lite`/`sypex_lite`/
| `maxmind_lite` (the 3 types with a real live-fetchable HostedDownloadManager)
| get `error` (a real failed HTTP call to the provider, 404 in this
| environment); every other type gets `update_available: false` — not
| reproduced here, it's the exact automated-download machinery this port
| deliberately does not implement.
|
| `geoDbs.update` is a documented, deliberate divergence: legacy attempts a
| real network fetch (and 500s when that fails, as it does in this offline
| dev environment); this port always 501s with an explanatory message,
| regardless of id. Both are asserted for what they actually do, not
| pretended to match.
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

function geoDbFind(array $items, string $id): ?array
{
    foreach ($items as $item) {
        if ($item['id'] === $id) {
            return $item;
        }
    }

    return null;
}

function geoDbStableFields(array $item): array
{
    return [
        'id' => $item['id'],
        'name' => $item['name'],
        'type' => $item['type'],
        'data_types' => $item['data_types'],
        'is_recommended' => $item['is_recommended'],
        'setting_key' => $item['setting_key'],
        'purchase_link' => $item['purchase_link'],
    ];
}

describe('geoDbs.index', function () {
    test('lists the same 15 known db types with identical static metadata on both targets', function () {
        $response = $this->api->get('geoDbs.index');
        expect($response->getStatusCode())->toBe(200);

        $items = ApiClient::json($response);
        expect($items)->toHaveCount(15);

        $stable = array_map('geoDbStableFields', $items);
        usort($stable, fn ($a, $b) => $a['id'] <=> $b['id']);

        // A hardcoded, cross-verified-against-legacy-source snapshot of the
        // one thing that is genuinely static in both codebases — not
        // reading the new port's own DB_TYPES const back at itself, which
        // would prove nothing.
        expect($stable)->toBe([
            ['id' => 'ip2location_full', 'name' => 'IP2Location DB3 Full', 'type' => 'external', 'data_types' => ['country', 'region', 'city'], 'is_recommended' => null, 'setting_key' => 'ip2location_full_token', 'purchase_link' => 'https://tds.io/go/iptolocation'],
            ['id' => 'ip2location_full_isp', 'name' => 'IP2Location DB4', 'type' => 'external', 'data_types' => ['country', 'region', 'city', 'isp'], 'is_recommended' => true, 'setting_key' => 'ip2location_full_isp_token', 'purchase_link' => 'https://tds.io/go/iptolocation'],
            ['id' => 'ip2location_lite', 'name' => 'IP2Location DB3 Lite', 'type' => 'hosted', 'data_types' => ['country', 'region', 'city'], 'is_recommended' => null, 'setting_key' => null, 'purchase_link' => null],
            ['id' => 'ip2location_px2', 'name' => 'IP2Location PX2', 'type' => 'external', 'data_types' => ['country', 'proxy_type'], 'is_recommended' => true, 'setting_key' => 'ip2location_px2_token', 'purchase_link' => 'https://tds.io/go/iptolocation'],
            ['id' => 'maxmind_connection_types', 'name' => 'Maxmind Connection Type (Legacy)', 'type' => 'external', 'data_types' => ['connection_type'], 'is_recommended' => null, 'setting_key' => 'maxmind_connection_type_key', 'purchase_link' => null],
            ['id' => 'maxmind_country_full', 'name' => 'Maxmind Country Full (Legacy)', 'type' => 'external', 'data_types' => ['country'], 'is_recommended' => null, 'setting_key' => 'maxmind_country_key', 'purchase_link' => null],
            ['id' => 'maxmind_full', 'name' => 'Maxmind City Full (Legacy)', 'type' => 'external', 'data_types' => ['country', 'region', 'city'], 'is_recommended' => null, 'setting_key' => 'maxmind_city_key', 'purchase_link' => null],
            ['id' => 'maxmind_isp', 'name' => 'Maxmind ISP', 'type' => 'external', 'data_types' => ['isp'], 'is_recommended' => null, 'setting_key' => 'maxmind_isp_key', 'purchase_link' => null],
            ['id' => 'maxmind_lite', 'name' => 'Maxmind City Lite (Legacy)', 'type' => 'hosted', 'data_types' => ['country', 'region', 'city'], 'is_recommended' => null, 'setting_key' => null, 'purchase_link' => null],
            ['id' => 'proip_essential', 'name' => 'ProIP Essential', 'type' => 'external', 'data_types' => ['country', 'region', 'city', 'isp'], 'is_recommended' => true, 'setting_key' => 'proip_essential_key', 'purchase_link' => 'https://tds.io/go/proip'],
            ['id' => 'sypex_full', 'name' => 'Sypex City Full', 'type' => 'external', 'data_types' => ['country', 'region', 'city', 'city_ru'], 'is_recommended' => null, 'setting_key' => 'sx_full_key', 'purchase_link' => null],
            ['id' => 'sypex_lite', 'name' => 'Sypex City Lite', 'type' => 'hosted', 'data_types' => ['country', 'region', 'city', 'city_ru'], 'is_recommended' => null, 'setting_key' => null, 'purchase_link' => null],
            ['id' => 'tds_bot_db2', 'name' => 'Tds BotDB2', 'type' => 'hosted', 'data_types' => ['bot_type'], 'is_recommended' => true, 'setting_key' => null, 'purchase_link' => null],
            ['id' => 'tds_carrier', 'name' => 'Tds Mobile Operator v3', 'type' => 'hosted', 'data_types' => ['operator', 'connection_type'], 'is_recommended' => true, 'setting_key' => null, 'purchase_link' => null],
            ['id' => 'user_bot_ip_db', 'name' => 'user_bot_ip_db', 'type' => 'internal', 'data_types' => ['bot_type'], 'is_recommended' => null, 'setting_key' => null, 'purchase_link' => null],
        ]);
    });

    test('an external db with no configured token reports no_key status and a null key', function () {
        $items = ApiClient::json($this->api->get('geoDbs.index'));
        $item = geoDbFind($items, 'ip2location_full');

        expect($item['status_code'])->toBe('no_key');
        expect($item['key'])->toBeNull();
    });

    // Deliberately NOT parity-tested for a *configured* key: found live
    // (2026-09-03) that legacy's real status resolution, once a non-"0"
    // key is present, attempts an actual outbound HTTP validity check
    // against the provider (application/Component/GeoDb/ProIP/*) - which
    // 500s in this offline dev environment (`An error occurred. Please
    // check Maintenance > Log`), reproduced twice via direct curl. This
    // port's `resolveStatus()` deliberately never makes that live call
    // (see GeoDbsController's class docblock, "OK/no-message is the
    // honest floor") and always returns 200 with the key echoed back
    // verbatim - a real, permanent behavioral divergence, not a bug to
    // chase, so it isn't asserted as shared parity here.
});

describe('geoDbs.settings / saveSettings', function () {
    test('round-trips the data_type => db_id map and overwrites it on a second call', function () {
        $before = ApiClient::json($this->api->get('geoDbs.settings'));

        try {
            $first = $this->api->post('geoDbs.saveSettings', [], [
                'settings' => ['country' => 'ip2location_lite', 'isp' => 'maxmind_isp'],
            ]);
            expect($first->getStatusCode())->toBe(200);
            expect(ApiClient::json($first))->toBe(['country' => 'ip2location_lite', 'isp' => 'maxmind_isp']);

            $read = ApiClient::json($this->api->get('geoDbs.settings'));
            expect($read)->toBe(['country' => 'ip2location_lite', 'isp' => 'maxmind_isp']);

            $second = $this->api->post('geoDbs.saveSettings', [], [
                'settings' => ['country' => 'sypex_lite'],
            ]);
            expect($second->getStatusCode())->toBe(200);
            expect(ApiClient::json($second))->toBe(['country' => 'sypex_lite']);
        } finally {
            // geoDbs.saveSettings has no partial-update mode (it replaces
            // the whole map, verified by the round-trip above) - restore
            // whatever was there before this test touched it.
            $this->api->post('geoDbs.saveSettings', [], ['settings' => $before]);
        }
    });

    test('rejects a non-array settings payload with a 500', function () {
        $response = $this->api->post('geoDbs.saveSettings', [], ['settings' => 'not-an-array']);
        expect($response->getStatusCode())->toBe(500);
    });
});

describe('geoDbs.update — documented divergence, not parity', function () {
    test('legacy attempts a real network fetch and 500s offline; the port always 501s, both non-2xx', function () {
        $response = $this->api->post('geoDbs.update', [], ['id' => 'ip2location_lite']);

        // Deliberately NOT asserting a shared status code - see file
        // header. Both are real, honest failures for an environment with
        // no license/network access to the actual geo db providers; only
        // "not a fake success" is the shared contract.
        expect($response->getStatusCode())->toBeGreaterThanOrEqual(500);
    });

    test('denies update to a non-admin user with a 403 on both targets', function () {
        $token = bin2hex(random_bytes(4));
        $password = 'CtPass!' . $token;
        Fixtures::createUser($this->api, [
            'login' => 'ct_geodb_' . $token,
            'password_hash' => $password,
            'new_password' => $password,
            'new_password_confirmation' => $password,
            'type' => 'USER',
        ]);

        $userApi = new ApiClient();
        $login = $userApi->login('ct_geodb_' . $token, $password);
        expect($login->getStatusCode())->toBe(200);

        $response = $userApi->post('geoDbs.update', [], ['id' => 'ip2location_lite']);
        expect($response->getStatusCode())->toBe(403);
    });
});
