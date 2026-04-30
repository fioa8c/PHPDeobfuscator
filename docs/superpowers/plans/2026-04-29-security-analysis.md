# Security Analysis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an opt-in security-analysis pass that runs after deobfuscation, producing a report of taint sources (`$_GET`, `$_POST`, etc.), dangerous-function sinks (`eval`, `system`, etc.), and context tags (`auto-exec` vs. `in-function:<name>`).

**Architecture:** A new `PHPDeobfuscator\Analysis` namespace under `src/Analysis/` containing five classes — two value objects (`Finding`, `Findings`), one catalog (`DangerousCatalog`), one `PhpParser\NodeVisitorAbstract` subclass (`SecurityAnalysisVisitor`), and one formatter (`ReportFormatter`). `Deobfuscator::analyze(string $code): Findings` re-parses the pretty-printed deobfuscated source so finding line numbers match what the user sees printed, then runs the visitor in a fresh `NodeTraverser`. CLI entry adds `-a` (text report) and `-j` (JSON report) flags; web SAPI adds `?analyze=text|json|both`. Test fixtures grow an optional `ANALYSIS` block.

**Tech Stack:** PHP 8.0+, `nikic/php-parser` v4 (`PhpParser\NodeVisitorAbstract`, `PhpParser\NodeTraverser`, `PhpParser\Node\*`). Test runner is `php test.php` — discovers all `tests/*.txt` files, runs each `INPUT`/`OUTPUT` (and now optional `ANALYSIS`) block end-to-end.

**Spec:** `docs/superpowers/specs/2026-04-29-security-analysis-design.md`

## File map

**New files (all under `PHPDeobfuscator\Analysis` namespace):**

- `src/Analysis/Finding.php` — Immutable value object: `kind` (`'source'|'sink'|'meta'`), `category` (string), `label` (string), `line` (int), `context` (string), `note` (?string).
- `src/Analysis/Findings.php` — Holder for two arrays (`$sources`, `$sinks`) of `Finding`. Methods: `addSource(Finding)`, `addSink(Finding)`, `getSources(): array`, `getSinks(): array`, `count(): int`, `autoExecCount(): int`, `categoriesPresent(): array`.
- `src/Analysis/DangerousCatalog.php` — Static map of lowercased function name → category. Single public method: `static lookup(string $name): ?string` returning the category, or `null` if not in the catalog.
- `src/Analysis/SecurityAnalysisVisitor.php` — Visitor maintaining two stacks: a *function-frame* stack (push on `Function_`/`Closure`/`ArrowFunction`/`ClassMethod`, pop on leave; empty = `auto-exec`) and a *name-context* stack (push on `Namespace_`/`Class_`/`Trait_`/`Interface_`, pop on leave; used to qualify class-method names). Emits findings into a `Findings` it owns.
- `src/Analysis/ReportFormatter.php` — Three methods: `formatText(Findings, string $filename): string` (production text report with column padding and summary), `formatJson(Findings, string $filename): string` (`json_encode` with `JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES`), `formatFixture(Findings): string` (deterministic, simplified, no column padding, no summary — used only by test runner).

**Modified files:**

- `src/Deobfuscator.php` — Add `public function analyze(string $deobfuscatedCode): Findings`. Re-parses, traverses, returns. Wraps everything in try/catch — on any internal exception, returns a `Findings` with a single `meta` finding describing the failure.
- `index.php` — Extend `getopt('tof:')` to `getopt('tof:aj')`. Add `-a`/`-j` handling for CLI; add `?analyze=text|json|both` handling for web SAPI.
- `test.php` — Extend the fixture parser to recognize an optional `ANALYSIS` block per test case. When present, run `Deobfuscator::analyze($code)` on the pretty-printed deobfuscated source, render via `ReportFormatter::formatFixture`, and compare byte-exactly against the expected `ANALYSIS` block.

**New test fixture:**

- `tests/analysis.txt` — Comprehensive coverage: one fixture per dangerous category, one per source kind, context-tagging cases, negative predicate cases, namespace/class qualifier cases.

---

## Task 1: Bootstrap value objects and DangerousCatalog

Build the data classes that everything else consumes. No tests yet — these are validated end-to-end in Task 2 when the visitor starts emitting findings.

**Files:**
- Create: `src/Analysis/Finding.php`
- Create: `src/Analysis/Findings.php`
- Create: `src/Analysis/DangerousCatalog.php`

- [ ] **Step 1: Create `Finding`**

```php
<?php

namespace PHPDeobfuscator\Analysis;

class Finding
{
    public string $kind;
    public string $category;
    public string $label;
    public int $line;
    public string $context;
    public ?string $note;

    public function __construct(
        string $kind,
        string $category,
        string $label,
        int $line,
        string $context,
        ?string $note = null
    ) {
        $this->kind = $kind;
        $this->category = $category;
        $this->label = $label;
        $this->line = $line;
        $this->context = $context;
        $this->note = $note;
    }

    public function isAutoExec(): bool
    {
        return $this->context === 'auto-exec';
    }
}
```

- [ ] **Step 2: Create `Findings`**

```php
<?php

namespace PHPDeobfuscator\Analysis;

class Findings
{
    /** @var Finding[] */
    private array $sources = [];
    /** @var Finding[] */
    private array $sinks = [];

    public function addSource(Finding $f): void
    {
        $this->sources[] = $f;
    }

    public function addSink(Finding $f): void
    {
        $this->sinks[] = $f;
    }

    /** @return Finding[] */
    public function getSources(): array
    {
        return $this->sources;
    }

    /** @return Finding[] */
    public function getSinks(): array
    {
        return $this->sinks;
    }

    public function count(): int
    {
        return count($this->sources) + count($this->sinks);
    }

    public function autoExecCount(): int
    {
        $n = 0;
        foreach ($this->sources as $f) if ($f->isAutoExec()) $n++;
        foreach ($this->sinks as $f) if ($f->isAutoExec()) $n++;
        return $n;
    }

    /** @return string[] */
    public function categoriesPresent(): array
    {
        $cats = [];
        foreach ($this->sinks as $f) $cats[$f->category] = true;
        $names = array_keys($cats);
        sort($names);
        return $names;
    }

    public function sortedSources(): array
    {
        return $this->sortByContextThenLine($this->sources);
    }

    public function sortedSinks(): array
    {
        return $this->sortByContextThenLine($this->sinks);
    }

    /** @param Finding[] $list @return Finding[] */
    private function sortByContextThenLine(array $list): array
    {
        usort($list, function (Finding $a, Finding $b): int {
            $ra = $a->isAutoExec() ? 0 : 1;
            $rb = $b->isAutoExec() ? 0 : 1;
            if ($ra !== $rb) return $ra - $rb;
            if ($a->line !== $b->line) return $a->line - $b->line;
            return strcmp($a->label, $b->label);
        });
        return $list;
    }
}
```

- [ ] **Step 3: Create `DangerousCatalog`**

```php
<?php

namespace PHPDeobfuscator\Analysis;

class DangerousCatalog
{
    private const MAP = [
        // code_exec
        'eval'              => 'code_exec',
        'assert'            => 'code_exec',
        'create_function'   => 'code_exec',
        'preg_replace'      => 'code_exec',
        'preg_filter'       => 'code_exec',
        // os_exec
        'system'            => 'os_exec',
        'exec'              => 'os_exec',
        'shell_exec'        => 'os_exec',
        'passthru'          => 'os_exec',
        'proc_open'         => 'os_exec',
        'popen'             => 'os_exec',
        'pcntl_exec'        => 'os_exec',
        // dispatch
        'call_user_func'        => 'dispatch',
        'call_user_func_array'  => 'dispatch',
        // deser
        'unserialize'       => 'deser',
        // file_write
        'file_put_contents' => 'file_write',
        'fwrite'            => 'file_write',
        'fputs'             => 'file_write',
        'unlink'            => 'file_write',
        'rename'            => 'file_write',
        'copy'              => 'file_write',
        'move_uploaded_file' => 'file_write',
        'chmod'             => 'file_write',
        // network
        'curl_exec'         => 'network',
        'fsockopen'         => 'network',
        'pfsockopen'        => 'network',
        'stream_socket_client' => 'network',
        'socket_create'     => 'network',
        // mail
        'mail'              => 'mail',
        // header_inj
        'header'            => 'header_inj',
        // obfusc
        'base64_decode'     => 'obfusc',
        'gzinflate'         => 'obfusc',
        'gzuncompress'      => 'obfusc',
        'str_rot13'         => 'obfusc',
        'hex2bin'           => 'obfusc',
        'convert_uudecode'  => 'obfusc',
        'pack'              => 'obfusc',
        'unpack'            => 'obfusc',
    ];

    public static function lookup(string $name): ?string
    {
        $key = strtolower($name);
        return self::MAP[$key] ?? null;
    }
}
```

- [ ] **Step 4: Verify autoload picks up the new namespace**

Run: `composer dump-autoload`
Expected: prints `Generated optimized autoload files containing X classes` (no errors).

- [ ] **Step 5: Commit**

```bash
git add src/Analysis/Finding.php src/Analysis/Findings.php src/Analysis/DangerousCatalog.php
git commit -m "Analysis: add Finding/Findings value objects and DangerousCatalog"
```

---

## Task 2: Skeleton SecurityAnalysisVisitor + first end-to-end fixture

Build the visitor scaffolding (function-frame stack, name-context stack, Findings emission) plus the first detection rule (superglobal sources). Build `ReportFormatter::formatFixture` and extend `test.php` so we can prove the round-trip works with a fixture.

**Files:**
- Create: `src/Analysis/SecurityAnalysisVisitor.php`
- Create: `src/Analysis/ReportFormatter.php`
- Modify: `src/Deobfuscator.php` (add `analyze()`)
- Modify: `test.php` (parse `ANALYSIS` blocks, run analyzer, compare)
- Create: `tests/analysis.txt` (with one starter fixture)

- [ ] **Step 1: Write the failing fixture (`tests/analysis.txt`)**

Create the file with one INPUT/OUTPUT/ANALYSIS block:

```
INPUT
$x = $_GET['c'];

OUTPUT
$x = $_GET["c"];

ANALYSIS
sources:
  auto-exec  line 2  $_GET['c']
sinks:
```

Note: the INPUT gets `<?php\n` prepended by the runner, so `$_GET['c']` is on parsed line 2. Re-parsing the pretty-printed `<?php\n\n$x = $_GET["c"];` also puts the assignment on line 3 — but our visitor will report based on the re-parsed tree. We'll discover the actual line via Step 2 below and adjust if needed.

- [ ] **Step 2: Create `SecurityAnalysisVisitor` (skeleton — no rules yet, just structure)**

```php
<?php

namespace PHPDeobfuscator\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

class SecurityAnalysisVisitor extends \PhpParser\NodeVisitorAbstract
{
    private Findings $findings;

    /** Stack of function-like frame names (empty => auto-exec context). */
    private array $frameStack = [];

    /**
     * Stack of name-context entries pushed by Namespace_/Class_/Trait_/Interface_.
     * Each entry: ['kind' => 'namespace'|'class'|'trait'|'interface'|'anon_class', 'name' => string, 'line' => int].
     */
    private array $nameStack = [];

    public function __construct()
    {
        $this->findings = new Findings();
    }

    public function getFindings(): Findings
    {
        return $this->findings;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->nameStack[] = ['kind' => 'namespace', 'name' => $node->name ? $node->name->toString() : '', 'line' => $node->getLine()];
        } elseif ($node instanceof Stmt\Class_) {
            $name = $node->name ? $node->name->toString() : null;
            $this->nameStack[] = $name === null
                ? ['kind' => 'anon_class', 'name' => 'class@line:' . $node->getLine(), 'line' => $node->getLine()]
                : ['kind' => 'class', 'name' => $name, 'line' => $node->getLine()];
        } elseif ($node instanceof Stmt\Trait_) {
            $this->nameStack[] = ['kind' => 'trait', 'name' => $node->name->toString(), 'line' => $node->getLine()];
        } elseif ($node instanceof Stmt\Interface_) {
            $this->nameStack[] = ['kind' => 'interface', 'name' => $node->name->toString(), 'line' => $node->getLine()];
        }

        if ($node instanceof Stmt\Function_) {
            $this->frameStack[] = $this->qualifyFreeFunction($node->name->toString());
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->frameStack[] = $this->qualifyMethod($node->name->toString());
        } elseif ($node instanceof Expr\Closure) {
            $this->frameStack[] = '{closure@line:' . $node->getLine() . '}';
        } elseif ($node instanceof Expr\ArrowFunction) {
            $this->frameStack[] = '{fn@line:' . $node->getLine() . '}';
        }

        $this->detectSource($node);
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction
        ) {
            array_pop($this->frameStack);
        }
        if ($node instanceof Stmt\Namespace_
            || $node instanceof Stmt\Class_
            || $node instanceof Stmt\Trait_
            || $node instanceof Stmt\Interface_
        ) {
            array_pop($this->nameStack);
        }
    }

    private function detectSource(Node $node): void
    {
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            static $globals = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_FILES', '_SERVER', '_ENV', 'GLOBALS'];
            if (in_array($node->name, $globals, true)) {
                $this->findings->addSource(new Finding(
                    'source',
                    'superglobal',
                    $this->labelForVariable($node),
                    $node->getLine(),
                    $this->currentContext()
                ));
            }
        }
    }

    private function labelForVariable(Expr\Variable $node): string
    {
        $base = '$' . $node->name;
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Expr\ArrayDimFetch && $parent->var === $node && $parent->dim instanceof Node\Scalar\String_) {
            return $base . "['" . $parent->dim->value . "']";
        }
        return $base;
    }

    private function currentContext(): string
    {
        if (empty($this->frameStack)) return 'auto-exec';
        return 'in-function:' . end($this->frameStack);
    }

    private function qualifyFreeFunction(string $name): string
    {
        $ns = $this->currentNamespace();
        return $ns === '' ? $name : ($ns . '\\' . $name);
    }

    private function qualifyMethod(string $name): string
    {
        for ($i = count($this->nameStack) - 1; $i >= 0; $i--) {
            $entry = $this->nameStack[$i];
            if (in_array($entry['kind'], ['class', 'trait', 'interface', 'anon_class'], true)) {
                $owner = $entry['kind'] === 'anon_class' ? $entry['name'] : $this->qualifyTypeName($entry['name']);
                return $owner . '::' . $name;
            }
        }
        // No enclosing class — shouldn't happen for ClassMethod, but be safe.
        return $name;
    }

    private function qualifyTypeName(string $name): string
    {
        $ns = $this->currentNamespace();
        return $ns === '' ? $name : ($ns . '\\' . $name);
    }

    private function currentNamespace(): string
    {
        foreach ($this->nameStack as $entry) {
            if ($entry['kind'] === 'namespace') return $entry['name'];
        }
        return '';
    }
}
```

The visitor relies on a `parent` attribute (used in `labelForVariable`). The re-parse traversal in `Deobfuscator::analyze()` (added in Step 4) will use `PhpParser\NodeVisitor\ParentConnectingVisitor` first to set those.

- [ ] **Step 3: Create `ReportFormatter` (with `formatFixture` only — text/JSON come later)**

```php
<?php

namespace PHPDeobfuscator\Analysis;

class ReportFormatter
{
    public function formatFixture(Findings $f): string
    {
        $lines = [];
        $lines[] = 'sources:';
        foreach ($f->sortedSources() as $s) {
            $lines[] = '  ' . $s->context . '  line ' . $s->line . '  ' . $s->label;
        }
        $lines[] = 'sinks:';
        foreach ($f->sortedSinks() as $s) {
            $note = $s->note === null ? '' : ' (' . $s->note . ')';
            $lines[] = '  ' . $s->context . '  ' . $s->category . '  line ' . $s->line . '  ' . $s->label . $note;
        }
        return implode("\n", $lines);
    }
}
```

- [ ] **Step 4: Add `analyze()` to `Deobfuscator`**

In `src/Deobfuscator.php`, add the new method (between `prettyPrint()` and `printFileReductions()`):

```php
    public function analyze(string $deobfuscatedCode): Analysis\Findings
    {
        try {
            $tree = $this->parser->parse($deobfuscatedCode);
            if ($tree === null) {
                throw new \RuntimeException('analyze: parser returned null');
            }
            $visitor = new Analysis\SecurityAnalysisVisitor();
            $traverser = new \PhpParser\NodeTraverser();
            $traverser->addVisitor(new \PhpParser\NodeVisitor\ParentConnectingVisitor());
            $traverser->addVisitor($visitor);
            $traverser->traverse($tree);
            return $visitor->getFindings();
        } catch (\Throwable $e) {
            error_log('PHPDeobfuscator analyze() error: ' . $e->getMessage());
            $findings = new Analysis\Findings();
            $findings->addSink(new Analysis\Finding(
                'meta',
                'analysis_aborted',
                $e->getMessage(),
                0,
                'auto-exec'
            ));
            return $findings;
        }
    }
```

- [ ] **Step 5: Extend `test.php` to parse `ANALYSIS` blocks and run the analyzer**

Replace the entire body of `test.php` with this version (it preserves existing behavior plus adds analysis comparison):

```php
<?php

require 'vendor/autoload.php';

$virtualPath = '/var/www/html/testcase.php';

error_reporting(E_ALL);

$testdir = dirname(__FILE__) . '/tests';

$d = opendir($testdir);

while ($testfile = readdir($d)) {
    if ($testfile === '.' || $testfile === '..') {
        continue;
    }
    $f = fopen($testdir . '/' . $testfile, 'r');
    if (!$f) {
        exit(1);
    }
    $tests = array();
    $curTest = array('input' => array(), 'output' => array(), 'analysis' => null);
    $lines = null;
    while (!feof($f)) {
        $line = fgets($f);
        $trim = trim($line);
        if ($trim === 'INPUT') {
            if ($lines !== null) {
                $tests[] = $curTest;
                $curTest = array('input' => array(), 'output' => array(), 'analysis' => null);
            }
            $lines = &$curTest['input'];
            continue;
        } elseif ($trim === 'OUTPUT') {
            $lines = &$curTest['output'];
            continue;
        } elseif ($trim === 'ANALYSIS') {
            $curTest['analysis'] = array();
            $lines = &$curTest['analysis'];
            continue;
        }
        if ($lines !== null) {
            $lines[] = $line;
        }
    }
    if ($lines !== null) {
        $tests[] = $curTest;
    }
    fclose($f);
    foreach ($tests as $i => $test) {
        $name = $testfile . '/' . ($i + 1);
        $code = "<?php\n" . trim(implode('', $test['input']));
        $deobf = new \PHPDeobfuscator\Deobfuscator();
        $deobf->getFilesystem()->write($virtualPath, $code);
        $deobf->setCurrentFilename($virtualPath);
        try {
            $out = $deobf->prettyPrint($deobf->deobfuscate($deobf->parse($code)));
        } catch (\Exception | \Error $e) {
            echo "Test $name failed:\n";
            echo "Exception: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            continue;
        }
        $expect = "<?php\n\n" . trim(implode('', $test['output']));
        $deobfPass = ($out === $expect);
        $analysisPass = true;
        $analysisExpected = null;
        $analysisGot = null;
        if ($test['analysis'] !== null) {
            $analysisExpected = trim(implode('', $test['analysis']));
            $findings = $deobf->analyze($out);
            $formatter = new \PHPDeobfuscator\Analysis\ReportFormatter();
            $analysisGot = trim($formatter->formatFixture($findings));
            $analysisPass = ($analysisGot === $analysisExpected);
        }
        if (!$deobfPass) {
            echo "Test $name failed (deobfuscation):\n";
            echo "Expected:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $expect)));
            echo "\n";
            echo "Got:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $out)));
            echo "\n";
        }
        if (!$analysisPass) {
            echo "Test $name failed (analysis):\n";
            echo "Expected:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $analysisExpected)));
            echo "\n";
            echo "Got:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $analysisGot)));
            echo "\n";
        }
        if ($deobfPass && $analysisPass) {
            echo "Test $name pass\n";
        }
    }
}

closedir($d);
```

- [ ] **Step 6: Run the suite. Verify the new fixture passes and existing fixtures still pass.**

Run: `php -d error_reporting=E_ALL test.php`
Expected: `Test analysis.txt/1 pass`, plus all existing fixtures still showing `pass` (no regressions). If line number is off by one (e.g., expected `line 2` but got `line 3`), update the fixture's `ANALYSIS` block to match the actual output — the re-parsed tree's line numbers are authoritative.

- [ ] **Step 7: Commit**

```bash
git add src/Analysis/SecurityAnalysisVisitor.php src/Analysis/ReportFormatter.php src/Deobfuscator.php test.php tests/analysis.txt
git commit -m "Analysis: end-to-end superglobal source detection with first fixture"
```

---

## Task 3: All eight superglobals + multiple sources per fixture

Verify the visitor handles every superglobal listed in the spec (`_GET`, `_POST`, `_REQUEST`, `_COOKIE`, `_FILES`, `_SERVER`, `_ENV`, `GLOBALS`) and that multiple sources in one input produce multiple findings.

**Files:**
- Modify: `tests/analysis.txt` (append fixture)

- [ ] **Step 1: Append the failing fixture to `tests/analysis.txt`**

```

INPUT
$a = $_GET['x'];
$b = $_POST['y'];
$c = $_REQUEST['z'];
$d = $_COOKIE['k'];
$e = $_FILES['up'];
$f = $_SERVER['HTTP_USER_AGENT'];
$g = $_ENV['HOME'];
$h = $GLOBALS['shared'];

OUTPUT
$a = $_GET["x"];
$b = $_POST["y"];
$c = $_REQUEST["z"];
$d = $_COOKIE["k"];
$e = $_FILES["up"];
$f = $_SERVER["HTTP_USER_AGENT"];
$g = $_ENV["HOME"];
$h = $GLOBALS["shared"];

ANALYSIS
sources:
  auto-exec  line 2  $_GET['x']
  auto-exec  line 3  $_POST['y']
  auto-exec  line 4  $_REQUEST['z']
  auto-exec  line 5  $_COOKIE['k']
  auto-exec  line 6  $_FILES['up']
  auto-exec  line 7  $_SERVER['HTTP_USER_AGENT']
  auto-exec  line 8  $_ENV['HOME']
  auto-exec  line 9  $GLOBALS['shared']
sinks:
```

- [ ] **Step 2: Run the suite. Verify the fixture passes (the visitor already handles all eight names from Task 2).**

Run: `php -d error_reporting=E_ALL test.php`
Expected: `Test analysis.txt/2 pass`. If any line shows a mismatch, the visitor's superglobal list is wrong — fix it in `SecurityAnalysisVisitor::detectSource`.

- [ ] **Step 3: Commit**

```bash
git add tests/analysis.txt
git commit -m "Analysis: cover all eight superglobals in fixture"
```

---

## Task 4: Context tagging — auto-exec vs. in-function

Verify findings inside a function/method/closure/arrow get tagged correctly, including namespace and class qualifier prefixes.

**Files:**
- Modify: `tests/analysis.txt` (append fixture)

- [ ] **Step 1: Append fixture**

```

INPUT
namespace App\Web;

class Handler {
    public function handle() {
        $a = $_POST['data'];
    }
}

function helper() {
    $b = $_GET['q'];
}

$top = $_REQUEST['t'];

$cb = function () {
    $c = $_COOKIE['cid'];
};

$arr = fn() => $_SERVER['HTTP_HOST'];

OUTPUT
namespace App\Web;

class Handler
{
    public function handle()
    {
        $a = $_POST["data"];
    }
}
function helper()
{
    $b = $_GET["q"];
}
$top = $_REQUEST["t"];
$cb = function () {
    $c = $_COOKIE["cid"];
};
$arr = fn() => $_SERVER["HTTP_HOST"];

ANALYSIS
sources:
  auto-exec  line 14  $_REQUEST['t']
  in-function:App\Web\Handler::handle  line 7  $_POST['data']
  in-function:App\Web\helper  line 12  $_GET['q']
  in-function:{closure@line:16}  line 17  $_COOKIE['cid']
  in-function:{fn@line:20}  line 20  $_SERVER['HTTP_HOST']
sinks:
```

The exact line numbers come from the *pretty-printed* output (after the leading `<?php\n\n`), and the runner re-parses that. Adjust the line numbers in the fixture to match what the re-parser actually emits — see Step 2.

- [ ] **Step 2: Run the suite, observe failure on first run, copy actual line numbers into the fixture, run again, verify pass.**

Run: `php -d error_reporting=E_ALL test.php`
Expected: First run probably fails because hand-counted lines don't match the pretty-printer's line-emission. Look at the `Got:` block from the failure, copy those line numbers into the fixture's `ANALYSIS` block. Re-run; expected `Test analysis.txt/3 pass`.

- [ ] **Step 3: Commit**

```bash
git add tests/analysis.txt
git commit -m "Analysis: cover auto-exec vs in-function context tagging"
```

---

## Task 5: Layer-A name-based sinks (the simple cases)

Add detection for all the `FuncCall` sinks whose only test is "name is in `DangerousCatalog`": eval (`FuncCall` form), assert, create_function, system, exec, shell_exec, passthru, proc_open, popen, pcntl_exec, call_user_func, call_user_func_array, unserialize, file_put_contents, fwrite, fputs, unlink, rename, copy, move_uploaded_file, chmod, curl_exec, fsockopen, pfsockopen, stream_socket_client, socket_create, mail, base64_decode, gzinflate, gzuncompress, str_rot13, hex2bin, convert_uudecode, pack, unpack.

(`include`/`require`, `header`, `preg_replace`/`preg_filter` come in later tasks because they need argument predicates.)

**Files:**
- Modify: `src/Analysis/SecurityAnalysisVisitor.php`
- Modify: `tests/analysis.txt`

- [ ] **Step 1: Append the failing fixture**

```

INPUT
$cmd = $_GET['c'];
system($cmd);
exec($cmd);
unserialize($_POST['d']);
file_put_contents($f, $d);
unlink($f);
curl_exec($ch);
mail('a@b', 's', 'b');
$x = base64_decode($enc);

OUTPUT
$cmd = $_GET["c"];
system($cmd);
exec($cmd);
unserialize($_POST["d"]);
file_put_contents($f, $d);
unlink($f);
curl_exec($ch);
mail("a@b", "s", "b");
$x = base64_decode($enc);

ANALYSIS
sources:
  auto-exec  line 2  $_GET['c']
  auto-exec  line 5  $_POST['d']
sinks:
  auto-exec  os_exec  line 3  system
  auto-exec  os_exec  line 4  exec
  auto-exec  deser  line 5  unserialize
  auto-exec  file_write  line 6  file_put_contents
  auto-exec  file_write  line 7  unlink
  auto-exec  network  line 8  curl_exec
  auto-exec  mail  line 9  mail
  auto-exec  obfusc  line 10  base64_decode
```

- [ ] **Step 2: Run, verify the analysis sub-test fails**

Run: `php -d error_reporting=E_ALL test.php`
Expected: `Test analysis.txt/4 failed (analysis):` showing the expected sinks block but `Got:` showing `sinks:` (empty) because the visitor doesn't detect sinks yet.

- [ ] **Step 3: Implement Layer-A FuncCall sink detection in `SecurityAnalysisVisitor`**

In `src/Analysis/SecurityAnalysisVisitor.php`, add a `detectSink` method called from `enterNode` after `detectSource($node)`:

In `enterNode`, after `$this->detectSource($node);`, add:
```php
        $this->detectSink($node);
```

Then add the methods:
```php
    private function detectSink(Node $node): void
    {
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->toString();
            $category = DangerousCatalog::lookup($name);
            if ($category !== null) {
                $this->findings->addSink(new Finding(
                    'sink',
                    $category,
                    strtolower($name),
                    $node->getLine(),
                    $this->currentContext()
                ));
            }
        }
    }
```

- [ ] **Step 4: Run the suite, adjust fixture line numbers if needed, verify pass**

Run: `php -d error_reporting=E_ALL test.php`
Expected: `Test analysis.txt/4 pass`. If line numbers are off, adjust as in Task 4 Step 2.

- [ ] **Step 5: Commit**

```bash
git add src/Analysis/SecurityAnalysisVisitor.php tests/analysis.txt
git commit -m "Analysis: detect Layer-A name-based sinks (eval, system, ...)"
```

---

## Task 6: AST-shape sinks (`Expr\Eval_` and `Expr\ShellExec`)

`eval(...)` parses to `Expr\Eval_`, NOT `FuncCall`. Backticks `` `...` `` parse to `Expr\ShellExec`. Both need shape-based detection.

**Files:**
- Modify: `src/Analysis/SecurityAnalysisVisitor.php`
- Modify: `tests/analysis.txt`

- [ ] **Step 1: Append the failing fixture**

```

INPUT
$x = $_GET['c'];
eval($x);
$out = `whoami $x`;

OUTPUT
$x = $_GET["c"];
eval($x);
$out = `whoami {$x}`;

ANALYSIS
sources:
  auto-exec  line 2  $_GET['c']
sinks:
  auto-exec  code_exec  line 3  eval
  auto-exec  os_exec  line 4  shell_exec (backticks)
```

- [ ] **Step 2: Run, verify failure**

Run: `php -d error_reporting=E_ALL test.php`
Expected: `Test analysis.txt/5 failed (analysis):` — sinks block is empty in `Got:` because Eval_/ShellExec aren't detected.

- [ ] **Step 3: Extend `detectSink` to handle `Expr\Eval_` and `Expr\ShellExec`**

In `SecurityAnalysisVisitor::detectSink`, add at the top (before the FuncCall check):

```php
        if ($node instanceof Expr\Eval_) {
            $this->findings->addSink(new Finding(
                'sink',
                'code_exec',
                'eval',
                $node->getLine(),
                $this->currentContext()
            ));
            return;
        }
        if ($node instanceof Expr\ShellExec) {
            $this->findings->addSink(new Finding(
                'sink',
                'os_exec',
                'shell_exec',
                $node->getLine(),
                $this->currentContext(),
                'backticks'
            ));
            return;
        }
```

- [ ] **Step 4: Run, adjust line numbers if needed, verify pass**

Run: `php -d error_reporting=E_ALL test.php`
Expected: `Test analysis.txt/5 pass`.

- [ ] **Step 5: Commit**

```bash
git add src/Analysis/SecurityAnalysisVisitor.php tests/analysis.txt
git commit -m "Analysis: detect Expr\\Eval_ and Expr\\ShellExec sinks"
```

---

## Task 7: Dynamic include sinks (`include` family with literal-arg predicate)

`include`, `include_once`, `require`, `require_once` parse to `Expr\Include_`. Skip the finding if the argument is a string literal; flag with `note: 'non-literal arg'` otherwise.

**Files:**
- Modify: `src/Analysis/SecurityAnalysisVisitor.php`
- Modify: `tests/analysis.txt`

- [ ] **Step 1: Append the failing fixture**

```

INPUT
$f = $_GET['f'];
include $f;
require_once $f . '.php';
include 'config.php';
require 'lib/setup.php';

OUTPUT
$f = $_GET["f"];
include $f;
require_once $f . ".php";
include "config.php";
require "lib/setup.php";

ANALYSIS
sources:
  auto-exec  line 2  $_GET['f']
sinks:
  auto-exec  dynamic_inc  line 3  include (non-literal arg)
  auto-exec  dynamic_inc  line 4  require_once (non-literal arg)
```

The static `include 'config.php'` and `require 'lib/setup.php'` are **not** flagged.

- [ ] **Step 2: Run, verify failure**

Run: `php -d error_reporting=E_ALL test.php`
Expected: failure showing empty sinks where dynamic includes were expected.

- [ ] **Step 3: Extend `detectSink` to handle `Expr\Include_`**

Add to `SecurityAnalysisVisitor::detectSink` (above the FuncCall check):

```php
        if ($node instanceof Expr\Include_) {
            if ($node->expr instanceof Node\Scalar\String_) {
                return; // static include — uninteresting
            }
            static $kindLabels = [
                Expr\Include_::TYPE_INCLUDE      => 'include',
                Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
                Expr\Include_::TYPE_REQUIRE      => 'require',
                Expr\Include_::TYPE_REQUIRE_ONCE => 'require_once',
            ];
            $label = $kindLabels[$node->type] ?? 'include';
            $this->findings->addSink(new Finding(
                'sink',
                'dynamic_inc',
                $label,
                $node->getLine(),
                $this->currentContext(),
                'non-literal arg'
            ));
            return;
        }
```

- [ ] **Step 4: Run, adjust, verify pass**

Run: `php -d error_reporting=E_ALL test.php`
Expected: `Test analysis.txt/6 pass`.

- [ ] **Step 5: Commit**

```bash
git add src/Analysis/SecurityAnalysisVisitor.php tests/analysis.txt
git commit -m "Analysis: detect dynamic include sinks (skip literal-arg)"
```

---

## Task 8: Header injection (literal-arg predicate)

`header()` with a non-literal first argument flags `header_inj`. With a literal first arg (e.g., `header('Content-Type: text/html')`), skip.

**Files:**
- Modify: `src/Analysis/SecurityAnalysisVisitor.php`
- Modify: `tests/analysis.txt`

- [ ] **Step 1: Append the failing fixture**

```

INPUT
header('Content-Type: text/html');
header('Location: ' . $_GET['url']);

OUTPUT
header("Content-Type: text/html");
header("Location: " . $_GET["url"]);

ANALYSIS
sources:
  auto-exec  line 3  $_GET['url']
sinks:
  auto-exec  header_inj  line 3  header (non-literal arg)
```

- [ ] **Step 2: Run, verify failure**

Currently `header` matches Layer A and would be flagged in *both* cases (including the benign `Content-Type` one). The failure shows two `header` sinks where we expect one.

- [ ] **Step 3: Update `detectSink` so `header` uses Layer B**

In `SecurityAnalysisVisitor::detectSink`, the FuncCall branch must consult a per-name predicate. Replace the FuncCall branch with:

```php
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = strtolower($node->name->toString());
            $category = DangerousCatalog::lookup($name);
            if ($category !== null) {
                $note = $this->predicateNote($name, $node);
                if ($note === self::SKIP) {
                    return;
                }
                $this->findings->addSink(new Finding(
                    'sink',
                    $category,
                    $name,
                    $node->getLine(),
                    $this->currentContext(),
                    $note
                ));
            }
        }
```

Add at top of class:
```php
    private const SKIP = "\0SKIP";
```

Add the predicate method:
```php
    /** Returns null (flag, no note), self::SKIP (don't flag), or a string (flag with note). */
    private function predicateNote(string $name, Expr\FuncCall $node): ?string
    {
        $args = $node->args;
        if ($name === 'header') {
            $first = $args[0]->value ?? null;
            if ($first instanceof Node\Scalar\String_) return self::SKIP;
            return 'non-literal arg';
        }
        return null;
    }
```

- [ ] **Step 4: Run, verify pass**

Run: `php -d error_reporting=E_ALL test.php`
Expected: `Test analysis.txt/7 pass`. The benign Content-Type call is skipped.

- [ ] **Step 5: Commit**

```bash
git add src/Analysis/SecurityAnalysisVisitor.php tests/analysis.txt
git commit -m "Analysis: skip header() with literal arg, flag dynamic"
```

---

## Task 9: preg_replace / preg_filter (`/e` modifier or non-literal pattern)

Flag when the first argument is a non-literal expression, OR when it's a string literal containing the `e` modifier in its delimiter trailer.

**Files:**
- Modify: `src/Analysis/SecurityAnalysisVisitor.php`
- Modify: `tests/analysis.txt`

- [ ] **Step 1: Append the failing fixture**

```

INPUT
$x = $_GET['v'];
preg_replace('/(.+)/e', 'strtoupper("$1")', $x);
preg_filter($pat, 'r', $x);
preg_replace('/x/', 'y', $x);

OUTPUT
$x = $_GET["v"];
preg_replace("/(.+)/e", "strtoupper(\"\$1\")", $x);
preg_filter($pat, "r", $x);
preg_replace("/x/", "y", $x);

ANALYSIS
sources:
  auto-exec  line 2  $_GET['v']
sinks:
  auto-exec  code_exec  line 3  preg_replace (e modifier)
  auto-exec  code_exec  line 4  preg_filter (non-literal pattern)
```

The benign `preg_replace('/x/', 'y', $x)` (literal pattern, no `e` modifier) is **not** flagged.

- [ ] **Step 2: Run, verify failure**

The failure should show three preg_* sinks where we expect two — the benign `/x/` call is currently flagged unconditionally.

- [ ] **Step 3: Extend `predicateNote` to handle preg_replace / preg_filter**

Add inside `predicateNote`, before `return null;`:

```php
        if ($name === 'preg_replace' || $name === 'preg_filter') {
            $first = $args[0]->value ?? null;
            if ($first instanceof Node\Scalar\String_) {
                $pattern = $first->value;
                $delim = $pattern[0] ?? '';
                $end = strrpos($pattern, $delim);
                if ($end === false || $end === 0) {
                    return self::SKIP; // malformed — don't flag
                }
                $modifiers = substr($pattern, $end + 1);
                if (strpos($modifiers, 'e') !== false) {
                    return 'e modifier';
                }
                return self::SKIP;
            }
            return 'non-literal pattern';
        }
```

- [ ] **Step 4: Run, verify pass**

Run: `php -d error_reporting=E_ALL test.php`
Expected: `Test analysis.txt/8 pass`.

- [ ] **Step 5: Commit**

```bash
git add src/Analysis/SecurityAnalysisVisitor.php tests/analysis.txt
git commit -m "Analysis: preg_replace flagged only on /e modifier or non-literal pattern"
```

---

## Task 10: Dispatch sinks (variable function/method/`new`)

`call_user_func` and `call_user_func_array` are already covered by Layer A. Add the AST-shape variants:
- `Expr\FuncCall` whose `name` is *not* a `Name` (variable function: `$f($x)`)
- `Expr\MethodCall` whose `name` is *not* an `Identifier` (`$o->$m()`)
- `Expr\StaticCall` whose `name` is *not* an `Identifier` (`Cls::$$m()`)
- `Expr\New_` whose `class` is *not* a `Name` (`new $c`)

**Files:**
- Modify: `src/Analysis/SecurityAnalysisVisitor.php`
- Modify: `tests/analysis.txt`

- [ ] **Step 1: Append the failing fixture**

```

INPUT
$fn = $_GET['fn'];
$fn('arg');
$o->$method();
$c::$$method();
$cls = $_POST['cls'];
new $cls();

OUTPUT
$fn = $_GET["fn"];
$fn("arg");
$o->{$method}();
$c::${$method}();
$cls = $_POST["cls"];
new $cls();

ANALYSIS
sources:
  auto-exec  line 2  $_GET['fn']
  auto-exec  line 6  $_POST['cls']
sinks:
  auto-exec  dispatch  line 3  $variable() (variable function)
  auto-exec  dispatch  line 4  $obj->$method() (variable method)
  auto-exec  dispatch  line 5  Class::$$method() (variable static method)
  auto-exec  dispatch  line 7  new $variable (variable class)
```

- [ ] **Step 2: Run, verify failure**

- [ ] **Step 3: Extend `detectSink`**

Add to `SecurityAnalysisVisitor::detectSink`, before the existing `FuncCall + Name` branch (so we don't fall through):

```php
        if ($node instanceof Expr\FuncCall && !($node->name instanceof Node\Name)) {
            $this->findings->addSink(new Finding(
                'sink', 'dispatch', '$variable()', $node->getLine(), $this->currentContext(), 'variable function'
            ));
            return;
        }
        if ($node instanceof Expr\MethodCall && !($node->name instanceof Node\Identifier)) {
            $this->findings->addSink(new Finding(
                'sink', 'dispatch', '$obj->$method()', $node->getLine(), $this->currentContext(), 'variable method'
            ));
            return;
        }
        if ($node instanceof Expr\StaticCall && !($node->name instanceof Node\Identifier)) {
            $this->findings->addSink(new Finding(
                'sink', 'dispatch', 'Class::$$method()', $node->getLine(), $this->currentContext(), 'variable static method'
            ));
            return;
        }
        if ($node instanceof Expr\New_ && !($node->class instanceof Node\Name)) {
            $this->findings->addSink(new Finding(
                'sink', 'dispatch', 'new $variable', $node->getLine(), $this->currentContext(), 'variable class'
            ));
            return;
        }
```

- [ ] **Step 4: Run, adjust line numbers if needed, verify pass**

- [ ] **Step 5: Commit**

```bash
git add src/Analysis/SecurityAnalysisVisitor.php tests/analysis.txt
git commit -m "Analysis: detect variable function/method/new dispatch sinks"
```

---

## Task 11: Pseudo-stream sources (`php://input`, `php://stdin`)

Detect `file_get_contents`, `fopen`, `stream_get_contents` calls whose first argument is a string literal beginning with `php://input` or `php://stdin`.

**Files:**
- Modify: `src/Analysis/SecurityAnalysisVisitor.php`
- Modify: `tests/analysis.txt`

- [ ] **Step 1: Append the failing fixture**

```

INPUT
$body = file_get_contents('php://input');
$h = fopen('php://stdin', 'r');
$other = file_get_contents('config.json');

OUTPUT
$body = file_get_contents("php://input");
$h = fopen("php://stdin", "r");
$other = file_get_contents("config.json");

ANALYSIS
sources:
  auto-exec  line 2  file_get_contents('php://input')
  auto-exec  line 3  fopen('php://stdin')
sinks:
```

The benign `file_get_contents('config.json')` is **not** flagged. Note that `file_get_contents` and `fopen` are NOT in the Layer-A sink catalog, so they don't get a sink finding — only the pseudo-stream source.

- [ ] **Step 2: Run, verify failure**

- [ ] **Step 3: Extend `detectSource`**

Add at the bottom of `SecurityAnalysisVisitor::detectSource`:

```php
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = strtolower($node->name->toString());
            if (in_array($name, ['file_get_contents', 'fopen', 'stream_get_contents'], true)) {
                $first = $node->args[0]->value ?? null;
                if ($first instanceof Node\Scalar\String_) {
                    $val = $first->value;
                    if (strpos($val, 'php://input') === 0 || strpos($val, 'php://stdin') === 0) {
                        $stream = (strpos($val, 'php://input') === 0) ? 'php://input' : 'php://stdin';
                        $this->findings->addSource(new Finding(
                            'source',
                            'pseudo_stream',
                            $name . "('" . $stream . "')",
                            $node->getLine(),
                            $this->currentContext()
                        ));
                    }
                }
            }
        }
```

- [ ] **Step 4: Run, verify pass**

- [ ] **Step 5: Commit**

```bash
git add src/Analysis/SecurityAnalysisVisitor.php tests/analysis.txt
git commit -m "Analysis: detect php://input and php://stdin pseudo-stream sources"
```

---

## Task 12: Production text formatter

Add `ReportFormatter::formatText()` — column-aligned report with `===== Analysis =====` header, three sections, and a summary line — and a fixture that exercises it. The fixture-comparison form (`formatFixture`) used by `test.php` stays as-is; this task adds a *separate* test mode for the production form.

**Files:**
- Modify: `src/Analysis/ReportFormatter.php`
- Modify: `test.php` (recognize an `ANALYSIS-TEXT` block)
- Modify: `tests/analysis.txt`

- [ ] **Step 1: Append fixture**

```

INPUT
$x = $_GET['c'];
eval($x);
function helper() {
    unserialize($_POST['p']);
}

OUTPUT
$x = $_GET["c"];
eval($x);
function helper()
{
    unserialize($_POST["p"]);
}

ANALYSIS-TEXT
===== Analysis =====

Sources (2):
  [auto-exec]                    line 2   $_GET['c']
  [in-function:helper]           line 5   $_POST['p']

Sinks (2):
  [auto-exec]                    code_exec  line 3   eval
  [in-function:helper]           deser      line 5   unserialize

Summary: 2 sources, 2 sinks (2 auto-exec out of 4 total findings). Categories present: code_exec, deser.
```

(Padding widths in the fixture: pick a consistent width per column — `[context]` col padded to the longest context+2, category col padded to the longest category+2. Adjust to match what `formatText` actually emits in Step 4.)

- [ ] **Step 2: Implement `formatText` in `ReportFormatter`**

Add to `src/Analysis/ReportFormatter.php`:

```php
    public function formatText(Findings $f, string $filename = ''): string
    {
        $sources = $f->sortedSources();
        $sinks = $f->sortedSinks();

        $contextWidth = 12;
        foreach (array_merge($sources, $sinks) as $finding) {
            $w = strlen('[' . $finding->context . ']');
            if ($w > $contextWidth) $contextWidth = $w;
        }
        $contextWidth += 2;

        $catWidth = 4;
        foreach ($sinks as $finding) {
            if (strlen($finding->category) > $catWidth) $catWidth = strlen($finding->category);
        }
        $catWidth += 2;

        $out = [];
        $out[] = '===== Analysis =====';
        $out[] = '';
        $out[] = 'Sources (' . count($sources) . '):';
        if (count($sources) === 0) {
            $out[] = '  (none)';
        } else {
            foreach ($sources as $s) {
                $ctx = str_pad('[' . $s->context . ']', $contextWidth);
                $out[] = '  ' . $ctx . 'line ' . $s->line . '   ' . $s->label;
            }
        }
        $out[] = '';
        $out[] = 'Sinks (' . count($sinks) . '):';
        if (count($sinks) === 0) {
            $out[] = '  (none)';
        } else {
            foreach ($sinks as $s) {
                $ctx = str_pad('[' . $s->context . ']', $contextWidth);
                $cat = str_pad($s->category, $catWidth);
                $note = $s->note === null ? '' : ' (' . $s->note . ')';
                $out[] = '  ' . $ctx . $cat . 'line ' . $s->line . '   ' . $s->label . $note;
            }
        }
        $out[] = '';
        $cats = $f->categoriesPresent();
        $out[] = sprintf(
            'Summary: %d sources, %d sinks (%d auto-exec out of %d total findings). Categories present: %s.',
            count($sources),
            count($sinks),
            $f->autoExecCount(),
            $f->count(),
            count($cats) === 0 ? '(none)' : implode(', ', $cats)
        );

        return implode("\n", $out);
    }
```

- [ ] **Step 3: Extend `test.php` to recognize an `ANALYSIS-TEXT` block**

In `test.php`'s parser, alongside the `ANALYSIS` branch, add:

```php
        } elseif ($trim === 'ANALYSIS-TEXT') {
            $curTest['analysis_text'] = array();
            $lines = &$curTest['analysis_text'];
            continue;
```

Initialize `$curTest['analysis_text'] = null;` alongside the existing `'analysis' => null`.

After the existing analysis-block comparison block, add:

```php
        $analysisTextPass = true;
        $analysisTextExpected = null;
        $analysisTextGot = null;
        if (isset($test['analysis_text']) && $test['analysis_text'] !== null) {
            $analysisTextExpected = trim(implode('', $test['analysis_text']));
            $findings = $deobf->analyze($out);
            $formatter = new \PHPDeobfuscator\Analysis\ReportFormatter();
            $analysisTextGot = trim($formatter->formatText($findings));
            $analysisTextPass = ($analysisTextGot === $analysisTextExpected);
        }
        if (!$analysisTextPass) {
            echo "Test $name failed (analysis-text):\n";
            echo "Expected:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $analysisTextExpected)));
            echo "\n";
            echo "Got:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $analysisTextGot)));
            echo "\n";
        }
```

Update the final pass condition to AND in `$analysisTextPass`:

```php
        if ($deobfPass && $analysisPass && $analysisTextPass) {
            echo "Test $name pass\n";
        }
```

- [ ] **Step 4: Run, copy actual `Got:` block into the fixture's `ANALYSIS-TEXT`, re-run, verify pass**

Run: `php -d error_reporting=E_ALL test.php`
Expected: First run fails because hand-aligned columns don't match. Copy the `Got:` text into the fixture, re-run, expected pass.

- [ ] **Step 5: Commit**

```bash
git add src/Analysis/ReportFormatter.php test.php tests/analysis.txt
git commit -m "Analysis: production text formatter with column alignment and summary"
```

---

## Task 13: JSON formatter

Add `ReportFormatter::formatJson()` and a fixture that compares JSON via `json_decode` + recursive equality (whitespace-insensitive).

**Files:**
- Modify: `src/Analysis/ReportFormatter.php`
- Modify: `test.php` (recognize an `ANALYSIS-JSON` block, decode-compare)
- Modify: `tests/analysis.txt`

- [ ] **Step 1: Append fixture**

```

INPUT
$x = $_GET['c'];
eval($x);

OUTPUT
$x = $_GET["c"];
eval($x);

ANALYSIS-JSON
{
  "version": 1,
  "filename": "input.php",
  "sources": [
    {"category": "superglobal", "label": "$_GET['c']", "line": 2, "context": "auto-exec"}
  ],
  "sinks": [
    {"category": "code_exec", "label": "eval", "line": 3, "context": "auto-exec", "note": null}
  ],
  "summary": {
    "source_count": 1,
    "sink_count": 1,
    "auto_exec_count": 2,
    "categories_present": ["code_exec"]
  }
}
```

- [ ] **Step 2: Implement `formatJson` in `ReportFormatter`**

Add to `src/Analysis/ReportFormatter.php`:

```php
    public function formatJson(Findings $f, string $filename = 'input.php'): string
    {
        $payload = [
            'version' => 1,
            'filename' => $filename,
            'sources' => array_map([$this, 'findingToArray'], $f->sortedSources()),
            'sinks' => array_map([$this, 'findingToArray'], $f->sortedSinks()),
            'summary' => [
                'source_count' => count($f->getSources()),
                'sink_count' => count($f->getSinks()),
                'auto_exec_count' => $f->autoExecCount(),
                'categories_present' => $f->categoriesPresent(),
            ],
        ];
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function findingToArray(Finding $f): array
    {
        $out = [
            'category' => $f->category,
            'label' => $f->label,
            'line' => $f->line,
            'context' => $f->context,
        ];
        if ($f->kind === 'sink' || $f->kind === 'meta') {
            $out['note'] = $f->note;
        }
        return $out;
    }
```

- [ ] **Step 3: Extend `test.php` for `ANALYSIS-JSON` block**

Mirror the `ANALYSIS-TEXT` plumbing from Task 12 Step 3. Initialize `$curTest['analysis_json'] = null;`. Add the `ANALYSIS-JSON` parser branch. Add comparison after the analysis-text block:

```php
        $analysisJsonPass = true;
        $analysisJsonExpected = null;
        $analysisJsonGot = null;
        if (isset($test['analysis_json']) && $test['analysis_json'] !== null) {
            $analysisJsonExpected = trim(implode('', $test['analysis_json']));
            $findings = $deobf->analyze($out);
            $formatter = new \PHPDeobfuscator\Analysis\ReportFormatter();
            $analysisJsonGot = trim($formatter->formatJson($findings));
            $expectedDecoded = json_decode($analysisJsonExpected, true);
            $gotDecoded = json_decode($analysisJsonGot, true);
            $analysisJsonPass = ($expectedDecoded !== null && $expectedDecoded === $gotDecoded);
        }
        if (!$analysisJsonPass) {
            echo "Test $name failed (analysis-json):\n";
            echo "Expected:\n";
            echo $analysisJsonExpected . "\n";
            echo "Got:\n";
            echo $analysisJsonGot . "\n";
        }
```

Update the final pass condition to AND in `$analysisJsonPass`.

- [ ] **Step 4: Run, adjust fixture (mostly line numbers and `auto_exec_count`), verify pass**

Run: `php -d error_reporting=E_ALL test.php`
Expected: pass after adjustments.

- [ ] **Step 5: Commit**

```bash
git add src/Analysis/ReportFormatter.php test.php tests/analysis.txt
git commit -m "Analysis: JSON formatter with decoded-equality fixture comparison"
```

---

## Task 14: CLI integration in `index.php`

Wire `-a` (text) and `-j` (JSON) flags into the CLI; mirror via `?analyze=text|json|both` query params for the web SAPI. Default behavior (no flags / no query) is unchanged.

**Files:**
- Modify: `index.php`

- [ ] **Step 1: Update `getopt` and CLI dispatch**

Change line 23 from:
```php
$opts = getopt('tof:');
```
to:
```php
$opts = getopt('tof:aj');
```

Replace the CLI block (the `if (php_sapi_name() == 'cli') { ... }` body) with:

```php
    $opts = getopt('tof:aj');
    if (!isset($opts['f'])) {
        die("Missing required parameter -f\n");
    }
    $filename = $opts['f'];
    $orig = isset($opts['o']);
    list($tree, $code) = deobfuscate(file_get_contents($filename), $filename, $orig);
    echo $code, "\n";
    if (isset($opts['t'])) {
        echo $nodeDumper->dump($tree), "\n";
    }
    if (isset($opts['a']) || isset($opts['j'])) {
        $deobf = new \PHPDeobfuscator\Deobfuscator();
        $findings = $deobf->analyze($code);
        $formatter = new \PHPDeobfuscator\Analysis\ReportFormatter();
        if (isset($opts['a'])) {
            echo "\n" . $formatter->formatText($findings, basename($filename)) . "\n";
        }
        if (isset($opts['a']) && isset($opts['j'])) {
            echo "\n===== Analysis (JSON) =====\n";
        }
        if (isset($opts['j'])) {
            echo "\n" . $formatter->formatJson($findings, basename($filename)) . "\n";
        }
    }
```

Note: the `analyze()` call uses a fresh `Deobfuscator` instance because the original `deobfuscate()` helper function creates one internally and doesn't return it. (We use a fresh instance because `analyze` only re-parses; it doesn't need state from the deobfuscation run.)

- [ ] **Step 2: Update web-SAPI block similarly**

Replace the `else { if (isset($_POST['phpdata'])) { ... } }` body's analysis tail. After the existing `echo $code, "\n\n";` and `if (array_key_exists('tree', $_GET)) { ... }`, before the `else { ... }` form fallback, insert:

```php
        if (isset($_GET['analyze'])) {
            $mode = $_GET['analyze'];
            $deobf = new \PHPDeobfuscator\Deobfuscator();
            $findings = $deobf->analyze($code);
            $formatter = new \PHPDeobfuscator\Analysis\ReportFormatter();
            if ($mode === 'text' || $mode === 'both') {
                echo "\n" . $formatter->formatText($findings, 'input.php') . "\n";
            }
            if ($mode === 'both') {
                echo "\n===== Analysis (JSON) =====\n";
            }
            if ($mode === 'json' || $mode === 'both') {
                echo "\n" . $formatter->formatJson($findings, 'input.php') . "\n";
            }
        }
```

- [ ] **Step 3: Smoke-test the CLI**

Create a one-off sample to test (don't commit it):

```bash
cat > /tmp/phpdeobf-smoketest.php <<'EOF'
<?php
$x = $_GET['c'];
eval($x);
EOF

php index.php -f /tmp/phpdeobf-smoketest.php -a
```
Expected: deobfuscated code (echoes `<?php`, blank line, `$x = $_GET["c"];`, `eval($x);`), then a blank line, then `===== Analysis =====` header, then sources/sinks listed.

```bash
php index.php -f /tmp/phpdeobf-smoketest.php -j
```
Expected: deobfuscated code, blank line, JSON document.

```bash
php index.php -f /tmp/phpdeobf-smoketest.php -aj
```
Expected: deobfuscated code, blank line, text report, blank line, `===== Analysis (JSON) =====`, blank line, JSON.

```bash
php index.php -f /tmp/phpdeobf-smoketest.php
```
Expected: deobfuscated code only — no analysis output (proves default behavior unchanged).

```bash
rm /tmp/phpdeobf-smoketest.php
```

- [ ] **Step 4: Run the test suite to confirm no regressions**

Run: `php -d error_reporting=E_ALL test.php`
Expected: all tests still pass.

- [ ] **Step 5: Commit**

```bash
git add index.php
git commit -m "CLI: -a/-j flags and ?analyze= web param wire up analysis report"
```

---

## Task 15: Negative-predicate fixture

A single fixture that proves the predicates correctly *skip* benign cases — exercise all three predicate-bearing rules in one place to lock down the behavior.

**Files:**
- Modify: `tests/analysis.txt`

- [ ] **Step 1: Append the fixture**

```

INPUT
include 'config.php';
require 'lib/setup.php';
header('Content-Type: text/html');
header('Cache-Control: no-cache');
preg_replace('/x/', 'y', $z);
preg_filter('/a/', 'b', $z);

OUTPUT
include "config.php";
require "lib/setup.php";
header("Content-Type: text/html");
header("Cache-Control: no-cache");
preg_replace("/x/", "y", $z);
preg_filter("/a/", "b", $z);

ANALYSIS
sources:
sinks:
```

- [ ] **Step 2: Run, verify pass**

Run: `php -d error_reporting=E_ALL test.php`
Expected: `Test analysis.txt/<N> pass`. The empty `sources:` and `sinks:` sections prove all six benign calls were skipped.

- [ ] **Step 3: Commit**

```bash
git add tests/analysis.txt
git commit -m "Analysis: negative fixture proves predicates skip benign calls"
```

---

## Task 16: Nested-frames fixture (closure inside method, namespace context)

Lock down the qualified-name behavior when frames nest inside namespaced classes — this is a fragile area and worth pinning down once everything else works.

**Files:**
- Modify: `tests/analysis.txt`

- [ ] **Step 1: Append the fixture**

```

INPUT
namespace Acme\Inner;

class Worker {
    public function run() {
        $cb = function () {
            eval($_POST['x']);
        };
    }
}

OUTPUT
namespace Acme\Inner;

class Worker
{
    public function run()
    {
        $cb = function () {
            eval($_POST["x"]);
        };
    }
}

ANALYSIS
sources:
  in-function:{closure@line:7}  line 8  $_POST['x']
sinks:
  in-function:{closure@line:7}  code_exec  line 8  eval
```

- [ ] **Step 2: Run, adjust line numbers if needed, verify pass**

Run: `php -d error_reporting=E_ALL test.php`
Expected: pass. The qualified name is the *closure*'s frame (innermost), not the enclosing method's. Spec design point: closures get `{closure@line:N}` regardless of enclosing class — the *innermost* frame wins. If you wanted the full chain (`Acme\Inner\Worker::run.{closure@line:7}`), that's a v2 enhancement.

- [ ] **Step 3: Commit**

```bash
git add tests/analysis.txt
git commit -m "Analysis: nested-frames fixture (closure inside namespaced method)"
```

---

## Task 17: Error-handling fixture (analyze() failure path)

The `analyze()` method swallows exceptions and emits a synthetic `meta` finding instead. We can't easily make the re-parser crash from a fixture (the input is already valid PHP by the time we get there), so we'll exercise the error path by writing a tiny one-off PHP script that calls `analyze('this is not <?php valid')` directly.

**Files:**
- No source changes (error handling already implemented in Task 2 Step 4).
- Create one-off verification script (don't commit it).

- [ ] **Step 1: Verify the error path manually**

```bash
cat > /tmp/phpdeobf-errcheck.php <<'EOF'
<?php
require 'vendor/autoload.php';
$d = new \PHPDeobfuscator\Deobfuscator();
$findings = $d->analyze('not valid php at all');
$fmt = new \PHPDeobfuscator\Analysis\ReportFormatter();
echo $fmt->formatFixture($findings), "\n";
EOF
php /tmp/phpdeobf-errcheck.php
rm /tmp/phpdeobf-errcheck.php
```

Expected: `error_log` writes a message to stderr; stdout shows `sources:\nsinks:\n  auto-exec  analysis_aborted  line 0  <some parser error message>`.

If the output doesn't match (e.g., no synthetic finding, or process crashes), fix `Deobfuscator::analyze()` — the try/catch in Task 2 Step 4 should be catching `\Throwable`.

- [ ] **Step 2: Run the test suite to confirm no regressions**

Run: `php -d error_reporting=E_ALL test.php`
Expected: all fixtures still pass.

- [ ] **Step 3: No commit** (no source changed in this task — it's just verification of work from Task 2).

---

## Task 18: README update

Document the new feature.

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add a new section to `README.md`**

Find an appropriate place after the existing usage docs. Add:

```markdown
## Security analysis

After deobfuscation, `index.php` can emit a static analysis report listing
attacker-controlled input sources (`$_GET`, `$_POST`, ...), dangerous-function
sinks (`eval`, `system`, `unserialize`, ...), and tag each finding as either
`auto-exec` (runs at script load) or `in-function:<name>` (only runs if that
function is called).

CLI:

    php index.php -f input.php -a       # text report
    php index.php -f input.php -j       # JSON report
    php index.php -f input.php -aj      # both

Web (after POSTing source via the form):

    ?analyze=text      # text report
    ?analyze=json      # JSON report
    ?analyze=both      # both

The analysis is a static, syntactic scan — it does not track data flow from
sources to sinks. Use the report to find candidate lines worth reading; verify
exploitability by hand.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "README: document -a / -j security analysis flags"
```

---

## Self-Review

Spec coverage check (every spec section has at least one task implementing it):

- **Sources / superglobals:** Task 2 (one), Task 3 (all eight). ✓
- **Sources / pseudo-streams:** Task 11. ✓
- **Sinks / Layer A name-based:** Task 5. ✓
- **Sinks / `Expr\Eval_` and `Expr\ShellExec`:** Task 6. ✓
- **Sinks / dynamic include with literal-arg predicate:** Task 7. ✓
- **Sinks / header with literal-arg predicate:** Task 8. ✓
- **Sinks / preg_replace `/e` modifier or non-literal pattern:** Task 9. ✓
- **Sinks / dispatch (variable function/method/`new`):** Task 10. ✓
- **Context tagging (auto-exec vs. in-function):** Tasks 2 (auto-exec), 4 (full coverage with namespace/class qualifiers), 16 (nested closures). ✓
- **Output / fixture form:** Task 2 (`formatFixture`). ✓
- **Output / production text:** Task 12 (`formatText`). ✓
- **Output / JSON:** Task 13 (`formatJson`). ✓
- **CLI flags `-a`/`-j` and combined `-aj`:** Task 14. ✓
- **Web SAPI `?analyze=text|json|both`:** Task 14. ✓
- **Test fixture format extension (`ANALYSIS` block):** Task 2. `ANALYSIS-TEXT` and `ANALYSIS-JSON` blocks: Tasks 12 and 13. ✓
- **Error handling (analyze never throws, emits meta finding on failure):** Task 2 Step 4 (implementation), Task 17 (verification). ✓
- **Negative-predicate coverage:** Task 15. ✓
- **Documentation:** Task 18. ✓

No gaps identified. No placeholder/TBD entries. Type names (`Findings`, `Finding`, `ReportFormatter`, `SecurityAnalysisVisitor`, `DangerousCatalog`) are consistent across tasks. Method signatures match between definition (Task 1, Task 2) and call sites (Tasks 12, 13, 14).
