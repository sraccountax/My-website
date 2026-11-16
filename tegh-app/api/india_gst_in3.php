<?php
declare(strict_types=1);

/*
 * IN-3a: GST for India companies, built on the R137 tax-code engine.
 * - A GST code is a tax code created from an approved GST rate rule (statutory_rates, category gst_rate).
 *   Tegh supplies no rate: the rule's rate, approved by the Owner or an Admin, is the only source.
 * - Intra-state code: CGST + SGST (UTGST for a union territory without a legislature), each half the rate.
 *   Inter-state code: IGST at the full rate. An approved cess rule adds a cess component to both.
 * - Place of supply decides intra- or inter-state; every India customer invoice, invoice edit and vendor
 *   invoice is checked against it and against the rule version in force on the document date.
 * Canadian companies never reach this file's checks (every hook returns at once outside the India edition).
 */

const IN3_UTGST_STATES = ['AN', 'CH', 'DH', 'LA', 'LD']; // union territories without a legislature (UTGST Act)
const IN3_FOREIGN = '96';                                 // place of supply outside India (exports, imports)
const IN3_HEADS = ['CGST', 'SGST', 'UTGST', 'IGST', 'CESS'];
const IN3_ACCOUNTS = [
    'out' => ['CGST' => ['2401', 'Output CGST'], 'SGST' => ['2402', 'Output SGST'], 'UTGST' => ['2403', 'Output UTGST'], 'IGST' => ['2404', 'Output IGST'], 'CESS' => ['2405', 'Output GST Cess']],
    'in' => ['CGST' => ['1451', 'Input CGST Credit'], 'SGST' => ['1452', 'Input SGST Credit'], 'UTGST' => ['1453', 'Input UTGST Credit'], 'IGST' => ['1454', 'Input IGST Credit'], 'CESS' => ['1455', 'Input GST Cess Credit']],
];

function in3_is_india(array $company): bool
{
    return function_exists('tegh_company_edition') && tegh_company_edition($company) === 'IN';
}

function in3_company_is_india(string $companyId): bool
{
    static $cache = [];
    if (!isset($cache[$companyId])) {
        if (!schema_column_exists('companies', 'edition')) return $cache[$companyId] = false;
        $q = db()->prepare('SELECT edition FROM companies WHERE id=?'); $q->execute([$companyId]);
        $cache[$companyId] = strtoupper((string)$q->fetchColumn()) === 'IN';
    }
    return $cache[$companyId];
}

function in3_schema_ready(): bool
{
    static $ready = false;
    if ($ready) return true;
    if (schema_table_exists('india_gst_codes') && schema_table_exists('india_document_gst')) return $ready = true;
    if (db()->inTransaction()) return false; // DDL would commit an open transaction
    if (function_exists('tegh_tax_codes_ready')) tegh_tax_codes_ready();
    db()->exec("CREATE TABLE IF NOT EXISTS india_gst_codes (
        tax_code_id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL,
        rule_jurisdiction VARCHAR(10) NOT NULL, rule_key VARCHAR(120) NOT NULL, rule_id VARCHAR(64) NOT NULL,
        cess_rule_jurisdiction VARCHAR(10) NULL, cess_rule_key VARCHAR(120) NULL, cess_rule_id VARCHAR(64) NULL,
        supply ENUM('intra','inter') NOT NULL, rate_mpct INT NOT NULL, cess_mpct INT NOT NULL DEFAULT 0,
        created_by VARCHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY india_gst_codes_rule_idx (company_id, rule_key),
        CONSTRAINT fk_india_gst_codes_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        CONSTRAINT fk_india_gst_codes_code FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS india_document_gst (
        company_id VARCHAR(64) NOT NULL, document_type VARCHAR(20) NOT NULL, document_id VARCHAR(64) NOT NULL,
        place_of_supply CHAR(2) NOT NULL, supply ENUM('intra','inter') NOT NULL,
        company_state_code CHAR(2) NOT NULL, party_state_code CHAR(2) NULL,
        company_gstin VARCHAR(15) NULL, party_gstin VARCHAR(15) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (company_id, document_type, document_id),
        CONSTRAINT fk_india_document_gst_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $ready = true;
}

/* ---------- states and parties ---------- */

/** GST state code ('27') for an ISO state ('MH'), a GST code, or null. */
function in3_state_code(?string $value): ?string
{
    $v = strtoupper(trim((string)$value));
    if ($v === '') return null;
    if (isset(TEGH_INDIA_STATES[$v])) return TEGH_INDIA_STATES[$v][1];
    if ($v === IN3_FOREIGN || $v === '97') return $v;
    foreach (TEGH_INDIA_STATES as [, $code]) if ($code === $v) return $v;
    return null;
}

function in3_state_name(string $code): string
{
    if ($code === IN3_FOREIGN) return 'Outside India';
    if ($code === '97') return 'Other Territory';
    foreach (TEGH_INDIA_STATES as [$name, $c]) if ($c === $code) return $name;
    return $code;
}

/** @return list<array{code:string,name:string}> */
function in3_places_of_supply(): array
{
    $out = [];
    foreach (TEGH_INDIA_STATES as [$name, $code]) $out[] = ['code' => $code, 'name' => $name];
    usort($out, static fn($a, $b) => strcmp($a['name'], $b['name']));
    $out[] = ['code' => '97', 'name' => 'Other Territory'];
    $out[] = ['code' => IN3_FOREIGN, 'name' => 'Outside India (export / import)'];
    return $out;
}

function in3_company_gstin(array $company): ?string
{
    $g = '';
    if (schema_table_exists('company_india_profile')) {
        $q = db()->prepare('SELECT gstin FROM company_india_profile WHERE company_id=?'); $q->execute([(string)$company['id']]);
        $g = (string)($q->fetchColumn() ?: '');
    }
    if ($g === '') $g = (string)($company['tax_number'] ?? '');
    return preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $g) ? $g : null;
}

/** The party's active GST registration in force on the date (India Masters), if any. */
function in3_party_gstin(string $companyId, string $entityType, string $entityId, string $date): ?string
{
    if (!schema_table_exists('india_master_records')) return null;
    $q = db()->prepare("SELECT data_json FROM india_master_records WHERE company_id=? AND record_type='gst_registration' AND entity_type=? AND entity_id=?
        AND status='active' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC, created_at DESC");
    $q->execute([$companyId, $entityType, $entityId, $date, $date]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $d = json_decode((string)$json, true);
        if (!is_array($d) || ($d['status'] ?? '') !== 'active') continue;
        $g = strtoupper((string)($d['gstin'] ?? ''));
        if (preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $g)) return $g;
    }
    return null;
}

/** Place of supply given on the document, or null. Accepts '27' or 'MH'; refuses anything else. */
function in3_input_place(array $input, string $key = 'placeOfSupply'): ?string
{
    $raw = trim((string)($input[$key] ?? ''));
    if ($raw === '') return null;
    $code = in3_state_code($raw);
    if ($code === null) fail('Choose a valid place of supply (a state or union territory, or outside India).', 422, 'india_gst_place_invalid');
    return $code;
}

function in3_party_state(array $party, ?string $gstin): ?string
{
    if ($gstin !== null) return substr($gstin, 0, 2);
    $fromProvince = in3_state_code((string)($party['province'] ?? ''));
    if ($fromProvince !== null && $fromProvince !== IN3_FOREIGN) return $fromProvince;
    $country = strtolower(trim((string)($party['country'] ?? '')));
    if ($country !== '' && !in_array($country, ['india', 'in', 'ind', 'bharat'], true)) return IN3_FOREIGN;
    return null;
}

/** Sales: intra-state when the place of supply is the company's own state. */
function in3_sales_context(array $company, array $input, array $customer, string $date, ?string $documentId = null): ?array
{
    if (!in3_is_india($company)) return null;
    in3_schema_ready();
    $companyState = in3_state_code((string)$company['province']) ?? '';
    $gstin = in3_party_gstin((string)$company['id'], 'customer', (string)$customer['id'], $date);
    $partyState = in3_party_state($customer, $gstin);
    $stored = null;
    if ($documentId !== null && $documentId !== '' && schema_table_exists('india_document_gst')) {
        // An edit that does not send a place of supply keeps the one saved with the invoice.
        $q = db()->prepare("SELECT place_of_supply FROM india_document_gst WHERE company_id=? AND document_type='invoice' AND document_id=?"); $q->execute([(string)$company['id'], $documentId]);
        $stored = $q->fetchColumn() ?: null;
    }
    $pos = in3_input_place($input) ?? $stored ?? $partyState ?? $companyState;
    return ['side' => 'sales', 'date' => $date, 'pos' => $pos, 'supply' => $pos === $companyState ? 'intra' : 'inter',
        'companyState' => $companyState, 'partyState' => $partyState, 'companyGstin' => in3_company_gstin($company), 'partyGstin' => $gstin];
}

/** Purchases: intra-state when the supplier's state is the place of supply (by default the company's state). */
function in3_purchase_context(array $company, array $input, array $vendor, string $date): ?array
{
    if (!in3_is_india($company)) return null;
    in3_schema_ready();
    $companyState = in3_state_code((string)$company['province']) ?? '';
    if (!array_key_exists('province', $vendor) || !array_key_exists('country', $vendor)) {
        $q = db()->prepare('SELECT province, country FROM vendors WHERE id=? AND company_id=?'); $q->execute([(string)$vendor['id'], (string)$company['id']]);
        $vendor += ($q->fetch(PDO::FETCH_ASSOC) ?: []);
    }
    $gstin = in3_party_gstin((string)$company['id'], 'vendor', (string)$vendor['id'], $date);
    $partyState = in3_input_place($input, 'supplierState') ?? in3_party_state($vendor, $gstin) ?? $companyState;
    $pos = in3_input_place($input) ?? $companyState;
    return ['side' => 'purchase', 'date' => $date, 'pos' => $pos, 'supply' => $partyState === $pos ? 'intra' : 'inter',
        'companyState' => $companyState, 'partyState' => $partyState, 'companyGstin' => in3_company_gstin($company), 'partyGstin' => $gstin];
}

/* ---------- rules and codes ---------- */

function in3_rule_in_force(string $companyId, string $jurisdiction, string $category, string $key, string $date): ?array
{
    $q = db()->prepare("SELECT * FROM statutory_rates WHERE company_id=? AND jurisdiction=? AND person_type=? AND rate_key=? AND status='active'
        AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC LIMIT 1");
    $q->execute([$companyId, $jurisdiction, $category, $key, $date, $date]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function in3_rule_mpct(array $rule): ?int
{
    if ($rule['value_decimal'] === null || $rule['value_decimal'] === '') return null;
    return (int)round((float)$rule['value_decimal'] * 1000);
}

function in3_code_link(string $companyId, string $taxCodeId): ?array
{
    if (!schema_table_exists('india_gst_codes')) return null;
    $q = db()->prepare('SELECT * FROM india_gst_codes WHERE company_id=? AND tax_code_id=?'); $q->execute([$companyId, $taxCodeId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** The company's GST code for a rule key and supply type (the most recent one, if several). */
function in3_code_for_rule(string $companyId, string $ruleKey, string $supply): ?array
{
    if (!schema_table_exists('india_gst_codes')) return null;
    $q = db()->prepare("SELECT g.tax_code_id FROM india_gst_codes g JOIN tax_codes t ON t.id=g.tax_code_id AND t.status='active'
        WHERE g.company_id=? AND g.rule_key=? AND g.supply=? ORDER BY g.created_at DESC LIMIT 1");
    $q->execute([$companyId, $ruleKey, $supply]);
    $id = $q->fetchColumn();
    return $id ? tax_code_get($companyId, (string)$id) : null;
}

/** The item's GST rate rule key in force on the date (from its India profile), if any. */
function in3_item_rule_key(string $companyId, string $itemId, string $date): ?string
{
    if ($itemId === '' || !schema_table_exists('india_entity_profiles')) return null;
    $q = db()->prepare("SELECT data_json FROM india_entity_profiles WHERE company_id=? AND entity_type='item' AND entity_id=? AND effective_from<=? ORDER BY effective_from DESC LIMIT 1");
    $q->execute([$companyId, $itemId, $date]);
    $d = json_decode((string)($q->fetchColumn() ?: ''), true);
    $ref = is_array($d) ? (string)($d['gstRate'] ?? '') : '';
    if ($ref === '') return null;
    // India Masters stores the rule's code; an id is accepted too.
    $r = db()->prepare("SELECT rate_key FROM statutory_rates WHERE company_id=? AND person_type='gst_rate' AND jurisdiction='IN' AND (rate_key=? OR id=?) LIMIT 1"); $r->execute([$companyId, $ref, $ref]);
    $key = $r->fetchColumn();
    return $key === false ? null : (string)$key;
}

/**
 * Decide and check the GST code of one document line (or of a whole vendor invoice).
 * $code is the code the user chose (null when none); $chosen says whether a choice was made at all,
 * so that an explicit "no tax" is kept and only an unspecified line takes the item's default.
 */
function in3_line_code(array $company, ?array $ctx, ?array $code, bool $chosen, string $itemId = ''): ?array
{
    if ($ctx === null) return $code;
    $companyId = (string)$company['id'];
    if ($code === null && !$chosen && $itemId !== '') {
        $key = in3_item_rule_key($companyId, $itemId, $ctx['date']);
        if ($key !== null) {
            $code = in3_code_for_rule($companyId, $key, $ctx['supply']);
            if ($code === null) fail('The item\'s GST rate rule ' . $key . ' has no ' . ($ctx['supply'] === 'intra' ? 'intra-state' : 'inter-state') . ' GST code. Create the codes under Settings › India Masters › GST codes, or choose a code on the line.', 422, 'india_gst_code_missing');
        }
    }
    if ($code === null) return null;
    in3_assert_code($company, $ctx, $code);
    return $code;
}

function in3_assert_code(array $company, array $ctx, array $code): void
{
    $companyId = (string)$company['id'];
    $link = in3_code_link($companyId, (string)$code['id']);
    if ($link === null) fail('Tax code ' . $code['code'] . ' is not a GST code. In an India company use a GST code created from an approved GST rate rule (Settings › India Masters › GST codes).', 422, 'india_gst_code_required');
    if ($ctx['side'] === 'sales' && (int)$code['totalRateMpct'] > 0 && $ctx['companyGstin'] === null)
        fail('The company has no GSTIN, so it cannot charge GST. Add the GSTIN to the company, or invoice without a GST code.', 422, 'india_gst_not_registered');
    if ((string)$link['supply'] !== $ctx['supply']) {
        $other = in3_code_for_rule($companyId, (string)$link['rule_key'], $ctx['supply']);
        $where = $ctx['side'] === 'sales'
            ? 'The place of supply (' . in3_state_name($ctx['pos']) . ') is ' . ($ctx['supply'] === 'intra' ? 'the company\'s own state' : 'not the company\'s state')
            : 'The supplier\'s state (' . in3_state_name((string)$ctx['partyState']) . ') is ' . ($ctx['supply'] === 'intra' ? 'the place of supply' : 'not the place of supply');
        fail($where . ', so this is an ' . ($ctx['supply'] === 'intra' ? 'intra-state supply (CGST + SGST)' : 'inter-state supply (IGST)') . '. Use '
            . ($other ? 'code ' . $other['code'] : 'the ' . ($ctx['supply'] === 'intra' ? 'intra-state' : 'inter-state') . ' code of this rate') . ' instead of ' . $code['code'] . '.', 422, 'india_gst_supply_mismatch');
    }
    foreach ([['rule', 'gst_rate', 'rate_mpct', 'GST rate'], ['cess_rule', 'gst_cess', 'cess_mpct', 'cess']] as [$p, $cat, $col, $label]) {
        $key = (string)($link[$p . '_key'] ?? '');
        if ($key === '') continue;
        $rule = in3_rule_in_force($companyId, (string)$link[$p . '_jurisdiction'], $cat, $key, $ctx['date']);
        if ($rule === null) fail('No approved ' . $label . ' rule ' . $key . ' is in force on ' . $ctx['date'] . ', so code ' . $code['code'] . ' cannot be used on that date. Add or approve the rule version for that date in India Masters.', 422, 'india_gst_rule_not_in_force');
        if (in3_rule_mpct($rule) !== (int)$link[$col])
            fail('From ' . $rule['effective_from'] . ' the ' . $label . ' rule ' . $key . ' is ' . tax_rate_label((int)in3_rule_mpct($rule)) . ', but code ' . $code['code'] . ' uses ' . tax_rate_label((int)$link[$col]) . '. Create GST codes from the rule version in force on ' . $ctx['date'] . '.', 422, 'india_gst_rate_changed');
    }
}

/** Store the place of supply of a saved document (only when a GST context exists). */
function in3_document_save(string $companyId, string $type, string $documentId, ?array $ctx): void
{
    if ($ctx === null || !schema_table_exists('india_document_gst')) return;
    db()->prepare('INSERT INTO india_document_gst (company_id,document_type,document_id,place_of_supply,supply,company_state_code,party_state_code,company_gstin,party_gstin) VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE place_of_supply=VALUES(place_of_supply), supply=VALUES(supply), company_state_code=VALUES(company_state_code), party_state_code=VALUES(party_state_code), company_gstin=VALUES(company_gstin), party_gstin=VALUES(party_gstin)')
        ->execute([$companyId, $type, $documentId, $ctx['pos'], $ctx['supply'], $ctx['companyState'], $ctx['partyState'], $ctx['companyGstin'], $ctx['partyGstin']]);
}

/** Entry paths without a place of supply (expenses, bank lines) do not take GST codes in India. */
function in3_refuse_code_here(array $company, ?array $code, string $what): void
{
    if ($code === null || !in3_is_india($company)) return;
    fail('In an India company GST is recorded on customer invoices and vendor invoices, where the place of supply is checked. Record this ' . $what . ' without a tax code, or enter it as a vendor invoice.', 422, 'india_gst_use_documents');
}

function in3_account_id(string $companyId, string $code, string $name, string $type, string $normal): string
{
    $q = db()->prepare('SELECT id, account_type FROM accounts WHERE company_id=? AND code=?'); $q->execute([$companyId, $code]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ((string)$row['account_type'] !== $type) fail('Account ' . $code . ' already exists as a ' . $row['account_type'] . ' account, so it cannot hold ' . $name . '. Change that account\'s code, then create the GST codes again.', 409, 'india_gst_account_conflict');
        return (string)$row['id'];
    }
    tax_ensure_account($companyId, $code, $name, $type, $normal, null);
    $q->execute([$companyId, $code]);
    return (string)$q->fetchColumn();
}

/** Create the intra-state and inter-state codes of an approved GST rate rule (and an optional approved cess rule). */
function in3_codes_create(array $user, array $company, array $input): array
{
    if (!in_array((string)$company['role'], ['owner', 'admin'], true)) fail('Only the Company Owner or an Admin creates GST codes.', 403, 'india_gst_codes_role');
    $companyId = (string)$company['id'];
    $load = static function (string $id, string $cat, string $label) use ($companyId): array {
        $q = db()->prepare("SELECT * FROM statutory_rates WHERE company_id=? AND id=? AND person_type=? AND jurisdiction='IN'"); $q->execute([$companyId, $id, $cat]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) fail('Choose an all-India ' . $label . ' rule of this company.', 422, 'india_gst_rule_invalid');
        if ((string)$r['status'] !== 'active') fail('The ' . $label . ' rule ' . $r['rate_key'] . ' is not approved yet. The Company Owner or an Admin approves it in India Masters › Statutory rules.', 409, 'india_gst_rule_not_approved');
        if (in3_rule_mpct($r) === null) fail('The ' . $label . ' rule ' . $r['rate_key'] . ' has no rate.', 422, 'india_gst_rule_no_rate');
        return $r;
    };
    $rule = $load(clean_text($input['ruleId'] ?? '', 'GST rate rule', 64), 'gst_rate', 'GST rate');
    $cessId = trim((string)($input['cessRuleId'] ?? ''));
    $cess = $cessId === '' ? null : $load($cessId, 'gst_cess', 'cess');
    $rate = (int)in3_rule_mpct($rule); $cessRate = $cess ? (int)in3_rule_mpct($cess) : 0;
    if ($rate % 2 !== 0) fail('The rate ' . tax_rate_label($rate) . ' cannot be split equally into CGST and SGST to three decimals.', 422, 'india_gst_rate_split');
    $state = strtoupper((string)$company['province']);
    $second = in_array($state, IN3_UTGST_STATES, true) ? 'UTGST' : 'SGST';
    $label = rtrim(tax_rate_label($rate), '%'); $cessLabel = $cess ? 'C' . rtrim(tax_rate_label($cessRate), '%') : '';
    $plan = [
        'intra' => ['code' => 'GST' . $label . $cessLabel, 'name' => 'GST ' . tax_rate_label($rate) . ' intra-state (CGST ' . tax_rate_label(intdiv($rate, 2)) . ' + ' . $second . ' ' . tax_rate_label(intdiv($rate, 2)) . ')' . ($cess ? ' + cess ' . tax_rate_label($cessRate) : ''),
            'heads' => [['CGST', intdiv($rate, 2)], [$second, intdiv($rate, 2)]]],
        'inter' => ['code' => 'IGST' . $label . $cessLabel, 'name' => 'IGST ' . tax_rate_label($rate) . ' inter-state' . ($cess ? ' + cess ' . tax_rate_label($cessRate) : ''),
            'heads' => [['IGST', $rate]]],
    ];
    if ($cess) { $plan['intra']['heads'][] = ['CESS', $cessRate]; $plan['inter']['heads'][] = ['CESS', $cessRate]; }
    foreach ($plan as $p) if (strlen($p['code']) > 20) fail('The code ' . $p['code'] . ' would be longer than 20 characters.', 422, 'india_gst_code_long');
    if (!in3_schema_ready()) fail('Try again: the GST tables could not be prepared inside a transaction.', 409, 'india_gst_schema_busy');
    db()->beginTransaction();
    try {
        $accounts = [];
        foreach (array_unique(array_merge(...array_map(static fn($p) => array_column($p['heads'], 0), array_values($plan)))) as $head) {
            [$oc, $on] = IN3_ACCOUNTS['out'][$head]; [$ic, $iname] = IN3_ACCOUNTS['in'][$head];
            $accounts[$head] = ['out' => in3_account_id($companyId, $oc, $on, 'liability', 'credit'), 'in' => in3_account_id($companyId, $ic, $iname, 'asset', 'debit')];
        }
        $out = [];
        foreach ($plan as $supply => $p) {
            $existing = db()->prepare('SELECT t.id, g.rule_key, g.rate_mpct, g.cess_mpct, g.supply, g.cess_rule_key FROM tax_codes t LEFT JOIN india_gst_codes g ON g.tax_code_id=t.id WHERE t.company_id=? AND t.code=? FOR UPDATE');
            $existing->execute([$companyId, $p['code']]); $e = $existing->fetch(PDO::FETCH_ASSOC);
            if ($e) {
                $same = $e['rule_key'] === $rule['rate_key'] && (int)$e['rate_mpct'] === $rate && (int)$e['cess_mpct'] === $cessRate && $e['supply'] === $supply && (string)($e['cess_rule_key'] ?? '') === (string)($cess['rate_key'] ?? '');
                if (!$same) fail('A tax code ' . $p['code'] . ' already exists for another rule. Make it inactive or rename it under Settings › Tax Codes first.', 409, 'india_gst_code_exists');
                $out[] = ['id' => (string)$e['id'], 'code' => $p['code'], 'supply' => $supply, 'created' => false];
                continue;
            }
            $id = new_id('taxcode');
            db()->prepare("INSERT INTO tax_codes (id,company_id,code,name,region,description,status,created_by,updated_by) VALUES (?,?,?,?,NULL,?,'active',?,?)")
                ->execute([$id, $companyId, $p['code'], $p['name'], 'Created from GST rate rule ' . $rule['rate_key'] . ' (' . $rule['source_label'] . ')' . ($cess ? ' and cess rule ' . $cess['rate_key'] : ''), $user['id'], $user['id']]);
            $comp = db()->prepare('INSERT INTO tax_code_components (id,tax_code_id,company_id,name,rate_mpct,sales_account_id,purchase_account_id,purchase_recoverable,sort_order) VALUES (?,?,?,?,?,?,?,1,?)');
            foreach ($p['heads'] as $i => [$head, $mpct]) $comp->execute([new_id('taxcomp'), $id, $companyId, $head === 'CESS' ? 'Cess' : $head, $mpct, $accounts[$head]['out'], $accounts[$head]['in'], $i]);
            db()->prepare('INSERT INTO india_gst_codes (tax_code_id,company_id,rule_jurisdiction,rule_key,rule_id,cess_rule_jurisdiction,cess_rule_key,cess_rule_id,supply,rate_mpct,cess_mpct,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $companyId, $rule['jurisdiction'], $rule['rate_key'], $rule['id'], $cess['jurisdiction'] ?? null, $cess['rate_key'] ?? null, $cess['id'] ?? null, $supply, $rate, $cessRate, $user['id']]);
            $out[] = ['id' => $id, 'code' => $p['code'], 'supply' => $supply, 'created' => true];
        }
        audit_event($user, $companyId, 'india.gst_codes_created', 'statutory_rate', (string)$rule['id'], ['ruleKey' => $rule['rate_key'], 'rateMpct' => $rate, 'cessRuleKey' => $cess['rate_key'] ?? null, 'cessMpct' => $cessRate, 'codes' => $out]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['codes' => $out];
}

function in3_codes_list(string $companyId): array
{
    if (!schema_table_exists('india_gst_codes')) return [];
    $links = db()->prepare('SELECT * FROM india_gst_codes WHERE company_id=?'); $links->execute([$companyId]);
    $by = []; foreach ($links->fetchAll(PDO::FETCH_ASSOC) as $l) $by[(string)$l['tax_code_id']] = $l;
    $out = [];
    foreach (tax_codes_list($companyId, true) as $c) {
        $l = $by[$c['id']] ?? null; if ($l === null) continue;
        $out[] = $c + ['supply' => $l['supply'], 'ruleKey' => $l['rule_key'], 'ruleId' => $l['rule_id'], 'rateMpct' => (int)$l['rate_mpct'], 'cessRuleKey' => $l['cess_rule_key'], 'cessMpct' => (int)$l['cess_mpct']];
    }
    usort($out, static fn($a, $b) => [$a['rateMpct'], $a['supply']] <=> [$b['rateMpct'], $b['supply']]);
    return $out;
}

/* ---------- GST summary (computed from the books; nothing is filed) ---------- */

function in3_summary(array $company, string $from, string $to): array
{
    $companyId = (string)$company['id'];
    $empty = ['from' => $from, 'to' => $to, 'outward' => [], 'inward' => [], 'heads' => [], 'documents' => []];
    if (!schema_table_exists('india_gst_codes')) return $empty;
    $heads = array_fill_keys(IN3_HEADS, ['output' => 0, 'credit' => 0, 'notClaimed' => 0]);
    $headOf = static fn(string $name): string => strtoupper($name) === 'CESS' ? 'CESS' : (in_array(strtoupper($name), IN3_HEADS, true) ? strtoupper($name) : 'CESS');
    // Outward: issued customer invoices (not drafts, not void) dated in the period.
    $sales = db()->prepare("SELECT i.id, i.number, i.issue_date, c.name party, d.place_of_supply, d.supply, d.party_gstin, il.tax_code_id, t.code, g.rate_mpct, g.cess_mpct, SUM(il.amount_cents) taxable
        FROM invoices i JOIN customers c ON c.id=i.customer_id JOIN invoice_lines il ON il.invoice_id=i.id JOIN india_gst_codes g ON g.tax_code_id=il.tax_code_id AND g.company_id=i.company_id JOIN tax_codes t ON t.id=g.tax_code_id
        LEFT JOIN india_document_gst d ON d.company_id=i.company_id AND d.document_type='invoice' AND d.document_id=i.id
        WHERE i.company_id=? AND i.status NOT IN ('draft','void') AND i.issue_date BETWEEN ? AND ? GROUP BY i.id, il.tax_code_id ORDER BY i.issue_date, i.number");
    $sales->execute([$companyId, $from, $to]);
    $tax = static function (string $type, string $id) use ($companyId): array {
        $q = db()->prepare('SELECT tax_code_id, component_name, recoverable, tax_cents FROM document_tax_lines WHERE company_id=? AND document_type=? AND document_id=?');
        $q->execute([$companyId, $type, $id]); return $q->fetchAll(PDO::FETCH_ASSOC);
    };
    $outward = []; $inward = []; $docs = []; $taxCache = [];
    foreach ($sales->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $taxCache['invoice|' . $r['id']] ??= $tax('invoice', (string)$r['id']);
        $byHead = array_fill_keys(IN3_HEADS, 0);
        foreach ($taxCache['invoice|' . $r['id']] as $t) if ($t['tax_code_id'] === $r['tax_code_id']) $byHead[$headOf((string)$t['component_name'])] += (int)$t['tax_cents'];
        $k = $r['supply'] . '|' . $r['rate_mpct'] . '|' . $r['cess_mpct'];
        $outward[$k] ??= ['supply' => $r['supply'], 'rateMpct' => (int)$r['rate_mpct'], 'cessMpct' => (int)$r['cess_mpct'], 'taxableCents' => 0] + array_fill_keys(IN3_HEADS, 0);
        $outward[$k]['taxableCents'] += (int)$r['taxable'];
        foreach ($byHead as $h => $v) { $outward[$k][$h] += $v; $heads[$h]['output'] += $v; }
        $docs[] = ['type' => 'invoice', 'id' => $r['id'], 'number' => $r['number'], 'date' => $r['issue_date'], 'party' => $r['party'], 'partyGstin' => $r['party_gstin'], 'placeOfSupply' => $r['place_of_supply'], 'supply' => $r['supply'], 'code' => $r['code'], 'taxableCents' => (int)$r['taxable']] + $byHead;
    }
    // Inward: posted vendor invoices dated in the period.
    $bills = db()->prepare("SELECT b.id, b.number, b.bill_date, v.name party, d.place_of_supply, d.supply, d.party_gstin, b.tax_code_id, t.code, g.rate_mpct, g.cess_mpct, b.subtotal_cents taxable
        FROM bills b JOIN vendors v ON v.id=b.vendor_id JOIN india_gst_codes g ON g.tax_code_id=b.tax_code_id AND g.company_id=b.company_id JOIN tax_codes t ON t.id=g.tax_code_id
        LEFT JOIN india_document_gst d ON d.company_id=b.company_id AND d.document_type='bill' AND d.document_id=b.id
        WHERE b.company_id=? AND b.status IN ('open','paid') AND b.bill_date BETWEEN ? AND ? ORDER BY b.bill_date, b.number");
    $bills->execute([$companyId, $from, $to]);
    foreach ($bills->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $credit = array_fill_keys(IN3_HEADS, 0); $notClaimed = 0;
        foreach ($tax('bill', (string)$r['id']) as $t) {
            $h = $headOf((string)$t['component_name']);
            if ((int)$t['recoverable'] === 1) { $credit[$h] += (int)$t['tax_cents']; $heads[$h]['credit'] += (int)$t['tax_cents']; }
            else { $notClaimed += (int)$t['tax_cents']; $heads[$h]['notClaimed'] += (int)$t['tax_cents']; }
        }
        $k = $r['supply'] . '|' . $r['rate_mpct'] . '|' . $r['cess_mpct'];
        $inward[$k] ??= ['supply' => $r['supply'], 'rateMpct' => (int)$r['rate_mpct'], 'cessMpct' => (int)$r['cess_mpct'], 'taxableCents' => 0, 'notClaimedCents' => 0] + array_fill_keys(IN3_HEADS, 0);
        $inward[$k]['taxableCents'] += (int)$r['taxable']; $inward[$k]['notClaimedCents'] += $notClaimed;
        foreach ($credit as $h => $v) $inward[$k][$h] += $v;
        $docs[] = ['type' => 'bill', 'id' => $r['id'], 'number' => $r['number'], 'date' => $r['bill_date'], 'party' => $r['party'], 'partyGstin' => $r['party_gstin'], 'placeOfSupply' => $r['place_of_supply'], 'supply' => $r['supply'], 'code' => $r['code'], 'taxableCents' => (int)$r['taxable'], 'notClaimedCents' => $notClaimed] + $credit;
    }
    $headRows = [];
    foreach ($heads as $h => $v) $headRows[] = ['head' => $h, 'outputCents' => $v['output'], 'creditCents' => $v['credit'], 'netCents' => $v['output'] - $v['credit'], 'notClaimedCents' => $v['notClaimed']];
    return ['from' => $from, 'to' => $to, 'outward' => array_values($outward), 'inward' => array_values($inward), 'heads' => $headRows, 'documents' => $docs,
        'note' => 'Computed from issued customer invoices and posted vendor invoices that use GST codes. Credit and debit notes, reverse charge and ITC reversals are not included yet. Nothing is filed.'];
}

/* ---------- routes: india/gst/... ---------- */

function handle_india_gst(string $action): never
{
    $user = require_user(); $company = require_company($user);
    if (!in3_is_india($company)) fail('Not found.', 404, 'india_only');
    $companyId = (string)$company['id'];
    $method = request_method();
    if ($method === 'GET' && $action === 'context') {
        require_company_permission($company, 'company.view');
        in3_schema_ready();
        $rules = db()->prepare("SELECT id, person_type category, rate_key code, label, value_decimal, effective_from, effective_to FROM statutory_rates WHERE company_id=? AND jurisdiction='IN' AND person_type IN ('gst_rate','gst_cess') AND status='active' ORDER BY person_type, rate_key, effective_from");
        $rules->execute([$companyId]);
        $date = trim((string)($_GET['date'] ?? '')); $date = $date === '' ? canadian_today() : safe_date($date, 'Date');
        $partyStates = ['customers' => [], 'vendors' => []];
        foreach (['customers' => ['customers', 'customer'], 'vendors' => ['vendors', 'vendor']] as $k => [$table, $type]) {
            $q = db()->prepare("SELECT id, province, country FROM `$table` WHERE company_id=?"); $q->execute([$companyId]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) { $s = in3_party_state($p, in3_party_gstin($companyId, $type, (string)$p['id'], $date)); if ($s !== null) $partyStates[$k][(string)$p['id']] = $s; }
        }
        $itemRules = [];
        if (schema_table_exists('india_entity_profiles')) {
            $q = db()->prepare("SELECT DISTINCT entity_id FROM india_entity_profiles WHERE company_id=? AND entity_type='item'"); $q->execute([$companyId]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $itemId) { $key = in3_item_rule_key($companyId, (string)$itemId, $date); if ($key !== null) $itemRules[(string)$itemId] = $key; }
        }
        json_response(['codes' => in3_codes_list($companyId), 'rules' => $rules->fetchAll(PDO::FETCH_ASSOC), 'placesOfSupply' => in3_places_of_supply(), 'partyStates' => $partyStates, 'itemRules' => $itemRules,
            'companyStateCode' => in3_state_code((string)$company['province']), 'companyGstin' => in3_company_gstin($company),
            'canCreate' => in_array((string)$company['role'], ['owner', 'admin'], true)]);
    }
    if ($method === 'GET' && $action === 'summary') {
        require_company_permission($company, 'reports.view');
        $from = safe_date((string)($_GET['from'] ?? ''), 'From'); $to = safe_date((string)($_GET['to'] ?? ''), 'To');
        if ($to < $from) fail('To is before From.', 422, 'india_gst_period');
        json_response(in3_summary($company, $from, $to));
    }
    if ($method === 'GET' && $action === 'document') {
        require_company_permission($company, 'company.view');
        $type = (string)($_GET['type'] ?? ''); if (!in_array($type, ['invoice', 'bill'], true)) fail('Unknown document type.', 422, 'india_gst_doc_type');
        $id = clean_text($_GET['id'] ?? '', 'Document', 64);
        $row = null;
        if (schema_table_exists('india_document_gst')) { $q = db()->prepare('SELECT * FROM india_document_gst WHERE company_id=? AND document_type=? AND document_id=?'); $q->execute([$companyId, $type, $id]); $row = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
        json_response(['gst' => $row ? ['placeOfSupply' => $row['place_of_supply'], 'placeName' => in3_state_name((string)$row['place_of_supply']), 'supply' => $row['supply'], 'partyState' => $row['party_state_code'], 'companyGstin' => $row['company_gstin'], 'partyGstin' => $row['party_gstin']] : null]);
    }
    if ($method === 'POST' && $action === 'codes') {
        require_csrf();
        json_response(in3_codes_create($user, $company, request_json()), 201);
    }
    fail('Not found.', 404, 'route_not_found');
}
