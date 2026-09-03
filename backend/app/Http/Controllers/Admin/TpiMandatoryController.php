<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\ThirdPartyIntegration;
use App\Models\TpiCampaignAssociation;
use App\Services\AclService;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Throwable;

/**
 * Port of legacy `Component\ThirdPartyIntegration\Controller\TPIMandatoryController`
 * + `Service\TPICampaignAssociationService` + `Repository\
 * TPICampaignAssociationRepository` (old codebase:
 * application/Component/ThirdPartyIntegration/{Controller,Service,Repository}/
 * TPI*.php).
 *
 * `?object=tpimandatory` (legacy `Initializer::loadControllers()` registers
 * `$repo->register("tpimandatory", ...)`, confirmed by reading that
 * Initializer directly).
 *
 * Legacy `_checkPro()`/`isBasic()` gate on `listAsOptionsAction()`
 * deliberately NOT ported — see ThirdPartyIntegrationController's docblock
 * for why (neutralized license system, always pro).
 */
class TpiMandatoryController extends Controller
{
    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

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

    private function boolParam($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    /**
     * Legacy: returns `[]` when no `integration` param is given; otherwise
     * prepends a synthetic "default" option (`value: 0`) before every real
     * integration matching that name. The default label is legacy's real
     * `third_party_integration.get_cost_default` translation string, taken
     * verbatim from `application/Component/ThirdPartyIntegration/
     * translations/en.php` (NOT a guessed placeholder) — "Not synchronize".
     */
    public function listAsOptionsAction(Request $request): array
    {
        $integration = $this->param($request, 'integration');
        $result = [];

        if (isset($integration)) {
            $data = ThirdPartyIntegration::query()->where('integration', $integration)->get();

            $result[] = ['value' => 0, 'name' => 'Not synchronize'];

            foreach ($data as $datum) {
                $result[] = ['value' => $datum->id, 'name' => $datum->getIntegrationName()];
            }
        }

        return $result;
    }

    /**
     * Port of `TPICampaignAssociationService::updateCampaign()`. Note the
     * legacy quirk replicated here: this looks up an EXISTING association
     * by the exact (integration_id, campaign_id) pair, not by campaign_id
     * alone — re-pointing a campaign from integration A to integration B
     * creates a NEW row rather than moving the old one; the old row is left
     * behind unless `removeCampaignAction` is called for it separately.
     * `integration_id = 0` on an existing row deletes it instead of saving
     * (legacy's way of representing "no cost sync").
     */
    public function addCampaignAction(Request $request): array
    {
        $integrationId = $this->param($request, 'integration_id');
        $campaignId = $this->param($request, 'campaign_id');

        try {
            $assoc = TpiCampaignAssociation::query()
                ->where('integration_id', $integrationId)
                ->where('campaign_id', $campaignId)
                ->first();

            $existed = $assoc !== null;

            if (! $assoc) {
                $assoc = new TpiCampaignAssociation();
            }

            $assoc->integration_id = $integrationId;
            $assoc->campaign_id = $campaignId;

            if ($existed) {
                if ((int) $integrationId === 0) {
                    $assoc->delete();
                } else {
                    $assoc->save();
                }
            } else {
                $assoc->save();
            }

            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false];
        }
    }

    public function removeCampaignAction(Request $request): array
    {
        $integrationId = $this->param($request, 'integration_id');
        $campaignId = $this->param($request, 'campaign_id');

        try {
            $assoc = TpiCampaignAssociation::query()
                ->where('integration_id', $integrationId)
                ->where('campaign_id', $campaignId)
                ->first();

            if (! $assoc) {
                // Legacy calls the delete service on a null lookup result,
                // which would not succeed either way — a defensive
                // "not found -> success:false" here achieves the same
                // observable failure without depending on how the legacy
                // internals happen to blow up on a null entity.
                return ['success' => false];
            }

            $assoc->delete();

            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false];
        }
    }

    /**
     * Port of `allAction()`: campaigns currently linked to a given
     * integration, ACL-filtered, in the `CampaignRepository::listAsOptions()`
     * shape (id/name/group_id/group/value) — replicated locally rather than
     * calling `CampaignsController::listAsOptionsAction()` (that method
     * queries its own full campaign list and doesn't accept a pre-filtered
     * id set), per task instructions to avoid duplicating the *query*, not
     * necessarily the item-shaping code.
     */
    public function allAction(Request $request): array
    {
        $integrationId = $this->param($request, 'integration_id');

        $campaignIds = TpiCampaignAssociation::query()
            ->where('integration_id', $integrationId)
            ->pluck('campaign_id')
            ->all();

        $addBlank = $this->boolParam($this->param($request, 'add_blank'));

        $campaigns = [];
        if (count($campaignIds)) {
            $campaigns = Campaign::query()
                ->whereIn('id', $campaignIds)
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->all();
        }

        $campaigns = $this->aclService->filterByAcl($campaigns, false, $this->currentUserService->get());

        $key = $this->param($request, 'key') ?: 'id';

        return $this->campaignsAsOptions($campaigns, $key, $addBlank);
    }

    /**
     * Port of legacy `CampaignRepository::listAsOptions($campaigns, $key,
     * $addBlank)`.
     *
     * CORRECTION (2026-09-03): a prior version of this hardcoded `group`
     * to "Default" unconditionally, citing "Groups module not ported yet"
     * — stale, Groups was ported for Campaigns earlier this session (see
     * CampaignsController::showAction()'s real `Group::find()` lookup).
     * Real legacy `listAsOptions()` does a real `GroupsRepository::
     * getName($campaign->getGroupId())` lookup, falling back to "Default"
     * only when that's null (application/Traffic/Repository/
     * CampaignRepository.php:67-68) — matched here the same way
     * CampaignsController already does.
     *
     * @param  array<int, Campaign>  $campaigns
     */
    private function campaignsAsOptions(array $campaigns, string $key, bool $addBlank): array
    {
        if ($key === '') {
            $key = 'id';
        }

        $items = [];

        if ($addBlank) {
            $items[] = [$key => '', 'name' => 'Choose campaign'];
        }

        foreach ($campaigns as $campaign) {
            $items[] = [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'group_id' => $campaign->group_id,
                // "No group", not "Default" - this is the same
                // LocaleService::t("groups.default") fallback as
                // CampaignsController::resolveGroup(), confirmed live
                // against legacy port 8090's real tpimandatory.all output
                // for a group-less campaign (not the different, literal
                // "Default" CampaignSerializer uses for campaigns.show).
                'group' => $campaign->group_id
                    ? \App\Models\Group::find($campaign->group_id)?->name
                    : 'No group',
                'value' => (int) $campaign->{$key},
            ];
        }

        return $items;
    }
}
