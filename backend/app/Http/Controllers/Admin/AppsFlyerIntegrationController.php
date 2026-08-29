<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Port of legacy `Component\ThirdPartyIntegration\Controller\AppsFlyerController`
 * — the entire legacy controller has just this one action (old codebase:
 * application/Component/ThirdPartyIntegration/Controller/AppsFlyerController.php
 * + AppsFlyer/AppsFlyerDescription.php).
 *
 * `?object=appsflyerintegration` (legacy `Initializer::loadControllers()`
 * registers `$repo->register("appsflyerintegration", ...)`, confirmed by
 * reading that Initializer directly).
 *
 * No i18n module ported — `language` read directly from the request,
 * defaulting to `'en'`, same convention as FacebookIntegrationController.
 */
class AppsFlyerIntegrationController extends Controller
{
    public function getDescriptionAction(Request $request): array
    {
        $language = $request->input('language', 'en');

        if ($language === 'ru') {
            return $this->descriptionRu();
        }

        return $this->descriptionEn();
    }

    /** Verbatim copy of `AppsFlyer\AppsFlyerDescription::getDescriptionRu()`. */
    private function descriptionRu(): array
    {
        $description = "Настройка интеграции для получения конверсий AppsFlyer через API<br>\n<h3>Инструкция по настройке</h3>\n<p>Для настройки вам необходимо добавить данные для подключения. Нажмите на кнопку \"Добавить новую\". В открывшемся окне вам необходимо\nввести соответствующие данные для подключения к AppsFlyer.</p>\n<ul>\n    <li>Имя интеграции - произвольное имя необходимое для удобной идентификации в списке интеграции</li>\n    <li>App name - имя приложения app_name из личного кабинета</li>\n    <li>API token - токен для подключения к рекламному аккаунту через API</li>\n    <li>Периодичность - периодичность обновления в минутах. Не ставьте маленькое значение это добавит нагрузку на трекер и может послужить причиной блокировки AppsFlyer.</li>\n    <li>Использовать прокси - получать данные через прокси (укажите настройки прокси)</li>\n</ul>";

        return ['description' => $description];
    }

    /** Verbatim copy of `AppsFlyer\AppsFlyerDescription::getDescriptionEn()`. */
    private function descriptionEn(): array
    {
        $description = "Configuring integration for receiving AppsFlyer conversions via the API<br>\n<h3>Configuration instructions</h3>\n<p>To configure, you need to add connection data. Click on the \"Add new\" button. In the window that opens, you need to enter the appropriate data to connect to Facebook.</p>\n<ul>\n    <li>Integration name - arbitrary name needed for easy identification in the integration list</li>\n    <li>App name - app_name from your personal account</li>\n    <li>API token - token for connecting to an ad account via the API</li>\n    <li>Query interval - the frequency of updates in minutes. Do not set a small value this will add load to the tracker and may cause AppsFlyer to be blocked.</li>\n    <li>Use proxy - to obtain data via proxy (specify the proxy settings)</li>\n</ul>";

        return ['description' => $description];
    }
}
