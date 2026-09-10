<?php

namespace PHPDeobfuscator;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Evidence-driven variable renaming (`-r`): gives an obfuscated-looking variable
 * a readable name ONLY when its role, source or value makes that name provable
 * from the code itself. Runs as the final readability step of `deobfuscate()`,
 * after reduction and dead-code removal, on the live reduced AST.
 *
 * The design constraint is the one the user set: a rename must reflect what the
 * variable is *about*, or it is worthless. Renaming `$O00O0` to `$var1` only
 * trades one meaningless name for another. So this pass never invents a
 * sequential name; it renames only from concrete evidence and leaves every
 * variable it cannot explain exactly as it found it.
 *
 * Evidence recognised (first assignment/use wins per name):
 *   - source:  `$x = $_POST['cmd']`      -> $post_cmd      (superglobal + key)
 *              `$x = getenv('KEY')`        -> $env_key
 *              `$x = base64_decode(...)`   -> $decoded       (also gz-inflate, rot13, hex2bin, pack)
 *              `$x = base64_encode(...)`   -> $encoded
 *              `$x = file_get_contents(...)`/fread/curl_exec -> $contents
 *              `$x = explode(...)`/str_split/preg_split      -> $parts
 *   - value:   `$x = "system"`  (string is a real PHP function name) -> $fn_system
 *   - role:    the callable argument of call_user_func/array_map/... -> $callback
 *              a variable called as a function, `$x(...)`             -> $fn
 *              a for-loop counter (`for(...; $x++)`)                  -> $i
 *              a `.=` accumulator                                     -> $buf
 *
 * Like DeadCodeEliminator this is a conservative pass over attacker code and
 * must never change behaviour. It renames only within a single scope at a time
 * (file top level, and each named function/method body) and abandons a whole
 * scope on the first sign that a variable's identity is reachable by a name it
 * cannot see or must keep stable: variable-variables (`$$x`/`${expr}`),
 * `$GLOBALS`, `global`, `extract`/`compact`/`get_defined_vars`/`parse_str`, or a
 * closure/arrow function (which capture enclosing variables *by name*). Nested
 * closure and arrow bodies are never renamed. New names are collision-checked
 * against every variable already present in the scope, so two distinct
 * variables are never merged.
 */
class VariableRenamer
{
    /** Superglobal variable name => short label used to build a source name. */
    private const SUPERGLOBAL_LABELS = [
        '_GET' => 'get', '_POST' => 'post', '_REQUEST' => 'req', '_COOKIE' => 'cookie',
        '_SERVER' => 'server', '_ENV' => 'env', '_FILES' => 'files', '_SESSION' => 'session',
    ];

    /** Decoder-ish sources: an assignment from one of these reads as "$decoded". */
    private const DECODE_FUNCS = [
        'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13',
        'hex2bin', 'convert_uudecode', 'rawurldecode', 'urldecode', 'pack',
    ];

    private const ENCODE_FUNCS = ['base64_encode', 'gzdeflate', 'gzcompress', 'gzencode', 'bin2hex'];
    private const READ_FUNCS = ['file_get_contents', 'fread', 'stream_get_contents', 'curl_exec', 'file'];
    private const SPLIT_FUNCS = ['explode', 'str_split', 'preg_split', 'mb_str_split'];

    /** Dispatchers whose callable argument (by index) names a "$callback". */
    private const CALLBACK_ARG_INDEX = [
        'call_user_func' => 0, 'call_user_func_array' => 0, 'array_map' => 0,
        'array_filter' => 1, 'array_walk' => 1, 'usort' => 1, 'uasort' => 1,
        'uksort' => 1, 'preg_replace_callback' => 1, 'array_reduce' => 1,
    ];

    /** Constructs that make variable identity unknowable / name-bound in a scope. */
    private const SCOPE_DESTROYERS = [
        'extract', 'compact', 'get_defined_vars', 'parse_str', 'mb_parse_str',
    ];

    public function run(array $stmts): array
    {
        return $this->processScope($stmts);
    }

    /**
     * Rename obfuscated-looking variables in one scope's statement list, then
     * recurse into nested named-function/method bodies as independent scopes.
     *
     * @param Stmt[] $stmts
     * @return Stmt[]
     */
    private function processScope(array $stmts): array
    {
        // Nested named-function bodies are their own scopes; clean them first so
        // recursion order does not matter.
        $this->recurseIntoNestedScopes($stmts);

        if ($this->scopeHasHazard($stmts)) {
            return $stmts;
        }

        $evidence = [];
        foreach ($stmts as $s) {
            $this->collectEvidence($s, $evidence);
        }
        if ($evidence === []) {
            return $stmts;
        }

        $existing = [];
        foreach ($stmts as $s) {
            $this->collectVarNames($s, $existing);
        }

        $map = $this->buildRenameMap($evidence, $existing);
        if ($map !== []) {
            foreach ($stmts as $s) {
                $this->applyRenames($s, $map);
            }
        }
        return $stmts;
    }

    /** Recurse into every named function/method body reachable within this scope. */
    private function recurseIntoNestedScopes(array &$stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->walkForNestedScopes($stmt);
        }
    }

    private function walkForNestedScopes(Node $node): void
    {
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
            if (is_array($node->stmts)) {
                $node->stmts = $this->processScope($node->stmts);
            }
            return; // its body is a separate scope, handled by the recursive call
        }
        // Closures and arrow functions are never renamed (they capture enclosing
        // variables by name); do not descend into them as scopes.
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
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

    /**
     * True if renaming anything in this scope is unsafe. Walks this scope only
     * (not nested named-function bodies, which are analysed on their own).
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
        // Do not descend into nested named scopes; they are handled separately.
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod
            || $node instanceof Stmt\Class_ || $node instanceof Stmt\Interface_
            || $node instanceof Stmt\Trait_) {
            return false;
        }
        // Closures / arrow functions capture enclosing variables by name, so
        // renaming an enclosing local could break a capture we do not rewrite.
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            return true;
        }
        if ($node instanceof Expr\Variable && !is_string($node->name)) {
            return true; // $$x / ${expr}
        }
        if ($node instanceof Expr\Variable && $node->name === 'GLOBALS') {
            return true;
        }
        if ($node instanceof Stmt\Global_) {
            return true; // `global $x` binds $x to $GLOBALS['x'] by name
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name
            && in_array(strtolower($node->name->toString()), self::SCOPE_DESTROYERS, true)) {
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
     * Gather evidence-based labels for variable names in this scope. Does not
     * descend into any function-like body (those are separate scopes). First
     * label wins per name.
     *
     * @param array<string,string> $ev name => label
     */
    private function collectEvidence($node, array &$ev): void
    {
        if (is_array($node)) {
            foreach ($node as $c) {
                $this->collectEvidence($c, $ev);
            }
            return;
        }
        if (!($node instanceof Node)) {
            return;
        }
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            return;
        }

        if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable
            && is_string($node->var->name)) {
            $label = $this->labelFromRhs($node->expr);
            if ($label !== null && !isset($ev[$node->var->name])) {
                $ev[$node->var->name] = $label;
            }
        }

        // Callable argument of a dispatcher: `array_map($cb, ...)` -> $callback.
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $fn = strtolower($node->name->toString());
            if (isset(self::CALLBACK_ARG_INDEX[$fn])) {
                $arg = $node->args[self::CALLBACK_ARG_INDEX[$fn]]->value ?? null;
                if ($arg instanceof Expr\Variable && is_string($arg->name) && !isset($ev[$arg->name])) {
                    $ev[$arg->name] = 'callback';
                }
            }
        }

        // A variable called as a function: `$x(...)` -> $fn.
        if ($node instanceof Expr\FuncCall && $node->name instanceof Expr\Variable
            && is_string($node->name->name) && !isset($ev[$node->name->name])) {
            $ev[$node->name->name] = 'fn';
        }

        // A for-loop counter: `for (...; ...; $x++)` -> $i.
        if ($node instanceof Stmt\For_) {
            foreach ($node->loop as $u) {
                if (($u instanceof Expr\PostInc || $u instanceof Expr\PreInc
                        || $u instanceof Expr\PostDec || $u instanceof Expr\PreDec)
                    && $u->var instanceof Expr\Variable && is_string($u->var->name)
                    && !isset($ev[$u->var->name])) {
                    $ev[$u->var->name] = 'i';
                }
            }
        }

        // A string accumulator: `$x .= ...` -> $buf.
        if ($node instanceof Expr\AssignOp\Concat && $node->var instanceof Expr\Variable
            && is_string($node->var->name) && !isset($ev[$node->var->name])) {
            $ev[$node->var->name] = 'buf';
        }

        foreach ($node->getSubNodeNames() as $sub) {
            $this->collectEvidence($node->$sub, $ev);
        }
    }

    /** Derive a label from the right-hand side of an assignment, or null. */
    private function labelFromRhs(Expr $rhs): ?string
    {
        // $x = $_POST['key']
        if ($rhs instanceof Expr\ArrayDimFetch && $rhs->var instanceof Expr\Variable
            && is_string($rhs->var->name) && isset(self::SUPERGLOBAL_LABELS[$rhs->var->name])) {
            $base = self::SUPERGLOBAL_LABELS[$rhs->var->name];
            $key = ($rhs->dim instanceof Node\Scalar\String_) ? $this->sanitize($rhs->dim->value) : null;
            return $key !== null ? $base . '_' . $key : $base;
        }
        // $x = getenv('KEY') / decoder / reader / splitter
        if ($rhs instanceof Expr\FuncCall && $rhs->name instanceof Node\Name) {
            $fn = strtolower($rhs->name->toString());
            if ($fn === 'getenv') {
                $arg = $rhs->args[0]->value ?? null;
                $key = ($arg instanceof Node\Scalar\String_) ? $this->sanitize($arg->value) : null;
                return $key !== null ? 'env_' . $key : 'env';
            }
            if (in_array($fn, self::DECODE_FUNCS, true)) {
                return 'decoded';
            }
            if (in_array($fn, self::ENCODE_FUNCS, true)) {
                return 'encoded';
            }
            if (in_array($fn, self::READ_FUNCS, true)) {
                return 'contents';
            }
            if (in_array($fn, self::SPLIT_FUNCS, true)) {
                return 'parts';
            }
        }
        // $x = "system"  where the string is a real PHP function name
        if ($rhs instanceof Node\Scalar\String_
            && preg_match('/^\\\\?[a-zA-Z_][a-zA-Z0-9_]*$/', $rhs->value)
            && function_exists($rhs->value)) {
            $clean = $this->sanitize($rhs->value);
            return $clean !== null ? 'fn_' . $clean : null;
        }
        return null;
    }

    /**
     * Build orig-name => new-name for obfuscated-looking names that have
     * evidence, avoiding collisions with any variable already in the scope and
     * with names already chosen.
     *
     * @param array<string,string> $evidence
     * @param array<string,bool>   $existing all variable names present in scope
     * @return array<string,string>
     */
    private function buildRenameMap(array $evidence, array $existing): array
    {
        $map = [];
        $taken = $existing; // new names must dodge every pre-existing var too
        foreach ($evidence as $orig => $label) {
            if (!$this->looksObfuscated($orig)) {
                continue;
            }
            $candidate = $label;
            $i = 2;
            while ($candidate === $orig || (isset($taken[$candidate]) && $candidate !== $orig)) {
                $candidate = $label . $i;
                $i++;
            }
            // Do not rename to a name that is not actually an improvement.
            if ($candidate === $orig) {
                continue;
            }
            $map[$orig] = $candidate;
            $taken[$candidate] = true;
        }
        return $map;
    }

    /**
     * Rename variable occurrences within this scope. Does not descend into any
     * function-like body (closures/arrows are hazards handled upstream; named
     * functions are separate scopes).
     *
     * @param array<string,string> $map orig => new
     */
    private function applyRenames($node, array $map): void
    {
        if (is_array($node)) {
            foreach ($node as $c) {
                $this->applyRenames($c, $map);
            }
            return;
        }
        if (!($node instanceof Node)) {
            return;
        }
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            return;
        }
        if ($node instanceof Expr\Variable && is_string($node->name) && isset($map[$node->name])) {
            $node->name = $map[$node->name];
        }
        foreach ($node->getSubNodeNames() as $sub) {
            $this->applyRenames($node->$sub, $map);
        }
    }

    /** Collect every string variable name used in this scope (not nested bodies). */
    private function collectVarNames($node, array &$names): void
    {
        if (is_array($node)) {
            foreach ($node as $c) {
                $this->collectVarNames($c, $names);
            }
            return;
        }
        if (!($node instanceof Node)) {
            return;
        }
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            return;
        }
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $names[$node->name] = true;
        }
        foreach ($node->getSubNodeNames() as $sub) {
            $this->collectVarNames($node->$sub, $names);
        }
    }

    /**
     * Heuristic: does this look like a machine-generated / obfuscated name whose
     * meaning is not already legible? Real, readable names ($count, $result, a
     * short loop var) return false so we never "improve" a name that is fine.
     */
    private function looksObfuscated(string $name): bool
    {
        if (strlen($name) < 4) {
            return false;
        }
        if (preg_match('/^[O0oIl1]{4,}$/', $name)) {
            return true; // O0Il homoglyph soup
        }
        if (preg_match('/^[a-fA-F0-9_]{6,}$/', $name)) {
            return true; // hex-ish handle
        }
        // Mixed letters+digits of some length that is not a common short idiom.
        if (strlen($name) >= 6 && preg_match('/[a-zA-Z]/', $name) && preg_match('/[0-9]/', $name)
            && !preg_match('/^(v|arg|tmp|str|val|item|key|res|ret|num|idx|col|row)\d*$/', $name)) {
            return true;
        }
        // Long all-lowercase run with almost no vowels: consonant-soup gibberish.
        if (preg_match('/^[a-z]{7,}$/', $name) && !preg_match('/[aeiou].*[aeiou]/', $name)) {
            return true;
        }
        return false;
    }

    /**
     * Reduce an arbitrary array key / string to a name fragment. Used only as a
     * suffix on an alphabetic label (post_, env_, fn_), so a leading digit here
     * is always preceded by that label and yields a valid identifier.
     */
    private function sanitize($value): ?string
    {
        $s = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower((string) $value));
        $s = trim($s, '_');
        return $s === '' ? null : substr($s, 0, 24);
    }
}
