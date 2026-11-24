<?php
declare(strict_types=1);

/*
 * R196 (India only): short set-up screens for GST and payroll.
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
const R196_COMPONENTS = [
    'basic' => ['name' => 'Basic', 'kind' => 'earning', 'calculation' => 'fixed', 'taxable' => true],
    'hra' => ['name' => 'HRA', 'kind' => 'earning', 'calculation' => 'fixed', 'taxable' => true],
    'special' => ['name' => 'Special allowance', 'kind' => 'earning', 'calculation' => 'fixed', 'taxable' => true],
    'tds' => ['name' => 'Income tax (TDS)', 'kind' => 'deduction', 'calculation' => 'entered'],
    'epf_ee' => ['name' => 'EPF (employee)', 'kind' => 'deduction', 'calculation' => 'rule', 'rule' => 'EPF-EE', 'base' => 'basic', 'needs' => 'epf'],
    'epf_er' => ['name' => 'EPF (employer)', 'kind' => 'employer_contribution', 'calculation' => 'rule', 'rule' => 'EPF-ER', 'base' => 'basic', 'needs' => 'epf'],
    'esic_ee' => ['name' => 'ESIC (employee)', 'kind' => 'deduction', 'calculation' => 'rule', 'rule' => 'ESIC-EE', 'needs' => 'esic'],
    'esic_er' => ['name' => 'ESIC (employer)', 'kind' => 'employer_contribution', 'calculation' => 'rule', 'rule' => 'ESIC-ER', 'needs' => 'esic'],
    'pt' => ['name' => 'Professional Tax', 'kind' => 'deduction', 'calculation' => 'rule', 'rule' => 'PT', 'needs' => 'pt'],
];

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
        && ($h['threshold_max_cents'] === null ? null : (int)$h['threshold_max_cents']) === ($r['thresholdMaxCents'] === '' ? null : (int)$r['thresholdMaxCents']);
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
    $q = db()->prepare("SELECT id, rate_key, value_decimal, effective_from, status FROM statutory_rates WHERE company_id=? AND jurisdiction='IN' AND person_type='gst_rate' AND status IN ('active','draft') ORDER BY value_decimal, effective_from");
    $q->execute([$companyId]); $rules = $q->fetchAll(PDO::FETCH_ASSOC);
    $codes = function_exists('in3_codes_list') ? in3_codes_list($companyId) : [];
    $have = [];
    foreach ($rules as $r) {
        $rate = rtrim(rtrim((string)$r['value_decimal'], '0'), '.') ?: '0';
        $have[$rate] = ['rate' => $rate, 'ruleCode' => (string)$r['rate_key'], 'status' => (string)$r['status'], 'effectiveFrom' => (string)$r['effective_from'],
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
    $rates = [];
    foreach ((array)($in['rates'] ?? []) as $r) { $p = r196_percent($r, 'A GST rate'); $rates[$p] = $p; }
    if (!$rates) fail('Tick at least one GST rate.', 422, 'india_setup_no_rates');
    foreach ($rates as $p) if (((int)round((float)$p * 1000)) % 2 !== 0) fail('GST ' . $p . '% cannot be split equally into CGST and SGST. Enter it in India Masters instead.', 422, 'india_gst_rate_split');
    if (!in3_schema_ready()) fail('Try again: the GST tables are being prepared.', 409, 'india_gst_schema_busy');
    $source = 'Confirmed by ' . (string)$user['email'] . ' in GST set-up (suggested by Tegh)';
    $out = [];
    foreach ($rates as $p) {
        $rule = r196_confirm_rule($user, $company, ['category' => 'gst_rate', 'code' => 'GST' . $p, 'label' => 'GST ' . $p . '%', 'state' => '', 'effectiveFrom' => $from, 'ratePercent' => $p, 'amountCents' => '', 'thresholdMaxCents' => ''], $source);
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

/** Set-up pay components that exist (active), keyed by set-up key, matched by name. */
function r196_components(string $companyId, string $date): array
{
    $byName = [];
    foreach (in5_records($companyId, 'pay_component', $date) as $id => $c) $byName[mb_strtolower((string)($c['name'] ?? ''))] = $id;
    $out = [];
    foreach (R196_COMPONENTS as $k => $c) if (isset($byName[mb_strtolower($c['name'])])) $out[$k] = $byName[mb_strtolower($c['name'])];
    return $out;
}

/** The set-up date: when the Basic component starts (the components and rules of the set-up start together). */
function r196_payroll_from(string $companyId): ?string
{
    $q = db()->prepare("SELECT MIN(effective_from) FROM india_master_records WHERE company_id=? AND record_type='pay_component' AND JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.name'))='Basic' AND status IN ('active','superseded','inactive')");
    $q->execute([$companyId]); $d = $q->fetchColumn();
    return $d ? (string)$d : null;
}

function r196_payroll_state(array $company): array
{
    $companyId = (string)$company['id']; in2_schema_ready(); in5_schema_ready();
    $today = canadian_today(); $from = r196_payroll_from($companyId);
    $comp = $from ? r196_components($companyId, max($today, $from)) : [];
    $rule = static function (string $cat, string $key, string $jur = 'IN') use ($companyId): ?array {
        $r = r196_rule($companyId, $jur, $cat, $key);
        return $r ? ['status' => (string)$r['status'], 'ratePercent' => $r['value_decimal'] !== null ? rtrim(rtrim((string)$r['value_decimal'], '0'), '.') : null,
            'amountRupees' => $r['value_cents'] !== null ? intdiv((int)$r['value_cents'], 100) : null, 'limitRupees' => $r['threshold_max_cents'] !== null ? intdiv((int)$r['threshold_max_cents'], 100) : null, 'effectiveFrom' => (string)$r['effective_from']] : null;
    };
    $state = strtoupper((string)$company['province']);
    $employees = [];
    $date = max($today, $from ?? $today);
    foreach (in5_records($companyId, 'employee', $date) + in5_records($companyId, 'employee', '9999-12-31') as $id => $e) {
        $s = in5_structure($companyId, $id, $date) ?? in5_structure($companyId, $id, '9999-12-31');
        $amounts = [];
        if ($s) foreach ($s['lines'] as $l) foreach (['basic', 'hra', 'special'] as $k) if (($comp[$k] ?? null) === $l['componentId']) $amounts[$k] = intdiv((int)$l['amountCents'], 100);
        $employees[$id] = ['id' => $id, 'employeeCode' => $e['employeeCode'] ?? '', 'name' => $e['name'] ?? '', 'pan' => $e['pan'] ?? '', 'dateOfJoining' => $e['dateOfJoining'] ?? '', 'dateOfLeaving' => $e['dateOfLeaving'] ?? '',
            'epf' => !empty($e['epfApplicable']), 'esic' => !empty($e['esicApplicable']), 'pt' => !empty($e['ptApplicable']), 'basic' => $amounts['basic'] ?? 0, 'hra' => $amounts['hra'] ?? 0, 'special' => $amounts['special'] ?? 0, 'salaryFrom' => $s['effectiveFrom'] ?? null];
    }
    return ['done' => isset($comp['basic']), 'effectiveFrom' => $from ?? r196_default_from($company), 'state' => $state, 'stateName' => r196_state_name($state),
        'epf' => ['employee' => $rule('epf_rate', 'EPF-EE'), 'employer' => $rule('epf_rate', 'EPF-ER')], 'esic' => ['employee' => $rule('esic_rate', 'ESIC-EE'), 'employer' => $rule('esic_rate', 'ESIC-ER')],
        'pt' => $rule('pt_slab', 'PT-' . $state, 'IN-' . $state), 'components' => array_keys($comp), 'suggestions' => R196_PAYROLL_SUGGESTIONS, 'employees' => array_values($employees),
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
    // Pay components (once each). Statutory components only when their item is ticked now or was set up before.
    $have = r196_components($companyId, max($from, canadian_today()));
    $created = [];
    foreach (R196_COMPONENTS as $k => $c) {
        if (isset($have[$k])) continue;
        if (!empty($c['needs']) && !$on[$c['needs']]) continue;
        $data = ['name' => $c['name'], 'kind' => $c['kind'], 'calculation' => $c['calculation']];
        if (!empty($c['taxable'])) $data['taxable'] = true;
        if (!empty($c['rule'])) $data['rule'] = $c['rule'] === 'PT' ? 'PT-' . $state : $c['rule'];
        if (!empty($c['base'])) $data['baseComponent'] = $have[$c['base']];
        $saved = in2_record_save($user, $company, ['recordType' => 'pay_component', 'effectiveFrom' => $from, 'data' => $data]);
        $have[$k] = (string)$saved['id']; $created[] = $c['name'];
    }
    // Employees already set up get the newly created statutory components, in a structure from the rules' date (or their
    // latest structure's date when that is later). Their amounts are unchanged.
    $updated = 0;
    if ($created) {
        $newIds = array_values(array_intersect_key($have, array_flip(array_keys(array_filter(R196_COMPONENTS, static fn($c) => in_array($c['name'], $created, true))))));
        foreach (in5_records($companyId, 'employee', '9999-12-31') as $eid => $e) {
            $s = in5_structure($companyId, $eid, '9999-12-31'); if (!$s) continue;
            $ids = array_column($s['lines'], 'componentId'); $add = array_values(array_diff($newIds, $ids)); if (!$add) continue;
            $at = max($ruleFrom, (string)$s['effectiveFrom'], (string)($e['dateOfJoining'] ?? $ruleFrom), $from);
            in5_structure_save($user, $company, ['employeeId' => $eid, 'effectiveFrom' => $at, 'lines' => array_merge($s['lines'], array_map(static fn($i) => ['componentId' => $i], $add))]);
            $updated++;
        }
    }
    audit_event($user, $companyId, 'india.payroll_setup_confirmed', 'company', $companyId, ['effectiveFrom' => $ruleFrom, 'items' => $on, 'componentsCreated' => $created, 'employeesUpdated' => $updated]);
    return ['rules' => $rules, 'componentsCreated' => $created, 'employeesUpdated' => $updated, 'state' => r196_payroll_state($company)];
}

function r196_employee_save(array $user, array $company, array $in): array
{
    require_company_permission($company, 'payroll.manage');
    $companyId = (string)$company['id']; in2_schema_ready(); in5_schema_ready();
    $setupFrom = r196_payroll_from($companyId);
    if ($setupFrom === null) fail('Set up payroll first (the step above).', 409, 'india_setup_payroll_first');
    $name = in2_text($in['name'] ?? '', 'Name', 160);
    $join = safe_date((string)($in['dateOfJoining'] ?? ''), 'Date of joining');
    $leave = trim((string)($in['dateOfLeaving'] ?? '')); $leave = $leave === '' ? '' : safe_date($leave, 'Date of leaving');
    if ($leave !== '' && $leave < $join) fail('The date of leaving is before the date of joining.', 422, 'india_setup_dates');
    $amounts = []; foreach (['basic' => 'Basic', 'hra' => 'HRA', 'special' => 'Special allowance'] as $k => $l) $amounts[$k] = r196_rupees($in[$k] ?? '0', $l, true);
    if ($amounts['basic'] === 0) fail('Enter the monthly Basic.', 422, 'india_setup_basic');
    $state = strtoupper((string)$company['province']);
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
        'epfApplicable' => !empty($in['epf']), 'esicApplicable' => !empty($in['esic']), 'ptApplicable' => !empty($in['pt']), 'ptState' => !empty($in['pt']) ? $state : ''], static fn($v) => $v !== '' && $v !== false);
    // Details the full form keeps (UAN, ESIC number, bank, regime...) are carried over when editing.
    if ($current) foreach (['uan', 'esicNumber', 'taxRegime', 'residency', 'lwfApplicable', 'lwfState', 'gratuityEligible', 'leaveEncashmentEligible', 'bankIfsc', 'bankAccountLast4'] as $k) if (isset($current[$k]) && $current[$k] !== '' && $current[$k] !== false) $data[$k] = $current[$k];
    $rec = $current
        ? in2_record_save($user, $company, ['recordType' => 'employee', 'effectiveFrom' => (string)db()->query("SELECT effective_from FROM india_master_records WHERE id=" . db()->quote((string)$current['versionId']))->fetchColumn(), 'replacesId' => (string)$current['versionId'], 'data' => $data])
        : in2_record_save($user, $company, ['recordType' => 'employee', 'effectiveFrom' => $join, 'data' => $data]);
    $employeeId = $current ? $id : (string)$rec['id'];
    // Salary structure: from the joining date (new employee) or the date the salary changes (editing), never before the set-up.
    $salaryFrom = trim((string)($in['salaryFrom'] ?? '')); $salaryFrom = $salaryFrom === '' ? $join : safe_date($salaryFrom, 'Salary from');
    $salaryFrom = max($salaryFrom, $join, $setupFrom);
    $comp = r196_components($companyId, $salaryFrom);
    $lines = [];
    foreach (['basic', 'hra', 'special'] as $k) if (isset($comp[$k])) $lines[] = ['componentId' => $comp[$k], 'amountCents' => $amounts[$k]];
    foreach (['tds', 'epf_ee', 'epf_er', 'esic_ee', 'esic_er', 'pt'] as $k) if (isset($comp[$k])) $lines[] = ['componentId' => $comp[$k]];
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
        fail('Not found.', 404, 'route_not_found');
    }
    require_method('POST'); require_csrf(); $in = request_json();
    if ($action === 'gst') json_response(r196_gst_apply($user, $company, $in));
    if ($action === 'payroll') json_response(r196_payroll_apply($user, $company, $in));
    if ($action === 'employee') json_response(r196_employee_save($user, $company, $in), 201);
    fail('Not found.', 404, 'route_not_found');
}
