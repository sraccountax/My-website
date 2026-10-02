<?php
declare(strict_types=1);

/** A read-only, versioned report registry. No DOM rows or client totals are accepted. */
const TEGH_REPORT_CONTRACT_5980='3.0';
const TEGH_REPORT_DEFINITION_5980='5990.1';
const TEGH_REPORT_LIMIT_5980=25000;
function tegh_report_definitions_5980(): array
{
    $items=[
        'profit-loss'=>['Profit and Loss','reports.view','financial','range','portrait'],
        'balance-sheet'=>['Balance Sheet','reports.view','financial','asOf','portrait'],
        'trial-balance'=>['Trial Balance','reports.view','financial','range','landscape'],
        'cash-flow'=>['Cash Flow Statement','reports.view','cash_flow','range','landscape'],
        'cash-forecast'=>['Cash Forecast','reports.view','forecast','range','landscape'],
        'budget-vs-actual'=>['Budget Versus Actual','reports.view','budget','budget','landscape'],
        'fixed-assets'=>['Fixed Asset Schedule','reports.view','assets','current','landscape'],
        'inventory'=>['Product Activity','reports.view','inventory','range','landscape'],
        'day-book'=>['Day Book','journals.view','day_book','range','landscape'],
        'general-ledger'=>['General Ledger Detail','reports.view','ledger','range','landscape'],
        'gl-account-ledger'=>['General Ledger Account Report','reports.view','ledger','range','landscape'],
        'audit-history'=>['Audit History','audit.view','audit','range','landscape'],
        'tax-summary'=>['Tax Control Account Summary','reports.view','tax','range','landscape'],
        'currency-exposure'=>['Currency Exposure','reports.view','currency','current','landscape'],
        'reconciliation-summary'=>['Bank Reconciliation Report','banking.view','reconciliation','range','landscape'],
        'ar-ageing'=>['Accounts Receivable Ageing','invoices.view','ageing','asOf','landscape'],
        'ap-ageing'=>['Accounts Payable Ageing','bills.view','ageing','asOf','landscape'],
        'customer-balances'=>['Customer Balances','invoices.view','party_balances','asOf','landscape'],
        'vendor-balances'=>['Vendor Balances','bills.view','party_balances','asOf','landscape'],
        'ar-trial-balance'=>['Accounts Receivable Trial Balance','invoices.view','subledger_trial','range','landscape'],
        'ap-trial-balance'=>['Accounts Payable Trial Balance','bills.view','subledger_trial','range','landscape'],
        'customer-ledger'=>['Customer Ledger','invoices.view','party_ledger','range','landscape'],
        'vendor-ledger'=>['Vendor Ledger','bills.view','party_ledger','range','landscape'],
        'customer-directory'=>['Customer Directory','invoices.view','directory','current','landscape'],
        'vendor-directory'=>['Vendor Directory','bills.view','directory','current','landscape'],
        'invoice-register'=>['Customer Invoice & Note Register','invoices.view','documents','range','landscape'],
        'bill-register'=>['Vendor Invoice & Note Register','bills.view','documents','range','landscape'],
        'expense-register'=>['Expense Register','bills.view','expenses','range','landscape'],
        'bank-transactions'=>['Bank Transaction Report','banking.view','bank','range','landscape'],
        'bank-general-ledger'=>['Bank General Ledger Report','banking.view','bank_ledger','range','landscape'],
        'payroll-runs'=>['Payroll / Pay Run Register','payroll.view','payroll','range','landscape'],
        'payroll-detail'=>['Pay Run Detail','payroll.view','payroll_detail','range','landscape'],
        'payroll-remittances'=>['Payroll Remittances','payroll.view','remittances','range','landscape'],
        'tax-mapping'=>['Tax Mapping Working Paper','reports.view','tax_mapping','range','landscape'],
        'customer-invoice'=>['Customer Invoice','invoices.view','invoice_document','document','portrait'],
        'vendor-bill'=>['Vendor Bill','bills.view','bill_document','document','portrait'],
    ];
    $definitions=[];foreach($items as $key=>[$title,$permission,$loader,$period,$orientation]){
        $definitions[$key]=['key'=>$key,'version'=>$key==='profit-loss'?'5990.r20':(in_array($key,['invoice-register','bill-register'],true)?'5990.r23':(in_array($key,['customer-balances','vendor-balances'],true)?'5990.r116':TEGH_REPORT_DEFINITION_5980)),'title'=>$title,'permissions'=>array_values(array_unique(['reports.view',$permission])),
            'feature'=>str_starts_with($key,'payroll')?'module.payroll':'core.accounting','loader'=>$loader,'periodMode'=>$period,'orientation'=>$orientation,
            'parameterSchema'=>['start'=>'ISO date','end'=>'ISO date','asOf'=>'ISO date','q'=>'text; maximum 200 characters','status'=>'definition-specific enumeration','accountId'=>'authorized company account','partyId'=>'authorized company party','bankAccountId'=>'authorized company bank','currency'=>'ISO 4217 currency code','runId'=>'authorized company pay run','budgetId'=>'authorized company budget','documentId'=>'authorized company document']+($key==='reconciliation-summary'?['page'=>'positive integer','pageSize'=>'fixed at 50']:[])+($key==='profit-loss'?['columnsMode'=>'total | monthly','comparison'=>'none | prior_year | previous_period | custom','comparisonStart'=>'ISO date for custom comparison','comparisonEnd'=>'ISO date for custom comparison']:[]),
            'currencySemantics'=>'Amounts labelled as money are integer cents in the disclosed presentation currency; foreign amounts are separately qualified.',
            'basisSemantics'=>'Posted-ledger reports use the company accounting basis as recorded by the established posting services. No basis conversion is invented.',
            'maxRows'=>TEGH_REPORT_LIMIT_5980,'completenessPolicy'=>'Fail explicitly before sealing when the complete result exceeds the bound.',
            'disclosure'=>'Management report — unaudited'];
        if(in_array($key,['customer-invoice','vendor-bill'],true))$definitions[$key]['permissions']=[$permission];
    }return $definitions;
}
function tegh_report_columns_5980(array $spec): array
{
    $columns=[];foreach($spec as $item){[$key,$label]=$item;$columns[]=['key'=>$key,'label'=>$label,'type'=>$item[2]??'text','width'=>$item[3]??18];}return $columns;
}
function tegh_report_query_5980(string $sql,array $params=[]): array
{
    // Callers supply only fixed application SQL. The extra row detects incompleteness.
    $q=db()->prepare($sql.' LIMIT '.(TEGH_REPORT_LIMIT_5980+1));$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);tegh_report_check_rows_5980($rows);return $rows;
}
function tegh_report_check_rows_5980(array $rows): void
{
    if(count($rows)>TEGH_REPORT_LIMIT_5980)fail('This complete report exceeds 25,000 rows. Narrow its authorized filters; no truncated report was generated.',413,'report_incomplete_over_limit',false);
}
function tegh_report_parameters_5980(array $definition,array $input): array
{
    $today=canadian_today();$p=['start'=>safe_date($input['start']??$input['from']??substr($today,0,4).'-01-01','Start date'),
        'end'=>safe_date($input['end']??$input['to']??$today,'End date'),'asOf'=>safe_date($input['asOf']??$input['end']??$input['to']??$today,'As-of date')];
    if($p['start']>$p['end'])fail('Start date must not be after end date.',422,'report_period_invalid',false);
    $p['status']=strtolower(trim((string)($input['status']??($definition['key']==='day-book'?'posted':'all'))));
    foreach(['q','module','sourceType','sourceId','accountId','bankAccountId','budgetId','runId','category','type'] as $key)$p[$key]=trim((string)($input[$key]??''));
    $p['currency']=strtoupper(trim((string)($input['currency']??'')));if($p['currency']!==''&&!preg_match('/^[A-Z]{3}$/',$p['currency']))fail('Choose a valid three-letter currency.',422,'report_currency_invalid',false);
    if(mb_strlen($p['q'])>200)fail('Search is limited to 200 characters.',422,'report_search_invalid',false);
    foreach(['module','sourceType','sourceId','accountId','bankAccountId','budgetId','runId','category','type'] as $key)if(strlen($p[$key])>100)fail('A report filter is too long.',422,'report_parameter_invalid',false);
    $p['partyId']=trim((string)($input['partyId']??$input['customerId']??$input['vendorId']??''));
    $p['documentId']=trim((string)($input['documentId']??$input['invoiceId']??$input['billId']??''));
    $p['bucketUnit']=strtolower(trim((string)($input['bucketUnit']??'month')));
    if(!in_array($p['bucketUnit'],['week','month','year'],true))fail('Unsupported forecast bucket.',422,'report_parameter_invalid',false);
    if(strlen($p['partyId'])>64||strlen($p['documentId'])>64)fail('Invalid report record.',422,'report_parameter_invalid',false);
    if($definition['key']==='reconciliation-summary'){$p['page']=isset($input['page'])&&ctype_digit((string)$input['page'])?max(1,(int)$input['page']):1;$p['pageSize']=isset($input['pageSize'])&&ctype_digit((string)$input['pageSize'])?(int)$input['pageSize']:50;if($p['pageSize']!==50)fail('Bank reconciliation history uses a fixed 50-row page.',422,'report_page_size_fixed',false);}
    elseif(isset($input['page'])||isset($input['pageSize']))fail('Paging parameters do not apply to this report.',422,'report_filter_unsupported',false);
    if($definition['key']==='gl-account-ledger'&&$p['accountId']==='')fail('Choose a General Ledger account.',422,'report_account_required',false);
    if($definition['key']==='bank-general-ledger'&&$p['bankAccountId']==='')fail('Choose a bank account.',422,'report_bank_account_required',false);
    if(in_array($definition['loader'],['party_ledger'],true)&&$p['partyId']==='')fail('Choose a customer or vendor.',422,'report_party_required',false);
    if($definition['loader']==='payroll_detail'&&$p['runId']==='')fail('Choose a pay run.',422,'report_run_required',false);
    if($definition['periodMode']==='document'&&$p['documentId']==='')fail('Choose a document.',422,'report_document_required',false);
    if($definition['periodMode']==='current'&&$p['asOf']!==$today)fail('This definition is a current snapshot, not a reconstructed historical balance. Use today as the as-of date.',422,'report_historical_snapshot_unsupported',false);
    if($definition['loader']==='ageing'&&(isset($input['c1'])||isset($input['c2'])||isset($input['c3']))){foreach(['c1','c2','c3'] as $k){if(!isset($input[$k])||!ctype_digit((string)$input[$k]))fail('Provide three complete ageing cutoffs.',422,'report_parameter_invalid',false);$p[$k]=(int)$input[$k];}if(!($p['c1']>=1&&$p['c1']<$p['c2']&&$p['c2']<$p['c3']&&$p['c3']<=3650))fail('Ageing cutoffs must be increasing positive day counts.',422,'report_parameter_invalid',false);}
    $applicable=match($definition['loader']){
        'financial','cash_flow','forecast','budget','assets','tax','tax_mapping'=>[],
        'day_book'=>['q','module','sourceType','sourceId'],
        'ledger'=>['accountId'],
        'bank_ledger'=>['bankAccountId','currency'],
        'directory'=>['q'],
        'documents','ageing','party_balances','subledger_trial'=>['q','partyId'],
        'expenses','remittances'=>['q'],
        'bank'=>['q','bankAccountId','currency'],
        'reconciliation'=>['q','bankAccountId'],
        'payroll','payroll_detail'=>['q','runId'],
        'audit'=>['q','category'],
        'currency'=>[],
        'party_ledger'=>['partyId'],
        'inventory'=>['q'],
        'invoice_document','bill_document'=>['documentId'],default=>[]};
    foreach(['q','module','sourceType','sourceId','accountId','bankAccountId','runId','partyId','documentId','category','type'] as $filter){if($p[$filter]!==''&&!in_array($filter,$applicable,true))fail('The '.$filter.' filter is not supported by this definition; it has not been silently ignored.',422,'report_filter_unsupported',false);}
    if($p['currency']!==''&&!in_array('currency',$applicable,true))fail('The currency filter is not supported by this definition; it has not been silently ignored.',422,'report_filter_unsupported',false);
    if($p['budgetId']!==''&&$definition['loader']!=='budget')fail('Budget selection does not apply to this report.',422,'report_filter_unsupported',false);
    if(in_array($definition['loader'],['financial','cash_flow','forecast','budget','assets','tax','tax_mapping','ledger','audit','currency','party_ledger','party_balances','subledger_trial','inventory','ageing','invoice_document','bill_document'],true)&&!in_array($p['status'],['all','posted',''],true))fail('This definition does not support that status filter.',422,'report_status_invalid',false);
    if ($definition['key'] === 'profit-loss') $p = tegh_profit_parameters_r20($p, $input);
    elseif (isset($input['comparison']) || isset($input['columnsMode']) || isset($input['comparisonStart']) || isset($input['comparisonEnd'])) fail('Comparison columns apply only to Profit and Loss.',422,'report_filter_unsupported',false);
    return $p;
}
function tegh_report_search_sql_5980(array $fields,string $query,array &$params): string
{
    if($query==='')return '';$needle='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';$parts=[];foreach($fields as $field){$parts[]="$field LIKE ? ESCAPE '!'";$params[]=$needle;}return ' AND ('.implode(' OR ',$parts).')';
}
function tegh_report_status_sql_5980(string $column,string $status,array $allowed,array &$params): string
{
    if($status===''||$status==='all')return '';if(!in_array($status,$allowed,true))fail('That status is not supported by this report.',422,'report_status_invalid',false);$params[]=$status;return ' AND '.$column.'=?';
}
function tegh_report_model_5980(array $d,array $company,array $p,array $columns,array $rows,array $totals=[]): array
{
    $asOf=in_array($d['periodMode'],['asOf','current'],true);return ['kind'=>'report','definitionKey'=>$d['key'],'title'=>$d['title'],'orientation'=>$d['orientation'],'paper'=>'letter',
        'company'=>tegh_output_company_v5800($company),'currency'=>(string)$company['currency'],'accountingBasis'=>(string)$company['accounting_basis'],'reportingFramework'=>(string)($company['reporting_framework']??'not_set'),
        'period'=>['mode'=>$asOf?'asOf':'range','start'=>$asOf?null:$p['start'],'end'=>$asOf?null:$p['end'],'asOf'=>$asOf?$p['asOf']:null,'inclusive'=>true],
        'columns'=>$columns,'rows'=>$rows,'totals'=>$totals,'exceptions'=>[],'controlTotals'=>[]];
}
/** JSON consumers use exact safe integers for cents, including nested controls. */
function tegh_report_validate_output_5980(array $model): void
{
    $safe=function(mixed $value,string $label):void{
        if(!is_int($value)||abs($value)>9007199254740991)fail('The '.$label.' value cannot be represented exactly in all report outputs. No rounded report was generated.',413,'report_numeric_precision_limit',false);
    };
    foreach($model['rows']??[] as $row){foreach($model['columns']??[] as $column){$key=$column['key'];$value=$row[$key]??null;if($value===null||$value==='')continue;
        if(in_array($column['type'],['money','integer'],true))$safe($value,$key);
        elseif($column['type']==='date')tegh_subledger_date_5980($value,$key);
        elseif(in_array($column['type'],['number','rate_bps','decimal'],true)&&(!is_numeric($value)||!is_finite((float)$value)))fail('A report contains an invalid numeric source.',409,'report_source_amount_invalid',false);
    }}
    $walk=function(mixed $values)use(&$walk,$safe):void{if(!is_array($values))return;foreach($values as $key=>$value){if(is_array($value))$walk($value);elseif($value!==null&&preg_match('/Cents$/',(string)$key))$safe($value,(string)$key);}};
    foreach(['totals','groupTotals','controlTotals','summaryRows'] as $key)$walk($model[$key]??[]);
}
function tegh_report_finalize_5980(array $user,array $company,array $definition,array $p,array $model): array
{
    tegh_report_check_rows_5980($model['rows']??[]);tegh_report_validate_output_5980($model);
    $model['definitionKey']=$definition['key'];$model['definitionVersion']=$definition['version'];$model['contractVersion']=TEGH_REPORT_CONTRACT_5980;
    $model['companyId']=(string)$company['id'];$model['company']=$model['company']??tegh_output_company_v5800($company);
    $model['accountingBasis']=(string)$company['accounting_basis'];$model['reportingFramework']=(string)($company['reporting_framework']??'not_set');
    $model['presentationStatus']='Management report — unaudited';$model['parameters']=$p;$model['filters']=$p;
    $model['postingScope']=$model['postingScope']??(in_array($definition['loader'],['financial','ledger','cash_flow','tax'],true)?'Posted journal entries only':$p['status']);
    $model['currencyQualifications']=$model['currencyQualifications']??'Ledger and base monetary columns use '.$company['currency'].'. Foreign-currency figures, where shown, are not added across currencies.';
    $model['frameworkDisclosure']='Configured framework: '.strtoupper(str_replace('_',' ',$model['reportingFramework'])).'. Accounting basis: '.$model['accountingBasis'].'. Presentation is not an audit, review engagement or guarantee of GAAP, ASPE or IFRS compliance.';
    $model['footer']='Confidential — prepared for management. Management report — unaudited. '.$model['frameworkDisclosure'];
    $model['rowCount']=count($model['rows']??[]);$model['completeness']=['state'=>'complete','complete'=>true,'rowCount'=>$model['rowCount'],'limit'=>TEGH_REPORT_LIMIT_5980,'truncated'=>false];
    $model['generatedBy']=['id'=>(string)$user['id'],'name'=>(string)($user['name']??$user['email']??'Authorized user')];
    $model['exceptions']=array_values($model['exceptions']??[]);$model['controlTotals']=$model['controlTotals']??[];
    $generated=gmdate('Y-m-d\TH:i:s\Z');$material=tegh_json_canonical($model);$hash=hash('sha256',$material);$totalsHash=hash('sha256',tegh_json_canonical($model['totals']??[]));
    $reference='TVO-'.strtoupper(substr(secret_hash($material.'|'.$generated.'|'.$user['id']),0,24));
    $model['verifiedOutput']=['reference'=>$reference,'generatedAt'=>$generated,'generatedBy'=>$model['generatedBy'],'companyTimezone'=>'America/Toronto','sourceRevisionHash'=>$hash,'totalsDigest'=>$totalsHash,'rowCount'=>$model['rowCount'],'statement'=>'Sealed server-authoritative output. Not an audit opinion.'];
    $model['outputReference']=$reference;$model['outputHash']=$hash;$model['immutable']=true;$model['accountingWrites']=0;$model['providerAttempts']=0;
    audit_event($user,(string)$company['id'],'professional_output.generated','professional_output',$reference,['definitionKey'=>$definition['key'],'definitionVersion'=>$definition['version'],'parameters'=>$p,'sourceRevisionHash'=>$hash,'totalsDigest'=>$totalsHash,'rowCount'=>$model['rowCount'],'accountingWrites'=>0,'providerAttempts'=>0]);
    return $model;
}
function tegh_report_output_5980(array $user,array $company,string $key,array $input): array
{
    $d=tegh_report_definitions_5980()[$key]??null;if(!$d)fail('Report definition not found.',404,'report_definition_not_found',false);
    foreach($d['permissions'] as $permission)require_company_permission($company,$permission);tegh_require_feature($user,$company,$d['feature']);
    $p=tegh_report_parameters_5980($d,$input);$pdo=db();if($pdo->inTransaction())throw new LogicException('Report snapshots require an independent read transaction.');
    $previous=$GLOBALS['tegh_report_building_5980']??false;$GLOBALS['tegh_report_building_5980']=true;
    try{$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
        $model=tegh_report_load_5980($user,$company,$d,$p);tegh_report_check_rows_5980($model['rows']??[]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{$GLOBALS['tegh_report_building_5980']=$previous;}
    // Recheck authorization outside the snapshot before issuing the immutable output.
    $fresh=require_company($user);if((string)$fresh['id']!==(string)$company['id'])fail('The company changed while generating the report.',409,'report_scope_changed',false);
    foreach($d['permissions'] as $permission)require_company_permission($fresh,$permission);
    tegh_require_feature($user,$fresh,$d['feature']);
    foreach(['currency','accounting_basis','reporting_framework','name','legal_name'] as $field){
        if(($fresh[$field]??null)!==($company[$field]??null))fail('Company report settings changed while the output was generated. Generate it again.',409,'report_settings_changed',false);
    }
    return tegh_report_finalize_5980($user,$fresh,$d,$p,$model);
}
function handle_report_output_5980(string $key): never
{
    require_method('GET');$user=require_user();$company=require_company($user);
    if($key==='definitions'){$items=[];foreach(tegh_report_definitions_5980() as $d)$items[]=$d;json_response(['contractVersion'=>TEGH_REPORT_CONTRACT_5980,'definitions'=>$items]);}
    $aliases=['invoice'=>'customer-invoice','bill'=>'vendor-bill','ar-aging'=>'ar-ageing','ap-aging'=>'ap-ageing','audit-trail'=>'audit-history','bank-reconciliation'=>'reconciliation-summary','customer-trial-balance'=>'ar-trial-balance','vendor-trial-balance'=>'ap-trial-balance'];$key=$aliases[$key]??$key;
    if($key==='trial-balance'&&isset($_GET['scope'])){
        $scope=strtolower(trim((string)$_GET['scope']));
        $key=match($scope){'general-ledger','general','all',''=>'trial-balance','receivables','ar','customers'=>'ar-trial-balance','payables','ap','vendors'=>'ap-trial-balance',default=>''};
        if($key==='')fail('Unsupported trial balance scope.',422,'report_scope_invalid',false);
    }
    try{$output=tegh_service_boundary(fn()=>tegh_report_output_5980($user,$company,$key,$_GET));}
    catch(Throwable $error){tegh_fail_service($error);}
    json_response(['output'=>$output]);
}
