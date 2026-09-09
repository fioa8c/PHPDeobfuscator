<?php

namespace PHPDeobfuscator\Reducer;

use PhpParser\Node;
use PHPDeobfuscator\Utils;
use PHPDeobfuscator\Exceptions;

class MiscReducer extends AbstractReducer
{
    public function reduceEncapsedString(Node\Scalar\Encapsed $node)
    {
        $newString = '';
        foreach ($node->parts as $part) {
            if ($part instanceof Node\Scalar\EncapsedStringPart) {
                $newString .= $part->value;
            } else {
                try {
                    $value = Utils::getValue($part);
                } catch (\InvalidArgumentException $e) {
                    return null;
                }
                if (is_array($value)) {
                    $newString .= 'Array';
                } elseif (is_object($value)) {
                    return null;
                } else {
                    $newString .= $value;
                }
            }
        }
        return Utils::scalarToNode($newString);
    }

    public function reduceTernary(Node\Expr\Ternary $node)
    {
        return Utils::scalarToNode(Utils::getValue($node->cond) ? Utils::getValue($node->if) : Utils::getValue($node->else));
    }

    public function reduceEcho(Node\Stmt\Echo_ $node)
    {
        $exprs = array();
        foreach ($node->exprs as $expr) {
            try {
                $exprs[] = Utils::scalarToNode(Utils::getValue($expr));
            } catch (Exceptions\UnknownValueException $e) {
                $exprs[] = $expr;
            }
        }
        return new Node\Stmt\Echo_($exprs);
    }

    public function reducePrint(Node\Expr\Print_ $node)
    {
        return new Node\Expr\Print_(Utils::scalarToNode(Utils::getValue($node->expr)));
    }

    public function reduceReturn(Node\Stmt\Return_ $node)
    {
        if ($node->expr === null) {
            return;
        }
        return new Node\Stmt\Return_(Utils::scalarToNode(Utils::getValue($node->expr)));
    }

    /**
     * Rewrites `new $cls(...)` into `new ActualClass(...)` when the class
     * expression provably holds a class name. Obfuscators hide the interesting
     * classes this way - `new $a()` says nothing, `new ZipArchive()` says a lot.
     *
     * Only a syntactically valid class identifier is substituted, so a value
     * that merely happens to be a string cannot produce invalid output. This is
     * a pure readability rewrite: PHP resolves `new $cls` to exactly this class.
     */
    public function reduceNew(Node\Expr\New_ $node)
    {
        if ($node->class instanceof Node\Name || $node->class instanceof Node\Stmt\Class_) {
            return;
        }
        $name = Utils::getValue($node->class);
        if (!is_string($name) || !preg_match('/^\\\\?[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*(\\\\[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*)*$/', $name)) {
            return;
        }
        return new Node\Expr\New_(new Node\Name($name), $node->args, $node->getAttributes());
    }
}
