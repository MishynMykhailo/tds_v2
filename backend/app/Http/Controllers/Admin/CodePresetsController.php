<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of legacy `Component\CampaignIntegration\Controller\CodePresetsController`
 * + `Repository\CodePresetsRepository` (old codebase: application/Component/
 * CampaignIntegration/{Controller/CodePresetsController.php,
 * Repository/CodePresetsRepository.php, data/code_presets.php}).
 *
 * `?object=codepresets` (legacy `Initializer::loadControllers()` registers
 * `$repo->register("codePresets", ...)`, confirmed by reading that
 * Initializer directly; lowercased here per ObjectDispatchController's
 * established convention).
 *
 * No i18n module ported — `language` read directly from the request,
 * defaulting to `'en'`, same convention as FacebookIntegrationController/
 * AppsFlyerIntegrationController. `group_translated` normally goes
 * through `LocaleService::t("integration.groups.".$group)` — verified
 * live (2026-09-03) against all 5 real groups (banners/frames/links/
 * other/redirects, both `language=en` and `=ru`) that the real
 * translation is simply `ucfirst($group)` in every case this data set
 * actually exercises, not a genuine per-language lookup — so `ucfirst()`
 * is used directly instead of the raw lowercase `$group` a prior version
 * returned here.
 *
 * DOC/REALITY MISMATCH found while porting: legacy `_prepare()`'s
 * `is_pro_only` flag is gated on
 * `FeatureService::getEdition() in ["trial", "pro"]` — but
 * `FeatureService::getEdition()` in this product build is hardcoded to
 * always return `EssentialPayload::BUSINESS` (confirmed by reading that
 * class directly), which is never in that array. So `is_pro_only` is
 * ALWAYS `false` in the live old backend too, regardless of a preset's own
 * `edition` field — not just a "neutralized license" simplification on our
 * side, the check is already dead in the source of truth. Hardcoded to
 * `false` here rather than porting an edition check that can never fire.
 */
class CodePresetsController extends Controller
{
    /** @var array<int, array<string, mixed>>|null */
    private static ?array $presets = null;

    /** @return array<int, array<string, mixed>> */
    private function presets(): array
    {
        if (self::$presets === null) {
            self::$presets = include resource_path('data/code_presets.php');
        }

        return self::$presets;
    }

    private function prepare(array $preset, string $locale): array
    {
        $data = [
            'id' => $preset['id'] ?? '',
            'name' => is_array($preset['name']) ? ($preset['name'][$locale] ?? $preset['name']['en'] ?? '') : $preset['name'],
            'instruction' => isset($preset['instructions']) ? ($preset['instructions'][$locale] ?? null) : null,
            'instruction_2' => isset($preset['instructions_2']) ? ($preset['instructions_2'][$locale] ?? null) : null,
            'code' => empty($preset['code']) ? '' : $preset['code'],
            'offer_code' => empty($preset['offer_code']) ? '' : $preset['offer_code'],
            'postback_code' => empty($preset['postback_code']) ? '' : $preset['postback_code'],
            'add_params' => isset($preset['add_params']) ? $this->prepareAddParams($preset['add_params']) : '',
            'group' => $preset['group'],
            'group_translated' => ucfirst($preset['group']),
            'settings' => $preset['settings'] ?? null,
            'is_beta' => $preset['beta'] ?? null,
            // Always false — see class docblock (FeatureService::getEdition()
            // dead-code finding).
            'is_pro_only' => false,
        ];

        return $data;
    }

    private function prepareAddParams(string $addParams): string
    {
        $params = explode('&', $addParams);
        $params = array_map(function ($paramString) {
            if (str_starts_with($paramString, 'frm')) {
                [$paramName, $paramValue] = explode('=', $paramString);

                return $paramName.uniqid().'='.$paramValue.uniqid();
            }

            return $paramString;
        }, $params);

        return implode('&', $params);
    }

    public function indexAction(Request $request): array
    {
        $language = $request->input('language', 'en');

        $result = [];
        foreach ($this->presets() as $preset) {
            $result[] = $this->prepare($preset, $language);
        }

        return $result;
    }

    public function showAction(Request $request): Response
    {
        $language = $request->input('language', 'en');
        $id = $request->input('id');

        $found = null;
        foreach ($this->presets() as $preset) {
            if (isset($preset['id']) && (string) $id === (string) $preset['id']) {
                $found = $preset;
                break;
            }
        }

        $result = $found ? $this->prepare($found, $language) : null;

        // Legacy `get($id)` implicitly returns `null` (no `return` reached)
        // when the id doesn't match anything — replicated as a literal JSON
        // `null` body (200), not a 404, matching the legacy contract as-is.
        // NOT built via `response()->json($result)`: this Laravel/Symfony
        // version's `JsonResponse` rewrites a `null` `$data` argument into
        // `{}` (see UserPreferencesController::getAction() for the same
        // documented workaround) — encoding manually sidesteps that.
        return response(json_encode($result))->header('Content-Type', 'application/json');
    }

    public function downloadClientAction(Request $request): Response
    {
        return response()->download(
            resource_path('kclients/kclient.php'),
            'kclient.php',
            ['Content-Type' => 'application/octet-stream']
        );
    }

    public function downloadClientV2Action(Request $request): Response
    {
        // Legacy `downloadClientV2Action()` uses the exact same
        // `Content-Disposition: attachment; filename=kclient.php` (NOT
        // `kclient_v2.php`) for the v2 file too — replicated verbatim
        // even though it looks like a copy-paste artifact in the source.
        return response()->download(
            resource_path('kclients/kclient_v2.php'),
            'kclient.php',
            ['Content-Type' => 'application/octet-stream']
        );
    }
}
