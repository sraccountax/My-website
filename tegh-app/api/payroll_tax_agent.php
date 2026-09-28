<?php
declare(strict_types=1);

/**
 * Tegh 5.0 Payroll/Tax Agent and bounded workflow-metadata autonomy.
 *
 * The specialist is read-only against accounting, payroll, tax, bank and rate
 * records. Autonomy is deny-by-default and is permanently limited to the five
 * reversible metadata actions below. Financial commits, filings, payments,
 * mail, payroll changes and rate changes cannot enter this service.
 */

const PAYROLL_TAX_AUTONOMY_ACTIONS = [
    'native_agent.finding_snooze',
    'native_agent.finding_assign',
    'native_agent.finding_task',
    'native_agent.scan_refresh',
    'native_agent.finding_resolved_dismiss',
];

final class PayrollTaxAutonomyRejected extends RuntimeException
{
    public function __construct(public readonly int $httpStatus,public readonly string $publicCode,string $message)
    {
        parent::__construct($message);
    }
}

function payroll_tax_autonomy_reject(string $message,int $status,string $code): never
{
    throw new PayrollTaxAutonomyRejected($status,$code,$message);
}

function payroll_tax_scalar_row(string $sql,array $params=[]): array
{
    $stmt=db()->prepare($sql);$stmt->execute($params);$row=$stmt->fetch();return is_array($row)?$row:[];
}

function payroll_tax_agent_collect(array $company,array $policy,string $sourceRevision): array
{
    $companyId=(string)$company['id'];$today=canadian_today();$findings=[];$processed=0;$materiality=max(0,(int)$policy['materialityCents']);
    $settings=payroll_tax_scalar_row('SELECT * FROM payroll_settings WHERE company_id=? AND active=1 LIMIT 1',[$companyId]);
    $payrollExpected=in_array((string)($company['module_mode']??'both'),['payroll','both'],true);
    if($payrollExpected&&!$settings){
        $evidence=['records'=>[['type'=>'company','id'=>$companyId]],'affectedCount'=>1,'check'=>'payroll_setup','status'=>'Waiting for User','accountingWrites'=>0,'providerAttempts'=>0];
        $findings[]=native_agent_finding_spec('payroll_tax','payroll_setup_incomplete',$companyId,'warning',10000,'Payroll setup is incomplete','Payroll is enabled for this company mode, but no active payroll settings record exists. Complete the normal Payroll setup before calculating or recording payroll.',$evidence,'nav.payroll',[],$today.' 00:00:00');
    }
    if($settings){
        $draft=payroll_tax_scalar_row("SELECT COUNT(DISTINCT r.id) run_count,COUNT(i.id) item_count,SUM(CASE WHEN i.verified_income_tax_cents IS NULL THEN 1 ELSE 0 END) unverified_count,MIN(r.pay_date) earliest_pay_date FROM payroll_runs r JOIN payroll_run_items i ON i.payroll_run_id=r.id WHERE r.company_id=? AND r.status='draft'",[$companyId]);$processed+=(int)($draft['run_count']??0);
        if((int)($draft['unverified_count']??0)>0){$count=(int)$draft['unverified_count'];$evidence=['records'=>[['type'=>'payroll_run_group','id'=>'draft-unverified']], 'affectedCount'=>$count,'draftRuns'=>(int)$draft['run_count'],'unverifiedEmployees'=>$count,'earliestPayDate'=>$draft['earliest_pay_date'],'officialVerificationRequired'=>true,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','payroll_tax_verification_required',$companyId.'|draft-unverified','critical',10000,'Draft payroll needs official tax verification','One or more draft payroll employees does not have a verified income-tax amount. Tegh will not finalise, post or pay this payroll automatically.',$evidence,'nav.payroll_verification',[],$draft['earliest_pay_date']?((string)$draft['earliest_pay_date'].' 00:00:00'):null);}

        $ready=payroll_tax_scalar_row("SELECT COUNT(*) run_count,COALESCE(SUM(net_pay_cents),0) net_cents,MIN(pay_date) earliest_pay_date FROM payroll_runs WHERE company_id=? AND status='verified' AND gl_status='ready_to_post'",[$companyId]);$processed+=(int)($ready['run_count']??0);
        if((int)($ready['run_count']??0)>0){$evidence=['records'=>[['type'=>'payroll_run_group','id'=>'verified-ready']], 'affectedCount'=>(int)$ready['run_count'],'netPayCents'=>(int)$ready['net_cents'],'earliestPayDate'=>$ready['earliest_pay_date'],'humanCommitRequired'=>true,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','payroll_gl_review_ready',$companyId.'|verified-ready','warning',10000,'Verified payroll is ready for GL review','Verified payroll journal drafts are waiting for an owner to review and post through the existing Payroll workflow. No entry was posted by the agent.',$evidence,'nav.payroll_runs',[],$ready['earliest_pay_date']?((string)$ready['earliest_pay_date'].' 00:00:00'):null);}

        $unpaid=payroll_tax_scalar_row("SELECT COUNT(*) run_count,COALESCE(SUM(net_pay_cents),0) net_cents,MIN(pay_date) earliest_pay_date FROM payroll_runs WHERE company_id=? AND status='posted' AND payment_date IS NULL",[$companyId]);$processed+=(int)($unpaid['run_count']??0);
        if((int)($unpaid['run_count']??0)>0){$evidence=['records'=>[['type'=>'payroll_run_group','id'=>'posted-unpaid']], 'affectedCount'=>(int)$unpaid['run_count'],'netPayCents'=>(int)$unpaid['net_cents'],'earliestPayDate'=>$unpaid['earliest_pay_date'],'paymentInitiated'=>false,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','payroll_payment_evidence_required',$companyId.'|posted-unpaid','critical',10000,'Posted payroll needs payment review','Posted payroll remains without recorded payment evidence. Tegh has not initiated a bank payment or marked the runs paid.',$evidence,'nav.payroll_runs',[],$unpaid['earliest_pay_date']?((string)$unpaid['earliest_pay_date'].' 00:00:00'):null);}

        $liabilityAccounts=array_values(array_unique(array_filter([(string)($settings['tax_payable_account_id']??''),(string)($settings['cpp_payable_account_id']??''),(string)($settings['ei_payable_account_id']??'')])));
        $liability=0;if($liabilityAccounts){$place=implode(',',array_fill(0,count($liabilityAccounts),'?'));$stmt=db()->prepare("SELECT COALESCE(SUM(jl.credit_cents-jl.debit_cents),0) balance_cents FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.status='posted' AND je.entry_date<=? AND jl.account_id IN ($place)");$stmt->execute(array_merge([$companyId,$today],$liabilityAccounts));$liability=max(0,(int)$stmt->fetchColumn());}
        $latestPay=payroll_tax_scalar_row("SELECT MAX(pay_date) latest_pay_date FROM payroll_runs WHERE company_id=? AND status IN ('posted','paid')",[$companyId]);$reviewDays=(int)($policy['thresholds']['payrollRemittanceReviewDays']??15);$age=0;if(!empty($latestPay['latest_pay_date']))$age=max(0,(int)(new DateTimeImmutable((string)$latestPay['latest_pay_date']))->diff(new DateTimeImmutable($today))->format('%a'));
        if($liability>max(0,$materiality)&&$age>=$reviewDays){$evidence=['records'=>array_map(static fn(string $id):array=>['type'=>'account','id'=>$id],$liabilityAccounts),'affectedCount'=>count($liabilityAccounts),'sourceDeductionLiabilityCents'=>$liability,'latestPayrollPayDate'=>$latestPay['latest_pay_date']??null,'reviewAgeDays'=>$age,'companyReviewThresholdDays'=>$reviewDays,'statutoryDueDateInferred'=>false,'paymentInitiated'=>false,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','payroll_remittance_review',$companyId.'|'.$liability.'|'.($latestPay['latest_pay_date']??''),'critical',10000,'Payroll source deductions need remittance review','Posted source-deduction liability remains above company materiality beyond the company review threshold. Tegh does not infer the statutory due date and has not created or paid a remittance.',$evidence,'nav.payroll_remittance',[],$latestPay['latest_pay_date']?((string)$latestPay['latest_pay_date'].' 00:00:00'):null);}

        $remittanceStmt=db()->prepare("SELECT pr.id,pr.period_end,pr.payment_date,pr.total_cents,pr.journal_entry_id,pr.bank_transaction_id,
          je.id linked_journal_id,
          COALESCE(SUM(CASE WHEN jl.account_id IN (?,?,?) THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) liability_relief_cents,
          MAX(bt.amount_cents) bank_amount_cents,MAX(bt.status) bank_status,MAX(bt.journal_entry_id) bank_journal_entry_id
          FROM payroll_remittances pr
          LEFT JOIN journal_entries je ON je.id=pr.journal_entry_id AND je.company_id=pr.company_id AND je.status='posted'
          LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id
          LEFT JOIN bank_transactions bt ON bt.id=pr.bank_transaction_id AND bt.company_id=pr.company_id
          WHERE pr.company_id=? AND pr.status='posted'
          GROUP BY pr.id,pr.period_end,pr.payment_date,pr.total_cents,pr.journal_entry_id,pr.bank_transaction_id,je.id
          ORDER BY pr.payment_date,pr.id LIMIT 100");
        $remittanceStmt->execute([(string)$settings['tax_payable_account_id'],(string)$settings['cpp_payable_account_id'],(string)$settings['ei_payable_account_id'],$companyId]);
        $remittanceIssues=[];
        foreach($remittanceStmt->fetchAll() as $row){
            $reasons=[];$journalId=(string)($row['journal_entry_id']??'');$bankTransactionId=(string)($row['bank_transaction_id']??'');$total=(int)$row['total_cents'];
            if((string)($company['payroll_posting_mode']??'draft')!=='none'&&$journalId==='')$reasons[]='No linked posted remittance journal is retained.';
            if($journalId!==''&&((string)($row['linked_journal_id']??'')===''||(int)$row['liability_relief_cents']!==$total))$reasons[]='The linked journal does not relieve the stored payroll liabilities by the remittance total.';
            if($bankTransactionId!==''&&((int)($row['bank_amount_cents']??0)!==-$total||(string)($row['bank_status']??'')!=='posted'||(string)($row['bank_journal_entry_id']??'')!==$journalId))$reasons[]='The linked bank evidence does not exactly match the stored remittance.';
            if($reasons)$remittanceIssues[]=['id'=>(string)$row['id'],'periodEnd'=>(string)$row['period_end'],'paymentDate'=>(string)$row['payment_date'],'totalCents'=>$total,'reasons'=>$reasons];
        }
        if($remittanceIssues){$records=array_map(static fn(array $row):array=>['type'=>'payroll_remittance','id'=>$row['id']],$remittanceIssues);$evidence=['records'=>$records,'affectedCount'=>count($remittanceIssues),'issues'=>$remittanceIssues,'humanReconciliationRequired'=>true,'paymentInitiated'=>false,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','payroll_remittance_evidence_mismatch',$companyId.'|'.native_agent_hash($remittanceIssues),'critical',10000,'Payroll remittance evidence needs reconciliation','One or more posted remittance records does not reconcile to its retained journal or bank evidence. Tegh has not changed, reversed, recreated or paid any remittance.',$evidence,'nav.payroll_remittance',[],$remittanceIssues[0]['paymentDate'].' 00:00:00');}

        $incomplete=payroll_tax_scalar_row("SELECT COUNT(*) employee_count FROM payroll_employees WHERE company_id=? AND active=1 AND (sin_last_four IS NULL OR federal_td1_cents IS NULL OR provincial_td1_cents IS NULL)",[$companyId]);
        if((int)($incomplete['employee_count']??0)>0){$count=(int)$incomplete['employee_count'];$evidence=['records'=>[['type'=>'payroll_employee_group','id'=>'incomplete-profiles']],'affectedCount'=>$count,'missingProfileCount'=>$count,'sensitiveValuesIncluded'=>false,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','payroll_employee_profile_review',$companyId.'|incomplete-profiles','warning',10000,'Employee payroll profiles need review','Active employee profiles are missing one or more SIN/TD1 readiness indicators. The finding stores only a count and never exposes SIN ciphertext or employee tax values.',$evidence,'nav.payroll_employees',[],$today.' 00:00:00');}
    }

    $year=(int)substr($today,0,4);$nextYear=$year+1;$yearEnd=new DateTimeImmutable($year.'-12-31');$daysToYearEnd=(int)(new DateTimeImmutable($today))->diff($yearEnd)->format('%r%a');$rateWindow=(int)($policy['thresholds']['payrollRateReviewDays']??150);
    if($settings&&$daysToYearEnd>=0&&$daysToYearEnd<=$rateWindow){$completeRelease=false;try{payroll_rates_for_date($nextYear.'-01-01',$companyId);$completeRelease=true;}catch(InvalidArgumentException){}if(!$completeRelease){$evidence=['records'=>[['type'=>'statutory_rate_release','id'=>(string)$nextYear]],'affectedCount'=>1,'targetYear'=>$nextYear,'daysToYearEnd'=>$daysToYearEnd,'completeApprovedRelease'=>false,'rateChangesApplied'=>false,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','next_year_rate_release_missing',$companyId.'|'.$nextYear,'warning',10000,'Next-year payroll rate pack is not approved','The next payroll year is within the company review window, but Tegh cannot find a complete approved effective-dated rate release. The Platform Owner can check Payroll updates and activate a supported release. Company users do not need to enter rate tables.',$evidence,'nav.payroll_rates',[],$today.' 00:00:00');}}

    if($settings&&$daysToYearEnd>=0&&$daysToYearEnd<=$rateWindow){
        $t4=payroll_tax_scalar_row("SELECT COUNT(DISTINCT r.id) run_count,COUNT(i.id) item_count,COUNT(DISTINCT i.employee_id) employee_count,
          SUM(CASE WHEN i.id IS NOT NULL AND (e.sin_last_four IS NULL OR e.sin_last_four='') THEN 1 ELSE 0 END) missing_profile_count,
          SUM(CASE WHEN i.id IS NOT NULL AND (i.verified_income_tax_cents IS NULL OR i.verification_status NOT IN ('official_verified','manual_override')) THEN 1 ELSE 0 END) unverified_item_count,
          SUM(CASE WHEN i.id IS NULL THEN 1 ELSE 0 END) runs_without_items
          FROM payroll_runs r LEFT JOIN payroll_run_items i ON i.payroll_run_id=r.id LEFT JOIN payroll_employees e ON e.id=i.employee_id
          WHERE r.company_id=? AND r.status IN ('posted','paid') AND YEAR(r.pay_date)=?",[$companyId,$year]);
        $runCount=(int)($t4['run_count']??0);
        if($runCount>0){$missing=(int)($t4['missing_profile_count']??0);$unverified=(int)($t4['unverified_item_count']??0);$withoutItems=(int)($t4['runs_without_items']??0);$severity=($missing+$unverified+$withoutItems)>0?'critical':'warning';$evidence=['records'=>[['type'=>'payroll_year','id'=>(string)$year]],'affectedCount'=>$runCount,'year'=>$year,'postedOrPaidRunCount'=>$runCount,'employeeCount'=>(int)($t4['employee_count']??0),'missingProfileCount'=>$missing,'unverifiedItemCount'=>$unverified,'runsWithoutRetainedItems'=>$withoutItems,'workingPaperOnly'=>true,'filingReady'=>false,'externalYearEndItemsUnableToVerify'=>true,'t4XmlGenerated'=>false,'filingSubmitted'=>false,'sensitiveValuesIncluded'=>false,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','payroll_t4_working_paper_review',$companyId.'|'.$year.'|'.native_agent_hash($evidence),$severity,10000,'T4 working-paper readiness needs human review','Current-year posted payroll supports a working-paper review only. Tegh cannot verify external year-end adjustments, official slip requirements or filing completion, and it has not generated or submitted a T4 return.',$evidence,'nav.payroll_history',[],$today.' 00:00:00');}
    }

    $levy=payroll_tax_scalar_row("SELECT p.jurisdiction,p.applicability,(SELECT COUNT(*) FROM employer_levy_rates r WHERE r.company_id=p.company_id AND r.jurisdiction=p.jurisdiction AND r.active=1 AND r.effective_from<=? AND (r.effective_to IS NULL OR r.effective_to>=?)) active_rates FROM employer_levy_profiles p WHERE p.company_id=? AND p.applicability<>'not_applicable' LIMIT 1",[$today,$today,$companyId]);if($levy&&(int)$levy['active_rates']===0){$evidence=['records'=>[['type'=>'employer_levy_profile','id'=>$companyId]],'affectedCount'=>1,'jurisdiction'=>(string)$levy['jurisdiction'],'activeEffectiveRate'=>false,'rateChangesApplied'=>false,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','employer_levy_rate_missing',$companyId.'|'.(string)$levy['jurisdiction'],'critical',10000,'Employer levy rate requires review','The company has an applicable employer-levy profile but no active rate covering today. Tegh will not guess or change a statutory rate.',$evidence,'nav.payroll_rates',[],$today.' 00:00:00');}

    if(!empty($company['tax_registered'])||!empty($company['pst_registered'])){$stmt=db()->prepare("SELECT a.id,a.code,a.name,COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<=? THEN jl.credit_cents-jl.debit_cents ELSE 0 END),0) balance_cents FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id WHERE a.company_id=? AND a.code IN ('1100','2100') GROUP BY a.id,a.code,a.name ORDER BY a.code");$stmt->execute([$today,$companyId]);$taxRows=$stmt->fetchAll();$taxBalance=0;$records=[];foreach($taxRows as $row){$taxBalance+=(int)$row['balance_cents'];$records[]=['type'=>'account','id'=>(string)$row['id']];}if(abs($taxBalance)>$materiality){$evidence=['records'=>$records,'affectedCount'=>count($records),'netSalesTaxControlCents'=>$taxBalance,'companyMaterialityCents'=>$materiality,'filingFrequencyKnown'=>false,'statutoryDueDateInferred'=>false,'filingSubmitted'=>false,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','sales_tax_control_review',$companyId.'|'.$taxBalance,'warning',10000,'Sales-tax control balance needs period review','The posted GST/HST/PST control balance exceeds company materiality. Tegh cannot infer the filing frequency or due date from incomplete evidence and has not prepared or submitted a return.',$evidence,'nav.tax_settings',[],$today.' 00:00:00');}}

    $staleDays=max(1,(int)($policy['staleDays']??30));$staleCutoff=(new DateTimeImmutable($today))->modify('-'.$staleDays.' days')->format('Y-m-d');
    $taxBankStmt=db()->prepare("SELECT id,transaction_date,amount_cents FROM bank_transactions
      WHERE company_id=? AND status='pending' AND transaction_date<=?
      AND LOWER(CONCAT_WS(' ',description,COALESCE(reference,''),COALESCE(normalized_merchant,'')))
          REGEXP '(^|[^a-z])(cra|canada revenue|receiver general|gst|hst|pst|source deduction|payroll remittance)([^a-z]|$)'
      ORDER BY transaction_date,id LIMIT 100");
    $taxBankStmt->execute([$companyId,$staleCutoff]);$taxBankRows=$taxBankStmt->fetchAll();
    if($taxBankRows){$records=array_map(static fn(array $row):array=>['type'=>'bank_transaction','id'=>(string)$row['id']],$taxBankRows);$oldest=(string)$taxBankRows[0]['transaction_date'];$total=array_sum(array_map(static fn(array $row):int=>(int)$row['amount_cents'],$taxBankRows));$evidence=['records'=>$records,'affectedCount'=>count($records),'oldestTransactionDate'=>$oldest,'netAmountCents'=>$total,'companyStaleThresholdDays'=>$staleDays,'keywordCandidateOnly'=>true,'humanClassificationRequired'=>true,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','stale_tax_bank_evidence',$companyId.'|'.native_agent_hash(array_column($taxBankRows,'id')),'warning',7000,'Possible tax-related bank items need review','One or more stale pending bank descriptions contains a payroll or sales-tax keyword. This is a keyword-based review prompt, not proof of a tax payment, filing or liability.',$evidence,'nav.bank_review',[],$oldest.' 00:00:00');}

    $locked=payroll_tax_scalar_row("SELECT COUNT(DISTINCT r.id) run_count,MIN(r.pay_date) earliest_pay_date FROM payroll_runs r JOIN period_locks l ON l.company_id=r.company_id AND l.locked=1 AND r.pay_date BETWEEN l.period_start AND l.period_end WHERE r.company_id=? AND r.status IN ('draft','verified')",[$companyId]);if((int)($locked['run_count']??0)>0){$evidence=['records'=>[['type'=>'payroll_run_group','id'=>'period-locked']], 'affectedCount'=>(int)$locked['run_count'],'earliestPayDate'=>$locked['earliest_pay_date'],'periodLockEnforced'=>true,'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('payroll_tax','payroll_period_lock',$companyId.'|period-locked','critical',10000,'A period lock blocks payroll progression','Draft or verified payroll falls inside a locked period. The agent has not bypassed the lock or changed any payroll state.',$evidence,'nav.payroll_runs',[],$locked['earliest_pay_date']?((string)$locked['earliest_pay_date'].' 00:00:00'):null);}

    return ['sourceRevisionHash'=>$sourceRevision,'findings'=>$findings,'processedCount'=>$processed,'cursor'=>['current'=>'','next'=>'','cycleComplete'=>true],'summary'=>['checks'=>13,'findingCandidates'=>count($findings),'accountingWrites'=>0,'providerAttempts'=>0,'filingsSubmitted'=>0,'paymentsInitiated'=>0,'sensitiveEmployeeValuesExposed'=>0]];
}

function payroll_tax_autonomy_policy_hash(array $policy): string
{
    return native_agent_hash(['companyId'=>$policy['companyId'],'actionId'=>$policy['actionId'],'enabled'=>(bool)$policy['enabled'],'maxSeverity'=>$policy['maxSeverity'],'dailyLimit'=>(int)$policy['dailyLimit'],'cooldownMinutes'=>(int)$policy['cooldownMinutes'],'maxSnoozeDays'=>(int)$policy['maxSnoozeDays'],'constraints'=>$policy['constraints'],'revision'=>(int)$policy['revision'],'suspendedAt'=>$policy['suspendedAt'],'suspendedBy'=>$policy['suspendedBy']??null,'suspensionReason'=>(string)($policy['suspensionReason']??'')]);
}

function payroll_tax_autonomy_policy_from_row(array $row): array
{
    $constraints=json_decode((string)$row['constraints_json'],true);if(!is_array($constraints))$constraints=[];$policy=['id'=>(string)$row['id'],'companyId'=>(string)$row['company_id'],'actionId'=>(string)$row['action_id'],'enabled'=>(bool)$row['enabled'],'maxSeverity'=>(string)$row['max_severity'],'dailyLimit'=>(int)$row['daily_limit'],'cooldownMinutes'=>(int)$row['cooldown_minutes'],'maxSnoozeDays'=>(int)$row['max_snooze_days'],'constraints'=>$constraints,'revision'=>(int)$row['revision'],'policyHash'=>(string)$row['policy_hash'],'suspendedAt'=>$row['suspended_at'],'suspendedBy'=>$row['suspended_by'],'suspensionReason'=>(string)$row['suspension_reason'],'updatedAt'=>(string)$row['updated_at']];return $policy;
}

function payroll_tax_autonomy_registry_action(string $actionId,bool $throw=false): array
{
    $action=tegh_action_registry()[$actionId]??null;
    if(!is_array($action)
        ||(string)($action['action_type']??'')!=='metadata'
        ||tegh_action_execution_class($action)!=='immediate'
        ||!empty($action['financial_commit'])
        ||!empty($action['destructive'])
        ||!empty($action['supports_navigation'])
        ||!empty($action['supports_ai_execution'])
        ||(string)($action['confirmation_requirement']??'none')!=='none'
        ||(string)($action['required_permission']??'')!=='company.settings'){
        if($throw)payroll_tax_autonomy_reject('The live Action Registry does not permit that bounded metadata action.',409,'autonomy_action_denied');
        fail('The live Action Registry does not permit that bounded metadata action.',409,'autonomy_action_denied');
    }
    return $action;
}

function payroll_tax_autonomy_policies(array $company): array
{
    require_company_permission($company,'company.view');$stmt=db()->prepare('SELECT * FROM native_agent_autonomy_policies WHERE company_id=? ORDER BY action_id');$stmt->execute([(string)$company['id']]);$by=[];foreach($stmt->fetchAll() as $row){$policy=payroll_tax_autonomy_policy_from_row($row);$policy['integrityValid']=hash_equals((string)$policy['policyHash'],payroll_tax_autonomy_policy_hash($policy));$by[$policy['actionId']]=$policy;}$out=[];foreach(PAYROLL_TAX_AUTONOMY_ACTIONS as $actionId)$out[]=$by[$actionId]??['id'=>null,'companyId'=>(string)$company['id'],'actionId'=>$actionId,'enabled'=>false,'maxSeverity'=>'info','dailyLimit'=>0,'cooldownMinutes'=>60,'maxSnoozeDays'=>7,'constraints'=>[],'revision'=>0,'policyHash'=>'','suspendedAt'=>null,'suspendedBy'=>null,'suspensionReason'=>'','updatedAt'=>null,'integrityValid'=>true];return $out;
}

function payroll_tax_autonomy_policy_save(array $user,array $company,array $input): array
{
    require_company_role($company,'owner');$actionId=clean_text($input['actionId']??'','Autonomy action',120);if(!in_array($actionId,PAYROLL_TAX_AUTONOMY_ACTIONS,true))fail('That action is outside the permanent low-risk autonomy allowlist.',422,'autonomy_action_denied');payroll_tax_autonomy_registry_action($actionId);
    $existingStmt=db()->prepare('SELECT * FROM native_agent_autonomy_policies WHERE company_id=? AND action_id=? LIMIT 1');$existingStmt->execute([(string)$company['id'],$actionId]);$existing=$existingStmt->fetch();$expected=(int)($input['expectedRevision']??0);if($existing&&$expected!==(int)$existing['revision'])fail('The autonomy policy changed after it was loaded. Refresh before saving.',409,'autonomy_policy_changed');if(!$existing&&$expected!==0)fail('The autonomy policy does not yet exist. Refresh before saving.',409,'autonomy_policy_changed');
    $enabled=native_agent_bool($input,'enabled',false);$severity=(string)($input['maxSeverity']??'info');if(!in_array($severity,['info','warning'],true))fail('Autonomy can never include critical findings.',422,'autonomy_severity_denied');$daily=native_agent_int($input,'dailyLimit',0,0,100);if($enabled&&$daily<1)fail('Choose a positive daily limit before enabling autonomy.',422,'autonomy_limit_required');$cooldown=native_agent_int($input,'cooldownMinutes',60,1,10080);$snooze=native_agent_int($input,'maxSnoozeDays',7,1,30);$constraints=is_array($input['constraints']??null)?$input['constraints']:[];$allowedConstraintKeys=['assignedUserId','snoozeDays'];foreach(array_keys($constraints) as $key)if(!in_array((string)$key,$allowedConstraintKeys,true))fail('The autonomy policy contains an unsupported constraint.',422,'autonomy_constraint_invalid');if(isset($constraints['snoozeDays']))$constraints['snoozeDays']=max(1,min($snooze,(int)$constraints['snoozeDays']));if(isset($constraints['assignedUserId'])){$assignee=clean_text($constraints['assignedUserId'],'Assigned user',64);$member=db()->prepare("SELECT 1 FROM company_members WHERE company_id=? AND user_id=? AND status='active' LIMIT 1");$member->execute([(string)$company['id'],$assignee]);if(!$member->fetchColumn())fail('Choose an active current-company member.',422,'autonomy_assignee_invalid');$constraints['assignedUserId']=$assignee;}
    $suspend=!empty($input['suspended']);$reason=$suspend?clean_text($input['suspensionReason']??'Owner suspension','Suspension reason',500):'';$revision=$existing?(int)$existing['revision']+1:1;$policy=['companyId'=>(string)$company['id'],'actionId'=>$actionId,'enabled'=>$enabled,'maxSeverity'=>$severity,'dailyLimit'=>$daily,'cooldownMinutes'=>$cooldown,'maxSnoozeDays'=>$snooze,'constraints'=>$constraints,'revision'=>$revision,'suspendedAt'=>$suspend?gmdate('Y-m-d H:i:s'):null,'suspendedBy'=>$suspend?(string)$user['id']:null,'suspensionReason'=>$reason];$hash=payroll_tax_autonomy_policy_hash($policy);$id=$existing?(string)$existing['id']:new_id('naauto');
    if($existing)db()->prepare('UPDATE native_agent_autonomy_policies SET enabled=?,max_severity=?,daily_limit=?,cooldown_minutes=?,max_snooze_days=?,constraints_json=?,revision=?,policy_hash=?,suspended_at=?,suspended_by=?,suspension_reason=?,updated_by=? WHERE id=? AND company_id=? AND revision=?')->execute([$enabled?1:0,$severity,$daily,$cooldown,$snooze,native_agent_json($constraints),$revision,$hash,$policy['suspendedAt'],$suspend?(string)$user['id']:null,$reason,(string)$user['id'],$id,(string)$company['id'],$expected]);
    else db()->prepare('INSERT INTO native_agent_autonomy_policies (id,company_id,action_id,enabled,max_severity,daily_limit,cooldown_minutes,max_snooze_days,constraints_json,revision,policy_hash,suspended_at,suspended_by,suspension_reason,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,(string)$company['id'],$actionId,$enabled?1:0,$severity,$daily,$cooldown,$snooze,native_agent_json($constraints),$revision,$hash,$policy['suspendedAt'],$suspend?(string)$user['id']:null,$reason,(string)$user['id'],(string)$user['id']]);
    audit_event($user,(string)$company['id'],'native_agent.autonomy_policy_updated','native_agent_autonomy_policy',$id,['actionId'=>$actionId,'enabled'=>$enabled,'maxSeverity'=>$severity,'dailyLimit'=>$daily,'cooldownMinutes'=>$cooldown,'revision'=>$revision,'policyHash'=>$hash,'suspended'=>$suspend]);return payroll_tax_autonomy_policies($company);
}

function payroll_tax_autonomy_severity_allowed(string $actual,string $maximum): bool
{
    $rank=['info'=>1,'warning'=>2,'critical'=>3];return ($rank[$actual]??99)<=($rank[$maximum]??0)&&$actual!=='critical';
}

function payroll_tax_autonomy_task_context(array $user,array $company,array $finding): array
{
    if(!schema_table_exists('ai_agent_tasks'))payroll_tax_autonomy_reject('Review-task storage is not ready.',503,'autonomy_task_storage_required');
    $actionId=(string)($finding['proposed_action_id']??'');$action=tegh_action_registry()[$actionId]??null;
    if(!is_array($action)||!empty($action['financial_commit'])||!empty($action['destructive'])||tegh_action_execution_class($action)==='authorized')payroll_tax_autonomy_reject('The finding no longer proposes a safe review workflow.',409,'autonomy_task_action_changed');
    $permission=(string)($action['required_permission']??'');if($permission!==''&&!company_role_can((string)$company['role'],$permission))payroll_tax_autonomy_reject('Your current company role cannot open the finding workflow.',403,'permission_forbidden');
    $inputs=json_decode((string)($finding['proposed_inputs_json']??'{}'),true);if(!is_array($inputs))$inputs=[];try{native_agent_validate_proposal($actionId,$inputs);}catch(Throwable){payroll_tax_autonomy_reject('The finding inputs no longer match the live Action Registry.',409,'autonomy_task_action_changed');}
    $existing=null;if(!empty($finding['accepted_task_id'])){$stmt=db()->prepare('SELECT id,user_id,status FROM ai_agent_tasks WHERE id=? AND company_id=? LIMIT 1');$stmt->execute([(string)$finding['accepted_task_id'],(string)$company['id']]);$row=$stmt->fetch();if($row&&in_array((string)$row['status'],['active','waiting_input','waiting_confirmation','suspended'],true)){$existing=$row;if((string)$row['user_id']!==(string)$user['id'])payroll_tax_autonomy_reject('This finding is already assigned to another active reviewer.',409,'autonomy_task_already_assigned');}}
    $required=array_values(array_map('strval',(array)($action['required_inputs']??[])));$missing=array_values(array_filter($required,static fn(string $key):bool=>!array_key_exists($key,$inputs)));
    return ['action'=>$action,'actionId'=>$actionId,'inputs'=>$inputs,'missing'=>$missing,'existing'=>$existing];
}

function payroll_tax_autonomy_prepare_task(array $user,array $company,array $finding,array $context): array
{
    if(is_array($context['existing']??null))return ['taskId'=>(string)$context['existing']['id'],'reused'=>true];
    $pdo=db();$pdo->beginTransaction();try{
        $freshStmt=$pdo->prepare('SELECT accepted_task_id FROM native_agent_findings WHERE id=? AND company_id=? FOR UPDATE');$freshStmt->execute([(string)$finding['id'],(string)$company['id']]);$fresh=$freshStmt->fetch();if(!$fresh)throw new RuntimeException('The finding disappeared while its task was being prepared.');
        if(!empty($fresh['accepted_task_id'])){$taskStmt=$pdo->prepare('SELECT id,user_id,status FROM ai_agent_tasks WHERE id=? AND company_id=? LIMIT 1');$taskStmt->execute([(string)$fresh['accepted_task_id'],(string)$company['id']]);$task=$taskStmt->fetch();if($task&&in_array((string)$task['status'],['active','waiting_input','waiting_confirmation','suspended'],true)){if((string)$task['user_id']!==(string)$user['id'])throw new RuntimeException('The finding was assigned to another reviewer concurrently.');$pdo->commit();return ['taskId'=>(string)$task['id'],'reused'=>true];}}
        $taskId=new_id('aitask');$status=$context['missing']?'waiting_input':'active';$action=(array)$context['action'];$pdo->prepare('INSERT INTO ai_agent_tasks (id,company_id,user_id,action_id,intent,module,workflow_state,collected_json,missing_json,status) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$taskId,(string)$company['id'],(string)$user['id'],(string)$context['actionId'],mb_substr('Bounded review task: '.(string)$finding['title'],0,500),(string)($action['module']??'Agent Center'),'finding_accepted',native_agent_json($context['inputs']),native_agent_json($context['missing']),$status]);
        $pdo->prepare('UPDATE native_agent_findings SET accepted_task_id=?,accepted_at=UTC_TIMESTAMP(),accepted_by=?,assigned_user_id=? WHERE id=? AND company_id=?')->execute([$taskId,(string)$user['id'],(string)$user['id'],(string)$finding['id'],(string)$company['id']]);
        audit_event($user,(string)$company['id'],'native_agent.autonomy_task_prepared','native_agent_finding',(string)$finding['id'],['actionId'=>(string)$context['actionId'],'taskId'=>$taskId,'sourceRevisionHash'=>(string)$finding['source_revision_hash'],'accountingCommit'=>false]);$pdo->commit();return ['taskId'=>$taskId,'reused'=>false];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function payroll_tax_autonomy_execute(array $user,array $company,array $input,bool $respond=true): array
{
    $companyId=(string)($company['id']??'');$actionId=trim((string)($input['actionId']??''));$findingId=trim((string)($input['findingId']??''));$lockName='';$locked=false;$runId='';$rejection=null;$unexpected=null;
    try{
        if($companyId===''||!company_role_can((string)($company['role']??''),'company.view'))payroll_tax_autonomy_reject('You do not have access to this company.',403,'permission_forbidden');
        if($actionId===''||strlen($actionId)>120||!in_array($actionId,PAYROLL_TAX_AUTONOMY_ACTIONS,true))payroll_tax_autonomy_reject('That action is outside the permanent autonomy allowlist.',403,'autonomy_action_denied');
        $action=payroll_tax_autonomy_registry_action($actionId,true);$permission=(string)($action['required_permission']??'');if($permission!==''&&!company_role_can((string)$company['role'],$permission))payroll_tax_autonomy_reject('Your current company role cannot execute bounded autonomy.',403,'permission_forbidden');
        if($findingId===''||strlen($findingId)>64||!preg_match('/^[A-Za-z0-9_-]+$/',$findingId))payroll_tax_autonomy_reject('Choose a valid current-company finding.',422,'autonomy_finding_invalid');
        // Serialize by company and action, not only by finding. This makes the
        // daily cap and cooling-off check race-safe across different findings.
        $lockName='tegh_auto_'.substr(hash('sha256',$companyId.'|'.$actionId),0,44);$lock=db()->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);$locked=(int)$lock->fetchColumn()===1;if(!$locked)payroll_tax_autonomy_reject('The same bounded action is already being checked for this company.',409,'autonomy_action_busy');
        $stmt=db()->prepare('SELECT * FROM native_agent_autonomy_policies WHERE company_id=? AND action_id=? LIMIT 1');$stmt->execute([$companyId,$actionId]);$row=$stmt->fetch();if(!$row)payroll_tax_autonomy_reject('This autonomy action is disabled by default.',403,'autonomy_policy_disabled');$policy=payroll_tax_autonomy_policy_from_row($row);if(!$policy['enabled']||$policy['suspendedAt']!==null||$policy['dailyLimit']<1)payroll_tax_autonomy_reject('This autonomy action is disabled or suspended.',403,'autonomy_policy_disabled');if(!hash_equals((string)$policy['policyHash'],payroll_tax_autonomy_policy_hash($policy)))payroll_tax_autonomy_reject('The autonomy policy failed its integrity check.',409,'autonomy_policy_integrity_failed');
        $findingStmt=db()->prepare('SELECT * FROM native_agent_findings WHERE id=? AND company_id=? LIMIT 1');$findingStmt->execute([$findingId,$companyId]);$finding=$findingStmt->fetch();if(!$finding)payroll_tax_autonomy_reject('That finding is not available in the current company.',404,'native_agent_finding_not_found');if(!payroll_tax_autonomy_severity_allowed((string)$finding['severity'],(string)$policy['maxSeverity']))payroll_tax_autonomy_reject('The finding severity exceeds this autonomy policy. Critical findings always require a person.',403,'autonomy_severity_denied');
        $context=[];$evidence=json_decode((string)$finding['evidence_json'],true);if(!is_array($evidence))$evidence=[];if((string)$finding['agent_type']==='month_end_close')$context['period']=substr((string)($evidence['period']['start']??''),0,7);$source=native_agent_source_revision($company,(string)$finding['agent_type'],$context);if(!hash_equals((string)$finding['source_revision_hash'],$source))payroll_tax_autonomy_reject('The finding evidence changed. Refresh the specialist before any bounded action.',409,'autonomy_source_changed');
        $idempotency=hash('sha256',native_agent_json(['companyId'=>$companyId,'actionId'=>$actionId,'findingId'=>$findingId,'sourceRevision'=>$source,'policyRevision'=>$policy['revision'],'policyHash'=>$policy['policyHash']]));$prior=db()->prepare('SELECT id,status,result_json FROM native_agent_autonomy_runs WHERE company_id=? AND idempotency_key=? LIMIT 1');$prior->execute([$companyId,$idempotency]);$priorRow=$prior->fetch();if($priorRow){$result=json_decode((string)$priorRow['result_json'],true)?:[];return ['ok'=>(string)$priorRow['status']==='completed','reused'=>true,'runId'=>(string)$priorRow['id'],'status'=>(string)$priorRow['status'],'result'=>$result];}
        $usage=payroll_tax_scalar_row("SELECT SUM(status IN ('running','completed')) used_today,MAX(CASE WHEN status='completed' THEN completed_at END) last_completed FROM native_agent_autonomy_runs WHERE company_id=? AND policy_id=? AND started_at>=UTC_DATE()",[$companyId,(string)$policy['id']]);if((int)($usage['used_today']??0)>=(int)$policy['dailyLimit'])payroll_tax_autonomy_reject('The company daily autonomy limit has been reached.',409,'autonomy_daily_limit');if(!empty($usage['last_completed'])&&time()-strtotime((string)$usage['last_completed'].' UTC')<(int)$policy['cooldownMinutes']*60)payroll_tax_autonomy_reject('This autonomy action is still inside its cooling-off period.',409,'autonomy_cooldown');
        $taskContext=null;$assignee='';if($actionId==='native_agent.finding_snooze'&&(string)$finding['state']!=='open')payroll_tax_autonomy_reject('The finding is no longer open for snoozing.',409,'autonomy_finding_state_changed');
        if($actionId==='native_agent.finding_assign'){$assignee=(string)($policy['constraints']['assignedUserId']??'');if($assignee==='')payroll_tax_autonomy_reject('This policy has no approved assignee.',409,'autonomy_assignee_required');$member=db()->prepare("SELECT 1 FROM company_members WHERE company_id=? AND user_id=? AND status='active' LIMIT 1");$member->execute([$companyId,$assignee]);if(!$member->fetchColumn())payroll_tax_autonomy_reject('The approved assignee is no longer an active company member.',409,'autonomy_assignee_stale');}
        if($actionId==='native_agent.finding_task')$taskContext=payroll_tax_autonomy_task_context($user,$company,$finding);
        if($actionId==='native_agent.finding_resolved_dismiss'&&(string)$finding['state']!=='resolved')payroll_tax_autonomy_reject('Only a deterministically resolved informational finding can be dismissed.',409,'autonomy_finding_not_resolved');
        $result=['accountingWrites'=>0,'financialCommits'=>0,'filingsSubmitted'=>0,'paymentsInitiated'=>0,'messagesSent'=>0,'rateChanges'=>0];$runId=new_id('nautorun');db()->prepare("INSERT INTO native_agent_autonomy_runs (id,company_id,policy_id,finding_id,requested_by,action_id,idempotency_key,policy_revision,policy_hash,source_revision_hash,status,result_json,error_code,error_message,started_at) VALUES (?,?,?,?,?,?,?,?,?,?,'needs_review',?,'bounded_action_unconfirmed','The bounded metadata action has not yet reached a verifiable completion state.',UTC_TIMESTAMP())")->execute([$runId,$companyId,(string)$policy['id'],$findingId,(string)$user['id'],$actionId,$idempotency,(int)$policy['revision'],(string)$policy['policyHash'],$source,native_agent_json($result)]);
        if($actionId==='native_agent.finding_snooze'){$days=max(1,min((int)$policy['maxSnoozeDays'],(int)($policy['constraints']['snoozeDays']??1)));$update=db()->prepare("UPDATE native_agent_findings SET state='snoozed',snoozed_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL $days DAY),snoozed_by=? WHERE id=? AND company_id=? AND state='open'");$update->execute([(string)$user['id'],$findingId,$companyId]);if($update->rowCount()!==1)throw new RuntimeException('Finding state changed during the bounded snooze.');$result+=['action'=>'snoozed','days'=>$days];}
        elseif($actionId==='native_agent.finding_assign'){$update=db()->prepare('UPDATE native_agent_findings SET assigned_user_id=? WHERE id=? AND company_id=?');$update->execute([$assignee,$findingId,$companyId]);if($update->rowCount()!==1)throw new RuntimeException('Finding assignment could not be verified.');$result+=['action'=>'assigned','assignedUserId'=>$assignee];}
        elseif($actionId==='native_agent.finding_task'){$prepared=payroll_tax_autonomy_prepare_task($user,$company,$finding,(array)$taskContext);$result+=['action'=>'task_prepared','taskId'=>$prepared['taskId'],'reusedTask'=>(bool)$prepared['reused'],'accountingCommit'=>false];}
        elseif($actionId==='native_agent.scan_refresh'){$refresh=native_agent_run_specialist($company,native_agent_policy_get($company,$user),(string)$finding['agent_type'],'manual',$user,$context);$result+=['action'=>'scanner_refreshed','specialistStatus'=>$refresh['status']??'unknown'];}
        else{$dismiss=db()->prepare('UPDATE notifications SET dismissed_at=UTC_TIMESTAMP() WHERE company_id=? AND unique_key=? AND dismissed_at IS NULL');$dismiss->execute([$companyId,'native-agent:'.(string)$finding['fingerprint']]);$result+=['action'=>'resolved_notification_dismissed','notificationsDismissed'=>$dismiss->rowCount()];}
        $complete=db()->prepare("UPDATE native_agent_autonomy_runs SET status='completed',result_json=?,error_code=NULL,error_message=NULL,completed_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND status='needs_review'");$complete->execute([native_agent_json($result),$runId,$companyId]);if($complete->rowCount()!==1)throw new RuntimeException('Bounded action completion could not be recorded.');audit_event($user,$companyId,'native_agent.autonomy_completed','native_agent_autonomy_run',$runId,['actionId'=>$actionId,'findingId'=>$findingId,'policyRevision'=>(int)$policy['revision'],'policyHash'=>(string)$policy['policyHash'],'sourceRevisionHash'=>$source]+$result);return ['ok'=>true,'runId'=>$runId,'status'=>'completed','result'=>$result];
    }catch(PayrollTaxAutonomyRejected $error){$rejection=$error;
    }catch(Throwable $error){$unexpected=$error;if($runId!==''){try{db()->prepare("UPDATE native_agent_autonomy_runs SET status='needs_review',error_code='bounded_action_unconfirmed',error_message='The bounded metadata action did not reach a verifiable completion state.',completed_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND status<>'completed'")->execute([$runId,$companyId]);}catch(Throwable $ignored){}}
    }finally{if($locked){try{$release=db()->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable $ignored){}}}
    if($rejection instanceof PayrollTaxAutonomyRejected){if($respond)fail($rejection->getMessage(),$rejection->httpStatus,$rejection->publicCode);throw $rejection;}
    if($unexpected instanceof Throwable){error_log('Bounded autonomy needs review action='.$actionId.' class='.$unexpected::class);if($respond)fail('The bounded metadata action could not be verified and has been retained for human review.',409,'bounded_action_unconfirmed');throw $unexpected;}
    throw new RuntimeException('Bounded autonomy ended without a result.');
}

function payroll_tax_autonomy_apply_eligible(array $user,array $company,int $limit=10): array
{
    if(function_exists('tegh_background_feature_allowed')&&!tegh_background_feature_allowed((string)$company['id'],'module.payroll'))return ['status'=>'disabled','reason'=>'feature_not_entitled','attempted'=>0,'completed'=>0,'results'=>[],'accountingWrites'=>0];
    $limit=max(1,min(25,$limit));$policies=array_values(array_filter(payroll_tax_autonomy_policies($company),static fn(array $policy):bool=>!empty($policy['enabled'])&&$policy['suspendedAt']===null&&(int)$policy['dailyLimit']>0));if(!$policies)return ['status'=>'disabled','attempted'=>0,'completed'=>0,'results'=>[],'accountingWrites'=>0];$claimed=[];$results=[];
    foreach($policies as $policy){if(count($results)>=$limit)break;$state=(string)$policy['actionId']==='native_agent.finding_resolved_dismiss'?'resolved':'open';$severity=(string)$policy['maxSeverity'];$stmt=db()->prepare("SELECT id FROM native_agent_findings WHERE company_id=? AND state=? AND severity IN (".($severity==='warning'?"'info','warning'":"'info'").") ORDER BY last_seen_at,id LIMIT ".($limit*2));$stmt->execute([(string)$company['id'],$state]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $findingId){$findingId=(string)$findingId;if(isset($claimed[$findingId])||count($results)>=$limit)continue;$claimed[$findingId]=true;try{$result=payroll_tax_autonomy_execute($user,$company,['actionId'=>(string)$policy['actionId'],'findingId'=>$findingId],false);$results[]=['actionId'=>(string)$policy['actionId'],'findingId'=>$findingId,'status'=>(string)($result['status']??'completed'),'runId'=>$result['runId']??null];}catch(Throwable $error){$results[]=['actionId'=>(string)$policy['actionId'],'findingId'=>$findingId,'status'=>'skipped','errorCode'=>$error instanceof PayrollTaxAutonomyRejected?$error->publicCode:'bounded_action_unconfirmed'];}}
    }
    $completed=count(array_filter($results,static fn(array $row):bool=>$row['status']==='completed'));return ['status'=>'completed','attempted'=>count($results),'completed'=>$completed,'results'=>$results,'accountingWrites'=>0,'financialCommits'=>0,'filingsSubmitted'=>0,'paymentsInitiated'=>0,'messagesSent'=>0,'rateChanges'=>0];
}

function payroll_tax_agent_overview(array $user,array $company): array
{
    require_company_permission($company,'payroll.view');$findings=native_agent_findings_list($user,$company,['agent'=>'payroll_tax']);$stmt=db()->prepare('SELECT id,action_id,status,policy_revision,source_revision_hash,result_json,error_code,started_at,completed_at FROM native_agent_autonomy_runs WHERE company_id=? ORDER BY started_at DESC,id DESC LIMIT 50');$stmt->execute([(string)$company['id']]);$runs=[];foreach($stmt->fetchAll() as $row)$runs[]=['id'=>(string)$row['id'],'actionId'=>(string)$row['action_id'],'status'=>(string)$row['status'],'policyRevision'=>(int)$row['policy_revision'],'sourceRevisionHash'=>(string)$row['source_revision_hash'],'result'=>json_decode((string)$row['result_json'],true)?:[],'errorCode'=>$row['error_code'],'startedAt'=>(string)$row['started_at'],'completedAt'=>$row['completed_at']];return ['agentEnabled'=>(bool)native_agent_policy_get($company,$user)['payrollTaxAgentEnabled'],'findings'=>$findings['findings'],'viewCounts'=>$findings['viewCounts'],'autonomyPolicies'=>payroll_tax_autonomy_policies($company),'recentAutonomyRuns'=>$runs,'boundaries'=>['accountingWrites'=>0,'financialCommits'=>0,'filingsSubmitted'=>0,'paymentsInitiated'=>0,'messagesSent'=>0,'rateChanges'=>0,'providerAttempts'=>0,'autonomyAllowlist'=>PAYROLL_TAX_AUTONOMY_ACTIONS]];
}

function handle_payroll_tax_agent(string $action): never
{
    $user=require_user();$company=require_company($user);$action=trim($action,'/');
    if($action===''||$action==='overview'){require_method('GET');json_response(payroll_tax_agent_overview($user,$company));}
    if($action==='policy'){if(request_method()==='GET')json_response(['policies'=>payroll_tax_autonomy_policies($company)]);require_method('PUT');require_csrf();json_response(['ok'=>true,'policies'=>payroll_tax_autonomy_policy_save($user,$company,request_json())]);}
    if($action==='execute'){require_method('POST');require_csrf();json_response(payroll_tax_autonomy_execute($user,$company,request_json()));}
    if($action==='runs'){require_method('GET');json_response(['recentAutonomyRuns'=>payroll_tax_agent_overview($user,$company)['recentAutonomyRuns']]);}
    fail('Payroll/Tax Agent route not found.',404,'route_not_found');
}
