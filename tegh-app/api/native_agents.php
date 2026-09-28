<?php
declare(strict_types=1);

/**
 * Tegh 5.0 Native Agent Supervisor.
 *
 * Specialist scans are deterministic and read-only against accounting tables.
 * The only writes in this module are policy, run, finding, notification, task
 * hand-off and audit metadata. Accounting commits remain in the existing Tegh
 * services and authorization boundary.
 */

const NATIVE_AGENT_TYPES = ['bookkeeping','reconciliation','month_end_close','accounts_payable','accounts_receivable','financial_analyst','payroll_tax'];

function native_agent_canonical(mixed $value): mixed
{
    if(!is_array($value))return $value;
    $isList=array_is_list($value);
    if(!$isList)ksort($value,SORT_STRING);
    foreach($value as $key=>$item)$value[$key]=native_agent_canonical($item);
    return $value;
}

function native_agent_json(mixed $value): string
{
    return json_encode(native_agent_canonical($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}

function native_agent_hash(mixed $value): string
{
    return hash('sha256',native_agent_json($value));
}

function native_agent_default_policy_values(): array
{
    return [
        'supervisorEnabled'=>true,
        'bookkeepingEnabled'=>true,
        'reconciliationEnabled'=>true,
        'monthEndCloseEnabled'=>true,
        'accountsPayableEnabled'=>true,
        'accountsReceivableEnabled'=>true,
        'financialAnalystEnabled'=>true,
        'payrollTaxAgentEnabled'=>true,
        'cadence'=>'manual',
        'opportunisticEnabled'=>true,
        'materialityCents'=>10000,
        'staleDays'=>30,
        'confidenceReviewBps'=>8000,
        'notificationMinSeverity'=>'warning',
        'connectedEnabled'=>false,
        'thresholds'=>[
            'batchSize'=>200,
            'unusualAmountMultiple'=>10,
            'reconciliationStrongScore'=>82,
            'reconciliationAccountBatch'=>3,
            'autoMatchPreviewEnabled'=>false,
            'autoMatchMinimumScore'=>96,
            'apUpcomingDays'=>7,
            'collectionFriendlyDays'=>1,
            'collectionFirmDays'=>15,
            'collectionFinalDays'=>30,
            'staleDocumentDays'=>14,
            'cashForecastDays'=>90,
            'minimumCashCents'=>0,
            'budgetVariancePercentBps'=>1000,
            'payrollRemittanceReviewDays'=>15,
            'payrollRateReviewDays'=>150,
        ],
    ];
}

function native_agent_policy_hash(array $policy): string
{
    $keys=['supervisorEnabled','bookkeepingEnabled','reconciliationEnabled','monthEndCloseEnabled','accountsPayableEnabled','accountsReceivableEnabled','financialAnalystEnabled','payrollTaxAgentEnabled','cadence','opportunisticEnabled','materialityCents','staleDays','confidenceReviewBps','notificationMinSeverity','connectedEnabled','thresholds'];
    $values=[];foreach($keys as $key)$values[$key]=$policy[$key]??null;
    return native_agent_hash($values);
}

function native_agent_policy_from_row(array $row): array
{
    $thresholds=json_decode((string)($row['thresholds_json']??'{}'),true);
    if(!is_array($thresholds))$thresholds=[];
    $defaults=native_agent_default_policy_values()['thresholds'];
    $policy=[
        'id'=>(string)$row['id'],'companyId'=>(string)$row['company_id'],
        'supervisorEnabled'=>(bool)$row['supervisor_enabled'],'bookkeepingEnabled'=>(bool)$row['bookkeeping_enabled'],
        'reconciliationEnabled'=>(bool)$row['reconciliation_enabled'],'monthEndCloseEnabled'=>(bool)$row['close_enabled'],
        'accountsPayableEnabled'=>(bool)($row['ap_enabled']??true),'accountsReceivableEnabled'=>(bool)($row['ar_enabled']??true),
        'financialAnalystEnabled'=>(bool)($row['financial_analyst_enabled']??true),
        'payrollTaxAgentEnabled'=>(bool)($row['payroll_tax_agent_enabled']??true),
        'cadence'=>(string)$row['cadence'],'opportunisticEnabled'=>(bool)$row['opportunistic_enabled'],
        'materialityCents'=>(int)$row['materiality_cents'],'staleDays'=>(int)$row['stale_days'],
        'confidenceReviewBps'=>(int)$row['confidence_review_bps'],'notificationMinSeverity'=>(string)$row['notification_min_severity'],
        'connectedEnabled'=>tegh_connected_release_enabled() && (bool)$row['connected_enabled'],'thresholds'=>array_merge($defaults,$thresholds),
        'revision'=>(int)$row['revision'],'policyHash'=>(string)$row['policy_hash'],
        'updatedBy'=>$row['updated_by']!==null?(string)$row['updated_by']:null,'updatedAt'=>(string)$row['updated_at'],
    ];
    $policy['connectedDisclosure']='When enabled, Tegh may send a redacted question and already-built accounting facts to the configured provider for difficult language or narrative explanation. The provider never receives database credentials and never becomes accounting authority.';
    return $policy;
}

function native_agent_policy_get(array $company,?array $actor=null): array
{
    if(!schema_table_exists('native_agent_policies'))fail('Native Agent storage is not ready. Run the protected Schema 35 upgrade.',503,'native_agent_schema_required');
    $companyId=(string)$company['id'];$stmt=db()->prepare('SELECT * FROM native_agent_policies WHERE company_id=? LIMIT 1');$stmt->execute([$companyId]);$row=$stmt->fetch();
    if(!$row){
        $defaults=native_agent_default_policy_values();$hash=native_agent_policy_hash($defaults);$id=new_id('napolicy');
        try{
            db()->prepare("INSERT INTO native_agent_policies (id,company_id,supervisor_enabled,bookkeeping_enabled,reconciliation_enabled,close_enabled,ap_enabled,ar_enabled,financial_analyst_enabled,payroll_tax_agent_enabled,cadence,opportunistic_enabled,materiality_cents,stale_days,confidence_review_bps,notification_min_severity,connected_enabled,thresholds_json,revision,policy_hash,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)")
                ->execute([$id,$companyId,1,1,1,1,1,1,1,1,'manual',1,10000,30,8000,'warning',0,native_agent_json($defaults['thresholds']),$hash,null,null]);
        }catch(PDOException $error){if((string)$error->getCode()!=='23000')throw $error;}
        $stmt->execute([$companyId]);$row=$stmt->fetch();
    }
    if(!$row)throw new RuntimeException('Native Agent policy row could not be prepared.');
    $policy=native_agent_policy_from_row($row);
    if(!hash_equals(native_agent_policy_hash($policy),(string)$policy['policyHash'])){
        $policy['policyHash']=native_agent_policy_hash($policy);
        db()->prepare('UPDATE native_agent_policies SET policy_hash=? WHERE id=? AND company_id=?')->execute([$policy['policyHash'],$policy['id'],$companyId]);
    }
    return $policy;
}

function native_agent_bool(array $input,string $key,bool $fallback): bool
{
    if(!array_key_exists($key,$input))return $fallback;
    if(is_bool($input[$key]))return $input[$key];
    if($input[$key]===0||$input[$key]==='0')return false;
    if($input[$key]===1||$input[$key]==='1')return true;
    fail('The '.$key.' setting must be on or off.',422,'native_agent_policy_invalid');
}

function native_agent_int(array $input,string $key,int $fallback,int $min,int $max): int
{
    if(!array_key_exists($key,$input))return $fallback;
    if(!is_int($input[$key])&&!is_string($input[$key])&&!is_float($input[$key]))fail('The '.$key.' setting must be a whole number.',422,'native_agent_policy_invalid');
    $raw=(string)$input[$key];if(!preg_match('/^-?\d+$/',$raw))fail('The '.$key.' setting must be a whole number.',422,'native_agent_policy_invalid');
    $value=(int)$raw;if($value<$min||$value>$max)fail('The '.$key.' setting is outside the supported range.',422,'native_agent_policy_invalid');return $value;
}

function native_agent_policy_save(array $user,array $company,array $input): array
{
    require_company_permission($company,'company.settings');
    if (!tegh_connected_release_enabled() && !empty($input['connectedEnabled'])) { tegh_disable_stale_connected_policy($company,$user); fail('Connected Intelligence is unavailable in this release.',409,'connected_unavailable_in_release'); }
    $input['connectedEnabled']=false;
    $current=native_agent_policy_get($company,$user);$thresholdInput=is_array($input['thresholds']??null)?$input['thresholds']:[];
    $cadence=(string)($input['cadence']??$current['cadence']);if(!in_array($cadence,['manual','daily','weekly'],true))fail('Choose a supported Native Agent cadence.',422,'native_agent_policy_invalid');
    $severity=(string)($input['notificationMinSeverity']??$current['notificationMinSeverity']);if(!in_array($severity,['info','warning','critical'],true))fail('Choose a supported notification severity.',422,'native_agent_policy_invalid');
    $policy=[
        'supervisorEnabled'=>native_agent_bool($input,'supervisorEnabled',(bool)$current['supervisorEnabled']),
        'bookkeepingEnabled'=>native_agent_bool($input,'bookkeepingEnabled',(bool)$current['bookkeepingEnabled']),
        'reconciliationEnabled'=>native_agent_bool($input,'reconciliationEnabled',(bool)$current['reconciliationEnabled']),
        'monthEndCloseEnabled'=>native_agent_bool($input,'monthEndCloseEnabled',(bool)$current['monthEndCloseEnabled']),
        'accountsPayableEnabled'=>native_agent_bool($input,'accountsPayableEnabled',(bool)$current['accountsPayableEnabled']),
        'accountsReceivableEnabled'=>native_agent_bool($input,'accountsReceivableEnabled',(bool)$current['accountsReceivableEnabled']),
        'financialAnalystEnabled'=>native_agent_bool($input,'financialAnalystEnabled',(bool)$current['financialAnalystEnabled']),
        'payrollTaxAgentEnabled'=>native_agent_bool($input,'payrollTaxAgentEnabled',(bool)$current['payrollTaxAgentEnabled']),
        'cadence'=>$cadence,'opportunisticEnabled'=>native_agent_bool($input,'opportunisticEnabled',(bool)$current['opportunisticEnabled']),
        'materialityCents'=>native_agent_int($input,'materialityCents',(int)$current['materialityCents'],0,100000000000),
        'staleDays'=>native_agent_int($input,'staleDays',(int)$current['staleDays'],1,3650),
        'confidenceReviewBps'=>native_agent_int($input,'confidenceReviewBps',(int)$current['confidenceReviewBps'],5000,10000),
        'notificationMinSeverity'=>$severity,
        'connectedEnabled'=>native_agent_bool($input,'connectedEnabled',(bool)$current['connectedEnabled']),
        'thresholds'=>[
            'batchSize'=>native_agent_int($thresholdInput,'batchSize',(int)$current['thresholds']['batchSize'],25,500),
            'unusualAmountMultiple'=>native_agent_int($thresholdInput,'unusualAmountMultiple',(int)$current['thresholds']['unusualAmountMultiple'],2,100),
            'reconciliationStrongScore'=>native_agent_int($thresholdInput,'reconciliationStrongScore',(int)$current['thresholds']['reconciliationStrongScore'],70,100),
            'reconciliationAccountBatch'=>native_agent_int($thresholdInput,'reconciliationAccountBatch',(int)$current['thresholds']['reconciliationAccountBatch'],1,5),
            'autoMatchPreviewEnabled'=>native_agent_bool($thresholdInput,'autoMatchPreviewEnabled',(bool)$current['thresholds']['autoMatchPreviewEnabled']),
            'autoMatchMinimumScore'=>native_agent_int($thresholdInput,'autoMatchMinimumScore',(int)$current['thresholds']['autoMatchMinimumScore'],96,100),
            'apUpcomingDays'=>native_agent_int($thresholdInput,'apUpcomingDays',(int)$current['thresholds']['apUpcomingDays'],1,90),
            'collectionFriendlyDays'=>native_agent_int($thresholdInput,'collectionFriendlyDays',(int)$current['thresholds']['collectionFriendlyDays'],0,90),
            'collectionFirmDays'=>native_agent_int($thresholdInput,'collectionFirmDays',(int)$current['thresholds']['collectionFirmDays'],1,180),
            'collectionFinalDays'=>native_agent_int($thresholdInput,'collectionFinalDays',(int)$current['thresholds']['collectionFinalDays'],1,365),
            'staleDocumentDays'=>native_agent_int($thresholdInput,'staleDocumentDays',(int)$current['thresholds']['staleDocumentDays'],1,365),
            'cashForecastDays'=>native_agent_int($thresholdInput,'cashForecastDays',(int)$current['thresholds']['cashForecastDays'],30,366),
            'minimumCashCents'=>native_agent_int($thresholdInput,'minimumCashCents',(int)$current['thresholds']['minimumCashCents'],0,1000000000000000),
            'budgetVariancePercentBps'=>native_agent_int($thresholdInput,'budgetVariancePercentBps',(int)$current['thresholds']['budgetVariancePercentBps'],0,10000),
            'payrollRemittanceReviewDays'=>native_agent_int($thresholdInput,'payrollRemittanceReviewDays',(int)$current['thresholds']['payrollRemittanceReviewDays'],1,90),
            'payrollRateReviewDays'=>native_agent_int($thresholdInput,'payrollRateReviewDays',(int)$current['thresholds']['payrollRateReviewDays'],30,366),
        ],
    ];
    if($policy['thresholds']['collectionFriendlyDays']>$policy['thresholds']['collectionFirmDays']||$policy['thresholds']['collectionFirmDays']>$policy['thresholds']['collectionFinalDays'])fail('Collection thresholds must increase from friendly to firm to final.',422,'native_agent_policy_invalid');
    $hash=native_agent_policy_hash($policy);$revision=(int)$current['revision']+1;
    db()->prepare('UPDATE native_agent_policies SET supervisor_enabled=?,bookkeeping_enabled=?,reconciliation_enabled=?,close_enabled=?,ap_enabled=?,ar_enabled=?,financial_analyst_enabled=?,payroll_tax_agent_enabled=?,cadence=?,opportunistic_enabled=?,materiality_cents=?,stale_days=?,confidence_review_bps=?,notification_min_severity=?,connected_enabled=?,thresholds_json=?,revision=?,policy_hash=?,updated_by=? WHERE id=? AND company_id=?')
        ->execute([$policy['supervisorEnabled']?1:0,$policy['bookkeepingEnabled']?1:0,$policy['reconciliationEnabled']?1:0,$policy['monthEndCloseEnabled']?1:0,$policy['accountsPayableEnabled']?1:0,$policy['accountsReceivableEnabled']?1:0,$policy['financialAnalystEnabled']?1:0,$policy['payrollTaxAgentEnabled']?1:0,$policy['cadence'],$policy['opportunisticEnabled']?1:0,$policy['materialityCents'],$policy['staleDays'],$policy['confidenceReviewBps'],$policy['notificationMinSeverity'],$policy['connectedEnabled']?1:0,native_agent_json($policy['thresholds']),$revision,$hash,(string)$user['id'],$current['id'],(string)$company['id']]);
    audit_event($user,(string)$company['id'],'native_agent.policy_updated','native_agent_policy',(string)$current['id'],['revision'=>$revision,'policyHash'=>$hash,'connectedEnabled'=>$policy['connectedEnabled'],'cadence'=>$policy['cadence']]);
    return native_agent_policy_get($company,$user);
}

function native_agent_revision_query(string $sql,array $params): array
{
    $stmt=db()->prepare($sql);$stmt->execute($params);$row=$stmt->fetch();return is_array($row)?$row:[];
}

function native_agent_source_revision(array $company,string $agent,array $context=[]): string
{
    $companyId=(string)$company['id'];$parts=['agent'=>$agent,'companyId'=>$companyId];
    $parts['accounts']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(SUM(CRC32(CONCAT_WS('|',id,code,name,account_type,normal_balance,is_control,active))),0) row_hash FROM accounts WHERE company_id=?",[$companyId]);
    if($agent==='bookkeeping'){
        $parts['bankTransactions']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,bank_account_id,transaction_date,description,COALESCE(reference,''),COALESCE(normalized_merchant,''),amount_cents,status,COALESCE(decided_account_id,''),COALESCE(suggested_account_id,''),tax_code,confidence,source_hash))),0) row_hash FROM bank_transactions WHERE company_id=?",[$companyId]);
        $parts['imports']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(created_at),'') max_created,COALESCE(SUM(CRC32(CONCAT_WS('|',id,bank_account_id,status,row_count,duplicate_count))),0) row_hash FROM import_batches WHERE company_id=?",[$companyId]);
    }elseif($agent==='reconciliation'){
        $parts['bankAccounts']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(SUM(CRC32(CONCAT_WS('|',id,ledger_account_id,active,last_reconciled_date,statement_balance_cents))),0) row_hash FROM bank_accounts WHERE company_id=?",[$companyId]);
        $parts['bankTransactions']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,bank_account_id,transaction_date,description,COALESCE(reference,''),COALESCE(normalized_merchant,''),amount_cents,status,COALESCE(journal_entry_id,''),source_hash))),0) row_hash FROM bank_transactions WHERE company_id=?",[$companyId]);
        $parts['journals']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(created_at),'') max_created,COALESCE(SUM(CRC32(CONCAT_WS('|',id,entry_date,status,source_type,source_id))),0) row_hash FROM journal_entries WHERE company_id=?",[$companyId]);
        $parts['journalLines']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(SUM(CRC32(CONCAT_WS('|',jl.id,jl.journal_entry_id,jl.account_id,jl.debit_cents,jl.credit_cents))),0) row_hash FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=?",[$companyId]);
        $parts['matches']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(COALESCE(unreconciled_at,matched_at,created_at)),'') max_changed,COALESCE(SUM(CRC32(CONCAT_WS('|',id,status,bank_amount_cents,book_amount_cents))),0) row_hash FROM bank_match_groups WHERE company_id=?",[$companyId]);
        $parts['reconciliations']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(created_at),'') max_created,COALESCE(SUM(CRC32(CONCAT_WS('|',id,bank_account_id,period_end,status,difference_cents))),0) row_hash FROM reconciliations WHERE company_id=?",[$companyId]);
    }elseif($agent==='accounts_payable'){
        $parts['ruleDate']=canadian_today();
        $parts['vendors']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,name,COALESCE(email,''),status,active,COALESCE(default_expense_account_id,''),default_terms_days))),0) row_hash FROM vendors WHERE company_id=?",[$companyId]);
        $parts['bills']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,vendor_id,number,bill_date,due_date,status,category_account_id,total_cents,balance_cents,COALESCE(import_reference,''),COALESCE(issued_journal_entry_id,'')))),0) row_hash FROM bills WHERE company_id=?",[$companyId]);
        $parts['payments']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,party_id,COALESCE(document_id,''),payment_date,amount_cents,applied_cents,status))),0) row_hash FROM party_payments WHERE company_id=? AND payment_type='vendor'",[$companyId]);
        $parts['documents']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,state,sha256,candidate_hash,source_revision_hash,COALESCE(linked_entity_id,'')))),0) row_hash FROM native_agent_documents WHERE company_id=? AND document_type='vendor_bill'",[$companyId]);
    }elseif($agent==='accounts_receivable'){
        $parts['ruleDate']=canadian_today();
        $parts['customers']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,name,COALESCE(email,''),status,active))),0) row_hash FROM customers WHERE company_id=?",[$companyId]);
        $parts['invoices']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,customer_id,number,issue_date,due_date,status,total_cents,balance_cents,COALESCE(import_reference,''),COALESCE(issued_journal_entry_id,'')))),0) row_hash FROM invoices WHERE company_id=?",[$companyId]);
        $parts['payments']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,party_id,COALESCE(document_id,''),payment_date,amount_cents,applied_cents,status))),0) row_hash FROM party_payments WHERE company_id=? AND payment_type='customer'",[$companyId]);
        $parts['followups']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(created_at),'') max_created,COALESCE(SUM(CRC32(CONCAT_WS('|',id,invoice_id,action_date,level,channel))),0) row_hash FROM invoice_followups WHERE company_id=?",[$companyId]);
        $parts['collections']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,invoice_id,state,source_revision_hash,content_hash,COALESCE(outbound_email_id,'')))),0) row_hash FROM native_agent_collection_drafts WHERE company_id=?",[$companyId]);
        $parts['documents']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,state,sha256,candidate_hash,source_revision_hash,COALESCE(linked_entity_id,'')))),0) row_hash FROM native_agent_documents WHERE company_id=? AND document_type='customer_invoice'",[$companyId]);
    }elseif($agent==='financial_analyst'){
        $parts['ruleDate']=canadian_today();
        $parts['bankAccounts']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(SUM(CRC32(CONCAT_WS('|',id,ledger_account_id,account_type,active,currency))),0) row_hash FROM bank_accounts WHERE company_id=?",[$companyId]);
        $parts['journals']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(created_at),'') max_created,COALESCE(SUM(CRC32(CONCAT_WS('|',id,entry_date,status,source_type,source_id))),0) row_hash FROM journal_entries WHERE company_id=?",[$companyId]);
        $parts['journalLines']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(SUM(CRC32(CONCAT_WS('|',jl.id,jl.journal_entry_id,jl.account_id,jl.debit_cents,jl.credit_cents))),0) row_hash FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=?",[$companyId]);
        $parts['invoices']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,due_date,status,balance_cents,total_cents))),0) row_hash FROM invoices WHERE company_id=?",[$companyId]);
        $parts['bills']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,due_date,status,balance_cents,total_cents))),0) row_hash FROM bills WHERE company_id=?",[$companyId]);
        $parts['budgets']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,name,period_start,period_end,status))),0) row_hash FROM budgets WHERE company_id=?",[$companyId]);
        $parts['budgetLines']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(SUM(CRC32(CONCAT_WS('|',bl.id,bl.budget_id,bl.account_id,COALESCE(bl.analytic_account_id,''),bl.planned_cents))),0) row_hash FROM budget_lines bl JOIN budgets b ON b.id=bl.budget_id WHERE b.company_id=?",[$companyId]);
        $parts['scenarios']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,horizon_start,horizon_end,base_kind,COALESCE(source_budget_id,''),status,revision,scenario_hash))),0) row_hash FROM financial_scenarios WHERE company_id=?",[$companyId]);
        $parts['scenarioAdjustments']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,scenario_id,adjustment_date,direction,amount_cents,activity,probability_bps))),0) row_hash FROM financial_scenario_adjustments WHERE company_id=?",[$companyId]);
    }elseif($agent==='payroll_tax'){
        $parts['ruleDate']=canadian_today();
        $parts['companyPolicy']=['moduleMode'=>(string)($company['module_mode']??'both'),'taxRegistered'=>(bool)($company['tax_registered']??false),'pstRegistered'=>(bool)($company['pst_registered']??false),'payrollPostingMode'=>(string)($company['payroll_posting_mode']??'draft')];
        $parts['settings']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',company_id,default_frequency,remitter_type,active,wages_account_id,employer_expense_account_id,net_pay_account_id,tax_payable_account_id,cpp_payable_account_id,ei_payable_account_id))),0) row_hash FROM payroll_settings WHERE company_id=?",[$companyId]);
        $parts['employees']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,employee_number,province_of_employment,province_of_residence,pay_frequency,pay_type,active,CASE WHEN sin_last_four IS NULL THEN 0 ELSE 1 END,CASE WHEN federal_td1_cents IS NULL THEN 0 ELSE 1 END,CASE WHEN provincial_td1_cents IS NULL THEN 0 ELSE 1 END))),0) row_hash FROM payroll_employees WHERE company_id=?",[$companyId]);
        $parts['runs']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,period_start,period_end,pay_date,status,gl_status,gross_pay_cents,income_tax_cents,net_pay_cents,COALESCE(payment_date,'')))),0) row_hash FROM payroll_runs WHERE company_id=?",[$companyId]);
        $parts['items']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(SUM(CRC32(CONCAT_WS('|',i.id,i.payroll_run_id,i.employee_id,i.verification_status,CASE WHEN i.verified_income_tax_cents IS NULL THEN 0 ELSE 1 END,i.locked_hash))),0) row_hash FROM payroll_run_items i JOIN payroll_runs r ON r.id=i.payroll_run_id WHERE r.company_id=?",[$companyId]);
        $parts['remittances']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(created_at),'') max_created,COALESCE(SUM(CRC32(CONCAT_WS('|',id,period_end,payment_date,employee_tax_cents,cpp_cents,ei_cents,total_cents,status,COALESCE(bank_transaction_id,''),COALESCE(journal_entry_id,''),COALESCE(reversal_journal_entry_id,'')))),0) row_hash FROM payroll_remittances WHERE company_id=?",[$companyId]);
        $parts['remittanceJournalLines']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(SUM(CRC32(CONCAT_WS('|',jl.id,jl.journal_entry_id,jl.account_id,jl.debit_cents,jl.credit_cents))),0) row_hash FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN payroll_remittances pr ON pr.journal_entry_id=je.id AND pr.company_id=je.company_id WHERE pr.company_id=?",[$companyId]);
        $parts['bankTransactions']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,bank_account_id,transaction_date,description,COALESCE(reference,''),COALESCE(normalized_merchant,''),amount_cents,status,COALESCE(journal_entry_id,'')))),0) row_hash FROM bank_transactions WHERE company_id=?",[$companyId]);
        $parts['rates']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,jurisdiction,person_type,rate_key,effective_from,COALESCE(effective_to,''),COALESCE(value_decimal,''),COALESCE(value_cents,''),status))),0) row_hash FROM statutory_rates WHERE company_id=?",[$companyId]);
        $parts['levyProfiles']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',company_id,jurisdiction,applicability,posting_mode,eligible_for_exemption,associated_group_payroll_cents))),0) row_hash FROM employer_levy_profiles WHERE company_id=?",[$companyId]);
        $parts['levyRates']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(created_at),'') max_created,COALESCE(SUM(CRC32(CONCAT_WS('|',id,jurisdiction,effective_from,COALESCE(effective_to,''),payroll_min_cents,COALESCE(payroll_max_cents,''),active))),0) row_hash FROM employer_levy_rates WHERE company_id=?",[$companyId]);
        $parts['taxControlLines']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(je.created_at),'') max_created,COALESCE(SUM(CRC32(CONCAT_WS('|',jl.id,je.entry_date,jl.account_id,jl.debit_cents,jl.credit_cents))),0) row_hash FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id=? AND je.status='posted' AND a.code IN ('1100','2100')",[$companyId]);
        $parts['locks']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,period_start,period_end,locked))),0) row_hash FROM period_locks WHERE company_id=?",[$companyId]);
    }elseif($agent==='month_end_close'){
        [$start,$end]=native_agent_close_period((string)($context['period']??''));$parts['period']=[$start,$end];
        foreach([
            'bankTransactions'=>"SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,bank_account_id,transaction_date,amount_cents,status,COALESCE(journal_entry_id,'')))),0) row_hash FROM bank_transactions WHERE company_id=? AND transaction_date<=?",
            'journals'=>"SELECT COUNT(*) row_count,COALESCE(MAX(created_at),'') max_created,COALESCE(SUM(CRC32(CONCAT_WS('|',id,entry_date,status,source_type,source_id))),0) row_hash FROM journal_entries WHERE company_id=? AND entry_date<=?",
            'invoices'=>"SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,issue_date,due_date,status,balance_cents,total_cents))),0) row_hash FROM invoices WHERE company_id=? AND issue_date<=?",
            'bills'=>"SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,bill_date,due_date,status,balance_cents,total_cents))),0) row_hash FROM bills WHERE company_id=? AND bill_date<=?",
            'payroll'=>"SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,period_end,pay_date,status,gl_status))),0) row_hash FROM payroll_runs WHERE company_id=? AND period_end<=?",
            'reconciliations'=>"SELECT COUNT(*) row_count,COALESCE(MAX(created_at),'') max_created,COALESCE(SUM(CRC32(CONCAT_WS('|',id,bank_account_id,period_end,status,difference_cents))),0) row_hash FROM reconciliations WHERE company_id=? AND period_end<=?",
            'locks'=>"SELECT COUNT(*) row_count,COALESCE(MAX(updated_at),'') max_updated,COALESCE(SUM(CRC32(CONCAT_WS('|',id,period_start,period_end,locked))),0) row_hash FROM period_locks WHERE company_id=? AND period_start<=?",
        ] as $key=>$sql)$parts[$key]=native_agent_revision_query($sql,[$companyId,$end]);
        $parts['journalLines']=native_agent_revision_query("SELECT COUNT(*) row_count,COALESCE(SUM(CRC32(CONCAT_WS('|',jl.id,jl.account_id,jl.debit_cents,jl.credit_cents,jl.memo))),0) row_hash FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.entry_date<=?",[$companyId,$end]);
    }else throw new InvalidArgumentException('Unsupported native agent type.');
    return native_agent_hash($parts);
}

function native_agent_close_period(string $period=''): array
{
    if($period==='')$period=(new DateTimeImmutable('first day of this month'))->modify('-1 month')->format('Y-m');
    if(!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/',$period))fail('Choose a close period in YYYY-MM format.',422,'native_agent_period_invalid');
    $start=$period.'-01';$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
    if($start>(new DateTimeImmutable('first day of this month'))->format('Y-m-d'))fail('The close period cannot be in the future.',422,'native_agent_period_invalid');
    return [$start,$end];
}

function native_agent_execution_class_for(string $actionId): string
{
    $action=tegh_action_get($actionId);return tegh_action_execution_class($action);
}

function native_agent_validate_proposal(string $actionId,array $inputs): array
{
    $action=tegh_action_get($actionId);$declared=array_fill_keys(array_merge((array)$action['required_inputs'],(array)$action['optional_inputs']),true);
    foreach(array_keys($inputs) as $key)if(!isset($declared[$key]))throw new RuntimeException('Native Agent finding proposed an undeclared registry input.');
    return [$action,tegh_action_execution_class($action)];
}

function native_agent_finding_spec(string $agent,string $type,string $identity,string $severity,int $confidence,string $title,string $explanation,array $evidence,string $actionId,array $inputs,?string $sourceDate=null): array
{
    if(!in_array($agent,NATIVE_AGENT_TYPES,true))throw new InvalidArgumentException('Unsupported native agent type.');
    if(!in_array($severity,['info','warning','critical'],true))throw new InvalidArgumentException('Unsupported native agent severity.');
    [, $class]=native_agent_validate_proposal($actionId,$inputs);
    return [
        'agentType'=>$agent,'findingType'=>$type,'fingerprint'=>hash('sha256',$agent.'|'.$type.'|'.$identity),
        'severity'=>$severity,'confidenceBps'=>max(0,min(10000,$confidence)),'title'=>mb_substr($title,0,220),
        'explanation'=>mb_substr($explanation,0,1200),'evidence'=>$evidence,'evidenceHash'=>native_agent_hash($evidence),
        'sourceDate'=>$sourceDate,'affectedCount'=>max(0,(int)($evidence['affectedCount']??count((array)($evidence['records']??[])))),
        'proposedActionId'=>$actionId,'proposedInputs'=>$inputs,'executionClass'=>$class,
    ];
}

function native_agent_bookkeeping_collect(array $company,array $policy,string $cursor,string $sourceRevision): array
{
    $companyId=(string)$company['id'];$limit=(int)$policy['thresholds']['batchSize'];$params=[$companyId];$cursorValue=[];
    if($cursor!==''){$cursorValue=json_decode($cursor,true);if(!is_array($cursorValue)||count($cursorValue)!==2)$cursorValue=[];}
    $where="bt.company_id=? AND bt.status='pending'";
    if($cursorValue){$where.=' AND (bt.updated_at>? OR (bt.updated_at=? AND bt.id>?))';$params[]=(string)$cursorValue[0];$params[]=(string)$cursorValue[0];$params[]=(string)$cursorValue[1];}
    $sql="SELECT bt.id,bt.bank_account_id,bt.import_batch_id,bt.transaction_date,bt.amount_cents,bt.status,bt.decided_account_id,bt.suggested_account_id,bt.tax_code,bt.confidence,bt.suggestion_source,bt.source_hash,bt.updated_at,
        ba.name bank_name,da.code decided_code,da.name decided_name,da.active decided_active,da.is_control decided_control,
        sa.code suggested_code,sa.name suggested_name,sa.active suggested_active,sa.is_control suggested_control
      FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id
      LEFT JOIN accounts da ON da.id=bt.decided_account_id AND da.company_id=bt.company_id
      LEFT JOIN accounts sa ON sa.id=bt.suggested_account_id AND sa.company_id=bt.company_id
      WHERE $where ORDER BY bt.updated_at,bt.id LIMIT ".($limit+1);
    $stmt=db()->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();$hasMore=count($rows)>$limit;if($hasMore)$rows=array_slice($rows,0,$limit);
    $findings=[];$today=new DateTimeImmutable(canadian_today());$staleDays=(int)$policy['staleDays'];$confidenceCutoff=(int)round((int)$policy['confidenceReviewBps']/100);$unusual=max(1,(int)$policy['materialityCents'])*(int)$policy['thresholds']['unusualAmountMultiple'];
    $validTax=['NO_TAX','GST_HST','HST13','PST','GST_HST_PST'];
    foreach($rows as $row){
        $reasons=[];$severity='info';$confidence=10000;$account=null;$accountValid=false;
        if($row['decided_account_id']!==null){$account=['id'=>(string)$row['decided_account_id'],'code'=>(string)$row['decided_code'],'name'=>(string)$row['decided_name']];$accountValid=(bool)$row['decided_active']&&!(bool)$row['decided_control'];if(!$accountValid){$reasons[]='The selected account is inactive or protected.';$severity='critical';}}
        elseif($row['suggested_account_id']!==null){$account=['id'=>(string)$row['suggested_account_id'],'code'=>(string)$row['suggested_code'],'name'=>(string)$row['suggested_name']];$accountValid=(bool)$row['suggested_active']&&!(bool)$row['suggested_control'];$reasons[]=$accountValid?'A suggestion exists but has not been reviewed.':'The suggested account is inactive or protected.';$source=(string)$row['suggestion_source'];if(in_array($source,['rule','history'],true))$reasons[]='Company rule/history evidence affected ranking only; it does not authorize posting.';elseif($source==='ai')$reasons[]='A prior Connected Intelligence suggestion is ranking evidence only; Native Intelligence still requires review.';$severity=$accountValid?'warning':'critical';$confidence=min(9900,max(0,(int)$row['confidence']*100));}
        else{$reasons[]='No account category has been selected.';$severity='warning';$confidence=0;}
        if(!in_array((string)$row['tax_code'],$validTax,true)){$reasons[]='The stored tax code is not supported by the current posting service.';$severity='critical';$accountValid=false;}
        if((int)$row['confidence']<$confidenceCutoff){$reasons[]='The available category evidence is below the company review threshold.';$severity=$severity==='critical'?'critical':'warning';$confidence=min($confidence,(int)$row['confidence']*100);}
        $days=max(0,(int)$today->diff(new DateTimeImmutable((string)$row['transaction_date']))->format('%a'));
        if($days>$staleDays){$reasons[]='The imported transaction has remained unreviewed beyond the company age threshold.';$severity=$severity==='critical'?'critical':'warning';}
        if(abs((int)$row['amount_cents'])>=$unusual){$reasons[]='The absolute amount exceeds the company materiality threshold multiplied by the configured unusual-amount factor.';$severity=$severity==='critical'?'critical':'warning';}
        if(!$reasons)$reasons[]='This imported transaction is still waiting for review.';
        $records=[['type'=>'bank_transaction','id'=>(string)$row['id'],'revisionHash'=>hash('sha256',implode('|',[(string)$row['source_hash'],(string)$row['status'],(string)$row['updated_at']]))]];
        $evidence=['records'=>$records,'affectedCount'=>1,'bankAccountId'=>(string)$row['bank_account_id'],'transactionDate'=>(string)$row['transaction_date'],'amountCents'=>(int)$row['amount_cents'],'daysUnreviewed'=>$days,'confidencePercent'=>(int)$row['confidence'],'suggestionSource'=>(string)$row['suggestion_source'],'rankingEvidenceOnly'=>in_array((string)$row['suggestion_source'],['rule','history','ai'],true),'reasonCodes'=>array_values(array_map(static fn(string $reason):string=>hash('sha256',$reason),$reasons)),'reasons'=>$reasons];
        $inputs=[];$actionId='nav.bank_review';
        if($accountValid&&$account!==null){$actionId='bank.transactions.categorize';$inputs=['account'=>$account,'taxTreatment'=>(string)$row['tax_code']];}
        $title=$severity==='critical'?'Bank transaction needs a corrected review':($accountValid?'Bank transaction is ready for category review':'Bank transaction needs bookkeeping review');
        $findings[]=native_agent_finding_spec('bookkeeping','bank_transaction_review',(string)$row['id'],$severity,$confidence,$title,implode(' ',$reasons),$evidence,$actionId,$inputs,(string)$row['transaction_date'].' 00:00:00');
    }
    if(!$cursorValue){
        $dup=db()->prepare("SELECT bank_account_id,transaction_date,amount_cents,GROUP_CONCAT(id ORDER BY id SEPARATOR ',') ids,COUNT(*) duplicate_count FROM bank_transactions WHERE company_id=? AND status='pending' GROUP BY bank_account_id,transaction_date,amount_cents,COALESCE(NULLIF(normalized_merchant,''),LOWER(TRIM(description))) HAVING COUNT(*)>1 ORDER BY transaction_date DESC,bank_account_id LIMIT 50");$dup->execute([$companyId]);
        foreach($dup->fetchAll() as $group){$ids=array_slice(array_values(array_filter(explode(',',(string)$group['ids']))),0,20);sort($ids,SORT_STRING);$records=array_map(static fn(string $id):array=>['type'=>'bank_transaction','id'=>$id],$ids);$evidence=['records'=>$records,'affectedCount'=>(int)$group['duplicate_count'],'bankAccountId'=>(string)$group['bank_account_id'],'transactionDate'=>(string)$group['transaction_date'],'amountCents'=>(int)$group['amount_cents'],'comparison'=>'same account, date, amount and normalized description'];$findings[]=native_agent_finding_spec('bookkeeping','likely_duplicate_bank_transactions',implode('|',$ids),'warning',9000,'Likely duplicate imported transactions','Multiple pending imported rows share the same financial account, date, exact-cent amount and normalized description. Review the source evidence before posting or excluding anything.',$evidence,'nav.bank_review',[],(string)$group['transaction_date'].' 00:00:00');}
        $cutoff=(new DateTimeImmutable(canadian_today()))->modify('-'.$staleDays.' days')->format('Y-m-d');$batches=db()->prepare("SELECT id,bank_account_id,status,row_count,duplicate_count,created_at FROM import_batches WHERE company_id=? AND status IN ('uploaded','extracted','needs_review') AND created_at<? ORDER BY created_at LIMIT 50");$batches->execute([$companyId,$cutoff.' 00:00:00']);
        foreach($batches->fetchAll() as $batch){$evidence=['records'=>[['type'=>'import_batch','id'=>(string)$batch['id']]],'affectedCount'=>(int)$batch['row_count'],'bankAccountId'=>(string)$batch['bank_account_id'],'batchStatus'=>(string)$batch['status'],'rowCount'=>(int)$batch['row_count'],'duplicateCount'=>(int)$batch['duplicate_count'],'ageThresholdDays'=>$staleDays];$findings[]=native_agent_finding_spec('bookkeeping','stale_import_batch',(string)$batch['id'],'warning',10000,'Bank import batch is still waiting','This import batch is older than the company review threshold and has not reached a completed accounting state.',$evidence,'nav.bank_review',[],(string)$batch['created_at']);}
    }
    $next=$hasMore&&$rows?native_agent_json([(string)end($rows)['updated_at'],(string)end($rows)['id']]):'';
    return ['sourceRevisionHash'=>$sourceRevision,'findings'=>$findings,'processedCount'=>count($rows),'cursor'=>['current'=>$cursor,'next'=>$next,'cycleComplete'=>!$hasMore],'summary'=>['pendingRowsProcessed'=>count($rows),'findingCandidates'=>count($findings),'accountingWrites'=>0,'providerAttempts'=>0]];
}

function native_agent_reconciliation_collect(array $company,array $policy,string $cursor,string $sourceRevision,array $context=[]): array
{
    $companyId=(string)$company['id'];$limit=(int)$policy['thresholds']['reconciliationAccountBatch'];$params=[$companyId];$sql="SELECT id,name,last_reconciled_date FROM bank_accounts WHERE company_id=? AND active=1 AND account_type IN ('bank','credit_card')";
    if($cursor!==''){$sql.=' AND id>?';$params[]=$cursor;}$sql.=' ORDER BY id LIMIT '.($limit+1);$stmt=db()->prepare($sql);$stmt->execute($params);$accounts=$stmt->fetchAll();$hasMore=count($accounts)>$limit;if($hasMore)$accounts=array_slice($accounts,0,$limit);
    $today=canadian_today();$fallback=(new DateTimeImmutable($today))->modify('-'.max(90,(int)$policy['staleDays']*3).' days')->format('Y-m-d');$findings=[];$strong=(int)$policy['thresholds']['reconciliationStrongScore'];$autoMatchQueued=0;$staleCutoff=(new DateTimeImmutable($today))->modify('-'.(int)$policy['staleDays'].' days')->format('Y-m-d');
    foreach($accounts as $account){
        $start=(string)($account['last_reconciled_date']??'');$start=$start!==''?(new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d'):$fallback;if($start>$today)$start=$today;
        // The existing reconciliation engine remains the single scoring
        // authority. This internal background call skips only the HTTP-user
        // permission assertion; it is read-only and company-scoped here.
        $data=operations_reconciliation_suggestions_data($company,(string)$account['id'],$start,$today,[],[],false);
        $proposals=array_values((array)$data['proposals']);
        foreach($proposals as $proposal){
            if((int)$proposal['score']<$strong)continue;
            $bankIds=array_values(array_map('strval',(array)$proposal['bankTransactionIds']));$bookIds=array_values(array_map('strval',(array)$proposal['journalEntryIds']));sort($bankIds,SORT_STRING);sort($bookIds,SORT_STRING);
            $alternatives=[];
            foreach($proposals as $candidate){
                if((string)$candidate['id']===(string)$proposal['id'])continue;
                $candidateBank=array_values(array_map('strval',(array)$candidate['bankTransactionIds']));$candidateBook=array_values(array_map('strval',(array)$candidate['journalEntryIds']));
                if(!array_intersect($bankIds,$candidateBank)&&!array_intersect($bookIds,$candidateBook))continue;
                $alternatives[]=['proposalId'=>(string)$candidate['id'],'kind'=>(string)$candidate['kind'],'score'=>(int)$candidate['score'],'reason'=>(string)$candidate['reason'],'bankTransactionIds'=>$candidateBank,'journalEntryIds'=>$candidateBook,'differenceCents'=>(int)$candidate['differenceCents']];
            }
            usort($alternatives,static fn(array $left,array $right):int=>$right['score']<=>$left['score']);$alternatives=array_slice($alternatives,0,3);
            $records=[];foreach($bankIds as $id)$records[]=['type'=>'bank_transaction','id'=>$id];foreach($bookIds as $id)$records[]=['type'=>'journal_entry','id'=>$id];
            $queueEligible=(bool)$policy['thresholds']['autoMatchPreviewEnabled']&&(int)$proposal['score']>=(int)$policy['thresholds']['autoMatchMinimumScore']&&empty($proposal['conflicts'])&&(int)$proposal['differenceCents']===0;
            $evidence=['records'=>$records,'affectedCount'=>count($records),'bankAccountId'=>(string)$account['id'],'period'=>['start'=>$start,'end'=>$today],'proposalId'=>(string)$proposal['id'],'matchKind'=>(string)$proposal['kind'],'score'=>(int)$proposal['score'],'scoreTiers'=>$proposal['scoreTiers']??[],'reason'=>(string)$proposal['reason'],'bankTransactionIds'=>$bankIds,'journalEntryIds'=>$bookIds,'bankTotalCents'=>(int)$proposal['bankTotalCents'],'bookTotalCents'=>(int)$proposal['bookTotalCents'],'differenceCents'=>(int)$proposal['differenceCents'],'groupBounds'=>['bankCount'=>count($bankIds),'bookCount'=>count($bookIds),'maximumPerSide'=>4],'alternatives'=>$alternatives,'autoMatchPreviewEligible'=>$queueEligible,'autoMatchCommit'=>false];
            $severity=(string)$proposal['kind']==='exact_source'?'info':'warning';$title=(string)$proposal['kind']==='exact_source'?'Exact source-linked reconciliation pair':'Strong reconciliation candidate';
            $actionId=$queueEligible?'bank.matches.match_selected':'nav.reconciliation';$inputs=$queueEligible?['accountId'=>(string)$account['id'],'periodStart'=>$start,'periodEnd'=>$today,'bankTransactionIds'=>$bankIds,'journalEntryIds'=>$bookIds]:[];if($queueEligible)$autoMatchQueued++;
            $findings[]=native_agent_finding_spec('reconciliation','match_candidate',implode(',',$bankIds).'::'.implode(',',$bookIds),$severity,(int)$proposal['score']*100,$title,(string)$proposal['reason'].'. This is a read-only suggestion; matching still requires the existing protected reconciliation workflow.',$evidence,$actionId,$inputs,$today.' 00:00:00');
        }
        foreach((array)$data['unmatchedBank'] as $row){if((string)$row['date']>$staleCutoff)continue;$evidence=['records'=>[['type'=>'bank_transaction','id'=>(string)$row['id']]],'affectedCount'=>1,'bankAccountId'=>(string)$account['id'],'period'=>['start'=>$start,'end'=>$today],'transactionDate'=>(string)$row['date'],'amountCents'=>(int)$row['amountCents'],'staleDays'=>(int)$policy['staleDays']];$findings[]=native_agent_finding_spec('reconciliation','stale_unreconciled_bank_item',(string)$row['id'],'warning',10000,'Stale unreconciled bank item','This current-company statement item is older than the company age threshold and has no current deterministic match candidate.',$evidence,'nav.reconciliation',[],(string)$row['date'].' 00:00:00');}
        $bankTotal=array_sum(array_map(static fn(array $row):int=>(int)$row['amountCents'],(array)$data['unmatchedBank']));$bookTotal=array_sum(array_map(static fn(array $row):int=>(int)$row['amountCents'],(array)$data['unmatchedBooks']));$difference=$bankTotal-$bookTotal;
        if($difference!==0){$evidence=['records'=>[['type'=>'bank_account','id'=>(string)$account['id']]],'affectedCount'=>(int)$data['summary']['availableBankItems']+(int)$data['summary']['bookEntries'],'bankAccountId'=>(string)$account['id'],'period'=>['start'=>$start,'end'=>$today],'unmatchedBankTotalCents'=>$bankTotal,'unmatchedBookTotalCents'=>$bookTotal,'unexplainedDifferenceCents'=>$difference,'exactCents'=>true];$severity=abs($difference)>=(int)$policy['materialityCents']?'critical':'warning';$findings[]=native_agent_finding_spec('reconciliation','unexplained_difference',(string)$account['id'].'|'.$start.'|'.$today,$severity,10000,'Unexplained bank-to-books difference','The unmatched current-company bank total does not equal the unmatched book total for this bounded reconciliation period. Tegh has not created an adjustment.',$evidence,'nav.reconciliation',[],$today.' 00:00:00');}
    }
    $next=$hasMore&&$accounts?(string)end($accounts)['id']:'';
    return ['sourceRevisionHash'=>$sourceRevision,'findings'=>$findings,'processedCount'=>count($accounts),'cursor'=>['current'=>$cursor,'next'=>$next,'cycleComplete'=>!$hasMore],'summary'=>['accountsProcessed'=>count($accounts),'findingCandidates'=>count($findings),'autoMatchPreviewQueued'=>$autoMatchQueued,'autoMatchPreviewEnabled'=>(bool)$policy['thresholds']['autoMatchPreviewEnabled'],'accountingWrites'=>0,'providerAttempts'=>0,'scoringAuthority'=>'operations_reconciliation_suggestions_data']];
}

function native_agent_close_item(string $key,string $label,string $status,string $explanation,array $facts=[]): array
{
    $allowed=['Passed','Needs Attention','Waiting for User','Not Applicable','Unable to Verify'];if(!in_array($status,$allowed,true))throw new InvalidArgumentException('Unsupported close status.');return ['key'=>$key,'label'=>$label,'status'=>$status,'explanation'=>$explanation,'facts'=>$facts];
}

function native_agent_month_end_close_collect(array $company,array $policy,string $sourceRevision,array $context=[]): array
{
    $companyId=(string)$company['id'];[$start,$end]=native_agent_close_period((string)($context['period']??''));$items=[];
    $q=db()->prepare("SELECT COALESCE(SUM(jl.debit_cents),0) debits,COALESCE(SUM(jl.credit_cents),0) credits FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id=? AND je.status='posted' AND je.entry_date<=?");$q->execute([$companyId,$end]);$tb=$q->fetch();$debits=(int)$tb['debits'];$credits=(int)$tb['credits'];$difference=$debits-$credits;$items[]=native_agent_close_item('trial_balance','Trial Balance integrity',$difference===0?'Passed':'Needs Attention',$difference===0?'Posted journal debits and credits balance exactly through the proposed period end.':'Posted journal debits and credits do not balance through the proposed period end.',['debitCents'=>$debits,'creditCents'=>$credits,'differenceCents'=>$difference]);
    $q=db()->prepare("SELECT ba.id,ba.name,MAX(CASE WHEN r.status='complete' THEN r.period_end END) reconciled_through FROM bank_accounts ba LEFT JOIN reconciliations r ON r.bank_account_id=ba.id AND r.company_id=ba.company_id WHERE ba.company_id=? AND ba.active=1 GROUP BY ba.id,ba.name ORDER BY ba.name");$q->execute([$companyId]);$bankRows=$q->fetchAll();$unready=[];foreach($bankRows as $bank)if((string)($bank['reconciled_through']??'')<$end)$unready[]=['bankAccountId'=>(string)$bank['id'],'reconciledThrough'=>$bank['reconciled_through']];$items[]=native_agent_close_item('bank_reconciliation','Bank reconciliation through period end',!$bankRows?'Not Applicable':($unready?'Needs Attention':'Passed'),!$bankRows?'No active financial account is configured.':($unready?'One or more active financial accounts are not reconciled through the proposed period end.':'Every active financial account has a completed reconciliation through the proposed period end.'),['activeBankAccounts'=>count($bankRows),'unreadyAccounts'=>$unready]);
    $q=db()->prepare("SELECT COUNT(*) item_count,COALESCE(SUM(ABS(amount_cents)),0) amount_cents FROM bank_transactions WHERE company_id=? AND status='pending' AND transaction_date<=?");$q->execute([$companyId,$end]);$pendingBank=$q->fetch();$items[]=native_agent_close_item('unreviewed_bank','Unreviewed bank transactions',(int)$pendingBank['item_count']?'Needs Attention':'Passed',(int)$pendingBank['item_count']?'Imported bank transactions dated on or before period end still need review.':'No imported bank transactions dated on or before period end remain pending.',['count'=>(int)$pendingBank['item_count'],'absoluteAmountCents'=>(int)$pendingBank['amount_cents']]);
    $q=db()->prepare("SELECT (SELECT COUNT(*) FROM invoices WHERE company_id=? AND status='draft' AND issue_date<=?) invoice_drafts,(SELECT COUNT(*) FROM bills WHERE company_id=? AND status='draft' AND bill_date<=?) bill_drafts");$q->execute([$companyId,$end,$companyId,$end]);$drafts=$q->fetch();$draftCount=(int)$drafts['invoice_drafts']+(int)$drafts['bill_drafts'];$items[]=native_agent_close_item('draft_documents','Draft and unposted source documents',$draftCount?'Needs Attention':'Passed',$draftCount?'Draft customer or vendor documents dated on or before period end require review.':'No supported draft customer or vendor documents dated on or before period end remain.',['invoiceDrafts'=>(int)$drafts['invoice_drafts'],'billDrafts'=>(int)$drafts['bill_drafts'],'journalDrafts'=>'Not Applicable — journal_entries supports posted/reversed states only']);
    $q=db()->prepare("SELECT COUNT(*) item_count,COALESCE(SUM(balance_cents),0) balance_cents FROM invoices WHERE company_id=? AND status='sent' AND balance_cents>0 AND due_date<=?");$q->execute([$companyId,$end]);$ar=$q->fetch();$q=db()->prepare("SELECT COUNT(*) item_count,COALESCE(SUM(balance_cents),0) balance_cents FROM bills WHERE company_id=? AND status='open' AND balance_cents>0 AND due_date<=?");$q->execute([$companyId,$end]);$ap=$q->fetch();$agedCount=(int)$ar['item_count']+(int)$ap['item_count'];$items[]=native_agent_close_item('aged_ar_ap','Aged receivables and payables',$agedCount?'Waiting for User':'Passed',$agedCount?'Open receivables or payables due by period end require a human collectability/payment review.':'No currently open receivable or payable is due by the proposed period end.',['receivableCount'=>(int)$ar['item_count'],'receivableCents'=>(int)$ar['balance_cents'],'payableCount'=>(int)$ap['item_count'],'payableCents'=>(int)$ap['balance_cents'],'historicalLimitation'=>'Balances reflect current document state; Tegh does not invent historical settlement states.']);
    $q=db()->prepare("SELECT a.id,a.code,a.name,COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<=? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) balance_cents FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE a.company_id=? AND a.active=1 AND (a.code='9999' OR LOWER(a.name) LIKE '%suspense%' OR LOWER(a.name) LIKE '%clearing%') GROUP BY a.id,a.code,a.name ORDER BY a.code");$q->execute([$end,$companyId]);$controlRows=$q->fetchAll();$exceptions=array_values(array_filter(array_map(static fn(array $row):array=>['accountId'=>(string)$row['id'],'code'=>(string)$row['code'],'balanceCents'=>(int)$row['balance_cents']],$controlRows),static fn(array $row):bool=>$row['balanceCents']!==0));$items[]=native_agent_close_item('suspense_controls','Suspense, clearing and Opening Balance Control',$exceptions?'Needs Attention':($controlRows?'Passed':'Not Applicable'),$exceptions?'One or more identified suspense, clearing or Opening Balance Control accounts has a non-zero posted balance.':($controlRows?'The supported suspense/control accounts have zero posted balances through period end.':'No supported suspense, clearing or Opening Balance Control account was identified.'),['checkedAccounts'=>count($controlRows),'exceptions'=>$exceptions]);
    $q=db()->prepare("SELECT COUNT(*) run_count,COALESCE(SUM(CASE WHEN status IN ('draft','verified') OR gl_status IN ('not_ready','ready_to_post') THEN 1 ELSE 0 END),0) incomplete_count FROM payroll_runs WHERE company_id=? AND period_end<=?");$q->execute([$companyId,$end]);$payroll=$q->fetch();$payrollApplicable=in_array((string)($company['module_mode']??''),['payroll','both'],true);$items[]=native_agent_close_item('payroll','Payroll runs and posting status',!$payrollApplicable?'Not Applicable':((int)$payroll['incomplete_count']?'Needs Attention':'Passed'),!$payrollApplicable?'Payroll is not enabled for this company.':((int)$payroll['incomplete_count']?'Payroll runs through period end remain draft, verified or not fully posted.':'Supported payroll runs through period end have no incomplete GL state.'),['runCount'=>(int)$payroll['run_count'],'incompleteCount'=>(int)$payroll['incomplete_count']]);
    $liabilityCodes=['2100','2300','2310','2320','2330','2340','2350'];$place=implode(',',array_fill(0,count($liabilityCodes),'?'));$q=db()->prepare("SELECT a.id,a.code,COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<=? THEN jl.credit_cents-jl.debit_cents ELSE 0 END),0) credit_balance_cents FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE a.company_id=? AND a.active=1 AND a.code IN ($place) GROUP BY a.id,a.code ORDER BY a.code");$q->execute(array_merge([$end,$companyId],$liabilityCodes));$liabilities=array_map(static fn(array $row):array=>['accountId'=>(string)$row['id'],'code'=>(string)$row['code'],'creditBalanceCents'=>(int)$row['credit_balance_cents']],$q->fetchAll());$nonzero=array_values(array_filter($liabilities,static fn(array $row):bool=>$row['creditBalanceCents']!==0));$items[]=native_agent_close_item('tax_payroll_liabilities','Payroll and sales-tax liabilities',!$liabilities?'Not Applicable':($nonzero?'Waiting for User':'Passed'),!$liabilities?'No supported default payroll or sales-tax liability accounts are active.':($nonzero?'Supported liability accounts have non-zero posted balances; confirm filing and remittance status.':'Supported liability accounts have zero posted balances through period end.'),['accounts'=>$nonzero,'checkedAccountCount'=>count($liabilities)]);
    $q=db()->prepare("SELECT id FROM period_locks WHERE company_id=? AND locked=1 AND period_start<=? AND period_end>=? LIMIT 1");$q->execute([$companyId,$start,$end]);$locked=(bool)$q->fetchColumn();$items[]=native_agent_close_item('period_lock','Period lock',$locked?'Passed':'Waiting for User',$locked?'An active company period lock covers the proposed close period.':'Tegh has not locked the proposed period. Locking remains a separate permission-protected user action.',['periodStart'=>$start,'periodEnd'=>$end,'locked'=>$locked,'formalCloseService'=>false]);
    $q=db()->prepare("SELECT a.id,a.code,a.account_type,a.normal_balance,SUM(jl.debit_cents-jl.credit_cents) net_debit_cents FROM accounts a JOIN journal_lines jl ON jl.account_id=a.id JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id WHERE a.company_id=? AND a.active=1 AND je.status='posted' AND je.entry_date<=? GROUP BY a.id,a.code,a.account_type,a.normal_balance HAVING (a.normal_balance='debit' AND SUM(jl.debit_cents-jl.credit_cents)<?) OR (a.normal_balance='credit' AND SUM(jl.debit_cents-jl.credit_cents)>?) ORDER BY ABS(SUM(jl.debit_cents-jl.credit_cents)) DESC LIMIT 25");$q->execute([$companyId,$end,-(int)$policy['materialityCents'],(int)$policy['materialityCents']]);$negative=array_map(static fn(array $row):array=>['accountId'=>(string)$row['id'],'code'=>(string)$row['code'],'normalBalance'=>(string)$row['normal_balance'],'netDebitCents'=>(int)$row['net_debit_cents']],$q->fetchAll());$items[]=native_agent_close_item('unusual_balances','Material unusual normal-balance exceptions',$negative?'Needs Attention':'Passed',$negative?'One or more accounts exceeds materiality on the side opposite its configured normal balance. This is a transparent review flag, not a proposed adjustment.':'No account exceeds materiality on the side opposite its configured normal balance.',['materialityCents'=>(int)$policy['materialityCents'],'exceptions'=>$negative]);
    $previousEnd=(new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');$materiality=max(1,(int)$policy['materialityCents']);
    $q=db()->prepare("SELECT a.id,a.code,a.name,a.account_type,COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<=? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) current_balance_cents,COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<=? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) previous_balance_cents FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id WHERE a.company_id=? AND a.active=1 GROUP BY a.id,a.code,a.name,a.account_type ORDER BY a.code");$q->execute([$end,$previousEnd,$companyId]);$changed=[];
    foreach($q->fetchAll() as $row){$current=(int)$row['current_balance_cents'];$previous=(int)$row['previous_balance_cents'];$delta=$current-$previous;if(abs($delta)<$materiality)continue;if($previous!==0&&abs($delta)<abs($previous))continue;$changed[]=['accountId'=>(string)$row['id'],'code'=>(string)$row['code'],'name'=>(string)$row['name'],'accountType'=>(string)$row['account_type'],'previousBalanceCents'=>$previous,'currentBalanceCents'=>$current,'changeCents'=>$delta];if(count($changed)>=25)break;}
    $items[]=native_agent_close_item('material_balance_changes','Material period-over-period balance changes',$changed?'Needs Attention':'Passed',$changed?'One or more closing balances changed by at least the company materiality threshold and by at least 100% of the prior closing balance. This is a transparent review flag, not an adjustment.':'No supported closing balance met the transparent material-change rule.',['comparisonEnd'=>$previousEnd,'currentEnd'=>$end,'materialityCents'=>$materiality,'relativeRule'=>'absolute change is at least 100% of the absolute prior closing balance, or the prior balance was zero','exceptions'=>$changed]);
    $items[]=native_agent_close_item('evidence_completeness','External evidence completeness','Unable to Verify','Tegh can verify records that exist in the package, but cannot prove that every external statement, receipt or source document has been received. A user must complete this review.',['automaticPassProhibited'=>true]);
    $counts=array_fill_keys(['Passed','Needs Attention','Waiting for User','Not Applicable','Unable to Verify'],0);foreach($items as $item)$counts[$item['status']]++;$ready=$counts['Needs Attention']===0&&$counts['Waiting for User']===0&&$counts['Unable to Verify']===0;$status=$ready?'Ready for Review':'Needs Attention';$severity=$counts['Needs Attention']>0?'critical':($counts['Unable to Verify']>0||$counts['Waiting for User']>0?'warning':'info');$evidence=['records'=>[['type'=>'company','id'=>$companyId]],'affectedCount'=>count($items),'period'=>['start'=>$start,'end'=>$end],'checklist'=>$items,'statusCounts'=>$counts,'readiness'=>$status,'ready'=>$ready,'formalCloseSupported'=>false,'accountingWrites'=>0,'providerAttempts'=>0];$finding=native_agent_finding_spec('month_end_close','close_readiness',$start.'|'.$end,$severity,10000,'Month-end close readiness: '.$status,'Tegh built this checklist only from verified current-company services. Unable to Verify is never treated as Passed, and this result does not formally close the period.',$evidence,'nav.month_end_close',[],$end.' 23:59:59');
    return ['sourceRevisionHash'=>$sourceRevision,'findings'=>[$finding],'processedCount'=>count($items),'cursor'=>['current'=>'','next'=>'','cycleComplete'=>true],'summary'=>['period'=>['start'=>$start,'end'=>$end],'readiness'=>$status,'statusCounts'=>$counts,'accountingWrites'=>0,'providerAttempts'=>0]];
}

function native_agent_cursor_state(string $companyId,string $agent,array $policy,string $sourceRevision,array $context=[]): array
{
    $stmt=db()->prepare("SELECT id,cursor_json,summary_json,status FROM native_agent_runs WHERE company_id=? AND agent_type=? AND policy_hash=? AND source_revision_hash=? AND status IN ('completed','partial') ORDER BY completed_at DESC,id DESC LIMIT 1");$stmt->execute([$companyId,$agent,(string)$policy['policyHash'],$sourceRevision]);$row=$stmt->fetch();if(!$row)return ['cursor'=>'','complete'=>false,'prior'=>null];$cursor=json_decode((string)$row['cursor_json'],true);if(!is_array($cursor))$cursor=[];return ['cursor'=>(string)($cursor['next']??''),'complete'=>(bool)($cursor['cycleComplete']??false),'prior'=>$row];
}

function native_agent_severity_meets(string $severity,string $minimum): bool
{
    $rank=['info'=>1,'warning'=>2,'critical'=>3];return ($rank[$severity]??0)>=($rank[$minimum]??2);
}

function native_agent_finding_upsert(array $company,array $policy,string $runId,string $sourceRevision,array $spec): array
{
    $companyId=(string)$company['id'];$stmt=db()->prepare('SELECT * FROM native_agent_findings WHERE company_id=? AND fingerprint=? FOR UPDATE');$stmt->execute([$companyId,$spec['fingerprint']]);$existing=$stmt->fetch();$now=gmdate('Y-m-d H:i:s');$notify=false;
    if($existing){
        $state=(string)$existing['state'];if(in_array($state,['resolved','superseded'],true))$state='open';if($state==='snoozed'&&($existing['snoozed_until']===null||(string)$existing['snoozed_until']<=$now))$state='open';
        $notify=$state==='open'&&((string)$existing['evidence_hash']!==(string)$spec['evidenceHash']||(string)$existing['severity']!==(string)$spec['severity']||(string)$existing['state']!=='open');
        db()->prepare('UPDATE native_agent_findings SET last_seen_run_id=?,agent_type=?,finding_type=?,severity=?,confidence_bps=?,title=?,explanation=?,evidence_json=?,evidence_hash=?,source_revision_hash=?,source_date=?,policy_revision=?,affected_count=?,proposed_action_id=?,proposed_inputs_json=?,execution_class=?,resolution_note=IF(state IN (\'resolved\',\'superseded\'),\'\',resolution_note),state=?,last_seen_at=?,resolved_at=NULL,resolved_by=NULL WHERE id=? AND company_id=?')
            ->execute([$runId,$spec['agentType'],$spec['findingType'],$spec['severity'],$spec['confidenceBps'],$spec['title'],$spec['explanation'],native_agent_json($spec['evidence']),$spec['evidenceHash'],$sourceRevision,$spec['sourceDate'],(int)$policy['revision'],$spec['affectedCount'],$spec['proposedActionId'],native_agent_json($spec['proposedInputs']),$spec['executionClass'],$state,$now,(string)$existing['id'],$companyId]);$id=(string)$existing['id'];
    }else{
        $id=new_id('nafinding');db()->prepare('INSERT INTO native_agent_findings (id,company_id,last_seen_run_id,agent_type,finding_type,fingerprint,severity,confidence_bps,title,explanation,evidence_json,evidence_hash,source_revision_hash,source_date,policy_revision,affected_count,proposed_action_id,proposed_inputs_json,execution_class,state,first_seen_at,last_seen_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'open\',?,?)')
            ->execute([$id,$companyId,$runId,$spec['agentType'],$spec['findingType'],$spec['fingerprint'],$spec['severity'],$spec['confidenceBps'],$spec['title'],$spec['explanation'],native_agent_json($spec['evidence']),$spec['evidenceHash'],$sourceRevision,$spec['sourceDate'],(int)$policy['revision'],$spec['affectedCount'],$spec['proposedActionId'],native_agent_json($spec['proposedInputs']),$spec['executionClass'],$now,$now]);$notify=true;
    }
    $notificationCount=0;if($notify&&native_agent_severity_meets((string)$spec['severity'],(string)$policy['notificationMinSeverity'])){$notificationId=platform_notification_create($companyId,null,'native_agent_finding',(string)$spec['title'],mb_substr((string)$spec['explanation'],0,1000),'nav:agent-center',(string)$spec['severity'],'native-agent:'.$spec['fingerprint'],'Open Agent Center');if($notificationId!=='')$notificationCount=1;}
    return ['id'=>$id,'notificationCount'=>$notificationCount,'new'=>$existing===false];
}

function native_agent_resolve_unseen(string $companyId,string $agent,string $sourceRevision): int
{
    $stmt=db()->prepare("UPDATE native_agent_findings SET state='resolved',resolved_at=UTC_TIMESTAMP(),resolved_by=NULL,resolution_note='The deterministic source condition is no longer present.' WHERE company_id=? AND agent_type=? AND state IN ('open','snoozed','dismissed') AND source_revision_hash<>?");$stmt->execute([$companyId,$agent,$sourceRevision]);return $stmt->rowCount();
}

function native_agent_lease_acquire(string $leaseName,string $owner,int $seconds): bool
{
    $seconds=max(30,min(300,$seconds));
    db()->prepare("INSERT INTO native_agent_leases (lease_name,lease_owner,lease_expires_at,lease_renewed_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL $seconds SECOND),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE lease_owner=IF(lease_expires_at<=UTC_TIMESTAMP() OR lease_owner=VALUES(lease_owner),VALUES(lease_owner),lease_owner),lease_expires_at=IF(lease_expires_at<=UTC_TIMESTAMP() OR lease_owner=VALUES(lease_owner),VALUES(lease_expires_at),lease_expires_at),lease_renewed_at=IF(lease_expires_at<=UTC_TIMESTAMP() OR lease_owner=VALUES(lease_owner),UTC_TIMESTAMP(),lease_renewed_at)")
        ->execute([$leaseName,$owner]);
    $stmt=db()->prepare('SELECT lease_owner,lease_expires_at FROM native_agent_leases WHERE lease_name=?');$stmt->execute([$leaseName]);$row=$stmt->fetch();
    return $row&&hash_equals($owner,(string)$row['lease_owner'])&&(string)$row['lease_expires_at']>gmdate('Y-m-d H:i:s');
}

function native_agent_lease_renew(string $leaseName,string $owner,int $seconds=90,?string $runId=null): bool
{
    $seconds=max(30,min(300,$seconds));
    // The same owner may safely renew after its timestamp elapsed provided no
    // other runner acquired the row. The owner predicate is the concurrency
    // boundary: once another runner takes the expired lease, this update
    // affects zero rows and the original run still stops safely.
    $stmt=db()->prepare("UPDATE native_agent_leases SET lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL $seconds SECOND),lease_renewed_at=UTC_TIMESTAMP() WHERE lease_name=? AND lease_owner=?");$stmt->execute([$leaseName,$owner]);
    // MySQL reports zero affected rows when an immediate same-second renewal
    // writes the same timestamp values. Re-read the lease instead of treating
    // that valid idempotent update as lost ownership.
    $check=db()->prepare('SELECT lease_owner,lease_expires_at FROM native_agent_leases WHERE lease_name=?');$check->execute([$leaseName]);$row=$check->fetch();
    if(!$row||!hash_equals($owner,(string)$row['lease_owner'])||(string)$row['lease_expires_at']<=gmdate('Y-m-d H:i:s'))return false;
    if($runId!==null)db()->prepare("UPDATE native_agent_runs SET lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL $seconds SECOND),lease_renewed_at=UTC_TIMESTAMP() WHERE id=? AND lease_owner=? AND status='running'")->execute([$runId,$owner]);
    return true;
}

function native_agent_lease_release(string $leaseName,string $owner): void
{
    db()->prepare('DELETE FROM native_agent_leases WHERE lease_name=? AND lease_owner=?')->execute([$leaseName,$owner]);
}

function native_agent_failure_record(string $companyId,string $agent,?string $runId,Throwable $error): void
{
    if(!schema_table_exists('native_agent_run_failures'))return;
    $hash=hash('sha256',$error::class.'|'.$error->getMessage());
    db()->prepare("INSERT INTO native_agent_run_failures (id,company_id,agent_type,run_id,error_code,error_hash,retry_count,next_retry_at,status,first_failed_at,last_failed_at) VALUES (?,?,?,?,'scanner_failed',?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE run_id=VALUES(run_id),error_code=VALUES(error_code),error_hash=VALUES(error_hash),retry_count=retry_count+1,next_retry_at=TIMESTAMPADD(MINUTE,LEAST(1440,5*POW(2,retry_count)),UTC_TIMESTAMP()),status='pending',resolved_at=NULL,last_failed_at=UTC_TIMESTAMP()")
        ->execute([new_id('nafailure'),$companyId,$agent,$runId,$hash]);
}

function native_agent_failure_resolve(string $companyId,string $agent): void
{
    if(schema_table_exists('native_agent_run_failures'))db()->prepare("UPDATE native_agent_run_failures SET status='resolved',resolved_at=UTC_TIMESTAMP() WHERE company_id=? AND agent_type=? AND status='pending'")->execute([$companyId,$agent]);
}

function native_agent_run_specialist(array $company,array $policy,string $agent,string $trigger,?array $user=null,array $context=[]): array
{
    if(!in_array($agent,NATIVE_AGENT_TYPES,true))throw new InvalidArgumentException('Unsupported native agent type.');$enabled=['bookkeeping'=>(bool)$policy['bookkeepingEnabled'],'reconciliation'=>(bool)$policy['reconciliationEnabled'],'month_end_close'=>(bool)$policy['monthEndCloseEnabled'],'accounts_payable'=>(bool)$policy['accountsPayableEnabled'],'accounts_receivable'=>(bool)$policy['accountsReceivableEnabled'],'financial_analyst'=>(bool)$policy['financialAnalystEnabled'],'payroll_tax'=>(bool)$policy['payrollTaxAgentEnabled']][$agent];if(!$enabled)return ['agentType'=>$agent,'status'=>'skipped','reason'=>'disabled'];
    if(function_exists('tegh_background_feature_allowed')){$feature=tegh_background_feature_for_agent($agent);if(!tegh_background_feature_allowed((string)$company['id'],$feature))return ['agentType'=>$agent,'status'=>'skipped','reason'=>'feature_not_entitled','requiredFeatureKey'=>$feature,'accountingWrites'=>0,'providerAttempts'=>0];}
    $companyId=(string)$company['id'];$sourceRevision=native_agent_source_revision($company,$agent,$context);$cursorState=native_agent_cursor_state($companyId,$agent,$policy,$sourceRevision,$context);if($cursorState['complete'])return ['agentType'=>$agent,'status'=>'completed','reused'=>true,'runId'=>(string)$cursorState['prior']['id'],'sourceRevisionHash'=>$sourceRevision];$cursor=(string)$cursorState['cursor'];$contextHash=native_agent_hash($context);$idempotency=hash('sha256',implode('|',[$companyId,$agent,(string)$policy['policyHash'],$sourceRevision,$cursor,$contextHash]));$leaseOwner=bin2hex(random_bytes(16));$leaseName='tegh_na_'.substr(hash('sha256',$companyId.'|'.$agent),0,48);$leaseAcquired=false;$runId='';
    try{
        try{$leaseAcquired=native_agent_lease_acquire($leaseName,$leaseOwner,90);}catch(Throwable $error){error_log('Native Agent specialist lease unavailable agent='.$agent.' class='.$error::class);}
        if(!$leaseAcquired)return ['agentType'=>$agent,'status'=>'skipped','reason'=>'lease_busy','accountingWrites'=>0,'providerAttempts'=>0];
        $existing=db()->prepare('SELECT * FROM native_agent_runs WHERE company_id=? AND agent_type=? AND idempotency_key=? LIMIT 1');$existing->execute([$companyId,$agent,$idempotency]);$row=$existing->fetch();
        if($row&&in_array((string)$row['status'],['completed','partial'],true))return ['agentType'=>$agent,'status'=>(string)$row['status'],'reused'=>true,'runId'=>(string)$row['id'],'sourceRevisionHash'=>$sourceRevision];
        if($row&&(string)$row['status']==='running'&&(string)$row['lease_expires_at']>gmdate('Y-m-d H:i:s'))return ['agentType'=>$agent,'status'=>'skipped','reason'=>'lease_busy','runId'=>(string)$row['id']];
        $runId=$row?(string)$row['id']:new_id('narun');
        if($row)db()->prepare("UPDATE native_agent_runs SET trigger_type=?,initiated_by=?,status='running',lease_owner=?,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 SECOND),lease_renewed_at=UTC_TIMESTAMP(),error_code=NULL,error_message=NULL,started_at=UTC_TIMESTAMP(),completed_at=NULL WHERE id=? AND company_id=?")->execute([$trigger,$user['id']??null,$leaseOwner,$runId,$companyId]);
        else db()->prepare("INSERT INTO native_agent_runs (id,company_id,agent_type,trigger_type,initiated_by,status,idempotency_key,policy_revision,policy_hash,source_revision_hash,lease_owner,lease_expires_at,lease_renewed_at,cursor_json,summary_json,started_at) VALUES (?,?,?,?,?,'running',?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 SECOND),UTC_TIMESTAMP(),'{}','{}',UTC_TIMESTAMP())")->execute([$runId,$companyId,$agent,$trigger,$user['id']??null,$idempotency,(int)$policy['revision'],$policy['policyHash'],$sourceRevision,$leaseOwner]);
        if(!native_agent_lease_renew($leaseName,$leaseOwner,90,$runId))throw new RuntimeException('Native Agent specialist lease was lost before collection.');
        $result=match($agent){
            'bookkeeping'=>native_agent_bookkeeping_collect($company,$policy,$cursor,$sourceRevision),
            'reconciliation'=>native_agent_reconciliation_collect($company,$policy,$cursor,$sourceRevision,$context),
            'month_end_close'=>native_agent_month_end_close_collect($company,$policy,$sourceRevision,$context),
            'accounts_payable'=>native_agent_accounts_payable_collect($company,$policy,$cursor,$sourceRevision),
            'accounts_receivable'=>native_agent_accounts_receivable_collect($company,$policy,$cursor,$sourceRevision),
            'financial_analyst'=>native_agent_financial_analyst_collect($company,$policy,$sourceRevision),
            'payroll_tax'=>payroll_tax_agent_collect($company,$policy,$sourceRevision),
        };
        if(!native_agent_lease_renew($leaseName,$leaseOwner,90,$runId))throw new RuntimeException('Native Agent specialist lease expired during collection.');
        $sourceAfterCollection=native_agent_source_revision($company,$agent,$context);
        if(!hash_equals($sourceRevision,(string)$result['sourceRevisionHash'])||!hash_equals($sourceRevision,$sourceAfterCollection))throw new RuntimeException('Native Agent source revision changed during collection.');
        $pdo=db();$pdo->beginTransaction();$findingCount=0;$notificationCount=0;try{foreach($result['findings'] as $spec){$upsert=native_agent_finding_upsert($company,$policy,$runId,$sourceRevision,$spec);$findingCount++;$notificationCount+=(int)$upsert['notificationCount'];if($findingCount%50===0&&!native_agent_lease_renew($leaseName,$leaseOwner,90,$runId))throw new RuntimeException('Native Agent specialist lease expired while persisting findings.');}$resolved=empty($result['cursor']['cycleComplete'])?0:native_agent_resolve_unseen($companyId,$agent,$sourceRevision);$status=empty($result['cursor']['cycleComplete'])?'partial':'completed';$summary=array_merge((array)$result['summary'],['resolvedCount'=>$resolved,'cycleComplete'=>(bool)$result['cursor']['cycleComplete']]);db()->prepare('UPDATE native_agent_runs SET status=?,cursor_json=?,summary_json=?,processed_count=?,finding_count=?,notification_count=?,lease_expires_at=UTC_TIMESTAMP(),lease_renewed_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND lease_owner=?')->execute([$status,native_agent_json($result['cursor']),native_agent_json($summary),(int)$result['processedCount'],$findingCount,$notificationCount,$runId,$companyId,$leaseOwner]);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
        if($user){try{audit_event($user,$companyId,'native_agent.scan_completed','native_agent_run',$runId,['agentType'=>$agent,'triggerType'=>$trigger,'sourceRevisionHash'=>$sourceRevision,'policyRevision'=>(int)$policy['revision'],'findingCount'=>$findingCount,'notificationCount'=>$notificationCount,'accountingWrites'=>0,'providerAttempts'=>0]);}catch(Throwable $auditError){error_log('Native Agent user audit failed class='.$auditError::class);}}
        native_agent_failure_resolve($companyId,$agent);
        return ['agentType'=>$agent,'status'=>$status,'runId'=>$runId,'sourceRevisionHash'=>$sourceRevision,'processedCount'=>(int)$result['processedCount'],'findingCount'=>$findingCount,'notificationCount'=>$notificationCount,'cursor'=>$result['cursor'],'summary'=>$summary,'accountingWrites'=>0,'providerAttempts'=>0];
    }catch(Throwable $error){if($runId!==''){try{db()->prepare("UPDATE native_agent_runs SET status='failed',error_code='scanner_failed',error_message='The specialist scan could not complete safely.',lease_expires_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?")->execute([$runId,$companyId]);}catch(Throwable $ignored){}}try{native_agent_failure_record($companyId,$agent,$runId!==''?$runId:null,$error);}catch(Throwable $ignored){}error_log('Native Agent scan failed agent='.$agent.' class='.$error::class);throw $error;
    }finally{if($leaseAcquired){try{native_agent_lease_release($leaseName,$leaseOwner);}catch(Throwable $ignored){}}}
}

function native_agent_supervisor_run(array $company,array $policy,string $trigger,?array $user=null,array $agents=[],array $context=[]): array
{
    if(!in_array($trigger,['manual','scheduled','opportunistic'],true))throw new InvalidArgumentException('Unsupported Native Agent trigger.');
    if(!(bool)$policy['supervisorEnabled'])return ['status'=>'skipped','reason'=>'supervisor_disabled','runs'=>[],'accountingWrites'=>0,'providerAttempts'=>0];
    $agents=$agents?:NATIVE_AGENT_TYPES;$agents=array_values(array_unique(array_map('strval',$agents)));foreach($agents as $agent)if(!in_array($agent,NATIVE_AGENT_TYPES,true))fail('Choose a supported Native Agent specialist.',422,'native_agent_type_invalid');
    $companyId=(string)$company['id'];$leaseName='tegh_na_supervisor_'.substr(hash('sha256',$companyId),0,42);$leaseOwner=bin2hex(random_bytes(16));$leaseAcquired=false;
    try{
        try{$leaseAcquired=native_agent_lease_acquire($leaseName,$leaseOwner,120);}catch(Throwable $error){error_log('Native Agent supervisor lease unavailable class='.$error::class);}
        if(!$leaseAcquired)return ['status'=>'skipped','reason'=>'supervisor_lease_busy','runs'=>[],'accountingWrites'=>0,'providerAttempts'=>0];
        $source=[];foreach($agents as $agent){try{$source[$agent]=native_agent_source_revision($company,$agent,$context);}catch(Throwable){$source[$agent]='unavailable';}}$idempotency=hash('sha256',native_agent_json(['companyId'=>$companyId,'agents'=>$agents,'policyHash'=>$policy['policyHash'],'source'=>$source,'context'=>$context]));$existing=db()->prepare("SELECT * FROM native_agent_runs WHERE company_id=? AND agent_type='supervisor' AND idempotency_key=? AND status='completed' LIMIT 1");$existing->execute([$companyId,$idempotency]);$prior=$existing->fetch();if($prior){$summary=json_decode((string)$prior['summary_json'],true)?:[];$autonomy=['status'=>'not_run','attempted'=>0,'completed'=>0,'accountingWrites'=>0];if($user&&function_exists('payroll_tax_autonomy_apply_eligible')){try{$autonomy=payroll_tax_autonomy_apply_eligible($user,$company,10);}catch(Throwable){$autonomy=['status'=>'needs_review','attempted'=>0,'completed'=>0,'accountingWrites'=>0];}}return ['status'=>(string)$prior['status'],'runId'=>(string)$prior['id'],'reused'=>true,'runs'=>(array)($summary['runs']??[]),'autonomy'=>$autonomy,'accountingWrites'=>0,'providerAttempts'=>0];}
        $runId=new_id('narun');db()->prepare("INSERT INTO native_agent_runs (id,company_id,agent_type,trigger_type,initiated_by,status,idempotency_key,policy_revision,policy_hash,source_revision_hash,lease_owner,lease_expires_at,lease_renewed_at,cursor_json,summary_json,started_at) VALUES (?,?, 'supervisor',?,?, 'running',?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),UTC_TIMESTAMP(),'{}','{}',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=id")->execute([$runId,$companyId,$trigger,$user['id']??null,$idempotency,(int)$policy['revision'],$policy['policyHash'],native_agent_hash($source),$leaseOwner]);
        $actual=db()->prepare("SELECT id FROM native_agent_runs WHERE company_id=? AND agent_type='supervisor' AND idempotency_key=? LIMIT 1");$actual->execute([$companyId,$idempotency]);$runId=(string)($actual->fetchColumn()?:$runId);
        $runs=[];$failures=[];foreach($agents as $agent){if(!native_agent_lease_renew($leaseName,$leaseOwner,120,$runId))throw new RuntimeException('Native Agent supervisor lease expired during the run.');try{$runs[]=native_agent_run_specialist($company,$policy,$agent,$trigger,$user,$context);}catch(Throwable $error){$failures[]=['agentType'=>$agent,'code'=>'scanner_failed'];$runs[]=['agentType'=>$agent,'status'=>'failed','error'=>'The specialist scan could not complete safely.'];}}
        $hasMore=(bool)array_filter($runs,static fn(array $run):bool=>(string)($run['status']??'')==='partial');$status=($failures||$hasMore)?'partial':'completed';$summary=['runs'=>$runs,'failures'=>$failures,'hasMore'=>$hasMore,'accountingWrites'=>0,'providerAttempts'=>0,'policyRevision'=>(int)$policy['revision'],'policyHash'=>$policy['policyHash']];db()->prepare('UPDATE native_agent_runs SET status=?,summary_json=?,processed_count=?,finding_count=?,notification_count=?,lease_expires_at=UTC_TIMESTAMP(),lease_renewed_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP() WHERE company_id=? AND agent_type=\'supervisor\' AND idempotency_key=?')->execute([$status,native_agent_json($summary),array_sum(array_map(static fn(array $run):int=>(int)($run['processedCount']??0),$runs)),array_sum(array_map(static fn(array $run):int=>(int)($run['findingCount']??0),$runs)),array_sum(array_map(static fn(array $run):int=>(int)($run['notificationCount']??0),$runs)),$companyId,$idempotency]);
        $autonomy=['status'=>'not_run','attempted'=>0,'completed'=>0,'accountingWrites'=>0];if($user&&function_exists('payroll_tax_autonomy_apply_eligible')){try{$autonomy=payroll_tax_autonomy_apply_eligible($user,$company,10);}catch(Throwable $error){$autonomy=['status'=>'needs_review','attempted'=>0,'completed'=>0,'accountingWrites'=>0];error_log('Bounded autonomy batch stopped safely class='.$error::class);}}
        return ['status'=>$status,'runId'=>$runId,'runs'=>$runs,'failures'=>$failures,'autonomy'=>$autonomy,'accountingWrites'=>0,'providerAttempts'=>0];
    }finally{if($leaseAcquired){try{native_agent_lease_release($leaseName,$leaseOwner);}catch(Throwable $ignored){}}}
}

function native_agent_scheduler_secret(): string
{
    $secret=trim((string)(config('native_agent.scheduler_secret')??''));
    if(strlen($secret)<40||str_contains($secret,'REPLACE_WITH'))fail('Native Agent HTTPS scheduling is not configured.',503,'native_agent_scheduler_not_configured',false);
    return $secret;
}

function native_agent_scheduler_rate_limit(): void
{
    if(PHP_SAPI==='cli')return;
    $directory=rtrim((string)config('storage_path'),'/').'/runtime';
    if(!is_dir($directory)&&!@mkdir($directory,0700,true)&&!is_dir($directory))fail('Native Agent scheduler admission storage is unavailable.',503,'native_agent_scheduler_limiter_unavailable',false);
    $handle=@fopen($directory.'/native-agent-scheduler-rate.json','c+');if($handle===false||!flock($handle,LOCK_EX)){if(is_resource($handle))fclose($handle);fail('Native Agent scheduler admission is unavailable.',503,'native_agent_scheduler_limiter_unavailable',false);}
    try{
        $raw=stream_get_contents($handle);$state=json_decode(is_string($raw)?$raw:'',true);if(!is_array($state))$state=[];$now=time();$ipHash=client_ip_hash();
        foreach($state as $key=>$seen)if(!is_int($seen)||$seen<$now-300)unset($state[$key]);
        if(isset($state[$ipHash])&&$state[$ipHash]>$now-60)fail('Wait one minute before another scheduler request.',429,'native_agent_scheduler_rate_limited',false);
        $state[$ipHash]=$now;rewind($handle);ftruncate($handle,0);fwrite($handle,json_encode($state,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));fflush($handle);if(function_exists('fsync'))fsync($handle);
    }finally{flock($handle,LOCK_UN);fclose($handle);}
}

function native_agent_scheduler_authorize(): void
{
    require_method('POST');native_agent_scheduler_rate_limit();$https=strtolower((string)($_SERVER['HTTPS']??''));$forwarded=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??''));
    if(PHP_SAPI!=='cli'&&!in_array($https,['on','1'],true)&&$forwarded!=='https')fail('Native Agent scheduling requires HTTPS.',403,'native_agent_scheduler_https_required',false);
    $authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));$provided='';if(preg_match('/^Bearer\s+([^\s]+)$/i',$authorization,$match))$provided=(string)$match[1];if($provided==='')$provided=trim((string)($_SERVER['HTTP_X_TEGH_SCHEDULER_KEY']??''));
    $expected=native_agent_scheduler_secret();if($provided===''||!hash_equals($expected,$provided))fail('Native Agent scheduler authorization failed.',403,'native_agent_scheduler_unauthorized',false);
}

function native_agent_scheduler_execute(int $companyLimit=2,string $trigger='scheduled'): array
{
    if(!in_array($trigger,['scheduled','opportunistic'],true))throw new InvalidArgumentException('Unsupported scheduler trigger.');$companyLimit=max(1,min(5,$companyLimit));
    if(!schema_table_exists('native_agent_policies')||!schema_table_exists('native_agent_runs')||!schema_table_exists('native_agent_findings')||!schema_table_exists('native_agent_leases')||!schema_table_exists('native_agent_run_failures'))fail('Native Agent storage is not ready for scheduling.',503,'native_agent_schema_required',false);
    $stmt=db()->prepare("SELECT c.* FROM native_agent_policies p JOIN companies c ON c.id=p.company_id AND c.active=1 WHERE p.supervisor_enabled=1 AND p.cadence IN ('daily','weekly') ORDER BY COALESCE((SELECT MAX(r.completed_at) FROM native_agent_runs r WHERE r.company_id=c.id AND r.agent_type='supervisor' AND r.status IN ('completed','partial')), '1970-01-01') ASC,c.id LIMIT ".($companyLimit*4));$stmt->execute();$processed=[];$skipped=[];$failedCompanyIds=[];
    foreach($stmt->fetchAll() as $company){
        if(count($processed)>=$companyLimit)break;
        $companyId=(string)$company['id'];
        // This verification is deliberately independent of the company's
        // weekly/daily specialist cadence. A daily cron therefore rechecks up
        // to 5,000 stored journal hashes even when business scans are not due.
        try{$integrity=function_exists('journal_entry_integrity_scan')?journal_entry_integrity_scan($companyId,5000):['checked'=>0,'mismatches'=>[]];}
        catch(Throwable $error){$integrity=['checked'=>0,'mismatches'=>[],'errorCode'=>'journal_integrity_scan_failed'];$failedCompanyIds[]=$companyId;error_log('Nightly journal integrity scan failed class='.$error::class);}
        if(!empty($integrity['mismatches']))$failedCompanyIds[]=$companyId;
        $backoff=db()->prepare("SELECT MIN(next_retry_at) FROM native_agent_run_failures WHERE company_id=? AND status='pending' AND next_retry_at>UTC_TIMESTAMP()");$backoff->execute([$companyId]);$retryAt=$backoff->fetchColumn();
        if($retryAt!==false&&$retryAt!==null){$skipped[]=['companyId'=>$companyId,'reason'=>'failure_backoff','nextRetryAt'=>(string)$retryAt,'journalIntegrity'=>['checked'=>(int)$integrity['checked'],'mismatchCount'=>count($integrity['mismatches'])]];continue;}
        $policy=native_agent_policy_get($company,null);$seconds=(string)$policy['cadence']==='weekly'?7*86400:86400;$lastStmt=db()->prepare("SELECT status,completed_at FROM native_agent_runs WHERE company_id=? AND agent_type='supervisor' AND status IN ('completed','partial') ORDER BY completed_at DESC LIMIT 1");$lastStmt->execute([$companyId]);$last=$lastStmt->fetch();
        if($last&&(string)$last['status']==='completed'&&time()-strtotime((string)$last['completed_at'].' UTC')<$seconds){$skipped[]=['companyId'=>$companyId,'reason'=>'not_due','journalIntegrity'=>['checked'=>(int)$integrity['checked'],'mismatchCount'=>count($integrity['mismatches'])]];continue;}
        try{$result=native_agent_supervisor_run($company,$policy,$trigger,null,[],[]);if(!empty($result['failures']))$failedCompanyIds[]=$companyId;$processed[]=['companyId'=>$companyId,'status'=>(string)($result['status']??'unknown'),'reused'=>(bool)($result['reused']??false),'runCount'=>count((array)($result['runs']??[])),'failureCount'=>count((array)($result['failures']??[])),'journalIntegrity'=>['checked'=>(int)$integrity['checked'],'mismatchCount'=>count($integrity['mismatches'])],'accountingWrites'=>0,'providerAttempts'=>0];}catch(Throwable $error){error_log('Native Agent scheduled scan failed class='.$error::class);$failedCompanyIds[]=$companyId;$GLOBALS['tegh_native_agent_failed_company_ids']=array_values(array_unique($failedCompanyIds));$processed[]=['companyId'=>$companyId,'status'=>'failed','errorCode'=>'scanner_failed','journalIntegrity'=>['checked'=>(int)$integrity['checked'],'mismatchCount'=>count($integrity['mismatches'])],'accountingWrites'=>0,'providerAttempts'=>0];}
    }
    $GLOBALS['tegh_native_agent_failed_company_ids']=array_values(array_unique($failedCompanyIds));
    return ['ok'=>$failedCompanyIds===[],'trigger'=>$trigger,'companiesProcessed'=>count($processed),'companiesSkipped'=>count($skipped),'failedCompanyIds'=>array_values(array_unique($failedCompanyIds)),'skips'=>$skipped,'results'=>$processed,'accountingWrites'=>0,'providerAttempts'=>0,'completedAt'=>gmdate('c')];
}

function handle_native_agent_scheduler(): never
{
    native_agent_scheduler_authorize();@ignore_user_abort(true);@set_time_limit(85);json_response(native_agent_scheduler_execute(2,'scheduled'));
}

function native_agent_permission_for_specialist(string $agent,bool $act=false): string
{
    return match($agent){
        'bookkeeping'=>$act?'banking.match':'banking.view',
        'reconciliation'=>$act?'banking.reconcile':'banking.view',
        'month_end_close'=>'reports.view',
        'accounts_payable'=>$act?'bills.write':'bills.view',
        'accounts_receivable'=>$act?'invoices.write':'invoices.view',
        'financial_analyst'=>'reports.view',
        'payroll_tax'=>'payroll.view',
        default=>'company.view',
    };
}

function native_agent_finding_row(string $id,array $company,bool $lock=false): array
{
    $sql='SELECT f.*,t.status accepted_task_status,t.user_id accepted_task_user_id FROM native_agent_findings f LEFT JOIN ai_agent_tasks t ON t.id=f.accepted_task_id AND t.company_id=f.company_id WHERE f.id=? AND f.company_id=? LIMIT 1'.($lock?' FOR UPDATE':'');
    $stmt=db()->prepare($sql);$stmt->execute([$id,(string)$company['id']]);$row=$stmt->fetch();if(!$row)fail('That Native Agent finding is no longer available in this company.',404,'native_agent_finding_not_found');
    require_company_permission($company,native_agent_permission_for_specialist((string)$row['agent_type']));return $row;
}

function native_agent_record_labels(string $companyId,array $findings): array
{
    $ids=[];foreach($findings as $finding){foreach((array)($finding['evidence']['records']??[]) as $record){$type=(string)($record['type']??'');$id=(string)($record['id']??'');if($id!==''&&in_array($type,['bank_transaction','journal_entry','import_batch','bank_account','company','bill','vendor','invoice','customer','native_agent_document','collection_draft'],true))$ids[$type][$id]=true;}}
    $labels=[];$load=static function(string $type,array $keys,string $sql,callable $format)use($companyId,&$labels):void{
        foreach(array_chunk(array_keys($keys),100) as $chunk){$place=implode(',',array_fill(0,count($chunk),'?'));$stmt=db()->prepare(str_replace(':ids',$place,$sql));$stmt->execute(array_merge([$companyId],$chunk));foreach($stmt->fetchAll() as $row)$labels[$type.':'.(string)$row['id']]=$format($row);}
    };
    if(!empty($ids['bank_transaction']))$load('bank_transaction',$ids['bank_transaction'],"SELECT bt.id,bt.transaction_date,bt.description,bt.reference,bt.amount_cents,bt.status,ba.name account_name FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id WHERE bt.company_id=? AND bt.id IN (:ids)",static fn(array $r):array=>['label'=>(string)$r['description'],'date'=>(string)$r['transaction_date'],'reference'=>$r['reference'],'amountCents'=>(int)$r['amount_cents'],'status'=>(string)$r['status'],'account'=>(string)$r['account_name']]);
    if(!empty($ids['journal_entry']))$load('journal_entry',$ids['journal_entry'],"SELECT id,entry_date,memo,source_type,status FROM journal_entries WHERE company_id=? AND id IN (:ids)",static fn(array $r):array=>['label'=>(string)$r['memo'],'date'=>(string)$r['entry_date'],'sourceType'=>(string)$r['source_type'],'status'=>(string)$r['status']]);
    if(!empty($ids['import_batch']))$load('import_batch',$ids['import_batch'],"SELECT id,filename,status,row_count,created_at FROM import_batches WHERE company_id=? AND id IN (:ids)",static fn(array $r):array=>['label'=>(string)$r['filename'],'date'=>(string)$r['created_at'],'status'=>(string)$r['status'],'rowCount'=>(int)$r['row_count']]);
    if(!empty($ids['bank_account']))$load('bank_account',$ids['bank_account'],"SELECT id,name,account_type,currency FROM bank_accounts WHERE company_id=? AND id IN (:ids)",static fn(array $r):array=>['label'=>(string)$r['name'],'accountType'=>(string)$r['account_type'],'currency'=>(string)$r['currency']]);
    if(!empty($ids['bill']))$load('bill',$ids['bill'],"SELECT b.id,b.number,b.bill_date,b.due_date,b.status,b.balance_cents,v.name party_name FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id WHERE b.company_id=? AND b.id IN (:ids)",static fn(array $r):array=>['label'=>'Bill '.(string)$r['number'].' · '.(string)$r['party_name'],'date'=>(string)$r['bill_date'],'dueDate'=>(string)$r['due_date'],'status'=>(string)$r['status'],'balanceCents'=>(int)$r['balance_cents']]);
    if(!empty($ids['vendor']))$load('vendor',$ids['vendor'],"SELECT id,name,email,status FROM vendors WHERE company_id=? AND id IN (:ids)",static fn(array $r):array=>['label'=>(string)$r['name'],'email'=>$r['email'],'status'=>(string)$r['status']]);
    if(!empty($ids['invoice']))$load('invoice',$ids['invoice'],"SELECT i.id,i.number,i.issue_date,i.due_date,i.status,i.balance_cents,c.name party_name FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.company_id=? AND i.id IN (:ids)",static fn(array $r):array=>['label'=>'Invoice '.(string)$r['number'].' · '.(string)$r['party_name'],'date'=>(string)$r['issue_date'],'dueDate'=>(string)$r['due_date'],'status'=>(string)$r['status'],'balanceCents'=>(int)$r['balance_cents']]);
    if(!empty($ids['customer']))$load('customer',$ids['customer'],"SELECT id,name,email,status FROM customers WHERE company_id=? AND id IN (:ids)",static fn(array $r):array=>['label'=>(string)$r['name'],'email'=>$r['email'],'status'=>(string)$r['status']]);
    if(!empty($ids['native_agent_document']))$load('native_agent_document',$ids['native_agent_document'],"SELECT id,original_name,document_type,state,updated_at FROM native_agent_documents WHERE company_id=? AND id IN (:ids)",static fn(array $r):array=>['label'=>(string)$r['original_name'],'documentType'=>(string)$r['document_type'],'status'=>(string)$r['state'],'date'=>(string)$r['updated_at']]);
    if(!empty($ids['collection_draft']))$load('collection_draft',$ids['collection_draft'],"SELECT id,subject,state,level,updated_at FROM native_agent_collection_drafts WHERE company_id=? AND id IN (:ids)",static fn(array $r):array=>['label'=>(string)$r['subject'],'level'=>(string)$r['level'],'status'=>(string)$r['state'],'date'=>(string)$r['updated_at']]);
    if(!empty($ids['company'])){$stmt=db()->prepare('SELECT id,name FROM companies WHERE id=?');$stmt->execute([$companyId]);if($row=$stmt->fetch())$labels['company:'.$companyId]=['label'=>(string)$row['name']];}
    return $labels;
}

function native_agent_view_tags(array $finding): array
{
    $state=(string)$finding['state'];$tags=['Outstanding'];$final=in_array($state,['resolved','dismissed','superseded'],true);if($final)return ['Completed',match($state){'resolved'=>'Done','dismissed'=>'Dismissed',default=>'Replaced'}];
    if(substr((string)$finding['last_seen_at'],0,10)===gmdate('Y-m-d'))$tags[]='Today';
    if(in_array((string)$finding['severity'],['warning','critical'],true))$tags[]='Needs Attention';
    if(!empty($finding['accepted_task_id'])&&in_array((string)($finding['accepted_task_status']??''),['active','waiting_input','waiting_confirmation','suspended'],true))$tags[]='Waiting for You';
    elseif(in_array((string)$finding['execution_class'],['prepared','authorized'],true)||$state==='open')$tags[]='Ready for Review';
    return array_values(array_unique($tags));
}

function native_agent_findings_list(array $user,array $company,array $filters=[]): array
{
    require_company_permission($company,'company.view');$params=[(string)$company['id']];$where=['f.company_id=?'];
    $agent=(string)($filters['agent']??'');if($agent!==''&&!in_array($agent,NATIVE_AGENT_TYPES,true))fail('Choose a supported Native Agent filter.',422,'native_agent_filter_invalid');if($agent!==''){$where[]='f.agent_type=?';$params[]=$agent;}
    $severity=(string)($filters['severity']??'');if($severity!==''&&!in_array($severity,['info','warning','critical'],true))fail('Choose a supported severity filter.',422,'native_agent_filter_invalid');if($severity!==''){$where[]='f.severity=?';$params[]=$severity;}
    $requestedView=(string)($filters['view']??'');
    if(in_array($requestedView,['Outstanding','Today','Needs Attention','Ready for Review','Waiting for You'],true))$where[]="f.state NOT IN ('resolved','dismissed','superseded')";
    if(in_array($requestedView,['Completed','Done','Dismissed','Replaced'],true))$where[]="f.state IN ('resolved','dismissed','superseded')";
    $state=(string)($filters['state']??'');if($state!==''&&!in_array($state,['open','snoozed','dismissed','resolved','superseded'],true))fail('Choose a supported finding state.',422,'native_agent_filter_invalid');if($state!==''){$where[]='f.state=?';$params[]=$state;}
    if(!empty($filters['dateFrom'])){$where[]='f.last_seen_at>=?';$params[]=safe_date($filters['dateFrom'],'From date').' 00:00:00';}if(!empty($filters['dateTo'])){$where[]='f.last_seen_at<=?';$params[]=safe_date($filters['dateTo'],'To date').' 23:59:59';}
    $stmt=db()->prepare('SELECT f.*,t.status accepted_task_status,t.user_id accepted_task_user_id FROM native_agent_findings f LEFT JOIN ai_agent_tasks t ON t.id=f.accepted_task_id AND t.company_id=f.company_id WHERE '.implode(' AND ',$where).' ORDER BY FIELD(f.severity,\'critical\',\'warning\',\'info\'),f.last_seen_at DESC,f.id LIMIT 500');$stmt->execute($params);$registry=tegh_action_registry();$rows=[];$period=(string)($filters['period']??'');if($period!==''&&!preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])$/',$period))fail('Choose a period in YYYY-MM format.',422,'native_agent_filter_invalid');$account=(string)($filters['account']??'');$view=(string)($filters['view']??'');$validViews=['Outstanding','Today','Needs Attention','Ready for Review','Waiting for You','Completed','Done','Dismissed','Replaced'];if($view!==''&&!in_array($view,$validViews,true))fail('Choose a supported Agent Center view.',422,'native_agent_filter_invalid');
    $countRows=[];foreach($stmt->fetchAll() as $row){if(!company_role_can((string)$company['role'],native_agent_permission_for_specialist((string)$row['agent_type'])))continue;$evidence=json_decode((string)$row['evidence_json'],true);if(!is_array($evidence))$evidence=[];$inputs=json_decode((string)$row['proposed_inputs_json'],true);if(!is_array($inputs))$inputs=[];if($period!==''&&substr((string)($evidence['period']['start']??''),0,7)!==$period)continue;if($account!==''&&(string)($evidence['bankAccountId']??'')!==$account)continue;$row['evidence']=$evidence;$row['proposedInputs']=$inputs;$row['viewTags']=native_agent_view_tags($row);$countRows[]=$row;if($view!==''&&!in_array($view,$row['viewTags'],true))continue;$rows[]=$row;}
    $labels=native_agent_record_labels((string)$company['id'],$rows);$out=[];
    foreach(array_slice($rows,0,250) as $row){$actionId=(string)($row['proposed_action_id']??'');$action=$registry[$actionId]??null;$actionAvailable=is_array($action)&&company_role_can((string)$company['role'],(string)$action['required_permission']);$records=[];foreach((array)($row['evidence']['records']??[]) as $record){$type=(string)($record['type']??'');$id=(string)($record['id']??'');$records[]=array_merge(['type'=>$type,'id'=>$id],$labels[$type.':'.$id]??['label'=>$id]);}$out[]=['id'=>(string)$row['id'],'agentType'=>(string)$row['agent_type'],'findingType'=>(string)$row['finding_type'],'severity'=>(string)$row['severity'],'confidenceBps'=>(int)$row['confidence_bps'],'title'=>(string)$row['title'],'explanation'=>(string)$row['explanation'],'evidenceTimestamp'=>$row['source_date']?:$row['last_seen_at'],'evidence'=>$row['evidence'],'evidenceHash'=>(string)$row['evidence_hash'],'affectedRecords'=>$records,'sourceRevisionHash'=>(string)$row['source_revision_hash'],'policyRevision'=>(int)$row['policy_revision'],'proposedAction'=>$action?['actionId'=>$actionId,'label'=>(string)$action['name'],'route'=>(string)$action['route'],'executionClass'=>tegh_action_execution_class($action),'available'=>$actionAvailable]:['actionId'=>$actionId,'label'=>'Unavailable action','route'=>null,'executionClass'=>(string)$row['execution_class'],'available'=>false],'proposedInputs'=>$row['proposedInputs'],'state'=>(string)$row['state'],'viewTags'=>$row['viewTags'],'assignedUserId'=>$row['assigned_user_id'],'acceptedTaskId'=>$row['accepted_task_id'],'acceptedTaskStatus'=>$row['accepted_task_status'],'firstSeenAt'=>(string)$row['first_seen_at'],'lastSeenAt'=>(string)$row['last_seen_at'],'snoozedUntil'=>$row['snoozed_until'],'history'=>['at'=>$row['resolved_at']??$row['dismissed_at']??null,'actorId'=>$row['resolved_by']??$row['dismissed_by']??null,'reason'=>$row['resolution_note']??'']];}
    $counts=array_fill_keys($validViews,0);foreach($countRows as $row)foreach($row['viewTags'] as $tag)$counts[$tag]++;
    return ['findings'=>$out,'count'=>count($out),'countScope'=>'Current filters, up to 500 eligible records','hasMore'=>count($rows)>250||count($countRows)>=500,'viewCounts'=>$counts,'filters'=>['agent'=>$agent,'severity'=>$severity,'state'=>$state,'period'=>$period,'account'=>$account,'view'=>$view],'registryIdentity'=>native_agent_hash(array_keys($registry))];
}

function native_agent_finding_metadata_action(array $user,array $company,array $input): array
{
    $id=clean_text($input['findingId']??'','Finding',64);$action=(string)($input['action']??'');if(!in_array($action,['snooze','dismiss','reopen','assign','resolve'],true))fail('Choose a supported finding action.',422,'native_agent_finding_action_invalid');$pdo=db();$pdo->beginTransaction();try{$row=native_agent_finding_row($id,$company,true);if(in_array($action,['dismiss','reopen','assign','resolve'],true))require_company_permission($company,'company.settings');else require_company_permission($company,native_agent_permission_for_specialist((string)$row['agent_type'],true));$metadata=[];
        if($action==='snooze'){$days=native_agent_int($input,'days',7,1,90);db()->prepare("UPDATE native_agent_findings SET state='snoozed',snoozed_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL $days DAY),snoozed_by=? WHERE id=? AND company_id=?")->execute([(string)$user['id'],$id,(string)$company['id']]);$metadata=['days'=>$days];}
        elseif($action==='dismiss'){db()->prepare("UPDATE native_agent_findings SET state='dismissed',dismissed_at=UTC_TIMESTAMP(),dismissed_by=?,snoozed_until=NULL WHERE id=? AND company_id=?")->execute([(string)$user['id'],$id,(string)$company['id']]);db()->prepare('UPDATE notifications SET dismissed_at=UTC_TIMESTAMP() WHERE company_id=? AND unique_key=? AND dismissed_at IS NULL')->execute([(string)$company['id'],'native-agent:'.(string)$row['fingerprint']]);}
        elseif($action==='reopen'){db()->prepare("UPDATE native_agent_findings SET state='open',dismissed_at=NULL,dismissed_by=NULL,resolved_at=NULL,resolved_by=NULL,snoozed_until=NULL,snoozed_by=NULL,resolution_note='' WHERE id=? AND company_id=?")->execute([$id,(string)$company['id']]);}
        elseif($action==='assign'){$assignee=trim((string)($input['userId']??''));if($assignee!==''){$check=db()->prepare("SELECT 1 FROM company_members WHERE company_id=? AND user_id=? AND status='active' LIMIT 1");$check->execute([(string)$company['id'],$assignee]);if(!$check->fetchColumn())fail('Choose an active member of this company.',422,'native_agent_assignee_invalid');}db()->prepare('UPDATE native_agent_findings SET assigned_user_id=? WHERE id=? AND company_id=?')->execute([$assignee!==''?$assignee:null,$id,(string)$company['id']]);$metadata=['assignedUserId'=>$assignee?:null];}
        else{$note=optional_text($input['note']??null,500)??'Marked complete after user review.';db()->prepare("UPDATE native_agent_findings SET state='resolved',resolved_at=UTC_TIMESTAMP(),resolved_by=?,resolution_note=? WHERE id=? AND company_id=?")->execute([(string)$user['id'],$note,$id,(string)$company['id']]);$metadata=['note'=>$note];}
        audit_event($user,(string)$company['id'],'native_agent.finding_'.$action,'native_agent_finding',$id,$metadata);$pdo->commit();return ['ok'=>true,'findingId'=>$id,'action'=>$action];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_agent_deterministic_plan(array $user,array $company,array $finding,array $action,array $inputs,?string $resultSetId,array $recordIds): ?array
{
    if(!function_exists('tegh_human_conversation')||!function_exists('tegh_human_save_plan')||tegh_action_execution_class($action)==='authorized')return null;
    // The server-created result set is the scoped record authority. Do not
    // duplicate source IDs into planner arguments; older imported identifiers
    // need not match the model-facing ID grammar, and the result set is already
    // revalidated before any later prepared or authorized operation.
    $conversation=tegh_human_conversation($user,$company,'','guided');$arguments=[];if($resultSetId!==null)$arguments['resultSetId']=$resultSetId;if(isset($inputs['taxTreatment']))$arguments['taxTreatment']=(string)$inputs['taxTreatment'];if(isset($inputs['period']))$arguments['period']=(string)$inputs['period'];
    $plan=['user_goal'=>'Review Native Agent finding: '.(string)$finding['title'],'intent_categories'=>['Prepare'],'risk_level'=>tegh_action_execution_class($action)==='prepared'?'medium':'low','confidence'=>(int)round((int)$finding['confidence_bps']/100),'assumptions'=>[],'missing_facts'=>[],'context_references'=>['native_agent_finding:'.(string)$finding['id'],'source_revision:'.(string)$finding['source_revision_hash']],'steps'=>[['step_id'=>'review_finding','action_id'=>(string)$action['action_id'],'action_type'=>(string)$action['action_type'],'dependencies'=>[],'arguments'=>$arguments,'expected_result'=>'Open the current Tegh workflow with the finding evidence prepared for user review.','requires_confirmation'=>false,'verification_rule'=>'Re-read company-scoped source evidence before any later prepared or authorized action.','safe_retry_policy'=>'read_retry_ok']],'requires_confirmation'=>false,'verification_requirements'=>['Current company must match the finding company.','The source revision must still match.','Any later accounting action uses the existing confirmation and commit-time revalidation boundary.'],'learning_candidates'=>[],'user_facing_summary'=>'Tegh prepared the existing workflow from a deterministic Native Agent finding. No accounting entry was created.'];
    $validated=tegh_human_validate_plan($company,$plan,[(string)$action['action_id']]);return tegh_human_save_plan($user,$company,(string)$conversation['id'],$validated);
}

function native_agent_accept_finding(array $user,array $company,array $input): array
{
    $id=clean_text($input['findingId']??'','Finding',64);$pdo=db();$pdo->beginTransaction();try{$finding=native_agent_finding_row($id,$company,true);if(in_array((string)$finding['state'],['resolved','dismissed','superseded'],true))fail('This finding is no longer open for action.',409,'native_agent_finding_closed');$policy=native_agent_policy_get($company,$user);if((int)$finding['policy_revision']!==(int)$policy['revision'])fail('The company Native Agent policy changed after this finding was built. Run the specialist again.',409,'native_agent_policy_changed');$evidence=json_decode((string)$finding['evidence_json'],true);if(!is_array($evidence))$evidence=[];$context=[];if((string)$finding['agent_type']==='month_end_close')$context['period']=substr((string)($evidence['period']['start']??''),0,7);$currentSource=native_agent_source_revision($company,(string)$finding['agent_type'],$context);if(!hash_equals((string)$finding['source_revision_hash'],$currentSource))fail('The accounting source changed after this finding was built. Refresh the Native Agent scan before acting.',409,'native_agent_source_changed');
        if(!empty($finding['accepted_task_id'])){$task=db()->prepare('SELECT id,user_id,status FROM ai_agent_tasks WHERE id=? AND company_id=? LIMIT 1');$task->execute([(string)$finding['accepted_task_id'],(string)$company['id']]);$existing=$task->fetch();if($existing&&in_array((string)$existing['status'],['active','waiting_input','waiting_confirmation','suspended'],true)){if((string)$existing['user_id']!==(string)$user['id'])fail('This finding is already waiting for another assigned user.',409,'native_agent_finding_already_accepted');$pdo->commit();return ['ok'=>true,'reused'=>true,'findingId'=>$id,'taskId'=>(string)$existing['id']];}}
        $registry=tegh_action_registry();$actionId=(string)$finding['proposed_action_id'];$action=$registry[$actionId]??null;if(!is_array($action))fail('The proposed action is no longer registered. Refresh the scan.',409,'native_agent_action_changed');tegh_action_assert_permission($company,$action,$user);if(tegh_action_execution_class($action)!==(string)$finding['execution_class'])fail('The proposed action safety class changed. Refresh the scan.',409,'native_agent_action_changed');$inputs=json_decode((string)$finding['proposed_inputs_json'],true);if(!is_array($inputs))$inputs=[];native_agent_validate_proposal($actionId,$inputs);
        $recordIds=[];foreach((array)($evidence['records']??[]) as $record)if((string)($record['type']??'')==='bank_transaction')$recordIds[]=(string)$record['id'];$recordIds=array_values(array_unique(array_filter($recordIds)));$resultSetId=null;
        if($recordIds){$place=implode(',',array_fill(0,count($recordIds),'?'));$stmt=db()->prepare("SELECT id,status,amount_cents,decided_account_id,tax_code,source_hash,updated_at FROM bank_transactions WHERE company_id=? AND id IN ($place) ORDER BY id");$stmt->execute(array_merge([(string)$company['id']],$recordIds));$bankRows=$stmt->fetchAll();if(count($bankRows)!==count($recordIds))fail('One or more source transactions is no longer available. Refresh the scan.',409,'native_agent_source_changed');foreach($bankRows as $row)if((string)$row['status']!=='pending')fail('A source transaction no longer needs review. Refresh the scan.',409,'native_agent_source_changed');$saved=tegh_agent_result_save($user,$company,['origin'=>'native_agent_finding','findingId'=>$id],$bankRows,null,$recordIds);$resultSetId=(string)$saved['id'];if(in_array('resultSetId',(array)$action['required_inputs'],true)||in_array('resultSetId',(array)$action['optional_inputs'],true))$inputs['resultSetId']=$resultSetId;}
        if($actionId==='bank.transactions.categorize'){$accountId=(string)($inputs['account']['id']??'');$check=db()->prepare('SELECT id FROM accounts WHERE id=? AND company_id=? AND active=1 AND is_control=0 LIMIT 1');$check->execute([$accountId,(string)$company['id']]);if(!$check->fetchColumn())fail('The proposed category is no longer a valid current-company account. Refresh the scan.',409,'native_agent_source_changed');$tax=(string)($inputs['taxTreatment']??'NO_TAX');if(!in_array($tax,['NO_TAX','GST_HST','HST13','PST','GST_HST_PST'],true))fail('The proposed tax treatment is no longer supported. Refresh the scan.',409,'native_agent_source_changed');}
        $required=array_values(array_map('strval',(array)$action['required_inputs']));$missing=array_values(array_filter($required,static fn(string $key):bool=>!array_key_exists($key,$inputs)));$plan=native_agent_deterministic_plan($user,$company,$finding,$action,$inputs,$resultSetId,$recordIds);$task=tegh_agent_task($user,$company,$actionId,'Accepted Native Agent finding: '.(string)$finding['title'],'finding_accepted',$inputs,$missing,$resultSetId,null,true);db()->prepare('UPDATE native_agent_findings SET accepted_task_id=?,accepted_at=UTC_TIMESTAMP(),accepted_by=?,assigned_user_id=? WHERE id=? AND company_id=?')->execute([(string)$task['id'],(string)$user['id'],(string)$user['id'],$id,(string)$company['id']]);audit_event($user,(string)$company['id'],'native_agent.finding_accepted','native_agent_finding',$id,['actionId'=>$actionId,'executionClass'=>(string)$finding['execution_class'],'taskId'=>(string)$task['id'],'planId'=>$plan['id']??null,'resultSetId'=>$resultSetId,'sourceRevisionHash'=>$currentSource,'accountingCommit'=>false]);$pdo->commit();return ['ok'=>true,'findingId'=>$id,'task'=>$task,'plan'=>$plan,'resultSetId'=>$resultSetId,'navigation'=>(string)$action['route'],'prefill'=>$inputs,'executionClass'=>(string)$finding['execution_class'],'accountingCommit'=>false];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_agent_run_history(array $company): array
{
    require_company_permission($company,'company.view');$stmt=db()->prepare('SELECT id,agent_type,trigger_type,initiated_by,status,policy_revision,policy_hash,source_revision_hash,summary_json,processed_count,finding_count,notification_count,error_code,started_at,completed_at FROM native_agent_runs WHERE company_id=? ORDER BY started_at DESC,id DESC LIMIT 80');$stmt->execute([(string)$company['id']]);$runs=[];foreach($stmt->fetchAll() as $row)$runs[]=['id'=>(string)$row['id'],'agentType'=>(string)$row['agent_type'],'triggerType'=>(string)$row['trigger_type'],'initiatedBy'=>$row['initiated_by'],'status'=>(string)$row['status'],'policyRevision'=>(int)$row['policy_revision'],'policyHash'=>(string)$row['policy_hash'],'sourceRevisionHash'=>(string)$row['source_revision_hash'],'summary'=>json_decode((string)$row['summary_json'],true)?:[],'processedCount'=>(int)$row['processed_count'],'findingCount'=>(int)$row['finding_count'],'notificationCount'=>(int)$row['notification_count'],'errorCode'=>$row['error_code'],'startedAt'=>(string)$row['started_at'],'completedAt'=>$row['completed_at']];return $runs;
}

function native_agent_failure_queue(array $company): array
{
    if(!schema_table_exists('native_agent_run_failures'))return [];
    $stmt=db()->prepare("SELECT id,agent_type,run_id,retry_count,next_retry_at,status,error_code,first_failed_at,last_failed_at FROM native_agent_run_failures WHERE company_id=? AND status='pending' ORDER BY next_retry_at,agent_type LIMIT 50");
    $stmt->execute([(string)$company['id']]);return array_map(static fn(array $row):array=>['id'=>(string)$row['id'],'agentType'=>(string)$row['agent_type'],'runId'=>$row['run_id'],'retryCount'=>(int)$row['retry_count'],'nextRetryAt'=>(string)$row['next_retry_at'],'status'=>(string)$row['status'],'errorCode'=>(string)$row['error_code'],'firstFailedAt'=>(string)$row['first_failed_at'],'lastFailedAt'=>(string)$row['last_failed_at']],$stmt->fetchAll());
}

function native_agent_auto_match_queue(array $company): array
{
    $policy=native_agent_policy_get($company,null);$enabled=(bool)$policy['thresholds']['autoMatchPreviewEnabled'];
    if(!$enabled)return ['enabled'=>false,'minimumScore'=>(int)$policy['thresholds']['autoMatchMinimumScore'],'items'=>[]];
    $stmt=db()->prepare("SELECT id,title,confidence_bps,evidence_json,source_revision_hash,last_seen_at FROM native_agent_findings WHERE company_id=? AND agent_type='reconciliation' AND finding_type='match_candidate' AND state='open' AND accepted_task_id IS NULL AND confidence_bps>=? AND JSON_UNQUOTE(JSON_EXTRACT(evidence_json,'$.autoMatchPreviewEligible'))='true' ORDER BY confidence_bps DESC,last_seen_at LIMIT 100");
    $stmt->execute([(string)$company['id'],(int)$policy['thresholds']['autoMatchMinimumScore']*100]);$items=[];
    foreach($stmt->fetchAll() as $row){$evidence=json_decode((string)$row['evidence_json'],true);if(!is_array($evidence))continue;$items[]=['findingId'=>(string)$row['id'],'title'=>(string)$row['title'],'score'=>(int)round((int)$row['confidence_bps']/100),'bankAccountId'=>(string)($evidence['bankAccountId']??''),'period'=>$evidence['period']??null,'bankTransactionIds'=>array_values(array_map('strval',(array)($evidence['bankTransactionIds']??[]))),'journalEntryIds'=>array_values(array_map('strval',(array)($evidence['journalEntryIds']??[]))),'reason'=>(string)($evidence['reason']??''),'sourceRevisionHash'=>(string)$row['source_revision_hash'],'lastSeenAt'=>(string)$row['last_seen_at']];}
    return ['enabled'=>true,'minimumScore'=>(int)$policy['thresholds']['autoMatchMinimumScore'],'items'=>$items,'commitRequiresProtectedConfirmation'=>true];
}

function native_agent_prepare_auto_match_batch(array $user,array $company,array $input): array
{
    require_company_permission($company,'banking.reconcile');$queue=native_agent_auto_match_queue($company);if(empty($queue['enabled']))fail('Enable the high-confidence reconciliation preview queue first.',409,'auto_match_preview_disabled');
    $selected=array_values(array_unique(array_filter(array_map('strval',(array)($input['findingIds']??[])))));if(!$selected||count($selected)>40)fail('Choose between 1 and 40 high-confidence proposals.',422,'auto_match_preview_selection_invalid');
    $available=[];foreach($queue['items'] as $item)$available[(string)$item['findingId']]=$item;$items=[];foreach($selected as $id){if(!isset($available[$id]))fail('A selected proposal is stale or no longer eligible. Run the reconciliation agent again.',409,'auto_match_preview_stale');$items[]=$available[$id];}
    $bankId=(string)$items[0]['bankAccountId'];$period=$items[0]['period'];$usedBank=[];$usedBook=[];$decisions=[];
    foreach($items as $item){if((string)$item['bankAccountId']!==$bankId||$item['period']!==$period)fail('Batch proposals must belong to the same financial account and period.',422,'auto_match_preview_mixed_scope');foreach($item['bankTransactionIds'] as $id){if(isset($usedBank[$id]))fail('Selected proposals overlap on a bank transaction.',409,'auto_match_preview_conflict');$usedBank[$id]=true;}foreach($item['journalEntryIds'] as $id){if(isset($usedBook[$id]))fail('Selected proposals overlap on a book entry.',409,'auto_match_preview_conflict');$usedBook[$id]=true;}$decisions[]=['kind'=>'match','bankTransactionIds'=>$item['bankTransactionIds'],'journalEntryIds'=>$item['journalEntryIds']];}
    $operationKey='native-auto-match:'.substr(hash('sha256',implode('|',$selected)),0,48);
    $preview=tegh_recon_prepare($user,$company,['accountId'=>$bankId,'periodStart'=>(string)$period['start'],'periodEnd'=>(string)$period['end'],'operationKey'=>$operationKey,'decisions'=>$decisions]);
    return ['preview'=>$preview,'findingIds'=>$selected,'proposalCount'=>count($selected),'accountingWrites'=>0,'nextStep'=>'confirm_protected_batch'];
}

function native_agent_overview(array $user,array $company): array
{
    $findings=native_agent_findings_list($user,$company,[]);$runs=native_agent_run_history($company);return ['policy'=>native_agent_policy_get($company,$user),'viewCounts'=>$findings['viewCounts'],'recentRuns'=>array_slice($runs,0,12),'runFailures'=>native_agent_failure_queue($company),'autoMatchPreviewQueue'=>native_agent_auto_match_queue($company),'nativeOnly'=>true,'providerAttempts'=>0,'accountingAuthority'=>'existing Tegh services'];
}

const MONTH_END_ATTESTATION_TEXT = 'I confirm that the available bank statements, receipts, invoices, bills, payroll records and tax information for this month have been reviewed.';

function native_month_end_current_finding(array $company,string $period,bool $lock=false): array
{
    [$start,$end]=native_agent_close_period($period);$sql="SELECT * FROM native_agent_findings WHERE company_id=? AND agent_type='month_end_close' AND finding_type='close_readiness' AND JSON_UNQUOTE(JSON_EXTRACT(evidence_json,'$.period.start'))=? ORDER BY last_seen_at DESC,id DESC LIMIT 1".($lock?' FOR UPDATE':'');
    $stmt=db()->prepare($sql);$stmt->execute([(string)$company['id'],$start]);$row=$stmt->fetch();if(!$row)fail('Run the Month-End readiness review before confirming external records.',409,'month_end_review_required');
    $current=native_agent_source_revision($company,'month_end_close',['period'=>$period]);if(!hash_equals((string)$row['source_revision_hash'],$current))fail('The accounting source changed. Run the Month-End readiness review again before confirming external records.',409,'month_end_evidence_stale');
    $row['period_start']=$start;$row['period_end']=$end;return $row;
}

function native_month_end_attestations(array $user,array $company,string $period): array
{
    require_company_permission($company,'reports.view');[$start,$end]=native_agent_close_period($period);$currentHash=null;$currentRevision=null;
    $findingStmt=db()->prepare("SELECT evidence_hash,source_revision_hash FROM native_agent_findings WHERE company_id=? AND agent_type='month_end_close' AND finding_type='close_readiness' AND JSON_UNQUOTE(JSON_EXTRACT(evidence_json,'$.period.start'))=? ORDER BY last_seen_at DESC,id DESC LIMIT 1");$findingStmt->execute([(string)$company['id'],$start]);$finding=$findingStmt->fetch();
    if($finding){$liveRevision=native_agent_source_revision($company,'month_end_close',['period'=>$period]);if(hash_equals((string)$finding['source_revision_hash'],$liveRevision)){$currentHash=(string)$finding['evidence_hash'];$currentRevision=$liveRevision;}}
    $stmt=db()->prepare('SELECT id,period_start,period_end,evidence_hash,evidence_revision,attestation_version,attestation_text,note,attested_by,attested_at FROM month_end_attestations WHERE company_id=? AND period_start=? AND period_end=? ORDER BY attested_at DESC,id DESC');$stmt->execute([(string)$company['id'],$start,$end]);$items=[];
    foreach($stmt->fetchAll() as $row)$items[]=['periodStart'=>(string)$row['period_start'],'periodEnd'=>(string)$row['period_end'],'attestationVersion'=>(int)$row['attestation_version'],'text'=>(string)$row['attestation_text'],'note'=>(string)$row['note'],'attestedAt'=>(string)$row['attested_at'],'current'=>$currentHash!==null&&hash_equals($currentHash,(string)$row['evidence_hash']),'stale'=>$currentHash===null||!hash_equals($currentHash,(string)$row['evidence_hash'])];
    return ['period'=>['start'=>$start,'end'=>$end],'statement'=>MONTH_END_ATTESTATION_TEXT,'currentEvidenceHash'=>$currentHash,'currentSourceRevisionHash'=>$currentRevision,'attestations'=>$items,'currentAttestation'=>current(array_filter($items,static fn(array $item):bool=>$item['current']))?:null,'accountingWrites'=>0,'periodLocks'=>0];
}

function native_month_end_attest(array $user,array $company,array $input): array
{
    require_company_permission($company,'reports.view');$period=trim((string)($input['period']??''));$ack=(string)($input['acknowledgement']??'');if(!hash_equals(MONTH_END_ATTESTATION_TEXT,$ack))fail('Confirm the exact external-record review statement.',422,'month_end_attestation_confirmation_required');$note=trim((string)($input['note']??''));if(mb_strlen($note)>500)fail('The confirmation note must be 500 characters or fewer.',422,'month_end_attestation_note_invalid');
    $pdo=db();$pdo->beginTransaction();try{$finding=native_month_end_current_finding($company,$period,true);$id=new_id('monthattest');$revision=mb_substr((string)$finding['last_seen_run_id'].'@'.(string)$finding['last_seen_at'],0,120);
        try{$pdo->prepare('INSERT INTO month_end_attestations (id,company_id,period_start,period_end,evidence_hash,evidence_revision,attestation_version,attestation_text,note,attested_by,attested_at) VALUES (?,?,?,?,?,?,1,?,?,?,UTC_TIMESTAMP())')->execute([$id,(string)$company['id'],(string)$finding['period_start'],(string)$finding['period_end'],(string)$finding['evidence_hash'],$revision,MONTH_END_ATTESTATION_TEXT,$note,(string)$user['id']]);}
        catch(PDOException $error){if((string)$error->getCode()!=='23000')throw $error;$stmt=$pdo->prepare('SELECT id FROM month_end_attestations WHERE company_id=? AND period_start=? AND evidence_hash=? AND attested_by=? AND attestation_version=1 LIMIT 1');$stmt->execute([(string)$company['id'],(string)$finding['period_start'],(string)$finding['evidence_hash'],(string)$user['id']]);$id=(string)$stmt->fetchColumn();}
        $after=native_agent_source_revision($company,'month_end_close',['period'=>$period]);if(!hash_equals((string)$finding['source_revision_hash'],$after))fail('The accounting source changed while the confirmation was being signed. Run the review again.',409,'month_end_evidence_stale');
        audit_event($user,(string)$company['id'],'month_end.external_records_attested','month_end_attestation',$id,['periodStart'=>(string)$finding['period_start'],'periodEnd'=>(string)$finding['period_end'],'evidenceHash'=>(string)$finding['evidence_hash'],'evidenceRevision'=>$revision,'attestationVersion'=>1,'accountingWrites'=>0,'periodLocks'=>0]);$pdo->commit();
        return ['ok'=>true,'attestationId'=>$id,'period'=>['start'=>(string)$finding['period_start'],'end'=>(string)$finding['period_end']],'evidenceHash'=>(string)$finding['evidence_hash'],'accountingWrites'=>0,'periodLocks'=>0];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function handle_native_agent(string $action): never
{
    $user=require_user();$company=require_company($user);$action=trim($action,'/');
    if($action===''||$action==='overview'){require_method('GET');json_response(native_agent_overview($user,$company));}
    if($action==='policy'){
        if(request_method()==='GET'){require_company_permission($company,'company.view');json_response(['policy'=>native_agent_policy_get($company,$user)]);}
        require_method('PUT');require_csrf();json_response(['ok'=>true,'policy'=>native_agent_policy_save($user,$company,request_json())]);
    }
    if($action==='run'){
        require_method('POST');require_csrf();$input=request_json();$agents=is_array($input['agents']??null)?array_values(array_map('strval',$input['agents'])):[];foreach($agents?:NATIVE_AGENT_TYPES as $agent)require_company_permission($company,native_agent_permission_for_specialist($agent));$context=[];if(isset($input['period'])&&trim((string)$input['period'])!==''){$context['period']=trim((string)$input['period']);native_agent_close_period($context['period']);}@ignore_user_abort(true);@set_time_limit(85);$policy=native_agent_policy_get($company,$user);json_response(['ok'=>true,'supervisor'=>native_agent_supervisor_run($company,$policy,'manual',$user,$agents,$context)]);
    }
    if($action==='opportunistic'){
        require_method('POST');require_csrf();$policy=native_agent_policy_get($company,$user);if(!$policy['opportunisticEnabled'])json_response(['ok'=>true,'supervisor'=>['status'=>'skipped','reason'=>'opportunistic_disabled']]);if((string)$policy['cadence']==='manual')json_response(['ok'=>true,'supervisor'=>['status'=>'skipped','reason'=>'manual_cadence']]);$stmt=db()->prepare("SELECT completed_at FROM native_agent_runs WHERE company_id=? AND agent_type='supervisor' AND trigger_type IN ('scheduled','opportunistic') AND status='completed' ORDER BY completed_at DESC LIMIT 1");$stmt->execute([(string)$company['id']]);$last=$stmt->fetchColumn();$minimum=(string)$policy['cadence']==='weekly'?7*86400:86400;if($last&&time()-strtotime((string)$last.' UTC')<$minimum)json_response(['ok'=>true,'supervisor'=>['status'=>'skipped','reason'=>'not_due']]);@ignore_user_abort(true);@set_time_limit(25);json_response(['ok'=>true,'supervisor'=>native_agent_supervisor_run($company,$policy,'opportunistic',$user,[],[])]);
    }
    if($action==='findings'){require_method('GET');json_response(native_agent_findings_list($user,$company,$_GET));}
    if($action==='auto-match-queue'){
        if(request_method()==='GET'){require_company_permission($company,'banking.view');json_response(native_agent_auto_match_queue($company));}
        require_method('POST');require_csrf();json_response(native_agent_prepare_auto_match_batch($user,$company,request_json()),201);
    }
    if($action==='month-end-attestations'){
        if(request_method()==='GET')json_response(native_month_end_attestations($user,$company,trim((string)($_GET['period']??''))));
        require_method('POST');require_csrf();json_response(native_month_end_attest($user,$company,request_json()),201);
    }
    if($action==='finding'){require_method('POST');require_csrf();json_response(native_agent_finding_metadata_action($user,$company,request_json()));}
    if($action==='accept'){require_method('POST');require_csrf();json_response(native_agent_accept_finding($user,$company,request_json()));}
    if($action==='runs'){require_method('GET');json_response(['runs'=>native_agent_run_history($company)]);}
    fail('Native Agent route not found.',404,'route_not_found');
}
