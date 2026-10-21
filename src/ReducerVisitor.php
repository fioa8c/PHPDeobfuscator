<?php

namespace PHPDeobfuscator;

use PhpParser\Node;

class ReducerVisitor extends \PhpParser\NodeVisitorAbstract
{
    private $reducerByClass = array();
    private $prettyPrinter;

    public function addReducer(Reducer $reducer)
    {
        foreach ($reducer->getNodeClasses() as $className) {
            if (isset($this->reducerByClass[$className])) {
                throw new \RuntimeException("Tried adding {$className} from reducer " . get_class($reducer)
                    . "but was already added from " . get_class($this->reducerByClass[$className]));
            }
            $this->reducerByClass[$className] = $reducer;
        }
    }

    public function enterNode(Node $node)
    {
        // For MaybeStmtArray, we tag the inner expression node as being attached to a Stmt\Expression
        if ($node instanceof Node\Stmt\Expression) {
            $node->expr->setAttribute(AttrName::IN_EXPR_STMT, true);
        }
    }

    public function leaveNode(Node $node)
    {
        // If Stmt\Expression was forwarded a MaybeStmtArray, now is the time to action it
        if ($node instanceof Node\Stmt\Expression && $node->expr instanceof MaybeStmtArray) {
            return $node->expr->stmts;
        }
        $reducer = $this->reducerByClass[get_class($node)] ?? null;
        if ($reducer === null) {
            return null; // nothing to do, and no handler churn for the majority of nodes
        }
        // Builtins and operators evaluated on hostile literals raise warnings and
        // deprecations ("5 apples" + 1, (string) [1]). The result is still what
        // PHP would compute; the diagnostic must not reach stdout, where the
        // CLI writes the deobfuscated source.
        set_error_handler(static function () { return true; }, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);
        try {
            $newNode = $reducer->reduce($node, $this);
            // Reducer wants to return a statement array, we forward this request if we'e inside a Stmt\Expression
            // Otherwise, use the fallback expression
            if ($newNode instanceof MaybeStmtArray) {
                if ($node->getAttribute(AttrName::IN_EXPR_STMT) === true) {
                    return $newNode;
                }
                return $newNode->expr;
            }
            return $newNode;
        } catch (Exceptions\BadValueException | \TypeError | \ArithmeticError | \ValueError | \ArgumentCountError $e) {
            // PHP 8 raises \TypeError on arithmetic with non-numeric strings
            // (e.g. "abc" - 1), \DivisionByZeroError on x % 0 and its parent
            // \ArithmeticError on 1 << -1 or intdiv(PHP_INT_MIN, -1). Treat these
            // the same as BadValueException: skip the reduction, leave the
            // original node intact.
        } catch (\Error $e) {
            // Anything else PHP throws while evaluating attacker-chosen operands
            // (e.g. an object in string context). The robustness invariant is
            // "degrade to unknown, never abort the file": keep the node and
            // leave a breadcrumb.
            error_log('PHPDeobfuscator: reduction skipped (' . $e->getMessage() . ')');
        } finally {
            restore_error_handler();
        }
    }
}
