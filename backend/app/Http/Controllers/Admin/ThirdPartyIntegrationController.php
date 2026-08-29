<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\ThirdPartyIntegration;
use App\Models\TpiCampaignAssociation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of legacy `Component\ThirdPartyIntegration\Controller\ThirdPartyIntegrationController`
 * + `Service\ThirdPartyIntegrationService` + `Repository\ThirdPartyIntegrationRepository`
 * + `Serializer\ThirdPartyIntegrationSerializer` (old codebase:
 * application/Component/ThirdPartyIntegration/{Controller,Service,Repository,Serializer}/*).
 *
 * `?object=thirdpartyintegration` (legacy `Initializer::loadControllers()`
 * registers `$repo->register("thirdpartyintegration", ...)`, confirmed by
 * reading that Initializer directly).
 *
 * Legacy `_checkPro()` gate deliberately NOT ported: the neutralized license
 * system in this product build hardcodes `FeatureService::isBasic()` to
 * `return false;` (confirmed by reading that class directly), so the gate
 * never actually fires in the real running system — omitted rather than
 * ported as dead code, per task instructions.
 */
class ThirdPartyIntegrationController extends Controller
{
    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated per-controller
    // convention established by DomainsController/SettingsController/etc.
    // ---------------------------------------------------------------

    private function parsedBody(Request $request): ?array
    {
        static $cache = null;
        static $cachedFor = null;

        if ($cachedFor === $request) {
            return $cache;
        }

        $raw = $request->getContent();
        $trimmed = is_string($raw) ? ltrim($raw) : '';

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            $result = is_array($decoded) ? $decoded : null;
        } elseif (is_string($raw) && str_contains($raw, '&')) {
            parse_str($raw, $parsed);
            $result = $parsed;
        } else {
            $result = null;
        }

        $cachedFor = $request;
        $cache = $result;

        return $result;
    }

    private function param(Request $request, string $name, $default = null)
    {
        if ($request->query->has($name)) {
            return $request->query->get($name);
        }

        $body = $this->parsedBody($request);
        if (is_array($body) && array_key_exists($name, $body)) {
            return $body[$name];
        }

        return $default;
    }

    private function postParams(Request $request): array
    {
        return $this->parsedBody($request) ?? [];
    }

    // ---------------------------------------------------------------
    // §6 error-shape helpers
    // ---------------------------------------------------------------

    /** `Core\Exceptions\NotFoundError` shape: 404, {"error", "stacktrace"}. */
    private function notFound(string $message = 'Not found'): Response
    {
        return response()->json(['error' => $message, 'stacktrace' => ''], 404);
    }

    // ---------------------------------------------------------------
    // Serialization: `ThirdPartyIntegrationSerializer::extra()` — replaces
    // the whole payload with the `settings` JSON blob plus `id` merged in
    // (confirmed by reading `Core\Json\AbstractSerializer::serialize()`:
    // `extra()`'s return value REPLACES `$data`, it does not merge into it).
    // ---------------------------------------------------------------

    private function serializeOne(?ThirdPartyIntegration $integration): ?array
    {
        if (! $integration) {
            return null;
        }

        $result = $integration->settings ?? [];
        $result['id'] = $integration->id;

        return $result;
    }

    /** @param  iterable<ThirdPartyIntegration>  $integrations */
    private function serializeMany(iterable $integrations): array
    {
        $result = [];
        foreach ($integrations as $integration) {
            $result[] = $this->serializeOne($integration);
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    public function createAction(Request $request): Response|array
    {
        $allParams = $this->postParams($request);
        $integration = $this->param($request, 'integration');

        if (! $integration) {
            // Legacy throws `Core\Application\Exception\Error` here, which
            // is NOT one of the specially-handled exception types (§6) — it
            // falls through to the catch-all handler: HTTP 500, plain body,
            // not JSON (same class of case as SettingsController::
            // updateAction()'s "Must be post request").
            return response('Param integration is required', 500);
        }

        $allParams['integration'] = $integration;

        $model = ThirdPartyIntegration::create([
            'integration' => $integration,
            'settings' => $allParams,
        ]);

        // Legacy `createAction()` returns the bare serialized object, NOT
        // wrapped in `["data" => ...]` — unlike update/get/find below.
        return $this->serializeOne($model);
    }

    public function updateAction(Request $request): Response
    {
        $allParams = $this->postParams($request);
        $id = $this->param($request, 'id');

        if (! $id) {
            return $this->notFound('No ID provided');
        }

        $integration = ThirdPartyIntegration::find($id);

        if (! $integration) {
            // INTENTIONAL DEVIATION: legacy `updateValues()` silently no-ops
            // on a missing id, then re-fetches (also null), and the
            // serializer's `extra()` auto-vivifies `$data["settings"]` on a
            // null array into `['id' => null]` — producing HTTP 200 with
            // `{"data": {"id": null}}` instead of a real error. Same class
            // of legacy bug as DomainsController::showAction() (see its
            // docblock) — a clean 404 is preferred here instead of
            // replicating that half-null 200 body.
            return $this->notFound('Third party integration not found');
        }

        // Legacy `ThirdPartyIntegrationService::updateValues()`: MERGES new
        // keys into the existing `settings` JSON, does not replace it.
        $settings = $integration->settings ?? [];
        foreach ($allParams as $key => $value) {
            $settings[$key] = $value;
        }
        $integration->settings = $settings;
        $integration->save();

        return response()->json(['data' => $this->serializeOne($integration)]);
    }

    public function getAction(Request $request): array
    {
        $integration = $this->param($request, 'integration');

        if (! isset($integration)) {
            return [];
        }

        $data = ThirdPartyIntegration::query()->where('integration', $integration)->get();

        return ['data' => $this->serializeMany($data)];
    }

    public function findAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (! isset($id)) {
            return $this->notFound('No ID provided');
        }

        $integration = ThirdPartyIntegration::find($id);

        if (! $integration) {
            // Same deviation as updateAction() above — clean 404 instead of
            // legacy's `{"data": {"id": null}}` 200.
            return $this->notFound('Third party integration not found');
        }

        return response()->json(['data' => $this->serializeOne($integration)]);
    }

    public function getByCampaignIdAction(Request $request): array
    {
        $id = $this->param($request, 'id');

        $key = TpiCampaignAssociation::query()
            ->where('campaign_id', $id)
            ->value('integration_id');

        return ['default' => $key];
    }

    public function deleteAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (! isset($id)) {
            return $this->notFound('No ID provided');
        }

        // Legacy `deleteById()` silently no-ops if the row doesn't exist,
        // and the controller always returns `["success" => true]` when an
        // id was provided at all — replicated as-is.
        ThirdPartyIntegration::where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------
    // Legacy `getSettingsIntegrationAction`/`updateSettingsIntegrationAction`
    // — despite living on this controller, these read/write the GLOBAL
    // system settings key/value store (`Traffic\Settings\Repository\
    // SettingsRepository`), NOT the `third_party_integration` table at all
    // (confirmed by reading the legacy source directly). Reuses the exact
    // same `App\Models\Setting` mechanism as SettingsController — see that
    // controller's own docblock note that these helpers are deliberately
    // duplicated per-controller rather than shared.
    // ---------------------------------------------------------------

    public function getSettingsIntegrationAction(Request $request): array
    {
        $param = $this->param($request, 'param');

        $value = Setting::query()->where('key', $param)->value('value');

        return [$param => $value];
    }

    public function updateSettingsIntegrationAction(Request $request): array
    {
        $param = $this->param($request, 'param');
        $key = $this->param($request, $param);
        $newSettings = [$param => $key];

        if (! is_null($key)) {
            Setting::query()->updateOrCreate(['key' => $param], ['value' => $key]);
        }

        $hash = [];
        foreach (array_keys($newSettings) as $settingKey) {
            $hash[$settingKey] = Setting::query()->where('key', $settingKey)->value('value');
        }

        return $hash;
    }
}
