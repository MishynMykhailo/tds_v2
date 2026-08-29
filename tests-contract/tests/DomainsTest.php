<?php

/*
|--------------------------------------------------------------------------
| Domains contract tests
|--------------------------------------------------------------------------
|
| Locks down the `domains` module contract documented in
| docs/legacy-reference/frontend/api/10.7_domains.md, run against the
| backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| Every regression test below creates its OWN domain fixture via
| `domains.create` (see tests/Support/Fixtures.php) before reading/
| asserting anything - it never depends on a specific pre-existing row.
| Domain names are crafted to look like real hostnames
| (`test-{token}.example.com`) - see the caveat on Fixtures::createDomain()
| about the legacy comma-splitting/feature-flag behavior around `name`.
|
| Several assertions below document genuine legacy quirks discovered while
| writing these tests (domains.show does NOT 404 on a missing id, and a
| freshly created domain does NOT appear in domains.listAsOptions). These
| are deliberately captured as-is, not "fixed" to match the naive
| expectation - see the comments on each test for why.
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

// §10.7: DomainSerializer has $_fields = true (all raw model fields pass
// through) plus extra() computed fields. Raw fields verified live against
// the `tds_domains` table schema.
const DOMAIN_RAW_FIELDS = [
    'id', 'name', 'is_ssl', 'network_status', 'default_campaign_id', 'state',
    'created_at', 'updated_at', 'wildcard', 'catch_not_found', 'notes',
    'error_description', 'ssl_status', 'redirect', 'ssl_data',
    'is_robots_allowed', 'next_check_at', 'ssl_redirect', 'allow_indexing',
    'check_retries',
];

// §10.7: DomainSerializer::extra() - always added, on top of the raw fields.
const DOMAIN_EXTRA_FIELDS = ['campaigns_count', 'default_campaign', 'error_solution'];

describe('domains.create / domains.show', function () {
    test('a freshly created domain round-trips the full documented field set via show', function () {
        $created = Fixtures::createDomain($this->api);

        // §10.7, verified live: domains.create responds with a JSON ARRAY,
        // not a single object - see the caveat on Fixtures::createDomain().
        expect($created)->toBeArray()->toHaveCount(1);
        $domain = $created[0];
        $id = (int) $domain['id'];

        // §10.7: create always forces network_status=validating server-side,
        // regardless of any value the caller sends.
        expect($domain['network_status'])->toBe('validating');

        $response = $this->api->get('domains.show', ['id' => $id]);
        expect($response->getStatusCode())->toBe(200);

        $shown = ApiClient::json($response);
        expect($shown)->toBeArray();

        // Identity of the fixture this test created.
        expect((int) $shown['id'])->toBe($id);
        expect($shown['name'])->toBe($domain['name']);
        expect($shown['state'])->toBe('active');

        foreach (DOMAIN_RAW_FIELDS as $field) {
            expect($shown)->toHaveKey($field);
        }
        foreach (DOMAIN_EXTRA_FIELDS as $field) {
            expect($shown)->toHaveKey($field);
        }
    });

    // §10.7, verified live: this is NOT the standard §6 NotFoundError contract
    // that campaigns.show/trafficSources.show follow (404 with
    // {error, stacktrace} on a missing id). DomainsController::showAction()
    // calls DomainsRepository::findActive($id), which is backed by
    // EntityRepository::findFirst() - that method returns NULL for "not
    // found" instead of throwing NotFoundError (unlike ::find(), which the
    // other show actions use and which DOES throw). The controller passes
    // that null straight into isViewAllowed()/serialize() with no null
    // check, so the request "succeeds" with HTTP 200 and a body missing
    // `id`/`name` but still carrying the serializer's extra() fields -
    // `campaigns_count` even comes back non-zero here, because extra()
    // looks up $data['id'] (null/absent) in a dictionary keyed by
    // domain_id, and that dictionary's null-key bucket holds the count of
    // campaigns with no domain assigned at all. This is a genuine legacy
    // bug/quirk, not a test mistake - do not "fix" this assertion to expect
    // 404 without re-verifying against the live backend first.
    test('a request with no id does NOT 404 - it 200s with a body missing id/name (legacy quirk)', function () {
        $response = $this->api->get('domains.show');

        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->not->toHaveKey('id');
        expect($body)->not->toHaveKey('name');
        foreach (DOMAIN_EXTRA_FIELDS as $field) {
            expect($body)->toHaveKey($field);
        }
    });

    test('a request with a non-existent id does NOT 404 either - same legacy quirk', function () {
        $response = $this->api->get('domains.show', ['id' => 999999999]);

        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->not->toHaveKey('id');
        expect($body)->not->toHaveKey('name');
    });
});

describe('domains.index', function () {
    test('a freshly created domain appears with id/name/state on each element', function () {
        $created = Fixtures::createDomain($this->api);
        $domain = $created[0];
        $id = (int) $domain['id'];

        $response = $this->api->get('domains.index');

        expect($response->getStatusCode())->toBe(200);

        $domains = ApiClient::json($response);
        expect($domains)->toBeArray()->not->toBeEmpty();

        foreach ($domains as $d) {
            expect($d)->toBeArray();
            expect($d)->toHaveKey('id');
            expect($d)->toHaveKey('name');
            expect($d)->toHaveKey('state');
        }

        $matching = array_values(array_filter(
            $domains,
            static fn ($d) => (int) $d['id'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['name'])->toBe($domain['name']);
        expect($matching[0]['state'])->toBe('active');
    });
});

describe('domains.listAsOptions', function () {
    // §10.7, verified live: unlike campaigns.listAsOptions/
    // trafficSources.listAsOptions, a freshly created domain does NOT show
    // up here. DomainsRepository::allActiveAndChecked() filters on
    // `network_status = active AND state = active`, but domains.create
    // always forces network_status=validating - a domain only flips to
    // `active` after DomainCheckerService's async check runs (a real
    // outbound HTTP request to the domain's own `?_ping=domain` endpoint),
    // which will never succeed for a fake `*.example.com` test domain. So
    // this suite can only assert the general option shape and the
    // always-present "default domain" entry, not the identity of a
    // just-created fixture.
    test('lists the documented {value,name} option shape including a default entry', function () {
        $response = $this->api->get('domains.listAsOptions');

        expect($response->getStatusCode())->toBe(200);

        $options = ApiClient::json($response);
        expect($options)->toBeArray()->not->toBeEmpty();

        foreach ($options as $option) {
            expect($option)->toBeArray();
            expect($option)->toHaveKeys(['value', 'name']);
        }

        // add_default defaults to true and prepends a {value: null, ...} entry.
        expect($options[0]['value'])->toBeNull();
    });

    test('add_default=0 omits the default entry', function () {
        $response = $this->api->get('domains.listAsOptions', ['add_default' => 0]);

        expect($response->getStatusCode())->toBe(200);

        $options = ApiClient::json($response);
        expect($options)->toBeArray();

        foreach ($options as $option) {
            expect($option['value'])->not->toBeNull();
        }
    });
});
