<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Macros\ClickMacroValues;
use TrafficCore\Macros\MacrosProcessor;
use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\Curl` (application/Traffic/
 * Actions/Predefined/Curl.php) + `Traffic\Actions\CurlService::request()`
 * (application/Traffic/Actions/CurlService.php) — fetches `actionPayload`
 * server-side and returns the fetched body/content-type directly to the
 * visitor (proxy-style, e.g. tracking-pixel forwarding). Bypasses the
 * `frm` context mechanism in legacy too, so this does not extend
 * `AbstractAction`.
 *
 * Ported: real HTTP fetch (PHP curl, no Guzzle dependency in this lean
 * core — same 10s timeout as legacy's `CurlService::TIMEOUT`), UA/Referer
 * forwarding, binary (image/pdf) responses base64-encoded, `utf8ize()`
 * (literal port of `Traffic\Tools\Tools::utf8ize()`) on text responses.
 *
 * Phase 14: `processMacros()` on the fetched body is now real (see
 * `TrafficCore\Macros\MacrosProcessor`).
 *
 * NOT ported: `CurlService::adaptAnchors()`/`addBasePath()`/`adaptResourcePaths()`/
 * `adaptFormAction()` — confirmed by reading the real source that
 * `CurlService::request()` (what `Curl::_execute()` actually calls) never
 * calls any of those; they're only used by `Component\Landings\LocalFile\
 * PageWrapper` (the separately-deferred `local_file` action's runtime
 * engine), not by this action, so correctly excluded, not a gap.
 * `ConfigService::isDemo()`'s stubbed response is dev/demo-mode-only, not
 * needed for a real deployment.
 *
 * `referrer` here comes from the stream's decoded `action_options` (best
 * match for legacy's `$payload->getActionOption("referrer")` — a
 * single-key accessor traffic-core's trimmed `Payload` doesn't have; its
 * `actionOptions` holds the same underlying JSON `action_options` column),
 * falling back to empty if absent/undecodable.
 */
class Curl implements ActionHandler
{
    private const TIMEOUT = 10;

    public function execute(Payload $payload): void
    {
        $url = trim((string) $payload->actionPayload);

        $options = json_decode((string) $payload->actionOptions, true);
        $referrer = is_array($options) ? ($options['referrer'] ?? '') : '';
        $userAgent = $payload->signal['userAgent'] ?? '';

        $headers = [];
        if ($referrer !== '') {
            $headers[] = 'Referer: ' . $referrer;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $userAgent !== '' ? $userAgent : 'TrafficCore-Curl',
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            $payload->body = 'Oops! Something went wrong on the requesting page';
            return;
        }

        if ($contentType !== '') {
            $payload->headers['Content-Type'] = $contentType;
        }

        if ($body === '') {
            return;
        }

        if (str_contains($contentType, 'image') || str_contains($contentType, 'application/pdf')) {
            $content = base64_encode($body);
        } else {
            $content = self::utf8ize($body);
            $content = MacrosProcessor::process($content, ClickMacroValues::forPayload($payload));
        }

        $payload->body = $content;
    }

    private static function utf8ize(string $value): string
    {
        if (mb_detect_encoding($value, ['UTF-8'], true) !== false) {
            return $value;
        }
        if (mb_detect_encoding($value, ['WINDOWS-1251'], true) !== false) {
            return mb_convert_encoding($value, 'UTF-8', 'WINDOWS-1251');
        }

        return mb_convert_encoding($value, 'UTF-8');
    }
}
