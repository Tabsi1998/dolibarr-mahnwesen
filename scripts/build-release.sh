#!/usr/bin/env bash
# Build the installable module ZIP into dist/.
#
#   bash scripts/build-release.sh                     release package of the module version
#   PACKAGE_VERSION=1.0.0.10 bash scripts/build-release.sh
#                                                     development package: the version inside
#                                                     the package and in its name is replaced
#
# SOURCE_DATE_EPOCH (default: the time of the current commit) fixes every
# timestamp, and entries are sorted with fixed permissions, so two builds of
# the same commit hold the same files in the same order.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MODULE_VERSION="$(awk -F"'" '/\$this->version[[:space:]]*=/ {print $2; exit}' core/modules/modMahnwesen.class.php)"
if [ -z "$MODULE_VERSION" ]; then
  echo "Cannot determine module version" >&2
  exit 1
fi
if ! printf '%s\n' "$MODULE_VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "Refusing release build for non-release module version: $MODULE_VERSION" >&2
  exit 1
fi
VERSION="${PACKAGE_VERSION:-$MODULE_VERSION}"
# Dolibarr's installer takes the module folder from the file name: whatever
# precedes "-<digits and dots>.zip" must be the folder inside the archive.
if ! printf '%s\n' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?$'; then
  echo "Package version must be x.y.z or x.y.z.n: $VERSION" >&2
  exit 1
fi
case "$VERSION" in
  "$MODULE_VERSION"|"$MODULE_VERSION".*) ;;
  *) echo "Package version $VERSION does not extend the module version $MODULE_VERSION" >&2; exit 1 ;;
esac

if [ -z "${SOURCE_DATE_EPOCH:-}" ]; then
  SOURCE_DATE_EPOCH="$(git log -1 --format=%ct 2>/dev/null || true)"
fi
# ZIP stores DOS times, which start in 1980.
if [ -z "${SOURCE_DATE_EPOCH:-}" ] || [ "$SOURCE_DATE_EPOCH" -lt 315532800 ]; then
  SOURCE_DATE_EPOCH=315532800
fi
export TZ=UTC

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
  --exclude='./CLAUDE.md' \
  . | (cd "$STAGE/mahnwesen" && tar -xf -)

if [ "$VERSION" != "$MODULE_VERSION" ]; then
  sed -i "s/\(\$this->version[[:space:]]*=[[:space:]]*'\)$MODULE_VERSION'/\1$VERSION'/" \
    "$STAGE/mahnwesen/core/modules/modMahnwesen.class.php"
  if ! grep -q "\$this->version = '$VERSION'" "$STAGE/mahnwesen/core/modules/modMahnwesen.class.php"; then
    echo "Could not set the package version $VERSION in the module descriptor" >&2
    exit 1
  fi
fi

find "$STAGE/mahnwesen" -type d -exec chmod 755 {} +
find "$STAGE/mahnwesen" -type f -exec chmod 644 {} +
find "$STAGE/mahnwesen" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +

ZIPFILE="$OUTDIR/mahnwesen-$VERSION.zip"
rm -f "$ZIPFILE" "$ZIPFILE.sha256"
if command -v zip >/dev/null 2>&1; then
  (cd "$STAGE" && find mahnwesen -print | LC_ALL=C sort | zip -q -X -@ "$ZIPFILE")
elif command -v powershell.exe >/dev/null 2>&1 && command -v cygpath >/dev/null 2>&1; then
  # Git for Windows ships tar/unzip but not always zip. Keep the same archive
  # layout through the native PowerShell ZIP implementation; it is not
  # reproducible, so publishing always builds with zip.
  MAHNWESEN_STAGE_WIN="$(cygpath -w "$STAGE/mahnwesen")"
  MAHNWESEN_ZIP_WIN="$(cygpath -w "$ZIPFILE")"
  export MAHNWESEN_STAGE_WIN MAHNWESEN_ZIP_WIN
  powershell.exe -NoProfile -NonInteractive -Command \
    "Compress-Archive -LiteralPath \"\$env:MAHNWESEN_STAGE_WIN\" -DestinationPath \"\$env:MAHNWESEN_ZIP_WIN\" -Force"
else
  echo "Neither zip nor a supported PowerShell fallback is available" >&2
  exit 1
fi
unzip -t "$ZIPFILE" >/dev/null
if command -v sha256sum >/dev/null 2>&1; then
  (cd "$OUTDIR" && sha256sum "$(basename "$ZIPFILE")" > "$(basename "$ZIPFILE").sha256")
fi
echo "$ZIPFILE"
