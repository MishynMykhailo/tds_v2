<?php

namespace TrafficCore\Pipeline;

/**
 * Phase 4 — runs first in the pipeline (see public/index.php) so real
 * per-request signal (IP, Referer, User-Agent, Accept-Language, GET/POST
 * params, current time) is available to `ChooseStreamStage`'s
 * `CheckFilters` call. See `Signal::fromRequest()` for exactly what's
 * captured and what's deliberately not (XFF/proxy-chain trust).
 */
class CaptureSignalStage
{
    public function process(Payload $payload): Payload
    {
        $payload->signal = Signal::fromRequest($payload->request);

        return $payload;
    }
}
