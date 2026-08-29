<?php

namespace Tests\Support;

/**
 * Self-contained fixture builders for the contract test suite.
 *
 * The suite runs against a live, shared, mutable database (the legacy
 * backend named by TDS_TEST_TARGET). Tests must never assume a specific
 * pre-existing row (id, alias, ...) survives between runs — another actor
 * (a human clicking around, another agent, a previous test run) can and
 * does mutate/delete that data. Every test that needs a campaign/stream
 * creates its own via these helpers instead.
 *
 * These helpers assert only that the *creation itself* succeeded (HTTP 200)
 * so a broken fixture fails fast with a clear message, attributed to the
 * calling test. They deliberately do NOT assert anything about the
 * documented response shape - that's the test's job.
 */
final class Fixtures
{
    /**
     * Creates a campaign via `campaigns.create` (§10.1) with a random,
     * collision-free alias, merged with any caller-supplied overrides.
     *
     * @return array The decoded JSON body of the create response.
     */
    public static function createCampaign(ApiClient $api, array $overrides = []): array
    {
        $alias = self::randomToken('ct');

        $payload = array_merge([
            'name' => 'Contract test campaign ' . $alias,
            'alias' => $alias,
        ], $overrides);

        $response = $api->post('campaigns.create', [], $payload);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Fixtures::createCampaign failed: HTTP %d, body: %s',
                $response->getStatusCode(),
                (string) $response->getBody()
            ));
        }

        return ApiClient::json($response);
    }

    /**
     * Creates a stream via `streams.create` (§10.2) inside the given
     * campaign. Defaults to the simplest valid payload accepted by
     * `StreamValidator` (`campaign_id`, `action_type`, `schema` are the
     * only required fields) - schema=redirect, action_type=do_nothing -
     * merged with any caller-supplied overrides.
     *
     * @return array The decoded JSON body of the create response.
     */
    public static function createStream(ApiClient $api, int $campaignId, array $overrides = []): array
    {
        $payload = array_merge([
            'campaign_id' => $campaignId,
            'action_type' => 'do_nothing',
            'schema' => 'redirect',
            'name' => 'Contract test stream ' . self::randomToken('st'),
        ], $overrides);

        $response = $api->post('streams.create', [], $payload);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Fixtures::createStream failed: HTTP %d, body: %s',
                $response->getStatusCode(),
                (string) $response->getBody()
            ));
        }

        return ApiClient::json($response);
    }

    /**
     * Creates an offer via `offers.create` (§10.3) with a random, unique
     * name, merged with any caller-supplied overrides. `OfferValidator` only
     * requires `name` - everything else (created_at/updated_at/state) is
     * defaulted by `EntityService::build()`, and the rest of the raw offer
     * columns are simply absent from the model until it's re-fetched via
     * `offers.show` (verified live: `offers.create`'s response body only
     * contains {name, created_at, updated_at, state, id}, NOT the full
     * column set - unlike campaigns.create/streams.create. Tests that need
     * the full field set must re-fetch via `offers.show`).
     *
     * @return array The decoded JSON body of the create response.
     */
    public static function createOffer(ApiClient $api, array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Contract test offer ' . self::randomToken('of'),
        ], $overrides);

        $response = $api->post('offers.create', [], $payload);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Fixtures::createOffer failed: HTTP %d, body: %s',
                $response->getStatusCode(),
                (string) $response->getBody()
            ));
        }

        return ApiClient::json($response);
    }

    /**
     * Creates a landing via `landings.create` (§10.4) with a random, unique
     * name, merged with any caller-supplied overrides. `LandingValidator`
     * only *effectively* requires `name` from the caller - `created_at`/
     * `updated_at` are also listed as "required" by the validator, but
     * `EntityService::build()` defaults them before validation runs when
     * they're empty, so a bare `{name}` payload is sufficient (verified
     * live). Same partial-response caveat as `createOffer()` applies: the
     * create response body is NOT the full documented field set - re-fetch
     * via `landings.show` for that.
     *
     * @return array The decoded JSON body of the create response.
     */
    public static function createLanding(ApiClient $api, array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Contract test landing ' . self::randomToken('ld'),
        ], $overrides);

        $response = $api->post('landings.create', [], $payload);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Fixtures::createLanding failed: HTTP %d, body: %s',
                $response->getStatusCode(),
                (string) $response->getBody()
            ));
        }

        return ApiClient::json($response);
    }

    /**
     * Creates a traffic source via `trafficSources.create` (§10.6) with a
     * random, unique name, merged with any caller-supplied overrides.
     * `TrafficSourceValidator` only requires `name` (unique, max 50 chars -
     * verified against `Component\TrafficSources\Validator\TrafficSourceValidator`).
     * Same partial-response caveat as `createOffer()`/`createLanding()`
     * applies here (verified live against the legacy backend): the create
     * response body is only {name, created_at, updated_at, state, id}, NOT
     * the full raw column set (`postback_url`, `postback_statuses`,
     * `template_name`, `accept_parameters`, `parameters`, `notes`,
     * `traffic_loss`, ...) - unlike campaigns.create/streams.create. Tests
     * that need the full field set must re-fetch via `trafficSources.show`.
     *
     * @return array The decoded JSON body of the create response.
     */
    public static function createTrafficSource(ApiClient $api, array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Contract test TS ' . self::randomToken('ts'),
        ], $overrides);

        $response = $api->post('trafficSources.create', [], $payload);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Fixtures::createTrafficSource failed: HTTP %d, body: %s',
                $response->getStatusCode(),
                (string) $response->getBody()
            ));
        }

        return ApiClient::json($response);
    }

    /**
     * Creates a domain via `domains.create` (§10.7) with a random,
     * realistic-looking hostname (`test-{token}.example.com`), merged with
     * any caller-supplied overrides. `DomainValidator` requires `name`
     * (unique, max 255 chars) plus `created_at`/`updated_at`, both defaulted
     * by `EntityService::build()` (same as `createLanding()`) so a bare
     * `{name}` payload is sufficient.
     *
     * A realistic dotted hostname is used deliberately: per
     * `DomainsController::_checkDomainsFeatureAndLicense()`, when the
     * multi-domain feature is OFF, `name` is split on `,` and only the
     * first segment is kept - a plain token without dots/commas would still
     * work, but a domain-shaped value is what a real client would send and
     * avoids exercising that edge case unintentionally. Verified live: this
     * legacy backend's `FeatureService::hasDomainsFeature()` is hardcoded to
     * always return `true`, so that comma-splitting branch is actually dead
     * code here - documented for whoever ports this to Laravel.
     *
     * IMPORTANT, verified live: unlike every other `*.create` endpoint in
     * this suite, `domains.create` responds with a JSON ARRAY, not a single
     * object - `DomainsController::createAction()` calls
     * `DomainService::createMultiple()` directly (built to create several
     * domains at once from a comma-separated `name`), and serializes the
     * resulting list as-is even when exactly one domain was created. Callers
     * must read `$created[0]`, not `$created['id']`. The create response is
     * also partial, same caveat as `createTrafficSource()` - fields like
     * `default_campaign_id`, `wildcard`, `notes`, `redirect`, `ssl_data`,
     * `is_robots_allowed`, `next_check_at`, `check_retries` are absent until
     * a re-fetch via `domains.show`.
     *
     * @return array The decoded JSON body of the create response (a list).
     */
    public static function createDomain(ApiClient $api, array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'test-' . self::randomToken('dm') . '.example.com',
        ], $overrides);

        $response = $api->post('domains.create', [], $payload);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Fixtures::createDomain failed: HTTP %d, body: %s',
                $response->getStatusCode(),
                (string) $response->getBody()
            ));
        }

        return ApiClient::json($response);
    }

    /**
     * Creates a user via `users.create` (§10.8, `UsersController`) with a
     * random, unique login, merged with any caller-supplied overrides.
     *
     * IMPORTANT, verified live: the create payload field is named
     * `password_hash`, NOT `password` - despite the name, the value sent is
     * a plain-text password (the legacy `UsersController`/`UserService`
     * hashes it server-side before persisting; there is no client-side
     * hashing). `UserValidator` requires `login`, `type` (enum, only
     * `ADMIN`/`USER` accepted - anything else is rejected with HTTP 406
     * `{"type":["Contains invalid value"]}`) and `password_hash`.
     *
     * A real, explicit password is always sent here rather than relying on
     * any framework default, since the caller may need a known credential -
     * though most callers in this suite only exercise CRUD as the
     * already-logged-in admin and never actually log in as the created user.
     *
     * `users.create`'s response body does NOT echo back the password or any
     * hash (verified live) - only `{id, login, type, rules, permissions,
     * access_data, keyCount, preferences}`.
     *
     * @return array The decoded JSON body of the create response.
     */
    public static function createUser(ApiClient $api, array $overrides = []): array
    {
        $token = self::randomToken('u');

        $payload = array_merge([
            'login' => 'ct_user_' . $token,
            'password_hash' => 'CtPass!' . $token,
            'type' => 'USER',
        ], $overrides);

        $response = $api->post('users.create', [], $payload);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Fixtures::createUser failed: HTTP %d, body: %s',
                $response->getStatusCode(),
                (string) $response->getBody()
            ));
        }

        return ApiClient::json($response);
    }

    /**
     * Creates a group via `groups.create` (§10.8, `GroupsController`) with a
     * random, unique name, merged with any caller-supplied overrides.
     *
     * `type` is required (verified live: an empty/missing `type` on
     * `groups.create` returns HTTP 500 "An error occurred..." rather than a
     * clean 406 validation error - `GroupsController` doesn't validate `type`
     * before calling `GroupService::getAclEntityType($type)`, so an
     * unrecognized/absent type blows up instead of failing gracefully).
     * Defaults to `campaigns`, a real ACL entity type verified live to work.
     *
     * `name` must be unique per `type` (verified live: a duplicate name for
     * the same type returns HTTP 406 `{"name":["This value has already
     * used"]}`) - the random token keeps fixtures collision-free.
     *
     * Note there is no `groups.show` action (verified live: it 404s with
     * "Controller action \"showAction\" is not defined") - a created group
     * can only be looked up again via `groups.index`/`groups.listAsOptions`.
     *
     * @return array The decoded JSON body of the create response.
     */
    public static function createGroup(ApiClient $api, array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Contract test group ' . self::randomToken('gr'),
            'type' => 'campaigns',
        ], $overrides);

        $response = $api->post('groups.create', [], $payload);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Fixtures::createGroup failed: HTTP %d, body: %s',
                $response->getStatusCode(),
                (string) $response->getBody()
            ));
        }

        return ApiClient::json($response);
    }

    /**
     * Creates an affiliate network via `affiliateNetworks.create` with a
     * random, unique name, merged with any caller-supplied overrides.
     *
     * `object=affiliateNetworks` verified live against the legacy backend
     * (this module has no doc under docs/legacy-reference/frontend/api/ - the
     * object name was confirmed by reading
     * `Component\AffiliateNetworks\Initializer::loadControllers()`, which
     * registers `Controller\AffiliateNetworksController` under the key
     * `"affiliateNetworks"`, plus the AdminApi routes in the same file and
     * the ACL resource bindings in `AclResourceRepository` - all three use
     * the exact string `affiliateNetworks`).
     *
     * `AffiliateNetworkValidator` only requires `name` (unique among
     * non-deleted rows, max 100 chars). Same partial-response caveat as
     * `createOffer()`/`createLanding()`/`createTrafficSource()` applies here:
     * `AffiliateNetworkService` (an `EntityService`) only sets
     * `name`/`created_at`/`updated_at`/`state` before insert, so
     * `affiliateNetworks.create`'s response body is only
     * `{name, created_at, updated_at, state, id}`, NOT the full raw column
     * set (`postback_url`, `offer_param`, `template_name`, `notes`,
     * `pull_api_options`). Tests that need the full field set must re-fetch
     * via `affiliateNetworks.show`.
     *
     * @return array The decoded JSON body of the create response.
     */
    public static function createAffiliateNetwork(ApiClient $api, array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Contract test AN ' . self::randomToken('an'),
        ], $overrides);

        $response = $api->post('affiliateNetworks.create', [], $payload);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Fixtures::createAffiliateNetwork failed: HTTP %d, body: %s',
                $response->getStatusCode(),
                (string) $response->getBody()
            ));
        }

        return ApiClient::json($response);
    }

    private static function randomToken(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(4)) . dechex(time());
    }
}
