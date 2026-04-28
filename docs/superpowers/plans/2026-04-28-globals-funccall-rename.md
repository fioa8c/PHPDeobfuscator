# `$GLOBALS["literal"](...)` Function-Name Rewrite — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rewrite obfuscated function calls of the shape `$GLOBALS["foo"](...)` into named calls (e.g. `file_get_contents(...)`) when the global variable's literal value is a valid PHP function identifier, by adding a fallback path inside the existing `FuncCallReducer`.

**Architecture:** A narrow, fallback-only addition. `FuncCallReducer::reduceFunctionCall` already rewrites `$node->name` when `Utils::getValue` succeeds. We add a local `catch` for `UnknownValueException` that calls a new `resolveGlobalsLiteralName(Node $expr)` helper. The helper recognises the shape `Expr\ArrayDimFetch(Variable("GLOBALS"), String_(literal))`, looks up the literal directly in `Resolver::getGlobalScope()->getVariable($key)` (bypassing the `GlobalVarArray::checkMutable()` guard that defeats the existing path), validates the result as a PHP function-identifier string, and returns the name to splice in. No changes to `ResolveValueVisitor`, `Resolver`, or any `ValRef` mutability logic.

**Tech Stack:** PHP 8.0+, `nikic/php-parser` v4. Existing test runner is `php test.php` — it iterates every file in `tests/` and runs each `INPUT`/`OUTPUT` block; pass/fail is printed per case.

**Spec:** `docs/superpowers/specs/2026-04-28-globals-funccall-rename-design.md`

## File map

- **Modify** `src/Reducer/FuncCallReducer.php` — add `Resolver` constructor injection, add private `resolveGlobalsLiteralName()` helper, add local `catch (UnknownValueException)` inside `reduceFunctionCall` that delegates to the helper.
- **Modify** `src/Deobfuscator.php` (line 42) — pass `$resolver` to the `FuncCallReducer` constructor.
- **Create** `tests/globals.txt` — five `INPUT`/`OUTPUT` fixture blocks covering happy path, error-suppress wrapping, validation rejection, unknown global, and mutable-scope bypass.

---

## Task 1: Capture current baseline on the real sample

Before any changes, lock in a "before" number for the smoke test we'll re-run at the end.

**Files:** none modified — read-only measurement.

- [ ] **Step 1: Run the deobfuscator on the motivating sample and count `$GLOBALS["` occurrences in the output.**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator
timeout 60 php -d memory_limit=1G index.php -f /Users/fioa8c/WORK/waffle-makers-tooling/ASTro/samples/bWNAdf.php 2>/dev/null > /tmp/bWNAdf-before.php
grep -c 'GLOBALS\["' /tmp/bWNAdf-before.php
```

Expected: prints a number (currently `142`). Write that number down — you'll compare against it in Task 8.

- [ ] **Step 2: Run the existing test suite and record the baseline.**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator
php -d error_reporting=E_ALL test.php 2>&1 | tail -50
```

Expected: every line is either `Test <name> pass` or a `failed` block. Record the count of passes — the new work must not reduce it.

```bash
php -d error_reporting=E_ALL test.php 2>&1 | grep -c ' pass$'
```

---

## Task 2: Add the failing happy-path fixture (with mutability trigger)

Pure TDD anchor: write the test, watch it fail, then implement.

**Critical context discovered during T2's first attempt:** without a mutability trigger in the INPUT, the `$GLOBALS["literal"]` rewrite already succeeds via the existing path — because nothing has marked the global scope's variables mutable. The whole reason this gap exists in `bWNAdf.php` is that branching constructs (e.g. `if (!function_exists('str_ireplace')) { ... }`) cause `Resolver::setCurrentVarsMutable()` to flip every global's `ScalarValue` to mutable, which makes `Utils::getValue` throw `MutableValueException`. **Every fixture that's supposed to exercise the NEW path must include a mutability trigger.** A simple `if (true) { $dummy = 1; }` between the `$f = 'strrev';` assignment and the closure is sufficient and predictable.

**Files:**
- Create: `tests/globals.txt`

- [ ] **Step 1: Create `tests/globals.txt` with the happy-path block (including mutability trigger).**

Exact contents (no trailing newline matters — match how existing fixtures look):

```
INPUT
$f = 'strrev';
if (true) {
    $dummy = 1;
}
$g = function ($x) {
    return $GLOBALS['f']($x);
};
OUTPUT
$f = 'strrev';
if (true) {
    $dummy = 1;
}
$g = function ($x) {
    return strrev($x);
};
```

- [ ] **Step 2: Run the test suite and confirm the new case fails.**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator
php -d error_reporting=E_ALL test.php 2>&1 | grep -A20 'globals.txt/1'
```

Expected: `Test globals.txt/1 failed:` followed by `Expected:` block with `return strrev($x);` and `Got:` block with `return $GLOBALS['f']($x);`.

If the `Got:` output is structurally different from the `Expected:` in any way *other than* `strrev` vs `$GLOBALS['f']` (e.g. spacing, semicolons, blank lines, `function ($x)` vs `function($x)`), copy the actual `Got:` body verbatim into the fixture's `OUTPUT` section but with `$GLOBALS['f']($x)` replaced by `strrev($x)`. The pretty-printer's exact whitespace is the source of truth — don't fight it.

- [ ] **Step 3: Confirm no other test regressed by adding the fixture.**

```bash
php -d error_reporting=E_ALL test.php 2>&1 | grep -c ' pass$'
```

Expected: same number as the baseline from Task 1, Step 2 (the new fixture failing only adds a `failed` line, doesn't reduce passes elsewhere).

- [ ] **Step 4: Commit (red).**

```bash
git add tests/globals.txt
git commit -m "Add failing fixture for \$GLOBALS[literal](\$x) rewrite"
```

---

## Task 3: Inject `Resolver` into `FuncCallReducer`

Plumbing only — no behaviour change yet. Tests should still pass/fail exactly as they did after Task 2.

**Files:**
- Modify: `src/Reducer/FuncCallReducer.php` (constructor + new private property)
- Modify: `src/Deobfuscator.php:42`

- [ ] **Step 1: Add the constructor and property to `FuncCallReducer`.**

Open `src/Reducer/FuncCallReducer.php`. The current top of the class is:

```php
class FuncCallReducer extends AbstractReducer
{
    private $funcCallMap = array();

    public function addReducer(FuncCallReducer\FunctionReducer $reducer)
```

Replace with:

```php
class FuncCallReducer extends AbstractReducer
{
    private $funcCallMap = array();
    private $resolver;

    public function __construct(\PHPDeobfuscator\Resolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function addReducer(FuncCallReducer\FunctionReducer $reducer)
```

- [ ] **Step 2: Pass `$resolver` to the `FuncCallReducer` at the call site.**

In `src/Deobfuscator.php`, change line 42 from:

```php
        $funcCallReducer = new Reducer\FuncCallReducer();
```

to:

```php
        $funcCallReducer = new Reducer\FuncCallReducer($resolver);
```

(`$resolver` is already in scope — it's declared on line 34, eight lines above.)

- [ ] **Step 3: Run the test suite and confirm no regressions.**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator
php -d error_reporting=E_ALL test.php 2>&1 | grep -c ' pass$'
```

Expected: same pass count as Task 1 Step 2 (the happy-path fixture from Task 2 still fails — that's intentional; we haven't implemented the helper yet).

- [ ] **Step 4: Commit (still red, but plumbed).**

```bash
git add src/Reducer/FuncCallReducer.php src/Deobfuscator.php
git commit -m "Inject Resolver into FuncCallReducer constructor"
```

---

## Task 4: Implement `resolveGlobalsLiteralName` and the local catch

This is the load-bearing change. After this task the happy-path fixture from Task 2 must pass.

**Files:**
- Modify: `src/Reducer/FuncCallReducer.php`

- [ ] **Step 1: Add the helper at the bottom of the class.**

Inside `src/Reducer/FuncCallReducer.php`, just before the final closing `}` of the class, add:

```php

    /**
     * Targeted fallback for the obfuscation pattern $GLOBALS["literal"](...).
     *
     * Bypasses the ValRef mutability check which defeats the normal
     * ResolveValueVisitor path on real-world samples (any branching
     * statement in the global scope flips Resolver::setCurrentVarsMutable
     * and marks every top-level ScalarValue mutable). Only fires for the
     * exact shape $GLOBALS[<string literal>] and only returns a value
     * that looks like a valid PHP function identifier.
     *
     * Closure caveat: the lookup uses whatever value the Resolver had
     * recorded by the time the closure literal was visited, not the value
     * at PHP-runtime call time. Acceptable for deobfuscation readability;
     * obfuscators don't reassign their function-name globals.
     */
    private function resolveGlobalsLiteralName(\PhpParser\Node $expr)
    {
        if (!($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch)) {
            return null;
        }
        $var = $expr->var;
        if (!($var instanceof \PhpParser\Node\Expr\Variable)) {
            return null;
        }
        if (!is_string($var->name) || $var->name !== 'GLOBALS') {
            return null;
        }
        $dim = $expr->dim;
        if (!($dim instanceof \PhpParser\Node\Scalar\String_)) {
            return null;
        }
        $valRef = $this->resolver->getGlobalScope()->getVariable($dim->value);
        if (!($valRef instanceof \PHPDeobfuscator\ValRef\ScalarValue)) {
            return null;
        }
        // Bypass the mutability check intentionally — that's the whole
        // point of this fallback. Toggle isMutable off for the read and
        // restore it after, so we don't perturb the rest of the pipeline.
        $wasMutable = $valRef->isMutable();
        $valRef->setMutable(false);
        try {
            $name = $valRef->getValue();
        } finally {
            $valRef->setMutable($wasMutable);
        }
        if (!is_string($name)) {
            return null;
        }
        if (!preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $name)) {
            return null;
        }
        return $name;
    }
```

- [ ] **Step 2: Add the local `catch` to `reduceFunctionCall`.**

Replace the existing body of `reduceFunctionCall` (currently around lines 26-39 of the file) with:

```php
    public function reduceFunctionCall(Node\Expr\FuncCall $node)
    {
        if ($node->name instanceof Node\Name) {
            $name = $node->name->toString();
        } else {
            try {
                $name = Utils::getValue($node->name);
            } catch (\PHPDeobfuscator\Exceptions\BadValueException $e) {
                $name = $this->resolveGlobalsLiteralName($node->name);
                if ($name === null) {
                    return;
                }
            }
            $nameNode = new Node\Name($name);
            // Special case for MetadataVisitor
            $nameNode->setAttribute('replaces', $node->name);
            $node->name = $nameNode;
        }
        // Normalise to lowercase - function names are case insensitive
        return $this->makeFunctionCall(strtolower($name), $node);
    }
```

Two things to notice vs. the original: (a) the `try`/`catch` is for `BadValueException` (the parent of both `UnknownValueException` and `MutableValueException`) — covering both reasons `Utils::getValue` can fail, since the real-world failure is `MutableValueException` triggered by `setCurrentVarsMutable`; (b) the `$nameNode = ...; $node->name = $nameNode;` block now runs after EITHER path produces `$name`. The previous behaviour of `ReducerVisitor::leaveNode` swallowing leftover `BadValueException`s is preserved automatically: if our new path also returns `null`, we explicitly `return`, which is identical to the propagated-then-swallowed behaviour.

- [ ] **Step 3: Run the failing fixture from Task 2 and confirm it now passes.**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator
php -d error_reporting=E_ALL test.php 2>&1 | grep 'globals.txt/1'
```

Expected: `Test globals.txt/1 pass`.

- [ ] **Step 4: Run the full suite and confirm pass count went UP by exactly 1 from the Task 1 baseline (the previously-failing fixture now passes; nothing else changes).**

```bash
php -d error_reporting=E_ALL test.php 2>&1 | grep -c ' pass$'
```

Compare against the baseline you recorded in Task 1, Step 2.

- [ ] **Step 5: Commit (green).**

```bash
git add src/Reducer/FuncCallReducer.php
git commit -m "Rewrite \$GLOBALS[literal](...) to named function call"
```

---

## Task 5: Add the error-suppress fixture

Verifies that `@$GLOBALS["foo"](...)` keeps the `@` and that the rewrite doesn't accidentally unwrap the `ErrorSuppress`. Mutability trigger included so the new path actually runs (without it, the existing path handles this fine and we'd be testing the wrong code).

**Files:**
- Modify: `tests/globals.txt`

- [ ] **Step 1: Append the second block to `tests/globals.txt`.**

Append (with a blank line separating from the previous `OUTPUT` block):

```
INPUT
$f = 'strrev';
if (true) {
    $dummy = 1;
}
$g = function ($x) {
    return @$GLOBALS['f']($x);
};
OUTPUT
$f = 'strrev';
if (true) {
    $dummy = 1;
}
$g = function ($x) {
    return @strrev($x);
};
```

- [ ] **Step 2: Run only the new case and confirm it passes.**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator
php -d error_reporting=E_ALL test.php 2>&1 | grep 'globals.txt/2'
```

Expected: `Test globals.txt/2 pass`.

If it failed because of pretty-printer whitespace differences, copy the printer's actual output for the rewritten line and adjust the `OUTPUT` block accordingly.

- [ ] **Step 3: Commit.**

```bash
git add tests/globals.txt
git commit -m "Test: \$GLOBALS rewrite preserves error-suppress wrapper"
```

---

## Task 6: Add the validation-rejection fixture

Verifies the identifier regex prevents producing syntactically invalid PHP. The fixture must NOT be rewritten. Mutability trigger included so the new path actually fires and gets a chance to reject.

**Files:**
- Modify: `tests/globals.txt`

- [ ] **Step 1: Append the third block to `tests/globals.txt`.**

```
INPUT
$f = 'echo 1;';
if (true) {
    $dummy = 1;
}
$g = function () {
    $GLOBALS['f']();
};
OUTPUT
$f = 'echo 1;';
if (true) {
    $dummy = 1;
}
$g = function () {
    $GLOBALS['f']();
};
```

- [ ] **Step 2: Run and confirm it passes.**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator
php -d error_reporting=E_ALL test.php 2>&1 | grep 'globals.txt/3'
```

Expected: `Test globals.txt/3 pass`. (The regex rejects `echo 1;` so the call is left untouched, matching the OUTPUT.)

If the run shows an unexpected reduction (e.g. the printer normalised the closure body), capture the actual output and adjust — the *important* invariant is that `$GLOBALS['f']()` stays unrewritten.

- [ ] **Step 3: Commit.**

```bash
git add tests/globals.txt
git commit -m "Test: \$GLOBALS rewrite rejects non-identifier values"
```

---

## Task 7: Add the unknown-global fixture

Verifies that `$GLOBALS["never_assigned"]()` is silently skipped — no crash, no rewrite.

**Files:**
- Modify: `tests/globals.txt`

- [ ] **Step 1: Append the fourth block to `tests/globals.txt`.**

```
INPUT
$g = function () {
    $GLOBALS['never_assigned']();
};
OUTPUT
$g = function () {
    $GLOBALS['never_assigned']();
};
```

- [ ] **Step 2: Run and confirm it passes.**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator
php -d error_reporting=E_ALL test.php 2>&1 | grep 'globals.txt/4'
```

Expected: `Test globals.txt/4 pass`.

- [ ] **Step 3: Commit.**

```bash
git add tests/globals.txt
git commit -m "Test: \$GLOBALS rewrite skips unknown global keys"
```

---

## Task 8: Add the simple-case regression-guard fixture

(Repurposed from the original spec's Test 3 since the canonical mutable-scope case is now T2.) The simple case — `$GLOBALS["literal"](...)` with NO mutability trigger — is handled by the EXISTING path today. We add it as a regression guard to make sure any future change to the new fallback doesn't accidentally break the existing path.

**Files:**
- Modify: `tests/globals.txt`

- [ ] **Step 1: Append the fifth block to `tests/globals.txt`.**

```
INPUT
$f = 'strrev';
$g = function ($x) {
    return $GLOBALS['f']($x);
};
OUTPUT
$f = 'strrev';
$g = function ($x) {
    return strrev($x);
};
```

- [ ] **Step 2: Run and confirm it passes (this should pass *both* before and after the helper exists, since the existing path handles it).**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator/.worktrees/globals-funccall-rename
php -d error_reporting=E_ALL test.php 2>&1 | grep 'globals.txt/5'
```

Expected: `Test globals.txt/5 pass`. If it fails with whitespace mismatch, update the `OUTPUT` block to match the printer's actual output (preserving the `strrev($x)` rewrite).

- [ ] **Step 3: Confirm the full suite still passes (no regressions across all five fixture files).**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator/.worktrees/globals-funccall-rename
php -d error_reporting=E_ALL test.php 2>&1 | grep -c ' pass$'
php -d error_reporting=E_ALL test.php 2>&1 | grep -c failed
```

Expected: pass count = baseline + 5 (one per new fixture). Failed count = 0.

- [ ] **Step 4: Commit.**

```bash
git add tests/globals.txt
git commit -m "Test: regression guard for simple \$GLOBALS[literal] rewrite via existing path"
```

---

## Task 9: Smoke test on `bWNAdf.php` and final commit

Validate the change against the motivating sample and capture before/after metrics in the commit message.

**Files:** none modified — measurement + final amend if needed.

- [ ] **Step 1: Re-run the deobfuscator on the real sample.**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator
timeout 60 php -d memory_limit=1G index.php -f /Users/fioa8c/WORK/waffle-makers-tooling/ASTro/samples/bWNAdf.php 2>/dev/null > /tmp/bWNAdf-after.php
grep -c 'GLOBALS\["' /tmp/bWNAdf-after.php
```

Expected: a number much smaller than the `142` recorded in Task 1 — ideally `0`, possibly a small handful if some sites have unusual shapes (e.g. a global whose value is itself an unresolved expression).

- [ ] **Step 2: Spot-check that legitimate usages survive.**

```bash
diff <(head -50 /tmp/bWNAdf-before.php) <(head -50 /tmp/bWNAdf-after.php)
```

The header (lines 1-43-ish, the scalar variable declarations) should be **identical** between before and after — those are top-level assignments, not `$GLOBALS["..."]` calls. Differences should only appear in the closure bodies further down.

- [ ] **Step 3: If the after-count is non-zero, sample the surviving sites.**

```bash
grep -nE 'GLOBALS\[' /tmp/bWNAdf-after.php | head -5
```

Look at each one. If they're shapes the spec explicitly excluded (e.g. `$GLOBALS[$variable]` with a non-literal key, or a global whose value is non-string) — that's expected and acceptable. If they're vanilla `$GLOBALS["literal"](...)` shapes that should have been rewritten, that's a bug; investigate before declaring done.

- [ ] **Step 4: Amend the implementation commit (Task 4) with the before/after numbers.**

The commit from Task 4 currently has the message `Rewrite $GLOBALS[literal](...) to named function call`. We want to add the empirical impact. Since several commits have happened since, do a regular new commit with the smoke-test numbers rather than rewriting history:

```bash
git commit --allow-empty -m "$(cat <<'EOF'
Note: \$GLOBALS rewrite reduces unresolved sites in bWNAdf.php

Before: <COUNT_FROM_TASK_1>
After:  <COUNT_FROM_TASK_9_STEP_1>

(Sample: /Users/fioa8c/WORK/waffle-makers-tooling/ASTro/samples/bWNAdf.php)
EOF
)"
```

Substitute the actual numbers before running. If you'd prefer to fold the metrics into the Task 4 commit instead of an empty commit, do an interactive rebase — but a separate annotation commit is simpler and avoids mid-stream history rewrites.

- [ ] **Step 5: Final verification — full suite and `git log`.**

```bash
cd /Users/fioa8c/WORK/PHPDeobfuscator
php -d error_reporting=E_ALL test.php 2>&1 | grep -E 'pass$|failed' | sort | uniq -c
git log --oneline -10
```

Expected: zero `failed` lines; log shows the eight commits from this plan in order on top of `cdbd2f4` (the spec commit). Done.
