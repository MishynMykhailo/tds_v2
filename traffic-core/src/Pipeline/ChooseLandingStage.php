<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;

/**
 * Port of legacy `Traffic\Pipeline\Stage\ChooseLandingStage`
 * (application/Traffic/Pipeline/Stage/ChooseLandingStage.php) — Phase 3.
 *
 * Ported: skip unless `stream.schema` is `landings`/`offers`; pick a
 * landing via `LandingOfferRotator` over `stream_landing_associations`
 * for this stream; if one is chosen, set `actionType`/`actionPayload`/
 * `actionOptions` from it directly (faithful — legacy's `_updatePayload()`
 * does this unconditionally too, no gating flag involved) plus
 * `payload.landingId` (used by `BuildRawClickStage` for `clicks.landing_id`).
 *
 * NOT ported: a pre-selected/"current" landing short-circuit (legacy
 * `$currentLanding` — only relevant once forced-landing query params
 * exist, which they don't here), entity binding / sticky visitor
 * selection (Redis), `needToken`/`addTokenToUrl` side effects (token flow
 * not implemented — see ChooseOfferStage's docblock for the related
 * offer-side deviation).
 */
class ChooseLandingStage
{
    public function process(Payload $payload): Payload
    {
        $stream = $payload->stream;

        if ($stream === null || !in_array($stream['schema'], ['landings', 'offers'], true)) {
            return $payload;
        }

        $pdo = Db::instance();
        $stmt = $pdo->prepare('SELECT * FROM stream_landing_associations WHERE stream_id = ?');
        $stmt->execute([$stream['id']]);
        $associations = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if (empty($associations)) {
            return $payload;
        }

        $landing = (new LandingOfferRotator())->getRandom($associations, 'landings', 'landing_id');

        if ($landing === null) {
            return $payload;
        }

        $payload->landingId = (int) $landing['id'];
        $payload->actionType = $landing['action_type'];
        $payload->actionPayload = $landing['action_payload'];
        $payload->actionOptions = $landing['action_options'] ?? null;

        return $payload;
    }
}
