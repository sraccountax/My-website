<?php
declare(strict_types=1);

function default_chart(): array
{
    return [
        ['1000', 'Business Chequing', 'asset', 'debit', 1, '1002'],
        ['1050', 'Undeposited Funds', 'asset', 'debit', 1, '1001'],
        ['1070', 'Interbank Transfer Clearing', 'asset', 'debit', 0, null],
        ['1100', 'GST/HST Recoverable', 'asset', 'debit', 1, '1066'],
        ['1110', 'PST Recoverable', 'asset', 'debit', 1, '1066'],
        ['1115', 'QST Recoverable', 'asset', 'debit', 1, '1066'],
        ['1200', 'Accounts Receivable', 'asset', 'debit', 1, '1062'],
        ['1300', 'Prepaid Expenses', 'asset', 'debit', 0, '1484'],
        ['1500', 'Equipment', 'asset', 'debit', 0, '1740'],
        ['1590', 'Accumulated Depreciation', 'asset', 'credit', 0, '1741'],
        ['2000', 'Business Credit Card', 'liability', 'credit', 1, '2707'],
        ['2050', 'Accounts Payable', 'liability', 'credit', 1, '2621'],
        ['2100', 'GST/HST Payable', 'liability', 'credit', 1, '2680'],
        ['2110', 'PST Payable', 'liability', 'credit', 1, '2680'],
        ['2115', 'QST Payable', 'liability', 'credit', 1, '2680'],
        ['2200', 'Loans Payable', 'liability', 'credit', 0, '2700'],
        ['2300', 'Payroll Net Pay Payable', 'liability', 'credit', 1, '2624'],
        ['2310', 'Payroll Income Tax Payable', 'liability', 'credit', 1, '2628'],
        ['2320', 'CPP/QPP Payable', 'liability', 'credit', 1, '2627'],
        ['2330', 'Employment Insurance Payable', 'liability', 'credit', 1, '2627'],
        ['2340', 'Other Payroll Deductions Payable', 'liability', 'credit', 1, '2627'],
        ['2350', 'Employer Payroll Levy Payable', 'liability', 'credit', 1, '2620'],
        ['3000', 'Owner Capital / Share Capital', 'equity', 'credit', 0, '3500'],
        ['3100', 'Owner Draws / Shareholder Advances', 'equity', 'debit', 0, '3600'],
        ['3200', 'Retained Earnings', 'equity', 'credit', 1, '3600'],
        ['9999', 'Opening Balance Control', 'equity', 'credit', 1, null],
        ['4000', 'Service Revenue', 'income', 'credit', 0, '8000'],
        ['4100', 'Other Revenue', 'income', 'credit', 0, '8230'],
        ['6000', 'Cost of Sales', 'expense', 'debit', 0, '8518'],
        ['7000', 'Wages and Salaries', 'expense', 'debit', 0, '9060'],
        ['7010', 'Employer Payroll Contributions', 'expense', 'debit', 0, '8622'],
        ['7020', 'Employer Payroll Levy Expense', 'expense', 'debit', 0, '8762'],
        ['6100', 'Advertising and Promotion', 'expense', 'debit', 0, '8521'],
        ['6200', 'Software Subscriptions', 'expense', 'debit', 0, '9150'],
        ['6300', 'Telephone and Internet', 'expense', 'debit', 0, '9225'],
        ['6400', 'Office Supplies', 'expense', 'debit', 0, '8811'],
        ['6500', 'Travel', 'expense', 'debit', 0, '9200'],
        ['6600', 'Meals and Entertainment', 'expense', 'debit', 0, '8523'],
        ['6700', 'Professional Fees', 'expense', 'debit', 0, '8860'],
        ['6800', 'Bank Charges and Interest', 'expense', 'debit', 0, '8710'],
        ['6810', 'Depreciation Expense', 'expense', 'debit', 0, '8670'],
        ['6850', 'Foreign Exchange Gain / Loss', 'expense', 'debit', 0, '8231'],
        ['6900', 'Vehicle Expenses', 'expense', 'debit', 0, '9281'],
        ['6950', 'Insurance', 'expense', 'debit', 0, '8690'],
        ['6999', 'Unassigned Expense', 'expense', 'debit', 0, '9270'],
    ];
}

function company_fiscal_dates(array $input, string $fallbackMonthDay = '12-31', ?string $fallbackFullDate = null): array
{
    $full = trim((string)($input['fiscalYearEndDate'] ?? ''));
    $legacy = trim((string)($input['fiscalYearEnd'] ?? $fallbackMonthDay));
    if ($full === '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $legacy)) $full = $legacy;
    if ($full !== '') {
        $full = safe_date($full, 'Fiscal year-end');
        return [substr($full, 5, 5), $full];
    }
    if (!preg_match('/^(\d{2})-(\d{2})$/', $legacy, $match)
        || !checkdate((int)$match[1], (int)$match[2], 2000)) {
        fail('Choose a valid fiscal year-end date.');
    }
    if ($legacy === $fallbackMonthDay && $fallbackFullDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallbackFullDate)) {
        return [$legacy, $fallbackFullDate];
    }
    return [$legacy, date('Y') . '-' . $legacy];
}

function company_books_start_date(array $input, ?string $fallback = null): string
{
    $raw = trim((string)($input['booksStartDate'] ?? $fallback ?? date('Y-01-01')));
    if ($raw === '') $raw = date('Y-01-01');
    return safe_date($raw, 'Start of books');
}

function handle_companies(): never
{
    $user = require_user();
    if (request_method() === 'GET') {
        json_response(['companies' => companies_for_user((string)$user['id'])]);
    }
    require_method('POST');
    require_csrf();
    $input = request_json();
    $name = clean_text($input['name'] ?? '', 'Company name', 160);
    $legalName = clean_text($input['legalName'] ?? $name, 'Legal name', 200);
    $businessType = (string)($input['businessType'] ?? 'corporation');
    if (!in_array($businessType, ['sole_proprietor', 'corporation', 'partnership', 'non_profit'], true)) {
        fail('Business type is invalid.');
    }
    // R141: country and province/state. Canada needs one of its 13 provinces and
    // territories; elsewhere the state/province code is optional.
    tegh_regions_ready();
    $location = tegh_location($input['country'] ?? 'Canada', $input['province'] ?? 'ON', true, 'Province or state');
    $province = (string)($location['province'] ?? '');
    $country = $location['country'];
    $isCanada = $location['countryCode'] === 'CA';
    [$fiscalYearEnd, $fiscalYearEndDate] = company_fiscal_dates($input);
    $booksStartDate = company_books_start_date($input);
    $taxRegistered = !empty($input['taxRegistered']);
    $taxNumber = $taxRegistered ? optional_text($input['taxNumber'] ?? null, 40) : null;
    if ($taxRegistered && $taxNumber === null) {
        fail($isCanada ? 'GST/HST number is required when the company is registered for GST/HST.' : 'Enter the sales tax / VAT / GST registration number.', 422, 'tax_number_required');
    }
    $pstRegistered = $isCanada && !empty($input['pstRegistered']);
    $pstRateMpct = $pstRegistered ? pst_rate_mpct_from_input($input) : 0;
    $pstRateBps = (int)round($pstRateMpct / 10); // rounded copy for older readers
    $pstRecoverable = $pstRegistered && !empty($input['pstRecoverable']);
    $requestedCurrency = safe_currency_code($input['currency'] ?? 'CAD', 'Base currency');
    if ($requestedCurrency !== 'CAD') {
        fail('Tegh General Ledger uses CAD as the company reporting currency. Create the company in CAD, then add USD or other transaction currencies under Currency Exchange Rates.', 422, 'cad_gl_required');
    }
    $currency = 'CAD';
    $accountingBasis = (string)($input['accountingBasis'] ?? 'accrual');
    if (!in_array($accountingBasis, ['accrual', 'cash'], true)) fail('Accounting basis is invalid.');
    $moduleMode = (string)($input['moduleMode'] ?? 'both');
    if (!in_array($moduleMode, ['accounting','payroll','both'], true)) fail('Module selection is invalid.');
    // R141: Payroll Support calculates Canadian payroll only.
    if (!$isCanada && $moduleMode === 'payroll') fail('Payroll Support is for Canadian payroll only. Choose Accounting for a company outside Canada.', 422, 'payroll_canada_only');
    if (!$isCanada) $moduleMode = 'accounting';
    $payrollPostingMode = (string)($input['payrollPostingMode'] ?? 'draft');
    if (!in_array($payrollPostingMode, ['automatic','draft','none'], true)) fail('Payroll posting selection is invalid.');
    $taxReportingProfile = (string)($input['taxReportingProfile'] ?? ($businessType === 'sole_proprietor' ? 't2125' : ($businessType === 'corporation' ? 'gifi' : 'none')));
    if (!in_array($taxReportingProfile, ['gifi','t2125','none'], true)) fail('Tax reporting profile is invalid.');
    $reportingFramework = (string)($input['reportingFramework'] ?? 'not_set');
    if (!in_array($reportingFramework, ['not_set','aspe','ifrs','other'], true)) fail('Financial reporting framework is invalid.');
    $testMode = !empty($input['testMode']);
    if ($testMode && platform_role_for_user((string)$user['id']) !== 'platform_owner') {
        fail('Test companies can only be created by the platform owner.', 403, 'platform_owner_required');
    }
    $testExpiresAt = $testMode ? gmdate('Y-m-d H:i:s', time() + (4 * 86400)) : null;
    $coaMode = (string)($input['coaMode'] ?? 'default');
    // R159: a company that starts onboarding gets its chart of accounts and tax codes in onboarding steps 2 and 3, for
    // this company alone (import, template or by hand). It never starts with a pre-filled chart or starter tax codes.
    if (!empty($input['onboarding']) && empty($input['testMode'])) $coaMode = 'manual';
    if (!in_array($coaMode, ['default', 'manual'], true)) {
        fail('Chart of accounts option is invalid.');
    }
    // Spreadsheet COA/opening-balance imports are centralized under Settings → Data Import.
    $companyId = new_id('company');
    tegh_pst_rate_precision_ready();
    // R153: address and short name (columns added on first use, before the transaction).
    $profile = function_exists('r153_profile_input') ? r153_profile_input($input) : [];
    if ($profile) r153_schema_ready();
    $accountIds = [];
    $accountTypesByCode = [];
    db()->beginTransaction();
    try {
        $stmt = db()->prepare("INSERT INTO companies (id, name, legal_name, business_type, province, currency, accounting_basis, module_mode, payroll_posting_mode, tax_reporting_profile, reporting_framework, fiscal_year_end, fiscal_year_end_date, books_start_date, tax_registered, tax_number, tax_rate_bps, pst_registered, pst_rate_bps, pst_recoverable, test_mode, test_expires_at, test_created_by, country)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$companyId, $name, $legalName, $businessType, $province, $currency, $accountingBasis, $moduleMode, $payrollPostingMode, $taxReportingProfile, $reportingFramework, $fiscalYearEnd, $fiscalYearEndDate, $booksStartDate, $taxRegistered ? 1 : 0, $taxNumber, $isCanada ? province_rate_bps($province) : 0, $pstRegistered ? 1 : 0, $pstRateBps, $pstRecoverable ? 1 : 0, $testMode ? 1 : 0, $testExpiresAt, $testMode ? $user['id'] : null, $country]);
        db()->prepare('UPDATE companies SET pst_rate_mpct = ? WHERE id = ?')->execute([$pstRateMpct, $companyId]);
        if ($profile) r153_profile_save($companyId, $profile);
        // R137: new companies charge nothing until the owner sets up tax codes.
        if (function_exists('schema_column_exists') && schema_column_exists('companies', 'tax_setup_mode')) db()->prepare("UPDATE companies SET tax_setup_mode = 'codes' WHERE id = ?")->execute([$companyId]);
        db()->prepare("INSERT INTO company_members (company_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$companyId, $user['id']]);
        db()->prepare('INSERT INTO company_currencies (company_id, currency_code, rate_to_base_micros, rate_date) VALUES (?, ?, 1000000, CURRENT_DATE)')
            ->execute([$companyId, $currency]);
        db()->prepare('INSERT INTO accounting_controls (company_id) VALUES (?)')->execute([$companyId]);
        db()->prepare("INSERT INTO document_sequences (company_id, document_type, next_number) VALUES (?, 'invoice', 1001)")
            ->execute([$companyId]);
        if (schema_table_exists('voucher_sequences')) db()->prepare('INSERT INTO voucher_sequences (company_id,next_serial) VALUES (?,1)')->execute([$companyId]);
        $invoiceTemplateInsert = db()->prepare("INSERT INTO invoice_templates
            (id, company_id, name, is_default, document_title, accent_color, layout_style, business_address, payment_instructions, footer)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Payment is due by the date shown above.', ?)");
        $invoiceTemplatePresets = [
            ['Tegh Standard', 1, 'INVOICE', '#0D6B57', 'classic', 'Thank you for your business.'],
            ['Tegh Modern', 0, 'INVOICE', '#1F5EFF', 'modern', 'Thank you. We appreciate your business.'],
            ['Tegh Minimal', 0, 'INVOICE', '#111827', 'minimal', 'Thank you for your business.'],
            ['Tegh Professional', 0, 'TAX INVOICE', '#0B4F6C', 'professional', 'We appreciate your prompt payment.'],
            ['Tegh Compact', 0, 'INVOICE', '#6B4EFF', 'compact', 'Thank you.'],
        ];
        foreach ($invoiceTemplatePresets as [$templateName,$isDefault,$documentTitle,$accentColor,$layoutStyle,$templateFooter]) {
            $invoiceTemplateInsert->execute([new_id('invtemplate'), $companyId, $templateName, $isDefault, $documentTitle, $accentColor, $layoutStyle, trim(($province !== '' ? $province . ', ' : '') . $country), $templateFooter]);
        }
        $accountStmt = db()->prepare("INSERT INTO accounts (id, company_id, code, name, account_type, normal_balance, is_control, gifi_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $chartRows = [];
        if ($coaMode === 'default') {
            $chartRows = default_chart();
            if ($moduleMode === 'payroll') {
                $payrollCodes = ['1000','2000','2300','2310','2320','2330','2340','2350','3000','7000','7010','7020','9999'];
                $chartRows = array_values(array_filter($chartRows, static fn(array $row): bool => in_array($row[0], $payrollCodes, true)));
            }
        }
        // 'manual' leaves $chartRows empty - the company starts with no accounts
        // and the owner adds them from the Chart of Accounts screen.
        foreach ($chartRows as [$code, $accountName, $type, $normal, $control, $gifiCode]) {
            $accountId = new_id('account');
            $accountIds[$code] = $accountId;
            $accountTypesByCode[$code] = $type;
            $accountStmt->execute([$accountId, $companyId, $code, $accountName, $type, $normal, $control, $gifiCode]);
        }
        if (!isset($accountIds['9999'])) {
            $openingControlId=new_id('account');
            db()->prepare("INSERT INTO accounts (id,company_id,code,name,description,account_type,normal_balance,is_control,active) VALUES (?,?, '9999','Opening Balance Control','Tegh system control account used only by authorized opening-balance services.','equity','credit',1,1)")->execute([$openingControlId,$companyId]);
            $accountIds['9999']=$openingControlId;$accountTypesByCode['9999']='equity';
        }
        if (schema_table_exists('company_system_accounts')) {
            db()->prepare("INSERT INTO company_system_accounts (company_id,system_key,configured_code,account_id,status,conflict_message) VALUES (?, 'opening_balance_control','9999',?,'active',NULL)")->execute([$companyId,$accountIds['9999']]);
        }
        if (isset($accountIds['1000']) && ($accountTypesByCode['1000'] ?? '') === 'asset') {
            // Only auto-link a code-1000 account that is actually an asset -
            // in a manually created chart a user's own account could coincidentally use 1000
            // for something else, and it would be wrong to wire that up as
            // the company's bank account.
            $bankStmt = db()->prepare("INSERT INTO bank_accounts (id, company_id, ledger_account_id, name, account_type, masked_number, currency)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $bankStmt->execute([new_id('bank'), $companyId, $accountIds['1000'], 'Business Chequing', 'bank', optional_text($input['bankMaskedNumber'] ?? null, 30), $currency]);
            if ($moduleMode !== 'payroll' && isset($accountIds['2000']) && ($accountTypesByCode['2000'] ?? '') === 'liability') {
                $bankStmt->execute([new_id('bank'), $companyId, $accountIds['2000'], 'Business Credit Card', 'credit_card', null, $currency]);
            }
        }
        audit_event($user, $companyId, 'company.created', 'company', $companyId, [
            'name' => $name, 'province' => $province, 'country' => $country, 'currency' => $currency, 'accountingBasis' => $accountingBasis,
            'moduleMode' => $moduleMode, 'payrollPostingMode' => $payrollPostingMode, 'taxReportingProfile' => $taxReportingProfile, 'reportingFramework'=>$reportingFramework,
            'testMode' => $testMode, 'testExpiresAt' => $testExpiresAt,
            'booksStartDate' => $booksStartDate, 'fiscalYearEndDate' => $fiscalYearEndDate, 'coaMode' => $coaMode,
        ]);
        ensure_v6_seed_data($companyId);
        if(function_exists('tegh_company_entitlements_initialize'))tegh_company_entitlements_initialize($user,$companyId,$name);
        // R138: companies on the default chart start with editable Canadian tax codes.
        // Canadian starter tax codes only for Canadian companies (R141).
        if ($coaMode === 'default' && $isCanada && function_exists('tax_codes_seed_canada')) tax_codes_seed_canada($user, $companyId);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
    // R157: a company created through the app's company forms starts with onboarding (outside the transaction:
    // the onboarding table is created on first use).
    if (!empty($input['onboarding']) && function_exists('tegh_onboarding_start')) tegh_onboarding_start($user, $companyId);
    $companies = companies_for_user((string)$user['id']);
    $created = array_values(array_filter($companies, static fn(array $company): bool => $company['id'] === $companyId))[0] ?? null;
    json_response(['company' => $created, 'companies' => $companies, 'onboarding' => function_exists('tegh_onboarding_state') ? tegh_onboarding_state($companyId) : null], 201);
}

function handle_settings(): never
{
    require_method('PUT');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_permission($company, 'company.settings');
    $input = request_json();
    $name = clean_text($input['name'] ?? '', 'Company name', 160);
    $legalName = clean_text($input['legalName'] ?? $name, 'Legal name', 200);
    $businessType = (string)($input['businessType'] ?? $company['business_type']);
    if (!in_array($businessType, ['sole_proprietor', 'corporation', 'partnership', 'non_profit'], true)) {
        fail('Business type is invalid.');
    }
    tegh_regions_ready();
    $location = tegh_location($input['country'] ?? ($company['country'] ?? 'Canada'), $input['province'] ?? $company['province'], true, 'Province or state');
    $province = (string)($location['province'] ?? '');
    $country = $location['country'];
    $isCanada = $location['countryCode'] === 'CA';
    [$fiscal, $fiscalDate] = company_fiscal_dates(
        $input,
        (string)$company['fiscal_year_end'],
        $company['fiscal_year_end_date'] !== null ? (string)$company['fiscal_year_end_date'] : null
    );
    $booksStartDate = company_books_start_date(
        $input,
        $company['books_start_date'] !== null ? (string)$company['books_start_date'] : null
    );
    $taxRegistered = !empty($input['taxRegistered']);
    $taxNumber = $taxRegistered ? optional_text($input['taxNumber'] ?? null, 40) : null;
    if ($taxRegistered && $taxNumber === null) {
        fail($isCanada ? 'GST/HST number is required when the company is registered for GST/HST.' : 'Enter the sales tax / VAT / GST registration number.', 422, 'tax_number_required');
    }
    $pstRegistered = $isCanada && (array_key_exists('pstRegistered',$input) ? !empty($input['pstRegistered']) : (bool)($company['pst_registered'] ?? false));
    $pstRateMpct = $pstRegistered ? pst_rate_mpct_from_input($input, company_pst_rate_mpct($company)) : 0;
    $pstRateBps = (int)round($pstRateMpct / 10); // rounded copy for older readers
    $pstRecoverable = $pstRegistered && (array_key_exists('pstRecoverable',$input) ? !empty($input['pstRecoverable']) : (bool)($company['pst_recoverable'] ?? false));
    $currency = safe_currency_code($input['currency'] ?? $company['currency'], 'Base currency');
    if ((string)$company['currency'] === 'CAD' && $currency !== 'CAD') {
        fail('The Tegh General Ledger reporting currency is CAD. Add foreign transaction currencies under Currency Exchange Rates instead of changing the GL currency.', 422, 'cad_gl_required');
    }
    $accountingBasis = (string)($input['accountingBasis'] ?? $company['accounting_basis']);
    if (!in_array($accountingBasis, ['accrual', 'cash'], true)) fail('Accounting basis is invalid.');
    $payrollPostingMode = (string)($input['payrollPostingMode'] ?? $company['payroll_posting_mode']);
    if (!in_array($payrollPostingMode, ['automatic','draft','none'], true)) fail('Payroll posting selection is invalid.');
    $reportingFramework = (string)($input['reportingFramework'] ?? ($company['reporting_framework'] ?? 'not_set'));
    if (!in_array($reportingFramework, ['not_set','aspe','ifrs','other'], true)) fail('Financial reporting framework is invalid.');
    if (($currency !== (string)$company['currency'] || $accountingBasis !== (string)$company['accounting_basis'])
        && company_has_accounting_activity((string)$company['id'])) {
        fail('Base currency and accounting basis are locked after the first document, statement import, payroll setup, or journal entry. Create a new company file or migrate the books under professional supervision.', 409, 'fundamental_setting_locked');
    }
    tegh_pst_rate_precision_ready();
    $profile = function_exists('r153_profile_input') ? r153_profile_input($input) : [];
    if ($profile) r153_schema_ready();
    db()->beginTransaction();
    try {
        $previousCurrency = (string)$company['currency'];
        if ($profile) r153_profile_save((string)$company['id'], $profile);
        $stmt = db()->prepare('UPDATE companies SET name = ?, legal_name = ?, business_type = ?, province = ?, currency = ?, accounting_basis = ?, payroll_posting_mode = ?, reporting_framework = ?, fiscal_year_end = ?, fiscal_year_end_date = ?, books_start_date = ?, tax_registered = ?, tax_number = ?, tax_rate_bps = ?, pst_registered = ?, pst_rate_bps = ?, pst_recoverable = ?, country = ? WHERE id = ?');
        $stmt->execute([$name, $legalName, $businessType, $province, $currency, $accountingBasis, $payrollPostingMode, $reportingFramework, $fiscal, $fiscalDate, $booksStartDate, $taxRegistered ? 1 : 0, $taxNumber, $isCanada ? province_rate_bps($province) : 0, $pstRegistered ? 1 : 0, $pstRateBps, $pstRecoverable ? 1 : 0, $country, $company['id']]);
        db()->prepare('UPDATE companies SET pst_rate_mpct = ? WHERE id = ?')->execute([$pstRateMpct, $company['id']]);
        if ($currency !== $previousCurrency) {
            db()->prepare('UPDATE bank_accounts SET currency = ? WHERE company_id = ? AND currency = ?')
                ->execute([$currency, $company['id'], $previousCurrency]);
            db()->prepare('DELETE FROM company_currencies WHERE company_id = ? AND currency_code = ?')
                ->execute([$company['id'], $previousCurrency]);
        }
        db()->prepare("INSERT INTO company_currencies (company_id, currency_code, rate_to_base_micros, rate_date, active)
            VALUES (?, ?, 1000000, CURRENT_DATE, 1)
            ON DUPLICATE KEY UPDATE rate_to_base_micros = 1000000, rate_date = CURRENT_DATE, active = 1")
            ->execute([$company['id'], $currency]);
        audit_event($user, (string)$company['id'], 'company.updated', 'company', (string)$company['id'], [
            'province' => $province, 'country' => $country, 'businessType' => $businessType, 'taxRegistered' => $taxRegistered, 'pstRegistered'=>$pstRegistered, 'pstRateBps'=>$pstRateBps, 'pstRateMpct'=>$pstRateMpct, 'pstRecoverable'=>$pstRecoverable,
            'currency' => $currency, 'accountingBasis' => $accountingBasis, 'payrollPostingMode' => $payrollPostingMode, 'reportingFramework'=>$reportingFramework,
            'fiscalYearEndDate' => $fiscalDate, 'booksStartDate' => $booksStartDate,
        ]);
        db()->commit();
        $taxConfigurationChanged = $province !== (string)$company['province']
            || $taxRegistered !== (bool)$company['tax_registered']
            || province_rate_bps($province) !== (int)$company['tax_rate_bps']
            || $pstRegistered !== (bool)($company['pst_registered'] ?? false)
            || $pstRateMpct !== company_pst_rate_mpct($company)
            || $pstRecoverable !== (bool)($company['pst_recoverable'] ?? false);
        if (function_exists('tegh_ai_decay_rules_for_config_change')) {
            if ($taxConfigurationChanged) tegh_ai_decay_rules_for_config_change($user, $company, 'tax_configuration_changed');
            if ($reportingFramework !== (string)($company['reporting_framework'] ?? 'not_set')) tegh_ai_decay_rules_for_config_change($user, $company, 'reporting_framework_changed');
            if ($accountingBasis !== (string)$company['accounting_basis']) tegh_ai_decay_rules_for_config_change($user, $company, 'accounting_basis_changed');
            if ($fiscalDate !== (string)($company['fiscal_year_end_date'] ?? '')) tegh_ai_decay_rules_for_config_change($user, $company, 'fiscal_configuration_changed');
        }
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['organization' => ['id' => $company['id']]]);
}

/** Codes that ledger posting resolves by code (account_by_code); they must stay active, present and typed as shipped. */
function tegh_posting_required_account_codes(): array
{
    return ['1100','1110','1200','2000','2050','2100','2110','4000','6800','6850','9999'];
}

/**
 * Server-side guard for account changes. Returns a refusal message, or null when allowed.
 * $action: delete | deactivate | update. $change carries the requested code and type for update.
 */
function tegh_account_change_block_reason(array $row, string $action, array $change, bool $hasJournalLines): ?string
{
    $code = (string)($row['code'] ?? '');
    $protected = !empty($row['is_control']) || in_array($code, tegh_posting_required_account_codes(), true);
    if ($action === 'delete' && $protected) {
        return 'Account ' . $code . ' is a protected control account used by automatic posting and cannot be deleted.';
    }
    if ($action === 'deactivate' && $protected) {
        return 'Account ' . $code . ' is a protected control account used by automatic posting and cannot be deactivated.';
    }
    if ($action === 'update') {
        $newCode = (string)($change['code'] ?? $code);
        $newType = (string)($change['type'] ?? ($row['account_type'] ?? ''));
        if ($protected && $newCode !== $code) {
            return 'Account ' . $code . ' is a protected control account. Its code cannot be changed; you can still rename it.';
        }
        if ($protected && $newType !== (string)($row['account_type'] ?? '')) {
            return 'Account ' . $code . ' is a protected control account. Its type cannot be changed.';
        }
        if ($hasJournalLines && $newType !== (string)($row['account_type'] ?? '')) {
            return 'This account already has posted transactions, so its type cannot be changed. Create a new account instead.';
        }
    }
    return null;
}

function tegh_account_has_journal_lines(string $accountId): bool
{
    $q = db()->prepare('SELECT 1 FROM journal_lines WHERE account_id = ? LIMIT 1');
    $q->execute([$accountId]);
    return $q->fetchColumn() !== false;
}

function handle_accounts(): never
{
    require_method('POST', 'PATCH', 'DELETE');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();

    if (request_method() === 'DELETE') {
        $id = clean_text($input['id'] ?? '', 'Account', 64);
        $guard=db()->prepare('SELECT id,code,is_control,account_type FROM accounts WHERE id=? AND company_id=? LIMIT 1');$guard->execute([$id,$companyId]);$guardRow=$guard->fetch();
        if(!$guardRow) fail('Account not found.',404,'account_not_found');
        if(tegh_account_is_system_control($companyId,$id) || (string)$guardRow['code']==='9999') fail('Opening Balance Control is a protected Tegh system account and cannot be deleted.',409,'system_account_protected');
        $blocked = tegh_account_change_block_reason($guardRow, 'delete', [], false);
        if ($blocked !== null) fail($blocked, 409, 'system_account_protected');
        try {
            $stmt = db()->prepare('DELETE FROM accounts WHERE id = ? AND company_id = ?');
            $stmt->execute([$id, $companyId]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                fail('This account has transactions or other records against it and cannot be deleted. Deactivate it instead.', 409, 'account_in_use');
            }
            throw $error;
        }
        if ($stmt->rowCount() === 0) fail('Account not found.', 404, 'account_not_found');
        audit_event($user, $companyId, 'account.deleted', 'account', $id, []);
        if (function_exists('tegh_ai_decay_rules_for_config_change')) tegh_ai_decay_rules_for_config_change($user, $company, 'account_deleted', $id);
        json_response(['deleted' => true]);
    }

    if (request_method() === 'PATCH') {
        $id = clean_text($input['id'] ?? '', 'Account', 64);
        $guard=db()->prepare('SELECT id,code,is_control,account_type FROM accounts WHERE id=? AND company_id=? LIMIT 1');$guard->execute([$id,$companyId]);$guardRow=$guard->fetch();
        if(!$guardRow) fail('Account not found.',404,'account_not_found');
        if(tegh_account_is_system_control($companyId,$id) || (string)$guardRow['code']==='9999') fail('Opening Balance Control is a protected Tegh system account and cannot be edited or deactivated.',409,'system_account_protected');
        $action = (string)($input['action'] ?? 'update');

        if ($action === 'setActive') {
            $active = !empty($input['active']);
            if (!$active) {
                $blocked = tegh_account_change_block_reason($guardRow, 'deactivate', [], false);
                if ($blocked !== null) fail($blocked, 409, 'system_account_protected');
            }
            $stmt = db()->prepare('UPDATE accounts SET active = ? WHERE id = ? AND company_id = ?');
            $stmt->execute([$active ? 1 : 0, $id, $companyId]);
            if ($stmt->rowCount() === 0) {
                $exists = db()->prepare('SELECT 1 FROM accounts WHERE id = ? AND company_id = ?');
                $exists->execute([$id, $companyId]);
                if (!$exists->fetchColumn()) fail('Account not found.', 404, 'account_not_found');
            }
            audit_event($user, $companyId, $active ? 'account.reactivated' : 'account.deactivated', 'account', $id, []);
            if (!$active && function_exists('tegh_ai_decay_rules_for_config_change')) tegh_ai_decay_rules_for_config_change($user, $company, 'account_deactivated', $id);
            json_response(['account' => ['id' => $id, 'active' => $active]]);
        }

        if ($action !== 'update') fail('Unrecognized account action.');
        $code = strtoupper(clean_text($input['code'] ?? '', 'Account code', 20));
        if (!preg_match('/^[A-Z0-9.-]+$/', $code)) {
            fail('Account code may contain letters, numbers, dots, and hyphens only.');
        }
        if ($code === '9999') fail('Account code 9999 is reserved for Tegh Opening Balance Control.',409,'system_account_code_reserved');
        $name = clean_text($input['name'] ?? '', 'Account name', 160);
        $description = optional_text($input['description'] ?? null,500);
        $type = (string)($input['type'] ?? 'expense');
        if (!in_array($type, ['asset','liability','equity','income','expense'], true)) {
            fail('Account type is invalid.');
        }
        $normal = in_array($type, ['asset','expense'], true) ? 'debit' : 'credit';
        $gifiCode = optional_text($input['gifiCode'] ?? null, 10);
        if ($gifiCode !== null && !preg_match('/^\d{4}$/', $gifiCode)) fail('GIFI code must contain exactly four digits.');
        $blocked = tegh_account_change_block_reason($guardRow, 'update', ['code' => $code, 'type' => $type], tegh_account_has_journal_lines($id));
        if ($blocked !== null) fail($blocked, 409, 'account_change_blocked');
        try {
            $stmt = db()->prepare('UPDATE accounts SET code = ?, name = ?, description = ?, account_type = ?, normal_balance = ?, gifi_code = ? WHERE id = ? AND company_id = ?');
            $stmt->execute([$code, $name, $description, $type, $normal, $gifiCode, $id, $companyId]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                fail('That account code already exists.', 409, 'duplicate_account_code');
            }
            throw $error;
        }
        if ($stmt->rowCount() === 0) {
            $exists = db()->prepare('SELECT 1 FROM accounts WHERE id = ? AND company_id = ?');
            $exists->execute([$id, $companyId]);
            if (!$exists->fetchColumn()) fail('Account not found.', 404, 'account_not_found');
        }
        audit_event($user, $companyId, 'account.updated', 'account', $id, ['code' => $code, 'name' => $name, 'type' => $type, 'gifiCode' => $gifiCode]);
        if (function_exists('tegh_ai_decay_rules_for_config_change')) tegh_ai_decay_rules_for_config_change($user, $company, 'account_updated', $id);
        json_response(['account' => ['id' => $id, 'code' => $code, 'name' => $name, 'type' => $type, 'normalBalance' => $normal, 'gifiCode' => $gifiCode]]);
    }

    // POST: create (unchanged behaviour from prior releases)
    $code = strtoupper(clean_text($input['code'] ?? '', 'Account code', 20));
    if (!preg_match('/^[A-Z0-9.-]+$/', $code)) {
        fail('Account code may contain letters, numbers, dots, and hyphens only.');
    }
    if ($code === '9999') fail('Account code 9999 is reserved for Tegh Opening Balance Control.',409,'system_account_code_reserved');
    $name = clean_text($input['name'] ?? '', 'Account name', 160);
    $description = optional_text($input['description'] ?? null,500);
    $type = (string)($input['type'] ?? 'expense');
    if (!in_array($type, ['asset','liability','equity','income','expense'], true)) {
        fail('Account type is invalid.');
    }
    $normal = in_array($type, ['asset','expense'], true) ? 'debit' : 'credit';
    $gifiCode = optional_text($input['gifiCode'] ?? null, 10);
    if ($gifiCode !== null && !preg_match('/^\d{4}$/', $gifiCode)) fail('GIFI code must contain exactly four digits.');
    $id = new_id('account');
    try {
        db()->prepare('INSERT INTO accounts (id, company_id, code, name, description, account_type, normal_balance, is_control, gifi_code) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute([$id, $companyId, $code, $name, $description, $type, $normal, $gifiCode]);
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') {
            fail('That account code already exists.', 409, 'duplicate_account_code');
        }
        throw $error;
    }
    audit_event($user, $companyId, 'account.created', 'account', $id, ['code' => $code, 'name' => $name, 'type' => $type, 'gifiCode' => $gifiCode]);
    json_response(['account' => ['id' => $id, 'gifiCode' => $gifiCode]], 201);
}

/** Bank account profiles extend the legacy posting family without changing signs. */
function tegh_bank_profile(array $row): array
{
    $family = (string)($row['account_type'] ?? 'bank');
    $defaults = ['version'=>1,'accountSubtype'=>$family === 'bank' ? 'chequing' : 'credit_card',
        'institution'=>'','reportingClassification'=>$family === 'bank' ? 'current_asset' : 'current_liability',
        'creditLimitCents'=>null,'termsNote'=>'','repayableOnDemand'=>false,'integralCashManagement'=>false,
        'cashFlowOverdraftEligible'=>false];
    if (empty($row['profile_json'])) return $defaults;
    try { $stored = json_decode((string)$row['profile_json'], true, 32, JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { fail('This account has unreadable settings. Ask the company owner to review it.',409,'bank_profile_invalid'); }
    if (!is_array($stored) || ($stored['version'] ?? null) !== 1) fail('This account profile requires a compatible Tegh version.',409,'bank_profile_version');
    return array_merge($defaults, array_intersect_key($stored, $defaults));
}

function tegh_bank_profile_normalize(array $input, ?array $existing = null): array
{
    $prior = $existing ? tegh_bank_profile($existing) : [];
    $subtype = (string)($input['accountSubtype'] ?? $prior['accountSubtype'] ?? (($input['accountType'] ?? 'bank') === 'bank' ? 'chequing' : 'credit_card'));
    $families = ['chequing'=>'bank','savings'=>'bank','other_bank'=>'bank','credit_card'=>'credit_card',
        'line_of_credit'=>'credit_card','overdraft'=>'credit_card','other_credit'=>'credit_card'];
    if (!isset($families[$subtype])) fail('Choose a supported bank or credit account type.',422,'bank_subtype_invalid');
    $family = $families[$subtype];
    if (isset($input['accountType']) && (string)$input['accountType'] !== $family) fail('The account type and accounting family do not agree.',422,'bank_family_invalid');
    $classification = (string)($input['reportingClassification'] ?? $prior['reportingClassification'] ?? ($family==='bank'?'current_asset':'current_liability'));
    if (!in_array($classification, $family==='bank'?['current_asset','non_current_asset']:['current_liability','non_current_liability'], true)) fail('Choose a reporting category that matches this account.',422,'bank_classification_invalid');
    $limit = $input['creditLimitCents'] ?? $prior['creditLimitCents'] ?? null;
    if (array_key_exists('creditLimitCents',$input) && $input['creditLimitCents'] === null) $limit=null;
    if ($limit!==null && (!is_int($limit) || $limit<0 || $limit>9000000000000000 || $family!=='credit_card')) fail('Credit limit must be a non-negative amount in cents for a credit account.',422,'bank_credit_limit_invalid');
    $booleans=[];
    foreach(['repayableOnDemand','integralCashManagement','cashFlowOverdraftEligible'] as $key){
        $value=$input[$key]??$prior[$key]??false;
        if(!is_bool($value))fail('Account policy selections must be true or false.',422,'bank_policy_invalid');
        $booleans[$key]=$value;
    }
    $terms=trim((string)($input['termsNote']??$prior['termsNote']??''));
    if(strlen($terms)>2000)fail('Keep the account terms within 2,000 characters.',422,'bank_terms_too_long');
    if($classification==='non_current_liability' && ($booleans['repayableOnDemand'] || $terms===''))fail('Non-current borrowing needs documented terms and cannot be payable on demand.',422,'bank_terms_required');
    if($booleans['cashFlowOverdraftEligible'] && ($subtype!=='overdraft'||!$booleans['repayableOnDemand']||!$booleans['integralCashManagement']||$terms===''))fail('Cash-flow overdraft treatment needs an on-demand overdraft, integral cash management and documented terms.',422,'bank_overdraft_policy_invalid');
    $institution=trim((string)($input['institution']??$prior['institution']??''));
    if(strlen($institution)>160)fail('Keep the institution name within 160 characters.',422,'bank_institution_too_long');
    return ['accountType'=>$family,'profile'=>array_merge(['version'=>1,'accountSubtype'=>$subtype,'institution'=>$institution,
        'reportingClassification'=>$classification,'creditLimitCents'=>$limit,'termsNote'=>$terms],$booleans)];
}

/** Includes cascading foreign keys: an unused-account delete must not erase evidence. */
function tegh_bank_references(array $bank): array
{
    $db=db();$references=[];
    $q=$db->prepare("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND ((REFERENCED_TABLE_NAME='bank_accounts' AND REFERENCED_COLUMN_NAME='id') OR (REFERENCED_TABLE_NAME='accounts' AND REFERENCED_COLUMN_NAME='id'))");
    $q->execute();
    foreach($q->fetchAll() as $ref){
        $table=(string)$ref['TABLE_NAME'];$column=(string)$ref['COLUMN_NAME'];$target=(string)$ref['REFERENCED_TABLE_NAME'];
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table)||!preg_match('/^[a-zA-Z0-9_]+$/D',$column))throw new RuntimeException('Unexpected foreign-key identifier.');
        // The selected bank-to-ledger relationship is the one we intentionally remove.
        $sql="SELECT COUNT(*) FROM `$table` WHERE `$column`=?";
        $args=[$target==='bank_accounts'?$bank['id']:$bank['ledger_account_id']];
        if($table==='bank_accounts' && $target==='accounts'){$sql.=' AND id<>?';$args[]=$bank['id'];}
        $check=$db->prepare($sql);$check->execute($args);$count=(int)$check->fetchColumn();
        if($count)$references[$table.'.'.$column]=$count;
    }
    if((int)($bank['statement_balance_cents']??0)!==0)$references['statementBalance']=1;
    if(!empty($bank['last_reconciled_date']))$references['reconciliationHistory']=1;
    // Prepared workflows use JSON and do not necessarily have foreign keys.
    foreach([['voucher_draft_payloads','payload_json'],['ai_agent_action_authorizations','payload_json'],['ai_agent_plans','plan_json']] as [$table,$column]){
        if(!schema_table_exists($table)||!schema_column_exists($table,$column))continue;
        $check=$db->prepare("SELECT COUNT(*) FROM `$table` WHERE company_id=? AND (LOCATE(?,`$column`)>0 OR LOCATE(?,`$column`)>0)");
        $check->execute([$bank['company_id'],json_encode((string)$bank['id']),json_encode((string)$bank['ledger_account_id'])]);
        if($count=(int)$check->fetchColumn())$references[$table.'.'.$column]=$count;
    }
    return $references;
}

function tegh_bank_public(array $row): array
{
    $profile=tegh_bank_profile($row);
    $balance=(int)($row['ledger_balance_cents']??0);
    $debt=$row['account_type']==='credit_card'?max(0,-$balance):0;
    return array_merge(['id'=>(string)$row['id'],'name'=>(string)$row['name'],'accountType'=>(string)$row['account_type'],
        'maskedNumber'=>$row['masked_number'],'currency'=>(string)$row['currency'],'active'=>(bool)$row['active'],
        'ledgerAccountId'=>(string)$row['ledger_account_id'],'ledgerCode'=>(string)($row['ledger_code']??''),
        'ledgerBalanceCents'=>$balance,'ledgerBalanceCurrency'=>(string)($row['base_currency']??$row['currency']),
        'statementBalanceCents'=>(int)$row['statement_balance_cents'],'lastReconciledDate'=>$row['last_reconciled_date'],
        'latestStatementBalanceCents'=>$row['latest_statement_balance_cents']===null?null:(int)$row['latest_statement_balance_cents'],
        'latestStatementDate'=>$row['latest_statement_date']?:null,'latestStatementSource'=>$row['latest_statement_source']?:null,
        // Cross-currency facilities require a same-currency ledger conversion; do not mix units.
        'availableCreditCents'=>($profile['creditLimitCents']!==null && ($row['base_currency']??$row['currency'])===$row['currency'])?max(0,$profile['creditLimitCents']-$debt):null],$profile);
}

function tegh_bank_list(string $companyId): array
{
    $q=db()->prepare("SELECT ba.*,a.code AS ledger_code,c.currency AS base_currency,COALESCE(t.balance,0) AS ledger_balance_cents,
      (SELECT r.source_closing_cents FROM bank_import_control_receipts r WHERE r.company_id=ba.company_id AND r.bank_account_id=ba.id AND r.source_closing_cents IS NOT NULL AND r.coverage_end IS NOT NULL ORDER BY r.coverage_end DESC,r.created_at DESC,r.id DESC LIMIT 1) latest_statement_balance_cents,
      (SELECT r.coverage_end FROM bank_import_control_receipts r WHERE r.company_id=ba.company_id AND r.bank_account_id=ba.id AND r.source_closing_cents IS NOT NULL AND r.coverage_end IS NOT NULL ORDER BY r.coverage_end DESC,r.created_at DESC,r.id DESC LIMIT 1) latest_statement_date,
      (SELECT COALESCE(NULLIF(ib.filename,''),'Approved Import Evidence') FROM bank_import_control_receipts r LEFT JOIN import_batches ib ON ib.id=r.import_batch_id AND ib.company_id=r.company_id WHERE r.company_id=ba.company_id AND r.bank_account_id=ba.id AND r.source_closing_cents IS NOT NULL AND r.coverage_end IS NOT NULL ORDER BY r.coverage_end DESC,r.created_at DESC,r.id DESC LIMIT 1) latest_statement_source
      FROM bank_accounts ba JOIN accounts a ON a.id=ba.ledger_account_id AND a.company_id=ba.company_id JOIN companies c ON c.id=ba.company_id LEFT JOIN (SELECT jl.account_id,SUM(jl.debit_cents-jl.credit_cents) balance FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.status='posted' GROUP BY jl.account_id) t ON t.account_id=ba.ledger_account_id WHERE ba.company_id=? ORDER BY ba.active DESC,ba.name,ba.id");
    $q->execute([$companyId,$companyId]);return array_map('tegh_bank_public',$q->fetchAll());
}

function tegh_bank_mutate(array $user,array $company,string $method,array $input): array
{
    $pdo=db();$companyId=(string)$company['id'];$pdo->beginTransaction();
    try{
        // Serializes account creation/mapping changes for this company, including duplicate retries.
        $guard=$pdo->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');$guard->execute([$companyId]);
        $existing=null;$bankId='';
        if($method!=='POST'){
            $bankId=clean_text($input['id']??$input['bankAccountId']??'','Bank account',64);
            $q=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? FOR UPDATE');$q->execute([$bankId,$companyId]);$existing=$q->fetch();
            if(!$existing)fail('Bank account not found.',404,'bank_account_not_found');
            $lock=$pdo->prepare('SELECT id FROM accounts WHERE id=? AND company_id=? FOR UPDATE');$lock->execute([$existing['ledger_account_id'],$companyId]);
        }
        if($method==='DELETE'){
            if(tegh_bank_references($existing))fail('This account has records or a balance. Make it inactive to preserve its history.',409,'bank_account_in_use');
            $pdo->prepare('DELETE FROM bank_accounts WHERE id=? AND company_id=?')->execute([$bankId,$companyId]);
            // Keep the ledger account: deleting a banking profile never deletes accounting history.
            audit_event($user,$companyId,'bank_account.deleted','bank_account',$bankId,['name'=>$existing['name'],'ledgerAccountId'=>$existing['ledger_account_id']]);
            $pdo->commit();return ['deleted'=>true,'id'=>$bankId,'ledgerPreserved'=>true];
        }
        if($method==='PATCH' && ($input['action']??'update')==='setActive'){
            if(!isset($input['active'])||!is_bool($input['active']))fail('Choose whether this account is active.',422,'bank_active_invalid');
            $pdo->prepare('UPDATE bank_accounts SET active=? WHERE id=? AND company_id=?')->execute([(int)$input['active'],$bankId,$companyId]);
            audit_event($user,$companyId,$input['active']?'bank_account.reactivated':'bank_account.inactivated','bank_account',$bankId, ['active'=>$input['active']]);
            $pdo->commit();return ['bankAccount'=>['id'=>$bankId,'active'=>$input['active']]];
        }
        if($method==='PATCH' && !in_array((string)($input['action']??'update'),['update'],true))fail('This bank-account action is unavailable.',422,'bank_action_invalid');
        $normalized=tegh_bank_profile_normalize($input,$existing?:null);$profile=$normalized['profile'];$family=$normalized['accountType'];
        $name=clean_text($input['name']??$existing['name']??'','Account name',160);
        $currency=safe_currency_code($input['currency']??$existing['currency']??$company['currency'],'Account currency');
        if(!company_currency($companyId,$currency))fail('Add that currency to the company before using it here.',422,'bank_currency_unavailable');
        $masked=optional_text($input['maskedNumber']??$existing['masked_number']??null,30);
        // Store only a masked identifier or last four digits, never a full banking number.
        if($masked!==null && preg_match('/\d{5,}/',preg_replace('/[\s-]/','',$masked)))fail('Enter only the last four digits or a masked account identifier.',422,'bank_number_not_masked');
        $ledgerId=trim((string)($input['ledgerAccountId']??$existing['ledger_account_id']??''));
        $ledgerType=$family==='bank'?'asset':'liability';$normal=$family==='bank'?'debit':'credit';
        if($existing){
            $prior=tegh_bank_profile($existing);$sensitive=['accountSubtype','reportingClassification','cashFlowOverdraftEligible','repayableOnDemand','integralCashManagement'];
            $changed=$currency!==$existing['currency']||$ledgerId!==$existing['ledger_account_id']||$family!==$existing['account_type'];
            foreach($sensitive as $key)if($prior[$key]!==$profile[$key])$changed=true;
            if($changed && tegh_bank_references($existing))fail('This account has history. Its currency, ledger mapping and reporting treatment need a separate accounting review; its name and institution can still be edited.',409,'bank_mapping_in_use');
        }
        if($ledgerId!==''){
            $q=$pdo->prepare('SELECT * FROM accounts WHERE id=? AND company_id=? FOR UPDATE');$q->execute([$ledgerId,$companyId]);$ledger=$q->fetch();
            if(!$ledger||!(bool)$ledger['active']||$ledger['account_type']!==$ledgerType||$ledger['normal_balance']!==$normal)fail('Choose an active ledger account with the correct asset/liability and normal-balance type.',422,'bank_ledger_invalid');
            $q=$pdo->prepare('SELECT id FROM bank_accounts WHERE company_id=? AND ledger_account_id=? AND id<>? LIMIT 1');$q->execute([$companyId,$ledgerId,$bankId]);
            if($q->fetchColumn())fail('That ledger account is already linked to another bank or credit account.',409,'bank_ledger_already_linked');
            if((!$existing || $ledgerId!==$existing['ledger_account_id']) && ((bool)$ledger['is_control'] || $ledger['code']==='9999'))fail('Choose an unused non-control ledger account, or create a new ledger code.',422,'bank_ledger_control');
        }else{
            $code=strtoupper(clean_text($input['ledgerCode']??'','New ledger code',20));
            if(!preg_match('/^[A-Z0-9.-]+$/D',$code)||$code==='9999')fail('Choose a valid, unreserved ledger code.',422,'bank_ledger_code_invalid');
            $ledgerId=new_id('account');
            $pdo->prepare('INSERT INTO accounts (id,company_id,code,name,account_type,normal_balance,is_control) VALUES (?,?,?,?,?,?,1)')->execute([$ledgerId,$companyId,$code,$name,$ledgerType,$normal]);
        }
        $json=json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if($method==='POST'){
            $bankId=new_id('bank');
            $pdo->prepare('INSERT INTO bank_accounts (id,company_id,ledger_account_id,name,account_type,masked_number,currency,profile_json) VALUES (?,?,?,?,?,?,?,?)')->execute([$bankId,$companyId,$ledgerId,$name,$family,$masked,$currency,$json]);
            audit_event($user,$companyId,'bank_account.created','bank_account',$bankId,['name'=>$name,'type'=>$family,'currency'=>$currency,'profile'=>$profile,'ledgerAccountId'=>$ledgerId]);
        }else{
            $pdo->prepare('UPDATE bank_accounts SET ledger_account_id=?,name=?,account_type=?,masked_number=?,currency=?,profile_json=? WHERE id=? AND company_id=?')->execute([$ledgerId,$name,$family,$masked,$currency,$json,$bankId,$companyId]);
            audit_event($user,$companyId,'bank_account.updated','bank_account',$bankId,['name'=>$name,'profile'=>$profile,'ledgerAccountId'=>$ledgerId,'currency'=>$currency]);
        }
        $pdo->commit();return ['bankAccount'=>['id'=>$bankId,'accountType'=>$family,'accountSubtype'=>$profile['accountSubtype']]];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        if($error instanceof PDOException && (string)$error->getCode()==='23000')fail('That ledger code is already used, or this account has linked records. Choose another code or make the account inactive.',409,'bank_account_conflict');
        throw $error;
    }
}

function handle_bank_accounts(): never
{
    require_method('GET','POST','PATCH','DELETE');$user=require_user();$company=require_company($user);
    if(request_method()==='GET'){
        require_company_permission($company,'banking.view');
        json_response(['bankAccounts'=>tegh_bank_list((string)$company['id']),'canManage'=>in_array((string)$company['role'],['owner','admin','editor','bookkeeper'],true)]);
    }
    require_csrf();require_company_role($company,'owner','bookkeeper');
    if(!schema_column_exists('bank_accounts','profile_json'))fail('Ask the Platform Owner to complete the protected database upgrade before editing bank accounts.',503,'bank_profile_upgrade_required');
    json_response(tegh_bank_mutate($user,$company,request_method(),request_json()),request_method()==='POST'?201:200);
}
