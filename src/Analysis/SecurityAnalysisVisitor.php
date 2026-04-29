<?php

namespace PHPDeobfuscator\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

class SecurityAnalysisVisitor extends \PhpParser\NodeVisitorAbstract
{
    private Findings $findings;

    /** Stack of function-like frame names (empty => auto-exec context). */
    private array $frameStack = [];

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
        }
        if ($node instanceof Stmt\Namespace_
            || $node instanceof Stmt\Class_
            || $node instanceof Stmt\Trait_
            || $node instanceof Stmt\Interface_
        ) {
            array_pop($this->nameStack);
        }
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
    }

    private function detectSink(Node $node): void
    {
        if ($node instanceof Expr\Eval_) {
            $this->findings->addSink(new Finding(
                'sink',
                'code_exec',
                'eval',
                $node->getLine(),
                $this->currentContext()
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
                'backticks'
            ));
            return;
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->toString();
            $category = DangerousCatalog::lookup($name);
            if ($category !== null) {
                $this->findings->addSink(new Finding(
                    'sink',
                    $category,
                    strtolower($name),
                    $node->getLine(),
                    $this->currentContext()
                ));
            }
        }
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
