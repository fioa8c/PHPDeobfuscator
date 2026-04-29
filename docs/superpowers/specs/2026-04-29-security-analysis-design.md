# Security analysis (sources & dangerous-function sinks) — design

**Date:** 2026-04-29
**Builds on:** the existing two-pass deobfuscation pipeline in `Deobfuscator::deobfuscate()`.

## Problem

The deobfuscator produces clean PHP source from obfuscated input, but operators have to read that output by hand to find the parts that matter for triage: where attacker-controlled input enters the script, where dangerous PHP primitives are called, and what runs at script load vs. inside a function that may never be called. For webshell and backdoor analysis these three answers are nearly always the *only* answers anyone wants.

## Goal

Add a static, post-deobfuscation analysis pass that produces a report of:

1. **Sources** — reads of attacker-controlled superglobals and pseudo-streams.
2. **Sinks** — calls to a hard-coded catalog of dangerous functions plus AST-shape sinks that aren't expressible as function names.
3. **Context** — every finding tagged `auto-exec` (reachable from the file's top without crossing a function boundary) or `in-function:<qualified-name>` (only runs if that function is invoked).

The analysis runs only when explicitly requested via a CLI flag (`-a` for text, `-j` for JSON, `-aj` for both) or web query parameter (`?analyze=text`, `?analyze=json`, `?analyze=both`). Default behavior of the tool — printing deobfuscated PHP to stdout — is unchanged when neither flag is set.

## Non-goals (v1)

- **No taint flow.** We list sources and sinks; we don't connect them. `$x = $_GET['c']; eval($x);` produces a `$_GET` source finding and an `eval` sink finding; we don't claim the source flows to the sink.
- **No cross-file analysis.** The tool still consumes one file at a time.
- **No suppression syntax** (`@phpdeobf-ignore` comments, allowlists). Add later only if needed.
- **No configurability of the dangerous catalog.** It's a hard-coded map in `src/Analysis/`. Extending it requires a code change.
- **No exit-code semantics that change for existing callers.** Analysis output is purely additive.
- **No DB-injection sinks** (`mysqli_query`, PDO `->query`). Useful only with taint flow; without it they dominate the report with noise.
- **No reflection-based sinks** (`ReflectionFunction::invoke`, etc.). Same reason.

## Architecture

One new visitor + one report formatter, behind a CLI flag. No changes to the existing reduction pipeline.

### New files

| Path | Responsibility |
|---|---|
| `src/Analysis/SecurityAnalysisVisitor.php` | `PhpParser\NodeVisitorAbstract` subclass. Maintains a function-frame stack and a `Findings` collection. On every node, applies source and sink rules and appends findings. |
| `src/Analysis/Findings.php` | Plain value object with two arrays: `sources` and `sinks`, each a list of `Finding`. |
| `src/Analysis/Finding.php` | Value object: `kind` (`source`/`sink`/`meta`), `category`, `label`, `line`, `context`, optional `note`. |
| `src/Analysis/DangerousCatalog.php` | Hard-coded map of lowercased function name → category. Plus a small predicate registry for sinks that need argument inspection (`include`/`require`/`header`/`preg_replace`). |
| `src/Analysis/ReportFormatter.php` | Two methods: `formatText(Findings): string` and `formatJson(Findings): string`. Pure formatting; no AST access. |

### Wiring

- **`Deobfuscator::analyze(array $tree): Findings`** — new method. Runs `SecurityAnalysisVisitor` over the (already-deobfuscated) tree via a fresh `NodeTraverser`, returns the `Findings`. Does **not** mutate the tree. Doesn't run unless the caller asks.
- **`index.php`** — extend `getopt('tof:')` to `getopt('tof:aj')`. After `prettyPrint()`, when `-a` and/or `-j` is set, call `Deobfuscator::analyze()` on the deobfuscated tree and append the formatted report(s) to stdout. The `-aj` combination prints text first, then a `===== Analysis (JSON) =====` divider, then JSON. The web SAPI mirrors this with `?analyze=text` / `?analyze=json` query params; `text/plain` content type is preserved.
- **`test.php`** — extend the fixture parser to recognise an optional `ANALYSIS` block per test case. Existing fixtures without the block work exactly as today.

### Why a fresh traverser, not piggyback on `ReducerVisitor`

The analysis needs context tracking (function-frame stack) the existing visitors don't carry, runs only when asked, and benefits from being independently testable. Coupling it to the reducer would entangle two unrelated concerns.

### Namespace choice

Code lives under `src/Analysis/` with namespace `PHPDeobfuscator\Analysis`. The existing top-level `src/` layout is flat (`Reducer.php`, `Resolver.php`, etc.), but the analyzer is a downstream consumer of the deobfuscated AST, not part of the reduction pipeline — the namespace makes that boundary explicit.

## Detection rules

### Sources

| Rule | Match | Label emitted | Category |
|---|---|---|---|
| Superglobal read | `Expr\Variable` whose `name` is a string equal to one of `_GET`, `_POST`, `_REQUEST`, `_COOKIE`, `_FILES`, `_SERVER`, `_ENV`, `GLOBALS` | `$_GET`, `$_POST`, etc. — with subscript if directly indexed (`$_GET['cmd']`) | `superglobal` |
| Pseudo-stream read | `FuncCall` to `file_get_contents` / `fopen` / `stream_get_contents` whose first arg is a string literal beginning with `php://input` or `php://stdin` | `file_get_contents('php://input')` etc. | `pseudo_stream` |

A superglobal read inside `isset()`/`empty()`/`array_key_exists()` is **still** recorded — those are the giveaways in webshells. The analyzer doesn't try to be clever about "look but don't use." The operator reads context from the deobfuscated source.

Variable-variable forms like `${"_GET"}` (`Expr\Variable` with an `Expr` `name` rather than a string) are **not** matched as sources in v1. The deobfuscator's `Resolver` already folds many of these to literal-name `Variable` nodes; whatever survives is rare enough to be triaged manually.

### Sinks — Layer A (name-based, via `DangerousCatalog`)

`FuncCall` whose callee is a `Name`. Lowercased name → category lookup:

```
code_exec    eval (also Expr\Eval_), assert, create_function
             preg_replace, preg_filter   (with predicate, see Layer B)
os_exec      system, exec, shell_exec, passthru, proc_open, popen, pcntl_exec
             + Expr\ShellExec (backticks)
dynamic_inc  Expr\Include_ / Expr\Require_ / Expr\IncludeOnce_ / Expr\RequireOnce_
             (with predicate, see Layer B)
dispatch     call_user_func, call_user_func_array
             + FuncCall whose callee is not a Name (variable function: $f($x))
             + MethodCall whose name is not Identifier (variable method)
             + StaticCall whose name is not Identifier
             + New_ whose class is not a Name (new $c)
deser        unserialize
file_write   file_put_contents, fwrite, fputs, unlink, rename, copy,
             move_uploaded_file, chmod
network      curl_exec, fsockopen, pfsockopen, stream_socket_client, socket_create
mail         mail
header_inj   header   (with predicate, see Layer B)
obfusc       base64_decode, gzinflate, gzuncompress, str_rot13, hex2bin,
             convert_uudecode, pack, unpack
```

### Sinks — Layer B (argument predicates)

Applied after a Layer-A name match for these specific cases:

- **`include` / `require` / `include_once` / `require_once`** — skip the finding if argument is a `Scalar\String_` literal. Static includes are uninteresting. When the include is dynamic, the finding gets `note: "non-literal arg"`.
- **`header`** — skip the finding if first argument is a `Scalar\String_` literal. Benign content-type/cache headers shouldn't be flagged. Dynamic-arg `header` calls get `note: "non-literal arg"`.
- **`preg_replace` / `preg_filter`** — flag only if the first argument is a string literal whose pattern modifiers contain `e`, OR if the first argument is non-literal (we can't prove it's safe). When flagged because of the `e` modifier, `note: "e modifier"`. When flagged because the pattern is non-literal, `note: "non-literal pattern"`.

### Context tagging

Every finding carries a `context` string computed from the function-frame stack:

- Empty stack → `auto-exec`
- Otherwise → `in-function:<qualified-name>` of the innermost frame, where:
  - free function → `funcName` (with namespace prefix if declared inside a `namespace` block, e.g. `Foo\bar`)
  - method → `Class::method` (with namespace prefix on the class if applicable; e.g. `Foo\Bar::baz`. Trait/interface methods use the same form: `TraitName::method`, `InterfaceName::method`)
  - closure → `{closure@line:N}`
  - arrow function → `{fn@line:N}`
  - anonymous class methods get a synthetic `class@line:N` segment in the qualified name (e.g. `class@line:7::handle`)

The visitor pushes a frame on entering `Function_`, `Closure`, `ArrowFunction`, `ClassMethod` and pops on leaving. Conditionals, loops, and `try` blocks at the file's top do **not** push frames — code inside them is still `auto-exec` because it runs on script load.

## Output formats

### Text (`-a`)

Appended after the deobfuscated source, separated by a blank line. Three sections in fixed order. Empty sections render with `(none)` for predictable grep/awk parsing:

```
===== Analysis =====

Sources (3):
  [auto-exec]                     line 4   $_REQUEST['c']
  [auto-exec]                     line 4   $_SERVER['HTTP_USER_AGENT']
  [in-function:handle]            line 12  $_POST['data']

Sinks (4):
  [auto-exec]      code_exec      line 4   eval
  [auto-exec]      os_exec        line 7   system
  [in-function:handle]  deser     line 14  unserialize
  [in-function:handle]  dynamic_inc line 18  include (non-literal arg)

Summary: 3 sources, 4 sinks (4 auto-exec out of 7 total findings). Categories present: code_exec, os_exec, deser, dynamic_inc.
```

Findings sorted by `(context-rank, line)` — `auto-exec` ranks before any `in-function:*`; within the same rank, ascending line.

### JSON (`-j`)

Replaces the trailing report with a single JSON document on its own line, after a blank line. The deobfuscated source is **not** embedded in the JSON — it stays in stdout above, preserving the "default output is PHP" contract. Downstream tooling can split on the blank-line boundary or call the tool twice.

```json
{
  "version": 1,
  "filename": "input.php",
  "sources": [
    {
      "category": "superglobal",
      "label": "$_REQUEST['c']",
      "line": 4,
      "context": "auto-exec"
    }
  ],
  "sinks": [
    {
      "category": "code_exec",
      "label": "eval",
      "line": 4,
      "context": "auto-exec",
      "note": null
    },
    {
      "category": "dynamic_inc",
      "label": "include",
      "line": 18,
      "context": "in-function:handle",
      "note": "non-literal arg"
    }
  ],
  "summary": {
    "source_count": 1,
    "sink_count": 2,
    "auto_exec_count": 2,
    "categories_present": ["code_exec", "dynamic_inc"]
  }
}
```

Emitted via `json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)`.

### Combined (`-aj`)

Text report first, then `===== Analysis (JSON) =====`, then the JSON document.

### Web SAPI

Mirrors the CLI: `?analyze=text` appends the text report; `?analyze=json` appends the JSON; `?analyze=both` produces the combined form (text report, then divider, then JSON). Any other value (or absence of the parameter) skips analysis. No HTML rendering of findings in v1 — same `text/plain` content type as today.

## Testing

### Fixture format

Extend the existing `tests/*.txt` parser with an optional `ANALYSIS` block per test case. Existing fixtures without the block work exactly as today.

```
INPUT
$_GET['x'];
eval($_GET['x']);
OUTPUT
$_GET["x"];
eval($_GET["x"]);
ANALYSIS
sources:
  auto-exec  line 1  $_GET['x']
  auto-exec  line 2  $_GET['x']
sinks:
  auto-exec  code_exec  line 2  eval
```

The analyzer's output is rendered into a deterministic, simplified text form for fixture comparison — one finding per line, no column padding, no summary line. Fixture comparison is byte-exact on this simplified form. The full text report (with column alignment and summary) is exercised by a small number of dedicated fixtures.

### Coverage in v1

- One fixture per dangerous category: eval, system, unserialize, dynamic include, dispatch (variable function call), file write, network, mail, header injection with non-literal, obfuscation primitives, `preg_replace` with `/e` modifier.
- One fixture per source kind: each superglobal listed in the rules table, plus `php://input` via `file_get_contents`.
- A fixture exercising context tagging: a top-level `eval` and an in-function `eval` in the same input, asserting both `auto-exec` and `in-function:<name>` contexts appear correctly.
- A fixture covering nested function frames: a closure inside a method, with a sink in each, asserting the qualified-name format for both.
- A negative fixture: `include 'config.php';`, `header('Content-Type: text/html');`, `preg_replace('/x/', 'y', $z);` — all should be skipped by the predicates and produce an empty `ANALYSIS` block.
- A fixture for the combined-text-and-JSON output covering one realistic input, comparing the JSON via `json_decode` + recursive equality (rather than byte-exact text) so the assertion survives whitespace tweaks in `json_encode` output.

## Error handling

The analysis pass is read-only over an already-validated AST. Failure modes:

- **Unknown node shapes** (e.g., a future PHP-Parser version adds a node we don't switch on) — visitor falls through silently. Findings simply don't include something we didn't recognize.
- **Catalog lookup miss** — definition: not a sink. Skip.
- **Malformed `Resolver` output** (e.g., a `Name` we expected resolved but isn't) — fall back to `$node->name->toString()` lowercased. If even that throws, swallow and skip the node — better to underreport than crash a run.

The analyzer **never throws** out to the caller. Any internal exception is caught at the top of `Deobfuscator::analyze()`, logged via `error_log()`, and produces a `Findings` with one synthetic finding `{kind: meta, category: 'analysis_aborted', label: <message>, line: 0, context: 'auto-exec'}` plus whatever findings had been collected before the failure. The existing CLI/web behavior (printing the deobfuscated source) is unaffected.

## Out of scope (deferred)

- Taint flow connecting sources to sinks (option (2) from brainstorming — explicitly punted to a follow-up after v1 ships).
- Configurable dangerous-function catalog via external file.
- Suppression syntax for false positives.
- HTML rendering of findings in the web SAPI.
- DB-injection and reflection-based sinks (need taint flow to be useful).
- Cross-file project-level analysis (web-reachable file detection, etc.).
