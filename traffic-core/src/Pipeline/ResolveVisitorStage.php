<?php

namespace TrafficCore\Pipeline;

use TrafficCore\BotDetection\BotDetectionService;
use TrafficCore\Pipeline\Device\DeviceInfoResolver;
use TrafficCore\Pipeline\GeoDb\GeoDbResolver;
use TrafficCore\Pipeline\Visitor\VisitorResolver;

/**
 * New stage (no direct legacy equivalent — legacy resolves GeoDb/device
 * info and the Visitor row as sub-steps inside its monolithic
 * `BuildRawClickStage::_findIpInfo()`/`_findDeviceInfo()`, see that
 * file's docblock). Split out here as its own pipeline stage so
 * `BuildRawClickStage` (reserved for the other in-progress feature —
 * only its `visitor_id` line changes) stays a one-line consumer of an
 * already-resolved `payload->visitorId`.
 *
 * Runs right after `CaptureSignalStage` (needs `payload->signal['ip']`/
 * `['userAgent']`, see `Signal::fromRequest()`) and before
 * `BuildRawClickStage` (needs `payload->visitorId`).
 *
 * Ports, in order: `IpInfoService::getIpInfo()` (via `GeoDbResolver`),
 * `DeviceInfoService::info()` (via `DeviceInfoResolver`), then
 * `VisitorService::generateCode()` + the real find-or-create Visitor
 * lookup (via `VisitorResolver`) — see each of those classes' docblocks
 * for exactly what was/wasn't ported and why. Finally resolves
 * `payload->isBot` (`BotDetection\BotDetectionService`) — deliberately
 * done HERE, not in `BuildRawClickStage` (which is where legacy's own
 * `_checkIfBot()` textually lives), because the resolved value must be
 * available to `ChooseStreamStage`'s `bot` `StreamFilter` — which in
 * THIS pipeline's ordering (see `public/index.php`'s ordering-deviation
 * note) runs before `BuildRawClickStage`, same as it needs to run before
 * `ChooseStreamStage` in legacy's own (different) ordering.
 *
 * GeoDb/device resolution failures are swallowed by their resolvers
 * (null fields, never an exception) — but a resolved visitor id of 0
 * would silently corrupt `clicks.visitor_id`, so `VisitorResolver`
 * throwing here is left to propagate: a broken visitor insert is a real
 * bug that should surface, not a GeoDb/device edge case to paper over.
 */
class ResolveVisitorStage
{
    public function __construct(
        private GeoDbResolver $geoDb = new GeoDbResolver(),
        private DeviceInfoResolver $device = new DeviceInfoResolver(),
        private VisitorResolver $visitors = new VisitorResolver(),
        private BotDetectionService $botDetection = new BotDetectionService(),
    ) {
    }

    public function process(Payload $payload): Payload
    {
        $ip = (string) ($payload->signal['ip'] ?? '');
        $userAgent = (string) ($payload->signal['userAgent'] ?? '');
        $language = (string) ($payload->signal['language'] ?? '');

        $geo = $this->geoDb->resolve($ip);
        $deviceInfo = $this->device->resolve($userAgent);

        $payload->geoDevice = ['geo' => $geo, 'device' => $deviceInfo];
        $payload->visitorId = $this->visitors->resolve($ip, $userAgent, $geo, $deviceInfo, $language);
        $payload->isBot = $this->botDetection->resolve($deviceInfo['is_bot'] ?? null, $userAgent, $ip);

        return $payload;
    }
}
