#!/usr/bin/env php
<?php
/**
 * Phase 1 — re-run files the static reducer could not finish (UNREDUCED,
 * TIMEOUT, and RESIDUAL) with pure-function execution enabled (-x) to see if
 * the sandboxed decoder runs crack them open. Writes improved/unchanged
 * comparison reports and the deobfuscated source for each improved file.
 *
 * Usage:
 *   php bin/phase1.php [--limit N] [--timeout N] [--include-residual]
 *
 * Defaults: limit 25 total, 90 s per file, residual included.
 */

declare(strict_types=1);

$LIBRARY     = '/Users/fioa8c/WORK/jetpack-threat-library';
$PHASE0      = __DIR__ . '/../report/phase0';
$OUT_DIR     = __DIR__ . '/../out/phase1';
$WORKER      = __DIR__ . '/_one.php';
$PHPBIN      = PHP_BINARY;
$DEFAULT_TIMEOUT = 90;
$DEFAULT_LIMIT   = 25;
$DEFAULT_MAXBYTES = 16 * 1024 * 1024;

function parseArgs(array $argv): array
{
    array_shift($argv);
    $limit = $GLOBALS['DEFAULT_LIMIT'];
    $timeout = $GLOBALS['DEFAULT_TIMEOUT'];
    $maxbytes = $GLOBALS['DEFAULT_MAXBYTES'];
    $includeResidual = true;
    while ($argv) {
        $arg = array_shift($argv);
        switch ($arg) {
            case '--limit':            $limit = (int)(array_shift($argv) ?? $GLOBALS['DEFAULT_LIMIT']); break;
            case '--timeout':          $timeout = (int)(array_shift($argv) ?? $GLOBALS['DEFAULT_TIMEOUT']); break;
            case '--maxbytes':         $maxbytes = (int)(array_shift($argv) ?? $GLOBALS['DEFAULT_MAXBYTES']); break;
            case '--include-residual': $includeResidual = true; break;
            case '--no-residual':      $includeResidual = false; break;
            default: fwrite(STDERR, "Unknown arg: $arg\n"); exit(2);
        }
    }
    return compact('limit','timeout','maxbytes','includeResidual');
}

function drainStream($s)
{
    $buf = '';
    stream_set_blocking($s, false);
    while (($chunk = (string)@fread($s, 8192)) !== '') $buf .= $chunk;
    return $buf;
}

function runOneExec(string $path, int $maxbytes, int $timeout, string $deobfPath, string $worker, string $php): array
{
    $cmd  = [$php, $worker, $path, (string)$maxbytes, $GLOBALS['LIBRARY'], '1', $deobfPath];
    $res  = proc_open($cmd,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes);
    if (!is_resource($res)) return ['status' => 'TIMEOUT', 'reason' => 'proc_open failed'];
    fclose($pipes[0]);
    $out = drainStream($pipes[1]);
    $start = microtime(true);
    $timedOut = false;

    while ($r = @proc_get_status($res)) {
        if ($r['running']) {
            $out .= drainStream($pipes[1]);
            drainStream($pipes[2]);
            if ((microtime(true) - $start) > $timeout) {
                @proc_terminate($res, 9);
                $timedOut = true;
            }
            usleep(25000);
            continue;
        }
        $out .= drainStream($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        $rc = $r['exitcode'];
        @proc_close($res);
        if ($timedOut) return ['status' => 'TIMEOUT', 'reason' => "killed after {$timeout}s", 'partial' => substr($out, 0, 500)];
        $json = trim($out);
        if ($json === '') return ['status' => 'TIMEOUT', 'reason' => 'no output', 'rc' => $rc];
        $data = json_decode($json, true);
        if (is_array($data)) return $data;
        return ['status' => 'TIMEOUT', 'reason' => "worker rc=$rc", 'partial' => substr($json, 0, 500)];
    }
    @fclose($pipes[1] ?? null); @fclose($pipes[2] ?? null); @proc_close($res);
    return ['status' => 'TIMEOUT', 'reason' => 'process disappeared'];
}

function safeName(string $p): string
{
    return str_replace(['/', '.'], '-', $p);
}

function collectTargets(array $opts, string $phase0Dir): array
{
    $candidates = [];
    $seen = [];
    foreach (glob($phase0Dir . '/*') ?: [] as $d) {
        if (!is_dir($d)) continue;
        foreach (glob($d . '/*.json') ?: [] as $f) {
            if (basename($f) === 'summary.json') continue;
            $rep = json_decode((string)file_get_contents($f), true);
            if (!is_array($rep) || empty($rep['file'])) continue;
            $key = $rep['file'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $st = $rep['status'] ?? '';
            $isTarget = $st === 'UNREDUCED' || $st === 'TIMEOUT' || ($opts['includeResidual'] && $st === 'RESIDUAL');
            if (!$isTarget) continue;
            // Rank: TIMEOUT first, then UNREDUCED, then RESIDUAL with the most primitives left.
            $prio = 2;
            if ($st === 'TIMEOUT') $prio = 0; elseif ($st === 'UNREDUCED') $prio = 1;
            $candidates[] = ['prio' => $prio, 'prims' => (int)($rep['newPrims'] ?? $rep['origPrims'] ?? 0),
                'file' => $rep['file'], 'rel' => $rep['file'], 'prev' => $rep];
        }
    }
    usort($candidates, function ($a, $b) {
        return ($a['prio'] <=> $b['prio']) ?: ($b['prims'] <=> $a['prims']);
    });
    return array_slice($candidates, 0, $opts['limit']);
}

// ── Main ─────────────────────────────────────────────────────────────────────
$opts = parseArgs($_SERVER['argv']);
@mkdir($OUT_DIR, 0755, true);

$targets = collectTargets($opts, $PHASE0);
fwrite(STDOUT, "=== Phase 1: pure-function execution on " . count($targets) . " targets (timeout {$opts['timeout']}s) ===\n\n");

$improved = $regressed = $flat = $errors = $timeouts = 0;
$report = [];

foreach ($targets as $c) {
    $rel = $c['file'];
    $full = $LIBRARY . '/' . $rel;
    if (!is_file($full)) {
        fwrite(STDOUT, "[SKIP] $rel (missing)\n");
        $errors++;
        $report[] = ['file' => $rel, 'note' => 'missing'];
        continue;
    }
    $prevPrims = $c['prev']['newPrims'] ?? $c['prev']['origPrims'] ?? 0;
    $deobfOut = $OUT_DIR . '/' . safeName($rel) . '.deobf.php';
    @unlink($deobfOut);

    fwrite(STDOUT, "[run] $rel (prev prims $prevPrims) ... ");
    fflush(STDOUT);
    $r = runOneExec($full, $opts['maxbytes'], $opts['timeout'], $deobfOut, $WORKER, $PHPBIN);
    $st = $r['status'];

    if ($st === 'PARSE_ERROR' || $st === 'TIMEOUT') {
        $line = $st . (isset($r['reason']) ? " (".$r['reason'].")" : "");
    } else {
        $newPrims = $r['newPrims'] ?? -1;
        $line = $st . " [prims $prevPrims -> " . ($newPrims == -1 ? 'n/a' : $newPrims) . "]";
    }

    // Delta vs Phase 0.
    $delta = '';
    if (isset($r['newPrims'])) {
        $d = $r['newPrims'] - $prevPrims;
        $delta = ($d < 0) ? " (improved by " . abs($d) . ")" : (($d > 0) ? " (regressed by $d)" : " (flat)");
    }
    fwrite(STDOUT, $line . $delta . "\n");
    fflush(STDOUT);

    if (isset($r['newPrims'])) {
        $d = $r['newPrims'] - $prevPrims;
        if ($d < 0) $improved++; elseif ($d > 0) $regressed++; else $flat++;
    } else {
        if ($st === 'TIMEOUT') $timeouts++; else $errors++;
    }

    $entry = ['file' => $rel, 'prev' => $c['prev'], 'phase1' => $r];
    if (isset($r['newPrims']) && $r['newPrims'] < $prevPrims) $entry['deobfOut'] = str_replace(__DIR__ . '/../', '', $deobfOut);
    $report[] = $entry;
    file_put_contents($OUT_DIR . '/' . safeName($rel) . '.json',
        json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

file_put_contents($OUT_DIR . '/summary.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

fwrite(STDOUT, "\n=== Phase 1 summary ===\n");
fwrite(STDOUT, "improved: $improved, regressed: $regressed, flat: $flat, timeouts: $timeouts, errors/missing: $errors\n");
fwrite(STDOUT, "Deobfuscated sources + reports in out/phase1/\n");
