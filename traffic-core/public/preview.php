<?php

/**
 * New entry point (no direct legacy equivalent that was ever actually
 * built — see `docs/default/TODO_IMPROVEMENTS.md` in the legacy source,
 * "[НЕ СДЕЛАНО] Превью оффера/лендинга прямо из админки": the idea
 * — `?object=landings.preview`/`offers.preview` bypassing the campaign/
 * stream pipeline, gated to an authenticated admin session — was
 * documented but never implemented in the legacy codebase this project
 * ports from).
 *
 * Renders a `local_file` landing/offer's content directly by id, with
 * NO domain/campaign/stream resolution — reuses the exact same
 * `TrafficCore\Pipeline\Actions\LocalFile` handler a real click would
 * hit (same sandbox, same HTML path adaptation, same macro
 * substitution — `rawClick`/`campaign`/`stream`/etc. on the `Payload`
 * simply stay at their empty defaults, which every consumer already
 * null-coalesces safely).
 *
 * Auth: `backend/` (a separate Composer project, no shared code — see
 * docs/ARCHITECTURE_PLAN.md) can't call into this process directly, so
 * access is gated by a short-lived HMAC token instead — same pattern
 * already established for `JWT_SALT` (`LpTokenKey`/`DomainService`):
 * `token = hash_hmac('sha256', "{type}:{id}:{expires}", PREVIEW_SECRET)`.
 * `backend/`'s `LandingsController::previewAction()`/`OffersController::
 * previewAction()` mint this token only after its own `isViewAllowed()`
 * ACL check passes — this endpoint trusts a valid, unexpired token as
 * proof of that, the same way a real click trusts nothing (public by
 * design) but this preview path is deliberately NOT public.
 *
 * `PREVIEW_SECRET` env var, `getenv('PREVIEW_SECRET') ?: '...dev
 * default...'` — same fallback-for-dev convention as `JWT_SALT`, MUST
 * be overridden (and match `backend/.env`'s value) outside local dev.
 */

require __DIR__.'/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use TrafficCore\Db;
use TrafficCore\Pipeline\Actions\LocalFile;
use TrafficCore\Pipeline\Payload;
use TrafficCore\Pipeline\Signal;

const PREVIEW_ALLOWED_TYPES = ['landing' => 'landings', 'offer' => 'offers'];

function previewSecret(): string
{
    return getenv('PREVIEW_SECRET') ?: 'tds_v2-dev-only-preview-secret-override-via-PREVIEW_SECRET-env';
}

function previewRespondError(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain');
    echo $message;
    exit;
}

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
$request = $creator->fromGlobals();
$params = $request->getQueryParams();

$type = (string) ($params['type'] ?? '');
$id = (int) ($params['id'] ?? 0);
$expires = (int) ($params['expires'] ?? 0);
$token = (string) ($params['token'] ?? '');

if (!isset(PREVIEW_ALLOWED_TYPES[$type]) || $id <= 0 || $token === '') {
    previewRespondError(400, 'Bad preview request');
}

if ($expires < time()) {
    previewRespondError(403, 'Preview link expired');
}

$expected = hash_hmac('sha256', "{$type}:{$id}:{$expires}", previewSecret());
if (!hash_equals($expected, $token)) {
    previewRespondError(403, 'Invalid preview token');
}

$table = PREVIEW_ALLOWED_TYPES[$type];
$stmt = Db::instance()->prepare("SELECT action_type, action_options FROM {$table} WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);

if ($row === false) {
    previewRespondError(404, ucfirst($type).' not found');
}

if ($row['action_type'] !== 'local_file') {
    previewRespondError(422, ucfirst($type)." is not a local_file {$type} — nothing to preview");
}

$payload = new Payload($request);
$payload->signal = Signal::fromRequest($request);
$payload->actionType = $row['action_type'];
$payload->actionOptions = $row['action_options'];

(new LocalFile())->execute($payload);

http_response_code($payload->statusCode);
foreach ($payload->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo $payload->body;
