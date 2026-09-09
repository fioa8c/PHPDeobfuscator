# PHPDeobfuscator

## Overview

This deobfuscator attempts to reverse common obfuscation techniques applied to PHP source code.

It is implemented in PHP with the help of [PHP-Parser](https://github.com/nikic/PHP-Parser).

## Features

- Reduces all constant expressions e.g. `1 + 2` is replaced by `3`
- Safely run whitelisted PHP functions e.g. `base64_decode`
- Deobfuscate `eval` expressions
- Unwrap deeply nested obfuscation
- Filesystem virtualization
- Variable resolver (e.g. `$var1 = 10; $var2 = &$var1; $var2 = 20;` can determine `$var1` equals `20`)
- Rewrite control flow obfuscation
- Rewrite `$GLOBALS["..."]` calls — resolves obfuscated function dispatch through global string variables and through closure literals, including symbolic evaluation of the closure body when arguments are constant

## Installation

Requires PHP 8.0+. PHP Deobfuscator uses [Composer](https://getcomposer.org/) to manage its dependencies. Make sure Composer is installed first.

Run `composer install` in the root of this project to fetch dependencies.

A `Dockerfile` (pinning `php:8.5-cli-bookworm`) is also included. To build the image:

```
docker build -t phpdeobf .
```

The image's default command is `php index.php` with no arguments, which only
prints usage — `docker run --rm phpdeobf` does not deobfuscate anything. To run
the tool against a file on your machine, see
[Running a sample in Docker](#running-a-sample-in-docker).

The image is a snapshot: the Dockerfile does `COPY . /app`, so rebuild after
changing the source.

## Usage

### CLI

```
php index.php [-f filename] [-t] [-o] [-a] [-j] [-c] [-x]

required arguments:

-f    The obfuscated PHP file

optional arguments:

-t    Dump the output node tree for debugging
-o    Output comments next to each expression with the original code
-a    Append a security-analysis text report after the deobfuscated code
-j    Append a security-analysis JSON report after the deobfuscated code
-c    Strip all comments from the input (runs first, so -o/-a annotations remain)
-x    Execute provably pure user functions in a sandbox to resolve decoder calls
```

`-h`, or running with no arguments, prints usage.

The deobfuscated output is printed to STDOUT. When `-a` and `-j` are combined, the text report is emitted first, then a `===== Analysis (JSON) =====` divider, then the JSON document.

### Pure-function execution (`-x`)

Many obfuscators route every string through a small set of decoder functions built from loops, static lookup tables and character substitution — constructs the static reducer cannot fold. With `-x`, a function is executed for real, and its return value substituted, but **only** if it passes a default-deny purity analysis: an explicit allowlist of AST node types and side-effect-free builtins, applied recursively across everything the function calls. Globals and superglobals, dynamic calls, variable variables, objects, closures, callbacks, output, `eval`/`include` and by-reference parameters all disqualify a function, and static variables are permitted only as write-once lazy-initialised lookup tables.

Approved functions run in a separate `php -n` process with `disable_functions` covering process, filesystem, network and environment access, an `open_basedir` confined to a throwaway directory, memory and time limits, and a wall-clock timeout. Each call is executed twice and refused if the two results differ.

This still means running attacker-authored code, so it is **off by default and opt-in**. When analysing untrusted samples, run it inside the container using the hardened invocation in [Running a sample in Docker](#running-a-sample-in-docker).

### Running a sample in Docker

The tool lives at `/app` inside the image; your sample does not. Bind-mount the
directory that holds it (read-only) and override the default command, passing
the *container* path to `-f`:

```
docker run --rm -v "$PWD/samples:/samples:ro" phpdeobf \
  php index.php -f /samples/obfuscated.php
```

Any host directory works:

```
docker run --rm -v "/path/to/malware:/samples:ro" phpdeobf \
  php index.php -f /samples/suspicious.php > deobfuscated.php
```

The deobfuscated source goes to stdout, so redirect it on the host. Nothing is
written inside the container: `index.php` seeds the input into the in-memory
Flysystem at `/var/www/html/<basename>`, not the real filesystem.

#### Hardened invocation

Recommended whenever you pass `-x`, since that executes code from the sample:

```
docker run --rm \
  --network none \
  --read-only \
  --tmpfs /tmp:rw,noexec,nosuid,size=512m \
  --user "$(id -u):$(id -g)" \
  -m 2g \
  -v "$PWD/samples:/samples:ro" \
  phpdeobf \
  php index.php -f /samples/obfuscated.php -x
```

Two of these matter specifically for `-x`:

- **`--tmpfs /tmp` is required alongside `--read-only`.** The sandbox worker
  writes its script to a throwaway directory under `sys_get_temp_dir()`. With a
  read-only root filesystem and no writable `/tmp`, the worker cannot start and
  `-x` silently produces no extra reductions instead of failing loudly.
- **`--network none` is safe.** The sandbox subprocess never needs the network,
  and removing it closes the last escape route if something slips past the
  purity gate.

Drop `--user "$(id -u):$(id -g)"` if you hit permission errors; the container
only needs to write to `/tmp`, which the tmpfs already provides.

### Web Server

`index.php` outputs a simple textarea to paste the PHP code into. Deobfuscated code is printed when the form is submitted. The optional `?analyze=text|json|both` query parameter appends a security-analysis report to the response (mirrors the CLI `-a`/`-j` flags).

## Security analysis

After deobfuscation, the optional analysis pass scans the deobfuscated AST and lists:

- **Sources** — reads of attacker-controlled superglobals (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES`, `$_SERVER`, `$_ENV`, `$GLOBALS`) and pseudo-streams (`file_get_contents('php://input')`, etc.).
- **Sinks** — calls to dangerous PHP primitives, grouped by category: `code_exec` (`eval`, `assert`, `preg_replace` with `/e`, …), `os_exec` (`system`, `exec`, backticks, …), `dynamic_inc` (`include`/`require` with non-literal arg), `dispatch` (variable function/method/`new`), `deser` (`unserialize`), `file_write` (`file_put_contents`, `unlink`, …), `network` (`curl_exec`, `fsockopen`, …), `mail`, `header_inj` (`header()` with non-literal arg), and `obfusc` (`base64_decode`, `gzinflate`, `pack`, …).
- **Context** — every finding is tagged `auto-exec` (runs at script load) or `in-function:<qualified-name>` (only runs if that function is invoked).

The analysis is a purely syntactic scan — it does not track data flow from sources to sinks. Use the report to find candidate lines worth reading; verify exploitability by hand.

## Examples

#### Input
```php
<?php
eval(base64_decode("ZWNobyAnSGVsbG8gV29ybGQnOwo="));
```
#### Output
```php
<?php

eval /* PHPDeobfuscator eval output */ {
    echo "Hello World";
};
```

#### Input
```php
<?
$f = fopen(__FILE__, 'r');
$str = fread($f, 200);
list(,, $payload) = explode('?>', $str);
eval($payload . '');
?>
if ($doBadThing) {
    evil_payload();
}
```

#### Output
```php
<?php

$f = fopen("/var/www/html/input.php", 'r');
$str = "<?\n\$f = fopen(__FILE__, 'r');\n\$str = fread(\$f, 200);\nlist(,, \$payload) = explode('?>', \$str);\neval(\$payload . '');\n?>\nif (\$doBadThing) {\n    evil_payload();\n}\n";
list(, , $payload) = array(0 => "<?\n\$f = fopen(__FILE__, 'r');\n\$str = fread(\$f, 200);\nlist(,, \$payload) = explode('", 1 => "', \$str);\neval(\$payload . '');\n", 2 => "\nif (\$doBadThing) {\n    evil_payload();\n}\n");
eval /* PHPDeobfuscator eval output */ {
    if ($doBadThing) {
        evil_payload();
    }
};
?>
if ($doBadThing) {
    evil_payload();
}
```

#### Input
```php
<?php
$x = 'y';
$$x = 10;
echo $y * 2;
```

#### Output
```php
<?php

$x = 'y';
$y = 10;
echo 20;
```

#### Input
```php
<?php
$decode = function ($x) {
    return strrev($x);
};
$out = $GLOBALS['decode']('hello');
```

#### Output
```php
<?php

$decode = function ($x) {
    return strrev($x);
};
$out = "olleh";
```

#### Input
```php
<?php
goto label4;
label1:
func4();
exit;
label2:
func3();
goto label1;
label3:
func2();
goto label2;
label4:
func1();
goto label3;
```

#### Output
```php
<?php

func1();
func2();
func3();
func4();
exit;
```

## Tests

Run the test suite from the repository root:

```
php test.php
```

The runner discovers every `tests/*.txt` fixture file and prints `pass`/`failed` per `INPUT`/`OUTPUT` block.
