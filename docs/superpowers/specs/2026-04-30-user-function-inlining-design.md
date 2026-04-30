# User-Defined Function Inlining

**Date:** 2026-04-30
**Status:** Approved

## Problem

Obfuscated PHP files frequently define small helper functions (e.g. a `str_replace` wrapper that strips a noise character from strings) and then use those helpers as callbacks to `array_map`, or call them directly to decode variable names and function references. Because the deobfuscator only knows about built-in PHP functions, these user-defined calls are left unreduced, which breaks the entire downstream decoding chain.

The `samples/381_orig.php` sample is the motivating case: `faeriqmn()` wraps `str_replace($GLOBALS["hrwpjggtjx"], "", $s)`. Nothing reduces until that function can be inlined.

## Goal

Automatically inline any user-defined `function foo() {}` declaration encountered in the file, so that a call like `faeriqmn($var)` reduces to its scalar result without any manual intervention from the researcher.

## Approach: Pre-pass registry + `FuncCallReducer` fallback (Option B)

Mirrors the existing `ClosureRegistryPrepass` / `resolveGlobalsLiteralClosureCall` pattern exactly.

## Components

### 1. `Resolver` — new registry methods

Add a `private array $userFunctions = []` field and two public methods:

```php
public function registerUserFunction(string $name, Stmt\Function_ $func): void
// stores $func under strtolower($name)

public function getUserFunction(string $name): ?Stmt\Function_
// returns the registered node or null
```

Names are lowercased on both sides (PHP function calls are case-insensitive). The registry is NOT cloned by `cloneScope()` — function definitions are global and immutable once registered.

### 2. `UserFunctionPrepass` — `src/UserFunctionPrepass.php`

A new `NodeVisitorAbstract` subclass. Overrides `enterNode` only:

```php
public function enterNode(Node $node) {
    if ($node instanceof Stmt\Function_) {
        $this->resolver->registerUserFunction(
            $node->name->toLowerString(),
            $node
        );
    }
}
```

Added to `Deobfuscator::__construct` in the existing `$this->closurePrepass` traverser, immediately after `ClosureRegistryPrepass`:

```php
$this->closurePrepass->addVisitor(new ClosureRegistryPrepass($resolver));
$this->closurePrepass->addVisitor(new UserFunctionPrepass($resolver));
```

No new traverser is needed.

### 3. `FuncCallReducer::tryInlineUserFunction` — fallback in `makeFunctionCall`

`makeFunctionCall` currently returns `null` when `$name` is not in the plugin map. Replace that early return with a call to the new method:

```php
private function makeFunctionCall($name, $node)
{
    if (isset($this->funcCallMap[$name])) {
        // ... existing plugin dispatch (unchanged)
    }
    return $this->tryInlineUserFunction($name, $node);
}
```

`tryInlineUserFunction` logic:

1. Look up `$this->resolver->getUserFunction($name)` — return `null` if not found.
2. Reject calls with splat (`unpack`) or by-ref args.
3. Reject if arg count doesn't match the function's param count.
4. Pretty-print each argument expression; build a binding preamble (`$param = <argSrc>;` per param).
5. Pretty-print the function's body stmts.
6. Wrap preamble + body in a closure literal and pass to `$this->evalReducer->runEvalTree(...)`.
7. Unwrap: if the reduced result's last statement is a `Return_` carrying a VALUE attribute, return the scalar node. Otherwise return `null` (leave the call unreduced).

This is structurally identical to `resolveGlobalsLiteralClosureCall`, which already works.

## Data flow for `381_orig.php`

```
closurePrepass:
  UserFunctionPrepass sees `function faeriqmn(...)` → resolver->registerUserFunction('faeriqmn', node)

secondPass — for `faeriqmn($cjjkyp)`:
  FuncCallReducer: 'faeriqmn' not in plugin map
  → tryInlineUserFunction('faeriqmn', node)
  → builds: function() { $egjovlldqfca = 'ba<se6<4_de<code'; return str_replace(...); }
  → runEvalTree reduces str_replace('<', '', 'ba<se6<4_de<code') → 'base64_decode'
  → $swgsykpvzde = 'base64_decode'  ✓

  FuncCallReducer: array_map('faeriqmn', $clyqcygzupmt)
  → staticArrayMap calls runEvalTree("return faeriqmn('...');") per element
  → inner traversal finds 'faeriqmn' in registry, inlines it → stripped strings  ✓

  FuncCallReducer: array_map('base64_decode', $kdrxyztj)
  → staticArrayMap reduces each element via FunctionSandbox  ✓

  $GLOBALS['eouodsoyt'] = [decoded function name array]  ✓
```

## Edge cases

| Case | Behaviour |
|------|-----------|
| Recursive function | `EvalReducer::MAX_DEPTH = 8` bottoms out; call left unreduced |
| Body doesn't reduce to a scalar | `getValue` throws `BadValueException`; `tryInlineUserFunction` returns `null` |
| Splat or by-ref args | Rejected early; call left unreduced |
| Arg count mismatch | Rejected early; call left unreduced |
| Function defined after call site | Pre-pass registers before main pass runs; order in source is irrelevant |
| Duplicate function name | Last definition registered wins (matches PHP behaviour) |
| Functions with I/O / network | No sandbox covers those calls; body won't reduce to scalar; left unreduced |

## Files changed

| File | Change |
|------|--------|
| `src/Resolver.php` | Add `$userFunctions` field + `registerUserFunction` / `getUserFunction` |
| `src/UserFunctionPrepass.php` | New file |
| `src/Deobfuscator.php` | Add `new UserFunctionPrepass($resolver)` to `closurePrepass` traverser |
| `src/Reducer/FuncCallReducer.php` | Add `tryInlineUserFunction`; wire into `makeFunctionCall` |
| `tests/reducers.txt` | New `INPUT`/`OUTPUT` fixture covering the `faeriqmn` pattern |
