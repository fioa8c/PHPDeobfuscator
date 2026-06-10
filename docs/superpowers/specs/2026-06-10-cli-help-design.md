# CLI Help Section — Design

Date: 2026-06-10

## Goal

Give the CLI entrypoint (`index.php`, `php_sapi_name() == 'cli'` branch) a proper
help/usage section, replacing the current terse `die("Missing required parameter -f\n")`.

## Triggers

Help text is shown when:

1. **`-h` flag** is present — print usage to **stdout**, exit `0`.
2. **No arguments at all** — print usage to **stdout**, exit `0`.
3. **Invalid usage** — `-f` missing, or the given file does not exist / is not
   readable — print `Error: <reason>` to **stderr**, then usage, exit `1`.

`getopt` silently ignores genuinely unknown flags and cannot cleanly report them,
so "invalid usage" in practice means a missing or unreadable `-f`.

## Implementation

- Extend the getopt string from `'tof:aj'` to `'tof:ajh'`.
- Add a plain `usage()` function in `index.php` returning a here-doc string
  (no new class). It documents:
  - Usage line: `php index.php -f <file> [-t] [-o] [-a] [-j]`
  - Each flag, one line each: `-f` (required, file to deobfuscate), `-t` (dump
    node tree), `-o` (annotate each reduced expression with original source),
    `-a` (analysis report, text), `-j` (analysis report, JSON), `-h` (this help).
  - A couple of example invocations.
- Detect "no arguments" via `$argc`/`$argv` (or empty `getopt` result with no
  trailing args) before requiring `-f`.
- Add a file-readability check (`is_readable`) before `file_get_contents`, which
  today would only emit a warning on a bad path.

## Out of scope

- The web / SAPI branch is untouched.
