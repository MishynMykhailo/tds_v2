<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Compatibility port of the legacy
 * `Component\Streams\Controller\StreamTypesController` +
 * `Component\Streams\Repository\StreamTypeRepository::getTypesAsOptions()`
 * (old codebase: application/Component/Streams/Controller/
 * StreamTypesController.php, application/Component/Streams/Repository/
 * StreamTypeRepository.php).
 *
 * `object=streamTypes.listAsOptions` — static catalogue of the 3 stream
 * types (`Traffic\Model\Stream::TYPE_*`; see
 * docs/legacy-reference/frontend/api/10.2_streams.md, "StreamTypes"), used
 * by the stream editor UI. Order matches
 * `StreamTypeRepository::getTypes()`: regular, default, forced. Names are
 * the legacy English translation strings (`streams.types.*`, old codebase:
 * application/Component/Streams/translations/en.php).
 */
class StreamTypesController extends Controller
{
    public function listAsOptionsAction(Request $request): array
    {
        return [
            ['value' => 'regular', 'name' => 'Regular'],
            ['value' => 'default', 'name' => 'Default'],
            ['value' => 'forced', 'name' => 'Forced'],
        ];
    }
}
