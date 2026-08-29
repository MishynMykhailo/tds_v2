<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoProfile;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of legacy `Component\GeoProfiles\Controller\GeoProfilesController`
 * + `Service\GeoProfileService` + `Serializer\DecoratedGeoProfileSerializer`
 * (old codebase: application/Component/GeoProfiles/{Controller,Service,
 * Serializer,Model}/*).
 *
 * `?object=geoprofiles` (legacy `Initializer::loadControllers()` registers
 * `$repo->register("geoProfiles", ...)`, confirmed by reading that
 * Initializer directly; lowercased here per ObjectDispatchController's
 * established convention).
 *
 * `countries` is stored as a space-separated string of ISO codes (legacy
 * `parseCountriesAndCreate/Update()` does `implode(" ", $codes)` when given
 * an array) — always returned to the client as an array, accepted as
 * either an array or a pre-joined string.
 *
 * `decorated_countries` (a human-readable ", "-joined country name list)
 * is ported using the real legacy country-name dictionary, copied verbatim
 * from `application/Component/GeoDb/dictionaries/countries.php` to
 * `resources/data/countries.php` — English names only (`en`), since no
 * i18n/locale module has been ported yet (established precedent, see
 * ResourceController::complementaryAsOptionsAction docblock), unlike
 * legacy's locale-driven `getCountryName()`.
 */
class GeoProfilesController extends Controller
{
    public function __construct(
        private readonly CurrentUserService $currentUserService,
    ) {}

    /** `Core\Exceptions\DenyError` shape (§5/§6): 403, {"error": "..."}. */
    private function deny(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    private function isAdmin(): bool
    {
        $user = $this->currentUserService->get();

        return $user !== null && $user->isAdmin();
    }

    /** @var array<string, array<string, string>>|null */
    private static ?array $countryNames = null;

    /** @return array<string, array<string, string>> */
    private function countryDictionary(): array
    {
        if (self::$countryNames === null) {
            self::$countryNames = include resource_path('data/countries.php');
        }

        return self::$countryNames;
    }

    /** @param  array<int, string>  $codes */
    private function decoratedCountries(array $codes): string
    {
        $dictionary = $this->countryDictionary();
        $names = [];

        foreach ($codes as $code) {
            $names[] = $dictionary[$code]['en'] ?? ($dictionary['@empty']['en'] ?? $code);
        }

        return implode(', ', $names);
    }

    private function serializeOne(GeoProfile $profile): array
    {
        $codes = $profile->countryCodes();

        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'countries' => $codes,
            'decorated_countries' => $this->decoratedCountries($codes),
        ];
    }

    /** @param  string|array<int, string>|null  $countries */
    private function normalizeCountries(mixed $countries): string
    {
        if (is_array($countries)) {
            return implode(' ', $countries);
        }

        return (string) ($countries ?? '');
    }

    public function indexAction(Request $request): array
    {
        return GeoProfile::query()->get()->map(fn (GeoProfile $p) => $this->serializeOne($p))->all();
    }

    public function listAsOptionsAction(Request $request): array
    {
        return $this->indexAction($request);
    }

    public function showAction(Request $request): Response
    {
        $id = (int) $request->input('id');
        $profile = GeoProfile::find($id);

        // Legacy `showAction()` calls `serialize(null, ...)` when the id
        // doesn't match anything, which the base serializer turns into a
        // literal JSON `null` (200) — replicated as-is (this is a plain
        // "not found" result, not a half-populated broken object like the
        // Domains/ThirdPartyIntegration cases documented elsewhere in this
        // log, so there is no reason to deviate here).
        return response(json_encode($profile ? $this->serializeOne($profile) : null))
            ->header('Content-Type', 'application/json');
    }

    public function createAction(Request $request): Response|array
    {
        if (! $this->isAdmin()) {
            return $this->deny();
        }

        $params = $request->all();

        $profile = GeoProfile::create([
            'name' => $params['name'] ?? '',
            'countries' => $this->normalizeCountries($params['countries'] ?? null),
        ]);

        return $this->serializeOne($profile);
    }

    public function updateAction(Request $request): Response|array
    {
        if (! $this->isAdmin()) {
            return $this->deny();
        }

        $id = (int) $request->input('id');
        $profile = GeoProfile::find($id);

        if (! $profile) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $params = $request->all();

        if (array_key_exists('name', $params)) {
            $profile->name = $params['name'];
        }
        if (array_key_exists('countries', $params)) {
            $profile->countries = $this->normalizeCountries($params['countries']);
        }
        $profile->save();

        return $this->serializeOne($profile);
    }

    public function deleteAction(Request $request): Response|array
    {
        if (! $this->isAdmin()) {
            return $this->deny();
        }

        // Legacy also gates on `ConfigService::isDemo()` — no "demo mode"
        // concept exists in this project, omitted.
        $id = (int) $request->input('id');
        GeoProfile::where('id', $id)->delete();

        return ['success' => true];
    }
}
