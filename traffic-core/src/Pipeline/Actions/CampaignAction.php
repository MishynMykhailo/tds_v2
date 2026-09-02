<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\ToCampaign` (application/
 * Traffic/Actions/Predefined/ToCampaign.php) — `_execute()` only resolves
 * the target campaign for click-logging metadata (`setDestinationInfo()`,
 * not ported, see BuildRawClickStage's docblock), same no-op-on-response
 * shape as `DoNothing`. The actual redirect-to-another-campaign work
 * happens one stage later, in `CheckSendingToAnotherCampaign`
 * (`payload->forcedCampaignId` + `PipelineRunner`'s recursion), exactly
 * mirroring legacy's stage order (`ExecuteActionStage` then
 * `CheckSendingToAnotherCampaign` in `Pipeline::firstLevelStages()`).
 *
 * Registered for BOTH `campaign` and `group` action-type keys — confirmed
 * via legacy `StreamActionRepository::alias("group", "campaign")`, i.e.
 * `group` is a pure alias of the same handler, not a distinct action.
 */
class CampaignAction implements ActionHandler
{
    public function execute(Payload $payload): void
    {
        // Intentionally empty — mirrors legacy exactly.
    }
}
