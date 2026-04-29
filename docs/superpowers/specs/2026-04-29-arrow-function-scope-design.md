# Arrow function scope tracking — design

**Date:** 2026-04-29
**Builds on:** PR #2 (`globals-closure-prepass`)

## Problem

`Resolver::changesScope` and `ClosureRegistryPrepass::changesScope` recognise `Stmt\Function_`, `Stmt\ClassMethod`, and `Expr\Closure` as nodes that introduce a new variable scope. They do not recognise `Expr\ArrowFunction` (PHP 7.4+ `fn() => ...`).

Today this produces two scope-correctness bugs that are dormant only because no current obfuscator sample uses arrow functions:

1. **`Resolver` treats arrow functions as scope-transparent.** Params declared in `fn ($x) => ...` land in whatever scope is currently active, where they can shadow or be confused with outer vars of the same name. After the arrow function ends, the params remain in scope.
2. **`ClosureRegistryPrepass` walks into arrow function bodies as if they were top-level.** A construct like `fn() => $b = function () { ... };` would cause the inner closure to be registered as a global closure even though it only exists in the arrow function's scope.

Both are observed in unit-trace inspection but unobserved in any current obfuscator sample.

## Goal

Treat `Expr\ArrowFunction` as a scope-changing node in both `Resolver::changesScope` and `ClosureRegistryPrepass::changesScope`. Inside an arrow function:

- Params are scoped to the arrow function and do not leak into the enclosing scope.
- Top-level-shaped assignments inside the body do not register into the global closure registry.

Out of scope (deferred until a sample motivates it):

- **Auto-capture by value.** PHP arrow functions implicitly read all referenced outer variables by value. After this change, those outer variables become invisible inside arrow function bodies (the new scope is empty apart from params). Net effect: any reduction inside an arrow function body that depends on outer vars will fail to fold. This is strictly less-aggressive deobfuscation — never produces incorrect output — and the obfuscator samples don't use arrow functions, so the user-visible cost is zero today.
- **`ControlFlowVisitor` updates.** Arrow function bodies are a single `Expr` (no `stmts`), so they cannot contain `goto` / `label` constructs. The goto rewriter has nothing to do inside them. No change to `nestedTypes`.
- **`Expr\ArrowFunction`-valued globals as closure-call targets.** `FuncCallReducer::resolveGlobalsLiteralClosureCall` recognises only `Expr\Closure`. Adding arrow function support there is its own feature.
- **`nameForScope` cleanup.** `Resolver::nameForScope` falls through to `$node->getType()` (returns `"Expr_ArrowFunction"`) for unrecognised scope-changing node types. That string is used only for debugging; no functional dependency.

## Approach

Two single-line additions plus a regression fixture.

### `src/Resolver.php`

In `changesScope` (currently lines 169–174), add the `ArrowFunction` instance check:

```php
private function changesScope(Node $node)
{
    return $node instanceof Stmt\Function_
        || $node instanceof Stmt\ClassMethod
        || $node instanceof Expr\Closure
        || $node instanceof Expr\ArrowFunction;
}
```

The existing `enterNode` logic at line 60 — `if ($node instanceof Expr\Closure) { foreach ($node->uses as $use) ... }` — runs only for `Expr\Closure` and is responsible for explicit `use ($var)` capture. Arrow functions have no `uses` array (they auto-capture), so this block does not run for them. The new arrow function scope is left empty apart from params bound during child traversal. That is the documented limitation in the "Out of scope" section above.

`nameForScope` falls through to `$node->getType()` for `ArrowFunction`, returning the string `"Expr_ArrowFunction"`. Acceptable — used only in debug paths.

### `src/ClosureRegistryPrepass.php`

In `changesScope` (currently lines 59–64), add the same check:

```php
private function changesScope(Node $node): bool
{
    return $node instanceof Expr\Closure
        || $node instanceof Stmt\Function_
        || $node instanceof Stmt\ClassMethod
        || $node instanceof Expr\ArrowFunction;
}
```

After this change, any `Expr\Assign` reached while traversing inside an arrow function body has `nestingDepth > 0` and is correctly skipped at registration time.

## Walkthrough

For a hostile-shaped input:

```php
$a = fn() => $b = function () {
    return $GLOBALS['c']('hello');
};
$c = function ($x) {
    return strrev($x);
};
```

**Before this change** (current master):

- `ClosureRegistryPrepass` traversal:
  - `enterNode` for `$a = fn(...) => ...` (`Expr\Assign`): nestingDepth = 0; tryRegister; RHS is `Expr\ArrowFunction` (not `Expr\Closure`); gate fails; skip.
  - `enterNode` for `Expr\ArrowFunction`: not in `changesScope`; nestingDepth stays at 0.
  - `enterNode` for `$b = function () {...}` (`Expr\Assign`, the inner assign that is the arrow function's body expression): nestingDepth = 0; tryRegister; RHS is a `Closure`; gate passes; register `b` → `$b`'s closure node. **Bug: $b is now a global closure even though it textually exists only inside `$a`'s body.**
  - `enterNode` for inner `Expr\Closure`: in `changesScope`; nestingDepth = 1.
  - Inside: closure body has no top-level Assign, nothing else registers.
  - `leaveNode` chain pops nestingDepth back.
  - `enterNode` for `$c = function (...) ...`: nestingDepth = 0; gate passes; register `c`.
- secondPass then visits `$a`'s arrow function body. `ResolveValueVisitor` / `ReducerVisitor` walk it. `$b = function () { ... }` is processed. Its body `return $GLOBALS['c']('hello');` is reduced through the closure-call evaluator: `c` is in the registry, so the call folds to `"olleh"`. So `$b`'s body becomes `return "olleh";`.

**After this change:**

- `ClosureRegistryPrepass` traversal:
  - `enterNode` for `$a = fn(...) => ...`: gate fails (RHS is `ArrowFunction`); skip.
  - `enterNode` for `Expr\ArrowFunction`: now in `changesScope`; nestingDepth = 1.
  - `enterNode` for `$b = function () {...}` inside the arrow function body: nestingDepth = 1; tryRegister early-returns. **`b` is not registered.** ✓
  - leaves nesting back to 0; visits `$c = ...`; registers `c`.
- secondPass: when `$b = function () { return $GLOBALS['c']('hello'); }` is processed, the inner call's resolver looks up `b` in the registry — wait, the inner call references `c`, not `b`. Same as before: `c` is registered, the inner call folds to `"olleh"`. So `$b`'s body becomes `return "olleh";`.

The user-visible OUTPUT is identical for this hostile input — the bug `b` was being incorrectly registered, but no other call site referenced `$GLOBALS['b']` so nothing observable changes. The fix is preventive.

To exercise the fix observably, the fixture below uses `$GLOBALS['b']` from outside the arrow function — which today would incorrectly resolve, and after this change correctly stays unresolved.

## Edge cases & safety

- **Arrow function with a non-Assign body expression (the common case).** Bodies like `fn ($x) => $x + 1` contain no top-level Assign. The prepass changes nothing observable. Resolver's new scope just keeps $x scoped to the arrow function (correct).
- **Nested arrow functions: `fn ($x) => fn ($y) => $x + $y`.** Each ArrowFunction increments and decrements nestingDepth correctly. Resolver pushes nested scopes; params are correctly local to each arrow scope.
- **Arrow function with `use ($outer)`-shaped body — N/A.** PHP rejects this at parse time; arrow functions don't allow `use` clauses.
- **Auto-capture loss.** Arrow function bodies that reference outer scalar vars will no longer see those values. Example: `$x = 5; $f = fn() => $x + 1;` previously would have folded $x to 5 inside the body (because scope was transparent); now $x is unknown inside the body and the addition stays unfolded. No incorrect output is produced — only fewer reductions happen. Documented in "Out of scope".
- **Previous behavior was already broken** for the symmetric case — params leaking out. Example: `function outer($x) { $f = fn ($x) => $x; ... use $x here ... }` — previously, `$x` in the arrow function would land in `outer`'s scope and confuse value tracking. Now correctly scoped.

## Testing

One new fixture appended to `tests/globals-closure-eval.txt`. Two test cases would each lock in one half of the scope semantics:

1. **Inner closure assignment inside an arrow function body is not globally registered.**

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

   Verified on master: this same input produces `$out = "olleh";` today (the prepass incorrectly registers `$b` as a global because the arrow function is treated as scope-transparent, so `$out = $GLOBALS['b']()` from outside the arrow function evaluates the inner closure via the registry hit). After this change, `$b` is not registered, the outer call cannot fold, and the OUTPUT matches what is shown above.

   `$out = $GLOBALS['b']();` from the global scope **should not fold** after this change — `$b` is no longer registered as a global closure (it lives only inside `$a`'s arrow function body). On master, this same input would erroneously fold `$out` to `"olleh"`. The fixture catches the bug fix.

   The closure body inside `$b` still folds (`$GLOBALS['c']('hello')` → `"olleh"`) because `$c` IS registered. This is unrelated to the scope fix; it confirms the rest of the pipeline still works.

   Note: the AOT pretty-printer may render `fn() => $b = function () {...}` slightly differently than the input. The OUTPUT in the fixture must match what `ExtendedPrettyPrinter::prettyPrintFile` actually emits; verify during implementation.

That single fixture is enough — it exercises both `Resolver::changesScope` (the fact that `$b` doesn't leak as a global) and `ClosureRegistryPrepass::changesScope` (the prepass doesn't register `$b`). Pre-existing fixtures (cases 1–11 in `tests/globals-closure-eval.txt`) confirm no regression for non-arrow-function paths.

**Smoke check:** none — the obfuscator samples don't use arrow functions. Re-run `php index.php -f samples/next_DOLOLO.php` and confirm the `$GLOBALS["` count is unchanged from the post-PR-#2 baseline (15).
