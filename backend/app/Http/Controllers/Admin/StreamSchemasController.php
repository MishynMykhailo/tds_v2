<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Compatibility port of the legacy
 * `Component\Streams\Controller\StreamSchemasController` +
 * `Component\Streams\Repository\StreamSchemaRepository::getListAsOptions()`
 * (old codebase: application/Component/Streams/Controller/
 * StreamSchemasController.php, application/Component/Streams/Repository/
 * StreamSchemaRepository.php, `Traffic\Model\BaseStream::LANDINGS/REDIRECT/
 * ACTION` constants).
 *
 * `object=streamSchemas.listAsOptions` — static catalogue of the 3 stream
 * "schemas" (see docs/legacy-reference/frontend/api/10.2_streams.md,
 * "StreamSchemaRepository"): `landings` (stream shows a landing page, which
 * may then show an offer), `redirect` (aliased `OFFERS` in places — direct
 * redirect to an offer, no landing page), `action` (arbitrary direct action
 * — curl/show_text/404/local_file/etc., see StreamActionsController — no
 * offer/landing entities at all). Order matches
 * `StreamSchemaRepository::getSchemas()`: landings, redirect, action.
 *
 * NOTE — legacy quirk found while porting: old `getListAsOptions()` also
 * emits a `description` built from a `streams.schemas.<name>_desc`
 * translation key, but that key does not exist for ANY of the 3 schemas in
 * the old translation file (application/Component/Streams/translations/
 * en.php only defines `streams.schemas.{action,redirect,landings}`, no
 * `_desc` variants) — `LocaleService::t()` falls back to returning the raw,
 * untranslated key string for a missing key, so the old backend literally
 * ships `"description": "streams.schemas.landings_desc"` etc. Rather than
 * reproduce that, `description` here is a real (English) description
 * derived from §10.2's prose write-up of what each schema means.
 */
class StreamSchemasController extends Controller
{
    public function listAsOptionsAction(Request $request): array
    {
        return [
            [
                'value' => 'landings',
                'name' => 'Landing pages & offers',
                'description' => 'Stream shows a landing page, which may then show an offer.',
            ],
            [
                'value' => 'redirect',
                'name' => 'Direct URL',
                'description' => 'Direct redirect to an offer, without a landing page.',
            ],
            [
                'value' => 'action',
                'name' => 'Action',
                'description' => 'Arbitrary direct action (curl, show text, 404, local file, etc.) — no offer/landing entities involved.',
            ],
        ];
    }
}
