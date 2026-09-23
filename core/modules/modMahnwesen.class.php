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
        $this->version = '1.4.2';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'bill';

        $this->module_parts = array(
            // A payment or a correction of an invoice re-evaluates its case at once (#36).
            'triggers' => 1,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'printing' => 0,
            'theme' => 0,
            // CSS comes with the module's own pages, the template help with
            // Dolibarr's email template page only (addHtmlHeader), not with
            // every Dolibarr page (#27).
            'hooks' => array('emailtemplates', 'invoicecard', 'main'),
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
            6 => array('MAHNWESEN_MIN_AMOUNT', 'chaine', '1.00', 'Minimum remaining amount to include', 0, 'current', 0),
            7 => array('MAHNWESEN_MAX_SCAN', 'chaine', '500', 'Maximum invoices scanned per run', 0, 'current', 0),
            8 => array('MAHNWESEN_INCLUDE_DEPOSITS', 'chaine', '0', 'Include deposit invoices in dunning scan', 0, 'current', 0),
            9 => array('MAHNWESEN_FROM_EMAIL', 'chaine', '', 'Sender email for dunning notices', 0, 'current', 0),
            10 => array('MAHNWESEN_MANUAL_SEND_ENABLED', 'chaine', '0', 'Allow explicitly approved manual dunning email sends', 0, 'current', 0),
            11 => array('MAHNWESEN_AUTO_SEND_ENABLED', 'chaine', '0', 'Allow cron to send configured dunning levels automatically', 0, 'current', 1),
            12 => array('MAHNWESEN_AUTO_SEND_MAX', 'chaine', '10', 'Maximum automatic sends per cron run', 0, 'current', 0),
            13 => array('MAHNWESEN_AUTO_RECIPIENT_POLICY', 'chaine', 'single_billing', 'Automatic recipient resolution policy', 0, 'current', 0),
            21 => array('MAHNWESEN_ALLOW_LANGUAGE_FALLBACK', 'chaine', '0', 'Allow explicit cross-language template fallback', 0, 'current', 0),
            22 => array('MAHNWESEN_AUTO_RETRY_MAX', 'chaine', '3', 'Maximum failed automatic attempts per case and stage', 0, 'current', 0),
            23 => array('MAHNWESEN_AUTO_MAX_PER_CUSTOMER', 'chaine', '1', 'Maximum automatic delivery attempts per customer and cron run', 0, 'current', 0),
            24 => array('MAHNWESEN_MAX_EXTRA_ATTACHMENTS', 'chaine', '5', 'Maximum manually uploaded email attachments', 0, 'current', 0),
            25 => array('MAHNWESEN_MAX_EXTRA_ATTACHMENT_MB', 'chaine', '10', 'Maximum size of one manually uploaded attachment', 0, 'current', 0),
            30 => array('MAHNWESEN_RUN_NOTIFY_EMAIL', 'chaine', '', 'Address told about automatic runs that fail or end with warnings', 0, 'current', 0),
            31 => array('MAHNWESEN_LETTER_QR', 'chaine', '1', 'Print the EPC QR code of the invoice on the dunning letter', 0, 'current', 0),
            32 => array('MAHNWESEN_EVENT_RETRY_MAX', 'chaine', '5', 'How often an event delivery is retried before it rests in the backlog', 0, 'current', 0),
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

        // Runs the cron's decision without sending; it writes a run record (#16).
        $this->rights[$r][0] = $this->numero.'14';
        $this->rights[$r][1] = 'Run the automatic dry run';
        $this->rights[$r][4] = 'automation';
        $this->rights[$r][5] = 'dryrun';
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

        // Stage settings of earlier versions move into the stage table before
        // the module counts as enabled; the code no longer reads them (#20).
        require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);
        $migration = new DunningManager($this->db);
        if (!$migration->migrateStageSettings(isset($GLOBALS['user']) ? $GLOBALS['user'] : null)) {
            $this->error = 'Unable to move the stage settings into the stage table: '.$migration->error;
            dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
            return -1;
        }
        // The fees for companies and private persons become dunning profiles, once (#32).
        if (!$migration->migrateProfiles(isset($GLOBALS['user']) ? $GLOBALS['user'] : null)) {
            $this->error = 'Unable to move the fee settings into dunning profiles: '.$migration->error;
            dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
            return -1;
        }

        // Dolibarr's own fields hold the dunning block of a customer and of an
        // invoice (#37). Adding a field that exists changes nothing.
        require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);
        $blockFields = array(
            array('mahnwesen_block', 'MahnwesenBlock', 'boolean', '', 'MahnwesenBlockHelp'),
            array('mahnwesen_block_until', 'MahnwesenBlockUntil', 'date', '', 'MahnwesenBlockUntilHelp'),
            array('mahnwesen_block_reason', 'MahnwesenBlockReason', 'varchar', '255', ''),
        );
        foreach (array('societe', 'facture') as $elementtype) {
            foreach ($blockFields as $position => $field) {
                $added = $extrafields->addExtraField($field[0], $field[1], $field[2], 1100 + $position, $field[3], $elementtype, 0, 0, '', '', 1, '', '1', $field[4], '', '', 'mahnwesen@mahnwesen', "isModEnabled('mahnwesen')");
                if ($added <= 0) {
                    $this->error = 'Unable to add the field '.$field[0].' to '.$elementtype.': '.$extrafields->error;
                    dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
                    return -1;
                }
            }
        }
        // The dunning profile chosen on an invoice goes before its categories (#32).
        $profileList = array('options' => array('mahnwesen_profile:label:rowid::(active:=:1) AND (entity:=:$ENTITY$)' => null));
        if ($extrafields->addExtraField('mahnwesen_profile', 'MahnwesenProfile', 'sellist', 1103, '', 'facture', 0, 0, '', $profileList, 1, '', '1', 'MahnwesenProfileFieldHelp', '', '', 'mahnwesen@mahnwesen', "isModEnabled('mahnwesen')") <= 0) {
            $this->error = 'Unable to add the field mahnwesen_profile to facture: '.$extrafields->error;
            dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
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
            dolibarr_set_const($this->db, 'MAIN_MODULE_MAHNWESEN_HOOKS', json_encode(array('emailtemplates', 'invoicecard', 'main')), 'chaine', 0, '', $conf->entity);
            // Keep the current request coherent too; the next request will reload
            // the same values from llx_const.
            if (!isset($conf->modules_parts['hooks']) || !is_array($conf->modules_parts['hooks'])) { $conf->modules_parts['hooks'] = array(); }
            $conf->modules_parts['hooks']['mahnwesen'] = array('emailtemplates', 'invoicecard', 'main');

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
        global $conf;

        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

        // Versions before 1.2.3 loaded the CSS and JS on every page (#27). Dolibarr
        // only removes the parts the module still declares, so these go by name.
        foreach (array('MAIN_MODULE_MAHNWESEN_CSS', 'MAIN_MODULE_MAHNWESEN_JS') as $name) {
            dolibarr_del_const($this->db, $name, $conf->entity);
        }
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
