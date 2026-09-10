<?php

namespace PHPDeobfuscator\Reducer;

use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;

use PHPDeobfuscator\Deobfuscator;
use PHPDeobfuscator\EvalBlock;
use PHPDeobfuscator\Utils;

class EvalReducer extends AbstractReducer
{
    private $deobfuscator;
    private $outputAsEvalStr;
    private int $depth = 0;
    private const MAX_DEPTH = 8;

    public function __construct(Deobfuscator $deobfuscator, $outputAsEvalStr = false)
    {
        $this->deobfuscator = $deobfuscator;
        $this->outputAsEvalStr = $outputAsEvalStr;
    }

    public function reduceEval(Expr\Eval_ $node)
    {
        $expr = Utils::getValue($node->expr);
        if (!is_string($expr)) {
            return null;
        }
        $newExpr = $this->tryRunEval($expr);
        return $newExpr;
    }

    public function reduceInclude(Expr\Include_ $node)
    {
        // TODO $node->type
        // TODO should this replace the include with an eval or should it just export the symbols?
        // need to handle recursive includes
        // One of Include_::(TYPE_INCLUDE, TYPE_INCLUDE_ONCE, TYPE_REQUIRE, TYPE_REQUIRE_ONCE)
        $file = Utils::getValue($node->expr);
        $fileSystem = $this->deobfuscator->getFilesystem();
        if (!Utils::safeFileExists($fileSystem, $file)) {
            return;
        }
        try {
            $code = $fileSystem->read($file);
        } catch (\League\Flysystem\FilesystemException $e) {
            return;
        }
        return $this->tryRunEval($code);
    }

    private function tryRunEval($code)
    {
        try {
            return $this->runEval($code);
        } catch (\Throwable $e) {
            // The decoded eval() body is not reducible (e.g. it is not parseable
            // PHP, or a nested pass threw). Degrade per the robustness invariant:
            // leave the eval() call intact and never abort the whole file. A
            // stack trace here used to be dumped to STDOUT, corrupting the
            // deobfuscated output; keep a one-line breadcrumb on STDERR instead.
            fwrite(STDERR, 'note: eval() layer left intact (' . $e->getMessage() . ")\n");
            return null;
        }
    }

    public function runEval($code)
    {
        if ($this->depth >= self::MAX_DEPTH) {
            return null;
        }
        $this->depth++;
        try {
            $origTree = $this->parseCode($code);
            $tree = $this->deobfTree($origTree);
            // If it's just a single expression, return directly
            // XXX this is not semantically correct because eval does not return
            // anything by default
            if (count($tree) === 1 && $tree[0] instanceof Stmt\Expression) {
                return $tree[0]->expr;
            }
            if (count($tree) === 1 && $tree[0] instanceof Stmt\Return_) {
                return $tree[0]->expr;
            }
            if ($this->outputAsEvalStr) {
                $expr = new Expr\Eval_(new String_($this->deobfuscator->prettyPrint($tree, false), array(
                    'kind' => String_::KIND_NOWDOC, 'docLabel' => 'EVAL' . rand()
                ))) ;
            } else {
                $expr = new EvalBlock($tree, $origTree);
            }
            return $expr;
        } finally {
            $this->depth--;
        }
    }

    private function parseCode($code)
    {
        // eval()'d code runs starting in PHP mode. If it already carries an open
        // tag we parse it as-is; otherwise we prepend an open tag. A body that
        // begins with a close tag (immediately dropping to inline HTML, e.g. a
        // DOCTYPE-emitting shell) is valid PHP once the open tag is prepended, so
        // it must NOT be rewritten into an open tag itself — doing so turned the
        // close tag into a second open tag and produced unparseable input.
        $prefix = substr($code, 0, 2) === '<?' ? '' : '<?php ';
        return $this->deobfuscator->parse("{$prefix}{$code}");
    }

    private function deobfTree($tree)
    {
        return $this->deobfuscator->deobfuscate($tree);
    }

    /**
     * Like runEvalTree() but for statements that were built in memory (e.g. a
     * cloned function body) - skips the print/parse round trip.
     */
    public function runEvalStmts(array $stmts)
    {
        if ($this->depth >= self::MAX_DEPTH) {
            return [];
        }
        $this->depth++;
        try {
            return $this->deobfTree($stmts);
        } finally {
            $this->depth--;
        }
    }

    public function runEvalTree($code)
    {
        if ($this->depth >= self::MAX_DEPTH) {
            return [];
        }
        $this->depth++;
        try {
            return $this->deobfTree($this->parseCode($code));
        } finally {
            $this->depth--;
        }
    }

}
