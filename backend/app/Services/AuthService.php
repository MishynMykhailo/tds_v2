<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPasswordHash;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Compat port of legacy `Component\Users\Service\AuthService`
 * (old codebase: application/Component/Users/Service/AuthService.php +
 * application/Traffic/Cookies/Service/CookiesService.php::setRaw()).
 * See docs/legacy-reference/frontend/backend_api_reference.md §4.1.
 *
 * Only the cookie/JWT STRUCTURE is a compatibility requirement, not the
 * signing secret's byte value:
 *
 *   payload = {
 *     login:     md5(login . "-tds"),
 *     password:  urlencode(bcrypt_hash),
 *     timestamp: unix_time,
 *   }
 *   token = "v1" . JWT::encode(payload, secret, "HS256")
 *
 * `password_hash` in the payload/DB is always the CURRENT bcrypt hash of the
 * user's password at login time (legacy re-derives/stores it on every
 * successful login via `_findByLoginAndPassword()` + `_storeHash()`) — here
 * we only support the bcrypt path (legacy `password` md5 column /
 * `legacyEncodePassword()` fallback is intentionally NOT ported; the parent
 * task only asked for `Hash::check()` against `password_hash`).
 *
 * NOT ported (legacy features irrelevant to this task, see docblocks on the
 * old files): BruteForceDetectionService (handled at controller level per
 * task instructions — not in this service), forceLegacy/forceSingleHash
 * flags, `expireAllTokens()`/`clearCookieToken()` (logout just forgets the
 * cookie, tokens are left to expire naturally via `expires_at`).
 */
class AuthService
{
    public const COOKIE_PARAM = 'states';

    public const VERSION_PREFIX = 'v1';

    /** Matches legacy `AuthService::_getExpireSeconds()` — 31 days. */
    public const TTL_SECONDS = 2678400;

    private function secret(): string
    {
        return (string) config('app.jwt_secret');
    }

    /**
     * Verifies login+password against `users.password_hash` (bcrypt),
     * stores a fresh `user_password_hashes` row (mirrors legacy
     * `_storeHash()`), and returns the signed "v1<jwt>" cookie token, or
     * null if the credentials don't match.
     */
    public function login(string $login, string $password): ?string
    {
        $user = User::where('login', $login)->first();

        if (! $user || empty($user->password_hash) || ! Hash::check($password, $user->password_hash)) {
            return null;
        }

        $expiresAt = Carbon::now()->addSeconds(self::TTL_SECONDS);

        UserPasswordHash::create([
            'user_id' => $user->id,
            'password_hash' => $user->password_hash,
            'expires_at' => $expiresAt,
        ]);

        $payload = [
            'login' => md5($user->login.'-tds'),
            'password' => urlencode($user->password_hash),
            'timestamp' => time(),
        ];

        $jwt = JWT::encode($payload, $this->secret(), 'HS256');

        return self::VERSION_PREFIX.$jwt;
    }

    /**
     * Mirrors legacy `_tryToLoadFromToken()`: strips the "v1" prefix,
     * decodes the JWT, then re-checks login_hash + password_hash against
     * `user_password_hashes` (JOIN semantics via whereHas) with
     * `expires_at > now()`, plus the JWT's own `timestamp` claim against the
     * TTL. Returns null on any failure (malformed token, bad signature,
     * expired, user/hash not found) — never throws.
     */
    public function verifyFromCookie(?string $token): ?User
    {
        if (empty($token) || ! str_starts_with($token, self::VERSION_PREFIX)) {
            return null;
        }

        $jwt = substr($token, strlen(self::VERSION_PREFIX));

        try {
            $decoded = (array) JWT::decode($jwt, new Key($this->secret(), 'HS256'));
        } catch (\Throwable $e) {
            return null;
        }

        $loginHash = $decoded['login'] ?? null;
        $passwordHash = isset($decoded['password']) ? urldecode((string) $decoded['password']) : null;
        $timestamp = $decoded['timestamp'] ?? null;

        if (! is_string($loginHash) || ! is_string($passwordHash) || ! is_numeric($timestamp)) {
            return null;
        }

        if (self::TTL_SECONDS < (time() - (int) $timestamp)) {
            return null;
        }

        // Filter by the password-hash+expiry relation in SQL first (that's
        // the selective, indexable condition) and compare the login hash in
        // PHP afterwards. The original used `whereRaw("MD5(CONCAT(login,
        // '-tds')) = ?")`, which is MySQL-only syntax and breaks the test
        // suite's SQLite DB (`no such function: MD5`) — md5()/concatenation
        // is trivial to do portably in PHP instead, there's no need for it
        // to run inside the SQL engine at all.
        return User::query()
            ->whereHas('passwordHashes', function ($query) use ($passwordHash) {
                $query->where('password_hash', $passwordHash)
                    ->where('expires_at', '>', Carbon::now());
            })
            ->get()
            ->first(fn (User $user) => hash_equals(md5($user->login.'-tds'), $loginHash));
    }
}
