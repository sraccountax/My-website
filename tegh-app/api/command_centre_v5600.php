<?php
declare(strict_types=1);

/** Tegh 5.6.0 structured command surface backed only by tegh_action_registry(). */

/** @return array<string,mixed> */
function tegh_command_project_action(array $action): array
{
    $class=function_exists('tegh_action_execution_class')?tegh_action_execution_class($action):(!empty($action['financial_commit'])||!empty($action['destructive'])?'authorized':'immediate');
    $blocklist=[];
    if($class==='authorized')$blocklist[]='requires_fresh_server_preview';
    if(in_array((string)($action['confirmation_requirement']??'none'),['explicit','strong'],true))$blocklist[]='requires_explicit_confirmation';
    if(!empty($action['financial_commit']))$blocklist[]='financial_commit';
    if(!empty($action['destructive']))$blocklist[]='destructive_action';
    $action['execution_class']=$class;
    $action['safe_stop']=$blocklist!==[];
    $action['blocklist']=array_values(array_unique($blocklist));
    return $action;
}

/** @return array<int,array<string,mixed>> */
function tegh_command_visible_registry(array $user,array $company): array
{
    // R123: ~170 actions share about a dozen feature keys; resolve each key once.
    $out=[];$features=[];$platformOwner=platform_role_for_user((string)$user['id'])==='platform_owner';
    foreach(tegh_action_registry() as $action){
        if(empty($action['command_eligible']))continue;
        if(!company_role_can((string)$company['role'],(string)$action['required_permission']))continue;
        if(!empty($action['owner_only'])&&!$platformOwner)continue;
        $feature=(string)($action['required_feature_key']??'core.accounting');$resolved=$features[$feature]??=tegh_resolve_feature($user,$company,$feature);if(!$resolved['allowed'])continue;
        $action['entitlement_revision']=(int)$resolved['revision'];$action['advanced']=(bool)($resolved['billable']??false);$out[]=tegh_command_project_action($action);
    }
    return $out;
}

/** @return array<string,mixed> */
function tegh_command_validate_parameters(array $action,mixed $raw): array
{
    if(!is_array($raw))fail('Command parameters must be a typed object.',422,'command_parameters_invalid');$schema=(array)($action['parameter_schema']??[]);$properties=(array)($schema['properties']??[]);
    foreach(array_keys($raw) as $key)if(!is_string($key)||!array_key_exists($key,$properties))fail('The command included an unregistered parameter.',422,'command_parameter_unregistered');
    foreach((array)($schema['required']??[]) as $required)if(!array_key_exists((string)$required,$raw))fail('A required command parameter is missing: '.(string)$required.'.',422,'command_parameter_required');
    $out=[];foreach($raw as $key=>$value){$definition=(array)$properties[$key];$type=(string)($definition['type']??'string');$valid=match($type){'array'=>is_array($value),'integer'=>is_int($value)||(is_string($value)&&preg_match('/^-?\d+$/',$value)),'boolean'=>is_bool($value),'string'=>is_string($value),default=>false};if(!$valid)fail('A command parameter has the wrong type: '.$key.'.',422,'command_parameter_type');if(($definition['format']??'')==='date')$value=safe_date($value,$key);if($type==='integer'){$value=(int)$value;if(array_key_exists('minimum',$definition)&&$value<(int)$definition['minimum'])fail('A command parameter is below its minimum: '.$key.'.',422,'command_parameter_range');if(array_key_exists('maximum',$definition)&&$value>(int)$definition['maximum'])fail('A command parameter is above its maximum: '.$key.'.',422,'command_parameter_range');}$out[$key]=$value;}
    foreach($out as $key=>$value){
        $definition=(array)$properties[$key];
        if(isset($definition['enum'])&&!in_array($value,(array)$definition['enum'],true))fail('Choose an allowed value for '.$key.'.',422,'command_parameter_enum');
        if(is_string($value)){
            $length=mb_strlen($value);
            if($length<(int)($definition['minLength']??0)||$length>(int)($definition['maxLength']??4000))fail('The value for '.$key.' has an invalid length.',422,'command_parameter_length');
        }
        if(is_array($value)){
            if(!array_is_list($value)||count($value)<(int)($definition['minItems']??0)||count($value)>(int)($definition['maxItems']??100))fail('Choose a valid number of records for '.$key.'.',422,'command_parameter_items');
            if(($definition['items']['type']??'')==='string')foreach($value as $item)if(!is_string($item)||trim($item)===''||mb_strlen($item)>(int)($definition['items']['maxLength']??64))fail('A selected record is invalid.',422,'command_parameter_item_type');
            if(!empty($definition['uniqueItems'])&&count(array_unique($value,SORT_STRING))!==count($value))fail('The same record was selected more than once.',422,'command_parameter_duplicate');
        }
    }
    foreach([['periodStart','periodEnd'],['start','end'],['from','to']] as [$start,$end])if(isset($out[$start],$out[$end])&&$out[$start]>$out[$end])fail('The start date must be on or before the end date.',422,'command_period_invalid');
    return $out;
}

/** Reject a partially stale selection instead of silently analyzing a subset. */
function tegh_command_assert_selection_rows(array $ids,array $rows): void
{
    $eligible=array_fill_keys(array_map(static fn(array $row):string=>(string)$row['id'],$rows),true);
    foreach($ids as $id)if(!isset($eligible[$id]))fail('A selected item has changed or is unavailable in this account and period. Refresh your selection.',409,'command_selection_stale');
}

function tegh_command_validate_bank_selection(array $company,array $parameters): void
{
    $accountId=(string)$parameters['accountId'];$companyId=(string)$company['id'];$ids=$parameters['bankTransactionIds'];
    $q=db()->prepare("SELECT id FROM bank_accounts WHERE id=? AND company_id=? AND active=1 AND account_type IN ('bank','credit_card')");$q->execute([$accountId,$companyId]);
    if(!$q->fetchColumn())fail('Choose an available financial account in this company.',404,'bank_account_not_found');
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $q=db()->prepare("SELECT bt.id FROM bank_transactions bt WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.transaction_date BETWEEN ? AND ? AND bt.id IN ($marks) AND bt.status NOT IN ('duplicate','excluded') AND NOT EXISTS (SELECT 1 FROM bank_match_bank_items bi JOIN bank_match_groups g ON g.id=bi.match_group_id WHERE bi.bank_transaction_id=bt.id AND g.status='matched') AND NOT EXISTS (SELECT 1 FROM reconciliation_items ri JOIN reconciliations r ON r.id=ri.reconciliation_id WHERE ri.bank_transaction_id=bt.id AND r.company_id=bt.company_id AND r.status='complete')");
    $q->execute(array_merge([$companyId,$accountId,$parameters['periodStart'],$parameters['periodEnd']],$ids));tegh_command_assert_selection_rows($ids,$q->fetchAll());
}

function tegh_command_idempotency_key(array $input): string
{
    $key=trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY']??$input['idempotencyKey']??''));
    if($key==='')$key='cmd_'.substr(hash('sha256',request_id()),0,48);
    if(strlen($key)<16||strlen($key)>128||!preg_match('/^[A-Za-z0-9._:-]+$/',$key))fail('Use an idempotency key of 16 to 128 letters, numbers, dots, colons, underscores, or hyphens.',422,'command_idempotency_key_invalid');
    return $key;
}

/**
 * Serialize admission with the same company row lock that protects audit order.
 * A successful non-replay return intentionally leaves the transaction open so
 * the command audit row is committed in the same admission boundary.
 * @return array{replay:bool,keyHash:string}
 */
function tegh_command_admit(array $user,array $company,string $key): array
{
    $pdo=db();$companyId=(string)$company['id'];$userId=(string)$user['id'];$keyHash=hash('sha256',$key);
    try{
        if($pdo->inTransaction())throw new RuntimeException('Command admission cannot join an unrelated transaction.');
        if(!schema_table_exists('voucher_sequences')||!schema_table_exists('audit_log'))throw new RuntimeException('Command admission storage is unavailable.');
        $pdo->beginTransaction();
        $pdo->prepare('INSERT IGNORE INTO voucher_sequences (company_id,next_serial) VALUES (?,1)')->execute([$companyId]);
        $lock=$pdo->prepare('SELECT next_serial FROM voucher_sequences WHERE company_id=? FOR UPDATE');$lock->execute([$companyId]);$lock->fetchColumn();
        $duplicate=$pdo->prepare("SELECT id FROM audit_log WHERE company_id=? AND actor_user_id=? AND action='command_centre.command_invoked' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.idempotencyKeyHash'))=? ORDER BY created_at DESC,id DESC LIMIT 1");
        $duplicate->execute([$companyId,$userId,$keyHash]);
        if($duplicate->fetchColumn()!==false){$pdo->commit();return ['replay'=>true,'keyHash'=>$keyHash];}
        $limit=$pdo->prepare("SELECT COUNT(*) company_count,COALESCE(SUM(actor_user_id=?),0) user_count FROM audit_log WHERE company_id=? AND action='command_centre.command_invoked' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)");
        $limit->execute([$userId,$companyId]);$counts=$limit->fetch()?:['company_count'=>0,'user_count'=>0];
        if((int)$counts['user_count']>=60|| (int)$counts['company_count']>=300){$pdo->rollBack();fail('Too many Tegh commands were submitted. Wait a moment and try again.',429,'command_rate_limited',false);}
        return ['replay'=>false,'keyHash'=>$keyHash];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();error_log('Tegh command admission failed request='.request_id().' class='.$error::class);fail('Tegh command admission could not be verified. Nothing was run.',503,'command_admission_unavailable',false);}
}

/** @return array<string,mixed> */
function tegh_command_aging_data(array $company,string $type,string $asOf): array
{
    $asOf=safe_date($asOf,'As-of date');$companyId=(string)$company['id'];
    if($type==='receivable')$sql="SELECT i.id,i.number,i.issue_date document_date,i.due_date,i.balance_cents,c.name party FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.company_id=? AND i.balance_cents>0 AND i.status IN ('sent','paid') AND i.issue_date<=?";
    else $sql="SELECT b.id,b.number,b.bill_date document_date,b.due_date,b.balance_cents,v.name party FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id WHERE b.company_id=? AND b.balance_cents>0 AND b.status IN ('open','paid') AND b.bill_date<=?";
    $stmt=db()->prepare($sql);$stmt->execute([$companyId,$asOf]);$rows=[];$total=0;$overdue=0;
    foreach($stmt->fetchAll() as $row){$days=max(0,(int)floor((strtotime($asOf)-strtotime((string)$row['due_date']))/86400));$amount=(int)$row['balance_cents'];$total+=$amount;if($days>0)$overdue+=$amount;$rows[]=['id'=>(string)$row['id'],'number'=>(string)$row['number'],'party'=>(string)$row['party'],'documentDate'=>(string)$row['document_date'],'dueDate'=>(string)$row['due_date'],'daysOverdue'=>$days,'balanceCents'=>$amount];}
    usort($rows,static fn(array $a,array $b):int=>$b['daysOverdue']<=>$a['daysOverdue']?:strcmp($a['party'],$b['party']));return ['type'=>$type,'asOf'=>$asOf,'rows'=>$rows,'totals'=>['openCents'=>$total,'overdueCents'=>$overdue],'currency'=>(string)$company['currency'],'balanceBasis'=>'current_outstanding','basisNotice'=>'Current outstanding balances, aged at '.$asOf.'. This does not reconstruct balances outstanding on a past date.','accountingWrites'=>0];
}

/** @return array<string,mixed> */
function tegh_command_due_items(array $company,string $type,string $from,string $to): array
{
    $from=safe_date($from,'From date');$to=safe_date($to,'To date');if($from>$to)fail('From date cannot be after To date.',422,'command_period_invalid');$companyId=(string)$company['id'];
    if($type==='receivable')$sql="SELECT i.id,i.number,i.issue_date document_date,i.due_date,i.balance_cents,c.name party FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.company_id=? AND i.status='sent' AND i.balance_cents>0 AND i.due_date BETWEEN ? AND ?";
    else $sql="SELECT b.id,b.number,b.bill_date document_date,b.due_date,b.balance_cents,v.name party FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id WHERE b.company_id=? AND b.status='open' AND b.balance_cents>0 AND b.due_date BETWEEN ? AND ?";
    $stmt=db()->prepare($sql.' ORDER BY due_date,number');$stmt->execute([$companyId,$from,$to]);$rows=[];$total=0;foreach($stmt->fetchAll() as $row){$amount=(int)$row['balance_cents'];$total+=$amount;$rows[]=['id'=>(string)$row['id'],'number'=>(string)$row['number'],'party'=>(string)$row['party'],'documentDate'=>(string)$row['document_date'],'dueDate'=>(string)$row['due_date'],'balanceCents'=>$amount];}
    return ['type'=>$type,'from'=>$from,'to'=>$to,'rows'=>$rows,'totalCents'=>$total,'currency'=>(string)$company['currency']];
}

/** @return array<string,mixed> */
function tegh_command_vendor_payment_history(array $company,string $from,string $to): array
{
    $from=safe_date($from,'From date');$to=safe_date($to,'To date');if($from>$to)fail('From date cannot be after To date.',422,'command_period_invalid');
    $stmt=db()->prepare("SELECT pp.id,pp.payment_date,pp.reference,pp.amount_cents,pp.currency,v.name vendor,b.number document_number FROM party_payments pp JOIN vendors v ON v.id=pp.party_id AND v.company_id=pp.company_id LEFT JOIN bills b ON b.id=pp.document_id AND b.company_id=pp.company_id WHERE pp.company_id=? AND pp.payment_type='vendor' AND pp.status='posted' AND pp.payment_date BETWEEN ? AND ? ORDER BY pp.payment_date DESC,pp.id");$stmt->execute([$company['id'],$from,$to]);$rows=[];$total=0;foreach($stmt->fetchAll() as $row){$amount=(int)$row['amount_cents'];$total+=$amount;$rows[]=['id'=>(string)$row['id'],'paymentDate'=>(string)$row['payment_date'],'vendor'=>(string)$row['vendor'],'documentNumber'=>(string)($row['document_number']??''),'reference'=>(string)$row['reference'],'amountCents'=>$amount,'currency'=>(string)$row['currency']];}return ['from'=>$from,'to'=>$to,'rows'=>$rows,'totalCents'=>$total];
}

/** @return array<string,mixed> */
function tegh_command_bank_duplicates(array $company,string $accountId,string $start,string $end): array
{
    $accountId=clean_text($accountId,'Financial account',64);$start=safe_date($start,'Period start');$end=safe_date($end,'Period end');if($start>$end)fail('Period start cannot be after period end.',422,'command_period_invalid');
    $account=db()->prepare("SELECT id,name,currency FROM bank_accounts WHERE id=? AND company_id=? AND active=1 AND account_type IN ('bank','credit_card')");$account->execute([$accountId,$company['id']]);$accountRow=$account->fetch();if(!$accountRow)fail('That financial account is unavailable.',404,'bank_account_not_found');
    $stmt=db()->prepare("SELECT source_hash,COUNT(*) duplicate_count,MIN(transaction_date) first_date,MAX(transaction_date) last_date,SUM(amount_cents) combined_cents FROM bank_transactions WHERE company_id=? AND bank_account_id=? AND transaction_date BETWEEN ? AND ? AND source_hash IS NOT NULL AND source_hash<>'' GROUP BY source_hash HAVING COUNT(*)>1 ORDER BY duplicate_count DESC,first_date");$stmt->execute([$company['id'],$accountId,$start,$end]);$rows=[];foreach($stmt->fetchAll() as $row)$rows[]=['fingerprintReference'=>substr(hash('sha256',(string)$row['source_hash']),0,16),'count'=>(int)$row['duplicate_count'],'firstDate'=>(string)$row['first_date'],'lastDate'=>(string)$row['last_date'],'combinedCents'=>(int)$row['combined_cents']];return ['account'=>['id'=>(string)$accountRow['id'],'name'=>(string)$accountRow['name'],'currency'=>(string)$accountRow['currency']],'period'=>['start'=>$start,'end'=>$end],'rows'=>$rows,'providerUsed'=>false];
}

/** @return array<string,mixed>|null */
function tegh_command_deterministic_result(array $action,array $parameters,array $company): ?array
{
    $id=(string)$action['action_id'];$today=canadian_today();$from=(string)($parameters['from']??substr($today,0,4).'-01-01');$to=(string)($parameters['to']??$today);$asOf=(string)($parameters['asOf']??$today);$period=null;
    $periodKey=match($id){'report.profit_loss'=>'profit_loss','report.balance_sheet'=>'balance_sheet','report.trial_balance'=>'trial_balance','report.cash_flow'=>'cash_flow','analysis.cash_forecast'=>'cash_forecast',default=>null};
    if($periodKey!==null){$period=tegh_report_period_resolve($company,$parameters,$periodKey);$from=$period['start'];$to=$period['end'];$asOf=$period['asOf']??$period['end'];}
    return match($id){
        'report.profit_loss'=>['renderer'=>'financial_statement','data'=>array_merge(portal_financial_report_data($company,'profit-loss',$from,$to),['period'=>$period,'accountingWrites'=>0])],
        'report.balance_sheet'=>['renderer'=>'financial_statement','data'=>array_merge(portal_financial_report_data($company,'balance-sheet',$asOf,$asOf),['period'=>$period,'asOf'=>$asOf,'accountingWrites'=>0])],
        'report.trial_balance'=>['renderer'=>'trial_balance','data'=>array_merge(portal_financial_report_data($company,'trial-balance',$from,$to),['period'=>$period,'accountingWrites'=>0])],
        'report.cash_flow'=>['renderer'=>'cash_flow','data'=>array_merge(advanced_cash_flow_data((string)$company['id'],$from,$to),['period'=>$period,'accountingWrites'=>0])],
        'analysis.cash_forecast'=>['renderer'=>'cash_forecast','data'=>(static function()use($company,$parameters,$period):array{$forecast=financial_cash_forecast($company,optional_text($parameters['scenarioId']??null,64),['start'=>$period['start'],'end'=>$period['end'],'bucketUnit'=>(string)($parameters['bucketUnit']??$period['unit']??'month')]);$forecast['period']=$period;return $forecast;})()],
        'report.ar_aging'=>['renderer'=>'aging','data'=>tegh_command_aging_data($company,'receivable',$asOf)],
        'report.ap_aging'=>['renderer'=>'aging','data'=>tegh_command_aging_data($company,'payable',$asOf)],
        'report.pay_run_register','nav.payroll_runs'=>['renderer'=>'payroll_register','data'=>payroll_workspace_data($company)],
        'receivables.overdue'=>['renderer'=>'aging','data'=>array_merge(tegh_command_aging_data($company,'receivable',$asOf),['rows'=>array_values(array_filter(tegh_command_aging_data($company,'receivable',$asOf)['rows'],static fn(array $row):bool=>$row['daysOverdue']>0))])],
        'payables.overdue'=>['renderer'=>'aging','data'=>array_merge(tegh_command_aging_data($company,'payable',$asOf),['rows'=>array_values(array_filter(tegh_command_aging_data($company,'payable',$asOf)['rows'],static fn(array $row):bool=>$row['daysOverdue']>0))])],
        'receivables.expected_collections'=>['renderer'=>'due_items','data'=>tegh_command_due_items($company,'receivable',$from,$to)],
        'payables.upcoming'=>['renderer'=>'due_items','data'=>tegh_command_due_items($company,'payable',$from,$to)],
        'payables.payment_history'=>['renderer'=>'payment_history','data'=>tegh_command_vendor_payment_history($company,$from,$to)],
        'payroll.readiness'=>['renderer'=>'payroll_readiness','data'=>(static function()use($company):array{$workspace=payroll_workspace_data($company);$active=array_values(array_filter((array)($workspace['employees']??[]),static fn(array $employee):bool=>!empty($employee['active'])));$drafts=array_values(array_filter((array)($workspace['runs']??[]),static fn(array $run):bool=>(string)($run['status']??'')==='draft'));return ['settingsReady'=>!empty($workspace['settings']),'activeEmployeeCount'=>count($active),'draftRunCount'=>count($drafts),'ready'=>!empty($workspace['settings'])&&count($active)>0,'providerUsed'=>false];})()],
        'bank.matches.analyze'=>['renderer'=>'reconciliation_proposals','data'=>tegh_recon_analysis($company,$parameters)],
        'bank.matches.review_exact'=>['renderer'=>'reconciliation_proposals','data'=>(static function()use($company,$parameters):array{$analysis=tegh_recon_analysis($company,$parameters);$analysis['matchProposals']=array_values(array_filter($analysis['matchProposals'],static fn(array $proposal):bool=>(string)$proposal['kind']==='exact_source'));$analysis['postCandidates']=[];return $analysis;})()],
        'bank.duplicates.find'=>['renderer'=>'duplicate_candidates','data'=>tegh_command_bank_duplicates($company,(string)$parameters['accountId'],(string)$parameters['periodStart'],(string)$parameters['periodEnd'])],
        'report.current.explain'=>tegh_command_explain_report($parameters,$company),
        default=>null,
    };
}

/** Re-read authorized records; never treat browser totals as verified evidence. */
function tegh_command_explain_report(array $parameters,array $company): array
{
    $id=(string)($parameters['reportId']??'');$allowed=['report.profit_loss','report.balance_sheet','report.trial_balance','report.cash_flow','report.ar_aging','report.ap_aging'];
    if(!in_array($id,$allowed,true))fail('Open a supported financial or ageing report first, then choose Explain this report.',422,'report_context_required');
    if(!empty($parameters['comparison']))fail('This explanation cannot apply comparison filters. Open the report comparison controls to review those figures.',422,'report_context_filter_unsupported');
    $snapshot=in_array($id,['report.balance_sheet','report.ar_aging','report.ap_aging'],true);
    if(($snapshot&&empty($parameters['asOf']))||(!$snapshot&&(empty($parameters['start'])||empty($parameters['end']))))fail('Choose the current report dates before asking for an explanation.',422,'report_context_period_required');
    $action=tegh_action_get($id);require_company_permission($company,(string)$action['required_permission']);
    $typed=$snapshot?['asOf'=>$parameters['asOf']]:['mode'=>'custom','start'=>$parameters['start'],'end'=>$parameters['end']];
    if(isset($action['parameter_schema']['properties']['mode'])&&$snapshot)$typed['mode']='as_of';
    $typed=tegh_command_validate_parameters($action,$typed);$result=tegh_command_deterministic_result($action,$typed,$company);
    $period=$result['data']['period']??null;$label=(string)$action['plain_label'];
    return ['renderer'=>'report_explanation','data'=>['reportId'=>$id,'reportLabel'=>$label,'period'=>$period,'asOf'=>$result['data']['asOf']??null,'verifiedReport'=>$result,'message'=>$label.' has been refreshed for the selected company and dates. Review the rows and totals below.','basisNotice'=>$result['data']['basisNotice']??'Amounts come from the existing posted-accounting report service.','providerUsed'=>false,'accountingWrites'=>0]];
}

/** @return array<string,mixed> */
function tegh_command_catalog_payload(array $user,array $company,string $screen=''): array
{
    $actions=tegh_command_visible_registry($user,$company);$ids=array_map(static fn(array $row):string=>(string)$row['action_id'],$actions);sort($ids,SORT_STRING);$categories=['Today','Banking','Receivables','Payables','Payroll','Reports','Documents','Month-End','Setup'];
    $preferences=agent_interface_preference($user,$company);$mode=(string)($_GET['mode']??'full');if(!in_array($mode,['guided','full'],true))$mode='full';$pinned=(array)($preferences['quickActions'][$mode]['actionIds']??[]);
    $recent=[];if(schema_table_exists('audit_log')){$stmt=db()->prepare("SELECT entity_id,metadata_json,created_at FROM audit_log WHERE company_id=? AND actor_user_id=? AND action='command_centre.command_invoked' ORDER BY created_at DESC LIMIT 20");$stmt->execute([$company['id'],$user['id']]);foreach($stmt->fetchAll() as $row){$meta=json_decode((string)$row['metadata_json'],true)?:[];$recent[]=['actionId'=>(string)$row['entity_id'],'result'=>(string)($meta['resultKind']??''),'createdAt'=>(string)$row['created_at']];}}
    $queue=[];if(schema_table_exists('ai_agent_tasks')){$stmt=db()->prepare("SELECT id,action_id,status,workflow_state,updated_at FROM ai_agent_tasks WHERE company_id=? AND user_id=? AND status IN ('waiting_confirmation','waiting_input','active') ORDER BY updated_at DESC LIMIT 30");$stmt->execute([$company['id'],$user['id']]);$queue=$stmt->fetchAll();}
    return ['actions'=>$actions,'categories'=>$categories,'recommended'=>array_values(array_filter($actions,static fn(array $action):bool=>$screen!==''&&in_array($screen,(array)$action['recommended_screens'],true))),
        'pinned'=>array_slice($pinned,0,7),'recent'=>$recent,'preparedQueue'=>$queue,'count'=>count($actions),'classHash'=>hash('sha256',implode("\n",$ids)),'entitlementRevision'=>(int)(tegh_entitlement_my_payload($user,$company)['revision']??0),'providerRequired'=>false];
}

function tegh_command_result_continuations(array $action,array $user,array $company): array
{
    if(!in_array((string)$action['action_id'],['bank.matches.analyze','bank.matches.review_exact'],true))return [];
    $out=[];
    foreach(['bank.matches.match_selected','bank.postings.prepare_selected'] as $id){
        $next=tegh_action_get($id);$required=tegh_action_continuation_features($next);$missing=[];$resolved=tegh_resolve_feature($user,$company,(string)$next['required_feature_key']);
        foreach($required as $feature){$check=tegh_resolve_feature($user,$company,$feature);if(empty($check['allowed']))$missing[]=$feature;}
        $permission=company_role_can((string)$company['role'],(string)$next['required_permission']);
        $resolved['requiredFeatureKeys']=$required;$resolved['missingFeatureKeys']=$missing;
        $resolved['unavailableReason']=$permission?'This review needs additional feature access. Open Features & requests.':'Your role cannot confirm this accounting action.';
        $out[]=tegh_contextual_assistance_action($next,$resolved,$permission&&$missing===[]);
    }
    return $out;
}

function tegh_command_execute(array $user,array $company): never
{
    require_method('POST');require_csrf();$input=request_json();$actionId=clean_text($input['actionId']??'','Action ID',120);$action=tegh_command_project_action(tegh_action_get($actionId));tegh_action_assert_permission($company,$action,$user,(string)($input['mode']??'full'));$parameters=tegh_command_validate_parameters($action,$input['parameters']??[]);$idempotencyKey=tegh_command_idempotency_key($input);
    if(in_array($actionId,['bank.matches.analyze','bank.matches.review_exact'],true))tegh_command_validate_bank_selection($company,$parameters);
    $resolved=tegh_resolve_feature($user,$company,(string)$action['required_feature_key']);$clientRevision=(int)($input['entitlementRevision']??-1);if($clientRevision<0||$clientRevision!==(int)$resolved['revision'])fail('Feature access changed. Refresh the Command Centre before continuing.',409,'stale_entitlement_revision');
    $admission=tegh_command_admit($user,$company,$idempotencyKey);
    $result=tegh_command_deterministic_result($action,$parameters,$company);$kind='workflow';
    if($result!==null)$kind='result';elseif(!empty($action['safe_stop'])){$kind='approval_required';$result=['message'=>'Open the protected workflow to prepare a fresh signed preview. Nothing has been committed.','route'=>(string)$action['route'],'actionId'=>$actionId,'safe_stop'=>true,'blocklist'=>$action['blocklist']];}else $result=['route'=>(string)$action['route'],'message'=>'The registered Tegh workflow is ready to open.','safe_stop'=>false,'blocklist'=>[]];
    $response=['ok'=>true,'kind'=>$kind,'outcome'=>$kind==='result'?'analysis_complete':($kind==='approval_required'?'ready_for_review':'workflow_available'),'accountingWrites'=>0,'parameters'=>$parameters,'action'=>['actionId'=>$actionId,'label'=>$action['plain_label'],'riskLabel'=>$action['risk_label'],'route'=>$action['route'],'safe_stop'=>(bool)$action['safe_stop'],'blocklist'=>$action['blocklist']],'result'=>$result,'providerUsed'=>false,'requestReference'=>request_id(),'idempotencyKey'=>$idempotencyKey,'idempotentReplay'=>(bool)$admission['replay']];
    $response['continuations']=tegh_command_result_continuations($action,$user,$company);
    if(!empty($admission['replay']))json_response($response);
    audit_event($user,(string)$company['id'],'command_centre.command_invoked','tegh_action',$actionId,['parametersHash'=>hash('sha256',json_encode($parameters,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'resultKind'=>$kind,'providerUsed'=>false,'entitlementRevision'=>(int)$resolved['revision'],'idempotencyKeyHash'=>$admission['keyHash'],'safeStop'=>(bool)$action['safe_stop'],'blocklist'=>$action['blocklist']]);
    db()->commit();tegh_usage_record((string)$company['id'],'tegh.command_centre',true,1);json_response($response);
}

/** Native date slots are conservative: an unclear period is never discarded. */
function tegh_command_interpret_parameters(string $query,array $action,array $company,array $entities=[],?string $today=null): array
{
    // Schema 42 supports the same accounting timezone as canadian_today().
    $timezone=function_exists('tegh_accounting_timezone')?tegh_accounting_timezone():'America/Toronto';$today=$today??canadian_today();$now=tegh_report_date($today,'Today');
    $text=strtolower(trim($query));$properties=(array)$action['parameter_schema']['properties'];$parameters=[];$question='';$periodLabel='';$executionBlocked=false;$clarificationFields=[];
    $snapshot=isset($properties['asOf'])&&!isset($properties['start']);$phraseLabel='';$navigation=in_array((string)($action['action_type']??''),['navigation','prepare'],true);
    $periodSupported=isset($properties['start'])||isset($properties['from'])||isset($properties['periodStart'])||isset($properties['asOf']);
    $start=null;$end=null;$matches=[];preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/',$query,$matches);$dates=$matches[0];
    foreach($dates as $date){$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone($timezone));if(!$parsed||$parsed->format('Y-m-d')!==$date)$question='Which valid reporting dates should I use? Use YYYY-MM-DD.';}
    if(count($dates)>2)$question='Which one reporting period should I use?';
    preg_match_all('/\b(?:(?:last|previous) month|last 30 days|fiscal year[- ]to[- ]date|fiscal ytd|fytd|(?:this|current) fiscal year|(?:last|previous|prior|this|current) quarter|(?:this|current) month|calendar year[- ]to[- ]date|this calendar year|today|yesterday)\b/',$text,$relativePeriods);
    if(count($relativePeriods[0])>1||($dates&&$relativePeriods[0]))$question='Which one reporting period should I use? The request contains different date instructions.';
    if($question===''){
        if(count($dates)===2){[$start,$end]=$dates;if($start>$end)$question='Which start and end dates should I use? The start must be before the end.';}
        elseif(count($dates)===1){if($snapshot)$start=$end=$dates[0];else $question='What is the other date in the reporting period?';}
        elseif(preg_match('/\b(?:last|previous) month\b/',$text)){[$start,$end]=tegh_report_period_preset($company,'previous_month',$today);}
        elseif(preg_match('/\blast 30 days\b/',$text)){$start=$now->modify('-29 days')->format('Y-m-d');$end=$today;}
        elseif(preg_match('/\b(?:fiscal year[- ]to[- ]date|fiscal ytd|fytd|this fiscal year|current fiscal year)\b/',$text)){[$start,$end]=tegh_report_period_preset($company,'fiscal_current',$today);}
        elseif(preg_match('/\b(?:last|previous|prior) quarter\b/',$text)){$month=(int)(floor(((int)$now->format('n')-1)/3)*3+1);$current=$now->setDate((int)$now->format('Y'),$month,1);$start=$current->modify('-3 months')->format('Y-m-d');$end=$current->modify('-1 day')->format('Y-m-d');}
        elseif(preg_match('/\b(?:this|current) quarter\b/',$text)){[$start,$end]=tegh_report_period_preset($company,'current_quarter',$today);}
        elseif(preg_match('/\b(?:this|current) month\b/',$text)){[$start,$end]=tegh_report_period_preset($company,'current_month',$today);}
        elseif(preg_match('/\b(?:calendar year[- ]to[- ]date|this calendar year)\b/',$text)){[$start,$end]=tegh_report_period_preset($company,'current_year',$today);}
        elseif(preg_match('/\b(?:today|current balances)\b/',$text)){$start=$end=$today;}
        elseif(preg_match('/\byesterday\b/',$text)){$start=$end=$now->modify('-1 day')->format('Y-m-d');}
        elseif(function_exists('tegh_assist_period_phrase')&&($phrase=tegh_assist_period_phrase(function_exists('tegh_assist_lay_rewrite')?tegh_assist_lay_rewrite($query):$text,$company,$today,in_array((string)$action['action_id'],['analysis.cash_forecast','receivables.expected_collections','payables.upcoming'],true)))!==null){[$start,$end,$phraseLabel]=$phrase;}
        elseif($periodSupported&&preg_match('/\b(?:period|month|quarter|year|ytd|as[- ]?of|january|february|march|april|may|june|july|august|september|october|november|december)\b|\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}/',$text))$question='Which reporting dates should I use? Choose dates or a supported period.';
        // R123: an everyday question with no dates runs on a sensible,
        // clearly labelled default period instead of stopping to ask.
        if($question===''&&$start===null&&function_exists('tegh_assist_default_period')){
            if($snapshot&&$periodSupported){$start=$end=$today;$phraseLabel='Today';}
            elseif(($default=tegh_assist_default_period((string)$action['action_id'],$company,$today))!==null){[$start,$end,$phraseLabel]=$default;$phraseLabel.=' (default)';}
        }
    }
    if($start!==null&&$end!==null&&$question===''){
        // R123: opening a screen ignores dates in the request instead of refusing.
        if(!$periodSupported){if(!$navigation){$question='This action cannot apply a reporting period. Would you like to open its workflow instead?';$executionBlocked=true;$clarificationFields[]='action';}}
        elseif($snapshot){$parameters['asOf']=$end;if(isset($properties['mode']))$parameters['mode']='as_of';$periodLabel='As of '.$end;}
        elseif(isset($properties['start'])){$parameters=['mode'=>'custom','start'=>$start,'end'=>$end];if(!isset($properties['mode']))unset($parameters['mode']);$periodLabel=$start.' to '.$end;}
        elseif(isset($properties['periodStart'])){$parameters=['periodStart'=>$start,'periodEnd'=>$end];$periodLabel=$start.' to '.$end;}
        else{$parameters=['from'=>$start,'to'=>$end];$periodLabel=$start.' to '.$end;}
        if($phraseLabel!==''&&$periodLabel!=='')$periodLabel=$phraseLabel.' · '.$periodLabel;
    }
    $entityLabels=[];
    foreach(['customer'=>'customerId','vendor'=>'vendorId','account'=>'account'] as $type=>$field){
        $matches=array_values(array_filter($entities,static fn(array $entity):bool=>(string)($entity['type']??'')===$type&&(float)($entity['confidence']??0)>=1.0));
        if(count($matches)>1){$question=$question?:'Which '.$type.' did you mean? Choose one from the list.';$executionBlocked=true;$clarificationFields[]=$field;continue;}
        if(count($matches)===1&&isset($properties[$field])){$parameters[$field]=(string)$matches[0]['id'];$entityLabels[$field]=(string)$matches[0]['name'];}
        elseif(count($matches)===1&&preg_match('/\b(?:for|customer|vendor|supplier|account)\s+'.preg_quote(strtolower((string)$matches[0]['name']),'/').'\b/',$text)){$question=$question?:'This action cannot apply that '.$type.' filter. Which supported action would you like to use?';$executionBlocked=true;$clarificationFields[]=$field;}
        elseif(count($matches)===0&&isset($properties[$field])&&preg_match('/\b'.($type==='vendor'?'(?:vendor|supplier)':$type).'\s+(?!aging\b|ageing\b|balances?\b|ledger\b|invoices?\b|payments?\b)\S+/',$text)){$question=$question?:'Which '.$type.' did you mean? Choose the exact record before continuing.';$executionBlocked=true;$clarificationFields[]=$field;}
    }
    if(!$navigation&&preg_match('/\b(?:company|client file)\s+["\']?(.+?)(?:["\']|\s+(?:for|from|as of)\b|$)/',$text,$companyMatch)){
        $requested=trim((string)preg_replace('/\s+(?:(?:last|previous|prior|this|current)\s+(?:month|quarter|year)|fiscal year[- ]to[- ]date)\b.*$/','',trim($companyMatch[1])));$current=strtolower(trim((string)($company['name']??'')));
        if($requested!==''&&$requested!==$current){$question=$question?:'This request will use the selected company. Switch company first if you meant another one.';$executionBlocked=true;$clarificationFields[]='company';}
    }
    if(!$navigation&&preg_match('/\b(?:over|under|above|below|greater than|less than)\s*\$?\d|\bonly (?:debits|credits|unpaid|paid)\b/',$text)){$question=$question?:'Which exact filters should I apply? Open the transaction filter controls before continuing.';$executionBlocked=true;$clarificationFields[]='filters';}
    if(!$navigation&&preg_match('/\b(?:excluding|except|department|cost cent(?:er|re)|project filter)\b|\bwithout\s+(?!(?:posting|changing|reconciling|saving|ai|a model)\b)\S+|\bonly\s+(?!(?:last|previous|this|current|posted)\b)\S+/',$text)){$question=$question?:'This request includes a filter that has not been applied. Choose a supported filter or revise the request before running it.';$executionBlocked=true;$clarificationFields[]='filters';}
    if($periodSupported&&preg_match('/\b(?:compare|compared|versus|vs\.?)\b/',$text)){$question=$question?:'Which two exact periods should I compare? Open the report comparison controls to review them.';$executionBlocked=true;$clarificationFields[]='comparison';}
    if($periodSupported&&$periodLabel===''&&$question==='')$question=$snapshot?'Which as-of date should I use?':'Which reporting period should I use?';
    if($question!==''&&!$executionBlocked)$clarificationFields=$snapshot?['asOf']:['start','end'];
    return ['parameters'=>$parameters,'needsClarification'=>$question!=='','clarificationQuestion'=>$question,'clarificationFields'=>array_values(array_unique($clarificationFields)),'canResolveWithPeriod'=>$question!==''&&!$executionBlocked,'executionBlocked'=>$executionBlocked,'interpretation'=>['companyId'=>(string)$company['id'],'companyName'=>(string)($company['name']??''),'timezone'=>$timezone,'periodLabel'=>$periodLabel,'filters'=>$entityLabels,'dateSource'=>'native','accountingWrites'=>0]];
}

function tegh_command_compound_plan(string $query): ?array
{
    $parts=preg_split('/\s*(?:;|\b(?:and then|then|after that)\b|\band\b(?=\s+(?:show|generate|provide|run|post|pay|create|prepare|explain|reconcile|finalize|delete|void)\b))\s*/i',$query,-1,PREG_SPLIT_NO_EMPTY)?:[];
    if(count($parts)<2)return null;
    if(count($parts)>6)return ['kind'=>'choices','choices'=>[],'message'=>'Please split this into at most six steps so each result can be reviewed.','needsClarification'=>true,'committed'=>false,'providerUsed'=>false];
    $plan=[];foreach($parts as $index=>$part)$plan[]=['position'=>$index+1,'request'=>trim($part),'status'=>'needs_review'];
    return ['kind'=>'plan','compoundRequest'=>true,'orderedPlan'=>$plan,'choices'=>[],'message'=>'Review each step in order. A later step stays blocked until the previous result is verified.','committed'=>false,'providerUsed'=>false,'accountingWrites'=>0];
}

function tegh_command_free_text(array $user,array $company): never
{
    require_method('POST');require_csrf();$input=request_json();$query=trim((string)($input['query']??''));if($query==='')fail('Enter a command search.',422,'command_query_required');if(mb_strlen($query)>1200)fail('Keep each request under 1,200 characters.',422,'command_query_too_long');$filtered=tegh_router_language_filter($query);$normalized=tegh_morphology_normalize_action_text((string)$filtered['text']);$prohibited=tegh_router_prohibited_request($normalized);if($prohibited!==null)json_response($prohibited+['committed'=>false,'providerUsed'=>false]);
    $plan=tegh_command_compound_plan($query);if($plan!==null)json_response($plan);
    $registry=[];foreach(tegh_command_visible_registry($user,$company) as $action)$registry[(string)$action['action_id']]=$action;
    $entities=tegh_router_entity_candidates($normalized,tegh_company_lexicon($user,$company),5);$ranked=tegh_router_native_rank($normalized,$registry,$entities,$company);$candidates=(array)($ranked['candidates']??[]);
    // R123: a screen and its report often share one name ("Trial Balance").
    // Offer it once, preferring the version that shows the answer here.
    $byLabel=[];foreach($candidates as $candidate){$label=mb_strtolower((string)($registry[(string)$candidate['action_id']]['plain_label']??$candidate['action_id']));$current=$byLabel[$label]??null;$prefer=static fn(string $id):int=>str_starts_with($id,'nav.')?0:1;if($current===null||(float)$candidate['confidence']>(float)$current['confidence']||((float)$candidate['confidence']===(float)$current['confidence']&&$prefer((string)$candidate['action_id'])>$prefer((string)$current['action_id'])))$byLabel[$label]=$candidate;}
    $candidates=array_values($byLabel);usort($candidates,static fn($a,$b)=>$b['confidence']<=>$a['confidence']?:strcmp((string)$a['action_id'],(string)$b['action_id']));$topConfidence=(float)($candidates[0]['confidence']??0);$runnerConfidence=(float)($candidates[1]['confidence']??0);
    $choices=[];foreach(array_slice($candidates,0,6) as $candidate){$action=$registry[(string)$candidate['action_id']]??null;if(!$action)continue;$interpreted=tegh_command_interpret_parameters($query,$action,$company,$entities);$choices[]=['actionId'=>(string)$action['action_id'],'label'=>(string)$action['plain_label'],'description'=>(string)$action['plain_description'],'riskLabel'=>(string)$action['risk_label'],'confidence'=>(float)$candidate['confidence'],'safe_stop'=>(bool)$action['safe_stop'],'blocklist'=>$action['blocklist']]+$interpreted;}
    $resolved=count($choices)>=1&&$topConfidence>=.72&&($topConfidence-$runnerConfidence)>=.10;
    if(!$resolved)json_response(['kind'=>'choices','message'=>$choices?'Choose the command you meant.':'No registered command matched. Try a screen or accounting task name.','choices'=>$choices,'committed'=>false,'providerUsed'=>false,'normalizedBy'=>'tegh_router_native']);
    json_response(['kind'=>'candidate','candidate'=>$choices[0],'message'=>'Review this registered command and its typed parameters before continuing.','committed'=>false,'providerUsed'=>false,'normalizedBy'=>'tegh_router_native']);
}

/** Bounded names for ordinary input controls; identifiers remain server-owned. */
function tegh_command_picker_payload(array $company,string $kind,string $query,string $selectedId=''): array
{
    $specs=[
        'accountId'=>['bank_accounts','banking.view','name',"active=1 AND account_type IN ('bank','credit_card')"],
        'account'=>['accounts','company.view','code','active=1'],
        'customerId'=>['customers','customers.view','name','active=1'],
        'vendorId'=>['vendors','vendors.view','name','active=1'],
    ];
    if(!isset($specs[$kind]))fail('Choose a supported record picker.',422,'command_picker_invalid');
    [$table,$permission,$order,$condition]=$specs[$kind];require_company_permission($company,$permission);
    $query=mb_substr(trim($query),0,120);$params=[(string)$company['id']];$where='';
    if($query!==''){$where=' AND (name LIKE ?'.($kind==='account'?' OR code LIKE ?':'').')';$params[]='%'.$query.'%';if($kind==='account')$params[]='%'.$query.'%';}
    $sql='SELECT id,name'.($kind==='account'?',code':'')." FROM $table WHERE company_id=? AND $condition$where ORDER BY $order,id LIMIT 51";
    $q=db()->prepare($sql);$q->execute($params);$rows=$q->fetchAll();$items=[];
    foreach(array_slice($rows,0,50) as $row)$items[]=['id'=>(string)$row['id'],'name'=>(string)$row['name'],'code'=>(string)($row['code']??'')];
    $selectedItem=null;
    if($selectedId!==''){
        if(strlen($selectedId)>64)fail('The selected record is invalid.',422,'command_picker_invalid');
        $selected=db()->prepare('SELECT id,name'.($kind==='account'?',code':'')." FROM $table WHERE company_id=? AND $condition AND id=? LIMIT 1");$selected->execute([(string)$company['id'],$selectedId]);$row=$selected->fetch();
        if($row)$selectedItem=['id'=>(string)$row['id'],'name'=>(string)$row['name'],'code'=>(string)($row['code']??'')];
    }
    return ['items'=>$items,'selectedItem'=>$selectedItem,'hasMore'=>count($rows)>50,'companyId'=>(string)$company['id'],'accountingWrites'=>0];
}

function handle_command_centre(string $action): never
{
    $user=require_user();$company=require_company($user);require_company_permission($company,'company.view');tegh_require_feature($user,$company,'tegh.command_centre');
    if($action===''||$action==='catalog'){require_method('GET');json_response(tegh_command_catalog_payload($user,$company,mb_substr(trim((string)($_GET['screen']??'')),0,120)));}
    if($action==='pickers'){require_method('GET');json_response(tegh_command_picker_payload($company,(string)($_GET['kind']??''),(string)($_GET['query']??''),(string)($_GET['selectedId']??'')));}
    if($action==='execute')tegh_command_execute($user,$company);
    if($action==='resolve')tegh_command_free_text($user,$company);
    fail('Command Centre route not found.',404,'route_not_found');
}
