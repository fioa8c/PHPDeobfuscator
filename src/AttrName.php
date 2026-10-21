<?php

namespace PHPDeobfuscator;

class AttrName
{
    const VALUE = 'value';
    const REDUCED_FROM = 'reducedFrom';
    const IN_EXPR_STMT = 'inExprStmt';
    /** Resolver cache: names written inside a branch/loop subtree, or false if unanalysable. */
    const MUTATED_NAMES = 'mutatedNames';
    /** Set on the BinaryOp the Resolver builds from an AssignOp (`$a .= x` -> `$a = $a . x`). */
    const SELF_ASSIGN = 'selfAssign';
    /**
     * Set by ReducerVisitor::enterNode on an expression whose parent provably
     * only reads its value (assignment RHS, operator operand, array item,
     * ternary arm, echo/return). The second pass has no parent attribute, so
     * context-sensitive reducers (MiscReducer::reduceArrayDimFetch) rely on
     * this tag instead of walking up.
     */
    const READ_CONTEXT = 'readContext';
    /**
     * Set on an expression that is written to (assignment target, ++/--,
     * unset, foreach target, destructuring item). Beats READ_CONTEXT: the
     * Resolver's AssignOp rewrite shares one node between the target and the
     * operand, so a node can carry both.
     */
    const LVALUE = 'lvalue';
}
