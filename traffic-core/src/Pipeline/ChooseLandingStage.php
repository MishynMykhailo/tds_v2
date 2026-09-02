<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;
use TrafficCore\Uniqueness\EntityBindingService;

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
 * Phase 13: sticky-landing binding is now real — see
 * `LandingOfferRotator`'s own docblock.
 *
 * Phase 17: `needToken` IS now ported, narrowly — legacy's real trigger
 * (`ChooseLandingStage::_updatePayload()`, application/Traffic/Pipeline/
 * Stage/ChooseLandingStage.php): `if ($payload->getStream() &&
 * ...hasCachedOffers($payload->getStream())) { setNeedToken(true);
 * setAddTokenToUrl(true); }` — i.e. a chosen landing whose STREAM has
 * offers configured behind it (`stream_offer_associations` non-empty)
 * gets a token regardless of whether an offer was picked yet, so
 * `public/landing-offer.php` (the later "offer requested separately"
 * hop) has something to restore by. `setAddTokenToUrl()` stays
 * unported — see `GenerateTokenStage`'s docblock, still a verified
 * no-op in every flow this project models (a landing page reads the
 * token from `?_token=` this stage's SIBLING already put in `payload`,
 * not from its own served URL).
 *
 * NOT ported: a pre-selected/"current" landing short-circuit (legacy
 * `$currentLanding` — only relevant once forced-landing query params
 * exist, which they don't here).
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

        $landing = (new LandingOfferRotator())->getRandom(
            $associations,
            'landings',
            'landing_id',
            $payload->campaign,
            $payload->signal,
            EntityBindingService::TYPE_LANDING,
        );

        if ($landing === null) {
            return $payload;
        }

        $payload->landingId = (int) $landing['id'];
        $payload->actionType = $landing['action_type'];
        $payload->actionPayload = $landing['action_payload'];
        $payload->actionOptions = $landing['action_options'] ?? null;

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM stream_offer_associations WHERE stream_id = ?');
        $countStmt->execute([$stream['id']]);
        if ((int) $countStmt->fetchColumn() > 0) {
            $payload->needToken = true;
        }

        return $payload;
    }
}
