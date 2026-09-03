<?php

namespace App\Services;

/**
 * Builds the same HMAC-signed `traffic-core/public/preview.php` URL
 * `LandingsController`/`OffersController::previewAction()` return to the
 * admin's browser, and `App\Jobs\GenerateLocalFilePreviewJob` feeds to
 * `PreviewImageService::capture()` — one place for this logic instead of
 * three copies of the same HMAC construction. See `preview.php`'s own
 * docblock (traffic-core) for the validation side and why a signed token
 * exists at all (backend/ and traffic-core/ are separate Composer
 * projects, no shared code — this is the trust boundary between them).
 */
class PreviewUrlBuilder
{
    public function build(string $type, int $id, int $ttlMinutes = 5): string
    {
        $expires = now()->addMinutes($ttlMinutes)->getTimestamp();
        $secret = (string) config('services.traffic_core.preview_secret');
        $token = hash_hmac('sha256', "{$type}:{$id}:{$expires}", $secret);

        $base = rtrim((string) config('services.traffic_core.url'), '/');

        return "{$base}/preview.php?".http_build_query([
            'type' => $type,
            'id' => $id,
            'expires' => $expires,
            'token' => $token,
        ]);
    }
}
