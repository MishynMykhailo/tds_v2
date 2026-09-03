<?php

namespace App\Jobs;

use App\Models\Landing;
use App\Models\Offer;
use App\Services\LocalFileService;
use App\Services\PreviewImageService;
use App\Services\PreviewUrlBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Port of legacy `Component\Landings\DelayedCommand\
 * CreatePreviewImageCommand` (application/Component/Landings/
 * DelayedCommand/CreatePreviewImageCommand.php) — queued (legacy: a
 * custom DB-backed command queue; here: a plain Laravel queued job, same
 * substitution already used for `App\Jobs\DeleteStatsJob`) after a
 * `local_file` landing/offer's content changes, so its grid thumbnail
 * stays current.
 *
 * Legacy enqueued this from `EditorController::saveFileDataAction()`/
 * `removeFileAction()` (`application/Component/Editor/Controller/
 * EditorController.php`) with `($domain, $systemPath)` — this port
 * dispatches it the same two places (see `EditorController.php` here)
 * with just `(type, id)`, since rendering goes through this project's
 * own `traffic-core/public/preview.php` (no admin-request-derived
 * `$domain` needed at all — a real improvement over legacy's approach,
 * not a fidelity gap: legacy's screenshot target depended on whichever
 * domain the editing admin happened to be using, this doesn't).
 *
 * A screenshot failure (service down, bad render) must never surface as
 * a user-facing error on the file save/delete it was triggered by —
 * `$tries`/`backoff` give it a few automatic retries, and a final
 * failure just leaves the previous (or no) `_preview.png` in place.
 */
class GenerateLocalFilePreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $type,
        public readonly int $id,
    ) {}

    public function handle(
        PreviewUrlBuilder $urlBuilder,
        PreviewImageService $screenshots,
        LocalFileService $localFileService,
    ): void {
        $model = match ($this->type) {
            'landing' => Landing::find($this->id),
            'offer' => Offer::find($this->id),
            default => null,
        };

        if ($model === null || $model->action_type !== 'local_file') {
            return;
        }

        $options = is_string($model->action_options) ? json_decode($model->action_options, true) : $model->action_options;
        $folder = is_array($options) ? ($options['folder'] ?? null) : null;

        if (! is_string($folder) || $folder === '') {
            return;
        }

        $url = $urlBuilder->build($this->type, $this->id);
        $destination = $localFileService->buildPath($folder).'/'.PreviewImageService::PREVIEW_FILE;

        // Caught, not thrown: the caller (EditorController::
        // saveFileDataAction()/removeFileAction(), or a real queue
        // worker) must never see this fail — see class docblock. With
        // QUEUE_CONNECTION=sync (this project's test env) the job runs
        // inline in the same request, so an uncaught exception here
        // would otherwise turn a successful file save into a 500.
        try {
            $screenshots->capture($url, $destination);
        } catch (\RuntimeException $e) {
            \Illuminate\Support\Facades\Log::warning('GenerateLocalFilePreviewJob: preview capture failed', [
                'type' => $this->type,
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
