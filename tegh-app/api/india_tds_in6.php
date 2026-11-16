<?php
declare(strict_types=1);

/*
 * IN-6: TDS on vendor invoices and TCS on customer invoices for India companies (user-defined sections),
 * the India setup checklist and dashboard figures.
 * - Sections are statutory rules (India Masters › Statutory rules, categories tds_section / tcs_section) approved by the Owner or an Admin:
 *   rate (%), "threshold from" = the single-document limit, "threshold to" = the yearly limit per party. Tegh supplies no section or rate.
 *   A party without a PAN uses the approved rule "<section>-NOPAN" when it exists (section 206AA / 206CC); otherwise deduction is refused.
 *   A lower or nil deduction certificate (India Masters › Records) valid on the date and within its limit replaces the rate.
 * - TDS is an explicit step on a posted vendor invoice: one journal (Dr payables, Cr TDS Payable 2330) and the invoice balance falls by the TDS.
 *   When the yearly limit is first crossed, earlier invoices of the year under the same section that had no TDS are added to the base.
 * - TCS is an explicit step on an issued customer invoice: Tegh issues a separate receivable document "TCS-<invoice number>"
 *   (Dr receivables, Cr TCS Payable 2340) that the customer pays with the invoice. It is not a supply, so GST reports leave it out.
 * - The original invoice cannot be voided while its TDS or TCS stands; reversing the TDS (or voiding the TCS document) comes first.
 * Canadian companies never reach this file (404 india_only).
 */

const IN6_ACCOUNTS = ['tds' => ['2330', 'TDS Payable'], 'tcs' => ['2340', 'TCS Payable']];

function in6_schema_ready(): bool
{
    static $ready = false;
    if ($ready) return true;
    if (schema_table_exists('india_tds_entries')) return $ready = true;
    if (db()->inTransaction()) return false;
    db()->exec("CREATE TABLE IF NOT EXISTS india_tds_entries (
        id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, kind ENUM('tds','tcs') NOT NULL, section_key VARCHAR(120) NOT NULL,
        document_type ENUM('bill','invoice') NOT NULL, document_id VARCHAR(64) NOT NULL, party_id VARCHAR(64) NOT NULL, party_pan VARCHAR(10) NULL,
        deduction_date DATE NOT NULL, base_cents BIGINT NOT NULL, rate_mpct INT NOT NULL, amount_cents BIGINT NOT NULL, rate_source VARCHAR(40) NOT NULL,
        catch_up_json LONGTEXT NOT NULL, certificate_id VARCHAR(64) NULL, journal_entry_id VARCHAR(64) NULL, tcs_invoice_id VARCHAR(64) NULL,
        status ENUM('active','reversed') NOT NULL DEFAULT 'active', reversal_journal_entry_id VARCHAR(64) NULL, reversal_reason VARCHAR(500) NULL,
        created_by VARCHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY india_tds_entries_doc_idx (company_id, document_type, document_id, status), KEY india_tds_entries_section_idx (company_id, kind, section_key, deduction_date),
        CONSTRAINT fk_india_tds_entries_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $ready = true;
}

/** Latest India profile of a party on a date (PAN, TDS/TCS section). */
function in6_profile(string $companyId, string $entityType, string $entityId, string $date): array
{
    if (!schema_table_exists('india_entity_profiles')) return [];
    $q = db()->prepare('SELECT data_json FROM india_entity_profiles WHERE company_id=? AND entity_type=? AND entity_id=? AND effective_from<=? ORDER BY effective_from DESC LIMIT 1');
    $q->execute([$companyId, $entityType, $entityId, $date]);
    $d = json_decode((string)($q->fetchColumn() ?: ''), true);
    return is_array($d) ? $d : [];
}

function in6_fy_start(string $date): string { $y = (int)substr($date, 0, 4); return ((int)substr($date, 5, 2) >= 4 ? $y : $y - 1) . '-04-01'; }

/** Quarter of the financial year (Q1 April–June … Q4 January–March), as used for TDS returns. */
function in6_quarter(string $date): string { $m = (int)substr($date, 5, 2); $q = $m >= 4 ? intdiv($m - 4, 3) + 1 : 4; $s = (int)substr(in6_fy_start($date), 0, 4); return sprintf('%d-%02d Q%d', $s, ($s + 1) % 100, $q); }

function in6_rule(string $companyId, string $kind, string $code, string $date): ?array
{
    $q = db()->prepare("SELECT * FROM statutory_rates WHERE company_id=? AND jurisdiction='IN' AND person_type=? AND rate_key=? AND status='active' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC LIMIT 1");
    $q->execute([$companyId, $kind === 'tds' ? 'tds_section' : 'tcs_section', $code, $date, $date]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Valid lower/nil deduction certificate of the party for the section on the date, with what is left of its limit. */
function in6_certificate(string $companyId, string $entityType, string $partyId, string $section, string $date): ?array
{
    if (!schema_table_exists('india_master_records')) return null;
    $q = db()->prepare("SELECT id, data_json FROM india_master_records WHERE company_id=? AND record_type='tds_certificate' AND entity_type=? AND entity_id=? AND status='active' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)");
    $q->execute([$companyId, $entityType, $partyId, $date, $date]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = json_decode((string)$r['data_json'], true);
        if (!is_array($d) || (string)($d['section'] ?? '') !== $section || (string)($d['validFrom'] ?? '') > $date || (string)($d['validTo'] ?? '9999-12-31') < $date) continue;
        $used = 0;
        if (schema_table_exists('india_tds_entries')) { $u = db()->prepare("SELECT COALESCE(SUM(base_cents),0) FROM india_tds_entries WHERE company_id=? AND certificate_id=? AND status='active'"); $u->execute([$companyId, $r['id']]); $used = (int)$u->fetchColumn(); }
        $limit = isset($d['limitCents']) && $d['limitCents'] !== '' && $d['limitCents'] !== null ? (int)$d['limitCents'] : null;
        return ['id' => (string)$r['id'], 'number' => (string)($d['certificateNumber'] ?? ''), 'rateMpct' => (int)round((float)($d['ratePercent'] ?? 0) * 1000), 'remainingCents' => $limit === null ? null : max(0, $limit - $used)];
    }
    return null;
}

/** The document (posted vendor invoice or issued customer invoice) with its party. */
function in6_document(string $companyId, string $kind, string $documentId, bool $lock = false): array
{
    $sql = $kind === 'tds'
        ? 'SELECT b.id, b.number, b.bill_date doc_date, b.status, b.subtotal_cents, b.total_cents, b.balance_cents, b.foreign_balance_cents, b.currency, b.vendor_id party_id, v.name party FROM bills b JOIN vendors v ON v.id=b.vendor_id WHERE b.company_id=? AND b.id=?'
        : 'SELECT i.id, i.number, i.issue_date doc_date, i.status, i.subtotal_cents, i.total_cents, i.balance_cents, i.foreign_balance_cents, i.currency, i.customer_id party_id, c.name party FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.company_id=? AND i.id=?';
    $q = db()->prepare($sql . ($lock ? ' FOR UPDATE' : '')); $q->execute([$companyId, $documentId]);
    $d = $q->fetch(PDO::FETCH_ASSOC);
    if (!$d) fail($kind === 'tds' ? 'Vendor invoice not found.' : 'Customer invoice not found.', 404, 'india_tds_document');
    return $d;
}

/**
 * Work out the TDS (or TCS) on one document without posting it.
 * Base = the document's value before GST (subtotal). Returns the amount, the rate and where it came from, and the earlier documents added by the yearly limit.
 */
function in6_compute(string $companyId, string $kind, string $documentId, ?string $section, ?string $date): array
{
    $doc = in6_document($companyId, $kind, $documentId);
    $entity = $kind === 'tds' ? 'vendor' : 'customer';
    $date = $date ?: (string)$doc['doc_date'];
    $profile = in6_profile($companyId, $entity, (string)$doc['party_id'], $date);
    $section = $section ?: (string)($profile[$kind === 'tds' ? 'tdsSection' : 'tcsSection'] ?? '');
    $out = ['kind' => $kind, 'documentId' => $doc['id'], 'number' => $doc['number'], 'date' => $doc['doc_date'], 'party' => $doc['party'], 'partyId' => $doc['party_id'], 'section' => $section, 'pan' => $profile['pan'] ?? null,
        'baseCents' => (int)$doc['subtotal_cents'], 'rateMpct' => 0, 'amountCents' => 0, 'rateSource' => '', 'catchUp' => [], 'due' => false, 'reason' => '', 'certificate' => null];
    if ($section === '') { $out['reason'] = 'No ' . strtoupper($kind) . ' section on this ' . $entity . ' (India Masters › Profiles) and none chosen.'; return $out; }
    if ($kind === 'tds' && !in_array((string)$doc['status'], ['open', 'paid'], true)) { $out['reason'] = 'The vendor invoice is not posted.'; return $out; }
    if ($kind === 'tcs' && !in_array((string)$doc['status'], ['sent', 'paid'], true)) { $out['reason'] = 'The customer invoice is not issued.'; return $out; }
    $rule = in6_rule($companyId, $kind, $section, $date);
    if (!$rule) { $out['reason'] = 'No approved ' . strtoupper($kind) . ' section rule ' . $section . ' is in force on ' . $date . ' (India Masters › Statutory rules).'; return $out; }
    $rate = (int)round((float)$rule['value_decimal'] * 1000); $source = 'section ' . $section;
    $pan = strtoupper((string)($profile['pan'] ?? ''));
    if ($pan === '') {
        $np = in6_rule($companyId, $kind, $section . '-NOPAN', $date);
        if (!$np) { $out['reason'] = 'The ' . $entity . ' has no PAN. Add the PAN to its India profile, or add and approve the rule ' . $section . '-NOPAN with the rate for parties without a PAN.'; return $out; }
        $rate = (int)round((float)$np['value_decimal'] * 1000); $source = 'no PAN (' . $section . '-NOPAN)';
    }
    // Yearly limit: documents of the financial year for the same party and section.
    $fy = in6_fy_start($date);
    $single = $rule['threshold_min_cents'] !== null ? (int)$rule['threshold_min_cents'] : null;
    $yearly = $rule['threshold_max_cents'] !== null ? (int)$rule['threshold_max_cents'] : null;
    $base = (int)$doc['subtotal_cents'];
    $prior = []; $priorTotal = 0;
    $docs = $kind === 'tds'
        ? db()->prepare("SELECT id, number, bill_date d, subtotal_cents s FROM bills WHERE company_id=? AND vendor_id=? AND status IN ('open','paid') AND bill_date BETWEEN ? AND ? AND id<>? ORDER BY bill_date, number")
        : db()->prepare("SELECT id, number, issue_date d, subtotal_cents s FROM invoices WHERE company_id=? AND customer_id=? AND status IN ('sent','paid') AND issue_date BETWEEN ? AND ? AND id<>? AND number NOT LIKE 'TCS-%' ORDER BY issue_date, number");
    $docs->execute([$companyId, $doc['party_id'], $fy, $date, $doc['id']]);
    $taken = [];
    if (schema_table_exists('india_tds_entries')) {
        $t = db()->prepare("SELECT e.document_id, e.catch_up_json FROM india_tds_entries e LEFT JOIN invoices v ON v.id=e.tcs_invoice_id WHERE e.company_id=? AND e.kind=? AND e.section_key=? AND e.party_id=? AND e.status='active' AND (e.tcs_invoice_id IS NULL OR v.status<>'void') AND e.deduction_date>=?");
        $t->execute([$companyId, $kind, $section, $doc['party_id'], $fy]);
        foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $x) { $taken[$x['document_id']] = true; foreach ((json_decode((string)$x['catch_up_json'], true) ?: []) as $c) $taken[$c['documentId']] = true; }
    }
    foreach ($docs->fetchAll(PDO::FETCH_ASSOC) as $p) { $priorTotal += (int)$p['s']; if (!isset($taken[$p['id']])) $prior[] = ['documentId' => $p['id'], 'number' => $p['number'], 'date' => $p['d'], 'baseCents' => (int)$p['s']]; }
    $overSingle = $single === null || $base > $single;
    $overYear = $yearly !== null && $priorTotal + $base > $yearly;
    if (!$overSingle && !$overYear) { $out['reason'] = 'Below the limits of section ' . $section . ': this document ' . number_format($base / 100, 2) . ' (limit ' . number_format(($single ?? 0) / 100, 2) . '), the year ' . number_format(($priorTotal + $base) / 100, 2) . ($yearly !== null ? ' (limit ' . number_format($yearly / 100, 2) . ')' : '') . '. Nothing to deduct.'; return $out + ['priorYearCents' => $priorTotal]; }
    $catch = $overYear && $yearly !== null && $priorTotal <= $yearly ? $prior : [];
    if ($overYear && $priorTotal > $yearly) $catch = []; // limit crossed earlier: earlier documents were dealt with then
    $fullBase = $base + array_sum(array_column($catch, 'baseCents'));
    $cert = in6_certificate($companyId, $entity, (string)$doc['party_id'], $section, $date);
    if ($cert && ($cert['remainingCents'] === null || $cert['remainingCents'] >= $fullBase)) { $rate = $cert['rateMpct']; $source = 'certificate ' . $cert['number']; $out['certificate'] = $cert; }
    $amount = (int)round($fullBase * $rate / 100000);
    return array_merge($out, ['baseCents' => $fullBase, 'rateMpct' => $rate, 'amountCents' => $amount, 'rateSource' => $source, 'catchUp' => $catch, 'due' => $amount > 0, 'priorYearCents' => $priorTotal,
        'reason' => $amount > 0 ? '' : 'The rate is nil (' . $source . ').']);
}

function in6_account(string $companyId, string $kind): string
{
    [$code, $name] = IN6_ACCOUNTS[$kind];
    $q = db()->prepare('SELECT id, account_type FROM accounts WHERE company_id=? AND code=?'); $q->execute([$companyId, $code]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) { if ((string)$row['account_type'] !== 'liability') fail('Account ' . $code . ' is not a liability account, so it cannot hold ' . $name . '.', 409, 'india_tds_account_conflict'); return (string)$row['id']; }
    tax_ensure_account($companyId, $code, $name, 'liability', 'credit', null);
    $q->execute([$companyId, $code]);
    return (string)$q->fetchColumn();
}

function in6_post(array $user, array $company, array $input): array
{
    $kind = (string)($input['kind'] ?? '') === 'tcs' ? 'tcs' : 'tds';
    require_company_permission($company, $kind === 'tds' ? 'bills.write' : 'invoices.write');
    $companyId = (string)$company['id'];
    $documentId = clean_text($input['documentId'] ?? '', 'Document', 64);
    $section = trim((string)($input['section'] ?? '')) ?: null;
    $date = trim((string)($input['date'] ?? '')); $date = $date === '' ? null : safe_date($date, 'Date');
    in6_schema_ready();
    $c = in6_compute($companyId, $kind, $documentId, $section, $date);
    if (!$c['due']) fail($c['reason'] ?: 'Nothing to deduct.', 422, 'india_tds_not_due');
    $date ??= (string)$c['date'];
    if ($date < (string)$c['date']) fail('The date is before the document date.', 422, 'india_tds_date');
    assert_not_future_date($date, 'Date');
    $account = in6_account($companyId, $kind);
    $ctrl = note_control_account_id($companyId, $kind === 'tcs');
    $id = new_id('indtds');
    db()->beginTransaction();
    try {
        $doc = in6_document($companyId, $kind, $documentId, true);
        $dup = db()->prepare("SELECT COUNT(*) FROM india_tds_entries e LEFT JOIN invoices t ON t.id=e.tcs_invoice_id WHERE e.company_id=? AND e.document_id=? AND e.kind=? AND e.status='active' AND (e.tcs_invoice_id IS NULL OR t.status<>'void')"); $dup->execute([$companyId, $documentId, $kind]);
        if ((int)$dup->fetchColumn() > 0) fail(strtoupper($kind) . ' is already recorded on this document.', 409, 'india_tds_exists');
        $tcsInvoice = null;
        if ($kind === 'tds') {
            if ((string)$doc['currency'] !== 'INR') fail('TDS is recorded on rupee vendor invoices only.', 422, 'india_tds_currency');
            if ((int)$doc['balance_cents'] < $c['amountCents']) fail('The TDS (' . number_format($c['amountCents'] / 100, 2) . ') is more than the unpaid balance of the vendor invoice. Record TDS before paying the vendor in full.', 409, 'india_tds_balance');
            $je = add_journal_entry($user, $companyId, $date, 'india_tds', $id, 'TDS u/s ' . $c['section'] . ' on vendor invoice ' . $doc['number'] . ' · ' . $doc['party'], [
                ['accountId' => $ctrl, 'debitCents' => $c['amountCents'], 'creditCents' => 0, 'memo' => 'TDS deducted · ' . $doc['number']],
                ['accountId' => $account, 'debitCents' => 0, 'creditCents' => $c['amountCents'], 'memo' => 'TDS u/s ' . $c['section'] . ' · ' . $doc['party']]]);
            $remaining = (int)$doc['balance_cents'] - $c['amountCents'];
            db()->prepare("UPDATE bills SET balance_cents=?, foreign_balance_cents=GREATEST(0,foreign_balance_cents-?), status=? WHERE id=? AND company_id=?")->execute([$remaining, $c['amountCents'], $remaining === 0 ? 'paid' : 'open', $documentId, $companyId]);
        } else {
            if ((string)$doc['currency'] !== 'INR') fail('TCS is recorded on rupee customer invoices only.', 422, 'india_tcs_currency');
            // A separate receivable document that the customer pays with the invoice.
            $tcsInvoice = new_id('invoice'); $number = mb_substr('TCS-' . $doc['number'], 0, 60);
            $je = add_journal_entry($user, $companyId, $date, 'invoice', $tcsInvoice, 'TCS u/s ' . $c['section'] . ' on customer invoice ' . $doc['number'] . ' · ' . $doc['party'], [
                ['accountId' => $ctrl, 'debitCents' => $c['amountCents'], 'creditCents' => 0, 'memo' => 'TCS collectible · ' . $number],
                ['accountId' => $account, 'debitCents' => 0, 'creditCents' => $c['amountCents'], 'memo' => 'TCS u/s ' . $c['section'] . ' · ' . $doc['party']]]);
            db()->prepare("INSERT INTO invoices (id,company_id,customer_id,number,issue_date,due_date,status,subtotal_cents,gst_hst_cents,pst_cents,tax_cents,tax_entry_mode,total_cents,balance_cents,message,currency,exchange_rate_micros,foreign_subtotal_cents,foreign_tax_cents,foreign_total_cents,foreign_balance_cents,issued_journal_entry_id)
                VALUES (?,?,?,?,?,?,'sent',?,0,0,0,'none',?,?,?,'INR',1000000,?,0,?,?,?)")
                ->execute([$tcsInvoice, $companyId, $doc['party_id'], $number, $date, $date, $c['amountCents'], $c['amountCents'], $c['amountCents'], 'Tax collected at source u/s ' . $c['section'] . ' on invoice ' . $doc['number'] . ' (' . number_format($c['rateMpct'] / 1000, 3) . '% of ' . number_format($c['baseCents'] / 100, 2) . ')', $c['amountCents'], $c['amountCents'], $c['amountCents'], $je]);
            db()->prepare('INSERT INTO invoice_lines (id,invoice_id,income_account_id,description,quantity_milli,unit_price_cents,tax_rate_bps,amount_cents,tax_cents,foreign_unit_price_cents,foreign_amount_cents,foreign_tax_cents) VALUES (?,?,?,?,1000,?,0,?,0,?,?,0)')
                ->execute([new_id('iline'), $tcsInvoice, $account, 'TCS u/s ' . $c['section'] . ' on ' . $doc['number'], $c['amountCents'], $c['amountCents'], $c['amountCents'], $c['amountCents']]);
        }
        db()->prepare('INSERT INTO india_tds_entries (id,company_id,kind,section_key,document_type,document_id,party_id,party_pan,deduction_date,base_cents,rate_mpct,amount_cents,rate_source,catch_up_json,certificate_id,journal_entry_id,tcs_invoice_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $companyId, $kind, $c['section'], $kind === 'tds' ? 'bill' : 'invoice', $documentId, $doc['party_id'], $c['pan'] ? strtoupper((string)$c['pan']) : null, $date, $c['baseCents'], $c['rateMpct'], $c['amountCents'], mb_substr($c['rateSource'], 0, 40), json_encode($c['catchUp']), $c['certificate']['id'] ?? null, $je, $tcsInvoice, $user['id']]);
        audit_event($user, $companyId, 'india.' . $kind . '_recorded', 'india_tds_entry', $id, ['section' => $c['section'], 'documentId' => $documentId, 'baseCents' => $c['baseCents'], 'rateMpct' => $c['rateMpct'], 'amountCents' => $c['amountCents'], 'rateSource' => $c['rateSource'], 'catchUp' => array_column($c['catchUp'], 'number'), 'journalEntryId' => $je]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['id' => $id, 'kind' => $kind, 'amountCents' => $c['amountCents'], 'journalEntryId' => $je, 'tcsInvoiceId' => $tcsInvoice] + $c;
}

/** Reverse TDS (TCS is undone by voiding its TCS document in the ordinary way, which this also does). */
function in6_reverse(array $user, array $company, array $input): array
{
    if (!in_array((string)$company['role'], ['owner', 'admin'], true)) fail('Only the Company Owner or an Admin reverses TDS or TCS.', 403, 'india_tds_role');
    $companyId = (string)$company['id']; $id = clean_text($input['id'] ?? '', 'Entry', 64);
    $reason = trim((string)($input['reason'] ?? '')); if (mb_strlen($reason) < 5) fail('Give the reason for reversing (at least 5 characters).', 422, 'india_tds_reason');
    in6_schema_ready();
    db()->beginTransaction();
    try {
        $q = db()->prepare("SELECT * FROM india_tds_entries WHERE company_id=? AND id=? FOR UPDATE"); $q->execute([$companyId, $id]); $e = $q->fetch(PDO::FETCH_ASSOC);
        if (!$e || $e['status'] !== 'active') fail('Only recorded TDS or TCS can be reversed.', 409, 'india_tds_not_active');
        $date = safe_date((string)($input['date'] ?? '') ?: (string)$e['deduction_date'], 'Reversal date');
        if ($date < (string)$e['deduction_date']) fail('The reversal date is before the deduction date.', 422, 'india_tds_date');
        if ($e['kind'] === 'tds') {
            $b = db()->prepare('SELECT balance_cents, total_cents FROM bills WHERE company_id=? AND id=? FOR UPDATE'); $b->execute([$companyId, $e['document_id']]); $bill = $b->fetch(PDO::FETCH_ASSOC);
            if (!$bill) fail('Vendor invoice not found.', 404, 'india_tds_document');
            if ((int)$bill['balance_cents'] + (int)$e['amount_cents'] > (int)$bill['total_cents']) fail('The vendor invoice balance changed; it cannot take the TDS back.', 409, 'india_tds_balance');
            require_journal_unmatched_before_reversal($companyId, (string)$e['journal_entry_id'], 'TDS entry');
            $rev = add_reversing_journal_entry($user, $companyId, (string)$e['journal_entry_id'], $date, 'india_tds_reversal', $id, 'Reversal of TDS u/s ' . $e['section_key'] . ': ' . mb_substr($reason, 0, 200));
            db()->prepare("UPDATE bills SET balance_cents=balance_cents+?, foreign_balance_cents=LEAST(foreign_total_cents,foreign_balance_cents+?), status='open' WHERE company_id=? AND id=?")->execute([(int)$e['amount_cents'], (int)$e['amount_cents'], $companyId, $e['document_id']]);
        } else {
            $i = db()->prepare('SELECT status, balance_cents, total_cents FROM invoices WHERE company_id=? AND id=? FOR UPDATE'); $i->execute([$companyId, $e['tcs_invoice_id']]); $inv = $i->fetch(PDO::FETCH_ASSOC);
            if (!$inv) fail('TCS document not found.', 404, 'india_tds_document');
            if ((int)$inv['balance_cents'] !== (int)$inv['total_cents'] && $inv['status'] !== 'void') fail('The customer has paid the TCS document. Reverse that payment first.', 409, 'india_tcs_paid');
            $rev = null;
            if ($inv['status'] !== 'void') {
                $rev = add_reversing_journal_entry($user, $companyId, (string)$e['journal_entry_id'], $date, 'india_tds_reversal', $id, 'Reversal of TCS u/s ' . $e['section_key'] . ': ' . mb_substr($reason, 0, 200));
                db()->prepare("UPDATE invoices SET status='void', balance_cents=0, foreign_balance_cents=0 WHERE company_id=? AND id=?")->execute([$companyId, $e['tcs_invoice_id']]);
            }
        }
        db()->prepare("UPDATE india_tds_entries SET status='reversed', reversal_journal_entry_id=?, reversal_reason=? WHERE id=?")->execute([$rev, mb_substr($reason, 0, 500), $id]);
        audit_event($user, $companyId, 'india.' . $e['kind'] . '_reversed', 'india_tds_entry', $id, ['section' => $e['section_key'], 'documentId' => $e['document_id'], 'amountCents' => (int)$e['amount_cents'], 'reason' => mb_substr($reason, 0, 500), 'reversalJournalEntryId' => $rev]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['id' => $id, 'status' => 'reversed'];
}

/** Documents of the period whose party has a section, with what would be deducted (nothing is posted). */
function in6_pending(array $company, string $kind, string $from, string $to): array
{
    $companyId = (string)$company['id'];
    $sql = $kind === 'tds'
        ? "SELECT id FROM bills WHERE company_id=? AND status IN ('open','paid') AND bill_date BETWEEN ? AND ? AND currency='INR' ORDER BY bill_date, number"
        : "SELECT id FROM invoices WHERE company_id=? AND status IN ('sent','paid') AND issue_date BETWEEN ? AND ? AND currency='INR' AND number NOT LIKE 'TCS-%' ORDER BY issue_date, number";
    $q = db()->prepare($sql); $q->execute([$companyId, $from, $to]);
    $done = [];
    if (schema_table_exists('india_tds_entries')) { $d = db()->prepare("SELECT e.document_id, e.catch_up_json FROM india_tds_entries e LEFT JOIN invoices t ON t.id=e.tcs_invoice_id WHERE e.company_id=? AND e.kind=? AND e.status='active' AND (e.tcs_invoice_id IS NULL OR t.status<>'void')"); $d->execute([$companyId, $kind]); foreach ($d->fetchAll(PDO::FETCH_ASSOC) as $x) { $done[$x['document_id']] = 'recorded'; foreach ((json_decode((string)$x['catch_up_json'], true) ?: []) as $c) $done[$c['documentId']] = 'included in a later deduction'; } }
    $rows = [];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (isset($done[$id])) continue;
        $c = in6_compute($companyId, $kind, (string)$id, null, null);
        if ($c['section'] === '') continue;
        $rows[] = $c;
    }
    return ['kind' => $kind, 'from' => $from, 'to' => $to, 'rows' => $rows];
}

/** TDS/TCS register for a period: entries by section and quarter, with the deductee PAN; totals equal the TDS/TCS Payable credits. */
function in6_register(array $company, string $kind, string $from, string $to): array
{
    $companyId = (string)$company['id'];
    $rows = []; $bySection = [];
    if (schema_table_exists('india_tds_entries')) {
        $party = $kind === 'tds' ? 'vendors' : 'customers';
        $q = db()->prepare("SELECT e.*, p.name party, COALESCE(b.number, i.number) doc_number FROM india_tds_entries e JOIN `$party` p ON p.id=e.party_id LEFT JOIN bills b ON b.id=e.document_id AND e.document_type='bill' LEFT JOIN invoices i ON i.id=e.document_id AND e.document_type='invoice'
            LEFT JOIN invoices t ON t.id=e.tcs_invoice_id
            WHERE e.company_id=? AND e.kind=? AND e.status='active' AND (e.tcs_invoice_id IS NULL OR t.status<>'void') AND e.deduction_date BETWEEN ? AND ? ORDER BY e.deduction_date, e.created_at");
        $q->execute([$companyId, $kind, $from, $to]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $rows[] = ['id' => $e['id'], 'section' => $e['section_key'], 'quarter' => in6_quarter((string)$e['deduction_date']), 'date' => $e['deduction_date'], 'party' => $e['party'], 'pan' => $e['party_pan'], 'document' => $e['doc_number'],
                'baseCents' => (int)$e['base_cents'], 'rateMpct' => (int)$e['rate_mpct'], 'amountCents' => (int)$e['amount_cents'], 'rateSource' => $e['rate_source'], 'catchUp' => array_column(json_decode((string)$e['catch_up_json'], true) ?: [], 'number')];
            $k = $e['section_key'];
            $bySection[$k] ??= ['section' => $k, 'count' => 0, 'baseCents' => 0, 'amountCents' => 0];
            $bySection[$k]['count']++; $bySection[$k]['baseCents'] += (int)$e['base_cents']; $bySection[$k]['amountCents'] += (int)$e['amount_cents'];
        }
    }
    $total = array_sum(array_column($rows, 'amountCents'));
    [$code] = IN6_ACCOUNTS[$kind];
    $gl = db()->prepare("SELECT COALESCE(SUM(jl.credit_cents-jl.debit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id
        WHERE je.company_id=? AND je.status='posted' AND a.code=? AND je.entry_date BETWEEN ? AND ? AND je.source_type IN ('india_tds','india_tds_reversal','invoice')");
    $gl->execute([$companyId, $code, $from, $to]);
    $posted = (int)$gl->fetchColumn();
    return ['kind' => $kind, 'from' => $from, 'to' => $to, 'rows' => $rows, 'sections' => array_values($bySection), 'totalCents' => $total, 'account' => $code, 'postedCents' => $posted, 'ties' => $posted === $total,
        'note' => 'Working data for the ' . ($kind === 'tds' ? 'TDS return (Form 26Q)' : 'TCS return (Form 27EQ)') . ' by section and quarter. Paying the tax to the government and filing the return are done outside Tegh; record the payment as a journal against account ' . $code . '.'];
}

/* ---------- India setup checklist and dashboard ---------- */

function in6_checklist(array $company): array
{
    $companyId = (string)$company['id'];
    $count = static function (string $sql, array $p) { try { $q = db()->prepare($sql); $q->execute($p); return (int)$q->fetchColumn(); } catch (Throwable) { return 0; } };
    $prof = [];
    if (schema_table_exists('company_india_profile')) { $q = db()->prepare('SELECT * FROM company_india_profile WHERE company_id=?'); $q->execute([$companyId]); $prof = $q->fetch(PDO::FETCH_ASSOC) ?: []; }
    $rule = static fn(string $cat) => $count("SELECT COUNT(*) FROM statutory_rates WHERE company_id=? AND jurisdiction LIKE 'IN%' AND person_type=? AND status='active'", [$companyId, $cat]);
    $rec = static fn(string $type) => schema_table_exists('india_master_records') ? $count("SELECT COUNT(*) FROM india_master_records WHERE company_id=? AND record_type=? AND status='active'", [$companyId, $type]) : 0;
    $items = [
        ['key' => 'pan', 'group' => 'Company', 'label' => 'Company PAN', 'done' => (string)($prof['pan'] ?? '') !== '', 'page' => 'company-details'],
        ['key' => 'gstin', 'group' => 'Company', 'label' => 'GSTIN (if registered for GST)', 'done' => in3_company_gstin($company) !== null, 'page' => 'company-details'],
        ['key' => 'tan', 'group' => 'Company', 'label' => 'TAN (needed to deduct TDS)', 'done' => (string)($prof['tan'] ?? '') !== '', 'page' => 'company-details'],
        ['key' => 'gst_rate', 'group' => 'GST', 'label' => 'GST rate rules approved', 'done' => $rule('gst_rate') > 0, 'page' => 'india-masters', 'tab' => 'rules'],
        ['key' => 'gst_codes', 'group' => 'GST', 'label' => 'GST codes created from the rules', 'done' => schema_table_exists('india_gst_codes') && $count('SELECT COUNT(*) FROM india_gst_codes WHERE company_id=?', [$companyId]) > 0, 'page' => 'india-masters', 'tab' => 'gst'],
        ['key' => 'b2cl', 'group' => 'GST', 'label' => 'GSTR-1 B2C (large) limit rule approved', 'done' => in3_rule_in_force($companyId, 'IN', 'gst_return_limit', 'B2CL', canadian_today()) !== null, 'page' => 'india-masters', 'tab' => 'rules'],
        ['key' => 'party_gst', 'group' => 'GST', 'label' => 'GST registrations of customers and vendors', 'done' => schema_table_exists('india_master_records') && $count("SELECT COUNT(*) FROM india_master_records WHERE company_id=? AND record_type='gst_registration' AND entity_type IN ('customer','vendor') AND status='active'", [$companyId]) > 0, 'page' => 'india-masters', 'tab' => 'records'],
        ['key' => 'hsn', 'group' => 'GST', 'label' => 'HSN/SAC codes on products and services', 'done' => schema_table_exists('india_entity_profiles') && $count("SELECT COUNT(*) FROM india_entity_profiles WHERE company_id=? AND entity_type='item' AND JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.hsnSac')) <> ''", [$companyId]) > 0, 'page' => 'india-masters', 'tab' => 'profiles'],
        ['key' => 'employees', 'group' => 'Payroll', 'label' => 'Employees', 'done' => $rec('employee') > 0, 'page' => 'india-masters', 'tab' => 'records'],
        ['key' => 'components', 'group' => 'Payroll', 'label' => 'Pay components', 'done' => $rec('pay_component') > 0, 'page' => 'india-masters', 'tab' => 'records'],
        ['key' => 'structures', 'group' => 'Payroll', 'label' => 'Salary structures', 'done' => schema_table_exists('india_salary_structures') && $count('SELECT COUNT(*) FROM india_salary_structures WHERE company_id=?', [$companyId]) > 0, 'page' => 'india-payroll', 'tab' => 'structures'],
        ['key' => 'pay_rules', 'group' => 'Payroll', 'label' => 'EPF, ESIC, Professional Tax or LWF rules approved (as they apply)', 'done' => $rule('epf_rate') + $rule('esic_rate') + $rule('pt_slab') + $rule('lwf_rate') > 0, 'page' => 'india-masters', 'tab' => 'rules'],
        ['key' => 'tds_sections', 'group' => 'TDS and TCS', 'label' => 'TDS sections approved', 'done' => $rule('tds_section') > 0, 'page' => 'india-masters', 'tab' => 'rules'],
        ['key' => 'vendor_sections', 'group' => 'TDS and TCS', 'label' => 'TDS section on vendors that need it', 'done' => schema_table_exists('india_entity_profiles') && $count("SELECT COUNT(*) FROM india_entity_profiles WHERE company_id=? AND entity_type='vendor' AND JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.tdsSection')) <> ''", [$companyId]) > 0, 'page' => 'india-masters', 'tab' => 'profiles'],
    ];
    return ['items' => $items, 'done' => count(array_filter($items, static fn($i) => $i['done'])), 'total' => count($items)];
}

function in6_dashboard(array $company): array
{
    $companyId = (string)$company['id'];
    $today = canadian_today(); $prev = date('Y-m', strtotime(substr($today, 0, 7) . '-01 -1 month'));
    $balance = static function (string $code, bool $credit) use ($companyId): int {
        $q = db()->prepare("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id=? AND je.status='posted' AND a.code=?");
        $q->execute([$companyId, $code]); $v = (int)$q->fetchColumn(); return $credit ? -$v : $v;
    };
    $gst = null;
    if (schema_table_exists('india_gst_codes')) {
        require_once __DIR__ . '/india_gst_returns_in4.php';
        [$f, $t] = in4_month_range($prev);
        $g = in4_gstr3b($company, $f, $t);
        $gst = ['period' => $prev, 'cashCents' => array_sum(array_column($g['payment'], 'cashCents')), 'creditLeftCents' => array_sum(array_column($g['payment'], 'creditLeftCents'))];
    }
    $returns = [];
    if (schema_table_exists('india_gst_returns')) { $q = db()->prepare('SELECT return_type, status, arn FROM india_gst_returns WHERE company_id=? AND period=?'); $q->execute([$companyId, $prev]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $returns[$r['return_type']] = $r['status']; }
    $payroll = null;
    if (schema_table_exists('india_pay_runs')) { $q = db()->prepare("SELECT period, status, net_cents FROM india_pay_runs WHERE company_id=? AND status<>'reversed' ORDER BY period DESC LIMIT 1"); $q->execute([$companyId]); $p = $q->fetch(PDO::FETCH_ASSOC); if ($p) $payroll = ['period' => $p['period'], 'status' => $p['status'], 'netCents' => (int)$p['net_cents']]; }
    $pendingTds = 0;
    if (schema_table_exists('india_entity_profiles')) { [$f, $t] = [in6_fy_start($today), $today]; foreach (in6_pending($company, 'tds', $f, $t)['rows'] as $r) if ($r['due']) $pendingTds++; }
    return ['gst' => $gst, 'returns' => ['period' => $prev, 'GSTR1' => $returns['GSTR1'] ?? 'not_prepared', 'GSTR3B' => $returns['GSTR3B'] ?? 'not_prepared'], 'payroll' => $payroll,
        'balances' => ['tdsPayableCents' => $balance('2330', true), 'tcsPayableCents' => $balance('2340', true), 'salariesPayableCents' => $balance('2300', true), 'outputGstCents' => $balance('2401', true) + $balance('2402', true) + $balance('2403', true) + $balance('2404', true) + $balance('2405', true), 'inputCreditCents' => $balance('1451', false) + $balance('1452', false) + $balance('1453', false) + $balance('1454', false) + $balance('1455', false)],
        'pendingTds' => $pendingTds, 'checklist' => in6_checklist($company)];
}

/* ---------- routes: india/tds/... and india/home ---------- */

function handle_india_tds(string $action): never
{
    $user = require_user(); $company = require_company($user);
    if (!function_exists('in3_is_india') || !in3_is_india($company)) fail('Not found.', 404, 'india_only');
    $method = request_method();
    $kind = ($_GET['kind'] ?? '') === 'tcs' ? 'tcs' : 'tds';
    if ($method === 'GET' && in_array($action, ['pending', 'register'], true)) {
        require_company_permission($company, 'reports.view'); in6_schema_ready();
        $from = safe_date((string)($_GET['from'] ?? ''), 'From'); $to = safe_date((string)($_GET['to'] ?? ''), 'To'); if ($to < $from) fail('To is before From.', 422, 'india_tds_period');
        json_response($action === 'pending' ? in6_pending($company, $kind, $from, $to) : in6_register($company, $kind, $from, $to));
    }
    if ($method === 'GET' && $action === 'preview') {
        require_company_permission($company, 'reports.view'); in6_schema_ready();
        $date = trim((string)($_GET['date'] ?? '')); json_response(in6_compute((string)$company['id'], $kind, clean_text($_GET['documentId'] ?? '', 'Document', 64), trim((string)($_GET['section'] ?? '')) ?: null, $date === '' ? null : safe_date($date, 'Date')));
    }
    if ($method === 'GET' && $action === 'home') {
        require_company_permission($company, 'company.view'); in6_schema_ready();
        json_response(in6_dashboard($company));
    }
    if ($method === 'POST') {
        require_csrf(); $in = request_json();
        if ($action === 'record') json_response(in6_post($user, $company, $in), 201);
        if ($action === 'reverse') json_response(in6_reverse($user, $company, $in));
    }
    fail('Not found.', 404, 'route_not_found');
}
