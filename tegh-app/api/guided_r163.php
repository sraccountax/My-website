<?php
declare(strict_types=1);

/**
 * R163: the Guided bank-statement wizard and "Connect with an accountant".
 *
 * Statement months. Guided mode books a fiscal year one bank statement at a time. The fiscal year (from the company's
 * fiscal year end) is shown as its months; each month takes the one statement issued in that month, which normally holds
 * transactions from the previous month too. The issue month is the month of the statement's last transaction or the
 * month after it (a statement is often issued a few days into the next month). Transactions dated before the fiscal year
 * starts or after it ends are imported like the others, and the person is told how many there are.
 *
 * Mapping. A statement's lines are grouped by a keyword taken from the description (the first two meaningful words,
 * reference numbers removed) and by direction, so a statement of thousands of lines becomes a short list of groups. Each
 * group carries the suggestion already made at import (company rules, history, built-in keyword rules) and can be
 * changed; one decision posts every line of the group through the normal posting service (same tax splits, period
 * locks and audit trail), 100 lines at a time.
 *
 * Reconciliation. Once every line of the statement is dealt with, the statement period's bank reconciliation, which
 * includes each posted line through its own journal, is completed by the normal reconciliation service, which completes it only
 * when the bank and the books agree to the cent.
 *
 * Accountant. guided_accountant_review() looks for unfinished work and likely mistakes and prices it. The hours and the
 * hourly rate stay on the server: the browser receives the findings and one total. A request is stored and emailed to
 * the Tegh accountants' address (config accountant.request_email, default enquiry@sraccountax.ca) with the breakdown.
 *
 * Tables, created on first use: guided_statement_months and accountant_requests.
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
            coverage_start DATE NULL,
            coverage_end DATE NULL,
            line_count INT NOT NULL DEFAULT 0,
            before_fy_count INT NOT NULL DEFAULT 0,
            after_fy_count INT NOT NULL DEFAULT 0,
            reconciliation_id VARCHAR(64) NULL,
            reconciled_at DATETIME NULL,
            created_by VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY guided_month_uq (company_id, bank_account_id, statement_month),
            UNIQUE KEY guided_month_batch_uq (company_id, import_batch_id),
            KEY guided_month_company (company_id, fiscal_year_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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

/** The fiscal year ending on $end (Y-m-d): start, end and its months (Y-m). */
function guided_fiscal_year(string $end): array
{
    $endDate = new DateTimeImmutable($end);
    $start = $endDate->modify('-1 year')->modify('+1 day');
    $months = [];
    for ($m = $start->modify('first day of this month'); $m <= $endDate; $m = $m->modify('+1 month')) $months[] = $m->format('Y-m');
    return ['start' => $start->format('Y-m-d'), 'end' => $endDate->format('Y-m-d'), 'months' => $months,
        'label' => 'Fiscal year ' . $start->format('M Y') . ' – ' . $endDate->format('M Y')];
}

/** Fiscal years the company can book: from the one holding its books start (or first bank line) to the current one. */
function guided_fiscal_years(array $company): array
{
    $mmdd = (string)($company['fiscal_year_end'] ?? '12-31');
    if (!preg_match('/^(\d{2})-(\d{2})$/', $mmdd, $m)) $mmdd = '12-31';
    $today = function_exists('canadian_today') ? canadian_today() : date('Y-m-d');
    $endFor = static function (string $date) use ($mmdd): string {
        $year = (int)substr($date, 0, 4);
        $candidate = sprintf('%04d-%s', $year, $mmdd);
        if (!checkdate((int)substr($mmdd, 0, 2), (int)substr($mmdd, 3, 2), $year)) $candidate = sprintf('%04d-%s', $year, substr($mmdd, 0, 3) . '28');
        return $candidate >= $date ? $candidate : (new DateTimeImmutable($candidate))->modify('+1 year')->format('Y-m-d');
    };
    $first = (string)($company['books_start_date'] ?? '') ?: $today;
    $q = db()->prepare('SELECT MIN(transaction_date) FROM bank_transactions WHERE company_id=?');
    $q->execute([(string)$company['id']]);
    $earliest = (string)($q->fetchColumn() ?: '');
    if ($earliest !== '' && $earliest < $first) $first = $earliest;
    $years = [];
    for ($end = $endFor($first); $end <= $endFor($today); $end = (new DateTimeImmutable($end))->modify('+1 year')->format('Y-m-d')) {
        $years[] = guided_fiscal_year($end);
        if (count($years) > 15) break;
    }
    return ['years' => array_reverse($years), 'current' => $endFor($today)];
}

/** The statement issue month must be the month of its last transaction or the month after it. */
function guided_allowed_month(string $lastDate, string $month): bool
{
    $last = substr($lastDate, 0, 7);
    $next = (new DateTimeImmutable(substr($lastDate, 0, 7) . '-01'))->modify('+1 month')->format('Y-m');
    return $month === $last || $month === $next;
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

/** The tax value the Guided form uses: NO_TAX or CODE:<id> in tax-code mode; NO_TAX, GST_HST, PST or GST_HST_PST otherwise. */
function guided_tax_choice(array $company, string $stored): string
{
    $stored = strtoupper(trim($stored));
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

function guided_batch_counts(string $companyId, string $batchId): array
{
    $q = db()->prepare("SELECT status, COUNT(*) n FROM bank_transactions WHERE company_id=? AND import_batch_id=? GROUP BY status");
    $q->execute([$companyId, $batchId]);
    $counts = ['pending' => 0, 'posted' => 0, 'excluded' => 0, 'duplicate' => 0];
    foreach ($q->fetchAll() as $r) $counts[(string)$r['status']] = (int)$r['n'];
    return $counts;
}

function guided_months_state(array $company, string $bankAccountId, string $fiscalYearEnd): array
{
    $companyId = (string)$company['id'];
    $years = guided_fiscal_years($company);
    if ($fiscalYearEnd === '') $fiscalYearEnd = $years['current'];
    $fy = guided_fiscal_year(safe_date($fiscalYearEnd, 'Fiscal year end'));
    $q = db()->prepare("SELECT id,name,masked_number,currency,account_type FROM bank_accounts WHERE company_id=? AND active=1 AND account_type IN ('bank','credit_card') ORDER BY created_at,name");
    $q->execute([$companyId]);
    $banks = array_map(static fn(array $b): array => ['id' => (string)$b['id'], 'name' => (string)$b['name'], 'maskedNumber' => (string)($b['masked_number'] ?? ''), 'currency' => (string)$b['currency'], 'type' => (string)$b['account_type']], $q->fetchAll());
    if ($bankAccountId === '' && $banks) $bankAccountId = $banks[0]['id'];
    $records = [];
    if ($bankAccountId !== '') {
        $r = db()->prepare('SELECT * FROM guided_statement_months WHERE company_id=? AND bank_account_id=? AND statement_month BETWEEN ? AND ?');
        $r->execute([$companyId, $bankAccountId, $fy['months'][0], end($fy['months'])]);
        foreach ($r->fetchAll() as $row) $records[(string)$row['statement_month']] = $row;
    }
    $months = [];
    foreach ($fy['months'] as $month) {
        $row = $records[$month] ?? null;
        $entry = ['month' => $month, 'label' => guided_month_label($month), 'status' => 'empty'];
        if ($row) {
            $counts = guided_batch_counts($companyId, (string)$row['import_batch_id']);
            $entry = array_merge($entry, [
                'id' => (string)$row['id'], 'coverageStart' => $row['coverage_start'], 'coverageEnd' => $row['coverage_end'],
                'lineCount' => (int)$row['line_count'], 'beforeFiscalYear' => (int)$row['before_fy_count'], 'afterFiscalYear' => (int)$row['after_fy_count'],
                'pending' => $counts['pending'], 'posted' => $counts['posted'], 'excluded' => $counts['excluded'] + $counts['duplicate'],
                'reconciled' => $row['reconciliation_id'] !== null,
                'status' => $row['reconciliation_id'] !== null ? 'reconciled' : ($counts['pending'] === 0 ? 'posted' : 'uploaded'),
            ]);
        }
        $months[] = $entry;
    }
    return ['fiscalYears' => array_map(static fn(array $y): array => ['end' => $y['end'], 'start' => $y['start'], 'label' => $y['label']], $years['years']),
        'fiscalYear' => ['end' => $fy['end'], 'start' => $fy['start'], 'label' => $fy['label']],
        'bankAccounts' => $banks, 'bankAccountId' => $bankAccountId, 'months' => $months];
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
    $dates = [];
    foreach ($rows as $row) { $d = (string)($row['date'] ?? ''); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $dates[] = $d; }
    sort($dates);
    $first = $dates[0] ?? (string)$preview['first_transaction_date'];
    $last = $dates ? end($dates) : (string)$preview['last_transaction_date'];
    $suggestedMonth = $last !== '' ? substr($last, 0, 7) : '';
    if ($last !== '' && !guided_allowed_month($last, $month)) {
        $problems[] = 'This statement\'s last transaction is on ' . (new DateTimeImmutable($last))->format('F j, Y') . ', so it was issued in '
            . guided_month_label(substr($last, 0, 7)) . ' or early ' . guided_month_label((new DateTimeImmutable(substr($last, 0, 7) . '-01'))->modify('+1 month')->format('Y-m'))
            . '. Upload it against that month instead of ' . guided_month_label($month) . '.';
    }
    $before = count(array_filter($dates, static fn(string $d): bool => $d < $fy['start']));
    $after = count(array_filter($dates, static fn(string $d): bool => $d > $fy['end']));
    return ['ok' => !$problems, 'problems' => $problems, 'firstDate' => $first, 'lastDate' => $last, 'lineCount' => count($rows),
        'beforeFiscalYear' => $before, 'afterFiscalYear' => $after, 'fiscalYearStart' => $fy['start'], 'fiscalYearEnd' => $fy['end'],
        'suggestedMonth' => $suggestedMonth];
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
    $b = db()->prepare('SELECT id FROM import_batches WHERE id=? AND company_id=? AND bank_account_id=?');
    $b->execute([$batchId, $companyId, (string)$bank['id']]);
    if (!$b->fetchColumn()) fail('That statement import is not available for this account.', 404, 'guided_batch_missing');
    $q = db()->prepare("SELECT MIN(transaction_date) a, MAX(transaction_date) z, COUNT(*) n,
        SUM(transaction_date < ?) before_fy, SUM(transaction_date > ?) after_fy FROM bank_transactions WHERE company_id=? AND import_batch_id=?");
    $q->execute([$fy['start'], $fy['end'], $companyId, $batchId]);
    $s = $q->fetch();
    if (!$s || (int)$s['n'] === 0) fail('The statement import has no transactions.', 422, 'guided_batch_empty');
    if (!guided_allowed_month((string)$s['z'], $month)) fail('This statement ends on ' . (string)$s['z'] . '. Upload it against the month it was issued.', 422, 'guided_month_mismatch');
    $id = new_id('gsm');
    try {
        db()->prepare('INSERT INTO guided_statement_months (id,company_id,bank_account_id,fiscal_year_end,statement_month,import_batch_id,coverage_start,coverage_end,line_count,before_fy_count,after_fy_count,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $companyId, (string)$bank['id'], $fy['end'], $month, $batchId, $s['a'], $s['z'], (int)$s['n'], (int)$s['before_fy'], (int)$s['after_fy'], (string)$user['id']]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') fail('A statement is already recorded for ' . guided_month_label($month) . ', or this import already belongs to a month.', 409, 'guided_month_taken');
        throw $e;
    }
    audit_event($user, $companyId, 'guided.statement_month_recorded', 'guided_statement_month', $id, ['month' => $month, 'bankAccountId' => (string)$bank['id'], 'importBatchId' => $batchId, 'lines' => (int)$s['n'], 'beforeFiscalYear' => (int)$s['before_fy'], 'afterFiscalYear' => (int)$s['after_fy']]);
    return ['id' => $id, 'month' => $month, 'lineCount' => (int)$s['n'], 'beforeFiscalYear' => (int)$s['before_fy'], 'afterFiscalYear' => (int)$s['after_fy'], 'coverageStart' => $s['a'], 'coverageEnd' => $s['z']];
}

/** Remove a month's statement while none of its lines has been posted: the lines are deleted, the month is free again. */
function guided_remove_month(array $user, array $company, string $id): array
{
    $companyId = (string)$company['id'];
    $row = guided_month_row($companyId, $id);
    $counts = guided_batch_counts($companyId, (string)$row['import_batch_id']);
    if ($counts['posted'] > 0) fail('Some lines of this statement are already posted. Void them first, or keep the statement.', 409, 'guided_month_has_postings');
    $q = db()->prepare("SELECT id FROM bank_transactions WHERE company_id=? AND import_batch_id=? AND status IN ('pending','excluded') AND journal_entry_id IS NULL");
    $q->execute([$companyId, (string)$row['import_batch_id']]);
    $ids = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
    foreach (array_chunk($ids, 100) as $chunk) bank_transaction_delete_service($user, $company, $chunk, 'manual');
    db()->prepare('DELETE FROM guided_statement_months WHERE id=? AND company_id=?')->execute([$id, $companyId]);
    audit_event($user, $companyId, 'guided.statement_month_removed', 'guided_statement_month', $id, ['month' => (string)$row['statement_month'], 'linesDeleted' => count($ids)]);
    return ['removed' => true, 'linesDeleted' => count($ids)];
}

/* ---------------------------------------------------------------- mapping groups */

function guided_month_lines(string $companyId, string $batchId): array
{
    $q = db()->prepare('SELECT bt.id,bt.transaction_date,bt.description,bt.reference,bt.amount_cents,bt.status,bt.suggested_account_id,bt.decided_account_id,bt.tax_code,bt.confidence,bt.normalized_merchant,bt.bank_account_id
        FROM bank_transactions bt WHERE bt.company_id=? AND bt.import_batch_id=? ORDER BY bt.transaction_date,bt.source_row_number,bt.id');
    $q->execute([$companyId, $batchId]);
    return $q->fetchAll();
}

function guided_mapping(array $company, string $monthId): array
{
    $companyId = (string)$company['id'];
    $row = guided_month_row($companyId, $monthId);
    $accounts = [];
    $qa = db()->prepare('SELECT id,code,name,account_type,active,is_control FROM accounts WHERE company_id=?');
    $qa->execute([$companyId]);
    foreach ($qa->fetchAll() as $a) $accounts[(string)$a['id']] = $a;
    $groups = [];
    $totals = ['lines' => 0, 'pending' => 0, 'posted' => 0, 'excluded' => 0, 'mapped' => 0, 'unmapped' => 0];
    foreach (guided_month_lines($companyId, (string)$row['import_batch_id']) as $line) {
        $totals['lines']++;
        $status = (string)$line['status'];
        if ($status === 'posted') $totals['posted']++; elseif ($status === 'pending') $totals['pending']++; else $totals['excluded']++;
        $direction = (int)$line['amount_cents'] >= 0 ? 'in' : 'out';
        $keyword = guided_keyword((string)$line['description']);
        $key = $keyword . '|' . $direction;
        if (!isset($groups[$key])) $groups[$key] = ['key' => $key, 'keyword' => $keyword, 'direction' => $direction, 'count' => 0, 'pending' => 0, 'posted' => 0, 'excluded' => 0,
            'totalCents' => 0, 'pendingCents' => 0, 'examples' => [], 'votes' => [], 'firstPending' => null, 'postedAccounts' => []];
        $g = &$groups[$key];
        $g['count']++; $g['totalCents'] += (int)$line['amount_cents'];
        if (count($g['examples']) < 3 && !in_array((string)$line['description'], $g['examples'], true)) $g['examples'][] = (string)$line['description'];
        if ($status === 'pending') {
            $g['pending']++; $g['pendingCents'] += (int)$line['amount_cents'];
            $g['firstPending'] ??= $line;
            $suggested = (string)($line['suggested_account_id'] ?? '');
            if ($suggested !== '' && isset($accounts[$suggested])) {
                $vote = $suggested . '|' . guided_tax_choice($company, (string)$line['tax_code']);
                $g['votes'][$vote] = ($g['votes'][$vote] ?? 0) + 1;
            }
        } elseif ($status === 'posted') {
            $g['posted']++;
            if ($line['decided_account_id']) $g['postedAccounts'][(string)$line['decided_account_id']] = true;
        } else $g['excluded']++;
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
        $mappedLines = $suggestion ? $g['pending'] : 0;
        $totals['mapped'] += $mappedLines; $totals['unmapped'] += $g['pending'] - $mappedLines;
        $account = $suggestion ? ($accounts[$suggestion['accountId']] ?? null) : null;
        $out[] = ['key' => $g['key'], 'keyword' => $g['keyword'], 'direction' => $g['direction'], 'count' => $g['count'], 'pending' => $g['pending'], 'posted' => $g['posted'], 'excluded' => $g['excluded'],
            'totalCents' => $g['totalCents'], 'pendingCents' => $g['pendingCents'], 'examples' => $g['examples'],
            'suggestedAccountId' => $suggestion['accountId'] ?? null, 'suggestedAccount' => $account ? trim((string)$account['code'] . ' · ' . (string)$account['name']) : null,
            'suggestedTax' => $suggestion['tax'] ?? 'NO_TAX', 'suggestionSource' => $suggestion ? $source : null, 'mixedSuggestions' => $mixed];
    }
    usort($out, static fn(array $a, array $b): int => [$b['pending'] > 0, $b['count'], $a['keyword']] <=> [$a['pending'] > 0, $a['count'], $b['keyword']]);
    return ['month' => ['id' => (string)$row['id'], 'month' => (string)$row['statement_month'], 'label' => guided_month_label((string)$row['statement_month']), 'bankAccountId' => (string)$row['bank_account_id'],
        'coverageStart' => $row['coverage_start'], 'coverageEnd' => $row['coverage_end'], 'beforeFiscalYear' => (int)$row['before_fy_count'], 'afterFiscalYear' => (int)$row['after_fy_count'],
        'reconciled' => $row['reconciliation_id'] !== null], 'totals' => $totals, 'groups' => $out];
}

function guided_group_lines(array $company, string $monthId, string $groupKey, int $offset, int $limit): array
{
    $companyId = (string)$company['id'];
    $row = guided_month_row($companyId, $monthId);
    $rows = [];
    foreach (guided_month_lines($companyId, (string)$row['import_batch_id']) as $line) {
        $key = guided_keyword((string)$line['description']) . '|' . ((int)$line['amount_cents'] >= 0 ? 'in' : 'out');
        if ($key === $groupKey) $rows[] = ['id' => (string)$line['id'], 'date' => (string)$line['transaction_date'], 'description' => (string)$line['description'], 'reference' => (string)($line['reference'] ?? ''), 'amountCents' => (int)$line['amount_cents'], 'status' => (string)$line['status']];
    }
    return ['total' => count($rows), 'offset' => $offset, 'lines' => array_slice($rows, $offset, $limit)];
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
    $tax = (string)($input['tax'] ?? 'NO_TAX');
    $skip = array_fill_keys(array_map('strval', is_array($input['skipIds'] ?? null) ? $input['skipIds'] : []), true);
    $failedBefore = array_fill_keys(array_map('strval', is_array($input['failedIds'] ?? null) ? $input['failedIds'] : []), true);
    $todo = [];
    foreach (guided_month_lines($companyId, (string)$row['import_batch_id']) as $line) {
        if ((string)$line['status'] !== 'pending' || isset($skip[(string)$line['id']]) || isset($failedBefore[(string)$line['id']])) continue;
        $key = guided_keyword((string)$line['description']) . '|' . ((int)$line['amount_cents'] >= 0 ? 'in' : 'out');
        if ($key === $groupKey) $todo[] = $line;
    }
    return guided_post_lines($user, $company, array_slice($todo, 0, 100), static fn(array $l): array => [$accountId, $tax]) + ['remaining' => max(0, count($todo) - 100)];
}

/** Post individual lines (per-line changes inside a group): [{id, accountId, tax}], at most 100. */
function guided_post_single_lines(array $user, array $company, array $input): array
{
    $companyId = (string)$company['id'];
    $row = guided_month_row($companyId, clean_text($input['monthId'] ?? '', 'Statement month', 64));
    $wanted = [];
    foreach (array_slice(is_array($input['lines'] ?? null) ? $input['lines'] : [], 0, 100) as $l) if (is_array($l)) $wanted[(string)($l['id'] ?? '')] = [clean_text($l['accountId'] ?? '', 'Account', 64), (string)($l['tax'] ?? 'NO_TAX')];
    $todo = [];
    foreach (guided_month_lines($companyId, (string)$row['import_batch_id']) as $line) if ((string)$line['status'] === 'pending' && isset($wanted[(string)$line['id']])) $todo[] = $line;
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
 * Match up to 300 posted lines of the statement to their own journals, then (when nothing is left to match and no line
 * is pending) complete the bank reconciliation for the statement period. The reconciliation service completes it only
 * when the bank side (opening balance and imported lines) and the books agree to the cent.
 */
function guided_reconcile_month(array $user, array $company, string $monthId): array
{
    $companyId = (string)$company['id'];
    $row = guided_month_row($companyId, $monthId);
    if ($row['reconciliation_id'] !== null) return ['reconciled' => true, 'matched' => 0, 'remaining' => 0, 'reconciliationId' => (string)$row['reconciliation_id']];
    $counts = guided_batch_counts($companyId, (string)$row['import_batch_id']);
    if ($counts['pending'] > 0) return ['reconciled' => false, 'matched' => 0, 'remaining' => 0, 'reason' => $counts['pending'] . ' line' . ($counts['pending'] === 1 ? ' is' : 's are') . ' not posted yet. Post or exclude every line before reconciling.'];
    $bankId = (string)$row['bank_account_id'];
    // Each posted line already carries its own journal, so the reconciliation includes it directly; matching line by line
    // would add nothing and takes minutes on a large statement.
    $matched = 0;
    // The period runs from the statement's first line (or the day after the last completed reconciliation) to its last line.
    $last = db()->prepare("SELECT MAX(period_end) FROM reconciliations WHERE company_id=? AND bank_account_id=? AND status='complete'");
    $last->execute([$companyId, $bankId]);
    $lastEnd = (string)($last->fetchColumn() ?: '');
    $start = (string)$row['coverage_start'];
    if ($lastEnd !== '' && $lastEnd >= $start) $start = (new DateTimeImmutable($lastEnd))->modify('+1 day')->format('Y-m-d');
    $end = (string)$row['coverage_end'];
    if ($start > $end) return ['reconciled' => false, 'matched' => $matched, 'remaining' => 0, 'reason' => 'This statement period is already covered by a completed reconciliation (to ' . $lastEnd . ').'];
    try {
        $result = tegh_service_boundary(static function () use ($user, $company, $bankId, $start, $end, $row): array {
            operations_assert_reconciliation_range_open((string)$company['id'], $start, $end);
            return reconciliation_save_service($user, $company, ['bankAccountId' => $bankId, 'periodStart' => $start, 'periodEnd' => $end, 'status' => 'complete',
                'notes' => 'Guided statement for ' . guided_month_label((string)$row['statement_month']) . ' reconciled automatically after posting.'])[0];
        });
    } catch (TeghServiceFailure $e) {
        return ['reconciled' => false, 'matched' => $matched, 'remaining' => 0, 'reason' => $e->getMessage()];
    }
    $reconciliationId = (string)$result['reconciliation']['id'];
    db()->prepare('UPDATE guided_statement_months SET reconciliation_id=?, reconciled_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?')->execute([$reconciliationId, (string)$row['id'], $companyId]);
    audit_event($user, $companyId, 'guided.statement_month_reconciled', 'guided_statement_month', (string)$row['id'], ['reconciliationId' => $reconciliationId, 'periodStart' => $start, 'periodEnd' => $end, 'matched' => $matched]);
    return ['reconciled' => true, 'matched' => $matched, 'remaining' => 0, 'reconciliationId' => $reconciliationId, 'periodStart' => $start, 'periodEnd' => $end];
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
    if ($missing > 0) $add('missing-statements', 'Bank statements missing', $missing . ' month' . ($missing === 1 ? '' : 's') . ' of this fiscal year have no bank activity imported.', $missing, 'pending', $missing * 15);
    $summary = function_exists('tegh_workspace_summary_data') ? (tegh_workspace_summary_data($company)['summary'] ?? []) : [];
    if (!empty($summary['receivableControlApplicable']) && (int)($summary['receivableDifferenceCents'] ?? 0) !== 0) $add('receivables', 'Customer balances do not agree with Accounts Receivable', 'The difference is ' . number_format(abs((int)$summary['receivableDifferenceCents']) / 100, 2) . '.', 1, 'mistake', 90);
    if (!empty($summary['payableControlApplicable']) && (int)($summary['payableDifferenceCents'] ?? 0) !== 0) $add('payables', 'Vendor balances do not agree with Accounts Payable', 'The difference is ' . number_format(abs((int)$summary['payableDifferenceCents']) / 100, 2) . '.', 1, 'mistake', 90);
    $duplicates = (int)$one("SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND status='duplicate'", [$companyId]);
    if ($duplicates > 0) $add('duplicates', 'Possible duplicate bank lines', $duplicates . ' line' . ($duplicates === 1 ? '' : 's') . ' flagged as possible duplicates to check.', $duplicates, 'mistake', $duplicates * 5);
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
            'quoteCents' => $quote, 'currency' => 'CAD', 'taxNote' => 'Plus applicable GST/HST.']];
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
            . "\n\nQuote shown in Tegh: " . $money($quote['quoteCents']) . " plus applicable GST/HST.\nRequest reference: $id\n" . ($note !== '' ? "\nMy note:\n$note\n" : '') . ($phone !== '' ? "\nPhone: $phone\n" : '') . "\nThank you."];
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
        json_response(guided_months_state($company, trim((string)($_GET['bankAccountId'] ?? '')), trim((string)($_GET['fiscalYearEnd'] ?? ''))));
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
        'reconcile' => guided_reconcile_month($user, $company, clean_text($input['monthId'] ?? '', 'Statement month', 64)),
        default => fail('Guided route not found.', 404, 'route_not_found'),
    };
    json_response($result);
}
