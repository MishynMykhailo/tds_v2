<?php

namespace TrafficCore\Postback;

use TrafficCore\Db;

/**
 * Port of the secret-key check from legacy
 * `Traffic\Dispatcher\PostbackDispatcher::dispatch()` +
 * `Component\AffiliateNetworks\Repository\NetworkTemplatesRepository::
 * getSecret()` (application/Traffic/Dispatcher/PostbackDispatcher.php,
 * application/Component/AffiliateNetworks/Repository/
 * NetworkTemplatesRepository.php:59).
 *
 * `_findKey()` ported literally — both mechanisms, in the same priority
 * order as legacy: (1) a `key` query param, (2) a bare/valueless query
 * param used AS the key itself (legacy's `$params[0]` — a query string
 * like `?SECRET&subid=...` where `SECRET` has no `=value`; PHP puts that
 * literal token as an array key with an empty string value, not under a
 * numeric index, so this is read by scanning the raw query string for the
 * first key with an empty value rather than literally indexing `[0]`,
 * which would never populate under standard PSR-7 query parsing — see
 * this class's `findKey()` docblock for why).
 *
 * `getSecret()` DEVIATION (explicit per task): legacy falls back to
 * `substr(md5(SALT), 15, 7)` when no `postback_key` config value is set —
 * traffic-core has no global `SALT` constant equivalent. Instead: the
 * secret lives in the `settings` table under key `postback_key`
 * (`SELECT value FROM settings WHERE \`key\` = 'postback_key'`), matching
 * this project's existing settings-lookup convention (see
 * `TrafficCore\LpToken\LpTokenService::setting()`). If unset/empty, this
 * is treated as "the postback endpoint is disabled" — `isValid()` returns
 * `false` for EVERY key, including an empty/null one, rather than
 * inventing a fake fallback secret. This must be documented plainly: an
 * install that never sets `settings.postback_key` cannot receive
 * postbacks at all, by design.
 */
class PostbackAuthService
{
    private const SETTING_KEY = 'postback_key';

    public function findKey(\Psr\Http\Message\ServerRequestInterface $request): ?string
    {
        $query = $request->getQueryParams();

        if (isset($query['key']) && $query['key'] !== '') {
            return (string) $query['key'];
        }

        // Fallback: the first bare (valueless) query param, e.g.
        // `?SECRET&subid=123` -> `SECRET` used as the key itself. A
        // "valueless" param parses to an empty string under PHP's/PSR-7's
        // query parsing, so the first key whose value is '' (and which
        // isn't itself the literal string "key") is taken as the
        // candidate secret, walked in raw query-string order.
        $rawQuery = $request->getUri()->getQuery();
        if ($rawQuery === '') {
            return null;
        }

        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '' || str_contains($pair, '=')) {
                continue;
            }

            $candidate = urldecode($pair);

            return $candidate !== '' ? $candidate : null;
        }

        return null;
    }

    public function isValid(?string $key): bool
    {
        $secret = $this->getSecret();

        if ($secret === null || $secret === '') {
            // Postback endpoint effectively disabled — no fake fallback
            // secret is ever generated (see class docblock).
            return false;
        }

        return $key !== null && hash_equals($secret, $key);
    }

    private function getSecret(): ?string
    {
        $stmt = Db::instance()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([self::SETTING_KEY]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
