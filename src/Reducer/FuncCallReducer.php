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
    /**
     * Memo of user-function inlining outcomes (scalar result, or null when the
     * body could not be fully reduced), keyed by function name and the
     * serialized scalar argument list. Inlining re-parses and fully re-reduces
     * the body on every call site, which is prohibitively slow for obfuscators
     * that route thousands of calls through a few decoder helpers. The cache is
     * dropped whenever a new user function is registered, since that can turn
     * a previous failure into a success.
     */
    private array $inlineCache = [];
    private int $inlineCacheVersion = -1;
    /**
     * Upper bound on user-function inlinings per run. Junk-function droppers
     * define dozens of cross-calling functions; every call site re-analyses the
     * callee (and its callees), which without a cap runs for minutes.
     */
    const MAX_INLINE_ATTEMPTS = 4000;
    private int $inlineAttempts = 0;

    /** Optional real-execution fallback for provably pure user functions. */
    private ?\PHPDeobfuscator\PureFunction\PurityAnalyzer $purityAnalyzer = null;
    private ?\PHPDeobfuscator\PureFunction\PureFunctionExecutor $pureExecutor = null;
    /** Functions the sandbox refused; never retried. */
    private array $pureBlacklist = [];

    /**
     * Enables executing purity-verified user functions in a sandbox to resolve
     * calls the symbolic reducer cannot. Off unless the caller opts in.
     */
    public function enablePureExecution(
        \PHPDeobfuscator\PureFunction\PurityAnalyzer $analyzer,
        \PHPDeobfuscator\PureFunction\PureFunctionExecutor $executor
    ): void {
        $this->purityAnalyzer = $analyzer;
        $this->pureExecutor = $executor;
    }

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
            // The resolved value must be a usable function name. A variable can
            // hold an arbitrary string (e.g. 'echo 1;'); rewriting that into a
            // call name would emit invalid code, so leave the call untouched.
            if (!$this->isValidFunctionName($name)) {
                return;
            }
            $nameNode = new Node\Name($name);
            // Special case for MetadataVisitor
            $nameNode->setAttribute('replaces', $node->name);
            $node->name = $nameNode;
        }
        // Normalise to lowercase - function names are case insensitive
        return $this->makeFunctionCall(strtolower($name), $node);
    }

    /**
     * Whether $name is a syntactically valid (optionally namespaced) PHP
     * function name. Guards against rewriting a call when a variable callee
     * resolved to a string that is not a real identifier.
     */
    private function isValidFunctionName($name): bool
    {
        return is_string($name)
            && preg_match('/^\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', $name) === 1;
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
        if (++$this->inlineAttempts > self::MAX_INLINE_ATTEMPTS) {
            return null;
        }

        try {
            $bindings = [];
            $argValues = [];
            $cacheable = true;
            foreach ($func->params as $i => $param) {
                $paramName = $param->var->name;
                if (!is_string($paramName)) {
                    return null;
                }
                try {
                    $argVal = Utils::getValue($node->args[$i]->value);
                    if (is_array($argVal) || is_object($argVal) || is_resource($argVal)) {
                        return null;
                    }
                    $argNode = Utils::scalarToNode($argVal);
                    $argValues[] = $argVal;
                } catch (\PHPDeobfuscator\Exceptions\BadValueException $e) {
                    $argNode = Utils::cloneAst($node->args[$i]->value);
                    $cacheable = false;
                }
                $bindings[] = new Node\Stmt\Expression(
                    new Node\Expr\Assign(new Node\Expr\Variable($paramName), $argNode));
            }
            // Unknown arguments: the result is only worth caching when it was a
            // failure - a function that could not be reduced with opaque inputs
            // will not reduce at the next call site with opaque inputs either.
            $cacheKey = $cacheable ? strtolower($name) . ':' . serialize($argValues) : strtolower($name) . ':?';
            if ($cacheKey !== null) {
                $version = $this->resolver->getUserFunctionVersion();
                if ($version !== $this->inlineCacheVersion) {
                    $this->inlineCache = [];
                    $this->inlineCacheVersion = $version;
                }
                if (array_key_exists($cacheKey, $this->inlineCache)) {
                    $cached = $this->inlineCache[$cacheKey];
                    return $cached === null ? null : Utils::scalarToNode($cached);
                }
            }
            // Build the synthetic closure in memory: printing the body and
            // parsing it back cost more than the reduction itself on samples
            // that call a decoder thousands of times.
            $closure = new Node\Expr\Closure([
                'stmts' => array_merge($bindings, Utils::cloneAst($func->stmts)),
            ]);
            $stmts = $this->evalReducer->runEvalStmts([new Node\Stmt\Expression($closure)]);
        } catch (\Throwable $e) {
            return null;
        }
        $value = $this->extractInlinedReturn($stmts);
        if ($value === null && $cacheable) {
            $value = $this->tryExecutePure($name, $argValues);
        }
        // Only record if no function was registered while reducing the body;
        // otherwise a later attempt might legitimately do better.
        if ($cacheKey !== null && $this->resolver->getUserFunctionVersion() === $this->inlineCacheVersion
            && ($cacheable || $value === null)) {
            $this->inlineCache[$cacheKey] = $value;
        }
        return $value === null ? null : Utils::scalarToNode($value);
    }

    /**
     * Last-resort resolution for calls the symbolic reducer could not reduce:
     * if the function and its whole call graph pass the purity analysis, run it
     * for real in the sandbox and use the value it produces.
     *
     * Returns null on any doubt - unverified function, sandbox error,
     * non-scalar or null result.
     *
     * @param scalar[] $argValues
     * @return scalar|null
     */
    private function tryExecutePure(string $name, array $argValues)
    {
        if ($this->purityAnalyzer === null || $this->pureExecutor === null) {
            return null;
        }
        $key = strtolower($name);
        if (isset($this->pureBlacklist[$key])) {
            return null;
        }
        if (!$this->purityAnalyzer->isPure($key)) {
            $this->pureBlacklist[$key] = true;
            return null;
        }
        $printer = new \PHPDeobfuscator\ExtendedPrettyPrinter();
        $defs = [];
        try {
            foreach ($this->purityAnalyzer->getDependencies() as $dep) {
                $func = $this->resolver->getUserFunction($dep);
                if ($func === null) {
                    $this->pureBlacklist[$key] = true;
                    return null;
                }
                $defs[strtolower($dep)] = $printer->prettyPrint([$func]);
            }
        } catch (\Throwable $e) {
            $this->pureBlacklist[$key] = true;
            return null;
        }
        if (!$defs) {
            $this->pureBlacklist[$key] = true;
            return null;
        }
        $result = $this->pureExecutor->call($defs, $key, $argValues);
        if (empty($result['ok'])) {
            // Only stop retrying when the refusal is a property of the function
            // itself (non-deterministic, produced output, failed to define).
            // A per-call refusal may not apply to other arguments, and a
            // transient sandbox failure says nothing about the function -
            // blacklisting on either would cascade to every dependent decoder.
            if (($result['kind'] ?? 'function') === 'function') {
                $this->pureBlacklist[$key] = true;
            }
            return null;
        }
        $value = $result['value'] ?? null;
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        return $value;
    }

    /**
     * Returns the scalar an inlined closure body returns, or null when the body
     * did not reduce to a closure ending in a single provable scalar `return`.
     */
    private function extractInlinedReturn(array $stmts)
    {
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
        return $value;
    }

}
