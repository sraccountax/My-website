<?php
declare(strict_types=1);

/**
 * Tegh 5.6.0 canonical application-agent and Quick Action layer.
 *
 * The language model is never allowed to invent endpoints. Natural language is
 * resolved to this registry, then the same application services used by the
 * manual UI perform validation and execution.
 */

function tegh_action_required_feature(string $actionId,string $module,string $route): string
{
    if($actionId==='nav.command_centre')return 'tegh.command_centre';
    if($actionId==='bank.transactions.match_post')return 'banking.reconciliation.match_post';
    if($actionId==='bank.matches.match_selected')return 'banking.reconciliation.bulk_match';
    if($actionId==='bank.postings.prepare_selected')return 'banking.reconciliation.match_post';
    if(str_starts_with($actionId,'bank.matches.')||$actionId==='bank.duplicates.find')return 'banking.reconciliation.advanced';
    $text=strtolower($actionId.' '.$module.' '.$route);
    if(str_contains($text,'payroll'))return 'module.payroll';
    if(str_contains($text,'document')||str_contains($text,'ocr'))return 'module.document_intake';
    if(str_contains($text,'collection'))return 'module.collections';
    if(str_contains($text,'financial-analyst')||str_contains($text,'forecast')||str_contains($text,'scenario'))return 'module.financial_analysis';
    if(str_contains($text,'research'))return 'tegh.ai.advanced';
    return 'core.accounting';
}

function tegh_action_command_category(string $module,string $route): string
{
    $text=strtolower($module.' '.$route);
    return match(true){
        str_contains($text,'bank')||str_contains($text,'reconcil')=>'Banking',
        str_contains($text,'receivable')||str_contains($text,'customer')||str_contains($text,'sales')=>'Receivables',
        str_contains($text,'payable')||str_contains($text,'vendor')||str_contains($text,'bill')=>'Payables',
        str_contains($text,'payroll')=>'Payroll',
        str_contains($text,'report')||str_contains($text,'analysis')=>'Reports',
        str_contains($text,'document')||str_contains($text,'attachment')=>'Documents',
        str_contains($text,'month-end')||str_contains($text,'period-close')=>'Month-End',
        str_contains($text,'setting')||str_contains($text,'setup')||str_contains($text,'accounting')=>'Setup',
        default=>'Today',
    };
}

/** @return array<string,mixed> */
function tegh_action_parameter_schema(array $required,array $optional): array
{
    $properties=[];
    foreach(array_unique(array_merge($required,$optional)) as $name){
        $name=(string)$name;$lower=strtolower($name);$type=str_ends_with($lower,'ids')||in_array($lower,['employees','lines','decisions'],true)?'array':(str_ends_with($lower,'cents')||str_ends_with($lower,'count')?'integer':(str_starts_with($lower,'is')||str_starts_with($lower,'include')?'boolean':'string'));
        $properties[$name]=['type'=>$type];if(str_ends_with($lower,'date')||str_contains($lower,'periodstart')||str_contains($lower,'periodend')||in_array($lower,['start','end','from','to','asof'],true))$properties[$name]['format']='date';
        if(str_ends_with($lower,'ids'))$properties[$name]+=['items'=>['type'=>'string','minLength'=>1,'maxLength'=>64],'minItems'=>1,'maxItems'=>100,'uniqueItems'=>true];
        if(in_array($lower,['accountid','account','customerid','vendorid','reportid'],true))$properties[$name]+=['minLength'=>1,'maxLength'=>120];
        if($lower==='count')$properties[$name]+=['minimum'=>1,'maximum'=>260];
    }
    return ['type'=>'object','additionalProperties'=>false,'required'=>array_values($required),'properties'=>$properties];
}

/** Compatibility is explicit; empty targets have no implemented destination. */
function tegh_guidance_action_aliases(): array
{
    return [
        'open_advanced'=>'nav.advanced_accounting','open_audit_history'=>'nav.audit_history',
        'open_bank_review'=>'nav.bank_review','open_banking'=>'nav.banking',
        'open_bill_new'=>'nav.vendor_invoice_create','open_bills'=>'nav.vendor_invoices',
        'open_company_setup'=>'nav.company_setup','open_customer_invoices'=>'nav.customer_invoices',
        'open_customer_payments'=>'nav.customer_payments','open_customers'=>'nav.customers',
        'open_day_book'=>'nav.day_book','open_expense_vouchers'=>'nav.expense_vouchers',
        'open_inventory_report'=>'report.inventory','open_invoice_new'=>'nav.customer_invoice_create',
        'open_invoice_templates'=>'nav.invoice_templates','open_invoices'=>'nav.customer_invoices',
        'open_levy'=>'','open_opening_setup'=>'nav.company_setup','open_payroll'=>'nav.payroll',
        'open_payroll_quick'=>'nav.payroll_calculator','open_payroll_remittance'=>'nav.payroll_remittance',
        'open_payroll_verification'=>'nav.payroll_verification','open_products'=>'nav.products_services',
        'open_rates'=>'nav.payroll_rates','open_reconciliation'=>'nav.reconciliation',
        'open_reports'=>'nav.reports','open_statement_preview'=>'nav.bank_import','open_tax_reporting'=>'',
        'open_vendor_invoices'=>'nav.vendor_invoices','open_vendor_payments'=>'nav.vendor_payments','open_vendors'=>'nav.vendors',
    ];
}

function tegh_guidance_canonical_action_id(string $id): string
{
    $id=tegh_guidance_action_aliases()[$id]??$id;
    return $id!==''&&isset(tegh_action_registry()[$id])?$id:'';
}

/** Features required by the actual continuation, without granting dependencies. */
function tegh_action_continuation_features(array $action): array
{
    $features=[(string)($action['required_feature_key']??'core.accounting')];
    if(in_array((string)$action['action_id'],['bank.matches.analyze','bank.matches.review_exact'],true))$features[]='tegh.command_centre';
    // These entries use the existing combined protected preview/confirm workflow.
    if(in_array((string)$action['action_id'],['bank.matches.match_selected','bank.postings.prepare_selected','bank.transactions.match_post'],true))$features=array_merge($features,['banking.reconciliation.advanced','banking.reconciliation.match_post']);
    return array_values(array_unique($features));
}

function tegh_action_registry(): array
{
    // R123: the registry is static data; build it once per request.
    static $cached=null;if($cached!==null)return $cached;
    $a = static function(
        string $id,string $name,string $description,string $module,string $route,string $type,string $permission,
        array $modes=['guided','full'],array $required=[],array $optional=[],bool $companyScoped=true,string $validation='',string $execution='',
        string $confirmation='none',bool $financial=false,bool $destructive=false,bool $navigate=true,bool $execute=true,string $successRoute='',bool $audit=true,
        array $quick=[]
    ): array {
        $requiredFeature=tegh_action_required_feature($id,$module,$route);$category=tegh_action_command_category($module,$route);
        $risk=$destructive?'Destructive':($financial?'Approval required':($type==='prepare'?'Prepares a draft':($type==='navigation'?'Opens workflow':'Read only')));
        return [
            'action_id'=>$id,'name'=>$name,'description'=>$description,'module'=>$module,'route'=>$route,'action_type'=>$type,
            'required_permission'=>$permission,'supported_modes'=>$modes,'required_inputs'=>$required,'optional_inputs'=>$optional,
            'company_scoped'=>$companyScoped,'validation_service'=>$validation,'execution_service'=>$execution,
            'confirmation_requirement'=>$confirmation,'financial_commit'=>$financial,'destructive'=>$destructive,
            'supports_navigation'=>$navigate,'supports_ai_execution'=>$execute,'success_route'=>$successRoute ?: $route,'audit_required'=>$audit,
            // Quick Actions consume this metadata directly from the authenticated
            // Action Registry. The UI never owns a second catalogue.
            'label'=>(string)($quick['label']??$name),
            'short_label'=>(string)($quick['short_label']??$name),
            'section'=>(string)($quick['section']??$module),
            'icon'=>(string)($quick['icon']??'→'),
            'keywords'=>array_values(array_filter(array_map('strval',(array)($quick['keywords']??[])))),
            'mode'=>$modes,
            'permission'=>$permission,
            'owner_only'=>(bool)($quick['owner_only']??false),
            'quick_action_eligible'=>(bool)($quick['eligible']??false),
            'sort_order'=>(int)($quick['sort_order']??9999),
            'mode_labels'=>(array)($quick['mode_labels']??[]),
            'mode_short_labels'=>(array)($quick['mode_short_labels']??[]),
            'mode_routes'=>(array)($quick['mode_routes']??[]),
            'command_eligible'=>true,'command_category'=>$category,'plain_label'=>(string)($quick['label']??$name),
            'plain_description'=>$description,'risk_label'=>$risk,'required_feature_key'=>$requiredFeature,
            'parameter_schema'=>tegh_action_parameter_schema($required,$optional),
            'preview_service'=>$validation,'result_renderer'=>$type==='read'?'table':($financial?'receipt':'workflow'),
            'empty_state'=>'No eligible company records were found for this command.',
            'recommended_screens'=>array_values(array_unique(array_filter(array_merge(
                [$route,$successRoute],
                array_map('strval',(array)($quick['recommended_screens']??[]))
            )))),
        ];
    };
    $nav = static function(
        string $id,string $name,string $description,string $module,string $route,string $permission,
        array $quick=[],array $modes=['guided','full'],string $type='navigation'
    ) use ($a): array {
        $quick=array_merge(['eligible'=>true,'section'=>$module],$quick);
        return $a($id,$name,$description,$module,$route,$type,$permission,$modes,[],[],true,'existing route validation','existing navigation service','none',false,false,true,true,$route,true,$quick);
    };
    $report = static function(
        string $id,string $name,string $description,string $route,array $required=[],array $optional=[],array $quick=[]
    ) use ($a): array {
        $service=match($id){
            'report.profit_loss','report.balance_sheet'=>'portal/financial-report',
            'report.cash_flow'=>'advanced/cash-flow',
            'report.trial_balance'=>'portal/trial-balance',
            'report.ar_aging','report.ap_aging'=>'portal/aging',
            'report.account_ledger'=>'portal/gl-account-ledger',
            default=>'existing report service',
        };
        return $a($id,$name,$description,'Reports',$route,'read','reports.view',['guided','full'],$required,$optional,true,'existing report validation',$service,'none',false,false,true,true,$route,true,$quick);
    };
    $items = [
        // Canonical navigation and workflow-entry destinations. Quick Actions,
        // Ask Tegh and the shell all resolve these stable action IDs.
        $nav('nav.home','Home','Open the current bookkeeping home.','Home','home','company.view',[
            'icon'=>'HM','keywords'=>['dashboard','overview','guided home','home page'],'sort_order'=>10,
            'mode_labels'=>['guided'=>'Guided Home','full'=>'Dashboard'],
            'mode_short_labels'=>['guided'=>'Home','full'=>'Dashboard'],
            'mode_routes'=>['guided'=>'guided-home','full'=>'dashboard'],
        ]),
        // Kept for Ask Tegh command compatibility; nav.home is the single
        // logical Quick Action across Guided and Full Accounting.
        $a('nav.guided_home','Guided Home','Open Guided Mode home.','Home','guided-home','navigation','company.view'),
        $nav('nav.agent_center','Agent Center','Open proactive Native Agent findings, evidence and review work.','Agent Center','agent-center','company.view',[
            'icon'=>'AC','keywords'=>['native agents','needs attention','ready for review','proactive accounting','today'],'sort_order'=>20,
        ]),
        $nav('nav.month_end_close','Month-End Close','Open the deterministic month-end close readiness workspace.','Agent Center','month-end-close','reports.view',[
            'icon'=>'MC','keywords'=>['close checklist','month end','period readiness','closing books'],'sort_order'=>30,
        ]),
        $nav('nav.financial_analyst','Financial Analyst','Open deterministic cash forecasts, budget variance, scenarios and management alerts.','Reports','financial-analyst','reports.view',[
            'icon'=>'FA','keywords'=>['cash forecast','budget variance','financial scenario','management alerts','planning'],'sort_order'=>505,
        ]),
        $nav('nav.payroll_tax_center','Payroll & Tax Center','Open deterministic payroll and tax readiness, company review thresholds, evidence and bounded-autonomy controls.','Agent Center','payroll-tax-center','payroll.view',[
            'icon'=>'PT','keywords'=>['payroll tax agent','payroll compliance','tax readiness','source deductions','remittance review','bounded autonomy'],'sort_order'=>506,
        ]),
        $nav('nav.native_agent_settings','Native Agent Settings','Open company-wide Native Agent policy and privacy controls.','Settings','native-agent-settings','company.settings',[
            'icon'=>'NA','keywords'=>['agent settings','connected intelligence opt in','scan cadence','materiality'],'sort_order'=>970,
        ]),

        $nav('nav.banking','Banking','Open the Banking workspace.','Banking','banking','banking.view',['icon'=>'BK','keywords'=>['bank dashboard','financial accounts'],'sort_order'=>100]),
        $nav('nav.bank_import','Upload Bank Statement','Open the existing statement import workflow.','Banking','bank-import','banking.import',[
            'icon'=>'⇩','keywords'=>['import bank statement','statement upload','csv','xlsx','pdf'],'sort_order'=>110,
            'mode_labels'=>['guided'=>'Upload Statement','full'=>'Upload Bank Statement'],
        ],['guided','full'],'prepare'),
        $nav('nav.bank_review','Review Transactions','Open the bank transaction review workflow.','Banking','bank-review','banking.view',['icon'=>'BR','keywords'=>['bank review','categorize transactions','match transactions','review bank'],'sort_order'=>120]),
        $nav('nav.bank_transactions','Bank Transactions','Open imported bank transactions.','Banking','bank-transactions','banking.view',['icon'=>'BT','keywords'=>['imported transactions','statement lines','bank register'],'sort_order'=>130]),
        $nav('nav.reconciliation','Reconciliation','Open Bank Reconciliation.','Banking','bank-reconciliation','banking.reconcile',['icon'=>'✓','keywords'=>['reconcile bank','bank reconciliation','matching'],'sort_order'=>140]),
        $nav('nav.bank_accounts','Bank Accounts','Open financial bank and credit-card accounts.','Banking','bank-accounts','banking.view',['icon'=>'BA','keywords'=>['financial accounts','credit card accounts','cash accounts'],'sort_order'=>150]),
        $nav('nav.find_imported_deposit','Find Imported Deposit','Open Customer Payments and search imported deposits.','Banking','find-imported-deposit','invoices.view',['icon'=>'FD','keywords'=>['match receipt','customer deposit','bank deposit'],'sort_order'=>160]),
        $nav('nav.bank_transfers','Bank Transfers','Open transfers between financial accounts.','Banking','bank-transfers','banking.view',['icon'=>'⇄','keywords'=>['transfer between accounts','cash transfer'],'sort_order'=>170]),

        $nav('nav.receivables','Receivables','Open the Receivables workspace.','Receivables','receivables','invoices.view',['icon'=>'AR','keywords'=>['money in','accounts receivable','sales'],'sort_order'=>200]),
        $nav('nav.customer_create','Create Customer','Open the protected new-customer form.','Receivables','customer-create','customers.write',['icon'=>'C+','keywords'=>['add customer','new customer','client'],'sort_order'=>210]),
        $nav('nav.customers','Customers','Open the Customer Register.','Receivables','customers','customers.view',['icon'=>'CU','keywords'=>['customer list','client directory','customer register'],'sort_order'=>220]),
        $nav('nav.products_services','Products & Services','Open reusable products and services.','Receivables','products-services','invoices.view',['icon'=>'PS','keywords'=>['items','inventory items','services','sales products'],'sort_order'=>230]),
        $nav('nav.customer_invoice_create','Create Customer Invoice','Open the normal customer invoice workflow.','Receivables','customer-invoice','invoices.write',[
            'icon'=>'CI','keywords'=>['new invoice','sales invoice','invoice customer'],'sort_order'=>240,
            'mode_labels'=>['guided'=>'Create Invoice','full'=>'Create Customer Invoice'],
        ]),
        $nav('nav.customer_invoices','Customer Invoice Register','Open customer invoices and their current status.','Receivables','invoices','invoices.view',['icon'=>'IR','keywords'=>['customer invoices','sales invoice register','invoice list'],'sort_order'=>250]),
        $nav('nav.customer_payments','Customer Payments','Open customer receipts, allocations and bank matching.','Receivables','customer-payments','invoices.view',['icon'=>'CP','keywords'=>['customer receipts','payments received','receive payment'],'sort_order'=>260]),
        $nav('nav.customer_ledgers','Customer Ledgers','Open customer-by-customer account activity.','Receivables','customer-ledgers','customers.view',['icon'=>'CL','keywords'=>['customer ledger','receivable ledger','client activity'],'sort_order'=>270]),

        $nav('nav.payables','Payables','Open the Payables workspace.','Payables','payables','bills.view',['icon'=>'AP','keywords'=>['money out','accounts payable','purchases'],'sort_order'=>300]),
        $nav('nav.vendor_create','Create Vendor','Open the protected new-vendor form.','Payables','vendor-create','vendors.write',['icon'=>'V+','keywords'=>['add vendor','new supplier','create supplier'],'sort_order'=>310]),
        $nav('nav.vendors','Vendors','Open the Vendor Register.','Payables','vendors','vendors.view',['icon'=>'VE','keywords'=>['supplier list','vendor directory','vendor register'],'sort_order'=>320]),
        $nav('nav.vendor_invoice_create','Create Vendor Invoice','Open the normal vendor invoice workflow.','Payables','vendor-invoice','bills.write',[
            'icon'=>'VI','keywords'=>['add bill','create bill','supplier invoice','purchase invoice'],'sort_order'=>330,
            'mode_labels'=>['guided'=>'Add Bill','full'=>'Create Vendor Invoice'],
        ]),
        $nav('nav.vendor_invoices','Vendor Invoice Register','Open vendor invoices and their current status.','Payables','bills','bills.view',['icon'=>'VR','keywords'=>['vendor invoices','bill register','supplier bills','purchase invoices'],'sort_order'=>340]),
        $nav('nav.vendor_payments','Vendor Payments','Open vendor payments, allocations and bank matching.','Payables','vendor-payments','bills.view',['icon'=>'VP','keywords'=>['pay bills','supplier payments','payments made'],'sort_order'=>350]),
        $nav('nav.vendor_ledgers','Vendor Ledgers','Open vendor-by-vendor account activity.','Payables','vendor-ledgers','vendors.view',['icon'=>'VL','keywords'=>['supplier ledger','payable ledger','vendor activity'],'sort_order'=>360]),
        $nav('nav.recurring_vendor_bills','Recurring Vendor Bills','Open recurring vendor invoice profiles.','Payables','recurring-vendor-bills','bills.write',['icon'=>'RB','keywords'=>['recurring bills','scheduled vendor invoices','repeat bills'],'sort_order'=>370]),

        $nav('nav.gl','General Ledger','Open the General Ledger workspace.','Accounting','general-ledger','journals.view',['icon'=>'GL','keywords'=>['accounting','general ledger dashboard'],'sort_order'=>400],['full']),
        $nav('nav.journal_create','Create Journal Entry','Open the controlled manual journal workflow.','Accounting','journal-create','journals.write',['icon'=>'J+','keywords'=>['new journal','manual journal','gl entry','adjustment'],'sort_order'=>410],['full']),
        $nav('nav.journal_entries','Journal Entries','Open journal entry records and recurring journal options.','Accounting','journal-entries','journals.view',['icon'=>'JE','keywords'=>['journal register','manual journals','general journal'],'sort_order'=>420],['full']),
        $nav('nav.expense_vouchers','Expense Vouchers','Open the normal expense voucher workflow.','Accounting','expense-vouchers','attachments.write',['icon'=>'EX','keywords'=>['record expense','cash expense','expense register','receipt'],'sort_order'=>425],['full']),
        $nav('nav.day_book','Day Book','Open chronological source vouchers and GL lines.','Accounting','day-book','journals.view',['icon'=>'DB','keywords'=>['journal history','source entries','voucher register'],'sort_order'=>430],['full']),
        $nav('nav.trial_balance','Trial Balance','Open the period Trial Balance.','Accounting','trial-balance','reports.view',['icon'=>'TB','keywords'=>['trial balance report','debits credits'],'sort_order'=>440]),
        $nav('nav.account_ledger','Account Ledger','Open the General Ledger account activity report.','Accounting','account-ledger','journals.view',['icon'=>'AL','keywords'=>['gl account ledger','account activity','running balance'],'sort_order'=>450]),
        $nav('nav.chart_of_accounts','Chart of Accounts','Open the active company Chart of Accounts.','Accounting','chart-of-accounts','company.view',['icon'=>'CO','keywords'=>['coa','account list','gl accounts'],'sort_order'=>460]),
        $nav('nav.opening_balances','Opening Balances','Open manual opening balance setup.','Accounting','opening-balances','company.view',['icon'=>'OB','keywords'=>['opening trial balance','starting balances','beginning balances'],'sort_order'=>470]),
        $nav('nav.gifi_report','GIFI Report','Open GIFI-mapped account balances.','Accounting','gifi-report','reports.view',['icon'=>'GF','keywords'=>['tax mapping','gifi accounts'],'sort_order'=>480],['full']),

        $nav('nav.advanced_accounting','Advanced Accounting','Open advanced accounting workspaces.','Accounting','advanced-accounting','company.view',['icon'=>'GL','keywords'=>['advanced accounting'],'sort_order'=>490],['full']),
        $nav('nav.reports','Reports','Open the current Reports Centre.','Reports','reports','reports.view',[
            'icon'=>'RP','keywords'=>['financial reports','management reports','how is my business doing'],'sort_order'=>500,
            'mode_labels'=>['guided'=>'How Is My Business Doing?','full'=>'Reports'],
        ]),

        // Query/report actions.
        $a('bank.transactions.query','Find Bank Transactions','Filter imported bank transactions using structured server-side criteria.','Banking','bank-review','read','banking.view',['guided','full'],[],['description','direction','amount','date','bankAccount','batch','status','category','tax','reconciled'],true,'server-side transaction query','bank transaction query service','none',false,false,true,true,'bank-review'),
        $a('bank.transactions.select','Select Bank Transactions','Change selection inside the current transaction result set.','Banking','bank-review','read','banking.view',['guided','full'],['resultSetId'],['selectionFilter'],true,'result-set ownership','result-set selection service','none'),
        $report('report.profit_loss','Profit & Loss','Income, expenses and net income for a typed preset, trailing or custom reporting period.','profit-loss',[],['mode','preset','count','unit','start','end','comparison'],['eligible'=>true,'icon'=>'P&L','keywords'=>['p&l','pnl','income statement','profit and loss','earnings'],'sort_order'=>510]),
        $report('report.balance_sheet','Balance Sheet','Assets, liabilities and equity at one authoritative as-of date.','balance-sheet',[],['mode','asOf'],['eligible'=>true,'icon'=>'BS','keywords'=>['statement of financial position','assets liabilities equity'],'sort_order'=>520]),
        $report('report.cash_flow','Cash Flow','Historical operating, investing and financing cash movements for a typed range.','cash-flow',[],['mode','preset','count','unit','start','end'],['eligible'=>true,'icon'=>'CF','keywords'=>['cashflow','cash movement','operating investing financing'],'sort_order'=>530]),
        $a('analysis.cash_forecast','Cash Forecast','Build a deterministic forward cash forecast by weeks, months, years or custom dates.','Reports','cash-forecast','forecast','reports.view',['guided','full'],[],['mode','count','unit','start','end','bucketUnit','scenarioId'],true,'current_company_typed_forecast_period','financial_cash_forecast','none',false,false,true,true,'cash-forecast'),
        $a('analysis.budget_variance','Budget Variance Analysis','Compare an existing current-company budget with posted actual results.','Reports','financial-analyst','compare','reports.view',['guided','full'],[],['budgetId'],true,'current_company_budget','financial_budget_variance','none',false,false,true,true,'financial-analyst'),
        $a('analysis.financial_scenario','Financial Scenario','Open saved cash-planning assumptions for review or controlled editing.','Reports','financial-analyst','prepare','reports.view',['guided','full'],[],['scenarioId'],true,'current_company_scenario_revision','financial_scenario_update','none',false,false,true,true,'financial-analyst'),
        $report('report.trial_balance','Trial Balance','Run the Trial Balance for a typed preset, trailing or custom range.','trial-balance',[],['mode','preset','count','unit','start','end']),
        $report('report.ar_aging','Receivable Ageing','Open customer invoices grouped by overdue period.','ar-aging',[],['asOf'],[
            'eligible'=>true,'icon'=>'AR','keywords'=>['ar aging','ar ageing','customer aging','customer ageing','money owed to me','receivables aging'],'sort_order'=>540,
            'mode_labels'=>['guided'=>'Money Owed To Me','full'=>'Receivable Ageing'],
        ]),
        $report('report.ap_aging','Payables Ageing','Open vendor invoices grouped by overdue period.','ap-aging',[],['asOf'],[
            'eligible'=>true,'icon'=>'AP','keywords'=>['ap aging','ap ageing','vendor aging','supplier ageing','money i owe','payables aging'],'sort_order'=>550,
            'mode_labels'=>['guided'=>'Money I Owe','full'=>'Payables Ageing'],
        ]),
        $report('report.account_ledger','Account Ledger','Run the existing account ledger report.','account-ledger',['account'],['from','to']),
        $nav('report.general_ledger_detail','General Ledger Detail','Open posted account activity with entry references.','Reports','report-general-ledger','reports.view',['icon'=>'GL','keywords'=>['detailed ledger','posted account activity'],'sort_order'=>560]),
        $nav('report.inventory','Inventory Report','Open the product catalogue and invoice activity report.','Reports','report-inventory','reports.view',['icon'=>'IN','keywords'=>['product report','inventory items','stock'],'sort_order'=>570]),
        $nav('report.customer_balances','Customer Balances','Open outstanding balances summarized by customer.','Reports','report-customer-balances','reports.view',['icon'=>'CB','keywords'=>['customer balance summary','receivable balances'],'sort_order'=>580]),
        $nav('report.vendor_balances','Vendor Balances','Open outstanding balances summarized by vendor.','Reports','report-vendor-balances','reports.view',['icon'=>'VB','keywords'=>['supplier balance summary','payable balances'],'sort_order'=>590]),
        $nav('report.expense_register','Expense Register','Open posted costs by vendor, category and payment account.','Reports','report-expense-register','reports.view',['icon'=>'EX','keywords'=>['expenses','cost register','purchase costs'],'sort_order'=>600]),
        $nav('report.bank_reconciliation_summary','Bank Reconciliation Summary','Open book, statement and reconciliation differences.','Reports','report-bank-reconciliation','reports.view',['icon'=>'RS','keywords'=>['reconciliation report','bank difference'],'sort_order'=>610]),
        $nav('report.bank_transactions','Bank Transaction Report','Open imported bank activity and review status.','Reports','report-bank-transactions','reports.view',['icon'=>'BT','keywords'=>['bank report','statement transactions'],'sort_order'=>620]),
        $nav('report.tax_summary','Tax Summary','Open GST/HST/PST control balances and net tax position.','Reports','report-tax-summary','reports.view',['icon'=>'TX','keywords'=>['gst hst pst','sales tax report','tax payable'],'sort_order'=>630]),
        $nav('report.pay_run_register','Payroll Reports','Open the Pay Run Register and payroll reporting records.','Reports','report-payroll-runs','payroll.view',['icon'=>'PR','keywords'=>['payroll report','pay run register','payroll runs'],'sort_order'=>640]),
        $nav('report.currency_exposure','Currency Exposure','Open active currencies and exchange-rate exposure.','Reports','report-currency-exposure','reports.view',['icon'=>'FX','keywords'=>['foreign exchange','currency report','fx exposure'],'sort_order'=>650]),
        $nav('report.budget_actual','Budget Versus Actual','Open planned and actual results by account.','Reports','report-budget-actual','reports.view',['icon'=>'BA','keywords'=>['budget vs actual','variance report'],'sort_order'=>660]),
        $nav('report.fixed_assets','Fixed Asset Register','Open asset cost, depreciation and book value.','Reports','report-fixed-assets','reports.view',['icon'=>'FA','keywords'=>['asset register','depreciation report'],'sort_order'=>670]),

        $nav('nav.payroll','Payroll Support','Open Payroll Support: payroll calculations and accounting records.','Payroll','payroll','payroll.view',['icon'=>'PL','keywords'=>['payroll dashboard','pay employees'],'sort_order'=>700],['full']),
        $nav('nav.payroll_calculator','Payroll Calculator','Open the no-record Quick Payroll Calculator.','Payroll','payroll-calculator','payroll.view',['icon'=>'PC','keywords'=>['quick calculation','pay calculator','payroll estimate'],'sort_order'=>710],['full']),
        $nav('nav.payroll_employees','Employees','Open payroll employee records.','Payroll','payroll-employees','payroll.view',['icon'=>'EM','keywords'=>['staff','employee setup','payroll people'],'sort_order'=>720],['full']),
        $nav('nav.payroll_runs','Payroll Runs','Open the Pay Run Register.','Payroll','payroll-runs','payroll.view',['icon'=>'PR','keywords'=>['pay runs','pay run register','run payroll'],'sort_order'=>730],['full']),
        $nav('nav.payroll_verification','Payroll Verification','Open retained payroll calculation checks.','Payroll','payroll-verification','payroll.view',['icon'=>'PV','keywords'=>['verify deductions','official calculator check'],'sort_order'=>740],['full']),
        $nav('nav.payroll_remittance','Payroll Remittance Records','Record payroll source-deduction remittances paid outside Tegh.','Payroll','payroll-remittance','payroll.view',['icon'=>'CR','keywords'=>['cra remittance','source deductions','payroll tax payment'],'sort_order'=>750],['full']),
        $nav('nav.payroll_history','Payroll History','Open completed, reversed and deleted pay-run history.','Payroll','payroll-history','payroll.view',['icon'=>'PH','keywords'=>['payroll reports','completed pay runs','pay history'],'sort_order'=>760],['full']),

        $nav('nav.settings','Settings','Open Company & Workspace Settings.','Settings','settings','company.view',['icon'=>'ST','keywords'=>['preferences','setup','configuration'],'sort_order'=>800]),
        $nav('nav.company_setup','Company Setup','Open manual company accounting setup.','Settings','company-setup','company.view',['icon'=>'CS','keywords'=>['accounting setup','company configuration'],'sort_order'=>810]),
        $nav('nav.company_details','Company Details','Open company identity, calendar and tax details.','Settings','company-details','company.settings',['icon'=>'CD','keywords'=>['business details','fiscal year','gst number'],'sort_order'=>820]),
        $nav('nav.tax_settings','Tax Settings','Open company GST/HST/PST settings.','Settings','tax-settings','company.settings',['icon'=>'TX','keywords'=>['gst hst pst settings','sales tax setup','tax accounts'],'sort_order'=>830]),
        $nav('nav.invoice_templates','Invoice Templates','Open customer invoice layout and branding settings.','Settings','invoice-templates','company.settings',['icon'=>'IT','keywords'=>['invoice design','logo template','invoice branding'],'sort_order'=>840]),
        $nav('nav.users','Users & Permissions','Open company users, roles and permissions.','Settings','users','users.manage',['icon'=>'US','keywords'=>['user management','access roles','company admin editor viewer'],'sort_order'=>850]),
        $nav('nav.audit_history','Audit History','Open company accounting and access history.','Settings','audit-history','audit.view',['icon'=>'AH','keywords'=>['audit trail','integrity','history'],'sort_order'=>860]),
        $nav('nav.data_import','Data Import','Open the company Data Import Centre.','Data Import','data-import','customers.write',['icon'=>'DI','keywords'=>['import centre','import center','spreadsheet imports'],'sort_order'=>870]),
        $nav('nav.bookkeeping_mode','Bookkeeping Mode','Choose Guided or Full Accounting presentation.','Settings','bookkeeping-mode-settings','company.view',['icon'=>'BM','keywords'=>['guided mode','full accounting','experience mode'],'sort_order'=>880]),
        $nav('nav.sales_purchase_defaults','Sales & Purchase Defaults','Open customer, vendor and payment-term defaults.','Settings','sales-purchase-defaults','company.view',['icon'=>'SP','keywords'=>['sales defaults','purchase defaults','payment terms'],'sort_order'=>890]),
        $nav('nav.tegh_preferences','Tegh Preferences','Open interface and Ask Tegh preferences.','Settings','tegh-preferences','company.view',['icon'=>'TP','keywords'=>['interface preferences','notification badge','navigation density'],'sort_order'=>900]),
        $nav('nav.tegh_learned_rules','Tegh AI Learned Rules','Review, disable or retire company-specific Tegh AI learned suggestions.','Settings','tegh-learned-rules','company.view',['icon'=>'LR','keywords'=>['ai learned rules','learning memory','category suggestions'],'sort_order'=>905]),
        $nav('nav.tegh_improvement_lab','Tegh AI Improvement Lab','Review sanitized failure clusters, regression-gated behavior candidates and rollback history.','Settings','tegh-improvement-lab','company.view',['icon'=>'AI','keywords'=>['ai improvement lab','self improvement','agent qa','agent performance'],'owner_only'=>true,'sort_order'=>907]),
        $nav('nav.backup','Backup & Restore','Open company backup and restore controls.','Settings','backup','company.settings',['icon'=>'BK','keywords'=>['download backup','restore tegh archive'],'sort_order'=>910]),
        $nav('nav.currencies','Currency Exchange Rates','Open company currency and exchange-rate settings.','Settings','currencies','company.settings',['icon'=>'FX','keywords'=>['foreign currency','exchange rate','multi currency'],'sort_order'=>920]),
        $nav('nav.period_locking','Period Locking','Open accounting period controls.','Settings','period-locking','company.view',['icon'=>'LK','keywords'=>['close period','lock books','unlock period'],'sort_order'=>930]),
        $nav('nav.recurring_transactions','Recurring Transactions','Open recurring invoice, bill and journal profiles.','Settings','recurring-transactions','company.view',['icon'=>'RT','keywords'=>['scheduled entries','repeat invoices','recurring bills'],'sort_order'=>940]),
        $nav('nav.company_create','Add Company or Client File','Open the normal new-company setup workflow.','Settings','company-create','company.view',['icon'=>'C+','keywords'=>['new company','add client file','create business'],'sort_order'=>950]),
        $nav('nav.my_account','My Account','Open signed-in account and company access controls.','Settings','my-account','company.view',['icon'=>'ME','keywords'=>['profile','password','account access'],'sort_order'=>960]),
        $nav('nav.bookkeeping_guide','Bookkeeping Guide','Open Tegh tutorials and bookkeeping workflow guidance.','Settings','tutorial-hub','company.view',['icon'=>'BG','keywords'=>['tutorial','help guide','guided work'],'sort_order'=>970]),
        $nav('nav.faq','FAQ','Open the Tegh Help Centre.','Settings','faq','company.view',['icon'=>'?','keywords'=>['frequently asked questions','help centre','knowledge base'],'sort_order'=>980]),
        $nav('nav.support','Support','Open support requests and temporary-access controls.','Settings','support','company.view',['icon'=>'SU','keywords'=>['contact support','report problem','help'],'sort_order'=>990]),
        $nav('nav.payroll_rates','Payroll Rates','Open statutory payroll-rate maintenance.','Settings','rates','payroll.view',['icon'=>'RR','keywords'=>['statutory rates','cpp ei tax tables'],'owner_only'=>true,'sort_order'=>1000]),
        $nav('nav.platform_administration','Platform Administration','Open tenant and platform controls.','Settings','platform-administration','company.view',['icon'=>'PA','keywords'=>['platform owner','tenant administration'],'owner_only'=>true,'sort_order'=>1010]),
        $nav('nav.qa_guardian','QA Centre','Run protected read-only deployment checks and inspect isolated synthetic QA evidence.','Settings','qa-guardian','company.view',['icon'=>'QA','keywords'=>['qa centre','quality assurance','release checks','deployment checks','stress evidence'],'owner_only'=>true,'sort_order'=>1015]),
        $nav('nav.system_incidents','System Incident Audit','Open restricted technical incident history.','Settings','system-incidents','company.view',['icon'=>'SI','keywords'=>['platform incidents','technical audit','errors'],'owner_only'=>true,'sort_order'=>1020]),

        $nav('nav.import_customers','Import Customers','Open the Customer Import card.','Data Import','data-import-customers','customers.write',['icon'=>'CU','keywords'=>['customer spreadsheet','customer csv'],'sort_order'=>1100]),
        $nav('nav.import_vendors','Import Vendors','Open the Vendor Import card.','Data Import','data-import-vendors','vendors.write',['icon'=>'VE','keywords'=>['supplier import','vendor spreadsheet'],'sort_order'=>1110]),
        $nav('nav.import_products','Import Products & Services','Open the Products & Services Import card.','Data Import','data-import-products','invoices.write',['icon'=>'PS','keywords'=>['item import','service import','product csv'],'sort_order'=>1120]),
        $nav('nav.import_customer_invoices','Import Customer Invoices','Open the Customer Invoice Import card.','Data Import','data-import-customer-invoices','invoices.write',['icon'=>'CI','keywords'=>['sales invoice import','customer invoice spreadsheet'],'sort_order'=>1130]),
        $nav('nav.import_vendor_invoices','Import Vendor Invoices','Open the Vendor Invoice Import card.','Data Import','data-import-vendor-invoices','bills.write',['icon'=>'VI','keywords'=>['import vendor bills','supplier invoice import','purchase invoice spreadsheet'],'sort_order'=>1140]),
        $nav('nav.import_chart_of_accounts','Import Chart of Accounts','Open the Chart of Accounts Import card.','Data Import','data-import-chart-of-accounts','company.settings',['icon'=>'CO','keywords'=>['import coa','gl account import'],'sort_order'=>1150]),
        $nav('nav.import_opening_balances','Import Opening Balances','Open the Opening Balances Import card.','Data Import','data-import-opening-balances','company.settings',['icon'=>'OB','keywords'=>['opening trial balance import','starting balances import'],'sort_order'=>1160]),
        $nav('nav.import_coa_opening_balances','Import Chart of Accounts and Opening Balances','Open the combined Chart of Accounts and Opening Balances Import card.','Data Import','data-import-coa-opening-balances','company.settings',['icon'=>'CB','keywords'=>['combined coa opening import','chart and balances import'],'sort_order'=>1170]),
        $nav('nav.import_opening_customer_invoices','Import Opening Customer Invoices','Open the cutover-only unpaid customer invoice Import card.','Data Import','data-import-opening-customer-invoices','invoices.write',['icon'=>'OI','keywords'=>['opening ar invoices','cutover customer invoices','historical receivables'],'sort_order'=>1180]),
        $nav('nav.import_opening_vendor_bills','Import Opening Vendor Bills','Open the cutover-only unpaid vendor bill Import card.','Data Import','data-import-opening-vendor-bills','bills.write',['icon'=>'OV','keywords'=>['opening ap bills','cutover vendor invoices','historical payables'],'sort_order'=>1190]),

        $nav('nav.budgets','Budgets','Open the budget and variance workspace.','Advanced Accounting','advanced-budgets','reports.view',['icon'=>'BU','keywords'=>['planning','budget variance'],'sort_order'=>1200],['full']),
        $nav('nav.fixed_assets','Fixed Assets','Open the fixed-asset and depreciation workspace.','Advanced Accounting','advanced-assets','reports.view',['icon'=>'FA','keywords'=>['asset register','depreciation schedules'],'sort_order'=>1210],['full']),
        $nav('nav.analytics','Analytics','Open management analytics.','Advanced Accounting','advanced-analytics','reports.view',['icon'=>'AN','keywords'=>['management analysis','dimensions'],'sort_order'=>1220],['full']),
        $nav('nav.collections','Collections','Open customer invoice follow-up.','Advanced Accounting','advanced-collections','invoices.view',['icon'=>'CL','keywords'=>['overdue follow up','customer collections'],'sort_order'=>1230],['full']),
        $nav('nav.document_intake','Document Intake','Review private vendor-bill and customer-invoice documents using fully local extraction.','Agent Center','document-intake','attachments.write',['icon'=>'DI','keywords'=>['scan invoice','scan bill','ocr document','upload document','document inbox'],'sort_order'=>35],['guided','full'],'navigation'),
        $nav('nav.collection_drafts','Collection Drafts','Review, edit, approve and explicitly send overdue-invoice follow-up drafts.','Receivables','collection-drafts','invoices.view',['icon'=>'CD','keywords'=>['collection messages','overdue email drafts','customer follow up drafts'],'sort_order'=>280]),

        // Build 5420 curated Command Centre aliases. These live in the same
        // server registry as Ask Tegh and Quick Actions; the browser receives
        // labels and typed parameters, never a second executable catalogue.
        $a('bank.matches.analyze','Analyze possible matches','Read deterministic matching and posting candidates for explicitly selected statement rows.','Banking','authorized-match-post','read','banking.view',['guided','full'],['accountId','periodStart','periodEnd','bankTransactionIds'],[],true,'current company account, period and selected rows','tegh_recon_analysis','none',false,false,true,true,'authorized-match-post',true,['recommended_screens'=>['bank-review','bank-reconciliation']]),
        $a('bank.matches.review_exact','Review exact matches','Show only canonical one-to-one source links from the current deterministic proposal set.','Banking','authorized-match-post','read','banking.view',['guided','full'],['accountId','periodStart','periodEnd','bankTransactionIds'],[],true,'current company exact-source relationships','tegh_recon_analysis','none',false,false,true,true,'authorized-match-post',true,['recommended_screens'=>['bank-review','bank-reconciliation']]),
        $a('bank.matches.match_selected','Match selected posted transactions','Open the protected review path for selected bank and already-posted book records.','Banking','authorized-match-post','financial_commit','banking.match',['guided','full'],['accountId','periodStart','periodEnd','bankTransactionIds'],['journalEntryIds'],true,'fresh signed proposal required','operations_reconciliation_match_service','explicit',true,false,true,false,'bank-reconciliation',true,['recommended_screens'=>['bank-review']]),
        $a('bank.postings.prepare_selected','Prepare postings for selected bank lines','Open deterministic account, tax and document choices without posting anything.','Banking','authorized-match-post','prepare','banking.match',['guided','full'],['accountId','periodStart','periodEnd','bankTransactionIds'],[],true,'current company selected pending rows','tegh_recon_analysis','none',false,false,true,false,'authorized-match-post',true,['recommended_screens'=>['bank-review','bank-reconciliation']]),
        $a('bank.duplicates.find','Find duplicates','Read imported bank lines with repeated source fingerprints in the selected account and period.','Banking','bank-review','read','banking.view',['guided','full'],['accountId','periodStart','periodEnd'],[],true,'current company source fingerprints','tegh_command_bank_duplicates','none',false,false,true,true,'bank-review'),
        $a('bank.reconciliation.open','Open reconciliation','Open the existing statement/period proof workspace.','Banking','bank-reconciliation','navigation','banking.reconcile',['guided','full'],[],[],true,'existing reconciliation permission','existing reconciliation workflow','none',false,false,true,true,'bank-reconciliation'),
        $a('receivables.overdue','Show overdue customer invoices','Read current open customer invoices whose due date has passed.','Receivables','ar-aging','read','invoices.view',['guided','full'],[],['asOf'],true,'current company open invoices','tegh_command_aging_data','none',false,false,true,true,'ar-aging'),
        $a('receivables.expected_collections','Expected collections','Read open customer invoices expected in a selected due-date window.','Receivables','financial-analyst','read','invoices.view',['guided','full'],[],['from','to'],true,'current company open invoices','tegh_command_due_items','none',false,false,true,true,'financial-analyst'),
        $a('collections.reminders.prepare','Prepare collection reminders','Open deterministic overdue-invoice reminder drafts for explicit review and send approval.','Receivables','collection-drafts','prepare','invoices.write',['guided','full'],[],[],true,'current company overdue invoices','native_collection_prepare','none',false,false,true,false,'collection-drafts'),
        $a('payables.overdue','Show overdue vendor invoices','Read current open vendor invoices whose due date has passed.','Payables','ap-aging','read','bills.view',['guided','full'],[],['asOf'],true,'current company open bills','tegh_command_aging_data','none',false,false,true,true,'ap-aging'),
        $a('payables.upcoming','Upcoming payments','Read open vendor invoices due in a selected date window.','Payables','ap-aging','read','bills.view',['guided','full'],[],['from','to'],true,'current company open bills','tegh_command_due_items','none',false,false,true,true,'ap-aging'),
        $a('payables.payment_history','Vendor payment history','Read posted vendor payments in the selected date window.','Payables','vendor-payments','read','bills.view',['guided','full'],[],['from','to'],true,'current company posted vendor payments','tegh_command_vendor_payment_history','none',false,false,true,true,'vendor-payments'),
        $a('payroll.readiness','Check Payroll readiness','Read settings, employee eligibility and draft verification state without finalizing or posting.','Payroll','payroll','read','payroll.view',['guided','full'],[],[],true,'current company payroll workspace','payroll_workspace_data','none',false,false,true,true,'payroll'),
        $a('payroll.run.start','Start a new Pay Run','Open the native protected draft Pay Run form. No General Ledger entry is created.','Payroll','payroll-runs','prepare','payroll.manage',['guided','full'],[],[],true,'active company employees and payroll settings','handle_payroll_run_create','none',false,false,true,false,'payroll-runs'),
        $a('payroll.register.open','Open Pay Run Register','Open current-company Pay Run drafts and history.','Payroll','payroll-runs','navigation','payroll.view',['guided','full'],[],[],true,'current company payroll permission','payroll_workspace_data','none',false,false,true,true,'payroll-runs'),
        $a('payroll.verification.open','Open Payroll verification','Open retained official-deduction verification controls.','Payroll','payroll-verification','navigation','payroll.view',['guided','full'],[],[],true,'current company payroll permission','existing payroll verification workflow','none',false,false,true,true,'payroll-verification'),
        $a('report.current.explain','Explain current report','Explain the selected report using its verified period and current company records.','Reports','reports','read','reports.view',['guided','full'],['reportId'],['mode','preset','count','unit','start','end','asOf','comparison'],true,'current company report permission','existing deterministic report services','none',false,false,true,true,'reports'),
        $a('month_end.checks','Run Month-End checks','Open deterministic period locks, reconciliations, balances and close-readiness evidence.','Month-End','month-end-close','read','reports.view',['guided','full'],[],['period'],true,'current company close evidence','native_agent_month_end_close_collect','none',false,false,true,true,'month-end-close'),

        // Native Agent scans write only company-scoped run/finding/notification
        // metadata. They never cross the existing accounting commit boundary.
        $a('native_agent.run_all','Run Native Agent Supervisor','Run every enabled deterministic specialist scan.','Agent Center','agent-center','prepare','company.view',['guided','full'],[],['period'],true,'native_agent_policy_and_company_scope','native_agent_supervisor_run','none',false,false,true,true,'agent-center'),
        $a('native_agent.run_bookkeeping','Run Bookkeeping Agent','Scan current-company bookkeeping exceptions without posting.','Agent Center','agent-center','prepare','banking.view',['guided','full'],[],[],true,'native_agent_policy_and_company_scope','native_agent_bookkeeping_collect','none',false,false,true,true,'agent-center'),
        $a('native_agent.run_reconciliation','Run Reconciliation Agent','Build read-only candidates using the existing reconciliation engine.','Agent Center','agent-center','prepare','banking.view',['guided','full'],[],[],true,'native_agent_policy_and_company_scope','operations_reconciliation_suggestions_data','none',false,false,true,true,'agent-center'),
        $a('native_agent.run_month_end_close','Run Month-End Close Agent','Build a deterministic close-readiness checklist for a supported period.','Agent Center','month-end-close','prepare','reports.view',['guided','full'],[],['period'],true,'native_agent_policy_and_company_scope','native_agent_month_end_close_collect','none',false,false,true,true,'month-end-close'),
        $a('native_agent.run_accounts_payable','Run Accounts Payable Agent','Scan current-company bills, vendors and private document state without posting or paying.','Agent Center','agent-center','prepare','bills.view',['guided','full'],[],[],true,'native_agent_policy_and_company_scope','native_agent_accounts_payable_collect','none',false,false,true,true,'agent-center'),
        $a('native_agent.run_accounts_receivable','Run Accounts Receivable Agent','Scan current-company invoices, customers and follow-up state without issuing or settling.','Agent Center','agent-center','prepare','invoices.view',['guided','full'],[],[],true,'native_agent_policy_and_company_scope','native_agent_accounts_receivable_collect','none',false,false,true,true,'agent-center'),
        $a('native_agent.run_financial_analyst','Run Financial Analyst','Scan deterministic liquidity and budget-variance conditions without changing accounting records.','Agent Center','financial-analyst','prepare','reports.view',['guided','full'],[],[],true,'native_agent_policy_and_company_scope','native_agent_financial_analyst_collect','none',false,false,true,true,'financial-analyst'),
        $a('native_agent.run_payroll_tax','Run Payroll/Tax Agent','Scan current-company payroll, source-deduction, rate, levy, sales-tax and period-lock evidence without filing, paying or posting.','Agent Center','payroll-tax-center','prepare','payroll.view',['guided','full'],[],[],true,'native_agent_policy_and_company_scope','payroll_tax_agent_collect','none',false,false,true,true,'payroll-tax-center'),
        $a('native_agent.finding_snooze','Auto-snooze Finding','Apply an owner-approved bounded snooze to one current low-risk finding.','Agent Center','payroll-tax-center','metadata','company.settings',['guided','full'],['findingId'],['days'],true,'autonomy_policy_and_fresh_evidence','payroll_tax_autonomy_execute','none',false,false,false,false,'payroll-tax-center'),
        $a('native_agent.finding_assign','Auto-assign Finding','Assign one current low-risk finding to the owner-approved active company member.','Agent Center','payroll-tax-center','metadata','company.settings',['guided','full'],['findingId'],['userId'],true,'autonomy_policy_and_fresh_evidence','payroll_tax_autonomy_execute','none',false,false,false,false,'payroll-tax-center'),
        $a('native_agent.finding_task','Auto-prepare Finding Task','Prepare a normal user-scoped review task from one current low-risk finding without committing accounting.','Agent Center','payroll-tax-center','metadata','company.settings',['guided','full'],['findingId'],[],true,'autonomy_policy_and_fresh_evidence','payroll_tax_autonomy_execute','none',false,false,false,false,'payroll-tax-center'),
        $a('native_agent.scan_refresh','Auto-refresh Specialist','Rerun the finding specialist under current company policy without changing accounting records.','Agent Center','payroll-tax-center','metadata','company.settings',['guided','full'],['findingId'],[],true,'autonomy_policy_and_fresh_evidence','payroll_tax_autonomy_execute','none',false,false,false,false,'payroll-tax-center'),
        $a('native_agent.finding_resolved_dismiss','Auto-dismiss Resolved Notice','Dismiss only the notification for a deterministically resolved informational finding.','Agent Center','payroll-tax-center','metadata','company.settings',['guided','full'],['findingId'],[],true,'autonomy_policy_and_fresh_evidence','payroll_tax_autonomy_execute','none',false,false,false,false,'payroll-tax-center'),
        $a('collections.prepare','Prepare Collection Draft','Prepare a deterministic customer follow-up draft from a current overdue invoice for human review.','Receivables','collection-drafts','prepare','invoices.write',['guided','full'],['invoice'],['level'],true,'current_company_invoice_and_customer_snapshot','native_collection_prepare','none',false,false,true,true,'collection-drafts'),

        // Executable record/accounting actions.
        $a('customer.create','Create Customer','Prepare and create a customer using the existing customer service.','Receivables','customers','prepare','customers.write',['guided','full'],['name'],['email','phone','billingAddress','province'],true,'customer_record_values','create_customer_record','explicit',false,false,true,true,'customers'),
        $a('customer.find','Find Customer','Find an active customer in the current company.','Receivables','customers','read','customers.view',['guided','full'],['query'],[],true,'company scoped lookup','company-scoped customer lookup','none'),
        $a('customer.balance','Customer Balance','Read current posted customer subledger balances.','Receivables','ledger-customer','read','customers.view',['guided','full'],[],['query'],true,'company scoped posted-document lookup','company-scoped customer balance lookup','none'),
        $a('customer.edit','Edit Customer','Open the existing protected customer edit workflow.','Receivables','customers','prepare','customers.write',['guided','full'],['customer'],[],true,'existing customer edit protections','existing customer PUT workflow','context',false,false,true,false,'customers'),
        $a('vendor.create','Create Vendor','Prepare and create a vendor using the existing vendor service.','Payables','vendors','prepare','vendors.write',['guided','full'],['name'],['email','address','defaultTermsDays','defaultExpenseAccountId','currency'],true,'vendor_record_values','create_vendor_record','explicit',false,false,true,true,'vendors'),
        $a('vendor.find','Find Vendor','Find an active vendor in the current company.','Payables','vendors','read','vendors.view',['guided','full'],['query'],[],true,'company scoped lookup','company-scoped vendor lookup','none'),
        $a('vendor.balance','Vendor Balance','Read current posted vendor subledger balances.','Payables','ledger-vendor','read','vendors.view',['guided','full'],[],['query'],true,'company scoped posted-document lookup','company-scoped vendor balance lookup','none'),
        $a('vendor.edit','Edit Vendor','Open the existing protected vendor edit workflow.','Payables','vendors','prepare','vendors.write',['guided','full'],['vendor'],[],true,'existing vendor edit protections','existing vendor PUT workflow','context',false,false,true,false,'vendors'),
        $a('invoice.create','Create Customer Invoice','Open/prepare the existing customer invoice workflow.','Receivables','customer-invoice','prepare','invoices.write',['guided','full'],['customer','invoiceDetails'],['lines','date','tax'],true,'existing invoice validation','existing invoice service','context',false,false,true,false,'customer-invoice'),
        $a('bill.create','Create Vendor Invoice','Open/prepare the existing vendor invoice workflow.','Payables','bills','prepare','bills.write',['guided','full'],['vendor','billDetails'],['amount','date','tax'],true,'existing bill validation','existing bill service','context',false,false,true,false,'bills'),
        $a('payment.customer','Record Customer Payment','Open/prepare the existing customer payment workflow.','Receivables','customer-payments','financial_commit','payments.write',['guided','full'],[],['customer','invoice','amount'],true,'existing payment validation','existing payment service','explicit',true,false,true,false,'customer-payments'),
        $a('payment.vendor','Record Vendor Payment','Open/prepare the existing vendor payment workflow.','Payables','vendor-payments','financial_commit','payments.write',['guided','full'],[],['vendor','bill','amount'],true,'existing payment validation','existing payment service','explicit',true,false,true,false,'vendor-payments'),
        $a('journal.prepare','Prepare Journal','Use the Tegh Journal Copilot to infer and preview a genuine GL adjustment.','General Ledger','manual-journal','prepare','journals.write',['guided','full'],['businessEvent'],['date','memo'],true,'agent_resolve_posting_payload','agent/journal-compose + posting-preview','explicit',false,false,true,true,'manual-journal'),
        $a('journal.post','Post Journal','Post an authorized journal using the existing journal posting service.','General Ledger','manual-journal','financial_commit','journals.write',['guided','full'],['authorizationId'],[],true,'agent_resolve_posting_payload','agent/posting-authorize','explicit',true,false,true,true,'day-book'),
        $a('bank.transactions.categorize','Categorize Bank Transactions','Apply an account/tax category without posting.','Banking','bank-review','prepare','banking.match',['guided','full'],['resultSetId','account'],['taxTreatment'],true,'pending/result-set/account validation','bank_transaction_categorize_service','none',false,false,true,true,'bank-review'),
        $a('bank.transactions.post','Post Bank Transactions','Post eligible result-set transactions through the existing bank posting engine.','Banking','bank-review','financial_commit','banking.match',['guided','full'],['resultSetId'],['account','taxTreatment'],true,'fresh status/account/tax/period/control validation','bank_transaction_post_service','explicit',true,false,true,true,'bank-review'),
        $a('bank.transactions.exclude','Exclude Bank Transactions','Exclude eligible pending bank rows.','Banking','bank-review','destructive','banking.match',['guided','full'],['resultSetId'],[],true,'pending/result-set validation','bank_transaction_review_state_service','explicit',false,true,true,true,'bank-review'),
        $a('bank.transactions.delete','Delete Imported Bank Transactions','Delete eligible unposted imported rows.','Banking','bank-review','destructive','banking.match',['guided','full'],['resultSetId'],[],true,'existing delete protections','bank_transaction_delete_service','explicit',false,true,true,true,'bank-review'),
        $a('bank.reconcile','Reconcile Bank','Use the existing reconciliation workflow.','Banking','bank-reconciliation','financial_commit','banking.reconcile',['guided','full'],[],['bankAccount','period'],true,'existing reconciliation validation','existing reconciliation service','explicit',true,false,true,false,'bank-reconciliation'),
        $a('bank.transactions.match_post','Match & Post Selected','Prepare and atomically confirm selected bank matches and postings.','Banking','bank-reconciliation','financial_commit','banking.match',['guided','full'],['accountId','periodStart','periodEnd','bankTransactionIds'],['decisions'],true,'authorized bank proposal snapshot validation','operations/authorized-match-post','explicit',true,false,true,true,'bank-reconciliation',true,['recommended_screens'=>['bank-review','authorized-match-post']]),
        $a('payroll.calculate','Calculate Payroll','Open/use the existing payroll calculation workflow.','Payroll','payroll','prepare','payroll.manage',['guided','full'],[],['period','employees'],true,'existing payroll validation','existing payroll calculator','context',false,false,true,false,'payroll'),
        $a('payroll.finalize','Finalize Payroll','Finalize through the existing payroll service.','Payroll','payroll','financial_commit','payroll.manage',['guided','full'],[],['runId'],true,'existing payroll validation','existing payroll service','explicit',true,false,true,false,'payroll'),
    ];
    $out=[];
    foreach($items as $item){
        $capability=tegh_action_ai_capability($item);
        $item['ai_capability']=$capability;
        $out[$item['action_id']]=$item;
    }
    return $cached=$out;
}

/**
 * Tegh 4.7.0 Ask Tegh capability boundary. This classification is evaluated
 * server-side from registry metadata, never trusted from the model/client.
 */
function tegh_action_ai_capability(array $action): string
{
    if(!empty($action['financial_commit'])||!empty($action['destructive']))return 'authorize';
    $type=(string)($action['action_type']??'');
    if(in_array($type,['financial_commit','destructive','reverse','void','reconcile','finalize','delete'],true))return 'authorize';
    if($type==='read')return str_starts_with((string)($action['action_id']??''),'report.')?'analyze':'read';
    if($type==='analysis'||$type==='diagnose'||$type==='forecast'||$type==='compare')return 'analyze';
    if($type==='prepare'||$type==='navigation')return 'prepare';
    return 'read';
}

function tegh_ai_mutation_blocked(array $action): bool
{
    return tegh_action_ai_capability($action)==='authorize';
}

function tegh_ai_human_commit_guidance(array $action, array $context=[]): array
{
    $route=(string)($action['success_route']??$action['route']??'home');
    $name=(string)($action['name']??'this action');
    $steps=match((string)($action['action_id']??'')){
        'invoice.create'=>['Open Receivables → Customer Invoices and choose New Invoice.','Choose the customer, invoice date and due date, then add the products/services or invoice lines.','Review quantities, rates, GST/HST/PST treatment and the invoice total.','Use Save as Draft if it still needs review, or click Record and Post yourself when it is ready.'],
        'bill.create'=>['Open Payables → Vendor Invoices and choose New Vendor Invoice.','Choose the vendor, invoice date/due date, expense or asset coding and tax treatment.','Review the bill total and any duplicate warning.','Use Save as Draft if it still needs review, or click Record and Post yourself when it is approved.'],
        'bank.transactions.post'=>['Review the selected transaction categories and tax.','Deselect anything you do not want to post.','Click Post Selected.','Review Tegh’s posting preview and confirm in the normal workflow.'],
        'bank.transactions.exclude'=>['Open Review Transactions.','Select the transactions you want to exclude.','Use the normal Exclude action and review the scope before confirming.'],
        'bank.transactions.delete'=>['Open Review Transactions.','Select only the eligible unposted imported rows.','Use the normal Delete action and confirm the displayed scope.'],
        'journal.post'=>['Review the drafted debit and credit lines.','Open Manual Journal.','Enter or review the prepared fields.','Use the normal Record and Post control when you are satisfied.'],
        'customer.create'=>['Open New Customer.','Review the prepared customer details.','Complete any required fields.','Click Save Customer yourself.'],
        'customer.edit'=>['Open Customers and select the customer.','Review the existing details and the change you need.','Update only the intended fields.','Click Save yourself after review.'],
        'vendor.create'=>['Open New Vendor.','Review the prepared vendor details.','Complete any required fields.','Click Save Vendor yourself.'],
        'vendor.edit'=>['Open Vendors and select the vendor.','Review the existing details and the change you need.','Update only the intended fields.','Click Save yourself after review.'],
        'payment.customer'=>['Open Customer Payments.','Select the customer and open invoice, then enter the receipt date, amount and receiving account.','Review the allocation and accounting effect.','Complete the payment through the normal Tegh workflow yourself.'],
        'payment.vendor'=>['Open Vendor Payments.','Select the vendor and open bill, then enter the payment date, amount and bank account.','Review the allocation and accounting effect.','Complete the payment through the normal Tegh workflow yourself.'],
        'bank.reconcile'=>['Open Bank Reconciliation.','Review Tegh’s suggested matches and outstanding differences.','Resolve any exceptions.','Complete reconciliation yourself in the normal workflow.'],
        'payroll.finalize'=>['Open Payroll.','Review the calculated payroll and employee results.','Resolve any warnings.','Use the normal Finalize control yourself.'],
        default=>['Open the indicated Tegh screen.','Review the prepared information.','Complete the final committing action yourself using the normal Tegh controls.'],
    };
    return ['recognized'=>true,'kind'=>'guided_commit','actionId'=>(string)($action['action_id']??''),'navigation'=>$route,'message'=>'This is guidance for '.$name.' and changes nothing. If this action has a verified Ask Tegh adapter, a separate request will produce one explicit confirmation before any protected change; otherwise Tegh opens the existing protected workflow.','steps'=>$steps,'posted'=>false,'humanCommitOnly'=>true,'context'=>$context];
}

/**
 * Stateless procedural guidance must remain available even when the optional
 * durable Ask Tegh schema has not yet been installed. This keeps read/guide
 * capability separate from conversation/result-set storage.
 */
function tegh_agent_schema_available(): bool
{
    return schema_table_exists('ai_agent_tasks')
        && schema_table_exists('ai_agent_result_sets')
        && schema_table_exists('ai_agent_action_authorizations');
}

function tegh_agent_procedural_guidance_action(string $text): ?string
{
    $q=tegh_normalize_action_text($text);
    $procedural=(bool)preg_match('/\b(?:how (?:do|can|should|would) i|show me how to|walk me through|guide me through|steps? to|what are the steps to|what do i need to do to)\b/u',$q);
    if(!$procedural)return null;
    if(preg_match('/\b(?:vendor|supplier)\s+invoice\b|\bbill\b/u',$q) && preg_match('/\b(?:post|record|enter|create|prepare|save|issue)\b/u',$q))return 'bill.create';
    if(preg_match('/\b(?:customer|sales)?\s*invoice\b/u',$q) && preg_match('/\b(?:post|record|create|prepare|save|issue)\b/u',$q))return 'invoice.create';
    if(preg_match('/\bcustomer\s+(?:payment|receipt)\b/u',$q))return 'payment.customer';
    if(preg_match('/\b(?:vendor|supplier)\s+payment\b|\bpay\s+(?:a\s+)?(?:vendor|supplier|bill)\b/u',$q))return 'payment.vendor';
    if(preg_match('/\breconcil/u',$q) && preg_match('/\bbank/u',$q))return 'bank.reconcile';
    if(preg_match('/\bfinali[sz]e\s+payroll\b/u',$q))return 'payroll.finalize';
    if(preg_match('/\b(?:post|record)\b.*\b(?:journal|general ledger|\bgl\b)\b/u',$q))return 'journal.post';
    if(preg_match('/\b(?:create|add)\s+(?:a\s+)?customer\b/u',$q))return 'customer.create';
    if(preg_match('/\b(?:create|add)\s+(?:a\s+)?(?:vendor|supplier)\b/u',$q))return 'vendor.create';
    if(preg_match('/\bpost\b.*\bbank\s+transactions?\b|\bpost\s+(?:selected|these|those)\s+transactions?\b/u',$q))return 'bank.transactions.post';
    return null;
}



/**
 * Deterministic command normalization is intentionally conservative. It fixes
 * common application-word typos, but never uses fuzzy matching to authorize a
 * financial commit or destructive operation.
 */
function tegh_normalize_action_text(string $text): string
{
    if(function_exists('tegh_morphology_normalize_action_text'))return tegh_morphology_normalize_action_text($text);
    $normalized=mb_strtolower(trim($text));
    $normalized=(string)preg_replace('/[^\pL\pN&+.$%\-\s]/u',' ',$normalized);
    return trim((string)preg_replace('/\s+/u',' ',$normalized));
}

function tegh_reserved_entity_candidate(string $name): bool
{
    $candidate=trim(tegh_normalize_action_text($name)," \t\n\r\0\x0B\"“”'.,!?");
    if($candidate==='')return true;
    $reserved=[
        'invoice','customer invoice','vendor invoice','supplier invoice','bill','payment','customer payment','vendor payment',
        'customer','vendor','supplier','journal','journal entry','entry','gl','general ledger','bank','banking','payroll',
        'report','p&l','profit and loss','profit & loss','balance sheet','cash flow','transaction','transactions',
        'reconciliation','account','ledger','register','tax','settings','receivables','payables',
    ];
    return in_array($candidate,$reserved,true);
}

/**
 * Resolve explicit application intents from most-specific to least-specific.
 * This is the central precedence table used before entity-name extraction.
 */
function tegh_resolve_explicit_action(string $text): ?array
{
    $q=tegh_normalize_action_text($text);
    $patterns=[
        'bill.create'=>[
            '/\b(?:create|prepare|enter|record|make)\s+(?:a\s+)?(?:vendor|supplier)\s+invoice\b/u',
            '/\b(?:create|prepare|enter|record)\s+(?:a\s+)?bill\b/u',
        ],
        'invoice.create'=>[
            '/\b(?:create|prepare|make|new)\s+(?:a|an)?\s*(?:customer|sales)?\s*invoice\b/u',
            '/\binvoice\s+(?:for|to)\b/u',
        ],
        'payment.customer'=>['/\b(?:record|enter|apply)\s+(?:a\s+)?customer\s+payment\b/u'],
        'payment.vendor'=>['/\b(?:record|enter|make)\s+(?:a\s+)?(?:vendor|supplier)\s+payment\b/u'],
        'customer.create'=>['/\b(?:create|add|new)\s+(?:a\s+)?customer\b/u'],
        'vendor.create'=>['/\b(?:create|add|new)\s+(?:a\s+)?(?:vendor|supplier)\b/u'],
        'journal.prepare'=>['/\b(?:post|prepare|make|create)\b.*\b(?:gl|general ledger|journal)\b/u','/\bjournal entr/u'],
        'nav.bank_import'=>['/\bimport\b.*\b(?:bank )?statement\b/u'],
        'nav.import_customer_invoices'=>['/\bimport\s+(?:customer|sales)\s+invoices?\b/u'],
        'nav.import_vendor_invoices'=>['/\bimport\s+(?:vendor|supplier|purchase)\s+(?:invoices?|bills?)\b/u'],
        'nav.import_products'=>['/\bimport\s+(?:products?(?:\s*(?:and|&)\s*services?)?|services?)\b/u'],
        'nav.import_customers'=>['/\bimport\s+customers?\b/u'],
        'nav.import_coa_opening_balances'=>['/\bimport\s+(?:chart\s+of\s+accounts|coa)\s+(?:and|&)\s+opening\s+balances?\b/u'],
        'nav.import_opening_balances'=>['/\bimport\s+opening\s+balances?\b/u'],
        'nav.import_chart_of_accounts'=>['/\bimport\s+(?:chart\s+of\s+accounts|coa)\b/u'],
        'nav.import_vendors'=>['/\bimport\s+(?:vendors?|suppliers?)\b/u'],
        'nav.data_import'=>['/\b(?:data\s+import|import\s+centre|import\s+center)\b/u'],
        'payroll.calculate'=>['/\b(?:run|calculate|prepare)\s+payroll(?:\s+calculation)?\b/u'],
    ];
    foreach($patterns as $actionId=>$regexes)foreach($regexes as $regex)if(preg_match($regex,$q))return ['actionId'=>$actionId,'normalized'=>$q];
    return null;
}

function tegh_parse_create_chain(string $text): ?array
{
    $q=trim($text);
    if(preg_match('/\bcreate\s+(?:a\s+)?customer(?:\s+(?:named|called))?\s+(.+?)\s+(?:and\s+then|then|and)\s+(?:create|prepare|make)\s+(?:a|an)?\s*(?:customer\s+)?invoice(?:\s+for\s+(?:them|that customer))?\b/iu',$q,$m)){
        $name=trim($m[1]," \t\n\r\0\x0B\"“”'.,!?");
        if($name!==''&&!tegh_reserved_entity_candidate($name))return ['firstAction'=>'customer.create','name'=>$name,'followUpAction'=>'invoice.create'];
    }
    if(preg_match('/\bcreate\s+(?:a\s+)?(?:vendor|supplier)(?:\s+(?:named|called))?\s+(.+?)\s+(?:and\s+then|then|and)\s+(?:enter|create|prepare|record)\s+(?:a\s+)?(?:\$?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)\s*)?(?:vendor\s+invoice|bill)\b/iu',$q,$m)){
        $name=trim($m[1]," \t\n\r\0\x0B\"“”'.,!?");$amount=isset($m[2])&&$m[2]!==''?(int)round((float)str_replace(',','',$m[2])*100):null;
        if($name!==''&&!tegh_reserved_entity_candidate($name))return ['firstAction'=>'vendor.create','name'=>$name,'followUpAction'=>'bill.create','amountCents'=>$amount];
    }
    return null;
}

function tegh_task_payload(array $task,string $key): array
{
    $value=json_decode((string)($task[$key]??''),true);return is_array($value)?$value:[];
}

function tegh_recent_created_party(array $user,array $company,string $kind): ?array
{
    $actionId=$kind==='vendor'?'vendor.create':'customer.create';
    $stmt=db()->prepare("SELECT collected_json FROM ai_agent_tasks WHERE company_id=? AND user_id=? AND action_id=? AND status='completed' AND updated_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 MINUTE) ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([(string)$company['id'],(string)$user['id'],$actionId]);$row=$stmt->fetch();if(!$row)return null;
    $collected=json_decode((string)$row['collected_json'],true);if(!is_array($collected))return null;
    $result=$collected['result'][$kind]??null;
    if(is_array($result)&&!empty($result['id'])&&!empty($result['name']))return ['id'=>(string)$result['id'],'name'=>(string)$result['name']];
    $request=$collected['request'][$kind]??null;
    if(is_array($request)&&!empty($request['name'])){
        $matches=tegh_party_lookup($company,$kind,(string)$request['name'],false);
        if(count($matches)===1)return ['id'=>(string)$matches[0]['id'],'name'=>(string)$matches[0]['name']];
    }
    return null;
}

function tegh_extract_invoice_party_query(string $text,string $kind): ?string
{
    $q=tegh_normalize_action_text($text);
    if($kind==='customer'){
        if(preg_match('/\binvoice\s+(?:for|to)\s+(.+?)(?:[.!?]|$)/u',$q,$m)){
            $candidate=trim($m[1]," \t\n\r\0\x0B\"“”'.,!?");
            if(!in_array($candidate,['them','him','her','that customer','the customer'],true)&&!tegh_reserved_entity_candidate($candidate))return $candidate;
        }
    }else{
        if(preg_match('/\b(?:vendor invoice|bill)\s+(?:for|from)\s+(.+?)(?:[.!?]|$)/u',$q,$m)){
            $candidate=trim($m[1]," \t\n\r\0\x0B\"“”'.,!?");
            if(!in_array($candidate,['them','that vendor','the vendor','that supplier','the supplier'],true)&&!tegh_reserved_entity_candidate($candidate))return $candidate;
        }
    }
    return null;
}

function tegh_invoice_detail_from_text(string $text): array
{
    $raw=trim($text);$amountCents=null;
    if(preg_match('/\$?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/',$raw,$m))$amountCents=(int)round((float)str_replace(',','',$m[1])*100);
    $description=trim(preg_replace('/\s*(?:for|at|amount(?:ing)? to)?\s*\$?\s*[0-9][0-9,]*(?:\.[0-9]{1,2})?\s*/i',' ',$raw,1)??$raw," \t\n\r\0\x0B.,!?");
    if(tegh_reserved_entity_candidate($description))$description='';
    return ['description'=>$description,'amountCents'=>$amountCents];
}

function tegh_needs_input_response(array $task,string $actionId,string $message,?string $navigation=null,array $workflowContext=[]): array
{
    $out=['recognized'=>true,'kind'=>'needs_input','actionId'=>$actionId,'task'=>$task,'message'=>$message];
    if($navigation!==null)$out['navigation']=$navigation;if($workflowContext)$out['workflowContext']=$workflowContext;return $out;
}

function tegh_action_get(string $actionId): array
{
    $registry=tegh_action_registry();
    if(!isset($registry[$actionId])) fail('That Ask Tegh action is not registered.',400,'unknown_action_id');
    return $registry[$actionId];
}

function tegh_action_assert_permission(array $company,array $action,?array $user=null,?string $mode=null): void
{
    $permission=(string)($action['required_permission']??'');
    if($permission!=='') require_company_permission($company,$permission);
    if(!empty($action['owner_only'])){
        $effectiveUser=$user??require_user();
        if(platform_role_for_user((string)$effectiveUser['id'])!=='platform_owner')fail('That Ask Tegh action is restricted to the platform owner.',403,'permission_forbidden');
    }
    $feature=(string)($action['required_feature_key']??'');if($feature!==''&&function_exists('tegh_require_feature'))tegh_require_feature($user??require_user(),$company,$feature);
    if($mode!==null&&!in_array($mode,(array)($action['supported_modes']??['guided','full']),true))fail('That Ask Tegh action is not available in the current bookkeeping mode.',409,'action_mode_unavailable');
}

function tegh_agent_schema_ready(): void
{
    if(!tegh_agent_schema_available()) {
        fail('Advanced Ask Tegh memory is not ready yet. Basic guidance remains available while the AI storage upgrade is pending.',503,'schema_upgrade_required');
    }
}

function tegh_json(mixed $value): string
{
    return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}

function tegh_agent_active_task(array $user,array $company): ?array
{
    tegh_agent_schema_ready();
    $stmt=db()->prepare("SELECT * FROM ai_agent_tasks WHERE company_id=? AND user_id=? AND status IN ('active','waiting_input','waiting_confirmation') ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([(string)$company['id'],(string)$user['id']]);$row=$stmt->fetch();return $row?:null;
}

function tegh_agent_cancel_active(array $user,array $company,string $reason='superseded'): void
{
    tegh_agent_schema_ready();$companyId=(string)$company['id'];$userId=(string)$user['id'];
    $rows=db()->prepare("SELECT id,pending_confirmation_id,conversation_id,plan_id FROM ai_agent_tasks WHERE company_id=? AND user_id=? AND status IN ('active','waiting_input','waiting_confirmation')");
    $rows->execute([$companyId,$userId]);$tasks=$rows->fetchAll();
    db()->prepare("UPDATE ai_agent_tasks SET status='cancelled',workflow_state=? WHERE company_id=? AND user_id=? AND status IN ('active','waiting_input','waiting_confirmation')")
        ->execute([mb_substr($reason,0,60),$companyId,$userId]);
    foreach($tasks as $task){
        $cid=trim((string)($task['pending_confirmation_id']??''));
        if($cid!=='')db()->prepare("UPDATE ai_agent_action_authorizations SET status='cancelled',result_status=IF(result_status='none','failed',result_status) WHERE id=? AND company_id=? AND user_id=? AND status='pending'")->execute([$cid,$companyId,$userId]);
        $planId=trim((string)($task['plan_id']??''));if($planId!==''&&schema_table_exists('ai_agent_plans'))db()->prepare("UPDATE ai_agent_plans SET status='cancelled' WHERE id=? AND company_id=? AND user_id=? AND status IN ('proposed','waiting_input','waiting_confirmation')")->execute([$planId,$companyId,$userId]);
    }
}

function tegh_agent_invalidate_other_companies(array $user,array $company): void
{
    // Company switch is a hard context and confirmation boundary.
    tegh_agent_schema_ready();$userId=(string)$user['id'];$companyId=(string)$company['id'];
    db()->prepare("UPDATE ai_agent_tasks SET status='cancelled',workflow_state='company_changed' WHERE user_id=? AND company_id<>? AND status IN ('active','waiting_input','waiting_confirmation')")->execute([$userId,$companyId]);
    db()->prepare("UPDATE ai_agent_action_authorizations SET status='cancelled',result_status=IF(result_status='none','failed',result_status) WHERE user_id=? AND company_id<>? AND status='pending' AND action_type LIKE 'app:%'")->execute([$userId,$companyId]);
    db()->prepare("UPDATE ai_agent_result_sets SET expires_at=UTC_TIMESTAMP() WHERE user_id=? AND company_id<>? AND expires_at>UTC_TIMESTAMP()")->execute([$userId,$companyId]);
    if(schema_table_exists('ai_agent_conversations'))db()->prepare("UPDATE ai_agent_conversations SET status='suspended' WHERE user_id=? AND company_id<>? AND status='active'")->execute([$userId,$companyId]);
    if(schema_table_exists('ai_agent_plans'))db()->prepare("UPDATE ai_agent_plans SET status='cancelled' WHERE user_id=? AND company_id<>? AND status IN ('proposed','waiting_input','waiting_confirmation','executing')")->execute([$userId,$companyId]);
}

function tegh_agent_task(array $user,array $company,string $actionId,string $intent,string $state='active',array $collected=[],array $missing=[],?string $resultSetId=null,?string $confirmationId=null,bool $replaceActive=true): array
{
    tegh_agent_schema_ready();$companyId=(string)$company['id'];$userId=(string)$user['id'];$action=tegh_action_get($actionId);tegh_action_assert_permission($company,$action);
    if($replaceActive)tegh_agent_cancel_active($user,$company,'superseded');
    $conversationId=null;$planId=null;
    if(function_exists('tegh_human_conversation')&&schema_table_exists('ai_agent_conversations')){
        $conv=tegh_human_conversation($user,$company,'','guided');$conversationId=(string)$conv['id'];
        if(schema_table_exists('ai_agent_plans')){$ps=db()->prepare("SELECT id FROM ai_agent_plans WHERE company_id=? AND user_id=? AND conversation_id=? AND status IN ('proposed','waiting_input','waiting_confirmation') ORDER BY updated_at DESC LIMIT 1");$ps->execute([(string)$company['id'],(string)$user['id'],$conversationId]);$v=$ps->fetchColumn();if($v!==false)$planId=(string)$v;}
    }
    $id=new_id('aitask');$status=$confirmationId?'waiting_confirmation':($missing?'waiting_input':'active');
    if(schema_column_exists('ai_agent_tasks','conversation_id')){
        db()->prepare('INSERT INTO ai_agent_tasks (id,company_id,user_id,action_id,intent,module,workflow_state,collected_json,missing_json,result_set_id,pending_confirmation_id,conversation_id,plan_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,(string)$company['id'],(string)$user['id'],$actionId,mb_substr($intent,0,500),(string)$action['module'],$state,tegh_json($collected),tegh_json($missing),$resultSetId,$confirmationId,$conversationId,$planId,$status]);
        if($conversationId!==null)db()->prepare('UPDATE ai_agent_conversations SET active_task_id=?,last_intent=?,revision=revision+1 WHERE id=? AND company_id=? AND user_id=?')->execute([$id,mb_substr($intent,0,500),$conversationId,(string)$company['id'],(string)$user['id']]);
        if($planId!==null)db()->prepare("UPDATE ai_agent_plans SET task_id=?,status=? WHERE id=? AND company_id=? AND user_id=?")->execute([$id,$missing?'waiting_input':($confirmationId?'waiting_confirmation':'proposed'),$planId,(string)$company['id'],(string)$user['id']]);
    } else {
        db()->prepare('INSERT INTO ai_agent_tasks (id,company_id,user_id,action_id,intent,module,workflow_state,collected_json,missing_json,result_set_id,pending_confirmation_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,(string)$company['id'],(string)$user['id'],$actionId,mb_substr($intent,0,500),(string)$action['module'],$state,tegh_json($collected),tegh_json($missing),$resultSetId,$confirmationId,$status]);
    }
    try{audit_event($user,$companyId,'ai_agent.task_created','ai_agent_task',$id,['actionId'=>$actionId,'model'=>'none','confidence'=>0,'interpretationPath'=>'task_state','confirmationId'=>$confirmationId,'userId'=>$userId,'companyId'=>$companyId,'registryVersion'=>defined('TEGH_ROUTER_VERSION')?TEGH_ROUTER_VERSION:'registry','executionClass'=>function_exists('tegh_action_execution_class')?tegh_action_execution_class($action):'unknown']);}catch(Throwable $error){error_log('Tegh AI task audit skipped '.$error::class);}
    return ['id'=>$id,'actionId'=>$actionId,'status'=>$status,'workflowState'=>$state,'resultSetId'=>$resultSetId,'confirmationId'=>$confirmationId,'conversationId'=>$conversationId,'planId'=>$planId];
}

function tegh_agent_task_update(string $taskId,array $user,array $company,array $fields): void
{
    $allowed=['workflow_state','collected_json','missing_json','result_set_id','pending_confirmation_id','conversation_id','plan_id','status'];$sets=[];$params=[];
    foreach($fields as $k=>$v){if(!in_array($k,$allowed,true))continue;$sets[]="$k=?";$params[]=$v;}
    if(!$sets)return;$params[]=$taskId;$params[]=(string)$company['id'];$params[]=(string)$user['id'];
    db()->prepare('UPDATE ai_agent_tasks SET '.implode(',',$sets).' WHERE id=? AND company_id=? AND user_id=?')->execute($params);
}

function tegh_confirmation_window_minutes(string $actionId,array $payload,?int $requested=null): int
{
    $windows=config('ai.confirmation_windows_minutes');$configured=is_array($windows)&&array_key_exists($actionId,$windows)?(int)$windows[$actionId]:null;$minutes=$configured??$requested??15;$minutes=max(1,min(60,$minutes));
    if($actionId==='bank.transactions.delete')$minutes=min($minutes,5);
    if($actionId==='journal.post'){$journal=is_array($payload['journal']??null)?$payload['journal']:$payload;$total=(int)($journal['debitsCents']??0);$threshold=max(100000,min(1000000000,(int)(config('ai.journal_step_up_threshold_cents')??1000000)));if($total>$threshold)$minutes=min($minutes,5);}
    return $minutes;
}

function tegh_agent_authorization(array $user,array $company,string $actionId,array $payload,?int $minutes=null,string $conversationId='',string $planId='',string $taskId=''): string
{
    $action=tegh_action_get($actionId);tegh_action_assert_permission($company,$action);$companyId=(string)$company['id'];$userId=(string)$user['id'];$minutes=tegh_confirmation_window_minutes($actionId,$payload,$minutes);
    if(tegh_action_execution_class($action)!=='authorized')fail('That action is not an authorized commit class.',409,'authorization_class_invalid');
    if(empty($action['supports_ai_execution']))fail('That protected action must be completed in its existing Tegh workflow.',409,'authorization_adapter_unavailable');
    $canonical=tegh_json($payload);$payloadHash=hash('sha256',$canonical);$id=new_id('aiaction');
    if($conversationId===''&&function_exists('tegh_human_conversation')&&schema_table_exists('ai_agent_conversations')){$conv=tegh_human_conversation($user,$company,'','guided');$conversationId=(string)$conv['id'];}
    $clientOperationKey=trim((string)($payload['_teghOperationKey']??''));
    $operationKey=$clientOperationKey!==''
        ? hash('sha256','user-operation|'.$companyId.'|'.$userId.'|'.$actionId.'|'.$clientOperationKey)
        : (function_exists('tegh_human_operation_key')?tegh_human_operation_key($user,$company,$actionId,$payload,$conversationId,$planId,''):hash('sha256',$companyId.'|'.$userId.'|'.$actionId.'|'.$canonical));
    if(schema_column_exists('ai_agent_action_authorizations','operation_key')){
        $existing=db()->prepare("SELECT id,status,result_status,result_json,payload_hash,payload_json FROM ai_agent_action_authorizations WHERE company_id=? AND user_id=? AND operation_key=? AND action_type=? AND status IN ('pending','authorized','completed') ORDER BY created_at DESC LIMIT 1");
        $existing->execute([$companyId,$userId,$operationKey,'app:'.$actionId]);$prior=$existing->fetch();if($prior){
            if(!hash_equals((string)$prior['payload_hash'],$payloadHash)){
                $previous=json_decode((string)$prior['payload_json'],true);$samePreparedWork=is_array($previous)
                    && (string)($previous['_teghOperationKey']??'')===$clientOperationKey
                    && (string)($previous['companyId']??'')===$companyId
                    && (string)($previous['userId']??'')===$userId
                    && (string)($previous['sourceRevisionHash']??'')===(string)($payload['sourceRevisionHash']??'')
                    && tegh_json($previous['decisions']??[])===tegh_json($payload['decisions']??[]);
                if(!$samePreparedWork)fail('That operation key was already used for different prepared work.',409,'operation_key_conflict');
            }
            return (string)$prior['id'];
        }
    }
    db()->prepare("UPDATE ai_agent_action_authorizations SET status='expired' WHERE company_id=? AND user_id=? AND status='pending' AND expires_at<UTC_TIMESTAMP()")->execute([$companyId,$userId]);
    // Latest pending confirmation wins. Authorized/completed operations are not cancelled by a new request.
    db()->prepare("UPDATE ai_agent_action_authorizations SET status='cancelled',result_status=IF(result_status='none','failed',result_status) WHERE company_id=? AND user_id=? AND status='pending' AND action_type LIKE 'app:%'")->execute([$companyId,$userId]);
    if(schema_column_exists('ai_agent_action_authorizations','operation_key')){
        db()->prepare("INSERT INTO ai_agent_action_authorizations (id,company_id,user_id,action_type,payload_json,payload_hash,status,expires_at,conversation_id,plan_id,task_id,operation_key,result_status) VALUES (?,?,?,?,?,?,'pending',DATE_ADD(UTC_TIMESTAMP(),INTERVAL $minutes MINUTE),?,?,?,?, 'none')")
            ->execute([$id,$companyId,$userId,'app:'.$actionId,$canonical,$payloadHash,$conversationId?:null,$planId?:null,$taskId?:null,$operationKey]);
    } else {
        db()->prepare("INSERT INTO ai_agent_action_authorizations (id,company_id,user_id,action_type,payload_json,payload_hash,status,expires_at) VALUES (?,?,?,?,?,?,'pending',DATE_ADD(UTC_TIMESTAMP(),INTERVAL $minutes MINUTE))")
            ->execute([$id,$companyId,$userId,'app:'.$actionId,$canonical,$payloadHash]);
    }
    audit_event($user,$companyId,'ai_agent.authorization_prepared','ai_agent_action',$id,['actionId'=>$actionId,'payloadHash'=>$payloadHash,'operationKeyHash'=>hash('sha256',$operationKey),'conversationIdHash'=>$conversationId!==''?hash('sha256',$conversationId):null,'planIdHash'=>$planId!==''?hash('sha256',$planId):null]);
    return $id;
}

function tegh_agent_result_get(string $id,array $user,array $company): array
{
    tegh_agent_schema_ready();
    $stmt=db()->prepare('SELECT * FROM ai_agent_result_sets WHERE id=? AND company_id=? AND user_id=? AND expires_at>UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([$id,(string)$company['id'],(string)$user['id']]);$row=$stmt->fetch();
    if(!$row) fail('That Ask Tegh result list is no longer available. Run the query again.',409,'result_set_stale');
    foreach(['query_json'=>'query','record_ids_json'=>'recordIds','selected_ids_json'=>'selectedIds','status_snapshot_json'=>'statusSnapshot'] as $src=>$dst){$row[$dst]=json_decode((string)$row[$src],true)?:[];}
    return $row;
}

/** Build the exact accounting identity protected between preview and commit. */
function tegh_agent_snapshot_identity(array $row): array
{
    if(is_array($row['_teghIdentity']??null)){
        $identity=(array)$row['_teghIdentity'];unset($identity['recordHash']);ksort($identity);
    }else{
        // Keep this field order stable: existing schema-34 result sets already
        // carry hashes produced from this canonical bank-transaction identity.
        $identity=['status'=>(string)($row['status']??''),'amountCents'=>(int)($row['amount_cents']??0),'accountId'=>(string)($row['decided_account_id']??''),'taxCode'=>(string)($row['tax_code']??''),'sourceHash'=>(string)($row['source_hash']??''),'updatedAt'=>(string)($row['updated_at']??'')];
    }
    $identity['recordHash']=hash('sha256',tegh_json($identity));return $identity;
}

/** @param array<int,array<string,mixed>> $rows */
function tegh_agent_status_snapshot(array $rows): array
{
    $snapshot=[];foreach($rows as $row){$id=(string)($row['id']??'');if($id!=='')$snapshot[$id]=tegh_agent_snapshot_identity($row);}return $snapshot;
}

function tegh_agent_result_save(array $user,array $company,array $query,array $rows,?string $existingId=null,array $selected=[]): array
{
    $conversationId='';if(function_exists('tegh_human_conversation')&&schema_table_exists('ai_agent_conversations')){try{$conv=tegh_human_conversation($user,$company,'','guided');$conversationId=(string)$conv['id'];}catch(Throwable){}}
    $ids=array_map(static fn(array $r):string=>(string)$r['id'],$rows);$snapshot=tegh_agent_status_snapshot($rows);
    if($existingId){
        $current=tegh_agent_result_get($existingId,$user,$company);
        $selected=array_values(array_intersect($selected?:($current['selectedIds']??[]),$ids));
        $scopeChanged=tegh_json($current['query']??[])!==tegh_json($query) || tegh_json($current['recordIds']??[])!==tegh_json($ids) || tegh_json($current['selectedIds']??[])!==tegh_json($selected);
        $confirmationInvalidated=false;
        if($scopeChanged){
            // A prepared approval is bound to the exact visible/selected scope shown in its preview.
            $stmt=db()->prepare("SELECT pending_confirmation_id FROM ai_agent_tasks WHERE company_id=? AND user_id=? AND result_set_id=? AND status='waiting_confirmation'");
            $stmt->execute([(string)$company['id'],(string)$user['id'],$existingId]);$pendingTasks=$stmt->fetchAll();$confirmationInvalidated=count($pendingTasks)>0;
            foreach($pendingTasks as $task){$cid=trim((string)($task['pending_confirmation_id']??''));if($cid!=='')db()->prepare("UPDATE ai_agent_action_authorizations SET status='cancelled' WHERE id=? AND company_id=? AND user_id=? AND status='pending'")->execute([$cid,(string)$company['id'],(string)$user['id']]);}
            db()->prepare("UPDATE ai_agent_tasks SET status='cancelled',workflow_state='result_scope_changed',pending_confirmation_id=NULL WHERE company_id=? AND user_id=? AND result_set_id=? AND status='waiting_confirmation'")
                ->execute([(string)$company['id'],(string)$user['id'],$existingId]);
        }
        if($conversationId!==''&&schema_column_exists('ai_agent_result_sets','conversation_id'))db()->prepare('UPDATE ai_agent_result_sets SET query_json=?,record_ids_json=?,selected_ids_json=?,status_snapshot_json=?,conversation_id=?,expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 MINUTE) WHERE id=? AND company_id=? AND user_id=?')->execute([tegh_json($query),tegh_json($ids),tegh_json($selected),tegh_json($snapshot),$conversationId,$existingId,(string)$company['id'],(string)$user['id']]);
        else db()->prepare('UPDATE ai_agent_result_sets SET query_json=?,record_ids_json=?,selected_ids_json=?,status_snapshot_json=?,expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 MINUTE) WHERE id=? AND company_id=? AND user_id=?')->execute([tegh_json($query),tegh_json($ids),tegh_json($selected),tegh_json($snapshot),$existingId,(string)$company['id'],(string)$user['id']]);
        if($conversationId!==''&&function_exists('tegh_human_conversation_touch'))tegh_human_conversation_touch($user,$company,$conversationId,'result_set_updated',['resultSets'=>[$existingId]]);
        return ['id'=>$existingId,'recordIds'=>$ids,'selectedIds'=>$selected,'query'=>$query,'scopeChanged'=>$scopeChanged,'confirmationInvalidated'=>$confirmationInvalidated];
    }
    $id=new_id('airesult');
    if($conversationId!==''&&schema_column_exists('ai_agent_result_sets','conversation_id'))db()->prepare("INSERT INTO ai_agent_result_sets (id,company_id,user_id,conversation_id,kind,query_json,record_ids_json,selected_ids_json,status_snapshot_json,expires_at) VALUES (?,?,?,?,'bank_transactions',?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 MINUTE))")->execute([$id,(string)$company['id'],(string)$user['id'],$conversationId,tegh_json($query),tegh_json($ids),tegh_json($selected),tegh_json($snapshot)]);
    else db()->prepare("INSERT INTO ai_agent_result_sets (id,company_id,user_id,kind,query_json,record_ids_json,selected_ids_json,status_snapshot_json,expires_at) VALUES (?,?,?,'bank_transactions',?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 MINUTE))")->execute([$id,(string)$company['id'],(string)$user['id'],tegh_json($query),tegh_json($ids),tegh_json($selected),tegh_json($snapshot)]);
    if($conversationId!==''&&function_exists('tegh_human_conversation_touch'))tegh_human_conversation_touch($user,$company,$conversationId,'result_set_created',['resultSets'=>[$id]]);
    return ['id'=>$id,'recordIds'=>$ids,'selectedIds'=>$selected,'query'=>$query];
}

function tegh_agent_result_assert_unchanged(array $result,array $rows,array $targetIds): void
{
    $snapshot=(array)($result['statusSnapshot']??[]);$by=[];foreach($rows as $r)$by[(string)$r['id']]=$r;
    foreach(array_values(array_unique(array_map('strval',$targetIds))) as $id){
        $prior=$snapshot[$id]??null;$row=$by[$id]??null;if(!$row)fail('A transaction in the approved result set is no longer available. Refresh the list and review it again.',409,'result_set_changed');
        if(is_string($prior)){if((string)$row['status']!==$prior)fail('A transaction changed after the Ask Tegh preview. Refresh the result set before continuing.',409,'result_set_changed');continue;}
        if(!is_array($prior))fail('The Ask Tegh result snapshot is incomplete. Refresh the result set before continuing.',409,'result_set_changed');
        $current=tegh_agent_snapshot_identity($row);
        // Build 4400 fresh target reads include the full accounting identity used in
        // the preview. Any source/status/amount/account/tax/version change invalidates
        // the prepared confirmation and requires a fresh impact preview.
        if(isset($prior['recordHash'])){if(!hash_equals((string)$prior['recordHash'],(string)$current['recordHash']))fail('An accounting record changed after the Ask Tegh preview. Refresh and review the accounting impact again.',409,'result_set_changed');continue;}
        foreach(['status','amountCents','accountId','taxCode','sourceHash','updatedAt'] as $key)if(($prior[$key]??null)!==($current[$key]??null))fail('A transaction changed after the Ask Tegh preview. Refresh the result set and review the accounting impact again.',409,'result_set_changed');
    }
}

function tegh_month_range(int $year,int $month): array
{
    $start=sprintf('%04d-%02d-01',$year,$month);$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');return [$start,$end];
}

/** Parse only fields Tegh actually stores. This parser creates structured criteria; SQL remains parameterized. */
function tegh_parse_bank_filters(string $text,array $base=[]): array
{
    $q=trim($text);$l=tegh_normalize_action_text($q);$f=$base;
    $year=(int)date('Y');
    $months=['january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12,'jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'sept'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    if(preg_match('/\b(20\d{2})\b/',$l,$m))$year=(int)$m[1];
    if(str_contains($l,'last quarter')||str_contains($l,'previous quarter')){$now=new DateTimeImmutable('today');$m=(int)$now->format('n');$qStartMonth=(int)(floor(($m-1)/3)*3+1);$thisQuarter=new DateTimeImmutable($now->format('Y').'-'.str_pad((string)$qStartMonth,2,'0',STR_PAD_LEFT).'-01');$start=$thisQuarter->modify('-3 months');$f['dateFrom']=$start->format('Y-m-d');$f['dateTo']=$thisQuarter->modify('-1 day')->format('Y-m-d');}
    elseif(str_contains($l,'this quarter')){$now=new DateTimeImmutable('today');$m=(int)$now->format('n');$qStartMonth=(int)(floor(($m-1)/3)*3+1);$start=new DateTimeImmutable($now->format('Y').'-'.str_pad((string)$qStartMonth,2,'0',STR_PAD_LEFT).'-01');$f['dateFrom']=$start->format('Y-m-d');$f['dateTo']=$start->modify('+3 months -1 day')->format('Y-m-d');}
    elseif(str_contains($l,'last month')){$d=(new DateTimeImmutable('first day of last month'));$f['dateFrom']=$d->format('Y-m-d');$f['dateTo']=$d->modify('last day of this month')->format('Y-m-d');}
    elseif(str_contains($l,'this month')){$d=new DateTimeImmutable('first day of this month');$f['dateFrom']=$d->format('Y-m-d');$f['dateTo']=$d->modify('last day of this month')->format('Y-m-d');}
    else foreach($months as $name=>$month) if(preg_match('/\b'.preg_quote($name,'/').'\b/',$l)){[$f['dateFrom'],$f['dateTo']]=tegh_month_range($year,$month);break;}

    if(preg_match('/\bdebits?\b/',$l))$f['direction']='debit';elseif(preg_match('/\bcredits?\b/',$l))$f['direction']='credit';elseif(preg_match('/\ball\b/',$l)&&str_contains($l,'transaction'))unset($f['direction']);
    if(preg_match('/\b(?:unposted|pending|not\s+posted)\b/',$l))$f['status']='pending';elseif(preg_match('/\bposted\b/',$l))$f['status']='posted';elseif(preg_match('/\bexcluded\b/',$l))$f['status']='excluded';
    if(preg_match('/possible duplicates?|duplicates?/',$l))$f['status']='duplicate';
    if(preg_match('/\buncategorized\b/',$l))$f['uncategorized']=true;
    if(preg_match('/\breconciled\b/',$l) && !str_contains($l,'unreconciled'))$f['reconciled']=true;
    if(preg_match('/\bunreconciled\b/',$l))$f['reconciled']=false;
    if(str_contains($l,'latest statement')||str_contains($l,'latest imported'))$f['latestBatch']=true;

    if(preg_match('/\bbetween\s*\$?([0-9,]+(?:\.\d{1,2})?)\s*(?:and|to|-)\s*\$?([0-9,]+(?:\.\d{1,2})?)/i',$q,$m)){$f['amountMinCents']=(int)round((float)str_replace(',','',$m[1])*100);$f['amountMaxCents']=(int)round((float)str_replace(',','',$m[2])*100);}
    else {
        if(preg_match('/\b(?:over|greater than|above)\s*\$?([0-9,]+(?:\.\d{1,2})?)/i',$q,$m))$f['amountMinCents']=(int)round((float)str_replace(',','',$m[1])*100)+1;
        if(preg_match('/\b(?:under|less than|below)\s*\$?([0-9,]+(?:\.\d{1,2})?)/i',$q,$m))$f['amountMaxCents']=(int)round((float)str_replace(',','',$m[1])*100)-1;
        if(preg_match('/\b(?:equals?|exactly)\s*\$?([0-9,]+(?:\.\d{1,2})?)/i',$q,$m))$f['amountEqualsCents']=(int)round((float)str_replace(',','',$m[1])*100);
    }

    $desc=null;
    if(preg_match('/(?:description\s+)?(?:containing|contains)\s+["“]?(.+?)["”]?(?=\s+(?:from|in|that|which|under|over|between|not|and\s+apply|and\s+then|transactions?\b)|[.!?]?$)/i',$q,$m))$desc=trim($m[1]," \t\n\r\0\x0B\"“”");
    elseif(preg_match('/description\s+(?:equals|is)\s+["“]?(.+?)["”]?(?:[.!?]|$)/i',$q,$m)){$f['descriptionEquals']=trim($m[1]," \"“”");}
    elseif(preg_match('/description\s+(?:starts with|beginning with)\s+["“]?(.+?)["”]?(?:[.!?]|$)/i',$q,$m)){$f['descriptionStarts']=trim($m[1]," \"“”");}
    else {
        // Handles required phrases such as "August Tim Hortons transactions" and "unposted SERVICE CHARGE transactions".
        $clean=preg_replace('/\b(show|find|list|post|categorize|categorise|me|all|only|the|august|january|february|march|april|may|june|july|september|october|november|december|20\d{2}|unposted|pending|posted|debits?|credits?|under|over|below|above|from|last month|this month)\b/i',' ',$q);
        $clean=preg_replace('/\$?[0-9,]+(?:\.\d{1,2})?/',' ',$clean);
        if(preg_match('/(.+?)\s+transactions?\b/i',(string)$clean,$m)){$candidate=trim(preg_replace('/\s+/',' ',$m[1]));if($candidate!=='' && !preg_match('/^(possible duplicates?|uncategorized)$/i',$candidate))$desc=$candidate;}
    }
    if($desc===null && preg_match('/\btransactions?\s+from\s+["“]?(.+?)["”]?(?:[.!?]|$)/i',$q,$m)){
        $candidate=trim($m[1]," \"“”");
        if(!preg_match('/^(?:the\s+)?latest(?:\s+imported)?(?:\s+bank)?\s+statement$/i',$candidate) && !preg_match('/(?:bank\s+)?account$/i',$candidate))$desc=$candidate;
    }
    if($desc===null && preg_match('/\bshow(?:\s+me)?\s+(?:all\s+)?(.+?)\s+(?:debits?|credits?)\b/i',$q,$m)){
        $candidate=preg_replace('/\b(?:january|february|march|april|may|june|july|august|september|october|november|december|20\d{2}|unposted|pending|posted|not posted)\b/i',' ',trim($m[1]));
        $candidate=trim(preg_replace('/\s+/',' ',(string)$candidate));if($candidate!==''&&!preg_match('/^(?:all|transactions?)$/i',$candidate))$desc=$candidate;
    }
    if($desc===null && preg_match('/\b(?:debits?|credits?)\s+from\s+["“]?(.+?)["”]?(?=\s+to\s+|\s+and\s+then|[.!?]|$)/i',$q,$m)){
        $candidate=trim($m[1]," \"“”");
        if(!preg_match('/^(?:last|this)\s+month$/i',$candidate))$desc=$candidate;
    }
    if($desc!==null && mb_strlen($desc)<=200)$f['descriptionContains']=$desc;
    if(preg_match('/\b(?:bank account|account)\s+(?:named\s+)?["“]?([A-Za-z0-9 &._-]{2,80})["”]?/i',$q,$m) && !str_contains(mb_strtolower($m[1]),'ledger'))$f['bankAccountName']=trim($m[1]);
    elseif(preg_match('/\bfrom\s+(?:the\s+)?["“]?(.+?)["”]?\s+(?:bank\s+)?account\b/i',$q,$m))$f['bankAccountName']=trim($m[1]," \"“”");
    if(preg_match('/categor(?:ized|ised) to\s+["“]?(.+?)["”]?(?:[.!?]|$)/i',$q,$m))$f['categoryName']=trim($m[1]," \"“”");
    if(preg_match('/(?:reference|ref)\s+(?:containing|contains|equals|is)\s+["“]?(.+?)["”]?(?:[.!?]|$)/i',$q,$m)){$f['referenceContains']=trim($m[1]," \"“”");if(!preg_match('/\bdescription\b/i',$q))unset($f['descriptionContains']);}
    if(preg_match('/\b(?:tax|tax treatment)\s+(?:is|equals)?\s*(GST_HST|HST13|GST\/HST|PST|NO_TAX|no tax)\b/i',$q,$m)){$tax=strtoupper(str_replace(['/',' '],['_','_'],trim($m[1])));$f['taxCode']=$tax==='NO_TAX'?$tax:($tax==='GST_HST'?$tax:(str_contains($tax,'PST')?'PST':$tax));}
    return $f;
}

function tegh_bank_query_rows(array $company,array $filters,int $limit=300): array
{
    require_company_permission($company,'banking.view');$companyId=(string)$company['id'];$where=['bt.company_id=?'];$params=[$companyId];
    if(isset($filters['descriptionContains'])){$where[]='bt.description LIKE ?';$params[]='%'.str_replace(['%','_'],['\\%','\\_'],(string)$filters['descriptionContains']).'%';}
    if(isset($filters['descriptionEquals'])){$where[]='bt.description=?';$params[]=(string)$filters['descriptionEquals'];}
    if(isset($filters['descriptionStarts'])){$where[]='bt.description LIKE ?';$params[]=str_replace(['%','_'],['\\%','\\_'],(string)$filters['descriptionStarts']).'%';}
    if(isset($filters['merchant'])){$where[]='bt.normalized_merchant LIKE ?';$params[]='%'.(string)$filters['merchant'].'%';}
    if(($filters['direction']??'')==='debit')$where[]='bt.amount_cents<0';elseif(($filters['direction']??'')==='credit')$where[]='bt.amount_cents>0';
    if(isset($filters['amountEqualsCents'])){$where[]='ABS(bt.amount_cents)=?';$params[]=(int)$filters['amountEqualsCents'];}
    if(isset($filters['amountMinCents'])){$where[]='ABS(bt.amount_cents)>=?';$params[]=(int)$filters['amountMinCents'];}
    if(isset($filters['amountMaxCents'])){$where[]='ABS(bt.amount_cents)<=?';$params[]=(int)$filters['amountMaxCents'];}
    if(isset($filters['dateFrom'])){$where[]='bt.transaction_date>=?';$params[]=(string)$filters['dateFrom'];}
    if(isset($filters['dateTo'])){$where[]='bt.transaction_date<=?';$params[]=(string)$filters['dateTo'];}
    if(isset($filters['status'])){$where[]='bt.status=?';$params[]=(string)$filters['status'];}
    if(!empty($filters['uncategorized']))$where[]='bt.decided_account_id IS NULL';
    if(isset($filters['bankAccountName'])){$where[]='ba.name LIKE ?';$params[]='%'.(string)$filters['bankAccountName'].'%';}
    if(isset($filters['categoryName'])){$where[]='ac.name LIKE ?';$params[]='%'.(string)$filters['categoryName'].'%';}
    if(isset($filters['referenceContains'])){$where[]='bt.reference LIKE ?';$params[]='%'.str_replace(['%','_'],['\\%','\\_'],(string)$filters['referenceContains']).'%';}
    if(isset($filters['taxCode'])){$where[]='bt.tax_code=?';$params[]=(string)$filters['taxCode'];}
    if(!empty($filters['latestBatch']))$where[]='bt.import_batch_id=(SELECT ib.id FROM import_batches ib WHERE ib.company_id=bt.company_id ORDER BY ib.created_at DESC LIMIT 1)';
    if(array_key_exists('reconciled',$filters)){
        $exists="EXISTS(SELECT 1 FROM reconciliation_items ri JOIN reconciliations r ON r.id=ri.reconciliation_id WHERE ri.bank_transaction_id=bt.id AND r.company_id=bt.company_id AND r.status='complete')";
        $where[]=($filters['reconciled']?$exists:'NOT '.$exists);
    }
    $limit=max(1,min(500,$limit));
    $sql="SELECT bt.id,bt.transaction_date,bt.description,bt.reference,bt.normalized_merchant,bt.amount_cents,bt.currency,bt.status,bt.tax_code,bt.bank_account_id,bt.import_batch_id,bt.decided_account_id,bt.source_hash,bt.updated_at,ba.name bank_account_name,ac.code account_code,ac.name account_name,CASE WHEN EXISTS(SELECT 1 FROM reconciliation_items ri JOIN reconciliations r ON r.id=ri.reconciliation_id WHERE ri.bank_transaction_id=bt.id AND r.company_id=bt.company_id AND r.status='complete') THEN 1 ELSE 0 END reconciled FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id LEFT JOIN accounts ac ON ac.id=bt.decided_account_id WHERE ".implode(' AND ',$where)." ORDER BY bt.transaction_date DESC,bt.created_at DESC LIMIT $limit";
    $stmt=db()->prepare($sql);$stmt->execute($params);return $stmt->fetchAll();
}

function tegh_bank_summary(array $rows): array
{
    $debits=0;$credits=0;foreach($rows as $r){$a=(int)$r['amount_cents'];if($a<0)$debits+=abs($a);else$credits+=$a;}
    return ['count'=>count($rows),'totalDebitsCents'=>$debits,'totalCreditsCents'=>$credits];
}

function tegh_resolve_account(array $company,string $needle,?string $direction=null): array
{
    $needle=trim($needle);if($needle==='')return ['status'=>'missing','candidates'=>[]];$companyId=(string)$company['id'];
    $stmt=db()->prepare("SELECT id,code,name,account_type,is_control FROM accounts WHERE company_id=? AND active=1 AND (LOWER(name)=LOWER(?) OR code=?) ORDER BY is_control,name LIMIT 10");$stmt->execute([$companyId,$needle,$needle]);$exact=$stmt->fetchAll();
    $valid=static function(array $r)use($direction):bool{if((bool)$r['is_control'])return false;if($direction==='debit')return in_array((string)$r['account_type'],['expense','asset','equity','liability'],true);if($direction==='credit')return in_array((string)$r['account_type'],['income','asset','equity','liability'],true);return true;};
    $exact=array_values(array_filter($exact,$valid));if(count($exact)===1)return ['status'=>'resolved','account'=>$exact[0],'candidates'=>$exact];if(count($exact)>1)return ['status'=>'ambiguous','candidates'=>$exact];
    $stmt=db()->prepare("SELECT id,code,name,account_type,is_control FROM accounts WHERE company_id=? AND active=1 AND (name LIKE ? OR code LIKE ?) ORDER BY is_control,name LIMIT 8");$stmt->execute([$companyId,'%'.$needle.'%','%'.$needle.'%']);$rows=array_values(array_filter($stmt->fetchAll(),$valid));
    if(count($rows)===1)return ['status'=>'resolved','account'=>$rows[0],'candidates'=>$rows];return ['status'=>$rows?'ambiguous':'missing','candidates'=>$rows];
}

function tegh_parse_report(string $q): ?array
{
    $l=tegh_normalize_action_text($q);$id=null;
    if(preg_match('/\b(p\s*&\s*l|p\s+and\s+l|profit\s*(?:&|and)?\s*loss|income statement)\b/i',$q))$id='report.profit_loss';
    elseif(str_contains($l,'balance sheet'))$id='report.balance_sheet';
    elseif(str_contains($l,'cash flow'))$id='report.cash_flow';
    elseif(str_contains($l,'trial balance'))$id='report.trial_balance';
    elseif(preg_match('/\b(ar|accounts receivable)\s+aging\b|outstanding\s+(?:customer|receivable)\s+balances?|money\s+customers?\s+owe|customers?\s+owe\s+(?:me|us)|receivables?\s+outstanding/i',$q))$id='report.ar_aging';
    elseif(preg_match('/\b(ap|accounts payable)\s+aging\b|outstanding\s+(?:vendor|supplier|payable)\s+balances?|money\s+(?:i|we)\s+owe\s+(?:vendors?|suppliers?)|payables?\s+outstanding/i',$q))$id='report.ap_aging';
    elseif(str_contains($l,'account ledger'))$id='report.account_ledger';
    if(!$id)return null;
    $filters=tegh_parse_bank_filters($q,[]);$period=[];
    if(isset($filters['dateFrom'])){$period['from']=$filters['dateFrom'];$period['to']=$filters['dateTo'];}
    if($id==='report.balance_sheet'||$id==='report.ar_aging'||$id==='report.ap_aging')$period=['asOf'=>$period['to']??date('Y-m-d')];
    if(preg_match('/as (?:of|at)\s+([A-Za-z]+)\s+(\d{1,2})(?:,?\s*(20\d{2}))?/i',$q,$m)){$dt=DateTimeImmutable::createFromFormat('!F j Y',$m[1].' '.$m[2].' '.($m[3]??date('Y')));if($dt)$period=['asOf'=>$dt->format('Y-m-d')];}
    if(str_contains($l,'this year') && !isset($period['from']))$period=['from'=>date('Y').'-01-01','to'=>date('Y-m-d')];
    $out=['actionId'=>$id,'period'=>$period];
    if($id==='report.account_ledger' && preg_match('/account ledger\s+(?:for\s+)?(.+?)(?:\s+from\s+|\s+for\s+|[.!?]|$)/i',$q,$m))$out['accountName']=trim($m[1]);
    return $out;
}

function tegh_party_lookup(array $company,string $kind,string $query='',bool $includeBalance=false): array
{
    if(!in_array($kind,['customer','vendor'],true))fail('Party lookup type is invalid.');
    require_company_permission($company,$kind==='customer'?'customers.view':'vendors.view');$companyId=(string)$company['id'];$q=trim($query);$params=[$companyId];$where='p.company_id=? AND p.active=1';
    if($q!==''){$where.=' AND p.name LIKE ?';$params[]='%'.str_replace(['%','_'],['\\%','\\_'],$q).'%';}
    if($kind==='customer'){
        $balance=$includeBalance?",COALESCE(SUM(CASE WHEN d.status IN ('sent','paid') THEN d.balance_cents ELSE 0 END),0) balance_cents":",0 balance_cents";
        $sql="SELECT p.id,p.name,p.email,p.phone,p.province $balance FROM customers p LEFT JOIN invoices d ON d.company_id=p.company_id AND d.customer_id=p.id WHERE $where GROUP BY p.id,p.name,p.email,p.phone,p.province ORDER BY p.name LIMIT 20";
    }else{
        $balance=$includeBalance?",COALESCE(SUM(CASE WHEN d.status IN ('open','paid') THEN d.balance_cents ELSE 0 END),0) balance_cents":",0 balance_cents";
        $sql="SELECT p.id,p.name,p.email,NULL phone,NULL province $balance FROM vendors p LEFT JOIN bills d ON d.company_id=p.company_id AND d.vendor_id=p.id WHERE $where GROUP BY p.id,p.name,p.email ORDER BY p.name LIMIT 20";
    }
    $stmt=db()->prepare($sql);$stmt->execute($params);return $stmt->fetchAll();
}

function tegh_navigation_intent(string $q): ?array
{
    $l=tegh_normalize_action_text($q);$explicit=preg_match('/\b(go to|open|take me to|navigate to|show me)\b/',$l)
        || preg_match('/\bwhere\s+(?:do|can|should)\s+i\s+(?:enter|record|find|open)\b/',$l)
        || preg_match('/\b(?:payroll setup|payroll verification|payroll employees?|cra (?:payroll )?remittance(?: screen| page)?|payroll remittance(?: screen| page)?)\b/',$l);
    $map=[
        'nav.bank_review'=>['bank review'],'nav.bank_import'=>['import bank statement','import statement','bank import'],'nav.reconciliation'=>['bank reconciliation','reconcile'],
        'nav.banking'=>['banking'],'nav.receivables'=>['receivable'],'nav.customers'=>['customers'],
        // More-specific vendor register terms must resolve before the generic "invoice register" alias.
        'nav.vendor_invoices'=>['vendor invoice register','bill register'],'nav.customer_invoices'=>['customer invoice register','invoice register'],
        'nav.customer_payments'=>['customer payments'],'nav.payables'=>['payable'],'nav.vendors'=>['vendors'],
        'nav.vendor_payments'=>['vendor payments'],'nav.day_book'=>['day book'],'nav.trial_balance'=>['trial balance'],'nav.account_ledger'=>['account ledger'],'nav.gl'=>['general ledger',' gl'],
        'nav.import_customer_invoices'=>['import customer invoice','import sales invoice'],'nav.import_vendor_invoices'=>['import vendor invoice','import supplier invoice','import purchase invoice','import bills'],
        'nav.import_coa_opening_balances'=>['import chart of accounts and opening balances','import coa and opening balances'],'nav.import_opening_balances'=>['import opening balances'],'nav.import_chart_of_accounts'=>['import chart of accounts','import coa'],
        'nav.import_products'=>['import products','import product and services','import products and services','import services'],'nav.import_customers'=>['import customers'],'nav.import_vendors'=>['import vendors','import suppliers'],'nav.data_import'=>['data import','import centre','import center'],
        'nav.payroll'=>['payroll setup','payroll verification','payroll employees','cra payroll remittance','cra remittance','payroll remittance','payroll'],'nav.reports'=>['reports'],'nav.tax_settings'=>['tax settings'],'nav.settings'=>['settings'],'nav.guided_home'=>['guided home'],
    ];
    if(!$explicit && !preg_match('/^(banking|receivables?|payables?|payroll|reports?|settings)$/i',trim($q)))return null;
    foreach($map as $id=>$terms)foreach($terms as $term)if(str_contains(' '.$l,$term))return ['actionId'=>$id];return null;
}

function tegh_extract_create_name(string $q,string $kind): ?string
{
    $subject=$kind==='customer'?'customer':'(?:vendor|supplier)';
    $pat='/\b(?:create|add|new)\s+(?:a\s+)?'.$subject.'(?:\s+(?:named|called))?\s+["“]?(.+?)["”]?(?=\s+(?:and\s+then|then|and|with)\b|[.!?]|$)/iu';
    $source=$q;
    if(!preg_match($pat,$source,$m)){
        $source=tegh_normalize_action_text($q);
        if(!preg_match($pat,$source,$m))return null;
    }
    $name=trim($m[1]," \t\n\r\0\x0B\"“”'.,!?");
    return $name!==''&&!tegh_reserved_entity_candidate($name)?$name:null;
}


function tegh_resolve_party_reference(array $company,string $kind,string $query): array
{
    $query=trim($query," \t\n\r\0\x0B\"“”'.,!?");
    if($query==='')return ['status'=>'missing','party'=>null,'candidates'=>[]];
    $rows=tegh_party_lookup($company,$kind,$query,false);
    $exact=array_values(array_filter($rows,static fn(array $row):bool=>mb_strtolower(trim((string)$row['name']))===mb_strtolower($query)));
    if(count($exact)===1)return ['status'=>'resolved','party'=>['id'=>(string)$exact[0]['id'],'name'=>(string)$exact[0]['name']],'candidates'=>$rows];
    if(count($rows)===1)return ['status'=>'resolved','party'=>['id'=>(string)$rows[0]['id'],'name'=>(string)$rows[0]['name']],'candidates'=>$rows];
    return ['status'=>$rows?'ambiguous':'missing','party'=>null,'candidates'=>$rows];
}

function tegh_start_invoice_task(array $user,array $company,string $q): array
{
    $query=tegh_extract_invoice_party_query($q,'customer');$resolved=null;
    if($query!==null)$resolved=tegh_resolve_party_reference($company,'customer',$query);
    $customer=$resolved&&$resolved['status']==='resolved'?$resolved['party']:null;
    if(!$customer)$customer=tegh_recent_created_party($user,$company,'customer');
    $collected=$customer?['customer'=>$customer]:[];$missing=$customer?['invoiceDetails']:['customer','invoiceDetails'];
    $task=tegh_agent_task($user,$company,'invoice.create',$q,'collecting_invoice',$collected,$missing);
    if(!$customer){
        $out=tegh_needs_input_response($task,'invoice.create','Which customer should the invoice be for?');
        if($resolved&&$resolved['status']==='ambiguous')$out['candidates']=$resolved['candidates'];
        elseif($resolved&&$resolved['status']==='missing'&&$query!==null)$out['message']='I could not find that active customer. Which customer should the invoice be for?';
        return $out;
    }
    return tegh_needs_input_response($task,'invoice.create',"I’ll create an invoice for {$customer['name']}. What should I invoice them for? Include the service or item and amount.",'customer-invoice',['customerId'=>$customer['id'],'customerName'=>$customer['name']]);
}

function tegh_start_bill_task(array $user,array $company,string $q): array
{
    $query=tegh_extract_invoice_party_query($q,'vendor');$resolved=null;
    if($query!==null)$resolved=tegh_resolve_party_reference($company,'vendor',$query);
    $vendor=$resolved&&$resolved['status']==='resolved'?$resolved['party']:null;
    if(!$vendor)$vendor=tegh_recent_created_party($user,$company,'vendor');
    $details=tegh_invoice_detail_from_text($q);$collected=$vendor?['vendor'=>$vendor]:[];
    if($details['amountCents']!==null)$collected['billDetails']=$details;
    $missing=[];if(!$vendor)$missing[]='vendor';if(empty($collected['billDetails']))$missing[]='billDetails';
    $task=tegh_agent_task($user,$company,'bill.create',$q,'collecting_bill',$collected,$missing);
    if(!$vendor){
        $out=tegh_needs_input_response($task,'bill.create','Which vendor should this vendor invoice be for?');
        if($resolved&&$resolved['status']==='ambiguous')$out['candidates']=$resolved['candidates'];
        return $out;
    }
    if(empty($collected['billDetails']))return tegh_needs_input_response($task,'bill.create',"I’ll prepare the vendor invoice for {$vendor['name']}. What is the amount and what was purchased?",'bills',['mode'=>'new','vendorId'=>$vendor['id'],'vendorName'=>$vendor['name']]);
    tegh_agent_task_update((string)$task['id'],$user,$company,['workflow_state'=>'existing_workflow','missing_json'=>'[]','status'=>'active']);
    $task['status']='active';$task['workflowState']='existing_workflow';
    return ['recognized'=>true,'kind'=>'workflow','actionId'=>'bill.create','navigation'=>'bills','task'=>$task,'workflowContext'=>['mode'=>'new','vendorId'=>$vendor['id'],'vendorName'=>$vendor['name'],'amountCents'=>$details['amountCents'],'memo'=>$details['description']],'message'=>'The existing Vendor Invoice workflow is open with the known details prepared. Review the remaining required fields before saving or posting.'];
}

function tegh_agent_read_help_intent(string $q): bool
{
    $n=strtolower(trim(preg_replace('/\s+/', ' ', $q)??$q));
    if($n==='')return false;
    return (bool)(
        preg_match('/^(what can i do|what should i do next)(?: on| with)? (?:this|the|current) (?:screen|page)[?.!]*$/i',$n) ||
        preg_match('/^(help(?: me)?|explain)(?: with| on)? (?:this|the|current) (?:screen|page)[?.!]*$/i',$n) ||
        preg_match('/^(what is|what does) (?:this|the|current) (?:screen|page)(?: do| show| mean)?[?.!]*$/i',$n) ||
        preg_match('/^find (?:me )?a related report[?.!]*$/i',$n) ||
        preg_match('/^which report should i use(?: for (?:this|the|current) (?:screen|page|work))?[?.!]*$/i',$n) ||
        preg_match('/^what should i do next[?.!]*$/i',$n)
    );
}

function tegh_waiting_task_message_is_relevant(array $active,string $q,?array $explicit): bool
{
    $actionId=(string)($active['action_id']??'');
    if($explicit)return (string)($explicit['actionId']??'')===$actionId;
    $n=tegh_normalize_action_text($q);
    if($n==='' || preg_match('/^(hi|hello|hey|thanks|thank you|ok|okay)$/i',$n))return false;
    if(preg_match('/^(what|how|why|where|when|help|explain|show|find|open|go|take|navigate|tell|list|report|dashboard|settings|banking|receivables|payables|payroll)\b/i',$n))return false;
    $missing=tegh_task_payload($active,'missing_json');
    if(in_array($actionId,['invoice.create','bill.create'],true) && (in_array('invoiceDetails',$missing,true)||in_array('billDetails',$missing,true))){
        $details=tegh_invoice_detail_from_text($q);
        return $details['amountCents']!==null && trim((string)$details['description'])!=='';
    }
    if(($actionId==='invoice.create'&&in_array('customer',$missing,true))||($actionId==='bill.create'&&in_array('vendor',$missing,true))){
        return mb_strlen(trim($q))<=100 && !str_contains($q,'?');
    }
    if(in_array($actionId,['customer.create','vendor.create'],true)){
        return mb_strlen(trim($q))<=100 && !str_contains($q,'?');
    }
    return true;
}

function tegh_continue_waiting_task(array $user,array $company,array $active,string $q,?array $explicit): ?array
{
    if((string)$active['status']!=='waiting_input')return null;
    // Router clarifications own their strict choice continuation. They must not
    // be mistaken for invoice, journal or other slot-collection tasks.
    if((string)($active['workflow_state']??'')==='router_clarification')return null;
    $actionId=(string)$active['action_id'];$normalized=tegh_normalize_action_text($q);
    if($explicit&&($explicit['actionId']??'')!==$actionId)return null;
    if(!tegh_waiting_task_message_is_relevant($active,$q,$explicit))return null;
    $collected=tegh_task_payload($active,'collected_json');$missing=tegh_task_payload($active,'missing_json');

    if($actionId==='customer.create'){
        if($explicit&&$explicit['actionId']==='customer.create'&&tegh_extract_create_name($q,'customer')===null)return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'customer.create','What is the customer name?');
        $name=tegh_extract_create_name($q,'customer')??trim($q," \t\n\r\0\x0B\"“”'.,!?");
        if(tegh_reserved_entity_candidate($name))return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'customer.create','What is the customer name?');
        $province=(string)$company['province'];$payload=['customer'=>['name'=>$name,'province'=>$province]];$preview=['title'=>'Create Customer','name'=>$name,'province'=>$province,'financialCommit'=>false];
        return tegh_prepare_confirmation($user,$company,'customer.create',$q,$payload,$preview);
    }
    if($actionId==='vendor.create'){
        if($explicit&&$explicit['actionId']==='vendor.create'&&tegh_extract_create_name($q,'vendor')===null)return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'vendor.create','What is the vendor name?');
        $name=tegh_extract_create_name($q,'vendor')??trim($q," \t\n\r\0\x0B\"“”'.,!?");
        if(tegh_reserved_entity_candidate($name))return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'vendor.create','What is the vendor name?');
        $payload=['vendor'=>['name'=>$name,'currency'=>(string)$company['currency'],'defaultTermsDays'=>30]];$preview=['title'=>'Create Vendor','name'=>$name,'currency'=>(string)$company['currency'],'defaultTermsDays'=>30,'financialCommit'=>false];
        return tegh_prepare_confirmation($user,$company,'vendor.create',$q,$payload,$preview);
    }
    if($actionId==='invoice.create'){
        $customer=$collected['customer']??null;
        if(in_array('customer',$missing,true)){
            if($explicit&&($explicit['actionId']??'')==='invoice.create')return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'invoice.create','Which customer should the invoice be for?');
            $resolved=tegh_resolve_party_reference($company,'customer',$q);
            if($resolved['status']!=='resolved'){
                $out=tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'invoice.create',$resolved['status']==='ambiguous'?'More than one customer matches. Which customer should I use?':'I could not find that active customer. Which customer should the invoice be for?');
                if($resolved['candidates'])$out['candidates']=$resolved['candidates'];return $out;
            }
            $customer=$resolved['party'];$collected['customer']=$customer;$missing=array_values(array_diff($missing,['customer']));
            tegh_agent_task_update((string)$active['id'],$user,$company,['collected_json'=>tegh_json($collected),'missing_json'=>tegh_json($missing),'workflow_state'=>'collecting_invoice']);
            return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'invoice.create',"I’ll create an invoice for {$customer['name']}. What should I invoice them for? Include the service or item and amount.",'customer-invoice',['customerId'=>$customer['id'],'customerName'=>$customer['name']]);
        }
        if($normalized==='invoice'||$normalized==='customer invoice'||($explicit&&($explicit['actionId']??'')==='invoice.create'&&!preg_match('/[0-9$]/',$q))){
            return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'invoice.create',"What should I invoice {$customer['name']} for? Include the service or item and amount.",'customer-invoice',['customerId'=>$customer['id'],'customerName'=>$customer['name']]);
        }
        $details=tegh_invoice_detail_from_text($q);
        if($details['description']===''||$details['amountCents']===null)return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'invoice.create',"Please give me both the invoice item/service and amount for {$customer['name']}, for example: Consulting services $500.",'customer-invoice',['customerId'=>$customer['id'],'customerName'=>$customer['name']]);
        $collected['invoiceDetails']=$details;$missing=array_values(array_diff($missing,['invoiceDetails']));
        tegh_agent_task_update((string)$active['id'],$user,$company,['collected_json'=>tegh_json($collected),'missing_json'=>tegh_json($missing),'workflow_state'=>'existing_workflow','status'=>'active']);
        return ['recognized'=>true,'kind'=>'workflow','actionId'=>'invoice.create','navigation'=>'customer-invoice','task'=>['id'=>(string)$active['id'],'actionId'=>'invoice.create','status'=>'active'],'workflowContext'=>['customerId'=>$customer['id'],'customerName'=>$customer['name'],'line'=>['description'=>$details['description'],'quantity'=>1,'unitPriceCents'=>$details['amountCents']]],'message'=>'The existing Customer Invoice workflow is open with the known details prepared. Review dates, tax and any remaining fields before saving or issuing.'];
    }
    if($actionId==='bill.create'){
        $vendor=$collected['vendor']??null;
        if(in_array('vendor',$missing,true)){
            if($explicit&&($explicit['actionId']??'')==='bill.create')return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'bill.create','Which vendor should this vendor invoice be for?');
            $resolved=tegh_resolve_party_reference($company,'vendor',$q);
            if($resolved['status']!=='resolved'){
                $out=tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'bill.create',$resolved['status']==='ambiguous'?'More than one vendor matches. Which vendor should I use?':'I could not find that active vendor. Which vendor should this invoice be for?');
                if($resolved['candidates'])$out['candidates']=$resolved['candidates'];return $out;
            }
            $vendor=$resolved['party'];$collected['vendor']=$vendor;$missing=array_values(array_diff($missing,['vendor']));
            tegh_agent_task_update((string)$active['id'],$user,$company,['collected_json'=>tegh_json($collected),'missing_json'=>tegh_json($missing),'workflow_state'=>'collecting_bill']);
            return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'bill.create',"I’ll prepare the vendor invoice for {$vendor['name']}. What is the amount and what was purchased?",'bills',['mode'=>'new','vendorId'=>$vendor['id'],'vendorName'=>$vendor['name']]);
        }
        $details=tegh_invoice_detail_from_text($q);$existingDetails=is_array($collected['billDetails']??null)?$collected['billDetails']:[];
        if($details['amountCents']===null && array_key_exists('amountCents',$existingDetails))$details['amountCents']=$existingDetails['amountCents'];
        if($details['description']==='' && !empty($existingDetails['description']))$details['description']=$existingDetails['description'];
        if($details['amountCents']===null)return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'bill.create',"What is the vendor invoice amount for {$vendor['name']}?",'bills',['mode'=>'new','vendorId'=>$vendor['id'],'vendorName'=>$vendor['name']]);
        if($details['description']==='')return tegh_needs_input_response(['id'=>(string)$active['id'],'actionId'=>$actionId,'status'=>'waiting_input'],'bill.create',"What was purchased from {$vendor['name']}?",'bills',['mode'=>'new','vendorId'=>$vendor['id'],'vendorName'=>$vendor['name'],'amountCents'=>$details['amountCents']]);
        $collected['billDetails']=$details;$missing=array_values(array_diff($missing,['billDetails']));
        tegh_agent_task_update((string)$active['id'],$user,$company,['collected_json'=>tegh_json($collected),'missing_json'=>tegh_json($missing),'workflow_state'=>'existing_workflow','status'=>'active']);
        return ['recognized'=>true,'kind'=>'workflow','actionId'=>'bill.create','navigation'=>'bills','task'=>['id'=>(string)$active['id'],'actionId'=>'bill.create','status'=>'active'],'workflowContext'=>['mode'=>'new','vendorId'=>$vendor['id'],'vendorName'=>$vendor['name'],'amountCents'=>$details['amountCents'],'memo'=>$details['description']],'message'=>'The existing Vendor Invoice workflow is open with the known details prepared. Review invoice number, date, account and tax before saving or posting.'];
    }
    return null;
}

function tegh_extract_target_ids(array $result): array
{
    $selected=$result['selectedIds']??[];return array_values($selected?:($result['recordIds']??[]));
}

function tegh_fresh_bank_targets(array $company,array $ids): array
{
    if(!$ids)return ['eligible'=>[],'ineligible'=>[],'rows'=>[]];$ids=array_slice(array_values(array_unique($ids)),0,100);$ph=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("SELECT bt.id,bt.transaction_date,bt.description,bt.amount_cents,bt.status,bt.decided_account_id,bt.tax_code,bt.source_hash,bt.updated_at,CASE WHEN EXISTS(SELECT 1 FROM reconciliation_items ri JOIN reconciliations r ON r.id=ri.reconciliation_id WHERE ri.bank_transaction_id=bt.id AND r.company_id=bt.company_id AND r.status='complete') THEN 1 ELSE 0 END reconciled FROM bank_transactions bt WHERE bt.company_id=? AND bt.id IN ($ph)");
    $stmt->execute(array_merge([(string)$company['id']],$ids));$rows=$stmt->fetchAll();$by=[];foreach($rows as $r)$by[(string)$r['id']]=$r;$eligible=[];$bad=[];
    foreach($ids as $id){$r=$by[$id]??null;if(!$r){$bad[]=['id'=>$id,'reason'=>'no_longer_available'];continue;}if((string)$r['status']!=='pending'){$bad[]=['id'=>$id,'reason'=>'status_'.$r['status']];continue;}if((bool)$r['reconciled']){$bad[]=['id'=>$id,'reason'=>'reconciled'];continue;}$eligible[]=$id;}
    return ['eligible'=>$eligible,'ineligible'=>$bad,'rows'=>$rows];
}

function tegh_fresh_bank_destructive_targets(array $company,array $ids,string $action): array
{
    if(!$ids)return ['eligible'=>[],'ineligible'=>[],'rows'=>[]];$ids=array_slice(array_values(array_unique(array_map('strval',$ids))),0,100);$ph=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("SELECT bt.id,bt.status,bt.journal_entry_id,CASE WHEN EXISTS(SELECT 1 FROM reconciliation_items ri JOIN reconciliations r ON r.id=ri.reconciliation_id WHERE ri.bank_transaction_id=bt.id AND r.company_id=bt.company_id AND r.status='complete') THEN 1 ELSE 0 END reconciled FROM bank_transactions bt WHERE bt.company_id=? AND bt.id IN ($ph)");
    $stmt->execute(array_merge([(string)$company['id']],$ids));$rows=$stmt->fetchAll();$by=[];foreach($rows as $r)$by[(string)$r['id']]=$r;$eligible=[];$bad=[];
    foreach($ids as $id){$r=$by[$id]??null;if(!$r){$bad[]=['id'=>$id,'reason'=>'no_longer_available'];continue;}if((bool)$r['reconciled']){$bad[]=['id'=>$id,'reason'=>'reconciled'];continue;}
        if($action==='exclude'){if((string)$r['status']!=='pending'){$bad[]=['id'=>$id,'reason'=>'status_'.$r['status']];continue;}}
        else {if(!in_array((string)$r['status'],['pending','excluded'],true) || $r['journal_entry_id']!==null){$bad[]=['id'=>$id,'reason'=>'protected_or_posted'];continue;}}
        $eligible[]=$id;
    }
    return ['eligible'=>$eligible,'ineligible'=>$bad,'rows'=>$rows];
}

/**
 * Return company-scoped Chart of Accounts identities used by a resolved journal.
 * Accounts has no updated_at column in schema 34, so every accounting-relevant
 * field is included in the canonical identity instead of relying on a phantom
 * version field.
 */
function tegh_journal_authorization_identity_rows(array $company,array $journal): array
{
    $ids=[];foreach((array)($journal['lines']??[]) as $line){$id=trim((string)($line['accountId']??''));if($id!=='')$ids[]=$id;}
    $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);if(!$ids)fail('The journal preview has no account identities to protect.',409,'authorization_snapshot_incomplete');
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("SELECT id,code,name,account_type,normal_balance,is_control,active FROM accounts WHERE company_id=? AND id IN ($ph)");
    $stmt->execute(array_merge([(string)$company['id']],$ids));$found=[];foreach($stmt->fetchAll() as $row)$found[(string)$row['id']]=$row;
    $rows=[];foreach($ids as $id){$row=$found[$id]??null;if(!$row)fail('An account in the journal preview is no longer available. Review the journal again.',409,'result_set_changed');$rows[]=['id'=>$id,'_teghIdentity'=>[
        'kind'=>'journal_account','accountId'=>$id,'accountCode'=>(string)$row['code'],'accountName'=>(string)$row['name'],'accountType'=>(string)$row['account_type'],'normalBalance'=>(string)$row['normal_balance'],'isControl'=>(bool)$row['is_control'],'active'=>(bool)$row['active'],
    ]];}
    return $rows;
}

function tegh_journal_authorization_guard(array $company,array $journal): array
{
    $rows=tegh_journal_authorization_identity_rows($company,$journal);$targetIds=array_map(static fn(array $row):string=>(string)$row['id'],$rows);
    return ['statusSnapshot'=>tegh_agent_status_snapshot($rows),'targetIds'=>$targetIds];
}

function tegh_prepare_confirmation(array $user,array $company,string $actionId,string $intent,array $payload,array $preview,?string $resultSetId=null,array $routing=[]): array
{
    $action=tegh_action_get($actionId);tegh_action_assert_permission($company,$action,$user);$class=tegh_action_execution_class($action);
    $payload['_teghAi']=[
        'actionId'=>$actionId,'path'=>(string)($routing['path']??'deterministic_command'),
        'confidence'=>max(0.0,min(1.0,(float)($routing['confidence']??1.0))),
        'model'=>(string)($routing['model']??'none'),
        'mode'=>in_array((string)($routing['mode']??''),['guided','full'],true)?(string)$routing['mode']:'',
        'registryVersion'=>(string)($routing['registryVersion']??(defined('TEGH_ROUTER_VERSION')?TEGH_ROUTER_VERSION:'registry')),
    ];
    if($class==='prepared'){
        tegh_agent_cancel_active($user,$company,'prepared_action_replaced');
        $task=tegh_agent_task($user,$company,$actionId,$intent,'prepared_for_review',$payload,[],$resultSetId,null,false);
        audit_event($user,(string)$company['id'],'ai_agent.action_prepared','ai_agent_task',(string)$task['id'],['actionId'=>$actionId,'executionClass'=>'prepared','path'=>$payload['_teghAi']['path'],'confidence'=>$payload['_teghAi']['confidence'],'model'=>$payload['_teghAi']['model'],'registryVersion'=>$payload['_teghAi']['registryVersion'],'posted'=>0]);
        $prefill=$payload;unset($prefill['_teghAi']);
        return ['recognized'=>true,'kind'=>'prepared','executionClass'=>'prepared','actionId'=>$actionId,'task'=>$task,'preview'=>$preview,'prefill'=>$prefill,'navigation'=>(string)$action['route'],'message'=>'The known details are prepared in the existing Tegh workflow. Review and save them there; nothing has been committed.'];
    }
    if($class!=='authorized')fail('That action does not require an accounting authorization.',409,'authorization_not_required');
    if(empty($action['supports_ai_execution']))fail('That protected action must be completed in its existing Tegh workflow.',409,'authorization_adapter_unavailable');
    if($actionId==='journal.post'){
        if(!function_exists('agent_resolve_posting_payload'))fail('The validated journal service is unavailable.',503,'authorization_adapter_unavailable');
        $journal=is_array($payload['journal']??null)?$payload['journal']:[];$resolved=agent_resolve_posting_payload($company,$journal);
        $payload['journal']=$resolved;$payload['_teghResultGuard']=tegh_journal_authorization_guard($company,$resolved);
    }
    // Replacement order matters: invalidate stale task/confirmation state, create a task, then bind one server-generated confirmation to it.
    tegh_agent_cancel_active($user,$company,'superseded');
    $task=tegh_agent_task($user,$company,$actionId,$intent,'ready_for_approval',$payload,[],$resultSetId,null,false);
    $minutes=tegh_confirmation_window_minutes($actionId,$payload);$confirmationId=tegh_agent_authorization($user,$company,$actionId,$payload,$minutes,(string)($task['conversationId']??''),(string)($task['planId']??''),(string)$task['id']);
    tegh_agent_task_update((string)$task['id'],$user,$company,['pending_confirmation_id'=>$confirmationId,'status'=>'waiting_confirmation']);$task['confirmationId']=$confirmationId;$task['status']='waiting_confirmation';
    if(!empty($task['planId'])&&schema_table_exists('ai_agent_plans'))db()->prepare("UPDATE ai_agent_plans SET status='waiting_confirmation',requires_confirmation=1 WHERE id=? AND company_id=? AND user_id=?")->execute([(string)$task['planId'],(string)$company['id'],(string)$user['id']]);
    if(function_exists('tegh_ai_record_event'))tegh_ai_record_event($user,$company,'suggestion_offered',['question'=>$intent,'module'=>(string)(tegh_action_get($actionId)['module']??''),'actionId'=>$actionId,'intentCategory'=>'Prepare','signal'=>0,'outcome'=>'prepared','confidenceBps'=>9000,'resultSetId'=>$resultSetId,'confirmationIdHash'=>hash('sha256',$confirmationId)]);
    return ['recognized'=>true,'kind'=>'confirmation','actionId'=>$actionId,'task'=>$task,'confirmationId'=>$confirmationId,'confirmationExpiresInSeconds'=>$minutes*60,'operationKey'=>schema_column_exists('ai_agent_action_authorizations','operation_key')?(function()use($confirmationId,$company,$user){$q=db()->prepare('SELECT operation_key FROM ai_agent_action_authorizations WHERE id=? AND company_id=? AND user_id=?');$q->execute([$confirmationId,(string)$company['id'],(string)$user['id']]);return (string)($q->fetchColumn()?:'');})():'','preview'=>$preview,'message'=>'Ready for approval.'];
}

function tegh_fresh_bank_identity_rows(array $company,array $ids): array
{
    $ids=array_values(array_unique(array_map('strval',$ids)));if(!$ids)return [];$ph=implode(',',array_fill(0,count($ids),'?'));
    $q=db()->prepare("SELECT id,status,amount_cents,decided_account_id,tax_code,source_hash,updated_at FROM bank_transactions WHERE company_id=? AND id IN ($ph)");
    $q->execute(array_merge([(string)$company['id']],$ids));return $q->fetchAll();
}

function tegh_execute_bank_authorization(array $user,array $company,string $actionId,array $payload,bool $resuming=false): array
{
    $resultSetId=clean_text($payload['resultSetId']??'','Result set',64);$result=tegh_agent_result_get($resultSetId,$user,$company);
    $targets=array_values(array_unique(array_map('strval',(array)($payload['targetIds']??[]))));if(!$targets)fail('The approved transaction scope is empty.',409,'empty_result_set');
    if($actionId==='bank.transactions.post'){
        $identity=tegh_fresh_bank_identity_rows($company,$targets);$by=[];foreach($identity as $row)$by[(string)$row['id']]=$row;$snapshot=(array)($result['statusSnapshot']??[]);$providedAccount=trim((string)($payload['accountId']??''));$taxExplicit=(bool)($payload['taxExplicit']??false);$pending=[];$recovered=[];
        foreach($targets as $id){$row=$by[$id]??null;if(!$row)fail('A selected transaction is no longer available.',409,'result_set_changed');$prior=is_array($snapshot[$id]??null)?$snapshot[$id]:[];$accountId=$providedAccount!==''?$providedAccount:trim((string)($prior['accountId']??$row['decided_account_id']??''));if($accountId==='')fail('A selected transaction no longer has an approved bookkeeping category.',409,'posting_category_required');$stored=(string)($prior['taxCode']??$row['tax_code']??'NO_TAX');$applyGst=$taxExplicit?(bool)($payload['applyGstHst']??false):in_array($stored,['GST_HST','HST13','GST_HST_PST'],true);$applyPst=$taxExplicit?(bool)($payload['applyPst']??false):in_array($stored,['PST','GST_HST_PST'],true);$taxCode=$applyGst&&$applyPst?'GST_HST_PST':($applyGst?'GST_HST':($applyPst?'PST':'NO_TAX'));$decision=['id'=>$id,'accountId'=>$accountId,'applyGstHst'=>$applyGst,'applyPst'=>$applyPst,'taxCode'=>$taxCode];if($resuming&&(string)$row['status']==='posted'){$existing=bank_transaction_existing_post_result($company,$decision);if($existing===null)fail('The prior posting attempt could not be verified.',409,'authorization_recovery_failed');$recovered[]=$existing;}else{$pending[]=$decision;}}
        if($pending){$pendingIds=array_column($pending,'id');tegh_agent_result_assert_unchanged($result,$identity,$pendingIds);$fresh=tegh_fresh_bank_targets($company,$pendingIds);if(count($fresh['eligible'])!==count($pendingIds))fail('A transaction is no longer eligible to post. Refresh and review the result set again.',409,'result_set_changed');$posted=bank_transaction_post_service($user,$company,$pending,'ask_tegh');}else$posted=0;
        $q=db()->prepare('SELECT id,journal_entry_id,decided_account_id,tax_code FROM bank_transactions WHERE company_id=? AND id IN ('.implode(',',array_fill(0,count($targets),'?')).') ORDER BY id');$q->execute(array_merge([(string)$company['id']],$targets));
        return ['posted'=>$posted,'recovered'=>count($recovered),'transactionCount'=>count($targets),'transactions'=>$q->fetchAll(),'followUp'=>$payload['followUp']??null];
    }
    $kind=$actionId==='bank.transactions.delete'?'delete':'exclude';$identity=tegh_fresh_bank_identity_rows($company,$targets);$by=[];foreach($identity as $row)$by[(string)$row['id']]=$row;$remaining=[];$recovered=0;
    foreach($targets as $id){$row=$by[$id]??null;if($resuming&&$kind==='delete'&&$row===null){$recovered++;continue;}if($resuming&&$kind==='exclude'&&is_array($row)&&(string)$row['status']==='excluded'){$recovered++;continue;}if(!$row)fail('A transaction in the approved scope is no longer available.',409,'result_set_changed');$remaining[]=$id;}
    if($remaining){tegh_agent_result_assert_unchanged($result,$identity,$remaining);$fresh=tegh_fresh_bank_destructive_targets($company,$remaining,$kind);if(count($fresh['eligible'])!==count($remaining))fail('A transaction is no longer eligible for the approved action. Refresh and review the result set again.',409,'result_set_changed');$changed=$kind==='delete'?bank_transaction_delete_service($user,$company,$remaining,'ask_tegh'):bank_transaction_review_state_service($user,$company,$remaining,'exclude','ask_tegh');}else$changed=$kind==='delete'?['deleted'=>0,'postedEntriesDeleted'=>0]:['updated'=>0,'status'=>'excluded'];
    $changed['recovered']=$recovered;$changed['approvedCount']=count($targets);return $changed;
}

function tegh_execute_journal_authorization(array $user,array $company,array $payload,string $confirmationId,bool $resuming=false): array
{
    // If a request reached the posting service before the response was lost,
    // recover the existing confirmation-derived journal before revalidating a
    // now-irrelevant preview. This is the idempotent retry path, not a new post.
    if($resuming){$sourceId='ask_'.substr(hash('sha256',$confirmationId),0,48);$prior=db()->prepare("SELECT id FROM journal_entries WHERE company_id=? AND source_type='manual_journal' AND source_id=? AND status='posted' LIMIT 1");$prior->execute([(string)$company['id'],$sourceId]);$existing=$prior->fetchColumn();if($existing!==false)return ['journalEntry'=>['id'=>(string)$existing],'idempotent'=>true];}
    $journal=is_array($payload['journal']??null)?$payload['journal']:[];$resolved=agent_resolve_posting_payload($company,$journal);
    $guard=is_array($payload['_teghResultGuard']??null)?$payload['_teghResultGuard']:[];$targetIds=array_values(array_unique(array_map('strval',(array)($guard['targetIds']??[]))));
    if(!$targetIds||!is_array($guard['statusSnapshot']??null))fail('The journal approval has no complete preview snapshot. Prepare and review it again.',409,'authorization_snapshot_incomplete');
    $rows=tegh_journal_authorization_identity_rows($company,$resolved);$currentIds=array_map(static fn(array $row):string=>(string)$row['id'],$rows);sort($targetIds,SORT_STRING);sort($currentIds,SORT_STRING);
    if($targetIds!==$currentIds)fail('The journal account scope changed after preview. Prepare and review it again.',409,'result_set_changed');
    tegh_agent_result_assert_unchanged($guard,$rows,$targetIds);
    return manual_journal_post_service($user,$company,['date'=>$resolved['date'],'memo'=>$resolved['memo'],'lines'=>$resolved['lines']],'ask_tegh',$confirmationId);
}

function tegh_execute_registered_authorized_action(array $user,array $company,string $actionId,array $payload,string $confirmationId,bool $resuming=false): array
{
    return match($actionId){
        'bank.transactions.post','bank.transactions.exclude','bank.transactions.delete'=>tegh_execute_bank_authorization($user,$company,$actionId,$payload,$resuming),
        'bank.transactions.match_post'=>tegh_execute_authorized_reconciliation($user,$company,$payload,$resuming),
        'journal.post'=>tegh_execute_journal_authorization($user,$company,$payload,$confirmationId,$resuming),
        default=>fail('That protected action must be completed in its existing Tegh workflow because no verified Ask Tegh commit adapter is registered.',409,'authorization_adapter_unavailable'),
    };
}

function tegh_execute_authorization(array $user,array $company,string $confirmationId): array
{
    tegh_agent_schema_ready();
    $confirmationId=clean_text($confirmationId,'Approval',64);
    $companyId=(string)$company['id'];$userId=(string)$user['id'];
    $lockName='tegh_ai_'.substr(hash('sha256',$companyId.'|'.$confirmationId),0,48);
    $lock=db()->prepare('SELECT GET_LOCK(?,5)');$lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)fail('That approval is already being processed. Check its status before trying again.',409,'authorization_in_progress');

    // fail() deliberately emits the public JSON response and exits. PHP exit is
    // not a Throwable, so a normal catch block cannot settle an authorization or
    // roll back a transaction after commit-time validation rejects the request.
    // This shutdown guard closes that boundary for every exit/fatal path while
    // the company + confirmation lock still serializes exactly-once execution.
    $authorizationSettled=false;
    register_shutdown_function(static function()use(&$authorizationSettled,$confirmationId,$companyId,$userId,$lockName):void{
        if($authorizationSettled)return;
        try{
            if(db()->inTransaction())db()->rollBack();
            $settle=db()->prepare("UPDATE ai_agent_action_authorizations SET status='cancelled',result_status='needs_review',result_json=? WHERE id=? AND company_id=? AND user_id=? AND status IN ('pending','authorized')");
            $settle->execute([tegh_json(['errorCode'=>'commit_revalidation_failed','message'=>'The approved action did not complete. Refresh current records and review it again.']),$confirmationId,$companyId,$userId]);
            if($settle->rowCount()>0)db()->prepare("UPDATE ai_agent_tasks SET status='active',workflow_state='commit_revalidation_failed',pending_confirmation_id=NULL WHERE company_id=? AND user_id=? AND pending_confirmation_id=?")->execute([$companyId,$userId,$confirmationId]);
        }catch(Throwable $shutdownError){error_log('Tegh authorization fail-closed settlement failed confirmation='.hash('sha256',$confirmationId).' '.$shutdownError::class);}
        try{$release=db()->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable){}
    });

    try{
        $stmt=db()->prepare("SELECT * FROM ai_agent_action_authorizations WHERE id=? AND company_id=? AND user_id=? AND action_type LIKE 'app:%' LIMIT 1");
        $stmt->execute([$confirmationId,$companyId,$userId]);$row=$stmt->fetch();
        if(!$row)fail('The Ask Tegh approval is no longer available.',409,'authorization_not_found');
        $status=(string)$row['status'];$prior=json_decode((string)($row['result_json']??''),true);
        if($status==='completed'){
            $authorizationSettled=true;
            if(!is_array($prior))fail('The prior Ask Tegh result could not be verified. Review the accounting record before trying anything else.',409,'authorization_recovery_failed');
            return ['recognized'=>true,'kind'=>'success','idempotent'=>true,'confirmationId'=>$confirmationId,'actionId'=>str_starts_with((string)$row['action_type'],'app:')?substr((string)$row['action_type'],4):(string)$row['action_type'],'result'=>$prior];
        }
        if(in_array($status,['cancelled','expired'],true)){$authorizationSettled=true;fail('That Ask Tegh approval is no longer active. Prepare and review the action again.',409,'authorization_stale');}
        if($status==='pending'&&strtotime((string)$row['expires_at'])<time()){
            db()->prepare("UPDATE ai_agent_action_authorizations SET status='expired',result_status='failed' WHERE id=? AND status='pending'")->execute([$confirmationId]);
            $authorizationSettled=true;fail('That Ask Tegh approval expired. Prepare and review the action again.',409,'authorization_expired');
        }
        $canonical=(string)$row['payload_json'];
        if(!hash_equals((string)$row['payload_hash'],hash('sha256',$canonical)))fail('The approved payload failed its integrity check.',409,'authorization_integrity_failed');
        $payload=json_decode($canonical,true,128,JSON_THROW_ON_ERROR);if(!is_array($payload))fail('The approved payload is invalid.',409,'authorization_integrity_failed');
        $actionId=str_starts_with((string)$row['action_type'],'app:')?substr((string)$row['action_type'],4):(string)$row['action_type'];
        $action=tegh_action_get($actionId);$meta=is_array($payload['_teghAi']??null)?$payload['_teghAi']:[];
        $approvedMode=in_array((string)($meta['mode']??''),['guided','full'],true)?(string)$meta['mode']:null;
        tegh_action_assert_permission($company,$action,$user,$approvedMode);
        if(tegh_action_execution_class($action)!=='authorized')fail('That action is not an authorized commit class.',409,'authorization_class_invalid');
        if(empty($action['supports_ai_execution']))fail('That protected action must be completed in its existing Tegh workflow.',409,'authorization_adapter_unavailable');
        $resuming=$status==='authorized';
        if($status==='pending')db()->prepare("UPDATE ai_agent_action_authorizations SET status='authorized',authorized_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND user_id=? AND status='pending'")->execute([$confirmationId,$companyId,$userId]);
        try{
            $result=tegh_execute_registered_authorized_action($user,$company,$actionId,$payload,$confirmationId,$resuming);
        }catch(Throwable $error){
            if(db()->inTransaction())db()->rollBack();
            db()->prepare("UPDATE ai_agent_action_authorizations SET status='cancelled',result_status='needs_review',result_json=? WHERE id=? AND company_id=? AND user_id=? AND status='authorized'")->execute([tegh_json(['errorCode'=>'commit_revalidation_failed','message'=>'The approved action must be reviewed again because current records or controls no longer match its preview.']),$confirmationId,$companyId,$userId]);
            db()->prepare("UPDATE ai_agent_tasks SET status='active',workflow_state='commit_revalidation_failed',pending_confirmation_id=NULL WHERE company_id=? AND user_id=? AND pending_confirmation_id=?")->execute([$companyId,$userId,$confirmationId]);
            $authorizationSettled=true;throw $error;
        }
        $complete=db()->prepare("UPDATE ai_agent_action_authorizations SET status='completed',completed_at=UTC_TIMESTAMP(),result_status='completed',result_json=? WHERE id=? AND company_id=? AND user_id=? AND status='authorized'");
        $complete->execute([tegh_json($result),$confirmationId,$companyId,$userId]);
        if($complete->rowCount()!==1)fail('The approval state changed before completion could be recorded. Review the accounting record before retrying.',409,'authorization_recovery_failed');
        db()->prepare("UPDATE ai_agent_tasks SET status='completed',workflow_state='completed',pending_confirmation_id=NULL,collected_json=? WHERE company_id=? AND user_id=? AND pending_confirmation_id=?")->execute([tegh_json(['result'=>$result]),$companyId,$userId,$confirmationId]);
        $authorizationSettled=true;
        audit_event($user,$companyId,'ai_agent.action_completed','ai_agent_action',$confirmationId,['actionId'=>$actionId,'model'=>(string)($meta['model']??'none'),'confidence'=>(float)($meta['confidence']??1),'interpretationPath'=>(string)($meta['path']??'deterministic_command'),'confirmationId'=>$confirmationId,'userId'=>$userId,'companyId'=>$companyId,'registryVersion'=>(string)($meta['registryVersion']??'registry'),'idempotencyKey'=>hash('sha256',$confirmationId)]);
        return ['recognized'=>true,'kind'=>'success','idempotent'=>false,'confirmationId'=>$confirmationId,'actionId'=>$actionId,'result'=>$result,'message'=>'The approved Tegh action completed successfully.'];
    }finally{
        try{$release=db()->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable){}
    }
}

function tegh_agent_registry_endpoint(array $user,array $company): never
{
    require_method('GET');
    $out=[];$usage=function_exists('tegh_ai_action_usage_counts')?tegh_ai_action_usage_counts($user,$company):[];
    $isPlatformOwner=platform_role_for_user((string)$user['id'])==='platform_owner';
    foreach(tegh_action_registry() as $a){
        if(!company_role_can((string)$company['role'],(string)$a['required_permission']))continue;
        if(!empty($a['owner_only'])&&!$isPlatformOwner)continue;
        $feature=(string)($a['required_feature_key']??'');if($feature!==''&&function_exists('tegh_resolve_feature')&&!tegh_resolve_feature($user,$company,$feature)['allowed'])continue;
        $a['learned_usage_count']=(int)($usage[(string)$a['action_id']]??0);$out[]=$a;
    }
    $ids=array_map(static fn(array $action):string=>(string)$action['action_id'],$out);sort($ids,SORT_STRING);
    $revision=0;if(function_exists('tegh_entitlement_my_payload'))$revision=(int)(tegh_entitlement_my_payload($user,$company)['revision']??0);
    json_response(['version'=>SR_ACCOUNTAX_VERSION,'build'=>SR_ACCOUNTAX_BUILD,'schema'=>SR_ACCOUNTAX_SCHEMA_VERSION,'count'=>count($out),'classHash'=>hash('sha256',implode("\n",$ids)),'entitlementRevision'=>$revision,'actions'=>$out]);
}

function tegh_agent_result_endpoint(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_permission($company,'banking.view');$input=request_json();$id=clean_text($input['resultSetId']??'','Result set',64);$current=tegh_agent_result_get($id,$user,$company);
    $query=is_array($input['query']??null)?(!empty($input['replaceQuery'])?$input['query']:array_merge($current['query']??[],$input['query'])):($current['query']??[]);
    if(isset($input['command']) && trim((string)$input['command'])!=='')$query=tegh_parse_bank_filters((string)$input['command'],$query);
    $rows=tegh_bank_query_rows($company,$query);$selected=is_array($input['selectedIds']??null)?array_map('strval',$input['selectedIds']):($current['selectedIds']??[]);
    if(isset($input['selectionMode'])){
        $mode=(string)$input['selectionMode'];$ids=array_map(static fn(array $r):string=>(string)$r['id'],$rows);
        if($mode==='all')$selected=$ids;elseif($mode==='none')$selected=[];elseif($mode==='debits')$selected=array_map(static fn(array $r):string=>(string)$r['id'],array_values(array_filter($rows,static fn(array $r):bool=>(int)$r['amount_cents']<0)));
    }
    $saved=tegh_agent_result_save($user,$company,$query,$rows,$id,$selected);json_response(['resultSet'=>$saved,'rows'=>$rows,'summary'=>array_merge(tegh_bank_summary($rows),['selectionCount'=>count($saved['selectedIds'])])]);
}

function tegh_agent_command(array $user,array $company): never
{
    require_method('POST');require_csrf();$t0=microtime(true);$input=request_json();$q=trim((string)($input['question']??''));if($q==='')fail('Enter a request for Ask Tegh.');$l=tegh_normalize_action_text($q);$resultSetId=trim((string)($input['resultSetId']??''));
    $clientContext=is_array($input['context']??null)?(array)$input['context']:[];$nativeIntent=is_array($input['nativeIntent']??null)?(array)$input['nativeIntent']:null;

    // Human-control guidance is deliberately stateless. A user asking HOW to
    // post/create/reconcile/finalize must receive steps even when the optional
    // durable Ask Tegh tables are still pending on an upgraded installation.
    $guideActionId=tegh_agent_procedural_guidance_action($q);
    if($guideActionId!==null){
        $guideAction=tegh_action_get($guideActionId);tegh_action_assert_permission($company,$guideAction);
        $out=tegh_ai_human_commit_guidance($guideAction,['guidanceOnly'=>true,'advancedAgentStorageReady'=>tegh_agent_schema_available()]);
        $out['message']='Here are the steps. This explanation changes nothing. A later supported action request must still pass its preview and one explicit confirmation; other protected actions open their existing Tegh workflow.';
        $out['performance']=['totalMs'=>(int)round((microtime(true)-$t0)*1000)];
        json_response($out);
    }

    // Live/current research is also read-only and must not depend on durable AI
    // state tables. A provider, cache or telemetry failure is recovered inside
    // the research gateway and must never become a generic Ask Tegh 500.
    if(function_exists('tegh_research_should_use')&&function_exists('tegh_research_openai')&&tegh_research_should_use($q)){
        $research=tegh_research_openai($user,$company,$q,'accounting_research',true);
        $research['recognized']=true;$research['kind']='research';
        if(trim((string)($research['message']??''))==='')$research['message']=$research['usedInternet']?'I checked current external information through Tegh’s secure read-only research gateway.':'I could not verify current external information. I did not invent a result.';
        $research['guidanceOnly']=!tegh_agent_schema_available();
        $research['advancedAgentStorageReady']=tegh_agent_schema_available();
        $research['performance']=['totalMs'=>(int)round((microtime(true)-$t0)*1000)];
        json_response($research);
    }

    // A short answer to a pending slot question belongs to that verified task
    // unless it clearly names a different action. Resolve it before the general
    // router so replies such as "Home Depot" or "$500 consulting" are not
    // misread as unrelated fresh commands.
    if(tegh_agent_schema_available()){
        try{
            $pendingBeforeRouting=tegh_agent_active_task($user,$company);
            if($pendingBeforeRouting && (string)$pendingBeforeRouting['status']==='waiting_input'){
                $explicitBeforeRouting=tegh_resolve_explicit_action($q);
                $continuedBeforeRouting=tegh_continue_waiting_task($user,$company,$pendingBeforeRouting,$q,$explicitBeforeRouting);
                if($continuedBeforeRouting!==null){$continuedBeforeRouting['performance']=['totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($continuedBeforeRouting);}
            }
        }catch(Throwable $error){error_log('Tegh pending-task continuation recovered '.$error::class);}
    }

    // Tegh 4.7.0 universal routing owns ordinary accounting requests. Protocol
    // replies (confirm/cancel/continuations) remain bound to server-side task
    // state and are deliberately never reinterpreted as a new action.
    $universalDecision=null;$universalActionId='';
    if(function_exists('tegh_route_universal') && (!function_exists('tegh_router_protocol_phrase') || !tegh_router_protocol_phrase($q))){
        try{
            $universalDecision=tegh_route_universal($user,$company,$q,$clientContext,$nativeIntent);
            if(!empty($universalDecision['blocked']) || ($universalDecision['kind']??'')==='blocked'){
                $universalDecision['performance']=['totalMs'=>(int)round((microtime(true)-$t0)*1000)];
                json_response($universalDecision,403);
            }
            if(($universalDecision['status']??'')==='clarify'){
                $out=tegh_router_clarification_response($user,$company,$q,$universalDecision);
                $out['performance']=['totalMs'=>(int)round((microtime(true)-$t0)*1000)];
                json_response($out);
            }
            if(($universalDecision['status']??'')==='resolved'){
                $universalActionId=(string)($universalDecision['action_id']??'');
                $registry=tegh_action_registry();$resolvedAction=$registry[$universalActionId]??null;
                try{audit_event($user,(string)$company['id'],'ai_agent.request_routed','ai_agent_action',new_id('aiaction'),[
                    'actionId'=>$universalActionId,'model'=>(string)($universalDecision['model']??(str_starts_with((string)($universalDecision['path']??''),'native')?'deterministic':'none')),
                    'confidence'=>(float)($universalDecision['confidence']??0),'interpretationPath'=>(string)($universalDecision['path']??'universal'),
                    'confirmationId'=>null,'userId'=>(string)$user['id'],'companyId'=>(string)$company['id'],
                    'registryVersion'=>(string)($universalDecision['registryVersion']??TEGH_ROUTER_VERSION),'executionClass'=>$universalActionId==='explain'?'immediate':(is_array($resolvedAction)?tegh_action_execution_class($resolvedAction):'clarify'),
                ]);}catch(Throwable $error){error_log('Tegh routed-action audit skipped '.$error::class);}
                if(is_array($resolvedAction) && tegh_action_execution_class($resolvedAction)==='immediate' && !empty($universalDecision['missing_inputs'])){
                    $missing=array_values(array_map('strval',(array)$universalDecision['missing_inputs']));
                    json_response(['recognized'=>true,'kind'=>'needs_input','actionId'=>$universalActionId,'message'=>'What '.implode(' and ',$missing).' should Tegh use?','missingInputs'=>$missing,'routing'=>['path'=>$universalDecision['path']??'universal','confidence'=>(float)($universalDecision['confidence']??0),'registryVersion'=>$universalDecision['registryVersion']??TEGH_ROUTER_VERSION]]);
                }
                $out=tegh_router_immediate_response($user,$company,$q,$clientContext,$universalDecision,$resultSetId);
                if($out!==null){$out['performance']=['totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out);}
            }
        }catch(Throwable $error){
            // Routing is optional to availability, never to safety. Existing
            // deterministic lanes remain available if the router/cache fails.
            error_log('Tegh universal router recovered request='.(function_exists('request_id')?request_id():'unknown').' '.$error::class);
            $universalDecision=null;$universalActionId='';
        }
    }

    // Safe report reads must resolve before optional durable conversation/task
    // storage. A report request must never become a generic 500 merely because
    // advanced Ask Tegh state is temporarily unhealthy. Permission and report
    // execution still use Tegh's authoritative server-side services.
    $reportEarly=tegh_parse_report($q);
    if($reportEarly){
        $action=tegh_action_get((string)$reportEarly['actionId']);tegh_action_assert_permission($company,$action);
        $reportEarly['route']=$action['execution_service'];$reportEarly['title']=$action['name'];
        if(($reportEarly['actionId']??'')==='report.account_ledger'&&!empty($reportEarly['accountName'])){
            $resolved=tegh_resolve_account($company,(string)$reportEarly['accountName']);
            if($resolved['status']==='resolved')$reportEarly['account']=$resolved['account'];else{$reportEarly['accountCandidates']=$resolved['candidates'];$reportEarly['needsAccount']=true;}
        }
        if(tegh_agent_schema_available()){
            try{tegh_agent_cancel_active($user,$company,'report_requested');}catch(Throwable $ignored){}
            try{tegh_ai_record_event($user,$company,'report_opened',['question'=>$q,'module'=>'Reports','actionId'=>(string)$reportEarly['actionId'],'intentCategory'=>'Report','signal'=>1,'outcome'=>'completed','confidenceBps'=>10000,'source'=>'safe_report_lane','period'=>$reportEarly['period']??[]]);}catch(Throwable $ignored){}
        }
        json_response(['recognized'=>true,'kind'=>'report','report'=>$reportEarly,'safeReportLane'=>true,'advancedAgentStorageReady'=>tegh_agent_schema_available(),'performance'=>['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);
    }

    // "Process an invoice" is ambiguous in an accounting system because it can
    // mean a customer invoice (Money In) or a vendor invoice/bill (Money Out).
    // Resolve that ambiguity before optional AI task storage, and never guess a
    // financial workflow from an underspecified phrase.
    if(preg_match('/\b(?:process|handle|work on)\s+(?:an?\s+|the\s+)?invoice\b/u',$l)){
        if(preg_match('/\b(?:vendor|supplier|purchase)\b/u',$l)){
            $action=tegh_action_get('bill.create');tegh_action_assert_permission($company,$action);$out=tegh_ai_human_commit_guidance($action,['mode'=>'new']);$out['performance']=['totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out);
        }
        if(preg_match('/\b(?:customer|sales)\b/u',$l)){
            $action=tegh_action_get('invoice.create');tegh_action_assert_permission($company,$action);$out=tegh_ai_human_commit_guidance($action);$out['performance']=['totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out);
        }
        require_company_permission($company,'company.view');$choices=[];
        if(company_role_can((string)$company['role'],'invoices.write'))$choices[]=['label'=>'Customer Invoice · Money In','navigation'=>'customer-invoice','actionId'=>'invoice.create'];
        if(company_role_can((string)$company['role'],'bills.write'))$choices[]=['label'=>'Vendor Invoice / Bill · Money Out','navigation'=>'bills','workflowContext'=>['mode'=>'new'],'actionId'=>'bill.create'];
        if(!$choices)fail('Your company role does not permit invoice processing.',403,'permission_forbidden');
        json_response(['recognized'=>true,'kind'=>'needs_input','message'=>'Do you want to process a customer invoice (Money In) or a vendor invoice / bill (Money Out)?','choices'=>$choices,'humanCommitOnly'=>true,'performance'=>['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);
    }

    // The accounting workspace must not lose basic Ask Tegh help merely because
    // Schema 33/34 durable-agent storage is pending. Safe navigation/reports can
    // still resolve statelessly; all other requests fall through to agent/ask,
    // which supplies read-only/local guidance without durable task state.
    if(!tegh_agent_schema_available()){
        if($universalActionId!=='' && isset(tegh_action_registry()[$universalActionId])){
            $action=tegh_action_get($universalActionId);tegh_action_assert_permission($company,$action);
            $class=tegh_action_execution_class($action);
            $message=$class==='authorized'
                ? 'Opening the existing protected Tegh workflow. Nothing has been committed; its normal preview and confirmation controls still apply.'
                : 'Opening the existing Tegh workflow in guidance-only mode. Nothing has been saved.';
            json_response(['recognized'=>true,'kind'=>'workflow','actionId'=>$universalActionId,'executionClass'=>$class,'navigation'=>(string)$action['route'],'prefill'=>(array)($universalDecision['slots']??[]),'message'=>$message,'guidanceOnly'=>true,'advancedAgentStorageReady'=>false,'routing'=>['path'=>$universalDecision['path']??'universal','confidence'=>(float)($universalDecision['confidence']??0),'registryVersion'=>$universalDecision['registryVersion']??TEGH_ROUTER_VERSION],'performance'=>['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);
        }
        $explicitStateless=tegh_resolve_explicit_action($q);
        if($explicitStateless!==null){
            $actionId=(string)($explicitStateless['actionId']??'');
            if($actionId!=='' && isset(tegh_action_registry()[$actionId])){
                $action=tegh_action_get($actionId);tegh_action_assert_permission($company,$action);
                if(tegh_ai_mutation_blocked($action)){
                    $out=tegh_ai_human_commit_guidance($action,['guidanceOnly'=>true,'advancedAgentStorageReady'=>false]);
                    $out['performance']=['totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out);
                }
            }
        }
        $reportStateless=tegh_parse_report($q);
        if($reportStateless){
            $action=tegh_action_get((string)$reportStateless['actionId']);tegh_action_assert_permission($company,$action);
            $reportStateless['route']=$action['execution_service'];$reportStateless['title']=$action['name'];
            json_response(['recognized'=>true,'kind'=>'report','report'=>$reportStateless,'guidanceOnly'=>true,'advancedAgentStorageReady'=>false,'performance'=>['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);
        }
        $navStateless=tegh_navigation_intent($q);
        if($navStateless){
            $action=tegh_action_get((string)$navStateless['actionId']);tegh_action_assert_permission($company,$action);
            json_response(['recognized'=>true,'kind'=>'navigation','actionId'=>$navStateless['actionId'],'navigation'=>$action['route'],'message'=>'Opening '.$action['name'].'.','guidanceOnly'=>true,'advancedAgentStorageReady'=>false,'performance'=>['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);
        }
        json_response(['recognized'=>false,'kind'=>'guidance','guidanceOnly'=>true,'advancedAgentStorageReady'=>false,'message'=>'Using Ask Tegh guidance-only mode while advanced conversation memory is being prepared.','performance'=>['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);
    }

    tegh_agent_invalidate_other_companies($user,$company);$conversation=null;
    if(function_exists('tegh_human_conversation')&&schema_table_exists('ai_agent_conversations')){$requestedConversation=trim((string)($input['conversationId']??''));$conversation=tegh_human_conversation($user,$company,$requestedConversation,tegh_human_mode($clientContext));tegh_human_conversation_touch($user,$company,(string)$conversation['id'],$q,['bookkeepingMode'=>tegh_human_mode($clientContext)]);}
    $interpretation=tegh_ai_interpret_request($q);$agentPlan=tegh_ai_build_plan($q,$interpretation,null,$resultSetId?:null);
    try{tegh_ai_record_event($user,$company,'command_received',['question'=>$q,'module'=>'Agent','intentCategory'=>(string)$interpretation['primary'],'confidenceBps'=>(int)$interpretation['confidenceBps'],'signal'=>0,'outcome'=>'observed','risk'=>$interpretation['risk'],'compound'=>$interpretation['compound'],'planPhases'=>array_column($agentPlan['steps'],'phase')]);}catch(Throwable $ignored){}
    try{tegh_ai_maybe_self_review($user,$company);}catch(Throwable $ignored){}
    $perf=['intentParseMs'=>0,'actionResolutionMs'=>0,'transactionQueryMs'=>0,'executionServiceMs'=>0];$p=microtime(true);
    if(preg_match('/\b(?:do|use) the same as last month\b/i',$q) && $resultSetId===''){
        $verified=$conversation?json_decode((string)($conversation['verified_context_json']??'{}'),true):[];$prior=array_values(array_filter((array)($verified['resultSets']??[])));
        if(count($prior)!==1)json_response(['recognized'=>true,'kind'=>'needs_input','message'=>'I can do that, but I do not have one unique verified prior result to use. Which prior task or result should I repeat?','conversation'=>$conversation?tegh_human_conversation_public($conversation):null,'confidence'=>'Low']);
        $resultSetId=(string)$prior[0];
    }
    if(preg_match('/^(?:those|them|do the rest|post them|categorize those|categorise those)[.!?]*$/i',$q) && $resultSetId===''){
        $verified=$conversation?json_decode((string)($conversation['verified_context_json']??'{}'),true):[];$prior=array_values(array_unique(array_filter(array_map('strval',(array)($verified['resultSets']??[])))));
        if(count($prior)===1)$resultSetId=$prior[0];elseif(count($prior)>1)json_response(['recognized'=>true,'kind'=>'needs_input','message'=>'I have more than one current result list that “those” could mean. Choose the list you want me to use.','resultSetChoices'=>$prior,'conversation'=>$conversation?tegh_human_conversation_public($conversation):null]);
    }
    $active=tegh_agent_active_task($user,$company);
    if(preg_match('/^(yes|yes please|authorize|approve|go ahead|do it|post it)\.?$/i',$q)){
        if(!$active || (string)$active['status']!=='waiting_confirmation' || trim((string)$active['pending_confirmation_id'])==='')fail('There is no current Ask Tegh action waiting for approval.',409,'no_pending_confirmation');
        $perf['intentParseMs']=(int)round((microtime(true)-$p)*1000);$e=microtime(true);$out=tegh_execute_authorization($user,$company,(string)$active['pending_confirmation_id']);$perf['executionServiceMs']=(int)round((microtime(true)-$e)*1000);$out['performance']=$perf+['totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out);
    }
    $explicitTaskCancel=(bool)preg_match('/^cancel(?: the| my)?(?: current| active)?(?: customer| vendor)?(?: invoice| bill| journal| customer| vendor)? task[.!?]*$/i',$q);
    if($explicitTaskCancel || preg_match('/^(no|cancel(?: that)?|forget that|stop|never mind|nevermind)\.?$/i',$q) || preg_match('/\b(no gl adjustment|cancel that|forget that|instead|do something else)\b/i',$q)){
        tegh_agent_cancel_active($user,$company,'cancelled_by_user');
        // Never allow the in-memory snapshot of the cancelled task to drive a later branch in this same request.
        $active=null;
        if($explicitTaskCancel || preg_match('/^(no|cancel(?: that)?|forget that|stop|never mind|nevermind)\.?$/i',$q))json_response(['recognized'=>true,'kind'=>'cancelled','message'=>'Cancelled.','performance'=>['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);
        // Continue resolving any explicit replacement intent in the same utterance.
    }
    $perf['intentParseMs']=(int)round((microtime(true)-$p)*1000);$p=microtime(true);
    $explicit=tegh_resolve_explicit_action($q);
    if($universalActionId!==''&&isset(tegh_action_registry()[$universalActionId]))$explicit=['actionId'=>$universalActionId,'source'=>'universal_router','slots'=>(array)($universalDecision['slots']??[])];
    $behaviorAction=tegh_ai_behavior_synonym($company,$q);if($behaviorAction!==null&&$explicit===null)$explicit=['actionId'=>$behaviorAction,'source'=>'learned_behavior'];

    // Explicit self-diagnostic/learning intents are company scoped and cannot
    // inherit a stale financial task. Explanation is read-only and may leave a
    // pending preview intact so a user can understand it before approving.
    if(preg_match('/\bhow (?:are you|is tegh ai) performing\b|\btegh ai performance\b/i',$q)){tegh_agent_cancel_active($user,$company,'performance_requested');$out=tegh_ai_performance_command($user,$company);$out['agentPlan']=$agentPlan;json_response($out);}
    $learningRule=tegh_ai_explicit_rule_from_command($user,$company,$q,$resultSetId);if($learningRule!==null){tegh_agent_cancel_active($user,$company,'learning_rule_requested');$learningRule['agentPlan']=$agentPlan;json_response($learningRule,$learningRule['kind']==='learned_rule'?201:200);}
    if(preg_match('/\bwhy did you (?:suggest|categorize|categorise|classify)|\bwhy (?:this|that) (?:category|account)\b/i',$q)){$out=tegh_ai_explain_suggestion($user,$company,$resultSetId);$out['agentPlan']=$agentPlan;json_response($out);}
    if(preg_match('/\breview (?:my|the) books\b|\bready to close\b|\bbooks look complete\b|\bwhy doesn.t (?:my )?balance sheet balance\b|\bwhy doesn.t (?:ar|accounts receivable) aging agree\b|\bwhy doesn.t (?:ap|accounts payable) aging agree\b|\bfind (?:unreconciled|duplicate)\b/i',$q)){tegh_agent_cancel_active($user,$company,'diagnostic_requested');$out=tegh_ai_close_review($user,$company,$q);$out['agentPlan']=$agentPlan;json_response($out);}
    if(function_exists('tegh_research_should_use')&&function_exists('tegh_research_openai')&&tegh_research_should_use($q)){try{tegh_agent_cancel_active($user,$company,'external_research_requested');}catch(Throwable $ignored){}$research=tegh_research_openai($user,$company,$q,'accounting_research',true);$research['recognized']=true;$research['kind']='research';if(trim((string)($research['message']??''))==='')$research['message']=$research['usedInternet']?'I checked current external information through Tegh’s secure read-only research gateway.':'I could not verify current external information. I did not invent a result.';$research['agentPlan']=$agentPlan;json_response($research);}

    // Screen/page help is read-only. It suspends, rather than cancels, an unrelated
    // waiting task and can never replay a previous navigation action.
    if(tegh_agent_read_help_intent($q)){
        json_response(['recognized'=>false,'kind'=>'guidance','taskSuspended'=>$active!==null,'message'=>'Screen help is handled from the current page context.','performance'=>$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);
    }

    // A waiting task may consume a plain-language follow-up only when the user
    // has not explicitly requested a different action. Latest explicit intent wins.
    if($active){
        $continued=tegh_continue_waiting_task($user,$company,$active,$q,$explicit);
        if($continued!==null){$continued['performance']=$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($continued);}
    }

    // Multi-action create chains are explicit plans. The follow-up starts only
    // after the first service reports success.
    $chain=tegh_parse_create_chain($q);
    if($chain){
        if($chain['firstAction']==='customer.create'){
            $action=tegh_action_get('customer.create');tegh_action_assert_permission($company,$action);$province=(string)$company['province'];
            $payload=['customer'=>['name'=>$chain['name'],'province'=>$province],'followUpAction'=>'invoice.create'];
            $preview=['title'=>'Create Customer','name'=>$chain['name'],'province'=>$province,'financialCommit'=>false,'followUp'=>'Create customer invoice after successful customer creation'];
            $out=tegh_prepare_confirmation($user,$company,'customer.create',$q,$payload,$preview);$out['navigation']='customers';$out['performance']=$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out,201);
        }
        $action=tegh_action_get('vendor.create');tegh_action_assert_permission($company,$action);
        $payload=['vendor'=>['name'=>$chain['name'],'currency'=>(string)$company['currency'],'defaultTermsDays'=>30],'followUpAction'=>'bill.create','followUpAmountCents'=>$chain['amountCents']??null];
        $preview=['title'=>'Create Vendor','name'=>$chain['name'],'currency'=>(string)$company['currency'],'defaultTermsDays'=>30,'financialCommit'=>false,'followUp'=>'Prepare vendor invoice after successful vendor creation'];
        $out=tegh_prepare_confirmation($user,$company,'vendor.create',$q,$payload,$preview);$out['navigation']='vendors';$out['performance']=$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out,201);
    }

    // Most-specific application action wins before generic customer/vendor name extraction.
    if(($explicit['actionId']??'')==='invoice.create'){
        $action=tegh_action_get('invoice.create');tegh_action_assert_permission($company,$action);$out=tegh_start_invoice_task($user,$company,$q);$out['performance']=$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out);
    }
    if(($explicit['actionId']??'')==='bill.create'){
        $action=tegh_action_get('bill.create');tegh_action_assert_permission($company,$action);$out=tegh_start_bill_task($user,$company,$q);$out['performance']=$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out);
    }

    if(($explicit['actionId']??'')==='customer.create'){
        $action=tegh_action_get('customer.create');tegh_action_assert_permission($company,$action);$customerName=tegh_extract_create_name($q,'customer');
        if($customerName===null){$task=tegh_agent_task($user,$company,'customer.create',$q,'collecting_customer',[],['name']);$out=tegh_needs_input_response($task,'customer.create','What is the customer name?');$out['performance']=$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out);}
        $province=(string)$company['province'];$payload=['customer'=>['name'=>$customerName,'province'=>$province]];$preview=['title'=>'Create Customer','name'=>$customerName,'province'=>$province,'financialCommit'=>false];$out=tegh_prepare_confirmation($user,$company,'customer.create',$q,$payload,$preview);$out['navigation']='customers';$out['performance']=$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out,201);
    }
    if(($explicit['actionId']??'')==='vendor.create'){
        $action=tegh_action_get('vendor.create');tegh_action_assert_permission($company,$action);$vendorName=tegh_extract_create_name($q,'vendor');
        if($vendorName===null){$task=tegh_agent_task($user,$company,'vendor.create',$q,'collecting_vendor',[],['name']);$out=tegh_needs_input_response($task,'vendor.create','What is the vendor name?');$out['performance']=$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out);}
        $payload=['vendor'=>['name'=>$vendorName,'currency'=>(string)$company['currency'],'defaultTermsDays'=>30]];$preview=['title'=>'Create Vendor','name'=>$vendorName,'currency'=>(string)$company['currency'],'defaultTermsDays'=>30,'financialCommit'=>false];$out=tegh_prepare_confirmation($user,$company,'vendor.create',$q,$payload,$preview);$out['navigation']='vendors';$out['performance']=$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out,201);
    }

    // Company-scoped customer/vendor find and balance reads. These are source-record reads, not model-calculated financial statements.
    if(preg_match('/\bcustomer\s+balance(?:s)?(?:\s+(?:for|of))?\s*(.*)$/i',$q,$m)){
        $query=trim((string)$m[1]," .?\t\n\r\0\x0B\"");$action=tegh_action_get('customer.balance');tegh_action_assert_permission($company,$action);$rows=tegh_party_lookup($company,'customer',$query,true);json_response(['recognized'=>true,'kind'=>'party_results','actionId'=>'customer.balance','partyType'=>'customer','query'=>$query,'rows'=>$rows,'currency'=>(string)$company['currency'],'message'=>count($rows).' customer record(s) found.']);
    }
    if(preg_match('/\bvendor\s+balance(?:s)?(?:\s+(?:for|of))?\s*(.*)$/i',$q,$m)){
        $query=trim((string)$m[1]," .?\t\n\r\0\x0B\"");$action=tegh_action_get('vendor.balance');tegh_action_assert_permission($company,$action);$rows=tegh_party_lookup($company,'vendor',$query,true);json_response(['recognized'=>true,'kind'=>'party_results','actionId'=>'vendor.balance','partyType'=>'vendor','query'=>$query,'rows'=>$rows,'currency'=>(string)$company['currency'],'message'=>count($rows).' vendor record(s) found.']);
    }
    if(preg_match('/\b(?:find|view|show)\s+(?:a\s+|the\s+)?(customer|vendor)(?:\s+named|\s+called)?\s+(.+?)[.!?]?$/i',$q,$m)){
        $kind=mb_strtolower($m[1]);$query=trim($m[2]," .?\t\n\r\0\x0B\"");$actionId=$kind==='customer'?'customer.find':'vendor.find';$action=tegh_action_get($actionId);tegh_action_assert_permission($company,$action);$rows=tegh_party_lookup($company,$kind,$query,false);json_response(['recognized'=>true,'kind'=>'party_results','actionId'=>$actionId,'partyType'=>$kind,'query'=>$query,'rows'=>$rows,'currency'=>(string)$company['currency'],'message'=>count($rows).' '.($kind==='customer'?'customer':'vendor').' record(s) found.']);
    }
    if(preg_match('/\bedit\s+(?:a\s+|the\s+)?(customer|vendor)(?:\s+named|\s+called)?\s+(.+?)[.!?]?$/i',$q,$m)){
        $kind=mb_strtolower($m[1]);$query=trim($m[2]," .?\t\n\r\0\x0B\"");$actionId=$kind==='customer'?'customer.edit':'vendor.edit';$action=tegh_action_get($actionId);tegh_action_assert_permission($company,$action);$rows=tegh_party_lookup($company,$kind,$query,false);$task=tegh_agent_task($user,$company,$actionId,$q,'existing_workflow',['query'=>$query],[]);json_response(['recognized'=>true,'kind'=>'workflow','actionId'=>$actionId,'navigation'=>$kind==='customer'?'customers':'vendors','task'=>$task,'message'=>count($rows)===1?'Opening the existing protected edit workflow for the matching record.':'Opening the existing register; use the matching record there so Tegh preserves its edit/lock protections.','matches'=>$rows]);
    }

    // A regression-gated learned behavior may only redirect to a registered safe
    // read/navigation/prepare action. It never invokes a financial/destructive service.
    if($behaviorAction!==null){$learnedAction=tegh_action_get($behaviorAction);tegh_action_assert_permission($company,$learnedAction);if(!empty($learnedAction['financial_commit'])||!empty($learnedAction['destructive']))fail('A learned behavior attempted to target a protected action and was blocked.',409,'learned_behavior_protected');if(str_starts_with($behaviorAction,'report.')){$parsedLearned=tegh_parse_report($q);$period=is_array($parsedLearned)?($parsedLearned['period']??[]):[];tegh_agent_cancel_active($user,$company,'learned_report_requested');tegh_ai_record_event($user,$company,'report_opened',['question'=>$q,'module'=>'Reports','actionId'=>$behaviorAction,'intentCategory'=>'Report','signal'=>1,'outcome'=>'completed','confidenceBps'=>(int)$interpretation['confidenceBps'],'source'=>'learned_behavior','period'=>$period]);json_response(['recognized'=>true,'kind'=>'report','report'=>['actionId'=>$behaviorAction,'period'=>$period,'route'=>$learnedAction['execution_service'],'title'=>$learnedAction['name']],'learnedBehavior'=>true,'agentPlan'=>$agentPlan]);}if((string)$learnedAction['action_type']==='navigation'){tegh_agent_cancel_active($user,$company,'learned_navigation_requested');tegh_ai_record_event($user,$company,'navigation_used',['question'=>$q,'module'=>(string)$learnedAction['module'],'actionId'=>$behaviorAction,'intentCategory'=>'Navigation','signal'=>1,'outcome'=>'completed','confidenceBps'=>(int)$interpretation['confidenceBps'],'source'=>'learned_behavior']);json_response(['recognized'=>true,'kind'=>'navigation','actionId'=>$behaviorAction,'navigation'=>$learnedAction['route'],'learnedBehavior'=>true,'message'=>'Opening '.$learnedAction['name'].'.','agentPlan'=>$agentPlan]);}}

    // Reports use existing report endpoints; the companion only hosts the returned view.
    $report=tegh_parse_report($q);
    if($report){$action=tegh_action_get($report['actionId']);tegh_action_assert_permission($company,$action);tegh_agent_cancel_active($user,$company,'report_requested');$report['route']=$action['execution_service'];$report['title']=$action['name'];if(($report['actionId']??'')==='report.account_ledger' && !empty($report['accountName'])){$resolved=tegh_resolve_account($company,(string)$report['accountName']);if($resolved['status']==='resolved')$report['account']=$resolved['account'];else{$report['accountCandidates']=$resolved['candidates'];$report['needsAccount']=true;}}tegh_ai_record_event($user,$company,'report_opened',['question'=>$q,'module'=>'Reports','actionId'=>(string)$report['actionId'],'intentCategory'=>'Report','signal'=>1,'outcome'=>'completed','confidenceBps'=>(int)$interpretation['confidenceBps'],'period'=>$report['period']??[]]);json_response(['recognized'=>true,'kind'=>'report','report'=>$report,'performance'=>$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);}

    // Import uses the existing uploader. Task state survives the file picker and company is bound server-side.
    if($universalActionId==='nav.bank_import'||preg_match('/\bimport\b.*\b(bank )?statement\b/i',$q)){$action=tegh_action_get('nav.bank_import');tegh_action_assert_permission($company,$action);$task=tegh_agent_task($user,$company,'nav.bank_import',$q,'waiting_for_file',[],['statementFile']);json_response(['recognized'=>true,'kind'=>'file_upload','actionId'=>'nav.bank_import','navigation'=>'bank-import','task'=>$task,'message'=>'Choose the statement file.','performance'=>$perf+['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);}

    // Genuine GL request starts/continues Journal Copilot only when no newer explicit intent replaces it.
    if($universalActionId==='journal.prepare'||preg_match('/\b(post|prepare|make|create)\b.*\b(gl|general ledger|journal)\b|\bjournal entr/i',$q)){$action=tegh_action_get('journal.prepare');tegh_action_assert_permission($company,$action);$businessEvent=trim((string)($universalDecision['slots']['businessEvent']??''));$collected=$businessEvent!==''?['businessEvent'=>$businessEvent]:[];$missing=$businessEvent!==''?[]:['businessEvent'];$task=tegh_agent_task($user,$company,'journal.prepare',$q,$businessEvent!==''?'ready_to_compose':'collecting_business_event',$collected,$missing);if($businessEvent!=='')json_response(['recognized'=>true,'kind'=>'journal','journalMode'=>'compose','taskId'=>(string)$task['id'],'businessEvent'=>$businessEvent,'navigation'=>'manual-journal','performance'=>$perf+['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);json_response(['recognized'=>true,'kind'=>'journal','journalMode'=>'start','task'=>$task,'navigation'=>'manual-journal','message'=>'What happened in the business? Describe it in ordinary language; Tegh will infer the accounts from this company’s Chart of Accounts.','performance'=>$perf+['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);}
    if($active && (string)$active['action_id']==='journal.prepare' && (string)$active['status']==='waiting_input')json_response(['recognized'=>true,'kind'=>'journal','journalMode'=>'compose','taskId'=>(string)$active['id'],'businessEvent'=>$q,'performance'=>$perf+['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);

    // Bank result-set operations and natural query/refinement.
    // Selection changes the selection on the current list; it does not silently replace the list itself.
    if($resultSetId!=='' && ($universalActionId==='bank.transactions.select'||preg_match('/\bselect\b|remove .*selection/i',$q))){
        $result=tegh_agent_result_get($resultSetId,$user,$company);$rows=tegh_bank_query_rows($company,$result['query']??[]);$ids=$result['selectedIds']??[];
        if(preg_match('/select all/i',$q))$ids=array_map(static fn(array $r):string=>(string)$r['id'],$rows);
        elseif(preg_match('/(?:only\s+)?select.*debits/i',$q))$ids=array_map(static fn(array $r):string=>(string)$r['id'],array_values(array_filter($rows,static fn(array $r):bool=>(int)$r['amount_cents']<0)));
        elseif(preg_match('/select.*credits/i',$q))$ids=array_map(static fn(array $r):string=>(string)$r['id'],array_values(array_filter($rows,static fn(array $r):bool=>(int)$r['amount_cents']>0)));
        elseif(preg_match('/select.*under\s*\$?([0-9,.]+)/i',$q,$m)){$max=(int)round((float)str_replace(',','',$m[1])*100);$ids=array_map(static fn(array $r):string=>(string)$r['id'],array_values(array_filter($rows,static fn(array $r):bool=>abs((int)$r['amount_cents'])<$max)));}
        elseif(preg_match('/select\s+(?:the\s+)?(?:five|5)\s+(.+?)\s+transactions?/i',$q,$m)){$needle=mb_strtolower(trim($m[1]));$matches=array_values(array_filter($rows,static fn(array $r):bool=>str_contains(mb_strtolower((string)$r['description']),$needle)));$ids=array_slice(array_map(static fn(array $r):string=>(string)$r['id'],$matches),0,5);}
        if(preg_match('/remove.*credits/i',$q)){$ids=array_values(array_filter($ids,function(string $id)use($rows):bool{foreach($rows as $r)if((string)$r['id']===$id)return (int)$r['amount_cents']<0;return false;}));}
        $saved=tegh_agent_result_save($user,$company,$result['query']??[],$rows,$resultSetId,$ids);
        json_response(['recognized'=>true,'kind'=>'transactions','resultSet'=>$saved,'rows'=>$rows,'summary'=>array_merge(tegh_bank_summary($rows),['selectionCount'=>count($ids)]),'message'=>count($ids).' transaction(s) selected.']);
    }

    // Direct bank actions may describe the target set and the action in one sentence.
    // Build that result set first, server-side, so a subsequent approval is still bound to explicit record identities.
    $bankMutationIds=['bank.transactions.categorize','bank.transactions.post','bank.transactions.exclude','bank.transactions.delete'];
    $isBankMutation=in_array($universalActionId,$bankMutationIds,true)||(bool)preg_match('/\b(categorize|categorise|post|exclude|delete)\b|\bremove\b.*\b(?:from review|transactions?)\b/i',$q);
    if($resultSetId==='' && $isBankMutation && preg_match('/\b(transactions?|debits?|credits?)\b|service charge|tim hortons|amazon|stripe/i',$q)){
        $action=tegh_action_get('bank.transactions.query');tegh_action_assert_permission($company,$action);
        $filters=tegh_parse_bank_filters($q,[]);$qs=microtime(true);$rows=tegh_bank_query_rows($company,$filters);$perf['transactionQueryMs']=(int)round((microtime(true)-$qs)*1000);
        $saved=tegh_agent_result_save($user,$company,$filters,$rows);$resultSetId=$saved['id'];
    }

    $isBankQuery=$universalActionId==='bank.transactions.query'||(preg_match('/\btransactions?\b/i',$q) && !$isBankMutation && preg_match('/\b(show|find|list|only|debits?|credits?|pending|unposted|posted|duplicate|uncategorized|service charge|tim hortons|amazon|stripe)\b/i',$q));
    $isRefine=$resultSetId!=='' && !$isBankMutation && !preg_match('/\bselect\b|remove .*selection/i',$q) && preg_match('/\b(only|debits?|credits?|under|over|between|not posted|pending|posted)\b/i',$q);
    if($isBankQuery || $isRefine){
        $action=tegh_action_get('bank.transactions.query');tegh_action_assert_permission($company,$action);$base=[];$existing=null;
        if($resultSetId!==''){$existing=tegh_agent_result_get($resultSetId,$user,$company);$base=$existing['query']??[];}
        $filters=tegh_parse_bank_filters($q,$base);$qs=microtime(true);$rows=tegh_bank_query_rows($company,$filters);$perf['transactionQueryMs']=(int)round((microtime(true)-$qs)*1000);
        $saved=tegh_agent_result_save($user,$company,$filters,$rows,$resultSetId?:null,$existing['selectedIds']??[]);tegh_agent_task($user,$company,'bank.transactions.query',$q,'showing_results',[],[],$saved['id']);
        json_response(['recognized'=>true,'kind'=>'transactions','actionId'=>'bank.transactions.query','resultSet'=>$saved,'rows'=>$rows,'summary'=>array_merge(tegh_bank_summary($rows),['selectionCount'=>count($saved['selectedIds'])]),'filters'=>$filters,'performance'=>$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);
    }

    // Protected exclude/delete actions are also bound to the current result/selection and require an explicit scoped approval.
    if($resultSetId!=='' && (in_array($universalActionId,['bank.transactions.exclude','bank.transactions.delete'],true)||preg_match('/\b(exclude|delete)\b|\bremove\b.*\b(?:from review|transactions?)\b/i',$q))){
        $result=tegh_agent_result_get($resultSetId,$user,$company);$targets=tegh_extract_target_ids($result);if(!$targets)fail('There are no current transactions in this result set.',409,'empty_result_set');
        $kind=$universalActionId==='bank.transactions.delete'||($universalActionId===''&&preg_match('/\bdelete\b/i',$q))?'delete':'exclude';$actionId=$kind==='delete'?'bank.transactions.delete':'bank.transactions.exclude';$action=tegh_action_get($actionId);tegh_action_assert_permission($company,$action);
        $fresh=tegh_fresh_bank_destructive_targets($company,$targets,$kind);if(!$fresh['eligible'])fail('None of the current transactions is eligible for that action.',409,'no_eligible_transactions');
        $payload=['resultSetId'=>$resultSetId,'targetIds'=>$fresh['eligible']];
        $preview=['title'=>$kind==='delete'?'Delete Imported Transactions':'Exclude Transactions','count'=>count($fresh['eligible']),'eligible'=>count($fresh['eligible']),'notEligible'=>count($fresh['ineligible']),'ineligible'=>$fresh['ineligible'],'destructive'=>true];
        $out=tegh_prepare_confirmation($user,$company,$actionId,$q,$payload,$preview,$resultSetId,is_array($universalDecision)?$universalDecision:[]);$out['performance']=$perf+['totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out,201);
    }

    // Categorize/post "these" is bound to the current server-side result set.
    if($resultSetId!=='' && $isBankMutation){
        $result=tegh_agent_result_get($resultSetId,$user,$company);$targets=tegh_extract_target_ids($result);if(!$targets)fail('There are no current transactions in this result set.',409,'empty_result_set');
        $accountName='';if(preg_match('/\b(?:to|as)\s+(.+?)(?=\s+and\s+apply|\s+but\s+don.t\s+post|\s+and\s+then|[.!?]|$)/i',$q,$m))$accountName=trim($m[1]);
        $direction=null;$fresh=tegh_fresh_bank_targets($company,$targets);foreach($fresh['rows'] as $r){$d=(int)$r['amount_cents']<0?'debit':'credit';$direction=$direction===null?$d:($direction===$d?$d:null);}
        $accountId='';$account=null;if($accountName!==''){$resolved=tegh_resolve_account($company,$accountName,$direction);if($resolved['status']!=='resolved')json_response(['recognized'=>true,'kind'=>'needs_account','actionId'=>str_contains($l,'post')?'bank.transactions.post':'bank.transactions.categorize','accountQuery'=>$accountName,'candidates'=>$resolved['candidates'],'message'=>$resolved['status']==='missing'?'No suitable active account matches that name.':'More than one suitable account matches. Choose one.']);$account=$resolved['account'];$accountId=(string)$account['id'];}
        $explicitNoTax=(bool)preg_match('/\b(no tax|without tax|do not apply tax|don.t apply tax)\b/i',$q);
        $applyGstHst=(bool)preg_match('/\b(hst|gst|gst\/hst|apply tax)\b/i',$q) && !$explicitNoTax;$applyPst=(bool)preg_match('/\bpst\b/i',$q) && !$explicitNoTax;$taxExplicit=$explicitNoTax||$applyGstHst||$applyPst;
        if($universalActionId==='bank.transactions.categorize'||($universalActionId===''&&preg_match('/\bcategor(?:ize|ise)\b/i',$q) && !preg_match('/\bpost\b/i',$q))){
            if($accountId==='')fail('Tell Tegh which active Chart of Accounts category to use.',409,'account_required');
            if($applyPst)fail('PST-only bulk categorization is not supported by the existing Bank Review categorization service. Use Bank Review for that tax treatment.',409,'tax_treatment_not_supported');
            $eligible=$fresh['eligible'];if(!$eligible)fail('None of the current transactions is eligible to categorize.',409,'no_eligible_transactions');
            $taxLabel=$taxExplicit?($applyGstHst?'Apply GST/HST':'No tax'):'Preserve each transaction’s current tax treatment';
            $payload=['resultSetId'=>$resultSetId,'targetIds'=>$eligible,'accountId'=>$accountId,'account'=>$account,'taxExplicit'=>$taxExplicit,'applyGstHst'=>$applyGstHst];
            $preview=['title'=>'Prepared Bank Categorization','count'=>count($eligible),'account'=>$account,'tax'=>$taxLabel,'eligible'=>count($eligible),'notEligible'=>count($fresh['ineligible']),'ineligible'=>$fresh['ineligible'],'posted'=>false];
            $out=tegh_prepare_confirmation($user,$company,'bank.transactions.categorize',$q,$payload,$preview,$resultSetId,is_array($universalDecision)?$universalDecision:[]);$out['performance']=$perf+['totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out,201);
        }
        // Post: if no account name, existing per-row decided accounts are used.
        $eligible=$fresh['eligible'];if(!$eligible)fail('None of the current transactions is eligible to post.',409,'no_eligible_transactions');
        if($accountId===''){foreach($fresh['rows'] as $r)if(in_array((string)$r['id'],$eligible,true) && trim((string)($r['decided_account_id']??''))==='')fail('These transactions need a category before they can be posted.',409,'posting_category_required');}
        $sumRows=array_values(array_filter($fresh['rows'],static fn(array $r):bool=>in_array((string)$r['id'],$eligible,true)));$summary=tegh_bank_summary($sumRows);
        $followUp=null;if(preg_match('/\band then\b(.+)$/i',$q,$m))$followUp=tegh_parse_report(trim($m[1]));
        $payload=['resultSetId'=>$resultSetId,'targetIds'=>$eligible,'accountId'=>$accountId,'taxExplicit'=>$taxExplicit,'applyGstHst'=>$applyGstHst,'applyPst'=>$applyPst,'followUp'=>$followUp];
        $taxPreview=$taxExplicit?($explicitNoTax?'No tax requested':'According to current company tax settings'):'Existing stored transaction tax treatment; company rules will be revalidated at posting';
        $impact=tegh_ai_bank_impact_preview($company,$sumRows,$accountId,$taxExplicit,$applyGstHst,$applyPst);if(empty($impact['balanced']))fail('The proposed accounting impact is not balanced. Nothing was prepared for posting.',409,'preview_unbalanced');
        $preview=['title'=>'Ready To Post','count'=>count($eligible),'account'=>$account,'totalDebitsCents'=>$summary['totalDebitsCents'],'totalCreditsCents'=>$summary['totalCreditsCents'],'tax'=>$taxPreview,'accountingImpact'=>$impact,'eligible'=>count($eligible),'notEligible'=>count($fresh['ineligible']),'ineligible'=>$fresh['ineligible']];
        $out=tegh_prepare_confirmation($user,$company,'bank.transactions.post',$q,$payload,$preview,$resultSetId,is_array($universalDecision)?$universalDecision:[]);$out['performance']=$perf+['totalMs'=>(int)round((microtime(true)-$t0)*1000)];json_response($out,201);
    }

    // Navigation is deliberately after execute intents so "go to Receivables and create..." completes the requested objective.
    $nav=tegh_navigation_intent($q);if($nav){$action=tegh_action_get($nav['actionId']);tegh_action_assert_permission($company,$action);tegh_agent_cancel_active($user,$company,'navigation_requested');tegh_ai_record_event($user,$company,'navigation_used',['question'=>$q,'module'=>(string)$action['module'],'actionId'=>(string)$nav['actionId'],'intentCategory'=>'Navigation','signal'=>1,'outcome'=>'completed','confidenceBps'=>(int)$interpretation['confidenceBps']]);json_response(['recognized'=>true,'kind'=>'navigation','actionId'=>$nav['actionId'],'navigation'=>$action['route'],'message'=>'Opening '.$action['name'].'.','performance'=>$perf+['totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);}

    // Explicit invoice/bill/payment/payroll requests navigate into the existing validated workflow rather than inventing a second engine.
    $workflowMap=[
        '/\b(create|prepare).*customer invoice|\binvoice for\b/i'=>['invoice.create','customer-invoice'],
        '/\b(create|prepare).*vendor invoice|\bcreate.*bill\b/i'=>['bill.create','bills'],
        '/\brecord customer payment\b/i'=>['payment.customer','customer-payments'],
        '/\b(record|make).*vendor payment\b/i'=>['payment.vendor','vendor-payments'],
        '/\brun payroll calculation|calculate payroll\b/i'=>['payroll.calculate','payroll'],
        // Do not capture analytical references such as "Analyze this bank
        // reconciliation". Only an actual reconcile/complete instruction is a
        // protected workflow request; read-only analysis must fall through to
        // agent/ask where deterministic reconciliation facts are available.
        '/\b(?:reconcile\s+(?:the\s+)?bank|(?:finish|complete)\s+(?:the\s+)?(?:bank\s+)?reconciliation|reconcile\s+(?:the\s+)?(?:bank\s+)?reconciliation)\b/i'=>['bank.reconcile','bank-reconciliation'],
        '/\b(?:finalize|finalise|complete)\s+payroll\b/i'=>['payroll.finalize','payroll'],
    ];
    $routedWorkflows=[
        'payment.customer'=>'customer-payments','payment.vendor'=>'vendor-payments',
        'payroll.calculate'=>'payroll','bank.reconcile'=>'bank-reconciliation','payroll.finalize'=>'payroll',
    ];
    if(isset($routedWorkflows[$universalActionId])){$action=tegh_action_get($universalActionId);tegh_action_assert_permission($company,$action);$task=tegh_agent_task($user,$company,$universalActionId,$q,'existing_workflow',(array)($universalDecision['slots']??[]),(array)($universalDecision['missing_inputs']??[]));json_response(['recognized'=>true,'kind'=>'workflow','actionId'=>$universalActionId,'executionClass'=>tegh_action_execution_class($action),'navigation'=>$routedWorkflows[$universalActionId],'prefill'=>(array)($universalDecision['slots']??[]),'task'=>$task,'message'=>tegh_action_execution_class($action)==='authorized'?'Opening the existing protected Tegh workflow. Nothing has been committed; the final accounting action still requires one explicit confirmation.':'Opening the existing Tegh workflow with the interpreted details prepared for review.','agentPlan'=>$agentPlan,'routing'=>['path'=>$universalDecision['path']??'universal','confidence'=>(float)($universalDecision['confidence']??0),'registryVersion'=>$universalDecision['registryVersion']??TEGH_ROUTER_VERSION]]);}
    foreach($workflowMap as $regex=>$data)if(preg_match($regex,$q)){$action=tegh_action_get($data[0]);tegh_action_assert_permission($company,$action);$task=tegh_agent_task($user,$company,$data[0],$q,'existing_workflow',[],[]);json_response(['recognized'=>true,'kind'=>'workflow','actionId'=>$data[0],'navigation'=>$data[1],'task'=>$task,'message'=>(!empty($action['financial_commit'])?'Opening the existing protected Tegh workflow. No financial action has been committed; its normal review and confirmation controls still apply.':'Opening the existing Tegh workflow so the same validations and accounting service are used.'),'agentPlan'=>$agentPlan]);}

    // Every permission-filtered registry action has a deterministic terminal
    // route. Specialized lanes above may query or prepare more context; this
    // fallback still reaches the existing application workflow and never treats
    // a model-selected action ID as executable authority.
    if($universalActionId!==''&&isset(tegh_action_registry()[$universalActionId])){
        $action=tegh_action_get($universalActionId);tegh_action_assert_permission($company,$action);$class=tegh_action_execution_class($action);
        $slots=(array)($universalDecision['slots']??[]);$missing=(array)($universalDecision['missing_inputs']??[]);
        if($missing){$task=tegh_agent_task($user,$company,$universalActionId,$q,'collecting_inputs',$slots,$missing,$resultSetId?:null);$out=tegh_needs_input_response($task,$universalActionId,'What '.implode(' and ',array_map('strval',$missing)).' should Tegh use?',(string)$action['route'],['prefill'=>$slots]);$out['executionClass']=$class;$out['routing']=['path'=>$universalDecision['path']??'universal','confidence'=>(float)($universalDecision['confidence']??0),'registryVersion'=>$universalDecision['registryVersion']??TEGH_ROUTER_VERSION];json_response($out);}
        $task=$class==='immediate'?null:tegh_agent_task($user,$company,$universalActionId,$q,'existing_workflow',$slots,[],$resultSetId?:null);
        json_response(['recognized'=>true,'kind'=>$class==='immediate'?'navigation':'workflow','actionId'=>$universalActionId,'executionClass'=>$class,'navigation'=>(string)$action['route'],'prefill'=>$slots,'task'=>$task,'message'=>$class==='authorized'?'Opening the existing protected Tegh workflow. Nothing has been committed; the final accounting action still requires one explicit confirmation.':($class==='prepared'?'Opening the existing Tegh workflow with the interpreted details prepared for review. Nothing has been saved.':'Opening '.$action['name'].'.'),'agentPlan'=>$agentPlan,'routing'=>['path'=>$universalDecision['path']??'universal','confidence'=>(float)($universalDecision['confidence']??0),'registryVersion'=>$universalDecision['registryVersion']??TEGH_ROUTER_VERSION],'performance'=>$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);
    }

    // Final resolver is intentionally restricted to safe actions. Medium/low
    // confidence returns candidates instead of guessing, and protected actions
    // are excluded by the scorer regardless of lexical similarity.
    $safe=tegh_ai_resolve_safe_action($company,$q,$interpretation);
    if($safe['status']==='resolved'&&($interpretation['confidenceBps']??0)>=6000){$candidate=$safe['top'];$action=tegh_action_get((string)$candidate['actionId']);tegh_action_assert_permission($company,$action);tegh_agent_cancel_active($user,$company,'safe_action_resolved');tegh_ai_record_event($user,$company,'navigation_used',['question'=>$q,'module'=>(string)$action['module'],'actionId'=>(string)$candidate['actionId'],'intentCategory'=>'Navigation','signal'=>1,'outcome'=>'completed','confidenceBps'=>(int)$interpretation['confidenceBps'],'source'=>'scored_safe_action']);json_response(['recognized'=>true,'kind'=>'navigation','actionId'=>$candidate['actionId'],'navigation'=>$candidate['route'],'message'=>'Opening '.$candidate['name'].'.','confidence'=>$interpretation['confidence'],'confidenceBps'=>$interpretation['confidenceBps'],'agentPlan'=>$agentPlan,'resolver'=>'scored_safe_action']);}
    if($safe['status']==='ambiguous')tegh_ai_record_event($user,$company,'clarification',['question'=>$q,'module'=>'Agent','intentCategory'=>(string)$interpretation['primary'],'signal'=>0,'outcome'=>'needs_clarification','confidenceBps'=>(int)$interpretation['confidenceBps'],'candidateActionIds'=>array_column($safe['candidates'],'actionId')]);
    json_response(['recognized'=>false,'kind'=>'guidance','confidence'=>$interpretation['confidence'],'candidates'=>$safe['candidates'],'agentPlan'=>$agentPlan,'performance'=>$perf+['actionResolutionMs'=>(int)round((microtime(true)-$p)*1000),'totalMs'=>(int)round((microtime(true)-$t0)*1000)]]);
}

function tegh_agent_file_task_complete(array $user,array $company): never
{
    require_method('POST');require_csrf();$input=request_json();$taskId=clean_text($input['taskId']??'','Task',64);$batchId=optional_text($input['batchId']??null,64);
    $stmt=db()->prepare("SELECT id FROM ai_agent_tasks WHERE id=? AND company_id=? AND user_id=? AND action_id='nav.bank_import' AND status IN ('active','waiting_input') LIMIT 1");$stmt->execute([$taskId,(string)$company['id'],(string)$user['id']]);if(!$stmt->fetch())fail('That statement-import task is no longer active.',409,'task_stale');
    tegh_agent_task_update($taskId,$user,$company,['workflow_state'=>'import_completed','collected_json'=>tegh_json(['batchId'=>$batchId]),'missing_json'=>'[]','status'=>'completed']);json_response(['ok'=>true,'taskId'=>$taskId,'batchId'=>$batchId]);
}
