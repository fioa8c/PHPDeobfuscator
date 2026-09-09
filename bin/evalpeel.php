#!/usr/bin/env php
<?php
/**
 * Dynamic eval peeling via the php-eval-hook extension.
 *
 * Runs a sample in a throwaway sandbox (php -n, open_basedir confined, writes /
 * network / process / env functions disabled, memory + time caps) with eval()
 * hooked so every layer of code handed to eval() is captured before it runs.
 * Captured layers are printed as JSON (or written to --out dir as layerN.php).
 *
 * Usage: php bin/evalpeel.php <file> [--out DIR] [--timeout 10] [--max-layers 50]
 *        [--stop-first] (capture the first eval layer and abort it instead of running it)
 *        [--ext /path/to/evalhook.so] [--php /path/to/php]
 */
declare(strict_types=1);

$o = ['out' => '', 'timeout' => 10, 'max-layers' => 50, 'stop-first' => false, 'ext' => '/Users/fioa8c/WORK/php-eval-hook/modules/evalhook.so', 'php' => PHP_BINARY, 'memory' => '256M'];
$file = '';
$argv = $_SERVER['argv']; array_shift($argv);
while ($argv) {
    $a = array_shift($argv);
    if ($a === '--stop-first') { $o['stop-first'] = true; continue; }
    if (str_starts_with($a, '--')) { $o[substr($a, 2)] = array_shift($argv); continue; }
    $file = $a;
}
if ($file === '' || !is_file($file)) { fwrite(STDERR, "usage: evalpeel.php <file> [--out DIR]\n"); exit(2); }
if (!is_file($o['ext'])) { fwrite(STDERR, "evalhook extension not found at {$o['ext']}\n"); exit(2); }
$o['timeout'] = (int)$o['timeout']; $o['max-layers'] = (int)$o['max-layers'];

require __DIR__ . '/lib/evalpeel.php';
$r = evalPeel($file, $o);
$layers = $r['layers'];
$res = ['file' => $file, 'layers' => count($layers), 'rc' => $r['rc'], 'killed' => $r['killed'], 'secs' => $r['secs'],
    'stderr' => $r['stderr'], 'stdout' => $r['stdout']];
if ($o['out'] !== '') {
    @mkdir($o['out'], 0755, true);
    foreach ($layers as $l) file_put_contents($o['out'] . '/layer' . $l['n'] . '.php', "<?php\n" . $l['code']);
    $res['layerFiles'] = array_map(fn($l) => ['n' => $l['n'], 'len' => $l['len'], 'sha1' => $l['sha1']], $layers);
} else {
    $res['layerList'] = array_map(fn($l) => ['n' => $l['n'], 'len' => $l['len'], 'sha1' => $l['sha1'], 'head' => substr($l['code'], 0, 200)], $layers);
}
echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
