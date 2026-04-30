<?php

namespace PHPDeobfuscator;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Pre-pass visitor that registers every top-level named function declaration
 * in the Resolver's user-function registry before the main second pass runs.
 * This mirrors ClosureRegistryPrepass for Expr\Closure and ensures that
 * call sites for functions declared later in the source are still inlined.
 *
 * Only top-level (nestingDepth === 0) functions are registered: a function
 * declared inside another function is not globally callable.
 */
class UserFunctionPrepass extends NodeVisitorAbstract
{
    private Resolver $resolver;
    private int $nestingDepth = 0;

    public function __construct(Resolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Function_ && $this->nestingDepth === 0) {
            $this->resolver->registerUserFunction(
                $node->name->toString(),
                $node
            );
        }
        if ($this->changesScope($node)) {
            $this->nestingDepth++;
        }
        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($this->changesScope($node)) {
            $this->nestingDepth--;
        }
        return null;
    }

    private function changesScope(Node $node): bool
    {
        return $node instanceof Expr\Closure
            || $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\ArrowFunction;
    }
}
