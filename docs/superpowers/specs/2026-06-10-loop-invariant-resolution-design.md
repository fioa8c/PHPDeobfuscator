# Loop-Invariant Variable Resolution — Design

Date: 2026-06-10

## Problem

Value-dependent reductions do not fire inside loop bodies. Concretely, a variable
holding a function name (`$f = "str_repeat"; … $f($x, 3)`) is rewritten to the real
call (`str_repeat($x, 3)`) at top level but left untouched inside a `foreach`/`for`/
`while`/`do`. `samples/hidden.php` is the motivating case: four function-name
variables are assigned once and then called from inside two nested `foreach` loops.

## Root cause

On entering a loop, `Resolver` calls `setCurrentVarsMutable()`, which marks **every**
in-scope variable mutable. A mutable `ValRef` throws `MutableValueException` from
`getValue()` (`src/ValRef/AbstractValRef.php`), so `Utils::getValue($node->name)` in
`FuncCallReducer::reduceFunctionCall` fails and the variable call is left as-is. The
blanket marking is the conservative "values may change across iterations" guard, but
it also poisons variables that are never written in the loop.

## Approach: selective mutability

On entering a loop, compute the set of variable names that may be reassigned within
**one iteration** of that loop and mark only those mutable. Variables that are read
but never written keep their concrete value and remain reducible.

Rejected alternatives:
- Special-casing only the function-name callee — too narrow; helps nothing else.
- A standalone assigned-once dataflow pass — larger and redundant with the existing
  scope/mutability machinery.

## The "mutated names" analysis

`Resolver::loopMutatedNames(Node $loop): ?array` walks the **repeated** parts of the
loop:
- `Foreach_`: key/value targets + `stmts`.
- `For_`: `cond` + `loop` + `stmts` (excludes the once-only `init`).
- `While_`: `cond` + `stmts`. `Do_`: `stmts` + `cond`.

It collects the base variable names of assignment targets:
- `Assign`, `AssignRef`, `AssignOp`; `PreInc`/`PreDec`/`PostInc`/`PostDec`.
- `unset`, `global`, `static` statements.
- `foreach` key/value targets (nested loops included).
- `list()` / `[]` destructuring targets.
- Base variable of `$a[..] = …` and `$o->p = …` (ArrayDimFetch / PropertyFetch).
- By-ref `use (&$x)` on a nested closure (the closure may mutate `$x` when invoked).

It does **not** descend into nested closure / arrow-function / function / class bodies
(those have their own scope). RHS expressions and array-dimension expressions are still
walked so embedded assignments are caught.

## Safety fallback

The analysis returns `null` (→ caller falls back to the existing blanket
`setCurrentVarsMutable()`) when the body contains an unanalysable mutation:
- Variable-variable assignment target (`$$x = …`, dynamic property/var names).
- `extract()`, `parse_str($s)` / `mb_parse_str($s)` with a single argument (these
  create arbitrary locals). This mirrors the existing `onFuncCall` handling.
- Any assignment-target shape the analysis does not recognise (fail safe).

## Edit surface

Confined to `src/Resolver.php`:
- `setCurrentVarsMutable(?array $names = null)` — `null` marks all (unchanged);
  an array marks only the named in-scope variables (via `Scope::getVariables()`).
- The `For_` / `Foreach_` / `While_` / `Do_` branches compute and pass the set
  (threaded through the existing `enterMutableContext` deferral mechanism so loop
  headers/init still evaluate in the pre-loop state).
- `If_` / `Switch_` / `Case_` / `Label` keep the blanket behavior — conditional-
  execution uncertainty is a separate concern.

## Known limitation (unchanged)

By-ref function arguments (`sort($a)`, `settype($x, …)`) are not modelled as writes —
the tool does not track by-ref mutation anywhere, including at top level, so loop
behavior stays consistent with top-level behavior. Not addressed here.

## Out of scope

Dead-assignment removal. The now-unused `$var = "funcname"` lines in `hidden.php`
remain in the output; only the calls are rewritten.

## Verification

Test-first via `tests/variables.txt`:
- A variable-function call inside a `foreach` resolves to the real function name.
- A variable read (not written) inside a loop reduces; one written in the loop does not.
- An `extract()` in the loop body suppresses reduction (safety fallback).

Then confirm `samples/hidden.php`'s four calls resolve and `php test.php` stays green.
