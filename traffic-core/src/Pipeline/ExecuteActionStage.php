<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Pipeline\Actions\ActionHandler;
use TrafficCore\Pipeline\Actions\BlankReferrer;
use TrafficCore\Pipeline\Actions\CampaignAction;
use TrafficCore\Pipeline\Actions\Curl;
use TrafficCore\Pipeline\Actions\DoNothing;
use TrafficCore\Pipeline\Actions\DoubleMeta;
use TrafficCore\Pipeline\Actions\Frame;
use TrafficCore\Pipeline\Actions\FormSubmit;
use TrafficCore\Pipeline\Actions\Iframe;
use TrafficCore\Pipeline\Actions\Js;
use TrafficCore\Pipeline\Actions\JsForIframe;
use TrafficCore\Pipeline\Actions\JsForScript;
use TrafficCore\Pipeline\Actions\LocalFile;
use TrafficCore\Macros\ClickMacroValues;
use TrafficCore\Macros\MacrosProcessor;
use TrafficCore\Pipeline\Actions\Meta;
use TrafficCore\Pipeline\Actions\Remote;
use TrafficCore\Pipeline\Actions\ShowHtml;
use TrafficCore\Pipeline\Actions\ShowText;
use TrafficCore\Pipeline\Actions\Status404;
use TrafficCore\Pipeline\Actions\SubId;

/**
 * Trimmed port of legacy `Traffic\Pipeline\Stage\ExecuteActionStage`
 * (application/Traffic/Pipeline/Stage/ExecuteActionStage.php), which
 * dispatches to one of 19 action classes registered in
 * `Traffic\Actions\Repository\StreamActionRepository` (application/
 * Traffic/Actions/Repository/StreamActionRepository.php, confirmed by
 * reading it directly — 19 base keys, not counting aliases like
 * `build_html`/`return_html`/`echo`/`group`/`location`) by the
 * `action_type` string key.
 *
 * Ported: `http` (inline below, unchanged from Phase 1) + 15 more this
 * round (see `TrafficCore\Pipeline\Actions\*` — each class's docblock
 * cites its exact legacy source file and documents what was and wasn't
 * carried over): `blank_referrer`, `curl`, `do_nothing`, `formsubmit`,
 * `frame`, `iframe`, `js`, `js_for_iframe`, `js_for_script`, `meta`,
 * `remote`, `show_html`, `show_text`, `status404`, `sub_id`.
 *
 * Phase 14: `processMacros()` is now real (`TrafficCore\Macros\
 * MacrosProcessor`/`ClickMacroValues`) — legacy applies it via a
 * universal `AbstractAction::getActionPayload()` accessor (confirmed by
 * reading `application/Traffic/Actions/AbstractAction.php:55` —
 * `return $this->processMacros($this->getRawActionPayload());` — used by
 * nearly every action's `getActionPayload()` call, not a per-class
 * concern). Ported centrally here instead: `process()` substitutes
 * macros into `payload->actionPayload` ONCE, before dispatch, covering
 * every action type below that reads it. Skipped for `campaign`/`group`
 * — confirmed by reading `Traffic\Actions\Predefined\ToCampaign::
 * _execute()`, it calls `getRawActionPayload()` (the UN-substituted raw
 * value, since it's a numeric campaign id, not content) — substituting
 * macros there would risk corrupting the id `CheckSendingToAnotherCampaign`
 * casts to int right after this stage. `Curl`/`LocalFile` additionally
 * apply macros to their FETCHED/FILE content (not just `actionPayload`)
 * — see their own docblocks, matching legacy's separate `processMacros()`
 * calls on fetched bodies (`CurlService`) and rendered pages
 * (`PageWrapper::_processMacros()`).
 *
 * `campaign`/`group` (`Traffic\Actions\Predefined\ToCampaign`) ported
 * separately — see `CampaignAction` (this stage's handler, a no-op
 * mirroring legacy exactly) plus `CheckSendingToAnotherCampaign` and
 * `PipelineRunner` for the actual recursive re-run.
 *
 * `double_meta` also ported (Phase 7) — see `DoubleMeta`, `LpTokenKey`,
 * and `public/gateway.php`. Correcting an earlier note in this docblock:
 * it does NOT need `GenerateTokenStage`/`LpTokenService`'s TTL/storage
 * machinery — that's an unrelated token flow (offer-redirect two-step
 * tracking attribution). `double_meta`'s own JWT usage
 * (`LpTokenService::generateUserKey()` + `Firebase\JWT\JWT`) is
 * self-contained and needed nothing but a small receiving endpoint.
 *
 * `local_file` also ported (Phase 8) — see `LocalFile`, `LocalFileSandbox`,
 * `HtmlPathAdapter`, and `bin/execute_local_file.php`. All 19 real
 * `action_type` keys are now implemented.
 */
class ExecuteActionStage
{
    /** @var array<string,class-string<ActionHandler>> */
    private const REGISTRY = [
        'blank_referrer' => BlankReferrer::class,
        'campaign' => CampaignAction::class,
        'group' => CampaignAction::class,
        'curl' => Curl::class,
        'do_nothing' => DoNothing::class,
        'double_meta' => DoubleMeta::class,
        'formsubmit' => FormSubmit::class,
        'frame' => Frame::class,
        'iframe' => Iframe::class,
        'js' => Js::class,
        'js_for_iframe' => JsForIframe::class,
        'js_for_script' => JsForScript::class,
        'local_file' => LocalFile::class,
        'meta' => Meta::class,
        'remote' => Remote::class,
        'show_html' => ShowHtml::class,
        'show_text' => ShowText::class,
        'status404' => Status404::class,
        'sub_id' => SubId::class,
    ];

    public function process(Payload $payload): Payload
    {
        if (empty($payload->actionType)) {
            // Mirrors legacy: empty actionType -> leave response as-is
            // (legacy's own `do_nothing` action / no-stream fallback).
            return $payload;
        }

        if (!in_array($payload->actionType, ['campaign', 'group'], true) && $payload->actionPayload !== null) {
            $payload->actionPayload = MacrosProcessor::process(
                $payload->actionPayload,
                ClickMacroValues::forPayload($payload),
                $payload->signal['params'] ?? [],
            );
        }

        if ($payload->actionType === 'http') {
            $payload->statusCode = 302;
            $payload->headers['Location'] = $payload->actionPayload;
            return $payload;
        }

        $handlerClass = self::REGISTRY[$payload->actionType] ?? null;
        if ($handlerClass === null) {
            $payload->abort(501, "Action type \"{$payload->actionType}\" not implemented in traffic-core");
            return $payload;
        }

        (new $handlerClass())->execute($payload);

        return $payload;
    }
}
