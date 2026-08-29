<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Port of legacy `Component\Macros\Controller\MacrosController`
 * (object=macros) + `Traffic\Macros\MacroRepository::getActiveMacroNames()`
 * — the full registered macro-name list (see MacrosProcessor fix earlier
 * this session, application/Traffic/Macros/MacroRepository.php::loadMacros()),
 * sorted and with the legacy `$_exclude` list removed, for the landing/
 * postback-URL macro-picker UI.
 *
 * TODO: legacy's `getMacroNames($type)` splits into CLICK vs CONVERSION
 * macro sets; `$type` is accepted here but not used to filter yet — the
 * full combined+deduped list is returned regardless of `$type`. Low risk
 * (UI autocomplete only, not security-sensitive) but worth revisiting.
 */
class MacrosController extends Controller
{
    /** Static names, verified against the real `loadMacros()` registrations. Dynamic sub_id_N/extra_param_N use the same defaults as the `clicks` table (15/10). */
    private const NAMES = [
        'sample', 'random', 'from_file', 'date', 'device_type', 'profit', 'revenue',
        'status', 'original_status', 'tid', 'cost', 'conversion_cost',
        'conversion_revenue', 'conversion_profit', 'campaign_name',
        'tds_landing_id', 'tds_offer_id', 'operator', 'carrier', 'connection_type',
        'city', 'country', 'ip', 'region', 'conversion_time', 'debug',
        'x_requested_with', 'subid', 'sub_id',
        'se', 'source', 'ad_campaign_id', 'external_id', 'creative_id',
        'landing_id', 'ts_id', 'offer_id', 'campaign_id', 'stream_id', 'isp',
        'parent_campaign_id', 'is_bot', 'is_using_proxy', 'search_engine',
        'browser', 'browser_version', 'os', 'os_version', 'language',
        'user_agent', 'device_model', 'device_brand', 'destination', 'token',
        'visitor_id', 'keyword', 'offer', 'current_domain',
        'traffic_source_name', 'visitor_code',
    ];

    /** Legacy `MacroRepository::$_exclude`. */
    private const EXCLUDE = [
        'group_id', 'country_code', 'referer', 'ua', 'example', 'keyword_cp1251',
        'tds_campaign_id', 'tds_campaign_name', 'country_name', 'region_name',
        'se', 'useragent',
    ];

    public function macrosAction(Request $request): array
    {
        $names = array_merge(
            self::NAMES,
            array_map(fn (int $i) => "sub_id_{$i}", range(1, 15)),
            array_map(fn (int $i) => "extra_param_{$i}", range(1, 10)),
        );

        $names = array_values(array_diff(array_unique($names), self::EXCLUDE));
        sort($names);

        return $names;
    }
}
