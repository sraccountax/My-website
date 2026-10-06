<?php
declare(strict_types=1);

/*
 * R152: Document Intake — supplier memory, coding suggestions and duplicate checks.
 *
 *  - Supplier memory: when a reviewer verifies a vendor invoice, Tegh compares the fields the local reader
 *    extracted (kept as the extracted candidate, never the raw text) with the verified fields, and remembers
 *    per vendor what was confirmed: the numeric date order, the shape of the vendor's invoice numbers, and
 *    whether the printed amount due is the invoice total. The browser applies it to the next extraction.
 *  - Coding suggestions: the expense account and tax code for each line come from the vendor's earlier
 *    invoices (matching line wording first, then the vendor's most used account), never from a guess.
 *  - Duplicates: an invoice with the same supplier and number as a saved vendor invoice or another intake
 *    document is flagged, as is one with the same supplier and total within seven days. Creating a draft
 *    from a same-number duplicate needs an explicit "not a duplicate" confirmation.
 *
 * Schema changes are additive and made on first use, outside any transaction.
 */

function r152_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        if (schema_table_exists('native_agent_documents')) schema_add_column('native_agent_documents', 'extracted_json', 'MEDIUMTEXT NULL');
        if (!schema_table_exists('vendor_document_memory')) {
            db()->exec("CREATE TABLE IF NOT EXISTS vendor_document_memory (
 company_id VARCHAR(64) NOT NULL,
 vendor_id VARCHAR(64) NOT NULL,
 date_order VARCHAR(3) NULL,
 date_order_count INT NOT NULL DEFAULT 0,
 number_shape VARCHAR(80) NULL,
 number_count INT NOT NULL DEFAULT 0,
 total_source VARCHAR(16) NULL,
 total_count INT NOT NULL DEFAULT 0,
 confirmations INT NOT NULL DEFAULT 0,
 corrections INT NOT NULL DEFAULT 0,
 last_document_id VARCHAR(64) NULL,
 updated_by VARCHAR(64) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (company_id,vendor_id),
 CONSTRAINT vendor_document_memory_vendor_fk FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
 CONSTRAINT vendor_document_memory_ck CHECK (date_order IS NULL OR date_order IN ('mdy','dmy'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $ready = schema_table_exists('vendor_document_memory') && schema_column_exists('native_agent_documents', 'extracted_json');
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** "NW-2026-0142" → "AA-9999-9999": letters become A, digits 9, separators stay. */
function r152_number_shape(string $number): string
{
    $shape = preg_replace('/\d/', '9', preg_replace('/[A-Za-z]/', 'A', trim($number)) ?? '') ?? '';
    return mb_substr($shape, 0, 80);
}

function r152_normal_number(string $number): string
{
    return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $number) ?? '');
}

/** Extra candidate fields kept by R152 (line quantities, prices, tax hints, line check, applied memory notes). */
function r152_candidate_line(array $line): ?array
{
    $description = mb_substr(trim((string)($line['description'] ?? '')), 0, 300);
    $amount = (string)($line['amountCents'] ?? '');
    if ($description === '' || !preg_match('/^\d{1,12}$/', $amount)) return null;
    $out = ['description' => $description, 'amountCents' => (int)$amount];
    $quantity = trim((string)($line['quantity'] ?? ''));
    if ($quantity !== '' && preg_match('/^\d{1,7}(\.\d{1,3})?$/', $quantity) && (float)$quantity > 0) $out['quantity'] = $quantity;
    $unit = (string)($line['unitCents'] ?? '');
    if ($unit !== '' && preg_match('/^\d{1,12}$/', $unit)) $out['unitCents'] = (int)$unit;
    $hint = trim((string)($line['taxHint'] ?? ''));
    if ($hint !== '') $out['taxHint'] = mb_substr($hint, 0, 16);
    if (array_key_exists('checked', $line)) $out['checked'] = $line['checked'] === true;
    foreach (['accountId', 'taxCodeId'] as $key) { $value = trim((string)($line[$key] ?? '')); if ($value !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value)) $out[$key] = $value; }
    return $out;
}

function r152_vendor_memory(array $company, string $vendorId): ?array
{
    if ($vendorId === '' || !r152_schema_ready()) return null;
    $stmt = db()->prepare('SELECT * FROM vendor_document_memory WHERE company_id=? AND vendor_id=? LIMIT 1');
    $stmt->execute([(string)$company['id'], $vendorId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return ['vendorId' => $vendorId, 'dateOrder' => $row['date_order'] ?: null, 'numberShape' => $row['number_shape'] ?: null, 'totalSource' => $row['total_source'] ?: null,
        'confirmations' => (int)$row['confirmations'], 'corrections' => (int)$row['corrections'], 'updatedAt' => (string)$row['updated_at']];
}

/**
 * Learn from one verified vendor invoice. $extracted is the reader's candidate for this document (empty when the
 * fields were typed by hand), $verified the reviewed candidate. Runs inside the caller's transaction (DML only).
 */
function r152_memory_learn(array $user, array $company, array $document, array $extracted, array $verified): array
{
    $vendorId = trim((string)($verified['partyId'] ?? ''));
    if ($vendorId === '' || !r152_schema_ready()) return [];
    $lock = db()->prepare('SELECT * FROM vendor_document_memory WHERE company_id=? AND vendor_id=? FOR UPDATE');
    $lock->execute([(string)$company['id'], $vendorId]);
    $row = $lock->fetch() ?: ['date_order' => null, 'date_order_count' => 0, 'number_shape' => null, 'number_count' => 0, 'total_source' => null, 'total_count' => 0, 'confirmations' => 0, 'corrections' => 0];
    $learned = [];
    $corrected = false;
    // Date order: the reader offered two readings of a numeric date and the reviewer chose one.
    $date = (string)($verified['documentDate'] ?? '');
    if ($date !== '' && empty($extracted['documentDate'])) {
        foreach ((array)($extracted['alternatives'] ?? []) as $alternative) {
            $order = (string)($alternative['dateOrder'] ?? '');
            if (($alternative['documentDate'] ?? '') === $date && in_array($order, ['mdy', 'dmy'], true)) {
                $row['date_order_count'] = $row['date_order'] === $order ? (int)$row['date_order_count'] + 1 : 1;
                $row['date_order'] = $order; $learned[] = 'dateOrder';
                break;
            }
        }
    } elseif ($date !== '' && !empty($extracted['documentDate']) && $extracted['documentDate'] !== $date && !empty($row['date_order'])) {
        // The remembered order produced a date the reviewer changed to the swapped reading: the order was wrong.
        [$y, $m, $d] = array_map('intval', explode('-', (string)$extracted['documentDate']));
        if (checkdate($d, $m, $y) && sprintf('%04d-%02d-%02d', $y, $d, $m) === $date) { $row['date_order'] = $row['date_order'] === 'dmy' ? 'mdy' : 'dmy'; $row['date_order_count'] = 1; $learned[] = 'dateOrder'; $corrected = true; }
    }
    // Invoice number shape: always from the verified number.
    $number = trim((string)($verified['documentNumber'] ?? ''));
    if ($number !== '') {
        $shape = r152_number_shape($number);
        $row['number_count'] = $row['number_shape'] === $shape ? (int)$row['number_count'] + 1 : 1;
        $row['number_shape'] = $shape; $learned[] = 'numberShape';
        if (trim((string)($extracted['documentNumber'] ?? '')) !== $number && $extracted) $corrected = true;
    }
    // Which printed amount is the invoice total.
    $total = isset($verified['totalCents']) ? (int)$verified['totalCents'] : null;
    if ($total !== null && isset($extracted['amountDueCents'], $extracted['totalCents']) && (int)$extracted['amountDueCents'] !== (int)$extracted['totalCents']) {
        $source = $total === (int)$extracted['amountDueCents'] ? 'amountDue' : ($total === (int)$extracted['totalCents'] ? 'total' : null);
        if ($source !== null) { $row['total_count'] = $row['total_source'] === $source ? (int)$row['total_count'] + 1 : 1; $row['total_source'] = $source; $learned[] = 'totalSource'; if ($source === 'amountDue') $corrected = true; }
    } elseif ($total !== null && isset($extracted['totalCents']) && (int)$extracted['totalCents'] !== $total) $corrected = true;
    if (!$learned) return [];
    $row['confirmations'] = (int)$row['confirmations'] + 1;
    if ($corrected) $row['corrections'] = (int)$row['corrections'] + 1;
    db()->prepare('INSERT INTO vendor_document_memory (company_id,vendor_id,date_order,date_order_count,number_shape,number_count,total_source,total_count,confirmations,corrections,last_document_id,updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE date_order=VALUES(date_order),date_order_count=VALUES(date_order_count),number_shape=VALUES(number_shape),number_count=VALUES(number_count),
        total_source=VALUES(total_source),total_count=VALUES(total_count),confirmations=VALUES(confirmations),corrections=VALUES(corrections),last_document_id=VALUES(last_document_id),updated_by=VALUES(updated_by)')
        ->execute([(string)$company['id'], $vendorId, $row['date_order'], (int)$row['date_order_count'], $row['number_shape'], (int)$row['number_count'], $row['total_source'], (int)$row['total_count'],
            (int)$row['confirmations'], (int)$row['corrections'], (string)$document['id'], (string)$user['id']]);
    return ['vendorId' => $vendorId, 'learned' => $learned, 'corrected' => $corrected];
}

/**
 * Memory for the vendor an extraction most likely belongs to. Only the identity fields are sent (name, email,
 * business number, address); the vendor must be the single preselected match. Read-only.
 */
function r152_memory_lookup(array $company, array $input): array
{
    require_company_permission($company, 'attachments.write');
    require_company_permission($company, 'bills.view');
    $identity = [];
    foreach (['partyName' => 160, 'partyEmail' => 254, 'businessIdentifier' => 120, 'address' => 500] as $key => $max) {
        $value = trim((string)($input[$key] ?? ''));
        if ($value !== '') $identity[$key] = mb_substr($value, 0, $max);
    }
    if (!$identity) return ['vendorId' => null, 'memory' => null, 'accountingWrites' => 0, 'providerAttempts' => 0];
    $ranked = native_vendor_suggestions($company, ['document_type' => 'vendor_bill', 'candidate_json' => json_encode($identity)]);
    $vendorId = (string)($ranked['preselectedVendorId'] ?? '');
    return ['vendorId' => $vendorId ?: null, 'memory' => $vendorId !== '' ? r152_vendor_memory($company, $vendorId) : null, 'accountingWrites' => 0, 'providerAttempts' => 0];
}

/** Lower-case words of three or more letters, accents folded, without filler words or numbers. */
function r152_words(string $text): array
{
    $text = mb_strtolower($text);
    if (class_exists('Normalizer')) $text = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($text, Normalizer::FORM_D) ?? $text) ?? $text;
    preg_match_all('/[a-z]{3,}/u', $text, $matches);
    static $stop = ['the', 'and', 'for', 'with', 'from', 'les', 'des', 'pour', 'avec', 'une', 'per', 'each', 'box', 'boite'];
    return array_values(array_unique(array_diff($matches[0], $stop)));
}

/** Share of the shorter description's words found in the other; two shared words are needed unless one has a single word. */
function r152_similarity(array $a, array $b): float
{
    if (!$a || !$b) return 0.0;
    $common = count(array_intersect($a, $b));
    if ($common < min(2, count($a), count($b))) return 0.0;
    return $common / min(count($a), count($b));
}

/**
 * Account and tax-code suggestions for a vendor invoice, from the vendor's earlier invoices in this company.
 * Each suggestion says where it came from; nothing is suggested without history.
 */
function r152_coding_suggestions(array $company, array $document): array
{
    require_company_permission($company, 'bills.view');
    $candidate = json_decode((string)$document['candidate_json'], true);
    if (!is_array($candidate)) $candidate = [];
    $vendorId = trim((string)($candidate['partyId'] ?? ''));
    if ($vendorId === '') { $ranked = native_vendor_suggestions($company, $document); $vendorId = (string)($ranked['preselectedVendorId'] ?? ''); }
    $companyId = (string)$company['id'];
    $usable = db()->prepare("SELECT id FROM accounts WHERE company_id=? AND active=1 AND is_control=0 AND account_type IN ('expense','asset')");
    $usable->execute([$companyId]);
    $accounts = array_flip(array_map('strval', $usable->fetchAll(PDO::FETCH_COLUMN)));
    $codes = [];
    if (schema_table_exists('tax_codes')) { $stmt = db()->prepare("SELECT id FROM tax_codes WHERE company_id=? AND status='active'"); $stmt->execute([$companyId]); $codes = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))); }
    $history = [];
    $billCount = 0;
    if ($vendorId !== '') {
        if (schema_table_exists('bill_lines')) {
            $stmt = db()->prepare("SELECT bl.description,bl.account_id,bl.tax_code_id,b.id bill_id,b.bill_date FROM bill_lines bl JOIN bills b ON b.id=bl.bill_id AND b.company_id=bl.company_id
                WHERE bl.company_id=? AND b.vendor_id=? AND b.status<>'void' ORDER BY b.bill_date DESC,b.id DESC,bl.sort_order LIMIT 500");
            $stmt->execute([$companyId, $vendorId]);
            $history = $stmt->fetchAll();
        }
        $stmt = db()->prepare("SELECT b.number description,b.category_account_id account_id,b.tax_code_id,b.id bill_id,b.bill_date FROM bills b WHERE b.company_id=? AND b.vendor_id=? AND b.status<>'void'"
            . (schema_table_exists('bill_lines') ? ' AND NOT EXISTS (SELECT 1 FROM bill_lines bl WHERE bl.bill_id=b.id AND bl.company_id=b.company_id)' : '') . ' ORDER BY b.bill_date DESC,b.id DESC LIMIT 200');
        $stmt->execute([$companyId, $vendorId]);
        foreach ($stmt->fetchAll() as $bill) { $bill['wholeBill'] = true; $history[] = $bill; }
        $billCount = count(array_unique(array_map(static fn($row) => (string)$row['bill_id'], $history)));
    }
    $history = array_values(array_filter($history, static fn($row) => isset($accounts[(string)$row['account_id']])));
    // The vendor's most used account and tax code, counted once per invoice, newest first on ties.
    $tally = static function (array $rows, string $key, array $allowed): ?string {
        $seen = []; $count = []; $order = [];
        foreach ($rows as $index => $row) {
            $value = (string)($row[$key] ?? '');
            if ($value === '' || !isset($allowed[$value]) || isset($seen[$row['bill_id'] . '|' . $value])) continue;
            $seen[$row['bill_id'] . '|' . $value] = true; $count[$value] = ($count[$value] ?? 0) + 1; $order[$value] ??= $index;
        }
        if (!$count) return null;
        uksort($count, static fn($a, $b) => [$count[$b], $order[$a]] <=> [$count[$a], $order[$b]]);
        return (string)array_key_first($count);
    };
    $defaultAccount = $tally($history, 'account_id', $accounts);
    $defaultTax = $tally($history, 'tax_code_id', $codes);
    $default = $defaultAccount ? ['accountId' => $defaultAccount, 'taxCodeId' => $defaultTax, 'reason' => sprintf('Most used on %d earlier invoice%s from this vendor', $billCount, $billCount === 1 ? '' : 's')] : null;
    $lineHistory = array_values(array_filter($history, static fn($row) => empty($row['wholeBill'])));
    $lines = [];
    foreach (array_slice((array)($candidate['lineItems'] ?? []), 0, 100) as $index => $line) {
        $words = r152_words((string)($line['description'] ?? ''));
        $best = null; $score = 0.0;
        foreach ($lineHistory as $row) { $similarity = r152_similarity($words, r152_words((string)$row['description'])); if ($similarity > $score) { $score = $similarity; $best = $row; } }
        if ($best && $score >= 0.66) {
            $lines[] = ['index' => $index, 'accountId' => (string)$best['account_id'], 'taxCodeId' => isset($codes[(string)$best['tax_code_id']]) ? (string)$best['tax_code_id'] : $defaultTax,
                'reason' => 'Same wording as "' . mb_substr((string)$best['description'], 0, 60) . '" on an earlier invoice from this vendor', 'source' => 'line_match'];
        } elseif ($default) {
            $lines[] = ['index' => $index, 'accountId' => $default['accountId'], 'taxCodeId' => $defaultTax, 'reason' => $default['reason'], 'source' => 'vendor_default'];
        }
    }
    return ['vendorId' => $vendorId ?: null, 'invoicesSeen' => $billCount, 'default' => $default, 'lines' => $lines, 'accountingWrites' => 0, 'providerAttempts' => 0];
}

/**
 * Possible duplicates of an intake document: saved vendor invoices (or customer invoices) and other open intake
 * documents with the same party and number, or the same party and total within seven days.
 */
function r152_duplicates(array $company, array $document, ?array $candidate = null): array
{
    $candidate ??= json_decode((string)$document['candidate_json'], true);
    if (!is_array($candidate)) return [];
    $vendor = (string)$document['document_type'] === 'vendor_bill';
    $partyId = trim((string)($candidate['partyId'] ?? ''));
    $partyName = mb_strtolower(trim((string)($candidate['partyName'] ?? '')));
    $number = r152_normal_number((string)($candidate['documentNumber'] ?? ''));
    $total = isset($candidate['totalCents']) ? (int)$candidate['totalCents'] : null;
    $date = (string)($candidate['documentDate'] ?? '');
    if (($partyId === '' && $partyName === '') || ($number === '' && ($total === null || $date === ''))) return [];
    $near = static fn(string $other): bool => $date !== '' && $other !== '' && abs((strtotime($other) - strtotime($date)) / 86400) <= 7;
    $companyId = (string)$company['id'];
    $out = [];
    $table = $vendor ? 'bills' : 'invoices'; $partyTable = $vendor ? 'vendors' : 'customers'; $partyColumn = $vendor ? 'vendor_id' : 'customer_id'; $dateColumn = $vendor ? 'bill_date' : 'issue_date';
    if ($vendor || schema_column_exists('invoices', 'issue_date')) {
        $sql = "SELECT d.id,d.number,d.$dateColumn doc_date,d.foreign_total_cents total,d.status FROM $table d JOIN $partyTable p ON p.id=d.$partyColumn AND p.company_id=d.company_id
            WHERE d.company_id=? AND d.status<>'void' AND " . ($partyId !== '' ? "d.$partyColumn=?" : 'LOWER(p.name)=?') . " ORDER BY d.$dateColumn DESC LIMIT 2000";
        try {
            $stmt = db()->prepare($sql); $stmt->execute([$companyId, $partyId !== '' ? $partyId : $partyName]);
            foreach ($stmt->fetchAll() as $row) {
                if ((string)$document['linked_entity_id'] === (string)$row['id']) continue;
                $reason = $number !== '' && r152_normal_number((string)$row['number']) === $number ? 'number' : ($total !== null && (int)$row['total'] === $total && $near((string)$row['doc_date']) ? 'total_date' : null);
                if ($reason) $out[] = ['kind' => $vendor ? 'bill' : 'invoice', 'id' => (string)$row['id'], 'number' => (string)$row['number'], 'date' => (string)$row['doc_date'], 'totalCents' => (int)$row['total'], 'status' => (string)$row['status'], 'reason' => $reason];
            }
        } catch (PDOException $e) { /* a missing optional column on an old schema leaves this check out */ }
    }
    $stmt = db()->prepare("SELECT id,original_name,state,candidate_json,linked_entity_id FROM native_agent_documents WHERE company_id=? AND document_type=? AND id<>? AND state<>'dismissed' ORDER BY created_at DESC LIMIT 500");
    $stmt->execute([$companyId, (string)$document['document_type'], (string)$document['id']]);
    $listed = array_column($out, 'id');
    foreach ($stmt->fetchAll() as $row) {
        if ($row['linked_entity_id'] && in_array((string)$row['linked_entity_id'], $listed, true)) continue;
        $other = json_decode((string)$row['candidate_json'], true);
        if (!is_array($other)) continue;
        $sameParty = $partyId !== '' && ($other['partyId'] ?? '') === $partyId || $partyName !== '' && mb_strtolower(trim((string)($other['partyName'] ?? ''))) === $partyName;
        if (!$sameParty) continue;
        $reason = $number !== '' && r152_normal_number((string)($other['documentNumber'] ?? '')) === $number ? 'number' : ($total !== null && isset($other['totalCents']) && (int)$other['totalCents'] === $total && $near((string)($other['documentDate'] ?? '')) ? 'total_date' : null);
        if ($reason) $out[] = ['kind' => 'document', 'id' => (string)$row['id'], 'number' => (string)($other['documentNumber'] ?? ''), 'date' => (string)($other['documentDate'] ?? ''), 'totalCents' => isset($other['totalCents']) ? (int)$other['totalCents'] : null, 'status' => (string)$row['state'], 'name' => (string)$row['original_name'], 'reason' => $reason];
    }
    return array_slice($out, 0, 10);
}

/** A same-number duplicate blocks a new draft or workflow until the reviewer confirms it is a different invoice. */
function r152_assert_not_duplicate(array $company, array $document, array $candidate, array $input): void
{
    if (($input['confirmNotDuplicate'] ?? false) === true) return;
    $same = array_values(array_filter(r152_duplicates($company, $document, $candidate), static fn($item) => $item['reason'] === 'number'));
    if (!$same) return;
    $first = $same[0];
    fail(sprintf('This looks like a duplicate: %s %s from the same %s is already in Tegh. Check it, then confirm this is a different invoice to continue.',
        $first['kind'] === 'document' ? 'intake document' : ($first['kind'] === 'bill' ? 'vendor invoice' : 'invoice'), $first['number'] ?: $first['id'], (string)$document['document_type'] === 'vendor_bill' ? 'vendor' : 'customer'),
        409, 'native_document_possible_duplicate');
}
