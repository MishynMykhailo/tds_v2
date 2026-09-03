<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FavouriteReport;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy `Component\Reports\Controller\
 * FavouriteReportController` + `Component\Reports\Repository\
 * FavouriteReportRepository` + `Component\Reports\Service\
 * FavouriteBookmarkService` + `Component\Reports\Serializer\
 * FavouriteReportSerializer` (old codebase: application/Component/Reports/
 * Controller/FavouriteReportController.php,
 * application/Component/Reports/Repository/FavouriteReportRepository.php,
 * application/Component/Reports/Service/FavouriteBookmarkService.php,
 * application/Component/Reports/Serializer/FavouriteReportSerializer.php).
 * Registered as `object=favouriteReports` even though the legacy class
 * physically lives inside the Reports module — it is its own controller/
 * object key there, not a `reports.*` action.
 *
 * `favourite_reports` rows are bookmarked report configurations belonging
 * to exactly one user (`user_id`); there is no ACL/campaign check anywhere
 * in the legacy contract — the only access rule is per-row ownership
 * (`FavouriteReportRepository::findByUser()` scopes every lookup by
 * `user_id = :currentUserId`, throwing legacy `NotFoundError` — a 404 here —
 * for a missing OR someone-else's row; it never distinguishes the two,
 * replicated as-is below).
 *
 * `payload` (application/data/schema.sql: `tds_favourite_reports.payload`
 * is a plain `text` column, NOT a JSON column type) is stored and returned
 * completely opaque/verbatim — legacy `FavouriteReportSerializer` has
 * `$_fields = true` with no `payload`-specific decode, so unlike e.g.
 * StreamFilter's `payload` (a real JSON column, decoded on serialize by
 * FavouriteStreamsController::decodeActionOptions()) this one is never
 * json_decode()'d server-side at all. If the caller sends a non-string
 * (e.g. a parsed JSON object) it is json_encode()'d before saving purely so
 * the NOT NULL text column always gets a string; a string payload is
 * stored byte-for-byte untouched either way.
 */
class FavouriteReportController extends Controller
{
    public function __construct(
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated per-controller
    // convention (see TriggersController/UserPreferencesController headers).
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

    private function notFound(string $message = 'Report is not found'): Response
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

    /** Mirrors `FavouriteReportSerializer` ($_fields = true, extra() unsets user_id). */
    private function serializeReport(FavouriteReport $report): array
    {
        $data = $report->getAttributes();
        $data['is_shared'] = (bool) ($data['is_shared'] ?? false);
        unset($data['user_id']);

        return $data;
    }

    /** @return array{name?: array<string>, payload?: array<string>} */
    private function validateParams(?string $name, $payload): array
    {
        $errors = [];

        if ($name === null || trim((string) $name) === '') {
            $errors['name'] = ['The name field is required.'];
        } elseif (strlen((string) $name) > 50) {
            $errors['name'] = ['The name may not be greater than 50 characters.'];
        }

        if ($payload === null || $payload === '') {
            $errors['payload'] = ['The payload field is required.'];
        }

        return $errors;
    }

    private function normalizePayload($payload): string
    {
        return is_string($payload) ? $payload : json_encode($payload);
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    /** All favourite reports of the CURRENT user (legacy `allByUser()`, ordered by name). */
    public function indexAction(Request $request): array
    {
        $user = $this->currentUserService->get();
        if (! $user) {
            return [];
        }

        $reports = FavouriteReport::query()->where('user_id', $user->id)->orderBy('name')->get();

        return $reports->map(fn (FavouriteReport $r) => $this->serializeReport($r))->values()->all();
    }

    public function createAction(Request $request): Response
    {
        $user = $this->currentUserService->get();
        if (! $user) {
            return $this->forbidden('You must be logged in');
        }

        $name = $this->param($request, 'name');
        $payload = $this->param($request, 'payload');
        $isShared = $this->param($request, 'is_shared', false);

        $errors = $this->validateParams($name, $payload);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $report = FavouriteReport::create([
            'name' => $name,
            'user_id' => $user->id,
            'is_shared' => (bool) $isShared,
            'payload' => $this->normalizePayload($payload),
        ]);

        return response()->json($this->serializeReport($report));
    }

    public function updateAction(Request $request): Response
    {
        $user = $this->currentUserService->get();
        if (! $user) {
            return $this->forbidden('You must be logged in');
        }

        $id = (int) $this->param($request, 'id');
        $report = FavouriteReport::query()->where('user_id', $user->id)->where('id', $id)->first();
        if (! $report) {
            return $this->notFound();
        }

        $name = $this->param($request, 'name', $report->name);
        $payload = $this->param($request, 'payload', $report->payload);
        $isShared = $this->param($request, 'is_shared', $report->is_shared);

        $errors = $this->validateParams($name, $payload);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $report->update([
            'name' => $name,
            'is_shared' => (bool) $isShared,
            'payload' => $this->normalizePayload($payload),
        ]);

        return response()->json($this->serializeReport($report));
    }

    public function deleteAction(Request $request): ?Response
    {
        $user = $this->currentUserService->get();
        if (! $user) {
            return $this->forbidden('You must be logged in');
        }

        $id = (int) $this->param($request, 'id');
        $report = FavouriteReport::query()->where('user_id', $user->id)->where('id', $id)->first();
        if (! $report) {
            return $this->notFound();
        }

        $report->delete();

        return null;
    }
}
