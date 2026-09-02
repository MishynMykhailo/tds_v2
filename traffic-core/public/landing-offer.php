<?php

/**
 * Port of legacy `Traffic\Context\LandingOfferContext` + `Traffic\
 * Dispatcher\LandingOfferDispatcher` (application/Traffic/Context/
 * LandingOfferContext.php, application/Traffic/Dispatcher/
 * LandingOfferDispatcher.php) — a landing page that was ALREADY shown to
 * a visitor calls this to request/confirm the offer it should send them
 * to (the "offer requested separately after the landing already
 * rendered" flow noted since Phase 3's `ChooseOfferStage` docblock).
 * Restores the ORIGINAL click by `_token` (a `TrafficCore\LpToken\
 * LpTokenService` lookup token, format `uuid_<subId>_...`, set for every
 * click by `GenerateTokenStage`), re-resolves the SAME stream/offer that
 * click already had (never rolls new ones — `Payload::$forcedStreamId`/
 * `$forcedOfferId`, Phase 17 additions to `ChooseStreamStage`/
 * `ChooseOfferStage`), executes that offer's action, and reports the
 * result back onto the ORIGINAL click row (`landing_clicked`, `offer_id`,
 * `affiliate_network_id`) via `TrafficCore\Queue\ClickUpdateQueue` —
 * mirrors legacy's own explicit `UpdateClickCommand::saveLpClick()` call.
 *
 * Stage list ported from legacy `Pipeline::secondLevelStages()`
 * (application/Traffic/Pipeline/Pipeline.php:20), MINUS:
 *  - `UpdateParamsFromLandingStage` — landing-configured param overrides
 *    for THIS second pass; no such per-landing override concept exists
 *    on traffic-core's `landings` schema, not built anywhere else either.
 *  - `FindAffiliateNetworkStage` — `affiliate_network_id` is resolved
 *    from the chosen offer directly by the `ClickUpdateQueue` worker
 *    instead (same place `saveLpClick`'s own affiliate-network lookup
 *    happens, see `bin/process_click_queue.php`).
 *  - `SetCookieStage` — no cookie-jar/session-cookie system in
 *    traffic-core at all (an established, project-wide gap, not new
 *    here).
 *  - `StoreRawClicksStage` — would `RPUSH` a SECOND row for the SAME
 *    `sub_id` onto `ClickQueue` (an INSERT-only queue, see its own
 *    docblock), which `clicks.sub_id`'s UNIQUE constraint would reject
 *    at insert time (and, worse, fail the ENTIRE batch INSERT it landed
 *    in — `bin/process_click_queue.php`'s grouping has no per-row error
 *    isolation). The correct traffic-core equivalent of "record what
 *    happened" for an ALREADY-EXISTING click is an UPDATE, which is
 *    exactly what the explicit `ClickUpdateQueue` push below does —
 *    this is not a simplification, it's the only correct choice given
 *    traffic-core's queue is insert-only by design.
 *  - `GenerateTokenStage`/`UpdateHitLimitStage`/`UpdateUniquenessStage` —
 *    absent from legacy's `secondLevelStages()` too (confirmed by
 *    reading it directly): a new attribution token, hit count, and
 *    uniqueness determination all belong to the FIRST click only.
 *
 * NOT ported: cookie-based token fallback (`CookiesService::getRaw()` —
 * `_token` query param only, no cookie system here), the
 * empty-token-means-fresh-click branch (legacy falls back to
 * `ClickDispatcher` — a real, deliberate simplification: an offer
 * confirmation request with NO token is almost certainly a broken/
 * malicious call, not a legitimate fresh visit, so this port 400s
 * instead of silently running a whole new click for it).
 *
 * Documented macro gap: `payload->clickFields` (source for `{sub_id_1}`
 * .. `{extra_param_10}`/`{source}`/`{referrer}`/etc. macros — see
 * `ClickMacroValues`) is only ever populated by `BuildRawClickStage`,
 * which does NOT run in this second pass (see above). Only `{sub_id}`/
 * `{subid}` are restored here (the literal `sub_id` string needs no
 * dictionary reverse-lookup); every other click-detail macro resolves
 * empty in the offer's `action_payload` for this one entry point. Real,
 * narrow gap — flagged in docs/PORTING_LOG.md Phase 17, not silent.
 */

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use TrafficCore\Db;
use TrafficCore\LpToken\LpTokenService;
use TrafficCore\Pipeline\Payload;
use TrafficCore\Pipeline\PipelineRunner;
use TrafficCore\Pipeline\CaptureSignalStage;
use TrafficCore\Pipeline\ResolveVisitorStage;
use TrafficCore\Pipeline\FindCampaignStage;
use TrafficCore\Pipeline\CheckDefaultCampaignStage;
use TrafficCore\Pipeline\CheckParamAliasesStage;
use TrafficCore\Pipeline\ChooseStreamStage;
use TrafficCore\Pipeline\ChooseOfferStage;
use TrafficCore\Pipeline\UpdateCostsStage;
use TrafficCore\Pipeline\UpdatePayoutStage;
use TrafficCore\Pipeline\ExecuteActionStage;
use TrafficCore\Pipeline\CheckSendingToAnotherCampaign;
use TrafficCore\Queue\ClickUpdateQueue;

const UUID_PREFIX = 'uuid_';
const SUB_ID_COUNT = 15;
const EXTRA_PARAM_COUNT = 10;

function landingOfferErrorResponse(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/html');
    echo $message;
    exit;
}

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
$request = $creator->fromGlobals();
$params = array_merge((array) $request->getParsedBody(), $request->getQueryParams());

$token = (string) ($params['_token'] ?? '');

if ($token !== '' && !str_starts_with($token, UUID_PREFIX)) {
    landingOfferErrorResponse(441, 'Page is unavailable');
}

if ($token === '') {
    landingOfferErrorResponse(400, 'Missing _token');
}

$lpTokenService = new LpTokenService();
$rawClick = $lpTokenService->getRawClickByToken($token);

if ($rawClick === null) {
    $subId = $lpTokenService->subIdFromToken($token);
    if ($subId !== null) {
        $stmt = Db::instance()->prepare('SELECT * FROM clicks WHERE sub_id = ? LIMIT 1');
        $stmt->execute([$subId]);
        $rawClick = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}

if ($rawClick === null) {
    landingOfferErrorResponse(422, "Failed to restore click by token '{$token}'");
}

if (!empty($params['offer_id'])) {
    $rawClick['offer_id'] = (int) $params['offer_id'];
}

$payload = new Payload($request);
$payload->rawClick = $rawClick;
$payload->clickFields = ['sub_id' => $rawClick['sub_id'] ?? null];
// Deliberately NOT setting $payload->landingId from the restored click:
// ChooseOfferStage's `if ($payload->landingId !== null) return $payload;`
// guard exists to skip offer selection when ChooseLandingStage picked a
// landing EARLIER IN THE SAME RUN (the normal first-pass case) — it is
// not a "this click already has a landing on record" check. This stage
// list never runs ChooseLandingStage, so leaving landingId null here is
// what lets ChooseOfferStage's normal (non-forced) rotation actually
// resolve a real offer the first time a landing-with-offers-behind-it
// click asks for one. Documented macro-gap consequence: `{landing_id}`/
// `{tds_landing_id}` resolve empty in the offer's own action_payload for
// this one entry point (`ClickMacroValues::forPayload()` reads
// `$payload->landingId` directly) — real, narrow, not silent.
$payload->parentCampaignId = isset($rawClick['parent_campaign_id']) ? (int) $rawClick['parent_campaign_id'] : null;
$payload->forcedCampaignId = isset($rawClick['campaign_id']) ? (int) $rawClick['campaign_id'] : null;
$payload->forcedStreamId = isset($rawClick['stream_id']) ? (int) $rawClick['stream_id'] : null;
$payload->forcedOfferId = isset($rawClick['offer_id']) ? (int) $rawClick['offer_id'] : null;

$runner = new PipelineRunner([
    new CaptureSignalStage(),
    new ResolveVisitorStage(),
    new FindCampaignStage(),
    new CheckDefaultCampaignStage(),
    new CheckParamAliasesStage(),
    new ChooseStreamStage(),
    new ChooseOfferStage(),
    new UpdateCostsStage(),
    new UpdatePayoutStage(),
    new ExecuteActionStage(),
    new CheckSendingToAnotherCampaign(),
]);

$payload = $runner->run($payload);

if (!empty($rawClick['sub_id']) && $payload->offerId !== null) {
    $fields = [
        'sub_id' => $rawClick['sub_id'],
        'offer_id' => $payload->offerId,
        'landing_clicked' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
    ];

    for ($i = 1; $i <= SUB_ID_COUNT; $i++) {
        $key = "sub_id_{$i}";
        if (!empty($params[$key])) {
            $fields[$key] = urldecode((string) $params[$key]);
        }
    }

    for ($i = 1; $i <= EXTRA_PARAM_COUNT; $i++) {
        $key = "extra_param_{$i}";
        if (!empty($params[$key])) {
            $fields[$key] = urldecode((string) $params[$key]);
        }
    }

    if (!empty($params['is_bot'])) {
        $fields['is_bot'] = (int) $params['is_bot'];
    }

    (new ClickUpdateQueue())->push($fields);
}

http_response_code($payload->statusCode);
foreach ($payload->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo $payload->body;
