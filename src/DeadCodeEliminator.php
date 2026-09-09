<?php

namespace PHPDeobfuscator;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PHPDeobfuscator\PureFunction\PurityAnalyzer;

/**
 * Removes dead code the reduced program no longer uses (`-u`): assignments to
 * variables that are never read, and uncalled *pure* functions (decoder
 * scaffolding left after inlining). An uncalled function is removed only when
 * PurityAnalyzer proves it pure, so an unreferenced *impure* function - a
 * possible dormant payload - is kept for the security-analysis pass to see.
 *
 * After the reducer inlines a decoder's output, the scaffolding is left behind:
 * `$q = "<base64 blob>";`, FOPO ladders (`$x = "b"; $x = "ba"; …`), and a
 * `$gz = "<megabytes of binary>";` whose only use was inlined. None of it is
 * read any more; dropping it makes the payload legible.
 *
 * This is deliberately conservative - it is a readability pass over
 * attacker-authored code, so it must never change behaviour:
 *
 *   - It removes ONLY a statement of the exact shape `$plainVar = <expr>;`
 *     whose right-hand side is provably side-effect-free (built only from
 *     literals, variables, constants and pure operators - never a call, new,
 *     eval, include, assignment or closure).
 *   - It removes such a store ONLY when the variable is not read ANYWHERE in
 *     its scope (unused-variable elimination, not full liveness) - so it can
 *     never drop a value that is still consumed, even across branches/loops.
 *   - It abandons a whole scope on the first sign that variable identity is not
 *     statically knowable there: variable-variables, `extract`/`compact`/
 *     `get_defined_vars`/`parse_str`, `eval`, `include`/`require`, references,
 *     `global`, or `$GLOBALS`. In those scopes nothing is touched.
 *
 * Scopes are analysed independently: the file top level, and each function,
 * method and closure body. A closure's `use` list and an arrow function's
 * captured variables count as reads of the enclosing scope.
 */
class DeadCodeEliminator
{
    private const SUPERGLOBALS = [
        'GLOBALS', '_GET', '_POST', '_SERVER', '_COOKIE', '_FILES', '_ENV', '_REQUEST', '_SESSION', 'this',
    ];

    /** FuncCall names that make every local in the scope dynamically reachable. */
    private const SCOPE_DESTROYERS = [
        'extract', 'compact', 'get_defined_vars', 'parse_str', 'mb_parse_str',
        'func_get_args', 'func_get_arg', 'func_num_args',
    ];

    public function run(array $stmts): array
    {
        $stmts = $this->processScope($stmts, []);
        $stmts = $this->removeDeadFunctions($stmts);
        return $stmts;
    }

    /**
     * Remove top-level (and namespace-level) function definitions whose name is
     * never referenced anywhere - decoder functions left behind after their
     * calls were inlined. Abandoned entirely if the file dispatches
     * dynamically (a variable function, $$, or call_user_func / create_function
     * with a computed callable, function_exists / is_callable introspection),
     * since then a function could be reached by a name we cannot see. A name
     * appearing as a plain string literal also counts as a use (it may be a
     * callable). Iterated to a fixpoint: removing one function can free another.
     *
     * @param Stmt[] $stmts
     * @return Stmt[]
     */
    private function removeDeadFunctions(array $stmts): array
    {
        if ($this->hasDynamicDispatchHazard($stmts)) {
            return $stmts;
        }
        // Only pure functions are safe to drop: an uncalled *impure* function
        // may be a dormant payload the analyst (and the -a security pass) needs
        // to see, so it is kept. A pure decoder left over after inlining has no
        // sink or side effect and only adds noise.
        $resolver = new Resolver();
        try {
            $reg = new NodeTraverser();
            $reg->addVisitor(new ClosureRegistryPrepass($resolver));
            $reg->addVisitor(new UserFunctionPrepass($resolver));
            $reg->traverse($stmts);
            $purity = new PurityAnalyzer($resolver);
        } catch (\Throwable $e) {
            return $stmts; // if we cannot build the purity oracle, remove nothing
        }
        do {
            $used = [];
            foreach ($stmts as $s) {
                $this->collectFunctionUses($s, $used);
            }
            $changed = $this->stripDeadFunctions($stmts, $used, $purity);
        } while ($changed);
        return $stmts;
    }

    /** Drop dead Stmt\Function_ from $stmts and any namespace blocks within it. */
    private function stripDeadFunctions(array &$stmts, array $used, PurityAnalyzer $purity): bool
    {
        $changed = false;
        $kept = [];
        foreach ($stmts as $s) {
            if ($s instanceof Stmt\Function_
                && !isset($used[strtolower($s->name->toString())])
                && $this->isRemovablePureFunction($s, $purity)) {
                $changed = true;
                continue;
            }
            if ($s instanceof Stmt\Namespace_ && is_array($s->stmts)) {
                if ($this->stripDeadFunctions($s->stmts, $used, $purity)) {
                    $changed = true;
                }
            }
            $kept[] = $s;
        }
        $stmts = $kept;
        return $changed;
    }

    private function isRemovablePureFunction(Stmt\Function_ $fn, PurityAnalyzer $purity): bool
    {
        try {
            return $purity->isPure($fn->name->toString());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Record every function name referenced as a call or a bare string literal. */
    private function collectFunctionUses($node, array &$used): void
    {
        if (is_array($node)) {
            foreach ($node as $c) {
                $this->collectFunctionUses($c, $used);
            }
            return;
        }
        if (!($node instanceof Node)) {
            return;
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $used[strtolower(ltrim($node->name->toString(), '\\'))] = true;
        }
        if ($node instanceof Node\Scalar\String_) {
            $used[strtolower(ltrim($node->value, '\\'))] = true;
        }
        foreach ($node->getSubNodeNames() as $sub) {
            $this->collectFunctionUses($node->$sub, $used);
        }
    }

    /** True if the file can call a function by a name that is not statically visible. */
    private function hasDynamicDispatchHazard($node): bool
    {
        if (is_array($node)) {
            foreach ($node as $c) {
                if ($this->hasDynamicDispatchHazard($c)) {
                    return true;
                }
            }
            return false;
        }
        if (!($node instanceof Node)) {
            return false;
        }
        if ($node instanceof Expr\Variable && !is_string($node->name)) {
            return true; // $$x
        }
        if ($node instanceof Expr\FuncCall) {
            if (!($node->name instanceof Node\Name)) {
                return true; // $f(...)
            }
            $fn = strtolower($node->name->toString());
            static $introspect = ['function_exists', 'is_callable', 'method_exists', 'get_defined_functions'];
            if (in_array($fn, $introspect, true)) {
                return true;
            }
            static $dispatch = ['call_user_func', 'call_user_func_array', 'forward_static_call',
                'forward_static_call_array', 'create_function', 'register_shutdown_function',
                'register_tick_function', 'set_error_handler', 'set_exception_handler', 'spl_autoload_register'];
            if (in_array($fn, $dispatch, true)) {
                $first = $node->args[0]->value ?? null;
                if (!($first instanceof Node\Scalar\String_)) {
                    return true; // computed callable
                }
            }
        }
        foreach ($node->getSubNodeNames() as $sub) {
            if ($this->hasDynamicDispatchHazard($node->$sub)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Clean one scope's statement list. $pinnedNames are variables that must be
     * treated as read regardless (by-ref params, closure by-ref captures).
     *
     * @param Stmt[] $stmts
     * @param string[] $pinnedNames
     * @return Stmt[]
     */
    private function processScope(array $stmts, array $pinnedNames): array
    {
        // Clean nested scopes (function/method/closure bodies) first; their
        // locals are independent of ours.
        $this->recurseIntoNestedScopes($stmts);

        if ($this->scopeHasHazard($stmts)) {
            return $stmts;
        }

        $pinned = array_fill_keys($pinnedNames, true);
        foreach (self::SUPERGLOBALS as $sg) {
            $pinned[$sg] = true;
        }

        // Fixpoint: removing `$b = $a;` can make `$a` unused in turn.
        do {
            $reads = [];
            foreach ($stmts as $s) {
                $this->collectReads($s, $reads);
            }
            $changed = $this->stripDeadAssignments($stmts, $reads, $pinned);
        } while ($changed);

        return $stmts;
    }

    /** Recurse into every function-like body reachable without leaving this scope's own control flow. */
    private function recurseIntoNestedScopes(array &$stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->walkForNestedScopes($stmt);
        }
    }

    private function walkForNestedScopes(Node $node): void
    {
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod || $node instanceof Expr\Closure) {
            if (is_array($node->stmts)) {
                $node->stmts = $this->processScope($node->stmts, $this->paramPins($node));
            }
            return; // its own body is a separate scope, handled by the recursive call
        }
        // Arrow functions capture by value implicitly; their single expr is
        // handled as part of the enclosing scope's reads, so nothing to do.
        if ($node instanceof Expr\ArrowFunction) {
            return;
        }
        foreach ($node->getSubNodeNames() as $sub) {
            $child = $node->$sub;
            if ($child instanceof Node) {
                $this->walkForNestedScopes($child);
            } elseif (is_array($child)) {
                foreach ($child as $c) {
                    if ($c instanceof Node) {
                        $this->walkForNestedScopes($c);
                    }
                }
            }
        }
    }

    /** By-ref parameters alias the caller, so their writes are observable: pin them. */
    private function paramPins(Node $fnLike): array
    {
        $pins = [];
        $params = $fnLike->params ?? [];
        foreach ($params as $param) {
            if (($param->byRef ?? false) && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $pins[] = $param->var->name;
            }
        }
        return $pins;
    }

    /**
     * True if the scope contains anything that makes a local reachable by a name
     * we cannot see statically. Walks this scope only (not nested function-like
     * bodies, which are separate scopes).
     */
    private function scopeHasHazard($node): bool
    {
        if (is_array($node)) {
            foreach ($node as $c) {
                if ($c instanceof Node && $this->scopeHasHazard($c)) {
                    return true;
                }
            }
            return false;
        }
        if (!($node instanceof Node)) {
            return false;
        }
        // Do not descend into nested scopes; they are analysed on their own.
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure || $node instanceof Stmt\Class_
            || $node instanceof Stmt\Interface_ || $node instanceof Stmt\Trait_) {
            return false;
        }
        if ($node instanceof Expr\Variable && !is_string($node->name)) {
            return true; // $$x / ${expr}
        }
        if ($node instanceof Expr\Variable && $node->name === 'GLOBALS') {
            return true;
        }
        if ($node instanceof Stmt\Global_ || $node instanceof Expr\AssignRef
            || $node instanceof Expr\Eval_ || $node instanceof Expr\Include_) {
            return true;
        }
        if ($node instanceof Stmt\Static_) {
            return true; // static locals persist across calls; leave them alone
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            if (in_array(strtolower($node->name->toString()), self::SCOPE_DESTROYERS, true)) {
                return true;
            }
        }
        // A dynamic call $f(...) could be extract() et al. behind a variable.
        if ($node instanceof Expr\FuncCall && !($node->name instanceof Node\Name)) {
            return true;
        }
        foreach ($node->getSubNodeNames() as $sub) {
            if ($this->scopeHasHazard($node->$sub)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Record every variable NAME read as an rvalue within this scope. Descends
     * control flow but not nested function/method/closure bodies (their reads
     * of our locals happen only through capture, recorded here explicitly).
     */
    private function collectReads($node, array &$reads): void
    {
        if (is_array($node)) {
            foreach ($node as $c) {
                $this->collectReads($c, $reads);
            }
            return;
        }
        if (!($node instanceof Node)) {
            return;
        }

        // Closures: `use` captures read the enclosing variable (by value or ref).
        if ($node instanceof Expr\Closure) {
            foreach ($node->uses as $use) {
                if (is_string($use->var->name)) {
                    $reads[$use->var->name] = true;
                }
            }
            return; // body is a separate scope
        }
        // Arrow functions implicitly capture (by value) every variable their
        // body references: those references are reads of our scope.
        if ($node instanceof Expr\ArrowFunction) {
            $this->collectReads($node->expr, $reads);
            return;
        }
        // Named function / method / class definitions do not read our locals.
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod
            || $node instanceof Stmt\Class_ || $node instanceof Stmt\Interface_
            || $node instanceof Stmt\Trait_) {
            return;
        }

        // A plain `$v = E` store: the LHS $v is a write, not a read; only E and
        // the rest of the tree are reads. Every other shape that touches $v
        // (compound assign, `$v[...] =`, `$v++`, `&$v`, list()) counts as a read
        // so we never drop it.
        if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $this->collectReads($node->expr, $reads);
            return;
        }

        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $reads[$node->name] = true;
            return;
        }

        foreach ($node->getSubNodeNames() as $sub) {
            $this->collectReads($node->$sub, $reads);
        }
    }

    /**
     * Remove `$v = <pure>;` statements whose $v is unread and unpinned. Recurses
     * into control-flow blocks (if/for/while/foreach/switch/try) within this
     * scope but not into nested function-like bodies. Returns whether anything
     * was removed.
     */
    private function stripDeadAssignments(array &$stmts, array $reads, array $pinned): bool
    {
        $changed = false;
        $kept = [];
        foreach ($stmts as $stmt) {
            if ($this->isDeadAssignment($stmt, $reads, $pinned)) {
                $changed = true;
                continue;
            }
            $this->descendControlFlow($stmt, $reads, $pinned, $changed);
            $kept[] = $stmt;
        }
        $stmts = $kept;
        return $changed;
    }

    private function isDeadAssignment(Node $stmt, array $reads, array $pinned): bool
    {
        if (!($stmt instanceof Stmt\Expression) || !($stmt->expr instanceof Expr\Assign)) {
            return false;
        }
        $assign = $stmt->expr;
        if (!($assign->var instanceof Expr\Variable) || !is_string($assign->var->name)) {
            return false;
        }
        $name = $assign->var->name;
        if (isset($reads[$name]) || isset($pinned[$name])) {
            return false;
        }
        return $this->isSideEffectFree($assign->expr);
    }

    /** Recurse the removal into the statement lists nested in a control-flow node. */
    private function descendControlFlow(Node $node, array $reads, array $pinned, bool &$changed): void
    {
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction
            || $node instanceof Stmt\Class_ || $node instanceof Stmt\Interface_
            || $node instanceof Stmt\Trait_) {
            return; // separate scope
        }
        foreach ($node->getSubNodeNames() as $sub) {
            $child = $node->$sub;
            if (is_array($child) && $this->isStmtList($child)) {
                if ($this->stripDeadAssignments($child, $reads, $pinned)) {
                    $changed = true;
                }
                $node->$sub = $child;
                foreach ($child as $c) {
                    if ($c instanceof Node) {
                        $this->descendControlFlow($c, $reads, $pinned, $changed);
                    }
                }
            } elseif ($child instanceof Node) {
                $this->descendControlFlow($child, $reads, $pinned, $changed);
            } elseif (is_array($child)) {
                foreach ($child as $c) {
                    if ($c instanceof Node) {
                        $this->descendControlFlow($c, $reads, $pinned, $changed);
                    }
                }
            }
        }
    }

    private function isStmtList(array $arr): bool
    {
        foreach ($arr as $x) {
            if (!($x instanceof Stmt)) {
                return false;
            }
        }
        return $arr !== [];
    }

    /**
     * True when evaluating $expr cannot have any observable effect: it reads
     * only literals, variables, constants and pure operators. Anything that
     * could call code (FuncCall, New_, MethodCall, Eval_, Include_, ShellExec,
     * a nested assignment, inc/dec, closure, match, …) is rejected - such a
     * value is kept even if the variable is unread.
     */
    private function isSideEffectFree(Node $expr): bool
    {
        static $pure = [
            Expr\ConstFetch::class, Expr\ClassConstFetch::class, Node\Scalar\String_::class,
            Node\Scalar\LNumber::class, Node\Scalar\DNumber::class, Node\Scalar\Encapsed::class,
            Node\Scalar\EncapsedStringPart::class, Node\Scalar\MagicConst::class,
            Expr\BinaryOp::class, Expr\UnaryMinus::class, Expr\UnaryPlus::class,
            Expr\BooleanNot::class, Expr\BitwiseNot::class,
            Expr\Cast\Int_::class, Expr\Cast\Double::class, Expr\Cast\String_::class,
            Expr\Cast\Bool_::class, Expr\Cast\Array_::class, Expr\Cast\Object_::class,
            Expr\Ternary::class, Expr\ArrayDimFetch::class, Expr\ConstFetch::class,
        ];
        if ($expr instanceof Expr\Variable) {
            return is_string($expr->name);
        }
        if ($expr instanceof Expr\Array_) {
            foreach ($expr->items as $item) {
                if ($item === null) {
                    continue;
                }
                if ($item->byRef) {
                    return false;
                }
                if ($item->key !== null && !$this->isSideEffectFree($item->key)) {
                    return false;
                }
                if (!$this->isSideEffectFree($item->value)) {
                    return false;
                }
            }
            return true;
        }
        $ok = false;
        foreach ($pure as $class) {
            if ($expr instanceof $class) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return false;
        }
        // All sub-expressions must also be pure (e.g. both sides of a BinaryOp).
        foreach ($expr->getSubNodeNames() as $sub) {
            $child = $expr->$sub;
            if ($child instanceof Expr && !$this->isSideEffectFree($child)) {
                return false;
            }
            if (is_array($child)) {
                foreach ($child as $c) {
                    if ($c instanceof Expr && !$this->isSideEffectFree($c)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }
}
