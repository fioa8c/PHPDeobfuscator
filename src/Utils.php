<?php

namespace PHPDeobfuscator;

use League\Flysystem\Filesystem;
use League\Flysystem\PathTraversalDetected;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

use PHPDeobfuscator\ValRef\ArrayVal;
use PHPDeobfuscator\ValRef\ScalarValue;

class Utils
{
    public static function scalarToNode($value, $attrs = array())
    {
        if (is_object($value) || is_resource($value)) {
            // Reducers catch BadValueException and leave the node untouched.
            throw new Exceptions\UnknownValueException("Cannot represent value as a literal node");
        }
        if (!is_array($value)) { // Do this for arrays later
            $attrs[AttrName::VALUE] = new ScalarValue($value);
        }
        if (is_int($value)) {
            return new Scalar\LNumber($value, $attrs);
        }
        if (is_float($value)) {
            return new Scalar\DNumber($value, $attrs);
        }
        if (is_string($value)) {
            return new Scalar\String_($value, array_merge(array('kind' => Scalar\String_::KIND_DOUBLE_QUOTED), $attrs));
        }
        if (is_null($value)) {
            return new Node\Expr\ConstFetch(new Node\Name('null'), $attrs);
        }
        if (is_bool($value)) {
            return new Node\Expr\ConstFetch(new Node\Name($value ? 'true' : 'false'), $attrs);
        }
        if (is_array($value)) {
            $items = array();
            $valArray = array();
            foreach ($value as $key => $val) {
                $valNode = self::scalarToNode($val);
                $keyNode = self::scalarToNode($key);
                $items[] = new Node\Expr\ArrayItem($valNode, $keyNode);
                $valArray[self::getValue($keyNode)] = self::getValueRef($valNode);
            }
            $attrs[AttrName::VALUE] = new ArrayVal($valArray);
            return new Node\Expr\Array_($items, $attrs);
        }
        throw new Exceptions\UnknownValueException("Unknown value type");
    }

    /**
     * Deep-clones a node (or list of nodes) and drops every attribute this
     * pipeline attached to it, so the copy can be re-analysed from scratch as
     * if it had just been parsed. Parser-level attributes (positions, string
     * kind, comments) are kept - the printer needs them.
     *
     * @param Node|Node[] $ast
     * @return Node|Node[]
     */
    public static function cloneAst($ast)
    {
        static $traverser = null;
        if ($traverser === null) {
            $traverser = new \PhpParser\NodeTraverser();
            $traverser->addVisitor(new \PhpParser\NodeVisitor\CloningVisitor());
            $traverser->addVisitor(new class extends \PhpParser\NodeVisitorAbstract {
                private const KEEP = [
                    'comments' => true, 'kind' => true, 'docLabel' => true, 'docIndentation' => true,
                    'rawValue' => true, 'startLine' => true, 'endLine' => true, 'startTokenPos' => true,
                    'endTokenPos' => true, 'startFilePos' => true, 'endFilePos' => true,
                ];
                public function leaveNode(Node $node)
                {
                    $node->setAttributes(array_intersect_key($node->getAttributes(), self::KEEP));
                    return null;
                }
            });
        }
        $isList = is_array($ast);
        $out = $traverser->traverse($isList ? $ast : [$ast]);
        return $isList ? $out : $out[0];
    }

    public static function getValueRef(Node $node)
    {
        $valRef = $node->getAttribute(AttrName::VALUE);
        if ($valRef === null) {
            throw new Exceptions\UnknownValueException("Cannot determine value of node");
        }
        return $valRef;
    }

    public static function getValue(Node $node)
    {
        return self::getValueRef($node)->getValue();
    }

    /**
     * Like getValue(), but refuses objects. An operator applied to an object
     * (`"x" . $obj`, `(string) $obj`) throws a plain \Error that no reducer
     * can model as a value; treat the operand as unknown instead.
     */
    public static function getOperandValue(Node $node)
    {
        $value = self::getValue($node);
        if (is_object($value)) {
            throw new Exceptions\UnknownValueException("Object operand");
        }
        return $value;
    }

    public static function refsToValues(array $refs)
    {
        $values = array();
        foreach ($refs as $ref) {
            $values[] = $ref->getValue();
        }
        return $values;
    }

    public static function safeFileExists(Filesystem $fileSystem, $path)
    {
        if (!is_string($path) || $path === '' || strpos($path, "\0") !== false) {
            return false;
        }
        try {
            return $fileSystem->fileExists($path);
        } catch (\League\Flysystem\FilesystemException | \InvalidArgumentException | \ValueError $e) {
            // PathTraversalDetected, CorruptedPathDetected, UnableToCheckExistence, ...
            return false;
        }
    }

    /**
     * True when evaluating $expr cannot have any observable effect: it reads
     * only literals, variables, constants and pure operators. Anything that
     * could call code (FuncCall, New_, MethodCall, Eval_, Include_, ShellExec,
     * a nested assignment, inc/dec, closure, match, …) is rejected - such a
     * value is kept even if the variable is unread.
     */
    public static function isSideEffectFree(Node $expr): bool
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
                if ($item->key !== null && !self::isSideEffectFree($item->key)) {
                    return false;
                }
                if (!self::isSideEffectFree($item->value)) {
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
            if ($child instanceof Expr && !self::isSideEffectFree($child)) {
                return false;
            }
            if (is_array($child)) {
                foreach ($child as $c) {
                    if ($c instanceof Expr && !self::isSideEffectFree($c)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }
}
