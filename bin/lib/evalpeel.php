<?php
/**
 * Dynamic eval peeling via the php-eval-hook extension — library form.
 * See bin/evalpeel.php for the CLI wrapper and the sandbox rationale.
 */
declare(strict_types=1);

const EVALPEEL_DEFAULT_EXT = '/Users/fioa8c/WORK/php-eval-hook/modules/evalhook.so';

/**
 * @param array{timeout?:int,'max-layers'?:int,'stop-first'?:bool,ext?:string,php?:string,memory?:string} $o
 * @return array{layers: list<array{n:int,len:int,file:string,sha1:string,code:string}>, rc:int, killed:bool, secs:float, stderr:string, stdout:string}
 */
function evalPeel(string $file, array $o = []): array
{
    $o += ['timeout' => 10, 'max-layers' => 50, 'stop-first' => false, 'ext' => EVALPEEL_DEFAULT_EXT, 'php' => PHP_BINARY, 'memory' => '256M'];
    $DISABLED = [
        // process / code loading
        'exec','shell_exec','system','passthru','proc_open','proc_close','proc_get_status','proc_nice','proc_terminate',
        'popen','pclose','dl','pcntl_exec','pcntl_fork','pcntl_signal','pcntl_alarm','pcntl_wait','pcntl_waitpid',
        'posix_kill','posix_setuid','posix_seteuid','posix_setgid','apache_child_terminate','mail',
        // filesystem writes (reads stay enabled: self-decoding shells read __FILE__; open_basedir confines them)
        'file_put_contents','unlink','rename','copy','link','symlink','mkdir','rmdir','touch','chmod','chown','chgrp',
        'tempnam','tmpfile','move_uploaded_file','ftruncate','fputcsv','flock',
        // network
        'fsockopen','pfsockopen','stream_socket_client','stream_socket_server','stream_socket_accept','socket_create',
        'socket_connect','socket_bind','socket_listen','socket_accept','socket_write','socket_read','socket_send','socket_recv',
        'curl_init','curl_exec','curl_setopt','curl_setopt_array','curl_multi_init','curl_multi_exec','checkdnsrr',
        'dns_get_record','gethostbyname','gethostbynamel','gethostbyaddr','get_headers','ftp_connect','ftp_ssl_connect',
        // environment / state
        'putenv','ini_set','ini_alter','ini_restore','set_include_path','error_log','syslog','openlog',
        'phpinfo','set_error_handler','set_exception_handler','register_shutdown_function','session_start',
        'setcookie','header','header_remove','set_time_limit','sleep','usleep','time_nanosleep','time_sleep_until',
        'get_loaded_extensions','opcache_compile_file','opcache_invalidate',
    ];
    $DISABLED_CLASSES = ['mysqli','PDO','SQLite3','SplFileObject','SplTempFileObject','ZipArchive','SoapClient','Phar','PharData',
        'DirectoryIterator','FilesystemIterator','RecursiveDirectoryIterator','GlobIterator','CurlHandle','Socket'];

    $dir = sys_get_temp_dir() . '/evalpeel-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $sample = $dir . '/' . basename($file);
    copy($file, $sample);
    $nonce = bin2hex(random_bytes(8));
    $max = (int)$o['max-layers'];
    $stopFirst = $o['stop-first'] ? 'true' : 'false';
    $harness = <<<PHP
    <?php
    \$GLOBALS['__peel_n'] = 0;
    function __eval(\$code, \$file) {
        \$n = ++\$GLOBALS['__peel_n'];
        fwrite(STDOUT, "\\n__PEEL_{$nonce}_BEGIN n=\$n len=" . strlen(\$code) . " file=" . str_replace("\\n", " ", \$file) . "\\n" . \$code . "\\n__PEEL_{$nonce}_END\\n");
        if (\$n >= {$max} || {$stopFirst}) return false;
    }
    // Stubs for disabled functions: PHP 8 removes disabled builtins from the
    // function table, so user code may redefine them. A fatal "undefined
    // function" would stop the run before the next eval() layer; a benign stub
    // lets the sample keep going while still doing nothing real.
    if (!function_exists('usleep')) { function usleep(\$u) {} }
    if (!function_exists('sleep')) { function sleep(\$s) { return 0; } }
    if (!function_exists('set_time_limit')) { function set_time_limit(\$s) { return true; } }
    if (!function_exists('ini_set')) { function ini_set(\$k, \$v) { return false; } }
    if (!function_exists('ini_alter')) { function ini_alter(\$k, \$v) { return false; } }
    if (!function_exists('ini_restore')) { function ini_restore(\$k) {} }
    if (!function_exists('error_log')) { function error_log(...\$a) { return true; } }
    if (!function_exists('header')) { function header(...\$a) {} }
    if (!function_exists('header_remove')) { function header_remove(...\$a) {} }
    if (!function_exists('setcookie')) { function setcookie(...\$a) { return true; } }
    if (!function_exists('session_start')) { function session_start(...\$a) { return true; } }
    if (!function_exists('mail')) { function mail(...\$a) { return false; } }
    if (!function_exists('putenv')) { function putenv(\$s) { return false; } }
    if (!function_exists('file_put_contents')) { function file_put_contents(\$f, \$d, \$fl = 0, \$c = null) { return strlen(is_array(\$d) ? implode('', \$d) : (string)\$d); } }
    if (!function_exists('unlink')) { function unlink(...\$a) { return false; } }
    if (!function_exists('rename')) { function rename(...\$a) { return false; } }
    if (!function_exists('copy')) { function copy(...\$a) { return false; } }
    if (!function_exists('mkdir')) { function mkdir(...\$a) { return false; } }
    if (!function_exists('chmod')) { function chmod(...\$a) { return false; } }
    if (!function_exists('touch')) { function touch(...\$a) { return false; } }
    if (!function_exists('exec')) { function exec(\$c, &\$o = null, &\$r = null) { \$o = []; \$r = 127; return ''; } }
    if (!function_exists('shell_exec')) { function shell_exec(\$c) { return null; } }
    if (!function_exists('system')) { function system(\$c, &\$r = null) { \$r = 127; return ''; } }
    if (!function_exists('passthru')) { function passthru(\$c, &\$r = null) { \$r = 127; return null; } }
    if (!function_exists('curl_init')) { function curl_init(...\$a) { return false; } }
    if (!function_exists('curl_setopt')) { function curl_setopt(...\$a) { return false; } }
    if (!function_exists('curl_setopt_array')) { function curl_setopt_array(...\$a) { return false; } }
    if (!function_exists('curl_exec')) { function curl_exec(...\$a) { return false; } }
    if (!function_exists('curl_error')) { function curl_error(...\$a) { return 'disabled'; } }
    if (!function_exists('curl_getinfo')) { function curl_getinfo(...\$a) { return []; } }
    if (!function_exists('fsockopen')) { function fsockopen(...\$a) { return false; } }
    if (!function_exists('pfsockopen')) { function pfsockopen(...\$a) { return false; } }
    if (!function_exists('stream_socket_client')) { function stream_socket_client(...\$a) { return false; } }
    if (!function_exists('gethostbyname')) { function gethostbyname(\$h) { return \$h; } }
    if (!function_exists('set_error_handler')) { function set_error_handler(...\$a) { return null; } }
    if (!function_exists('set_exception_handler')) { function set_exception_handler(...\$a) { return null; } }
    if (!function_exists('register_shutdown_function')) { function register_shutdown_function(...\$a) {} }
    if (!function_exists('phpinfo')) { function phpinfo(...\$a) { return true; } }
    if (!function_exists('get_loaded_extensions')) { function get_loaded_extensions(...\$a) { return []; } }
    if (!function_exists('opcache_compile_file')) { function opcache_compile_file(...\$a) { return false; } }
    if (!function_exists('opcache_invalidate')) { function opcache_invalidate(...\$a) { return false; } }
    \$_SERVER['DOCUMENT_ROOT'] = '{$dir}';
    \$_SERVER['SCRIPT_FILENAME'] = '{$sample}';
    \$_SERVER['REQUEST_METHOD'] = 'GET';
    \$_SERVER['HTTP_HOST'] = 'localhost';
    \$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    \$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    \$_SERVER['REQUEST_URI'] = '/' . basename('{$sample}');
    chdir('{$dir}');
    include '{$sample}';
    PHP;
    file_put_contents($dir . '/harness.php', $harness);

    $cmd = [$o['php'], '-n', '-d', 'extension=' . $o['ext'], '-d', 'memory_limit=' . $o['memory'],
        '-d', 'max_execution_time=' . (int)$o['timeout'], '-d', 'open_basedir=' . $dir,
        '-d', 'disable_functions=' . implode(',', $DISABLED), '-d', 'disable_classes=' . implode(',', $DISABLED_CLASSES),
        '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL',
        '-d', 'zend.assertions=-1', '-d', 'output_buffering=0', $dir . '/harness.php'];
    $p = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $dir);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $t0 = microtime(true); $killed = false;
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) break;
        if (microtime(true) - $t0 > (int)$o['timeout'] + 2 || strlen($out) > 64 * 1024 * 1024) { proc_terminate($p, 9); $killed = true; break; }
        usleep(10000);
    }
    $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $rc = proc_close($p);

    $layers = [];
    if (preg_match_all("/__PEEL_{$nonce}_BEGIN n=(\d+) len=(\d+) file=([^\n]*)\n(.*?)\n__PEEL_{$nonce}_END\n/s", $out, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) $layers[] = ['n' => (int)$mm[1], 'len' => (int)$mm[2], 'file' => $mm[3], 'sha1' => sha1($mm[4]), 'code' => $mm[4]];
    }
    $stdout = preg_replace("/__PEEL_{$nonce}_BEGIN.*?__PEEL_{$nonce}_END\n/s", '', $out);
    // cleanup sandbox
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);

    return ['layers' => $layers, 'rc' => $rc, 'killed' => $killed, 'secs' => round(microtime(true) - $t0, 2),
        'stderr' => substr(trim($err), 0, 2000), 'stdout' => substr($stdout, 0, 2000)];
}
