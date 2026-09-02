<?php

/**
 * traffic-core entrypoint — Phase 1-4 proof of concept.
 *
 * Mirrors legacy `index.php` + `Traffic\Pipeline\Pipeline::firstLevelStages()`
 * (application/Traffic/Pipeline/Pipeline.php), trimmed to the subset of
 * stages ported so far. Real stage order (confirmed by reading
 * Pipeline.php, not guessed):
 *   DomainRedirectStage, CheckPrefetchStage, BuildRawClickStage,
 *   FindCampaignStage, CheckDefaultCampaignStage, UpdateRawClickStage,
 *   CheckParamAliasesStage, UpdateCampaignUniquenessSessionStage,
 *   ChooseStreamStage, UpdateStreamUniquenessSessionStage,
 *   ChooseLandingStage, ChooseOfferStage, GenerateTokenStage,
 *   FindAffiliateNetworkStage, UpdateHitLimitStage, UpdateCostsStage,
 *   UpdatePayoutStage, SaveUniquenessSessionStage, SetCookieStage,
 *   ExecuteActionStage, PrepareRawClickToStoreStage,
 *   CheckSendingToAnotherCampaign, StoreRawClicksStage.
 *
 * Implemented so far: CaptureSignalStage -> FindCampaignStage ->
 * ChooseStreamStage -> ChooseLandingStage -> ChooseOfferStage ->
 * BuildRawClickStage -> ExecuteActionStage -> StoreRawClickStage. Phase 4
 * adds `CaptureSignalStage` (not a legacy stage — real per-request IP/UA/
 * Referer/Accept-Language/params capture, feeds `ChooseStreamStage`'s
 * `CheckFilters` real filter engine, see Signal::fromRequest()). See
 * docs/TRAFFIC_CORE_PLAN.md for the full phased plan and why the rest is
 * deferred.
 *
 * NOTE on ordering vs. legacy (two deliberate deviations, same root
 * cause): legacy runs `BuildRawClickStage` first (before campaign/stream/
 * landing/offer are even resolved) and `ChooseLandingStage`/
 * `ChooseOfferStage` AFTER `ChooseStreamStage` but BEFORE the cost/payout/
 * token stages, all before `ExecuteActionStage`. Here, this trimmed
 * `BuildRawClickStage` only needs to persist ids that already exist by
 * the time it runs, so it was moved to run LAST among the "resolve"
 * stages — after landing/offer selection, not before — so it can include
 * `landing_id`/`offer_id` in a single INSERT. Once real per-request
 * fields (IP/UA/referrer) are ported this whole ordering should be
 * revisited to match legacy's.
 *
 * Campaign-recursion addition: `CheckSendingToAnotherCampaign` now runs
 * after `ExecuteActionStage` (same position as legacy's
 * `firstLevelStages()`), and the whole stage list is driven by
 * `PipelineRunner` instead of a flat one-pass `foreach` — see
 * `PipelineRunner`'s docblock for the recursion mechanics
 * (`forcedCampaignId` + `LIMIT = 10`, mirrors legacy `Pipeline::_run()`).
 *
 * Hit-limit/cost/payout addition: `UpdateHitLimitStage`,
 * `UpdateCostsStage`, `UpdatePayoutStage` inserted right after
 * `GenerateTokenStage` and before `ExecuteActionStage` — this matches
 * legacy's real relative order exactly (see the full stage list above:
 * `GenerateTokenStage, FindAffiliateNetworkStage, UpdateHitLimitStage,
 * UpdateCostsStage, UpdatePayoutStage, SaveUniquenessSessionStage,
 * SetCookieStage, ExecuteActionStage` — `FindAffiliateNetworkStage`/
 * `SaveUniquenessSessionStage`/`SetCookieStage` remain unported, not
 * needed for these three). All three only mutate `$payload->rawClick`
 * (`cost`/`is_sale`/`sale_revenue`), so running them before
 * `ExecuteActionStage` guarantees the final values are already in
 * `rawClick` by the time `StoreRawClickStage` builds its INSERT — and
 * `UpdateHitLimitStage`'s own hit-recording is unconditional at this
 * point in the pipeline, so it happens regardless of whether the
 * click's action later redirects successfully.
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

if (!empty(\TrafficCore\Pipeline\CheckFilters::$skipped)) {
    $payload->headers['X-Filters-Skipped'] = implode(',', \TrafficCore\Pipeline\CheckFilters::$skipped);
}

http_response_code($payload->statusCode);
foreach ($payload->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo $payload->body;
