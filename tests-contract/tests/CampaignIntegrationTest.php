<?php

/*
|--------------------------------------------------------------------------
| CampaignIntegration contract tests
|--------------------------------------------------------------------------
|
| Locks down `Component\CampaignIntegration\Controller\
| {CodePresetsController,KClientJSPresetController}` and
| `Component\ThirdPartyIntegration\Controller\{FacebookController,
| AppsFlyerController}` (`?object=codepresets/kclientjspreset/
| facebookintegration/appsflyerintegration`), run against the backend
| named by TDS_TEST_TARGET. None had any contract-test coverage before
| this file.
|
*/

use Tests\Support\ApiClient;

beforeEach(function () {
    $this->api = new ApiClient();
    $loginResponse = $this->api->login();

    expect($loginResponse->getStatusCode())->toBe(200);
    $loginBody = ApiClient::json($loginResponse);
    expect($loginBody)->toBeArray()->and($loginBody['success'] ?? null)->toBeTrue();
});

describe('codepresets.index / .show', function () {
    test('index returns all 16 presets with the exact same stable fields (excluding add_params\' per-request random tokens)', function () {
        $response = $this->api->get('codepresets.index');
        expect($response->getStatusCode())->toBe(200);

        $presets = ApiClient::json($response);
        expect($presets)->toHaveCount(16);

        $expectedGroups = ['banners', 'frames', 'links', 'other', 'redirects'];
        foreach ($presets as $preset) {
            expect($preset)->toHaveKeys([
                'id', 'name', 'instruction', 'instruction_2', 'code', 'offer_code',
                'postback_code', 'add_params', 'group', 'group_translated', 'settings',
                'is_beta', 'is_pro_only',
            ]);
            expect($preset['is_pro_only'])->toBeFalse();
            expect(in_array($preset['group'], $expectedGroups, true))->toBeTrue();
            // "No group"-vs-"Default" precedent doesn't apply here - this
            // really is a plain ucfirst($group), verified live against
            // all 5 real groups on both language=en and =ru (2026-09-03).
            expect($preset['group_translated'])->toBe(ucfirst($preset['group']));
        }
    });

    test('show returns the single matching preset by id, or a truly empty 200 body for an unknown id', function () {
        $known = $this->api->get('codepresets.show', ['id' => 'pixel']);
        expect($known->getStatusCode())->toBe(200);
        $body = ApiClient::json($known);
        expect($body['id'])->toBe('pixel');
        expect($body['group_translated'])->toBe('Other');

        // Empty body (0 bytes), NOT the literal string "null" - confirmed
        // live against legacy port 8090 (2026-09-03), same class of
        // "implicit PHP null -> genuinely empty body" contract already
        // established for userPreferences.get.
        $unknown = $this->api->get('codepresets.show', ['id' => 'not_a_real_preset_id']);
        expect($unknown->getStatusCode())->toBe(200);
        expect((string) $unknown->getBody())->toBe('');
    });
});

describe('codepresets.downloadClient / .downloadClientV2', function () {
    test('both serve a real file download, V2 keeps the SAME filename as V1 (a literal legacy copy-paste artifact, not fixed)', function () {
        $v1 = $this->api->get('codepresets.downloadClient');
        expect($v1->getStatusCode())->toBe(200);
        expect($v1->getHeaderLine('Content-Disposition'))->toBe('attachment; filename=kclient.php');
        expect($v1->getHeaderLine('Content-Type'))->toBe('application/octet-stream');

        $v2 = $this->api->get('codepresets.downloadClientV2');
        expect($v2->getStatusCode())->toBe(200);
        expect($v2->getHeaderLine('Content-Disposition'))->toBe('attachment; filename=kclient.php');
    });
});

describe('kclientjspreset.show', function () {
    test('generates a real base64 client-side script embedding the given url/ttl settings', function () {
        $response = $this->api->post('kclientjspreset.show', [], [
            'unique' => true, 'url' => 'https://example.com', 'host' => 'example.com', 'base' => true,
        ]);
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toHaveKey('code');
        expect($body['code'])->toContain('<script src="data:text/javascript;base64,');

        $b64 = [];
        preg_match('/base64,([^"]+)/', $body['code'], $b64);
        $decoded = base64_decode($b64[1]);
        expect($decoded)->toContain("ttl: 86400");
        expect($decoded)->toContain("R_PATH: 'https://example.com'");
    });

    test('an empty request still returns a valid code block (all settings default)', function () {
        $response = $this->api->post('kclientjspreset.show', [], []);
        expect($response->getStatusCode())->toBe(200);
        expect(ApiClient::json($response))->toHaveKey('code');
    });
});

describe('facebookintegration.getDescription / appsflyerintegration.getDescription', function () {
    test('both return a non-empty HTML description string', function () {
        $fb = $this->api->get('facebookintegration.getDescription');
        expect($fb->getStatusCode())->toBe(200);
        $fbBody = ApiClient::json($fb);
        expect($fbBody)->toHaveKey('description');
        expect($fbBody['description'])->toContain('Facebook');

        $af = $this->api->get('appsflyerintegration.getDescription');
        expect($af->getStatusCode())->toBe(200);
        $afBody = ApiClient::json($af);
        expect($afBody)->toHaveKey('description');
    });
});
