<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Stream;
use App\Models\StreamEvent;
use App\Services\AclService;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Streams\Controller\StreamEventsController` +
 * `Component\Streams\Repository\StreamEventsRepository` +
 * `Component\StreamEvents\Service\StreamEventService` +
 * `Component\Streams\Serializer\StreamEventSerializer` (old codebase:
 * application/Component/Streams/Controller/StreamEventsController.php,
 * application/Component/Streams/Repository/StreamEventsRepository.php,
 * application/Component/StreamEvents/Service/StreamEventService.php,
 * application/Component/Streams/Serializer/StreamEventSerializer.php,
 * application/Component/Streams/Model/StreamEvent.php — table
 * `monitoring_history`, see App\Models\StreamEvent's own docblock on why
 * the table name doesn't match the API object name).
 *
 * `object=streamEvents` (see docs/legacy-reference/frontend/api/
 * 10.2_streams.md, "StreamEvents"): paginated event log for one stream.
 * Both `index` and `clear` are gated by `isViewAllowed()` on the stream's
 * parent campaign — legacy uses `isViewAllowed` for BOTH actions (verified
 * against source; NOT `isEditAllowed` for `clear`, which would be the more
 * obvious guess).
 *
 * `index` side effect (replicated deliberately, see task brief and legacy
 * source): every event on the returned page that is currently `unread` is
 * flipped to `read` as part of reading it — a "mark as read on view"
 * pattern, not a pure read. The response reflects the POST-update state
 * (legacy re-uses the same in-memory objects after `->save()`).
 *
 * `limit`/`page`: legacy reads them as plain ints with no clamping;
 * `StreamEvent::DEFAULT_LIMIT = 50` only exists as an unused constant in
 * the old model (nothing in the old controller actually falls back to it —
 * an unrequested/zero `limit` reaches the DB layer as `LIMIT 0`, which is
 * arguably a further legacy quirk). Here `limit` defaults to 50 and `page`
 * to 1 when absent/non-positive, which is the more useful behavior for a
 * real API consumer and matches what `DEFAULT_LIMIT` was clearly meant for.
 */
class StreamEventsController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated, see other Admin
    // controllers' header comments on why (not shared via a base class).
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

    private function notFound(string $message = 'Not found'): Response
    {
        return response()->json(['error' => $message, 'stacktrace' => (new \Exception($message))->getTraceAsString()], 404);
    }

    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    private function parentCampaign(Stream $stream): ?Campaign
    {
        return $stream->campaign ?? Campaign::find($stream->campaign_id);
    }

    /** Mirrors `StreamEventSerializer` ($_fields = true + formatted `date`). */
    private function serializeEvent(StreamEvent $event): array
    {
        $data = $event->getAttributes();

        if (isset($data['date'])) {
            $data['date'] = $event->date->format('Y-m-d H:i:s');
        }

        return $data;
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    private function findStreamOrFail(Request $request): Stream|Response
    {
        $streamId = (int) $this->param($request, 'stream_id');
        $stream = Stream::find($streamId);

        if (! $stream) {
            return $this->notFound('Stream not found');
        }

        $campaign = $this->parentCampaign($stream);
        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to view this stream');
        }

        return $stream;
    }

    public function indexAction(Request $request): array|Response
    {
        $stream = $this->findStreamOrFail($request);
        if ($stream instanceof Response) {
            return $stream;
        }

        $limit = (int) $this->param($request, 'limit', 0);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        $page = (int) $this->param($request, 'page', 1);
        if ($page <= 0) {
            $page = 1;
        }

        $query = StreamEvent::query()->where('stream_id', $stream->id);
        $total = (clone $query)->count();

        $items = $query
            ->orderByDesc('id')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        // Side effect: mark unread events on this page as read (see class
        // docblock) — bulk-update the DB, then reflect it on the in-memory
        // rows we're about to serialize, matching legacy's "save then
        // return the same objects" behavior.
        $unreadIds = $items->where('state', StreamEvent::UNREAD)->pluck('id');
        if ($unreadIds->isNotEmpty()) {
            StreamEvent::query()->whereIn('id', $unreadIds)->update(['state' => StreamEvent::READ]);
            $items->each(function (StreamEvent $item) use ($unreadIds) {
                if ($unreadIds->contains($item->id)) {
                    $item->state = StreamEvent::READ;
                }
            });
        }

        return [
            // Legacy returns "total" as a numeric string, not a JSON number
            // (same convention seen throughout the API — e.g. Triggers'
            // reverse/enabled — confirmed live by the contract-test suite).
            'total' => (string) $total,
            'items' => $items->map(fn (StreamEvent $e) => $this->serializeEvent($e))->values()->all(),
        ];
    }

    public function clearAction(Request $request): ?Response
    {
        $stream = $this->findStreamOrFail($request);
        if ($stream instanceof Response) {
            return $stream;
        }

        StreamEvent::query()->where('stream_id', $stream->id)->delete();

        return null;
    }
}
