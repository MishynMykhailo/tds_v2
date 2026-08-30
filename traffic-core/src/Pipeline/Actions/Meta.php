<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\Meta` (application/Traffic/
 * Actions/Predefined/Meta.php) — meta-refresh redirect.
 *
 * `executeForScript()` NOT ported: legacy pipes the meta-refresh HTML
 * through `Traffic\Actions\AdsParser` (application/Traffic/Actions/
 * AdsParser.php, not ported anywhere in traffic-core) to rewrite it for
 * async `<script>`-tag ad-network loads keyed by a `_cid` query param.
 * Falls back to `AbstractAction`'s generic "incompatible" stub instead of
 * emitting unparsed, likely-broken output — visible gap, not silent.
 */
class Meta extends AbstractAction
{
    protected function executeDefault(Payload $payload): void
    {
        $payload->body = RedirectService::metaRedirect((string) $payload->actionPayload);
    }
}
