#!/usr/bin/env bash
set -euo pipefail
VERSION="${1:-22.0}"
case "$VERSION" in
  21.0|22.0|23.0) ;;
  *) echo "Unsupported CI compatibility target: $VERSION" >&2; exit 1 ;;
esac

test -f core/modules/modMahnwesen.class.php
test -f class/dunningmanager.class.php
test -f class/dunningnotice.class.php
test -f invoice.php
test -f notice.php

grep -q "need_dolibarr_version" core/modules/modMahnwesen.class.php
grep -q "DOL_DOCUMENT_ROOT" core/modules/modMahnwesen.class.php

echo "Dolibarr $VERSION compatibility smoke-check: OK"
