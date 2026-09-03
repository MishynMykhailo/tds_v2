<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Port of the `store()` half of legacy `Component\Conversions\
 * ConversionCapacity\Storage\RedisStorage` (application/Component/
 * Conversions/ConversionCapacity/Storage/RedisStorage.php) — records a
 * conversion against an offer's daily-cap Redis sorted set (key
 * `daily_cap:<offer_id>`, score = the conversion's own postback
 * timestamp). Used by `ConversionImportService` only — this port's
 * manual CSV import (`conversions.import`) needs the SAME side effect
 * legacy's real import has, since legacy's `ConversionsService::
 * importArray()` runs every row through the identical
 * `Component\Postback\ProcessPostback\Pipeline` a live postback uses
 * (including `UpdateConversionCapStage`), confirmed when
 * `ConversionImportService` was first ported.
 *
 * Deliberately just the write side: unlike `TrafficCore\
 * ConversionCapacity\ConversionCapacityService` (traffic-core, the real
 * click-processing offer-selection path), `backend/` never picks an
 * offer for a live click — it only ever records an already-decided
 * offer's conversion, so `currentValueForOffer()`/the alternative-offer
 * fallback chain has no caller here and isn't duplicated.
 *
 * Uses the `traffic` Redis connection (config/database.php) — an
 * unprefixed connection into the SAME Redis keyspace traffic-core's own
 * `TrafficCore\Redis\RedisClient` writes to directly (see
 * `App\Console\Commands\PruneHitLimits`'s docblock for the full
 * "why a separate connection" reasoning — `default`/`cache` apply
 * Laravel's own key prefix and would never see traffic-core's real keys).
 */
class ConversionCapacityService
{
    private const SET_PREFIX = 'daily_cap:';

    public function store(int $offerId, int $timestamp): void
    {
        $member = date('YmdHis').random_int(10000, 999999);
        Redis::connection('traffic')->zadd(self::SET_PREFIX.$offerId, $timestamp, $member);
    }
}
