<?php

namespace App\Services\CampaignIntegration;

use App\Models\Campaign;

/**
 * Port of legacy `Component\CampaignIntegration\KClientJS\KClientJSSettings`
 * (application/Component/CampaignIntegration/KClientJS/KClientJSSettings.php).
 *
 * Legacy builds property names dynamically via a `_allowedOptions`
 * whitelist + `Tools::toCamelCase()`; replicated here as a plain
 * constructor over the same fixed option list, since the whitelist never
 * changes and a dynamic property-name builder would just obscure it.
 */
class KClientJsSettings
{
    private ?int $campaignId = null;

    private bool $unique = true;

    private string $url = '';

    private string $host = '';

    private bool $base = true;

    /** @param  array<string, mixed>  $options */
    public function __construct(array $options = [])
    {
        if (array_key_exists('campaign_id', $options)) {
            $this->campaignId = $options['campaign_id'] !== null ? (int) $options['campaign_id'] : null;
        }
        if (array_key_exists('unique', $options)) {
            $this->unique = (bool) $options['unique'];
        }
        if (array_key_exists('url', $options)) {
            $this->url = (string) $options['url'];
        }
        if (array_key_exists('host', $options)) {
            $this->host = (string) $options['host'];
        }
        if (array_key_exists('base', $options)) {
            $this->base = (bool) $options['base'];
        }
    }

    public function getUnique(): string
    {
        return $this->unique ? 'true' : 'false';
    }

    public function getCampaignId(): ?int
    {
        return $this->campaignId;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getBase(): bool
    {
        return $this->base;
    }

    /**
     * Legacy: `Campaign::cookies_ttl` (hours) * 3600 when a campaign_id is
     * given, else 86400 (1 day) default.
     *
     * Defensive addition vs. legacy: a campaign_id pointing at a
     * non-existent campaign falls back to the same 86400 default instead
     * of fataling on `null->get(...)` (legacy would crash) — a strictly
     * safer behavior for the same "no valid campaign" input, not a
     * behavioral feature worth reproducing as a crash.
     */
    public function getCookiesTTL(): int
    {
        if ($this->campaignId) {
            $campaign = Campaign::find($this->campaignId);
            if ($campaign) {
                return (int) $campaign->cookies_ttl * 60 * 60;
            }
        }

        return 86400;
    }
}
