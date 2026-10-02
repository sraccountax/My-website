<?php
declare(strict_types=1);

/** Linked adjustment notes. All amounts supplied by the client are in the document currency. */
function note_kind_spec(string $kind): array
{
    return match($kind){
        'customer_credit'=>['source'=>'invoice','permission'=>'ar.credit_note.write','prefix'=>'CN','direction'=>'decrease'],
        'customer_debit'=>['source'=>'invoice','permission'=>'ar.debit_note.write','prefix'=>'DN','direction'=>'increase'],
        'vendor_credit'=>['source'=>'bill','permission'=>'ap.credit_note.write','prefix'=>'SCN','direction'=>'decrease'],
        'vendor_debit'=>['source'=>'bill','permission'=>'ap.debit_note.write','prefix'=>'SDN','direction'=>'decrease'],
        default=>fail('Choose a valid customer or vendor debit or credit note.',422,'note_kind_invalid'),
    };
}

function note_assert_invoice_action_allowed(string $companyId,string $invoiceId,string $action): void
{
    if(!schema_table_exists('accounting_notes'))return;
    $q=db()->prepare("SELECT id FROM accounting_notes WHERE company_id=? AND debit_document_id=? AND status='posted' LIMIT 1");
    $q->execute([$companyId,$invoiceId]);
    if($q->fetchColumn()!==false)fail('Use the linked debit note to reverse this document.',409,'linked_note_action_required');
    if($action==='void'){
        $q=db()->prepare("SELECT id FROM accounting_notes WHERE company_id=? AND source_type='invoice' AND source_id=? AND status IN ('draft','posted') LIMIT 1");
        $q->execute([$companyId,$invoiceId]);
        if($q->fetchColumn()!==false)fail('Void the linked notes before voiding the original invoice.',409,'linked_note_void_required');
    }
}

function note_source(string $companyId,string $sourceType,string $sourceId,bool $lock=true): array
{
    $table=$sourceType==='invoice'?'invoices':'bills';
    $sql="SELECT * FROM `$table` WHERE company_id=? AND id=?".($lock?' FOR UPDATE':'');
    $q=db()->prepare($sql);$q->execute([$companyId,$sourceId]);$row=$q->fetch();
    if(!$row)fail('The original invoice is unavailable in this company.',404,'note_source_missing');
    if($sourceType==='invoice')$row=note_repair_invoice_tax_split($companyId,$row);
    return $row;
}

/* R119: invoices created after the Schema 46 upgrade were saved without their
   GST/HST and PST split, so every taxed note against them failed with
   note_source_tax_unclassified. Recover the split from the invoice's own posted
   journal (the same rule the R69 upgrade used) and persist it. Never guess. */
function note_repair_invoice_tax_split(string $companyId,array $row): array
{
    $tax=(int)($row['tax_cents']??0);
    if($tax<=0||!array_key_exists('gst_hst_cents',$row)||(int)$row['gst_hst_cents']+(int)$row['pst_cents']===$tax||empty($row['issued_journal_entry_id']))return $row;
    $q=db()->prepare("SELECT SUM(CASE WHEN a.code='2100' THEN jl.credit_cents-jl.debit_cents ELSE 0 END) gst,SUM(CASE WHEN a.code='2110' THEN jl.credit_cents-jl.debit_cents ELSE 0 END) pst FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id=? AND a.company_id=? AND a.code IN ('2100','2110')");
    $q->execute([(string)$row['issued_journal_entry_id'],$companyId]);$posted=$q->fetch(PDO::FETCH_ASSOC)?:[];
    $gst=(int)($posted['gst']??0);$pst=(int)($posted['pst']??0);
    if($gst<0||$pst<0||$gst+$pst!==$tax)return $row;
    db()->prepare("UPDATE invoices SET gst_hst_cents=?,pst_cents=?,tax_entry_mode=IF(tax_entry_mode='none','exclusive',tax_entry_mode) WHERE company_id=? AND id=? AND tax_cents=?")
        ->execute([$gst,$pst,$companyId,(string)$row['id'],$tax]);
    $row['gst_hst_cents']=$gst;$row['pst_cents']=$pst;if(($row['tax_entry_mode']??'none')==='none')$row['tax_entry_mode']='exclusive';
    return $row;
}

function note_assert_original_document(string $companyId,string $sourceId): void
{
    $q=db()->prepare("SELECT id FROM accounting_notes WHERE company_id=? AND debit_document_id=? AND status='posted' LIMIT 1");
    $q->execute([$companyId,$sourceId]);
    if($q->fetchColumn()!==false)fail('Choose the original customer invoice rather than another debit note.',409,'note_original_required');
}

function note_reserve_number(string $companyId,string $kind,string $prefix): string
{
    // Caller holds a transaction; the sequence row serializes concurrent creators.
    db()->prepare('INSERT INTO document_sequences(company_id,document_type,next_number) VALUES(?,?,1001) ON DUPLICATE KEY UPDATE next_number=next_number')->execute([$companyId,$kind]);
    $q=db()->prepare('SELECT next_number FROM document_sequences WHERE company_id=? AND document_type=? FOR UPDATE');$q->execute([$companyId,$kind]);$next=(int)$q->fetchColumn();
    db()->prepare('UPDATE document_sequences SET next_number=? WHERE company_id=? AND document_type=?')->execute([$next+1,$companyId,$kind]);
    return $prefix.'-'.$next;
}

function note_remaining_amounts(string $companyId,array $note): array
{
    $q=db()->prepare("SELECT settlement_kind,COALESCE(SUM(amount_cents),0) base_total,COALESCE(SUM(foreign_amount_cents),0) foreign_total,COUNT(*) rows_total FROM accounting_note_settlements WHERE company_id=? AND note_id=? AND status='posted' GROUP BY settlement_kind");
    $q->execute([$companyId,$note['id']]);$by=[];foreach($q->fetchAll() as $row)$by[(string)$row['settlement_kind']]=$row;
    $applied=(int)$note['applied_cents'];
    $foreignApplied=isset($by['application'])?(int)$by['application']['foreign_total']:($applied>0?(int)$note['foreign_total_cents']:0);
    $refunded=(int)($by['refund']['base_total']??0);$foreignRefunded=(int)($by['refund']['foreign_total']??0);
    $legacy=$applied>0&&!isset($by['application']);
    $base=($legacy?$applied:(int)$note['total_cents'])-$applied-$refunded;
    $foreign=(int)$note['foreign_total_cents']-$foreignApplied-$foreignRefunded;
    if($base<0||$foreign<0)fail('The retained note settlements exceed its value. Review the note audit.',409,'note_settlement_conflict',false);
    return ['remainingCents'=>$base,'foreignRemainingCents'=>$foreign,'appliedCents'=>$applied,
        'foreignAppliedCents'=>$foreignApplied,'refundedCents'=>$refunded,'foreignRefundedCents'=>$foreignRefunded,
        'settlementCount'=>(int)($by['application']['rows_total']??0)+(int)($by['refund']['rows_total']??0)];
}

function note_last_settlement_date(string $companyId,string $noteId): ?string
{
    $q=db()->prepare('SELECT MAX(GREATEST(settlement_date,COALESCE(reversal_date,settlement_date))) FROM accounting_note_settlements WHERE company_id=? AND note_id=?');
    $q->execute([$companyId,$noteId]);$value=$q->fetchColumn();
    return $value===false||$value===null?null:(string)$value;
}

function note_assert_settlement_date_order(string $companyId,string $noteId,string $date): void
{
    $last=note_last_settlement_date($companyId,$noteId);
    if($last!==null&&$date<$last)fail('Use a date on or after this note’s latest settlement activity.',409,'note_settlement_date_order');
}

function note_assert_uncredited_source(string $companyId,string $sourceType,array $source,int $foreignTotal): void
{
    $kinds=$sourceType==='invoice'?['customer_credit']:['vendor_credit','vendor_debit'];
    $marks=implode(',',array_fill(0,count($kinds),'?'));
    $q=db()->prepare("SELECT COALESCE(SUM(foreign_total_cents),0) FROM accounting_notes WHERE company_id=? AND source_type=? AND source_id=? AND status='posted' AND note_kind IN ($marks)");
    $q->execute(array_merge([$companyId,$sourceType,$source['id']],$kinds));
    if($foreignTotal>(int)$source['foreign_total_cents']-(int)$q->fetchColumn())
        fail('The total linked credits would exceed the original document.',409,'note_exceeds_original_total');
}

/** Preserve the original invoice's revenue account split on a partial note. */
function note_customer_revenue_lines(string $companyId,string $invoiceId,int $subtotal,bool $reverse): array
{
    $default=account_by_code($companyId,'4000');
    $q=db()->prepare('SELECT COALESCE(income_account_id,?) account_id,SUM(amount_cents) amount_cents FROM invoice_lines WHERE invoice_id=? GROUP BY COALESCE(income_account_id,?) ORDER BY account_id');
    $q->execute([$default,$invoiceId,$default]);$rows=$q->fetchAll();
    if(!$rows)$rows=[['account_id'=>$default,'amount_cents'=>$subtotal]];
    $basis=array_sum(array_map(static fn(array $r):int=>(int)$r['amount_cents'],$rows));
    if($basis<=0)fail('The original invoice has no revenue allocation.',409,'note_revenue_missing');
    $allocated=0;$result=[];$last=count($rows)-1;
    foreach($rows as $i=>$row){
        $amount=$i===$last?$subtotal-$allocated:min($subtotal-$allocated,max(0,(int)round($subtotal*(int)$row['amount_cents']/$basis)));
        $allocated+=$amount;if($amount<=0)continue;
        $result[]=['accountId'=>(string)$row['account_id'],'debitCents'=>$reverse?$amount:0,'creditCents'=>$reverse?0:$amount,'memo'=>'Original invoice revenue adjustment'];
    }
    return $result;
}

function note_control_account_id(string $companyId,bool $ar): string
{
    $keys=$ar?['accounts_receivable','ar_control']:['accounts_payable','ap_control'];
    $q=db()->prepare("SELECT a.id FROM company_system_accounts cs JOIN accounts a ON a.id=cs.account_id AND a.company_id=cs.company_id AND a.active=1 WHERE cs.company_id=? AND cs.status='active' AND cs.system_key IN (?,?) ORDER BY cs.system_key");
    $q->execute([$companyId,$keys[0],$keys[1]]);
    $ids=array_values(array_unique($q->fetchAll(PDO::FETCH_COLUMN)));
    if(count($ids)>1)fail('Resolve the conflicting partner control mappings before posting.',409,'partner_control_mapping_conflict');
    return $ids?(string)$ids[0]:account_by_code($companyId,$ar?'1200':'2050');
}

function note_posting_lines(string $companyId,array $company,array $note,array $source,int $carrying): array
{
    $kind=(string)$note['note_kind'];$net=$carrying-(int)$note['tax_cents'];$tax=(int)$note['tax_cents'];
    if($net<=0)fail('The note amount is too small for its tax at the original exchange rate.',409,'note_tax_rounding_invalid');
    // R137: when the original document saved tax-code components, the note's
    // tax is split across those components and posts to each one's account.
    $isCustomer=in_array($kind,['customer_debit','customer_credit'],true);
    $rows=function_exists('document_tax_rows_get')?document_tax_rows_get($companyId,$isCustomer?'invoice':'bill',(string)$source['id']):[];
    if($rows&&$tax>0){
        $part=tax_rows_portion($rows,$tax);
        if($isCustomer){
            $increase=$kind==='customer_debit';
            $result=[['accountId'=>note_control_account_id($companyId,true),'debitCents'=>$increase?$carrying:0,'creditCents'=>$increase?0:$carrying,'memo'=>'Accounts receivable']];
            $result=array_merge($result,note_customer_revenue_lines($companyId,(string)$source['id'],$net,!$increase));
            return array_merge($result,tax_rows_sales_lines($companyId,$part,!$increase));
        }
        $purchase=tax_rows_purchase_lines($companyId,$part,true);
        $result=[['accountId'=>note_control_account_id($companyId,false),'debitCents'=>$carrying,'creditCents'=>0,'memo'=>'Accounts payable']];
        $result[]=['accountId'=>(string)$source['category_account_id'],'debitCents'=>0,'creditCents'=>$net+$purchase['costCents'],'memo'=>'Original vendor invoice cost adjustment'];
        return array_merge($result,$purchase['lines']);
    }
    $sourceTax=(int)$source['tax_cents'];$sourceGst=(int)$source['gst_hst_cents'];$sourcePst=(int)$source['pst_cents'];
    if($tax>0&&($sourceTax<=0||$sourceGst+$sourcePst!==$sourceTax))
        fail('The original invoice tax split must be reconciled before this note can be posted.',409,'note_source_tax_unclassified');
    $gst=$tax>0?(int)round($tax*$sourceGst/$sourceTax):0;$pst=$tax-$gst;
    if($kind==='customer_debit' || $kind==='customer_credit'){
        $increase=$kind==='customer_debit';
        $result=[['accountId'=>note_control_account_id($companyId,true),'debitCents'=>$increase?$carrying:0,'creditCents'=>$increase?0:$carrying,'memo'=>'Accounts receivable']];
        $result=array_merge($result,note_customer_revenue_lines($companyId,(string)$source['id'],$net,!$increase));
        if($gst>0)$result[]=['accountId'=>account_by_code($companyId,'2100'),'debitCents'=>$increase?0:$gst,'creditCents'=>$increase?$gst:0,'memo'=>'GST/HST adjustment'];
        if($pst>0)$result[]=['accountId'=>account_by_code($companyId,'2110'),'debitCents'=>$increase?0:$pst,'creditCents'=>$increase?$pst:0,'memo'=>'PST adjustment'];
        return $result;
    }
    $recoverable=!empty($company['pst_recoverable']);
    $result=[['accountId'=>note_control_account_id($companyId,false),'debitCents'=>$carrying,'creditCents'=>0,'memo'=>'Accounts payable']];
    $cost=$net+($recoverable?0:$pst);
    $result[]=['accountId'=>(string)$source['category_account_id'],'debitCents'=>0,'creditCents'=>$cost,'memo'=>'Original vendor invoice cost adjustment'];
    if($gst>0)$result[]=['accountId'=>account_by_code($companyId,'1100'),'debitCents'=>0,'creditCents'=>$gst,'memo'=>'GST/HST recoverable adjustment'];
    if($pst>0&&$recoverable)$result[]=['accountId'=>account_by_code($companyId,'1110'),'debitCents'=>0,'creditCents'=>$pst,'memo'=>'PST recoverable adjustment'];
    return $result;
}

function note_prepare(array $company,array $input): array
{
    $companyId=(string)$company['id'];$kind=clean_text($input['kind']??'','Note kind',32);$spec=note_kind_spec($kind);
    require_company_permission($company,$spec['permission']);
    // Cash-basis note recognition and refunds require their own settlement paths.
    if((string)$company['accounting_basis']!=='accrual')fail('Linked notes currently require accrual accounting. Cash-basis note settlement is not enabled.',409,'note_cash_basis_unavailable');
    $sourceId=clean_text($input['sourceId']??'','Original invoice',64);
    $source=note_source($companyId,$spec['source'],$sourceId);
    if($spec['source']==='invoice')note_assert_original_document($companyId,$sourceId);
    if(!empty($source['is_opening_document']))fail('Opening documents require a separate cutover adjustment.',409,'note_opening_document_unavailable');
    $date=safe_date($input['date']??'','Note date');assert_not_future_date($date,'Note date');assert_period_open($companyId,$date);
    $sourceDate=(string)$source[$spec['source']==='invoice'?'issue_date':'bill_date'];
    if($date<$sourceDate)fail('The note date cannot precede its original invoice date.',422,'note_date_invalid');
    $status=(string)$source['status'];$increase=$spec['direction']==='increase';
    if(!in_array($status,$spec['source']==='invoice'?['sent','paid']:['open','paid'],true))
        fail('Choose an issued original invoice or bill.',409,'note_source_unavailable');
    $net=safe_cents($input['subtotalCents']??0,'Note subtotal');$tax=safe_cents($input['taxCents']??0,'Note tax',true);
    if($tax<0)fail('The note tax cannot be negative.',422,'note_tax_negative');
    if($net<=0)fail('The note subtotal must be positive.');$foreignTotal=$net+$tax;
    if($foreignTotal>0 && $foreignTotal>PHP_INT_MAX/2)fail('The note amount is too large.');
    $expected=(int)round($net*(int)$source['foreign_tax_cents']/max(1,(int)$source['foreign_subtotal_cents']));
    if(abs($tax-$expected)>1)fail('The note tax must use the original invoice tax proportions.',422,'note_tax_mismatch');
    if(!$increase)note_assert_uncredited_source($companyId,$spec['source'],$source,$foreignTotal);
    $rate=(int)$source['exchange_rate_micros'];$total=convert_to_base_cents($foreignTotal,$rate);$baseTax=convert_to_base_cents($tax,$rate);$baseNet=$total-$baseTax;
    if($baseNet<=0)fail('The note subtotal rounds to zero in the company currency.',422,'note_amount_too_small');
    if($total>company_materiality_threshold_cents($companyId))fail('This material note requires a separate approval workflow before posting.',409,'material_note_review_required');
    return compact('kind','spec','sourceId','source','date','net','tax','foreignTotal','rate','total','baseTax','baseNet')+['memo'=>optional_text($input['memo']??null,500)??''];
}

function note_issue(array $user,array $company,array $note,bool $leaveOpen=false): array
{
    $companyId=(string)$company['id'];$spec=note_kind_spec((string)$note['note_kind']);require_company_permission($company,$spec['permission']);
    if((string)$note['status']!=='draft')fail('Only a draft note can be posted.',409,'note_already_posted');
    $source=note_source($companyId,$spec['source'],(string)$note['source_id']);
    if($spec['source']==='invoice')note_assert_original_document($companyId,(string)$note['source_id']);
    $sourceDate=(string)$source[$spec['source']==='invoice'?'issue_date':'bill_date'];
    if((string)$note['note_date']<$sourceDate)fail('The original invoice date changed.',409,'note_source_changed');
    if(!empty($source['is_opening_document']))fail('Opening documents require a separate adjustment.',409,'note_opening_document_unavailable');
    if((string)$company['accounting_basis']!=='accrual')fail('Cash-basis note settlement is not enabled.',409,'note_cash_basis_unavailable');
    assert_not_future_date((string)$note['note_date'],'Note date');assert_period_open($companyId,(string)$note['note_date']);
    if((string)$note['currency']!==(string)$source['currency']||(int)$note['exchange_rate_micros']!==(int)$source['exchange_rate_micros'])fail('The original currency or exchange rate changed.',409,'note_source_changed');
    if((int)$note['total_cents']>company_materiality_threshold_cents($companyId))fail('This material note requires a separate approval workflow before posting.',409,'material_note_review_required');
    $increase=$spec['direction']==='increase';
    if($increase&&$leaveOpen)fail('Customer debit notes create their own receivable.',422,'note_settlement_mode_invalid');
    if(!in_array((string)$source['status'],$spec['source']==='invoice'?['sent','paid']:['open','paid'],true))fail('The original invoice is unavailable.',409,'note_source_unavailable');
    if(!$increase)note_assert_uncredited_source($companyId,$spec['source'],$source,(int)$note['foreign_total_cents']);
    if(!$increase&&!$leaveOpen&&((string)$source['status']!==($spec['source']==='invoice'?'sent':'open')||(int)$note['foreign_total_cents']>(int)$source['foreign_balance_cents']))
        fail('Choose Leave open for a paid document or a note above its unpaid balance.',409,'note_requires_open_settlement');
    $carrying=(!$increase&&!$leaveOpen&&(int)$note['foreign_total_cents']===(int)$source['foreign_balance_cents'])?(int)$source['balance_cents']:(int)$note['total_cents'];
    if($carrying>company_materiality_threshold_cents($companyId))fail('This material note requires a separate approval workflow before posting.',409,'material_note_review_required');
    if(!$increase&&!$leaveOpen&&$carrying>(int)$source['balance_cents'])fail('The note exceeds the original invoice balance in company currency.',409,'note_exceeds_base_balance');
    $lines=note_posting_lines($companyId,$company,$note,$source,$carrying);
    $journal=add_journal_entry($user,$companyId,(string)$note['note_date'],'accounting_note',(string)$note['id'],ucwords(str_replace('_',' ',(string)$note['note_kind'])).' '.(string)$note['number'].' · Original '.(string)$source['number'],$lines);
    $debitDocumentId=null;
    if($increase){
        $debitDocumentId=new_id('invoice');$dueDate=(string)$note['note_date'];
        $customerId=(string)$source['customer_id'];
        $gst=((int)$note['tax_cents']>0)
            ?(int)round((int)$note['tax_cents']*(int)$source['gst_hst_cents']/max(1,(int)$source['tax_cents']))
            :0;
        $pst=(int)$note['tax_cents']-$gst;
        db()->prepare("INSERT INTO invoices(id,company_id,customer_id,number,issue_date,due_date,status,subtotal_cents,gst_hst_cents,pst_cents,tax_cents,tax_entry_mode,total_cents,balance_cents,message,currency,exchange_rate_micros,foreign_subtotal_cents,foreign_tax_cents,foreign_total_cents,foreign_balance_cents,issued_journal_entry_id,template_snapshot_json,customer_snapshot_json) VALUES(?,?,?,?,?,?,'sent',?,?,?,?,'exclusive',?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$debitDocumentId,$companyId,$customerId,$note['number'],$note['note_date'],$dueDate,$carrying-(int)$note['tax_cents'],$gst,$pst,(int)$note['tax_cents'],$carrying,$carrying,'Debit note for invoice '.(string)$source['number'].' · '.(string)$note['memo'],$note['currency'],$note['exchange_rate_micros'],$note['foreign_subtotal_cents'],$note['foreign_tax_cents'],$note['foreign_total_cents'],$note['foreign_total_cents'],$journal,$source['template_snapshot_json']??null,$source['customer_snapshot_json']??null]);
        if(function_exists('document_tax_rows_get')&&(int)$note['tax_cents']>0){$srcRows=document_tax_rows_get($companyId,'invoice',(string)$source['id']);if($srcRows)document_tax_rows_save($companyId,'invoice',$debitDocumentId,tax_rows_portion($srcRows,(int)$note['tax_cents'],(int)$note['foreign_tax_cents']));}
        $q=db()->prepare('SELECT income_account_id FROM invoice_lines WHERE invoice_id=? ORDER BY sort_order,id LIMIT 1');$q->execute([(string)$source['id']]);$income=$q->fetchColumn()?:null;
        $rateBps=(int)round(10000*(int)$note['foreign_tax_cents']/max(1,(int)$note['foreign_subtotal_cents']));
        db()->prepare('INSERT INTO invoice_lines(id,invoice_id,income_account_id,description,quantity_milli,unit_price_cents,tax_rate_bps,amount_cents,tax_cents,foreign_unit_price_cents,foreign_amount_cents,foreign_tax_cents) VALUES(?,?,?,?,1000,?,?,?,?, ?,?,?)')
            ->execute([new_id('iline'),$debitDocumentId,$income,'Debit note · Original '.(string)$source['number'],$carrying-(int)$note['tax_cents'],$rateBps,$carrying-(int)$note['tax_cents'],(int)$note['tax_cents'],(int)$note['foreign_subtotal_cents'],(int)$note['foreign_subtotal_cents'],(int)$note['foreign_tax_cents']]);
    }elseif(!$leaveOpen){
        $table=$spec['source']==='invoice'?'invoices':'bills';$open=$spec['source']==='invoice'?'sent':'open';
        $remaining=(int)$source['balance_cents']-$carrying;$foreignRemaining=(int)$source['foreign_balance_cents']-(int)$note['foreign_total_cents'];
        $status=$remaining===0?'paid':$open;
        $update=db()->prepare("UPDATE `$table` SET balance_cents=?,foreign_balance_cents=?,status=? WHERE company_id=? AND id=? AND status=? AND balance_cents>=? AND foreign_balance_cents>=?");
        $update->execute([$remaining,$foreignRemaining,$status,$companyId,$source['id'],$open,$carrying,$note['foreign_total_cents']]);
        if($update->rowCount()!==1)fail('The original balance changed before posting.',409,'note_balance_conflict');
    }
    db()->prepare("UPDATE accounting_notes SET status='posted',journal_entry_id=?,applied_cents=?,debit_document_id=? WHERE id=? AND company_id=? AND status='draft'")
        ->execute([$journal,($increase||$leaveOpen)?0:$carrying,$debitDocumentId,$note['id'],$companyId]);
    if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,'accounting_note',(string)$note['id'],$journal);
    audit_event($user,$companyId,'accounting_note.posted','accounting_note',(string)$note['id'],['kind'=>$note['note_kind'],'number'=>$note['number'],'sourceId'=>$source['id'],'journalEntryId'=>$journal,'debitDocumentId'=>$debitDocumentId,'settlementMode'=>$leaveOpen?'open':'original','appliedCents'=>($increase||$leaveOpen)?0:$carrying]);
    return ['id'=>(string)$note['id'],'status'=>'posted','journalEntryId'=>$journal,'debitDocumentId'=>$debitDocumentId,'appliedCents'=>($increase||$leaveOpen)?0:$carrying];
}

function note_apply(array $user,array $company,array $note,array $input): array
{
    $companyId=(string)$company['id'];$spec=note_kind_spec((string)$note['note_kind']);
    require_company_permission($company,$spec['permission']);
    if($spec['direction']!=='decrease'||(string)$note['status']!=='posted')
        fail('Choose a posted reducing note with an open credit.',409,'note_not_applicable');
    $documentId=clean_text($input['documentId']??'','Invoice or bill',64);
    $date=safe_date($input['applicationDate']??'','Application date');
    assert_not_future_date($date,'Application date');assert_period_open($companyId,$date);
    if($date<(string)$note['note_date'])fail('Application date cannot precede the note.',409,'note_application_date_invalid');
    note_assert_settlement_date_order($companyId,(string)$note['id'],$date);
    $foreign=safe_cents($input['foreignAmountCents']??0,'Application amount');
    if($foreign<=0)fail('Enter a positive amount to apply.',422,'note_application_amount_invalid');
    $key=clean_text($input['operationKey']??'','Operation key',120);
    $prior=db()->prepare('SELECT * FROM accounting_note_settlements WHERE company_id=? AND operation_key=? FOR UPDATE');
    $prior->execute([$companyId,$key]);$existing=$prior->fetch();
    if($existing){
        if((string)$existing['created_by']!==(string)$user['id']||(string)$existing['note_id']!==(string)$note['id']
            ||(string)$existing['settlement_kind']!=='application'||(string)$existing['document_id']!==$documentId
            ||(string)$existing['settlement_date']!==$date||(int)$existing['foreign_amount_cents']!==$foreign)
            fail('This operation key belongs to another note settlement.',409,'note_operation_key_conflict');
        return ['id'=>(string)$existing['id'],'noteId'=>(string)$note['id'],'status'=>'posted','idempotent'=>true];
    }
    $remaining=note_remaining_amounts($companyId,$note);
    if($foreign>$remaining['foreignRemainingCents']||$remaining['remainingCents']<=0)
        fail('The note has insufficient credit remaining.',409,'note_balance_insufficient');
    $document=note_source($companyId,$spec['source'],$documentId);
    $partyKey=$spec['source']==='invoice'?'customer_id':'vendor_id';
    $open=$spec['source']==='invoice'?'sent':'open';
    $documentDate=(string)$document[$spec['source']==='invoice'?'issue_date':'bill_date'];
    if((string)$document[$partyKey]!==(string)$note['party_id']||(string)$document['status']!==$open
        ||!empty($document['is_opening_document'])||(string)$document['currency']!==(string)$note['currency']
        ||(int)$document['exchange_rate_micros']!==(int)$note['exchange_rate_micros']
        ||$date<$documentDate)
        fail('Choose an open document for the same party, currency and exchange rate.',409,'note_target_ineligible');
    if($foreign>(int)$document['foreign_balance_cents'])fail('Application exceeds the document balance.',409,'note_target_balance_insufficient');
    $base=convert_to_base_cents($foreign,(int)$note['exchange_rate_micros']);
    if($foreign===$remaining['foreignRemainingCents'])$base=$remaining['remainingCents'];
    if($foreign===(int)$document['foreign_balance_cents']&&$base!==(int)$document['balance_cents'])
        fail('The final carrying values differ. Review the exchange allocation before applying.',409,'note_fx_difference_review');
    if($base<=0||$base>$remaining['remainingCents']||$base>(int)$document['balance_cents'])
        fail('The note and document carrying values do not reconcile.',409,'note_carrying_conflict');
    $table=$spec['source']==='invoice'?'invoices':'bills';
    $newBase=(int)$document['balance_cents']-$base;$newForeign=(int)$document['foreign_balance_cents']-$foreign;
    if(($newBase===0)!==($newForeign===0))fail('The document would have an incomplete currency balance.',409,'note_fx_difference_review');
    $status=$newBase===0?'paid':$open;
    $update=db()->prepare("UPDATE `$table` SET balance_cents=?,foreign_balance_cents=?,status=? WHERE id=? AND company_id=? AND status=? AND balance_cents>=? AND foreign_balance_cents>=?");
    $update->execute([$newBase,$newForeign,$status,$documentId,$companyId,$open,$base,$foreign]);
    if($update->rowCount()!==1)fail('The document changed before application.',409,'note_target_conflict');
    $id=new_id('noteapply');
    db()->prepare("INSERT INTO accounting_note_settlements(id,company_id,note_id,settlement_kind,document_id,settlement_date,foreign_amount_cents,amount_cents,operation_key,created_by) VALUES(?,?,?,'application',?,?,?,?,?,?)")
        ->execute([$id,$companyId,$note['id'],$documentId,$date,$foreign,$base,$key,$user['id']]);
    db()->prepare("UPDATE accounting_notes SET applied_cents=applied_cents+? WHERE id=? AND company_id=? AND status='posted'")
        ->execute([$base,$note['id'],$companyId]);
    audit_event($user,$companyId,'accounting_note.applied','accounting_note',(string)$note['id'],['settlementId'=>$id,'documentId'=>$documentId,'date'=>$date,'foreignAmountCents'=>$foreign,'amountCents'=>$base]);
    return ['id'=>$id,'noteId'=>(string)$note['id'],'status'=>'posted','documentId'=>$documentId,'foreignAmountCents'=>$foreign,'amountCents'=>$base,'remainingCents'=>$remaining['remainingCents']-$base];
}

function note_unapply(array $user,array $company,array $note,array $input): array
{
    $companyId=(string)$company['id'];$spec=note_kind_spec((string)$note['note_kind']);
    require_company_permission($company,$spec['permission']);
    if($spec['direction']!=='decrease'||(string)$note['status']!=='posted')fail('Choose a posted reducing note.',409,'note_not_applicable');
    $id=clean_text($input['settlementId']??'','Application',64);
    $date=safe_date($input['reversalDate']??'','Reversal date');assert_not_future_date($date,'Reversal date');assert_period_open($companyId,$date);
    $q=db()->prepare("SELECT * FROM accounting_note_settlements WHERE company_id=? AND id=? AND note_id=? AND settlement_kind='application' FOR UPDATE");
    $q->execute([$companyId,$id,$note['id']]);$settlement=$q->fetch();
    if(!$settlement)fail('The note application is unavailable.',404,'note_application_missing');
    if((string)$settlement['status']==='reversed'){
        if((string)$settlement['reversal_date']!==$date)fail('This application was reversed on another date.',409,'note_application_already_reversed');
        return ['id'=>$id,'noteId'=>$note['id'],'status'=>'reversed','idempotent'=>true];
    }
    if($date<(string)$settlement['settlement_date'])fail('Reversal date cannot precede application.',409,'note_application_reversal_date_invalid');
    note_assert_settlement_date_order($companyId,(string)$note['id'],$date);
    $document=note_source($companyId,$spec['source'],(string)$settlement['document_id']);
    $open=$spec['source']==='invoice'?'sent':'open';$table=$spec['source']==='invoice'?'invoices':'bills';
    if(!in_array((string)$document['status'],[$open,'paid'],true))fail('The target document is unavailable for reversal.',409,'note_target_unavailable');
    $base=(int)$document['balance_cents']+(int)$settlement['amount_cents'];
    $foreign=(int)$document['foreign_balance_cents']+(int)$settlement['foreign_amount_cents'];
    if($base>(int)$document['total_cents']||$foreign>(int)$document['foreign_total_cents'])
        fail('The target balance cannot safely restore this application.',409,'note_application_restore_conflict');
    db()->prepare("UPDATE `$table` SET balance_cents=?,foreign_balance_cents=?,status=? WHERE company_id=? AND id=?")
        ->execute([$base,$foreign,$open,$companyId,$document['id']]);
    db()->prepare("UPDATE accounting_note_settlements SET status='reversed',reversal_date=? WHERE company_id=? AND id=? AND status='posted'")
        ->execute([$date,$companyId,$id]);
    $changed=db()->prepare("UPDATE accounting_notes SET applied_cents=applied_cents-? WHERE company_id=? AND id=? AND applied_cents>=?");
    $changed->execute([(int)$settlement['amount_cents'],$companyId,$note['id'],(int)$settlement['amount_cents']]);
    if($changed->rowCount()!==1)fail('The note applied amount changed before reversal.',409,'note_application_restore_conflict');
    audit_event($user,$companyId,'accounting_note.application_reversed','accounting_note',(string)$note['id'],['settlementId'=>$id,'documentId'=>$document['id'],'reversalDate'=>$date,'amountCents'=>(int)$settlement['amount_cents']]);
    return ['id'=>$id,'noteId'=>$note['id'],'status'=>'reversed','documentId'=>$document['id']];
}

function note_void(array $user,array $company,array $note,array $input): array
{
    $companyId=(string)$company['id'];$spec=note_kind_spec((string)$note['note_kind']);require_company_role($company,'owner');
    if((string)$note['status']==='void')fail('This note has already been voided.',409,'note_already_void');
    if((string)$note['status']==='draft'){
        db()->prepare("UPDATE accounting_notes SET status='void' WHERE id=? AND company_id=? AND status='draft'")->execute([$note['id'],$companyId]);
        if(function_exists('voucher_mark_void'))voucher_mark_void($user,$companyId,'accounting_note',(string)$note['id']);
        audit_event($user,$companyId,'accounting_note.draft_voided','accounting_note',(string)$note['id'],[]);return ['id'=>$note['id'],'status'=>'void'];
    }
    $voidDate=safe_date($input['voidDate']??canadian_today(),'Void date');assert_not_future_date($voidDate,'Void date');
    if($voidDate<(string)$note['note_date'])fail('Void date cannot precede the note date.');assert_period_open($companyId,$voidDate);
    note_assert_settlement_date_order($companyId,(string)$note['id'],$voidDate);
    $amounts=note_remaining_amounts($companyId,$note);
    if($amounts['settlementCount']>0)fail('This note has applications or refunds. Reverse those settlements before voiding the note.',409,'note_has_settlements');
    $source=note_source($companyId,$spec['source'],(string)$note['source_id']);$increase=$spec['direction']==='increase';
    if($increase){
        $id=(string)$note['debit_document_id'];$document=note_source($companyId,'invoice',$id);
        if((string)$document['status']!=='sent'||(int)$document['balance_cents']!==(int)$document['total_cents']||(int)$document['foreign_balance_cents']!==(int)$document['foreign_total_cents'])fail('Reverse the debit note payment before voiding it.',409,'note_has_payments');
        db()->prepare("UPDATE invoices SET status='void',balance_cents=0,foreign_balance_cents=0 WHERE id=? AND company_id=? AND status='sent'")->execute([$id,$companyId]);
    }elseif((int)$note['applied_cents']>0){
        $table=$spec['source']==='invoice'?'invoices':'bills';$open=$spec['source']==='invoice'?'sent':'open';
        if(!in_array((string)$source['status'],['paid',$open],true))fail('The original invoice is unavailable for note reversal.',409,'note_source_unavailable');
        $new=(int)$source['balance_cents']+(int)$note['applied_cents'];$foreign=(int)$source['foreign_balance_cents']+(int)$note['foreign_total_cents'];
        if($new>(int)$source['total_cents']||$foreign>(int)$source['foreign_total_cents'])fail('The note allocation cannot be restored safely.',409,'note_restore_conflict');
        db()->prepare("UPDATE `$table` SET balance_cents=?,foreign_balance_cents=?,status=? WHERE id=? AND company_id=?")
            ->execute([$new,$foreign,$open,$source['id'],$companyId]);
    }
    if(!empty($note['journal_entry_id']))require_journal_unmatched_before_reversal($companyId,(string)$note['journal_entry_id'],'note');
    $reversal=add_reversing_journal_entry($user,$companyId,(string)$note['journal_entry_id'],$voidDate,'accounting_note_void',(string)$note['id'],'Void note '.(string)$note['number']);
    db()->prepare("UPDATE accounting_notes SET status='void',void_date=?,reversal_journal_entry_id=? WHERE id=? AND company_id=? AND status='posted'")->execute([$voidDate,$reversal,$note['id'],$companyId]);
    if(function_exists('voucher_mark_void'))voucher_mark_void($user,$companyId,'accounting_note',(string)$note['id']);
    audit_event($user,$companyId,'accounting_note.voided','accounting_note',(string)$note['id'],['reversalJournalEntryId'=>$reversal,'voidDate'=>$voidDate]);
    return ['id'=>$note['id'],'status'=>'void','reversalJournalEntryId'=>$reversal];
}

/* R120: optional itemized note lines (product returns, price adjustments).
   The note's accounting still comes from its subtotal and the original
   document's tax proportion; lines are the itemized source of that subtotal.
   The table is additive and created on first use, outside any transaction. */
function note_lines_ready(): bool
{
    static $ready=null;if($ready!==null)return $ready;
    try{
        if(!schema_table_exists('accounting_note_lines')){
            db()->exec("CREATE TABLE IF NOT EXISTS accounting_note_lines (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 company_id VARCHAR(64) NOT NULL,
 note_id VARCHAR(64) NOT NULL,
 source_line_id VARCHAR(64) NULL,
 product_service_id VARCHAR(64) NULL,
 description VARCHAR(500) NOT NULL,
 quantity_milli BIGINT NOT NULL,
 foreign_unit_price_cents BIGINT NOT NULL,
 foreign_amount_cents BIGINT NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY accounting_note_lines_note_idx (company_id,note_id,sort_order),
 KEY accounting_note_lines_source_idx (company_id,source_line_id),
 CONSTRAINT accounting_note_lines_note_fk FOREIGN KEY (note_id) REFERENCES accounting_notes(id) ON DELETE CASCADE,
 CONSTRAINT accounting_note_lines_amount_ck CHECK (quantity_milli > 0 AND foreign_unit_price_cents >= 0 AND foreign_amount_cents >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $ready=schema_table_exists('accounting_note_lines');
    }catch(Throwable $e){$ready=false;}
    return $ready;
}

/** Validate submitted lines against the original document; returns clean lines or [] when none were sent. */
function note_prepare_lines(string $companyId,array $values,mixed $raw): array
{
    if($raw===null||$raw===[])return [];
    if(!is_array($raw)||!array_is_list($raw)||count($raw)>100)fail('Send between 1 and 100 note lines.',422,'note_lines_invalid');
    if(!note_lines_ready())fail('Itemized note lines are unavailable on this database. Enter the subtotal only.',503,'note_lines_unavailable');
    $source=$values['source'];$isInvoice=$values['spec']['source']==='invoice';$reducing=$values['spec']['direction']!=='increase';
    $originals=[];
    if($isInvoice){$q=db()->prepare('SELECT id,quantity_milli,product_service_id FROM invoice_lines WHERE invoice_id=?');$q->execute([(string)$source['id']]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$originals[(string)$row['id']]=$row;}
    else $originals[(string)$source['id']]=['id'=>(string)$source['id'],'quantity_milli'=>(int)($source['quantity_milli']??1000),'product_service_id'=>$source['product_service_id']??null];
    $lines=[];$sum=0;$wanted=[];
    foreach($raw as $i=>$line){
        if(!is_array($line))fail('Each note line must be an object.',422,'note_lines_invalid');
        $description=trim((string)($line['description']??''));if($description===''||mb_strlen($description)>500)fail('Each note line needs a description of up to 500 characters.',422,'note_line_description');
        $qty=filter_var($line['quantityMilli']??null,FILTER_VALIDATE_INT);$unit=filter_var($line['foreignUnitPriceCents']??null,FILTER_VALIDATE_INT);
        if($qty===false||$qty<=0||$qty>1000000000)fail('Each note line needs a positive quantity.',422,'note_line_quantity');
        if($unit===false||$unit<0||$unit>100000000000)fail('Each note line needs a unit price of zero or more.',422,'note_line_price');
        $amount=(int)round($qty*$unit/1000);$sum+=$amount;
        $sourceLine=trim((string)($line['sourceLineId']??''));
        if($sourceLine!==''){
            if(!isset($originals[$sourceLine]))fail('A returned line does not belong to the original document.',422,'note_line_source');
            $wanted[$sourceLine]=($wanted[$sourceLine]??0)+$qty;
        }
        $product=trim((string)($line['productServiceId']??''))?:($sourceLine!==''?($originals[$sourceLine]['product_service_id']??null):null);
        $lines[]=['description'=>$description,'quantityMilli'=>$qty,'foreignUnitPriceCents'=>$unit,'foreignAmountCents'=>$amount,'sourceLineId'=>$sourceLine?:null,'productServiceId'=>$product?:null,'sortOrder'=>$i];
    }
    if($sum!==(int)$values['net'])fail('The note subtotal must equal the sum of its lines.',422,'note_lines_total_mismatch');
    if($reducing&&$wanted){
        // Returned quantities cannot exceed what the original document sold,
        // counting every other draft or posted note against the same line.
        $marks=implode(',',array_fill(0,count($wanted),'?'));
        $q=db()->prepare("SELECT l.source_line_id,COALESCE(SUM(l.quantity_milli),0) qty FROM accounting_note_lines l JOIN accounting_notes n ON n.id=l.note_id AND n.company_id=l.company_id WHERE l.company_id=? AND n.status<>'void' AND n.note_kind IN (?,?) AND l.source_line_id IN ($marks) GROUP BY l.source_line_id");
        $reducingKinds=$isInvoice?['customer_credit','customer_credit']:['vendor_credit','vendor_debit'];
        $q->execute(array_merge([$companyId],$reducingKinds,array_keys($wanted)));$prior=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$prior[(string)$row['source_line_id']]=(int)$row['qty'];
        foreach($wanted as $id=>$qty)if($qty+($prior[$id]??0)>(int)$originals[$id]['quantity_milli'])fail('A returned quantity is more than the original document still has available to return.',422,'note_line_quantity_exceeded');
    }
    return $lines;
}

function note_store_lines(string $companyId,string $noteId,array $lines): void
{
    if(!$lines)return;
    $s=db()->prepare('INSERT INTO accounting_note_lines(id,company_id,note_id,source_line_id,product_service_id,description,quantity_milli,foreign_unit_price_cents,foreign_amount_cents,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach($lines as $l)$s->execute([new_id('nline'),$companyId,$noteId,$l['sourceLineId'],$l['productServiceId'],$l['description'],$l['quantityMilli'],$l['foreignUnitPriceCents'],$l['foreignAmountCents'],$l['sortOrder']]);
}

function handle_accounting_notes(): never
{
    require_method('GET','POST','PATCH');$user=require_user();$company=require_company($user);$companyId=(string)$company['id'];tegh_notes_r67_require();
    note_lines_ready();
    if(request_method()==='GET'){
        $sourceId=trim((string)($_GET['sourceId']??''));$where='company_id=?';$params=[$companyId];
        if($sourceId!==''){$where.=' AND source_id=?';$params[]=clean_text($sourceId,'Original invoice',64);}
        $q=db()->prepare("SELECT * FROM accounting_notes WHERE $where ORDER BY note_date DESC,created_at DESC LIMIT 500");$q->execute($params);
        $rows=$q->fetchAll();$totals=[];$settlements=[];
        if($rows){
            $ids=array_column($rows,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
            $sum=db()->prepare("SELECT note_id,settlement_kind,SUM(amount_cents) base_total,SUM(foreign_amount_cents) foreign_total FROM accounting_note_settlements WHERE company_id=? AND status='posted' AND note_id IN ($marks) GROUP BY note_id,settlement_kind");
            $sum->execute(array_merge([$companyId],$ids));
            foreach($sum->fetchAll() as $s)$totals[(string)$s['note_id']][(string)$s['settlement_kind']]=$s;
            $detailId=trim((string)($_GET['noteId']??''));
            if($detailId!==''){
                $detailId=clean_text($detailId,'Note',64);
                if(in_array($detailId,$ids,true)){
                    $detail=db()->prepare('SELECT id,note_id,settlement_kind,document_id,payment_id,settlement_date,foreign_amount_cents,amount_cents,status,reversal_date FROM accounting_note_settlements WHERE company_id=? AND note_id=? ORDER BY settlement_date,id LIMIT 500');
                    $detail->execute([$companyId,$detailId]);$settlements=$detail->fetchAll();
                }
            }
        }
        $notes=array_map(static function(array $row)use($totals):array{
            $parts=$totals[(string)$row['id']]??[];
            $applied=(int)$row['applied_cents'];
            $foreignApplied=isset($parts['application'])?(int)$parts['application']['foreign_total']:($applied>0?(int)$row['foreign_total_cents']:0);
            $refunded=(int)($parts['refund']['base_total']??0);$foreignRefunded=(int)($parts['refund']['foreign_total']??0);
            $legacy=$applied>0&&!isset($parts['application']);
            return ['id'=>$row['id'],'kind'=>$row['note_kind'],'number'=>$row['number'],'sourceType'=>$row['source_type'],'sourceId'=>$row['source_id'],'partyId'=>$row['party_id'],'date'=>$row['note_date'],'status'=>$row['status'],'currency'=>$row['currency'],'exchangeRateMicros'=>(int)$row['exchange_rate_micros'],'foreignSubtotalCents'=>(int)$row['foreign_subtotal_cents'],'foreignTaxCents'=>(int)$row['foreign_tax_cents'],'foreignTotalCents'=>(int)$row['foreign_total_cents'],'subtotalCents'=>(int)$row['subtotal_cents'],'taxCents'=>(int)$row['tax_cents'],'totalCents'=>(int)$row['total_cents'],'appliedCents'=>$applied,'foreignAppliedCents'=>$foreignApplied,'refundedCents'=>$refunded,'foreignRefundedCents'=>$foreignRefunded,'remainingCents'=>max(0,($legacy?$applied:(int)$row['total_cents'])-$applied-$refunded),'foreignRemainingCents'=>max(0,(int)$row['foreign_total_cents']-$foreignApplied-$foreignRefunded),'debitDocumentId'=>$row['debit_document_id'],'journalEntryId'=>$row['journal_entry_id'],'memo'=>$row['memo']];
        },$rows);
        if($notes&&note_lines_ready()){
            $ids=array_column($notes,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
            $q=db()->prepare("SELECT note_id,source_line_id,product_service_id,description,quantity_milli,foreign_unit_price_cents,foreign_amount_cents FROM accounting_note_lines WHERE company_id=? AND note_id IN ($marks) ORDER BY note_id,sort_order");
            $q->execute(array_merge([$companyId],$ids));$byNote=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $l)$byNote[(string)$l['note_id']][]=['sourceLineId'=>$l['source_line_id'],'productServiceId'=>$l['product_service_id'],'description'=>$l['description'],'quantityMilli'=>(int)$l['quantity_milli'],'foreignUnitPriceCents'=>(int)$l['foreign_unit_price_cents'],'foreignAmountCents'=>(int)$l['foreign_amount_cents']];
            foreach($notes as &$n)$n['lines']=$byNote[(string)$n['id']]??[];unset($n);
        }
        json_response(['notes'=>$notes,'settlements'=>$settlements,'settlementReady'=>true,'linesReady'=>note_lines_ready()]);
    }
    require_csrf();require_company_role($company,'owner','bookkeeper');$input=request_json();
    if(request_method()==='POST'){
        $result=db_transaction_retry(static function()use($user,$company,$input,$companyId):array{
            $company=tegh_bank_reauthorize_mutation($user,$company,'');
            require_company_permission($company,note_kind_spec((string)($input['kind']??''))['permission']);
            $operationKey=clean_text($input['operationKey']??'','Operation key',120);
            $payloadHash=hash('sha256',json_encode(['companyId'=>$companyId,'actorId'=>$user['id'],'kind'=>$input['kind']??null,'sourceId'=>$input['sourceId']??null,'date'=>$input['date']??null,'subtotalCents'=>$input['subtotalCents']??null,'taxCents'=>$input['taxCents']??null,'memo'=>$input['memo']??null]+(empty($input['lines'])?[]:['lines'=>$input['lines']]),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $prior=db()->prepare('SELECT id,number,status,payload_hash,created_by FROM accounting_notes WHERE company_id=? AND operation_key=? FOR UPDATE');
            $prior->execute([$companyId,$operationKey]);$previous=$prior->fetch();
            if($previous){
                if(!hash_equals((string)$previous['payload_hash'],$payloadHash)||(string)$previous['created_by']!==(string)$user['id'])fail('The operation key was already used for a different note.',409,'note_operation_key_conflict');
                return ['id'=>$previous['id'],'number'=>$previous['number'],'status'=>$previous['status'],'idempotent'=>true];
            }
            $values=note_prepare($company,$input);$lines=note_prepare_lines($companyId,$values,$input['lines']??null);$kind=$values['kind'];$spec=$values['spec'];$source=$values['source'];$number=note_reserve_number($companyId,$kind,$spec['prefix']);$id=new_id('note');
            db()->prepare("INSERT INTO accounting_notes(id,company_id,source_type,source_id,party_id,note_kind,number,note_date,status,currency,exchange_rate_micros,foreign_subtotal_cents,foreign_tax_cents,foreign_total_cents,subtotal_cents,tax_cents,total_cents,memo,operation_key,payload_hash,created_by) VALUES(?,?,?,?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$id,$companyId,$spec['source'],$source['id'],$source[$spec['source']==='invoice'?'customer_id':'vendor_id'],$kind,$number,$values['date'],$source['currency'],$values['rate'],$values['net'],$values['tax'],$values['foreignTotal'],$values['baseNet'],$values['baseTax'],$values['total'],$values['memo'],$operationKey,$payloadHash,$user['id']]);
            note_store_lines($companyId,$id,$lines);
            if(function_exists('voucher_register_saved'))voucher_register_saved($user,$companyId,$spec['prefix'],$spec['source']==='invoice'?'AR':'AP','accounting_note',$id,$values['date'],$number.' · '.(string)$source['number'],$values['total'],null,false);
            audit_event($user,$companyId,'accounting_note.created','accounting_note',$id,['kind'=>$kind,'number'=>$number,'sourceId'=>$source['id']]);
            return ['id'=>$id,'number'=>$number,'status'=>'draft'];
        });json_response(['note'=>$result],201);
    }
    $noteId=clean_text($input['noteId']??'','Note',64);$action=(string)($input['action']??'');if(!in_array($action,['post','apply','unapply','void'],true))fail('Choose post, apply, unapply or void for the note.');
    $result=db_transaction_retry(static function()use($user,$company,$companyId,$noteId,$action,$input):array{
        $company=tegh_bank_reauthorize_mutation($user,$company,'');
        $q=db()->prepare('SELECT * FROM accounting_notes WHERE id=? AND company_id=? FOR UPDATE');$q->execute([$noteId,$companyId]);$note=$q->fetch();
        if(!$note)fail('This note is unavailable.',404,'note_unavailable');
        if($action==='post'){
            $mode=(string)($input['settlementMode']??'original');
            if(!in_array($mode,['original','open'],true))fail('Choose how the note will be settled.',422,'note_settlement_mode_invalid');
            return note_issue($user,$company,$note,$mode==='open');
        }
        if($action==='apply')return note_apply($user,$company,$note,$input);
        if($action==='unapply')return note_unapply($user,$company,$note,$input);
        return note_void($user,$company,$note,$input);
    });json_response(['note'=>$result]);
}
