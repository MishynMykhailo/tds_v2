<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Landing;
use App\Models\Offer;
use App\Services\AclService;
use App\Services\CurrentUserService;
use App\Services\LocalFileService;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Editor\Controller\EditorController` +
 * `Component\Editor\Service\EditorService` +
 * `Component\Editor\Repository\EditorRepository` (old codebase:
 * application/Component/Editor/Controller/EditorController.php,
 * application/Component/Editor/Service/EditorService.php,
 * application/Component/Editor/Repository/EditorRepository.php).
 *
 * A "local" landing/offer (action_type=local_file) owns a folder of static
 * files under App\Services\LocalFileService::getStoragePath() — this
 * controller is a minimal file manager over that folder for the admin UI.
 *
 * Deviations from legacy (deliberate):
 * - `loadFilesAction` returns a flat `[{path, type, ext}]` list instead of
 *   legacy's nested tree shape (`EditorRepository::_createStructure()`) —
 *   that shape was built for a specific old JS tree-view widget that
 *   doesn't exist yet in this project's (not-yet-started) frontend; a flat
 *   list is trivial to turn into a tree client-side later.
 * - Every `path` request param is resolved via
 *   `LocalFileService::resolveSafePath()`, which rejects `..`/absolute-path
 *   escapes — legacy concatenates the path with zero traversal checks.
 * - A missing/invalid `id`/`type` returns a real 404 instead of legacy's
 *   `findModel()` returning `false` and then calling ACL checks on `false`.
 * - `infoLandingAction` is NOT ported — trivial re-serialization of an
 *   already-existing landing/offer, not core Editor behavior, skipped to
 *   keep this round scoped to the actual file-manager surface.
 * - `CreatePreviewImageCommand::enqueue(...)` after save/remove IS now
 *   called (`App\Jobs\GenerateLocalFilePreviewJob`) — see that job's
 *   docblock for how rendering works now that `PreviewImageService` is
 *   real (`App\Services\PreviewImageService`, headless-Chrome based).
 */
class EditorController extends Controller
{
    private const MODEL_LANDING = 'landing';

    private const MODEL_OFFER = 'offer';

    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
        private readonly LocalFileService $localFileService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated from the other Admin
    // controllers rather than shared via inheritance, same convention as
    // LandingsController/OffersController (plain `$request->input()` isn't
    // reliable here: legacy/contract clients don't always send a proper
    // `application/json` Content-Type header).
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

    /** Legacy `getParam($name)` — query first, then parsed body. */
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

    private function findModel(int $id, ?string $type): Landing|Offer|null
    {
        return match ($type) {
            self::MODEL_OFFER => Offer::find($id),
            self::MODEL_LANDING => Landing::find($id),
            default => null,
        };
    }

    private function decodeActionOptions($raw): ?array
    {
        return is_string($raw) ? json_decode($raw, true) : $raw;
    }

    /** Port of `EditorRepository::checkLocalType()`. */
    private function folderFor(Landing|Offer $model): ?string
    {
        $options = $this->decodeActionOptions($model->action_options);

        return $options['folder'] ?? null;
    }

    private function notFound(string $message = 'Not found'): Response
    {
        return response()->json(['error' => $message, 'stacktrace' => (new \Exception($message))->getTraceAsString()], 404);
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
     * Resolves `id`/`type`/edit-permission, common to every action below.
     * Returns either [Landing|Offer $model, string $folder] or a Response
     * to return immediately.
     */
    private function resolveEditable(Request $request): array|Response
    {
        $id = (int) $this->param($request, 'id');
        $type = $this->param($request, 'type');

        $model = $this->findModel($id, $type);
        if (! $model) {
            return $this->notFound();
        }

        if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $model)) {
            return $this->forbidden('You are not allowed to edit this resource');
        }

        $folder = $this->folderFor($model);
        if (! $folder) {
            return $this->validationError(['error' => ['Only local landing available']]);
        }

        return [$model, $folder];
    }

    public function loadFilesAction(Request $request): Response
    {
        $resolved = $this->resolveEditable($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [, $folder] = $resolved;

        $basePath = $this->localFileService->buildPath($folder);
        $files = [];

        if (is_dir($basePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $relative = trim(substr($item->getPathname(), strlen($basePath)), '/');
                $files[] = [
                    'path' => $relative,
                    'type' => $item->isDir() ? 'folder' : 'file',
                    'ext' => $item->isDir() ? null : pathinfo($relative, PATHINFO_EXTENSION),
                ];
            }
        }

        return response()->json(['name' => $folder, 'children' => $files]);
    }

    public function loadFileDataAction(Request $request): Response
    {
        $resolved = $this->resolveEditable($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [, $folder] = $resolved;

        try {
            $path = $this->localFileService->resolveSafePath($folder, (string) $this->param($request, 'path'));
        } catch (RuntimeException $e) {
            return $this->validationError(['path' => [$e->getMessage()]]);
        }

        if (! is_file($path)) {
            return $this->notFound('File not found');
        }

        $data = str_replace("\r", '', (string) file_get_contents($path));

        return response()->json(['data' => $data]);
    }

    public function saveFileDataAction(Request $request): Response
    {
        $resolved = $this->resolveEditable($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$model, $folder] = $resolved;

        try {
            $path = $this->localFileService->resolveSafePath($folder, (string) $this->param($request, 'path'));
        } catch (RuntimeException $e) {
            return $this->validationError(['path' => [$e->getMessage()]]);
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, (string) $this->param($request, 'data'));

        $this->queuePreviewRegeneration($model);

        return response()->json(['path' => $path]);
    }

    public function createFileAction(Request $request): Response
    {
        $resolved = $this->resolveEditable($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [, $folder] = $resolved;

        $relativePath = (string) $this->param($request, 'path');
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        if (! in_array($ext, LocalFileService::availableExtensions(), true)) {
            return $this->validationError(['path' => ['Validation error']]);
        }

        if ($ext === 'php' && ! $this->localFileService->isPhpAllowed()) {
            return $this->validationError(['path' => ['PHP is not allowed']]);
        }

        try {
            $path = $this->localFileService->resolveSafePath($folder, $relativePath);
        } catch (RuntimeException $e) {
            return $this->validationError(['path' => [$e->getMessage()]]);
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, '');

        return response()->json(['path' => $path]);
    }

    public function removeFileAction(Request $request): Response
    {
        $resolved = $this->resolveEditable($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$model, $folder] = $resolved;

        try {
            $path = $this->localFileService->resolveSafePath($folder, (string) $this->param($request, 'path'));
        } catch (RuntimeException $e) {
            return $this->validationError(['path' => [$e->getMessage()]]);
        }

        if (is_dir($path)) {
            $this->localFileService->removeDirectory($path);
        } elseif (is_file($path)) {
            unlink($path);
        } else {
            return response()->json(['success' => false]);
        }

        $this->queuePreviewRegeneration($model);

        return response()->json(['success' => true]);
    }

    /**
     * Port of legacy `CreatePreviewImageCommand::enqueue($domain,
     * $systemPath)`'s call sites (`saveFileDataAction`/`removeFileAction`
     * only — legacy does NOT call it from `createFileAction`, confirmed
     * by reading the real source, not assumed for symmetry).
     */
    private function queuePreviewRegeneration(Landing|Offer $model): void
    {
        \App\Jobs\GenerateLocalFilePreviewJob::dispatch(
            $model instanceof Landing ? self::MODEL_LANDING : self::MODEL_OFFER,
            $model->id,
        );
    }
}
