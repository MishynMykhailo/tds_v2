<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Port of legacy `Component\GeoDb\Controller\IpInfoDataTypesController` +
 * `Traffic\GeoDb\IpInfoType::all()` — static list of geo/device data type
 * keys resolvable from an IP (used by UI dropdowns, e.g. GeoDb file
 * assignment). Verified against the real legacy source, not guessed.
 */
class IpInfoDataTypesController extends Controller
{
    private const TYPES = [
        'country', 'region', 'city', 'city_ru', 'isp', 'proxy_type',
        'bot_type', 'connection_type', 'operator',
    ];

    public function indexAction(Request $request): array
    {
        return self::TYPES;
    }
}
