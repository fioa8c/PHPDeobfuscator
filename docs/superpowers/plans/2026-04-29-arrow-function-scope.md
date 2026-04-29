# Arrow Function Scope Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recognise `Expr\ArrowFunction` as a scope-changing node in both `Resolver::changesScope` and `ClosureRegistryPrepass::changesScope`, fixing two latent scope-correctness bugs that today are dormant only because no obfuscator sample uses arrow functions.

**Architecture:** Two single-line additions plus two regression fixtures. Each fixture is structured so that adding `Expr\ArrowFunction` to exactly one of the two `changesScope` predicates flips that fixture from failing to passing — fixture A exercises only the prepass change, fixture B exercises only the Resolver change. This keeps each predicate's effect independently verifiable.

**Tech Stack:** PHP 8.0+, `nikic/php-parser` v4. Test runner is `php test.php` — iterates `tests/*.txt`, prints `pass`/`failed` per `INPUT`/`OUTPUT` block, no fail-stop.

**Spec:** `docs/superpowers/specs/2026-04-29-arrow-function-scope-design.md`

## File map

- **Modify** `src/ClosureRegistryPrepass.php` — add `Expr\ArrowFunction` to `changesScope` predicate (one line).
- **Modify** `src/Resolver.php` — add `Expr\ArrowFunction` to `changesScope` predicate (one line).
- **Modify** `tests/globals-closure-eval.txt` — append two new INPUT/OUTPUT blocks (Fixture A and Fixture B from the spec).

---

### Task 1: Add the failing prepass-side fixture (Fixture A)

Lock in the target behavior for the `ClosureRegistryPrepass::changesScope` change before touching the predicate.

**Files:**
- Modify: `tests/globals-closure-eval.txt` (append a new INPUT/OUTPUT block — case 12)

- [ ] **Step 1: Append the fixture.**

The file currently ends with case 11 (the default-param skip fixture from PR #2). Append (with one blank line separating from the previous block):

```

INPUT
$a = fn() => $b = function () {
    return $GLOBALS['c']('hello');
};
$c = function ($x) {
    return strrev($x);
};
$out = $GLOBALS['b']();

OUTPUT
$a = fn() => $b = function () {
    return "olleh";
};
$c = function ($x) {
    return strrev($x);
};
$out = $GLOBALS['b']();
```

The arrow function body contains `$b = function () { ... }`. Today the prepass treats the arrow function as scope-transparent, walks into its body at `nestingDepth === 0`, and registers `$b` as a global closure. Then `$out = $GLOBALS['b']()` finds `b` in the registry and folds the outer call. After Task 2's prepass fix, the prepass enters arrow function scope (depth 1) and skips the inner `$b = ...` registration, so `$out = $GLOBALS['b']()` cannot fold and stays verbatim. The expected OUTPUT above is the post-fix state.

The closure body inside `$b` still folds (`$GLOBALS['c']('hello')` → `"olleh"`) because `$c` IS registered — this is unrelated to the scope fix.

- [ ] **Step 2: Run the suite, confirm the new case fails.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`

Expected: `Test globals-closure-eval.txt/12 failed:` with `Got:` showing `$out = "olleh";` (the bug — `$b` is incorrectly registered, so the outer call resolves). Cases 1–11 still `pass`.

- [ ] **Step 3: Confirm no other fixture regressed.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep "failed" | grep -v "globals-closure-eval"`

Expected: empty output.

- [ ] **Step 4: Commit.**

```bash
git add tests/globals-closure-eval.txt
git commit -m "Add failing fixture: closure inside arrow function body leaks into registry

Locks in the target behaviour for the upcoming
ClosureRegistryPrepass::changesScope fix. Today the prepass walks into
arrow function bodies as if they were top-level, registering inner
closure assignments as global. After the fix, arrow functions push a
nesting-depth boundary and the inner registration is correctly skipped.
The fixture's \$out = \$GLOBALS['b']() outside the arrow function will
fold to \"olleh\" today (bug) and stay verbatim after the fix."
```

---

### Task 2: Add `ArrowFunction` to `ClosureRegistryPrepass::changesScope`

One-line addition. Turns Task 1's fixture green.

**Files:**
- Modify: `src/ClosureRegistryPrepass.php`

- [ ] **Step 1: Add `Expr\ArrowFunction` to the predicate.**

Locate `changesScope` (around lines 59–64). Current:

```php
    private function changesScope(Node $node): bool
    {
        return $node instanceof Expr\Closure
            || $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod;
    }
```

Replace with:

```php
    private function changesScope(Node $node): bool
    {
        return $node instanceof Expr\Closure
            || $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\ArrowFunction;
    }
```

- [ ] **Step 2: Run the suite, confirm Task 1's fixture now passes.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`

Expected: `Test globals-closure-eval.txt/12 pass`. All other cases also `pass`. No `failed` lines.

- [ ] **Step 3: Commit.**

```bash
git add src/ClosureRegistryPrepass.php
git commit -m "ClosureRegistryPrepass: treat ArrowFunction as scope-changing

Arrow functions previously were scope-transparent in the prepass,
allowing top-level-shaped assignments inside their bodies to register
into the global closure registry. Adding Expr\\ArrowFunction to the
nesting-depth predicate fixes that.

ControlFlowVisitor doesn't need a matching change — arrow function
bodies are a single Expr (no stmts), so they cannot contain goto
constructs and the goto rewriter has nothing to do inside them."
```

---

### Task 3: Add the failing Resolver-side fixture (Fixture B)

Lock in the target behavior for the `Resolver::changesScope` change. This fixture must NOT be sensitive to Task 2's change — it should still fail after Task 2 and only flip to passing in Task 4.

**Files:**
- Modify: `tests/globals-closure-eval.txt` (append a new INPUT/OUTPUT block — case 13)

- [ ] **Step 1: Append the fixture.**

Append (with one blank line separating from the previous block):

```

INPUT
$x = 'outer';
$f = fn($x) => $x . 'suffix';

OUTPUT
$x = 'outer';
$f = fn($x) => $x . 'suffix';
```

Today the Resolver treats arrow functions as scope-transparent, so when the body's `$x . 'suffix'` is evaluated, `$x` resolves to the outer `'outer'` and the concat folds to `"outersuffix"`. The arrow function's param `$x` is supposed to shadow the outer in real PHP, but since the Resolver doesn't push a fresh scope, the shadow has no effect. After Task 4's Resolver fix, the body runs in a fresh empty scope where `$x` is unknown, the concat does not fold, and the body stays as `$x . 'suffix'`. The expected OUTPUT above is the post-fix state.

- [ ] **Step 2: Run the suite, confirm the new case fails (case 12 still passes).**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`

Expected: `Test globals-closure-eval.txt/13 failed:` with `Got:` showing `$f = fn($x) => "outersuffix";` (the bug — outer `$x` resolved through the arrow function). Cases 1–12 still `pass`.

- [ ] **Step 3: Confirm no other fixture regressed.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep "failed" | grep -v "globals-closure-eval"`

Expected: empty output.

- [ ] **Step 4: Commit.**

```bash
git add tests/globals-closure-eval.txt
git commit -m "Add failing fixture: arrow function param shadowing leaks scope

Locks in the target behaviour for the upcoming Resolver::changesScope
fix. Today the Resolver treats arrow functions as scope-transparent,
so the body's \$x resolves to the outer scope's \$x — incorrect, since
arrow function params shadow outer vars in real PHP. After the fix,
the body runs in a fresh scope where \$x is unknown and the concat
stays unfolded."
```

---

### Task 4: Add `ArrowFunction` to `Resolver::changesScope`

One-line addition. Turns Task 3's fixture green.

**Files:**
- Modify: `src/Resolver.php`

- [ ] **Step 1: Add `Expr\ArrowFunction` to the predicate.**

Locate `changesScope` (around lines 169–174). Current:

```php
    private function changesScope(Node $node)
    {
        return $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure;
    }
```

Replace with:

```php
    private function changesScope(Node $node)
    {
        return $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction;
    }
```

Note: `Resolver::nameForScope` (lines 176–185) falls through to `$node->getType()` for `ArrowFunction` (returns the string `"Expr_ArrowFunction"`). That string is used only for debug paths; no functional dependency. No additional change needed.

- [ ] **Step 2: Run the suite, confirm Task 3's fixture now passes.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep -E "globals-closure-eval|failed"`

Expected: `Test globals-closure-eval.txt/13 pass`. All cases 1–13 `pass`. No `failed` lines anywhere in the suite.

- [ ] **Step 3: Commit.**

```bash
git add src/Resolver.php
git commit -m "Resolver: treat ArrowFunction as scope-changing

Arrow functions previously were scope-transparent in the Resolver,
letting their params leak into the enclosing scope and letting the
enclosing scope's vars override their params during reduction. Adding
Expr\\ArrowFunction to changesScope pushes a fresh scope on entry, so
params are correctly local.

Auto-capture by value is intentionally not modelled — arrow function
bodies that reference outer scalar vars will see those vars as unknown.
This is strictly less-aggressive deobfuscation, never incorrect output.
Documented as out-of-scope in the spec; obfuscator samples don't use
arrow functions, so the user-visible cost today is zero."
```

---

### Task 5: Smoke check + final regression sweep

Confirm the obfuscator sample's output is unchanged (arrow functions don't appear in obfuscator-generated PHP, so the smoke baseline should hold).

**Files:**
- None modified. Run-only.

- [ ] **Step 1: Generate post-fix output and compare to post-PR-#2 baseline.**

Run:

```bash
php index.php -f samples/next_DOLOLO.php > /tmp/post.out 2>&1
echo "after-fix \$GLOBALS[\" count: $(grep -c '\$GLOBALS\["' /tmp/post.out)"
```

Expected: count is `15`, matching the post-PR-#2 baseline. Anything other than 15 means this change had an unintended effect on the sample (arrow functions don't appear there, so any change indicates a regression).

- [ ] **Step 2: Final full-suite regression sweep.**

Run: `php -d error_reporting=E_ALL test.php 2>&1 | grep failed`

Expected: empty output. All 13 fixtures in `tests/globals-closure-eval.txt` plus all pre-existing fixtures in `tests/{reducers,variables,goto-tests,filesystem,globals}.txt` pass.

- [ ] **Step 3: Confirm working tree is clean.**

Run: `git status`

Expected: `samples/` and possibly `.htaccess` listed as untracked (not part of this work). No modified or staged files.

---

## Done criteria

- All 13 fixtures in `tests/globals-closure-eval.txt` pass (11 pre-existing + 2 new).
- All pre-existing fixtures in other `tests/*.txt` files continue to pass.
- `samples/next_DOLOLO.php` produces exactly 15 `$GLOBALS["` occurrences (unchanged from post-PR-#2 baseline).
- Branch `arrow-function-scope` contains the spec commits plus this plan commit plus 4 task commits (Task 5 is verification-only).
