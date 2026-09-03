<?php

use App\Models\Offer;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\OfferFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Offers compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=offers.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and the
| App\Http\Controllers\Admin\OffersController being ported in parallel)
| through Laravel's internal HTTP testing helpers (getJson/postJson) — no
| external HTTP calls.
|
| Contract reference: docs/legacy-reference/frontend/backend_api_reference.md
| §10.3 (Offers).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
| NOTE: as of writing, OffersController.php is being written by a parallel
| agent and 'offers' is not yet registered in
| ObjectDispatchController::CONTROLLERS — every request below may 404 until
| both land. That is expected, not a bug in this test file.
|
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function offersEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "offers.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Same indirection as tests/Feature/CampaignsTest.php::actingAsAdminForCampaigns()
 * / tests/Feature/StreamsTest.php::actingAsAdminForStreams() — duplicated
 * under a distinct name since Pest loads every test file into one process
 * (two files can't both declare a global function with the same name).
 * App\Http\Middleware\LegacyAuthMiddleware unconditionally re-derives
 * CurrentUserService from the `states` cookie on every request, so a plain
 * CurrentUserService::set() call would get silently clobbered back to null
 * before the controller runs — mocking AuthService::verifyFromCookie()
 * sidesteps that (and the real cookie/JWT path, exercised elsewhere).
 */
function actingAsAdminForOffers(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

/*
 * Admins bypass ACL entirely (User::isAdmin() === true short-circuits every
 * AclService check per §5), so authenticating as an admin here keeps every
 * assertion in this file valid without needing per-test ACL rule fixtures
 * — same pattern as CampaignsTest.php/StreamsTest.php, even though 'offers'
 * is not yet wired into AclService::ACL_KEYS.
 */
beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForOffers($admin);
});

it('lists offers as a JSON array', function () {
    OfferFactory::new()->count(3)->create();

    $response = $this->getJson(offersEndpoint('index'));

    $response->assertStatus(200);

    expect($response->json())->toBeArray()->and($response->json())->toHaveCount(3);
});

it('shows an offer with every model field', function () {
    $offer = OfferFactory::new()->create();

    $response = $this->getJson(offersEndpoint('show', ['id' => $offer->id]));

    $response->assertStatus(200);

    $data = $response->json();

    foreach ((new Offer)->getFillable() as $field) {
        expect($data)->toHaveKey($field);
    }
    expect($data)->toHaveKey('id');
});

it('returns 404 for show without a valid id', function () {
    $response = $this->getJson(offersEndpoint('show'));

    $response->assertStatus(404);
});

it('returns 404 for show with a non-existent id', function () {
    $response = $this->getJson(offersEndpoint('show', ['id' => 999999]));

    $response->assertStatus(404);
});

it('creates an offer given a valid name', function () {
    $payload = [
        'name' => 'Summer Offer',
        'offer_type' => 'external',
        'action_type' => 'http',
        'url' => 'https://example.com/offer',
        'payout_type' => 'CPA',
        'payout_value' => 12.5,
    ];

    $response = $this->postJson(offersEndpoint('create'), $payload);

    $response->assertStatus(200);

    $this->assertDatabaseHas('offers', [
        'name' => 'Summer Offer',
        'offer_type' => 'external',
    ]);
});

it('rejects offer creation without a name with a 406 and a name error', function () {
    $response = $this->postJson(offersEndpoint('create'), [
        'url' => 'https://example.com/missing-name',
    ]);

    $response->assertStatus(406);

    $data = $response->json();
    expect($data)->toHaveKey('name');

    $this->assertDatabaseMissing('offers', [
        'url' => 'https://example.com/missing-name',
    ]);
});

it('updates an offer', function () {
    $offer = OfferFactory::new()->create(['name' => 'Old Name']);

    $response = $this->postJson(offersEndpoint('update', ['id' => $offer->id]), [
        'name' => 'Updated Name',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('offers', [
        'id' => $offer->id,
        'name' => 'Updated Name',
    ]);
});

it('lists offers as options', function () {
    OfferFactory::new()->count(2)->create();

    $response = $this->getJson(offersEndpoint('listAsOptions'));

    $response->assertStatus(200);

    $data = $response->json();

    expect($data)->toBeArray()->and($data)->toHaveCount(2);

    foreach ($data as $item) {
        expect($item)->toHaveKey('id');
        expect($item)->toHaveKey('name');
    }
});

it('show: local_file offer gets a preview field ({folder}/_preview.png), regardless of whether the file exists yet', function () {
    $offer = OfferFactory::new()->create(['action_type' => 'local_file', 'action_options' => json_encode(['folder' => 'my-offer-folder'])]);

    $response = $this->getJson(offersEndpoint('show', ['id' => $offer->id]));

    $response->assertStatus(200);
    expect($response->json('preview'))->toBe('my-offer-folder/'.\App\Services\PreviewImageService::PREVIEW_FILE);
});

it('preview: returns a signed traffic-core preview URL for a local_file offer', function () {
    $offer = OfferFactory::new()->create(['action_type' => 'local_file', 'action_options' => json_encode(['folder' => 'x'])]);

    $response = $this->getJson(offersEndpoint('preview', ['id' => $offer->id]));

    $response->assertStatus(200);
    $url = $response->json('url');
    expect($url)->toContain('/preview.php?');
    expect($url)->toContain('type=offer');
    expect($url)->toContain('id='.$offer->id);

    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $expected = hash_hmac('sha256', "offer:{$offer->id}:{$query['expires']}", config('services.traffic_core.preview_secret'));
    expect($query['token'])->toBe($expected);
});

it('preview: rejects a non-local_file offer with a validation error', function () {
    $offer = OfferFactory::new()->create(['action_type' => 'http']);

    $response = $this->getJson(offersEndpoint('preview', ['id' => $offer->id]));

    $response->assertStatus(406);
});

it('denies a guest (no current user) access to view an offer with a 403', function () {
    $offer = OfferFactory::new()->create();
    actingAsAdminForOffers(null);

    $response = $this->getJson(offersEndpoint('show', ['id' => $offer->id]));

    $response->assertStatus(403);
    expect($response->json())->toHaveKey('error');
});

it('denies a guest (no current user) access to create an offer with a 403', function () {
    actingAsAdminForOffers(null);

    $response = $this->postJson(offersEndpoint('create'), [
        'name' => 'Guest Offer',
    ]);

    $response->assertStatus(403);

    $this->assertDatabaseMissing('offers', ['name' => 'Guest Offer']);
});

it('denies a guest (no current user) access to update an offer with a 403', function () {
    $offer = OfferFactory::new()->create(['name' => 'Original']);
    actingAsAdminForOffers(null);

    $response = $this->postJson(offersEndpoint('update', ['id' => $offer->id]), [
        'name' => 'Changed',
    ]);

    $response->assertStatus(403);
    $this->assertDatabaseHas('offers', ['id' => $offer->id, 'name' => 'Original']);
});

/*
 * Regression coverage for the `affiliate_network` name lookup in
 * serializeOffer()'s `$withGroupName` branch (see the TODO note that used
 * to sit at this spot before App\Models\AffiliateNetwork's table/
 * controller existed): with `?withGroupName=1` and a real
 * affiliate_network_id, the offer's `affiliate_network` key must resolve
 * to that network's actual `name`, not null.
 */
it('resolves the real affiliate network name with withGroupName=1', function () {
    $network = \Database\Factories\AffiliateNetworkFactory::new()->create([
        'name' => 'Acme Affiliates',
    ]);
    $offer = OfferFactory::new()->create(['affiliate_network_id' => $network->id]);

    $response = $this->getJson(offersEndpoint('show', ['id' => $offer->id, 'withGroupName' => 1]));

    $response->assertStatus(200);
    expect($response->json('affiliate_network'))->toBe('Acme Affiliates');
});

it('leaves affiliate_network null with withGroupName=1 when the offer has no network', function () {
    $offer = OfferFactory::new()->create(['affiliate_network_id' => null]);

    $response = $this->getJson(offersEndpoint('show', ['id' => $offer->id, 'withGroupName' => 1]));

    $response->assertStatus(200);
    expect($response->json('affiliate_network'))->toBeNull();
});

it('resolves the real group name with withGroupName=1, null (not "Default") when ungrouped', function () {
    $group = \Database\Factories\GroupFactory::new()->forOffers()->create(['name' => 'Offer Group']);
    $grouped = OfferFactory::new()->create(['group_id' => $group->id]);
    $ungrouped = OfferFactory::new()->create(['group_id' => 0]);

    $response = $this->getJson(offersEndpoint('show', ['id' => $grouped->id, 'withGroupName' => 1]));
    $response->assertStatus(200);
    expect($response->json('group'))->toBe('Offer Group');

    $response = $this->getJson(offersEndpoint('show', ['id' => $ungrouped->id, 'withGroupName' => 1]));
    $response->assertStatus(200);
    expect($response->json('group'))->toBeNull();
});

it('listAsOptions resolves the real group name for offers', function () {
    $group = \Database\Factories\GroupFactory::new()->forOffers()->create(['name' => 'Offer List Group']);
    $offer = OfferFactory::new()->create(['group_id' => $group->id, 'state' => 'active']);

    $response = $this->getJson(offersEndpoint('listAsOptions'));

    $response->assertStatus(200);
    $byId = collect($response->json())->keyBy('id');
    expect($byId[$offer->id]['group'])->toBe('Offer List Group');
});
