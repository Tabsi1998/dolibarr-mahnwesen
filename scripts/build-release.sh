#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="$(awk -F"'" '/\$this->version[[:space:]]*=/ {print $2; exit}' core/modules/modMahnwesen.class.php)"
if [ -z "$VERSION" ]; then
  echo "Cannot determine module version" >&2
  exit 1
fi
if ! printf '%s\n' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "Refusing release build for non-release module version: $VERSION" >&2
  exit 1
fi

OUTDIR="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$OUTDIR" "$STAGE/mahnwesen"
rsync -a \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude 'dist/' \
  --exclude 'build/' \
  --exclude 'scripts/' \
  --exclude '.gitignore' \
  --exclude '.gitattributes' \
  --exclude '.editorconfig' \
  --exclude 'CONTRIBUTING.md' \
  "$ROOT/" "$STAGE/mahnwesen/"
(
  cd "$STAGE"
  zip -qr "$OUTDIR/mahnwesen-$VERSION.zip" mahnwesen
)
unzip -t "$OUTDIR/mahnwesen-$VERSION.zip" >/dev/null
echo "$OUTDIR/mahnwesen-$VERSION.zip"
