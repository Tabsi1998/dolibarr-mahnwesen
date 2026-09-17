<?php
/*
 * Mahnwesen - Dolibarr custom module
 * Copyright (C) 2026 Module contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor for Mahnwesen.
 */
class modMahnwesen extends DolibarrModules
{
    /**
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf, $langs;

        $this->db = $db;

        // Internal/custom module id. IDs > 500000 are intended for modules
        // that are not distributed publicly and do not require reservation.
        $this->numero = 756220;
        $this->rights_class = 'mahnwesen';
        $this->family = 'financial';
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'ModuleMahnwesenDesc';
        $this->descriptionlong = 'ModuleMahnwesenDescLong';
        $this->editor_name = 'Custom Dolibarr Module';
        $this->editor_url = '';
        $this->version = '1.1.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'bill';

        $this->module_parts = array(
            'triggers' => 0,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'printing' => 0,
            'theme' => 0,
            'css' => array('/mahnwesen/css/mahnwesen.css'),
            'js' => array('/mahnwesen/js/mahnwesen-emailtemplates.js'),
            // Dolibarr 21+ accepts the simple string-list form for hook contexts.
            // Using the direct form avoids an activation-format ambiguity seen
            // with some external-module installations.
            'hooks' => array('emailtemplates', 'invoicecard'),
            'moduleforexternal' => 0,
        );

        $this->dirs = array('/mahnwesen/temp', '/mahnwesen/notices');
        $this->config_page_url = array('setup.php@mahnwesen');
        $this->hidden = false;
        $this->depends = array('modFacture');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array('mahnwesen@mahnwesen');
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(21, 0);
        $this->need_javascript_ajax = 0;
        $this->warnings_activation = array();
        $this->warnings_activation_ext = array();

        // Add a dedicated Mahnwesen tab to customer invoices.
        $this->tabs = array();
        $this->tabs[] = array('data' => 'invoice:+mahnwesen:Mahnwesen:mahnwesen@mahnwesen:$user->hasRight(\'mahnwesen\', \'dashboard\', \'read\'):/mahnwesen/invoice.php?id=__ID__');
        // Defaults. Automatic sending resets to OFF when the module is disabled,
        // so an update never resumes unattended mail. Manual sending is asked
        // for per notice anyway and keeps the administrator's choice (#54).
        $this->const = array(
            1 => array('MAHNWESEN_MODE', 'chaine', 'controlled', 'Mahnwesen runtime mode', 0, 'current', 0),
            2 => array('MAHNWESEN_STAGE1_DAYS', 'chaine', '3', 'Days after due date for stage 1', 0, 'current', 0),
            3 => array('MAHNWESEN_STAGE2_DAYS', 'chaine', '10', 'Days after due date for stage 2', 0, 'current', 0),
            4 => array('MAHNWESEN_STAGE3_DAYS', 'chaine', '20', 'Days after due date for stage 3', 0, 'current', 0),
            5 => array('MAHNWESEN_STAGE4_DAYS', 'chaine', '30', 'Days after due date for stage 4', 0, 'current', 0),
            6 => array('MAHNWESEN_MIN_AMOUNT', 'chaine', '1.00', 'Minimum remaining amount to include', 0, 'current', 0),
            7 => array('MAHNWESEN_MAX_SCAN', 'chaine', '500', 'Maximum invoices scanned per run', 0, 'current', 0),
            8 => array('MAHNWESEN_INCLUDE_DEPOSITS', 'chaine', '0', 'Include deposit invoices in dunning scan', 0, 'current', 0),
            9 => array('MAHNWESEN_FROM_EMAIL', 'chaine', '', 'Sender email for dunning notices', 0, 'current', 0),
            10 => array('MAHNWESEN_MANUAL_SEND_ENABLED', 'chaine', '0', 'Allow explicitly approved manual dunning email sends', 0, 'current', 0),
            11 => array('MAHNWESEN_AUTO_SEND_ENABLED', 'chaine', '0', 'Allow cron to send configured dunning levels automatically', 0, 'current', 1),
            12 => array('MAHNWESEN_AUTO_SEND_MAX', 'chaine', '10', 'Maximum automatic sends per cron run', 0, 'current', 0),
            13 => array('MAHNWESEN_AUTO_RECIPIENT_POLICY', 'chaine', 'single_billing', 'Automatic recipient resolution policy', 0, 'current', 0),
            15 => array('MAHNWESEN_PRIVATE_FEES_ALLOWED', 'chaine', '0', 'Apply configured dunning fees to TE_PRIVATE customers', 0, 'current', 0),
            16 => array('MAHNWESEN_UNKNOWN_FEES_ALLOWED', 'chaine', '0', 'Apply configured dunning fees to unknown/special customer types', 0, 'current', 0),
            17 => array('MAHNWESEN_PRIVATE_FEE_1', 'chaine', '0.00', 'Private-person fee at payment reminder', 0, 'current', 0),
            18 => array('MAHNWESEN_PRIVATE_FEE_2', 'chaine', '0.00', 'Private-person fee at first dunning notice', 0, 'current', 0),
            19 => array('MAHNWESEN_PRIVATE_FEE_3', 'chaine', '0.00', 'Private-person fee at second dunning notice', 0, 'current', 0),
            20 => array('MAHNWESEN_PRIVATE_FEE_4', 'chaine', '0.00', 'Private-person fee at third dunning notice', 0, 'current', 0),
            21 => array('MAHNWESEN_ALLOW_LANGUAGE_FALLBACK', 'chaine', '0', 'Allow explicit cross-language template fallback', 0, 'current', 0),
            22 => array('MAHNWESEN_AUTO_RETRY_MAX', 'chaine', '3', 'Maximum failed automatic attempts per case and stage', 0, 'current', 0),
            23 => array('MAHNWESEN_AUTO_MAX_PER_CUSTOMER', 'chaine', '1', 'Maximum automatic delivery attempts per customer and cron run', 0, 'current', 0),
            24 => array('MAHNWESEN_MAX_EXTRA_ATTACHMENTS', 'chaine', '5', 'Maximum manually uploaded email attachments', 0, 'current', 0),
            25 => array('MAHNWESEN_MAX_EXTRA_ATTACHMENT_MB', 'chaine', '10', 'Maximum size of one manually uploaded attachment', 0, 'current', 0),
            26 => array('MAHNWESEN_PAYMENT_DAYS_1', 'chaine', '0', 'Payment period in days granted by the payment reminder, 0 for none', 0, 'current', 0),
            27 => array('MAHNWESEN_PAYMENT_DAYS_2', 'chaine', '0', 'Payment period in days granted by the first dunning notice, 0 for none', 0, 'current', 0),
            28 => array('MAHNWESEN_PAYMENT_DAYS_3', 'chaine', '0', 'Payment period in days granted by the second dunning notice, 0 for none', 0, 'current', 0),
            29 => array('MAHNWESEN_PAYMENT_DAYS_4', 'chaine', '0', 'Payment period in days granted by the third dunning notice, 0 for none', 0, 'current', 0),
        );

        // Daily worker. Case synchronization and timed pause release are safe
        // module-only writes. Actual automatic email sending remains gated by
        // MAHNWESEN_AUTO_SEND_ENABLED (OFF by default) and per-stage flags.
        $this->cronjobs = array(
            0 => array(
                'label' => 'Mahnwesen daily workflow',
                'jobtype' => 'method',
                'class' => '/mahnwesen/class/dunningmanager.class.php',
                'objectname' => 'DunningManager',
                'method' => 'doScheduledJob',
                'parameters' => '',
                'comment' => 'Synchronizes dunning cases, releases dated pauses and optionally sends explicitly configured stages. Never modifies invoices.',
                'frequency' => 1,
                'unitfrequency' => 86400,
                'status' => 1,
                'test' => 'isModEnabled("mahnwesen")',
                'priority' => 50,
            ),
        );

        // Permissions.
        $this->rights = array();
        $r = 0;
        $this->rights[$r][0] = $this->numero.'11';
        $this->rights[$r][1] = 'Read Mahnwesen dashboard';
        $this->rights[$r][4] = 'dashboard';
        $this->rights[$r][5] = 'read';
        $r++;

        $this->rights[$r][0] = $this->numero.'12';
        $this->rights[$r][1] = 'Manage dunning cases';
        $this->rights[$r][4] = 'case';
        $this->rights[$r][5] = 'write';
        $r++;

        $this->rights[$r][0] = $this->numero.'13';
        $this->rights[$r][1] = 'Send dunning notices';
        $this->rights[$r][4] = 'notice';
        $this->rights[$r][5] = 'send';
        $r++;

        // Menus.
        $this->menu = array();
        $r = 0;
        $this->menu[$r++] = array(
            'fk_menu' => '',
            'type' => 'top',
            'titre' => 'ModuleMahnwesenName',
            'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
            'mainmenu' => 'mahnwesen',
            'leftmenu' => '',
            'url' => '/mahnwesen/index.php',
            'langs' => 'mahnwesen@mahnwesen',
            'position' => 1000 + $r,
            'enabled' => 'isModEnabled("mahnwesen")',
            'perms' => '$user->hasRight("mahnwesen", "dashboard", "read")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=mahnwesen',
            'type' => 'left',
            'titre' => 'MahnwesenSendAttempts',
            'mainmenu' => 'mahnwesen',
            'leftmenu' => 'mahnwesen_attempts',
            'url' => '/mahnwesen/attempts.php',
            'langs' => 'mahnwesen@mahnwesen',
            'position' => 1000 + $r,
            'enabled' => 'isModEnabled("mahnwesen")',
            'perms' => '$user->hasRight("mahnwesen", "case", "write")',
            'target' => '',
            'user' => 0,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=mahnwesen',
            'type' => 'left',
            'titre' => 'MahnwesenDashboard',
            'mainmenu' => 'mahnwesen',
            'leftmenu' => 'mahnwesen_dashboard',
            'url' => '/mahnwesen/index.php',
            'langs' => 'mahnwesen@mahnwesen',
            'position' => 1000 + $r,
            'enabled' => 'isModEnabled("mahnwesen")',
            'perms' => '$user->hasRight("mahnwesen", "dashboard", "read")',
            'target' => '',
            'user' => 0,
        );
    }

    /**
     * Enable module.
     *
     * @param string $options Options
     * @return int 1 on success, <=0 on failure
     */
    public function init($options = '')
    {
        global $conf;

        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

        $result = $this->_load_tables('/mahnwesen/sql/');
        if ($result < 0) {
            return -1;
        }

        $this->remove($options);
        $sql = array();
        $res = $this->_init($sql, $options);
        if ($res > 0) {
            // Redundant/self-healing registration for Dolibarr's email-template hook.
            // Conf::setValues() exposes MAIN_MODULE_*_HOOKS through
            // $conf->modules_parts['hooks']. The plain context string is also
            // supported by Dolibarr's backward-compatible hook loader.
            dolibarr_set_const($this->db, 'MAIN_MODULE_MAHNWESEN_HOOKS', json_encode(array('emailtemplates', 'invoicecard')), 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($this->db, 'MAIN_MODULE_MAHNWESEN_JS', json_encode(array('/mahnwesen/js/mahnwesen-emailtemplates.js')), 'chaine', 0, '', $conf->entity);
            // Keep the current request coherent too; the next request will reload
            // the same values from llx_const.
            if (!isset($conf->modules_parts['hooks']) || !is_array($conf->modules_parts['hooks'])) { $conf->modules_parts['hooks'] = array(); }
            $conf->modules_parts['hooks']['mahnwesen'] = array('emailtemplates', 'invoicecard');

            // Migrate only templates that were created by Mahnwesen 0.4.0 under
            // the former single technical type. No user/core templates outside
            // module='mahnwesen' are touched.
            $legacyMap = array(
                'Mahnwesen - Zahlungserinnerung' => 'mahnwesen_reminder',
                'Mahnwesen - 1. Mahnung' => 'mahnwesen_dunning1',
                'Mahnwesen - 2. Mahnung' => 'mahnwesen_dunning2',
                'Mahnwesen - Letzte Mahnung' => 'mahnwesen_dunning3',
            );
            foreach ($legacyMap as $label => $type) {
                $sql = 'UPDATE '.MAIN_DB_PREFIX.'c_email_templates SET type_template = \''.$this->db->escape($type).'\'';
                $sql .= ' WHERE module = \'mahnwesen\' AND type_template = \'mahnwesen_send\' AND label = \''.$this->db->escape($label).'\'';
                $this->db->query($sql);
            }


            // Provide one editable starter template per stage automatically.
            // Existing templates are never overwritten.
            try {
                require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);
                require_once dol_buildpath('/mahnwesen/class/dunningnotice.class.php', 0);
                $manager = new DunningManager($this->db);
                $notice = new DunningNoticeService($this->db, $manager);
                $notice->createDefaultNativeTemplates(isset($GLOBALS['user']) ? $GLOBALS['user'] : null);
                $notice->cleanupExactDuplicateModuleTemplates();
            } catch (Throwable $e) {
                dol_syslog(__METHOD__.' Unable to create starter email templates: '.$e->getMessage(), LOG_WARNING);
            }
        }
        return $res;
    }

    /**
     * Disable module.
     *
     * @param string $options Options
     * @return int
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
