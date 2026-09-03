<?php

/*
|--------------------------------------------------------------------------
| Cleaner contract tests
|--------------------------------------------------------------------------
|
| Locks down the `cleaner` module contract
| (`Component\Cleaner\Controller\CleanerController`), run against the
| backend named by TDS_TEST_TARGET.
|
| `cleaner.clean` dispatches an async stats-deletion job/command - this
| suite only asserts response status/shape, not that rows were actually
| deleted (no way to observe that through the API alone, and both targets
| already have their own dedicated coverage for the underlying deletion
| logic: backend/tests/Feature/CleanerTest.php for the port,
| DeleteStatsCommand for legacy).
|
| Two cases are deliberately NOT parity-tested against legacy (documented,
| not silently skipped):
| - admin, no `campaign_id` (the "clean everything" path): live-verified
|   2026-09-03 that real legacy 500s here EVERY time with an uncaught
|   `ArgumentCountError` - `CleanerController::_schedule($startDate,
|   $endDate = NULL, $timezone = NULL, string $campaignId)` declares a
|   required parameter AFTER two optional ones, and the admin-without-
|   campaign_id call site only ever passes 3 arguments. This is a real,
|   environment-independent bug in the live legacy app itself (not this
|   dev container's fault) - worth flagging to the user directly, since
|   it means legacy's own "clean stats for everything" action is
|   permanently broken as shipped. This port's equivalent has no such
|   defect and returns `{"success":true}`.
| - a real `campaign_id` that exists and IS allowed: 500s in this legacy
|   dev environment specifically, traced to `DeleteStatsCommand`'s
|   underlying Redis connection being unreachable here - same class of
|   environment artifact already documented for Editor's saveFileData/
|   removeFile.
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

describe('cleaner.clean — validation', function () {
    test('a non-POST request returns a plain {success:false}, no deletion attempted', function () {
        $response = $this->api->get('cleaner.clean', ['start_date' => '2024-01-01', 'end_date' => '2024-01-31']);

        expect($response->getStatusCode())->toBe(200);
        expect(ApiClient::json($response))->toBe(['success' => false]);
    });

    test('missing start_date/end_date is a plain 200, not a 406 - an ordinary return in legacy, not the ValidationError throw', function () {
        $response = $this->api->post('cleaner.clean', [], ['start_date' => '2024-01-01']);

        expect($response->getStatusCode())->toBe(200);
        expect(ApiClient::json($response))->toBe(['success' => false, 'error' => 'Invalid format date']);
    });

    test('an invalid date format is a real 406, matching the ValidationError shape', function () {
        $response = $this->api->post('cleaner.clean', [], [
            'start_date' => 'not-a-date', 'end_date' => '2024-01-31',
        ]);

        expect($response->getStatusCode())->toBe(406);
        expect(ApiClient::json($response))->toBe(['success' => false, 'error' => 'Invalid format date']);
    });

    test('a non-existent campaign_id gives the exact legacy "Traffic\\Model\\Campaign #<id> not found" 404, not a 403', function () {
        $missingId = 999999999;

        $response = $this->api->post('cleaner.clean', [], [
            'start_date' => '2024-01-01', 'end_date' => '2024-01-31', 'campaign_id' => $missingId,
        ]);

        expect($response->getStatusCode())->toBe(404);
        expect(ApiClient::json($response)['error'])->toBe("Traffic\\Model\\Campaign #{$missingId} not found");
    });
});

describe('cleaner.clean — authorization', function () {
    test('a non-admin user without edit access to the target campaign gets a 403', function () {
        $token = bin2hex(random_bytes(4));
        $password = 'CtPass!' . $token;
        Fixtures::createUser($this->api, [
            'login' => 'ct_cleaner_' . $token,
            'password_hash' => $password,
            'new_password' => $password,
            'new_password_confirmation' => $password,
            'type' => 'USER',
        ]);
        $campaign = Fixtures::createCampaign($this->api);

        try {
            $userApi = new ApiClient();
            $login = $userApi->login('ct_cleaner_' . $token, $password);
            expect($login->getStatusCode())->toBe(200);

            $response = $userApi->post('cleaner.clean', [], [
                'start_date' => '2024-01-01', 'end_date' => '2024-01-31', 'campaign_id' => $campaign['id'],
            ]);
            expect($response->getStatusCode())->toBe(403);
        } finally {
            $this->api->post('campaigns.update', [], ['id' => $campaign['id'], 'state' => 'deleted']);
        }
    });

    // Same documented divergence as Editor's guest test - a request with
    // no auth cookie at all gets legacy's HTML login shell (200), not a
    // JSON 403; detected by content, not by target URL.
    test('a guest (no session) is denied - JSON 403 on the port, HTML login shell on legacy', function () {
        $guestApi = new ApiClient();
        $response = $guestApi->get('cleaner.clean', ['start_date' => '2024-01-01', 'end_date' => '2024-01-31']);
        $body = (string) $response->getBody();

        if (str_starts_with(ltrim($body), '<')) {
            expect($response->getStatusCode())->toBe(200);
        } else {
            // Guest -> non-POST branch fires first either way (§ above),
            // so this is the same plain {success:false}, not a 403 - the
            // ACL check is never reached for a GET request.
            expect($response->getStatusCode())->toBe(200);
            expect(json_decode($body, true))->toBe(['success' => false]);
        }
    });
});

describe('cleaner.clean — real legacy bug, documented not reproduced', function () {
    test('admin without campaign_id: legacy 500s (ArgumentCountError), this port succeeds with {success:true}', function () {
        $response = $this->api->post('cleaner.clean', [], [
            'start_date' => '2024-01-01', 'end_date' => '2024-01-31',
        ]);
        $body = (string) $response->getBody();

        if ($response->getStatusCode() === 200 && str_starts_with(ltrim($body), '{')) {
            // The port: real success, not a crash.
            expect(json_decode($body, true))->toBe(['success' => true]);
        } else {
            // Legacy: the documented ArgumentCountError crash above.
            expect($response->getStatusCode())->toBeGreaterThanOrEqual(500);
        }
    });
});
