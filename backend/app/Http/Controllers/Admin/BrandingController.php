<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branding;
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
 */
class BrandingController extends Controller
{
    public function __construct(
        private readonly CurrentUserService $currentUserService,
    ) {}

    public function indexAction(Request $request): array
    {
        return $this->serialize($this->row());
    }

    public function updateAction(Request $request): array|Response
    {
        $user = $this->currentUserService->get();
        if (! $user || ! $user->isAdmin()) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        if (! $request->isMethod('post')) {
            return response((string) null);
        }

        $row = $this->row();
        // logo/favicon are sent as raw binary (data URI decoded client-side
        // in legacy) — accept whatever body fields are present.
        $row->fill($request->only(['logo', 'favicon']));
        $row->save();

        return $this->serialize($row);
    }

    private function row(): Branding
    {
        return Branding::query()->first() ?? Branding::create([]);
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
