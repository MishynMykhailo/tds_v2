<?php

use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| StreamActions compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=streamActions.index` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\StreamActionsController). Static catalogue, no
| ACL involved (same as StreamFilters/StreamTypes/StreamSchemas).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

function streamActionsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "streamActions.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsAdminForStreamActions(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    actingAsAdminForStreamActions(UserFactory::new()->admin()->create());
});

it('returns the direct-action catalogue as a JSON array', function () {
    $response = $this->getJson(streamActionsEndpoint('index'));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toBeArray()->not->toBeEmpty();

    foreach ($data as $entry) {
        expect($entry)->toHaveKeys(['key', 'name', 'field', 'type', 'description']);
    }
});

it('includes the real action keys (not the simplified 10-item brief list)', function () {
    $response = $this->getJson(streamActionsEndpoint('index'));

    $keys = array_column($response->json(), 'key');

    expect($keys)->toContain('curl', 'show_text', 'show_html', 'local_file', 'status404', 'campaign', 'frame', 'iframe', 'do_nothing', 'http', 'js', 'meta', 'remote', 'blank_referrer', 'double_meta', 'formsubmit');
    expect($keys)->not->toContain('sub_id', 'build_html', 'group', 'echo', 'location');
});
