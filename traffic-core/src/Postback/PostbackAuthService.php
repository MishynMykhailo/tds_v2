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
 * `findKey()` ports `_findKey()` literally — both mechanisms, in the
 * same priority order as legacy: (1) a `key` query param, (2) a
 * bare/valueless query param used AS the key itself (legacy's
 * `$params[0]` — a query string like `?SECRET&subid=...` where `SECRET`
 * has no `=value`; PHP puts that literal token as an array key with an
 * empty string value, not under a numeric index, so this is read by
 * scanning the raw query string for the
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

    /**
     * NEW (no legacy equivalent — the two mechanisms `findKey()` already
     * ports are legacy's real, only ones): pretty-URL key, `/{key}/postback`
     * — e.g. `https://yourdomain.com/6a05078/postback?subid=...` instead
     * of `?key=6a05078&subid=...`. Requested by the project owner: a
     * bare query param leaks the fact that it's a "secret" more than a
     * URL path segment does, and matches the shape real Keitaro-family
     * installs are commonly configured with in production (via a
     * webserver rewrite in front of the app — see `deploy/nginx-traffic-
     * core.conf.example`).
     *
     * traffic-core has no routing layer of its own (every entry point is
     * a literal file under `public/`) — reaching this method with a
     * `/{key}/postback` request therefore requires SOMETHING in front to
     * route it to `public/postback.php` (nginx `rewrite` in production;
     * `public/router.php` for local dev via `php -S host:port -t public
     * public/router.php` — see that file). This method itself is
     * transport-agnostic: it just reads whatever path the incoming
     * request actually has.
     */
    public function findPathKey(\Psr\Http\Message\ServerRequestInterface $request): ?string
    {
        $path = $request->getUri()->getPath();

        if (preg_match('#^/([^/]+)/postback/?$#', $path, $matches) === 1) {
            $key = urldecode($matches[1]);

            return $key !== '' ? $key : null;
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
