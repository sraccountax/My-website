<?php
declare(strict_types=1);

const TEGH_REPORT_PERIOD_MAX_DAYS = 1827;

function tegh_report_date(string $value,string $label): DateTimeImmutable
{
    $safe=safe_date($value,$label);return new DateTimeImmutable($safe,new DateTimeZone('UTC'));
}

function tegh_report_add_months_clamped(DateTimeImmutable $date,int $months): DateTimeImmutable
{
    $year=(int)$date->format('Y');$month=(int)$date->format('n');$day=(int)$date->format('j');$index=$year*12+($month-1)+$months;$targetYear=(int)floor($index/12);$targetMonth=$index-$targetYear*12+1;$last=(int)(new DateTimeImmutable(sprintf('%04d-%02d-01',$targetYear,$targetMonth),new DateTimeZone('UTC')))->modify('last day of this month')->format('j');return $date->setDate($targetYear,$targetMonth,min($day,$last));
}

function tegh_report_period_days(string $start,string $end): int
{
    $left=tegh_report_date($start,'From date');$right=tegh_report_date($end,'To date');if($right<$left)fail('The From date must be on or before the To date.',422,'report_period_invalid');$days=$left->diff($right)->days+1;if($days>TEGH_REPORT_PERIOD_MAX_DAYS)fail('The selected period cannot exceed five years.',422,'report_period_too_long');return $days;
}

function tegh_report_period_preset(array $company,string $preset,string $today): array
{
    $now=tegh_report_date($today,'Report date');$monthStart=$now->modify('first day of this month');
    if($preset==='current_month')return [$monthStart->format('Y-m-d'),$today];
    if($preset==='previous_month'){$start=$monthStart->modify('-1 month');return [$start->format('Y-m-d'),$start->modify('last day of this month')->format('Y-m-d')];}
    if($preset==='current_quarter'){$month=(int)$now->format('n');$quarterMonth=(int)(floor(($month-1)/3)*3+1);return [$now->setDate((int)$now->format('Y'),$quarterMonth,1)->format('Y-m-d'),$today];}
    if($preset==='previous_year'){$year=(int)$now->format('Y')-1;return [sprintf('%04d-01-01',$year),sprintf('%04d-12-31',$year)];}
    if($preset==='current_year')return [$now->format('Y-01-01'),$today];
    $fy=(string)($company['fiscal_year_end']??'12-31');if(!preg_match('/^(\d{2})-(\d{2})$/',$fy,$parts))$parts=[null,'12','31'];$candidate=new DateTimeImmutable($now->format('Y').'-'.$parts[1].'-'.$parts[2],new DateTimeZone('UTC'));$end=$candidate>=$now?$candidate:$candidate->modify('+1 year');$start=$end->modify('-1 year')->modify('+1 day');return [$start->format('Y-m-d'),min($today,$end->format('Y-m-d'))];
}

/** @return array{mode:string,count:?int,unit:?string,start:string,end:string,asOf:?string,preset:?string,direction:string,inclusive:bool,days:int} */
function tegh_report_period_resolve(array $company,array $input,string $reportKey): array
{
    $snapshot=in_array($reportKey,['balance_sheet','receivable_aging','payable_aging'],true);$forecast=$reportKey==='cash_forecast';$mode=strtolower(trim((string)($input['mode']??($snapshot?'as_of':($forecast?'trailing':'preset')))));$today=canadian_today();$count=null;$unit=null;$asOf=null;$preset=null;
    if($snapshot){$mode='as_of';$asOf=safe_date($input['asOf']??$input['end']??$today,'As-of date');$start=$asOf;$end=$asOf;}
    elseif($mode==='custom'){$start=safe_date($input['start']??'','From date');$end=safe_date($input['end']??'','To date');}
    elseif($mode==='preset'){$preset=(string)($input['preset']??'fiscal_current');if(!in_array($preset,['current_month','previous_month','current_quarter','current_year','previous_year','fiscal_current'],true))fail('Choose a supported report preset.',422,'report_period_preset_invalid');[$start,$end]=tegh_report_period_preset($company,$preset,$today);}
    elseif($mode==='trailing'){$count=filter_var($input['count']??($forecast?13:12),FILTER_VALIDATE_INT);$unit=strtolower(trim((string)($input['unit']??($forecast?'week':'month'))));if($count===false||$count<1||$count>260||!in_array($unit,['week','month','year'],true))fail('Choose a valid whole-number period in weeks, months or years.',422,'report_period_trailing_invalid');
        if($forecast){$start=safe_date($input['start']??$today,'Forecast start date');$left=tegh_report_date($start,'Forecast start date');$exclusive=$unit==='week'?$left->modify('+'.($count*7).' days'):tegh_report_add_months_clamped($left,$count*($unit==='year'?12:1));$end=$exclusive->modify('-1 day')->format('Y-m-d');}
        else{$end=safe_date($input['end']??$today,'To date');$right=tegh_report_date($end,'To date');$exclusive=$unit==='week'?$right->modify('-'.($count*7).' days'):tegh_report_add_months_clamped($right,-$count*($unit==='year'?12:1));$start=$exclusive->modify('+1 day')->format('Y-m-d');}
    }else fail('Choose preset, trailing or custom dates.',422,'report_period_mode_invalid');
    $days=tegh_report_period_days($start,$end);return ['mode'=>$mode,'count'=>$count,'unit'=>$unit,'start'=>$start,'end'=>$end,'asOf'=>$asOf,'preset'=>$preset,'direction'=>$forecast?'forward':'historical','inclusive'=>true,'days'=>$days];
}

function tegh_report_period_access(array $user,array $company): void
{
    require_company_permission($company,'reports.view');tegh_require_feature($user,$company,'core.accounting');
}

function handle_report_periods_v5600(string $action): never
{
    $user=require_user();$company=require_company($user);tegh_report_period_access($user,$company);$action=trim($action,'/');$reportKey=trim((string)($_GET['reportKey']??'profit_loss'));if(!preg_match('/^[a-z][a-z0-9_]{1,63}$/',$reportKey))fail('Report key is invalid.',422,'report_key_invalid');$period=tegh_report_period_resolve($company,$_GET,$reportKey);
    if($action===''||$action==='resolve'){require_method('GET');json_response(['period'=>$period,'reportKey'=>$reportKey,'contractVersion'=>1,'accountingWrites'=>0]);}
    if($action==='financial'){require_method('GET');$kind=(string)($_GET['kind']??'profit-loss');$data=portal_financial_report_data($company,$kind,$period['start'],$period['end']);if($kind==='balance-sheet'){$data['asOf']=$period['asOf']??$period['end'];unset($data['start']);}$data['period']=$period;$data['accountingWrites']=0;json_response($data);}
    if($action==='cash-flow'){require_method('GET');$data=advanced_cash_flow_data((string)$company['id'],$period['start'],$period['end']);$data['period']=$period;$data['accountingWrites']=0;json_response(['cashFlow'=>$data]);}
    if($action==='forecast'){require_method('GET');$bucket=(string)($_GET['bucketUnit']??($period['unit']??'month'));$forecast=financial_cash_forecast($company,optional_text($_GET['scenarioId']??null,64),['start'=>$period['start'],'end'=>$period['end'],'bucketUnit'=>$bucket]);$forecast['period']=$period;json_response(['forecast'=>$forecast]);}
    fail('Report-period route not found.',404,'route_not_found');
}

