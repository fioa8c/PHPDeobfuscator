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
}
