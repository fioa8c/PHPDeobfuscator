#!/usr/bin/env php
<?php
/**
 * Parallel sweep: run bin/_work.php over every file listed in a scan JSONL
 * (optionally filtered by tier), N workers at a time, hard timeout per file.
 * Writes results.jsonl + deobfuscated outputs under --out.
 *
 * Usage: php bin/sweep.php --list report/scan/all.jsonl --out out/sweep/static
 *          [--tier HEAVY] [--workers 6] [--timeout 60] [--exec 0|1] [--limit N] [--maxbytes N]
 *          [--only <substr>] [--resume]
 */
declare(strict_types=1);
$LIBRARY = '/Users/fioa8c/WORK/jetpack-threat-library';
$o = ['list' => '', 'out' => '', 'tier' => 'HEAVY', 'workers' => 6, 'timeout' => 60, 'exec' => 0,
      'limit' => 0, 'maxbytes' => 2 * 1024 * 1024, 'only' => '', 'resume' => false, 'maxinline' => 65536,
      'worker' => '_work.php', 'status' => ''];
$argv = $_SERVER['argv']; array_shift($argv);
while ($argv) {
    $a = array_shift($argv);
    if ($a === '--resume') { $o['resume'] = true; continue; }
    $k = ltrim($a, '-');
    if (!array_key_exists($k, $o)) { fwrite(STDERR, "unknown arg $a\n"); exit(2); }
    $o[$k] = array_shift($argv);
}
if (!$o['list'] || !$o['out']) { fwrite(STDERR, "need --list and --out\n"); exit(2); }
@mkdir($o['out'] . '/files', 0755, true);

$done = [];
$resultsPath = $o['out'] . '/results.jsonl';
if ($o['resume'] && is_file($resultsPath)) {
    foreach (file($resultsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $r = json_decode($l, true); if ($r) $done[$r['file']] = true;
    }
}
$queue = [];
foreach (file($o['list'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $r = json_decode($l, true); if (!$r) continue;
    $tier = $r['tier'] ?? ($r['before']['tier'] ?? '');
    if ($o['tier'] !== 'ALL' && $tier !== $o['tier']) continue;
    if ($o['status'] !== '' && !in_array($r['status'] ?? '', explode(',', $o['status']), true)) continue;
    if ($o['only'] !== '' && strpos($r['file'], $o['only']) === false) continue;
    if (($r['len'] ?? $r['before']['len'] ?? 0) > (int)$o['maxbytes']) continue;
    if (isset($done[$r['file']])) continue;
    $queue[] = $r['file'];
}
if ((int)$o['limit'] > 0) $queue = array_slice($queue, 0, (int)$o['limit']);
$total = count($queue);
fwrite(STDOUT, "queued $total files, workers={$o['workers']} timeout={$o['timeout']}s exec={$o['exec']}\n");

$results = fopen($resultsPath, $o['resume'] ? 'a' : 'w');
$running = []; $started = 0; $finished = 0; $counts = [];
$tStart = microtime(true);

function launch(string $rel, array $o, string $lib): array
{
    $outfile = $o['out'] . '/files/' . str_replace('/', '__', $rel) . '.deobf.php';
    $extra = $o['worker'] === '_peelwork.php' ? (string)max(1, (int)$o['timeout'] - 3) : (string)$o['maxinline'];
    $cmd = [PHP_BINARY, __DIR__ . '/' . $o['worker'], "$lib/$rel", $lib, (string)(int)$o['exec'], $outfile, $extra];
    $p = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    return ['p' => $p, 'pipes' => $pipes, 'rel' => $rel, 'start' => microtime(true), 'out' => '', 'err' => ''];
}

while ($queue || $running) {
    while ($queue && count($running) < (int)$o['workers']) {
        $running[] = launch(array_shift($queue), $o, $LIBRARY);
        $started++;
    }
    foreach ($running as $i => &$w) {
        $w['out'] .= (string)stream_get_contents($w['pipes'][1]);
        $w['err'] .= (string)stream_get_contents($w['pipes'][2]);
        $st = proc_get_status($w['p']);
        $elapsed = microtime(true) - $w['start'];
        if ($st['running'] && $elapsed < (int)$o['timeout']) continue;
        if ($st['running']) {
            proc_terminate($w['p'], 9);
            $rec = ['file' => $w['rel'], 'status' => 'TIMEOUT', 'secs' => round($elapsed, 1)];
        } else {
            $w['out'] .= (string)stream_get_contents($w['pipes'][1]);
            $w['err'] .= (string)stream_get_contents($w['pipes'][2]);
            $line = trim(strrchr("\n" . trim($w['out']), "\n"));
            $rec = json_decode($line, true);
            if (!is_array($rec)) {
                $rec = ['file' => $w['rel'], 'status' => 'CRASH', 'stage' => 'process',
                        'error' => 'rc=' . $st['exitcode'] . ' ' . substr(trim($w['err'] . $w['out']), -300), 'secs' => round($elapsed, 1)];
            }
        }
        fclose($w['pipes'][1]); fclose($w['pipes'][2]); proc_close($w['p']);
        unset($running[$i]);
        $finished++;
        $counts[$rec['status']] = ($counts[$rec['status']] ?? 0) + 1;
        fwrite($results, json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n"); fflush($results);
        if ($finished % 25 === 0 || $finished === $total) {
            ksort($counts);
            fwrite(STDOUT, sprintf("[%d/%d] %ds %s\n", $finished, $total, (int)(microtime(true) - $tStart), json_encode($counts)));
            fflush(STDOUT);
        }
    }
    unset($w);
    $running = array_values($running);
    usleep(20000);
}
ksort($counts);
fwrite(STDOUT, "done: " . json_encode($counts) . "\n");
