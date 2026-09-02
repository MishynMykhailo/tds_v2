<?php

namespace TrafficCore\Pipeline;

/**
 * Trimmed port of legacy `Traffic\Pipeline\Stage\BuildRawClickStage`
 * (application/Traffic/Pipeline/Stage/BuildRawClickStage.php — ~15
 * sub-steps in the real class: language, referrer, search-engine,
 * keyword, sub-ids, extra-params, cost, GeoDb IP info, device info, bot
 * detection, proxy detection).
 *
 * Ported here: only the columns that are NOT NULL on `clicks`
 * (visitor_id, sub_id, datetime, campaign_id, source_id, referrer_id)
 * plus stream_id — enough to satisfy the table's constraints with
 * honest placeholder values, not real tracking data. Phase 3 adds
 * landing_id/offer_id from `ChooseLandingStage`/`ChooseOfferStage`
 * (both nullable, both null when the stream's schema isn't
 * landings/offers). Campaign-recursion adds `parent_campaign_id`
 * (`clicks` has this column for real, unlike `parent_sub_id` — see
 * `PipelineRunner`'s docblock) from `payload->parentCampaignId`, set by
 * `PipelineRunner::prepareForCampaign()` on a `campaign`/`group` hop.
 *
 * NOT ported (see docs/TRAFFIC_CORE_PLAN.md): visitor resolution
 * (`Component\Clicks\Model\Visitor` — here a random id is generated,
 * legacy finds-or-creates a real Visitor row keyed by ip+useragent),
 * every GeoDb/device/bot/referrer/keyword/subid/extra-param field (all
 * left at column defaults — 0/NULL), IP/UA capture.
 */
class BuildRawClickStage
{
    public function process(Payload $payload): Payload
    {
        $payload->rawClick = [
            // NOT a real visitor lookup — see docblock above.
            'visitor_id' => random_int(1, PHP_INT_MAX),
            'sub_id' => bin2hex(random_bytes(16)),
            'datetime' => gmdate('Y-m-d H:i:s'),
            'campaign_id' => $payload->campaign['id'],
            'parent_campaign_id' => $payload->parentCampaignId,
            'stream_id' => $payload->stream['id'] ?? null,
            'landing_id' => $payload->landingId,
            'offer_id' => $payload->offerId,
            'source_id' => 0,
            'referrer_id' => 0,
        ];

        return $payload;
    }
}
