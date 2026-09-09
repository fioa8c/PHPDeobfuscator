#!/usr/bin/env php
<?php
/**
 * Sweep worker — deobfuscate one file, emit a JSON line with before/after
 * obfuscation features and an outcome classification.
 * Usage: php bin/_work.php <path> <library> <exec 0|1> <outfile|-> [<maxInlineBytes>]
 */
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/obfscore.php';

use PHPDeobfuscator\Deobfuscator;

ini_set('memory_limit', '1024M');
ini_set('xdebug.max_nesting_level', 4000);

[$_, $path, $library, $exec, $outfile] = $argv + [null, '', '', '0', '-'];
$maxInline = (int)($argv[5] ?? 65536);
$exec = (bool)(int)$exec;

set_error_handler(function () { return true; },
    E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);

$rel = ltrim(str_replace($library . '/', '', $path), '/');
$res = ['file' => $rel];
$content = @file_get_contents($path);
if ($content === false || $content === '') { $res['status'] = 'SKIP'; echo json_encode($res), "\n"; exit(0); }

$before = obfFeatures($content);
// Payload blobs (base64 / hex / \x runs) with whitespace folded so 76-col wrapped base64 counts as one blob.
$folded = preg_replace('/\s+/', '', $content);
preg_match_all('/[A-Za-z0-9+\/]{120,}={0,2}|(?:[0-9a-fA-F]{2}){64,}|(?:\\\\x[0-9a-fA-F]{2}){20,}/', $folded, $bm);
$blobs = array_unique($bm[0]);
$res['before'] = ['len' => $before['len'], 'score' => $before['score'], 'tier' => $before['tier']];
$t0 = microtime(true);
try {
    ob_start();
    $d = new Deobfuscator(false, false, false, $exec, $maxInline);
    $d->getFilesystem()->write('/var/www/html/' . basename($path), $content);
    $d->setCurrentFilename('/var/www/html/' . basename($path));
    $tree = $d->parse($content);
    $stage = 'deobfuscate';
    $tree = $d->deobfuscate($tree);
    $stage = 'print';
    $out = $d->prettyPrint($tree);
    ob_end_clean();
    $after = obfFeatures($out);
    $res['after'] = ['len' => $after['len'], 'score' => $after['score'], 'tier' => $after['tier']];
    if ($outfile !== '-') file_put_contents($outfile, $out);
    // Outcome: relative to obfuscation score.
    $b = max(1, $before['score']); $a = $after['score'];
    $res['ratio'] = round($a / $b, 2);
    $kept = 0;
    $outFolded = preg_replace('/\s+/', '', $out);
    foreach ($blobs as $blob) if (strpos($outFolded, $blob) !== false) $kept++;
    $res['blobs'] = count($blobs); $res['blobsKept'] = $kept;
    $peeled = count($blobs) > 0 && $kept <= 0.5 * count($blobs);
    if ($a == 0 || $after['tier'] === 'LIGHT') $res['status'] = 'CLEAN';
    elseif ($peeled && $a >= $b) $res['status'] = 'PEELED';
    elseif ($a <= 0.5 * $b) $res['status'] = 'IMPROVED';
    elseif ($a < $b) $res['status'] = 'RESIDUAL';
    else $res['status'] = 'UNREDUCED';
    // residual markers: what decoder primitives remain in the output
    $res['residual'] = ['exec' => $after['exec'], 'decoders' => $after['decoders'], 'dyncalls' => $after['dyncalls'],
        'hexesc' => $after['hexesc'], 'goto' => $after['goto'], 'b64runs' => $after['b64runs'], 'bitops' => $after['bitops'],
        'chrs' => $after['chrs'], 'globals' => $after['globals']];
} catch (\PhpParser\Error $e) {
    @ob_end_clean();
    $res['status'] = 'PARSE_ERROR';
    $res['error'] = substr($e->getMessage(), 0, 300);
} catch (\Throwable $e) {
    @ob_end_clean();
    $res['status'] = 'CRASH';
    $res['stage'] = $stage ?? 'parse';
    $res['error'] = get_class($e) . ': ' . substr($e->getMessage(), 0, 300);
    $tr = $e->getTrace();
    $res['at'] = basename($e->getFile()) . ':' . $e->getLine();
}
$res['secs'] = round(microtime(true) - $t0, 2);
echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
