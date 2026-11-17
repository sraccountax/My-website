<?php
declare(strict_types=1);

/*
 * IN-5: user-defined payroll for India companies.
 * - Masters (IN-2, India Masters): employees and pay components (earning, deduction, employer contribution, reimbursement;
 *   fixed, percent of another component, from a statutory rule, or entered each pay run).
 * - Salary structures: the components of each employee and the monthly amount of each fixed component, by effective date.
 * - Pay runs: one per month. Each employee's fixed amounts are prorated by paid days; percent components follow their base;
 *   rule components use the approved statutory rule in force on the pay date (EPF/ESIC rates with their ceilings, Professional
 *   Tax and Labour Welfare Fund slabs of the employee's state). Tegh supplies no rate, slab or ceiling.
 * - Posting: one balanced journal per pay run (expenses debited, deductions, employer contributions and net pay credited).
 *   A posted run can be reversed by the Owner or an Admin; the reversal is a reversing journal.
 * - Income-tax deduction (TDS on salary) is a deduction component entered each pay run; Tegh does not project annual tax.
 * Canadian companies never reach this file (404 india_only), and the Canadian payroll tables are not used.
 */

const IN5_DEFAULT_ACCOUNTS = [
    'earning' => ['6000', 'Salaries and Wages', 'expense', 'debit'],
    'reimbursement' => ['6000', 'Salaries and Wages', 'expense', 'debit'],
    'employer_expense' => ['6010', 'Employer Contributions to Funds', 'expense', 'debit'],
    'net' => ['2300', 'Salaries Payable', 'liability', 'credit'],
    'deduction' => ['2310', 'Payroll Deductions Payable', 'liability', 'credit'],
    'employer_payable' => ['2320', 'Employer Contributions Payable', 'liability', 'credit'],
];

function in5_schema_ready(): bool
{
    static $ready = false;
    if ($ready) return true;
    if (schema_table_exists('india_salary_structures') && schema_table_exists('india_pay_runs') && schema_table_exists('india_pay_run_lines')) return $ready = true;
    if (db()->inTransaction()) return false;
    db()->exec("CREATE TABLE IF NOT EXISTS india_salary_structures (
        id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, employee_id VARCHAR(64) NOT NULL, effective_from DATE NOT NULL,
        lines_json LONGTEXT NOT NULL, created_by VARCHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY india_salary_structures_uq (company_id, employee_id, effective_from),
        CONSTRAINT fk_india_salary_structures_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS india_pay_runs (
        id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, period CHAR(7) NOT NULL, pay_date DATE NOT NULL,
        status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft', inputs_json LONGTEXT NOT NULL,
        gross_cents BIGINT NOT NULL DEFAULT 0, deductions_cents BIGINT NOT NULL DEFAULT 0, employer_cents BIGINT NOT NULL DEFAULT 0, net_cents BIGINT NOT NULL DEFAULT 0,
        journal_entry_id VARCHAR(64) NULL, reversal_journal_entry_id VARCHAR(64) NULL, reversal_reason VARCHAR(500) NULL,
        created_by VARCHAR(64) NOT NULL, posted_by VARCHAR(64) NULL, reversed_by VARCHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY india_pay_runs_period_idx (company_id, period, status),
        CONSTRAINT fk_india_pay_runs_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS india_pay_run_lines (
        id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, run_id VARCHAR(64) NOT NULL, employee_id VARCHAR(64) NOT NULL,
        employee_code VARCHAR(30) NOT NULL, employee_name VARCHAR(160) NOT NULL, paid_days_milli INT NOT NULL, period_days INT NOT NULL,
        components_json LONGTEXT NOT NULL, gross_cents BIGINT NOT NULL, deductions_cents BIGINT NOT NULL, employer_cents BIGINT NOT NULL, net_cents BIGINT NOT NULL,
        KEY india_pay_run_lines_run_idx (run_id),
        CONSTRAINT fk_india_pay_run_lines_run FOREIGN KEY (run_id) REFERENCES india_pay_runs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $ready = true;
}

/* ---------- masters ---------- */

/** Original id of each record version (a change makes a new row that points to the one it replaces). */
function in5_roots(string $companyId, string $type): array
{
    static $cache = [];
    $k = $companyId . '|' . $type;
    if (isset($cache[$k])) return $cache[$k];
    $q = db()->prepare('SELECT id, replaces_id FROM india_master_records WHERE company_id=? AND record_type=?'); $q->execute([$companyId, $type]);
    $parent = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $parent[(string)$r['id']] = (string)($r['replaces_id'] ?? '');
    $root = [];
    foreach ($parent as $id => $_) { $x = $id; for ($i = 0; $i < 100 && ($parent[$x] ?? '') !== '' && isset($parent[$parent[$x]]); $i++) $x = $parent[$x]; $root[$id] = $x; }
    return $cache[$k] = $root;
}

/** India master records of a type in force on a date, keyed by their original id (the version in force is used). */
function in5_records(string $companyId, string $type, string $date): array
{
    if (!schema_table_exists('india_master_records')) return [];
    $q = db()->prepare("SELECT id, data_json FROM india_master_records WHERE company_id=? AND record_type=? AND status IN ('active','inactive','superseded')
        AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from, created_at");
    $q->execute([$companyId, $type, $date, $date]);
    $roots = in5_roots($companyId, $type); $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = json_decode((string)$r['data_json'], true); if (!is_array($d)) continue;
        $root = $roots[(string)$r['id']] ?? (string)$r['id'];
        if ($type === 'pay_component' && !empty($d['baseComponent'])) { $pc = in5_roots($companyId, 'pay_component'); $d['baseComponent'] = $pc[(string)$d['baseComponent']] ?? $d['baseComponent']; }
        $out[$root] = ['id' => $root, 'versionId' => (string)$r['id']] + $d;
    }
    return $out;
}

/** Employees employed at any time in the month (joining on or before the month end, not left before the month starts). */
function in5_employees(string $companyId, string $from, string $to): array
{
    $out = [];
    foreach (in5_records($companyId, 'employee', $to) + in5_records($companyId, 'employee', $from) as $id => $e) {
        $join = (string)($e['dateOfJoining'] ?? ''); $leave = (string)($e['dateOfLeaving'] ?? '');
        if ($join === '' || $join > $to || ($leave !== '' && $leave < $from)) continue;
        $out[$id] = $e;
    }
    uasort($out, static fn($a, $b) => strcmp((string)($a['employeeCode'] ?? ''), (string)($b['employeeCode'] ?? '')));
    return $out;
}

function in5_structure(string $companyId, string $employeeId, string $date): ?array
{
    if (!schema_table_exists('india_salary_structures')) return null;
    $q = db()->prepare('SELECT * FROM india_salary_structures WHERE company_id=? AND employee_id=? AND effective_from<=? ORDER BY effective_from DESC LIMIT 1');
    $q->execute([$companyId, $employeeId, $date]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return ['id' => $r['id'], 'effectiveFrom' => $r['effective_from'], 'lines' => json_decode((string)$r['lines_json'], true) ?: []];
}

function in5_structure_save(array $user, array $company, array $input): array
{
    require_company_permission($company, 'payroll.manage');
    $companyId = (string)$company['id'];
    $employeeId = clean_text($input['employeeId'] ?? '', 'Employee', 64);
    $from = safe_date((string)($input['effectiveFrom'] ?? ''), 'Effective from');
    $employees = in5_records($companyId, 'employee', $from);
    if (!isset($employees[$employeeId])) fail('Choose an employee who is active (India Masters › Records › Employee) on the effective date.', 422, 'india_payroll_employee');
    $components = in5_records($companyId, 'pay_component', $from);
    $lines = [];
    foreach ((array)($input['lines'] ?? []) as $l) {
        if (!is_array($l)) continue;
        $cid = (string)($l['componentId'] ?? '');
        if (!isset($components[$cid])) fail('A pay component in the structure is not an active pay component on the effective date.', 422, 'india_payroll_component');
        if (isset($lines[$cid])) fail('A pay component appears twice in the structure.', 422, 'india_payroll_component_twice');
        $c = $components[$cid];
        $amount = null;
        if (($c['calculation'] ?? '') === 'fixed') {
            $raw = $l['amountCents'] ?? null;
            if (!is_int($raw) && !(is_string($raw) && preg_match('/^[0-9]{1,13}$/', $raw))) fail('Enter the monthly amount of ' . ($c['name'] ?? 'the fixed component') . '.', 422, 'india_payroll_amount');
            $amount = (int)$raw;
        }
        $lines[$cid] = ['componentId' => $cid, 'amountCents' => $amount];
    }
    if (!$lines) fail('Add at least one pay component to the structure.', 422, 'india_payroll_structure_empty');
    // Percent components need their base component in the same structure.
    foreach ($lines as $cid => $_) {
        $c = $components[$cid];
        if (($c['calculation'] ?? '') === 'percent' && !isset($lines[(string)($c['baseComponent'] ?? '')])) fail(($c['name'] ?? 'A component') . ' is a percent of another component that is not in this structure.', 422, 'india_payroll_base_missing');
    }
    in5_schema_ready();
    $id = new_id('salstruct');
    $q = db()->prepare('SELECT id FROM india_salary_structures WHERE company_id=? AND employee_id=? AND effective_from=?'); $q->execute([$companyId, $employeeId, $from]);
    $existing = $q->fetchColumn();
    if ($existing) { db()->prepare('UPDATE india_salary_structures SET lines_json=?, created_by=? WHERE id=?')->execute([json_encode(array_values($lines)), $user['id'], $existing]); $id = (string)$existing; }
    else db()->prepare('INSERT INTO india_salary_structures (id,company_id,employee_id,effective_from,lines_json,created_by) VALUES (?,?,?,?,?,?)')->execute([$id, $companyId, $employeeId, $from, json_encode(array_values($lines)), $user['id']]);
    audit_event($user, $companyId, 'india.salary_structure_saved', 'india_salary_structure', $id, ['employeeId' => $employeeId, 'effectiveFrom' => $from, 'lines' => array_values($lines), 'replaced' => (bool)$existing]);
    return ['id' => $id, 'effectiveFrom' => $from];
}

/* ---------- calculation ---------- */

/** Statutory rule rows for a pay component's rule on the pay date: the rule category and jurisdiction decide which rows apply. */
function in5_rule_rows(string $companyId, string $ruleCode, array $employee, string $date): array
{
    $q = db()->prepare("SELECT person_type FROM statutory_rates WHERE company_id=? AND rate_key=? AND jurisdiction LIKE 'IN%' AND person_type IN ('epf_rate','esic_rate','pt_slab','lwf_rate') ORDER BY effective_from DESC LIMIT 1");
    $q->execute([$companyId, $ruleCode]);
    $cat = (string)($q->fetchColumn() ?: '');
    if ($cat === '') return ['category' => null, 'rows' => [], 'applies' => false, 'why' => 'rule ' . $ruleCode . ' not found'];
    $flag = ['epf_rate' => 'epfApplicable', 'esic_rate' => 'esicApplicable', 'pt_slab' => 'ptApplicable', 'lwf_rate' => 'lwfApplicable'][$cat];
    if (empty($employee[$flag])) return ['category' => $cat, 'rows' => [], 'applies' => false, 'why' => 'not applicable to this employee'];
    if (in_array($cat, ['pt_slab', 'lwf_rate'], true)) {
        // Slabs: every approved row of the category for the employee's state, in force on the pay date.
        $state = strtoupper((string)($employee[$cat === 'pt_slab' ? 'ptState' : 'lwfState'] ?? ''));
        if ($state === '') return ['category' => $cat, 'rows' => [], 'applies' => false, 'why' => 'no state on the employee'];
        $r = db()->prepare("SELECT * FROM statutory_rates WHERE company_id=? AND jurisdiction=? AND person_type=? AND status='active' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY threshold_min_cents, rate_key");
        $r->execute([$companyId, 'IN-' . $state, $cat, $date, $date]);
        return ['category' => $cat, 'rows' => $r->fetchAll(PDO::FETCH_ASSOC), 'applies' => true, 'why' => ''];
    }
    $r = db()->prepare("SELECT * FROM statutory_rates WHERE company_id=? AND rate_key=? AND person_type=? AND jurisdiction='IN' AND status='active' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC LIMIT 1");
    $r->execute([$companyId, $ruleCode, $cat, $date, $date]);
    return ['category' => $cat, 'rows' => $r->fetchAll(PDO::FETCH_ASSOC), 'applies' => true, 'why' => ''];
}

function in5_round_rupee(int $paise): int { return (int)(round($paise / 100) * 100); }

/** Apply a rule to a base amount. EPF: rate on the base capped at "threshold to". ESIC: rate on the base, nothing above "threshold to". Slabs: the row whose range holds the base. */
function in5_apply_rule(array $rule, int $base): array
{
    if (!$rule['applies']) return [0, $rule['why']];
    if (!$rule['rows']) fail('No approved ' . str_replace('_', ' ', (string)$rule['category']) . ' rule is in force on the pay date for this employee. Add or approve it in India Masters › Statutory rules.', 422, 'india_payroll_rule_missing');
    $cat = $rule['category'];
    if ($cat === 'epf_rate') {
        $r = $rule['rows'][0]; $cap = $r['threshold_max_cents'] !== null ? (int)$r['threshold_max_cents'] : null;
        $b = $cap !== null ? min($base, $cap) : $base;
        return [in5_round_rupee((int)round($b * (float)$r['value_decimal'] / 100)), 'rate ' . (float)$r['value_decimal'] . '% of ' . ($cap !== null && $base > $cap ? 'ceiling ' : '') . number_format($b / 100, 2, '.', '')];
    }
    if ($cat === 'esic_rate') {
        $r = $rule['rows'][0]; $limit = $r['threshold_max_cents'] !== null ? (int)$r['threshold_max_cents'] : null;
        if ($limit !== null && $base > $limit) return [0, 'wages above the ESIC limit'];
        return [in5_round_rupee((int)round($base * (float)$r['value_decimal'] / 100)), 'rate ' . (float)$r['value_decimal'] . '%'];
    }
    foreach ($rule['rows'] as $r) {
        $min = $r['threshold_min_cents'] !== null ? (int)$r['threshold_min_cents'] : PHP_INT_MIN; $max = $r['threshold_max_cents'] !== null ? (int)$r['threshold_max_cents'] : PHP_INT_MAX;
        if ($base < $min || $base > $max) continue;
        if ($r['value_cents'] !== null) return [(int)$r['value_cents'], 'slab ' . $r['rate_key']];
        return [in5_round_rupee((int)round($base * (float)$r['value_decimal'] / 100)), 'slab ' . $r['rate_key'] . ' rate ' . (float)$r['value_decimal'] . '%'];
    }
    return [0, 'no slab for this amount'];
}

/**
 * Calculate one employee for a month.
 * $paidDaysMilli: days paid × 1000 (fixed amounts are prorated by paid days / days in the month).
 * $entered: componentId => paise for components entered each pay run.
 */
function in5_calculate_employee(string $companyId, array $employee, array $structure, array $components, string $from, string $to, string $payDate, int $paidDaysMilli, array $entered): array
{
    $days = (int)date('t', strtotime($from));
    $lines = [];
    foreach ($structure['lines'] as $l) { $cid = (string)$l['componentId']; if (isset($components[$cid])) $lines[$cid] = ['component' => $components[$cid], 'amount' => $l['amountCents']]; }
    $value = []; $note = [];
    $pending = array_keys($lines);
    $gross = static function () use (&$value, $lines): int { $g = 0; foreach ($value as $cid => $v) if (in_array($lines[$cid]['component']['kind'] ?? '', ['earning', 'reimbursement'], true)) $g += $v; return $g; };
    // Fixed and entered components first; then percent and rule components once their base is known.
    for ($guard = 0; $pending && $guard < 50; $guard++) {
        $progress = false;
        foreach ($pending as $i => $cid) {
            $c = $lines[$cid]['component']; $calc = (string)($c['calculation'] ?? '');
            if ($calc === 'fixed') { $value[$cid] = (int)round((int)$lines[$cid]['amount'] * $paidDaysMilli / ($days * 1000)); $note[$cid] = $paidDaysMilli === $days * 1000 ? 'monthly amount' : 'prorated ' . rtrim(rtrim(number_format($paidDaysMilli / 1000, 3, '.', ''), '0'), '.') . '/' . $days . ' days'; }
            elseif ($calc === 'entered') { $v = $entered[$cid] ?? 0; if (!is_int($v) && !(is_string($v) && preg_match('/^[0-9]{1,13}$/', $v))) fail('Enter ' . ($c['name'] ?? 'the amount') . ' as a whole number of paise.', 422, 'india_payroll_entered'); $value[$cid] = (int)$v; $note[$cid] = 'entered for this pay run'; }
            elseif ($calc === 'percent') {
                $base = (string)($c['baseComponent'] ?? '');
                if (!isset($value[$base])) continue;
                $value[$cid] = (int)round($value[$base] * (float)($c['percent'] ?? 0) / 100); $note[$cid] = (float)($c['percent'] ?? 0) . '% of ' . ($lines[$base]['component']['name'] ?? 'base');
            } elseif ($calc === 'rule') {
                $base = (string)($c['baseComponent'] ?? '');
                if ($base !== '') { if (!isset($value[$base])) continue; $b = $value[$base]; }
                else {
                    // Without a base component the rule applies to gross pay, once every earning is known.
                    $waiting = false; foreach ($pending as $p) if ($p !== $cid && in_array($lines[$p]['component']['kind'] ?? '', ['earning', 'reimbursement'], true)) $waiting = true;
                    if ($waiting) continue; $b = $gross();
                }
                [$value[$cid], $note[$cid]] = in5_apply_rule(in5_rule_rows($companyId, (string)($c['rule'] ?? ''), $employee, $payDate), $b);
            } else fail('Pay component ' . ($c['name'] ?? '') . ' has no calculation.', 422, 'india_payroll_component');
            unset($pending[$i]); $progress = true;
        }
        if (!$progress) break;
    }
    if ($pending) fail('These pay components depend on each other or on a component missing from the structure: ' . implode(', ', array_map(static fn($c) => $lines[$c]['component']['name'] ?? $c, $pending)) . '.', 422, 'india_payroll_cycle');
    $out = []; $g = 0; $d = 0; $er = 0;
    foreach ($lines as $cid => $x) {
        $kind = (string)($x['component']['kind'] ?? '');
        $out[] = ['componentId' => $cid, 'name' => (string)($x['component']['name'] ?? ''), 'kind' => $kind, 'amountCents' => $value[$cid], 'basis' => $note[$cid]];
        if (in_array($kind, ['earning', 'reimbursement'], true)) $g += $value[$cid]; elseif ($kind === 'deduction') $d += $value[$cid]; else $er += $value[$cid];
    }
    if ($g - $d < 0) fail('Deductions of ' . ($employee['name'] ?? 'an employee') . ' are more than gross pay.', 422, 'india_payroll_negative_net');
    return ['components' => $out, 'grossCents' => $g, 'deductionsCents' => $d, 'employerCents' => $er, 'netCents' => $g - $d];
}

/** Days employed in the month (joining and leaving dates prorate automatically). */
function in5_employed_days(array $e, string $from, string $to): int
{
    $s = max($from, (string)$e['dateOfJoining']); $end = min($to, (string)(($e['dateOfLeaving'] ?? '') ?: $to));
    return $end < $s ? 0 : (int)((strtotime($end) - strtotime($s)) / 86400) + 1;
}

function in5_run_save(array $user, array $company, array $input): array
{
    require_company_permission($company, 'payroll.manage');
    $companyId = (string)$company['id'];
    $period = (string)($input['period'] ?? '');
    if (!preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/', $period)) fail('Choose the pay month.', 422, 'india_payroll_period');
    $from = $period . '-01'; $to = date('Y-m-t', strtotime($from)); $days = (int)date('t', strtotime($from));
    $payDate = safe_date((string)($input['payDate'] ?? ''), 'Pay date');
    if ($payDate < $from) fail('The pay date is before the pay month.', 422, 'india_payroll_pay_date');
    $inputs = is_array($input['inputs'] ?? null) ? $input['inputs'] : [];
    in5_schema_ready();
    $employees = in5_employees($companyId, $from, $to);
    if (!$employees) fail('No employee is employed in ' . $period . ' (India Masters › Records › Employee).', 422, 'india_payroll_no_employees');
    $components = in5_records($companyId, 'pay_component', $to) + in5_records($companyId, 'pay_component', $from);
    $calc = []; $skipped = [];
    foreach ($employees as $id => $e) {
        $st = in5_structure($companyId, $id, $to);
        if (!$st) { $skipped[] = ['employeeId' => $id, 'name' => $e['name'] ?? '', 'reason' => 'no salary structure']; continue; }
        $in = is_array($inputs[$id] ?? null) ? $inputs[$id] : [];
        $employed = in5_employed_days($e, $from, $to);
        $paid = $employed * 1000;
        if (isset($in['paidDays']) && $in['paidDays'] !== '') {
            if (!is_numeric($in['paidDays']) || (float)$in['paidDays'] < 0 || (float)$in['paidDays'] > $employed) fail('Paid days of ' . ($e['name'] ?? '') . ' must be from 0 to ' . $employed . '.', 422, 'india_payroll_paid_days');
            $paid = (int)round((float)$in['paidDays'] * 1000);
        }
        $r = in5_calculate_employee($companyId, $e, $st, $components, $from, $to, $payDate, $paid, is_array($in['entered'] ?? null) ? $in['entered'] : []);
        $calc[$id] = $r + ['employee' => $e, 'paidDaysMilli' => $paid];
    }
    if (!$calc) fail('No employee has a salary structure for ' . $period . '.', 422, 'india_payroll_no_structures');
    db()->beginTransaction();
    try {
        $q = db()->prepare("SELECT * FROM india_pay_runs WHERE company_id=? AND period=? AND status<>'reversed' FOR UPDATE"); $q->execute([$companyId, $period]); $cur = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($cur && $cur['status'] === 'posted') fail('The pay run for ' . $period . ' is posted. Reverse it before calculating it again.', 409, 'india_payroll_run_posted');
        $tot = ['g' => 0, 'd' => 0, 'e' => 0, 'n' => 0]; foreach ($calc as $r) { $tot['g'] += $r['grossCents']; $tot['d'] += $r['deductionsCents']; $tot['e'] += $r['employerCents']; $tot['n'] += $r['netCents']; }
        $runId = $cur ? (string)$cur['id'] : new_id('inpayrun');
        if ($cur) {
            db()->prepare('DELETE FROM india_pay_run_lines WHERE run_id=?')->execute([$runId]);
            db()->prepare('UPDATE india_pay_runs SET pay_date=?, inputs_json=?, gross_cents=?, deductions_cents=?, employer_cents=?, net_cents=? WHERE id=?')->execute([$payDate, json_encode($inputs), $tot['g'], $tot['d'], $tot['e'], $tot['n'], $runId]);
        } else db()->prepare('INSERT INTO india_pay_runs (id,company_id,period,pay_date,inputs_json,gross_cents,deductions_cents,employer_cents,net_cents,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$runId, $companyId, $period, $payDate, json_encode($inputs), $tot['g'], $tot['d'], $tot['e'], $tot['n'], $user['id']]);
        $ins = db()->prepare('INSERT INTO india_pay_run_lines (id,company_id,run_id,employee_id,employee_code,employee_name,paid_days_milli,period_days,components_json,gross_cents,deductions_cents,employer_cents,net_cents) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($calc as $id => $r) $ins->execute([new_id('inpayline'), $companyId, $runId, $id, (string)($r['employee']['employeeCode'] ?? ''), (string)($r['employee']['name'] ?? ''), $r['paidDaysMilli'], $days, json_encode($r['components']), $r['grossCents'], $r['deductionsCents'], $r['employerCents'], $r['netCents']]);
        audit_event($user, $companyId, 'india.pay_run_calculated', 'india_pay_run', $runId, ['period' => $period, 'payDate' => $payDate, 'employees' => count($calc), 'grossCents' => $tot['g'], 'netCents' => $tot['n']]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return in5_run_get($companyId, $runId) + ['skipped' => $skipped];
}

function in5_run_get(string $companyId, string $runId): array
{
    $q = db()->prepare('SELECT * FROM india_pay_runs WHERE company_id=? AND id=?'); $q->execute([$companyId, $runId]); $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('Pay run not found.', 404, 'india_payroll_run_missing');
    $l = db()->prepare('SELECT * FROM india_pay_run_lines WHERE run_id=? ORDER BY employee_code, employee_name'); $l->execute([$runId]);
    return ['run' => ['id' => $r['id'], 'period' => $r['period'], 'payDate' => $r['pay_date'], 'status' => $r['status'], 'grossCents' => (int)$r['gross_cents'], 'deductionsCents' => (int)$r['deductions_cents'],
        'employerCents' => (int)$r['employer_cents'], 'netCents' => (int)$r['net_cents'], 'journalEntryId' => $r['journal_entry_id'], 'reversalJournalEntryId' => $r['reversal_journal_entry_id'], 'reversalReason' => $r['reversal_reason'], 'inputs' => json_decode((string)$r['inputs_json'], true) ?: []],
        'lines' => array_map(static fn($x) => ['employeeId' => $x['employee_id'], 'employeeCode' => $x['employee_code'], 'name' => $x['employee_name'], 'paidDaysMilli' => (int)$x['paid_days_milli'], 'periodDays' => (int)$x['period_days'],
            'components' => json_decode((string)$x['components_json'], true) ?: [], 'grossCents' => (int)$x['gross_cents'], 'deductionsCents' => (int)$x['deductions_cents'], 'employerCents' => (int)$x['employer_cents'], 'netCents' => (int)$x['net_cents']], $l->fetchAll(PDO::FETCH_ASSOC))];
}

/* ---------- posting ---------- */

function in5_account(string $companyId, string $role): string
{
    [$code, $name, $type, $normal] = IN5_DEFAULT_ACCOUNTS[$role];
    $q = db()->prepare('SELECT id, account_type FROM accounts WHERE company_id=? AND code=?'); $q->execute([$companyId, $code]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ((string)$row['account_type'] !== $type) fail('Account ' . $code . ' is a ' . $row['account_type'] . ' account, so it cannot hold ' . $name . '. Set a GL account on the pay component, or change that account\'s code.', 409, 'india_payroll_account_conflict');
        return (string)$row['id'];
    }
    tax_ensure_account($companyId, $code, $name, $type, $normal, null);
    $q->execute([$companyId, $code]);
    return (string)$q->fetchColumn();
}

function in5_component_account(string $companyId, array $component, string $field, string $role, string $wantType): string
{
    $id = (string)($component[$field] ?? '');
    if ($id !== '') {
        $q = db()->prepare('SELECT account_type FROM accounts WHERE company_id=? AND id=? AND active=1'); $q->execute([$companyId, $id]);
        $type = (string)($q->fetchColumn() ?: '');
        if ($type === '') fail('The GL account of pay component ' . ($component['name'] ?? '') . ' is not an active account of this company.', 422, 'india_payroll_account');
        if ($type !== $wantType) fail('The ' . ($field === 'payableAccount' ? 'payable' : 'GL') . ' account of pay component ' . ($component['name'] ?? '') . ' must be a' . ($wantType === 'expense' ? 'n expense' : ' liability') . ' account.', 422, 'india_payroll_account_type');
        return $id;
    }
    return in5_account($companyId, $role);
}

/** Journal lines of a calculated run, one line per account and side. */
function in5_journal_lines(string $companyId, array $run): array
{
    $components = [];
    $comp = static function (string $cid) use ($companyId, &$components, $run): array {
        if (!isset($components[$cid])) { $all = in5_records($companyId, 'pay_component', $run['run']['payDate']) + in5_records($companyId, 'pay_component', $run['run']['period'] . '-01'); $components += $all; }
        return $components[$cid] ?? ['name' => 'component'];
    };
    $dr = []; $cr = [];
    $add = static function (array &$side, string $acct, int $cents, string $memo): void { if ($cents === 0) return; $side[$acct] ??= ['cents' => 0, 'memo' => $memo]; $side[$acct]['cents'] += $cents; };
    foreach ($run['lines'] as $l) foreach ($l['components'] as $c) {
        $m = $comp((string)$c['componentId']); $v = (int)$c['amountCents'];
        if ($v === 0) continue;
        switch ($c['kind']) {
            case 'earning': case 'reimbursement':
                $add($dr, in5_component_account($companyId, $m, 'glAccount', $c['kind'], 'expense'), $v, 'Salaries and wages ' . $run['run']['period']); break;
            case 'deduction':
                $add($cr, in5_component_account($companyId, $m, 'payableAccount', 'deduction', 'liability'), $v, $c['name'] . ' deducted ' . $run['run']['period']); break;
            case 'employer_contribution':
                $add($dr, in5_component_account($companyId, $m, 'glAccount', 'employer_expense', 'expense'), $v, $c['name'] . ' (employer) ' . $run['run']['period']);
                $add($cr, in5_component_account($companyId, $m, 'payableAccount', 'employer_payable', 'liability'), $v, $c['name'] . ' (employer) payable ' . $run['run']['period']); break;
        }
    }
    $add($cr, in5_account($companyId, 'net'), $run['run']['netCents'], 'Net salaries payable ' . $run['run']['period']);
    $lines = [];
    foreach ($dr as $a => $x) $lines[] = ['accountId' => $a, 'debitCents' => $x['cents'], 'creditCents' => 0, 'memo' => $x['memo']];
    foreach ($cr as $a => $x) $lines[] = ['accountId' => $a, 'debitCents' => 0, 'creditCents' => $x['cents'], 'memo' => $x['memo']];
    return $lines;
}

function in5_run_post(array $user, array $company, array $input): array
{
    if (!in_array((string)$company['role'], ['owner', 'admin'], true)) fail('Only the Company Owner or an Admin posts a pay run.', 403, 'india_payroll_post_role');
    $companyId = (string)$company['id']; $runId = clean_text($input['id'] ?? '', 'Pay run', 64);
    in5_schema_ready();
    $run = in5_run_get($companyId, $runId);
    if ($run['run']['status'] !== 'draft') fail('Only a draft pay run can be posted.', 409, 'india_payroll_not_draft');
    // Accounts are made before the transaction (account creation may run DDL-free inserts, but keep the journal atomic).
    $lines = in5_journal_lines($companyId, $run);
    db()->beginTransaction();
    try {
        $q = db()->prepare("SELECT status FROM india_pay_runs WHERE id=? AND company_id=? FOR UPDATE"); $q->execute([$runId, $companyId]);
        if ($q->fetchColumn() !== 'draft') fail('Only a draft pay run can be posted.', 409, 'india_payroll_not_draft');
        $je = add_journal_entry($user, $companyId, $run['run']['payDate'], 'india_pay_run', $runId, 'Payroll ' . $run['run']['period'] . ' (' . count($run['lines']) . ' employees)', $lines);
        db()->prepare("UPDATE india_pay_runs SET status='posted', journal_entry_id=?, posted_by=? WHERE id=?")->execute([$je, $user['id'], $runId]);
        audit_event($user, $companyId, 'india.pay_run_posted', 'india_pay_run', $runId, ['period' => $run['run']['period'], 'journalEntryId' => $je, 'grossCents' => $run['run']['grossCents'], 'netCents' => $run['run']['netCents']]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return in5_run_get($companyId, $runId);
}

function in5_run_reverse(array $user, array $company, array $input): array
{
    if (!in_array((string)$company['role'], ['owner', 'admin'], true)) fail('Only the Company Owner or an Admin reverses a pay run.', 403, 'india_payroll_post_role');
    $companyId = (string)$company['id']; $runId = clean_text($input['id'] ?? '', 'Pay run', 64);
    $reason = trim((string)($input['reason'] ?? '')); if (mb_strlen($reason) < 5) fail('Give the reason for reversing (at least 5 characters).', 422, 'india_payroll_reason');
    $date = safe_date((string)($input['date'] ?? '') ?: canadian_today(), 'Reversal date');
    in5_schema_ready();
    db()->beginTransaction();
    try {
        $q = db()->prepare("SELECT * FROM india_pay_runs WHERE id=? AND company_id=? FOR UPDATE"); $q->execute([$runId, $companyId]); $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['status'] !== 'posted') fail('Only a posted pay run can be reversed.', 409, 'india_payroll_not_posted');
        if ($date < (string)$r['pay_date']) fail('The reversal date is before the pay date.', 422, 'india_payroll_reversal_date');
        require_journal_unmatched_before_reversal($companyId, (string)$r['journal_entry_id'], 'pay run');
        $je = add_reversing_journal_entry($user, $companyId, (string)$r['journal_entry_id'], $date, 'india_pay_run_reversal', $runId, 'Reversal of payroll ' . $r['period'] . ': ' . mb_substr($reason, 0, 200));
        db()->prepare("UPDATE india_pay_runs SET status='reversed', reversal_journal_entry_id=?, reversal_reason=?, reversed_by=? WHERE id=?")->execute([$je, mb_substr($reason, 0, 500), $user['id'], $runId]);
        audit_event($user, $companyId, 'india.pay_run_reversed', 'india_pay_run', $runId, ['period' => $r['period'], 'reversalJournalEntryId' => $je, 'reason' => mb_substr($reason, 0, 500)]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return in5_run_get($companyId, $runId);
}

/* ---------- registers ---------- */

/** Salary register, component totals and liability check for the posted runs of a period range. */
function in5_registers(array $company, string $from, string $to): array
{
    $companyId = (string)$company['id'];
    $q = db()->prepare("SELECT id FROM india_pay_runs WHERE company_id=? AND status='posted' AND period BETWEEN ? AND ? ORDER BY period"); $q->execute([$companyId, substr($from, 0, 7), substr($to, 0, 7)]);
    $employees = []; $components = []; $runs = [];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $run = in5_run_get($companyId, (string)$id); $runs[] = $run['run'];
        foreach ($run['lines'] as $l) {
            $k = $l['employeeId'];
            $employees[$k] ??= ['employeeCode' => $l['employeeCode'], 'name' => $l['name'], 'grossCents' => 0, 'deductionsCents' => 0, 'employerCents' => 0, 'netCents' => 0, 'components' => []];
            foreach (['grossCents', 'deductionsCents', 'employerCents', 'netCents'] as $f) $employees[$k][$f] += $l[$f];
            foreach ($l['components'] as $c) {
                $employees[$k]['components'][$c['name']] = ($employees[$k]['components'][$c['name']] ?? 0) + (int)$c['amountCents'];
                $components[$c['componentId']] ??= ['name' => $c['name'], 'kind' => $c['kind'], 'amountCents' => 0, 'employees' => 0];
                $components[$c['componentId']]['amountCents'] += (int)$c['amountCents']; if ((int)$c['amountCents'] !== 0) $components[$c['componentId']]['employees']++;
            }
        }
    }
    // Liability check: credits posted by these runs to each payable account equal the register totals.
    $liab = [];
    foreach ($runs as $r) {
        if (!$r['journalEntryId']) continue;
        $l = db()->prepare('SELECT a.code, a.name, SUM(jl.credit_cents) cr FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id=? AND jl.credit_cents>0 GROUP BY a.id'); $l->execute([$r['journalEntryId']]);
        foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $x) { $liab[$x['code']] ??= ['accountCode' => $x['code'], 'accountName' => $x['name'], 'postedCents' => 0]; $liab[$x['code']]['postedCents'] += (int)$x['cr']; }
    }
    $tot = ['grossCents' => 0, 'deductionsCents' => 0, 'employerCents' => 0, 'netCents' => 0]; foreach ($employees as $e) foreach ($tot as $k => $_) $tot[$k] += $e[$k];
    $posted = array_sum(array_column($liab, 'postedCents'));
    return ['from' => $from, 'to' => $to, 'runs' => $runs, 'employees' => array_values($employees), 'components' => array_values($components), 'totals' => $tot, 'liabilities' => array_values($liab),
        'ties' => $posted === $tot['deductionsCents'] + $tot['employerCents'] + $tot['netCents']];
}

/* ---------- routes: india/payroll/... ---------- */

function handle_india_payroll(string $action): never
{
    $user = require_user(); $company = require_company($user);
    if (!function_exists('in3_is_india') || !in3_is_india($company)) fail('Not found.', 404, 'india_only');
    $companyId = (string)$company['id'];
    $method = request_method();
    if ($method === 'GET' && $action === 'context') {
        require_company_permission($company, 'payroll.view');
        in5_schema_ready();
        $date = trim((string)($_GET['date'] ?? '')) ?: canadian_today(); $date = safe_date($date, 'Date');
        $employees = [];
        foreach (in5_records($companyId, 'employee', $date) as $id => $e) $employees[] = ['id' => $id, 'employeeCode' => $e['employeeCode'] ?? '', 'name' => $e['name'] ?? '', 'dateOfJoining' => $e['dateOfJoining'] ?? null, 'dateOfLeaving' => $e['dateOfLeaving'] ?? null, 'structure' => in5_structure($companyId, $id, '9999-12-31')];
        $components = [];
        foreach (in5_records($companyId, 'pay_component', $date) as $id => $c) $components[] = ['id' => $id, 'name' => $c['name'] ?? '', 'kind' => $c['kind'] ?? '', 'calculation' => $c['calculation'] ?? '', 'percent' => $c['percent'] ?? null, 'baseComponent' => $c['baseComponent'] ?? null, 'rule' => $c['rule'] ?? null];
        $runs = db()->prepare('SELECT id, period, pay_date, status, gross_cents, net_cents FROM india_pay_runs WHERE company_id=? ORDER BY period DESC, created_at DESC LIMIT 36'); $runs->execute([$companyId]);
        json_response(['employees' => $employees, 'components' => $components, 'runs' => array_map(static fn($r) => ['id' => $r['id'], 'period' => $r['period'], 'payDate' => $r['pay_date'], 'status' => $r['status'], 'grossCents' => (int)$r['gross_cents'], 'netCents' => (int)$r['net_cents']], $runs->fetchAll(PDO::FETCH_ASSOC)),
            'canPost' => in_array((string)$company['role'], ['owner', 'admin'], true), 'canManage' => company_role_can((string)$company['role'], 'payroll.manage')]);
    }
    if ($method === 'GET' && $action === 'run') {
        require_company_permission($company, 'payroll.view'); in5_schema_ready();
        json_response(in5_run_get($companyId, clean_text($_GET['id'] ?? '', 'Pay run', 64)));
    }
    if ($method === 'GET' && $action === 'registers') {
        require_company_permission($company, 'payroll.view'); in5_schema_ready();
        $from = safe_date((string)($_GET['from'] ?? ''), 'From'); $to = safe_date((string)($_GET['to'] ?? ''), 'To'); if ($to < $from) fail('To is before From.', 422, 'india_payroll_period');
        json_response(in5_registers($company, $from, $to));
    }
    if ($method === 'POST') {
        require_csrf();
        $in = request_json();
        if ($action === 'structure') json_response(in5_structure_save($user, $company, $in), 201);
        if ($action === 'run') json_response(in5_run_save($user, $company, $in), 201);
        if ($action === 'run-post') json_response(in5_run_post($user, $company, $in));
        if ($action === 'run-reverse') json_response(in5_run_reverse($user, $company, $in));
    }
    fail('Not found.', 404, 'route_not_found');
}
