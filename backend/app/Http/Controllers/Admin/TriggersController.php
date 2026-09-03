<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Stream;
use App\Models\Trigger;
use App\Services\AclService;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Triggers\Controller\TriggersController` (old codebase:
 * application/Component/Triggers/Controller/TriggersController.php +
 * application/Component/Triggers/Repository/TriggersRepository.php +
 * application/Component/Triggers/Service/StreamTriggerService.php +
 * application/Component/Triggers/Model/TriggerAssociation.php).
 *
 * `object=triggers` is mostly reference data for the trigger-condition
 * builder UI (`targets`/`conditions`/`actions`, all static enums — see
 * docs/legacy-reference/frontend/api/10.2_streams.md, "Triggers"), plus one
 * real write action: `update`, which replaces ALL of a stream's triggers
 * with the given list (`{id}` in the request is the STREAM id, per legacy
 * `getParam("id")` in `updateAction()` — NOT a trigger id).
 *
 * `assignTriggers()` is public and stateless w.r.t. the HTTP layer so
 * StreamsController can call it directly for nested `triggers: [...]` on
 * `streams.create`/`streams.update` (mirrors legacy `StreamService::
 * _updateAssociations()` calling `StreamTriggerService::assign()` directly,
 * and this codebase's established cross-controller-call convention, see
 * CampaignsController::saveNestedStreams() -> StreamsController::
 * createStreamRecord()/updateStreamRecord()).
 */
class TriggersController extends Controller
{
    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated from
    // StreamsController rather than shared via inheritance, matching this
    // codebase's established convention (see StreamsController's own
    // header comment on why these helpers are duplicated rather than
    // extracted into a shared base).
    // ---------------------------------------------------------------

    private function parsedBody(Request $request): ?array
    {
        static $cache = null;
        static $cachedFor = null;

        if ($cachedFor === $request) {
            return $cache;
        }

        $raw = $request->getContent();
        $trimmed = is_string($raw) ? ltrim($raw) : '';

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            $result = is_array($decoded) ? $decoded : null;
        } elseif (is_string($raw) && str_contains($raw, '&')) {
            parse_str($raw, $parsed);
            $result = $parsed;
        } else {
            $result = null;
        }

        $cachedFor = $request;
        $cache = $result;

        return $result;
    }

    private function param(Request $request, string $name, $default = null)
    {
        if ($request->query->has($name)) {
            return $request->query->get($name);
        }

        $body = $this->parsedBody($request);
        if (is_array($body) && array_key_exists($name, $body)) {
            return $body[$name];
        }

        return $default;
    }

    private function postParam(Request $request, string $name, $default = null)
    {
        $body = $this->parsedBody($request);

        return is_array($body) && array_key_exists($name, $body) ? $body[$name] : $default;
    }

    private function isPost(Request $request): bool
    {
        return ! empty($this->parsedBody($request)) || $request->isMethod('post');
    }

    private function notFound(string $message = 'Not found'): Response
    {
        return response()->json(['error' => $message, 'stacktrace' => (new \Exception($message))->getTraceAsString()], 404);
    }

    private function validationError(array $errors): Response
    {
        return response()->json($errors, 406);
    }

    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    // ---------------------------------------------------------------
    // Reference-data actions (TriggersRepository::getTargets()/
    // getConditions()/getActions() — translated (English) labels keyed by
    // the TriggerAssociation::TARGET_*/CONDITION_*/ACTION_* constants).
    // ---------------------------------------------------------------

    public function targetsAction(Request $request): array
    {
        return [
            'stream' => 'URL of stream',
            'landings' => 'Landing Pages',
            'offers' => 'Offers',
            'selected_page' => 'Another URL',
        ];
    }

    public function conditionsAction(Request $request): array
    {
        return [
            'not_respond' => "Doesn't respond",
            'contains' => 'Contains',
            'not_contains' => "Doesn't contain",
            'av_detected' => 'Detects by Anti-Viruses',
            'always' => 'Always',
        ];
    }

    public function actionsAction(Request $request): array
    {
        return [
            'disable' => 'Disable stream',
            'grab_from_page' => 'Grab a new URL from page',
            'replace_url' => 'Replace to',
            'do_nothing' => 'Do nothing',
            'webhook' => 'WebHook',
        ];
    }

    /** Legacy `$_validTargets`/`$_validConditions`/`$_validActions` (TriggersRepository). */
    public const VALID_TARGETS = ['stream', 'landings', 'offers', 'selected_page'];

    public const VALID_CONDITIONS = ['not_respond', 'contains', 'not_contains', 'av_detected', 'always'];

    public const VALID_ACTIONS = ['disable', 'grab_from_page', 'replace_url', 'do_nothing', 'webhook'];

    // ---------------------------------------------------------------
    // update — replaces all of a stream's triggers.
    // ---------------------------------------------------------------

    public function updateAction(Request $request): Response
    {
        $streamId = $this->param($request, 'id');

        if (empty($streamId)) {
            return $this->notFound('Stream not found');
        }

        $stream = Stream::find((int) $streamId);

        if (! $stream) {
            return $this->notFound('Stream not found');
        }

        $campaign = $stream->campaign ?? Campaign::find($stream->campaign_id);
        if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to edit this stream');
        }

        if (! $this->isPost($request)) {
            return response()->json(null);
        }

        $items = $this->postParam($request, 'triggers', []);
        if (! is_array($items)) {
            $items = [];
        }

        $result = $this->assignTriggers($stream, $items);

        if (isset($result['errors'])) {
            return $this->validationError($result['errors']);
        }

        return response()->json($result['triggers']->map(fn (Trigger $t) => $this->serializeTrigger($t))->values()->all());
    }

    /**
     * Mirrors `StreamTriggerService::assign()`: for each item, update the
     * existing trigger (by `id`, scoped to this stream) or create a new
     * one; any of the stream's existing triggers NOT present in the
     * incoming list are deleted (simple full-replace, no diffing).
     *
     * @param  array<int, mixed>  $items
     * @return array{triggers?: \Illuminate\Support\Collection<int, Trigger>, errors?: array}
     */
    public function assignTriggers(Stream $stream, array $items): array
    {
        $keptIds = [];

        foreach ($items as $data) {
            if (! is_array($data)) {
                continue;
            }

            $errors = $this->validateTriggerParams($data);
            if (! empty($errors)) {
                return ['errors' => $errors];
            }

            $trigger = null;
            if (! empty($data['id'])) {
                $trigger = Trigger::where('id', (int) $data['id'])
                    ->where('stream_id', $stream->id)
                    ->first();
            }

            if (! $trigger) {
                $trigger = new Trigger();
                $trigger->stream_id = $stream->id;
            }

            $trigger->fill($this->fillableParams($data));
            $trigger->stream_id = $stream->id;
            $trigger->save();

            $keptIds[] = $trigger->id;
        }

        // whereNotIn() with an empty array matches every row (Laravel
        // compiles it to a constant-true condition), so an empty $items
        // list correctly deletes ALL of the stream's existing triggers —
        // matching legacy `StreamTriggerService::assign()` behavior when
        // called with an empty/absent `triggers` list.
        Trigger::where('stream_id', $stream->id)
            ->whereNotIn('id', $keptIds)
            ->delete();

        $triggers = Trigger::where('stream_id', $stream->id)->orderBy('id')->get();

        return ['triggers' => $triggers];
    }

    /**
     * Mirrors `TriggerValidator`: required `target`/`condition`/`action`/
     * `interval` (`stream_id` is always controller-injected, not
     * re-checked), plus the `in` enum checks against
     * `TriggersRepository::getValid{Targets,Conditions,Actions}()`.
     */
    private function validateTriggerParams(array $params): array
    {
        $errors = [];

        foreach (['target', 'condition', 'action', 'interval'] as $field) {
            if (! array_key_exists($field, $params) || trim((string) $params[$field]) === '') {
                $errors[$field] = ["The {$field} field is required."];
            }
        }

        if (array_key_exists('target', $params) && ! in_array($params['target'], self::VALID_TARGETS, true)) {
            $errors['target'] = ['Invalid target.'];
        }
        if (array_key_exists('condition', $params) && ! in_array($params['condition'], self::VALID_CONDITIONS, true)) {
            $errors['condition'] = ['Invalid condition.'];
        }
        if (array_key_exists('action', $params) && ! in_array($params['action'], self::VALID_ACTIONS, true)) {
            $errors['action'] = ['Invalid action.'];
        }

        return $errors;
    }

    private function fillableParams(array $params): array
    {
        return array_intersect_key($params, array_flip((new Trigger())->getFillable()));
    }

    /** Mirrors `StreamTriggerSerializer` ($_fields = true + `oid` = id). */
    public function serializeTrigger(Trigger $trigger): array
    {
        $data = $trigger->getAttributes();

        foreach (['reverse', 'enabled', 'scan_page'] as $boolField) {
            if (array_key_exists($boolField, $data)) {
                $data[$boolField] = (bool) $data[$boolField];
            }
        }

        $data['oid'] = $data['id'];

        return $data;
    }
}
