<?php

use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| StreamTypes compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=streamTypes.listAsOptions` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\StreamTypesController). Static catalogue, no
| ACL involved.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

function streamTypesEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "streamTypes.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsAdminForStreamTypes(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    actingAsAdminForStreamTypes(UserFactory::new()->admin()->create());
});

it('returns the 3 stream types in legacy order (regular, default, forced)', function () {
    $response = $this->getJson(streamTypesEndpoint('listAsOptions'));

    $response->assertStatus(200);
    $data = $response->json();

    expect(array_column($data, 'value'))->toBe(['regular', 'default', 'forced']);
});

it('gives each entry a value and a name', function () {
    $response = $this->getJson(streamTypesEndpoint('listAsOptions'));

    foreach ($response->json() as $entry) {
        expect($entry)->toHaveKeys(['value', 'name']);
        expect($entry['name'])->toBeString()->not->toBeEmpty();
    }
});
