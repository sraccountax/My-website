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
    // IN-7a: full Indian address, financial year start and the first bank account type (India-only table).
    if (!schema_column_exists('company_india_profile', 'address_json')) db()->exec('ALTER TABLE company_india_profile ADD COLUMN address_json LONGTEXT NULL, ADD COLUMN fy_start_date DATE NULL');
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

/* ---------- IN-7a: set-up inputs shared by create and edit ---------- */

/** Any IANA time zone (the browser lists them all); India Standard Time by default. */
function tegh_india_timezone(mixed $value): string
{
    $zone = trim((string)$value);
    if ($zone === '') return 'Asia/Kolkata';
    if (!in_array($zone, DateTimeZone::listIdentifiers(), true)) fail('Choose a time zone from the list.', 422, 'india_timezone_invalid');
    return $zone;
}

/**
 * Financial year from its start and end dates (date pickers). The end is after the start and at most 18 months later,
 * so a first financial year that starts mid-year (for example from incorporation to 31 March) is allowed.
 * Older clients that send only "fiscalYearEnd" (MM-DD) keep working.
 * @return array{0:string,1:string,2:string} [start, end, MM-DD of the end]
 */
function tegh_india_financial_year(array $input, string $booksStart): array
{
    $s = trim((string)($input['financialYearStart'] ?? '')); $e = trim((string)($input['financialYearEnd'] ?? ''));
    if ($s !== '' || $e !== '') {
        $start = safe_date($s, 'Financial year start'); $end = safe_date($e, 'Financial year end');
        if ($end <= $start) fail('The financial year ends after it starts.', 422, 'india_fy_invalid');
        if ($end > date('Y-m-d', strtotime($start . ' +18 months -1 day'))) fail('A financial year can be at most 18 months long (a first year that starts mid-year included).', 422, 'india_fy_invalid');
        return [$start, $end, substr($end, 5)];
    }
    $fyEnd = (string)($input['fiscalYearEnd'] ?? '03-31');
    if (!preg_match('/^(\d{2})-(\d{2})$/', $fyEnd, $m) || !checkdate((int)$m[1], (int)$m[2], 2001)) fail('Choose a valid financial year end.', 422, 'india_fy_invalid');
    $endYear = (int)substr($booksStart, 0, 4) + ((int)$m[1] < (int)substr($booksStart, 5, 2) ? 1 : 0);
    $end = sprintf('%04d-%s', $endYear, $fyEnd);
    return [date('Y-m-d', strtotime($end . ' -1 year +1 day')), $end, $fyEnd];
}

/** A full Indian address: building, street, area, landmark, city, district, state, PIN. Lines 1 and 2 are composed for documents. */
function tegh_india_address(array $input): array
{
    $part = static fn(string $k, string $label, int $max) => optional_text($input[$k] ?? null, $max) ?? '';
    $a = ['building' => $part('building', 'Flat, building', 150), 'street' => $part('street', 'Street', 150), 'locality' => $part('locality', 'Area or locality', 120),
        'landmark' => $part('landmark', 'Landmark', 120), 'city' => $part('city', 'City', 100), 'district' => $part('district', 'District', 100)];
    // Older clients send addressLine1/2 only.
    if ($a['building'] === '' && $a['street'] === '' && trim((string)($input['addressLine1'] ?? '')) !== '') $a['building'] = (string)optional_text($input['addressLine1'], 150);
    if ($a['locality'] === '' && trim((string)($input['addressLine2'] ?? '')) !== '') $a['locality'] = (string)optional_text($input['addressLine2'], 120);
    $line1 = implode(', ', array_filter([$a['building'], $a['street']]));
    $line2 = implode(', ', array_filter([$a['locality'], $a['landmark'] !== '' ? 'Near ' . $a['landmark'] : '', $a['district'] !== '' && $a['district'] !== $a['city'] ? $a['district'] . ' district' : '']));
    return $a + ['line1' => mb_substr($line1, 0, 180), 'line2' => mb_substr($line2, 0, 180)];
}

/** Registrations kept in the India Masters company profile (validated with the same specification). */
function tegh_india_registrations(string $companyId, mixed $raw): array
{
    if (!is_array($raw) || !$raw) return [];
    require_once __DIR__ . '/india_masters_in2.php';
    $allowed = ['businessActivity', 'iec', 'udyamNumber', 'msmeCategory', 'epfEstablishmentCode', 'esicEmployerCode', 'ptRegistration', 'ptEnrolment', 'shopsEstablishment', 'lwfRegistration', 'lei', 'fssai'];
    $fields = array_intersect_key(in2_specs()['profiles']['company']['fields'], array_flip($allowed));
    foreach ($raw as $k => $_) if (!isset($fields[$k])) fail('Unknown registration: ' . mb_substr((string)$k, 0, 40), 422, 'india_field_unknown');
    if (!empty($raw['udyamNumber']) && empty($raw['msmeCategory'])) fail('Choose the MSME category shown on the Udyam certificate.', 422, 'india_field_required');
    return array_filter(in2_clean($companyId, $fields, $raw), static fn($v) => $v !== '' && $v !== false && $v !== null);
}

const TEGH_INDIA_GST_TYPES = ['regular' => 'Regular', 'composition' => 'Composition', 'sez_unit' => 'SEZ unit', 'sez_developer' => 'SEZ developer', 'casual' => 'Casual taxable person', 'non_resident' => 'Non-resident taxable person', 'isd' => 'Input Service Distributor'];
const TEGH_INDIA_BANK_TYPES = ['current' => 'Current Account', 'savings' => 'Savings Account'];

function tegh_india_company_create(array $user, array $input): never
{
    if (!tegh_india_allowed_for((string)$user['id'])) fail('The India edition is not available for this sign-in.', 403, 'india_edition_unavailable');
    tegh_edition_schema_ready();
    $name = clean_text($input['name'] ?? '', 'Company name', 160);
    $legalName = clean_text(trim((string)($input['legalName'] ?? '')) !== '' ? $input['legalName'] : $name, 'Legal name', 200); // blank = the company name
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
    // Financial year: chosen by its start and end dates (April to March by default). Books start on the first day of it unless chosen.
    $defaultStart = trim((string)($input['financialYearStart'] ?? '')) ?: (date('n') >= 4 ? date('Y') . '-04-01' : (date('Y') - 1) . '-04-01');
    $booksStart = safe_date((string)($input['booksStartDate'] ?? '') ?: $defaultStart, 'Start of books');
    [$fyStart, $fyEndDate, $fyEnd] = tegh_india_financial_year($input, $booksStart);
    if ($booksStart > $fyEndDate) fail('The start of books is after the end of the financial year.', 422, 'india_books_start');
    $timezone = tegh_india_timezone($input['timezone'] ?? '');
    $gstType = (string)($input['gstRegistrationType'] ?? 'regular'); if (!isset(TEGH_INDIA_GST_TYPES[$gstType])) fail('Choose the GST registration type.', 422, 'india_gst_type');
    $bankType = (string)($input['bankAccountType'] ?? 'current'); if (!isset(TEGH_INDIA_BANK_TYPES[$bankType])) fail('Choose a current or savings account.', 422, 'india_bank_type');
    $address = tegh_india_address($input);
    $currency = strtoupper(trim((string)($input['currency'] ?? 'INR'))); if ($currency !== 'INR') fail('India companies keep their books in Indian rupees (INR).', 422, 'india_currency_inr');
    $basis = (string)($input['accountingBasis'] ?? 'accrual'); if (!in_array($basis, ['accrual', 'cash'], true)) fail('Accounting basis is invalid.');
    $coaMode = (string)($input['coaMode'] ?? 'default'); if (!in_array($coaMode, ['default', 'manual'], true)) fail('Chart of accounts option is invalid.');
    $address1 = $address['line1'] !== '' ? $address['line1'] : null; $address2 = $address['line2'] !== '' ? $address['line2'] : null;
    $city = $address['city'] !== '' ? $address['city'] : null; $district = $address['district'] !== '' ? $address['district'] : null;
    $tradeName = optional_text($input['tradeName'] ?? null, 200);
    $companyId = new_id('company');
    $registrations = tegh_india_registrations($companyId, $input['registrations'] ?? null);
    require_once __DIR__ . '/india_masters_in2.php'; in2_schema_ready(); // first-use tables, before the transaction
    if (function_exists('r153_schema_ready')) r153_schema_ready();
    db()->beginTransaction();
    try {
        db()->prepare("INSERT INTO companies (id, name, legal_name, business_type, province, country, currency, accounting_basis, module_mode, payroll_posting_mode, tax_reporting_profile, reporting_framework, fiscal_year_end, fiscal_year_end_date, books_start_date, tax_registered, tax_number, tax_rate_bps, pst_registered, pst_rate_bps, pst_recoverable, edition)
            VALUES (?, ?, ?, ?, ?, 'India', 'INR', ?, 'accounting', 'none', 'none', 'not_set', ?, ?, ?, ?, ?, 0, 0, 0, 0, 'IN')")
            ->execute([$companyId, $name, $legalName, TEGH_INDIA_CONSTITUTIONS[$constitution][1], $state, $basis, $fyEnd, $fyEndDate, $booksStart, $gstin !== '' ? 1 : 0, $gstin !== '' ? $gstin : null]);
        if (schema_column_exists('companies', 'tax_setup_mode')) db()->prepare("UPDATE companies SET tax_setup_mode = 'codes' WHERE id = ?")->execute([$companyId]);
        if (schema_column_exists('companies', 'timezone')) db()->prepare('UPDATE companies SET timezone = ? WHERE id = ?')->execute([$timezone, $companyId]);
        if (schema_column_exists('companies', 'address_line1')) db()->prepare('UPDATE companies SET address_line1 = ?, address_line2 = ?, city = ?, postal_code = ? WHERE id = ?')->execute([$address1, $address2, $city, $pin !== '' ? $pin : null, $companyId]);
        db()->prepare('INSERT INTO company_india_profile (company_id, constitution, pan, gstin, gst_state_code, tan, cin, trade_name, district, pin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$companyId, $constitution, $pan !== '' ? $pan : null, $gstin !== '' ? $gstin : null, TEGH_INDIA_STATES[$state][1], $tan !== '' ? $tan : null, $cin !== '' ? $cin : null, $tradeName, $district, $pin !== '' ? $pin : null]);
        db()->prepare('UPDATE company_india_profile SET address_json = ?, fy_start_date = ? WHERE company_id = ?')->execute([json_encode(array_intersect_key($address, array_flip(['building', 'street', 'locality', 'landmark', 'city', 'district']))), $fyStart, $companyId]);
        // Registrations live in the India Masters company profile (versioned from the start of the financial year);
        // the company GSTIN is also its first GST registration record.
        if ($registrations) db()->prepare('INSERT INTO india_entity_profiles (id,company_id,entity_type,entity_id,effective_from,data_json,created_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([new_id('inprof'), $companyId, 'company', $companyId, min($fyStart, $booksStart), json_encode($registrations), $user['id']]);
        if ($gstin !== '') db()->prepare("INSERT INTO india_master_records (id,company_id,record_type,entity_type,entity_id,data_json,effective_from,status,created_by) VALUES (?,?,'gst_registration','company',?,?,?,'active',?)")
            ->execute([new_id('inrec'), $companyId, $companyId, json_encode(['gstin' => $gstin, 'registrationType' => $gstType, 'status' => 'active', 'validFrom' => min($fyStart, $booksStart), 'tradeName' => $tradeName]), min($fyStart, $booksStart), $user['id']]);
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
        if (isset($ids['1000'])) {
            $bankName = TEGH_INDIA_BANK_TYPES[$bankType];
            db()->prepare('UPDATE accounts SET name = ? WHERE id = ?')->execute(['Bank - ' . $bankName, $ids['1000']]);
            db()->prepare("INSERT INTO bank_accounts (id, company_id, ledger_account_id, name, account_type, masked_number, currency, profile_json) VALUES (?, ?, ?, ?, 'bank', NULL, 'INR', ?)")
                ->execute([new_id('bank'), $companyId, $ids['1000'], $bankName, json_encode(['version' => 1, 'accountSubtype' => $bankType, 'institution' => '', 'reportingClassification' => 'current_asset', 'creditLimitCents' => null, 'termsNote' => '', 'repayableOnDemand' => false, 'integralCashManagement' => false, 'cashFlowOverdraftEligible' => false])]);
        }
        audit_event($user, $companyId, 'company.created', 'company', $companyId, ['name' => $name, 'edition' => 'IN', 'country' => 'India', 'state' => $state, 'currency' => 'INR', 'constitution' => $constitution,
            'gstinRecorded' => $gstin !== '', 'panRecorded' => $pan !== '', 'financialYearStart' => $fyStart, 'financialYearEnd' => $fyEndDate, 'booksStartDate' => $booksStart, 'coaMode' => $coaMode, 'timezone' => $timezone, 'registrations' => array_keys($registrations), 'bankAccountType' => $bankType]);
        if (function_exists('tegh_company_entitlements_initialize')) tegh_company_entitlements_initialize($user, $companyId, $name);
        db()->commit();
    } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
    $companies = companies_for_user((string)$user['id']);
    $created = array_values(array_filter($companies, static fn(array $c): bool => $c['id'] === $companyId))[0] ?? null;
    json_response(['company' => $created, 'companies' => $companies], 201);
}

/**
 * IN-7a: edit an India company's details (Owner or Admin). The financial year can change only while the company has
 * no journal entries; the state only while it agrees with the GSTIN. Registrations are saved as a new India Masters
 * company profile version from today (a second change on the same day corrects that version).
 */
function tegh_india_company_update(array $user, array $company, array $input): array
{
    if (!in_array((string)$company['role'], ['owner', 'admin'], true)) fail('Only the Company Owner or an Admin changes company details.', 403, 'india_company_role');
    $companyId = (string)$company['id'];
    tegh_edition_schema_ready();
    $q = db()->prepare('SELECT c.*, p.pan, p.gstin, p.tan, p.cin, p.trade_name, p.district, p.pin, p.address_json, p.fy_start_date FROM companies c JOIN company_india_profile p ON p.company_id=c.id WHERE c.id=?');
    $q->execute([$companyId]); $cur = $q->fetch(PDO::FETCH_ASSOC);
    if (!$cur) fail('Company not found.', 404, 'company_missing');
    $name = clean_text($input['name'] ?? $cur['name'], 'Company name', 160);
    $legalName = clean_text(trim((string)($input['legalName'] ?? '')) !== '' ? $input['legalName'] : ($cur['legal_name'] ?: $name), 'Legal name', 200);
    $tradeName = array_key_exists('tradeName', $input) ? optional_text($input['tradeName'], 200) : $cur['trade_name'];
    $state = strtoupper(trim((string)($input['state'] ?? $cur['province'])));
    if (!isset(TEGH_INDIA_STATES[$state])) fail('Choose the state or union territory.', 422, 'india_state_required');
    $pan = strtoupper(trim((string)($input['pan'] ?? $cur['pan'] ?? '')));
    if ($pan !== '' && !tegh_india_pan_valid($pan)) fail('A PAN has 10 characters: 5 letters, 4 digits and a letter (for example AAACT1234F).', 422, 'india_pan_invalid');
    $gstin = strtoupper(trim((string)($input['gstin'] ?? $cur['gstin'] ?? '')));
    if ($gstin !== '') {
        if ($problem = tegh_india_gstin_problem($gstin, $pan !== '' ? $pan : null)) fail($problem, 422, 'india_gstin_invalid');
        if (substr($gstin, 0, 2) !== TEGH_INDIA_STATES[$state][1]) fail('The GSTIN state code (' . substr($gstin, 0, 2) . ') is not the state chosen for the company (' . TEGH_INDIA_STATES[$state][1] . ' ' . TEGH_INDIA_STATES[$state][0] . ').', 422, 'india_gstin_state');
        if ($pan === '') $pan = substr($gstin, 2, 10);
    }
    if ($state !== (string)$cur['province'] && schema_table_exists('india_document_gst')) {
        $used = db()->prepare('SELECT COUNT(*) FROM india_document_gst WHERE company_id=?'); $used->execute([$companyId]);
        if ((int)$used->fetchColumn() > 0) fail('Invoices already record this state as the company state, so it cannot change. A business in another state needs its own GST registration (India Masters › Records).', 409, 'india_state_locked');
    }
    $tan = strtoupper(trim((string)($input['tan'] ?? $cur['tan'] ?? '')));
    if ($tan !== '' && !preg_match('/^[A-Z]{4}[0-9]{5}[A-Z]$/', $tan)) fail('A TAN has 10 characters: 4 letters, 5 digits and a letter.', 422, 'india_tan_invalid');
    $cin = strtoupper(trim((string)($input['cin'] ?? $cur['cin'] ?? '')));
    if ($cin !== '' && !preg_match('/^[A-Z0-9-]{8,21}$/', $cin)) fail('Enter the CIN or LLPIN as shown on the certificate of incorporation.', 422, 'india_cin_invalid');
    $pin = trim((string)($input['pin'] ?? $cur['pin'] ?? ''));
    if ($pin !== '' && !preg_match('/^[1-9][0-9]{5}$/', $pin)) fail('A PIN code has 6 digits and does not start with 0.', 422, 'india_pin_invalid');
    $oldAddress = json_decode((string)($cur['address_json'] ?? ''), true) ?: ['building' => (string)$cur['address_line1'], 'locality' => (string)$cur['address_line2'], 'city' => (string)$cur['city'], 'district' => (string)$cur['district']];
    $address = tegh_india_address(array_merge($oldAddress, array_intersect_key($input, array_flip(['building', 'street', 'locality', 'landmark', 'city', 'district']))));
    $timezone = array_key_exists('timezone', $input) ? tegh_india_timezone($input['timezone']) : (string)($cur['timezone'] ?? 'Asia/Kolkata');
    $fyStart = (string)($cur['fy_start_date'] ?? ''); $fyEndDate = (string)$cur['fiscal_year_end_date']; $fyEnd = (string)$cur['fiscal_year_end'];
    if (isset($input['financialYearStart']) || isset($input['financialYearEnd'])) {
        [$ns, $ne, $nmd] = tegh_india_financial_year($input, (string)$cur['books_start_date']);
        if ($ns !== $fyStart || $ne !== $fyEndDate) {
            $j = db()->prepare('SELECT COUNT(*) FROM journal_entries WHERE company_id=?'); $j->execute([$companyId]);
            if ((int)$j->fetchColumn() > 0) fail('The financial year cannot change once the company has entries. Period locks and reports depend on it.', 409, 'india_fy_locked');
            [$fyStart, $fyEndDate, $fyEnd] = [$ns, $ne, $nmd];
        }
    }
    $registrations = array_key_exists('registrations', $input) ? tegh_india_registrations($companyId, $input['registrations']) : null;
    $before = ['name' => $cur['name'], 'legalName' => $cur['legal_name'], 'state' => $cur['province'], 'gstin' => $cur['gstin'], 'tan' => $cur['tan'] !== null, 'cin' => $cur['cin'], 'timezone' => $cur['timezone'] ?? null, 'financialYear' => [$cur['fy_start_date'], $cur['fiscal_year_end_date']], 'address' => $oldAddress];
    db()->beginTransaction();
    try {
        db()->prepare('UPDATE companies SET name=?, legal_name=?, province=?, tax_registered=?, tax_number=?, fiscal_year_end=?, fiscal_year_end_date=? WHERE id=?')
            ->execute([$name, $legalName, $state, $gstin !== '' ? 1 : 0, $gstin !== '' ? $gstin : null, $fyEnd, $fyEndDate, $companyId]);
        if (schema_column_exists('companies', 'timezone')) db()->prepare('UPDATE companies SET timezone=? WHERE id=?')->execute([$timezone, $companyId]);
        if (schema_column_exists('companies', 'address_line1')) db()->prepare('UPDATE companies SET address_line1=?, address_line2=?, city=?, postal_code=? WHERE id=?')->execute([$address['line1'] ?: null, $address['line2'] ?: null, $address['city'] ?: null, $pin !== '' ? $pin : null, $companyId]);
        db()->prepare('UPDATE company_india_profile SET pan=?, gstin=?, gst_state_code=?, tan=?, cin=?, trade_name=?, district=?, pin=?, address_json=?, fy_start_date=? WHERE company_id=?')
            ->execute([$pan !== '' ? $pan : null, $gstin !== '' ? $gstin : null, TEGH_INDIA_STATES[$state][1], $tan !== '' ? $tan : null, $cin !== '' ? $cin : null, $tradeName, $address['district'] ?: null, $pin !== '' ? $pin : null,
                json_encode(array_intersect_key($address, array_flip(['building', 'street', 'locality', 'landmark', 'city', 'district']))), $fyStart !== '' ? $fyStart : null, $companyId]);
        if ($registrations !== null) {
            require_once __DIR__ . '/india_masters_in2.php';
            // Dated in the company's (possibly new) time zone, the zone every later read uses; the request's zone is the old one.
            $today = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
            $prev = db()->prepare("SELECT data_json FROM india_entity_profiles WHERE company_id=? AND entity_type='company' AND entity_id=? AND effective_from<=? ORDER BY effective_from DESC LIMIT 1"); $prev->execute([$companyId, $companyId, $today]);
            // Fields the set-up form does not show (regime, tax audit, accounting standard) are kept.
            $data = array_merge(array_diff_key(json_decode((string)($prev->fetchColumn() ?: '{}'), true) ?: [], array_flip(['businessActivity', 'iec', 'udyamNumber', 'msmeCategory', 'epfEstablishmentCode', 'esicEmployerCode', 'ptRegistration', 'ptEnrolment', 'shopsEstablishment', 'lwfRegistration', 'lei', 'fssai'])), $registrations);
            $same = db()->prepare("SELECT id FROM india_entity_profiles WHERE company_id=? AND entity_type='company' AND entity_id=? AND effective_from=?"); $same->execute([$companyId, $companyId, $today]);
            if ($vid = $same->fetchColumn()) db()->prepare('UPDATE india_entity_profiles SET data_json=?, created_by=? WHERE id=?')->execute([json_encode($data), $user['id'], $vid]);
            else db()->prepare('INSERT INTO india_entity_profiles (id,company_id,entity_type,entity_id,effective_from,data_json,created_by) VALUES (?,?,?,?,?,?,?)')->execute([new_id('inprof'), $companyId, 'company', $companyId, $today, json_encode($data), $user['id']]);
        }
        audit_event($user, $companyId, 'india.company_updated', 'company', $companyId, ['before' => $before, 'after' => ['name' => $name, 'legalName' => $legalName, 'state' => $state, 'gstin' => $gstin ?: null, 'tan' => $tan !== '', 'cin' => $cin ?: null, 'timezone' => $timezone, 'financialYear' => [$fyStart, $fyEndDate], 'address' => array_intersect_key($address, array_flip(['building', 'street', 'locality', 'landmark', 'city', 'district'])), 'registrations' => $registrations === null ? null : array_keys($registrations)]]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    $GLOBALS['tegh_accounting_timezone'] = $timezone; // the reply is read in the new zone
    return ['ok' => true, 'india' => tegh_india_profile_public($companyId)];
}

/** IN-7a: details for the Company details page. PAN and TAN in full only for the Owner or an Admin (others see them masked). */
function tegh_india_company_details(array $company): array
{
    require_company_permission($company, 'company.view');
    tegh_edition_schema_ready();
    $companyId = (string)$company['id'];
    $q = db()->prepare('SELECT c.name, c.legal_name, c.province, c.fiscal_year_end_date, c.books_start_date, c.accounting_basis, c.address_line1, c.address_line2, c.city' . (schema_column_exists('companies', 'timezone') ? ', c.timezone' : '') . ', p.* FROM companies c JOIN company_india_profile p ON p.company_id=c.id WHERE c.id=?');
    $q->execute([$companyId]); $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('Company not found.', 404, 'company_missing');
    $full = in_array((string)$company['role'], ['owner', 'admin'], true);
    $mask = static fn(?string $v): ?string => $v === null || $v === '' ? null : ($full ? $v : substr($v, 0, 2) . str_repeat('•', max(0, strlen($v) - 4)) . substr($v, -2));
    $j = db()->prepare('SELECT COUNT(*) FROM journal_entries WHERE company_id=?'); $j->execute([$companyId]);
    $fyEnd = (string)$r['fiscal_year_end_date'];
    return ['name' => $r['name'], 'legalName' => $r['legal_name'], 'tradeName' => $r['trade_name'], 'constitution' => $r['constitution'], 'constitutionLabel' => TEGH_INDIA_CONSTITUTIONS[(string)$r['constitution']][0] ?? $r['constitution'],
        'pan' => $mask($r['pan']), 'gstin' => $r['gstin'], 'tan' => $mask($r['tan']), 'cin' => $r['cin'], 'state' => $r['province'], 'pin' => $r['pin'], 'district' => $r['district'],
        'address' => json_decode((string)($r['address_json'] ?? ''), true) ?: ['building' => (string)$r['address_line1'], 'locality' => (string)$r['address_line2'], 'city' => (string)$r['city'], 'district' => (string)$r['district']],
        'timezone' => $r['timezone'] ?? 'Asia/Kolkata', 'financialYearStart' => $r['fy_start_date'] ?: date('Y-m-d', strtotime($fyEnd . ' -1 year +1 day')), 'financialYearEnd' => $fyEnd,
        'booksStartDate' => $r['books_start_date'], 'accountingBasis' => $r['accounting_basis'], 'fyLocked' => (int)$j->fetchColumn() > 0,
        'registrations' => tegh_india_company_registrations($companyId), 'canEdit' => $full, 'fullIdentifiers' => $full];
}

/** India facts for the browser: formats, states, constitutions and the company's India profile. */
function tegh_india_profile_public(string $companyId): ?array
{
    if (!schema_table_exists('company_india_profile')) return null;
    $extra = schema_column_exists('company_india_profile', 'address_json') ? ', address_json, fy_start_date' : '';
    $q = db()->prepare('SELECT constitution, pan, gstin, gst_state_code, tan, cin, trade_name, district, pin' . $extra . ' FROM company_india_profile WHERE company_id = ?');
    $q->execute([$companyId]); $r = $q->fetch();
    if (!$r) return null;
    $mask = static fn(?string $v): ?string => $v === null || $v === '' ? null : substr($v, 0, 2) . str_repeat('•', max(0, strlen($v) - 4)) . substr($v, -2);
    return ['constitution' => (string)$r['constitution'], 'constitutionLabel' => TEGH_INDIA_CONSTITUTIONS[(string)$r['constitution']][0] ?? (string)$r['constitution'],
        'panMasked' => $mask($r['pan']), 'gstin' => $r['gstin'], 'gstStateCode' => $r['gst_state_code'], 'tanMasked' => $mask($r['tan']), 'cin' => $r['cin'],
        'tradeName' => $r['trade_name'], 'district' => $r['district'], 'pin' => $r['pin'],
        'address' => json_decode((string)($r['address_json'] ?? ''), true) ?: null, 'financialYearStart' => $r['fy_start_date'] ?? null,
        'registrations' => tegh_india_company_registrations($companyId)];
}

/** The registrations in the India Masters company profile in force today (empty when none are recorded). */
function tegh_india_company_registrations(string $companyId): array
{
    if (!schema_table_exists('india_entity_profiles')) return [];
    $q = db()->prepare("SELECT data_json FROM india_entity_profiles WHERE company_id=? AND entity_type='company' AND entity_id=? AND effective_from<=? ORDER BY effective_from DESC LIMIT 1");
    $q->execute([$companyId, $companyId, function_exists('canadian_today') ? canadian_today() : date('Y-m-d')]);
    $d = json_decode((string)($q->fetchColumn() ?: ''), true);
    return is_array($d) ? array_intersect_key($d, array_flip(['businessActivity', 'iec', 'udyamNumber', 'msmeCategory', 'epfEstablishmentCode', 'esicEmployerCode', 'ptRegistration', 'ptEnrolment', 'shopsEstablishment', 'lwfRegistration', 'lei', 'fssai'])) : [];
}

function tegh_india_reference_public(): array
{
    $states = []; foreach (TEGH_INDIA_STATES as $code => [$name, $gst, $ut]) $states[] = ['code' => $code, 'name' => $name, 'gstCode' => $gst, 'unionTerritory' => $ut];
    usort($states, static fn($a, $b) => strcmp($a['name'], $b['name']));
    $constitutions = []; foreach (TEGH_INDIA_CONSTITUTIONS as $code => [$label]) $constitutions[] = ['code' => $code, 'label' => $label];
    $gstTypes = []; foreach (TEGH_INDIA_GST_TYPES as $code => $label) $gstTypes[] = ['code' => $code, 'label' => $label];
    $bankTypes = []; foreach (TEGH_INDIA_BANK_TYPES as $code => $label) $bankTypes[] = ['code' => $code, 'label' => $label];
    return ['states' => $states, 'constitutions' => $constitutions, 'currency' => 'INR', 'numberLocale' => 'en-IN', 'dateFormat' => 'DD-MM-YYYY', 'fiscalYearEnd' => '03-31',
        'timezone' => 'Asia/Kolkata', 'gstRegistrationTypes' => $gstTypes, 'bankAccountTypes' => $bankTypes];
}

/* ---------- IN-7a: full Indian address on customers and vendors ---------- */

function tegh_india_party_schema_ready(): void
{
    static $ready = false;
    if ($ready || schema_table_exists('india_party_addresses')) { $ready = true; return; }
    if (db()->inTransaction()) return;
    db()->exec("CREATE TABLE IF NOT EXISTS india_party_addresses (
        company_id VARCHAR(64) NOT NULL, entity_type ENUM('customer','vendor') NOT NULL, entity_id VARCHAR(64) NOT NULL,
        building VARCHAR(150) NULL, street VARCHAR(150) NULL, locality VARCHAR(120) NULL, landmark VARCHAR(120) NULL, district VARCHAR(100) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (company_id, entity_type, entity_id),
        CONSTRAINT fk_india_party_addresses_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

/**
 * In an India company a customer or vendor form sends "india" = {building, street, locality, landmark, district}.
 * Address lines 1 and 2 are composed from it (so invoices, statements and exports show the full address);
 * city, state (province) and PIN (postal code) stay in their usual fields. Canadian companies are not affected.
 */
function tegh_india_party_input(array $company, array $input): array
{
    // The browser sends the parts as indiaBuilding, indiaStreet, ... (form fields) or as an "india" object (API).
    $flat = [];
    foreach (['building', 'street', 'locality', 'landmark', 'district'] as $k) { $f = 'india' . ucfirst($k); if (array_key_exists($f, $input)) { $flat[$k] = $input[$f]; unset($input[$f]); } }
    if ($flat && !is_array($input['india'] ?? null)) $input['india'] = $flat;
    if (!is_array($input['india'] ?? null) || !function_exists('tegh_company_edition') || tegh_company_edition($company) !== 'IN') { unset($input['india']); return $input; }
    tegh_india_party_schema_ready();
    $a = tegh_india_address(array_intersect_key($input['india'], array_flip(['building', 'street', 'locality', 'landmark', 'district'])) + ['city' => (string)($input['city'] ?? '')]);
    $pin = trim((string)($input['postalCode'] ?? ''));
    if ($pin !== '' && strtolower(trim((string)($input['country'] ?? 'India'))) === 'india' && !preg_match('/^[1-9][0-9]{5}$/', $pin)) fail('A PIN code has 6 digits and does not start with 0.', 422, 'india_pin_invalid');
    $input['addressLine1'] = $a['line1']; $input['addressLine2'] = $a['line2'];
    $input['_indiaAddress'] = array_intersect_key($a, array_flip(['building', 'street', 'locality', 'landmark', 'district']));
    unset($input['india']);
    return $input;
}

function tegh_india_party_address_save(string $companyId, string $type, string $id, array $input): void
{
    if (!isset($input['_indiaAddress']) || !schema_table_exists('india_party_addresses')) return;
    $a = $input['_indiaAddress']; $v = static fn(string $k) => ($a[$k] ?? '') !== '' ? $a[$k] : null;
    db()->prepare('INSERT INTO india_party_addresses (company_id,entity_type,entity_id,building,street,locality,landmark,district) VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE building=VALUES(building), street=VALUES(street), locality=VALUES(locality), landmark=VALUES(landmark), district=VALUES(district)')
        ->execute([$companyId, $type, $id, $v('building'), $v('street'), $v('locality'), $v('landmark'), $v('district')]);
}

function tegh_india_party_address_get(string $companyId, string $type, string $id): ?array
{
    if (!schema_table_exists('india_party_addresses')) return null;
    $q = db()->prepare('SELECT building, street, locality, landmark, district FROM india_party_addresses WHERE company_id=? AND entity_type=? AND entity_id=?');
    $q->execute([$companyId, $type, $id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? array_map(static fn($x) => (string)($x ?? ''), $r) : null;
}

