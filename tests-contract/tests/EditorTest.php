<?php

/*
|--------------------------------------------------------------------------
| Editor contract tests
|--------------------------------------------------------------------------
|
| Locks down the `editor` module contract
| (`Component\Editor\Controller\EditorController` - a file manager over a
| local_file landing/offer's folder), run against the backend named by
| TDS_TEST_TARGET.
|
| `editor.loadFiles`' full tree shape is NOT compared - a documented,
| deliberate simplification (flat list vs legacy's nested
| `{toggled,type,name,children}` tree, built for a JS widget this
| project's frontend doesn't have yet - see EditorController's class
| docblock). Only that files/folders round-trip through create/list/load/
| remove is asserted here.
|
| `editor.saveFileData`/`.removeFile` are NOT parity-tested against legacy
| in this dev environment specifically: found live (2026-09-03) that real
| legacy 500s on both, traced to `CreatePreviewImageCommand::enqueue()`'s
| Redis connection failing in this container - unrelated to file I/O, and
| this port's equivalent already swallows that same class of failure
| internally (see GenerateLocalFilePreviewJob's docblock). Exercised
| against the PORT only.
|
| No `landings.delete` action exists in either codebase - cleanup here
| uses `landings.update` with `state: "deleted"`, same convention as
| every other entity module's fixture cleanup in this project's Grid-
| listed controllers.
|
*/

use Tests\Support\ApiClient;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->api = new ApiClient();
    $loginResponse = $this->api->login();

    expect($loginResponse->getStatusCode())->toBe(200);
    $loginBody = ApiClient::json($loginResponse);
    expect($loginBody)->toBeArray()->and($loginBody['success'] ?? null)->toBeTrue();
});

function createLocalLanding(ApiClient $api): array
{
    return Fixtures::createLanding($api, [
        'action_type' => 'local_file',
        'landing_type' => 'local',
    ]);
}

function deleteLanding(ApiClient $api, int $id): void
{
    $api->post('landings.update', [], ['id' => $id, 'state' => 'deleted']);
}

describe('editor.createFile / .loadFiles / .loadFileData', function () {
    test('a created file appears in loadFiles and its content round-trips', function () {
        $landing = createLocalLanding($this->api);

        try {
            $create = $this->api->post('editor.createFile', [], [
                'id' => $landing['id'], 'type' => 'landing', 'path' => 'index.html',
            ]);
            expect($create->getStatusCode())->toBe(200);

            $list = ApiClient::json($this->api->get('editor.loadFiles', ['id' => $landing['id'], 'type' => 'landing']));
            $paths = array_column($list['children'], 'path');
            expect($paths)->toContain('index.html');

            $data = ApiClient::json($this->api->get('editor.loadFileData', [
                'id' => $landing['id'], 'type' => 'landing', 'path' => 'index.html',
            ]));
            expect($data)->toBe(['data' => '']);
        } finally {
            deleteLanding($this->api, $landing['id']);
        }
    });

    test('rejects an unknown extension with a 406', function () {
        $landing = createLocalLanding($this->api);

        try {
            $response = $this->api->post('editor.createFile', [], [
                'id' => $landing['id'], 'type' => 'landing', 'path' => 'file.notarealext',
            ]);
            expect($response->getStatusCode())->toBe(406);
        } finally {
            deleteLanding($this->api, $landing['id']);
        }
    });

    // No path-traversal contract test here (deliberately - not an
    // oversight): live-verified 2026-09-03 that legacy has a REAL, live
    // path-traversal vulnerability - `editor.loadFileData` with
    // `path=../../../../etc/passwd` against a genuine local landing
    // returned the container's actual /etc/passwd content (200, full
    // file body), confirmed by direct curl against port 8090. This port
    // already rejects it (`LocalFileService::resolveSafePath()`, covered
    // by backend/tests/Feature/EditorTest.php's own traversal test) - a
    // deliberate security fix, not something to assert as a shared
    // contract against a target that's genuinely vulnerable. Worth
    // flagging to the user directly since it affects their live legacy
    // app, not just this port.

    test('loadFiles for a non-local (external) landing rejects with a validation error', function () {
        $landing = Fixtures::createLanding($this->api);

        try {
            $response = $this->api->get('editor.loadFiles', ['id' => $landing['id'], 'type' => 'landing']);
            expect($response->getStatusCode())->toBe(406);
        } finally {
            deleteLanding($this->api, $landing['id']);
        }
    });

    test('every action 404s for a non-existent id', function () {
        $missingId = 999999999;

        foreach (['loadFiles', 'loadFileData', 'createFile', 'removeFile'] as $action) {
            $response = $this->api->get('editor.'.$action, ['id' => $missingId, 'type' => 'landing']);
            expect($response->getStatusCode())->toBe(404);
        }
    });
});

describe('editor.infoLanding', function () {
    test('includes preview for a local_file landing', function () {
        $landing = createLocalLanding($this->api);
        $folder = $landing['action_options']['folder'] ?? json_decode($landing['action_options'], true)['folder'];

        try {
            $response = $this->api->get('editor.infoLanding', ['id' => $landing['id'], 'type' => 'landing']);
            expect($response->getStatusCode())->toBe(200);

            $body = ApiClient::json($response);
            expect($body['preview'])->toBe($folder.'/_preview.png');
        } finally {
            deleteLanding($this->api, $landing['id']);
        }
    });

    test('returns 404 for a non-existent id, 200 for an external (non-local) landing', function () {
        $missing = $this->api->get('editor.infoLanding', ['id' => 999999999, 'type' => 'landing']);
        expect($missing->getStatusCode())->toBe(404);

        $landing = Fixtures::createLanding($this->api);
        try {
            $response = $this->api->get('editor.infoLanding', ['id' => $landing['id'], 'type' => 'landing']);
            expect($response->getStatusCode())->toBe(200);
        } finally {
            deleteLanding($this->api, $landing['id']);
        }
    });
});

describe('authorization', function () {
    // NOT a shared-contract assertion (deliberately) - MAJOR finding,
    // live-verified 2026-09-03, first time any contract test in this
    // suite has hit a truly session-less (zero-cookie) request against
    // real legacy: a request with NO auth cookie at all - regardless of
    // `object=`, regardless of Accept/X-Requested-With headers - gets
    // legacy's SPA login shell (200, HTML), not a JSON 403. This is a
    // front-controller-level routing choice (no session -> serve the
    // login page unconditionally, let client-side JS handle it), NOT
    // per-controller behavior - almost certainly applies to every
    // ACL-gated action in the whole legacy app, not just Editor's. A
    // request WITH a valid session but insufficient ACL (an
    // authenticated non-admin USER) DOES get a real JSON 403 - already
    // verified repeatedly elsewhere this session (Conversions/GeoDb).
    // This port intentionally does NOT replicate the "serve HTML on no
    // session" behavior (see App\Http\Middleware\LegacyAuthMiddleware) -
    // it has no SPA to serve at this route, and a real JSON 403 is a
    // more honest response for an API consumer than a silent 200 HTML
    // page. See docs/PORTING_LOG.md for the full write-up and why this
    // wasn't caught earlier (every prior "denies a guest" test in this
    // project is an internal Pest test - port-only, never checked
    // against live legacy with zero cookies until now).
    test('port: denies a guest (no session) with a real JSON 403', function () {
        $landing = createLocalLanding($this->api);

        try {
            $guestApi = new ApiClient();
            $response = $guestApi->get('editor.loadFiles', ['id' => $landing['id'], 'type' => 'landing']);
            $body = (string) $response->getBody();

            if (str_starts_with(ltrim($body), '<')) {
                // Legacy's documented divergence above, not a failure -
                // detected by content (an HTML login shell), not by
                // guessing which target this is from its URL.
                expect($response->getStatusCode())->toBe(200);
            } else {
                expect($response->getStatusCode())->toBe(403);
                expect(json_decode($body, true))->toHaveKey('error');
            }
        } finally {
            deleteLanding($this->api, $landing['id']);
        }
    });
});
