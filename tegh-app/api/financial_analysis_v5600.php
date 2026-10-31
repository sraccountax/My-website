<?php
declare(strict_types=1);

/**
 * Tegh 4.9 Financial Analyst.
 *
 * Forecasts are deterministic planning views over verified current-company
 * accounting records. Only scenario assumptions are persisted. Forecast
 * output is never an accounting record and this module never posts entries,
 * changes source documents or invokes Connected Intelligence.
 */

const FINANCIAL_FORECAST_MAX_DAYS = 1827;
const FINANCIAL_SCENARIO_BASES = ['open_items','budget'];
const FINANCIAL_SCENARIO_STATES = ['draft','active','archived'];

function financial_canonical(mixed $value): mixed
{
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('financial_canonical',$value);
    ksort($value,SORT_STRING);
    foreach($value as $key=>$item)$value[$key]=financial_canonical($item);
    return $value;
}

function financial_json(mixed $value): string
{
    return json_encode(financial_canonical($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}

function financial_hash(mixed $value): string
{
    return hash('sha256',financial_json($value));
}

function financial_schema_required(): void
{
    if(!function_exists('financial_analysis_schema_status')||!financial_analysis_schema_status()['ready']){
        fail('Financial Analyst storage is not ready. Run the protected Schema 37 upgrade.',503,'financial_analysis_schema_required');
    }
}

function financial_int(array $input,string $key,int $fallback,int $min,int $max): int
{
    if(!array_key_exists($key,$input))return $fallback;
    $raw=$input[$key];
    if(is_float($raw)&&floor($raw)!==$raw)fail('The '.$key.' assumption must be a whole number.',422,'financial_assumption_invalid');
    if(!is_int($raw)&&!is_float($raw)&&!is_string($raw))fail('The '.$key.' assumption must be a whole number.',422,'financial_assumption_invalid');
    $text=(string)$raw;
    if(!preg_match('/^-?\d+$/',$text))fail('The '.$key.' assumption must be a whole number.',422,'financial_assumption_invalid');
    $value=(int)$text;
    if($value<$min||$value>$max)fail('The '.$key.' assumption is outside the supported range.',422,'financial_assumption_invalid');
    return $value;
}

function financial_default_assumptions(): array
{
    return [
        'collectionDelayDays'=>0,
        'paymentDelayDays'=>0,
        'receivableRealizationBps'=>10000,
        'payableRealizationBps'=>10000,
        'incomeGrowthBps'=>0,
        'expenseGrowthBps'=>0,
        'minimumCashCents'=>0,
        'forecastMode'=>'long_range',
        'conservativeReceiptRealizationBps'=>8000,
        'receiptConfidenceThresholdBps'=>7500,
        'receiptSafetyDelayDays'=>2,
        'recurringChargeConfidenceThresholdBps'=>7500,
        'includeMediumConfidenceChargesConservative'=>false,
        'sourceOverrides'=>[],
    ];
}

function financial_assumptions(mixed $source,array $fallback=[]): array
{
    $input=is_array($source)?$source:[];
    $base=array_merge(financial_default_assumptions(),$fallback);
    $mode=(string)($input['forecastMode']??$base['forecastMode']);if(!in_array($mode,['long_range','four_week'],true))fail('Choose a supported forecast mode.',422,'financial_assumption_invalid');
    $includeMedium=$input['includeMediumConfidenceChargesConservative']??$base['includeMediumConfidenceChargesConservative'];if(!is_bool($includeMedium))fail('The medium-confidence charge choice must be true or false.',422,'financial_assumption_invalid');
    $rawOverrides=array_key_exists('sourceOverrides',$input)?$input['sourceOverrides']:$base['sourceOverrides'];$overrides=function_exists('financial5300_source_overrides')?financial5300_source_overrides($rawOverrides):(is_array($rawOverrides)?$rawOverrides:[]);
    return [
        'collectionDelayDays'=>financial_int($input,'collectionDelayDays',(int)$base['collectionDelayDays'],0,365),
        'paymentDelayDays'=>financial_int($input,'paymentDelayDays',(int)$base['paymentDelayDays'],0,365),
        'receivableRealizationBps'=>financial_int($input,'receivableRealizationBps',(int)$base['receivableRealizationBps'],0,10000),
        'payableRealizationBps'=>financial_int($input,'payableRealizationBps',(int)$base['payableRealizationBps'],0,10000),
        'incomeGrowthBps'=>financial_int($input,'incomeGrowthBps',(int)$base['incomeGrowthBps'],-9000,100000),
        'expenseGrowthBps'=>financial_int($input,'expenseGrowthBps',(int)$base['expenseGrowthBps'],-9000,100000),
        'minimumCashCents'=>financial_int($input,'minimumCashCents',(int)$base['minimumCashCents'],0,1000000000000000),
        'forecastMode'=>$mode,
        'conservativeReceiptRealizationBps'=>financial_int($input,'conservativeReceiptRealizationBps',(int)$base['conservativeReceiptRealizationBps'],0,10000),
        'receiptConfidenceThresholdBps'=>financial_int($input,'receiptConfidenceThresholdBps',(int)$base['receiptConfidenceThresholdBps'],0,10000),
        'receiptSafetyDelayDays'=>financial_int($input,'receiptSafetyDelayDays',(int)$base['receiptSafetyDelayDays'],0,90),
        'recurringChargeConfidenceThresholdBps'=>financial_int($input,'recurringChargeConfidenceThresholdBps',(int)$base['recurringChargeConfidenceThresholdBps'],6000,10000),
        'includeMediumConfidenceChargesConservative'=>$includeMedium,
        'sourceOverrides'=>$overrides,
    ];
}

function financial_date_range(string $start,string $end): array
{
    $start=safe_date($start,'Forecast start date');$end=safe_date($end,'Forecast end date');
    if($end<$start)fail('Forecast end date cannot precede the start date.',422,'financial_horizon_invalid');
    $days=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->diff(new DateTimeImmutable($end,new DateTimeZone('UTC')))->days+1;
    if($days<1||$days>FINANCIAL_FORECAST_MAX_DAYS)fail('A cash forecast can cover at most five years.',422,'financial_horizon_invalid');
    return [$start,$end,$days];
}

function financial_scale_cents(int $amount,int $basisPoints): int
{
    if($amount===0||$basisPoints===0)return 0;
    $negative=($amount<0)!==($basisPoints<0);$left=abs($amount);$right=abs($basisPoints);
    if($left>intdiv(PHP_INT_MAX,max(1,$right)))throw new RuntimeException('The planning amount is too large to calculate safely.');
    $scaled=intdiv($left*$right+5000,10000);
    return $negative?-$scaled:$scaled;
}

function financial_scenario_budget(array $company,?string $budgetId,string $start,string $end,bool $required): ?array
{
    if($budgetId===null||$budgetId===''){
        if($required)fail('Choose a current-company budget for this scenario.',422,'financial_budget_required');
        return null;
    }
    $stmt=db()->prepare('SELECT * FROM budgets WHERE id=? AND company_id=? LIMIT 1');$stmt->execute([$budgetId,(string)$company['id']]);$budget=$stmt->fetch();
    if(!$budget)fail('Choose an available current-company budget.',422,'financial_budget_unavailable');
    if((string)$budget['period_end']<$start||(string)$budget['period_start']>$end)fail('The selected budget does not overlap the scenario horizon.',422,'financial_budget_outside_horizon');
    return $budget;
}

function financial_adjustments(mixed $source,string $start,string $end): array
{
    if(!is_array($source))fail('Scenario adjustments must be a list.',422,'financial_adjustments_invalid');
    if(count($source)>200)fail('A scenario can contain at most 200 adjustments.',422,'financial_adjustments_invalid');
    $rows=[];
    foreach($source as $item){
        if(!is_array($item))fail('A scenario adjustment is invalid.',422,'financial_adjustments_invalid');
        $date=safe_date($item['date']??'','Adjustment date');if($date<$start||$date>$end)fail('Every adjustment date must fall inside the scenario horizon.',422,'financial_adjustment_outside_horizon');
        $direction=(string)($item['direction']??'');if(!in_array($direction,['inflow','outflow'],true))fail('Choose inflow or outflow for each adjustment.',422,'financial_adjustments_invalid');
        $activity=(string)($item['activity']??'operating');if(!in_array($activity,['operating','investing','financing'],true))fail('Choose a supported cash-flow activity.',422,'financial_adjustments_invalid');
        $amount=financial_int($item,'amountCents',0,1,1000000000000000);$probability=financial_int($item,'probabilityBps',10000,0,10000);
        $description=clean_text($item['description']??'','Adjustment description',500);
        $rows[]=['date'=>$date,'direction'=>$direction,'activity'=>$activity,'amountCents'=>$amount,'probabilityBps'=>$probability,'description'=>$description];
    }
    usort($rows,static fn(array $a,array $b):int=>[$a['date'],$a['direction'],$a['activity'],$a['description'],$a['amountCents'],$a['probabilityBps']]<=>[$b['date'],$b['direction'],$b['activity'],$b['description'],$b['amountCents'],$b['probabilityBps']]);
    return $rows;
}

function financial_scenario_hash_values(array $values,array $adjustments): string
{
    return financial_hash([
        'name'=>$values['name'],'description'=>$values['description'],'horizonStart'=>$values['horizonStart'],'horizonEnd'=>$values['horizonEnd'],
        'baseKind'=>$values['baseKind'],'sourceBudgetId'=>$values['sourceBudgetId'],'status'=>$values['status'],
        'assumptions'=>$values['assumptions'],'adjustments'=>$adjustments,
    ]);
}

function financial_scenario_row(array $company,string $id,bool $lock=false): array
{
    financial_schema_required();$sql='SELECT * FROM financial_scenarios WHERE id=? AND company_id=? LIMIT 1'.($lock?' FOR UPDATE':'');$stmt=db()->prepare($sql);$stmt->execute([$id,(string)$company['id']]);$row=$stmt->fetch();
    if(!$row)fail('That financial scenario is not available in this company.',404,'financial_scenario_not_found');
    return $row;
}

function financial_scenario_adjustment_rows(array $company,string $scenarioId): array
{
    $stmt=db()->prepare('SELECT * FROM financial_scenario_adjustments WHERE company_id=? AND scenario_id=? ORDER BY adjustment_date,direction,activity,description,id');$stmt->execute([(string)$company['id'],$scenarioId]);return $stmt->fetchAll();
}

function financial_scenario_public(array $company,array $row): array
{
    $assumptions=json_decode((string)$row['assumptions_json'],true);if(!is_array($assumptions))$assumptions=financial_default_assumptions();
    $adjustments=array_map(static fn(array $item):array=>[
        'id'=>(string)$item['id'],'date'=>(string)$item['adjustment_date'],'direction'=>(string)$item['direction'],'activity'=>(string)$item['activity'],
        'amountCents'=>(int)$item['amount_cents'],'probabilityBps'=>(int)$item['probability_bps'],'description'=>(string)$item['description'],
        'createdAt'=>(string)$item['created_at'],'updatedAt'=>(string)$item['updated_at'],
    ],financial_scenario_adjustment_rows($company,(string)$row['id']));
    return [
        'id'=>(string)$row['id'],'name'=>(string)$row['name'],'description'=>(string)$row['description'],
        'horizonStart'=>(string)$row['horizon_start'],'horizonEnd'=>(string)$row['horizon_end'],'baseKind'=>(string)$row['base_kind'],
        'sourceBudgetId'=>$row['source_budget_id']!==null?(string)$row['source_budget_id']:null,'status'=>(string)$row['status'],
        'assumptions'=>financial_assumptions($assumptions),'adjustments'=>$adjustments,'revision'=>(int)$row['revision'],
        'scenarioHash'=>(string)$row['scenario_hash'],'createdBy'=>(string)$row['created_by'],'updatedBy'=>(string)$row['updated_by'],
        'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at'],
    ];
}

function financial_scenarios_list(array $company,bool $includeArchived=false): array
{
    financial_schema_required();require_company_permission($company,'reports.view');$sql='SELECT * FROM financial_scenarios WHERE company_id=?'.($includeArchived?'':" AND status<>'archived'").' ORDER BY FIELD(status,\'active\',\'draft\',\'archived\'),updated_at DESC,id';$stmt=db()->prepare($sql);$stmt->execute([(string)$company['id']]);$rows=array_map(static fn(array $row):array=>financial_scenario_public($company,$row),$stmt->fetchAll());
    return ['scenarios'=>$rows,'count'=>count($rows),'assumptionDefaults'=>financial_default_assumptions(),'maxHorizonDays'=>FINANCIAL_FORECAST_MAX_DAYS,'accountingWrites'=>0,'providerAttempts'=>0];
}

function financial_scenario_write_access(array $company): void
{
    require_company_role($company,'owner','bookkeeper');
}

function financial_scenario_values(array $company,array $input,?array $current=null): array
{
    $name=clean_text($input['name']??($current['name']??''),'Scenario name',160);$description=optional_text($input['description']??($current['description']??null),1000)??'';
    $start=safe_date($input['horizonStart']??($current['horizon_start']??''),'Forecast start date');$end=safe_date($input['horizonEnd']??($current['horizon_end']??''),'Forecast end date');financial_date_range($start,$end);
    $base=(string)($input['baseKind']??($current['base_kind']??'open_items'));if(!in_array($base,FINANCIAL_SCENARIO_BASES,true))fail('Choose open items or budget as the scenario base.',422,'financial_scenario_base_invalid');
    $budgetId=optional_text($input['sourceBudgetId']??($current['source_budget_id']??null),64);financial_scenario_budget($company,$budgetId,$start,$end,$base==='budget');if($base==='open_items')$budgetId=null;
    $status=(string)($input['status']??($current['status']??'draft'));if(!in_array($status,FINANCIAL_SCENARIO_STATES,true))fail('Choose a supported scenario status.',422,'financial_scenario_status_invalid');
    $fallback=[];if($current){$decoded=json_decode((string)$current['assumptions_json'],true);if(is_array($decoded))$fallback=$decoded;}
    $assumptions=financial_assumptions($input['assumptions']??[],$fallback);
    if(function_exists('financial5300_validate_source_overrides'))financial5300_validate_source_overrides($company,$assumptions['sourceOverrides'],$start,$end);
    return ['name'=>$name,'description'=>$description,'horizonStart'=>$start,'horizonEnd'=>$end,'baseKind'=>$base,'sourceBudgetId'=>$budgetId,'status'=>$status,'assumptions'=>$assumptions];
}

function financial_insert_adjustments(array $user,array $company,string $scenarioId,array $adjustments): void
{
    $insert=db()->prepare('INSERT INTO financial_scenario_adjustments (id,company_id,scenario_id,adjustment_date,direction,amount_cents,activity,description,probability_bps,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach($adjustments as $item)$insert->execute([new_id('fadjustment'),(string)$company['id'],$scenarioId,$item['date'],$item['direction'],$item['amountCents'],$item['activity'],$item['description'],$item['probabilityBps'],(string)$user['id'],(string)$user['id']]);
}

function financial_scenario_create(array $user,array $company,array $input): array
{
    financial_schema_required();financial_scenario_write_access($company);$values=financial_scenario_values($company,$input);$adjustments=financial_adjustments($input['adjustments']??[],$values['horizonStart'],$values['horizonEnd']);$hash=financial_scenario_hash_values($values,$adjustments);$id=new_id('fscenario');$pdo=db();$pdo->beginTransaction();
    try{
        db()->prepare('INSERT INTO financial_scenarios (id,company_id,name,description,horizon_start,horizon_end,base_kind,source_budget_id,status,assumptions_json,revision,scenario_hash,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?,?)')->execute([$id,(string)$company['id'],$values['name'],$values['description'],$values['horizonStart'],$values['horizonEnd'],$values['baseKind'],$values['sourceBudgetId'],$values['status'],financial_json($values['assumptions']),$hash,(string)$user['id'],(string)$user['id']]);
        financial_insert_adjustments($user,$company,$id,$adjustments);audit_event($user,(string)$company['id'],'financial_scenario.created','financial_scenario',$id,['scenarioHash'=>$hash,'baseKind'=>$values['baseKind'],'sourceBudgetId'=>$values['sourceBudgetId'],'adjustmentCount'=>count($adjustments),'accountingWrites'=>0,'providerAttempts'=>0]);$pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    return ['scenario'=>financial_scenario_public($company,financial_scenario_row($company,$id)),'accountingWrites'=>0,'providerAttempts'=>0];
}

function financial_existing_adjustments_for_validation(array $company,string $scenarioId): array
{
    return array_map(static fn(array $item):array=>['date'=>(string)$item['adjustment_date'],'direction'=>(string)$item['direction'],'activity'=>(string)$item['activity'],'amountCents'=>(int)$item['amount_cents'],'probabilityBps'=>(int)$item['probability_bps'],'description'=>(string)$item['description']],financial_scenario_adjustment_rows($company,$scenarioId));
}

function financial_scenario_update(array $user,array $company,array $input): array
{
    financial_schema_required();financial_scenario_write_access($company);$id=clean_text($input['scenarioId']??'','Scenario',64);$expected=financial_int($input,'expectedRevision',0,1,2147483646);$pdo=db();$pdo->beginTransaction();
    try{
        $current=financial_scenario_row($company,$id,true);if((int)$current['revision']!==$expected)fail('The scenario changed after you opened it. Refresh before saving.',409,'financial_scenario_revision_changed');$values=financial_scenario_values($company,$input,$current);$replace=array_key_exists('adjustments',$input);$adjustments=$replace?financial_adjustments($input['adjustments'],$values['horizonStart'],$values['horizonEnd']):financial_existing_adjustments_for_validation($company,$id);
        foreach($adjustments as $adjustment)if($adjustment['date']<$values['horizonStart']||$adjustment['date']>$values['horizonEnd'])fail('Adjust the scenario dates or remove assumptions outside the new horizon.',422,'financial_adjustment_outside_horizon');
        $revision=$expected+1;$hash=financial_scenario_hash_values($values,$adjustments);$stmt=db()->prepare('UPDATE financial_scenarios SET name=?,description=?,horizon_start=?,horizon_end=?,base_kind=?,source_budget_id=?,status=?,assumptions_json=?,revision=?,scenario_hash=?,updated_by=? WHERE id=? AND company_id=? AND revision=?');$stmt->execute([$values['name'],$values['description'],$values['horizonStart'],$values['horizonEnd'],$values['baseKind'],$values['sourceBudgetId'],$values['status'],financial_json($values['assumptions']),$revision,$hash,(string)$user['id'],$id,(string)$company['id'],$expected]);if($stmt->rowCount()!==1)fail('The scenario changed before it could be saved.',409,'financial_scenario_revision_changed');
        if($replace){db()->prepare('DELETE FROM financial_scenario_adjustments WHERE scenario_id=? AND company_id=?')->execute([$id,(string)$company['id']]);financial_insert_adjustments($user,$company,$id,$adjustments);}
        audit_event($user,(string)$company['id'],'financial_scenario.updated','financial_scenario',$id,['revision'=>$revision,'scenarioHash'=>$hash,'status'=>$values['status'],'baseKind'=>$values['baseKind'],'sourceBudgetId'=>$values['sourceBudgetId'],'adjustmentCount'=>count($adjustments),'accountingWrites'=>0,'providerAttempts'=>0]);$pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    return ['scenario'=>financial_scenario_public($company,financial_scenario_row($company,$id)),'accountingWrites'=>0,'providerAttempts'=>0];
}

function financial_month_buckets(string $start,string $end): array
{
    $cursor=new DateTimeImmutable($start,new DateTimeZone('UTC'));$last=new DateTimeImmutable($end,new DateTimeZone('UTC'));$out=[];
    while($cursor<=$last){$monthEnd=$cursor->modify('last day of this month');if($monthEnd>$last)$monthEnd=$last;$out[]=['start'=>$cursor->format('Y-m-d'),'end'=>$monthEnd->format('Y-m-d'),'label'=>$cursor->format('M Y')];$cursor=$monthEnd->modify('+1 day');}
    return $out;
}

/** @return array<int,array{start:string,end:string,label:string}> */
function financial_time_buckets(string $start,string $end,string $unit): array
{
    if(!in_array($unit,['week','month','year'],true))fail('Choose weekly, monthly or yearly forecast buckets.',422,'financial_bucket_unit_invalid');
    $cursor=new DateTimeImmutable($start,new DateTimeZone('UTC'));$last=new DateTimeImmutable($end,new DateTimeZone('UTC'));$out=[];
    while($cursor<=$last){
        if($unit==='week'){$bucketEnd=$cursor->modify('+6 days');$label='Week of '.$cursor->format('M j, Y');}
        elseif($unit==='month'){$bucketEnd=$cursor->modify('last day of this month');$label=$cursor->format('M Y');}
        else{$bucketEnd=$cursor->setDate((int)$cursor->format('Y'),12,31);$label=$cursor->format('Y');}
        if($bucketEnd>$last)$bucketEnd=$last;
        $out[]=['start'=>$cursor->format('Y-m-d'),'end'=>$bucketEnd->format('Y-m-d'),'label'=>$label];
        $cursor=$bucketEnd->modify('+1 day');
        if(count($out)>270)throw new RuntimeException('The requested forecast creates too many display buckets.');
    }
    return $out;
}

function financial_overlap_days(string $rangeStart,string $rangeEnd,string $bucketStart,string $bucketEnd): int
{
    $start=max($rangeStart,$bucketStart);$end=min($rangeEnd,$bucketEnd);if($end<$start)return 0;return (new DateTimeImmutable($start,new DateTimeZone('UTC')))->diff(new DateTimeImmutable($end,new DateTimeZone('UTC')))->days+1;
}

function financial_prorated_cents(int $total,string $rangeStart,string $rangeEnd,string $bucketStart,string $bucketEnd): int
{
    $totalDays=financial_overlap_days($rangeStart,$rangeEnd,$rangeStart,$rangeEnd);$overlapStart=max($rangeStart,$bucketStart);$overlapEnd=min($rangeEnd,$bucketEnd);if($totalDays<1||$overlapEnd<$overlapStart)return 0;
    $base=new DateTimeImmutable($rangeStart,new DateTimeZone('UTC'));$before=max(0,$base->diff(new DateTimeImmutable($overlapStart,new DateTimeZone('UTC')))->days);$through=$before+$base->modify('+'.$before.' days')->diff(new DateTimeImmutable($overlapEnd,new DateTimeZone('UTC')))->days+1;
    $cumulative=static function(int $days)use($total,$totalDays):int{if($days<=0)return 0;if($days>=$totalDays)return $total;if(abs($total)>intdiv(PHP_INT_MAX,$days))throw new RuntimeException('The budget amount is too large to allocate safely.');$value=intdiv(abs($total)*$days+intdiv($totalDays,2),$totalDays);return $total<0?-$value:$value;};
    return $cumulative($through)-$cumulative($before);
}

function financial_opening_cash(array $company,string $start): array
{
    $stmt=db()->prepare("SELECT a.id,a.code,a.name,COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) balance_cents FROM accounts a JOIN (SELECT DISTINCT ledger_account_id FROM bank_accounts WHERE company_id=? AND active=1 AND account_type='bank') cash ON cash.ledger_account_id=a.id LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id WHERE a.company_id=? GROUP BY a.id,a.code,a.name ORDER BY a.code,a.id");$stmt->execute([$start,(string)$company['id'],(string)$company['id']]);$accounts=array_map(static fn(array $row):array=>['accountId'=>(string)$row['id'],'code'=>(string)$row['code'],'name'=>(string)$row['name'],'balanceCents'=>(int)$row['balance_cents']],$stmt->fetchAll());return ['totalCents'=>array_sum(array_column($accounts,'balanceCents')),'accounts'=>$accounts];
}

function financial_event(array &$events,string $date,string $direction,int $amount,string $sourceType,string $sourceId,string $activity='operating',string $label=''): void
{
    if($amount<=0)return;$events[]=['date'=>$date,'direction'=>$direction,'amountCents'=>$amount,'sourceType'=>$sourceType,'sourceId'=>$sourceId,'activity'=>$activity,'label'=>$label];
}

function financial_projected_date(string $date,int $delay,string $start): string
{
    $projected=(new DateTimeImmutable($date,new DateTimeZone('UTC')))->modify('+'.$delay.' days')->format('Y-m-d');return max($start,$projected);
}

function financial_open_item_events(array $company,string $start,string $end,array $assumptions): array
{
    $events=[];$companyId=(string)$company['id'];$stmt=db()->prepare("SELECT id,number,due_date,balance_cents FROM invoices WHERE company_id=? AND status='sent' AND balance_cents>0 ORDER BY due_date,id");$stmt->execute([$companyId]);$invoiceRows=$stmt->fetchAll();foreach($invoiceRows as $row){$date=financial_projected_date((string)$row['due_date'],(int)$assumptions['collectionDelayDays'],$start);if($date>$end)continue;$amount=financial_scale_cents((int)$row['balance_cents'],(int)$assumptions['receivableRealizationBps']);financial_event($events,$date,'inflow',$amount,'invoice',(string)$row['id'],'operating','Invoice '.(string)$row['number']);}
    $stmt=db()->prepare("SELECT id,number,due_date,balance_cents FROM bills WHERE company_id=? AND status='open' AND balance_cents>0 ORDER BY due_date,id");$stmt->execute([$companyId]);$billRows=$stmt->fetchAll();foreach($billRows as $row){$date=financial_projected_date((string)$row['due_date'],(int)$assumptions['paymentDelayDays'],$start);if($date>$end)continue;$amount=financial_scale_cents((int)$row['balance_cents'],(int)$assumptions['payableRealizationBps']);financial_event($events,$date,'outflow',$amount,'bill',(string)$row['id'],'operating','Bill '.(string)$row['number']);}
    return ['events'=>$events,'sourceCounts'=>['openInvoices'=>count($invoiceRows),'openBills'=>count($billRows)]];
}

function financial_budget_events(array $company,array $budget,string $start,string $end,array $assumptions,array $buckets): array
{
    $stmt=db()->prepare("SELECT bl.id,bl.planned_cents,a.id account_id,a.code,a.name,a.account_type FROM budget_lines bl JOIN accounts a ON a.id=bl.account_id AND a.company_id=? WHERE bl.budget_id=? AND a.account_type IN ('income','expense') ORDER BY a.code,bl.id");$stmt->execute([(string)$company['id'],(string)$budget['id']]);$lines=$stmt->fetchAll();$events=[];
    foreach($lines as $line)foreach($buckets as $bucket){$base=financial_prorated_cents((int)$line['planned_cents'],(string)$budget['period_start'],(string)$budget['period_end'],$bucket['start'],$bucket['end']);if($base<=0)continue;$growth=(string)$line['account_type']==='income'?(int)$assumptions['incomeGrowthBps']:(int)$assumptions['expenseGrowthBps'];$amount=financial_scale_cents($base,10000+$growth);financial_event($events,$bucket['end'],(string)$line['account_type']==='income'?'inflow':'outflow',$amount,'budget_line',(string)$line['id'],'operating',(string)$line['code'].' '.(string)$line['name']);}
    return ['events'=>$events,'sourceCounts'=>['budgetLines'=>count($lines)],'budget'=>['id'=>(string)$budget['id'],'name'=>(string)$budget['name'],'periodStart'=>(string)$budget['period_start'],'periodEnd'=>(string)$budget['period_end'],'status'=>(string)$budget['status']]];
}

function financial_cash_forecast(array $company,?string $scenarioId=null,array $options=[]): array
{
    financial_schema_required();require_company_permission($company,'reports.view');$scenario=null;$budget=null;$bucketUnit=(string)($options['bucketUnit']??'month');if(!in_array($bucketUnit,['week','month','year'],true))fail('Choose weekly, monthly or yearly forecast buckets.',422,'financial_bucket_unit_invalid');
    if($scenarioId!==null&&$scenarioId!==''){$row=financial_scenario_row($company,$scenarioId);$scenario=financial_scenario_public($company,$row);$start=$scenario['horizonStart'];$end=$scenario['horizonEnd'];$base=$scenario['baseKind'];$assumptions=$scenario['assumptions'];if($base==='budget')$budget=financial_scenario_budget($company,$scenario['sourceBudgetId'],$start,$end,true);}
    else{$start=isset($options['start'])?safe_date($options['start'],'Forecast start date'):canadian_today();$days=isset($options['horizonDays'])?max(1,min(FINANCIAL_FORECAST_MAX_DAYS,(int)$options['horizonDays'])):90;$end=isset($options['end'])?safe_date($options['end'],'Forecast end date'):(new DateTimeImmutable($start,new DateTimeZone('UTC')))->modify('+'.($days-1).' days')->format('Y-m-d');$base='open_items';$assumptions=financial_assumptions($options['assumptions']??[]);}
    [,,$horizonDays]=financial_date_range($start,$end);$buckets=financial_time_buckets($start,$end,$bucketUnit);$opening=financial_opening_cash($company,$start);$source=$base==='budget'?financial_budget_events($company,$budget,$start,$end,$assumptions,$buckets):financial_open_item_events($company,$start,$end,$assumptions);$events=$source['events'];
    if($scenario)foreach($scenario['adjustments'] as $adjustment){$amount=financial_scale_cents((int)$adjustment['amountCents'],(int)$adjustment['probabilityBps']);financial_event($events,(string)$adjustment['date'],(string)$adjustment['direction'],$amount,'scenario_adjustment',(string)$adjustment['id'],(string)$adjustment['activity'],(string)$adjustment['description']);}
    usort($events,static fn(array $a,array $b):int=>[$a['date'],$a['direction'],$a['sourceType'],$a['sourceId']]<=>[$b['date'],$b['direction'],$b['sourceType'],$b['sourceId']]);$points=[];$running=(int)$opening['totalCents'];$minimum=$running;$minimumDate=$start;$firstNegative=$running<0?$start:null;$totalInflows=0;$totalOutflows=0;$daily=[];
    foreach($events as $event){$sign=$event['direction']==='inflow'?1:-1;$daily[$event['date']]=($daily[$event['date']]??0)+$sign*(int)$event['amountCents'];}
    ksort($daily,SORT_STRING);foreach($daily as $date=>$net){$running+=$net;if($running<$minimum){$minimum=$running;$minimumDate=$date;}if($firstNegative===null&&$running<0)$firstNegative=$date;}
    $running=(int)$opening['totalCents'];foreach($buckets as $bucket){$bucketEvents=array_values(array_filter($events,static fn(array $event):bool=>$event['date']>=$bucket['start']&&$event['date']<=$bucket['end']));$inflows=array_sum(array_map(static fn(array $event):int=>$event['direction']==='inflow'?(int)$event['amountCents']:0,$bucketEvents));$outflows=array_sum(array_map(static fn(array $event):int=>$event['direction']==='outflow'?(int)$event['amountCents']:0,$bucketEvents));$openingCents=$running;$running=$running+$inflows-$outflows;$totalInflows+=$inflows;$totalOutflows+=$outflows;$breakdown=[];foreach($bucketEvents as $event){$key=$event['sourceType'].($event['direction']==='inflow'?'InflowsCents':'OutflowsCents');$breakdown[$key]=($breakdown[$key]??0)+(int)$event['amountCents'];}ksort($breakdown,SORT_STRING);$points[]=['label'=>$bucket['label'],'periodStart'=>$bucket['start'],'periodEnd'=>$bucket['end'],'openingCents'=>$openingCents,'inflowsCents'=>$inflows,'outflowsCents'=>$outflows,'netChangeCents'=>$inflows-$outflows,'closingCents'=>$running,'sourceBreakdown'=>$breakdown];}
    $sourceHash=financial_hash(['companyId'=>(string)$company['id'],'horizon'=>[$start,$end],'opening'=>$opening,'baseKind'=>$base,'budget'=>$source['budget']??null,'scenarioHash'=>$scenario['scenarioHash']??null,'assumptions'=>$assumptions,'events'=>$events]);$actualEnd=min($end,canadian_today());$actual=$actualEnd>=$start?advanced_cash_flow_data((string)$company['id'],$start,$actualEnd):null;
    return ['scenario'=>$scenario?['id'=>$scenario['id'],'name'=>$scenario['name'],'status'=>$scenario['status'],'revision'=>$scenario['revision'],'scenarioHash'=>$scenario['scenarioHash']]:null,'baseKind'=>$base,'sourceBudget'=>$source['budget']??null,'currency'=>(string)($company['currency']??'CAD'),'horizonStart'=>$start,'horizonEnd'=>$end,'horizonDays'=>$horizonDays,'bucketUnit'=>$bucketUnit,'bucketCount'=>count($buckets),'openingCashCents'=>(int)$opening['totalCents'],'openingCashAccounts'=>$opening['accounts'],'assumptions'=>$assumptions,'points'=>$points,'events'=>$events,'sourceCounts'=>$source['sourceCounts']??[],'totalInflowsCents'=>$totalInflows,'totalOutflowsCents'=>$totalOutflows,'netChangeCents'=>$totalInflows-$totalOutflows,'closingCashCents'=>$running,'minimumProjectedCashCents'=>$minimum,'minimumProjectedCashDate'=>$minimumDate,'firstNegativeCashDate'=>$firstNegative,'minimumCashThresholdCents'=>(int)$assumptions['minimumCashCents'],'belowMinimumCash'=>$minimum<(int)$assumptions['minimumCashCents'],'sourceRevisionHash'=>$sourceHash,'evidenceGeneratedAt'=>gmdate('c'),'actualCashFlow'=>$actual,'planningNotice'=>'This deterministic cash forecast is a planning estimate, not an accounting record. Posted books, current open items and saved assumptions remain the evidence authority.','accountingWrites'=>0,'providerAttempts'=>0];
}

function financial_budget_variance(array $company,?string $budgetId=null,array $options=[]): array
{
    require_company_permission($company,'reports.view');$companyId=(string)$company['id'];
    if($budgetId!==null&&$budgetId!==''){$stmt=db()->prepare('SELECT * FROM budgets WHERE id=? AND company_id=? LIMIT 1');$stmt->execute([$budgetId,$companyId]);}
    else{$today=canadian_today();$stmt=db()->prepare("SELECT * FROM budgets WHERE company_id=? ORDER BY (status='active' AND period_start<=? AND period_end>=?) DESC,(status='active') DESC,period_end DESC,created_at DESC LIMIT 1");$stmt->execute([$companyId,$today,$today]);}
    $budget=$stmt->fetch();if(!$budget)return ['budget'=>null,'lines'=>[],'summary'=>['plannedCents'=>0,'actualCents'=>0,'favorableVarianceCents'=>0,'adverseVarianceCents'=>0,'materialAdverseCount'=>0],'evidenceHash'=>financial_hash(['companyId'=>$companyId,'budget'=>null]),'accountingWrites'=>0,'providerAttempts'=>0];
    $lineStmt=db()->prepare('SELECT bl.*,a.code,a.name account_name,a.account_type,a.normal_balance FROM budget_lines bl JOIN accounts a ON a.id=bl.account_id AND a.company_id=? WHERE bl.budget_id=? ORDER BY a.code,bl.id');$lineStmt->execute([$companyId,(string)$budget['id']]);$lines=[];$plannedTotal=0;$actualTotal=0;$favorableTotal=0;$adverseTotal=0;$material=[];$materiality=max(0,(int)($options['materialityCents']??10000));$percentThreshold=max(0,min(10000,(int)($options['variancePercentBps']??1000)));
    foreach($lineStmt->fetchAll() as $line){$planned=(int)$line['planned_cents'];$actual=advanced_budget_actual($companyId,$budget,$line);$type=(string)$line['account_type'];$favorable=$type==='income'?$actual-$planned:($type==='expense'?$planned-$actual:0);$adverse=max(0,-$favorable);$varianceBps=$planned>0?(int)min(100000,round(abs($actual-$planned)*10000/$planned)):($actual!==0?10000:0);$isMaterial=$adverse>=$materiality&&$varianceBps>=$percentThreshold;$item=['id'=>(string)$line['id'],'accountId'=>(string)$line['account_id'],'accountCode'=>(string)$line['code'],'accountName'=>(string)$line['account_name'],'accountType'=>$type,'plannedCents'=>$planned,'actualCents'=>$actual,'rawVarianceCents'=>$actual-$planned,'favorableVarianceCents'=>$favorable,'adverseVarianceCents'=>$adverse,'absoluteVarianceBps'=>$varianceBps,'materialAdverse'=>$isMaterial];$lines[]=$item;$plannedTotal+=$planned;$actualTotal+=$actual;$favorableTotal+=max(0,$favorable);$adverseTotal+=$adverse;if($isMaterial)$material[]=$item;}
    $evidenceHash=financial_hash(['budget'=>['id'=>(string)$budget['id'],'periodStart'=>(string)$budget['period_start'],'periodEnd'=>(string)$budget['period_end'],'status'=>(string)$budget['status']],'lines'=>$lines,'rules'=>['materialityCents'=>$materiality,'variancePercentBps'=>$percentThreshold]]);
    return ['budget'=>['id'=>(string)$budget['id'],'name'=>(string)$budget['name'],'periodStart'=>(string)$budget['period_start'],'periodEnd'=>(string)$budget['period_end'],'status'=>(string)$budget['status']],'currency'=>(string)($company['currency']??'CAD'),'lines'=>$lines,'materialAdverseLines'=>$material,'summary'=>['plannedCents'=>$plannedTotal,'actualCents'=>$actualTotal,'favorableVarianceCents'=>$favorableTotal,'adverseVarianceCents'=>$adverseTotal,'materialAdverseCount'=>count($material),'materialityCents'=>$materiality,'variancePercentBps'=>$percentThreshold],'evidenceHash'=>$evidenceHash,'accountingWrites'=>0,'providerAttempts'=>0];
}

function financial_management_alert_summary(array $company): array
{
    if(!schema_table_exists('native_agent_findings'))return ['open'=>0,'critical'=>0,'warning'=>0];$stmt=db()->prepare("SELECT COUNT(*) open_count,SUM(severity='critical') critical_count,SUM(severity='warning') warning_count FROM native_agent_findings WHERE company_id=? AND agent_type='financial_analyst' AND state IN ('open','snoozed')");$stmt->execute([(string)$company['id']]);$row=$stmt->fetch()?:[];return ['open'=>(int)($row['open_count']??0),'critical'=>(int)($row['critical_count']??0),'warning'=>(int)($row['warning_count']??0)];
}

function financial_overview(array $user,array $company,array $filters=[]): array
{
    require_company_permission($company,'reports.view');$scenarioId=optional_text($filters['scenarioId']??null,64);$budgetId=optional_text($filters['budgetId']??null,64);$baseline=financial_cash_forecast($company,null,['horizonDays'=>90]);$selected=$scenarioId!==null?financial_cash_forecast($company,$scenarioId):null;$variance=financial_budget_variance($company,$budgetId);return ['baselineForecast'=>$baseline,'selectedForecast'=>$selected,'budgetVariance'=>$variance,'scenarios'=>financial_scenarios_list($company,!empty($filters['includeArchived'])),'managementAlerts'=>financial_management_alert_summary($company),'nativeOnly'=>true,'providerAttempts'=>0,'accountingWrites'=>0,'accountingAuthority'=>'posted Tegh books, current open items and existing budgets'];
}

function native_agent_financial_analyst_collect(array $company,array $policy,string $sourceRevision): array
{
    $days=max(30,min(FINANCIAL_FORECAST_MAX_DAYS,(int)($policy['thresholds']['cashForecastDays']??90)));$minimum=max(0,(int)($policy['thresholds']['minimumCashCents']??0));$variancePercent=max(0,min(10000,(int)($policy['thresholds']['budgetVariancePercentBps']??1000)));$forecast=financial_cash_forecast($company,null,['horizonDays'=>$days,'assumptions'=>['minimumCashCents'=>$minimum]]);$variance=financial_budget_variance($company,null,['materialityCents'=>(int)$policy['materialityCents'],'variancePercentBps'=>$variancePercent]);$findings=[];$records=array_map(static fn(array $row):array=>['type'=>'account','id'=>(string)$row['accountId']],$forecast['openingCashAccounts']);
    $forecastEvidence=['records'=>$records,'affectedCount'=>count($forecast['events']),'horizon'=>['start'=>$forecast['horizonStart'],'end'=>$forecast['horizonEnd']],'openingCashCents'=>$forecast['openingCashCents'],'closingCashCents'=>$forecast['closingCashCents'],'minimumProjectedCashCents'=>$forecast['minimumProjectedCashCents'],'minimumProjectedCashDate'=>$forecast['minimumProjectedCashDate'],'firstNegativeCashDate'=>$forecast['firstNegativeCashDate'],'minimumCashThresholdCents'=>$minimum,'totalInflowsCents'=>$forecast['totalInflowsCents'],'totalOutflowsCents'=>$forecast['totalOutflowsCents'],'sourceRevisionHash'=>$forecast['sourceRevisionHash'],'planningNotice'=>$forecast['planningNotice'],'accountingWrites'=>0,'providerAttempts'=>0];
    if((int)$forecast['minimumProjectedCashCents']<0)$findings[]=native_agent_finding_spec('financial_analyst','projected_negative_cash','baseline_liquidity','critical',10000,'Cash forecast projects a negative balance','The deterministic baseline forecast falls below zero. Review expected receipts, payments and explicit scenario assumptions before taking action.',$forecastEvidence,'nav.financial_analyst',[],$forecast['minimumProjectedCashDate'].' 23:59:59');
    elseif((int)$forecast['minimumProjectedCashCents']<$minimum)$findings[]=native_agent_finding_spec('financial_analyst','projected_cash_below_minimum','baseline_liquidity','warning',10000,'Cash forecast falls below the company minimum','The deterministic baseline forecast remains non-negative but falls below the configured management threshold.',$forecastEvidence,'nav.financial_analyst',[],$forecast['minimumProjectedCashDate'].' 23:59:59');
    $material=$variance['materialAdverseLines']??[];if($material){$budget=$variance['budget'];$budgetRecords=array_map(static fn(array $line):array=>['type'=>'account','id'=>(string)$line['accountId']],$material);$evidence=['records'=>$budgetRecords,'affectedCount'=>count($material),'budget'=>$budget,'summary'=>$variance['summary'],'materialAdverseLines'=>array_slice($material,0,50),'evidenceHash'=>$variance['evidenceHash'],'rule'=>['minimumAdverseCents'=>(int)$policy['materialityCents'],'minimumAbsoluteVarianceBps'=>$variancePercent],'accountingWrites'=>0,'providerAttempts'=>0];$findings[]=native_agent_finding_spec('financial_analyst','material_adverse_budget_variance',(string)$budget['id'],'warning',10000,'Budget has material adverse variances','Posted actual results contain one or more adverse income or expense variances above both company management thresholds.',$evidence,'nav.financial_analyst',[],(string)$budget['periodEnd'].' 23:59:59');}
    return ['sourceRevisionHash'=>$sourceRevision,'findings'=>$findings,'processedCount'=>count($forecast['events'])+count($variance['lines']??[]),'cursor'=>['current'=>'','next'=>'','cycleComplete'=>true],'summary'=>['forecast'=>['horizonStart'=>$forecast['horizonStart'],'horizonEnd'=>$forecast['horizonEnd'],'openingCashCents'=>$forecast['openingCashCents'],'closingCashCents'=>$forecast['closingCashCents'],'minimumProjectedCashCents'=>$forecast['minimumProjectedCashCents'],'firstNegativeCashDate'=>$forecast['firstNegativeCashDate']],'budgetId'=>$variance['budget']['id']??null,'materialAdverseCount'=>count($material),'managementAlertCount'=>count($findings),'accountingWrites'=>0,'providerAttempts'=>0]];
}

require_once __DIR__.'/financial_forecast_v5300.php';

function handle_financial_analysis(string $action): never
{
    $user=require_user();$company=require_company($user);$action=trim($action,'/');
    if($action===''||$action==='overview'){require_method('GET');json_response(financial_overview($user,$company,$_GET));}
    if($action==='forecast'){require_method('GET');$scenarioId=optional_text($_GET['scenarioId']??null,64);$options=[];if(isset($_GET['start']))$options['start']=$_GET['start'];if(isset($_GET['end']))$options['end']=$_GET['end'];if(isset($_GET['horizonDays']))$options['horizonDays']=financial_int($_GET,'horizonDays',90,1,FINANCIAL_FORECAST_MAX_DAYS);if(isset($_GET['bucketUnit']))$options['bucketUnit']=(string)$_GET['bucketUnit'];json_response(['forecast'=>financial_cash_forecast($company,$scenarioId,$options)]);}
    if($action==='four-week'){require_method('GET');$scenarioId=optional_text($_GET['scenarioId']??null,64);$options=[];foreach(['conservativeReceiptRealizationBps','receiptConfidenceThresholdBps','receiptSafetyDelayDays','minimumCashCents'] as $field)if(isset($_GET[$field]))$options[$field]=$_GET[$field];json_response(['forecast'=>financial5300_four_week($company,(string)($_GET['asOf']??canadian_today()),$scenarioId,$options)]);}
    if($action==='four-week-preview'){require_method('POST');require_csrf();$input=request_json();$scenarioId=optional_text($input['scenarioId']??null,64);$assumptions=$input['assumptions']??[];if(!is_array($assumptions))fail('Forecast assumptions must be an object.',422,'financial_assumption_invalid');json_response(['forecast'=>financial5300_four_week($company,(string)($input['asOf']??canadian_today()),$scenarioId,$assumptions)]);}
    if($action==='dashboard'){require_method('GET');json_response(['dashboard'=>financial5300_dashboard($company,(string)($_GET['asOf']??canadian_today()))]);}
    if($action==='budget-variance'){require_method('GET');json_response(['budgetVariance'=>financial_budget_variance($company,optional_text($_GET['budgetId']??null,64))]);}
    if($action==='scenarios'){
        if(request_method()==='GET')json_response(financial_scenarios_list($company,!empty($_GET['includeArchived'])));
        require_method('POST');require_csrf();json_response(financial_scenario_create($user,$company,request_json()),201);
    }
    if($action==='scenario'){
        if(request_method()==='GET'){require_company_permission($company,'reports.view');$id=clean_text($_GET['scenarioId']??'','Scenario',64);json_response(['scenario'=>financial_scenario_public($company,financial_scenario_row($company,$id)),'accountingWrites'=>0,'providerAttempts'=>0]);}
        require_method('PUT');require_csrf();json_response(financial_scenario_update($user,$company,request_json()));
    }
    fail('Financial Analyst route not found.',404,'route_not_found');
}
