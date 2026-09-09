# Threat-library sweep — findings and TODO (2026-09-09)

## Fixed this session

- **Eval-hook peeling (`-e`, `src/EvalHook/EvalPeeler.php`)** — nested `eval()`
  chains are now unpacked dynamically in a hardened php-eval-hook sandbox and
  each layer statically cleaned. b374k unpacks to readable source (8 layers);
  z5encrypt and FOPO families likewise. Extension rebuilt for PHP 8.4.
- **Robustness: 228 crashes -> 0, 18 timeouts -> 0** on the swept HEAVY set.
  Fixes, each with a `tests/robustness.txt` fixture:
  - list() destructure of a mutable/uncertain array (MutableValueException)
  - reference to a valueless variable (UnknownValueException in ByReference)
  - returning / folding a non-scalar (object/resource) value
  - string-offset writes with non-int keys (ScalarValue::arrayAssign)
  - Flysystem path-traversal / corrupted-path on include & fopen("w")
  - reference cycles ($a=&$b;$b=&$a) - depth + re-entrancy guards
  - self-referential array stringification in diagnostics
  - a resolver-wide try/catch so value-tracking failures degrade to "unknown"
    instead of aborting the whole file.
- **Undefined constants** are left symbolic instead of folded to their own name
  (`error_reporting(E_ERROR|E_WARNING)` no longer becomes a garbage string;
  `ABSPATH` stays `ABSPATH`). `PHP_EOL` is still resolved. (`tests/robustness.txt`)
- **Performance / memory:**
  - `$out .= '...'` chains past 4 KB keep `.=` instead of folding an
    ever-growing literal (was quadratic; `threats/FIO-3432` etc.).
  - AST-level user-function inlining (no print/parse round trip) + a
    4000-inlining budget + memoised failed inlinings (junk-function droppers).
  - `MutableValueException` no longer stringifies big values into its message.
  - Allocation bombs guarded in `PassThrough`: `str_repeat`, `str_pad`,
    `str_replace`/`str_ireplace`, `substr_replace`, `chunk_split`, `wordwrap`,
    `range`, `array_fill`/`array_pad`, `sprintf`, and gz* decompression are
    size-bounded before the call (`chunk_split($x,1,$big)` was a 4 GB bomb).
- **Dead-store elimination (`-u`, `src/DeadCodeEliminator.php`)** — removes
  assignments to variables the reduced code never reads (decoder scaffolding,
  FOPO ladders). Conservative and sound: bails on any scope with
  variable-variables/extract/eval/include/references. Across a 400-file corpus
  sample it cleaned ~40% of files (14k dead lines) with zero behavioural errors.
  Fixtures: `tests/dead-code.txt`.
- **Taint reporting for request-keyed / remote payloads (item 8)** — the
  security-analysis pass now annotates a dangerous sink `tainted by <source>`
  when its argument derives from a superglobal, `getenv()`, a remote-URL or
  `php://input` fetch, or `curl_exec`, via a syntactic subtree scan plus a
  best-effort per-scope forward taint map. `eval(base64_decode($_POST[..]))`,
  `eval(...file_get_contents('http://...'))` and UA-keyed XOR decoders are now
  reported rather than left silently obfuscated. Fixtures: `tests/taint-flow.txt`
  (and updated `tests/analysis.txt`).
- **Pure-builtin whitelist widened** (`PassThrough`) from ~27 to ~150 pure,
  deterministic builtins, all allocation-guarded.

Remaining items below are still open.

---


Corpus: `jetpack-threat-library` (local mirror). Tooling lives in `bin/` (see
"Method"). Reports are gitignored under `report/` and `out/`.

## Method

1. `bin/scan.php` — walks every collection except `false-positives` and
   `binary_or_data_files`, keeps files that carry a PHP open tag, dedupes by
   sha1 and scores obfuscation heaviness with `bin/lib/obfscore.php`
   (decoder/exec primitives, dynamic calls, hex escapes, base64/hex blobs,
   bitwise string ops, goto, `$GLOBALS`, density). Tiers: HEAVY / MEDIUM / LIGHT.
2. `bin/sweep.php` — runs a worker over the HEAVY set in parallel with a hard
   per-file timeout. Workers: `_work.php` (static pipeline, `--exec 1` adds
   `-x`), `_peelwork.php` (dynamic eval peeling through the `php-eval-hook`
   extension, see below).
3. `bin/triage.php` — clusters outcomes into a markdown report
   (`report/sweep-*-triage.md`).

Outcome labels (per file, comparing the obfuscation score before/after):
CLEAN (nothing left), READABLE (tiny output whose only remaining sinks take
request input — nothing to decode statically), IMPROVED (score halved),
PEELED (input payload blobs consumed but the exposed layer is still obfuscated),
RESIDUAL, UNREDUCED, PARSE_ERROR, CRASH, TIMEOUT.

## Corpus numbers

| collection | PHP files | HEAVY | MEDIUM | LIGHT |
|---|---|---|---|---|
| threats | 10,624 | 6,098 | 2,050 | 2,247 |
| for-later-review | 4,202 | 962 | 766 | 2,433 |
| sample-dump (bendigital2019) | 21,315 | 20,551 | 760 | 0 |
| webshells / php-backdoors-obfuscated / obfuscation | 147 | 139 | 6 | 2 |
| Joomla / Magento / Drupal samples | 427 | 100 | 111 | 187 |
| escalated_issues_samples, sample_breaking_parser, misc | 126 | 43 | 23 | 53 |

sample-dump is three polymorphic families (comment-padded SEO doorways, a
`define()`-noise doorway, and a request-keyed XOR decoder); it was sampled
(411 files, stratified by feature signature) rather than swept in full.

## Sweep results (7,751 HEAVY files, static pipeline)

| status | count | % |
|---|---|---|
| CLEAN | 405 | 5.2 |
| READABLE | 1,305 | 16.8 |
| IMPROVED | 1,082 | 14.0 |
| PEELED | 1,117 | 14.4 |
| RESIDUAL | 1,074 | 13.9 |
| UNREDUCED | 2,264 | 29.2 |
| PARSE_ERROR | 258 | 3.3 |
| CRASH | 228 | 2.9 |
| TIMEOUT | 18 | 0.2 |

Re-running the non-clean set with `-x` (pure-function execution) changed the
outcome of only 54 files. Re-running it under the eval hook (dynamic peeling)
improved 1,057 files: 725 CLEAN and 1,527 IMPROVED in the best-of-three view.

## TODO — ranked by files affected

### 1. Dynamic eval peeling through php-eval-hook (`-e`) — ~1,050 files
The static pipeline gets one layer of FOPO / nested `eval(gzinflate(base64…))`
chains and stops (1,117 PEELED). Running the sample under
`/Users/fioa8c/WORK/php-eval-hook` (rebuilt for PHP 8.4; `extension_loaded()`
evasion built in) with a hardened sandbox captures every layer in well under a
second. Prototype: `bin/evalpeel.php` / `bin/lib/evalpeel.php`.
Sandbox: `php -n`, `open_basedir` on a throwaway dir, `disable_functions` for
process/network/write/env, `disable_classes` for DB/socket/zip/phar,
`allow_url_*` off, memory + wall-clock caps; disabled builtins get benign
stubs so the sample keeps running to its next `eval()` instead of fataling.
Plan: port into `src/EvalHook/`, add `-e` to `index.php`, feed each captured
layer back through the static pipeline, emit `// ==== eval layer N ====`
sections. Requires the extension `.so`; degrade to a clear message without it.
Examples: `php-backdoors-obfuscated/b374kShell_426f…php` (8 layers),
`threats/php_generic_eval_fgc_002_11/index.php`, `threats/FIO-5184/8405…php`.
Not solved by it: request-keyed decoders (key from `$_COOKIE`/UA), VM-style
obfuscators (`obfuscation/z5encrypt_*` execute an opcode loop, >50 evals).

### 2. Crashes — 228 files (uncaught exceptions escape the resolver)
- 97× `MutableValueException` from `ListVarRef::assignValue` →
  `ArrayVal::arrayFetch` on a mutable array (`list($a,$b) = $arr` after a
  branch). `threats/php_spamseo_masterdoorway_00*`.
- 77× `UnknownValueException` from `ByReference::isMutable()` when the
  referenced variable has no value (`adminer.php`, `class-wc-template-builder.php`).
- 12× `InvalidArgumentException: Value not scalar!` — `MiscReducer::reduceReturn`
  hands an `stdClass` to `Utils::scalarToNode`.
- 4× `TypeError: Cannot access offset of type string on string` —
  `ScalarValue::arrayAssign` on a string value with a string key.
- 8× Flysystem `PathTraversalDetected` / `CorruptedPathDetected` escaping
  `FileSystemCall` (`include '../../x.php'`).
- 6× memory exhaustion: thousands of `$out .= 'hex'` statements are folded
  into ever-growing literals (quadratic output), `threats/FIO-3432/…`;
  `threats/FIO-4562/…` (50 KB `eval(gzinflate(base64_decode(...)))` in a
  short-tag file).
- 1× stack overflow in `ArrayVal` (`MagentoSamples/FIO-7464/…`, 1.9 MB).
Fix: make each site return "unknown" instead of throwing, and wrap the
resolver's `leaveNode` handlers so a value-tracking failure never aborts.

### 3. Undefined constants are folded as their own name — correctness bug
`error_reporting(E_ERROR | E_WARNING)` becomes `error_reporting("E___V__OG")`
(`threats/php_webshell_cryptojacker_003_2/x.php`); host constants such as
`ABSPATH` become the string `"ABSPATH"`. `Resolver::getConstant` must return
unknown for anything not defined in the file, except a whitelist of PHP
builtin constants (`E_*`, `PHP_EOL`, `PREG_*`, `JSON_*`, `ENT_*`, …).

### 4. Dead stores left behind after values are substituted — readability
Every decoded sample keeps `$q = "<base64 blob>";`, FOPO's `$x = "b"; $x =
"ba"; … $x = "base64_decode";` ladders, and `$gz = "<164 KB of binary>"`
(`threats/FIO-4300/…`). Add a post-pass that removes assignments of
side-effect-free values to plain locals that are never read again in scope,
guarded against dynamic scope access (`$$`, `compact`, `extract`,
`get_defined_vars`, `eval`/`include` of unknown code, `global`, `$GLOBALS`,
closures with `use`, references). Opt-in flag first (`-u`).

### 5. Timeouts — 18 files
Big webshells (alfa/cartel/us ~460 KB) and osTicket `class.ticket.php` copies
(~170 KB) exceed 45 s. `threats/FIO-3483/…` (715 B) hangs in the resolver
while inlining a user function (`str_repeat` on a request-derived length?) —
profile with `bin/_trace.php <file> <secs> <exec> <samples>`.

### 6. Parse errors — 258 files
Mostly corrupt / truncated samples (unterminated strings, stray `}`), plus
13 short-open-tag files (`<?` without `php`; `short_open_tag` is off on
this PHP) — retry the parse with `<?` normalised to `<?php` on failure
(`threats/FIO-3701/…`, `threats/FIO-4562/…`). `sample_breaking_parser/*`
contains control bytes inside identifiers that PHP itself rejects.

### 7. `-x` pure execution barely moves the needle (54 files)
It does crack `chr(ord($s[$i]) - 1)` loops and `pack('H*')` decoders
(`threats/wf-191/…`, `threats/FIO-7145/…`) but the payloads underneath are
mostly request-driven shells, so the score stays RESIDUAL. Worth adding a
rejection-reason trace to `PurityAnalyzer` to find common refusals
(likely: `preg_replace` with computed patterns, `str_repeat` on unknown
lengths, `global $S` tables — `threats/FIO-3483/…`).

### 8. Request-keyed and remote payloads — not decodable, report instead
`eval(strrev(file_get_contents('cloudlicenseinc.php')))`,
`eval(...base64_decode(file_get_contents('http://pastebin…')))`,
XOR decoders keyed by `$_SERVER['HTTP_USER_AGENT']`
(`sample-dump/bendigital2019_01540/…`). The security analysis pass should
flag "eval of data keyed by request/remote input" explicitly.

### 9. Scorer precision (tooling only)
`bitops`/`dyncalls` regexes match inside string literals (`system("… | grep")`,
`'&url='`). Switch `bin/lib/obfscore.php` to `token_get_all`.

## Assumptions from the previous session that did not survive

- "Build a `SelfIntegrityCheckPrepass` for FOPO/b374k" — unnecessary: the
  sample is unmodified when analysed, so the sha1 self-check passes under the
  eval hook and all 8 layers come out. Static handling only needs the
  file-read inlining cap to not block *hashing* of the content (the value can
  be known without being printed).
- "Reverse-engineer the z5encrypt VM" — 3 files in the whole corpus, and an
  opcode-loop VM. Deprioritised; the eval hook captures its layers but they
  are per-opcode fragments.
- "`-x` will crack the decoder families" — it does for isolated decoders but
  changes the outcome for <1% of the corpus; the multi-layer eval chains
  dominate and need dynamic peeling.
