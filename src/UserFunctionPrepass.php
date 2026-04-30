<?php

namespace PHPDeobfuscator;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Pre-pass visitor that registers every named function declaration in the
 * Resolver's user-function registry before the main second pass runs.
 * This mirrors ClosureRegistryPrepass for Expr\Closure and ensures that
 * call sites for functions declared later in the source are still inlined.
 */
class UserFunctionPrepass extends NodeVisitorAbstract
{
    private Resolver $resolver;

    public function __construct(Resolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Function_) {
            $this->resolver->registerUserFunction(
                $node->name->toLowerString(),
                $node
            );
        }
        return null;
    }
}
