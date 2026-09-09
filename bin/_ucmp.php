<?php
require __DIR__.'/../vendor/autoload.php';
use PHPDeobfuscator\Deobfuscator;
ini_set('memory_limit','1024M');
set_error_handler(fn()=>true, E_ALL);
[$_,$path,$lib]=$argv+[null,'',''];
$c=@file_get_contents($path); if($c===false||$c===''){echo json_encode(['file'=>$path,'status'=>'SKIP']),"\n";exit;}
$rel=ltrim(str_replace($lib.'/','',$path),'/');
function deob($c,$path,$dead){ $d=new Deobfuscator(false,false,false,false,null,$dead); $d->getFilesystem()->write('/var/www/html/'.basename($path),$c); $d->setCurrentFilename('/var/www/html/'.basename($path)); return $d->prettyPrint($d->deobfuscate($d->parse($c))); }
try{
  ob_start(); $a=deob($c,$path,false); $b=deob($c,$path,true); ob_end_clean();
  $la=substr_count($a,"\n"); $lb=substr_count($b,"\n");
  echo json_encode(['file'=>$rel,'status'=>'OK','linesNoU'=>$la,'linesU'=>$lb,'removed'=>$la-$lb]),"\n";
}catch(\Throwable $e){ @ob_end_clean(); echo json_encode(['file'=>$rel,'status'=>'ERR','error'=>get_class($e).': '.substr($e->getMessage(),0,100)]),"\n"; }
