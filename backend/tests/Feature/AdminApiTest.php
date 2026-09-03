<?php

/*
|--------------------------------------------------------------------------
| AdminApi compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=adminApi.<action>` route (see
| App\Http\Controllers\Admin\AdminApiController). Neither legacy action
| gates on auth at all (no isAdmin()/isViewAllowed() call in the real
| source) - verified live against legacy port 8090, both reachable
| unauthenticated.
|
*/

function adminApiEndpoint(string $action): string
{
    return '/admin/index.php?'.http_build_query(['object' => "adminApi.{$action}"]);
}

it('serves a real Swagger UI HTML page from adminApi.index, no auth required', function () {
    $response = $this->get(adminApiEndpoint('index'));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    expect($response->getContent())->toContain('swagger-ui');
    expect($response->getContent())->toContain('?object=adminApi.spec');
});

it('redirects adminApi.spec to the real OpenAPI spec URL, no auth required', function () {
    $response = $this->get(adminApiEndpoint('spec'));

    $response->assertStatus(302);
    $response->assertRedirect('https://admin-api.docs.tds.io/openapi.yaml');
});
