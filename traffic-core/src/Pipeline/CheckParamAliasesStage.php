<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;

/**
 * Port of legacy `Traffic\Pipeline\Stage\CheckParamAliasesStage`
 * (application/Traffic/Pipeline/Stage/CheckParamAliasesStage.php) — lets
 * a campaign/global config accept a click-tracking param under a
 * DIFFERENT query-string name than traffic-core's own canonical one
 * (e.g. an ad network sends `?kw=...` but the canonical field is
 * `keyword`).
 *
 * Architectural adaptation, not a fidelity cut: legacy mutates a
 * partially-built `RawClick` object in place, since its
 * `BuildRawClickStage` runs BEFORE this stage and re-reads/overwrites
 * fields on the same mutable object. traffic-core's `BuildRawClickStage`
 * runs once, late, straight from the request — so instead this stage
 * writes resolved canonical values to `payload->resolvedParams`, which
 * `BuildRawClickStage` now consults before the request's own params for
 * every aliasable field (see that class). Same net effect: an aliased
 * param wins over a same-named-but-absent canonical one.
 *
 * Ported: global aliases via the `<param>_aliases` setting (comma
 * -separated list, e.g. `settings['keyword_aliases'] = 'kw,q'` — legacy's
 * `ParameterRepository::getValue()` also falls back to a static
 * `ConfigService`/defaults layer for a handful of params; NOT ported
 * here, this project has no equivalent static defaults file, so an
 * unconfigured param simply has no aliases, which is the correct
 * behavior for a fresh install anyway); per-campaign aliases +
 * placeholder defaults via `campaigns.parameters` (JSON: `{paramName:
 * {name?: aliasName, placeholder?: defaultValue}}`); the `site` ->
 * `source` shortcut alias.
 *
 * NOT ported: `_isMacro()`'s exact bracket-detection semantics for
 * placeholders are ported literally (a placeholder starting with `[` or
 * `{` is treated as an un-substituted macro and skipped) but
 * `processMacros()` itself — which would actually resolve such macros —
 * remains the same project-wide gap documented in
 * `ExecuteActionStage`'s docblock.
 */
class CheckParamAliasesStage
{
    private const ALIASABLE_PARAMS = [
        'se_referrer', 'source', 'keyword', 'se', 'landing_id', 'creative_id',
        'ad_campaign_id', 'external_id', 'cost', 'currency',
        'sub_id_1', 'sub_id_2', 'sub_id_3', 'sub_id_4', 'sub_id_5',
        'sub_id_6', 'sub_id_7', 'sub_id_8', 'sub_id_9', 'sub_id_10',
        'sub_id_11', 'sub_id_12', 'sub_id_13', 'sub_id_14', 'sub_id_15',
        'extra_param_1', 'extra_param_2', 'extra_param_3', 'extra_param_4',
        'extra_param_5', 'extra_param_6', 'extra_param_7', 'extra_param_8',
        'extra_param_9', 'extra_param_10',
    ];

    public function process(Payload $payload): Payload
    {
        if ($payload->campaign === null) {
            return $payload;
        }

        $params = $payload->signal['params'] ?? [];

        $this->checkAliasesFromSettings($params, $payload);

        $parameters = $this->campaignParameters($payload->campaign);
        $this->checkAliasesFromCampaign($params, $payload, $parameters);
        $this->checkSiteAlias($params, $payload);
        $this->checkPlaceholderFromCampaign($params, $payload, $parameters);

        return $payload;
    }

    private function checkAliasesFromSettings(array $params, Payload $payload): void
    {
        foreach (self::ALIASABLE_PARAMS as $paramName) {
            if (isset($params[$paramName])) {
                continue;
            }

            foreach ($this->aliasesFor($paramName) as $alias) {
                if ($alias !== $paramName && isset($params[$alias])) {
                    $payload->resolvedParams[$paramName] = (string) $params[$alias];
                    break;
                }
            }
        }
    }

    /** @return list<string> */
    private function aliasesFor(string $paramName): array
    {
        $stmt = Db::instance()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$paramName . '_aliases']);
        $value = $stmt->fetchColumn();

        if (!$value) {
            return [];
        }

        return array_filter(array_map('trim', explode(',', (string) $value)));
    }

    /** @return array<string,array{name?:string,placeholder?:string}> */
    private function campaignParameters(array $campaign): array
    {
        $raw = $campaign['parameters'] ?? null;
        if (!$raw) {
            return [];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? $decoded : [];
    }

    private function checkAliasesFromCampaign(array $params, Payload $payload, array $parameters): void
    {
        foreach ($parameters as $paramName => $config) {
            $alias = $config['name'] ?? $paramName;
            if ($alias !== $paramName && isset($params[$alias]) && !isset($payload->resolvedParams[$paramName])) {
                $payload->resolvedParams[$paramName] = (string) $params[$alias];
            }
        }
    }

    private function checkSiteAlias(array $params, Payload $payload): void
    {
        if (isset($params['site'])) {
            $payload->resolvedParams['source'] = (string) $params['site'];
        }
    }

    private function checkPlaceholderFromCampaign(array $params, Payload $payload, array $parameters): void
    {
        foreach ($parameters as $paramName => $config) {
            $placeholder = $config['placeholder'] ?? null;
            if ($placeholder === null || $placeholder === '') {
                continue;
            }

            $alias = $config['name'] ?? $paramName;
            $hasValue = isset($params[$alias]) || isset($params[$paramName]) || isset($payload->resolvedParams[$paramName]);

            if (!$hasValue && !$this->isMacro($placeholder)) {
                $payload->resolvedParams[$paramName] = (string) $placeholder;
            }
        }
    }

    private function isMacro(string $value): bool
    {
        return in_array(substr(trim($value), 0, 1), ['[', '{'], true);
    }
}
