<?php
declare(strict_types=1);

/**
 * R144: Tegh Intelligence — insights worked out inside Tegh from the company's own books.
 *
 * No external AI service is called. Every insight says why it was raised and links to the
 * screen where a person reviews it. Nothing here posts or changes accounting records; the
 * only write is a "Not a problem" dismissal, kept per company and per insight.
 */

require_once __DIR__ . '/workspace_summary_v5610.php';

function tegh_ins_today(): string { return tegh_workspace_summary_today(); }

function tegh_ins_days(string $from, string $to): int
{
    return (int)((new DateTimeImmutable($to))->diff(new DateTimeImmutable($from))->days) * ($to >= $from ? 1 : -1);
}

function tegh_ins_add_days(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function tegh_ins_money(int $cents, string $currency = 'CAD'): string
{
    $sign = $cents < 0 ? '-' : '';
    return $sign . '$' . number_format(abs($cents) / 100, 2);
}

function tegh_ins_col(string $sql, array $params = []): mixed
{
    $q = db()->prepare($sql); $q->execute($params); return $q->fetchColumn();
}

function tegh_ins_rows(string $sql, array $params = []): array
{
    $q = db()->prepare($sql); $q->execute($params); return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Debit-minus-credit balance of the given accounts on posted entries up to $asOf (inclusive). */
function tegh_ins_balance(string $companyId, array $accountIds, string $asOf, ?string $from = null): int
{
    if (!$accountIds) return 0;
    $in = implode(',', array_fill(0, count($accountIds), '?'));
    $sql = "SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id
            WHERE je.company_id=? AND je.status='posted' AND je.entry_date<=? " . ($from !== null ? 'AND je.entry_date>=? ' : '') . "AND jl.account_id IN ($in)";
    $params = array_merge([$companyId, $asOf], $from !== null ? [$from] : [], $accountIds);
    return (int)tegh_ins_col($sql, $params);
}

function tegh_ins_accounts(string $companyId, string $where, array $params = []): array
{
    return array_map(static fn(array $r): string => (string)$r['id'], tegh_ins_rows("SELECT id FROM accounts WHERE company_id=? AND $where", array_merge([$companyId], $params)));
}

/** Bank and cash ledger accounts that hold money (assets), not credit cards. */
function tegh_ins_cash_accounts(string $companyId): array
{
    return array_map(static fn(array $r): string => (string)$r['ledger_account_id'], tegh_ins_rows(
        "SELECT DISTINCT ba.ledger_account_id FROM bank_accounts ba JOIN accounts a ON a.id=ba.ledger_account_id WHERE ba.company_id=? AND ba.active=1 AND a.account_type='asset'", [$companyId]));
}

/** Income minus expenses on posted entries between two dates. */
function tegh_ins_profit(string $companyId, string $from, string $to): array
{
    $r = tegh_ins_rows("SELECT a.account_type t, COALESCE(SUM(jl.credit_cents-jl.debit_cents),0) n FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id
        WHERE je.company_id=? AND je.status='posted' AND je.entry_date BETWEEN ? AND ? AND a.account_type IN ('income','expense') GROUP BY a.account_type", [$companyId, $from, $to]);
    $income = 0; $expense = 0;
    foreach ($r as $x) { if ($x['t'] === 'income') $income = (int)$x['n']; else $expense = -(int)$x['n']; }
    return ['income' => $income, 'expense' => $expense, 'profit' => $income - $expense];
}

function tegh_ins_dismissals_ready(): void
{
    static $ready = false; if ($ready) return;
    if (!schema_table_exists('company_insight_dismissals')) {
        if (db()->inTransaction()) return;
        db()->exec("CREATE TABLE IF NOT EXISTS company_insight_dismissals (
          company_id VARCHAR(64) NOT NULL,
          insight_key VARCHAR(190) NOT NULL,
          dismissed_by VARCHAR(64) NULL,
          dismissed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (company_id, insight_key),
          CONSTRAINT company_insight_dismissals_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready = true;
}

function tegh_ins_dismissed(string $companyId): array
{
    if (!schema_table_exists('company_insight_dismissals')) return [];
    return array_fill_keys(array_map(static fn(array $r): string => (string)$r['insight_key'], tegh_ins_rows('SELECT insight_key FROM company_insight_dismissals WHERE company_id=?', [$companyId])), true);
}

/* ------------------------------------------------------------------ anomalies */

/** Things worth a second look. Each has a stable key (dismissals stick to it), a reason and a link. */
function tegh_ins_anomalies(array $company, bool $includeDismissed = false): array
{
    $cid = (string)$company['id']; $today = tegh_ins_today(); $out = [];
    $add = static function (string $key, string $kind, string $severity, string $title, string $why, array $link, ?int $amount = null) use (&$out): void {
        $out[] = ['key' => $key, 'kind' => $kind, 'severity' => $severity, 'title' => $title, 'why' => $why, 'link' => $link, 'amountCents' => $amount];
    };

    // Same vendor, same total, within 14 days (or the same vendor invoice number).
    foreach (tegh_ins_rows("SELECT a.id a_id,b.id b_id,a.number a_no,b.number b_no,a.total_cents t,a.bill_date a_d,b.bill_date b_d,v.name vendor
        FROM bills a JOIN bills b ON b.company_id=a.company_id AND b.vendor_id=a.vendor_id AND b.id>a.id
          AND ((b.total_cents=a.total_cents AND ABS(DATEDIFF(b.bill_date,a.bill_date))<=14) OR (a.number<>'' AND b.number=a.number))
        JOIN vendors v ON v.id=a.vendor_id
        WHERE a.company_id=? AND a.status NOT IN ('void','draft') AND b.status NOT IN ('void','draft') AND a.bill_date>=? ORDER BY b.bill_date DESC LIMIT 20", [$cid, tegh_ins_add_days($today, -365)]) as $r) {
        $same = $r['a_no'] !== '' && $r['a_no'] === $r['b_no'];
        $add('dup_bill:' . $r['a_id'] . ':' . $r['b_id'], 'duplicate_bill', 'high', 'Possible duplicate vendor invoice from ' . $r['vendor'],
            $same ? "Two vendor invoices from {$r['vendor']} have the same number {$r['a_no']}."
                  : "{$r['vendor']} has two vendor invoices for " . tegh_ins_money((int)$r['t']) . " dated {$r['a_d']} and {$r['b_d']} ({$r['a_no']} and {$r['b_no']}).",
            ['menu' => ['Payables', 'Vendor Invoice Register']], (int)$r['t']);
    }
    // Same customer, same total, same date.
    foreach (tegh_ins_rows("SELECT a.id a_id,b.id b_id,a.number a_no,b.number b_no,a.total_cents t,a.issue_date d,c.name customer
        FROM invoices a JOIN invoices b ON b.company_id=a.company_id AND b.customer_id=a.customer_id AND b.id>a.id AND b.total_cents=a.total_cents AND b.issue_date=a.issue_date
        JOIN customers c ON c.id=a.customer_id
        WHERE a.company_id=? AND a.status NOT IN ('void','draft') AND b.status NOT IN ('void','draft') AND a.issue_date>=? LIMIT 20", [$cid, tegh_ins_add_days($today, -365)]) as $r) {
        $add('dup_invoice:' . $r['a_id'] . ':' . $r['b_id'], 'duplicate_invoice', 'high', 'Possible duplicate invoice to ' . $r['customer'],
            "{$r['a_no']} and {$r['b_no']} are both " . tegh_ins_money((int)$r['t']) . " to {$r['customer']} on {$r['d']}.",
            ['menu' => ['Receivables', 'Customer Invoice Register']], (int)$r['t']);
    }
    // A vendor invoice far above that vendor's usual amount.
    $recent = tegh_ins_rows("SELECT b.id,b.number,b.total_cents,b.bill_date,b.vendor_id,v.name vendor FROM bills b JOIN vendors v ON v.id=b.vendor_id
        WHERE b.company_id=? AND b.status NOT IN ('void','draft') AND b.bill_date>=? ORDER BY b.bill_date DESC LIMIT 200", [$cid, tegh_ins_add_days($today, -90)]);
    foreach ($recent as $r) {
        $hist = array_map('intval', array_column(tegh_ins_rows("SELECT total_cents FROM bills WHERE company_id=? AND vendor_id=? AND id<>? AND status NOT IN ('void','draft') ORDER BY bill_date DESC LIMIT 24", [$cid, $r['vendor_id'], $r['id']]), 'total_cents'));
        if (count($hist) < 3) continue;
        sort($hist); $mid = intdiv(count($hist), 2); $median = count($hist) % 2 ? $hist[$mid] : intdiv($hist[$mid - 1] + $hist[$mid], 2);
        $t = (int)$r['total_cents'];
        if ($median > 0 && $t >= 3 * $median && $t - $median >= 10000) {
            $add('unusual_bill:' . $r['id'], 'unusual_amount', 'medium', "Unusually large vendor invoice from {$r['vendor']}",
                ($r['number'] !== '' ? "{$r['number']} " : '') . 'is ' . tegh_ins_money($t) . ', about ' . round($t / $median, 1) . '× the usual ' . tegh_ins_money($median) . ' from this vendor (last ' . count($hist) . ' invoices).',
                ['menu' => ['Payables', 'Vendor Invoice Register']], $t);
        }
    }
    // A tax code for one region used for a customer in another.
    if (schema_table_exists('tax_codes') && function_exists('tegh_tax_region_candidates')) {
        $seen = [];
        foreach (tegh_ins_rows("SELECT i.id,i.number,c.name customer,c.country,c.province,t.code,t.region FROM invoice_lines il JOIN invoices i ON i.id=il.invoice_id
            JOIN customers c ON c.id=i.customer_id JOIN tax_codes t ON t.id=il.tax_code_id
            WHERE i.company_id=? AND i.status NOT IN ('void','draft') AND i.issue_date>=? AND t.region IS NOT NULL AND t.region<>'' LIMIT 500", [$cid, tegh_ins_add_days($today, -180)]) as $r) {
            if (isset($seen[$r['id']])) continue;
            $cands = tegh_tax_region_candidates((string)($r['country'] ?? 'Canada'), (string)($r['province'] ?? ''));
            if (!$cands || in_array((string)$r['region'], $cands, true)) continue;
            $seen[$r['id']] = true;
            $add('tax_region:' . $r['id'], 'tax_code_region', 'medium', "Tax code {$r['code']} on {$r['number']} doesn't match the customer's region",
                "{$r['customer']} is in " . ($r['province'] ?: $r['country']) . ", but {$r['number']} uses the {$r['code']} code (region {$r['region']}). Check the sales tax charged.",
                ['menu' => ['Receivables', 'Customer Invoice Register']]);
        }
    }
    // Money parked in Unassigned Expense / Opening Balance Control.
    foreach ([['6999', 'Unassigned Expense', 'Recode these amounts to the right expense accounts before month-end.'], ['9999', 'Opening Balance Control', 'Opening balances are not fully allocated; finish them in Chart of Accounts & Opening Balances.']] as [$code, $name, $hint]) {
        $ids = tegh_ins_accounts($cid, 'code=?', [$code]); $bal = tegh_ins_balance($cid, $ids, $today);
        if ($bal !== 0) $add('parked:' . $code . ':' . $bal, 'parked_balance', 'medium', "$name holds " . tegh_ins_money(abs($bal)), "$code $name has a balance of " . tegh_ins_money($bal) . ". $hint", ['menu' => ['Reports', 'Trial Balance']], abs($bal));
    }
    // Bank lines waiting a long time.
    $old = tegh_ins_rows("SELECT COUNT(*) n, MIN(transaction_date) oldest FROM bank_transactions WHERE company_id=? AND status='pending' AND transaction_date<?", [$cid, tegh_ins_add_days($today, -30)])[0] ?? ['n' => 0];
    if ((int)$old['n'] > 0) $add('stale_bank:' . $old['oldest'] . ':' . $old['n'], 'stale_bank_lines', 'medium', $old['n'] . ' bank line' . ($old['n'] == 1 ? '' : 's') . ' waiting more than 30 days',
        'The oldest unposted bank line is from ' . $old['oldest'] . '. Until they are posted or matched, the books and the bank will not agree.', ['menu' => ['Banking', 'Match and Post Transactions']]);
    // Bank account overdrawn in the books.
    foreach (tegh_ins_rows("SELECT ba.name,ba.ledger_account_id FROM bank_accounts ba JOIN accounts a ON a.id=ba.ledger_account_id WHERE ba.company_id=? AND ba.active=1 AND a.account_type='asset'", [$cid]) as $b) {
        $bal = tegh_ins_balance($cid, [(string)$b['ledger_account_id']], $today);
        if ($bal < 0) $add('negative_bank:' . $b['ledger_account_id'] . ':' . $bal, 'negative_bank', 'high', "{$b['name']} is overdrawn in the books", "{$b['name']} shows " . tegh_ins_money($bal) . '. Check for a missing deposit or a payment posted to the wrong account.', ['menu' => ['Banking', 'Manage Bank Accounts']], abs($bal));
    }
    // Verified payroll not posted.
    if (schema_table_exists('payroll_runs')) {
        foreach (tegh_ins_rows("SELECT id,pay_date,gross_pay_cents FROM payroll_runs WHERE company_id=? AND gl_status='ready_to_post' AND pay_date<?", [$cid, tegh_ins_add_days($today, -3)]) as $r) {
            $add('payroll_unposted:' . $r['id'], 'payroll_unposted', 'medium', 'Verified pay run not posted to the GL', 'The pay run dated ' . $r['pay_date'] . ' (gross ' . tegh_ins_money((int)$r['gross_pay_cents']) . ') is verified but not posted, so wages and remittances are missing from the books.', ['menu' => ['Payroll', 'Pay Run Register']], (int)$r['gross_pay_cents']);
        }
    }
    $dismissed = tegh_ins_dismissed($cid);
    foreach ($out as &$x) $x['dismissed'] = isset($dismissed[$x['key']]);
    unset($x);
    if (!$includeDismissed) $out = array_values(array_filter($out, static fn(array $x): bool => !$x['dismissed']));
    $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($out, static fn(array $a, array $b): int => [$rank[$a['severity']], -(int)$a['amountCents']] <=> [$rank[$b['severity']], -(int)$b['amountCents']]);
    return $out;
}

/* ------------------------------------------------------------------ chase list */

/** Overdue customers ranked by amount and lateness, with their payment habit. */
function tegh_ins_chase(array $company, int $limit = 5): array
{
    $cid = (string)$company['id']; $today = tegh_ins_today();
    $rows = tegh_ins_rows("SELECT c.id,c.name,COUNT(*) n,SUM(i.balance_cents) owed,MIN(i.due_date) oldest_due FROM invoices i JOIN customers c ON c.id=i.customer_id
        WHERE i.company_id=? AND i.status='sent' AND i.balance_cents>0 AND i.due_date<? GROUP BY c.id,c.name", [$cid, $today]);
    $out = [];
    foreach ($rows as $r) {
        $days = tegh_ins_days((string)$r['oldest_due'], $today);
        // Habit: of this customer's paid invoices, how many were settled after the due date.
        $paid = tegh_ins_rows("SELECT i.due_date, MAX(pa.application_date) paid_on FROM invoices i JOIN party_payment_applications pa ON pa.document_id=i.id AND pa.company_id=i.company_id AND pa.status='posted'
            WHERE i.company_id=? AND i.customer_id=? AND i.status='paid' GROUP BY i.id,i.due_date ORDER BY i.due_date DESC LIMIT 12", [$cid, $r['id']]);
        $late = count(array_filter($paid, static fn(array $p): bool => (string)$p['paid_on'] > (string)$p['due_date']));
        $habit = $paid ? ($late === 0 ? 'usually pays on time' : "paid late $late of the last " . count($paid) . ' times') : 'no payment history yet';
        $score = (int)$r['owed'] * (1 + $days / 30) * ($paid ? 1 + $late / max(1, count($paid)) : 1.2);
        $out[] = ['customerId' => (string)$r['id'], 'name' => (string)$r['name'], 'overdueCents' => (int)$r['owed'], 'invoices' => (int)$r['n'], 'oldestDaysLate' => $days, 'habit' => $habit, 'score' => round($score / 100),
            'why' => tegh_ins_money((int)$r['owed']) . ' overdue across ' . $r['n'] . ' invoice' . ($r['n'] == 1 ? '' : 's') . "; the oldest is $days day" . ($days == 1 ? '' : 's') . " late; $habit."];
    }
    usort($out, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $limit);
}

/* ------------------------------------------------------------------ cash runway */

function tegh_ins_cash(array $company): array
{
    $cid = (string)$company['id']; $today = tegh_ins_today(); $cash = tegh_ins_cash_accounts($cid);
    $balance = tegh_ins_balance($cid, $cash, $today);
    $months = [];
    $first = (new DateTimeImmutable(substr($today, 0, 7) . '-01'));
    for ($i = 6; $i >= 1; $i--) {
        $start = $first->modify("-$i months"); $end = $start->modify('last day of this month');
        $months[] = ['month' => $start->format('Y-m'), 'netChangeCents' => tegh_ins_balance($cid, $cash, $end->format('Y-m-d'), $start->format('Y-m-d'))];
    }
    $last3 = array_slice($months, -3);
    $active = array_values(array_filter($last3, static fn(array $m): bool => $m['netChangeCents'] !== 0));
    $avg = $active ? (int)round(array_sum(array_column($active, 'netChangeCents')) / count($active)) : 0;
    $burn = $avg < 0 ? -$avg : 0;
    return ['balanceCents' => $balance, 'months' => $months, 'averageMonthlyChangeCents' => $avg, 'monthlyBurnCents' => $burn,
        'runwayMonths' => $burn > 0 && $balance > 0 ? round($balance / $burn, 1) : null, 'basisMonths' => count($active)];
}

/* ------------------------------------------------------------------ morning brief */

function tegh_ins_brief(array $company): array
{
    $cid = (string)$company['id']; $today = tegh_ins_today(); $cur = (string)($company['currency'] ?? 'CAD'); $lines = [];
    $cash = tegh_ins_cash_accounts($cid);
    if ($cash) {
        $now = tegh_ins_balance($cid, $cash, $today); $before = tegh_ins_balance($cid, $cash, tegh_ins_add_days($today, -30)); $d = $now - $before;
        $lines[] = ['key' => 'cash', 'tone' => $now < 0 ? 'bad' : 'neutral', 'text' => 'Cash in the bank is ' . tegh_ins_money($now) . ($d === 0 ? ', the same as 30 days ago.' : ', ' . ($d > 0 ? 'up ' : 'down ') . tegh_ins_money(abs($d)) . ' from 30 days ago.'), 'link' => ['menu' => ['Banking', 'Manage Bank Accounts']]];
    }
    $od = tegh_ins_rows("SELECT COUNT(*) n,COALESCE(SUM(balance_cents),0) s,MIN(due_date) oldest FROM invoices WHERE company_id=? AND status='sent' AND balance_cents>0 AND due_date<?", [$cid, $today])[0];
    $lines[] = (int)$od['n'] > 0
        ? ['key' => 'overdue', 'tone' => 'warn', 'text' => $od['n'] . ' customer invoice' . ($od['n'] == 1 ? ' is' : 's are') . ' overdue (' . tegh_ins_money((int)$od['s']) . '); the oldest is ' . tegh_ins_days((string)$od['oldest'], $today) . ' days late.', 'link' => ['menu' => ['Receivables', 'Receivable Ageing']]]
        : ['key' => 'overdue', 'tone' => 'good', 'text' => 'No customer invoices are overdue.', 'link' => ['menu' => ['Receivables', 'Receivable Ageing']]];
    $due = tegh_ins_rows("SELECT COUNT(*) n,COALESCE(SUM(balance_cents),0) s FROM bills WHERE company_id=? AND status IN ('open','approved') AND balance_cents>0 AND due_date BETWEEN ? AND ?", [$cid, $today, tegh_ins_add_days($today, 7)])[0];
    $late = tegh_ins_rows("SELECT COUNT(*) n,COALESCE(SUM(balance_cents),0) s FROM bills WHERE company_id=? AND status IN ('open','approved') AND balance_cents>0 AND due_date<?", [$cid, $today])[0];
    if ((int)$late['n'] > 0) $lines[] = ['key' => 'bills_late', 'tone' => 'warn', 'text' => $late['n'] . ' vendor invoice' . ($late['n'] == 1 ? ' is' : 's are') . ' past due (' . tegh_ins_money((int)$late['s']) . ').', 'link' => ['menu' => ['Payables', 'Payable Ageing']]];
    if ((int)$due['n'] > 0) $lines[] = ['key' => 'bills_due', 'tone' => 'neutral', 'text' => $due['n'] . ' vendor invoice' . ($due['n'] == 1 ? ' is' : 's are') . ' due in the next 7 days (' . tegh_ins_money((int)$due['s']) . ').', 'link' => ['menu' => ['Payables', 'Payable Ageing']]];
    $taxNet = -tegh_ins_balance($cid, tegh_ins_accounts($cid, "code IN ('2100','1100')"), $today);
    if ($taxNet !== 0) $lines[] = ['key' => 'tax', 'tone' => 'neutral', 'text' => $taxNet > 0 ? 'You owe about ' . tegh_ins_money($taxNet) . ' in GST/HST (collected minus input tax credits).' : 'You are due a GST/HST refund of about ' . tegh_ins_money(-$taxNet) . '.', 'link' => ['action' => 'report.tax_summary']];
    $pending = (int)tegh_ins_col("SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND status='pending'", [$cid]);
    if ($pending > 0) $lines[] = ['key' => 'bank', 'tone' => 'neutral', 'text' => "$pending bank line" . ($pending == 1 ? ' is' : 's are') . ' waiting in Match and Post.', 'link' => ['menu' => ['Banking', 'Match and Post Transactions']]];
    $start = tegh_workspace_summary_period_start($today, (string)$company['fiscal_year_end']);
    $ytd = tegh_ins_profit($cid, $start, $today);
    $lyStart = (new DateTimeImmutable($start))->modify('-1 year')->format('Y-m-d'); $lyEnd = (new DateTimeImmutable($today))->modify('-1 year')->format('Y-m-d');
    $ly = tegh_ins_profit($cid, $lyStart, $lyEnd);
    $p = $ytd['profit'];
    $text = ($p >= 0 ? 'Profit so far this year is ' . tegh_ins_money($p) : 'So far this year the business has a loss of ' . tegh_ins_money(-$p));
    $text .= ($ly['income'] !== 0 || $ly['expense'] !== 0) ? ', compared with ' . ($ly['profit'] >= 0 ? 'a profit of ' : 'a loss of ') . tegh_ins_money(abs($ly['profit'])) . ' at this point last year.' : '.';
    $lines[] = ['key' => 'profit', 'tone' => $p >= 0 ? 'good' : 'bad', 'text' => $text, 'link' => ['menu' => ['Reports', 'Profit and Loss']]];
    $anomalies = tegh_ins_anomalies($company);
    if ($anomalies) $lines[] = ['key' => 'anomalies', 'tone' => 'warn', 'text' => 'Tegh noticed ' . count($anomalies) . ' thing' . (count($anomalies) == 1 ? '' : 's') . ' worth a look' . ($anomalies[0]['severity'] === 'high' ? ', starting with: ' . lcfirst($anomalies[0]['title']) . '.' : '.'), 'link' => ['page' => 'insights']];
    return ['asOf' => $today, 'lines' => $lines, 'anomalyCount' => count($anomalies)];
}

/* ------------------------------------------------------------------ bank suggestions */

/** For pending bank lines: what this company did with similar lines before, with a confidence and a reason. */
function tegh_ins_bank_suggestions(array $company, array $ids): array
{
    $cid = (string)$company['id']; $ids = array_slice(array_values(array_unique(array_map('strval', $ids))), 0, 100); if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pending = tegh_ins_rows("SELECT id,description,amount_cents,bank_account_id FROM bank_transactions WHERE company_id=? AND status='pending' AND id IN ($in)", array_merge([$cid], $ids));
    if (!$pending) return [];
    $merchant = static fn(string $d): string => function_exists('normalize_merchant') ? mb_strtoupper(trim(normalize_merchant($d))) : mb_strtoupper(trim($d));
    $history = tegh_ins_rows("SELECT bt.description,bt.amount_cents,bt.decided_account_id,bt.tax_code,a.code,a.name FROM bank_transactions bt JOIN accounts a ON a.id=bt.decided_account_id
        WHERE bt.company_id=? AND bt.status='posted' AND bt.decided_account_id IS NOT NULL ORDER BY bt.transaction_date DESC LIMIT 3000", [$cid]);
    $byMerchant = [];
    foreach ($history as $h) { $m = $merchant((string)$h['description']); if ($m === '') continue; $byMerchant[$m][] = $h; }
    $out = [];
    foreach ($pending as $p) {
        $m = $merchant((string)$p['description']); $sign = (int)$p['amount_cents'] < 0 ? -1 : 1;
        $same = array_values(array_filter($byMerchant[$m] ?? [], static fn(array $h): bool => ((int)$h['amount_cents'] < 0 ? -1 : 1) === $sign));
        $suggestion = null;
        if ($same) {
            $counts = [];
            foreach ($same as $h) { $k = $h['decided_account_id'] . '|' . ($h['tax_code'] ?? ''); $counts[$k] = ($counts[$k] ?? 0) + 1; }
            arsort($counts); $top = array_key_first($counts); $n = $counts[$top]; $total = count($same);
            [$acct, $tax] = explode('|', $top, 2); $row = current(array_filter($same, static fn(array $h): bool => $h['decided_account_id'] === $acct));
            $confidence = (int)round(100 * ($n / $total) * ($total / ($total + 1)));
            // Posted bank lines store the tax code as CODE:<code>; the posting form lists CODE:<id>.
            $taxValue = $tax !== '' ? $tax : null; $taxName = null;
            if ($tax !== '' && str_starts_with($tax, 'CODE:')) {
                $codeRow = tegh_ins_rows("SELECT id,code FROM tax_codes WHERE company_id=? AND code=? AND status<>'inactive' LIMIT 1", [$cid, substr($tax, 5)])[0] ?? null;
                $taxValue = $codeRow ? 'CODE:' . $codeRow['id'] : null; $taxName = $codeRow['code'] ?? null;
            }
            $suggestion = ['accountId' => $acct, 'accountLabel' => $row['code'] . ' · ' . $row['name'], 'taxCode' => $taxValue, 'taxName' => $taxName, 'confidence' => $confidence, 'source' => 'history',
                'reason' => "You posted \u{201C}" . mb_convert_case(mb_strtolower($m), MB_CASE_TITLE) . "\u{201D} to {$row['code']} {$row['name']}" . ($taxName ? " with the $taxName tax code" : '') . " $n time" . ($n == 1 ? '' : 's') . ($total > $n ? " (of $total similar lines)" : '') . '.'];
        }
        if (function_exists('tegh_ai_best_rule')) {
            try { $rule = tegh_ai_best_rule($company, (string)$p['description'], (string)$p['bank_account_id']); } catch (Throwable) { $rule = null; }
            $sg = is_array($rule['suggestion'] ?? null) ? $rule['suggestion'] : [];
            if ($rule && !empty($sg['accountId'])) {
                $ruleAcct = (string)$sg['accountId']; $ruleConf = (int)round(((int)($rule['confidence_bps'] ?? 0)) / 100);
                if (!$suggestion || $ruleConf > $suggestion['confidence']) {
                    $a = tegh_ins_rows('SELECT code,name FROM accounts WHERE id=? AND company_id=?', [$ruleAcct, $cid])[0] ?? null;
                    if ($a) $suggestion = ['accountId' => $ruleAcct, 'accountLabel' => $a['code'] . ' · ' . $a['name'], 'taxCode' => $sg['taxCode'] ?? null, 'confidence' => max(1, min(99, $ruleConf)), 'source' => 'learned_rule',
                        'reason' => "A learned rule matches \u{201C}" . ($rule['pattern_value'] ?? $m) . "\u{201D} (confirmed " . (int)($rule['positive_count'] ?? 0) . ' time' . ((int)($rule['positive_count'] ?? 0) === 1 ? '' : 's') . ').'];
                }
            }
        }
        if ($suggestion && is_string($suggestion['taxCode'] ?? null) && str_starts_with($suggestion['taxCode'], 'CODE:') && !str_starts_with($suggestion['taxCode'], 'CODE:taxcode_')) {
            $codeRow = tegh_ins_rows("SELECT id,code FROM tax_codes WHERE company_id=? AND code=? AND status<>'inactive' LIMIT 1", [$cid, substr($suggestion['taxCode'], 5)])[0] ?? null;
            $suggestion['taxCode'] = $codeRow ? 'CODE:' . $codeRow['id'] : null; $suggestion['taxName'] = $codeRow['code'] ?? null;
        }
        if ($suggestion) $out[(string)$p['id']] = $suggestion;
    }
    return $out;
}

/* ------------------------------------------------------------------ ask your books */

/** Parse a period from everyday words. Returns [from, to, label] or null. */
function tegh_ins_period(string $q, array $company): ?array
{
    $today = tegh_ins_today(); $t = new DateTimeImmutable($today); $y = (int)$t->format('Y');
    $fyStart = tegh_workspace_summary_period_start($today, (string)$company['fiscal_year_end']);
    $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    if (preg_match('/\b(this|current) month\b|\bmonth to date\b|\bmtd\b/', $q)) return [$t->format('Y-m-01'), $today, 'this month'];
    if (preg_match('/\blast month\b|\bprevious month\b/', $q)) { $s = $t->modify('first day of last month'); return [$s->format('Y-m-d'), $s->modify('last day of this month')->format('Y-m-d'), 'last month']; }
    if (preg_match('/\blast (\d{1,3}) days\b/', $q, $m)) return [tegh_ins_add_days($today, -(int)$m[1] + 1), $today, 'the last ' . $m[1] . ' days'];
    if (preg_match('/\bq([1-4])\b(?:\s*(20\d\d))?/', $q, $m) || preg_match('/\b(first|second|third|fourth) quarter\b(?:\s*(?:of\s*)?(20\d\d))?/', $q, $m)) {
        $qn = is_numeric($m[1]) ? (int)$m[1] : ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4][$m[1]]; $yr = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $y;
        $s = new DateTimeImmutable(sprintf('%04d-%02d-01', $yr, ($qn - 1) * 3 + 1)); return [$s->format('Y-m-d'), $s->modify('+2 months')->modify('last day of this month')->format('Y-m-d'), "Q$qn $yr"];
    }
    if (preg_match('/\blast quarter\b/', $q)) { $qn = intdiv((int)$t->format('n') - 1, 3); $yr = $y; if ($qn === 0) { $qn = 4; $yr--; } $s = new DateTimeImmutable(sprintf('%04d-%02d-01', $yr, ($qn - 1) * 3 + 1)); return [$s->format('Y-m-d'), $s->modify('+2 months')->modify('last day of this month')->format('Y-m-d'), "last quarter (Q$qn $yr)"]; }
    if (preg_match('/\bthis quarter\b/', $q)) { $qn = intdiv((int)$t->format('n') - 1, 3) + 1; $s = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, ($qn - 1) * 3 + 1)); return [$s->format('Y-m-d'), $today, "this quarter (Q$qn)"]; }
    if (preg_match('/\blast year\b|\bprevious year\b/', $q)) { $s = (new DateTimeImmutable($fyStart))->modify('-1 year'); return [$s->format('Y-m-d'), (new DateTimeImmutable($fyStart))->modify('-1 day')->format('Y-m-d'), 'last year']; }
    if (preg_match('/\bin (20\d\d)\b|\bfor (20\d\d)\b|\bduring (20\d\d)\b/', $q, $m)) { $yr = (int)($m[1] ?: ($m[2] ?: $m[3])); return ["$yr-01-01", "$yr-12-31", (string)$yr]; }
    if (preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)(?:uary|ruary|ch|il|e|y|ust|t|tember|ember|ober)?\b(?:\s+(20\d\d))?/', $q, $m)
        && ($m[1] !== 'may' || preg_match('/\b(?:in|for|during|of|since)\s+may\b|\bmay\s+20\d\d\b/', $q))) {
        $mn = $months[$m[1]]; $yr = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : ($mn > (int)$t->format('n') ? $y - 1 : $y);
        $s = new DateTimeImmutable(sprintf('%04d-%02d-01', $yr, $mn)); return [$s->format('Y-m-d'), min($today, $s->modify('last day of this month')->format('Y-m-d')), $s->format('F Y')];
    }
    if (preg_match('/\bthis year\b|\bso far\b|\bytd\b|\byear to date\b/', $q)) return [$fyStart, $today, 'this year'];
    return null;
}

/** Find expense accounts or vendors named in the question ("spend on fuel", "paid to Rogers"). */
function tegh_ins_match_subject(string $companyId, string $phrase): array
{
    $phrase = trim(preg_replace('/\b(my|the|our|on|for|to|at|in|of|a|an)\b/', ' ', $phrase) ?? ''); $phrase = trim(preg_replace('/\s+/', ' ', $phrase) ?? '');
    if ($phrase === '' || mb_strlen($phrase) < 3) return [];
    $synonyms = ['fuel' => 'vehicle', 'gas' => 'vehicle', 'car' => 'vehicle', 'phone' => 'telephone', 'internet' => 'telephone', 'cell' => 'telephone', 'ads' => 'advertising', 'marketing' => 'advertising', 'software' => 'software',
        'meals' => 'meals', 'food' => 'meals', 'restaurants' => 'meals', 'flights' => 'travel', 'hotel' => 'travel', 'trips' => 'travel', 'lawyer' => 'professional', 'accountant' => 'professional', 'legal' => 'professional', 'salaries' => 'wages', 'payroll' => 'wages', 'staff' => 'wages', 'supplies' => 'supplies', 'rent' => 'rent', 'insurance' => 'insurance', 'bank fees' => 'bank charges', 'fees' => 'bank charges'];
    $stop = array_fill_keys(['and','the','for','with','from','all','any','our','your','this','that','how','much','did','spend','spent','what','was','were','are','its','other','total','money','costs','cost','expenses','expense'], true);
    $words = array_values(array_filter(explode(' ', mb_strtolower($phrase)), static fn(string $w): bool => mb_strlen($w) >= 3 && !isset($stop[$w])));
    $terms = $words; foreach ($synonyms as $k => $v) if (str_contains(mb_strtolower($phrase), $k)) $terms[] = $v;
    $accounts = [];
    foreach (tegh_ins_rows("SELECT id,code,name FROM accounts WHERE company_id=? AND account_type='expense' AND active=1", [$companyId]) as $a) {
        $n = mb_strtolower((string)$a['name']);
        foreach ($terms as $w) if (str_contains($n, rtrim($w, 's'))) { $accounts[(string)$a['id']] = $a['code'] . ' ' . $a['name']; break; }
    }
    $vendors = [];
    if (!$accounts) foreach (tegh_ins_rows('SELECT id,name FROM vendors WHERE company_id=?', [$companyId]) as $v) {
        $n = mb_strtolower((string)$v['name']); foreach ($words as $w) if (str_contains($n, $w)) { $vendors[(string)$v['id']] = (string)$v['name']; break; }
    }
    return ['accounts' => $accounts, 'vendors' => $vendors];
}

function tegh_ins_ask(array $company, string $question): array
{
    $cid = (string)$company['id']; $q = mb_strtolower(trim($question)); $q = preg_replace('/[?!.]+$/', '', $q) ?? $q;
    $period = tegh_ins_period($q, $company) ?? [tegh_workspace_summary_period_start(tegh_ins_today(), (string)$company['fiscal_year_end']), tegh_ins_today(), 'this year'];
    [$from, $to, $label] = $period;
    $sumAccounts = static function (array $ids, string $f, string $t) use ($cid): int {
        if (!$ids) return 0; $in = implode(',', array_fill(0, count($ids), '?'));
        return (int)tegh_ins_col("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.status='posted' AND je.entry_date BETWEEN ? AND ? AND jl.account_id IN ($in)", array_merge([$cid, $f, $t], $ids));
    };
    $prior = static function (string $f, string $t): array { $a = new DateTimeImmutable($f); $b = new DateTimeImmutable($t); return [$a->modify('-1 year')->format('Y-m-d'), $b->modify('-1 year')->format('Y-m-d')]; };
    $compare = (bool)preg_match('/\b(vs|versus|compared?|compare|than|last year)\b/', $q) && !preg_match('/^(how much|what).{0,30}last year$/', $q);

    // Spending on something: "how much did I spend on fuel last quarter", "what did we pay Rogers this year".
    if (preg_match('/\b(?:spend|spent|spending|cost|costs|paid|pay)\b(?:\s+(?:on|for|to|at))?\s+(.+?)(?:\s+(?:this|last|in|during|for|so|since|over|q[1-4])\b.*)?$/', $q, $m) && !preg_match('/\b(customers?|clients?|owe|owed)\b/', $q)) {
        $subject = tegh_ins_match_subject($cid, $m[1]);
        if (!empty($subject['accounts'])) {
            $ids = array_keys($subject['accounts']); $total = $sumAccounts($ids, $from, $to);
            $rows = []; foreach ($subject['accounts'] as $id => $name) $rows[] = ['label' => $name, 'amountCents' => $sumAccounts([$id], $from, $to)];
            $text = 'You spent ' . tegh_ins_money($total) . ' on ' . implode(', ', array_values($subject['accounts'])) . " in $label.";
            if ($compare) { [$pf, $pt] = $prior($from, $to); $p = $sumAccounts($ids, $pf, $pt); $text .= ' Same period last year: ' . tegh_ins_money($p) . ($p ? ' (' . ($total >= $p ? '+' : '') . round(($total - $p) / max(1, abs($p)) * 100) . '%).' : '.'); }
            return ['answered' => true, 'kind' => 'spend', 'text' => $text, 'period' => ['from' => $from, 'to' => $to, 'label' => $label], 'rows' => $rows, 'link' => ['menu' => ['Reports', 'Profit and Loss']]];
        }
        if (!empty($subject['vendors'])) {
            $vin = implode(',', array_fill(0, count($subject['vendors']), '?'));
            $bills = (int)tegh_ins_col("SELECT COALESCE(SUM(total_cents),0) FROM bills WHERE company_id=? AND status NOT IN ('void','draft') AND bill_date BETWEEN ? AND ? AND vendor_id IN ($vin)", array_merge([$cid, $from, $to], array_keys($subject['vendors'])));
            $paid = (int)tegh_ins_col("SELECT COALESCE(SUM(amount_cents),0) FROM party_payments WHERE company_id=? AND payment_type='vendor' AND status='posted' AND payment_date BETWEEN ? AND ? AND party_id IN ($vin)", array_merge([$cid, $from, $to], array_keys($subject['vendors'])));
            $names = implode(', ', array_values($subject['vendors']));
            return ['answered' => true, 'kind' => 'vendor', 'text' => "$names: vendor invoices of " . tegh_ins_money($bills) . ' and payments of ' . tegh_ins_money($paid) . " in $label.", 'period' => ['from' => $from, 'to' => $to, 'label' => $label], 'rows' => [], 'link' => ['menu' => ['Payables', 'Vendor Ledgers']]];
        }
    }
    // Sales / revenue / income for a period, optionally compared with last year.
    if (preg_match('/\b(sales|revenue|income|turnover|earn(?:ed|ings)?|made|sold)\b/', $q) && !preg_match('/\b(profit|net|tax|owe)\b/', $q)) {
        $ids = tegh_ins_accounts($cid, "account_type='income'"); $total = -$sumAccounts($ids, $from, $to);
        $text = 'Sales (income) were ' . tegh_ins_money($total) . " in $label.";
        if ($compare) { [$pf, $pt] = $prior($from, $to); $p = -$sumAccounts($ids, $pf, $pt); $text .= ' Same period last year: ' . tegh_ins_money($p) . ($p ? ' (' . ($total >= $p ? '+' : '') . round(($total - $p) / max(1, abs($p)) * 100) . '%).' : '.'); }
        return ['answered' => true, 'kind' => 'sales', 'text' => $text, 'period' => ['from' => $from, 'to' => $to, 'label' => $label], 'rows' => [], 'link' => ['menu' => ['Reports', 'Profit and Loss']]];
    }
    // Biggest expenses.
    if (preg_match('/\b(biggest|largest|top|main|highest)\b.{0,20}\b(expenses?|costs?|spending)\b|\bwhere (?:does|did|is) (?:my|our|the) money go\b/', $q)) {
        $rows = tegh_ins_rows("SELECT a.code,a.name,SUM(jl.debit_cents-jl.credit_cents) n FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id
            WHERE je.company_id=? AND je.status='posted' AND je.entry_date BETWEEN ? AND ? AND a.account_type='expense' GROUP BY a.id,a.code,a.name HAVING n>0 ORDER BY n DESC LIMIT 5", [$cid, $from, $to]);
        if (!$rows) return ['answered' => true, 'kind' => 'top_expenses', 'text' => "No expenses are recorded for $label.", 'period' => ['from' => $from, 'to' => $to, 'label' => $label], 'rows' => [], 'link' => ['menu' => ['Reports', 'Profit and Loss']]];
        return ['answered' => true, 'kind' => 'top_expenses', 'text' => "Your biggest expense in $label was {$rows[0]['name']} (" . tegh_ins_money((int)$rows[0]['n']) . ').', 'period' => ['from' => $from, 'to' => $to, 'label' => $label],
            'rows' => array_map(static fn(array $r): array => ['label' => $r['code'] . ' ' . $r['name'], 'amountCents' => (int)$r['n']], $rows), 'link' => ['menu' => ['Reports', 'Profit and Loss']]];
    }
    // Best customers.
    if (preg_match('/\b(top|best|biggest|largest|main)\b.{0,15}\b(customers?|clients?)\b|\bwho (?:are|is) my (?:best|biggest|top) (?:customers?|clients?)\b/', $q)) {
        $rows = tegh_ins_rows("SELECT c.name,SUM(i.subtotal_cents) n,COUNT(*) k FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.company_id=? AND i.status NOT IN ('void','draft') AND i.issue_date BETWEEN ? AND ? GROUP BY c.id,c.name ORDER BY n DESC LIMIT 5", [$cid, $from, $to]);
        if (!$rows) return ['answered' => true, 'kind' => 'top_customers', 'text' => "No customer invoices were issued in $label.", 'period' => ['from' => $from, 'to' => $to, 'label' => $label], 'rows' => [], 'link' => ['menu' => ['Receivables', 'Customers']]];
        return ['answered' => true, 'kind' => 'top_customers', 'text' => "Your biggest customer in $label was {$rows[0]['name']} (" . tegh_ins_money((int)$rows[0]['n']) . ' before tax, ' . $rows[0]['k'] . ' invoice' . ($rows[0]['k'] == 1 ? '' : 's') . ').', 'period' => ['from' => $from, 'to' => $to, 'label' => $label],
            'rows' => array_map(static fn(array $r): array => ['label' => $r['name'], 'amountCents' => (int)$r['n']], $rows), 'link' => ['menu' => ['Receivables', 'Customers']]];
    }
    return ['answered' => false];
}

/* ------------------------------------------------------------------ HTTP */

function handle_insights(string $action): never
{
    $user = require_user(); $company = require_company($user); $cid = (string)$company['id'];
    header('Cache-Control: private, no-store');
    if ($action === 'dismiss') {
        require_method('POST'); require_csrf(); require_company_permission($company, 'reports.view');
        $input = request_json(); $key = clean_text($input['key'] ?? '', 'Insight', 190); $undo = !empty($input['undo']);
        tegh_ins_dismissals_ready();
        if ($undo) db()->prepare('DELETE FROM company_insight_dismissals WHERE company_id=? AND insight_key=?')->execute([$cid, $key]);
        else db()->prepare('INSERT IGNORE INTO company_insight_dismissals (company_id,insight_key,dismissed_by) VALUES (?,?,?)')->execute([$cid, $key, $user['id']]);
        audit_event($user, $cid, $undo ? 'insight.restored' : 'insight.dismissed', 'company', $cid, ['key' => $key]);
        json_response(['key' => $key, 'dismissed' => !$undo]);
    }
    if ($action === 'bank-suggestions') {
        require_method('POST'); require_csrf(); require_company_permission($company, 'banking.view');
        $ids = request_json()['ids'] ?? []; if (!is_array($ids)) fail('Choose bank lines.', 422, 'insights_ids_invalid');
        json_response(['suggestions' => (object)tegh_ins_bank_suggestions($company, $ids)]);
    }
    if ($action === 'ask') {
        require_method('POST'); require_csrf(); require_company_permission($company, 'reports.view');
        $question = clean_text(request_json()['question'] ?? '', 'Question', 300);
        json_response(tegh_ins_ask($company, $question) + ['currency' => (string)($company['currency'] ?? 'CAD')]);
    }
    require_method('GET'); require_company_permission($company, 'reports.view');
    if ($action === 'brief') json_response(tegh_ins_brief($company));
    json_response(['currency' => (string)($company['currency'] ?? 'CAD'), 'brief' => tegh_ins_brief($company), 'anomalies' => tegh_ins_anomalies($company, true), 'chase' => tegh_ins_chase($company), 'cash' => tegh_ins_cash($company)]);
}
