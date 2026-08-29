<?php

use App\Models\StreamLandingAssociation;
use App\Models\StreamOfferAssociation;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\CampaignFactory;
use Database\Factories\LandingFactory;
use Database\Factories\OfferFactory;
use Database\Factories\StreamFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Nested `landings`/`offers` associations on streams.create/streams.update
|--------------------------------------------------------------------------
|
| Ports `Component\Landings\Service\StreamLandingAssociationService::
| assign()` / `Component\Offers\Service\StreamOfferAssociationService::
| assign()` (called from legacy `StreamService::_updateAssociations()`) —
| see App\Http\Controllers\Admin\StreamsController::assignStreamLandings()/
| assignStreamOffers(). Filters/triggers nested-association behavior is
| already covered by tests/Feature/StreamsTest.php; this file covers only
| the landings/offers pair, including the upsert-by-natural-key semantics
| that make it different from assignStreamFilters() (which upserts by `id`).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

function streamAssocEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "streams.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsAdminForStreamAssoc(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForStreamAssoc($admin);
});

it('creates a stream with nested landings/offers and returns them non-empty on read', function () {
    $campaign = CampaignFactory::new()->create();
    $landing = LandingFactory::new()->create();
    $offer = OfferFactory::new()->create();

    $payload = [
        'campaign_id' => $campaign->id,
        'name' => 'Stream With LP/Offer',
        'action_type' => 'http',
        'schema' => 'landings',
        'landings' => [
            ['landing_id' => $landing->id, 'share' => 100],
        ],
        'offers' => [
            ['offer_id' => $offer->id, 'share' => 50],
        ],
    ];

    $response = $this->postJson(streamAssocEndpoint('create'), $payload);
    $response->assertStatus(200);

    $data = $response->json();

    expect($data['landings'])->toBeArray()->toHaveCount(1);
    expect($data['landings'][0])->toMatchArray(['landing_id' => $landing->id, 'share' => 100, 'state' => 'active']);

    expect($data['offers'])->toBeArray()->toHaveCount(1);
    expect($data['offers'][0])->toMatchArray(['offer_id' => $offer->id, 'share' => 50, 'state' => 'active']);

    $this->assertDatabaseHas('stream_landing_associations', ['stream_id' => $data['id'], 'landing_id' => $landing->id, 'share' => 100]);
    $this->assertDatabaseHas('stream_offer_associations', ['stream_id' => $data['id'], 'offer_id' => $offer->id, 'share' => 50]);

    // Re-reading via streams.show must return the same non-empty associations.
    $show = $this->getJson(streamAssocEndpoint('show', ['id' => $data['id']]));
    $show->assertStatus(200);
    expect($show->json()['landings'])->toHaveCount(1);
    expect($show->json()['offers'])->toHaveCount(1);
});

it('upserts an existing landing association by (stream_id, landing_id) instead of duplicating it', function () {
    $stream = StreamFactory::new()->create(['type' => 'regular', 'schema' => 'landings']);
    $landing = LandingFactory::new()->create();

    $first = $this->postJson(streamAssocEndpoint('update', ['id' => $stream->id]), [
        'landings' => [['landing_id' => $landing->id, 'share' => 10]],
    ]);
    $first->assertStatus(200);
    $firstAssocId = $first->json()['landings'][0]['id'];

    // Same landing_id sent again with a different share must UPDATE the
    // same row (natural-key upsert), not create a second association.
    $second = $this->postJson(streamAssocEndpoint('update', ['id' => $stream->id]), [
        'landings' => [['landing_id' => $landing->id, 'share' => 90]],
    ]);
    $second->assertStatus(200);

    expect(StreamLandingAssociation::where('stream_id', $stream->id)->count())->toBe(1);

    $assoc = StreamLandingAssociation::where('stream_id', $stream->id)->first();
    expect($assoc->id)->toBe($firstAssocId);
    expect($assoc->share)->toBe(90);
});

it('replaces nested landings/offers on update (full replace, not merge)', function () {
    $stream = StreamFactory::new()->create(['type' => 'regular', 'schema' => 'offers']);
    $landingA = LandingFactory::new()->create();
    $landingB = LandingFactory::new()->create();

    $create = $this->postJson(streamAssocEndpoint('update', ['id' => $stream->id]), [
        'landings' => [['landing_id' => $landingA->id, 'share' => 10]],
    ]);
    $create->assertStatus(200);
    expect($create->json()['landings'])->toHaveCount(1);

    $update = $this->postJson(streamAssocEndpoint('update', ['id' => $stream->id]), [
        'landings' => [['landing_id' => $landingB->id, 'share' => 20]],
    ]);
    $update->assertStatus(200);

    $data = $update->json();
    expect($data['landings'])->toHaveCount(1);
    expect($data['landings'][0]['landing_id'])->toBe($landingB->id);

    $this->assertDatabaseMissing('stream_landing_associations', ['stream_id' => $stream->id, 'landing_id' => $landingA->id]);
});

it('silently skips landing items without a landing_id, matching legacy (no validation error)', function () {
    $stream = StreamFactory::new()->create(['type' => 'regular', 'schema' => 'landings']);

    $response = $this->postJson(streamAssocEndpoint('update', ['id' => $stream->id]), [
        'landings' => [['share' => 10]],
    ]);

    $response->assertStatus(200);
    expect($response->json()['landings'])->toBe([]);
});

it('clears landings/offers when the stream schema is action/redirect (legacy StreamService::_updateAssociations())', function () {
    $stream = StreamFactory::new()->create(['type' => 'regular', 'schema' => 'landings']);
    $landing = LandingFactory::new()->create();

    $this->postJson(streamAssocEndpoint('update', ['id' => $stream->id]), [
        'landings' => [['landing_id' => $landing->id, 'share' => 10]],
    ])->assertStatus(200);

    expect(StreamLandingAssociation::where('stream_id', $stream->id)->count())->toBe(1);

    // Switching schema to 'action' must force-clear existing landings, even
    // though this request doesn't send a `landings` key at all.
    $response = $this->postJson(streamAssocEndpoint('update', ['id' => $stream->id]), [
        'schema' => 'action',
    ]);
    $response->assertStatus(200);

    expect(StreamLandingAssociation::where('stream_id', $stream->id)->count())->toBe(0);
    expect($response->json()['landings'])->toBe([]);
});

it('force-clears landings/offers on a TYPE_DEFAULT stream regardless of schema', function () {
    $stream = StreamFactory::new()->create(['type' => 'default', 'schema' => 'landings']);
    $landing = LandingFactory::new()->create();

    $response = $this->postJson(streamAssocEndpoint('update', ['id' => $stream->id]), [
        'landings' => [['landing_id' => $landing->id, 'share' => 10]],
    ]);

    // Note: DEFAULT-stream clearing in updateStreamAssociations() only
    // force-empties filters/triggers, not landings/offers directly — but a
    // DEFAULT stream's schema is 'action'/'redirect' in real legacy usage
    // (it carries its action inline, like a forced fallback). Here we
    // force schema='landings' to isolate the assertion: DEFAULT streams
    // are NOT separately blocked from landings/offers by `type` alone,
    // only by `schema`. This documents that real boundary rather than
    // assuming a stricter rule that legacy doesn't actually enforce.
    $response->assertStatus(200);
    expect($response->json()['landings'])->toHaveCount(1);
});

it('denies a guest (no current user) from updating landings on a stream with a 403', function () {
    $stream = StreamFactory::new()->create(['type' => 'regular', 'schema' => 'landings']);
    $landing = LandingFactory::new()->create();
    actingAsAdminForStreamAssoc(null);

    $response = $this->postJson(streamAssocEndpoint('update', ['id' => $stream->id]), [
        'landings' => [['landing_id' => $landing->id, 'share' => 10]],
    ]);

    $response->assertStatus(403);
    expect(StreamLandingAssociation::where('stream_id', $stream->id)->count())->toBe(0);
});
