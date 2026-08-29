<?php

use App\Models\Setting;
use App\Models\UserBotIp;

/*
|--------------------------------------------------------------------------
| Botlist compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=botlist.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\BotlistController) through Laravel's internal
| HTTP testing helpers — no external HTTP calls.
|
| Scope: only the MySQL-backed `user_bot_ips` path is covered (DBCA
| proprietary storage is out of scope, see BotlistController's docblock).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
| No auth mocking needed: BotlistController has no isAdmin()/ACL gate,
| matching the real legacy actions (see class docblock re: ConfigService::
| isDemo() not being ported either).
*/

function botlistEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "botlist.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

it('reports an empty list by default', function () {
    $count = $this->getJson(botlistEndpoint('getBotListCount'));
    $count->assertStatus(200)->assertJson(['count' => 0]);

    $list = $this->getJson(botlistEndpoint('getBotList'));
    $list->assertStatus(200)->assertJson(['value' => '']);
});

it('saves a bot list and round-trips it, normalizing a CIDR to a dash range', function () {
    $save = $this->postJson(botlistEndpoint('saveBotList'), ['value' => "1.2.3.0/24\n8.8.8.8"]);
    $save->assertStatus(200)->assertJson(['count' => 2]);

    $this->assertDatabaseHas('user_bot_ips', [
        'min_ip' => ip2long('1.2.3.0'),
        'max_ip' => ip2long('1.2.3.255'),
        'raw_value' => '1.2.3.0-1.2.3.255',
    ]);
    $this->assertDatabaseHas('user_bot_ips', [
        'min_ip' => ip2long('8.8.8.8'),
        'max_ip' => ip2long('8.8.8.8'),
        'raw_value' => '8.8.8.8',
    ]);

    $list = $this->getJson(botlistEndpoint('getBotList'));
    $list->assertStatus(200);
    // Ordered by min_ip ascending: 1.2.3.0/24's min_ip (16909056) sorts
    // before 8.8.8.8's min_ip (134744072).
    expect($list->json('value'))->toBe("1.2.3.0-1.2.3.255\n8.8.8.8");
});

it('merges overlapping ranges into a single entry on save', function () {
    $this->postJson(botlistEndpoint('saveBotList'), ['value' => "1.2.3.0-1.2.3.10\n1.2.3.5-1.2.3.20"]);

    expect(UserBotIp::query()->count())->toBe(1);
    $this->assertDatabaseHas('user_bot_ips', [
        'min_ip' => ip2long('1.2.3.0'),
        'max_ip' => ip2long('1.2.3.20'),
        'raw_value' => '1.2.3.0-1.2.3.20',
    ]);
});

it('clearing the list via an empty saveBotList value', function () {
    $this->postJson(botlistEndpoint('saveBotList'), ['value' => '1.2.3.4']);
    expect(UserBotIp::query()->count())->toBe(1);

    $response = $this->postJson(botlistEndpoint('saveBotList'), ['value' => '']);
    $response->assertStatus(200)->assertJson(['count' => 0]);
    expect(UserBotIp::query()->count())->toBe(0);
});

it('rejects an unparseable single-line saveBotList value with a 500', function () {
    $response = $this->postJson(botlistEndpoint('saveBotList'), ['value' => 'not-an-ip']);
    $response->assertStatus(500);
    expect(UserBotIp::query()->count())->toBe(0);
});

it('adds new IPs to an existing list without disturbing disjoint entries', function () {
    $this->postJson(botlistEndpoint('saveBotList'), ['value' => '1.1.1.1']);

    $add = $this->postJson(botlistEndpoint('addBotList'), ['value' => '2.2.2.2']);
    $add->assertStatus(200)->assertJson(['count' => 2]);

    $this->assertDatabaseHas('user_bot_ips', ['raw_value' => '1.1.1.1']);
    $this->assertDatabaseHas('user_bot_ips', ['raw_value' => '2.2.2.2']);
});

it('merges an added range into an existing overlapping one', function () {
    $this->postJson(botlistEndpoint('saveBotList'), ['value' => '1.2.3.0-1.2.3.10']);

    $add = $this->postJson(botlistEndpoint('addBotList'), ['value' => '1.2.3.5-1.2.3.30']);
    $add->assertStatus(200)->assertJson(['count' => 1]);

    $this->assertDatabaseHas('user_bot_ips', [
        'min_ip' => ip2long('1.2.3.0'),
        'max_ip' => ip2long('1.2.3.30'),
    ]);
});

it('excludes a sub-range, splitting the remaining range into two entries', function () {
    $this->postJson(botlistEndpoint('saveBotList'), ['value' => '1.2.3.0-1.2.3.20']);

    $exclude = $this->postJson(botlistEndpoint('excludeBotList'), ['value' => '1.2.3.5-1.2.3.10']);
    $exclude->assertStatus(200)->assertJson(['count' => 2]);

    $this->assertDatabaseHas('user_bot_ips', [
        'min_ip' => ip2long('1.2.3.0'),
        'max_ip' => ip2long('1.2.3.4'),
    ]);
    $this->assertDatabaseHas('user_bot_ips', [
        'min_ip' => ip2long('1.2.3.11'),
        'max_ip' => ip2long('1.2.3.20'),
    ]);
});

it('excludes an entire range, leaving nothing behind', function () {
    $this->postJson(botlistEndpoint('saveBotList'), ['value' => '1.2.3.4']);

    $exclude = $this->postJson(botlistEndpoint('excludeBotList'), ['value' => '1.2.3.0-1.2.3.255']);
    $exclude->assertStatus(200)->assertJson(['count' => 0]);
    expect(UserBotIp::query()->count())->toBe(0);
});

it('clears the whole list via clearBotList', function () {
    $this->postJson(botlistEndpoint('saveBotList'), ['value' => "1.1.1.1\n2.2.2.2"]);

    $response = $this->postJson(botlistEndpoint('clearBotList'));
    $response->assertStatus(200)->assertJson(['count' => 0]);
    expect(UserBotIp::query()->count())->toBe(0);
});

it('reports an empty bot-signature list by default', function () {
    $count = $this->getJson(botlistEndpoint('getBotSignatureCount'));
    $count->assertStatus(200)->assertJson(['count' => 0]);

    $value = $this->getJson(botlistEndpoint('getBotSignature'));
    $value->assertStatus(200)->assertJson(['value' => '']);
});

it('saves and round-trips a bot-signature list', function () {
    $save = $this->postJson(botlistEndpoint('saveBotSignature'), ['value' => "SemrushBot\nAhrefsBot"]);
    $save->assertStatus(200)->assertJson(['count' => 2]);

    $value = $this->getJson(botlistEndpoint('getBotSignature'));
    $value->assertStatus(200)->assertJson(['value' => "SemrushBot\nAhrefsBot"]);

    $this->assertDatabaseHas('settings', [
        'key' => 'bots.additional.signature',
        'value' => "SemrushBot\nAhrefsBot",
    ]);
});

it('splits bot-signature entries on commas and drops blanks', function () {
    $save = $this->postJson(botlistEndpoint('saveBotSignature'), ['value' => "SemrushBot, AhrefsBot,\n\n , YandexBot"]);
    $save->assertStatus(200)->assertJson(['count' => 3]);

    $value = $this->getJson(botlistEndpoint('getBotSignature'));
    expect($value->json('value'))->toBe("SemrushBot\nAhrefsBot\nYandexBot");
});

it('overwrites a previously saved bot-signature list', function () {
    $this->postJson(botlistEndpoint('saveBotSignature'), ['value' => 'OldBot']);
    $second = $this->postJson(botlistEndpoint('saveBotSignature'), ['value' => "NewBot\nOtherBot"]);

    $second->assertStatus(200)->assertJson(['count' => 2]);
    expect(Setting::query()->find('bots.additional.signature')->value)->toBe("NewBot\nOtherBot");
});
