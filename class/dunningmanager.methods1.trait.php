<?php
/* Auto-split method trait for maintainable source files. */
trait DunningManagerMethods1
{
    /** @var DoliDB */
    public $db;
    public $error = '';
    public $errors = array();
    public $output = '';
    public $diagnostics = array();
    protected $rulesCache = null;

    public function __construct($db) { $this->db = $db; }

    public function scanDueInvoices($limit = 0)
    {
        $this->error=''; $this->errors=array(); $this->diagnostics=array();
        $limit=(int)$limit; if($limit<=0) $limit=$this->getMaxScan();
        $todayStart=dol_get_first_hour(dol_now(),'tzserver'); $todaySql=$this->db->idate($todayStart);
        $todayYmd=dol_print_date($todayStart,'%Y-%m-%d','tzserver'); if(empty($todayYmd)) $todayYmd=date('Y-m-d');
        $minAmount=$this->getMinimumAmount(); $this->diagnostics=$this->loadRawDiagnostics($todaySql);
        foreach(array('scanned_candidates','excluded_type','excluded_no_balance','excluded_below_minimum','excluded_invalid_due_date','excluded_fetch_error','included','type_standard','type_replacement','type_credit_note','type_deposit','type_proforma','type_situation','type_other') as $k) $this->diagnostics[$k]=0;
        $this->diagnostics['scan_limit']=$limit; $this->diagnostics['minimum_amount']=$minAmount; $this->diagnostics['include_deposits']=$this->includeDepositInvoices()?1:0;
        $sql='SELECT f.rowid, f.entity as invoice_entity, f.fk_soc, f.date_lim_reglement, f.type, f.fk_statut, f.paye, s.nom as socname, mc.rowid as case_id, mc.paused as case_paused, mc.current_level as case_level, mc.status as case_status FROM '.MAIN_DB_PREFIX.'facture as f INNER JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid=f.fk_soc LEFT JOIN '.MAIN_DB_PREFIX.'mahnwesen_case as mc ON mc.fk_facture=f.rowid AND mc.entity=f.entity WHERE f.entity IN ('.getEntity('invoice').') AND f.fk_statut='.((int)Facture::STATUS_VALIDATED).' AND f.date_lim_reglement IS NOT NULL AND f.date_lim_reglement < \''.$this->db->escape($todaySql).'\' ORDER BY f.date_lim_reglement ASC, f.rowid ASC'.$this->db->plimit($limit);
        $resql=$this->db->query($sql); if(!$resql){$this->error=$this->db->lasterror();$this->errors[]=$this->error;return false;}
        $rows=array();
        while($obj=$this->db->fetch_object($resql)){
            $this->diagnostics['scanned_candidates']++; $this->countType((int)$obj->type);
            if(!$this->isSupportedInvoiceType((int)$obj->type)){ $this->diagnostics['excluded_type']++; continue; }
            $invoice=new Facture($this->db); if($invoice->fetch((int)$obj->rowid)<=0){$this->diagnostics['excluded_fetch_error']++;continue;}
            $remainRaw=$invoice->getRemainToPay(0); if(!is_numeric($remainRaw)){ $this->diagnostics['excluded_fetch_error']++; continue; }
            $remain=(float)$remainRaw; if($remain<=0){$this->diagnostics['excluded_no_balance']++;continue;} if($remain<$minAmount){$this->diagnostics['excluded_below_minimum']++;continue;}
            $dueYmd=dol_print_date($invoice->date_lim_reglement,'%Y-%m-%d','tzserver'); if(empty($dueYmd)){ $this->diagnostics['excluded_invalid_due_date']++; continue; }
            $daysLate=$this->daysBetween($dueYmd,$todayYmd); $stage=$this->determineStage($daysLate); $caseId=isset($obj->case_id)?(int)$obj->case_id:0;
            $nextRequiredLevel=$this->getNextRequiredLevel($caseId,$stage); $highestCompletedLevel=$this->getHighestCompletedLevel($caseId);
            $nextRequiredAt=$nextRequiredLevel>0?$this->calculateWorkflowStageDueAt($caseId,$dueYmd,$nextRequiredLevel):null;
            $nextFutureLevel=$this->getNextFutureLevel($stage); $nextFutureAt=$nextFutureLevel>0?$this->calculateWorkflowStageDueAt($caseId,$dueYmd,$nextFutureLevel):null;
            $rows[]=array('invoice_id'=>(int)$invoice->id,'invoice_ref'=>$invoice->ref,'invoice_type'=>(int)$invoice->type,'socid'=>(int)$invoice->socid,'socname'=>(string)$obj->socname,'invoice_date'=>$invoice->date,'due_date'=>$invoice->date_lim_reglement,'due_ymd'=>$dueYmd,'days_late'=>$daysLate,'total_ttc'=>(float)$invoice->total_ttc,'remain_to_pay'=>$remain,'stage'=>$stage,'stage_key'=>$this->getStageLabelKey($stage),'next_required_level'=>$nextRequiredLevel,'next_required_key'=>$this->getStageLabelKey($nextRequiredLevel),'next_required_at'=>$nextRequiredAt,'next_future_level'=>$nextFutureLevel,'next_future_at'=>$nextFutureAt,'highest_completed_level'=>$highestCompletedLevel,'paused'=>!empty($obj->case_paused),'stored_level'=>isset($obj->case_level)?(int)$obj->case_level:0,'case_status'=>isset($obj->case_status)?(string)$obj->case_status:'','case_id'=>$caseId,'invoice_entity'=>isset($obj->invoice_entity)?(int)$obj->invoice_entity:1);
            $this->diagnostics['included']++;
        }
        $this->db->free($resql); return $rows;
    }

    protected function loadRawDiagnostics($todaySql)
    {
        $diag=array('all_invoices_entity'=>0,'validated_status1'=>0,'validated_no_due_date'=>0,'validated_due_today_or_future'=>0,'validated_overdue_raw'=>0,'validated_paye_flag_set'=>0);
        $sql='SELECT COUNT(*) as all_invoices_entity, SUM(CASE WHEN f.fk_statut='.((int)Facture::STATUS_VALIDATED).' THEN 1 ELSE 0 END) as validated_status1, SUM(CASE WHEN f.fk_statut='.((int)Facture::STATUS_VALIDATED).' AND f.date_lim_reglement IS NULL THEN 1 ELSE 0 END) as validated_no_due_date, SUM(CASE WHEN f.fk_statut='.((int)Facture::STATUS_VALIDATED).' AND f.date_lim_reglement IS NOT NULL AND f.date_lim_reglement >= \''.$this->db->escape($todaySql).'\' THEN 1 ELSE 0 END) as validated_due_today_or_future, SUM(CASE WHEN f.fk_statut='.((int)Facture::STATUS_VALIDATED).' AND f.date_lim_reglement IS NOT NULL AND f.date_lim_reglement < \''.$this->db->escape($todaySql).'\' THEN 1 ELSE 0 END) as validated_overdue_raw, SUM(CASE WHEN f.fk_statut='.((int)Facture::STATUS_VALIDATED).' AND f.paye=1 THEN 1 ELSE 0 END) as validated_paye_flag_set FROM '.MAIN_DB_PREFIX.'facture as f WHERE f.entity IN ('.getEntity('invoice').')';
        $resql=$this->db->query($sql); if($resql){$obj=$this->db->fetch_object($resql);if($obj){foreach($diag as $k=>$v){if(isset($obj->$k))$diag[$k]=(int)$obj->$k;}}$this->db->free($resql);} return $diag;
    }

    protected function countType($type)
    {
        $map=array(Facture::TYPE_STANDARD=>'type_standard',Facture::TYPE_REPLACEMENT=>'type_replacement',Facture::TYPE_CREDIT_NOTE=>'type_credit_note',Facture::TYPE_DEPOSIT=>'type_deposit',Facture::TYPE_PROFORMA=>'type_proforma',Facture::TYPE_SITUATION=>'type_situation');
        $k=isset($map[$type])?$map[$type]:'type_other'; $this->diagnostics[$k]++;
    }
    public function isSupportedInvoiceType($type){$a=array(Facture::TYPE_STANDARD,Facture::TYPE_REPLACEMENT,Facture::TYPE_SITUATION);if($this->includeDepositInvoices())$a[]=Facture::TYPE_DEPOSIT;return in_array((int)$type,$a,true);}
    public function includeDepositInvoices(){return getDolGlobalInt('MAHNWESEN_INCLUDE_DEPOSITS',0)>0;}
    public function determineStage($daysLate){$daysLate=max(0,(int)$daysLate);$stage=0;foreach($this->getStageThresholds() as $l=>$d){if($daysLate>=$d)$stage=(int)$l;}return $stage;}
    public function getStageThresholds(){ $rules=$this->getRules();$out=array();foreach($rules as $l=>$r)$out[(int)$l]=(int)$r['days_after_due'];if(count($out)===4){ksort($out);return $out;}return array(1=>$this->getIntSetting('MAHNWESEN_STAGE1_DAYS',3),2=>$this->getIntSetting('MAHNWESEN_STAGE2_DAYS',10),3=>$this->getIntSetting('MAHNWESEN_STAGE3_DAYS',20),4=>$this->getIntSetting('MAHNWESEN_STAGE4_DAYS',30)); }
    public function getMinimumAmount(){ $v=getDolGlobalString('MAHNWESEN_MIN_AMOUNT');if($v==='')$v='1.00';return max(0.0,(float)price2num($v)); }
    public function getMaxScan(){return max(1,$this->getIntSetting('MAHNWESEN_MAX_SCAN',500));}

    public function getCaseByInvoice($invoiceId)
    {
        $sql='SELECT rowid, entity, fk_facture, current_level, paused, status, remaining_amount, last_notice_at, next_action_at, note_private, date_creation, tms, fk_user_create, fk_user_modif FROM '.MAIN_DB_PREFIX.'mahnwesen_case WHERE fk_facture='.((int)$invoiceId).' AND entity IN ('.getEntity('invoice').') ORDER BY rowid DESC'.$this->db->plimit(1);
        $res=$this->db->query($sql);if(!$res){$this->error=$this->db->lasterror();return null;}$o=$this->db->fetch_object($res);$this->db->free($res);if(!$o)return null;
        return array('id'=>(int)$o->rowid,'entity'=>(int)$o->entity,'invoice_id'=>(int)$o->fk_facture,'current_level'=>(int)$o->current_level,'paused'=>(int)$o->paused,'status'=>(string)$o->status,'remaining_amount'=>(float)$o->remaining_amount,'last_notice_at'=>$o->last_notice_at,'next_action_at'=>$o->next_action_at,'note_private'=>(string)$o->note_private,'date_creation'=>$o->date_creation,'tms'=>$o->tms,'fk_user_create'=>(int)$o->fk_user_create,'fk_user_modif'=>(int)$o->fk_user_modif);
    }

    public function getHistoryByInvoice($invoiceId,$limit=100)
    {
        $limit=max(1,min(500,(int)$limit));$rows=array();$sql='SELECT rowid,fk_case,fk_facture,action,level,amount_snapshot,mode,result,recipient,message,date_creation,fk_user_create FROM '.MAIN_DB_PREFIX.'mahnwesen_history WHERE fk_facture='.((int)$invoiceId).' AND entity IN ('.getEntity('invoice').') ORDER BY date_creation DESC,rowid DESC'.$this->db->plimit($limit);$res=$this->db->query($sql);if(!$res)return $rows;
        while($o=$this->db->fetch_object($res))$rows[]=array('id'=>(int)$o->rowid,'case_id'=>(int)$o->fk_case,'invoice_id'=>(int)$o->fk_facture,'action'=>(string)$o->action,'level'=>(int)$o->level,'amount_snapshot'=>(float)$o->amount_snapshot,'mode'=>(string)$o->mode,'result'=>(string)$o->result,'recipient'=>(string)$o->recipient,'message'=>(string)$o->message,'date_creation'=>$o->date_creation,'fk_user_create'=>(int)$o->fk_user_create);
        $this->db->free($res);return $rows;
    }
}
