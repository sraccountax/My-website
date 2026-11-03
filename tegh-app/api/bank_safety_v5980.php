<?php
declare(strict_types=1);

/** Shared eligibility rules. UI hints are never the authorization boundary. */
function tegh_bank_account_reason(array $account,int $signedCents,bool $allowContra=false): string
{
    if(empty($account['active']))return 'Inactive account';
    if(!empty($account['linked_bank_id']))return 'Financial account — use Transfer';
    if(!empty($account['system_control'])||!empty($account['is_control'])||in_array((string)($account['code']??''),['9999','1200','2050','1100','1110','1115','2100','2110','2115','1050','2300','2310','2320','2330','2340','2350','3200'],true))return 'Protected control/system account';
    $type=(string)($account['account_type']??$account['type']??'');
    if(!in_array($type,['asset','liability','equity','income','expense'],true))return 'Unsupported account type';
    if($signedCents===0)return 'Zero-value statement line';
    if(!$allowContra&&(($signedCents>0&&$type==='expense')||($signedCents<0&&$type==='income')))return 'Opposite direction — an explicit contra decision is required';
    return '';
}
function tegh_bank_category_catalogue(string $companyId,bool $lock=false): array
{
    // Linked ledgers are protected even when a legacy is_control bit is wrong.
    $sql="SELECT a.*, (SELECT MIN(ba.id) FROM bank_accounts ba WHERE ba.company_id=a.company_id AND ba.ledger_account_id=a.id) linked_bank_id,
       EXISTS(SELECT 1 FROM company_system_accounts cs WHERE cs.company_id=a.company_id AND cs.account_id=a.id AND cs.status='active') system_control
       FROM accounts a WHERE a.company_id=? ORDER BY a.id".($lock?' FOR UPDATE':'');
    $q=db()->prepare($sql);$q->execute([$companyId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$row){$capability=tegh_account_capability_resolve($companyId,(string)$row['id'],false);$row['posting_capability']=$capability['kind'];$row['required_context']=$capability['requiredContext'];$row['posting_allowed']=$capability['postingAllowed'];$row['capability_reason']=$capability['reason'];}unset($row);
    return $rows;
}
function tegh_bank_category(string $companyId,string $accountId,int $amount,bool $allowContra=false,bool $lock=true): array
{
    // Validate against a fresh, company-scoped account at the decision site.
    $q=db()->prepare("SELECT a.*,(SELECT MIN(ba.id) FROM bank_accounts ba WHERE ba.company_id=a.company_id AND ba.ledger_account_id=a.id) linked_bank_id,
      EXISTS(SELECT 1 FROM company_system_accounts cs WHERE cs.company_id=a.company_id AND cs.account_id=a.id AND cs.status='active') system_control
      FROM accounts a WHERE a.company_id=? AND a.id=?".($lock?' FOR UPDATE':''));$q->execute([$companyId,$accountId]);$a=$q->fetch(PDO::FETCH_ASSOC);
    if(!$a)fail('Choose an available current-company account.',422,'bank_category_unavailable');
    $capability=tegh_account_capability_resolve($companyId,$accountId,$lock);
    if((string)$capability['kind']!=='ordinary_gl')fail((string)($capability['reason']?:'Complete the required account-specific workflow.'),422,'account_workflow_required');
    $reason=tegh_bank_account_reason($a,$amount,$allowContra);if($reason!=='')fail($reason,422,'bank_category_invalid');$a['capability']=$capability;return $a;
}
function tegh_bank_reauthorize_mutation(array $user,array $company,string $permission='banking.match'): array
{
    if(!db()->inTransaction())throw new LogicException('Reauthorization requires the mutation transaction.');
    $q=db()->prepare("SELECT c.*,cm.role FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id
      WHERE c.id=? AND cm.user_id=? AND c.active=1 AND cm.status='active' AND u.active=1 FOR UPDATE");
    $q->execute([(string)$company['id'],(string)$user['id']]);$fresh=$q->fetch(PDO::FETCH_ASSOC);
    if(!$fresh)fail('This company membership is no longer active.',403,'company_forbidden');
    if($permission!=='')require_company_permission($fresh,$permission);return $fresh;
}
function tegh_bank_resolve_transfer(array $company,array $transaction,array $decision,bool $lock=true): array
{
    $companyId=(string)$company['id'];$counterpartyId=trim((string)($decision['transferBankAccountId']??''));
    if($counterpartyId==='')fail('Select a counterparty financial account through Transfer.',422,'transfer_account_required');
    $currentId=(string)$transaction['bank_account_id'];if($counterpartyId===$currentId)fail('Transfer From and Transfer To must be different accounts.',422,'transfer_same_account');
    $ids=[$currentId,$counterpartyId];sort($ids,SORT_STRING);
    $q=db()->prepare("SELECT ba.id,ba.currency,ba.ledger_account_id,ba.active,ba.account_type,a.account_type ledger_type,a.active ledger_active,a.code,
      EXISTS(SELECT 1 FROM company_system_accounts cs WHERE cs.company_id=ba.company_id AND cs.account_id=a.id AND cs.status='active') system_control
      FROM bank_accounts ba JOIN accounts a ON a.id=ba.ledger_account_id AND a.company_id=ba.company_id
      WHERE ba.company_id=? AND ba.id IN (?,?) ORDER BY ba.id".($lock?' FOR UPDATE':''));$q->execute([$companyId,...$ids]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$byId=array_column($rows,null,'id');
    if(count($rows)!==2)fail('Select available financial accounts in the current company.',422,'transfer_account_unavailable');
    foreach($rows as $bank){
        if(empty($bank['active'])||empty($bank['ledger_active'])||!in_array((string)$bank['account_type'],['bank','credit_card'],true)||!in_array((string)$bank['ledger_type'],['asset','liability'],true)||!empty($bank['system_control'])||in_array((string)$bank['code'],['9999','1050','1100','1110','1115','1200','2050','2100','2110','2115','2300','2310','2320','2330','2340','2350','3200'],true))fail('A selected financial account is inactive or unsupported.',422,'transfer_account_unavailable');
        if((string)$bank['currency']!==(string)$transaction['currency'])fail('Transfers between different currencies require a supported FX workflow.',422,'transfer_currency_unsupported');
    }
    $current=$byId[$currentId];$other=$byId[$counterpartyId];
    $capability=tegh_account_capability_resolve($companyId,(string)$other['ledger_account_id'],$lock);
    if((string)$capability['kind']!=='financial_account'||empty($capability['postingAllowed']))fail('Choose an active authorized financial account.',422,'transfer_account_unavailable');
    if((string)$current['ledger_account_id']===(string)$other['ledger_account_id'])fail('The financial accounts use the same ledger.',422,'transfer_same_ledger');
    if(isset($decision['accountId'])&&trim((string)$decision['accountId'])!==''&&(string)$decision['accountId']!==(string)$other['ledger_account_id'])fail('The transfer decision has contradictory account instructions.',409,'transfer_decision_conflict');
    $foreign=(int)($transaction['foreign_amount_cents']??0);$base=(int)($transaction['amount_cents']??0);$pairId=trim((string)($decision['pairCounterpartTransactionId']??''));
    $pending=db()->prepare("SELECT bt.id FROM bank_transactions bt WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.id<>? AND bt.status='pending' AND bt.journal_entry_id IS NULL AND bt.currency=? AND bt.foreign_amount_cents=? AND bt.amount_cents=? ORDER BY bt.id".($lock?' FOR UPDATE':''));$pending->execute([$companyId,$counterpartyId,(string)$transaction['id'],(string)$transaction['currency'],-$foreign,-$base]);$pendingIds=array_map('strval',$pending->fetchAll(PDO::FETCH_COLUMN));
    $distinct=!empty($decision['confirmedDistinctTransfer']);$distinctReason=$distinct?clean_text($decision['distinctTransferReason']??'','Separate-transfer reason',500):'';if($distinct&&mb_strlen($distinctReason)<12)fail('Explain why this is a separate transfer in at least 12 characters.',422,'interbank_distinct_reason_required');
    if($pendingIds!==[]&&!in_array($pairId,$pendingIds,true)&&!$distinct)fail('An exact opposite pending statement row is available. Use Post transfer and link both so one journal serves both rows.',409,'interbank_pair_confirmation_required');
    $role=$foreign<0?'source':'destination';$accountColumn=$role==='source'?'source_bank_account_id':'destination_bank_account_id';$existing=db()->prepare("SELECT id FROM interbank_transfers WHERE company_id=? AND $accountColumn=? AND currency=? AND amount_cents=? AND status='awaiting_counterpart' ORDER BY created_at,id LIMIT 2".($lock?' FOR UPDATE':''));$existing->execute([$companyId,$currentId,(string)$transaction['currency'],abs($foreign)]);$existingIds=array_map('strval',$existing->fetchAll(PDO::FETCH_COLUMN));
    if($existingIds!==[]&&!$distinct)fail(count($existingIds)>1?'Multiple existing transfers could match this statement row. Review the candidates before posting.':'This statement row matches an existing posted transfer. Use Match transfer; no new journal is required.',409,'interbank_match_required');
    return ['currentLedger'=>(string)$current['ledger_account_id'],'counterpartyLedger'=>(string)$other['ledger_account_id'],'counterpartyBankAccountId'=>$counterpartyId,'direction'=>(int)$transaction['amount_cents']>0?'from':'to','taxCode'=>'NO_TAX'];
}
/** Typed search alternatives; textual wildcards are escaped independently. */
function tegh_bank_search_typed(string $text): array
{
    $text=trim($text);$date=null;$amount=null;$signed=false;
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$text)){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$text,new DateTimeZone('UTC'));if($d&&$d->format('Y-m-d')===$text)$date=$text;}
    elseif(preg_match('/^[A-Za-z]{3}\s+\d{1,2},\s*\d{4}$/D',$text)){$d=DateTimeImmutable::createFromFormat('!M j, Y',preg_replace('/,\s*/',', ',$text),new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();if($d&&(!$errors||(!$errors['warning_count']&&!$errors['error_count'])))$date=$d->format('Y-m-d');}
    if(preg_match('/^([+-]?)(?:\$\s*)?((?:\d{1,3}(?:,\d{3})+|\d{1,13}))(?:\.(\d{1,2}))?$/D',$text,$m)){
        $digits=str_replace(',','',$m[2]);if(strlen($digits)<=13){$amount=(int)$digits*100+(int)str_pad($m[3]??'',2,'0');$signed=$m[1]!=='';if($m[1]==='-')$amount=-$amount;}
    }elseif(preg_match('/^\$([+-])(.*)$/D',$text,$m)){return tegh_bank_search_typed($m[1].'$'.$m[2]);}
    return ['date'=>$date,'amountCents'=>$amount,'signed'=>$signed];
}
