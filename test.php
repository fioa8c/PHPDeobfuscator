<?php

require 'vendor/autoload.php';

$virtualPath = '/var/www/html/testcase.php';

error_reporting(E_ALL);

$testdir = dirname(__FILE__) . '/tests';

$d = opendir($testdir);

while ($testfile = readdir($d)) {
    if ($testfile === '.' || $testfile === '..') {
        continue;
    }
    $f = fopen($testdir . '/' . $testfile, 'r');
    if (!$f) {
        exit(1);
    }
    $tests = array();
    $curTest = array('input' => array(), 'output' => array(), 'analysis' => null, 'analysis_text' => null, 'analysis_json' => null);
    $lines = null;
    while (!feof($f)) {
        $line = fgets($f);
        $trim = trim($line);
        if ($trim === 'INPUT') {
            if ($lines !== null) {
                $tests[] = $curTest;
                $curTest = array('input' => array(), 'output' => array(), 'analysis' => null, 'analysis_text' => null, 'analysis_json' => null);
            }
            $lines = &$curTest['input'];
            continue;
        } elseif ($trim === 'OUTPUT') {
            $lines = &$curTest['output'];
            continue;
        } elseif ($trim === 'ANALYSIS') {
            $curTest['analysis'] = array();
            $lines = &$curTest['analysis'];
            continue;
        } elseif ($trim === 'ANALYSIS-TEXT') {
            $curTest['analysis_text'] = array();
            $lines = &$curTest['analysis_text'];
            continue;
        } elseif ($trim === 'ANALYSIS-JSON') {
            $curTest['analysis_json'] = array();
            $lines = &$curTest['analysis_json'];
            continue;
        }
        if ($lines !== null) {
            $lines[] = $line;
        }
    }
    if ($lines !== null) {
        $tests[] = $curTest;
    }
    fclose($f);
    foreach ($tests as $i => $test) {
        $name = $testfile . '/' . ($i + 1);
        $code = "<?php\n" . trim(implode('', $test['input']));
        $deobf = new \PHPDeobfuscator\Deobfuscator();
        $deobf->getFilesystem()->write($virtualPath, $code);
        $deobf->setCurrentFilename($virtualPath);
        try {
            $deobfTree = $deobf->deobfuscate($deobf->parse($code));
            $out = $deobf->prettyPrint($deobfTree);
        } catch (\Exception | \Error $e) {
            echo "Test $name failed:\n";
            echo "Exception: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            continue;
        }
        $expect = "<?php\n\n" . trim(implode('', $test['output']));
        $deobfPass = ($out === $expect);
        $analysisPass = true;
        $analysisExpected = null;
        $analysisGot = null;
        if ($test['analysis'] !== null) {
            $analysisExpected = trim(implode('', $test['analysis']));
            $findings = $deobf->analyze($out, $deobfTree);
            $formatter = new \PHPDeobfuscator\Analysis\ReportFormatter();
            $analysisGot = trim($formatter->formatFixture($findings));
            $analysisPass = ($analysisGot === $analysisExpected);
        }
        if (!$deobfPass) {
            echo "Test $name failed (deobfuscation):\n";
            echo "Expected:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $expect)));
            echo "\n";
            echo "Got:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $out)));
            echo "\n";
        }
        if (!$analysisPass) {
            echo "Test $name failed (analysis):\n";
            echo "Expected:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $analysisExpected)));
            echo "\n";
            echo "Got:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $analysisGot)));
            echo "\n";
        }
        $analysisTextPass = true;
        $analysisTextExpected = null;
        $analysisTextGot = null;
        if (isset($test['analysis_text']) && $test['analysis_text'] !== null) {
            $analysisTextExpected = trim(implode('', $test['analysis_text']));
            $findings = $deobf->analyze($out, $deobfTree);
            $formatter = new \PHPDeobfuscator\Analysis\ReportFormatter();
            $analysisTextGot = trim($formatter->formatText($findings));
            $analysisTextPass = ($analysisTextGot === $analysisTextExpected);
        }
        if (!$analysisTextPass) {
            echo "Test $name failed (analysis-text):\n";
            echo "Expected:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $analysisTextExpected)));
            echo "\n";
            echo "Got:\n";
            echo implode("\n", array_map(function($l) { return "[]: $l"; }, explode("\n", $analysisTextGot)));
            echo "\n";
        }
        $analysisJsonPass = true;
        $analysisJsonExpected = null;
        $analysisJsonGot = null;
        if (isset($test['analysis_json']) && $test['analysis_json'] !== null) {
            $analysisJsonExpected = trim(implode('', $test['analysis_json']));
            $findings = $deobf->analyze($out, $deobfTree);
            $formatter = new \PHPDeobfuscator\Analysis\ReportFormatter();
            $analysisJsonGot = trim($formatter->formatJson($findings));
            $expectedDecoded = json_decode($analysisJsonExpected, true);
            $gotDecoded = json_decode($analysisJsonGot, true);
            $analysisJsonPass = ($expectedDecoded !== null && $expectedDecoded === $gotDecoded);
        }
        if (!$analysisJsonPass) {
            echo "Test $name failed (analysis-json):\n";
            echo "Expected:\n";
            echo $analysisJsonExpected . "\n";
            echo "Got:\n";
            echo $analysisJsonGot . "\n";
        }
        if ($deobfPass && $analysisPass && $analysisTextPass && $analysisJsonPass) {
            echo "Test $name pass\n";
        }
    }
}

closedir($d);
