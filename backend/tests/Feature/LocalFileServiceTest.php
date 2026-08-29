<?php

use App\Exceptions\IncompatibleLocalFileException;
use App\Models\Setting;
use App\Services\LocalFileService;

/*
|--------------------------------------------------------------------------
| LocalFileService tests
|--------------------------------------------------------------------------
|
| Port fidelity target: application/Component/Landings/LocalFile/
| LocalFileService.php + Validator/Validator.php.
|
| `lp_dir` is pointed at a disposable folder under storage/framework/testing
| (relative to base_path(), same as production `lander`) instead of the
| real top-level `lander/` folder, and removed again in afterEach() — never
| touches real project files.
|
*/

function localFileTestDir(): string
{
    return 'storage/framework/testing/lander-'.getmypid();
}

beforeEach(function () {
    Setting::create(['key' => 'lp_dir', 'value' => localFileTestDir()]);
    $this->service = app(LocalFileService::class);
});

afterEach(function () {
    $path = base_path(localFileTestDir());
    if (is_dir($path)) {
        app(LocalFileService::class)->removeDirectory($path);
    }
});

function buildTestZip(array $files): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'test.zip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $data = base64_encode(file_get_contents($tmp));
    unlink($tmp);

    return $data;
}

test('generateUniqueFolder slugifies the name', function () {
    $folder = $this->service->generateUniqueFolder('My Landing Page!!');
    expect($folder)->toBe('my-landing-page');
});

test('generateUniqueFolder falls back to lp for an empty/unsluggable name', function () {
    expect($this->service->generateUniqueFolder('!!!'))->toBe('lp');
});

test('generateUniqueFolder appends a random suffix on collision', function () {
    $first = $this->service->generateUniqueFolder('dup');
    mkdir($this->service->buildPath($first), 0755, true);

    $second = $this->service->generateUniqueFolder('dup');

    expect($second)->not->toBe($first)
        ->and($second)->toStartWith('dup-');
});

test('replaceFiles unpacks a valid zip with an index.html at its root', function () {
    $zip = buildTestZip(['index.html' => '<html>hi</html>', 'style.css' => 'body{}']);

    $this->service->replaceFiles('valid-folder', $zip);

    $path = $this->service->buildPath('valid-folder');
    expect(is_file($path.'/index.html'))->toBeTrue()
        ->and(is_file($path.'/style.css'))->toBeTrue()
        ->and(is_dir($path.'/_tmp'))->toBeFalse();
});

test('replaceFiles finds the main folder when the zip wraps everything in a subdirectory', function () {
    $zip = buildTestZip(['site/index.html' => '<html>hi</html>']);

    $this->service->replaceFiles('nested-folder', $zip);

    expect(is_file($this->service->buildPath('nested-folder').'/index.html'))->toBeTrue();
});

test('replaceFiles rejects a zip with no index file', function () {
    $zip = buildTestZip(['readme.txt' => 'no index here']);

    $this->service->replaceFiles('no-index', $zip);
})->throws(IncompatibleLocalFileException::class);

test('replaceFiles rejects a zip containing a blacklisted file', function () {
    $zip = buildTestZip(['index.html' => '<html></html>', 'kclient.php' => '<?php']);

    $this->service->replaceFiles('forbidden', $zip);
})->throws(IncompatibleLocalFileException::class);

test('replaceFiles rejects .php files when PHP is not allowed', function () {
    Setting::create(['key' => 'lp_allow_php', 'value' => '0']);
    $zip = buildTestZip(['index.php' => '<?php echo 1;']);

    $this->service->replaceFiles('php-disallowed', $zip);
})->throws(IncompatibleLocalFileException::class);

test('replaceFiles allows .php files when PHP is allowed', function () {
    Setting::create(['key' => 'lp_allow_php', 'value' => '1']);
    $zip = buildTestZip(['index.php' => '<?php echo 1;']);

    $this->service->replaceFiles('php-allowed', $zip);

    expect(is_file($this->service->buildPath('php-allowed').'/index.php'))->toBeTrue();
});

test('resolveSafePath resolves a normal relative path inside the folder', function () {
    mkdir($this->service->buildPath('safe'), 0755, true);
    file_put_contents($this->service->buildPath('safe').'/a.txt', 'x');

    $resolved = $this->service->resolveSafePath('safe', 'a.txt');

    expect($resolved)->toEndWith('/safe/a.txt');
});

test('resolveSafePath rejects a path traversal attempt', function () {
    mkdir($this->service->buildPath('guarded'), 0755, true);

    $this->service->resolveSafePath('guarded', '../../../../etc/passwd');
})->throws(RuntimeException::class);
