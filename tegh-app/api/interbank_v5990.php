<?php
declare(strict_types=1);

/**
 * Durable same-currency interbank transfer linkage.
 *
 * The first reviewed statement row posts the only journal. The opposite
 * statement row is evidence for the other side of that same journal and must
 * never create a second entry.
 */
function tegh_interbank_payload_hash(array $payload): string
{
    return hash('sha256',tegh_json_canonical($payload));
}

function tegh_interbank_journal_line(string $companyId,string $journalEntryId,string $ledgerAccountId,bool $lock=false): array
{
    $q=db()->prepare('SELECT jl.id,jl.account_id,jl.debit_cents,jl.credit_cents FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.id=? AND jl.account_id=? ORDER BY jl.id'.($lock?' FOR UPDATE':''));
    $q->execute([$companyId,$journalEntryId,$ledgerAccountId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)fail('The transfer journal does not contain one provable financial-account line.',409,'interbank_journal_line_unproven');
    return $rows[0];
}

function tegh_interbank_transfer_result(array $transfer,bool $replay=false): array
{
    $voucher='';
    if(!empty($transfer['journal_entry_id'])){
        $q=db()->prepare('SELECT voucher_number FROM vouchers WHERE company_id=? AND journal_entry_id=? ORDER BY CASE WHEN status=\'posted\' THEN 0 ELSE 1 END,serial_number LIMIT 1');
        $q->execute([(string)$transfer['company_id'],(string)$transfer['journal_entry_id']]);$voucher=(string)($q->fetchColumn()?:'');
    }
    return [
        'transferId'=>(string)$transfer['id'],
        'status'=>(string)$transfer['status'],
        'journalEntryId'=>(string)$transfer['journal_entry_id'],
        'sourceBankAccountId'=>(string)$transfer['source_bank_account_id'],
        'destinationBankAccountId'=>(string)$transfer['destination_bank_account_id'],
        'currency'=>(string)$transfer['currency'],
        'amountCents'=>(int)$transfer['amount_cents'],
        'publicVoucher'=>$voucher,
        'journalsCreated'=>0,
        'idempotentReplay'=>$replay,
    ];
}

function tegh_interbank_operation_replay(string $companyId,string $operationKey,string $type,string $hash,bool $lock=true): ?array
{
    $q=db()->prepare('SELECT * FROM interbank_transfer_operations WHERE company_id=? AND operation_key=?'.($lock?' FOR UPDATE':''));
    $q->execute([$companyId,$operationKey]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
    if((string)$row['operation_type']!==$type||!hash_equals((string)$row['payload_hash'],$hash))fail('This operation key belongs to different interbank instructions.',409,'idempotency_payload_conflict');
    if((string)$row['status']!=='completed')return ['_pendingOperation'=>$row];
    $result=json_decode((string)($row['result_json']??''),true);
    if(!is_array($result))throw new RuntimeException('Stored interbank operation evidence is malformed.');
    $result['idempotentReplay']=true;return $result;
}

function tegh_interbank_operation_create(array $user,string $companyId,string $operationKey,string $type,string $hash,?string $transferId=''): string
{
    $id=new_id('ibop');
    db()->prepare('INSERT INTO interbank_transfer_operations(id,company_id,transfer_id,initiated_by,operation_key,operation_type,payload_hash,status) VALUES(?,?,?,?,?,?,?,\'completed\')')
        ->execute([$id,$companyId,$transferId!==''?$transferId:null,(string)$user['id'],$operationKey,$type,$hash]);
    return $id;
}

function tegh_interbank_operation_finish(string $id,?string $transferId,array $result,string $status='completed'): array
{
    db()->prepare('UPDATE interbank_transfer_operations SET transfer_id=?,status=?,result_json=?,updated_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([$transferId,$status,json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
    return $result;
}

/** Prove that a posted journal contains exactly two financial-account lines. */
function tegh_interbank_journal_evidence(string $companyId,string $journalEntryId,string $currentBankId,?string $otherBankId=null,bool $lock=false): array
{
    $entryQ=db()->prepare("SELECT id,entry_date,memo,source_type,source_id,status FROM journal_entries WHERE company_id=? AND id=?".($lock?' FOR UPDATE':''));
    $entryQ->execute([$companyId,$journalEntryId]);$entry=$entryQ->fetch(PDO::FETCH_ASSOC);
    if(!$entry||(string)$entry['status']!=='posted')fail('Choose an available posted journal.',409,'interbank_journal_unavailable');
    $linesQ=db()->prepare('SELECT id,account_id,debit_cents,credit_cents FROM journal_lines WHERE journal_entry_id=? ORDER BY id'.($lock?' FOR UPDATE':''));
    $linesQ->execute([$journalEntryId]);$lines=$linesQ->fetchAll(PDO::FETCH_ASSOC);
    if(count($lines)!==2)fail('The adopted journal must contain exactly two financial-account lines.',409,'interbank_journal_shape_unproven');
    $accountIds=array_values(array_unique(array_map(static fn(array $r):string=>(string)$r['account_id'],$lines)));
    if(count($accountIds)!==2)fail('The adopted journal must move value between two different financial ledgers.',409,'interbank_journal_shape_unproven');
    $ph=implode(',',array_fill(0,count($accountIds),'?'));
    $banksQ=db()->prepare("SELECT ba.id,ba.currency,ba.ledger_account_id FROM bank_accounts ba JOIN accounts a ON a.id=ba.ledger_account_id AND a.company_id=ba.company_id WHERE ba.company_id=? AND ba.active=1 AND a.active=1 AND ba.ledger_account_id IN ($ph) ORDER BY ba.id".($lock?' FOR UPDATE':''));
    $banksQ->execute(array_merge([$companyId],$accountIds));$banks=$banksQ->fetchAll(PDO::FETCH_ASSOC);
    $byLedger=[];foreach($banks as $bank)$byLedger[(string)$bank['ledger_account_id']][]=$bank;
    $proved=[];foreach($lines as $line){$matches=$byLedger[(string)$line['account_id']]??[];if(count($matches)!==1)fail('Each journal line must identify one current-company financial account.',409,'interbank_financial_account_unproven');$proved[(string)$matches[0]['id']]=['bank'=>$matches[0],'line'=>$line];}
    if(count($proved)!==2||!isset($proved[$currentBankId]))fail('The journal does not contain the selected statement account.',409,'interbank_journal_account_mismatch');
    if($otherBankId!==null&&(!isset($proved[$otherBankId])||count($proved)!==2))fail('The journal does not contain the selected opposite financial account.',409,'interbank_journal_account_mismatch');
    $otherBankId=$otherBankId??(string)array_values(array_filter(array_keys($proved),static fn(string $id):bool=>$id!==$currentBankId))[0];
    return ['entry'=>$entry,'current'=>$proved[$currentBankId],'other'=>$proved[$otherBankId],'otherBankId'=>$otherBankId];
}

function tegh_interbank_assert_statement_unreconciled(string $companyId,string $bankTransactionId): void
{
    if(active_bank_match_for_bank_transaction($companyId,$bankTransactionId,true)!==null)fail('This statement evidence is matched in Bank Reconciliation. Unmatch it there first.',409,'bank_reconciliation_unmatch_required');
    if(schema_table_exists('reconciliation_items')){$q=db()->prepare('SELECT COUNT(*) FROM reconciliation_items ri JOIN reconciliations r ON r.id=ri.reconciliation_id WHERE r.company_id=? AND ri.bank_transaction_id=?');$q->execute([$companyId,$bankTransactionId]);if((int)$q->fetchColumn()>0)fail('This statement evidence belongs to a saved reconciliation. Reopen and remove it there first.',409,'saved_reconciliation_unmatch_required');}
}

/** Called inside the canonical posting transaction immediately after journal creation. */
function tegh_interbank_record_first_leg(array $user,array $company,array $transaction,array $decision,array $resolved,string $journalEntryId): array
{
    tegh_schema45_require();
    if(!db()->inTransaction())throw new LogicException('Interbank origin linkage requires the posting transaction.');
    $companyId=(string)$company['id'];$transactionId=(string)$transaction['id'];
    $operationKey=tegh_bank_operation_key($decision['transferOperationKey']??null);
    $foreignAmount=(int)$transaction['foreign_amount_cents'];
    if($foreignAmount===0)fail('A zero-value statement row cannot create an interbank transfer.',422,'interbank_amount_invalid');
    $currentBankId=(string)$transaction['bank_account_id'];$otherBankId=(string)$resolved['counterpartyBankAccountId'];
    $sourceBankId=$foreignAmount<0?$currentBankId:$otherBankId;
    $destinationBankId=$foreignAmount<0?$otherBankId:$currentBankId;
    $role=$foreignAmount<0?'source':'destination';
    $remarks=mb_substr(trim((string)($decision['remarks']??$transaction['remarks']??'')),0,500);
    $payload=[
        'companyId'=>$companyId,'transactionId'=>$transactionId,'sourceBankAccountId'=>$sourceBankId,
        'destinationBankAccountId'=>$destinationBankId,'currency'=>(string)$transaction['currency'],
        'amountCents'=>abs($foreignAmount),'journalEntryId'=>$journalEntryId,'remarks'=>$remarks,
        'distinctTransferReason'=>trim((string)($decision['distinctTransferReason']??'')),
    ];
    $hash=tegh_interbank_payload_hash($payload);
    $existingQ=db()->prepare('SELECT * FROM interbank_transfers WHERE company_id=? AND created_by=? AND operation_key=? FOR UPDATE');
    $existingQ->execute([$companyId,(string)$user['id'],$operationKey]);$existing=$existingQ->fetch(PDO::FETCH_ASSOC);
    if($existing){
        if(!hash_equals((string)$existing['payload_hash'],$hash))fail('This transfer operation key belongs to different transfer evidence.',409,'idempotency_payload_conflict');
        return tegh_interbank_transfer_result($existing,true)+['originBankTransactionId'=>$transactionId];
    }
    $line=tegh_interbank_journal_line($companyId,$journalEntryId,(string)$resolved['currentLedger'],true);
    $baseAmount=abs((int)$transaction['amount_cents']);
    $lineMovement=(int)$line['debit_cents']-(int)$line['credit_cents'];
    if(abs($lineMovement)!==$baseAmount||($foreignAmount<0&&$lineMovement>=0)||($foreignAmount>0&&$lineMovement<=0)){
        fail('The transfer journal direction or amount does not agree with the originating statement row.',409,'interbank_journal_amount_mismatch');
    }
    $transferId=new_id('ibtransfer');
    db()->prepare("INSERT INTO interbank_transfers(id,company_id,source_bank_account_id,destination_bank_account_id,currency,amount_cents,journal_entry_id,operation_key,payload_hash,remarks,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,'awaiting_counterpart',?)")
        ->execute([$transferId,$companyId,$sourceBankId,$destinationBankId,(string)$transaction['currency'],abs($foreignAmount),$journalEntryId,$operationKey,$hash,$remarks,(string)$user['id']]);
    db()->prepare("INSERT INTO interbank_transfer_legs(id,company_id,transfer_id,bank_transaction_id,bank_account_id,journal_line_id,link_kind,operation_key,payload_hash,leg_role,status,linked_by) VALUES(?,?,?,?,?,?,'posted_origin',?,?,?,'linked',?)")
        ->execute([new_id('ibleg'),$companyId,$transferId,$transactionId,$currentBankId,(string)$line['id'],$operationKey,$hash,$role,(string)$user['id']]);
    audit_event($user,$companyId,'interbank_transfer.origin_posted','interbank_transfer',$transferId,[
        'transactionId'=>$transactionId,'journalEntryId'=>$journalEntryId,'operationKey'=>$operationKey,
        'sourceBankAccountId'=>$sourceBankId,'destinationBankAccountId'=>$destinationBankId,
        'currency'=>(string)$transaction['currency'],'amountCents'=>abs($foreignAmount),'journalsCreated'=>1,
        'distinctTransferReason'=>trim((string)($decision['distinctTransferReason']??'')),
    ]);
    $voucherQ=db()->prepare("SELECT voucher_number FROM vouchers WHERE company_id=? AND source_type='bank_transaction' AND source_id=? ORDER BY serial_number LIMIT 1");$voucherQ->execute([$companyId,$transactionId]);
    return [
        'transferId'=>$transferId,'status'=>'awaiting_counterpart','journalEntryId'=>$journalEntryId,
        'sourceBankAccountId'=>$sourceBankId,'destinationBankAccountId'=>$destinationBankId,
        'currency'=>(string)$transaction['currency'],'amountCents'=>abs($foreignAmount),
        'publicVoucher'=>(string)($voucherQ->fetchColumn()?:''),
        'originBankTransactionId'=>$transactionId,'journalsCreated'=>1,'idempotentReplay'=>false,
    ];
}

function tegh_interbank_candidates(array $company,string $transactionId): array
{
    tegh_schema45_require();$companyId=(string)$company['id'];
    $txQ=db()->prepare("SELECT bt.id,bt.bank_account_id,bt.transaction_date,bt.description,bt.remarks,bt.currency,bt.foreign_amount_cents,bt.amount_cents FROM bank_transactions bt WHERE bt.company_id=? AND bt.id=? AND bt.status='pending' AND bt.journal_entry_id IS NULL LIMIT 1");
    $txQ->execute([$companyId,$transactionId]);$tx=$txQ->fetch(PDO::FETCH_ASSOC);
    if(!$tx)fail('This statement row is no longer available for transfer matching.',409,'transaction_unavailable');
    if(active_bank_match_for_bank_transaction($companyId,$transactionId)!==null)fail('This statement row is already matched in Bank Reconciliation.',409,'bank_transaction_already_recorded');
    $foreign=(int)$tx['foreign_amount_cents'];if($foreign===0)return ['transactionId'=>$transactionId,'ambiguous'=>false,'candidates'=>[]];
    $role=$foreign<0?'source':'destination';$accountColumn=$role==='source'?'source_bank_account_id':'destination_bank_account_id';
    $q=db()->prepare("SELECT t.*,origin.bank_transaction_id origin_transaction_id,obt.transaction_date origin_transaction_date,
      ABS(DATEDIFF(?,obt.transaction_date)) date_distance
      FROM interbank_transfers t JOIN interbank_transfer_legs origin ON origin.transfer_id=t.id AND origin.company_id=t.company_id AND origin.link_kind='posted_origin' AND origin.status='linked'
      JOIN bank_transactions obt ON obt.company_id=t.company_id AND obt.id=origin.bank_transaction_id
      WHERE t.company_id=? AND t.status='awaiting_counterpart' AND t.$accountColumn=? AND t.currency=? AND t.amount_cents=? AND ABS(obt.amount_cents)=?
        AND origin.bank_transaction_id<>?
      ORDER BY date_distance,t.created_at,t.id LIMIT 25");
    $q->execute([(string)$tx['transaction_date'],$companyId,(string)$tx['bank_account_id'],(string)$tx['currency'],abs($foreign),abs((int)$tx['amount_cents']),$transactionId]);
    $candidates=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$candidates[]=[
        'transferId'=>(string)$r['id'],'originTransactionId'=>(string)$r['origin_transaction_id'],
        'journalEntryId'=>(string)$r['journal_entry_id'],'sourceBankAccountId'=>(string)$r['source_bank_account_id'],
        'destinationBankAccountId'=>(string)$r['destination_bank_account_id'],'currency'=>(string)$r['currency'],
        'amountCents'=>(int)$r['amount_cents'],'dateDistanceDays'=>(int)$r['date_distance'],
        'remarks'=>(string)$r['remarks'],'status'=>(string)$r['status'],
    ];
    $journalCandidates=[];
    $journalQ=db()->prepare("SELECT DISTINCT je.id,je.entry_date FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN bank_accounts current_bank ON current_bank.company_id=je.company_id AND current_bank.ledger_account_id=jl.account_id AND current_bank.id=? WHERE je.company_id=? AND je.status='posted' AND (SELECT COUNT(*) FROM journal_lines all_lines WHERE all_lines.journal_entry_id=je.id)=2 AND NOT EXISTS(SELECT 1 FROM interbank_transfers existing WHERE existing.company_id=je.company_id AND existing.journal_entry_id=je.id) ORDER BY je.entry_date DESC,je.id LIMIT 100");
    $journalQ->execute([(string)$tx['bank_account_id'],$companyId]);
    foreach($journalQ->fetchAll(PDO::FETCH_ASSOC) as $journalRow){$journalId=(string)$journalRow['id'];
        try{$e=tegh_service_boundary(fn()=>tegh_interbank_journal_evidence($companyId,(string)$journalId,(string)$tx['bank_account_id']));}catch(TeghServiceFailure){continue;}
        $currentMovement=(int)$e['current']['line']['debit_cents']-(int)$e['current']['line']['credit_cents'];
        if(abs($currentMovement)!==abs((int)$tx['amount_cents'])||($foreign<0&&$currentMovement>=0)||($foreign>0&&$currentMovement<=0))continue;
        if((string)$e['current']['bank']['currency']!==(string)$tx['currency']||(string)$e['other']['bank']['currency']!==(string)$tx['currency'])continue;
        $sourceQ=db()->prepare("SELECT id,bank_account_id,currency,foreign_amount_cents,amount_cents,status FROM bank_transactions WHERE company_id=? AND journal_entry_id=? AND id<>? ORDER BY created_at,id LIMIT 2");$sourceQ->execute([$companyId,(string)$journalId,$transactionId]);$sourceRows=$sourceQ->fetchAll(PDO::FETCH_ASSOC);$source=count($sourceRows)===1?$sourceRows[0]:null;
        if((string)$tx['currency']!==(string)$company['currency']){
            if(!$source||(string)$source['status']!=='posted'||(string)$source['bank_account_id']!==(string)$e['otherBankId']||(string)$source['currency']!==(string)$tx['currency']||(int)$source['foreign_amount_cents']!==-$foreign||(int)$source['amount_cents']!==-(int)$tx['amount_cents'])continue;
        }
        $journalCandidates[]=['candidateKind'=>'journal','journalEntryId'=>(string)$journalId,'otherBankAccountId'=>(string)$e['otherBankId'],'entryDate'=>(string)$e['entry']['entry_date'],'memo'=>(string)$e['entry']['memo'],'sourceType'=>(string)$e['entry']['source_type'],'sourceTransactionId'=>$source?(string)$source['id']:null,'amountCents'=>abs($foreign),'currency'=>(string)$tx['currency'],'dateDistanceDays'=>abs((int)((new DateTimeImmutable((string)$tx['transaction_date']))->diff(new DateTimeImmutable((string)$e['entry']['entry_date']))->format('%r%a'))),];
    }
    foreach($candidates as &$candidate)$candidate['candidateKind']='transfer';unset($candidate);
    $pendingQ=db()->prepare("SELECT other.id,other.bank_account_id,other.transaction_date,other.description,other.reference,other.currency,other.foreign_amount_cents,other.amount_cents,ABS(DATEDIFF(?,other.transaction_date)) date_distance
      FROM bank_transactions other JOIN bank_accounts oba ON oba.company_id=other.company_id AND oba.id=other.bank_account_id AND oba.active=1
      WHERE other.company_id=? AND other.id<>? AND other.bank_account_id<>? AND other.status='pending' AND other.journal_entry_id IS NULL
        AND other.currency=? AND other.foreign_amount_cents=? AND other.amount_cents=?
        AND NOT EXISTS(SELECT 1 FROM bank_match_bank_items bmi JOIN bank_match_groups bmg ON bmg.id=bmi.match_group_id WHERE bmi.bank_transaction_id=other.id AND bmg.company_id=other.company_id AND bmg.status='matched')
      ORDER BY date_distance,other.transaction_date,other.id LIMIT 25");
    $pendingQ->execute([(string)$tx['transaction_date'],$companyId,$transactionId,(string)$tx['bank_account_id'],(string)$tx['currency'],-$foreign,-(int)$tx['amount_cents']]);
    $pendingCandidates=[];foreach($pendingQ->fetchAll(PDO::FETCH_ASSOC) as $r)$pendingCandidates[]=[
        'candidateKind'=>'pending_pair','counterpartTransactionId'=>(string)$r['id'],'otherBankAccountId'=>(string)$r['bank_account_id'],
        'transactionDate'=>(string)$r['transaction_date'],'description'=>(string)$r['description'],'reference'=>(string)($r['reference']??''),
        'currency'=>(string)$r['currency'],'amountCents'=>abs((int)$r['foreign_amount_cents']),'dateDistanceDays'=>(int)$r['date_distance'],
    ];
    $all=array_merge($pendingCandidates,$candidates,$journalCandidates);
    return ['transactionId'=>$transactionId,'requiredLegRole'=>$role,'candidateCount'=>count($all),'ambiguous'=>count($all)>1,'candidates'=>$all];
}

/** Atomically post one pending side and link the exact pending opposite side. */
function tegh_interbank_post_pair(array $user,array $company,array $input): array
{
    if(($input['confirmed']??false)!==true)fail('Confirm the two-row interbank transfer posting.',422,'confirmation_required');
    $companyId=(string)$company['id'];$transactionId=clean_text($input['transactionId']??'','Transaction',64);$counterpartId=clean_text($input['counterpartTransactionId']??'','Counterpart transaction',64);
    if($transactionId===$counterpartId)fail('Choose two different statement rows.',422,'interbank_pair_invalid');
    $decision=is_array($input['decision']??null)?$input['decision']:[];$decision['id']=$transactionId;
    $operationKey=tegh_bank_operation_key($input['operationKey']??null);$payload=['companyId'=>$companyId,'transactionId'=>$transactionId,'counterpartTransactionId'=>$counterpartId,'decision'=>$decision];$hash=tegh_interbank_payload_hash($payload);
    return tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$company,$companyId,$transactionId,$counterpartId,$decision,$operationKey,$hash){
        $fresh=tegh_bank_reauthorize_mutation($user,$company);$priorQ=db()->prepare('SELECT * FROM interbank_transfers WHERE company_id=? AND created_by=? AND operation_key=? FOR UPDATE');$priorQ->execute([$companyId,(string)$user['id'],$operationKey]);$prior=$priorQ->fetch(PDO::FETCH_ASSOC);
        if($prior){$legsQ=db()->prepare('SELECT bank_transaction_id,operation_key,payload_hash FROM interbank_transfer_legs WHERE company_id=? AND transfer_id=? AND status=\'linked\' ORDER BY bank_transaction_id FOR UPDATE');$legsQ->execute([$companyId,(string)$prior['id']]);$legs=$legsQ->fetchAll(PDO::FETCH_ASSOC);$actual=array_map(static fn(array $row):string=>(string)$row['bank_transaction_id'],$legs);$expected=[$transactionId,$counterpartId];sort($expected,SORT_STRING);$pairLeg=array_values(array_filter($legs,static fn(array $row):bool=>hash_equals((string)($row['payload_hash']??''),$hash)));if($actual!==$expected||count($pairLeg)!==1)fail('This operation key belongs to different interbank instructions.',409,'idempotency_payload_conflict');return tegh_interbank_transfer_result($prior,true)+['originBankTransactionId'=>$transactionId,'matchedBankTransactionId'=>$counterpartId,'operationKey'=>$operationKey,'journalsCreated'=>0,'rowsPosted'=>2];}
        $ids=[$transactionId,$counterpartId];sort($ids,SORT_STRING);$rows=[];
        foreach($ids as $id){$q=db()->prepare("SELECT bt.*,ba.ledger_account_id FROM bank_transactions bt JOIN bank_accounts ba ON ba.company_id=bt.company_id AND ba.id=bt.bank_account_id AND ba.active=1 WHERE bt.company_id=? AND bt.id=? AND bt.status='pending' AND bt.journal_entry_id IS NULL FOR UPDATE");$q->execute([$companyId,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)fail('One of the selected statement rows is no longer pending.',409,'transaction_unavailable');bank_transaction_assert_unrecorded($fresh,$row);$rows[$id]=$row;}
        $origin=$rows[$transactionId];$counterpart=$rows[$counterpartId];
        if((string)$origin['bank_account_id']===(string)$counterpart['bank_account_id']||(string)$origin['currency']!==(string)$counterpart['currency']||(int)$origin['foreign_amount_cents']!==-(int)$counterpart['foreign_amount_cents']||(int)$origin['amount_cents']!==-(int)$counterpart['amount_cents']||(int)$origin['foreign_amount_cents']===0)fail('The selected rows must be exact opposite amounts in the same currency on two different financial accounts.',409,'interbank_candidate_mismatch');
        if((string)($decision['transferBankAccountId']??'')!==(string)$counterpart['bank_account_id'])fail('The chosen opposite financial account does not match the selected statement row.',409,'interbank_candidate_mismatch');
        assert_period_open($companyId,(string)$origin['transaction_date']);assert_period_open($companyId,(string)$counterpart['transaction_date']);
        $decision['transferOperationKey']=$operationKey;$decision['pairCounterpartTransactionId']=$counterpartId;bank_transaction_post_service_once($user,$fresh,[$decision],'interbank_pair',false);
        $tQ=db()->prepare('SELECT * FROM interbank_transfers WHERE company_id=? AND created_by=? AND operation_key=? FOR UPDATE');$tQ->execute([$companyId,(string)$user['id'],$operationKey]);$transfer=$tQ->fetch(PDO::FETCH_ASSOC);if(!$transfer)throw new RuntimeException('The originating transfer relationship was not created.');
        $role=(int)$counterpart['foreign_amount_cents']<0?'source':'destination';$expectedBankId=$role==='source'?(string)$transfer['source_bank_account_id']:(string)$transfer['destination_bank_account_id'];
        if($expectedBankId!==(string)$counterpart['bank_account_id']||(string)$transfer['currency']!==(string)$counterpart['currency']||(int)$transfer['amount_cents']!==abs((int)$counterpart['foreign_amount_cents']))fail('The opposite statement row changed before posting.',409,'interbank_candidate_mismatch');
        $line=tegh_interbank_journal_line($companyId,(string)$transfer['journal_entry_id'],(string)$counterpart['ledger_account_id'],true);$movement=(int)$line['debit_cents']-(int)$line['credit_cents'];
        if(abs($movement)!==abs((int)$counterpart['amount_cents'])||((int)$counterpart['foreign_amount_cents']<0&&$movement>=0)||((int)$counterpart['foreign_amount_cents']>0&&$movement<=0))fail('The new transfer journal does not exactly match the opposite statement row.',409,'interbank_journal_amount_mismatch');
        $pairLegKey='pairlink_'.substr(hash('sha256',$operationKey),0,64);db()->prepare("INSERT INTO interbank_transfer_legs(id,company_id,transfer_id,bank_transaction_id,bank_account_id,journal_line_id,link_kind,operation_key,payload_hash,leg_role,status,linked_by) VALUES(?,?,?,?,?,?,'matched_counterpart',?,?,?,'linked',?)")
          ->execute([new_id('ibleg'),$companyId,(string)$transfer['id'],$counterpartId,(string)$counterpart['bank_account_id'],(string)$line['id'],$pairLegKey,$hash,$role,(string)$user['id']]);
        $otherBankId=$role==='source'?(string)$transfer['destination_bank_account_id']:(string)$transfer['source_bank_account_id'];$otherQ=db()->prepare('SELECT ledger_account_id FROM bank_accounts WHERE company_id=? AND id=? AND active=1 FOR UPDATE');$otherQ->execute([$companyId,$otherBankId]);$otherLedger=$otherQ->fetchColumn();if($otherLedger===false)fail('The opposite transfer account is unavailable.',409,'transfer_account_unavailable');
        $update=db()->prepare("UPDATE bank_transactions SET decided_account_id=?,tax_code='NO_TAX',suggestion_source='manual',status='posted',journal_entry_id=? WHERE company_id=? AND id=? AND status='pending' AND journal_entry_id IS NULL");$update->execute([(string)$otherLedger,(string)$transfer['journal_entry_id'],$companyId,$counterpartId]);if($update->rowCount()!==1)throw new RuntimeException('Pair update affected an unexpected statement row count.');
        if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,'bank_transaction',$counterpartId,(string)$transfer['journal_entry_id']);db()->prepare("UPDATE interbank_transfers SET status='matched',updated_at=UTC_TIMESTAMP() WHERE company_id=? AND id=? AND status='awaiting_counterpart'")->execute([$companyId,(string)$transfer['id']]);$transfer['status']='matched';
        $result=array_replace(tegh_interbank_transfer_result($transfer,false),['originBankTransactionId'=>$transactionId,'matchedBankTransactionId'=>$counterpartId,'operationKey'=>$operationKey,'journalsCreated'=>1,'rowsPosted'=>2]);audit_event($user,$companyId,'interbank_transfer.pending_pair_posted','interbank_transfer',(string)$transfer['id'],$result);return $result;
    },4));
}

function tegh_interbank_match(array $user,array $company,array $input): array
{
    if(($input['confirmed']??false)!==true)fail('Confirm the match-only transfer operation.',422,'confirmation_required');
    $transactionId=clean_text($input['transactionId']??'','Transaction',64);$transferId=clean_text($input['transferId']??'','Transfer',64);
    $operationKey=tegh_bank_operation_key($input['operationKey']??null);$companyId=(string)$company['id'];
    $payload=['companyId'=>$companyId,'transactionId'=>$transactionId,'transferId'=>$transferId];$hash=tegh_interbank_payload_hash($payload);
    return tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$company,$companyId,$transactionId,$transferId,$operationKey,$payload,$hash){
        $fresh=tegh_bank_reauthorize_mutation($user,$company);
        $opQ=db()->prepare('SELECT * FROM interbank_transfer_legs WHERE company_id=? AND linked_by=? AND operation_key=? FOR UPDATE');
        $opQ->execute([$companyId,(string)$user['id'],$operationKey]);$prior=$opQ->fetch(PDO::FETCH_ASSOC);
        if($prior){
            if(!hash_equals((string)($prior['payload_hash']??''),$hash))fail('This match operation key belongs to different transfer evidence.',409,'idempotency_payload_conflict');
            $tQ=db()->prepare('SELECT * FROM interbank_transfers WHERE company_id=? AND id=?');$tQ->execute([$companyId,(string)$prior['transfer_id']]);$stored=$tQ->fetch(PDO::FETCH_ASSOC);
            if(!$stored)fail('The matched transfer record is unavailable.',409,'interbank_transfer_unavailable');
            return tegh_interbank_transfer_result($stored,true)+['matchedBankTransactionId'=>(string)$prior['bank_transaction_id'],'operationKey'=>$operationKey];
        }
        $tQ=db()->prepare('SELECT * FROM interbank_transfers WHERE company_id=? AND id=? FOR UPDATE');$tQ->execute([$companyId,$transferId]);$transfer=$tQ->fetch(PDO::FETCH_ASSOC);
        if(!$transfer)fail('The transfer candidate is unavailable.',409,'interbank_transfer_unavailable');
        if((string)$transfer['status']!=='awaiting_counterpart')fail('The transfer candidate is no longer awaiting its opposite statement row.',409,'interbank_transfer_unavailable');
        $txQ=db()->prepare("SELECT bt.*,ba.ledger_account_id,ba.last_reconciled_date FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id WHERE bt.company_id=? AND bt.id=? AND bt.status='pending' FOR UPDATE");
        $txQ->execute([$companyId,$transactionId]);$tx=$txQ->fetch(PDO::FETCH_ASSOC);
        if(!$tx)fail('This statement row is no longer available for transfer matching.',409,'transaction_unavailable');
        bank_transaction_assert_unrecorded($fresh,$tx);assert_period_open($companyId,(string)$tx['transaction_date']);
        $foreign=(int)$tx['foreign_amount_cents'];$role=$foreign<0?'source':'destination';
        $expectedBankId=$role==='source'?(string)$transfer['source_bank_account_id']:(string)$transfer['destination_bank_account_id'];
        if($foreign===0||$expectedBankId!==(string)$tx['bank_account_id']||(string)$transfer['currency']!==(string)$tx['currency']||(int)$transfer['amount_cents']!==abs($foreign)){
            fail('The selected statement row does not exactly match the transfer account, direction, currency, and amount.',409,'interbank_candidate_mismatch');
        }
        $originQ=db()->prepare("SELECT * FROM interbank_transfer_legs WHERE company_id=? AND transfer_id=? AND link_kind='posted_origin' AND status='linked' FOR UPDATE");
        $originQ->execute([$companyId,$transferId]);$origins=$originQ->fetchAll(PDO::FETCH_ASSOC);
        if(count($origins)!==1)fail('The originating transfer evidence is incomplete.',409,'interbank_origin_unproven');
        $line=tegh_interbank_journal_line($companyId,(string)$transfer['journal_entry_id'],(string)$tx['ledger_account_id'],true);
        $baseAmount=abs((int)$tx['amount_cents']);$movement=(int)$line['debit_cents']-(int)$line['credit_cents'];
        if(abs($movement)!==$baseAmount||($foreign<0&&$movement>=0)||($foreign>0&&$movement<=0))fail('The existing transfer journal does not exactly match this statement row.',409,'interbank_journal_amount_mismatch');
        $otherBankId=$role==='source'?(string)$transfer['destination_bank_account_id']:(string)$transfer['source_bank_account_id'];
        $otherQ=db()->prepare('SELECT ledger_account_id FROM bank_accounts WHERE company_id=? AND id=? AND active=1 FOR UPDATE');$otherQ->execute([$companyId,$otherBankId]);$otherLedger=$otherQ->fetchColumn();
        if($otherLedger===false)fail('The opposite transfer account is unavailable.',409,'transfer_account_unavailable');
        $oldLeg=db()->prepare("SELECT id FROM interbank_transfer_legs WHERE company_id=? AND transfer_id=? AND bank_transaction_id=? AND status='unmatched' FOR UPDATE");$oldLeg->execute([$companyId,$transferId,$transactionId]);$oldLegId=$oldLeg->fetchColumn();
        if($oldLegId!==false)db()->prepare("UPDATE interbank_transfer_legs SET journal_line_id=?,link_kind='matched_counterpart',operation_key=?,payload_hash=?,leg_role=?,status='linked',linked_by=?,linked_at=UTC_TIMESTAMP(),unmatched_by=NULL,unmatched_at=NULL WHERE id=?")->execute([(string)$line['id'],$operationKey,$hash,$role,(string)$user['id'],(string)$oldLegId]);
        else db()->prepare("INSERT INTO interbank_transfer_legs(id,company_id,transfer_id,bank_transaction_id,bank_account_id,journal_line_id,link_kind,operation_key,payload_hash,leg_role,status,linked_by) VALUES(?,?,?,?,?,?,'matched_counterpart',?,?,?,'linked',?)")
            ->execute([new_id('ibleg'),$companyId,$transferId,$transactionId,(string)$tx['bank_account_id'],(string)$line['id'],$operationKey,$hash,$role,(string)$user['id']]);
        $update=db()->prepare("UPDATE bank_transactions SET decided_account_id=?,tax_code='NO_TAX',suggestion_source='manual',status='posted',journal_entry_id=? WHERE company_id=? AND id=? AND status='pending' AND journal_entry_id IS NULL");
        $update->execute([(string)$otherLedger,(string)$transfer['journal_entry_id'],$companyId,$transactionId]);
        if($update->rowCount()!==1)throw new RuntimeException('Match-only update affected an unexpected statement row count.');
        if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,'bank_transaction',$transactionId,(string)$transfer['journal_entry_id']);
        db()->prepare("UPDATE interbank_transfers SET status='matched',updated_at=UTC_TIMESTAMP() WHERE company_id=? AND id=? AND status='awaiting_counterpart'")->execute([$companyId,$transferId]);
        $transfer['status']='matched';
        audit_event($user,$companyId,'interbank_transfer.counterpart_matched','interbank_transfer',$transferId,[
            'transactionId'=>$transactionId,'originTransactionId'=>(string)$origins[0]['bank_transaction_id'],
            'journalEntryId'=>(string)$transfer['journal_entry_id'],'operationKey'=>$operationKey,
            'payloadHash'=>$hash,'journalsCreated'=>0,
        ]);
        return tegh_interbank_transfer_result($transfer,false)+['matchedBankTransactionId'=>$transactionId,'originBankTransactionId'=>(string)$origins[0]['bank_transaction_id'],'operationKey'=>$operationKey];
    },4));
}

function tegh_interbank_adopt(array $user,array $company,array $input): array
{
    if(($input['confirmed']??false)!==true)fail('Confirm that the selected posted journal should be adopted without reposting.',422,'confirmation_required');
    $companyId=(string)$company['id'];$transactionId=clean_text($input['transactionId']??'','Transaction',64);$journalId=clean_text($input['journalEntryId']??'','Journal',64);$otherBankId=clean_text($input['otherBankAccountId']??'','Financial account',64);$operationKey=tegh_bank_operation_key($input['operationKey']??null);
    $payload=['companyId'=>$companyId,'transactionId'=>$transactionId,'journalEntryId'=>$journalId,'otherBankAccountId'=>$otherBankId];$hash=tegh_interbank_payload_hash($payload);
    return tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$company,$companyId,$transactionId,$journalId,$otherBankId,$operationKey,$payload,$hash){
        $fresh=tegh_bank_reauthorize_mutation($user,$company);$prior=tegh_interbank_operation_replay($companyId,$operationKey,'adopt',$hash);if($prior!==null)return $prior;
        $txQ=db()->prepare("SELECT bt.*,ba.ledger_account_id FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id WHERE bt.company_id=? AND bt.id=? AND bt.status='pending' AND bt.journal_entry_id IS NULL FOR UPDATE");$txQ->execute([$companyId,$transactionId]);$tx=$txQ->fetch(PDO::FETCH_ASSOC);if(!$tx)fail('This statement row is no longer available for adoption.',409,'transaction_unavailable');bank_transaction_assert_unrecorded($fresh,$tx);assert_period_open($companyId,(string)$tx['transaction_date']);
        $e=tegh_interbank_journal_evidence($companyId,$journalId,(string)$tx['bank_account_id'],$otherBankId,true);$foreign=(int)$tx['foreign_amount_cents'];$movement=(int)$e['current']['line']['debit_cents']-(int)$e['current']['line']['credit_cents'];
        if($foreign===0||abs($movement)!==abs((int)$tx['amount_cents'])||($foreign<0&&$movement>=0)||($foreign>0&&$movement<=0)||(string)$e['current']['bank']['currency']!==(string)$tx['currency']||(string)$e['other']['bank']['currency']!==(string)$tx['currency'])fail('The selected journal does not exactly match the statement account, direction, currency and amount.',409,'interbank_candidate_mismatch');
        $existing=db()->prepare('SELECT id FROM interbank_transfers WHERE company_id=? AND journal_entry_id=? FOR UPDATE');$existing->execute([$companyId,$journalId]);if($existing->fetchColumn()!==false)fail('That journal already belongs to an interbank relationship.',409,'interbank_journal_already_adopted');
        $role=$foreign<0?'source':'destination';$sourceBankId=$role==='source'?(string)$tx['bank_account_id']:$otherBankId;$destinationBankId=$role==='destination'?(string)$tx['bank_account_id']:$otherBankId;
        $sourceQ=db()->prepare("SELECT * FROM bank_transactions WHERE company_id=? AND journal_entry_id=? AND id<>? ORDER BY created_at,id LIMIT 2 FOR UPDATE");$sourceQ->execute([$companyId,$journalId,$transactionId]);$sourceRows=$sourceQ->fetchAll(PDO::FETCH_ASSOC);$source=count($sourceRows)===1?$sourceRows[0]:null;
        if((string)$tx['currency']!==(string)$company['currency']&&(!$source||(string)$source['status']!=='posted'||(string)$source['bank_account_id']!==$otherBankId||(string)$source['currency']!==(string)$tx['currency']||(int)$source['foreign_amount_cents']!==-$foreign||(int)$source['amount_cents']!==-(int)$tx['amount_cents']))fail('Foreign-currency adoption requires the exact opposite posted statement evidence.',409,'interbank_foreign_evidence_required');
        $matched=$source&&(string)$source['status']==='posted'&&(string)$source['bank_account_id']===$otherBankId&&(string)$source['currency']===(string)$tx['currency']&&(int)$source['foreign_amount_cents']===-$foreign&&(int)$source['amount_cents']===-(int)$tx['amount_cents'];
        if($matched){tegh_interbank_assert_statement_unreconciled($companyId,(string)$source['id']);$used=db()->prepare('SELECT COUNT(*) FROM interbank_transfer_legs WHERE company_id=? AND bank_transaction_id=?');$used->execute([$companyId,(string)$source['id']]);if((int)$used->fetchColumn()>0)fail('The opposite statement evidence already belongs to another transfer.',409,'interbank_evidence_already_linked');}
        $transferId=new_id('ibtransfer');$remarks=mb_substr(trim((string)($input['remarks']??$tx['remarks']??$e['entry']['memo'])),0,500);
        db()->prepare("INSERT INTO interbank_transfers(id,company_id,source_bank_account_id,destination_bank_account_id,currency,amount_cents,journal_entry_id,operation_key,payload_hash,remarks,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$transferId,$companyId,$sourceBankId,$destinationBankId,(string)$tx['currency'],abs($foreign),$journalId,$operationKey,$hash,$remarks,$matched?'matched':'awaiting_counterpart',(string)$user['id']]);
        if($matched){$sourceRole=(int)$source['foreign_amount_cents']<0?'source':'destination';db()->prepare("INSERT INTO interbank_transfer_legs(id,company_id,transfer_id,bank_transaction_id,bank_account_id,journal_line_id,link_kind,leg_role,status,linked_by) VALUES(?,?,?,?,?,?,'posted_origin',?,'linked',?)")->execute([new_id('ibleg'),$companyId,$transferId,(string)$source['id'],$otherBankId,(string)$e['other']['line']['id'],$sourceRole,(string)$user['id']]);}
        db()->prepare("INSERT INTO interbank_transfer_legs(id,company_id,transfer_id,bank_transaction_id,bank_account_id,journal_line_id,link_kind,operation_key,payload_hash,leg_role,status,linked_by) VALUES(?,?,?,?,?,?,?,?,?,?,'linked',?)")->execute([new_id('ibleg'),$companyId,$transferId,$transactionId,(string)$tx['bank_account_id'],(string)$e['current']['line']['id'],$matched?'matched_counterpart':'posted_origin',$operationKey,$hash,$role,(string)$user['id']]);
        $update=db()->prepare("UPDATE bank_transactions SET decided_account_id=?,tax_code='NO_TAX',suggestion_source='manual',status='posted',journal_entry_id=? WHERE company_id=? AND id=? AND status='pending' AND journal_entry_id IS NULL");$update->execute([(string)$e['other']['bank']['ledger_account_id'],$journalId,$companyId,$transactionId]);if($update->rowCount()!==1)throw new RuntimeException('Adoption affected an unexpected statement row count.');if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,'bank_transaction',$transactionId,$journalId);
        $opId=tegh_interbank_operation_create($user,$companyId,$operationKey,'adopt',$hash,$transferId);$result=['transferId'=>$transferId,'status'=>$matched?'matched':'awaiting_counterpart','journalEntryId'=>$journalId,'adoptedBankTransactionId'=>$transactionId,'originBankTransactionId'=>$matched?(string)$source['id']:$transactionId,'journalsCreated'=>0,'operationKey'=>$operationKey,'idempotentReplay'=>false];
        audit_event($user,$companyId,'interbank_transfer.journal_adopted','interbank_transfer',$transferId,$result);return tegh_interbank_operation_finish($opId,$transferId,$result);
    },4));
}

function tegh_interbank_unmatch(array $user,array $company,array $input): array
{
    if(($input['confirmed']??false)!==true)fail('Confirm that only the statement-evidence link should be removed.',422,'confirmation_required');
    $companyId=(string)$company['id'];$transferId=clean_text($input['transferId']??'','Transfer',64);$transactionId=clean_text($input['transactionId']??'','Transaction',64);$operationKey=tegh_bank_operation_key($input['operationKey']??null);$payload=['companyId'=>$companyId,'transferId'=>$transferId,'transactionId'=>$transactionId];$hash=tegh_interbank_payload_hash($payload);
    return tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$company,$companyId,$transferId,$transactionId,$operationKey,$hash){
        tegh_bank_reauthorize_mutation($user,$company);$prior=tegh_interbank_operation_replay($companyId,$operationKey,'unmatch',$hash);if($prior!==null)return $prior;
        $tQ=db()->prepare("SELECT * FROM interbank_transfers WHERE company_id=? AND id=? AND status='matched' FOR UPDATE");$tQ->execute([$companyId,$transferId]);$transfer=$tQ->fetch(PDO::FETCH_ASSOC);if(!$transfer)fail('This matched transfer is no longer available.',409,'interbank_transfer_unavailable');
        $legQ=db()->prepare("SELECT * FROM interbank_transfer_legs WHERE company_id=? AND transfer_id=? AND bank_transaction_id=? AND link_kind='matched_counterpart' AND status='linked' FOR UPDATE");$legQ->execute([$companyId,$transferId,$transactionId]);$leg=$legQ->fetch(PDO::FETCH_ASSOC);if(!$leg)fail('Only a linked counterpart statement row can be unmatched.',409,'interbank_unmatch_unavailable');
        tegh_interbank_assert_statement_unreconciled($companyId,$transactionId);require_journal_unmatched_before_reversal($companyId,(string)$transfer['journal_entry_id'],'interbank transfer');
        $statementUpdate=db()->prepare("UPDATE bank_transactions SET status='pending',journal_entry_id=NULL,decided_account_id=NULL,tax_code='NO_TAX',suggestion_source='manual' WHERE company_id=? AND id=? AND journal_entry_id=? AND status='posted'");$statementUpdate->execute([$companyId,$transactionId,(string)$transfer['journal_entry_id']]);if($statementUpdate->rowCount()!==1)fail('The counterpart statement row changed before it could be unmatched.',409,'interbank_unmatch_state_changed');
        if(schema_table_exists('vouchers'))db()->prepare("UPDATE vouchers SET status='draft',journal_entry_id=NULL,posted_by=NULL,posted_at=NULL WHERE company_id=? AND source_type='bank_transaction' AND source_id=?")->execute([$companyId,$transactionId]);
        db()->prepare("UPDATE interbank_transfer_legs SET status='unmatched',unmatched_by=?,unmatched_at=UTC_TIMESTAMP() WHERE id=? AND status='linked'")->execute([(string)$user['id'],(string)$leg['id']]);db()->prepare("UPDATE interbank_transfers SET status='awaiting_counterpart',updated_at=UTC_TIMESTAMP() WHERE company_id=? AND id=?")->execute([$companyId,$transferId]);
        $opId=tegh_interbank_operation_create($user,$companyId,$operationKey,'unmatch',$hash,$transferId);$result=['transferId'=>$transferId,'status'=>'awaiting_counterpart','unmatchedBankTransactionId'=>$transactionId,'journalEntryId'=>(string)$transfer['journal_entry_id'],'journalSurvived'=>true,'journalsCreated'=>0,'operationKey'=>$operationKey,'idempotentReplay'=>false];audit_event($user,$companyId,'interbank_transfer.counterpart_unmatched','interbank_transfer',$transferId,$result);return tegh_interbank_operation_finish($opId,$transferId,$result);
    },4));
}

function tegh_interbank_relationship(array $company,string $transactionId): array
{
    $q=db()->prepare("SELECT t.*,l.id leg_id,l.link_kind,l.leg_role,l.status leg_status FROM interbank_transfer_legs l JOIN interbank_transfers t ON t.id=l.transfer_id AND t.company_id=l.company_id WHERE l.company_id=? AND l.bank_transaction_id=? ORDER BY l.linked_at DESC LIMIT 1");$q->execute([(string)$company['id'],$transactionId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return ['transactionId'=>$transactionId,'relationship'=>null];
    $pending=null;if((string)$row['status']!=='reversed'){$opQ=db()->prepare("SELECT result_json FROM interbank_transfer_operations WHERE company_id=? AND transfer_id=? AND operation_type='reverse' AND status='pending_approval' ORDER BY created_at DESC LIMIT 1");$opQ->execute([(string)$company['id'],(string)$row['id']]);$stored=$opQ->fetchColumn();if($stored!==false){$decoded=json_decode((string)$stored,true);if(is_array($decoded))$pending=$decoded;}}
    return ['transactionId'=>$transactionId,'relationship'=>tegh_interbank_transfer_result($row)+['legKind'=>(string)$row['link_kind'],'legRole'=>(string)$row['leg_role'],'legStatus'=>(string)$row['leg_status'],'reversalJournalEntryId'=>$row['reversal_journal_entry_id']!==null?(string)$row['reversal_journal_entry_id']:null,'reversedAt'=>$row['reversed_at']!==null?(string)$row['reversed_at']:null,'canUnmatch'=>(string)$row['status']==='matched'&&(string)$row['link_kind']==='matched_counterpart'&&(string)$row['leg_status']==='linked','canReverse'=>(string)$row['status']!=='reversed','pendingReversal'=>$pending]];
}

function tegh_interbank_reverse(array $user,array $company,array $input): array
{
    if(($input['confirmed']??false)!==true)fail('Confirm the whole-transfer reversal.',422,'confirmation_required');
    $companyId=(string)$company['id'];$transferId=clean_text($input['transferId']??'','Transfer',64);$date=safe_date($input['reversalDate']??canadian_today(),'Reversal date');assert_not_future_date($date,'Reversal date');$reason=clean_text($input['reason']??'','Reason',500);if(mb_strlen($reason)<12)fail('Give a reason of at least 12 characters. It is kept with the audit record.',422,'reversal_reason_required');$operationKey=tegh_bank_operation_key($input['operationKey']??null);$approvalId=trim((string)($input['approvalId']??''));if($approvalId!=='')require_company_role($company,'owner');
    $payload=['companyId'=>$companyId,'transferId'=>$transferId,'reversalDate'=>$date,'reason'=>$reason];$hash=tegh_interbank_payload_hash($payload);
    return tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$company,$companyId,$transferId,$date,$reason,$operationKey,$approvalId,$hash){
        tegh_bank_reauthorize_mutation($user,$company,'banking.reverse');$prior=tegh_interbank_operation_replay($companyId,$operationKey,'reverse',$hash);$operationRow=null;
        if($prior!==null){if(!isset($prior['_pendingOperation']))return $prior;$operationRow=$prior['_pendingOperation'];}
        $tQ=db()->prepare("SELECT t.*,je.entry_date FROM interbank_transfers t JOIN journal_entries je ON je.id=t.journal_entry_id AND je.company_id=t.company_id WHERE t.company_id=? AND t.id=? FOR UPDATE");$tQ->execute([$companyId,$transferId]);$transfer=$tQ->fetch(PDO::FETCH_ASSOC);if(!$transfer)fail('This transfer is unavailable.',409,'interbank_transfer_unavailable');if((string)$transfer['status']==='reversed')fail('This transfer has already been reversed.',409,'interbank_transfer_already_reversed');if($date<(string)$transfer['entry_date'])fail('Reversal date cannot precede the transfer journal date.',422,'reversal_date_invalid');assert_period_open($companyId,$date);require_journal_unmatched_before_reversal($companyId,(string)$transfer['journal_entry_id'],'interbank transfer');
        $legsQ=db()->prepare("SELECT bank_transaction_id FROM interbank_transfer_legs WHERE company_id=? AND transfer_id=? AND status='linked' FOR UPDATE");$legsQ->execute([$companyId,$transferId]);$legIds=$legsQ->fetchAll(PDO::FETCH_COLUMN);if($legIds===[])fail('Transfer evidence is incomplete.',409,'interbank_evidence_unavailable');foreach($legIds as $id)tegh_interbank_assert_statement_unreconciled($companyId,(string)$id);
        $amountQ=db()->prepare('SELECT COALESCE(SUM(debit_cents),0) FROM journal_lines WHERE journal_entry_id=?');$amountQ->execute([(string)$transfer['journal_entry_id']]);$amount=(int)$amountQ->fetchColumn();if($amount<=0)fail('The transfer journal amount is unavailable.',409,'interbank_journal_amount_unavailable');$approvalRow=null;$opId=$operationRow?(string)$operationRow['id']:'';
        if($amount>company_materiality_threshold_cents($companyId)){
            if($approvalId===''){
                if($operationRow){$stored=json_decode((string)$operationRow['result_json'],true);if(!is_array($stored))throw new RuntimeException('Stored reversal approval evidence is malformed.');$stored['idempotentReplay']=true;return $stored;}
                $approvalQ=db()->prepare("SELECT id FROM journal_approvals WHERE company_id=? AND source_type='interbank_transfer_reversal' AND source_id=? AND status='submitted' AND payload_hash=? LIMIT 1 FOR UPDATE");$approvalQ->execute([$companyId,$transferId,$hash]);$requestId=$approvalQ->fetchColumn();if($requestId===false){$requestId=new_id('ibapproval');db()->prepare("INSERT INTO journal_approvals(id,company_id,journal_entry_id,source_type,source_id,amount_cents,status,payload_hash,prepared_by,submitted_at,review_note) VALUES(?,?,?,?,?,?,'submitted',?,?,UTC_TIMESTAMP(),?)")->execute([$requestId,$companyId,(string)$transfer['journal_entry_id'],'interbank_transfer_reversal',$transferId,$amount,$hash,(string)$user['id'],$reason]);}
                $opId=tegh_interbank_operation_create($user,$companyId,$operationKey,'reverse',$hash,$transferId);$result=['pendingApproval'=>true,'approvalId'=>(string)$requestId,'transferId'=>$transferId,'status'=>(string)$transfer['status'],'amountCents'=>$amount,'reversalDate'=>$date,'reason'=>$reason,'operationKey'=>$operationKey,'journalsCreated'=>0,'idempotentReplay'=>false];audit_event($user,$companyId,'interbank_transfer.reversal_submitted','interbank_transfer',$transferId,$result);return tegh_interbank_operation_finish($opId,$transferId,$result,'pending_approval');
            }
            if(!$operationRow)fail('Use the original reversal operation key when approving this request.',409,'interbank_reversal_operation_unavailable');$approvalQ=db()->prepare("SELECT * FROM journal_approvals WHERE id=? AND company_id=? AND source_type='interbank_transfer_reversal' AND source_id=? FOR UPDATE");$approvalQ->execute([$approvalId,$companyId,$transferId]);$approvalRow=$approvalQ->fetch(PDO::FETCH_ASSOC);if(!$approvalRow||(string)$approvalRow['status']!=='submitted')fail('This reversal approval is no longer available.',409,'interbank_reversal_approval_unavailable');if(hash_equals((string)$approvalRow['prepared_by'],(string)$user['id']))fail('The person who requested this reversal cannot approve it.',409,'interbank_dual_control_required');if(!hash_equals((string)$approvalRow['payload_hash'],$hash)||(int)$approvalRow['amount_cents']!==$amount)fail('The transfer or reversal reason changed after approval was requested.',409,'interbank_reversal_approval_integrity_failed');db()->prepare("UPDATE journal_approvals SET status='approved',approved_by=?,approved_at=UTC_TIMESTAMP() WHERE id=? AND status='submitted'")->execute([(string)$user['id'],$approvalId]);
        }elseif($operationRow)fail('This reversal operation is in an invalid pending state.',409,'interbank_reversal_operation_invalid');else $opId=tegh_interbank_operation_create($user,$companyId,$operationKey,'reverse',$hash,$transferId);
        $journalId=add_reversing_journal_entry($user,$companyId,(string)$transfer['journal_entry_id'],$date,'interbank_transfer_reversal',$transferId,'Reverse interbank transfer · '.$reason);db()->prepare("UPDATE interbank_transfers SET status='reversed',reversal_journal_entry_id=?,reversed_by=?,reversed_at=UTC_TIMESTAMP(),reversal_reason=?,updated_at=UTC_TIMESTAMP() WHERE company_id=? AND id=? AND status<>'reversed'")->execute([$journalId,(string)$user['id'],$reason,$companyId,$transferId]);
        if(function_exists('voucher_register_saved'))voucher_register_saved($user,$companyId,'TR','BS','interbank_transfer_reversal',$transferId,$date,'Interbank transfer reversal · '.$reason,$amount,$journalId,true);if($approvalRow)db()->prepare("UPDATE journal_approvals SET status='posted',posted_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND status='approved'")->execute([$approvalId,$companyId]);
        $result=['pendingApproval'=>false,'transferId'=>$transferId,'status'=>'reversed','journalEntryId'=>(string)$transfer['journal_entry_id'],'reversalJournalEntryId'=>$journalId,'statementEvidenceCount'=>count($legIds),'journalsCreated'=>1,'operationKey'=>$operationKey,'idempotentReplay'=>false];audit_event($user,$companyId,'interbank_transfer.reversed','interbank_transfer',$transferId,$result+['reason'=>$reason,'reversalDate'=>$date,'approvalId'=>$approvalRow?$approvalId:null]);return tegh_interbank_operation_finish($opId,$transferId,$result);
    },4));
}

function handle_tegh_interbank_v5990(string $action): never
{
    $user=require_user();$company=require_company($user);tegh_schema45_require();header('Cache-Control: private, no-store');
    if($action==='candidates'){
        require_company_permission($company,'banking.match');
        require_method('GET');$transactionId=clean_text($_GET['transactionId']??'','Transaction',64);
        json_response(['ok'=>true]+tegh_interbank_candidates($company,$transactionId));
    }
    if($action==='relationship'){
        require_company_permission($company,'banking.view');require_method('GET');$transactionId=clean_text($_GET['transactionId']??'','Transaction',64);json_response(['ok'=>true]+tegh_interbank_relationship($company,$transactionId));
    }
    require_method('POST');require_csrf();$input=request_json();
    try{$result=match($action){'post-pair'=>tegh_interbank_post_pair($user,$company,$input),'match'=>tegh_interbank_match($user,$company,$input),'adopt'=>tegh_interbank_adopt($user,$company,$input),'unmatch'=>tegh_interbank_unmatch($user,$company,$input),'reverse'=>tegh_interbank_reverse($user,$company,$input),default=>throw new TeghServiceFailure('Interbank action not found.',404,'route_not_found')};}
    catch(TeghServiceFailure $e){tegh_fail_service($e);}
    json_response(['ok'=>true]+$result,!empty($result['pendingApproval'])?202:200);
}
