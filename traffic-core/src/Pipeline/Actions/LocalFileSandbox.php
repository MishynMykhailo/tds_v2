<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Db;

/**
 * Port of legacy `Component\Landings\LocalFile\LocalFileService` (the
 * lookup/path half only — upload/replace/validate live in `backend/`'s
 * already-ported `App\Services\LocalFileService`, this class only READS
 * what that side already wrote to disk) + `Core\Sandbox\Sandbox` +
 * `Core\Sandbox\CgiExecutor\CgiExecutor` (the PHP-execution half —
 * application/Core/Sandbox/Sandbox.php,
 * application/Core/Sandbox/CgiExecutor/CgiExecutor.php).
 *
 * Full technical parity with legacy's execution engine, both tiers:
 * `SandboxFactory::create()` (application/Core/Sandbox/SandboxFactory/
 * SandboxFactory.php) prefers a pooled FastCGI worker
 * (`FcgiExecutor`/`cgi-fcgi -bind -connect <socket>`) when one is
 * reachable, falling back to spawning a fresh `php-cgi` per request
 * (`CgiExecutor`) otherwise. Phase 16 ports the FCGI tier: if
 * `PHP_FPM_HOST` is set and a pool is actually reachable there (checked
 * live via a short `fsockopen`, not just "the env var exists" — matches
 * legacy's `FcgiExecutor::isAvailable()` doing a real filesystem check
 * on its Unix-socket path, not just trusting config), requests go
 * through `cgi-fcgi` to that persistent pool (`deploy/
 * php-fpm-local-file-pool.conf` + `docker-compose.yml`'s
 * `traffic-core-php-fpm` service) instead of paying process-spawn cost
 * per landing hit. Falls back to the Phase 8 per-process `php-cgi` path
 * automatically and silently otherwise — this class's public interface
 * doesn't change either way.
 *
 * Both tiers talk the same real CGI wire protocol (headers, blank line,
 * body) — see `bin/execute_local_file.php` for the receiving side, and
 * `deploy/Dockerfile.dev-php`'s comment for why `php-cgi`/`php-fpm`
 * aren't installable as Debian packages here and are built from the
 * same PHP source tree as the image's `php` CLI SAPI instead (same
 * Zend/TSRM ABI, so `pdo_mysql`/`pdo_sqlite`/etc. load into both
 * unchanged). CGI binary location: `PHP_CGI_BINARY` env if set, else the
 * same search legacy's `CgiExecutor::_findCGIBinary()` does (`/usr/bin/`,
 * `dirname(getenv('PHP_BINARY'))`, `PHP_BINDIR`, trying
 * `php\<major><minor>-cgi` then `php-cgi` in each).
 *
 * **Hardening trade-off, FCGI tier vs. CGI tier (documented, not
 * accidental)**: the CGI tier's `disable_functions`/`open_basedir` are
 * passed per-request via `-d` flags to a fresh process, scoped to the
 * CURRENT landing's own folder. A shared FPM pool can't accept
 * per-request `-d` overrides through `cgi-fcgi` (a protocol bridge, not
 * a process this class controls) — the pool's `open_basedir` is
 * therefore a STATIC setting scoped to the whole landings storage root,
 * not the current request's specific folder (see the pool conf's own
 * comment). Still strictly better than legacy (hardens neither tier at
 * all); just less granular on the pooled path.
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

    private const HEADERS_SEPARATOR = "\r\n\r\n";

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

        // Same wire shape as legacy's `Sandbox::execute()`: the real
        // request data travels as a single urlencoded `params` POST
        // field (JSON-encoded), never through the CGI env — the CGI vars
        // below are deliberately the same placeholder set legacy uses,
        // not a real per-request CGI environment.
        $input = 'params=' . urlencode((string) json_encode($params));

        // `proc_open()`'s explicit-$env form REPLACES the child's whole
        // environment (unlike Symfony Process, which legacy uses and
        // which merges) — layer the CGI placeholders over the current
        // environment (left side wins on key collision) so PATH/HOME/
        // etc. survive while the CGI vars stay deterministic.
        // `php-cgi` rejects a `SCRIPT_FILENAME` containing `..` segments
        // ("No input file specified.") — must be a clean absolute path.
        $env = [
            'REDIRECT_STATUS' => 'true',
            'SCRIPT_FILENAME' => (string) realpath(__DIR__ . '/../../../bin/execute_local_file.php'),
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => '127.127.127.127',
            'CONTENT_LENGTH' => (string) strlen($input),
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ] + (getenv() ?: []);

        $fcgiConnection = $this->findFcgiConnection();
        $cgiFcgiBinary = $fcgiConnection !== null ? $this->findCgiFcgiBinary() : null;

        if ($fcgiConnection !== null && $cgiFcgiBinary !== null) {
            $command = [$cgiFcgiBinary, '-bind', '-connect', $fcgiConnection];

            return $this->runProcess($command, $env, $input, $this->phpTimeout());
        }

        $cgiBinary = $this->findCgiBinary();
        if ($cgiBinary === null) {
            return [500, [], 'Internal error: neither a reachable php-fpm pool nor a php-cgi binary was found'];
        }

        $command = [
            $cgiBinary,
            '-d', 'disable_functions=' . self::DISABLED_FUNCTIONS,
            '-d', 'open_basedir=' . dirname($indexFilePath) . ':/tmp:' . dirname(__DIR__, 3) . '/bin',
        ];

        return $this->runProcess($command, $env, $input, $this->phpTimeout());
    }

    /**
     * `PHP_FPM_HOST`/`PHP_FPM_PORT` env-configured (default port 9070,
     * matching `deploy/docker-compose.yml`'s `traffic-core-php-fpm`
     * service and `deploy/php-fpm-local-file-pool.conf`'s `listen`
     * directive) — but only used if a real, live connection actually
     * succeeds (a short `fsockopen`, immediately closed — this is a
     * liveness probe, not the request itself). Mirrors legacy's
     * `FcgiExecutor::isAvailable()` checking the real Unix-socket file
     * rather than trusting config alone; TCP has no filesystem
     * equivalent to stat, so an actual connect attempt is the closest
     * honest equivalent. Returns null (silently, not an error) if
     * `PHP_FPM_HOST` is unset or the pool isn't reachable — `execute()`
     * falls back to the per-process CGI tier either way.
     */
    private function findFcgiConnection(): ?string
    {
        $host = getenv('PHP_FPM_HOST');
        if (!$host) {
            return null;
        }

        $port = (int) (getenv('PHP_FPM_PORT') ?: 9070);

        $socket = @fsockopen($host, $port, $errno, $errstr, 0.3);
        if ($socket === false) {
            return null;
        }
        fclose($socket);

        return "{$host}:{$port}";
    }

    private function findCgiFcgiBinary(): ?string
    {
        $override = getenv('CGI_FCGI_BINARY');
        if ($override && is_executable($override)) {
            return $override;
        }

        foreach (['/usr/bin/cgi-fcgi', '/usr/local/bin/cgi-fcgi'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function findCgiBinary(): ?string
    {
        $override = getenv('PHP_CGI_BINARY');
        if ($override && is_executable($override)) {
            return $override;
        }

        $dirs = ['/usr/bin/', '/usr/local/bin/'];
        $phpBinary = getenv('PHP_BINARY') ?: PHP_BINARY;
        if ($phpBinary) {
            $dirs[] = rtrim(dirname($phpBinary), '/') . '/';
        }
        $dirs[] = rtrim(PHP_BINDIR, '/') . '/';

        $names = ['php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '-cgi', 'php-cgi'];

        foreach ($dirs as $dir) {
            foreach ($names as $name) {
                $path = $dir . $name;
                if (is_executable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $env
     * @return array{0:int,1:list<string>,2:string}
     */
    private function runProcess(array $command, array $env, string $input, int $timeoutSeconds): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, null, $env);

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

        return $this->parseCgiOutput($stdout, $stderr);
    }

    /**
     * Port of legacy `Sandbox::_parseOutputToResponse()`/`_applyHeaders()`
     * — real CGI wire format: headers, a blank line, then the body.
     * `Status:` sets the HTTP status; every other header is passed
     * through as-is. Legacy's `Location:`-rewriting special case (prefix
     * a relative redirect with the landing's own routed URL path) is NOT
     * ported — same reason as `HtmlPathAdapter::addBasePath()` not being
     * ported: no local URL concept for a landing page in traffic-core yet.
     *
     * @return array{0:int,1:list<string>,2:string}
     */
    private function parseCgiOutput(string $output, string $stderr): array
    {
        $headerEnd = strpos($output, self::HEADERS_SEPARATOR);
        if ($headerEnd === false) {
            $suffix = $stderr !== '' ? " ({$stderr})" : '';

            return [502, [], 'Internal error: sandbox produced invalid output' . $suffix];
        }

        $headerBlock = substr($output, 0, $headerEnd);
        $body = substr($output, $headerEnd + strlen(self::HEADERS_SEPARATOR));

        $status = 200;
        $headers = [];

        foreach (explode("\n", $headerBlock) as $line) {
            $line = trim($line, "\r");
            if ($line === '') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if (strcasecmp($name, 'Status') === 0) {
                $status = (int) $value;
                continue;
            }

            $headers[] = $name . ': ' . $value;
        }

        return [$status, $headers, $body];
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
