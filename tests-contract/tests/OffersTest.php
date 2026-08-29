<?php

/*
|--------------------------------------------------------------------------
| Offers contract tests
|--------------------------------------------------------------------------
|
| Locks down the `offers` module contract documented in
| docs/legacy-reference/frontend/api/10.3_offers.md, run against the
| backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| Every test below creates its OWN offer fixture via `offers.create`
| (see tests/Support/Fixtures.php) before reading/asserting anything - it
| never depends on a specific pre-existing row, since the target database
| is live and shared (see CampaignsTest.php for the full rationale).
|
| §10.3 field shapes below were verified live against TDS_TEST_TARGET
| (not just read off the doc), same as the Fixtures::createOffer() doc
| comment notes for the create response.
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

// §10.3: OfferSerializer has $_fields = true (all raw model fields pass
// through), verified live via offers.show on a freshly created offer.
const OFFER_RAW_FIELDS = [
    'id', 'name', 'group_id', 'action_payload', 'affiliate_network_id',
    'payout_value', 'payout_currency', 'payout_type', 'state', 'created_at',
    'updated_at', 'payout_auto', 'payout_upsell', 'country', 'notes',
    'action_options', 'action_type', 'offer_type', 'url',
    'conversion_cap_enabled', 'daily_cap', 'conversion_timezone',
    'alternative_offer_id',
];

describe('offers.create / offers.show', function () {
    test('a freshly created offer round-trips the full documented field set via show', function () {
        $created = Fixtures::createOffer($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('offers.show', ['id' => $id]);
        expect($response->getStatusCode())->toBe(200);

        $offer = ApiClient::json($response);
        expect($offer)->toBeArray();

        // Identity of the fixture this test created.
        expect((int) $offer['id'])->toBe($id);
        expect($offer['name'])->toBe($created['name']);
        expect($offer['state'])->toBe('active');

        foreach (OFFER_RAW_FIELDS as $field) {
            expect($offer)->toHaveKey($field);
        }

        // §10.3: `affiliate_network_id = null` is normalized to `0` by
        // OfferSerializer - verified live (a fresh offer has no network set).
        expect((int) $offer['affiliate_network_id'])->toBe(0);

        // §10.3: `group`/`affiliate_network` are only added when
        // withGroupName=true; not present on a plain offers.show - verified live.
        expect($offer)->not->toHaveKey('group');
        expect($offer)->not->toHaveKey('affiliate_network');

        // §10.3: `preview` is only added when action_type == "local_file";
        // this fixture is a plain (non-local) offer.
        expect($offer)->not->toHaveKey('preview');

        // §10.3: `conversion_cap` is only added when conversionCapEnabled is
        // set on the offer; not the case for a freshly created default offer.
        expect($offer)->not->toHaveKey('conversion_cap');
    });

    test('rejects a request with no id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('offers.show');

        // §6: NotFoundError -> HTTP 404, body {"error": ..., "stacktrace": ...}.
        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
        expect($body['error'])->toBeString()->not->toBeEmpty();
        expect($body['stacktrace'])->toBeString()->not->toBeEmpty();
    });

    test('rejects a request with a non-existent id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('offers.show', ['id' => 999999999]);

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
    });
});

describe('offers.index', function () {
    test('a freshly created offer appears with id/name/state on each element', function () {
        $created = Fixtures::createOffer($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('offers.index');

        expect($response->getStatusCode())->toBe(200);

        $offers = ApiClient::json($response);
        expect($offers)->toBeArray()->not->toBeEmpty();

        foreach ($offers as $offer) {
            expect($offer)->toBeArray();
            expect($offer)->toHaveKey('id');
            expect($offer)->toHaveKey('name');
            expect($offer)->toHaveKey('state');
        }

        $matching = array_values(array_filter(
            $offers,
            static fn ($o) => (int) $o['id'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['name'])->toBe($created['name']);
        expect($matching[0]['state'])->toBe('active');
    });
});

describe('offers.listAsOptions', function () {
    test('a freshly created offer appears with the documented {value,name} option shape', function () {
        $created = Fixtures::createOffer($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('offers.listAsOptions');

        expect($response->getStatusCode())->toBe(200);

        $options = ApiClient::json($response);
        expect($options)->toBeArray()->not->toBeEmpty();

        foreach ($options as $option) {
            expect($option)->toBeArray();
            expect($option)->toHaveKeys(['value', 'name']);
        }

        $matching = array_values(array_filter(
            $options,
            static fn ($o) => (int) $o['value'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['name'])->toBe($created['name']);
    });
});
