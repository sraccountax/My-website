<?php
declare(strict_types=1);

/**
 * Tegh QA Guardian successor, maintained against the active runtime identity.
 *
 * The service is deliberately read-only with respect to accounting and platform
 * records. It may persist redacted QA evidence in private storage. OpenAI is an
 * optional reviewer of that evidence; it is never the test runner, status
 * authority, or execution engine.
 */

const TEGH_QA_GUARDIAN_VERSION = '5960.1';
const TEGH_QA_GUARDIAN_PROMPT = 'qa-guardian-system-prompt-v5820.md';

function qa_guardian_require_owner(): array
{
    $user = require_user();
    if (platform_role_for_user((string)$user['id']) !== 'platform_owner') {
        fail('Only the Platform Owner can use QA Centre.', 403, 'platform_owner_required');
    }
    return $user;
}

function qa_guardian_enabled(): bool
{
    $configured = config('qa_guardian.enabled');
    return $configured === null ? true : (bool)$configured;
}

function qa_guardian_assert_enabled(): void
{
    if (!qa_guardian_enabled()) {
        fail('QA Centre is disabled in the private server configuration.', 503, 'qa_guardian_disabled', false);
    }
}

function qa_guardian_https(): bool
{
    if (PHP_SAPI === 'cli') return true;
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $forwarded = strtolower(trim((string)(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? '')));
    return in_array($https, ['on', '1'], true) || $forwarded === 'https';
}

function qa_guardian_storage_directory(): string
{
    $directory = private_storage_root() . DIRECTORY_SEPARATOR . 'qa-guardian';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The private QA evidence directory could not be created.');
    }
    @chmod($directory, 0700);
    $runs = $directory . DIRECTORY_SEPARATOR . 'runs';
    if (!is_dir($runs) && !mkdir($runs, 0700, true) && !is_dir($runs)) {
        throw new RuntimeException('The private QA run directory could not be created.');
    }
    @chmod($runs, 0700);
    return $directory;
}

function qa_guardian_atomic_json(string $path, array $value): void
{
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
    $handle=@fopen($temporary,'xb');if($handle===false)throw new RuntimeException('Private QA evidence could not be opened.');
    try{
        if(!flock($handle,LOCK_EX))throw new RuntimeException('Private QA evidence could not be locked.');
        $offset=0;$length=strlen($encoded);while($offset<$length){$written=fwrite($handle,substr($encoded,$offset));if($written===false||$written===0)throw new RuntimeException('Private QA evidence could not be written.');$offset+=$written;}
        if(!fflush($handle))throw new RuntimeException('Private QA evidence could not be flushed.');
        if(!function_exists('fsync')||!fsync($handle))throw new RuntimeException('Private QA evidence could not be synchronized.');
        flock($handle,LOCK_UN);fclose($handle);$handle=null;
    }catch(Throwable $error){if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);}@unlink($temporary);throw $error;}
    @chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Private QA evidence could not be committed.');
    }
    @chmod($path, 0600);
}

function qa_guardian_safe_run_id(mixed $value): string
{
    $id = trim((string)$value);
    if (!preg_match('/^qarun_[a-f0-9]{24,64}$/', $id)) {
        fail('The selected QA run is invalid.', 422, 'qa_run_invalid', false);
    }
    return $id;
}

function qa_guardian_load_index(): array
{
    try {
        $path = qa_guardian_storage_directory() . DIRECTORY_SEPARATOR . 'index.json';
        if (!is_file($path)) return [];
        $decoded = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    } catch (Throwable $error) {
        error_log('Tegh QA Guardian index read skipped request=' . request_id() . ' class=' . $error::class);
        return [];
    }
}

function qa_guardian_load_run(string $runId): array
{
    $path = qa_guardian_storage_directory() . DIRECTORY_SEPARATOR . 'runs' . DIRECTORY_SEPARATOR . $runId . '.json';
    if (!is_file($path)) fail('That QA run is no longer available.', 404, 'qa_run_not_found', false);
    try {
        $decoded = json_decode((string)file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !hash_equals($runId, (string)($decoded['id'] ?? ''))) throw new RuntimeException('QA run identity mismatch.');
        return $decoded;
    } catch (Throwable $error) {
        error_log('Tegh QA Guardian run read failed request=' . request_id() . ' class=' . $error::class);
        fail('The stored QA run could not be verified.', 409, 'qa_run_integrity_failed', false);
    }
}

function qa_guardian_store_run(array $run): void
{
    $directory = qa_guardian_storage_directory();
    $lockPath = $directory . DIRECTORY_SEPARATOR . 'write.lock';
    $lock = fopen($lockPath, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('The QA evidence lock is unavailable.');
    try {
        @chmod($lockPath, 0600);
        qa_guardian_atomic_json($directory . DIRECTORY_SEPARATOR . 'runs' . DIRECTORY_SEPARATOR . $run['id'] . '.json', $run);
        $index = qa_guardian_load_index();
        $summary = [
            'id' => (string)$run['id'],
            'mode' => (string)$run['mode'],
            'status' => (string)$run['status'],
            'createdAt' => (string)$run['createdAt'],
            'completedAt' => (string)$run['completedAt'],
            'total' => (int)$run['summary']['total'],
            'passed' => (int)$run['summary']['passed'],
            'warnings' => (int)$run['summary']['warnings'],
            'failed' => (int)$run['summary']['failed'],
            'blocked' => (int)$run['summary']['blocked'],
            'accountingWrites' => 0,
            'providerAttempts' => (int)$run['providerAttempts'],
        ];
        $index = array_values(array_filter($index, static fn(array $item): bool => (string)($item['id'] ?? '') !== (string)$run['id']));
        array_unshift($index, $summary);
        $maximum = max(10, min(500, (int)(config('qa_guardian.max_retained_runs') ?? 100)));
        $discarded = array_slice($index, $maximum);
        $index = array_slice($index, 0, $maximum);
        qa_guardian_atomic_json($directory . DIRECTORY_SEPARATOR . 'index.json', $index);
        foreach ($discarded as $item) {
            $old = (string)($item['id'] ?? '');
            if (preg_match('/^qarun_[a-f0-9]{24,64}$/', $old)) @unlink($directory . DIRECTORY_SEPARATOR . 'runs' . DIRECTORY_SEPARATOR . $old . '.json');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function qa_guardian_check(string $id, string $category, string $name, string $status, string $summary, string $expected, string $actual, string $severity = 'medium'): array
{
    if (!in_array($status, ['pass', 'warning', 'fail', 'blocked'], true)) $status = 'fail';
    if (!in_array($severity, ['info', 'low', 'medium', 'high', 'critical'], true)) $severity = 'medium';
    return [
        'id' => $id,
        'category' => $category,
        'name' => $name,
        'status' => $status,
        'severity' => $severity,
        'summary' => mb_substr($summary, 0, 500),
        'expected' => mb_substr($expected, 0, 300),
        'actual' => mb_substr($actual, 0, 300),
        'checkedAt' => gmdate('c'),
    ];
}

function qa_guardian_query_count(string $sql, array $bindings = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($bindings);
    return (int)($stmt->fetchColumn() ?: 0);
}

function qa_guardian_control_amounts_match(int $controlCents,int $subledgerCents): bool
{
    return $controlCents===$subledgerCents;
}

function qa_guardian_selected_company(array $user, bool $testOnly = false): ?array
{
    $companyId = trim((string)($_SERVER['HTTP_X_COMPANY_ID'] ?? $_GET['companyId'] ?? ''));
    if ($companyId === '') return null;
    $statusClause = schema_column_exists('company_members', 'status') ? " AND cm.status='active'" : '';
    $stmt = db()->prepare("SELECT c.*,cm.role FROM companies c JOIN company_members cm ON cm.company_id=c.id WHERE c.id=? AND cm.user_id=? AND c.active=1" . $statusClause . ' LIMIT 1');
    $stmt->execute([$companyId, (string)$user['id']]);
    $company = $stmt->fetch();
    if (!$company) fail('QA Centre can inspect company accounting only when you are an active member.', 403, 'qa_company_membership_required', false);
    if ($testOnly) {
        $current = !empty($company['test_mode']) && ($company['test_expires_at'] === null || strtotime((string)$company['test_expires_at'] . ' UTC') > time());
        if (!$current) fail('Full synthetic testing requires an active Test Mode company.', 403, 'isolated_test_company_required', false);
    }
    return $company;
}

function qa_guardian_release_evidence(): array
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'RELEASE-MANIFEST.json';
    if (!is_file($path)) return ['available' => false, 'reason' => 'release_manifest_missing'];
    try {
        $manifest = json_decode((string)file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) throw new RuntimeException('Release manifest is not an object.');
        $verification=is_array($manifest['verification']??null)?$manifest['verification']:[];
        $generatedAt = trim((string)($manifest['generatedAt'] ?? $verification['recordedAt'] ?? ''));
        $generatedTimestamp = $generatedAt === '' ? false : strtotime($generatedAt);
        $maximumAgeHours = max(1, min(8760, (int)(config('qa_guardian.synthetic_evidence_max_age_hours') ?? 168)));
        $ageSeconds = $generatedTimestamp === false ? null : max(0, time() - $generatedTimestamp);
        $executed=is_array($manifest['executedLocalGates']??null)?$manifest['executedLocalGates']:(is_array($verification['gates']??null)?$verification['gates']:[]);$inherited=is_array($manifest['inheritedPreModificationEvidence']??null)?$manifest['inheritedPreModificationEvidence']:[];
        $identityMatches=(string)($manifest['version']??'')===SR_ACCOUNTAX_VERSION&&(int)($manifest['build']??0)===SR_ACCOUNTAX_BUILD&&(int)($manifest['schemaTarget']??$manifest['schema']??0)===SR_ACCOUNTAX_SCHEMA_VERSION;
        return [
            'available' => true,
            'version' => (string)($manifest['version'] ?? ''),
            'build' => (int)($manifest['build'] ?? 0),
            'status' => (string)($manifest['releaseStatus'] ?? ''),
            'sealed' => (bool)($manifest['sealed'] ?? false),
            // Evidence generated against an older codebase is useful history,
            // but cannot certify the modified build. Only current-build gates
            // are eligible for a pass in the fixed-catalogue checks.
            'gates' => $identityMatches?$executed:[],
            'identityMatchesRuntime' => $identityMatches,
            'inheritedPreModificationEvidence' => $inherited,
            'inheritedEvidenceEligible' => false,
            'generatedAt' => $generatedAt,
            'ageHours' => $ageSeconds === null ? null : round($ageSeconds / 3600, 1),
            'fresh' => $identityMatches&&$ageSeconds !== null && $ageSeconds <= $maximumAgeHours * 3600,
            'maximumAgeHours' => $maximumAgeHours,
        ];
    } catch (Throwable $error) {
        return ['available' => false, 'reason' => 'release_manifest_invalid'];
    }
}

function qa_guardian_accounting_checks(?array $company): array
{
    if ($company === null) {
        return [qa_guardian_check('accounting.company_scope', 'accounting', 'Company accounting invariants', 'blocked', 'Choose a company where you are an active member to run company-scoped ledger checks.', 'Explicit company membership', 'No authorized company selected', 'info')];
    }
    $companyId = (string)$company['id'];
    $checks = [];
    try {
        $unbalanced = qa_guardian_query_count("SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id=? GROUP BY je.id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) q", [$companyId]);
        $checks[] = qa_guardian_check('accounting.balanced_journals', 'accounting', 'Balanced journals', $unbalanced === 0 ? 'pass' : 'fail', $unbalanced === 0 ? 'Every journal in the authorized company balances.' : 'One or more journals do not balance.', '0 unbalanced journals', (string)$unbalanced . ' unbalanced journal(s)', $unbalanced === 0 ? 'info' : 'critical');
    } catch (Throwable $error) {
        $checks[] = qa_guardian_check('accounting.balanced_journals', 'accounting', 'Balanced journals', 'fail', 'The ledger balance query could not complete.', 'A completed read-only invariant query', 'Query failed; inspect the protected incident reference', 'critical');
    }
    $relations = [
        ['accounting.cross_company_invoices', 'Invoices and customers', "SELECT COUNT(*) FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.company_id=? AND c.company_id<>i.company_id"],
        ['accounting.cross_company_bills', 'Bills and vendors', "SELECT COUNT(*) FROM bills b JOIN vendors v ON v.id=b.vendor_id WHERE b.company_id=? AND v.company_id<>b.company_id"],
        ['accounting.cross_company_bank', 'Bank rows and accounts', "SELECT COUNT(*) FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id WHERE bt.company_id=? AND ba.company_id<>bt.company_id"],
        ['accounting.cross_company_journals', 'Journal lines and accounts', "SELECT COUNT(*) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id=? AND a.company_id<>je.company_id"],
    ];
    foreach ($relations as [$id, $name, $sql]) {
        try {
            $count = qa_guardian_query_count($sql, [$companyId]);
            $checks[] = qa_guardian_check($id, 'security', $name . ' company isolation', $count === 0 ? 'pass' : 'fail', $count === 0 ? 'No cross-company relationship was found.' : 'A cross-company relationship was detected.', '0 cross-company rows', (string)$count . ' cross-company row(s)', $count === 0 ? 'info' : 'critical');
        } catch (Throwable $error) {
            $checks[] = qa_guardian_check($id, 'security', $name . ' company isolation', 'fail', 'The company-isolation query could not complete.', 'A completed read-only isolation query', 'Query failed; inspect the protected incident reference', 'critical');
        }
    }
    $accrual=(string)($company['accounting_basis']??'accrual')==='accrual';
    try{
        $control=qa_guardian_query_count("SELECT COALESCE(SUM(CASE WHEN je.id IS NOT NULL THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id AND je.status='posted' WHERE a.company_id=? AND a.code='1200'",[$companyId]);
        $sql=$accrual?"SELECT COALESCE(SUM(balance_cents),0) FROM invoices WHERE company_id=? AND status IN ('sent','paid')":"SELECT COALESCE(SUM(balance_cents),0) FROM invoices WHERE company_id=? AND status IN ('sent','paid') AND is_opening_document=1";$subledger=qa_guardian_query_count($sql,[$companyId]);
        if(schema_table_exists('party_opening_balances'))$subledger+=qa_guardian_query_count("SELECT COALESCE(SUM(amount_cents),0) FROM party_opening_balances WHERE company_id=? AND party_type='customer'",[$companyId]);
        $match=qa_guardian_control_amounts_match($control,$subledger);$checks[]=qa_guardian_check('accounting.ar_control_subledger','accounting','Accounts Receivable control to customer subledger',$match?'pass':'fail',$match?'Accounts Receivable agrees to customer balances.':'Accounts Receivable does not agree to customer balances.','AR control balance equals applicable customer balances','control='.$control.', customerSubledger='.$subledger,$match?'info':'critical');
    }catch(Throwable){$checks[]=qa_guardian_check('accounting.ar_control_subledger','accounting','Accounts Receivable control to customer subledger','fail','The AR control assertion could not complete.','Completed read-only control-to-subledger assertion','Query failed','critical');}
    try{
        $control=qa_guardian_query_count("SELECT COALESCE(SUM(CASE WHEN je.id IS NOT NULL THEN jl.credit_cents-jl.debit_cents ELSE 0 END),0) FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id AND je.status='posted' WHERE a.company_id=? AND a.code='2050'",[$companyId]);
        $sql=$accrual?"SELECT COALESCE(SUM(balance_cents),0) FROM bills WHERE company_id=? AND status IN ('open','paid')":"SELECT COALESCE(SUM(balance_cents),0) FROM bills WHERE company_id=? AND status IN ('open','paid') AND is_opening_document=1";$subledger=qa_guardian_query_count($sql,[$companyId]);
        if(schema_table_exists('party_opening_balances'))$subledger+=qa_guardian_query_count("SELECT COALESCE(SUM(amount_cents),0) FROM party_opening_balances WHERE company_id=? AND party_type='vendor'",[$companyId]);
        $match=qa_guardian_control_amounts_match($control,$subledger);$checks[]=qa_guardian_check('accounting.ap_control_subledger','accounting','Accounts Payable control to vendor subledger',$match?'pass':'fail',$match?'Accounts Payable agrees to vendor balances.':'Accounts Payable does not agree to vendor balances.','AP control balance equals applicable vendor balances','control='.$control.', vendorSubledger='.$subledger,$match?'info':'critical');
    }catch(Throwable){$checks[]=qa_guardian_check('accounting.ap_control_subledger','accounting','Accounts Payable control to vendor subledger','fail','The AP control assertion could not complete.','Completed read-only control-to-subledger assertion','Query failed','critical');}
    try{
        $differences=qa_guardian_query_count("SELECT COUNT(*) FROM reconciliations WHERE company_id=? AND status='complete' AND difference_cents<>0",[$companyId]);$match=$differences===0;
        $checks[]=qa_guardian_check('accounting.bank_reconciliation_zero','accounting','Completed bank reconciliation difference',$match?'pass':'fail',$match?'Every completed bank reconciliation has a zero difference.':'A completed bank reconciliation has a non-zero difference.','0 completed reconciliations with a difference',(string)$differences.' non-zero completed reconciliation(s)',$match?'info':'critical');
    }catch(Throwable){$checks[]=qa_guardian_check('accounting.bank_reconciliation_zero','accounting','Completed bank reconciliation difference','fail','The bank reconciliation assertion could not complete.','Completed read-only reconciliation assertion','Query failed','critical');}
    try{
        $settings=null;if(schema_table_exists('payroll_settings')){$q=db()->prepare('SELECT * FROM payroll_settings WHERE company_id=? AND active=1 LIMIT 1');$q->execute([$companyId]);$settings=$q->fetch();}
        if(!$settings)$checks[]=qa_guardian_check('accounting.payroll_control_subledger','accounting','Payroll controls to payroll liabilities','pass','Payroll is not configured for this company; no payroll control assertion is applicable.','Configured payroll controls agree to payroll liabilities','Not applicable: payroll not configured','info');
        else{
            $ids=array_values(array_unique(array_filter(array_map('strval',[$settings['net_pay_account_id'],$settings['tax_payable_account_id'],$settings['cpp_payable_account_id'],$settings['ei_payable_account_id'],$settings['other_payable_account_id']]))));$marks=implode(',',array_fill(0,count($ids),'?'));
            $actual=qa_guardian_query_count("SELECT COALESCE(SUM(jl.credit_cents-jl.debit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.status='posted' AND jl.account_id IN ($marks)",array_merge([$companyId],$ids));
            $q=db()->prepare("SELECT COALESCE(SUM(income_tax_cents+employee_cpp_cents+employee_cpp2_cents+employer_cpp_cents+employer_cpp2_cents+employee_ei_cents+employer_ei_cents+other_deductions_cents+CASE WHEN status='posted' THEN net_pay_cents ELSE 0 END),0) FROM payroll_runs WHERE company_id=? AND status IN ('posted','paid') AND gl_status='posted'");$q->execute([$companyId]);$expected=(int)$q->fetchColumn();
            if(schema_table_exists('payroll_remittances'))$expected-=qa_guardian_query_count("SELECT COALESCE(SUM(total_cents),0) FROM payroll_remittances WHERE company_id=? AND status='posted'",[$companyId]);
            $match=qa_guardian_control_amounts_match($actual,$expected);$checks[]=qa_guardian_check('accounting.payroll_control_subledger','accounting','Payroll controls to payroll liabilities',$match?'pass':'fail',$match?'Payroll control accounts agree to posted payroll liabilities.':'Payroll control accounts do not agree to posted payroll liabilities.','Payroll control balance equals outstanding posted payroll liabilities','control='.$actual.', payrollLiability='.$expected,$match?'info':'critical');
        }
    }catch(Throwable){$checks[]=qa_guardian_check('accounting.payroll_control_subledger','accounting','Payroll controls to payroll liabilities','fail','The payroll control assertion could not complete.','Completed read-only control-to-subledger assertion','Query failed','critical');}
    return $checks;
}

function qa_guardian_catalogue_checks(array $evidence): array
{
    $gates = (array)($evidence['gates'] ?? []);
    $definitions = [
        ['catalogue.commands', 'Fixed 42-command certification matrix', 'siteFixedCatalogue', 'commands', 42, ['failures' => 0, 'wrongCommits' => 0]],
        ['catalogue.stress_commands', 'Extreme 5 × 60 sequential stress commands', 'siteExtremeStress', 'commands', 300, ['underlyingActions' => 210, 'expectedSafeStops' => 145, 'safeStopAccountingChanges' => 0, 'sequenceCount' => 6, 'sequencePassesEach' => 50, 'journalCount' => 50, 'debitCreditEqual' => 1, 'wrongCommits' => 0, 'unauthorizedWrites' => 0, 'duplicateOperations' => 0, 'crossCompanyRows' => 0]],
        ['catalogue.quality', 'Core quality gates', 'siteCoreQuality', 'checks', 16, ['failures' => 0]],
    ];
    $checks = [];
    foreach ($definitions as [$id, $name, $gateName, $countKey, $expectedCount, $requirements]) {
        $gate = is_array($gates[$gateName] ?? null) ? $gates[$gateName] : [];
        $missing = $gate === [];
        $clean = !$missing && !empty($gate['passed']) && (int)($gate[$countKey] ?? -1) === $expectedCount;
        foreach ($requirements as $key => $expected) $clean = $clean && (int)($gate[$key] ?? -1) === $expected;
        $actualParts = $missing ? ['evidence missing'] : [$countKey . '=' . (int)($gate[$countKey] ?? -1), 'passed=' . (!empty($gate['passed']) ? 'true' : 'false')];
        foreach ($requirements as $key => $expected) $actualParts[] = $key . '=' . (int)($gate[$key] ?? -1);
        if(!$clean&&$id==='catalogue.commands'){$expectedIds=array_values(array_unique(array_map('strval',(array)($gate['expectedActionIds']??[]))));$actualIds=array_values(array_unique(array_map('strval',(array)($gate['actualActionIds']??[]))));sort($expectedIds,SORT_STRING);sort($actualIds,SORT_STRING);if($expectedIds||$actualIds){$actualParts[]='addedActionIds='.implode('|',array_values(array_diff($actualIds,$expectedIds)));$actualParts[]='removedActionIds='.implode('|',array_values(array_diff($expectedIds,$actualIds)));}else $actualParts[]='actionIdDelta=unavailable_in_evidence';}
        $checks[] = qa_guardian_check($id, 'agent_tests', $name, $clean ? 'pass' : 'fail', $clean ? 'The exact packaged fixed-catalogue evidence is clean.' : 'The exact packaged fixed-catalogue evidence is missing or not clean.', 'Published fixed metrics with zero failures and zero unsafe writes', implode(', ', $actualParts), $clean ? 'info' : 'critical');
    }
    return $checks;
}

function qa_guardian_run(array $user, string $mode,array $executionContext=[]): array
{
    $started = microtime(true);
    $synthetic = $mode === 'synthetic_catalogue';
    $company = qa_guardian_selected_company($user, $synthetic);
    $checks = [];

    $runtimeEvidence=qa_guardian_release_evidence();$runtimeIdentityOk=!empty($runtimeEvidence['identityMatchesRuntime']);
    $runtimeIdentity='Tegh ' . SR_ACCOUNTAX_VERSION . ' · Build ' . SR_ACCOUNTAX_BUILD . ' · Schema ' . SR_ACCOUNTAX_SCHEMA_VERSION;
    $checks[] = qa_guardian_check('runtime.identity', 'deployment', 'Runtime build identity', $runtimeIdentityOk ? 'pass' : 'fail', $runtimeIdentityOk ? 'The PHP runtime identity matches the packaged release manifest.' : 'The PHP runtime identity does not match the packaged release manifest.', $runtimeIdentity, $runtimeIdentityOk ? $runtimeIdentity : 'Release manifest mismatch', $runtimeIdentityOk ? 'info' : 'critical');
    $checks[] = qa_guardian_check('runtime.https', 'security', 'HTTPS request boundary', qa_guardian_https() ? 'pass' : 'fail', qa_guardian_https() ? 'The QA request arrived through HTTPS.' : 'The QA request did not prove HTTPS.', 'HTTPS', qa_guardian_https() ? 'HTTPS' : 'Not HTTPS', qa_guardian_https() ? 'info' : 'critical');

    try {
        $storage = qa_guardian_storage_directory();
        $checks[] = qa_guardian_check('runtime.private_storage', 'security', 'Private QA evidence storage', 'pass', 'QA evidence storage is available outside the public web root.', 'Writable private directory', 'Ready; path withheld', 'info');
    } catch (Throwable $error) {
        $checks[] = qa_guardian_check('runtime.private_storage', 'security', 'Private QA evidence storage', 'fail', 'Private QA evidence storage is unavailable.', 'Writable private directory', 'Unavailable; path withheld', 'critical');
    }

    try {
        db()->query('SELECT 1')->fetchColumn();
        $checks[] = qa_guardian_check('database.connection', 'database', 'Database connection', 'pass', 'The database answered a read-only probe.', 'Successful read-only query', 'Connected', 'info');
    } catch (Throwable $error) {
        $checks[] = qa_guardian_check('database.connection', 'database', 'Database connection', 'fail', 'The database did not answer the read-only probe.', 'Successful read-only query', 'Connection failed', 'critical');
    }

    try {
        $schema = current_database_schema_version();
        $schemaMatches=$schema===SR_ACCOUNTAX_SCHEMA_VERSION;
        $checks[] = qa_guardian_check('database.schema', 'database', 'Deployed schema version', $schemaMatches ? 'pass' : 'fail', $schemaMatches ? 'The deployed schema marker matches the active package target.' : 'The deployed schema marker does not match the package target.', 'Schema ' . SR_ACCOUNTAX_SCHEMA_VERSION, 'Schema ' . $schema, $schemaMatches ? 'info' : 'critical');
        $coreReady = core_accounting_schema_ready();
        $checks[] = qa_guardian_check('database.core_ready', 'database', 'Core accounting schema', $coreReady ? 'pass' : 'fail', $coreReady ? 'Required core accounting structures are present.' : 'One or more required accounting structures are missing.', 'Core schema ready', $coreReady ? 'Ready' : 'Not ready', $coreReady ? 'info' : 'critical');
        $vendorReady = function_exists('tegh_schema41_status') && !empty(tegh_schema41_status()['ready']);
        $checks[] = qa_guardian_check('database.schema41_ready', 'database', 'Schema 41 structural readiness', $vendorReady ? 'pass' : 'fail', $vendorReady ? 'Schema 41 structural checks passed.' : 'Schema 41 structural checks are incomplete.', 'Schema 41 structures ready', $vendorReady ? 'Ready' : 'Not ready', $vendorReady ? 'info' : 'high');
        $transactionControlsReady = function_exists('tegh_schema42_status') && !empty(tegh_schema42_status()['ready']);
        $checks[] = qa_guardian_check('database.schema42_ready', 'database', 'Schema 42 transaction controls', $transactionControlsReady ? 'pass' : 'fail', $transactionControlsReady ? 'Schema 42 transaction-control checks passed.' : 'Schema 42 transaction-control checks are incomplete.', 'Schema 42 structures ready', $transactionControlsReady ? 'Ready' : 'Not ready', $transactionControlsReady ? 'info' : 'critical');
        $aiReady = function_exists('ai_additive_schema_status') && !empty(ai_additive_schema_status()['ready']);
        $checks[] = qa_guardian_check('database.ai_storage', 'database', 'AI telemetry and budget storage', $aiReady ? 'pass' : 'warning', $aiReady ? 'AI-only additive storage is ready.' : 'AI-only additive storage needs maintenance; accounting remains separate.', 'AI storage ready', $aiReady ? 'Ready' : 'Maintenance required', $aiReady ? 'info' : 'medium');
    } catch (Throwable $error) {
        $checks[] = qa_guardian_check('database.readiness', 'database', 'Database readiness checks', 'fail', 'Database readiness could not be verified.', 'Completed schema checks', 'Verification failed', 'critical');
    }

    try {
        $hours = max(1, min(168, (int)(config('qa_guardian.incident_lookback_hours') ?? 24)));
        $recent = schema_table_exists('platform_incident_log') ? qa_guardian_query_count("SELECT COUNT(*) FROM platform_incident_log WHERE severity IN ('error','critical') AND occurred_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL " . $hours . ' HOUR)') : -1;
        $checks[] = qa_guardian_check('operations.recent_errors', 'live_errors', 'Recent production errors', $recent === 0 ? 'pass' : ($recent > 0 ? 'warning' : 'fail'), $recent === 0 ? 'No error or critical incident was recorded in the review window.' : ($recent > 0 ? 'Recent errors require Platform Owner review.' : 'The protected incident log is unavailable.'), '0 unresolved error/critical events in the review window', $recent < 0 ? 'Incident storage unavailable' : (string)$recent . ' recent event(s)', $recent === 0 ? 'info' : ($recent > 0 ? 'high' : 'critical'));
        $workers = schema_table_exists('platform_incident_log') ? qa_guardian_query_count("SELECT COUNT(*) FROM platform_incident_log WHERE severity IN ('error','critical') AND occurred_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL " . $hours . " HOUR) AND (source LIKE '%scheduler%' OR source LIKE '%worker%' OR source LIKE '%provider%' OR error_code LIKE '%provider%')") : -1;
        $checks[] = qa_guardian_check('operations.worker_errors', 'live_errors', 'Recent worker and provider errors', $workers === 0 ? 'pass' : ($workers > 0 ? 'warning' : 'fail'), $workers === 0 ? 'No recent worker or provider error was recorded.' : ($workers > 0 ? 'Recent worker or provider errors require review.' : 'Worker-error evidence is unavailable.'), '0 recent worker/provider errors', $workers < 0 ? 'Evidence unavailable' : (string)$workers . ' recent event(s)', $workers === 0 ? 'info' : 'high');
    } catch (Throwable $error) {
        $checks[] = qa_guardian_check('operations.incidents', 'live_errors', 'Protected incident evidence', 'fail', 'Recent incident evidence could not be queried.', 'Completed read-only incident query', 'Query failed', 'high');
    }

    $providerConfigured = function_exists('tegh_ai_provider_configured') && tegh_ai_provider_configured();
    $checks[] = qa_guardian_check('openai.server_configuration', 'agent_tests', 'OpenAI server configuration', $providerConfigured ? 'pass' : 'warning', $providerConfigured ? 'A server-side key and PHP cURL are available. The key is never returned.' : 'The optional model reviewer is not ready; deterministic QA remains available.', 'Server key plus PHP cURL for optional review', $providerConfigured ? 'Configured; key withheld' : 'Not ready; key withheld', $providerConfigured ? 'info' : 'medium');

    try {
        $registry = function_exists('tegh_action_registry') ? tegh_action_registry() : [];
        $ids = array_keys($registry);
        $duplicateCount = count($ids) - count(array_unique($ids));
        $unsafe = 0;
        foreach ($registry as $action) {
            if (!is_array($action) || trim((string)($action['required_permission'] ?? '')) === '' || trim((string)($action['action_type'] ?? '')) === '') $unsafe++;
        }
        $clean = count($registry) > 0 && $duplicateCount === 0 && $unsafe === 0;
        $checks[] = qa_guardian_check('agent.registry', 'agent_tests', 'Agent action registry', $clean ? 'pass' : 'fail', $clean ? 'Registered actions have unique IDs and explicit permission/type metadata.' : 'The action registry is missing or contains unsafe metadata.', 'Non-empty registry; 0 duplicate IDs; 0 incomplete controls', count($registry) . ' action(s), ' . $duplicateCount . ' duplicate(s), ' . $unsafe . ' incomplete', $clean ? 'info' : 'critical');
    } catch (Throwable $error) {
        $checks[] = qa_guardian_check('agent.registry', 'agent_tests', 'Agent action registry', 'fail', 'The action registry could not be inspected.', 'Completed deterministic registry inspection', 'Inspection failed', 'critical');
    }

    $release = qa_guardian_release_evidence();
    if (empty($release['available'])) {
        $checks[] = qa_guardian_check('release.evidence', 'release_history', 'Packaged release evidence', 'fail', 'The package release evidence is missing or invalid.', 'Valid Build ' . SR_ACCOUNTAX_BUILD . ' release manifest', (string)($release['reason'] ?? 'unavailable'), 'critical');
    } else {
        $sameBuild = (int)$release['build'] === SR_ACCOUNTAX_BUILD && (string)$release['version'] === SR_ACCOUNTAX_VERSION;
        $checks[] = qa_guardian_check('release.evidence', 'release_history', 'Packaged release evidence', $sameBuild ? 'pass' : 'fail', $sameBuild ? 'The release evidence belongs to the running build.' : 'The release evidence belongs to a different build.', 'Tegh ' . SR_ACCOUNTAX_VERSION . ' · Build ' . SR_ACCOUNTAX_BUILD, 'Tegh ' . $release['version'] . ' · Build ' . $release['build'], $sameBuild ? 'info' : 'critical');
        $fresh = !empty($release['fresh']);
        $checks[] = qa_guardian_check('release.freshness', 'release_history', 'Synthetic evidence freshness', $fresh ? 'pass' : 'warning', $fresh ? 'The packaged synthetic evidence is within the configured review window.' : 'The packaged synthetic evidence is stale or has no valid timestamp.', 'No older than ' . (int)$release['maximumAgeHours'] . ' hours', $release['ageHours'] === null ? 'Timestamp missing or invalid' : (string)$release['ageHours'] . ' hours old', $fresh ? 'info' : 'high');
        $sealed = !empty($release['sealed']);
        $checks[] = qa_guardian_check('release.seal', 'release_history', 'Release approval state', $sealed ? 'pass' : 'warning', $sealed ? 'The release evidence is sealed.' : 'The candidate remains HOLD / NOT SEALED until retained-host validation is complete.', 'Sealed only after retained-host gates pass', $sealed ? 'Sealed' : (string)$release['status'], $sealed ? 'info' : 'high');
        if ($synthetic) $checks = array_merge($checks, qa_guardian_catalogue_checks($release));
    }

    if ($synthetic) {
        $checks[] = qa_guardian_check('synthetic.test_company', 'security', 'Isolated Test Mode company', $company !== null ? 'pass' : 'fail', $company !== null ? 'The run is bound to an active Test Mode company where the owner is an explicit member.' : 'No safe Test Mode company is selected.', 'Active Test Mode company plus explicit membership', $company !== null ? 'Verified; identity withheld' : 'Not verified', $company !== null ? 'info' : 'critical');
    }
    $checks = array_merge($checks, qa_guardian_accounting_checks($company));

    $counts = ['passed' => 0, 'warnings' => 0, 'failed' => 0, 'blocked' => 0];
    foreach ($checks as $check) {
        if ($check['status'] === 'pass') $counts['passed']++;
        elseif ($check['status'] === 'warning') $counts['warnings']++;
        elseif ($check['status'] === 'blocked') $counts['blocked']++;
        else $counts['failed']++;
    }
    $status = $counts['failed'] > 0 ? 'failed' : (($counts['warnings'] > 0 || $counts['blocked'] > 0) ? 'review_required' : 'passed');
    $run = [
        'id' => 'qarun_' . bin2hex(random_bytes(16)),
        'suiteVersion' => TEGH_QA_GUARDIAN_VERSION,
        'mode' => $mode,
        'status' => $status,
        'scope' => $company === null ? 'platform_only' : ($synthetic ? 'isolated_test_company' : 'authorized_company_read_only'),
        'createdAt' => gmdate('c'),
        'completedAt' => gmdate('c'),
        'durationMs' => (int)round((microtime(true) - $started) * 1000),
        'summary' => ['total' => count($checks)] + $counts,
        'accountingWrites' => 0,
        'providerAttempts' => 0,
        'deterministicAuthority' => true,
        'fixedCatalogueOnly' => $synthetic,
        'checks' => $checks,
        'modelReview' => null,
    ];
    if(isset($executionContext['schedulerSourceHash']))$run['schedulerSourceIpHash']=(string)$executionContext['schedulerSourceHash'];
    qa_guardian_store_run($run);
    return $run;
}

function qa_guardian_prompt_text(): string
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'knowledge' . DIRECTORY_SEPARATOR . TEGH_QA_GUARDIAN_PROMPT;
    if (!is_file($path)) throw new RuntimeException('The QA reviewer prompt is missing.');
    $prompt = trim((string)file_get_contents($path));
    if ($prompt === '') throw new RuntimeException('The QA reviewer prompt is empty.');
    return $prompt;
}

function qa_guardian_model_evidence(array $run): array
{
    $checks = [];
    foreach ((array)($run['checks'] ?? []) as $check) {
        if (!is_array($check)) continue;
        $checks[] = [
            'id' => mb_substr((string)($check['id'] ?? ''), 0, 120),
            'category' => mb_substr((string)($check['category'] ?? ''), 0, 80),
            'name' => mb_substr((string)($check['name'] ?? ''), 0, 160),
            'status' => mb_substr((string)($check['status'] ?? ''), 0, 20),
            'severity' => mb_substr((string)($check['severity'] ?? ''), 0, 20),
            'summary' => mb_substr((string)($check['summary'] ?? ''), 0, 400),
            'expected' => mb_substr((string)($check['expected'] ?? ''), 0, 240),
            'actual' => mb_substr((string)($check['actual'] ?? ''), 0, 240),
        ];
    }
    return [
        'runIdHash' => hash('sha256', (string)$run['id']),
        'suiteVersion' => (string)$run['suiteVersion'],
        'mode' => (string)$run['mode'],
        'deterministicStatus' => (string)$run['status'],
        'summary' => (array)$run['summary'],
        'accountingWrites' => 0,
        'fixedCatalogueOnly' => (bool)($run['fixedCatalogueOnly'] ?? false),
        'checks' => $checks,
    ];
}

function qa_guardian_review_run(array $user, array $company, array $run): array
{
    if (!(bool)(config('qa_guardian.model_review_enabled') ?? true)) {
        fail('The optional OpenAI QA reviewer is disabled in private configuration.', 503, 'qa_model_review_disabled', false);
    }
    if (empty($company['test_mode']) || ($company['test_expires_at'] !== null && strtotime((string)$company['test_expires_at'] . ' UTC') <= time())) {
        fail('OpenAI QA review is restricted to an active Test Mode company.', 403, 'isolated_test_company_required', false);
    }
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'summary' => ['type' => 'string', 'maxLength' => 1200],
            'releaseDecision' => ['type' => 'string', 'enum' => ['pass_with_observations', 'review_required', 'block']],
            'findings' => ['type' => 'array', 'maxItems' => 20, 'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'id' => ['type' => 'string', 'maxLength' => 80],
                    'severity' => ['type' => 'string', 'enum' => ['info', 'low', 'medium', 'high', 'critical']],
                    'area' => ['type' => 'string', 'maxLength' => 120],
                    'title' => ['type' => 'string', 'maxLength' => 180],
                    'evidence' => ['type' => 'string', 'maxLength' => 500],
                    'likelyCause' => ['type' => 'string', 'maxLength' => 500],
                    'recommendation' => ['type' => 'string', 'maxLength' => 700],
                    'regressionTest' => ['type' => 'string', 'maxLength' => 700],
                    'requiresCodeChange' => ['type' => 'boolean'],
                    'confidenceBps' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
                ],
                'required' => ['id', 'severity', 'area', 'title', 'evidence', 'likelyCause', 'recommendation', 'regressionTest', 'requiresCodeChange', 'confidenceBps'],
            ]],
            'unknowns' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 400]],
            'accountingSafety' => ['type' => 'string', 'maxLength' => 600],
        ],
        'required' => ['summary', 'releaseDecision', 'findings', 'unknowns', 'accountingSafety'],
    ];
    $model = trim((string)(config('qa_guardian.model') ?? config('openai.interpretation_model') ?? config('openai.model') ?? '')) ?: 'gpt-5.6-luna';
    $body = [
        'model' => $model,
        'store' => false,
        'max_output_tokens' => 2400,
        'reasoning' => ['effort' => (string)(config('qa_guardian.reasoning_effort') ?? 'low')],
        'safety_identifier' => function_exists('tegh_human_safety_identifier') ? tegh_human_safety_identifier($user,$company,(string)$run['id']) : 'tegh_qa_' . substr(secret_hash((string)$user['id'] . '|' . (string)$company['id'] . '|' . (string)$run['id']), 0, 48),
        'input' => [
            ['role' => 'developer', 'content' => [['type' => 'input_text', 'text' => qa_guardian_prompt_text()]]],
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => json_encode(['evidence' => qa_guardian_model_evidence($run)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]]],
        ],
        'text' => ['format' => ['type' => 'json_schema', 'name' => 'tegh_qa_guardian_review_v5820', 'strict' => true, 'schema' => $schema]],
    ];
    $provider = tegh_ai_provider_request($user, $company, $body, ['purpose' => 'qa_guardian_review', 'maxAttempts' => 1, 'idempotent' => true]);
    if (empty($provider['ok'])) {
        error_log('Tegh QA Guardian model review unavailable request=' . request_id() . ' providerError=' . mb_substr((string)($provider['error'] ?? 'unavailable'), 0, 80));
        fail('OpenAI could not review this run. The deterministic QA result is unchanged.', 503, 'qa_model_review_unavailable', false);
    }
    try {
        $review = json_decode((string)$provider['text'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($review)) throw new RuntimeException('Reviewer output is not an object.');
    } catch (Throwable $error) {
        tegh_ai_provider_discard($user, $company, 'qa_guardian_review', (string)$provider['requestId'], 'invalid_qa_guardian_review', $error);
        fail('OpenAI returned an invalid QA review. The deterministic QA result is unchanged.', 503, 'qa_model_review_invalid', false);
    }
    return [
        'source' => 'openai_annotation',
        'model' => (string)$provider['model'],
        'requestReference' => (string)$provider['requestId'],
        'reviewedAt' => gmdate('c'),
        'providerAttempts' => (int)$provider['attempts'],
        'deterministicStatusUnchanged' => true,
        'review' => $review,
    ];
}

function qa_guardian_recent_incidents(): array
{
    if (!schema_table_exists('platform_incident_log')) return [];
    $stmt = db()->query("SELECT occurred_at,severity,source,route,http_status,error_code,public_message,request_id FROM platform_incident_log WHERE severity IN ('warning','error','critical') ORDER BY occurred_at DESC LIMIT 20");
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $clean = static fn(string $value, int $limit): string => function_exists('system_incident_redact_text') ? mb_substr(system_incident_redact_text($value), 0, $limit) : mb_substr($value, 0, $limit);
        $items[] = [
            'occurredAt' => (string)$row['occurred_at'],
            'severity' => (string)$row['severity'],
            'source' => $clean((string)$row['source'], 80),
            'route' => $clean((string)$row['route'], 180),
            'httpStatus' => $row['http_status'] === null ? null : (int)$row['http_status'],
            'errorCode' => $clean((string)$row['error_code'], 120),
            'publicMessage' => $clean((string)$row['public_message'], 500),
            'requestReference' => $clean((string)$row['request_id'], 80),
        ];
    }
    return $items;
}

function handle_qa_guardian(string $subroute = ''): never
{
    qa_guardian_assert_enabled();
    $user = qa_guardian_require_owner();
    if ($subroute !== '') fail('QA Centre route not found.', 404, 'qa_guardian_route_not_found', false);
    require_method('GET', 'POST');
    if (request_method() === 'GET') {
        $storageReady = true;
        try { qa_guardian_storage_directory(); } catch (Throwable) { $storageReady = false; }
        $selectedCompany = qa_guardian_selected_company($user, false);
        $testModeReady = $selectedCompany !== null
            && !empty($selectedCompany['test_mode'])
            && ($selectedCompany['test_expires_at'] === null || strtotime((string)$selectedCompany['test_expires_at'] . ' UTC') > time());
        $providerReadiness = $selectedCompany !== null && function_exists('tegh_ai_provider_readiness')
            ? tegh_ai_provider_readiness($selectedCompany)
            : ['readyForRequest' => false, 'blockers' => ['select_active_test_company'], 'nextStep' => 'Select an active Test Mode company where you are a member.'];
        $readyForReview = $testModeReady && !empty($providerReadiness['readyForRequest']);
        $runs = qa_guardian_load_index();
        $latestRun = null;
        if ($storageReady && !empty($runs[0]['id'])) {
            try { $latestRun = qa_guardian_load_run((string)$runs[0]['id']); } catch (Throwable) { $latestRun = null; }
        }
        json_response([
            'ok' => true,
            'suiteVersion' => TEGH_QA_GUARDIAN_VERSION,
            'enabled' => true,
            'storageReady' => $storageReady,
            'readOnlyProductionChecks' => true,
            'syntheticRequiresTestCompany' => true,
            'modelReview' => [
                'optional' => true,
                'configured' => tegh_ai_provider_configured(),
                'readyForReview' => $readyForReview,
                'testModeReady' => $testModeReady,
                'model' => trim((string)(config('qa_guardian.model') ?? config('openai.interpretation_model') ?? '')) ?: 'gpt-5.6-luna',
                'blockers' => $readyForReview ? [] : array_values(array_unique(array_merge($testModeReady ? [] : ['active_test_company_required'], (array)($providerReadiness['blockers'] ?? [])))),
                'nextStep' => $readyForReview
                    ? 'Ready for an optional evidence review.'
                    : (!$testModeReady ? 'Select an active Test Mode company where you are a member.' : (string)($providerReadiness['nextStep'] ?? 'Review the private OpenAI settings.')),
                'deterministicAuthority' => false,
                'apiKeyExposed' => false,
            ],
            'runs' => $runs,
            'latestRun' => $latestRun,
            'recentIncidents' => qa_guardian_recent_incidents(),
            'accountingWrites' => 0,
            'providerAttempts' => 0,
        ]);
    }
    require_csrf();
    $input = request_json();
    $action = (string)($input['action'] ?? '');
    if ($action === 'run_safe_scan') {
        $run = qa_guardian_run($user, 'safe_scan');
        json_response(['ok' => $run['status'] !== 'failed', 'run' => $run, 'accountingWrites' => 0, 'providerAttempts' => 0]);
    }
    if ($action === 'run_synthetic_catalogue') {
        $run = qa_guardian_run($user, 'synthetic_catalogue');
        json_response(['ok' => $run['status'] === 'passed', 'run' => $run, 'accountingWrites' => 0, 'providerAttempts' => 0]);
    }
    if ($action === 'review_run') {
        $runId = qa_guardian_safe_run_id($input['runId'] ?? '');
        $run = qa_guardian_load_run($runId);
        $company = qa_guardian_selected_company($user, true);
        if ($company === null) fail('Choose an active Test Mode company before asking OpenAI to review QA evidence.', 409, 'isolated_test_company_required', false);
        $annotation = qa_guardian_review_run($user, $company, $run);
        $run['modelReview'] = $annotation;
        $run['providerAttempts'] = (int)$annotation['providerAttempts'];
        qa_guardian_store_run($run);
        json_response(['ok' => true, 'run' => $run, 'modelReview' => $annotation, 'deterministicStatus' => $run['status'], 'accountingWrites' => 0, 'providerAttempts' => (int)$annotation['providerAttempts']]);
    }
    fail('Choose a valid QA Centre action.', 422, 'qa_guardian_action_invalid', false);
}

function qa_guardian_scheduler_secret(): string
{
    $secret = trim((string)(config('qa_guardian.scheduler_secret') ?? ''));
    if (strlen($secret) < 40 || str_contains($secret, 'REPLACE_WITH')) {
        fail('QA Guardian scheduling is not configured.', 503, 'qa_guardian_scheduler_not_configured', false);
    }
    return $secret;
}

function qa_guardian_scheduler_source_hash(): string
{
    return function_exists('client_ip_hash')?client_ip_hash():hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'));
}

function qa_guardian_scheduler_admit(string $sourceHash): void
{
    $directory=qa_guardian_storage_directory();$lock=fopen($directory.DIRECTORY_SEPARATOR.'scheduler-rate.lock','c+');if($lock===false||!flock($lock,LOCK_EX))fail('QA Guardian scheduler admission is unavailable.',503,'qa_guardian_scheduler_admission_unavailable',false);
    $blocked=false;
    try{
        $path=$directory.DIRECTORY_SEPARATOR.'scheduler-rate.json';$state=[];if(is_file($path)){try{$decoded=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);if(is_array($decoded))$state=$decoded;}catch(Throwable){$state=[];}}
        $now=time();$window=max(30,min(3600,(int)(config('qa_guardian.scheduler_rate_limit_seconds')??60)));$last=(int)($state[$sourceHash]??0);$blocked=$last>0&&$last>$now-$window;
        if(!$blocked){$state[$sourceHash]=$now;foreach($state as $hash=>$timestamp)if((int)$timestamp<$now-86400)unset($state[$hash]);qa_guardian_atomic_json($path,$state);}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    if($blocked)fail('QA Guardian scheduler requests are limited to one per source per minute.',429,'qa_guardian_scheduler_rate_limited',false);
}

function handle_qa_guardian_scheduler(): never
{
    qa_guardian_assert_enabled();
    require_method('POST');
    if (!qa_guardian_https()) fail('QA Guardian scheduling requires HTTPS.', 403, 'qa_guardian_scheduler_https_required', false);
    $sourceHash=qa_guardian_scheduler_source_hash();qa_guardian_scheduler_admit($sourceHash);
    $provided = trim((string)($_SERVER['HTTP_X_TEGH_QA_SECRET'] ?? ''));
    $expected = qa_guardian_scheduler_secret();
    if ($provided === '' || !hash_equals($expected, $provided)) fail('QA Guardian scheduler authorization failed.', 403, 'qa_guardian_scheduler_unauthorized', false);
    if (!schema_column_exists('users', 'platform_role')) fail('Platform Owner storage is unavailable.', 503, 'platform_owner_storage_required', false);
    $stmt = db()->query("SELECT id,email,display_name FROM users WHERE platform_role='platform_owner' AND active=1 ORDER BY created_at LIMIT 1");
    $user = $stmt->fetch();
    if (!$user) fail('No active Platform Owner is available for the scheduled QA record.', 503, 'platform_owner_unavailable', false);
    // Scheduled execution is platform-only and read-only. It never selects a
    // company, calls OpenAI, or executes the synthetic accounting catalogue.
    unset($_SERVER['HTTP_X_COMPANY_ID']);
    $run = qa_guardian_run($user, 'safe_scan',['schedulerSourceHash'=>$sourceHash]);
    json_response(['ok' => $run['status'] !== 'failed', 'runId' => $run['id'], 'status' => $run['status'], 'summary' => $run['summary'], 'accountingWrites' => 0, 'providerAttempts' => 0], $run['status'] === 'failed' ? 409 : 200);
}
