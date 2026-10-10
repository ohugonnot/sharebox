#!/bin/sh
# Copies the PDF reader and the audiobook player from the portal (projet-v2).
# These copies are never edited here: fix them upstream, then run this again.
#   tools/sync-reader.sh          copy what changed, rewrite reader/UPSTREAM.txt
#   tools/sync-reader.sh --check  copy nothing; fail if a copy or upstream drifted
# Upstream tests require '../js/<file>.js'; the copy points them at the repo root
# with the sed below, so the test files are the only ones not byte-identical.
set -eu

SRC=${READER_UPSTREAM:-/var/www/html/projet-v2}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
MANIFEST=reader/UPSTREAM.txt
MODE=${1:-sync}

case "$MODE" in
    sync|--check) ;;
    *) echo "usage: $0 [--check]" >&2; exit 64 ;;
esac

# source (relative to SRC) -> destination (relative to ROOT)
FILES='js/reader.js reader.js
css/reader.css reader.css
js/audiobook.js audiobook.js
css/audiobook.css audiobook.css
js/reader/pdfjs/pdf.min.mjs reader/pdfjs/pdf.min.mjs
js/reader/pdfjs/pdf.worker.min.mjs reader/pdfjs/pdf.worker.min.mjs
js/reader/pdfjs/LICENSE reader/pdfjs/LICENSE
js/reader/VERSIONS.txt reader/VERSIONS.txt
tests/reader_js_test.js tests/unit/reader.test.js
tests/audiobook_js_test.js tests/unit/audiobook.test.js'

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# Writes the expected content of $2 into $TMP/out.
render() {
    case "$2" in
        tests/unit/*) sed "s#path.join(__dirname, '..', 'js', #path.join(__dirname, '..', '..', #" "$SRC/$1" > "$TMP/out" ;;
        *) cp "$SRC/$1" "$TMP/out" ;;
    esac
}

cd "$ROOT"
changed=0
drift=0
: > "$TMP/sums"
echo "$FILES" | while read -r src dst; do
    [ -f "$SRC/$src" ] || { echo "missing upstream file: $SRC/$src" >&2; exit 1; }
    render "$src" "$dst"
    if cmp -s "$TMP/out" "$dst" 2>/dev/null; then
        :
    elif [ "$MODE" = --check ]; then
        echo "differs from upstream: $dst" >&2
        echo x >> "$TMP/drift"
    else
        mkdir -p "$(dirname "$dst")"
        cp "$TMP/out" "$dst"
        echo "copied: $dst"
        echo x >> "$TMP/changed"
    fi
    echo "$(sha256sum "$TMP/out" | cut -d' ' -f1)  $dst" >> "$TMP/sums"
done
[ -f "$TMP/changed" ] && changed=1
[ -f "$TMP/drift" ] && drift=1

if [ "$MODE" = --check ]; then
    sha256sum --quiet -c "$MANIFEST" || drift=1
    [ "$drift" -eq 0 ] && echo "reader copies match $MANIFEST and upstream"
    exit "$drift"
fi

# The date only moves when something changed, so a no-op run leaves git clean.
if [ "$changed" -eq 1 ] || [ ! -f "$MANIFEST" ]; then
    {
        echo "# Copied by tools/sync-reader.sh on $(date -u +%Y-%m-%dT%H:%M:%SZ)"
        echo "# Source: $SRC"
        echo "# Do not edit these files here. Verify with: sha256sum -c $MANIFEST"
        cat "$TMP/sums"
    } > "$MANIFEST"
    echo "wrote $MANIFEST"
else
    echo "already up to date"
fi
