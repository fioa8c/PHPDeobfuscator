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
 * A few functions can allocate unbounded memory from attacker-chosen
 * arguments (str_repeat(x, 1e9)) - those are size-guarded in guard().
 */
class PassThrough implements FunctionReducer
{
    /** Largest string/array a guarded function may produce. */
    const MAX_RESULT_BYTES = 8 * 1024 * 1024;
    const MAX_RESULT_ITEMS = 200000;

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
        if (is_string($result) && strlen($result) > self::MAX_RESULT_BYTES) {
            return null;
        }
        if (is_array($result) && count($result) > self::MAX_RESULT_ITEMS) {
            return null;
        }
        return Utils::scalarToNode($result);
    }

    /** False when the call could allocate unboundedly from its (attacker-chosen) arguments. */
    private function guard($name, array &$v): bool
    {
        switch ($name) {
            case 'str_repeat':
                return isset($v[0], $v[1]) && is_scalar($v[0]) && is_numeric($v[1])
                    && strlen((string)$v[0]) * (float)$v[1] <= self::MAX_RESULT_BYTES;
            case 'str_pad':
                return isset($v[1]) && is_numeric($v[1]) && (float)$v[1] <= self::MAX_RESULT_BYTES;
            case 'array_fill':
                return isset($v[1]) && is_numeric($v[1]) && (float)$v[1] <= self::MAX_RESULT_ITEMS;
            case 'array_pad':
                return isset($v[1]) && is_numeric($v[1]) && abs((float)$v[1]) <= self::MAX_RESULT_ITEMS;
            case 'range':
                if (count($v) < 2 || !is_scalar($v[0]) || !is_scalar($v[1])) {
                    return false;
                }
                // Character ranges (range('a','z')) as well as numeric ones;
                // measure span by ordinal when either bound is non-numeric.
                $lo = is_numeric($v[0]) && is_numeric($v[1]) ? (float)$v[0] : ord((string)$v[0]);
                $hi = is_numeric($v[0]) && is_numeric($v[1]) ? (float)$v[1] : ord((string)$v[1]);
                $step = isset($v[2]) && is_numeric($v[2]) ? abs((float)$v[2]) : 1;
                return $step > 0 && abs($hi - $lo) / $step <= self::MAX_RESULT_ITEMS;
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
                return $worst <= self::MAX_RESULT_BYTES;
            case 'substr_replace':
                if (count($v) < 2 || !is_scalar($v[0] ?? null) || !is_scalar($v[1] ?? null)) {
                    return count($v) < 2;
                }
                return strlen((string)$v[0]) + strlen((string)$v[1]) <= self::MAX_RESULT_BYTES;
            case 'sprintf':
            case 'vsprintf':
                // A width/precision like %999999999d allocates that many bytes.
                return isset($v[0]) && is_string($v[0]) && !preg_match('/%[^a-zA-Z%]*\d{5,}/', $v[0]);
            case 'chunk_split':
                // chunk_split(body, len, end) inserts `end` after every `len`
                // bytes: chunk_split(x, 1, big) explodes to len(body)/1 * len(end).
                if (!is_scalar($v[0] ?? null)) {
                    return false;
                }
                $bodyLen = strlen((string)$v[0]);
                $chunkLen = isset($v[1]) && is_numeric($v[1]) ? max(1, (int)$v[1]) : 76;
                $endLen = isset($v[2]) && is_scalar($v[2]) ? strlen((string)$v[2]) : 2;
                return $bodyLen + intdiv($bodyLen, $chunkLen) * $endLen <= self::MAX_RESULT_BYTES;
            case 'wordwrap':
                if (!is_scalar($v[0] ?? null)) {
                    return false;
                }
                // break after every char with a long break string is the bomb.
                $wl = isset($v[1]) && is_numeric($v[1]) ? max(1, (int)$v[1]) : 75;
                $brk = isset($v[2]) && is_scalar($v[2]) ? strlen((string)$v[2]) : 1;
                return strlen((string)$v[0]) + intdiv(strlen((string)$v[0]), $wl) * $brk <= self::MAX_RESULT_BYTES;
            case 'gzinflate':
            case 'gzuncompress':
            case 'gzdecode':
                // Decompression bombs: cap the output length these accept.
                $v[1] = isset($v[1]) && is_numeric($v[1]) && $v[1] > 0
                    ? min((int)$v[1], self::MAX_RESULT_BYTES) : self::MAX_RESULT_BYTES;
                return true;
            default:
                return true;
        }
    }
}
