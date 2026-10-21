<?php

namespace PHPDeobfuscator\Reducer\FuncCallReducer;

use PhpParser\Node\Expr\FuncCall;
use PHPDeobfuscator\Utils;

/**
 * Pure builtins that are evaluated for real when every argument is known.
 *
 * Everything here must be deterministic, free of side effects and independent
 * of the host (no time, locale, randomness, filesystem, network, environment,
 * symbol table). Functions taking callbacks are handled elsewhere or refused.
 *
 * The arguments are attacker-chosen literals, so a call may also be a bomb:
 * str_repeat(x, 1e9), pack("a2000000000", x), implode of a 200k-element array
 * of 8 MB strings, similar_text on two multi-megabyte strings. The post-hoc
 * size check on the result comes too late for those (the allocation or the
 * CPU time happens inside the builtin), so guard() bounds the worst-case
 * output or work from the arguments before the call is made.
 */
class PassThrough implements FunctionReducer
{
    /** Largest string, or total array payload, a guarded function may produce. */
    const MAX_RESULT_BYTES = 8 * 1024 * 1024;
    const MAX_RESULT_ITEMS = 200000;
    /** Longest operands for the quadratic/cubic string metrics; above these they take seconds to minutes. */
    const MAX_LEVENSHTEIN_BYTES = 16384; // O(n*m): ~0.2s at this size
    const MAX_SIMILAR_TEXT_BYTES = 2048; // up to O(n^3): 4096 already takes ~4s

    private int $maxResultBytes;
    private int $maxResultItems;

    /**
     * The limits are overridable so a fixture can exercise the guards with
     * small literals instead of multi-megabyte ones.
     */
    public function __construct(?int $maxResultBytes = null, ?int $maxResultItems = null)
    {
        $this->maxResultBytes = $maxResultBytes ?? self::MAX_RESULT_BYTES;
        $this->maxResultItems = $maxResultItems ?? self::MAX_RESULT_ITEMS;
    }

    public function getSupportedNames()
    {
        return array(
            // decoding / encoding
            'base64_decode', 'base64_encode', 'bin2hex', 'hex2bin', 'convert_uudecode', 'convert_uuencode',
            'gzinflate', 'gzuncompress', 'gzdecode', 'gzdeflate', 'gzcompress', 'gzencode',
            'quoted_printable_decode', 'quoted_printable_encode', 'rawurldecode', 'rawurlencode',
            'urldecode', 'urlencode', 'http_build_query', 'str_rot13', 'strrev', 'pack', 'unpack',
            'json_decode', 'json_encode', 'serialize', 'htmlspecialchars', 'htmlspecialchars_decode',
            'html_entity_decode', 'htmlentities', 'addslashes', 'stripslashes', 'strip_tags', 'nl2br',
            'mb_convert_encoding',
            // hashing (deterministic)
            'md5', 'sha1', 'crc32', 'hash',
            // strings
            'chr', 'ord', 'strlen', 'mb_strlen', 'substr', 'mb_substr', 'substr_count', 'substr_replace',
            'substr_compare', 'str_replace', 'str_ireplace', 'str_pad', 'str_repeat', 'str_split',
            'mb_str_split', 'str_contains', 'str_starts_with', 'str_ends_with', 'str_word_count',
            'strtolower', 'strtoupper', 'mb_strtolower', 'mb_strtoupper', 'ucfirst', 'lcfirst',
            'ucwords', 'trim', 'ltrim', 'rtrim', 'chunk_split', 'wordwrap', 'strtr', 'strcmp',
            'strcasecmp', 'strncmp', 'strncasecmp', 'strnatcmp', 'strnatcasecmp', 'strpos', 'stripos',
            'strrpos', 'strripos', 'strstr', 'stristr', 'strrchr', 'strchr', 'strpbrk', 'strspn',
            'strcspn', 'similar_text', 'levenshtein', 'soundex', 'metaphone', 'sprintf', 'vsprintf',
            'number_format', 'explode', 'implode', 'join', 'preg_quote', 'preg_split', 'dirname',
            'basename', 'ctype_alnum', 'ctype_alpha', 'ctype_digit', 'ctype_lower', 'ctype_upper',
            'ctype_space', 'ctype_xdigit',
            // numbers
            'intval', 'floatval', 'boolval', 'strval', 'abs', 'ceil', 'floor', 'round', 'fmod', 'intdiv',
            'pow', 'sqrt', 'max', 'min', 'base_convert', 'bindec', 'decbin', 'dechex', 'hexdec',
            'octdec', 'decoct', 'gmmktime',
            // arrays (no callbacks)
            'count', 'sizeof', 'in_array', 'array_search', 'array_key_exists', 'array_keys',
            'array_values', 'array_key_first', 'array_key_last', 'array_flip', 'array_reverse',
            'array_merge', 'array_slice', 'array_combine', 'array_fill', 'array_pad', 'array_chunk',
            'array_unique', 'array_count_values', 'array_sum', 'array_product', 'array_diff',
            'array_intersect', 'range',
            // type predicates
            'is_array', 'is_bool', 'is_float', 'is_int', 'is_null', 'is_numeric', 'is_string',
            'is_scalar', 'gettype',
        );
    }

    public function execute($name, array $args, FuncCall $node)
    {
        $values = Utils::refsToValues($args);
        if (!$this->guard($name, $values)) { // may clamp $values (e.g. a decompression maxlen)
            return null;
        }
        // Builtins raise warnings/deprecations on hostile input; the return
        // value carries what matters.
        $result = @call_user_func_array($name, $values);
        if (is_string($result) && strlen($result) > $this->maxResultBytes) {
            return null;
        }
        if (is_array($result)) {
            // Item count bounds the AST nodes; payload bytes bound what the
            // printer would emit (200k references to one 8 MB string are cheap
            // to hold and 1.6 TB to print).
            if (count($result) > $this->maxResultItems
                || self::dataBytes($result) > $this->maxResultBytes) {
                return null;
            }
        }
        return Utils::scalarToNode($result);
    }

    /**
     * Approximate serialized size of a value: string bytes plus a small
     * per-item overhead. Non-scalars that cannot be measured count as infinite.
     */
    private static function dataBytes($v, int $depth = 0): int
    {
        if ($v === null || is_bool($v)) {
            return 5;
        }
        if (is_int($v) || is_float($v)) {
            return 24;
        }
        if (is_string($v)) {
            return strlen($v);
        }
        if (is_array($v)) {
            if ($depth > 16) {
                return PHP_INT_MAX;
            }
            $total = 0;
            foreach ($v as $k => $x) {
                $total += 8 + (is_string($k) ? strlen($k) : 0) + self::dataBytes($x, $depth + 1);
                if ($total < 0) { // overflow
                    return PHP_INT_MAX;
                }
            }
            return $total;
        }
        return PHP_INT_MAX;
    }

    /** Total bytes of every scalar in an argument list (arrays included). */
    private static function argBytes(array $v): int
    {
        $total = 0;
        foreach ($v as $a) {
            $total += self::dataBytes($a);
            if ($total < 0) {
                return PHP_INT_MAX;
            }
        }
        return $total;
    }

    /** False when the call could allocate unboundedly from its (attacker-chosen) arguments. */
    private function guard($name, array &$v): bool
    {
        $maxBytes = $this->maxResultBytes;
        $maxItems = $this->maxResultItems;
        switch ($name) {
            case 'str_repeat':
                return isset($v[0], $v[1]) && is_scalar($v[0]) && is_numeric($v[1])
                    && strlen((string)$v[0]) * (float)$v[1] <= $maxBytes;
            case 'str_pad':
                return isset($v[1]) && is_numeric($v[1]) && (float)$v[1] <= $maxBytes;
            case 'array_fill':
                return isset($v[1]) && is_numeric($v[1]) && (float)$v[1] <= $maxItems;
            case 'array_pad':
                return isset($v[1]) && is_numeric($v[1]) && abs((float)$v[1]) <= $maxItems;
            case 'range':
                if (count($v) < 2 || !is_scalar($v[0]) || !is_scalar($v[1])) {
                    return false;
                }
                // Character ranges (range('a','z')) as well as numeric ones;
                // measure span by ordinal when either bound is non-numeric.
                $lo = is_numeric($v[0]) && is_numeric($v[1]) ? (float)$v[0] : ord((string)$v[0]);
                $hi = is_numeric($v[0]) && is_numeric($v[1]) ? (float)$v[1] : ord((string)$v[1]);
                $step = isset($v[2]) && is_numeric($v[2]) ? abs((float)$v[2]) : 1;
                return $step > 0 && abs($hi - $lo) / $step <= $maxItems;
            case 'str_replace':
            case 'str_ireplace':
                // Replacing N single-char matches with an M-byte string yields
                // N*M bytes - str_repeat('B',65538) then str_replace('x',big,...)
                // is a 4 GB bomb. Bound the worst case cheaply; arrays are refused.
                if (count($v) < 3) {
                    return true; // 3rd arg unknown -> not folded anyway
                }
                foreach ([$v[0], $v[1], $v[2]] as $a) {
                    if (!is_scalar($a) && $a !== null) {
                        return false;
                    }
                }
                $subjectLen = strlen((string)$v[2]);
                $searchLen = max(1, strlen((string)$v[0]));
                $replaceLen = strlen((string)$v[1]);
                // worst case: every $searchLen-window is a match, each grows to $replaceLen
                $worst = $subjectLen + intdiv($subjectLen, $searchLen) * max(0, $replaceLen - $searchLen);
                return $worst <= $maxBytes;
            case 'strtr':
                // strtr(subject, [from => to]): every shortest key may match
                // back-to-back and grow to the longest replacement.
                if (!isset($v[1]) || !is_array($v[1])) {
                    return true; // 3-arg form never changes the length
                }
                if (!is_scalar($v[0] ?? null)) {
                    return false;
                }
                $minFrom = PHP_INT_MAX;
                $maxTo = 0;
                foreach ($v[1] as $from => $to) {
                    if (!is_scalar($to) && $to !== null) {
                        return false;
                    }
                    $minFrom = min($minFrom, max(1, strlen((string)$from)));
                    $maxTo = max($maxTo, strlen((string)$to));
                }
                $subjectLen = strlen((string)$v[0]);
                return $subjectLen + intdiv($subjectLen, $minFrom) * $maxTo <= $maxBytes;
            case 'substr_replace':
                if (count($v) < 2 || !is_scalar($v[0] ?? null) || !is_scalar($v[1] ?? null)) {
                    return count($v) < 2;
                }
                return strlen((string)$v[0]) + strlen((string)$v[1]) <= $maxBytes;
            case 'sprintf':
            case 'vsprintf':
                if (!isset($v[0]) || !is_string($v[0])) {
                    return false;
                }
                // A width/precision like %999999999d allocates that many bytes,
                // and since PHP 8 `%*d` takes the width from an argument.
                if (preg_match('/%[^a-zA-Z%]*(\d{5,}|\*)/', $v[0])) {
                    return false;
                }
                // Output is at most the format plus every argument rendered.
                return self::argBytes($v) <= $maxBytes;
            case 'number_format':
                // number_format(1.0, 2e9) emits that many decimals.
                return !isset($v[1]) || (is_numeric($v[1]) && (float)$v[1] <= $maxBytes);
            case 'pack':
                // a/A/Z pad to the count, x emits count NULs, @ NUL-fills to an
                // absolute offset: pack("a2000000000", "x") is a 2 GB allocation.
                if (!isset($v[0]) || !is_string($v[0])) {
                    return false;
                }
                $fixed = 0;
                if (preg_match_all('/[aAZx@](\d+)/', $v[0], $m)) {
                    foreach ($m[1] as $n) {
                        $fixed += (float)$n;
                    }
                }
                return $fixed <= $maxBytes && self::argBytes($v) <= $maxBytes;
            case 'implode':
            case 'join':
            case 'json_encode':
            case 'serialize':
            case 'http_build_query':
                // Output is bounded by the total payload of the arguments; an
                // array of 200k references to one 8 MB string is tiny to hold
                // and terabytes to render.
                return self::argBytes($v) <= $maxBytes;
            case 'similar_text':
            case 'levenshtein':
                // O(n*m) to O(n^3) in the operand lengths: a CPU bomb long
                // before any memory is allocated.
                $cap = min($name === 'levenshtein' ? self::MAX_LEVENSHTEIN_BYTES : self::MAX_SIMILAR_TEXT_BYTES, $maxBytes);
                foreach ([$v[0] ?? null, $v[1] ?? null] as $a) {
                    if (!is_scalar($a) || strlen((string)$a) > $cap) {
                        return false;
                    }
                }
                return true;
            case 'str_split':
            case 'mb_str_split':
                // One element per chunk; the post-hoc item check is too late
                // once 8M one-byte strings have been allocated.
                if (!is_scalar($v[0] ?? null)) {
                    return false;
                }
                $chunk = isset($v[1]) && is_numeric($v[1]) ? max(1, (int)$v[1]) : 1;
                return strlen((string)$v[0]) / $chunk <= $maxItems;
            case 'explode':
            case 'preg_split':
            case 'unpack':
            case 'str_word_count':
                // Each may produce up to one element per byte of its subject
                // (explode on every char, preg_split on //, unpack("C*"),
                // str_word_count($s, 1)). Allow a few times the item cap in
                // transient allocation, no more.
                $subject = $name === 'str_word_count' ? ($v[0] ?? null) : ($v[1] ?? null);
                if ($subject !== null && !is_scalar($subject)) {
                    return false;
                }
                return $subject === null || strlen((string)$subject) <= 4 * $maxItems;
            case 'chunk_split':
                // chunk_split(body, len, end) inserts `end` after every `len`
                // bytes: chunk_split(x, 1, big) explodes to len(body)/1 * len(end).
                if (!is_scalar($v[0] ?? null)) {
                    return false;
                }
                $bodyLen = strlen((string)$v[0]);
                $chunkLen = isset($v[1]) && is_numeric($v[1]) ? max(1, (int)$v[1]) : 76;
                $endLen = isset($v[2]) && is_scalar($v[2]) ? strlen((string)$v[2]) : 2;
                return $bodyLen + intdiv($bodyLen, $chunkLen) * $endLen <= $maxBytes;
            case 'wordwrap':
                if (!is_scalar($v[0] ?? null)) {
                    return false;
                }
                // break after every char with a long break string is the bomb.
                $wl = isset($v[1]) && is_numeric($v[1]) ? max(1, (int)$v[1]) : 75;
                $brk = isset($v[2]) && is_scalar($v[2]) ? strlen((string)$v[2]) : 1;
                return strlen((string)$v[0]) + intdiv(strlen((string)$v[0]), $wl) * $brk <= $maxBytes;
            case 'gzinflate':
            case 'gzuncompress':
            case 'gzdecode':
                // Decompression bombs: cap the output length these accept.
                $v[1] = isset($v[1]) && is_numeric($v[1]) && $v[1] > 0
                    ? min((int)$v[1], $maxBytes) : $maxBytes;
                return true;
            default:
                return true;
        }
    }
}
