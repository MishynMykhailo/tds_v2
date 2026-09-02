<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;

/**
 * Port of legacy `Traffic\Pipeline\Stage\CheckPrefetchStage`
 * (application/Traffic/Pipeline/Stage/CheckPrefetchStage.php) — blocks
 * browser link-prefetch requests from being counted as real clicks when
 * the `ingore_prefetch` setting is on (that's legacy's actual setting
 * key — a typo, kept verbatim for consistency with any existing
 * settings row/admin-UI binding, not fixed here).
 *
 * Detection ported literally: any of the `X-PURPOSE: preview` /
 * `X-MOZ: prefetch` / `X-FB-HTTP-ENGINE: Liger` headers, OR both a
 * `version` AND `prefetch` query param present at once.
 */
class CheckPrefetchStage
{
    private const HEADER_MATCHES = [
        'X-PURPOSE' => 'preview',
        'X-MOZ' => 'prefetch',
        'X-FB-HTTP-ENGINE' => 'Liger',
    ];

    public function process(Payload $payload): Payload
    {
        $stmt = Db::instance()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute(['ingore_prefetch']);
        $enabled = $stmt->fetchColumn();

        if (!$enabled || !filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            return $payload;
        }

        $params = $payload->request->getQueryParams();
        $isPrefetch = !empty($params['version']) && !empty($params['prefetch']);

        foreach (self::HEADER_MATCHES as $name => $value) {
            if ($payload->request->getHeaderLine($name) === $value) {
                $isPrefetch = true;
                break;
            }
        }

        if ($isPrefetch) {
            $payload->abort(403, 'Prefetch not allowed');
        }

        return $payload;
    }
}
