<?php
/*
 * Mahnwesen - Dolibarr custom module hooks
 * GPL-3.0-or-later
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Hook actions for Mahnwesen.
 */
class ActionsMahnwesen extends CommonHookActions
{
    /** @var DoliDB */
    public $db;
    /** @var array */
    public $results = array();
    /** @var string */
    public $resprints = '';
    /** @var string */
    public $error = '';
    /** @var array */
    public $errors = array();

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Add four real Mahnwesen template types to Dolibarr's native
     * E-Mail-Einstellungen -> E-Mail-Vorlagen page.
     *
     * @param array $parameters Hook parameters
     * @param mixed $object Object (unused)
     * @param string $action Action
     * @param HookManager $hookmanager Hook manager
     * @return int
     */
    public function emailElementlist($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;

        $langs->load('mahnwesen@mahnwesen');
        $this->results = array(
            'mahnwesen_reminder' => img_picto('', 'bill', 'class="pictofixedwidth"').$langs->trans('EmailTemplateTypePaymentReminder'),
            'mahnwesen_dunning1' => img_picto('', 'bill', 'class="pictofixedwidth"').$langs->trans('EmailTemplateTypeDunning1'),
            'mahnwesen_dunning2' => img_picto('', 'bill', 'class="pictofixedwidth"').$langs->trans('EmailTemplateTypeDunning2'),
            'mahnwesen_dunning3' => img_picto('', 'bill', 'class="pictofixedwidth"').$langs->trans('EmailTemplateTypeDunning3'),
        );
        return 0;
    }

    /**
     * Add the next sequential dunning action to the regular customer invoice card.
     * The calendar stage is deliberately not used as the button target: an earlier
     * due stage must be completed first, so the UI cannot encourage stage skipping.
     *
     * @param array $parameters Hook parameters
     * @param mixed $object Current Dolibarr object (Facture in invoicecard)
     * @param string $action Current action
     * @param HookManager $hookmanager Hook manager
     * @return int
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $user;

        if (empty($parameters['currentcontext']) || strpos((string) $parameters['currentcontext'], 'invoicecard') === false) {
            return 0;
        }
        if (!is_object($object) || empty($object->id) || !isset($object->element) || $object->element !== 'facture') {
            return 0;
        }
        if (!$user->hasRight('mahnwesen', 'dashboard', 'read') || !$user->hasRight('mahnwesen', 'notice', 'send')) {
            return 0;
        }

        $langs->load('mahnwesen@mahnwesen');
        require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);
        $manager = new DunningManager($this->db);
        $workflow = $manager->getWorkflowState((int) $object->id);
        if ($workflow === false || empty($workflow['case'])) {
            return 0;
        }

        $case = $workflow['case'];
        $requiredLevel = (int) $workflow['next_required_level'];
        $url = dol_buildpath('/mahnwesen/notice.php?id='.(int) $object->id, 1);

        if (!empty($workflow['actionable']) && $requiredLevel > 0) {
            $stage = $langs->trans($manager->getStageLabelKey($requiredLevel));
            $label = $langs->trans('MahnwesenPrepareStage', $stage);
            $pdfAction = dol_buildpath('/mahnwesen/invoice.php', 1);
            $pdfLabel = $langs->trans('MahnwesenGenerateLinkedPdf', $stage);
            $this->resprints = '<a class="butAction" href="'.dol_escape_htmltag($url).'">'.img_picto('', 'email').' '.dol_escape_htmltag($label).'</a>';
            $this->resprints .= '<form method="POST" class="inline-block" action="'.dol_escape_htmltag($pdfAction).'">';
            $this->resprints .= '<input type="hidden" name="token" value="'.newToken().'">';
            $this->resprints .= '<input type="hidden" name="id" value="'.((int) $object->id).'">';
            $this->resprints .= '<input type="hidden" name="action" value="generate_notice_pdf">';
            $this->resprints .= '<button class="butAction" type="submit">'.img_picto('', 'pdf').' '.dol_escape_htmltag($pdfLabel).'</button></form>';
            return 0;
        }

        // Do not add noisy disabled buttons for a closed/completed workflow. A
        // paused case gets one disabled indicator so the user understands why
        // the expected dunning action is currently unavailable.
        if (!empty($case['paused']) && $requiredLevel > 0) {
            $stage = $langs->trans($manager->getStageLabelKey($requiredLevel));
            $title = $langs->trans('NoticeCasePaused');
            $this->resprints = '<span class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($title).'">'.img_picto('', 'email').' '.dol_escape_htmltag($langs->trans('MahnwesenPrepareStage', $stage)).'</span>';
        } elseif ($requiredLevel > 0 && !empty($workflow['required_at']) && ((int) $this->db->jdate($workflow['required_at'])) > dol_now()) {
            $stage = $langs->trans($manager->getStageLabelKey($requiredLevel));
            $when = dol_print_date($this->db->jdate($workflow['required_at']), 'day');
            $title = $langs->trans('MahnwesenSequentialCooldownInfo', $stage, $when);
            $this->resprints = '<span class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($title).'">'.img_picto('', 'email').' '.dol_escape_htmltag($langs->trans('MahnwesenPrepareStage', $stage)).'</span>';
        }
        return 0;
    }

}
