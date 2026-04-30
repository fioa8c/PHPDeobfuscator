# Extending PHPDeobfuscator: A Researcher's Field Guide

> A guide for analysts and reverse engineers who want to teach this tool how
> to peel a *new* family of PHP malware. We start with one-line wins and end
> with full custom node-rewriting passes. The running case study is
> `samples/381_orig.php` — a real-world WordPress dropper that hides every
> identifier behind base64, a stuffing character, and a giant runtime
> dispatch table.

---

## 0. Mental model in 60 seconds

Open `src/Deobfuscator.php`. The whole pipeline is right there in
`__construct` and `deobfuscate`:

```
parse() ──> firstPass ──> closurePrepass ──> secondPass ──> prettyPrint()
              │              │                    │
              │              │                    └─ Resolver
              │              │                       ResolveValueVisitor
              │              │                       ReducerVisitor    ◀─── most plugins live here
              │              │                       MetadataVisitor (optional)
              │              │
              │              └─ ClosureRegistryPrepass
              │
              └─ ControlFlowVisitor (rewrites goto/label spaghetti)
```

Three layers of "plug in a thing":

| Layer | What it does | When to add one |
|-------|--------------|-----------------|
| **NodeVisitor** (PHP-Parser's built-in concept) | Walks every node. Sees the whole tree. Heaviest hammer. | You need to rewrite *structure* — control flow, scope, prepass setup. Example: `ControlFlowVisitor`. |
| **Reducer** (this project's term) | Owns specific AST node *classes* (e.g. `Expr\BinaryOp\Concat`). Returns a replacement node. | You want to constant-fold or simplify a kind of expression. Example: `BinaryOpReducer`, `MagicReducer`. |
| **FunctionReducer plugin** | Owns specific PHP *function names* (e.g. `base64_decode`). Receives evaluated arguments. | You want to teach the engine to symbolically execute a named PHP function. **This is the layer you'll touch 90% of the time.** |

When in doubt, start at the bottom of that table. Move up only when the
shape of what you want to do doesn't fit.

---

## 1. The case study: `samples/381_orig.php`

Open it. The first 200 lines look like this:

```php
$lyzrnvxwhnsg = 'a<H<R0cHM6<Ly<9l<';
$ksayuy       = 'dA=';
$xpqlfu       = "lL";
$qyuogrijapry = '<V<F9Q<';
...
$cjjkyp       = "ba<se6<4_de<code";
...
$ptaving      = "<ar<ra<y_map";
...
$GLOBALS['hrwpjggtjx'] = "<";
```

Then it builds a giant array of obfuscated strings, decodes them all in one
shot, and stashes the result on the globals so every later call site can
look up function names by index:

```php
function faeriqmn($s) {
    return str_replace($GLOBALS['hrwpjggtjx'], "", $s);   // strip the "<" stuffer
}
$swgsykpvzde = faeriqmn($cjjkyp);                          // "base64_decode"
$juzjcqlmtp  = faeriqmn($ptaving);                         // "array_map"
$kdrxyztj    = $juzjcqlmtp('faeriqmn', $clyqcygzupmt);    // strip "<" from every entry
$GLOBALS['eouodsoyt'] = $juzjcqlmtp($swgsykpvzde, $kdrxyztj); // base64_decode every entry
```

Every later call in the file looks like

```php
$puxujy = $GLOBALS['eouodsoyt'];
@$puxujy[240]($somefile);          // file_exists
$puxujy[212]("...");               // some other PHP function
```

So the dropper is using **six** distinct obfuscation tricks, all stacked:

1. **Stuffing character.** Every base64 string is sprinkled with `<` characters. Strip them and the b64 is valid.
2. **Function names hidden in strings.** `"ba<se6<4_de<code"`, `"<ar<ra<y_map"`.
3. **Concatenation chains.** `"<Z2V<0X<3Jl<c<3V" . "sdH" . $wdvait` — many fragments concatenated to form one identifier.
4. **`array_map` over a user callback** to mass-decode an array.
5. **Indexed dispatch table.** Names live in `$GLOBALS["eouodsoyt"][N]` and get called as `$table[N](...)`.
6. **`@`-suppressed indirect calls.** Most call sites have `@` so failed deobfs don't crash the original payload at runtime.

Patterns 1–3 and the indirect `$GLOBALS["literal"](...)` pattern are already
handled by what's in `src/`. Patterns 4 and 5 are exactly the kind of thing
you'd extend the tool to handle. We'll get there.

---

## 2. Tooling you'll actually use

```bash
composer install                           # once
php -d error_reporting=E_ALL test.php      # run the whole fixture suite
php index.php -f samples/381_orig.php      # deobfuscate a sample, write to stdout
php index.php -f samples/381_orig.php -t   # also dump the post-pass node tree
php index.php -f samples/381_orig.php -o   # annotate each reduced expr with its source
```

Workflow that scales:

1. Reproduce the obfuscation pattern in a *minimum* PHP file. Five lines, no
   noise.
2. Add it as an `INPUT` / `OUTPUT` block in `tests/reducers.txt` (or pick the
   right fixture by topic — `tests/globals.txt`, `tests/variables.txt`,
   `tests/filesystem.txt`, `tests/goto-tests.txt`). Run `php test.php`. Watch
   it fail.
3. Make the smallest change to the codebase that turns it green.
4. Re-run `php index.php -f samples/<real-sample>.php` and diff against the
   prior output. The fixture protected you from regressing the rest of the
   suite.

The fixture format is plain text. Each test is `INPUT\n...\nOUTPUT\n...`,
optionally followed by `ANALYSIS\n...`. Multiple tests per file are stacked
back-to-back. See `test.php` for the parser; it's 100 lines.

---

## 3. Walkthrough A — the one-liner: register a known-pure function

**Goal:** the sample calls `gzdecode` (and dozens like it) through the
dispatch table. Once names are resolved, the engine sees `gzdecode($blob)`
with `$blob` as a known-string `ValRef`. We want it to actually run.

`gzdecode` is a *pure* PHP function: no I/O, no globals, output depends only
on inputs. Those go in **`PassThrough`** — `src/Reducer/FuncCallReducer/PassThrough.php`.

```php
public function getSupportedNames()
{
    return array(
        'base64_decode',
        // ...
        'gzdecode',          // ← add this line
        // ...
    );
}
```

That's the entire patch. The dispatch in `execute()` is generic
(`call_user_func_array($name, ...)`), so any function whose semantics match
"call the real PHP impl with the real values" is a one-line add.

**Test it.** Append to `tests/reducers.txt`:

```
INPUT
echo gzdecode(hex2bin('1f8b0800000000000003cb48cdc9c907008b1eebe303000000'));

OUTPUT
echo "Hello";
```

`php test.php` should print `pass` for that case.

**Why this works:** `FuncCallReducer::reduceFunctionCall`
(`src/Reducer/FuncCallReducer.php:36`) hands every recognised function call
to its registered plugin. The plugin only runs if the engine has already
proved values for *all* arguments. If even one arg is unknown, the call
stays put — safety by default.

**When it doesn't work:** if the function has side effects (writes a file,
opens a socket, mutates a global), `PassThrough` is *wrong*. Use the
sandbox or write a custom plugin instead.

---

## 4. Walkthrough B — the convention-based add: a sandboxed pure function

`FunctionSandbox` (`src/Reducer/FuncCallReducer/FunctionSandbox.php`) does
the same job as `PassThrough` but with a much nicer registration trick: it
auto-discovers any method named `_sandbox_<funcname>` and exposes it as the
implementation of the PHP function `<funcname>`.

The class is currently a clean slate — perfect classroom example. Suppose
you find a sample (or a future variant of `381_orig.php`) that does:

```php
$rotated = str_rot13(strrev($blob));   // already handled, both in PassThrough
$tweaked = my_custom_xor($rotated, 0xAA);
```

`my_custom_xor` doesn't exist as a real PHP function, but the obfuscator
has defined it inline. You can give the engine a sandboxed implementation:

```php
// src/Reducer/FuncCallReducer/FunctionSandbox.php

class FunctionSandbox implements FunctionReducer
{
    // ... existing getSupportedNames() / execute() unchanged ...

    private function _sandbox_my_custom_xor($input, $key)
    {
        $out = '';
        for ($i = 0, $n = strlen($input); $i < $n; $i++) {
            $out .= chr(ord($input[$i]) ^ $key);
        }
        return $out;
    }
}
```

Done. `getSupportedNames()` reflects on `_sandbox_*` methods and registers
the suffix. `execute()` calls the matching method with raw values
(`Utils::refsToValues`) and wraps the return in an AST node
(`Utils::scalarToNode`).

**Use this layer when:**
- The function is pure but PHP doesn't ship it (custom math, custom hash).
- You want a deterministic stand-in that's easier to audit than the real
  implementation.
- The real implementation is *too* permissive and you want a stricter one
  for analysis.

---

## 5. Walkthrough C — a real plugin: making `array_map` collapse the dispatch table

This is the one that actually unlocks `381_orig.php`.

### What the engine already does

By the time the second pass walks the tree, `Resolver` knows
`$swgsykpvzde === "base64_decode"` and `$juzjcqlmtp === "array_map"`. So
`FuncCallReducer::reduceFunctionCall` (`src/Reducer/FuncCallReducer.php:36`)
unwraps the variable-as-name pattern and we're left with literally:

```php
array_map('faeriqmn', $clyqcygzupmt)
```

`array_map` isn't in `PassThrough` for a reason: its callback can be a
**user-defined** function (`faeriqmn`) whose body lives in this very file.
PHP's real `array_map` would need that function to exist at runtime — but
we're not at runtime, we're statically rewriting an AST.

### What to build

A new `FunctionReducer` plugin that:
1. Recognises `array_map`.
2. Requires the callback argument to be a literal string naming a function
   the deobfuscator can resolve.
3. Requires the array argument to have a fully-known `ValRef`.
4. Walks the array, evaluates the callback on each element using the
   existing `EvalReducer`, and emits a fully reduced array literal.
5. Bails out (returns `null` / leaves the call alone) if any of those
   conditions fail.

### Where it goes

You can put it directly in `MiscFunctions`
(`src/Reducer/FuncCallReducer/MiscFunctions.php`) — that class is the home
for "needs the `Resolver` and/or `EvalReducer`" plugins, which this one
does. Or write a fresh `ArrayMap` plugin and register it in
`Deobfuscator::__construct`. The architecture allows both.

### Sketch

```php
// src/Reducer/FuncCallReducer/MiscFunctions.php

public function getSupportedNames()
{
    return array(
        'preg_replace',
        'reset',
        'create_function',
        'array_map',     // ← add this line
    );
}

public function execute($name, array $args, FuncCall $node)
{
    $args = Utils::refsToValues($args);
    switch ($name) {
        // ... existing cases ...
        case 'array_map':
            return $this->staticArrayMap($args[0], $args[1]);
    }
}

private function staticArrayMap($callback, $items)
{
    if (!is_string($callback) || !is_array($items)) {
        return null;
    }
    // Reject things like 'system' or 'eval' early — we are NOT executing arbitrary PHP.
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $callback)) {
        return null;
    }

    $out = array();
    foreach ($items as $key => $val) {
        if (!is_scalar($val) && !is_null($val)) {
            return null;        // give up; can't symbolically pass non-scalars yet
        }
        $valSrc = var_export($val, true);
        try {
            // Reuse the eval pipeline: parse a tiny PHP snippet that calls the callback,
            // run it through the full deobf pass, and read the reduced return value.
            $stmts  = $this->evalReducer->runEvalTree("return {$callback}({$valSrc});");
            $expr   = $stmts[0]->expr;
            $out[$key] = Utils::getValue($expr);
        } catch (\Throwable $e) {
            return null;        // anything weird? bail safely
        }
    }
    return Utils::scalarToNode($out);
}
```

A few things worth pointing out for *why* this works the way it does:

- **`runEvalTree`** is the existing trick `MiscFunctions::createFunction`
  uses to fold a synthetic snippet through the *whole* deobf pass —
  Resolver, ResolveValueVisitor, ReducerVisitor, the lot. So when we ask it
  to reduce `return faeriqmn("a<H<R0...");`, the sample's own
  `faeriqmn` definition (registered earlier by the Resolver) is in scope.
  This is the same superpower `preg_replace`-with-`/e` uses to defang
  `preg_replace_callback` payloads.
- **Conservative bailouts.** Every `return null` is intentional. The engine
  treats `null` as "I declined to reduce; leave the call site alone." Better
  to surface obfuscation than emit incorrect output.
- **Mutability.** If `Utils::getValue` throws a `MutableValueException`,
  `ReducerVisitor::leaveNode` catches it (`src/ReducerVisitor.php:48`) and
  drops the reduction silently. You don't have to defend against that
  yourself; it's the engine's contract.
- **Identifier whitelist.** The regex on `$callback` is a defense-in-depth
  guard so a malicious sample can't trick us into static-evaluating
  something we don't intend.

### Drop a fixture

```
INPUT
function strip_x($s) { return str_replace('x', '', $s); }
$out = array_map('strip_x', array('axbxc', 'dxe', 'fghx'));

OUTPUT
function strip_x($s)
{
    return str_replace("x", "", $s);
}
$out = array(0 => "abc", 1 => "de", 2 => "fgh");
```

Now point the tool at `samples/381_orig.php` again. The dispatch table
should fold into a real array literal and every later
`$puxujy[240]($file)` becomes a recognisable `file_exists($file)`.

---

## 6. Walkthrough D — a top-level Reducer for a new node class

Sometimes the obfuscation isn't around a function call at all. It's around
an *expression shape*. Examples already in the tree:

| File | What it owns |
|------|--------------|
| `src/Reducer/BinaryOpReducer.php` | every `Expr\BinaryOp\*` (Concat, Plus, Mod, Identical, …) |
| `src/Reducer/UnaryReducer.php` | casts, `++`/`--`, unary minus |
| `src/Reducer/MagicReducer.php` | `__CLASS__`, `__FILE__`, `__LINE__`, etc. |
| `src/Reducer/MiscReducer.php` | encapsed strings, ternary, echo, print, return |

These all extend `AbstractReducer`. Read it
(`src/Reducer/AbstractReducer.php`) — it's 47 lines:

> Any method named `reduce<Anything>(SomeNode $node)` is automatically
> registered as the handler for the `SomeNode` class.

That's the whole convention. Adding a handler for a node class the engine
doesn't yet fold is mechanical:

```php
// hypothetical: fold isset() of a known-undefined variable to false
// in MiscReducer.php

public function reduceIsset(Node\Expr\Isset_ $node)
{
    foreach ($node->vars as $var) {
        try {
            Utils::getValue($var);   // throws if unresolved
        } catch (Exceptions\UnknownValueException $e) {
            return null;             // can't decide, leave it
        }
    }
    return Utils::scalarToNode(true);
}
```

**Rules of the road:**

- One node class, one reducer. `ReducerVisitor::addReducer`
  (`src/ReducerVisitor.php:14`) and `FuncCallReducer::addReducer`
  (`src/Reducer/FuncCallReducer.php:25`) both throw on conflict. If you need
  to "extend" an existing reducer, add a method to *that* class.
- Return `null` (or just `return;`) to leave the node untouched.
- Return a new `Node` to replace it.
- Return a `MaybeStmtArray` to optimistically replace with a *list* of
  statements (only honoured when the parent is `Stmt\Expression`). See
  `UnaryReducer::postIncDec` and `src/MaybeStmtArray.php` for the details.
- Throw `Exceptions\BadValueException` to abort this reduction quietly —
  `ReducerVisitor::leaveNode` swallows it.

---

## 7. Walkthrough E — a NodeVisitor for control-flow rewrites

Reserved for when you need access to *parents*, *siblings*, or *order of
statements* — things a per-node reducer can't see.

The canonical example here is `src/ControlFlowVisitor.php`, which collapses
`goto`/label obfuscation by building a `CodeBlock` graph from the
statement list and re-emitting structured control flow. That's 400+ lines
of work because you're literally inverting a compiler pass.

A more modest custom NodeVisitor might:
- Tag every node inside a known-dead branch so reducers skip it.
- Pre-collect every closure literal under `$GLOBALS["..."] = function...;`
  (this is exactly what `ClosureRegistryPrepass` does — read it, it's
  60 lines).
- Inline a known-aliased `$puxujy = $GLOBALS["eouodsoyt"];` so subsequent
  reducers see the canonical name.

The contract: subclass `\PhpParser\NodeVisitorAbstract`, implement
`enterNode` / `leaveNode`, and add yourself to `firstPass`,
`closurePrepass`, or `secondPass` in `Deobfuscator::__construct` — choose
the pass based on what other state you depend on. NodeVisitors run *in
registration order* per pass.

---

## 8. The data model you'll bump into

### `ValRef` — symbolic value

`src/ValRef.php` and the seven implementations under `src/ValRef/`. A
`ValRef` is "the engine's best knowledge about the value of this
expression." Implementations:

| Class | Represents |
|-------|------------|
| `ScalarValue` | int / float / string / bool / null |
| `ArrayVal` | array of `ValRef`s |
| `ObjectVal` | object with property `ValRef`s |
| `ByReference` | aliasing |
| `GlobalVarArray` | the `$GLOBALS` superglobal specifically |
| `ResourceValue` | file handles, etc. (mostly opaque) |
| `UnknownValRef` | "I know it exists but not what it is" |

Every `ValRef` carries an `isMutable()` flag. If the Resolver decides a
variable could be reassigned in some branch it hasn't explored, it marks
its current value mutable. Calling `getValue()` on a mutable `ValRef`
throws `MutableValueException`, which the visitor catches and turns into
"don't reduce this." That's the safety mechanism that keeps the deobf
faithful even with sloppy approximations.

Helpers:
- `Utils::getValueRef($node)` — pull the attached `ValRef`, or throw.
- `Utils::getValue($node)` — `getValueRef` + `->getValue()`.
- `Utils::refsToValues($args)` — unwrap an array of `ValRef`s.
- `Utils::scalarToNode($v)` — build the right `Node\Scalar\*` (or
  `Expr\Array_`, `Expr\ConstFetch` for null/true/false) and stamp it with a
  `ValRef` attribute so downstream passes can read it back.

### `VarRef` — symbolic variable location

`src/VarRef.php` and `src/VarRef/`. Same idea but for *locations*:
`LiteralName`, `ArrayAccessVariable`, `PropertyAccessVariable`, etc. You
rarely interact with these unless you're writing a new visitor that has
to assign or read variables.

### `Scope`

`src/Scope.php`. A symbol table per lexical scope, plus a parent pointer.
Cloning a scope deep-clones values, which is how the engine speculatively
explores branches without polluting state.

### `AttrName`

`src/AttrName.php`. The single source of truth for node-attribute keys
(`VALUE`, `REDUCED_FROM`, `IN_EXPR_STMT`). If you need a new attribute,
add the constant here so it's discoverable.

---

## 9. Common traps

- **Forgetting to lowercase function names.** `FuncCallReducer` lowercases
  before lookup (PHP function names are case-insensitive). Your plugin's
  `getSupportedNames()` must return lowercase strings. Otherwise your
  handler silently never fires.
- **Returning a node without a `ValRef` attribute.** `Utils::scalarToNode`
  attaches the attribute for you. If you build a node by hand, downstream
  reducers can't see its value. Prefer `scalarToNode`.
- **Returning a node that's the same object as `$node`.** PHP-Parser
  treats that as a no-op. If you need to *mutate in place*, mutate; if you
  need to *replace*, return a fresh node.
- **Mutating arguments.** `Utils::refsToValues` returns *values*, but those
  values may be arrays or objects from the engine's state. Don't write
  back into them. Build fresh outputs.
- **Adding a reducer for a class another reducer already owns.**
  `addReducer` throws at construction time. Move the method to the
  existing reducer.
- **Naming `_sandbox_X` for a function PHP itself doesn't have.** Totally
  fine — but make sure your implementation is correct, deterministic, and
  side-effect-free. Sandbox methods are a *contract*: they may be invoked
  many times across the pass.
- **Side effects in a Reducer.** Reducers run inside `leaveNode` of a
  `NodeTraverser`. Don't do I/O. Don't print. Don't mutate global state.
  If you need stateful behaviour (e.g. a registry of seen closures), tuck
  it into a NodeVisitor in the appropriate pass and have the reducer read
  from it via constructor injection.

---

## 10. Researcher's checklist for a new sample

1. Run `php index.php -f <sample>.php > out.php`. Diff against the original.
2. Find the *first* construct the tool refused to fold. That's your target.
3. Reduce it to a 5-line PHP fixture. Append to the appropriate
   `tests/*.txt`. Confirm `php test.php` reports it as failing.
4. Decide which layer:
   - Pure named function? → `PassThrough`, one line.
   - Pure but PHP doesn't ship it? → `_sandbox_<name>` in `FunctionSandbox`.
   - Needs the resolver / eval pipeline (callbacks, scope, code from the
     sample itself)? → method on `MiscFunctions`, or a new
     `FunctionReducer` plugin.
   - Folding a *kind of expression*, not a function call? →
     `reduce<Class>` method on the appropriate `Reducer` subclass.
   - Rewriting *structure* (control flow, prepass)? → new `NodeVisitor`,
     wired in `Deobfuscator::__construct`.
5. Implement. Re-run `php test.php` until everything is green.
6. Re-run on the real sample. Diff against the previous output. Look for
   regressions in *other* parts of the file — your change can fold the
   thing you wanted *and* break something subtle elsewhere.
7. Commit the fixture together with the code change. Future-you and future
   researchers will thank present-you.

---

## 11. Where to look next

- **`src/Deobfuscator.php`** — wiring. Every pass and every plugin is
  registered here. If you can read this file, you can navigate the rest.
- **`src/Reducer/AbstractReducer.php`** — the reflection-based registration
  trick. 47 lines, worth understanding line by line.
- **`src/Reducer/FuncCallReducer.php`** — the targeted-fallback methods at
  the bottom (`resolveGlobalsLiteralName`, `resolveGlobalsLiteralClosureCall`)
  are master-class examples of "narrow, opt-in, well-commented" workarounds
  for real-world obfuscation patterns. When you need to do something
  similar, model your plugin on these — including the comments explaining
  *why* the bailout conditions exist.
- **`tests/`** — every `*.txt` is both regression coverage and a tutorial
  on what the engine currently can and cannot do.
- **`samples/`** — keep specimens here. Big files are fine; this is an
  analysis tool.

Now go fold something.
