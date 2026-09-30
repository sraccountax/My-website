<?php
declare(strict_types=1);

/* R137: user-defined tax codes.
   A tax code (for example "QC" or "GST-ONLY") holds one or more named tax
   components (GST, QST, PST, HST, CGST, SGST ...). Each component has its own
   rate (thousandths of a percent: 5% = 5000, 9.975% = 9975), the GL account
   that collects it on sales, and the GL account that recovers it on purchases
   (or no recovery, in which case the tax becomes part of the purchase cost).
   A code may be linked to one region (province); customers in that region get
   it by default on invoice lines. Only one active code may claim a region.
   Codes stay editable; documents keep a snapshot of the components they used
   in document_tax_lines, so editing a code never rewrites posted history.

   Companies created from R137 start in "codes" mode with no codes: nothing is
   charged until the owner sets them up. Existing companies stay in "legacy"
   mode (the R135/R136 GST/HST + PST rules) until their first tax code is
   saved, which switches them to codes mode. */

const TEGH_TAX_REGIONS = ['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'];

function tegh_tax_codes_ready(): bool
{
    static $ready = null;
    if ($ready === true) return true;
    $probe = db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name='tax_codes' AND column_name='active_region') OR (table_name='tax_code_components' AND column_name='purchase_recoverable') OR (table_name='document_tax_lines' AND column_name='foreign_tax_cents') OR (table_name='invoice_lines' AND column_name='tax_code_id') OR (table_name='bills' AND column_name='tax_code_id') OR (table_name='expenses' AND column_name='tax_code_id') OR (table_name='companies' AND column_name='tax_setup_mode'))")->fetchColumn();
    if ((int)$probe === 7) return $ready = true;
    if (db()->inTransaction()) return $ready = false; // DDL would commit an open transaction
    db()->exec("CREATE TABLE IF NOT EXISTS tax_codes (
      id VARCHAR(64) NOT NULL PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      code VARCHAR(20) NOT NULL,
      name VARCHAR(120) NOT NULL,
      region VARCHAR(10) NULL,
      description VARCHAR(500) NULL,
      status ENUM('active','inactive') NOT NULL DEFAULT 'active',
      active_region VARCHAR(10) GENERATED ALWAYS AS (IF(status='active', region, NULL)) STORED,
      created_by VARCHAR(64) NULL,
      updated_by VARCHAR(64) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY tax_codes_code_unique (company_id, code),
      UNIQUE KEY tax_codes_region_unique (company_id, active_region),
      CONSTRAINT tax_codes_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS tax_code_components (
      id VARCHAR(64) NOT NULL PRIMARY KEY,
      tax_code_id VARCHAR(64) NOT NULL,
      company_id VARCHAR(64) NOT NULL,
      name VARCHAR(40) NOT NULL,
      rate_mpct INT NOT NULL,
      sales_account_id VARCHAR(64) NULL,
      purchase_account_id VARCHAR(64) NULL,
      purchase_recoverable TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      KEY tax_code_components_code (tax_code_id, sort_order),
      CONSTRAINT tax_code_components_code_fk FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id) ON DELETE CASCADE,
      CONSTRAINT tax_code_components_rate_ck CHECK (rate_mpct >= 0 AND rate_mpct <= 50000)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS document_tax_lines (
      id VARCHAR(64) NOT NULL PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      document_type VARCHAR(20) NOT NULL,
      document_id VARCHAR(64) NOT NULL,
      side ENUM('sales','purchase') NOT NULL,
      tax_code_id VARCHAR(64) NULL,
      tax_code VARCHAR(20) NOT NULL,
      component_id VARCHAR(64) NULL,
      component_name VARCHAR(40) NOT NULL,
      rate_mpct INT NOT NULL,
      account_id VARCHAR(64) NULL,
      recoverable TINYINT(1) NOT NULL DEFAULT 1,
      taxable_base_cents BIGINT NOT NULL DEFAULT 0,
      tax_cents BIGINT NOT NULL,
      foreign_tax_cents BIGINT NOT NULL DEFAULT 0,
      sort_order INT NOT NULL DEFAULT 0,
      KEY document_tax_lines_doc (company_id, document_type, document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([['invoice_lines','tax_code_id','VARCHAR(64) NULL DEFAULT NULL'],['bills','tax_code_id','VARCHAR(64) NULL DEFAULT NULL'],['expenses','tax_code_id','VARCHAR(64) NULL DEFAULT NULL'],['companies','tax_setup_mode',"VARCHAR(10) NOT NULL DEFAULT 'legacy'"]] as [$t,$c,$d]) {
        if (schema_table_exists($t) && !schema_column_exists($t, $c)) db()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $d");
    }
    return $ready = true;
}

function tax_setup_mode(array $company): string
{
    return (string)($company['tax_setup_mode'] ?? 'legacy') === 'codes' ? 'codes' : 'legacy';
}

function tax_codes_list(string $companyId, bool $includeInactive = false): array
{
    if (!tegh_tax_codes_ready() && !schema_table_exists('tax_codes')) return [];
    $q = db()->prepare('SELECT * FROM tax_codes WHERE company_id=?' . ($includeInactive ? '' : " AND status='active'") . ' ORDER BY status, code');
    $q->execute([$companyId]);
    $codes = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$codes) return [];
    $c = db()->prepare('SELECT tc.*, sa.code sales_account_code, sa.name sales_account_name, pa.code purchase_account_code, pa.name purchase_account_name FROM tax_code_components tc LEFT JOIN accounts sa ON sa.id=tc.sales_account_id LEFT JOIN accounts pa ON pa.id=tc.purchase_account_id WHERE tc.company_id=? ORDER BY tc.tax_code_id, tc.sort_order, tc.id');
    $c->execute([$companyId]);
    $by = [];
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(string)$r['tax_code_id']][] = $r;
    return array_map(static fn(array $r): array => tax_code_public($r, $by[(string)$r['id']] ?? []), $codes);
}

function tax_code_public(array $r, array $components): array
{
    $comps = array_map(static fn(array $c): array => [
        'id' => (string)$c['id'], 'name' => (string)$c['name'], 'rateMpct' => (int)$c['rate_mpct'],
        'salesAccountId' => $c['sales_account_id'] !== null ? (string)$c['sales_account_id'] : null,
        'salesAccount' => isset($c['sales_account_code']) && $c['sales_account_code'] !== null ? $c['sales_account_code'] . ' · ' . $c['sales_account_name'] : null,
        'purchaseAccountId' => $c['purchase_account_id'] !== null ? (string)$c['purchase_account_id'] : null,
        'purchaseAccount' => isset($c['purchase_account_code']) && $c['purchase_account_code'] !== null ? $c['purchase_account_code'] . ' · ' . $c['purchase_account_name'] : null,
        'purchaseRecoverable' => (bool)$c['purchase_recoverable'],
    ], $components);
    return ['id' => (string)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'region' => $r['region'] !== null ? (string)$r['region'] : null,
        'description' => (string)($r['description'] ?? ''), 'status' => (string)$r['status'], 'components' => $comps,
        'totalRateMpct' => array_sum(array_map(static fn(array $c): int => $c['rateMpct'], $comps))];
}

function tax_code_get(string $companyId, string $id, bool $activeOnly = true): ?array
{
    if ($id === '') return null;
    foreach (tax_codes_list($companyId, !$activeOnly) as $code) if ($code['id'] === $id) return $code;
    return null;
}

function tax_code_for_region(string $companyId, ?string $region): ?array
{
    $region = strtoupper(trim((string)$region));
    if ($region === '') return null;
    foreach (tax_codes_list($companyId) as $code) if ($code['region'] === $region) return $code;
    return null;
}

/** Tax for one amount under one code. Returns per-component foreign tax and the net/gross. */
function tax_code_compute(array $code, int $amountCents, string $mode = 'exclusive'): array
{
    $comps = $code['components'];
    $total = array_sum(array_map(static fn(array $c): int => (int)$c['rateMpct'], $comps));
    if ($mode === 'none' || $total === 0 || !$comps) return ['net' => $amountCents, 'gross' => $amountCents, 'tax' => 0, 'parts' => array_map(static fn(array $c): int => 0, $comps)];
    if ($mode === 'inclusive') {
        $net = (int)round(($amountCents * 100000) / (100000 + $total));
        $tax = $amountCents - $net;
        $parts = []; $left = $tax; $last = count($comps) - 1;
        foreach ($comps as $i => $c) { $p = $i === $last ? $left : (int)round($tax * (int)$c['rateMpct'] / $total); $parts[] = $p; $left -= $p; }
        return ['net' => $net, 'gross' => $amountCents, 'tax' => $tax, 'parts' => $parts];
    }
    $parts = array_map(static fn(array $c): int => (int)round(($amountCents * (int)$c['rateMpct']) / 100000), $comps);
    $tax = array_sum($parts);
    return ['net' => $amountCents, 'gross' => $amountCents + $tax, 'tax' => $tax, 'parts' => $parts];
}

/** Split a base-currency total across weights (largest remainder, never negative). */
function tax_allocate(int $total, array $weights): array
{
    $sum = array_sum($weights);
    if ($total === 0 || $sum <= 0) { $out = array_fill(0, count($weights), 0); if ($total !== 0 && $out) $out[count($out) - 1] = $total; return $out; }
    $out = []; $rema = []; $given = 0;
    foreach ($weights as $i => $w) { $exact = $total * $w / $sum; $f = (int)floor($exact); $out[$i] = $f; $given += $f; $rema[$i] = $exact - $f; }
    arsort($rema);
    foreach (array_keys($rema) as $i) { if ($given >= $total) break; $out[$i]++; $given++; }
    return array_values($out);
}

/** Merge a line's component tax into document rows keyed by code+component. */
function tax_rows_add(array &$rows, array $code, array $foreignParts, array $baseParts, int $baseAmount, string $side): void
{
    foreach ($code['components'] as $i => $c) {
        $key = $code['id'] . '|' . $c['id'];
        if (!isset($rows[$key])) $rows[$key] = ['side' => $side, 'taxCodeId' => $code['id'], 'taxCode' => $code['code'], 'componentId' => $c['id'], 'componentName' => $c['name'], 'rateMpct' => (int)$c['rateMpct'],
            'accountId' => $side === 'sales' ? $c['salesAccountId'] : ($c['purchaseRecoverable'] ? $c['purchaseAccountId'] : null), 'recoverable' => $side === 'sales' ? true : (bool)$c['purchaseRecoverable'],
            'taxableBaseCents' => 0, 'taxCents' => 0, 'foreignTaxCents' => 0, 'sortOrder' => count($rows)];
        $rows[$key]['taxableBaseCents'] += $baseAmount;
        $rows[$key]['taxCents'] += (int)($baseParts[$i] ?? 0);
        $rows[$key]['foreignTaxCents'] += (int)($foreignParts[$i] ?? 0);
    }
}

function document_tax_rows_save(string $companyId, string $type, string $documentId, array $rows): void
{
    if (!schema_table_exists('document_tax_lines')) return;
    db()->prepare('DELETE FROM document_tax_lines WHERE company_id=? AND document_type=? AND document_id=?')->execute([$companyId, $type, $documentId]);
    $ins = db()->prepare('INSERT INTO document_tax_lines(id,company_id,document_type,document_id,side,tax_code_id,tax_code,component_id,component_name,rate_mpct,account_id,recoverable,taxable_base_cents,tax_cents,foreign_tax_cents,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $i = 0;
    foreach (array_values($rows) as $r) {
        if ((int)$r['taxCents'] === 0 && (int)$r['foreignTaxCents'] === 0) continue;
        $ins->execute([new_id('doctax'), $companyId, $type, $documentId, $r['side'], $r['taxCodeId'] ?? null, (string)$r['taxCode'], $r['componentId'] ?? null, (string)$r['componentName'], (int)$r['rateMpct'], $r['accountId'] ?? null, !empty($r['recoverable']) ? 1 : 0, (int)$r['taxableBaseCents'], (int)$r['taxCents'], (int)$r['foreignTaxCents'], $i++]);
    }
}

function document_tax_rows_get(string $companyId, string $type, string $documentId): array
{
    if (!schema_table_exists('document_tax_lines')) return [];
    $q = db()->prepare('SELECT * FROM document_tax_lines WHERE company_id=? AND document_type=? AND document_id=? ORDER BY sort_order, id');
    $q->execute([$companyId, $type, $documentId]);
    return array_map(static fn(array $r): array => ['side' => (string)$r['side'], 'taxCodeId' => $r['tax_code_id'], 'taxCode' => (string)$r['tax_code'], 'componentId' => $r['component_id'], 'componentName' => (string)$r['component_name'], 'rateMpct' => (int)$r['rate_mpct'],
        'accountId' => $r['account_id'], 'recoverable' => (bool)$r['recoverable'], 'taxableBaseCents' => (int)$r['taxable_base_cents'], 'taxCents' => (int)$r['tax_cents'], 'foreignTaxCents' => (int)$r['foreign_tax_cents'], 'sortOrder' => (int)$r['sort_order']], $q->fetchAll(PDO::FETCH_ASSOC));
}

/** Scale rows to a portion of their total tax (for notes, partial cash-basis receipts). */
function tax_rows_portion(array $rows, int $portion, ?int $foreignPortion = null): array
{
    $rows = array_values($rows);
    $alloc = tax_allocate($portion, array_map(static fn(array $r): int => max(0, (int)$r['taxCents']), $rows));
    $falloc = $foreignPortion === null ? null : tax_allocate($foreignPortion, array_map(static fn(array $r): int => max(0, (int)$r['foreignTaxCents']), $rows));
    foreach ($rows as $i => &$r) { $r['taxCents'] = $alloc[$i]; if ($falloc !== null) $r['foreignTaxCents'] = $falloc[$i]; }
    unset($r);
    return $rows;
}

/** Journal lines for sales tax collected (credit), or reversed (debit) for customer credit notes. */
function tax_rows_sales_lines(string $companyId, array $rows, bool $reverse = false, string $memoSuffix = ''): array
{
    $lines = [];
    foreach ($rows as $r) {
        $amt = (int)$r['taxCents'];
        if ($amt === 0) continue;
        $account = (string)($r['accountId'] ?? '');
        if ($account === '') fail('Tax code ' . $r['taxCode'] . ' has no sales GL account for ' . $r['componentName'] . '. Set it under Settings → Tax Codes.', 409, 'tax_code_sales_account_missing');
        $memo = $r['componentName'] . ' ' . tax_rate_label((int)$r['rateMpct']) . ($reverse ? ' adjustment' : ' collected') . $memoSuffix;
        $lines[] = ['accountId' => $account, 'debitCents' => $reverse ? $amt : 0, 'creditCents' => $reverse ? 0 : $amt, 'memo' => $memo];
    }
    return $lines;
}

/** Purchase side: recoverable rows debit their account; the rest is returned as extra cost. */
function tax_rows_purchase_lines(string $companyId, array $rows, bool $reverse = false): array
{
    $lines = []; $cost = 0;
    foreach ($rows as $r) {
        $amt = (int)$r['taxCents'];
        if ($amt === 0) continue;
        if (!empty($r['recoverable']) && (string)($r['accountId'] ?? '') !== '') {
            $lines[] = ['accountId' => (string)$r['accountId'], 'debitCents' => $reverse ? 0 : $amt, 'creditCents' => $reverse ? $amt : 0, 'memo' => $r['componentName'] . ' ' . tax_rate_label((int)$r['rateMpct']) . ' recoverable' . ($reverse ? ' adjustment' : '')];
        } else $cost += $amt;
    }
    return ['lines' => $lines, 'costCents' => $cost];
}

/** Legacy two-bucket view kept on documents (gst_hst_cents / pst_cents) for summaries. */
function tax_rows_buckets(string $companyId, array $rows): array
{
    static $codes = [];
    $gst = 0; $pst = 0;
    foreach ($rows as $r) {
        $id = (string)($r['accountId'] ?? '');
        if ($id !== '' && !isset($codes[$id])) { $q = db()->prepare('SELECT code FROM accounts WHERE id=? AND company_id=?'); $q->execute([$id, $companyId]); $codes[$id] = (string)($q->fetchColumn() ?: ''); }
        $code = $id !== '' ? $codes[$id] : '';
        if (in_array($code, ['2100', '1100'], true)) $gst += (int)$r['taxCents']; else $pst += (int)$r['taxCents'];
    }
    return [$gst, $pst];
}

function tax_rate_label(int $mpct): string
{
    $v = $mpct / 1000;
    return (abs($v - round($v, 2)) < 1e-9 ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : rtrim(number_format($v, 3, '.', ''), '0')) . '%';
}

/** Validate and save one code with its components (create or edit). */
function tax_code_save(array $user, array $company, array $input): array
{
    $companyId = (string)$company['id'];
    $id = trim((string)($input['id'] ?? ''));
    $code = strtoupper(clean_text($input['code'] ?? '', 'Tax code', 20));
    if (!preg_match('/^[A-Z0-9][A-Z0-9 ._\-\/+]{0,19}$/', $code)) fail('Use letters, numbers, spaces, dots, dashes or slashes in the tax code (up to 20).', 422, 'tax_code_invalid');
    $name = clean_text($input['name'] ?? '', 'Tax code name', 120);
    $region = strtoupper(trim((string)($input['region'] ?? '')));
    if ($region === '') $region = null;
    elseif (!preg_match('/^[A-Z0-9\-]{2,10}$/', $region)) fail('Choose a province, or enter a 2–10 character region code.', 422, 'tax_code_region_invalid');
    $description = optional_text($input['description'] ?? null, 500);
    $status = ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $components = $input['components'] ?? [];
    if (!is_array($components) || count($components) > 6) fail('A tax code can have up to 6 taxes.', 422, 'tax_code_components_invalid');
    $clean = []; $names = [];
    foreach (array_values($components) as $i => $c) {
        if (!is_array($c)) fail('A tax in this code is invalid.', 422, 'tax_code_components_invalid');
        $cname = clean_text($c['name'] ?? '', 'Tax name', 40);
        $key = mb_strtolower($cname);
        if (isset($names[$key])) fail('Each tax in a code needs a different name.', 422, 'tax_code_component_duplicate');
        $names[$key] = true;
        if (isset($c['rateMpct']) && $c['rateMpct'] !== '') $rate = filter_var($c['rateMpct'], FILTER_VALIDATE_INT);
        elseif (isset($c['ratePercent']) && is_numeric($c['ratePercent'])) $rate = (int)round((float)$c['ratePercent'] * 1000);
        else $rate = false;
        if ($rate === false || $rate < 0 || $rate > 50000) fail($cname . ': enter a rate from 0% to 50% (up to three decimals).', 422, 'tax_code_rate_invalid');
        $sales = trim((string)($c['salesAccountId'] ?? '')) ?: null;
        $purchase = trim((string)($c['purchaseAccountId'] ?? '')) ?: null;
        $recoverable = array_key_exists('purchaseRecoverable', $c) ? (bool)$c['purchaseRecoverable'] : true;
        if ($sales === null && $purchase === null) fail($cname . ': choose at least one GL account (sales or purchases).', 422, 'tax_code_account_required');
        if ($sales !== null) tax_code_assert_account($companyId, $sales, 'sales', $cname);
        if ($purchase !== null) tax_code_assert_account($companyId, $purchase, 'purchase', $cname);
        if ($recoverable && $purchase === null) $recoverable = false;
        $clean[] = ['name' => $cname, 'rate' => (int)$rate, 'sales' => $sales, 'purchase' => $purchase, 'recoverable' => $recoverable, 'sort' => $i];
    }
    if (!tegh_tax_codes_ready()) fail('Tax code storage is being prepared. Try again.', 503, 'tax_codes_not_ready');
    return db_transaction_retry(static function () use ($user, $companyId, $id, $code, $name, $region, $description, $status, $clean): array {
        db()->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE')->execute([$companyId]);
        $prior = null;
        if ($id !== '') {
            $q = db()->prepare('SELECT * FROM tax_codes WHERE id=? AND company_id=? FOR UPDATE'); $q->execute([$id, $companyId]); $prior = $q->fetch(PDO::FETCH_ASSOC);
            if (!$prior) fail('That tax code no longer exists.', 404, 'tax_code_not_found');
        }
        $dup = db()->prepare('SELECT status FROM tax_codes WHERE company_id=? AND code=? AND id<>? LIMIT 1'); $dup->execute([$companyId, $code, $id]);
        $dupStatus = $dup->fetchColumn();
        if ($dupStatus !== false) fail('Tax code ' . $code . ' already exists' . ($dupStatus === 'inactive' ? ' (inactive). Edit and reactivate it, or use a different code.' : '. Use a different code.'), 409, 'tax_code_duplicate');
        if ($region !== null && $status === 'active') {
            $r = db()->prepare("SELECT code FROM tax_codes WHERE company_id=? AND region=? AND status='active' AND id<>? LIMIT 1"); $r->execute([$companyId, $region, $id]);
            $taken = $r->fetchColumn();
            if ($taken !== false) fail('Region ' . $region . ' already uses tax code ' . $taken . '. A region can have only one tax code; edit that code instead, or remove its region first.', 409, 'tax_code_region_taken');
        }
        if ($prior === null) {
            $id = new_id('taxcode');
            db()->prepare('INSERT INTO tax_codes(id,company_id,code,name,region,description,status,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id, $companyId, $code, $name, $region, $description, $status, $user['id'], $user['id']]);
        } else {
            db()->prepare('UPDATE tax_codes SET code=?,name=?,region=?,description=?,status=?,updated_by=? WHERE id=? AND company_id=?')->execute([$code, $name, $region, $description, $status, $user['id'], $id, $companyId]);
        }
        $before = db()->prepare('SELECT name,rate_mpct,sales_account_id,purchase_account_id,purchase_recoverable FROM tax_code_components WHERE tax_code_id=? ORDER BY sort_order'); $before->execute([$id]);
        $priorComponents = $before->fetchAll(PDO::FETCH_ASSOC);
        db()->prepare('DELETE FROM tax_code_components WHERE tax_code_id=? AND company_id=?')->execute([$id, $companyId]);
        $ins = db()->prepare('INSERT INTO tax_code_components(id,tax_code_id,company_id,name,rate_mpct,sales_account_id,purchase_account_id,purchase_recoverable,sort_order) VALUES(?,?,?,?,?,?,?,?,?)');
        foreach ($clean as $c) $ins->execute([new_id('taxcomp'), $id, $companyId, $c['name'], $c['rate'], $c['sales'], $c['purchase'], $c['recoverable'] ? 1 : 0, $c['sort']]);
        $switched = false;
        $mode = db()->prepare('SELECT tax_setup_mode FROM companies WHERE id=?'); $mode->execute([$companyId]);
        if ((string)$mode->fetchColumn() !== 'codes') { db()->prepare("UPDATE companies SET tax_setup_mode='codes' WHERE id=?")->execute([$companyId]); $switched = true; }
        audit_event($user, $companyId, $prior ? 'tax_code.updated' : 'tax_code.created', 'tax_code', $id, ['code' => $code, 'region' => $region, 'status' => $status,
            'components' => array_map(static fn(array $c): array => ['name' => $c['name'], 'rateMpct' => $c['rate'], 'salesAccountId' => $c['sales'], 'purchaseAccountId' => $c['purchase'], 'recoverable' => $c['recoverable']], $clean),
            'previous' => $prior ? ['code' => $prior['code'], 'region' => $prior['region'], 'status' => $prior['status'], 'components' => $priorComponents] : null, 'switchedToTaxCodes' => $switched]);
        return ['id' => $id, 'switchedToTaxCodes' => $switched];
    });
}

function tax_code_assert_account(string $companyId, string $accountId, string $side, string $label): void
{
    $q = db()->prepare('SELECT code,account_type,active FROM accounts WHERE id=? AND company_id=?'); $q->execute([$accountId, $companyId]);
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a || !(bool)$a['active']) fail($label . ': choose an active GL account.', 422, 'tax_code_account_invalid');
    $blocked = ['1200', '2050', '9999', '3200'];
    if (in_array((string)$a['code'], $blocked, true)) fail($label . ': ' . $a['code'] . ' is a control account and cannot hold tax.', 422, 'tax_code_account_invalid');
    $bank = db()->prepare('SELECT 1 FROM bank_accounts WHERE company_id=? AND ledger_account_id=? LIMIT 1'); $bank->execute([$companyId, $accountId]);
    if ($bank->fetchColumn()) fail($label . ': a bank or card account cannot hold tax.', 422, 'tax_code_account_invalid');
    if ($side === 'sales' && (string)$a['account_type'] !== 'liability') fail($label . ': tax collected on sales must go to a liability account (for example 2100 GST/HST Payable).', 422, 'tax_code_account_invalid');
    if ($side === 'purchase' && !in_array((string)$a['account_type'], ['asset', 'liability'], true)) fail($label . ': recoverable tax on purchases must go to an asset or liability account (for example 1100 GST/HST Recoverable).', 422, 'tax_code_account_invalid');
}

function handle_tax_codes(): never
{
    require_method('GET', 'POST', 'PUT', 'DELETE');
    $user = require_user(); $company = require_company($user); $companyId = (string)$company['id'];
    tegh_tax_codes_ready();
    if (request_method() === 'GET') {
        $mode = db()->prepare('SELECT tax_setup_mode FROM companies WHERE id=?'); $mode->execute([$companyId]);
        json_response(['taxCodes' => tax_codes_list($companyId, true), 'taxSetupMode' => (string)($mode->fetchColumn() ?: 'legacy'), 'regions' => TEGH_TAX_REGIONS]);
    }
    require_csrf(); require_company_role($company, 'owner', 'admin', 'bookkeeper');
    $input = request_json();
    if (request_method() === 'DELETE') {
        $id = clean_text($input['id'] ?? '', 'Tax code', 64);
        $result = db_transaction_retry(static function () use ($user, $companyId, $id): array {
            $q = db()->prepare('SELECT * FROM tax_codes WHERE id=? AND company_id=? FOR UPDATE'); $q->execute([$id, $companyId]); $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) fail('That tax code no longer exists.', 404, 'tax_code_not_found');
            $used = db()->prepare('SELECT 1 FROM document_tax_lines WHERE company_id=? AND tax_code_id=? LIMIT 1'); $used->execute([$companyId, $id]);
            if ($used->fetchColumn()) {
                db()->prepare("UPDATE tax_codes SET status='inactive',updated_by=? WHERE id=?")->execute([$user['id'], $id]);
                audit_event($user, $companyId, 'tax_code.deactivated', 'tax_code', $id, ['code' => $row['code'], 'reason' => 'used_on_documents']);
                return ['id' => $id, 'status' => 'inactive', 'message' => 'This code is used on saved documents, so it was made inactive instead of deleted. Its region is free for another code.'];
            }
            db()->prepare('DELETE FROM tax_codes WHERE id=? AND company_id=?')->execute([$id, $companyId]);
            audit_event($user, $companyId, 'tax_code.deleted', 'tax_code', $id, ['code' => $row['code'], 'region' => $row['region']]);
            return ['id' => $id, 'status' => 'deleted'];
        });
        json_response(['taxCode' => $result]);
    }
    if (($input['action'] ?? '') === 'seed-canada') {
        require_company_role($company, 'owner', 'admin');
        $r = tax_codes_seed_canada($user, $companyId);
        if (($r['reason'] ?? '') === 'missing_accounts') fail('The starter codes need GL accounts 2100 (GST/HST Payable), 1100 (GST/HST Recoverable) and 2110 (PST Payable). Add them to the chart of accounts, or create tax codes by hand.', 409, 'tax_code_starter_accounts_missing');
        json_response(['created' => $r['created'], 'skipped' => $r['skipped'], 'taxCodes' => tax_codes_list($companyId, true)], 201);
    }
    $saved = tax_code_save($user, $company, $input);
    json_response(['taxCode' => tax_code_get($companyId, $saved['id'], false), 'switchedToTaxCodes' => $saved['switchedToTaxCodes']], request_method() === 'POST' ? 201 : 200);
}

/** R137: tax code for a bank-feed categorisation decision, or null (legacy rules / no tax). */
function bank_decision_tax_code(array $company, array $decision): ?array
{
    if (tax_setup_mode($company) !== 'codes') return null;
    $companyId = (string)$company['id'];
    if (array_key_exists('taxCodeId', $decision) && $decision['taxCodeId'] !== null) {
        $wanted = trim((string)$decision['taxCodeId']);
        if ($wanted === '') return null;
        $code = tax_code_get($companyId, $wanted);
        if (!$code) fail('The selected tax code is no longer active.', 422, 'tax_code_unavailable');
        return $code;
    }
    if (!empty($decision['applyGstHst']) || !empty($decision['applyPst']) || in_array((string)($decision['taxCode'] ?? ''), ['GST_HST', 'HST13', 'GST_HST_PST', 'PST'], true)) return tax_code_for_region($companyId, (string)$company['province']);
    return null;
}


/* R138: starter Canadian tax codes, one per province/territory plus "GST only".
   They use the default chart's tax accounts (2100/1100 for GST/HST, 2110 for
   PST/QST/RST; QST is recoverable to 1110, BC/MB/SK PST is not recoverable and
   becomes part of cost). Provinces that already have a code and codes whose
   name is taken are skipped. Everything created stays fully editable. */
function tax_codes_canada_starter(): array
{
    $gst = ['GST', 5000, '2100', '1100'];
    $hst = static fn(int $r): array => [['HST', $r, '2100', '1100']];
    return [
        'AB' => ['Alberta', [$gst]], 'BC' => ['British Columbia', [$gst, ['PST', 7000, '2110', null]]],
        'MB' => ['Manitoba', [$gst, ['RST', 7000, '2110', null]]], 'NB' => ['New Brunswick', $hst(15000)],
        'NL' => ['Newfoundland and Labrador', $hst(15000)], 'NS' => ['Nova Scotia', $hst(14000)],
        'NT' => ['Northwest Territories', [$gst]], 'NU' => ['Nunavut', [$gst]], 'ON' => ['Ontario', $hst(13000)],
        'PE' => ['Prince Edward Island', $hst(15000)], 'QC' => ['Quebec', [$gst, ['QST', 9975, '2110', '1110']]],
        'SK' => ['Saskatchewan', [$gst, ['PST', 6000, '2110', null]]], 'YT' => ['Yukon', [$gst]],
    ];
}

function tax_codes_seed_canada(array $user, string $companyId): array
{
    if (!tegh_tax_codes_ready()) return ['created' => [], 'skipped' => [], 'reason' => 'not_ready'];
    $acct = static function (string $code) use ($companyId): ?string { $q = db()->prepare('SELECT id FROM accounts WHERE company_id=? AND code=? AND active=1 LIMIT 1'); $q->execute([$companyId, $code]); $id = $q->fetchColumn(); return $id === false ? null : (string)$id; };
    if ($acct('2100') === null) return ['created' => [], 'skipped' => array_keys(tax_codes_canada_starter()), 'reason' => 'missing_accounts'];
    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction();
    try {
        $existing = db()->prepare('SELECT code, region, status FROM tax_codes WHERE company_id=?'); $existing->execute([$companyId]);
        $codes = []; $regions = [];
        foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $r) { $codes[strtoupper((string)$r['code'])] = true; if ($r['status'] === 'active' && $r['region'] !== null) $regions[(string)$r['region']] = true; }
        $insCode = db()->prepare("INSERT INTO tax_codes(id,company_id,code,name,region,description,status,created_by,updated_by) VALUES(?,?,?,?,?,?,'active',?,?)");
        $insComp = db()->prepare('INSERT INTO tax_code_components(id,tax_code_id,company_id,name,rate_mpct,sales_account_id,purchase_account_id,purchase_recoverable,sort_order) VALUES(?,?,?,?,?,?,?,?,?)');
        $created = []; $skipped = [];
        $add = static function (string $code, string $name, ?string $region, array $components, string $note) use ($companyId, $user, $insCode, $insComp, $acct, &$created, &$skipped, &$codes, &$regions): void {
            if (isset($codes[$code]) || ($region !== null && isset($regions[$region]))) { $skipped[] = $code; return; }
            foreach ($components as [, , $sales]) if ($acct($sales) === null) { $skipped[] = $code; return; }
            $id = new_id('taxcode');
            $insCode->execute([$id, $companyId, $code, $name, $region, $note, $user['id'] ?? null, $user['id'] ?? null]);
            foreach (array_values($components) as $i => [$cname, $rate, $sales, $purchase]) {
                $pid = $purchase !== null ? $acct($purchase) : null;
                $insComp->execute([new_id('taxcomp'), $id, $companyId, $cname, $rate, $acct($sales), $pid, $pid !== null ? 1 : 0, $i]);
            }
            $codes[$code] = true; if ($region !== null) $regions[$region] = true; $created[] = $code;
        };
        foreach (tax_codes_canada_starter() as $prov => [$provName, $components]) {
            $label = $provName . ' — ' . implode(' + ', array_map(static fn(array $c): string => $c[0] . ' ' . tax_rate_label((int)$c[1]), $components));
            $add($prov, $label, $prov, $components, 'Starter code created by Tegh from general Canadian rates. Review the rates and GL accounts before use; edit any time.');
        }
        $add('GST', 'GST only — 5% (no provincial tax)', null, [['GST', 5000, '2100', '1100']], 'Use for suppliers or sales where only GST applies, for example a BC supplier who charged no PST.');
        if ($created) db()->prepare("UPDATE companies SET tax_setup_mode='codes' WHERE id=?")->execute([$companyId]);
        if ($created) audit_event($user, $companyId, 'tax_code.canada_starter_created', 'company', $companyId, ['created' => $created, 'skipped' => $skipped]);
        if ($own) db()->commit();
        return ['created' => $created, 'skipped' => $skipped];
    } catch (Throwable $e) { if ($own && db()->inTransaction()) db()->rollBack(); throw $e; }
}
