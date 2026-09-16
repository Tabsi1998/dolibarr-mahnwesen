#!/usr/bin/env bash
set -euo pipefail
VERSION="${1:-22.0}"
case "$VERSION" in
  21.0|22.0|23.0|24.0) ;;
  *) echo "Unsupported CI compatibility target: $VERSION" >&2; exit 1 ;;
esac

TMPDIR_API="$(mktemp -d)"
trap 'rm -rf "$TMPDIR_API"' EXIT
BASE="https://raw.githubusercontent.com/Dolibarr/dolibarr/${VERSION}/htdocs"
curl --fail --silent --show-error --location "$BASE/compta/facture/card.php" -o "$TMPDIR_API/invoice-card.php"
curl --fail --silent --show-error --location "$BASE/compta/facture/class/facture.class.php" -o "$TMPDIR_API/facture.class.php"
curl --fail --silent --show-error --location "$BASE/core/class/commoninvoice.class.php" -o "$TMPDIR_API/commoninvoice.class.php"
curl --fail --silent --show-error --location "$BASE/core/class/CMailFile.class.php" -o "$TMPDIR_API/CMailFile.class.php"
curl --fail --silent --show-error --location "$BASE/core/class/html.formmail.class.php" -o "$TMPDIR_API/formmail.class.php"
curl --fail --silent --show-error --location "$BASE/core/class/html.formfile.class.php" -o "$TMPDIR_API/formfile.class.php"
curl --fail --silent --show-error --location "$BASE/core/actions_sendmails.inc.php" -o "$TMPDIR_API/actions_sendmails.inc.php"

grep -q "need_dolibarr_version" core/modules/modMahnwesen.class.php
grep -q "DOL_DOCUMENT_ROOT" core/modules/modMahnwesen.class.php
grep -q "function getRemainToPay" "$TMPDIR_API/commoninvoice.class.php"
grep -q "class CMailFile" "$TMPDIR_API/CMailFile.class.php"
grep -q "c_email_senderprofile" "$TMPDIR_API/formmail.class.php"
grep -q "function get_form" "$TMPDIR_API/formmail.class.php"
grep -q "function get_attached_files" "$TMPDIR_API/formmail.class.php"
grep -q "function clear_attached_files" "$TMPDIR_API/formmail.class.php"
grep -q "function setSubstitFromObject" "$TMPDIR_API/formmail.class.php"
grep -q "function showPreview" "$TMPDIR_API/formfile.class.php"
grep -q "dol_add_file_process" "$TMPDIR_API/actions_sendmails.inc.php"
grep -q "restrictedArea" "$TMPDIR_API/invoice-card.php"

echo "Dolibarr $VERSION source/API compatibility check: OK"
