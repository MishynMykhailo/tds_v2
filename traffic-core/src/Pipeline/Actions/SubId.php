<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\SubId` (application/Traffic/
 * Actions/Predefined/SubId.php) — echoes the click's generated sub_id back
 * to the caller, optionally JSONP-wrapped. Bypasses the `frm` context
 * mechanism entirely in legacy too (no `_executeInContext()` call), so
 * this does not extend `AbstractAction`.
 *
 * `$payload->rawClick['sub_id']` (set by `BuildRawClickStage`, which runs
 * before this stage) stands in for legacy's `RawClick::getSubId()` — same
 * value, just read from the trimmed array instead of a RawClick object.
 */
class SubId implements ActionHandler
{
    private const SUB_ID = 'SubId';

    public function execute(Payload $payload): void
    {
        $subId = (string) ($payload->rawClick['sub_id'] ?? '');

        $query = $payload->request->getQueryParams();
        if (($query['return'] ?? null) === 'jsonp') {
            $payload->headers['Content-Type'] = 'application/javascript; charset=utf-8';
            $subId = 'KTracking.response("' . $subId . '")';
        }

        $payload->body = $subId;
    }
}
