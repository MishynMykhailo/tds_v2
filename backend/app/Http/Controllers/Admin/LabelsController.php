<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Label;
use App\Services\AclService;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy `Component\Reports\Controller\
 * LabelsController` + `Component\Reports\Repository\LabelRepository` +
 * `Component\Reports\Service\LabelService` (old codebase:
 * application/Component/Reports/Controller/LabelsController.php,
 * application/Component/Reports/Repository/LabelRepository.php,
 * application/Component/Reports/Service/LabelService.php). Registered as
 * `object=labels` even though the legacy class physically lives inside the
 * Reports module — it is its own controller/object key there, not a
 * `reports.*` action.
 *
 * `labels`/`ip_id` etc. dimension values are joined in legacy via per-
 * `ref_name` dictionary tables (`ref_ips`, `ref_sources`, ...) and a
 * `labels.ref_id` FK. This port's `labels` table
 * (database/migrations/2025_01_01_000022_create_labels_table.php)
 * deliberately adds a denormalized `ref_value` string column instead:
 * `App\Services\Grid\GridBuilder` has no join support (see
 * ReportsController's header docblock for the same limitation), and only
 * SOME of the label-eligible ref names even have a dictionary table ported
 * (ref_sources/ref_creative_ids/ref_ad_campaign_ids/ref_keywords/
 * ref_x_requested_with exist; `ip` and `sub_id_N` do not — confirmed
 * against database/migrations/2025_01_01_000017_create_ref_dictionary_tables.php).
 * So, unlike legacy, every action here keys/dedups labels by
 * (campaign_id, ref_name, ref_value) directly and leaves `ref_id` unset —
 * no ref-dictionary lookup/join is performed at all. This is a deliberate
 * schema-driven simplification, not an oversight.
 *
 * CONFIRMED live (2026-09-03) this divergence is real and necessary, not
 * just theoretical: legacy's `LabelService::updateLabels()`/`replaceList()`
 * treat `items`'/`ref_values`' KEYS as the ref dictionary row's raw
 * `value` (cast through `ip2long()` for `ip`, `(int)` otherwise) and do a
 * `WHERE value = ...` lookup to resolve the real numeric `ref_id` before
 * ever touching `labels` — i.e. legacy's own wire contract requires the
 * CALLER to already know exactly what internal transformation its `value`
 * column needs (which silently produces wrong/empty results for a
 * non-numeric `ref_name` like `source` with a domain-string value — verified
 * live, a literal domain string key resolves to `(int) "example.com" ===
 * 0` and matches nothing). This port's `ref_value`-keyed contract sidesteps
 * that footgun entirely — a real behavioral improvement, not just a
 * schema-forced compromise, so intentionally NOT replicated even though a
 * live re-check was done specifically to make sure this wasn't secretly
 * load-bearing for a real client. `ref_name` validation also intentionally
 * improves on legacy: an unrecognized `ref_name` here is a clean 406
 * (`validRefNames()`), where real legacy has no such check at all and
 * 500s via `LabelService::getRefDefinition()`'s uncaught `Error` (verified
 * live) — again a deliberate, safe improvement, not an oversight.
 *
 * Access: all three real actions gate on `isViewAllowed($campaign)` against
 * the label's parent campaign (verified against legacy source — the write
 * actions `update`/`replaceList` use `isViewAllowed`, not `isEditAllowed`,
 * same as `index`), same "resolve parent campaign, check ACL through it"
 * pattern as StreamFiltersController/TriggersController use for streams.
 */
class LabelsController extends Controller
{
    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    /**
     * Legacy label_name catalogue (`LabelRepository::WHITELIST`/`::BLACKLIST`
     * + `getLabelVariations()`). Icons/labels kept verbatim from the English
     * translations (application/Component/Reports/translations/en.php
     * "labels" key).
     */
    public const WHITELIST = 'whitelist';

    public const BLACKLIST = 'blacklist';

    /**
     * Legacy label-eligible `ref_name`s (`Column::LABELS_ALLOWED === true`
     * entries in ClicksDefinition::initColumns(), cross-checked against
     * `LabelService::getRefDefinition()`'s supported cases). Titles are the
     * literal English strings from
     * application/Component/Grid/translations/en.php.
     *
     * CORRECTION (2026-09-03): a prior version capped `sub_id_N` at 10 on
     * the premise "legacy default `ParameterRepository::getSubIdCount()`
     * is 10 unless a `sub_id_15_id` column exists" — that premise is
     * schema-conditional, and THIS port's own `clicks` table (`database/
     * migrations/2025_01_01_000018_create_clicks_table.php`) already has
     * `sub_id_1_id` through `sub_id_15_id` (all 15). Verified live against
     * legacy port 8090 (`?object=labels.refNameVariations`): real legacy
     * returns exactly 15 `sub_id_N` entries, not 10 — confirming this
     * port's own schema puts it on the "15" side of that same condition,
     * not the "10" default. Capped at 10 was a real, confirmed bug, not a
     * defensible scope decision.
     */
    private const REF_NAME_TITLES = [
        'ip' => 'IP',
        'source' => 'Site',
        'x_requested_with' => 'X-Requested-With',
        'ad_campaign_id' => 'Ad Campaign ID',
        'creative_id' => 'Creative ID',
        'keyword' => 'Keyword',
    ];

    private const SUB_ID_COUNT = 15;

    /** @return list<string> */
    public static function validRefNames(): array
    {
        $names = array_keys(self::REF_NAME_TITLES);
        for ($i = 1; $i <= self::SUB_ID_COUNT; $i++) {
            $names[] = "sub_id_{$i}";
        }

        return $names;
    }

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated per-controller
    // convention (see TriggersController/StreamFiltersController headers).
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

    private function validationError(array $errors): Response
    {
        return response()->json($errors, 406);
    }

    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    /**
     * CORRECTION (2026-09-03): none of the 3 actions below originally
     * checked this — `Campaign::find()` returning null just fell straight
     * into `isViewAllowed(null, ...)`, giving 403 for a missing campaign.
     * Real legacy's `CampaignRepository::find()` THROWS for a missing id
     * (a real `NotFoundError`, caught by `AdminDispatcher` into a genuine
     * 404) — verified live against port 8090 (`campaign_id=0` -> 404
     * "Traffic\Model\Campaign #0 not found", not 403). Matches the
     * "resolve entity, 404 if missing, THEN check ACL" order every other
     * ported controller already uses (e.g. `CampaignsController::
     * showAction()`).
     */
    private function notFound(string $message = 'Not found'): Response
    {
        return response()->json(['error' => $message, 'stacktrace' => (new \Exception($message))->getTraceAsString()], 404);
    }

    // ---------------------------------------------------------------
    // Reference-data actions (LabelRepository::getLabelVariations()/
    // retRefNameVariations()) — no ACL, pure static catalogues.
    // ---------------------------------------------------------------

    public function labelVariationsAction(Request $request): array
    {
        return [
            ['value' => self::WHITELIST, 'label' => 'Whitelist', 'icon' => 'ion-thumbsup grid-filter-label-whitelist'],
            ['value' => self::BLACKLIST, 'label' => 'Blacklist', 'icon' => 'ion-thumbsdown grid-filter-label-blacklist'],
        ];
    }

    public function refNameVariationsAction(Request $request): array
    {
        $items = [];
        foreach (self::REF_NAME_TITLES as $value => $name) {
            $items[] = ['value' => $value, 'name' => $name];
        }
        for ($i = 1; $i <= self::SUB_ID_COUNT; $i++) {
            $items[] = ['value' => "sub_id_{$i}", 'name' => "Sub ID {$i}"];
        }

        return $items;
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    public function indexAction(Request $request): Response|array|null
    {
        $campaignId = (int) $this->param($request, 'campaign_id');
        $campaign = Campaign::find($campaignId);

        if (! $campaign) {
            return $this->notFound("Traffic\\Model\\Campaign #{$campaignId} not found");
        }
        $refName = (string) $this->param($request, 'ref_name');
        $labelName = $this->param($request, 'label_name');

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to view this campaign');
        }

        if (! in_array($refName, self::validRefNames(), true)) {
            return $this->validationError(['ref_name' => ["Incorrect ref_name {$refName}"]]);
        }

        $query = Label::query()->where('campaign_id', $campaignId)->where('ref_name', $refName);
        if (! empty($labelName)) {
            $query->where('label_name', $labelName);
        }

        $labels = [];
        foreach ($query->orderBy('id')->get(['ref_value', 'label_name']) as $row) {
            $labels[$row->ref_value] = $row->label_name;
        }

        if (empty($labels)) {
            // CORRECTION (2026-09-03): legacy's `indexAction()` returns
            // bare PHP `null`, which `ObjectDispatchController`'s generic
            // non-array/object passthrough casts to `(string) null` — an
            // EMPTY body (0 bytes), not the 4-byte text "null". A prior
            // version of this branch built that literal text manually
            // (`response(json_encode(null))`), verified live against
            // legacy port 8090 to be wrong (real legacy response body is
            // empty). Returning the bare `null` here lets the dispatcher's
            // same generic cast produce the correct empty body, same
            // fix-shape as `UserPreferencesController::getAction()`.
            return null;
        }

        return response()->json($labels);
    }

    public function updateAction(Request $request): Response
    {
        $campaignId = (int) $this->param($request, 'campaign_id');
        $campaign = Campaign::find($campaignId);

        if (! $campaign) {
            return $this->notFound("Traffic\\Model\\Campaign #{$campaignId} not found");
        }
        $refName = (string) $this->param($request, 'ref_name');
        $items = $this->param($request, 'items', []);

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to view this campaign');
        }

        if (! in_array($refName, self::validRefNames(), true)) {
            return $this->validationError(['ref_name' => ["Incorrect ref_name {$refName}"]]);
        }

        if (! is_array($items)) {
            $items = [];
        }

        // Mirrors `LabelService::set()`/`::remove()`: an empty label value
        // for a ref_value deletes the row, otherwise it's an upsert.
        foreach ($items as $refValue => $labelName) {
            $refValue = (string) $refValue;

            if (empty($labelName)) {
                Label::query()
                    ->where('campaign_id', $campaignId)
                    ->where('ref_name', $refName)
                    ->where('ref_value', $refValue)
                    ->delete();

                continue;
            }

            Label::query()->updateOrCreate(
                ['campaign_id' => $campaignId, 'ref_name' => $refName, 'ref_value' => $refValue],
                ['label_name' => $labelName]
            );
        }

        return response()->json(['success' => true]);
    }

    public function replaceListAction(Request $request): Response
    {
        $campaignId = (int) $this->param($request, 'campaign_id');
        $campaign = Campaign::find($campaignId);

        if (! $campaign) {
            return $this->notFound("Traffic\\Model\\Campaign #{$campaignId} not found");
        }
        $refName = (string) $this->param($request, 'ref_name');
        $refValues = $this->param($request, 'ref_values', []);
        $labelName = $this->param($request, 'label_name');

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to view this campaign');
        }

        if (empty($labelName)) {
            return $this->validationError(['label_name' => ['Empty label_name']]);
        }

        if (! in_array($refName, self::validRefNames(), true)) {
            return $this->validationError(['ref_name' => ["Incorrect ref_name {$refName}"]]);
        }

        if (! is_array($refValues)) {
            $refValues = [];
        }
        $refValues = array_values(array_unique(array_map('strval', $refValues)));

        // Mirrors `LabelService::replaceList()`/`_deleteAllExcept()`: every
        // existing (campaign_id, ref_name, label_name) row whose ref_value
        // is NOT in the incoming list is dropped, then the incoming list is
        // upserted. `whereNotIn()` with an empty $refValues array matches
        // every row (Laravel compiles it to a constant-true condition),
        // matching legacy's explicit `$leaveRefIds = [-1]` fallback when the
        // list is empty (see TriggersController::assignTriggers() for the
        // same Laravel behavior noted elsewhere in this codebase).
        Label::query()
            ->where('campaign_id', $campaignId)
            ->where('label_name', $labelName)
            ->where('ref_name', $refName)
            ->whereNotIn('ref_value', $refValues)
            ->delete();

        foreach ($refValues as $refValue) {
            Label::query()->updateOrCreate(
                ['campaign_id' => $campaignId, 'ref_name' => $refName, 'ref_value' => $refValue],
                ['label_name' => $labelName]
            );
        }

        return response()->json(['success' => true]);
    }
}
