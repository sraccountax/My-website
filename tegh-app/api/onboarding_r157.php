<?php
declare(strict_types=1);

/*
 * R157: company onboarding.
 *
 * A company created through the app's company forms starts with onboarding switched on. Until every step is
 * marked complete, the app keeps its modules locked and opens the onboarding page at sign-in. Companies created
 * before R157 (no onboarding row) are treated as complete, so existing books are never locked.
 *
 * Steps are marked complete by a person (owner or admin). Tegh shows what it detects for each step (accounts,
 * tax codes, bank accounts, imported records) as a hint only; it never marks a step on its own, except the company
 * step, which is complete once the company exists.
 *
 * The table is created on first use, outside any transaction (same approach as R137/R141/R153).
 */

const TEGH_ONBOARDING_STEPS = ['company', 'chart', 'tax', 'banks', 'imports', 'team'];

function tegh_onboarding_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        if (!schema_table_exists('company_onboarding')) {
            db()->exec("CREATE TABLE IF NOT EXISTS company_onboarding (
                company_id VARCHAR(64) NOT NULL PRIMARY KEY,
                required TINYINT(1) NOT NULL DEFAULT 1,
                steps_json TEXT NOT NULL,
                started_by VARCHAR(64) NULL,
                started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL DEFAULT NULL,
                completed_by VARCHAR(64) NULL,
                tour_done_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $ready = schema_table_exists('company_onboarding');
    } catch (Throwable $e) {
        error_log('Tegh R157 onboarding setup: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/** Starts onboarding for a company just created through the app. The company step is complete at once. */
function tegh_onboarding_start(array $user, string $companyId): void
{
    if (!tegh_onboarding_ready()) return;
    $steps = [];
    foreach (TEGH_ONBOARDING_STEPS as $key) $steps[$key] = ['status' => 'pending'];
    $steps['company'] = ['status' => 'complete', 'completedAt' => gmdate('c'), 'completedBy' => (string)$user['id'], 'method' => 'created'];
    db()->prepare('INSERT IGNORE INTO company_onboarding (company_id, required, steps_json, started_by) VALUES (?, 1, ?, ?)')
        ->execute([$companyId, json_encode($steps, JSON_UNESCAPED_SLASHES), (string)$user['id']]);
    audit_event($user, $companyId, 'onboarding.started', 'company', $companyId, ['steps' => TEGH_ONBOARDING_STEPS]);
}

function tegh_onboarding_row(string $companyId, bool $forUpdate = false): ?array
{
    if (!tegh_onboarding_ready()) return null;
    $q = db()->prepare('SELECT * FROM company_onboarding WHERE company_id=?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $q->execute([$companyId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tegh_onboarding_steps(array $row): array
{
    $saved = json_decode((string)$row['steps_json'], true);
    $steps = [];
    foreach (TEGH_ONBOARDING_STEPS as $key) {
        $s = is_array($saved[$key] ?? null) ? $saved[$key] : [];
        $steps[$key] = [
            'status' => ($s['status'] ?? '') === 'complete' ? 'complete' : 'pending',
            'completedAt' => $s['completedAt'] ?? null,
            'completedBy' => $s['completedBy'] ?? null,
            'method' => $s['method'] ?? null,
        ];
    }
    return $steps;
}

/** What Tegh can see for each step. Shown as hints; never used to mark a step complete. */
function tegh_onboarding_detected(string $companyId): array
{
    $count = static function (string $sql, array $args) {
        try { $q = db()->prepare($sql); $q->execute($args); return (int)$q->fetchColumn(); } catch (Throwable) { return 0; }
    };
    return [
        'accounts' => $count("SELECT COUNT(*) FROM accounts WHERE company_id=? AND active=1 AND code<>'9999'", [$companyId]),
        'taxCodes' => schema_table_exists('tax_codes') ? $count("SELECT COUNT(*) FROM tax_codes WHERE company_id=? AND status='active'", [$companyId]) : 0,
        'bankAccounts' => $count('SELECT COUNT(*) FROM bank_accounts WHERE company_id=? AND active=1', [$companyId]),
        'customers' => $count('SELECT COUNT(*) FROM customers WHERE company_id=?', [$companyId]),
        'vendors' => $count('SELECT COUNT(*) FROM vendors WHERE company_id=?', [$companyId]),
        'products' => $count('SELECT COUNT(*) FROM products_services WHERE company_id=?', [$companyId]),
        'imports' => $count("SELECT COUNT(*) FROM data_import_previews WHERE company_id=? AND status NOT IN ('validated','expired','cancelled')", [$companyId])
            + $count('SELECT COUNT(*) FROM opening_balance_imports WHERE company_id=?', [$companyId])
            + $count('SELECT COUNT(*) FROM opening_document_imports WHERE company_id=?', [$companyId]),
        'members' => $count('SELECT COUNT(*) FROM company_members WHERE company_id=?', [$companyId]),
        'postedEntries' => $count("SELECT COUNT(*) FROM journal_entries WHERE company_id=? AND status='posted'", [$companyId]),
    ];
}

function tegh_onboarding_state(string $companyId): array
{
    $row = tegh_onboarding_row($companyId);
    if ($row === null || (int)$row['required'] !== 1) {
        return ['required' => false, 'complete' => true, 'locked' => false, 'steps' => null, 'order' => TEGH_ONBOARDING_STEPS, 'tourDone' => true, 'completedAt' => null];
    }
    $steps = tegh_onboarding_steps($row);
    $done = count(array_filter($steps, static fn(array $s): bool => $s['status'] === 'complete'));
    $complete = $row['completed_at'] !== null;
    return [
        'required' => true,
        'complete' => $complete,
        'locked' => !$complete,
        'steps' => $steps,
        'order' => TEGH_ONBOARDING_STEPS,
        'doneCount' => $done,
        'totalCount' => count(TEGH_ONBOARDING_STEPS),
        'startedAt' => $row['started_at'],
        'completedAt' => $row['completed_at'],
        'tourDone' => $row['tour_done_at'] !== null,
        'detected' => tegh_onboarding_detected($companyId),
    ];
}

function handle_onboarding(string $action): never
{
    $user = require_user();
    $company = require_company($user);
    $companyId = (string)$company['id'];
    if (!tegh_onboarding_ready()) fail('Onboarding is being prepared. Try again in a moment.', 503, 'onboarding_not_ready');
    if ($action === '' && request_method() === 'GET') json_response(['onboarding' => tegh_onboarding_state($companyId)]);
    require_method('POST');
    require_csrf();
    $input = request_json();

    if ($action === 'tour') {
        // Any member may finish or skip the welcome tour; it changes nothing in the books.
        db()->prepare('UPDATE company_onboarding SET tour_done_at=COALESCE(tour_done_at,CURRENT_TIMESTAMP) WHERE company_id=?')->execute([$companyId]);
        json_response(['onboarding' => tegh_onboarding_state($companyId)]);
    }

    require_company_role($company, 'owner', 'admin');

    if ($action === 'step') {
        $key = (string)($input['step'] ?? '');
        if (!in_array($key, TEGH_ONBOARDING_STEPS, true)) fail('Choose a valid onboarding step.', 422, 'onboarding_step_invalid');
        $complete = !empty($input['complete']);
        $method = optional_text($input['method'] ?? null, 40);
        $result = db_transaction_retry(static function () use ($user, $companyId, $key, $complete, $method): array {
            $row = tegh_onboarding_row($companyId, true);
            if ($row === null || (int)$row['required'] !== 1) fail('This company has no onboarding to complete.', 409, 'onboarding_not_required');
            if ($row['completed_at'] !== null) fail('Onboarding is already finished for this company. Use Settings to change its setup.', 409, 'onboarding_finished');
            if ($key === 'company' && !$complete) fail('The company step stays complete once the company exists.', 409, 'onboarding_company_step');
            $steps = tegh_onboarding_steps($row);
            $steps[$key] = $complete
                ? ['status' => 'complete', 'completedAt' => gmdate('c'), 'completedBy' => (string)$user['id'], 'method' => $method]
                : ['status' => 'pending', 'completedAt' => null, 'completedBy' => null, 'method' => null];
            $all = !in_array('pending', array_column($steps, 'status'), true);
            db()->prepare('UPDATE company_onboarding SET steps_json=?, completed_at=' . ($all ? 'CURRENT_TIMESTAMP' : 'NULL') . ', completed_by=? WHERE company_id=?')
                ->execute([json_encode($steps, JSON_UNESCAPED_SLASHES), $all ? (string)$user['id'] : null, $companyId]);
            audit_event($user, $companyId, $complete ? 'onboarding.step_completed' : 'onboarding.step_reopened', 'company', $companyId, ['step' => $key, 'method' => $method]);
            if ($all) audit_event($user, $companyId, 'onboarding.completed', 'company', $companyId, []);
            return ['finished' => $all];
        });
        json_response(['onboarding' => tegh_onboarding_state($companyId), 'finished' => $result['finished']]);
    }

    if ($action === 'chart-template') {
        // The standard chart of accounts for an existing company that has no accounts yet (other than Tegh's
        // opening-balance control account). The same template a company gets when it is created with the default chart.
        $created = db_transaction_retry(static function () use ($user, $company, $companyId): array {
            db()->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE')->execute([$companyId]);
            $q = db()->prepare("SELECT code FROM accounts WHERE company_id=? AND code<>'9999'");
            $q->execute([$companyId]);
            if ($q->fetchColumn() !== false) fail('This company already has accounts. Add the accounts you need on the Chart of Accounts screen, or import them.', 409, 'chart_not_empty');
            $rows = default_chart();
            if (($company['module_mode'] ?? '') === 'payroll') {
                $payrollCodes = ['1000','2000','2300','2310','2320','2330','2340','2350','3000','7000','7010','7020','9999'];
                $rows = array_values(array_filter($rows, static fn(array $row): bool => in_array($row[0], $payrollCodes, true)));
            }
            $ins = db()->prepare('INSERT INTO accounts (id, company_id, code, name, account_type, normal_balance, is_control, gifi_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $ids = []; $types = []; $codes = [];
            foreach ($rows as [$code, $name, $type, $normal, $control, $gifi]) {
                if ($code === '9999') continue;
                $id = new_id('account');
                $ins->execute([$id, $companyId, $code, $name, $type, $normal, $control, $gifi]);
                $ids[$code] = $id; $types[$code] = $type; $codes[] = $code;
            }
            // The same two financial accounts a new company gets with the default chart.
            $hasBank = db()->prepare('SELECT COUNT(*) FROM bank_accounts WHERE company_id=?');
            $hasBank->execute([$companyId]);
            if ((int)$hasBank->fetchColumn() === 0 && isset($ids['1000']) && $types['1000'] === 'asset') {
                $bank = db()->prepare('INSERT INTO bank_accounts (id, company_id, ledger_account_id, name, account_type, masked_number, currency) VALUES (?, ?, ?, ?, ?, NULL, ?)');
                $bank->execute([new_id('bank'), $companyId, $ids['1000'], 'Business Chequing', 'bank', (string)$company['currency']]);
                if (($company['module_mode'] ?? '') !== 'payroll' && isset($ids['2000']) && $types['2000'] === 'liability') {
                    $bank->execute([new_id('bank'), $companyId, $ids['2000'], 'Business Credit Card', 'credit_card', (string)$company['currency']]);
                }
            }
            audit_event($user, $companyId, 'onboarding.chart_template_applied', 'company', $companyId, ['accounts' => count($codes)]);
            return $codes;
        });
        json_response(['created' => count($created), 'onboarding' => tegh_onboarding_state($companyId)], 201);
    }

    if ($action === 'tax-import') {
        // Rows: code, name, region, taxName, ratePercent, salesAccountCode, purchaseAccountCode, recoverable.
        // Several rows with the same code are the taxes of one code (for example GST and PST).
        $rows = $input['rows'] ?? null;
        if (!is_array($rows) || !$rows || count($rows) > 300) fail('Choose a file with between 1 and 300 tax rows.', 422, 'tax_import_rows');
        $acct = db()->prepare("SELECT id FROM accounts WHERE company_id=? AND code=? AND active=1 LIMIT 1");
        $codes = []; $errors = [];
        foreach (array_values($rows) as $i => $r) {
            $line = $i + 2;
            if (!is_array($r)) { $errors[] = "Row $line is not readable."; continue; }
            $code = strtoupper(trim((string)($r['code'] ?? '')));
            $taxName = trim((string)($r['taxName'] ?? ''));
            $rate = trim((string)($r['ratePercent'] ?? ''));
            if ($code === '' || $taxName === '' || $rate === '' || !is_numeric(str_replace('%', '', $rate))) { $errors[] = "Row $line needs a code, a tax name and a rate."; continue; }
            $component = ['name' => $taxName, 'ratePercent' => (float)str_replace('%', '', $rate)];
            foreach (['salesAccountCode' => 'salesAccountId', 'purchaseAccountCode' => 'purchaseAccountId'] as $from => $to) {
                $glCode = trim((string)($r[$from] ?? ''));
                if ($glCode === '') continue;
                $acct->execute([$companyId, $glCode]);
                $id = $acct->fetchColumn();
                if ($id === false) { $errors[] = "Row $line: GL account $glCode is not in the chart of accounts."; continue 2; }
                $component[$to] = (string)$id;
            }
            $recoverable = strtolower(trim((string)($r['recoverable'] ?? 'yes')));
            $component['purchaseRecoverable'] = !in_array($recoverable, ['no', 'n', 'false', '0'], true);
            if (!isset($codes[$code])) $codes[$code] = ['code' => $code, 'name' => trim((string)($r['name'] ?? '')) ?: $code, 'region' => trim((string)($r['region'] ?? '')), 'components' => []];
            $codes[$code]['components'][] = $component;
        }
        if ($errors) fail(implode(' ', array_slice($errors, 0, 8)) . (count($errors) > 8 ? ' (' . (count($errors) - 8) . ' more)' : ''), 422, 'tax_import_invalid');
        $created = []; $failed = [];
        // Each code is saved on its own, so one refused code (for example a duplicate) does not stop the others.
        $prevBoundary = $GLOBALS['tegh_service_exception_boundary'] ?? null;
        $GLOBALS['tegh_service_exception_boundary'] = true;
        try {
            foreach ($codes as $code => $payload) {
                try { tax_code_save($user, $company, $payload); $created[] = $code; }
                catch (TeghServiceFailure $e) { $failed[] = ['code' => $code, 'error' => $e->getMessage()]; }
            }
        } finally {
            $GLOBALS['tegh_service_exception_boundary'] = $prevBoundary;
        }
        audit_event($user, $companyId, 'onboarding.tax_codes_imported', 'company', $companyId, ['created' => $created, 'failed' => array_column($failed, 'code')]);
        json_response(['created' => $created, 'failed' => $failed, 'onboarding' => tegh_onboarding_state($companyId)], $created ? 201 : 422);
    }

    fail('Unknown onboarding action.', 404, 'onboarding_action_unknown');
}
