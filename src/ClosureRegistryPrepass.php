<?php

namespace PHPDeobfuscator;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Pre-pass visitor that harvests every top-level closure assignment into
 * Resolver's global-closure registry. Runs in a dedicated traverser between
 * firstPass and secondPass so the registry is fully populated before
 * secondPass starts visiting call sites. (A separate traverser is required
 * because ControlFlowVisitor in firstPass returns DONT_TRAVERSE_CHILDREN,
 * which would block sibling visitors from descending into closure bodies.)
 *
 * Without this prepass, FuncCall sites inside closure bodies cannot fold
 * when the closure they reference is declared textually later — Resolver's
 * onAssign-based registration only fires at the leaveNode of the later
 * assignment, by which time the depth-first walk has already visited and
 * skipped the call.
 *
 * Gate predicate matches the previous Resolver::tryRegisterGlobalClosure:
 *   - Top-level (not inside a function/method/closure body).
 *   - LHS is $name or $GLOBALS["literal"].
 *   - RHS is Expr\Closure with no use(), no by-ref params, no default-valued
 *     params, no variadic params.
 */
class ClosureRegistryPrepass extends NodeVisitorAbstract
{
    private Resolver $resolver;
    private int $nestingDepth = 0;

    public function __construct(Resolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Expr\Assign && $this->nestingDepth === 0) {
            $this->tryRegister($node);
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

    private function tryRegister(Expr\Assign $assign): void
    {
        $rhs = $assign->expr;
        if (!($rhs instanceof Expr\Closure)) {
            return;
        }
        if ($rhs->uses !== []) {
            return;
        }
        foreach ($rhs->params as $param) {
            if ($param->byRef || $param->default !== null || $param->variadic) {
                return;
            }
        }

        $lhs = $assign->var;
        $name = null;
        if ($lhs instanceof Expr\Variable && is_string($lhs->name)) {
            $name = $lhs->name;
        } elseif ($lhs instanceof Expr\ArrayDimFetch
            && $lhs->var instanceof Expr\Variable
            && $lhs->var->name === 'GLOBALS'
            && $lhs->dim instanceof Node\Scalar\String_
        ) {
            $name = $lhs->dim->value;
        }
        if ($name === null) {
            return;
        }

        $this->resolver->registerGlobalClosure($name, $rhs);
    }
}
