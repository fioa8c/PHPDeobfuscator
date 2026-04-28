# PHP 8 Compatibility — Design

Date: 2026-04-28
Status: Approved (ready for implementation plan)

## 1. Goal & Scope

### Goal

Make PHPDeobfuscator work cleanly on modern PHP 8.x:

- `composer.json` declares `"php": "^8.0"`.
- The test suite (`test.php`) runs on PHP 8.x with **zero deprecation notices and zero warnings**.
- `Dockerfile` targets the latest stable PHP 8.x tag on a non-EOL Debian base.

### In scope

1. Fix every PHP-8-emitted deprecation/warning originating in `src/`.
2. Migrate `league/flysystem` v2 → v3 (and `flysystem-memory` accordingly) to eliminate vendor-side deprecations.
3. Declare missing properties on classes that currently rely on dynamic property creation (PHP 8.2 fix).
4. Add `"php": "^8.0"` to `composer.json`.
5. Update `Dockerfile` to `php:8.5-cli-bookworm`.

### Out of scope

- Adding `declare(strict_types=1)`, constructor promotion, typed properties beyond what the dynamic-property fix forces, readonly, enums, match, named-args sweeps.
- Switching from the homegrown `test.php` to PHPUnit.
- Any feature work, refactors, or cleanup unrelated to the issues above.
- CI configuration (no CI currently exists; not adding one).

### Decisions taken during brainstorming

- **Compatibility target.** PHP 8.0 is the floor (declared in `composer.json`); the project is also deprecation-clean on the latest 8.x.
- **Vendor deprecations.** Resolved by upgrading `league/flysystem` v2 → v3 rather than suppressing them.
- **Modernization scope.** Targeted: fix what's deprecated, declare properties for the dynamic-property fix, no broader style sweep.
- **Dockerfile.** Tracks the latest stable PHP 8 tag (`php:8.5-cli-bookworm`).

## 2. Concrete Changes

### Group A — Implicit nullable parameters (PHP 8.4 deprecation)

Add `?` to make the nullable type explicit. No behavior change.

| File | Line | Change |
|---|---|---|
| `src/Resolver.php` | 377 | `function assign(VarRef $var, ValRef $val = null)` → `?ValRef $val = null` |
| `src/Scope.php` | 12 | `Scope $parent = null` → `?Scope $parent = null` |
| `src/EvalBlock.php` | 14 | `$origStmts = null` → typed nullable explicitly |
| `src/VarRef/UnknownVarRef.php` | 17 | `$parentContext = null` → typed nullable explicitly |

### Group B — `ReflectionParameter::getClass()` (PHP 8.0 deprecation)

| File | Line | Change |
|---|---|---|
| `src/Reducer/AbstractReducer.php` | 24 | Replace `$param->getClass()` with `$param->getType()` narrowed to `ReflectionNamedType`, then resolve via `new \ReflectionClass($type->getName())`. Behavior is identical for the existing single-class type-hints used by reducers. |

### Group C — Dynamic property creation (PHP 8.2 deprecation)

Declare each property explicitly on its class. Per the targeted-modernization scope, declarations include types only where the call sites already imply a single, obvious one; ambiguous cases are declared without a type.

| File / Class | Properties to declare |
|---|---|
| `src/Deobfuscator.php` | `$metaVisitor` (assigned in `__construct`, line 60) |
| `src/EvalBlock.php` | `$origStmts` |
| `src/VarRef/ListVarRef.php` | `$vars` |
| `src/ControlFlowVisitor.php` (`WrappedNode` class) | `$node`, `$subNodes`, `$stmts`, `$elseifs`, `$else` |

### Group D — Smaller deprecations / warnings

| File | Line | Issue | Fix |
|---|---|---|---|
| `src/Reducer/UnaryReducer.php` | 36 | `(double)` cast deprecated (PHP 8.1) | Replace with `(float)` |
| `src/Reducer/UnaryReducer.php` | 77 | Increment on non-numeric string (PHP 8.3) | Read surrounding logic; gate on numeric check or call `str_increment()` for the alphabetic-string case. Plan step decides exact form after reading the function. |
| `src/ValRef/ArrayVal.php` | 33 | Null as array offset (PHP 7.4) | Use `''` for null keys (PHP's documented coercion) or coerce earlier. Plan step decides. |
| `src/Reducer/MiscReducer.php` | 19 | Array-to-string conversion warning | Read context; most likely a missing array case in a reducer that should bail out instead of stringifying. Plan step decides. |

The three "Plan step decides" items require reading the surrounding function before committing to the exact fix. Intent is fixed; implementation form is not.

### Group E — Flysystem v2 → v3 migration

`composer.json`:

- `"league/flysystem": "^2"` → `"^3"`
- `"league/flysystem-memory": "^2"` → `"^3"`

Code-side adjustments (5 call sites total):

- `src/Deobfuscator.php:36` — `new Filesystem(new InMemoryFilesystemAdapter())` keeps the same constructor shape in v3.
- `src/Reducer/FuncCallReducer/FileSystemCall.php:7` — replace `use League\Flysystem\FileNotFoundException;` with `League\Flysystem\UnableToReadFile`.
- `src/Reducer/FuncCallReducer/FileSystemCall.php:73` — `catch (FileNotFoundException $e)` → `catch (UnableToReadFile $e)`. The catch surrounds a `readStream` call, so `UnableToReadFile` is the correct v3 replacement.
- `src/Utils.php` — `PathTraversalDetected` still exists at the same FQCN in v3; verify and leave unchanged.
- All other calls (`read`, `write`, `writeStream`, `readStream`, `fileExists`) have identical signatures in v3.

### Group F — Infra

- `composer.json` — add `"require": { "php": "^8.0", ... }` to the existing `require` block.
- `Dockerfile` — `FROM php:7.4-cli-buster` → `FROM php:8.5-cli-bookworm`.

## 3. Verification & Test Strategy

### Safety net

The existing `test.php` is the regression check. All 30 tests pass on PHP 8.5.4 today; they must continue to pass at every step.

### Definition of "deprecation-clean"

When run with `error_reporting=E_ALL`, the test suite produces:

- **0** lines matching `Deprecated:` or `Warning:` on stderr.
- **30** lines matching `pass$` on stdout.
- **0** lines matching `failed:` on stdout.

### Verification command

Used at the end of each group and once at the end:

```sh
php -d error_reporting=E_ALL test.php > /tmp/out 2> /tmp/err
grep -E "^(Deprecated|Warning|Fatal|PHP (Deprecated|Warning|Fatal))" /tmp/err | wc -l   # must be 0
grep -c "pass$" /tmp/out                                                                # must be 30
grep -c "failed:" /tmp/out                                                              # must be 0
```

### Per-step verification

Each group of changes (A–F) is independently verifiable against this command. A group is considered done only when the suite still reports 30/0 AND the deprecations the group targeted are gone from stderr.

### Order of work

1. **Group F (infra)** first — `composer.json` `php` constraint + Dockerfile bump. Pure metadata; no runtime risk.
2. **Group E (flysystem v2 → v3)** — biggest single risk (vendor major bump). Done before code-side cleanup so any v3 surprises surface against an otherwise-untouched codebase. After this step the vendor deprecations are gone.
3. **Groups A, B, C, D** — small, independent, any order. Group D last because three of its lines need surrounding-code reading.
4. **Final check** — re-run the verification command from a clean `composer install` (regenerate `composer.lock` and `vendor/`).

### Container check

`docker build .` after the Dockerfile change to confirm the image still builds. Not blocking the plan.

### Manual smoke test of `index.php`

After Group E, run `index.php -f` against a README example to confirm the CLI path still works end-to-end (`test.php` does not exercise `index.php`).
