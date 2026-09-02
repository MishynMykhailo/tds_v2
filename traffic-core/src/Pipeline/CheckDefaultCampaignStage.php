<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;

/**
 * Port of legacy `Traffic\Pipeline\Stage\CheckDefaultCampaignStage`
 * (application/Traffic/Pipeline/Stage/CheckDefaultCampaignStage.php) —
 * runs right after `FindCampaignStage`; a no-op if a campaign was
 * already resolved. Otherwise applies the admin-configured fallback via
 * the `extra_action` setting (values confirmed by reading legacy's
 * `Traffic\Model\Setting` constants):
 *  - `"campaign"` — redirect into a specific fallback campaign
 *    (`extra_campaign` setting, a campaign id) via the SAME
 *    `forcedCampaignId` + `PipelineRunner` recursion mechanism already
 *    built for the `campaign`/`group` action type — legacy does the
 *    literal equivalent (`setForcedCampaignId()` + `abort()`, caught by
 *    `Pipeline::_run()`'s recursion check).
 *  - `"redirect"` — 302 to a fixed URL (`extra_url` setting).
 *  - anything else (including unset) — real 404, same as this stage's
 *    own `_triggerNotFound()`.
 *
 * NOT ported: the "default campaign is configured but inactive ->
 * still 404" edge case's own log message (behavior is identical, just
 * not logged — traffic-core has no per-request log sink yet, same gap
 * as everywhere else in this port).
 */
class CheckDefaultCampaignStage
{
    public function process(Payload $payload): Payload
    {
        if ($payload->campaign !== null) {
            return $payload;
        }

        $pdo = Db::instance();
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');

        $stmt->execute(['extra_action']);
        $extraAction = $stmt->fetchColumn();

        if ($extraAction === 'campaign') {
            $stmt->execute(['extra_campaign']);
            $campaignId = (int) $stmt->fetchColumn();

            if ($campaignId > 0) {
                $check = $pdo->prepare("SELECT id FROM campaigns WHERE id = ? AND state = 'active' LIMIT 1");
                $check->execute([$campaignId]);
                if ($check->fetchColumn()) {
                    $payload->forcedCampaignId = $campaignId;
                    $payload->aborted = true;
                    return $payload;
                }
            }
            // Configured fallback campaign missing/inactive -> fall through to 404.
        } elseif ($extraAction === 'redirect') {
            $stmt->execute(['extra_url']);
            $extraUrl = (string) $stmt->fetchColumn();

            if ($extraUrl !== '') {
                $payload->statusCode = 302;
                $payload->headers['Location'] = $extraUrl;
                $payload->aborted = true;
                return $payload;
            }
        }

        $payload->abort(404, '404 Not Found');

        return $payload;
    }
}
