# `$GLOBALS["literal"](closure-args)` Evaluation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a `FuncCall` has the shape `$GLOBALS["k"](args)` and the literal key was assigned a closure literal at top level in the global scope, evaluate the closure body symbolically with the call's arguments and replace the entire call site with the resulting scalar — best-effort: only when the body folds cleanly to `return <scalar>`.

**Architecture:** Sibling of the previously-shipped `resolveGlobalsLiteralName` fallback. A new `Resolver` side-channel records `name → Expr\Closure` for top-level closure assignments in the global scope (skipping closures with `use()`, by-ref, or default-valued params). A new `FuncCallReducer::resolveGlobalsLiteralClosureCall` recognises the same `$GLOBALS["literal"]` shape, looks up the closure, and builds a synthetic source string of shape `function () { $param1=arg1; ...; <body> };` — the closure-literal wrapper is required for scope isolation. `EvalReducer::runEvalTree` runs this through the full pipeline; if the synthetic closure's last body stmt is `Return_` with a scalar `VALUE`, that scalar replaces the call. Recursion is depth-capped at 8 in `EvalReducer`.

**Tech Stack:** PHP 8.0+, `nikic/php-parser` v4. Test runner is `php test.php` — iterates `tests/*.txt`, prints `pass`/`failed` per `INPUT`/`OUTPUT` block, no fail-stop, no filter.

**Spec:** `docs/superpowers/specs/2026-04-28-globals-closure-eval-design.md`

## File map

- **Modify** `src/Reducer/FuncCallReducer/PassThrough.php` — add `'strlen'` to the supported-names list (prerequisite — closure body in motivating sample uses it).
- **Modify** `src/Resolver.php` — add `private array $globalClosures = []`, public `registerGlobalClosure()`, public `getGlobalClosure()`, private `tryRegisterGlobalClosure()`. Hook the registration into `onAssign`.
- **Modify** `src/Reducer/EvalReducer.php` — add a recursion-depth counter (`private int $depth = 0`, `const MAX_DEPTH = 8`), increment/decrement around `deobfTree` calls in `runEval` and `runEvalTree`, short-circuit when exceeded.
- **Modify** `src/Reducer/FuncCallReducer.php` — accept `EvalReducer` in constructor, add private `resolveGlobalsLiteralClosureCall()` helper, splice it into `reduceFunctionCall` as a new fallback ahead of the existing `resolveGlobalsLiteralName` call.
- **Modify** `src/Deobfuscator.php` (line 42) — pass `$evalReducer` into the `FuncCallReducer` constructor.
- **Create** `tests/globals-closure-eval.txt` — five `INPUT`/`OUTPUT` fixture blocks (happy path, mutability bypass, body-doesn't-fold, `use()` skip, function-body skip).
- **Modify** `tests/reducers.txt` — add a `strlen` regression fixture (locks in the PassThrough addition).

---

### Task 1: Register `strlen` in PassThrough

`strlen` isn't claimed by any reducer today; the motivating closure body uses it for the middle slice. Adding it is a one-line registration plus a regression fixture.

**Files:**
- Modify: `src/Reducer/FuncCallReducer/PassThrough.php` (the alphabetical name list)
- Modify: `tests/reducers.txt` (append a regression fixture)

- [ ] **Step 1: Add the regression fixture first (failing).**

Append to `tests/reducers.txt`:

```
INPUT
$x = strlen('hello');

OUTPUT
$x = 5;
```

- [ ] **Step 2: Run the suite, confirm the new case fails.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | tail -20`
Expected: a line beginning `Test reducers.txt/<n> failed:` with `Got:` showing `$x = strlen('hello');` (the call wasn't folded). All other tests still `pass`.

- [ ] **Step 3: Add `strlen` to PassThrough's name list.**

In `src/Reducer/FuncCallReducer/PassThrough.php`, the list inside `getSupportedNames()` is alphabetical. Insert `'strlen',` between `'strcmp',` and `'strrev',`:

```php
            'strcmp',
            'strlen',
            'strrev',
```

- [ ] **Step 4: Run the suite, confirm the new case passes.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "failed|reducers.txt"`
Expected: every `reducers.txt/<n>` line shows `pass`. No `failed` lines anywhere.

- [ ] **Step 5: Commit.**

```bash
git add src/Reducer/FuncCallReducer/PassThrough.php tests/reducers.txt
git commit -m "Register strlen as PassThrough reducer

Prerequisite for the upcoming \$GLOBALS-closure-eval feature: the motivating
closure body uses strlen(\$strv) to compute a middle-slice length, which
won't fold without strlen in the function-call reducer registry."
```

---

### Task 2: Add the failing happy-path fixture

Lock in the target behavior before any feature code — the suite will report this case as failed until Task 7 lands.

**Files:**
- Create: `tests/globals-closure-eval.txt`

- [ ] **Step 1: Create the fixture file with the happy-path case only.**

Write `tests/globals-closure-eval.txt` with exactly:

```
INPUT
$decode = function ($strv) {
    $a = substr($strv, 0, 5);
    $b = substr($strv, -5);
    $c = substr($strv, 7, strlen($strv) - 14);
    return gzinflate(base64_decode($a . $c . $b));
};
$out = $GLOBALS['decode']('09PXyhTyhJTE5OLS4tPGAA==');

OUTPUT
$decode = function ($strv) {
    $a = substr($strv, 0, 5);
    $b = substr($strv, -5);
    $c = substr($strv, 7, strlen($strv) - 14);
    return gzinflate(base64_decode($a . $c . $b));
};
$out = './.htaccess';
```

(The closure definition is preserved verbatim in the OUTPUT — only the call site is rewritten. Cleaning up the now-unused closure definition is out of scope.)

- [ ] **Step 2: Run the suite, confirm the new case fails.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval"`
Expected: `Test globals-closure-eval.txt/1 failed:`. The `Got:` block will show `$out = $GLOBALS['decode']('09PXyhTyhJTE5OLS4tPGAA==');` instead of `$out = './.htaccess';`.

- [ ] **Step 3: Confirm no other fixture files regressed.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep "failed" | grep -v "globals-closure-eval"`
Expected: empty output.

- [ ] **Step 4: Commit.**

```bash
git add tests/globals-closure-eval.txt
git commit -m "Add failing fixture: \$GLOBALS[literal](closure-args) happy path

Locks in the target behavior before implementation: a top-level closure
assigned to \$decode invoked via \$GLOBALS['decode'](literal) should be
evaluated symbolically to its scalar return value."
```

---

### Task 3: Add closure registry storage to `Resolver`

Add the empty registry and its public read/write API. No auto-population yet — Task 4 wires that.

**Files:**
- Modify: `src/Resolver.php`

- [ ] **Step 1: Add `Expr\Closure` to the file's import block.**

Find the `use PhpParser\Node\Expr;` line (already present near the top) — no edit needed; `Expr\Closure` is reachable via that import.

- [ ] **Step 2: Add the property.**

Locate the property block in the `Resolver` class (after the constants, before the constructor — there's a `private $constants = [];` style block). Add a typed property:

```php
    /** @var array<string, \PhpParser\Node\Expr\Closure> */
    private array $globalClosures = [];
```

- [ ] **Step 3: Add `registerGlobalClosure` and `getGlobalClosure` near `getGlobalScope` (around line 260).**

Right after the existing `public function getGlobalScope()` method, add:

```php
    public function registerGlobalClosure(string $name, Expr\Closure $closure): void
    {
        $this->globalClosures[$name] = $closure;
    }

    public function getGlobalClosure(string $name): ?Expr\Closure
    {
        return $this->globalClosures[$name] ?? null;
    }
```

- [ ] **Step 4: Run the test suite, confirm no regression.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep "failed" | grep -v "globals-closure-eval"`
Expected: empty output. (The happy-path fixture from Task 2 still fails; everything else still passes.)

- [ ] **Step 5: Commit.**

```bash
git add src/Resolver.php
git commit -m "Resolver: add empty global-closure registry + read/write API

Stores name -> Expr\\Closure for top-level closure assignments. Currently
populated by no caller; wiring lands in the next commit."
```

---

### Task 4: Populate the closure registry from `onAssign`

Hook into the existing `onAssign` so that a top-level assignment matching `$X = function(){...}` (or `$GLOBALS["X"] = function(){...}`) registers the closure — gated on the current scope being the global scope and the closure having no `use`, no by-ref params, no default-valued params.

**Files:**
- Modify: `src/Resolver.php`

- [ ] **Step 1: Add a private helper `tryRegisterGlobalClosure`.**

Place it directly after `getGlobalClosure` from Task 3:

```php
    private function tryRegisterGlobalClosure(Expr\Assign $expr): void
    {
        if ($this->scope !== $this->getGlobalScope()) {
            return;
        }
        $rhs = $expr->expr;
        if (!($rhs instanceof Expr\Closure)) {
            return;
        }
        if ($rhs->uses !== []) {
            return;
        }
        foreach ($rhs->params as $param) {
            if ($param->byRef) {
                return;
            }
            if ($param->default !== null) {
                return;
            }
        }

        $lhs = $expr->var;
        $name = null;
        if ($lhs instanceof Expr\Variable && is_string($lhs->name)) {
            $name = $lhs->name;
        } elseif ($lhs instanceof Expr\ArrayDimFetch
            && $lhs->var instanceof Expr\Variable
            && $lhs->var->name === 'GLOBALS'
            && $lhs->dim instanceof \PhpParser\Node\Scalar\String_
        ) {
            $name = $lhs->dim->value;
        }
        if ($name === null) {
            return;
        }

        $this->registerGlobalClosure($name, $rhs);
    }
```

- [ ] **Step 2: Call the helper from `onAssign`.**

Locate `onAssign` (currently around line 357). Add the helper call as the first line:

```php
    private function onAssign(Expr\Assign $expr)
    {
        $this->tryRegisterGlobalClosure($expr);
        $varRef = $this->resolveVariable($expr->var);
        $valRef = $this->resolveValue($expr->expr);
        $this->assign($varRef, $valRef);
    }
```

- [ ] **Step 3: Run the suite, confirm no regressions.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep "failed" | grep -v "globals-closure-eval"`
Expected: empty output. (The happy-path fixture still fails — the registry is now populated but nothing reads it yet.)

- [ ] **Step 4: Commit.**

```bash
git add src/Resolver.php
git commit -m "Resolver: auto-register top-level closure assignments

In onAssign, when LHS is a simple variable (or \$GLOBALS[literal]) at
global scope and RHS is a closure literal with no use(), no by-ref params,
and no default-valued params, record name -> Expr\\Closure in the registry.
Last write wins on reassignment."
```

---

### Task 5: Add recursion-depth guard to `EvalReducer`

Cap recursive `runEvalTree` / `runEval` invocations at 8 levels. Returns an empty stmt list when exceeded so callers see "no return statement" and abort cleanly.

**Files:**
- Modify: `src/Reducer/EvalReducer.php`

- [ ] **Step 1: Add the depth property and constant.**

In `EvalReducer`, add right after the existing `private $outputAsEvalStr;` property:

```php
    private int $depth = 0;
    private const MAX_DEPTH = 8;
```

- [ ] **Step 2: Wrap `runEvalTree` with the depth guard.**

Replace the existing one-line method with:

```php
    public function runEvalTree($code)
    {
        if ($this->depth >= self::MAX_DEPTH) {
            return [];
        }
        $this->depth++;
        try {
            return $this->deobfTree($this->parseCode($code));
        } finally {
            $this->depth--;
        }
    }
```

- [ ] **Step 3: Wrap the body of `runEval` so it shares the same counter.**

`runEval` already calls `deobfTree`. Replace its body:

```php
    public function runEval($code)
    {
        if ($this->depth >= self::MAX_DEPTH) {
            return null;
        }
        $this->depth++;
        try {
            $origTree = $this->parseCode($code);
            $tree = $this->deobfTree($origTree);
            // If it's just a single expression, return directly
            // XXX this is not semantically correct because eval does not return
            // anything by default
            if (count($tree) === 1 && $tree[0] instanceof Stmt\Expression) {
                return $tree[0]->expr;
            }
            if (count($tree) === 1 && $tree[0] instanceof Stmt\Return_) {
                return $tree[0]->expr;
            }
            if ($this->outputAsEvalStr) {
                $expr = new Expr\Eval_(new String_($this->deobfuscator->prettyPrint($tree, false), array(
                    'kind' => String_::KIND_NOWDOC, 'docLabel' => 'EVAL' . rand()
                ))) ;
            } else {
                $expr = new EvalBlock($tree, $origTree);
            }
            return $expr;
        } finally {
            $this->depth--;
        }
    }
```

- [ ] **Step 4: Run the suite, confirm no regressions.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep "failed" | grep -v "globals-closure-eval"`
Expected: empty output.

- [ ] **Step 5: Commit.**

```bash
git add src/Reducer/EvalReducer.php
git commit -m "EvalReducer: cap recursive deobfuscation depth at 8

Adds a re-entry guard around runEvalTree/runEval so the upcoming
closure-call evaluation can't infinite-loop on closure-calls-closure
chains. Eight levels is well above any realistic obfuscator stacking;
exhaustion returns no result so callers abort their rewrite cleanly."
```

---

### Task 6: Wire `EvalReducer` into `FuncCallReducer`

Plumbing-only change — no behavior delta yet. Adding the constructor parameter now keeps Task 7 focused on the new helper.

**Files:**
- Modify: `src/Reducer/FuncCallReducer.php` (constructor + property)
- Modify: `src/Deobfuscator.php` (line 42)

- [ ] **Step 1: Add `EvalReducer` import + property + constructor parameter.**

Top of `src/Reducer/FuncCallReducer.php`, add to the imports:

```php
use PHPDeobfuscator\Reducer\EvalReducer;
```

Replace the property block and constructor:

```php
    private $funcCallMap = array();
    private $resolver;
    private $evalReducer;

    public function __construct(Resolver $resolver, EvalReducer $evalReducer)
    {
        $this->resolver = $resolver;
        $this->evalReducer = $evalReducer;
    }
```

- [ ] **Step 2: Pass `$evalReducer` in `Deobfuscator::__construct`.**

In `src/Deobfuscator.php`, change line 42 from:

```php
        $funcCallReducer = new Reducer\FuncCallReducer($resolver);
```

to:

```php
        $funcCallReducer = new Reducer\FuncCallReducer($resolver, $evalReducer);
```

- [ ] **Step 3: Run the suite, confirm no regressions.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep "failed" | grep -v "globals-closure-eval"`
Expected: empty output.

- [ ] **Step 4: Commit.**

```bash
git add src/Reducer/FuncCallReducer.php src/Deobfuscator.php
git commit -m "FuncCallReducer: inject EvalReducer

Plumbing for the upcoming closure-call evaluator. No behavior change."
```

---

### Task 7: Implement `resolveGlobalsLiteralClosureCall` and wire fallback

The substantive change: build the synthetic closure-literal, run it through `EvalReducer::runEvalTree`, and inspect the result for a scalar return.

**Files:**
- Modify: `src/Reducer/FuncCallReducer.php`

- [ ] **Step 1: Add the helper at the bottom of the class.**

Right after the existing `resolveGlobalsLiteralName` method, add:

```php
    /**
     * Targeted fallback: $GLOBALS["literal"](args) where the literal was
     * assigned a closure literal at global scope. Returns a fully-evaluated
     * scalar replacement node, or null to leave the call site untouched.
     *
     * Strategy: build a synthetic parameter-less closure literal whose body
     * binds the call's arguments to the original closure's parameter names
     * and then runs the original body. EvalReducer::runEvalTree drives the
     * full pipeline on the synthetic source; the closure-literal wrapper
     * isolates the synthetic locals from the caller's scope (the Resolver
     * is shared with the outer traversal). If the reduced body's last stmt
     * is a Return_ with a known scalar VALUE, return that scalar.
     */
    private function resolveGlobalsLiteralClosureCall(Node\Expr\FuncCall $node): ?Node
    {
        $expr = $node->name;
        if (!($expr instanceof Node\Expr\ArrayDimFetch)) {
            return null;
        }
        $var = $expr->var;
        if (!($var instanceof Node\Expr\Variable)
            || !is_string($var->name) || $var->name !== 'GLOBALS'
        ) {
            return null;
        }
        $dim = $expr->dim;
        if (!($dim instanceof Node\Scalar\String_)) {
            return null;
        }
        $closure = $this->resolver->getGlobalClosure($dim->value);
        if ($closure === null) {
            return null;
        }
        if (count($closure->params) !== count($node->args)) {
            return null;
        }

        try {
            $printer = new \PHPDeobfuscator\ExtendedPrettyPrinter();
            $bindings = '';
            foreach ($closure->params as $i => $param) {
                $paramName = $param->var->name;
                if (!is_string($paramName)) {
                    return null;
                }
                $argSrc = $printer->prettyPrintExpr($node->args[$i]->value);
                $bindings .= '$' . $paramName . ' = ' . $argSrc . ";\n";
            }
            $bodySrc = $printer->prettyPrint($closure->stmts);
            $source = "function () {\n" . $bindings . $bodySrc . "\n};";
            $stmts = $this->evalReducer->runEvalTree($source);
        } catch (\Throwable $e) {
            return null;
        }
        if (count($stmts) !== 1 || !($stmts[0] instanceof Node\Stmt\Expression)) {
            return null;
        }
        $reducedClosure = $stmts[0]->expr;
        if (!($reducedClosure instanceof Node\Expr\Closure)) {
            return null;
        }
        $bodyStmts = $reducedClosure->stmts;
        if (empty($bodyStmts)) {
            return null;
        }
        $last = end($bodyStmts);
        if (!($last instanceof Node\Stmt\Return_) || $last->expr === null) {
            return null;
        }
        try {
            $value = Utils::getValue($last->expr);
        } catch (\PHPDeobfuscator\Exceptions\BadValueException $e) {
            return null;
        }
        if (is_array($value) || is_object($value) || is_resource($value)) {
            return null;
        }
        return Utils::scalarToNode($value);
    }
```

- [ ] **Step 2: Splice the new fallback into `reduceFunctionCall`.**

The current `reduceFunctionCall` body's catch block is:

```php
            try {
                $name = Utils::getValue($node->name);
            } catch (\PHPDeobfuscator\Exceptions\BadValueException $e) {
                $name = $this->resolveGlobalsLiteralName($node->name);
                if ($name === null) {
                    return;
                }
            }
```

Replace with:

```php
            try {
                $name = Utils::getValue($node->name);
            } catch (\PHPDeobfuscator\Exceptions\BadValueException $e) {
                $replacement = $this->resolveGlobalsLiteralClosureCall($node);
                if ($replacement !== null) {
                    return $replacement;
                }
                $name = $this->resolveGlobalsLiteralName($node->name);
                if ($name === null) {
                    return;
                }
            }
```

(The closure-call helper is tried first because it's the more specific shape; on a registry miss, control falls through to the existing string-name fallback.)

- [ ] **Step 3: Run the suite, confirm the happy-path fixture now passes.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`
Expected: `Test globals-closure-eval.txt/1 pass`. No `failed` lines anywhere in the output.

- [ ] **Step 4: Commit.**

```bash
git add src/Reducer/FuncCallReducer.php
git commit -m "Implement \$GLOBALS[literal](closure-args) symbolic evaluation

When the existing globals-rewrite fallback can't produce a string function
name and the literal key resolves to a registered closure, build a
synthetic parameter-less closure literal binding the args to the
parameters and running the original body. Run it through EvalReducer; if
the reduced body ends in 'return <scalar>', replace the call site with
that scalar. Closure-literal wrapper isolates the synthetic locals from
the caller's Resolver scope."
```

---

### Task 8: Add fixture — mutability bypass

Confirms the registry side-channel is unaffected by `setCurrentVarsMutable` (the obfuscator-trigger condition the previous spec documented).

**Files:**
- Modify: `tests/globals-closure-eval.txt`

- [ ] **Step 1: Append the new block.**

Append to `tests/globals-closure-eval.txt` (with a blank line separating from the previous block):

```

INPUT
$decode = function ($s) {
    return base64_decode($s);
};
function poison() {
    $GLOBALS['unrelated'] = something();
}
$out = $GLOBALS['decode']('aGVsbG8=');

OUTPUT
$decode = function ($s) {
    return base64_decode($s);
};
function poison()
{
    $GLOBALS['unrelated'] = something();
}
$out = 'hello';
```

- [ ] **Step 2: Run the suite, confirm the case passes.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`
Expected: both `globals-closure-eval.txt/1 pass` and `globals-closure-eval.txt/2 pass`. No `failed` lines.

- [ ] **Step 3: Commit.**

```bash
git add tests/globals-closure-eval.txt
git commit -m "Test: closure-call eval bypasses global-scope mutability flips

Confirms the registry side-channel is not gated by Resolver's mutability
machinery — a poison() function flipping global vars mutable doesn't
prevent closure-call evaluation."
```

---

### Task 9: Add fixture — body that doesn't fold

Confirms silent skip when the closure body's return depends on something the pipeline can't resolve.

**Files:**
- Modify: `tests/globals-closure-eval.txt`

- [ ] **Step 1: Append the new block.**

Append:

```

INPUT
$f = function ($s) {
    return getenv($s) . 'x';
};
$out = $GLOBALS['f']('PATH');

OUTPUT
$f = function ($s) {
    return getenv($s) . 'x';
};
$out = $GLOBALS['f']('PATH');
```

(`getenv` has no reducer registered, so the closure body's return doesn't fold. Whole call left alone.)

- [ ] **Step 2: Run the suite, confirm the case passes.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`
Expected: cases 1, 2, 3 all `pass`. No `failed` lines.

- [ ] **Step 3: Commit.**

```bash
git add tests/globals-closure-eval.txt
git commit -m "Test: closure-call eval silently skips when body doesn't fold

When the closure body's return depends on an unreduced value (e.g.
getenv() with a literal arg), the rewrite aborts and leaves the call
site verbatim."
```

---

### Task 10: Add fixture — closure with `use()` clause

Confirms conservative `use()` policy: closures that capture outer state are skipped at registration time, even when the captured value is a known constant.

**Files:**
- Modify: `tests/globals-closure-eval.txt`

- [ ] **Step 1: Append the new block.**

Append:

```

INPUT
$prefix = 'p_';
$f = function ($s) use ($prefix) {
    return $prefix . $s;
};
$out = $GLOBALS['f']('x');

OUTPUT
$prefix = 'p_';
$f = function ($s) use ($prefix) {
    return $prefix . $s;
};
$out = $GLOBALS['f']('x');
```

- [ ] **Step 2: Run the suite, confirm the case passes.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`
Expected: cases 1–4 all `pass`. No `failed` lines.

- [ ] **Step 3: Commit.**

```bash
git add tests/globals-closure-eval.txt
git commit -m "Test: closure-call eval skips closures with use() clauses

Even when the captured outer var is a known constant, the registry
ignores closures with non-empty 'uses'. Conservative policy by spec."
```

---

### Task 11: Add fixture — closure assigned inside a function body

Confirms scope gating: only top-level (global-scope) closure assignments are registered.

**Files:**
- Modify: `tests/globals-closure-eval.txt`

- [ ] **Step 1: Append the new block.**

Append:

```

INPUT
function setup() {
    $GLOBALS['inner'] = function ($s) {
        return strrev($s);
    };
}
$out = $GLOBALS['inner']('abc');

OUTPUT
function setup()
{
    $GLOBALS['inner'] = function ($s) {
        return strrev($s);
    };
}
$out = $GLOBALS['inner']('abc');
```

- [ ] **Step 2: Run the suite, confirm the case passes.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`
Expected: cases 1–5 all `pass`. No `failed` lines anywhere in the suite.

- [ ] **Step 3: Commit.**

```bash
git add tests/globals-closure-eval.txt
git commit -m "Test: closure-call eval ignores closures assigned in function bodies

The registry only picks up global-scope assignments. Inside setup(),
\$this->scope !== getGlobalScope(), so the closure is never registered
and the call site stays verbatim."
```

---

### Task 12: Smoke check on the real sample

Confirms the feature has the intended effect on the motivating obfuscator output.

**Files:**
- None modified. Run-only.

- [ ] **Step 1: Count `$GLOBALS["` occurrences in the output before-vs-after.**

Run:

```bash
php index.php -f samples/next_DOLOLO.php > /tmp/post.out
git stash
php index.php -f samples/next_DOLOLO.php > /tmp/pre.out
git stash pop
echo "before:"; grep -c '\$GLOBALS\["' /tmp/pre.out
echo "after:";  grep -c '\$GLOBALS\["' /tmp/post.out
```

Expected: the "after" count is meaningfully lower than the "before" count. The exact target depends on how many call sites in `next_DOLOLO.php` invoke registered closures with literal args, but at minimum the `$GLOBALS["D_O_OLLLO_"]('...')` sites visible in the sample (around lines 67, 80, 82, 133, 134, etc. of the original source) should disappear.

- [ ] **Step 2: Sanity-eyeball the diff.**

Run:

```bash
diff /tmp/pre.out /tmp/post.out | head -40
```

Confirm changes are scalar replacements at call sites (`./.htaccess`-shaped strings appearing where `$GLOBALS["..."](...)` previously stood) and not unrelated rewrites.

- [ ] **Step 3: Append the before/after counts to the next commit message.**

There is nothing to commit from the smoke check itself, but the numbers go into the eventual PR description / merge commit, mirroring the previous spec's convention. If a follow-up commit is warranted (e.g., to address an issue uncovered by the smoke check), include the numbers there.

- [ ] **Step 4: Final regression sweep.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep failed`
Expected: empty output. All five new fixtures plus all pre-existing fixtures still `pass`.

---

## Done criteria

- All five fixtures in `tests/globals-closure-eval.txt` `pass`.
- `tests/reducers.txt` strlen fixture `pass`es.
- No regressions in `tests/reducers.txt`, `tests/variables.txt`, `tests/goto-tests.txt`, `tests/filesystem.txt`, `tests/globals.txt`.
- `samples/next_DOLOLO.php` produces strictly fewer `$GLOBALS["` occurrences after the change than before.
- Branch `globals-closure-eval` contains 8 well-scoped commits (Tasks 1, 3, 4, 5, 6, 7, plus fixture commits per Tasks 2, 8–11) on top of the spec commits.
