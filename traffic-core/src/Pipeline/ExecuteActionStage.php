<?php

namespace TrafficCore\Pipeline;

/**
 * Trimmed port of legacy `Traffic\Pipeline\Stage\ExecuteActionStage`
 * (application/Traffic/Pipeline/Stage/ExecuteActionStage.php), which
 * dispatches to one of ~18 action classes registered in
 * `Traffic\Actions\Repository\StreamActionRepository` (application/
 * Traffic/Actions/Repository/StreamActionRepository.php) by the
 * `action_type` string key.
 *
 * Ported: only the `"http"` key (legacy
 * `Traffic\Actions\Predefined\HttpRedirect` —
 * application/Traffic/Actions/Predefined/HttpRedirect.php) — plain
 * `Location` header + 302. Legacy's `kversion` version-compare branch
 * (skips the 302 status for old client versions) is dropped: this core
 * has no such legacy client to support, always sends 302.
 *
 * NOT ported (see docs/TRAFFIC_CORE_PLAN.md): the other 17 action types
 * (`remote` — curl-fetch-then-redirect, `local_file`, `curl`, `frame`,
 * `iframe`, `js*`, `meta`, `double_meta`, `show_html`, `show_text`,
 * `campaign` (chained redirect), `sub_id`, `blank_referrer`,
 * `formsubmit`, `status404`, `do_nothing`).
 */
class ExecuteActionStage
{
    public function process(Payload $payload): Payload
    {
        if (empty($payload->actionType)) {
            // Mirrors legacy: empty actionType -> leave response as-is
            // (legacy's own `do_nothing` action / no-stream fallback).
            return $payload;
        }

        if ($payload->actionType !== 'http') {
            $payload->abort(501, "Action type \"{$payload->actionType}\" not implemented in traffic-core yet (Phase 1 only supports \"http\")");
            return $payload;
        }

        $payload->statusCode = 302;
        $payload->headers['Location'] = $payload->actionPayload;

        return $payload;
    }
}
