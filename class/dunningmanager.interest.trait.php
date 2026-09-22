<?php
/* Late-payment interest: the base rates over time, and what an invoice owes by today (#33). */
trait DunningManagerInterest
{
    /** @return array<string,string> The interest rules a profile can follow, with their language key */
    public function getInterestModes()
    {
        return array('none' => 'MahnwesenInterestModeNone', 'fixed' => 'MahnwesenInterestModeFixed', 'base_plus' => 'MahnwesenInterestModeBasePlus');
    }

    /**
     * The base rates of the active entity, newest first, each with the day it
     * starts to apply (#33).
     *
     * @param bool $refresh Read again
     * @return array<int,array{id:int,from:string,rate:float,note:string}>
     */
    public function getInterestRates($refresh = false)
    {
        global $conf;
        if (!$refresh && is_array($this->interestRatesCache)) {
            return $this->interestRatesCache;
        }
        $rates = array();
        $res = $this->db->query('SELECT rowid, date_from, rate, note FROM '.MAIN_DB_PREFIX.'mahnwesen_interest_rate WHERE entity = '.((int) $conf->entity).' ORDER BY date_from DESC');
        if (!$res) {
            $this->error = $this->db->lasterror();
            return $rates;
        }
        while ($o = $this->db->fetch_object($res)) {
            $rates[] = array('id' => (int) $o->rowid, 'from' => substr((string) $o->date_from, 0, 10), 'rate' => (float) $o->rate, 'note' => (string) $o->note);
        }
        $this->db->free($res);
        $this->interestRatesCache = $rates;
        return $rates;
    }

    /** Add or change the base rate that applies from one day on. */
    public function saveInterestRate($dateFrom, $rate, $note, $user)
    {
        global $conf, $langs;
        $day = substr(trim((string) $dateFrom), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $this->error = $langs->trans('MahnwesenInterestRateDateInvalid');
            return false;
        }
        $rate = (float) price2num($rate);
        if ($rate < -10 || $rate > 100) {
            $this->error = $langs->trans('MahnwesenInterestRateInvalid');
            return false;
        }
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $existing = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX."mahnwesen_interest_rate WHERE entity = ".((int) $conf->entity)." AND date_from = '".$this->db->escape($day)."'".$this->db->plimit(1));
        $row = $existing ? $this->db->fetch_object($existing) : false;
        if ($existing) {
            $this->db->free($existing);
        }
        if ($row) {
            $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_interest_rate SET rate = '.$rate.", note = '".$this->db->escape((string) $note)."' WHERE rowid = ".((int) $row->rowid);
        } else {
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_interest_rate (entity, date_from, rate, note, date_creation, fk_user_create) VALUES (';
            $sql .= ((int) $conf->entity).", '".$this->db->escape($day)."', ".$rate.", '".$this->db->escape((string) $note)."', '".$this->db->idate(dol_now())."', ".$uid.')';
        }
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $this->interestRatesCache = null;
        return true;
    }

    /** Remove one base rate; the days it covered then carry no interest. */
    public function deleteInterestRate($rateId)
    {
        global $conf;
        if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'mahnwesen_interest_rate WHERE entity = '.((int) $conf->entity).' AND rowid = '.((int) $rateId))) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $this->interestRatesCache = null;
        return true;
    }

    /**
     * What an invoice owes in late-payment interest by a day (#33).
     *
     * The interest follows the profile of the invoice: no rule means no
     * interest. It is counted per day on the open amount, from the day after
     * the due date, with the base rate of each day. The invoice itself stays
     * as it is.
     *
     * @param Facture $invoice Invoice
     * @param float $amount Open amount
     * @param int $profileId Profile, 0 for the default profile
     * @param string $untilYmd Last day, empty for today
     * @return array{amount:float,days:int,parts:array,mode:string,rate:float}
     */
    public function calculateInterest($invoice, $amount, $profileId = 0, $untilYmd = '')
    {
        $profiles = $this->getProfiles();
        $profileId = (int) $profileId > 0 ? (int) $profileId : $this->getDefaultProfileId();
        $mode = isset($profiles[$profileId]) ? (string) $profiles[$profileId]['interest_mode'] : 'none';
        $rate = isset($profiles[$profileId]) ? (float) $profiles[$profileId]['interest_rate'] : 0.0;
        $empty = array('amount' => 0.0, 'days' => 0, 'parts' => array(), 'mode' => $mode, 'rate' => $rate);
        if ($mode === 'none' || empty($invoice->date_lim_reglement)) {
            return $empty;
        }
        $dueYmd = dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d', 'tzserver');
        $until = $untilYmd !== '' ? substr((string) $untilYmd, 0, 10) : dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
        if (empty($dueYmd) || empty($until)) {
            return $empty;
        }
        $interest = MahnwesenInterestPolicy::interest((float) $amount, $dueYmd, $until, $this->getInterestRates(), $mode, $rate);
        return array_merge($interest, array('mode' => $mode, 'rate' => $rate));
    }

    /**
     * The interest of an invoice in one phrase, for the letter and the email.
     *
     * @param array $interest From calculateInterest()
     * @param Translate $outputlangs Language of the customer
     * @return string Plain text, empty without interest
     */
    public function describeInterest($interest, $outputlangs)
    {
        global $conf;
        if ((float) $interest['amount'] <= 0.000001) {
            return '';
        }
        $rates = array();
        foreach ($interest['parts'] as $part) {
            $rates[(string) $part['rate']] = price((float) $part['rate'], 0, $outputlangs, 0, -1, 2).' %';
        }
        return $outputlangs->transnoentities('MahnwesenInterestLine', price((float) $interest['amount'], 0, $outputlangs, 1, -1, -1, $conf->currency),
            (int) $interest['days'], implode(', ', $rates));
    }
}
