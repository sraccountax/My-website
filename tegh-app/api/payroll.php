<?php
declare(strict_types=1);

function payroll_nonnegative_cents(mixed $value, string $label): int
{
    $amount = safe_cents($value, $label, true);
    if ($amount < 0) {
        fail($label . ' cannot be negative.');
    }
    return $amount;
}

function payroll_safe_hours_milli(mixed $value, string $label): int
{
    $text = trim((string)$value);
    if (!preg_match('/\A(?:0|[1-9]\d{0,2})(?:\.(\d{1,3}))?\z/', $text, $match)) {
        fail($label . ' must be a non-negative number with no more than three decimal places.');
    }
    [$whole] = explode('.', $text, 2);
    $fraction = str_pad((string)($match[1] ?? ''), 3, '0');
    $milli = ((int)$whole * 1000) + (int)$fraction;
    if ($milli > 500_000) {
        fail($label . ' must be between 0 and 500 hours.');
    }
    return $milli;
}

function payroll_round_nonnegative_ratio(int $numerator, int $denominator): int
{
    if ($numerator < 0 || $denominator < 1) throw new InvalidArgumentException('Payroll ratio is invalid.');
    return intdiv($numerator + intdiv($denominator, 2), $denominator);
}

function payroll_creation_operation_key(mixed $value): string
{
    $key = trim((string)$value);
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,63}\z/', $key)) {
        fail('The Pay Run operation key is missing or invalid.', 422, 'payroll_operation_key_invalid');
    }
    return $key;
}

/** @param array<int,mixed> $entries */
function payroll_creation_request_hash(string $periodStart,string $periodEnd,string $payDate,string $frequency,array $entries): string
{
    $canonical=[];
    foreach($entries as $entry){
        if(!is_array($entry))fail('Employee payroll input is invalid.');
        $canonical[]=[
            'employeeId'=>trim((string)($entry['employeeId']??'')),
            'regularHours'=>array_key_exists('regularHours',$entry)?trim((string)$entry['regularHours']):null,
            'overtimeHours'=>array_key_exists('overtimeHours',$entry)?trim((string)$entry['overtimeHours']):null,
            'additionalPayCents'=>array_key_exists('additionalPayCents',$entry)?trim((string)$entry['additionalPayCents']):null,
            'otherDeductionsCents'=>array_key_exists('otherDeductionsCents',$entry)?trim((string)$entry['otherDeductionsCents']):null,
        ];
    }
    usort($canonical,static fn(array $a,array $b):int=>$a['employeeId']<=>$b['employeeId']);
    return hash('sha256',json_encode([
        'periodStart'=>$periodStart,'periodEnd'=>$periodEnd,'payDate'=>$payDate,'frequency'=>$frequency,'employees'=>$canonical,
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

/** @param array<int,mixed> $entries */
function payroll_recalculation_request_hash(string $runId,string $expectedRevision,string $periodStart,string $periodEnd,string $payDate,string $frequency,array $entries): string
{
    return hash('sha256',json_encode([
        'runId'=>$runId,
        'expectedRevision'=>$expectedRevision,
        'draftHash'=>payroll_creation_request_hash($periodStart,$periodEnd,$payDate,$frequency,$entries),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

/** @param array<int,array<string,mixed>> $items */
function payroll_run_edit_revision(array $run,array $items): string
{
    usort($items,static fn(array $a,array $b):int=>[(string)($a['employee_id']??''),(string)($a['id']??'')]<=>[(string)($b['employee_id']??''),(string)($b['id']??'')]);
    $itemEvidence=array_map(static fn(array $item):array=>[
        'id'=>(string)($item['id']??''),
        'employeeId'=>(string)($item['employee_id']??''),
        'lockedHash'=>(string)($item['locked_hash']??''),
    ],$items);
    $evidence=[
        'id'=>(string)($run['id']??''),
        'companyId'=>(string)($run['company_id']??''),
        'periodStart'=>(string)($run['period_start']??''),
        'periodEnd'=>(string)($run['period_end']??''),
        'payDate'=>(string)($run['pay_date']??''),
        'frequency'=>(string)($run['frequency']??''),
        'runSequence'=>(int)($run['run_sequence']??0),
        'status'=>(string)($run['status']??''),
        'glStatus'=>(string)($run['gl_status']??''),
        'calculationVersion'=>(string)($run['calculation_version']??''),
        'rateSnapshotHash'=>hash('sha256',(string)($run['rate_snapshot_json']??'')),
        'verificationReference'=>$run['verification_reference']??null,
        'updatedAt'=>(string)($run['updated_at']??''),
        'items'=>$itemEvidence,
    ];
    return hash_hmac('sha256',json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(string)config('app.secret'));
}

function payroll_dynamic_federal_basic(float $annualIncome, ?array $basic = null): float
{
    // The defaults retain compatibility for callers of this standalone helper.
    // Payroll calculations always supply the constants from their resolved pack.
    $basic ??= ['maximum'=>16_452.0,'minimum'=>14_829.0,'reductionStart'=>181_440.0,'reductionEnd'=>258_482.0];
    if ($annualIncome <= $basic['reductionStart']) return (float)$basic['maximum'];
    if ($annualIncome >= $basic['reductionEnd']) return (float)$basic['minimum'];
    return $basic['maximum'] - ($basic['maximum'] - $basic['minimum'])
        * (($annualIncome - $basic['reductionStart']) / ($basic['reductionEnd'] - $basic['reductionStart']));
}

/** @param array{thresholds:array<int,float>,rates:array<int,float>,constants:array<int,float>} $schedule */
function payroll_schedule_tax(float $annualIncome, array $schedule): float
{
    $index = 0;
    foreach ($schedule['thresholds'] as $candidate => $threshold) {
        if ($annualIncome >= $threshold) $index = $candidate;
    }
    return max(0.0, $schedule['rates'][$index] * $annualIncome - $schedule['constants'][$index]);
}

function payroll_ontario_health_premium(float $annualIncome, ?array $bands = null): float
{
    $bands ??= [
        ['threshold'=>20_000.0,'base'=>0.0,'rate'=>0.06,'maximum'=>300.0],
        ['threshold'=>36_000.0,'base'=>300.0,'rate'=>0.06,'maximum'=>450.0],
        ['threshold'=>48_000.0,'base'=>450.0,'rate'=>0.25,'maximum'=>600.0],
        ['threshold'=>72_000.0,'base'=>600.0,'rate'=>0.25,'maximum'=>750.0],
        ['threshold'=>200_000.0,'base'=>750.0,'rate'=>0.25,'maximum'=>900.0],
    ];
    $premium = 0.0;
    foreach ($bands as $band) {
        if ($annualIncome <= $band['threshold']) break;
        $premium = min($band['maximum'], $band['base'] + $band['rate'] * ($annualIncome - $band['threshold']));
    }
    return $premium;
}

/**
 * CRA pensionable-month rules for the two age boundaries that can be derived
 * from the employee profile without inventing an election or disability date.
 * CPP starts with the first pay dated in the month after age 18 and continues
 * through the month in which the employee turns 70.
 *
 * @return array{months:int,currentPayPensionable:bool}
 */
function payroll_cpp_age_profile(?string $dateOfBirth, string $payDate): array
{
    payroll_valid_rate_date($payDate);
    if ($dateOfBirth === null || $dateOfBirth === '') return ['months'=>12,'currentPayPensionable'=>true];
    if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $dateOfBirth, $birth)
        || !checkdate((int)$birth[2], (int)$birth[3], (int)$birth[1])
        || (int)$birth[1] < 1900 || $dateOfBirth > $payDate) {
        throw new InvalidArgumentException('Employee date of birth is invalid.');
    }
    $payYear=(int)substr($payDate,0,4);$payMonth=(int)substr($payDate,5,2);
    $birthYear=(int)substr($dateOfBirth,0,4);$birthMonth=(int)substr($dateOfBirth,5,2);
    $ageAtYearEnd=$payYear-$birthYear;
    if ($ageAtYearEnd < 18) return ['months'=>0,'currentPayPensionable'=>false];
    if ($ageAtYearEnd === 18) return ['months'=>12-$birthMonth,'currentPayPensionable'=>$payMonth>$birthMonth];
    if ($ageAtYearEnd < 70) return ['months'=>12,'currentPayPensionable'=>true];
    if ($ageAtYearEnd === 70) return ['months'=>$birthMonth,'currentPayPensionable'=>$payMonth<=$birthMonth];
    return ['months'=>0,'currentPayPensionable'=>false];
}

/** Reject stale draft packs without changing retained finalized payroll. */
function payroll_assert_draft_rate_snapshot(array $run, array $currentRates): void
{
    if ((string)($run['status'] ?? '') !== 'draft') return;
    $snapshot = json_decode((string)($run['rate_snapshot_json'] ?? ''), true);
    if (!is_array($snapshot)) {
        throw new InvalidArgumentException('This draft has no complete payroll rate snapshot. Recalculate the draft before finalising.');
    }
    try {
        $snapshot = payroll_validate_rate_release($snapshot);
        $currentRates = payroll_validate_rate_release($currentRates);
        payroll_assert_rate_date($snapshot, (string)$run['pay_date']);
        payroll_assert_rate_date($currentRates, (string)$run['pay_date']);
    } catch (InvalidArgumentException $error) {
        throw new InvalidArgumentException('This draft needs a complete approved rate pack. Recalculate the draft before finalising.', 0, $error);
    }
    if (!hash_equals((string)$snapshot['digest'], (string)$currentRates['digest'])
        || (string)($run['calculation_version'] ?? '') !== (string)$snapshot['version']) {
        throw new InvalidArgumentException('The approved payroll rate pack changed after this draft was calculated. Recalculate the draft before finalising.');
    }
}

/** Display retained metadata without trying to upgrade legacy snapshots. */
function payroll_rate_snapshot_metadata(?array $rates): array
{
    return [
        'ratePackId'=>is_string($rates['id']??null)?$rates['id']:null,
        'ratePackDigest'=>is_string($rates['digest']??null)?$rates['digest']:null,
        'rateEffectiveFrom'=>is_string($rates['effectiveFrom']??null)?$rates['effectiveFrom']:null,
        'rateEffectiveTo'=>is_string($rates['effectiveTo']??null)?$rates['effectiveTo']:null,
    ];
}

/**
 * Deterministic 2026 draft calculation. CPP/CPP2 and EI use statutory caps.
 * Income tax is an explainable T4127-based estimate and is never final by itself.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function payroll_calculate_2026(array $input, ?array $rateSnapshot = null): array
{
    $payDate = (string)$input['payDate'];
    $province = strtoupper((string)$input['province']);
    if ($province === 'QC') {
        throw new InvalidArgumentException('Quebec payroll requires Revenu Quebec WebRAS and is outside this release.');
    }
    $rates = payroll_validate_rate_release($rateSnapshot ?? payroll_rates_for_date($payDate, isset($input['companyId']) ? (string)$input['companyId'] : null));
    payroll_assert_rate_date($rates, $payDate);
    if (!isset($rates['provinces'][$province])) {
        throw new InvalidArgumentException('The province of employment is unsupported.');
    }
    $periods = payroll_frequency_periods((string)$input['frequency']);
    $gross = max(0.0, ((int)$input['grossPayCents']) / 100);
    $pensionable = max(0.0, ((int)($input['pensionablePayCents'] ?? $input['grossPayCents'])) / 100);
    $insurable = max(0.0, ((int)($input['insurablePayCents'] ?? $input['grossPayCents'])) / 100);
    $ytdPensionable = max(0.0, ((int)($input['ytdPensionableCents'] ?? 0)) / 100);
    $ytdCpp = max(0.0, ((int)($input['ytdCppCents'] ?? 0)) / 100);
    $ytdCpp2 = max(0.0, ((int)($input['ytdCpp2Cents'] ?? 0)) / 100);
    $ytdEi = max(0.0, ((int)($input['ytdEiCents'] ?? 0)) / 100);
    $ytdEmployerEiCents = isset($input['ytdEmployerEiCents']) ? max(0, (int)$input['ytdEmployerEiCents']) : null;
    $cppExempt = !empty($input['cppExempt']);
    $eiExempt = !empty($input['eiExempt']);
    $pensionableMonths = isset($input['pensionableMonths']) ? (int)$input['pensionableMonths'] : 12;
    if ($pensionableMonths < 0 || $pensionableMonths > 12) throw new InvalidArgumentException('CPP pensionable months must be between 0 and 12.');
    $cppRates = $rates['cpp'];
    $eiRates = $rates['ei'];
    $constants = $rates['formulaConstants'];
    if ($ytdEmployerEiCents === null) {
        $ytdEmployerEiCents = (int)round($ytdEi * 100 * ($eiRates['employerRate'] / $eiRates['employeeRate']));
    }

    $cppMaximum = round($cppRates['max'] * ($pensionableMonths / 12), 2);
    $cpp = ($cppExempt || $pensionableMonths === 0) ? 0.0 : max(0.0, min(
        $cppMaximum - $ytdCpp,
        $cppRates['rate'] * max(0.0, $pensionable - ($cppRates['basicExemption'] / $periods)),
    ));
    // T4127 C2 is the lesser of the remaining prorated annual maximum and the
    // current-period earnings newly exposed above the prorated YMPE. Do not
    // subtract prior CPP2 from the second limb; CRA PDOC applies D2 only to the
    // annual-cap limb.
    $cpp2Maximum = round($cppRates['cpp2Max'] * ($pensionableMonths / 12), 2);
    $proratedYmpe = $cppRates['ympe'] * ($pensionableMonths / 12);
    $proratedYampe = $cppRates['yampe'] * ($pensionableMonths / 12);
    $cpp2Band = max(0.0, min($ytdPensionable + $pensionable, $proratedYampe) - max($ytdPensionable, $proratedYmpe));
    $cpp2 = ($cppExempt || $pensionableMonths === 0) ? 0.0 : max(0.0, min(
        $cpp2Maximum - $ytdCpp2,
        $cppRates['cpp2Rate'] * $cpp2Band,
    ));
    $ei = $eiExempt ? 0.0 : max(0.0, min($eiRates['employeeMax'] - $ytdEi, $eiRates['employeeRate'] * $insurable));

    // Only the additional CPP components reduce annual taxable income in T4127.
    $cppFirstAdditional = $cpp * ($cppRates['firstAdditionalRate'] / $cppRates['rate']);
    $annualIncome = max(0.0, $periods * ($gross - $cppFirstAdditional - $cpp2));
    $federal = $rates['federal'];
    $federalBasic = isset($input['federalTd1Cents']) && $input['federalTd1Cents'] !== null
        ? max(0.0, ((int)$input['federalTd1Cents']) / 100)
        : payroll_dynamic_federal_basic($annualIncome, $constants['federalBasic']);
    $federalLowest = $federal['rates'][0];
    $annualBaseCppCredit = min($cppRates['baseMax'] * ($pensionableMonths / 12), $periods * $cpp * ($cppRates['baseRate'] / $cppRates['rate']));
    $annualEiCredit = min($eiRates['employeeMax'], $periods * $ei);
    $employmentAmount = min($constants['employmentAmount'], $periods * $gross);
    $federalTax = max(0.0,
        payroll_schedule_tax($annualIncome, $federal)
        - $federalLowest * ($federalBasic + $annualBaseCppCredit + $annualEiCredit + $employmentAmount)
    );

    $provincial = $rates['provinces'][$province];
    $provincialBasic = isset($input['provincialTd1Cents']) && $input['provincialTd1Cents'] !== null
        ? max(0.0, ((int)$input['provincialTd1Cents']) / 100)
        : ($provincial['basic'] === 'dynamic_federal'
            ? payroll_dynamic_federal_basic($annualIncome, $constants['federalBasic'])
            : ($provincial['basic'] === 'dynamic_manitoba' ? $constants['manitobaBasic'] : (float)$provincial['basic']));
    $provincialBaseTax = max(0.0,
        payroll_schedule_tax($annualIncome, $provincial)
        - $provincial['rates'][0] * ($provincialBasic + $annualBaseCppCredit + $annualEiCredit)
    );
    $provincialTax = $provincialBaseTax;
    $provincialAdjustments = 0.0;
    if ($province === 'ON') {
        $ontario = $constants['ontario'];
        $surtax = 0.0;
        foreach ($ontario['surtaxRates'] as $index => $rate) {
            $surtax += $rate * max(0.0, $provincialBaseTax - $ontario['surtaxThresholds'][$index]);
        }
        $health = payroll_ontario_health_premium($annualIncome, $ontario['healthBands']);
        $reduction = max(0.0, min($provincialBaseTax + $surtax, 2 * $ontario['reductionBase'] - ($provincialBaseTax + $surtax)));
        $provincialAdjustments = $surtax + $health - $reduction;
        $provincialTax = max(0.0, $provincialBaseTax + $provincialAdjustments);
    }

    $additionalTax = max(0.0, ((int)($input['additionalTaxCents'] ?? 0)) / 100);
    $estimatedIncomeTax = max(0.0, ($federalTax + $provincialTax) / $periods + $additionalTax);
    $toCents = static fn(float $amount): int => (int)round($amount * 100);
    $cppCents = $toCents($cpp);
    $cpp2Cents = $toCents($cpp2);
    $eiCents = $toCents($ei);
    $employerEiMaximumCents = $toCents($eiRates['employerMax']);
    $employerEiRequiredToDateCents = (int)round(($toCents($ytdEi) + $eiCents) * ($eiRates['employerRate'] / $eiRates['employeeRate']));
    $employerEiCents = $eiExempt ? 0 : max(0, min(
        $employerEiMaximumCents - $ytdEmployerEiCents,
        $employerEiRequiredToDateCents - $ytdEmployerEiCents,
    ));
    return [
        'employeeCppCents' => $cppCents,
        'employeeCpp2Cents' => $cpp2Cents,
        'employeeEiCents' => $eiCents,
        'employerCppCents' => $cppCents,
        'employerCpp2Cents' => $cpp2Cents,
        'employerEiCents' => $employerEiCents,
        'estimatedIncomeTaxCents' => $toCents($estimatedIncomeTax),
        'annualizedTaxableIncomeCents' => $toCents($annualIncome),
        'federalAnnualTaxCents' => $toCents($federalTax),
        'provincialAnnualTaxCents' => $toCents($provincialTax),
        'provincialAdjustmentCents' => $toCents($provincialAdjustments),
        'calculationVersion' => $rates['version'],
        'rateHalf' => $rates['half'],
        'ratePackId' => $rates['id'],
        'ratePackDigest' => $rates['digest'],
        'rateEffectiveFrom' => $rates['effectiveFrom'],
        'rateEffectiveTo' => $rates['effectiveTo'],
        'pensionableMonths' => $pensionableMonths,
        'requiresIncomeTaxVerification' => true,
    ];
}

/* R130: Tegh never stores Social Insurance Numbers. The employee's internal
   Employee ID (for example EMP-0001) identifies them instead. This check only
   detects a SIN-shaped value (nine digits passing the SIN checksum) so it can
   be refused; nothing is kept. */
function payroll_looks_like_sin(string $value): bool
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) !== 9 || !preg_match('/^\s*\d{3}[\s-]?\d{3}[\s-]?\d{3}\s*$/', $value)) return false;
    $sum = 0;
    foreach (str_split($digits) as $index => $character) {
        $digit = (int)$character;
        if ($index % 2 === 1) { $digit *= 2; if ($digit > 9) $digit -= 9; }
        $sum += $digit;
    }
    return $sum % 10 === 0;
}

function payroll_refuse_sin_input(array $input): void
{
    foreach (['sin', 'socialInsuranceNumber', 'social_insurance_number', 'sinLastFour', 'sin_last_four'] as $key) {
        if (array_key_exists($key, $input) && trim((string)$input[$key]) !== '') fail('Tegh does not store Social Insurance Numbers (SINs). Remove the SIN and use the Employee ID instead.', 422, 'sin_not_accepted');
    }
    foreach (['employeeNumber', 'firstName', 'lastName', 'email'] as $key) {
        if (isset($input[$key]) && payroll_looks_like_sin((string)$input[$key])) fail('This looks like a Social Insurance Number. Tegh does not store SINs; use an Employee ID such as EMP-0001.', 422, 'sin_not_accepted');
    }
}

/* R130: clear any SIN values saved by earlier releases for this company. It
   runs on payroll requests; once clean it is one indexed read. The clean-up is
   recorded in the company audit trail as an automatic action. */
function payroll_purge_stored_sins(array $user, string $companyId): void
{
    static $done = []; if (isset($done[$companyId])) return; $done[$companyId] = true;
    $stmt = db()->prepare('SELECT COUNT(*) FROM payroll_employees WHERE company_id = ? AND (sin_ciphertext IS NOT NULL OR sin_last_four IS NOT NULL)');
    $stmt->execute([$companyId]);
    $count = (int)$stmt->fetchColumn();
    if ($count === 0) return;
    db()->prepare('UPDATE payroll_employees SET sin_ciphertext = NULL, sin_last_four = NULL WHERE company_id = ? AND (sin_ciphertext IS NOT NULL OR sin_last_four IS NOT NULL)')->execute([$companyId]);
    audit_event($user, $companyId, 'payroll.sin_data_removed', 'payroll_employee', 'all', ['employeesCleared' => $count, 'automatic' => true, 'reason' => 'Tegh no longer stores Social Insurance Numbers']);
}

function payroll_encrypt_secret(string $plaintext): string
{
    $key = hash('sha256', 'sr-accountax-payroll-sin|' . (string)config('app.secret'), true);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'Tegh-SIN-v1');
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('Sensitive employee data encryption is unavailable.');
    }
    return 'v1.' . base64url_encode($iv) . '.' . base64url_encode($tag) . '.' . base64url_encode($ciphertext);
}

function payroll_account_specs(): array
{
    return [
        '2300' => ['Payroll Net Pay Payable', 'liability', 'credit', 1],
        '2310' => ['Payroll Income Tax Payable', 'liability', 'credit', 1],
        '2320' => ['CPP/QPP Payable', 'liability', 'credit', 1],
        '2330' => ['Employment Insurance Payable', 'liability', 'credit', 1],
        '2340' => ['Other Payroll Deductions Payable', 'liability', 'credit', 1],
        '2350' => ['Employer Payroll Levy Payable', 'liability', 'credit', 1],
        '7000' => ['Wages and Salaries', 'expense', 'debit', 0],
        '7010' => ['Employer Payroll Contributions', 'expense', 'debit', 0],
        '7020' => ['Employer Payroll Levy Expense', 'expense', 'debit', 0],
    ];
}

/** @return array<string,string> */
function ensure_payroll_accounts(string $companyId): array
{
    $ids = [];
    $lookup = db()->prepare('SELECT id FROM accounts WHERE company_id = ? AND code = ? LIMIT 1');
    $insert = db()->prepare('INSERT INTO accounts (id, company_id, code, name, account_type, normal_balance, is_control) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach (payroll_account_specs() as $code => [$name, $type, $normal, $control]) {
        $lookup->execute([$companyId, $code]);
        $id = $lookup->fetchColumn();
        if ($id === false) {
            $id = new_id('account');
            $insert->execute([$id, $companyId, $code, $name, $type, $normal, $control]);
        }
        $ids[$code] = (string)$id;
    }
    return $ids;
}

function payroll_settings_row(string $companyId): ?array
{
    try {
        $stmt = db()->prepare('SELECT * FROM payroll_settings WHERE company_id = ? AND active = 1 LIMIT 1');
        $stmt->execute([$companyId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '42S02') return null;
        throw $error;
    }
}

function payroll_item_integrity_hash(array $row): string
{
    $fields = ['payroll_run_id','employee_id','regular_hours_milli','overtime_hours_milli','regular_pay_cents','overtime_pay_cents','additional_pay_cents','vacation_pay_cents','gross_pay_cents','employee_cpp_cents','employee_cpp2_cents','employee_ei_cents','estimated_income_tax_cents','verified_income_tax_cents','other_deductions_cents','net_pay_cents','employer_cpp_cents','employer_cpp2_cents','employer_ei_cents'];
    $values = [];
    foreach ($fields as $field) $values[$field] = $row[$field] ?? null;
    return hash_hmac('sha256', json_encode($values, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string)config('app.secret'));
}

/** @return array<string,int> */
function payroll_employee_ytd(string $companyId, string $employeeId, string $payDate): array
{
    $yearStart = substr($payDate, 0, 4) . '-01-01';
    $stmt = db()->prepare("SELECT
        COALESCE(SUM(i.pensionable_pay_cents),0) pensionable,
        COALESCE(SUM(i.insurable_pay_cents),0) insurable,
        COALESCE(SUM(i.employee_cpp_cents),0) cpp,
        COALESCE(SUM(i.employee_cpp2_cents),0) cpp2,
        COALESCE(SUM(i.employee_ei_cents),0) ei,
        COALESCE(SUM(i.employer_ei_cents),0) employer_ei
      FROM payroll_run_items i JOIN payroll_runs r ON r.id = i.payroll_run_id
      WHERE r.company_id = ? AND i.employee_id = ? AND r.status IN ('posted','paid') AND r.pay_date BETWEEN ? AND ?");
    $stmt->execute([$companyId, $employeeId, $yearStart, $payDate]);
    $row = $stmt->fetch() ?: [];
    return ['pensionable' => (int)($row['pensionable'] ?? 0), 'insurable' => (int)($row['insurable'] ?? 0), 'cpp' => (int)($row['cpp'] ?? 0), 'cpp2' => (int)($row['cpp2'] ?? 0), 'ei' => (int)($row['ei'] ?? 0), 'employerEi' => (int)($row['employer_ei'] ?? 0)];
}

function payroll_recalculate_run_totals(string $runId): void
{
    $stmt = db()->prepare('SELECT
        COALESCE(SUM(gross_pay_cents),0) gross_pay_cents,
        COALESCE(SUM(employee_cpp_cents),0) employee_cpp_cents,
        COALESCE(SUM(employee_cpp2_cents),0) employee_cpp2_cents,
        COALESCE(SUM(employee_ei_cents),0) employee_ei_cents,
        COALESCE(SUM(COALESCE(verified_income_tax_cents, estimated_income_tax_cents)),0) income_tax_cents,
        COALESCE(SUM(other_deductions_cents),0) other_deductions_cents,
        COALESCE(SUM(net_pay_cents),0) net_pay_cents,
        COALESCE(SUM(employer_cpp_cents),0) employer_cpp_cents,
        COALESCE(SUM(employer_cpp2_cents),0) employer_cpp2_cents,
        COALESCE(SUM(employer_ei_cents),0) employer_ei_cents
      FROM payroll_run_items WHERE payroll_run_id = ?');
    $stmt->execute([$runId]);
    $totals = $stmt->fetch();
    db()->prepare('UPDATE payroll_runs SET gross_pay_cents=?, employee_cpp_cents=?, employee_cpp2_cents=?, employee_ei_cents=?, income_tax_cents=?, other_deductions_cents=?, net_pay_cents=?, employer_cpp_cents=?, employer_cpp2_cents=?, employer_ei_cents=? WHERE id=?')
        ->execute([(int)$totals['gross_pay_cents'], (int)$totals['employee_cpp_cents'], (int)$totals['employee_cpp2_cents'], (int)$totals['employee_ei_cents'], (int)$totals['income_tax_cents'], (int)$totals['other_deductions_cents'], (int)$totals['net_pay_cents'], (int)$totals['employer_cpp_cents'], (int)$totals['employer_cpp2_cents'], (int)$totals['employer_ei_cents'], $runId]);
}

function payroll_workspace_data(array $company): array
{
    $companyId = (string)$company['id'];
    $settings = payroll_settings_row($companyId);
    if (!$settings) {
        return [
            'enabled' => false,
            'postingMode' => payroll_company_posting_mode($company),
            'calculationVersion' => SR_PAYROLL_CALCULATION_VERSION,
            'supportedYear' => 2026,
            'employees' => [], 'runs' => [], 'remittances' => [],
            'boundary' => ['quebecSupported' => false, 'directDeposit' => false, 'craFiling' => false, 't4Xml' => false],
        ];
    }
    $stmt = db()->prepare('SELECT * FROM payroll_employees WHERE company_id = ? ORDER BY active DESC, last_name, first_name');
    $stmt->execute([$companyId]);
    $employees = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'employeeNumber' => (string)$row['employee_number'],
        'firstName' => (string)$row['first_name'], 'lastName' => (string)$row['last_name'],
        'name' => (string)$row['first_name'] . ' ' . (string)$row['last_name'],
        'email' => $row['email'] !== null ? (string)$row['email'] : null,
        'provinceOfEmployment' => (string)$row['province_of_employment'], 'provinceOfResidence' => (string)$row['province_of_residence'],
        'hireDate' => (string)$row['hire_date'], 'terminationDate' => $row['termination_date'] !== null ? (string)$row['termination_date'] : null,
        'payFrequency' => (string)$row['pay_frequency'], 'payType' => (string)$row['pay_type'],
        'annualSalaryCents' => (int)$row['annual_salary_cents'], 'hourlyRateCents' => (int)$row['hourly_rate_cents'],
        'standardHoursMilli' => (int)$row['standard_hours_milli'], 'vacationRateBps' => (int)$row['vacation_rate_bps'],
        'vacationPaidEachPay' => (bool)$row['vacation_paid_each_pay'], 'cppExempt' => (bool)$row['cpp_exempt'],
        'eiExempt' => (bool)$row['ei_exempt'], 'active' => (bool)$row['active'],
    ], $stmt->fetchAll());

    $stmt = db()->prepare('SELECT * FROM payroll_runs WHERE company_id = ? ORDER BY pay_date DESC, created_at DESC LIMIT 80');
    $stmt->execute([$companyId]);
    $runRows = $stmt->fetchAll();
    $itemsByRun = [];$revisionItemsByRun=[];
    if ($runRows) {
        $runIds = array_map(static fn(array $row): string => (string)$row['id'], $runRows);
        $placeholders = implode(',', array_fill(0, count($runIds), '?'));
        $itemStmt = db()->prepare("SELECT i.*, CONCAT(e.first_name, ' ', e.last_name) employee_name, e.employee_number
            FROM payroll_run_items i JOIN payroll_employees e ON e.id=i.employee_id
            WHERE i.payroll_run_id IN ($placeholders) ORDER BY i.payroll_run_id,e.last_name,e.first_name");
        $itemStmt->execute($runIds);
        foreach ($itemStmt->fetchAll() as $item) {
            $revisionItemsByRun[(string)$item['payroll_run_id']][]=['id'=>(string)$item['id'],'employee_id'=>(string)$item['employee_id'],'locked_hash'=>(string)$item['locked_hash']];
            $itemsByRun[(string)$item['payroll_run_id']][] = [
                'id' => (string)$item['id'], 'employeeId' => (string)$item['employee_id'], 'employeeName' => (string)$item['employee_name'],
                'employeeNumber' => (string)$item['employee_number'], 'regularHoursMilli' => (int)$item['regular_hours_milli'],
                'overtimeHoursMilli' => (int)$item['overtime_hours_milli'], 'regularPayCents' => (int)$item['regular_pay_cents'],
                'overtimePayCents' => (int)$item['overtime_pay_cents'], 'additionalPayCents' => (int)$item['additional_pay_cents'],
                'vacationPayCents' => (int)$item['vacation_pay_cents'], 'grossPayCents' => (int)$item['gross_pay_cents'],
                'employeeCppCents' => (int)$item['employee_cpp_cents'], 'employeeCpp2Cents' => (int)$item['employee_cpp2_cents'],
                'employeeEiCents' => (int)$item['employee_ei_cents'], 'estimatedIncomeTaxCents' => (int)$item['estimated_income_tax_cents'],
                'verifiedIncomeTaxCents' => $item['verified_income_tax_cents'] !== null ? (int)$item['verified_income_tax_cents'] : null,
                'otherDeductionsCents' => (int)$item['other_deductions_cents'], 'netPayCents' => (int)$item['net_pay_cents'],
            ];
        }
    }
    $voucherByRun=[];if($runRows&&schema_table_exists('vouchers')){$ids=array_map(static fn(array $r):string=>(string)$r['id'],$runRows);$ph=implode(',',array_fill(0,count($ids),'?'));$q=db()->prepare("SELECT source_id,voucher_number FROM vouchers WHERE company_id=? AND source_type='payroll_run' AND source_id IN ($ph)");$q->execute(array_merge([$companyId],$ids));foreach($q->fetchAll() as $vr)$voucherByRun[(string)$vr['source_id']]=(string)$vr['voucher_number'];}
    $runs = [];
    foreach ($runRows as $row) {
        $items = $itemsByRun[(string)$row['id']] ?? [];
        $rateSnapshot = json_decode((string)($row['rate_snapshot_json'] ?? ''), true);
        $runs[] = [
            'id' => (string)$row['id'], 'periodStart' => (string)$row['period_start'], 'periodEnd' => (string)$row['period_end'],
            'payDate' => (string)$row['pay_date'], 'frequency' => (string)$row['frequency'], 'status' => (string)$row['status'],
            'glStatus' => (string)($row['gl_status'] ?? ((string)$row['accrual_journal_entry_id'] !== '' ? 'posted' : 'not_ready')),
            'accrualJournalEntryId' => $row['accrual_journal_entry_id'] !== null ? (string)$row['accrual_journal_entry_id'] : null,
            'paymentJournalEntryId' => $row['payment_journal_entry_id'] !== null ? (string)$row['payment_journal_entry_id'] : null,
            'glPostedAt' => $row['gl_posted_at'] !== null ? (string)$row['gl_posted_at'] : null,
            'runSequence' => (int)$row['run_sequence'], 'transactionNumber'=>$voucherByRun[(string)$row['id']]??null,
            'calculationVersion' => (string)$row['calculation_version'], 'verificationReference' => $row['verification_reference'] !== null ? (string)$row['verification_reference'] : null,
            'grossPayCents' => (int)$row['gross_pay_cents'], 'employeeCppCents' => (int)$row['employee_cpp_cents'],
            'employeeCpp2Cents' => (int)$row['employee_cpp2_cents'], 'employeeEiCents' => (int)$row['employee_ei_cents'],
            'incomeTaxCents' => (int)$row['income_tax_cents'], 'otherDeductionsCents' => (int)$row['other_deductions_cents'],
            'netPayCents' => (int)$row['net_pay_cents'], 'employerCppCents' => (int)$row['employer_cpp_cents'],
            'employerCpp2Cents' => (int)$row['employer_cpp2_cents'], 'employerEiCents' => (int)$row['employer_ei_cents'],
            'paymentDate' => $row['payment_date'] !== null ? (string)$row['payment_date'] : null,
            'editRevision'=>payroll_run_edit_revision($row,$revisionItemsByRun[(string)$row['id']]??[]),
            'items' => $items,
            ...payroll_rate_snapshot_metadata(is_array($rateSnapshot) ? $rateSnapshot : null),
        ];
    }
    $stmt = db()->prepare('SELECT * FROM payroll_remittances WHERE company_id=? ORDER BY payment_date DESC LIMIT 36');
    $stmt->execute([$companyId]);
    $remittances = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'periodEnd' => (string)$row['period_end'], 'paymentDate' => (string)$row['payment_date'],
        'employeeTaxCents' => (int)$row['employee_tax_cents'], 'cppCents' => (int)$row['cpp_cents'],
        'eiCents' => (int)$row['ei_cents'], 'totalCents' => (int)$row['total_cents'], 'status' => (string)$row['status'],
        'bankTransactionId' => $row['bank_transaction_id'] !== null ? (string)$row['bank_transaction_id'] : null,
    ], $stmt->fetchAll());
    return [
        'enabled' => true, 'calculationVersion' => SR_PAYROLL_CALCULATION_VERSION, 'supportedYear' => 2026,
        'postingMode' => payroll_company_posting_mode($company),
        'settings' => ['payrollAccountNumber' => $settings['payroll_account_number'], 'defaultFrequency' => $settings['default_frequency'], 'remitterType' => $settings['remitter_type']],
        'employees' => $employees, 'runs' => $runs, 'remittances' => $remittances,
        'boundary' => ['quebecSupported' => false, 'directDeposit' => false, 'craFiling' => false, 't4Xml' => false],
    ];
}

function handle_payroll_quick_calculate(array $user, array $company): never
{
    require_method('POST'); require_csrf();
    $input=request_json();
    $payDate=safe_date($input['payDate']??'','Pay date');
    $frequency=(string)($input['frequency']??'biweekly');
    payroll_frequency_periods($frequency);
    $employees=is_array($input['employees']??null)?$input['employees']:[];
    if(count($employees)<1||count($employees)>10) fail('Quick Payroll Calculator supports between 1 and 10 employees.');
    try{$rateSet=payroll_rates_for_date($payDate,(string)$company['id']);}catch(InvalidArgumentException $error){fail($error->getMessage(),422,'payroll_rate_release_required');}
    $rows=[];$totals=['grossPayCents'=>0,'employeeCppCents'=>0,'employeeCpp2Cents'=>0,'employeeEiCents'=>0,'estimatedIncomeTaxCents'=>0,'otherDeductionsCents'=>0,'netPayCents'=>0,'employerContributionsCents'=>0];
    foreach($employees as $index=>$employee){
        if(!is_array($employee)) fail('Employee '.($index+1).' is invalid.');
        $name=clean_text($employee['name']??('Employee '.($index+1)),'Employee name',120);
        $province=strtoupper(clean_text($employee['province']??$company['province'],'Province',2));
        $gross=payroll_nonnegative_cents($employee['grossPayCents']??0,'Gross pay');
        if($gross<=0) fail($name.' must have positive gross pay.');
        $other=payroll_nonnegative_cents($employee['otherDeductionsCents']??0,'Other deductions');
        try{$calc=payroll_calculate_2026([
            'payDate'=>$payDate,'province'=>$province,'frequency'=>$frequency,'grossPayCents'=>$gross,'companyId'=>(string)$company['id'],
            'ytdPensionableCents'=>(int)($employee['ytdPensionableCents']??0),'ytdCppCents'=>(int)($employee['ytdCppCents']??0),
            'ytdCpp2Cents'=>(int)($employee['ytdCpp2Cents']??0),'ytdEiCents'=>(int)($employee['ytdEiCents']??0),
            'cppExempt'=>!empty($employee['cppExempt']),'eiExempt'=>!empty($employee['eiExempt'])
        ],$rateSet);}catch(InvalidArgumentException $error){fail($name.': '.$error->getMessage(),422,'payroll_quick_calculation_invalid');}
        $tax=(int)$calc['estimatedIncomeTaxCents'];$cpp=(int)$calc['employeeCppCents'];$cpp2=(int)$calc['employeeCpp2Cents'];$ei=(int)$calc['employeeEiCents'];
        $net=max(0,$gross-$tax-$cpp-$cpp2-$ei-$other);$employer=(int)$calc['employerCppCents']+(int)$calc['employerCpp2Cents']+(int)$calc['employerEiCents'];
        $row=['name'=>$name,'province'=>$province,'grossPayCents'=>$gross,'employeeCppCents'=>$cpp,'employeeCpp2Cents'=>$cpp2,'employeeEiCents'=>$ei,'estimatedIncomeTaxCents'=>$tax,'otherDeductionsCents'=>$other,'netPayCents'=>$net,'employerContributionsCents'=>$employer,'calculationVersion'=>$calc['calculationVersion'],'requiresIncomeTaxVerification'=>true];
        $row+=payroll_rate_snapshot_metadata($rateSet);
        $rows[]=$row;foreach($totals as $key=>$_)$totals[$key]+=(int)($row[$key]??0);
    }
    json_response(['quickCalculation'=>['payDate'=>$payDate,'frequency'=>$frequency,'employees'=>$rows,'totals'=>$totals,'stored'=>false,'notice'=>'Quick calculations are not saved. Income tax is an estimate and should be verified against the official CRA payroll calculator before using the figures for a recorded pay run.']]);
}

function handle_payroll_setup(array $user, array $company): never
{
    require_method('POST'); require_csrf(); require_company_role($company, 'owner');
    initialize_schema();
    $input = request_json();
    $frequency = (string)($input['defaultFrequency'] ?? 'biweekly');
    payroll_frequency_periods($frequency);
    $remitter = (string)($input['remitterType'] ?? 'regular');
    if (!in_array($remitter, ['regular','quarterly','accelerated_1','accelerated_2'], true)) fail('Remitter type is invalid.');
    $accountNumber = optional_text($input['payrollAccountNumber'] ?? null, 40);
    db()->beginTransaction();
    try {
        $ids = ensure_payroll_accounts((string)$company['id']);
        db()->prepare('INSERT INTO payroll_settings (company_id,payroll_account_number,default_frequency,remitter_type,wages_account_id,employer_expense_account_id,net_pay_account_id,tax_payable_account_id,cpp_payable_account_id,ei_payable_account_id,other_payable_account_id) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payroll_account_number=VALUES(payroll_account_number),default_frequency=VALUES(default_frequency),remitter_type=VALUES(remitter_type),active=1')
            ->execute([$company['id'], $accountNumber, $frequency, $remitter, $ids['7000'], $ids['7010'], $ids['2300'], $ids['2310'], $ids['2320'], $ids['2330'], $ids['2340']]);
        audit_event($user, (string)$company['id'], 'payroll.enabled', 'payroll_settings', (string)$company['id'], ['frequency' => $frequency, 'calculationVersion' => SR_PAYROLL_CALCULATION_VERSION]);
        db()->commit();
    } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
    json_response(['payroll' => payroll_workspace_data($company)], 201);
}

function handle_payroll_employee(array $user, array $company): never
{
    require_method('POST', 'PUT', 'DELETE'); require_csrf(); require_company_role($company, 'owner', 'bookkeeper');
    if (!payroll_settings_row((string)$company['id'])) fail('Enable payroll for this company first.', 409, 'payroll_not_enabled');
    $input = request_json();
    if (request_method() === 'DELETE') {
        $id = clean_text($input['employeeId'] ?? '', 'Employee', 64);
        $stmt = db()->prepare('SELECT first_name, last_name FROM payroll_employees WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $company['id']]);
        $employee = $stmt->fetch();
        if (!$employee) fail('Employee not found.', 404, 'employee_not_found');
        $used = db()->prepare('SELECT COUNT(*) FROM payroll_run_items WHERE employee_id = ?');
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) {
            db()->prepare('UPDATE payroll_employees SET active = 0 WHERE id = ? AND company_id = ?')->execute([$id, $company['id']]);
            $result = 'archived';
        } else {
            db()->prepare('DELETE FROM payroll_employees WHERE id = ? AND company_id = ?')->execute([$id, $company['id']]);
            $result = 'deleted';
        }
        audit_event($user, (string)$company['id'], 'payroll.employee_' . $result, 'payroll_employee', $id, [
            'name' => (string)$employee['first_name'] . ' ' . (string)$employee['last_name'],
        ]);
        json_response(['employee' => ['id' => $id, 'status' => $result]]);
    }
    $provinceEmployment = strtoupper(clean_text($input['provinceOfEmployment'] ?? $company['province'], 'Province of employment', 2));
    $provinceResidence = strtoupper(clean_text($input['provinceOfResidence'] ?? $provinceEmployment, 'Province of residence', 2));
    $provinces = ['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','SK','YT'];
    if ($provinceEmployment === 'QC' || $provinceResidence === 'QC') fail('Quebec payroll is blocked in this release. Use Revenu Quebec WebRAS.', 422, 'quebec_payroll_unsupported');
    if (!in_array($provinceEmployment, $provinces, true) || !in_array($provinceResidence, $provinces, true)) fail('Province is invalid.');
    $frequency = (string)($input['payFrequency'] ?? 'biweekly'); payroll_frequency_periods($frequency);
    $payType = (string)($input['payType'] ?? 'salary');
    if (!in_array($payType, ['salary','hourly'], true)) fail('Pay type is invalid.');
    $salary = payroll_nonnegative_cents($input['annualSalaryCents'] ?? 0, 'Annual salary');
    $hourly = payroll_nonnegative_cents($input['hourlyRateCents'] ?? 0, 'Hourly rate');
    if (($payType === 'salary' && $salary <= 0) || ($payType === 'hourly' && $hourly <= 0)) fail('Enter compensation for the selected pay type.');
    $standardHours = payroll_safe_hours_milli($input['standardHours'] ?? ($payType === 'hourly' ? 80 : 0), 'Standard hours');
    $vacationRate = (int)($input['vacationRateBps'] ?? 400);
    if ($vacationRate < 0 || $vacationRate > 2000) fail('Vacation rate must be between 0% and 20%.');
    payroll_refuse_sin_input($input);
    $sinCipher = null; $sinLast = null; // R130: SINs are never stored.
    $email = trim((string)($input['email'] ?? ''));
    $email = $email === '' ? null : safe_email($email);
    $isUpdate = request_method() === 'PUT';
    $id = $isUpdate ? clean_text($input['employeeId'] ?? '', 'Employee', 64) : new_id('employee');
    try {
        $values = [clean_text($input['employeeNumber'] ?? '', 'Employee number', 30),clean_text($input['firstName'] ?? '', 'First name', 100),clean_text($input['lastName'] ?? '', 'Last name', 100),$email,$sinCipher,$sinLast,$provinceEmployment,$provinceResidence,isset($input['dateOfBirth']) && $input['dateOfBirth'] !== '' ? safe_date($input['dateOfBirth'], 'Date of birth') : null,safe_date($input['hireDate'] ?? '', 'Hire date'),isset($input['terminationDate']) && $input['terminationDate'] !== '' ? safe_date($input['terminationDate'], 'Termination date') : null,$frequency,$payType,$salary,$hourly,$standardHours,$vacationRate,!empty($input['vacationPaidEachPay']) ? 1 : 0,isset($input['federalTd1Cents']) ? payroll_nonnegative_cents($input['federalTd1Cents'],'Federal TD1') : null,isset($input['provincialTd1Cents']) ? payroll_nonnegative_cents($input['provincialTd1Cents'],'Provincial TD1') : null,payroll_nonnegative_cents($input['additionalTaxCents'] ?? 0,'Additional tax'),!empty($input['cppExempt']) ? 1 : 0,!empty($input['eiExempt']) ? 1 : 0,array_key_exists('active',$input) ? (!empty($input['active']) ? 1 : 0) : 1];
        if ($values[10] !== null && $values[10] < $values[9]) fail('Termination date cannot precede hire date.');
        if ($isUpdate) {
            $stmt = db()->prepare('UPDATE payroll_employees SET employee_number=?,first_name=?,last_name=?,email=?,sin_ciphertext=?,sin_last_four=?,province_of_employment=?,province_of_residence=?,date_of_birth=COALESCE(?,date_of_birth),hire_date=?,termination_date=?,pay_frequency=?,pay_type=?,annual_salary_cents=?,hourly_rate_cents=?,standard_hours_milli=?,vacation_rate_bps=?,vacation_paid_each_pay=?,federal_td1_cents=COALESCE(?,federal_td1_cents),provincial_td1_cents=COALESCE(?,provincial_td1_cents),additional_tax_cents=?,cpp_exempt=?,ei_exempt=?,active=? WHERE id=? AND company_id=?');
            $stmt->execute([...$values,$id,$company['id']]);
            if ($stmt->rowCount() === 0) {
                $check=db()->prepare('SELECT COUNT(*) FROM payroll_employees WHERE id=? AND company_id=?');$check->execute([$id,$company['id']]);
                if((int)$check->fetchColumn()===0)fail('Employee not found.',404,'employee_not_found');
            }
        } else {
            db()->prepare('INSERT INTO payroll_employees (id,company_id,employee_number,first_name,last_name,email,sin_ciphertext,sin_last_four,province_of_employment,province_of_residence,date_of_birth,hire_date,termination_date,pay_frequency,pay_type,annual_salary_cents,hourly_rate_cents,standard_hours_milli,vacation_rate_bps,vacation_paid_each_pay,federal_td1_cents,provincial_td1_cents,additional_tax_cents,cpp_exempt,ei_exempt,active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id,$company['id'],...$values,$user['id']]);
        }
    } catch (PDOException $error) { if ((string)$error->getCode() === '23000') fail('That employee number already exists.', 409, 'duplicate_employee_number'); throw $error; }
    audit_event($user, (string)$company['id'], $isUpdate ? 'payroll.employee_updated' : 'payroll.employee_created', 'payroll_employee', $id, ['province' => $provinceEmployment, 'payType' => $payType]);
    json_response(['employee' => ['id' => $id]], $isUpdate ? 200 : 201);
}

function payroll_company_posting_mode(array $company): string
{
    $mode=(string)($company['payroll_posting_mode']??'draft');
    return in_array($mode,['automatic','draft','none'],true)?$mode:'draft';
}

function payroll_posting_memo(array $company, array $run, array $items): string
{
    $period = (string)$run['period_start'] . ' to ' . (string)$run['period_end'];
    $payDate = (string)$run['pay_date'];
    if (count($items) !== 1) {
        return 'Payroll · ' . count($items) . ' employees · Period ' . $period . ' · Pay date ' . $payDate;
    }
    $stmt = db()->prepare('SELECT first_name,last_name,pay_type,annual_salary_cents,hourly_rate_cents
        FROM payroll_employees WHERE id=? AND company_id=?');
    $stmt->execute([(string)$items[0]['employee_id'], (string)$company['id']]);
    $employee = $stmt->fetch();
    if (!$employee) return 'Payroll · 1 employee · Period ' . $period . ' · Pay date ' . $payDate;
    $currency = (string)($company['currency'] ?? 'CAD');
    $compensation = (string)$employee['pay_type'] === 'hourly'
        ? 'Hourly rate ' . number_format((int)$employee['hourly_rate_cents'] / 100, 2, '.', ',') . ' ' . $currency
        : 'Yearly salary ' . number_format((int)$employee['annual_salary_cents'] / 100, 2, '.', ',') . ' ' . $currency;
    return mb_substr('Payroll · ' . trim((string)$employee['first_name'] . ' ' . (string)$employee['last_name'])
        . ' · ' . $compensation . ' · Period ' . $period . ' · Pay date ' . $payDate, 0, 500);
}

/** @return array{applicable:bool,taxCents:int,rateDecimal:float,postingMode:string,expenseAccountId:?string,payableAccountId:?string} */
function payroll_employer_levy_for_annual_payroll(string $companyId,string $date,int $annualPayrollCents): array
{
    $stmt=db()->prepare('SELECT * FROM employer_levy_profiles WHERE company_id=?');$stmt->execute([$companyId]);$profile=$stmt->fetch();
    if(!$profile||(string)$profile['applicability']==='not_applicable')return ['applicable'=>false,'taxCents'=>0,'rateDecimal'=>0.0,'postingMode'=>'none','expenseAccountId'=>null,'payableAccountId'=>null];
    $jur=(string)$profile['jurisdiction'];
    $stmt=db()->prepare('SELECT * FROM employer_levy_rates WHERE company_id=? AND jurisdiction=? AND active=1 AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) AND payroll_min_cents<=? AND (payroll_max_cents IS NULL OR payroll_max_cents>=?) ORDER BY effective_from DESC,payroll_min_cents DESC LIMIT 1');
    $stmt->execute([$companyId,$jur,$date,$date,$annualPayrollCents,$annualPayrollCents]);$rate=$stmt->fetch();
    if(!$rate)return ['applicable'=>false,'taxCents'=>0,'rateDecimal'=>0.0,'postingMode'=>(string)$profile['posting_mode'],'expenseAccountId'=>$profile['expense_account_id'],'payableAccountId'=>$profile['payable_account_id']];
    $exemption=(bool)$profile['eligible_for_exemption']?(int)$rate['exemption_cents']:0;$threshold=$rate['exemption_threshold_cents']===null?null:(int)$rate['exemption_threshold_cents'];
    if($threshold!==null&&max($annualPayrollCents,(int)$profile['associated_group_payroll_cents'])>$threshold)$exemption=0;
    $taxable=max(0,$annualPayrollCents-$exemption);$decimal=$rate['rate_decimal']===null?((int)$rate['rate_bps']/10000):(float)$rate['rate_decimal'];
    return ['applicable'=>true,'taxCents'=>(int)round($taxable*$decimal),'rateDecimal'=>$decimal,'postingMode'=>(string)$profile['posting_mode'],'expenseAccountId'=>$profile['expense_account_id'],'payableAccountId'=>$profile['payable_account_id']];
}

function payroll_run_levy_increment(string $companyId,string $runId,string $payDate,int $currentGrossCents): int
{
    $year=substr($payDate,0,4);$stmt=db()->prepare("SELECT COALESCE(SUM(gross_pay_cents),0) FROM payroll_runs WHERE company_id=? AND id<>? AND status IN ('posted','paid') AND YEAR(pay_date)=?");$stmt->execute([$companyId,$runId,$year]);$prior=(int)$stmt->fetchColumn();
    $before=payroll_employer_levy_for_annual_payroll($companyId,$payDate,$prior);$after=payroll_employer_levy_for_annual_payroll($companyId,$payDate,$prior+$currentGrossCents);
    return max(0,(int)$after['taxCents']-(int)$before['taxCents']);
}

/**
 * Insert the authoritative employee calculation rows for a draft Pay Run.
 * New-run creation and draft recalculation deliberately share this boundary.
 *
 * @param array<int,mixed> $entries
 */
function payroll_insert_calculated_run_items(string $companyId,string $runId,string $periodStart,string $periodEnd,string $payDate,string $frequency,array $entries,int $validationStatus=400,int $unavailableStatus=400,?array $rateSnapshot=null): int
{
    if(count($entries)<1||count($entries)>250)fail('Choose between 1 and 250 employees.',$validationStatus,'payroll_employee_count_invalid');
    try{
        $rateSnapshot=payroll_validate_rate_release($rateSnapshot??payroll_rates_for_date($payDate,$companyId));
        payroll_assert_rate_date($rateSnapshot,$payDate);
    }catch(InvalidArgumentException $error){fail($error->getMessage(),422,'payroll_rate_release_required');}
    $periods=payroll_frequency_periods($frequency);
    $employeeStmt=db()->prepare('SELECT * FROM payroll_employees WHERE id=? AND company_id=? AND active=1 LIMIT 1');
    $insert=db()->prepare('INSERT INTO payroll_run_items (id,payroll_run_id,employee_id,regular_hours_milli,overtime_hours_milli,regular_pay_cents,overtime_pay_cents,additional_pay_cents,vacation_pay_cents,taxable_benefits_cents,gross_pay_cents,pensionable_pay_cents,insurable_pay_cents,employee_cpp_cents,employee_cpp2_cents,employee_ei_cents,estimated_income_tax_cents,other_deductions_cents,net_pay_cents,employer_cpp_cents,employer_cpp2_cents,employer_ei_cents,calculation_json,locked_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $seen=[];
    foreach($entries as $entry){
        if(!is_array($entry))fail('Employee payroll input is invalid.',$validationStatus,'payroll_employee_input_invalid');
        $employeeId=trim((string)($entry['employeeId']??''));
        if($employeeId===''||isset($seen[$employeeId]))fail('Each selected employee must appear exactly once.',$validationStatus,'payroll_employee_duplicate');
        $seen[$employeeId]=true;
        $employeeStmt->execute([$employeeId,$companyId]);$employee=$employeeStmt->fetch();
        if(!$employee)fail('A selected employee is unavailable.',$unavailableStatus,'payroll_employee_unavailable');
        if((string)$employee['pay_frequency']!==$frequency)fail('All employees in a run must use the selected frequency.',$validationStatus,'payroll_employee_frequency_mismatch');
        if((string)$employee['province_of_employment']==='QC')fail('Quebec payroll is blocked.',$validationStatus,'quebec_payroll_unsupported');
        if((string)$employee['hire_date']>$periodEnd)fail($employee['first_name'].' '.$employee['last_name'].' was not hired by this pay period.',$validationStatus,'payroll_employee_not_hired');
        if($employee['termination_date']!==null&&$periodStart>(string)$employee['termination_date'])fail($employee['first_name'].' '.$employee['last_name'].' was terminated before this pay period.',$validationStatus,'payroll_employee_terminated');
        $regularHours=array_key_exists('regularHours',$entry)?payroll_safe_hours_milli($entry['regularHours'],'Regular hours'):(int)$employee['standard_hours_milli'];
        $overtimeHours=payroll_safe_hours_milli($entry['overtimeHours']??0,'Overtime hours');
        $additional=payroll_nonnegative_cents($entry['additionalPayCents']??0,'Additional pay');
        $other=payroll_nonnegative_cents($entry['otherDeductionsCents']??0,'Other deductions');
        $regular=(string)$employee['pay_type']==='salary'
            ?payroll_round_nonnegative_ratio((int)$employee['annual_salary_cents'],$periods)
            :payroll_round_nonnegative_ratio((int)$employee['hourly_rate_cents']*$regularHours,1000);
        $overtime=(string)$employee['pay_type']==='hourly'
            ?payroll_round_nonnegative_ratio((int)$employee['hourly_rate_cents']*$overtimeHours*3,2000)
            :0;
        $vacation=(bool)$employee['vacation_paid_each_pay']?payroll_round_nonnegative_ratio(($regular+$overtime+$additional)*(int)$employee['vacation_rate_bps'],10_000):0;
        $gross=$regular+$overtime+$additional+$vacation;
        if($gross<=0)fail('Every employee must have positive gross pay.',422,'payroll_employee_zero_gross');
        $ytd=payroll_employee_ytd($companyId,$employeeId,$payDate);
        $cppAge=payroll_cpp_age_profile($employee['date_of_birth']!==null?(string)$employee['date_of_birth']:null,$payDate);
        $currentPensionable=!$employee['cpp_exempt']&&$cppAge['currentPayPensionable'];
        $currentInsurable=!$employee['ei_exempt'];
        $pensionablePay=$currentPensionable?$gross:0;
        $insurablePay=$currentInsurable?$gross:0;
        try{
            $calculation=payroll_calculate_2026(['companyId'=>$companyId,'payDate'=>$payDate,'province'=>$employee['province_of_employment'],'frequency'=>$frequency,'grossPayCents'=>$gross,'pensionablePayCents'=>$pensionablePay,'insurablePayCents'=>$insurablePay,'ytdPensionableCents'=>$ytd['pensionable'],'ytdCppCents'=>$ytd['cpp'],'ytdCpp2Cents'=>$ytd['cpp2'],'ytdEiCents'=>$ytd['ei'],'ytdEmployerEiCents'=>$ytd['employerEi'],'federalTd1Cents'=>$employee['federal_td1_cents'],'provincialTd1Cents'=>$employee['provincial_td1_cents'],'additionalTaxCents'=>$employee['additional_tax_cents'],'cppExempt'=>!$currentPensionable,'eiExempt'=>!$currentInsurable,'pensionableMonths'=>$cppAge['months']],$rateSnapshot);
        }catch(InvalidArgumentException $error){fail($error->getMessage(),422,'payroll_calculation_blocked');}
        $net=$gross-$calculation['employeeCppCents']-$calculation['employeeCpp2Cents']-$calculation['employeeEiCents']-$calculation['estimatedIncomeTaxCents']-$other;
        if($net<0)fail('Estimated deductions exceed gross pay for '.$employee['first_name'].' '.$employee['last_name'].'.',422,'payroll_deductions_exceed_gross');
        $item=['payroll_run_id'=>$runId,'employee_id'=>$employeeId,'regular_hours_milli'=>$regularHours,'overtime_hours_milli'=>$overtimeHours,'regular_pay_cents'=>$regular,'overtime_pay_cents'=>$overtime,'additional_pay_cents'=>$additional,'vacation_pay_cents'=>$vacation,'gross_pay_cents'=>$gross,'employee_cpp_cents'=>$calculation['employeeCppCents'],'employee_cpp2_cents'=>$calculation['employeeCpp2Cents'],'employee_ei_cents'=>$calculation['employeeEiCents'],'estimated_income_tax_cents'=>$calculation['estimatedIncomeTaxCents'],'verified_income_tax_cents'=>null,'other_deductions_cents'=>$other,'net_pay_cents'=>$net,'employer_cpp_cents'=>$calculation['employerCppCents'],'employer_cpp2_cents'=>$calculation['employerCpp2Cents'],'employer_ei_cents'=>$calculation['employerEiCents']];
        $insert->execute([new_id('payitem'),$runId,$employeeId,$regularHours,$overtimeHours,$regular,$overtime,$additional,$vacation,0,$gross,$pensionablePay,$insurablePay,$calculation['employeeCppCents'],$calculation['employeeCpp2Cents'],$calculation['employeeEiCents'],$calculation['estimatedIncomeTaxCents'],$other,$net,$calculation['employerCppCents'],$calculation['employerCpp2Cents'],$calculation['employerEiCents'],json_encode(['calculation'=>$calculation,'ytdBeforeRun'=>$ytd,'notice'=>'Income tax is an estimate until verified using the official deductions calculator.'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),payroll_item_integrity_hash($item)]);
    }
    return count($seen);
}

function handle_payroll_run_create(array $user, array $company): never
{
    require_method('POST'); require_csrf(); require_company_role($company, 'owner', 'bookkeeper');
    if (!payroll_settings_row((string)$company['id'])) fail('Enable payroll for this company first.', 409, 'payroll_not_enabled');
    $input = request_json();
    $periodStart = safe_date($input['periodStart'] ?? '', 'Period start');
    $periodEnd = safe_date($input['periodEnd'] ?? '', 'Period end');
    $payDate = safe_date($input['payDate'] ?? '', 'Pay date');
    if ($periodStart > $periodEnd || $periodEnd > $payDate) fail('Payroll dates must follow period start, period end, then pay date.');
    $frequency = (string)($input['frequency'] ?? 'biweekly'); payroll_frequency_periods($frequency);
    $entries = $input['employees'] ?? [];
    if (!is_array($entries) || count($entries) < 1 || count($entries) > 250) fail('Choose between 1 and 250 employees.');
    $operationKey=payroll_creation_operation_key($input['operationKey']??'');
    $requestHash=payroll_creation_request_hash($periodStart,$periodEnd,$payDate,$frequency,$entries);
    $existingStmt=db()->prepare('SELECT id,create_request_hash FROM payroll_runs WHERE company_id=? AND created_by=? AND create_operation_key=? LIMIT 1');
    $existingStmt->execute([$company['id'],$user['id'],$operationKey]);$existing=$existingStmt->fetch();
    if($existing){
        if(!hash_equals((string)$existing['create_request_hash'],$requestHash))fail('This Pay Run operation key was already used for different details.',409,'payroll_operation_key_conflict');
        json_response(['run'=>['id'=>(string)$existing['id']],'replayed'=>true],201);
    }
    $runId = new_id('payrun');
    db()->beginTransaction();
    try {
        try { $rateSet = payroll_rates_for_date($payDate, (string)$company['id']); } catch (InvalidArgumentException $error) { fail($error->getMessage(), 422, 'payroll_rate_release_required'); }
        $sequenceStmt=db()->prepare('SELECT COALESCE(MAX(run_sequence),0)+1 FROM payroll_runs WHERE company_id=? AND period_start=? AND period_end=? AND frequency=?');$sequenceStmt->execute([$company['id'],$periodStart,$periodEnd,$frequency]);$runSequence=(int)$sequenceStmt->fetchColumn();
        db()->prepare("INSERT INTO payroll_runs (id,company_id,period_start,period_end,pay_date,frequency,run_sequence,status,calculation_version,rate_snapshot_json,created_by,create_operation_key,create_request_hash) VALUES (?,?,?,?,?,?,?,'draft',?,?,?,?,?)")
            ->execute([$runId,$company['id'],$periodStart,$periodEnd,$payDate,$frequency,$runSequence,(string)$rateSet['version'],json_encode($rateSet,JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR),$user['id'],$operationKey,$requestHash]);
        $employeeCount=payroll_insert_calculated_run_items((string)$company['id'],$runId,$periodStart,$periodEnd,$payDate,$frequency,$entries,400,400,$rateSet);
        payroll_recalculate_run_totals($runId);
        $totalStmt=db()->prepare('SELECT gross_pay_cents FROM payroll_runs WHERE id=?');$totalStmt->execute([$runId]);$runGross=(int)$totalStmt->fetchColumn();
        $levyIncrement=payroll_run_levy_increment((string)$company['id'],$runId,$payDate,$runGross);
        db()->prepare('UPDATE payroll_runs SET employer_levy_cents=? WHERE id=?')->execute([$levyIncrement,$runId]);
        audit_event($user,(string)$company['id'],'payroll.run_created','payroll_run',$runId,['payDate'=>$payDate,'runSequence'=>$runSequence,'employeeCount'=>$employeeCount,'calculationVersion'=>(string)$rateSet['version']]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        if ($error instanceof PDOException && (string)$error->getCode()==='23000') {
            $existingStmt->execute([$company['id'],$user['id'],$operationKey]);$existing=$existingStmt->fetch();
            if($existing&&hash_equals((string)$existing['create_request_hash'],$requestHash))json_response(['run'=>['id'=>(string)$existing['id']],'replayed'=>true],201);
            if($existing)fail('This Pay Run operation key was already used for different details.',409,'payroll_operation_key_conflict');
            fail('The payroll run sequence conflicted with another request. Refresh and try once more.',409,'duplicate_payroll_run');
        }
        throw $error;
    }
    json_response(['run'=>['id'=>$runId]],201);
}

function handle_payroll_run_recalculate(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_role($company,'owner','bookkeeper');require_company_permission($company,'payroll.manage');
    if(!payroll_settings_row((string)$company['id']))fail('Enable payroll for this company first.',409,'payroll_not_enabled');
    $input=request_json();
    $runId=clean_text($input['runId']??'','Payroll run',64);
    $expectedRevision=strtolower(trim((string)($input['expectedRevision']??'')));
    if(!preg_match('/\A[0-9a-f]{64}\z/',$expectedRevision))fail('Refresh the Pay Run Register before recalculating this draft.',409,'payroll_draft_revision_required',false);
    $periodStart=safe_date($input['periodStart']??'','Period start');
    $periodEnd=safe_date($input['periodEnd']??'','Period end');
    $payDate=safe_date($input['payDate']??'','Pay date');
    if($periodStart>$periodEnd||$periodEnd>$payDate)fail('Payroll dates must follow period start, period end, then pay date.',422,'payroll_dates_invalid');
    $frequency=(string)($input['frequency']??'biweekly');payroll_frequency_periods($frequency);
    $entries=$input['employees']??[];
    if(!is_array($entries)||count($entries)<1||count($entries)>250)fail('Choose between 1 and 250 employees.',422,'payroll_employee_count_invalid');
    $operationKey=payroll_creation_operation_key($input['operationKey']??'');
    $requestHash=payroll_recalculation_request_hash($runId,$expectedRevision,$periodStart,$periodEnd,$payDate,$frequency,$entries);
    $payloadJson=json_encode(['runId'=>$runId,'expectedRevision'=>$expectedRevision,'requestHash'=>$requestHash],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $actionType='app:payroll.run_recalculate';
    $result=[];
    db()->beginTransaction();
    try{
        // The company row serializes operation-key lookup and insertion even though
        // the retained Schema 40 receipt table predates a unique operation index.
        $companyLock=db()->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');$companyLock->execute([$company['id']]);
        if($companyLock->fetchColumn()===false)fail('The selected company is unavailable.',403,'company_forbidden',false);
        $receiptStmt=db()->prepare("SELECT payload_hash,status,result_json FROM ai_agent_action_authorizations WHERE company_id=? AND user_id=? AND action_type=? AND operation_key=? ORDER BY created_at DESC,id DESC LIMIT 1");
        $receiptStmt->execute([$company['id'],$user['id'],$actionType,$operationKey]);$receipt=$receiptStmt->fetch();
        if($receipt){
            if(!hash_equals((string)$receipt['payload_hash'],$requestHash))fail('This recalculation operation key was already used for different Pay Run details.',409,'payroll_operation_key_conflict',false);
            $stored=json_decode((string)($receipt['result_json']??''),true);
            if((string)$receipt['status']!=='completed'||!is_array($stored))fail('The earlier recalculation is still unresolved. Check the Pay Run Register before trying again.',409,'payroll_recalculation_unresolved',false);
            $stored['replayed']=true;
            db()->commit();
            json_response($stored,200);
        }
        $runStmt=db()->prepare('SELECT * FROM payroll_runs WHERE id=? AND company_id=? FOR UPDATE');$runStmt->execute([$runId,$company['id']]);$run=$runStmt->fetch();
        if(!$run)fail('Payroll run not found.',404,'payroll_run_not_found',false);
        if((string)$run['status']!=='draft'||(string)$run['gl_status']!=='not_ready'||$run['accrual_journal_entry_id']!==null||$run['payment_journal_entry_id']!==null||$run['payment_date']!==null)fail('Only an unchanged draft Pay Run can be recalculated.',409,'payroll_draft_unavailable',false);
        $journalDraftStmt=db()->prepare("SELECT id FROM payroll_journal_drafts WHERE payroll_run_id=? AND company_id=? AND status='draft' LIMIT 1 FOR UPDATE");$journalDraftStmt->execute([$runId,$company['id']]);
        if($journalDraftStmt->fetchColumn()!==false)fail('This Pay Run already has prepared General Ledger work and cannot be recalculated.',409,'payroll_draft_has_gl_work',false);
        $itemStmt=db()->prepare('SELECT id,employee_id,locked_hash FROM payroll_run_items WHERE payroll_run_id=? ORDER BY employee_id,id FOR UPDATE');$itemStmt->execute([$runId]);$priorItems=$itemStmt->fetchAll();
        $actualRevision=payroll_run_edit_revision($run,$priorItems);
        if(!hash_equals($actualRevision,$expectedRevision))fail('This Pay Run changed after the editor was opened. Refresh the register and review the current draft.',409,'payroll_draft_stale',false);
        try{$rateSet=payroll_rates_for_date($payDate,(string)$company['id']);}catch(InvalidArgumentException $error){fail($error->getMessage(),422,'payroll_rate_release_required');}
        if((string)$run['period_start']===$periodStart&&(string)$run['period_end']===$periodEnd&&(string)$run['frequency']===$frequency){
            $runSequence=(int)$run['run_sequence'];
        }else{
            $sequenceStmt=db()->prepare('SELECT COALESCE(MAX(run_sequence),0)+1 FROM payroll_runs WHERE company_id=? AND period_start=? AND period_end=? AND frequency=? AND id<>?');$sequenceStmt->execute([$company['id'],$periodStart,$periodEnd,$frequency,$runId]);$runSequence=(int)$sequenceStmt->fetchColumn();
        }
        db()->prepare('DELETE FROM payroll_run_items WHERE payroll_run_id=?')->execute([$runId]);
        db()->prepare("UPDATE payroll_runs SET period_start=?,period_end=?,pay_date=?,frequency=?,run_sequence=?,status='draft',gl_status='not_ready',calculation_version=?,rate_snapshot_json=?,verification_reference=NULL,gross_pay_cents=0,employee_cpp_cents=0,employee_cpp2_cents=0,employee_ei_cents=0,income_tax_cents=0,other_deductions_cents=0,net_pay_cents=0,employer_cpp_cents=0,employer_cpp2_cents=0,employer_ei_cents=0,employer_levy_cents=0,approved_by=NULL,approved_at=NULL WHERE id=? AND company_id=?")
            ->execute([$periodStart,$periodEnd,$payDate,$frequency,$runSequence,(string)$rateSet['version'],json_encode($rateSet,JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR),$runId,$company['id']]);
        $employeeCount=payroll_insert_calculated_run_items((string)$company['id'],$runId,$periodStart,$periodEnd,$payDate,$frequency,$entries,422,404,$rateSet);
        if(config('testing.fail_after_payroll_recalculation_items')===true)throw new RuntimeException('Injected Payroll recalculation failure after item replacement.');
        payroll_recalculate_run_totals($runId);
        $totalStmt=db()->prepare('SELECT gross_pay_cents FROM payroll_runs WHERE id=? AND company_id=?');$totalStmt->execute([$runId,$company['id']]);$runGross=(int)$totalStmt->fetchColumn();
        $levyIncrement=payroll_run_levy_increment((string)$company['id'],$runId,$payDate,$runGross);
        db()->prepare('UPDATE payroll_runs SET employer_levy_cents=? WHERE id=? AND company_id=?')->execute([$levyIncrement,$runId,$company['id']]);
        $runStmt->execute([$runId,$company['id']]);$updatedRun=$runStmt->fetch();
        $itemStmt->execute([$runId]);$updatedItems=$itemStmt->fetchAll();
        $newRevision=payroll_run_edit_revision($updatedRun,$updatedItems);
        $result=['run'=>['id'=>$runId,'editRevision'=>$newRevision,'periodStart'=>$periodStart,'periodEnd'=>$periodEnd,'payDate'=>$payDate,'frequency'=>$frequency,'runSequence'=>$runSequence,'status'=>'draft','employeeCount'=>$employeeCount,'grossPayCents'=>(int)$updatedRun['gross_pay_cents'],'netPayCents'=>(int)$updatedRun['net_pay_cents'],'verificationReset'=>true],'replayed'=>false,'accountingWrites'=>0,'paymentsCreated'=>0,'remittancesCreated'=>0];
        audit_event($user,(string)$company['id'],'payroll.run_recalculated','payroll_run',$runId,['operationKeyHash'=>hash('sha256',$operationKey),'previousRevision'=>$expectedRevision,'resultingRevision'=>$newRevision,'payDate'=>$payDate,'runSequence'=>$runSequence,'employeeCount'=>$employeeCount,'calculationVersion'=>(string)$rateSet['version'],'accountingWrites'=>0]);
        db()->prepare("INSERT INTO ai_agent_action_authorizations (id,company_id,user_id,action_type,payload_json,payload_hash,status,expires_at,authorized_at,completed_at,operation_key,result_status,result_json) VALUES (?,?,?,?,?,?,'completed',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,'completed',?)")
            ->execute([new_id('payrecalc'),$company['id'],$user['id'],$actionType,$payloadJson,$requestHash,$operationKey,json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        db()->commit();
    }catch(Throwable $error){
        if(db()->inTransaction())db()->rollBack();
        if($error instanceof PDOException&&(string)$error->getCode()==='23000')fail('Another request changed this Pay Run. Refresh the register before trying again.',409,'payroll_draft_conflict',false);
        throw $error;
    }
    json_response($result,200);
}

function handle_payroll_verify(array $user, array $company): never
{
    require_method('PUT'); require_csrf(); require_company_role($company, 'owner', 'bookkeeper');
    $input = request_json(); $itemId = clean_text($input['itemId'] ?? '', 'Payroll item', 64);
    $verifiedTax = payroll_nonnegative_cents($input['verifiedIncomeTaxCents'] ?? null, 'Verified income tax');
    db()->beginTransaction();
    try {
        $stmt=db()->prepare("SELECT i.*,r.company_id,r.status FROM payroll_run_items i JOIN payroll_runs r ON r.id=i.payroll_run_id WHERE i.id=? AND r.company_id=? FOR UPDATE");
        $stmt->execute([$itemId,$company['id']]); $item=$stmt->fetch();
        if(!$item) fail('Payroll item not found.',404,'payroll_item_not_found');
        if($item['status']!=='draft') fail('Only a draft payroll run can be verified.',409,'payroll_run_locked');
        $net=(int)$item['gross_pay_cents']-(int)$item['employee_cpp_cents']-(int)$item['employee_cpp2_cents']-(int)$item['employee_ei_cents']-$verifiedTax-(int)$item['other_deductions_cents'];
        if($net<0) fail('Verified deductions exceed gross pay. Recheck the CRA result.');
        $item['verified_income_tax_cents']=$verifiedTax; $item['net_pay_cents']=$net;
        db()->prepare('UPDATE payroll_run_items SET verified_income_tax_cents=?,net_pay_cents=?,locked_hash=? WHERE id=?')->execute([$verifiedTax,$net,payroll_item_integrity_hash($item),$itemId]);
        payroll_recalculate_run_totals((string)$item['payroll_run_id']);
        audit_event($user,(string)$company['id'],'payroll.tax_verified','payroll_run_item',$itemId,['verifiedTaxCents'=>$verifiedTax]);
        db()->commit();
    } catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    json_response(['ok'=>true]);
}

function handle_payroll_finalize(array $user, array $company): never
{
    require_method('POST'); require_csrf(); require_company_role($company,'owner');
    $input=request_json(); if(($input['reviewConfirmed']??null)!==true) fail('Confirm "I have reviewed and verified the payroll calculations and statutory deductions." before finalizing the pay run.',422,'payroll_review_confirmation_required');
    $runId=clean_text($input['runId']??'','Payroll run',64);$reference=clean_text($input['verificationReference']??'','Verification reference',200);
    $settings=payroll_settings_row((string)$company['id']);if(!$settings)fail('Payroll is not enabled.',409,'payroll_not_enabled');$mode=payroll_company_posting_mode($company);
    $journal=null;$draftId=null;
    db()->beginTransaction();
    try{
        $stmt=db()->prepare("SELECT * FROM payroll_runs WHERE id=? AND company_id=? FOR UPDATE");$stmt->execute([$runId,$company['id']]);$run=$stmt->fetch();if(!$run)fail('Payroll run not found.',404,'payroll_run_not_found');if($run['status']!=='draft')fail('Only a draft run can be finalised.',409,'payroll_run_locked');
        try{
            payroll_assert_draft_rate_snapshot($run,payroll_rates_for_date((string)$run['pay_date'],(string)$company['id']));
        }catch(InvalidArgumentException $error){fail($error->getMessage(),409,'payroll_rate_snapshot_stale');}
        $stmt=db()->prepare('SELECT * FROM payroll_run_items WHERE payroll_run_id=? FOR UPDATE');$stmt->execute([$runId]);$items=$stmt->fetchAll();if(!$items)fail('Payroll run has no employees.');
        foreach($items as $item){if($item['verified_income_tax_cents']===null)fail('Every employee needs a verified income-tax amount.',409,'payroll_tax_verification_required');if(!hash_equals((string)$item['locked_hash'],payroll_item_integrity_hash($item)))throw new RuntimeException('Payroll item integrity check failed.');$evidence=json_decode((string)$item['calculation_json'],true);$expected=is_array($evidence)&&is_array($evidence['ytdBeforeRun']??null)?$evidence['ytdBeforeRun']:null;$current=payroll_employee_ytd((string)$company['id'],(string)$item['employee_id'],(string)$run['pay_date']);if($expected===null||array_map('intval',$expected)!==$current)fail('This draft is stale because finalised year-to-date payroll changed. Discard and rebuild it before posting.',409,'payroll_draft_stale');}
        payroll_recalculate_run_totals($runId);$stmt=db()->prepare('SELECT * FROM payroll_runs WHERE id=?');$stmt->execute([$runId]);$run=$stmt->fetch();
        $employer=(int)$run['employer_cpp_cents']+(int)$run['employer_cpp2_cents']+(int)$run['employer_ei_cents'];
        $lines=[['accountId'=>$settings['wages_account_id'],'debitCents'=>(int)$run['gross_pay_cents'],'creditCents'=>0,'memo'=>'Gross wages'],['accountId'=>$settings['employer_expense_account_id'],'debitCents'=>$employer,'creditCents'=>0,'memo'=>'Employer payroll contributions'],['accountId'=>$settings['tax_payable_account_id'],'debitCents'=>0,'creditCents'=>(int)$run['income_tax_cents'],'memo'=>'Employee income tax withheld'],['accountId'=>$settings['cpp_payable_account_id'],'debitCents'=>0,'creditCents'=>(int)$run['employee_cpp_cents']+(int)$run['employee_cpp2_cents']+(int)$run['employer_cpp_cents']+(int)$run['employer_cpp2_cents'],'memo'=>'Employee and employer pension contributions'],['accountId'=>$settings['ei_payable_account_id'],'debitCents'=>0,'creditCents'=>(int)$run['employee_ei_cents']+(int)$run['employer_ei_cents'],'memo'=>'Employee and employer insurance premiums'],['accountId'=>$settings['other_payable_account_id'],'debitCents'=>0,'creditCents'=>(int)$run['other_deductions_cents'],'memo'=>'Other deductions'],['accountId'=>$settings['net_pay_account_id'],'debitCents'=>0,'creditCents'=>(int)$run['net_pay_cents'],'memo'=>'Net pay payable']];
        $levy=payroll_employer_levy_for_annual_payroll((string)$company['id'],(string)$run['pay_date'],(int)$run['gross_pay_cents']);$levyAmount=(int)$run['employer_levy_cents'];
        if($levyAmount>0&&$levy['expenseAccountId']&&$levy['payableAccountId']&&!in_array($levy['postingMode'],['report_only','none'],true)){$lines[]=['accountId'=>$levy['expenseAccountId'],'debitCents'=>$levyAmount,'creditCents'=>0,'memo'=>'Employer payroll tax'];$lines[]=['accountId'=>$levy['payableAccountId'],'debitCents'=>0,'creditCents'=>$levyAmount,'memo'=>'Employer payroll tax payable'];}
        $lines=array_values(array_filter($lines,static fn(array $line):bool=>$line['debitCents']>0||$line['creditCents']>0));$memo=payroll_posting_memo($company,$run,$items);
        $lifecycleStatus='posted';$glStatus='not_applicable';
        if($mode==='automatic'){$journal=add_journal_entry($user,(string)$company['id'],(string)$run['pay_date'],'payroll_accrual',$runId,$memo,$lines);$glStatus='posted';}
        elseif($mode==='draft'){$draftId=new_id('payjdraft');db()->prepare("INSERT INTO payroll_journal_drafts (id,company_id,payroll_run_id,entry_date,memo,lines_json,status,created_by) VALUES (?,?,?,?,?,?,'draft',?)")->execute([$draftId,$company['id'],$runId,$run['pay_date'],$memo,json_encode($lines,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$user['id']]);$lifecycleStatus='verified';$glStatus='ready_to_post';}
        db()->prepare("UPDATE payroll_runs SET status=?,gl_status=?,verification_reference=?,accrual_journal_entry_id=?,approved_by=?,approved_at=UTC_TIMESTAMP(),gl_posted_by=?,gl_posted_at=? WHERE id=?")
            ->execute([$lifecycleStatus,$glStatus,$reference,$journal,$user['id'],$journal!==null?$user['id']:null,$journal!==null?gmdate('Y-m-d H:i:s'):null,$runId]);
        if($journal!==null&&function_exists('voucher_mark_posted'))voucher_mark_posted($user,(string)$company['id'],'payroll_run',$runId,$journal);
        audit_event($user,(string)$company['id'],'payroll.run_finalized','payroll_run',$runId,['journalEntryId'=>$journal,'draftJournalId'=>$draftId,'postingMode'=>$mode,'glStatus'=>$glStatus,'verificationReference'=>$reference,'employerLevyCents'=>$levyAmount,'reviewConfirmation'=>'I have reviewed and verified the payroll calculations and statutory deductions.']);db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    json_response(['journalEntryId'=>$journal,'draftJournalId'=>$draftId,'postingMode'=>$mode]);
}

function handle_payroll_post_gl(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_role($company,'owner');$input=request_json();$runId=clean_text($input['runId']??'','Payroll run',64);
    $journal=null;$alreadyPosted=false;db()->beginTransaction();
    try{
        $q=db()->prepare("SELECT * FROM payroll_runs WHERE id=? AND company_id=? FOR UPDATE");$q->execute([$runId,$company['id']]);$run=$q->fetch();if(!$run)fail('Payroll run not found.',404,'payroll_run_not_found');
        if((string)$run['gl_status']==='posted'&&$run['accrual_journal_entry_id']!==null){$journal=(string)$run['accrual_journal_entry_id'];$alreadyPosted=true;db()->commit();json_response(['journalEntryId'=>$journal,'glStatus'=>'posted','alreadyPosted'=>true]);}
        if((string)$run['gl_status']!=='ready_to_post'||(string)$run['status']!=='verified')fail('This pay run is not ready to post to the GL.',409,'payroll_gl_not_ready');
        $d=db()->prepare("SELECT * FROM payroll_journal_drafts WHERE payroll_run_id=? AND company_id=? AND status='draft' FOR UPDATE");$d->execute([$runId,$company['id']]);$draft=$d->fetch();if(!$draft)fail('The verified payroll journal draft is unavailable.',409,'payroll_gl_draft_missing');
        $lines=json_decode((string)$draft['lines_json'],true,512,JSON_THROW_ON_ERROR);if(!is_array($lines))throw new RuntimeException('Payroll journal draft is invalid.');
        $journal=add_journal_entry($user,(string)$company['id'],(string)$draft['entry_date'],'payroll_accrual',$runId,(string)$draft['memo'],$lines);
        db()->prepare("UPDATE payroll_journal_drafts SET status='posted',journal_entry_id=?,posted_by=?,posted_at=UTC_TIMESTAMP() WHERE id=? AND status='draft'")->execute([$journal,$user['id'],$draft['id']]);
        db()->prepare("UPDATE payroll_runs SET status='posted',gl_status='posted',accrual_journal_entry_id=?,gl_posted_by=?,gl_posted_at=UTC_TIMESTAMP() WHERE id=? AND gl_status='ready_to_post'")->execute([$journal,$user['id'],$runId]);
        if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,(string)$company['id'],'payroll_run',$runId,$journal);
        audit_event($user,(string)$company['id'],'payroll.gl_posted','payroll_run',$runId,['journalEntryId'=>$journal,'draftJournalId'=>(string)$draft['id']]);db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    json_response(['journalEntryId'=>$journal,'glStatus'=>'posted','alreadyPosted'=>$alreadyPosted]);
}

function handle_payroll_payment(array $user,array $company):never
{
    require_method('POST');require_csrf();require_company_role($company,'owner','bookkeeper');$input=request_json();$runId=clean_text($input['runId']??'','Payroll run',64);$date=safe_date($input['paymentDate']??'','Payment date');assert_not_future_date($date,'Payment date');$settings=payroll_settings_row((string)$company['id']);if(!$settings)fail('Payroll is not enabled.',409,'payroll_not_enabled');$journal=null;$bankId=trim((string)($input['bankAccountId']??''));
    db()->beginTransaction();try{
        $stmt=db()->prepare("SELECT * FROM payroll_runs WHERE id=? AND company_id=? FOR UPDATE");$stmt->execute([$runId,$company['id']]);$run=$stmt->fetch();if(!$run)fail('Payroll run not found.',404,'payroll_run_not_found');
        if((string)$run['status']==='paid'){db()->commit();json_response(['journalEntryId'=>$run['payment_journal_entry_id'],'alreadyRecorded'=>true]);}
        if((string)$run['status']!=='posted')fail('Post the verified pay run before recording payment.',409,'payroll_payment_not_ready');
        if(!in_array((string)$run['gl_status'],['posted','not_applicable'],true))fail('The pay run is not in a valid accounting posting state.',409,'payroll_gl_status_invalid');
        if($date<(string)$run['pay_date'])fail('Payment date cannot precede the payroll pay date.');if((int)$run['net_pay_cents']<=0)fail('This run has no positive net payment to record.',409,'payroll_zero_net_payment');
        if((string)$run['gl_status']==='posted'){
            $bankId=clean_text($bankId,'Bank account',64);$stmt=db()->prepare("SELECT ledger_account_id FROM bank_accounts WHERE id=? AND company_id=? AND account_type='bank' AND active=1");$stmt->execute([$bankId,$company['id']]);$bankLedger=$stmt->fetchColumn();if($bankLedger===false)fail('Choose an active bank account.');
            if(function_exists('voucher_register_saved'))voucher_register_saved($user,(string)$company['id'],'PP','PL','payroll_payment',$runId,$date,'Payroll bank payment through '.(string)$run['period_end'],(int)$run['net_pay_cents'],null,false);
            $journal=add_journal_entry($user,(string)$company['id'],$date,'payroll_payment',$runId,'Net payroll payment '.$run['period_end'],[['accountId'=>$settings['net_pay_account_id'],'debitCents'=>(int)$run['net_pay_cents'],'creditCents'=>0],['accountId'=>(string)$bankLedger,'debitCents'=>0,'creditCents'=>(int)$run['net_pay_cents']]]);
            if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,(string)$company['id'],'payroll_payment',$runId,$journal);
        }
        db()->prepare("UPDATE payroll_runs SET status='paid',payment_journal_entry_id=?,payment_date=? WHERE id=?")->execute([$journal,$date,$runId]);audit_event($user,(string)$company['id'],'payroll.payment_recorded','payroll_run',$runId,['journalEntryId'=>$journal,'bankAccountId'=>$bankId?:null,'glStatus'=>$run['gl_status']]);db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}json_response(['journalEntryId'=>$journal,'glStatus'=>$run['gl_status']??null]);
}

function handle_payroll_remittance(array $user,array $company):never
{
    require_method('POST');require_csrf();require_company_role($company,'owner','bookkeeper');
    $input=request_json();$settings=payroll_settings_row((string)$company['id']);if(!$settings)fail('Payroll is not enabled.',409,'payroll_not_enabled');
    $mode=payroll_company_posting_mode($company);$periodEnd=safe_date($input['periodEnd']??'','Remittance period end');$date=safe_date($input['paymentDate']??'','Payment date');assert_not_future_date($date,'Payment date');
    $bankId=trim((string)($input['bankAccountId']??''));$bankTransactionId=trim((string)($input['bankTransactionId']??''));
    $tax=payroll_nonnegative_cents($input['employeeTaxCents']??0,'Income tax remittance');$cpp=payroll_nonnegative_cents($input['cppCents']??0,'Pension-plan remittance');$ei=payroll_nonnegative_cents($input['eiCents']??0,'Employment-insurance remittance');$total=$tax+$cpp+$ei;if($total<=0)fail('Remittance total must be positive.');
    if($bankTransactionId!==''&&$mode==='none')fail('Bank-statement matching is unavailable while Payroll GL Posting is set to Keep Payroll Records Only. Change payroll posting setup or record the bank item separately.',409,'payroll_remittance_bank_match_requires_gl');
    $journal=null;$id=new_id('remit');
    db()->beginTransaction();try{
        $bankTransaction=null;
        if($bankTransactionId!==''){
            $tx=db()->prepare("SELECT bt.*,ba.ledger_account_id FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id WHERE bt.id=? AND bt.company_id=? AND bt.status='pending' FOR UPDATE");
            $tx->execute([$bankTransactionId,$company['id']]);$bankTransaction=$tx->fetch();if(!$bankTransaction)fail('Choose an available pending bank-statement withdrawal.',409,'payroll_remittance_bank_transaction_unavailable');
            if((int)$bankTransaction['amount_cents']>=0)fail('CRA payroll remittance must be matched to money leaving the bank.');
            if(abs((int)$bankTransaction['amount_cents'])!==$total)fail('The selected bank transaction must exactly equal the CRA remittance total. Adjust the remittance breakdown or choose a different bank transaction.');
            if((string)$bankTransaction['transaction_date']!==$date)fail('Payment date must match the selected bank transaction date.');
            if($bankId!==''&&$bankId!==(string)$bankTransaction['bank_account_id'])fail('The selected bank transaction belongs to a different bank account.');
            $bankId=(string)$bankTransaction['bank_account_id'];$bankLedger=(string)$bankTransaction['ledger_account_id'];
        }else{
            $bankId=clean_text($bankId,'Bank account',64);$stmt=db()->prepare("SELECT ledger_account_id FROM bank_accounts WHERE id=? AND company_id=? AND account_type='bank' AND active=1");$stmt->execute([$bankId,$company['id']]);$bankLedger=$stmt->fetchColumn();if($bankLedger===false)fail('Choose an active bank account.');
        }
        $latestStmt=db()->prepare("SELECT MAX(payment_date) FROM payroll_remittances WHERE company_id=? AND status='posted'");$latestStmt->execute([$company['id']]);$latest=$latestStmt->fetchColumn();if($latest!==false&&$latest!==null&&$date<(string)$latest)fail('Remittances must be recorded in date order. Reverse the later remittance first.',409,'payroll_remittance_out_of_order');
        if($mode!=='none'){
            $balanceStmt=db()->prepare("SELECT COALESCE(SUM(jl.credit_cents-jl.debit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.status='posted' AND jl.account_id=? AND je.entry_date<=?");
            foreach([[$settings['tax_payable_account_id'],$tax,'Income tax'],[$settings['cpp_payable_account_id'],$cpp,'Pension contributions'],[$settings['ei_payable_account_id'],$ei,'Employment insurance']] as [$accountId,$amount,$label]){$balanceStmt->execute([$company['id'],$accountId,$date]);if($amount>(int)$balanceStmt->fetchColumn())fail($label.' remittance exceeds the posted liability balance.',409,'payroll_remittance_exceeds_liability');}
            $lines=[];if($tax>0)$lines[]=['accountId'=>$settings['tax_payable_account_id'],'debitCents'=>$tax,'creditCents'=>0];if($cpp>0)$lines[]=['accountId'=>$settings['cpp_payable_account_id'],'debitCents'=>$cpp,'creditCents'=>0];if($ei>0)$lines[]=['accountId'=>$settings['ei_payable_account_id'],'debitCents'=>$ei,'creditCents'=>0];$lines[]=['accountId'=>(string)$bankLedger,'debitCents'=>0,'creditCents'=>$total];
            $journal=add_journal_entry($user,(string)$company['id'],$date,'payroll_remittance',$id,'Payroll remittance through '.$periodEnd,$lines);
        }
        db()->prepare('INSERT INTO payroll_remittances (id,company_id,period_end,payment_date,employee_tax_cents,cpp_cents,ei_cents,total_cents,bank_account_id,bank_transaction_id,journal_entry_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$company['id'],$periodEnd,$date,$tax,$cpp,$ei,$total,$bankId,$bankTransactionId!==''?$bankTransactionId:null,$journal,$user['id']]);
        if($bankTransactionId!==''&&$journal!==null){db()->prepare("UPDATE bank_transactions SET decided_account_id=NULL,tax_code='NO_TAX',suggestion_source='manual',status='posted',journal_entry_id=? WHERE id=? AND company_id=? AND status='pending'")->execute([$journal,$bankTransactionId,$company['id']]);if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,(string)$company['id'],'bank_transaction',$bankTransactionId,$journal);}
        audit_event($user,(string)$company['id'],'payroll.remittance_recorded','payroll_remittance',$id,['journalEntryId'=>$journal,'totalCents'=>$total,'postingMode'=>$mode,'bankTransactionId'=>$bankTransactionId!==''?$bankTransactionId:null]);db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    json_response(['remittance'=>['id'=>$id,'bankTransactionId'=>$bankTransactionId!==''?$bankTransactionId:null],'journalEntryId'=>$journal,'postingMode'=>$mode],201);
}

function handle_payroll_reverse(array $user,array $company):never
{
    require_method('POST');require_csrf();require_company_role($company,'owner');$input=request_json();$runId=clean_text($input['runId']??'','Payroll run',64);$date=safe_date($input['reversalDate']??'','Reversal date');assert_not_future_date($date,'Reversal date');
    db()->beginTransaction();try{$stmt=db()->prepare('SELECT * FROM payroll_runs WHERE id=? AND company_id=? FOR UPDATE');$stmt->execute([$runId,$company['id']]);$run=$stmt->fetch();if(!$run)fail('Payroll run not found.',404,'payroll_run_not_found');if(!in_array($run['status'],['posted','paid'],true))fail('Only posted or paid payroll can be reversed.',409,'payroll_reversal_unavailable');if($date<(string)$run['pay_date']||($run['payment_date']!==null&&$date<(string)$run['payment_date']))fail('Reversal date cannot precede the payroll or payment date.');$remitCheck=db()->prepare("SELECT COUNT(*) FROM payroll_remittances WHERE company_id=? AND status='posted' AND period_end>=?");$remitCheck->execute([$company['id'],$run['period_end']]);if((int)$remitCheck->fetchColumn()>0)fail('A posted payroll remittance may include this run. Reverse the applicable remittance first.',409,'payroll_remittance_reversal_required');if($run['payment_journal_entry_id'])require_journal_unmatched_before_reversal((string)$company['id'],(string)$run['payment_journal_entry_id'],'payroll payment');$reversals=[];if($run['payment_journal_entry_id'])$reversals[]=add_reversing_journal_entry($user,(string)$company['id'],(string)$run['payment_journal_entry_id'],$date,'payroll_payment_reversal',$runId,'Reverse net payroll payment '.$run['period_end']);if($run['accrual_journal_entry_id'])$reversals[]=add_reversing_journal_entry($user,(string)$company['id'],(string)$run['accrual_journal_entry_id'],$date,'payroll_accrual_reversal',$runId,'Reverse payroll '.$run['period_end']);db()->prepare("UPDATE payroll_journal_drafts SET status='discarded' WHERE payroll_run_id=? AND status='draft'")->execute([$runId]);db()->prepare("UPDATE payroll_runs SET status='reversed',gl_status='reversed',reversed_by=?,reversed_at=UTC_TIMESTAMP() WHERE id=?")->execute([$user['id'],$runId]);audit_event($user,(string)$company['id'],'payroll.run_reversed','payroll_run',$runId,['reversalJournalEntryIds'=>$reversals]);db()->commit();}catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}json_response(['reversalJournalEntryIds'=>$reversals]);
}

function handle_payroll_discard(array $user,array $company):never
{
    require_method('POST');require_csrf();require_company_role($company,'owner','bookkeeper');$input=request_json();$runId=clean_text($input['runId']??'','Payroll run',64);
    db()->beginTransaction();try{$stmt=db()->prepare("SELECT id FROM payroll_runs WHERE id=? AND company_id=? AND status='draft' FOR UPDATE");$stmt->execute([$runId,$company['id']]);if(!$stmt->fetch())fail('Only an available draft can be discarded.',409,'payroll_draft_unavailable');db()->prepare('DELETE FROM payroll_runs WHERE id=? AND company_id=?')->execute([$runId,$company['id']]);audit_event($user,(string)$company['id'],'payroll.draft_discarded','payroll_run',$runId,[]);db()->commit();}catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}json_response(['ok'=>true]);
}

function handle_payroll_delete(array $user,array $company):never
{
    require_method('POST');require_csrf();require_company_role($company,'owner');$input=request_json();
    $runId=clean_text($input['runId']??'','Payroll run',64);
    if((string)($input['confirmation']??'')!=='DELETE PAYROLL')fail('Enter DELETE PAYROLL exactly to confirm permanent deletion.',422,'payroll_delete_confirmation_required');
    $date=safe_date($input['deletionDate']??canadian_today(),'Deletion date');assert_not_future_date($date,'Deletion date');
    db()->beginTransaction();
    try{
        $stmt=db()->prepare('SELECT * FROM payroll_runs WHERE id=? AND company_id=? FOR UPDATE');$stmt->execute([$runId,$company['id']]);$run=$stmt->fetch();
        if(!$run)fail('Payroll run not found.',404,'payroll_run_not_found');
        if($date<(string)$run['pay_date']&&in_array((string)$run['status'],['posted','paid'],true))fail('Deletion date cannot precede the payroll pay date.');
        if($run['payment_date']!==null&&$date<(string)$run['payment_date'])fail('Deletion date cannot precede the payroll payment date.');
        $reversals=[];
        if(in_array((string)$run['status'],['posted','paid'],true)){
            $remitCheck=db()->prepare("SELECT COUNT(*) FROM payroll_remittances WHERE company_id=? AND status='posted' AND period_end>=?");
            $remitCheck->execute([$company['id'],$run['period_end']]);
            if((int)$remitCheck->fetchColumn()>0)fail('A posted payroll remittance may include this run. Reverse the applicable remittance before deleting the payroll run.',409,'payroll_remittance_reversal_required');
            if($run['payment_journal_entry_id'])require_journal_unmatched_before_reversal((string)$company['id'],(string)$run['payment_journal_entry_id'],'payroll payment');
            if($run['payment_journal_entry_id'])$reversals[]=add_reversing_journal_entry($user,(string)$company['id'],(string)$run['payment_journal_entry_id'],$date,'payroll_payment_delete_reversal',$runId,'Delete payroll run: reverse net payment through '.$run['period_end']);
            if($run['accrual_journal_entry_id'])$reversals[]=add_reversing_journal_entry($user,(string)$company['id'],(string)$run['accrual_journal_entry_id'],$date,'payroll_accrual_delete_reversal',$runId,'Delete payroll run: reverse payroll through '.$run['period_end']);
        }
        $itemCountStmt=db()->prepare('SELECT COUNT(*) FROM payroll_run_items WHERE payroll_run_id=?');$itemCountStmt->execute([$runId]);$itemCount=(int)$itemCountStmt->fetchColumn();
        db()->prepare("UPDATE payroll_journal_drafts SET status='discarded' WHERE payroll_run_id=? AND status='draft'")->execute([$runId]);
        if(function_exists('voucher_mark_void'))voucher_mark_void($user,(string)$company['id'],'payroll_run',$runId);
        db()->prepare('DELETE FROM payroll_runs WHERE id=? AND company_id=?')->execute([$runId,$company['id']]);
        audit_event($user,(string)$company['id'],'payroll.run_deleted','payroll_run',$runId,[
            'previousStatus'=>(string)$run['status'],'periodStart'=>(string)$run['period_start'],'periodEnd'=>(string)$run['period_end'],
            'payDate'=>(string)$run['pay_date'],'employeeCount'=>$itemCount,'grossPayCents'=>(int)$run['gross_pay_cents'],
            'netPayCents'=>(int)$run['net_pay_cents'],'deletionDate'=>$date,'reversalJournalEntryIds'=>$reversals,
        ]);
        db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    json_response(['deleted'=>true,'runId'=>$runId,'reversalJournalEntryIds'=>$reversals]);
}

function handle_payroll_remittance_reverse(array $user,array $company):never
{
    require_method('POST');require_csrf();require_company_role($company,'owner');$input=request_json();$id=clean_text($input['remittanceId']??'','Payroll remittance',64);$date=safe_date($input['reversalDate']??'','Reversal date');assert_not_future_date($date,'Reversal date');$journal=null;
    db()->beginTransaction();try{$stmt=db()->prepare("SELECT pr.* FROM payroll_remittances pr WHERE pr.id=? AND pr.company_id=? FOR UPDATE");$stmt->execute([$id,$company['id']]);$remittance=$stmt->fetch();if(!$remittance)fail('Payroll remittance not found.',404,'payroll_remittance_not_found');if($remittance['status']!=='posted')fail('Only a posted remittance can be reversed.',409,'payroll_remittance_reversal_unavailable');$latest=db()->prepare("SELECT id FROM payroll_remittances WHERE company_id=? AND status='posted' ORDER BY payment_date DESC,created_at DESC,id DESC LIMIT 1");$latest->execute([$company['id']]);if((string)$latest->fetchColumn()!==$id)fail('Reverse payroll remittances newest first.',409,'payroll_remittance_reverse_order');if($date<(string)$remittance['payment_date'])fail('Reversal date cannot precede the remittance payment date.');if($remittance['journal_entry_id'])require_journal_unmatched_before_reversal((string)$company['id'],(string)$remittance['journal_entry_id'],'payroll remittance');if($remittance['journal_entry_id'])$journal=add_reversing_journal_entry($user,(string)$company['id'],(string)$remittance['journal_entry_id'],$date,'payroll_remittance_reversal',$id,'Reverse payroll remittance through '.$remittance['period_end']);db()->prepare("UPDATE payroll_remittances SET status='reversed',reversal_journal_entry_id=? WHERE id=?")->execute([$journal,$id]);if(!empty($remittance['bank_transaction_id'])){db()->prepare("UPDATE bank_transactions SET status='pending',journal_entry_id=NULL,decided_account_id=NULL,tax_code='NO_TAX',suggestion_source='manual' WHERE id=? AND company_id=? AND journal_entry_id=?")->execute([$remittance['bank_transaction_id'],$company['id'],$remittance['journal_entry_id']]);}audit_event($user,(string)$company['id'],'payroll.remittance_reversed','payroll_remittance',$id,['reversalJournalEntryId'=>$journal]);db()->commit();}catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}json_response(['reversalJournalEntryId'=>$journal]);
}

function handle_payroll_t4(array $user,array $company):never
{
    require_method('GET');$year=(int)($_GET['year']??date('Y'));if($year<2020||$year>2100)fail('Year is invalid.');
    $stmt=db()->prepare("SELECT e.employee_number,e.first_name,e.last_name,
      COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN i.gross_pay_cents ELSE 0 END),0) employment_income_cents,
      COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN i.employee_cpp_cents ELSE 0 END),0) cpp_cents,
      COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN i.employee_cpp2_cents ELSE 0 END),0) cpp2_cents,
      COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN i.employee_ei_cents ELSE 0 END),0) ei_cents,
      COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN i.verified_income_tax_cents ELSE 0 END),0) income_tax_cents
      FROM payroll_employees e LEFT JOIN payroll_run_items i ON i.employee_id=e.id LEFT JOIN payroll_runs r ON r.id=i.payroll_run_id AND r.status IN ('posted','paid') AND YEAR(r.pay_date)=?
      WHERE e.company_id=? GROUP BY e.id,e.employee_number,e.first_name,e.last_name
      HAVING COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN i.gross_pay_cents ELSE 0 END),0)>0
      ORDER BY e.last_name,e.first_name");$stmt->execute([$year,$company['id']]);
    $rows=array_map(static fn(array $row):array=>['employeeNumber'=>$row['employee_number'],'employeeName'=>$row['first_name'].' '.$row['last_name'],'box14EmploymentIncomeCents'=>(int)$row['employment_income_cents'],'box16CppCents'=>(int)$row['cpp_cents'],'box16ACpp2Cents'=>(int)$row['cpp2_cents'],'box18EiCents'=>(int)$row['ei_cents'],'box22IncomeTaxCents'=>(int)$row['income_tax_cents']],$stmt->fetchAll());
    json_response(['year'=>$year,'companyId'=>$company['id'],'workingPaperOnly'=>true,'filingReady'=>false,'notice'=>'Review against CRA year-end guidance. This is not an official T4 slip, XML return, or filing submission.','employees'=>$rows]);
}

function handle_payroll(string $action): never
{
    $user=require_user();$company=require_company($user);
    require_company_permission($company,'payroll.view');
    payroll_purge_stored_sins($user,(string)$company['id']);
    if((string)$company['currency']!=='CAD')fail('Canadian payroll is available only when the company base currency is CAD. Use a separate CAD company file for payroll.',409,'payroll_requires_cad');
    if($action===''||$action==='workspace'){require_method('GET');json_response(['payroll'=>payroll_workspace_data($company)]);}
    if($action==='quick-calculate')handle_payroll_quick_calculate($user,$company);
    if($action==='setup')handle_payroll_setup($user,$company);
    if($action==='employees')handle_payroll_employee($user,$company);
    if($action==='runs')handle_payroll_run_create($user,$company);
    if($action==='runs/recalculate')handle_payroll_run_recalculate($user,$company);
    if($action==='verify')handle_payroll_verify($user,$company);
    if($action==='finalize')handle_payroll_finalize($user,$company);
    if($action==='post-gl')handle_payroll_post_gl($user,$company);
    if($action==='payment')handle_payroll_payment($user,$company);
    if($action==='remittances')handle_payroll_remittance($user,$company);
    if($action==='remittance-reverse')handle_payroll_remittance_reverse($user,$company);
    if($action==='reverse')handle_payroll_reverse($user,$company);
    if($action==='discard')handle_payroll_discard($user,$company);
    if($action==='delete')handle_payroll_delete($user,$company);
    if($action==='t4-working-paper')handle_payroll_t4($user,$company);
    fail('Payroll route not found.',404,'route_not_found');
}
