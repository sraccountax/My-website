<?php
declare(strict_types=1);

/**
 * R163: the Guided bank-statement wizard and "Connect with an accountant".
 *
 * Statement months. Guided mode books a fiscal year one bank statement at a time. The fiscal year (from the company's
 * fiscal year end) is shown as its months; each month takes the statement issued in that month, which normally holds
 * transactions from the previous month too. The issue month is the month of the statement's last transaction or the
 * month after it (a statement is often issued a few days into the next month). Transactions dated before the fiscal year
 * starts or after it ends are imported like the others, and the person is told how many there are.
 *
 * Work done in Full Accounting. A statement imported from Banking is taken into the month list the next time Guided
 * looks at the account (the month of its last transaction, or the month after; when both already hold a statement it
 * joins the first), so a month can be made of several imports (guided_statement_batches). A line matched to a book
 * entry in Full Accounting counts as done, and a statement whose posted lines are all in a completed reconciliation
 * counts as reconciled. Empty months whose usual period (the calendar month before, within a week at each end) is
 * inside another statement are shown as included in it. guided_resume() says where to continue: post, check the balance, upload the next month, or
 * the next step.
 *
 * Mapping. A statement's lines are grouped by a keyword taken from the description (the first two meaningful words,
 * reference numbers removed) and by direction, so a statement of thousands of lines becomes a short list of groups. Each
 * group carries the suggestion already made at import (company rules, history, built-in keyword rules) and can be
 * changed; one decision posts every line of the group through the normal posting service (same tax splits, period
 * locks and audit trail), 100 lines at a time. For a GST/HST registrant, deposits suggested to an income account carry
 * no tax suggestion: the person chooses whether they include GST/HST.
 *
 * Reconciliation. Once every line of the statement is dealt with, Tegh compares its bank balance on the statement's
 * last date (opening balance and every imported line not excluded) with the closing balance printed on the statement
 * (read from the file, or typed once). Only when they agree to the cent is the statement period's bank reconciliation
 * completed by the normal reconciliation service. Without a closing balance the month stays "posted", not reconciled.
 *
 * Accountant. guided_accountant_review() looks for unfinished work and likely mistakes and prices it. The hours and the
 * hourly rate stay on the server: the browser receives the findings and one total. A request is stored and emailed to
 * the Tegh accountants' address (config accountant.request_email, default enquiry@sraccountax.ca) with the breakdown.
 *
 * Tables, created on first use: guided_statement_months, guided_statement_batches and accountant_requests.
 */

function tegh_guided_r163_ready(): void
{
    static $ready = false;
    if ($ready) return;
    if (!schema_table_exists('guided_statement_months')) {
        db()->exec("CREATE TABLE IF NOT EXISTS guided_statement_months (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            company_id VARCHAR(64) NOT NULL,
            bank_account_id VARCHAR(64) NOT NULL,
            fiscal_year_end DATE NOT NULL,
            statement_month CHAR(7) NOT NULL,
            import_batch_id VARCHAR(64) NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'guided',
            coverage_start DATE NULL,
            coverage_end DATE NULL,
            line_count INT NOT NULL DEFAULT 0,
            before_fy_count INT NOT NULL DEFAULT 0,
            after_fy_count INT NOT NULL DEFAULT 0,
            statement_closing_cents BIGINT NULL,
            balance_source VARCHAR(16) NULL,
            balance_checked_at DATETIME NULL,
            reconciliation_id VARCHAR(64) NULL,
            reconciled_at DATETIME NULL,
            created_by VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY guided_month_uq (company_id, bank_account_id, statement_month),
            UNIQUE KEY guided_month_batch_uq (company_id, import_batch_id),
            KEY guided_month_company (company_id, fiscal_year_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } elseif (!schema_column_exists('guided_statement_months', 'source')) {
        schema_add_column('guided_statement_months', 'source', "VARCHAR(16) NOT NULL DEFAULT 'guided' AFTER import_batch_id");
        schema_add_column('guided_statement_months', 'statement_closing_cents', 'BIGINT NULL AFTER after_fy_count');
        schema_add_column('guided_statement_months', 'balance_source', 'VARCHAR(16) NULL AFTER statement_closing_cents');
        schema_add_column('guided_statement_months', 'balance_checked_at', 'DATETIME NULL AFTER balance_source');
    }
    if (!schema_table_exists('guided_statement_batches')) {
        db()->exec("CREATE TABLE IF NOT EXISTS guided_statement_batches (
            month_id VARCHAR(64) NOT NULL,
            company_id VARCHAR(64) NOT NULL,
            batch_id VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (month_id, batch_id),
            UNIQUE KEY guided_batch_uq (company_id, batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec('INSERT IGNORE INTO guided_statement_batches (month_id, company_id, batch_id) SELECT id, company_id, import_batch_id FROM guided_statement_months');
    }
    if (!schema_table_exists('accountant_requests')) {
        db()->exec("CREATE TABLE IF NOT EXISTS accountant_requests (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            company_id VARCHAR(64) NOT NULL,
            requested_by VARCHAR(64) NOT NULL,
            requested_by_email VARCHAR(254) NOT NULL,
            note VARCHAR(2000) NOT NULL DEFAULT '',
            findings_json LONGTEXT NOT NULL,
            hours_hundredths INT NOT NULL,
            rate_cents INT NOT NULL,
            quote_cents BIGINT NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'CAD',
            email_status VARCHAR(40) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY accountant_requests_company (company_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready = true;
}

/* ---------------------------------------------------------------- fiscal years */

function guided_company_mmdd(array $company): string
{
    $mmdd = (string)($company['fiscal_year_end'] ?? '12-31');
    return preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $mmdd) ? $mmdd : '12-31';
}

/** A year end on the last day of its month (February 28 or 29 included) stays on the last day of that month every year. */
function guided_year_end_in(string $mmdd, int $year): string
{
    [$mm, $dd] = array_map('intval', explode('-', $mmdd));
    $last = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mm)))->format('t');
    $monthEnd = $dd >= (int)(new DateTimeImmutable(sprintf('2001-%02d-01', $mm)))->format('t');
    return sprintf('%04d-%02d-%02d', $year, $mm, $monthEnd ? $last : min($dd, $last));
}

/** The fiscal year end on or after $date. */
function guided_fy_end_after(string $mmdd, string $date): string
{
    $year = (int)substr($date, 0, 4);
    $end = guided_year_end_in($mmdd, $year);
    return $end >= $date ? $end : guided_year_end_in($mmdd, $year + 1);
}

/** The fiscal year ending on $end (Y-m-d): start, end and its months (Y-m). */
function guided_fiscal_year(string $end): array
{
    $endDate = new DateTimeImmutable($end);
    $mmdd = $endDate->format('m-d');
    if ((int)$endDate->format('d') === (int)$endDate->format('t')) $mmdd = $endDate->format('m') . '-31';
    $start = (new DateTimeImmutable(guided_year_end_in($mmdd, (int)$endDate->format('Y') - 1)))->modify('+1 day');
    $months = [];
    for ($m = $start->modify('first day of this month'); $m <= $endDate; $m = $m->modify('+1 month')) $months[] = $m->format('Y-m');
    return ['start' => $start->format('Y-m-d'), 'end' => $endDate->format('Y-m-d'), 'months' => $months,
        'label' => 'Fiscal year ' . $start->format('M Y') . ' – ' . $endDate->format('M Y')];
}

function guided_today(): string
{
    return function_exists('canadian_today') ? canadian_today() : date('Y-m-d');
}

/** Fiscal years the company can book: from the one holding its books start (or first bank line) to the current one. */
function guided_fiscal_years(array $company): array
{
    $mmdd = guided_company_mmdd($company);
    $today = guided_today();
    $first = (string)($company['books_start_date'] ?? '') ?: $today;
    $q = db()->prepare('SELECT MIN(transaction_date) FROM bank_transactions WHERE company_id=?');
    $q->execute([(string)$company['id']]);
    $earliest = (string)($q->fetchColumn() ?: '');
    if ($earliest !== '' && $earliest < $first) $first = $earliest;
    $current = guided_fy_end_after($mmdd, $today);
    $years = [];
    for ($end = guided_fy_end_after($mmdd, $first); $end <= $current; $end = guided_fy_end_after($mmdd, (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d'))) {
        $years[] = guided_fiscal_year($end);
        if (count($years) > 15) break;
    }
    return ['years' => array_reverse($years), 'current' => $current];
}

/** The statement issue month must be the month of its last transaction or the month after it. */
function guided_allowed_month(string $lastDate, string $month): bool
{
    return $month === substr($lastDate, 0, 7) || $month === guided_next_month(substr($lastDate, 0, 7));
}

function guided_next_month(string $month, int $by = 1): string
{
    return (new DateTimeImmutable($month . '-01'))->modify(($by >= 0 ? '+' : '') . $by . ' month')->format('Y-m');
}

function guided_month_label(string $month): string
{
    return (new DateTimeImmutable($month . '-01'))->format('F Y');
}

/* ---------------------------------------------------------------- keywords and tax choices */

/** The grouping keyword: the first two meaningful words of the description, reference numbers removed. */
function guided_keyword(string $description): string
{
    $v = mb_strtoupper(html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
    $v = preg_replace('/\bE-?\s?TRANSFER\b/u', ' ', $v) ?? $v;
    $v = preg_replace('/[^A-Z0-9& ]+/u', ' ', $v) ?? $v;
    static $noise = ['POS','DEBIT','CREDIT','PURCHASE','PAYMENT','PMT','ONLINE','INTERAC','VISA','MC','MASTERCARD','PREAUTHORIZED','PRE','AUTHORIZED','PAD','WWW','COM','CA','INC','LTD','LLC','CORP','CO','THE','TO','FROM','FOR','REF','NO','ID','AND','OF','DR','CR','RECURRING','CHQ','CHEQUE#'];
    $words = [];
    foreach (preg_split('/\s+/u', trim($v)) ?: [] as $word) {
        if ($word === '' || preg_match('/\d/', $word) || mb_strlen($word) < 2 || in_array($word, $noise, true)) continue;
        $words[] = $word;
        if (count($words) === 2) break;
    }
    return $words ? implode(' ', $words) : 'OTHER';
}

/**
 * The tax value the Guided form uses: NO_TAX or CODE:<id> in tax-code mode; NO_TAX, GST_HST, PST or GST_HST_PST
 * otherwise. A company that is not registered for sales tax never gets a tax suggestion.
 */
function guided_tax_choice(array $company, string $stored): string
{
    $stored = strtoupper(trim($stored));
    if (empty($company['tax_registered']) && empty($company['pst_registered'])) return 'NO_TAX';
    if (function_exists('tax_setup_mode') && tax_setup_mode($company) === 'codes') {
        if ($stored === '' || $stored === 'NO_TAX') return 'NO_TAX';
        if (in_array($stored, ['GST_HST', 'HST13', 'GST_HST_PST', 'PST'], true)) {
            $home = function_exists('tax_code_home') ? tax_code_home($company) : null;
            return $home ? 'CODE:' . (string)$home['id'] : 'NO_TAX';
        }
        if (str_starts_with($stored, 'CODE:')) {
            $q = db()->prepare('SELECT id FROM tax_codes WHERE company_id=? AND (id=? OR code=?) LIMIT 1');
            $q->execute([(string)$company['id'], substr($stored, 5), substr($stored, 5)]);
            $id = $q->fetchColumn();
            return $id ? 'CODE:' . (string)$id : 'NO_TAX';
        }
        return 'NO_TAX';
    }
    return in_array($stored, ['GST_HST', 'PST', 'GST_HST_PST'], true) ? $stored : 'NO_TAX';
}

/** A posting decision for the normal bank posting service. */
function guided_decision(array $company, string $transactionId, string $accountId, string $tax, string $remarks): array
{
    $decision = ['id' => $transactionId, 'accountId' => $accountId, 'remarks' => mb_substr($remarks, 0, 500)];
    if (str_starts_with($tax, 'CODE:')) return $decision + ['taxCodeId' => substr($tax, 5)];
    if (function_exists('tax_setup_mode') && tax_setup_mode($company) === 'codes') return $decision + ['taxCode' => 'NO_TAX'];
    return $decision + ['taxCode' => in_array($tax, ['GST_HST', 'PST', 'GST_HST_PST'], true) ? $tax : 'NO_TAX',
        'applyGstHst' => str_contains($tax, 'GST_HST'), 'applyPst' => str_contains($tax, 'PST')];
}

/* ---------------------------------------------------------------- statement months */

function guided_month_row(string $companyId, string $id): array
{
    $q = db()->prepare('SELECT * FROM guided_statement_months WHERE id=? AND company_id=?');
    $q->execute([$id, $companyId]);
    $row = $q->fetch();
    if (!$row) fail('That statement month is not available in this company.', 404, 'guided_month_not_found');
    return $row;
}

function guided_bank_account(string $companyId, string $bankAccountId): array
{
    $q = db()->prepare("SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1 AND account_type IN ('bank','credit_card')");
    $q->execute([$bankAccountId, $companyId]);
    $bank = $q->fetch();
    if (!$bank) fail('Choose an active bank or credit-card account.', 422, 'guided_bank_required');
    return $bank;
}

function guided_placeholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

/** The import batches that make up a statement month. */
function guided_month_batches(string $companyId, string $monthId): array
{
    $q = db()->prepare('SELECT batch_id FROM guided_statement_batches WHERE company_id=? AND month_id=? ORDER BY created_at, batch_id');
    $q->execute([$companyId, $monthId]);
    return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
}

const GUIDED_MATCHED_SQL = "EXISTS(SELECT 1 FROM bank_match_bank_items bi JOIN bank_match_groups g ON g.id=bi.match_group_id AND g.status='matched' WHERE bi.bank_transaction_id=bt.id)";
const GUIDED_CLEARED_SQL = "EXISTS(SELECT 1 FROM reconciliation_items ri JOIN reconciliations r ON r.id=ri.reconciliation_id AND r.status='complete' AND r.company_id=bt.company_id WHERE ri.bank_transaction_id=bt.id)";

/**
 * Line counts of a statement: pending (still to post), matched (pending but matched to a book entry in Full Accounting,
 * so done), posted, excluded (excluded or duplicate, with their total) and cleared (posted lines in a completed
 * reconciliation).
 */
function guided_batch_counts(string $companyId, array $batchIds): array
{
    $counts = ['pending' => 0, 'matched' => 0, 'posted' => 0, 'excluded' => 0, 'excludedCents' => 0, 'cleared' => 0, 'clearedMatched' => 0];
    if (!$batchIds) return $counts;
    $q = db()->prepare('SELECT bt.status, ' . GUIDED_MATCHED_SQL . ' m, ' . GUIDED_CLEARED_SQL . " c, COUNT(*) n, COALESCE(SUM(bt.amount_cents),0) total
        FROM bank_transactions bt WHERE bt.company_id=? AND bt.import_batch_id IN (" . guided_placeholders($batchIds) . ') GROUP BY bt.status, m, c');
    $q->execute(array_merge([$companyId], $batchIds));
    foreach ($q->fetchAll() as $r) {
        $n = (int)$r['n'];
        if ($r['status'] === 'posted') { $counts['posted'] += $n; if ((int)$r['c']) $counts['cleared'] += $n; }
        elseif ($r['status'] === 'pending') { $counts[(int)$r['m'] ? 'matched' : 'pending'] += $n; if ((int)$r['m'] && (int)$r['c']) $counts['clearedMatched'] += $n; }
        else { $counts['excluded'] += $n; $counts['excludedCents'] += (int)$r['total']; }
    }
    return $counts;
}

/** Recompute the dates and counts of a month from all its imports. */
function guided_refresh_month(string $companyId, string $monthId): void
{
    $row = guided_month_row($companyId, $monthId);
    $batches = guided_month_batches($companyId, $monthId);
    if (!$batches) return;
    $fy = guided_fiscal_year((string)$row['fiscal_year_end']);
    $q = db()->prepare("SELECT MIN(transaction_date) a, MAX(transaction_date) z, COUNT(*) n, COALESCE(SUM(transaction_date < ?),0) b, COALESCE(SUM(transaction_date > ?),0) f
        FROM bank_transactions WHERE company_id=? AND import_batch_id IN (" . guided_placeholders($batches) . ')');
    $q->execute(array_merge([$fy['start'], $fy['end'], $companyId], $batches));
    $s = $q->fetch();
    db()->prepare('UPDATE guided_statement_months SET coverage_start=?, coverage_end=?, line_count=?, before_fy_count=?, after_fy_count=? WHERE id=? AND company_id=?')
        ->execute([$s['a'], $s['z'], (int)$s['n'], (int)$s['b'], (int)$s['f'], $monthId, $companyId]);
}

/**
 * Take statements imported from Banking (Full Accounting) into the month list. Each import goes to the month of its
 * last transaction, or the month after when that one is taken; when both are taken it joins the first.
 */
function guided_adopt_imports(array $user, array $company, string $bankId): int
{
    $companyId = (string)$company['id'];
    $mmdd = guided_company_mmdd($company);
    $q = db()->prepare("SELECT bt.import_batch_id b, MIN(bt.transaction_date) a, MAX(bt.transaction_date) z, COUNT(*) n FROM bank_transactions bt
        WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.import_batch_id IS NOT NULL AND bt.status<>'duplicate'
        AND NOT EXISTS(SELECT 1 FROM guided_statement_batches gb WHERE gb.company_id=bt.company_id AND gb.batch_id=bt.import_batch_id)
        GROUP BY bt.import_batch_id ORDER BY z, b LIMIT 200");
    $q->execute([$companyId, $bankId]);
    $find = db()->prepare('SELECT id FROM guided_statement_months WHERE company_id=? AND bank_account_id=? AND statement_month=?');
    $adopted = 0;
    foreach ($q->fetchAll() as $b) {
        $last = substr((string)$b['z'], 0, 7);
        $find->execute([$companyId, $bankId, $last]); $atLast = $find->fetchColumn();
        $find->execute([$companyId, $bankId, guided_next_month($last)]); $atNext = $find->fetchColumn();
        try {
            if (!$atLast || !$atNext) {
                $month = !$atLast ? $last : guided_next_month($last);
                $fy = guided_fiscal_year(guided_fy_end_after($mmdd, $month . '-01'));
                $id = new_id('gsm');
                db()->prepare("INSERT INTO guided_statement_months (id,company_id,bank_account_id,fiscal_year_end,statement_month,import_batch_id,source,coverage_start,coverage_end,line_count,created_by) VALUES (?,?,?,?,?,?,'import',?,?,?,?)")
                    ->execute([$id, $companyId, $bankId, $fy['end'], $month, (string)$b['b'], $b['a'], $b['z'], (int)$b['n'], (string)$user['id']]);
            } else {
                $id = (string)$atLast; $month = $last;
            }
            db()->prepare('INSERT INTO guided_statement_batches (month_id, company_id, batch_id) VALUES (?,?,?)')->execute([$id, $companyId, (string)$b['b']]);
            guided_refresh_month($companyId, $id);
            audit_event($user, $companyId, 'guided.statement_import_added', 'guided_statement_month', $id, ['month' => $month, 'importBatchId' => (string)$b['b'], 'lines' => (int)$b['n']]);
            $adopted++;
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '23000') throw $e; // taken by a simultaneous request
        }
    }
    return $adopted;
}

/** Whether a statement covering $a..$z holds the usual period of another month ($pStart..$pEnd), within a week at each end. */
function guided_covers(string $a, string $z, string $pStart, string $pEnd): bool
{
    $tolerance = static fn(string $d, string $by): string => (new DateTimeImmutable($d))->modify($by)->format('Y-m-d');
    return $a <= $tolerance($pStart, '+7 days') && $z >= $tolerance($pEnd, '-7 days');
}

function guided_month_entry(array $company, array $row): array
{
    $companyId = (string)$company['id'];
    $counts = guided_batch_counts($companyId, guided_month_batches($companyId, (string)$row['id']));
    $done = $counts['posted'] + $counts['matched'];
    $reconciledHere = $row['reconciliation_id'] !== null;
    $reconciledElsewhere = !$reconciledHere && $counts['pending'] === 0 && $counts['posted'] > 0 && $counts['cleared'] === $counts['posted'];
    $status = $counts['pending'] > 0 ? 'uploaded' : (($reconciledHere || $reconciledElsewhere) ? 'reconciled' : 'posted');
    return ['month' => (string)$row['statement_month'], 'label' => guided_month_label((string)$row['statement_month']), 'status' => $status,
        'id' => (string)$row['id'], 'source' => (string)($row['source'] ?? 'guided'), 'coverageStart' => $row['coverage_start'], 'coverageEnd' => $row['coverage_end'],
        'lineCount' => (int)$row['line_count'], 'beforeFiscalYear' => (int)$row['before_fy_count'], 'afterFiscalYear' => (int)$row['after_fy_count'],
        'pending' => $counts['pending'], 'posted' => $counts['posted'], 'matched' => $counts['matched'], 'excluded' => $counts['excluded'], 'excludedCents' => $counts['excludedCents'],
        'done' => $done, 'reconciled' => $status === 'reconciled', 'reconciledIn' => $reconciledHere ? 'guided' : ($reconciledElsewhere ? 'bank-reconciliation' : null),
        'statementClosingCents' => $row['statement_closing_cents'] === null ? null : (int)$row['statement_closing_cents'], 'balanceSource' => $row['balance_source'],
        'fiscalYearEnd' => (string)$row['fiscal_year_end'], 'bankAccountId' => (string)$row['bank_account_id']];
}

function guided_months_state(array $user, array $company, string $bankAccountId, string $fiscalYearEnd): array
{
    $companyId = (string)$company['id'];
    $years = guided_fiscal_years($company);
    if ($fiscalYearEnd === '') $fiscalYearEnd = $years['current'];
    $fy = guided_fiscal_year(safe_date($fiscalYearEnd, 'Fiscal year end'));
    $banks = guided_bank_list($companyId);
    if ($bankAccountId === '' && $banks) $bankAccountId = $banks[0]['id'];
    $records = []; $all = []; $unbatched = 0;
    if ($bankAccountId !== '') {
        guided_bank_account($companyId, $bankAccountId);
        guided_adopt_imports($user, $company, $bankAccountId);
        $r = db()->prepare('SELECT * FROM guided_statement_months WHERE company_id=? AND bank_account_id=? ORDER BY statement_month');
        $r->execute([$companyId, $bankAccountId]);
        foreach ($r->fetchAll() as $row) { $all[] = $row; if (in_array((string)$row['statement_month'], $fy['months'], true)) $records[(string)$row['statement_month']] = $row; }
        $u = db()->prepare("SELECT COUNT(*) FROM bank_transactions bt WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.status='pending' AND bt.import_batch_id IS NULL AND NOT " . GUIDED_MATCHED_SQL);
        $u->execute([$companyId, $bankAccountId]);
        $unbatched = (int)$u->fetchColumn();
    }
    $months = [];
    foreach ($fy['months'] as $month) {
        $row = $records[$month] ?? null;
        if ($row) { $months[] = guided_month_entry($company, $row); continue; }
        $entry = ['month' => $month, 'label' => guided_month_label($month), 'status' => 'empty'];
        // A statement issued in this month would cover the calendar month before it; is that inside another statement?
        $period = guided_next_month($month, -1);
        $pStart = $period . '-01'; $pEnd = (new DateTimeImmutable($pStart))->format('Y-m-t');
        foreach ($all as $other) {
            if ($other['coverage_start'] !== null && guided_covers((string)$other['coverage_start'], (string)$other['coverage_end'], $pStart, $pEnd)) {
                $entry['status'] = 'covered'; $entry['coveredBy'] = guided_month_label((string)$other['statement_month']); $entry['coveredById'] = (string)$other['id'];
                break;
            }
        }
        $months[] = $entry;
    }
    return ['fiscalYears' => array_map(static fn(array $y): array => ['end' => $y['end'], 'start' => $y['start'], 'label' => $y['label']], $years['years']),
        'fiscalYear' => ['end' => $fy['end'], 'start' => $fy['start'], 'label' => $fy['label']],
        'bankAccounts' => $banks, 'bankAccountId' => $bankAccountId, 'months' => $months, 'unbatchedPending' => $unbatched,
        'resume' => guided_resume($user, $company)];
}

function guided_bank_list(string $companyId): array
{
    $q = db()->prepare("SELECT id,name,masked_number,currency,account_type FROM bank_accounts WHERE company_id=? AND active=1 AND account_type IN ('bank','credit_card') ORDER BY created_at,name");
    $q->execute([$companyId]);
    return array_map(static fn(array $b): array => ['id' => (string)$b['id'], 'name' => (string)$b['name'], 'maskedNumber' => (string)($b['masked_number'] ?? ''), 'currency' => (string)$b['currency'], 'type' => (string)$b['account_type']], $q->fetchAll());
}

/**
 * Where to continue, whatever was done in Guided or in Full Accounting: the first statement with lines to post, then
 * the first one whose balance is still to check, then the next month of the current fiscal year without a statement
 * (from the books start to this month), otherwise the next step.
 */
function guided_resume(array $user, array $company): array
{
    static $memo = [];
    $companyId = (string)$company['id'];
    if (isset($memo[$companyId])) return $memo[$companyId];
    $banks = guided_bank_list($companyId);
    if (!$banks) return $memo[$companyId] = ['action' => 'add-bank'];
    foreach ($banks as $b) guided_adopt_imports($user, $company, $b['id']);
    $names = array_column($banks, 'name', 'id');
    $q = db()->prepare('SELECT m.* FROM guided_statement_months m JOIN bank_accounts ba ON ba.id=m.bank_account_id AND ba.company_id=m.company_id AND ba.active=1 WHERE m.company_id=? ORDER BY m.statement_month, m.created_at');
    $q->execute([$companyId]);
    $entries = array_map(static fn(array $row): array => guided_month_entry($company, $row), $q->fetchAll());
    $base = static fn(array $e, string $action): array => ['action' => $action, 'monthId' => $e['id'], 'month' => $e['month'], 'label' => $e['label'],
        'bankAccountId' => $e['bankAccountId'], 'bankName' => $names[$e['bankAccountId']] ?? '', 'fiscalYearEnd' => $e['fiscalYearEnd'], 'pending' => $e['pending'], 'uploaded' => count($entries)];
    foreach ($entries as $e) if ($e['pending'] > 0) return $memo[$companyId] = $base($e, 'post');
    foreach ($entries as $e) if ($e['status'] === 'posted' && $e['done'] > 0) return $memo[$companyId] = $base($e, 'reconcile');
    $years = guided_fiscal_years($company);
    $fy = guided_fiscal_year($years['current']);
    // A statement issued in a month normally covers the month before it: skip months whose period ends before the books start.
    $booksStart = (string)($company['books_start_date'] ?? '') ?: $fy['start'];
    $to = substr(guided_today(), 0, 7);
    $have = []; $covered = [];
    foreach ($entries as $e) {
        $have[$e['bankAccountId'] . '|' . $e['month']] = true;
        if ($e['coverageStart'] !== null) $covered[$e['bankAccountId']][] = [(string)$e['coverageStart'], (string)$e['coverageEnd']];
    }
    foreach ($banks as $b) {
        foreach ($fy['months'] as $month) {
            if ($month > $to || isset($have[$b['id'] . '|' . $month])) continue;
            $p = guided_next_month($month, -1); $pStart = $p . '-01'; $pEnd = (new DateTimeImmutable($pStart))->format('Y-m-t');
            if ($pEnd < $booksStart) continue;
            $isCovered = false;
            foreach ($covered[$b['id']] ?? [] as [$a, $z]) if (guided_covers($a, $z, $pStart, $pEnd)) { $isCovered = true; break; }
            if ($isCovered) continue;
            return $memo[$companyId] = ['action' => 'upload', 'month' => $month, 'label' => guided_month_label($month), 'bankAccountId' => $b['id'], 'bankName' => $b['name'], 'fiscalYearEnd' => $fy['end'], 'uploaded' => count($entries)];
        }
    }
    return $memo[$companyId] = ['action' => 'done', 'fiscalYearEnd' => $fy['end'], 'uploaded' => count($entries)];
}

/** Check a verification preview against the chosen month before anything is imported. */
function guided_check_preview(array $company, array $input): array
{
    $companyId = (string)$company['id'];
    $bank = guided_bank_account($companyId, clean_text($input['bankAccountId'] ?? '', 'Bank account', 64));
    $fy = guided_fiscal_year(safe_date($input['fiscalYearEnd'] ?? '', 'Fiscal year end'));
    $month = (string)($input['month'] ?? '');
    if (!in_array($month, $fy['months'], true)) fail('Choose a month of the selected fiscal year.', 422, 'guided_month_invalid');
    $q = db()->prepare('SELECT * FROM statement_previews WHERE id=? AND company_id=? AND bank_account_id=?');
    $q->execute([clean_text($input['previewId'] ?? '', 'Preview', 64), $companyId, (string)$bank['id']]);
    $preview = $q->fetch();
    if (!$preview) fail('The statement preview is no longer available. Select the file again.', 404, 'guided_preview_missing');
    $exists = db()->prepare('SELECT id FROM guided_statement_months WHERE company_id=? AND bank_account_id=? AND statement_month=?');
    $exists->execute([$companyId, (string)$bank['id'], $month]);
    $problems = [];
    if ($exists->fetchColumn()) $problems[] = 'A statement is already uploaded for ' . guided_month_label($month) . '. Remove it first to upload a different one.';
    $rows = json_decode((string)$preview['rows_json'], true) ?: [];
    $dates = []; $beforeCents = 0; $afterCents = 0;
    foreach ($rows as $row) {
        $d = (string)($row['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        $dates[] = $d;
        $c = (int)($row['amountCents'] ?? $row['foreignAmountCents'] ?? 0);
        if ($d < $fy['start']) $beforeCents += $c; elseif ($d > $fy['end']) $afterCents += $c;
    }
    sort($dates);
    $first = $dates[0] ?? (string)$preview['first_transaction_date'];
    $last = $dates ? end($dates) : (string)$preview['last_transaction_date'];
    $suggestedMonth = $last !== '' ? substr($last, 0, 7) : '';
    if ($last !== '' && !guided_allowed_month($last, $month)) {
        $problems[] = 'This statement\'s last transaction is on ' . (new DateTimeImmutable($last))->format('F j, Y') . ', so it was issued in '
            . guided_month_label(substr($last, 0, 7)) . ' or early ' . guided_month_label(guided_next_month(substr($last, 0, 7)))
            . '. Upload it against that month instead of ' . guided_month_label($month) . '.';
    }
    // Lines already in the books for this account (imported before, here or in Banking).
    $already = 0;
    if ($first !== '' && $last !== '') {
        $a = db()->prepare("SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND bank_account_id=? AND status<>'duplicate' AND transaction_date BETWEEN ? AND ?");
        $a->execute([$companyId, (string)$bank['id'], $first, $last]);
        $already = (int)$a->fetchColumn();
    }
    $before = count(array_filter($dates, static fn(string $d): bool => $d < $fy['start']));
    $after = count(array_filter($dates, static fn(string $d): bool => $d > $fy['end']));
    return ['ok' => !$problems, 'problems' => $problems, 'firstDate' => $first, 'lastDate' => $last, 'lineCount' => count($rows),
        'beforeFiscalYear' => $before, 'afterFiscalYear' => $after, 'beforeFiscalYearCents' => $beforeCents, 'afterFiscalYearCents' => $afterCents,
        'fiscalYearStart' => $fy['start'], 'fiscalYearEnd' => $fy['end'], 'suggestedMonth' => $suggestedMonth, 'linesAlreadyInPeriod' => $already,
        'closingBalanceCents' => $preview['closing_balance_cents'] === null ? null : (int)$preview['closing_balance_cents']];
}

/** Record an approved import batch as the statement of a month. */
function guided_attach_batch(array $user, array $company, array $input): array
{
    $companyId = (string)$company['id'];
    $bank = guided_bank_account($companyId, clean_text($input['bankAccountId'] ?? '', 'Bank account', 64));
    $fy = guided_fiscal_year(safe_date($input['fiscalYearEnd'] ?? '', 'Fiscal year end'));
    $month = (string)($input['month'] ?? '');
    if (!in_array($month, $fy['months'], true)) fail('Choose a month of the selected fiscal year.', 422, 'guided_month_invalid');
    $batchId = clean_text($input['batchId'] ?? '', 'Import batch', 64);
    $b = db()->prepare('SELECT id, closing_balance_cents FROM import_batches WHERE id=? AND company_id=? AND bank_account_id=?');
    $b->execute([$batchId, $companyId, (string)$bank['id']]);
    $batch = $b->fetch();
    if (!$batch) fail('That statement import is not available for this account.', 404, 'guided_batch_missing');
    $q = db()->prepare("SELECT MIN(transaction_date) a, MAX(transaction_date) z, COUNT(*) n FROM bank_transactions WHERE company_id=? AND import_batch_id=?");
    $q->execute([$companyId, $batchId]);
    $s = $q->fetch();
    if (!$s || (int)$s['n'] === 0) fail('The statement import has no transactions.', 422, 'guided_batch_empty');
    if (!guided_allowed_month((string)$s['z'], $month)) fail('This statement ends on ' . (string)$s['z'] . '. Upload it against the month it was issued.', 422, 'guided_month_mismatch');
    $closing = $batch['closing_balance_cents'] === null ? null : (int)$batch['closing_balance_cents'];
    // A look at the month list between the import and this call may already have taken the import in; claim it.
    $linked = db()->prepare('SELECT m.* FROM guided_statement_batches gb JOIN guided_statement_months m ON m.id=gb.month_id WHERE gb.company_id=? AND gb.batch_id=?');
    $linked->execute([$companyId, $batchId]);
    if ($existing = $linked->fetch()) {
        if ((string)$existing['source'] === 'guided' || count(guided_month_batches($companyId, (string)$existing['id'])) !== 1) fail('This import already belongs to ' . guided_month_label((string)$existing['statement_month']) . '.', 409, 'guided_month_taken');
        $taken = db()->prepare('SELECT id FROM guided_statement_months WHERE company_id=? AND bank_account_id=? AND statement_month=? AND id<>?');
        $taken->execute([$companyId, (string)$bank['id'], $month, (string)$existing['id']]);
        if ($taken->fetchColumn()) fail('A statement is already recorded for ' . guided_month_label($month) . '.', 409, 'guided_month_taken');
        db()->prepare("UPDATE guided_statement_months SET statement_month=?, fiscal_year_end=?, source='guided', statement_closing_cents=COALESCE(statement_closing_cents,?), balance_source=COALESCE(balance_source,?), created_by=? WHERE id=? AND company_id=?")
            ->execute([$month, $fy['end'], $closing, $closing === null ? null : 'statement', (string)$user['id'], (string)$existing['id'], $companyId]);
        $id = (string)$existing['id'];
    } else {
        $id = new_id('gsm');
        try {
            db()->prepare("INSERT INTO guided_statement_months (id,company_id,bank_account_id,fiscal_year_end,statement_month,import_batch_id,source,statement_closing_cents,balance_source,created_by) VALUES (?,?,?,?,?,?,'guided',?,?,?)")
                ->execute([$id, $companyId, (string)$bank['id'], $fy['end'], $month, $batchId, $closing, $closing === null ? null : 'statement', (string)$user['id']]);
            db()->prepare('INSERT INTO guided_statement_batches (month_id, company_id, batch_id) VALUES (?,?,?)')->execute([$id, $companyId, $batchId]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                db()->prepare('DELETE FROM guided_statement_months WHERE id=? AND company_id=?')->execute([$id, $companyId]);
                fail('A statement is already recorded for ' . guided_month_label($month) . ', or this import already belongs to a month.', 409, 'guided_month_taken');
            }
            throw $e;
        }
    }
    guided_refresh_month($companyId, $id);
    $row = guided_month_row($companyId, $id);
    audit_event($user, $companyId, 'guided.statement_month_recorded', 'guided_statement_month', $id, ['month' => $month, 'bankAccountId' => (string)$bank['id'], 'importBatchId' => $batchId, 'lines' => (int)$row['line_count'], 'beforeFiscalYear' => (int)$row['before_fy_count'], 'afterFiscalYear' => (int)$row['after_fy_count']]);
    return ['id' => $id, 'month' => $month, 'lineCount' => (int)$row['line_count'], 'beforeFiscalYear' => (int)$row['before_fy_count'], 'afterFiscalYear' => (int)$row['after_fy_count'],
        'coverageStart' => $row['coverage_start'], 'coverageEnd' => $row['coverage_end'], 'statementClosingCents' => $closing];
}

/** Remove a month's statement while none of its lines has been posted or matched: the lines are deleted, the month is free again. */
function guided_remove_month(array $user, array $company, string $id): array
{
    $companyId = (string)$company['id'];
    $row = guided_month_row($companyId, $id);
    $batches = guided_month_batches($companyId, $id);
    $counts = guided_batch_counts($companyId, $batches);
    if ($counts['posted'] > 0 || $counts['matched'] > 0) fail('Some lines of this statement are already posted or matched. Void them first, or keep the statement.', 409, 'guided_month_has_postings');
    $ids = [];
    if ($batches) {
        $q = db()->prepare("SELECT id FROM bank_transactions WHERE company_id=? AND import_batch_id IN (" . guided_placeholders($batches) . ") AND status IN ('pending','excluded','duplicate') AND journal_entry_id IS NULL");
        $q->execute(array_merge([$companyId], $batches));
        $ids = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach (array_chunk($ids, 100) as $chunk) bank_transaction_delete_service($user, $company, $chunk, 'manual');
    db()->prepare('DELETE FROM guided_statement_batches WHERE month_id=? AND company_id=?')->execute([$id, $companyId]);
    db()->prepare('DELETE FROM guided_statement_months WHERE id=? AND company_id=?')->execute([$id, $companyId]);
    audit_event($user, $companyId, 'guided.statement_month_removed', 'guided_statement_month', $id, ['month' => (string)$row['statement_month'], 'linesDeleted' => count($ids)]);
    return ['removed' => true, 'linesDeleted' => count($ids)];
}

/* ---------------------------------------------------------------- mapping groups */

/** The lines of a statement month, each with its state: pending, matched, posted or excluded. */
function guided_month_lines(string $companyId, string $monthId): array
{
    $batches = guided_month_batches($companyId, $monthId);
    if (!$batches) return [];
    $q = db()->prepare('SELECT bt.id,bt.transaction_date,bt.description,bt.reference,bt.amount_cents,bt.status,bt.suggested_account_id,bt.decided_account_id,bt.tax_code,bt.confidence,bt.normalized_merchant,bt.bank_account_id,' . GUIDED_MATCHED_SQL . ' matched
        FROM bank_transactions bt WHERE bt.company_id=? AND bt.import_batch_id IN (' . guided_placeholders($batches) . ') ORDER BY bt.transaction_date,bt.source_row_number,bt.id');
    $q->execute(array_merge([$companyId], $batches));
    $lines = $q->fetchAll();
    foreach ($lines as &$line) {
        $s = (string)$line['status'];
        $line['state'] = $s === 'posted' ? 'posted' : ($s === 'pending' ? ((int)$line['matched'] ? 'matched' : 'pending') : 'excluded');
    }
    return $lines;
}

function guided_line_key(array $line): string
{
    return guided_keyword((string)$line['description']) . '|' . ((int)$line['amount_cents'] >= 0 ? 'in' : 'out');
}

function guided_mapping(array $company, string $monthId): array
{
    $companyId = (string)$company['id'];
    $row = guided_month_row($companyId, $monthId);
    $accounts = [];
    $qa = db()->prepare('SELECT id,code,name,account_type,active,is_control FROM accounts WHERE company_id=?');
    $qa->execute([$companyId]);
    foreach ($qa->fetchAll() as $a) $accounts[(string)$a['id']] = $a;
    $registered = !empty($company['tax_registered']) || !empty($company['pst_registered']);
    $groups = [];
    $totals = ['lines' => 0, 'pending' => 0, 'posted' => 0, 'matched' => 0, 'excluded' => 0, 'mapped' => 0, 'unmapped' => 0, 'taxToChoose' => 0];
    foreach (guided_month_lines($companyId, $monthId) as $line) {
        $totals['lines']++;
        $state = (string)$line['state'];
        $totals[$state]++;
        $key = guided_line_key($line);
        [$keyword, $direction] = explode('|', $key);
        if (!isset($groups[$key])) $groups[$key] = ['key' => $key, 'keyword' => $keyword, 'direction' => $direction, 'count' => 0, 'pending' => 0, 'posted' => 0, 'matched' => 0, 'excluded' => 0,
            'totalCents' => 0, 'pendingCents' => 0, 'examples' => [], 'votes' => [], 'firstPending' => null];
        $g = &$groups[$key];
        $g['count']++; $g['totalCents'] += (int)$line['amount_cents']; $g[$state]++;
        if (count($g['examples']) < 3 && !in_array((string)$line['description'], $g['examples'], true)) $g['examples'][] = (string)$line['description'];
        if ($state === 'pending') {
            $g['pendingCents'] += (int)$line['amount_cents'];
            $g['firstPending'] ??= $line;
            $suggested = (string)($line['suggested_account_id'] ?? '');
            if ($suggested !== '' && isset($accounts[$suggested])) {
                $vote = $suggested . '|' . guided_tax_choice($company, (string)$line['tax_code']);
                $g['votes'][$vote] = ($g['votes'][$vote] ?? 0) + 1;
            }
        }
        unset($g);
    }
    $out = [];
    foreach ($groups as $g) {
        $suggestion = null; $mixed = count($g['votes']) > 1; $source = 'statement';
        if ($g['votes']) { arsort($g['votes']); [$accountId, $tax] = explode('|', (string)array_key_first($g['votes']), 2); $suggestion = ['accountId' => $accountId, 'tax' => $tax]; }
        elseif ($g['firstPending'] && function_exists('tegh_research_local_suggestion')) {
            // Rules learned from earlier months (company rules and posting history).
            $local = tegh_research_local_suggestion($company, $g['firstPending']);
            if ($local) { $suggestion = ['accountId' => (string)$local['account']['id'], 'tax' => guided_tax_choice($company, (string)($local['taxCode'] ?? 'NO_TAX'))]; $source = 'history'; }
        }
        $account = $suggestion ? ($accounts[$suggestion['accountId']] ?? null) : null;
        // A registrant's deposits to income: whether they include GST/HST is the person's choice, never a default.
        $taxRequired = $registered && $g['direction'] === 'in' && $account && in_array((string)$account['account_type'], ['revenue', 'income'], true) && $source !== 'history';
        if ($taxRequired && $suggestion) $suggestion['tax'] = '';
        $mappedLines = $suggestion ? $g['pending'] : 0;
        $totals['mapped'] += $mappedLines; $totals['unmapped'] += $g['pending'] - $mappedLines;
        if ($taxRequired && $g['pending'] > 0) $totals['taxToChoose'] += $g['pending'];
        $out[] = ['key' => $g['key'], 'keyword' => $g['keyword'], 'direction' => $g['direction'], 'count' => $g['count'], 'pending' => $g['pending'], 'posted' => $g['posted'], 'matched' => $g['matched'], 'excluded' => $g['excluded'],
            'totalCents' => $g['totalCents'], 'pendingCents' => $g['pendingCents'], 'examples' => $g['examples'],
            'suggestedAccountId' => $suggestion['accountId'] ?? null, 'suggestedAccount' => $account ? trim((string)$account['code'] . ' · ' . (string)$account['name']) : null,
            'suggestedTax' => $suggestion['tax'] ?? 'NO_TAX', 'taxRequired' => $taxRequired, 'suggestionSource' => $suggestion ? $source : null, 'mixedSuggestions' => $mixed];
    }
    usort($out, static fn(array $a, array $b): int => [$b['pending'] > 0, $b['count'], $a['keyword']] <=> [$a['pending'] > 0, $a['count'], $b['keyword']]);
    $entry = guided_month_entry($company, $row);
    return ['month' => ['id' => (string)$row['id'], 'month' => (string)$row['statement_month'], 'label' => guided_month_label((string)$row['statement_month']), 'bankAccountId' => (string)$row['bank_account_id'],
        'coverageStart' => $row['coverage_start'], 'coverageEnd' => $row['coverage_end'], 'beforeFiscalYear' => (int)$row['before_fy_count'], 'afterFiscalYear' => (int)$row['after_fy_count'],
        'source' => (string)$row['source'], 'reconciled' => $entry['reconciled'], 'reconciledIn' => $entry['reconciledIn'], 'excluded' => $entry['excluded'], 'excludedCents' => $entry['excludedCents'],
        'statementClosingCents' => $entry['statementClosingCents'], 'balanceSource' => $entry['balanceSource']], 'totals' => $totals, 'groups' => $out];
}

function guided_group_lines(array $company, string $monthId, string $groupKey, int $offset, int $limit): array
{
    $companyId = (string)$company['id'];
    guided_month_row($companyId, $monthId);
    $rows = [];
    foreach (guided_month_lines($companyId, $monthId) as $line) {
        if (guided_line_key($line) === $groupKey) $rows[] = ['id' => (string)$line['id'], 'date' => (string)$line['transaction_date'], 'description' => (string)$line['description'], 'reference' => (string)($line['reference'] ?? ''), 'amountCents' => (int)$line['amount_cents'], 'status' => $line['state'] === 'excluded' ? (string)$line['status'] : (string)$line['state']];
    }
    return ['total' => count($rows), 'offset' => $offset, 'lines' => array_slice($rows, $offset, $limit)];
}

function guided_require_tax(string $tax): string
{
    if ($tax === '') fail('Choose whether these deposits include GST/HST.', 422, 'guided_tax_required');
    return $tax;
}

/**
 * Post the next (up to 100) pending lines of a group with one account and tax choice, through the normal posting
 * service. Lines named in skipIds (posted on their own, or left for later) are not touched. A line the posting service
 * refuses (a closed period, for example) is reported and left pending; the others are posted.
 */
function guided_post_group(array $user, array $company, array $input): array
{
    $companyId = (string)$company['id'];
    $row = guided_month_row($companyId, clean_text($input['monthId'] ?? '', 'Statement month', 64));
    $groupKey = (string)($input['groupKey'] ?? '');
    $accountId = clean_text($input['accountId'] ?? '', 'Account', 64);
    $tax = guided_require_tax((string)($input['tax'] ?? 'NO_TAX'));
    $skip = array_fill_keys(array_map('strval', is_array($input['skipIds'] ?? null) ? $input['skipIds'] : []), true);
    $failedBefore = array_fill_keys(array_map('strval', is_array($input['failedIds'] ?? null) ? $input['failedIds'] : []), true);
    $todo = [];
    foreach (guided_month_lines($companyId, (string)$row['id']) as $line) {
        if ($line['state'] !== 'pending' || isset($skip[(string)$line['id']]) || isset($failedBefore[(string)$line['id']])) continue;
        if (guided_line_key($line) === $groupKey) $todo[] = $line;
    }
    return guided_post_lines($user, $company, array_slice($todo, 0, 100), static fn(array $l): array => [$accountId, $tax]) + ['remaining' => max(0, count($todo) - 100)];
}

/** Post individual lines (per-line changes inside a group): [{id, accountId, tax}], at most 100. */
function guided_post_single_lines(array $user, array $company, array $input): array
{
    $companyId = (string)$company['id'];
    $row = guided_month_row($companyId, clean_text($input['monthId'] ?? '', 'Statement month', 64));
    $wanted = [];
    foreach (array_slice(is_array($input['lines'] ?? null) ? $input['lines'] : [], 0, 100) as $l) if (is_array($l)) $wanted[(string)($l['id'] ?? '')] = [clean_text($l['accountId'] ?? '', 'Account', 64), guided_require_tax((string)($l['tax'] ?? 'NO_TAX'))];
    $todo = [];
    foreach (guided_month_lines($companyId, (string)$row['id']) as $line) if ($line['state'] === 'pending' && isset($wanted[(string)$line['id']])) $todo[] = $line;
    return guided_post_lines($user, $company, $todo, static fn(array $l): array => $wanted[(string)$l['id']]);
}

function guided_post_lines(array $user, array $company, array $lines, callable $choice): array
{
    if (!$lines) return ['posted' => 0, 'failed' => []];
    $decisions = array_map(static function (array $l) use ($company, $choice): array { [$accountId, $tax] = $choice($l); return guided_decision($company, (string)$l['id'], (string)$accountId, (string)$tax, (string)$l['description']); }, $lines);
    try {
        $posted = tegh_service_boundary(static fn(): int => bank_transaction_post_service($user, $company, $decisions, 'guided'));
        return ['posted' => (int)$posted, 'failed' => []];
    } catch (TeghServiceFailure $whole) {
        // One line refused: post the others one by one and report the refused lines.
        $posted = 0; $failed = [];
        foreach ($decisions as $decision) {
            try { $posted += (int)tegh_service_boundary(static fn(): int => bank_transaction_post_service($user, $company, [$decision], 'guided')); }
            catch (TeghServiceFailure $e) { $failed[] = ['id' => (string)$decision['id'], 'message' => $e->getMessage()]; }
        }
        return ['posted' => $posted, 'failed' => $failed];
    }
}

/* ---------------------------------------------------------------- reconciliation */

/**
 * When no line of the statement is left to post, compare Tegh's bank balance on the statement's last date with the
 * statement's closing balance, and complete the bank reconciliation for the statement period only when they agree.
 * Each posted line carries its own journal, so the reconciliation includes it without a separate match. A statement
 * whose posted lines are all in a completed reconciliation (done in Bank Reconciliation) is recorded as reconciled.
 */
function guided_reconcile_month(array $user, array $company, string $monthId, array $input = []): array
{
    $companyId = (string)$company['id'];
    $row = guided_month_row($companyId, $monthId);
    if ($row['reconciliation_id'] !== null) return ['reconciled' => true, 'matched' => 0, 'remaining' => 0, 'reconciliationId' => (string)$row['reconciliation_id']];
    $batches = guided_month_batches($companyId, $monthId);
    $counts = guided_batch_counts($companyId, $batches);
    $base = ['reconciled' => false, 'matched' => 0, 'remaining' => 0, 'excluded' => $counts['excluded'], 'excludedCents' => $counts['excludedCents'], 'statementEnd' => $row['coverage_end']];
    if ($counts['pending'] > 0) return $base + ['reason' => $counts['pending'] . ' line' . ($counts['pending'] === 1 ? ' is' : 's are') . ' not posted yet. Post or exclude every line before reconciling.'];
    $bank = guided_bank_account($companyId, (string)$row['bank_account_id']);
    if ($counts['posted'] > 0 && $counts['cleared'] === $counts['posted']) {
        $q = db()->prepare('SELECT r.id FROM bank_transactions bt JOIN reconciliation_items ri ON ri.bank_transaction_id=bt.id JOIN reconciliations r ON r.id=ri.reconciliation_id AND r.status=\'complete\' AND r.company_id=bt.company_id
            WHERE bt.company_id=? AND bt.import_batch_id IN (' . guided_placeholders($batches) . ') ORDER BY r.period_end DESC LIMIT 1');
        $q->execute(array_merge([$companyId], $batches));
        $rid = (string)$q->fetchColumn();
        db()->prepare('UPDATE guided_statement_months SET reconciliation_id=?, reconciled_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?')->execute([$rid, $monthId, $companyId]);
        audit_event($user, $companyId, 'guided.statement_month_reconciled', 'guided_statement_month', $monthId, ['reconciliationId' => $rid, 'in' => 'bank-reconciliation']);
        return ['reconciled' => true, 'matched' => 0, 'remaining' => 0, 'reconciliationId' => $rid, 'reconciledIn' => 'bank-reconciliation'];
    }
    // The statement's closing balance: typed now, kept from before, or read from the statement file.
    if (array_key_exists('closingBalanceCents', $input) && $input['closingBalanceCents'] !== null && $input['closingBalanceCents'] !== '') {
        $entered = safe_cents($input['closingBalanceCents'], 'Closing balance', true);
        db()->prepare("UPDATE guided_statement_months SET statement_closing_cents=?, balance_source='entered' WHERE id=? AND company_id=?")->execute([$entered, $monthId, $companyId]);
        $row['statement_closing_cents'] = $entered; $row['balance_source'] = 'entered';
    }
    if ($row['statement_closing_cents'] === null && $batches) {
        $q = db()->prepare('SELECT ib.closing_balance_cents FROM import_batches ib WHERE ib.company_id=? AND ib.id IN (' . guided_placeholders($batches) . ') AND ib.closing_balance_cents IS NOT NULL
            ORDER BY (SELECT MAX(transaction_date) FROM bank_transactions bt WHERE bt.import_batch_id=ib.id AND bt.company_id=ib.company_id) DESC LIMIT 1');
        $q->execute(array_merge([$companyId], $batches));
        $fromFile = $q->fetchColumn();
        if ($fromFile !== false && $fromFile !== null) {
            $row['statement_closing_cents'] = (int)$fromFile; $row['balance_source'] = 'statement';
            db()->prepare("UPDATE guided_statement_months SET statement_closing_cents=?, balance_source='statement' WHERE id=? AND company_id=?")->execute([(int)$fromFile, $monthId, $companyId]);
        }
    }
    $isCard = (string)$bank['account_type'] === 'credit_card';
    if ($row['statement_closing_cents'] === null) return $base + ['needsBalance' => true, 'isCard' => $isCard,
        'reason' => 'Enter the closing balance printed on the statement (on ' . (new DateTimeImmutable((string)$row['coverage_end']))->format('F j, Y') . ' or the statement date) so Tegh can check that nothing is missing.'];
    require_once __DIR__ . '/reconciliation_position_r122.php';
    $start = (string)$row['coverage_start']; $end = (string)$row['coverage_end'];
    $position = tegh_recon_position_r122($companyId, $bank, $start, $end);
    $tegh = (int)$position['bank']['closingCents'];
    $statement = (int)$row['statement_closing_cents'];
    // A card statement shows the balance owed as a positive number; Tegh holds it as money out (negative).
    $agrees = $statement === $tegh || ($isCard && $row['balance_source'] === 'entered' && $statement === -$tegh);
    if (!$agrees) {
        $difference = ($isCard && $row['balance_source'] === 'entered' && $statement > 0 && $tegh < 0) ? $statement + $tegh : $statement - $tegh;
        $hints = [];
        $o = db()->prepare("SELECT COUNT(*) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id=? AND je.status='posted' AND je.source_type='opening_balance' AND jl.account_id=?");
        $o->execute([$companyId, (string)$bank['ledger_account_id']]);
        if ((int)$o->fetchColumn() === 0) $hints[] = 'This account has no opening balance in Tegh. If the first statement starts with a balance, record it as the opening balance.';
        if ($counts['excluded'] > 0) $hints[] = $counts['excluded'] . ' line' . ($counts['excluded'] === 1 ? ' of this statement is' : 's of this statement are') . ' excluded or marked as duplicates (' . number_format(abs($counts['excludedCents']) / 100, 2) . '). Check that they really are not on the statement.';
        $hints[] = 'A line may be missing from the file, or the balance may have been typed differently.';
        return $base + ['needsBalance' => true, 'isCard' => $isCard, 'statementClosingCents' => $statement, 'teghClosingCents' => $tegh, 'differenceCents' => $difference, 'hints' => $hints,
            'reason' => 'The statement says ' . number_format($statement / 100, 2) . ' but Tegh\'s balance for this account on ' . (new DateTimeImmutable($end))->format('F j, Y') . ' is ' . number_format($tegh / 100, 2) . '.'];
    }
    // The period runs from the statement's first line (or the day after the last completed reconciliation) to its last line.
    $last = db()->prepare("SELECT MAX(period_end) FROM reconciliations WHERE company_id=? AND bank_account_id=? AND status='complete'");
    $last->execute([$companyId, (string)$bank['id']]);
    $lastEnd = (string)($last->fetchColumn() ?: '');
    if ($lastEnd !== '' && $lastEnd >= $start) $start = (new DateTimeImmutable($lastEnd))->modify('+1 day')->format('Y-m-d');
    if ($start > $end) return $base + ['reason' => 'A completed reconciliation to ' . $lastEnd . ' already covers this statement period, but ' . ($counts['posted'] - $counts['cleared']) . ' posted line(s) of it are not in it. Reopen that reconciliation in Bank Reconciliation to include them.'];
    try {
        $result = tegh_service_boundary(static function () use ($user, $company, $bank, $start, $end, $row): array {
            operations_assert_reconciliation_range_open((string)$company['id'], $start, $end);
            return reconciliation_save_service($user, $company, ['bankAccountId' => (string)$bank['id'], 'periodStart' => $start, 'periodEnd' => $end, 'status' => 'complete',
                'notes' => 'Guided statement for ' . guided_month_label((string)$row['statement_month']) . ': closing balance ' . number_format((int)$row['statement_closing_cents'] / 100, 2) . ' (' . ($row['balance_source'] === 'entered' ? 'typed from the statement' : 'from the statement file') . ') agrees with Tegh.'])[0];
        });
    } catch (TeghServiceFailure $e) {
        return $base + ['reason' => $e->getMessage()];
    }
    $reconciliationId = (string)$result['reconciliation']['id'];
    db()->prepare('UPDATE guided_statement_months SET reconciliation_id=?, reconciled_at=UTC_TIMESTAMP(), balance_checked_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?')->execute([$reconciliationId, $monthId, $companyId]);
    audit_event($user, $companyId, 'guided.statement_month_reconciled', 'guided_statement_month', $monthId, ['reconciliationId' => $reconciliationId, 'periodStart' => $start, 'periodEnd' => $end, 'statementClosingCents' => $statement, 'balanceSource' => $row['balance_source']]);
    return ['reconciled' => true, 'matched' => 0, 'remaining' => 0, 'reconciliationId' => $reconciliationId, 'periodStart' => $start, 'periodEnd' => $end, 'statementClosingCents' => $statement];
}

/* ---------------------------------------------------------------- accountant review and quote */

function guided_accountant_rate_cents(): int
{
    $rate = (int)(config('accountant.hourly_rate_cents') ?? 3000);
    return $rate > 0 ? $rate : 3000;
}

/**
 * Findings with the time each needs. Minutes stay on the server; the browser gets the findings and the total.
 * @return array{findings:array<int,array{id:string,title:string,detail:string,count:int,severity:string,minutes:int}>,minutes:int}
 */
function guided_accountant_review(array $company): array
{
    $companyId = (string)$company['id'];
    $one = static function (string $sql, array $params) { $q = db()->prepare($sql); $q->execute($params); return $q->fetchColumn(); };
    $findings = [];
    $add = static function (string $id, string $title, string $detail, int $count, string $severity, int $minutes) use (&$findings): void {
        if ($minutes > 0) $findings[] = ['id' => $id, 'title' => $title, 'detail' => $detail, 'count' => $count, 'severity' => $severity, 'minutes' => $minutes];
    };
    $pending = (int)$one("SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND status='pending'", [$companyId]);
    if ($pending > 0) $add('bank-lines', 'Bank lines to categorize and post', $pending . ' imported bank line' . ($pending === 1 ? ' is' : 's are') . ' not posted yet.', $pending, 'pending', max(15, (int)ceil(min($pending, 200) * 1.0 + max(0, $pending - 200) * 0.25)));
    $suspense = (int)$one("SELECT COUNT(*) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='posted' JOIN accounts a ON a.id=jl.account_id WHERE je.company_id=? AND a.code='6999'", [$companyId]);
    if ($suspense > 0) $add('unassigned', 'Postings to Unassigned Expense', $suspense . ' posting' . ($suspense === 1 ? '' : 's') . ' to 6999 Unassigned Expense ' . ($suspense === 1 ? 'needs' : 'need') . ' a proper category.', $suspense, 'mistake', max(15, $suspense * 5));
    $difference = (int)$one("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='posted' WHERE je.company_id=?", [$companyId]);
    if ($difference !== 0) $add('trial-balance', 'Trial balance out of balance', 'Debits and credits differ by ' . number_format(abs($difference) / 100, 2) . '.', 1, 'mistake', 120);
    // Months with posted bank activity after the last completed reconciliation, per bank account.
    $q = db()->prepare("SELECT ba.id, ba.name, COUNT(DISTINCT DATE_FORMAT(bt.transaction_date,'%Y-%m')) months FROM bank_accounts ba
        JOIN bank_transactions bt ON bt.bank_account_id=ba.id AND bt.company_id=ba.company_id AND bt.status='posted'
        WHERE ba.company_id=? AND ba.active=1 AND bt.transaction_date > COALESCE((SELECT MAX(r.period_end) FROM reconciliations r WHERE r.company_id=ba.company_id AND r.bank_account_id=ba.id AND r.status='complete'),'1900-01-01')
        GROUP BY ba.id, ba.name");
    $q->execute([$companyId]);
    $unreconciled = 0; $names = [];
    foreach ($q->fetchAll() as $r) { $unreconciled += (int)$r['months']; $names[] = (string)$r['name']; }
    if ($unreconciled > 0) $add('reconciliation', 'Bank months not reconciled', $unreconciled . ' month' . ($unreconciled === 1 ? '' : 's') . ' of posted bank activity (' . implode(', ', array_slice($names, 0, 3)) . ') without a completed reconciliation.', $unreconciled, 'pending', $unreconciled * 30);
    // Months of the current fiscal year, to last month, with no statement for an account that has statements.
    $years = guided_fiscal_years($company);
    $fy = guided_fiscal_year($years['current']);
    $today = function_exists('canadian_today') ? canadian_today() : date('Y-m-d');
    $lastMonth = (new DateTimeImmutable(substr($today, 0, 7) . '-01'))->modify('-1 month')->format('Y-m');
    $q = db()->prepare("SELECT ba.id, GROUP_CONCAT(DISTINCT DATE_FORMAT(bt.transaction_date,'%Y-%m')) months FROM bank_accounts ba JOIN bank_transactions bt ON bt.bank_account_id=ba.id AND bt.company_id=ba.company_id WHERE ba.company_id=? AND ba.active=1 GROUP BY ba.id");
    $q->execute([$companyId]);
    $missing = 0;
    foreach ($q->fetchAll() as $r) {
        $have = array_flip(explode(',', (string)$r['months']));
        foreach ($fy['months'] as $m) if ($m <= $lastMonth && $m >= substr((string)($company['books_start_date'] ?? $fy['start']), 0, 7) && !isset($have[$m])) $missing++;
    }
    if ($missing > 0) $add('missing-statements', 'Months without bank activity', $missing . ' month' . ($missing === 1 ? '' : 's') . ' of this fiscal year, across the bank accounts, have no bank transactions in Tegh. Their statements may still need importing.', $missing, 'pending', $missing * 15);
    $summary = function_exists('tegh_workspace_summary_data') ? (tegh_workspace_summary_data($company)['summary'] ?? []) : [];
    if (!empty($summary['receivableControlApplicable']) && (int)($summary['receivableDifferenceCents'] ?? 0) !== 0) $add('receivables', 'Customer balances do not agree with Accounts Receivable', 'The difference is ' . number_format(abs((int)$summary['receivableDifferenceCents']) / 100, 2) . '.', 1, 'mistake', 90);
    if (!empty($summary['payableControlApplicable']) && (int)($summary['payableDifferenceCents'] ?? 0) !== 0) $add('payables', 'Vendor balances do not agree with Accounts Payable', 'The difference is ' . number_format(abs((int)$summary['payableDifferenceCents']) / 100, 2) . '.', 1, 'mistake', 90);
    $duplicates = (int)$one("SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND status='duplicate'", [$companyId]);
    if ($duplicates > 0) $add('duplicates', 'Possible duplicate bank lines', $duplicates . ' line' . ($duplicates === 1 ? '' : 's') . ' flagged as possible duplicates to check.', $duplicates, 'mistake', $duplicates * 5);
    $excluded = (int)$one("SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND status='excluded'", [$companyId]);
    if ($excluded > 0) $add('excluded', 'Excluded bank lines to confirm', $excluded . ' bank line' . ($excluded === 1 ? ' is' : 's are') . ' excluded from the books. Each should be confirmed as not belonging to the business.', $excluded, 'mistake', max(10, $excluded * 2));
    if (schema_table_exists('guided_statement_months')) {
        $unchecked = (int)$one("SELECT COUNT(*) FROM guided_statement_months WHERE company_id=? AND reconciliation_id IS NULL", [$companyId]);
        if ($unchecked > 0) $add('balance-unchecked', 'Statements without a balance check', $unchecked . ' uploaded statement' . ($unchecked === 1 ? ' has' : 's have') . ' not been checked against the closing balance on the statement.', $unchecked, 'pending', $unchecked * 15);
    }
    $drafts = (int)$one("SELECT (SELECT COUNT(*) FROM invoices WHERE company_id=? AND status='draft') + (SELECT COUNT(*) FROM bills WHERE company_id=? AND status='draft')", [$companyId, $companyId]);
    if ($drafts > 0) $add('drafts', 'Draft invoices and vendor invoices', $drafts . ' draft document' . ($drafts === 1 ? '' : 's') . ' to finish or remove.', $drafts, 'pending', $drafts * 6);
    $opening = (int)$one("SELECT COUNT(*) FROM journal_entries WHERE company_id=? AND status='posted' AND source_type LIKE 'opening%'", [$companyId]);
    $activity = (int)$one("SELECT COUNT(*) FROM journal_entries WHERE company_id=? AND status='posted'", [$companyId]);
    if ($opening === 0 && $activity > 0) $add('opening', 'Opening balances not recorded', 'No opening balances are posted. Balance sheet accounts may start from zero by mistake.', 1, 'pending', 60);
    $taxActivity = (int)$one("SELECT COUNT(*) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='posted' JOIN accounts a ON a.id=jl.account_id WHERE je.company_id=? AND a.code IN ('2100','1100','2110','1110','2115','1115')", [$companyId]);
    if ($taxActivity > 0 && !empty($company['tax_registered'])) $add('sales-tax', 'GST/HST return preparation', 'Sales tax collected and paid this year should be reconciled to the return for each filing period.', 1, 'pending', 90);
    $minutes = array_sum(array_column($findings, 'minutes'));
    if ($minutes > 0) { $add('review', 'Final review of the statements', 'Review of the trial balance, profit and loss and balance sheet once the work is done.', 1, 'pending', 60); $minutes += 60; }
    return ['findings' => $findings, 'minutes' => $minutes];
}

/** What the browser sees: findings without minutes, and one total. */
function guided_accountant_quote(array $company): array
{
    $review = guided_accountant_review($company);
    $hundredths = (int)(ceil($review['minutes'] / 15) * 25); // hours to the next quarter hour, in hundredths
    $quote = (int)round($hundredths * guided_accountant_rate_cents() / 100);
    return ['review' => $review, 'hoursHundredths' => $hundredths, 'quoteCents' => $quote,
        'public' => ['findings' => array_map(static fn(array $f): array => array_diff_key($f, ['minutes' => true]), $review['findings']),
            'quoteCents' => $quote, 'currency' => 'CAD', 'taxNote' => 'An estimate for the bookkeeping work listed, plus applicable GST/HST. It does not include tax returns (GST/HST, T2 or personal) or payroll filings. A Tegh accountant confirms the scope and the price with you before starting.']];
}

function guided_accountant_request(array $user, array $company, array $input): array
{
    $companyId = (string)$company['id'];
    $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 2000);
    $phone = mb_substr(preg_replace('/[^0-9 +().-]/', '', (string)($input['phone'] ?? '')) ?? '', 0, 40);
    $rq = db()->prepare('SELECT COUNT(*) FROM accountant_requests WHERE company_id=? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
    $rq->execute([$companyId]);
    $recent = (int)$rq->fetchColumn();
    if ($recent >= 5) fail('Several requests were sent in the last hour. The Tegh accountants will reply to the earlier one.', 429, 'accountant_request_rate_limited');
    $quote = guided_accountant_quote($company);
    $to = (string)(config('accountant.request_email') ?? 'enquiry@sraccountax.ca');
    $id = new_id('acctreq');
    $email = (string)($user['email'] ?? '');
    db()->prepare('INSERT INTO accountant_requests (id,company_id,requested_by,requested_by_email,note,findings_json,hours_hundredths,rate_cents,quote_cents,currency) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $companyId, (string)$user['id'], $email, $note, json_encode($quote['review']['findings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $quote['hoursHundredths'], guided_accountant_rate_cents(), $quote['quoteCents'], 'CAD']);
    $companyName = (string)($company['name'] ?? 'Company');
    $money = static fn(int $c): string => '$' . number_format($c / 100, 2) . ' CAD';
    // The internal email carries the breakdown (time per finding and rate); it goes to the Tegh accountants only.
    $lines = [];
    foreach ($quote['review']['findings'] as $f) $lines[] = '- ' . $f['title'] . ': ' . $f['detail'] . ' (' . number_format($f['minutes'] / 60, 2) . ' h)';
    $text = "New request to connect with an accountant.\n\nCompany: $companyName\nRequested by: " . ((string)($user['display_name'] ?? $user['displayName'] ?? '') ?: $email) . " <$email>" . ($phone !== '' ? "\nPhone: $phone" : '')
        . "\nRequest: $id\n\nFindings:\n" . ($lines ? implode("\n", $lines) : '- No open items found.')
        . "\n\nEstimated time: " . number_format($quote['hoursHundredths'] / 100, 2) . ' h at ' . $money(guided_accountant_rate_cents()) . "/h\nQuote shown to the client: " . $money($quote['quoteCents']) . " (plus applicable GST/HST)\n"
        . ($note !== '' ? "\nClient's note:\n$note\n" : '');
    $status = 'pending';
    try {
        $sent = sr_mail_send($companyId, (string)$user['id'], $to, 'accountant_request', 'Tegh: accountant requested by ' . $companyName . ' (' . $money($quote['quoteCents']) . ')', $text);
        $status = !empty($sent['sent']) ? 'sent' : (string)($sent['status'] ?? 'not_sent');
    } catch (Throwable $e) {
        error_log('Tegh R163 accountant request email: ' . $e::class);
        $status = 'failed';
    }
    db()->prepare('UPDATE accountant_requests SET email_status=? WHERE id=?')->execute([$status, $id]);
    audit_event($user, $companyId, 'accountant.request_sent', 'accountant_request', $id, ['quoteCents' => $quote['quoteCents'], 'findings' => count($quote['review']['findings']), 'emailStatus' => $status]);
    // The draft the client can open in Outlook: the findings and the total, never the time or the rate.
    $draftLines = [];
    foreach ($quote['public']['findings'] as $f) $draftLines[] = '- ' . $f['title'] . ': ' . $f['detail'];
    $draft = ['to' => $to, 'subject' => 'Accountant request for ' . $companyName . ' (ref. ' . $id . ')',
        'body' => "Hello,\n\nI would like a Tegh accountant to complete the remaining bookkeeping for $companyName.\n\nWhat Tegh found:\n" . ($draftLines ? implode("\n", $draftLines) : '- A general review of the books.')
            . "\n\nEstimate shown in Tegh: " . $money($quote['quoteCents']) . " plus applicable GST/HST, for the bookkeeping work listed (tax returns and payroll filings not included).\nRequest reference: $id\n" . ($note !== '' ? "\nMy note:\n$note\n" : '') . ($phone !== '' ? "\nPhone: $phone\n" : '') . "\nThank you."];
    return ['requestId' => $id, 'emailStatus' => $status, 'quoteCents' => $quote['quoteCents'], 'currency' => 'CAD', 'draft' => $draft];
}

/* ---------------------------------------------------------------- routes */

function handle_guided_r163(string $action): never
{
    tegh_guided_r163_ready();
    $user = require_user();
    $company = require_company($user);
    $method = request_method();
    if ($action === 'accountant/review') {
        require_company_role($company, 'owner', 'admin', 'bookkeeper', 'editor');
        json_response(guided_accountant_quote($company)['public']);
    }
    if ($action === 'accountant/request') {
        require_method('POST'); require_csrf();
        require_company_role($company, 'owner', 'admin', 'bookkeeper', 'editor');
        json_response(guided_accountant_request($user, $company, request_json()), 201);
    }
    if ($action === 'statement-months' && $method === 'GET') {
        require_company_permission($company, 'banking.view');
        json_response(guided_months_state($user, $company, trim((string)($_GET['bankAccountId'] ?? '')), trim((string)($_GET['fiscalYearEnd'] ?? ''))));
    }
    if ($action === 'resume' && $method === 'GET') {
        require_company_permission($company, 'banking.view');
        json_response(guided_resume($user, $company));
    }
    if ($action === 'mapping' && $method === 'GET') {
        require_company_permission($company, 'banking.view');
        json_response(guided_mapping($company, clean_text($_GET['monthId'] ?? '', 'Statement month', 64)));
    }
    if ($action === 'group-lines' && $method === 'GET') {
        require_company_permission($company, 'banking.view');
        json_response(guided_group_lines($company, clean_text($_GET['monthId'] ?? '', 'Statement month', 64), (string)($_GET['group'] ?? ''), max(0, (int)($_GET['offset'] ?? 0)), max(1, min(200, (int)($_GET['limit'] ?? 100)))));
    }
    require_method('POST'); require_csrf();
    require_company_role($company, 'owner', 'bookkeeper');
    require_company_permission($company, 'banking.match');
    $input = request_json();
    $result = match ($action) {
        'statement-months/check' => guided_check_preview($company, $input),
        'statement-months/attach' => guided_attach_batch($user, $company, $input),
        'statement-months/remove' => guided_remove_month($user, $company, clean_text($input['monthId'] ?? '', 'Statement month', 64)),
        'post-group' => guided_post_group($user, $company, $input),
        'post-lines' => guided_post_single_lines($user, $company, $input),
        'reconcile' => guided_reconcile_month($user, $company, clean_text($input['monthId'] ?? '', 'Statement month', 64), $input),
        default => fail('Guided route not found.', 404, 'route_not_found'),
    };
    json_response($result);
}
