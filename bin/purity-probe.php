#!/usr/bin/env php
<?php
/**
 * Probe: for every top-level user function in each sampled file, register it in
 * a Resolver and run PurityAnalyzer, tallying WHY functions are refused for -x
 * execution. Reveals which restrictions block the most decoder functions, so a
 * safe relaxation can be targeted.
 *
 * Usage: php bin/purity-probe.php --list report/scan/all.jsonl [--limit N] [--maxbytes N] [--examples 3]
 */
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use PHPDeobfuscator\Resolver;
use PHPDeobfuscator\UserFunctionPrepass;
use PHPDeobfuscator\ClosureRegistryPrepass;
use PHPDeobfuscator\PureFunction\PurityAnalyzer;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\Node\Stmt;

$LIB='/Users/fioa8c/WORK/jetpack-threat-library';
$o=['list'=>'report/scan/all.jsonl','limit'=>0,'maxbytes'=>60000,'examples'=>3];
$argv=$_SERVER['argv']; array_shift($argv);
while($argv){$a=array_shift($argv);$k=ltrim($a,'-');if(!array_key_exists($k,$o)){fwrite(STDERR,"bad $a\n");exit(2);}$o[$k]=array_shift($argv);}
set_error_handler(fn()=>true, E_ALL);

$parser=(new ParserFactory())->create(ParserFactory::PREFER_PHP7);
$GLOBALS['full']=[]; $reasons=[]; $ex=[]; $funcs=0; $pure=0; $files=0; $filesWithFuncs=0;
foreach(file($o['list'],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    if((int)$o['limit'] && $files>=(int)$o['limit']) break;
    $r=json_decode($line,true); if(!$r) continue;
    if(($r['len']??1e9)>(int)$o['maxbytes']) continue;
    $c=@file_get_contents($LIB.'/'.$r['file']); if($c===false||$c==='') continue;
    try{ $tree=$parser->parse($c); }catch(\Throwable $e){ continue; }
    if($tree===null) continue;
    $files++;
    $resolver=new Resolver();
    $pre=new NodeTraverser(); $pre->addVisitor(new ClosureRegistryPrepass($resolver)); $pre->addVisitor(new UserFunctionPrepass($resolver));
    try{ $pre->traverse($tree); }catch(\Throwable $e){ continue; }
    // Collect top-level function names.
    $names=[];
    foreach($tree as $n){ if($n instanceof Stmt\Function_) $names[]=$n->name->toString(); }
    if(!$names) continue;
    $filesWithFuncs++;
    $pa=new PurityAnalyzer($resolver);
    foreach($names as $fn){
        $funcs++;
        try{ $ok=$pa->isPure($fn); }catch(\Throwable $e){ $reasons['EXCEPTION:'.get_class($e)]=($reasons['EXCEPTION:'.get_class($e)]??0)+1; continue; }
        if($ok){ $pure++; continue; }
        $why=$pa->lastRejection() ?? 'unknown';
        // Bucket dynamic suffixes (function names) to keep the histogram readable.
        $bucket=preg_replace('/:.*/','',$why);
        $reasons[$bucket]=($reasons[$bucket]??0)+1;
        $GLOBALS['full'][$why]=($GLOBALS['full'][$why]??0)+1;
        if(!isset($ex[$bucket])) $ex[$bucket]=[];
        if(count($ex[$bucket])<(int)$o['examples']) $ex[$bucket][]=$r['file'].'  ['.$why.']';
    }
}
arsort($reasons);
$full=$GLOBALS['full']??[]; arsort($full);
fwrite(STDERR, "=== top 40 full reasons (builtin/func suffixes) ===\n");
$i=0; foreach($full as $w=>$n){ fwrite(STDERR, sprintf("%6d  %s\n",$n,$w)); if(++$i>=400) break; }
fwrite(STDERR, "\n");
fwrite(STDERR,"files=$files filesWithFuncs=$filesWithFuncs funcs=$funcs pure=$pure impure=".($funcs-$pure)."\n\n");
foreach($reasons as $why=>$n){
    printf("%5d  %s\n",$n,$why);
    foreach($ex[$why]??[] as $e) printf("         %s\n",$e);
}
