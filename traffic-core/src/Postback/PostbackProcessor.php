<?php

namespace TrafficCore\Postback;

/**
 * Scoped-down port of legacy `Component\Postback\ProcessPostback\Pipeline`
 * + its `PayloadFactory`/`Payload`/`Stages\*` (application/Component/
 * Postback/ProcessPostback/). Legacy runs 10 stages
 * (`BuildConversionStage`, `UpdateStatusesStage`, `UpdateRevenueStage`,
 * `UpdateCostsStage`, `UpdateConversionParamsStage`,
 * `UpdateClickParamsStage`, `SaveChangesStage`,
 * `SyncConversionWithClickStage`, `SendPostbacksStage`,
 * `UpdateConversionCapStage`) over a `Payload` per matched click. This
 * class folds the subset actually in scope into one place:
 *
 *  - **find-or-update by sub_id** (`BuildConversionStage` + `PayloadFactory`
 *    dedup logic) — see `PostbackConversionRepository`'s docblock for the
 *    exact simplification (one conversion row per sub_id, not per
 *    sub_id+tid).
 *  - **status** (`UpdateStatusesStage`) — conversion `status`/
 *    `previous_status`/`original_status`, click `is_lead`/`is_sale`/
 *    `is_rejected`. Rebill status (`Conversion::REBILL`) is NOT ported —
 *    `checkOldConversionsHasSale()`/`isAdditionalConversion()`'s upsell
 *    detection needs the multi-conversion-per-subid tracking this port
 *    explicitly skips.
 *  - **revenue** (`UpdateRevenueStage`) — conversion `revenue` = the
 *    postback's raw revenue (no currency exchange, no offer override —
 *    `getRevenueCalculated($offer)` simplified to the plain value, per
 *    task). Click `lead_revenue`/`sale_revenue`/`rejected_revenue`:
 *    legacy sums `getTotalLeadRevenue()`/etc. across every OLD + NEW
 *    conversion for the click. Since this port tracks at most one
 *    conversion per sub_id, that sum collapses to "this conversion's
 *    revenue under its own status's column, 0 for the other two" — exactly
 *    what `updateClickTotals()` below does.
 *  - **cost** (`UpdateCostsStage`) — SCOPED DOWN HARD: legacy's
 *    `_updateClickCost()` branches on the campaign's `cost_type`
 *    (revshare vs. fixed vs. per-sale/per-acquisition vs. auto) and
 *    recomputes `clicks.cost`. That whole campaign-cost-model business
 *    rule is out of scope for this task (not listed in the task's
 *    explicit (a)/(b) scope) — only `conversions.cost` is set, directly
 *    from the postback's own `cost` param (0 if absent). `clicks.cost` is
 *    never written by this class.
 *  - **datetime bookkeeping** (`UpdateConversionParamsStage::
 *    _updateDatetime()`): `postback_datetime` is set ONLY on insert (a
 *    status-update/dedup postback does NOT overwrite the original
 *    notification time — literal port of `if (!isStatusChange())`);
 *    `sale_datetime` is set to "now" the first time status becomes
 *    `sale` and left alone on subsequent sale updates, and cleared to
 *    `NULL` whenever the current status is not `sale` — literal port of
 *    `_updateDatetime()`'s sale_datetime rules (rebill is not a status
 *    this port produces, so that half of the OR is moot here).
 *
 * Explicitly SKIPPED (per task's scope-down list, cited to their exact
 * legacy source):
 *  - `UpdateConversionParamsStage::_updateSubIds()`/`_updateExtraParam()`
 *    and `UpdateClickParamsStage` entirely — sub_id_N/extra_param
 *    click/conversion field sync. Not requested by the task's (a)/(b)
 *    scope; `SubIdNService::getOrCreateSubIdN()` dictionary plumbing this
 *    would need is unrelated to the postback feature itself.
 *  - `UpdateConversionParamsStage::_processIgnorePostback()` IS ported
 *    (see the `isIgnore()` branch below) — it's cheap and directly
 *    relevant to the 4-status model (`sale`/`lead`/`rejected`/`ignore`)
 *    the task's `Postback` class already implements.
 *  - `Payload::haltOnOfferDisallowRebill()`, `UpdateConversionCapStage`,
 *    `is_processed`, rebill (`Conversion::REBILL`) — all explicitly named
 *    as skipped in the task description.
 */
class PostbackProcessor
{
    private PostbackClickRepository $clicks;
    private PostbackConversionRepository $conversions;

    public function __construct(
        ?PostbackClickRepository $clicks = null,
        ?PostbackConversionRepository $conversions = null
    ) {
        $this->clicks = $clicks ?? new PostbackClickRepository();
        $this->conversions = $conversions ?? new PostbackConversionRepository();
    }

    /** @throws PostbackException */
    public function process(Postback $postback): PostbackResult
    {
        $subId = $postback->getSubId();
        if ($subId === null || $subId === '') {
            throw new PostbackException('SubID empty');
        }

        $click = $this->clicks->findBySubId($subId);
        if ($click === null) {
            throw new PostbackException(sprintf('SubID not found "%s"', $subId));
        }

        $existing = $this->conversions->findBySubId($subId);
        $isNew = $existing === null;

        if ($postback->isIgnore()) {
            return $this->processIgnore($postback, $existing, $subId);
        }

        $status = $postback->getStatus();
        $revenue = $postback->getRevenue();
        $cost = $postback->getCost();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $saleDatetime = null;
        if ($status === 'sale') {
            $saleDatetime = ($existing['sale_datetime'] ?? null) ?: $nowUtc;
        }

        $paramsJson = json_encode($postback->getParams(), JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($paramsJson === false) {
            $paramsJson = '{}';
        }

        if ($isNew) {
            $conversionId = $this->conversions->insert([
                'sub_id' => $subId,
                'click_id' => $click['click_id'],
                'campaign_id' => $click['campaign_id'],
                'stream_id' => $click['stream_id'],
                'ts_id' => $click['ts_id'] ?? null,
                'landing_id' => $click['landing_id'],
                'offer_id' => $click['offer_id'],
                'affiliate_network_id' => $click['affiliate_network_id'] ?? null,
                'tid' => $postback->getTid(),
                'click_datetime' => $click['datetime'],
                'postback_datetime' => $nowUtc,
                'status' => $status,
                'original_status' => $postback->getOriginalStatus(),
                'revenue' => $revenue,
                'cost' => $cost,
                'params' => $paramsJson,
                'sale_datetime' => $saleDatetime,
            ]);
        } else {
            $conversionId = (int) $existing['conversion_id'];
            $this->conversions->update($conversionId, [
                'tid' => $postback->getTid() ?? $existing['tid'],
                'status' => $status,
                'previous_status' => $existing['status'],
                'original_status' => $postback->getOriginalStatus(),
                'revenue' => $revenue,
                'cost' => $cost,
                'params' => $paramsJson,
                'sale_datetime' => $saleDatetime,
                // postback_datetime deliberately not touched — see class docblock.
            ]);
        }

        $this->updateClickTotals((int) $click['click_id'], $status, $revenue);

        return new PostbackResult(
            conversionId: $conversionId,
            isNew: $isNew,
            status: $status,
            campaignId: (int) $click['campaign_id'],
            subId: $subId,
            tid: $postback->getTid(),
            revenue: $revenue,
            cost: $cost,
        );
    }

    /** @param array<string,mixed>|null $existing */
    private function processIgnore(Postback $postback, ?array $existing, string $subId): PostbackResult
    {
        if ($existing !== null) {
            $this->conversions->update((int) $existing['conversion_id'], [
                'original_status' => $postback->getOriginalStatus(),
            ]);
        }

        // No click writes, no new conversion — matches legacy's shared
        // `isIgnore()` early-return across UpdateStatusesStage/
        // UpdateRevenueStage/UpdateCostsStage/SaveChangesStage, and
        // BuildConversionStage's `isStatusChange()` gate (ignore is
        // always treated as "not a new conversion", even for a brand new
        // sub_id — a first-ever postback with an ignore status creates
        // nothing at all, literal legacy behavior).
        return new PostbackResult(
            conversionId: $existing['conversion_id'] ?? null,
            isNew: false,
            status: 'ignore',
            subId: $subId,
        );
    }

    private function updateClickTotals(int $clickId, string $status, float $revenue): void
    {
        $this->clicks->update($clickId, [
            'is_lead' => $status === 'lead' ? 1 : 0,
            'is_sale' => $status === 'sale' ? 1 : 0,
            'is_rejected' => $status === 'rejected' ? 1 : 0,
            'lead_revenue' => $status === 'lead' ? $revenue : 0,
            'sale_revenue' => $status === 'sale' ? $revenue : 0,
            'rejected_revenue' => $status === 'rejected' ? $revenue : 0,
        ]);
    }
}
