<?php
require __DIR__.'/../vendor/autoload.php';
use PHPDeobfuscator\Deobfuscator;
$target=$argv[1]; $cap=(int)$argv[2]; $exec=(bool)$argv[3]; $outfile=$argv[4];
$c=file_get_contents($target);
$d=new Deobfuscator(false,false,false,$exec,$cap);
ob_start();
$d->getFilesystem()->write("/var/www/html/index.php",$c);
$d->setCurrentFilename("/var/www/html/index.php");
$tr=$d->deobfuscate($d->parse($c));
$o=$d->prettyPrint($tr);
ob_end_clean();
file_put_contents($outfile,$o);
$pat="/\b(eval|str_rot13|base64_decode|base64_encode|gzinflate|gzuncompress|hex2bin|hexdec|strrev|bin2hex|strtr|chr)\s*\(/i";
echo json_encode(['len'=>strlen($o),'prims'=>preg_match_all($pat,$o)])."\n";
