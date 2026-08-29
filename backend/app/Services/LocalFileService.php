<?php

namespace App\Services;

use App\Exceptions\IncompatibleLocalFileException;
use App\Models\Setting;
use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Port of legacy `Component\Landings\LocalFile\LocalFileService` +
 * `Component\Landings\LocalFile\Validator\Validator` (old codebase:
 * application/Component/Landings/LocalFile/LocalFileService.php,
 * application/Component/Landings/LocalFile/Validator/Validator.php).
 *
 * Storage root mirrors legacy's ROOT-relative `lander` folder (legacy
 * `Bootstrap::initLocalFileService()`: `join("/", [ROOT, get(LP_DIR,
 * "lander")])`) — deliberately `base_path()`, not `storage_path('app/...')`,
 * because a future traffic-core will need to serve these files by HTTP from
 * the same on-disk path.
 *
 * Security note (deliberate improvement over legacy, not a fidelity gap):
 * legacy concatenates Editor's `path` request param directly into a
 * filesystem path with zero traversal checks. `resolveSafePath()` below is
 * new and rejects any `path` that resolves outside the target folder.
 */
class LocalFileService
{
    private const TMP_FOLDER = '_tmp';

    private const AVAILABLE_EXTENSIONS = ['php', 'html', 'css', 'js', 'txt'];

    private const FORBIDDEN_INDEX_FILES = ['kclient.php', 'kclick_client.php'];

    private const FORBIDDEN_EXTENSIONS = ['sh'];

    private const EXECUTABLE_PHP_EXTENSIONS = ['php', 'phtml', 'php5', 'php4'];

    private const INDEX_FILES = ['index.html', 'index.php'];

    public function lpDir(): string
    {
        return Setting::query()->where('key', 'lp_dir')->value('value') ?? 'lander';
    }

    public function getStoragePath(): string
    {
        return base_path($this->lpDir());
    }

    public function isPhpAllowed(): bool
    {
        $value = Setting::query()->where('key', 'lp_allow_php')->value('value');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function buildPath(string $folder): string
    {
        return rtrim($this->getStoragePath(), '/').'/'.trim($folder, '/');
    }

    /**
     * Resolve a user-supplied relative file path against a folder's real
     * storage path, guaranteeing the result stays inside it. Throws on
     * `..`/absolute-path escape attempts. New vs. legacy — see class
     * docblock.
     */
    public function resolveSafePath(string $folder, string $relativePath): string
    {
        $base = $this->buildPath($folder);
        $baseReal = realpath($base) ?: $base;

        $candidate = $baseReal.'/'.ltrim($relativePath, '/');
        $normalized = $this->normalizePath($candidate);

        if ($normalized !== $baseReal && ! str_starts_with($normalized, $baseReal.'/')) {
            throw new RuntimeException('Path is outside of the allowed folder');
        }

        return $normalized;
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }

    public function removeDirectory(string $systemPath): void
    {
        if (! $systemPath || ! is_dir($systemPath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($systemPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($systemPath);
    }

    public function copyDirectory(string $src, string $dst): void
    {
        if (empty($src)) {
            throw new RuntimeException('Empty src');
        }
        if ($src === $dst) {
            throw new RuntimeException('src == dst');
        }
        if (! is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $dst.'/'.$iterator->getSubPathName();
            if ($item->isDir()) {
                if (! is_dir($path)) {
                    mkdir($path, 0755);
                }
            } else {
                copy($item->getPathname(), $path);
            }
        }
    }

    public function renameFolder(string $oldFolder, string $newFolder): void
    {
        if (empty($oldFolder)) {
            return;
        }

        $old = $this->buildPath($oldFolder);
        $new = $this->buildPath($newFolder);

        if (is_dir($new)) {
            throw new RuntimeException("A folder named {$new} already exists");
        }
        if ($old !== $new && is_dir($old)) {
            rename($old, $new);
        }
    }

    /** Port of `ActionableResourceTrait::_generateFolderName()` + `_makeUniqueFolder()`. */
    public function generateUniqueFolder(string $name): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', trim($name)));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'lp';
        }

        $candidate = $slug;
        while (is_dir($this->buildPath($candidate))) {
            $candidate = $slug.'-'.Str::random(6);
        }

        return $candidate;
    }

    /**
     * Port of `LocalFileService::replaceFiles()`. `$fileData` is the raw
     * base64 payload as sent by the legacy frontend (optionally prefixed
     * with a `data:application/zip;base64,`-style data URI, same as
     * legacy's `_stringToFile()`).
     *
     * @throws IncompatibleLocalFileException on any validation failure.
     */
    public function replaceFiles(string $folder, string $fileData): void
    {
        if (empty($folder)) {
            throw new RuntimeException("Param 'folder' is empty");
        }
        if (empty($fileData)) {
            throw new IncompatibleLocalFileException('That is not valid ZIP file');
        }

        $systemPath = $this->buildPath($folder);
        $this->removeDirectory($systemPath);

        $tmpSystemPath = $this->unpack($systemPath, $fileData);

        try {
            $pathWithFiles = $this->findMainFolder($tmpSystemPath);
            $indexFile = $this->findIndexFile($pathWithFiles);
            $this->validate($pathWithFiles, $indexFile);

            $this->copyDirectory($pathWithFiles, $systemPath);
            $this->removeDirectory($tmpSystemPath);
        } catch (IncompatibleLocalFileException $exception) {
            $this->removeDirectory($tmpSystemPath);

            throw $exception;
        }
    }

    private function unpack(string $systemPath, string $fileData): string
    {
        $pos = strpos($fileData, ',');
        if ($pos !== false && str_starts_with($fileData, 'data:')) {
            $fileData = substr($fileData, $pos + 1);
        }

        $decoded = base64_decode($fileData, true);
        if ($decoded === false) {
            throw new IncompatibleLocalFileException('That is not valid ZIP file');
        }

        $tmpFileName = tempnam(sys_get_temp_dir(), 'lp.zip');
        file_put_contents($tmpFileName, $decoded);

        $zip = new ZipArchive();
        if ($zip->open($tmpFileName) !== true) {
            unlink($tmpFileName);

            throw new IncompatibleLocalFileException('That is not valid ZIP file');
        }

        $tmpSystemPath = $systemPath.'/'.self::TMP_FOLDER;
        if (! is_dir($tmpSystemPath) && ! mkdir($tmpSystemPath, 0777, true) && ! is_dir($tmpSystemPath)) {
            $zip->close();
            unlink($tmpFileName);

            throw new RuntimeException("Can't create directory {$tmpSystemPath}");
        }

        $zip->extractTo($tmpSystemPath);
        $zip->close();
        unlink($tmpFileName);

        return $tmpSystemPath;
    }

    public function findMainFolder(string $tmpPath): string
    {
        if ($this->findIndexFile($tmpPath) !== null) {
            return $tmpPath;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir() && $this->findIndexFile($file->getPathname()) !== null) {
                return $file->getPathname();
            }
        }

        return $tmpPath;
    }

    public function findIndexFile(string $systemPath): ?string
    {
        foreach (self::INDEX_FILES as $file) {
            if (file_exists($systemPath.'/'.$file)) {
                return $file;
            }
        }

        return null;
    }

    private function validate(string $systemPath, ?string $indexFile): void
    {
        $forbiddenFiles = $this->containsForbiddenFiles($systemPath);
        if (! empty($forbiddenFiles)) {
            throw new IncompatibleLocalFileException(
                'That archive contains forbidden files: '.implode(', ', $forbiddenFiles).'.'
            );
        }

        if (empty($indexFile)) {
            throw new IncompatibleLocalFileException(
                'The archive must contain file which name: '.implode(', ', self::INDEX_FILES).'.'
            );
        }

        $functionName = $this->containsForbiddenFunction($systemPath, $indexFile);
        if ($functionName !== null) {
            $lineNumber = $this->searchLineNumber($systemPath, $indexFile, $functionName);
            throw new IncompatibleLocalFileException(
                "Index file contains forbidden function: '{$functionName}' on line '{$lineNumber}'"
            );
        }

        $this->assertAllowedCharset($systemPath, $indexFile);
    }

    /** @return string[] relative paths of forbidden files found */
    private function containsForbiddenFiles(string $systemPath): array
    {
        $forbiddenExtensions = self::FORBIDDEN_EXTENSIONS;
        if (! $this->isPhpAllowed()) {
            $forbiddenExtensions = array_merge($forbiddenExtensions, self::EXECUTABLE_PHP_EXTENSIONS);
        }

        $list = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($systemPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (in_array($file->getFilename(), self::FORBIDDEN_INDEX_FILES, true) || in_array($ext, $forbiddenExtensions, true)) {
                $list[] = trim(substr($file->getPathname(), strlen($systemPath)), '/');
            }
        }

        return $list;
    }

    /** Returns the forbidden function name/pattern key, or null. */
    private function containsForbiddenFunction(string $systemPath, string $indexFile): ?string
    {
        $content = (string) file_get_contents($systemPath.'/'.$indexFile);

        if (str_contains($content, 'system(')) {
            return 'system()';
        }
        if (preg_match('/^\.exec\(/', $content)) {
            return 'exec()';
        }

        return null;
    }

    private function assertAllowedCharset(string $systemPath, string $indexFile): void
    {
        $content = (string) file_get_contents($systemPath.'/'.$indexFile);

        if (preg_match('/<meta[^>]+charset[^>]{1,5}(?:win|cp)[^>]{0,5}1251/mi', $content)) {
            throw new IncompatibleLocalFileException('Index file contains forbidden charset: windows-1251.');
        }
    }

    private function searchLineNumber(string $systemPath, string $indexFile, string $functionName): int
    {
        $pattern = $functionName === 'system()' ? 'system(' : '.exec(';
        $path = $systemPath.'/'.$indexFile;
        $lineNumber = 0;

        if ($handle = fopen($path, 'r')) {
            $count = 0;
            while (($line = fgets($handle, 4096)) !== false && ! $lineNumber) {
                $count++;
                if (str_contains($line, $pattern)) {
                    $lineNumber = $count;
                }
            }
            fclose($handle);
        }

        return $lineNumber;
    }

    public static function availableExtensions(): array
    {
        return self::AVAILABLE_EXTENSIONS;
    }
}
