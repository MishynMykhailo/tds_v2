<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Db;

/**
 * Port of legacy `Component\Landings\LocalFile\LocalFileService` (the
 * lookup/path half only — upload/replace/validate live in `backend/`'s
 * already-ported `App\Services\LocalFileService`, this class only READS
 * what that side already wrote to disk) + `Core\Sandbox\Sandbox` (the
 * PHP-execution half — see `bin/execute_local_file.php` for why this
 * runs under plain CLI SAPI via `proc_open` instead of legacy's
 * `php-cgi`, a documented infrastructure substitution, not a fidelity
 * cut).
 *
 * Storage path mirrors `backend/`'s `App\Services\LocalFileService::
 * getStoragePath()` (`base_path($lpDir)`) exactly — same physical
 * directory tree, so a landing uploaded via the already-ported
 * Editor/Cleaner admin screens is servable here unchanged. Overridable
 * via `LANDINGS_STORAGE_PATH` env (absolute path) for deployments where
 * `backend/` and `traffic-core/` aren't sibling directories; default
 * assumes the repo layout this project actually uses.
 */
class LocalFileSandbox
{
    private const DISABLED_FUNCTIONS = 'exec,shell_exec,system,passthru,proc_open,popen,proc_close,pcntl_exec,pcntl_fork';

    public function storagePath(): string
    {
        $override = getenv('LANDINGS_STORAGE_PATH');
        if ($override) {
            return rtrim($override, '/');
        }

        $repoRoot = dirname(__DIR__, 4);

        return $repoRoot . '/backend/' . $this->lpDir();
    }

    public function lpDir(): string
    {
        return $this->setting('lp_dir') ?? 'lander';
    }

    public function isPhpAllowed(): bool
    {
        $value = $this->setting('lp_allow_php');

        return $value !== null && filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function phpTimeout(): int
    {
        $value = $this->setting('lp_php_timeout');

        return $value !== null ? max(1, (int) $value) : 1;
    }

    /**
     * @throws \RuntimeException if `$folder` escapes the storage root.
     */
    public function resolveFolderPath(string $folder): string
    {
        $base = $this->storagePath();
        $candidate = rtrim($base, '/') . '/' . trim($folder, '/');
        $normalized = $this->normalizePath($candidate);
        $baseNormalized = $this->normalizePath($base);

        if ($normalized !== $baseNormalized && !str_starts_with($normalized, $baseNormalized . '/')) {
            throw new \RuntimeException('Path is outside of the allowed folder');
        }

        return $normalized;
    }

    public function findIndexFile(string $systemPath): ?string
    {
        foreach (['index.html', 'index.php'] as $file) {
            if (is_file($systemPath . '/' . $file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $rawClick
     * @return array{0:int,1:list<string>,2:string} [status, raw header lines, body]
     */
    public function execute(string $indexFilePath, \Psr\Http\Message\ServerRequestInterface $request, array $rawClick): array
    {
        $body = $request->getParsedBody();
        $params = [
            'server' => $request->getServerParams(),
            'get' => $request->getQueryParams(),
            'post' => is_array($body) ? $body : [],
            'cookie' => $request->getCookieParams(),
            'filepath' => $indexFilePath,
            'rawClick' => $rawClick,
        ];

        $command = [
            PHP_BINARY,
            '-d', 'disable_functions=' . self::DISABLED_FUNCTIONS,
            '-d', 'open_basedir=' . dirname($indexFilePath) . ':/tmp',
            __DIR__ . '/../../../bin/execute_local_file.php',
        ];

        return $this->runProcess($command, (string) json_encode($params), $this->phpTimeout());
    }

    /**
     * @param list<string> $command
     * @return array{0:int,1:list<string>,2:string}
     */
    private function runProcess(array $command, string $input, int $timeoutSeconds): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return [500, [], 'Internal error: cannot start local_file sandbox'];
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return [504, [], 'Timed out'];
            }

            usleep(10000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            $suffix = $stderr !== '' ? " ({$stderr})" : '';

            return [502, [], 'Internal error: sandbox produced invalid output' . $suffix];
        }

        return [
            (int) ($decoded['status'] ?? 200),
            array_values(array_filter((array) ($decoded['headers'] ?? []), 'is_string')),
            (string) ($decoded['body'] ?? ''),
        ];
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

        return '/' . implode('/', $parts);
    }

    private function setting(string $key): ?string
    {
        $stmt = Db::instance()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
