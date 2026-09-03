<?php

namespace App\Services;

use App\Models\Click;
use App\Models\Conversion;

/**
 * Port of legacy `Component\Conversions\Service\ConversionsService`
 * (application/Component/Conversions/Service/ConversionsService.php)
 * `processEntries()`/`import()`/`importArray()`, backing
 * `App\Http\Controllers\Admin\ConversionsController::importAction()`.
 *
 * Legacy's `importArray()` runs each parsed row through the SAME
 * `Component\Postback\ProcessPostback\Pipeline` real postbacks use
 * (`Postback::buildFromParams($entry)` + `$pipeline->process($postback)`).
 * This project's equivalent live-postback logic already lives in
 * `traffic-core/src/Postback/PostbackProcessor.php` — but `backend/` and
 * `traffic-core/` are deliberately separate Composer projects (see
 * docs/ARCHITECTURE_PLAN.md: traffic-core is meant to be ionCube-encoded
 * independently), so there is no cross-project class to call into. This
 * class re-implements the SAME find-or-update-by-sub_id + click-totals-
 * sync semantics natively against Eloquent, matching
 * `PostbackProcessor::process()` field-for-field (see that class's
 * docblock for the exact scope it itself already cut down from legacy's
 * 10-stage pipeline — cost model, sub_id_N/extra_param sync, rebill,
 * conversion-cap — all carried over here unimplemented for the same
 * reasons, not new cuts made for this class).
 *
 * NOT ported: currency conversion. Legacy's `processEntries()` calls
 * `Core\Currency\Service\CurrencyService::exchange()`, which hits a live
 * external exchange-rate API (`Core\Currency\DataSource\ExratesTds`) —
 * confirmed no equivalent exists anywhere in this project (traffic-core's
 * own `Postback` class already made and documented this exact same
 * scope-down: "no currency-conversion infra anywhere ... Revenue and
 * cost stored exactly as parsed, no conversion"). The `currency` request
 * param is still required (matches the controller's existing 406 guard)
 * but has no effect — revenue is stored exactly as submitted, regardless
 * of the declared currency. A real exchange-rate integration is a
 * separate, standalone feature, not a "finish the import stub" detail.
 */
class ConversionImportService
{
    private const STATUS_SALE = 'sale';
    private const STATUS_LEAD = 'lead';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_IGNORE = 'ignore';

    /**
     * Verbatim from `TrafficCore\Postback\Postback::DEFAULT_STATUS_VARIATIONS`
     * (itself a literal port of legacy `Postback::$_statuses`) — kept in
     * sync manually since the two projects share no code.
     */
    private const STATUS_VARIATIONS = [
        self::STATUS_SALE => [
            'approved', '1', 'done', 'confirm', 'confirmed', 'paid', 'rebill', 'sale', 'signup',
            'payed', 'awarded', 'sell', 'sms', 'birj', 'redeem', 'cgbk', 'insf', 'test_sale',
        ],
        self::STATUS_REJECTED => [
            'failure', 'reject', 'cancelled', 'refund', 'cancel', 'decline', 'declined', 'rejected',
            'invalid', 'canceled', 'trash',
        ],
        self::STATUS_IGNORE => [
            'unsubscribe', 'subscribeOff', 'off', 'rfnd', 'cancel-rebill', 'test_rfnd', 'cancel-test-rebill',
        ],
    ];

    /**
     * @return array{errors: string[], success: int, total: int}
     */
    public function import(string $data): array
    {
        $entries = $this->parseEntries($data);

        $good = 0;
        $errors = [];

        foreach ($entries as $entry) {
            $error = $this->importEntry($entry);
            if ($error === null) {
                $good++;
            } else {
                $errors[] = $error;
            }
        }

        return ['errors' => $errors, 'success' => $good, 'total' => count($entries)];
    }

    /**
     * Port of `ConversionsService::processEntries()`. A row with fewer
     * than 2 comma-separated columns is silently dropped — not even
     * counted toward `total` — matching legacy's `isset($params[0]) &&
     * isset($params[1])` guard exactly.
     *
     * @return list<array{sub_id: string, revenue: float, tid: ?string, status: ?string}>
     */
    private function parseEntries(string $data): array
    {
        $entries = [];

        foreach (explode("\n", $data) as $row) {
            $params = explode(',', $row);
            if (!isset($params[0]) || !isset($params[1])) {
                continue;
            }

            $entries[] = [
                'sub_id' => trim($params[0]),
                'revenue' => (float) str_replace(',', '.', trim($params[1])),
                'tid' => isset($params[2]) ? trim($params[2]) : null,
                // null (key genuinely absent) vs a present-but-unmatched string
                // matters for findStatus() below — mirrors legacy's
                // `isset($params[3])` guard on whether the 'status' key is
                // set on the entry array at all.
                'status' => isset($params[3]) ? trim($params[3]) : null,
            ];
        }

        return $entries;
    }

    /**
     * @param array{sub_id: string, revenue: float, tid: ?string, status: ?string} $entry
     * @return string|null Formatted error message, or null on success.
     */
    private function importEntry(array $entry): ?string
    {
        $subId = $entry['sub_id'];

        // Port of legacy's `PostbackError` branch ($errors[] = $e->getMessage(),
        // no sub_id prefix) — `Postback::getSubId()` returning empty throws
        // "SubID empty" with no lookup ever attempted.
        if ($subId === '') {
            return 'SubID empty';
        }

        $click = Click::where('sub_id', $subId)->first();

        // Port of legacy's `NotFoundError` branch — DOES get the sub_id
        // prefix, unlike the branch above (`$entry[self::SUB_ID_NAME] . ": "
        // . $e->getMessage()`). Message text matches
        // `TrafficCore\Postback\PostbackProcessor`'s own port of this same
        // legacy error for consistency between the two independent ports.
        if ($click === null) {
            return sprintf('%s: SubID not found "%s"', $subId, $subId);
        }

        $status = $this->resolveStatus($entry['status']);
        $originalStatus = $entry['status'];
        $revenue = $entry['revenue'];

        $existing = Conversion::where('sub_id', $subId)->first();

        if ($status === self::STATUS_IGNORE) {
            // Port of PostbackProcessor::processIgnore() — no click writes,
            // no new conversion; only original_status touched on an
            // existing row.
            $existing?->update(['original_status' => $originalStatus]);

            return null;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $saleDatetime = $status === self::STATUS_SALE
            ? ($existing?->sale_datetime ?: $nowUtc)
            : null;

        if ($existing === null) {
            Conversion::create([
                'sub_id' => $subId,
                'click_id' => $click->click_id,
                'campaign_id' => $click->campaign_id,
                'stream_id' => $click->stream_id,
                'ts_id' => $click->ts_id,
                'landing_id' => $click->landing_id,
                'offer_id' => $click->offer_id,
                'affiliate_network_id' => $click->affiliate_network_id,
                'tid' => $entry['tid'],
                'click_datetime' => $click->datetime,
                'postback_datetime' => $nowUtc,
                'status' => $status,
                'original_status' => $originalStatus,
                'revenue' => $revenue,
                'cost' => 0,
                'params' => json_encode($entry, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}',
                'sale_datetime' => $saleDatetime,
            ]);
        } else {
            $existing->update([
                'tid' => $entry['tid'] ?? $existing->tid,
                'previous_status' => $existing->status,
                'status' => $status,
                'original_status' => $originalStatus,
                'revenue' => $revenue,
                'cost' => 0,
                'params' => json_encode($entry, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}',
                'sale_datetime' => $saleDatetime,
                // postback_datetime deliberately not touched — a status-
                // update/dedup import row does not overwrite the original
                // notification time, matching PostbackProcessor exactly.
            ]);
        }

        $click->update([
            'is_lead' => $status === self::STATUS_LEAD,
            'is_sale' => $status === self::STATUS_SALE,
            'is_rejected' => $status === self::STATUS_REJECTED,
            'lead_revenue' => $status === self::STATUS_LEAD ? $revenue : 0,
            'sale_revenue' => $status === self::STATUS_SALE ? $revenue : 0,
            'rejected_revenue' => $status === self::STATUS_REJECTED ? $revenue : 0,
        ]);

        return null;
    }

    /**
     * Port of `Postback::findStatus()`, scoped to the import entry shape
     * (no `sale_status`/`lead_status`/`rejected_status`/`ignore_status`
     * override params exist here — those come from general postback
     * request params, which an import row never has). No `status` column
     * in the row at all (`null`) defaults to `sale`; a present-but-
     * unmatched status string falls through to `lead` — both literal
     * legacy quirks, not omissions (see `Postback::findStatus()`'s own
     * docblock in traffic-core for the same note).
     */
    private function resolveStatus(?string $rawStatus): string
    {
        if ($rawStatus === null || $rawStatus === '') {
            return self::STATUS_SALE;
        }

        $matched = self::STATUS_LEAD;
        foreach (self::STATUS_VARIATIONS as $status => $names) {
            if (in_array(strtolower($rawStatus), $names, true)) {
                $matched = $status;
            }
        }

        return $matched;
    }
}
