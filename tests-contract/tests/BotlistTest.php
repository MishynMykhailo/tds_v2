<?php

/*
|--------------------------------------------------------------------------
| Botlist contract tests
|--------------------------------------------------------------------------
|
| Locks down the `botlist` module contract, run against the backend named
| by TDS_TEST_TARGET (see tests/Support/ApiClient.php). Object key
| verified live against the legacy source: `application/Component/
| BotDetection/Initializer.php` registers
| `$repo->register("botlist", new Controller\BotlistController());`.
|
| Two independent sub-features share this one controller:
|  - IP-range list (`getBotList`/`saveBotList`/`addBotList`/
|    `excludeBotList`/`clearBotList`/`getBotListCount`) — backed by the
|    `user_bot_ips` table (min_ip/max_ip/raw_value).
|  - UA-signature list (`getBotSignature`/`saveBotSignature`/
|    `getBotSignatureCount`) — backed by a single `Setting` row
|    (`bots.additional.signature`, newline-joined).
|
| Both lists are cleared in `afterEach` (via `saveBotList`/
| `saveBotSignature` with an empty value) rather than depending on a
| pre-existing empty state — the target database is live and mutable
| (shared with humans and other agents), matching the convention already
| established in GroupsTest.php/CampaignsTest.php.
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

afterEach(function () {
    $this->api->post('botlist.saveBotList', jsonBody: ['value' => '']);
    $this->api->post('botlist.saveBotSignature', jsonBody: ['value' => '']);
});

describe('botlist IP-range list', function () {
    test('getBotListCount/getBotList start empty, saveBotList replaces the whole list', function () {
        $count = ApiClient::json($this->api->get('botlist.getBotListCount'));
        expect($count)->toBe(['count' => 0]);

        $list = ApiClient::json($this->api->get('botlist.getBotList'));
        expect($list)->toBe(['value' => '']);

        $response = $this->api->post('botlist.saveBotList', jsonBody: ['value' => "1.2.3.4\n5.6.7.8"]);
        expect($response->getStatusCode())->toBe(200);
        expect(ApiClient::json($response))->toBe(['count' => 2]);

        $list = ApiClient::json($this->api->get('botlist.getBotList'));
        expect($list['value'])->toBe("1.2.3.4\n5.6.7.8");
    });

    test('saveBotList accepts a CIDR range, stored as a dash-range raw_value', function () {
        $response = $this->api->post('botlist.saveBotList', jsonBody: ['value' => '10.0.0.0/30']);
        expect($response->getStatusCode())->toBe(200);
        expect(ApiClient::json($response))->toBe(['count' => 1]);

        $list = ApiClient::json($this->api->get('botlist.getBotList'));
        // 10.0.0.0/30 = 10.0.0.0-10.0.0.3.
        expect($list['value'])->toBe('10.0.0.0-10.0.0.3');
    });

    test('saveBotList rejects an invalid single-value entry with a 500', function () {
        $response = $this->api->post('botlist.saveBotList', jsonBody: ['value' => 'not-an-ip']);
        expect($response->getStatusCode())->toBe(500);
    });

    test('addBotList merges into the existing list instead of replacing it', function () {
        $this->api->post('botlist.saveBotList', jsonBody: ['value' => '1.1.1.1']);

        $response = $this->api->post('botlist.addBotList', jsonBody: ['value' => '2.2.2.2']);
        expect($response->getStatusCode())->toBe(200);
        expect(ApiClient::json($response))->toBe(['count' => 2]);

        $list = ApiClient::json($this->api->get('botlist.getBotList'));
        $lines = explode("\n", $list['value']);
        sort($lines);
        expect($lines)->toBe(['1.1.1.1', '2.2.2.2']);
    });

    test('excludeBotList removes a single IP from a stored range', function () {
        $this->api->post('botlist.saveBotList', jsonBody: ['value' => '10.0.0.0-10.0.0.3']);

        $response = $this->api->post('botlist.excludeBotList', jsonBody: ['value' => '10.0.0.1']);
        expect($response->getStatusCode())->toBe(200);

        $list = ApiClient::json($this->api->get('botlist.getBotList'));
        $lines = explode("\n", $list['value']);
        sort($lines);
        // 10.0.0.0-10.0.0.3 minus 10.0.0.1 -> 10.0.0.0 and 10.0.0.2-10.0.0.3.
        expect($lines)->toBe(['10.0.0.0', '10.0.0.2-10.0.0.3']);
    });

    test('clearBotList empties the list and getBotListCount reflects it', function () {
        $this->api->post('botlist.saveBotList', jsonBody: ['value' => '1.2.3.4']);

        $response = $this->api->post('botlist.clearBotList');
        expect($response->getStatusCode())->toBe(200);
        expect(ApiClient::json($response))->toBe(['count' => 0]);
    });
});

describe('botlist UA-signature list', function () {
    test('getBotSignatureCount/getBotSignature start empty, saveBotSignature replaces the whole list', function () {
        $count = ApiClient::json($this->api->get('botlist.getBotSignatureCount'));
        expect($count)->toBe(['count' => 0]);

        $response = $this->api->post('botlist.saveBotSignature', jsonBody: ['value' => "FooBot\nBarCrawler"]);
        expect($response->getStatusCode())->toBe(200);
        expect(ApiClient::json($response))->toBe(['count' => 2]);

        $value = ApiClient::json($this->api->get('botlist.getBotSignature'));
        expect($value)->toBe(['value' => "FooBot\nBarCrawler"]);
    });

    test('saveBotSignature treats a comma-separated value the same as newline-separated', function () {
        $response = $this->api->post('botlist.saveBotSignature', jsonBody: ['value' => 'FooBot,BarCrawler']);
        expect($response->getStatusCode())->toBe(200);
        expect(ApiClient::json($response))->toBe(['count' => 2]);

        $value = ApiClient::json($this->api->get('botlist.getBotSignature'));
        expect($value)->toBe(['value' => "FooBot\nBarCrawler"]);
    });

    test('saveBotSignature drops blank lines', function () {
        $response = $this->api->post('botlist.saveBotSignature', jsonBody: ['value' => "FooBot\n\n\nBarCrawler\n"]);
        expect(ApiClient::json($response))->toBe(['count' => 2]);
    });
});
