<?php
declare(strict_types=1);

/**
 * Tegh 5.3.0 four-week cash forecast.
 *
 * This is an additive policy mode over the existing Financial Analyst. It
 * reads one current-company, repeatable-read snapshot and never writes an
 * accounting record or invokes a provider.
 */

const FINANCIAL5300_POLICY_VERSION = 'four-week-v1';
const FINANCIAL5300_HORIZON_DAYS = 28;
const FINANCIAL5300_CHARGE_HISTORY_DAYS = 366;
const FINANCIAL5300_CHARGE_CONFIDENCE_BPS = 7500;
const FINANCIAL5300_DISCLAIMER = 'This forecast is a management planning estimate, not an accounting record. It does not post entries, collect money or schedule payments.';

function financial5300_date_add(string $date,int $days): string
{
    return (new DateTimeImmutable($date,new DateTimeZone('UTC')))->modify(($days>=0?'+':'').$days.' days')->format('Y-m-d');
}

function financial5300_days_between(string $from,string $to): int
{
    $a=new DateTimeImmutable($from,new DateTimeZone('UTC'));$b=new DateTimeImmutable($to,new DateTimeZone('UTC'));
    return (int)$a->diff($b)->format('%r%a');
}

function financial5300_is_weekend(string $date): bool
{
    return (int)(new DateTimeImmutable($date,new DateTimeZone('UTC')))->format('N')>=6;
}

function financial5300_next_business_day(string $date): string
{
    while(financial5300_is_weekend($date))$date=financial5300_date_add($date,1);
    return $date;
}

function financial5300_previous_business_day(string $date): string
{
    while(financial5300_is_weekend($date))$date=financial5300_date_add($date,-1);
    return $date;
}

function financial5300_receipt_date(string $date,string $firstDay): string
{
    $date=max($date,$firstDay);return financial5300_next_business_day($date);
}

function financial5300_payable_date(string $date,string $firstDay): string
{
    if($date<$firstDay)return $firstDay;
    $date=financial5300_previous_business_day($date);return $date<$firstDay?$firstDay:$date;
}

function financial5300_median(array $values): int
{
    if(!$values)return 0;$values=array_values(array_map('intval',$values));sort($values,SORT_NUMERIC);$count=count($values);$middle=intdiv($count,2);
    if($count%2===1)return $values[$middle];
    $sum=$values[$middle-1]+$values[$middle];return $sum>=0?intdiv($sum+1,2):-intdiv(abs($sum)+1,2);
}

function financial5300_mad(array $values,int $median): int
{
    return financial5300_median(array_map(static fn($value):int=>abs((int)$value-$median),$values));
}

function financial5300_weeks(string $asOf): array
{
    $weeks=[];
    for($week=1;$week<=4;$week++){
        $start=financial5300_date_add($asOf,(($week-1)*7)+1);$end=financial5300_date_add($asOf,$week*7);
        $weeks[]=['week'=>$week,'label'=>'Week '.$week,'periodStart'=>$start,'periodEnd'=>$end];
    }
    return $weeks;
}

function financial5300_with_snapshot(callable $callback): mixed
{
    $pdo=db();
    if($pdo->inTransaction())return $callback();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    try{$result=$callback();$pdo->commit();return $result;}
    catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function financial5300_as_of(mixed $value): string
{
    $today=canadian_today();$asOf=safe_date($value??$today,'As-of date');
    if($asOf>$today)fail('The cash forecast as-of date cannot be in the future.',422,'financial_forecast_future_date');
    if($asOf<$today)fail('Tegh cannot reconstruct every invoice, bill and payment status exactly as it existed on that past date. Choose today so the forecast does not mix current open balances with historical cash.',422,'financial_historical_snapshot_unavailable');
    return $asOf;
}

function financial5300_source_overrides(mixed $source): array
{
    if($source===null)return [];
    if(!is_array($source)||!array_is_list($source))fail('Forecast source overrides must be a list.',422,'financial_overrides_invalid');
    if(count($source)>250)fail('A scenario can contain at most 250 source overrides.',422,'financial_override_limit');
    $allowed=['sourceType','sourceId','includeExpected','includeConservative','expectedDate','conservativeDate','expectedAmountCents','conservativeAmountCents','sourceRevisionHash','reason'];$out=[];$seen=[];
    foreach($source as $raw){
        if(!is_array($raw)||array_diff(array_keys($raw),$allowed))fail('A forecast source override contains unsupported fields.',422,'financial_overrides_invalid');
        $type=(string)($raw['sourceType']??'');if(!in_array($type,['invoice','bill'],true))fail('A forecast override must identify an invoice or vendor invoice.',422,'financial_overrides_invalid');
        $id=clean_text($raw['sourceId']??'','Forecast source',64);$key=$type.':'.$id;if(isset($seen[$key]))fail('A forecast source can be overridden only once.',422,'financial_override_duplicate');$seen[$key]=true;
        $item=['sourceType'=>$type,'sourceId'=>$id,'reason'=>clean_text($raw['reason']??'User planning override','Override reason',250)];
        foreach(['includeExpected','includeConservative'] as $field)if(array_key_exists($field,$raw)){if(!is_bool($raw[$field]))fail('Forecast include choices must be true or false.',422,'financial_overrides_invalid');$item[$field]=$raw[$field];}
        foreach(['expectedDate','conservativeDate'] as $field)if(isset($raw[$field])&&trim((string)$raw[$field])!=='')$item[$field]=safe_date($raw[$field],'Override date');
        foreach(['expectedAmountCents','conservativeAmountCents'] as $field)if(array_key_exists($field,$raw))$item[$field]=financial_int($raw,$field,0,0,1000000000000000);
        if(isset($raw['sourceRevisionHash'])&&trim((string)$raw['sourceRevisionHash'])!==''){$hash=strtolower(trim((string)$raw['sourceRevisionHash']));if(!preg_match('/^[a-f0-9]{64}$/',$hash))fail('The override source revision is invalid.',422,'financial_overrides_invalid');$item['sourceRevisionHash']=$hash;}
        $out[]=$item;
    }
    usort($out,static fn(array $a,array $b):int=>[$a['sourceType'],$a['sourceId']]<=>[$b['sourceType'],$b['sourceId']]);return $out;
}

function financial5300_override_map(array $assumptions): array
{
    $map=[];foreach(financial5300_source_overrides($assumptions['sourceOverrides']??[]) as $item)$map[$item['sourceType'].':'.$item['sourceId']]=$item;return $map;
}

function financial5300_validate_source_overrides(array $company,array $overrides,string $start,string $end): void
{
    if(!$overrides)return;$companyId=(string)$company['id'];$invoice=db()->prepare("SELECT balance_cents,updated_at,status FROM invoices WHERE id=? AND company_id=? LIMIT 1");$bill=db()->prepare("SELECT balance_cents,updated_at,status FROM bills WHERE id=? AND company_id=? LIMIT 1");
    foreach($overrides as $item){
        $stmt=$item['sourceType']==='invoice'?$invoice:$bill;$stmt->execute([$item['sourceId'],$companyId]);$row=$stmt->fetch();$valid=$row&&$row['balance_cents']>0&&in_array((string)$row['status'],$item['sourceType']==='invoice'?['sent']:['open'],true);
        if(!$valid)fail('A forecast override points to a source that is not currently open in this company.',409,'financial_override_source_stale');
        foreach(['expectedDate','conservativeDate'] as $field)if(isset($item[$field])&&($item[$field]<$start||$item[$field]>$end))fail('Every source override date must fall inside the scenario horizon.',422,'financial_override_outside_horizon');
        foreach(['expectedAmountCents','conservativeAmountCents'] as $field)if(isset($item[$field])&&(int)$item[$field]>(int)$row['balance_cents'])fail('A forecast override amount cannot exceed the authoritative open balance.',422,'financial_override_amount_exceeded');
    }
}

function financial5300_opening_cash(array $company,string $asOf): array
{
    $duplicate=db()->prepare("SELECT ledger_account_id,COUNT(*) mapped_accounts FROM bank_accounts WHERE company_id=? AND active=1 AND account_type='bank' GROUP BY ledger_account_id HAVING COUNT(*)>1 LIMIT 1");
    $duplicate->execute([(string)$company['id']]);
    if($duplicate->fetch())fail('More than one active bank account is mapped to the same General Ledger account. Correct the bank-account setup so opening cash is not counted twice.',422,'financial_forecast_duplicate_bank_ledger');
    $stmt=db()->prepare("SELECT ba.id bank_account_id,ba.name bank_name,ba.currency bank_currency,a.id account_id,a.code,a.name,
        COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<=? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) balance_cents
        FROM bank_accounts ba JOIN accounts a ON a.id=ba.ledger_account_id AND a.company_id=ba.company_id AND a.active=1
        LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=ba.company_id
        WHERE ba.company_id=? AND ba.active=1 AND ba.account_type='bank'
        GROUP BY ba.id,ba.name,ba.currency,a.id,a.code,a.name ORDER BY a.code,ba.id");
    $stmt->execute([$asOf,(string)$company['id']]);$accounts=[];$total=0;
    foreach($stmt->fetchAll() as $row){$balance=(int)$row['balance_cents'];$total+=$balance;$accounts[]=['bankAccountId'=>(string)$row['bank_account_id'],'accountId'=>(string)$row['account_id'],'code'=>(string)$row['code'],'name'=>(string)$row['name'],'bankName'=>(string)$row['bank_name'],'currency'=>(string)$row['bank_currency'],'balanceCents'=>$balance,'overdrawn'=>$balance<0,'authority'=>'posted_general_ledger_through_as_of'];}
    return ['totalCents'=>$total,'accounts'=>$accounts,'sourceRevisionHash'=>financial_hash(['asOf'=>$asOf,'accounts'=>$accounts])];
}

function financial5300_payment_evidence(string $companyId,string $type,string $asOf): array
{
    $stmt=db()->prepare("SELECT document_id,SUM(applied_cents) applied_cents,MAX(payment_date) last_payment_date,COUNT(*) payment_count,
        GROUP_CONCAT(id ORDER BY payment_date,id SEPARATOR ',') payment_ids
        FROM party_payments WHERE company_id=? AND payment_type=? AND status='posted' AND document_id IS NOT NULL AND payment_date<=? GROUP BY document_id");
    $stmt->execute([$companyId,$type,$asOf]);$out=[];
    foreach($stmt->fetchAll() as $row)$out[(string)$row['document_id']]=['appliedCents'=>(int)$row['applied_cents'],'lastPaymentDate'=>$row['last_payment_date']!==null?(string)$row['last_payment_date']:null,'paymentCount'=>(int)$row['payment_count'],'paymentIds'=>$row['payment_ids']!==null?explode(',',(string)$row['payment_ids']):[]];
    return $out;
}

function financial5300_customer_history(string $companyId,string $asOf): array
{
    $from=financial5300_date_add($asOf,-365);
    $stmt=db()->prepare("SELECT i.customer_id,i.id invoice_id,i.due_date,MAX(p.payment_date) settled_date,i.total_cents,
        SUM(CASE WHEN p.status='posted' THEN p.applied_cents ELSE 0 END) applied_cents,
        SUM(CASE WHEN p.status='reversed' THEN 1 ELSE 0 END) reversed_count
        FROM invoices i JOIN party_payments p ON p.document_id=i.id AND p.company_id=i.company_id AND p.payment_type='customer' AND p.payment_date<=?
        JOIN journal_entries je ON je.id=i.issued_journal_entry_id AND je.company_id=i.company_id AND je.status='posted' AND je.entry_date<=?
        WHERE i.company_id=? AND i.status='paid' AND i.balance_cents=0 AND i.issue_date<=?
        GROUP BY i.customer_id,i.id,i.due_date,i.total_cents
        HAVING applied_cents=i.total_cents AND reversed_count=0 AND settled_date>=? ORDER BY settled_date DESC,i.id DESC");
    $stmt->execute([$asOf,$asOf,$companyId,$asOf,$from]);$grouped=[];
    foreach($stmt->fetchAll() as $row){$id=(string)$row['customer_id'];if(count($grouped[$id]??[])>=24)continue;$delay=max(-90,min(365,financial5300_days_between((string)$row['due_date'],(string)$row['settled_date'])));$grouped[$id][]=['invoiceId'=>(string)$row['invoice_id'],'dueDate'=>(string)$row['due_date'],'settledDate'=>(string)$row['settled_date'],'delayDays'=>$delay,'totalCents'=>(int)$row['total_cents']];}
    $profiles=[];
    foreach($grouped as $customerId=>$rows){$delays=array_column($rows,'delayDays');$n=count($delays);if($n<3)continue;$median=financial5300_median($delays);$mad=financial5300_mad($delays,$median);$recency=financial5300_days_between((string)$rows[0]['settledDate'],$asOf);$confidence=7800+min(1200,($n-3)*200)-min(1200,$mad*60)-min(600,max(0,$recency-60)*3);$profiles[$customerId]=['sampleSize'=>$n,'medianDelayDays'=>$median,'madDays'=>$mad,'windowStart'=>$from,'windowEnd'=>$asOf,'confidenceBps'=>max(0,min(10000,$confidence)),'evidence'=>array_slice($rows,0,24)];}
    return $profiles;
}

function financial5300_fx_evidence(array $row,string $baseCurrency,string $sourceType): array
{
    $currency=(string)$row['currency'];$rate=(int)$row['exchange_rate_micros'];$foreign=(int)$row['foreign_balance_cents'];
    if($rate<=0||($currency!==$baseCurrency&&$foreign<=0))fail('A '.$sourceType.' has incomplete booked exchange-rate evidence. Correct that document before relying on the cash forecast.',422,'financial_forecast_fx_evidence_missing');
    return ['sourceCurrency'=>$currency,'sourceAmountCents'=>$currency===$baseCurrency?(int)$row['balance_cents']:$foreign,'baseCurrency'=>$baseCurrency,'baseAmountCents'=>(int)$row['balance_cents'],'exchangeRateMicros'=>$rate,'rateBasis'=>'booked_document_rate'];
}

function financial5300_apply_override(array $event,?array $override): array
{
    if(!$override)return $event;
    if(array_key_exists('includeExpected',$override))$event['expected']['included']=(bool)$override['includeExpected'];
    if(array_key_exists('includeConservative',$override))$event['conservative']['included']=(bool)$override['includeConservative'];
    if(isset($override['expectedDate'])){$event['expected']['projectedDate']=$override['expectedDate'];$event['expected']['dateBasis']='scenario_override';$event['expected']['explanation']='Explicit scenario date: '.$override['reason'];}
    if(isset($override['conservativeDate'])){$event['conservative']['projectedDate']=$override['conservativeDate'];$event['conservative']['dateBasis']='scenario_override';$event['conservative']['explanation']='Explicit scenario date: '.$override['reason'];}
    if(isset($override['expectedAmountCents']))$event['expected']['amountCents']=(int)$override['expectedAmountCents'];
    if(isset($override['conservativeAmountCents']))$event['conservative']['amountCents']=(int)$override['conservativeAmountCents'];
    $event['overrideReason']=$override['reason'];$event['overrideApplied']=true;
    if(isset($override['sourceRevisionHash'])&&!hash_equals($override['sourceRevisionHash'],$event['sourceRevisionHash']))fail('A forecast source changed after the scenario override was saved. Refresh current books before relying on the scenario.',409,'financial_override_source_stale');
    return $event;
}

function financial5300_invoice_events(array $company,string $asOf,string $firstDay,string $end,array $assumptions,array $overrides): array
{
    $companyId=(string)$company['id'];$base=(string)$company['currency'];$payments=financial5300_payment_evidence($companyId,'customer',$asOf);$history=financial5300_customer_history($companyId,$asOf);$events=[];$excluded=[];
    $stmt=db()->prepare("SELECT i.*,c.name party_name,je.entry_date posted_date,je.status journal_status FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id JOIN journal_entries je ON je.id=i.issued_journal_entry_id AND je.company_id=i.company_id WHERE i.company_id=? AND i.issue_date<=? AND i.status='sent' AND i.balance_cents>0 AND je.status='posted' AND je.entry_date<=? ORDER BY i.due_date,i.number,i.id");$stmt->execute([$companyId,$asOf,$asOf]);
    foreach($stmt->fetchAll() as $row){
        $profile=$history[(string)$row['customer_id']]??null;$confidence=$profile?(int)$profile['confidenceBps']:6500;$basis=$profile?'customer_settlement_history':'invoice_due_date';$delay=$profile?(int)$profile['medianDelayDays']:0;$expectedDate=financial5300_receipt_date(financial5300_date_add((string)$row['due_date'],$delay),$firstDay);$conservativeDelay=$profile?max($delay,$delay+(int)$profile['madDays']+(int)$assumptions['receiptSafetyDelayDays']):0;$conservativeDate=financial5300_receipt_date(financial5300_date_add((string)$row['due_date'],$conservativeDelay),$firstDay);$revision=financial_hash(['id'=>$row['id'],'status'=>$row['status'],'balance'=>(int)$row['balance_cents'],'updatedAt'=>$row['updated_at'],'payments'=>$payments[(string)$row['id']]??null]);$fx=financial5300_fx_evidence($row,$base,'customer invoice');$high=$confidence>=(int)$assumptions['receiptConfidenceThresholdBps'];
        $event=array_merge(['id'=>'invoice:'.(string)$row['id'],'companyId'=>$companyId,'sourceType'=>'invoice','sourceId'=>(string)$row['id'],'reference'=>(string)$row['number'],'party'=>(string)$row['party_name'],'direction'=>'inflow','documentDate'=>(string)$row['issue_date'],'dueDate'=>(string)$row['due_date'],'openBalanceCents'=>(int)$row['balance_cents'],'openingDocument'=>(bool)$row['is_opening_document'],'paymentEvidence'=>$payments[(string)$row['id']]??['appliedCents'=>0,'lastPaymentDate'=>null,'paymentCount'=>0,'paymentIds'=>[]],'history'=>$profile,'sourceRevisionHash'=>$revision,'overrideApplied'=>false],$fx,[
            'expected'=>['included'=>true,'projectedDate'=>$expectedDate,'amountCents'=>(int)$row['balance_cents'],'dateBasis'=>$basis,'confidenceBps'=>$confidence,'explanation'=>$profile?'Median customer settlement delay from '.$profile['sampleSize'].' reliable invoices.':'No reliable three-invoice settlement pattern; the posted due date is used.'],
            'conservative'=>['included'=>$high,'projectedDate'=>$conservativeDate,'amountCents'=>$high?financial_scale_cents((int)$row['balance_cents'],(int)$assumptions['conservativeReceiptRealizationBps']):0,'dateBasis'=>$profile?'history_median_plus_mad_and_safety':'excluded_low_confidence','confidenceBps'=>$confidence,'explanation'=>$high?'Later robust customer-history date at the selected receipt realization percentage.':'Below the conservative receipt-confidence threshold; shown as excluded evidence.'],
        ]);
        $event=financial5300_apply_override($event,$overrides['invoice:'.(string)$row['id']]??null);if(!$event['expected']['included'])$event['expected']['amountCents']=0;if(!$event['conservative']['included'])$event['conservative']['amountCents']=0;$events[]=$event;if(!$event['conservative']['included'])$excluded[]=['sourceType'=>'invoice','sourceId'=>(string)$row['id'],'reference'=>(string)$row['number'],'reason'=>'Receipt confidence '.$confidence.' bps is below the conservative threshold.'];
    }
    return ['events'=>$events,'excluded'=>$excluded,'sourceCount'=>count($events),'historyCustomerCount'=>count($history)];
}

function financial5300_bill_events(array $company,string $asOf,string $firstDay,string $end,array $assumptions,array $overrides): array
{
    $companyId=(string)$company['id'];$base=(string)$company['currency'];$payments=financial5300_payment_evidence($companyId,'vendor',$asOf);$events=[];
    $stmt=db()->prepare("SELECT b.*,v.name party_name,je.entry_date posted_date,je.status journal_status FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id JOIN journal_entries je ON je.id=b.issued_journal_entry_id AND je.company_id=b.company_id WHERE b.company_id=? AND b.bill_date<=? AND b.status='open' AND b.balance_cents>0 AND je.status='posted' AND je.entry_date<=? ORDER BY b.due_date,b.number,b.id");$stmt->execute([$companyId,$asOf,$asOf]);
    foreach($stmt->fetchAll() as $row){$date=financial5300_payable_date((string)$row['due_date'],$firstDay);$revision=financial_hash(['id'=>$row['id'],'status'=>$row['status'],'balance'=>(int)$row['balance_cents'],'updatedAt'=>$row['updated_at'],'payments'=>$payments[(string)$row['id']]??null]);$fx=financial5300_fx_evidence($row,$base,'vendor invoice');$event=array_merge(['id'=>'bill:'.(string)$row['id'],'companyId'=>$companyId,'sourceType'=>'bill','sourceId'=>(string)$row['id'],'reference'=>(string)$row['number'],'party'=>(string)$row['party_name'],'direction'=>'outflow','documentDate'=>(string)$row['bill_date'],'dueDate'=>(string)$row['due_date'],'openBalanceCents'=>(int)$row['balance_cents'],'openingDocument'=>(bool)$row['is_opening_document'],'paymentEvidence'=>$payments[(string)$row['id']]??['appliedCents'=>0,'lastPaymentDate'=>null,'paymentCount'=>0,'paymentIds'=>[]],'history'=>null,'sourceRevisionHash'=>$revision,'overrideApplied'=>false],$fx,[
            'expected'=>['included'=>true,'projectedDate'=>$date,'amountCents'=>(int)$row['balance_cents'],'dateBasis'=>(string)$row['due_date']<$firstDay?'overdue_first_forecast_day':'vendor_invoice_due_date','confidenceBps'=>10000,'explanation'=>'The authoritative vendor-invoice due date is used; overdue amounts are placed on the first forecast day.'],
            'conservative'=>['included'=>true,'projectedDate'=>$date,'amountCents'=>(int)$row['balance_cents'],'dateBasis'=>(string)$row['due_date']<$firstDay?'overdue_first_forecast_day':'vendor_invoice_due_date','confidenceBps'=>10000,'explanation'=>'Vendor outflows remain at 100% and are not postponed.'],
        ]);$event=financial5300_apply_override($event,$overrides['bill:'.(string)$row['id']]??null);if(!$event['expected']['included'])$event['expected']['amountCents']=0;if(!$event['conservative']['included'])$event['conservative']['amountCents']=0;$events[]=$event;}
    return ['events'=>$events,'excluded'=>[],'sourceCount'=>count($events)];
}

function financial5300_signature(string $merchant,string $description): string
{
    $value=trim($merchant)!==''?$merchant:$description;$value=mb_strtolower($value);$value=preg_replace('/\b(?:19|20)\d{2}[-\/.]\d{1,2}[-\/.]\d{1,2}\b/u',' ',$value);$value=preg_replace('/\b\d{5,}\b/u',' ',$value);$value=preg_replace('/[^\p{L}\p{N}]+/u',' ',$value);$value=trim(preg_replace('/\s+/u',' ',$value));return mb_substr($value,0,120);
}

function financial5300_charge_cadence(array $dates): ?array
{
    sort($dates,SORT_STRING);if(count($dates)<3)return null;$intervals=[];for($i=1;$i<count($dates);$i++)$intervals[]=financial5300_days_between($dates[$i-1],$dates[$i]);$median=financial5300_median($intervals);$mad=financial5300_mad($intervals,$median);$cadence=$median>=6&&$median<=8?'weekly':($median>=12&&$median<=16?'biweekly':($median>=26&&$median<=35?'monthly':null));if($cadence===null)return null;$cycle=$cadence==='weekly'?7:($cadence==='biweekly'?14:$median);$days=array_map(static fn(string $date):int=>(int)substr($date,8,2),$dates);return ['cadence'=>$cadence,'medianIntervalDays'=>$median,'intervalMadDays'=>$mad,'cycleDays'=>$cycle,'medianDayOfMonth'=>financial5300_median($days)];
}

function financial5300_charge_next_date(string $last,array $cadence,string $asOf): string
{
    $next=$last;do{if($cadence['cadence']==='monthly'){$month=(new DateTimeImmutable(substr($next,0,7).'-01',new DateTimeZone('UTC')))->modify('+1 month');$lastDay=(int)$month->modify('last day of this month')->format('d');$next=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),min($lastDay,(int)$cadence['medianDayOfMonth']))->format('Y-m-d');}else $next=financial5300_date_add($next,(int)$cadence['cycleDays']);}while($next<=$asOf);return financial5300_next_business_day($next);
}

function financial5300_recurring_charges(array $company,string $asOf,string $firstDay,string $end,array $assumptions): array
{
    $from=financial5300_date_add($asOf,-FINANCIAL5300_CHARGE_HISTORY_DAYS);$companyId=(string)$company['id'];$base=(string)$company['currency'];
    $stmt=db()->prepare("SELECT bt.id,bt.bank_account_id,ba.name bank_name,bt.transaction_date,bt.description,bt.reference,bt.normalized_merchant,bt.amount_cents,bt.currency,bt.foreign_amount_cents,bt.exchange_rate_micros,bt.decided_account_id,a.code account_code,a.name account_name,a.account_type,a.expense_category
        FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id AND ba.active=1 AND ba.account_type='bank'
        JOIN journal_entries je ON je.id=bt.journal_entry_id AND je.company_id=bt.company_id AND je.status='posted' AND je.entry_date=bt.transaction_date
        LEFT JOIN accounts a ON a.id=bt.decided_account_id AND a.company_id=bt.company_id
        LEFT JOIN party_payments pp ON pp.bank_transaction_id=bt.id AND pp.company_id=bt.company_id
        WHERE bt.company_id=? AND bt.status='posted' AND bt.amount_cents<0 AND bt.transaction_date BETWEEN ? AND ? AND pp.id IS NULL ORDER BY bt.transaction_date,bt.id");$stmt->execute([$companyId,$from,$asOf]);$groups=[];$possible=[];
    foreach($stmt->fetchAll() as $row){if($row['decided_account_id']!==null&&(string)$row['account_type']!=='expense')continue;if((int)$row['exchange_rate_micros']<=0||((string)$row['currency']!==$base&&(int)$row['foreign_amount_cents']===0))fail('A recurring bank-charge source has incomplete booked exchange-rate evidence. Correct that transaction before relying on the forecast.',422,'financial_forecast_fx_evidence_missing');$accountText=mb_strtolower(trim((string)$row['account_name'].' '.(string)$row['expense_category']));$configured=$row['decided_account_id']!==null&&(bool)preg_match('/\b(bank|merchant|service|processing|wire|transaction)\b.*\b(fee|charge)s?\b|\b(fee|charge)s?\b.*\b(bank|merchant|service|processing|wire|transaction)\b/u',$accountText);$keyword=(bool)preg_match('/\b(bank fee|service charge|monthly fee|wire fee|merchant fee|processing fee)\b/ui',(string)$row['description']);$signature=financial5300_signature((string)$row['normalized_merchant'],(string)$row['description']);if($signature==='')$signature='account '.(string)$row['decided_account_id'];$key=implode('|',[$row['bank_account_id'],$row['currency'],$row['decided_account_id']??'unclassified',$signature]);$row['configured']=$configured;$row['keyword']=$keyword;$row['signature']=$signature;$groups[$key][]=$row;}
    $events=[];$includedGroups=[];
    foreach($groups as $key=>$rows){if(count($rows)<3){if(array_filter($rows,static fn(array $row):bool=>$row['keyword']))$possible[]=['signature'=>$rows[0]['signature'],'bankAccountId'=>(string)$rows[0]['bank_account_id'],'reason'=>'Fewer than three qualifying occurrences.','evidenceCount'=>count($rows)];continue;}$dates=array_map(static fn(array $row):string=>(string)$row['transaction_date'],$rows);$cadence=financial5300_charge_cadence($dates);if(!$cadence){if(array_filter($rows,static fn(array $row):bool=>$row['keyword']))$possible[]=['signature'=>$rows[0]['signature'],'bankAccountId'=>(string)$rows[0]['bank_account_id'],'reason'=>'The intervals do not form a stable weekly, biweekly or monthly pattern.','evidenceCount'=>count($rows)];continue;}$configured=(bool)array_product(array_map(static fn(array $row):int=>$row['configured']?1:0,$rows));$amounts=array_map(static fn(array $row):int=>abs((int)$row['amount_cents']),$rows);$median=financial5300_median($amounts);$amountMad=financial5300_mad($amounts,$median);$last=$dates[count($dates)-1];$recency=financial5300_days_between($last,$asOf);$amountPenalty=$median>0?min(1400,(int)round($amountMad*12000/$median)):1400;$intervalPenalty=min(1400,(int)$cadence['intervalMadDays']*260);$confidence=6000+min(1300,(count($rows)-3)*260)+1000+900-$amountPenalty-$intervalPenalty-min(900,max(0,$recency-(int)$cadence['cycleDays']*2)*20);if($cadence['cadence']==='monthly'){$days=array_map(static fn(string $date):int=>(int)substr($date,8,2),$dates);$domMad=financial5300_mad($days,financial5300_median($days));$confidence-=min(900,$domMad*180);}else $domMad=0;$confidence=max(0,min(10000,$confidence));$next=financial5300_charge_next_date($last,$cadence,$asOf);$high=$configured&&$confidence>=(int)$assumptions['recurringChargeConfidenceThresholdBps'];$medium=$configured&&$confidence>=6000&&!$high;
        $sourceAmounts=array_map(static fn(array $row):int=>abs((int)$row['foreign_amount_cents']),$rows);$sourceMedian=financial5300_median($sourceAmounts);$rates=array_map(static fn(array $row):int=>(int)$row['exchange_rate_micros'],$rows);$rateMedian=financial5300_median($rates);$summary=['signature'=>$rows[0]['signature'],'bankAccountId'=>(string)$rows[0]['bank_account_id'],'bankName'=>(string)$rows[0]['bank_name'],'accountId'=>$rows[0]['decided_account_id']!==null?(string)$rows[0]['decided_account_id']:null,'accountCode'=>$rows[0]['account_code']!==null?(string)$rows[0]['account_code']:null,'accountName'=>$rows[0]['account_name']!==null?(string)$rows[0]['account_name']:null,'currency'=>(string)$rows[0]['currency'],'cadence'=>$cadence['cadence'],'medianAmountCents'=>$median,'medianSourceAmountCents'=>$sourceMedian,'medianExchangeRateMicros'=>$rateMedian,'amountMadCents'=>$amountMad,'medianIntervalDays'=>$cadence['medianIntervalDays'],'intervalMadDays'=>$cadence['intervalMadDays'],'dayOfMonthMadDays'=>$domMad,'confidenceBps'=>$confidence,'lastOccurrence'=>$last,'predictedDate'=>$next,'evidenceCount'=>count($rows),'transactionReferences'=>array_map(static fn(array $row):array=>['id'=>(string)$row['id'],'date'=>(string)$row['transaction_date'],'reference'=>(string)($row['reference']?:$row['description']),'baseAmountCents'=>abs((int)$row['amount_cents']),'sourceAmountCents'=>abs((int)$row['foreign_amount_cents']),'exchangeRateMicros'=>(int)$row['exchange_rate_micros']],$rows),'configuredClassification'=>$configured];
        if(!$high&&!($medium&&!empty($assumptions['includeMediumConfidenceChargesConservative']))){$summary['reason']=$configured?'Confidence is below the inclusion threshold.':'Description evidence is not an approved bank-charge classification.';$possible[]=$summary;continue;}
        // Materialize the exact 28-day horizon plus Day 29 only. Open documents may
        // still disclose later authoritative dates, while recurring patterns avoid
        // inventing an arbitrary extra forecast window beyond this contract.
        $includedGroups[]=$summary;$cursor=$next;$occurrence=1;while($cursor<=financial5300_date_add($end,1)){$expectedIncluded=$high;$conservativeIncluded=$high||($medium&&!empty($assumptions['includeMediumConfidenceChargesConservative']));$conservativeDate=max($firstDay,financial5300_next_business_day(financial5300_date_add($cursor,-(int)$cadence['intervalMadDays'])));$revision=financial_hash($summary);$events[]=['id'=>'recurring-charge:'.substr(hash('sha256',$key),0,24).':'.$occurrence,'companyId'=>$companyId,'sourceType'=>'recurring_bank_charge','sourceId'=>substr(hash('sha256',$key),0,40),'reference'=>(string)$rows[0]['signature'],'party'=>(string)$rows[0]['bank_name'],'direction'=>'outflow','documentDate'=>$last,'dueDate'=>$cursor,'sourceCurrency'=>(string)$rows[0]['currency'],'sourceAmountCents'=>$sourceMedian,'baseCurrency'=>$base,'baseAmountCents'=>$median,'exchangeRateMicros'=>$rateMedian,'rateBasis'=>'posted_bank_transaction_rate','sourceRevisionHash'=>$revision,'chargeEvidence'=>$summary,'overrideApplied'=>false,'expected'=>['included'=>$expectedIncluded,'projectedDate'=>$cursor,'amountCents'=>$expectedIncluded?$median:0,'dateBasis'=>'robust_'.$cadence['cadence'].'_pattern','confidenceBps'=>$confidence,'explanation'=>'High-confidence approved charge pattern at the robust median amount and predicted date.'],'conservative'=>['included'=>$conservativeIncluded,'projectedDate'=>$conservativeDate,'amountCents'=>$conservativeIncluded?$median+$amountMad:0,'dateBasis'=>'earlier_supported_charge_date','confidenceBps'=>$confidence,'explanation'=>'Higher robust amount on the earliest supported business date.']];$occurrence++;if($cadence['cadence']==='monthly'){$month=(new DateTimeImmutable(substr($cursor,0,7).'-01',new DateTimeZone('UTC')))->modify('+1 month');$lastDay=(int)$month->modify('last day of this month')->format('d');$cursor=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),min($lastDay,(int)$cadence['medianDayOfMonth']))->format('Y-m-d');}else $cursor=financial5300_date_add($cursor,(int)$cadence['cycleDays']);$cursor=financial5300_next_business_day($cursor);}
    }
    return ['events'=>$events,'includedGroups'=>$includedGroups,'possible'=>$possible,'sourceCount'=>count($events),'candidateGroupCount'=>count($groups)];
}

function financial5300_scenario_events(?array $scenario,string $firstDay,string $end): array
{
    if(!$scenario)return [];$events=[];
    foreach($scenario['adjustments'] as $adjustment){$amount=financial_scale_cents((int)$adjustment['amountCents'],(int)$adjustment['probabilityBps']);$events[]=['id'=>'scenario-adjustment:'.$adjustment['id'],'companyId'=>'','sourceType'=>'scenario_adjustment','sourceId'=>(string)$adjustment['id'],'reference'=>(string)$adjustment['description'],'party'=>'Scenario','direction'=>(string)$adjustment['direction'],'documentDate'=>(string)$adjustment['date'],'dueDate'=>(string)$adjustment['date'],'sourceCurrency'=>'','sourceAmountCents'=>(int)$adjustment['amountCents'],'baseCurrency'=>'','baseAmountCents'=>$amount,'exchangeRateMicros'=>1000000,'rateBasis'=>'scenario_base_currency','sourceRevisionHash'=>financial_hash($adjustment),'overrideApplied'=>false,'expected'=>['included'=>true,'projectedDate'=>(string)$adjustment['date'],'amountCents'=>$amount,'dateBasis'=>'explicit_scenario_date','confidenceBps'=>(int)$adjustment['probabilityBps'],'explanation'=>'Explicit scenario adjustment using validated probability and half-up integer-cent rounding.'],'conservative'=>['included'=>true,'projectedDate'=>(string)$adjustment['date'],'amountCents'=>$amount,'dateBasis'=>'explicit_scenario_date','confidenceBps'=>(int)$adjustment['probabilityBps'],'explanation'=>'Explicit scenario adjustment; outflows are never reduced.']];}
    return $events;
}

function financial5300_event_totals(array $events,string $date,string $series): array
{
    $out=['customerReceiptsCents'=>0,'vendorPaymentsCents'=>0,'recurringBankChargesCents'=>0,'scenarioInflowsCents'=>0,'scenarioOutflowsCents'=>0,'inflowsCents'=>0,'outflowsCents'=>0,'eventIds'=>[]];
    foreach($events as $event){$point=$event[$series];if(empty($point['included'])||$point['projectedDate']!==$date||(int)$point['amountCents']<=0)continue;$amount=(int)$point['amountCents'];$out['eventIds'][]=$event['id'];if($event['sourceType']==='invoice')$out['customerReceiptsCents']+=$amount;elseif($event['sourceType']==='bill')$out['vendorPaymentsCents']+=$amount;elseif($event['sourceType']==='recurring_bank_charge')$out['recurringBankChargesCents']+=$amount;elseif($event['direction']==='inflow')$out['scenarioInflowsCents']+=$amount;else$out['scenarioOutflowsCents']+=$amount;if($event['direction']==='inflow')$out['inflowsCents']+=$amount;else$out['outflowsCents']+=$amount;}
    return $out;
}

function financial5300_series(array $events,array $weeks,int $opening,string $series,string $asOf): array
{
    $daily=[];$running=$opening;$minimum=$opening;$minimumDate=$asOf;$firstNegative=$opening<0?$asOf:null;
    for($day=1;$day<=FINANCIAL5300_HORIZON_DAYS;$day++){$date=financial5300_date_add($asOf,$day);$totals=financial5300_event_totals($events,$date,$series);$open=$running;$running=$running+$totals['inflowsCents']-$totals['outflowsCents'];if($running<$minimum){$minimum=$running;$minimumDate=$date;}if($firstNegative===null&&$running<0)$firstNegative=$date;$daily[]=array_merge(['date'=>$date,'openingCents'=>$open,'netMovementCents'=>$totals['inflowsCents']-$totals['outflowsCents'],'closingCents'=>$running],$totals);}
    $points=[];foreach($weeks as $week){$days=array_values(array_filter($daily,static fn(array $day):bool=>$day['date']>=$week['periodStart']&&$day['date']<=$week['periodEnd']));$sum=static fn(string $key):int=>array_sum(array_column($days,$key));$eventIds=[];foreach($days as $day)$eventIds=array_merge($eventIds,$day['eventIds']);$points[]=array_merge($week,['openingCents'=>(int)$days[0]['openingCents'],'customerReceiptsCents'=>$sum('customerReceiptsCents'),'vendorPaymentsCents'=>$sum('vendorPaymentsCents'),'recurringBankChargesCents'=>$sum('recurringBankChargesCents'),'scenarioInflowsCents'=>$sum('scenarioInflowsCents'),'scenarioOutflowsCents'=>$sum('scenarioOutflowsCents'),'inflowsCents'=>$sum('inflowsCents'),'outflowsCents'=>$sum('outflowsCents'),'netMovementCents'=>$sum('netMovementCents'),'closingCents'=>(int)$days[count($days)-1]['closingCents'],'eventIds'=>array_values(array_unique($eventIds))]);}
    return ['daily'=>$daily,'weeks'=>$points,'closingCashCents'=>$running,'minimumProjectedCashCents'=>$minimum,'minimumProjectedCashDate'=>$minimumDate,'firstNegativeCashDate'=>$firstNegative,'totalInflowsCents'=>array_sum(array_column($daily,'inflowsCents')),'totalOutflowsCents'=>array_sum(array_column($daily,'outflowsCents'))];
}

function financial5300_after_horizon(array $events,string $end,string $series): array
{
    $count=0;$inflows=0;$outflows=0;$ids=[];foreach($events as $event){$point=$event[$series];if(empty($point['included'])||$point['projectedDate']<=$end||(int)$point['amountCents']<=0)continue;$count++;$ids[]=$event['id'];if($event['direction']==='inflow')$inflows+=(int)$point['amountCents'];else$outflows+=(int)$point['amountCents'];}
    return ['count'=>$count,'inflowsCents'=>$inflows,'outflowsCents'=>$outflows,'netCents'=>$inflows-$outflows,'eventIds'=>$ids];
}

function financial5300_four_week_data(array $company,string $asOf,?string $scenarioId=null,array $optionAssumptions=[]): array
{
    require_company_permission($company,'reports.view');$asOf=financial5300_as_of($asOf);$firstDay=financial5300_date_add($asOf,1);$end=financial5300_date_add($asOf,FINANCIAL5300_HORIZON_DAYS);$weeks=financial5300_weeks($asOf);$scenario=null;$assumptions=financial_assumptions(['forecastMode'=>'four_week']);
    if($scenarioId!==null&&$scenarioId!==''){$scenario=financial_scenario_public($company,financial_scenario_row($company,$scenarioId));$assumptions=$scenario['assumptions'];}
    if($optionAssumptions)$assumptions=financial_assumptions($optionAssumptions,$assumptions);
    $overrides=financial5300_override_map($assumptions);financial5300_validate_source_overrides($company,array_values($overrides),$firstDay,$end);$opening=financial5300_opening_cash($company,$asOf);$invoices=financial5300_invoice_events($company,$asOf,$firstDay,$end,$assumptions,$overrides);$bills=financial5300_bill_events($company,$asOf,$firstDay,$end,$assumptions,$overrides);
    $resolvedOverrides=[];foreach(array_merge($invoices['events'],$bills['events']) as $event)$resolvedOverrides[$event['sourceType'].':'.$event['sourceId']]=true;foreach(array_keys($overrides) as $key)if(!isset($resolvedOverrides[$key]))fail('A forecast source changed or closed after its scenario override was saved. Refresh current books and review the scenario.',409,'financial_override_source_stale');
    $charges=financial5300_recurring_charges($company,$asOf,$firstDay,$end,$assumptions);$scenarioEvents=financial5300_scenario_events($scenario,$firstDay,$end);foreach($scenarioEvents as &$event){$event['companyId']=(string)$company['id'];$event['sourceCurrency']=(string)$company['currency'];$event['baseCurrency']=(string)$company['currency'];}unset($event);$events=array_merge($invoices['events'],$bills['events'],$charges['events'],$scenarioEvents);usort($events,static fn(array $a,array $b):int=>[$a['expected']['projectedDate'],$a['sourceType'],$a['sourceId'],$a['id']]<=>[$b['expected']['projectedDate'],$b['sourceType'],$b['sourceId'],$b['id']]);$expected=financial5300_series($events,$weeks,(int)$opening['totalCents'],'expected',$asOf);$conservative=financial5300_series($events,$weeks,(int)$opening['totalCents'],'conservative',$asOf);
    foreach($expected['daily'] as $index=>$day)if((int)$conservative['daily'][$index]['closingCents']>(int)$day['closingCents'])fail('The selected assumptions would make Conservative cash exceed Expected cash. Review source overrides before relying on this scenario.',422,'financial_conservative_invariant_failed');
    for($i=1;$i<4;$i++){if((int)$expected['weeks'][$i]['openingCents']!==(int)$expected['weeks'][$i-1]['closingCents']||(int)$conservative['weeks'][$i]['openingCents']!==(int)$conservative['weeks'][$i-1]['closingCents'])throw new RuntimeException('Forecast week continuity invariant failed.');}
    $warnings=['Statutory holidays are not modeled because Tegh does not have an authoritative company holiday calendar. Weekend rules are applied exactly.'];if(array_filter($events,static fn(array $event):bool=>$event['sourceCurrency']!==$event['baseCurrency']))$warnings[]='Foreign-currency documents use their authoritative booked exchange rate; no current-market rate is substituted.';if($charges['possible'])$warnings[]='Possible recurring charges are shown separately and are not included unless the documented confidence and classification rules are met.';$excluded=array_merge($invoices['excluded'],$bills['excluded']);$qualityStatus=$opening['accounts']?'complete':'limited';if(!$opening['accounts'])$warnings[]='No active posted bank/cash ledger accounts were found for opening cash.';
    $evidence=['companyId'=>(string)$company['id'],'asOf'=>$asOf,'policyVersion'=>FINANCIAL5300_POLICY_VERSION,'opening'=>$opening,'invoiceEvents'=>$invoices['events'],'billEvents'=>$bills['events'],'chargeEvidence'=>['includedGroups'=>$charges['includedGroups'],'possible'=>$charges['possible']],'scenario'=>$scenario?['id'=>$scenario['id'],'revision'=>$scenario['revision'],'scenarioHash'=>$scenario['scenarioHash']]:null,'assumptions'=>$assumptions,'events'=>$events];$hash=financial_hash($evidence);
    return ['mode'=>'four_week','policyVersion'=>FINANCIAL5300_POLICY_VERSION,'company'=>['id'=>(string)$company['id'],'name'=>(string)$company['name']],'currency'=>(string)$company['currency'],'asOf'=>$asOf,'forecastStart'=>$firstDay,'forecastEnd'=>$end,'horizonDays'=>28,'weeks'=>$weeks,'generatedAt'=>gmdate('c'),'sourceRevisionHash'=>$hash,'openingCashCents'=>(int)$opening['totalCents'],'openingCashAccounts'=>$opening['accounts'],'assumptions'=>$assumptions,'scenario'=>$scenario?['id'=>$scenario['id'],'name'=>$scenario['name'],'revision'=>$scenario['revision'],'scenarioHash'=>$scenario['scenarioHash'],'status'=>$scenario['status']]:null,'expected'=>$expected,'conservative'=>$conservative,'events'=>$events,'afterHorizon'=>['expected'=>financial5300_after_horizon($events,$end,'expected'),'conservative'=>financial5300_after_horizon($events,$end,'conservative')],'recurringCharges'=>['included'=>$charges['includedGroups'],'possible'=>$charges['possible']],'minimumCashThresholdCents'=>(int)$assumptions['minimumCashCents'],'quality'=>['status'=>$qualityStatus,'coverage'=>['openingCashAccountCount'=>count($opening['accounts']),'invoiceCount'=>$invoices['sourceCount'],'billCount'=>$bills['sourceCount'],'customerHistoryProfileCount'=>$invoices['historyCustomerCount'],'includedChargePatternCount'=>count($charges['includedGroups']),'possibleChargeCount'=>count($charges['possible'])],'excludedSources'=>$excluded,'warnings'=>$warnings,'sourceCounts'=>['openingCashAccounts'=>count($opening['accounts']),'openInvoices'=>$invoices['sourceCount'],'openBills'=>$bills['sourceCount'],'recurringChargeEvents'=>$charges['sourceCount'],'scenarioAdjustments'=>count($scenarioEvents)]] ,'planningDisclaimer'=>FINANCIAL5300_DISCLAIMER,'accountingAuthority'=>'posted General Ledger, posted open documents and allocations, booked exchange rates, and revisioned Financial Analyst scenarios','accountingWrites'=>0,'providerAttempts'=>0];
}

function financial5300_four_week(array $company,string $asOf,?string $scenarioId=null,array $optionAssumptions=[]): array
{
    return financial5300_with_snapshot(static fn():array=>financial5300_four_week_data($company,$asOf,$scenarioId,$optionAssumptions));
}

function financial5300_fiscal_months(array $company,string $asOf): array
{
    $cursor=(new DateTimeImmutable($asOf,new DateTimeZone('UTC')))->modify('first day of this month');$months=[];$books=isset($company['books_start_date'])&&$company['books_start_date']!==null?(string)$company['books_start_date']:null;
    for($i=5;$i>=0;$i--){$start=$cursor->modify('-'.$i.' months')->format('Y-m-d');$end=min($asOf,(new DateTimeImmutable($start,new DateTimeZone('UTC')))->modify('last day of this month')->format('Y-m-d'));if($books!==null&&$end<$books)continue;if($books!==null&&$start<$books)$start=$books;$report=portal_financial_report_data($company,'profit-loss',$start,$end);$months[]=['label'=>(new DateTimeImmutable($end,new DateTimeZone('UTC')))->format('M Y'),'periodStart'=>$start,'periodEnd'=>$end,'incomeCents'=>(int)$report['totals']['incomeCents'],'expenseCents'=>(int)$report['totals']['expenseCents'],'netIncomeCents'=>(int)$report['totals']['netIncomeCents']];}
    return $months;
}

function financial5300_dashboard_data(array $company,string $asOf): array
{
    require_company_permission($company,'reports.view');$asOf=financial5300_as_of($asOf);$forecast=financial5300_four_week_data($company,$asOf,null);$balance=portal_financial_report_data($company,'balance-sheet',$asOf,$asOf);$months=financial5300_fiscal_months($company,$asOf);$arEvents=array_values(array_filter($forecast['events'],static fn(array $event):bool=>$event['sourceType']==='invoice'));$apEvents=array_values(array_filter($forecast['events'],static fn(array $event):bool=>$event['sourceType']==='bill'));$arTotal=array_sum(array_column($arEvents,'openBalanceCents'));$arOverdue=array_sum(array_map(static fn(array $event):int=>$event['dueDate']<$asOf?(int)$event['openBalanceCents']:0,$arEvents));$apTotal=array_sum(array_column($apEvents,'openBalanceCents'));$apOverdue=array_sum(array_map(static fn(array $event):int=>$event['dueDate']<$asOf?(int)$event['openBalanceCents']:0,$apEvents));
    $afterEnd=(string)$forecast['forecastEnd'];$arAfter=array_sum(array_map(static fn(array $event):int=>$event['expected']['included']&&$event['expected']['projectedDate']>$afterEnd?(int)$event['expected']['amountCents']:0,$arEvents));$apAfter=array_sum(array_map(static fn(array $event):int=>$event['expected']['included']&&$event['expected']['projectedDate']>$afterEnd?(int)$event['expected']['amountCents']:0,$apEvents));
    $cashStart=$months?$months[0]['periodStart']:$asOf;$cashFlow=advanced_cash_flow_data((string)$company['id'],$cashStart,$asOf);$snapshotHash=financial_hash(['forecastHash'=>$forecast['sourceRevisionHash'],'balanceSheet'=>$balance,'profitLossMonths'=>$months,'cashFlow'=>$cashFlow]);
    return ['company'=>$forecast['company'],'currency'=>$forecast['currency'],'asOf'=>$asOf,'generatedAt'=>gmdate('c'),'sourceRevisionHash'=>$snapshotHash,'fourWeekCashOutlook'=>['forecastStart'=>$forecast['forecastStart'],'forecastEnd'=>$forecast['forecastEnd'],'weeks'=>$forecast['expected']['weeks'],'conservativeWeeks'=>$forecast['conservative']['weeks'],'openingCashCents'=>$forecast['openingCashCents'],'expectedClosingCashCents'=>$forecast['expected']['closingCashCents'],'conservativeClosingCashCents'=>$forecast['conservative']['closingCashCents'],'minimumCashThresholdCents'=>$forecast['minimumCashThresholdCents'],'expectedFirstNegativeCashDate'=>$forecast['expected']['firstNegativeCashDate'],'conservativeFirstNegativeCashDate'=>$forecast['conservative']['firstNegativeCashDate']],'bankBalances'=>['accounts'=>$forecast['openingCashAccounts'],'totalCents'=>$forecast['openingCashCents'],'asOf'=>$asOf],'receivables'=>['totalOutstandingCents'=>$arTotal,'overdueCents'=>$arOverdue,'weeklyExpectedReceiptsCents'=>array_column($forecast['expected']['weeks'],'customerReceiptsCents'),'afterFourWeeksCents'=>$arAfter,'sourceCount'=>count($arEvents)],'payables'=>['totalOutstandingCents'=>$apTotal,'overdueCents'=>$apOverdue,'weeklyExpectedPaymentsCents'=>array_column($forecast['expected']['weeks'],'vendorPaymentsCents'),'afterFourWeeksCents'=>$apAfter,'sourceCount'=>count($apEvents)],'profitAndLoss'=>['months'=>$months,'authority'=>'portal_financial_report_data'],'financialPosition'=>['assetsCents'=>(int)$balance['totals']['assetsCents'],'liabilitiesCents'=>(int)$balance['totals']['liabilitiesCents'],'equityCents'=>(int)$balance['totals']['equityCents'],'liabilitiesEquityCents'=>(int)$balance['totals']['liabilitiesEquityCents'],'differenceCents'=>(int)$balance['totals']['differenceCents'],'balanced'=>(int)$balance['totals']['differenceCents']===0,'authority'=>'portal_financial_report_data'],'cashFlowAuthority'=>['periodStart'=>$cashStart,'periodEnd'=>$asOf,'report'=>$cashFlow],'planningDisclaimer'=>FINANCIAL5300_DISCLAIMER,'accountingWrites'=>0,'providerAttempts'=>0];
}

function financial5300_dashboard(array $company,string $asOf): array
{
    return financial5300_with_snapshot(static fn():array=>financial5300_dashboard_data($company,$asOf));
}
