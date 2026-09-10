# Scoping: decoding the "leftover encoded strings"

**Date:** 2026-09-10
**Method:** ran the full `-x -u` pipeline over a 150-file stride sample of the
HEAVY tier from `report/scan/all.jsonl`, then scored each **output** with
`bin/lib/obfscore.php` and hand-classified the residuals. Scripts:
`/tmp/resid.php`, `/tmp/resid2.php` (throwaway).

## Headline: the premise is mostly false

After `-x -u`, the decode engine has already unpacked essentially everything a
*static* tool can. Measured on the output tier:

| output tier after `-x -u` | count (of 147 processed) |
|---|---|
| HEAVY | 139 |
| MEDIUM | 6 |
| LIGHT | 2 |

139 "still-HEAVY" *looks* alarming, but the obfuscation scorer counts legitimate
`base64_decode`/`gzinflate` calls and embedded base64 blobs, so **plaintext code
scores HEAVY too**. Breaking the 139 down:

- **110 are `sample-dump/bendigital2019_*`** — a single family whose files are
  **already plaintext PHP** (verbose word-salad doc-comments + a legit embedded
  base64 blob, signature `b64=2 max=2655 dec=7–9`). Input ≈ output; nothing to
  decode. Pure scorer false-positive.
- **Several more are WordPress core / libraries** injected into the corpus, e.g.
  `bendigital2019_03168` (= `Services_JSON`, `OBJECT_K`/`ARRAY_A`), `LUKE-2178`
  `class-wp-terms-list-table-template.php` (`max=119992` legit data table).
  Plaintext, not malware.
- That leaves **~a dozen files** with a *genuine* residual decode.

So "decode the leftover strings" — as a generic feature — has **little real
surface left**. The static folder already handles multi-layer FOPO, base64 /
gz* / rot13 / hex2bin, concatenated + dynamic decoder-name dispatch
(`$f("…")`, `$GLOBALS['k']("…")`), and `-x` runs pure custom decoder functions.
(Verified: `$f="base64_decode"; $f("…")` and concatenated-name variants all
fold today.)

## The genuine remaining decode gaps

The real residuals cluster into three buckets — only one is worth building for.

### G1 — `file_get_contents(__FILE__)` self-reading unpackers  ← the real target
The decoder reads the sample's **own source** at runtime, slices a region
(often via `preg_match`/offset), and decodes that. Examples:
`webshell-simple-php-backdoor-2/simple-php-backdoor.php`,
`php_obfuscator_xxtea_exec_001_5/xeoninfo.php` (numeric-packed-string decoder
that does `file_get_contents(__FILE__)` + `preg_match` on itself),
`wf-7537/…` (SoleVisible; ends in `$out .= chr($bytes[$i++] ^ 0x7); eval($out);`).

Why every layer misses them:
- **Static reduction** can't: the decode input is the file's runtime bytes.
- **`-x`** can't and *shouldn't*: the decoder is impure (reads FS / `__FILE__`).
  `PurityAnalyzer` correctly returns `bad` here — not a purity-gate bug.
- **`-e` is the intended tool but currently fails on them.** The eval-hook
  extension is present and working (captures fine on ordinary nested evals), yet
  `-e` on `wf-7537` reports **0 layers captured**. The self-read is not
  reproducing inside the jail (the copied sample's `__FILE__` path/bytes, or a
  blocked call on the self-read path, aborts before the `eval()` fires).

**This is the single highest-value decode work:** the whole genuine-gap family
funnels into one fix — make `-e` capture on `__FILE__` self-readers. Likely
scope: in `EvalPeeler`, run the copied sample at a path whose bytes are
byte-identical to the original, keep `__FILE__`/`__DIR__` reads inside the jail
resolving to that copy, and confirm the self-read path's builtins
(`file_get_contents`, `preg_match`, `fopen`/`fread`) are enabled, not stubbed.
Then re-run the G1 examples and add fixtures. **Medium effort**, self-contained.

### G2 — genuinely runtime-keyed decodes  ← out of scope (undecidable)
`base64_decode($_POST[...])`, remote-fetched payloads, `php://input`. No static
tool can resolve these; `-e` can only help when running the sample is safe and
the input is present. Correctly left as-is today. ~a handful in the sample.

### G3 — `pack()`-based binary rebuilders (z5encrypt)  ← low ROI
`obfuscation/z5encrypt_003/*` leaves `pack(...)` + `gzinflate` + scattered
`\xNN`. Some `pack()` calls have computed args (runtime); the fully-literal ones
could fold if `pack`/`unpack` coverage in the `-x` path is widened. Small,
narrow payoff; do only if z5encrypt specifically matters.

## Adjacent, higher-leverage than more decoding: triage precision
110/139 false-positives means the corpus **triage** (not the decoder) is what
wastes analyst time. A cheap "readability gate" — if the `-x -u` output
re-parses and is mostly plain statements with low dynamic-dispatch density,
downgrade its tier — would remove the WP-core / verbose-library noise and make
the HEAVY list actually mean "needs decoding". This is what creates the
*illusion* of leftover encoded strings. **Low–medium effort**, high signal.

## Recommendation (ranked)
1. **G1: `-e` self-reader support.** The only decode gap with a coherent, sizable
   target; one fix covers the whole `__FILE__`-unpacker family. Medium effort.
2. **Triage/scorer precision** (readability downgrade). Kills the false-positive
   noise that motivated this investigation. Low–medium effort.
3. **G3: `pack()` widening.** Optional, only if z5encrypt matters. Low effort.

Not recommended: a generic "string decoder" pass — the static engine already
covers the decidable cases; what's left is either self-reading (G1/`-e`) or
runtime-keyed (G2, undecidable).
