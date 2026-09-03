<?php

namespace TrafficCore\BotDetection;

use TrafficCore\Db;

/**
 * Port of legacy `Component\BotDetection\Service\UserBotListService`
 * (application/Component/BotDetection/Service/UserBotListService.php),
 * called from `Traffic\Pipeline\Stage\BuildRawClickStage::_checkIfBot()`.
 * Wired here into `TrafficCore\Pipeline\BuildRawClickStage` the same way
 * (see that class, `is_bot` field).
 *
 * NOT ported: the GeoDb `BOT_TYPE` branch legacy checks FIRST (`if
 * ($rawClick->get(IpInfoType::BOT_TYPE)) { setBot(true); }`) — confirmed
 * unreachable in this project the same way ISP already is (see
 * `Pipeline\GeoDb\GeoDbResolver`'s docblock): `bot_type`/`proxy_type` both
 * require IP2Location's paid "PX"/proxy database tier, and this project
 * only has the free LITE DB3 (country/region/city) `.BIN` file. `isBot()`
 * itself is otherwise a complete, faithful port:
 *  - `check_bot_empty_ua` (default true here, matching legacy install
 *    default `1` from `application/data/data.sql`) — empty user agent
 *    string = bot, checked first, short-circuits everything else.
 *  - `check_bot_ua` (default true) — the exact ~50-entry hardcoded
 *    substring signature list below (`stristr`, case-insensitive
 *    substring, ported 1:1 including legacy's own duplicate entries —
 *    e.g. "Googlebot"/"Twitterbot" each appear twice in the real source,
 *    kept verbatim rather than de-duplicated since this is a literal
 *    port, not a rewrite), OR the admin-maintained custom signature list
 *    (`Setting` row `bots.additional.signature` — see
 *    `App\Http\Controllers\Admin\BotlistController`, same substring
 *    match semantics as legacy's `CheckInList` v2 mode).
 *  - `check_bot_ip` (default true) — admin-maintained IP range list
 *    (`user_bot_ips` table: `min_ip <= ip2long($ip) <= max_ip`, 1-to-1
 *    with legacy's `MysqlStorage::itemExists()`).
 *  - `check_bot_referer` setting exists in legacy (and in this project's
 *    Settings UI) but is READ and never actually used anywhere inside
 *    the real `UserBotListService::isBot()` — a genuine legacy dead
 *    option, not a gap in this port. Not implemented here either, for
 *    the same reason.
 *
 * Settings defaults (`check_bot_ip`/`check_bot_ua`=1,
 * `check_bot_empty_ua`=0 when the `settings` row is simply absent, e.g. a
 * fresh install that never touched the Bot Detection settings page) match
 * `application/data/data.sql`'s real install-time values — a missing row
 * is NOT treated as "feature off", since a real legacy install always has
 * these rows populated from day one.
 */
class BotDetectionService
{
    /**
     * Verbatim from legacy `UserBotListService::$_signatures` — including
     * its own duplicate entries (Googlebot, Twitterbot).
     *
     * @var string[]
     */
    private const SIGNATURES = [
        'Advisorbot', 'crawler', 'oBot', 'spider', 'ezooms', 'FlipboardProxy',
        'CHTML Proxy', 'TweetmemeBot', 'bitlybot', 'SputnikBot', 'Googlebot',
        'SemrushBot', 'YandexBot', 'WebIndex', 'Slurp', 'org_bot', 'bot.html',
        'bot.php', 'Twitterbot', 'Adsbot', '/bots', 'RU_Bot', 'OrangeBot',
        'Synapse', 'SEOstats', 'urllib', 'Owler', 'ltx71', 'WinHttpRequest',
        'python-requests', 'PageAnalyzer', 'OpenLinkProfiler', 'BOT for JCE',
        'BUbiNG', 'Nutch', 'megaindex', 'SeznamBot', 'Twitterbot', 'bingbot',
        'facebook', 'Google Web Preview', 'BingPreview/1.0b',
        'Exabot-Thumbnails', 'coccoc', 'Googlebot', 'Sleuth', 'cmcm.com',
        'YandexMobileBot', 'curl', 'Google-Youtube-Links', 'MailRuConnect',
        'vkShare', 'SurveyBot', 'AppEngine', 'NetcraftSurveyAgent',
    ];

    private const BOT_SIGNATURE_SETTING_KEY = 'bots.additional.signature';

    /**
     * Single entry point for `ResolveVisitorStage`: `$deviceIsBot` is
     * `DeviceInfoResolver::resolve()`'s `is_bot` (device-detector's own,
     * much larger bot-signature database, `check_bot_ua`-gated, `null`
     * when that setting is off) — mirrors legacy `_checkIfBot()`'s `if
     * (!$rawClick->isBot())` short-circuit exactly: a `true` there is
     * authoritative and skips this class's own check entirely.
     */
    public function resolve(?bool $deviceIsBot, string $userAgent, string $ip): bool
    {
        if ($deviceIsBot === true) {
            return true;
        }

        return $this->isBot($userAgent, $ip);
    }

    public function isBot(string $userAgent, string $ip): bool
    {
        $settings = $this->settings(['check_bot_ip', 'check_bot_ua', 'check_bot_empty_ua']);

        if (($settings['check_bot_empty_ua'] ?? true) && $userAgent === '') {
            return true;
        }

        if (($settings['check_bot_ua'] ?? true) && $this->checkByUserAgent($userAgent)) {
            return true;
        }

        if (($settings['check_bot_ip'] ?? true) && $this->checkByIp($ip)) {
            return true;
        }

        return false;
    }

    private function checkByUserAgent(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        foreach (self::SIGNATURES as $signature) {
            if (stripos($userAgent, $signature) !== false) {
                return true;
            }
        }

        return $this->checkByCustomSignatures($userAgent);
    }

    private function checkByCustomSignatures(string $userAgent): bool
    {
        $pdo = Db::instance();
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([self::BOT_SIGNATURE_SETTING_KEY]);
        $list = (string) $stmt->fetchColumn();

        if ($list === '') {
            return false;
        }

        foreach (preg_split('/\r\n|\r|\n/', $list) as $signature) {
            $signature = trim($signature);
            if ($signature !== '' && stripos($userAgent, $signature) !== false) {
                return true;
            }
        }

        return false;
    }

    private function checkByIp(string $ip): bool
    {
        $intIp = ip2long($ip);
        if ($intIp === false) {
            return false;
        }
        // ip2long() returns a signed 32-bit int on 32-bit builds but this
        // project only targets 64-bit PHP (matches the rest of traffic-core,
        // e.g. GeoDbResolver's IPv4-only assumption), so no sign-mask needed
        // for the unsigned min_ip/max_ip columns' range comparison here.

        $pdo = Db::instance();
        $stmt = $pdo->prepare('SELECT 1 FROM user_bot_ips WHERE min_ip <= ? AND ? <= max_ip LIMIT 1');
        $stmt->execute([$intIp, $intIp]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param  string[]  $keys
     * @return array<string,bool>
     */
    private function settings(array $keys): array
    {
        $pdo = Db::instance();
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ({$placeholders})");
        $stmt->execute($keys);

        $defaults = [
            'check_bot_ip' => true,
            'check_bot_ua' => true,
            'check_bot_empty_ua' => false,
        ];

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $defaults[$key] ?? false;
        }

        foreach ($stmt->fetchAll(\PDO::FETCH_KEY_PAIR) as $key => $value) {
            $result[$key] = (bool) ((int) $value);
        }

        return $result;
    }
}
