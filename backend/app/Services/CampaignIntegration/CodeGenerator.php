<?php

namespace App\Services\CampaignIntegration;

use Illuminate\Support\Str;

/**
 * Port of legacy `Component\CampaignIntegration\KClientJS\CodeGenerator`
 * — ONLY `getCode()` (application/Component/CampaignIntegration/KClientJS/
 * CodeGenerator.php). `generateClientCode()` is deliberately NOT ported —
 * it belongs to the click-serving runtime (`traffic-core/`), out of scope
 * until that backend is built (see docs/PORTING_LOG.md).
 *
 * DOC/REALITY MISMATCH found while porting: `getCode()` computes
 * `$urlPostback` via `NetworkTemplatesRepository::getSecret()` and puts it
 * in the `$replaces` map under the key `"{postback_url}"` — but the actual
 * `$code` template string literal in the legacy class does NOT contain the
 * substring `"{postback_url}"` anywhere (confirmed with
 * `grep -c postback_url CodeGenerator.php` against the old source: the only
 * occurrence is the `$replaces` array key itself, never in `$code`). The
 * secret lookup is genuinely dead code for this output — skipped entirely
 * here rather than building a new secret/config mechanism for a value that
 * is computed but never emitted.
 */
class CodeGenerator
{
    /**
     * Verbatim port of the legacy `$code` template string (same macro
     * placeholders: {KTracking}, {unique}, {ttl}, {campaignUrl},
     * {configStorage}, {config}, {now}, {subId}, {token}, {params} — all
     * replaced below with randomized identifiers to avoid global-scope
     * collisions when multiple snippets are embedded on one page).
     */
    private string $code = "\n    (function() {\n    var name = '{KTracking}';\n    if (!window.{KTracking}) {\n        window.{KTracking} = {\n            unique: {unique},\n            ttl: {ttl},\n            R_PATH: '{campaignUrl}',\n        };\n    }\n    const {configStorage} = localStorage.getItem('config');\n    if (typeof {configStorage} !== 'undefined' && {configStorage} !== null) {\n        var {config} = JSON.parse({configStorage});\n        var {now} = Math.round(+new Date()/1000);\n        if ({config}.created_at + window.{KTracking}.ttl < {now}) {\n            localStorage.removeItem('subId');\n            localStorage.removeItem('token');\n            localStorage.removeItem('config');\n        }\n    }\n    var {subId} = localStorage.getItem('subId');\n    var {token} = localStorage.getItem('token');\n    var {params} = '?return=js.client';\n        {params} += '&' + decodeURIComponent(window.location.search.replace('?', ''));\n        {params} += '&se_referrer=' + encodeURIComponent(document.referrer);\n        {params} += '&default_keyword=' + encodeURIComponent(document.title);\n        {params} += '&landing_url=' + encodeURIComponent(document.location.hostname + document.location.pathname);\n        {params} += '&name=' + encodeURIComponent(name);\n        {params} += '&host=' + encodeURIComponent(window.{KTracking}.R_PATH);\n    if (typeof {subId} !== 'undefined' && {subId} && window.{KTracking}.unique) {\n        {params} += '&sub_id=' + encodeURIComponent({subId});\n    }\n    if (typeof {token} !== 'undefined' && {token} && window.{KTracking}.unique) {\n        {params} += '&token=' + encodeURIComponent({token});\n    }\n    var a = document.createElement('script');\n        a.type = 'application/javascript';\n        a.src = window.{KTracking}.R_PATH + {params};\n    var s = document.getElementsByTagName('script')[0];\n    s.parentNode.insertBefore(a, s)\n    })();\n    ";

    public function getCode(KClientJsSettings $settings): string
    {
        $replaces = [
            '{campaignUrl}' => $settings->getUrl(),
            '{campaignHost}' => $settings->getHost(),
            '{unique}' => $settings->getUnique(),
            '{ttl}' => $settings->getCookiesTTL(),
            '{subId}' => '_'.Str::random(16),
            '{token}' => '_'.Str::random(16),
            '{params}' => '_'.Str::random(16),
            '{KTracking}' => '_'.Str::random(16),
            '{configStorage}' => '_'.Str::random(16),
            '{config}' => '_'.Str::random(16),
            '{now}' => '_'.Str::random(16),
        ];

        // Legacy uses `preg_replace("/" . $key . "/", ...)` (the curly-brace
        // keys are passed straight in as a regex pattern); ported with
        // `str_replace()` instead, which is behaviorally identical for
        // these fixed literal keys and avoids relying on how a given PCRE
        // build happens to parse a bare, non-quantifier `{...}` pattern.
        $code = str_replace(array_keys($replaces), array_values($replaces), $this->code);

        if ($settings->getBase()) {
            return '<script src="data:text/javascript;base64,'.base64_encode($code).'"></script>';
        }

        return '<script>'.$code.'</script>';
    }
}
