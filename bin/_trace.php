<?php
// Run the deobfuscator on a file and dump a backtrace when it exceeds N seconds.
// Usage: php bin/_trace.php <file> [seconds] [exec 0|1]
require __DIR__ . '/../vendor/autoload.php';
use PHPDeobfuscator\Deobfuscator;
$file = $argv[1]; $secs = (int)($argv[2] ?? 10); $exec = (bool)($argv[3] ?? 0);
pcntl_async_signals(true);
$GLOBALS['__samples'] = (int)($argv[4] ?? 1);
pcntl_signal(SIGALRM, function () use ($secs) {
    static $n = 0; $n++;
    fwrite(STDERR, "=== sample $n (t=" . ($n * $secs) . "s) ===\n");
    $depth = $GLOBALS['__samples'] > 1 ? 12 : 40;
    foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1, $depth) as $i => $fr) {
        fwrite(STDERR, sprintf("#%d %s:%d %s%s%s\n", $i, basename($fr['file'] ?? '?'), $fr['line'] ?? 0, $fr['class'] ?? '', $fr['type'] ?? '', $fr['function'] ?? ''));
    }
    if ($n >= $GLOBALS['__samples']) exit(3);
    pcntl_alarm($secs);
});
pcntl_alarm($secs);
$c = file_get_contents($file);
$d = new Deobfuscator(false, false, false, $exec);
$d->getFilesystem()->write('/var/www/html/' . basename($file), $c);
$d->setCurrentFilename('/var/www/html/' . basename($file));
$t = $d->deobfuscate($d->parse($c));
echo substr($d->prettyPrint($t), 0, 1500), "\n";
