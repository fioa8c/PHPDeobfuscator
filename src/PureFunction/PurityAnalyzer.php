<?php

namespace PHPDeobfuscator\PureFunction;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPDeobfuscator\Resolver;

/**
 * Decides whether a user-defined function is safe to execute for real in order
 * to resolve its return value (see PureFunctionExecutor).
 *
 * The policy is DEFAULT-DENY: only an explicit allowlist of node types and
 * builtin functions is accepted, and anything unrecognised rejects the whole
 * function. This is the security gate for executing attacker-authored code, so
 * it errs heavily towards refusing.
 *
 * Accepted functions must be self-contained: no globals, superglobals, objects,
 * resources, closures, dynamic calls, output, includes or eval. Static
 * variables are allowed only in the lazy-initialisation idiom
 * (`static $a = null; if ($a === null) { $a = ...; }`) so that a static acts as
 * an immutable memo table and the Nth call returns what the 1st call returns —
 * without that restriction, inlining a call would be unsound (consider
 * `function c() { static $n = 0; return ++$n; }`).
 */
class PurityAnalyzer
{
    /**
     * Side-effect-free builtins. Deliberately excludes anything touching the
     * filesystem, network, process, environment, randomness, time, locale,
     * output or the symbol table.
     */
    private const PURE_BUILTINS = [
        // string
        'addslashes', 'bin2hex', 'chr', 'chunk_split', 'convert_uuencode', 'convert_uudecode',
        'count_chars', 'crc32', 'explode', 'hex2bin', 'html_entity_decode', 'htmlspecialchars',
        'htmlspecialchars_decode', 'implode', 'join', 'lcfirst', 'levenshtein', 'ltrim', 'md5',
        'nl2br', 'number_format', 'ord', 'quotemeta', 'rtrim', 'sha1', 'similar_text', 'soundex',
        'sprintf', 'str_contains', 'str_ends_with', 'str_pad', 'str_repeat', 'str_replace',
        'str_ireplace', 'str_rot13', 'str_split', 'str_starts_with', 'str_word_count', 'strcasecmp',
        'strcmp', 'strcspn', 'strip_tags', 'stripslashes', 'stripos', 'stristr', 'strlen',
        'strnatcasecmp', 'strnatcmp', 'strncasecmp', 'strncmp', 'strpbrk', 'strpos', 'strrchr',
        'strrev', 'strripos', 'strrpos', 'strspn', 'strstr', 'strtolower', 'strtoupper', 'strtr',
        'substr', 'substr_count', 'substr_replace', 'trim', 'ucfirst', 'ucwords', 'vsprintf',
        'wordwrap', 'nl_langinfo', 'money_format', 'metaphone',
        // encoding
        'base64_decode', 'base64_encode', 'urldecode', 'urlencode', 'rawurldecode', 'rawurlencode',
        'json_decode', 'json_encode', 'serialize', 'gzinflate', 'gzuncompress', 'gzdecode',
        'gzcompress', 'gzencode', 'gzdeflate', 'quoted_printable_decode', 'quoted_printable_encode',
        'pack', 'unpack', 'hash', 'crc32b', 'parse_url', 'basename', 'dirname', 'pathinfo',
        // array
        'array_chunk', 'array_combine', 'array_count_values', 'array_diff', 'array_diff_key',
        'array_fill', 'array_fill_keys', 'array_flip', 'array_intersect', 'array_intersect_key',
        'array_key_exists', 'array_key_first', 'array_key_last', 'array_keys', 'array_merge',
        'array_merge_recursive', 'array_pad', 'array_product', 'array_reverse', 'array_search',
        'array_slice', 'array_sum', 'array_unique', 'array_values', 'count', 'in_array', 'range',
        'sizeof', 'compact_placeholder_never_used',
        // math / numeric
        'abs', 'base_convert', 'bindec', 'ceil', 'decbin', 'dechex', 'decoct', 'floor', 'fmod',
        'hexdec', 'intdiv', 'intval', 'is_finite', 'is_infinite', 'is_nan', 'max', 'min', 'octdec',
        'pow', 'round', 'sqrt', 'log', 'log10', 'exp', 'pi', 'floatval', 'doubleval', 'strval',
        'boolval', 'number_format',
        // type predicates
        'gettype', 'is_array', 'is_bool', 'is_callable', 'is_float', 'is_int', 'is_integer',
        'is_long', 'is_null', 'is_numeric', 'is_object', 'is_scalar', 'is_string',
        // regex (no /e, no callbacks - enforced separately)
        'preg_match', 'preg_match_all', 'preg_quote', 'preg_split', 'preg_replace',
    ];

    /** preg_* variants taking a callable are never allowed. */
    private const CALLBACK_FUNCS = [
        'preg_replace_callback', 'preg_replace_callback_array', 'array_map', 'array_filter',
        'array_walk', 'array_reduce', 'usort', 'uasort', 'uksort', 'call_user_func',
        'call_user_func_array', 'forward_static_call', 'forward_static_call_array',
        'register_shutdown_function', 'set_error_handler', 'spl_autoload_register',
    ];

    private const SUPERGLOBALS = [
        'GLOBALS', '_GET', '_POST', '_SERVER', '_COOKIE', '_FILES', '_ENV', '_REQUEST', '_SESSION',
    ];

    private Resolver $resolver;
    /** @var array<string, bool> memo of decided functions */
    private array $decided = [];
    /** @var array<string, true> functions currently being analysed (recursion guard) */
    private array $inProgress = [];
    /** @var array<string, true> names of functions reached, for building the exec payload */
    private array $dependencies = [];

    public function __construct(Resolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * True when $name and every user function it calls are safe to execute.
     */
    /** Root-cause of the most recent rejection (first-wins), for diagnostics. */
    private ?string $rejectReason = null;

    public function lastRejection(): ?string
    {
        return $this->rejectReason;
    }

    /** Record a rejection reason (first one wins, i.e. the leaf cause) and return false. */
    private function rej(string $code): bool
    {
        if ($this->rejectReason === null) {
            $this->rejectReason = $code;
        }
        return false;
    }

    public function isPure(string $name): bool
    {
        $this->dependencies = [];
        $this->rejectReason = null;
        return $this->check($name);
    }

    /**
     * Names of the user functions that must be defined for a pure call to run,
     * including the entry point itself. Only meaningful after isPure() === true.
     *
     * @return string[]
     */
    public function getDependencies(): array
    {
        return array_keys($this->dependencies);
    }

    private function check(string $name): bool
    {
        $key = strtolower($name);
        if (array_key_exists($key, $this->decided)) {
            if ($this->decided[$key]) {
                $this->dependencies[$key] = true;
            }
            return $this->decided[$key];
        }
        if (isset($this->inProgress[$key])) {
            // Recursive call: assume pure, the outer frame decides.
            $this->dependencies[$key] = true;
            return true;
        }
        $func = $this->resolver->getUserFunction($key);
        if ($func === null) {
            return $this->rej('unknown-function');
        }
        $this->inProgress[$key] = true;
        try {
            $ok = $this->checkFunction($func);
        } finally {
            unset($this->inProgress[$key]);
        }
        $this->decided[$key] = $ok;
        if ($ok) {
            $this->dependencies[$key] = true;
        }
        return $ok;
    }

    private function checkFunction(Stmt\Function_ $func): bool
    {
        if ($func->byRef) {
            return $this->rej('byref-return-function');
        }
        foreach ($func->params as $param) {
            if ($param->byRef || $param->variadic) {
                return $this->rej('byref-or-variadic-param');
            }
            if (!($param->var instanceof Expr\Variable) || !is_string($param->var->name)) {
                return $this->rej('complex-param');
            }
            if ($param->default !== null && !$this->checkNode($param->default)) {
                return false;
            }
        }
        $statics = $this->collectStaticNames($func);
        if ($statics === null) {
            return $this->rej('malformed-static');
        }
        foreach ($func->stmts ?? [] as $stmt) {
            if (!$this->checkNode($stmt)) {
                return false;
            }
        }
        if (!$this->staticsOnlyLazyInitialised($func, $statics)) {
            return $this->rej('static-not-lazy-init');
        }
        return true;
    }

    /**
     * @return string[]|null names of static vars, or null if a static decl is malformed
     */
    private function collectStaticNames(Stmt\Function_ $func): ?array
    {
        $names = [];
        $bad = false;
        $this->walk($func->stmts ?? [], function (Node $node) use (&$names, &$bad) {
            if ($node instanceof Expr\Closure || $node instanceof Stmt\Function_) {
                return false; // nested scopes rejected elsewhere
            }
            if ($node instanceof Stmt\Static_) {
                foreach ($node->vars as $var) {
                    if (!is_string($var->var->name)) {
                        $bad = true;
                        return true;
                    }
                    $names[] = $var->var->name;
                }
            }
            return true;
        });
        return $bad ? null : $names;
    }

    /**
     * Every write to a static must sit inside a null-check guard on that same
     * static, so the static behaves as a write-once memo table.
     */
    private function staticsOnlyLazyInitialised(Stmt\Function_ $func, array $statics): bool
    {
        if (!$statics) {
            return true;
        }
        $ok = true;
        $guards = [];
        $this->walkWithGuards($func->stmts ?? [], $guards, function (Node $node, array $guards) use ($statics, &$ok) {
            $target = $this->writeTargetName($node);
            if ($target === null || !in_array($target, $statics, true)) {
                return;
            }
            if (!in_array($target, $guards, true)) {
                $ok = false;
            }
        });
        return $ok;
    }

    /** Name of the variable a write-ish node targets, or null. */
    private function writeTargetName(Node $node): ?string
    {
        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Expr\AssignOp) {
            $var = $node->var;
        } elseif ($node instanceof Expr\PreInc || $node instanceof Expr\PreDec
            || $node instanceof Expr\PostInc || $node instanceof Expr\PostDec) {
            $var = $node->var;
        } else {
            return null;
        }
        while ($var instanceof Expr\ArrayDimFetch) {
            $var = $var->var;
        }
        if ($var instanceof Expr\Variable && is_string($var->name)) {
            return $var->name;
        }
        return null;
    }

    /** Variables proven null-guarded by an if-condition, e.g. `$a === null`. */
    private function guardedByNullCheck(Node\Expr $cond): array
    {
        $names = [];
        if ($cond instanceof Expr\BinaryOp\Identical || $cond instanceof Expr\BinaryOp\Equal) {
            foreach ([[$cond->left, $cond->right], [$cond->right, $cond->left]] as [$a, $b]) {
                if ($a instanceof Expr\Variable && is_string($a->name) && $this->isNullConst($b)) {
                    $names[] = $a->name;
                }
            }
        } elseif ($cond instanceof Expr\BooleanNot) {
            $inner = $cond->expr;
            if ($inner instanceof Expr\Isset_) {
                foreach ($inner->vars as $v) {
                    if ($v instanceof Expr\Variable && is_string($v->name)) {
                        $names[] = $v->name;
                    }
                }
            } elseif ($inner instanceof Expr\FuncCall && $inner->name instanceof Node\Name
                && strtolower($inner->name->toString()) === 'is_array' && isset($inner->args[0])
                && $inner->args[0]->value instanceof Expr\Variable
                && is_string($inner->args[0]->value->name)) {
                $names[] = $inner->args[0]->value->name;
            }
        } elseif ($cond instanceof Expr\FuncCall && $cond->name instanceof Node\Name
            && strtolower($cond->name->toString()) === 'is_null' && isset($cond->args[0])
            && $cond->args[0]->value instanceof Expr\Variable
            && is_string($cond->args[0]->value->name)) {
            $names[] = $cond->args[0]->value->name;
        } elseif ($cond instanceof Expr\Empty_ && $cond->expr instanceof Expr\Variable
            && is_string($cond->expr->name)) {
            $names[] = $cond->expr->name;
        }
        return $names;
    }

    private function isNullConst(Node $n): bool
    {
        return $n instanceof Expr\ConstFetch && strtolower($n->name->toString()) === 'null';
    }

    /**
     * Note: a sub-node slot holds either an array of nodes or a single node.
     * Casting a single Node with (array) yields its properties, not the node,
     * which would silently skip every node reached through a scalar slot -
     * `Stmt\Expression->expr` among them. Both shapes are handled explicitly.
     */
    private function walk($node, callable $fn): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->walk($child, $fn);
            }
            return;
        }
        if (!($node instanceof Node)) {
            return;
        }
        if ($fn($node) === false) {
            return;
        }
        foreach ($node->getSubNodeNames() as $sub) {
            $this->walk($node->$sub, $fn);
        }
    }

    /** Walk carrying the list of variables null-guarded by enclosing ifs. */
    private function walkWithGuards($node, array $guards, callable $fn): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->walkWithGuards($child, $guards, $fn);
            }
            return;
        }
        {
            if (!($node instanceof Node)) {
                return;
            }
            $fn($node, $guards);
            if ($node instanceof Stmt\If_) {
                $inner = array_merge($guards, $this->guardedByNullCheck($node->cond));
                $this->walkWithGuards($node->cond, $guards, $fn);
                $this->walkWithGuards($node->stmts, $inner, $fn);
                $this->walkWithGuards($node->elseifs, $guards, $fn);
                $this->walkWithGuards($node->else, $guards, $fn);
                return;
            }
            if ($node instanceof Expr\Ternary) {
                $inner = array_merge($guards, $this->guardedByNullCheck($node->cond));
                $this->walkWithGuards($node->cond, $guards, $fn);
                $this->walkWithGuards($node->if, $inner, $fn);
                $this->walkWithGuards($node->else, $guards, $fn);
                return;
            }
            foreach ($node->getSubNodeNames() as $sub) {
                $this->walkWithGuards($node->$sub, $guards, $fn);
            }
        }
    }

    /** Default-deny node walk. */
    private function checkNode($node): bool
    {
        if ($node === null || is_scalar($node)) {
            return true;
        }
        if (is_array($node)) {
            foreach ($node as $child) {
                if (!$this->checkNode($child)) {
                    return false;
                }
            }
            return true;
        }
        if (!($node instanceof Node)) {
            return false;
        }

        // --- calls -------------------------------------------------------
        if ($node instanceof Expr\FuncCall) {
            if (!($node->name instanceof Node\Name)) {
                return $this->rej('dynamic-call'); // $f(...)
            }
            $fname = strtolower($node->name->toString());
            if (in_array($fname, self::CALLBACK_FUNCS, true)) {
                return $this->rej('callback-func:' . $fname);
            }
            foreach ($node->args as $arg) {
                if ($arg->unpack || $arg->byRef) {
                    return $this->rej('arg-unpack-or-byref');
                }
            }
            if (!in_array($fname, self::PURE_BUILTINS, true)) {
                // May still be a pure user function.
                if ($this->resolver->getUserFunction($fname) === null) {
                    return $this->rej('non-pure-builtin:' . $fname);
                }
                if (!$this->check($fname)) {
                    return false; // deeper reason already recorded
                }
            } elseif ($fname === 'preg_replace' || $fname === 'preg_match'
                || $fname === 'preg_match_all' || $fname === 'preg_split') {
                // Reject the /e modifier and by-ref match outputs.
                if (count($node->args) > 2 && ($fname === 'preg_match' || $fname === 'preg_match_all')) {
                    return $this->rej('preg-byref-matches');
                }
                $pat = $node->args[0]->value ?? null;
                if (!($pat instanceof Node\Scalar\String_)) {
                    return $this->rej('preg-dynamic-pattern');
                }
                if (preg_match('/[a-zA-Z]*e[a-zA-Z]*$/', substr($pat->value, (int) strrpos($pat->value, $pat->value[0] ?? '/')))) {
                    return $this->rej('preg-e-modifier');
                }
            }
            return $this->checkNode($node->args);
        }

        // --- hard rejects ------------------------------------------------
        static $forbidden = [
            Expr\New_::class, Expr\MethodCall::class, Expr\NullsafeMethodCall::class,
            Expr\StaticCall::class, Expr\PropertyFetch::class, Expr\NullsafePropertyFetch::class,
            Expr\StaticPropertyFetch::class, Expr\Clone_::class, Expr\Eval_::class,
            Expr\Include_::class, Expr\ShellExec::class, Expr\Exit_::class, Expr\Print_::class,
            Expr\Closure::class, Expr\ArrowFunction::class, Expr\Yield_::class, Expr\YieldFrom::class,
            Expr\AssignRef::class, Expr\Throw_::class,
            Expr\ClassConstFetch::class, Expr\Instanceof_::class, Expr\List_::class,
            Stmt\Echo_::class, Stmt\Global_::class, Stmt\Unset_::class, Stmt\Goto_::class,
            Stmt\Label::class, Stmt\InlineHTML::class, Stmt\Function_::class, Stmt\Class_::class,
            Stmt\Interface_::class, Stmt\Trait_::class, Stmt\Throw_::class, Stmt\TryCatch::class,
            Stmt\Declare_::class, Stmt\Namespace_::class, Stmt\Use_::class, Stmt\Const_::class,
            Stmt\HaltCompiler::class,
        ];
        foreach ($forbidden as $class) {
            if ($node instanceof $class) {
                return $this->rej('forbidden:' . (new \ReflectionClass($node))->getShortName());
            }
        }

        // --- variables ---------------------------------------------------
        if ($node instanceof Expr\Variable) {
            if (!is_string($node->name)) {
                return $this->rej('variable-variable'); // $$dynamic
            }
            if (in_array($node->name, self::SUPERGLOBALS, true)) {
                return $this->rej('superglobal:$' . $node->name);
            }
            return true;
        }

        // --- constants ---------------------------------------------------
        if ($node instanceof Expr\ConstFetch) {
            $c = strtolower($node->name->toString());
            return in_array($c, ['true', 'false', 'null'], true);
        }

        // --- explicitly allowed node types -------------------------------
        static $allowed = [
            Node\Scalar\String_::class, Node\Scalar\LNumber::class, Node\Scalar\DNumber::class,
            Node\Scalar\Encapsed::class, Node\Scalar\EncapsedStringPart::class,
            Node\Scalar\MagicConst\Line::class,
            Node\Arg::class, Node\Param::class, Node\Identifier::class, Node\Name::class,
            Node\Name\FullyQualified::class,
            Expr\Array_::class, Node\Expr\ArrayItem::class, Expr\ArrayDimFetch::class,
            Expr\Assign::class, Expr\AssignOp::class, Expr\BinaryOp::class, Expr\UnaryMinus::class,
            Expr\UnaryPlus::class, Expr\BooleanNot::class, Expr\BitwiseNot::class,
            Expr\PreInc::class, Expr\PreDec::class, Expr\PostInc::class, Expr\PostDec::class,
            Expr\Ternary::class, Expr\Isset_::class, Expr\Empty_::class, Expr\Match_::class,
            Node\MatchArm::class, Expr\ErrorSuppress::class,
            Expr\Cast\Int_::class, Expr\Cast\Double::class, Expr\Cast\String_::class,
            Expr\Cast\Bool_::class, Expr\Cast\Array_::class,
            Stmt\Expression::class, Stmt\Return_::class, Stmt\If_::class, Stmt\ElseIf_::class,
            Stmt\Else_::class, Stmt\For_::class, Stmt\Foreach_::class, Stmt\While_::class,
            Stmt\Do_::class, Stmt\Switch_::class, Stmt\Case_::class, Stmt\Break_::class,
            Stmt\Continue_::class, Stmt\Static_::class, Stmt\StaticVar::class, Stmt\Nop::class,
        ];
        $isAllowed = false;
        foreach ($allowed as $class) {
            if ($node instanceof $class) {
                $isAllowed = true;
                break;
            }
        }
        if (!$isAllowed) {
            return $this->rej('unhandled:' . (new \ReflectionClass($node))->getShortName());
        }

        if ($node instanceof Stmt\Foreach_ && $node->byRef) {
            return $this->rej('foreach-byref');
        }

        foreach ($node->getSubNodeNames() as $sub) {
            if (!$this->checkNode($node->$sub)) {
                return false;
            }
        }
        return true;
    }
}
