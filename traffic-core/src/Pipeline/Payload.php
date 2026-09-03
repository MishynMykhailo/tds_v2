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

    /**
     * Phase 17 — `LandingOfferDispatcher`'s re-entry fields (port of
     * legacy `Payload::__construct()`'s `forced_stream_id`/
     * `forced_offer_id` keys, application/Traffic/Pipeline/Payload.php).
     * Consumed by `ChooseStreamStage`/`ChooseOfferStage` exactly like
     * `forcedCampaignId` is consumed by `FindCampaignStage`: check, act,
     * null it out. Used when a landing page requests its offer AFTER
     * already being shown (the stream/offer were already decided on the
     * FIRST click; this second pass must resolve the SAME ones, not roll
     * new ones) — see `public/landing-offer.php`.
     */
    public ?int $forcedStreamId = null;
    public ?int $forcedOfferId = null;

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
     * Real bot verdict — port of legacy `RawClick::isBot()`'s resolved
     * value. Computed once in `ResolveVisitorStage` (device-detector's
     * `is_bot`, in `geoDevice['device']`, OR'd with
     * `BotDetection\BotDetectionService::isBot()` — see that class),
     * BEFORE `ChooseStreamStage` runs, so the `bot` `StreamFilter` (see
     * `Filters\FilterEngine::evaluate()`) can consult it exactly like
     * legacy's real `_checkIfBot()` → `ChooseStreamStage` ordering.
     * `BuildRawClickStage` reads this same resolved value for
     * `clicks.is_bot` rather than recomputing it.
     */
    public bool $isBot = false;

    /**
     * Redis lookup token for the offer-attribution flow — see
     * `GenerateTokenStage`/`LpTokenService`. Null when no offer was
     * chosen and no landing needed one either (see `$needToken` below).
     * Read by `public/ktrk.php`'s JS callback and `ClickApiResponseBuilder`'s
     * `info` block.
     */
    public ?string $lookupToken = null;

    /**
     * Phase 17 — port of legacy `Payload::isTokenNeeded()`. Set by
     * `ChooseLandingStage` when the chosen landing's STREAM has offers
     * configured behind it (`stream_offer_associations` non-empty) —
     * the trigger `public/landing-offer.php` depends on to later restore
     * this click by token. See `ChooseLandingStage`'s own docblock for
     * the exact legacy method this mirrors.
     */
    public bool $needToken = false;

    /**
     * Canonical-name overrides from `CheckParamAliasesStage` (e.g. a
     * campaign configured `keyword`'s alias as `kw` — a request with
     * `?kw=foo` lands here as `['keyword' => 'foo']`). `BuildRawClickStage`
     * consults this before falling back to the request's own query/body
     * params for every aliasable field.
     *
     * @var array<string,string>
     */
    public array $resolvedParams = [];

    /**
     * Raw (pre-dictionary-lookup) click field STRINGS — `sub_id_1`..`15`,
     * `extra_param_1`..`10`, `source`, `referrer`, `keyword`,
     * `search_engine`, `ad_campaign_id`, `x_requested_with`, `cost` —
     * populated by `BuildRawClickStage` alongside `rawClick` (which holds
     * the resolved `ref_*` dictionary FK ids instead, for the `clicks`
     * INSERT). `MacrosProcessor` (Phase 14) reads from here: a macro like
     * `{sub_id_1}` must expand to the actual submitted value, not an
     * opaque dictionary integer — same distinction legacy's `RawClick`
     * draws by keeping both raw getters (`getSubIdN()`) and a
     * dictionary-resolving `serialize()`.
     *
     * @var array<string,string|null>
     */
    public array $clickFields = [];

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
