<?php

require 'vendor/autoload.php';

ini_set('xdebug.var_display_max_depth', -1);
ini_set('memory_limit', '512M');
ini_set('xdebug.max_nesting_level', 1000);

function deobfuscate($code, $filename, $dumpOrig, $stripComments = false) {
    $deobf = new \PHPDeobfuscator\Deobfuscator($dumpOrig, false, $stripComments);
    $cwd = '/var/www/html/';
    $virtualPath = $cwd . basename($filename);
    $deobf->getFilesystem()->write($virtualPath, $code);
    $deobf->setCurrentFilename($virtualPath);
    $tree = $deobf->parse($code);
    $tree = $deobf->deobfuscate($tree);
    $newCode = $deobf->prettyPrint($tree);
    return array($tree, $newCode);
}

function usage() {
    $script = basename($_SERVER['argv'][0] ?? 'index.php');
    return <<<TXT
PHP source-code deobfuscator.

Usage:
  php {$script} -f <file> [-t] [-o] [-a] [-j] [-c]
  php {$script} -h

Options:
  -f <file>  File to deobfuscate (required).
  -t         Dump the resulting node tree after the reduced source.
  -c         Strip all comments from the input.
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
    $opts = getopt('tof:ajhc');
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
    list($tree, $code) = deobfuscate(file_get_contents($filename), $filename, $orig, $stripComments);
    echo $code, "\n";
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
