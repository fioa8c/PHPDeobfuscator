#!/usr/bin/env php
<?php
/**
 * Phase 0 — Threat library classify.
 *
 * Crawls selected collections in the Jetpack Threat Library; for each PHP
 * sample it launches the deobfuscator in a separate PHP subprocess (hard-killed
 * on timeout) for memory isolation and to survive hangs, then classifies the
 * result and writes JSON reports.
 *
 * Usage:
 *   php bin/classify.php --dir <name>... [--limit N] [--timeout N] [--maxbytes N]
 *
 * Defaults: obfuscation+webshells+php-backdoors-obfuscated+sample_breaking_parser,
 * limit 10 per collection, 30 s per file, 16 MiB max file size.
 */

declare(strict_types=1);

$LIBRARY          = '/Users/fioa8c/WORK/jetpack-threat-library';
$DEFAULT_DIRS     = ['obfuscation', 'webshells', 'php-backdoors-obfuscated', 'sample_breaking_parser'];
$DEFAULT_TIMEOUT  = 30;
$DEFAULT_MAXBYTES = 16 * 1024 * 1024;
$WORKER           = __DIR__ . '/_one.php';
$PHPBIN           = PHP_BINARY;

function parseArgs(array $argv): array
{
    array_shift($argv);
    $dirs    = [];
    $limit   = 10;
    $timeout = $GLOBALS['DEFAULT_TIMEOUT'];
    $maxbytes = $GLOBALS['DEFAULT_MAXBYTES'];
    while ($argv) {
        $arg = array_shift($argv);
        switch ($arg) {
            case '--dir':      $dirs[] = array_shift($argv) ?? ''; break;
            case '--limit':    $limit = (int)(array_shift($argv) ?? 10); break;
            case '--timeout':  $timeout = (int)(array_shift($argv) ?? 30); break;
            case '--maxbytes': $maxbytes = (int)(array_shift($argv) ?? (16*1024*1024)); break;
            default:           fwrite(STDERR, "Unknown arg: $arg\n"); exit(2);
        }
    }
    if (!$dirs) $dirs = $GLOBALS['DEFAULT_DIRS'];
    return compact('dirs','limit','timeout','maxbytes');
}

function safeName(string $p): string
{
    return str_replace(['/', '.'], '-', $p);
}

function drainStream($s)
{
    $buf = '';
    stream_set_blocking($s, false);
    while (($chunk = (string)@fread($s, 8192)) !== '') $buf .= $chunk;
    return $buf;
}

function runOne(string $path, int $maxbytes, int $timeout, string $library, string $worker, string $php): array
{
    $cmd  = [$php, $worker, $path, (string)$maxbytes, $library];
    $res  = proc_open($cmd,
        [0 => ['pipe', 'r'],
         1 => ['pipe', 'w'],
         2 => ['pipe', 'w']],
        $pipes);
    if (!is_resource($res)) return ['status' => 'TIMEOUT', 'reason' => 'proc_open failed'];
    fclose($pipes[0]);

    $out  = drainStream($pipes[1]);
    $start = microtime(true);
    $timedOut = false;

    while ($r = @proc_get_status($res)) {
        if ($r['running']) {
            $out .= drainStream($pipes[1]);
            drainStream($pipes[2]);
            if ((microtime(true) - $start) > $timeout) {
                @proc_terminate($res, 9);  // SIGKILL
                $timedOut = true;
            }
            usleep(25000);
            continue;
        }
        // Finished; pull remaining output.
        $out .= drainStream($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        $rc = $r['exitcode'];
        @proc_close($res);
        if ($timedOut) {
            return ['status' => 'TIMEOUT', 'reason' => "killed after {$timeout}s", 'partial' => substr($out, 0, 500)];
        }
        $json = trim($out);
        if ($json === '') return ['status' => 'TIMEOUT', 'reason' => 'no output', 'rc' => $rc];
        $data = json_decode($json, true);
        if (is_array($data)) return $data;
        return ['status' => 'TIMEOUT', 'reason' => "worker rc=$rc", 'partial' => substr($json, 0, 500)];
    }

    // Status went false (proc_open closed prematurely).
    fclose(@($pipes[1] ?? null) ?: fopen('php://memory','r'));
    fclose(@($pipes[2] ?? null) ?: fopen('php://memory','r'));
    @proc_close($res);
    return ['status' => 'TIMEOUT', 'reason' => 'process disappeared'];
}

function walkDir(string $dir, int $limit, array $cfg): array
{
    $files = [];
    $ri = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($ri as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') $files[] = $f->getPathname();
    }
    usort($files, fn($a, $b) => filesize($b) - filesize($a));
    $files = array_slice($files, 0, $limit);

    $reportDir = __DIR__ . '/../report/phase0/' . basename($dir);
    @mkdir($reportDir, 0755, true);

    $results = [];
    $n = count($files);
    for ($i = 0; $i < $n; $i++) {
        $f = $files[$i];
        fwrite(STDOUT, sprintf('[%d/%d] %s (%d B) ... ', $i+1, $n, basename($f), filesize($f)));
        fflush(STDOUT);
        $r = runOne($f, $cfg['maxbytes'], $cfg['timeout'], $cfg['library'], $cfg['worker'], $cfg['php']);
        $line = $r['status'];
        if (isset($r['reason'])) $line .= ' ('.$r['reason'].')';
        if (isset($r['origPrims'])) {
            $new = $r['newPrims'] ?? 'n/a';
            $line .= " [prims {$r['origPrims']}→$new]";
        }
        fwrite(STDOUT, $line . "\n");
        fflush(STDOUT);
        $results[] = $r;
        $rel = ltrim(str_replace($cfg['library'].'/', '', $f), '/');
        file_put_contents($reportDir . '/' . safeName($rel) . '.json',
            json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    return $results;
}

// ── Main ─────────────────────────────────────────────────────────────────────
$opts = parseArgs($_SERVER['argv']);
$cfg  = ['maxbytes' => $opts['maxbytes'], 'timeout' => $opts['timeout'],
         'library' => $LIBRARY, 'worker' => $WORKER, 'php' => $PHPBIN];

$summary = ['generatedAt' => date('c'), 'options' => $opts, 'dirs' => []];
$STATUSES = ['CLEAN_NO_OBF','CLEAN_FULL','RESIDUAL','UNREDUCED','PARSE_ERROR','TIMEOUT','SKIP'];

foreach ($opts['dirs'] as $dirName) {
    $dirPath = "$LIBRARY/$dirName";
    fwrite(STDOUT, "\n=== $dirName ===\n");
    if (!is_dir($dirPath)) {
        $summary['dirs'][] = ['dir' => $dirName, 'note' => 'not a directory'];
        fwrite(STDOUT, "  [SKIP] not a directory\n");
        continue;
    }
    $counts = array_fill_keys($STATUSES, 0);
    $pOrig = $pNew = 0;
    $results = walkDir($dirPath, $opts['limit'], $cfg);
    foreach ($results as $r) {
        $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
        $pOrig += $r['origPrims'] ?? 0;
        $pNew  += $r['newPrims']  ?? 0;
    }
    $summary['dirs'][] = [
        'dir' => $dirName, 'count' => count($results),
        'counts' => $counts, 'primsOrig' => $pOrig, 'primsAfter' => $pNew,
    ];
    fwrite(STDOUT, "  Subtotal: " . json_encode($counts) . "\n  Primitives: $pOrig → $pNew\n");
}

@mkdir(__DIR__ . '/../report/phase0', 0755, true);
file_put_contents(__DIR__ . '/../report/phase0/summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

fwrite(STDOUT, "\nDone. Reports in report/phase0/\n");
