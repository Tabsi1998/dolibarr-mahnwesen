<?php
/*
 * Constants Dolibarr defines while it starts (master.inc.php, filefunc.inc.php),
 * for PHPStan only. Values do not matter, only that they exist.
 */
foreach (array(
    'DOL_DOCUMENT_ROOT' => '/var/www/html',
    'DOL_DATA_ROOT' => '/var/www/documents',
    'DOL_URL_ROOT' => '',
    'DOL_MAIN_URL_ROOT' => '',
    'DOL_VERSION' => '24.0.1',
    'MAIN_DB_PREFIX' => 'llx_',
    'LOG_EMERG' => 0, 'LOG_ALERT' => 1, 'LOG_CRIT' => 2, 'LOG_ERR' => 3,
    'LOG_WARNING' => 4, 'LOG_NOTICE' => 5, 'LOG_INFO' => 6, 'LOG_DEBUG' => 7,
) as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
