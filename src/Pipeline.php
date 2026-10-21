<?php
namespace PHPDeobfuscator;

/**
 * The deobfuscation pipeline shared by the CLI (index.php) and the MWDB
 * sidecar. Keep behaviour identical to the former top-level functions in
 * index.php; index.php is now a thin wrapper around this class.
 */
class Pipeline
{
    /** @return array{0: array, 1: string} [$tree, $code] */
    public static function deobfuscate($code, $filename, $dumpOrig = false, $stripComments = false, $executePure = false, $removeDeadCode = false, $renameVars = false)
    {
        $deobf = new Deobfuscator($dumpOrig, false, $stripComments, $executePure, null, $removeDeadCode, $renameVars);
        $cwd = '/var/www/html/';
        $virtualPath = $cwd . basename($filename);
        $deobf->getFilesystem()->write($virtualPath, $code);
        $deobf->setCurrentFilename($virtualPath);
        $tree = $deobf->parse($code);
        $tree = $deobf->deobfuscate($tree);
        $newCode = $deobf->prettyPrint($tree);
        return array($tree, $newCode);
    }

    public static function evalHookAvailable(): bool
    {
        return (new EvalHook\EvalPeeler())->isAvailable();
    }

    /**
     * Dynamically peel eval() layers with the php-eval-hook sandbox, then run each
     * captured layer through the static pipeline. Returns the assembled source, or
     * null when the extension is unavailable / nothing was captured ($error says why).
     */
    public static function peelEvalLayers($code, $filename, $stripComments, $executePure, $removeDeadCode, $renameVars, &$error = null, array $peelerOpts = [])
    {
        $peeler = new EvalHook\EvalPeeler(null, $peelerOpts);
        if (!$peeler->isAvailable()) {
            $error = "eval-hook extension not found (build php-eval-hook, or set PHPDEOBF_EVALHOOK to its evalhook.so)";
            return null;
        }
        $result = $peeler->peel($code, basename($filename));
        if (empty($result['layers'])) {
            $error = !empty($result['error'])
                ? $result['error']
                : 'no eval() layers were captured'
                  . ($result['killed'] ? ' (sandbox timed out)' : '')
                  . (trim($result['stderr']) !== '' ? '; sandbox stderr: ' . substr(trim($result['stderr']), 0, 200) : '');
            return null;
        }
        $out = "<?php\n\n// Dynamically unpacked with php-eval-hook: "
            . count($result['layers']) . " eval() layer(s) captured in {$result['secs']}s.\n"
            . "// Each layer is the source passed to eval(), then statically deobfuscated.\n";
        foreach ($result['layers'] as $layer) {
            $out .= "\n// ===== eval() layer {$layer['n']} ({$layer['len']} bytes) =====\n";
            $layerCode = $layer['code'];
            try {
                list(, $clean) = self::deobfuscate('<?php ' . $layerCode, $filename, false, $stripComments, $executePure, $removeDeadCode, $renameVars);
                $out .= ltrim(preg_replace('/^<\?php\s*/', '', $clean)) . "\n";
            } catch (\Throwable $e) {
                $out .= "// (layer left raw: " . $e->getMessage() . ")\n" . rtrim($layerCode) . "\n";
            }
        }
        return $out;
    }
}
