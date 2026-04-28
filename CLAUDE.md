# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

PHP source-code deobfuscator that statically reduces obfuscated PHP by symbolically executing what it can prove safe and rewriting the AST. Built on `nikic/PHP-Parser` v4. Requires PHP 8.0+; the Dockerfile pins `php:8.5-cli-bookworm`.

## Commands

- Install deps: `composer install`
- Run the test suite: `php test.php` (preferred: `php -d error_reporting=E_ALL test.php`). The script discovers every `tests/*.txt` file, runs each `INPUT`/`OUTPUT` block through the full pipeline, and prints `pass`/`failed` per case. There is no PHPUnit, no `--filter`; to run a single case temporarily edit `test.php` or move other test files aside.
- Deobfuscate a file from CLI: `php index.php -f <file> [-t] [-o]` (`-t` dumps the resulting node tree; `-o` annotates each reduced expression with its original source).
- Web entrypoint: `index.php` also serves a simple textarea form when accessed via SAPI.
- Docker: `docker build -t phpdeobf . && docker run --rm phpdeobf` runs `php index.php` inside the container.

## Architecture

The deobfuscator is a two-pass AST rewrite around PHP-Parser. `Deobfuscator::deobfuscate()` (`src/Deobfuscator.php`) wires it all together:

1. **First pass — `ControlFlowVisitor`** rewrites `goto`/label control-flow obfuscation by building `CodeBlock` graphs (`src/ControlFlowVisitor.php`) and re-serialising them into structured statements.
2. **Second pass — a stack of visitors run in order:**
   - `AddOriginalVisitor` (only when `-o` / `$dumpOrig`) snapshots each node's original source.
   - `Resolver` tracks lexical scope, name scope (namespace/class/function/method/trait), constants, and rewrites `Expr\AssignOp` into `Assign(BinaryOp)`. It owns the `Scope` chain and resolves any variable expression to a `VarRef`.
   - `ResolveValueVisitor` opportunistically attaches a `ValRef` to every `Expr` it can prove a value for, stored as the `AttrName::VALUE` node attribute. Downstream reducers consume that attribute via `Utils::getValue` / `Utils::getValueRef`.
   - `ReducerVisitor` dispatches each node to a registered `Reducer` keyed by node class. Reducers may return a replacement node or a `MaybeStmtArray` (a sentinel that gets unfolded into multiple statements only when the parent is `Stmt\Expression`).
   - `MetadataVisitor` (only with `$annotateReductions`) annotates the printed output with the original code per reduction.

`ExtendedPrettyPrinter` handles the fake nodes (`EvalBlock`, etc.) when emitting source.

### Reducers

`Reducer` (interface in `src/Reducer.php`) declares which node classes it handles via `getNodeClasses()`. `AbstractReducer` (`src/Reducer/AbstractReducer.php`) implements this with reflection: **any method named `reduce<Anything>(SomeNode $node)` is auto-registered as the handler for `SomeNode`.** Each node class can only be claimed by one reducer — `ReducerVisitor::addReducer` and `FuncCallReducer::addReducer` both throw on conflict. To add support for a new node type, add a `reduce*` method on the appropriate reducer (or add a new reducer in `Deobfuscator::__construct`).

Top-level reducers: `BinaryOpReducer`, `EvalReducer`, `FuncCallReducer`, `MagicReducer`, `UnaryReducer`, `MiscReducer`.

### FuncCallReducer plugins

`FuncCallReducer` is itself a registry of `FunctionReducer` plugins (`src/Reducer/FuncCallReducer/`) keyed by lowercased PHP function name:

- `FunctionSandbox` — whitelisted pure functions, **registered by convention**: any method named `_sandbox_<funcname>` is exposed as the reducer for that PHP function. To add a new safe-to-execute function, add a `_sandbox_<name>` method.
- `FileSystemCall` — file functions routed through the in-memory `League\Flysystem` filesystem owned by `Deobfuscator` (use `getFilesystem()` to seed inputs; `index.php` writes the source under `/var/www/html/<basename>`).
- `MiscFunctions` — functions that need access to the `Resolver` or `EvalReducer` (e.g. `create_function`, things that introspect scope).
- `PassThrough` — functions whose name is recognised but whose call we leave intact (so other reducers know not to touch them).

Reducers receive `ValRef[]` arguments; `Utils::refsToValues` unwraps them and `Utils::scalarToNode` converts return values back into AST nodes carrying a `ValRef` attribute.

### Value and variable model

- **`ValRef`** (`src/ValRef.php` + `src/ValRef/`) — abstract reference to a runtime value. Implementations: `ScalarValue`, `ArrayVal`, `ObjectVal`, `ByReference`, `GlobalVarArray`, `ResourceValue`, `UnknownValRef`. Supports mutability tracking — when a reducer can't prove a value won't change it throws `Exceptions\MutableValueException` / `BadValueException`, which `ReducerVisitor` swallows to skip the reduction.
- **`VarRef`** (`src/VarRef.php` + `src/VarRef/`) — abstract reference to a variable location: `LiteralName`, `ArrayAccessVariable`, `PropertyAccessVariable`, `ListVarRef`, `FutureVarRef`, `UnknownVarRef`.
- **`Scope`** holds variable map + superglobals + parent link. Cloning a scope deep-clones values so speculative branches don't pollute state.
- **`AttrName`** centralises the node attribute keys (`VALUE`, `REDUCED_FROM`, `IN_EXPR_STMT`).

### Fake nodes

`EvalBlock` and `MaybeStmtArray` are synthetic `Expr` subclasses used internally — `EvalBlock` carries reduced `eval()` output through the tree until `ExtendedPrettyPrinter` emits it; `MaybeStmtArray` lets a reducer optimistically return statements that only get expanded when the parent context is a `Stmt\Expression`. Neither should appear in final output.

## Tests

Tests live in `tests/*.txt` as plain-text fixtures. Each file contains repeated `INPUT` / `OUTPUT` blocks separated by those literal lines. The runner prepends `<?php\n` to each input and compares the pretty-printed deobfuscation against `<?php\n\n` + the expected output. To add a test, append a new `INPUT` / `OUTPUT` pair to the relevant file (`reducers.txt`, `variables.txt`, `goto-tests.txt`, `filesystem.txt`).

When a fixture fails the runner prints the expected vs. got bodies prefixed with `[]:` per line — it does not stop on first failure.
