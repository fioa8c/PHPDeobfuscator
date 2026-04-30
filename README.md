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

A `Dockerfile` (pinning `php:8.5-cli-bookworm`) is also included. To build and run the deobfuscator inside a container:

```
docker build -t phpdeobf .
docker run --rm phpdeobf
```

## Usage

### CLI

```
php index.php [-f filename] [-t] [-o] [-a] [-j]

required arguments:

-f    The obfuscated PHP file

optional arguments:

-t    Dump the output node tree for debugging
-o    Output comments next to each expression with the original code
-a    Append a security-analysis text report after the deobfuscated code
-j    Append a security-analysis JSON report after the deobfuscated code
```

The deobfuscated output is printed to STDOUT. When `-a` and `-j` are combined, the text report is emitted first, then a `===== Analysis (JSON) =====` divider, then the JSON document.

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
