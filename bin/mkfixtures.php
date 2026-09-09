#!/usr/bin/env php
<?php
/**
 * Mine the threat library for small, deterministic deobfuscations that make
 * good regression fixtures. Reproduces EXACTLY what test.php does (virtual
 * path /var/www/html/testcase.php, "<?php\n" + input, compared against
 * "<?php\n\n" + output) and keeps only candidates that:
 *   - start with a single <?php open tag (so a fixture INPUT round-trips),
 *   - reduce meaningfully (output differs and drops obfuscation score),
 *   - are small, self-contained and byte-stable across two runs,
 *   - leak no host path.
 * Deduped by transformation shape.
 *
 * Usage: php bin/mkfixtures.php --list report/scan/all.jsonl --maxin 1000
 *        --maxout 45 --per-shape 2 --out FILE
 */
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/obfscore.php';
use PHPDeobfuscator\Deobfuscator;

$LIB = '/Users/fioa8c/WORK/jetpack-threat-library';
$VP  = '/var/www/html/testcase.php';
$o = ['list'=>'report/scan/all.jsonl','maxin'=>1200,'maxout'=>60,'limit'=>0,'per-shape'=>1,'out'=>'','maxscoreout'=>9999,'maxfixtures'=>0];
$argv=$_SERVER['argv']; array_shift($argv);
while($argv){ $a=array_shift($argv); $k=ltrim($a,'-'); if(!array_key_exists($k,$o)){fwrite(STDERR,"bad arg $a\n");exit(2);} $o[$k]=array_shift($argv); }

set_error_handler(fn()=>true, E_ALL);

function deob(string $code, string $vp): ?string {
    try {
        $d = new Deobfuscator(false,false,false,false);
        $d->getFilesystem()->write($vp, $code);
        $d->setCurrentFilename($vp);
        ob_start(); $out = $d->prettyPrint($d->deobfuscate($d->parse($code))); ob_end_clean();
        return $out;
    } catch (\Throwable $e) { @ob_end_clean(); return null; }
}

function shapeSig(string $inBody): string {
    $feat = obfFeatures("<?php\n".$inBody);
    $keys=[];
    foreach(['exec','decoders','chrs','dyncalls','hexesc','goto','b64runs','bitops','concatchr'] as $k) {
        if(($feat[$k]??0)>0) $keys[]=$k;
    }
    return implode(',', $keys) ?: 'plain';
}

/**
 * Structural fingerprint of an output: string contents, variable names and
 * numbers normalized away, so two samples that decode to the same skeleton
 * (a whole malware family) collapse to one fixture regardless of shape bucket.
 */
function structSig(string $outBody): string {
    $x = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/', 'S', $outBody);
    $x = preg_replace('/\$[A-Za-z_]\w*/', 'V', $x);
    $x = preg_replace('/\b\d+\b/', 'N', $x);
    $x = preg_replace('/\s+/', ' ', $x);
    return md5(trim($x));
}

$candidates=[]; $seenShape=[]; $seenStruct=[]; $tested=0; $kept=0;
foreach (file($o['list'], FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    if ((int)$o['limit'] && $kept >= (int)$o['limit']) break;
    if ((int)$o['maxfixtures'] && $kept >= (int)$o['maxfixtures']) break;
    $r = json_decode($line, true); if (!$r) continue;
    if (!in_array($r['tier'] ?? '', ['HEAVY','MEDIUM'], true)) continue;
    if (($r['len'] ?? 1e9) > (int)$o['maxin']) continue;
    $content = @file_get_contents($LIB.'/'.$r['file']); if ($content===false||$content==='') continue;
    $trimmed = trim($content);
    if (!preg_match('/^<\?php\b/', $trimmed)) continue;
    $rest = preg_replace('/^<\?php\b/', '', $trimmed);
    if (strpos($rest, '<?') !== false) continue;
    $rest = preg_replace('/\?>\s*$/', '', $rest);
    if (strpos($rest, '?>') !== false) continue;
    $inBody = trim($rest);
    if ($inBody==='') continue;
    $tested++;

    $harnessCode = "<?php\n".$inBody;
    $out1 = deob($harnessCode, $VP); if ($out1===null) continue;
    $out2 = deob($harnessCode, $VP); if ($out1 !== $out2) continue;
    if (!preg_match('/^<\?php\n\n?/', $out1)) continue;
    $outBody = rtrim(preg_replace('/^<\?php\n\n?/', '', $out1));

    if (trim($outBody) === $inBody) continue;
    $fin = obfFeatures("<?php\n".$inBody); $fout = obfFeatures("<?php\n".$outBody);
    if ($fout['score'] >= $fin['score']) continue;
    if (substr_count($outBody, "\n")+1 > (int)$o['maxout']) continue;
    if ($fout['score'] > (int)$o['maxscoreout']) continue;
    if (strpos($outBody, '/Users/') !== false) continue;

    $expect = "<?php\n\n".trim($outBody);
    if (deob("<?php\n".$inBody, $VP) !== $expect) continue;

    $struct = structSig($outBody);
    if (isset($seenStruct[$struct])) continue;   // same decoded skeleton -> one fixture
    $sig = shapeSig($inBody);
    $seenShape[$sig] = ($seenShape[$sig] ?? 0) + 1;
    if ($seenShape[$sig] > (int)$o['per-shape']) continue;
    $seenStruct[$struct] = true;

    $candidates[] = ['file'=>$r['file'],'sig'=>$sig,'in'=>$inBody,'out'=>trim($outBody),
        'inLen'=>strlen($inBody),'scoreIn'=>$fin['score'],'scoreOut'=>$fout['score']];
    $kept++;
}
fwrite(STDERR, "tested=$tested kept=$kept shapes=".count($seenShape)."\n");
$buf=''; $idx=[];
foreach ($candidates as $c) {
    $idx[] = sprintf("%-46s [%s] %dB score %d->%d", $c['file'], $c['sig'], $c['inLen'], $c['scoreIn'], $c['scoreOut']);
    $buf .= "INPUT\n".$c['in']."\nOUTPUT\n".$c['out']."\n";
}
if ($o['out']!=='') { file_put_contents($o['out'], $buf); fwrite(STDERR, "wrote {$o['out']}\n"); }
fwrite(STDERR, implode("\n", $idx)."\n");
