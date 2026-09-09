<?php

namespace PHPDeobfuscator\PureFunction;

/**
 * Runs purity-verified user functions for real, in a locked-down PHP
 * subprocess, and returns the scalar they produce.
 *
 * Obfuscators commonly funnel every string through a handful of decoder
 * functions built from loops, static lookup tables and character substitution.
 * Reducing those symbolically means reimplementing a PHP interpreter one
 * construct at a time; executing them is exact and construct-agnostic.
 *
 * Safety model, in order of importance:
 *  1. PurityAnalyzer must approve the function and its whole call graph first.
 *     That is the real gate - nothing reaches this class unverified.
 *  2. Execution happens in a separate `php -n` process with a disable_functions
 *     list covering process, filesystem, network and environment access, an
 *     open_basedir confined to the throwaway worker directory, a memory cap and
 *     an execution-time cap.
 *  3. A wall-clock timeout on our side kills a worker that stops responding.
 *
 * Note the worker keeps fgets/fwrite/fflush/feof enabled because it needs them
 * to speak the request protocol on stdin/stdout; a function that slipped past
 * the analyzer could therefore garble the protocol, but not reach the OS.
 *
 * The worker is persistent: definitions are sent once per distinct set, so
 * thousands of call sites cost one process spawn rather than thousands.
 */
class PureFunctionExecutor
{
    private const TIMEOUT_SECONDS = 10;

    private const DISABLED_FUNCTIONS = [
        // process / code loading
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'proc_close', 'proc_get_status',
        'proc_nice', 'proc_terminate', 'popen', 'pclose', 'dl', 'assert', 'create_function',
        'pcntl_exec', 'pcntl_fork', 'pcntl_signal', 'pcntl_alarm', 'pcntl_wait', 'pcntl_waitpid',
        'posix_kill', 'posix_setuid', 'posix_seteuid', 'posix_setgid', 'apache_child_terminate',
        // filesystem
        'fopen', 'tmpfile', 'file', 'file_get_contents', 'file_put_contents', 'readfile',
        'fpassthru', 'unlink', 'rename', 'copy', 'link', 'symlink', 'mkdir', 'rmdir', 'touch',
        'chmod', 'chown', 'chgrp', 'umask', 'opendir', 'readdir', 'scandir', 'glob', 'rewinddir',
        'closedir', 'realpath', 'tempnam', 'sys_get_temp_dir', 'move_uploaded_file', 'parse_ini_file',
        'file_exists', 'is_file', 'is_dir', 'is_readable', 'is_writable', 'is_executable',
        'fileperms', 'fileowner', 'filegroup', 'filesize', 'filemtime', 'stat', 'lstat', 'disk_free_space',
        // network
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server',
        'stream_socket_accept', 'socket_create', 'socket_connect', 'socket_bind', 'socket_listen',
        'socket_accept', 'socket_write', 'socket_read', 'curl_init', 'curl_exec', 'curl_setopt',
        'curl_multi_init', 'curl_multi_exec', 'mail', 'checkdnsrr', 'dns_get_record',
        'gethostbyname', 'gethostbynamel', 'gethostbyaddr', 'get_headers', 'fastcgi_finish_request',
        // environment / introspection / state
        'putenv', 'getenv', 'ini_set', 'ini_alter', 'ini_restore', 'set_include_path',
        'error_log', 'syslog', 'openlog', 'phpinfo', 'php_uname', 'getmypid', 'getmyuid',
        'get_current_user', 'set_error_handler', 'set_exception_handler',
        'register_shutdown_function', 'spl_autoload_register', 'session_start', 'setcookie',
        'header', 'headers_sent', 'extension_loaded', 'get_loaded_extensions', 'get_defined_vars',
        'get_defined_functions', 'getrusage', 'sleep', 'usleep', 'time_nanosleep', 'time_sleep_until',
    ];

    private string $phpBinary;
    private ?string $workerDir = null;
    /** @var resource|null */
    private $process = null;
    /** @var array{0:resource,1:resource,2:resource}|null */
    private ?array $pipes = null;
    /** @var array<string, true> function definitions already sent to the live worker */
    private array $sentDefs = [];
    /** Consecutive worker deaths; the sandbox gives up after too many. */
    private int $restarts = 0;
    private const MAX_RESTARTS = 3;

    public function __construct(?string $phpBinary = null)
    {
        $this->phpBinary = $phpBinary ?? (PHP_BINARY ?: 'php');
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    /**
     * Execute $funcName($args) inside the sandbox.
     *
     * Definitions are passed per function rather than per dependency set:
     * dependency sets overlap (two decoders sharing one table function), and
     * re-evaluating an already-defined function is an uncatchable
     * "cannot redeclare" fatal that would take the worker down.
     *
     * @param array<string, string> $defs function name => its PHP source
     * @param scalar[] $args
     * @return array{ok: bool, value?: mixed, error?: string}
     */
    public function call(array $defs, string $funcName, array $args, bool $retrying = false): array
    {
        if ($this->restarts > self::MAX_RESTARTS) {
            return ['ok' => false, 'error' => 'sandbox unavailable', 'kind' => 'transient'];
        }
        if (!$this->ensureWorker()) {
            $this->restarts++;
            return ['ok' => false, 'error' => 'sandbox failed to start', 'kind' => 'transient'];
        }
        $pending = [];
        foreach ($defs as $name => $source) {
            if (!isset($this->sentDefs[strtolower($name)])) {
                $pending[strtolower($name)] = $source;
            }
        }
        $response = $this->exchange([
            'defs' => $pending,
            'func' => $funcName,
            'args' => $args,
        ]);
        if ($response === null) {
            // Worker died or hung. Drop it so the next call gets a fresh one,
            // and forget what it had defined. This is infrastructure failure,
            // not a verdict on the function, so it is reported as transient -
            // blacklisting here would cascade to every dependent decoder.
            $this->shutdown();
            $this->restarts++;
            if (!$retrying) {
                return $this->call($defs, $funcName, $args, true);
            }
            return ['ok' => false, 'error' => 'sandbox timed out or died', 'kind' => 'transient'];
        }
        $this->restarts = 0;
        foreach ($pending as $name => $_) {
            $this->sentDefs[$name] = true;
        }
        return $response;
    }

    /** @return array|null */
    private function exchange(array $request): ?array
    {
        $payload = base64_encode(serialize($request)) . "\n";
        if (@fwrite($this->pipes[0], $payload) === false) {
            return null;
        }
        @fflush($this->pipes[0]);

        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $line = '';
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            $read = [$this->pipes[1]];
            $write = null;
            $except = null;
            $sec = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1000000);
            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                return null;
            }
            $chunk = @fgets($this->pipes[1]);
            if ($chunk === false || $chunk === '') {
                if (feof($this->pipes[1])) {
                    return null;
                }
                continue;
            }
            $line .= $chunk;
            if (substr($line, -1) === "\n") {
                break;
            }
        }
        $decoded = @unserialize(base64_decode(trim($line)), ['allowed_classes' => false]);
        return is_array($decoded) ? $decoded : null;
    }

    private function ensureWorker(): bool
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                return true;
            }
            $this->shutdown();
        }
        $this->sentDefs = [];

        $dir = sys_get_temp_dir() . '/phpdeobf-sandbox-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true)) {
            return false;
        }
        $this->workerDir = $dir;
        $workerPath = $dir . '/worker.php';
        if (@file_put_contents($workerPath, $this->workerSource()) === false) {
            return false;
        }

        $cmd = [
            $this->phpBinary,
            '-n',
            '-d', 'memory_limit=128M',
            '-d', 'max_execution_time=5',
            '-d', 'display_errors=0',
            '-d', 'log_errors=0',
            '-d', 'open_basedir=' . $dir,
            '-d', 'disable_functions=' . implode(',', self::DISABLED_FUNCTIONS),
            $workerPath,
        ];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes, $dir);
        if (!is_resource($process)) {
            return false;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->process = $process;
        $this->pipes = $pipes;
        return true;
    }

    public function shutdown(): void
    {
        if (is_resource($this->process)) {
            foreach ($this->pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            @proc_terminate($this->process);
            @proc_close($this->process);
        }
        $this->process = null;
        $this->pipes = null;
        if ($this->workerDir !== null && is_dir($this->workerDir)) {
            @unlink($this->workerDir . '/worker.php');
            @rmdir($this->workerDir);
        }
        $this->workerDir = null;
        $this->sentDefs = [];
    }

    /**
     * The sandbox worker. Reads base64(serialize(request)) lines on stdin,
     * writes base64(serialize(response)) lines on stdout.
     *
     * Each call is executed twice; a function whose second result differs is
     * reported as non-deterministic and refused, which catches state-carrying
     * functions that the static purity check let through.
     */
    private function workerSource(): string
    {
        return <<<'WORKER'
<?php

function respond(array $r): void
{
    fwrite(STDOUT, base64_encode(serialize($r)) . "\n");
    fflush(STDOUT);
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $req = @unserialize(base64_decode($line), ['allowed_classes' => false]);
    if (!is_array($req) || !isset($req['func']) || !is_array($req['args'] ?? null)) {
        respond(['ok' => false, 'error' => 'malformed request', 'kind' => 'call']);
        continue;
    }
    $failed = null;
    foreach ((array) ($req['defs'] ?? []) as $name => $source) {
        // function_exists guards against an uncatchable redeclare fatal.
        if (!is_string($name) || !is_string($source) || function_exists($name)) {
            continue;
        }
        ob_start();
        try {
            eval($source);
        } catch (\Throwable $e) {
            $failed = $e->getMessage();
        }
        ob_end_clean();
        if ($failed !== null) {
            break;
        }
    }
    if ($failed !== null) {
        respond(['ok' => false, 'error' => 'definition failed: ' . $failed, 'kind' => 'function']);
        continue;
    }
    $func = $req['func'];
    if (!is_string($func) || !function_exists($func)) {
        respond(['ok' => false, 'error' => 'undefined function', 'kind' => 'function']);
        continue;
    }
    foreach ($req['args'] as $a) {
        if ($a !== null && !is_scalar($a)) {
            respond(['ok' => false, 'error' => 'non-scalar argument', 'kind' => 'call']);
            continue 2;
        }
    }
    try {
        ob_start();
        $first = $func(...$req['args']);
        $second = $func(...$req['args']);
        $output = ob_get_clean();
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        respond(['ok' => false, 'error' => 'threw: ' . $e->getMessage(), 'kind' => 'call']);
        continue;
    }
    if ($output !== '') {
        respond(['ok' => false, 'error' => 'produced output', 'kind' => 'function']);
        continue;
    }
    if (serialize($first) !== serialize($second)) {
        respond(['ok' => false, 'error' => 'non-deterministic', 'kind' => 'function']);
        continue;
    }
    if ($first !== null && !is_scalar($first)) {
        respond(['ok' => false, 'error' => 'non-scalar result', 'kind' => 'call']);
        continue;
    }
    respond(['ok' => true, 'value' => $first]);
}
WORKER;
    }
}
