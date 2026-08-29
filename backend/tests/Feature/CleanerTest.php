<?php

use App\Http\Controllers\Admin\CleanerController;
use App\Jobs\DeleteStatsJob;
use App\Models\AclRule;
use App\Models\Click;
use App\Models\Conversion;
use App\Models\User;
use App\Services\CurrentUserService;
use Database\Factories\CampaignFactory;
use Database\Factories\UserFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Cleaner compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Exercises App\Http\Controllers\Admin\CleanerController::cleanAction()
| directly (app()->call, no HTTP dispatch) instead of going through
| `?object=cleaner.clean` on /admin/index.php: the "cleaner" dispatch key
| is registered in App\Http\Controllers\ObjectDispatchController by the
| coordinator after all parallel porting work lands (shared file, avoids a
| concurrent-write conflict with other in-flight modules) — it is not
| registered yet as this file is written, so hitting the route would 404
| regardless of controller correctness. Calling the controller method
| directly still exercises the exact same code this task is scoped to
| (param reading, ACL, job dispatch) and needs no route.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
| QUEUE_CONNECTION=sync in .env.testing, so DeleteStatsJob::dispatch() runs
| inline unless Queue::fake() is active for a given test.
*/

function callClean(array $params, string $method = 'POST'): \Symfony\Component\HttpFoundation\Response
{
    $request = Request::create('/admin/index.php', $method, $method === 'POST' ? $params : [], [], [], [], http_build_query($params));
    $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

    if ($method === 'GET') {
        $request = Request::create('/admin/index.php?'.http_build_query($params), 'GET');
    }

    return app(CleanerController::class)->cleanAction($request);
}

function actingAsForCleaner(?User $user): void
{
    app(CurrentUserService::class)->set($user);
}

it('returns success:false for a non-POST request without deleting anything', function () {
    actingAsForCleaner(UserFactory::new()->admin()->create());

    $response = callClean([], 'GET');

    expect($response->getStatusCode())->toBe(200);
    expect(json_decode($response->getContent(), true))->toBe(['success' => false]);
});

it('rejects missing start_date/end_date with a 406', function () {
    actingAsForCleaner(UserFactory::new()->admin()->create());

    $response = callClean(['start_date' => '2024-01-01']);

    expect($response->getStatusCode())->toBe(406);
    expect(json_decode($response->getContent(), true)['success'])->toBeFalse();
});

it('rejects an invalid date format with a 406', function () {
    actingAsForCleaner(UserFactory::new()->admin()->create());

    $response = callClean(['start_date' => 'not-a-date', 'end_date' => '2024-01-31']);

    expect($response->getStatusCode())->toBe(406);
    expect(json_decode($response->getContent(), true))->toBe(['success' => false, 'error' => 'Invalid format date']);
});

it('admin without campaign_id schedules one global cleanup job and deletes matching stats', function () {
    actingAsForCleaner(UserFactory::new()->admin()->create());

    Click::create([
        'visitor_id' => 1, 'sub_id' => 'in-range', 'datetime' => '2024-01-15 00:00:00',
        'campaign_id' => 1, 'source_id' => 1, 'referrer_id' => 1,
    ]);
    Click::create([
        'visitor_id' => 2, 'sub_id' => 'out-of-range', 'datetime' => '2023-01-15 00:00:00',
        'campaign_id' => 1, 'source_id' => 1, 'referrer_id' => 1,
    ]);
    Conversion::create([
        'sub_id' => 'conv-in-range', 'click_datetime' => '2024-01-15 00:00:00',
        'postback_datetime' => '2024-01-15 00:00:00', 'campaign_id' => 1,
    ]);

    $response = callClean(['start_date' => '2024-01-01', 'end_date' => '2024-01-31']);

    expect($response->getStatusCode())->toBe(200);
    expect(json_decode($response->getContent(), true))->toBe(['success' => true]);

    expect(Click::where('sub_id', 'in-range')->exists())->toBeFalse();
    expect(Click::where('sub_id', 'out-of-range')->exists())->toBeTrue();
    expect(Conversion::where('sub_id', 'conv-in-range')->exists())->toBeFalse();
});

it('denies cleaning a campaign the user is not allowed to edit', function () {
    $user = UserFactory::new()->create();
    actingAsForCleaner($user);
    $campaign = CampaignFactory::new()->create();

    $response = callClean([
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-31',
        'campaign_id' => $campaign->id,
    ]);

    expect($response->getStatusCode())->toBe(403);
});

it('allows cleaning a campaign the user has full_access to and scopes deletion to it', function () {
    $user = UserFactory::new()->create();
    actingAsForCleaner($user);
    $campaign = CampaignFactory::new()->create();
    $otherCampaign = CampaignFactory::new()->create();

    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'campaigns',
        'access_type' => AclRule::FULL_ACCESS,
    ]);

    Click::create([
        'visitor_id' => 1, 'sub_id' => 'own-campaign', 'datetime' => '2024-01-15 00:00:00',
        'campaign_id' => $campaign->id, 'source_id' => 1, 'referrer_id' => 1,
    ]);
    Click::create([
        'visitor_id' => 2, 'sub_id' => 'other-campaign', 'datetime' => '2024-01-15 00:00:00',
        'campaign_id' => $otherCampaign->id, 'source_id' => 1, 'referrer_id' => 1,
    ]);

    $response = callClean([
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-31',
        'campaign_id' => $campaign->id,
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(Click::where('sub_id', 'own-campaign')->exists())->toBeFalse();
    expect(Click::where('sub_id', 'other-campaign')->exists())->toBeTrue();
});

it('non-admin without campaign_id schedules one job per allowed campaign', function () {
    Queue::fake();

    $user = UserFactory::new()->create();
    actingAsForCleaner($user);
    $allowed = CampaignFactory::new()->create();

    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'campaigns',
        'access_type' => AclRule::TO_GROUPS_AND_SELECTED,
        'entities' => [$allowed->id],
    ]);

    $response = callClean(['start_date' => '2024-01-01', 'end_date' => '2024-01-31']);

    expect($response->getStatusCode())->toBe(200);
    Queue::assertPushed(DeleteStatsJob::class, 1);
});

it('non-admin without campaign_id and without any acl rule schedules nothing', function () {
    Queue::fake();

    actingAsForCleaner(UserFactory::new()->create());

    $response = callClean(['start_date' => '2024-01-01', 'end_date' => '2024-01-31']);

    expect($response->getStatusCode())->toBe(200);
    Queue::assertNotPushed(DeleteStatsJob::class);
});
