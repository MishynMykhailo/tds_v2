<?php

namespace TrafficCore\Postback;

use TrafficCore\Db;

/**
 * Port of legacy `Component\Postback\ProcessPostback\Stages\
 * SendPostbacksStage` (application/Component/Postback/ProcessPostback/
 * Stages/SendPostbacksStage.php) — after a conversion is saved, fires the
 * S2S postback(s) configured for its campaign: (a) the campaign's traffic
 * source (`traffic_sources.postback_url`/`postback_statuses`), and (b)
 * any `campaign_postbacks` rows for that campaign (new table, see the
 * migration this task adds — legacy's `CampaignPostbackRepository` reads
 * a table that doesn't exist yet in `tds2`).
 *
 * `postback_statuses` / `campaign_postbacks.statuses` serialization:
 * confirmed live (not guessed) by reading `backend/app/Http/Controllers/
 * Admin/TrafficSourcesController.php`'s `decodeJsonField()`/
 * `encodeJsonFieldForWrite()` and its docblock at ~line 153 —
 * `traffic_sources.postback_statuses` is a VARCHAR column holding a JSON
 * array STRING, e.g. `["sale","lead","rejected","rebill"]`, decoded/
 * encoded by the controller rather than via an Eloquent cast. This class
 * (and the `campaign_postbacks` migration) uses the exact same
 * convention for `statuses` so both sources of postback config are read
 * identically by `matchesStatus()`.
 *
 * URL macros: legacy pipes the postback URL through the full
 * `Traffic\Macros\MacrosProcessor` (project-wide unported gap — see
 * `TrafficCore\Pipeline\ExecuteActionStage`'s docblock for the same note
 * made once project-wide). Per task, only a minimal literal
 * `str_replace()` for the 5 macros confirmed as real, registered macro
 * names actually usable in postback URLs (`Traffic\Macros\MacroRepository
 * ::loadMacros()`, application/Traffic/Macros/MacroRepository.php:
 * `subid`/aliased as `sub_id`, `status`, `tid`, `cost`, `revenue` — all
 * `AbstractConversionMacro`s) is implemented: `{sub_id}`, `{status}`,
 * `{tid}`, `{cost}`, `{revenue}`. No other macro syntax (`$macro`,
 * `{macro:args}`, click-side macros, custom campaign parameters) is
 * substituted.
 *
 * HTTP send: legacy `_httpSend()` wraps Guzzle
 * (`Traffic\Http\Service\HttpService`) — this project has no HTTP client
 * dependency (confirmed via `composer.json`), so this uses raw PHP curl
 * directly, matching the pattern already established by
 * `TrafficCore\Pipeline\Actions\Remote`'s `fetch()`. Timeout: `settings.
 * s2s_timeout` (default 5s), clamped 1-15s — literal port of legacy's
 * `min($timeout, 15); max($timeout, 1)` clamp in `_httpSend()`.
 *
 * **Best-effort, non-blocking**: `sendFor()` is the only public entry
 * point and NEVER throws — every failure (bad campaign, bad JSON in
 * `postback_statuses`/`statuses`, curl error, DNS failure, timeout) is
 * caught and logged via `error_log()`, never allowed to affect the
 * incoming postback's own HTTP response. This is what the task calls for
 * explicitly ("wrap the whole outbound-send step in try/catch,
 * log-and-continue on failure").
 *
 * NOT ported: `Payload::getRawClick()`/`getStream()`/full
 * `SandboxContext` construction that legacy's `MacrosProcessor::process()`
 * needs for click-side/stream-side macros — moot here since only the 5
 * literal conversion macros above are supported.
 */
class OutboundPostbackService
{
    private const DEFAULT_TIMEOUT = 5;
    private const MIN_TIMEOUT = 1;
    private const MAX_TIMEOUT = 15;

    public function sendFor(PostbackResult $result): void
    {
        try {
            $this->send($result);
        } catch (\Throwable $e) {
            error_log('[postback][s2s] outbound send failed: ' . $e->getMessage());
        }
    }

    private function send(PostbackResult $result): void
    {
        if ($result->campaignId === null) {
            return;
        }

        $campaign = $this->findCampaign($result->campaignId);
        if ($campaign === null) {
            error_log("[postback][s2s] campaign #{$result->campaignId} not found");

            return;
        }

        $timeout = $this->resolveTimeout();

        $trafficSourceId = (int) ($campaign['traffic_source_id'] ?? 0);
        if ($trafficSourceId > 0) {
            $trafficSource = $this->findTrafficSource($trafficSourceId);
            if ($trafficSource !== null && !empty($trafficSource['postback_url'])) {
                $statuses = $this->decodeStatuses($trafficSource['postback_statuses'] ?? null);
                if ($this->matchesStatus($statuses, $result->status)) {
                    $this->fire((string) $trafficSource['postback_url'], 'GET', $result, $timeout);
                }
            }
        }

        foreach ($this->findCampaignPostbacks($result->campaignId) as $postback) {
            $statuses = $this->decodeStatuses($postback['statuses'] ?? null);
            if ($this->matchesStatus($statuses, $result->status)) {
                $method = strtoupper((string) ($postback['method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET';
                $this->fire((string) $postback['url'], $method, $result, $timeout);
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function findCampaign(int $campaignId): ?array
    {
        $stmt = Db::instance()->prepare('SELECT id, traffic_source_id FROM campaigns WHERE id = ? LIMIT 1');
        $stmt->execute([$campaignId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function findTrafficSource(int $id): ?array
    {
        $stmt = Db::instance()->prepare('SELECT postback_url, postback_statuses FROM traffic_sources WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    private function findCampaignPostbacks(int $campaignId): array
    {
        $stmt = Db::instance()->prepare('SELECT url, method, statuses FROM campaign_postbacks WHERE campaign_id = ?');
        $stmt->execute([$campaignId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<string> */
    private function decodeStatuses(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Tolerate a plain comma-separated string too, in case a row
            // was written by hand rather than through the JSON convention.
            return array_map('trim', explode(',', $raw));
        }

        return array_values(array_map('strval', $decoded));
    }

    /** @param list<string> $statuses */
    private function matchesStatus(array $statuses, string $status): bool
    {
        return in_array($status, $statuses, true);
    }

    private function fire(string $url, string $method, PostbackResult $result, int $timeout): void
    {
        $url = $this->substituteMacros($url, $result);

        $ch = curl_init();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = '';
        } else {
            $options[CURLOPT_URL] = $url;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("[postback][s2s] send failed \"{$url}\": {$error}");
        } else {
            error_log("[postback][s2s] sent \"{$url}\" (" . strlen((string) $response) . ' bytes response)');
        }
    }

    private function substituteMacros(string $url, PostbackResult $result): string
    {
        $replacements = [
            '{sub_id}' => (string) $result->subId,
            '{status}' => $result->status,
            '{tid}' => (string) $result->tid,
            '{cost}' => (string) $result->cost,
            '{revenue}' => (string) $result->revenue,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $url);
    }

    private function resolveTimeout(): int
    {
        $stmt = Db::instance()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute(['s2s_timeout']);
        $value = $stmt->fetchColumn();

        $timeout = ($value === false || $value === null || $value === '' || !is_numeric($value))
            ? self::DEFAULT_TIMEOUT
            : (int) $value;

        $timeout = min($timeout, self::MAX_TIMEOUT);
        $timeout = max($timeout, self::MIN_TIMEOUT);

        return $timeout;
    }
}
