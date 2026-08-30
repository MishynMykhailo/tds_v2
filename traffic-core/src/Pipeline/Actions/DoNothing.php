<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\DoNothing` (application/
 * Traffic/Actions/Predefined/DoNothing.php) — `_execute()` only calls
 * `setDestinationInfo()` (click-logging metadata, not ported anywhere in
 * traffic-core's trimmed `rawClick` — see BuildRawClickStage's docblock),
 * nothing else. Genuinely a no-op on the HTTP response: leaves
 * `Payload`'s default 200/empty body untouched.
 */
class DoNothing implements ActionHandler
{
    public function execute(Payload $payload): void
    {
        // Intentionally empty — mirrors legacy exactly.
    }
}
