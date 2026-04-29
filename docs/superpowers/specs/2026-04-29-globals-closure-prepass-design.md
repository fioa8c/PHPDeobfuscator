# Closure-registry pre-pass — design

**Date:** 2026-04-29
**Motivating sample:** `samples/next_DOLOLO.php`
**Builds on:** `2026-04-28-globals-closure-eval-design.md`

## Problem

The previous globals-closure-eval feature shipped a closure-call evaluator gated on `Resolver`'s closure registry. The registry is populated as a side effect of `Resolver::tryRegisterGlobalClosure`, called from `onAssign`, which fires during the secondPass traversal at the `leaveNode` of each `Expr\Assign`.

That timing produces a forward-reference bug: any `$GLOBALS["k"](...)` call site whose closure-bound key is declared *later* in source order than the call site cannot fold. The `FuncCall` is visited via depth-first traversal *before* the `leaveNode` for the syntactically-later closure assignment ever fires.

In `samples/next_DOLOLO.php` the decoder closure `$D_O_OLLLO_` is defined at line 120, but three call sites that use it live in closure bodies at lines 67, 80, and 82 — inside `$DL_LOO_OL_` (line 66) and `$D_OL_L_OOL` (line 78). Those three sites stay unresolved in the deobfuscated output. The 16 sites that *do* fold are all in closures defined at line 126 or later, where `$D_O_OLLLO_` was already registered by the time the call site was visited.

## Goal

Populate the closure registry from a separate pass that completes before any call-site reduction runs. After this change, the textual order of top-level closure assignments relative to the call sites that use them no longer matters.

Specifically: in `samples/next_DOLOLO.php`, the three currently-unresolved call sites at lines 67, 80, 82 fold to scalars (`'\\\\?/|&()[]{}+^$!:*'`, `'googlebot|baiduspider|bingbot|google|baidu|aol|bing|yahoo'`, `'|Mozilla'` respectively, verified independently).

Out of scope:

- Forward-reference handling for the existing string-name fallback (`resolveGlobalsLiteralName`). The motivating obfuscators put their string assignments at the very top of the file, so this is unobserved in practice.
- Any change to how the registry is *consumed* — `FuncCallReducer::resolveGlobalsLiteralClosureCall` and the gating logic stay as-is.
- Two-pass-style global value tracking (e.g., letting forward-referenced *string* assignments inside closures resolve too). That's a much larger structural change.

## Approach

### Why adding a visitor to `secondPass` doesn't work

`NodeTraverser` walks all visitors in lockstep at each node. Adding a prepass visitor to `secondPass` *before* `Resolver` would not fix the bug: the new visitor's `enterNode`/`leaveNode` for line 67's call-site `FuncCall` still happens before any visitor's `leaveNode` for line 120's `Assign`. The walk is depth-first, but the order is per-node-across-all-visitors, not per-visitor-across-all-nodes.

The fix requires the registry to be populated by a **separate, fully-completed traversal** that finishes before secondPass starts.

### Where the new pass lives

`Deobfuscator::__construct` already runs a `firstPass` (`ControlFlowVisitor` only) before `secondPass`. Add a new visitor `ClosureRegistryPrepass` to `firstPass`.

`firstPass.traverse()` returns only after every visitor has finished its full walk of the tree. By the time `secondPass.traverse()` begins, the registry is fully populated.

### What the new visitor does

`ClosureRegistryPrepass` (new file `src/ClosureRegistryPrepass.php`) is a `NodeVisitorAbstract` that:

1. Tracks whether it is currently inside a function body, method body, or closure body. A counter `$nestingDepth` increments on `enterNode` for `Stmt\Function_`, `Stmt\ClassMethod`, or `Expr\Closure`; decrements on `leaveNode` for the same. The "global scope" condition is `$this->nestingDepth === 0`.
2. On `enterNode` for `Expr\Assign`, when `$nestingDepth === 0`, runs the same gate predicate the current `Resolver::tryRegisterGlobalClosure` uses:
   - LHS is `Expr\Variable` with a string `name`, OR `Expr\ArrayDimFetch` with `var=Expr\Variable("GLOBALS")` and `dim=Scalar\String_`.
   - RHS is `Expr\Closure` with empty `uses`, no by-ref params, no default-valued params, no variadic params.
3. On match, calls `$this->resolver->registerGlobalClosure($name, $rhs)`.

The visitor takes the `Resolver` instance via constructor injection so it can call the existing public `registerGlobalClosure` method. No new API on `Resolver` is needed.

### Removing the now-redundant `onAssign` registration

`Resolver::tryRegisterGlobalClosure` and its one-line invocation from `onAssign` are deleted. The prepass becomes the sole source of truth for the registry.

Behavioral consequence (last-write-wins): the registry holds the *textually-last* closure assignment for each name. If a closure is reassigned mid-file with a different body, both call sites — those before and those after the reassignment — see the second body. PHP runtime semantics would have the call sites before the reassignment use the first body, but obfuscators don't reassign closures, so this divergence is theoretical. The previous design already documented this same caveat under a different framing.

### Wiring

```php
// In Deobfuscator::__construct, before any visitor that consumes the registry:
$resolver = new Resolver();
$this->firstPass->addVisitor(new ControlFlowVisitor());
$this->firstPass->addVisitor(new ClosureRegistryPrepass($resolver));
// ...
$this->secondPass->addVisitor($resolver);
```

`Resolver` must be constructed *before* the prepass visitor (the prepass holds a reference). The current `Deobfuscator` constructor already creates `$resolver` in the right position; only the visitor-add lines move/grow.

## Walkthrough on the real sample

For `samples/next_DOLOLO.php`:

**firstPass (single traversal):**

1. `ControlFlowVisitor` rewrites any goto-based control flow (the sample has none on the path between the closures of interest).
2. `ClosureRegistryPrepass` visits every node depth-first. Top-level `Expr\Assign` nodes hit by `enterNode` while `nestingDepth === 0`:
   - Line 51: `$DLOOL__O_L = function ($url) {...};` — closure has no `use`/byRef/default/variadic, registered.
   - Line 66: `$DL_LOO_OL_ = function ($DOL__LOL_O, $DO_L_L_LOO = 1) {...};` — has default-valued param, **skipped**.
   - Line 78: `$D_OL_L_OOL = function ($DO_OLOL__L = '', $D_O__OLLOL = false) {...};` — has defaults, **skipped**.
   - Line 91: `$DL_OO_O_LL = function ($DL_LO_LO_O = '') {...};` — has default, **skipped**.
   - Line 98: `$DLO_O_LL_O = function ($DL_LO_LO_O) {...};` — registered.
   - Line 117: `$D_LOL_L_OO = function ($enstr) {...};` — registered.
   - **Line 120: `$D_O_OLLLO_ = function ($strv) {...};` — registered.** ← critical.
   - Line 126 onward: more closures, registered as eligible.

   Inside each closure body, `nestingDepth > 0`, so any nested `$x = function(){...}` is correctly ignored.

3. `firstPass.traverse()` returns. Registry now holds `D_O_OLLLO_` (and the others).

**secondPass:**

4. `Resolver` enters node-by-node, no longer touching the registry. `ResolveValueVisitor`, `ReducerVisitor` work as before.
5. When `FuncCall` at line 67 is visited inside `$DL_LOO_OL_`'s body: `FuncCallReducer::reduceFunctionCall` → `BadValueException` catch → `resolveGlobalsLiteralClosureCall` → `getGlobalClosure("D_O_OLLLO_")` → **hit**. Synthetic closure-literal source built and run through `EvalReducer::runEvalTree`. Body folds to `return '\\\\?/|&()[]{}+^$!:*';`. Call site replaced with that scalar.
6. Same path for lines 80 and 82.

**Pretty-printed output:** the three call sites become scalar string literals; the closure definitions themselves stay verbatim (cleanup of unused closures is a separate concern).

**Smoke check expectation:** `$GLOBALS["` count drops from 18 (current master) to 15 — matching the three forward-reference cases the user identified.

## Edge cases & safety

- **Reassignment textual semantics.** Documented above; no real-world impact.
- **Closures created via `create_function` or eval.** The prepass walks the *original* AST. Any closure synthesized later (e.g. by `MiscFunctions::createFunction`) is not in the prepass-walked tree; the existing flow that emits that closure still inhabits a `Stmt\Expression` node which doesn't run through firstPass — it lives only in the secondPass-emitted output. These continue to be invisible to the registry. That's pre-existing behavior, not a regression.
- **Closures inside conditional top-level statements.** `if (...) { $f = function(){}; }` — `$f` is at top level textually but inside an `Stmt\If_`. With the new visitor, `If_` does not increment `nestingDepth`, so the assignment is registered. PHP runtime would only assign if the branch is taken; the registry is "best effort" pre-runtime. This matches the existing `Resolver::tryRegisterGlobalClosure` behavior — `setCurrentVarsMutable` flags are not checked there either — so the new prepass is no more aggressive than the old code. No change.
- **Multiple firstPass invocations (recursion via `EvalReducer::runEvalTree`).** `EvalReducer` calls `Deobfuscator::deobfuscate`, which runs *both* passes. So the prepass runs again on each synthetic source. That's fine: the synthetic source is a closure literal whose body has no top-level closure assignments (the assignments are nested inside the synthetic outer closure → `nestingDepth > 0` → skipped). The registry is unchanged by the recursive prepass run.
- **`ControlFlowVisitor` interactions.** ControlFlowVisitor rewrites goto-label patterns into structured control flow inside `Stmt\Function_` / `Stmt\ClassMethod` / `Expr\Closure` bodies. It doesn't touch top-level non-goto code. Since our prepass only registers at `nestingDepth === 0`, ControlFlowVisitor's mutations (which all happen *inside* nested function bodies) cannot affect what the prepass sees.

## Testing

Three new fixtures appended to the existing `tests/globals-closure-eval.txt` (auto-discovered by `php test.php`).

1. **Forward-reference: caller-before-callee, single hop.**

   Closure A is defined first, references `$GLOBALS['B']` in its body. Closure B is defined later. Calling A through `$GLOBALS['A']` should fold to the scalar B would have produced.

   ```
   INPUT
   $a = function ($x) {
       return $GLOBALS['b']($x);
   };
   $b = function ($x) {
       return strrev($x);
   };
   $out = $GLOBALS['a']('abc');

   OUTPUT
   $a = function ($x) {
       return $GLOBALS['b']($x);
   };
   $b = function ($x) {
       return strrev($x);
   };
   $out = "cba";
   ```

   Without the prepass, the inner call inside `$a`'s body would not fold (b not registered when a is visited), so `$GLOBALS['a']('abc')` itself wouldn't fold either — the test would show `$out = $GLOBALS['a']('abc');`.

2. **Closure inside a function body — still skipped post-prepass.** Lifted from the previous spec's coverage but rerun to confirm the prepass respects nesting. Already covered by case 5 of the existing fixture file — no new fixture needed.

3. **Forward-referenced closure with default-valued params is still skipped.**

   ```
   INPUT
   $a = function ($x) {
       return $GLOBALS['b']($x);
   };
   $b = function ($x = 'default') {
       return $x;
   };
   $out = $GLOBALS['a']('abc');

   OUTPUT
   $a = function ($x) {
       return $GLOBALS['b']($x);
   };
   $b = function ($x = 'default') {
       return $x;
   };
   $out = $GLOBALS['a']('abc');
   ```

   `$b` is forward-referenced but its registration is gated out (default-valued param). Both call sites stay unresolved.

**Smoke check (not committed):** rerun `php index.php -f samples/next_DOLOLO.php`. Expect the three previously-unresolved call sites to fold; `$GLOBALS["` count should drop from 18 to ~15. Numbers go in the merge commit message.

**Regression guard:** all pre-existing fixtures (including the nine in `tests/globals-closure-eval.txt`) and the previous spec's `globals.txt` must continue to pass. The change is strictly additive in capability; the gates that filtered before still filter now (just at prepass time instead of onAssign time).
