<?php
declare(strict_types=1);

/*
 * IN-1: India edition foundation.
 *
 * Tegh has two editions, Canada (CA, every company before IN-1) and India (IN). The person chooses the edition on the
 * sign-in screen; the session remembers it; the company list shows only that edition's companies; and require_company()
 * refuses a company of the other edition. A company's edition is set when it is created and never changes.
 *
 * The India edition is behind the release switch "India edition" (Site & Release Settings): off (default), platform
 * owner only, or everyone. While it is off nothing here changes what anyone sees, and no column or table is created.
 *
 * Canadian behaviour is not edited: every Canadian path runs exactly as before. India companies are created by
 * tegh_india_company_create() below, with an Indian chart of accounts and no Canadian tax, payroll or report set-up.
 */

const TEGH_EDITIONS = ['CA', 'IN'];

/** Indian states and union territories: ISO 3166-2:IN code => [name, GST state code, union territory]. */
const TEGH_INDIA_STATES = [
    'AN' => ['Andaman and Nicobar Islands', '35', true], 'AP' => ['Andhra Pradesh', '37', false], 'AR' => ['Arunachal Pradesh', '12', false],
    'AS' => ['Assam', '18', false], 'BR' => ['Bihar', '10', false], 'CH' => ['Chandigarh', '04', true], 'CT' => ['Chhattisgarh', '22', false],
    'DH' => ['Dadra and Nagar Haveli and Daman and Diu', '26', true], 'DL' => ['Delhi', '07', true], 'GA' => ['Goa', '30', false],
    'GJ' => ['Gujarat', '24', false], 'HR' => ['Haryana', '06', false], 'HP' => ['Himachal Pradesh', '02', false],
    'JK' => ['Jammu and Kashmir', '01', true], 'JH' => ['Jharkhand', '20', false], 'KA' => ['Karnataka', '29', false],
    'KL' => ['Kerala', '32', false], 'LA' => ['Ladakh', '38', true], 'LD' => ['Lakshadweep', '31', true],
    'MP' => ['Madhya Pradesh', '23', false], 'MH' => ['Maharashtra', '27', false], 'MN' => ['Manipur', '14', false],
    'ML' => ['Meghalaya', '17', false], 'MZ' => ['Mizoram', '15', false], 'NL' => ['Nagaland', '13', false],
    'OR' => ['Odisha', '21', false], 'PY' => ['Puducherry', '34', true], 'PB' => ['Punjab', '03', false],
    'RJ' => ['Rajasthan', '08', false], 'SK' => ['Sikkim', '11', false], 'TN' => ['Tamil Nadu', '33', false],
    'TG' => ['Telangana', '36', false], 'TR' => ['Tripura', '16', false], 'UP' => ['Uttar Pradesh', '09', false],
    'UT' => ['Uttarakhand', '05', false], 'WB' => ['West Bengal', '19', false],
];

/** Business constitution => [label, closest existing business_type]. The constitution itself is kept in the India profile. */
const TEGH_INDIA_CONSTITUTIONS = [
    'proprietorship' => ['Proprietorship', 'sole_proprietor'], 'partnership' => ['Partnership firm', 'partnership'],
    'llp' => ['Limited liability partnership (LLP)', 'partnership'], 'private_limited' => ['Private limited company', 'corporation'],
    'public_limited' => ['Public limited company', 'corporation'], 'opc' => ['One person company (OPC)', 'corporation'],
    'huf' => ['Hindu undivided family (HUF)', 'sole_proprietor'], 'trust' => ['Trust', 'non_profit'],
    'society' => ['Society', 'non_profit'], 'section8' => ['Section 8 company', 'non_profit'],
];

function tegh_india_edition_mode(): string
{
    if (!function_exists('r174_group')) require_once __DIR__ . '/site_settings_r174.php';
    $mode = (string)(r174_group('release')['value']['indiaEdition'] ?? 'off');
    return in_array($mode, ['off', 'owner', 'everyone'], true) ? $mode : 'off';
}

function tegh_india_allowed_for(string $userId): bool
{
    $mode = tegh_india_edition_mode();
    if ($mode === 'everyone') return true;
    return $mode === 'owner' && function_exists('platform_role_for_user') && platform_role_for_user($userId) === 'platform_owner';
}

/** Columns and the India profile table, created on first use (as R174 to R184 did); never while the switch is off. */
function tegh_edition_schema_ready(): void
{
    static $ready = false;
    if ($ready) return;
    if (!schema_column_exists('sessions', 'edition')) db()->exec("ALTER TABLE sessions ADD COLUMN edition CHAR(2) NOT NULL DEFAULT 'CA'");
    if (!schema_column_exists('companies', 'edition')) db()->exec("ALTER TABLE companies ADD COLUMN edition CHAR(2) NOT NULL DEFAULT 'CA'");
    db()->exec("CREATE TABLE IF NOT EXISTS company_india_profile (
        company_id VARCHAR(64) NOT NULL PRIMARY KEY,
        constitution VARCHAR(20) NOT NULL,
        pan CHAR(10) NULL,
        gstin CHAR(15) NULL,
        gst_state_code CHAR(2) NULL,
        tan CHAR(10) NULL,
        cin VARCHAR(21) NULL,
        trade_name VARCHAR(200) NULL,
        district VARCHAR(100) NULL,
        pin CHAR(6) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_company_india_profile_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    tegh_edition_columns_present(true);
    $ready = true;
}

/** The edition of the current request's session: set at sign-in, otherwise read from the session row. Canada by default. */
function tegh_session_edition(): string
{
    if (isset($GLOBALS['tegh_session_edition']) && in_array($GLOBALS['tegh_session_edition'], TEGH_EDITIONS, true)) return $GLOBALS['tegh_session_edition'];
    if (!tegh_edition_columns_present() || !function_exists('session_record')) return 'CA';
    $session = session_record();
    $edition = strtoupper((string)($session['edition'] ?? 'CA'));
    return in_array($edition, TEGH_EDITIONS, true) ? $edition : 'CA';
}

/** Whether the edition columns exist, checked once per request (Canada-only installs never pay for it twice). */
function tegh_edition_columns_present(bool $refresh = false): bool
{
    static $present = null;
    if ($present === null || $refresh) $present = schema_column_exists('companies', 'edition') && schema_column_exists('sessions', 'edition');
    return $present;
}

function tegh_company_edition(array $company): string
{
    $edition = strtoupper((string)($company['edition'] ?? 'CA'));
    return in_array($edition, TEGH_EDITIONS, true) ? $edition : 'CA';
}

/** Called by require_company(): a company opens only in its own edition. */
function tegh_assert_company_edition(array $company): void
{
    $want = tegh_session_edition(); $have = tegh_company_edition($company);
    if ($want === $have) return;
    fail($have === 'IN'
        ? 'This company is in the India edition. Sign out, choose India on the sign-in screen, and sign in again to open it.'
        : 'This company is in the Canada edition. Sign out, choose Canada on the sign-in screen, and sign in again to open it.', 403, 'edition_mismatch');
}

/**
 * Sign-in: the edition the person chose. India needs the switch (and, in owner-only mode, the platform owner).
 * Called after the password is verified, before the session is created.
 */
function tegh_login_edition(array $input, string $userId): string
{
    $requested = strtoupper(trim((string)($input['edition'] ?? 'CA')));
    if (!in_array($requested, TEGH_EDITIONS, true)) fail('Choose Canada or India.', 422, 'edition_invalid');
    if ($requested === 'IN') {
        if (!tegh_india_allowed_for($userId)) fail('The India edition is not available for this sign-in yet. Choose Canada.', 403, 'india_edition_unavailable');
        tegh_edition_schema_ready();
    }
    $GLOBALS['tegh_session_edition'] = $requested;
    return $requested;
}

/** create_session(): store a non-Canadian edition on the new session row. */
function tegh_session_store_edition(string $sessionId): void
{
    $edition = $GLOBALS['tegh_session_edition'] ?? 'CA';
    if ($edition === 'CA' || !tegh_edition_columns_present()) return;
    db()->prepare('UPDATE sessions SET edition = ? WHERE id = ?')->execute([$edition, $sessionId]);
}

/** Extra WHERE for company lists: only the session edition's companies. Empty while no company has an edition. */
function tegh_edition_company_filter(string $alias = 'c'): string
{
    if (!tegh_edition_columns_present()) return '';
    return tegh_session_edition() === 'IN' ? " AND $alias.edition = 'IN'" : " AND ($alias.edition IS NULL OR $alias.edition <> 'IN')";
}

/** Database maintenance that adds Canadian accounts or rates to every company skips India companies. */
function tegh_canadian_companies_where(): string
{
    return tegh_edition_columns_present() ? " WHERE (edition IS NULL OR edition <> 'IN')" : '';
}

/* ---------- identifiers ---------- */

function tegh_india_pan_valid(string $pan): bool
{
    return (bool)preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan);
}

/** GSTIN: state code (2) + PAN (10) + entity (1) + 'Z' + check character, with the published base-36 checksum. */
function tegh_india_gstin_problem(string $gstin, ?string $pan = null): ?string
{
    if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) return 'A GSTIN has 15 characters: 2-digit state code, the 10-character PAN, an entity number, Z and a check character.';
    $codes = array_column(TEGH_INDIA_STATES, 1);
    if (!in_array(substr($gstin, 0, 2), $codes, true) && substr($gstin, 0, 2) !== '97') return 'The first two digits of the GSTIN are not an Indian state or union territory code.';
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; $sum = 0;
    for ($i = 0; $i < 14; $i++) { $product = strpos($chars, $gstin[$i]) * ($i % 2 === 0 ? 1 : 2); $sum += intdiv($product, 36) + $product % 36; }
    if ($chars[(36 - $sum % 36) % 36] !== $gstin[14]) return 'The GSTIN check character does not match. Check the number on the registration certificate.';
    if ($pan !== null && $pan !== '' && substr($gstin, 2, 10) !== $pan) return 'The PAN inside the GSTIN (characters 3 to 12) is not the company PAN.';
    return null;
}

/* ---------- India company ---------- */

/** The India starter chart: Schedule III headings, no tax or payroll accounts (the person adds those in set-up). */
function tegh_india_default_chart(): array
{
    // [code, name, type, normal balance, control]
    return [
        ['1000', 'Bank - Current Account', 'asset', 'debit', 0],
        ['1010', 'Cash in Hand', 'asset', 'debit', 0],
        ['1070', 'Interbank Transfer Clearing', 'asset', 'debit', 0],
        ['1200', 'Trade Receivables', 'asset', 'debit', 1],
        ['1300', 'Advances to Suppliers', 'asset', 'debit', 0],
        ['1310', 'Prepaid Expenses', 'asset', 'debit', 0],
        ['1400', 'Inventories', 'asset', 'debit', 0],
        ['1500', 'Property, Plant and Equipment', 'asset', 'debit', 0],
        ['1590', 'Accumulated Depreciation', 'asset', 'credit', 0],
        ['1600', 'Security Deposits', 'asset', 'debit', 0],
        ['2050', 'Trade Payables', 'liability', 'credit', 1],
        ['2200', 'Short-term Borrowings', 'liability', 'credit', 0],
        ['2300', 'Salaries Payable', 'liability', 'credit', 0],
        ['2500', 'Advances from Customers', 'liability', 'credit', 0],
        ['2600', 'Provisions', 'liability', 'credit', 0],
        ['2700', 'Other Current Liabilities', 'liability', 'credit', 0],
        ['3000', 'Share Capital / Owner\'s Capital', 'equity', 'credit', 0],
        ['3100', 'Drawings', 'equity', 'debit', 0],
        ['3200', 'Reserves and Surplus', 'equity', 'credit', 0],
        ['4000', 'Revenue from Operations - Sale of Goods', 'income', 'credit', 0],
        ['4100', 'Revenue from Operations - Sale of Services', 'income', 'credit', 0],
        ['4500', 'Other Income', 'income', 'credit', 0],
        ['4600', 'Interest Income', 'income', 'credit', 0],
        ['5000', 'Purchases of Stock-in-Trade', 'expense', 'debit', 0],
        ['5100', 'Changes in Inventories', 'expense', 'debit', 0],
        ['6000', 'Salaries and Wages', 'expense', 'debit', 0],
        ['6100', 'Rent', 'expense', 'debit', 0],
        ['6200', 'Legal and Professional Fees', 'expense', 'debit', 0],
        ['6300', 'Telephone and Internet', 'expense', 'debit', 0],
        ['6400', 'Office Expenses', 'expense', 'debit', 0],
        ['6500', 'Travelling and Conveyance', 'expense', 'debit', 0],
        ['6600', 'Bank Charges', 'expense', 'debit', 0],
        ['6700', 'Repairs and Maintenance', 'expense', 'debit', 0],
        ['6800', 'Finance Costs', 'expense', 'debit', 0],
        ['6810', 'Depreciation and Amortisation', 'expense', 'debit', 0],
        ['6850', 'Foreign Exchange Gain / Loss', 'expense', 'debit', 0],
        ['6900', 'Miscellaneous Expenses', 'expense', 'debit', 0],
    ];
}

function tegh_india_company_create(array $user, array $input): never
{
    if (!tegh_india_allowed_for((string)$user['id'])) fail('The India edition is not available for this sign-in.', 403, 'india_edition_unavailable');
    tegh_edition_schema_ready();
    $name = clean_text($input['name'] ?? '', 'Company name', 160);
    $legalName = clean_text($input['legalName'] ?? $name, 'Legal name', 200);
    $constitution = (string)($input['constitution'] ?? 'private_limited');
    if (!isset(TEGH_INDIA_CONSTITUTIONS[$constitution])) fail('Choose the business constitution.', 422, 'india_constitution_invalid');
    $state = strtoupper(trim((string)($input['state'] ?? '')));
    if (!isset(TEGH_INDIA_STATES[$state])) fail('Choose the state or union territory.', 422, 'india_state_required');
    $pan = strtoupper(trim((string)($input['pan'] ?? '')));
    if ($pan !== '' && !tegh_india_pan_valid($pan)) fail('A PAN has 10 characters: 5 letters, 4 digits and a letter (for example AAACT1234F).', 422, 'india_pan_invalid');
    $gstin = strtoupper(trim((string)($input['gstin'] ?? '')));
    if ($gstin !== '') {
        if ($problem = tegh_india_gstin_problem($gstin, $pan !== '' ? $pan : null)) fail($problem, 422, 'india_gstin_invalid');
        if (substr($gstin, 0, 2) !== TEGH_INDIA_STATES[$state][1]) fail('The GSTIN state code (' . substr($gstin, 0, 2) . ') is not the state chosen for the company (' . TEGH_INDIA_STATES[$state][1] . ' ' . TEGH_INDIA_STATES[$state][0] . ').', 422, 'india_gstin_state');
        if ($pan === '') $pan = substr($gstin, 2, 10);
    }
    $pin = trim((string)($input['pin'] ?? ''));
    if ($pin !== '' && !preg_match('/^[1-9][0-9]{5}$/', $pin)) fail('A PIN code has 6 digits and does not start with 0.', 422, 'india_pin_invalid');
    $tan = strtoupper(trim((string)($input['tan'] ?? '')));
    if ($tan !== '' && !preg_match('/^[A-Z]{4}[0-9]{5}[A-Z]$/', $tan)) fail('A TAN has 10 characters: 4 letters, 5 digits and a letter.', 422, 'india_tan_invalid');
    $cin = strtoupper(trim((string)($input['cin'] ?? '')));
    if ($cin !== '' && !preg_match('/^[A-Z0-9-]{8,21}$/', $cin)) fail('Enter the CIN or LLPIN as shown on the certificate of incorporation.', 422, 'india_cin_invalid');
    // Financial year: April to March by default.
    $fyEnd = (string)($input['fiscalYearEnd'] ?? '03-31');
    if (!preg_match('/^(\d{2})-(\d{2})$/', $fyEnd, $m) || !checkdate((int)$m[1], (int)$m[2], 2001)) fail('Choose a valid financial year end.', 422, 'india_fy_invalid');
    $booksStart = safe_date((string)($input['booksStartDate'] ?? '') ?: (date('n') >= 4 ? date('Y') . '-04-01' : (date('Y') - 1) . '-04-01'), 'Start of books');
    $fyEndDate = (int)substr($booksStart, 0, 4) + ((int)$m[1] < (int)substr($booksStart, 5, 2) ? 1 : 0) . '-' . $fyEnd;
    $basis = (string)($input['accountingBasis'] ?? 'accrual'); if (!in_array($basis, ['accrual', 'cash'], true)) fail('Accounting basis is invalid.');
    $coaMode = (string)($input['coaMode'] ?? 'default'); if (!in_array($coaMode, ['default', 'manual'], true)) fail('Chart of accounts option is invalid.');
    $address1 = optional_text($input['addressLine1'] ?? null, 200); $address2 = optional_text($input['addressLine2'] ?? null, 200);
    $city = optional_text($input['city'] ?? null, 100); $district = optional_text($input['district'] ?? null, 100);
    $tradeName = optional_text($input['tradeName'] ?? null, 200);
    $companyId = new_id('company');
    if (function_exists('r153_schema_ready')) r153_schema_ready();
    db()->beginTransaction();
    try {
        db()->prepare("INSERT INTO companies (id, name, legal_name, business_type, province, country, currency, accounting_basis, module_mode, payroll_posting_mode, tax_reporting_profile, reporting_framework, fiscal_year_end, fiscal_year_end_date, books_start_date, tax_registered, tax_number, tax_rate_bps, pst_registered, pst_rate_bps, pst_recoverable, edition)
            VALUES (?, ?, ?, ?, ?, 'India', 'INR', ?, 'accounting', 'none', 'none', 'not_set', ?, ?, ?, ?, ?, 0, 0, 0, 0, 'IN')")
            ->execute([$companyId, $name, $legalName, TEGH_INDIA_CONSTITUTIONS[$constitution][1], $state, $basis, $fyEnd, $fyEndDate, $booksStart, $gstin !== '' ? 1 : 0, $gstin !== '' ? $gstin : null]);
        if (schema_column_exists('companies', 'tax_setup_mode')) db()->prepare("UPDATE companies SET tax_setup_mode = 'codes' WHERE id = ?")->execute([$companyId]);
        if (schema_column_exists('companies', 'timezone')) db()->prepare("UPDATE companies SET timezone = 'Asia/Kolkata' WHERE id = ?")->execute([$companyId]);
        if (schema_column_exists('companies', 'address_line1')) db()->prepare('UPDATE companies SET address_line1 = ?, address_line2 = ?, city = ?, postal_code = ? WHERE id = ?')->execute([$address1, $address2, $city, $pin !== '' ? $pin : null, $companyId]);
        db()->prepare('INSERT INTO company_india_profile (company_id, constitution, pan, gstin, gst_state_code, tan, cin, trade_name, district, pin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$companyId, $constitution, $pan !== '' ? $pan : null, $gstin !== '' ? $gstin : null, TEGH_INDIA_STATES[$state][1], $tan !== '' ? $tan : null, $cin !== '' ? $cin : null, $tradeName, $district, $pin !== '' ? $pin : null]);
        db()->prepare("INSERT INTO company_members (company_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$companyId, $user['id']]);
        db()->prepare('INSERT INTO company_currencies (company_id, currency_code, rate_to_base_micros, rate_date) VALUES (?, \'INR\', 1000000, CURRENT_DATE)')->execute([$companyId]);
        db()->prepare('INSERT INTO accounting_controls (company_id) VALUES (?)')->execute([$companyId]);
        db()->prepare("INSERT INTO document_sequences (company_id, document_type, next_number) VALUES (?, 'invoice', 1001)")->execute([$companyId]);
        if (schema_table_exists('voucher_sequences')) db()->prepare('INSERT INTO voucher_sequences (company_id,next_serial) VALUES (?,1)')->execute([$companyId]);
        $template = db()->prepare("INSERT INTO invoice_templates (id, company_id, name, is_default, document_title, accent_color, layout_style, business_address, payment_instructions, footer)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Payment is due by the date shown above.', ?)");
        $place = TEGH_INDIA_STATES[$state][0] . ', India';
        foreach ([['Tegh Standard', 1, 'TAX INVOICE', '#0D6B57', 'classic', 'Thank you for your business.'], ['Tegh Modern', 0, 'TAX INVOICE', '#1F5EFF', 'modern', 'Thank you. We appreciate your business.'],
                  ['Tegh Minimal', 0, 'INVOICE', '#111827', 'minimal', 'Thank you for your business.'], ['Tegh Professional', 0, 'TAX INVOICE', '#0B4F6C', 'professional', 'We appreciate your prompt payment.'],
                  ['Tegh Compact', 0, 'INVOICE', '#6B4EFF', 'compact', 'Thank you.']] as [$tName, $default, $title, $colour, $layout, $footer])
            $template->execute([new_id('invtemplate'), $companyId, $tName, $default, $title, $colour, $layout, $place, $footer]);
        $ids = [];
        $insert = db()->prepare('INSERT INTO accounts (id, company_id, code, name, account_type, normal_balance, is_control, gifi_code) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)');
        foreach ($coaMode === 'default' ? tegh_india_default_chart() : [] as [$code, $aName, $type, $normal, $control]) { $ids[$code] = new_id('account'); $insert->execute([$ids[$code], $companyId, $code, $aName, $type, $normal, $control]); }
        $ids['9999'] = new_id('account');
        db()->prepare("INSERT INTO accounts (id,company_id,code,name,description,account_type,normal_balance,is_control,active) VALUES (?,?, '9999','Opening Balance Control','Tegh system control account used only by authorized opening-balance services.','equity','credit',1,1)")->execute([$ids['9999'], $companyId]);
        if (schema_table_exists('company_system_accounts')) {
            $map = db()->prepare("INSERT INTO company_system_accounts (company_id,system_key,configured_code,account_id,status,conflict_message) VALUES (?, ?, ?, ?, 'active', NULL)");
            $map->execute([$companyId, 'opening_balance_control', '9999', $ids['9999']]);
            if (isset($ids['1200'])) $map->execute([$companyId, 'accounts_receivable', '1200', $ids['1200']]);
        }
        if (isset($ids['1000'])) db()->prepare("INSERT INTO bank_accounts (id, company_id, ledger_account_id, name, account_type, masked_number, currency) VALUES (?, ?, ?, 'Current Account', 'bank', NULL, 'INR')")->execute([new_id('bank'), $companyId, $ids['1000']]);
        audit_event($user, $companyId, 'company.created', 'company', $companyId, ['name' => $name, 'edition' => 'IN', 'country' => 'India', 'state' => $state, 'currency' => 'INR', 'constitution' => $constitution,
            'gstinRecorded' => $gstin !== '', 'panRecorded' => $pan !== '', 'fiscalYearEnd' => $fyEnd, 'booksStartDate' => $booksStart, 'coaMode' => $coaMode]);
        if (function_exists('tegh_company_entitlements_initialize')) tegh_company_entitlements_initialize($user, $companyId, $name);
        db()->commit();
    } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
    $companies = companies_for_user((string)$user['id']);
    $created = array_values(array_filter($companies, static fn(array $c): bool => $c['id'] === $companyId))[0] ?? null;
    json_response(['company' => $created, 'companies' => $companies], 201);
}

/** India facts for the browser: formats, states, constitutions and the company's India profile. */
function tegh_india_profile_public(string $companyId): ?array
{
    if (!schema_table_exists('company_india_profile')) return null;
    $q = db()->prepare('SELECT constitution, pan, gstin, gst_state_code, tan, cin, trade_name, district, pin FROM company_india_profile WHERE company_id = ?');
    $q->execute([$companyId]); $r = $q->fetch();
    if (!$r) return null;
    $mask = static fn(?string $v): ?string => $v === null || $v === '' ? null : substr($v, 0, 2) . str_repeat('•', max(0, strlen($v) - 4)) . substr($v, -2);
    return ['constitution' => (string)$r['constitution'], 'constitutionLabel' => TEGH_INDIA_CONSTITUTIONS[(string)$r['constitution']][0] ?? (string)$r['constitution'],
        'panMasked' => $mask($r['pan']), 'gstin' => $r['gstin'], 'gstStateCode' => $r['gst_state_code'], 'tanMasked' => $mask($r['tan']), 'cin' => $r['cin'],
        'tradeName' => $r['trade_name'], 'district' => $r['district'], 'pin' => $r['pin']];
}

function tegh_india_reference_public(): array
{
    $states = []; foreach (TEGH_INDIA_STATES as $code => [$name, $gst, $ut]) $states[] = ['code' => $code, 'name' => $name, 'gstCode' => $gst, 'unionTerritory' => $ut];
    usort($states, static fn($a, $b) => strcmp($a['name'], $b['name']));
    $constitutions = []; foreach (TEGH_INDIA_CONSTITUTIONS as $code => [$label]) $constitutions[] = ['code' => $code, 'label' => $label];
    return ['states' => $states, 'constitutions' => $constitutions, 'currency' => 'INR', 'numberLocale' => 'en-IN', 'dateFormat' => 'DD-MM-YYYY', 'fiscalYearEnd' => '03-31'];
}
