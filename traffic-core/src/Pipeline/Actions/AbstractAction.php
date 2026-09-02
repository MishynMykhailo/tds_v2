<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Base for the "context-switching" action types — those whose legacy
 * `_execute()` is just `$this->_executeInContext();` (application/Traffic/
 * Actions/AbstractAction.php): BlankReferrer, Frame, Iframe, Js,
 * JsForIframe, JsForScript, Meta, Remote, ShowHtml, ShowText, DoubleMeta.
 * `Curl`, `DoNothing`, `FormSubmit`,
 * `Status404`, `SubId` do NOT extend this — legacy's `_execute()` for
 * those bypasses `_executeInContext()` entirely, they implement
 * `ActionHandler` directly instead.
 *
 * legacy's `_executeInContext()` picks one of three render modes based on
 * a query param whose NAME starts with "frm" (added only by the embed
 * code-presets — `Component/CampaignIntegration/data/code_presets.php`,
 * e.g. `add_params => "frm=script"`/`"frm=frame"` — never present on a
 * plain tracking-link click, which is what `ClickDispatcher`/this port
 * models):
 *
 * ```php
 * $from = NULL;
 * foreach ($this->getServerRequest()->getQueryParams() as $paramName => $paramValue) {
 *     if (strpos($paramName, "frm") === 0) {
 *         $from = $paramValue;
 *         if (strpos($from, "script") === 0) {
 *             if (strpos($from, "frame") === 0) {
 *                 $this->_executeDefault();          // dead: a string can't start with both "script" and "frame"
 *             } else {
 *                 $this->_executeForFrame();          // frm=script -> ForFrame (swapped)
 *             }
 *         } else {
 *             $this->_executeForScript();             // frm=frame  -> ForScript (swapped)
 *         }
 *     }
 * }
 * ```
 *
 * **CONFIRMED BUG (live-verified against the running legacy app, not just
 * read statically — see docs/PORTING_LOG.md for the exact curl evidence),
 * FIXED here, not reproduced:**
 *  1. `_executeDefault()` is unreachable dead code — no string can start
 *     with both "script" and "frame" — so on a PLAIN click (no `frm*`
 *     param at all, the only case this port's `ClickDispatcher` produces)
 *     `_executeInContext()` does nothing at all: verified live, both
 *     `frame` and `js` actions return an EMPTY 200 body on a plain click
 *     against the real running legacy app (`tds-app`, port 8090, campaign
 *     alias `frmtest1`, temporary fixture, removed after).
 *  2. The `frm=script`/`frm=frame` branches are swapped versus their
 *     method names — verified live: `frm=frame` on a `js`-action stream
 *     returned the *`_executeForScript()`* body (bare JS function, no
 *     `<script>` wrapper); `frm=script` returned the *`_executeForFrame()`*
 *     body (`RedirectService::frameRedirect()`'s `<script>`-wrapped
 *     `top.location` snippet). Same swap independently confirmed on
 *     `Component\StreamActions\AbstractAction` (application/Traffic/
 *     BackCompatibility/classes/AbstractAction.php), the back-compat
 *     custom-action base class — identical logic duplicated there, so this
 *     is the app's real, consistent (if broken) behavior, not a one-off
 *     typo.
 *
 * Fix applied here: no `frm*` param -> call `executeDefault()` directly
 * (this is what a plain tracking-link click needs, and what the class
 * hierarchy's naming clearly intends); `frm` present and starts with
 * "script" -> `executeForScript()`; otherwise -> `executeForFrame()`
 * (un-swapped). Since traffic-core doesn't emit `frm` params anywhere yet
 * (no embed/JS-client flow ported — see docs/TRAFFIC_CORE_PLAN.md), only
 * the first branch is reachable today; the second is fixed for
 * forward-compatibility, whenever that flow gets ported.
 */
abstract class AbstractAction implements ActionHandler
{
    public function execute(Payload $payload): void
    {
        $from = $this->contextParam($payload);

        if ($from === null) {
            $this->executeDefault($payload);
            return;
        }

        if (str_starts_with($from, 'script')) {
            $this->executeForScript($payload);
        } else {
            $this->executeForFrame($payload);
        }
    }

    abstract protected function executeDefault(Payload $payload): void;

    /**
     * Legacy's generic fallback (application/Traffic/Actions/
     * AbstractAction.php::_executeForFrame()) for classes that don't
     * override it — locale key `stream_actions.action_incompatible`,
     * exact English string confirmed live against the running legacy app.
     */
    protected function executeForFrame(Payload $payload): void
    {
        $payload->body = '<script>window.console && console.error("This action is incompatible with current integration method. Please choose a more appropriate action in the stream.");</script>';
    }

    /**
     * Legacy's generic fallback (::_executeForScript()) for classes that
     * don't override it.
     */
    protected function executeForScript(Payload $payload): void
    {
        $payload->headers['Content-Type'] = 'application/javascript; charset=utf-8';
        $payload->body = 'window.console && console.error("This action is incompatible with current integration method. Please choose a more appropriate action in the stream.");';
    }

    private function contextParam(Payload $payload): ?string
    {
        $from = null;
        foreach ($payload->request->getQueryParams() as $paramName => $paramValue) {
            if (str_starts_with((string) $paramName, 'frm')) {
                $from = (string) $paramValue;
            }
        }

        return $from;
    }
}
