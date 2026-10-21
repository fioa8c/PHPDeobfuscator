<?php

require 'vendor/autoload.php';

use PHPDeobfuscator\Pipeline;

ini_set('xdebug.var_display_max_depth', -1);
ini_set('memory_limit', '512M');
ini_set('xdebug.max_nesting_level', 1000);

function deobfuscate($code, $filename, $dumpOrig, $stripComments = false, $executePure = false, $removeDeadCode = false, $renameVars = false) {
    return Pipeline::deobfuscate($code, $filename, $dumpOrig, $stripComments, $executePure, $removeDeadCode, $renameVars);
}

function peelEvalLayers($code, $filename, $stripComments, $executePure, $removeDeadCode, $renameVars, &$error = null) {
    return Pipeline::peelEvalLayers($code, $filename, $stripComments, $executePure, $removeDeadCode, $renameVars, $error);
}

function usage() {
    $script = basename($_SERVER['argv'][0] ?? 'index.php');
    return <<<TXT
PHP source-code deobfuscator.

Usage:
  php {$script} -f <file> [-t] [-o] [-a] [-j] [-c] [-x] [-e] [-u] [-r]
  php {$script} -h

Options:
  -f <file>  File to deobfuscate (required).
  -t         Dump the resulting node tree after the reduced source.
  -c         Strip all comments from the input.
  -x         Execute provably pure user functions in a sandboxed subprocess to
             resolve decoder calls the static reducer cannot reduce.
  -e         Dynamically peel nested eval() layers by running the sample under
             the php-eval-hook extension in a hardened sandbox, then statically
             clean each captured layer. Needs the extension (see PHPDEOBF_EVALHOOK).
  -u         Remove assignments to variables the reduced code never reads
             (decoder scaffolding). Conservative: skips any scope using
             variable-variables, extract/compact, eval, include or references.
  -r         Give obfuscated-looking variables readable names when the code
             proves what they are (source, decoder, callable role, ...). Only
             evidence-backed renames; unexplained names are left untouched.
             Conservative: skips any scope using variable-variables, \$GLOBALS,
             global, extract/compact or closures. Best combined with -u.
  -o         Annotate each reduced expression with its original source.
  -a         Append a security-analysis report in text form.
  -j         Append a security-analysis report in JSON form.
  -h         Show this help and exit.

Examples:
  php {$script} -f obfuscated.php
  php {$script} -f obfuscated.php -o -a
  php {$script} -f obfuscated.php -t -j

TXT;
}

$nodeDumper = new PhpParser\NodeDumper();
if (php_sapi_name() == 'cli') {
    $opts = getopt('tof:ajhcxeur');
    // Explicit help, or run with no arguments at all: print usage to stdout, exit 0.
    if (isset($opts['h']) || ($_SERVER['argc'] ?? 1) <= 1) {
        echo usage();
        exit(0);
    }
    // Invalid usage: missing or unreadable -f. Report to stderr, then usage, exit 1.
    if (!isset($opts['f'])) {
        fwrite(STDERR, "Error: missing required parameter -f\n\n");
        fwrite(STDERR, usage());
        exit(1);
    }
    $filename = $opts['f'];
    if (!is_readable($filename)) {
        fwrite(STDERR, "Error: cannot read file '{$filename}'\n\n");
        fwrite(STDERR, usage());
        exit(1);
    }
    $orig = isset($opts['o']);
    $stripComments = isset($opts['c']);
    $executePure = isset($opts['x']);
    $removeDeadCode = isset($opts['u']);
    $renameVars = isset($opts['r']);
    $rawInput = file_get_contents($filename);
    if (isset($opts['e'])) {
        $peelError = null;
        $peeled = peelEvalLayers($rawInput, $filename, $stripComments, $executePure, $removeDeadCode, $renameVars, $peelError);
        if ($peeled !== null) {
            echo $peeled;
        } else {
            fwrite(STDERR, "Note: eval-hook peeling did not run ({$peelError}); using static output.\n");
        }
    }
    list($tree, $code) = deobfuscate($rawInput, $filename, $orig, $stripComments, $executePure, $removeDeadCode, $renameVars);
    if (!isset($opts['e'])) {
        echo $code, "\n";
    }
    if (isset($opts['t'])) {
        echo $nodeDumper->dump($tree), "\n";
    }
    if (isset($opts['a']) || isset($opts['j'])) {
        $deobf = new \PHPDeobfuscator\Deobfuscator();
        $findings = $deobf->analyze($code, $tree);
        $formatter = new \PHPDeobfuscator\Analysis\ReportFormatter();
        if (isset($opts['a'])) {
            echo "\n" . $formatter->formatText($findings, basename($filename)) . "\n";
        }
        if (isset($opts['a']) && isset($opts['j'])) {
            echo "\n===== Analysis (JSON) =====\n";
        }
        if (isset($opts['j'])) {
            echo "\n" . $formatter->formatJson($findings, basename($filename)) . "\n";
        }
    }
} else {
    if (isset($_POST['phpdata'])) {
        $orig = array_key_exists('orig', $_GET);
        $php = $_POST['phpdata'];
        header('Content-Type: text/plain');
        list($tree, $code) = deobfuscate($php, 'input.php', $orig);
        echo $code, "\n\n";
        if (array_key_exists('tree', $_GET)) {
            echo '======== Tree =======', "\n";
            echo $nodeDumper->dump($tree), "\n";
        }
        if (isset($_GET['analyze'])) {
            $mode = $_GET['analyze'];
            $deobf = new \PHPDeobfuscator\Deobfuscator();
            $findings = $deobf->analyze($code, $tree);
            $formatter = new \PHPDeobfuscator\Analysis\ReportFormatter();
            if ($mode === 'text' || $mode === 'both') {
                echo "\n" . $formatter->formatText($findings, 'input.php') . "\n";
            }
            if ($mode === 'both') {
                echo "\n===== Analysis (JSON) =====\n";
            }
            if ($mode === 'json' || $mode === 'both') {
                echo "\n" . $formatter->formatJson($findings, 'input.php') . "\n";
            }
        }
    } else {
        echo <<<HTML
<html>
<body>
<form action="index.php" method="POST">
<textarea name="phpdata" rows=40 cols=180></textarea>
<br>
<input type="submit" value="Deobfuscate">
</form>
</body>
</html>
HTML;
    }
}
