<?php

/**
 * Port of legacy `Traffic\Context\ClickApiContext` + `Traffic\Dispatcher\
 * ClickApiDispatcher` (application/Traffic/Context/ClickApiContext.php,
 * application/Traffic/Dispatcher/ClickApiDispatcher.php) — the AJAX
 * click API: a landing page's own JS calls this instead of navigating
 * the browser, gets back JSON describing what a normal click WOULD have
 * done (resolved stream/offer/redirect target, headers, uniqueness
 * info), and decides what to do itself (`window.location = ...`, etc.).
 * Runs the exact SAME click pipeline as `public/index.php` — confirmed
 * by reading `ClickApiDispatcher::dispatch()`, it calls
 * `Pipeline::firstLevelStages()`, the identical stage list a normal
 * click uses, just serializes the result differently at the end instead
 * of emitting it as a real HTTP response.
 *
 * VERIFIED LEGACY BUG, not a design choice being deviated from: reading
 * `ClickApiDispatcher::dispatch()`'s `switch ($this->_version)` literally
 * (application/Traffic/Dispatcher/ClickApiDispatcher.php:39-58), cases 1
 * and 2 compute `$json` and `break` — there is NO `return` statement
 * after the switch, so `dispatch()` returns `null` (PHP: falls off the
 * end of the function) for versions 1 and 2. Since `ClickApiContext::
 * DEFAULT_VERSION = 1`, this means the endpoint's DEFAULT behavior in
 * legacy, and its documented v2 behavior, both return an empty response
 * — only `?v=3` (case 3, which explicitly `return`s) ever worked. Any
 * real caller of this endpoint in a legacy install MUST already be
 * passing `?v=3`. Given traffic-core has no two-step JWT/cookie
 * offer-redirect flow (v3's real differentiator — `force_redirect_offer
 * = false` unless overridden, engaging attribution machinery this
 * project never built, see many earlier phases' docblocks), and v1/v2
 * are simply broken (not "a simpler mode intentionally left thin"),
 * this port RETURNS the v2-shaped JSON unconditionally (fixing the
 * dead-code bug) rather than reproducing an empty response nobody could
 * have depended on. `?v=` is still read and echoed nowhere else — this
 * project has one working response shape, not three.
 *
 * Auth: `?token=<campaigns.token>` (resolves that specific campaign
 * directly, mirrors `CachedCampaignRepository::findByToken()`) OR
 * `?api_key=<value>` matching the `api_key` row in `settings` (mirrors
 * legacy's global-API-key mode — campaign resolution then falls through
 * to the normal domain/alias logic inside `FindCampaignStage`, same as
 * legacy leaving `$campaign = NULL` in that branch). Neither legacy's
 * `hasClickApiFeature()` PRO-license gate is ported — traffic-core has
 * no licensing system at all (existing project-wide stance, not new
 * here).
 *
 * IP/UA/referrer overrides: see `ClickApiSignalStage`'s own docblock for
 * exactly what's ported (`ip`/`ua`/`referrer`) vs. not
 * (`language`/`search_engine`/`landing_id`/`datetime`/
 * `always_empty_cookies` — documented gaps, not silent ones).
 */

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use TrafficCore\Db;
use TrafficCore\Pipeline\Payload;
use TrafficCore\Pipeline\PipelineRunner;
use TrafficCore\Pipeline\DomainRedirectStage;
use TrafficCore\Pipeline\CheckPrefetchStage;
use TrafficCore\Pipeline\ClickApiSignalStage;
use TrafficCore\Pipeline\ResolveVisitorStage;
use TrafficCore\Pipeline\FindCampaignStage;
use TrafficCore\Pipeline\CheckDefaultCampaignStage;
use TrafficCore\Pipeline\CheckParamAliasesStage;
use TrafficCore\Pipeline\ChooseStreamStage;
use TrafficCore\Pipeline\ChooseLandingStage;
use TrafficCore\Pipeline\ChooseOfferStage;
use TrafficCore\Pipeline\BuildRawClickStage;
use TrafficCore\Pipeline\GenerateTokenStage;
use TrafficCore\Pipeline\UpdateUniquenessStage;
use TrafficCore\Pipeline\UpdateHitLimitStage;
use TrafficCore\Pipeline\UpdateCostsStage;
use TrafficCore\Pipeline\UpdatePayoutStage;
use TrafficCore\Pipeline\ExecuteActionStage;
use TrafficCore\Pipeline\CheckSendingToAnotherCampaign;
use TrafficCore\Pipeline\StoreRawClickStage;
use TrafficCore\Pipeline\ClickApiResponseBuilder;

function clickApiErrorResponse(int $status, string $error): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $error]);
    exit;
}

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
$request = $creator->fromGlobals();
$params = array_merge((array) $request->getParsedBody(), $request->getQueryParams());

$pdo = Db::instance();

$forcedCampaignId = null;

$apiKeyParam = $params['api_key'] ?? null;
if ($apiKeyParam !== null && $apiKeyParam !== '') {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'api_key' LIMIT 1");
    $stmt->execute();
    $configuredKey = $stmt->fetchColumn();

    if ($configuredKey === false || $configuredKey === '' || !hash_equals((string) $configuredKey, (string) $apiKeyParam)) {
        clickApiErrorResponse(403, 'Invalid token or api key');
    }
    // Campaign resolves via the normal domain/alias flow inside FindCampaignStage.
} else {
    $token = $params['token'] ?? null;
    if ($token === null || $token === '') {
        clickApiErrorResponse(404, 'No campaign token');
    }

    $stmt = $pdo->prepare("SELECT id FROM campaigns WHERE token = ? AND state = 'active' LIMIT 1");
    $stmt->execute([$token]);
    $campaignId = $stmt->fetchColumn();

    if ($campaignId === false) {
        clickApiErrorResponse(403, "Invalid campaign token '{$token}'");
    }

    $forcedCampaignId = (int) $campaignId;
}

$payload = new Payload($request);
$payload->forcedCampaignId = $forcedCampaignId;

$runner = new PipelineRunner([
    new DomainRedirectStage(),
    new CheckPrefetchStage(),
    new ClickApiSignalStage(),
    new ResolveVisitorStage(),
    new FindCampaignStage(),
    new CheckDefaultCampaignStage(),
    new CheckParamAliasesStage(),
    new ChooseStreamStage(),
    new ChooseLandingStage(),
    new ChooseOfferStage(),
    new BuildRawClickStage(),
    new GenerateTokenStage(),
    new UpdateUniquenessStage(),
    new UpdateHitLimitStage(),
    new UpdateCostsStage(),
    new UpdatePayoutStage(),
    new ExecuteActionStage(),
    new CheckSendingToAnotherCampaign(),
    new StoreRawClickStage(),
]);

$payload = $runner->run($payload);

$json = ClickApiResponseBuilder::build($payload, !empty($params['info']));

header('Content-Type: application/json');
echo json_encode($json);
