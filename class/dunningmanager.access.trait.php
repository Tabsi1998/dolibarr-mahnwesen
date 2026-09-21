<?php
/* The one check whether a user may see a customer's invoices (#24). */
trait DunningManagerAccess
{
    /** @var array<int,array<int,bool>> User id => customer id => visible, for one request. */
    protected $customerScopeCache = array();

    /**
     * Whether the user sees this customer, as Dolibarr's invoice pages decide:
     * with the right to see all customers, or as one of its sales representatives.
     *
     * @param User $user User
     * @param int $socid Customer
     * @return bool
     */
    public function canSeeCustomer($user, $socid)
    {
        if (!is_object($user) || empty($user->id)) {
            return false;
        }
        if ($user->hasRight('societe', 'client', 'voir')) {
            return true;
        }
        $uid = (int) $user->id;
        $socid = (int) $socid;
        if (!isset($this->customerScopeCache[$uid][$socid])) {
            $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'societe_commerciaux WHERE fk_soc = '.$socid.' AND fk_user = '.$uid.$this->db->plimit(1);
            $res = $this->db->query($sql);
            $this->customerScopeCache[$uid][$socid] = $res && (bool) $this->db->fetch_object($res);
            if ($res) {
                $this->db->free($res);
            }
        }
        return $this->customerScopeCache[$uid][$socid];
    }
}
