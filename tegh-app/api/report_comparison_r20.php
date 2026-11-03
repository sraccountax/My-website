<?php
declare(strict_types=1);

/** R20: calendar-month P&L columns and a fully disclosed comparison range. */
function tegh_profit_parameters_r20(array $p, array $input): array
{
    tegh_report_period_days($p['start'], $p['end']);
    $p['columnsMode'] = strtolower(trim((string)($input['columnsMode'] ?? 'total')));
    $p['comparison'] = strtolower(trim((string)($input['comparison'] ?? 'none')));
    if (!in_array($p['columnsMode'], ['total','monthly'], true)
        || !in_array($p['comparison'], ['none','prior_year','previous_period','custom'], true)) {
        fail('Choose total or monthly columns and a supported comparison.', 422, 'profit_comparison_invalid', false);
    }
    $left = tegh_report_date($p['start'], 'From date');
    $right = tegh_report_date($p['end'], 'To date');
    $p['comparisonStart'] = null; $p['comparisonEnd'] = null;
    if ($p['comparison'] === 'prior_year') {
        $p['comparisonStart'] = tegh_report_add_months_clamped($left, -12)->format('Y-m-d');
        $p['comparisonEnd'] = tegh_report_add_months_clamped($right, -12)->format('Y-m-d');
    } elseif ($p['comparison'] === 'previous_period') {
        $days = $left->diff($right)->days + 1;
        $p['comparisonEnd'] = $left->modify('-1 day')->format('Y-m-d');
        $p['comparisonStart'] = $left->modify('-'.$days.' days')->format('Y-m-d');
    } elseif ($p['comparison'] === 'custom') {
        $p['comparisonStart'] = safe_date($input['comparisonStart'] ?? '', 'Comparison from date');
        $p['comparisonEnd'] = safe_date($input['comparisonEnd'] ?? '', 'Comparison to date');
    }
    if ($p['comparison'] !== 'none') tegh_report_period_days($p['comparisonStart'], $p['comparisonEnd']);
    return $p;
}

/** Months are clipped to the inclusive chosen range; no daily proration is invented. */
function tegh_profit_months_r20(string $start, string $end): array
{
    tegh_report_period_days($start, $end);
    $cursor = tegh_report_date($start, 'From date')->modify('first day of this month');
    $months = [];
    while ($cursor->format('Y-m-d') <= $end) {
        $monthStart = $cursor->format('Y-m-d');
        $monthEnd = $cursor->modify('last day of this month')->format('Y-m-d');
        $months[] = ['key'=>'month_'.$cursor->format('Y_m').'Cents','month'=>$cursor->format('Y-m'),
            'label'=>$cursor->format('M Y'),'start'=>max($start, $monthStart),'end'=>min($end, $monthEnd),
            'partial'=>$start > $monthStart || $end < $monthEnd];
        $cursor = $cursor->modify('+1 month');
    }
    return $months;
}

/** Signed change. Percentage uses abs(prior); zero baseline is explicitly not applicable. */
function tegh_profit_variance_r20(int $current, int $prior): array
{
    return ['varianceCents'=>$current-$prior,
        'varianceBps'=>$prior === 0 ? ($current === 0 ? 0 : null) : round(($current-$prior) / abs($prior) * 10000, 2),
        'varianceBasis'=>$prior === 0 && $current !== 0 ? 'zero_baseline' : 'absolute_prior'];
}

/** Pure assembly enables exact signed/zero/partial-month tests independently of SQL. */
function tegh_profit_assemble_r20(array $company, array $d, array $p, array $monthlyRows, array $priorRows): array
{
    $months = tegh_profit_months_r20($p['start'], $p['end']);
    $monthByValue = array_column($months, 'key', 'month');
    $compare = $p['comparison'] !== 'none';
    $rows = [];
    $newRow = static fn(array $r): array => array_merge(['accountId'=>(string)$r['id'],
        'accountCode'=>(string)$r['code'],'accountName'=>(string)$r['name'],'section'=>(string)$r['account_type']],
        array_fill_keys(array_column($months,'key'),0), ['amountCents'=>0,'priorCents'=>0]);
    foreach ($monthlyRows as $raw) {
        $id = (string)$raw['id']; $key = $monthByValue[(string)$raw['month_key']] ?? null;
        if ($key === null) throw new LogicException('P&L month is outside the authorized date range.');
        $rows[$id] ??= $newRow($raw);
        $amount = (int)$raw['amount_cents'];
        $rows[$id][$key] += $amount; $rows[$id]['amountCents'] += $amount;
    }
    foreach ($priorRows as $raw) {
        $id = (string)$raw['id']; $rows[$id] ??= $newRow($raw);
        $rows[$id]['priorCents'] += (int)$raw['amount_cents'];
    }
    $rows = array_values(array_filter($rows, static function(array $r) use ($months, $compare): bool {
        if ($compare && $r['priorCents'] !== 0) return true;
        foreach ($months as $month) if ($r[$month['key']] !== 0) return true;
        return false;
    }));
    usort($rows, static fn(array $a,array $b): int =>
        ($a['section']==='income'?0:1) <=> ($b['section']==='income'?0:1) ?: strnatcasecmp($a['accountCode'],$b['accountCode']));
    $zero = array_merge(array_fill_keys(array_column($months,'key'),0), ['amountCents'=>0,'priorCents'=>0]);
    $groups = ['income'=>$zero,'expense'=>$zero];
    foreach ($rows as &$row) {
        if (!isset($groups[$row['section']])) throw new LogicException('Unexpected P&L account section.');
        foreach ($zero as $key=>$_) $groups[$row['section']][$key] += $row[$key];
        if ($compare) $row += tegh_profit_variance_r20($row['amountCents'],$row['priorCents']);
    } unset($row);
    $net = $zero;
    foreach ($zero as $key=>$_) $net[$key] = $groups['income'][$key] - $groups['expense'][$key];
    if ($compare) {
        foreach ($groups as &$group) $group += tegh_profit_variance_r20($group['amountCents'],$group['priorCents']);
        unset($group); $net += tegh_profit_variance_r20($net['amountCents'],$net['priorCents']);
    }
    $columns = tegh_report_columns_5980([['accountCode','Account','identifier',12],['accountName','Account name','text',34],['section','Section','text',15]]);
    if ($p['columnsMode']==='monthly') foreach ($months as $month) $columns[] = ['key'=>$month['key'],
        'label'=>$month['label'].($month['partial']?' (partial)':''),'type'=>'money','width'=>18];
    $columns[] = ['key'=>'amountCents','label'=>'Selected period total','type'=>'money','width'=>21];
    if ($compare) array_push($columns, ['key'=>'priorCents','label'=>'Prior period total','type'=>'money','width'=>21],
        ['key'=>'varianceCents','label'=>'Variance','type'=>'money','width'=>19],
        ['key'=>'varianceBps','label'=>'Variance %','type'=>'rate_bps','width'=>16]);
    $totals = ['incomeCents'=>$groups['income']['amountCents'],'expenseCents'=>$groups['expense']['amountCents'],'netIncomeCents'=>$net['amountCents']];
    if ($compare) $totals += ['priorIncomeCents'=>$groups['income']['priorCents'],'priorExpenseCents'=>$groups['expense']['priorCents'],
        'priorNetIncomeCents'=>$net['priorCents'],'netVarianceCents'=>$net['varianceCents']];
    $model = tegh_report_model_5980($d,$company,$p,$columns,$rows,$totals);
    $model['orientation']='landscape'; $model['months']=$months; $model['columnsMode']=$p['columnsMode'];
    $model['comparison']=['mode'=>$p['comparison'],'start'=>$p['comparisonStart'],'end'=>$p['comparisonEnd'],
        'selectedDays'=>tegh_report_period_days($p['start'],$p['end']),
        'priorDays'=>$compare?tegh_report_period_days($p['comparisonStart'],$p['comparisonEnd']):null,
        'label'=>match($p['comparison']) {'prior_year'=>'Same dates, prior year','previous_period'=>'Previous period (same number of days)','custom'=>'Custom comparison',default=>'No comparison'},
        'varianceDefinition'=>'Selected period minus prior period. Percentage = difference / absolute prior amount. A nonzero change from zero is N/A; two zeros are 0%. Amounts are not annualized or prorated.'];
    $model['groupBy']='section'; $model['groupOrder']=['income','expense'];
    $model['groupLabels']=['income'=>'Income','expense'=>'Expenses']; $model['groupTotals']=$groups;
    $model['summaryRows']=[array_merge(['accountCode'=>'','accountName'=>'Net profit / (loss)','section'=>'net','isSummary'=>true],$net)];
    $model['definitions']=['calculationAuthority'=>'Posted journal lines in one repeatable-read snapshot',
        'monthSemantics'=>'Calendar months clipped to selected inclusive dates; all company income and expense accounts are eligible, including inactive historical accounts.',
        'basisSemantics'=>'Existing posted-ledger accounting basis; no cash/accrual conversion or currency mixing.'];
    return $model;
}

function tegh_profit_report_r20(array $company, array $d, array $p): array
{
    // Aggregate once per account/month, rather than a separate full report for each month.
    $sql = "SELECT a.id,a.code,a.name,a.account_type,DATE_FORMAT(je.entry_date,'%Y-%m') month_key,
        SUM(CASE WHEN a.account_type='income' THEN jl.credit_cents-jl.debit_cents ELSE jl.debit_cents-jl.credit_cents END) amount_cents
        FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id
        JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id
        WHERE je.company_id=? AND je.status='posted' AND je.entry_date BETWEEN ? AND ?
          AND a.account_type IN ('income','expense')
        GROUP BY a.id,a.code,a.name,a.account_type,DATE_FORMAT(je.entry_date,'%Y-%m')";
    $q = db()->prepare($sql); $q->execute([$company['id'],$p['start'],$p['end']]);
    // The server report limit bounds account rows, not account-month cells.
    $monthly=[]; while($r=$q->fetch(PDO::FETCH_ASSOC)) {
        $monthly[]=$r;
        if(count($monthly)>TEGH_REPORT_LIMIT_5980*62) fail('The monthly report exceeds its complete-result limit.',413,'report_incomplete_over_limit',false);
    }
    $prior=[];
    if ($p['comparison'] !== 'none') {
        $q = db()->prepare("SELECT a.id,a.code,a.name,a.account_type,
            SUM(CASE WHEN a.account_type='income' THEN jl.credit_cents-jl.debit_cents ELSE jl.debit_cents-jl.credit_cents END) amount_cents
            FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id
            JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id
            WHERE je.company_id=? AND je.status='posted' AND je.entry_date BETWEEN ? AND ?
            AND a.account_type IN ('income','expense') GROUP BY a.id,a.code,a.name,a.account_type");
        $q->execute([$company['id'],$p['comparisonStart'],$p['comparisonEnd']]); $prior=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return tegh_profit_assemble_r20($company,$d,$p,$monthly,$prior);
}
