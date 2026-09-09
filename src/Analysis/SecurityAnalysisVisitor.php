<?php

namespace PHPDeobfuscator\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

class SecurityAnalysisVisitor extends \PhpParser\NodeVisitorAbstract
{
    private const SKIP = "\0SKIP";

    private Findings $findings;

    /** Stack of function-like frame names (empty => auto-exec context). */
    private array $frameStack = [];

    /**
     * Parallel to the scope stack: variable-name => taint label, one map per
     * scope (index 0 is the file/global scope). A best-effort forward taint
     * pass that lets `$x = $_POST['c']; eval($x);` be reported as tainted even
     * though the source is one assignment removed from the sink.
     */
    private array $taintStack = [[]];

    /**
     * Stack of name-context entries pushed by Namespace_/Class_/Trait_/Interface_.
     * Each entry: ['kind' => 'namespace'|'class'|'trait'|'interface'|'anon_class', 'name' => string, 'line' => int].
     */
    private array $nameStack = [];

    public function __construct()
    {
        $this->findings = new Findings();
    }

    public function getFindings(): Findings
    {
        return $this->findings;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->nameStack[] = ['kind' => 'namespace', 'name' => $node->name ? $node->name->toString() : '', 'line' => $node->getLine()];
        } elseif ($node instanceof Stmt\Class_) {
            $name = $node->name ? $node->name->toString() : null;
            $this->nameStack[] = $name === null
                ? ['kind' => 'anon_class', 'name' => 'class@line:' . $node->getLine(), 'line' => $node->getLine()]
                : ['kind' => 'class', 'name' => $name, 'line' => $node->getLine()];
        } elseif ($node instanceof Stmt\Trait_) {
            $this->nameStack[] = ['kind' => 'trait', 'name' => $node->name->toString(), 'line' => $node->getLine()];
        } elseif ($node instanceof Stmt\Interface_) {
            $this->nameStack[] = ['kind' => 'interface', 'name' => $node->name->toString(), 'line' => $node->getLine()];
        }

        if ($node instanceof Stmt\Function_) {
            $this->frameStack[] = $this->qualifyFreeFunction($node->name->toString());
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->frameStack[] = $this->qualifyMethod($node->name->toString());
        } elseif ($node instanceof Expr\Closure) {
            $this->frameStack[] = '{closure@line:' . $node->getLine() . '}';
        } elseif ($node instanceof Expr\ArrowFunction) {
            $this->frameStack[] = '{fn@line:' . $node->getLine() . '}';
        }
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->taintStack[] = [];
        }

        $this->recordTaint($node);
        $this->detectSource($node);
        $this->detectSink($node);
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction
        ) {
            array_pop($this->frameStack);
            array_pop($this->taintStack);
        }
        if ($node instanceof Stmt\Namespace_
            || $node instanceof Stmt\Class_
            || $node instanceof Stmt\Trait_
            || $node instanceof Stmt\Interface_
        ) {
            array_pop($this->nameStack);
        }
    }

    /** Reference to the taint map for the current (innermost) scope. */
    private function &currentTaint(): array
    {
        return $this->taintStack[count($this->taintStack) - 1];
    }

    /**
     * Forward-propagate taint through a straight-line assignment so a later
     * sink that reads the variable can be flagged. Best-effort: it follows
     * traversal (document) order and does not reason about branches, so it can
     * miss a flow or, rarely, carry one past a reassignment in another branch.
     */
    private function recordTaint(Node $node): void
    {
        if (($node instanceof Expr\Assign || $node instanceof Expr\AssignOp)
            && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $labels = [];
            $this->collectTaint($node->expr, $labels);
            if ($node instanceof Expr\AssignOp) {
                // `$x .= f($_GET)` keeps whatever $x already carried.
                $map = $this->currentTaint();
                if (isset($map[$node->var->name])) {
                    $labels[$map[$node->var->name]] = true;
                }
            }
            $map =& $this->currentTaint();
            if ($labels) {
                $map[$node->var->name] = implode(', ', array_keys($labels));
            } else {
                unset($map[$node->var->name]);
            }
        }
    }

    /**
     * Collect attacker-controlled / remote taint labels reachable in $node's
     * subtree into $found (used as a set). Does not descend into nested
     * function-like bodies, which have their own scope.
     */
    private function collectTaint(?Node $node, array &$found): void
    {
        if ($node === null) {
            return;
        }
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            static $superglobals = [
                '_GET' => '$_GET', '_POST' => '$_POST', '_REQUEST' => '$_REQUEST',
                '_COOKIE' => '$_COOKIE', '_FILES' => '$_FILES', '_SERVER' => '$_SERVER',
                '_ENV' => '$_ENV', 'GLOBALS' => '$GLOBALS',
            ];
            if (isset($superglobals[$node->name])) {
                $found[$superglobals[$node->name]] = true;
                return;
            }
            $map = $this->currentTaint();
            if (isset($map[$node->name])) {
                $found[$map[$node->name]] = true;
            }
            return;
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $fn = strtolower($node->name->toString());
            if ($fn === 'getenv') {
                $found['getenv()'] = true;
            } elseif (in_array($fn, ['curl_exec', 'curl_multi_exec'], true)) {
                $found['remote (curl)'] = true;
            } elseif (in_array($fn, ['file_get_contents', 'fopen', 'readfile', 'file', 'stream_get_contents', 'get_headers'], true)) {
                $arg0 = $node->args[0]->value ?? null;
                if ($arg0 instanceof Node\Scalar\String_) {
                    if (preg_match('#^\s*(https?|ftps?)://#i', $arg0->value)) {
                        $found['remote URL'] = true;
                    } elseif (preg_match('#^\s*php://(input|stdin)#i', $arg0->value)) {
                        $found['request body (php://input)'] = true;
                    }
                }
            }
        }
        // Do not cross into a nested scope's body.
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction
            || $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
            return;
        }
        foreach ($node->getSubNodeNames() as $sub) {
            $child = $node->$sub;
            if ($child instanceof Node) {
                $this->collectTaint($child, $found);
            } elseif (is_array($child)) {
                foreach ($child as $c) {
                    if ($c instanceof Node) {
                        $this->collectTaint($c, $found);
                    }
                }
            }
        }
    }

    /**
     * A note fragment naming the attacker/remote sources feeding $exprs, or null
     * when none reach them. Merged into the sink finding's note so a report
     * shows "eval ... (tainted by $_POST)".
     *
     * @param Node ...$exprs
     */
    private function taintNote(...$exprs): ?string
    {
        $found = [];
        foreach ($exprs as $e) {
            if ($e instanceof Node) {
                $this->collectTaint($e, $found);
            }
        }
        if (!$found) {
            return null;
        }
        return 'tainted by ' . implode(', ', array_keys($found));
    }

    /** Merge a base note and a taint note into one note string (or null). */
    private static function mergeNote(?string $base, ?string $taint): ?string
    {
        $parts = array_filter([$base, $taint], fn($x) => $x !== null && $x !== '');
        return $parts ? implode('; ', $parts) : null;
    }

    private function detectSource(Node $node): void
    {
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            static $globals = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_FILES', '_SERVER', '_ENV', 'GLOBALS'];
            if (in_array($node->name, $globals, true)) {
                $this->findings->addSource(new Finding(
                    'source',
                    'superglobal',
                    $this->labelForVariable($node),
                    $node->getLine(),
                    $this->currentContext()
                ));
            }
        }

        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && !$node->isFirstClassCallable()) {
            $name = strtolower($node->name->toString());
            if (in_array($name, ['file_get_contents', 'fopen', 'stream_get_contents'], true)) {
                $first = $node->args[0]->value ?? null;
                if ($first instanceof Node\Scalar\String_) {
                    $val = $first->value;
                    if (strpos($val, 'php://input') === 0 || strpos($val, 'php://stdin') === 0) {
                        $stream = (strpos($val, 'php://input') === 0) ? 'php://input' : 'php://stdin';
                        $this->findings->addSource(new Finding(
                            'source',
                            'pseudo_stream',
                            $name . "('" . $stream . "')",
                            $node->getLine(),
                            $this->currentContext()
                        ));
                    }
                }
            }
        }
    }

    private function detectSink(Node $node): void
    {
        if ($node instanceof Expr\Eval_) {
            $this->findings->addSink(new Finding(
                'sink',
                'code_exec',
                'eval',
                $node->getLine(),
                $this->currentContext(),
                $this->taintNote($node->expr)
            ));
            return;
        }
        if ($node instanceof Expr\ShellExec) {
            $this->findings->addSink(new Finding(
                'sink',
                'os_exec',
                'shell_exec',
                $node->getLine(),
                $this->currentContext(),
                self::mergeNote('backticks', $this->taintNote($node))
            ));
            return;
        }
        if ($node instanceof Expr\Include_) {
            if ($node->expr instanceof Node\Scalar\String_) {
                return; // static include — uninteresting
            }
            static $kindLabels = [
                Expr\Include_::TYPE_INCLUDE      => 'include',
                Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
                Expr\Include_::TYPE_REQUIRE      => 'require',
                Expr\Include_::TYPE_REQUIRE_ONCE => 'require_once',
            ];
            $label = $kindLabels[$node->type] ?? 'include';
            $this->findings->addSink(new Finding(
                'sink',
                'dynamic_inc',
                $label,
                $node->getLine(),
                $this->currentContext(),
                self::mergeNote('non-literal arg', $this->taintNote($node->expr))
            ));
            return;
        }
        if ($node instanceof Expr\FuncCall && !($node->name instanceof Node\Name)) {
            $this->findings->addSink(new Finding(
                'sink', 'dispatch', '$variable()', $node->getLine(), $this->currentContext(),
                self::mergeNote('variable function', $this->taintNote($node->name))
            ));
            return;
        }
        if ($node instanceof Expr\MethodCall && !($node->name instanceof Node\Identifier)) {
            $this->findings->addSink(new Finding(
                'sink', 'dispatch', '$obj->$method()', $node->getLine(), $this->currentContext(),
                self::mergeNote('variable method', $this->taintNote($node->name))
            ));
            return;
        }
        if ($node instanceof Expr\NullsafeMethodCall && !($node->name instanceof Node\Identifier)) {
            $this->findings->addSink(new Finding(
                'sink', 'dispatch', '$obj?->$method()', $node->getLine(), $this->currentContext(), 'variable method (nullsafe)'
            ));
            return;
        }
        if ($node instanceof Expr\StaticCall) {
            $variableClass = !($node->class instanceof Node\Name);
            $variableMethod = !($node->name instanceof Node\Identifier);
            if ($variableClass || $variableMethod) {
                if ($variableClass && $variableMethod) {
                    $note = 'variable static method (variable class)';
                } elseif ($variableMethod) {
                    $note = 'variable static method';
                } else {
                    $note = 'variable static class';
                }
                $this->findings->addSink(new Finding(
                    'sink', 'dispatch', 'Class::$$method()', $node->getLine(), $this->currentContext(), $note
                ));
                return;
            }
        }
        // Only flag `new $expr` (variable class). Exclude `new ClassName` (Name)
        // and `new class { ... }` (Stmt\Class_, anonymous-class definition).
        if ($node instanceof Expr\New_ && $node->class instanceof Expr) {
            $this->findings->addSink(new Finding(
                'sink', 'dispatch', 'new $variable', $node->getLine(), $this->currentContext(),
                self::mergeNote('variable class', $this->taintNote($node->class))
            ));
            return;
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && !$node->isFirstClassCallable()) {
            $name = strtolower($node->name->toString());
            $category = DangerousCatalog::lookup($name);
            if ($category !== null) {
                $note = $this->predicateNote($name, $node);
                if ($note === self::SKIP) {
                    return;
                }
                $argExprs = array_map(fn($a) => $a->value, $node->args);
                $note = self::mergeNote($note, $this->taintNote(...$argExprs));
                $this->findings->addSink(new Finding(
                    'sink',
                    $category,
                    $name,
                    $node->getLine(),
                    $this->currentContext(),
                    $note
                ));
            }
        }
    }

    /** Returns null (flag, no note), self::SKIP (don't flag), or a string (flag with note). */
    private function predicateNote(string $name, Expr\FuncCall $node): ?string
    {
        $args = $node->args;
        if ($name === 'header') {
            $first = $args[0]->value ?? null;
            if ($first instanceof Node\Scalar\String_) return self::SKIP;
            return 'non-literal arg';
        }
        if ($name === 'preg_replace' || $name === 'preg_filter') {
            $first = $args[0]->value ?? null;
            if ($first instanceof Node\Scalar\String_) {
                $pattern = $first->value;
                $delim = $pattern[0] ?? '';
                // PHP allows paired bracket delimiters; the closer is what we
                // search for to find the modifier suffix.
                static $pairs = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'];
                $close = $pairs[$delim] ?? $delim;
                $end = strrpos($pattern, $close);
                if ($end === false || $end === 0) {
                    return self::SKIP; // malformed — don't flag
                }
                $modifiers = substr($pattern, $end + 1);
                if (strpos($modifiers, 'e') !== false) {
                    return 'e modifier';
                }
                return self::SKIP;
            }
            return 'non-literal pattern';
        }
        return null;
    }

    private function labelForVariable(Expr\Variable $node): string
    {
        $base = '$' . $node->name;
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Expr\ArrayDimFetch && $parent->var === $node && $parent->dim instanceof Node\Scalar\String_) {
            return $base . "['" . $parent->dim->value . "']";
        }
        return $base;
    }

    private function currentContext(): string
    {
        if (empty($this->frameStack)) return 'auto-exec';
        return 'in-function:' . end($this->frameStack);
    }

    private function qualifyFreeFunction(string $name): string
    {
        $ns = $this->currentNamespace();
        return $ns === '' ? $name : ($ns . '\\' . $name);
    }

    private function qualifyMethod(string $name): string
    {
        // Scan inward (innermost first) for the nearest enclosing class/trait/interface.
        for ($i = count($this->nameStack) - 1; $i >= 0; $i--) {
            $entry = $this->nameStack[$i];
            if (in_array($entry['kind'], ['class', 'trait', 'interface', 'anon_class'], true)) {
                $owner = $entry['kind'] === 'anon_class' ? $entry['name'] : $this->qualifyTypeName($entry['name']);
                return $owner . '::' . $name;
            }
        }
        // No enclosing class — shouldn't happen for ClassMethod, but be safe.
        return $name;
    }

    private function qualifyTypeName(string $name): string
    {
        $ns = $this->currentNamespace();
        return $ns === '' ? $name : ($ns . '\\' . $name);
    }

    private function currentNamespace(): string
    {
        // PHP forbids nested namespace declarations, so at most one entry exists.
        foreach ($this->nameStack as $entry) {
            if ($entry['kind'] === 'namespace') return $entry['name'];
        }
        return '';
    }
}
