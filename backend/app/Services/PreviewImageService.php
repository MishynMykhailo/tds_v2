<?php

namespace App\Services;

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Exception\CommunicationException;
use HeadlessChromium\Exception\NoResponseAvailable;
use HeadlessChromium\Exception\OperationTimedOut;

/**
 * Real implementation of what legacy `Component\Landings\LocalFile\
 * PreviewImageService::createPreview()` was DISABLED for — that method
 * is a no-op in the actual legacy source (`return;`, immediately, with
 * its own docblock: "Screenshot generation via screenshot.tds24.ru
 * disabled on purpose. Own local (headless-browser based) implementation
 * is planned"). There is no working legacy behavior to port here; this
 * is a from-scratch implementation of that never-realized plan, using
 * `chrome-php/chrome` (Packagist: 5.7M+ downloads, MIT, actively
 * maintained — checked before adding per project convention) driving
 * `chromedp/headless-shell` (deploy/docker-compose.yml `screenshot`
 * service) over the Chrome DevTools Protocol.
 *
 * Screenshots the SAME `traffic-core/public/preview.php` URL the admin
 * "Preview" button opens (`LandingsController`/`OffersController::
 * previewAction()`) — one rendering path for both features, no second
 * way to render `local_file` content needed.
 *
 * `PREVIEW_FILE` name (`_preview.png`) matches legacy's
 * `PreviewImageService::PREVIEW_FILE` exactly, so the relative path this
 * project's `serializeLanding()`/`serializeOffer()` already return in
 * the `preview` field (`{folder}/_preview.png` — see
 * `ActionableResourceTrait::addPreviewData()` in legacy, ported as-is)
 * keeps working unchanged once a real file exists at that path.
 */
class PreviewImageService
{
    public const PREVIEW_FILE = '_preview.png';

    /**
     * @throws \RuntimeException on any failure to reach the screenshot
     *         service or render the page — caller (a queued Job) decides
     *         whether/how to retry; a missing preview image must never
     *         break the landing/offer itself.
     */
    public function capture(string $url, string $destinationPath): void
    {
        $cdpUrl = (string) config('services.screenshot.cdp_url');

        try {
            $version = json_decode((string) @file_get_contents("{$cdpUrl}/json/version"), true);
            $webSocketUrl = is_array($version) ? ($version['webSocketDebuggerUrl'] ?? null) : null;

            if (! is_string($webSocketUrl) || $webSocketUrl === '') {
                throw new \RuntimeException("Screenshot service at {$cdpUrl} did not return a webSocketDebuggerUrl");
            }

            $browser = BrowserFactory::connectToBrowser($webSocketUrl);
            $page = $browser->createPage();
            $page->navigate($url)->waitForNavigation();

            $directory = dirname($destinationPath);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $page->screenshot()->saveToFile($destinationPath);
        } catch (CommunicationException|NoResponseAvailable|OperationTimedOut $e) {
            throw new \RuntimeException("Screenshot capture failed for {$url}: {$e->getMessage()}", previous: $e);
        }
    }
}
