# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

PHP source-code deobfuscator that statically reduces obfuscated PHP by symbolically executing what it can prove safe and rewriting the AST. Built on `nikic/PHP-Parser` v4. Requires PHP 8.0+; the Dockerfile pins `php:8.5-cli-bookworm`.

## Commands

- Install deps: `composer install`
- Run the test suite: `php test.php` (preferred: `php -d error_reporting=E_ALL test.php`). The script discovers every `tests/*.txt` file, runs each `INPUT`/`OUTPUT` block through the full pipeline, and prints `pass`/`failed` per case. There is no PHPUnit, no `--filter`; to run a single case temporarily edit `test.php` or move other test files aside.
- Deobfuscate a file from CLI: `php index.php -f <file> [-t] [-o] [-a] [-j] [-c] [-x] [-e] [-u]` (`-t` dumps the resulting node tree; `-o` annotates each reduced expression with its original source; `-a`/`-j` append a security-analysis report in text/JSON; `-c` strips the input's comments, running before any pass so `-o`/`-a` annotations are preserved; `-x` enables sandboxed execution of provably pure user functions — see **Pure-function execution** below; `-e` dynamically peels nested `eval()` layers by running the sample under the php-eval-hook extension — see **Eval-hook peeling** below; `-u` removes dead code the reduced program no longer uses — see **Dead-code elimination** below). `-h`, or running with no args, prints usage; a missing/unreadable `-f` prints an error plus usage to stderr and exits non-zero (`usage()` lives in `index.php`).
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

### Security analysis (`src/Analysis/`)

A separate, optional pass invoked via `Deobfuscator::analyze()` (not part of `deobfuscate()`) — the CLI runs it on the *deobfuscated* code when `-a`/`-j` are passed, the web entrypoint via `?analyze=text|json|both`. `SecurityAnalysisVisitor` walks the tree producing `Finding`s collected into `Findings`. It is mostly a syntactic scan, plus a light **taint pass**: each dangerous sink is annotated `tainted by <source>` when its argument derives from an attacker-controlled or remote source — a superglobal, `getenv()`, a `file_get_contents`/`fopen`/… of an `http(s)`/`ftp` URL or `php://input`, or `curl_exec`. Taint is found both syntactically in the sink's argument subtree and through a best-effort per-scope forward map over simple assignments, so `$x = $_POST['c']; eval($x);` and `$c = file_get_contents('http://…'); eval($c);` are both flagged. The map follows document order and does not reason about branches, so it can miss a flow but rarely invents one. `DangerousCatalog` is the lookup table classifying sinks by category (`code_exec`, `os_exec`, `dispatch`, `deser`, …) and recognising attacker-controlled sources (superglobals, `php://input`). Each finding is tagged `auto-exec` or `in-function:<name>` for execution context. `ReportFormatter` renders `Findings` as `formatText()` / `formatJson()`.

### Pure-function execution (`src/PureFunction/`, opt-in via `-x`)

Off by default; enabled by `Deobfuscator::__construct(..., $executePureFunctions = true)` or the `-x` CLI flag. It exists for obfuscators that funnel every string through decoder functions built from loops, static lookup tables and character substitution — constructs the symbolic reducer cannot fold, and which would otherwise require reimplementing a PHP interpreter one node type at a time.

`FuncCallReducer::tryInlineUserFunction` still tries symbolic reduction first; only when that yields nothing does it fall back to `tryExecutePure`, so **default behaviour is byte-identical with the flag off**.

- `PurityAnalyzer` is the security gate and is **default-deny**: an explicit allowlist of node types and pure builtins, with anything unrecognised rejecting the whole function. It rejects globals/superglobals, dynamic calls (`$f()`), variable variables, objects, closures, callbacks, output, `eval`/`include`, byref params and more, and it recurses through the callee graph so a function is only pure if everything it calls is too. Static variables are allowed **only** in the lazy-init idiom (`static $a = null; if ($a === null) { … }`) so a static acts as a write-once memo table — otherwise inlining `function c(){static $n=0; return ++$n;}` would be unsound.
- `PureFunctionExecutor` runs approved functions in a persistent `php -n` subprocess with `disable_functions` covering process/filesystem/network/environment access, `open_basedir` confined to a throwaway worker dir, memory and execution-time caps, and a wall-clock timeout. Definitions are sent **per function** (not per dependency set) because sets overlap and re-`eval`ing a defined function is an uncatchable redeclare fatal. Every call is executed twice and a differing second result is refused as non-deterministic — a runtime backstop for gaps in the static analysis.
- Failures are classified `transient` (sandbox died/timed out — retried once, never blacklisted), `function` (non-deterministic, produced output, failed to define — function blacklisted), or `call` (threw, non-scalar result — this call only). Blacklisting on a transient failure would cascade to every dependent decoder.

### Dead-code elimination (`src/DeadCodeEliminator.php`, opt-in via `-u`)

Off by default (default output stays byte-identical). Runs as the final step of
`deobfuscate()` when the `Deobfuscator` is constructed with `$removeDeadCode`.
After the reducer inlines a decoder's output, the scaffolding is left behind
(`$q = "<base64 blob>";`, FOPO `$x = "b"; $x = "ba"; …` ladders, a
`$gz = "<binary>";` whose only use was inlined); this pass drops it. It also removes uncalled **pure** functions (decoder scaffolding left after their calls were inlined) — gated by `PurityAnalyzer`, so an unreferenced *impure* function (a possible dormant payload) is kept for the `-a` security pass, and function removal is skipped entirely when the file dispatches dynamically (`$$`, `$var()`, `call_user_func`/`create_function` with a computed callable, `function_exists`/`is_callable`).

Deliberately conservative — it is a readability pass over attacker code and must
never change behaviour. It removes ONLY a statement of the exact shape
`$plainVar = <expr>;` whose RHS is provably side-effect-free (literals,
variables, constants, pure operators — never a call/new/eval/include/assign/
closure) and whose variable is read *nowhere* in its scope (unused-variable
elimination, not full liveness). It analyses the file top level and each
function/method/closure body as independent scopes (a closure `use` and an
arrow function's captures count as reads of the enclosing scope), pins by-ref
params and superglobals, and abandons a whole scope on the first sign that
variable identity is not statically knowable there: variable-variables,
`extract`/`compact`/`get_defined_vars`/`parse_str`, `eval`, `include`/`require`,
references, `global`, `$GLOBALS`, or a static local. Because an `eval` of code
that still shares the scope is a hazard, scopes with a residual `eval` are left
untouched. Fixture coverage: `tests/dead-code.txt`. Across a 400-file corpus
sample it cleaned ~40% of files (14k dead lines) with no behavioural errors.

### Eval-hook peeling (`src/EvalHook/`, opt-in via `-e`)

Off by default. The static pipeline reduces one layer of an `eval(decode(...))`
chain and stops; malware nests these dozens deep (FOPO, "wso"/b374k,
z5encrypt). `-e` runs the sample for real under the `php-eval-hook` extension
(`/Users/fioa8c/WORK/php-eval-hook`, rebuilt for PHP 8.4) with `eval()` hooked,
capturing every string passed to `eval()` *before* it executes, then feeds each
captured layer back through the normal static pipeline for readability. Output
is a series of `// ===== eval() layer N =====` sections.

`EvalPeeler` (`src/EvalHook/EvalPeeler.php`) owns the sandbox: a separate
`php -n` process, `open_basedir` confined to a throwaway per-run dir,
`disable_functions`/`disable_classes` covering process/network/write/env and
DB/socket/archive/phar, `allow_url_*` off, memory + time caps + a wall-clock
kill. Disabled builtins get benign no-op stubs (defined without `eval()`, so
they are not themselves captured as layers) so a sample keeps running to its
next `eval()` instead of fataling on a blocked call. File reads stay enabled
(self-decoding shells read `__FILE__`) but the jail limits them to the copied
sample. The extension path defaults next to the repo; override with the
`PHPDEOBF_EVALHOOK` environment variable. When the extension is unavailable the
CLI prints a note to stderr and falls back to static-only output. `bin/evalpeel.php`
is a standalone CLI wrapper around the same library (`bin/lib/evalpeel.php`).

### Reducers

`Reducer` (interface in `src/Reducer.php`) declares which node classes it handles via `getNodeClasses()`. `AbstractReducer` (`src/Reducer/AbstractReducer.php`) implements this with reflection: **any method named `reduce<Anything>(SomeNode $node)` is auto-registered as the handler for `SomeNode`.** Each node class can only be claimed by one reducer — `ReducerVisitor::addReducer` and `FuncCallReducer::addReducer` both throw on conflict. To add support for a new node type, add a `reduce*` method on the appropriate reducer (or add a new reducer in `Deobfuscator::__construct`).

Top-level reducers: `BinaryOpReducer`, `EvalReducer`, `FuncCallReducer`, `MagicReducer`, `UnaryReducer`, `MiscReducer`.

`MiscReducer::reduceNew` resolves `new $cls(...)` to `new ActualClass(...)` when the class expression provably holds a valid class identifier — obfuscators hide the revealing classes (`mysqli`, `ZipArchive`) behind a variable.

### FuncCallReducer plugins

`FuncCallReducer` is itself a registry of `FunctionReducer` plugins (`src/Reducer/FuncCallReducer/`) keyed by lowercased PHP function name:

- `FunctionSandbox` — **registered by convention**: any method named `_sandbox_<funcname>` is exposed as the reducer for that PHP function. It currently defines none, so it contributes nothing; the effective pure-builtin whitelist is `PassThrough` (below).
- `FileSystemCall` — file functions routed through the in-memory `League\Flysystem` filesystem owned by `Deobfuscator` (use `getFilesystem()` to seed inputs; `index.php` writes the source under `/var/www/html/<basename>`). Reads larger than `FileSystemCall::DEFAULT_MAX_INLINE_BYTES` (64 KiB, overridable via the `Deobfuscator` constructor's `$maxInlineFileBytes`; a negative value disables the cap) are **not** folded into a string literal — self-replicating malware does `file_get_contents(__FILE__)`, and inlining that buries the real code under repeated copies of the still-obfuscated source without revealing anything.
- `MiscFunctions` — functions that need access to the `Resolver` or `EvalReducer` (e.g. `create_function`, things that introspect scope). Also rewrites `constant("FOO")` into a plain `FOO` constant fetch; the *value* is deliberately not substituted, since host constants (`ABSPATH`, `DB_HOST`) are defined by the application, not this file.
- `PassThrough` — despite the name, this is the real pure-builtin whitelist: `execute()` calls `call_user_func_array($name, ...)`, so listed functions are actually evaluated. Add new safe-to-execute builtins here. (Separate from `PurityAnalyzer::PURE_BUILTINS`, which governs the `-x` sandbox and is deliberately broader.)

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

A test may include an `OPTIONS` block (same INPUT/OUTPUT delimiter style) listing per-case flags: `execute-pure` constructs the `Deobfuscator` with pure-function execution enabled, and `max-inline-file=<bytes>` sets the file-read inlining cap so a fixture can exercise it without a huge input. See `tests/pure-exec.txt`, which covers both a decoder that must reduce and impure functions that must be left intact.

When a fixture fails the runner prints the expected vs. got bodies prefixed with `[]:` per line — it does not stop on first failure.
