<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\UserBotIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy `Component\BotDetection\Controller\BotlistController`
 * (old codebase: application/Component/BotDetection/Controller/BotlistController.php),
 * backed by `Component\BotDetection\Service\UserBotsService` +
 * `Component\BotDetection\BotsStorage\MysqlStorage` +
 * `Component\BotDetection\Model\UserBotIp` (application/Component/BotDetection/
 * Service/UserBotsService.php, BotsStorage/MysqlStorage.php, Model/UserBotIp.php).
 *
 * SCOPE (explicit task boundary): legacy stores the user bot-IP list in TWO
 * possible backends behind `UserBotsStorageRepository::getMainStorage()`:
 *  - `DBCAStorage` — a proprietary binary DB format (same family as the cut
 *    ip2location/GeoDb vendor binaries) that gets built lazily the first time
 *    a save happens. This is NOT ported — no proprietary DB writer/reader
 *    exists in this codebase, and none should be reimplemented from a
 *    guessed format.
 *  - `MysqlStorage` — a plain `tds_user_bot_ips` table
 *    (`min_ip`/`max_ip`/`raw_value`, see `App\Models\UserBotIp` +
 *    `database/migrations/..._create_user_bot_ips_table.php`, already 1-to-1
 *    with the legacy table per DESCRIBE). This is the ONLY backend this
 *    controller implements, per task instruction — every action below is a
 *    faithful, line-by-line port of `UserBotsService`'s parsing/merging
 *    logic (which is storage-agnostic) wired directly to `UserBotIp::query()`
 *    instead of `MysqlStorage`.
 *
 * `UserBotsStorageRepository::getRepository()`/`getService()` picks between
 * `MysqlStorage` and legacy-file storage based on
 * `isMigratedToNewFormat()` = "does a DBCA db file exist, or does the legacy
 * flat-file NOT exist" — i.e. a fresh/default install with no legacy file on
 * disk is *already* considered "migrated" and uses the Mysql-array path
 * (`UserBotsService`/`MysqlStorage`), not the DBCA path (DBCA is only
 * actually written to once a save has happened AND the old flat file
 * existed to migrate from). This confirms Mysql-backed `UserBotsService` is
 * the correct default-path logic to port, not a fallback of last resort.
 * The pre-migration flat-file path (`UserBotsLegacyRepository`/
 * `UserBotsLegacyService`, single `var/bots/bots.additional.dat` text file,
 * no add/exclude support — `addToList`/`excludeFromList`/`cleanList` all
 * throw "needs migration") is NOT ported here: this app has no legacy
 * install to migrate from, so it always behaves as "already migrated".
 *
 * IMPORTANT CORRECTNESS NOTE re: `raw_value` (verified against the real
 * source, not assumed): `raw_value` is NOT simply "whatever the user typed"
 * persisted verbatim. `UserBotsService::_mergeIntersectedInSortedList()`
 * calls `_fillRawValue()` on *every* entry that reaches storage — including
 * entries that never intersected anything else — which unconditionally
 * recomputes `raw_value` from `min_ip`/`max_ip` as either a single
 * `long2ip($min)` (when `$min === $max`) or a `"$minIp-$maxIp"` dash range.
 * A CIDR like `1.2.3.0/24` the user typed is therefore normalized to
 * `1.2.3.0-1.2.3.255` before it's ever stored — the original CIDR text does
 * not survive. Replicated faithfully here via `fillRawValue()` below, not
 * "corrected" to preserve user input, since that would diverge from actual
 * legacy behavior other code may depend on.
 *
 * Legacy `Traffic\Service\ConfigService::isDemo()` gate on the 4 mutating
 * actions is not ported, same precedent as SettingsController::updateAction()
 * (no ConfigService/"demo mode" concept exists anywhere in this codebase).
 *
 * `clearBotListAction` in legacy also clears the legacy flat-file list
 * (`UserBotsLegacyRepository::clearList()`) alongside the Mysql-backed one —
 * skipped here since the flat-file backend isn't ported (see above); only
 * the `user_bot_ips` table is cleared.
 *
 * Bot *signature* (user-agent string) list is an entirely separate feature
 * from the IP list, backed by `Component\BotDetection\Service\
 * UserBotSignatureService` — verified against its real source
 * (application/Component/BotDetection/Service/UserBotSignatureService.php):
 * it is a flat NEWLINE-joined list of trimmed strings persisted to a single
 * flat file (`var/bots/bots.additional.signature.dat`), NOT a JSON blob.
 * There is no flat-file store in this Laravel port, so it's persisted as a
 * single `Setting` row instead (key below) holding that same
 * newline-joined plain-text value verbatim (not JSON-encoded) — the closest
 * faithful equivalent of "one opaque text blob" using infrastructure that
 * already exists in this codebase (same `Setting` key/value table
 * `GeoDbsController` already uses for its own blob-shaped value).
 * `getAdditionalListCount()` counts newlines-plus-one on that raw string
 * (`mb_substr_count($list, "\n") + 1`), NOT the number of parsed/deduped
 * items — replicated literally (unlike the IP-list count, which legacy
 * computes as `count($array)`, i.e. number of stored ranges — the two counts
 * are deliberately computed differently in legacy itself).
 *
 * Legacy `saveAdditionalList()` also calls `array_unique($items)` without
 * assigning the result back — a no-op due to the missing assignment, so
 * duplicates are NOT actually deduped in real legacy behavior. Reproduced
 * here by simply not deduping, rather than "fixing" a bug that live
 * behavior may already depend on.
 */
class BotlistController extends Controller
{
    private const BOT_SIGNATURE_SETTING_KEY = 'bots.additional.signature';

    // ---------------------------------------------------------------
    // Legacy param-reading helper, duplicated per-controller convention
    // (see GeoDbsController/SettingsController/etc.).
    // ---------------------------------------------------------------

    private function parsedBody(Request $request): ?array
    {
        $raw = $request->getContent();
        $trimmed = is_string($raw) ? ltrim($raw) : '';

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);

            return is_array($decoded) ? $decoded : null;
        }

        if (is_string($raw) && str_contains($raw, '&')) {
            parse_str($raw, $parsed);

            return $parsed;
        }

        return null;
    }

    /** Legacy `getPostParam($name)` — parsed body ONLY (not query). */
    private function postParam(Request $request, string $name): mixed
    {
        $body = $this->parsedBody($request);

        return is_array($body) ? ($body[$name] ?? null) : null;
    }

    // ---------------------------------------------------------------
    // IP-range parsing (`UserBotsService::mapItem()` / `_parseInput()` /
    // `_validateSingle()`, ported literally).
    // ---------------------------------------------------------------

    /**
     * `Traffic\Tools\Tools::isValidCIDR()`, ported literally including its
     * quirks (no octet-count check beyond the regex, no rejection of a
     * missing netmask since this is only ever called with one present).
     */
    private function isValidCidr(string $cidr): bool
    {
        if (! preg_match('/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}(\/[0-9]{1,2})?$/', $cidr)) {
            return false;
        }

        [$ip, $netmask] = array_pad(explode('/', $cidr), 2, '');

        foreach (explode('.', $ip) as $octet) {
            if ((int) $octet > 255) {
                return false;
            }
        }

        return ! ($netmask !== '' && (int) $netmask > 32);
    }

    /** `Traffic\Tools\Tools::CIDRToRange()`, ported literally. */
    private function cidrToRange(string $cidr): array
    {
        [$ip, $bits] = explode('/', $cidr);
        $bits = (int) $bits;
        $min = long2ip(ip2long($ip) & (-1 << (32 - $bits)));
        $max = long2ip(ip2long($min) + (2 ** (32 - $bits)) - 1);

        return [$min, $max];
    }

    /**
     * `UserBotsService::mapItem()`, ported literally. Returns
     * `['min_ip' => int, 'max_ip' => int, 'raw_value' => string]` or `null`
     * for an unparseable item (CIDR / `ip-ip` range / bare IP, IPv4 only —
     * legacy uses `FILTER_FLAG_IPV4` throughout).
     */
    private function mapItem(string $item): ?array
    {
        $item = trim($item);
        if ($item === '') {
            return null;
        }

        if (str_contains($item, '/')) {
            if (! $this->isValidCidr($item)) {
                return null;
            }
            $range = $this->cidrToRange($item);
        } elseif (str_contains($item, '-')) {
            $range = explode('-', $item);
            if (count($range) !== 2) {
                return null;
            }
            foreach ($range as $i => $v) {
                $v = trim($v);
                if (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    return null;
                }
                $range[$i] = $v;
            }
        } else {
            if (filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                return null;
            }
            $range = [$item, $item];
        }

        return [
            'min_ip' => (int) sprintf('%u', ip2long($range[0])),
            'max_ip' => (int) sprintf('%u', ip2long($range[1])),
            'raw_value' => $item,
        ];
    }

    /**
     * `UserBotsService::_validateSingle()` + `_parseInput()`. Throws
     * (caught by the calling action, see below) exactly when legacy throws:
     * a single-line (no `\n`) submission that fails to parse. A multi-line
     * submission tolerates unparseable lines by silently dropping them
     * (via `array_filter` on the mapped list) — same as legacy.
     *
     * @return array<int, array{min_ip: int, max_ip: int, raw_value: string}>
     *
     * @throws \RuntimeException
     */
    private function parseInput(string $content): array
    {
        $content = str_replace(',', "\n", $content);

        if (! str_contains(trim($content), "\n") && $this->mapItem(trim($content)) === null) {
            throw new \RuntimeException('Invalid ip or range to add to bot list: '.$content);
        }

        $items = array_map(fn (string $line) => $this->mapItem($line), explode("\n", $content));

        return $this->prepareResultList($items);
    }

    /**
     * `UserBotsService::cmpArray()` + `_prepareResultList()`: dedupe exact
     * duplicates, drop unparseable (`null`) entries, sort by `min_ip`, then
     * merge overlapping ranges.
     *
     * @param  array<int, array{min_ip: int, max_ip: int, raw_value: string}|null>  $items
     * @return array<int, array{min_ip: int, max_ip: int, raw_value: string}>
     */
    private function prepareResultList(array $items): array
    {
        $items = array_values(array_unique($items, SORT_REGULAR));
        $items = array_values(array_filter($items));

        if ($items === []) {
            return [];
        }

        usort($items, fn (array $a, array $b) => $a['min_ip'] <=> $b['min_ip']);

        return $this->mergeIntersectedInSortedList($items);
    }

    /** `UserBotsService::_checkIntersection()`. */
    private function intersects(array $x, array $y): bool
    {
        return $x['min_ip'] <= $y['max_ip'] && $y['min_ip'] <= $x['max_ip'];
    }

    /** `UserBotsService::_getUnion()`. */
    private function union(array $x, array $y): array
    {
        return [
            'min_ip' => min($x['min_ip'], $y['min_ip']),
            'max_ip' => max($x['max_ip'], $y['max_ip']),
            'raw_value' => '',
        ];
    }

    /**
     * `UserBotsService::_fillRawValue()` — see class docblock's
     * "IMPORTANT CORRECTNESS NOTE": this OVERWRITES `raw_value`, discarding
     * whatever text produced this range.
     */
    private function fillRawValue(array $item): array
    {
        $item['raw_value'] = $item['min_ip'] === $item['max_ip']
            ? long2ip($item['min_ip'])
            : long2ip($item['min_ip']).'-'.long2ip($item['max_ip']);

        return $item;
    }

    /**
     * `UserBotsService::_mergeIntersectedInSortedList()`, ported literally
     * (including running `fillRawValue()` over every entry, merged or not).
     *
     * @param  array<int, array{min_ip: int, max_ip: int, raw_value: string}>  $sorted  non-empty, sorted by min_ip
     */
    private function mergeIntersectedInSortedList(array $sorted): array
    {
        $result = [];
        $current = $sorted[0];

        foreach ($sorted as $interval) {
            if ($this->intersects($current, $interval)) {
                $current = $this->union($current, $interval);
            } else {
                $result[] = $this->fillRawValue($current);
                $current = $interval;
            }
        }

        $last = end($result);
        if ($last === false || $last != $current) {
            $result[] = $this->fillRawValue($current);
        }

        return $result;
    }

    /**
     * `UserBotsService::_cropRanges()`, ported literally: subtracts every
     * range in `$excludeList` from `$src`, returning 0..n leftover
     * fragments (each with `raw_value` left blank — filled in by the
     * caller via `fillRawValue()`, same as legacy).
     *
     * @param  array<int, array{min_ip: int, max_ip: int, raw_value: string}>  $excludeList
     * @return array<int, array{min_ip: int, max_ip: int, raw_value: string}>
     */
    private function cropRanges(array $src, array $excludeList): array
    {
        $result = [];
        $sourceMin = $src['min_ip'];
        $sourceMax = $src['max_ip'];

        foreach ($excludeList as $exclude) {
            if ($exclude['min_ip'] <= $sourceMin && $sourceMin <= $exclude['max_ip']) {
                $sourceMin = $exclude['max_ip'] + 1;
            } elseif ($sourceMin < $exclude['min_ip'] && $exclude['min_ip'] <= $sourceMax) {
                $result[] = ['min_ip' => $sourceMin, 'max_ip' => $exclude['min_ip'] - 1, 'raw_value' => ''];
                $sourceMin = $exclude['max_ip'] + 1;
            }

            if ($sourceMax < $sourceMin) {
                return $result;
            }
        }

        if ($sourceMin <= $sourceMax) {
            $result[] = ['min_ip' => $sourceMin, 'max_ip' => $sourceMax, 'raw_value' => ''];
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // Storage helpers (`MysqlStorage`, ported literally: full
    // delete-then-reinsert, not an incremental diff).
    // ---------------------------------------------------------------

    /** @return array<int, array{min_ip: int, max_ip: int, raw_value: string}> */
    private function allStoredItems(): array
    {
        return UserBotIp::query()
            ->orderBy('min_ip')
            ->get(['min_ip', 'max_ip', 'raw_value'])
            ->map(fn (UserBotIp $row) => [
                'min_ip' => $row->min_ip,
                'max_ip' => $row->max_ip,
                'raw_value' => $row->raw_value,
            ])
            ->all();
    }

    /**
     * `MysqlStorage::saveList()`: delete every existing row, then reinsert
     * the whole given list, inside one transaction.
     *
     * @param  array<int, array{min_ip: int, max_ip: int, raw_value: string}>  $items
     */
    private function storeItems(array $items): void
    {
        DB::transaction(function () use ($items) {
            UserBotIp::query()->delete();
            foreach ($items as $item) {
                UserBotIp::query()->create($item);
            }
        });
    }

    // ---------------------------------------------------------------
    // IP-list actions.
    // ---------------------------------------------------------------

    public function getBotListCountAction(Request $request): array
    {
        return ['count' => UserBotIp::query()->count()];
    }

    /** `UserBotsRepository::getList()`: newline-joined `raw_value`s, in `min_ip` order. */
    public function getBotListAction(Request $request): array
    {
        return ['value' => UserBotIp::query()->orderBy('min_ip')->pluck('raw_value')->implode("\n")];
    }

    public function saveBotListAction(Request $request): array|Response
    {
        $content = trim((string) $this->postParam($request, 'value'));

        if ($content === '') {
            UserBotIp::query()->delete();

            return $this->getBotListCountAction($request);
        }

        try {
            $items = $this->parseInput($content);
        } catch (\RuntimeException $e) {
            // Legacy throws a generic `Core\Application\Exception\Error`
            // here, which falls to the catch-all 500/plain-text handler —
            // same convention as SettingsController::updateAction()/
            // GeoDbsController::updateAction()'s unknown-id case.
            return response($e->getMessage(), 500);
        }

        $this->storeItems($items);

        return $this->getBotListCountAction($request);
    }

    public function addBotListAction(Request $request): array|Response
    {
        try {
            $newItems = $this->parseInput((string) $this->postParam($request, 'value'));
        } catch (\RuntimeException $e) {
            return response($e->getMessage(), 500);
        }

        $merged = $this->prepareResultList(array_merge($newItems, $this->allStoredItems()));
        $this->storeItems($merged);

        return $this->getBotListCountAction($request);
    }

    public function excludeBotListAction(Request $request): array|Response
    {
        try {
            $excludeItems = $this->parseInput((string) $this->postParam($request, 'value'));
        } catch (\RuntimeException $e) {
            return response($e->getMessage(), 500);
        }

        $result = [];
        foreach ($this->allStoredItems() as $oldItem) {
            foreach ($this->cropRanges($oldItem, $excludeItems) as $cropped) {
                $result[] = $this->fillRawValue($cropped);
            }
        }

        $this->storeItems($result);

        return $this->getBotListCountAction($request);
    }

    /**
     * Legacy also clears the legacy flat-file list here
     * (`UserBotsLegacyRepository::clearList()`) — not ported, see class
     * docblock (no flat-file backend in this port).
     */
    public function clearBotListAction(Request $request): array
    {
        UserBotIp::query()->delete();

        return $this->getBotListCountAction($request);
    }

    // ---------------------------------------------------------------
    // Bot-signature (user-agent) actions — see class docblock for the
    // Setting-row-as-flat-text-blob storage decision.
    // ---------------------------------------------------------------

    private function signatureList(): string
    {
        return (string) (Setting::query()->find(self::BOT_SIGNATURE_SETTING_KEY)?->value ?? '');
    }

    public function getBotSignatureCountAction(Request $request): array
    {
        $list = $this->signatureList();

        return ['count' => $list === '' ? 0 : mb_substr_count($list, "\n") + 1];
    }

    public function getBotSignatureAction(Request $request): array
    {
        return ['value' => $this->signatureList()];
    }

    public function saveBotSignatureAction(Request $request): array
    {
        $content = str_replace(',', "\n", (string) $this->postParam($request, 'value'));
        $items = array_map('trim', explode("\n", $content));
        // Legacy calls `array_unique($items)` without assigning the result
        // back (a no-op bug) — see class docblock: duplicates are NOT
        // deduped in real legacy behavior, so we don't dedupe here either.
        $items = array_filter($items, fn (string $item) => $item !== '');

        Setting::query()->updateOrCreate(
            ['key' => self::BOT_SIGNATURE_SETTING_KEY],
            ['value' => implode("\n", $items)],
        );

        return $this->getBotSignatureCountAction($request);
    }
}
