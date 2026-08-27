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
tar -cf - \
  --exclude='./.git' \
  --exclude='./.github' \
  --exclude='./dist' \
  --exclude='./build' \
  --exclude='./scripts' \
  --exclude='./tests' \
  --exclude='./.gitignore' \
  --exclude='./.gitattributes' \
  --exclude='./.editorconfig' \
  --exclude='./CONTRIBUTING.md' \
  . | (cd "$STAGE/mahnwesen" && tar -xf -)

ZIPFILE="$OUTDIR/mahnwesen-$VERSION.zip"
if command -v zip >/dev/null 2>&1; then
  (cd "$STAGE" && zip -qr "$ZIPFILE" mahnwesen)
elif command -v powershell.exe >/dev/null 2>&1 && command -v cygpath >/dev/null 2>&1; then
  # Git for Windows ships tar/unzip but not always zip. Keep the same archive
  # layout through the native PowerShell ZIP implementation.
  export MAHNWESEN_STAGE_WIN="$(cygpath -w "$STAGE/mahnwesen")"
  export MAHNWESEN_ZIP_WIN="$(cygpath -w "$ZIPFILE")"
  powershell.exe -NoProfile -NonInteractive -Command \
    "Compress-Archive -LiteralPath \"\$env:MAHNWESEN_STAGE_WIN\" -DestinationPath \"\$env:MAHNWESEN_ZIP_WIN\" -Force"
else
  echo "Neither zip nor a supported PowerShell fallback is available" >&2
  exit 1
fi
unzip -t "$ZIPFILE" >/dev/null
echo "$ZIPFILE"
