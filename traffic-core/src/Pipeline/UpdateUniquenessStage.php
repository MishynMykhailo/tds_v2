<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Uniqueness\UniquenessService;

/**
 * Port of legacy `UpdateCampaignUniquenessSessionStage` +
 * `UpdateStreamUniquenessSessionStage` + `SaveUniquenessSessionStage`
 * (application/Traffic/Pipeline/Stage/*.php) — merged into one stage
 * here since traffic-core computes the whole `rawClick` array in a
 * single pass (`BuildRawClickStage`) rather than mutating a shared
 * mutable object across many stages, same adaptation already applied to
 * `CheckParamAliasesStage` this session.
 *
 * Sets `payload->rawClick['is_unique_campaign']`/`is_unique_stream`/
 * `is_unique_global` — CHECKED before this click's own hit is recorded
 * (mirrors legacy: the three `UpdateXUniquenessSessionStage`s only
 * READ, `SaveUniquenessSessionStage` — which runs later, near
 * `SetCookieStage`, itself unported — is what WRITES). Here, check and
 * touch happen in the same stage since there is no later unported stage
 * left to defer the write to and no reason to.
 *
 * Bot short-circuit ported literally: legacy sets `is_unique_campaign`/
 * `is_unique_stream` to `false` outright for a bot click, without even
 * checking the session (`RawClick::isBot()` — traffic-core has no bot
 * detection at all yet, `clicks.is_bot` stays at its column default `0`,
 * so this branch is currently unreachable — documented, not a fixable
 * gap in this stage itself).
 *
 * NOT ported: cookie-based storage (see `UniquenessService`'s docblock
 * for why), the "deprecated" murmurhash3 uniqueness id fallback.
 */
class UpdateUniquenessStage
{
    public function process(Payload $payload): Payload
    {
        if (empty($payload->rawClick) || $payload->campaign === null) {
            return $payload;
        }

        $ip = $payload->signal['ip'] ?? '';
        $userAgent = $payload->signal['userAgent'] ?? '';
        $uniqueByIpUa = ($payload->campaign['uniqueness_method'] ?? 'ip_ua') !== 'ip';
        $campaignId = (int) $payload->campaign['id'];
        $streamId = $payload->stream['id'] ?? null;
        $streamId = $streamId !== null ? (int) $streamId : null;

        $service = new UniquenessService();
        $result = $service->check($ip, $userAgent, $uniqueByIpUa, $campaignId, $streamId);

        $payload->rawClick['is_unique_campaign'] = $result['campaign'] ? 1 : 0;
        $payload->rawClick['is_unique_stream'] = $streamId === null ? 0 : ($result['stream'] ? 1 : 0);
        $payload->rawClick['is_unique_global'] = $result['global'] ? 1 : 0;

        $ttlSeconds = (int) ($payload->campaign['cookies_ttl'] ?? 0) * 3600;
        $service->touch($ip, $userAgent, $uniqueByIpUa, $campaignId, $streamId, $ttlSeconds);

        return $payload;
    }
}
