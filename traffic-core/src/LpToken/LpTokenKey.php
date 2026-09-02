<?php

namespace TrafficCore\LpToken;

/**
 * Port of the one piece of legacy `Traffic\LpToken\Service\LpTokenService`
 * (application/Traffic/LpToken/Service/LpTokenService.php) that
 * `double_meta`/the gateway endpoint actually need:
 * `generateUserKey($postFix)` = `hash("sha256", SALT) . $postFix` — a
 * per-user-agent JWT signing key (not really "per user", just makes the
 * signed token unverifiable with a different UA, a cheap anti-tamper/
 * anti-replay-across-clients measure, nothing more).
 *
 * Legacy's `SALT` is a per-install secret loaded from its own DB-backed
 * config (`Core\Application\Bootstrap` -> `ConfigService::get("system",
 * "salt")`) — traffic-core has no such config system, so this is a
 * NEW secret for tds_v2 specifically, not required to match legacy's
 * (double_meta tokens are only ever encoded and decoded by traffic-core
 * itself, never exchanged with the old app). Read from `JWT_SALT` env
 * var; the fallback is dev-only and MUST be overridden via env in any
 * real deployment (same pattern as `Db::instance()`'s getenv() defaults).
 *
 * NOT ported: the rest of `LpTokenService` (`storeRawClick`/
 * `getRawClickByToken`/TTL/UUID-token storage) — that's the unrelated
 * `GenerateTokenStage`/offer-redirect two-step tracking token flow, not
 * needed by `double_meta`'s JWT (confirmed by reading both classes: they
 * share a namespace and a static-helper method, nothing else).
 */
class LpTokenKey
{
    public static function generateUserKey(string $userAgent): string
    {
        $salt = getenv('JWT_SALT') ?: 'tds_v2-dev-only-salt-override-via-JWT_SALT-env';

        return hash('sha256', $salt) . $userAgent;
    }
}
