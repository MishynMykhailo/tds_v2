<?php

/*
|--------------------------------------------------------------------------
| AffiliateNetworks contract tests
|--------------------------------------------------------------------------
|
| Locks down the `affiliateNetworks` module contract against the backend
| named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| Unlike every other module in this suite, there is NO doc file for this
| module under docs/legacy-reference/frontend/api/ - the object name
| `affiliateNetworks` was confirmed by reading the legacy source directly:
| `Component\AffiliateNetworks\Initializer::loadControllers()` registers
| `Controller\AffiliateNetworksController` under the exact key
| `"affiliateNetworks"` (also used, consistently, by that same Initializer's
| AdminApi route table and by `AclResourceRepository`'s ACL resource
| bindings). Confirmed live: `?object=affiliateNetworks.index` returns 200,
| not 404.
|
| Every regression test below creates its OWN affiliate network fixture via
| `affiliateNetworks.create` (see tests/Support/Fixtures.php) before reading/
| asserting anything - it never depends on a specific pre-existing row. The
| target database is live and mutable (shared with humans clicking around
| and other agents), so pinning assertions to a fixed id is fragile (see the
| equivalent note in CampaignsTest.php/TrafficSourcesTest.php).
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

// AffiliateNetworkSerializer has $_fields = true (all raw model fields pass
// through, no whitelist). Verified against the `tds_affiliate_networks`
// table schema (application/data/schema.sql): id, name, postback_url,
// offer_param, state, template_name, created_at, updated_at, notes,
// pull_api_options.
const AFFILIATE_NETWORK_RAW_FIELDS = [
    'id', 'name', 'postback_url', 'offer_param', 'state', 'template_name',
    'created_at', 'updated_at', 'notes', 'pull_api_options',
];

describe('affiliateNetworks.create / affiliateNetworks.show', function () {
    test('a freshly created affiliate network round-trips the full documented field set via show', function () {
        $created = Fixtures::createAffiliateNetwork($this->api);
        $id = (int) $created['id'];

        // Verified live: affiliateNetworks.create's response body only
        // contains {name, created_at, updated_at, state, id} - the same
        // partial-response behavior as offers.create/landings.create/
        // trafficSources.create, unlike campaigns.create's full field set.
        // See Fixtures::createAffiliateNetwork().
        expect($created)->toHaveKeys(['id', 'name', 'created_at', 'updated_at', 'state']);

        $response = $this->api->get('affiliateNetworks.show', ['id' => $id]);
        expect($response->getStatusCode())->toBe(200);

        $network = ApiClient::json($response);
        expect($network)->toBeArray();

        // Identity of the fixture this test created.
        expect((int) $network['id'])->toBe($id);
        expect($network['name'])->toBe($created['name']);
        expect($network['state'])->toBe('active');

        foreach (AFFILIATE_NETWORK_RAW_FIELDS as $field) {
            expect($network)->toHaveKey($field);
        }

        // showAction uses `new AffiliateNetworkSerializer()` (extended
        // defaults to false), unlike indexAction which passes `true` - so
        // the "offers" count field (added by ::extra() only when extended)
        // must NOT appear here. Verified live.
        expect($network)->not->toHaveKey('offers');
    });

    test('rejects a request with no id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('affiliateNetworks.show');

        // §6: NotFoundError -> HTTP 404, body {"error": ..., "stacktrace": ...}.
        // Verified live: affiliateNetworks.show follows the same §6 contract
        // as campaigns.show/trafficSources.show (its repository uses the
        // same generic `EntityRepository::find()` -> `DataRepository::find()`
        // path as trafficSources).
        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
        expect($body['error'])->toBeString()->not->toBeEmpty();
        expect($body['stacktrace'])->toBeString()->not->toBeEmpty();
    });

    test('rejects a request with a non-existent id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('affiliateNetworks.show', ['id' => 999999999]);

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
    });
});

describe('affiliateNetworks.index', function () {
    test('a freshly created affiliate network appears with the full raw field set plus an offers count', function () {
        $created = Fixtures::createAffiliateNetwork($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('affiliateNetworks.index');

        expect($response->getStatusCode())->toBe(200);

        $networks = ApiClient::json($response);
        expect($networks)->toBeArray()->not->toBeEmpty();

        foreach ($networks as $network) {
            expect($network)->toBeArray();
            foreach (AFFILIATE_NETWORK_RAW_FIELDS as $field) {
                expect($network)->toHaveKey($field);
            }
            // indexAction uses `new AffiliateNetworkSerializer(true)` -
            // extended=true triggers ::extra() to add an "offers" active
            // offer count. Verified live.
            expect($network)->toHaveKey('offers');
        }

        $matching = array_values(array_filter(
            $networks,
            static fn ($n) => (int) $n['id'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['name'])->toBe($created['name']);
        expect($matching[0]['state'])->toBe('active');
        // Verified live: OfferRepository::countActive() returns the raw
        // mysqli COUNT(*) result, which comes back as a numeric string, not
        // a PHP int - so this is "0", not 0, once JSON-decoded.
        expect((int) $matching[0]['offers'])->toBe(0);
    });
});

describe('affiliateNetworks.listAsOptions', function () {
    test('a freshly created affiliate network appears with the documented {value,name,template} option shape', function () {
        // Verified live/via source (Core\Entity\ListOptions\Builder::build()):
        // AffiliateNetworksRepository::listAsOptions() calls the generic
        // Builder with `["template" => "template_name"]` as extra fields,
        // and `affiliate_network` is not a group-scoped entity type (unlike
        // campaigns/offers/landings), so there is no group_id/group here -
        // only {value, name, template}, unlike trafficSources.listAsOptions
        // which is just {value, name} (no extra field).
        $created = Fixtures::createAffiliateNetwork($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('affiliateNetworks.listAsOptions');

        expect($response->getStatusCode())->toBe(200);

        $options = ApiClient::json($response);
        expect($options)->toBeArray()->not->toBeEmpty();

        foreach ($options as $option) {
            expect($option)->toBeArray();
            expect($option)->toHaveKeys(['value', 'name', 'template']);
        }

        $matching = array_values(array_filter(
            $options,
            static fn ($o) => (int) $o['value'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['name'])->toBe($created['name']);
    });
});
