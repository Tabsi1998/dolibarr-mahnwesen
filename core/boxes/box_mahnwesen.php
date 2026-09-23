<?php
/*
 * Mahnwesen - Dolibarr custom module
 * Copyright (C) 2026 Module contributors
 * GPL-3.0-or-later
 */

/**
 * \file    core/boxes/box_mahnwesen.php
 * \ingroup mahnwesen
 * \brief   Box on the home page: what dunning needs today (#41)
 */

require_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * The box counts exactly what the dashboard counts: cases whose next step is
 * due, delivery attempts nobody resolved yet, and the open claims of the
 * ledger. A user sees only their own customers.
 */
class box_mahnwesen extends ModeleBoxes
{
    public $boxcode = 'mahnwesendue';
    public $boximg = 'object_bill';
    public $boxlabel = 'MahnwesenBoxTitle';
    public $depends = array('mahnwesen');

    /** @var DoliDB */
    public $db;

    public $enabled = 1;

    /**
     * @param DoliDB $db Database handler
     * @param string $param More parameters
     */
    public function __construct($db, $param = '')
    {
        global $user;
        $this->db = $db;
        $this->hidden = !$user->hasRight('mahnwesen', 'dashboard', 'read');
    }

    /**
     * Fill the box with the figures of today.
     *
     * @param int $max Maximum number of rows
     * @return void
     */
    public function loadBox($max = 5)
    {
        global $conf, $langs, $user;
        $langs->loadLangs(array('mahnwesen@mahnwesen'));
        $this->info_box_head = array('text' => $langs->trans('MahnwesenBoxTitle')
            .'<a class="paddingleft valignmiddle" href="'.dol_buildpath('/mahnwesen/index.php', 1).'"><span class="badge">...</span></a>');
        $this->info_box_contents = array();
        if (!$user->hasRight('mahnwesen', 'dashboard', 'read')) {
            return;
        }
        $scope = $user->hasRight('societe', 'client', 'voir') ? ''
            : ' INNER JOIN '.MAIN_DB_PREFIX.'societe_commerciaux as sc ON sc.fk_soc = f.fk_soc AND sc.fk_user = '.((int) $user->id);
        $nowSql = "'".$this->db->escape($this->db->idate(dol_now()))."'";
        $figures = array(
            'MahnwesenBoxDue' => 'SELECT COUNT(*) as total FROM '.MAIN_DB_PREFIX.'mahnwesen_case as c'
                .' INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = c.fk_facture'.$scope
                .' WHERE c.entity = '.((int) $conf->entity)." AND c.status = 'open' AND c.paused = 0"
                .' AND c.next_action_at IS NOT NULL AND c.next_action_at <= '.$nowSql,
            'MahnwesenBoxUnresolved' => 'SELECT COUNT(*) as total FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt as a'
                .' INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = a.fk_facture'.$scope
                .' WHERE a.entity = '.((int) $conf->entity)." AND a.status IN ('reserved', 'sending', 'ambiguous')",
        );
        foreach ($figures as $labelKey => $sql) {
            $this->info_box_contents[] = array(
                array('td' => 'class="left"', 'text' => $langs->trans($labelKey)),
                array('td' => 'class="right"', 'text' => (string) $this->countOf($sql)),
            );
        }
        $sql = 'SELECT SUM(fe.amount) as total FROM '.MAIN_DB_PREFIX.'mahnwesen_fee as fe'
            .' INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = fe.fk_facture'.$scope
            .' WHERE fe.entity = '.((int) $conf->entity)." AND fe.status = 'open'";
        $resql = $this->db->query($sql);
        $row = $resql ? $this->db->fetch_object($resql) : false;
        if ($resql) {
            $this->db->free($resql);
        }
        $this->info_box_contents[] = array(
            array('td' => 'class="left"', 'text' => $langs->trans('MahnwesenBoxClaims')),
            array('td' => 'class="right"', 'text' => price($row ? (float) $row->total : 0.0, 0, $langs, 1, -1, -1, $conf->currency)),
        );
    }

    /**
     * Run one counting query.
     *
     * @param string $sql Query with one column total
     * @return int
     */
    protected function countOf($sql)
    {
        $resql = $this->db->query($sql);
        $row = $resql ? $this->db->fetch_object($resql) : false;
        if ($resql) {
            $this->db->free($resql);
        }
        return $row ? (int) $row->total : 0;
    }

    /**
     * Print the box.
     *
     * @param array $head Header
     * @param array $contents Content
     * @param int $nooutput No print, return the content
     * @return string
     */
    public function showBox($head = null, $contents = null, $nooutput = 0)
    {
        return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
    }
}
