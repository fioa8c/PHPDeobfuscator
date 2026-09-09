<?php
require __DIR__ . '/../vendor/autoload.php';
use PHPDeobfuscator\Deobfuscator;

$target = $argv[1];
$maxbytes = (int)($argv[2] ?? 1 << 20);       // pass cap
$exec = (bool)($argv[3] ?? 0);

$c = file_get_contents($target);
$deobf = new Deobfuscator(false, false, false, $exec, $maxbytes);
$deobf->getFilesystem()->write('/var/www/html/index.php', $c);
$deobf->setCurrentFilename('/var/www/html/index.php');
$tree = $deobf->deobfuscate($deobf->parse($c));
$out = $deobf->prettyPrint($tree);

echo "== LEN=" . strlen($out) . " cap=" . $maxbytes . " exec=" . (int)$exec . "\n";
echo "== head 1200:\n";
echo substr($out, 0, 1200);
echo "\n== tail 1200:\n";
echo substr($out, -1200);
