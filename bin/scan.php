#!/usr/bin/env php
<?php
/**
 * Threat-library scan: walk collections, dedupe by sha1, score every PHP-bearing
 * file for obfuscation heaviness, write JSONL per collection + a merged file.
 *
 * Usage: php bin/scan.php [--dir <name>]... [--maxbytes N] [--out report/scan]
 */
declare(strict_types=1);
require __DIR__ . '/lib/obfscore.php';

$LIBRARY = '/Users/fioa8c/WORK/jetpack-threat-library';
$DEFAULT_DIRS = ['obfuscation','webshells','php-backdoors-obfuscated','sample_breaking_parser',
    'escalated_issues_samples','oddSamples','bugged_samples','core-modifications','hardening','spam_related',
    'DrupalSamples','JoomlaSamples','MagentoSamples','threats','for-later-review','sample-dump'];
$dirs = []; $maxbytes = 4 * 1024 * 1024; $out = __DIR__ . '/../report/scan';
$argv = $_SERVER['argv']; array_shift($argv);
while ($argv) {
    $a = array_shift($argv);
    switch ($a) {
        case '--dir': $dirs[] = array_shift($argv); break;
        case '--maxbytes': $maxbytes = (int)array_shift($argv); break;
        case '--out': $out = array_shift($argv); break;
        default: fwrite(STDERR, "unknown arg $a\n"); exit(2);
    }
}
if (!$dirs) $dirs = $DEFAULT_DIRS;
@mkdir($out, 0755, true);

$seen = [];
$all = fopen("$out/all.jsonl", 'w');
$totals = [];
foreach ($dirs as $d) {
    $path = "$LIBRARY/$d";
    if (!is_dir($path)) { fwrite(STDERR, "skip $d: not a dir\n"); continue; }
    $fh = fopen("$out/$d.jsonl", 'w');
    $t = ['files' => 0, 'php' => 0, 'dup' => 0, 'big' => 0, 'HEAVY' => 0, 'MEDIUM' => 0, 'LIGHT' => 0];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fi) {
        if (!$fi->isFile()) continue;
        $t['files']++;
        $size = $fi->getSize();
        if ($size === 0) continue;
        if ($size > $maxbytes) { $t['big']++; continue; }
        $ext = strtolower($fi->getExtension());
        if (in_array($ext, ['md','js','css','gif','jpg','png','jpeg','svg','ico','woff','ttf','json','yara','yar','zip','gz','tar','pdf','mp3','mp4','xml','po','mo','lock'], true)) continue;
        $c = file_get_contents($fi->getPathname());
        if ($c === false || !looksLikePHP($c)) continue;
        $t['php']++;
        $sha = sha1($c);
        $rel = substr($fi->getPathname(), strlen($LIBRARY) + 1);
        if (isset($seen[$sha])) { $t['dup']++; continue; }
        $seen[$sha] = $rel;
        $f = obfFeatures($c);
        $t[$f['tier']]++;
        $rec = ['file' => $rel, 'sha1' => $sha, 'ext' => $ext] + $f;
        $line = json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n";
        fwrite($fh, $line); fwrite($all, $line);
    }
    fclose($fh);
    $totals[$d] = $t;
    fwrite(STDOUT, sprintf("%-28s files=%6d php=%6d dup=%5d big=%3d HEAVY=%5d MEDIUM=%5d LIGHT=%5d\n",
        $d, $t['files'], $t['php'], $t['dup'], $t['big'], $t['HEAVY'], $t['MEDIUM'], $t['LIGHT']));
    fflush(STDOUT);
}
fclose($all);
file_put_contents("$out/summary.json", json_encode(['generatedAt' => date('c'), 'totals' => $totals], JSON_PRETTY_PRINT));
