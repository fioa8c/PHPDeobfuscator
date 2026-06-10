<?php

namespace PHPDeobfuscator;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Expr;

use PHPDeobfuscator\ValRef\ByReference;
use PHPDeobfuscator\ValRef\GlobalVarArray;
use PHPDeobfuscator\ValRef\ScalarValue;
use PHPDeobfuscator\VarRef\ArrayAccessVariable;
use PHPDeobfuscator\VarRef\ListVarRef;
use PHPDeobfuscator\VarRef\LiteralName;
use PHPDeobfuscator\VarRef\FutureVarRef;
use PHPDeobfuscator\VarRef\PropertyAccessVariable;
use PHPDeobfuscator\VarRef\UnknownVarRef;

class Resolver extends \PhpParser\NodeVisitorAbstract
{

    private $scope = null;
    private $globalScope;
    private $nameScope;
    private $constants;

    /** @var array<string, \PhpParser\Node\Expr\Closure> */
    private array $globalClosures = [];

    /** @var array<string, \PhpParser\Node\Stmt\Function_> */
    private array $userFunctions = [];

    public function __construct()
    {
        // TODO This was in beforeTraverse but we want to share globals
        // between calls to eval so can't reset on each traversal.
        // Maybe make an option to share certain things in eval
        $this->scope = null;
        $this->newScope('global');
        $this->scope->setSuperGlobal('GLOBALS', new GlobalVarArray($this));
        $this->globalScope = $this->scope;
        $this->nameScope = array(
            'function' => '',
            'namespace' => '',
            'class' => '',
            'method' => '',
            'trait' => ''
        );
        $this->constants = array(
            'PHP_EOL' => "\n"
        );
    }

    public function enterNode(Node $node)
    {
        $mutableContext = $node->getAttribute('enterMutableContext');
        if ($mutableContext !== null) {
            // true => mark every variable mutable; an array => only those names.
            $this->setCurrentVarsMutable($mutableContext === true ? null : $mutableContext);
        }
        $this->updateNameScope($node, true);
        if ($this->changesScope($node)) {
            $this->newScope($this->nameForScope($node));
            // Inherit variables from the use clause
            if ($node instanceof Expr\Closure) {
                foreach ($node->uses as $use) {
                    $var = new LiteralName($use->var->name);
                    $parentScope = $this->scope->getParent();
                    if ($use->byRef) {
                        $val = new ByReference($var, $parentScope);
                    } else {
                        $val = $var->getValue($parentScope);
                    }
                    // Only assign if variable is known
                    if ($val !== null) {
                        $var->assignValue($this->scope, $val);
                    }
                }
            }
        }
        // Transform AssignOp into the longer form BinaryOp
        if ($node instanceof Expr\AssignOp) {
            $op = str_replace('AssignOp', 'BinaryOp', get_class($node));
            return new Expr\Assign($node->var, new $op($node->var, $node->expr));
        }

        if ($node instanceof Stmt\For_) {
            // Everything except the init expression. Only variables that the
            // loop may reassign become mutable inside it; loop-invariant ones
            // keep their value (see loopMutatedNames).
            $names = $this->loopMutatedNames($node);
            $this->setNodesInMutableContext($node->cond, $names);
            $this->setNodesInMutableContext($node->loop, $names);
            $this->setNodesInMutableContext($node->stmts, $names);
        }
        if ($node instanceof Stmt\Foreach_) {
            $this->setNodesInMutableContext($node->stmts, $this->loopMutatedNames($node));
        }
        if ($node instanceof Stmt\While_ || $node instanceof Stmt\Do_) {
            $this->setCurrentVarsMutable($this->loopMutatedNames($node));
        }
        if ($node instanceof Stmt\Case_ || $node instanceof Stmt\Label) {
            // Conditional execution: any variable might or might not be written.
            $this->setCurrentVarsMutable();
        }
    }

    private function setNodesInMutableContext(array $nodes, ?array $names = null)
    {
        foreach ($nodes as $node) {
            // true (null $names) => mark all mutable; an array => only those names.
            $node->setAttribute('enterMutableContext', $names === null ? true : $names);
            return;
        }
    }

    private function nodeCanBranch(Node $node)
    {
        return $node instanceof Stmt\If_
            || $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
            || $node instanceof Stmt\Switch_;
    }

    public function leaveNode(Node $node)
    {
        $retNode = null;
        $this->updateNameScope($node, false);
        if ($this->changesScope($node)) {
            $this->leaveScope();
        }
        if ($node instanceof Expr\Assign) {
            $this->onAssign($node);
            // Try to transform BinaryOp back into AssignOp
            if ($node->expr instanceof Expr\BinaryOp) {
                $op = str_replace('BinaryOp', 'AssignOp', get_class($node->expr));
                if (class_exists($op)) {
                    $varRef = $this->resolveVariable($node->var);
                    $leftVar = $this->resolveVariable($node->expr->left);
                    $isVarRef = !($leftVar instanceof UnknownVarRef) || !$leftVar->notAVarRef();
                    // If they are the same reference then we can combine
                    if ($isVarRef && $varRef == $leftVar) {
                        $retNode = new $op($node->expr->left, $node->expr->right);
                    }
                }
            }
        }
        if ($node instanceof Expr\AssignRef) {
            $this->onAssignRef($node);
        }
        if ($node instanceof Stmt\Unset_) {
            $this->onUnset($node);
        }
        if ($node instanceof Stmt\Global_) {
            foreach ($node->vars as $var) {
                $var = $this->resolveVariable($var);
                $val = $var->getValue($this->getGlobalScope());
                $this->assign($var, $val);
            }
        }
        if ($node instanceof Expr\FuncCall) {
            $this->onFuncCall($node);
        }
        if ($this->nodeCanBranch($node)) {
            $this->setCurrentVarsMutable();
        }
        return $retNode;
    }

    /**
     * Mark variables in the current scope mutable. With $names === null every
     * variable is marked (the conservative default); otherwise only the named
     * ones are, leaving loop-invariant variables reducible.
     */
    private function setCurrentVarsMutable(?array $names = null)
    {
        foreach ($this->scope->getVariables() as $name => $var) {
            if ($names === null || in_array($name, $names, true)) {
                $var->setMutable(true);
            }
        }
    }

    /**
     * Names of local variables that may be reassigned within one iteration of a
     * loop, or null if the body contains an unanalysable mutation (variable
     * variables, extract/parse_str) - in which case the caller must treat every
     * variable as mutable. See spec 2026-06-10-loop-invariant-resolution-design.
     */
    private function loopMutatedNames(Node $loop): ?array
    {
        $names = array();
        $roots = array();
        if ($loop instanceof Stmt\Foreach_) {
            foreach (array($loop->keyVar, $loop->valueVar) as $target) {
                if ($target !== null && !$this->collectAssignTarget($target, $names)) {
                    return null;
                }
            }
            $roots = $loop->stmts;
        } elseif ($loop instanceof Stmt\For_) {
            $roots = array_merge($loop->cond, $loop->loop, $loop->stmts);
        } elseif ($loop instanceof Stmt\While_) {
            $roots = $loop->stmts;
            $roots[] = $loop->cond;
        } elseif ($loop instanceof Stmt\Do_) {
            $roots = $loop->stmts;
            $roots[] = $loop->cond;
        } else {
            return null;
        }
        foreach ($roots as $root) {
            if ($root !== null && !$this->collectMutations($root, $names)) {
                return null;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Walk a subtree collecting the base names of assignment targets into
     * $names. Returns false if an unanalysable mutation is found. Does not
     * descend into nested function/closure/class bodies (separate scope), but
     * records variables a closure captures by reference.
     */
    private function collectMutations(Node $node, array &$names): bool
    {
        if ($node instanceof Expr\Closure) {
            foreach ($node->uses as $use) {
                if ($use->byRef && is_string($use->var->name)) {
                    $names[] = $use->var->name;
                }
            }
            return true;
        }
        if ($node instanceof Expr\ArrowFunction
            || $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Stmt\ClassLike) {
            return true;
        }

        if ($node instanceof Expr\Assign
            || $node instanceof Expr\AssignRef
            || $node instanceof Expr\AssignOp) {
            if (!$this->collectAssignTarget($node->var, $names)) {
                return false;
            }
            // Walk both sides for embedded assignments / unanalysable calls
            // (e.g. assignments inside an array-dimension expression).
            return $this->collectMutations($node->var, $names)
                && $this->collectMutations($node->expr, $names);
        }
        if ($node instanceof Expr\PreInc || $node instanceof Expr\PreDec
            || $node instanceof Expr\PostInc || $node instanceof Expr\PostDec) {
            return $this->collectAssignTarget($node->var, $names);
        }
        if ($node instanceof Stmt\Unset_) {
            foreach ($node->vars as $var) {
                if (!$this->collectAssignTarget($var, $names)) {
                    return false;
                }
            }
            return true;
        }
        if ($node instanceof Stmt\Global_) {
            foreach ($node->vars as $var) {
                if (!($var instanceof Expr\Variable) || !is_string($var->name)) {
                    return false;
                }
                $names[] = $var->name;
            }
            return true;
        }
        if ($node instanceof Stmt\Static_) {
            foreach ($node->vars as $staticVar) {
                if (!is_string($staticVar->var->name)) {
                    return false;
                }
                $names[] = $staticVar->var->name;
            }
            return true;
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $fn = strtolower($node->name->toString());
            // These create arbitrary locals from runtime data we cannot see.
            if ($fn === 'extract'
                || (($fn === 'parse_str' || $fn === 'mb_parse_str') && count($node->args) < 2)) {
                return false;
            }
        }
        if ($node instanceof Stmt\Foreach_) {
            // Nested-loop targets are reassigned each iteration too.
            foreach (array($node->keyVar, $node->valueVar) as $target) {
                if ($target !== null && !$this->collectAssignTarget($target, $names)) {
                    return false;
                }
            }
            // Fall through to walk the iterated expression and body.
        }

        foreach ($node->getSubNodeNames() as $subName) {
            $sub = $node->$subName;
            if ($sub instanceof Node) {
                if (!$this->collectMutations($sub, $names)) {
                    return false;
                }
            } elseif (is_array($sub)) {
                foreach ($sub as $child) {
                    if ($child instanceof Node && !$this->collectMutations($child, $names)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * Record the base local variable name(s) written by an assignment target.
     * Returns false if the target is unanalysable (variable-variable, dynamic
     * property/name, or an unrecognised shape).
     */
    private function collectAssignTarget(Expr $target, array &$names): bool
    {
        if ($target instanceof Expr\Variable) {
            if (!is_string($target->name)) {
                return false; // $$x - variable variable
            }
            $names[] = $target->name;
            return true;
        }
        if ($target instanceof Expr\ArrayDimFetch
            || $target instanceof Expr\PropertyFetch
            || $target instanceof Expr\NullsafePropertyFetch) {
            return $this->collectAssignTarget($target->var, $names);
        }
        if ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
            foreach ($target->items as $item) {
                if ($item !== null && !$this->collectAssignTarget($item->value, $names)) {
                    return false;
                }
            }
            return true;
        }
        if ($target instanceof Expr\StaticPropertyFetch) {
            return true; // class static, not a local variable
        }
        return false; // unknown target shape - be safe
    }

    private function changesScope(Node $node)
    {
        // ArrowFunction pushes a fresh scope so params don't leak. Auto-capture
        // by value is intentionally not modelled — outer scalar vars appear
        // unknown inside the body. See spec 2026-04-29-arrow-function-scope-design.
        return $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction;
    }

    private function nameForScope(Node $node)
    {
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
            return $this->nameScope['function'];
        }
        if ($node instanceof Expr\Closure) {
            return 'closure';
        }
        return $node->getType();
    }

    private function newScope($name)
    {
        $this->scope = new Scope($name, $this->scope);
    }

    private function updateNameScope(Node $node, $isEnter)
    {
        $key = null;
        if ($node instanceof Stmt\Namespace_) {
            $key = 'namespace';
        } elseif ($node instanceof Stmt\Function_) {
            $key = 'function';
        } elseif ($node instanceof Stmt\Class_) {
            $key = 'class';
        } elseif ($node instanceof Stmt\ClassMethod) {
            $key = 'method';
        } elseif ($node instanceof Stmt\Trait_) {
            $key = 'trait';
        } else {
            return;
        }
        if ($isEnter) {
            // name is either Name or Identifier, both have toString
            $name = $node->name ? $node->name->toString() : '';
            if ($key == 'method') {
                // function is set to the name of the method
                $this->nameScope['function'] = $name;
                $parentName = $this->nameScope['class'] . $this->nameScope['trait'];
                if ($parentName) {
                    $name = $parentName . '::' . $name;
                }
            }
            // If we've entered into a trait, the class can't be known
            if ($key == 'trait') {
                $this->nameScope['class'] = null;
            }
            if (in_array($key, array('class', 'trait', 'function')) && $this->nameScope['namespace']) {
                $name = $this->nameScope['namespace'] . '\\' . $name;
            }
            if ($key == 'function') {
                $this->nameScope['method'] = $name;
            }
            $this->nameScope[$key] = $name;
        } else {
            $this->nameScope[$key] = '';
            if ($key == 'method') {
                $this->nameScope['function'] = '';
            }
            if ($key == 'function') {
                $this->nameScope['method'] = '';
            }
            if ($key == 'trait') {
                $this->nameScope['class'] = '';
            }
        }
    }

    private function leaveScope()
    {
        $this->scope = $this->scope->getParent();
    }

    public function getConstant($name)
    {
        if (isset($this->constants[$name])) {
            return new ScalarValue($this->constants[$name]);
        }
        // PHP assumes a string of the name of the constant
        return new ScalarValue($name);
    }

    public function getCurrentScope()
    {
        return $this->scope;
    }

    public function getGlobalScope()
    {
        return $this->globalScope;
    }

    public function registerGlobalClosure(string $name, Expr\Closure $closure): void
    {
        $this->globalClosures[$name] = $closure;
    }

    public function getGlobalClosure(string $name): ?Expr\Closure
    {
        return $this->globalClosures[$name] ?? null;
    }

    public function registerUserFunction(string $name, Stmt\Function_ $func): void
    {
        // PHP function names are case-insensitive; normalise to lowercase.
        $this->userFunctions[strtolower($name)] = $func;
    }

    public function getUserFunction(string $name): ?Stmt\Function_
    {
        return $this->userFunctions[strtolower($name)] ?? null;
    }

    public function cloneScope()
    {
        // TODO nameScope and constants
        return clone $this->scope;
    }

    public function resetScope(Scope $scope)
    {
        $this->scope = clone $scope;
        // Reset globalScope to ensure correct reference
        do {
            $this->globalScope = $scope;
            $scope = $scope->getParent();
        } while ($scope != null);
    }

    public function currentClass()
    {
        return $this->nameScope['class'];
    }

    public function currentFunction()
    {
        return $this->nameScope['function'];
    }

    public function currentMethod()
    {
        return $this->nameScope['method'];
    }

    public function currentNamespace()
    {
        return $this->nameScope['namespace'];
    }

    public function currentTrait()
    {
        return $this->nameScope['trait'];
    }

    private function onFuncCall(Expr\FuncCall $expr)
    {
        $name = null;
        if ($expr->name instanceof Node\Name) {
            $name = $expr->name->toString();
        } else {
            $nameRef = $this->resolveValue($expr->name);
            if ($nameRef !== null && !$nameRef->isMutable()) {
                $name = $nameRef->getValue();
            }
        }
        if ($name === null) {
            return;
        }
        $argCount = count($expr->args);
        // Should set vars mutable here if name is null due to the chance that
        // it's really parse_str or extract, but that's unlikely so don't ruin all variables
        // for something very unlikely
        switch ($name) {
        case 'parse_str':
            if ($argCount > 1) {
                break;
            }
        case 'extract':
            $this->setCurrentVarsMutable();
            break;
        case 'define':
            if ($argCount >= 2) {
                $this->onDefine($expr->args[0]->value, $expr->args[1]->value);
            }
            break;
        }
    }

    private function onDefine(Expr $name, Expr $value)
    {
        $nameRef = $this->resolveValue($name);
        if ($nameRef === null || $nameRef->isMutable()) {
            return;
        }
        $valRef = $this->resolveValue($value);
        if ($valRef === null || $valRef->isMutable() || !($valRef instanceof ScalarValue)) {
            return;
        }
        $constName = $nameRef->getValue();
        if (array_key_exists($constName, $this->constants)) {
            return; // PHP won't override existing constants
        }
        $this->constants[$constName] = $valRef->getValue();
    }

    private function onAssign(Expr\Assign $expr)
    {
        $varRef = $this->resolveVariable($expr->var);
        $valRef = $this->resolveValue($expr->expr);
        $this->assign($varRef, $valRef);
    }

    private function onAssignRef(Expr\AssignRef $expr)
    {
        $var = $this->resolveVariable($expr->var);
        $ref = $this->resolveVariable($expr->expr);
        if (!($ref instanceof UnknownVarRef) || !$ref->notAVarRef()) {
            $val = new ByReference($ref, $this->scope);
        } else {
            // Possible assignment to a non-variable - just a normal assignment
            $val = $this->resolveValue($expr->expr);
        }
        $this->assign($var, $val);
    }

    private function assign(VarRef $var, ?ValRef $val = null)
    {
        $didAssign = false;
        if ($val !== null) {
            while (($oldValue = $var->getValue($this->scope)) instanceof ByReference) {
                $var = $oldValue->getVariable();
            }
            $didAssign = $var->assignValue($this->scope, $val);
        }
        if (!$didAssign) {
            if ($var instanceof UnknownVarRef) {
                if ($var->getContext() === null) {
                    // If this was an unknown variable assignment with no parent context, all bets are off
                    $this->setCurrentVarsMutable();
                } else {
                    // Otherwise, only the parent needs to be set mutable
                    $var = $var->getContext();
                }
            }
            if ($var instanceof ListVarRef) {
                foreach ($var->getVars() as $listVar) {
                    if ($listVar === null) {
                        continue;
                    }
                    $oldValue = $listVar->getValue($this->scope);
                    if ($oldValue !== null) {
                        $oldValue->setMutable(true);
                    }
                }
            } else {
                $oldValue = $var->getValue($this->scope);
                if ($oldValue !== null) {
                    $oldValue->setMutable(true);
                }
            }
        }
    }

    private function onUnset(Stmt\Unset_ $stmt)
    {
        foreach ($stmt->vars as $expr) {
            $var = $this->resolveVariable($expr);
            $var->unsetVar($this->scope);
        }
    }

    private function resolveValue(Expr $expr, $tryUnknownVar = false)
    {
        try {
            return Utils::getValueRef($expr);
        } catch (Exceptions\UnknownValueException $e) {
            if ($tryUnknownVar) {
                return $this->resolveVariable($expr)->getValue($this->scope);
            }
            return null;
        }
    }

    // See FutureVarRef for why $tryUnknownVar is needed
    public function resolveVariable(Expr $var, $tryUnknownVar = false)
    {
        if ($var instanceof Expr\Variable) {
            $varName = $var->name;
            if (is_string($varName)) {
                return new LiteralName($varName);
            } else {
                $nameRef = $this->resolveValue($varName, $tryUnknownVar);
                if ($nameRef !== null && !$nameRef->isMutable()) {
                    // Replace name in tree
                    $var->name = $nameRef->getValue();
                    return new LiteralName($nameRef->getValue());
                }
                return UnknownVarRef::$ANY;
            }
        } elseif ($var instanceof Expr\List_) {
            $vars = array();
            foreach ($var->items as $item) {
                if ($item === null) {
                    $vars[] = null;
                    continue;
                }
                if($item->key !== null || $item->byRef) {
                    throw new \Exception("Don't know how to handle element in list()");
                }
                $varExpr = $item->value;
                $varRef = $this->resolveVariable($varExpr, $tryUnknownVar);
                if ($varRef instanceof UnknownVarRef) {
                    $varRef = new FutureVarRef($varExpr, $this);
                }
                $vars[] = $varRef;
            }
            return new ListVarRef($vars);
        } elseif ($var instanceof Expr\ArrayDimFetch) {
            $arrVar = $this->resolveVariable($var->var, $tryUnknownVar);
            if ($arrVar instanceof UnknownVarRef) {
                return $arrVar;
            }
            if ($var->dim === null) { // e.g. $arr[] = 1;
                $dim = new ScalarValue(null);
            } else {
                $dim = $this->resolveValue($var->dim, $tryUnknownVar);
            }
            if ($dim !== null && !$dim->isMutable()) {
                return new ArrayAccessVariable($arrVar, $dim->getValue());
            }
            return new UnknownVarRef($arrVar);
        } elseif ($var instanceof Expr\PropertyFetch) {
            $objVar = $this->resolveVariable($var->var, $tryUnknownVar);
            if ($objVar instanceof UnknownVarRef) {
                return $objVar;
            }
            if ($var->name instanceof Expr) {
                $nameVal = $this->resolveValue($var->name, $tryUnknownVar);
                if ($nameVal !== null && !$nameVal->isMutable()) {
                    $name = $nameVal->getValue();
                } else {
                    $name = null;
                }
            } else {
                $name = $var->name->name;
            }
            if ($name !== null) {
                return new PropertyAccessVariable($objVar, $name);
            }
            return new UnknownVarRef($objVar);
        } elseif ($var instanceof Expr\StaticPropertyFetch) {
            // TODO
        }
        return UnknownVarRef::$NOT_A_VAR_REF;
    }
}
