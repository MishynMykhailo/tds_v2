<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeleteStatsJob;
use App\Models\Campaign;
use App\Services\AclService;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy `Component\Cleaner\Controller\CleanerController`
 * (old codebase: application/Component/Cleaner/Controller/CleanerController.php,
 * application/Component/Cleaner/Service/CleanerService.php,
 * application/Component/Cleaner/DelayedCommand/DeleteStatsCommand.php).
 *
 * Only `cleanAction` is ported (the single legacy `?object=cleaner.clean`
 * dispatch surface, legacy route `POST /clicks/clean`). NOT ported (TODO):
 * `warmupCacheAction` (depends on `CachedDataRepository`, not ported) and
 * every `CleanerService::prune*()` method (`pruneClicks`/`pruneVisitors`/
 * `pruneConversions`/`pruneClickLinks`/`pruneReferences`) — those belong to
 * the Cron `PruneData` task / `DelayedCommands` cluster, which has zero
 * `?object=` dispatch surface of its own (confirmed by the full module
 * census, docs/PORTING_LOG.md) and isn't this controller's job to expose.
 * `ConfigService::isDemo()` demo-mode deny at the top of legacy
 * `cleanAction` also isn't ported — no demo-mode config module exists yet
 * in this project (same scope decision as GeoProfilesController).
 */
class CleanerController extends Controller
{
    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — same duplicated-per-controller
    // pattern as CampaignsController/LandingsController/OffersController.
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

    private function isPost(Request $request): bool
    {
        return ! empty($this->parsedBody($request)) || $request->isMethod('post');
    }

    /** `Core\Exceptions\DenyError` shape (§5/§6): 403, {"error": "..."}. */
    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    /**
     * REAL BUG, found live against legacy port 8090 (2026-09-03): a
     * non-existent `campaign_id` was treated the same as "found but not
     * allowed" (403) — real legacy's `CampaignRepository::find()` (the
     * same `Core\Entity\Repository\EntityRepository::find()` every other
     * entity lookup in this codebase already replicates as a 404, e.g.
     * Labels/GeoProfiles/Reports) throws a real `NotFoundError` before
     * `isEditAllowed()` is ever reached — confirmed live, exact message
     * `"Traffic\Model\Campaign #<id> not found"`.
     */
    private function campaignNotFound(int $campaignId): Response
    {
        $message = "Traffic\\Model\\Campaign #{$campaignId} not found";

        return response()->json(['error' => $message, 'stacktrace' => (new \Exception($message))->getTraceAsString()], 404);
    }

    /**
     * Legacy `_validateDate()` throwing `Core\Validator\ValidationError`
     * with the literal `["success" => false, "error" => "Invalid format
     * date"]` payload — NOT the generic field-map {field: [msg]} shape used
     * by other controllers' validationError(), because that's exactly what
     * legacy itself threw here (verbatim array, not a field-keyed one).
     * Still a 406 (ValidationError's fixed HTTP status, §6).
     */
    private function dateError(): Response
    {
        return response()->json(['success' => false, 'error' => 'Invalid format date'], 406);
    }

    // ---------------------------------------------------------------
    // Action
    // ---------------------------------------------------------------

    public function cleanAction(Request $request): Response
    {
        if (! $this->isPost($request)) {
            return response()->json(['success' => false]);
        }

        $timezone = $this->param($request, 'timezone');
        $startDate = $this->param($request, 'start_date');
        $endDate = $this->param($request, 'end_date');

        // REAL BUG, found live against legacy port 8090 (2026-09-03): this
        // was returning the same 406 as the invalid-date-FORMAT branch
        // below, but real legacy's missing-start/end-date check is a
        // plain `return [...]` (application/Component/Cleaner/Controller/
        // CleanerController.php) - an ordinary controller return, HTTP
        // 200 - NOT the `_validateDate()` throw a few lines later, which
        // really is a `Core\Validator\ValidationError` (fixed 406, §6).
        // Same {success, error} body either way, only the status code
        // was wrong.
        if (! $startDate || ! $endDate) {
            return response()->json(['success' => false, 'error' => 'Invalid format date']);
        }

        if (! $this->isValidDate($startDate, $timezone) || ! $this->isValidDate($endDate, $timezone)) {
            return $this->dateError();
        }

        $campaignId = $this->param($request, 'campaign_id');

        if (! empty($campaignId)) {
            $campaign = Campaign::find((int) $campaignId);

            if (! $campaign) {
                return $this->campaignNotFound((int) $campaignId);
            }

            if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $campaign)) {
                return $this->forbidden();
            }

            DeleteStatsJob::dispatch($startDate, $endDate, $timezone, (int) $campaign->id);

            return response()->json(['success' => true]);
        }

        $user = $this->currentUserService->get();

        if ($user && $user->isAdmin()) {
            DeleteStatsJob::dispatch($startDate, $endDate, $timezone, null);

            return response()->json(['success' => true]);
        }

        $allowedCampaignIds = $this->aclService->getAllowedCampaignIds($user);

        if (is_array($allowedCampaignIds)) {
            foreach ($allowedCampaignIds as $allowedCampaignId) {
                DeleteStatsJob::dispatch($startDate, $endDate, $timezone, (int) $allowedCampaignId);
            }
        } elseif ($allowedCampaignIds === AclService::ALLOW_ANY) {
            // Non-admin user with a full_access/read_only campaigns rule:
            // legacy's own equivalent path here is only reachable for
            // admins (see class docblock) — getAllowedCampaignIds() can
            // still return ALLOW_ANY for a non-admin, so schedule a single
            // unfiltered cleanup exactly like the admin branch above,
            // matching what "every campaign visible" means everywhere else
            // this sentinel is consumed (GridBuilder).
            DeleteStatsJob::dispatch($startDate, $endDate, $timezone, null);
        }

        return response()->json(['success' => true]);
    }

    private function isValidDate(string $date, ?string $timezone): bool
    {
        try {
            $tz = empty($timezone) ? new \DateTimeZone('UTC') : new \DateTimeZone($timezone);
            new \DateTime($date, $tz);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
