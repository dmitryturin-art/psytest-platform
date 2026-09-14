#!/usr/bin/env bash

# Build a staging release artifact from the current commit.
#
# Usage: bin/build-release.sh [output-dir]
# Default output: tmp/release-<short-sha>/ (tar.gz alongside).
#
# Rules learned from incident 25.08.2026 (SMIL chart background 404):
# - every exclude must be anchored to the repo root (/pattern), otherwise
#   rsync silently drops tracked assets like public/images/smil-profile-bg.png;
# - source files come from the committed Git tree, never from the working
#   directory, so ignored local artifacts cannot enter a release;
# - after build, every git-tracked file under public/ must exist in the
#   artifact — the verification below fails the build otherwise.

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

SHA=$(git rev-parse --short HEAD)
OUT=${1:-"tmp/release-$SHA"}
if [[ ! "$OUT" =~ ^tmp/release-[A-Za-z0-9._-]+$ ]]; then
    echo "ERROR: output must be a tmp/release-* path inside this repository." >&2
    exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "ERROR: working tree is not clean — commit or stash first." >&2
    exit 1
fi

STAGE="$OUT/release-$SHA"
SOURCE="$OUT/tracked-$SHA"

rm -rf "$OUT"
mkdir -p "$STAGE" "$SOURCE"

# `git archive` includes only files committed at HEAD. The clean-tree check
# above is intentionally retained: a release must name a reviewable commit,
# not silently skip a developer's uncommitted work.
git archive --format=tar HEAD | tar -xf - -C "$SOURCE"

rsync -a \
    --exclude '/.git' \
    --exclude '/.github' \
    --exclude '/tests' \
    --exclude '/docs' \
    --exclude '/.kilo' \
    --exclude '/.claude' \
    --exclude '/.superpowers' \
    --exclude '/.zcode' \
    --exclude '/.playwright-mcp' \
    --exclude '/.vscode' \
    --exclude '/.phpstan' \
    --exclude '/.phpunit.cache' \
    --exclude '/.php-cs-fixer.cache' \
    --exclude '/.php-cs-fixer.php' \
    --exclude '/phpstan.neon' \
    --exclude '/phpunit.xml' \
    --exclude '/graphify-out' \
    --exclude '/output' \
    --exclude '/tmp' \
    --exclude '/source' \
    --exclude '/.env' \
    --exclude '/.env.example' \
    --exclude '/.gitignore' \
    --exclude '/.DS_Store' \
    --exclude '/storage/*' \
    --exclude '/vendor' \
    "$SOURCE/" "$STAGE/"

rm -rf "$SOURCE"

mkdir -p "$STAGE/storage/logs" "$STAGE/storage/cache" "$STAGE/storage/pdfs"

composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$STAGE" -q

missing=0
while IFS= read -r f; do
    if [ ! -f "$STAGE/$f" ]; then
        echo "MISSING in artifact: $f" >&2
        missing=1
    fi
done < <(git ls-files public)

if [ "$missing" -ne 0 ]; then
    echo "ERROR: artifact verification failed." >&2
    exit 1
fi

COPYFILE_DISABLE=1 tar --no-xattrs -czf "$OUT.tar.gz" -C "$OUT" "release-$SHA"
echo "Artifact OK: $OUT.tar.gz"
shasum -a 256 "$OUT.tar.gz"
