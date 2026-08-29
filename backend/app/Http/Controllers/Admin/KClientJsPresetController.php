<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CampaignIntegration\CodeGenerator;
use App\Services\CampaignIntegration\KClientJsSettings;
use Illuminate\Http\Request;

/**
 * Port of legacy `Component\CampaignIntegration\Controller\KClientJSPresetController`
 * (application/Component/CampaignIntegration/Controller/KClientJSPresetController.php).
 *
 * `?object=kclientjspreset` (legacy `Initializer::loadControllers()`
 * registers `$repo->register("kClientJSPreset", ...)`, confirmed by reading
 * that Initializer directly; lowercased here per ObjectDispatchController's
 * established convention).
 *
 * Legacy `isBasic()` pro-gate deliberately NOT ported — see
 * ThirdPartyIntegrationController's docblock for why (neutralized license
 * system, always pro).
 */
class KClientJsPresetController extends Controller
{
    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated per-controller
    // convention established by DomainsController/SettingsController/etc.
    // ---------------------------------------------------------------

    private function parsedBody(Request $request): ?array
    {
        $raw = $request->getContent();
        $trimmed = is_string($raw) ? ltrim($raw) : '';

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);

            return is_array($decoded) ? $decoded : null;
        }

        if (is_string($raw) && str_contains($raw, '&')) {
            parse_str($raw, $parsed);

            return $parsed;
        }

        return null;
    }

    /** Legacy `getPostParams()` — the whole parsed body. */
    private function postParams(Request $request): array
    {
        return $this->parsedBody($request) ?? [];
    }

    public function showAction(Request $request): array
    {
        $data = $this->postParams($request);

        $settings = new KClientJsSettings($data);
        $generator = new CodeGenerator();

        return ['code' => $generator->getCode($settings)];
    }
}
