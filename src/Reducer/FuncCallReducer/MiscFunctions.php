<?php

namespace PHPDeobfuscator\Reducer\FuncCallReducer;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;

use PHPDeobfuscator\Exceptions;
use PHPDeobfuscator\Reducer\EvalReducer;
use PHPDeobfuscator\Resolver;
use PHPDeobfuscator\Utils;

class MiscFunctions implements FunctionReducer
{
    private $evalReducer;
    private $resolver;

    public function __construct(EvalReducer $evalReducer, Resolver $resolver)
    {
        $this->evalReducer = $evalReducer;
        $this->resolver = $resolver;
    }

    public function getSupportedNames()
    {
        return array(
            'preg_replace',
            'reset',
            'create_function',
            'array_map'
        );
    }

    public function execute($name, array $args, FuncCall $node)
    {
        $args = Utils::refsToValues($args);
        switch ($name) {
        case 'preg_replace':
            return $this->safePregReplace($args[0], $args[1], $args[2]);
        case 'reset':
            // Pass by reference
            $arg = &$args[0];
            return Utils::scalarToNode(reset($arg));
        case 'create_function':
            return $this->createFunction($args[0], $args[1]);
        case 'array_map':
                return $this->staticArrayMap($args[0], $args[1]);
        }
    }

    private function safePregReplace($pattern, $replacement, $subject)
    {
        preg_match('/((\W).*(?:\2|\}|\]|\>))([imsxeADSUXJu]*)/', $pattern, $patternMatch);
        if (!empty($patternMatch)) {
            $modifiers = $patternMatch[3];
            if (strpos($modifiers, 'e') !== false) {
                $pattern = $patternMatch[1] . str_replace('e', '', $modifiers);
                // Try different strategies in order of preference
                // Clone the old scope so we can reset if it fails
                // TODO potential edge case where scope does not completely clone / reset properly
                // alternative is to not retry strategies but try them all in one pass
                $oldScope = $this->resolver->cloneScope();
                $result = $this->evalPregReplace($pattern, $replacement, $subject);
                if ($result === null) {
                    $this->resolver->resetScope($oldScope);
                    $result = $this->obfuscatedEvalPregReplace($pattern, $replacement, $subject);
                }
                if ($result === null) {
                    $this->resolver->resetScope($oldScope);
                    $result = $this->fallbackEvalPregReplace($pattern, $replacement, $subject);
                }
                if ($result === null) {
                    $this->resolver->resetScope($oldScope);
                }

                return $result;
            }
        }
        return Utils::scalarToNode(preg_replace($pattern, $replacement, $subject));
    }

    private function evalPregReplace($pattern, $replacement, $subject)
    {
        $wasSuccessful = true;
        $result = preg_replace_callback($pattern, function($match) use ($replacement, &$wasSuccessful) {
            $rep = $replacement;
            for($i = 1; $i < count($match); $i++) {
                $rep = str_replace(array("\\{$i}", "\${$i}"), addslashes($match[$i]), $rep);
            }
            $expr = null;
            try {
                // Prepend "return" to force $rep to be an expression, throwing if not
                $stmts = $this->evalReducer->runEvalTree("return $rep ?>");
                $expr = $stmts[0]->expr;
            } catch (\Exception $e) {
            }
            try {
                if ($expr !== null) {
                    return Utils::getValue($expr);
                }
            } catch (Exceptions\BadValueException $e) {
            }
            $wasSuccessful = false;
            return "";
        }, $subject);
        if ($wasSuccessful) {
            return Utils::scalarToNode($result);
        }
    }

    // A common obfuscation technique is to embed the payload in a preg_replace
    // Something like: preg_replace("/.*/e", "eval($payload)", ".")
    private function obfuscatedEvalPregReplace($pattern, $replacement, $subject)
    {
        if (preg_match($pattern, $subject, $match)) {
            // If the entire string matched, then it is equivalent to just running the replacement
            // expression on the subject (replacing group references as necessary)
            if ($match[0] == $subject) {
                for($i = 1; $i < count($match); $i++) {
                    $replacement = str_replace(array("\\{$i}", "\${$i}"), addslashes($match[$i]), $replacement);
                }
                try {
                    return $this->evalReducer->runEval("$replacement ?>");
                } catch (\Exception $e) {
                }
            }
        }
        return null;
    }

    private function fallbackEvalPregReplace($pattern, $replacement, $subject)
    {
        // replace markers up to $100
        for($i = 1; $i < 100; $i++) {
            $replacement = str_replace(array("\\{$i}", "\${$i}"), "\$__preg_replace_match_result[$i]", $replacement);
        }
        try {
            $evalNode = $this->evalReducer->runEval("return $replacement ?>");
        } catch (\Exception $e) {
            return null;
        }
        return new FuncCall(new Node\Name('preg_replace'), array(
            new Node\Arg(Utils::scalarToNode($pattern)),
            new Node\Arg($evalNode),
            new Node\Arg(Utils::scalarToNode($subject))
        ));
    }

    private function createFunction($args, $code)
    {
        try {
            // Wrap it into a closure and return that closure
            $stmts = $this->evalReducer->runEvalTree("(function($args) { $code });");
            return $stmts[0]->expr;
        } catch (\Exception $e) {
            return null;
        }
    }
    private function staticArrayMap($callback, $items)
    {
        if (!is_string($callback) || !is_array($items)) {
            return null;
        }
        // Reject things like 'system' or 'eval' early — we are NOT executing arbitrary PHP.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $callback)) {
            return null;
        }

        $out = array();
        foreach ($items as $key => $val) {
            if (!is_scalar($val) && !is_null($val)) {
                return null;        // give up; can't symbolically pass non-scalars yet
            }
            $valSrc = var_export($val, true);
            try {
                // Reuse the eval pipeline: parse a tiny PHP snippet that calls the callback,
                // run it through the full deobf pass, and read the reduced return value.
                $stmts  = $this->evalReducer->runEvalTree("return {$callback}({$valSrc});");
                $expr   = $stmts[0]->expr;
                $out[$key] = Utils::getValue($expr);
            } catch (\Throwable $e) {
                return null;        // anything weird? bail safely
            }
        }
        return Utils::scalarToNode($out);
    }

}
