<?php

namespace TrafficCore\Domain;

/**
 * Port of legacy `Component\Domains\Service\DomainService::getTrackerCode()`
 * (application/Component/Domains/Service/DomainService.php) — used by
 * `PingDomainContext` so the backend admin can verify a domain actually
 * points at this tracker (a domain-health "ping" check compares this
 * value against what the admin UI expects).
 *
 * Legacy formula: `substr(md5(SALT . TRACKER_CODE_PREFIX), 3, 10)` where
 * `SALT` is a per-install DB-backed secret (`ConfigService::get("system",
 * "salt")`) and `TRACKER_CODE_PREFIX = "domains"` is a literal constant.
 * traffic-core has no such config system — reuses the SAME `JWT_SALT` env
 * var already established as tds_v2's generic per-install secret
 * substitute (see `TrafficCore\LpToken\LpTokenKey`, same fallback
 * pattern). Not required to match legacy's own value (this code is only
 * ever compared against tds_v2's own admin backend, never legacy's).
 */
class DomainService
{
    private const TRACKER_CODE_PREFIX = 'domains';

    public static function getTrackerCode(): string
    {
        $salt = getenv('JWT_SALT') ?: 'tds_v2-dev-only-salt-override-via-JWT_SALT-env';

        return substr(md5($salt . self::TRACKER_CODE_PREFIX), 3, 10);
    }
}
