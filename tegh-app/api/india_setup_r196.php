<?php
declare(strict_types=1);

/*
 * R196 (India only): short set-up screens for GST and payroll. R197: the payroll set-up is a list of pay heads (fixed
 * heads and the person's own) and each employee's pay is a table of amounts (monthly, annual, semi-monthly or bi-weekly)
 * or percentages of Basic Pay, Dearness Pay, Dearness Allowance or gross pay.
 * - GST: the person ticks the GST rates they charge or pay (Tegh suggests the usual slabs, each editable) and confirms
 *   them once. Tegh then saves each rate as a statutory rule, approves it (the person is the Company Owner or an Admin,
 *   who approve rules anyway) and creates its two GST codes (CGST + SGST/UTGST, and IGST). The GSTR-1 B2C (large) limit is
 *   saved the same way when ticked.
 * - Payroll: the person ticks EPF, ESIC and Professional Tax (Tegh suggests the usual rates and limits, each editable) and
 *   confirms once. Tegh saves and approves those rules and creates the standard pay components: Basic, HRA, Special
 *   allowance, Income tax (entered each pay run) and the statutory deductions and employer contributions ticked.
 * - Employees: name, joining date, PAN and three monthly amounts. Tegh saves the employee record and the salary structure
 *   (every set-up component) together.
 * Everything goes through the existing India Masters, GST and payroll functions, so their checks still apply; the rules
 * record "Confirmed by <email> in ... set-up (suggested by Tegh)" as their source. India Masters keeps every advanced option.
 * Canadian companies never reach this file (404 india_only).
 */

require_once __DIR__ . '/india_masters_in2.php';
require_once __DIR__ . '/india_payroll_in5.php';

/** The usual GST slabs offered for ticking (rates since 22 September 2025). Nothing is saved until the person confirms. */
const R196_GST_SUGGESTIONS = [
    ['rate' => '5', 'note' => 'Essential goods and many everyday goods and services', 'checked' => true],
    ['rate' => '18', 'note' => 'Most services and most other goods', 'checked' => true],
    ['rate' => '40', 'note' => 'Luxury and sin goods (special rate)', 'checked' => false],
    ['rate' => '0', 'note' => 'Nil-rated goods and services', 'checked' => false],
    ['rate' => '3', 'note' => 'Gold, silver and jewellery', 'checked' => false],
    ['rate' => '0.25', 'note' => 'Rough precious and semi-precious stones', 'checked' => false],
];
const R196_B2CL_SUGGESTED_RUPEES = 100000;
/** The usual EPF and ESIC figures offered for ticking; each is editable before it is confirmed. */
const R196_PAYROLL_SUGGESTIONS = [
    'epf' => ['employeePercent' => '12', 'employerPercent' => '12', 'ceilingRupees' => 15000],
    'esic' => ['employeePercent' => '0.75', 'employerPercent' => '3.25', 'limitRupees' => 21000],
    'pt' => ['amountRupees' => 200],
];
/** Pay components created by the payroll set-up, by name. Statutory ones are created only when their item is ticked. */

function r196_state_name(string $code): string { return (string)(TEGH_INDIA_STATES[strtoupper($code)][0] ?? $code); }

function r196_require_rules_role(array $company, string $what): void
{
    if (!in_array((string)$company['role'], ['owner', 'admin'], true)) fail('Only the Company Owner or an Admin sets up ' . $what . ', because it approves tax rates.', 403, 'india_setup_role');
}

/** A default "effective from": the start of books, or today. */
function r196_default_from(array $company): string
{
    $b = trim((string)($company['books_start_date'] ?? ''));
    return $b !== '' ? $b : canadian_today();
}

/** Percent from the person: 0 to 100, at most three decimals. */
function r196_percent(mixed $v, string $label): string
{
    $t = trim((string)$v);
    if (!preg_match('/^(100(\.0{1,3})?|[0-9]{1,2}(\.[0-9]{1,3})?)$/', $t)) fail($label . ' must be a percentage from 0 to 100 with at most three decimals.', 422, 'india_setup_percent');
    return rtrim(rtrim(number_format((float)$t, 3, '.', ''), '0'), '.') ?: '0';
}

/** Whole rupees from the person, as paise. */
function r196_rupees(mixed $v, string $label, bool $allowZero = false): int
{
    $t = str_replace([',', ' ', '₹'], '', trim((string)$v));
    if (!preg_match('/^[0-9]{1,11}$/', $t) || (!$allowZero && (int)$t === 0)) fail($label . ' must be a whole number of rupees' . ($allowZero ? '' : ' above zero') . '.', 422, 'india_setup_rupees');
    return (int)$t * 100;
}

/** The rule version of a key in force or starting on a date (any status except superseded), newest first. */
function r196_rule(string $companyId, string $jur, string $cat, string $key): ?array
{
    $q = db()->prepare("SELECT * FROM statutory_rates WHERE company_id=? AND jurisdiction=? AND person_type=? AND rate_key=? AND status<>'superseded' ORDER BY effective_from DESC LIMIT 1");
    $q->execute([$companyId, $jur, $cat, $key]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Save and approve one rule unless the same figures are already approved. A different figure from a later date becomes a
 * new version (the earlier one ends the day before); a different figure from the same or an earlier date is refused, so a
 * figure that was used is never rewritten.
 */
function r196_confirm_rule(array $user, array $company, array $r, string $source): array
{
    $companyId = (string)$company['id']; $jur = $r['state'] === '' ? 'IN' : 'IN-' . $r['state'];
    $have = r196_rule($companyId, $jur, $r['category'], $r['code']);
    $same = static fn(array $h): bool => ($h['value_decimal'] === null ? null : (float)$h['value_decimal']) === ($r['ratePercent'] === '' ? null : (float)$r['ratePercent'])
        && ($h['value_cents'] === null ? null : (int)$h['value_cents']) === ($r['amountCents'] === '' ? null : (int)$r['amountCents'])
        && ($h['threshold_max_cents'] === null ? null : (int)$h['threshold_max_cents']) === ($r['thresholdMaxCents'] === '' ? null : (int)$r['thresholdMaxCents'])
        && ($h['threshold_min_cents'] === null ? null : (int)$h['threshold_min_cents']) === (($r['thresholdMinCents'] ?? '') === '' ? null : (int)$r['thresholdMinCents']);
    if ($have && $same($have)) {
        if ((string)$have['status'] === 'draft') in2_rule_approve($user, $company, ['id' => $have['id']]);
        return ['id' => (string)$have['id'], 'code' => $r['code'], 'status' => 'unchanged'];
    }
    if ($have && (string)$have['effective_from'] >= $r['effectiveFrom']) fail($r['label'] . ' already has a different figure from ' . $have['effective_from'] . '. To change it, choose a later "effective from" date, or change it in India Masters › Statutory rules.', 409, 'india_setup_rule_conflict');
    $saved = in2_rule_save($user, $company, ['category' => $r['category'], 'code' => $r['code'], 'label' => $r['label'], 'state' => $r['state'], 'effectiveFrom' => $r['effectiveFrom'],
        'ratePercent' => $r['ratePercent'], 'amountCents' => $r['amountCents'], 'thresholdMinCents' => $r['thresholdMinCents'] ?? '', 'thresholdMaxCents' => $r['thresholdMaxCents'], 'source' => $source,
        'notes' => 'Suggested by Tegh and confirmed in the set-up. Check it against the current notification.']);
    in2_rule_approve($user, $company, ['id' => $saved['id']]);
    return ['id' => (string)$saved['id'], 'code' => $r['code'], 'status' => $have ? 'new version' : 'created'];
}

/* ---------- GST ---------- */

function r196_gst_state(array $company): array
{
    $companyId = (string)$company['id'];
    $q = db()->prepare("SELECT id, rate_key, label, value_decimal, effective_from, status FROM statutory_rates WHERE company_id=? AND jurisdiction='IN' AND person_type='gst_rate' AND status IN ('active','draft') ORDER BY value_decimal, effective_from");
    $q->execute([$companyId]); $rules = $q->fetchAll(PDO::FETCH_ASSOC);
    $codes = function_exists('in3_codes_list') ? in3_codes_list($companyId) : [];
    $have = [];
    foreach ($rules as $r) {
        $rate = rtrim(rtrim((string)$r['value_decimal'], '0'), '.') ?: '0';
        $have[$rate] = ['rate' => $rate, 'ruleCode' => (string)$r['rate_key'], 'label' => (string)$r['label'], 'fixed' => in_array($rate, array_column(R196_GST_SUGGESTIONS, 'rate'), true), 'status' => (string)$r['status'], 'effectiveFrom' => (string)$r['effective_from'],
            'codes' => array_values(array_map(static fn($c) => $c['code'], array_filter($codes, static fn($c) => ($c['ruleKey'] ?? $c['rule_key'] ?? '') === $r['rate_key'])))];
    }
    $b2cl = in3_rule_in_force($companyId, 'IN', 'gst_return_limit', 'B2CL', canadian_today());
    return ['gstin' => in3_company_gstin($company), 'state' => strtoupper((string)$company['province']), 'stateName' => r196_state_name(strtoupper((string)$company['province'])),
        'rates' => array_values($have), 'suggestions' => R196_GST_SUGGESTIONS, 'b2clRupees' => $b2cl && $b2cl['value_cents'] !== null ? intdiv((int)$b2cl['value_cents'], 100) : null,
        'b2clSuggestedRupees' => R196_B2CL_SUGGESTED_RUPEES, 'effectiveFrom' => r196_default_from($company), 'canSetUp' => in_array((string)$company['role'], ['owner', 'admin'], true)];
}

function r196_gst_apply(array $user, array $company, array $in): array
{
    r196_require_rules_role($company, 'GST');
    if (empty($in['confirmed'])) fail('Tick "I have checked these rates" to confirm them.', 422, 'india_setup_confirm');
    $from = safe_date((string)($in['effectiveFrom'] ?? r196_default_from($company)), 'Effective from');
    // R198: a rate may come with a heading ({rate, label}); the usual slabs keep "GST n%".
    $rates = []; $labels = [];
    foreach ((array)($in['rates'] ?? []) as $r) {
        $p = r196_percent(is_array($r) ? ($r['rate'] ?? '') : $r, 'A GST rate'); $rates[$p] = $p;
        $l = is_array($r) ? trim((string)($r['label'] ?? '')) : '';
        if ($l !== '' && !in_array($p, array_column(R196_GST_SUGGESTIONS, 'rate'), true)) $labels[$p] = in2_text($l, 'Heading of GST ' . $p . '%', 120);
    }
    if (!$rates) fail('Tick at least one GST rate.', 422, 'india_setup_no_rates');
    foreach ($rates as $p) if (((int)round((float)$p * 1000)) % 2 !== 0) fail('GST ' . $p . '% cannot be split equally into CGST and SGST. Enter it in India Masters instead.', 422, 'india_gst_rate_split');
    if (!in3_schema_ready()) fail('Try again: the GST tables are being prepared.', 409, 'india_gst_schema_busy');
    $source = 'Confirmed by ' . (string)$user['email'] . ' in GST set-up (suggested by Tegh)';
    $out = [];
    foreach ($rates as $p) {
        $rule = r196_confirm_rule($user, $company, ['category' => 'gst_rate', 'code' => 'GST' . $p, 'label' => $labels[$p] ?? (r196_rule((string)$company['id'], 'IN', 'gst_rate', 'GST' . $p)['label'] ?? 'GST ' . $p . '%'), 'state' => '', 'effectiveFrom' => $from, 'ratePercent' => $p, 'amountCents' => '', 'thresholdMaxCents' => ''], $source);
        $codes = in3_codes_create($user, $company, ['ruleId' => $rule['id']]);
        $out[] = ['rate' => $p, 'rule' => $rule, 'codes' => array_map(static fn($c) => $c['code'], $codes['codes'])];
    }
    $b2cl = null;
    if (trim((string)($in['b2clRupees'] ?? '')) !== '') {
        $cents = r196_rupees($in['b2clRupees'], 'The B2C (large) invoice limit');
        $b2cl = r196_confirm_rule($user, $company, ['category' => 'gst_return_limit', 'code' => 'B2CL', 'label' => 'GSTR-1 B2C (large) invoice limit', 'state' => '', 'effectiveFrom' => $from, 'ratePercent' => '', 'amountCents' => (string)$cents, 'thresholdMaxCents' => ''], $source);
    }
    audit_event($user, (string)$company['id'], 'india.gst_setup_confirmed', 'company', (string)$company['id'], ['effectiveFrom' => $from, 'rates' => array_values($rates), 'b2clRupees' => $b2cl ? intdiv(r196_rupees($in['b2clRupees'], 'limit'), 100) : null]);
    return ['rates' => $out, 'b2cl' => $b2cl, 'state' => r196_gst_state($company)];
}

/* ---------- payroll ---------- */

/**
 * R197: the pay heads of the payroll set-up, in order. Fixed heads are created once and keep their names; the person adds
 * their own allowances and deductions (headings they can rename). Statutory heads exist when their item is ticked.
 * "aliases" match heads created by earlier releases (R196 named Basic Pay "Basic").
 */
const R196_COMPONENTS = [
    'basic' => ['name' => 'Basic Pay', 'aliases' => ['Basic'], 'kind' => 'earning', 'calculation' => 'fixed', 'taxable' => true],
    'dp' => ['name' => 'Dearness Pay', 'kind' => 'earning', 'calculation' => 'fixed', 'taxable' => true],
    'da' => ['name' => 'Dearness Allowance', 'kind' => 'earning', 'calculation' => 'fixed', 'taxable' => true],
    'cell' => ['name' => 'Cell Phone Allowance', 'kind' => 'earning', 'calculation' => 'fixed', 'taxable' => true],
    'travel' => ['name' => 'Travel Allowance', 'kind' => 'earning', 'calculation' => 'fixed', 'taxable' => true],
    'medical' => ['name' => 'Medical Allowance', 'kind' => 'earning', 'calculation' => 'fixed', 'taxable' => true],
    'epf_ee' => ['name' => 'EPF (employee)', 'kind' => 'deduction', 'calculation' => 'rule', 'rule' => 'EPF-EE', 'base' => 'basic', 'needs' => 'epf'],
    'esic_ee' => ['name' => 'ESIC (employee)', 'kind' => 'deduction', 'calculation' => 'rule', 'rule' => 'ESIC-EE', 'needs' => 'esic'],
    'pt' => ['name' => 'Professional Tax', 'kind' => 'deduction', 'calculation' => 'rule', 'rule' => 'PT', 'needs' => 'pt'],
    'cpf' => ['name' => 'CPF', 'kind' => 'deduction', 'calculation' => 'fixed'],
    'gpf' => ['name' => 'GPF', 'kind' => 'deduction', 'calculation' => 'fixed'],
    'insurance' => ['name' => 'Insurance', 'kind' => 'deduction', 'calculation' => 'fixed'],
    'tds' => ['name' => 'Income tax (TDS)', 'kind' => 'deduction', 'calculation' => 'entered'],
    'loan' => ['name' => 'Loan Recovery', 'kind' => 'deduction', 'calculation' => 'fixed'],
    'epf_er' => ['name' => 'EPF (employer)', 'kind' => 'employer_contribution', 'calculation' => 'rule', 'rule' => 'EPF-ER', 'base' => 'basic', 'needs' => 'epf'],
    'esic_er' => ['name' => 'ESIC (employer)', 'kind' => 'employer_contribution', 'calculation' => 'rule', 'rule' => 'ESIC-ER', 'needs' => 'esic'],
];
/** What a percentage can be of: keys of the fixed earning heads (and gross pay, for deductions and employer contributions). */
const R197_OF = [
    'basic' => ['Basic Pay', ['basic']], 'dp' => ['Dearness Pay', ['dp']], 'da' => ['Dearness Allowance', ['da']],
    'basic_da' => ['Basic Pay + DA', ['basic', 'da']], 'basic_dp' => ['Basic Pay + DP', ['basic', 'dp']], 'basic_dp_da' => ['Basic Pay + DP + DA', ['basic', 'dp', 'da']], 'gross' => ['Gross pay', ['gross']],
];

/** Active pay components on a date keyed by set-up key (fixed heads), matched by name or an earlier name. */
function r196_components(string $companyId, string $date): array
{
    $byName = [];
    foreach (in5_records($companyId, 'pay_component', $date) as $id => $c) $byName[mb_strtolower((string)($c['name'] ?? ''))] ??= $id;
    $out = [];
    foreach (R196_COMPONENTS as $k => $c) foreach (array_merge([$c['name']], $c['aliases'] ?? []) as $n) if (isset($byName[mb_strtolower($n)])) { $out[$k] = $byName[mb_strtolower($n)]; break; }
    return $out;
}

/** The set-up date: when Basic Pay starts (the components and rules of the set-up start together). */
function r196_payroll_from(string $companyId): ?string
{
    $q = db()->prepare("SELECT MIN(effective_from) FROM india_master_records WHERE company_id=? AND record_type='pay_component' AND JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.name')) IN ('Basic','Basic Pay') AND status IN ('active','superseded','inactive')");
    $q->execute([$companyId]); $d = $q->fetchColumn();
    return $d ? (string)$d : null;
}

/** Create the fixed heads that do not exist yet (statutory heads only when their rule exists). Returns the names created. */
function r197_ensure_heads(array $user, array $company, string $from): array
{
    $companyId = (string)$company['id']; $state = strtoupper((string)$company['province']);
    $have = r196_components($companyId, max($from, canadian_today()));
    $ruleExists = static function (string $key) use ($companyId, $state): bool {
        $q = db()->prepare("SELECT COUNT(*) FROM statutory_rates WHERE company_id=? AND rate_key=? AND jurisdiction LIKE 'IN%' AND status='active'"); $q->execute([$companyId, $key === 'PT' ? 'PT-' . $state : $key]);
        return (int)$q->fetchColumn() > 0;
    };
    $created = [];
    foreach (R196_COMPONENTS as $k => $c) {
        if (isset($have[$k])) continue;
        if (!empty($c['rule']) && !$ruleExists($c['rule'])) continue;
        $data = ['name' => $c['name'], 'kind' => $c['kind'], 'calculation' => $c['calculation']];
        if (!empty($c['taxable'])) $data['taxable'] = true;
        if (!empty($c['rule'])) $data['rule'] = $c['rule'] === 'PT' ? 'PT-' . $state : $c['rule'];
        if (!empty($c['base']) && isset($have[$c['base']])) $data['baseComponent'] = $have[$c['base']];
        $saved = in2_record_save($user, $company, ['recordType' => 'pay_component', 'effectiveFrom' => $from, 'data' => $data]);
        $have[$k] = (string)$saved['id']; $created[] = $c['name'];
    }
    return $created;
}

/** Pay heads in order: fixed heads first (in R196_COMPONENTS order), then the person's own, by kind. */
function r197_heads(string $companyId, string $date): array
{
    $comps = in5_records($companyId, 'pay_component', $date); $keys = array_flip(r196_components($companyId, $date));
    $order = array_flip(array_keys(R196_COMPONENTS)); $out = [];
    foreach ($comps as $id => $c) {
        $key = $keys[$id] ?? null; $calc = (string)($c['calculation'] ?? '');
        $out[] = ['id' => $id, 'key' => $key, 'name' => (string)($c['name'] ?? ''), 'kind' => (string)($c['kind'] ?? ''), 'calculation' => $calc, 'rule' => $c['rule'] ?? null,
            'fixed' => $key !== null, 'editable' => $key === null && $calc === 'fixed' && in_array((string)($c['kind'] ?? ''), ['earning', 'deduction', 'reimbursement'], true), 'sort' => $key !== null ? $order[$key] : 100];
    }
    usort($out, static fn($a, $b) => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);
    return $out;
}

/** The ids behind an "of" key (Basic Pay + DA ...), or null when one of its heads does not exist. */
function r197_of_ids(string $key, array $keyIds): ?array
{
    if (!isset(R197_OF[$key])) return null;
    $ids = [];
    foreach (R197_OF[$key][1] as $k) { if ($k === 'gross') { $ids[] = 'gross'; continue; } if (!isset($keyIds[$k])) return null; $ids[] = $keyIds[$k]; }
    return $ids;
}

/** Monthly-equivalent paise of an amount entered on a basis (rounded to the paisa). */
function r197_monthly(int $cents, string $basis): int
{
    [$mul, $div] = IN5_PAY_BASES[$basis] ?? [1, 1];
    return (int)round($cents * $mul / $div);
}

/** Rupees (up to two decimals) as paise. */
function r197_paise(mixed $v, string $label): int
{
    $t = str_replace([',', ' ', '₹'], '', trim((string)$v));
    if (!preg_match('/^[0-9]{1,11}(\.[0-9]{1,2})?$/', $t)) fail($label . ' must be an amount in rupees (up to two decimals).', 422, 'india_setup_rupees');
    return (int)round((float)$t * 100);
}

/**
 * Structure lines from the employee pay table. Each row: componentId, mode (amount|percent|rule|entered), amount and basis,
 * percent and of (a key of R197_OF). Empty rows are left out; employer contributions follow the employee's ticks.
 */
function r197_lines(string $companyId, string $date, array $rows, array $flags): array
{
    $components = in5_records($companyId, 'pay_component', $date); $keyIds = r196_components($companyId, $date); $byId = array_flip($keyIds);
    $lines = []; $epfOf = null;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $cid = (string)($r['componentId'] ?? ''); if (!isset($components[$cid])) fail('A pay head on this employee no longer exists. Reload the page.', 409, 'india_setup_head_missing');
        $c = $components[$cid]; $name = (string)($c['name'] ?? 'A pay head'); $mode = (string)($r['mode'] ?? '');
        $key = $byId[$cid] ?? null;
        if (in_array($key, ['epf_ee', 'esic_ee', 'pt'], true) && !$flags[['epf_ee' => 'epf', 'esic_ee' => 'esic', 'pt' => 'pt'][$key]]) continue;
        if ($mode === 'amount') {
            if (trim((string)($r['amount'] ?? '')) === '') continue;
            $basis = (string)($r['basis'] ?? 'monthly'); if (!isset(IN5_PAY_BASES[$basis])) fail('Choose monthly, annual, semi-monthly or bi-weekly for ' . $name . '.', 422, 'india_payroll_basis');
            $ec = r197_paise($r['amount'], $name); if ($ec === 0) continue;
            $lines[] = ['componentId' => $cid, 'mode' => 'amount', 'amountCents' => r197_monthly($ec, $basis), 'entered' => ['amountCents' => $ec, 'basis' => $basis]];
        } elseif ($mode === 'percent') {
            if (trim((string)($r['percent'] ?? '')) === '') continue;
            $of = r197_of_ids((string)($r['of'] ?? ''), $keyIds); if ($of === null) fail('Choose what ' . $name . ' is a percentage of.', 422, 'india_payroll_base_missing');
            $lines[] = ['componentId' => $cid, 'mode' => 'percent', 'percent' => trim((string)$r['percent']), 'percentOf' => $of];
        } elseif ($mode === 'rule') {
            $line = ['componentId' => $cid, 'mode' => 'rule'];
            if (trim((string)($r['of'] ?? '')) !== '') { $of = r197_of_ids((string)$r['of'], $keyIds); if ($of === null) fail('Choose the wages ' . $name . ' is worked out on.', 422, 'india_payroll_base_missing'); $line['percentOf'] = $of; }
            if ($key === 'epf_ee') $epfOf = $line['percentOf'] ?? null;
            $lines[] = $line;
        } elseif ($mode === 'entered') {
            $lines[] = ['componentId' => $cid, 'mode' => 'entered'];
        } elseif ($mode === 'default') {
            $lines[] = ['componentId' => $cid];
        }
    }
    // Employer contributions: EPF on the same wages as the employee's EPF, ESIC on gross pay.
    $ids = array_column($lines, 'componentId');
    if ($flags['epf'] && isset($keyIds['epf_er'])) $lines[] = ['componentId' => $keyIds['epf_er'], 'mode' => 'rule'] + ($epfOf ? ['percentOf' => $epfOf] : []);
    if ($flags['esic'] && isset($keyIds['esic_er'])) $lines[] = ['componentId' => $keyIds['esic_er'], 'mode' => 'rule'];
    $earn = array_filter($lines, static fn($l) => in_array((string)($components[$l['componentId']]['kind'] ?? ''), ['earning', 'reimbursement'], true));
    if (!$earn) fail('Enter at least one earning (for example Basic Pay).', 422, 'india_setup_basic');
    // Validate here so the person sees the problem before anything is saved.
    in5_structure_lines($components, $lines);
    return $lines;
}

/** Rows of the pay table from a saved structure (for editing). */
function r197_rows(string $companyId, string $date, array $lines): array
{
    $keyIds = r196_components($companyId, $date); $rows = [];
    $ofKey = static function (array $ids) use ($keyIds): ?string { foreach (R197_OF as $k => [, $parts]) { $want = array_map(static fn($p) => $p === 'gross' ? 'gross' : ($keyIds[$p] ?? '?'), $parts); if ($want === array_values($ids)) return $k; } return null; };
    foreach ($lines as $l) {
        $cid = (string)$l['componentId']; $mode = (string)($l['mode'] ?? '');
        $row = ['componentId' => $cid, 'mode' => $mode === '' ? 'default' : $mode];
        if ($mode === 'amount' || ($mode === '' && $l['amountCents'] !== null)) { $row['mode'] = 'amount'; $e = $l['entered'] ?? ['amountCents' => (int)$l['amountCents'], 'basis' => 'monthly']; $row['amount'] = rtrim(rtrim(number_format($e['amountCents'] / 100, 2, '.', ''), '0'), '.'); $row['basis'] = $e['basis']; }
        if (isset($l['percent'])) $row['percent'] = (string)$l['percent'];
        if (isset($l['percentOf'])) $row['of'] = $ofKey((array)$l['percentOf']);
        $rows[] = $row;
    }
    return $rows;
}

/** One month of an employee's pay (full month, no entered amounts): the preview and the list figures. */
function r197_month(string $companyId, array $employee, array $lines, string $date): array
{
    $from = substr($date, 0, 8) . '01'; $to = date('Y-m-t', strtotime($from)); $days = (int)date('t', strtotime($from));
    $components = in5_records($companyId, 'pay_component', $to) + in5_records($companyId, 'pay_component', $from);
    $r = in5_calculate_employee($companyId, $employee, ['lines' => $lines], $components, $from, $to, $to, $days * 1000, []);
    return ['month' => substr($from, 0, 7), 'components' => $r['components'], 'grossCents' => $r['grossCents'], 'deductionsCents' => $r['deductionsCents'], 'employerCents' => $r['employerCents'], 'netCents' => $r['netCents']];
}

function r196_payroll_state(array $company): array
{
    $companyId = (string)$company['id']; in2_schema_ready(); in5_schema_ready();
    $today = canadian_today(); $from = r196_payroll_from($companyId);
    $date = max($today, $from ?? $today);
    $comp = $from ? r196_components($companyId, $date) : [];
    $rule = static function (string $cat, string $key, string $jur = 'IN') use ($companyId): ?array {
        $r = r196_rule($companyId, $jur, $cat, $key);
        return $r ? ['status' => (string)$r['status'], 'ratePercent' => $r['value_decimal'] !== null ? rtrim(rtrim((string)$r['value_decimal'], '0'), '.') : null,
            'amountRupees' => $r['value_cents'] !== null ? intdiv((int)$r['value_cents'], 100) : null, 'limitRupees' => $r['threshold_max_cents'] !== null ? intdiv((int)$r['threshold_max_cents'], 100) : null, 'effectiveFrom' => (string)$r['effective_from']] : null;
    };
    $state = strtoupper((string)$company['province']);
    $employees = [];
    foreach (in5_records($companyId, 'employee', $date) + in5_records($companyId, 'employee', '9999-12-31') as $id => $e) {
        $s = in5_structure($companyId, $id, '9999-12-31');
        $month = null; $problem = null;
        if ($s) { try { $month = r197_month($companyId, $e, $s['lines'], max($date, (string)$s['effectiveFrom'])); } catch (Throwable $x) { $problem = $x->getMessage(); } }
        $employees[$id] = ['id' => $id, 'employeeCode' => $e['employeeCode'] ?? '', 'name' => $e['name'] ?? '', 'pan' => $e['pan'] ?? '', 'dateOfJoining' => $e['dateOfJoining'] ?? '', 'dateOfLeaving' => $e['dateOfLeaving'] ?? '',
            'epf' => !empty($e['epfApplicable']), 'esic' => !empty($e['esicApplicable']), 'pt' => !empty($e['ptApplicable']), 'salaryFrom' => $s['effectiveFrom'] ?? null,
            'rows' => $s ? r197_rows($companyId, $date, $s['lines']) : [], 'month' => $month, 'problem' => $problem];
    }
    uasort($employees, static fn($a, $b) => strcmp((string)$a['employeeCode'], (string)$b['employeeCode']));
    $of = []; foreach (R197_OF as $k => [$label]) if (r197_of_ids($k, $comp) !== null) $of[] = ['key' => $k, 'label' => $label];
    return ['done' => isset($comp['basic']), 'effectiveFrom' => $from ?? r196_default_from($company), 'state' => $state, 'stateName' => r196_state_name($state),
        'epf' => ['employee' => $rule('epf_rate', 'EPF-EE'), 'employer' => $rule('epf_rate', 'EPF-ER')], 'esic' => ['employee' => $rule('esic_rate', 'ESIC-EE'), 'employer' => $rule('esic_rate', 'ESIC-ER')],
        'pt' => $rule('pt_slab', 'PT-' . $state, 'IN-' . $state), 'components' => array_keys($comp), 'heads' => $from ? r197_heads($companyId, $date) : [], 'of' => $of, 'bases' => array_keys(IN5_PAY_BASES),
        'suggestions' => R196_PAYROLL_SUGGESTIONS, 'employees' => array_values($employees),
        'canSetUp' => in_array((string)$company['role'], ['owner', 'admin'], true), 'canManage' => company_role_can((string)$company['role'], 'payroll.manage')];
}

function r196_payroll_apply(array $user, array $company, array $in): array
{
    r196_require_rules_role($company, 'payroll');
    require_company_permission($company, 'payroll.manage');
    if (empty($in['confirmed'])) fail('Tick "I have checked these rates and limits" to confirm them.', 422, 'india_setup_confirm');
    $companyId = (string)$company['id']; in2_schema_ready(); in5_schema_ready();
    $from = r196_payroll_from($companyId) ?? safe_date((string)($in['effectiveFrom'] ?? r196_default_from($company)), 'Effective from');
    $ruleFrom = safe_date((string)($in['effectiveFrom'] ?? $from), 'Effective from');
    $state = strtoupper((string)$company['province']);
    $source = 'Confirmed by ' . (string)$user['email'] . ' in payroll set-up (suggested by Tegh)';
    $on = ['epf' => !empty($in['epf']['on']), 'esic' => !empty($in['esic']['on']), 'pt' => !empty($in['pt']['on'])];
    $rules = [];
    if ($on['epf']) {
        $cap = (string)r196_rupees($in['epf']['ceilingRupees'] ?? '', 'The EPF wage ceiling');
        foreach (['EE' => ['employeePercent', 'EPF employee contribution'], 'ER' => ['employerPercent', 'EPF employer contribution']] as $s => [$f, $l])
            $rules[] = r196_confirm_rule($user, $company, ['category' => 'epf_rate', 'code' => 'EPF-' . $s, 'label' => $l, 'state' => '', 'effectiveFrom' => $ruleFrom, 'ratePercent' => r196_percent($in['epf'][$f] ?? '', $l), 'amountCents' => '', 'thresholdMaxCents' => $cap], $source);
    }
    if ($on['esic']) {
        $limit = (string)r196_rupees($in['esic']['limitRupees'] ?? '', 'The ESIC wage limit');
        foreach (['EE' => ['employeePercent', 'ESIC employee contribution'], 'ER' => ['employerPercent', 'ESIC employer contribution']] as $s => [$f, $l])
            $rules[] = r196_confirm_rule($user, $company, ['category' => 'esic_rate', 'code' => 'ESIC-' . $s, 'label' => $l, 'state' => '', 'effectiveFrom' => $ruleFrom, 'ratePercent' => r196_percent($in['esic'][$f] ?? '', $l), 'amountCents' => '', 'thresholdMaxCents' => $limit], $source);
    }
    if ($on['pt']) {
        if (!isset(TEGH_INDIA_STATES[$state])) fail('Set the company state in Company details first: Professional Tax follows the state.', 422, 'india_setup_state');
        $rules[] = r196_confirm_rule($user, $company, ['category' => 'pt_slab', 'code' => 'PT-' . $state, 'label' => 'Professional Tax ' . r196_state_name($state) . ' (monthly)', 'state' => $state, 'effectiveFrom' => $ruleFrom,
            'ratePercent' => '', 'amountCents' => (string)r196_rupees($in['pt']['amountRupees'] ?? '', 'The Professional Tax amount', true), 'thresholdMinCents' => '0', 'thresholdMaxCents' => ''], $source);
    }
    $before = r196_components($companyId, max($from, canadian_today()));
    $created = r197_ensure_heads($user, $company, $from);
    // Employees already set up get the newly created statutory heads (rules only; fixed heads need an amount, so they are
    // added when the person enters one), in a structure from the rules' date or their latest structure's date.
    $updated = 0;
    $after = r196_components($companyId, max($from, canadian_today()));
    $newRule = []; foreach (['epf_ee', 'epf_er', 'esic_ee', 'esic_er', 'pt'] as $k) if (isset($after[$k]) && !isset($before[$k])) $newRule[$k] = $after[$k];
    if ($newRule) {
        foreach (in5_records($companyId, 'employee', '9999-12-31') as $eid => $e) {
            $s = in5_structure($companyId, $eid, '9999-12-31'); if (!$s) continue;
            $ids = array_column($s['lines'], 'componentId'); $add = array_values(array_diff($newRule, $ids)); if (!$add) continue;
            $at = max($ruleFrom, (string)$s['effectiveFrom'], (string)($e['dateOfJoining'] ?? $ruleFrom), $from);
            in5_structure_save($user, $company, ['employeeId' => $eid, 'effectiveFrom' => $at, 'lines' => array_merge($s['lines'], array_map(static fn($i) => ['componentId' => $i], $add))]);
            $updated++;
        }
    }
    audit_event($user, $companyId, 'india.payroll_setup_confirmed', 'company', $companyId, ['effectiveFrom' => $ruleFrom, 'items' => $on, 'componentsCreated' => $created, 'employeesUpdated' => $updated]);
    return ['rules' => $rules, 'componentsCreated' => $created, 'employeesUpdated' => $updated, 'state' => r196_payroll_state($company)];
}

/** Add a pay head of the person's own, or rename one. Fixed and statutory heads keep their names. */
function r197_head_save(array $user, array $company, array $in): array
{
    require_company_permission($company, 'payroll.manage');
    $companyId = (string)$company['id']; in2_schema_ready(); in5_schema_ready();
    $from = r196_payroll_from($companyId); if ($from === null) fail('Set up payroll first (step 1).', 409, 'india_setup_payroll_first');
    $date = max(canadian_today(), $from);
    $name = in2_text($in['name'] ?? '', 'Heading', 80);
    $id = trim((string)($in['id'] ?? ''));
    $comps = in5_records($companyId, 'pay_component', $date);
    foreach ($comps as $cid => $c) if ($cid !== $id && mb_strtolower((string)($c['name'] ?? '')) === mb_strtolower($name)) fail('There is already a pay head called ' . $c['name'] . '.', 409, 'india_setup_head_exists');
    foreach (R196_COMPONENTS as $c) if (mb_strtolower($c['name']) === mb_strtolower($name) && ($id === '' || !isset(array_flip(r196_components($companyId, $date))[$id]))) fail($c['name'] . ' is one of the standard pay heads.', 409, 'india_setup_head_exists');
    if ($id === '') {
        $kind = (string)($in['kind'] ?? ''); if (!in_array($kind, ['earning', 'deduction'], true)) fail('Choose allowance or deduction.', 422, 'india_setup_head_kind');
        $data = ['name' => $name, 'kind' => $kind, 'calculation' => 'fixed'] + ($kind === 'earning' ? ['taxable' => true] : []);
        $saved = in2_record_save($user, $company, ['recordType' => 'pay_component', 'effectiveFrom' => $from, 'data' => $data]);
        return ['id' => (string)$saved['id'], 'state' => r196_payroll_state($company)];
    }
    if (!isset($comps[$id])) fail('That pay head no longer exists.', 404, 'india_setup_head_missing');
    if (isset(array_flip(r196_components($companyId, $date))[$id])) fail('Standard pay heads keep their names.', 409, 'india_setup_head_fixed');
    $c = $comps[$id]; $version = (string)$c['versionId'];
    $q = db()->prepare('SELECT effective_from, data_json FROM india_master_records WHERE id=?'); $q->execute([$version]); $row = $q->fetch(PDO::FETCH_ASSOC);
    $data = json_decode((string)$row['data_json'], true) ?: []; $data['name'] = $name;
    in2_record_save($user, $company, ['recordType' => 'pay_component', 'effectiveFrom' => (string)$row['effective_from'], 'replacesId' => $version, 'data' => $data]);
    return ['id' => $id, 'state' => r196_payroll_state($company)];
}

/** Stop using a pay head of the person's own from a date (default today). Pay runs already posted keep it. */
function r197_head_remove(array $user, array $company, array $in): array
{
    require_company_permission($company, 'payroll.manage');
    $companyId = (string)$company['id']; $from = r196_payroll_from($companyId) ?? canadian_today(); $date = max(canadian_today(), $from);
    $id = trim((string)($in['id'] ?? '')); $comps = in5_records($companyId, 'pay_component', $date);
    if (!isset($comps[$id])) fail('That pay head no longer exists.', 404, 'india_setup_head_missing');
    if (isset(array_flip(r196_components($companyId, $date))[$id])) fail('Standard pay heads cannot be removed; leave them empty on employees who do not get them.', 409, 'india_setup_head_fixed');
    in2_record_deactivate($user, $company, ['id' => (string)$comps[$id]['versionId'], 'effectiveTo' => $date]);
    return ['id' => $id, 'state' => r196_payroll_state($company)];
}

/** The pay table of the employee form, without saving: the month's figures from the payroll calculation. */
function r197_preview(array $company, array $in): array
{
    require_company_permission($company, 'payroll.view');
    $companyId = (string)$company['id']; in2_schema_ready(); in5_schema_ready();
    $from = r196_payroll_from($companyId); if ($from === null) fail('Set up payroll first (step 1).', 409, 'india_setup_payroll_first');
    $date = trim((string)($in['salaryFrom'] ?? '')); $date = $date === '' ? canadian_today() : safe_date($date, 'Pay from'); $date = max($date, $from);
    $flags = ['epf' => !empty($in['epf']), 'esic' => !empty($in['esic']), 'pt' => !empty($in['pt'])];
    $lines = r197_lines($companyId, $date, (array)($in['rows'] ?? []), $flags);
    $state = strtoupper((string)$company['province']);
    $employee = ['name' => 'Preview', 'epfApplicable' => $flags['epf'], 'esicApplicable' => $flags['esic'], 'ptApplicable' => $flags['pt'], 'ptState' => $flags['pt'] ? $state : ''];
    return r197_month($companyId, $employee, $lines, $date);
}

function r196_employee_save(array $user, array $company, array $in): array
{
    require_company_permission($company, 'payroll.manage');
    $companyId = (string)$company['id']; in2_schema_ready(); in5_schema_ready();
    $setupFrom = r196_payroll_from($companyId);
    if ($setupFrom === null) fail('Set up payroll first (the step above).', 409, 'india_setup_payroll_first');
    r197_ensure_heads($user, $company, $setupFrom);
    $name = in2_text($in['name'] ?? '', 'Name', 160);
    $join = safe_date((string)($in['dateOfJoining'] ?? ''), 'Date of joining');
    $leave = trim((string)($in['dateOfLeaving'] ?? '')); $leave = $leave === '' ? '' : safe_date($leave, 'Date of leaving');
    if ($leave !== '' && $leave < $join) fail('The date of leaving is before the date of joining.', 422, 'india_setup_dates');
    $state = strtoupper((string)$company['province']);
    $flags = ['epf' => !empty($in['epf']), 'esic' => !empty($in['esic']), 'pt' => !empty($in['pt'])];
    $salaryFrom = trim((string)($in['salaryFrom'] ?? '')); $salaryFrom = $salaryFrom === '' ? $join : safe_date($salaryFrom, 'Pay from');
    $salaryFrom = max($salaryFrom, $join, $setupFrom);
    // Rows of the pay table (R197). The R196 form sent Basic, HRA and Special allowance; those are mapped to rows.
    $rows = (array)($in['rows'] ?? []);
    if (!isset($in['rows'])) {
        $comps = in5_records($companyId, 'pay_component', $salaryFrom); $byName = []; foreach ($comps as $cid => $c) $byName[mb_strtolower((string)$c['name'])] = $cid;
        $keyIds = r196_components($companyId, $salaryFrom);
        foreach (['basic' => $keyIds['basic'] ?? null, 'hra' => $byName['hra'] ?? null, 'special' => $byName['special allowance'] ?? null] as $f => $cid) if ($cid && trim((string)($in[$f] ?? '')) !== '' && (float)$in[$f] > 0) $rows[] = ['componentId' => $cid, 'mode' => 'amount', 'amount' => (string)$in[$f], 'basis' => 'monthly'];
        foreach (['tds' => 'entered', 'epf_ee' => 'rule', 'esic_ee' => 'rule', 'pt' => 'rule'] as $k => $m) if (isset($keyIds[$k])) $rows[] = ['componentId' => $keyIds[$k], 'mode' => $m];
    }
    $lines = r197_lines($companyId, $salaryFrom, $rows, $flags);
    $id = trim((string)($in['id'] ?? ''));
    $current = $id !== '' ? (in5_records($companyId, 'employee', '9999-12-31')[$id] ?? in5_records($companyId, 'employee', $join)[$id] ?? null) : null;
    if ($id !== '' && !$current) fail('That employee no longer exists.', 404, 'india_setup_employee_missing');
    $code = $current['employeeCode'] ?? null;
    if ($code === null) {
        $q = db()->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.employeeCode')) FROM india_master_records WHERE company_id=? AND record_type='employee'"); $q->execute([$companyId]);
        $n = 0; foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $c) if (preg_match('/^E([0-9]{1,6})$/', (string)$c, $m)) $n = max($n, (int)$m[1]);
        $code = sprintf('E%03d', $n + 1);
    }
    $data = array_filter(['employeeCode' => $code, 'name' => $name, 'pan' => strtoupper(trim((string)($in['pan'] ?? ''))), 'dateOfJoining' => $join, 'dateOfLeaving' => $leave,
        'epfApplicable' => $flags['epf'], 'esicApplicable' => $flags['esic'], 'ptApplicable' => $flags['pt'], 'ptState' => $flags['pt'] ? $state : ''], static fn($v) => $v !== '' && $v !== false);
    // Details the full form keeps (UAN, ESIC number, bank, regime...) are carried over when editing.
    if ($current) foreach (['uan', 'esicNumber', 'taxRegime', 'residency', 'lwfApplicable', 'lwfState', 'gratuityEligible', 'leaveEncashmentEligible', 'bankIfsc', 'bankAccountLast4'] as $k) if (isset($current[$k]) && $current[$k] !== '' && $current[$k] !== false) $data[$k] = $current[$k];
    // The pay table was checked above (r197_lines), so the structure saves once the employee record exists.
    $rec = $current
        ? in2_record_save($user, $company, ['recordType' => 'employee', 'effectiveFrom' => (string)db()->query("SELECT effective_from FROM india_master_records WHERE id=" . db()->quote((string)$current['versionId']))->fetchColumn(), 'replacesId' => (string)$current['versionId'], 'data' => $data])
        : in2_record_save($user, $company, ['recordType' => 'employee', 'effectiveFrom' => $join, 'data' => $data]);
    $employeeId = $current ? $id : (string)$rec['id'];
    $structure = in5_structure_save($user, $company, ['employeeId' => $employeeId, 'effectiveFrom' => $salaryFrom, 'lines' => $lines]);
    return ['employeeId' => $employeeId, 'employeeCode' => $code, 'salaryFrom' => $structure['effectiveFrom'], 'state' => r196_payroll_state($company)];
}

/* ---------- route ---------- */

function handle_india_setup(string $action): never
{
    $user = require_user(); $company = require_company($user);
    if (!function_exists('in3_is_india') || !in3_is_india($company)) fail('Not found.', 404, 'india_only');
    header('Cache-Control: private, no-store');
    $method = request_method();
    if ($method === 'GET') {
        if ($action === 'gst') { require_company_permission($company, 'company.view'); in3_schema_ready(); json_response(r196_gst_state($company)); }
        if ($action === 'payroll') { require_company_permission($company, 'payroll.view'); json_response(r196_payroll_state($company)); }
        if ($action === 'tax') { require_company_permission($company, 'company.view'); require_once __DIR__ . '/india_tax_setup_r198.php'; json_response(r198_state($company)); }
        fail('Not found.', 404, 'route_not_found');
    }
    require_method('POST'); require_csrf(); $in = request_json();
    if ($action === 'gst') json_response(r196_gst_apply($user, $company, $in));
    if ($action === 'payroll') json_response(r196_payroll_apply($user, $company, $in));
    if ($action === 'employee') json_response(r196_employee_save($user, $company, $in), 201);
    if ($action === 'head') json_response(r197_head_save($user, $company, $in), 201);
    if ($action === 'head-remove') json_response(r197_head_remove($user, $company, $in));
    if ($action === 'preview') json_response(r197_preview($company, $in));
    if (in_array($action, ['sections', 'tax-label', 'party', 'item'], true)) {
        require_once __DIR__ . '/india_tax_setup_r198.php'; // R198 Tax Set-up
        if ($action === 'sections') json_response(r198_sections_apply($user, $company, $in));
        if ($action === 'tax-label') json_response(r198_label_save($user, $company, $in));
        if ($action === 'party') json_response(r198_party_save($user, $company, $in));
        json_response(r198_item_save($user, $company, $in));
    }
    fail('Not found.', 404, 'route_not_found');
}
