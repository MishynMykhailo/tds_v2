<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\FavouriteStream;
use App\Models\Stream;
use App\Models\StreamFilter;
use App\Models\Trigger;
use App\Services\AclService;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Streams\Controller\FavouriteStreamsController` +
 * `Component\Streams\Service\FavouriteStreamService` +
 * `Component\Streams\Repository\FavouriteStreamRepository` (old codebase:
 * application/Component/Streams/Controller/FavouriteStreamsController.php,
 * application/Component/Streams/Service/FavouriteStreamService.php,
 * application/Component/Streams/Repository/FavouriteStreamRepository.php,
 * application/Component/Streams/Model/FavouriteStream.php — table
 * `favourite_streams`).
 *
 * `object=favouriteStreams` (see docs/legacy-reference/frontend/api/
 * 10.2_streams.md, "FavouriteStreams"): favourite streams of the CURRENT
 * user (`CurrentUserService`), full stream objects on `index` (legacy
 * `getFavouriteStreams()` re-loads the actual Stream rows and serializes
 * them with the same `StreamSerializer` as `streams.index`, ordered by
 * `name` — it does NOT just return stream ids), `add`/`remove` take
 * `stream_id` and are gated by `isEditAllowed()` on the stream's PARENT
 * CAMPAIGN (legacy uses `isEditAllowed` for both, not `isViewAllowed` —
 * verified against source).
 *
 * `index` itself has NO per-favourite ACL re-check in legacy (a favourite
 * pointing at a stream from a campaign the user has since lost access to
 * would still be returned) — replicated as-is.
 *
 * `add` is idempotent in legacy: `FavouriteStreamService::addStream()`
 * catches the unique-constraint `ADODB_Exception` from a duplicate insert
 * and swallows it silently; `FavouriteStream::firstOrCreate()` below gives
 * the same idempotent behavior. `remove` deletes unconditionally, silently
 * doing nothing if the favourite didn't exist (legacy `deleteMany()` is a
 * no-op WHERE match on zero rows too).
 *
 * The `serializeStream()`/`serializeStreamFilter()` helpers below duplicate
 * (rather than reuse) StreamsController's private equivalents, per the
 * "don't touch other controllers" scope for this batch and this codebase's
 * established convention of duplicating param/serialization helpers across
 * controllers instead of extracting a shared base (see StreamsController's
 * and TriggersController's own header comments on this). `serializeTrigger`
 * IS reused via `app(TriggersController::class)`, matching the existing
 * cross-controller-call convention (see StreamsController::serializeStream()
 * doing the same).
 */
class FavouriteStreamsController extends Controller
{
    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated, see class docblock.
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
        return response()->json(['error' => $message, 'stacktrace' => ''], 404);
    }

    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    private function parentCampaign(Stream $stream): ?Campaign
    {
        return $stream->campaign ?? Campaign::find($stream->campaign_id);
    }

    private function decodeActionOptions($raw)
    {
        return is_string($raw) ? json_decode($raw, true) : $raw;
    }

    private function serializeStreamFilter(StreamFilter $filter): array
    {
        $data = $filter->getAttributes();

        if (array_key_exists('payload', $data)) {
            $data['payload'] = $this->decodeActionOptions($data['payload']);
        }

        if (in_array($data['name'] ?? null, ['uniqueness_cookie', 'uniqueness_ip'], true)) {
            $data['name'] = 'uniqueness';
        }

        $data['oid'] = $data['id'];

        return $data;
    }

    /** Mirrors StreamsController::serializeStream() — see class docblock. */
    private function serializeStream(Stream $stream): array
    {
        $stream->refresh();

        $data = $stream->getAttributes();

        if (array_key_exists('action_options', $data)) {
            $data['action_options'] = $this->decodeActionOptions($data['action_options']);
        }

        foreach (['collect_clicks', 'filter_or'] as $boolField) {
            if (array_key_exists($boolField, $data)) {
                $data[$boolField] = (bool) $data[$boolField];
            }
        }

        unset($data['landing_id'], $data['offer_id'], $data['status'], $data['updated_at']);

        if (isset($data['created_at']) && $data['created_at'] instanceof \DateTimeInterface) {
            $data['created_at'] = Carbon::instance($data['created_at'])->toDateTimeString();
        }

        $data['filters'] = $stream->filters()->orderBy('id')->get()
            ->map(fn (StreamFilter $f) => $this->serializeStreamFilter($f))->values()->all();
        $data['triggers'] = $stream->triggers()->orderBy('id')->get()
            ->map(fn (Trigger $t) => app(TriggersController::class)->serializeTrigger($t))->values()->all();
        $data['landings'] = [];
        $data['offers'] = [];

        return $data;
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    public function indexAction(Request $request): array
    {
        $user = $this->currentUserService->get();

        if (! $user) {
            return [];
        }

        $streamIds = FavouriteStream::query()->where('user_id', $user->id)->pluck('stream_id');

        if ($streamIds->isEmpty()) {
            return [];
        }

        $streams = Stream::query()->whereIn('id', $streamIds)->orderBy('name')->get();

        return $streams->map(fn (Stream $s) => $this->serializeStream($s))->values()->all();
    }

    public function addAction(Request $request): ?Response
    {
        $user = $this->currentUserService->get();
        $streamId = (int) $this->param($request, 'stream_id');

        $stream = Stream::find($streamId);
        if (! $stream) {
            return $this->notFound('Stream not found');
        }

        $campaign = $this->parentCampaign($stream);
        if (! $this->aclService->isEditAllowed($user, $campaign)) {
            return $this->forbidden('You are not allowed to edit this stream');
        }

        FavouriteStream::query()->firstOrCreate([
            'user_id' => $user->id,
            'stream_id' => $stream->id,
        ]);

        return null;
    }

    public function removeAction(Request $request): ?Response
    {
        $user = $this->currentUserService->get();
        $streamId = (int) $this->param($request, 'stream_id');

        $stream = Stream::find($streamId);
        if (! $stream) {
            return $this->notFound('Stream not found');
        }

        $campaign = $this->parentCampaign($stream);
        if (! $this->aclService->isEditAllowed($user, $campaign)) {
            return $this->forbidden('You are not allowed to edit this stream');
        }

        FavouriteStream::query()
            ->where('user_id', $user->id)
            ->where('stream_id', $stream->id)
            ->delete();

        return null;
    }
}
