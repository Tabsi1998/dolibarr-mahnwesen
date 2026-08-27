#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if command -v php >/dev/null 2>&1; then
  while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null
  done < <(find . -type f -name '*.php' -not -path './vendor/*' -print0)
  echo "PHP lint: OK"
  php tests/run.php
else
  echo "PHP not found; skipping lint" >&2
fi

for lang in de_DE en_US; do
  file="langs/$lang/mahnwesen.lang"
  test -f "$file"
  awk -F= 'NF >= 2 && $1 !~ /^[[:space:]]*#/ {gsub(/[[:space:]]+$/, "", $1); if ($1 != "") print $1}' "$file" | sort > "/tmp/mahnwesen-$lang.keys"
done

if grep -RInE '^[A-Za-z0-9_]+=.*[[:alnum:]_]Mahnwesen[A-Za-z0-9_]+=' langs/*/mahnwesen.lang; then
  echo "Concatenated language key detected" >&2
  exit 1
fi

diff -u /tmp/mahnwesen-de_DE.keys /tmp/mahnwesen-en_US.keys
if [ "$(sort /tmp/mahnwesen-de_DE.keys | uniq -d | wc -l)" -ne 0 ]; then
  echo "Duplicate DE language keys detected" >&2
  exit 1
fi
if [ "$(sort /tmp/mahnwesen-en_US.keys | uniq -d | wc -l)" -ne 0 ]; then
  echo "Duplicate EN language keys detected" >&2
  exit 1
fi
echo "Language keys: OK"

if grep -RInE "(UPDATE|INSERT INTO|DELETE FROM)[^;]*(llx_facture([[:space:]]|$)|MAIN_DB_PREFIX[[:space:]]*\.[[:space:]]*['\"]facture['\"])" --include='*.php' .; then
  echo "Potential direct invoice-table write detected; review required" >&2
  exit 1
fi
echo "Invoice write guard: OK"

grep -q "restrictedArea(\$user, 'facture'" invoice.php
grep -q "restrictedArea(\$user, 'facture'" notice.php
grep -q "JSON_HEX_TAG" notice.php
grep -q "status IN ('reserved', 'sending', 'ambiguous')" class/dunningmanager.methods3.trait.php
echo "Security contracts: OK"
