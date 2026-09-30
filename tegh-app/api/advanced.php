<?php
declare(strict_types=1);

function advanced_next_date(string $date, string $frequency, ?int $anchorDay = null): string
{
    $months = match ($frequency) {
        'monthly' => 1,
        'quarterly' => 3,
        'yearly' => 12,
        default => throw new InvalidArgumentException('Unsupported recurrence frequency.'),
    };
    $current = new DateTimeImmutable($date, new DateTimeZone('UTC'));
    $day = $anchorDay ?? (int)$current->format('d');
    if ($day < 1 || $day > 31) throw new InvalidArgumentException('Recurrence anchor day must be between 1 and 31.');
    $targetMonth = $current->modify('first day of this month')->modify('+' . $months . ' months');
    $targetDay = min($day, (int)$targetMonth->format('t'));
    return $targetMonth->setDate((int)$targetMonth->format('Y'), (int)$targetMonth->format('m'), $targetDay)->format('Y-m-d');
}

function advanced_valid_frequency(mixed $value): string
{
    $frequency = (string)$value;
    if (!in_array($frequency, ['monthly', 'quarterly', 'yearly'], true)) {
        fail('Choose monthly, quarterly, or yearly frequency.');
    }
    return $frequency;
}

function advanced_account_row(string $companyId, string $accountId): array
{
    $account = company_account($companyId, $accountId);
    if (!$account) fail('Choose an available ledger account.');
    return $account;
}

function advanced_analytic_row(string $companyId, string $analyticId): array
{
    $stmt = db()->prepare('SELECT * FROM analytic_accounts WHERE id = ? AND company_id = ? AND active = 1');
    $stmt->execute([$analyticId, $companyId]);
    $row = $stmt->fetch();
    if (!$row) fail('Choose an available analytic account.');
    return $row;
}

function advanced_budget_actual(string $companyId, array $budget, array $line): int
{
    $accountId = (string)$line['account_id'];
    $analyticId = $line['analytic_account_id'] !== null ? (string)$line['analytic_account_id'] : null;
    if ($analyticId === null) {
        $stmt = db()->prepare("SELECT COALESCE(SUM(
            CASE WHEN a.normal_balance = 'debit' THEN jl.debit_cents - jl.credit_cents
                 ELSE jl.credit_cents - jl.debit_cents END), 0)
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_entry_id
            JOIN accounts a ON a.id = jl.account_id
            WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_id = ?
              AND je.entry_date BETWEEN ? AND ?");
        $stmt->execute([$companyId, $accountId, $budget['period_start'], $budget['period_end']]);
        return (int)$stmt->fetchColumn();
    }
    $stmt = db()->prepare("SELECT COALESCE(SUM(ROUND(
        (CASE WHEN a.normal_balance = 'debit' THEN jl.debit_cents - jl.credit_cents
              ELSE jl.credit_cents - jl.debit_cents END) * aa.percentage_bps / 10000)), 0)
        FROM analytic_allocations aa
        JOIN journal_lines jl ON jl.id = aa.journal_line_id
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        JOIN accounts a ON a.id = jl.account_id
        WHERE aa.company_id = ? AND aa.analytic_account_id = ? AND jl.account_id = ?
          AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?");
    $stmt->execute([$companyId, $analyticId, $accountId, $budget['period_start'], $budget['period_end']]);
    return (int)$stmt->fetchColumn();
}

function advanced_classification_for_account(array $account, ?string $mapped): string
{
    if(in_array($mapped,['operating','investing','financing','exchange_effects'],true))return $mapped;
    $type=(string)$account['account_type'];$name=strtolower((string)$account['name']);
    if($type==='equity')return 'financing';
    if(in_array($type,['income','revenue','expense'],true))return 'operating';
    if($type==='liability'){
        if(preg_match('/loan|mortgage|shareholder|lease liability|capital lease|line of credit|credit card|overdraft/',$name))return 'financing';
        if(preg_match('/payable|payroll|wage|salary|gst|hst|pst|tax|accrued|deferred revenue|customer deposit/',$name))return 'operating';
    }
    if($type==='asset'){
        if(preg_match('/equipment|machine|vehicle|property|land|building|investment|intangible|goodwill/',$name))return 'investing';
        if(preg_match('/receivable|inventory|prepaid|tax recoverable|input tax/',$name))return 'operating';
    }
    // Account codes are company-defined, not a financial-reporting standard.
    return 'unclassified';
}

/** Allocate a balanced cash journal without pretending non-cash cross-sign legs are cash flows. */
function advanced_cash_flow_allocate(array $entry, array $lines, array $cashAccountIds, array $mappings): array
{
    $cashSet=array_fill_keys($cashAccountIds,true);$cash=0;$counter=[];$balance=0;
    foreach($lines as $line){$signed=(int)$line['debit_cents']-(int)$line['credit_cents'];$balance+=$signed;
        if(isset($cashSet[(string)$line['account_id']])){$cash+=$signed;continue;}
        if($signed===0)continue;
        $explicit=$mappings[(string)$line['account_id']]??null;
        $activity=advanced_classification_for_account($line,$explicit);
        // Policy-sensitive interest/dividends and unknown types need an explicit company mapping.
        if($explicit===null&&(preg_match('/interest|dividend|exchange|forex|currency gain|currency loss/i',(string)$line['name'])
            || !in_array((string)$line['account_type'],['asset','liability','equity','income','revenue','expense'],true)))$activity='unclassified';
        $counter[]=['line'=>$line,'activity'=>$activity,'amount'=>-$signed,'explicit'=>$explicit!==null];
    }
    $base=['journalEntryId'=>(string)$entry['id'],'date'=>(string)$entry['entry_date'],'memo'=>(string)$entry['memo']];
    if($cash===0)return ['cashDeltaCents'=>0,'activities'=>[],'exceptions'=>[],'internalOrNonCash'=>true];
    if($balance!==0)return ['cashDeltaCents'=>$cash,'activities'=>[$base+['activity'=>'unclassified','amountCents'=>$cash]],'exceptions'=>[$base+['code'=>'unbalanced_journal','differenceCents'=>$balance]],'internalOrNonCash'=>false];
    $groups=array_unique(array_column($counter,'activity'));
    // Realised FX on a receipt is part of that receipt, not a separate cash remeasurement.
    if(count($groups)>1&&in_array('exchange_effects',$groups,true))return ['cashDeltaCents'=>$cash,'activities'=>[$base+['activity'=>'unclassified','amountCents'=>$cash]],
        'exceptions'=>[$base+['code'=>'mixed_fx_receipt_requires_review','message'=>'Review this journal: a mapped currency difference is mixed with a receipt or payment. It cannot be treated automatically as cash remeasurement.']], 'internalOrNonCash'=>false];
    $opposing=array_filter($counter,static fn(array $line):bool=>($cash>0&&$line['amount']<0)||($cash<0&&$line['amount']>0));
    if($opposing){return ['cashDeltaCents'=>$cash,'activities'=>[$base+['activity'=>'unclassified','amountCents'=>$cash]],
        'exceptions'=>[$base+['code'=>'mixed_cash_and_non_cash_components','message'=>'Review this journal: opposite-sign non-cash lines prevent a reliable cash allocation.']], 'internalOrNonCash'=>false];}
    $activities=[];$exceptions=[];
    foreach($counter as $item){$line=$item['line'];$activities[]=$base+['journalLineId'=>(string)$line['line_id'],'accountId'=>(string)$line['account_id'],
        'accountCode'=>(string)$line['code'],'accountName'=>(string)$line['name'],'activity'=>$item['activity'],'amountCents'=>$item['amount'],
        'classificationSource'=>$item['explicit']?'company_mapping':'account_type_rule'];
        if($item['activity']==='unclassified')$exceptions[]=$base+['code'=>'mapping_required','accountId'=>(string)$line['account_id'],'accountName'=>(string)$line['name']];
    }
    return ['cashDeltaCents'=>$cash,'activities'=>$activities,'exceptions'=>$exceptions,'internalOrNonCash'=>false];
}

function advanced_cash_flow_data(string $companyId, string $startDate, string $endDate): array
{
    $startDate=safe_date($startDate,'Start date');$endDate=safe_date($endDate,'End date');
    if($startDate>$endDate)fail('Start date must not be after end date.',422,'cash_flow_period_invalid');
    $companyStmt=db()->prepare('SELECT reporting_framework,currency FROM companies WHERE id=?');$companyStmt->execute([$companyId]);$company=$companyStmt->fetch()?:[];
    // SELECT ba.* is retained-schema compatible: the optional 5930 profile is inspected only when present.
    $stmt=db()->prepare('SELECT ba.* FROM bank_accounts ba WHERE ba.company_id=?');$stmt->execute([$companyId]);$cashAccountIds=[];$accountEvidence=[];
    foreach($stmt->fetchAll() as $bank){$profile=json_decode((string)($bank['profile_json']??''),true);$profile=is_array($profile)?$profile:[];
        $eligible=(string)$bank['account_type']==='bank';
        $overdraft=(string)($profile['accountSubtype']??'')==='overdraft';
        if($overdraft)$eligible=(string)($company['reporting_framework']??'')==='ifrs'&&($profile['cashFlowOverdraftEligible']??false)===true&&($profile['repayableOnDemand']??false)===true&&($profile['integralCashManagement']??false)===true&&trim((string)($profile['termsNote']??''))!=='';
        if(!$eligible)continue;$cashAccountIds[]=(string)$bank['ledger_account_id'];
        $accountEvidence[]=['bankAccountId'=>(string)$bank['id'],'ledgerAccountId'=>(string)$bank['ledger_account_id'],'active'=>(bool)$bank['active'],'overdraftException'=>$overdraft];
    }
    $cashAccountIds=array_values(array_unique($cashAccountIds));$sections=['operating'=>0,'investing'=>0,'financing'=>0];
    $result=['periodStart'=>$startDate,'periodEnd'=>$endDate,'sections'=>$sections,'openingCashCents'=>0,'closingCashCents'=>0,'netChangeCents'=>0,
        'exchangeEffectsCents'=>0,'unclassifiedCents'=>0,'reconciliationDifferenceCents'=>0,'activities'=>[],'exceptions'=>[],'cashAccounts'=>$accountEvidence,
        'currency'=>(string)($company['currency']??'CAD'),'reportingFramework'=>(string)($company['reporting_framework']??'not_set'),
        'presentationStatus'=>'Management report — unaudited','policyNotice'=>'Account mappings determine cash-flow categories. Review interest, dividends, currency adjustments and exceptional financing before external financial-statement use. Framework selection alone is not compliance certification.',
        'definitionVersion'=>'5930.cash-flow.1','accountingWrites'=>0];
    if(!$cashAccountIds)return $result;
    $placeholders=implode(',',array_fill(0,count($cashAccountIds),'?'));
    $stmt=db()->prepare("SELECT COALESCE(SUM(CASE WHEN je.entry_date<? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) opening,
        COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) closing FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id
        JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id
        WHERE je.company_id=? AND je.status='posted' AND je.entry_date<=? AND jl.account_id IN ($placeholders)");
    $stmt->execute(array_merge([$startDate,$companyId,$endDate],$cashAccountIds));$balances=$stmt->fetch();
    $result['openingCashCents']=(int)$balances['opening'];$result['closingCashCents']=(int)$balances['closing'];
    $map=db()->prepare('SELECT account_id,activity FROM cash_flow_mappings WHERE company_id=?');$map->execute([$companyId]);$mappings=[];
    foreach($map->fetchAll() as $row)$mappings[(string)$row['account_id']]=(string)$row['activity'];
    // One bounded query avoids one SQL request for every journal and retains exact line provenance.
    $stmt=db()->prepare("SELECT je.id,je.entry_date,je.memo,je.source_type,jl.id line_id,jl.account_id,jl.debit_cents,jl.credit_cents,a.code,a.name,a.account_type
        FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id
        WHERE je.company_id=? AND je.status='posted' AND je.entry_date BETWEEN ? AND ?
        AND EXISTS(SELECT 1 FROM journal_lines cash WHERE cash.journal_entry_id=je.id AND cash.account_id IN ($placeholders))
        ORDER BY je.entry_date,je.id,jl.id");
    $stmt->execute(array_merge([$companyId,$startDate,$endDate],$cashAccountIds));$entries=[];
    while($line=$stmt->fetch()){$id=(string)$line['id'];$entries[$id]['entry']=$line;$entries[$id]['lines'][]=$line;}
    foreach($entries as $journal){$allocation=advanced_cash_flow_allocate($journal['entry'],$journal['lines'],$cashAccountIds,$mappings);
        foreach($allocation['activities'] as $activity){$kind=$activity['activity'];if(isset($result['sections'][$kind]))$result['sections'][$kind]+=$activity['amountCents'];elseif($kind==='exchange_effects')$result['exchangeEffectsCents']+=$activity['amountCents'];else $result['unclassifiedCents']+=$activity['amountCents'];$result['activities'][]=$activity;}
        array_push($result['exceptions'],...$allocation['exceptions']);
    }
    $result['netChangeCents']=array_sum($result['sections']);
    $result['reconciliationDifferenceCents']=$result['closingCashCents']-$result['openingCashCents']-$result['netChangeCents']-$result['exchangeEffectsCents']-$result['unclassifiedCents'];
    if($result['reconciliationDifferenceCents']!==0)$result['exceptions'][]=['code'=>'cash_balance_does_not_reconcile','differenceCents'=>$result['reconciliationDifferenceCents']];
    $result['sourceRevisionHash']=hash('sha256',json_encode([$startDate,$endDate,$cashAccountIds,$result['activities'],$result['openingCashCents'],$result['closingCashCents']],JSON_THROW_ON_ERROR));
    return $result;
}

/**
 * Schema 41 already gives a depreciation line one immutable asset/period
 * identity and gives its journal one company/source identity.  This digest is
 * the optimistic-lock token exposed to the browser; it contains no secret or
 * cross-company identifier that the browser did not already receive.
 */
function advanced_depreciation_source_revision(array $line): string
{
    $material = [
        'lineId'=>(string)($line['id']??''),
        'assetId'=>(string)($line['asset_id']??''),
        'assetUpdatedAt'=>(string)($line['asset_updated_at']??''),
        'sequenceNumber'=>(int)($line['sequence_number']??0),
        'periodEnd'=>(string)($line['period_end']??''),
        'depreciationCents'=>(int)($line['depreciation_cents']??0),
        'status'=>(string)($line['status']??''),
        'journalEntryId'=>$line['journal_entry_id']!==null?(string)$line['journal_entry_id']:null,
        'expenseAccountId'=>(string)($line['depreciation_expense_account_id']??''),
        'accumulatedAccountId'=>(string)($line['accumulated_depreciation_account_id']??''),
    ];
    return hash('sha256', json_encode($material, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function advanced_depreciation_operation_key(mixed $value): string
{
    $key=trim((string)$value);
    if(strlen($key)<16||strlen($key)>64||!preg_match('/^[A-Za-z0-9._:-]+$/',$key)){
        fail('The depreciation operation key is invalid. Refresh the schedule and try again.',422,'depreciation_operation_key_invalid');
    }
    return $key;
}

function advanced_depreciation_receipt(string $companyId,string $lineId): ?array
{
    $stmt=db()->prepare("SELECT dl.id,dl.asset_id,dl.sequence_number,dl.period_end,dl.depreciation_cents,dl.status,dl.journal_entry_id,dl.posted_at,
        fa.name AS asset_name,fa.status AS asset_status,v.id AS voucher_id,v.voucher_number,v.status AS voucher_status,
        je.company_id AS journal_company_id,je.source_type AS journal_source_type,je.source_id AS journal_source_id,je.status AS journal_status
        FROM asset_depreciation_lines dl
        JOIN fixed_assets fa ON fa.id=dl.asset_id AND fa.company_id=?
        LEFT JOIN journal_entries je ON je.id=dl.journal_entry_id
        LEFT JOIN vouchers v ON v.company_id=fa.company_id AND v.source_type='asset_depreciation' AND v.source_id=dl.id
        WHERE dl.id=? LIMIT 1");
    $stmt->execute([$companyId,$lineId]);$row=$stmt->fetch();
    if(!$row||$row['journal_entry_id']===null)return null;
    if((string)$row['journal_company_id']!==$companyId||(string)$row['journal_source_type']!=='asset_depreciation'
        ||(string)$row['journal_source_id']!==(string)$row['id']||(string)$row['journal_status']!=='posted')return null;
    return [
        'depreciationLine'=>[
            'id'=>(string)$row['id'],'assetId'=>(string)$row['asset_id'],'assetName'=>(string)$row['asset_name'],
            'sequenceNumber'=>(int)$row['sequence_number'],'periodEnd'=>(string)$row['period_end'],
            'depreciationCents'=>(int)$row['depreciation_cents'],'status'=>(string)$row['status'],
            'postedAt'=>$row['posted_at']!==null?(string)$row['posted_at']:null,
        ],
        'journalEntryId'=>(string)$row['journal_entry_id'],
        'voucher'=>[
            'id'=>$row['voucher_id']!==null?(string)$row['voucher_id']:null,
            'number'=>$row['voucher_number']!==null?(string)$row['voucher_number']:null,
            'status'=>$row['voucher_status']!==null?(string)$row['voucher_status']:null,
        ],
        'amountCents'=>(int)$row['depreciation_cents'],'postingDate'=>(string)$row['period_end'],
        'accountingWrites'=>1,'requestReference'=>request_id(),
    ];
}

function advanced_depreciation_test_failure(string $point,array $company,array $input): void
{
    if((string)($input['failurePoint']??'')!==$point)return;
    if((string)($_SERVER['HTTP_X_SR_TEST_RUN']??'')!=='1'||empty($company['test_mode']))return;
    throw new RuntimeException('Injected depreciation failure after '.$point.'.');
}

/** @return array<int,array<string,mixed>> */
function advanced_depreciation_journal_lines(string $companyId,string $journalId): array
{
    $stmt=db()->prepare('SELECT jl.account_id,a.code,a.name,jl.debit_cents,jl.credit_cents,jl.memo
        FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id
        JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id
        WHERE je.id=? AND je.company_id=? ORDER BY jl.account_id,jl.debit_cents,jl.credit_cents,jl.id');
    $stmt->execute([$journalId,$companyId]);
    return array_map(static fn(array $row):array=>[
        'accountId'=>(string)$row['account_id'],'accountCode'=>(string)$row['code'],'accountName'=>(string)$row['name'],
        'debitCents'=>(int)$row['debit_cents'],'creditCents'=>(int)$row['credit_cents'],'memo'=>(string)$row['memo'],
    ],$stmt->fetchAll());
}

/** @return array<string,mixed> */
function advanced_depreciation_diagnostic_data(string $companyId): array
{
    $issues=[
        'multipleJournalsForOccurrence'=>[],
        'postedOccurrenceWithoutValidJournal'=>[],
        'pendingOccurrenceWithJournal'=>[],
        'journalWithoutOccurrence'=>[],
        'voucherMissing'=>[],
        'dayBookOmission'=>[],
        'unbalancedJournal'=>[],
        'assetBalanceMismatch'=>[],
        'possibleDuplicateCandidates'=>[],
    ];

    $stmt=db()->prepare("SELECT je.source_id,COUNT(*) journal_count,GROUP_CONCAT(je.id ORDER BY je.id SEPARATOR ',') journal_ids
        FROM journal_entries je WHERE je.company_id=? AND je.source_type='asset_depreciation'
        GROUP BY je.source_id HAVING COUNT(*)>1");
    $stmt->execute([$companyId]);
    foreach($stmt->fetchAll() as $row)$issues['multipleJournalsForOccurrence'][]=[
        'scheduleOccurrenceId'=>(string)$row['source_id'],'journalCount'=>(int)$row['journal_count'],
        'journalIds'=>array_values(array_filter(explode(',',(string)$row['journal_ids']))),
        'classification'=>'confirmed_contract_violation',
    ];

    $stmt=db()->prepare("SELECT dl.id occurrence_id,dl.asset_id,fa.name asset_name,dl.sequence_number,dl.period_end,
        dl.depreciation_cents,dl.status,dl.journal_entry_id,je.id canonical_journal_id,je.company_id journal_company_id,
        je.source_type,je.source_id,je.status journal_status,v.id voucher_id
        FROM asset_depreciation_lines dl JOIN fixed_assets fa ON fa.id=dl.asset_id AND fa.company_id=?
        LEFT JOIN journal_entries je ON je.id=dl.journal_entry_id
        LEFT JOIN vouchers v ON v.company_id=fa.company_id AND v.source_type='asset_depreciation' AND v.source_id=dl.id
        ORDER BY fa.name,dl.sequence_number");
    $stmt->execute([$companyId]);
    $occurrences=$stmt->fetchAll();
    foreach($occurrences as $row){
        $identity=['scheduleOccurrenceId'=>(string)$row['occurrence_id'],'assetId'=>(string)$row['asset_id'],'assetName'=>(string)$row['asset_name'],
            'sequenceNumber'=>(int)$row['sequence_number'],'periodEnd'=>(string)$row['period_end'],'amountCents'=>(int)$row['depreciation_cents'],
            'journalEntryId'=>$row['journal_entry_id']!==null?(string)$row['journal_entry_id']:null];
        $validJournal=$row['canonical_journal_id']!==null&&(string)$row['journal_company_id']===$companyId
            &&(string)$row['source_type']==='asset_depreciation'&&(string)$row['source_id']===(string)$row['occurrence_id'];
        if((string)$row['status']==='posted'&&!$validJournal)$issues['postedOccurrenceWithoutValidJournal'][]=$identity+[
            'classification'=>'confirmed_provenance_failure','safeNextAction'=>'Review the exact occurrence and journal evidence; do not post or reverse automatically.',
        ];
        if((string)$row['status']!=='posted'&&($row['journal_entry_id']!==null||$row['canonical_journal_id']!==null))$issues['pendingOccurrenceWithJournal'][]=$identity+[
            'classification'=>'confirmed_state_mismatch','occurrenceStatus'=>(string)$row['status'],
        ];
        if($validJournal&&$row['voucher_id']===null){
            $item=$identity+['classification'=>'confirmed_register_omission'];
            $issues['voucherMissing'][]=$item;$issues['dayBookOmission'][]=$item;
        }
    }

    $stmt=db()->prepare("SELECT je.id journal_id,je.source_type,je.source_id,je.entry_date,je.memo,je.status,
        COALESCE(SUM(jl.debit_cents),0) debit_cents,COALESCE(SUM(jl.credit_cents),0) credit_cents
        FROM journal_entries je LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id
        LEFT JOIN asset_depreciation_lines dl ON dl.id=je.source_id
        LEFT JOIN fixed_assets fa ON fa.id=dl.asset_id AND fa.company_id=je.company_id
        WHERE je.company_id=? AND je.source_type IN ('asset_depreciation','asset_depreciation_duplicate_reversal')
        GROUP BY je.id,je.source_id,je.entry_date,je.memo,je.status,fa.id
        ORDER BY je.entry_date,je.id");
    $stmt->execute([$companyId]);
    foreach($stmt->fetchAll() as $row){
        $item=['journalEntryId'=>(string)$row['journal_id'],'sourceType'=>(string)$row['source_type'],'scheduleOccurrenceId'=>(string)$row['source_id'],'date'=>(string)$row['entry_date'],
            'memo'=>(string)$row['memo'],'status'=>(string)$row['status'],'debitCents'=>(int)$row['debit_cents'],'creditCents'=>(int)$row['credit_cents']];
        if((string)$row['source_type']==='asset_depreciation'&&$row['source_id']!==null&&!array_filter($occurrences,static fn(array $occ):bool=>(string)$occ['occurrence_id']===(string)$row['source_id'])
            &&(string)$row['source_id']!=='')$issues['journalWithoutOccurrence'][]=$item+['classification'=>'confirmed_orphan_source'];
        if((int)$row['debit_cents']!==(int)$row['credit_cents']||(int)$row['debit_cents']<=0)$issues['unbalancedJournal'][]=$item+['classification'=>'confirmed_balance_failure'];
    }

    $stmt=db()->prepare("SELECT fa.id asset_id,fa.name asset_name,fa.original_cost_cents,fa.salvage_value_cents,
        COALESCE(SUM(CASE WHEN dl.status='posted' THEN dl.depreciation_cents ELSE 0 END),0) schedule_posted_cents,
        COALESCE((SELECT SUM(jl.debit_cents) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id
          WHERE je.company_id=fa.company_id AND je.source_type='asset_depreciation' AND je.status='posted'
            AND jl.account_id=fa.depreciation_expense_account_id AND je.source_id IN (SELECT x.id FROM asset_depreciation_lines x WHERE x.asset_id=fa.id)),0) gl_expense_cents,
        COALESCE((SELECT SUM(jl.credit_cents) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id
          WHERE je.company_id=fa.company_id AND je.source_type='asset_depreciation' AND je.status='posted'
            AND jl.account_id=fa.accumulated_depreciation_account_id AND je.source_id IN (SELECT x.id FROM asset_depreciation_lines x WHERE x.asset_id=fa.id)),0) gl_accumulated_cents
        FROM fixed_assets fa LEFT JOIN asset_depreciation_lines dl ON dl.asset_id=fa.id
        WHERE fa.company_id=? GROUP BY fa.id,fa.name,fa.original_cost_cents,fa.salvage_value_cents,
          fa.company_id,fa.depreciation_expense_account_id,fa.accumulated_depreciation_account_id ORDER BY fa.name");
    $stmt->execute([$companyId]);
    $assetRollups=[];
    foreach($stmt->fetchAll() as $row){
        $rollup=['assetId'=>(string)$row['asset_id'],'assetName'=>(string)$row['asset_name'],'originalCostCents'=>(int)$row['original_cost_cents'],
            'salvageValueCents'=>(int)$row['salvage_value_cents'],'scheduleAccumulatedDepreciationCents'=>(int)$row['schedule_posted_cents'],
            'glDepreciationExpenseCents'=>(int)$row['gl_expense_cents'],'glAccumulatedDepreciationCents'=>(int)$row['gl_accumulated_cents'],
            'scheduleBookValueCents'=>(int)$row['original_cost_cents']-(int)$row['schedule_posted_cents']];
        $assetRollups[]=$rollup;
        if($rollup['scheduleAccumulatedDepreciationCents']!==$rollup['glDepreciationExpenseCents']
            ||$rollup['scheduleAccumulatedDepreciationCents']!==$rollup['glAccumulatedDepreciationCents'])
            $issues['assetBalanceMismatch'][]=$rollup+['classification'=>'confirmed_amount_mismatch'];
    }

    // Similar amount/date/memo evidence is deliberately classified only as a
    // candidate. It is never sufficient authority for reversal or relinking.
    $stmt=db()->prepare("SELECT dl.id occurrence_id,fa.id asset_id,fa.name asset_name,dl.period_end,dl.depreciation_cents,
        linked.id canonical_journal_id,candidate.id candidate_journal_id,candidate.source_type candidate_source_type,candidate.memo
        FROM asset_depreciation_lines dl JOIN fixed_assets fa ON fa.id=dl.asset_id AND fa.company_id=?
        JOIN journal_entries linked ON linked.id=dl.journal_entry_id AND linked.company_id=fa.company_id
        JOIN journal_entries candidate ON candidate.company_id=fa.company_id AND candidate.id<>linked.id
          AND candidate.entry_date=dl.period_end AND candidate.status='posted'
        WHERE dl.status='posted' AND EXISTS(
          SELECT 1 FROM journal_lines jl WHERE jl.journal_entry_id=candidate.id
          GROUP BY jl.journal_entry_id HAVING SUM(jl.debit_cents)=dl.depreciation_cents AND SUM(jl.credit_cents)=dl.depreciation_cents)
        ORDER BY dl.period_end,fa.name,candidate.id LIMIT 500");
    $stmt->execute([$companyId]);
    foreach($stmt->fetchAll() as $row)$issues['possibleDuplicateCandidates'][]=[
        'classification'=>'review_candidate_not_proven','scheduleOccurrenceId'=>(string)$row['occurrence_id'],'assetId'=>(string)$row['asset_id'],
        'assetName'=>(string)$row['asset_name'],'periodEnd'=>(string)$row['period_end'],'amountCents'=>(int)$row['depreciation_cents'],
        'canonicalJournalId'=>(string)$row['canonical_journal_id'],'candidateJournalId'=>(string)$row['candidate_journal_id'],
        'candidateSourceType'=>(string)$row['candidate_source_type'],'candidateMemo'=>(string)$row['memo'],
        'warning'=>'Same date and amount are not proof of duplication. Review exact source provenance before any correction.',
    ];

    $counts=[];$confirmed=0;
    foreach($issues as $name=>$rows){$counts[$name]=count($rows);if($name!=='possibleDuplicateCandidates')$confirmed+=count($rows);}
    $fingerprint=hash('sha256',json_encode(['counts'=>$counts,'assetRollups'=>$assetRollups,'issues'=>$issues],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return ['schemaVersion'=>41,'readOnly'=>true,'accountingWrites'=>0,'requestReference'=>request_id(),'generatedAt'=>gmdate('c'),
        'summary'=>['confirmedIssueCount'=>$confirmed,'reviewCandidateCount'=>count($issues['possibleDuplicateCandidates']),'counts'=>$counts,'evidenceHash'=>$fingerprint],
        'issues'=>$issues,'assetRollups'=>$assetRollups];
}

function handle_advanced_depreciation_diagnostic(): never
{
    require_method('GET');$user=require_user();$company=require_company($user);
    require_company_role($company,'owner');require_company_permission($company,'audit.view');
    json_response(['diagnostic'=>advanced_depreciation_diagnostic_data((string)$company['id'])]);
}

/** @return array<string,mixed> */
function advanced_depreciation_remediation_evidence(string $companyId,string $canonicalJournalId,string $duplicateJournalId,bool $lock=false): array
{
    if(hash_equals($canonicalJournalId,$duplicateJournalId))fail('Choose two different journal entries.',422,'depreciation_remediation_same_journal',false);
    $suffix=$lock?' FOR UPDATE':'';
    $stmt=db()->prepare("SELECT je.id,je.entry_date,je.source_type,je.source_id,je.memo,je.status,je.reversal_of_id,
        dl.id occurrence_id,dl.asset_id,dl.period_end,dl.depreciation_cents,fa.name asset_name
        FROM journal_entries je
        LEFT JOIN asset_depreciation_lines dl ON dl.id=je.source_id
        LEFT JOIN fixed_assets fa ON fa.id=dl.asset_id AND fa.company_id=je.company_id
        WHERE je.company_id=? AND je.id=? LIMIT 1".$suffix);
    $stmt->execute([$companyId,$canonicalJournalId]);$canonical=$stmt->fetch();
    $stmt->execute([$companyId,$duplicateJournalId]);$duplicate=$stmt->fetch();
    if(!$canonical||!$duplicate)fail('One or more selected journals are unavailable.',404,'depreciation_remediation_unavailable',false);
    if((string)$canonical['source_type']!=='asset_depreciation'||$canonical['occurrence_id']===null)
        fail('The canonical journal is not linked to a depreciation schedule occurrence.',409,'depreciation_canonical_provenance_required',false);
    if((string)$canonical['status']!=='posted'||(string)$duplicate['status']!=='posted')
        fail('Only two currently posted journals can be compared for remediation.',409,'depreciation_remediation_status_invalid',false);
    if($duplicate['reversal_of_id']!==null)fail('A reversal journal cannot be selected as the duplicate.',409,'depreciation_remediation_target_invalid',false);
    $canonicalLines=advanced_depreciation_journal_lines($companyId,$canonicalJournalId);
    $duplicateLines=advanced_depreciation_journal_lines($companyId,$duplicateJournalId);
    $normalize=static fn(array $rows):array=>array_map(static fn(array $row):array=>[
        'accountId'=>$row['accountId'],'debitCents'=>$row['debitCents'],'creditCents'=>$row['creditCents'],
    ],$rows);
    $canonicalShape=$normalize($canonicalLines);$duplicateShape=$normalize($duplicateLines);
    $blockers=[];
    if($canonicalShape!==$duplicateShape)$blockers[]='The selected journals do not have the same exact accounts and debit/credit cents.';
    if((string)$canonical['entry_date']!==(string)$duplicate['entry_date'])$blockers[]='The selected journals do not have the same posting date.';
    if((int)$canonical['depreciation_cents']!==array_sum(array_column($canonicalLines,'debitCents')))
        $blockers[]='The canonical journal does not equal the schedule occurrence amount.';
    $existingStmt=db()->prepare("SELECT id,entry_date,status FROM journal_entries WHERE company_id=? AND reversal_of_id=? AND source_type='asset_depreciation_duplicate_reversal' ORDER BY created_at DESC LIMIT 1".($lock?' FOR UPDATE':''));
    $existingStmt->execute([$companyId,$duplicateJournalId]);$existing=$existingStmt->fetch();
    if($existing)$blockers[]='The selected duplicate already has a protected depreciation correction.';
    $evidence=[
        'canonicalJournal'=>['id'=>(string)$canonical['id'],'date'=>(string)$canonical['entry_date'],'sourceType'=>(string)$canonical['source_type'],
            'scheduleOccurrenceId'=>(string)$canonical['occurrence_id'],'assetId'=>(string)$canonical['asset_id'],'assetName'=>(string)$canonical['asset_name'],
            'periodEnd'=>(string)$canonical['period_end'],'amountCents'=>(int)$canonical['depreciation_cents'],'lines'=>$canonicalLines],
        'duplicateJournal'=>['id'=>(string)$duplicate['id'],'date'=>(string)$duplicate['entry_date'],'sourceType'=>(string)$duplicate['source_type'],
            'sourceId'=>(string)$duplicate['source_id'],'memo'=>(string)$duplicate['memo'],'lines'=>$duplicateLines],
        'existingCorrection'=>$existing?['id'=>(string)$existing['id'],'date'=>(string)$existing['entry_date'],'status'=>(string)$existing['status']]:null,
        'blockers'=>$blockers,
    ];
    $evidence['evidenceHash']=hash('sha256',json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return $evidence;
}

function handle_advanced_depreciation_remediation(): never
{
    require_method('POST');require_csrf();$user=require_user();$company=require_company($user);
    require_company_role($company,'owner');require_company_permission($company,'journals.reverse');
    $input=request_json();$companyId=(string)$company['id'];
    $canonicalId=clean_text($input['canonicalJournalId']??'','Canonical journal',64);
    $duplicateId=clean_text($input['duplicateJournalId']??'','Duplicate journal',64);
    $apply=($input['apply']??false)===true;
    $evidence=advanced_depreciation_remediation_evidence($companyId,$canonicalId,$duplicateId,false);
    $adjustmentDate=null;$originalDate=(string)$evidence['duplicateJournal']['date'];$originalLock=period_lock_for_date($companyId,$originalDate);
    if($originalLock!==null){
        $raw=trim((string)($input['adjustmentDate']??''));
        if($raw==='')$evidence['blockers'][]='The original period is locked. Choose an authorized open adjustment date; Tegh will not silently back-date the correction.';
        else{
            $adjustmentDate=safe_date($raw,'Adjustment date');assert_not_future_date($adjustmentDate,'Adjustment date');
            if($adjustmentDate<$originalDate)$evidence['blockers'][]='The adjustment date cannot precede the duplicate posting date.';
            elseif(period_lock_for_date($companyId,$adjustmentDate)!==null)$evidence['blockers'][]='The selected adjustment date is also in a locked period.';
        }
    }else $adjustmentDate=$originalDate;
    $evidence['proposedReversalDate']=$adjustmentDate;$evidence['originalPeriodLocked']=$originalLock!==null;
    $evidence['evidenceHash']=hash('sha256',json_encode([
        'canonicalJournal'=>$evidence['canonicalJournal'],'duplicateJournal'=>$evidence['duplicateJournal'],
        'existingCorrection'=>$evidence['existingCorrection'],'proposedReversalDate'=>$adjustmentDate,'originalPeriodLocked'=>$originalLock!==null,
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    if(!$apply)json_response(['preview'=>$evidence+['dryRun'=>true,'accountingWrites'=>0,'applyAllowed'=>$evidence['blockers']===[],'requestReference'=>request_id()]]);

    if(function_exists('tegh_entitlement_recent_auth_required'))tegh_entitlement_recent_auth_required();
    if(($input['confirmed']??false)!==true)fail('Explicitly confirm the reviewed duplicate correction.',409,'depreciation_remediation_confirmation_required',false);
    $reason=trim((string)($input['reason']??''));if(mb_strlen($reason)<20||mb_strlen($reason)>1000)fail('Give a correction reason of 20 to 1,000 characters.',422,'depreciation_remediation_reason_required',false);
    $expectedHash=strtolower(trim((string)($input['expectedEvidenceHash']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$expectedHash)||!hash_equals($expectedHash,(string)$evidence['evidenceHash']))fail('The remediation evidence changed. Review the dry run again.',409,'depreciation_remediation_evidence_stale',false);
    if($evidence['blockers']!==[])fail(implode(' ',$evidence['blockers']),409,'depreciation_remediation_blocked',false);
    $operationKey=advanced_depreciation_operation_key($input['operationKey']??'');
    $payloadHash=hash('sha256',json_encode(['companyId'=>$companyId,'userId'=>(string)$user['id'],'canonicalJournalId'=>$canonicalId,
        'duplicateJournalId'=>$duplicateId,'evidenceHash'=>$expectedHash,'reversalDate'=>$adjustmentDate,'reason'=>$reason],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $actionType='app:fixed_assets.depreciation_remediate';$result=[];
    db()->beginTransaction();
    try{
        $companyLock=db()->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');$companyLock->execute([$companyId]);$companyLock->fetchColumn();
        $prior=db()->prepare('SELECT payload_hash,status,result_json FROM ai_agent_action_authorizations WHERE company_id=? AND user_id=? AND action_type=? AND operation_key=? ORDER BY created_at DESC,id DESC LIMIT 1');
        $prior->execute([$companyId,$user['id'],$actionType,$operationKey]);
        if($row=$prior->fetch()){
            if(!hash_equals((string)$row['payload_hash'],$payloadHash))fail('That correction operation key was already used for different instructions.',409,'depreciation_remediation_operation_conflict',false);
            $stored=json_decode((string)($row['result_json']??''),true);if((string)$row['status']!=='completed'||!is_array($stored))fail('The earlier correction is unresolved. Review the diagnostic before retrying.',409,'depreciation_remediation_unresolved',false);
            $stored['idempotentReplay']=true;$stored['accountingWrites']=0;db()->commit();json_response($stored);
        }
        $locked=advanced_depreciation_remediation_evidence($companyId,$canonicalId,$duplicateId,true);
        $lockedDate=$originalLock!==null?$adjustmentDate:$originalDate;
        $lockedHash=hash('sha256',json_encode(['canonicalJournal'=>$locked['canonicalJournal'],'duplicateJournal'=>$locked['duplicateJournal'],
            'existingCorrection'=>$locked['existingCorrection'],'proposedReversalDate'=>$lockedDate,'originalPeriodLocked'=>$originalLock!==null],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        if(!hash_equals($expectedHash,$lockedHash)||$locked['blockers']!==[])fail('The journals changed after the dry run. Review the diagnostic again.',409,'depreciation_remediation_evidence_stale',false);
        if($lockedDate===null||period_lock_for_date($companyId,$lockedDate)!==null)fail('The correction date is locked. Review the permitted adjustment period.',409,'period_locked',false);
        $reversalId=add_reversing_journal_entry($user,$companyId,$duplicateId,$lockedDate,'asset_depreciation_duplicate_reversal',$duplicateId,
            'Correction of confirmed duplicate depreciation · '.(string)$locked['canonicalJournal']['assetName']);
        $amount=array_sum(array_column((array)$locked['duplicateJournal']['lines'],'debitCents'));
        $voucherId=function_exists('voucher_register_saved')?voucher_register_saved($user,$companyId,'DR','GL','asset_depreciation_duplicate_reversal',$duplicateId,$lockedDate,
            'Depreciation duplicate correction · '.(string)$locked['canonicalJournal']['assetName'],$amount,$reversalId,true):'';
        $result=['status'=>'completed','canonicalJournalId'=>$canonicalId,'duplicateJournalId'=>$duplicateId,'reversalJournalId'=>$reversalId,
            'voucherId'=>$voucherId!==''?$voucherId:null,'reversalDate'=>$lockedDate,'amountCents'=>$amount,'idempotentReplay'=>false,
            'accountingWrites'=>1,'requestReference'=>request_id()];
        audit_event($user,$companyId,'asset.depreciation_duplicate_reversed','fixed_asset',(string)$locked['canonicalJournal']['assetId'],[
            'canonicalJournalId'=>$canonicalId,'duplicateJournalId'=>$duplicateId,'reversalJournalId'=>$reversalId,'voucherId'=>$voucherId,
            'scheduleOccurrenceId'=>(string)$locked['canonicalJournal']['scheduleOccurrenceId'],'reversalDate'=>$lockedDate,'amountCents'=>$amount,
            'reason'=>$reason,'operationKeyHash'=>hash('sha256',$operationKey),'evidenceHash'=>$expectedHash,
        ]);
        db()->prepare("INSERT INTO ai_agent_action_authorizations
            (id,company_id,user_id,action_type,payload_json,payload_hash,status,expires_at,authorized_at,completed_at,journal_entry_id,operation_key,result_status,result_json)
            VALUES (?,?,?,?,?,?,'completed',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,?,'completed',?)")
            ->execute([new_id('depremreceipt'),$companyId,$user['id'],$actionType,json_encode(['canonicalJournalId'=>$canonicalId,'duplicateJournalId'=>$duplicateId,'evidenceHash'=>$expectedHash],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                $payloadHash,$reversalId,$operationKey,json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();if($error instanceof PDOException&&(string)$error->getCode()==='23000')fail('The selected duplicate was corrected concurrently. Refresh the diagnostic.',409,'depreciation_remediation_concurrent',false);throw $error;}
    json_response($result);
}

function advanced_workspace_data(array $user, array $company): array
{
    $companyId = (string)$company['id'];
    $today = canadian_today();
    $periodStart = fiscal_period_start($today, (string)$company['fiscal_year_end']);

    $stmt = db()->prepare('SELECT id, code, name, account_type, normal_balance, is_control, active FROM accounts WHERE company_id = ? AND active = 1 ORDER BY code');
    $stmt->execute([$companyId]);
    $accounts = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'code' => (string)$row['code'], 'name' => (string)$row['name'],
        'type' => (string)$row['account_type'], 'normalBalance' => (string)$row['normal_balance'],
        'isControl' => (bool)$row['is_control'],
    ], $stmt->fetchAll());

    $stmt = db()->prepare('SELECT aa.*, c.name AS customer_name FROM analytic_accounts aa LEFT JOIN customers c ON c.id = aa.customer_id WHERE aa.company_id = ? AND aa.active = 1 ORDER BY aa.code, aa.name');
    $stmt->execute([$companyId]);
    $analyticAccounts = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'code' => (string)$row['code'], 'name' => (string)$row['name'],
        'parentId' => $row['parent_id'] !== null ? (string)$row['parent_id'] : null,
        'customerId' => $row['customer_id'] !== null ? (string)$row['customer_id'] : null,
        'customerName' => $row['customer_name'] !== null ? (string)$row['customer_name'] : null,
    ], $stmt->fetchAll());

    $stmt = db()->prepare("SELECT jl.id, je.entry_date, je.memo AS entry_memo, je.source_type,
        a.code, a.name AS account_name, a.normal_balance, jl.debit_cents, jl.credit_cents,
        COALESCE(SUM(aa.percentage_bps), 0) AS allocated_bps
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        JOIN accounts a ON a.id = jl.account_id
        LEFT JOIN analytic_allocations aa ON aa.journal_line_id = jl.id
        WHERE je.company_id = ? AND je.status = 'posted'
        GROUP BY jl.id, je.entry_date, je.memo, je.source_type, a.code, a.name, a.normal_balance, jl.debit_cents, jl.credit_cents
        ORDER BY je.entry_date DESC, jl.id DESC LIMIT 150");
    $stmt->execute([$companyId]);
    $journalLines = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'date' => (string)$row['entry_date'], 'entryMemo' => (string)$row['entry_memo'],
        'sourceType' => (string)$row['source_type'], 'accountCode' => (string)$row['code'], 'accountName' => (string)$row['account_name'],
        'amountCents' => (int)$row['debit_cents'] + (int)$row['credit_cents'],
        'side' => (int)$row['debit_cents'] > 0 ? 'debit' : 'credit', 'allocatedBps' => (int)$row['allocated_bps'],
    ], $stmt->fetchAll());

    $stmt = db()->prepare("SELECT aa.journal_line_id, aa.analytic_account_id, aa.percentage_bps, an.code, an.name
        FROM analytic_allocations aa JOIN analytic_accounts an ON an.id = aa.analytic_account_id
        WHERE aa.company_id = ? ORDER BY aa.created_at DESC");
    $stmt->execute([$companyId]);
    $allocations = array_map(static fn(array $row): array => [
        'journalLineId' => (string)$row['journal_line_id'], 'analyticAccountId' => (string)$row['analytic_account_id'],
        'percentageBps' => (int)$row['percentage_bps'], 'analyticCode' => (string)$row['code'], 'analyticName' => (string)$row['name'],
    ], $stmt->fetchAll());

    $stmt = db()->prepare('SELECT * FROM budgets WHERE company_id = ? ORDER BY period_start DESC, created_at DESC');
    $stmt->execute([$companyId]);
    $budgets = [];
    $lineStmt = db()->prepare("SELECT bl.*, a.code, a.name AS account_name, an.code AS analytic_code, an.name AS analytic_name
        FROM budget_lines bl JOIN accounts a ON a.id = bl.account_id
        LEFT JOIN analytic_accounts an ON an.id = bl.analytic_account_id
        WHERE bl.budget_id = ? ORDER BY a.code, an.code");
    foreach ($stmt->fetchAll() as $budget) {
        $lineStmt->execute([(string)$budget['id']]);
        $lines = [];
        $planned = 0;
        $actual = 0;
        foreach ($lineStmt->fetchAll() as $line) {
            $lineActual = advanced_budget_actual($companyId, $budget, $line);
            $planned += (int)$line['planned_cents'];
            $actual += $lineActual;
            $lines[] = [
                'id' => (string)$line['id'], 'accountId' => (string)$line['account_id'],
                'accountCode' => (string)$line['code'], 'accountName' => (string)$line['account_name'],
                'analyticAccountId' => $line['analytic_account_id'] !== null ? (string)$line['analytic_account_id'] : null,
                'analyticCode' => $line['analytic_code'] !== null ? (string)$line['analytic_code'] : null,
                'analyticName' => $line['analytic_name'] !== null ? (string)$line['analytic_name'] : null,
                'plannedCents' => (int)$line['planned_cents'], 'actualCents' => $lineActual,
                'varianceCents' => (int)$line['planned_cents'] - $lineActual,
            ];
        }
        $budgets[] = [
            'id' => (string)$budget['id'], 'name' => (string)$budget['name'],
            'periodStart' => (string)$budget['period_start'], 'periodEnd' => (string)$budget['period_end'],
            'status' => (string)$budget['status'], 'plannedCents' => $planned, 'actualCents' => $actual,
            'varianceCents' => $planned - $actual, 'lines' => $lines,
        ];
    }

    $stmt = db()->prepare("SELECT fa.*,
        aa.code AS asset_code, aa.name AS asset_account_name,
        de.code AS expense_code, de.name AS expense_account_name,
        ad.code AS accumulated_code, ad.name AS accumulated_account_name,
        COALESCE(SUM(CASE WHEN dl.status = 'posted' THEN dl.depreciation_cents ELSE 0 END), 0) AS posted_depreciation_cents,
        MIN(CASE WHEN dl.status = 'planned' THEN dl.period_end ELSE NULL END) AS next_depreciation_date,
        SUM(CASE WHEN dl.status = 'planned' AND dl.period_end <= ? THEN 1 ELSE 0 END) AS due_count
        FROM fixed_assets fa
        JOIN accounts aa ON aa.id = fa.asset_account_id
        JOIN accounts de ON de.id = fa.depreciation_expense_account_id
        JOIN accounts ad ON ad.id = fa.accumulated_depreciation_account_id
        LEFT JOIN asset_depreciation_lines dl ON dl.asset_id = fa.id
        WHERE fa.company_id = ? GROUP BY fa.id ORDER BY fa.in_service_date DESC, fa.created_at DESC");
    $stmt->execute([$today, $companyId]);
    $assets = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'name' => (string)$row['name'], 'acquisitionDate' => (string)$row['acquisition_date'],
        'inServiceDate' => (string)$row['in_service_date'], 'originalCostCents' => (int)$row['original_cost_cents'],
        'salvageValueCents' => (int)$row['salvage_value_cents'], 'usefulLifeMonths' => (int)$row['useful_life_months'],
        'status' => (string)$row['status'], 'assetAccountId' => (string)$row['asset_account_id'],
        'assetAccount' => (string)$row['asset_code'] . ' · ' . (string)$row['asset_account_name'],
        'depreciationExpenseAccountId' => (string)$row['depreciation_expense_account_id'],
        'depreciationExpenseAccount' => (string)$row['expense_code'] . ' · ' . (string)$row['expense_account_name'],
        'accumulatedDepreciationAccountId' => (string)$row['accumulated_depreciation_account_id'],
        'accumulatedDepreciationAccount' => (string)$row['accumulated_code'] . ' · ' . (string)$row['accumulated_account_name'],
        'postedDepreciationCents' => (int)$row['posted_depreciation_cents'],
        'bookValueCents' => (int)$row['original_cost_cents'] - (int)$row['posted_depreciation_cents'],
        'nextDepreciationDate' => $row['next_depreciation_date'] !== null ? (string)$row['next_depreciation_date'] : null,
        'dueCount' => (int)$row['due_count'], 'updatedAt'=>(string)$row['updated_at'],
    ], $stmt->fetchAll());

    $stmt = db()->prepare("SELECT dl.id,dl.asset_id,dl.sequence_number,dl.period_end,dl.depreciation_cents,dl.status,dl.journal_entry_id,
        fa.name AS asset_name,fa.updated_at AS asset_updated_at,fa.depreciation_expense_account_id,fa.accumulated_depreciation_account_id
        FROM asset_depreciation_lines dl JOIN fixed_assets fa ON fa.id = dl.asset_id
        WHERE fa.company_id = ? AND dl.status = 'planned' ORDER BY dl.period_end, dl.sequence_number LIMIT 250");
    $stmt->execute([$companyId]);
    $depreciationLines = array_map(static function(array $row): array {
        return [
            'id'=>(string)$row['id'],'assetId'=>(string)$row['asset_id'],'assetName'=>(string)$row['asset_name'],
            'sequenceNumber'=>(int)$row['sequence_number'],'periodEnd'=>(string)$row['period_end'],
            'depreciationCents'=>(int)$row['depreciation_cents'],'status'=>(string)$row['status'],
            'due'=>(string)$row['period_end']<=canadian_today(),
            'sourceRevisionHash'=>advanced_depreciation_source_revision($row),
        ];
    }, $stmt->fetchAll());

    $stmt = db()->prepare('SELECT * FROM recurring_journal_templates WHERE company_id = ? ORDER BY active DESC, next_run_date, name');
    $stmt->execute([$companyId]);
    $recurringJournals = [];
    $rjLineStmt = db()->prepare("SELECT rjl.*, a.code, a.name AS account_name FROM recurring_journal_lines rjl
        JOIN accounts a ON a.id = rjl.account_id WHERE rjl.template_id = ? ORDER BY rjl.sort_order, rjl.id");
    foreach ($stmt->fetchAll() as $row) {
        $rjLineStmt->execute([(string)$row['id']]);
        $lines = array_map(static fn(array $line): array => [
            'id' => (string)$line['id'], 'accountId' => (string)$line['account_id'],
            'accountCode' => (string)$line['code'], 'accountName' => (string)$line['account_name'],
            'debitCents' => (int)$line['debit_cents'], 'creditCents' => (int)$line['credit_cents'], 'memo' => (string)$line['memo'],
        ], $rjLineStmt->fetchAll());
        $recurringJournals[] = [
            'id' => (string)$row['id'], 'name' => (string)$row['name'], 'memo' => (string)$row['memo'],
            'frequency' => (string)$row['frequency'], 'nextRunDate' => (string)$row['next_run_date'],
            'endDate' => $row['end_date'] !== null ? (string)$row['end_date'] : null, 'active' => (bool)$row['active'],
            'due' => (bool)$row['active'] && (string)$row['next_run_date'] <= $today, 'lines' => $lines,
        ];
    }

    $stmt = db()->prepare("SELECT rip.*, c.name AS customer_name, c.email AS customer_email FROM recurring_invoice_profiles rip
        JOIN customers c ON c.id = rip.customer_id WHERE rip.company_id = ? ORDER BY rip.active DESC, rip.next_invoice_date, rip.name");
    $stmt->execute([$companyId]);
    $recurringInvoices = [];
    $riLineStmt = db()->prepare('SELECT * FROM recurring_invoice_lines WHERE profile_id = ? ORDER BY sort_order, id');
    foreach ($stmt->fetchAll() as $row) {
        $riLineStmt->execute([(string)$row['id']]);
        $lines = array_map(static fn(array $line): array => [
            'id' => (string)$line['id'], 'description' => (string)$line['description'],
            'quantityMilli' => (int)$line['quantity_milli'], 'foreignUnitPriceCents' => (int)$line['foreign_unit_price_cents'],
            'taxable' => (bool)$line['taxable'],
        ], $riLineStmt->fetchAll());
        $recurringInvoices[] = [
            'id' => (string)$row['id'], 'name' => (string)$row['name'], 'customerId' => (string)$row['customer_id'],
            'customerName' => (string)$row['customer_name'], 'customerEmail' => $row['customer_email'] !== null ? (string)$row['customer_email'] : null,
            'frequency' => (string)$row['frequency'], 'nextInvoiceDate' => (string)$row['next_invoice_date'],
            'endDate' => $row['end_date'] !== null ? (string)$row['end_date'] : null,
            'paymentTermsDays' => (int)$row['payment_terms_days'], 'currency' => (string)$row['currency'],
            'templateId' => $row['template_id'] !== null ? (string)$row['template_id'] : null,
            'message' => (string)$row['message'], 'issueAutomatically' => (bool)$row['issue_automatically'],
            'active' => (bool)$row['active'], 'due' => (bool)$row['active'] && (string)$row['next_invoice_date'] <= $today,
            'lines' => $lines,
        ];
    }

    $stmt = db()->prepare("SELECT rbp.*, v.name AS vendor_name, a.code AS category_code, a.name AS category_name
        FROM recurring_bill_profiles rbp
        JOIN vendors v ON v.id = rbp.vendor_id
        JOIN accounts a ON a.id = rbp.category_account_id
        WHERE rbp.company_id = ? ORDER BY rbp.active DESC, rbp.next_bill_date, rbp.name");
    $stmt->execute([$companyId]);
    $recurringBills = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'name' => (string)$row['name'],
        'vendorId' => (string)$row['vendor_id'], 'vendorName' => (string)$row['vendor_name'],
        'frequency' => (string)$row['frequency'], 'nextBillDate' => (string)$row['next_bill_date'],
        'endDate' => $row['end_date'] !== null ? (string)$row['end_date'] : null,
        'paymentTermsDays' => (int)$row['payment_terms_days'], 'currency' => (string)$row['currency'],
        'categoryAccountId' => (string)$row['category_account_id'],
        'categoryAccount' => (string)$row['category_code'] . ' · ' . (string)$row['category_name'],
        'foreignAmountCents' => (int)$row['foreign_amount_cents'],
        'applyGstHst' => (bool)$row['apply_gst_hst'], 'applyPst' => (bool)$row['apply_pst'],
        'taxEntryMode' => (string)$row['tax_entry_mode'], 'memo' => (string)$row['memo'],
        'issueAutomatically' => (bool)$row['issue_automatically'],
        'active' => (bool)$row['active'], 'due' => (bool)$row['active'] && (string)$row['next_bill_date'] <= $today,
    ], $stmt->fetchAll());

    $stmt = db()->prepare("SELECT i.id, i.number, i.due_date, i.balance_cents, i.foreign_balance_cents, i.currency,
        c.name AS customer_name, c.email AS customer_email,
        MAX(f.action_date) AS last_followup_date,
        SUBSTRING_INDEX(GROUP_CONCAT(f.level ORDER BY f.action_date DESC, f.created_at DESC), ',', 1) AS last_followup_level
        FROM invoices i JOIN customers c ON c.id = i.customer_id
        LEFT JOIN invoice_followups f ON f.invoice_id = i.id
        WHERE i.company_id = ? AND i.status = 'sent' AND i.balance_cents > 0 AND i.due_date < ?
        GROUP BY i.id ORDER BY i.due_date, i.number");
    $stmt->execute([$companyId, $today]);
    $overdueInvoices = array_map(static function(array $row) use ($today): array {
        $due = new DateTimeImmutable((string)$row['due_date'], new DateTimeZone('UTC'));
        $now = new DateTimeImmutable($today, new DateTimeZone('UTC'));
        $days = (int)$due->diff($now)->format('%a');
        $recommended = $days >= 60 ? 'final' : ($days >= 30 ? 'firm' : 'friendly');
        return [
            'id' => (string)$row['id'], 'number' => (string)$row['number'], 'dueDate' => (string)$row['due_date'],
            'daysOverdue' => $days, 'balanceCents' => (int)$row['balance_cents'],
            'foreignBalanceCents' => (int)$row['foreign_balance_cents'], 'currency' => (string)$row['currency'],
            'customerName' => (string)$row['customer_name'], 'customerEmail' => $row['customer_email'] !== null ? (string)$row['customer_email'] : null,
            'lastFollowupDate' => $row['last_followup_date'] !== null ? (string)$row['last_followup_date'] : null,
            'lastFollowupLevel' => $row['last_followup_level'] !== null ? (string)$row['last_followup_level'] : null,
            'recommendedLevel' => $recommended,
        ];
    }, $stmt->fetchAll());

    $stmt = db()->prepare('SELECT id, name, email FROM customers WHERE company_id = ? AND active = 1 ORDER BY name');
    $stmt->execute([$companyId]);
    $customers = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'name' => (string)$row['name'], 'email' => $row['email'] !== null ? (string)$row['email'] : null,
    ], $stmt->fetchAll());
    $stmt = db()->prepare('SELECT id, name, default_terms_days, default_expense_account_id, default_currency FROM vendors WHERE company_id = ? AND active = 1 ORDER BY name');
    $stmt->execute([$companyId]);
    $vendors = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'name' => (string)$row['name'], 'defaultTermsDays' => (int)$row['default_terms_days'],
        'defaultExpenseAccountId' => $row['default_expense_account_id'] !== null ? (string)$row['default_expense_account_id'] : null,
        'defaultCurrency' => (string)$row['default_currency'],
    ], $stmt->fetchAll());
    $templates = invoice_templates_for_company($company);

    $stmt = db()->prepare('SELECT account_id, activity FROM cash_flow_mappings WHERE company_id = ?');
    $stmt->execute([$companyId]);
    $cashMappings = [];
    foreach ($stmt->fetchAll() as $row) $cashMappings[(string)$row['account_id']] = (string)$row['activity'];
    $cashFlowAccounts = array_map(static function(array $account) use ($cashMappings): array {
        return $account + ['cashFlowActivity' => advanced_classification_for_account([
            'code' => $account['code'], 'name' => $account['name'], 'account_type' => $account['type'],
        ], $cashMappings[$account['id']] ?? null)];
    }, array_values(array_filter($accounts, static fn(array $account): bool => !$account['isControl'])));

    $entitlement=function_exists('tegh_resolve_feature')?tegh_resolve_feature($user,$company,'core.accounting'):['revision'=>0];
    return [
        'version' => SR_ACCOUNTAX_VERSION,
        'organization' => ['id' => $companyId, 'name' => (string)$company['name'], 'currency' => (string)$company['currency'], 'today' => $today],
        'entitlementRevision'=>(int)($entitlement['revision']??0),
        'accounts' => $accounts, 'customers' => $customers, 'vendors' => $vendors, 'invoiceTemplates' => $templates,
        'analyticAccounts' => $analyticAccounts, 'journalLines' => $journalLines, 'analyticAllocations' => $allocations,
        'budgets' => $budgets, 'assets' => $assets, 'depreciationLines' => $depreciationLines,
        'recurringJournals' => $recurringJournals, 'recurringInvoices' => $recurringInvoices, 'recurringBills' => $recurringBills,
        'overdueInvoices' => $overdueInvoices, 'cashFlow' => advanced_cash_flow_data($companyId, $periodStart, $today),
        'cashFlowAccounts' => $cashFlowAccounts,
    ];
}

function handle_advanced_workspace(): never
{
    require_method('GET');
    $user = require_user();
    $company = require_company($user);
    json_response(advanced_workspace_data($user, $company));
}

function handle_advanced_analytic_accounts(): never
{
    require_method('POST', 'PATCH');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    if (request_method() === 'PATCH') {
        $id = clean_text($input['analyticAccountId'] ?? '', 'Analytic account', 64);
        $stmt = db()->prepare('SELECT active FROM analytic_accounts WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $companyId]);
        $current = $stmt->fetchColumn();
        if ($current === false) fail('Choose an available analytic account.');
        $active = !empty($input['active']);
        if ((bool)$current !== $active) {
            db()->prepare('UPDATE analytic_accounts SET active = ? WHERE id = ? AND company_id = ?')->execute([$active ? 1 : 0, $id, $companyId]);
            audit_event($user, $companyId, $active ? 'analytic_account.activated' : 'analytic_account.archived', 'analytic_account', $id);
        }
        json_response(['analyticAccount' => ['id' => $id, 'active' => $active]]);
    }
    $code = strtoupper(clean_text($input['code'] ?? '', 'Analytic code', 30));
    if (!preg_match('/^[A-Z0-9._-]+$/', $code)) fail('Analytic code can use letters, numbers, periods, hyphens, and underscores.');
    $name = clean_text($input['name'] ?? '', 'Analytic account name', 160);
    $parentId = optional_text($input['parentId'] ?? null, 64);
    if ($parentId !== null) advanced_analytic_row($companyId, $parentId);
    $customerId = optional_text($input['customerId'] ?? null, 64);
    if ($customerId !== null) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM customers WHERE id = ? AND company_id = ? AND active = 1');
        $stmt->execute([$customerId, $companyId]);
        if ((int)$stmt->fetchColumn() !== 1) fail('Choose an available customer.');
    }
    $id = new_id('analytic');
    try {
        db()->prepare('INSERT INTO analytic_accounts (id, company_id, code, name, parent_id, customer_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $companyId, $code, $name, $parentId, $customerId]);
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') fail('That analytic code already exists.', 409, 'analytic_code_exists');
        throw $error;
    }
    audit_event($user, $companyId, 'analytic_account.created', 'analytic_account', $id, ['code' => $code, 'name' => $name]);
    json_response(['analyticAccount' => ['id' => $id]], 201);
}

function handle_advanced_analytic_allocations(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    $journalLineId = clean_text($input['journalLineId'] ?? '', 'Journal line', 64);
    $stmt = db()->prepare('SELECT jl.id FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id WHERE jl.id = ? AND je.company_id = ? AND je.status = \'posted\'');
    $stmt->execute([$journalLineId, $companyId]);
    if (!$stmt->fetch()) fail('Choose an available posted journal line.');
    $source = $input['allocations'] ?? null;
    if (!is_array($source) || count($source) > 20) fail('Provide up to 20 analytic allocations.');
    $rows = [];
    $sum = 0;
    $seen = [];
    foreach ($source as $allocation) {
        if (!is_array($allocation)) fail('An analytic allocation is invalid.');
        $analyticId = clean_text($allocation['analyticAccountId'] ?? '', 'Analytic account', 64);
        if (isset($seen[$analyticId])) fail('The same analytic account cannot be selected twice.');
        $seen[$analyticId] = true;
        advanced_analytic_row($companyId, $analyticId);
        $bps = safe_cents($allocation['percentageBps'] ?? 0, 'Allocation percentage');
        if ($bps <= 0 || $bps > 10000) fail('Each allocation must be greater than 0% and no more than 100%.');
        $sum += $bps;
        $rows[] = [$analyticId, $bps];
    }
    if ($sum > 10000) fail('Analytic allocations cannot exceed 100% of a journal line.');
    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM analytic_allocations WHERE company_id = ? AND journal_line_id = ?')->execute([$companyId, $journalLineId]);
        $insert = db()->prepare('INSERT INTO analytic_allocations (id, company_id, journal_line_id, analytic_account_id, percentage_bps, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($rows as [$analyticId, $bps]) $insert->execute([new_id('allocation'), $companyId, $journalLineId, $analyticId, $bps, $user['id']]);
        audit_event($user, $companyId, 'analytic_allocation.updated', 'journal_line', $journalLineId, ['allocationCount' => count($rows), 'allocatedBps' => $sum]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['journalLineId' => $journalLineId, 'allocatedBps' => $sum]);
}

function handle_advanced_budgets(): never
{
    require_method('POST', 'PATCH');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    if (request_method() === 'PATCH') {
        $budgetId = clean_text($input['budgetId'] ?? '', 'Budget', 64);
        $status = (string)($input['status'] ?? '');
        if (!in_array($status, ['draft', 'active', 'closed'], true)) fail('Budget status is invalid.');
        $stmt = db()->prepare('UPDATE budgets SET status = ? WHERE id = ? AND company_id = ?');
        $stmt->execute([$status, $budgetId, $companyId]);
        if ($stmt->rowCount() !== 1) fail('Choose an available budget.');
        audit_event($user, $companyId, 'budget.status_changed', 'budget', $budgetId, ['status' => $status]);
        json_response(['budget' => ['id' => $budgetId, 'status' => $status]]);
    }
    $name = clean_text($input['name'] ?? '', 'Budget name', 160);
    $periodStart = safe_date($input['periodStart'] ?? '', 'Budget start date');
    $periodEnd = safe_date($input['periodEnd'] ?? '', 'Budget end date');
    if ($periodEnd < $periodStart) fail('Budget end date cannot precede the start date.');
    $sourceLines = $input['lines'] ?? null;
    if (!is_array($sourceLines) || count($sourceLines) < 1 || count($sourceLines) > 200) fail('A budget requires between 1 and 200 lines.');
    $lines = [];
    $seen = [];
    $accountModes = [];
    foreach ($sourceLines as $source) {
        if (!is_array($source)) fail('A budget line is invalid.');
        $accountId = clean_text($source['accountId'] ?? '', 'Budget account', 64);
        advanced_account_row($companyId, $accountId);
        $analyticId = optional_text($source['analyticAccountId'] ?? null, 64);
        if ($analyticId !== null) advanced_analytic_row($companyId, $analyticId);
        $key = $accountId . '|' . ($analyticId ?? '');
        if (isset($seen[$key])) fail('A budget account and analytic combination can appear only once.');
        $seen[$key] = true;
        $mode = $analyticId === null ? 'whole' : 'analytic';
        if (isset($accountModes[$accountId]) && $accountModes[$accountId] !== $mode) {
            fail('Within one budget, use either a whole-account line or analytic breakdowns for an account, not both.');
        }
        $accountModes[$accountId] = $mode;
        $planned = safe_cents($source['plannedCents'] ?? 0, 'Planned amount', true);
        if ($planned < 0) fail('Planned amounts cannot be negative.');
        $lines[] = [$accountId, $analyticId, $planned];
    }
    $budgetId = new_id('budget');
    db()->beginTransaction();
    try {
        db()->prepare("INSERT INTO budgets (id, company_id, name, period_start, period_end, status, created_by) VALUES (?, ?, ?, ?, ?, 'active', ?)")
            ->execute([$budgetId, $companyId, $name, $periodStart, $periodEnd, $user['id']]);
        $insert = db()->prepare('INSERT INTO budget_lines (id, budget_id, account_id, analytic_account_id, planned_cents) VALUES (?, ?, ?, ?, ?)');
        foreach ($lines as [$accountId, $analyticId, $planned]) $insert->execute([new_id('budgetline'), $budgetId, $accountId, $analyticId, $planned]);
        audit_event($user, $companyId, 'budget.created', 'budget', $budgetId, ['name' => $name, 'periodStart' => $periodStart, 'periodEnd' => $periodEnd, 'lineCount' => count($lines)]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['budget' => ['id' => $budgetId]], 201);
}

function advanced_month_end(string $inServiceDate, int $offset): string
{
    // Anchor at the first day before adding months. Adding months directly to
    // dates such as January 31 or July 31 can overflow into the following
    // month, creating duplicate depreciation period ends.
    $date = new DateTimeImmutable($inServiceDate, new DateTimeZone('UTC'));
    $date = $date->modify('first day of this month');
    if ($offset > 0) $date = $date->modify('+' . $offset . ' months');
    return $date->modify('last day of this month')->format('Y-m-d');
}

function handle_advanced_assets(): never
{
    require_method('POST', 'PATCH');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    if (request_method() === 'PATCH') {
        $assetId = clean_text($input['assetId'] ?? '', 'Asset', 64);
        $status = (string)($input['status'] ?? '');
        if (!in_array($status, ['active', 'disposed'], true)) fail('Asset status is invalid.');
        $stmt = db()->prepare('SELECT status FROM fixed_assets WHERE id = ? AND company_id = ?');
        $stmt->execute([$assetId, $companyId]);
        $currentStatus = $stmt->fetchColumn();
        if ($currentStatus === false) fail('Choose an available asset.');
        if ((string)$currentStatus !== $status) {
            db()->prepare('UPDATE fixed_assets SET status = ? WHERE id = ? AND company_id = ?')->execute([$status, $assetId, $companyId]);
            audit_event($user, $companyId, 'asset.status_changed', 'fixed_asset', $assetId, ['previousStatus' => (string)$currentStatus, 'status' => $status]);
        }
        json_response(['asset' => ['id' => $assetId, 'status' => $status]]);
    }
    $name = clean_text($input['name'] ?? '', 'Asset name', 200);
    $acquisitionDate = safe_date($input['acquisitionDate'] ?? '', 'Acquisition date');
    $inServiceDate = safe_date($input['inServiceDate'] ?? '', 'In-service date');
    if ($inServiceDate < $acquisitionDate) fail('In-service date cannot precede acquisition.');
    $cost = safe_cents($input['originalCostCents'] ?? 0, 'Original cost');
    $salvage = safe_cents($input['salvageValueCents'] ?? 0, 'Salvage value', true);
    if ($cost <= 0 || $salvage < 0 || $salvage >= $cost) fail('Salvage value must be zero or less than original cost.');
    $life = (int)($input['usefulLifeMonths'] ?? 0);
    if ($life < 1 || $life > 1200) fail('Useful life must be between 1 and 1,200 months.');
    $assetAccount = advanced_account_row($companyId, clean_text($input['assetAccountId'] ?? '', 'Asset account', 64));
    $expenseAccount = advanced_account_row($companyId, clean_text($input['depreciationExpenseAccountId'] ?? '', 'Depreciation expense account', 64));
    $accumAccount = advanced_account_row($companyId, clean_text($input['accumulatedDepreciationAccountId'] ?? '', 'Accumulated depreciation account', 64));
    if ((string)$assetAccount['account_type'] !== 'asset' || (string)$expenseAccount['account_type'] !== 'expense'
        || (string)$accumAccount['account_type'] !== 'asset' || (string)$accumAccount['normal_balance'] !== 'credit') {
        fail('Choose an asset account, an expense account, and a credit-normal accumulated depreciation asset account.');
    }
    $depreciable = $cost - $salvage;
    $base = intdiv($depreciable, $life);
    $remainder = $depreciable % $life;
    $assetId = new_id('asset');
    db()->beginTransaction();
    try {
        db()->prepare("INSERT INTO fixed_assets (id, company_id, name, acquisition_date, in_service_date, original_cost_cents,
            salvage_value_cents, useful_life_months, method, asset_account_id, depreciation_expense_account_id,
            accumulated_depreciation_account_id, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'straight_line', ?, ?, ?, 'active', ?)")
            ->execute([$assetId, $companyId, $name, $acquisitionDate, $inServiceDate, $cost, $salvage, $life,
                $assetAccount['id'], $expenseAccount['id'], $accumAccount['id'], $user['id']]);
        $insert = db()->prepare("INSERT INTO asset_depreciation_lines (id, asset_id, sequence_number, period_end, depreciation_cents, status)
            VALUES (?, ?, ?, ?, ?, 'planned')");
        for ($index = 0; $index < $life; $index++) {
            $amount = $base + ($index < $remainder ? 1 : 0);
            $insert->execute([new_id('depline'), $assetId, $index + 1, advanced_month_end($inServiceDate, $index), $amount]);
        }
        audit_event($user, $companyId, 'asset.created', 'fixed_asset', $assetId, ['name' => $name, 'costCents' => $cost, 'salvageValueCents' => $salvage, 'usefulLifeMonths' => $life]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['asset' => ['id' => $assetId]], 201);
}

function handle_advanced_asset_post(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_permission($company, 'journals.write');
    $entitlement=function_exists('tegh_require_feature')?tegh_require_feature($user,$company,'core.accounting'):['revision'=>0];
    $companyId = (string)$company['id'];
    $input = request_json();
    $lineId = clean_text($input['depreciationLineId'] ?? '', 'Depreciation line', 64);
    $operationKey=advanced_depreciation_operation_key($input['operationKey']??'');
    $expectedRevision=strtolower(trim((string)($input['expectedSourceRevisionHash']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$expectedRevision))fail('The depreciation schedule changed. Refresh it before posting.',409,'depreciation_revision_required');
    $expectedEntitlement=filter_var($input['expectedEntitlementRevision']??null,FILTER_VALIDATE_INT);
    if($expectedEntitlement===false||(int)$expectedEntitlement!==(int)($entitlement['revision']??0))fail('Feature access changed. Refresh the schedule before posting.',409,'entitlement_revision_stale');
    $operationKeyHash=hash('sha256',$companyId.'|'.(string)$user['id'].'|'.$operationKey);
    $payloadHash=hash('sha256',json_encode([
        'companyId'=>$companyId,'userId'=>(string)$user['id'],'lineId'=>$lineId,
        'expectedSourceRevisionHash'=>$expectedRevision,'expectedEntitlementRevision'=>(int)$expectedEntitlement,
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $actionType='app:fixed_assets.depreciation_post';
    $receipt=null;
    db()->beginTransaction();
    try {
        // The company row serializes durable operation receipts. The schedule
        // row and the journal source uniqueness are the independent business-
        // occurrence boundary, so a different browser key still cannot post a
        // second journal for the same occurrence.
        $companyLock=db()->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');
        $companyLock->execute([$companyId]);
        if($companyLock->fetchColumn()===false)fail('The selected company is unavailable.',403,'company_forbidden',false);
        $prior=db()->prepare('SELECT payload_hash,status,result_json FROM ai_agent_action_authorizations WHERE company_id=? AND user_id=? AND action_type=? AND operation_key=? ORDER BY created_at DESC,id DESC LIMIT 1');
        $prior->execute([$companyId,$user['id'],$actionType,$operationKey]);
        if($priorRow=$prior->fetch()){
            if(!hash_equals((string)$priorRow['payload_hash'],$payloadHash))fail('That depreciation operation key was already used for different instructions.',409,'depreciation_operation_key_conflict',false);
            $stored=json_decode((string)($priorRow['result_json']??''),true);
            if((string)$priorRow['status']!=='completed'||!is_array($stored))fail('The earlier depreciation request is unresolved. Refresh the schedule before trying again.',409,'depreciation_operation_unresolved',false);
            $stored['idempotentReplay']=true;$stored['alreadyPosted']=false;$stored['accountingWrites']=0;
            db()->commit();json_response($stored);
        }

        $stmt = db()->prepare("SELECT dl.*,fa.name AS asset_name,fa.status AS asset_status,fa.updated_at AS asset_updated_at,
                fa.original_cost_cents,fa.salvage_value_cents,fa.depreciation_expense_account_id,fa.accumulated_depreciation_account_id
            FROM asset_depreciation_lines dl JOIN fixed_assets fa ON fa.id = dl.asset_id
            WHERE dl.id = ? AND fa.company_id = ? FOR UPDATE");
        $stmt->execute([$lineId, $companyId]);
        $line = $stmt->fetch();
        if(!$line)fail('That depreciation occurrence is unavailable.',404,'depreciation_unavailable',false);
        if((string)$line['status']==='posted'){
            $receipt=advanced_depreciation_receipt($companyId,$lineId);
            if(!$receipt)fail('The depreciation occurrence is marked posted but its journal provenance is incomplete. Run the protected diagnostic.',409,'depreciation_posting_incomplete');
            $receipt['idempotentReplay']=false;$receipt['alreadyPosted']=true;$receipt['accountingWrites']=0;
            $receipt['requestReference']=request_id();
            db()->commit();json_response($receipt);
        }
        if((string)$line['status']!=='planned'||(string)$line['asset_status']!=='active')fail('Choose an available planned depreciation occurrence.',409,'depreciation_unavailable');
        $actualRevision=advanced_depreciation_source_revision($line);
        if(!hash_equals($actualRevision,$expectedRevision))fail('The depreciation schedule changed. Refresh before posting.',409,'depreciation_revision_stale');
        $date = (string)$line['period_end'];
        if ($date > canadian_today()) fail('Future depreciation cannot be posted.',422,'depreciation_not_due');
        if($company['books_start_date']!==null&&$date<(string)$company['books_start_date'])fail('This depreciation predates Start of Books. Use the protected opening workflow.',409,'depreciation_before_books_start');
        // Lock both period-control sources before the canonical posting engine
        // rechecks them. This closes the race between preview and commit.
        if(schema_table_exists('accounting_controls')){
            $controlLock=db()->prepare('SELECT company_id,closed_through_date FROM accounting_controls WHERE company_id=? FOR UPDATE');
            $controlLock->execute([$companyId]);$control=$controlLock->fetch();
            if($control&&$control['closed_through_date']!==null&&$date<=(string)$control['closed_through_date'])fail('This date is in a locked period. Reopen the period before posting.',409,'period_locked',false);
        }
        if(schema_table_exists('period_locks')){
            $periodLock=db()->prepare('SELECT id FROM period_locks WHERE company_id=? AND locked=1 AND ? BETWEEN period_start AND period_end LIMIT 1 FOR UPDATE');
            $periodLock->execute([$companyId,$date]);
            if($periodLock->fetchColumn()!==false)fail('This date is in a locked period. Reopen the period before posting.',409,'period_locked',false);
        }
        $postedTotal=db()->prepare("SELECT COALESCE(SUM(depreciation_cents),0) FROM asset_depreciation_lines WHERE asset_id=? AND status='posted'");
        $postedTotal->execute([$line['asset_id']]);
        $remainingBase=(int)$line['original_cost_cents']-(int)$line['salvage_value_cents']-(int)$postedTotal->fetchColumn();
        if((int)$line['depreciation_cents']>$remainingBase)fail('This depreciation would reduce book value below the retained residual value.',409,'depreciation_residual_overrun');
        advanced_depreciation_test_failure('occurrence_claim',$company,$input);
        $entryId = add_journal_entry($user, $companyId, $date, 'asset_depreciation', $lineId,
            'Depreciation · ' . (string)$line['asset_name'], [
                ['accountId'=>(string)$line['depreciation_expense_account_id'],'debitCents'=>(int)$line['depreciation_cents'],'creditCents'=>0,'memo'=>'Depreciation expense · '.(string)$line['asset_name']],
                ['accountId'=>(string)$line['accumulated_depreciation_account_id'],'debitCents'=>0,'creditCents'=>(int)$line['depreciation_cents'],'memo'=>'Accumulated depreciation · '.(string)$line['asset_name']],
            ]);
        advanced_depreciation_test_failure('journal_header_and_lines',$company,$input);
        $voucherId=function_exists('voucher_register_saved')?voucher_register_saved($user,$companyId,'DP','GL','asset_depreciation',$lineId,$date,'Depreciation · '.(string)$line['asset_name'],(int)$line['depreciation_cents'],$entryId,true):'';
        advanced_depreciation_test_failure('source_link',$company,$input);
        $update=db()->prepare("UPDATE asset_depreciation_lines SET status='posted',journal_entry_id=?,posted_by=?,posted_at=UTC_TIMESTAMP() WHERE id=? AND status='planned' AND journal_entry_id IS NULL");
        $update->execute([$entryId,$user['id'],$lineId]);
        if($update->rowCount()!==1)throw new RuntimeException('The depreciation occurrence could not be claimed exactly once.');
        advanced_depreciation_test_failure('schedule_update',$company,$input);
        $remaining = db()->prepare("SELECT COUNT(*) FROM asset_depreciation_lines WHERE asset_id = ? AND status = 'planned'");
        $remaining->execute([(string)$line['asset_id']]);
        if ((int)$remaining->fetchColumn() === 0) db()->prepare("UPDATE fixed_assets SET status = 'fully_depreciated' WHERE id = ?")->execute([(string)$line['asset_id']]);
        $receipt=advanced_depreciation_receipt($companyId,$lineId)??throw new RuntimeException('The committed depreciation receipt could not be constructed.');
        $receipt['voucher']['id']=$voucherId!==''?$voucherId:$receipt['voucher']['id'];$receipt['idempotentReplay']=false;$receipt['alreadyPosted']=false;
        $receipt['requestReference']=request_id();
        audit_event($user,$companyId,'asset.depreciation_posted','fixed_asset',(string)$line['asset_id'],[
            'depreciationLineId'=>$lineId,'date'=>$date,'amountCents'=>(int)$line['depreciation_cents'],'journalEntryId'=>$entryId,
            'voucherId'=>$voucherId,'operationKeyHash'=>$operationKeyHash,'payloadHash'=>$payloadHash,'sourceRevisionHash'=>$actualRevision,
            'entitlementRevision'=>(int)$expectedEntitlement,'receipt'=>$receipt,
        ]);
        advanced_depreciation_test_failure('success_audit',$company,$input);
        db()->prepare("INSERT INTO ai_agent_action_authorizations
            (id,company_id,user_id,action_type,payload_json,payload_hash,status,expires_at,authorized_at,completed_at,journal_entry_id,operation_key,result_status,result_json)
            VALUES (?,?,?,?,?,?,'completed',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,?,'completed',?)")
            ->execute([
                new_id('depreceipt'),$companyId,$user['id'],$actionType,
                json_encode(['depreciationLineId'=>$lineId,'sourceRevisionHash'=>$expectedRevision,'entitlementRevision'=>(int)$expectedEntitlement],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                $payloadHash,$entryId,$operationKey,json_encode($receipt,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            ]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        if($error instanceof PDOException&&(string)$error->getCode()==='23000'){
            // A different-key race is resolved by the database source identity.
            $existing=advanced_depreciation_receipt($companyId,$lineId);
            if($existing){$existing['idempotentReplay']=false;$existing['alreadyPosted']=true;$existing['accountingWrites']=0;$existing['requestReference']=request_id();json_response($existing);}
            fail('That depreciation occurrence was completed concurrently. Refresh the schedule.',409,'depreciation_concurrent_change',false);
        }
        throw $error;
    }
    json_response($receipt??['ok'=>true]);
}

function advanced_validate_recurring_journal_lines(string $companyId, mixed $source): array
{
    if (!is_array($source) || count($source) < 2 || count($source) > 100) fail('A recurring journal requires between 2 and 100 lines.');
    $lines = [];
    $debits = 0;
    $credits = 0;
    foreach ($source as $index => $row) {
        if (!is_array($row)) fail('A recurring journal line is invalid.');
        $accountId = clean_text($row['accountId'] ?? '', 'Recurring journal account', 64);
        advanced_account_row($companyId, $accountId);
        $debit = safe_cents($row['debitCents'] ?? 0, 'Debit amount', true);
        $credit = safe_cents($row['creditCents'] ?? 0, 'Credit amount', true);
        if ($debit < 0 || $credit < 0 || (($debit > 0) === ($credit > 0))) fail('Each recurring line must have exactly one positive debit or credit.');
        $debits += $debit;
        $credits += $credit;
        $lines[] = [$accountId, $debit, $credit, mb_substr(trim((string)($row['memo'] ?? '')), 0, 500), $index];
    }
    if ($debits <= 0 || $debits !== $credits) fail('Recurring journal debits and credits must balance exactly.');
    return $lines;
}

function handle_advanced_recurring_journals(): never
{
    require_method('POST', 'PATCH');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    if (request_method() === 'PATCH') {
        $templateId = clean_text($input['templateId'] ?? '', 'Recurring journal', 64);
        $active = !empty($input['active']);
        $nextRunDate = array_key_exists('nextRunDate', $input) ? safe_date($input['nextRunDate'], 'Next run date') : null;
        $stmt = db()->prepare('UPDATE recurring_journal_templates SET active = ?, next_run_date = COALESCE(?, next_run_date), anchor_day = COALESCE(?, anchor_day) WHERE id = ? AND company_id = ?');
        $stmt->execute([$active ? 1 : 0, $nextRunDate, $nextRunDate !== null ? (int)substr($nextRunDate, 8, 2) : null, $templateId, $companyId]);
        $exists = db()->prepare('SELECT COUNT(*) FROM recurring_journal_templates WHERE id = ? AND company_id = ?');
        $exists->execute([$templateId, $companyId]);
        if ((int)$exists->fetchColumn() !== 1) fail('Choose an available recurring journal.');
        audit_event($user, $companyId, $nextRunDate !== null ? 'recurring_journal.schedule_updated' : ($active ? 'recurring_journal.activated' : 'recurring_journal.paused'), 'recurring_journal', $templateId, ['nextRunDate' => $nextRunDate]);
        json_response(['recurringJournal' => ['id' => $templateId, 'active' => $active, 'nextRunDate' => $nextRunDate]]);
    }
    $name = clean_text($input['name'] ?? '', 'Recurring journal name', 160);
    $memo = mb_substr(trim((string)($input['memo'] ?? '')), 0, 500);
    $frequency = advanced_valid_frequency($input['frequency'] ?? 'monthly');
    $nextRunDate = safe_date($input['nextRunDate'] ?? '', 'Next run date');
    $endDate = optional_text($input['endDate'] ?? null, 10);
    if ($endDate !== null) {
        $endDate = safe_date($endDate, 'End date');
        if ($endDate < $nextRunDate) fail('End date cannot precede the next run date.');
    }
    $lines = advanced_validate_recurring_journal_lines($companyId, $input['lines'] ?? null);
    $templateId = new_id('recjournal');
    db()->beginTransaction();
    try {
        db()->prepare('INSERT INTO recurring_journal_templates (id, company_id, name, memo, frequency, anchor_day, next_run_date, end_date, active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)')
            ->execute([$templateId, $companyId, $name, $memo, $frequency, (int)substr($nextRunDate, 8, 2), $nextRunDate, $endDate, $user['id']]);
        $insert = db()->prepare('INSERT INTO recurring_journal_lines (id, template_id, account_id, debit_cents, credit_cents, memo, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($lines as [$accountId, $debit, $credit, $lineMemo, $sort]) $insert->execute([new_id('recjline'), $templateId, $accountId, $debit, $credit, $lineMemo, $sort]);
        audit_event($user, $companyId, 'recurring_journal.created', 'recurring_journal', $templateId, ['name' => $name, 'frequency' => $frequency, 'nextRunDate' => $nextRunDate]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['recurringJournal' => ['id' => $templateId]], 201);
}

function handle_advanced_recurring_journal_run(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    $templateId = clean_text($input['templateId'] ?? '', 'Recurring journal', 64);
    db()->beginTransaction();
    try {
        $stmt = db()->prepare('SELECT * FROM recurring_journal_templates WHERE id = ? AND company_id = ? FOR UPDATE');
        $stmt->execute([$templateId, $companyId]);
        $template = $stmt->fetch();
        if (!$template || !(bool)$template['active']) fail('Choose an active recurring journal.');
        $runDate = (string)$template['next_run_date'];
        if ($runDate > canadian_today()) fail('This recurring journal is not due yet.');
        if ($template['end_date'] !== null && $runDate > (string)$template['end_date']) fail('This recurring journal has ended.');
        $lineStmt = db()->prepare('SELECT * FROM recurring_journal_lines WHERE template_id = ? ORDER BY sort_order, id');
        $lineStmt->execute([$templateId]);
        $lines = array_map(static fn(array $row): array => [
            'accountId' => (string)$row['account_id'], 'debitCents' => (int)$row['debit_cents'],
            'creditCents' => (int)$row['credit_cents'], 'memo' => (string)$row['memo'],
        ], $lineStmt->fetchAll());
        $sourceId = $templateId . ':' . $runDate;
        $entryId = add_journal_entry($user, $companyId, $runDate, 'recurring_journal', $sourceId,
            (string)($template['memo'] !== '' ? $template['memo'] : $template['name']), $lines);
        $next = advanced_next_date($runDate, (string)$template['frequency'], (int)$template['anchor_day']);
        $active = $template['end_date'] === null || $next <= (string)$template['end_date'];
        db()->prepare('UPDATE recurring_journal_templates SET next_run_date = ?, active = ? WHERE id = ?')
            ->execute([$next, $active ? 1 : 0, $templateId]);
        audit_event($user, $companyId, 'recurring_journal.posted', 'recurring_journal', $templateId, ['runDate' => $runDate, 'journalEntryId' => $entryId, 'nextRunDate' => $next, 'active' => $active]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['recurringJournal' => ['id' => $templateId, 'postedDate' => $runDate, 'nextRunDate' => $next, 'active' => $active]]);
}

function advanced_validate_recurring_invoice_lines(mixed $source): array
{
    if (!is_array($source) || count($source) < 1 || count($source) > 100) fail('A recurring invoice requires between 1 and 100 lines.');
    $lines = [];
    foreach ($source as $index => $row) {
        if (!is_array($row)) fail('A recurring invoice line is invalid.');
        $description = clean_text($row['description'] ?? '', 'Recurring invoice line description', 500);
        $quantityRaw = $row['quantity'] ?? 0;
        if (!is_numeric($quantityRaw) || !is_finite((float)$quantityRaw)) fail('Recurring invoice quantity must be numeric.');
        $quantityMilli = (int)round((float)$quantityRaw * 1000);
        $unitPrice = safe_cents($row['unitPriceCents'] ?? 0, 'Recurring invoice line rate');
        if ($quantityMilli <= 0 || $quantityMilli > 1_000_000 || $unitPrice <= 0) fail('Recurring invoice quantity and rate must be positive.');
        $lines[] = [$description, $quantityMilli, $unitPrice, !empty($row['taxable']) ? 1 : 0, $index];
    }
    return $lines;
}

function handle_advanced_recurring_invoices(): never
{
    require_method('POST', 'PATCH');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    if (request_method() === 'PATCH') {
        $profileId = clean_text($input['profileId'] ?? '', 'Recurring invoice', 64);
        $active = !empty($input['active']);
        $nextInvoiceDate = array_key_exists('nextInvoiceDate', $input) ? safe_date($input['nextInvoiceDate'], 'Next invoice date') : null;
        $stmt = db()->prepare('UPDATE recurring_invoice_profiles SET active = ?, next_invoice_date = COALESCE(?, next_invoice_date), anchor_day = COALESCE(?, anchor_day) WHERE id = ? AND company_id = ?');
        $stmt->execute([$active ? 1 : 0, $nextInvoiceDate, $nextInvoiceDate !== null ? (int)substr($nextInvoiceDate, 8, 2) : null, $profileId, $companyId]);
        $exists = db()->prepare('SELECT COUNT(*) FROM recurring_invoice_profiles WHERE id = ? AND company_id = ?');
        $exists->execute([$profileId, $companyId]);
        if ((int)$exists->fetchColumn() !== 1) fail('Choose an available recurring invoice.');
        audit_event($user, $companyId, $nextInvoiceDate !== null ? 'recurring_invoice.schedule_updated' : ($active ? 'recurring_invoice.activated' : 'recurring_invoice.paused'), 'recurring_invoice', $profileId, ['nextInvoiceDate' => $nextInvoiceDate]);
        json_response(['recurringInvoice' => ['id' => $profileId, 'active' => $active, 'nextInvoiceDate' => $nextInvoiceDate]]);
    }
    $name = clean_text($input['name'] ?? '', 'Recurring invoice name', 160);
    $customerId = clean_text($input['customerId'] ?? '', 'Customer', 64);
    $stmt = db()->prepare('SELECT COUNT(*) FROM customers WHERE id = ? AND company_id = ? AND active = 1');
    $stmt->execute([$customerId, $companyId]);
    if ((int)$stmt->fetchColumn() !== 1) fail('Choose an available customer.');
    $frequency = advanced_valid_frequency($input['frequency'] ?? 'monthly');
    $nextInvoiceDate = safe_date($input['nextInvoiceDate'] ?? '', 'Next invoice date');
    $endDate = optional_text($input['endDate'] ?? null, 10);
    if ($endDate !== null) {
        $endDate = safe_date($endDate, 'End date');
        if ($endDate < $nextInvoiceDate) fail('End date cannot precede the next invoice date.');
    }
    $terms = (int)($input['paymentTermsDays'] ?? 30);
    if ($terms < 0 || $terms > 3650) fail('Payment terms must be between 0 and 3,650 days.');
    $currency = safe_currency_code($input['currency'] ?? $company['currency'], 'Recurring invoice currency');
    if (!company_currency($companyId, $currency)) fail('Add that currency to the company before using it.');
    $templateId = optional_text($input['templateId'] ?? null, 64);
    if ($templateId !== null) invoice_template_row($companyId, $templateId);
    $message = mb_substr(trim((string)($input['message'] ?? '')), 0, 1000);
    $issueAutomatically = !empty($input['issueAutomatically']);
    $lines = advanced_validate_recurring_invoice_lines($input['lines'] ?? null);
    $profileId = new_id('recinvoice');
    db()->beginTransaction();
    try {
        db()->prepare('INSERT INTO recurring_invoice_profiles (id, company_id, customer_id, name, frequency, anchor_day, next_invoice_date,
            end_date, payment_terms_days, currency, template_id, message, issue_automatically, active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)')
            ->execute([$profileId, $companyId, $customerId, $name, $frequency, (int)substr($nextInvoiceDate, 8, 2), $nextInvoiceDate, $endDate, $terms,
                $currency, $templateId, $message, $issueAutomatically ? 1 : 0, $user['id']]);
        $insert = db()->prepare('INSERT INTO recurring_invoice_lines (id, profile_id, description, quantity_milli, foreign_unit_price_cents, taxable, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($lines as [$description, $quantityMilli, $unitPrice, $taxable, $sort]) $insert->execute([new_id('reciline'), $profileId, $description, $quantityMilli, $unitPrice, $taxable, $sort]);
        audit_event($user, $companyId, 'recurring_invoice.created', 'recurring_invoice', $profileId, ['name' => $name, 'frequency' => $frequency, 'nextInvoiceDate' => $nextInvoiceDate, 'issueAutomatically' => $issueAutomatically]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['recurringInvoice' => ['id' => $profileId]], 201);
}

function handle_advanced_recurring_invoice_run(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    $profileId = clean_text($input['profileId'] ?? '', 'Recurring invoice', 64);
    db()->beginTransaction();
    try {
        $stmt = db()->prepare('SELECT * FROM recurring_invoice_profiles WHERE id = ? AND company_id = ? FOR UPDATE');
        $stmt->execute([$profileId, $companyId]);
        $profile = $stmt->fetch();
        if (!$profile || !(bool)$profile['active']) fail('Choose an active recurring invoice.');
        $issueDate = (string)$profile['next_invoice_date'];
        if ($issueDate > canadian_today()) fail('This recurring invoice is not due yet.');
        if ($profile['end_date'] !== null && $issueDate > (string)$profile['end_date']) fail('This recurring invoice has ended.');
        $customerStmt = db()->prepare('SELECT id, name, email, phone, billing_address, province FROM customers WHERE id = ? AND company_id = ? AND active = 1');
        $customerStmt->execute([(string)$profile['customer_id'], $companyId]);
        $customer = $customerStmt->fetch();
        if (!$customer) fail('The recurring invoice customer is unavailable.');
        $currencyRow = company_currency($companyId, (string)$profile['currency']);
        if (!$currencyRow) fail('The recurring invoice currency is unavailable.');
        $exchangeRate = safe_exchange_rate_micros($currencyRow['rate_to_base_micros'], (string)$profile['currency'], (string)$company['currency']);
        $template = invoice_template_row($companyId, $profile['template_id'] !== null ? (string)$profile['template_id'] : null);
        $lineStmt = db()->prepare('SELECT * FROM recurring_invoice_lines WHERE profile_id = ? ORDER BY sort_order, id');
        $lineStmt->execute([$profileId]);
        $sourceLines = $lineStmt->fetchAll();
        if (count($sourceLines) === 0) fail('The recurring invoice has no lines.');
        $supplyProvince = (string)($customer['province'] ?: $company['province']);
        // R135: a PST-registered seller charges PST by default on taxable lines supplied in its own province.
        $pstRate = (bool)($company['pst_registered'] ?? false) && strtoupper($supplyProvince) === strtoupper((string)$company['province']) ? company_pst_rate_mpct($company) : 0; // thousandths of a percent
        $calculated = [];
        $foreignSubtotal = $foreignTax = $subtotal = $tax = $pst = 0;
        $codesMode = function_exists('tax_setup_mode') && tax_setup_mode($company) === 'codes';
        $regionCode = $codesMode ? tax_code_for_region($companyId, $supplyProvince) : null; $taxRows = [];
        foreach ($sourceLines as $row) {
            $foreignAmount = (int)round(((int)$row['quantity_milli'] * (int)$row['foreign_unit_price_cents']) / 1000);
            if ($codesMode) {
                // R137: taxable recurring lines use the customer's province tax code.
                $lineCode = (bool)$row['taxable'] ? $regionCode : null;
                $calc = $lineCode ? tax_code_compute($lineCode, $foreignAmount, 'exclusive') : ['tax' => 0, 'parts' => []];
                $baseAmount = convert_to_base_cents($foreignAmount, $exchangeRate);
                $baseTax = convert_to_base_cents($foreignAmount + (int)$calc['tax'], $exchangeRate) - $baseAmount;
                if ($lineCode) tax_rows_add($taxRows, $lineCode, $calc['parts'], tax_allocate($baseTax, $calc['parts']), $baseAmount, 'sales');
                $calculated[] = ['description' => (string)$row['description'], 'quantityMilli' => (int)$row['quantity_milli'],
                    'foreignUnitPriceCents' => (int)$row['foreign_unit_price_cents'], 'foreignAmountCents' => $foreignAmount,
                    'foreignTaxCents' => (int)$calc['tax'], 'unitPriceCents' => convert_to_base_cents((int)$row['foreign_unit_price_cents'], $exchangeRate),
                    'amountCents' => $baseAmount, 'taxCents' => $baseTax, 'taxRateBps' => $lineCode ? (int)round($lineCode['totalRateMpct'] / 10) : 0, 'sortOrder' => (int)$row['sort_order'], 'taxCodeId' => $lineCode['id'] ?? null];
                $foreignSubtotal += $foreignAmount; $foreignTax += (int)$calc['tax']; $subtotal += $baseAmount; $tax += $baseTax;
                continue;
            }
            $gstRate = (bool)$row['taxable'] && (bool)$company['tax_registered'] ? province_rate_bps($supplyProvince) : 0;
            $linePstRate = (bool)$row['taxable'] ? $pstRate : 0;
            $taxRate = $gstRate + (int)round($linePstRate / 10);
            $foreignLinePst = (int)round($foreignAmount * $linePstRate / 100000);
            $foreignLineTax = (int)round($foreignAmount * $gstRate / 10000) + $foreignLinePst;
            $baseAmount = convert_to_base_cents($foreignAmount, $exchangeRate);
            $baseTotal = convert_to_base_cents($foreignAmount + $foreignLineTax, $exchangeRate);
            $baseTax = $baseTotal - $baseAmount;
            $pst += min($baseTax, convert_to_base_cents($foreignLinePst, $exchangeRate));
            $calculated[] = [
                'description' => (string)$row['description'], 'quantityMilli' => (int)$row['quantity_milli'],
                'foreignUnitPriceCents' => (int)$row['foreign_unit_price_cents'], 'foreignAmountCents' => $foreignAmount,
                'foreignTaxCents' => $foreignLineTax, 'unitPriceCents' => convert_to_base_cents((int)$row['foreign_unit_price_cents'], $exchangeRate),
                'amountCents' => $baseAmount, 'taxCents' => $baseTax, 'taxRateBps' => $taxRate, 'sortOrder' => (int)$row['sort_order'],
            ];
            $foreignSubtotal += $foreignAmount; $foreignTax += $foreignLineTax; $subtotal += $baseAmount; $tax += $baseTax;
        }
        if ($codesMode) { $taxRows = array_values($taxRows); [, $pst] = tax_rows_buckets($companyId, $taxRows); }
        $foreignTotal = $foreignSubtotal + $foreignTax;
        $total = $subtotal + $tax;
        if ($total <= 0 || $total > 100_000_000_000) fail('Recurring invoice total is outside the supported range.');
        $invoiceId = new_id('invoice');
        $number = reserve_invoice_number($companyId);
        $issue = (bool)$profile['issue_automatically'];
        if ($issue) assert_period_open($companyId, $issueDate);
        $dueDate = (new DateTimeImmutable($issueDate, new DateTimeZone('UTC')))->modify('+' . (int)$profile['payment_terms_days'] . ' days')->format('Y-m-d');
        $entryId = $issue && (string)$company['accounting_basis'] === 'accrual'
            ? add_journal_entry($user, $companyId, $issueDate, 'invoice', $invoiceId, 'Invoice ' . $number, invoice_posting_lines($companyId, $subtotal, $tax, $total, null, null, $pst, $codesMode ? $taxRows : null))
            : null;
        db()->prepare('INSERT INTO invoices (id, company_id, customer_id, number, issue_date, due_date, status,
            subtotal_cents, tax_cents, total_cents, balance_cents, message, currency, exchange_rate_micros,
            foreign_subtotal_cents, foreign_tax_cents, foreign_total_cents, foreign_balance_cents,
            purchase_order, template_id, template_snapshot_json, customer_snapshot_json, issued_journal_entry_id, is_recurring)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, 1)')
            ->execute([$invoiceId, $companyId, $profile['customer_id'], $number, $issueDate, $dueDate, $issue ? 'sent' : 'draft',
                $subtotal, $tax, $total, $total, (string)$profile['message'], (string)$profile['currency'], $exchangeRate,
                $foreignSubtotal, $foreignTax, $foreignTotal, $foreignTotal, $template['id'],
                json_encode(invoice_template_snapshot($template, $company), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode(invoice_customer_snapshot($customer), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $entryId]);
        $insertLine = db()->prepare('INSERT INTO invoice_lines (id, invoice_id, description, quantity_milli, unit_price_cents, tax_rate_bps, amount_cents, tax_cents, foreign_unit_price_cents, foreign_amount_cents, foreign_tax_cents, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($calculated as $line) $insertLine->execute([new_id('iline'), $invoiceId, $line['description'], $line['quantityMilli'], $line['unitPriceCents'], $line['taxRateBps'], $line['amountCents'], $line['taxCents'], $line['foreignUnitPriceCents'], $line['foreignAmountCents'], $line['foreignTaxCents'], $line['sortOrder']]);
        if (function_exists('invoice_record_tax_split')) invoice_record_tax_split($companyId, $invoiceId, $tax, $pst);
        if ($codesMode) { document_tax_rows_save($companyId, 'invoice', $invoiceId, $taxRows); $cs = db()->prepare('UPDATE invoice_lines SET tax_code_id=? WHERE invoice_id=? AND sort_order=?'); foreach ($calculated as $line) if (!empty($line['taxCodeId'])) $cs->execute([$line['taxCodeId'], $invoiceId, $line['sortOrder']]); }
        $next = advanced_next_date($issueDate, (string)$profile['frequency'], (int)$profile['anchor_day']);
        $active = $profile['end_date'] === null || $next <= (string)$profile['end_date'];
        db()->prepare('UPDATE recurring_invoice_profiles SET next_invoice_date = ?, active = ? WHERE id = ?')->execute([$next, $active ? 1 : 0, $profileId]);
        audit_event($user, $companyId, $issue ? 'recurring_invoice.issued' : 'recurring_invoice.draft_generated', 'recurring_invoice', $profileId, ['invoiceId' => $invoiceId, 'number' => $number, 'issueDate' => $issueDate, 'totalCents' => $total, 'nextInvoiceDate' => $next]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['invoice' => ['id' => $invoiceId, 'number' => $number, 'status' => $issue ? 'sent' : 'draft'], 'recurringInvoice' => ['id' => $profileId, 'nextInvoiceDate' => $next, 'active' => $active]]);
}

function handle_advanced_recurring_bills(): never
{
    require_method('POST', 'PATCH');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    if (request_method() === 'PATCH') {
        $profileId = clean_text($input['profileId'] ?? '', 'Recurring vendor invoice', 64);
        $active = !empty($input['active']);
        $nextBillDate = array_key_exists('nextBillDate', $input) ? safe_date($input['nextBillDate'], 'Next vendor invoice date') : null;
        $stmt = db()->prepare('UPDATE recurring_bill_profiles SET active = ?, next_bill_date = COALESCE(?, next_bill_date), anchor_day = COALESCE(?, anchor_day) WHERE id = ? AND company_id = ?');
        $stmt->execute([$active ? 1 : 0, $nextBillDate, $nextBillDate !== null ? (int)substr($nextBillDate, 8, 2) : null, $profileId, $companyId]);
        $exists = db()->prepare('SELECT COUNT(*) FROM recurring_bill_profiles WHERE id = ? AND company_id = ?');
        $exists->execute([$profileId, $companyId]);
        if ((int)$exists->fetchColumn() !== 1) fail('Choose an available recurring vendor invoice.');
        audit_event($user, $companyId, $nextBillDate !== null ? 'recurring_bill.schedule_updated' : ($active ? 'recurring_bill.activated' : 'recurring_bill.paused'), 'recurring_bill', $profileId, ['nextBillDate' => $nextBillDate]);
        json_response(['recurringBill' => ['id' => $profileId, 'active' => $active, 'nextBillDate' => $nextBillDate]]);
    }

    $name = clean_text($input['name'] ?? '', 'Recurring vendor invoice name', 160);
    $vendorId = clean_text($input['vendorId'] ?? '', 'Vendor', 64);
    $vendorStmt = db()->prepare('SELECT COUNT(*) FROM vendors WHERE id = ? AND company_id = ? AND active = 1');
    $vendorStmt->execute([$vendorId, $companyId]);
    if ((int)$vendorStmt->fetchColumn() !== 1) fail('Choose an available vendor.');
    $frequency = advanced_valid_frequency($input['frequency'] ?? 'monthly');
    $nextBillDate = safe_date($input['nextBillDate'] ?? '', 'Next vendor invoice date');
    $endDate = optional_text($input['endDate'] ?? null, 10);
    if ($endDate !== null) {
        $endDate = safe_date($endDate, 'End date');
        if ($endDate < $nextBillDate) fail('End date cannot precede the next vendor invoice date.');
    }
    $terms = filter_var($input['paymentTermsDays'] ?? 30, FILTER_VALIDATE_INT);
    if ($terms === false || $terms < 0 || $terms > 3650) fail('Payment terms must be between 0 and 3,650 days.');
    $currency = safe_currency_code($input['currency'] ?? $company['currency'], 'Recurring vendor invoice currency');
    if (!company_currency($companyId, $currency)) fail('Add that currency to the company before using it.');
    $categoryAccountId = clean_text($input['categoryAccountId'] ?? '', 'Expense or asset account', 64);
    $category = company_account($companyId, $categoryAccountId);
    if (!$category || !in_array((string)$category['account_type'], ['expense', 'asset'], true) || (bool)$category['is_control']) fail('Choose a valid non-control expense or asset account.');
    $foreignAmountCents = safe_cents($input['foreignAmountCents'] ?? 0, 'Recurring vendor invoice amount');
    if ($foreignAmountCents <= 0) fail('Recurring vendor invoice amount must be positive.');
    $applyGstHst = !empty($input['applyGstHst']);
    $applyPst = !empty($input['applyPst']);
    if ($applyGstHst && !(bool)$company['tax_registered']) fail('GST/HST is not enabled in this company tax setup.', 409, 'gst_hst_not_configured');
    if ($applyPst && (!(bool)($company['pst_registered'] ?? false) || company_pst_rate_mpct($company) <= 0)) fail('PST is not enabled in this company tax setup.', 409, 'pst_not_configured');
    $taxEntryMode = (string)($input['taxEntryMode'] ?? (($applyGstHst || $applyPst) ? 'exclusive' : 'none'));
    if (!in_array($taxEntryMode, ['none', 'exclusive', 'inclusive'], true)) fail('Choose a valid tax entry mode.');
    if (!$applyGstHst && !$applyPst) $taxEntryMode = 'none';
    $memo = mb_substr(trim((string)($input['memo'] ?? '')), 0, 500);
    $issueAutomatically = !empty($input['issueAutomatically']);
    $profileId = new_id('recbill');
    db()->prepare('INSERT INTO recurring_bill_profiles (id, company_id, vendor_id, name, frequency, anchor_day, next_bill_date, end_date,
        payment_terms_days, currency, category_account_id, foreign_amount_cents, apply_gst_hst, apply_pst, tax_entry_mode, memo,
        issue_automatically, active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)')
        ->execute([$profileId, $companyId, $vendorId, $name, $frequency, (int)substr($nextBillDate, 8, 2), $nextBillDate, $endDate,
            $terms, $currency, $categoryAccountId, $foreignAmountCents, $applyGstHst ? 1 : 0, $applyPst ? 1 : 0,
            $taxEntryMode, $memo, $issueAutomatically ? 1 : 0, $user['id']]);
    audit_event($user, $companyId, 'recurring_bill.created', 'recurring_bill', $profileId, ['name' => $name, 'frequency' => $frequency, 'nextBillDate' => $nextBillDate, 'issueAutomatically' => $issueAutomatically]);
    json_response(['recurringBill' => ['id' => $profileId]], 201);
}

function handle_advanced_recurring_bill_run(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    $profileId = clean_text($input['profileId'] ?? '', 'Recurring vendor invoice', 64);
    db()->beginTransaction();
    try {
        $stmt = db()->prepare('SELECT * FROM recurring_bill_profiles WHERE id = ? AND company_id = ? FOR UPDATE');
        $stmt->execute([$profileId, $companyId]);
        $profile = $stmt->fetch();
        if (!$profile || !(bool)$profile['active']) fail('Choose an active recurring vendor invoice.');
        $billDate = (string)$profile['next_bill_date'];
        if ($billDate > canadian_today()) fail('This recurring vendor invoice is not due yet.');
        if ($profile['end_date'] !== null && $billDate > (string)$profile['end_date']) fail('This recurring vendor invoice has ended.');
        $number = 'RB-' . str_replace('-', '', $billDate) . '-' . strtoupper(substr(hash('sha256', $profileId . ':' . $billDate), 0, 6));
        $values = bill_input_values($company, [
            'vendorId' => (string)$profile['vendor_id'], 'number' => $number, 'billDate' => $billDate,
            'paymentTermsDays' => (int)$profile['payment_terms_days'], 'categoryAccountId' => (string)$profile['category_account_id'],
            'currency' => (string)$profile['currency'], 'foreignAmountCents' => (int)$profile['foreign_amount_cents'],
            'applyGstHst' => (bool)$profile['apply_gst_hst'], 'applyPst' => (bool)$profile['apply_pst'],
            'taxEntryMode' => (string)$profile['tax_entry_mode'], 'memo' => (string)$profile['memo'],
        ]);
        $billId = new_id('bill');
        $issue = (bool)$profile['issue_automatically'];
        if ($issue) assert_period_open($companyId, $values['billDate']);
        if (function_exists('voucher_register_saved')) voucher_register_saved($user, $companyId, 'VI', 'AP', 'bill', $billId, $values['billDate'], 'Vendor invoice ' . $values['number'], $values['total'], null, false);
        $entryId = $issue && (string)$company['accounting_basis'] === 'accrual'
            ? add_journal_entry($user, $companyId, $values['billDate'], 'bill', $billId,
                bill_posting_description((string)$values['vendor']['name'], $values['number'], $values['billDate'], (string)$values['category']['name']),
                (!empty($values['codesMode']) ? bill_posting_lines_rows($companyId, $values['categoryId'], $values['subtotal'], $values['taxRows'], $values['total']) : bill_posting_lines($companyId, $values['categoryId'], $values['subtotal'], $values['gst'], $values['pst'], $values['total'], (bool)($company['pst_recoverable'] ?? false))))
            : null;
        db()->prepare('INSERT INTO bills (id,company_id,vendor_id,number,bill_date,due_date,status,category_account_id,payment_terms_days,subtotal_cents,gst_hst_cents,pst_cents,tax_cents,tax_entry_mode,total_cents,balance_cents,currency,exchange_rate_micros,foreign_subtotal_cents,foreign_gst_hst_cents,foreign_pst_cents,foreign_tax_cents,foreign_total_cents,foreign_balance_cents,memo,issued_journal_entry_id,is_recurring) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)')
            ->execute([$billId,$companyId,$values['vendorId'],$values['number'],$values['billDate'],$values['dueDate'],$issue?'open':'draft',$values['categoryId'],$values['termsDays'],$values['subtotal'],$values['gst'],$values['pst'],$values['taxTotal'],$values['mode'],$values['total'],$values['total'],$values['currency'],$values['rate'],$values['foreignSubtotal'],$values['foreignGst'],$values['foreignPst'],$values['foreignGst']+$values['foreignPst'],$values['foreignTotal'],$values['foreignTotal'],$values['memo'],$entryId]);
        if (!empty($values['codesMode'])) { document_tax_rows_save($companyId, 'bill', $billId, $values['taxRows']); db()->prepare('UPDATE bills SET tax_code_id=? WHERE id=?')->execute([$values['taxCodeId'], $billId]); }
        if ($issue && function_exists('voucher_mark_posted')) voucher_mark_posted($user, $companyId, 'bill', $billId, $entryId);
        $next = advanced_next_date($billDate, (string)$profile['frequency'], (int)$profile['anchor_day']);
        $active = $profile['end_date'] === null || $next <= (string)$profile['end_date'];
        db()->prepare('UPDATE recurring_bill_profiles SET next_bill_date = ?, active = ? WHERE id = ?')->execute([$next, $active ? 1 : 0, $profileId]);
        audit_event($user, $companyId, $issue ? 'recurring_bill.issued' : 'recurring_bill.draft_generated', 'recurring_bill', $profileId, ['billId' => $billId, 'number' => $number, 'billDate' => $billDate, 'totalCents' => $values['total'], 'nextBillDate' => $next]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['bill' => ['id' => $billId, 'number' => $number, 'status' => $issue ? 'open' : 'draft'], 'recurringBill' => ['id' => $profileId, 'nextBillDate' => $next, 'active' => $active]]);
}

function handle_advanced_followups(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    $invoiceId = clean_text($input['invoiceId'] ?? '', 'Invoice', 64);
    $actionDate = safe_date($input['actionDate'] ?? canadian_today(), 'Follow-up date');
    assert_not_future_date($actionDate, 'Follow-up date');
    $level = (string)($input['level'] ?? 'friendly');
    if (!in_array($level, ['friendly', 'firm', 'final'], true)) fail('Follow-up level is invalid.');
    $channel = (string)($input['channel'] ?? 'email');
    if (!in_array($channel, ['email', 'phone', 'letter', 'other'], true)) fail('Follow-up channel is invalid.');
    $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 1000);
    $stmt = db()->prepare("SELECT id, number, due_date, balance_cents FROM invoices WHERE id = ? AND company_id = ? AND status = 'sent' AND balance_cents > 0");
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch();
    if (!$invoice) fail('Choose an open issued invoice.');
    $id = new_id('followup');
    db()->prepare('INSERT INTO invoice_followups (id, company_id, invoice_id, action_date, level, channel, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $companyId, $invoiceId, $actionDate, $level, $channel, $note, $user['id']]);
    audit_event($user, $companyId, 'invoice.followup_recorded', 'invoice', $invoiceId, ['followupId' => $id, 'level' => $level, 'channel' => $channel, 'actionDate' => $actionDate]);
    json_response(['followup' => ['id' => $id]], 201);
}

function handle_advanced_cash_flow(): never
{
    require_method('GET');
    $user = require_user();
    $company = require_company($user);
    $start = safe_date($_GET['start'] ?? fiscal_period_start(canadian_today(), (string)$company['fiscal_year_end']), 'Cash-flow start date');
    $end = safe_date($_GET['end'] ?? canadian_today(), 'Cash-flow end date');
    if ($end < $start) fail('Cash-flow end date cannot precede the start date.');
    json_response(['cashFlow' => advanced_cash_flow_data((string)$company['id'], $start, $end)]);
}

function handle_advanced_cash_flow_mappings(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    $accountId = clean_text($input['accountId'] ?? '', 'Account', 64);
    $account = advanced_account_row($companyId, $accountId);
    if ((bool)$account['is_control']) fail('Control accounts use their source transaction classification and cannot be remapped here.');
    $activity = (string)($input['activity'] ?? '');
    if (!in_array($activity, ['operating', 'investing', 'financing', 'exchange_effects'], true)) fail('Cash-flow activity is invalid.');
    db()->prepare('INSERT INTO cash_flow_mappings (company_id, account_id, activity, updated_by) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE activity = VALUES(activity), updated_by = VALUES(updated_by)')
        ->execute([$companyId, $accountId, $activity, $user['id']]);
    audit_event($user, $companyId, 'cash_flow_mapping.updated', 'account', $accountId, ['accountCode' => $account['code'], 'activity' => $activity]);
    json_response(['mapping' => ['accountId' => $accountId, 'activity' => $activity]]);
}

function handle_advanced(string $subroute): never
{
    if ($subroute === '' || $subroute === 'workspace') handle_advanced_workspace();
    if ($subroute === 'analytic-accounts') handle_advanced_analytic_accounts();
    if ($subroute === 'analytic-allocations') handle_advanced_analytic_allocations();
    if ($subroute === 'budgets') handle_advanced_budgets();
    if ($subroute === 'assets') handle_advanced_assets();
    if ($subroute === 'assets/post-depreciation') handle_advanced_asset_post();
    if ($subroute === 'assets/depreciation-diagnostic') handle_advanced_depreciation_diagnostic();
    if ($subroute === 'assets/depreciation-remediation') handle_advanced_depreciation_remediation();
    if ($subroute === 'recurring-journals') handle_advanced_recurring_journals();
    if ($subroute === 'recurring-journals/run') handle_advanced_recurring_journal_run();
    if ($subroute === 'recurring-invoices') handle_advanced_recurring_invoices();
    if ($subroute === 'recurring-invoices/run') handle_advanced_recurring_invoice_run();
    if ($subroute === 'recurring-bills') handle_advanced_recurring_bills();
    if ($subroute === 'recurring-bills/run') handle_advanced_recurring_bill_run();
    if ($subroute === 'followups') handle_advanced_followups();
    if ($subroute === 'cash-flow') handle_advanced_cash_flow();
    if ($subroute === 'cash-flow-mappings') handle_advanced_cash_flow_mappings();
    fail('Advanced accounting route not found.', 404, 'route_not_found');
}
