<?php

namespace TrafficCore\Pipeline;

/**
 * Port of legacy `Traffic\Pipeline\Pipeline::_run()` +
 * `_preparePayloadForCampaign()` (application/Traffic/Pipeline/
 * Pipeline.php) — the recursive re-run mechanism behind the `campaign`/
 * `group` action types (`CheckSendingToAnotherCampaign` sets
 * `forcedCampaignId` and aborts; this class detects that and restarts
 * the whole stage list from the top, same as legacy re-entering
 * `firstLevelStages()` from index 0).
 *
 * `LIMIT = 10` copied literally from legacy's `Pipeline::LIMIT` — a
 * self-referencing (or mutually-referencing) chain of `campaign` actions
 * throws in legacy (`StageException`, "makes infinite recursion"); here
 * it terminates the response with `508 Loop Detected` instead (a real,
 * applicable HTTP status — legacy has no HTTP-facing equivalent since it
 * throws before any output, this is a deliberate, documented choice, not
 * a literal port of "throw").
 *
 * NOT ported: `parentSubId` (legacy's `RawClick::setParentSubId()`) —
 * `clicks` has no such column in tds_v2's schema (confirmed by reading
 * the migration), so only `parentCampaignId` (a real `clicks` column) is
 * carried forward. `forcedStreamId` reset is also skipped — traffic-core
 * has no forced-stream-id field/feature ported at all yet.
 */
class PipelineRunner
{
    private const LIMIT = 10;

    /** @param object[] $stages Each must expose process(Payload): Payload. */
    public function __construct(private array $stages)
    {
    }

    public function run(Payload $payload): Payload
    {
        $repeats = 0;

        while (true) {
            foreach ($this->stages as $stage) {
                $payload = $stage->process($payload);
                if ($payload->aborted) {
                    break;
                }
            }

            if (!$payload->aborted || $payload->forcedCampaignId === null) {
                return $payload;
            }

            $repeats++;
            if ($repeats >= self::LIMIT) {
                $payload->abort(508, 'Campaign action makes infinite recursion. Aborting.');
                return $payload;
            }

            $payload = $this->prepareForCampaign($payload);
        }
    }

    private function prepareForCampaign(Payload $payload): Payload
    {
        $payload->parentCampaignId = $payload->campaign['id'] ?? null;

        $payload->campaign = null;
        $payload->stream = null;
        $payload->landingId = null;
        $payload->offerId = null;
        $payload->actionType = null;
        $payload->actionPayload = null;
        $payload->actionOptions = null;
        $payload->signal = [];
        $payload->aborted = false;
        // forcedCampaignId is left set — FindCampaignStage consumes and clears it.

        return $payload;
    }
}
