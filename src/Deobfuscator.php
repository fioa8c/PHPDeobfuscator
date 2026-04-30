<?php

namespace PHPDeobfuscator;

use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

class Deobfuscator
{
    private $parser;
    private $prettyPrinter;

    private $firstPass;
    private $closurePrepass;
    private $secondPass;

    private $fileSystem;
    private $filename;

    private $metaVisitor;

    public function __construct($dumpOrig = false, $annotateReductions = false)
    {
        $this->parser = (new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7);
        $this->prettyPrinter = new ExtendedPrettyPrinter();

        $this->firstPass = new \PhpParser\NodeTraverser;
        $this->closurePrepass = new \PhpParser\NodeTraverser;
        $this->secondPass = new \PhpParser\NodeTraverser;

        $resolver = new Resolver();

        $this->firstPass->addVisitor(new ControlFlowVisitor());
        $this->closurePrepass->addVisitor(new ClosureRegistryPrepass($resolver));
        $this->closurePrepass->addVisitor(new UserFunctionPrepass($resolver));

        if ($dumpOrig) {
            $this->secondPass->addVisitor(new AddOriginalVisitor($this));
        }
        $this->secondPass->addVisitor($resolver);
        $this->secondPass->addVisitor(new ResolveValueVisitor($resolver));

        $this->fileSystem = new Filesystem(new InMemoryFilesystemAdapter());

        $evalReducer = new Reducer\EvalReducer($this);

        $funcCallReducer = new Reducer\FuncCallReducer($resolver, $evalReducer);
        $funcCallReducer->addReducer(new Reducer\FuncCallReducer\FunctionSandbox());
        $funcCallReducer->addReducer(new Reducer\FuncCallReducer\FileSystemCall($this->fileSystem));
        $funcCallReducer->addReducer(new Reducer\FuncCallReducer\MiscFunctions($evalReducer, $resolver));
        $funcCallReducer->addReducer(new Reducer\FuncCallReducer\PassThrough());

        $reducer = new ReducerVisitor();
        $reducer->addReducer(new Reducer\BinaryOpReducer());
        $reducer->addReducer($evalReducer);
        $reducer->addReducer($funcCallReducer);
        $reducer->addReducer(new Reducer\MagicReducer($this, $resolver));
        $reducer->addReducer(new Reducer\UnaryReducer($resolver));
        $reducer->addReducer(new Reducer\MiscReducer());

        $this->secondPass->addVisitor($reducer);

        if ($annotateReductions) {
            $this->metaVisitor = new MetadataVisitor($this);
            $this->secondPass->addVisitor($this->metaVisitor);
        } else {
            $this->metaVisitor = null;
        }
    }

    public function getFilesystem()
    {
        return $this->fileSystem;
    }

    public function getCurrentFilename()
    {
        return $this->filename;
    }

    public function setCurrentFilename($filename)
    {
        $this->filename = $filename;
    }

    public function parse($phpCode)
    {
        $phpCode = str_ireplace('<?=', '<?php echo ', $phpCode);
        $phpCode = str_ireplace('<?', '<?php ', $phpCode);
        $phpCode = str_ireplace('<?php php', '<?php', $phpCode);
        return $this->parser->parse($phpCode);
    }

    public function prettyPrint(array $tree, $file = true)
    {
        if ($file) {
            return $this->prettyPrinter->prettyPrintFile($tree);
        } else {
            return $this->prettyPrinter->prettyPrint($tree);
        }
    }

    public function analyze(string $deobfuscatedCode, ?array $fallbackTree = null): Analysis\Findings
    {
        $tree = null;
        $reparseError = null;
        try {
            $tree = $this->parser->parse($deobfuscatedCode);
            if ($tree === null) {
                $reparseError = 'parser returned null';
            }
        } catch (\Throwable $e) {
            $reparseError = $e->getMessage();
        }

        if ($tree === null) {
            // Re-parse failed (e.g. EvalBlock or other custom-printer output isn't
            // valid PHP). Fall back to the in-memory deobfuscated tree if the
            // caller provided one. Line numbers in findings will then come from
            // the original-input parse rather than matching the printed output —
            // best-effort, but better than dropping the analysis entirely.
            if ($fallbackTree !== null) {
                $tree = $fallbackTree;
            } else {
                error_log('PHPDeobfuscator analyze() re-parse error: ' . $reparseError);
                $findings = new Analysis\Findings();
                $findings->addSink(new Analysis\Finding(
                    'meta',
                    'analysis_aborted',
                    $reparseError,
                    0,
                    'auto-exec'
                ));
                return $findings;
            }
        }

        try {
            $visitor = new Analysis\SecurityAnalysisVisitor();
            $traverser = new \PhpParser\NodeTraverser();
            $traverser->addVisitor(new \PhpParser\NodeVisitor\ParentConnectingVisitor());
            $traverser->addVisitor($visitor);
            $traverser->traverse($tree);
            $findings = $visitor->getFindings();
            if ($reparseError !== null) {
                error_log('PHPDeobfuscator analyze() re-parse fell back to in-memory tree: ' . $reparseError);
                $findings->addSink(new Analysis\Finding(
                    'meta',
                    'reparse_fallback',
                    'fell back to in-memory tree (line numbers may not match printed output)',
                    0,
                    'auto-exec'
                ));
            }
            return $findings;
        } catch (\Throwable $e) {
            error_log('PHPDeobfuscator analyze() traverse error: ' . $e->getMessage());
            $findings = new Analysis\Findings();
            $findings->addSink(new Analysis\Finding(
                'meta',
                'analysis_aborted',
                $e->getMessage(),
                0,
                'auto-exec'
            ));
            return $findings;
        }
    }

    public function printFileReductions(array $stmts)
    {
        if ($this->metaVisitor === null) {
            throw new \LogicException("annotateReductions was not set on construction");
        }
        return $this->metaVisitor->printFileReductions($stmts);
    }

    public function deobfuscate(array $tree)
    {
        $tree = $this->firstPass->traverse($tree);
        $tree = $this->closurePrepass->traverse($tree);
        $tree = $this->secondPass->traverse($tree);
        return $tree;
    }

}
