<?php
declare(strict_types=1);

/*
 * IN-4: GST return working reports for India companies, computed from the books.
 * - GSTR-1 (outward supplies), GSTR-3B (summary and tax payable), sales and purchase (ITC) registers,
 *   GST ledger check (report figures against the GST GL accounts), return periods (prepared / filed with ARN),
 *   GSTR-2B import (the JSON the taxpayer downloads from the GST portal) and reconciliation with the purchase register.
 * - Nothing is filed and no government service is called. Figures are working figures for the user to check.
 * - Every route answers 404 for a Canadian company (handle_india_gst checks the edition first).
 * - An uploaded GSTR-2B file is untrusted data: it is size-limited, parsed as JSON only, and every value is validated.
 */

const IN4_RETURN_TYPES = ['GSTR1' => 'GSTR-1', 'GSTR3B' => 'GSTR-3B'];
const IN4_2B_MAX_BYTES = 5_000_000;
const IN4_2B_MAX_LINES = 20000;

function in4_schema_ready(): bool
{
    static $ready = false;
    if ($ready) return true;
    if (!in3_schema_ready()) return false;
    if (schema_table_exists('india_gst_returns') && schema_table_exists('india_gstr2b_imports') && schema_table_exists('india_gstr2b_lines')) return $ready = true;
    if (db()->inTransaction()) return false;
    db()->exec("CREATE TABLE IF NOT EXISTS india_gst_returns (
        id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, return_type VARCHAR(10) NOT NULL, period CHAR(7) NOT NULL,
        status ENUM('prepared','filed') NOT NULL, figures_json LONGTEXT NOT NULL, figures_hash CHAR(64) NOT NULL,
        arn VARCHAR(20) NULL, filed_on DATE NULL, prepared_by VARCHAR(64) NOT NULL, filed_by VARCHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY india_gst_returns_period_uq (company_id, return_type, period),
        CONSTRAINT fk_india_gst_returns_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS india_gstr2b_imports (
        id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, period CHAR(7) NOT NULL, gstin VARCHAR(15) NULL,
        file_name VARCHAR(200) NOT NULL, sha256 CHAR(64) NOT NULL, line_count INT NOT NULL, status ENUM('active','replaced') NOT NULL DEFAULT 'active',
        imported_by VARCHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY india_gstr2b_imports_period_idx (company_id, period, status),
        CONSTRAINT fk_india_gstr2b_imports_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS india_gstr2b_lines (
        id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, import_id VARCHAR(64) NOT NULL,
        section VARCHAR(10) NOT NULL, supplier_gstin VARCHAR(15) NOT NULL, supplier_name VARCHAR(200) NOT NULL DEFAULT '',
        document_type VARCHAR(10) NOT NULL, document_number VARCHAR(40) NOT NULL, number_key VARCHAR(40) NOT NULL, document_date DATE NOT NULL,
        place_of_supply CHAR(2) NULL, reverse_charge TINYINT(1) NOT NULL DEFAULT 0, itc_available TINYINT(1) NOT NULL DEFAULT 1,
        value_cents BIGINT NOT NULL DEFAULT 0, taxable_cents BIGINT NOT NULL DEFAULT 0, igst_cents BIGINT NOT NULL DEFAULT 0, cgst_cents BIGINT NOT NULL DEFAULT 0,
        sgst_cents BIGINT NOT NULL DEFAULT 0, cess_cents BIGINT NOT NULL DEFAULT 0,
        KEY india_gstr2b_lines_match_idx (company_id, supplier_gstin, number_key),
        CONSTRAINT fk_india_gstr2b_lines_import FOREIGN KEY (import_id) REFERENCES india_gstr2b_imports(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $ready = true;
}

/* ---------- period helpers ---------- */

/** '2026-10' => ['2026-10-01','2026-10-31']. */
function in4_month_range(string $period): array
{
    if (!preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/', $period, $m)) fail('Choose a return period (month).', 422, 'india_gst_period');
    $from = $period . '-01';
    return [$from, date('Y-m-t', strtotime($from))];
}

function in4_period_input(): array
{
    $period = trim((string)($_GET['period'] ?? ''));
    if ($period !== '') { [$from, $to] = in4_month_range($period); return [$from, $to, $period]; }
    $from = safe_date((string)($_GET['from'] ?? ''), 'From'); $to = safe_date((string)($_GET['to'] ?? ''), 'To');
    if ($to < $from) fail('To is before From.', 422, 'india_gst_period');
    if ((strtotime($to) - strtotime($from)) > 400 * 86400) fail('Choose a period of at most one year.', 422, 'india_gst_period');
    return [$from, $to, null];
}

function in4_head(string $name): string
{
    $n = strtoupper(trim($name));
    return in_array($n, IN3_HEADS, true) ? $n : 'CESS';
}

function in4_zero_heads(): array { return array_fill_keys(IN3_HEADS, 0); }

/** Normalised supplier invoice number for matching: letters and digits only, upper case, leading zeros removed. */
function in4_number_key(string $number): string
{
    $k = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $number));
    $k = ltrim($k, '0');
    return mb_substr($k === '' ? '0' : $k, 0, 40);
}

/* ---------- the document set (one builder used by every report) ---------- */

/** Item profile (HSN/SAC, UQC, GST treatment) in force on a date. */
function in4_item_profile(string $companyId, ?string $itemId, string $date): array
{
    static $cache = [];
    if ($itemId === null || $itemId === '' || !schema_table_exists('india_entity_profiles')) return [];
    $k = $companyId . '|' . $itemId . '|' . $date;
    if (isset($cache[$k])) return $cache[$k];
    $q = db()->prepare("SELECT data_json FROM india_entity_profiles WHERE company_id=? AND entity_type='item' AND entity_id=? AND effective_from<=? ORDER BY effective_from DESC LIMIT 1");
    $q->execute([$companyId, $itemId, $date]);
    $d = json_decode((string)($q->fetchColumn() ?: ''), true);
    return $cache[$k] = is_array($d) ? $d : [];
}

/**
 * Every GST-relevant document of the period, with taxable value and tax by head for each GST code,
 * untaxed lines (nil, exempt, non-GST), and item lines for the HSN summary.
 * Signs: credit notes carry negative amounts so that totals are net.
 */
function in4_documents(string $companyId, string $from, string $to): array
{
    if (!schema_table_exists('india_gst_codes')) return [];
    $codes = [];
    $q = db()->prepare('SELECT g.tax_code_id, g.rate_mpct, g.cess_mpct, g.supply, t.code FROM india_gst_codes g JOIN tax_codes t ON t.id=g.tax_code_id WHERE g.company_id=?');
    $q->execute([$companyId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) $codes[(string)$c['tax_code_id']] = $c;
    $taxQ = db()->prepare('SELECT tax_code_id, component_name, recoverable, tax_cents FROM document_tax_lines WHERE company_id=? AND document_type=? AND document_id=?');
    $gstQ = db()->prepare('SELECT * FROM india_document_gst WHERE company_id=? AND document_type=? AND document_id=?');
    $docs = [];
    $build = static function (array $head, string $taxType, string $gstType, string $gstId, array $lines, int $sign, ?array $taxOverride = null) use ($companyId, $codes, $taxQ, $gstQ): array {
        $gstQ->execute([$companyId, $gstType, $gstId]); $g = $gstQ->fetch(PDO::FETCH_ASSOC) ?: [];
        $byCode = []; $untaxed = []; $items = []; $pool = [];
        $isNote = $taxType === 'accounting_note';
        $taxQ->execute([$companyId, $taxType, (string)$head['id']]); $taxRows = $taxQ->fetchAll(PDO::FETCH_ASSOC);
        if (!$taxRows && $taxOverride) $taxRows = $taxOverride;
        foreach ($lines as $l) {
            $codeId = (string)($l['tax_code_id'] ?? '');
            // A note line without its own code takes the GST codes of the note's tax rows (the invoice's proportions).
            if ($isNote && $codeId === '' && $taxRows) { $pool[] = $l; continue; }
            $amount = (int)$l['amount'];
            $profile = in4_item_profile($companyId, $l['item'] ?? null, (string)$head['date']);
            if ($codeId !== '' && isset($codes[$codeId])) {
                $byCode[$codeId] ??= ['codeId' => $codeId, 'code' => (string)$codes[$codeId]['code'], 'rateMpct' => (int)$codes[$codeId]['rate_mpct'], 'cessMpct' => (int)$codes[$codeId]['cess_mpct'],
                    'taxableCents' => 0, 'tax' => in4_zero_heads(), 'notClaimed' => in4_zero_heads()];
                $byCode[$codeId]['taxableCents'] += $sign * $amount;
            } else {
                $treatment = (string)($profile['gstTreatment'] ?? '');
                if ($codeId !== '') $treatment = 'non_gst_code';
                $untaxed[] = ['taxableCents' => $sign * $amount, 'treatment' => $treatment];
            }
            $items[] = ['codeId' => $codeId, 'hsn' => (string)($profile['hsnSac'] ?? ''), 'uqc' => strtoupper((string)($profile['uqc'] ?? '')) ?: 'OTH',
                'qtyMilli' => $sign * (int)($l['qty'] ?? 0), 'taxableCents' => $sign * $amount];
        }
        foreach ($taxRows as $t) {
            $cid = (string)$t['tax_code_id'];
            if (!isset($byCode[$cid])) {
                if (!isset($codes[$cid])) continue;
                $byCode[$cid] = ['codeId' => $cid, 'code' => (string)$codes[$cid]['code'], 'rateMpct' => (int)$codes[$cid]['rate_mpct'], 'cessMpct' => (int)$codes[$cid]['cess_mpct'], 'taxableCents' => 0, 'tax' => in4_zero_heads(), 'notClaimed' => in4_zero_heads()];
            }
            $h = in4_head((string)$t['component_name']);
            if ($head['side'] === 'purchase' && (int)$t['recoverable'] !== 1) $byCode[$cid]['notClaimed'][$h] += $sign * (int)$t['tax_cents'];
            else $byCode[$cid]['tax'][$h] += $sign * (int)$t['tax_cents'];
        }
        if ($pool && $byCode) {
            $cids = array_keys($byCode);
            $weights = array_map(static fn($c) => abs(array_sum($byCode[$c]['tax']) + array_sum($byCode[$c]['notClaimed'])), $cids);
            foreach ($pool as $l) {
                $alloc = tax_allocate((int)$l['amount'], $weights);
                foreach ($cids as $n => $cid) $byCode[$cid]['taxableCents'] += $sign * $alloc[$n];
                $profile = in4_item_profile($companyId, $l['item'] ?? null, (string)$head['date']);
                $main = $cids[array_search(max($weights), $weights, true)];
                $items[] = ['codeId' => $main, 'hsn' => (string)($profile['hsnSac'] ?? ''), 'uqc' => strtoupper((string)($profile['uqc'] ?? '')) ?: 'OTH', 'qtyMilli' => $sign * (int)($l['qty'] ?? 0), 'taxableCents' => $sign * (int)$l['amount']];
            }
        } elseif ($pool) foreach ($pool as $l) $untaxed[] = ['taxableCents' => $sign * (int)$l['amount'], 'treatment' => ''];
        // Item lines take their share of each code's tax (for the HSN summary).
        foreach ($byCode as $cid => $c) {
            $idx = array_keys(array_filter($items, static fn($i) => $i['codeId'] === $cid));
            if (!$idx) continue;
            foreach (IN3_HEADS as $h) {
                $alloc = tax_allocate($c['tax'][$h] + $c['notClaimed'][$h], array_map(static fn($i) => abs($items[$i]['taxableCents']), $idx));
                foreach ($idx as $n => $i) $items[$i]['tax'][$h] = $alloc[$n];
            }
        }
        foreach ($items as &$i) $i['tax'] ??= in4_zero_heads(); unset($i);
        $taxTotal = 0; foreach ($byCode as $c) $taxTotal += array_sum($c['tax']) + array_sum($c['notClaimed']);
        return $head + ['pos' => $g['place_of_supply'] ?? null, 'supply' => $g['supply'] ?? null, 'partyGstin' => $g['party_gstin'] ?? null,
            'partyState' => $g['party_state_code'] ?? null, 'codes' => array_values($byCode), 'untaxed' => $untaxed, 'items' => $items, 'taxCents' => $taxTotal];
    };
    // Customer invoices (issued, not void).
    $inv = db()->prepare("SELECT i.id, i.number, i.issue_date, i.total_cents, i.status, i.customer_id, c.name FROM invoices i JOIN customers c ON c.id=i.customer_id
        WHERE i.company_id=? AND i.status IN ('sent','paid') AND i.issue_date BETWEEN ? AND ? ORDER BY i.issue_date, i.number");
    $inv->execute([$companyId, $from, $to]);
    $lq = db()->prepare('SELECT tax_code_id, amount_cents amount, quantity_milli qty, product_service_id item FROM invoice_lines WHERE invoice_id=? ORDER BY sort_order, id');
    foreach ($inv->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lq->execute([$r['id']]);
        $docs[] = $build(['kind' => 'invoice', 'side' => 'sales', 'id' => (string)$r['id'], 'number' => (string)$r['number'], 'date' => (string)$r['issue_date'], 'partyId' => (string)$r['customer_id'], 'partyName' => (string)$r['name'],
            'valueCents' => (int)$r['total_cents'], 'source' => null], 'invoice', 'invoice', (string)$r['id'], $lq->fetchAll(PDO::FETCH_ASSOC), 1);
    }
    // Vendor invoices (posted).
    $bills = db()->prepare("SELECT b.id, b.number, b.bill_date, b.total_cents, b.vendor_id, v.name, b.tax_code_id, b.subtotal_cents, b.product_service_id, b.quantity_milli FROM bills b JOIN vendors v ON v.id=b.vendor_id
        WHERE b.company_id=? AND b.status IN ('open','paid') AND b.bill_date BETWEEN ? AND ? ORDER BY b.bill_date, b.number");
    $bills->execute([$companyId, $from, $to]);
    $bl = db()->prepare('SELECT tax_code_id, amount_cents amount, quantity_milli qty, product_service_id item FROM bill_lines WHERE bill_id=? ORDER BY sort_order, id');
    foreach ($bills->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $bl->execute([$r['id']]); $lines = $bl->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) $lines = [['tax_code_id' => $r['tax_code_id'], 'amount' => (int)$r['subtotal_cents'], 'qty' => (int)$r['quantity_milli'], 'item' => $r['product_service_id']]];
        $docs[] = $build(['kind' => 'bill', 'side' => 'purchase', 'id' => (string)$r['id'], 'number' => (string)$r['number'], 'date' => (string)$r['bill_date'], 'partyId' => (string)$r['vendor_id'], 'partyName' => (string)$r['name'],
            'valueCents' => (int)$r['total_cents'], 'source' => null], 'bill', 'bill', (string)$r['id'], $lines, 1);
    }
    // Credit and debit notes (posted). A note takes the place of supply of its invoice.
    $notes = db()->prepare("SELECT n.*, COALESCE(c.name, v.name) party, si.number src_number, si.issue_date src_date, si.total_cents src_value, sb.number bsrc_number, sb.bill_date bsrc_date
        FROM accounting_notes n LEFT JOIN customers c ON c.id=n.party_id AND n.source_type='invoice' LEFT JOIN vendors v ON v.id=n.party_id AND n.source_type='bill'
        LEFT JOIN invoices si ON si.id=n.source_id AND n.source_type='invoice' LEFT JOIN bills sb ON sb.id=n.source_id AND n.source_type='bill'
        WHERE n.company_id=? AND n.status='posted' AND n.note_date BETWEEN ? AND ? ORDER BY n.note_date, n.number");
    $notes->execute([$companyId, $from, $to]);
    $nl = db()->prepare('SELECT tax_code_id, foreign_amount_cents, quantity_milli qty, product_service_id item FROM accounting_note_lines WHERE note_id=? ORDER BY sort_order, id');
    foreach ($notes->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $sales = $n['source_type'] === 'invoice';
        $credit = in_array((string)$n['note_kind'], ['customer_credit', 'vendor_credit'], true);
        $nl->execute([$n['id']]);
        $rate = max(1, (int)$n['exchange_rate_micros']);
        $lines = array_map(static fn($l) => ['tax_code_id' => $l['tax_code_id'], 'amount' => (int)round((int)$l['foreign_amount_cents'] * $rate / 1000000), 'qty' => (int)$l['qty'], 'item' => $l['item']], $nl->fetchAll(PDO::FETCH_ASSOC));
        if (!$lines) $lines = [['tax_code_id' => null, 'amount' => (int)$n['subtotal_cents'], 'qty' => 0, 'item' => null]];
        // A note's tax is not stored per component: posting splits it across the GST components of its invoice
        // (tax_rows_portion), so the report splits it the same way and agrees with the GST accounts.
        $override = null;
        if ((int)$n['tax_cents'] !== 0 && function_exists('document_tax_rows_get')) {
            $src = document_tax_rows_get($companyId, $sales ? 'invoice' : 'bill', (string)$n['source_id']);
            if ($src) $override = array_map(static fn($r) => ['tax_code_id' => $r['taxCodeId'], 'component_name' => $r['componentName'], 'recoverable' => $r['recoverable'] ? 1 : 0, 'tax_cents' => $r['taxCents']], tax_rows_portion($src, (int)$n['tax_cents']));
        }
        $docs[] = $build(['kind' => (string)$n['note_kind'], 'side' => $sales ? 'sales' : 'purchase', 'id' => (string)$n['id'], 'number' => (string)$n['number'], 'date' => (string)$n['note_date'],
            'partyId' => (string)$n['party_id'], 'partyName' => (string)$n['party'], 'valueCents' => ($credit ? -1 : 1) * (int)$n['total_cents'],
            'source' => ['number' => $sales ? $n['src_number'] : ($n['reference_number'] ?: $n['bsrc_number']), 'date' => $sales ? $n['src_date'] : $n['bsrc_date'], 'valueCents' => $sales ? (int)$n['src_value'] : null]],
            'accounting_note', $sales ? 'invoice' : 'bill', (string)$n['source_id'], $lines, $credit ? -1 : 1, $override);
    }
    return $docs;
}

/** Approved GSTR-1 B2C (large) invoice limit in force on a date: rule category gst_return_limit, code B2CL. */
function in4_b2cl_limit(string $companyId, string $date): ?int
{
    $r = in3_rule_in_force($companyId, 'IN', 'gst_return_limit', 'B2CL', $date);
    return ($r && $r['value_cents'] !== null) ? (int)$r['value_cents'] : null;
}

function in4_add_heads(array &$into, array $heads, int $sign = 1): void { foreach (IN3_HEADS as $h) $into[$h] = ($into[$h] ?? 0) + $sign * (int)($heads[$h] ?? 0); }

/** SGST and UTGST are reported in one column (State/UT tax). */
function in4_cols(array $heads): array
{
    return ['igstCents' => (int)$heads['IGST'], 'cgstCents' => (int)$heads['CGST'], 'sgstCents' => (int)$heads['SGST'] + (int)$heads['UTGST'], 'cessCents' => (int)$heads['CESS']];
}

/* ---------- GSTR-1 ---------- */

function in4_gstr1(array $company, string $from, string $to, ?array $docs = null): array
{
    $companyId = (string)$company['id'];
    $docs ??= in4_documents($companyId, $from, $to);
    $limit = in4_b2cl_limit($companyId, $to);
    $companyState = in3_state_code((string)$company['province']) ?? '';
    $b2b = []; $b2cl = []; $exp = []; $b2cs = []; $cdnr = []; $cdnur = []; $hsn = []; $nil = []; $warnings = [];
    $isExport = static fn(array $d): bool => $d['pos'] === IN3_FOREIGN;
    foreach ($docs as $d) {
        if ($d['side'] !== 'sales') continue;
        if ($d['pos'] === null && $d['codes']) $warnings[] = ['number' => $d['number'], 'message' => 'No place of supply is stored with this document (it was saved before GST was set up). It is reported as an intra-state supply.'];
        $pos = $d['pos'] ?? $companyState;
        $registered = $d['partyGstin'] !== null;
        $note = $d['kind'] !== 'invoice';
        foreach ($d['codes'] as $c) {
            $row = ['number' => $d['number'], 'date' => $d['date'], 'party' => $d['partyName'], 'partyGstin' => $d['partyGstin'], 'placeOfSupply' => $pos, 'valueCents' => $d['valueCents'],
                'rateMpct' => $c['rateMpct'], 'cessRateMpct' => $c['cessMpct'], 'taxableCents' => $c['taxableCents'], 'reverseCharge' => false] + in4_cols($c['tax']);
            if ($note) {
                $row += ['noteType' => $d['kind'] === 'customer_credit' ? 'C' : 'D', 'invoiceNumber' => $d['source']['number'] ?? null, 'invoiceDate' => $d['source']['date'] ?? null];
                if ($registered && !$isExport($d)) { $cdnr[] = $row; continue; }
                $srcLarge = $limit !== null && $pos !== $companyState && (int)($d['source']['valueCents'] ?? 0) > $limit;
                if ($isExport($d)) { $cdnur[] = $row + ['type' => array_sum($c['tax']) !== 0 ? 'EXPWP' : 'EXPWOP']; continue; }
                if ($srcLarge) { $cdnur[] = $row + ['type' => 'B2CL']; continue; }
                // Notes on small B2C invoices adjust B2C (small) by place of supply and rate.
            } elseif ($isExport($d)) { $exp[] = $row + ['type' => array_sum($c['tax']) !== 0 ? 'WPAY' : 'WOPAY']; continue; }
            elseif ($registered) { $b2b[] = $row; continue; }
            elseif ($limit !== null && $pos !== $companyState && $d['valueCents'] > $limit) { $b2cl[] = $row; continue; }
            if ($registered || $isExport($d)) continue;
            $k = $pos . '|' . $c['rateMpct'] . '|' . $c['cessMpct'];
            $b2cs[$k] ??= ['placeOfSupply' => $pos, 'supply' => $pos === $companyState ? 'intra' : 'inter', 'rateMpct' => $c['rateMpct'], 'cessRateMpct' => $c['cessMpct'], 'taxableCents' => 0, 'igstCents' => 0, 'cgstCents' => 0, 'sgstCents' => 0, 'cessCents' => 0];
            $b2cs[$k]['taxableCents'] += $c['taxableCents'];
            foreach (in4_cols($c['tax']) as $col => $v) $b2cs[$k][$col] += $v;
        }
        // Nil-rated, exempt and non-GST supplies (lines without a GST code, and 0% codes).
        $inter = $pos !== $companyState;
        foreach ($d['untaxed'] as $u) {
            $class = match ($u['treatment']) { 'non_gst', 'non_gst_code' => 'nonGst', 'nil_rated' => 'nil', 'exempt' => 'exempt', default => 'exempt' };
            $k = ($inter ? 'inter' : 'intra') . '|' . ($registered ? 'reg' : 'unreg');
            $nil[$k] ??= ['supply' => $inter ? 'inter' : 'intra', 'registered' => $registered, 'nilCents' => 0, 'exemptCents' => 0, 'nonGstCents' => 0];
            $nil[$k][$class . 'Cents'] += $u['taxableCents'];
        }
        foreach ($d['codes'] as $c) if ($c['rateMpct'] === 0 && $c['cessMpct'] === 0) {
            $k = ($inter ? 'inter' : 'intra') . '|' . ($registered ? 'reg' : 'unreg');
            $nil[$k] ??= ['supply' => $inter ? 'inter' : 'intra', 'registered' => $registered, 'nilCents' => 0, 'exemptCents' => 0, 'nonGstCents' => 0];
            $nil[$k]['nilCents'] += $c['taxableCents'];
        }
        // HSN summary.
        foreach ($d['items'] as $i) {
            if ($i['codeId'] === '') continue;
            $rate = 0; foreach ($d['codes'] as $c) if ($c['codeId'] === $i['codeId']) $rate = $c['rateMpct'];
            $k = ($i['hsn'] === '' ? '-' : $i['hsn']) . '|' . $i['uqc'] . '|' . $rate;
            $hsn[$k] ??= ['hsnSac' => $i['hsn'], 'uqc' => $i['uqc'], 'rateMpct' => $rate, 'quantityMilli' => 0, 'valueCents' => 0, 'taxableCents' => 0, 'igstCents' => 0, 'cgstCents' => 0, 'sgstCents' => 0, 'cessCents' => 0];
            $hsn[$k]['quantityMilli'] += $i['qtyMilli']; $hsn[$k]['taxableCents'] += $i['taxableCents'];
            foreach (in4_cols($i['tax']) as $col => $v) $hsn[$k][$col] += $v;
            $hsn[$k]['valueCents'] += $i['taxableCents'] + array_sum($i['tax']);
        }
    }
    foreach ($hsn as $h) if ($h['hsnSac'] === '') { $warnings[] = ['number' => null, 'message' => 'Some invoice lines have no HSN/SAC code (no item, or the item has no HSN/SAC in India Masters). They are shown under "-" in the HSN summary.']; break; }
    if ($limit === null) $warnings[] = ['number' => null, 'message' => 'No approved GSTR-1 B2C (large) limit rule is in force (India Masters › Statutory rules, category "GST return limit", code B2CL). All invoices to unregistered persons are shown in B2C (small).'];
    // Documents issued (table 13): invoices and notes numbered in the period, including cancelled (void) ones.
    $series = [];
    foreach ([['Invoices for outward supply', "SELECT number, status FROM invoices WHERE company_id=? AND status<>'draft' AND issue_date BETWEEN ? AND ?"],
              ['Credit notes', "SELECT number, status FROM accounting_notes WHERE company_id=? AND note_kind='customer_credit' AND status<>'draft' AND note_date BETWEEN ? AND ?"],
              ['Debit notes', "SELECT number, status FROM accounting_notes WHERE company_id=? AND note_kind='customer_debit' AND status<>'draft' AND note_date BETWEEN ? AND ?"]] as [$label, $sql]) {
        $q = db()->prepare($sql); $q->execute([$companyId, $from, $to]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) continue;
        $nums = array_column($rows, 'number'); natsort($nums); $nums = array_values($nums);
        $cancelled = count(array_filter($rows, static fn($r) => $r['status'] === 'void'));
        $series[] = ['document' => $label, 'from' => $nums[0], 'to' => $nums[count($nums) - 1], 'total' => count($rows), 'cancelled' => $cancelled, 'net' => count($rows) - $cancelled];
    }
    $sum = static function (array $rows): array { $t = ['taxableCents' => 0, 'igstCents' => 0, 'cgstCents' => 0, 'sgstCents' => 0, 'cessCents' => 0]; foreach ($rows as $r) foreach ($t as $k => $_) $t[$k] += (int)($r[$k] ?? 0); return $t; };
    $b2cs = array_values($b2cs); usort($b2cs, static fn($a, $b) => [$a['placeOfSupply'], $a['rateMpct']] <=> [$b['placeOfSupply'], $b['rateMpct']]);
    $hsn = array_values($hsn); usort($hsn, static fn($a, $b) => [$a['hsnSac'], $a['rateMpct']] <=> [$b['hsnSac'], $b['rateMpct']]);
    $sections = ['b2b' => $b2b, 'b2cl' => $b2cl, 'exp' => $exp, 'b2cs' => $b2cs, 'cdnr' => $cdnr, 'cdnur' => $cdnur, 'nil' => array_values($nil), 'hsn' => $hsn, 'docs' => $series];
    $totals = [];
    foreach (['b2b', 'b2cl', 'exp', 'b2cs', 'cdnr', 'cdnur', 'hsn'] as $s) $totals[$s] = $sum($sections[$s]) + ['count' => count($sections[$s])];
    return ['returnType' => 'GSTR1', 'from' => $from, 'to' => $to, 'gstin' => in3_company_gstin($company), 'b2clLimitCents' => $limit, 'sections' => $sections, 'totals' => $totals, 'warnings' => $warnings,
        'note' => 'Working figures computed from issued customer invoices and posted credit and debit notes. Nothing is filed. Check every figure before you enter or upload it on the GST portal.'];
}

/* ---------- GSTR-3B ---------- */

/** Set off input tax credit against output tax (CGST Act s.49 and rule 88A order: IGST credit first; CGST and SGST/UTGST credit never cross). */
function in4_setoff(array $liability, array $credit): array
{
    $l = ['IGST' => max(0, $liability['IGST']), 'CGST' => max(0, $liability['CGST']), 'SGST' => max(0, $liability['SGST'] + $liability['UTGST']), 'CESS' => max(0, $liability['CESS'])];
    $c = ['IGST' => max(0, $credit['IGST']), 'CGST' => max(0, $credit['CGST']), 'SGST' => max(0, $credit['SGST'] + $credit['UTGST']), 'CESS' => max(0, $credit['CESS'])];
    $used = []; foreach (['IGST', 'CGST', 'SGST', 'CESS'] as $h) $used[$h] = ['IGST' => 0, 'CGST' => 0, 'SGST' => 0, 'CESS' => 0];
    $take = static function (string $from, string $to) use (&$l, &$c, &$used): void { $x = min($l[$to], $c[$from]); $l[$to] -= $x; $c[$from] -= $x; $used[$to][$from] += $x; };
    $take('IGST', 'IGST'); $take('IGST', 'CGST'); $take('IGST', 'SGST');
    $take('CGST', 'CGST'); $take('CGST', 'IGST');
    $take('SGST', 'SGST'); $take('SGST', 'IGST');
    $take('CESS', 'CESS');
    $rows = [];
    foreach (['IGST', 'CGST', 'SGST', 'CESS'] as $h) {
        $orig = $h === 'SGST' ? $liability['SGST'] + $liability['UTGST'] : $liability[$h];
        $rows[] = ['head' => $h === 'SGST' ? 'SGST/UTGST' : $h, 'liabilityCents' => $orig, 'paidByIgstCents' => $used[$h]['IGST'], 'paidByCgstCents' => $used[$h]['CGST'], 'paidBySgstCents' => $used[$h]['SGST'], 'paidByCessCents' => $used[$h]['CESS'],
            'cashCents' => $l[$h], 'creditLeftCents' => $c[$h]];
    }
    return $rows;
}

function in4_gstr3b(array $company, string $from, string $to, ?array $docs = null): array
{
    $companyId = (string)$company['id'];
    $docs ??= in4_documents($companyId, $from, $to);
    $companyState = in3_state_code((string)$company['province']) ?? '';
    $blank = static fn(): array => ['taxableCents' => 0] + in4_zero_heads();
    $t31 = ['a' => $blank(), 'b' => $blank(), 'c' => $blank(), 'd' => $blank(), 'e' => $blank()];
    $t32 = []; $itc = ['A1' => in4_zero_heads(), 'A5' => in4_zero_heads(), 'D2' => in4_zero_heads()]; $t5 = ['inter' => 0, 'intra' => 0, 'nonGstInter' => 0, 'nonGstIntra' => 0];
    foreach ($docs as $d) {
        $pos = $d['pos'] ?? $companyState;
        if ($d['side'] === 'sales') {
            foreach ($d['codes'] as $c) {
                $box = $pos === IN3_FOREIGN ? 'b' : (($c['rateMpct'] === 0 && $c['cessMpct'] === 0) ? 'c' : 'a');
                $t31[$box]['taxableCents'] += $c['taxableCents']; in4_add_heads($t31[$box], $c['tax']);
                if ($box === 'a' && $d['partyGstin'] === null && $pos !== $companyState) {
                    $t32[$pos] ??= ['placeOfSupply' => $pos, 'taxableCents' => 0, 'igstCents' => 0];
                    $t32[$pos]['taxableCents'] += $c['taxableCents']; $t32[$pos]['igstCents'] += $c['tax']['IGST'];
                }
            }
            foreach ($d['untaxed'] as $u) { $box = in_array($u['treatment'], ['non_gst', 'non_gst_code'], true) ? 'e' : 'c'; $t31[$box]['taxableCents'] += $u['taxableCents']; }
        } else {
            $foreign = ($d['partyState'] ?? null) === IN3_FOREIGN;
            foreach ($d['codes'] as $c) { in4_add_heads($itc[$foreign ? 'A1' : 'A5'], $c['tax']); in4_add_heads($itc['D2'], $c['notClaimed']); }
            $inter = ($d['supply'] ?? 'intra') === 'inter';
            foreach ($d['untaxed'] as $u) $t5[(in_array($u['treatment'], ['non_gst', 'non_gst_code'], true) ? 'nonGst' . ($inter ? 'Inter' : 'Intra') : ($inter ? 'inter' : 'intra'))] += $u['taxableCents'];
            foreach ($d['codes'] as $c) if ($c['rateMpct'] === 0 && $c['cessMpct'] === 0) $t5[$inter ? 'inter' : 'intra'] += $c['taxableCents'];
        }
    }
    $netItc = in4_zero_heads(); in4_add_heads($netItc, $itc['A1']); in4_add_heads($netItc, $itc['A5']);
    $liability = in4_zero_heads(); foreach (['a', 'b', 'd'] as $b) in4_add_heads($liability, $t31[$b]);
    $fmt = static fn(array $r): array => ['taxableCents' => (int)($r['taxableCents'] ?? 0)] + in4_cols($r);
    ksort($t32);
    return ['returnType' => 'GSTR3B', 'from' => $from, 'to' => $to, 'gstin' => in3_company_gstin($company),
        'table31' => ['a' => $fmt($t31['a']), 'b' => $fmt($t31['b']), 'c' => $fmt($t31['c']), 'd' => $fmt($t31['d']), 'e' => $fmt($t31['e'])],
        'table32' => array_values($t32),
        'table4' => ['A1' => in4_cols($itc['A1']), 'A3' => in4_cols(in4_zero_heads()), 'A5' => in4_cols($itc['A5']), 'B1' => in4_cols(in4_zero_heads()), 'B2' => in4_cols(in4_zero_heads()), 'C' => in4_cols($netItc), 'D2' => in4_cols($itc['D2'])],
        'table5' => $t5,
        'payment' => in4_setoff($liability, $netItc),
        'notSupported' => ['3.1(d) inward supplies liable to reverse charge', '4(A)(3) ITC on reverse charge', '4(B) ITC reversed (rules 38, 42, 43, s.17(5))', 'Interest and late fee'],
        'note' => 'Working figures computed from the books. Imports of goods (4(A)(1)) are vendor invoices from a supplier outside India. Reverse charge and ITC reversals are not computed yet, so those boxes show 0. The set-off follows section 49 and rule 88A (IGST credit first; CGST and SGST/UTGST credit are never used against each other). Nothing is filed.'];
}

/* ---------- registers and ledger check ---------- */

function in4_register(array $company, string $from, string $to, string $side): array
{
    $rows = []; $totals = ['taxableCents' => 0, 'igstCents' => 0, 'cgstCents' => 0, 'sgstCents' => 0, 'cessCents' => 0, 'notClaimedCents' => 0];
    foreach (in4_documents((string)$company['id'], $from, $to) as $d) {
        if ($d['side'] !== $side) continue;
        foreach ($d['codes'] as $c) {
            $r = ['kind' => $d['kind'], 'id' => $d['id'], 'number' => $d['number'], 'date' => $d['date'], 'party' => $d['partyName'], 'partyGstin' => $d['partyGstin'], 'placeOfSupply' => $d['pos'], 'supply' => $d['supply'],
                'code' => $c['code'], 'rateMpct' => $c['rateMpct'], 'taxableCents' => $c['taxableCents']] + in4_cols($c['tax']) + ['notClaimedCents' => array_sum($c['notClaimed'])];
            foreach ($totals as $k => $_) $totals[$k] += $r[$k];
            $rows[] = $r;
        }
    }
    return ['side' => $side, 'from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $totals];
}

/** GST GL accounts: opening, movement from GST documents, other entries, closing; and the report figure the document movement must equal. */
function in4_ledger(array $company, string $from, string $to): array
{
    $companyId = (string)$company['id'];
    $docs = in4_documents($companyId, $from, $to);
    $report = ['out' => in4_zero_heads(), 'in' => in4_zero_heads()];
    foreach ($docs as $d) foreach ($d['codes'] as $c) in4_add_heads($report[$d['side'] === 'sales' ? 'out' : 'in'], $c['tax']);
    $docSources = ['invoice', 'bill', 'accounting_note', 'accounting_note_void'];
    $in = implode(',', array_fill(0, count($docSources), '?'));
    $rows = []; $allTie = true;
    foreach (['out' => 'credit', 'in' => 'debit'] as $side => $normal) {
        foreach (IN3_ACCOUNTS[$side] as $h => [$code, $name]) {
            $a = db()->prepare('SELECT id, name FROM accounts WHERE company_id=? AND code=?'); $a->execute([$companyId, $code]); $acct = $a->fetch(PDO::FETCH_ASSOC);
            if (!$acct) continue;
            $sumQ = static function (string $where, array $p) use ($acct): int {
                $q = db()->prepare("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE jl.account_id=? AND je.status='posted' AND $where");
                $q->execute(array_merge([(string)$acct['id']], $p)); return (int)$q->fetchColumn();
            };
            $opening = $sumQ('je.entry_date<?', [$from]);
            $docMove = $sumQ("je.entry_date BETWEEN ? AND ? AND je.source_type IN ($in)", array_merge([$from, $to], $docSources));
            $other = $sumQ("je.entry_date BETWEEN ? AND ? AND je.source_type NOT IN ($in)", array_merge([$from, $to], $docSources));
            $sign = $normal === 'credit' ? -1 : 1; // show balances on their normal side
            $fromDocs = $sign * $docMove; $expected = $report[$side][$h];
            $tie = $fromDocs === $expected; $allTie = $allTie && $tie;
            $rows[] = ['side' => $side === 'out' ? 'output' : 'input', 'head' => $h, 'accountCode' => $code, 'accountName' => (string)$acct['name'],
                'openingCents' => $sign * $opening, 'fromDocumentsCents' => $fromDocs, 'otherEntriesCents' => $sign * $other, 'closingCents' => $sign * ($opening + $docMove + $other),
                'reportCents' => $expected, 'differenceCents' => $fromDocs - $expected];
        }
    }
    return ['from' => $from, 'to' => $to, 'rows' => $rows, 'ties' => $allTie,
        'note' => '"From GST documents" is the movement posted by customer invoices, vendor invoices and notes in the period; it must equal the report figure. "Other entries" are journals such as GST payments and set-off entries.'];
}

/* ---------- return periods ---------- */

function in4_figures(array $company, string $type, string $from, string $to): array
{
    $docs = in4_documents((string)$company['id'], $from, $to);
    if ($type === 'GSTR1') { $r = in4_gstr1($company, $from, $to, $docs); return ['totals' => $r['totals'], 'nil' => $r['sections']['nil'], 'docs' => $r['sections']['docs']]; }
    $r = in4_gstr3b($company, $from, $to, $docs);
    return ['table31' => $r['table31'], 'table4' => $r['table4'], 'payment' => $r['payment']];
}

function in4_periods(array $company, string $fy): array
{
    $companyId = (string)$company['id'];
    if (!preg_match('/^(20[0-9]{2})-([0-9]{2})$/', $fy, $m) || ((int)$m[1] + 1) % 100 !== (int)$m[2]) fail('Choose a financial year such as 2026-27.', 422, 'india_gst_fy');
    $start = (int)$m[1];
    $saved = [];
    if (schema_table_exists('india_gst_returns')) {
        $q = db()->prepare('SELECT * FROM india_gst_returns WHERE company_id=? AND period BETWEEN ? AND ?'); $q->execute([$companyId, sprintf('%04d-04', $start), sprintf('%04d-03', $start + 1)]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $saved[$r['return_type'] . '|' . $r['period']] = $r;
    }
    $out = [];
    for ($i = 0; $i < 12; $i++) {
        $y = $start + intdiv(3 + $i, 12); $mo = ((3 + $i) % 12) + 1; $period = sprintf('%04d-%02d', $y, $mo);
        [$from, $to] = in4_month_range($period);
        $row = ['period' => $period, 'from' => $from, 'to' => $to];
        foreach (IN4_RETURN_TYPES as $type => $label) {
            $s = $saved[$type . '|' . $period] ?? null;
            $changed = null;
            if ($s && $from <= canadian_today()) $changed = hash('sha256', json_encode(in4_figures($company, $type, $from, $to))) !== (string)$s['figures_hash'];
            $row[$type] = $s ? ['status' => $s['status'], 'arn' => $s['arn'], 'filedOn' => $s['filed_on'], 'changedSince' => $changed, 'updatedAt' => $s['updated_at']] : ['status' => 'not_prepared'];
        }
        $out[] = $row;
    }
    return ['financialYear' => $fy, 'periods' => $out, 'canFile' => in_array((string)$company['role'], ['owner', 'admin'], true)];
}

function in4_period_action(array $user, array $company, array $input): array
{
    $companyId = (string)$company['id'];
    $type = (string)($input['returnType'] ?? ''); if (!isset(IN4_RETURN_TYPES[$type])) fail('Choose GSTR-1 or GSTR-3B.', 422, 'india_gst_return_type');
    $period = (string)($input['period'] ?? ''); [$from, $to] = in4_month_range($period);
    $action = (string)($input['action'] ?? '');
    $owner = in_array((string)$company['role'], ['owner', 'admin'], true);
    in4_schema_ready();
    db()->beginTransaction();
    try {
        $q = db()->prepare('SELECT * FROM india_gst_returns WHERE company_id=? AND return_type=? AND period=? FOR UPDATE'); $q->execute([$companyId, $type, $period]); $cur = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($action === 'prepare') {
            if (!in_array((string)$company['role'], ['owner', 'admin', 'accountant', 'editor'], true)) fail('You cannot prepare GST returns in this company.', 403, 'india_gst_return_role');
            if ($cur && $cur['status'] === 'filed') fail(IN4_RETURN_TYPES[$type] . ' for ' . $period . ' is marked filed. The Company Owner or an Admin reopens it first.', 409, 'india_gst_return_filed');
            $figures = in4_figures($company, $type, $from, $to); $json = json_encode($figures);
            if ($cur) db()->prepare("UPDATE india_gst_returns SET status='prepared', figures_json=?, figures_hash=?, prepared_by=? WHERE id=?")->execute([$json, hash('sha256', $json), $user['id'], $cur['id']]);
            else db()->prepare("INSERT INTO india_gst_returns (id,company_id,return_type,period,status,figures_json,figures_hash,prepared_by) VALUES (?,?,?,?,'prepared',?,?,?)")->execute([new_id('gstret'), $companyId, $type, $period, $json, hash('sha256', $json), $user['id']]);
            audit_event($user, $companyId, 'india.gst_return_prepared', 'india_gst_return', $type . ':' . $period, ['returnType' => $type, 'period' => $period, 'figuresHash' => hash('sha256', $json)]);
        } elseif ($action === 'filed') {
            if (!$owner) fail('Only the Company Owner or an Admin marks a return as filed.', 403, 'india_gst_return_role');
            if (!$cur || $cur['status'] !== 'prepared') fail('Prepare the return first, check it, then mark it filed.', 409, 'india_gst_return_not_prepared');
            $arn = strtoupper(trim((string)($input['arn'] ?? ''))); if (!preg_match('/^[A-Z0-9]{10,20}$/', $arn)) fail('Enter the ARN shown on the GST portal (10 to 20 letters and digits).', 422, 'india_gst_arn');
            $filedOn = safe_date((string)($input['filedOn'] ?? ''), 'Filed on'); if ($filedOn < $from) fail('The filing date is before the period starts.', 422, 'india_gst_filed_on');
            db()->prepare("UPDATE india_gst_returns SET status='filed', arn=?, filed_on=?, filed_by=? WHERE id=?")->execute([$arn, $filedOn, $user['id'], $cur['id']]);
            audit_event($user, $companyId, 'india.gst_return_filed', 'india_gst_return', $type . ':' . $period, ['returnType' => $type, 'period' => $period, 'arn' => $arn, 'filedOn' => $filedOn]);
        } elseif ($action === 'reopen') {
            if (!$owner) fail('Only the Company Owner or an Admin reopens a filed return.', 403, 'india_gst_return_role');
            if (!$cur || $cur['status'] !== 'filed') fail('Only a return marked filed can be reopened.', 409, 'india_gst_return_not_filed');
            $reason = trim((string)($input['reason'] ?? '')); if (mb_strlen($reason) < 5) fail('Give the reason for reopening (at least 5 characters).', 422, 'india_gst_reopen_reason');
            db()->prepare("UPDATE india_gst_returns SET status='prepared', filed_by=NULL WHERE id=?")->execute([$cur['id']]);
            audit_event($user, $companyId, 'india.gst_return_reopened', 'india_gst_return', $type . ':' . $period, ['returnType' => $type, 'period' => $period, 'arn' => $cur['arn'], 'reason' => mb_substr($reason, 0, 500)]);
        } else fail('Unknown action.', 422, 'india_gst_return_action');
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['returnType' => $type, 'period' => $period, 'action' => $action];
}

/* ---------- GSTR-2B import and reconciliation ---------- */

function in4_paise(mixed $v, string $what): int
{
    if ($v === null || $v === '') return 0;
    if (!is_int($v) && !is_float($v) && !(is_string($v) && preg_match('/^-?[0-9]{1,13}(\.[0-9]{1,2})?$/', trim($v)))) fail('GSTR-2B file: ' . $what . ' is not an amount.', 422, 'india_2b_value');
    $f = (float)$v; if (abs($f) > 1e13) fail('GSTR-2B file: ' . $what . ' is out of range.', 422, 'india_2b_value');
    return (int)round($f * 100);
}

function in4_2b_date(mixed $v): string
{
    $s = trim((string)$v);
    if (preg_match('/^([0-3][0-9])-([01][0-9])-(20[0-9]{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return $m[3] . '-' . $m[2] . '-' . $m[1];
    if (preg_match('/^(20[0-9]{2})-([01][0-9])-([0-3][0-9])$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $s;
    fail('GSTR-2B file: a document date is not a date (expected DD-MM-YYYY).', 422, 'india_2b_date');
}

/** Parse the GSTR-2B JSON (portal download). Only the B2B invoice and B2B credit/debit note sections are read. */
function in4_2b_parse(string $content): array
{
    if (strlen($content) > IN4_2B_MAX_BYTES) fail('The GSTR-2B file is larger than 5 MB.', 413, 'india_2b_too_large');
    try { $j = json_decode($content, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); } catch (Throwable) { fail('The file is not a GSTR-2B JSON file (it could not be read as JSON).', 422, 'india_2b_not_json'); }
    if (!is_array($j)) fail('The file is not a GSTR-2B JSON file.', 422, 'india_2b_not_json');
    $data = is_array($j['data'] ?? null) ? $j['data'] : $j;
    $doc = $data['docdata'] ?? null;
    if (!is_array($doc)) fail('The file has no "docdata" section, so it is not a GSTR-2B JSON file.', 422, 'india_2b_shape');
    $gstin = strtoupper(trim((string)($data['gstin'] ?? '')));
    $rtn = (string)($data['rtnprd'] ?? '');
    $period = preg_match('/^(0[1-9]|1[0-2])(20[0-9]{2})$/', $rtn, $m) ? $m[2] . '-' . $m[1] : null;
    $lines = [];
    $add = static function (string $section, array $party, array $d, string $type) use (&$lines): void {
        $ctin = strtoupper(trim((string)($party['ctin'] ?? '')));
        if (!preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $ctin)) fail('GSTR-2B file: a supplier GSTIN is not valid.', 422, 'india_2b_gstin');
        $num = trim((string)($d['inum'] ?? $d['ntnum'] ?? ''));
        if ($num === '' || mb_strlen($num) > 40 || preg_match('/[\x00-\x1f]/', $num)) fail('GSTR-2B file: a document number is missing or too long.', 422, 'india_2b_number');
        $t = ['taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0, 'cess' => 0];
        $items = is_array($d['items'] ?? null) ? $d['items'] : [$d];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $t['taxable'] += in4_paise($it['txval'] ?? 0, 'taxable value'); $t['igst'] += in4_paise($it['igst'] ?? 0, 'IGST');
            $t['cgst'] += in4_paise($it['cgst'] ?? 0, 'CGST'); $t['sgst'] += in4_paise($it['sgst'] ?? 0, 'SGST'); $t['cess'] += in4_paise($it['cess'] ?? 0, 'cess');
        }
        $sign = ($type === 'C') ? -1 : 1;
        $pos = trim((string)($d['pos'] ?? '')); $pos = preg_match('/^[0-9]{2}$/', $pos) ? $pos : null;
        $lines[] = ['section' => $section, 'gstin' => $ctin, 'name' => mb_substr(trim(preg_replace('/[\x00-\x1f]/', ' ', (string)($party['trdnm'] ?? ''))), 0, 200), 'type' => $type,
            'number' => $num, 'key' => in4_number_key($num), 'date' => in4_2b_date($d['dt'] ?? $d['ntdt'] ?? ''), 'pos' => $pos,
            'rcm' => strtoupper((string)($d['rev'] ?? 'N')) === 'Y' ? 1 : 0, 'itc' => strtoupper((string)($d['itcavl'] ?? 'Y')) === 'N' ? 0 : 1,
            'value' => $sign * in4_paise($d['val'] ?? 0, 'document value'), 'taxable' => $sign * $t['taxable'], 'igst' => $sign * $t['igst'], 'cgst' => $sign * $t['cgst'], 'sgst' => $sign * $t['sgst'], 'cess' => $sign * $t['cess']];
        if (count($lines) > IN4_2B_MAX_LINES) fail('The GSTR-2B file has more than ' . IN4_2B_MAX_LINES . ' documents.', 422, 'india_2b_too_many');
    };
    foreach ((array)($doc['b2b'] ?? []) as $p) { if (!is_array($p)) continue; foreach ((array)($p['inv'] ?? []) as $d) if (is_array($d)) $add('B2B', $p, $d, 'I'); }
    foreach ((array)($doc['cdnr'] ?? []) as $p) { if (!is_array($p)) continue; foreach ((array)($p['nt'] ?? []) as $d) if (is_array($d)) $add('CDNR', $p, $d, strtoupper((string)($d['typ'] ?? $d['ntty'] ?? 'C')) === 'D' ? 'D' : 'C'); }
    return ['gstin' => preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $gstin) ? $gstin : null, 'period' => $period, 'lines' => $lines];
}

function in4_2b_import(array $user, array $company, array $input): array
{
    if (!in_array((string)$company['role'], ['owner', 'admin', 'accountant', 'editor'], true)) fail('You cannot import GSTR-2B in this company.', 403, 'india_2b_role');
    $companyId = (string)$company['id'];
    $content = (string)($input['content'] ?? '');
    $name = mb_substr(trim(preg_replace('/[^\w .()-]/u', '_', (string)($input['fileName'] ?? 'gstr2b.json'))), 0, 200) ?: 'gstr2b.json';
    $parsed = in4_2b_parse($content);
    $period = (string)($input['period'] ?? '') ?: (string)$parsed['period'];
    in4_month_range($period);
    if ($parsed['period'] !== null && $parsed['period'] !== $period) fail('The file is for ' . $parsed['period'] . ', not ' . $period . '.', 422, 'india_2b_period');
    $own = in3_company_gstin($company);
    if ($parsed['gstin'] !== null && $own !== null && $parsed['gstin'] !== $own) fail('The file is for GSTIN ' . $parsed['gstin'] . ', not this company\'s GSTIN ' . $own . '.', 422, 'india_2b_gstin_other');
    in4_schema_ready();
    $sha = hash('sha256', $content); $id = new_id('gstr2b');
    db()->beginTransaction();
    try {
        db()->prepare("UPDATE india_gstr2b_imports SET status='replaced' WHERE company_id=? AND period=? AND status='active'")->execute([$companyId, $period]);
        db()->prepare("INSERT INTO india_gstr2b_imports (id,company_id,period,gstin,file_name,sha256,line_count,imported_by) VALUES (?,?,?,?,?,?,?,?)")->execute([$id, $companyId, $period, $parsed['gstin'], $name, $sha, count($parsed['lines']), $user['id']]);
        $ins = db()->prepare('INSERT INTO india_gstr2b_lines (id,company_id,import_id,section,supplier_gstin,supplier_name,document_type,document_number,number_key,document_date,place_of_supply,reverse_charge,itc_available,value_cents,taxable_cents,igst_cents,cgst_cents,sgst_cents,cess_cents) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($parsed['lines'] as $l) $ins->execute([new_id('gstr2bl'), $companyId, $id, $l['section'], $l['gstin'], $l['name'], $l['type'], $l['number'], $l['key'], $l['date'], $l['pos'], $l['rcm'], $l['itc'], $l['value'], $l['taxable'], $l['igst'], $l['cgst'], $l['sgst'], $l['cess']]);
        audit_event($user, $companyId, 'india.gstr2b_imported', 'india_gstr2b_import', $id, ['period' => $period, 'fileName' => $name, 'sha256' => $sha, 'documents' => count($parsed['lines'])]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['id' => $id, 'period' => $period, 'documents' => count($parsed['lines'])];
}

/**
 * Reconcile the active GSTR-2B of a period with the purchase register.
 * Books: vendor invoices and vendor notes with a GST code, dated from the start of the financial year to the period end.
 * Match key: supplier GSTIN + normalised document number. Amounts agree when each head differs by at most ₹1.
 */
function in4_2b_reconcile(array $company, string $period): array
{
    $companyId = (string)$company['id'];
    [$pFrom, $pTo] = in4_month_range($period);
    $imp = null;
    if (schema_table_exists('india_gstr2b_imports')) { $q = db()->prepare("SELECT * FROM india_gstr2b_imports WHERE company_id=? AND period=? AND status='active'"); $q->execute([$companyId, $period]); $imp = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
    $fyStart = ((int)substr($period, 5, 2) >= 4 ? substr($period, 0, 4) : (string)((int)substr($period, 0, 4) - 1)) . '-04-01';
    $books = [];
    foreach (in4_documents($companyId, $fyStart, $pTo) as $d) {
        if ($d['side'] !== 'purchase' || !$d['codes']) continue;
        $t = ['taxable' => 0] + in4_zero_heads();
        foreach ($d['codes'] as $c) { $t['taxable'] += $c['taxableCents']; in4_add_heads($t, $c['tax']); in4_add_heads($t, $c['notClaimed']); }
        $num = $d['kind'] === 'bill' ? $d['number'] : (string)($d['number']);
        $books[] = ['id' => $d['id'], 'kind' => $d['kind'], 'number' => $num, 'date' => $d['date'], 'party' => $d['partyName'], 'gstin' => $d['partyGstin'], 'key' => ($d['partyGstin'] ?? '') . '|' . in4_number_key($num),
            'taxableCents' => $t['taxable'], 'igstCents' => $t['IGST'], 'cgstCents' => $t['CGST'], 'sgstCents' => $t['SGST'] + $t['UTGST'], 'cessCents' => $t['CESS'], 'inPeriod' => $d['date'] >= $pFrom];
    }
    $byKey = []; foreach ($books as $i => $b) $byKey[$b['key']][] = $i;
    $used = [];
    $rows = [];
    $counts = ['matched' => 0, 'mismatch' => 0, 'only2b' => 0, 'onlyBooks' => 0, 'noGstin' => 0];
    if ($imp) {
        $q = db()->prepare('SELECT * FROM india_gstr2b_lines WHERE import_id=? ORDER BY supplier_gstin, document_date, document_number'); $q->execute([$imp['id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $k = $l['supplier_gstin'] . '|' . $l['number_key'];
            $match = null; foreach ($byKey[$k] ?? [] as $i) if (!isset($used[$i])) { $match = $i; break; }
            $two = ['number' => $l['document_number'], 'date' => $l['document_date'], 'gstin' => $l['supplier_gstin'], 'party' => $l['supplier_name'], 'type' => $l['document_type'], 'itcAvailable' => (bool)$l['itc_available'], 'reverseCharge' => (bool)$l['reverse_charge'],
                'taxableCents' => (int)$l['taxable_cents'], 'igstCents' => (int)$l['igst_cents'], 'cgstCents' => (int)$l['cgst_cents'], 'sgstCents' => (int)$l['sgst_cents'], 'cessCents' => (int)$l['cess_cents']];
            if ($match === null) { $rows[] = ['status' => 'only2b', 'gstr2b' => $two, 'books' => null, 'differences' => []]; $counts['only2b']++; continue; }
            $used[$match] = true; $b = $books[$match];
            $diff = [];
            foreach (['taxableCents', 'igstCents', 'cgstCents', 'sgstCents', 'cessCents'] as $f) if (abs($two[$f] - $b[$f]) > 100) $diff[$f] = $two[$f] - $b[$f];
            if ($two['date'] !== $b['date']) $diff['date'] = $two['date'];
            $status = $diff ? 'mismatch' : 'matched'; $counts[$status]++;
            $rows[] = ['status' => $status, 'gstr2b' => $two, 'books' => $b, 'differences' => $diff];
        }
    }
    foreach ($books as $i => $b) {
        if (isset($used[$i]) || !$b['inPeriod']) continue;
        if ($b['gstin'] === null) { $counts['noGstin']++; $rows[] = ['status' => 'noGstin', 'gstr2b' => null, 'books' => $b, 'differences' => []]; continue; }
        $counts['onlyBooks']++; $rows[] = ['status' => 'onlyBooks', 'gstr2b' => null, 'books' => $b, 'differences' => []];
    }
    return ['period' => $period, 'import' => $imp ? ['id' => $imp['id'], 'fileName' => $imp['file_name'], 'documents' => (int)$imp['line_count'], 'importedAt' => $imp['created_at'], 'sha256' => $imp['sha256']] : null,
        'counts' => $counts, 'rows' => $rows,
        'note' => 'Matched on supplier GSTIN and document number (letters and digits only, leading zeros ignored). Amounts agree when each figure differs by at most ₹1. Vendor invoices are looked for from the start of the financial year; "Only in books" lists vendor invoices dated in this period that are not in this GSTR-2B.'];
}

/* ---------- routes (called from handle_india_gst after the edition check) ---------- */

function in4_route(array $user, array $company, string $method, string $action): bool
{
    if ($method === 'GET' && in_array($action, ['gstr1', 'gstr3b', 'register', 'ledger'], true)) {
        require_company_permission($company, 'reports.view');
        [$from, $to] = in4_period_input();
        in3_schema_ready();
        $out = match ($action) {
            'gstr1' => in4_gstr1($company, $from, $to),
            'gstr3b' => in4_gstr3b($company, $from, $to),
            'register' => in4_register($company, $from, $to, ($_GET['side'] ?? '') === 'sales' ? 'sales' : 'purchase'),
            'ledger' => in4_ledger($company, $from, $to),
        };
        json_response($out);
    }
    if ($method === 'GET' && $action === 'periods') {
        require_company_permission($company, 'reports.view');
        in4_schema_ready();
        json_response(in4_periods($company, (string)($_GET['fy'] ?? '')));
    }
    if ($method === 'POST' && $action === 'periods') {
        require_csrf();
        json_response(in4_period_action($user, $company, request_json()));
    }
    if ($method === 'GET' && $action === 'gstr2b') {
        require_company_permission($company, 'reports.view');
        in4_schema_ready();
        json_response(in4_2b_reconcile($company, (string)($_GET['period'] ?? '')));
    }
    if ($method === 'POST' && $action === 'gstr2b') {
        require_csrf();
        json_response(in4_2b_import($user, $company, request_json()), 201);
    }
    return false;
}
