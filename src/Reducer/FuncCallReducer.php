<?php

namespace PHPDeobfuscator\Reducer;

use PhpParser\Node;

use PHPDeobfuscator\FunctionSandbox;
use PHPDeobfuscator\Reducer\EvalReducer;
use PHPDeobfuscator\Resolver;
use PHPDeobfuscator\Utils;
use PHPDeobfuscator\ValRef\ScalarValue;

class FuncCallReducer extends AbstractReducer
{
    private $funcCallMap = array();
    private $resolver;
    private $evalReducer;

    public function __construct(Resolver $resolver, EvalReducer $evalReducer)
    {
        $this->resolver = $resolver;
        $this->evalReducer = $evalReducer;
    }

    public function addReducer(FuncCallReducer\FunctionReducer $reducer)
    {
        foreach ($reducer->getSupportedNames() as $funcName) {
            if (isset($this->funcCallMap[$funcName])) {
                throw new \RuntimeException("Tried adding {$funcName} from reducer " . get_class($reducer)
                    . "but was already added from " . get_class($this->funcCallMap[$funcName]));
            }
            $this->funcCallMap[$funcName] = $reducer;
        }
    }

    public function reduceFunctionCall(Node\Expr\FuncCall $node)
    {
        if ($node->name instanceof Node\Name) {
            $name = $node->name->toString();
        } else {
            try {
                $name = Utils::getValue($node->name);
            } catch (\PHPDeobfuscator\Exceptions\BadValueException $e) {
                $replacement = $this->resolveGlobalsLiteralClosureCall($node);
                if ($replacement !== null) {
                    return $replacement;
                }
                $name = $this->resolveGlobalsLiteralName($node->name);
                if ($name === null) {
                    return;
                }
            }
            $nameNode = new Node\Name($name);
            // Special case for MetadataVisitor
            $nameNode->setAttribute('replaces', $node->name);
            $node->name = $nameNode;
        }
        // Normalise to lowercase - function names are case insensitive
        return $this->makeFunctionCall(strtolower($name), $node);
    }

    private function makeFunctionCall($name, $node)
    {
        if(!isset($this->funcCallMap[$name])) {
            return $this->tryInlineUserFunction($name, $node);
        }
        $args = array();
        foreach ($node->args as $arg) {
            $valRef = Utils::getValueRef($arg->value);
            if ($arg->byRef) {
                return; // "Call-time pass-by-reference has been removed"
            }
            $args[] = $valRef;
        }
        return $this->funcCallMap[$name]->execute($name, $args, $node);
    }

    /**
     * Targeted fallback: $GLOBALS["literal"](args) where the literal was
     * assigned a closure literal at global scope. Returns a fully-evaluated
     * scalar replacement node, or null to leave the call site untouched.
     *
     * Strategy: build a synthetic parameter-less closure literal whose body
     * binds the call's arguments to the original closure's parameter names
     * and then runs the original body. EvalReducer::runEvalTree drives the
     * full pipeline on the synthetic source; the closure-literal wrapper
     * isolates the synthetic locals from the caller's scope (the Resolver
     * is shared with the outer traversal). If the reduced body's last stmt
     * is a Return_ with a known scalar VALUE, return that scalar.
     */
    private function resolveGlobalsLiteralClosureCall(Node\Expr\FuncCall $node): ?Node
    {
        $expr = $node->name;
        if (!($expr instanceof Node\Expr\ArrayDimFetch)) {
            return null;
        }
        $var = $expr->var;
        if (!($var instanceof Node\Expr\Variable)
            || !is_string($var->name) || $var->name !== 'GLOBALS'
        ) {
            return null;
        }
        $dim = $expr->dim;
        if (!($dim instanceof Node\Scalar\String_)) {
            return null;
        }
        $closure = $this->resolver->getGlobalClosure($dim->value);
        if ($closure === null) {
            return null;
        }
        if (count($closure->params) !== count($node->args)) {
            return null;
        }
        foreach ($node->args as $arg) {
            if ($arg->unpack || $arg->byRef) {
                return null;
            }
        }

        try {
            $printer = new \PHPDeobfuscator\ExtendedPrettyPrinter();
            $bindings = '';
            foreach ($closure->params as $i => $param) {
                $paramName = $param->var->name;
                if (!is_string($paramName)) {
                    return null;
                }
                $argSrc = $printer->prettyPrintExpr($node->args[$i]->value);
                $bindings .= '$' . $paramName . ' = ' . $argSrc . ";\n";
            }
            $bodySrc = $printer->prettyPrint($closure->stmts);
            $source = "function () {\n" . $bindings . $bodySrc . "\n};";
            $stmts = $this->evalReducer->runEvalTree($source);
        } catch (\Throwable $e) {
            return null;
        }
        if (count($stmts) !== 1 || !($stmts[0] instanceof Node\Stmt\Expression)) {
            return null;
        }
        $reducedClosure = $stmts[0]->expr;
        if (!($reducedClosure instanceof Node\Expr\Closure)) {
            return null;
        }
        $bodyStmts = $reducedClosure->stmts;
        if (empty($bodyStmts)) {
            return null;
        }
        $last = end($bodyStmts);
        if (!($last instanceof Node\Stmt\Return_) || $last->expr === null) {
            return null;
        }
        try {
            $value = Utils::getValue($last->expr);
        } catch (\PHPDeobfuscator\Exceptions\BadValueException $e) {
            return null;
        }
        if (is_array($value) || is_object($value) || is_resource($value)) {
            return null;
        }
        return Utils::scalarToNode($value);
    }

    /**
     * Targeted fallback for the obfuscation pattern $GLOBALS["literal"](...).
     *
     * Bypasses the ValRef mutability check which defeats the normal
     * ResolveValueVisitor path on real-world samples (any branching
     * statement in the global scope flips Resolver::setCurrentVarsMutable
     * and marks every top-level ScalarValue mutable). Only fires for the
     * exact shape $GLOBALS[<string literal>] and only returns a value
     * that looks like a valid PHP function identifier.
     *
     * Closure caveat: the lookup uses whatever value the Resolver had
     * recorded by the time the closure literal was visited, not the value
     * at PHP-runtime call time. Acceptable for deobfuscation readability;
     * obfuscators don't reassign their function-name globals.
     */
    private function resolveGlobalsLiteralName(Node $expr)
    {
        if (!($expr instanceof Node\Expr\ArrayDimFetch)) {
            return null;
        }
        $var = $expr->var;
        if (!($var instanceof Node\Expr\Variable)) {
            return null;
        }
        if (!is_string($var->name) || $var->name !== 'GLOBALS') {
            return null;
        }
        $dim = $expr->dim;
        if (!($dim instanceof Node\Scalar\String_)) {
            return null;
        }
        $valRef = $this->resolver->getGlobalScope()->getVariable($dim->value);
        if (!($valRef instanceof ScalarValue)) {
            return null;
        }
        // Bypass the mutability check intentionally — that's the whole
        // point of this fallback. Toggle isMutable off for the read and
        // restore it after, so we don't perturb the rest of the pipeline.
        $wasMutable = $valRef->isMutable();
        $valRef->setMutable(false);
        try {
            $name = $valRef->getValue();
        } finally {
            $valRef->setMutable($wasMutable);
        }
        if (!is_string($name)) {
            return null;
        }
        if (!preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $name)) {
            return null;
        }
        return $name;
    }

    /**
     * Fallback for calls to user-defined functions registered by
     * UserFunctionPrepass. Mirrors resolveGlobalsLiteralClosureCall:
     * builds a synthetic closure that binds the call's arguments to the
     * function's parameter names, runs the body through EvalReducer::runEvalTree,
     * and returns the scalar result if the body reduces fully.
     */
    private function tryInlineUserFunction(string $name, Node\Expr\FuncCall $node): ?Node
    {
        $func = $this->resolver->getUserFunction($name);
        if ($func === null) {
            return null;
        }
        if (count($func->params) !== count($node->args)) {
            return null;
        }
        foreach ($node->args as $arg) {
            if ($arg->unpack || $arg->byRef) {
                return null;
            }
        }
        foreach ($func->params as $param) {
            if ($param->byRef || $param->variadic) {
                return null;
            }
        }

        try {
            $printer = new \PHPDeobfuscator\ExtendedPrettyPrinter();
            $bindings = '';
            foreach ($func->params as $i => $param) {
                $paramName = $param->var->name;
                if (!is_string($paramName)) {
                    return null;
                }
                $argSrc = $printer->prettyPrintExpr($node->args[$i]->value);
                $bindings .= '$' . $paramName . ' = ' . $argSrc . ";\n";
            }
            $bodySrc = $printer->prettyPrint($func->stmts);
            $source = "function () {\n" . $bindings . $bodySrc . "\n};";
            $stmts = $this->evalReducer->runEvalTree($source);
        } catch (\Throwable $e) {
            return null;
        }

        if (count($stmts) !== 1 || !($stmts[0] instanceof Node\Stmt\Expression)) {
            return null;
        }
        $reducedClosure = $stmts[0]->expr;
        if (!($reducedClosure instanceof Node\Expr\Closure)) {
            return null;
        }
        $bodyStmts = $reducedClosure->stmts;
        if (empty($bodyStmts)) {
            return null;
        }
        $last = end($bodyStmts);
        if (!($last instanceof Node\Stmt\Return_) || $last->expr === null) {
            return null;
        }
        try {
            $value = Utils::getValue($last->expr);
        } catch (\PHPDeobfuscator\Exceptions\BadValueException $e) {
            return null;
        }
        if (is_array($value) || is_object($value) || is_resource($value)) {
            return null;
        }
        return Utils::scalarToNode($value);
    }

}
