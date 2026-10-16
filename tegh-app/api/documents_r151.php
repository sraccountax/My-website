<?php
declare(strict_types=1);

/*
 * R151: one document form for invoices, credit notes and debit notes on both
 * the customer and the vendor side.
 *
 *  - Vendor invoices can carry several lines, each with its own GL account and
 *    tax (tax code, or GST/HST and PST switches on companies without codes).
 *    Single-amount vendor invoices (imports, recurring profiles, agents) keep
 *    the original one-line path unchanged.
 *  - Credit and debit notes carry a GL account on every line. A note is either
 *    linked to an original document in Tegh (tax follows the original, as
 *    before) or recorded against the customer or vendor with a typed reference
 *    when the original is not in Tegh; such a note computes its own tax per
 *    line and is posted as an open credit (or, for a customer debit note, as a
 *    separate receivable).
 *
 * Schema changes are additive and made on first use, outside any transaction
 * (DDL commits implicitly in MariaDB).
 */

function r151_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        if (!schema_table_exists('bill_lines')) {
            db()->exec("CREATE TABLE IF NOT EXISTS bill_lines (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 company_id VARCHAR(64) NOT NULL,
 bill_id VARCHAR(64) NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 product_service_id VARCHAR(64) NULL,
 description VARCHAR(500) NOT NULL,
 quantity_milli BIGINT NOT NULL,
 foreign_unit_price_cents BIGINT NOT NULL,
 foreign_amount_cents BIGINT NOT NULL,
 foreign_net_cents BIGINT NOT NULL,
 foreign_tax_cents BIGINT NOT NULL DEFAULT 0,
 account_id VARCHAR(64) NOT NULL,
 tax_code_id VARCHAR(64) NULL,
 apply_gst TINYINT(1) NOT NULL DEFAULT 0,
 apply_pst TINYINT(1) NOT NULL DEFAULT 0,
 amount_cents BIGINT NOT NULL,
 gst_cents BIGINT NOT NULL DEFAULT 0,
 pst_cents BIGINT NOT NULL DEFAULT 0,
 tax_cents BIGINT NOT NULL DEFAULT 0,
 cost_tax_cents BIGINT NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY bill_lines_bill_idx (company_id,bill_id,sort_order),
 KEY bill_lines_account_idx (company_id,account_id),
 CONSTRAINT bill_lines_bill_fk FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
 CONSTRAINT bill_lines_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id),
 CONSTRAINT bill_lines_amount_ck CHECK (quantity_milli > 0 AND foreign_unit_price_cents >= 0 AND foreign_amount_cents >= 0 AND foreign_net_cents >= 0 AND foreign_tax_cents >= 0 AND amount_cents >= 0 AND tax_cents >= 0 AND cost_tax_cents >= 0 AND cost_tax_cents <= tax_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (schema_table_exists('accounting_notes')) {
            schema_add_column('accounting_notes', 'reference_number', 'VARCHAR(60) NULL AFTER `source_id`');
            if (!schema_column_nullable('accounting_notes', 'source_id')) db()->exec('ALTER TABLE accounting_notes MODIFY source_id VARCHAR(64) NULL');
        }
        if (function_exists('note_lines_ready') && note_lines_ready()) {
            schema_add_column('accounting_note_lines', 'account_id', 'VARCHAR(64) NULL AFTER `product_service_id`');
            schema_add_column('accounting_note_lines', 'tax_code_id', 'VARCHAR(64) NULL AFTER `account_id`');
            schema_add_column('accounting_note_lines', 'apply_gst', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `tax_code_id`');
            schema_add_column('accounting_note_lines', 'apply_pst', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `apply_gst`');
            schema_add_column('accounting_note_lines', 'foreign_tax_cents', 'BIGINT NOT NULL DEFAULT 0 AFTER `foreign_amount_cents`');
        }
        $ready = schema_table_exists('bill_lines');
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/* ---------- shared line arithmetic ---------- */

/** Quantity in thousandths from a decimal quantity or quantityMilli. */
function r151_quantity_milli(array $line): int
{
    if (isset($line['quantityMilli'])) {
        $q = filter_var($line['quantityMilli'], FILTER_VALIDATE_INT);
        if ($q === false) fail('Each line needs a quantity greater than zero.', 422, 'document_line_quantity');
    } else {
        $raw = $line['quantity'] ?? 1;
        if (!is_numeric($raw)) fail('Each line needs a quantity greater than zero.', 422, 'document_line_quantity');
        $q = (int)round((float)$raw * 1000);
    }
    if ($q <= 0 || $q > 1000000000) fail('Each line needs a quantity greater than zero and no more than 1,000,000.', 422, 'document_line_quantity');
    return $q;
}

function r151_unit_cents(array $line): int
{
    $raw = $line['unitPriceCents'] ?? $line['foreignUnitPriceCents'] ?? null;
    $u = filter_var($raw, FILTER_VALIDATE_INT);
    if ($u === false || $u < 0 || $u > 100000000000) fail('Each line needs a rate of zero or more.', 422, 'document_line_rate');
    return $u;
}

/** The tax a line carries: a tax code (codes mode) or GST/HST + PST switches. */
function r151_line_tax(array $company, array $line, string $side, ?array $defaultCode): array
{
    $companyId = (string)$company['id'];
    if (function_exists('tax_setup_mode') && tax_setup_mode($company) === 'codes') {
        $code = $defaultCode;
        if (array_key_exists('taxCodeId', $line) && $line['taxCodeId'] !== null) {
            $wanted = trim((string)$line['taxCodeId']);
            $code = null;
            if ($wanted !== '') {
                $code = tax_code_get($companyId, $wanted);
                if (!$code) fail('A selected tax code is no longer active. Choose another.', 422, 'tax_code_unavailable');
            }
        }
        if ($code && $side === 'purchase') $code = tax_code_for_purchases($company, $code);
        return ['codes' => true, 'code' => $code, 'gst' => false, 'pst' => false];
    }
    $gst = !empty($line['applyGstHst']);
    $pst = !empty($line['applyPst']);
    if ($gst && !(bool)$company['tax_registered']) fail('GST/HST is not enabled in this company tax setup.', 409, 'gst_hst_not_configured');
    if ($pst && (!(bool)($company['pst_registered'] ?? false) || company_pst_rate_mpct($company) <= 0)) fail('PST is not enabled in this company tax setup.', 409, 'pst_not_configured');
    return ['codes' => false, 'code' => null, 'gst' => $gst, 'pst' => $pst];
}

/**
 * Compute itemized lines in the document currency, then convert to the
 * company currency with exact totals: base net is split by line, base tax by
 * line and component (largest remainder), so every figure adds up to the cent.
 *
 * @param array<int,array> $prepared each: amount (foreign, as entered), tax (from r151_line_tax), plus pass-through fields
 */
function r151_compute_lines(array $company, array $prepared, string $mode, int $rate, string $side): array
{
    $companyId = (string)$company['id'];
    $gstRate = (int)$company['tax_rate_bps'];
    $pstRate = company_pst_rate_mpct($company);
    $pstRecoverable = (bool)($company['pst_recoverable'] ?? false);
    $calc = [];
    foreach ($prepared as $i => $p) {
        $t = $p['tax'];
        if ($t['codes']) {
            $c = $t['code'] ? tax_code_compute($t['code'], $p['amount'], $mode) : ['net' => $p['amount'], 'gross' => $p['amount'], 'tax' => 0, 'parts' => []];
            if (!$t['code'] && $mode === 'inclusive') $c = ['net' => $p['amount'], 'gross' => $p['amount'], 'tax' => 0, 'parts' => []];
            $calc[$i] = ['net' => (int)$c['net'], 'gross' => (int)$c['gross'], 'parts' => array_map('intval', $c['parts'])];
        } else {
            $lineMode = ($t['gst'] || $t['pst']) ? $mode : 'none';
            $c = calculate_tax_components($p['amount'], $lineMode, $t['gst'] ? $gstRate : 0, $t['pst'] ? $pstRate : 0);
            $calc[$i] = ['net' => (int)$c['netCents'], 'gross' => (int)$c['grossCents'], 'parts' => [(int)$c['gstHstCents'], (int)$c['pstCents']]];
        }
    }
    $foreignSubtotal = array_sum(array_column($calc, 'net'));
    $foreignTotal = array_sum(array_column($calc, 'gross'));
    $subtotal = convert_to_base_cents($foreignSubtotal, $rate);
    $total = convert_to_base_cents($foreignTotal, $rate);
    $baseTax = $total - $subtotal;
    if ($baseTax < 0) fail('Tax conversion rounding produced an invalid amount. Review the exchange rate.', 422, 'document_tax_rounding');
    $baseNets = tax_allocate($subtotal, array_map(static fn(array $c): int => $c['net'], $calc));
    $flat = [];
    foreach ($calc as $i => $c) foreach ($c['parts'] as $j => $part) $flat[] = [$i, $j, $part];
    $baseFlat = $flat ? tax_allocate($baseTax, array_map(static fn(array $f): int => max(0, $f[2]), $flat)) : [];
    $baseParts = [];
    foreach ($flat as $k => $f) $baseParts[$f[0]][$f[1]] = (int)($baseFlat[$k] ?? 0);

    $rows = [];
    $lines = [];
    $gstTotal = 0; $pstTotal = 0; $foreignGst = 0; $foreignPst = 0;
    foreach ($prepared as $i => $p) {
        $t = $p['tax'];
        $parts = $baseParts[$i] ?? [];
        $lineTax = array_sum($parts);
        $foreignLineTax = array_sum($calc[$i]['parts']);
        $cost = 0; $lineGst = 0; $linePst = 0;
        if ($t['codes']) {
            if ($t['code']) {
                tax_rows_add($rows, $t['code'], $calc[$i]['parts'], array_values($parts + array_fill(0, count($t['code']['components']), 0)), $baseNets[$i], $side);
                foreach ($t['code']['components'] as $j => $component) {
                    $amount = (int)($parts[$j] ?? 0);
                    $recoverable = $side === 'sales' ? true : ((bool)$component['purchaseRecoverable'] && (string)($component['purchaseAccountId'] ?? '') !== '');
                    if (!$recoverable) $cost += $amount;
                }
            }
        } else {
            $lineGst = (int)($parts[0] ?? 0);
            $linePst = (int)($parts[1] ?? 0);
            $gstTotal += $lineGst; $pstTotal += $linePst;
            $foreignGst += (int)$calc[$i]['parts'][0];
            $foreignPst += (int)$calc[$i]['parts'][1];
            if ($side === 'purchase' && !$pstRecoverable) $cost += $linePst;
        }
        $lines[] = $p + [
            'foreignNetCents' => $calc[$i]['net'],
            'foreignTaxCents' => $foreignLineTax,
            'amountCents' => (int)$baseNets[$i],
            'gstCents' => $lineGst,
            'pstCents' => $linePst,
            'taxCents' => $lineTax,
            'costTaxCents' => $cost,
            'sortOrder' => $i,
        ];
    }
    $rows = array_values($rows);
    if ($rows) {
        [$gstTotal, $pstTotal] = tax_rows_buckets($companyId, $rows);
        $foreignGst = 0; $foreignPst = 0;
        foreach ($rows as $r) {
            [$rowGst] = tax_rows_buckets($companyId, [['accountId' => $r['accountId'], 'taxCents' => 1]]);
            if ($rowGst > 0) $foreignGst += (int)$r['foreignTaxCents']; else $foreignPst += (int)$r['foreignTaxCents'];
        }
    }
    return [
        'lines' => $lines, 'taxRows' => $rows,
        'foreignSubtotal' => $foreignSubtotal, 'foreignTotal' => $foreignTotal, 'foreignTax' => $foreignTotal - $foreignSubtotal,
        'foreignGst' => $foreignGst, 'foreignPst' => $foreignPst,
        'subtotal' => $subtotal, 'total' => $total, 'taxTotal' => $baseTax, 'gst' => $gstTotal, 'pst' => $pstTotal,
    ];
}

/* ---------- vendor invoices with lines ---------- */

function r151_bill_has_lines_input(array $input): bool
{
    return isset($input['lines']) && is_array($input['lines']) && $input['lines'] !== [];
}

/**
 * Same contract as bill_input_values() for an itemized vendor invoice.
 * @return array<string,mixed>
 */
function r151_bill_input_values(array $company, array $input): array
{
    if (!r151_schema_ready()) fail('Itemized vendor invoices are unavailable on this database.', 503, 'bill_lines_unavailable');
    $companyId = (string)$company['id'];
    $vendorId = clean_text($input['vendorId'] ?? '', 'Vendor', 64);
    $stmt = db()->prepare('SELECT id,name,default_expense_account_id,default_currency,default_terms_days FROM vendors WHERE id=? AND company_id=? AND active=1');
    $stmt->execute([$vendorId, $companyId]);
    $vendor = $stmt->fetch();
    if (!$vendor) fail('Choose an active vendor.');
    $number = clean_text($input['number'] ?? '', 'Vendor invoice number', 60);
    $billDate = safe_date($input['billDate'] ?? '', 'Vendor invoice date');
    assert_not_future_date($billDate, 'Vendor invoice date');
    assert_vendor_invoice_on_or_after_books_start($company, $billDate);
    $termsDays = filter_var($input['paymentTermsDays'] ?? $vendor['default_terms_days'], FILTER_VALIDATE_INT);
    if ($termsDays === false || $termsDays < 0 || $termsDays > 3650) fail('Payment terms must be between 0 and 3,650 days.');
    $dueDate = trim((string)($input['dueDate'] ?? ''));
    $dueDate = $dueDate === '' ? (new DateTimeImmutable($billDate))->modify('+' . $termsDays . ' days')->format('Y-m-d') : safe_date($dueDate, 'Due date');
    if ($dueDate < $billDate) fail('Due date cannot be before the vendor invoice date.');
    $currency = safe_currency_code($input['currency'] ?? $vendor['default_currency'] ?? $company['currency']);
    $currencyRow = company_currency($companyId, $currency);
    if (!$currencyRow) fail('Add that currency to the company before using it on a vendor invoice.');
    $rate = safe_exchange_rate_micros($input['exchangeRateMicros'] ?? $currencyRow['rate_to_base_micros'], $currency, (string)$company['currency']);
    $mode = (string)($input['taxEntryMode'] ?? 'exclusive');
    if (!in_array($mode, ['exclusive', 'inclusive'], true)) fail('Choose whether the line amounts are before or including tax.', 422, 'bill_tax_entry_mode_invalid');
    $raw = $input['lines'];
    if (!is_array($raw) || !array_is_list($raw) || count($raw) < 1 || count($raw) > 100) fail('Send between 1 and 100 vendor invoice lines.', 422, 'bill_lines_invalid');
    $codesMode = function_exists('tax_setup_mode') && tax_setup_mode($company) === 'codes';
    $defaultCode = $codesMode ? tax_code_home($company) : null;
    $prepared = [];
    $productStmt = db()->prepare('SELECT id,kind,name FROM products_services WHERE id=? AND company_id=? AND active=1');
    foreach ($raw as $line) {
        if (!is_array($line)) fail('Each vendor invoice line must be an object.', 422, 'bill_lines_invalid');
        $description = trim((string)($line['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 500) fail('Each line needs a description of up to 500 characters.', 422, 'document_line_description');
        $qty = r151_quantity_milli($line);
        $unit = r151_unit_cents($line);
        $amount = (int)round($qty * $unit / 1000);
        $productId = trim((string)($line['productServiceId'] ?? ''));
        if ($productId !== '') {
            $productId = clean_text($productId, 'Product or service', 64);
            $productStmt->execute([$productId, $companyId]);
            if (!$productStmt->fetch()) fail('Choose an active product or service from this company.', 422, 'document_line_product');
        } else $productId = null;
        $accountId = clean_text($line['accountId'] ?? $vendor['default_expense_account_id'] ?? '', 'GL account', 64);
        $account = company_account($companyId, $accountId);
        if (!$account || !in_array((string)$account['account_type'], ['expense', 'asset'], true) || (bool)$account['is_control']) fail('Each line needs a valid non-control expense or asset GL account.', 422, 'document_line_account');
        $prepared[] = ['description' => $description, 'quantityMilli' => $qty, 'foreignUnitPriceCents' => $unit, 'amount' => $amount, 'productServiceId' => $productId, 'accountId' => $accountId, 'accountName' => (string)$account['name'],
            'tax' => r151_line_tax($company, $line, 'purchase', $defaultCode)];
    }
    if (array_sum(array_column($prepared, 'amount')) <= 0) fail('Vendor invoice lines must add up to more than zero.', 422, 'bill_amount_invalid');
    $v = r151_compute_lines($company, $prepared, $mode, $rate, 'purchase');
    // The header keeps the largest line's account and the first line's item so
    // registers, approvals and older reports have a representative value.
    $main = $v['lines'][0];
    foreach ($v['lines'] as $l) if ($l['amountCents'] > $main['amountCents']) $main = $l;
    $first = $v['lines'][0];
    return [
        'vendorId' => $vendorId, 'vendor' => $vendor, 'productServiceId' => $first['productServiceId'], 'productService' => null, 'quantityMilli' => count($v['lines']) === 1 ? $first['quantityMilli'] : 1000,
        'number' => $number, 'billDate' => $billDate, 'termsDays' => $termsDays, 'dueDate' => $dueDate,
        'categoryId' => $main['accountId'], 'category' => ['name' => $main['accountName']],
        'currency' => $currency, 'rate' => $rate, 'mode' => $mode, 'gstRate' => 0, 'pstRate' => 0,
        'foreignSubtotal' => $v['foreignSubtotal'], 'foreignGst' => $v['foreignGst'], 'foreignPst' => $v['foreignPst'], 'foreignTotal' => $v['foreignTotal'],
        'subtotal' => $v['subtotal'], 'gst' => $v['gst'], 'pst' => $v['pst'], 'taxTotal' => $v['taxTotal'], 'total' => $v['total'], 'fxRounding' => 0,
        'taxRows' => $v['taxRows'], 'codesMode' => $codesMode, 'taxCodeId' => $codesMode ? ($first['tax']['code']['id'] ?? null) : null,
        'memo' => optional_text($input['memo'] ?? null, 500) ?? '',
        'r151Lines' => $v['lines'],
    ];
}

function r151_bill_store_lines(string $companyId, string $billId, array $lines): void
{
    db()->prepare('DELETE FROM bill_lines WHERE company_id=? AND bill_id=?')->execute([$companyId, $billId]);
    $s = db()->prepare('INSERT INTO bill_lines(id,company_id,bill_id,sort_order,product_service_id,description,quantity_milli,foreign_unit_price_cents,foreign_amount_cents,foreign_net_cents,foreign_tax_cents,account_id,tax_code_id,apply_gst,apply_pst,amount_cents,gst_cents,pst_cents,tax_cents,cost_tax_cents) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($lines as $l) {
        $s->execute([new_id('bline'), $companyId, $billId, $l['sortOrder'], $l['productServiceId'], $l['description'], $l['quantityMilli'], $l['foreignUnitPriceCents'], $l['amount'], $l['foreignNetCents'], $l['foreignTaxCents'],
            $l['accountId'], $l['tax']['codes'] ? ($l['tax']['code']['id'] ?? null) : null, $l['tax']['gst'] ? 1 : 0, $l['tax']['pst'] ? 1 : 0, $l['amountCents'], $l['gstCents'], $l['pstCents'], $l['taxCents'], $l['costTaxCents']]);
    }
}

function r151_bill_saved_lines(string $companyId, string $billId): array
{
    if (!schema_table_exists('bill_lines')) return [];
    $q = db()->prepare('SELECT * FROM bill_lines WHERE company_id=? AND bill_id=? ORDER BY sort_order,id');
    $q->execute([$companyId, $billId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Debit lines (one per GL account) for an itemized vendor invoice. */
function r151_bill_account_debits(array $lines, string $memo): array
{
    $by = [];
    foreach ($lines as $l) {
        $account = (string)($l['account_id'] ?? $l['accountId']);
        $by[$account] = ($by[$account] ?? 0) + (int)($l['amount_cents'] ?? $l['amountCents']) + (int)($l['cost_tax_cents'] ?? $l['costTaxCents']);
    }
    $out = [];
    foreach ($by as $account => $amount) if ($amount !== 0) $out[] = ['accountId' => (string)$account, 'debitCents' => $amount, 'creditCents' => 0, 'memo' => $memo];
    return $out;
}

/** Recoverable tax debits for an itemized vendor invoice. */
function r151_bill_tax_debits(string $companyId, array $company, array $lines, array $taxRows): array
{
    if ($taxRows) return tax_rows_purchase_lines($companyId, $taxRows)['lines'];
    $gst = 0; $pst = 0;
    foreach ($lines as $l) { $gst += (int)($l['gst_cents'] ?? $l['gstCents']); $pst += (int)($l['pst_cents'] ?? $l['pstCents']); }
    $out = [];
    if ($gst > 0) $out[] = ['accountId' => account_by_code($companyId, '1100'), 'debitCents' => $gst, 'creditCents' => 0, 'memo' => 'GST/HST recoverable'];
    if ($pst > 0 && (bool)($company['pst_recoverable'] ?? false)) $out[] = ['accountId' => account_by_code($companyId, '1110'), 'debitCents' => $pst, 'creditCents' => 0, 'memo' => 'PST recoverable'];
    return $out;
}

/** Accrual posting for an itemized vendor invoice: each line to its account, recoverable tax, payable. */
function r151_bill_posting_lines(string $companyId, array $company, array $lines, array $taxRows, int $total): array
{
    $out = array_merge(r151_bill_account_debits($lines, 'Vendor invoice lines'), r151_bill_tax_debits($companyId, $company, $lines, $taxRows));
    $out[] = ['accountId' => account_by_code($companyId, '2050'), 'debitCents' => 0, 'creditCents' => $total, 'memo' => 'Accounts payable'];
    return $out;
}

/** Posting lines for a saved bill when it is itemized; null for single-line bills. */
function r151_bill_saved_posting_lines(string $companyId, array $company, array $bill): ?array
{
    $lines = r151_bill_saved_lines($companyId, (string)$bill['id']);
    if (!$lines) return null;
    $rows = function_exists('document_tax_rows_get') ? document_tax_rows_get($companyId, 'bill', (string)$bill['id']) : [];
    return r151_bill_posting_lines($companyId, $company, $lines, $rows, (int)$bill['total_cents']);
}

/**
 * Cash-basis recognition of an itemized bill for an amount paid or applied:
 * the amount is spread over the bill's account and recoverable-tax debits in
 * proportion to their share of the bill (exact to the cent). Null for single-line bills.
 */
function r151_bill_cash_lines(string $companyId, ?array $company, array $bill, int $amount, string $memo): ?array
{
    $lines = r151_bill_saved_lines($companyId, (string)$bill['id']);
    if (!$lines) return null;
    if ($company === null) {
        $q = db()->prepare('SELECT id,pst_recoverable FROM companies WHERE id=?');
        $q->execute([$companyId]);
        $company = $q->fetch() ?: ['id' => $companyId];
    }
    $rows = function_exists('document_tax_rows_get') ? document_tax_rows_get($companyId, 'bill', (string)$bill['id']) : [];
    $buckets = array_merge(r151_bill_account_debits($lines, $memo), r151_bill_tax_debits($companyId, $company, $lines, $rows));
    $shares = tax_allocate($amount, array_map(static fn(array $b): int => (int)$b['debitCents'], $buckets));
    $out = [];
    foreach ($buckets as $i => $b) if ((int)$shares[$i] > 0) $out[] = ['accountId' => $b['accountId'], 'debitCents' => (int)$shares[$i], 'creditCents' => 0, 'memo' => $b['memo']];
    return $out;
}

function r151_bill_lines_public(array $rows): array
{
    return array_map(static fn(array $l): array => [
        'productServiceId' => $l['product_service_id'], 'description' => $l['description'], 'quantityMilli' => (int)$l['quantity_milli'],
        'foreignUnitPriceCents' => (int)$l['foreign_unit_price_cents'], 'foreignAmountCents' => (int)$l['foreign_amount_cents'], 'foreignNetCents' => (int)$l['foreign_net_cents'],
        'foreignTaxCents' => (int)$l['foreign_tax_cents'], 'accountId' => $l['account_id'], 'taxCodeId' => $l['tax_code_id'],
        'applyGstHst' => (bool)$l['apply_gst'], 'applyPst' => (bool)$l['apply_pst'], 'amountCents' => (int)$l['amount_cents'], 'taxCents' => (int)$l['tax_cents'],
    ], $rows);
}

/** Lines for every itemized bill of a company, keyed by bill id (workspace payload). */
function r151_bill_lines_by_bill(string $companyId): array
{
    if (!schema_table_exists('bill_lines')) return [];
    $q = db()->prepare('SELECT * FROM bill_lines WHERE company_id=? ORDER BY bill_id,sort_order,id');
    $q->execute([$companyId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(string)$row['bill_id']][] = $row;
    return array_map('r151_bill_lines_public', $out);
}

/** R151: due date and memo of an issued vendor invoice can change without touching amounts. */
function r151_bill_update_details(array $user, array $company, string $billId, array $input): array
{
    $companyId = (string)$company['id'];
    $allowed = ['action', 'billId', 'dueDate', 'memo'];
    if (array_diff(array_keys($input), $allowed)) fail('Issued amounts, vendor, number and date cannot be changed here. Void the vendor invoice or enter a note.', 422, 'bill_edit_fields_forbidden');
    db()->beginTransaction();
    try {
        $q = db()->prepare('SELECT id,number,status,bill_date,due_date,memo FROM bills WHERE id=? AND company_id=? FOR UPDATE');
        $q->execute([$billId, $companyId]);
        $bill = $q->fetch();
        if (!$bill) fail('Vendor invoice not found.', 404, 'bill_not_found');
        if (!in_array((string)$bill['status'], ['open', 'paid', 'submitted_for_approval', 'approved'], true)) fail('Only an issued vendor invoice uses this edit. Edit drafts in full.', 409, 'bill_details_edit_unavailable');
        $due = safe_date($input['dueDate'] ?? $bill['due_date'], 'Due date');
        if ($due < (string)$bill['bill_date']) fail('Due date cannot be before the vendor invoice date.');
        $memo = array_key_exists('memo', $input) ? (optional_text($input['memo'], 500) ?? '') : (string)$bill['memo'];
        if ((string)$bill['status'] === 'submitted_for_approval' || (string)$bill['status'] === 'approved') {
            if ($memo !== (string)$bill['memo']) fail('The memo is part of the approval. Return the vendor invoice to draft to change it.', 409, 'bill_approval_memo_locked');
        }
        db()->prepare('UPDATE bills SET due_date=?,memo=? WHERE id=? AND company_id=?')->execute([$due, $memo, $billId, $companyId]);
        audit_event($user, $companyId, 'bill.details_updated', 'bill', $billId, ['number' => (string)$bill['number'], 'before' => ['dueDate' => (string)$bill['due_date'], 'memo' => (string)$bill['memo']], 'after' => ['dueDate' => $due, 'memo' => $memo]]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return ['id' => $billId, 'status' => (string)$bill['status'], 'dueDate' => $due];
}

/* ---------- credit and debit notes ---------- */

function r151_note_side_account_ok(array $account, bool $customer): bool
{
    if ((bool)$account['is_control']) return false;
    return $customer ? in_array((string)$account['account_type'], ['income', 'expense'], true) : in_array((string)$account['account_type'], ['expense', 'asset'], true);
}

/**
 * Validate the GL accounts sent on note lines. Returns accountId per line
 * (null when not sent). Unlinked notes must send one on every line.
 */
function r151_note_line_accounts(string $companyId, bool $customer, array $raw, bool $required): array
{
    $out = [];
    foreach ($raw as $line) {
        $id = trim((string)($line['accountId'] ?? ''));
        if ($id === '') {
            if ($required) fail('Each line needs a GL account.', 422, 'document_line_account');
            $out[] = null;
            continue;
        }
        $account = company_account($companyId, clean_text($id, 'GL account', 64));
        if (!$account || !r151_note_side_account_ok($account, $customer)) fail($customer ? 'Each line needs a non-control income (or contra) GL account.' : 'Each line needs a non-control expense or asset GL account.', 422, 'document_line_account');
        $out[] = (string)$account['id'];
    }
    return $out;
}

/** A linked note's base net split over its lines' GL accounts, or null when any line has none. */
function r151_note_account_split(string $companyId, string $noteId, int $net): ?array
{
    if (!schema_column_exists('accounting_note_lines', 'account_id')) return null;
    $q = db()->prepare('SELECT account_id,foreign_amount_cents FROM accounting_note_lines WHERE company_id=? AND note_id=? ORDER BY sort_order,id');
    $q->execute([$companyId, $noteId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;
    foreach ($rows as $r) if ((string)($r['account_id'] ?? '') === '') return null;
    $shares = tax_allocate($net, array_map(static fn(array $r): int => (int)$r['foreign_amount_cents'], $rows));
    $by = [];
    foreach ($rows as $i => $r) $by[(string)$r['account_id']] = ($by[(string)$r['account_id']] ?? 0) + (int)$shares[$i];
    return array_filter($by, static fn(int $v): bool => $v !== 0);
}

/**
 * Prepare a note that is not linked to an original in Tegh. Tax and accounts
 * come from its own lines; the typed reference is kept with the note.
 */
function r151_note_prepare_unlinked(array $company, array $input): array
{
    if (!r151_schema_ready()) fail('Notes without an original document are unavailable on this database.', 503, 'note_unlinked_unavailable');
    $companyId = (string)$company['id'];
    $kind = clean_text($input['kind'] ?? '', 'Note kind', 32);
    $spec = note_kind_spec($kind);
    require_company_permission($company, $spec['permission']);
    if ((string)$company['accounting_basis'] !== 'accrual') fail('Notes currently require accrual accounting. Cash-basis note settlement is not enabled.', 409, 'note_cash_basis_unavailable');
    $customer = $spec['source'] === 'invoice';
    $partyId = clean_text($input['partyId'] ?? '', $customer ? 'Customer' : 'Vendor', 64);
    $q = db()->prepare($customer ? 'SELECT id,name,province,country FROM customers WHERE id=? AND company_id=? AND active=1' : 'SELECT id,name FROM vendors WHERE id=? AND company_id=? AND active=1');
    $q->execute([$partyId, $companyId]);
    $party = $q->fetch();
    if (!$party) fail($customer ? 'Choose an active customer.' : 'Choose an active vendor.', 422, 'note_party_invalid');
    $reference = trim((string)($input['referenceNumber'] ?? ''));
    if ($reference === '' || mb_strlen($reference) > 60) fail('Enter the original invoice number (up to 60 characters).', 422, 'note_reference_required');
    if (preg_match('/[\x00-\x1f\x7f<>]/u', $reference)) fail('The invoice number contains characters that are not allowed.', 422, 'note_reference_invalid');
    $date = safe_date($input['date'] ?? '', 'Note date');
    assert_not_future_date($date, 'Note date');
    assert_period_open($companyId, $date);
    $currency = safe_currency_code($input['currency'] ?? $company['currency']);
    $currencyRow = company_currency($companyId, $currency);
    if (!$currencyRow) fail('Add that currency to the company before using it on a note.', 422, 'note_currency_invalid');
    $rate = safe_exchange_rate_micros($input['exchangeRateMicros'] ?? $currencyRow['rate_to_base_micros'], $currency, (string)$company['currency']);
    $raw = $input['lines'] ?? null;
    if (!is_array($raw) || !array_is_list($raw) || count($raw) < 1 || count($raw) > 100) fail('Add between 1 and 100 note lines.', 422, 'note_lines_invalid');
    $accounts = r151_note_line_accounts($companyId, $customer, $raw, true);
    $codesMode = function_exists('tax_setup_mode') && tax_setup_mode($company) === 'codes';
    $defaultCode = null;
    if ($codesMode) $defaultCode = $customer ? tax_code_auto_region($company, $companyId, (string)($party['province'] ?? ''), $party) : tax_code_home($company);
    $prepared = [];
    foreach ($raw as $i => $line) {
        if (!is_array($line)) fail('Each note line must be an object.', 422, 'note_lines_invalid');
        $description = trim((string)($line['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 500) fail('Each note line needs a description of up to 500 characters.', 422, 'note_line_description');
        $qty = r151_quantity_milli($line);
        $unit = r151_unit_cents($line);
        $product = trim((string)($line['productServiceId'] ?? '')) ?: null;
        $prepared[] = ['description' => $description, 'quantityMilli' => $qty, 'foreignUnitPriceCents' => $unit, 'amount' => (int)round($qty * $unit / 1000),
            'productServiceId' => $product, 'accountId' => $accounts[$i], 'sourceLineId' => null, 'tax' => r151_line_tax($company, $line, $customer ? 'sales' : 'purchase', $defaultCode)];
    }
    $v = r151_compute_lines($company, $prepared, 'exclusive', $rate, $customer ? 'sales' : 'purchase');
    if ($v['foreignSubtotal'] <= 0) fail('The note subtotal must be positive.', 422, 'note_amount_invalid');
    if ($v['subtotal'] <= 0) fail('The note subtotal rounds to zero in the company currency.', 422, 'note_amount_too_small');
    if ($v['total'] > company_materiality_threshold_cents($companyId)) fail('This material note requires a separate approval workflow before posting.', 409, 'material_note_review_required');
    return ['kind' => $kind, 'spec' => $spec, 'customer' => $customer, 'party' => $party, 'partyId' => $partyId, 'reference' => $reference, 'date' => $date, 'currency' => $currency, 'rate' => $rate,
        'net' => $v['foreignSubtotal'], 'tax' => $v['foreignTax'], 'foreignTotal' => $v['foreignTotal'], 'baseNet' => $v['subtotal'], 'baseTax' => $v['taxTotal'], 'total' => $v['total'],
        'gst' => $v['gst'], 'pst' => $v['pst'], 'taxRows' => $v['taxRows'], 'lines' => $v['lines'], 'memo' => optional_text($input['memo'] ?? null, 500) ?? ''];
}

function r151_note_store_lines(string $companyId, string $noteId, array $lines, array $accounts = []): void
{
    $s = db()->prepare('INSERT INTO accounting_note_lines(id,company_id,note_id,source_line_id,product_service_id,account_id,tax_code_id,apply_gst,apply_pst,description,quantity_milli,foreign_unit_price_cents,foreign_amount_cents,foreign_tax_cents,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach (array_values($lines) as $i => $l) {
        $tax = $l['tax'] ?? null;
        $s->execute([new_id('nline'), $companyId, $noteId, $l['sourceLineId'] ?? null, $l['productServiceId'] ?? null, $l['accountId'] ?? ($accounts[$i] ?? null),
            $tax && $tax['codes'] ? ($tax['code']['id'] ?? null) : null, $tax && !$tax['codes'] && $tax['gst'] ? 1 : 0, $tax && !$tax['codes'] && $tax['pst'] ? 1 : 0,
            $l['description'], $l['quantityMilli'], $l['foreignUnitPriceCents'], $l['foreignAmountCents'] ?? $l['amount'], (int)($l['foreignTaxCents'] ?? 0), $l['sortOrder'] ?? $i]);
    }
}

/** Journal lines for an unlinked note from its stored lines and tax rows. */
function r151_note_unlinked_posting_lines(string $companyId, array $company, array $note): array
{
    $kind = (string)$note['note_kind'];
    $customer = in_array($kind, ['customer_credit', 'customer_debit'], true);
    $increase = $kind === 'customer_debit';
    $q = db()->prepare('SELECT account_id,foreign_amount_cents,apply_gst,apply_pst,tax_code_id FROM accounting_note_lines WHERE company_id=? AND note_id=? ORDER BY sort_order,id');
    $q->execute([$companyId, (string)$note['id']]);
    $lines = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) fail('This note has no lines to post.', 409, 'note_lines_missing');
    $net = (int)$note['subtotal_cents'];
    $shares = tax_allocate($net, array_map(static fn(array $l): int => (int)$l['foreign_amount_cents'], $lines));
    $rows = function_exists('document_tax_rows_get') ? document_tax_rows_get($companyId, 'accounting_note', (string)$note['id']) : [];
    $control = note_control_account_id($companyId, $customer);
    $total = (int)$note['total_cents'];
    $tax = (int)$note['tax_cents'];
    $out = [];
    if ($customer) {
        // Credit note: Dr revenue, Dr tax, Cr AR. Debit note: the reverse.
        $out[] = ['accountId' => $control, 'debitCents' => $increase ? $total : 0, 'creditCents' => $increase ? 0 : $total, 'memo' => 'Accounts receivable'];
        $by = [];
        foreach ($lines as $i => $l) $by[(string)$l['account_id']] = ($by[(string)$l['account_id']] ?? 0) + (int)$shares[$i];
        foreach ($by as $account => $amount) if ($amount !== 0) $out[] = ['accountId' => (string)$account, 'debitCents' => $increase ? 0 : $amount, 'creditCents' => $increase ? $amount : 0, 'memo' => $increase ? 'Debit note' : 'Credit note'];
        if ($rows) $out = array_merge($out, tax_rows_sales_lines($companyId, $rows, !$increase));
        else {
            $gst = (int)($note['r151_gst'] ?? 0); $pst = $tax - $gst;
            if ($gst > 0) $out[] = ['accountId' => account_by_code($companyId, '2100'), 'debitCents' => $increase ? 0 : $gst, 'creditCents' => $increase ? $gst : 0, 'memo' => 'GST/HST adjustment'];
            if ($pst > 0) $out[] = ['accountId' => account_by_code($companyId, '2110'), 'debitCents' => $increase ? 0 : $pst, 'creditCents' => $increase ? $pst : 0, 'memo' => 'PST adjustment'];
        }
        return $out;
    }
    // Vendor credit or debit note: both reduce what is owed. Dr AP; Cr each
    // line's account (plus tax that is part of cost); Cr recoverable tax.
    $out[] = ['accountId' => $control, 'debitCents' => $total, 'creditCents' => 0, 'memo' => 'Accounts payable'];
    $recoverable = [];
    $costTax = 0;
    if ($rows) {
        $p = tax_rows_purchase_lines($companyId, $rows, true);
        $recoverable = $p['lines'];
        $costTax = (int)$p['costCents'];
    } else {
        $gst = (int)($note['r151_gst'] ?? 0); $pst = $tax - $gst;
        if ($gst > 0) $recoverable[] = ['accountId' => account_by_code($companyId, '1100'), 'debitCents' => 0, 'creditCents' => $gst, 'memo' => 'GST/HST recoverable adjustment'];
        if ($pst > 0 && (bool)($company['pst_recoverable'] ?? false)) $recoverable[] = ['accountId' => account_by_code($companyId, '1110'), 'debitCents' => 0, 'creditCents' => $pst, 'memo' => 'PST recoverable adjustment'];
        elseif ($pst > 0) $costTax = $pst;
    }
    $costShares = tax_allocate($net + $costTax, array_map(static fn(array $l): int => (int)$l['foreign_amount_cents'], $lines));
    $by = [];
    foreach ($lines as $i => $l) $by[(string)$l['account_id']] = ($by[(string)$l['account_id']] ?? 0) + (int)$costShares[$i];
    foreach ($by as $account => $amount) if ($amount !== 0) $out[] = ['accountId' => (string)$account, 'debitCents' => 0, 'creditCents' => $amount, 'memo' => 'Vendor note cost adjustment'];
    return array_merge($out, $recoverable);
}

/** Post an unlinked note: an open credit on the party, or a customer debit note's own receivable. */
function r151_note_issue_unlinked(array $user, array $company, array $note): array
{
    $companyId = (string)$company['id'];
    $spec = note_kind_spec((string)$note['note_kind']);
    require_company_permission($company, $spec['permission']);
    if ((string)$note['status'] !== 'draft') fail('Only a draft note can be posted.', 409, 'note_already_posted');
    if ((string)$company['accounting_basis'] !== 'accrual') fail('Cash-basis note settlement is not enabled.', 409, 'note_cash_basis_unavailable');
    assert_not_future_date((string)$note['note_date'], 'Note date');
    assert_period_open($companyId, (string)$note['note_date']);
    if ((int)$note['total_cents'] > company_materiality_threshold_cents($companyId)) fail('This material note requires a separate approval workflow before posting.', 409, 'material_note_review_required');
    $increase = $spec['direction'] === 'increase';
    $gst = 0;
    if (!(function_exists('document_tax_rows_get') && document_tax_rows_get($companyId, 'accounting_note', (string)$note['id']))) $gst = r151_note_legacy_gst($companyId, $company, $note);
    $note['r151_gst'] = $gst;
    $lines = r151_note_unlinked_posting_lines($companyId, $company, $note);
    $journal = add_journal_entry($user, $companyId, (string)$note['note_date'], 'accounting_note', (string)$note['id'], ucwords(str_replace('_', ' ', (string)$note['note_kind'])) . ' ' . (string)$note['number'] . ' · Ref ' . (string)$note['reference_number'], $lines);
    $debitDocumentId = null;
    if ($increase) {
        // A customer debit note is a separate receivable the customer pays like an invoice.
        $debitDocumentId = new_id('invoice');
        $tax = (int)$note['tax_cents'];
        $rows = function_exists('document_tax_rows_get') ? document_tax_rows_get($companyId, 'accounting_note', (string)$note['id']) : [];
        $docGst = $rows ? tax_rows_buckets($companyId, $rows)[0] : $gst;
        $snapshot = null;
        if (function_exists('invoice_customer_snapshot')) {
            $cq = db()->prepare('SELECT id,name,email,phone,billing_address,province,country FROM customers WHERE id=? AND company_id=?');
            $cq->execute([(string)$note['party_id'], $companyId]);
            if ($row = $cq->fetch()) $snapshot = json_encode(invoice_customer_snapshot($row), JSON_THROW_ON_ERROR);
        }
        db()->prepare("INSERT INTO invoices(id,company_id,customer_id,number,issue_date,due_date,status,subtotal_cents,gst_hst_cents,pst_cents,tax_cents,tax_entry_mode,total_cents,balance_cents,message,currency,exchange_rate_micros,foreign_subtotal_cents,foreign_tax_cents,foreign_total_cents,foreign_balance_cents,issued_journal_entry_id,customer_snapshot_json) VALUES(?,?,?,?,?,?,'sent',?,?,?,?,'exclusive',?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$debitDocumentId, $companyId, (string)$note['party_id'], $note['number'], $note['note_date'], $note['note_date'], (int)$note['subtotal_cents'], $docGst, $tax - $docGst, $tax, (int)$note['total_cents'], (int)$note['total_cents'],
                'Debit note · Ref ' . (string)$note['reference_number'] . ((string)$note['memo'] !== '' ? ' · ' . (string)$note['memo'] : ''), $note['currency'], $note['exchange_rate_micros'], $note['foreign_subtotal_cents'], $note['foreign_tax_cents'], $note['foreign_total_cents'], $note['foreign_total_cents'], $journal, $snapshot]);
        if ($rows) document_tax_rows_save($companyId, 'invoice', $debitDocumentId, $rows);
        $q = db()->prepare('SELECT account_id,description,quantity_milli,foreign_unit_price_cents,foreign_amount_cents,foreign_tax_cents,product_service_id,sort_order FROM accounting_note_lines WHERE company_id=? AND note_id=? ORDER BY sort_order,id');
        $q->execute([$companyId, (string)$note['id']]);
        $noteLines = $q->fetchAll(PDO::FETCH_ASSOC);
        $baseNets = tax_allocate((int)$note['subtotal_cents'], array_map(static fn(array $l): int => (int)$l['foreign_amount_cents'], $noteLines));
        $baseTaxes = tax_allocate($tax, array_map(static fn(array $l): int => (int)$l['foreign_tax_cents'], $noteLines));
        $ins = db()->prepare('INSERT INTO invoice_lines(id,invoice_id,product_service_id,income_account_id,description,quantity_milli,unit_price_cents,tax_rate_bps,amount_cents,tax_cents,foreign_unit_price_cents,foreign_amount_cents,foreign_tax_cents,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($noteLines as $i => $l) {
            $amount = (int)$l['foreign_amount_cents'];
            $rateBps = $amount > 0 ? (int)round(10000 * (int)$l['foreign_tax_cents'] / $amount) : 0;
            $ins->execute([new_id('iline'), $debitDocumentId, $l['product_service_id'], $l['account_id'], $l['description'], (int)$l['quantity_milli'], (int)round((int)$baseNets[$i] * 1000 / max(1, (int)$l['quantity_milli'])), $rateBps, (int)$baseNets[$i], (int)$baseTaxes[$i], (int)$l['foreign_unit_price_cents'], $amount, (int)$l['foreign_tax_cents'], (int)$l['sort_order']]);
        }
    }
    db()->prepare("UPDATE accounting_notes SET status='posted',journal_entry_id=?,applied_cents=0,debit_document_id=? WHERE id=? AND company_id=? AND status='draft'")->execute([$journal, $debitDocumentId, $note['id'], $companyId]);
    if (function_exists('voucher_mark_posted')) voucher_mark_posted($user, $companyId, 'accounting_note', (string)$note['id'], $journal);
    audit_event($user, $companyId, 'accounting_note.posted', 'accounting_note', (string)$note['id'], ['kind' => $note['note_kind'], 'number' => $note['number'], 'referenceNumber' => $note['reference_number'], 'journalEntryId' => $journal, 'debitDocumentId' => $debitDocumentId, 'settlementMode' => $increase ? 'receivable' : 'open', 'appliedCents' => 0]);
    return ['id' => (string)$note['id'], 'status' => 'posted', 'journalEntryId' => $journal, 'debitDocumentId' => $debitDocumentId, 'appliedCents' => 0];
}

/** Base GST/HST of an unlinked note on a company without tax codes (from its lines). */
function r151_note_legacy_gst(string $companyId, array $company, array $note): int
{
    $q = db()->prepare('SELECT apply_gst,apply_pst,foreign_amount_cents FROM accounting_note_lines WHERE company_id=? AND note_id=? ORDER BY sort_order,id');
    $q->execute([$companyId, (string)$note['id']]);
    $foreignGst = 0; $foreignTax = 0;
    $gstRate = (int)$company['tax_rate_bps']; $pstRate = company_pst_rate_mpct($company);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $c = calculate_tax_components((int)$l['foreign_amount_cents'], ($l['apply_gst'] || $l['apply_pst']) ? 'exclusive' : 'none', $l['apply_gst'] ? $gstRate : 0, $l['apply_pst'] ? $pstRate : 0);
        $foreignGst += (int)$c['gstHstCents']; $foreignTax += (int)$c['taxCents'];
    }
    $tax = (int)$note['tax_cents'];
    return $foreignTax > 0 ? (int)round($tax * $foreignGst / $foreignTax) : 0;
}

/** Create a draft unlinked note (inside the caller's transaction). */
function r151_note_create_unlinked(array $user, array $company, array $input, string $operationKey, string $payloadHash): array
{
    $companyId = (string)$company['id'];
    $v = r151_note_prepare_unlinked($company, $input);
    $number = note_reserve_number($companyId, $v['kind'], $v['spec']['prefix']);
    $id = new_id('note');
    db()->prepare("INSERT INTO accounting_notes(id,company_id,source_type,source_id,reference_number,party_id,note_kind,number,note_date,status,currency,exchange_rate_micros,foreign_subtotal_cents,foreign_tax_cents,foreign_total_cents,subtotal_cents,tax_cents,total_cents,memo,operation_key,payload_hash,created_by) VALUES(?,?,?,NULL,?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$id, $companyId, $v['spec']['source'], $v['reference'], $v['partyId'], $v['kind'], $number, $v['date'], $v['currency'], $v['rate'], $v['net'], $v['tax'], $v['foreignTotal'], $v['baseNet'], $v['baseTax'], $v['total'], $v['memo'], $operationKey, $payloadHash, $user['id']]);
    r151_note_store_lines($companyId, $id, array_map(static fn(array $l): array => $l + ['foreignAmountCents' => $l['amount']], $v['lines']));
    if ($v['taxRows']) document_tax_rows_save($companyId, 'accounting_note', $id, $v['taxRows']);
    if (function_exists('voucher_register_saved')) voucher_register_saved($user, $companyId, $v['spec']['prefix'], $v['customer'] ? 'AR' : 'AP', 'accounting_note', $id, $v['date'], $number . ' · Ref ' . $v['reference'], $v['total'], null, false);
    audit_event($user, $companyId, 'accounting_note.created', 'accounting_note', $id, ['kind' => $v['kind'], 'number' => $number, 'referenceNumber' => $v['reference'], 'linked' => false]);
    return ['id' => $id, 'number' => $number, 'status' => 'draft'];
}

/**
 * Replace a draft note's contents (R151 edit on the main screen). The draft is
 * rebuilt from the same rules as a new note; its number and creator stay.
 */
function r151_note_update_draft(array $user, array $company, array $note, array $input): array
{
    $companyId = (string)$company['id'];
    if ((string)$note['status'] !== 'draft') fail('Only a draft note can be edited. Void a posted note and enter a new one.', 409, 'note_edit_unavailable');
    if ((string)($input['kind'] ?? '') !== (string)$note['note_kind']) fail('A note’s type cannot change. Void the draft and create the other type.', 422, 'note_kind_locked');
    $linked = trim((string)($input['sourceId'] ?? '')) !== '';
    if ($linked) {
        $values = note_prepare($company, $input);
        $raw = $input['lines'] ?? [];
        $lines = note_prepare_lines($companyId, $values, $raw);
        $accounts = r151_note_line_accounts($companyId, $values['spec']['source'] === 'invoice', is_array($raw) ? $raw : [], false);
        $source = $values['source'];
        db()->prepare('UPDATE accounting_notes SET source_id=?,reference_number=NULL,party_id=?,note_date=?,currency=?,exchange_rate_micros=?,foreign_subtotal_cents=?,foreign_tax_cents=?,foreign_total_cents=?,subtotal_cents=?,tax_cents=?,total_cents=?,memo=? WHERE id=? AND company_id=? AND status=\'draft\'')
            ->execute([$source['id'], $source[$values['spec']['source'] === 'invoice' ? 'customer_id' : 'vendor_id'], $values['date'], $source['currency'], $values['rate'], $values['net'], $values['tax'], $values['foreignTotal'], $values['baseNet'], $values['baseTax'], $values['total'], $values['memo'], $note['id'], $companyId]);
        db()->prepare('DELETE FROM accounting_note_lines WHERE company_id=? AND note_id=?')->execute([$companyId, $note['id']]);
        r151_note_store_lines($companyId, (string)$note['id'], $lines, $accounts);
        if (function_exists('document_tax_rows_save')) document_tax_rows_save($companyId, 'accounting_note', (string)$note['id'], []);
        $total = $values['total']; $date = $values['date'];
    } else {
        $v = r151_note_prepare_unlinked($company, $input);
        db()->prepare('UPDATE accounting_notes SET source_id=NULL,reference_number=?,party_id=?,note_date=?,currency=?,exchange_rate_micros=?,foreign_subtotal_cents=?,foreign_tax_cents=?,foreign_total_cents=?,subtotal_cents=?,tax_cents=?,total_cents=?,memo=? WHERE id=? AND company_id=? AND status=\'draft\'')
            ->execute([$v['reference'], $v['partyId'], $v['date'], $v['currency'], $v['rate'], $v['net'], $v['tax'], $v['foreignTotal'], $v['baseNet'], $v['baseTax'], $v['total'], $v['memo'], $note['id'], $companyId]);
        db()->prepare('DELETE FROM accounting_note_lines WHERE company_id=? AND note_id=?')->execute([$companyId, $note['id']]);
        r151_note_store_lines($companyId, (string)$note['id'], array_map(static fn(array $l): array => $l + ['foreignAmountCents' => $l['amount']], $v['lines']));
        if (function_exists('document_tax_rows_save')) document_tax_rows_save($companyId, 'accounting_note', (string)$note['id'], $v['taxRows']);
        $total = $v['total']; $date = $v['date'];
    }
    if (function_exists('voucher_update_saved')) voucher_update_saved($companyId, 'accounting_note', (string)$note['id'], $date, (string)$note['number'], $total);
    audit_event($user, $companyId, 'accounting_note.draft_updated', 'accounting_note', (string)$note['id'], ['number' => (string)$note['number'], 'linked' => $linked, 'totalCents' => $total]);
    return ['id' => (string)$note['id'], 'number' => (string)$note['number'], 'status' => 'draft'];
}

/** Revenue side of a linked customer note: its lines' GL accounts when set, else the original invoice's split. */
function r151_note_revenue_lines(string $companyId, array $note, string $invoiceId, int $net, bool $reverse): array
{
    $split = isset($note['id']) ? r151_note_account_split($companyId, (string)$note['id'], $net) : null;
    if (!$split) return note_customer_revenue_lines($companyId, $invoiceId, $net, $reverse);
    $out = [];
    foreach ($split as $account => $amount) $out[] = ['accountId' => (string)$account, 'debitCents' => $reverse ? $amount : 0, 'creditCents' => $reverse ? 0 : $amount, 'memo' => 'Note revenue adjustment'];
    return $out;
}

/** Cost side of a linked vendor note: its lines' GL accounts when set, else the original's account. */
function r151_note_cost_lines(string $companyId, array $note, array $source, int $amount): array
{
    $split = isset($note['id']) ? r151_note_account_split($companyId, (string)$note['id'], $amount) : null;
    if (!$split) return [['accountId' => (string)$source['category_account_id'], 'debitCents' => 0, 'creditCents' => $amount, 'memo' => 'Original vendor invoice cost adjustment']];
    $out = [];
    foreach ($split as $account => $value) $out[] = ['accountId' => (string)$account, 'debitCents' => 0, 'creditCents' => $value, 'memo' => 'Vendor note cost adjustment'];
    return $out;
}
