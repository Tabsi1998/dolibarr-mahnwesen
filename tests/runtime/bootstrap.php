<?php
/*
 * Shared start of the runtime check scripts. They run with the PHP CLI inside
 * a disposable Dolibarr container, mounted outside the web root, and must never
 * answer a web request: a Git checkout under htdocs/custom would otherwise
 * expose them. RT_DOLIBARR_ROOT names the Dolibarr htdocs folder.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('NOSESSION', 1);
define('NOCSRFCHECK', 1);
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

$dolibarrRoot = getenv('RT_DOLIBARR_ROOT') ?: '/var/www/html';
if (!is_file($dolibarrRoot.'/master.inc.php')) {
    fwrite(STDERR, "Dolibarr was not found in ".$dolibarrRoot."\n");
    exit(2);
}
require_once $dolibarrRoot.'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

/** Stop with a message the local check prints. */
function rt_fail($message)
{
    fwrite(STDERR, 'runtime fixture: '.$message."\n");
    exit(3);
}

/** The first administrator, with rights loaded. */
function rt_admin($db)
{
    $admin = new User($db);
    if ($admin->fetch(0, getenv('RT_ADMIN_LOGIN') ?: 'admin') <= 0) {
        rt_fail('the administrator account does not exist');
    }
    $admin->loadRights();
    return $admin;
}

/** One value from the database, or null. */
function rt_value($db, $sql)
{
    $resql = $db->query($sql);
    if (!$resql) {
        rt_fail('query failed: '.$db->lasterror().' - '.$sql);
    }
    $row = $db->fetch_row($resql);
    $db->free($resql);
    return $row ? $row[0] : null;
}

/** Run a statement that must succeed. */
function rt_exec($db, $sql)
{
    if (!$db->query($sql)) {
        rt_fail('statement failed: '.$db->lasterror().' - '.$sql);
    }
}

/** Set a constant in entity 1, the only entity of the check. */
function rt_const($db, $name, $value)
{
    if (dolibarr_set_const($db, $name, (string) $value, 'chaine', 0, '', 1) <= 0) {
        rt_fail('could not set '.$name);
    }
}
