<?php

use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| StreamSchemas compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=streamSchemas.listAsOptions` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\StreamSchemasController). Static catalogue, no
| ACL involved.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

function streamSchemasEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "streamSchemas.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsAdminForStreamSchemas(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    actingAsAdminForStreamSchemas(UserFactory::new()->admin()->create());
});

it('returns the 3 schemas in legacy order (landings, redirect, action)', function () {
    $response = $this->getJson(streamSchemasEndpoint('listAsOptions'));

    $response->assertStatus(200);
    $data = $response->json();

    expect(array_column($data, 'value'))->toBe(['landings', 'redirect', 'action']);
});

it('gives each entry a value, name and description', function () {
    $response = $this->getJson(streamSchemasEndpoint('listAsOptions'));

    foreach ($response->json() as $entry) {
        expect($entry)->toHaveKeys(['value', 'name', 'description']);
        expect($entry['description'])->toBeString()->not->toBeEmpty();
    }
});
