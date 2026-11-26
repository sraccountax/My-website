<?php
declare(strict_types=1);
/*
 * R200: Group & Intercompany.
 * A group bookkeeper links two companies as an intercompany pair: the customer and the vendor each company uses for the
 * other, the expense account a mirrored bill posts to, and any accounts that hold balances between them (due to / due
 * from, loans) or intercompany income and expense entered by journal (interest, recharges).
 *  - Mirror: an issued invoice to the partner's customer creates a draft vendor invoice in the partner company, which the
 *    partner reviews and posts. A mirror is created once per invoice.
 *  - Reconciliation: for each pair, as at a date, what each side says is owed between them, and the difference.
 *  - Consolidation worksheet: companies with one base currency side by side, the intercompany eliminations, and the
 *    consolidated amounts. A difference that does not eliminate is shown on its own line, never hidden.
 *  - Group close: close a period for several companies at once (all or none).
 * Tables are created on first use (no schema change; Schema stays 46). Every company the person acts on is checked for
 * membership and permission separately, as if it were opened on its own.
 */

function r200_schema_ready(): void
{
    static $ready = false;
    if ($ready) return;
    if (db()->inTransaction()) fail('Intercompany set-up is not ready.', 503, 'group_schema_busy');
    db()->exec("CREATE TABLE IF NOT EXISTS ic_pairs (
        id VARCHAR(64) NOT NULL PRIMARY KEY,
        company_a VARCHAR(64) NOT NULL, company_b VARCHAR(64) NOT NULL,
        a_customer_id VARCHAR(64) NULL, a_vendor_id VARCHAR(64) NULL, b_customer_id VARCHAR(64) NULL, b_vendor_id VARCHAR(64) NULL,
        a_expense_account_id VARCHAR(64) NULL, b_expense_account_id VARCHAR(64) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        KEY ic_pairs_a (company_a), KEY ic_pairs_b (company_b)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ic_pair_accounts (
        id VARCHAR(64) NOT NULL PRIMARY KEY, pair_id VARCHAR(64) NOT NULL, company_id VARCHAR(64) NOT NULL,
        account_id VARCHAR(64) NOT NULL, role VARCHAR(12) NOT NULL,
        UNIQUE KEY ic_pair_accounts_one (pair_id, account_id), KEY ic_pair_accounts_pair (pair_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ic_mirrors (
        id VARCHAR(64) NOT NULL PRIMARY KEY, pair_id VARCHAR(64) NOT NULL,
        source_company_id VARCHAR(64) NOT NULL, source_type VARCHAR(20) NOT NULL, source_id VARCHAR(64) NOT NULL,
        target_company_id VARCHAR(64) NOT NULL, target_type VARCHAR(20) NOT NULL, target_id VARCHAR(64) NOT NULL,
        created_by VARCHAR(64) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ic_mirrors_source (source_company_id, source_type, source_id), KEY ic_mirrors_target (target_company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

/** A company the signed-in person is an active member of (same checks as opening it), with their role. */
function r200_company(array $user, string $id): array
{
    $id = trim($id);
    if ($id === '' || strlen($id) > 64) fail('Choose a company.', 422, 'group_company_required');
    $status = schema_column_exists('company_members', 'status') ? " AND cm.status='active'" : '';
    $q = db()->prepare("SELECT c.*, cm.role FROM companies c JOIN company_members cm ON cm.company_id=c.id WHERE c.id=? AND cm.user_id=? AND c.active=1" . $status . " LIMIT 1");
    $q->execute([$id, $user['id']]);
    $company = $q->fetch();
    if (!$company) fail('One of the selected companies is not available to you.', 403, 'group_company_forbidden');
    if (function_exists('tegh_assert_company_edition')) tegh_assert_company_edition($company);
    return $company;
}

/** The same membership and edition checks as r200_company, returning null instead of refusing (for listings). */
function r200_company_or_null(array $user, string $id): ?array
{
    $status = schema_column_exists('company_members', 'status') ? " AND cm.status='active'" : '';
    $q = db()->prepare("SELECT c.*, cm.role FROM companies c JOIN company_members cm ON cm.company_id=c.id WHERE c.id=? AND cm.user_id=? AND c.active=1" . $status . " LIMIT 1");
    $q->execute([$id, $user['id']]);
    $company = $q->fetch();
    if (!$company) return null;
    if (function_exists('tegh_session_edition')) {
        $edition = strtoupper((string)($company['edition'] ?? '')) === 'IN' ? 'IN' : 'CA';
        if ($edition !== tegh_session_edition()) return null;
    }
    return $company;
}

function r200_can(array $company, string $permission): bool
{
    return company_role_can((string)$company['role'], $permission);
}

function r200_party(string $companyId, string $table, ?string $id): ?array
{
    if ($id === null || $id === '') return null;
    $q = db()->prepare("SELECT id,name FROM $table WHERE id=? AND company_id=? LIMIT 1");
    $q->execute([$id, $companyId]);
    $row = $q->fetch();
    if (!$row) fail('A selected ' . ($table === 'customers' ? 'customer' : 'vendor') . ' does not belong to that company.', 422, 'group_party_invalid');
    return ['id' => (string)$row['id'], 'name' => (string)$row['name']];
}

function r200_account(string $companyId, ?string $id, array $types): ?array
{
    if ($id === null || $id === '') return null;
    $q = db()->prepare('SELECT id,code,name,account_type FROM accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');
    $q->execute([$id, $companyId]);
    $row = $q->fetch();
    if (!$row || !in_array((string)$row['account_type'], $types, true)) fail('A selected account does not belong to that company or is the wrong type.', 422, 'group_account_invalid');
    return ['id' => (string)$row['id'], 'code' => (string)$row['code'], 'name' => (string)$row['name'], 'type' => (string)$row['account_type']];
}

/** Active pairs where the person belongs to both companies (each in the signed-in edition). */
function r200_pairs(array $user, ?array $onlyCompanies = null): array
{
    r200_schema_ready();
    $rows = db()->query("SELECT * FROM ic_pairs WHERE active=1 ORDER BY created_at, id")->fetchAll();
    $out = [];
    foreach ($rows as $p) {
        if ($onlyCompanies !== null && (!in_array((string)$p['company_a'], $onlyCompanies, true) || !in_array((string)$p['company_b'], $onlyCompanies, true))) continue;
        $a = r200_company_or_null($user, (string)$p['company_a']); $b = r200_company_or_null($user, (string)$p['company_b']);
        if (!$a || !$b) continue; // not a member of both, or one is in the other edition
        $out[] = ['row' => $p, 'a' => $a, 'b' => $b];
    }
    return $out;
}

function r200_pair_accounts(string $pairId): array
{
    $q = db()->prepare('SELECT pa.company_id, pa.role, a.id, a.code, a.name, a.account_type FROM ic_pair_accounts pa JOIN accounts a ON a.id=pa.account_id AND a.company_id=pa.company_id WHERE pa.pair_id=? ORDER BY a.code');
    $q->execute([$pairId]);
    return array_map(static fn(array $r): array => ['companyId' => (string)$r['company_id'], 'role' => (string)$r['role'], 'id' => (string)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'type' => (string)$r['account_type']], $q->fetchAll());
}

function r200_pair_public(array $pair): array
{
    $p = $pair['row'];
    $name = static function (string $table, string $companyId, $id): ?array { if (!$id) return null; $q = db()->prepare("SELECT id,name FROM $table WHERE id=? AND company_id=?"); $q->execute([$id, $companyId]); $r = $q->fetch(); return $r ? ['id' => (string)$r['id'], 'name' => (string)$r['name']] : null; };
    $acct = static function (string $companyId, $id): ?array { if (!$id) return null; $q = db()->prepare('SELECT id,code,name FROM accounts WHERE id=? AND company_id=?'); $q->execute([$id, $companyId]); $r = $q->fetch(); return $r ? ['id' => (string)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name']] : null; };
    $a = (string)$p['company_a']; $b = (string)$p['company_b'];
    return [
        'id' => (string)$p['id'],
        'a' => ['companyId' => $a, 'name' => (string)$pair['a']['name'], 'currency' => (string)$pair['a']['currency'], 'customer' => $name('customers', $a, $p['a_customer_id']), 'vendor' => $name('vendors', $a, $p['a_vendor_id']), 'expenseAccount' => $acct($a, $p['a_expense_account_id'])],
        'b' => ['companyId' => $b, 'name' => (string)$pair['b']['name'], 'currency' => (string)$pair['b']['currency'], 'customer' => $name('customers', $b, $p['b_customer_id']), 'vendor' => $name('vendors', $b, $p['b_vendor_id']), 'expenseAccount' => $acct($b, $p['b_expense_account_id'])],
        'accounts' => r200_pair_accounts((string)$p['id']),
        'canEdit' => r200_can($pair['a'], 'company.settings') && r200_can($pair['b'], 'company.settings'),
    ];
}

function r200_pair_save(array $user, array $input): array
{
    r200_schema_ready();
    $a = r200_company($user, (string)($input['companyA'] ?? ''));
    $b = r200_company($user, (string)($input['companyB'] ?? ''));
    if ((string)$a['id'] === (string)$b['id']) fail('Choose two different companies.', 422, 'group_pair_same_company');
    foreach ([$a, $b] as $c) if (!r200_can($c, 'company.settings')) fail('Only the Owner or an Admin of both companies links them.', 403, 'group_pair_role');
    $aId = (string)$a['id']; $bId = (string)$b['id'];
    $aCustomer = r200_party($aId, 'customers', $input['aCustomerId'] ?? null); $aVendor = r200_party($aId, 'vendors', $input['aVendorId'] ?? null);
    $bCustomer = r200_party($bId, 'customers', $input['bCustomerId'] ?? null); $bVendor = r200_party($bId, 'vendors', $input['bVendorId'] ?? null);
    if (!$aCustomer && !$aVendor && !$bCustomer && !$bVendor && empty($input['accounts'])) fail('Choose at least the customer or vendor each company uses for the other.', 422, 'group_pair_empty');
    $aExp = r200_account($aId, $input['aExpenseAccountId'] ?? null, ['expense', 'asset']);
    $bExp = r200_account($bId, $input['bExpenseAccountId'] ?? null, ['expense', 'asset']);
    $accounts = [];
    foreach ((array)($input['accounts'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $cid = (string)($row['companyId'] ?? ''); if ($cid !== $aId && $cid !== $bId) fail('An account must belong to one of the two companies.', 422, 'group_account_invalid');
        $role = (string)($row['role'] ?? ''); if (!in_array($role, ['balance', 'pl'], true)) fail('Choose whether the account holds a balance between the companies or intercompany income or expense.', 422, 'group_account_role');
        $acc = r200_account($cid, (string)($row['accountId'] ?? ''), $role === 'balance' ? ['asset', 'liability'] : ['income', 'expense']);
        $accounts[$acc['id']] = ['companyId' => $cid, 'accountId' => $acc['id'], 'role' => $role];
    }
    $id = trim((string)($input['id'] ?? ''));
    $own = !db()->inTransaction(); if ($own) db()->beginTransaction();
    try {
        if ($id !== '') {
            $q = db()->prepare('SELECT * FROM ic_pairs WHERE id=? AND active=1 FOR UPDATE'); $q->execute([$id]); $old = $q->fetch();
            if (!$old || !in_array((string)$old['company_a'], [$aId, $bId], true) || !in_array((string)$old['company_b'], [$aId, $bId], true)) fail('Intercompany pair not found.', 404, 'group_pair_not_found');
        } else {
            $dup = db()->prepare('SELECT id FROM ic_pairs WHERE active=1 AND ((company_a=? AND company_b=?) OR (company_a=? AND company_b=?)) LIMIT 1');
            $dup->execute([$aId, $bId, $bId, $aId]);
            if ($dup->fetchColumn()) fail('These two companies are already linked. Edit the existing pair.', 409, 'group_pair_exists');
            $id = new_id('icpair');
            db()->prepare('INSERT INTO ic_pairs (id,company_a,company_b,created_by) VALUES (?,?,?,?)')->execute([$id, $aId, $bId, $user['id']]);
        }
        db()->prepare('UPDATE ic_pairs SET company_a=?,company_b=?,a_customer_id=?,a_vendor_id=?,b_customer_id=?,b_vendor_id=?,a_expense_account_id=?,b_expense_account_id=?,updated_at=NOW() WHERE id=?')
            ->execute([$aId, $bId, $aCustomer['id'] ?? null, $aVendor['id'] ?? null, $bCustomer['id'] ?? null, $bVendor['id'] ?? null, $aExp['id'] ?? null, $bExp['id'] ?? null, $id]);
        db()->prepare('DELETE FROM ic_pair_accounts WHERE pair_id=?')->execute([$id]);
        $ins = db()->prepare('INSERT INTO ic_pair_accounts (id,pair_id,company_id,account_id,role) VALUES (?,?,?,?,?)');
        foreach ($accounts as $acc) $ins->execute([new_id('icacct'), $id, $acc['companyId'], $acc['accountId'], $acc['role']]);
        $detail = ['pairId' => $id, 'companyA' => $aId, 'companyB' => $bId, 'accounts' => count($accounts)];
        audit_event($user, $aId, 'group.pair_saved', 'ic_pair', $id, $detail + ['partner' => $bId]);
        audit_event($user, $bId, 'group.pair_saved', 'ic_pair', $id, $detail + ['partner' => $aId]);
        if ($own) db()->commit();
    } catch (Throwable $e) { if ($own && db()->inTransaction()) db()->rollBack(); throw $e; }
    $pair = array_values(array_filter(r200_pairs($user), static fn(array $p): bool => (string)$p['row']['id'] === $id))[0] ?? null;
    return $pair ? r200_pair_public($pair) : ['id' => $id];
}

/** Which side of the pair a company is, with that side's customer/vendor and the partner's. */
function r200_side(array $pair, string $companyId): array
{
    $p = $pair['row'];
    if ((string)$p['company_a'] === $companyId) return ['me' => 'a', 'company' => $pair['a'], 'partner' => $pair['b'], 'customer' => $p['a_customer_id'], 'vendor' => $p['a_vendor_id'], 'partnerCustomer' => $p['b_customer_id'], 'partnerVendor' => $p['b_vendor_id'], 'partnerExpense' => $p['b_expense_account_id']];
    return ['me' => 'b', 'company' => $pair['b'], 'partner' => $pair['a'], 'customer' => $p['b_customer_id'], 'vendor' => $p['b_vendor_id'], 'partnerCustomer' => $p['a_customer_id'], 'partnerVendor' => $p['a_vendor_id'], 'partnerExpense' => $p['a_expense_account_id']];
}

/** Issued invoices in a company to the customer that stands for a partner company, with their mirror status. */
function r200_documents(array $user, string $companyId): array
{
    $company = r200_company($user, $companyId);
    if (!r200_can($company, 'company.view')) fail('Your role cannot see this company.', 403, 'group_role');
    $out = [];
    foreach (r200_pairs($user) as $pair) {
        if (!in_array($companyId, [(string)$pair['row']['company_a'], (string)$pair['row']['company_b']], true)) continue;
        $s = r200_side($pair, $companyId);
        if (!$s['customer']) continue;
        $q = db()->prepare("SELECT i.id,i.number,i.issue_date,i.due_date,i.status,i.currency,i.total_cents,i.foreign_total_cents,m.target_id,b.status AS bill_status,b.number AS bill_number
            FROM invoices i LEFT JOIN ic_mirrors m ON m.source_company_id=i.company_id AND m.source_type='invoice' AND m.source_id=i.id
            LEFT JOIN bills b ON b.id=m.target_id AND b.company_id=m.target_company_id
            WHERE i.company_id=? AND i.customer_id=? AND i.status<>'draft' ORDER BY i.issue_date, i.number");
        $q->execute([$companyId, $s['customer']]);
        foreach ($q->fetchAll() as $r) $out[] = ['pairId' => (string)$pair['row']['id'], 'partner' => ['id' => (string)$s['partner']['id'], 'name' => (string)$s['partner']['name']], 'invoiceId' => (string)$r['id'], 'number' => (string)$r['number'], 'date' => (string)$r['issue_date'], 'dueDate' => (string)$r['due_date'],
            'currency' => (string)$r['currency'], 'totalCents' => (int)$r['total_cents'], 'foreignTotalCents' => (int)$r['foreign_total_cents'],
            'mirror' => $r['target_id'] ? ['billId' => (string)$r['target_id'], 'status' => $r['bill_status'] === null ? 'deleted' : (string)$r['bill_status'], 'number' => (string)($r['bill_number'] ?? '')] : null,
            'canMirror' => !$r['target_id'] && (bool)$s['partnerVendor'] && r200_can($s['partner'], 'bills.write')];
    }
    return $out;
}

/** Create the partner's draft vendor invoice from an issued invoice to the partner (once per invoice). */
function r200_mirror_invoice(array $user, string $companyId, string $invoiceId): array
{
    r200_schema_ready();
    $company = r200_company($user, $companyId);
    if (!r200_can($company, 'company.view')) fail('Your role cannot see this company.', 403, 'group_role');
    $q = db()->prepare("SELECT * FROM invoices WHERE id=? AND company_id=? LIMIT 1"); $q->execute([$invoiceId, $companyId]); $inv = $q->fetch();
    if (!$inv) fail('Invoice not found.', 404, 'group_invoice_not_found');
    if ((string)$inv['status'] === 'draft') fail('Issue the invoice before creating the partner\'s vendor invoice.', 409, 'group_invoice_draft');
    $pair = null; $side = null;
    foreach (r200_pairs($user) as $p) { if (!in_array($companyId, [(string)$p['row']['company_a'], (string)$p['row']['company_b']], true)) continue; $s = r200_side($p, $companyId); if ($s['customer'] && (string)$s['customer'] === (string)$inv['customer_id']) { $pair = $p; $side = $s; break; } }
    if (!$pair) fail('This invoice\'s customer is not linked to another company.', 422, 'group_invoice_not_intercompany');
    if (!$side['partnerVendor']) fail('Choose the vendor ' . $side['partner']['name'] . ' uses for ' . $company['name'] . ' in the intercompany pair first.', 422, 'group_partner_vendor_missing');
    $partner = $side['partner'];
    if (!r200_can($partner, 'bills.write')) fail('Your role in ' . $partner['name'] . ' cannot enter vendor invoices.', 403, 'group_partner_role');
    $done = db()->prepare("SELECT target_id FROM ic_mirrors WHERE source_company_id=? AND source_type='invoice' AND source_id=?"); $done->execute([$companyId, $invoiceId]);
    if ($done->fetchColumn()) fail('The partner\'s vendor invoice already exists for this invoice.', 409, 'group_mirror_exists');
    if ((string)$inv['currency'] !== (string)$partner['currency'] && !company_currency((string)$partner['id'], (string)$inv['currency'])) fail('Add ' . $inv['currency'] . ' to ' . $partner['name'] . ' first.', 422, 'group_partner_currency');
    // Partner's expense account: the pair's choice, else the vendor's default, else Unassigned Expense.
    $expense = $side['partnerExpense'];
    if (!$expense) { $v = db()->prepare('SELECT default_expense_account_id FROM vendors WHERE id=? AND company_id=?'); $v->execute([$side['partnerVendor'], $partner['id']]); $expense = $v->fetchColumn() ?: null; }
    if (!$expense) { $v = db()->prepare("SELECT id FROM accounts WHERE company_id=? AND code='6999' AND active=1"); $v->execute([$partner['id']]); $expense = $v->fetchColumn() ?: null; }
    if (!$expense) fail('Choose the expense account for ' . $partner['name'] . ' in the intercompany pair.', 422, 'group_partner_expense_missing');
    // Lines: description, quantity and price as invoiced; the partner's tax code with the same code (GST, ON, QC ...).
    $lq = db()->prepare('SELECT l.description,l.quantity_milli,l.unit_price_cents,l.foreign_unit_price_cents,l.tax_code_id,t.code AS tax_code FROM invoice_lines l LEFT JOIN tax_codes t ON t.id=l.tax_code_id WHERE l.invoice_id=? ORDER BY l.sort_order, l.id');
    $lq->execute([$invoiceId]);
    $codeFor = static function (?string $code) use ($partner): ?string { if ($code === null || $code === '') return null; $q = db()->prepare("SELECT id FROM tax_codes WHERE company_id=? AND code=? AND status='active' LIMIT 1"); $q->execute([$partner['id'], $code]); return $q->fetchColumn() ?: null; };
    $lines = []; $missingCode = [];
    foreach ($lq->fetchAll() as $l) {
        $unit = (int)($l['foreign_unit_price_cents'] ?? 0) !== 0 ? (int)$l['foreign_unit_price_cents'] : (int)$l['unit_price_cents'];
        $code = $codeFor($l['tax_code'] ?? null); if (($l['tax_code'] ?? '') !== '' && !$code) $missingCode[] = (string)$l['tax_code'];
        $lines[] = ['description' => (string)$l['description'], 'quantity' => ((int)$l['quantity_milli']) / 1000, 'unitPriceCents' => $unit, 'accountId' => $expense] + ($code ? ['taxCodeId' => $code] : []);
    }
    if (!$lines) fail('The invoice has no lines to copy.', 422, 'group_invoice_empty');
    if ($missingCode) fail($partner['name'] . ' has no tax code ' . implode(', ', array_unique($missingCode)) . '. Add it there first.', 422, 'group_partner_tax_code');
    $input = ['vendorId' => $side['partnerVendor'], 'number' => (string)$inv['number'], 'billDate' => (string)$inv['issue_date'], 'dueDate' => (string)$inv['due_date'],
        'currency' => (string)$inv['currency'], 'taxEntryMode' => 'exclusive', 'memo' => 'Intercompany: invoice ' . $inv['number'] . ' from ' . $company['name'], 'issue' => false, 'lines' => $lines];
    if ((string)$inv['currency'] !== (string)$partner['currency']) $input += ['exchangeRateMicros' => (int)$inv['exchange_rate_micros'], 'exchangeRateTyped' => true, 'exchangeRateConfirmed' => true];
    // The vendor invoice is created exactly as if the person had entered it in the partner company.
    if (function_exists('r151_schema_ready')) r151_schema_ready();
    $prevTz = date_default_timezone_get();
    if (function_exists('tegh_set_accounting_timezone')) tegh_set_accounting_timezone((string)($partner['timezone'] ?? ''));
    try { $bill = create_bill_record($user, $partner, $input, 'intercompany'); }
    finally { date_default_timezone_set($prevTz); }
    $mid = new_id('icmirror');
    db()->prepare("INSERT INTO ic_mirrors (id,pair_id,source_company_id,source_type,source_id,target_company_id,target_type,target_id,created_by) VALUES (?,?,?,'invoice',?,?,'bill',?,?)")
        ->execute([$mid, $pair['row']['id'], $companyId, $invoiceId, $partner['id'], $bill['id'], $user['id']]);
    audit_event($user, $companyId, 'group.invoice_mirrored', 'invoice', $invoiceId, ['partner' => (string)$partner['id'], 'billId' => (string)$bill['id']]);
    audit_event($user, (string)$partner['id'], 'group.bill_from_partner', 'bill', (string)$bill['id'], ['source' => $companyId, 'invoiceId' => $invoiceId]);
    return ['billId' => (string)$bill['id'], 'number' => (string)$bill['number'], 'status' => (string)$bill['status'], 'partner' => ['id' => (string)$partner['id'], 'name' => (string)$partner['name']], 'totalCents' => (int)$bill['totalCents']];
}

/** Party balance (customers: owed to us positive; vendors: owed by us positive) as at a date, from the subledger. */
function r200_party_balance(array $company, bool $ar, ?string $partyId, string $asOf): int
{
    if (!$partyId) return 0;
    static $cache = [];
    $key = $company['id'] . ($ar ? ':ar' : ':ap');
    if (!isset($cache[$key])) $cache[$key] = tegh_subledger_events_5980(tegh_subledger_sources_5980($company, $ar), (string)$company['accounting_basis'], $ar);
    $ledger = $cache[$key];
    $snap = tegh_subledger_snapshot_5980($ledger, $asOf);
    $sum = 0;
    foreach ($snap['balances'] as $item => $bal) if ((string)($ledger['items'][$item]['partyId'] ?? '') === (string)$partyId) $sum += (int)$bal;
    return $sum;
}

function r200_account_balance(string $companyId, string $accountId, string $asOf): int
{
    $q = db()->prepare("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=? AND je.status='posted' WHERE jl.account_id=? AND je.entry_date<=?");
    $q->execute([$companyId, $accountId, $asOf]);
    return (int)$q->fetchColumn();
}

/** One side's view of what the partner owes it (positive) as at a date, with its parts. */
function r200_side_position(array $pair, string $companyId, string $asOf): array
{
    $s = r200_side($pair, $companyId);
    $c = $s['company'];
    $ar = r200_party_balance($c, true, $s['customer'], $asOf);
    $ap = r200_party_balance($c, false, $s['vendor'], $asOf);
    $parts = [];
    if ($s['customer']) $parts[] = ['label' => 'Receivable from the partner (customer)', 'cents' => $ar];
    if ($s['vendor']) $parts[] = ['label' => 'Payable to the partner (vendor)', 'cents' => -$ap];
    $net = $ar - $ap;
    foreach (r200_pair_accounts((string)$pair['row']['id']) as $acc) {
        if ($acc['companyId'] !== $companyId || $acc['role'] !== 'balance') continue;
        $bal = r200_account_balance($companyId, $acc['id'], $asOf);
        $parts[] = ['label' => $acc['code'] . ' ' . $acc['name'], 'cents' => $bal, 'accountId' => $acc['id']];
        $net += $bal;
    }
    return ['companyId' => $companyId, 'name' => (string)$c['name'], 'currency' => (string)$c['currency'], 'netCents' => $net, 'parts' => $parts];
}

function r200_reconciliation(array $user, string $asOf, ?array $companies): array
{
    $out = [];
    foreach (r200_pairs($user, $companies) as $pair) {
        $a = r200_side_position($pair, (string)$pair['row']['company_a'], $asOf);
        $b = r200_side_position($pair, (string)$pair['row']['company_b'], $asOf);
        $same = $a['currency'] === $b['currency'];
        // What A says B owes it, plus what B says A owes it: zero when both sides agree.
        $diff = $same ? $a['netCents'] + $b['netCents'] : null;
        $drafts = db()->prepare("SELECT COUNT(*) FROM ic_mirrors m JOIN bills b ON b.id=m.target_id AND b.company_id=m.target_company_id WHERE m.pair_id=? AND b.status='draft'");
        $drafts->execute([$pair['row']['id']]);
        $out[] = ['pairId' => (string)$pair['row']['id'], 'a' => $a, 'b' => $b, 'differenceCents' => $diff, 'agrees' => $diff === 0, 'sameCurrency' => $same, 'draftMirrors' => (int)$drafts->fetchColumn()];
    }
    return ['asOf' => $asOf, 'pairs' => $out];
}

/** Intercompany income and expense of one company in a period, by account: invoices to / bills from the partner's
 *  customer and vendor, notes for them, and lines on the pair's intercompany income and expense accounts. */
function r200_pl_activity(array $pair, string $companyId, string $start, string $end): array
{
    $s = r200_side($pair, $companyId);
    $plAccounts = array_values(array_map(static fn(array $a): string => $a['id'], array_filter(r200_pair_accounts((string)$pair['row']['id']), static fn(array $a): bool => $a['companyId'] === $companyId && $a['role'] === 'pl')));
    $cond = []; $args = [];
    if ($s['customer']) { $cond[] = "(je.source_type='invoice' AND je.source_id IN (SELECT id FROM invoices WHERE company_id=? AND customer_id=?))"; array_push($args, $companyId, $s['customer']); }
    if ($s['vendor']) { $cond[] = "(je.source_type='bill' AND je.source_id IN (SELECT id FROM bills WHERE company_id=? AND vendor_id=?))"; array_push($args, $companyId, $s['vendor']); }
    $parties = array_values(array_filter([$s['customer'], $s['vendor']]));
    if ($parties) { $cond[] = "(je.source_type='accounting_note' AND je.source_id IN (SELECT id FROM accounting_notes WHERE company_id=? AND party_id IN (" . implode(',', array_fill(0, count($parties), '?')) . ")))"; array_push($args, $companyId, ...$parties); }
    if ($plAccounts) { $cond[] = 'jl.account_id IN (' . implode(',', array_fill(0, count($plAccounts), '?')) . ')'; array_push($args, ...$plAccounts); }
    if (!$cond) return [];
    $sql = "SELECT a.id,a.code,a.name,a.account_type, SUM(jl.debit_cents-jl.credit_cents) signed
        FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=? AND je.status='posted' AND je.entry_date BETWEEN ? AND ?
        JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id
        WHERE a.account_type IN ('income','expense') AND (" . implode(' OR ', $cond) . ") GROUP BY a.id,a.code,a.name,a.account_type";
    $q = db()->prepare($sql); $q->execute(array_merge([$companyId, $start, $end], $args));
    $rows = [];
    foreach ($q->fetchAll() as $r) { $signed = (int)$r['signed']; if ($signed === 0) continue; $rows[] = ['accountId' => (string)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'type' => (string)$r['account_type'], 'amountCents' => $r['account_type'] === 'income' ? -$signed : $signed]; }
    return $rows;
}

/** Consolidation worksheet for companies with one base currency: each company's amounts, eliminations, consolidated. */
function r200_consolidation(array $user, array $companyIds, string $start, string $end): array
{
    require_once __DIR__ . '/portal.php';
    $companyIds = array_values(array_unique(array_filter(array_map('strval', $companyIds))));
    if (count($companyIds) < 2) fail('Choose at least two companies.', 422, 'group_consolidation_companies');
    if (count($companyIds) > 20) fail('Choose up to 20 companies.', 422, 'group_consolidation_companies');
    $companies = [];
    foreach ($companyIds as $id) { $c = r200_company($user, $id); if (!r200_can($c, 'reports.view')) fail('Your role in ' . $c['name'] . ' does not include reports.', 403, 'group_role'); $companies[$id] = $c; }
    $currencies = array_values(array_unique(array_map(static fn(array $c): string => (string)$c['currency'], $companies)));
    if (count($currencies) > 1) fail('Consolidation needs one base currency. These companies use ' . implode(' and ', $currencies) . '; translation between currencies is not available yet.', 422, 'group_consolidation_currency');
    $start = safe_date($start, 'Start date'); $end = safe_date($end, 'End date'); if ($start > $end) fail('Start date must not be after end date.');
    $sheets = ['profit-loss' => [], 'balance-sheet' => []];
    $key = static fn(array $r): string => ($r['code'] ?? '') . '|' . ($r['type'] ?? '') . '|' . ($r['name'] ?? '');
    foreach (['profit-loss', 'balance-sheet'] as $kind) {
        $lines = [];
        foreach ($companies as $id => $c) {
            $data = portal_financial_report_data($c, $kind, $start, $end);
            foreach ($data['rows'] as $r) {
                $k = $key($r);
                $lines[$k] ??= ['code' => (string)($r['code'] ?? ''), 'name' => (string)$r['name'], 'type' => (string)$r['type'], 'byCompany' => [], 'eliminationCents' => 0];
                $lines[$k]['byCompany'][$id] = ($lines[$k]['byCompany'][$id] ?? 0) + (int)$r['amountCents'];
            }
        }
        $sheets[$kind] = $lines;
    }
    // Eliminations, pair by pair.
    $pairs = r200_pairs($user, $companyIds);
    $notes = []; $plDiff = 0; $bsDiff = 0;
    $acctKey = static function (string $companyId, string $accountId) use ($key): ?string { $q = db()->prepare('SELECT code,name,account_type FROM accounts WHERE id=? AND company_id=?'); $q->execute([$accountId, $companyId]); $a = $q->fetch(); return $a ? $key(['code' => (string)$a['code'], 'type' => (string)$a['account_type'], 'name' => (string)$a['name']]) : null; };
    $controlKey = static function (string $companyId, bool $ar) use ($key): ?string { $q = db()->prepare("SELECT code,name,account_type FROM accounts WHERE company_id=? AND code=? LIMIT 1"); $q->execute([$companyId, $ar ? '1200' : '2050']); $a = $q->fetch(); return $a ? $key(['code' => (string)$a['code'], 'type' => (string)$a['account_type'], 'name' => (string)$a['name']]) : null; };
    $eliminate = static function (string $kind, ?string $k, int $cents, array $fallback) use (&$sheets): void { if ($cents === 0) return; if ($k === null || !isset($sheets[$kind][$k])) { $k = $fallback['code'] . '|' . $fallback['type'] . '|' . $fallback['name']; $sheets[$kind][$k] ??= $fallback + ['byCompany' => [], 'eliminationCents' => 0]; } $sheets[$kind][$k]['eliminationCents'] -= $cents; };
    foreach ($pairs as $pair) {
        $label = $pair['a']['name'] . ' ↔ ' . $pair['b']['name'];
        $income = 0; $expense = 0;
        foreach ([(string)$pair['row']['company_a'], (string)$pair['row']['company_b']] as $cid) {
            foreach (r200_pl_activity($pair, $cid, $start, $end) as $r) {
                $eliminate('profit-loss', $acctKey($cid, $r['accountId']), $r['amountCents'], ['code' => $r['code'], 'name' => $r['name'], 'type' => $r['type']]);
                if ($r['type'] === 'income') $income += $r['amountCents']; else $expense += $r['amountCents'];
            }
        }
        $plDiff += $income - $expense;
        $notes[] = ['pair' => $label, 'kind' => 'profit-loss', 'incomeCents' => $income, 'expenseCents' => $expense, 'differenceCents' => $income - $expense];
        // Balance sheet: each side's receivable/payable with the partner and its balance accounts.
        $net = 0;
        foreach ([(string)$pair['row']['company_a'], (string)$pair['row']['company_b']] as $cid) {
            $s = r200_side($pair, $cid);
            $ar = r200_party_balance($s['company'], true, $s['customer'], $end); $ap = r200_party_balance($s['company'], false, $s['vendor'], $end);
            $eliminate('balance-sheet', $controlKey($cid, true), $ar, ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset']);
            $eliminate('balance-sheet', $controlKey($cid, false), $ap, ['code' => '2050', 'name' => 'Accounts Payable', 'type' => 'liability']);
            $net += $ar - $ap;
            foreach (r200_pair_accounts((string)$pair['row']['id']) as $acc) {
                if ($acc['companyId'] !== $cid || $acc['role'] !== 'balance') continue;
                $bal = r200_account_balance($cid, $acc['id'], $end);
                $eliminate('balance-sheet', $acctKey($cid, $acc['id']), $acc['type'] === 'asset' ? $bal : -$bal, ['code' => $acc['code'], 'name' => $acc['name'], 'type' => $acc['type']]);
                $net += $bal;
            }
        }
        $bsDiff += $net;
        $notes[] = ['pair' => $label, 'kind' => 'balance-sheet', 'differenceCents' => $net];
    }
    $result = ['start' => $start, 'end' => $end, 'currency' => $currencies[0],
        'companies' => array_values(array_map(static fn(array $c): array => ['id' => (string)$c['id'], 'name' => (string)$c['name']], $companies)), 'pairs' => count($pairs), 'notes' => $notes];
    foreach ($sheets as $kind => $lines) {
        $rows = [];
        foreach ($lines as $l) { $total = array_sum($l['byCompany']); $rows[] = $l + ['totalCents' => $total, 'consolidatedCents' => $total + $l['eliminationCents']]; }
        usort($rows, static fn(array $x, array $y): int => [array_search($x['type'], ['income', 'expense', 'asset', 'liability', 'equity'], true), $x['code'], $x['name']] <=> [array_search($y['type'], ['income', 'expense', 'asset', 'liability', 'equity'], true), $y['code'], $y['name']]);
        $result[$kind === 'profit-loss' ? 'profitLoss' : 'balanceSheet'] = $rows;
    }
    // A difference that does not eliminate is shown on its own line, so the worksheet still adds up and the cause can be found.
    // Income and expense are eliminated in full; the part that did not match is added back on one line, so group net
    // income is not changed by an unmatched entry and the difference stays visible.
    if ($plDiff !== 0) $result['profitLoss'][] = ['code' => '', 'name' => 'Intercompany difference to investigate (income not matched by expense)', 'type' => 'difference', 'byCompany' => [], 'eliminationCents' => $plDiff, 'totalCents' => 0, 'consolidatedCents' => $plDiff];
    if ($bsDiff !== 0) $result['balanceSheet'][] = ['code' => '', 'name' => 'Intercompany difference to investigate (balances that do not agree)', 'type' => 'difference', 'byCompany' => [], 'eliminationCents' => $bsDiff, 'totalCents' => 0, 'consolidatedCents' => $bsDiff];
    $sum = static function (array $rows, string $type, string $field): int { $t = 0; foreach ($rows as $r) if ($r['type'] === $type) $t += (int)$r[$field]; return $t; };
    $pl = $result['profitLoss'];
    $result['totals'] = [
        'incomeCents' => $sum($pl, 'income', 'consolidatedCents'), 'expenseCents' => $sum($pl, 'expense', 'consolidatedCents'),
        'netIncomeCents' => $sum($pl, 'income', 'consolidatedCents') - $sum($pl, 'expense', 'consolidatedCents') + $sum($pl, 'difference', 'consolidatedCents'),
        'netIncomeBeforeCents' => $sum($pl, 'income', 'totalCents') - $sum($pl, 'expense', 'totalCents'),
        'plDifferenceCents' => $plDiff, 'bsDifferenceCents' => $bsDiff,
    ];
    return $result;
}

/** Close a period for several companies at once: every company is checked first; then all are closed, or none. */
function r200_group_close(array $user, array $companyIds, string $through): array
{
    $companyIds = array_values(array_unique(array_filter(array_map('strval', $companyIds))));
    if (!$companyIds) fail('Choose the companies to close.', 422, 'group_close_companies');
    $through = safe_date($through, 'Close-through date'); assert_not_future_date($through, 'Close-through date');
    $companies = []; $problems = [];
    foreach ($companyIds as $id) {
        $c = r200_company($user, $id);
        if (!r200_can($c, 'period.lock')) { $problems[] = $c['name'] . ': your role cannot close periods'; continue; }
        $q = db()->prepare("SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND status='pending' AND transaction_date<=?"); $q->execute([$id, $through]);
        if (($n = (int)$q->fetchColumn()) > 0) $problems[] = $c['name'] . ': ' . $n . ' pending bank transaction' . ($n === 1 ? '' : 's') . ' through ' . $through;
        $companies[$id] = $c;
    }
    if ($problems) fail('No company was closed. ' . implode('; ', $problems) . '.', 409, 'group_close_blocked');
    db()->beginTransaction();
    try {
        foreach ($companies as $id => $c) {
            db()->prepare("UPDATE period_locks SET locked=0,reason='Close-through control replaced or reopened',updated_by=? WHERE company_id=? AND period_start=?")->execute([$user['id'], $id, '1900-01-01']);
            db()->prepare("INSERT INTO period_locks (id,company_id,period_start,period_end,locked,reason,updated_by) VALUES (?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE locked=1,reason=VALUES(reason),updated_by=VALUES(updated_by),period_end=VALUES(period_end)")
                ->execute([new_id('periodlock'), $id, '1900-01-01', $through, 'Close-through period control (group close)', $user['id']]);
            db()->prepare("INSERT INTO accounting_controls (company_id,closed_through_date,updated_by) VALUES (?,NULL,?) ON DUPLICATE KEY UPDATE closed_through_date=NULL,updated_by=VALUES(updated_by)")->execute([$id, $user['id']]);
            audit_event($user, $id, 'period.locked', 'period_lock', $id, ['periodStart' => '1900-01-01', 'periodEnd' => $through, 'source' => 'group_close', 'companies' => array_keys($companies)]);
        }
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['closedThroughDate' => $through, 'companies' => array_values(array_map(static fn(array $c): array => ['id' => (string)$c['id'], 'name' => (string)$c['name']], $companies))];
}

function r200_company_lists(array $user, string $companyId): array
{
    $c = r200_company($user, $companyId);
    if (!r200_can($c, 'company.view')) fail('Your role cannot see this company.', 403, 'group_role');
    $q = static function (string $sql) use ($companyId): array { $s = db()->prepare($sql); $s->execute([$companyId]); return $s->fetchAll(); };
    return [
        'company' => ['id' => (string)$c['id'], 'name' => (string)$c['name'], 'currency' => (string)$c['currency'], 'role' => (string)$c['role']],
        'customers' => array_map(static fn($r) => ['id' => (string)$r['id'], 'name' => (string)$r['name']], $q('SELECT id,name FROM customers WHERE company_id=? AND active=1 ORDER BY name')),
        'vendors' => array_map(static fn($r) => ['id' => (string)$r['id'], 'name' => (string)$r['name']], $q('SELECT id,name FROM vendors WHERE company_id=? AND active=1 ORDER BY name')),
        'accounts' => array_map(static fn($r) => ['id' => (string)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'type' => (string)$r['account_type']], $q("SELECT id,code,name,account_type FROM accounts WHERE company_id=? AND active=1 AND COALESCE(is_control,0)=0 ORDER BY code")),
    ];
}

function handle_group_r200(string $sub): never
{
    $user = require_user();
    $method = request_method();
    r200_schema_ready();
    $csv = static fn(string $v): array => array_values(array_filter(array_map('trim', explode(',', $v))));
    if ($sub === 'companies' && $method === 'GET') {
        $list = function_exists('companies_for_user') ? companies_for_user((string)$user['id']) : [];
        json_response(['companies' => array_values(array_map(static fn(array $c): array => ['id' => (string)$c['id'], 'name' => (string)$c['name'], 'currency' => (string)($c['currency'] ?? ''), 'role' => (string)($c['role'] ?? '')], $list))]);
    }
    if ($sub === 'lists' && $method === 'GET') json_response(r200_company_lists($user, (string)($_GET['companyId'] ?? '')));
    if ($sub === 'pairs') {
        if ($method === 'GET') json_response(['pairs' => array_map('r200_pair_public', r200_pairs($user))]);
        require_csrf(); $input = request_json();
        if ($method === 'POST') json_response(['pair' => r200_pair_save($user, $input)], 201);
        if ($method === 'DELETE') {
            $id = trim((string)($input['id'] ?? ''));
            $pair = array_values(array_filter(r200_pairs($user), static fn(array $p): bool => (string)$p['row']['id'] === $id))[0] ?? null;
            if (!$pair) fail('Intercompany pair not found.', 404, 'group_pair_not_found');
            if (!r200_can($pair['a'], 'company.settings') || !r200_can($pair['b'], 'company.settings')) fail('Only the Owner or an Admin of both companies removes the link.', 403, 'group_pair_role');
            db()->prepare('UPDATE ic_pairs SET active=0,updated_at=NOW() WHERE id=?')->execute([$id]);
            audit_event($user, (string)$pair['row']['company_a'], 'group.pair_removed', 'ic_pair', $id, ['partner' => (string)$pair['row']['company_b']]);
            audit_event($user, (string)$pair['row']['company_b'], 'group.pair_removed', 'ic_pair', $id, ['partner' => (string)$pair['row']['company_a']]);
            json_response(['removed' => true]);
        }
        fail('Method not allowed.', 405, 'method_not_allowed');
    }
    if ($sub === 'documents' && $method === 'GET') json_response(['documents' => r200_documents($user, (string)($_GET['companyId'] ?? ''))]);
    if ($sub === 'mirror' && $method === 'POST') { require_csrf(); $i = request_json(); json_response(['bill' => r200_mirror_invoice($user, (string)($i['companyId'] ?? ''), (string)($i['invoiceId'] ?? ''))], 201); }
    if ($sub === 'reconciliation' && $method === 'GET') {
        $asOf = safe_date((string)($_GET['asOf'] ?? date('Y-m-d')), 'As-of date');
        $only = isset($_GET['companies']) && $_GET['companies'] !== '' ? $csv((string)$_GET['companies']) : null;
        json_response(r200_reconciliation($user, $asOf, $only));
    }
    if ($sub === 'consolidation' && $method === 'GET') json_response(r200_consolidation($user, $csv((string)($_GET['companies'] ?? '')), (string)($_GET['start'] ?? date('Y-01-01')), (string)($_GET['end'] ?? date('Y-m-d'))));
    if ($sub === 'close' && $method === 'POST') { require_csrf(); $i = request_json(); json_response(r200_group_close($user, (array)($i['companyIds'] ?? []), (string)($i['closedThroughDate'] ?? ''))); }
    fail('Not found.', 404, 'route_not_found');
}
