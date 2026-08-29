<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Port of legacy `Component\Users\Controller\ResourceController`
 * (object=resource) + `AclResourceRepository::_fillMandatory()`/
 * `_fillComplementary()` (application/Component/Users/Repository/
 * AclResourceRepository.php) — static lists of ACL "resource" names (admin
 * menu sections) used by the permissions-editing UI. `mandatory` = always
 * available to every user regardless of ACL; `complementaryAsOptions` =
 * optional sections an admin can grant/revoke per user.
 */
class ResourceController extends Controller
{
    /** Verified against the real legacy source, not guessed. */
    private const MANDATORY = [
        'home', 'profile', 'status', 'search', 'macros', 'diagnostics',
        'codePresets', 'dics', 'userPreferences', 'favourite_streams',
        'streamActions', 'editor', 'tpimandatory', 'kClientJSPreset',
    ];

    private const COMPLEMENTARY = [
        'affiliate_networks', 'archive', 'campaigns', 'cleaner', 'clicks',
        'conversions', 'dashboard', 'geo_profiles', 'landings', 'migrations',
        'offers', 'reports', 'streams', 'traffic_sources', 'trends',
        'groups', 'labels', 'domains', 'api_keys', 'geo_dbs', 'integrations',
    ];

    public function mandatoryAction(Request $request): array
    {
        return self::MANDATORY;
    }

    public function complementaryAsOptionsAction(Request $request): array
    {
        // Legacy `name` is a translated label (LocaleService::t(), falls
        // back to "<resource>.title"). No i18n/translation module ported
        // yet, so `name` is the raw resource key instead of a human
        // translated string — TODO once i18n exists.
        return array_values(array_map(
            fn (string $resource) => ['name' => $resource, 'value' => $resource],
            self::COMPLEMENTARY
        ));
    }
}
