<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Compatibility port of the legacy
 * `Component\Settings\Controller\DicsController` +
 * `Core\Currency\Repository\CurrenciesRepository::getCurrencies()` (old
 * codebase: application/Component/Settings/Controller/DicsController.php,
 * application/Core/Currency/Repository/CurrenciesRepository.php,
 * application/Core/Currency/data/currencies.php).
 *
 * `Dics` (short for "dictionaries") turns out to be registered in the SAME
 * `Component\Settings\Initializer.php` as `SettingsController`
 * (`$repo->register("settings", ...); $repo->register("dics", ...);`) —
 * there is no separate `Component\Dics` module in the legacy codebase,
 * despite the name suggesting otherwise. Confirmed by reading
 * `application/Component/Settings/Initializer.php` directly.
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.12_settings.md:
 * "DicsController.currencies — просто справочник валют (дублирует часть
 * getAuxiliaryData)."
 *
 * The real `DicsController` source has exactly ONE action —
 * `currenciesAction()`, nothing else (no `indexAction()`, no other static
 * dictionaries) — so `object=dics` alone (no `.action`) 404s in the legacy
 * app too; the only real route is `object=dics.currencies`.
 */
class DicsController extends Controller
{
    /**
     * Mirrors `application/Core/Currency/data/currencies.php` verbatim — a
     * static currency-code => symbol map, not stored in any DB table.
     */
    private const CURRENCIES = [
        'USD' => '$',
        'RUB' => 'р.',
        'EUR' => '€',
        'GBP' => '£',
        'UAH' => '₴',
    ];

    /**
     * Mirrors `CurrenciesRepository::getCurrencies()`: `[{value, name}, ...]`
     * where `name` is `"<code> (<symbol>)"`, in the array's declaration
     * order.
     */
    public function currenciesAction(Request $request): array
    {
        $result = [];

        foreach (self::CURRENCIES as $code => $symbol) {
            $result[] = ['value' => $code, 'name' => "{$code} ({$symbol})"];
        }

        return $result;
    }
}
