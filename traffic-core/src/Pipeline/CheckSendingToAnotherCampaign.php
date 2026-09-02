<?php

namespace TrafficCore\Pipeline;

/**
 * Port of legacy `Traffic\Pipeline\Stage\CheckSendingToAnotherCampaign`
 * (application/Traffic/Pipeline/Stage/CheckSendingToAnotherCampaign.php)
 * — literal 1-to-1 port, no simplification: if the stream's action
 * resolved to `campaign` or `group` (`group` is a legacy alias of the
 * same handler, see `CampaignAction`'s docblock), set
 * `payload->forcedCampaignId` from the action payload (the target
 * campaign id) and abort the current pass so `PipelineRunner` re-runs
 * the whole stage list for that campaign (mirrors legacy
 * `Pipeline::_run()`'s `isAborted() && getForcedCampaignId()` check).
 *
 * Runs AFTER `ExecuteActionStage` in the stage list, exactly matching
 * legacy's `firstLevelStages()` order — `CampaignAction::execute()`
 * itself is a no-op, this stage is what actually triggers the redirect.
 */
class CheckSendingToAnotherCampaign
{
    public function process(Payload $payload): Payload
    {
        if ($payload->actionType === 'campaign' || $payload->actionType === 'group') {
            $payload->forcedCampaignId = (int) $payload->actionPayload;
            $payload->aborted = true;
        }
        return $payload;
    }
}
