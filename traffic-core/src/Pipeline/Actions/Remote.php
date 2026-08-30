<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\Remote` (application/Traffic/
 * Actions/Predefined/Remote.php) — `actionPayload` is a URL that returns
 * the REAL destination URL as its response body (e.g. an affiliate
 * network's dynamic redirect endpoint); this action fetches it, caches the
 * result to a file for `ttl` (60s, matches legacy `$_ttl`) to avoid
 * hitting the remote on every click, then redirects/frames/scripts to
 * whatever it returned.
 *
 * Ported faithfully: raw PHP curl (not Guzzle — legacy uses raw `curl_*`
 * here too, unlike `Curl.php`'s `CurlService`/Guzzle path; SSL verify
 * disabled, 5s timeout, `User-Agent: REMOTE`, matches legacy exactly),
 * `md5(actionPayload)`-keyed file cache, `_appendParams()`'s query-merge
 * logic (if the fetched value has no `"://"` it's treated as a bare
 * host/path and the ORIGINAL configured URL's query params are merged
 * onto it, defaulting scheme to `http`) — all literal ports.
 *
 * File cache location: `CACHE_DIR` env var, default
 * `<traffic-core root>/var/cache` (legacy: `ROOT . Cache::DEFAULT_CACHE_DIR`
 * = `/var/cache`, same idea — an app-relative writable dir, not a
 * system-wide path, since this core doesn't share legacy's cache
 * infrastructure at all — see docs/TRAFFIC_CORE_PLAN.md).
 *
 * NOT ported: `Remote::stub()` (legacy test-only static stub registry, not
 * relevant to a runtime port).
 */
class Remote extends AbstractAction
{
    private const TTL_SECONDS = 60;

    protected function executeDefault(Payload $payload): void
    {
        $url = $this->remoteUrl((string) $payload->actionPayload);
        $payload->headers['Location'] = $url;
        $payload->statusCode = 302;
    }

    protected function executeForFrame(Payload $payload): void
    {
        $url = $this->remoteUrl((string) $payload->actionPayload);
        $payload->body = RedirectService::frameRedirect($url);
    }

    protected function executeForScript(Payload $payload): void
    {
        $url = $this->remoteUrl((string) $payload->actionPayload);
        $payload->headers['Content-Type'] = 'application/javascript';
        $payload->body = RedirectService::scriptRedirect($url);
    }

    private function remoteUrl(string $from): string
    {
        $filename = $this->fileName($from);

        if (is_file($filename) && (time() - filemtime($filename)) < self::TTL_SECONDS) {
            $url = trim((string) @file_get_contents($filename));
        } else {
            $url = trim(strip_tags($this->fetch($from)));
            if ($url !== '') {
                @file_put_contents($filename, $url);
            }
        }

        if ($url !== '' && !str_contains($url, '://')) {
            $url = $this->appendParams($url, $from);
        }

        return $url;
    }

    private function fetch(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => html_entity_decode($url),
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'REMOTE',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

        return $result === false ? '' : $result;
    }

    private function fileName(string $url): string
    {
        $dir = getenv('CACHE_DIR') ?: (dirname(__DIR__, 3) . '/var/cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/' . md5($url) . '.link';
    }

    private function appendParams(string $actualUrl, string $url): string
    {
        if ($actualUrl === '') {
            return '';
        }

        $urlParts = parse_url($url) ?: [];
        parse_str($urlParts['query'] ?? '', $queryParams1);

        $actualParts = parse_url($actualUrl) ?: [];
        parse_str($actualParts['query'] ?? '', $queryParams2);

        if (!isset($actualParts['host']) && isset($actualParts['path'])) {
            $actualParts['host'] = $actualParts['path'];
            $actualParts['path'] = '/';
        }
        if (!isset($actualParts['scheme'])) {
            $actualParts['scheme'] = 'http';
        }

        $actualParts['query'] = http_build_query(array_merge($queryParams1, $queryParams2));

        $newUrl = $actualParts['scheme'] . '://' . $actualParts['host'];
        if (isset($actualParts['port'])) {
            $newUrl .= ':' . $actualParts['port'];
        }
        $newUrl .= $actualParts['path'] ?? '';
        if (($actualParts['query'] ?? '') !== '') {
            $newUrl .= '?' . $actualParts['query'];
        }

        return $newUrl;
    }
}
