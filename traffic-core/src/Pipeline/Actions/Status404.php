<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Literal port of legacy `Traffic\Actions\Predefined\Status404`
 * (application/Traffic/Actions/Predefined/Status404.php) — sets a bare 404
 * status, no body.
 */
class Status404 implements ActionHandler
{
    public function execute(Payload $payload): void
    {
        $payload->statusCode = 404;
    }
}
