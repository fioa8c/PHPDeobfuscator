# Closure-registry Pre-pass Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Populate the closure registry from a separate fully-completed traversal in `firstPass`, so call sites inside closures defined textually before the closures they reference can fold correctly.

**Architecture:** New `ClosureRegistryPrepass` visitor (`src/ClosureRegistryPrepass.php`) added to `firstPass`, alongside the existing `ControlFlowVisitor`. It walks the entire AST tracking nesting depth and calls the existing `Resolver::registerGlobalClosure(name, closure)` for top-level closure assignments matching the same gates the current `Resolver::tryRegisterGlobalClosure` enforces. Once the prepass is in place, `Resolver::tryRegisterGlobalClosure` and its `onAssign` invocation are removed — the prepass is the single source of truth for the registry.

**Tech Stack:** PHP 8.0+, `nikic/php-parser` v4. Test runner is `php test.php` — iterates `tests/*.txt`, prints `pass`/`failed` per `INPUT`/`OUTPUT` block, no fail-stop.

**Spec:** `docs/superpowers/specs/2026-04-29-globals-closure-prepass-design.md`

## File map

- **Create** `src/ClosureRegistryPrepass.php` — new `NodeVisitorAbstract` that owns the prepass walk. Tracks nesting via `$nestingDepth`, registers eligible top-level closures into `Resolver`. ~70 lines.
- **Modify** `src/Deobfuscator.php` — wire the new visitor into `firstPass` after constructing `$resolver`.
- **Modify** `src/Resolver.php` — remove `tryRegisterGlobalClosure()` and the one-line call from `onAssign()`. Both are obsoleted by the prepass.
- **Modify** `tests/globals-closure-eval.txt` — append two new fixtures (forward-reference happy path; forward-referenced default-param closure stays skipped).

---

### Task 1: Add the failing forward-reference fixture

Lock in the target behavior before any implementation — the suite will report this case as failed until Task 3 lands.

**Files:**
- Modify: `tests/globals-closure-eval.txt` (append a new INPUT/OUTPUT block)

- [ ] **Step 1: Append the fixture.**

Open `tests/globals-closure-eval.txt`. The file currently ends with the recursion-cap fixture (case 9). Append (with one blank line separating from the previous block):

```

INPUT
$a = function () {
    return $GLOBALS['b']('hello');
};
$b = function ($x) {
    return strrev($x);
};

OUTPUT
$a = function () {
    return "olleh";
};
$b = function ($x) {
    return strrev($x);
};
```

The closure `$a` references `$GLOBALS['b']` in its body, but `$b` is defined textually after `$a`. With the current registry mechanism (Resolver's `onAssign`), `$b` is not registered when `$a`'s body is visited, so the inner call cannot fold. After the prepass lands, `$b` is registered before any closure body is visited, the inner call folds via the existing closure-call evaluator, and `$a`'s body becomes `return "olleh";`. (`strrev('hello')` = `'olleh'`.)

- [ ] **Step 2: Run the suite, confirm the new case fails.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`

Expected: `Test globals-closure-eval.txt/10 failed:` with `Got:` showing `return $GLOBALS['b']('hello');` (the inner call wasn't folded). Cases 1–9 still `pass`.

- [ ] **Step 3: Confirm no other fixture regressed.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep "failed" | grep -v "globals-closure-eval"`

Expected: empty output.

- [ ] **Step 4: Commit.**

```bash
git add tests/globals-closure-eval.txt
git commit -m "Add failing fixture: forward-referenced closure inside another closure body

Locks in the target behavior before implementation. Closure \$a references
\$GLOBALS['b'] in its body; \$b is defined textually after \$a. With the
current Resolver onAssign-based registration, \$b is not in the registry
when \$a's body is visited, so the inner call doesn't fold. The upcoming
prepass populates the registry before secondPass starts."
```

---

### Task 2: Create the `ClosureRegistryPrepass` visitor

Standalone visitor file — no wiring yet. The failing fixture from Task 1 stays red until Task 3.

**Files:**
- Create: `src/ClosureRegistryPrepass.php`

- [ ] **Step 1: Create the file.**

Write `src/ClosureRegistryPrepass.php` with exactly:

```php
<?php

namespace PHPDeobfuscator;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Pre-pass visitor that harvests every top-level closure assignment into
 * Resolver's global-closure registry. Runs as part of firstPass so the
 * registry is fully populated before secondPass starts visiting call sites.
 *
 * Without this prepass, FuncCall sites inside closure bodies cannot fold
 * when the closure they reference is declared textually later — Resolver's
 * onAssign-based registration only fires at the leaveNode of the later
 * assignment, by which time the depth-first walk has already visited and
 * skipped the call.
 *
 * Gate predicate matches the previous Resolver::tryRegisterGlobalClosure:
 *   - Top-level (not inside a function/method/closure body).
 *   - LHS is $name or $GLOBALS["literal"].
 *   - RHS is Expr\Closure with no use(), no by-ref params, no default-valued
 *     params, no variadic params.
 */
class ClosureRegistryPrepass extends NodeVisitorAbstract
{
    private Resolver $resolver;
    private int $nestingDepth = 0;

    public function __construct(Resolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Expr\Assign && $this->nestingDepth === 0) {
            $this->tryRegister($node);
        }
        if ($this->changesScope($node)) {
            $this->nestingDepth++;
        }
        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($this->changesScope($node)) {
            $this->nestingDepth--;
        }
        return null;
    }

    private function changesScope(Node $node): bool
    {
        return $node instanceof Expr\Closure
            || $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod;
    }

    private function tryRegister(Expr\Assign $assign): void
    {
        $rhs = $assign->expr;
        if (!($rhs instanceof Expr\Closure)) {
            return;
        }
        if ($rhs->uses !== []) {
            return;
        }
        foreach ($rhs->params as $param) {
            if ($param->byRef || $param->default !== null || $param->variadic) {
                return;
            }
        }

        $lhs = $assign->var;
        $name = null;
        if ($lhs instanceof Expr\Variable && is_string($lhs->name)) {
            $name = $lhs->name;
        } elseif ($lhs instanceof Expr\ArrayDimFetch
            && $lhs->var instanceof Expr\Variable
            && $lhs->var->name === 'GLOBALS'
            && $lhs->dim instanceof Node\Scalar\String_
        ) {
            $name = $lhs->dim->value;
        }
        if ($name === null) {
            return;
        }

        $this->resolver->registerGlobalClosure($name, $rhs);
    }
}
```

- [ ] **Step 2: Confirm the file parses.**

Run: `php -l src/ClosureRegistryPrepass.php`

Expected: `No syntax errors detected in src/ClosureRegistryPrepass.php`

- [ ] **Step 3: Confirm the suite is unchanged.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep failed | grep -v "globals-closure-eval"`

Expected: empty output. (The Task 1 fixture is still failing — that's expected. No other fixtures should have changed since the new file isn't wired up.)

- [ ] **Step 4: Commit.**

```bash
git add src/ClosureRegistryPrepass.php
git commit -m "Add ClosureRegistryPrepass visitor (no wiring yet)

Standalone visitor that walks the AST tracking nesting depth and
registers eligible top-level closures into Resolver's existing
registry via the public registerGlobalClosure API.

Gates match the existing Resolver::tryRegisterGlobalClosure exactly:
no use(), no by-ref/default/variadic params, LHS is \$name or
\$GLOBALS[literal]. Wiring lands in the next commit."
```

---

### Task 3: Wire the prepass into `firstPass`

Add the visitor to `firstPass` so it runs to completion before `secondPass` begins.

**Files:**
- Modify: `src/Deobfuscator.php`

- [ ] **Step 1: Wire the visitor.**

In `src/Deobfuscator.php`, locate the constructor body. The current block (lines 26–36) reads:

```php
        $this->firstPass = new \PhpParser\NodeTraverser;
        $this->secondPass = new \PhpParser\NodeTraverser;

        $this->firstPass->addVisitor(new ControlFlowVisitor());

        if ($dumpOrig) {
            $this->secondPass->addVisitor(new AddOriginalVisitor($this));
        }
        $resolver = new Resolver();
        $this->secondPass->addVisitor($resolver);
        $this->secondPass->addVisitor(new ResolveValueVisitor($resolver));
```

The `Resolver` is currently constructed *after* `firstPass->addVisitor(new ControlFlowVisitor())`, but the new prepass needs `Resolver` for its constructor. Reorder so `$resolver` is constructed before the firstPass visitor list is built:

```php
        $this->firstPass = new \PhpParser\NodeTraverser;
        $this->secondPass = new \PhpParser\NodeTraverser;

        $resolver = new Resolver();

        $this->firstPass->addVisitor(new ControlFlowVisitor());
        $this->firstPass->addVisitor(new ClosureRegistryPrepass($resolver));

        if ($dumpOrig) {
            $this->secondPass->addVisitor(new AddOriginalVisitor($this));
        }
        $this->secondPass->addVisitor($resolver);
        $this->secondPass->addVisitor(new ResolveValueVisitor($resolver));
```

The `$resolver` declaration line is just moved up; the assignment to `$this->secondPass` later still uses the same `$resolver` variable.

- [ ] **Step 2: Run the suite, confirm Task 1's fixture now passes.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`

Expected: `Test globals-closure-eval.txt/10 pass` (the previously-failing forward-reference case). All other `globals-closure-eval.txt/N pass`. No `failed` lines anywhere in the suite.

- [ ] **Step 3: Commit.**

```bash
git add src/Deobfuscator.php
git commit -m "Wire ClosureRegistryPrepass into firstPass

The prepass now runs to completion before secondPass starts. Top-level
closures are visible in the registry by the time any closure body's
call site is visited, fixing the forward-reference bug from the
previous \$GLOBALS-closure-eval feature.

Resolver's onAssign-based registration becomes redundant after this;
removed in the next commit."
```

---

### Task 4: Remove `Resolver::tryRegisterGlobalClosure`

Single source of truth: the prepass. Delete the now-redundant `onAssign`-driven registration.

**Files:**
- Modify: `src/Resolver.php`

- [ ] **Step 1: Remove the helper method.**

In `src/Resolver.php`, delete the entire `tryRegisterGlobalClosure` method (currently lines 278–320):

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
            if ($param->variadic) {
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

- [ ] **Step 2: Remove its call from `onAssign`.**

Locate `onAssign` (around line 408 after the deletion in step 1). Current:

```php
    private function onAssign(Expr\Assign $expr)
    {
        $this->tryRegisterGlobalClosure($expr);
        $varRef = $this->resolveVariable($expr->var);
        $valRef = $this->resolveValue($expr->expr);
        $this->assign($varRef, $valRef);
    }
```

Remove the first line:

```php
    private function onAssign(Expr\Assign $expr)
    {
        $varRef = $this->resolveVariable($expr->var);
        $valRef = $this->resolveValue($expr->expr);
        $this->assign($varRef, $valRef);
    }
```

- [ ] **Step 3: Run the suite, confirm no regressions.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep failed`

Expected: empty output. (All fixtures still pass — the prepass is now solely responsible for the registry, and it produces the same result as the old onAssign path for non-forward-reference cases, plus correctly handles forward references.)

- [ ] **Step 4: Commit.**

```bash
git add src/Resolver.php
git commit -m "Remove Resolver::tryRegisterGlobalClosure (obsoleted by prepass)

The prepass in firstPass is now the single source of truth for the
closure registry. The onAssign-based registration was a side-channel
that fired during depth-first traversal — too late for forward
references, redundant otherwise. ~45 lines deleted."
```

---

### Task 5: Add the second fixture — forward-referenced default-param closure stays skipped

Regression guard: the prepass respects the existing skip gates (no-default, no-byRef, no-variadic). A closure with a default-valued param should not be registered, even when it's forward-referenced.

**Files:**
- Modify: `tests/globals-closure-eval.txt`

- [ ] **Step 1: Append the fixture.**

Append (with one blank line separating from the previous block):

```

INPUT
$a = function () {
    return $GLOBALS['b']('hello');
};
$b = function ($x = 'default') {
    return $x;
};

OUTPUT
$a = function () {
    return $GLOBALS['b']('hello');
};
$b = function ($x = 'default') {
    return $x;
};
```

`$b` has a default-valued parameter, which the prepass's gate predicate excludes from registration. So when `$a`'s body is visited and the inner `$GLOBALS['b']('hello')` call is processed, `getGlobalClosure("b")` returns null and the call stays. Output is the input verbatim.

- [ ] **Step 2: Run the suite, confirm the case passes.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`

Expected: cases 1–11 all `pass`. No `failed` lines.

- [ ] **Step 3: Commit.**

```bash
git add tests/globals-closure-eval.txt
git commit -m "Test: forward-referenced default-param closure stays skipped

Regression guard for the prepass's gate predicate. A closure with a
default-valued param is not registered, so a forward reference to it
from inside another closure's body does not fold. Locks in the
behaviour against accidental gate relaxation."
```

---

### Task 6: Smoke check on the real sample

Confirm the feature has the intended effect on `samples/next_DOLOLO.php`. Numbers go in the merge-commit / PR description.

**Files:**
- None modified. Run-only.

- [ ] **Step 1: Generate post-prepass output.**

Run: `php index.php -f samples/next_DOLOLO.php > /tmp/post.out 2>&1`

- [ ] **Step 2: Generate pre-prepass output.**

Temporarily revert the source files to master state, regenerate, then restore. Use file-level checkout (no destructive history operations):

```bash
cp src/Reducer/FuncCallReducer.php /tmp/_fcr.php
cp src/Resolver.php /tmp/_res.php
cp src/Deobfuscator.php /tmp/_deob.php
git checkout master -- src/Reducer/FuncCallReducer.php src/Resolver.php src/Deobfuscator.php 2>/dev/null || true
# Also remove the new prepass file if it leaks into the run
mv src/ClosureRegistryPrepass.php /tmp/_prepass.php 2>/dev/null || true
php index.php -f samples/next_DOLOLO.php > /tmp/pre.out 2>&1
# Restore
cp /tmp/_fcr.php src/Reducer/FuncCallReducer.php
cp /tmp/_res.php src/Resolver.php
cp /tmp/_deob.php src/Deobfuscator.php
mv /tmp/_prepass.php src/ClosureRegistryPrepass.php 2>/dev/null || true
```

- [ ] **Step 3: Compare counts.**

Run:

```bash
echo "before: $(grep -c '\$GLOBALS\["' /tmp/pre.out)"
echo "after:  $(grep -c '\$GLOBALS\["' /tmp/post.out)"
diff /tmp/pre.out /tmp/post.out | grep -E "^>.*=" | head -10
```

Expected: "before" count is 18 (the master baseline from the previous PR's smoke check). "after" count is 15 — the three previously-unresolved sites (`$D_LLO_O_OL`, the two on `$DOLLL__O_O`) now fold. The diff sample should show three `>` lines with scalar string replacements (specifically `'\\\\?/|&()[]{}+^$!:*'`, `'googlebot|baiduspider|...'`, and `'|Mozilla'`).

- [ ] **Step 4: Final regression sweep.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep failed`

Expected: empty output. All eleven fixtures in `tests/globals-closure-eval.txt` plus all pre-existing fixtures pass.

- [ ] **Step 5: Confirm working tree is clean.**

Run: `git status`

Expected: only `samples/` listed as untracked (not part of this work). No modified/staged files — Step 2's revert/restore left the working tree at the post-prepass state.

---

## Done criteria

- All eleven fixtures in `tests/globals-closure-eval.txt` pass (nine existing + two new).
- `tests/reducers.txt`, `tests/variables.txt`, `tests/goto-tests.txt`, `tests/filesystem.txt`, `tests/globals.txt` continue to pass.
- `samples/next_DOLOLO.php` produces strictly fewer `$GLOBALS["` occurrences than master baseline (target: 18 → 15, dropping the three forward-reference sites).
- Branch `globals-closure-prepass` contains the spec commit plus this plan commit plus 5 commits from Tasks 1–5 (Task 6 is verification-only).
