<?php
/**
 * Shared obfuscation heuristics for the threat-library sweep.
 *
 * Feature extraction is token-aware: structural signals (dangerous calls,
 * dynamic calls, bitwise-string operators, goto, ...) are measured over a
 * CODE-ONLY rendering of the file — string-literal contents collapsed to empty
 * quotes and comments removed — so a shell command like `system("a | b")` or a
 * URL like `'...&url='` no longer counts as a bitwise/`|` operator. Payload
 * signals (base64/hex runs, \xNN escapes) are measured over the string-literal
 * contents, where encoded payloads actually live. Falls back to the raw source
 * if the tokenizer chokes on a malformed sample.
 */

declare(strict_types=1);

/**
 * Split source into a code-only rendering and the concatenated string-literal
 * contents. In the code rendering every string literal becomes an empty quote
 * pair (so `'a' ^ 'b'` stays visibly a bitwise op between quotes while
 * `system("a|b")` becomes `system("")`), and comments are dropped.
 *
 * @return array{0:string,1:string} [codeText, stringText]
 */
function obfTokenize(string $code): array
{
    $tokens = @token_get_all($code);
    if (!is_array($tokens) || $tokens === []) {
        return [$code, $code]; // tolerate — degrade to raw
    }
    $codeText = '';
    $strText = '';
    foreach ($tokens as $t) {
        if (is_string($t)) {
            $codeText .= $t; // single-char tokens: operators, quotes, ; ( ) etc.
            continue;
        }
        [$id, $text] = $t;
        switch ($id) {
            case T_CONSTANT_ENCAPSED_STRING:
                $q = ($text[0] === '"' || $text[0] === "'") ? $text[0] : "'";
                $codeText .= $q . $q;                 // '' or "" — a string was here
                $strText .= substr($text, 1, -1);
                break;
            case T_ENCAPSED_AND_WHITESPACE:           // literal chunks inside "..$x.." / heredocs
                $strText .= $text;
                break;
            case T_INLINE_HTML:                        // content outside PHP tags
                $strText .= $text;
                break;
            case T_COMMENT:
            case T_DOC_COMMENT:
                break;                                 // drop comments entirely
            default:
                $codeText .= $text;
        }
    }
    return [$codeText, $strText];
}

function obfFeatures(string $code): array
{
    $len = strlen($code);
    $f = ['len' => $len];

    try {
        [$codeText, $strText] = obfTokenize($code);
    } catch (\Throwable $e) {
        $codeText = $code;
        $strText = $code;
    }

    // --- structural signals: measured on code only -------------------------
    $f['decoders'] = preg_match_all(
        '/\b(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|hex2bin|convert_uudecode|rawurldecode|urldecode|unserialize|openssl_decrypt|mcrypt_decrypt|pack|unpack)\s*\(/i', $codeText);
    $f['exec'] = preg_match_all(
        '/\b(eval|assert|create_function|call_user_func|call_user_func_array|array_map|array_filter|array_walk|usort|preg_replace_callback|register_shutdown_function|ob_start)\s*\(/i', $codeText)
        + preg_match_all('/\bpreg_replace\s*\(\s*["\'][^"\']{0,80}\/[a-z]*e[a-z]*["\']/i', $code); // /e needs the raw pattern
    $f['chrs']     = preg_match_all('/\b(chr|ord|chrs|dechex|hexdec|octdec|bindec|intval)\s*\(/i', $codeText);
    $f['dyncalls'] = preg_match_all('/\$\w+\s*\(/', $codeText)
        + preg_match_all('/\$\{\s*["\']/', $codeText)
        + preg_match_all('/\$\$\w+/', $codeText)
        + preg_match_all('/\$\w+\s*\[[^\]]{0,40}\]\s*\(/', $codeText);
    $f['globals']  = preg_match_all('/\$GLOBALS\s*\[/', $codeText);
    $f['goto']     = preg_match_all('/\bgoto\s+\w+\s*;/i', $codeText);
    $f['concatchr'] = preg_match_all('/chr\s*\(\s*\d+\s*\)\s*\./i', $codeText);
    $f['strtricks'] = preg_match_all('/\b(strtr|str_replace|substr|implode|explode|str_split|array_reverse|sprintf|strtolower|strtoupper|ucfirst)\s*\(/i', $codeText);
    // bitwise string ops: 'abc' ^ "def", 'x' | ~KW, & chains — the Unknown_* backdoor family.
    // Measured on code so operators inside string/shell literals are not counted.
    $f['bitops']   = preg_match_all('/["\'][^"\'\n]{0,4}["\']\s*[\^&|]\s*~?\s*(?:["\']|\w)/', $codeText)
        + preg_match_all('/[\^&|]\s*~\s*["\']/', $codeText);

    // --- payload signals: measured on string-literal contents --------------
    $f['hexesc']   = preg_match_all('/\\\\x[0-9a-fA-F]{2}/', $strText) + preg_match_all('/\\\\[0-7]{3}/', $strText);
    $f['b64runs']  = preg_match_all('/[A-Za-z0-9+\/]{120,}={0,2}/', $strText, $m);
    $f['maxrun']   = 0;
    foreach ($m[0] ?? [] as $run) $f['maxrun'] = max($f['maxrun'], strlen($run));
    $f['hexruns']  = preg_match_all('/\b(?:[0-9a-fA-F]{2}){64,}\b/', $strText);

    // --- whole-file shape --------------------------------------------------
    $f['maxline']  = 0;
    $pos = 0;
    while (($nl = strpos($code, "\n", $pos)) !== false) {
        $f['maxline'] = max($f['maxline'], $nl - $pos);
        $pos = $nl + 1;
    }
    $f['maxline'] = max($f['maxline'], $len - $pos);
    $f['nonascii'] = preg_match_all('/[\x80-\xFF]/', $code);
    $f['phpopen']  = preg_match_all('/<\?(?:php\b|=|\s)/i', $code);
    $f['lines']    = substr_count($code, "\n") + 1;
    $f['funcs']    = preg_match_all('/\bfunction\s+\w+\s*\(/i', $codeText);
    $f['score']    = obfScore($f);
    $f['tier']     = obfTier($f);
    return $f;
}

function obfScore(array $f): int
{
    $s = 3 * $f['exec'] + 2 * $f['decoders'] + (int)(0.5 * $f['chrs']) + $f['dyncalls']
        + $f['globals'] + (int)(0.2 * $f['hexesc']) + 2 * $f['goto'] + 3 * $f['b64runs']
        + 2 * $f['hexruns'] + $f['concatchr'] + 2 * $f['bitops'];
    if ($f['maxline'] > 2000) $s += 3;
    if ($f['maxrun'] > 500)   $s += 3;
    return (int)$s;
}

/** HEAVY: needs decoding. MEDIUM: some obfuscation. LIGHT: plain code. */
function obfTier(array $f): string
{
    $execAndPayload = $f['exec'] >= 1 && ($f['decoders'] >= 1 || $f['b64runs'] >= 1 || $f['hexesc'] >= 10 || $f['chrs'] >= 5 || $f['hexruns'] >= 1);
    if ($execAndPayload || $f['hexesc'] >= 40 || $f['goto'] >= 5 || $f['dyncalls'] >= 10
        || ($f['b64runs'] >= 1 && $f['maxrun'] >= 800) || $f['globals'] >= 10 || $f['concatchr'] >= 10
        || $f['hexruns'] >= 2 || $f['bitops'] >= 4
        || ($f['len'] >= 200 && $f['len'] <= 20000 && obfScore($f) * 1024 / $f['len'] >= 6)) {
        return 'HEAVY';
    }
    if ($f['exec'] >= 1 || $f['decoders'] >= 2 || $f['hexesc'] >= 5 || $f['goto'] >= 1 || $f['dyncalls'] >= 3
        || $f['b64runs'] >= 1 || $f['chrs'] >= 3 || $f['globals'] >= 3 || $f['bitops'] >= 1) {
        return 'MEDIUM';
    }
    return 'LIGHT';
}

function looksLikePHP(string $content): bool
{
    return (bool)preg_match('/<\?(?:php\b|=|\s)/i', substr($content, 0, 65536))
        || (bool)preg_match('/^#!.*php/', substr($content, 0, 200));
}
