<?php
declare(strict_types=1);

/**
 * Schema 40 advanced reconciliation authorization boundary.
 *
 * Analysis and preview are deterministic and read only. The one confirmation
 * endpoint delegates to the same posting and matching services used by the
 * normal UI, while owning one database transaction for the complete operation.
 */

/** @return array<string,mixed> */
function tegh_recon_role_context(array $user,array $company): array
{
    $stmt=db()->prepare("SELECT role,status FROM company_members WHERE company_id=? AND user_id=? LIMIT 1");
    $stmt->execute([(string)$company['id'],(string)$user['id']]);$membership=$stmt->fetch();
    return [
        'userId'=>(string)$user['id'],'companyId'=>(string)$company['id'],
        'role'=>(string)($membership['role']??''),'membershipStatus'=>(string)($membership['status']??''),
        'bankingMatch'=>company_role_can((string)($membership['role']??''),'banking.match'),
        'bankingReconcile'=>company_role_can((string)($membership['role']??''),'banking.reconcile'),
    ];
}

function tegh_recon_role_hash(array $user,array $company): string
{
    return hash('sha256',tegh_json(tegh_recon_role_context($user,$company)));
}

/** @return array<int,string> */
function tegh_recon_ids(mixed $value,string $label,int $maximum=100): array
{
    if(!is_array($value))fail($label.' must be a list.',422,'reconciliation_selection_invalid');
    $ids=[];foreach($value as $raw){$id=clean_text($raw,$label,64);$ids[$id]=true;}
    $ids=array_keys($ids);sort($ids,SORT_STRING);
    if(!$ids||count($ids)>$maximum)fail($label.' must contain between 1 and '.$maximum.' records.',422,'reconciliation_selection_invalid');
    return $ids;
}

/** @return array<int,array<string,mixed>> */
function tegh_recon_normalize_decisions(mixed $raw): array
{
    if(!is_array($raw)||count($raw)<1||count($raw)>100)fail('Choose between 1 and 100 reconciliation decisions.',422,'reconciliation_decisions_invalid');
    $out=[];$used=[];
    foreach($raw as $index=>$item){
        if(!is_array($item))fail('A reconciliation decision is invalid.',422,'reconciliation_decision_invalid');
        $kind=(string)($item['kind']??'');
        if(!in_array($kind,['match','post_payment','post_invoice','post_bill','post_gl'],true))fail('A reconciliation decision type is not registered.',422,'reconciliation_decision_kind_invalid');
        $bankIds=tegh_recon_ids($item['bankTransactionIds']??($item['bankTransactionId']??null), 'Bank transactions', 6);
        foreach($bankIds as $id){if(isset($used[$id]))fail('A bank transaction appears in more than one selected decision.',409,'reconciliation_selection_conflict');$used[$id]=true;}
        $row=['kind'=>$kind,'bankTransactionIds'=>$bankIds];
        if($kind==='match'){
            $row['journalEntryIds']=tegh_recon_ids($item['journalEntryIds']??null,'Book entries',6);
        }else{
            if(count($bankIds)!==1)fail('A posting decision must contain exactly one bank transaction.',422,'reconciliation_post_one_to_one');
            if($kind==='post_payment')$row['paymentId']=clean_text($item['paymentId']??'','Posted payment',64);
            elseif($kind==='post_invoice')$row['invoiceId']=clean_text($item['invoiceId']??'','Customer invoice',64);
            elseif($kind==='post_bill')$row['billId']=clean_text($item['billId']??'','Vendor bill',64);
            else{
                $row['accountId']=clean_text($item['accountId']??'','Bookkeeping account',64);
                $row['applyGstHst']=!empty($item['applyGstHst']);$row['applyPst']=!empty($item['applyPst']);
                $row['taxCode']=$row['applyGstHst']&&$row['applyPst']?'GST_HST_PST':($row['applyGstHst']?'GST_HST':($row['applyPst']?'PST':'NO_TAX'));
            }
        }
        $out[]=$row;
    }
    usort($out,static fn(array $a,array $b):int=>strcmp(implode('|',$a['bankTransactionIds']),implode('|',$b['bankTransactionIds']))?:strcmp($a['kind'],$b['kind']));
    return $out;
}

/** @return array<int,array<string,mixed>> */
function tegh_recon_post_candidates(array $company,string $bankAccountId,array $bankIds,string $start,string $end): array
{
    $companyId=(string)$company['id'];$ph=implode(',',array_fill(0,count($bankIds),'?'));
    $stmt=db()->prepare("SELECT bt.id,bt.transaction_date,bt.description,bt.normalized_merchant,bt.reference,bt.amount_cents,bt.foreign_amount_cents,bt.currency,bt.suggested_account_id,ba.ledger_account_id
      FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id
      WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.id IN ($ph) AND bt.transaction_date BETWEEN ? AND ? AND bt.status='pending' ORDER BY bt.transaction_date,bt.id");
    $stmt->execute(array_merge([$companyId,$bankAccountId],$bankIds,[$start,$end]));$rows=$stmt->fetchAll();$out=[];
    foreach($rows as $transaction){
        $incoming=(int)$transaction['amount_cents']>0;$foreign=abs((int)$transaction['foreign_amount_cents']);$candidates=[];
        $paymentType=$incoming?'customer':'vendor';
        $payment=db()->prepare("SELECT pp.id,pp.reference,pp.payment_date,pp.foreign_amount_cents,pp.payment_account_id,pa.code payment_account_code,COALESCE(c.name,v.name,'') party_name,COALESCE(i.number,b.number,'') document_number
          FROM party_payments pp JOIN accounts pa ON pa.id=pp.payment_account_id AND pa.company_id=pp.company_id
          LEFT JOIN customers c ON pp.payment_type='customer' AND c.id=pp.party_id AND c.company_id=pp.company_id
          LEFT JOIN vendors v ON pp.payment_type='vendor' AND v.id=pp.party_id AND v.company_id=pp.company_id
          LEFT JOIN invoices i ON pp.payment_type='customer' AND i.id=pp.document_id AND i.company_id=pp.company_id
          LEFT JOIN bills b ON pp.payment_type='vendor' AND b.id=pp.document_id AND b.company_id=pp.company_id
          WHERE pp.company_id=? AND pp.payment_type=? AND pp.status='posted' AND pp.bank_transaction_id IS NULL
            AND (pp.payment_account_id=? OR (pp.payment_type='customer' AND pa.code='1050'))
            AND pp.currency=? AND pp.foreign_amount_cents=? AND pp.payment_date<=? AND ABS(DATEDIFF(pp.payment_date,?))<=60
          ORDER BY ABS(DATEDIFF(pp.payment_date,?)),pp.created_at LIMIT 5");
        $payment->execute([$companyId,$paymentType,$transaction['ledger_account_id'],$transaction['currency'],$foreign,$transaction['transaction_date'],$transaction['transaction_date'],$transaction['transaction_date']]);
        foreach($payment->fetchAll() as $row){
            $rank=operations_review_existing_payment_score($transaction,$row);$strongEvidence=(bool)array_filter($rank['reasons'],static fn(string $reason):bool=>str_starts_with($reason,'Matching ')||str_contains($reason,'name appears'));$exactFinancialAccount=(string)$row['payment_account_id']===(string)$transaction['ledger_account_id'];
            if($rank['score']<72)continue;
            $candidates[]=['kind'=>'post_payment','targetId'=>(string)$row['id'],'label'=>$incoming?'Match posted customer receipt':'Match posted vendor payment','party'=>(string)$row['party_name'],'reference'=>(string)($row['document_number']?:$row['reference']),'documentDate'=>(string)$row['payment_date'],'amountCents'=>(int)$row['foreign_amount_cents'],'score'=>(int)$rank['score'],'reasons'=>array_merge($rank['reasons'],$exactFinancialAccount?[]:['Receipt is in Undeposited Funds; a reviewed transfer journal will be required']),'selectedByDefault'=>$exactFinancialAccount&&$rank['score']>=90&&$strongEvidence,'decision'=>['kind'=>'post_payment','bankTransactionIds'=>[(string)$transaction['id']],'paymentId'=>(string)$row['id']]];
        }
        if($incoming){
            $docs=db()->prepare("SELECT i.id,i.number,i.issue_date document_date,i.foreign_balance_cents,c.name party_name FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.company_id=? AND i.status='sent' AND i.currency=? AND i.foreign_balance_cents>=? ORDER BY (i.foreign_balance_cents=?) DESC,i.due_date LIMIT 12");
        }else{
            $docs=db()->prepare("SELECT b.id,b.number,b.bill_date document_date,b.foreign_balance_cents,v.name party_name FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id WHERE b.company_id=? AND b.status='open' AND b.currency=? AND b.foreign_balance_cents>=? ORDER BY (b.foreign_balance_cents=?) DESC,b.due_date LIMIT 12");
        }
        $docs->execute([$companyId,$transaction['currency'],$foreign,$foreign]);
        foreach($docs->fetchAll() as $document){$rank=operations_review_document_score($transaction,$document);if($rank['score']<68)continue;$kind=$incoming?'post_invoice':'post_bill';$targetField=$incoming?'invoiceId':'billId';$candidates[]=['kind'=>$kind,'targetId'=>(string)$document['id'],'label'=>$incoming?'Post customer payment and match':'Post vendor payment and match','party'=>(string)$document['party_name'],'reference'=>(string)$document['number'],'documentDate'=>(string)$document['document_date'],'amountCents'=>$foreign,'openBalanceCents'=>(int)$document['foreign_balance_cents'],'partial'=>$foreign!==(int)$document['foreign_balance_cents'],'score'=>(int)$rank['score'],'reasons'=>$rank['reasons'],'selectedByDefault'=>false,'decision'=>['kind'=>$kind,'bankTransactionIds'=>[(string)$transaction['id']],$targetField=>(string)$document['id']]];}
        $suggested=trim((string)($transaction['suggested_account_id']??''));
        if($suggested!==''){$account=company_account($companyId,$suggested);if($account&&!$account['is_control'])$candidates[]=['kind'=>'post_gl','targetId'=>$suggested,'label'=>'Post to selected bookkeeping account and match','party'=>'Direct General Ledger','reference'=>(string)$account['code'].' · '.(string)$account['name'],'amountCents'=>$foreign,'score'=>0,'reasons'=>['Requires explicit account and tax review'],'selectedByDefault'=>false,'decision'=>['kind'=>'post_gl','bankTransactionIds'=>[(string)$transaction['id']],'accountId'=>$suggested,'applyGstHst'=>false,'applyPst'=>false]];}
        $out[]=['transaction'=>['id'=>(string)$transaction['id'],'date'=>(string)$transaction['transaction_date'],'description'=>(string)$transaction['description'],'reference'=>(string)($transaction['reference']??''),'amountCents'=>(int)$transaction['amount_cents'],'currency'=>(string)$transaction['currency']],'candidates'=>$candidates];
    }
    return $out;
}

/** @return array<string,mixed> */
function tegh_recon_analysis(array $company,array $input): array
{
    $bankId=clean_text($input['accountId']??$input['bankAccountId']??'','Financial account',64);
    $start=safe_date($input['periodStart']??'','Period start');$end=safe_date($input['periodEnd']??'','Period end');if($start>$end)fail('Period start cannot be after period end.',422,'reconciliation_period_invalid');
    $bankIds=tegh_recon_ids($input['bankTransactionIds']??null,'Bank transactions');
    $match=operations_reconciliation_suggestions_data($company,$bankId,$start,$end,$bankIds,[],false);
    foreach($match['proposals'] as &$proposal){$proposal['decision']=['kind'=>'match','bankTransactionIds'=>$proposal['bankTransactionIds'],'journalEntryIds'=>$proposal['journalEntryIds']];}unset($proposal);
    return ['account'=>$match['account'],'period'=>['start'=>$start,'end'=>$end],'selectedBankTransactionIds'=>$bankIds,'matchProposals'=>$match['proposals'],'postCandidates'=>tegh_recon_post_candidates($company,$bankId,$bankIds,$start,$end),'analysisSource'=>'deterministic_company_records','providerUsed'=>false,'committed'=>false,'selectionPolicy'=>['exactSourceMayDefault'=>true,'strongPostedPaymentMayDefault'=>true,'fuzzyGroupedPartialFxTaxRequireExplicitSelection'=>true]];
}

/** @return array<string,mixed> */
function tegh_recon_snapshot(array $company,string $bankId,string $start,string $end,array $decisions,bool $lock=false): array
{
    $companyId=(string)$company['id'];$allBank=[];$journal=[];$payments=[];$invoices=[];$bills=[];$accounts=[];
    foreach($decisions as $decision){foreach($decision['bankTransactionIds'] as $id)$allBank[$id]=true;if($decision['kind']==='match')foreach($decision['journalEntryIds'] as $id)$journal[$id]=true;elseif($decision['kind']==='post_payment')$payments[$decision['paymentId']]=true;elseif($decision['kind']==='post_invoice')$invoices[$decision['invoiceId']]=true;elseif($decision['kind']==='post_bill')$bills[$decision['billId']]=true;elseif($decision['kind']==='post_gl')$accounts[$decision['accountId']]=true;}
    $bankIds=array_keys($allBank);sort($bankIds,SORT_STRING);$ph=implode(',',array_fill(0,count($bankIds),'?'));
    $q=db()->prepare("SELECT bt.id,bt.bank_account_id,bt.transaction_date,bt.description,bt.reference,bt.normalized_merchant,bt.amount_cents,bt.foreign_amount_cents,bt.currency,bt.exchange_rate_micros,bt.status,bt.source_hash,bt.suggested_account_id,bt.decided_account_id,bt.tax_code,bt.confidence,bt.suggestion_source,bt.journal_entry_id,bt.updated_at,ba.ledger_account_id,ba.last_reconciled_date
      FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.id IN ($ph) AND bt.transaction_date BETWEEN ? AND ?".($lock?' FOR UPDATE':''));
    $q->execute(array_merge([$companyId,$bankId],$bankIds,[$start,$end]));$rows=$q->fetchAll();if(count($rows)!==count($bankIds))fail('One or more selected bank transactions are unavailable in this account and period.',409,'reconciliation_source_changed');
    usort($rows,static fn(array $a,array $b):int=>strcmp((string)$a['id'],(string)$b['id']));$snapshot=['bankTransactions'=>$rows,'targets'=>[]];$ledger=(string)$rows[0]['ledger_account_id'];
    $load=function(array $ids,string $sql,string $kind)use(&$snapshot,$companyId,$lock):void{if(!$ids)return;$keys=array_keys($ids);sort($keys,SORT_STRING);$stmt=db()->prepare(str_replace(':ids',implode(',',array_fill(0,count($keys),'?')),$sql).($lock?' FOR UPDATE':''));$stmt->execute(array_merge([$companyId],$keys));$loaded=$stmt->fetchAll();if(count($loaded)!==count($keys))fail('A selected '.$kind.' target changed or is unavailable.',409,'reconciliation_target_changed');foreach($loaded as $row)$snapshot['targets'][$kind.':'.(string)$row['id']]=$row;};
    $load($payments,"SELECT id,payment_type,payment_date,amount_cents,foreign_amount_cents,currency,exchange_rate_micros,payment_account_id,journal_entry_id,bank_transaction_id,status,updated_at FROM party_payments WHERE company_id=? AND id IN (:ids)",'payment');
    $load($invoices,"SELECT id,status,issue_date,balance_cents,foreign_balance_cents,currency,exchange_rate_micros,subtotal_cents,tax_cents,foreign_total_cents,foreign_tax_cents,is_opening_document,updated_at FROM invoices WHERE company_id=? AND id IN (:ids)",'invoice');
    $load($bills,"SELECT id,status,bill_date,balance_cents,foreign_balance_cents,currency,exchange_rate_micros,category_account_id,subtotal_cents,tax_cents,foreign_total_cents,foreign_tax_cents,is_opening_document,updated_at FROM bills WHERE company_id=? AND id IN (:ids)",'bill');
    $load($accounts,"SELECT id,code,name,account_type,normal_balance,is_control,active FROM accounts WHERE company_id=? AND id IN (:ids)",'account');
    if($journal){
        $keys=array_keys($journal);sort($keys,SORT_STRING);$jph=implode(',',array_fill(0,count($keys),'?'));
        // Aggregate SELECT ... FOR UPDATE is rejected or does not establish a
        // useful row lock on supported MySQL/MariaDB variants. Lock canonical
        // source and line rows first, then calculate the deterministic amount.
        if($lock){
            $lockEntries=db()->prepare("SELECT id FROM journal_entries WHERE company_id=? AND id IN ($jph) FOR UPDATE");$lockEntries->execute(array_merge([$companyId],$keys));
            if(count($lockEntries->fetchAll(PDO::FETCH_COLUMN))!==count($keys))fail('A selected book entry changed or is unavailable.',409,'reconciliation_target_changed');
            $lockLines=db()->prepare("SELECT jl.id FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.id IN ($jph) ORDER BY jl.id FOR UPDATE");$lockLines->execute(array_merge([$companyId],$keys));$lockLines->fetchAll(PDO::FETCH_COLUMN);
        }
        $q=db()->prepare("SELECT je.id,je.entry_date,je.source_type,je.source_id,je.status,SUM(jl.debit_cents-jl.credit_cents) bank_impact_cents FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id=? AND je.id IN ($jph) AND jl.account_id=? GROUP BY je.id,je.entry_date,je.source_type,je.source_id,je.status");
        $q->execute(array_merge([$companyId],$keys,[$ledger]));$loaded=$q->fetchAll();if(count($loaded)!==count($keys))fail('A selected book entry changed or no longer posts to this financial account.',409,'reconciliation_target_changed');foreach($loaded as $row)$snapshot['targets']['journal:'.(string)$row['id']]=$row;
    }
    ksort($snapshot['targets'],SORT_STRING);
    $control=db()->prepare('SELECT closed_through_date FROM accounting_controls WHERE company_id=?');$control->execute([$companyId]);$snapshot['control']=['closedThroughDate'=>$control->fetchColumn()?:null];
    $locks=db()->prepare("SELECT id,period_start,period_end,locked,updated_at FROM period_locks WHERE company_id=? AND locked=1 AND period_start<=? AND period_end>=? ORDER BY period_start,id".($lock?' FOR UPDATE':''));$locks->execute([$companyId,$end,$start]);$snapshot['periodLocks']=$locks->fetchAll();
    return $snapshot;
}

/** @return array<int,array<string,mixed>> */
function tegh_recon_expected_effects(array $snapshot,array $decisions): array
{
    $bank=[];foreach($snapshot['bankTransactions'] as $row)$bank[(string)$row['id']]=$row;$out=[];$allowed=max(canadian_today(),gmdate('Y-m-d'));
    foreach($decisions as $decision){
        $amount=0;$dates=[];foreach($decision['bankTransactionIds'] as $id){$amount+=(int)$bank[$id]['amount_cents'];$dates[]=(string)$bank[$id]['transaction_date'];}
        $matchDate=max($dates);$kind=(string)$decision['kind'];$target=$kind==='match'?$decision['journalEntryIds']:[$decision['paymentId']??$decision['invoiceId']??$decision['billId']??$decision['accountId']??''];
        $createsJournal=$kind!=='match';$effect='Uses the existing authoritative bank posting service, then links the resulting posted book entry in the same transaction.';
        if($kind==='match'){$createsJournal=false;$effect='Links statement and existing posted book records; no General Ledger entry.';}
        elseif($kind==='post_payment'){
            $payment=$snapshot['targets']['payment:'.(string)$decision['paymentId']]??[];$bankLedger=(string)$bank[$decision['bankTransactionIds'][0]]['ledger_account_id'];
            $createsJournal=(string)($payment['payment_account_id']??'')!==$bankLedger;
            $effect=$createsJournal?'Links the posted payment and adds only the required transfer from Undeposited Funds before matching.':'Links the statement line to the already-posted payment and its existing journal; no new General Ledger entry.';
        }
        $out[]=['kind'=>$kind,'bankTransactionIds'=>$decision['bankTransactionIds'],'targetIds'=>$target,'amountCents'=>$amount,'currency'=>(string)$bank[$decision['bankTransactionIds'][0]]['currency'],'matchDate'=>$matchDate,'allowedLatestMatchDate'=>$allowed,'createsGeneralLedgerEntry'=>$createsJournal,'createsMatchMetadata'=>true,'accountingEffect'=>$effect];
    }
    return $out;
}

/** @return array<string,mixed> */
function tegh_recon_prepare(array $user,array $company,array $input): array
{
    require_company_permission($company,'banking.match');tegh_require_feature($user,$company,'banking.reconciliation.match_post');
    $bankId=clean_text($input['accountId']??$input['bankAccountId']??'','Financial account',64);$start=safe_date($input['periodStart']??'','Period start');$end=safe_date($input['periodEnd']??'','Period end');if($start>$end)fail('Period start cannot be after period end.',422,'reconciliation_period_invalid');
    $decisions=tegh_recon_normalize_decisions($input['decisions']??null);$operationKey=trim((string)($input['operationKey']??''));if(strlen($operationKey)<16||strlen($operationKey)>120||!preg_match('/^[A-Za-z0-9._:-]+$/',$operationKey))fail('Provide a stable operation key of 16 to 120 safe characters.',422,'operation_key_invalid');
    $snapshot=tegh_recon_snapshot($company,$bankId,$start,$end,$decisions,false);$effects=tegh_recon_expected_effects($snapshot,$decisions);
    foreach($effects as $effect)if($effect['matchDate']>$effect['allowedLatestMatchDate'])fail('The proposed match date '.$effect['matchDate'].' is after the allowed date '.$effect['allowedLatestMatchDate'].' under the company/server calendar rule.',422,'match_date_future');
    $resolved=tegh_resolve_feature($user,$company,'banking.reconciliation.match_post');$expiresAt=gmdate('Y-m-d H:i:s',time()+900);
    $payload=['actionId'=>'bank.transactions.match_post','userId'=>(string)$user['id'],'companyId'=>(string)$company['id'],'entitlementRevision'=>(int)$resolved['revision'],'roleContextHash'=>tegh_recon_role_hash($user,$company),'bankAccountId'=>$bankId,'periodStart'=>$start,'periodEnd'=>$end,'bankTransactionIds'=>array_values(array_unique(array_merge(...array_map(static fn(array $d):array=>$d['bankTransactionIds'],$decisions)))),'decisions'=>$decisions,'sourceSnapshot'=>$snapshot,'sourceRevisionHash'=>hash('sha256',tegh_json($snapshot)),'expectedAccountingEffects'=>$effects,'operationKey'=>$operationKey,'_teghOperationKey'=>$operationKey,'expiresAt'=>$expiresAt,'_teghAi'=>['actionId'=>'bank.transactions.match_post','path'=>'structured_command_centre','confidence'=>1.0,'model'=>'none','mode'=>'full','registryVersion'=>'5500']];
    sort($payload['bankTransactionIds'],SORT_STRING);$payload['previewSignature']=secret_hash(tegh_json($payload));
    $confirmationId=tegh_agent_authorization($user,$company,'bank.transactions.match_post',$payload,15);
    audit_event($user,(string)$company['id'],'reconciliation.match_post_previewed','ai_agent_action',$confirmationId,['sourceRevisionHash'=>$payload['sourceRevisionHash'],'operationKeyHash'=>hash('sha256',$operationKey),'decisionCount'=>count($decisions),'entitlementRevision'=>(int)$resolved['revision'],'providerUsed'=>false,'accountingWrites'=>0]);
    return ['confirmationId'=>$confirmationId,'operationKey'=>$operationKey,'expiresAt'=>$expiresAt,'expiresInSeconds'=>900,'confirmationWindowSeconds'=>900,'previewSignature'=>$payload['previewSignature'],'sourceRevisionHash'=>$payload['sourceRevisionHash'],'entitlementRevision'=>(int)$resolved['revision'],'effects'=>$effects,'decisions'=>$decisions,'authorizationRequired'=>true,'committed'=>false,'providerUsed'=>false];
}

function tegh_recon_resume_log_start(array $user,array $company,array $payload,bool $resuming): ?string
{
    if(!schema_table_exists('reconciliation_resume_log'))return null;
    $id=new_id('rresume');
    db()->prepare("INSERT INTO reconciliation_resume_log (id,company_id,user_id,bank_account_id,operation_key_hash,attempt_kind,result_status,decision_count,recovered_count,started_at) VALUES (?,?,?,?,?,?,'started',?,0,UTC_TIMESTAMP())")
        ->execute([$id,$company['id'],$user['id'],$payload['bankAccountId'],hash('sha256',(string)$payload['operationKey']),$resuming?'resume':'first_time',count((array)($payload['decisions']??[]))]);
    return $id;
}

function tegh_recon_resume_log_finish(?string $id,string $status,int $recoveredCount=0,?string $errorCode=null): void
{
    if($id===null)return;
    db()->prepare('UPDATE reconciliation_resume_log SET result_status=?,recovered_count=?,error_code=?,completed_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([$status,$recoveredCount,$errorCode,$id]);
}

/** @return array<string,mixed>|null */
function tegh_recon_exact_group(string $companyId,string $bankId,array $bankIds,array $journalIds): ?array
{
    sort($bankIds,SORT_STRING);sort($journalIds,SORT_STRING);$stmt=db()->prepare("SELECT DISTINCT g.id,g.bank_amount_cents,g.book_amount_cents,g.matched_at FROM bank_match_groups g JOIN bank_match_bank_items bi ON bi.match_group_id=g.id WHERE g.company_id=? AND g.bank_account_id=? AND g.status='matched' AND bi.bank_transaction_id=? ORDER BY g.matched_at DESC");$stmt->execute([$companyId,$bankId,$bankIds[0]]);
    foreach($stmt->fetchAll() as $group){$q=db()->prepare('SELECT bank_transaction_id FROM bank_match_bank_items WHERE match_group_id=? ORDER BY bank_transaction_id');$q->execute([$group['id']]);$actualBank=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));$q=db()->prepare('SELECT journal_entry_id FROM bank_match_book_items WHERE match_group_id=? ORDER BY journal_entry_id');$q->execute([$group['id']]);$actualJournal=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));if($actualBank===$bankIds&&$actualJournal===$journalIds)return $group;}
    return null;
}

/** @return array<string,mixed> */
function tegh_recon_posting_decision(array $decision): array
{
    $out=['id'=>(string)$decision['bankTransactionIds'][0]];
    foreach(['paymentId','invoiceId','billId','accountId','applyGstHst','applyPst','taxCode'] as $field)if(array_key_exists($field,$decision))$out[$field]=$decision[$field];
    return $out;
}

/** @return array<string,mixed>|null */
function tegh_recon_recovered_item(array $company,array $decision): ?array
{
    $companyId=(string)$company['id'];$bankId=(string)$company['_authorizedBankAccountId'];$journals=$decision['journalEntryIds']??[];
    if($decision['kind']!=='match'){
        $existing=bank_transaction_existing_post_result($company,tegh_recon_posting_decision($decision));if($existing===null)return null;
        $journal=(string)($existing['journalEntryId']??'');if($journal==='')fail('The prior bank posting has no verifiable journal result.',409,'authorization_recovery_failed');$journals=[$journal];
    }
    $group=tegh_recon_exact_group($companyId,$bankId,$decision['bankTransactionIds'],$journals);if(!$group)return null;
    return ['kind'=>$decision['kind'],'bankTransactionIds'=>$decision['bankTransactionIds'],'journalEntryIds'=>$journals,'matchGroupId'=>(string)$group['id'],'amountCents'=>(int)$group['bank_amount_cents'],'idempotent'=>true];
}

/** @return array<string,mixed> */
function tegh_execute_authorized_reconciliation(array $user,array $company,array $payload,bool $resuming=false): array
{
    $signature=(string)($payload['previewSignature']??'');$signable=$payload;unset($signable['previewSignature']);if($signature===''||!hash_equals($signature,secret_hash(tegh_json($signable))))fail('The reconciliation preview signature is invalid.',409,'authorization_integrity_failed');
    if(!hash_equals((string)$payload['userId'],(string)$user['id'])||!hash_equals((string)$payload['companyId'],(string)$company['id']))fail('The reconciliation preview belongs to a different user or company.',409,'authorization_context_changed');
    if(!hash_equals((string)$payload['roleContextHash'],tegh_recon_role_hash($user,$company)))fail('Your company role or membership changed after preview. Analyze and review again.',409,'authorization_role_changed');
    $resolved=tegh_resolve_feature($user,$company,'banking.reconciliation.match_post');if(!$resolved['allowed']||(int)$resolved['revision']!==(int)$payload['entitlementRevision'])fail('Feature access changed after preview. Analyze and review again.',409,'authorization_entitlement_changed');
    $decisions=tegh_recon_normalize_decisions($payload['decisions']??null);$bankId=(string)$payload['bankAccountId'];$company['_authorizedBankAccountId']=$bankId;$resumeLogId=tegh_recon_resume_log_start($user,$company,$payload,$resuming);
    if($resuming){$recovered=[];foreach($decisions as $decision){$item=tegh_recon_recovered_item($company,$decision);if($item!==null)$recovered[]=$item;}if(count($recovered)===count($decisions)){tegh_recon_resume_log_finish($resumeLogId,'recovered',count($recovered));return ['status'=>'completed','idempotent'=>true,'items'=>$recovered,'postedCount'=>count(array_filter($decisions,static fn(array $d):bool=>$d['kind']!=='match')),'matchedCount'=>count($recovered),'providerUsed'=>false];}if($recovered){tegh_recon_resume_log_finish($resumeLogId,'blocked',count($recovered),'authorization_recovery_failed');fail('A prior result is only partially verifiable. No retry was attempted; review the posted and matched records.',409,'authorization_recovery_failed');}}
    db()->beginTransaction();$posting=[];$postIds=[];$receipts=[];
    try{
        $current=tegh_recon_snapshot($company,$bankId,(string)$payload['periodStart'],(string)$payload['periodEnd'],$decisions,true);if(!hash_equals((string)$payload['sourceRevisionHash'],hash('sha256',tegh_json($current))))fail('A selected bank, book, document, tax, account, period or source record changed after preview. Analyze and review again.',409,'authorization_source_changed');
        foreach($decisions as $decision)if($decision['kind']!=='match'){$posting[]=tegh_recon_posting_decision($decision);$postIds[]=(string)$decision['bankTransactionIds'][0];}
        if($posting)bank_transaction_post_service($user,$company,$posting,'authorized_match_post',false);
        if(!empty(config('testing.fail_after_authorized_bank_post')))throw new RuntimeException('Injected failure after posting and before matching.');
        foreach($decisions as $decision){
            $journals=$decision['journalEntryIds']??[];
            if($decision['kind']!=='match'){$q=db()->prepare("SELECT journal_entry_id FROM bank_transactions WHERE id=? AND company_id=? AND bank_account_id=? AND status='posted' FOR UPDATE");$q->execute([$decision['bankTransactionIds'][0],$company['id'],$bankId]);$journal=$q->fetchColumn();if($journal===false||$journal===null)fail('The authoritative bank posting did not produce a verifiable book entry.',409,'match_post_journal_missing');$journals=[(string)$journal];}
            $matchDate='';foreach($current['bankTransactions'] as $row)if(in_array((string)$row['id'],$decision['bankTransactionIds'],true))$matchDate=max($matchDate,(string)$row['transaction_date']);
            $match=operations_reconciliation_match_service($user,$company,['bankAccountId'=>$bankId,'bankTransactionIds'=>$decision['bankTransactionIds'],'journalEntryIds'=>$journals,'matchDate'=>$matchDate],false);
            $balance=db()->prepare('SELECT bank_amount_cents,book_amount_cents FROM bank_match_groups WHERE id=? AND company_id=? FOR UPDATE');$balance->execute([$match['matchGroupId'],$company['id']]);$verified=$balance->fetch();if(!$verified||(int)$verified['bank_amount_cents']!==(int)$verified['book_amount_cents'])throw new RuntimeException('The match group failed its balance invariant.');
            foreach($journals as $journalId){$q=db()->prepare('SELECT COALESCE(SUM(debit_cents),0),COALESCE(SUM(credit_cents),0) FROM journal_lines WHERE journal_entry_id=?');$q->execute([$journalId]);$totals=$q->fetch(PDO::FETCH_NUM);if((int)$totals[0]!==((int)$totals[1]))throw new RuntimeException('A resulting journal failed its balance invariant.');}
            $receipts[]=['kind'=>$decision['kind'],'bankTransactionIds'=>$decision['bankTransactionIds'],'journalEntryIds'=>$journals,'matchGroupId'=>(string)$match['matchGroupId'],'amountCents'=>(int)$match['amountCents'],'matchDate'=>(string)$match['matchDate'],'idempotent'=>false];
        }
        audit_event($user,(string)$company['id'],'reconciliation.match_post_completed','bank_account',$bankId,['operationKeyHash'=>hash('sha256',(string)$payload['operationKey']),'sourceRevisionHash'=>(string)$payload['sourceRevisionHash'],'postedCount'=>count($posting),'matchedCount'=>count($receipts),'entitlementRevision'=>(int)$resolved['revision'],'providerUsed'=>false]);
        db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();tegh_recon_resume_log_finish($resumeLogId,'blocked',0,'execution_failed');throw $error;}
    if($postIds&&function_exists('tegh_ai_observe_bank_post_batch'))tegh_ai_observe_bank_post_batch($user,$company,$postIds,'authorized_match_post');
    tegh_usage_record((string)$company['id'],'banking.reconciliation.match_post',true,count($receipts));
    tegh_recon_resume_log_finish($resumeLogId,'executed',0);
    return ['status'=>'completed','idempotent'=>false,'items'=>$receipts,'postedCount'=>count($posting),'matchedCount'=>count($receipts),'providerUsed'=>false,'successMessage'=>'Selected transactions were matched and posted successfully.'];
}

function handle_authorized_reconciliation(string $action): never
{
    $user=require_user();$company=require_company($user);require_company_permission($company,$action==='analyze'?'banking.view':'banking.match');tegh_require_feature($user,$company,$action==='analyze'?'banking.reconciliation.advanced':'banking.reconciliation.match_post');
    require_method('POST');require_csrf();$input=request_json();
    if($action==='analyze')json_response(['analysis'=>tegh_recon_analysis($company,$input)]);
    if($action==='preview')json_response(['preview'=>tegh_recon_prepare($user,$company,$input)],201);
    if($action==='confirm'){$confirmationId=clean_text($input['confirmationId']??'','Reconciliation approval',64);$result=tegh_execute_authorization($user,$company,$confirmationId);$result['refreshRequired']=true;$result['closeReview']=true;$result['notification']='Match & Post completed successfully.';json_response($result);}
    fail('Authorized reconciliation route not found.',404,'route_not_found');
}
