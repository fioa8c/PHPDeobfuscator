#!/usr/bin/env php
<?php
/**
 * Phase 0/1 worker — deobfuscates a single file and prints a JSON result.
 * Usage: php bin/_one.php <path> <maxbytes> <libraryRoot> [<executePure 0|1>] [<deobfPath output for -x>]
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use PHPDeobfuscator\Deobfuscator;

ini_set('memory_limit', '768M');
ini_set('xdebug.var_display_max_depth', -1);
ini_set('xdebug.max_nesting_level', 1000);

$path       = $argv[1] ?? '';
$maxbytes   = (int)($argv[2] ?? 16777216);
$library    = $argv[3] ?? '';
$execute    = (bool)($argv[4] ?? 0);
$deobfPath  = $argv[5] ?? '';

// Silence non-fatal noise from the library so only the final JSON reaches stdout.
set_error_handler(function () { return true; },
    E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);

function countPrims(string $code): int
{
    $pat = '/\b(eval|str_rot13|base64_decode|base64_encode|gzinflate|gzuncompress|gzdeflate|gzdecode|hex2bin|hexdec|unpack|pack|strrev|bin2hex|strtr|chr|chr)\s*\(/i';
    return preg_match_all($pat, $code);
}

function isPHP(string $content): bool
{
    $head = substr($content, 0, 65536);
    return strpos($head, "<?php") !== false
        || strpos($head, '<?php') !== false
        || strpos($head, "<?") !== false;
}

$result = ['file' => ltrim(str_replace($library . '/', '', $path), '/')];

if ($path === '' || !is_file($path)) {
    $result['status'] = 'SKIP'; $result['reason'] = 'not found';
    echo json_encode($result, JSON_UNESCAPED_SLASHES); exit(0);
}

$size = filesize($path);
if ($size === false || $size === 0) {
    $result['status'] = 'SKIP'; $result['reason'] = 'empty';
    echo json_encode($result, JSON_UNESCAPED_SLASHES); exit(0);
}
if ($size > $maxbytes) {
    $result['status'] = 'SKIP'; $result['reason'] = "size $size > $maxbytes";
    echo json_encode($result, JSON_UNESCAPED_SLASHES); exit(0);
}

$content = file_get_contents($path);
if ($content === false) {
    $result['status'] = 'SKIP'; $result['reason'] = 'unreadable';
    echo json_encode($result, JSON_UNESCAPED_SLASHES); exit(0);
}
if (!isPHP($content)) {
    $result['status'] = 'SKIP'; $result['reason'] = 'not PHP';
    echo json_encode($result, JSON_UNESCAPED_SLASHES); exit(0);
}

$result['size']      = $size;
$origPrims           = countPrims($content);
$origSize            = strlen($content);
$result['origPrims'] = $origPrims;

try {
    // Capture any stray stdout from library internals.
    ob_start();
    $deobf  = new Deobfuscator(false, false, false, $execute);
    $deobf->getFilesystem()->write('/var/www/html/index.php', $content);
    $deobf->setCurrentFilename('/var/www/html/index.php');
    $tree = $deobf->parse($content);
    $tree = $deobf->deobfuscate($tree);
    $new  = $deobf->prettyPrint($tree);
    ob_end_clean();
    if ($deobfPath !== '') file_put_contents($deobfPath, $new);

    $newPrims = countPrims($new);
    $reduced  = (int)(100 * (1 - strlen($new) / $origSize));
    if ($reduced < 0) $reduced = 0;

    $result['newSize']    = strlen($new);
    $result['newPrims']   = $newPrims;
    $result['reduction%'] = $reduced;

    if ($origPrims == 0)           $result['status'] = 'CLEAN_NO_OBF';
    elseif ($newPrims == 0)       $result['status'] = 'CLEAN_FULL';
    elseif ($newPrims < $origPrims) $result['status'] = 'RESIDUAL';
    else                           $result['status'] = 'UNREDUCED';
} catch (\Throwable $e) {
    $result['status'] = 'PARSE_ERROR';
    $result['reason'] = get_class($e) . ': ' . substr(trim((string)$e), 0, 200);
}

echo json_encode($result, JSON_UNESCAPED_SLASHES);
exit(0);
