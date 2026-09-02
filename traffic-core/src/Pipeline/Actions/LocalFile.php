<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Macros\ClickMacroValues;
use TrafficCore\Macros\MacrosProcessor;
use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\LocalFile` (application/
 * Traffic/Actions/Predefined/LocalFile.php) — serves a landing page
 * already uploaded through the already-ported Editor/Cleaner admin
 * screens (`backend/`'s `App\Services\LocalFileService`, same on-disk
 * folder, same `{"folder": "..."}` `action_options` shape). Implements
 * `ActionHandler` directly, not `AbstractAction` — legacy's `_execute()`
 * bypasses `_executeInContext()` entirely, same as `Curl`/`DoNothing`/
 * `FormSubmit`/`Status404`/`SubId`.
 *
 * Only ever reached via `landings.action_type`/`offers.action_type` in
 * practice (`EditorController::folderFor()` in `backend/` only resolves
 * a folder for a `Landing`/`Offer` model, never a bare `Stream` — the
 * admin UI has no way to configure `local_file` directly on a stream,
 * even though the column would technically accept it), so
 * `payload->actionOptions` is always populated by
 * `ChooseLandingStage`/`ChooseOfferStage` by the time this runs.
 *
 * Phase 14: `_processMacros()` is now real, applied last (after the HTML
 * -adapting chain, matching legacy's `PageWrapper::_adaptBody()` order:
 * anchors -> base-path -> resource-paths -> form-action -> macros).
 * `addBasePath()` NOT ported — see `HtmlPathAdapter`'s docblock.
 */
class LocalFile implements ActionHandler
{
    private const NO_INDEX_FILE = 'Error: LP must contain index file. Please read the system log file.';

    public function execute(Payload $payload): void
    {
        $options = json_decode((string) $payload->actionOptions, true);
        $folder = is_array($options) ? ($options['folder'] ?? null) : null;

        if (!is_string($folder) || $folder === '') {
            $payload->statusCode = 502;
            $payload->body = self::NO_INDEX_FILE;

            return;
        }

        $sandbox = new LocalFileSandbox();

        try {
            $path = $sandbox->resolveFolderPath($folder);
        } catch (\RuntimeException) {
            $payload->statusCode = 502;
            $payload->body = self::NO_INDEX_FILE;

            return;
        }

        $indexFile = $sandbox->findIndexFile($path);
        if ($indexFile === null) {
            $payload->statusCode = 502;
            $payload->body = self::NO_INDEX_FILE;

            return;
        }

        $indexFilePath = $path . '/' . $indexFile;
        $isHtml = str_ends_with($indexFile, '.html') || str_ends_with($indexFile, '.htm');

        if (!$sandbox->isPhpAllowed() || $isHtml) {
            $payload->statusCode = 200;
            $payload->headers['Content-Type'] = 'text/html';
            $body = (string) file_get_contents($indexFilePath);
        } else {
            [$status, $headerLines, $body] = $sandbox->execute($indexFilePath, $payload->request, $payload->rawClick);
            $payload->statusCode = $status;
            foreach ($headerLines as $line) {
                $pos = strpos($line, ':');
                if ($pos === false) {
                    continue;
                }
                $name = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                if ($name !== '') {
                    $payload->headers[$name] = $value;
                }
            }
        }

        $adapter = new HtmlPathAdapter();
        $body = $adapter->adaptAnchors($body);
        $body = $adapter->adaptResourcePaths($body);
        $body = $adapter->adaptFormAction($body);
        $body = MacrosProcessor::process($body, ClickMacroValues::forPayload($payload));

        $payload->body = $body;
    }
}
