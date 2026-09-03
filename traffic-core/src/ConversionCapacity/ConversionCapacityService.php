<?php

namespace TrafficCore\ConversionCapacity;

use TrafficCore\Redis\RedisClient;

/**
 * Port of legacy `Component\Conversions\ConversionCapacity\Storage\
 * RedisStorage` (application/Component/Conversions/ConversionCapacity/
 * Storage/RedisStorage.php) — the offer daily-cap mechanism, previously
 * left unbuilt (see `ChooseOfferStage`'s own docblock: "offers.
 * conversion_cap_enabled/daily_cap columns exist in backend/ but no
 * runtime check reads them yet"). Legacy also has a `FileStorage`
 * fallback (`ConversionCapacityRepository::getCurrentStorage()` picks
 * one at runtime) — NOT ported, matching this project's established
 * Redis-only precedent for every other per-entity counter
 * (`HitLimitService`, visitor uniqueness) — this project has no
 * file-based storage layer anywhere, adding one just for this would be
 * inconsistent with every other counter already built.
 *
 * Mechanism ported 1-to-1: one Redis sorted set per offer, key
 * `daily_cap:<offer_id>` (`ZNAME`), member = a unique-per-call string
 * (only needs to be unique, content unused), score = the CONVERSION's
 * own postback timestamp (not the click's) — matches legacy's
 * `$conversion->getPostbackDatetime()->getTimestamp()` exactly.
 * `currentValueForOffer()` counts from local midnight (in the offer's
 * own `conversion_timezone`, "UTC" default) to +inf — a real per-
 * calendar-day count, not a rolling 24h window (unlike `HitLimitService::
 * perDay()`, which IS rolling — legacy really does use two different
 * "day" semantics for these two features, confirmed by reading both
 * storages directly, not assumed for consistency).
 */
class ConversionCapacityService
{
    private const SET_PREFIX = 'daily_cap:';

    public function store(int $offerId, int $timestamp): void
    {
        $member = date('YmdHis').random_int(10000, 999999);
        RedisClient::instance()->zadd($this->setName($offerId), [$member => $timestamp]);
    }

    /** @param string $timezone e.g. "UTC", "Europe/Kyiv" — offers.conversion_timezone */
    public function currentValueForOffer(int $offerId, string $timezone, int $timestamp): int
    {
        $midnight = (new \DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new \DateTimeZone($timezone !== '' ? $timezone : 'UTC'))
            ->setTime(0, 0, 0)
            ->getTimestamp();

        return (int) RedisClient::instance()->zcount($this->setName($offerId), $midnight, '+inf');
    }

    public function totalValueForOffer(int $offerId): int
    {
        return (int) RedisClient::instance()->zcount($this->setName($offerId), '-inf', '+inf');
    }

    private function setName(int $offerId): string
    {
        return self::SET_PREFIX.$offerId;
    }
}
