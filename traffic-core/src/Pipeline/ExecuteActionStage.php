<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Pipeline\Actions\ActionHandler;
use TrafficCore\Pipeline\Actions\BlankReferrer;
use TrafficCore\Pipeline\Actions\CampaignAction;
use TrafficCore\Pipeline\Actions\Curl;
use TrafficCore\Pipeline\Actions\DoNothing;
use TrafficCore\Pipeline\Actions\Frame;
use TrafficCore\Pipeline\Actions\FormSubmit;
use TrafficCore\Pipeline\Actions\Iframe;
use TrafficCore\Pipeline\Actions\Js;
use TrafficCore\Pipeline\Actions\JsForIframe;
use TrafficCore\Pipeline\Actions\JsForScript;
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
 * A shared, project-wide gap across all of them: `processMacros()`
 * (`Traffic\Macros\MacrosProcessor`) is NOT ported anywhere in
 * traffic-core — every action payload/content is used raw, macros like
 * `{sub_id_1}`/`{source_id}` are not substituted. Documented once here,
 * not repeated in each class.
 *
 * `campaign`/`group` (`Traffic\Actions\Predefined\ToCampaign`) ported
 * separately — see `CampaignAction` (this stage's handler, a no-op
 * mirroring legacy exactly) plus `CheckSendingToAnotherCampaign` and
 * `PipelineRunner` for the actual recursive re-run.
 *
 * Deliberately still NOT implemented (501, visible not silent):
 *  - `double_meta` — needs the JWT/gateway-token flow
 *    (`GenerateTokenStage`/`LpTokenService`/`GatewayRedirectContext`),
 *    itself a separately-deferred cluster; porting `double_meta` without
 *    it would mean shipping a broken two-step redirect, not a working one.
 *  - `local_file` — needs `Component\Landings\LocalFile\PageWrapper`, the
 *    RUNTIME landing-page file-serving/PHP-execution engine (distinct from
 *    the already-ported Editor/Cleaner ADMIN file-management CRUD) — a
 *    substantial, security-sensitive subsystem (executes uploaded landing
 *    page PHP) that deserves its own dedicated porting session, not a
 *    quick pass in this batch.
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
        'formsubmit' => FormSubmit::class,
        'frame' => Frame::class,
        'iframe' => Iframe::class,
        'js' => Js::class,
        'js_for_iframe' => JsForIframe::class,
        'js_for_script' => JsForScript::class,
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

        if ($payload->actionType === 'http') {
            $payload->statusCode = 302;
            $payload->headers['Location'] = $payload->actionPayload;
            return $payload;
        }

        $handlerClass = self::REGISTRY[$payload->actionType] ?? null;
        if ($handlerClass === null) {
            $payload->abort(501, "Action type \"{$payload->actionType}\" not implemented in traffic-core yet (double_meta/local_file are deliberately deferred, see this class's docblock)");
            return $payload;
        }

        (new $handlerClass())->execute($payload);

        return $payload;
    }
}
