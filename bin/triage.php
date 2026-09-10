#!/usr/bin/env php
<?php
/**
 * Aggregate sweep results into a triage report: counts per status, clusters of
 * failures (by error message / residual signature), with example files.
 * Usage: php bin/triage.php <results.jsonl> [--md out.md] [--examples 5]
 */
declare(strict_types=1);
$path = $argv[1] ?? ''; $md = ''; $examples = 5;
for ($i = 2; $i < count($argv); $i++) {
    if ($argv[$i] === '--md') $md = $argv[++$i];
    elseif ($argv[$i] === '--examples') $examples = (int)$argv[++$i];
}
if (!is_file($path)) { fwrite(STDERR, "usage: triage.php results.jsonl [--md out.md]\n"); exit(2); }

require __DIR__ . '/lib/obfscore.php';

$rows = [];
foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) { $r = json_decode($l, true); if ($r) $rows[] = $r; }
// Reclassify to READABLE any output the obfuscation scorer still calls busy but
// that has nothing left for a *static* tool to decode. The raw scorer over-counts:
// a legitimate library (PEAR, WordPress core) with an embedded base64 data table,
// or a plain request-fed backdoor (`eval($_POST[..])`), both score HEAVY though
// there is no encoded payload feeding computed code. `readabilityVerdict()` is the
// discriminator the scorer lacks — a sample is still "packed" only when it BOTH
// runs computed code (eval / $f() / create_function / …) AND carries an encoded
// payload that could feed it. Size-independent, so it also clears the large
// false-positives the previous request-input-only gate could not reach.
$filesDir = dirname($path) . '/files';
foreach ($rows as &$r) {
    if (!in_array($r['status'], ['UNREDUCED', 'RESIDUAL', 'IMPROVED'], true)) continue;
    $of = $filesDir . '/' . str_replace('/', '__', $r['file']) . '.deobf.php';
    if (!is_file($of)) continue;
    $o = file_get_contents($of);
    if ($o === false || strlen($o) > 4 * 1024 * 1024) continue; // guard pathological sizes only
    if (readabilityVerdict($o)['readable']) {
        $r['status'] = 'READABLE';
    }
}
unset($r);
$byStatus = [];
foreach ($rows as $r) $byStatus[$r['status']][] = $r;
ksort($byStatus);

function coll(string $f): string { return explode('/', $f, 2)[0]; }
function normError(string $e): string
{
    $e = preg_replace('/ on line \d+/', '', $e);
    $e = preg_replace('/\b[0-9a-f]{32,}\b/', '<hash>', $e);
    $e = preg_replace('/\$\w+/', '$var', $e);
    $e = preg_replace('/"[^"]{0,60}"/', '"…"', $e);
    $e = preg_replace('/\'[^\']{0,60}\'/', "'…'", $e);
    $e = preg_replace('/\d+/', 'N', $e);
    return substr($e, 0, 140);
}
function residualSig(array $r): string
{
    $k = [];
    foreach ($r['residual'] ?? [] as $name => $v) if ($v > 0) $k[] = $name;
    return $k ? implode(',', $k) : 'none';
}

$out = [];
$out[] = '# Sweep triage: ' . basename(dirname($path)) . ' (' . count($rows) . ' files, ' . date('Y-m-d H:i') . ')';
$out[] = '';
$out[] = '## Status counts';
$out[] = '';
$out[] = '| status | count | % |';
$out[] = '|---|---|---|';
foreach ($byStatus as $s => $rs) $out[] = sprintf('| %s | %d | %.1f |', $s, count($rs), 100 * count($rs) / count($rows));
$out[] = '';
$out[] = '## Status by collection';
$out[] = '';
$colls = [];
foreach ($rows as $r) $colls[coll($r['file'])][$r['status']] = ($colls[coll($r['file'])][$r['status']] ?? 0) + 1;
$statuses = array_keys($byStatus);
$out[] = '| collection | ' . implode(' | ', $statuses) . ' |';
$out[] = '|---|' . str_repeat('---|', count($statuses));
foreach ($colls as $c => $cs) {
    $line = "| $c |";
    foreach ($statuses as $s) $line .= ' ' . ($cs[$s] ?? 0) . ' |';
    $out[] = $line;
}
$out[] = '';

foreach (['CRASH', 'PARSE_ERROR', 'TIMEOUT'] as $s) {
    if (empty($byStatus[$s])) continue;
    $out[] = "## $s clusters";
    $out[] = '';
    $cl = [];
    foreach ($byStatus[$s] as $r) {
        $key = $s === 'TIMEOUT' ? 'timeout ' . ($r['secs'] ?? '?') . 's' : normError(($r['stage'] ?? '') . ' ' . ($r['error'] ?? '?'));
        $cl[$key][] = $r;
    }
    uasort($cl, fn($a, $b) => count($b) <=> count($a));
    foreach ($cl as $key => $rs) {
        $out[] = sprintf('- **%d×** `%s`', count($rs), $key);
        foreach (array_slice($rs, 0, $examples) as $r) $out[] = '  - `' . $r['file'] . '`' . (isset($r['at']) ? ' at ' . $r['at'] : '') . ' (' . ($r['before']['len'] ?? '?') . ' B)';
    }
    $out[] = '';
}

foreach (['UNREDUCED', 'RESIDUAL', 'PEELED', 'IMPROVED', 'READABLE'] as $s) {
    if (empty($byStatus[$s])) continue;
    $out[] = "## $s by residual signature";
    $out[] = '';
    $cl = [];
    foreach ($byStatus[$s] as $r) $cl[residualSig($r)][] = $r;
    uasort($cl, fn($a, $b) => count($b) <=> count($a));
    foreach ($cl as $key => $rs) {
        usort($rs, fn($a, $b) => ($a['before']['len'] ?? 0) <=> ($b['before']['len'] ?? 0));
        $out[] = sprintf('- **%d×** `%s`', count($rs), $key);
        foreach (array_slice($rs, 0, $examples) as $r) $out[] = sprintf('  - `%s` (%d B → %d B, score %d → %d)', $r['file'], $r['before']['len'] ?? 0, $r['after']['len'] ?? 0, $r['before']['score'] ?? 0, $r['after']['score'] ?? 0);
    }
    $out[] = '';
}
$text = implode("\n", $out) . "\n";
if ($md) { @mkdir(dirname($md), 0755, true); file_put_contents($md, $text); echo "wrote $md\n"; }
else echo $text;
