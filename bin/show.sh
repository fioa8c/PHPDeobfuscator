#!/bin/bash
# show.sh <relpath> [inbytes] [outbytes] — print input head and static-sweep output head side by side
L=/Users/fioa8c/WORK/jetpack-threat-library; f="$1"; ib=${2:-600}; ob=${3:-600}
echo "######## $f  ($(wc -c < "$L/$f") B)"; echo "--- INPUT:"; head -c $ib "$L/$f" | cut -c1-300; echo
o="out/sweep/static/files/${f//\//__}.deobf.php"; [ -f "$o" ] && { echo "--- OUTPUT ($(wc -c < "$o") B):"; head -c $ob "$o" | cut -c1-300; echo; }
