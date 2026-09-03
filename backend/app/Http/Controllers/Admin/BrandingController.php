<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branding;
use App\Services\AclService;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of legacy `Component\Branding\Controller\BrandingController`
 * (object=branding). Legacy gates both actions behind
 * `FeatureService::hasBrandingFeature()` (a fake license-tier check — this
 * port has no license gating at all, see docs/PORTING_LOG.md, so the
 * feature is simply always on). `updateAction` also requires
 * `isAdmin()` — replicated.
 *
 * REAL BUG, found live against legacy port 8090 (2026-09-03): the class
 * docblock never mentioned it, but BOTH actions are ALSO gated by the
 * generic resource-level ACL check every legacy controller gets before
 * dispatch (`AdminRequestFactory::checkAuthorization()` ->
 * `AclService::isResourceAllowed($user, "branding")`, exact message
 * "You have no permission to access to this page - Branding") - "branding"
 * is not a default resource for an ordinary user, so a non-admin without
 * it 403s on `indexAction()` too, which this port previously let through
 * unauthenticated-gate-free. Same class of gap already found and fixed
 * for Conversions this session.
 */
class BrandingController extends Controller
{
    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    private function forbiddenResource(): Response
    {
        return response()->json(['error' => 'You have no permission to access to this page - Branding'], 403);
    }

    public function indexAction(Request $request): array|Response
    {
        if (! $this->aclService->isResourceAllowed($this->currentUserService->get(), 'branding')) {
            return $this->forbiddenResource();
        }

        $row = Branding::query()->first();

        // CORRECTION (2026-09-03): a prior version of this eagerly
        // created and persisted a Branding row here (Branding::create([]))
        // when none existed - a write side-effect on a read-only action.
        // Live-verified against legacy port 8090 that the real behavior
        // is `{"id": null, "logo": null, "favicon": null}` when the
        // (real, empty) `tds_branding` table has no row at all - a row
        // only gets created once `branding.update` actually saves one.
        return $row ? $this->serialize($row) : ['id' => null, 'logo' => null, 'favicon' => null];
    }

    public function updateAction(Request $request): array|Response
    {
        if (! $this->aclService->isResourceAllowed($this->currentUserService->get(), 'branding')) {
            return $this->forbiddenResource();
        }

        $user = $this->currentUserService->get();
        if (! $user || ! $user->isAdmin()) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        if (! $request->isMethod('post')) {
            return response((string) null);
        }

        $row = Branding::query()->first() ?? new Branding();
        // logo/favicon are sent as raw binary (data URI decoded client-side
        // in legacy) — accept whatever body fields are present.
        $row->fill($request->only(['logo', 'favicon']));
        $row->save();

        return $this->serialize($row);
    }

    /** Legacy `BrandingSerializer`: `$_fields = true`, missing keys filled with null. */
    private function serialize(Branding $row): array
    {
        $data = $row->getAttributes();
        foreach (['id', 'logo', 'favicon'] as $key) {
            $data[$key] = $data[$key] ?? null;
        }

        return $data;
    }
}
