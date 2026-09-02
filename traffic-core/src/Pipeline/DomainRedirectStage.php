<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;

/**
 * Port of legacy `Traffic\Pipeline\Stage\DomainRedirectStage`
 * (application/Traffic/Pipeline/Stage/DomainRedirectStage.php) — forces
 * a domain's configured scheme (e.g. redirect http -> https) before any
 * campaign resolution. Runs first in the pipeline, mirroring legacy's
 * `firstLevelStages()` order.
 *
 * `domains.redirect` (confirmed via live `DESCRIBE` on the `tds2` dev
 * DB) stores the target scheme directly as a string: `"not"` (default,
 * no redirect) / `"http"` / `"https"`. This exact reading of the column
 * is INFERRED, not verified against a directly-read legacy getter body
 * (`Domain::getSSLRedirect()`'s own source wasn't found by name in the
 * legacy tree — likely a magic/reflection-generated accessor) — but
 * corroborated by two independent legacy facts: the column's own
 * default value is the string `"not"`, and `DomainsController.php`'s
 * admin-API mapping does `$result["ssl_redirect"] = $data["redirect"]
 * === "https";` (application/Component/Domains/Controller/
 * DomainsController.php:171) — i.e. the boolean UI flag is DERIVED from
 * this same string column, not the other way around. If a real
 * `getSSLRedirect()` read ever surfaces different semantics, revisit.
 *
 * NOT ported: `_checkCloudFlareScheme()` (skips the redirect if a
 * `CF-Visitor` header says Cloudflare already terminated TLS upstream)
 * — traffic-core has no Cloudflare-specific header handling anywhere
 * else either, so this narrow carve-out was left out rather than added
 * in isolation; means a domain sitting behind Cloudflare with `redirect
 * = "https"` could see a redirect loop with Cloudflare's own SSL
 * flexible mode — a real, documented limitation, not a silent gap.
 */
class DomainRedirectStage
{
    public function process(Payload $payload): Payload
    {
        $uri = $payload->request->getUri();
        $host = $uri->getHost();

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $payload;
        }

        $stmt = Db::instance()->prepare('SELECT redirect FROM domains WHERE name = ? LIMIT 1');
        $stmt->execute([$host]);
        $targetScheme = $stmt->fetchColumn();

        if (!$targetScheme || !in_array($targetScheme, ['http', 'https'], true)) {
            return $payload;
        }

        $currentScheme = $uri->getScheme();
        if ($targetScheme === $currentScheme) {
            return $payload;
        }

        $target = (string) $uri->withScheme($targetScheme);
        $payload->statusCode = 301;
        $payload->headers['Location'] = $target;
        $payload->aborted = true;

        return $payload;
    }
}
