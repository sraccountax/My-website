<?php
declare(strict_types=1);

const TEGH_OUTPUT_CONTRACT_V5800 = '2.0';
const TEGH_OUTPUT_DEFINITION_V5800 = '5930.1';
const TEGH_OUTPUT_MAX_ROWS_V5800 = 25000;

/** @param array<string,mixed> $model @return array<string,mixed> */
function tegh_output_seal_v5800(array $user,array $company,array $model,string $definitionKey,array $parameters): array
{
    if(!empty($GLOBALS['tegh_report_building_5980']))return $model;

    $generatedAt=gmdate('Y-m-d\TH:i:s\Z');
    $rowCount=count((array)($model['rows']??[]));
    $normalized=['definitionKey'=>$definitionKey,'definitionVersion'=>TEGH_OUTPUT_DEFINITION_V5800,'parameters'=>$parameters,
        'columns'=>$model['columns']??[],'rows'=>$model['rows']??[],'totals'=>$model['totals']??[]];
    $sourceRevisionHash=hash('sha256',json_encode($normalized,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $totalsDigest=hash('sha256',json_encode($model['totals']??[],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $companyScope=substr(hash('sha256',(string)$company['id']),0,16);
    $material=json_encode(['companyScope'=>$companyScope,'definitionKey'=>$definitionKey,'definitionVersion'=>TEGH_OUTPUT_DEFINITION_V5800,
        'parameters'=>$parameters,'basis'=>(string)$company['accounting_basis'],'currency'=>(string)($model['currency']??$company['currency']),
        'generatedAt'=>$generatedAt,'actorId'=>(string)$user['id'],'sourceRevisionHash'=>$sourceRevisionHash,'rowCount'=>$rowCount,'totalsDigest'=>$totalsDigest],
        JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $digest=function_exists('secret_hash')?secret_hash($material):hash('sha256',$material);
    $model['contractVersion']=TEGH_OUTPUT_CONTRACT_V5800;
    $model['definitionVersion']=TEGH_OUTPUT_DEFINITION_V5800;
    $model['parameters']=$parameters;
    $model['rowCount']=$rowCount;
    $model['verifiedOutput']=['reference'=>'TVO-'.strtoupper(substr($digest,0,20)),'generatedAt'=>$generatedAt,
        'companyTimezone'=>'America/Toronto','sourceRevisionHash'=>$sourceRevisionHash,'totalsDigest'=>$totalsDigest,'rowCount'=>$rowCount,
        'statement'=>'Reproducible Tegh output reference; this is not an audit opinion or legal certification.'];
    $model['accountingWrites']=0;$model['providerAttempts']=0;
    audit_event($user,(string)$company['id'],'professional_output.generated','professional_output',(string)$model['verifiedOutput']['reference'],[
        'definitionKey'=>$definitionKey,'definitionVersion'=>TEGH_OUTPUT_DEFINITION_V5800,'parameters'=>$parameters,
        'sourceRevisionHash'=>$sourceRevisionHash,'totalsDigest'=>$totalsDigest,'rowCount'=>$rowCount,'accountingWrites'=>0,'providerAttempts'=>0]);
    return $model;
}

/** @return array<string,mixed> */
function tegh_output_company_v5800(array $company): array
{
    return ['name'=>(string)$company['name'],'legalName'=>(string)$company['legal_name'],
        'currency'=>(string)$company['currency'],'accountingBasis'=>(string)$company['accounting_basis']];
}

/** @return array<string,mixed> */
function tegh_output_financial_statement_v5800(array $user,array $company,string $kind,string $start,string $end): array
{
    $kind=strtolower(trim($kind));
    if($kind==='trial-balance')return tegh_output_trial_balance_v5800($user,$company,$start,$end);
    $data=portal_financial_report_data($company,$kind,$start,$end);
    $asOf=$kind==='balance-sheet';
    $titles=['profit-loss'=>'Profit and Loss','balance-sheet'=>'Balance Sheet','trial-balance'=>'Trial Balance'];
    $rows=array_map(static fn(array $row):array=>['accountCode'=>(string)$row['code'],'accountName'=>(string)$row['name'],
        'section'=>(string)($row['presentationSection']??$row['type']),'amountCents'=>(int)$row['amountCents']],(array)$data['rows']);
    $model=['kind'=>'report','definitionKey'=>str_replace('-','_',$kind),'title'=>$titles[$kind]??'Financial Report',
        'orientation'=>'portrait','paper'=>'letter','currency'=>(string)$data['currency'],'accountingBasis'=>(string)$company['accounting_basis'],
        'company'=>tegh_output_company_v5800($company),'presentationStatus'=>'Management report — unaudited','reportingFramework'=>(string)($company['reporting_framework']??'not_set'),'period'=>['mode'=>$asOf?'asOf':'range','start'=>$asOf?null:$start,'end'=>$asOf?null:$end,'asOf'=>$asOf?$end:null,'inclusive'=>true],
        'columns'=>[['key'=>'accountCode','label'=>'Account','type'=>'identifier','width'=>13],['key'=>'accountName','label'=>'Account name','type'=>'text','width'=>42],
            ['key'=>'section','label'=>'Section','type'=>'text','width'=>16],['key'=>'amountCents','label'=>'Amount','type'=>'money','width'=>17]],
        'rows'=>$rows,'totals'=>$data['totals'],'definitions'=>['calculationAuthority'=>'posted journal lines','dateSemantics'=>$asOf?'Balances through the As of date':'Inclusive posting-date range']];
    return tegh_output_seal_v5800($user,$company,$model,(string)$model['definitionKey'],['start'=>$asOf?null:$start,'end'=>$asOf?null:$end,'asOf'=>$asOf?$end:null,'inclusive'=>true]);
}

/** @return array<string,mixed> */
function tegh_output_trial_balance_v5800(array $user,array $company,string $start,string $end): array
{
    $start=safe_date($start,'Start date');$end=safe_date($end,'End date');if($start>$end)fail('Start date must not be after end date.',422,'output_period_invalid',false);
    $stmt=db()->prepare("SELECT a.code,a.name,a.account_type,
      COALESCE(SUM(CASE WHEN je.entry_date<? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) opening_signed,
      COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.debit_cents ELSE 0 END),0) period_debit,
      COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.credit_cents ELSE 0 END),0) period_credit
      FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id
      LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id AND je.status='posted' AND je.entry_date<=?
      WHERE a.company_id=? GROUP BY a.id,a.code,a.name,a.account_type ORDER BY a.code");
    $stmt->execute([$start,$start,$end,$start,$end,$end,(string)$company['id']]);$rows=[];$totals=['openingDebitCents'=>0,'openingCreditCents'=>0,'periodDebitCents'=>0,'periodCreditCents'=>0,'closingDebitCents'=>0,'closingCreditCents'=>0];
    foreach($stmt->fetchAll() as $row){$opening=(int)$row['opening_signed'];$pd=(int)$row['period_debit'];$pc=(int)$row['period_credit'];$closing=$opening+$pd-$pc;if($opening===0&&$pd===0&&$pc===0)continue;
        $item=['accountCode'=>(string)$row['code'],'accountName'=>(string)$row['name'],'section'=>(string)$row['account_type'],
            'openingDebitCents'=>max(0,$opening),'openingCreditCents'=>max(0,-$opening),'periodDebitCents'=>$pd,'periodCreditCents'=>$pc,
            'closingDebitCents'=>max(0,$closing),'closingCreditCents'=>max(0,-$closing)];$rows[]=$item;
        foreach($totals as $key=>$_)$totals[$key]+=(int)$item[$key];
    }
    $totals['openingDifferenceCents']=$totals['openingDebitCents']-$totals['openingCreditCents'];$totals['periodDifferenceCents']=$totals['periodDebitCents']-$totals['periodCreditCents'];$totals['closingDifferenceCents']=$totals['closingDebitCents']-$totals['closingCreditCents'];
    $columns=[['key'=>'accountCode','label'=>'Account','type'=>'identifier','width'=>11],['key'=>'accountName','label'=>'Account name','type'=>'text','width'=>25]];
    foreach([['openingDebitCents','Opening debit'],['openingCreditCents','Opening credit'],['periodDebitCents','Period debit'],['periodCreditCents','Period credit'],['closingDebitCents','Closing debit'],['closingCreditCents','Closing credit']] as [$key,$label])$columns[]=['key'=>$key,'label'=>$label,'type'=>'money','width'=>14];
    $model=['kind'=>'report','definitionKey'=>'trial_balance','title'=>'Trial Balance','orientation'=>'landscape','paper'=>'letter','currency'=>(string)$company['currency'],
        'accountingBasis'=>(string)$company['accounting_basis'],'company'=>tegh_output_company_v5800($company),'presentationStatus'=>'Management report — unaudited','reportingFramework'=>(string)($company['reporting_framework']??'not_set'),'period'=>['mode'=>'range','start'=>$start,'end'=>$end,'asOf'=>null,'inclusive'=>true],
        'columns'=>$columns,'rows'=>$rows,'totals'=>$totals,'definitions'=>['equation'=>'Opening plus period movement equals closing','calculationAuthority'=>'posted journal lines']];
    return tegh_output_seal_v5800($user,$company,$model,'trial_balance',['start'=>$start,'end'=>$end,'inclusive'=>true]);
}

/** @return array<string,mixed> */
function tegh_output_cash_flow_v5800(array $user,array $company,string $start,string $end): array
{
    $start=safe_date($start,'Start date');$end=safe_date($end,'End date');if($start>$end)fail('Start date must not be after end date.',422,'output_period_invalid',false);
    $data=advanced_cash_flow_data((string)$company['id'],$start,$end);$rows=array_map(static fn(array $row):array=>['date'=>(string)$row['date'],'description'=>(string)$row['memo'],
        'section'=>(string)$row['activity'],'amountCents'=>(int)$row['amountCents']],(array)$data['activities']);
    $model=['kind'=>'report','definitionKey'=>'cash_flow_statement','title'=>'Cash Flow Statement','orientation'=>'portrait','paper'=>'letter','currency'=>(string)$company['currency'],
        'accountingBasis'=>(string)$company['accounting_basis'],'company'=>tegh_output_company_v5800($company),'presentationStatus'=>'Management report — unaudited','reportingFramework'=>(string)($company['reporting_framework']??'not_set'),'period'=>['mode'=>'range','start'=>$start,'end'=>$end,'asOf'=>null,'inclusive'=>true],
        'columns'=>[['key'=>'date','label'=>'Date','type'=>'date','width'=>12],['key'=>'description','label'=>'Description','type'=>'text','width'=>38],['key'=>'section','label'=>'Activity','type'=>'text','width'=>18],['key'=>'amountCents','label'=>'Cash movement','type'=>'money','width'=>18]],
        'rows'=>$rows,'totals'=>['openingCashCents'=>(int)$data['openingCashCents'],'operatingCents'=>(int)$data['sections']['operating'],'investingCents'=>(int)$data['sections']['investing'],'financingCents'=>(int)$data['sections']['financing'],'netChangeCents'=>(int)$data['netChangeCents'],'exchangeEffectsCents'=>(int)$data['exchangeEffectsCents'],'unclassifiedCents'=>(int)$data['unclassifiedCents'],'closingCashCents'=>(int)$data['closingCashCents'],'reconciliationDifferenceCents'=>(int)$data['reconciliationDifferenceCents']],
        'groupBy'=>'section','groupOrder'=>['operating','investing','financing','exchange_effects','unclassified'],'groupLabels'=>['operating'=>'Operating activities','investing'=>'Investing activities','financing'=>'Financing activities','exchange_effects'=>'Exchange effects on cash','unclassified'=>'Needs classification'],'groupTotals'=>array_merge($data['sections'],['exchange_effects'=>$data['exchangeEffectsCents'],'unclassified'=>$data['unclassifiedCents']]),
        'presentationStatus'=>$data['presentationStatus'],'reportingFramework'=>$data['reportingFramework'],'exceptions'=>$data['exceptions'],'cashAccounts'=>$data['cashAccounts'],
        'definitions'=>['statementType'=>'Historical cash flow from posted cash-account journals','notForecast'=>true]];
    return tegh_output_seal_v5800($user,$company,$model,'cash_flow_statement',['start'=>$start,'end'=>$end,'inclusive'=>true]);
}

/** @return array<string,mixed> */
function tegh_output_ledger_v5800(array $user,array $company,string $start,string $end,?string $accountId): array
{
    $start=safe_date($start,'Start date');$end=safe_date($end,'End date');if($start>$end)fail('Start date must not be after end date.',422,'output_period_invalid',false);
    $sql="SELECT je.entry_date,COALESCE(v.voucher_number,'Journal entry') document_number,je.source_type,je.memo,a.code account_code,a.name account_name,jl.debit_cents,jl.credit_cents
      FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id
      LEFT JOIN vouchers v ON v.company_id=je.company_id AND v.journal_entry_id=je.id WHERE je.company_id=? AND je.status='posted' AND je.entry_date BETWEEN ? AND ?";
    $params=[(string)$company['id'],$start,$end];if($accountId!==null&&$accountId!==''){$account=company_account((string)$company['id'],$accountId);if(!$account)fail('That ledger output is unavailable.',404,'ledger_output_unavailable',false);$sql.=' AND jl.account_id=?';$params[]=$accountId;}
    $sql.=' ORDER BY je.entry_date,je.created_at,je.id,jl.id LIMIT '.TEGH_OUTPUT_MAX_ROWS_V5800;$stmt=db()->prepare($sql);$stmt->execute($params);$rows=[];$debits=0;$credits=0;
    foreach($stmt->fetchAll() as $row){$debit=(int)$row['debit_cents'];$credit=(int)$row['credit_cents'];$debits+=$debit;$credits+=$credit;$rows[]=['date'=>(string)$row['entry_date'],'documentNumber'=>(string)$row['document_number'],
        'sourceType'=>(string)$row['source_type'],'description'=>(string)$row['memo'],'accountCode'=>(string)$row['account_code'],'accountName'=>(string)$row['account_name'],'debitCents'=>$debit,'creditCents'=>$credit];}
    $model=['kind'=>'report','definitionKey'=>'general_ledger','title'=>$accountId?'Account Ledger':'General Ledger','orientation'=>'landscape','paper'=>'letter','currency'=>(string)$company['currency'],
        'accountingBasis'=>(string)$company['accounting_basis'],'company'=>tegh_output_company_v5800($company),'presentationStatus'=>'Management report — unaudited','reportingFramework'=>(string)($company['reporting_framework']??'not_set'),'period'=>['mode'=>'range','start'=>$start,'end'=>$end,'asOf'=>null,'inclusive'=>true],
        'columns'=>[['key'=>'date','label'=>'Date','type'=>'date','width'=>11],['key'=>'documentNumber','label'=>'Document','type'=>'identifier','width'=>15],['key'=>'sourceType','label'=>'Source','type'=>'text','width'=>15],['key'=>'description','label'=>'Description','type'=>'text','width'=>28],['key'=>'accountCode','label'=>'Account','type'=>'identifier','width'=>11],['key'=>'accountName','label'=>'Account name','type'=>'text','width'=>22],['key'=>'debitCents','label'=>'Debit','type'=>'money','width'=>14],['key'=>'creditCents','label'=>'Credit','type'=>'money','width'=>14]],
        'rows'=>$rows,'totals'=>['debitCents'=>$debits,'creditCents'=>$credits,'differenceCents'=>$debits-$credits]];
    return tegh_output_seal_v5800($user,$company,$model,'general_ledger',['start'=>$start,'end'=>$end,'accountId'=>$accountId,'inclusive'=>true]);
}

/** @return array<string,mixed> */
function tegh_output_budget_v5800(array $user,array $company,?string $budgetId): array
{
    $companyId=(string)$company['id'];if($budgetId){$stmt=db()->prepare('SELECT * FROM budgets WHERE id=? AND company_id=? LIMIT 1');$stmt->execute([$budgetId,$companyId]);}
    else{$stmt=db()->prepare("SELECT * FROM budgets WHERE company_id=? ORDER BY (status='active') DESC,period_end DESC,created_at DESC LIMIT 1");$stmt->execute([$companyId]);}
    $budget=$stmt->fetch();if(!$budget)fail('No permitted budget is available for this output.',404,'budget_output_unavailable',false);
    $stmt=db()->prepare('SELECT bl.*,a.code,a.name account_name,aa.code analytic_code,aa.name analytic_name FROM budget_lines bl JOIN accounts a ON a.id=bl.account_id AND a.company_id=? LEFT JOIN analytic_accounts aa ON aa.id=bl.analytic_account_id AND aa.company_id=? WHERE bl.budget_id=? ORDER BY a.code,aa.code,bl.id');
    $stmt->execute([$companyId,$companyId,(string)$budget['id']]);$rows=[];$planned=0;$actual=0;
    foreach($stmt->fetchAll() as $line){$lineActual=advanced_budget_actual($companyId,$budget,$line);$p=(int)$line['planned_cents'];$planned+=$p;$actual+=$lineActual;$rows[]=['accountCode'=>(string)$line['code'],'accountName'=>(string)$line['account_name'],
        'analytic'=>(string)($line['analytic_name']??'—'),'plannedCents'=>$p,'actualCents'=>$lineActual,'varianceCents'=>$p-$lineActual];}
    $model=['kind'=>'report','definitionKey'=>'budget_vs_actual','title'=>'Budget vs Actual','subtitle'=>(string)$budget['name'],'orientation'=>'landscape','paper'=>'letter','currency'=>(string)$company['currency'],
        'accountingBasis'=>(string)$company['accounting_basis'],'company'=>tegh_output_company_v5800($company),'presentationStatus'=>'Management report — unaudited','reportingFramework'=>(string)($company['reporting_framework']??'not_set'),'period'=>['mode'=>'range','start'=>(string)$budget['period_start'],'end'=>(string)$budget['period_end'],'asOf'=>null,'inclusive'=>true],
        'columns'=>[['key'=>'accountCode','label'=>'Account','type'=>'identifier','width'=>12],['key'=>'accountName','label'=>'Account name','type'=>'text','width'=>28],['key'=>'analytic','label'=>'Dimension','type'=>'text','width'=>20],['key'=>'plannedCents','label'=>'Planned','type'=>'money','width'=>15],['key'=>'actualCents','label'=>'Actual','type'=>'money','width'=>15],['key'=>'varianceCents','label'=>'Variance','type'=>'money','width'=>15]],
        'rows'=>$rows,'totals'=>['plannedCents'=>$planned,'actualCents'=>$actual,'varianceCents'=>$planned-$actual],'status'=>(string)$budget['status']];
    return tegh_output_seal_v5800($user,$company,$model,'budget_vs_actual',['budgetId'=>(string)$budget['id'],'start'=>(string)$budget['period_start'],'end'=>(string)$budget['period_end'],'inclusive'=>true]);
}

/** @return array<string,mixed> */
function tegh_output_fixed_assets_v5800(array $user,array $company): array
{
    $stmt=db()->prepare("SELECT fa.id,fa.name,fa.in_service_date,fa.original_cost_cents,fa.salvage_value_cents,fa.useful_life_months,fa.status,a.code asset_code,
      COALESCE(SUM(CASE WHEN dl.status='posted' THEN dl.depreciation_cents ELSE 0 END),0) accumulated_cents,
      MIN(CASE WHEN dl.status='planned' THEN dl.period_end END) next_period
      FROM fixed_assets fa JOIN accounts a ON a.id=fa.asset_account_id AND a.company_id=fa.company_id LEFT JOIN asset_depreciation_lines dl ON dl.asset_id=fa.id
      WHERE fa.company_id=? GROUP BY fa.id,fa.name,fa.in_service_date,fa.original_cost_cents,fa.salvage_value_cents,fa.useful_life_months,fa.status,a.code ORDER BY fa.in_service_date,fa.name");
    $stmt->execute([(string)$company['id']]);$rows=[];$cost=0;$accum=0;$book=0;
    foreach($stmt->fetchAll() as $row){$c=(int)$row['original_cost_cents'];$a=(int)$row['accumulated_cents'];$b=$c-$a;$cost+=$c;$accum+=$a;$book+=$b;$rows[]=['assetName'=>(string)$row['name'],'assetAccount'=>(string)$row['asset_code'],'inServiceDate'=>(string)$row['in_service_date'],
        'status'=>(string)$row['status'],'usefulLifeMonths'=>(int)$row['useful_life_months'],'originalCostCents'=>$c,'salvageValueCents'=>(int)$row['salvage_value_cents'],'accumulatedDepreciationCents'=>$a,'bookValueCents'=>$b,'nextPeriod'=>$row['next_period']!==null?(string)$row['next_period']:null];}
    $model=['kind'=>'report','definitionKey'=>'fixed_asset_schedule','title'=>'Fixed Asset & Depreciation Schedule','orientation'=>'landscape','paper'=>'letter','currency'=>(string)$company['currency'],
        'accountingBasis'=>(string)$company['accounting_basis'],'company'=>tegh_output_company_v5800($company),'presentationStatus'=>'Management report — unaudited','reportingFramework'=>(string)($company['reporting_framework']??'not_set'),'period'=>['mode'=>'asOf','start'=>null,'end'=>null,'asOf'=>canadian_today(),'inclusive'=>true],
        'columns'=>[['key'=>'assetName','label'=>'Asset','type'=>'text','width'=>24],['key'=>'assetAccount','label'=>'Account','type'=>'identifier','width'=>11],['key'=>'inServiceDate','label'=>'In service','type'=>'date','width'=>12],['key'=>'status','label'=>'Status','type'=>'text','width'=>12],['key'=>'usefulLifeMonths','label'=>'Life (months)','type'=>'integer','width'=>11],['key'=>'originalCostCents','label'=>'Cost','type'=>'money','width'=>15],['key'=>'salvageValueCents','label'=>'Residual','type'=>'money','width'=>14],['key'=>'accumulatedDepreciationCents','label'=>'Accumulated depreciation','type'=>'money','width'=>18],['key'=>'bookValueCents','label'=>'Book value','type'=>'money','width'=>15],['key'=>'nextPeriod','label'=>'Next period','type'=>'date','width'=>12]],
        'rows'=>$rows,'totals'=>['originalCostCents'=>$cost,'accumulatedDepreciationCents'=>$accum,'bookValueCents'=>$book]];
    return tegh_output_seal_v5800($user,$company,$model,'fixed_asset_schedule',['asOf'=>canadian_today()]);
}

/** @return array<string,mixed> */
function tegh_output_forecast_v5800(array $user,array $company,string $start,string $end,string $bucketUnit): array
{
    $forecast=financial_cash_forecast($company,null,['start'=>$start,'end'=>$end,'bucketUnit'=>$bucketUnit]);$rows=array_map(static fn(array $row):array=>['period'=>(string)$row['label'],'start'=>(string)$row['periodStart'],'end'=>(string)$row['periodEnd'],
        'openingCents'=>(int)$row['openingCents'],'inflowsCents'=>(int)$row['inflowsCents'],'outflowsCents'=>(int)$row['outflowsCents'],'netChangeCents'=>(int)$row['netChangeCents'],'closingCents'=>(int)$row['closingCents']],(array)$forecast['points']);
    $model=['kind'=>'report','definitionKey'=>'cash_forecast','title'=>'Cash Forecast','subtitle'=>'Deterministic forward-looking planning output','orientation'=>'landscape','paper'=>'letter','currency'=>(string)$company['currency'],'accountingBasis'=>(string)$company['accounting_basis'],
        'company'=>tegh_output_company_v5800($company),'presentationStatus'=>'Management report — unaudited','reportingFramework'=>(string)($company['reporting_framework']??'not_set'),'period'=>['mode'=>'range','start'=>$forecast['horizonStart'],'end'=>$forecast['horizonEnd'],'asOf'=>null,'inclusive'=>true],
        'columns'=>[['key'=>'period','label'=>'Period','type'=>'text','width'=>18],['key'=>'start','label'=>'Start','type'=>'date','width'=>12],['key'=>'end','label'=>'End','type'=>'date','width'=>12],['key'=>'openingCents','label'=>'Opening cash','type'=>'money','width'=>15],['key'=>'inflowsCents','label'=>'Inflows','type'=>'money','width'=>15],['key'=>'outflowsCents','label'=>'Outflows','type'=>'money','width'=>15],['key'=>'netChangeCents','label'=>'Net change','type'=>'money','width'=>15],['key'=>'closingCents','label'=>'Closing cash','type'=>'money','width'=>15]],
        'rows'=>$rows,'totals'=>['openingCashCents'=>(int)$forecast['openingCashCents'],'totalInflowsCents'=>(int)$forecast['totalInflowsCents'],'totalOutflowsCents'=>(int)$forecast['totalOutflowsCents'],'netChangeCents'=>(int)$forecast['netChangeCents'],'closingCashCents'=>(int)$forecast['closingCashCents'],'minimumProjectedCashCents'=>(int)$forecast['minimumProjectedCashCents']],
        'definitions'=>['planningNotice'=>$forecast['planningNotice'],'sourceCounts'=>$forecast['sourceCounts'],'bucketUnit'=>$forecast['bucketUnit']]];
    return tegh_output_seal_v5800($user,$company,$model,'cash_forecast',['start'=>$forecast['horizonStart'],'end'=>$forecast['horizonEnd'],'bucketUnit'=>$forecast['bucketUnit'],'inclusive'=>true]);
}

/** Legacy URL compatibility: no independent source, sealing, permissions or totals. */
function handle_professional_output_v5800(string $action): never
{
    handle_report_output_5980(trim($action,'/'));
}
