<?php

/**
 * Port of legacy `Traffic\Context\KtrkContext` + `Traffic\Dispatcher\
 * KtrkDispatcher` (application/Traffic/Context/KtrkContext.php,
 * application/Traffic/Dispatcher/KtrkDispatcher.php) — the receiving end
 * of the `kclient.js` client-side tracking flow: a landing page's own
 * script requests this URL directly (real browser request, no ip/ua/
 * referrer override params — unlike `click-api.php`/`ClickApiContext`,
 * `KtrkContext` doesn't touch `modifyRequest()` at all), runs the SAME
 * click pipeline as a normal click (domain-default campaign resolution,
 * no token/api_key auth — confirmed by reading `KtrkContext::
 * dispatcher()`: it builds a bare `Payload` itself, doesn't go through
 * any of `ClickApiContext`'s auth branches), then wraps the result as a
 * `KTracking.response(json)` JS callback the embedded client code
 * invokes.
 *
 * VERIFIED LEGACY BUG (same root cause as `click-api.php`'s docblock):
 * `KtrkDispatcher extends ClickApiDispatcher` and is constructed with a
 * hardcoded version 2 (`new KtrkDispatcher($pipelinePayload, 2)`) —
 * `ClickApiDispatcher::dispatch()`'s version-2 branch has no `return`
 * statement, so `parent::dispatch($request)` always returns `null`,
 * which `KtrkDispatcher::dispatch()`'s own `if (empty($response)) throw
 * new Exception("Empty response")` immediately turns into an uncaught
 * exception on EVERY real call. `/ktrk` is dead in the legacy source as
 * shipped. This port runs the click pipeline and returns the intended
 * `sub_id`/`token` callback instead of reproducing a guaranteed
 * exception nobody could have depended on.
 *
 * Only `sub_id`/`token` are reported back (matches legacy's own
 * `KtrkDispatcher` JSON — `getRawClick()->getSubId()`/`getToken()`, not
 * the full `ClickApiResponseBuilder` shape used by `click-api.php`).
 */

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use TrafficCore\Pipeline\Payload;
use TrafficCore\Pipeline\PipelineRunner;
use TrafficCore\Pipeline\DomainRedirectStage;
use TrafficCore\Pipeline\CheckPrefetchStage;
use TrafficCore\Pipeline\CaptureSignalStage;
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

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
$request = $creator->fromGlobals();

$payload = new Payload($request);

$runner = new PipelineRunner([
    new DomainRedirectStage(),
    new CheckPrefetchStage(),
    new CaptureSignalStage(),
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

if ($payload->statusCode !== 200 && $payload->aborted) {
    http_response_code($payload->statusCode);
    foreach ($payload->headers as $name => $value) {
        header("{$name}: {$value}");
    }
    echo $payload->body;
    exit;
}

$json = json_encode([
    'sub_id' => $payload->rawClick['sub_id'] ?? null,
    'token' => $payload->lookupToken,
]);

header('Content-Type: application/javascript');
echo "KTracking.response({$json});";
