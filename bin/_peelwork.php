#!/usr/bin/env php
<?php
/**
 * Peel-sweep worker: run the eval-hook peeler on one file, then score the
 * deepest captured layer. Emits one JSON line.
 * Usage: php bin/_peelwork.php <path> <library> <unused> <outfile|-> [<timeout>]
 */
declare(strict_types=1);
require __DIR__ . '/lib/obfscore.php';
require __DIR__ . '/lib/evalpeel.php';
[$_, $path, $library, $_unused, $outfile] = $argv + [null, '', '', '', '-'];
$timeout = (int)($argv[5] ?? 10);
$rel = ltrim(str_replace($library . '/', '', $path), '/');
$content = (string)@file_get_contents($path);
$before = obfFeatures($content);
$res = ['file' => $rel, 'before' => ['len' => $before['len'], 'score' => $before['score'], 'tier' => $before['tier']]];
$r = evalPeel($path, ['timeout' => $timeout, 'max-layers' => 60]);
$res['layers'] = count($r['layers']); $res['killed'] = $r['killed']; $res['rc'] = $r['rc']; $res['secs'] = $r['secs'];
$err = $r['stderr'];
if (preg_match('/(?:Fatal error|Parse error|Warning|Uncaught \w+):\s*(.{0,160})/', $err, $m)) $res['error'] = trim($m[1]);
// Drop empty / whitespace-only layers: eval('') from a failed decode says nothing.
$r['layers'] = array_values(array_filter($r['layers'], fn($l) => trim($l['code']) !== ''));
if ($r['layers']) {
    $last = end($r['layers']);
    $after = obfFeatures("<?php\n" . $last['code']);
    $res['after'] = ['len' => $after['len'], 'score' => $after['score'], 'tier' => $after['tier']];
    $res['residual'] = ['exec' => $after['exec'], 'decoders' => $after['decoders'], 'dyncalls' => $after['dyncalls'],
        'hexesc' => $after['hexesc'], 'goto' => $after['goto'], 'b64runs' => $after['b64runs'], 'bitops' => $after['bitops'],
        'chrs' => $after['chrs'], 'globals' => $after['globals']];
    if ($outfile !== '-') {
        $all = '';
        foreach ($r['layers'] as $l) $all .= "// ===== eval layer {$l['n']} (" . $l['len'] . " B) =====\n" . $l['code'] . "\n";
        file_put_contents($outfile, "<?php\n" . $all);
    }
    $b = max(1, $before['score']); $a = $after['score'];
    if ($a == 0 || $after['tier'] === 'LIGHT') $res['status'] = 'CLEAN';
    elseif ($a <= 0.5 * $b) $res['status'] = 'IMPROVED';
    elseif ($a < $b) $res['status'] = 'RESIDUAL';
    else $res['status'] = 'UNREDUCED';
} else {
    $res['status'] = $r['killed'] ? 'TIMEOUT' : 'NO_EVAL';
}
echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
