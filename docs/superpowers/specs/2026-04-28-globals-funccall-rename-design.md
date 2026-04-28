# `$GLOBALS["literal"](...)` function-name rewrite — design

**Date:** 2026-04-28
**Motivating sample:** `/Users/fioa8c/WORK/waffle-makers-tooling/ASTro/samples/bWNAdf.php`

## Problem

Real-world PHP obfuscators commonly:

1. Build a string at the top of the file that spells out a PHP built-in function name (e.g. by indexing into a scrambled alphabet).
2. Assign that string to a variable in the global scope (`$DXXXX = "file_get_contents"`).
3. From inside closures (which don't `use` the variable), call the function via `$GLOBALS["DXXXX"](...)`.

The current deobfuscator already recovers step (1) and step (2): the top-level scalar resolution pipeline reduces every concatenation chain to its literal string. Step (3) is the gap. On `bWNAdf.php` the deobfuscated output contains 142 unresolved `$GLOBALS["..."](...)` call sites.

The existing `FuncCallReducer::reduceFunctionCall` already mutates `$node->name` in place when `Utils::getValue($node->name)` returns a known string — but on this sample the call fails because `ResolveValueVisitor` could not attach a `VALUE` attribute to the `Expr\ArrayDimFetch`. That happens because somewhere in the script the global scope is flagged mutable, so `ArrayAccessVariable->getValue()` short-circuits on its `!isMutable()` guard and returns null before ever touching `GlobalVarArray::arrayFetch`.

## Goal

When a `FuncCall`'s name is `$GLOBALS["<literal>"]` and that literal key resolves to a value that looks like a valid PHP function identifier, rewrite the call so its name becomes a plain `Node\Name(<resolved string>)`. After the rewrite the call site reads as a normal named function call.

Out of scope (deferred until a future sample motivates them):

- `$obj->{$method}()` / `$obj->$method()` (`Expr\MethodCall`).
- `Class::$static()` (`Expr\StaticCall`).
- Plain `$var(...)` — already handled today; we are not enlarging that path.
- Per-variable mutability tracking — we don't change the resolver or `ValRef` machinery.

## Approach

A new private helper inside `FuncCallReducer` (`src/Reducer/FuncCallReducer.php`), invoked from the existing `reduceFunctionCall` only as a fallback when the existing path returns no name.

```
reduceFunctionCall(FuncCall $node)
  if $node->name instanceof Node\Name      → existing path
  else
    try { $name = Utils::getValue($node->name) }    → existing path (rewrites $node->name in place)
    catch UnknownValueException:                    (newly caught LOCALLY — see note below)
      $name = resolveGlobalsLiteralName($node->name)
      if $name === null:
          return                            (leave the original call untouched)
      $node->name = Node\Name($name)        (with `replaces` attribute, like the existing path)
    return makeFunctionCall(strtolower($name), $node)
```

**Note on the local catch:** Today `Utils::getValue` throws `UnknownValueException` (a `BadValueException` subclass) when no `VALUE` attribute is set, and that exception propagates all the way up to `ReducerVisitor::leaveNode`, which silently swallows it. To make the fallback reachable we must catch `UnknownValueException` *locally* inside `reduceFunctionCall`. Other `BadValueException` subclasses (`MutableValueException`) should continue to propagate so existing behaviour is preserved — the local catch must be narrow.

`resolveGlobalsLiteralName(Node $expr)` recognises exactly one shape: `Expr\ArrayDimFetch` whose `var` is `Expr\Variable` named `"GLOBALS"` and whose `dim` is `Scalar\String_`. On a hit it reads from `Resolver::getGlobalScope()->getVariable($key)` directly — bypassing `GlobalVarArray::arrayFetch` and its `checkMutable()` call, which is what defeats the existing path.

If the global lookup yields a `ScalarValue` whose value is a string matching the identifier regex below, we return that string. Anything else → return `null` and the call stays as-is.

```
/^\\?[A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*$/
```

(Optional leading `\`, namespace separators allowed, no other characters. Empty strings, expression-like content, arrays, `UnknownValRef`, and `null` all fail this check.)

## Wiring

`FuncCallReducer` gains a constructor `Resolver $resolver` parameter. `Deobfuscator::__construct` already constructs `$resolver` before `$funcCallReducer`, so the change is one extra constructor argument:

```php
$funcCallReducer = new Reducer\FuncCallReducer($resolver);
```

No ordering change to the existing wiring.

## Walkthrough on the real sample

For the line:

```php
@$GLOBALS["DOLO__LLO_"]($url);
```

AST: `ErrorSuppress( FuncCall( name=ArrayDimFetch(var=Variable("GLOBALS"), dim=String_("DOLO__LLO_")), args=[Variable("url")] ) )`

Visitor `leaveNode` order:

1. `String_("DOLO__LLO_")` — `VALUE = ScalarValue("DOLO__LLO_")`.
2. `Variable("GLOBALS")` — superglobal `GlobalVarArray` resolved.
3. `ArrayDimFetch` — `ArrayAccessVariable->getValue()` short-circuits because `GlobalVarArray->isMutable()` is true; no `VALUE` attached.
4. `Variable("url")` — closure-local, unknown.
5. `FuncCall` — `FuncCallReducer::reduceFunctionCall` runs:
   - `$node->name` is not a `Node\Name`.
   - `Utils::getValue($node->name)` throws `UnknownValueException` (caught).
   - **New path:** `resolveGlobalsLiteralName` matches the shape, reads `getGlobalScope()->getVariable("DOLO__LLO_")` → `ScalarValue("file_get_contents")`, identifier regex matches, returns `"file_get_contents"`.
   - `$node->name` is replaced with `Node\Name("file_get_contents")` carrying the original `ArrayDimFetch` in a `replaces` attribute.
   - `makeFunctionCall("file_get_contents", $node)` — `file_get_contents` is in `PassThrough`, but its arg `$url` has no known value, so `makeFunctionCall` returns `null` without executing. The rewritten name persists.
6. `ErrorSuppress` — unchanged.

Pretty-printed output:

```php
@file_get_contents($url);
```

The same mechanism handles the other 141 sites in `bWNAdf.php`.

## Edge cases & safety

- **Closures see a snapshot, not a live binding.** Closures are visited at the textual point they appear, so the global lookup uses whatever value the Resolver had recorded by then. PHP's actual semantics for `$GLOBALS["foo"]` inside a closure read the value at *call* time. For obfuscated code where these globals are set once at the top and never reassigned, this is safe. If a script *did* later reassign one of the function-name globals, the rewrite at the earlier closure would reflect a stale name. We accept this — the deobfuscator targets readability, and a real reassignment of a function-name global would be unusual obfuscator behaviour. A short comment on the helper documents the assumption.
- **Mutability bypass is intentional and scoped.** The new helper deliberately skips `GlobalVarArray::arrayFetch`'s `checkMutable()` — but only when reading a *literal* key for a function-call name. `setVariable` on the global scope is unchanged elsewhere; we trust the most recent `setVariable` for a given key. No change to the `ValRef` mutability machinery; no other reducers or visitors touched.
- **Identifier validation as a hard gate.** The regex above is the safety net. Anything that doesn't match is silently skipped, leaving the original `$GLOBALS["foo"](...)` in the output. There is no path from this change to syntactically invalid PHP.
- **Side-effect risk from `PassThrough`.** Once we resolve a name like `base64_decode`, `makeFunctionCall` will invoke it iff *all* args are known constants. For the closure bodies in `bWNAdf.php` the args are local variables, so `PassThrough` does not fire and we get a clean rename. For the rare case where the args *are* all constant — e.g. `$GLOBALS["base64_decode"]("aGk=")` — `PassThrough` will execute and replace the whole call with the result. That is identical to today's behaviour for the un-obfuscated equivalent `base64_decode("aGk=")` and matches user expectation.

## Testing

One new fixture file: `tests/globals.txt`, picked up automatically by `test.php` (it iterates every file in `tests/`). Each block uses the existing `INPUT` / `OUTPUT` format.

Cases:

1. **Happy path — `$GLOBALS["literal"]` in a closure rewrites.**
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

2. **Suppressed call — `@$GLOBALS["foo"](...)` rewrites and keeps the `@`.** Confirms `ErrorSuppress` wrapping is preserved.

3. **Mutable-scope poisoned — new path still rewrites `$GLOBALS["f"](...)` even when global mutability is flipped.**
   ```
   INPUT
   $f = 'strrev';
   function poison() {
       $GLOBALS['unrelated'] = something();
   }
   $g = function ($x) {
       return $GLOBALS['f']($x);
   };
   OUTPUT
   $f = 'strrev';
   function poison()
   {
       $GLOBALS['unrelated'] = something();
   }
   $g = function ($x) {
       return strrev($x);
   };
   ```
   Confirms the bypass: the `poison()` body marks the global scope mutable, but `$GLOBALS['f']($x)` inside the closure is still rewritten. (`something()` is left intact because it's not in any reducer registry.)

4. **Validation rejection — non-identifier value is left alone.**
   ```
   INPUT
   $f = 'echo 1;';
   $g = function () { $GLOBALS['f'](); };
   OUTPUT
   $f = 'echo 1;';
   $g = function () {
       $GLOBALS['f']();
   };
   ```

5. **Unknown global — silent skip.** `$GLOBALS["never_assigned"]()` stays unchanged.

6. **Manual smoke check on the real sample (not a committed fixture).** Re-run `php index.php -f .../bWNAdf.php` before merging and confirm the count of `GLOBALS["` occurrences in the output drops from 142 to ~0. The before/after counts go in the commit message.

The four existing fixture files (`reducers.txt`, `variables.txt`, `goto-tests.txt`, `filesystem.txt`) must continue to pass — the new path only fires when the existing path failed, so no regressions are expected.
