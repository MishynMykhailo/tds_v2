<?php

namespace TrafficCore\Pipeline;

/**
 * Trimmed-down port of legacy `Traffic\Pipeline\Payload` (see
 * application/Traffic/Pipeline/Payload.php in the old source) — mutable
 * state object carried through the pipeline stages. Only the fields
 * needed for Phase 1 (see docs/TRAFFIC_CORE_PLAN.md) are ported; the
 * legacy class has ~20 fields (offer/landing/token/cookie-binding/
 * uniqueness flags etc.) that belong to later phases.
 *
 * Phase 3 adds `landingId`/`offerId`/`actionOptions` for
 * `ChooseLandingStage`/`ChooseOfferStage` (schema=landings/offers
 * rotation) — still no token/cookie-binding fields, that flow remains
 * unported (see ChooseOfferStage's docblock).
 */
class Payload
{
    public \Psr\Http\Message\ServerRequestInterface $request;

    /** @var array<string,mixed>|null */
    public ?array $campaign = null;

    /** @var array<string,mixed>|null */
    public ?array $stream = null;

    public ?string $actionType = null;
    public ?string $actionPayload = null;
    public ?string $actionOptions = null;

    public ?int $landingId = null;
    public ?int $offerId = null;

    /**
     * Campaign-recursion fields (`campaign`/`group` action type) — see
     * `CheckSendingToAnotherCampaign` and `PipelineRunner`. Mirrors
     * legacy `Payload::getForcedCampaignId()`/`RawClick::
     * setParentCampaignId()` (application/Traffic/Pipeline/Payload.php).
     */
    public ?int $forcedCampaignId = null;
    public ?int $parentCampaignId = null;

    /** @var array<string,mixed> Phase 4 — see Signal::fromRequest(). Populated by CaptureSignalStage. */
    public array $signal = [];

    /** @var array<string,mixed> Raw click fields to insert into `clicks`. */
    public array $rawClick = [];

    public bool $aborted = false;
    public int $statusCode = 200;
    public array $headers = [];
    public string $body = '';

    /**
     * Real Visitor find-or-create result — see `ResolveVisitorStage`
     * (GeoDb + device resolution, `VisitorResolver`). 0 means "not yet
     * resolved"; `BuildRawClickStage` treats 0 as a bug, not a fallback.
     */
    public int $visitorId = 0;

    /** @var array{geo?: array<string,mixed>, device?: array<string,mixed>} Populated by ResolveVisitorStage, for future filters/reporting. */
    public array $geoDevice = [];

    /**
     * Redis lookup token for the offer-attribution flow — see
     * `GenerateTokenStage`/`LpTokenService`. Null when no offer was
     * chosen (nothing was stored). Purely informational for now; nothing
     * downstream reads it yet.
     */
    public ?string $lookupToken = null;

    public function __construct(\Psr\Http\Message\ServerRequestInterface $request)
    {
        $this->request = $request;
    }

    public function abort(int $statusCode, string $body = ''): void
    {
        $this->aborted = true;
        $this->statusCode = $statusCode;
        $this->body = $body;
    }
}
