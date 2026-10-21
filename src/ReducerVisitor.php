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
        $this->tagChildContexts($node);
    }

    /**
     * Tag child expressions with the context their parent gives them, for
     * reducers that are only sound in a pure-read position (see
     * AttrName::READ_CONTEXT / LVALUE). Everything not tagged here is treated
     * as unknown, which is the safe default: a function-call argument, for
     * instance, is never tagged because the callee's by-ref parameters are
     * not knowable.
     */
    private function tagChildContexts(Node $node)
    {
        if ($node instanceof Node\Expr\Assign) {
            self::markLvalue($node->var);
            self::markRead($node->expr);
        } elseif ($node instanceof Node\Expr\AssignRef) {
            self::markLvalue($node->var);
            self::markLvalue($node->expr);
        } elseif ($node instanceof Node\Expr\AssignOp) {
            self::markLvalue($node->var);
        } elseif ($node instanceof Node\Expr\BinaryOp) {
            self::markRead($node->left);
            self::markRead($node->right);
        } elseif ($node instanceof Node\Expr\Ternary) {
            self::markRead($node->cond);
            self::markRead($node->if);
            self::markRead($node->else);
        } elseif ($node instanceof Node\Expr\Array_) {
            // An array literal is a value - unless it is a destructuring
            // target ([$a, $b] = ...), which its own parent has marked.
            $lvalue = $node->getAttribute(AttrName::LVALUE) === true;
            foreach ($node->items as $item) {
                if ($item === null) {
                    continue;
                }
                if ($lvalue) {
                    self::markLvalue($item->value);
                } elseif (!$item->byRef) {
                    self::markRead($item->value);
                }
            }
        } elseif ($node instanceof Node\Expr\List_) {
            foreach ($node->items as $item) {
                if ($item !== null) {
                    self::markLvalue($item->value);
                }
            }
        } elseif ($node instanceof Node\Stmt\Echo_) {
            foreach ($node->exprs as $expr) {
                self::markRead($expr);
            }
        } elseif ($node instanceof Node\Stmt\Return_) {
            self::markRead($node->expr);
        } elseif ($node instanceof Node\Stmt\Foreach_) {
            self::markLvalue($node->keyVar);
            self::markLvalue($node->valueVar);
        } elseif ($node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PreDec
            || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PostDec) {
            self::markLvalue($node->var);
        } elseif ($node instanceof Node\Stmt\Unset_ || $node instanceof Node\Expr\Isset_) {
            foreach ($node->vars as $var) {
                self::markLvalue($var);
            }
        } elseif ($node instanceof Node\Expr\Empty_) {
            self::markLvalue($node->expr);
        }
    }

    private static function markRead(?Node $node)
    {
        if ($node !== null) {
            $node->setAttribute(AttrName::READ_CONTEXT, true);
        }
    }

    private static function markLvalue(?Node $node)
    {
        if ($node !== null) {
            $node->setAttribute(AttrName::LVALUE, true);
        }
    }

    public function leaveNode(Node $node)
    {
        // If Stmt\Expression was forwarded a MaybeStmtArray, now is the time to action it
        if ($node instanceof Node\Stmt\Expression && $node->expr instanceof MaybeStmtArray) {
            return $node->expr->stmts;
        }
        try {
            $newNode = $this->reduceNode($node);
            // Reducer wants to return a statement array, we forward this request if we'e inside a Stmt\Expression
            // Otherwise, use the fallback expression
            if ($newNode instanceof MaybeStmtArray) {
                if ($node->getAttribute(AttrName::IN_EXPR_STMT) === true) {
                    return $newNode;
                }
                return $newNode->expr;
            }
            return $newNode;
        } catch (Exceptions\BadValueException | \TypeError | \DivisionByZeroError | \ValueError | \ArgumentCountError $e) {
            // PHP 8 raises \TypeError on arithmetic with non-numeric strings
            // (e.g. "abc" - 1) and \DivisionByZeroError on x % 0. Treat these
            // the same as BadValueException: skip the reduction, leave the
            // original node intact.
        }
    }

    private function reduceNode(Node $node)
    {
        $className = get_class($node);
        if (isset($this->reducerByClass[$className])) {
            return $this->reducerByClass[$className]->reduce($node, $this);
        }
    }
}
