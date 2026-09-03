<?php

use App\Models\Landing;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthService;
use App\Services\LocalFileService;
use Database\Factories\LandingFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Editor compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=editor.<action>` route (see
| App\Http\Controllers\Admin\EditorController). Port fidelity target:
| application/Component/Editor/{Controller,Service,Repository}.
|
| `lp_dir` is pointed at a disposable folder under storage/framework/testing
| (same convention as tests/Feature/LocalFileServiceTest.php) instead of the
| real top-level `lander/` folder, and removed again in afterEach().
|
*/

function editorTestDir(): string
{
    return 'storage/framework/testing/editor-'.getmypid();
}

function editorEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "editor.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsAdminForEditor(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    Setting::create(['key' => 'lp_dir', 'value' => editorTestDir()]);
    Setting::create(['key' => 'lp_allow_php', 'value' => '1']);

    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForEditor($admin);
});

afterEach(function () {
    $path = base_path(editorTestDir());
    if (is_dir($path)) {
        app(LocalFileService::class)->removeDirectory($path);
    }
});

/** Creates a "local" landing and materializes its folder + seed files on disk. */
function makeLocalLandingWithFiles(array $files = ['index.html' => '<html>hi</html>']): Landing
{
    $landing = LandingFactory::new()->local()->create();
    $folder = json_decode($landing->action_options, true)['folder'];

    $base = app(LocalFileService::class)->buildPath($folder);
    foreach ($files as $relative => $content) {
        $full = $base.'/'.$relative;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }
        file_put_contents($full, $content);
    }

    return $landing;
}

it('lists files for a local landing', function () {
    $landing = makeLocalLandingWithFiles(['index.html' => '<html></html>', 'css/style.css' => 'body{}']);

    $response = $this->getJson(editorEndpoint('loadFiles', ['id' => $landing->id, 'type' => 'landing']));

    $response->assertStatus(200);
    $paths = array_column($response->json('children'), 'path');

    expect($paths)->toContain('index.html')
        ->and($paths)->toContain('css')
        ->and($paths)->toContain('css/style.css');
});

it('loads file data for a local landing', function () {
    $landing = makeLocalLandingWithFiles(['index.html' => '<html>hello</html>']);

    $response = $this->getJson(editorEndpoint('loadFileData', [
        'id' => $landing->id, 'type' => 'landing', 'path' => 'index.html',
    ]));

    $response->assertStatus(200);
    expect($response->json('data'))->toBe('<html>hello</html>');
});

it('saves file data for a local landing', function () {
    $landing = makeLocalLandingWithFiles();

    $response = $this->postJson(editorEndpoint('saveFileData'), [
        'id' => $landing->id, 'type' => 'landing', 'path' => 'index.html', 'data' => '<html>updated</html>',
    ]);

    $response->assertStatus(200);

    $folder = json_decode($landing->action_options, true)['folder'];
    $content = file_get_contents(app(LocalFileService::class)->buildPath($folder).'/index.html');
    expect($content)->toBe('<html>updated</html>');
});

it('saveFileData queues a preview-image regeneration job (port of legacy CreatePreviewImageCommand::enqueue)', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $landing = makeLocalLandingWithFiles();

    $this->postJson(editorEndpoint('saveFileData'), [
        'id' => $landing->id, 'type' => 'landing', 'path' => 'index.html', 'data' => 'x',
    ])->assertStatus(200);

    \Illuminate\Support\Facades\Queue::assertPushed(
        \App\Jobs\GenerateLocalFilePreviewJob::class,
        fn ($job) => $job->type === 'landing' && $job->id === $landing->id,
    );
});

it('removeFile queues a preview-image regeneration job', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $landing = makeLocalLandingWithFiles(['index.html' => '<html></html>', 'old.txt' => 'x']);

    $this->postJson(editorEndpoint('removeFile'), [
        'id' => $landing->id, 'type' => 'landing', 'path' => 'old.txt',
    ])->assertStatus(200);

    \Illuminate\Support\Facades\Queue::assertPushed(
        \App\Jobs\GenerateLocalFilePreviewJob::class,
        fn ($job) => $job->type === 'landing' && $job->id === $landing->id,
    );
});

it('createFile does NOT queue a preview-image regeneration job (legacy only enqueues on save/remove)', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $landing = makeLocalLandingWithFiles();

    $this->postJson(editorEndpoint('createFile'), [
        'id' => $landing->id, 'type' => 'landing', 'path' => 'script.js',
    ])->assertStatus(200);

    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\GenerateLocalFilePreviewJob::class);
});

it('creates a new file with an allowed extension', function () {
    $landing = makeLocalLandingWithFiles();

    $response = $this->postJson(editorEndpoint('createFile'), [
        'id' => $landing->id, 'type' => 'landing', 'path' => 'script.js',
    ]);

    $response->assertStatus(200);

    $folder = json_decode($landing->action_options, true)['folder'];
    expect(is_file(app(LocalFileService::class)->buildPath($folder).'/script.js'))->toBeTrue();
});

it('rejects creating a file with a disallowed extension', function () {
    $landing = makeLocalLandingWithFiles();

    $response = $this->postJson(editorEndpoint('createFile'), [
        'id' => $landing->id, 'type' => 'landing', 'path' => 'shell.exe',
    ]);

    $response->assertStatus(406);
});

it('rejects creating a .php file when PHP is not allowed', function () {
    Setting::where('key', 'lp_allow_php')->update(['value' => '0']);
    $landing = makeLocalLandingWithFiles();

    $response = $this->postJson(editorEndpoint('createFile'), [
        'id' => $landing->id, 'type' => 'landing', 'path' => 'shell.php',
    ]);

    $response->assertStatus(406);
});

it('removes a file', function () {
    $landing = makeLocalLandingWithFiles(['index.html' => '<html></html>', 'old.txt' => 'x']);

    $response = $this->postJson(editorEndpoint('removeFile'), [
        'id' => $landing->id, 'type' => 'landing', 'path' => 'old.txt',
    ]);

    $response->assertStatus(200);

    $folder = json_decode($landing->action_options, true)['folder'];
    expect(is_file(app(LocalFileService::class)->buildPath($folder).'/old.txt'))->toBeFalse();
});

it('rejects a path traversal attempt with a 406, not a filesystem escape', function () {
    $landing = makeLocalLandingWithFiles();

    $response = $this->getJson(editorEndpoint('loadFileData', [
        'id' => $landing->id, 'type' => 'landing', 'path' => '../../../../../../etc/passwd',
    ]));

    $response->assertStatus(406);
});

it('returns 404 for a non-existent landing id', function () {
    $response = $this->getJson(editorEndpoint('loadFiles', ['id' => 999999, 'type' => 'landing']));

    $response->assertStatus(404);
});

it('returns a validation error for a non-local (external) landing', function () {
    $landing = LandingFactory::new()->create(); // external by default

    $response = $this->getJson(editorEndpoint('loadFiles', ['id' => $landing->id, 'type' => 'landing']));

    $response->assertStatus(406);
});

it('denies a guest (no current user) with a 403', function () {
    $landing = makeLocalLandingWithFiles();
    actingAsAdminForEditor(null);

    $response = $this->getJson(editorEndpoint('loadFiles', ['id' => $landing->id, 'type' => 'landing']));

    $response->assertStatus(403);
});

describe('editor.infoLanding', function () {
    it('returns the full serialized landing, unlike every other action here NOT gated on being local_file', function () {
        $landing = LandingFactory::new()->create(['name' => 'external one']); // external by default

        $response = $this->getJson(editorEndpoint('infoLanding', ['id' => $landing->id, 'type' => 'landing']));

        $response->assertStatus(200);
        $data = $response->json();
        expect($data['id'])->toBe($landing->id);
        expect($data['name'])->toBe('external one');
    });

    it('returns 404 for a non-existent id', function () {
        $response = $this->getJson(editorEndpoint('infoLanding', ['id' => 999999, 'type' => 'landing']));

        $response->assertStatus(404);
    });

    it('returns 404 for an unrecognized type', function () {
        $landing = LandingFactory::new()->create();

        $response = $this->getJson(editorEndpoint('infoLanding', ['id' => $landing->id, 'type' => 'bogus']));

        $response->assertStatus(404);
    });

    it('denies a guest (no current user) with a 403', function () {
        $landing = LandingFactory::new()->create();
        actingAsAdminForEditor(null);

        $response = $this->getJson(editorEndpoint('infoLanding', ['id' => $landing->id, 'type' => 'landing']));

        $response->assertStatus(403);
    });
});
