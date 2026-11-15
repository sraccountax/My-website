<?php
declare(strict_types=1);

/**
 * R184: Report Builder. Users build their own reports from a fixed catalogue of data areas and fields:
 * rows to group by, columns side by side (by period or by any field), values to add up, filters and sorting.
 * Reports are read-only. Queries are assembled only from catalogue expressions; every value a user supplies is
 * bound as a parameter, and every query is limited to the company in use. Results are sealed through the standard
 * professional-output pipeline (audit entry, reference, hash), exactly like the built-in reports.
 *
 * Off by default: Site & Release Settings › Release › Report Builder (off / platform owner only / everyone).
 * Saved reports live in `report_builder_reports`, created the first time a report is saved.
 */

const TEGH_RB_VERSION = '5990.r184';
const TEGH_RB_MAX_ROW_FIELDS = 3;
const TEGH_RB_MAX_VALUES = 4;
const TEGH_RB_MAX_FILTERS = 16;      // 12 on the form, plus up to 4 added when drilling into a figure
const TEGH_RB_FORM_FILTERS = 12;
const TEGH_RB_MAX_LIST_FIELDS = 20;
const TEGH_RB_MAX_BUCKETS = 60;
const TEGH_RB_MAX_GROUPS = 5000;

function tegh_rb_mode(): string
{
    if (!function_exists('r174_group')) return 'off';
    $mode = (string)(r174_group('release')['value']['reportBuilder'] ?? 'off');
    return in_array($mode, ['off', 'owner', 'everyone'], true) ? $mode : 'off';
}

function tegh_rb_available_for(?array $user): bool
{
    $mode = tegh_rb_mode();
    if ($mode === 'everyone') return true;
    if ($mode === 'owner' && $user !== null && function_exists('platform_role_for_user')) return platform_role_for_user((string)$user['id']) === 'platform_owner';
    return false;
}

function tegh_rb_require_available(array $user): void
{
    if (!tegh_rb_available_for($user)) fail('Report Builder is not switched on for this account. A platform owner can switch it on in Site & Release Settings.', 403, 'report_builder_off');
}

/**
 * The catalogue. Each data area:
 *  label, desc, perms (all required), from (SQL; every "?" in it is the company id), company (column), date (expression
 *  or null), where (fixed condition or ''), fields: key => [label, SQL expression, type, kind]
 *  type: text | date | money (integer cents) | integer | quantity (thousandths) ; kind: dim | measure
 * Money is the company's base currency (carrying amounts), as in the built-in reports.
 */
function tegh_rb_sources(): array
{
    $party = static fn(string $alias, string $label) => [$label, "COALESCE($alias.name,'(none)')", 'text', 'dim'];
    return [
        'gl' => [
            'start' => ['rows' => ['account'], 'values' => ['debit', 'credit']],
            'label' => 'Transactions (General Ledger lines)',
            'desc' => 'Every posted journal line: the same entries as the Trial Balance and General Ledger.',
            'perms' => ['reports.view', 'journals.view'],
            'from' => 'journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id',
            'company' => 'je.company_id', 'date' => 'je.entry_date', 'where' => "je.status='posted'",
            'list' => ['date', 'account', 'sourceType', 'entryMemo', 'debit', 'credit'],
            'fields' => [
                'date' => ['Date', 'je.entry_date', 'date', 'dim'],
                'account' => ['Account', "CONCAT(a.code,' ',a.name)", 'text', 'dim'],
                'accountCode' => ['Account number', 'a.code', 'text', 'dim'],
                'accountType' => ['Account type', 'a.account_type', 'text', 'dim'],
                'reportingGroup' => ['Reporting group', "COALESCE(NULLIF(a.reporting_group,''),'(none)')", 'text', 'dim'],
                'gifi' => ['GIFI code', "COALESCE(NULLIF(a.gifi_code,''),'(none)')", 'text', 'dim'],
                'sourceType' => ['Entry type', 'je.source_type', 'text', 'dim'],
                'entryMemo' => ['Entry memo', "COALESCE(je.memo,'')", 'text', 'dim'],
                'lineMemo' => ['Line memo', "COALESCE(jl.memo,'')", 'text', 'dim'],
                'debit' => ['Debit', 'jl.debit_cents', 'money', 'measure'],
                'credit' => ['Credit', 'jl.credit_cents', 'money', 'measure'],
                'net' => ['Net (debit − credit)', 'jl.debit_cents-jl.credit_cents', 'money', 'measure'],
                'natural' => ['Amount (normal sign)', "CASE WHEN a.normal_balance='credit' THEN jl.credit_cents-jl.debit_cents ELSE jl.debit_cents-jl.credit_cents END", 'money', 'measure'],
            ],
        ],
        'invoices' => [
            'start' => ['rows' => ['customer'], 'values' => ['total']],
            'label' => 'Sales invoices',
            'desc' => 'One row per customer invoice, with its totals and open balance.',
            'perms' => ['reports.view', 'invoices.view'],
            'from' => 'invoices i LEFT JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id',
            'company' => 'i.company_id', 'date' => 'i.issue_date', 'where' => '',
            'list' => ['number', 'date', 'customer', 'status', 'total', 'balance'],
            'fields' => [
                'number' => ['Invoice number', 'i.number', 'text', 'dim'],
                'date' => ['Invoice date', 'i.issue_date', 'date', 'dim'],
                'dueDate' => ['Due date', 'i.due_date', 'date', 'dim'],
                'customer' => $party('c', 'Customer'),
                'customerCity' => ['Customer city', "COALESCE(NULLIF(c.city,''),'(none)')", 'text', 'dim'],
                'customerProvince' => ['Customer province', "COALESCE(NULLIF(c.province,''),'(none)')", 'text', 'dim'],
                'status' => ['Status', 'i.status', 'text', 'dim'],
                'currency' => ['Currency', 'i.currency', 'text', 'dim'],
                'subtotal' => ['Subtotal', 'i.subtotal_cents', 'money', 'measure'],
                'gstHst' => ['GST/HST', 'i.gst_hst_cents', 'money', 'measure'],
                'pst' => ['PST', 'i.pst_cents', 'money', 'measure'],
                'tax' => ['Total tax', 'i.tax_cents', 'money', 'measure'],
                'total' => ['Total', 'i.total_cents', 'money', 'measure'],
                'balance' => ['Open balance', 'i.balance_cents', 'money', 'measure'],
            ],
        ],
        'invoiceLines' => [
            'start' => ['rows' => ['product'], 'values' => ['amount']],
            'label' => 'Sales invoice lines',
            'desc' => 'One row per line on a customer invoice: product or service, income account, quantity, amount and tax.',
            'perms' => ['reports.view', 'invoices.view'],
            'from' => 'invoice_lines il JOIN invoices i ON i.id=il.invoice_id LEFT JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id LEFT JOIN products_services ps ON ps.id=il.product_service_id AND ps.company_id=i.company_id LEFT JOIN accounts a ON a.id=il.income_account_id AND a.company_id=i.company_id LEFT JOIN tax_codes tc ON tc.id=il.tax_code_id AND tc.company_id=i.company_id',
            'company' => 'i.company_id', 'date' => 'i.issue_date', 'where' => '',
            'list' => ['number', 'date', 'customer', 'product', 'description', 'quantity', 'amount', 'tax'],
            'fields' => [
                'number' => ['Invoice number', 'i.number', 'text', 'dim'],
                'date' => ['Invoice date', 'i.issue_date', 'date', 'dim'],
                'customer' => $party('c', 'Customer'),
                'status' => ['Invoice status', 'i.status', 'text', 'dim'],
                'product' => ['Product or service', "COALESCE(ps.name,'(none)')", 'text', 'dim'],
                'account' => ['Income account', "COALESCE(CONCAT(a.code,' ',a.name),'(none)')", 'text', 'dim'],
                'taxCode' => ['Tax code', "COALESCE(tc.code,'(none)')", 'text', 'dim'],
                'description' => ['Line description', "COALESCE(il.description,'')", 'text', 'dim'],
                'quantity' => ['Quantity', 'il.quantity_milli', 'quantity', 'measure'],
                'amount' => ['Amount before tax', 'il.amount_cents', 'money', 'measure'],
                'tax' => ['Tax', 'il.tax_cents', 'money', 'measure'],
                'gross' => ['Amount with tax', 'il.amount_cents+il.tax_cents', 'money', 'measure'],
            ],
        ],
        'bills' => [
            'start' => ['rows' => ['vendor'], 'values' => ['total']],
            'label' => 'Bills (vendor invoices)',
            'desc' => 'One row per vendor invoice, with its totals and open balance.',
            'perms' => ['reports.view', 'bills.view'],
            'from' => 'bills b LEFT JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id LEFT JOIN accounts a ON a.id=b.category_account_id AND a.company_id=b.company_id',
            'company' => 'b.company_id', 'date' => 'b.bill_date', 'where' => '',
            'list' => ['number', 'date', 'vendor', 'status', 'total', 'balance'],
            'fields' => [
                'number' => ['Bill number', "COALESCE(b.number,'')", 'text', 'dim'],
                'date' => ['Bill date', 'b.bill_date', 'date', 'dim'],
                'dueDate' => ['Due date', 'b.due_date', 'date', 'dim'],
                'vendor' => $party('v', 'Vendor'),
                'category' => ['Expense account', "COALESCE(CONCAT(a.code,' ',a.name),'(split)')", 'text', 'dim'],
                'status' => ['Status', 'b.status', 'text', 'dim'],
                'currency' => ['Currency', 'b.currency', 'text', 'dim'],
                'subtotal' => ['Subtotal', 'b.subtotal_cents', 'money', 'measure'],
                'gstHst' => ['GST/HST', 'b.gst_hst_cents', 'money', 'measure'],
                'pst' => ['PST', 'b.pst_cents', 'money', 'measure'],
                'tax' => ['Total tax', 'b.tax_cents', 'money', 'measure'],
                'total' => ['Total', 'b.total_cents', 'money', 'measure'],
                'balance' => ['Open balance', 'b.balance_cents', 'money', 'measure'],
            ],
        ],
        'billLines' => [
            'start' => ['rows' => ['account'], 'values' => ['amount']],
            'label' => 'Bill lines',
            'desc' => 'One row per line on a vendor invoice: account, product, amount and tax.',
            'perms' => ['reports.view', 'bills.view'],
            'from' => 'bill_lines bl JOIN bills b ON b.id=bl.bill_id AND b.company_id=bl.company_id LEFT JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id LEFT JOIN products_services ps ON ps.id=bl.product_service_id AND ps.company_id=b.company_id LEFT JOIN accounts a ON a.id=bl.account_id AND a.company_id=b.company_id LEFT JOIN tax_codes tc ON tc.id=bl.tax_code_id AND tc.company_id=b.company_id',
            'company' => 'b.company_id', 'date' => 'b.bill_date', 'where' => '',
            'list' => ['number', 'date', 'vendor', 'account', 'description', 'amount', 'tax'],
            'fields' => [
                'number' => ['Bill number', "COALESCE(b.number,'')", 'text', 'dim'],
                'date' => ['Bill date', 'b.bill_date', 'date', 'dim'],
                'vendor' => $party('v', 'Vendor'),
                'status' => ['Bill status', 'b.status', 'text', 'dim'],
                'account' => ['Account', "COALESCE(CONCAT(a.code,' ',a.name),'(none)')", 'text', 'dim'],
                'product' => ['Product or service', "COALESCE(ps.name,'(none)')", 'text', 'dim'],
                'taxCode' => ['Tax code', "COALESCE(tc.code,'(none)')", 'text', 'dim'],
                'description' => ['Line description', "COALESCE(bl.description,'')", 'text', 'dim'],
                'quantity' => ['Quantity', 'bl.quantity_milli', 'quantity', 'measure'],
                'amount' => ['Amount before tax', 'bl.amount_cents', 'money', 'measure'],
                'gst' => ['GST/HST', 'bl.gst_cents', 'money', 'measure'],
                'pst' => ['PST', 'bl.pst_cents', 'money', 'measure'],
                'tax' => ['Tax', 'bl.tax_cents', 'money', 'measure'],
            ],
        ],
        'notes' => [
            'start' => ['rows' => ['kind'], 'values' => ['total']],
            'label' => 'Credit and debit notes',
            'desc' => 'Customer and vendor credit and debit notes.',
            'perms' => ['reports.view', 'invoices.view', 'bills.view'],
            'from' => "accounting_notes n LEFT JOIN customers c ON n.note_kind LIKE 'customer%' AND c.id=n.party_id AND c.company_id=n.company_id LEFT JOIN vendors v ON n.note_kind LIKE 'vendor%' AND v.id=n.party_id AND v.company_id=n.company_id",
            'company' => 'n.company_id', 'date' => 'n.note_date', 'where' => '',
            'list' => ['kind', 'number', 'date', 'party', 'status', 'total'],
            'fields' => [
                'kind' => ['Note type', 'n.note_kind', 'text', 'dim'],
                'number' => ['Note number', "COALESCE(n.number,'')", 'text', 'dim'],
                'date' => ['Note date', 'n.note_date', 'date', 'dim'],
                'party' => ['Customer or vendor', "COALESCE(c.name,v.name,'(none)')", 'text', 'dim'],
                'status' => ['Status', 'n.status', 'text', 'dim'],
                'currency' => ['Currency', 'n.currency', 'text', 'dim'],
                'subtotal' => ['Subtotal', 'n.subtotal_cents', 'money', 'measure'],
                'tax' => ['Tax', 'n.tax_cents', 'money', 'measure'],
                'total' => ['Total', 'n.total_cents', 'money', 'measure'],
                'applied' => ['Applied', 'n.applied_cents', 'money', 'measure'],
            ],
        ],
        'payments' => [
            'start' => ['rows' => ['party'], 'values' => ['amount']],
            'label' => 'Payments received and made',
            'desc' => 'Customer receipts and vendor payments, with the amount applied to invoices.',
            'perms' => ['reports.view', 'invoices.view', 'bills.view'],
            'from' => "party_payments p LEFT JOIN customers c ON p.payment_type='customer' AND c.id=p.party_id AND c.company_id=p.company_id LEFT JOIN vendors v ON p.payment_type='vendor' AND v.id=p.party_id AND v.company_id=p.company_id LEFT JOIN accounts a ON a.id=p.payment_account_id AND a.company_id=p.company_id",
            'company' => 'p.company_id', 'date' => 'p.payment_date', 'where' => '',
            'list' => ['type', 'date', 'party', 'reference', 'account', 'amount'],
            'fields' => [
                'type' => ['Payment type', 'p.payment_type', 'text', 'dim'],
                'date' => ['Payment date', 'p.payment_date', 'date', 'dim'],
                'party' => ['Customer or vendor', "COALESCE(c.name,v.name,'(none)')", 'text', 'dim'],
                'reference' => ['Reference', "COALESCE(p.reference,'')", 'text', 'dim'],
                'account' => ['Bank or cash account', "COALESCE(CONCAT(a.code,' ',a.name),'(none)')", 'text', 'dim'],
                'status' => ['Status', 'p.status', 'text', 'dim'],
                'currency' => ['Currency', 'p.currency', 'text', 'dim'],
                'amount' => ['Amount', 'p.amount_cents', 'money', 'measure'],
                'applied' => ['Applied to invoices', 'p.applied_cents', 'money', 'measure'],
                'unapplied' => ['Not yet applied', 'p.amount_cents-p.applied_cents', 'money', 'measure'],
            ],
        ],
        'expenses' => [
            'start' => ['rows' => ['category'], 'values' => ['total']],
            'label' => 'Expenses',
            'desc' => 'Expense vouchers paid directly, by category and payment account.',
            'perms' => ['reports.view', 'bills.view'],
            'from' => 'expenses e LEFT JOIN accounts ca ON ca.id=e.category_account_id AND ca.company_id=e.company_id LEFT JOIN accounts pa ON pa.id=e.paid_from_account_id AND pa.company_id=e.company_id',
            'company' => 'e.company_id', 'date' => 'e.expense_date', 'where' => '',
            'list' => ['date', 'vendor', 'category', 'paidFrom', 'total'],
            'fields' => [
                'date' => ['Expense date', 'e.expense_date', 'date', 'dim'],
                'vendor' => ['Vendor', "COALESCE(NULLIF(e.vendor,''),'(none)')", 'text', 'dim'],
                'category' => ['Expense account', "COALESCE(CONCAT(ca.code,' ',ca.name),'(none)')", 'text', 'dim'],
                'paidFrom' => ['Paid from', "COALESCE(CONCAT(pa.code,' ',pa.name),'(none)')", 'text', 'dim'],
                'status' => ['Status', 'e.status', 'text', 'dim'],
                'currency' => ['Currency', 'e.currency', 'text', 'dim'],
                'subtotal' => ['Subtotal', 'e.subtotal_cents', 'money', 'measure'],
                'gstHst' => ['GST/HST', 'e.gst_hst_cents', 'money', 'measure'],
                'pst' => ['PST', 'e.pst_cents', 'money', 'measure'],
                'tax' => ['Total tax', 'e.tax_cents', 'money', 'measure'],
                'total' => ['Total', 'e.total_cents', 'money', 'measure'],
            ],
        ],
        'bank' => [
            'start' => ['rows' => ['bankAccount'], 'values' => ['moneyIn', 'moneyOut']],
            'label' => 'Bank lines',
            'desc' => 'Imported and hand-entered bank and credit-card lines, posted or not.',
            'perms' => ['reports.view', 'banking.view'],
            'from' => 'bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id LEFT JOIN accounts a ON a.id=bt.decided_account_id AND a.company_id=bt.company_id',
            'company' => 'bt.company_id', 'date' => 'bt.transaction_date', 'where' => '',
            'list' => ['date', 'bankAccount', 'description', 'status', 'moneyIn', 'moneyOut'],
            'fields' => [
                'date' => ['Date', 'bt.transaction_date', 'date', 'dim'],
                'bankAccount' => ['Bank account', 'ba.name', 'text', 'dim'],
                'description' => ['Description', "COALESCE(bt.description,'')", 'text', 'dim'],
                'merchant' => ['Payee (cleaned)', "COALESCE(NULLIF(bt.normalized_merchant,''),'(none)')", 'text', 'dim'],
                'reference' => ['Reference', "COALESCE(bt.reference,'')", 'text', 'dim'],
                'status' => ['Status', 'bt.status', 'text', 'dim'],
                'source' => ['Source', "CASE WHEN bt.import_batch_id IS NULL THEN 'Entered by hand' ELSE 'Imported' END", 'text', 'dim'],
                'account' => ['Posted to account', "COALESCE(CONCAT(a.code,' ',a.name),'(not posted)')", 'text', 'dim'],
                'currency' => ['Currency', 'bt.currency', 'text', 'dim'],
                'amount' => ['Amount (in − out)', 'bt.amount_cents', 'money', 'measure'],
                'moneyIn' => ['Money in', 'GREATEST(bt.amount_cents,0)', 'money', 'measure'],
                'moneyOut' => ['Money out', 'GREATEST(-bt.amount_cents,0)', 'money', 'measure'],
            ],
        ],
        'taxLines' => [
            'start' => ['rows' => ['taxCode'], 'values' => ['tax']],
            'label' => 'Sales tax lines',
            'desc' => 'GST/HST, PST and QST charged on sales and paid on purchases, by tax code and component.',
            'perms' => ['reports.view', 'invoices.view', 'bills.view'],
            'from' => "document_tax_lines t LEFT JOIN invoices i ON t.document_type='invoice' AND i.id=t.document_id AND i.company_id=t.company_id LEFT JOIN bills b ON t.document_type='bill' AND b.id=t.document_id AND b.company_id=t.company_id LEFT JOIN expenses e ON t.document_type='expense' AND e.id=t.document_id AND e.company_id=t.company_id LEFT JOIN accounting_notes n ON t.document_type='accounting_note' AND n.id=t.document_id AND n.company_id=t.company_id LEFT JOIN accounts a ON a.id=t.account_id AND a.company_id=t.company_id",
            'company' => 't.company_id', 'date' => 'COALESCE(i.issue_date,b.bill_date,e.expense_date,n.note_date)', 'where' => '',
            'list' => ['date', 'documentType', 'side', 'taxCode', 'component', 'taxable', 'tax'],
            'fields' => [
                'date' => ['Document date', 'COALESCE(i.issue_date,b.bill_date,e.expense_date,n.note_date)', 'date', 'dim'],
                'documentType' => ['Document type', 't.document_type', 'text', 'dim'],
                'documentNumber' => ['Document number', "COALESCE(i.number,b.number,n.number,'')", 'text', 'dim'],
                'documentStatus' => ['Document status', "COALESCE(i.status,b.status,e.status,n.status,'')", 'text', 'dim'],
                'side' => ['Sales or purchases', 't.side', 'text', 'dim'],
                'taxCode' => ['Tax code', "COALESCE(t.tax_code,'(none)')", 'text', 'dim'],
                'component' => ['Tax component', "COALESCE(t.component_name,'(none)')", 'text', 'dim'],
                'account' => ['Tax account', "COALESCE(CONCAT(a.code,' ',a.name),'(none)')", 'text', 'dim'],
                'recoverable' => ['Recoverable', "CASE WHEN t.recoverable=1 THEN 'Recoverable' ELSE 'Not recoverable' END", 'text', 'dim'],
                'taxable' => ['Taxable amount', 't.taxable_base_cents', 'money', 'measure'],
                'tax' => ['Tax', 't.tax_cents', 'money', 'measure'],
            ],
        ],
        'customers' => [
            'start' => ['rows' => ['province'], 'values' => ['balance']],
            'label' => 'Customers',
            'desc' => 'Customer list with invoiced totals and open balances (issued invoices only).',
            'perms' => ['reports.view', 'invoices.view', 'customers.view'],
            'from' => "customers c LEFT JOIN (SELECT customer_id, COUNT(*) n, SUM(total_cents) inv, SUM(balance_cents) bal FROM invoices WHERE company_id=? AND status<>'draft' AND status<>'void' GROUP BY customer_id) ix ON ix.customer_id=c.id",
            'company' => 'c.company_id', 'date' => null, 'where' => '',
            'list' => ['name', 'city', 'province', 'invoiced', 'balance'],
            'fields' => [
                'name' => ['Customer', 'c.name', 'text', 'dim'],
                'city' => ['City', "COALESCE(NULLIF(c.city,''),'(none)')", 'text', 'dim'],
                'province' => ['Province', "COALESCE(NULLIF(c.province,''),'(none)')", 'text', 'dim'],
                'country' => ['Country', "COALESCE(NULLIF(c.country,''),'(none)')", 'text', 'dim'],
                'status' => ['Status', "CASE WHEN c.active=1 THEN 'Active' ELSE 'Inactive' END", 'text', 'dim'],
                'email' => ['Email', "COALESCE(c.email,'')", 'text', 'dim'],
                'invoiceCount' => ['Issued invoices', 'COALESCE(ix.n,0)', 'integer', 'measure'],
                'invoiced' => ['Invoiced (all time)', 'COALESCE(ix.inv,0)', 'money', 'measure'],
                'balance' => ['Open balance', 'COALESCE(ix.bal,0)', 'money', 'measure'],
            ],
        ],
        'vendors' => [
            'start' => ['rows' => ['province'], 'values' => ['balance']],
            'label' => 'Vendors',
            'desc' => 'Vendor list with billed totals and open balances (open and paid bills only).',
            'perms' => ['reports.view', 'bills.view', 'vendors.view'],
            'from' => "vendors v LEFT JOIN (SELECT vendor_id, COUNT(*) n, SUM(total_cents) billed, SUM(balance_cents) bal FROM bills WHERE company_id=? AND status<>'draft' AND status<>'void' GROUP BY vendor_id) bx ON bx.vendor_id=v.id",
            'company' => 'v.company_id', 'date' => null, 'where' => '',
            'list' => ['name', 'city', 'province', 'billed', 'balance'],
            'fields' => [
                'name' => ['Vendor', 'v.name', 'text', 'dim'],
                'city' => ['City', "COALESCE(NULLIF(v.city,''),'(none)')", 'text', 'dim'],
                'province' => ['Province', "COALESCE(NULLIF(v.province,''),'(none)')", 'text', 'dim'],
                'country' => ['Country', "COALESCE(NULLIF(v.country,''),'(none)')", 'text', 'dim'],
                'status' => ['Status', "CASE WHEN v.active=1 THEN 'Active' ELSE 'Inactive' END", 'text', 'dim'],
                'email' => ['Email', "COALESCE(v.email,'')", 'text', 'dim'],
                'billCount' => ['Bills', 'COALESCE(bx.n,0)', 'integer', 'measure'],
                'billed' => ['Billed (all time)', 'COALESCE(bx.billed,0)', 'money', 'measure'],
                'balance' => ['Open balance', 'COALESCE(bx.bal,0)', 'money', 'measure'],
            ],
        ],
    ];
}

/** Display wording for stored codes (statuses, entry types). Unknown codes are shown tidied, never hidden. */
function tegh_rb_label(string $field, mixed $value): string
{
    $v = (string)($value ?? '');
    static $map = [
        'sourceType' => ['invoice' => 'Customer invoice', 'bill' => 'Vendor invoice', 'customer_payment' => 'Customer payment', 'vendor_payment' => 'Vendor payment', 'bank_transaction' => 'Bank transaction', 'bank_transaction_voided' => 'Bank transaction (voided)', 'manual_journal' => 'Journal entry', 'opening_balance' => 'Opening balance', 'expense' => 'Expense', 'accounting_note' => 'Credit or debit note', 'accounting_note_void' => 'Credit or debit note (voided)', 'asset_depreciation' => 'Depreciation', 'payroll_accrual' => 'Payroll', 'invoice_correction' => 'Invoice correction', 'invoice_correction_reversal' => 'Invoice correction (reversal of original)'],
        'kind' => ['customer_credit' => 'Customer credit note', 'customer_debit' => 'Customer debit note', 'vendor_credit' => 'Vendor credit note', 'vendor_debit' => 'Vendor debit note'],
        'type' => ['customer' => 'Received from customer', 'vendor' => 'Paid to vendor'],
        'documentType' => ['invoice' => 'Customer invoice', 'bill' => 'Vendor invoice', 'expense' => 'Expense', 'accounting_note' => 'Credit or debit note'],
        'side' => ['sales' => 'Sales (tax charged)', 'purchase' => 'Purchases (tax paid)'],
    ];
    if (isset($map[$field][$v])) return $map[$field][$v];
    if (in_array($field, ['status', 'documentStatus', 'accountType', 'sourceType', 'kind', 'type', 'documentType', 'side'], true) && $v !== '') return ucfirst(str_replace('_', ' ', $v));
    return $v;
}

function tegh_rb_allowed_sources(array $company, array $user): array
{
    $out = [];
    foreach (tegh_rb_sources() as $key => $s) {
        $ok = true;
        foreach ($s['perms'] as $perm) if (!company_role_can((string)$company['role'], $perm)) { $ok = false; break; }
        if ($ok) $out[$key] = $s;
    }
    return $out;
}

function tegh_rb_catalogue_public(array $company, array $user): array
{
    $sources = [];
    foreach (tegh_rb_allowed_sources($company, $user) as $key => $s) {
        $fields = [];
        foreach ($s['fields'] as $fk => [$label, , $type, $kind]) $fields[] = ['key' => $fk, 'label' => $label, 'type' => $type, 'kind' => $kind];
        $sources[] = ['key' => $key, 'label' => $s['label'], 'description' => $s['desc'], 'hasDate' => $s['date'] !== null, 'listDefault' => $s['list'], 'start' => $s['start'], 'fields' => $fields];
    }
    return ['version' => TEGH_RB_VERSION, 'sources' => $sources,
        'periodColumns' => [['key' => 'period:month', 'label' => 'Month'], ['key' => 'period:quarter', 'label' => 'Quarter'], ['key' => 'period:year', 'label' => 'Calendar year'], ['key' => 'period:fy', 'label' => 'Financial year']],
        'limits' => ['rowFields' => TEGH_RB_MAX_ROW_FIELDS, 'values' => TEGH_RB_MAX_VALUES, 'filters' => TEGH_RB_FORM_FILTERS, 'drillFilters' => TEGH_RB_MAX_FILTERS, 'listFields' => TEGH_RB_MAX_LIST_FIELDS, 'columns' => TEGH_RB_MAX_BUCKETS, 'groups' => TEGH_RB_MAX_GROUPS, 'listRows' => TEGH_REPORT_LIMIT_5980]];
}

/** Validates a report definition against the catalogue for THIS user. Unknown keys are refused, never ignored. */
function tegh_rb_validate_spec(array $company, array $user, mixed $raw): array
{
    if (!is_array($raw) || array_is_list($raw)) fail('The report definition is not valid.', 422, 'report_builder_spec_invalid');
    $allowedKeys = ['source', 'mode', 'rows', 'columns', 'values', 'fields', 'filters', 'period', 'sort', 'compare', 'title', 'subtotals'];
    if ($extra = array_diff(array_keys($raw), $allowedKeys)) fail('Unknown report setting: ' . implode(', ', $extra) . '.', 422, 'report_builder_spec_invalid');
    $all = tegh_rb_sources();
    $sourceKey = (string)($raw['source'] ?? '');
    if (!isset($all[$sourceKey])) fail('Choose what the report is about.', 422, 'report_builder_source_invalid');
    $allowed = tegh_rb_allowed_sources($company, $user);
    if (!isset($allowed[$sourceKey])) fail('Your company role does not allow reports on ' . $all[$sourceKey]['label'] . '.', 403, 'permission_forbidden');
    $s = $allowed[$sourceKey];
    $fields = $s['fields'];
    $mode = (string)($raw['mode'] ?? 'summary');
    if (!in_array($mode, ['summary', 'list'], true)) fail('Choose a summary or a list.', 422, 'report_builder_spec_invalid');
    $dimKey = static function (mixed $k) use ($fields): string {
        $k = (string)$k;
        if (!isset($fields[$k]) || $fields[$k][3] !== 'dim') fail('That field cannot be used to group or filter rows.', 422, 'report_builder_field_invalid');
        return $k;
    };
    $spec = ['source' => $sourceKey, 'mode' => $mode, 'rows' => [], 'columns' => null, 'values' => [], 'fields' => [], 'filters' => [], 'period' => null, 'sort' => null, 'compare' => 'none', 'subtotals' => true, 'title' => ''];
    $spec['title'] = mb_substr(trim((string)($raw['title'] ?? '')), 0, 120);
    $spec['subtotals'] = ($raw['subtotals'] ?? true) !== false;
    if ($mode === 'summary') {
        $rows = $raw['rows'] ?? [];
        if (!is_array($rows) || count($rows) > TEGH_RB_MAX_ROW_FIELDS) fail('Choose up to ' . TEGH_RB_MAX_ROW_FIELDS . ' fields for the rows.', 422, 'report_builder_spec_invalid');
        foreach ($rows as $k) $spec['rows'][] = $dimKey($k);
        if (count(array_unique($spec['rows'])) !== count($spec['rows'])) fail('A field is chosen twice for the rows.', 422, 'report_builder_spec_invalid');
        $col = $raw['columns'] ?? null;
        if ($col !== null && $col !== '') {
            $col = (string)$col;
            if (str_starts_with($col, 'period:')) {
                if ($s['date'] === null) fail('This data has no date, so it cannot be split by period.', 422, 'report_builder_spec_invalid');
                if (!in_array($col, ['period:month', 'period:quarter', 'period:year', 'period:fy'], true)) fail('Choose month, quarter, year or financial year.', 422, 'report_builder_spec_invalid');
            } else {
                $col = $dimKey($col);
                if (in_array($col, $spec['rows'], true)) fail('A field cannot be both a row and a column.', 422, 'report_builder_spec_invalid');
            }
            $spec['columns'] = $col;
        }
        $values = $raw['values'] ?? [];
        if (!is_array($values) || !$values || count($values) > TEGH_RB_MAX_VALUES) fail('Choose between 1 and ' . TEGH_RB_MAX_VALUES . ' values to add up.', 422, 'report_builder_spec_invalid');
        foreach ($values as $k) {
            $k = (string)$k;
            if ($k !== 'count' && (!isset($fields[$k]) || $fields[$k][3] !== 'measure')) fail('That value cannot be added up.', 422, 'report_builder_field_invalid');
            $spec['values'][] = $k;
        }
        if (count(array_unique($spec['values'])) !== count($spec['values'])) fail('A value is chosen twice.', 422, 'report_builder_spec_invalid');
        $compare = (string)($raw['compare'] ?? 'none');
        if (!in_array($compare, ['none', 'change', 'percent'], true)) fail('Choose no comparison, change or percentage change.', 422, 'report_builder_spec_invalid');
        if ($compare !== 'none' && !str_starts_with((string)$spec['columns'], 'period:')) fail('Comparison columns need the columns side by side by period.', 422, 'report_builder_spec_invalid');
        $spec['compare'] = $compare;
    } else {
        $list = $raw['fields'] ?? [];
        if (!is_array($list) || !$list || count($list) > TEGH_RB_MAX_LIST_FIELDS) fail('Choose between 1 and ' . TEGH_RB_MAX_LIST_FIELDS . ' fields for the list.', 422, 'report_builder_spec_invalid');
        foreach ($list as $k) { $k = (string)$k; if (!isset($fields[$k])) fail('Unknown field in the list.', 422, 'report_builder_field_invalid'); $spec['fields'][] = $k; }
        $spec['fields'] = array_values(array_unique($spec['fields']));
    }
    $filters = $raw['filters'] ?? [];
    if (!is_array($filters) || count($filters) > TEGH_RB_MAX_FILTERS) fail('Use up to ' . TEGH_RB_MAX_FILTERS . ' filters.', 422, 'report_builder_spec_invalid');
    foreach ($filters as $f) {
        if (!is_array($f)) fail('A filter is not valid.', 422, 'report_builder_filter_invalid');
        $k = (string)($f['field'] ?? '');
        if (!isset($fields[$k])) fail('A filter uses an unknown field.', 422, 'report_builder_filter_invalid');
        $type = $fields[$k][2]; $op = (string)($f['op'] ?? '');
        $ops = match ($type) { 'text' => ['eq', 'ne', 'contains', 'in', 'blank', 'notblank'], 'date' => ['eq', 'before', 'after', 'between'], default => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between'] };
        if (!in_array($op, $ops, true)) fail('A filter on ' . $fields[$k][0] . ' uses an operator that does not apply to it.', 422, 'report_builder_filter_invalid');
        $value = $f['value'] ?? null;
        $norm = static function (mixed $v) use ($type, $fields, $k): mixed {
            if ($type === 'text') { $v = trim((string)$v); if (mb_strlen($v) > 200) fail('A filter value is too long.', 422, 'report_builder_filter_invalid'); return $v; }
            if ($type === 'date') return safe_date($v, $fields[$k][0]);
            if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d{1,15}$/', $v))) fail('A filter on ' . $fields[$k][0] . ' needs a whole number' . ($type === 'money' ? ' of cents' : '') . '.', 422, 'report_builder_filter_invalid');
            return (int)$v;
        };
        if (in_array($op, ['blank', 'notblank'], true)) $value = null;
        elseif ($op === 'in') { if (!is_array($value) || !$value || count($value) > 200) fail('Choose between 1 and 200 values for ' . $fields[$k][0] . '.', 422, 'report_builder_filter_invalid'); $value = array_values(array_unique(array_map($norm, $value))); }
        elseif ($op === 'between') { if (!is_array($value) || count($value) !== 2) fail('A range filter needs a start and an end.', 422, 'report_builder_filter_invalid'); $value = [$norm($value[0]), $norm($value[1])]; if ($value[0] > $value[1]) fail('A range filter starts after it ends.', 422, 'report_builder_filter_invalid'); }
        else $value = $norm($value);
        $spec['filters'][] = ['field' => $k, 'op' => $op, 'value' => $value];
    }
    if ($s['date'] !== null) {
        $p = $raw['period'] ?? null;
        if (!is_array($p)) fail('Choose the report period.', 422, 'report_builder_period_required');
        $start = safe_date($p['start'] ?? '', 'Start date'); $end = safe_date($p['end'] ?? '', 'End date');
        if ($start > $end) fail('Start date must not be after end date.', 422, 'report_period_invalid');
        $spec['period'] = ['start' => $start, 'end' => $end];
    }
    $sort = $raw['sort'] ?? null;
    if (is_array($sort) && ($sort['key'] ?? '') !== '') {
        $key = (string)$sort['key']; $dir = ($sort['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $okKeys = $mode === 'list' ? $spec['fields'] : array_merge($spec['rows'], ['__total']);
        if (!in_array($key, $okKeys, true)) fail('Sort by one of the fields in the report.', 422, 'report_builder_spec_invalid');
        $spec['sort'] = ['key' => $key, 'dir' => $dir];
    }
    return $spec;
}

/** Period bucket SQL and its label; the financial year is labelled by the year it ends. */
function tegh_rb_period_expr(string $col, string $date, array $company): string
{
    $fy = (string)($company['fiscal_year_end'] ?? '12-31');
    if (!preg_match('/^(\d{2})-(\d{2})$/', $fy)) $fy = '12-31';
    return match ($col) {
        'period:month' => "DATE_FORMAT($date,'%Y-%m')",
        'period:quarter' => "CONCAT(YEAR($date),'-Q',QUARTER($date))",
        'period:year' => "CAST(YEAR($date) AS CHAR)",
        'period:fy' => $fy === '12-31' ? "CAST(YEAR($date) AS CHAR)" : "CAST(IF(DATE_FORMAT($date,'%m-%d')>'$fy',YEAR($date)+1,YEAR($date)) AS CHAR)",
    };
}

/** The same bucket as tegh_rb_period_expr, worked out in PHP (used to list empty periods). */
function tegh_rb_bucket_of(string $col, string $date, array $company): string
{
    $fy = (string)($company['fiscal_year_end'] ?? '12-31');
    if (!preg_match('/^(\d{2})-(\d{2})$/', $fy)) $fy = '12-31';
    $y = (int)substr($date, 0, 4); $m = (int)substr($date, 5, 2);
    return match ($col) {
        'period:month' => substr($date, 0, 7),
        'period:quarter' => $y . '-Q' . intdiv($m - 1, 3) + 1,
        'period:year' => (string)$y,
        'period:fy' => (string)($fy !== '12-31' && substr($date, 5, 5) > $fy ? $y + 1 : $y),
    };
}

function tegh_rb_bucket_range(string $col, string $bucket, array $company): array
{
    $fy = (string)($company['fiscal_year_end'] ?? '12-31');
    if (!preg_match('/^(\d{2})-(\d{2})$/', $fy)) $fy = '12-31';
    if ($col === 'period:month') { $s = $bucket . '-01'; return [$s, date('Y-m-t', strtotime($s))]; }
    if ($col === 'period:quarter') { [$y, $q] = explode('-Q', $bucket); $m = ((int)$q - 1) * 3 + 1; $s = sprintf('%04d-%02d-01', (int)$y, $m); return [$s, date('Y-m-t', strtotime(sprintf('%04d-%02d-01', (int)$y, $m + 2)))]; }
    if ($col === 'period:year') return [$bucket . '-01-01', $bucket . '-12-31'];
    $endY = (int)$bucket; $end = sprintf('%04d-%s', $endY, $fy);
    if (!checkdate((int)substr($fy, 0, 2), (int)substr($fy, 3, 2), $endY)) $end = date('Y-m-t', strtotime(sprintf('%04d-%s-01', $endY, substr($fy, 0, 2))));
    $start = date('Y-m-d', strtotime(sprintf('%04d-%s', $endY - 1, $fy) . ' +1 day'));
    return [$start, $end];
}

function tegh_rb_bucket_label(string $col, string $bucket, array $company): string
{
    if ($col === 'period:month') return date('M Y', strtotime($bucket . '-01'));
    if ($col === 'period:quarter') { [$y, $q] = explode('-Q', $bucket); return 'Q' . $q . ' ' . $y; }
    if ($col === 'period:year') return $bucket;
    if ($col === 'period:fy') { $fy = (string)($company['fiscal_year_end'] ?? '12-31'); return $fy === '12-31' ? 'FY ' . $bucket : 'FY ending ' . date('M j, Y', strtotime(tegh_rb_bucket_range($col, $bucket, $company)[1])); }
    return $bucket;
}

/** WHERE clause for the company, the period and the filters. Values are always bound. */
function tegh_rb_where(array $s, array $spec, array $company, array &$params): string
{
    $params = array_merge($params, array_fill(0, substr_count($s['from'], '?'), (string)$company['id']));
    $where = [$s['company'] . '=?']; $params[] = (string)$company['id'];
    if ($s['where'] !== '') $where[] = $s['where'];
    if ($s['date'] !== null && $spec['period']) { $where[] = $s['date'] . ' BETWEEN ? AND ?'; $params[] = $spec['period']['start']; $params[] = $spec['period']['end']; }
    foreach ($spec['filters'] as $f) {
        $expr = $s['fields'][$f['field']][1]; $v = $f['value'];
        switch ($f['op']) {
            case 'eq': $where[] = "$expr=?"; $params[] = $v; break;
            case 'ne': $where[] = "($expr<>? OR $expr IS NULL)"; $params[] = $v; break;
            case 'gt': $where[] = "$expr>?"; $params[] = $v; break;
            case 'gte': $where[] = "$expr>=?"; $params[] = $v; break;
            case 'lt': case 'before': $where[] = "$expr<?"; $params[] = $v; break;
            case 'lte': $where[] = "$expr<=?"; $params[] = $v; break;
            case 'after': $where[] = "$expr>?"; $params[] = $v; break;
            case 'between': $where[] = "$expr BETWEEN ? AND ?"; $params[] = $v[0]; $params[] = $v[1]; break;
            case 'contains': $where[] = "$expr LIKE ? ESCAPE '!'"; $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v) . '%'; break;
            case 'in': $where[] = "$expr IN (" . implode(',', array_fill(0, count($v), '?')) . ')'; array_push($params, ...$v); break;
            case 'blank': $where[] = "($expr IS NULL OR $expr='' OR $expr='(none)')"; break;
            case 'notblank': $where[] = "($expr IS NOT NULL AND $expr<>'' AND $expr<>'(none)')"; break;
        }
    }
    return ' WHERE ' . implode(' AND ', $where);
}

function tegh_rb_value(string $type, mixed $raw): mixed
{
    if ($raw === null) return null;
    return match ($type) { 'money', 'integer' => (int)$raw, 'quantity' => round(((int)$raw) / 1000, 3), 'date' => substr((string)$raw, 0, 10), default => (string)$raw };
}

function tegh_rb_column_type(string $type): string
{
    return match ($type) { 'quantity' => 'number', default => $type };
}

function tegh_rb_filter_text(array $s, array $spec): string
{
    $words = ['eq' => 'is', 'ne' => 'is not', 'gt' => 'over', 'gte' => 'at least', 'lt' => 'under', 'lte' => 'at most', 'before' => 'before', 'after' => 'after', 'between' => 'between', 'contains' => 'contains', 'in' => 'is one of', 'blank' => 'is blank', 'notblank' => 'is not blank'];
    $parts = [];
    foreach ($spec['filters'] as $f) {
        [$label, , $type] = $s['fields'][$f['field']];
        $fmt = static fn($v) => $type === 'money' ? number_format($v / 100, 2) : ($type === 'text' ? tegh_rb_label($f['field'], $v) : (string)$v);
        $val = match (true) { $f['value'] === null => '', is_array($f['value']) && $f['op'] === 'between' => $fmt($f['value'][0]) . ' and ' . $fmt($f['value'][1]), is_array($f['value']) => implode(', ', array_map($fmt, $f['value'])), default => $fmt($f['value']) };
        $parts[] = trim($label . ' ' . $words[$f['op']] . ' ' . $val);
    }
    return $parts ? implode('; ', $parts) : 'None';
}

/** Runs a validated definition and returns the report model (not yet sealed) plus the layout for the builder screen. */
function tegh_rb_build(array $company, array $spec): array
{
    $s = tegh_rb_sources()[$spec['source']];
    $params = [];
    $where = tegh_rb_where($s, $spec, $company, $params);
    $layout = ['mode' => $spec['mode'], 'rowKeys' => [], 'buckets' => [], 'valueKeys' => $spec['values']];
    if ($spec['mode'] === 'list') {
        $select = []; $columns = [];
        foreach ($spec['fields'] as $i => $k) { [$label, $expr, $type] = $s['fields'][$k]; $select[] = "$expr AS f$i"; $columns[] = ['key' => $k, 'label' => $label, 'type' => tegh_rb_column_type($type), 'width' => $type === 'text' ? 24 : 14]; }
        $order = $s['date'] !== null ? $s['date'] : '1';
        if ($spec['sort']) { $i = array_search($spec['sort']['key'], $spec['fields'], true); $order = 'f' . $i . ' ' . strtoupper($spec['sort']['dir']); }
        $sql = 'SELECT ' . implode(',', $select) . ' FROM ' . $s['from'] . $where . ' ORDER BY ' . $order . ' LIMIT ' . (TEGH_REPORT_LIMIT_5980 + 1);
        $q = db()->prepare($sql); $q->execute($params); $raw = $q->fetchAll(PDO::FETCH_NUM);
        if (count($raw) > TEGH_REPORT_LIMIT_5980) fail('This list has more than ' . number_format(TEGH_REPORT_LIMIT_5980) . ' lines. Narrow the period or add a filter; no shortened list was produced.', 413, 'report_incomplete_over_limit', false);
        $rows = []; $totals = [];
        foreach ($raw as $n => $r) {
            $row = ['id' => 'l' . $n];
            foreach ($spec['fields'] as $i => $k) { $type = $s['fields'][$k][2]; $v = tegh_rb_value($type, $r[$i]); $row[$k] = $type === 'text' ? tegh_rb_label($k, $v) : $v; if (in_array($type, ['money', 'integer'], true)) $totals[$k] = ($totals[$k] ?? 0) + (int)$v; }
            $rows[] = $row;
        }
        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'layout' => $layout];
    }

    // Summary: GROUP BY the row fields and the column field, add up the values.
    $select = []; $group = [];
    foreach ($spec['rows'] as $i => $k) { $select[] = $s['fields'][$k][1] . " AS d$i"; $group[] = "d$i"; }
    $colExpr = null;
    if ($spec['columns'] !== null) {
        $colExpr = str_starts_with($spec['columns'], 'period:') ? tegh_rb_period_expr($spec['columns'], $s['date'], $company) : $s['fields'][$spec['columns']][1];
        $select[] = "$colExpr AS cb"; $group[] = 'cb';
    }
    foreach ($spec['values'] as $j => $k) $select[] = $k === 'count' ? "COUNT(*) AS m$j" : 'COALESCE(SUM(' . $s['fields'][$k][1] . "),0) AS m$j";
    $sql = 'SELECT ' . implode(',', $select) . ' FROM ' . $s['from'] . $where . ($group ? ' GROUP BY ' . implode(',', $group) : '') . ' LIMIT ' . (TEGH_RB_MAX_GROUPS * TEGH_RB_MAX_BUCKETS + 1);
    $q = db()->prepare($sql); $q->execute($params); $raw = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($raw) > TEGH_RB_MAX_GROUPS * TEGH_RB_MAX_BUCKETS) fail('This report is too large to prepare in full. Group by a broader field, shorten the period or add a filter; no shortened report was produced.', 413, 'report_incomplete_over_limit', false);

    $valueMeta = [];
    foreach ($spec['values'] as $j => $k) $valueMeta[$j] = $k === 'count' ? ['Number of lines', 'integer'] : [$s['fields'][$k][0], $s['fields'][$k][2]];
    $buckets = [];
    if ($colExpr !== null) {
        foreach ($raw as $r) $buckets[(string)($r['cb'] ?? '')] = true;
        $buckets = array_keys($buckets);
        // Every period in the report range gets a column, even an empty one, so months line up.
        if (str_starts_with($spec['columns'], 'period:') && $spec['period']) {
            $d = (new DateTimeImmutable($spec['period']['start']))->modify('first day of this month'); $end = new DateTimeImmutable($spec['period']['end']); $all = [];
            while ($d <= $end && count($all) <= TEGH_RB_MAX_BUCKETS) { $all[tegh_rb_bucket_of($spec['columns'], $d->format('Y-m-d'), $company)] = true; $d = $d->modify('+1 month'); }
            $all[tegh_rb_bucket_of($spec['columns'], $end->format('Y-m-d'), $company)] = true;
            $buckets = array_keys($all + array_fill_keys($buckets, true));
        }
        // PHP turns keys such as "2026" into integers; bucket keys are always text.
        $buckets = array_map('strval', $buckets);
        sort($buckets, SORT_NATURAL);
        if (count($buckets) > TEGH_RB_MAX_BUCKETS) fail('This report would have ' . count($buckets) . ' columns side by side; the most is ' . TEGH_RB_MAX_BUCKETS . '. Choose a broader period grouping, a shorter period or a filter.', 422, 'report_builder_too_many_columns', false);
    }
    // Pivot.
    $groups = [];
    foreach ($raw as $r) {
        $keyParts = []; foreach ($spec['rows'] as $i => $k) $keyParts[] = (string)($r["d$i"] ?? '');
        $gk = json_encode($keyParts);
        if (!isset($groups[$gk])) { if (count($groups) >= TEGH_RB_MAX_GROUPS) fail('This report has more than ' . number_format(TEGH_RB_MAX_GROUPS) . ' rows. Group by a broader field or add a filter; no shortened report was produced.', 413, 'report_incomplete_over_limit', false); $groups[$gk] = ['keys' => $keyParts, 'cells' => []]; }
        $b = $colExpr !== null ? (string)($r['cb'] ?? '') : '';
        foreach ($spec['values'] as $j => $k) $groups[$gk]['cells'][$b][$j] = ($groups[$gk]['cells'][$b][$j] ?? 0) + (int)$r["m$j"];
    }
    $bucketList = $colExpr !== null ? $buckets : [''];
    $multi = count($spec['values']) > 1;
    $columns = []; $colKeys = [];
    // Row labels are text (a subtotal row says "Subtotal: …" in the first column).
    foreach ($spec['rows'] as $i => $k) $columns[] = ['key' => "d$i", 'label' => $s['fields'][$k][0], 'type' => 'text', 'width' => 26];
    if (!$spec['rows']) $columns[] = ['key' => 'd0', 'label' => '', 'type' => 'text', 'width' => 18];
    $isPeriod = $colExpr !== null && str_starts_with($spec['columns'], 'period:');
    foreach ($bucketList as $bi => $b) {
        $bLabel = $colExpr === null ? '' : ($isPeriod ? tegh_rb_bucket_label($spec['columns'], $b, $company) : (tegh_rb_label($spec['columns'], $b) === '' ? '(blank)' : tegh_rb_label($spec['columns'], $b)));
        $bMeta = ['key' => $b, 'label' => $bLabel];
        if ($isPeriod) { [$bs, $be] = tegh_rb_bucket_range($spec['columns'], $b, $company); $bMeta['start'] = max($bs, $spec['period']['start']); $bMeta['end'] = min($be, $spec['period']['end']); }
        $layout['buckets'][] = $bMeta;
        foreach ($spec['values'] as $j => $k) {
            [$vLabel, $vType] = $valueMeta[$j];
            $key = "c{$bi}_m{$j}"; $colKeys[] = [$key, $bi, $j, 'value'];
            $columns[] = ['key' => $key, 'label' => $colExpr === null ? $vLabel : ($multi ? "$bLabel · $vLabel" : $bLabel), 'type' => tegh_rb_column_type($vType), 'width' => 14];
            if ($spec['compare'] !== 'none' && $bi > 0) {
                $ck = "x{$bi}_m{$j}"; $colKeys[] = [$ck, $bi, $j, $spec['compare']];
                $columns[] = ['key' => $ck, 'label' => ($spec['compare'] === 'change' ? 'Change' : '% change') . ($multi ? " · $vLabel" : ''), 'type' => $spec['compare'] === 'change' ? tegh_rb_column_type($vType) : 'number', 'width' => 12];
            }
        }
    }
    if ($colExpr !== null) foreach ($spec['values'] as $j => $k) { $colKeys[] = ["t_m$j", -1, $j, 'total']; $columns[] = ['key' => "t_m$j", 'label' => ($multi ? ($isPeriod ? 'Whole period · ' : 'All · ') . $valueMeta[$j][0] : 'Total'), 'type' => tegh_rb_column_type($valueMeta[$j][1]), 'width' => 15]; }

    $makeRow = static function (array $cells, array $labels) use ($colKeys, $bucketList, $valueMeta): array {
        $row = $labels;
        foreach ($colKeys as [$key, $bi, $j, $kind]) {
            $type = $valueMeta[$j][1];
            $raw = static fn(int $b) => $cells[$bucketList[$b]][$j] ?? 0;
            if ($kind === 'value') $v = $raw($bi);
            elseif ($kind === 'total') { $v = 0; foreach ($bucketList as $b) $v += $cells[$b][$j] ?? 0; }
            elseif ($kind === 'change') $v = $raw($bi) - $raw($bi - 1);
            else { $prev = $raw($bi - 1); $v = $prev === 0 ? null : round(($raw($bi) - $prev) * 100 / abs($prev), 1); }
            $row[$key] = $v === null ? null : ($type === 'quantity' && $kind !== 'percent' ? round($v / 1000, 3) : $v);
        }
        return $row;
    };
    $entries = [];
    foreach ($groups as $g) {
        $labels = []; foreach ($spec['rows'] as $i => $k) { $t = $s['fields'][$k][2]; $labels["d$i"] = $t === 'date' ? substr($g['keys'][$i], 0, 10) : tegh_rb_label($k, $g['keys'][$i]); }
        if (!$spec['rows']) $labels['d0'] = 'All lines';
        $total = 0; foreach ($g['cells'] as $c) $total += $c[0] ?? 0;
        $entries[] = ['keys' => $g['keys'], 'labels' => $labels, 'cells' => $g['cells'], 'total' => $total];
    }
    $sortKey = $spec['sort']['key'] ?? null; $dir = ($spec['sort']['dir'] ?? 'asc') === 'desc' ? -1 : 1;
    usort($entries, static function ($a, $b) use ($spec, $sortKey, $dir) {
        if ($sortKey === '__total') return ($a['total'] <=> $b['total']) * $dir ?: strnatcasecmp(implode('|', $a['labels']), implode('|', $b['labels']));
        // Group order always follows the first row field, so subtotals stay together.
        foreach ($spec['rows'] as $i => $k) { $c = strnatcasecmp((string)$a['labels']["d$i"], (string)$b['labels']["d$i"]); if ($c) return $k === $sortKey ? $c * $dir : $c; }
        return 0;
    });
    $rows = []; $layout['rowKeys'] = [];
    $withSubtotals = $spec['subtotals'] && count($spec['rows']) >= 2 && $sortKey !== '__total';
    $flush = static function (?array $acc, ?string $label) use (&$rows, &$layout, $makeRow, $spec) {
        if ($acc === null) return;
        $labels = ['d0' => 'Subtotal: ' . $label]; for ($i = 1; $i < count($spec['rows']); $i++) $labels["d$i"] = '';
        $row = $makeRow($acc, $labels); $row['id'] = 's' . count($rows); $row['__rowType'] = 'subtotal'; $rows[] = $row; $layout['rowKeys'][] = null;
    };
    $acc = null; $accLabel = null; $grand = [];
    foreach ($entries as $e) {
        if ($withSubtotals && $acc !== null && $accLabel !== $e['labels']['d0']) { $flush($acc, $accLabel); $acc = null; }
        $row = $makeRow($e['cells'], $e['labels']); $row['id'] = 'g' . count($rows); $rows[] = $row; $layout['rowKeys'][] = $e['keys'];
        foreach ($e['cells'] as $b => $vals) foreach ($vals as $j => $v) { $grand[$b][$j] = ($grand[$b][$j] ?? 0) + $v; if ($withSubtotals) $acc[$b][$j] = ($acc[$b][$j] ?? 0) + $v; }
        $accLabel = $e['labels']['d0'];
    }
    if ($withSubtotals) $flush($acc, $accLabel);
    $totalRow = $makeRow($grand, []);
    $totals = [];
    foreach ($colKeys as [$key, , $j, $kind]) if ($kind !== 'percent' && in_array($valueMeta[$j][1], ['money', 'integer'], true)) $totals[$key] = (int)($totalRow[$key] ?? 0);
    $layout['grandTotal'] = $totalRow;
    $layout['valueLabels'] = array_map(static fn($m) => $m[0], $valueMeta);
    return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'layout' => $layout];
}

function tegh_rb_run(array $user, array $company, array $spec, ?array $saved = null): array
{
    tegh_require_feature($user, $company, 'core.accounting');
    $s = tegh_rb_sources()[$spec['source']];
    $pdo = db();
    if ($pdo->inTransaction()) throw new LogicException('Report snapshots require an independent read transaction.');
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->beginTransaction();
        $built = tegh_rb_build($company, $spec);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $title = $spec['title'] !== '' ? $spec['title'] : ($saved['name'] ?? $s['label']);
    $definition = ['key' => 'report-builder', 'version' => TEGH_RB_VERSION, 'title' => $title, 'loader' => 'report_builder', 'periodMode' => $s['date'] !== null ? 'range' : 'current', 'orientation' => 'landscape'];
    $period = $spec['period'] ?? ['start' => canadian_today(), 'end' => canadian_today()];
    $p = ['start' => $period['start'], 'end' => $period['end'], 'asOf' => $period['end'], 'status' => 'all', 'source' => $s['label'],
        'rows' => implode(', ', array_map(static fn($k) => $s['fields'][$k][0], $spec['rows'])),
        'columnsSideBySide' => $spec['columns'] === null ? 'None' : (str_starts_with($spec['columns'], 'period:') ? ucfirst(substr($spec['columns'], 7) === 'fy' ? 'financial year' : substr($spec['columns'], 7)) : $s['fields'][$spec['columns']][0]),
        'filtersApplied' => tegh_rb_filter_text($s, $spec), 'savedReportId' => (string)($saved['id'] ?? '')];
    $model = tegh_report_model_5980($definition, $company, $p, $built['columns'], $built['rows'], $built['totals']);
    if ($s['date'] === null) $model['period'] = ['mode' => 'asOf', 'start' => null, 'end' => null, 'asOf' => canadian_today(), 'inclusive' => true];
    $model['postingScope'] = $spec['source'] === 'gl' ? 'Posted journal entries only' : 'All records of the chosen type, filtered as shown';
    $model['currencyQualifications'] = 'Amounts are in ' . $company['currency'] . ' (the company currency). Foreign-currency documents are included at their recorded ' . $company['currency'] . ' amounts.';
    $model['reportBuilder'] = ['source' => $spec['source'], 'layout' => $built['layout'], 'spec' => $spec];
    $out = tegh_report_finalize_5980($user, $company, $definition, $p, $model);
    $out['definitionKey'] = 'report-builder';
    return $out;
}

/* ---------- Saved reports ---------- */

function tegh_rb_table_require(): void
{
    if (schema_table_exists('report_builder_reports')) return;
    db()->exec("CREATE TABLE IF NOT EXISTS report_builder_reports (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      owner_user_id VARCHAR(64) NOT NULL,
      name VARCHAR(120) NOT NULL,
      description VARCHAR(500) NOT NULL DEFAULT '',
      visibility VARCHAR(16) NOT NULL DEFAULT 'private',
      definition_json JSON NOT NULL,
      definition_version VARCHAR(32) NOT NULL,
      revision INT NOT NULL DEFAULT 1,
      operation_key VARCHAR(128) NOT NULL,
      deleted_at DATETIME NULL,
      deleted_by VARCHAR(64) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY report_builder_operation_uq (company_id, operation_key),
      KEY report_builder_company (company_id, deleted_at, visibility),
      CONSTRAINT report_builder_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tegh_rb_saved_public(array $r, array $user, array $company): array
{
    $mine = (string)$r['owner_user_id'] === (string)$user['id'];
    return ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'description' => (string)$r['description'], 'visibility' => (string)$r['visibility'],
        'owner' => (string)($r['owner_name'] ?? ''), 'mine' => $mine, 'revision' => (int)$r['revision'], 'updatedAt' => (string)$r['updated_at'],
        'definition' => json_decode((string)$r['definition_json'], true),
        'canEdit' => $mine, 'canDelete' => $mine || ((string)$r['visibility'] === 'company' && in_array((string)$company['role'], ['owner', 'admin'], true))];
}

function tegh_rb_saved_find(array $user, array $company, string $id): array
{
    if (!schema_table_exists('report_builder_reports')) fail('That report was not found.', 404, 'report_builder_not_found');
    $q = db()->prepare("SELECT r.*, COALESCE(NULLIF(u.display_name,''),u.email) owner_name FROM report_builder_reports r LEFT JOIN users u ON u.id=r.owner_user_id WHERE r.id=? AND r.company_id=? AND r.deleted_at IS NULL AND (r.visibility='company' OR r.owner_user_id=?)");
    $q->execute([$id, (string)$company['id'], (string)$user['id']]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('That report was not found.', 404, 'report_builder_not_found');
    return $r;
}

function tegh_rb_save_input(array $user, array $company, array $input): array
{
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) fail('Give the report a name of up to 120 characters.', 422, 'report_builder_name_invalid');
    $description = mb_substr(trim((string)($input['description'] ?? '')), 0, 500);
    $visibility = (string)($input['visibility'] ?? 'private');
    if (!in_array($visibility, ['private', 'company'], true)) fail('Choose private or shared with the company.', 422, 'report_builder_visibility_invalid');
    $spec = tegh_rb_validate_spec($company, $user, $input['definition'] ?? null);
    return [$name, $description, $visibility, $spec];
}

function handle_report_builder_r184(string $sub): never
{
    $user = require_user(); $company = require_company($user);
    tegh_rb_require_available($user);
    require_company_permission($company, 'reports.view');
    $method = request_method();
    try {
        if ($sub === 'catalogue') { require_method('GET'); json_response(tegh_rb_catalogue_public($company, $user)); }
        if ($sub === 'run') {
            require_method('POST'); require_csrf(); $input = request_json();
            $saved = null;
            if (($input['reportId'] ?? '') !== '') { $saved = tegh_rb_saved_find($user, $company, clean_text($input['reportId'], 'Report', 64)); }
            $raw = $input['definition'] ?? ($saved ? json_decode((string)$saved['definition_json'], true) : null);
            if ($saved && isset($input['period']) && is_array($raw)) $raw['period'] = $input['period'];
            $spec = tegh_rb_validate_spec($company, $user, $raw);
            $out = tegh_service_boundary(fn() => tegh_rb_run($user, $company, $spec, $saved));
            json_response(['output' => $out]);
        }
        if ($sub === 'values') {
            require_method('GET');
            $all = tegh_rb_allowed_sources($company, $user); $sk = (string)($_GET['source'] ?? ''); $fk = (string)($_GET['field'] ?? '');
            if (!isset($all[$sk])) fail('Choose what the report is about.', 422, 'report_builder_source_invalid');
            $s = $all[$sk];
            if (!isset($s['fields'][$fk]) || $s['fields'][$fk][2] !== 'text' || $s['fields'][$fk][3] !== 'dim') fail('Values can be listed only for text fields.', 422, 'report_builder_field_invalid');
            $params = array_fill(0, substr_count($s['from'], '?'), (string)$company['id']); $params[] = (string)$company['id'];
            $expr = $s['fields'][$fk][1]; $search = trim((string)($_GET['q'] ?? ''));
            $sql = "SELECT DISTINCT $expr v FROM {$s['from']} WHERE {$s['company']}=?" . ($s['where'] !== '' ? " AND {$s['where']}" : '');
            if ($search !== '') { if (mb_strlen($search) > 100) fail('Search is limited to 100 characters.', 422, 'report_builder_filter_invalid'); $sql .= " AND $expr LIKE ? ESCAPE '!'"; $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%'; }
            $q = db()->prepare($sql . ' ORDER BY v LIMIT 201'); $q->execute($params); $vals = $q->fetchAll(PDO::FETCH_COLUMN);
            json_response(['values' => array_map(static fn($v) => ['value' => (string)$v, 'label' => tegh_rb_label($fk, $v)], array_slice($vals, 0, 200)), 'more' => count($vals) > 200]);
        }
        if ($sub === 'reports') {
            if ($method === 'GET') {
                if (!schema_table_exists('report_builder_reports')) json_response(['reports' => []]);
                $q = db()->prepare("SELECT r.*, COALESCE(NULLIF(u.display_name,''),u.email) owner_name FROM report_builder_reports r LEFT JOIN users u ON u.id=r.owner_user_id WHERE r.company_id=? AND r.deleted_at IS NULL AND (r.visibility='company' OR r.owner_user_id=?) ORDER BY r.name, r.created_at");
                $q->execute([(string)$company['id'], (string)$user['id']]);
                json_response(['reports' => array_map(static fn($r) => tegh_rb_saved_public($r, $user, $company), $q->fetchAll(PDO::FETCH_ASSOC))]);
            }
            require_method('POST'); require_csrf(); $input = request_json();
            $key = trim((string)($input['operationKey'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{16,120}$/D', $key)) fail('A stable operation key is required.', 422, 'operation_key_invalid');
            [$name, $description, $visibility, $spec] = tegh_rb_save_input($user, $company, $input);
            tegh_rb_table_require();
            $json = json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $pdo = db(); $pdo->beginTransaction();
            try {
                $q = $pdo->prepare('SELECT * FROM report_builder_reports WHERE company_id=? AND operation_key=? FOR UPDATE'); $q->execute([(string)$company['id'], $key]);
                if ($prior = $q->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->commit();
                    if ((string)$prior['owner_user_id'] !== (string)$user['id'] || (string)$prior['name'] !== $name || json_decode((string)$prior['definition_json'], true) != $spec) fail('That operation key was already used for a different report.', 409, 'idempotency_payload_conflict');
                    $r = tegh_rb_saved_find($user, $company, (string)$prior['id']);
                    json_response(['report' => tegh_rb_saved_public($r, $user, $company), 'idempotentReplay' => true]);
                }
                $id = new_id('rbr');
                $pdo->prepare('INSERT INTO report_builder_reports (id,company_id,owner_user_id,name,description,visibility,definition_json,definition_version,operation_key) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$id, (string)$company['id'], (string)$user['id'], $name, $description, $visibility, $json, TEGH_RB_VERSION, $key]);
                audit_event($user, (string)$company['id'], 'report_builder.saved', 'report_builder_report', $id, ['name' => $name, 'visibility' => $visibility, 'source' => $spec['source'], 'accountingWrites' => 0]);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            json_response(['report' => tegh_rb_saved_public(tegh_rb_saved_find($user, $company, $id), $user, $company)], 201);
        }
        if (str_starts_with($sub, 'reports/')) {
            $id = clean_text(substr($sub, 8), 'Report', 64);
            $r = tegh_rb_saved_find($user, $company, $id);
            if ($method === 'GET') json_response(['report' => tegh_rb_saved_public($r, $user, $company)]);
            require_csrf();
            if ($method === 'PUT' || $method === 'PATCH') {
                $input = request_json();
                if ((string)$r['owner_user_id'] !== (string)$user['id']) fail('Only the person who created this report can change it. Save a copy under your own name instead.', 403, 'report_builder_not_owner');
                if ((int)($input['revision'] ?? 0) !== (int)$r['revision']) fail('This report was changed since you opened it. Open it again.', 409, 'report_builder_revision_conflict');
                [$name, $description, $visibility, $spec] = tegh_rb_save_input($user, $company, $input);
                $json = json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $u = db()->prepare('UPDATE report_builder_reports SET name=?,description=?,visibility=?,definition_json=?,definition_version=?,revision=revision+1 WHERE id=? AND company_id=? AND revision=? AND deleted_at IS NULL');
                $u->execute([$name, $description, $visibility, $json, TEGH_RB_VERSION, $id, (string)$company['id'], (int)$r['revision']]);
                if ($u->rowCount() !== 1) fail('This report was changed since you opened it. Open it again.', 409, 'report_builder_revision_conflict');
                audit_event($user, (string)$company['id'], 'report_builder.updated', 'report_builder_report', $id, ['name' => $name, 'visibility' => $visibility, 'source' => $spec['source'], 'accountingWrites' => 0]);
                json_response(['report' => tegh_rb_saved_public(tegh_rb_saved_find($user, $company, $id), $user, $company)]);
            }
            if ($method === 'DELETE') {
                if (!tegh_rb_saved_public($r, $user, $company)['canDelete']) fail('Only the person who created this report, or a company owner or admin for a shared report, can delete it.', 403, 'report_builder_not_owner');
                db()->prepare('UPDATE report_builder_reports SET deleted_at=UTC_TIMESTAMP(),deleted_by=? WHERE id=? AND company_id=? AND deleted_at IS NULL')->execute([(string)$user['id'], $id, (string)$company['id']]);
                audit_event($user, (string)$company['id'], 'report_builder.deleted', 'report_builder_report', $id, ['name' => (string)$r['name'], 'accountingWrites' => 0]);
                json_response(['deleted' => true]);
            }
            fail('Method not allowed.', 405, 'method_not_allowed');
        }
        fail('Not found.', 404, 'not_found');
    } catch (Throwable $error) {
        if (function_exists('tegh_fail_service')) tegh_fail_service($error);
        throw $error;
    }
}
