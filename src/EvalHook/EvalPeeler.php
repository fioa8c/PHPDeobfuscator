<?php

namespace PHPDeobfuscator\EvalHook;

/**
 * Dynamic eval peeling using the php-eval-hook extension.
 *
 * The static pipeline reduces one layer of an `eval(decode(...))` chain and
 * stops; malware routinely nests these dozens deep (FOPO, "wso", z5encrypt).
 * This runs the sample for real, with eval() hooked, so every string handed to
 * eval() is captured *before* it executes - peeling the whole onion in one go.
 *
 * Executing attacker code is only acceptable inside a tight sandbox:
 *
 *   - a separate `php -n` process (no host php.ini, no other extensions);
 *   - `open_basedir` confined to a throwaway per-run directory;
 *   - `disable_functions` covering process, network, filesystem-write and
 *     environment access, and `disable_classes` covering DB/socket/archive/phar;
 *   - `allow_url_fopen` / `allow_url_include` off;
 *   - memory and execution-time caps plus a wall-clock kill;
 *   - disabled builtins are given benign stubs so a sample keeps running to its
 *     next eval() layer instead of dying on the first blocked call.
 *
 * File reads stay enabled (self-decoding shells read __FILE__), but the
 * open_basedir jail means they can only see the copied sample. Captured layers
 * are returned as source strings; nothing from the sample is ever written back
 * to the real filesystem.
 */
class EvalPeeler
{
    /** Functions removed from the sandbox (given benign stubs, see harness). */
    private const DISABLED_FUNCTIONS = [
        // process / code loading
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'proc_close', 'proc_get_status',
        'proc_nice', 'proc_terminate', 'popen', 'pclose', 'dl', 'pcntl_exec', 'pcntl_fork',
        'pcntl_signal', 'pcntl_alarm', 'pcntl_wait', 'pcntl_waitpid', 'posix_kill', 'posix_setuid',
        'posix_seteuid', 'posix_setgid', 'apache_child_terminate', 'mail',
        // filesystem writes (reads stay on; open_basedir confines them)
        'file_put_contents', 'unlink', 'rename', 'copy', 'link', 'symlink', 'mkdir', 'rmdir', 'touch',
        'chmod', 'chown', 'chgrp', 'tempnam', 'tmpfile', 'move_uploaded_file', 'ftruncate', 'fputcsv', 'flock',
        // network
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'stream_socket_accept',
        'socket_create', 'socket_connect', 'socket_bind', 'socket_listen', 'socket_accept', 'socket_write',
        'socket_read', 'socket_send', 'socket_recv', 'curl_init', 'curl_exec', 'curl_setopt',
        'curl_setopt_array', 'curl_multi_init', 'curl_multi_exec', 'curl_error', 'curl_getinfo',
        'checkdnsrr', 'dns_get_record', 'gethostbyname', 'gethostbynamel', 'gethostbyaddr', 'get_headers',
        'ftp_connect', 'ftp_ssl_connect',
        // environment / state
        'putenv', 'ini_set', 'ini_alter', 'ini_restore', 'set_include_path', 'error_log', 'syslog',
        'openlog', 'phpinfo', 'set_error_handler', 'set_exception_handler', 'register_shutdown_function',
        'session_start', 'setcookie', 'header', 'header_remove', 'set_time_limit', 'sleep', 'usleep',
        'time_nanosleep', 'time_sleep_until', 'get_loaded_extensions', 'opcache_compile_file',
        'opcache_invalidate',
    ];

    private const DISABLED_CLASSES = [
        'mysqli', 'PDO', 'SQLite3', 'SplFileObject', 'SplTempFileObject', 'ZipArchive', 'SoapClient',
        'Phar', 'PharData', 'DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator',
        'GlobIterator', 'CurlHandle', 'Socket',
    ];

    private string $extensionPath;
    private string $phpBinary;
    private int $timeout;
    private int $maxLayers;
    private string $memoryLimit;

    public function __construct(?string $extensionPath = null, array $opts = [])
    {
        $this->extensionPath = $extensionPath ?? self::locateExtension();
        $this->phpBinary = $opts['php'] ?? (PHP_BINARY ?: 'php');
        $this->timeout = (int)($opts['timeout'] ?? 10);
        $this->maxLayers = (int)($opts['maxLayers'] ?? 60);
        $this->memoryLimit = (string)($opts['memory'] ?? '256M');
    }

    /**
     * Where the compiled evalhook extension lives. Override with the
     * PHPDEOBF_EVALHOOK environment variable; otherwise look next to this repo.
     */
    public static function locateExtension(): string
    {
        $env = getenv('PHPDEOBF_EVALHOOK');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return dirname(__DIR__, 2) . '/../php-eval-hook/modules/evalhook.so';
    }

    public function isAvailable(): bool
    {
        return is_file($this->extensionPath);
    }

    /**
     * Capture every eval() layer produced while running $code.
     *
     * @return array{
     *   ok: bool, layers: list<array{n:int,len:int,file:string,sha1:string,code:string}>,
     *   rc: int, killed: bool, secs: float, stderr: string, stdout: string, error?: string
     * }
     */
    public function peel(string $code, string $sampleBasename = 'sample.php'): array
    {
        if (!$this->isAvailable()) {
            return $this->fail("evalhook extension not found at {$this->extensionPath}");
        }
        $dir = sys_get_temp_dir() . '/phpdeobf-peel-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true)) {
            return $this->fail("could not create sandbox dir");
        }
        try {
            return $this->run($code, $sampleBasename, $dir);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    private function run(string $code, string $sampleBasename, string $dir): array
    {
        $sample = $dir . '/' . basename($sampleBasename);
        file_put_contents($sample, $this->normalizeLegacyStringOffsets($code));
        $nonce = bin2hex(random_bytes(8));
        file_put_contents($dir . '/harness.php', $this->harnessSource($dir, $sample, $nonce));

        $cmd = [
            $this->phpBinary, '-n',
            '-d', 'extension=' . $this->extensionPath,
            '-d', 'memory_limit=' . $this->memoryLimit,
            '-d', 'max_execution_time=' . $this->timeout,
            '-d', 'open_basedir=' . $dir,
            '-d', 'disable_functions=' . implode(',', self::DISABLED_FUNCTIONS),
            '-d', 'disable_classes=' . implode(',', self::DISABLED_CLASSES),
            '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
            '-d', 'display_errors=stderr', '-d', 'error_reporting=0',
            '-d', 'zend.assertions=-1', '-d', 'output_buffering=0',
            $dir . '/harness.php',
        ];
        $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        if (!is_resource($p)) {
            return $this->fail("could not launch sandbox process");
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $t0 = microtime(true);
        $killed = false;
        while (true) {
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) {
                break;
            }
            if (microtime(true) - $t0 > $this->timeout + 2 || strlen($out) > 64 * 1024 * 1024) {
                proc_terminate($p, 9);
                $killed = true;
                break;
            }
            usleep(10000);
        }
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($p);

        $layers = [];
        $pattern = "/__PEEL_{$nonce}_BEGIN n=(\\d+) len=(\\d+) file=([^\\n]*)\\n(.*?)\\n__PEEL_{$nonce}_END\\n/s";
        if (preg_match_all($pattern, $out, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                if (trim($mm[4]) === '') {
                    continue; // eval('') from a failed decode says nothing
                }
                $layers[] = ['n' => (int)$mm[1], 'len' => (int)$mm[2], 'file' => $mm[3],
                    'sha1' => sha1($mm[4]), 'code' => $mm[4]];
            }
        }
        $stdout = preg_replace("/__PEEL_{$nonce}_BEGIN.*?__PEEL_{$nonce}_END\\n/s", '', $out);
        return [
            'ok' => true, 'layers' => $layers, 'rc' => $rc, 'killed' => $killed,
            'secs' => round(microtime(true) - $t0, 2),
            'stderr' => substr(trim((string)$err), 0, 4000), 'stdout' => substr((string)$stdout, 0, 4000),
        ];
    }

    private function harnessSource(string $dir, string $sample, string $nonce): string
    {
        $max = $this->maxLayers;
        $stubs = $this->stubSource();
        return <<<PHP
<?php
\$GLOBALS['__peel_n'] = 0;
function __eval(\$code, \$file) {
    \$n = ++\$GLOBALS['__peel_n'];
    fwrite(STDOUT, "\\n__PEEL_{$nonce}_BEGIN n=\$n len=" . strlen(\$code) . " file=" . str_replace("\\n", " ", \$file) . "\\n" . \$code . "\\n__PEEL_{$nonce}_END\\n");
    if (\$n >= {$max}) return false;
}
{$stubs}
\$_SERVER['DOCUMENT_ROOT'] = '{$dir}';
\$_SERVER['SCRIPT_FILENAME'] = '{$sample}';
\$_SERVER['SCRIPT_NAME'] = '/' . basename('{$sample}');
\$_SERVER['PHP_SELF'] = '/' . basename('{$sample}');
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['HTTP_HOST'] = 'localhost';
\$_SERVER['SERVER_NAME'] = 'localhost';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
\$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
\$_SERVER['REQUEST_URI'] = '/' . basename('{$sample}');
chdir('{$dir}');
include '{$sample}';
PHP;
    }

    /**
     * Benign replacements for disabled builtins. PHP 8 removes a disabled
     * builtin from the function table entirely, so a call to it is a fatal
     * "undefined function" that stops the run before the next eval() layer.
     * A no-op stub keeps the sample moving while still doing nothing real.
     *
     * The same trick covers builtins PHP itself removed that old shells still
     * call before their first eval(): the `magic_quotes` family (gone in PHP 8)
     * gets benign stubs, `each()` (gone in PHP 8) a working shim, and
     * `create_function()` (gone in PHP 8) a shim that rebuilds the function via
     * eval() — deliberately, because the created body is itself a payload the
     * hook should capture.
     */
    private function stubSource(): string
    {
        // NB: the benign no-op stubs below never use eval() - the hook captures
        // every eval(), so a benign stub that eval()'d to define itself would
        // show up as a spurious layer. create_function() is the one intentional
        // exception (its eval reveals the created function body).
        $names = [
            'set_magic_quotes_runtime', 'magic_quotes_runtime',
            'usleep', 'sleep', 'set_time_limit', 'ini_set', 'ini_alter', 'ini_restore', 'error_log',
            'header', 'header_remove', 'setcookie', 'session_start', 'mail', 'putenv', 'unlink', 'rename',
            'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'chown', 'chgrp', 'curl_setopt', 'curl_setopt_array',
            'curl_close', 'curl_multi_init', 'curl_multi_exec', 'phpinfo', 'set_error_handler',
            'set_exception_handler', 'register_shutdown_function', 'opcache_compile_file',
            'opcache_invalidate', 'syslog', 'openlog', 'flock', 'ftruncate', 'symlink', 'link',
            'proc_terminate', 'posix_kill', 'apache_child_terminate',
        ];
        $out = '';
        foreach ($names as $n) {
            $out .= "if (!function_exists('{$n}')) { function {$n}(...\$a) { return null; } }\n";
        }
        // Stubs with a more useful return value / signature than null.
        $out .= <<<'PHP'
if (!function_exists('file_put_contents')) { function file_put_contents($f, $d, $fl = 0, $c = null) { return strlen(is_array($d) ? implode('', $d) : (string)$d); } }
if (!function_exists('exec'))         { function exec($c, &$o = null, &$r = null) { $o = []; $r = 127; return ''; } }
if (!function_exists('shell_exec'))   { function shell_exec($c) { return null; } }
if (!function_exists('system'))       { function system($c, &$r = null) { $r = 127; return ''; } }
if (!function_exists('passthru'))     { function passthru($c, &$r = null) { $r = 127; return null; } }
if (!function_exists('curl_init'))    { function curl_init(...$a) { return false; } }
if (!function_exists('curl_exec'))    { function curl_exec(...$a) { return false; } }
if (!function_exists('curl_error'))   { function curl_error(...$a) { return 'disabled'; } }
if (!function_exists('curl_getinfo')) { function curl_getinfo(...$a) { return []; } }
if (!function_exists('fsockopen'))    { function fsockopen(...$a) { return false; } }
if (!function_exists('pfsockopen'))   { function pfsockopen(...$a) { return false; } }
if (!function_exists('stream_socket_client')) { function stream_socket_client(...$a) { return false; } }
if (!function_exists('gethostbyname')) { function gethostbyname($h) { return $h; } }
if (!function_exists('get_magic_quotes_gpc'))     { function get_magic_quotes_gpc() { return false; } }
if (!function_exists('get_magic_quotes_runtime')) { function get_magic_quotes_runtime() { return false; } }
if (!function_exists('each')) { function each(&$a) { $k = key($a); if ($k === null) { return false; } $v = current($a); next($a); return [1 => $v, 'value' => $v, 0 => $k, 'key' => $k]; } }
if (!function_exists('create_function')) { function create_function($args, $code) { return eval("return function({$args}) { {$code} };"); } }
PHP;
        return $out;
    }

    /**
     * Rewrite legacy curly-brace string offsets (`$s{$i}`, `$arr[0]{2}`) to the
     * bracket form (`$s[$i]`, `$arr[0][2]`) so the sandbox's PHP 8 can parse the
     * sample. This syntax was removed in PHP 8.0, and the huge FOPO / self-reading
     * shell family (`$name = $s{4}.$s{9}.…;`) is built entirely from it — under
     * PHP 8 those samples fatal at parse time and never reach their first eval(),
     * so the peeler captured nothing.
     *
     * The rewrite is **byte-length preserving** ('{'->'[' and '}'->']', one char
     * for one char): critical because these shells also do
     * `file_get_contents(__FILE__)` and slice themselves by fixed byte offset, so
     * shifting any byte would break the self-decode. The encoded payload is a
     * contiguous base64/binary run with no `$var{` token in it, so it is never
     * touched.
     *
     * Conservative: only a '{' that directly follows a variable or a closed
     * access (`]` / a converted offset `}`) becomes an offset — a '{' after ')'
     * (an `if (...) {` block) or a string-interpolation brace (`"{$x}"`, `"${x}"`)
     * is left alone. If the token stream cannot be classified unambiguously the
     * original source is returned untouched, so a bad rewrite can never be
     * executed.
     */
    public function normalizeLegacyStringOffsets(string $code): string
    {
        $tokens = @token_get_all($code);
        if (!is_array($tokens) || $tokens === []) {
            return $code;
        }
        $out = '';
        $stack = [];          // 'offset' | 'block' for each open plain '{'
        $pendingInterp = 0;   // unmatched string-interpolation opens ("{$..}/${..}")
        $prevWasAccessEnd = false; // prev significant token ends an accessible value

        foreach ($tokens as $tok) {
            if (is_array($tok)) {
                $id = $tok[0];
                $text = $tok[1];
                if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                    $out .= $text;
                    continue; // does not change access context
                }
                if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $pendingInterp++;         // its closing '}' is a plain char token
                    $out .= $text;
                    $prevWasAccessEnd = false;
                    continue;
                }
                $out .= $text;
                // A value we can index: a variable, or the string/array it may sit in.
                $prevWasAccessEnd = ($id === T_VARIABLE);
                continue;
            }

            if ($tok === '{') {
                if ($prevWasAccessEnd) {
                    $stack[] = 'offset';
                    $out .= '[';
                } else {
                    $stack[] = 'block';
                    $out .= '{';
                }
                $prevWasAccessEnd = false;
                continue;
            }
            if ($tok === '}') {
                if ($pendingInterp > 0) {
                    $pendingInterp--;         // closes a string interpolation
                    $out .= '}';
                    $prevWasAccessEnd = false;
                    continue;
                }
                if ($stack === []) {
                    return $code;             // unbalanced: refuse to rewrite
                }
                $kind = array_pop($stack);
                if ($kind === 'offset') {
                    $out .= ']';
                    $prevWasAccessEnd = true;  // `$a{0}{1}` / `$a{0}[1]` chaining
                } else {
                    $out .= '}';
                    $prevWasAccessEnd = false;
                }
                continue;
            }

            $out .= $tok;
            // `]` and `)` end a value that can be indexed further; only `]` may be
            // followed by a curly offset (`$a[0]{1}`) — `)` before `{` is a block.
            $prevWasAccessEnd = ($tok === ']');
        }

        if ($stack !== [] || $pendingInterp !== 0 || strlen($out) !== strlen($code)) {
            return $code; // anything unexpected: return the original untouched
        }
        return $out;
    }

    private function fail(string $msg): array
    {
        return ['ok' => false, 'layers' => [], 'rc' => -1, 'killed' => false, 'secs' => 0.0,
            'stderr' => '', 'stdout' => '', 'error' => $msg];
    }
}
