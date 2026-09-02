<?php

namespace TrafficCore\Pipeline;

/**
 * Port of legacy `Traffic\Pipeline\Stage\UpdateCostsStage`
 * (application/Traffic/Pipeline/Stage/UpdateCostsStage.php). Mutates
 * `$payload->rawClick['cost']` (already present, initialized by
 * `BuildRawClickStage` from the request's own `?cost=` param — read as
 * the starting value for the `cost_auto` branch here, not re-parsed from
 * the request).
 *
 * `cost_auto` branch: cost comes from `$payload->rawClick['cost']`
 * (`campaigns.cost_auto`/legacy `isCostAuto()`); non-auto branch: cost
 * comes from `campaigns.cost_value`/`cost_currency` — no `currency`
 * handling here at all (same as legacy's own `$currency` var, which is
 * captured but never has anywhere to go — `clicks` has no `currency`
 * column, confirmed via live `DESCRIBE`, matching `BuildRawClickStage`'s
 * own documented gap).
 *
 * `_applyTrafficLoss()` ported literally: `cost / (1 - traffic_loss/100)`,
 * only when both `cost` and `traffic_loss` are truthy.
 * `_patchMegapush()` ported literally (two-line regex fix for a leading
 * `"00"` cost string).
 *
 * `cost_type` groupings — copied EXACTLY from
 * `application/Traffic/Model/Campaign.php` (read directly, not guessed
 * from constant names):
 *   - isCostPerUnique():        cost_type in {CPUC, "CPUV"}
 *   - isCostPerThousand():      cost_type === CPM
 *   - isCostPerAcquisition():   cost_type === CPA
 *   - isCostPerSale():          cost_type === CPS
 *   - isCostPerClick():         cost_type in {CPC, "CPV"}
 *   - isCostRevShare():         cost_type === "RevShare"
 *
 * **Finding #1 (real legacy dead code, ported as-is per this task's
 * "port literally, don't fix" instruction)**: `cost_type` is a single
 * scalar DB column, so the six groups above are mutually exclusive. The
 * outer gate below only enters when cost_type is CPA/CPS/RevShare —
 * which makes the nested `isCostPerUnique()` (CPUC/CPUV) branch, and
 * everything inside it (the CPM/CPC per-thousand/per-click sub-checks),
 * UNREACHABLE in legacy itself: a campaign can never simultaneously be
 * "CPA/CPS/RevShare" and "CPUC/CPUV". Verified by reading every
 * `isCostPer*()`/`isCostRevShare()` method body directly — none overlap.
 * Ported byte-for-byte anyway (same nesting, same dead branch) because
 * the task is explicit about not "fixing" legacy oddities; flagging it
 * here because it means CPM/CPC/CPUC/CPUV campaigns NEVER get a nonzero
 * cost from this stage at all (cost stays 0), matching legacy's real,
 * if surprising, behavior — not a bug introduced by this port.
 *
 * **Finding #2 (documented, real, temporary limitation)**: the only
 * *reachable* cost-applying path (`isCostPerAcquisition() ||
 * isCostPerSale() || isCostRevShare()` true, `isCostPerUnique()` false)
 * additionally requires `$rawClick->isUniqueCampaign()` — a
 * per-campaign-uniqueness flag. traffic-core has no per-campaign/
 * per-stream/global uniqueness infrastructure yet (only the visitor row
 * itself is real so far — confirmed: `clicks.is_unique_campaign` exists
 * as a schema column but nothing in this project populates it). Per this
 * task's explicit instruction, `isUniqueCampaign()` is hardcoded `false`
 * below rather than fabricating a check — meaning, combined with Finding
 * #1, **this stage currently never applies a nonzero cost for ANY
 * cost_type**, until real campaign-uniqueness flags are built. This is a
 * faithful, literal port of legacy's own gating logic, not a shortcut
 * taken here.
 */
class UpdateCostsStage
{
    private const COST_TYPE_CPM = 'CPM';
    private const COST_TYPE_CPC = 'CPC';
    private const COST_TYPE_CPUC = 'CPUC';
    private const COST_TYPE_REV_SHARE = 'RevShare';
    private const COST_TYPE_CPA = 'CPA';
    private const COST_TYPE_CPS = 'CPS';

    public function process(Payload $payload): Payload
    {
        if ($payload->campaign === null || empty($payload->rawClick)) {
            return $payload;
        }

        $campaign = $payload->campaign;

        if ((bool) $campaign['cost_auto']) {
            // Already captured into rawClick['cost'] by BuildRawClickStage
            // from the request's own `?cost=` param — read it here rather
            // than re-parsing the request, per this task's instruction.
            $cost = $payload->rawClick['cost'] ?? null;
            if (is_string($cost)) {
                $cost = str_replace(',', '.', $cost);
            }
            $cost = $this->patchMegapush($cost);
        } else {
            $cost = $campaign['cost_value'];
        }

        // Legacy: `$rawClick->setCost(0);` unconditionally before the
        // numeric-sanity check, so an incorrect cost still leaves 0 (not
        // the raw request value) on the click.
        $payload->rawClick['cost'] = 0;

        if (!empty($cost) && !is_numeric($cost)) {
            error_log('[UpdateCostsStage] Incorrect cost received - ' . $cost);

            return $payload;
        }

        $cost = $this->applyTrafficLoss((float) $cost, (float) $campaign['traffic_loss']);
        $costType = (string) $campaign['cost_type'];

        if ($this->isCostPerAcquisition($costType) || $this->isCostPerSale($costType) || $this->isCostRevShare($costType)) {
            if ($this->isCostPerUnique($costType)) {
                // See Finding #1 — unreachable given cost_type's single
                // scalar value, ported literally anyway.
                if ($this->isCostPerThousand($costType) && $cost) {
                    if (!($this->isCostPerClick($costType) && $cost)) {
                        $payload->rawClick['cost'] = $cost;
                    }
                } else {
                    $payload->rawClick['cost'] = $cost / 1000;
                }

                return $payload;
            }

            if ($this->isUniqueCampaign()) {
                $payload->rawClick['cost'] = $cost;
            }

            return $payload;
        }

        return $payload;
    }

    /**
     * See Finding #2 above — hardcoded `false` until traffic-core has
     * real per-campaign uniqueness flags. Deliberately a method (not an
     * inline literal) so it is easy to find/grep and swap out later.
     */
    private function isUniqueCampaign(): bool
    {
        return false;
    }

    private function applyTrafficLoss(float $cost, float $trafficLoss): float
    {
        if ($cost && $trafficLoss) {
            return $cost / (1 - $trafficLoss / 100);
        }

        return $cost;
    }

    private function patchMegapush(mixed $cost): mixed
    {
        if (is_string($cost) && preg_match('/^00[0-9]+/', $cost)) {
            $cost = preg_replace('/^00/', '0.', $cost);
        }

        return $cost;
    }

    private function isCostPerUnique(string $costType): bool
    {
        return in_array($costType, [self::COST_TYPE_CPUC, 'CPUV'], true);
    }

    private function isCostPerThousand(string $costType): bool
    {
        return $costType === self::COST_TYPE_CPM;
    }

    private function isCostPerAcquisition(string $costType): bool
    {
        return $costType === self::COST_TYPE_CPA;
    }

    private function isCostPerSale(string $costType): bool
    {
        return $costType === self::COST_TYPE_CPS;
    }

    private function isCostPerClick(string $costType): bool
    {
        return in_array($costType, [self::COST_TYPE_CPC, 'CPV'], true);
    }

    private function isCostRevShare(string $costType): bool
    {
        return $costType === self::COST_TYPE_REV_SHARE;
    }
}
