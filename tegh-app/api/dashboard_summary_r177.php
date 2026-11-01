<?php
declare(strict_types=1);

/**
 * R177: one read-only answer for the new dashboard (GET dashboard/summary).
 *
 * Every figure comes from code the app already uses, so the new dashboard and the classic one agree:
 *  - headline figures: tegh_workspace_summary_data() (workspace/summary, with Dashboard Figures mappings);
 *  - cash forecast: financial_cash_forecast() (reports/v5600/forecast); past weeks: financial_opening_cash();
 *  - who owes you: tegh_command_aging_data() (the receivables ageing used by the Command Centre);
 *  - profit trend and last year: tegh_ins_profit() (posted income and expense accounts).
 * Nothing here writes. Each part is worked out on its own: a part the person's role may not see, or a part that
 * fails, is left out (null) and listed in `unavailable`, and the page shows that panel's empty or error state.
 */

require_once __DIR__ . '/workspace_summary_v5610.php';
require_once __DIR__ . '/insights_r144.php';

const R177_HISTORY_WEEKS = 8;
const R177_FORECAST_WEEKS = 13;
const R177_MONTHS = 12;

/** Run one part; a TeghServiceFailure (fail() inside the part) becomes null plus a note in $missing. */
function r177_part(string $name, callable $fn, array &$missing): mixed
{
    $previous = $GLOBALS['tegh_service_exception_boundary'] ?? null;
    $GLOBALS['tegh_service_exception_boundary'] = true;
    try {
        return $fn();
    } catch (TeghServiceFailure $e) {
        $missing[$name] = $e->httpStatus === 403 ? 'not_permitted' : 'unavailable';
        return null;
    } catch (Throwable $e) {
        if (function_exists('record_system_incident')) {
            try { record_system_incident('A dashboard panel could not be worked out.', 500, 'dashboard_summary_part_failed', $e, ['part' => $name]); } catch (Throwable) {}
        }
        $missing[$name] = 'unavailable';
        return null;
    } finally {
        $GLOBALS['tegh_service_exception_boundary'] = $previous;
    }
}

function r177_date_add(string $date, int $days): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/** Who may open the new dashboard: the platform owner while the switch is "owner", everyone when "everyone". */
function r177_dashboard_allowed(array $user): bool
{
    require_once __DIR__ . '/site_settings_r174.php';
    $mode = r177_new_dashboard_mode();
    if ($mode === 'everyone') return true;
    if ($mode === 'owner') return function_exists('platform_role_for_user') && platform_role_for_user((string)$user['id']) === 'platform_owner';
    return false;
}

function r177_dashboard_summary(array $user, array $company): array
{
    $started = hrtime(true);
    $cid = (string)$company['id'];
    $today = tegh_workspace_summary_today();
    $missing = [];
    $canReports = company_role_can((string)$company['role'], 'reports.view');

    // Headline figures: exactly the classic dashboard's numbers.
    $base = tegh_workspace_summary_data($company);
    $s = $base['summary'];
    $periodStart = (string)$s['periodStart'];
    $evidence = $base['workflowEvidence'] ?? [];

    // Setup: a company with no bank lines and no documents sees the getting-started steps.
    $invoiceCount = (int)tegh_ins_col('SELECT COUNT(*) FROM invoices WHERE company_id=?', [$cid]);
    $steps = [
        ['id' => 'company', 'title' => 'Create your company', 'done' => true],
        ['id' => 'bank-import', 'title' => 'Import a bank statement', 'done' => (int)($evidence['bankTransactions'] ?? 0) > 0],
        ['id' => 'opening-balances', 'title' => 'Add opening balances', 'done' => !empty($base['openingBalanceStatus']['posted'])],
        ['id' => 'first-invoice', 'title' => 'Send your first invoice', 'done' => $invoiceCount > 0],
    ];
    $isNew = (int)($evidence['bankTransactions'] ?? 0) === 0 && (int)($evidence['documents'] ?? 0) === 0;

    // Cash: past weeks from the ledger, next weeks from the forecast. Both use the bank ledger accounts.
    $cash = null;
    if ($canReports) {
        $cash = r177_part('cash', static function () use ($company, $today): array {
            // Past week-ends in one query, with the same accounts and rule as financial_opening_cash().
            $ends = [];
            for ($i = R177_HISTORY_WEEKS - 1; $i >= 0; $i--) $ends[] = r177_date_add($today, -7 * $i);
            $cols = implode(',', array_map(static fn(int $i): string => "COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<=? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) w$i", array_keys($ends)));
            $row = tegh_ins_rows("SELECT $cols FROM accounts a JOIN (SELECT DISTINCT ledger_account_id FROM bank_accounts WHERE company_id=? AND active=1 AND account_type='bank') cash ON cash.ledger_account_id=a.id
                LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id WHERE a.company_id=?", array_merge($ends, [(string)$company['id'], (string)$company['id']]))[0] ?? [];
            $weeks = [];
            foreach ($ends as $i => $end) $weeks[] = ['weekEnding' => $end, 'balanceCents' => (int)($row["w$i"] ?? 0), 'forecast' => false];
            $start = r177_date_add($today, 1);
            $forecast = financial_cash_forecast($company, null, ['start' => $start, 'end' => r177_date_add($start, R177_FORECAST_WEEKS * 7 - 1), 'bucketUnit' => 'week']);
            foreach ($forecast['points'] as $p) $weeks[] = ['weekEnding' => (string)$p['periodEnd'], 'balanceCents' => (int)$p['closingCents'], 'forecast' => true];
            return [
                'weeks' => $weeks,
                'openingCents' => (int)$forecast['openingCashCents'],
                'minimumProjectedCents' => (int)$forecast['minimumProjectedCashCents'],
                'minimumProjectedDate' => (string)$forecast['minimumProjectedCashDate'],
                'firstNegativeDate' => $forecast['firstNegativeCashDate'],
                'planningNotice' => (string)($forecast['planningNotice'] ?? ''),
            ];
        }, $missing);
    } else {
        $missing['cash'] = 'not_permitted';
    }

    // Money in and money out by month, from bank lines (the same rule as workspace/summary's cash flow).
    $monthsStart = (new DateTimeImmutable(substr($today, 0, 7) . '-01', new DateTimeZone('UTC')))->modify('-' . (R177_MONTHS - 1) . ' months')->format('Y-m-d');
    $cashFlow = r177_part('cashFlow', static function () use ($cid, $monthsStart, $today): array {
        $rows = tegh_ins_rows("SELECT DATE_FORMAT(transaction_date,'%Y-%m') m,
            COALESCE(SUM(CASE WHEN amount_cents>=0 THEN amount_cents ELSE 0 END),0) i,
            COALESCE(SUM(CASE WHEN amount_cents<0 THEN -amount_cents ELSE 0 END),0) o
            FROM bank_transactions WHERE company_id=? AND status<>'excluded' AND transaction_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(transaction_date,'%Y-%m')", [$cid, $monthsStart, $today]);
        $by = [];
        foreach ($rows as $r) $by[(string)$r['m']] = [(int)$r['i'], (int)$r['o']];
        $months = [];
        $cursor = new DateTimeImmutable($monthsStart, new DateTimeZone('UTC'));
        for ($k = 0; $k < R177_MONTHS; $k++) {
            $key = $cursor->format('Y-m');
            [$in, $out] = $by[$key] ?? [0, 0];
            $months[] = ['month' => $key, 'inCents' => $in, 'outCents' => $out];
            $cursor = $cursor->modify('+1 month');
        }
        // Months before the first bank line are left out, so a young company is not shown a row of zeros.
        while ($months && $months[0]['inCents'] === 0 && $months[0]['outCents'] === 0) array_shift($months);
        return ['months' => $months];
    }, $missing);

    // Profit this year: the headline figure plus a month-by-month running total and the same point last year.
    $profit = null;
    if ($s['netIncomeYtdCents'] !== null && ((int)$s['incomeYtdCents'] !== 0 || (int)$s['expensesYtdCents'] !== 0)) {
        $profit = r177_part('profit', static function () use ($cid, $periodStart, $today, $s, $base): array {
            $mapped = in_array('profit', $base['summary']['customMappedCards'] ?? [], true);
            $trend = [];
            if (!$mapped) {
                $cursor = new DateTimeImmutable($periodStart, new DateTimeZone('UTC'));
                while ($cursor->format('Y-m-d') <= $today) {
                    $end = min($today, $cursor->modify('last day of this month')->format('Y-m-d'));
                    $trend[] = tegh_ins_profit($cid, $periodStart, $end)['profit'];
                    $cursor = $cursor->modify('first day of next month');
                }
            }
            $lastStart = (new DateTimeImmutable($periodStart, new DateTimeZone('UTC')))->modify('-1 year')->format('Y-m-d');
            $lastEnd = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify('-1 year')->format('Y-m-d');
            $last = tegh_ins_profit($cid, $lastStart, $lastEnd);
            $income = (int)$s['incomeYtdCents'];
            return [
                'ytdCents' => (int)$s['netIncomeYtdCents'],
                'incomeCents' => $income,
                'expenseCents' => (int)$s['expensesYtdCents'],
                'marginPct' => $income > 0 ? (int)round((int)$s['netIncomeYtdCents'] * 100 / $income) : null,
                'previousYtdCents' => ($last['income'] !== 0 || $last['expense'] !== 0) ? (int)$last['profit'] : null,
                'lastYearMarginPct' => $last['income'] > 0 ? (int)round($last['profit'] * 100 / $last['income']) : null,
                'periodStart' => $periodStart,
                'trend' => $trend,
                'mapped' => $mapped,
            ];
        }, $missing);
    }

    // Who owes you, grouped with the company's own ageing cut-offs.
    $receivables = r177_part('receivables', static function () use ($company, $cid, $today): array {
        $aging = tegh_command_aging_data($company, 'receivable', $today);
        $profile = function_exists('portal_aging_profile') ? portal_aging_profile($cid) : ['ar_cutoff_1' => 30, 'ar_cutoff_2' => 60, 'ar_cutoff_3' => 90];
        [$c1, $c2, $c3] = [(int)$profile['ar_cutoff_1'], (int)$profile['ar_cutoff_2'], (int)$profile['ar_cutoff_3']];
        $labels = ['Not yet due', '1–' . $c1 . ' days', ($c1 + 1) . '–' . $c2 . ' days', ($c2 + 1) . '–' . $c3 . ' days', 'Over ' . $c3 . ' days'];
        $cents = [0, 0, 0, 0, 0];
        $late = ['count' => 0, 'cents' => 0, 'parties' => []];
        foreach ($aging['rows'] as $row) {
            $d = (int)$row['daysOverdue'];
            $b = $d <= 0 ? 0 : ($d <= $c1 ? 1 : ($d <= $c2 ? 2 : ($d <= $c3 ? 3 : 4)));
            $cents[$b] += (int)$row['balanceCents'];
            if ($d > $c1) { $late['count']++; $late['cents'] += (int)$row['balanceCents']; $late['parties'][(string)$row['party']] = true; }
        }
        $opening = (int)tegh_ins_col("SELECT COALESCE(SUM(amount_cents),0) FROM party_opening_balances WHERE company_id=? AND party_type='customer'", [$cid]);
        $buckets = [];
        foreach ($labels as $i => $label) $buckets[] = ['label' => $label, 'cents' => $cents[$i], 'followUp' => $i >= 3];
        if ($opening !== 0) $buckets[] = ['label' => 'Opening balances', 'cents' => $opening, 'followUp' => false];
        return [
            'agingTotalCents' => array_sum($cents) + $opening,
            'buckets' => array_sum($cents) + $opening === 0 ? [] : $buckets,
            'lateCount' => $late['count'], 'lateCents' => $late['cents'], 'lateAfterDays' => $c1,
            'lateParties' => array_slice(array_keys($late['parties']), 0, 3),
        ];
    }, $missing);

    // Where the money goes: posted expense accounts this fiscal year, largest five plus everything else.
    $expenses = r177_part('expenses', static function () use ($cid, $periodStart, $today): array {
        $rows = tegh_ins_rows("SELECT a.code,a.name,COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) n FROM journal_lines jl
            JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id
            WHERE je.company_id=? AND je.status='posted' AND je.entry_date BETWEEN ? AND ? AND a.account_type='expense'
            GROUP BY a.id,a.code,a.name HAVING n<>0 ORDER BY n DESC,a.code", [$cid, $periodStart, $today]);
        // Accounts with a credit balance (refunds, rebates) are shown on their own line so no bar is negative.
        $items = [];
        $other = 0;
        $credits = 0;
        foreach ($rows as $r) {
            $n = (int)$r['n'];
            if ($n < 0) $credits += $n;
            elseif (count($items) < 5) $items[] = ['label' => (string)$r['name'], 'code' => (string)$r['code'], 'cents' => $n, 'other' => false];
            else $other += $n;
        }
        if ($other !== 0) $items[] = ['label' => 'Everything else', 'code' => '', 'cents' => $other, 'other' => true];
        if ($credits !== 0) $items[] = ['label' => 'Refunds and credits', 'code' => '', 'cents' => $credits, 'other' => true, 'credit' => true];
        return ['periodStart' => $periodStart, 'periodEnd' => $today, 'totalCents' => array_sum(array_column($items, 'cents')), 'items' => $items];
    }, $missing);

    // Needs you: the same signals as the classic To Do list.
    $todo = [];
    $review = (int)$s['transactionsToReview'];
    if ($review > 0) $todo[] = ['severity' => 'warn', 'status' => 'To sort', 'icon' => 'lines', 'title' => $review . ' bank ' . ($review === 1 ? 'line' : 'lines') . ' to sort', 'detail' => 'Categorise them so your figures are complete', 'route' => 'bank-review'];
    if ($receivables && $receivables['lateCount'] > 0) {
        $who = $receivables['lateParties'];
        $more = $receivables['lateCount'] - count($who);
        $todo[] = ['severity' => 'crit', 'status' => 'Overdue', 'icon' => 'alert', 'title' => $receivables['lateCount'] . ' ' . ($receivables['lateCount'] === 1 ? 'invoice' : 'invoices') . ' more than ' . $receivables['lateAfterDays'] . ' days late',
            'detail' => implode(', ', $who) . ($more > 0 ? ' and ' . $more . ' more' : ''), 'amountCents' => $receivables['lateCents'], 'route' => 'aged-ar'];
    }
    $billsDue = r177_part('billsDue', static fn(): array => tegh_command_due_items($company, 'payable', $today, r177_date_add($today, 7)), $missing);
    if ($billsDue && count($billsDue['rows']) > 0) {
        $n = count($billsDue['rows']);
        $names = array_values(array_unique(array_map(static fn(array $r): string => (string)$r['party'], $billsDue['rows'])));
        $todo[] = ['severity' => 'warn', 'status' => 'This week', 'icon' => 'bill', 'title' => $n . ' ' . ($n === 1 ? 'bill' : 'bills') . ' due in the next 7 days', 'detail' => implode(', ', array_slice($names, 0, 2)) . (count($names) > 2 ? ' and ' . (count($names) - 2) . ' more' : ''), 'amountCents' => (int)$billsDue['totalCents'], 'route' => 'bills'];
    }
    if (!empty($s['taxSummary']) && (int)$s['taxSummary']['gstHstNetCents'] !== 0) {
        $net = (int)$s['taxSummary']['gstHstNetCents'];
        $todo[] = ['severity' => 'calm', 'status' => 'Sales tax', 'icon' => 'tax', 'title' => $net > 0 ? 'GST/HST you owe' : 'GST/HST refund due', 'detail' => 'Collected less paid on purchases, from your books', 'amountCents' => abs($net), 'route' => 'tax'];
    }
    $recon = $base['lastCompletedReconciliation'] ?? null;
    if ((int)($evidence['bankTransactions'] ?? 0) > 0) {
        $todo[] = $recon
            ? ['severity' => 'good', 'status' => 'Reconciled', 'icon' => 'check', 'title' => 'Bank reconciled', 'detail' => 'Up to ' . $recon['periodEnd'], 'route' => 'reconcile']
            : ['severity' => 'calm', 'status' => 'Not yet', 'icon' => 'check', 'title' => 'Reconcile your bank', 'detail' => 'Match your books to the bank statement', 'route' => 'reconcile'];
    }

    $duration = (hrtime(true) - $started) / 1e6;
    header('Server-Timing: dashboard-summary;dur=' . number_format($duration, 2, '.', ''));
    return [
        'contractVersion' => 1,
        'company' => ['id' => $cid, 'name' => (string)$company['name'], 'currency' => (string)$company['currency'], 'asOf' => $today, 'isNew' => $isNew, 'accountingBasis' => (string)$company['accounting_basis']],
        'setup' => ['done' => count(array_filter($steps, static fn(array $x): bool => $x['done'])), 'total' => count($steps), 'steps' => $steps],
        'headline' => [
            'bankBalanceCents' => (int)$s['bankBalanceCents'],
            'bankAccounts' => array_values(array_filter($base['bankAccounts'], static fn(array $a): bool => $a['accountType'] === 'bank')),
            'receivablesCents' => (int)$s['unpaidInvoicesCents'], 'openInvoiceCount' => (int)$s['openInvoiceCount'], 'overdueInvoicesCents' => (int)$s['overdueInvoicesCents'],
            'payablesCents' => (int)$s['payableSubledgerCents'], 'openBillCount' => (int)$s['openBillCount'], 'dueSoonBillCount' => (int)$s['dueSoonBillCount'],
            'netIncomeYtdCents' => $s['netIncomeYtdCents'] === null ? null : (int)$s['netIncomeYtdCents'],
            'periodStart' => $periodStart, 'periodEnd' => (string)($s['periodEnd'] ?? $today),
            'customMappedCards' => $s['customMappedCards'] ?? [],
        ],
        'cash' => $cash,
        'cashFlow' => $cashFlow,
        'profit' => $profit,
        'receivables' => $receivables,
        'expenses' => $expenses,
        'todo' => $todo,
        'unavailable' => $missing,
        'performance' => ['durationMs' => round($duration, 2)],
        'accountingWrites' => 0,
    ];
}

function handle_dashboard_summary(): never
{
    require_method('GET');
    $user = require_user();
    $company = require_company($user);
    if (!r177_dashboard_allowed($user)) fail('The new dashboard is not turned on for your account.', 403, 'dashboard_preview_not_enabled');
    @set_time_limit(45);
    try { db()->exec('SET SESSION MAX_EXECUTION_TIME = 30000'); } catch (Throwable) {}
    json_response(r177_dashboard_summary($user, $company));
}
