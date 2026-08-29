<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Compatibility port of the legacy
 * `Component\StreamFilters\Controller\FiltersController` +
 * `Component\StreamFilters\Repository\FilterRepository::getFiltersAsOptions()`
 * (old codebase: application/Component/StreamFilters/Repository/FilterRepository.php
 * + application/Component/StreamFilters/Filter/*.php, one class per filter
 * type, all extending Core\Filter\AbstractFilter).
 *
 * `object=streamFilters` is deliberately NOT a CRUD module (see
 * docs/legacy-reference/frontend/api/10.2_streams.md, "StreamFilters"): the
 * only action is `filters`, returning a STATIC catalogue of the filter
 * *types* available to the stream condition-builder UI. Actual filter
 * *instances* are `stream_filters` rows (App\Models\StreamFilter),
 * persisted/serialized by StreamsController (see
 * StreamsController::assignStreamFilters()/serializeStreamFilter()) exactly
 * as legacy `Traffic\Model\StreamFilter` rows are persisted via
 * `Component\StreamFilters\Service\StreamFilterService`, which this
 * controller has no involvement in either (legacy `FilterRepository` is a
 * pure catalogue, `StreamFilterService` is a separate class).
 *
 * Each catalogue entry mirrors legacy `AbstractFilter::getInfo()`:
 *   value            filter type key (== stream_filters.name); always equal
 *                     to the FilterRepository registration key (verified
 *                     against `Tools::fromCamelCase(Tools::demodulize(...))`
 *                     for every non-override class in the old codebase)
 *   tooltip          translated (English) helper text, or null
 *   modes            {accept:label, reject:label} (binary "IS"/"IS NOT" by
 *                     default), a filter-specific binary map (e.g.
 *                     black/white for the AV-detection filters), or null
 *                     (the Limit filter has no accept/reject toggle)
 *   group            RAW i18n key, e.g. "filters.groups.geo" — legacy never
 *                     translates this field server-side, the old Angular
 *                     frontend does; kept as-is for contract fidelity
 *   template         legacy Angular directive markup, kept verbatim
 *   header_template  ditto, null for most filters
 *   defaults         filter-specific default payload, or null
 *
 * Deliberately NOT ported (see also class-level TODOs below):
 * - dynamic sub_id/extra_param filter counts: legacy derives these from
 *   `ParameterRepository::getSubIdCount()` (10, or 15 if a `sub_id_15_id`
 *   column exists on the old `clicks` table) and `getExtraParamCount()` (0
 *   unless a `show_extra_param` setting is enabled) — hardcoded here to the
 *   legacy DEFAULTS (10 sub_id filters, 0 extra_param filters), since
 *   neither the schema-introspection nor the settings module those depend
 *   on has been ported;
 * - Isp/ImkloDetect/HideClickDetect conditionally swap `template` for a
 *   "not configured" string depending on GeoDb/Settings availability
 *   (GeoDb module not ported) — this port always returns the
 *   integration-enabled template;
 * - custom filters loaded from `application/filters/*.php` at runtime —
 *   the only file present in the old install (`example.php`) is excluded
 *   by legacy itself (`FilterRepository::$_exclude`), so nothing is lost.
 */
class StreamFiltersController extends Controller
{
    private const BINARY_MODES = ['accept' => 'IS', 'reject' => 'IS NOT'];

    /** Legacy alias names (`FilterRepository::alias()`) — not real filter types, both resolve to "uniqueness". */
    public const ALIASES = ['uniqueness_cookie', 'uniqueness_ip'];

    public function filtersAction(Request $request): array
    {
        return array_values(array_map(
            fn (array $def) => $this->entry(...$def),
            $this->definitions()
        ));
    }

    /**
     * The full valid `stream_filters.name` list (catalogue keys + the two
     * legacy aliases) — mirrors `FilterRepository::getFilterNames()`.
     * Consumed by StreamsController::validateFilterParams() so the two
     * controllers can't drift apart on what counts as a valid filter name.
     */
    public static function validNames(): array
    {
        $names = array_column((new self())->definitions(), 'value');
        $names = array_merge($names, self::ALIASES);
        sort($names);

        return $names;
    }

    /**
     * Ordered definitions, one per registered legacy filter (in
     * `FilterRepository::loadFilters()` registration order). Each maps
     * directly to `$this->entry(...)`'s named parameters.
     *
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        $definitions = [
            ['value' => 'interval', 'group' => 'filters.groups.time', 'modes' => ['accept' => 'YES', 'reject' => 'NO']],
            ['value' => 'limit', 'modes' => null],
            ['value' => 'parameter', 'group' => 'filters.groups.parameters'],
            ['value' => 'proxy', 'group' => 'filters.groups.geo', 'template' => ''],
            [
                'value' => 'referrer',
                'group' => 'filters.groups.parameters',
                'tooltip' => 'Examples: http://site.com/, *site.com*, /site-(a|b|c)\.com/, @empty',
                'template' => "<stream-multi-value-input\n         ng-model=\"filter.payload\"\n         field-name=\"'referrer'\"\n         ></stream-multi-value-input>",
            ],
            [
                'value' => 'schedule',
                'group' => 'filters.groups.time',
                'headerTemplate' => "\n          <timezone-select ng-model=\"filter.payload.timezone\"></timezone-select>\n        ",
            ],
            [
                'value' => 'uniqueness',
                'group' => 'filters.groups.device',
                'headerTemplate' => "<select class=\"form-control\" ng-model=\"filter.payload\">\n"
                    ."        <option value=\"stream\">for this stream</option>\n"
                    ."        <option value=\"campaign\">in this campaign</option>\n"
                    ."        <option value=\"global\">for all traffic</option>\n"
                    .'        </select>',
                'defaults' => 'stream',
            ],
        ];

        // Per-parameter filters (Filter\AnyParam) — group "parameters".
        foreach (['source', 'x_requested_with', 'keyword', 'search_engine', 'ad_campaign_id', 'creative_id'] as $param) {
            $definitions[] = $this->anyParamDefinition($param, 'parameters');
        }

        // Filter\AnyParam over sub_id_1.._N — legacy default getSubIdCount() == 10.
        for ($i = 1; $i <= 10; $i++) {
            $definitions[] = $this->anyParamDefinition("sub_id_{$i}", 'sub_ids');
        }
        // extra_param_1..10 intentionally omitted — legacy default getExtraParamCount() == 0.

        $definitions = array_merge($definitions, [
            ['value' => 'bot', 'group' => 'filters.groups.device'],
            ['value' => 'city', 'group' => 'filters.groups.geo'],
            ['value' => 'region', 'group' => 'filters.groups.geo'],
            ['value' => 'country', 'group' => 'filters.groups.geo'],
            ['value' => 'connection_type', 'group' => 'filters.groups.device'],
            ['value' => 'empty_referrer', 'group' => 'filters.groups.parameters', 'template' => ''],
            [
                'value' => 'ip',
                'group' => 'filters.groups.geo',
                'tooltip' => 'Examples: 1.2.3.4, 22.33.0-20.*, 22.33.1.0/24, 22.33.44.10-22.33.44.20',
                'template' => "<stream-multi-value-input ng-model=\"filter.payload\" separators=\"[',',';']\" field-name=\"'ip'\"></stream-multi-value-input>",
            ],
            ['value' => 'ipv_6', 'group' => 'filters.groups.geo', 'template' => ''],
            [
                'value' => 'operator',
                'group' => 'filters.groups.geo',
                'tooltip' => 'Start typing to see suggestions or enter a country code',
                'template' => '<stream-operator-filter ng-model="filter.payload"></stream-operator-filter>',
            ],
            ['value' => 'isp', 'group' => 'filters.groups.geo'],
            ['value' => 'browser', 'group' => 'filters.groups.device'],
            ['value' => 'browser_version', 'group' => 'filters.groups.device', 'template' => '<app-version-filter ng-model="filter.payload"></app-version-filter>'],
            ['value' => 'device_model', 'group' => 'filters.groups.device'],
            ['value' => 'device_type', 'group' => 'filters.groups.device'],
            ['value' => 'os', 'group' => 'filters.groups.device'],
            ['value' => 'os_version', 'group' => 'filters.groups.device', 'template' => '<app-version-filter ng-model="filter.payload"></app-version-filter>'],
            [
                'value' => 'user_agent',
                'group' => 'filters.groups.device',
                'template' => "<stream-multi-value-input ng-model=\"filter.payload\" separators=\"['##']\" \n        field-name=\"'user_agent'\"></stream-multi-value-input>",
            ],
            ['value' => 'language', 'group' => 'filters.groups.device'],
            ['value' => 'imklo_detect', 'group' => 'filters.groups.geo', 'modes' => ['black' => 'Black', 'white' => 'White']],
            ['value' => 'hide_click_detect', 'group' => 'filters.groups.geo', 'modes' => ['black' => 'Black', 'white' => 'White']],
        ]);

        return $definitions;
    }

    private function anyParamDefinition(string $param, string $group): array
    {
        return [
            'value' => $param,
            'group' => "filters.groups.{$group}",
            'tooltip' => 'Regular expressions available. Example: /value (a|b|c)/',
            'template' => "<stream-multi-value-input ng-model=\"filter.payload\" field-name=\"'{$param}'\"></stream-multi-value-input>",
        ];
    }

    private function entry(
        string $value,
        ?string $group = null,
        array|null $modes = self::BINARY_MODES,
        ?string $tooltip = null,
        ?string $template = null,
        ?string $headerTemplate = null,
        mixed $defaults = null,
    ): array {
        return [
            'value' => $value,
            'tooltip' => $tooltip,
            'modes' => $modes,
            'group' => $group ?? 'filters.groups.other',
            'template' => $template ?? $this->defaultTemplate($value),
            'header_template' => $headerTemplate,
            'defaults' => $defaults,
        ];
    }

    /** Mirrors `AbstractFilter::getTemplate()` (the non-overridden default). */
    private function defaultTemplate(string $key): string
    {
        $dashed = str_replace('_', '-', $key);

        return "<stream-{$dashed}-filter ng-model=\"filter.payload\"></stream-{$dashed}-filter>";
    }
}
