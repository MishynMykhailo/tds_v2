<?php

/**
 * Port of legacy `Traffic\Context\PingDomainContext`
 * (application/Traffic/Context/PingDomainContext.php) — returns a
 * per-install tracker code the backend admin uses to verify a domain
 * really points at this tracker. See `TrafficCore\Domain\DomainService`
 * for the value's formula and why it's not required to match legacy's.
 */

require __DIR__ . '/../vendor/autoload.php';

use TrafficCore\Domain\DomainService;

echo DomainService::getTrackerCode();
