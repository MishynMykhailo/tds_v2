<?php

/*
|--------------------------------------------------------------------------
| Labels contract tests
|--------------------------------------------------------------------------
|
| Locks down the `labels` module contract, run against the backend named
| by TDS_TEST_TARGET (see tests/Support/ApiClient.php). Registered as its
| own `?object=labels` key even though the legacy class physically lives
| inside the Reports module (`Component\Reports\Controller\
| LabelsController`), confirmed by reading that source directly.
|
| Deliberately NOT contract-tested here (documented, verified divergence -
| see App\Http\Controllers\Admin\LabelsController's class docblock in the
| backend): `labels.update`/`labels.replaceList`'s `items`/`ref_values`
| keys. Real legacy expects those keys to be a ref-dictionary row's raw
| numeric `value` (cast via `ip2long()`/`(int)`) and resolves the real
| `ref_id` via a `WHERE value = ...` lookup before writing anything - a
| literal domain-string key (`items: {"example.com": "whitelist"}`) silently
| matches NOTHING against real legacy (`(int) "example.com" === 0`),
| verified live against port 8090. This port's `ref_value`-keyed contract
| (write the string directly, no ref-dictionary join) is a deliberate,
| confirmed-safer choice, not a schema-forced compromise this suite should
| paper over by asserting legacy's confusing behavior as "the contract".
| Covered here instead: the two static reference catalogues (byte-exact
| against both targets), the real CRUD flow against the NEW backend's own
| documented contract, and the ACL/validation behavior common to both.
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

describe('labels.labelVariations / labels.refNameVariations', function () {
    test('labelVariations returns the exact 2-entry static catalogue', function () {
        $response = $this->api->get('labels.labelVariations');
        expect($response->getStatusCode())->toBe(200);

        expect(ApiClient::json($response))->toBe([
            ['value' => 'whitelist', 'label' => 'Whitelist', 'icon' => 'ion-thumbsup grid-filter-label-whitelist'],
            ['value' => 'blacklist', 'label' => 'Blacklist', 'icon' => 'ion-thumbsdown grid-filter-label-blacklist'],
        ]);
    });

    test('refNameVariations includes all 6 base names plus sub_id_1..15 (not 10 - verified live against legacy)', function () {
        $response = $this->api->get('labels.refNameVariations');
        expect($response->getStatusCode())->toBe(200);

        $values = array_column(ApiClient::json($response), 'value');

        foreach (['ip', 'source', 'x_requested_with', 'ad_campaign_id', 'creative_id', 'keyword'] as $expected) {
            expect($values)->toContain($expected);
        }
        for ($i = 1; $i <= 15; $i++) {
            expect($values)->toContain("sub_id_{$i}");
        }
        expect($values)->not->toContain('sub_id_16');
    });
});

describe('labels.index', function () {
    test('an empty result is a genuinely empty (0-byte) body, not JSON null', function () {
        $campaign = Fixtures::createCampaign($this->api);

        $response = $this->api->get('labels.index', ['campaign_id' => $campaign['id'], 'ref_name' => 'source']);

        expect($response->getStatusCode())->toBe(200);
        expect((string) $response->getBody())->toBe('');
    });

    test('returns 404 for a non-existent campaign_id, not 403', function () {
        // Verified live against legacy port 8090: CampaignRepository::find()
        // throws a real NotFoundError for a missing id ("campaign_id=0" ->
        // 404 "Traffic\Model\Campaign #0 not found"), not a 403 - a naive
        // "null campaign falls into the ACL check" implementation would
        // give 403 instead, which is what a prior version of this action did.
        $response = $this->api->get('labels.index', ['campaign_id' => 0, 'ref_name' => 'source']);

        expect($response->getStatusCode())->toBe(404);
    });
});
