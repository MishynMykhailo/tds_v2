<?php

namespace Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Thin HTTP client wrapper around the admin-panel API contract documented in
 * docs/legacy-reference/frontend/backend_api_reference.md (§2, §4, §6, §7).
 *
 * Deliberately backend-agnostic: it only knows "base URL + ?object=controller.action
 * routing + cookie auth", which is the documented contract, not an implementation
 * detail of the legacy PHP backend. Point TDS_TEST_TARGET at any backend that speaks
 * this contract (legacy or the Laravel rewrite) and these tests should still apply.
 */
final class ApiClient
{
    public const DEFAULT_LOGIN = 'admin';
    public const DEFAULT_PASSWORD = 'TdsAdmin2026!';

    private Client $http;
    private CookieJar $cookies;

    public function __construct(?string $baseUrl = null)
    {
        $baseUrl = $baseUrl ?? self::resolveBaseUrl();

        $this->cookies = new CookieJar();
        $this->http = new Client([
            // Admin panel is routed as ?object=controller.action under
            // /admin/index.php — see §2. The trailing `index.php` is
            // REQUIRED, not cosmetic: found live (2026-09-03) that a bare
            // `/admin/` base_uri silently 404s against the new Laravel
            // backend (its only route is the literal `/admin/index.php`
            // path, App\Http\Controllers\ObjectDispatchController — no
            // directory-index fallback the way a real webserver in front
            // of the legacy app provides for legacy's `/admin/`). This
            // means the "new backend" side of every contract test in this
            // suite has been silently unrunnable (immediate login 404)
            // until this fix — legacy alone happened to still work via
            // its webserver's own DirectoryIndex behavior, masking the
            // bug. See docs/PORTING_LOG.md for the full write-up.
            'base_uri' => rtrim($baseUrl, '/') . '/admin/index.php',
            'cookies' => $this->cookies,
            'http_errors' => false, // we assert status codes ourselves, incl. 403/404/406
            'timeout' => 15,
        ]);
    }

    public static function resolveBaseUrl(): string
    {
        $target = getenv('TDS_TEST_TARGET');
        if ($target === false || $target === '') {
            throw new RuntimeException(
                'TDS_TEST_TARGET env var is not set. Example: TDS_TEST_TARGET=http://localhost:8090'
            );
        }

        return $target;
    }

    /**
     * Cookie-based login per §4.1: POST ?object=auth.login, sets the `states` cookie.
     * On success the legacy backend replies {"success": true}.
     */
    public function login(string $login = self::DEFAULT_LOGIN, string $password = self::DEFAULT_PASSWORD): ResponseInterface
    {
        return $this->http->post('', [
            'query' => ['object' => 'auth.login'],
            'json' => ['login' => $login, 'password' => $password],
        ]);
    }

    public function get(string $object, array $query = []): ResponseInterface
    {
        return $this->http->get('', [
            'query' => array_merge(['object' => $object], $query),
        ]);
    }

    public function post(string $object, array $query = [], array $jsonBody = []): ResponseInterface
    {
        return $this->http->post('', [
            'query' => array_merge(['object' => $object], $query),
            'json' => $jsonBody,
        ]);
    }

    /**
     * Decode a response body as JSON regardless of the Content-Type header.
     * Per §2.2 the legacy backend does not always set Content-Type: application/json
     * on single ?object= requests even though the body is valid JSON.
     */
    public static function json(ResponseInterface $response): mixed
    {
        $body = (string) $response->getBody();

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
