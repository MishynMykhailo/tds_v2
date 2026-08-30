<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

interface ActionHandler
{
    public function execute(Payload $payload): void;
}
