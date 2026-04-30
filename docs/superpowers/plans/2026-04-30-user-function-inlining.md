# User-Defined Function Inlining Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatically inline user-defined PHP function calls so the deobfuscator can reduce chains like `faeriqmn($var)` to their scalar result without manual intervention.

**Architecture:** A new `UserFunctionPrepass` visitor (mirroring `ClosureRegistryPrepass`) scans the full AST before the main pass and registers every `Stmt\Function_` node in `Resolver`. `FuncCallReducer::makeFunctionCall` gains a fallback that, when no plugin matches a call name, looks up the registered function body, builds a synthetic closure binding the call's arguments to the function's parameters, and runs it through `EvalReducer::runEvalTree` to obtain the reduced scalar result.

**Tech Stack:** PHP 8.0+, nikic/PHP-Parser v4, existing `EvalReducer::runEvalTree`, `ExtendedPrettyPrinter`, `Utils::getValue` / `Utils::scalarToNode`.

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `src/Resolver.php` | Modify | Add `$userFunctions` registry + `registerUserFunction` / `getUserFunction` |
| `src/UserFunctionPrepass.php` | Create | Pre-pass visitor: scan AST for `Stmt\Function_`, register each one |
| `src/Deobfuscator.php` | Modify | Wire `UserFunctionPrepass` into `$this->closurePrepass` traverser |
| `src/Reducer/FuncCallReducer.php` | Modify | Add `tryInlineUserFunction`; call it as fallback in `makeFunctionCall` |
| `tests/reducers.txt` | Modify | Add two `INPUT`/`OUTPUT` fixtures for user-function inlining |

---

## Task 1: Extend `Resolver` with user-function registry

**Files:**
- Modify: `src/Resolver.php:28` (after `$globalClosures` field)
- Modify: `src/Resolver.php:280` (after `getGlobalClosure` method)

- [ ] **Step 1: Write the failing test**

Append to `tests/reducers.txt`:

```
INPUT

function addone($n) { return $n + 1; }
$x = addone(5);

OUTPUT

function addone($n)
{
    return $n + 1;
}
$x = 6;
```

- [ ] **Step 2: Run tests and confirm this new case fails**

```bash
php -d error_reporting=E_ALL test.php 2>&1 | tail -20
```

Expected: the new `addone` case prints `failed` (call is not reduced).

- [ ] **Step 3: Add the `$userFunctions` field to `Resolver`**

In `src/Resolver.php`, after line 28 (`private array $globalClosures = [];`), add:

```php
    /** @var array<string, \PhpParser\Node\Stmt\Function_> */
    private array $userFunctions = [];
```

- [ ] **Step 4: Add `registerUserFunction` and `getUserFunction` methods**

In `src/Resolver.php`, after the closing `}` of `getGlobalClosure` (currently line 280), add:

```php
    public function registerUserFunction(string $name, \PhpParser\Node\Stmt\Function_ $func): void
    {
        $this->userFunctions[strtolower($name)] = $func;
    }

    public function getUserFunction(string $name): ?\PhpParser\Node\Stmt\Function_
    {
        return $this->userFunctions[strtolower($name)] ?? null;
    }
```

- [ ] **Step 5: Verify no test regressions**

```bash
php -d error_reporting=E_ALL test.php 2>&1 | grep -E 'failed|pass'
```

Expected: same pass/fail counts as before — only the new `addone` case still fails (no inliner wired yet).

---

## Task 2: Create `UserFunctionPrepass`

**Files:**
- Create: `src/UserFunctionPrepass.php`

- [ ] **Step 1: Create the new file**

```php
<?php

namespace PHPDeobfuscator;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Pre-pass visitor that registers every named function declaration in the
 * Resolver's user-function registry before the main second pass runs.
 * This mirrors ClosureRegistryPrepass for Expr\Closure and ensures that
 * call sites for functions declared later in the source are still inlined.
 */
class UserFunctionPrepass extends NodeVisitorAbstract
{
    private Resolver $resolver;

    public function __construct(Resolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Function_) {
            $this->resolver->registerUserFunction(
                $node->name->toLowerString(),
                $node
            );
        }
        return null;
    }
}
```

- [ ] **Step 2: Verify file parses without errors**

```bash
php -l src/UserFunctionPrepass.php
```

Expected: `No syntax errors detected in src/UserFunctionPrepass.php`

---

## Task 3: Wire `UserFunctionPrepass` into `Deobfuscator`

**Files:**
- Modify: `src/Deobfuscator.php:34`

- [ ] **Step 1: Add the new visitor to the pre-pass traverser**

In `src/Deobfuscator.php`, line 34 currently reads:

```php
        $this->closurePrepass->addVisitor(new ClosureRegistryPrepass($resolver));
```

Add the new visitor immediately after it:

```php
        $this->closurePrepass->addVisitor(new ClosureRegistryPrepass($resolver));
        $this->closurePrepass->addVisitor(new UserFunctionPrepass($resolver));
```

- [ ] **Step 2: Verify no test regressions**

```bash
php -d error_reporting=E_ALL test.php 2>&1 | grep -E 'failed|pass'
```

Expected: same pass/fail as before — the pre-pass now registers functions, but `FuncCallReducer` still has no fallback, so calls are not yet reduced.

---

## Task 4: Add `tryInlineUserFunction` to `FuncCallReducer`

**Files:**
- Modify: `src/Reducer/FuncCallReducer.php:62-76` (`makeFunctionCall` and the end of the file)

- [ ] **Step 1: Replace the early-return in `makeFunctionCall`**

In `src/Reducer/FuncCallReducer.php`, the current `makeFunctionCall` (lines 62–76) is:

```php
    private function makeFunctionCall($name, $node)
    {
        if(!isset($this->funcCallMap[$name])) {
            return;
        }
        $args = array();
        foreach ($node->args as $arg) {
            $valRef = Utils::getValueRef($arg->value);
            if ($arg->byRef) {
                return; // "Call-time pass-by-reference has been removed"
            }
            $args[] = $valRef;
        }
        return $this->funcCallMap[$name]->execute($name, $args, $node);
    }
```

Replace it with:

```php
    private function makeFunctionCall($name, $node)
    {
        if(!isset($this->funcCallMap[$name])) {
            return $this->tryInlineUserFunction($name, $node);
        }
        $args = array();
        foreach ($node->args as $arg) {
            $valRef = Utils::getValueRef($arg->value);
            if ($arg->byRef) {
                return; // "Call-time pass-by-reference has been removed"
            }
            $args[] = $valRef;
        }
        return $this->funcCallMap[$name]->execute($name, $args, $node);
    }
```

- [ ] **Step 2: Add `tryInlineUserFunction` before the closing `}` of the class**

In `src/Reducer/FuncCallReducer.php`, before the final `}` on line 217, add:

```php
    /**
     * Fallback for calls to user-defined functions registered by
     * UserFunctionPrepass. Mirrors resolveGlobalsLiteralClosureCall:
     * builds a synthetic closure that binds the call's arguments to the
     * function's parameter names, runs the body through EvalReducer::runEvalTree,
     * and returns the scalar result if the body reduces fully.
     */
    private function tryInlineUserFunction(string $name, Node\Expr\FuncCall $node): ?Node
    {
        $func = $this->resolver->getUserFunction($name);
        if ($func === null) {
            return null;
        }
        if (count($func->params) !== count($node->args)) {
            return null;
        }
        foreach ($node->args as $arg) {
            if ($arg->unpack || $arg->byRef) {
                return null;
            }
        }
        foreach ($func->params as $param) {
            if ($param->variadic) {
                return null;
            }
        }

        try {
            $printer = new \PHPDeobfuscator\ExtendedPrettyPrinter();
            $bindings = '';
            foreach ($func->params as $i => $param) {
                $paramName = $param->var->name;
                if (!is_string($paramName)) {
                    return null;
                }
                $argSrc = $printer->prettyPrintExpr($node->args[$i]->value);
                $bindings .= '$' . $paramName . ' = ' . $argSrc . ";\n";
            }
            $bodySrc = $printer->prettyPrint($func->stmts);
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

- [ ] **Step 3: Run the test suite**

```bash
php -d error_reporting=E_ALL test.php 2>&1 | grep -E 'failed|pass'
```

Expected: the new `addone` fixture now passes. All previously-passing tests still pass.

- [ ] **Step 4: Commit**

```bash
git add src/Resolver.php src/UserFunctionPrepass.php src/Deobfuscator.php src/Reducer/FuncCallReducer.php tests/reducers.txt
git commit -m "feat: inline user-defined function calls in FuncCallReducer"
```

---

## Task 5: Add a second fixture covering GLOBALS-dependent function bodies

This tests the real-world obfuscation pattern (a function that references a `$GLOBALS` key that's been resolved to a known value).

**Files:**
- Modify: `tests/reducers.txt`

- [ ] **Step 1: Append the second fixture**

Append to `tests/reducers.txt`:

```
INPUT

$GLOBALS['sep'] = '-';
function glue($a, $b) { return $a . $GLOBALS['sep'] . $b; }
$r = glue('foo', 'bar');

OUTPUT

$GLOBALS['sep'] = '-';
function glue($a, $b)
{
    return $a . $GLOBALS['sep'] . $b;
}
$r = 'foo-bar';
```

- [ ] **Step 2: Run the test suite**

```bash
php -d error_reporting=E_ALL test.php 2>&1 | grep -E 'failed|pass'
```

Expected: both new fixtures pass, no regressions.

- [ ] **Step 3: Commit**

```bash
git add tests/reducers.txt
git commit -m "test: add GLOBALS-dependent user-function inlining fixture"
```

---

## Task 6: Manual smoke test on `samples/381_orig.php`

- [ ] **Step 1: Run the deobfuscator on the motivating sample**

```bash
php index.php -f samples/381_orig.php 2>&1 | head -60
```

Expected: variables like `$swgsykpvzde` are now replaced with their decoded string values (e.g. `'base64_decode'`), and `$GLOBALS['eouodsoyt']` is populated with the decoded function-name array rather than remaining as an unresolved `array_map` call.

- [ ] **Step 2: Confirm no PHP errors or fatal warnings in output**

```bash
php index.php -f samples/381_orig.php 2>&1 | grep -iE 'error|warning|fatal|exception' | head -10
```

Expected: no output (or only pre-existing warnings that were present before this change).
