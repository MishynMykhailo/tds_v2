<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;

/**
 * Trimmed port of legacy `Traffic\Pipeline\Stage\FindCampaignStage`
 * (application/Traffic/Pipeline/Stage/FindCampaignStage.php).
 *
 * Ported: resolve by an explicit `?campaign=<alias>` query param
 * (legacy's `_tryToFindCampaign()` walks several alias sources — here
 * only the direct query param is supported), and the domain-default
 * fallback (legacy's `_findDomainDefaultCampaign()` via
 * `CachedDomainRepository::getCampaignIdByUrl()` — here: `domains` table
 * lookup by `Host` header).
 *
 * NOT ported (documented in docs/TRAFFIC_CORE_PLAN.md as deferred):
 * `allow_by_id` numeric-id fallback, `ParameterRepository` custom alias
 * keys, "any query param key is a possible alias" fallback, campaign
 * caching (`CachedCampaignRepository`).
 *
 * When no campaign resolves (alias/domain-default both miss), this stage
 * no longer 404s directly — it leaves `payload->campaign` null and
 * returns, matching legacy's real `FindCampaignStage::process()` (which
 * also just `return $payload;`s on a miss) so `CheckDefaultCampaignStage`
 * (runs right after) can apply the admin-configured fallback (redirect
 * to a specific campaign, a fixed URL, or a real 404) instead of always
 * hard-coding 404.
 *
 * Campaign-recursion addition: when `payload->forcedCampaignId` is set
 * (by `CheckSendingToAnotherCampaign` on a `campaign`/`group` action,
 * consumed here by `PipelineRunner`'s re-run), resolve by that id
 * directly and skip alias/domain resolution entirely — mirrors legacy's
 * `_preparePayloadForCampaign()` re-entering `FindCampaignStage` with a
 * fresh `RawClick` but the target campaign already decided.
 */
class FindCampaignStage
{
    public function process(Payload $payload): Payload
    {
        $pdo = Db::instance();

        if ($payload->forcedCampaignId !== null) {
            $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ? AND state = 'active' LIMIT 1");
            $stmt->execute([$payload->forcedCampaignId]);
            $campaign = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            $payload->forcedCampaignId = null;

            if (!$campaign) {
                $payload->abort(404, 'Forced campaign not found');
                return $payload;
            }

            $payload->campaign = $campaign;
            return $payload;
        }

        $params = $payload->request->getQueryParams();

        $campaign = null;

        if (!empty($params['campaign'])) {
            $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE alias = ? AND state = 'active' LIMIT 1");
            $stmt->execute([$params['campaign']]);
            $campaign = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        if (!$campaign) {
            $host = $payload->request->getHeaderLine('Host');
            $host = explode(':', $host)[0];
            if ($host !== '') {
                $stmt = $pdo->prepare('SELECT default_campaign_id FROM domains WHERE name = ? LIMIT 1');
                $stmt->execute([$host]);
                $domain = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($domain && $domain['default_campaign_id']) {
                    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ? AND state = 'active' LIMIT 1");
                    $stmt->execute([$domain['default_campaign_id']]);
                    $campaign = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
                }
            }
        }

        if ($campaign) {
            $payload->campaign = $campaign;
        }

        return $payload;
    }
}
