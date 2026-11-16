<?php
declare(strict_types=1);

/*
 * IN-2: India master data. Extends the existing masters of an India-edition company; nothing here applies to a Canadian
 * company (every route refuses unless the company's edition is IN).
 *
 *  - India profile of an existing record (company, customer, vendor, item, account, asset, bank account): one versioned
 *    row per effective date in india_entity_profiles. A change never overwrites history; it adds a version.
 *  - India records with several rows per company or per record (GST registrations, addresses, TDS certificates,
 *    employees, pay components, warehouses...): india_master_records, effective-dated; an edit supersedes the old row.
 *  - Statutory rule masters: the existing statutory_rates table, jurisdiction 'IN' (or 'IN-<state>'), category in
 *    person_type, versioned by effective date, draft until an owner or admin approves. Tegh seeds no rates.
 *
 * Field definitions (types, choices, patterns, references) are served to the browser from the same spec the server
 * validates with. Every write is audited with the old and new values.
 */

const IN2_RULE_CATEGORIES = [
    'gst_rate' => 'GST rate', 'gst_cess' => 'GST compensation cess', 'hsn_sac' => 'HSN / SAC classification', 'rcm_rule' => 'Reverse charge rule',
    'itc_rule' => 'ITC eligibility / reversal class', 'tds_section' => 'TDS section', 'tcs_section' => 'TCS section', 'it_regime' => 'Income-tax regime',
    'it_slab' => 'Income-tax slab', 'it_surcharge' => 'Surcharge', 'it_rebate' => 'Rebate', 'it_exemption' => 'Tax exemption', 'chapter_via_section' => 'Chapter VI-A deduction',
    'depreciation_block' => 'Depreciation block (Income-tax)', 'msme_rule' => 'MSME payment rule', 'section_43b' => 'Section 43B classification',
    'section_40a' => 'Section 40 / 40A classification', 'itr_map' => 'ITR schedule line', 'form_3cd_clause' => 'Form 3CD clause', 'schedule3_line' => 'Schedule III line',
    'due_date' => 'Statutory due date (compliance calendar)', 'accounting_standard' => 'Accounting standard', 'pt_slab' => 'Professional Tax slab',
    'lwf_rate' => 'Labour Welfare Fund rate', 'epf_rate' => 'EPF rate', 'esic_rate' => 'ESIC rate', 'related_party_relationship' => 'Related-party relationship',
    'income_classification' => 'Income classification', 'loan_deposit_class' => 'Loan / deposit classification',
    'gst_return_limit' => 'GST return limit (e.g. B2CL: B2C large invoice value)',
];

const IN2_GST_REG_TYPES = ['regular' => 'Regular', 'composition' => 'Composition', 'casual' => 'Casual taxable person', 'sez_unit' => 'SEZ unit', 'sez_developer' => 'SEZ developer',
    'isd' => 'Input Service Distributor', 'tds_registrant' => 'TDS registrant', 'tcs_registrant' => 'TCS registrant (e-commerce operator)', 'non_resident' => 'Non-resident taxable person',
    'oidar' => 'OIDAR service provider', 'uin' => 'UIN holder', 'unregistered' => 'Unregistered'];

/** Field spec: key => [label, type, extra]. Types: text, date, number, percent, money, bool, select (choices), ref (rule:/record:/entity:), multi. */
function in2_specs(): array
{
    static $s = null; if ($s !== null) return $s;
    $states = []; foreach (TEGH_INDIA_STATES as $code => [$name]) $states[$code] = $name; asort($states);
    $yn = ['pattern' => null];
    $gstin = ['pattern' => 'gstin']; $pan = ['pattern' => 'pan'];
    $s = [
        'profiles' => [
            'company' => ['label' => 'Company', 'fields' => [
                'businessActivity' => ['Business activity', 'text', ['max' => 200]],
                'iec' => ['Importer-Exporter Code (IEC)', 'text', ['pattern' => 'iec']],
                'udyamNumber' => ['Udyam registration number', 'text', ['pattern' => 'udyam']],
                'msmeCategory' => ['MSME category', 'select', ['choices' => ['' => 'Not MSME', 'micro' => 'Micro', 'small' => 'Small', 'medium' => 'Medium']]],
                'incomeTaxRegime' => ['Income-tax regime', 'ref', ['ref' => 'rule:it_regime']],
                'taxAuditApplicable' => ['Tax audit applicable', 'bool', []],
                'accountingStandard' => ['Accounting standard', 'ref', ['ref' => 'rule:accounting_standard']],
            ]],
            'customer' => ['label' => 'Customer', 'fields' => in2_party_fields()],
            'vendor' => ['label' => 'Vendor', 'fields' => in2_party_fields() + [
                'tdsSection' => ['TDS section (payments to this vendor)', 'ref', ['ref' => 'rule:tds_section']],
                'jobWorker' => ['Job worker', 'bool', []],
                'transporter' => ['Transporter', 'bool', []],
            ]],
            'item' => ['label' => 'Product or service', 'fields' => [
                'hsnSac' => ['HSN (goods) or SAC (services) code', 'text', ['pattern' => 'hsnsac']],
                'uqc' => ['Unit quantity code (UQC)', 'text', ['pattern' => 'uqc']],
                'gstRate' => ['GST rate rule', 'ref', ['ref' => 'rule:gst_rate']],
                'cess' => ['Cess rule', 'ref', ['ref' => 'rule:gst_cess']],
                'gstTreatment' => ['GST treatment', 'select', ['choices' => in2_gst_treatments()]],
                'rcmApplicable' => ['Reverse charge applies', 'bool', []],
                'rcmRule' => ['Reverse charge rule', 'ref', ['ref' => 'rule:rcm_rule']],
                'itcClass' => ['ITC eligibility / reversal class', 'ref', ['ref' => 'rule:itc_rule']],
                'capitalGoods' => ['Capital goods', 'bool', []],
                'itrMapping' => ['Income-tax (ITR) mapping', 'ref', ['ref' => 'rule:itr_map']],
                'taxAuditClause' => ['Tax audit (Form 3CD) clause', 'ref', ['ref' => 'rule:form_3cd_clause']],
                'importExport' => ['Import / export', 'select', ['choices' => ['' => 'Neither', 'import' => 'Imported', 'export' => 'Exported', 'both' => 'Both']]],
                'customsTariff' => ['Customs tariff heading', 'text', ['pattern' => 'digits8']],
                'valuationMethod' => ['Inventory valuation method', 'select', ['choices' => ['' => 'Not tracked', 'fifo' => 'FIFO', 'weighted_average' => 'Weighted average', 'specific' => 'Specific identification']]],
                'trackBatch' => ['Track batches', 'bool', []], 'trackSerial' => ['Track serial numbers', 'bool', []], 'trackExpiry' => ['Track expiry dates', 'bool', []],
                'defaultWarehouse' => ['Default warehouse', 'ref', ['ref' => 'record:warehouse']],
            ]],
            'account' => ['label' => 'General Ledger account', 'fields' => [
                'schedule3Line' => ['Schedule III line', 'ref', ['ref' => 'rule:schedule3_line']],
                'itrSchedule' => ['ITR schedule and line', 'ref', ['ref' => 'rule:itr_map']],
                'form3cdClause' => ['Form 3CD clause', 'ref', ['ref' => 'rule:form_3cd_clause']],
                'gstTreatment' => ['GST treatment', 'select', ['choices' => in2_gst_treatments()]],
                'itcClass' => ['ITC class', 'ref', ['ref' => 'rule:itc_rule']],
                'tdsSection' => ['TDS section', 'ref', ['ref' => 'rule:tds_section']],
                'tcsSection' => ['TCS section', 'ref', ['ref' => 'rule:tcs_section']],
                'section40' => ['Section 40 / 40A classification', 'ref', ['ref' => 'rule:section_40a']],
                'section43b' => ['Section 43B classification', 'ref', ['ref' => 'rule:section_43b']],
                'section14a' => ['Relates to exempt income (section 14A)', 'bool', []],
                'section269' => ['Loans, deposits and cash (269SS / 269ST / 269T)', 'select', ['choices' => ['' => 'Not applicable', '269SS' => '269SS - loans or deposits taken', '269ST' => '269ST - cash receipts', '269T' => '269T - repayment of loans or deposits']]],
                'depreciationBlock' => ['Depreciation block', 'ref', ['ref' => 'rule:depreciation_block']],
                'controlAccount' => ['Control account', 'bool', []],
                'reconciliationRequired' => ['Reconcile regularly', 'bool', []],
                'incomeClassification' => ['Income classification', 'ref', ['ref' => 'rule:income_classification']],
            ]],
            'asset' => ['label' => 'Fixed asset', 'fields' => [
                'depreciationBlock' => ['Income-tax depreciation block', 'ref', ['ref' => 'rule:depreciation_block']],
                'additionalDepreciation' => ['Eligible for additional depreciation', 'bool', []],
                'itcAdjustmentCents' => ['ITC claimed (deducted from cost for income tax)', 'money', []],
                'subsidyAdjustmentCents' => ['Subsidy / grant reducing cost', 'money', []],
                'supplierVendor' => ['Supplier', 'ref', ['ref' => 'entity:vendor']],
            ]],
            'bank_account' => ['label' => 'Bank account', 'fields' => [
                'ifsc' => ['IFSC', 'text', ['pattern' => 'ifsc']],
                'bankName' => ['Bank name', 'text', ['max' => 120]], 'branch' => ['Branch', 'text', ['max' => 120]],
                'accountHolder' => ['Account holder name', 'text', ['max' => 160]],
                'accountKind' => ['Account type', 'select', ['choices' => ['' => 'Choose', 'current' => 'Current', 'savings' => 'Savings', 'cash_credit' => 'Cash credit', 'overdraft' => 'Overdraft', 'fixed_deposit' => 'Fixed deposit', 'eefc' => 'EEFC']]],
                'swift' => ['SWIFT / BIC', 'text', ['pattern' => 'swift']], 'upiId' => ['UPI ID', 'text', ['pattern' => 'upi']],
            ]],
        ],
        'records' => [
            'gst_registration' => ['label' => 'GST registration', 'entities' => ['company', 'customer', 'vendor'], 'fields' => [
                'gstin' => ['GSTIN', 'text', ['pattern' => 'gstin', 'required' => true]],
                'registrationType' => ['Registration type', 'select', ['choices' => IN2_GST_REG_TYPES, 'required' => true]],
                'status' => ['Status', 'select', ['choices' => ['active' => 'Active', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'], 'required' => true]],
                'validFrom' => ['Valid from', 'date', ['required' => true]], 'cancelledOn' => ['Cancelled on', 'date', []],
                'tradeName' => ['Trade name', 'text', ['max' => 200]], 'address' => ['Principal place of business', 'text', ['max' => 300]],
            ]],
            'address' => ['label' => 'Address', 'entities' => ['customer', 'vendor'], 'fields' => [
                'addressType' => ['Address type', 'select', ['choices' => ['billing' => 'Billing', 'shipping' => 'Shipping', 'branch' => 'Branch', 'other' => 'Other'], 'required' => true]],
                'line1' => ['Address line 1', 'text', ['max' => 200, 'required' => true]], 'line2' => ['Address line 2', 'text', ['max' => 200]],
                'city' => ['City', 'text', ['max' => 100]], 'state' => ['State or union territory', 'select', ['choices' => ['' => 'Outside India'] + $states]],
                'pin' => ['PIN code', 'text', ['pattern' => 'pin']], 'country' => ['Country', 'text', ['max' => 80]],
                'gstin' => ['GSTIN at this address', 'text', ['pattern' => 'gstin']],
            ]],
            'tds_certificate' => ['label' => 'Lower / nil deduction certificate', 'entities' => ['vendor', 'customer'], 'fields' => [
                'section' => ['Section', 'ref', ['ref' => 'rule:tds_section', 'required' => true]],
                'certificateNumber' => ['Certificate number', 'text', ['max' => 40, 'required' => true]],
                'ratePercent' => ['Rate on the certificate (%)', 'percent', ['required' => true]],
                'limitCents' => ['Amount limit on the certificate', 'money', []],
                'validFrom' => ['Valid from', 'date', ['required' => true]], 'validTo' => ['Valid to', 'date', ['required' => true]],
            ]],
            'employee' => ['label' => 'Employee', 'entities' => [], 'fields' => [
                'employeeCode' => ['Employee code', 'text', ['max' => 30, 'required' => true]], 'name' => ['Name', 'text', ['max' => 160, 'required' => true]],
                'pan' => ['PAN', 'text', ['pattern' => 'pan']], 'taxRegime' => ['Income-tax regime', 'ref', ['ref' => 'rule:it_regime']],
                'residency' => ['Residential status', 'select', ['choices' => ['resident' => 'Resident', 'non_resident' => 'Non-resident']]],
                'dateOfJoining' => ['Date of joining', 'date', ['required' => true]], 'dateOfLeaving' => ['Date of leaving', 'date', []],
                'uan' => ['UAN (EPF)', 'text', ['pattern' => 'digits12']], 'epfApplicable' => ['EPF applies', 'bool', []],
                'esicNumber' => ['ESIC insurance number', 'text', ['pattern' => 'digits10to17']], 'esicApplicable' => ['ESIC applies', 'bool', []],
                'ptApplicable' => ['Professional Tax applies', 'bool', []], 'ptState' => ['Professional Tax state', 'select', ['choices' => ['' => 'None'] + $states]],
                'lwfApplicable' => ['Labour Welfare Fund applies', 'bool', []], 'lwfState' => ['LWF state', 'select', ['choices' => ['' => 'None'] + $states]],
                'gratuityEligible' => ['Gratuity eligible', 'bool', []], 'leaveEncashmentEligible' => ['Leave encashment eligible', 'bool', []],
                'bankIfsc' => ['Salary bank IFSC', 'text', ['pattern' => 'ifsc']], 'bankAccountLast4' => ['Salary account (last 4 digits)', 'text', ['pattern' => 'digits4']],
            ]],
            'pay_component' => ['label' => 'Pay component', 'entities' => [], 'fields' => [
                'name' => ['Name', 'text', ['max' => 80, 'required' => true]],
                'kind' => ['Kind', 'select', ['choices' => ['earning' => 'Earning / allowance', 'deduction' => 'Deduction', 'employer_contribution' => 'Employer contribution', 'reimbursement' => 'Reimbursement'], 'required' => true]],
                'calculation' => ['Calculation', 'select', ['choices' => ['fixed' => 'Fixed amount', 'percent' => 'Percent of another component', 'rule' => 'From a statutory rule (slab)', 'entered' => 'Entered each pay run'], 'required' => true]],
                'percent' => ['Percent', 'percent', []], 'baseComponent' => ['Percent of component', 'ref', ['ref' => 'record:pay_component']],
                'rule' => ['Statutory rule', 'ref', ['ref' => 'rule:pt_slab|epf_rate|esic_rate|lwf_rate']],
                'taxable' => ['Taxable', 'bool', []], 'exemption' => ['Exemption rule', 'ref', ['ref' => 'rule:it_exemption']],
                'glAccount' => ['GL account (expense for earnings and employer contributions)', 'ref', ['ref' => 'entity:account']],
                'payableAccount' => ['Payable account (liability for deductions and employer contributions)', 'ref', ['ref' => 'entity:account']],
            ]],
            'chapter_via_declaration' => ['label' => 'Chapter VI-A declaration', 'entities' => [], 'fields' => [
                'employee' => ['Employee', 'ref', ['ref' => 'record:employee', 'required' => true]],
                'financialYear' => ['Financial year (e.g. 2026-27)', 'text', ['pattern' => 'fy', 'required' => true]],
                'section' => ['Section', 'ref', ['ref' => 'rule:chapter_via_section', 'required' => true]],
                'declaredCents' => ['Declared amount', 'money', ['required' => true]],
            ]],
            'payment_method' => ['label' => 'Payment method', 'entities' => [], 'fields' => [
                'name' => ['Name', 'text', ['max' => 80, 'required' => true]],
                'channel' => ['Channel', 'select', ['choices' => ['cash' => 'Cash', 'cheque_account_payee' => 'Account payee cheque', 'cheque_bearer' => 'Bearer cheque', 'demand_draft' => 'Account payee demand draft', 'electronic' => 'Electronic clearing (NEFT, RTGS, IMPS, UPI, card, ECS)'], 'required' => true]],
                'bankingChannel' => ['Counts as a banking channel', 'bool', []],
                'bankAccount' => ['Bank account', 'ref', ['ref' => 'entity:bank_account']],
            ]],
            'warehouse' => ['label' => 'Warehouse', 'entities' => [], 'fields' => [
                'code' => ['Code', 'text', ['max' => 20, 'required' => true]], 'name' => ['Name', 'text', ['max' => 120, 'required' => true]],
                'address' => ['Address', 'text', ['max' => 300]], 'state' => ['State or union territory', 'select', ['choices' => ['' => 'Choose'] + $states]],
                'gstin' => ['GSTIN of this place of business', 'text', ['pattern' => 'gstin']],
            ]],
            'location' => ['label' => 'Storage location', 'entities' => [], 'fields' => [
                'warehouse' => ['Warehouse', 'ref', ['ref' => 'record:warehouse', 'required' => true]], 'code' => ['Code', 'text', ['max' => 20, 'required' => true]], 'name' => ['Name', 'text', ['max' => 120]],
            ]],
            'transporter' => ['label' => 'Transporter', 'entities' => [], 'fields' => [
                'name' => ['Name', 'text', ['max' => 160, 'required' => true]], 'transporterId' => ['Transporter ID (GSTIN or TRANSIN)', 'text', ['pattern' => 'transporterid']],
                'vendor' => ['Vendor record', 'ref', ['ref' => 'entity:vendor']],
                'defaultMode' => ['Usual mode', 'select', ['choices' => ['' => 'Choose', 'road' => 'Road', 'rail' => 'Rail', 'air' => 'Air', 'ship' => 'Ship or ship-cum-road/rail']]],
            ]],
            'vehicle' => ['label' => 'Vehicle', 'entities' => [], 'fields' => [
                'number' => ['Vehicle number', 'text', ['pattern' => 'vehicle', 'required' => true]], 'transporter' => ['Transporter', 'ref', ['ref' => 'record:transporter']],
                'vehicleType' => ['Type', 'select', ['choices' => ['regular' => 'Regular', 'odc' => 'Over-dimensional cargo']]],
            ]],
            'port' => ['label' => 'Port', 'entities' => [], 'fields' => [
                'code' => ['Port code', 'text', ['pattern' => 'portcode', 'required' => true]], 'name' => ['Name', 'text', ['max' => 120, 'required' => true]],
                'portType' => ['Type', 'select', ['choices' => ['sea' => 'Sea', 'air' => 'Air', 'land' => 'Land', 'icd' => 'Inland container depot']]],
            ]],
            'job_worker' => ['label' => 'Job worker', 'entities' => [], 'fields' => [
                'vendor' => ['Vendor record', 'ref', ['ref' => 'entity:vendor', 'required' => true]], 'gstin' => ['GSTIN', 'text', ['pattern' => 'gstin']],
                'processes' => ['Processes carried out', 'text', ['max' => 300]],
            ]],
            'loan_deposit' => ['label' => 'Loan or deposit', 'entities' => [], 'fields' => [
                'party' => ['Party (customer or vendor record name)', 'text', ['max' => 160, 'required' => true]],
                'kind' => ['Kind', 'select', ['choices' => ['loan_taken' => 'Loan taken', 'loan_given' => 'Loan given', 'deposit_accepted' => 'Deposit accepted', 'deposit_given' => 'Deposit given'], 'required' => true]],
                'classification' => ['Classification', 'ref', ['ref' => 'rule:loan_deposit_class']], 'relatedParty' => ['Related party', 'bool', []],
                'interestPercent' => ['Interest rate (%)', 'percent', []], 'ledgerAccount' => ['Ledger account', 'ref', ['ref' => 'entity:account']],
            ]],
        ],
    ];
    return $s;
}

function in2_gst_treatments(): array
{
    return ['' => 'Not set', 'taxable' => 'Taxable', 'exempt' => 'Exempt', 'nil_rated' => 'Nil rated', 'non_gst' => 'Non-GST supply', 'zero_rated' => 'Zero rated (export / SEZ)', 'composition' => 'Composition (bill of supply)'];
}

function in2_party_fields(): array
{
    return [
        'pan' => ['PAN', 'text', ['pattern' => 'pan']],
        'residency' => ['Residential status', 'select', ['choices' => ['resident' => 'Resident', 'non_resident' => 'Non-resident']]],
        'foreignTaxId' => ['Foreign tax identification number', 'text', ['max' => 40]],
        'msme' => ['Registered MSME', 'bool', []], 'udyamNumber' => ['Udyam registration number', 'text', ['pattern' => 'udyam']],
        'msmeCategory' => ['MSME category', 'select', ['choices' => ['' => 'Not MSME', 'micro' => 'Micro', 'small' => 'Small', 'medium' => 'Medium']]],
        'msmeActivity' => ['MSME activity', 'select', ['choices' => ['' => 'Choose', 'manufacturing' => 'Manufacturing', 'services' => 'Services']]],
        'msmeRule' => ['MSME payment rule', 'ref', ['ref' => 'rule:msme_rule']],
        'agreedPaymentDays' => ['Agreed payment days', 'number', ['min' => 0, 'max' => 365]],
        'tcsSection' => ['TCS section (sales to this party)', 'ref', ['ref' => 'rule:tcs_section']],
        'relatedParty' => ['Related party', 'bool', []], 'relationship' => ['Relationship', 'ref', ['ref' => 'rule:related_party_relationship']],
    ];
}

/* ---------- schema ---------- */

function in2_schema_ready(): void
{
    static $ready = false; if ($ready) return;
    tegh_edition_schema_ready();
    db()->exec("CREATE TABLE IF NOT EXISTS india_entity_profiles (
        id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, entity_type VARCHAR(20) NOT NULL, entity_id VARCHAR(64) NOT NULL,
        effective_from DATE NOT NULL, data_json LONGTEXT NOT NULL, created_by VARCHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY india_entity_profiles_version_uq (company_id, entity_type, entity_id, effective_from),
        KEY india_entity_profiles_entity_idx (company_id, entity_type, entity_id),
        CONSTRAINT fk_india_entity_profiles_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS india_master_records (
        id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, record_type VARCHAR(40) NOT NULL,
        entity_type VARCHAR(20) NULL, entity_id VARCHAR(64) NULL, data_json LONGTEXT NOT NULL,
        effective_from DATE NOT NULL, effective_to DATE NULL, status ENUM('active','inactive','superseded') NOT NULL DEFAULT 'active',
        replaces_id VARCHAR(64) NULL, created_by VARCHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY india_master_records_type_idx (company_id, record_type, status), KEY india_master_records_entity_idx (company_id, entity_type, entity_id),
        CONSTRAINT fk_india_master_records_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

/* ---------- validation ---------- */

function in2_pattern_problem(string $pattern, string $v): ?string
{
    $rx = ['pan' => ['/^[A-Z]{5}[0-9]{4}[A-Z]$/', 'A PAN has 5 letters, 4 digits and a letter.'],
        'iec' => ['/^[A-Z0-9]{10}$/', 'An IEC has 10 characters.'],
        'udyam' => ['/^UDYAM-[A-Z]{2}-[0-9]{2}-[0-9]{7}$/', 'A Udyam number looks like UDYAM-MH-01-0000001.'],
        'hsnsac' => ['/^([0-9]{4}|[0-9]{6}|[0-9]{8})$/', 'HSN/SAC codes have 4, 6 or 8 digits.'],
        'uqc' => ['/^[A-Z]{3}$/', 'A UQC is a 3-letter code (as published for GST returns).'],
        'digits8' => ['/^[0-9]{4,8}$/', 'Enter 4 to 8 digits.'], 'digits12' => ['/^[0-9]{12}$/', 'A UAN has 12 digits.'], 'digits10to17' => ['/^[0-9]{10,17}$/', 'Enter 10 to 17 digits.'],
        'digits4' => ['/^[0-9]{4}$/', 'Enter the last 4 digits only.'],
        'ifsc' => ['/^[A-Z]{4}0[A-Z0-9]{6}$/', 'An IFSC has 11 characters: 4 letters, 0, then 6 letters or digits.'],
        'swift' => ['/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', 'A SWIFT/BIC has 8 or 11 characters.'], 'upi' => ['/^[A-Za-z0-9._-]{2,64}@[A-Za-z]{2,64}$/', 'A UPI ID looks like name@bank.'],
        'pin' => ['/^[1-9][0-9]{5}$/', 'A PIN code has 6 digits and does not start with 0.'], 'fy' => ['/^20[0-9]{2}-[0-9]{2}$/', 'Write the financial year as 2026-27.'],
        'transporterid' => ['/^[0-9A-Z]{15}$/', 'A transporter ID (GSTIN or TRANSIN) has 15 characters.'], 'vehicle' => ['/^[A-Z]{2}[0-9A-Z]{4,9}$/', 'Write the vehicle number without spaces, for example MH12AB1234.'],
        'portcode' => ['/^[A-Z0-9]{6}$/', 'A port code has 6 characters.']];
    if ($pattern === 'gstin') return tegh_india_gstin_problem($v);
    if (!isset($rx[$pattern])) return null;
    return preg_match($rx[$pattern][0], $v) ? null : $rx[$pattern][1];
}

/** Validate and normalise data against a field spec. Unknown keys are refused; references must exist in this company. */
function in2_clean(string $companyId, array $fields, array $input): array
{
    $out = [];
    foreach ($input as $k => $_) if (!isset($fields[$k])) fail('Unknown field: ' . mb_substr((string)$k, 0, 40), 422, 'india_field_unknown');
    foreach ($fields as $key => [$label, $type, $extra]) {
        $raw = $input[$key] ?? null; $required = !empty($extra['required']);
        if ($type === 'bool') { $out[$key] = !empty($raw) && $raw !== 'false' && $raw !== '0'; continue; }
        $text = is_string($raw) || is_numeric($raw) ? trim((string)$raw) : '';
        if ($text === '') { if ($required) fail($label . ' is required.', 422, 'india_field_required'); continue; }
        switch ($type) {
            case 'text':
                if (!empty($extra['pattern'])) $text = strtoupper($text) === $text || in_array($extra['pattern'], ['upi'], true) ? $text : strtoupper($text);
                if (!empty($extra['pattern']) && ($p = in2_pattern_problem((string)$extra['pattern'], $text))) fail($label . ': ' . $p, 422, 'india_field_invalid');
                if (mb_strlen($text) > (int)($extra['max'] ?? 300)) fail($label . ' is too long.', 422, 'india_field_invalid');
                if (preg_match('/[\x00-\x1F\x7F]/', $text)) fail($label . ' contains control characters.', 422, 'india_field_invalid');
                $out[$key] = $text; break;
            case 'date': $out[$key] = safe_date($text, $label); break;
            case 'number': if (!preg_match('/^-?[0-9]+$/', $text)) fail($label . ' must be a whole number.', 422, 'india_field_invalid');
                $n = (int)$text; if ((isset($extra['min']) && $n < $extra['min']) || (isset($extra['max']) && $n > $extra['max'])) fail($label . ' is out of range.', 422, 'india_field_invalid'); $out[$key] = $n; break;
            case 'percent': if (!is_numeric($text) || (float)$text < 0 || (float)$text > 100) fail($label . ' must be a percentage from 0 to 100.', 422, 'india_field_invalid'); $out[$key] = round((float)$text, 4); break;
            case 'money': if (!preg_match('/^[0-9]+$/', $text)) fail($label . ' must be an amount in paise (whole number).', 422, 'india_field_invalid'); $out[$key] = (int)$text; break;
            case 'select': if (!array_key_exists($text, $extra['choices'])) fail($label . ': choose one of the listed options.', 422, 'india_field_invalid'); $out[$key] = $text; break;
            case 'ref': if (!in2_ref_exists($companyId, (string)$extra['ref'], $text)) fail($label . ': the chosen item does not exist in this company.', 422, 'india_reference_missing'); $out[$key] = $text; break;
        }
    }
    if (isset($out['gstin'], $out['pan']) && substr($out['gstin'], 2, 10) !== $out['pan']) fail('The PAN inside the GSTIN is not this PAN.', 422, 'india_field_invalid');
    return $out;
}

const IN2_ENTITY_TABLES = ['customer' => 'customers', 'vendor' => 'vendors', 'item' => 'products_services', 'account' => 'accounts', 'asset' => 'fixed_assets', 'bank_account' => 'bank_accounts'];

function in2_ref_exists(string $companyId, string $ref, string $value): bool
{
    [$kind, $what] = explode(':', $ref, 2);
    if ($kind === 'rule') {
        $cats = explode('|', $what); $ph = implode(',', array_fill(0, count($cats), '?'));
        $q = db()->prepare("SELECT COUNT(*) FROM statutory_rates WHERE company_id=? AND jurisdiction LIKE 'IN%' AND person_type IN ($ph) AND rate_key=? AND status<>'superseded'");
        $q->execute(array_merge([$companyId], $cats, [$value])); return (int)$q->fetchColumn() > 0;
    }
    if ($kind === 'record') { $q = db()->prepare("SELECT COUNT(*) FROM india_master_records WHERE company_id=? AND record_type=? AND id=? AND status='active'"); $q->execute([$companyId, $what, $value]); return (int)$q->fetchColumn() > 0; }
    if ($kind === 'entity' && isset(IN2_ENTITY_TABLES[$what])) { $q = db()->prepare('SELECT COUNT(*) FROM ' . IN2_ENTITY_TABLES[$what] . ' WHERE company_id=? AND id=?'); $q->execute([$companyId, $value]); return (int)$q->fetchColumn() > 0; }
    return false;
}

function in2_entity_exists(string $companyId, string $entity, string $id): bool
{
    if ($entity === 'company') return $id === $companyId;
    return isset(IN2_ENTITY_TABLES[$entity]) && in2_ref_exists($companyId, 'entity:' . $entity, $id);
}

/** Required single-line text; a validation error is 422 like every other India master check. */
function in2_text(mixed $value, string $label, int $max): string
{
    $text = preg_replace('/\s+/u', ' ', trim(is_scalar($value) ? (string)$value : '')) ?? '';
    if ($text === '') fail($label . ' is required.', 422, 'india_field_required');
    if (mb_strlen($text) > $max || preg_match('/[\x00-\x1F\x7F]/', $text)) fail($label . ' is too long or contains control characters.', 422, 'india_field_invalid');
    return $text;
}

/* ---------- services ---------- */

function in2_profile_current(string $companyId, string $entity, string $id, ?string $asOf = null): array
{
    $q = db()->prepare('SELECT effective_from, data_json FROM india_entity_profiles WHERE company_id=? AND entity_type=? AND entity_id=? AND effective_from<=? ORDER BY effective_from DESC LIMIT 1');
    $q->execute([$companyId, $entity, $id, $asOf ?? canadian_today()]); $r = $q->fetch();
    return $r ? ['effectiveFrom' => (string)$r['effective_from'], 'data' => json_decode((string)$r['data_json'], true) ?: []] : ['effectiveFrom' => null, 'data' => []];
}

function in2_profile_save(array $user, array $company, array $input): array
{
    $companyId = (string)$company['id']; $specs = in2_specs()['profiles'];
    $entity = (string)($input['entity'] ?? ''); if (!isset($specs[$entity])) fail('Unknown record type.', 422, 'india_entity_unknown');
    $id = $entity === 'company' ? $companyId : clean_text($input['entityId'] ?? '', 'Record', 64);
    if (!in2_entity_exists($companyId, $entity, $id)) fail('That record does not exist in this company.', 404, 'india_entity_missing');
    $from = safe_date((string)($input['effectiveFrom'] ?? canadian_today()), 'Effective from');
    $data = in2_clean($companyId, $specs[$entity]['fields'], is_array($input['data'] ?? null) ? $input['data'] : []);
    $before = in2_profile_current($companyId, $entity, $id, $from);
    db()->beginTransaction();
    try {
        $exists = db()->prepare('SELECT id FROM india_entity_profiles WHERE company_id=? AND entity_type=? AND entity_id=? AND effective_from=? FOR UPDATE');
        $exists->execute([$companyId, $entity, $id, $from]); $versionId = $exists->fetchColumn();
        // A version dated the same day is corrected in place; any other date adds a version, so history is kept.
        if ($versionId) db()->prepare('UPDATE india_entity_profiles SET data_json=?, created_by=? WHERE id=?')->execute([json_encode($data), $user['id'], $versionId]);
        else db()->prepare('INSERT INTO india_entity_profiles (id,company_id,entity_type,entity_id,effective_from,data_json,created_by) VALUES (?,?,?,?,?,?,?)')->execute([$versionId = new_id('inprof'), $companyId, $entity, $id, $from, json_encode($data), $user['id']]);
        audit_event($user, $companyId, 'india.profile_saved', $entity, $id, ['effectiveFrom' => $from, 'before' => $before['data'], 'after' => $data, 'version' => (string)$versionId]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['entity' => $entity, 'entityId' => $id, 'effectiveFrom' => $from, 'data' => $data];
}

function in2_record_save(array $user, array $company, array $input): array
{
    $companyId = (string)$company['id']; $specs = in2_specs()['records'];
    $type = (string)($input['recordType'] ?? ''); if (!isset($specs[$type])) fail('Unknown record type.', 422, 'india_record_unknown');
    $spec = $specs[$type]; $entity = trim((string)($input['entity'] ?? '')); $entityId = null;
    if ($spec['entities']) {
        if (!in_array($entity, $spec['entities'], true)) fail('Choose what this ' . strtolower($spec['label']) . ' belongs to.', 422, 'india_record_entity');
        $entityId = $entity === 'company' ? $companyId : clean_text($input['entityId'] ?? '', 'Record', 64);
        if (!in2_entity_exists($companyId, $entity, $entityId)) fail('That record does not exist in this company.', 404, 'india_entity_missing');
    } else $entity = null;
    $from = safe_date((string)($input['effectiveFrom'] ?? canadian_today()), 'Effective from');
    $data = in2_clean($companyId, $spec['fields'], is_array($input['data'] ?? null) ? $input['data'] : []);
    if ($type === 'gst_registration' && $entity === 'company') {
        $profilePan = db()->prepare('SELECT pan FROM company_india_profile WHERE company_id=?'); $profilePan->execute([$companyId]); $pan = (string)$profilePan->fetchColumn();
        if ($pan !== '' && substr($data['gstin'], 2, 10) !== $pan) fail('A GST registration of this company must carry the company PAN (' . $pan . ').', 422, 'india_gstin_pan');
        if (($data['status'] ?? '') === 'cancelled' && empty($data['cancelledOn'])) fail('Enter the cancellation date.', 422, 'india_field_required');
    }
    $replaces = trim((string)($input['replacesId'] ?? ''));
    db()->beginTransaction();
    try {
        $before = null;
        if ($replaces !== '') {
            $old = db()->prepare("SELECT * FROM india_master_records WHERE company_id=? AND id=? AND record_type=? AND status='active' FOR UPDATE"); $old->execute([$companyId, $replaces, $type]); $before = $old->fetch();
            if (!$before) fail('The record being changed no longer exists or was already changed.', 409, 'india_record_stale');
            if ($from <= (string)$before['effective_from']) {
                // Same or earlier date: a correction of the same version; keep the old content in the audit trail.
                db()->prepare('UPDATE india_master_records SET data_json=?, effective_from=? WHERE id=?')->execute([json_encode($data), $from, $replaces]); $id = $replaces;
            } else {
                $to = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
                db()->prepare("UPDATE india_master_records SET status='superseded', effective_to=? WHERE id=?")->execute([$to, $replaces]);
                db()->prepare('INSERT INTO india_master_records (id,company_id,record_type,entity_type,entity_id,data_json,effective_from,replaces_id,created_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$id = new_id('inrec'), $companyId, $type, $entity, $entityId, json_encode($data), $from, $replaces, $user['id']]);
            }
        } else db()->prepare('INSERT INTO india_master_records (id,company_id,record_type,entity_type,entity_id,data_json,effective_from,created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$id = new_id('inrec'), $companyId, $type, $entity, $entityId, json_encode($data), $from, $user['id']]);
        audit_event($user, $companyId, 'india.record_saved', $type, (string)$id, ['entity' => $entity, 'entityId' => $entityId, 'effectiveFrom' => $from, 'replaces' => $replaces ?: null, 'before' => $before ? json_decode((string)$before['data_json'], true) : null, 'after' => $data]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['id' => $id, 'recordType' => $type, 'effectiveFrom' => $from, 'data' => $data];
}

function in2_record_deactivate(array $user, array $company, array $input): array
{
    $companyId = (string)$company['id']; $id = clean_text($input['id'] ?? '', 'Record', 64);
    $q = db()->prepare("SELECT record_type FROM india_master_records WHERE company_id=? AND id=? AND status='active'"); $q->execute([$companyId, $id]); $type = $q->fetchColumn();
    if ($type === false) fail('That record is not active.', 404, 'india_record_missing');
    $to = safe_date((string)($input['effectiveTo'] ?? canadian_today()), 'Inactive from');
    db()->prepare("UPDATE india_master_records SET status='inactive', effective_to=? WHERE id=?")->execute([$to, $id]);
    audit_event($user, $companyId, 'india.record_deactivated', (string)$type, $id, ['effectiveTo' => $to]);
    return ['id' => $id, 'status' => 'inactive'];
}

/** Statutory rules: statutory_rates rows, jurisdiction IN / IN-<state>. A new effective date supersedes the previous version. */
function in2_rule_save(array $user, array $company, array $input): array
{
    $companyId = (string)$company['id'];
    $cat = (string)($input['category'] ?? ''); if (!isset(IN2_RULE_CATEGORIES[$cat])) fail('Choose a rule category.', 422, 'india_rule_category');
    $key = in2_text($input['code'] ?? '', 'Code', 120); if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._()\/-]{0,119}$/', $key)) fail('A code may use letters, digits and . _ ( ) / - only.', 422, 'india_rule_code');
    $label = in2_text($input['label'] ?? '', 'Description', 200);
    $state = strtoupper(trim((string)($input['state'] ?? ''))); if ($state !== '' && !isset(TEGH_INDIA_STATES[$state])) fail('Choose a state or leave it blank for all of India.', 422, 'india_rule_state');
    $jur = $state === '' ? 'IN' : 'IN-' . $state;
    $from = safe_date((string)($input['effectiveFrom'] ?? ''), 'Effective from'); $to = trim((string)($input['effectiveTo'] ?? '')); $to = $to === '' ? null : safe_date($to, 'Effective to');
    if ($to !== null && $to < $from) fail('Effective to is before effective from.', 422, 'india_rule_dates');
    $rate = trim((string)($input['ratePercent'] ?? '')); if ($rate !== '' && (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 100)) fail('The rate must be a percentage from 0 to 100.', 422, 'india_rule_rate');
    $cents = static function ($v, string $l): ?int { $v = trim((string)$v); if ($v === '') return null; if (!preg_match('/^[0-9]+$/', $v)) fail($l . ' must be a whole number of paise.', 422, 'india_rule_amount'); return (int)$v; };
    $amount = $cents($input['amountCents'] ?? '', 'Amount'); $min = $cents($input['thresholdMinCents'] ?? '', 'Threshold from'); $max = $cents($input['thresholdMaxCents'] ?? '', 'Threshold to');
    $source = in2_text($input['source'] ?? '', 'Source (notification, circular or section)', 200);
    $url = trim((string)($input['sourceUrl'] ?? '')); if ($url !== '' && !preg_match('#^https://[^\s]{4,990}$#', $url)) fail('The source link must start with https://', 422, 'india_rule_url');
    $notes = optional_text($input['notes'] ?? null, 1000);
    db()->beginTransaction();
    try {
        $dupe = db()->prepare('SELECT id FROM statutory_rates WHERE company_id=? AND jurisdiction=? AND person_type=? AND rate_key=? AND effective_from=? FOR UPDATE');
        $dupe->execute([$companyId, $jur, $cat, $key, $from]); if ($dupe->fetchColumn()) fail('This rule already has a version from that date. Change that version, or choose another date.', 409, 'india_rule_version_exists');
        $id = new_id('statrate');
        db()->prepare("INSERT INTO statutory_rates (id,company_id,jurisdiction,person_type,rate_key,label,effective_from,effective_to,value_decimal,value_cents,threshold_min_cents,threshold_max_cents,metadata_json,source_url,source_label,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)")
            ->execute([$id, $companyId, $jur, $cat, $key, $label, $from, $to, $rate === '' ? null : $rate, $amount, $min, $max, json_encode(['notes' => $notes, 'edition' => 'IN']), $url !== '' ? $url : null, $source, $user['id']]);
        audit_event($user, $companyId, 'india.rule_saved', 'statutory_rate', $id, ['category' => $cat, 'code' => $key, 'jurisdiction' => $jur, 'effectiveFrom' => $from, 'effectiveTo' => $to, 'ratePercent' => $rate === '' ? null : $rate, 'amountCents' => $amount, 'source' => $source]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['id' => $id, 'status' => 'draft'];
}

function in2_rule_approve(array $user, array $company, array $input): array
{
    if (!in_array((string)$company['role'], ['owner', 'admin'], true)) fail('Only the Company Owner or an Admin approves statutory rules.', 403, 'india_rule_approver');
    $companyId = (string)$company['id']; $id = clean_text($input['id'] ?? '', 'Rule', 64);
    db()->beginTransaction();
    try {
        $q = db()->prepare("SELECT * FROM statutory_rates WHERE company_id=? AND id=? AND jurisdiction LIKE 'IN%' FOR UPDATE"); $q->execute([$companyId, $id]); $r = $q->fetch();
        if (!$r || $r['status'] !== 'draft') fail('Only a draft rule can be approved.', 409, 'india_rule_not_draft');
        // The version in force before this one ends the day before; later versions are not touched.
        $prev = db()->prepare("SELECT id FROM statutory_rates WHERE company_id=? AND jurisdiction=? AND person_type=? AND rate_key=? AND status='active' AND effective_from<? ORDER BY effective_from DESC LIMIT 1 FOR UPDATE");
        $prev->execute([$companyId, $r['jurisdiction'], $r['person_type'], $r['rate_key'], $r['effective_from']]); $prevId = $prev->fetchColumn();
        if ($prevId) db()->prepare("UPDATE statutory_rates SET effective_to=LEAST(COALESCE(effective_to,'9999-12-31'),DATE_SUB(?,INTERVAL 1 DAY)) WHERE id=?")->execute([$r['effective_from'], $prevId]);
        db()->prepare("UPDATE statutory_rates SET status='active', approved_by=? WHERE id=?")->execute([$user['id'], $id]);
        audit_event($user, $companyId, 'india.rule_approved', 'statutory_rate', $id, ['category' => $r['person_type'], 'code' => $r['rate_key'], 'effectiveFrom' => $r['effective_from'], 'previousVersionEnded' => $prevId ?: null]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['id' => $id, 'status' => 'active'];
}

/* ---------- routes ---------- */

function handle_india_masters(string $action): never
{
    $user = require_user(); $company = require_company($user);
    if (tegh_company_edition($company) !== 'IN') fail('India master data is available only in India companies.', 404, 'india_only');
    in2_schema_ready(); header('Cache-Control: private, no-store');
    $companyId = (string)$company['id']; $role = (string)$company['role'];
    $canWrite = in_array($role, ['owner', 'admin', 'editor'], true);
    if (request_method() === 'GET') {
        require_company_permission($company, 'company.view');
        if ($action === 'spec') {
            $out = ['profiles' => [], 'records' => [], 'ruleCategories' => IN2_RULE_CATEGORIES, 'states' => tegh_india_reference_public()['states']];
            foreach (in2_specs() as $group => $items) foreach ($items as $k => $v) {
                $fields = []; foreach ($v['fields'] as $fk => [$l, $t, $x]) $fields[] = ['key' => $fk, 'label' => $l, 'type' => $t, 'required' => !empty($x['required']), 'choices' => $x['choices'] ?? null, 'ref' => $x['ref'] ?? null, 'pattern' => $x['pattern'] ?? null];
                $out[$group][$k] = ['label' => $v['label'], 'entities' => $v['entities'] ?? null, 'fields' => $fields];
            }
            json_response($out + ['canWrite' => $canWrite, 'canApprove' => in_array($role, ['owner', 'admin'], true)]);
        }
        if ($action === 'profile') {
            $entity = (string)($_GET['entity'] ?? ''); $id = $entity === 'company' ? $companyId : (string)($_GET['entityId'] ?? '');
            if (!in2_entity_exists($companyId, $entity, $id)) fail('That record does not exist in this company.', 404, 'india_entity_missing');
            $h = db()->prepare('SELECT effective_from, data_json, created_at FROM india_entity_profiles WHERE company_id=? AND entity_type=? AND entity_id=? ORDER BY effective_from DESC LIMIT 50'); $h->execute([$companyId, $entity, $id]);
            json_response(['current' => in2_profile_current($companyId, $entity, $id), 'history' => array_map(static fn($r) => ['effectiveFrom' => (string)$r['effective_from'], 'data' => json_decode((string)$r['data_json'], true), 'savedAt' => (string)$r['created_at']], $h->fetchAll())]);
        }
        if ($action === 'records') {
            $type = (string)($_GET['recordType'] ?? ''); if (!isset(in2_specs()['records'][$type])) fail('Unknown record type.', 422, 'india_record_unknown');
            $sql = 'SELECT id, entity_type, entity_id, data_json, effective_from, effective_to, status FROM india_master_records WHERE company_id=? AND record_type=?'; $params = [$companyId, $type];
            if (($e = (string)($_GET['entity'] ?? '')) !== '') { $sql .= ' AND entity_type=? AND entity_id=?'; $params[] = $e; $params[] = $e === 'company' ? $companyId : (string)($_GET['entityId'] ?? ''); }
            if (empty($_GET['history'])) $sql .= " AND status='active'";
            $q = db()->prepare($sql . ' ORDER BY effective_from DESC, created_at DESC LIMIT 500'); $q->execute($params);
            json_response(['records' => array_map(static fn($r) => ['id' => (string)$r['id'], 'entity' => $r['entity_type'], 'entityId' => $r['entity_id'], 'data' => json_decode((string)$r['data_json'], true), 'effectiveFrom' => (string)$r['effective_from'], 'effectiveTo' => $r['effective_to'], 'status' => (string)$r['status']], $q->fetchAll())]);
        }
        if ($action === 'rules') {
            $cat = (string)($_GET['category'] ?? '');
            $q = db()->prepare("SELECT id, jurisdiction, person_type, rate_key, label, effective_from, effective_to, value_decimal, value_cents, threshold_min_cents, threshold_max_cents, source_label, source_url, status, metadata_json FROM statutory_rates WHERE company_id=? AND jurisdiction LIKE 'IN%'" . ($cat !== '' ? ' AND person_type=?' : '') . ' ORDER BY person_type, rate_key, effective_from DESC LIMIT 2000');
            $q->execute($cat !== '' ? [$companyId, $cat] : [$companyId]);
            json_response(['rules' => array_map(static fn($r) => ['id' => (string)$r['id'], 'category' => (string)$r['person_type'], 'code' => (string)$r['rate_key'], 'label' => (string)$r['label'], 'state' => strlen((string)$r['jurisdiction']) > 2 ? substr((string)$r['jurisdiction'], 3) : '',
                'effectiveFrom' => (string)$r['effective_from'], 'effectiveTo' => $r['effective_to'], 'ratePercent' => $r['value_decimal'] !== null ? rtrim(rtrim((string)$r['value_decimal'], '0'), '.') : null, 'amountCents' => $r['value_cents'] !== null ? (int)$r['value_cents'] : null,
                'thresholdMinCents' => $r['threshold_min_cents'] !== null ? (int)$r['threshold_min_cents'] : null, 'thresholdMaxCents' => $r['threshold_max_cents'] !== null ? (int)$r['threshold_max_cents'] : null,
                'source' => (string)$r['source_label'], 'sourceUrl' => $r['source_url'], 'status' => (string)$r['status'], 'notes' => json_decode((string)$r['metadata_json'], true)['notes'] ?? null], $q->fetchAll())]);
        }
        fail('Not found.', 404, 'route_not_found');
    }
    require_method('POST'); require_csrf();
    if (!$canWrite) fail('Your role can view India master data but not change it.', 403, 'india_write_forbidden');
    $input = request_json();
    $ownerOnly = in_array($action, ['rule', 'rule-approve'], true) || (in_array($action, ['profile'], true) && in_array((string)($input['entity'] ?? ''), ['company', 'account'], true));
    if ($ownerOnly && !in_array($role, ['owner', 'admin'], true)) fail('Only the Company Owner or an Admin changes company registrations, account mappings and statutory rules.', 403, 'india_write_forbidden');
    $result = match ($action) {
        'profile' => in2_profile_save($user, $company, $input),
        'record' => in2_record_save($user, $company, $input),
        'record-deactivate' => in2_record_deactivate($user, $company, $input),
        'rule' => in2_rule_save($user, $company, $input),
        'rule-approve' => in2_rule_approve($user, $company, $input),
        default => fail('Not found.', 404, 'route_not_found'),
    };
    json_response($result, in_array($action, ['record-deactivate', 'rule-approve'], true) ? 200 : 201);
}
