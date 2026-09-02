<?php

namespace TrafficCore\Postback;

/**
 * Outcome of `PostbackProcessor::process()` — carries just enough for
 * `public/postback.php` to build the response text and, on success, for
 * `OutboundPostbackService` to fire the S2S sends (campaign_id/status/
 * sub_id/tid/revenue/cost of the saved conversion).
 */
final class PostbackResult
{
    public function __construct(
        public readonly ?int $conversionId,
        public readonly bool $isNew,
        public readonly string $status,
        public readonly ?int $campaignId = null,
        public readonly ?string $subId = null,
        public readonly ?string $tid = null,
        public readonly float $revenue = 0.0,
        public readonly float $cost = 0.0,
    ) {
    }
}
