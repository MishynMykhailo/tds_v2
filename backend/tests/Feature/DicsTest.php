<?php

use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Dics compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=dics.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\DicsController) through Laravel's internal
| HTTP testing helpers — no external HTTP calls.
|
| Contract reference: docs/legacy-reference/frontend/api/10.12_settings.md.
| `Component\Settings\Initializer.php` registers this controller under the
| object key "dics" (there is no separate Component\Dics module in the old
| codebase) — confirmed by reading that Initializer directly.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
*/

function dicsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "dics.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsForDics(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

it('lists currencies as value/name pairs', function () {
    $user = UserFactory::new()->create();
    actingAsForDics($user);

    $response = $this->getJson(dicsEndpoint('currencies'));

    $response->assertStatus(200);
    expect($response->json())->toBe([
        ['value' => 'USD', 'name' => 'USD ($)'],
        ['value' => 'RUB', 'name' => 'RUB (р.)'],
        ['value' => 'EUR', 'name' => 'EUR (€)'],
        ['value' => 'GBP', 'name' => 'GBP (£)'],
        ['value' => 'UAH', 'name' => 'UAH (₴)'],
    ]);
});

it('has no dics.index route (only dics.currencies exists in the legacy source)', function () {
    $user = UserFactory::new()->create();
    actingAsForDics($user);

    $response = $this->getJson(dicsEndpoint('index'));

    $response->assertStatus(404);
});
