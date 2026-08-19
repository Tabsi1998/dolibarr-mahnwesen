<?php
/* Auto-split method trait for maintainable source files. */
trait DunningManagerMethods3
{

    /** Calendar state + sequential workflow state for one invoice. */
    public function getWorkflowState($invoiceId)
    {
        $evaluation = $this->evaluateInvoice((int) $invoiceId);
        if ($evaluation === false) { return false; }
        $case = $this->getCaseByInvoice((int) $invoiceId);
        $calculated = !empty($evaluation['eligible']) ? (int) $evaluation['row']['stage'] : 0;
        $caseId = $case ? (int) $case['id'] : 0;
        $required = $this->getNextRequiredLevel($caseId, $calculated);
        $future = $this->getNextFutureLevel($calculated);
        $dueYmd = !empty($evaluation['row']['due_ymd']) ? (string) $evaluation['row']['due_ymd'] : '';
        $requiredAt = $required > 0 ? $this->calculateWorkflowStageDueAt($caseId, $dueYmd, $required) : null;
        $futureAt = $future > 0 ? $this->calculateWorkflowStageDueAt($caseId, $dueYmd, $future) : null;
        $requiredReached = ($requiredAt === null || ((int) $this->db->jdate($requiredAt)) <= dol_now());
        return array(
            'evaluation' => $evaluation,
            'case' => $case,
            'calculated_level' => $calculated,
            'next_required_level' => $required,
            'next_future_level' => $future,
            'completed_levels' => $this->getCompletedLevels($caseId),
            'required_at' => $requiredAt,
            'future_at' => $futureAt,
            'actionable' => ($case && $case['status'] !== 'closed' && empty($case['paused']) && !empty($evaluation['eligible']) && $required > 0 && $requiredReached) ? 1 : 0,
        );
    }

    public function syncHistoryToAgenda($invoiceId, $fallbackUser = null, $limit = 250)
    {
        global $langs, $conf;
        if (!isModEnabled('agenda')) { return 0; }
        require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $invoiceId) <= 0) { return 0; }
        $invoice->fetch_thirdparty();
        $history = $this->getHistoryByInvoice((int) $invoiceId, max(1, min(1000, (int) $limit)));
        if (empty($history)) { return 0; }
        $history = array_reverse($history);
        $created = 0;
        foreach ($history as $row) {
            if ((string) $row['action'] === 'notice_sending' && (string) $row['result'] === 'pending') { continue; }
            $refExt = 'mahnwesen-history-'.((int) $row['id']);
            $sql = 'SELECT id FROM '.MAIN_DB_PREFIX."actioncomm WHERE ref_ext = '".$this->db->escape($refExt)."'".$this->db->plimit(1);
            $res = $this->db->query($sql);
            if ($res) { $exists=(bool)$this->db->fetch_object($res);$this->db->free($res);if($exists)continue; }
            $actor=null;
            if (!empty($row['fk_user_create'])) { $tmpuser=new User($this->db);if($tmpuser->fetch((int)$row['fk_user_create'])>0)$actor=$tmpuser; }
            if(!$actor && $fallbackUser instanceof User)$actor=$fallbackUser;
            if(!$actor){$actor=new User($this->db);$actor->id=0;}
            $event=new ActionComm($this->db);
            $event->type_code='AC_OTH_AUTO';$event->code='AC_OTH_AUTO';
            $event->label=$this->getAgendaLabelForHistory($row);
            $event->note_private=$this->getAgendaNoteForHistory($row);
            $event->datep=!empty($row['date_creation'])?$this->db->jdate($row['date_creation']):dol_now();$event->datef=$event->datep;
            $event->percentage=ActionComm::EVENT_FINISHED;$event->userownerid=isset($actor->id)?(int)$actor->id:0;$event->socid=(int)$invoice->socid;
            $event->elementid=(int)$invoice->id;$event->fk_element=(int)$invoice->id;$event->elementtype='facture';$event->ref_ext=$refExt;
            $event->extraparams=array('mahnwesen_history_id'=>(int)$row['id'],'mahnwesen_action'=>(string)$row['action']);
            if(!empty($row['recipient']))$event->email_to=(string)$row['recipient'];
            $mailMeta=$this->getHistoryMailMetadata((string)$row['message']);
            if(property_exists($event,'email_subject')&&!empty($mailMeta['subject']))$event->email_subject=$mailMeta['subject'];
            if(property_exists($event,'email_from')&&!empty($mailMeta['from']))$event->email_from=$mailMeta['from'];
            if(property_exists($event,'email_tocc')&&!empty($mailMeta['cc']))$event->email_tocc=$mailMeta['cc'];
            if(property_exists($event,'email_tobcc')&&!empty($mailMeta['bcc']))$event->email_tobcc=$mailMeta['bcc'];
            $result=$event->create($actor,1);if($result>0)$created++;else dol_syslog(__METHOD__.' Unable to mirror history #'.((int)$row['id']).': '.$event->error,LOG_WARNING);
        }
        return $created;
    }

    protected function getHistoryMailMetadata($message)
    {
        $result=array('subject'=>'','from'=>'','cc'=>'','bcc'=>'');$prefixes=array('Betreff:'=>'subject','Subject:'=>'subject','Von:'=>'from','From:'=>'from','CC:'=>'cc','BCC:'=>'bcc');
        foreach(preg_split('/\r?\n/',(string)$message) as $line){foreach($prefixes as $prefix=>$key){if(strpos($line,$prefix)===0){$result[$key]=trim(substr($line,strlen($prefix)));break;}}}
        return $result;
    }

    public function getHistoryActionLabelKey($action)
    {
        $map=array('case_created'=>'HistoryActionCaseCreated','case_reopened'=>'HistoryActionCaseReopened','case_closed'=>'HistoryActionCaseClosed','level_changed'=>'HistoryActionLevelChanged','paused'=>'HistoryActionPaused','resumed'=>'HistoryActionResumed','auto_resumed'=>'MahnwesenHistoryActionAutoResumed','note_changed'=>'HistoryActionNoteChanged','notice_sending'=>'HistoryActionNoticeSending','notice_sent'=>'HistoryActionNoticeSent','notice_failed'=>'HistoryActionNoticeFailed','stage_skipped'=>'HistoryActionStageSkipped','document_generated'=>'MahnwesenHistoryActionDocumentGenerated');
        return isset($map[(string)$action])?$map[(string)$action]:'HistoryActionOther';
    }
    protected function getAgendaLabelForHistory($row){global $langs;$base=$langs->trans($this->getHistoryActionLabelKey((string)$row['action']));if((int)$row['level']>0)$base.=' - '.$langs->trans($this->getStageLabelKey((int)$row['level']));return $base;}
    protected function getAgendaNoteForHistory($row){global $langs,$conf;$lines=array();if((int)$row['level']>0)$lines[]=$langs->trans('DunningStage').': '.$langs->trans($this->getStageLabelKey((int)$row['level']));$lines[]=$langs->trans('Amount').': '.price((float)$row['amount_snapshot'],0,$langs,1,-1,-1,$conf->currency);if(!empty($row['recipient']))$lines[]=$langs->trans('NoticeRecipient').': '.$row['recipient'];if(!empty($row['result']))$lines[]=$langs->trans('MahnwesenHistoryResult').': '.$langs->trans('MahnwesenHistoryResult'.ucfirst((string)$row['result']));if(trim((string)$row['message'])!==''){$lines[]='';$lines[]=(string)$row['message'];}return implode("\n",$lines);}
    public function recordGeneratedDocument($invoiceId,$level,$relative,$user,$mode='manual'){$case=$this->getCaseByInvoice((int)$invoiceId);if(!$case){$this->error='No dunning case exists for this invoice';return false;}$evaluation=$this->evaluateInvoice((int)$invoiceId);$amount=($evaluation&&isset($evaluation['remain_to_pay']))?(float)$evaluation['remain_to_pay']:(float)$case['remaining_amount'];if(!$this->addHistory((int)$case['entity'],(int)$case['id'],(int)$invoiceId,'document_generated',(int)$level,$amount,(string)$mode,'success','PDF: '.trim((string)$relative),$user))return false;$this->syncHistoryToAgenda((int)$invoiceId,$user);return true;}
    public function hasSuccessfulNoticeAtLevel($caseId,$level){$sql='SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_history WHERE fk_case='.((int)$caseId)." AND action='notice_sent' AND result='success' AND level=".((int)$level).$this->db->plimit(1);$resql=$this->db->query($sql);if(!$resql){$this->error=$this->db->lasterror();return false;}$found=(bool)$this->db->fetch_object($resql);$this->db->free($resql);return $found;}
    public function hasPendingNoticeAtLevel($caseId,$level){$sql='SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_history WHERE fk_case='.((int)$caseId)." AND action='notice_sending' AND result='pending' AND level=".((int)$level).$this->db->plimit(1);$resql=$this->db->query($sql);if(!$resql){$this->error=$this->db->lasterror();return false;}$found=(bool)$this->db->fetch_object($resql);$this->db->free($resql);return $found;}

    public function reserveNoticeAttempt($case,$recipient,$level,$amount,$message,$user,$mode='manual')
    {
        $this->db->begin();$sqlLock='SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_case WHERE rowid='.((int)$case['id']).' FOR UPDATE';$resLock=$this->db->query($sqlLock);
        if(!$resLock||!$this->db->fetch_object($resLock)){$this->error=$this->db->lasterror()?:'Unable to lock dunning case for send reservation';if($resLock)$this->db->free($resLock);$this->db->rollback();return false;}$this->db->free($resLock);
        $sqlCheck='SELECT rowid,action,result FROM '.MAIN_DB_PREFIX.'mahnwesen_history WHERE fk_case='.((int)$case['id']).' AND level='.((int)$level)." AND ((action='notice_sent' AND result='success') OR (action='notice_sending' AND result='pending'))".$this->db->plimit(1);$resCheck=$this->db->query($sqlCheck);if(!$resCheck){$this->error=$this->db->lasterror();$this->db->rollback();return false;}$existing=$this->db->fetch_object($resCheck);$this->db->free($resCheck);if($existing){$this->error=((string)$existing->result==='success')?'A successful notice already exists for this dunning level.':'A notice send is already pending for this dunning level.';$this->db->rollback();return false;}
        $sql='INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_history (entity,fk_case,fk_facture,action,level,amount_snapshot,mode,result,recipient,message,date_creation,fk_user_create) VALUES ('.((int)$case['entity']).','.((int)$case['id']).','.((int)$case['invoice_id']).",'notice_sending',".((int)$level).','.((float)$amount).",'".$this->db->escape((string)$mode)."','pending','".$this->db->escape((string)$recipient)."','".$this->db->escape((string)$message)."','".$this->db->escape($this->db->idate(dol_now()))."',".((is_object($user)&&isset($user->id))?(int)$user->id:0).')';
        if(!$this->db->query($sql)){$this->error=$this->db->lasterror();$this->db->rollback();return false;}$historyId=(int)$this->db->last_insert_id(MAIN_DB_PREFIX.'mahnwesen_history');if($historyId<=0){$this->error='Unable to get send reservation history id';$this->db->rollback();return false;}$this->db->commit();return $historyId;
    }

    public function finalizeNoticeAttempt($historyId,$success,$message,$case,$user)
    {
        $this->db->begin();$sqlLock='SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_history WHERE rowid='.((int)$historyId)." AND action='notice_sending' AND result='pending' FOR UPDATE";$resLock=$this->db->query($sqlLock);
        if(!$resLock||!$this->db->fetch_object($resLock)){$this->error=$this->db->lasterror()?:'Pending send reservation not found during audit finalization';if($resLock)$this->db->free($resLock);$this->db->rollback();return false;}$this->db->free($resLock);
        $action=$success?'notice_sent':'notice_failed';$result=$success?'success':'failed';$sql='UPDATE '.MAIN_DB_PREFIX."mahnwesen_history SET action='".$this->db->escape($action)."', result='".$this->db->escape($result)."', message='".$this->db->escape((string)$message)."' WHERE rowid=".((int)$historyId)." AND action='notice_sending' AND result='pending'";if(!$this->db->query($sql)){$this->error=$this->db->lasterror();$this->db->rollback();return false;}
        if($success){$sqlCase="UPDATE ".MAIN_DB_PREFIX."mahnwesen_case SET last_notice_at='".$this->db->escape($this->db->idate(dol_now()))."', fk_user_modif=".((is_object($user)&&isset($user->id))?(int)$user->id:0)." WHERE rowid=".((int)$case['id']);if(!$this->db->query($sqlCase)){$this->error=$this->db->lasterror();$this->db->rollback();return false;}}
        $this->db->commit();$this->syncHistoryToAgenda((int)$case['invoice_id'],$user);return true;
    }
}
