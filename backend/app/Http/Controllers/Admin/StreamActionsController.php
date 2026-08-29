<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Compatibility port of the legacy
 * `Component\StreamActions\Controller\StreamActionsController` +
 * `Traffic\Actions\Repository\StreamActionRepository::getListAsOptions()`
 * (old codebase: application/Component/StreamActions/Controller/
 * StreamActionsController.php, application/Traffic/Actions/Repository/
 * StreamActionRepository.php, application/Traffic/Actions/Predefined/*.php,
 * one class per action).
 *
 * `object=streamActions.index` — static catalogue of the direct-action
 * types available when a stream's `schema` is `action` (see
 * docs/legacy-reference/frontend/api/10.2_streams.md, "StreamActions").
 *
 * The old repository registers 19 built-in actions (`register()` calls in
 * `_registerBuiltInActions()`), aliases a few of them (`build_html`/
 * `return_html` -> `show_html`, `group` -> `campaign`, `echo` -> `show_text`,
 * `location` -> `http`), then `getListAsOptions()` drops alias keys plus an
 * explicit exclude list (`example`, `build_html`, `sub_id`, and
 * conditionally `hide_click_detect`) and sorts what's left by `getWeight()`
 * ascending. The result is 18 entries — NOT the 10-item list a first read
 * of the task brief suggests (that list conflates several things: it uses
 * `404`/`to_campaign` instead of the real keys `status404`/`campaign`, and
 * omits `blank_referrer`, `double_meta`, `formsubmit`, `http`, `js`,
 * `js_for_iframe`, `js_for_script`, `meta`, `remote` while including
 * `sub_id`, which the old repository explicitly excludes from this list).
 * Verified against source, one file per action, under
 * application/Traffic/Actions/Predefined/*.php.
 *
 * NOTE — legacy bug found while porting: `AbstractAction::getName()`
 * returns `$this->_name`, which is only ever set by `setInfo($name,
 * $weight)` — a method NEVER called anywhere in the old codebase (verified
 * via repo-wide grep). So the old backend's own `getListAsOptions()`
 * actually always returns `"name": null` for every action; the real
 * display strings only exist client-side / in the `stream_actions.actions.*`
 * translation file. Rather than reproduce that bug, `name` below is
 * populated from those same legacy English translation strings (old
 * codebase: application/Component/StreamActions/translations/en.php) so
 * the catalogue is actually usable. `local_file` has neither a weight nor a
 * translation entry in the old code either (it's the one action with a
 * `field` other than a plain redirect/text — landing-page uploads); it's
 * placed first (weight treated as 0, matching the old code's `null < int`
 * loose-comparison sort behavior) and given a plain fallback name.
 */
class StreamActionsController extends Controller
{
    public function indexAction(Request $request): array
    {
        return $this->options();
    }

    /**
     * @return list<array{key: string, name: string, field: string, type: string, description: string}>
     */
    private function options(): array
    {
        $rows = [
            // key, name, field, type, description
            ['local_file', 'Local file', 'upload', 'hidden', ''],
            ['http', 'HTTP redirect', 'url', 'redirect', 'Recommended. Simple and the most reliable redirect'],
            ['js', 'JS redirect', 'url', 'redirect', 'Use JS to perform redirect'],
            ['meta', 'Meta redirect', 'url', 'redirect', "Redirects by using 'meta' HTML tag"],
            ['blank_referrer', 'Redirect without Referer', 'url', 'redirect', "Redirect that doesn't send Referer"],
            ['curl', 'CURL', 'url', 'redirect', 'Help to load external page without actually redirecting'],
            ['double_meta', 'Double meta redirect', 'url', 'redirect', 'Two-step redirect that helps hide source from the destination website'],
            ['formsubmit', 'FormSubmit', 'url', 'redirect', 'The way to perform redirect with POST-data'],
            ['campaign', 'Send to campaign', 'campaigns', 'other', 'Send to another campaign (duplictes clicks for every campaign)'],
            ['iframe', 'Open in iframe', 'url', 'redirect', 'Show page in a iframe'],
            ['status404', '404 NotFound', 'nothing', 'other', "Show blank page with error '404 Not Found'"],
            ['show_html', 'Show as HTML', 'text', 'other', 'Show HTML content'],
            ['show_text', 'Show as text', 'text', 'other', 'Show plain text content'],
            ['do_nothing', 'Do nothing', 'nothing', 'other', 'Just leave the user where he is now'],
            ['remote', 'REMOTE', 'url', 'redirect', 'Two-step action: load the page content then use it as the next URL for redirecting'],
            ['frame', 'Open in frameset (outdated)', 'url', 'redirect', 'Show page in a frameset'],
            ['js_for_script', 'Redirect for script (Deprecated)', 'url', 'redirect', 'Use "JS redirect" instead'],
            ['js_for_iframe', 'Redirect for iframe (Deprecated)', 'url', 'redirect', 'Use "JS redirect" instead'],
        ];

        return array_map(
            fn (array $row) => ['key' => $row[0], 'name' => $row[1], 'field' => $row[2], 'type' => $row[3], 'description' => $row[4]],
            $rows
        );
    }
}
