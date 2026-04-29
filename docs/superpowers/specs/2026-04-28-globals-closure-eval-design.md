# `$GLOBALS["literal"](...)` closure-call evaluation — design

**Date:** 2026-04-28
**Motivating sample:** `samples/next_DOLOLO.php`
**Builds on:** `2026-04-28-globals-funccall-rename-design.md`

## Problem

The previous globals-rewrite work (`docs/superpowers/specs/2026-04-28-globals-funccall-rename-design.md`, shipped in commits `cdbd2f4`..`3939f19`) handles the case where a global string variable holds a built-in function name and is invoked via `$GLOBALS["k"](...)`. It rewrites the call site to a plain named call.

`samples/next_DOLOLO.php` exhibits a *second* obfuscation shape that the existing pipeline does not handle: the global variable holds an **anonymous function** (closure literal), and the closure is itself a small decoder.

```php
$D_O_OLLLO_ = function ($strv) {
    $DLO_OOLL__ = substr($strv, 0, 5);
    $DLL__O_OOL = substr($strv, -5);
    $DOOO_LL_L_ = substr($strv, 7, strlen($strv) - 14);
    return gzinflate(base64_decode($DLO_OOLL__ . $DOOO_LL_L_ . $DLL__O_OOL));
};

// later, inside another closure:
$DLLO_LO_O_ = $GLOBALS["D_O_OLLLO_"]('09PXyhTyhJTE5OLS4tPGAA==');
```

The decoder reduces a literal-arg call to a constant string (in this case `'./.htaccess'`). The body uses only functions our pipeline already folds (`substr`, `strlen`, `base64_decode`, `gzinflate`), so the call should be statically reducible.

The pipeline does not currently track closures as values: `Resolver::onAssign` of `$x = function(){...}` records nothing about the RHS, and `ResolveValueVisitor` does not attach a `VALUE` attribute to `Expr\Closure` nodes. Consequently, the existing globals fallback (`FuncCallReducer::resolveGlobalsLiteralName`) cannot help — there is no string-valued function name to rewrite to, and there is no value-side mechanism to invoke the closure symbolically.

## Goal

When a `FuncCall`'s name is `$GLOBALS["<literal>"]` and the literal key was assigned a closure literal at top level in the global scope, *evaluate* the closure with the call's arguments and replace the entire call site with the resulting scalar — but only when that evaluation produces a clean scalar return. Otherwise leave the call untouched.

Out of scope (deferred until a future sample motivates them):

- Closures with `use ($outer)` clauses (skip — see Edge cases).
- Closures with by-ref params (skip — safety).
- Closures with default-valued params (skip — arity gate).
- Plain `$f(args)` for closures bound to non-`$GLOBALS["literal"]` variables.
- User-defined named functions (`function foo(){...}` invoked as `foo(...)`).
- Method/static calls (`$obj->m()`, `Cls::$m()`).
- Inlining the closure body at the call site when the return is non-scalar (option (c) in brainstorming).

## Approach

A new fallback path in `FuncCallReducer::reduceFunctionCall`, parallel to the existing `resolveGlobalsLiteralName` helper, plus a small closure registry on `Resolver` populated as a side effect of top-level closure assignments.

### Closure registry on `Resolver`

A name → `Expr\Closure` map populated whenever `Resolver::leaveNode` processes an `Expr\Assign` that satisfies *all* of:

1. The current scope is the global scope (`$this->scope === $this->getGlobalScope()`).
2. The assignment LHS is either:
   - `Expr\Variable` with a string `name`, OR
   - `Expr\ArrayDimFetch` whose `var` is `Expr\Variable("GLOBALS")` and whose `dim` is `Scalar\String_`.
3. The assignment RHS is `Expr\Closure` with empty `uses` and no `byRef` parameters and no parameters with default values.

The registered key is the variable name (the `Variable->name` string, or the `String_->value` from the `$GLOBALS[...]` form).

Reassignments overwrite (last write wins). Closures defined inside other functions/methods/closures are silently skipped — gated by check (1).

Public API on `Resolver`:

```php
public function registerGlobalClosure(string $name, Node\Expr\Closure $closure): void;
public function getGlobalClosure(string $name): ?Node\Expr\Closure;
```

### New fallback in `FuncCallReducer`

`reduceFunctionCall` gains a third branch in the existing fallback chain. The new ordering:

```
reduceFunctionCall(FuncCall $node)
  if $node->name instanceof Node\Name              → existing path
  else
    try { $name = Utils::getValue($node->name) }    → existing path (rewrites $node->name in place)
    catch BadValueException:
      // NEW: try closure-call evaluation first
      $replacement = resolveGlobalsLiteralClosureCall($node)
      if $replacement !== null:
          return $replacement                       (replaces the entire FuncCall)
      // existing fallback unchanged
      $name = resolveGlobalsLiteralName($node->name)
      if $name === null:
          return                                    (call left untouched)
      $node->name = Node\Name($name) (with `replaces` attribute)
    return makeFunctionCall(strtolower($name), $node)
```

Closure evaluation is tried **before** the string-name fallback. The two are mutually exclusive in practice (a closure literal can't pass `resolveGlobalsLiteralName`'s identifier regex, and a string function name isn't in the closure registry), but the order makes the intent explicit and avoids running the regex check on every closure-typed call.

`resolveGlobalsLiteralClosureCall(FuncCall $node): ?Node`:

1. Recognise shape — same predicate as `resolveGlobalsLiteralName`: `$node->name` must be `ArrayDimFetch(var=Variable("GLOBALS"), dim=String_(<key>))`. Otherwise return null.
2. Look up the closure: `$closure = $this->resolver->getGlobalClosure($key)`. If null, return null.
3. Arity check: `count($closure->params) === count($node->args)`. Otherwise return null. (Default-valued params are already excluded at registration time, so this check is a strict equality.)
4. Build a synthetic mini-program as a string by pretty-printing each `$paramName = <argExpression>;` in order, then appending the closure body's statements pretty-printed. The strategy mirrors `MiscFunctions::createFunction`, which already builds source text and feeds it back to the parser.
5. Run the synthetic program through `$this->evalReducer->runEvalTree($source)`. Wrap in `try { ... } catch (\Throwable $e) { return null; }`.
6. Inspect the resulting statement list:
   - If the last statement is `Stmt\Return_`, attempt `Utils::getValue($return->expr)`. If it returns a scalar, wrap it via `Utils::scalarToNode(...)` and return that node.
   - On any other shape (no return, return without a resolvable value, multiple non-fold statements), return null.

The returned node fully replaces the original `FuncCall` in the AST.

### Wiring

`FuncCallReducer::__construct` gains an `EvalReducer $evalReducer` parameter. `Deobfuscator::__construct` already constructs `$evalReducer` (used by `MiscFunctions`) before `$funcCallReducer`, so the change is one extra constructor argument:

```php
$funcCallReducer = new Reducer\FuncCallReducer($resolver, $evalReducer);
```

No ordering change.

### Recursion safety

`EvalReducer` gains a depth counter:

```php
private int $depth = 0;
private const MAX_DEPTH = 8;
```

`runEvalTree($code)` and `runEval($code)` (which calls `deobfTree` likewise) increment `$this->depth` on entry, decrement in a `finally` block on exit. If `$this->depth > self::MAX_DEPTH` at entry, the method returns `[]` (empty stmt list) — caller sees no return statement and aborts the rewrite cleanly. The cap protects against pathological closure-calls-closure chains; eight levels is well above any realistic obfuscator stacking.

## Walkthrough on the real sample

For:

```php
$D_O_OLLLO_ = function ($strv) {
    $DLO_OOLL__ = substr($strv, 0, 5);
    $DLL__O_OOL = substr($strv, -5);
    $DOOO_LL_L_ = substr($strv, 7, strlen($strv) - 14);
    return gzinflate(base64_decode($DLO_OOLL__ . $DOOO_LL_L_ . $DLL__O_OOL));
};
$DLLO_LO_O_ = $GLOBALS["D_O_OLLLO_"]('09PXyhTyhJTE5OLS4tPGAA==');
```

**Setup pass.** When `Resolver::leaveNode` processes the first `Expr\Assign`:
- Scope is global. ✓
- LHS is `Variable("D_O_OLLLO_")`. ✓
- RHS is `Expr\Closure` with no `use`, no by-ref params, no defaults. ✓
- → `registerGlobalClosure("D_O_OLLLO_", <closure-node>)`.

**Call site pass.** `leaveNode` reaches the `FuncCall` for `$GLOBALS["D_O_OLLLO_"](...)`:
1. `$node->name` is `ArrayDimFetch`, not `Node\Name`.
2. `Utils::getValue($node->name)` throws `BadValueException` (no `VALUE` attribute on the array-dim-fetch).
3. `resolveGlobalsLiteralClosureCall($node)`:
   - Shape matches.
   - Registry hit: `$closure` for key `"D_O_OLLLO_"`.
   - Arity 1 == 1. ✓
   - Synthetic source:
     ```php
     $strv = '09PXyhTyhJTE5OLS4tPGAA==';
     $DLO_OOLL__ = substr($strv, 0, 5);
     $DLL__O_OOL = substr($strv, -5);
     $DOOO_LL_L_ = substr($strv, 7, strlen($strv) - 14);
     return gzinflate(base64_decode($DLO_OOLL__ . $DOOO_LL_L_ . $DLL__O_OOL));
     ```
   - `runEvalTree` parses and runs full deobfuscation. `substr`, `strlen`, `base64_decode`, `gzinflate` are all in `FunctionSandbox`; concat and the return fold to `'./.htaccess'`.
   - Last stmt is `Return_` with a scalar `VALUE` of `'./.htaccess'`.
   - Returns `Utils::scalarToNode('./.htaccess')`.
4. `reduceFunctionCall` returns the scalar string node, which `ReducerVisitor` substitutes for the entire `FuncCall`.

Pretty-printed output line:

```php
$DLLO_LO_O_ = './.htaccess';
```

The same mechanism handles the other call sites in `next_DOLOLO.php` that follow this pattern.

## Edge cases & safety

- **Closures see a snapshot of registration time.** A closure registered first and reassigned later: last write wins for *subsequent* call sites; earlier call sites already evaluated will already be replaced. Matches PHP's textual-order semantics and the closure caveat the previous spec documented.
- **Conservative `use ()` policy.** Closures that capture outer state are skipped at registration time. Reason: making `use` work correctly means propagating captured values' scalar bindings into the synthetic mini-program *and* respecting their mutability flags, which expands surface area for a feature that doesn't need it on the motivating sample. Revisit when a future sample motivates it.
- **By-ref params and default-valued params are skipped.** Skipped at registration: the synthetic mini-program can't model by-ref semantics, and default values would confuse the strict arity check.
- **Mutability bypass via side-channel.** The registry is populated unconditionally from `onAssign`; lookup bypasses the gated `Scope::getVariable` path entirely. This parallels the existing helper's `setMutable(false)` toggle, but achieves the same effect by avoiding the gated path rather than toggling around it. No change to the `ValRef` machinery.
- **No syntactically invalid PHP can be produced.** The fallback either returns a fully evaluated scalar node (always valid) or null (no rewrite). No partial AST mutations.
- **Side-effect containment.** The synthetic mini-program runs through the standard pipeline, which routes file I/O through the in-memory Flysystem and restricts function execution to whitelisted reducers. No host-system access is possible — same containment as `EvalReducer` already provides for `eval()` reductions.
- **Recursion cap.** Eight is well above any realistic obfuscator stacking. If exceeded, the deepest call returns null and the outer call also aborts; no exception propagates.
- **`static` closures (`static function (...) { ... }`).** Treated identically to non-static closures. The static modifier only forbids `$this` binding, which we don't use in evaluation.
- **Self-referential closure (`use (&$f)`).** Skipped at registration (`use` clause non-empty). Even were it not skipped, the recursion cap would terminate any infinite loop.
- **Closure that throws / has fatal-only paths.** If the synthetic program parses but throws during `runEvalTree`, the `try/catch (\Throwable)` in the fallback swallows it. The original call stays.

## Testing

One new fixture file: `tests/globals-closure-eval.txt`, picked up automatically by `test.php`. Five blocks; each in the existing `INPUT` / `OUTPUT` format.

1. **Happy path — scalar replaces call site.** The literal sample, transplanted to a minimal fixture (`$decode` closure, `$out = $GLOBALS['decode']('09P...');` → `$out = './.htaccess';`). Closure definition is preserved verbatim; only the call site is rewritten.
2. **Mutable-scope poison — registry bypasses mutability.** A `poison()` function that writes to `$GLOBALS['unrelated']` (flipping global vars mutable) followed by a closure call. Confirms the side-channel registry is unaffected by `setCurrentVarsMutable`.
3. **Body that doesn't fold — silent skip.** Closure body returns `getenv($s) . 'x'` (`getenv` is in `PassThrough`, no fold). Whole call stays.
4. **Closure with `use()` — skip registration entirely.** Even when the captured outer var is a known constant, no rewrite. Confirms the conservative `use` policy.
5. **Closure assigned inside a function body — not registered.** `function setup() { $GLOBALS['inner'] = function(...){...}; }` followed by `$GLOBALS['inner'](...)`. Confirms scope gating.

**Smoke check (not committed).** Re-run `php index.php -f samples/next_DOLOLO.php` before merging. Count `$GLOBALS["` occurrences in the output before vs. after the change. Numbers go in the commit message, mirroring the previous spec's smoke-check practice for `bWNAdf.php`.

**Regression guard.** All four pre-existing fixture files (`reducers.txt`, `variables.txt`, `goto-tests.txt`, `filesystem.txt`) plus the previous spec's `globals.txt` must continue to pass. The new path only fires when the existing string-name path would produce no rewrite *and* the closure registry has a hit — strictly additive.
