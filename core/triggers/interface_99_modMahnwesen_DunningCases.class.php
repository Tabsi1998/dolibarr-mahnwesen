<?php
/*
 * Mahnwesen - Dolibarr custom module
 * Copyright (C) 2026 Module contributors
 * GPL-3.0-or-later
 */

/**
 * \file       core/triggers/interface_99_modMahnwesen_DunningCases.class.php
 * \ingroup    mahnwesen
 * \brief      Payments and invoice corrections re-evaluate a dunning case at once (#36)
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * A payment or a correction of an invoice changes what is open. The dunning
 * case follows immediately instead of waiting for the daily run, through the
 * same evaluation the daily run and the manual synchronisation use.
 *
 * Fees and interest in the ledger are not touched: paying an invoice does not
 * pay them, and money that was never received (a credit note, a write-off) is
 * never recorded as a payment.
 */
class InterfaceDunningCases extends DolibarrTriggers
{
    /** Actions after which the open amount of an invoice can differ. */
    const INVOICE_ACTIONS = array('BILL_PAYED', 'BILL_UNPAYED', 'BILL_CANCEL', 'BILL_VALIDATE', 'BILL_UNVALIDATE', 'BILL_MODIFY', 'BILL_DELETE');

    /** Actions on a customer payment, which can touch several invoices. */
    const PAYMENT_ACTIONS = array('PAYMENT_CUSTOMER_CREATE', 'PAYMENT_CUSTOMER_DELETE');

    /**
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = 'DunningCases';
        $this->family = 'mahnwesen';
        $this->description = 'Keeps dunning cases in step with payments and invoice corrections';
        $this->version = 'dolibarr';
        $this->picto = 'bill';
    }

    /**
     * Re-evaluate the dunning case of every invoice the action touched.
     *
     * @param string $action Trigger name
     * @param CommonObject $object Object of the action
     * @param User $user Acting user
     * @param Translate $langs Language
     * @param Conf $conf Configuration
     * @return int 0 when the action is none of ours, 1 on success
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (!isModEnabled('mahnwesen')) {
            return 0;
        }
        $invoices = $this->invoicesOf($action, $object);
        if (empty($invoices)) {
            return 0;
        }
        require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);
        $manager = new DunningManager($this->db);
        foreach ($invoices as $invoiceId) {
            // Deleting an invoice leaves no case to follow; the daily run cleans up.
            if ($action === 'BILL_DELETE') {
                continue;
            }
            $result = $manager->syncInvoiceCase((int) $invoiceId, $user);
            if ($result === false) {
                $this->errors[] = 'Mahnwesen could not re-evaluate invoice '.((int) $invoiceId).' after '.$action.': '.$manager->error;
                dol_syslog(__METHOD__.' '.end($this->errors), LOG_ERR);
                // The business action itself stays valid; the daily run repairs the case.
                return 0;
            }
        }
        dol_syslog(__METHOD__.' '.$action.' re-evaluated '.count($invoices).' invoice(s)', LOG_DEBUG);
        return 1;
    }

    /**
     * The customer invoices an action touched.
     *
     * @param string $action Trigger name
     * @param CommonObject $object Object of the action
     * @return int[]
     */
    protected function invoicesOf($action, $object)
    {
        if (in_array($action, self::INVOICE_ACTIONS, true)) {
            return (is_object($object) && !empty($object->id) && $object->element === 'facture') ? array((int) $object->id) : array();
        }
        if (!in_array($action, self::PAYMENT_ACTIONS, true) || !is_object($object) || empty($object->id)) {
            return array();
        }
        // A payment can pay several invoices; Dolibarr keeps the amounts per invoice.
        $invoices = array();
        if (!empty($object->amounts) && is_array($object->amounts)) {
            foreach (array_keys($object->amounts) as $invoiceId) {
                if ((int) $invoiceId > 0) {
                    $invoices[(int) $invoiceId] = (int) $invoiceId;
                }
            }
        }
        $sql = 'SELECT fk_facture FROM '.MAIN_DB_PREFIX.'paiement_facture WHERE fk_paiement = '.((int) $object->id);
        $resql = $this->db->query($sql);
        while ($resql && ($row = $this->db->fetch_object($resql))) {
            $invoices[(int) $row->fk_facture] = (int) $row->fk_facture;
        }
        if ($resql) {
            $this->db->free($resql);
        }
        return array_values($invoices);
    }
}
