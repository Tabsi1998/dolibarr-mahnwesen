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
    /** @var string[] */
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
    public function addHtmlHeader($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;
        // Only Dolibarr's email template page needs the variable help (#27),
        // in the user's language (#28).
        if (!preg_match('#/admin/mails_templates\.php$#', (string) ($_SERVER['PHP_SELF'] ?? ''))) {
            return 0;
        }
        $langs->load('mahnwesen@mahnwesen');
        $tokens = array(
            '__MAHNWESEN_STAGE__' => 'MahnwesenTokenStageDesc',
            '__MAHNWESEN_OPEN_AMOUNT__' => 'MahnwesenTokenOpenAmountDesc',
            '__MAHNWESEN_FEE__' => 'MahnwesenTokenFeeDesc',
            '__MAHNWESEN_TOTAL__' => 'MahnwesenTokenTotalDesc',
            '__MAHNWESEN_CUSTOMER_CLASS__' => 'MahnwesenTokenCustomerClassDesc',
            '__MAHNWESEN_NEXT_STAGE_DATE__' => 'MahnwesenTokenNextStageDesc',
            '__MAHNWESEN_FEE_PARAGRAPH__' => 'MahnwesenTokenFeeParagraphDesc',
            '__MAHNWESEN_PAYMENT_DEADLINE__' => 'MahnwesenTokenPaymentDeadlineDesc',
            '__MAHNWESEN_PAYMENT_DAYS__' => 'MahnwesenTokenPaymentDaysDesc',
            '{INVOICE_REF}' => 'MahnwesenTokenInvoiceRefDesc',
            '{CUSTOMER_NAME}' => 'MahnwesenTokenCustomerNameDesc',
            '{INVOICE_DATE}' => 'MahnwesenTokenInvoiceDateDesc',
            '{DUE_DATE}' => 'MahnwesenTokenDueDateDesc',
            '{TODAY}' => 'MahnwesenTokenTodayDesc',
            '{COMPANY_NAME}' => 'MahnwesenTokenCompanyNameDesc',
        );
        $help = array('title' => $langs->transnoentities('MahnwesenTemplateVariables'), 'hint' => $langs->transnoentities('MahnwesenTemplateVariablesHint'),
            'copy' => $langs->transnoentities('MahnwesenTemplateVariableCopy'), 'tokens' => array());
        foreach ($tokens as $token => $key) {
            $help['tokens'][] = array($token, $langs->transnoentities($key));
        }
        // Safe inside <script>: no tag or entity can close it.
        $json = str_replace(array('<', '>', '&'), array('\u003c', '\u003e', '\u0026'), (string) json_encode($help));
        $this->resprints = '<script>window.mahnwesenTemplateHelp = '.$json.';</script>'
            .'<script src="'.dol_escape_htmltag(dol_buildpath('/mahnwesen/js/mahnwesen-emailtemplates.js', 1)).'"></script>';
        return 0;
    }

    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $user;

        // Dolibarr calls a module once per hook and page, in the first of the
        // page's contexts that it registered; with 'main' that is not the card's.
        $contexts = explode(':', (string) ($parameters['context'] ?? ($parameters['currentcontext'] ?? '')));
        if (!in_array('invoicecard', $contexts, true)) {
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
            // One action on the invoice card; the PDF and the rest are on the dunning tab (#56).
            $out = dolGetButtonAction($langs->trans('MahnwesenPrepareStageHelp'), img_picto('', 'email').' '.dol_escape_htmltag($label), 'default', $url, 'mahnwesen-prepare');
            // The invoice card does not print the hook manager's output for
            // this hook, so the buttons are printed here, as Dolibarr expects.
            print $out;
            return 0;
        }

        $out = '';
        // Do not add noisy disabled buttons for a closed/completed workflow. A
        // paused case gets one disabled indicator so the user understands why
        // the expected dunning action is currently unavailable.
        if (!empty($case['paused']) && $requiredLevel > 0) {
            $stage = $langs->trans($manager->getStageLabelKey($requiredLevel));
            $title = $langs->trans('NoticeCasePaused');
            $out = '<span class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($title).'">'.img_picto('', 'email').' '.dol_escape_htmltag($langs->trans('MahnwesenPrepareStage', $stage)).'</span>';
        } elseif ($requiredLevel > 0 && !empty($workflow['required_at']) && ((int) $this->db->jdate($workflow['required_at'])) > dol_now()) {
            $stage = $langs->trans($manager->getStageLabelKey($requiredLevel));
            $when = dol_print_date($this->db->jdate($workflow['required_at']), 'day');
            $title = $langs->trans('MahnwesenSequentialCooldownInfo', $stage, $when);
            $out = '<span class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($title).'">'.img_picto('', 'email').' '.dol_escape_htmltag($langs->trans('MahnwesenPrepareStage', $stage)).'</span>';
        }
        print $out;
        return 0;
    }

}
