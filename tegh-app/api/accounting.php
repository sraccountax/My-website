<?php
declare(strict_types=1);

function canadian_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Toronto')))->format('Y-m-d');
}

function assert_not_future_date(string $date, string $label): void
{
    // A browser can legitimately submit its local calendar date while the
    // Toronto and UTC calendars straddle midnight. Use the later of those two
    // authoritative server dates so a same-date source/match is not rejected
    // solely because of that timezone boundary. Dates beyond both remain
    // prohibited.
    $latestCurrentDate = max(canadian_today(), gmdate('Y-m-d'));
    if ($date > $latestCurrentDate) {
        fail($label . ' cannot be in the future.');
    }
}

function assert_customer_invoice_on_or_after_books_start(array $company, string $issueDate): void
{
    $booksStart=trim((string)($company['books_start_date']??''));
    if($booksStart!==''&&$issueDate<$booksStart){
        fail(
            'Customer invoices dated before '.$booksStart.' must be entered through Data Import → Opening Customer Invoices.',
            409,
            'invoice_before_books_start',
            false
        );
    }
}

function fiscal_period_start(string $today, string $fiscalYearEnd): string
{
    $year = (int)substr($today, 0, 4);
    $currentEnd = sprintf('%04d-%s', $year, $fiscalYearEnd);
    $end = $today > $currentEnd
        ? new DateTimeImmutable($currentEnd, new DateTimeZone('UTC'))
        : new DateTimeImmutable(sprintf('%04d-%s', $year - 1, $fiscalYearEnd), new DateTimeZone('UTC'));
    return $end->modify('+1 day')->format('Y-m-d');
}

function account_by_code(string $companyId, string $code): string
{
    $stmt = db()->prepare('SELECT id FROM accounts WHERE company_id = ? AND code = ? AND active = 1 LIMIT 1');
    $stmt->execute([$companyId, $code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException('Required ledger account ' . $code . ' is unavailable.');
    }
    return (string)$id;
}

function company_account(string $companyId, string $accountId): ?array
{
    $stmt = db()->prepare('SELECT id, code, name, account_type, normal_balance, is_control FROM accounts WHERE id = ? AND company_id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$accountId, $companyId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function calculate_tax_components(int $inputCents, string $mode, int $gstHstRateBps, int $pstRateBps = 0): array
{
    if ($inputCents < 0) fail('The tax amount cannot be negative.');
    if (!in_array($mode, ['none','exclusive','inclusive'], true)) fail('Choose a valid tax entry mode.');
    foreach (['GST/HST'=>$gstHstRateBps,'PST'=>$pstRateBps] as $label=>$rate) {
        if ($rate < 0 || $rate > 2500) fail($label.' rate must be between 0% and 25%.');
    }
    if ($mode === 'none' || ($gstHstRateBps + $pstRateBps) === 0) {
        return ['netCents'=>$inputCents,'gstHstCents'=>0,'pstCents'=>0,'taxCents'=>0,'grossCents'=>$inputCents,'mode'=>'none'];
    }
    if ($mode === 'exclusive') {
        $gst=(int)round(($inputCents*$gstHstRateBps)/10000);
        $pst=(int)round(($inputCents*$pstRateBps)/10000);
        return ['netCents'=>$inputCents,'gstHstCents'=>$gst,'pstCents'=>$pst,'taxCents'=>$gst+$pst,'grossCents'=>$inputCents+$gst+$pst,'mode'=>'exclusive'];
    }
    $totalRate=$gstHstRateBps+$pstRateBps;
    $net=(int)round(($inputCents*10000)/(10000+$totalRate));
    $totalTax=$inputCents-$net;
    if($totalRate===0){$gst=0;$pst=0;}
    else{
        // Allocate the exact included tax total by rate, assigning the final
        // cent to PST so net + GST/HST + PST always equals the entered gross.
        $gst=$gstHstRateBps===0?0:(int)round(($totalTax*$gstHstRateBps)/$totalRate);
        $pst=$totalTax-$gst;
    }
    return ['netCents'=>$net,'gstHstCents'=>$gst,'pstCents'=>$pst,'taxCents'=>$totalTax,'grossCents'=>$inputCents,'mode'=>'inclusive'];
}

function split_tax_inclusive(int $totalCents, int $rateBps): array
{
    $tax=calculate_tax_components($totalCents,'inclusive',$rateBps,0);
    return ['subtotalCents'=>$tax['netCents'],'taxCents'=>$tax['taxCents']];
}

/** @param array<int,array{accountId:string,debitCents:int,creditCents:int,memo?:string,fxRoundingCents?:int}> $lines */
function journal_entry_content_hash(string $date, string $memo, array $lines): string
{
    $canonical = array_map(static fn(array $line): array => [
        'accountId'=>(string)($line['accountId'] ?? $line['account_id'] ?? ''),
        'debitCents'=>(int)($line['debitCents'] ?? $line['debit_cents'] ?? 0),
        'creditCents'=>(int)($line['creditCents'] ?? $line['credit_cents'] ?? 0),
        'memo'=>mb_substr((string)($line['memo'] ?? ''),0,500),
        'fxRoundingCents'=>(int)($line['fxRoundingCents'] ?? $line['fx_rounding_cents'] ?? 0),
    ], $lines);
    usort($canonical, static fn(array $left,array $right): int => strcmp(
        json_encode($left,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        json_encode($right,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
    ));
    return hash('sha256',json_encode([
        'entryDate'=>$date,
        'memo'=>mb_substr($memo,0,500),
        'lines'=>$canonical,
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function company_materiality_threshold_cents(string $companyId): int
{
    $configured=(int)(config('accounting.materiality_threshold_cents') ?? 1_000_000);
    $threshold=$configured>0?$configured:1_000_000;
    if(function_exists('schema_column_exists')&&schema_column_exists('accounting_controls','materiality_threshold_cents')){
        $stmt=db()->prepare('SELECT materiality_threshold_cents FROM accounting_controls WHERE company_id=? LIMIT 1');
        $stmt->execute([$companyId]);$value=$stmt->fetchColumn();
        if($value!==false&&(int)$value>0)$threshold=(int)$value;
    }
    return max(1,min(100_000_000_000,$threshold));
}

/** @return array{checked:int,mismatches:array<int,array{id:string,expected:string,actual:string}>} */
function journal_entry_integrity_scan(string $companyId,int $limit=5000,bool $reportIncident=true): array
{
    $limit=max(1,min(5000,$limit));
    $stmt=db()->prepare('SELECT id,entry_date,memo,content_hash FROM journal_entries WHERE company_id=? ORDER BY created_at,id LIMIT '.$limit);
    $stmt->execute([$companyId]);$entries=$stmt->fetchAll();$mismatches=[];
    $lines=db()->prepare('SELECT account_id,debit_cents,credit_cents,memo,fx_rounding_cents FROM journal_lines WHERE journal_entry_id=? ORDER BY id');
    foreach($entries as $entry){
        $lines->execute([(string)$entry['id']]);
        $expected=journal_entry_content_hash((string)$entry['entry_date'],(string)$entry['memo'],$lines->fetchAll());
        $actual=(string)($entry['content_hash']??'');
        if($actual===''||!hash_equals($expected,$actual))$mismatches[]=['id'=>(string)$entry['id'],'expected'=>$expected,'actual'=>$actual];
    }
    if($reportIncident&&$mismatches&&function_exists('record_system_incident'))record_system_incident(
        'Journal integrity verification found stored content that no longer matches its posting hash.',
        500,'journal_content_hash_mismatch',null,
        ['source'=>'nightly_journal_integrity','companyId'=>$companyId,'mismatchCount'=>count($mismatches),'journalEntryIds'=>array_column($mismatches,'id')]
    );
    return ['checked'=>count($entries),'mismatches'=>$mismatches];
}

/** @param array<int,array{accountId:string,debitCents:int,creditCents:int,memo?:string,fxRoundingCents?:int}> $lines */
function add_journal_entry(array $user, string $companyId, string $date, string $sourceType, string $sourceId, string $memo, array $lines, ?string $reversalOfId = null, bool $approvalEligible = false): string
{
    if (count($lines) < 2 || count($lines) > 500) {
        fail('A journal entry must contain between 2 and 500 lines.');
    }
    $debits = 0;
    $credits = 0;
    $accountIds = [];
    foreach ($lines as $line) {
        $debit = (int)($line['debitCents'] ?? 0);
        $credit = (int)($line['creditCents'] ?? 0);
        if ($debit < 0 || $credit < 0 || (($debit > 0) === ($credit > 0))) {
            fail('Each journal line must contain exactly one positive debit or credit.');
        }
        $accountId = (string)($line['accountId'] ?? '');
        if ($accountId === '') {
            fail('Every journal line requires an account.');
        }
        $debits += $debit;
        $credits += $credit;
        $accountIds[$accountId] = true;
    }
    if ($debits <= 0 || $debits !== $credits) {
        fail('Journal entry debits and credits must balance exactly.');
    }
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $params = array_merge([$companyId], array_keys($accountIds));
    $stmt = db()->prepare("SELECT id,code,is_control FROM accounts WHERE company_id = ? AND active = 1 AND id IN ($placeholders)");
    $stmt->execute($params);
    $validRows=$stmt->fetchAll();
    if (count($validRows) !== count($accountIds)) fail('One or more journal accounts are invalid.');
    $systemAllowedSources=['opening_balance','opening_balance_import','customer_opening_balance','vendor_opening_balance','opening_customer_invoices','opening_vendor_bills','opening_invoice_void','opening_bill_void'];
    if ($reversalOfId === null && !in_array($sourceType,$systemAllowedSources,true)) {
        foreach ($validRows as $accountRow) {
            if ((string)$accountRow['code']==='9999' || (function_exists('tegh_account_is_opening_balance_control') && tegh_account_is_opening_balance_control($companyId,(string)$accountRow['id']))) {
                fail('Opening Balance Control account 9999 cannot be selected for this transaction.',409,'system_account_protected');
            }
        }
    }
    assert_period_open($companyId, $date, $lines);
    $entryId = new_id('journal');
    $entryMemo=mb_substr($memo,0,500);
    $contentHash=journal_entry_content_hash($date,$entryMemo,$lines);
    $requiresApproval=$approvalEligible&&$debits>company_materiality_threshold_cents($companyId);
    $status=$requiresApproval?'pending_post':'posted';
    db()->prepare('INSERT INTO journal_entries (id,company_id,entry_date,source_type,source_id,memo,status,reversal_of_id,content_hash,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$entryId,$companyId,$date,$sourceType,$sourceId,$entryMemo,$status,$reversalOfId,$contentHash,$user['id']]);
    $lineStmt = db()->prepare('INSERT INTO journal_lines (id,journal_entry_id,account_id,debit_cents,credit_cents,memo,fx_rounding_cents) VALUES (?,?,?,?,?,?,?)');
    foreach ($lines as $line) {
        $lineStmt->execute([
            new_id('jline'), $entryId, $line['accountId'], (int)$line['debitCents'], (int)$line['creditCents'],
            mb_substr((string)($line['memo'] ?? ''), 0, 500),(int)($line['fxRoundingCents']??0),
        ]);
    }
    if($requiresApproval){
        db()->prepare("INSERT INTO journal_approvals (id,company_id,journal_entry_id,source_type,source_id,amount_cents,status,payload_hash,prepared_by,submitted_at) VALUES (?,?,?,?,?,?,'submitted',?,?,UTC_TIMESTAMP())")
            ->execute([new_id('japproval'),$companyId,$entryId,$sourceType,$sourceId,$debits,$contentHash,$user['id']]);
    }
    return $entryId;
}

function add_reversing_journal_entry(array $user, string $companyId, string $originalEntryId, string $date, string $sourceType, string $sourceId, string $memo): string
{
    $stmt = db()->prepare("SELECT jl.account_id, jl.debit_cents, jl.credit_cents, jl.memo, jl.fx_rounding_cents
        FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE je.id = ? AND je.company_id = ? AND je.status = 'posted' ORDER BY jl.id");
    $stmt->execute([$originalEntryId, $companyId]);
    $rows = $stmt->fetchAll();
    if (count($rows) < 2) {
        fail('The original journal entry is unavailable for reversal.', 409, 'journal_reversal_unavailable');
    }
    $lines = array_map(static fn(array $row): array => [
        'accountId' => (string)$row['account_id'],
        'debitCents' => (int)$row['credit_cents'],
        'creditCents' => (int)$row['debit_cents'],
        'memo' => 'Reversal: ' . (string)$row['memo'],
        'fxRoundingCents' => -(int)($row['fx_rounding_cents'] ?? 0),
    ], $rows);
    return add_journal_entry($user, $companyId, $date, $sourceType, $sourceId, $memo, $lines, $originalEntryId);
}

/**
 * Opening documents share one immutable cutover journal, so reversing that
 * whole journal would also erase unrelated customer/vendor balances. Reverse
 * only this document's remaining control-account amount into Opening Balance
 * Control and retain the cutover batch unchanged for audit reconstruction.
 */
function add_opening_document_void_journal(array $user,string $companyId,string $documentType,array $document,string $date): string
{
    if(!in_array($documentType,['invoice','bill'],true))throw new InvalidArgumentException('Unsupported opening document type.');
    $amount=(int)($document['balance_cents']??0);
    if($amount<=0)fail('The opening document has no remaining balance to reverse.',409,'opening_document_balance_unavailable');
    $control=account_by_code($companyId,'9999');
    if($documentType==='invoice'){
        $lines=[
            ['accountId'=>$control,'debitCents'=>$amount,'creditCents'=>0,'memo'=>'Reverse opening customer invoice'],
            ['accountId'=>account_by_code($companyId,'1200'),'debitCents'=>0,'creditCents'=>$amount,'memo'=>'Remove opening receivable'],
        ];
    }else{
        $lines=[
            ['accountId'=>account_by_code($companyId,'2050'),'debitCents'=>$amount,'creditCents'=>0,'memo'=>'Remove opening payable'],
            ['accountId'=>$control,'debitCents'=>0,'creditCents'=>$amount,'memo'=>'Reverse opening vendor invoice'],
        ];
    }
    $number=(string)($document['number']??$document['id']??'');
    return add_journal_entry($user,$companyId,$date,'opening_'.$documentType.'_void',(string)$document['id'],'Void opening '.($documentType==='invoice'?'invoice ':'vendor invoice ').$number,$lines);
}

/**
 * Return the active bank-reconciliation match that currently owns a posted
 * book-side journal. A matched item must be explicitly unmatched/reopened
 * before the underlying accounting transaction can be reversed or deleted.
 */
function active_bank_match_for_journal(string $companyId, string $journalEntryId): ?string
{
    if ($journalEntryId === '' || !schema_table_exists('bank_match_book_items') || !schema_table_exists('bank_match_groups')) return null;
    $stmt = db()->prepare("SELECT g.id FROM bank_match_book_items bi
        JOIN bank_match_groups g ON g.id = bi.match_group_id
        WHERE bi.journal_entry_id = ? AND g.company_id = ? AND g.status = 'matched'
        ORDER BY g.matched_at DESC LIMIT 1");
    $stmt->execute([$journalEntryId, $companyId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (string)$id;
}

/** Active match for a bank-side imported/manual transaction. */
function active_bank_match_for_bank_transaction(string $companyId, string $bankTransactionId, bool $lock = false): ?string
{
    if ($bankTransactionId === '' || !schema_table_exists('bank_match_bank_items') || !schema_table_exists('bank_match_groups')) return null;
    $stmt = db()->prepare("SELECT g.id FROM bank_match_bank_items bi
        JOIN bank_match_groups g ON g.id = bi.match_group_id
        WHERE bi.bank_transaction_id = ? AND g.company_id = ? AND g.status = 'matched'
        ORDER BY g.matched_at DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$bankTransactionId, $companyId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (string)$id;
}

function require_journal_unmatched_before_reversal(string $companyId, string $journalEntryId, string $label = 'transaction'): void
{
    if (active_bank_match_for_journal($companyId, $journalEntryId) !== null) {
        fail('This '.$label.' is matched in Bank Reconciliation. Unreconcile or unmatch it before reversing it.', 409, 'bank_reconciliation_unmatch_required');
    }
}


function workspace_data(array $user, array $company): array
{
    $companyId = (string)$company['id'];
    $today = canadian_today();
    $periodStart = fiscal_period_start($today, (string)$company['fiscal_year_end']);

    $stmt = db()->prepare("SELECT a.*, COALESCE(b.signed_balance_cents, 0) AS signed_balance_cents,
        COALESCE(b.period_signed_balance_cents, 0) AS period_signed_balance_cents
        FROM accounts a LEFT JOIN (
          SELECT jl.account_id,
            SUM(CASE WHEN je.status = 'posted' AND je.entry_date <= ? THEN jl.debit_cents - jl.credit_cents ELSE 0 END) AS signed_balance_cents,
            SUM(CASE WHEN je.status = 'posted' AND je.entry_date BETWEEN ? AND ? THEN jl.debit_cents - jl.credit_cents ELSE 0 END) AS period_signed_balance_cents
          FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
          WHERE je.company_id = ? GROUP BY jl.account_id
        ) b ON b.account_id = a.id WHERE a.company_id = ? ORDER BY a.code");
    $stmt->execute([$today, $periodStart, $today, $companyId, $companyId]);
    $accountRows = $stmt->fetchAll();
    $accounts = [];
    $accountById = [];
    $accountByCode = [];
    foreach ($accountRows as $row) {
        $signed = (int)$row['signed_balance_cents'];
        $periodSigned = (int)$row['period_signed_balance_cents'];
        $normal = (string)$row['normal_balance'];
        $mapped = [
            'id' => (string)$row['id'], 'code' => (string)$row['code'], 'name' => (string)$row['name'],
            'description'=>$row['description']!==null?(string)$row['description']:null,'systemControl'=>((string)$row['code']==='9999' && (bool)$row['is_control']),
            'type' => (string)$row['account_type'], 'normalBalance' => $normal,
            'isControl' => (bool)$row['is_control'], 'active' => (bool)$row['active'],
            'balanceCents' => $normal === 'debit' ? $signed : -$signed,
            'periodBalanceCents' => $normal === 'debit' ? $periodSigned : -$periodSigned,
        ];
        $accounts[] = $mapped;
        $accountById[$mapped['id']] = $mapped;
        $accountByCode[$mapped['code']] = $mapped;
    }

    $invoiceTemplates = invoice_templates_for_company($company);
    $templateById = [];
    foreach ($invoiceTemplates as $template) $templateById[(string)$template['id']] = $template;
    $defaultTemplate = $invoiceTemplates[0] ?? null;

    $stmt = db()->prepare('SELECT c.id,c.name,c.contact_name,c.email,c.phone,c.billing_address,c.address_line1,c.address_line2,c.city,c.province,c.postal_code,c.country,c.default_terms_days,c.notes,c.status,c.hold_remarks,c.hold_at,c.release_remarks,c.released_at,c.active,
        pob.amount_cents AS opening_balance_cents,pob.effective_date AS opening_balance_date,vx.voucher_number AS opening_voucher_number,
        EXISTS(SELECT 1 FROM invoices i WHERE i.company_id=c.company_id AND i.customer_id=c.id) AS locked
        FROM customers c
        LEFT JOIN party_opening_balances pob ON pob.company_id=c.company_id AND pob.party_type=\'customer\' AND pob.party_id=c.id
        LEFT JOIN vouchers vx ON vx.id=pob.voucher_id
        WHERE c.company_id = ? ORDER BY CASE c.status WHEN \'active\' THEN 0 WHEN \'on_hold\' THEN 1 ELSE 2 END,c.name');
    $stmt->execute([$companyId]);
    $customers = array_map(static fn(array $row): array => [
        'id'=>(string)$row['id'],'name'=>(string)$row['name'],'contactName'=>$row['contact_name']!==null?(string)$row['contact_name']:null,
        'email'=>$row['email']!==null?(string)$row['email']:null,'phone'=>$row['phone']!==null?(string)$row['phone']:null,
        'billingAddress'=>$row['billing_address']!==null?(string)$row['billing_address']:null,'addressLine1'=>$row['address_line1']!==null?(string)$row['address_line1']:null,
        'addressLine2'=>$row['address_line2']!==null?(string)$row['address_line2']:null,'city'=>$row['city']!==null?(string)$row['city']:null,
        'province'=>$row['province']!==null?(string)$row['province']:null,'postalCode'=>$row['postal_code']!==null?(string)$row['postal_code']:null,
        'country'=>(string)($row['country']??'Canada'),'defaultTermsDays'=>(int)($row['default_terms_days']??30),'paymentTerms'=>tegh_terms_label((int)($row['default_terms_days']??30)),
        'notes'=>$row['notes']!==null?(string)$row['notes']:null,'status'=>(string)($row['status']??((bool)$row['active']?'active':'inactive')),
        'holdRemarks'=>$row['hold_remarks']!==null?(string)$row['hold_remarks']:null,'holdAt'=>$row['hold_at']!==null?(string)$row['hold_at']:null,
        'releaseRemarks'=>$row['release_remarks']!==null?(string)$row['release_remarks']:null,'releasedAt'=>$row['released_at']!==null?(string)$row['released_at']:null,
        'active'=>(bool)$row['active'],'openingBalanceCents'=>(int)($row['opening_balance_cents']??0),'openingBalanceDate'=>$row['opening_balance_date']!==null?(string)$row['opening_balance_date']:null,
        'openingVoucherNumber'=>$row['opening_voucher_number']!==null?(string)$row['opening_voucher_number']:null,'locked'=>(bool)$row['locked'],
    ], $stmt->fetchAll());

    // Native invoice editors consume the product/service catalogue directly.
    // Keeping this in the workspace payload avoids timing-dependent DOM overlays
    // and lets invoice lines persist an actual product/service link.
    $stmt = db()->prepare("SELECT ps.id,ps.kind,ps.code,ps.name,ps.description,ps.unit_price_cents,ps.taxable,ps.income_account_id,ps.active,
        a.code AS account_code,a.name AS account_name
        FROM products_services ps
        LEFT JOIN accounts a ON a.id=ps.income_account_id
        WHERE ps.company_id=? AND ps.active=1
        ORDER BY ps.kind,ps.name");
    $stmt->execute([$companyId]);
    $productsServices = array_map(static fn(array $row): array => [
        'id'=>(string)$row['id'], 'kind'=>(string)$row['kind'],
        'code'=>$row['code'] !== null ? (string)$row['code'] : null,
        'name'=>(string)$row['name'],
        'description'=>$row['description'] !== null ? (string)$row['description'] : null,
        'unitPriceCents'=>(int)$row['unit_price_cents'], 'taxable'=>(bool)$row['taxable'],
        'incomeAccountId'=>$row['income_account_id'] !== null ? (string)$row['income_account_id'] : null,
        'incomeAccount'=>$row['account_code'] !== null ? ((string)$row['account_code'].' · '.(string)$row['account_name']) : null,
        'active'=>(bool)$row['active'],
    ], $stmt->fetchAll());

    $stmt = db()->prepare("SELECT il.* FROM invoice_lines il
        JOIN invoices i ON i.id = il.invoice_id WHERE i.company_id = ?
        ORDER BY il.invoice_id, il.sort_order, il.id");
    $stmt->execute([$companyId]);
    $invoiceLines = [];
    foreach ($stmt->fetchAll() as $row) {
        $invoiceLines[(string)$row['invoice_id']][] = [
            'id' => (string)$row['id'],
            'description' => (string)$row['description'],
            'quantityMilli' => (int)$row['quantity_milli'],
            'unitPriceCents' => (int)$row['unit_price_cents'],
            'taxRateBps' => (int)$row['tax_rate_bps'],
            'amountCents' => (int)$row['amount_cents'],
            'taxCents' => (int)$row['tax_cents'],
            'foreignUnitPriceCents' => (int)$row['foreign_unit_price_cents'],
            'foreignAmountCents' => (int)$row['foreign_amount_cents'],
            'foreignTaxCents' => (int)$row['foreign_tax_cents'],
            'sortOrder' => (int)$row['sort_order'],
            'productServiceId' => $row['product_service_id'] ?? null,
            'incomeAccountId' => $row['income_account_id'] ?? null,
        ];
    }

    $stmt = db()->prepare("SELECT i.*, c.name AS customer_name, c.email AS customer_email,
        c.phone AS customer_phone, c.billing_address AS customer_address, c.province AS customer_province, COALESCE(vx.voucher_number,ovx.voucher_number) AS voucher_number, odi.journal_entry_id AS opening_journal_entry_id
        FROM invoices i JOIN customers c ON c.id = i.customer_id
        LEFT JOIN vouchers vx ON vx.company_id=i.company_id AND vx.source_type='invoice' AND vx.source_id=i.id
        LEFT JOIN opening_document_imports odi ON odi.id=i.opening_import_id AND odi.company_id=i.company_id
        LEFT JOIN vouchers ovx ON ovx.company_id=i.company_id AND ovx.source_type='opening_customer_invoices' AND ovx.source_id=i.opening_import_id
        WHERE i.company_id = ? ORDER BY i.issue_date DESC, i.number DESC");
    $stmt->execute([$companyId]);
    $invoices = array_map(static function (array $row) use ($today, $invoiceLines, $templateById, $defaultTemplate, $company): array {
        $status = (string)$row['status'];
        $display = $status === 'sent' && (string)$row['due_date'] < $today && (int)$row['balance_cents'] > 0
            ? 'Overdue' : ($status === 'draft' ? 'Draft' : ($status === 'sent' ? 'Sent' : ($status === 'paid' ? 'Paid' : 'Void')));
        $currentTemplate = $templateById[(string)($row['template_id'] ?? '')] ?? $defaultTemplate;
        $templateFallback = $currentTemplate !== null
            ? invoice_template_snapshot_from_mapped($currentTemplate, $company)
            : ['documentTitle' => 'INVOICE', 'accentColor' => '#C94F2D', 'businessName' => (string)$company['name']];
        $customerFallback = [
            'name' => (string)$row['customer_name'],
            'email' => $row['customer_email'] !== null ? (string)$row['customer_email'] : null,
            'phone' => $row['customer_phone'] !== null ? (string)$row['customer_phone'] : null,
            'billingAddress' => $row['customer_address'] !== null ? (string)$row['customer_address'] : null,
            'province' => $row['customer_province'] !== null ? (string)$row['customer_province'] : null,
        ];
        return [
            'id' => (string)$row['id'], 'number' => (string)$row['number'], 'customerId' => (string)$row['customer_id'],
            'customerName' => (string)$row['customer_name'], 'issueDate' => (string)$row['issue_date'], 'dueDate' => (string)$row['due_date'],
            'status' => $status, 'displayStatus' => $display, 'subtotalCents' => (int)$row['subtotal_cents'],
            'taxCents' => (int)$row['tax_cents'], 'totalCents' => (int)$row['total_cents'],
            'balanceCents' => (int)$row['balance_cents'], 'message' => (string)$row['message'],
            'currency' => (string)$row['currency'], 'exchangeRateMicros' => (int)$row['exchange_rate_micros'],
            'foreignSubtotalCents' => (int)$row['foreign_subtotal_cents'], 'foreignTaxCents' => (int)$row['foreign_tax_cents'],
            'foreignTotalCents' => (int)$row['foreign_total_cents'], 'foreignBalanceCents' => (int)$row['foreign_balance_cents'],
            'purchaseOrder' => $row['purchase_order'] !== null ? (string)$row['purchase_order'] : null,
            'templateId' => $row['template_id'] !== null ? (string)$row['template_id'] : null,
            'templateSnapshot' => decode_invoice_snapshot($row['template_snapshot_json'], $templateFallback),
            'customerSnapshot' => decode_invoice_snapshot($row['customer_snapshot_json'], $customerFallback),
            'journalEntryId' => $row['issued_journal_entry_id'] !== null ? (string)$row['issued_journal_entry_id'] : ($row['opening_journal_entry_id']!==null?(string)$row['opening_journal_entry_id']:null),
            'isOpeningDocument'=>(bool)($row['is_opening_document']??false),'openingImportId'=>$row['opening_import_id']!==null?(string)$row['opening_import_id']:null,'originalPaidCents'=>(int)($row['original_paid_cents']??0),
            'isRecurring' => (bool)($row['is_recurring'] ?? false),
            'transactionNumber'=>$row['voucher_number']!==null?(string)$row['voucher_number']:null,
            'lines' => $invoiceLines[(string)$row['id']] ?? [],
        ];
    }, $stmt->fetchAll());

    $stmt = db()->prepare("SELECT e.*, category.name AS category_name, paid.name AS paid_from_name, vx.voucher_number
        FROM expenses e JOIN accounts category ON category.id = e.category_account_id JOIN accounts paid ON paid.id = e.paid_from_account_id
        LEFT JOIN vouchers vx ON vx.company_id=e.company_id AND vx.source_type='expense' AND vx.source_id=e.id
        WHERE e.company_id = ? AND e.status IN ('posted','void') ORDER BY e.expense_date DESC, e.created_at DESC");
    $stmt->execute([$companyId]);
    $expenses = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'vendor' => (string)$row['vendor'], 'expenseDate' => (string)$row['expense_date'],
        'categoryAccountId' => (string)$row['category_account_id'], 'categoryName' => (string)$row['category_name'],
        'paidFromAccountId' => (string)$row['paid_from_account_id'], 'paidFromName' => (string)$row['paid_from_name'],
        'currency'=>(string)($row['currency']??'CAD'),'exchangeRateMicros'=>(int)($row['exchange_rate_micros']??1000000),
        'foreignSubtotalCents'=>(int)($row['foreign_subtotal_cents']??$row['subtotal_cents']),'foreignGstHstCents'=>(int)($row['foreign_gst_hst_cents']??$row['gst_hst_cents']??0),'foreignPstCents'=>(int)($row['foreign_pst_cents']??$row['pst_cents']??0),'foreignTaxCents'=>(int)($row['foreign_tax_cents']??$row['tax_cents']),'foreignTotalCents'=>(int)($row['foreign_total_cents']??$row['total_cents']),
        'subtotalCents' => (int)$row['subtotal_cents'], 'gstHstCents' => (int)($row['gst_hst_cents'] ?? $row['tax_cents']),
        'pstCents' => (int)($row['pst_cents'] ?? 0), 'taxCents' => (int)$row['tax_cents'],
        'taxEntryMode' => (string)($row['tax_entry_mode'] ?? ((int)$row['tax_cents'] > 0 ? 'exclusive' : 'none')),
        'totalCents' => (int)$row['total_cents'], 'taxCode' => (string)$row['tax_code'],
        'hasReceipt' => $row['receipt_path'] !== null, 'status' => (string)$row['status'],
        'journalEntryId' => $row['journal_entry_id'] !== null ? (string)$row['journal_entry_id'] : null,
        'sourceType' => 'expense', 'sourceId' => (string)$row['id'], 'transactionNumber'=>$row['voucher_number']!==null?(string)$row['voucher_number']:null,
    ], $stmt->fetchAll());
    $expenseEntries = [];
    $expenseEntryStmt = db()->prepare("SELECT journal_entry_id FROM expenses WHERE company_id = ? AND status = 'posted'");
    $expenseEntryStmt->execute([$companyId]);
    foreach ($expenseEntryStmt->fetchAll() as $row) {
        $expenseEntries[(string)$row['journal_entry_id']] = true;
    }

    $stmt = db()->prepare('SELECT * FROM bank_accounts WHERE company_id = ? AND active = 1 ORDER BY account_type, name');
    $stmt->execute([$companyId]);
    $bankAccountRows = $stmt->fetchAll();
    $bankAccounts = [];
    foreach ($bankAccountRows as $row) {
        $ledger = $accountById[(string)$row['ledger_account_id']] ?? null;
        $normalBalance = (int)($ledger['balanceCents'] ?? 0);
        $bankAccounts[] = [
            'id' => (string)$row['id'], 'ledgerAccountId' => (string)$row['ledger_account_id'], 'name' => (string)$row['name'],
            'accountType' => (string)$row['account_type'], 'maskedNumber' => $row['masked_number'] !== null ? (string)$row['masked_number'] : null,
            'currency' => (string)$row['currency'],
            'statementBalanceCents' => (int)$row['statement_balance_cents'],
            'bookBalanceCents' => $row['account_type'] === 'credit_card' ? -$normalBalance : $normalBalance,
            'lastReconciledDate' => $row['last_reconciled_date'] !== null ? (string)$row['last_reconciled_date'] : null,
        ];
    }

    $stmt = db()->prepare("SELECT bt.*, suggested.name AS suggested_account_name, decided.name AS decided_account_name,
        decided.account_type AS decided_account_type, decided.is_control AS decided_account_is_control,
        ba.name AS bank_account_name, ba.ledger_account_id, v.voucher_number AS transaction_number,
        (SELECT pp.id FROM party_payments pp WHERE pp.company_id=bt.company_id AND pp.bank_transaction_id=bt.id AND pp.status='posted' LIMIT 1) AS party_payment_id,
        COALESCE((SELECT SUM(tl.debit_cents - tl.credit_cents) FROM journal_lines tl JOIN accounts ta ON ta.id = tl.account_id WHERE tl.journal_entry_id = bt.journal_entry_id AND ta.code = '1100'), 0) AS posted_tax_cents,
        COALESCE((SELECT SUM(cl.debit_cents - cl.credit_cents) FROM journal_lines cl WHERE cl.journal_entry_id = bt.journal_entry_id AND cl.account_id = bt.decided_account_id), 0) AS posted_category_cents
        FROM bank_transactions bt JOIN bank_accounts ba ON ba.id = bt.bank_account_id
        LEFT JOIN vouchers v ON v.company_id=bt.company_id AND v.source_type='bank_transaction' AND v.source_id=bt.id
        LEFT JOIN accounts suggested ON suggested.id = bt.suggested_account_id
        LEFT JOIN accounts decided ON decided.id = bt.decided_account_id
        WHERE bt.company_id = ? ORDER BY bt.transaction_date DESC, bt.created_at DESC, bt.id DESC LIMIT 5000");
    $stmt->execute([$companyId]);
    $transactionRows = $stmt->fetchAll();
    $bankTransactions = [];
    foreach ($transactionRows as $row) {
        $bankTransactions[] = [
            'id' => (string)$row['id'], 'bankAccountId' => (string)$row['bank_account_id'],
            'transactionDate' => (string)$row['transaction_date'], 'description' => (string)$row['description'], 'reference' => $row['reference'] !== null ? (string)$row['reference'] : null,
            'transactionNumber' => $row['transaction_number'] !== null ? (string)$row['transaction_number'] : null,
            'normalizedMerchant' => $row['normalized_merchant'] !== null ? (string)$row['normalized_merchant'] : null,
            'amountCents' => (int)$row['amount_cents'],
            'currency' => (string)$row['currency'], 'foreignAmountCents' => (int)$row['foreign_amount_cents'],
            'exchangeRateMicros' => (int)$row['exchange_rate_micros'],
            'suggestedAccountId' => $row['suggested_account_id'] !== null ? (string)$row['suggested_account_id'] : null,
            'suggestedAccountName' => $row['suggested_account_name'] !== null ? (string)$row['suggested_account_name'] : null,
            'decidedAccountId' => $row['decided_account_id'] !== null ? (string)$row['decided_account_id'] : null,
            'decidedAccountName' => $row['decided_account_name'] !== null ? (string)$row['decided_account_name'] : null,
            'taxCode' => (string)$row['tax_code'], 'confidence' => (int)$row['confidence'],
            'suggestionSource' => (string)$row['suggestion_source'],
            'aiExplanation' => $row['ai_explanation'] !== null ? (string)$row['ai_explanation'] : null,
            'status' => (string)$row['status'],
            'journalEntryId' => $row['journal_entry_id'] !== null ? (string)$row['journal_entry_id'] : null,
            'matchedPaymentId' => $row['party_payment_id'] !== null ? (string)$row['party_payment_id'] : null,
            'canReassignToDocument' => (string)$row['status'] === 'posted' && $row['journal_entry_id'] !== null
                && $row['party_payment_id'] === null && !(bool)($row['decided_account_is_control'] ?? false),
        ];
        if ($row['status'] === 'posted' && (int)$row['amount_cents'] < 0 && $row['decided_account_type'] === 'expense' && $row['journal_entry_id'] !== null && !isset($expenseEntries[(string)$row['journal_entry_id']])) {
            $total = abs((int)$row['amount_cents']);
            $taxCode = (string)$row['tax_code'];
            $gstRequested = str_contains($taxCode, 'GST_HST') || $taxCode === 'HST13';
            $pstRequested = str_contains($taxCode, 'PST');
            $parts = calculate_tax_components(
                $total,
                ($gstRequested || $pstRequested) ? 'inclusive' : 'none',
                $gstRequested ? (int)$company['tax_rate_bps'] : 0,
                $pstRequested ? (int)($company['pst_rate_bps'] ?? 0) : 0
            );
            $expenses[] = [
                'id' => 'bank:' . $row['id'], 'vendor' => (string)$row['description'], 'expenseDate' => (string)$row['transaction_date'],
                'categoryAccountId' => (string)$row['decided_account_id'], 'categoryName' => (string)$row['decided_account_name'],
                'paidFromAccountId' => (string)$row['ledger_account_id'], 'paidFromName' => (string)$row['bank_account_name'],
                'subtotalCents' => (int)$parts['netCents'], 'gstHstCents' => (int)$parts['gstHstCents'],
                'pstCents' => (int)$parts['pstCents'], 'taxCents' => (int)$parts['taxCents'], 'taxEntryMode' => (string)$parts['mode'],
                'totalCents' => $total, 'taxCode' => $taxCode, 'hasReceipt' => false, 'status' => 'posted',
                'journalEntryId' => (string)$row['journal_entry_id'],
                'sourceType' => 'bank_transaction', 'sourceId' => (string)$row['id'],
            ];
        }
    }
    usort($expenses, static fn(array $a, array $b): int => strcmp($b['expenseDate'], $a['expenseDate']));

    // Legacy workspace consumers receive a bounded preview, never an implied
    // complete register. Counts are independent of that preview's 5,000 rows.
    $bankCountStmt=db()->prepare("SELECT COUNT(*) total_count,COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) pending_count FROM bank_transactions WHERE company_id=?");
    $bankCountStmt->execute([$companyId]);$bankCounts=$bankCountStmt->fetch();
    $bankTransactionsMeta=['scope'=>'Newest company bank transactions','returned'=>count($bankTransactions),'total'=>(int)$bankCounts['total_count'],'pendingTotal'=>(int)$bankCounts['pending_count'],'limit'=>5000,'complete'=>(int)$bankCounts['total_count']<=count($bankTransactions),'completeRegisterRoute'=>'bank-transactions','completeRegisterEndpoint'=>'bank-transactions/list'];

    $stmt = db()->prepare("SELECT ib.*,
        COALESCE(stats.transaction_count, 0) AS transaction_count,
        COALESCE(stats.pending_count, 0) AS pending_count,
        COALESCE(stats.excluded_count, 0) AS excluded_count,
        COALESCE(stats.posted_count, 0) AS posted_count
        FROM import_batches ib
        LEFT JOIN (
            SELECT import_batch_id, COUNT(*) AS transaction_count,
                SUM(status = 'pending') AS pending_count,
                SUM(status = 'excluded') AS excluded_count,
                SUM(status = 'posted') AS posted_count
            FROM bank_transactions WHERE company_id = ? AND import_batch_id IS NOT NULL
            GROUP BY import_batch_id
        ) stats ON stats.import_batch_id = ib.id
        WHERE ib.company_id = ? ORDER BY ib.created_at DESC LIMIT 30");
    $stmt->execute([$companyId, $companyId]);
    $importBatches = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'filename' => (string)$row['filename'], 'fileType' => (string)$row['file_type'],
        'status' => (string)$row['status'], 'rowCount' => (int)$row['row_count'],
        'duplicateCount' => (int)$row['duplicate_count'], 'createdAt' => (string)$row['created_at'],
        'currency' => (string)$row['currency'], 'exchangeRateMicros' => (int)$row['exchange_rate_micros'],
        'transactionCount' => (int)$row['transaction_count'], 'pendingCount' => (int)$row['pending_count'],
        'excludedCount' => (int)$row['excluded_count'], 'postedCount' => (int)$row['posted_count'],
        'deletable' => (int)$row['posted_count'] === 0,
    ], $stmt->fetchAll());

    $stmt = db()->prepare('SELECT * FROM reconciliations WHERE company_id = ? ORDER BY period_end DESC LIMIT 24');
    $stmt->execute([$companyId]);
    $reconciliations = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'bankAccountId' => (string)$row['bank_account_id'], 'periodEnd' => (string)$row['period_end'],
        'statementBalanceCents' => (int)$row['statement_balance_cents'], 'bookBalanceCents' => (int)$row['book_balance_cents'],
        'differenceCents' => (int)$row['difference_cents'], 'status' => (string)$row['status'],
        'completedAt' => $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
    ], $stmt->fetchAll());

    $stmt = db()->prepare('SELECT id, actor_email AS actor, action, entity_type, entity_id, created_at FROM audit_log WHERE company_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->execute([$companyId]);
    $auditEvents = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'actor' => (string)$row['actor'], 'action' => (string)$row['action'],
        'entityType' => (string)$row['entity_type'], 'entityId' => (string)$row['entity_id'], 'createdAt' => (string)$row['created_at'],
    ], $stmt->fetchAll());

    $stmt = db()->prepare("SELECT jl.id, je.id AS entry_id, je.entry_date, je.memo, je.source_type, je.source_id,
        a.id AS account_id, a.code AS account_code, a.name AS account_name, jl.debit_cents, jl.credit_cents,
        v.voucher_number AS transaction_number
        FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id JOIN accounts a ON a.id = jl.account_id
        LEFT JOIN vouchers v ON v.company_id = je.company_id AND v.source_type = je.source_type AND v.source_id = je.source_id
        WHERE je.company_id = ? AND je.status = 'posted' ORDER BY je.entry_date DESC, je.created_at DESC, jl.id LIMIT 250");
    $stmt->execute([$companyId]);
    $ledgerLines = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'entryId' => (string)$row['entry_id'], 'date' => (string)$row['entry_date'],
        'memo' => (string)$row['memo'], 'sourceType' => (string)$row['source_type'], 'sourceId' => (string)$row['source_id'],
        'transactionNumber' => $row['transaction_number'] !== null ? (string)$row['transaction_number'] : null, 'accountId' => (string)$row['account_id'],
        'accountCode' => (string)$row['account_code'], 'accountName' => (string)$row['account_name'],
        'debitCents' => (int)$row['debit_cents'], 'creditCents' => (int)$row['credit_cents'],
    ], $stmt->fetchAll());

    $stmt = db()->prepare("SELECT je.id, je.source_id, je.entry_date, je.memo, je.status, je.created_at,
        v.voucher_number AS transaction_number, COALESCE(SUM(jl.debit_cents), 0) AS total_debits
        FROM journal_entries je
        LEFT JOIN journal_lines jl ON jl.journal_entry_id = je.id
        LEFT JOIN vouchers v ON v.company_id = je.company_id AND v.source_type = je.source_type AND v.source_id = je.source_id
        WHERE je.company_id = ? AND je.source_type = 'manual_journal'
        GROUP BY je.id, je.source_id, je.entry_date, je.memo, je.status, je.created_at, v.voucher_number
        ORDER BY je.entry_date DESC, je.created_at DESC LIMIT 500");
    $stmt->execute([$companyId]);
    $manualJournals = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'], 'sourceId' => (string)$row['source_id'],
        'entryDate' => (string)$row['entry_date'], 'memo' => (string)$row['memo'],
        'status' => (string)$row['status'], 'transactionNumber' => $row['transaction_number'] !== null ? (string)$row['transaction_number'] : null, 'totalDebitsCents' => (int)$row['total_debits'],
        'sourceType' => 'manual_journal',
    ], $stmt->fetchAll());

    $stmt = db()->prepare("SELECT COALESCE(SUM(jl.debit_cents),0) AS debits, COALESCE(SUM(jl.credit_cents),0) AS credits
        FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date <= ?");
    $stmt->execute([$companyId, $today]);
    $ledgerTotals = $stmt->fetch() ?: ['debits' => 0, 'credits' => 0];

    $bankBalance = array_reduce(array_filter($bankAccounts, static fn(array $a): bool => $a['accountType'] === 'bank'), static fn(int $sum, array $a): int => $sum + $a['bookBalanceCents'], 0);
    $statementBalance = array_reduce(array_filter($bankAccounts, static fn(array $a): bool => $a['accountType'] === 'bank'), static fn(int $sum, array $a): int => $sum + $a['statementBalanceCents'], 0);
    $unpaid = array_reduce(array_filter($invoices, static fn(array $i): bool => $i['status'] === 'sent'), static fn(int $sum, array $i): int => $sum + $i['balanceCents'], 0);
    $overdue = array_reduce(array_filter($invoices, static fn(array $i): bool => $i['displayStatus'] === 'Overdue'), static fn(int $sum, array $i): int => $sum + $i['balanceCents'], 0);
    $customerOpeningTotal=0;$customerOpeningRows=[];
    if(schema_table_exists('party_opening_balances')){
        $pob=db()->prepare("SELECT party_id,effective_date,amount_cents FROM party_opening_balances WHERE company_id=? AND party_type='customer'");$pob->execute([$companyId]);$customerOpeningRows=$pob->fetchAll();
        foreach($customerOpeningRows as $row)$customerOpeningTotal+=(int)$row['amount_cents'];
    }
    $unpaid += $customerOpeningTotal;
    $income = array_reduce(array_filter($accounts, static fn(array $a): bool => $a['type'] === 'income'), static fn(int $sum, array $a): int => $sum + $a['periodBalanceCents'], 0);
    $expenseTotal = array_reduce(array_filter($accounts, static fn(array $a): bool => $a['type'] === 'expense'), static fn(int $sum, array $a): int => $sum + $a['periodBalanceCents'], 0);
    $openingStatusStmt = db()->prepare("SELECT COUNT(*) AS import_count, MAX(created_at) AS last_posted_at FROM opening_balance_imports WHERE company_id = ?");
    $openingStatusStmt->execute([$companyId]);
    $openingStatusRow = $openingStatusStmt->fetch() ?: ['import_count'=>0,'last_posted_at'=>null];
    $openingDraftStmt = db()->prepare("SELECT COUNT(*) FROM opening_balance_drafts WHERE company_id = ? AND amount_cents <> 0");
    $openingDraftStmt->execute([$companyId]);
    $openingBalanceStatus = ['posted'=>(int)$openingStatusRow['import_count'] > 0,'importCount'=>(int)$openingStatusRow['import_count'],'draftCount'=>(int)$openingDraftStmt->fetchColumn(),'lastPostedAt'=>$openingStatusRow['last_posted_at']];

    $receivable = (int)($accountByCode['1200']['balanceCents'] ?? 0);
    $debits = (int)$ledgerTotals['debits'];
    $credits = (int)$ledgerTotals['credits'];
    $books = books_workspace_data($company);
    $payableSubledger = array_reduce(array_filter($books['bills'], static fn(array $bill): bool => $bill['status'] === 'open'),
        static fn(int $sum, array $bill): int => $sum + $bill['balanceCents'], 0) + (int)($books['vendorOpeningTotalCents'] ?? 0);
    $payableControl = (int)($accountByCode['2050']['balanceCents'] ?? 0);
    $accrualBasis = (string)$company['accounting_basis'] === 'accrual';
    $receivableAging = ['current' => 0, 'days1To30' => 0, 'days31To60' => 0, 'days61To90' => 0, 'daysOver90' => 0];
    foreach ($invoices as $invoice) {
        if ($invoice['status'] === 'sent' && $invoice['balanceCents'] > 0) {
            $receivableAging[aging_bucket($invoice['dueDate'], $today)] += $invoice['balanceCents'];
        }
    }
    foreach($customerOpeningRows as $opening){
        $amount=(int)$opening['amount_cents'];
        if($amount>0)$receivableAging[aging_bucket((string)$opening['effective_date'],$today)]+=$amount;
        elseif($amount<0)$receivableAging['current']+=$amount;
    }

    $systemAccounts = [];
    try {
        $systemStmt = db()->prepare('SELECT system_key,configured_code,account_id,status,conflict_message FROM company_system_accounts WHERE company_id=?');
        $systemStmt->execute([$companyId]);
        foreach ($systemStmt->fetchAll() as $systemRow) {
            $systemAccounts[(string)$systemRow['system_key']] = [
                'code'=>(string)$systemRow['configured_code'],
                'accountId'=>$systemRow['account_id']!==null?(string)$systemRow['account_id']:null,
                'status'=>(string)$systemRow['status'],
                'message'=>$systemRow['conflict_message']!==null?(string)$systemRow['conflict_message']:null,
            ];
        }
    } catch (Throwable $ignored) {}

    return [
        'mode' => 'persistent',
        'organization' => [
            'id' => $companyId, 'name' => (string)$company['name'], 'legalName' => (string)$company['legal_name'],
            'businessType' => (string)$company['business_type'], 'province' => (string)$company['province'], 'currency' => (string)$company['currency'],
            'accountingBasis' => (string)$company['accounting_basis'],
            'payrollPostingMode' => (string)$company['payroll_posting_mode'],
            'reportingFramework' => (string)($company['reporting_framework'] ?? 'not_set'),
            'fiscalYearEnd' => (string)$company['fiscal_year_end'],
            'fiscalYearEndDate' => $company['fiscal_year_end_date'] !== null ? (string)$company['fiscal_year_end_date'] : null,
            'booksStartDate' => $company['books_start_date'] !== null ? (string)$company['books_start_date'] : null,
            'taxRegistered' => (bool)$company['tax_registered'],
            'taxNumber' => $company['tax_number'] !== null ? (string)$company['tax_number'] : null,
            'taxRateBps' => (int)$company['tax_rate_bps'], 'pstRegistered'=>(bool)($company['pst_registered']??false), 'pstRateBps'=>(int)($company['pst_rate_bps']??0), 'pstRecoverable'=>(bool)($company['pst_recoverable']??false), 'onboardingComplete' => true,
            'isTestMode' => (bool)($company['test_mode'] ?? false),
            'testExpiresAt' => $company['test_expires_at'] !== null ? (string)$company['test_expires_at'] . 'Z' : null,
        ],
        'accounts' => $accounts, 'systemAccounts'=>$systemAccounts, 'customers' => $customers, 'productsServices' => $productsServices, 'invoices' => $invoices, 'invoiceTemplates' => $invoiceTemplates, 'expenses' => $expenses,
        'vendors' => $books['vendors'], 'bills' => $books['bills'], 'companyCurrencies' => $books['companyCurrencies'],
        'accountingControls' => $books['accountingControls'], 'receivableAging' => $receivableAging, 'payableAging' => $books['payableAging'],
        'bankAccounts' => $bankAccounts, 'bankTransactions' => $bankTransactions, 'bankTransactionsMeta'=>$bankTransactionsMeta, 'importBatches' => $importBatches,
        'reconciliations' => $reconciliations, 'auditEvents' => $auditEvents, 'ledgerLines' => $ledgerLines,
        'manualJournals' => $manualJournals, 'openingBalanceStatus' => $openingBalanceStatus,
        'summary' => [
            'periodStart' => $periodStart, 'periodEnd' => $today, 'bankBalanceCents' => $bankBalance,
            'statementBalanceCents' => $statementBalance, 'unpaidInvoicesCents' => $unpaid, 'overdueInvoicesCents' => $overdue,
            'receivableControlCents' => $receivable, 'receivableSubledgerCents' => $unpaid,
            'receivableDifferenceCents' => $accrualBasis ? $receivable - $unpaid : 0,
            'receivableControlApplicable' => $accrualBasis,
            'payableControlCents' => $payableControl, 'payableSubledgerCents' => $payableSubledger,
            'payableDifferenceCents' => $accrualBasis ? $payableControl - $payableSubledger : 0,
            'payableControlApplicable' => $accrualBasis,
            'taxPayableCents' => max(0, (int)($accountByCode['2100']['balanceCents'] ?? 0) - (int)($accountByCode['1100']['balanceCents'] ?? 0)),
            'transactionsToReview' => (int)$bankCounts['pending_count'],
            'incomeYtdCents' => $income, 'expensesYtdCents' => $expenseTotal, 'netIncomeYtdCents' => $income - $expenseTotal,
            'ledgerDebitsCents' => $debits, 'ledgerCreditsCents' => $credits, 'ledgerDifferenceCents' => $debits - $credits,
        ],
    ];
}

function handle_workspace(): never
{
    require_method('GET');
    // A slow reporting query must return control to the startup gate instead
    // of leaving the browser behind a permanent full-screen loader.
    @set_time_limit(45);
    try { db()->exec('SET SESSION MAX_EXECUTION_TIME = 30000'); } catch (Throwable $ignored) {}
    try { db()->exec('SET SESSION max_statement_time = 30'); } catch (Throwable $ignored) {}
    $user = require_user();
    $company = require_company($user);
    json_response(workspace_data($user, $company));
}

/** @return array<string,mixed> */
function customer_record_values(array $company, array $input): array
{
    $name = clean_text($input['name'] ?? '', 'Customer name', 160);
    $contactName = optional_text($input['contactName'] ?? null, 160);
    $emailRaw = trim((string)($input['email'] ?? ''));
    $email = $emailRaw !== '' ? safe_email($emailRaw) : null;
    $phone = optional_text($input['phone'] ?? null, 60);
    $province = strtoupper(clean_text($input['province'] ?? '', 'Customer province or territory', 2));
    if (!in_array($province, tegh_province_codes(), true)) fail('Customer province or territory is invalid.');
    $address = tegh_structured_address($input, 'billingAddress');
    $termsDays = tegh_terms_days($input['paymentTerms'] ?? ($input['defaultTermsDays'] ?? 30), $input['customTermsDays'] ?? null, 30);
    $notes = optional_text($input['notes'] ?? null, 2000);
    $status = tegh_party_status($input['status'] ?? 'active', 'Customer status');
    $holdRemarks = optional_text($input['holdRemarks'] ?? null, 1000);
    if ($status === 'on_hold' && $holdRemarks === null) fail('Enter Hold Remarks before placing the customer on hold.',422,'hold_remarks_required');
    $openingBalanceCents = tegh_signed_opening_balance_cents($input['openingBalanceCents'] ?? 0);
    $openingBalanceDate = trim((string)($input['openingBalanceDate'] ?? ''));
    if ($openingBalanceCents !== 0) {
        if ($openingBalanceDate === '') fail('Enter an Opening Balance Date for a non-zero customer opening balance.');
        $openingBalanceDate = safe_date($openingBalanceDate,'Opening balance date');
    } else $openingBalanceDate = null;
    return [
        'name'=>$name,'contactName'=>$contactName,'email'=>$email,'phone'=>$phone,'province'=>$province,
        'billingAddress'=>$address['displayAddress'],'addressLine1'=>$address['addressLine1'],'addressLine2'=>$address['addressLine2'],
        'city'=>$address['city'],'postalCode'=>$address['postalCode'],'country'=>$address['country'],
        'termsDays'=>$termsDays,'notes'=>$notes,'status'=>$status,'holdRemarks'=>$holdRemarks,
        'openingBalanceCents'=>$openingBalanceCents,'openingBalanceDate'=>$openingBalanceDate,
    ];
}

/** Shared customer-create service used by the manual UI, imports and Ask Tegh. */
function create_customer_record(array $user, array $company, array $input, string $source = 'manual'): array
{
    require_company_permission($company, 'customers.write');
    $companyId = (string)$company['id'];
    $values = customer_record_values($company, $input);
    $dupe=db()->prepare('SELECT id FROM customers WHERE company_id=? AND name=? LIMIT 1');$dupe->execute([$companyId,$values['name']]);
    if($dupe->fetchColumn()!==false)fail('A customer with that name already exists in this company.',409,'duplicate_customer');
    $id = new_id('customer');
    $ownsTransaction=!db()->inTransaction();if($ownsTransaction)db()->beginTransaction();
    try {
        $active=$values['status']==='inactive'?0:1;
        db()->prepare('INSERT INTO customers (id,company_id,name,contact_name,email,phone,billing_address,address_line1,address_line2,city,province,postal_code,country,default_terms_days,notes,status,hold_remarks,hold_at,hold_by,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$companyId,$values['name'],$values['contactName'],$values['email'],$values['phone'],$values['billingAddress'],$values['addressLine1'],$values['addressLine2'],$values['city'],$values['province'],$values['postalCode'],$values['country'],$values['termsDays'],$values['notes'],$values['status'],$values['holdRemarks'],$values['status']==='on_hold'?gmdate('Y-m-d H:i:s'):null,$values['status']==='on_hold'?$user['id']:null,$active]);
        $opening=null;
        if($values['openingBalanceCents']!==0)$opening=post_party_opening_balance($user,$company,'customer',$id,$values['name'],$values['openingBalanceCents'],(string)$values['openingBalanceDate'],$source);
        audit_event($user,$companyId,'customer.created','customer',$id,[
            'name'=>$values['name'],'contactName'=>$values['contactName'],'status'=>$values['status'],'defaultTermsDays'=>$values['termsDays'],
            'openingBalanceCents'=>$values['openingBalanceCents'],'initiatedVia'=>$source==='ask_tegh'?'Ask Tegh':($source==='import'?'Data Import':'Manual UI'),
        ]);
        if($values['status']==='on_hold')audit_event($user,$companyId,'customer.hold_placed','customer',$id,['previousStatus'=>'new','remarks'=>$values['holdRemarks']]);
        if($ownsTransaction)db()->commit();
    } catch(Throwable $e) { if($ownsTransaction&&db()->inTransaction())db()->rollBack(); throw $e; }
    return ['id'=>$id,'name'=>$values['name'],'province'=>$values['province'],'status'=>$values['status'],'defaultTermsDays'=>$values['termsDays'],'openingBalance'=>$opening];
}

function handle_customers(): never
{
    require_method('POST', 'PUT', 'DELETE');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_permission($company,'customers.write');
    $companyId = (string)$company['id'];
    $input = request_json();
    if (request_method() === 'DELETE') {
        $id = clean_text($input['customerId'] ?? '', 'Customer', 64);
        $stmt = db()->prepare('SELECT name FROM customers WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $companyId]);$customer=$stmt->fetch();
        if (!$customer) fail('Customer not found.',404,'customer_not_found');
        $used=db()->prepare('SELECT (SELECT COUNT(*) FROM invoices WHERE customer_id=? AND company_id=?)+(SELECT COUNT(*) FROM party_opening_balances WHERE party_type=\'customer\' AND party_id=? AND company_id=?)');
        $used->execute([$id,$companyId,$id,$companyId]);
        if((int)$used->fetchColumn()>0){
            db()->prepare("UPDATE customers SET active=0,status='inactive' WHERE id=? AND company_id=?")->execute([$id,$companyId]);$result='archived';
        }else{db()->prepare('DELETE FROM customers WHERE id=? AND company_id=?')->execute([$id,$companyId]);$result='deleted';}
        audit_event($user,$companyId,'customer.'.$result,'customer',$id,['name'=>(string)$customer['name']]);
        json_response(['customer'=>['id'=>$id,'status'=>$result]]);
    }
    if(request_method()==='POST'){
        $customer=create_customer_record($user,$company,$input);
        json_response(['customer'=>$customer],201);
    }
    $values=customer_record_values($company,$input);
    $id=clean_text($input['customerId']??'','Customer',64);
    $existingStmt=db()->prepare('SELECT * FROM customers WHERE id=? AND company_id=? LIMIT 1');$existingStmt->execute([$id,$companyId]);$existing=$existingStmt->fetch();
    if(!$existing)fail('Customer not found.',404,'customer_not_found');
    $used=db()->prepare('SELECT COUNT(*) FROM invoices WHERE customer_id=? AND company_id=?');$used->execute([$id,$companyId]);$locked=(int)$used->fetchColumn()>0;
    if($locked && ((string)$existing['name']!==$values['name'] || strtoupper((string)$existing['province'])!==$values['province'])){
        fail('Customer name and province are locked after invoice use. Contact details, terms, notes and hold status can still be updated.',409,'customer_legal_fields_locked');
    }
    if($values['openingBalanceCents']!==0 && !party_opening_balance_row($companyId,'customer',$id)){
        fail('Opening balance can only be recorded when the customer is first created or through Data Import. Use an adjusting entry for later corrections.',409,'opening_balance_edit_blocked');
    }
    db()->beginTransaction();
    try{
        $active=$values['status']==='inactive'?0:1;
        db()->prepare('UPDATE customers SET name=?,contact_name=?,email=?,phone=?,billing_address=?,address_line1=?,address_line2=?,city=?,province=?,postal_code=?,country=?,default_terms_days=?,notes=?,active=? WHERE id=? AND company_id=?')
          ->execute([$locked?(string)$existing['name']:$values['name'],$values['contactName'],$values['email'],$values['phone'],$values['billingAddress'],$values['addressLine1'],$values['addressLine2'],$values['city'],$locked?(string)$existing['province']:$values['province'],$values['postalCode'],$values['country'],$values['termsDays'],$values['notes'],$active,$id,$companyId]);
        $oldStatus=(string)($existing['status']??'active');
        if($values['status']==='on_hold'){
            if($oldStatus!=='on_hold'||trim((string)$existing['hold_remarks'])!==trim((string)$values['holdRemarks']))tegh_customer_hold_state($user,$company,$id,'on_hold',$values['holdRemarks']);
        }elseif($values['status']==='active'){
            if($oldStatus==='on_hold')tegh_customer_hold_state($user,$company,$id,'active',null,$input['releaseRemarks']??null);
            else db()->prepare("UPDATE customers SET status='active',active=1 WHERE id=? AND company_id=?")->execute([$id,$companyId]);
        }else tegh_customer_hold_state($user,$company,$id,'inactive',null);
        audit_event($user,$companyId,'customer.updated','customer',$id,['name'=>$locked?(string)$existing['name']:$values['name'],'status'=>$values['status'],'defaultTermsDays'=>$values['termsDays']]);
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    json_response(['customer'=>['id'=>$id,'status'=>$values['status']]],200);
}

/* R135: customer-invoice sales tax is recorded and posted in two parts, the same
   way bills are: GST/HST to 2100 and PST (or QST) to 2110. The saved split is
   what credit/debit notes and cash-basis payments allocate from. */
function invoice_record_tax_split(string $companyId, string $invoiceId, int $tax, int $pst = 0): void
{
    if (!schema_column_exists('invoices', 'gst_hst_cents') || !schema_column_exists('invoices', 'pst_cents') || !schema_column_exists('invoices', 'tax_entry_mode')) return;
    $tax = max(0, $tax); $pst = max(0, min($pst, $tax));
    db()->prepare("UPDATE invoices SET gst_hst_cents=?, pst_cents=?, tax_entry_mode=? WHERE company_id=? AND id=?")
        ->execute([$tax - $pst, $pst, $tax > 0 ? 'exclusive' : 'none', $companyId, $invoiceId]);
}

/** Split a tax amount taken from an invoice in the invoice's own GST/HST : PST proportion. */
function invoice_tax_parts(array $invoice, int $taxPortion): array
{
    $tax = (int)($invoice['tax_cents'] ?? 0); $pst = (int)($invoice['pst_cents'] ?? 0);
    if ($taxPortion <= 0 || $tax <= 0 || $pst <= 0) return [max(0, $taxPortion), 0];
    $pstPart = (int)round($taxPortion * min($pst, $tax) / $tax);
    return [$taxPortion - $pstPart, $pstPart];
}

/** Credit lines for sales tax collected: GST/HST to 2100, PST to 2110. */
function invoice_tax_credit_lines(string $companyId, int $gstHst, int $pst): array
{
    $lines = [];
    if ($gstHst > 0) $lines[] = ['accountId' => account_by_code($companyId, '2100'), 'debitCents' => 0, 'creditCents' => $gstHst, 'memo' => 'GST/HST collected'];
    if ($pst > 0) $lines[] = ['accountId' => account_by_code($companyId, '2110'), 'debitCents' => 0, 'creditCents' => $pst, 'memo' => 'PST collected'];
    return $lines;
}

function invoice_posting_lines(string $companyId, int $subtotal, int $tax, int $total, ?array $calculatedLines = null, ?string $invoiceId = null, int $pst = 0): array
{
    $revenue=[];
    if($calculatedLines!==null){
        foreach($calculatedLines as $line){$account=(string)($line['incomeAccountId']??'');if($account==='')$account=account_by_code($companyId,'4000');$revenue[$account]=($revenue[$account]??0)+(int)$line['amountCents'];}
    }elseif($invoiceId!==null&&schema_column_exists('invoice_lines','income_account_id')){
        $q=db()->prepare('SELECT COALESCE(il.income_account_id,?) AS account_id,SUM(il.amount_cents) amount_cents FROM invoice_lines il WHERE il.invoice_id=? GROUP BY COALESCE(il.income_account_id,?)');$default=account_by_code($companyId,'4000');$q->execute([$default,$invoiceId,$default]);foreach($q->fetchAll() as $r)$revenue[(string)$r['account_id']]=(int)$r['amount_cents'];
    }
    if(!$revenue)$revenue=[account_by_code($companyId,'4000')=>$subtotal];
    $lines=[['accountId'=>account_by_code($companyId,'1200'),'debitCents'=>$total,'creditCents'=>0]];
    foreach($revenue as $account=>$amount)if($amount>0)$lines[]=['accountId'=>$account,'debitCents'=>0,'creditCents'=>$amount];
    $pst=max(0,min($pst,$tax));
    foreach(invoice_tax_credit_lines($companyId,$tax-$pst,$pst) as $line)$lines[]=$line;
    return $lines;
}

/** @param array<int,string> $lineDescriptions */
function invoice_posting_description(string $customerName, string $number, string $issueDate, array $lineDescriptions): string
{
    $summary = implode(', ', array_slice(array_values(array_filter(array_map('trim', $lineDescriptions))), 0, 3));
    if ($summary === '') $summary = 'Products or services';
    return mb_substr('Customer ' . $customerName . ' · Invoice ' . $number . ' · ' . $issueDate . ' · ' . $summary, 0, 500);
}

function prepare_invoice_record_r20(array $user,array $company,array $input,?string $excludeInvoiceId=null): array
{
    require_company_permission($company,'invoices.write');
    $companyId=(string)$company['id'];
$customerId = clean_text($input['customerId'] ?? '', 'Customer', 64);
$stmt = db()->prepare('SELECT id, name, email, phone, billing_address, province FROM customers WHERE id = ? AND company_id = ? AND active = 1');
$stmt->execute([$customerId, $companyId]);
$customer = $stmt->fetch();
if (!$customer) fail('Choose a valid customer.');
$issueDate = safe_date($input['issueDate'] ?? '', 'Invoice date');
assert_customer_invoice_on_or_after_books_start($company,$issueDate);
$dueDate = safe_date($input['dueDate'] ?? '', 'Due date');
if ($dueDate < $issueDate) fail('Due date cannot be before the invoice date.');
$issue = !empty($input['issue']);
if ($issue) {
    assert_customer_may_issue_invoice($companyId,$customerId);
    assert_not_future_date($issueDate, 'Invoice date');
}
$currency = safe_currency_code($input['currency'] ?? $company['currency'], 'Invoice currency');
$currencyRow = company_currency($companyId, $currency);
if (!$currencyRow) fail('Add that currency to the company before using it on an invoice.');
$exchangeRateMicros = safe_exchange_rate_micros($input['exchangeRateMicros'] ?? $currencyRow['rate_to_base_micros'], $currency, (string)$company['currency']);
$supplyProvince = (string)($customer['province'] ?: $company['province']);
// R135: PST/QST is charged on taxable lines when the company is registered for
// it and the sale is in the company's own province. A line may switch it off
// (for example a PST-exempt service) or on explicitly.
$pstRateCompany = (bool)($company['pst_registered'] ?? false) ? (int)($company['pst_rate_bps'] ?? 0) : 0;
$pstDefault = $pstRateCompany > 0 && strtoupper($supplyProvince) === strtoupper((string)$company['province']);
$sourceLines = $input['lines'] ?? [[
    'description' => $input['description'] ?? '',
    'quantity' => $input['quantity'] ?? 0,
    'unitPriceCents' => $input['unitPriceCents'] ?? 0,
    'taxable' => !empty($input['taxable']),
]];
if (!is_array($sourceLines) || count($sourceLines) < 1 || count($sourceLines) > 100) {
    fail('An invoice must contain between 1 and 100 lines.');
}
$calculatedLines = [];
$taxOverrides = [];
$foreignSubtotal = 0;
$foreignTax = 0;
$foreignPst = 0;
$subtotal = 0;
$tax = 0;
$pst = 0;
foreach (array_values($sourceLines) as $index => $line) {
    if (!is_array($line)) fail('An invoice line is invalid.');
    $description = clean_text($line['description'] ?? '', 'Line description', 500);
    $productServiceId=trim((string)($line['productServiceId']??''));
    $incomeAccountId=trim((string)($line['incomeAccountId']??''));if($incomeAccountId==='')$incomeAccountId=null;
    if($incomeAccountId!==null){
        $incomeAccount=company_account($companyId,$incomeAccountId);
        if(!$incomeAccount||(string)$incomeAccount['account_type']!=='income'||(bool)$incomeAccount['is_control'])fail('Choose a valid active non-control income GL account.');
    }
    if($productServiceId!==''){
        $itemStmt=db()->prepare("SELECT id,description,name,unit_price_cents,taxable,income_account_id FROM products_services WHERE id=? AND company_id=? AND active=1 LIMIT 1");$itemStmt->execute([$productServiceId,$companyId]);$item=$itemStmt->fetch();if(!$item)fail('A selected product or service is unavailable.');
        if(trim((string)($line['description']??''))==='')$description=(string)($item['description']?:$item['name']);
        if($incomeAccountId===null)$incomeAccountId=$item['income_account_id']!==null?(string)$item['income_account_id']:account_by_code($companyId,'4000');
        if(!isset($line['unitPriceCents']))$line['unitPriceCents']=(int)$item['unit_price_cents'];
        if(!array_key_exists('taxable',$line))$line['taxable']=(bool)$item['taxable'];
    }
    $quantityRaw = $line['quantity'] ?? 0;
    if (!is_numeric($quantityRaw)) fail('Line quantity must be numeric.');
    $quantity = (float)$quantityRaw;
    if (!is_finite($quantity)) fail('Line quantity is invalid.');
    $quantityMilli = (int)round($quantity * 1000);
    $foreignUnitPrice = safe_cents($line['unitPriceCents'] ?? 0, 'Line rate');
    if ($quantityMilli <= 0 || $quantityMilli > 1_000_000 || $foreignUnitPrice <= 0) fail('A line quantity or rate is invalid.');
    $foreignAmount = (int)round(($quantityMilli * $foreignUnitPrice) / 1000);
    if ($foreignAmount <= 0) fail('Each invoice line must have a positive amount.');
    $taxRate = 0;
    if (!empty($line['taxable']) && (bool)$company['tax_registered']) {
        $provinceRate = province_rate_bps($supplyProvince);
        $taxRate = $provinceRate;
        if (array_key_exists('taxRateBps', $line) && $line['taxRateBps'] !== null && $line['taxRateBps'] !== '') {
            $override = filter_var($line['taxRateBps'], FILTER_VALIDATE_INT);
            if ($override === false || $override < 0 || $override > 3000) fail('Line sales tax rate must be between 0% and 30%.');
            $taxRate = (int)$override;
            if($taxRate!==$provinceRate){
                $overrideReason=trim((string)($line['taxOverrideReason']??$input['taxOverrideReason']??''));
                if(mb_strlen($overrideReason)<12)fail('A sales-tax rate that differs from the province of supply requires an override reason of at least 12 characters.',422,'invoice_tax_override_reason_required');
                $taxOverrides[]=['line'=>$index,'provinceOfSupply'=>$supplyProvince,'expectedRateBps'=>$provinceRate,'appliedRateBps'=>$taxRate,'reason'=>mb_substr($overrideReason,0,500)];
            }
        }
    }
    $pstRate = 0;
    if (!empty($line['taxable'])) {
        $pstRequested = array_key_exists('pst', $line) && $line['pst'] !== null && $line['pst'] !== '' ? filter_var($line['pst'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : $pstDefault;
        if ($pstRequested === null) fail('Line PST choice is invalid.', 422, 'invoice_pst_invalid');
        if ($pstRequested && $pstRateCompany <= 0) fail('PST is not enabled in this company tax setup. Turn it on under Company Details first.', 409, 'pst_not_configured');
        $pstRate = $pstRequested ? $pstRateCompany : 0;
    }
    $foreignLineGst = (int)round(($foreignAmount * $taxRate) / 10000);
    $foreignLinePst = (int)round(($foreignAmount * $pstRate) / 10000);
    $foreignLineTax = $foreignLineGst + $foreignLinePst;
    $baseAmount = convert_to_base_cents($foreignAmount, $exchangeRateMicros);
    $baseLineTotal = convert_to_base_cents($foreignAmount + $foreignLineTax, $exchangeRateMicros);
    $baseLineTax = $baseLineTotal - $baseAmount;
    $baseLinePst = min($baseLineTax, convert_to_base_cents($foreignLinePst, $exchangeRateMicros));
    $calculatedLines[] = [
        'description' => $description, 'quantityMilli' => $quantityMilli,
        'foreignUnitPriceCents' => $foreignUnitPrice, 'foreignAmountCents' => $foreignAmount,
        'foreignTaxCents' => $foreignLineTax, 'unitPriceCents' => convert_to_base_cents($foreignUnitPrice, $exchangeRateMicros),
        'amountCents' => $baseAmount, 'taxCents' => $baseLineTax, 'taxRateBps' => $taxRate + $pstRate,
        'gstHstRateBps' => $taxRate, 'pstRateBps' => $pstRate, 'gstHstCents' => $baseLineTax - $baseLinePst, 'pstCents' => $baseLinePst,
        'foreignGstHstCents' => $foreignLineGst, 'foreignPstCents' => $foreignLinePst,
        'sortOrder' => $index, 'productServiceId' => $productServiceId !== '' ? $productServiceId : null,
        'incomeAccountId' => $incomeAccountId,
    ];
    $foreignSubtotal += $foreignAmount;
    $foreignTax += $foreignLineTax;
    $foreignPst += $foreignLinePst;
    $subtotal += $baseAmount;
    $tax += $baseLineTax;
    $pst += $baseLinePst;
}
$foreignTotal = $foreignSubtotal + $foreignTax;
$total = $subtotal + $tax;
if ($foreignTotal <= 0 || $foreignTotal > 100_000_000_000 || $total > 100_000_000_000) {
    fail('Invoice total is outside the supported range.');
}
$templateId = optional_text($input['templateId'] ?? null, 64);
$template = invoice_template_row($companyId, $templateId);
$templateSnapshot = invoice_template_snapshot($template, $company);
$customerSnapshot = invoice_customer_snapshot($customer);
$purchaseOrder = optional_text($input['purchaseOrder'] ?? null, 80);
$importReference = optional_text($input['importReference'] ?? null, 120);
if($importReference!==null){
    $dup=db()->prepare('SELECT 1 FROM invoices WHERE company_id=? AND import_reference=? AND id<>? LIMIT 1');$dup->execute([$companyId,$importReference,$excludeInvoiceId??'']);
    if($dup->fetchColumn())fail('That customer invoice import reference has already been used.',409,'duplicate_invoice_import_reference');
}
return compact('companyId','customerId','customer','issueDate','dueDate','issue','currency','exchangeRateMicros','calculatedLines','taxOverrides','foreignSubtotal','foreignTax','foreignPst','subtotal','tax','pst','foreignTotal','total','templateId','template','templateSnapshot','customerSnapshot','purchaseOrder','importReference');
}

function create_invoice_record(array $user,array $company,array $input,string $source='manual'): array
{
    // Shared, fixed-key validation preserves creation and edit calculations.
    extract(prepare_invoice_record_r20($user,$company,$input),EXTR_SKIP);
$id = new_id('invoice');
$status = $issue ? 'sent' : 'draft';
$isRecurring = !empty($input['isRecurring']);
if ($issue) assert_period_open($companyId, $issueDate);

// A validated import preserves the supplied external invoice number. Manual
// invoices continue to use Tegh's protected sequence allocator.
$importNumber=$source==='import'?optional_text($input['number']??null,60):null;
if($importNumber!==null){$number=$importNumber;$dupNumber=db()->prepare('SELECT 1 FROM invoices WHERE company_id=? AND number=? LIMIT 1');$dupNumber->execute([$companyId,$number]);if($dupNumber->fetchColumn())fail('That customer invoice number already exists.',409,'duplicate_invoice_number');}
else $number = reserve_invoice_number($companyId);

db_transaction_retry(function () use (
    $user, $companyId, $company, $issue, $issueDate, $id, $number,
    $subtotal, $tax, $total, $customerId, $dueDate, $status, $input,
    $currency, $exchangeRateMicros, $foreignSubtotal, $foreignTax,
    $foreignTotal, $purchaseOrder, $template, $templateSnapshot,
    $customerSnapshot, $calculatedLines, $customer, $isRecurring, $importReference, $source, $taxOverrides, $pst
): void {
    if(function_exists('voucher_register_saved'))voucher_register_saved($user,$companyId,'CI','AR','invoice',$id,$issueDate,'Customer invoice '.$number,$total,null,false);
    $lineDescriptions = array_map(static fn(array $line): string => (string)$line['description'], $calculatedLines);
    $entryId = $issue && (string)$company['accounting_basis'] === 'accrual'
        ? add_journal_entry($user, $companyId, $issueDate, 'invoice', $id,
            invoice_posting_description((string)$customer['name'], $number, $issueDate, $lineDescriptions),
            invoice_posting_lines($companyId,$subtotal,$tax,$total,$calculatedLines,null,$pst))
        : null;
    db()->prepare('INSERT INTO invoices (id, company_id, customer_id, number, issue_date, due_date, status,
        subtotal_cents, tax_cents, total_cents, balance_cents, message, currency, exchange_rate_micros,
        foreign_subtotal_cents, foreign_tax_cents, foreign_total_cents, foreign_balance_cents,
        purchase_order, import_reference, template_id, template_snapshot_json, customer_snapshot_json, issued_journal_entry_id, is_recurring)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $companyId, $customerId, $number, $issueDate, $dueDate, $status,
            $subtotal, $tax, $total, $total, mb_substr(trim((string)($input['message'] ?? '')), 0, 1000),
            $currency, $exchangeRateMicros, $foreignSubtotal, $foreignTax, $foreignTotal, $foreignTotal,
            $purchaseOrder, $importReference, $template['id'],
            json_encode($templateSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($customerSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $entryId, $isRecurring ? 1 : 0]);
    invoice_record_tax_split($companyId, $id, $tax, $pst);
    $lineStmt = db()->prepare('INSERT INTO invoice_lines
        (id,invoice_id,product_service_id,income_account_id,description,quantity_milli,unit_price_cents,tax_rate_bps,amount_cents,tax_cents,
         foreign_unit_price_cents,foreign_amount_cents,foreign_tax_cents,sort_order)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($calculatedLines as $line) {
        $lineStmt->execute([
            new_id('iline'),$id,$line['productServiceId'],$line['incomeAccountId'],$line['description'],$line['quantityMilli'],$line['unitPriceCents'],
            $line['taxRateBps'],$line['amountCents'],$line['taxCents'],$line['foreignUnitPriceCents'],
            $line['foreignAmountCents'],$line['foreignTaxCents'],$line['sortOrder'],
        ]);
    }
    if ($issue && function_exists('voucher_mark_posted')) voucher_mark_posted($user, $companyId, 'invoice', $id, $entryId);
    audit_event($user, $companyId, $issue ? 'invoice.issued' : 'invoice.draft_created', 'invoice', $id, [
        'number' => $number, 'totalCents' => $total, 'currency' => $currency,
        'foreignTotalCents' => $foreignTotal, 'lineCount' => count($calculatedLines),
        'templateId' => (string)$template['id'], 'accountingBasis' => (string)$company['accounting_basis'],
        'isRecurring' => $isRecurring, 'initiatedVia' => $source, 'taxRateOverrides' => $taxOverrides,
    ]);
}, 5);
return [
    'id' => $id, 'number' => $number, 'status' => $status, 'totalCents' => $total,
    'currency' => $currency, 'foreignTotalCents' => $foreignTotal, 'isRecurring' => $isRecurring,
    'importReference' => $importReference,
];
}

function handle_invoices(): never
{
    require_method('POST', 'PATCH');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    if (request_method() === 'PATCH') {
        $action = (string)($input['action'] ?? '');
        if (!in_array($action, ['issue', 'void'], true)) fail('Invoice action is invalid.');
        $invoiceId = clean_text($input['invoiceId'] ?? '', 'Invoice', 64);
        db()->beginTransaction();
        try {
            $stmt = db()->prepare('SELECT i.*, c.name AS customer_name FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = ? AND i.company_id = ? FOR UPDATE');
            $stmt->execute([$invoiceId, $companyId]);
            $invoice = $stmt->fetch();
            if (!$invoice) fail('Choose an available invoice.', 409, 'invoice_unavailable');
            if(function_exists('note_assert_invoice_action_allowed'))note_assert_invoice_action_allowed($companyId,$invoiceId,$action);
            if(function_exists('tegh_invoice_assert_not_sending_r20'))tegh_invoice_assert_not_sending_r20($companyId,$invoiceId);
            if ($action === 'issue') {
                if ((string)$invoice['status'] !== 'draft') fail('Choose an available draft invoice.', 409, 'invoice_unavailable');
                assert_customer_may_issue_invoice($companyId,(string)$invoice['customer_id']);
                assert_not_future_date((string)$invoice['issue_date'], 'Invoice date');
                assert_customer_invoice_on_or_after_books_start($company,(string)$invoice['issue_date']);
                assert_period_open($companyId, (string)$invoice['issue_date']);
                $entryId = null;
                if (!empty($invoice['is_opening_document']) || (string)$company['accounting_basis'] === 'accrual') {
                    $lineStmt = db()->prepare('SELECT description FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order, id LIMIT 3');
                    $lineStmt->execute([$invoiceId]);
                    $lineDescriptions = array_map('strval', $lineStmt->fetchAll(PDO::FETCH_COLUMN));
                    $entryId = add_journal_entry($user, $companyId, (string)$invoice['issue_date'], 'invoice', $invoiceId,
                        invoice_posting_description((string)$invoice['customer_name'], (string)$invoice['number'], (string)$invoice['issue_date'], $lineDescriptions),
                        invoice_posting_lines($companyId,(int)$invoice['subtotal_cents'],(int)$invoice['tax_cents'],(int)$invoice['total_cents'],null,$invoiceId,(int)($invoice['pst_cents']??0)));
                }
                db()->prepare("UPDATE invoices SET status = 'sent', issued_journal_entry_id = ? WHERE id = ? AND status = 'draft'")->execute([$entryId, $invoiceId]);
                if (function_exists('voucher_mark_posted')) voucher_mark_posted($user, $companyId, 'invoice', $invoiceId, $entryId);
                audit_event($user, $companyId, 'invoice.issued', 'invoice', $invoiceId, [
                    'number' => $invoice['number'], 'totalCents' => (int)$invoice['total_cents'],
                    'accountingBasis' => (string)$company['accounting_basis'],
                ]);
                $responseStatus = 'sent';
            } else {
                if (!in_array((string)$invoice['status'], ['draft', 'sent'], true)) {
                    fail('Only an unpaid draft or issued invoice can be voided.', 409, 'invoice_void_unavailable');
                }
                if(function_exists('void_assert_cycle_limit'))void_assert_cycle_limit($companyId,'invoice',$invoiceId);
                if((int)$invoice['total_cents']>company_materiality_threshold_cents($companyId)){
                    fail('This material invoice requires a second Company Owner. Use the Transaction Void review to submit and approve it.',409,'material_void_requires_dual_control');
                }
                if ((int)$invoice['balance_cents'] !== (int)$invoice['total_cents']
                    || (int)$invoice['foreign_balance_cents'] !== (int)$invoice['foreign_total_cents']) {
                    fail('An invoice with a payment cannot be voided. Reverse or reallocate the payment first.', 409, 'invoice_has_payments');
                }
                $reversalEntryId = null;
                if ((string)$invoice['status'] === 'sent') {
                    require_company_role($company, 'owner');
                    $voidDate = safe_date($input['voidDate'] ?? canadian_today(), 'Void date');
                    assert_not_future_date($voidDate, 'Void date');
                    if ($voidDate < (string)$invoice['issue_date']) fail('Void date cannot precede the invoice date.');
                    if (!empty($invoice['is_opening_document'])) {
                        $reversalEntryId=$invoice['issued_journal_entry_id']!==null
                            ?add_reversing_journal_entry($user,$companyId,(string)$invoice['issued_journal_entry_id'],$voidDate,'invoice_void',$invoiceId,'Void invoice '.(string)$invoice['number'])
                            :add_opening_document_void_journal($user,$companyId,'invoice',$invoice,$voidDate);
                    } elseif ((string)$company['accounting_basis'] === 'accrual') {
                        if ($invoice['issued_journal_entry_id'] === null) throw new RuntimeException('The issued invoice journal is missing.');
                        $reversalEntryId=add_reversing_journal_entry($user,$companyId,(string)$invoice['issued_journal_entry_id'],$voidDate,'invoice_void',$invoiceId,'Void invoice '.(string)$invoice['number']);
                    }
                }
                db()->prepare("UPDATE invoices SET status = 'void', balance_cents = 0, foreign_balance_cents = 0 WHERE id = ? AND status IN ('draft','sent')")
                    ->execute([$invoiceId]);
                if (function_exists('voucher_mark_void')) voucher_mark_void($user, $companyId, 'invoice', $invoiceId);
                audit_event($user, $companyId, 'invoice.voided', 'invoice', $invoiceId, [
                    'number' => (string)$invoice['number'], 'previousStatus' => (string)$invoice['status'],
                    'reversalJournalEntryId' => $reversalEntryId,
                ]);
                $responseStatus = 'void';
            }
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            throw $error;
        }
        json_response(['invoice' => ['id' => $invoiceId, 'status' => $responseStatus]]);
    }

    $invoice=create_invoice_record($user,$company,$input,'manual');
    json_response(['invoice'=>$invoice],201);
}

function handle_expenses(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    $vendor = clean_text($input['vendor'] ?? '', 'Vendor', 200);
    $date = safe_date($input['expenseDate'] ?? '', 'Expense date');
    assert_not_future_date($date, 'Expense date');
    $category = company_account($companyId, clean_text($input['categoryAccountId'] ?? '', 'Category', 64));
    if (!$category || $category['account_type'] !== 'expense' || (bool)$category['is_control']) fail('Choose a valid expense category.');
    $paid = company_account($companyId, clean_text($input['paidFromAccountId'] ?? '', 'Paid from', 64));
    if (!$paid) fail('Choose a valid payment account.');
    $bankStmt = db()->prepare('SELECT id, last_reconciled_date, currency FROM bank_accounts WHERE company_id = ? AND ledger_account_id = ? AND active = 1');
    $bankStmt->execute([$companyId, $paid['id']]);
    $bank = $bankStmt->fetch();
    if (!$bank) fail('Paid from must be an active bank or credit-card account.');

    $baseCurrency = strtoupper((string)$company['currency']);
    $currency = safe_currency_code($input['currency'] ?? $bank['currency'] ?? $baseCurrency, 'Expense currency');
    if (strtoupper((string)$bank['currency']) !== $currency) {
        fail('The payment account currency must match the Expense Voucher currency. Choose a matching currency account or record the foreign purchase as a Vendor Invoice.', 422, 'expense_payment_currency_mismatch');
    }
    $rate = 1000000;
    if ($currency !== $baseCurrency) {
        $configured = company_currency($companyId, $currency);
        if (!$configured) fail('Set an exchange rate for '.$currency.' under Settings → Currency Exchange Rates before posting this expense.', 422, 'exchange_rate_required');
        $rate = safe_exchange_rate_micros($configured['rate_to_base_micros'] ?? null, $currency, $baseCurrency);
    }

    $mode = (string)($input['taxEntryMode'] ?? (!empty($input['taxable']) ? 'exclusive' : 'none'));
    if (!in_array($mode, ['none','exclusive','inclusive'], true)) fail('Choose a valid tax entry mode.');
    $foreignAmount = safe_cents($input['amountCents'] ?? $input['subtotalCents'] ?? 0, 'Amount');
    if ($foreignAmount <= 0) fail('Amount must be positive.');
    $applyGstHst = (array_key_exists('applyGstHst', $input) ? !empty($input['applyGstHst']) : !empty($input['taxable'])) && (bool)$company['tax_registered'];
    $applyPst = !empty($input['applyPst']) && (bool)($company['pst_registered'] ?? false);
    if ($mode === 'none') { $applyGstHst = false; $applyPst = false; }
    $parts = calculate_tax_components($foreignAmount,$mode,$applyGstHst ? (int)$company['tax_rate_bps'] : 0,$applyPst ? (int)($company['pst_rate_bps'] ?? 0) : 0);
    $mode = (string)$parts['mode'];
    $foreignSubtotal = (int)$parts['netCents'];
    $foreignGstHst = (int)$parts['gstHstCents'];
    $foreignPst = (int)$parts['pstCents'];
    $foreignTax = (int)$parts['taxCents'];
    $foreignTotal = (int)$parts['grossCents'];
    $subtotal = convert_to_base_cents($foreignSubtotal,$rate);
    $gstHst = convert_to_base_cents($foreignGstHst,$rate);
    $pst = convert_to_base_cents($foreignPst,$rate);
    $tax = $gstHst + $pst;
    $total = $subtotal + $tax;
    $pstRecoverable = (bool)($company['pst_recoverable'] ?? false);

    $receiptPath = optional_text($input['receiptKey'] ?? null, 500);
    if ($receiptPath !== null && !valid_private_storage_reference($companyId, $receiptPath, 'receipts')) fail('The uploaded receipt reference is invalid.');
    $id = trim((string)($input['draftSourceId'] ?? ''));
    if ($id !== '') {
        $id = clean_text($id, 'Draft', 64);
        $draftStmt = db()->prepare("SELECT id FROM voucher_draft_payloads WHERE company_id=? AND source_type='expense' AND source_id=?");
        $draftStmt->execute([$companyId,$id]);
        if (!$draftStmt->fetch()) fail('The expense draft is no longer available.',409,'voucher_draft_unavailable');
    } else $id = new_id('expense');
    db()->beginTransaction();
    try {
        if(function_exists('voucher_register_saved'))voucher_register_saved($user,$companyId,'EX','EX','expense',$id,$date,'Expense · '.$vendor,$total,null,false);
        $categoryDebit = $subtotal + ($pstRecoverable ? 0 : $pst);
        $lines = [['accountId'=>(string)$category['id'],'debitCents'=>$categoryDebit,'creditCents'=>0,'memo'=>$pst > 0 && !$pstRecoverable ? 'Expense net + non-recoverable PST' : 'Expense net']];
        if ($gstHst > 0) $lines[]=['accountId'=>account_by_code($companyId,'1100'),'debitCents'=>$gstHst,'creditCents'=>0,'memo'=>'GST/HST recoverable'];
        if ($pst > 0 && $pstRecoverable) $lines[]=['accountId'=>account_by_code($companyId,'1110'),'debitCents'=>$pst,'creditCents'=>0,'memo'=>'PST recoverable'];
        $lines[]=['accountId'=>(string)$paid['id'],'debitCents'=>0,'creditCents'=>$total,'memo'=>'Payment account'];
        $entryId=add_journal_entry($user,$companyId,$date,'expense',$id,'Vendor '.$vendor.' · Expense '.$date.' · '.(string)$category['name'].' · '.$currency.' @ '.number_format($rate/1000000,6),$lines);
        if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,'expense',$id,$entryId);
        db()->prepare("DELETE FROM voucher_draft_payloads WHERE company_id=? AND source_type='expense' AND source_id=?")->execute([$companyId,$id]);
        $taxCode=$gstHst > 0 && $pst > 0 ? 'GST_HST_PST' : ($gstHst > 0 ? 'GST_HST' : ($pst > 0 ? 'PST' : 'NO_TAX'));
        db()->prepare('INSERT INTO expenses (id,company_id,vendor,expense_date,category_account_id,paid_from_account_id,currency,exchange_rate_micros,foreign_subtotal_cents,foreign_gst_hst_cents,foreign_pst_cents,foreign_tax_cents,foreign_total_cents,subtotal_cents,gst_hst_cents,pst_cents,tax_cents,tax_entry_mode,total_cents,tax_code,receipt_path,journal_entry_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$companyId,$vendor,$date,$category['id'],$paid['id'],$currency,$rate,$foreignSubtotal,$foreignGstHst,$foreignPst,$foreignTax,$foreignTotal,$subtotal,$gstHst,$pst,$tax,$mode,$total,$taxCode,$receiptPath,$entryId]);
        audit_event($user,$companyId,'expense.posted','expense',$id,['vendor'=>$vendor,'currency'=>$currency,'exchangeRateMicros'=>$rate,'foreignTotalCents'=>$foreignTotal,'totalCents'=>$total,'taxEntryMode'=>$mode]);
        db()->commit();
    } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
    json_response(['expense'=>['id'=>$id,'currency'=>$currency,'exchangeRateMicros'=>$rate,'foreignSubtotalCents'=>$foreignSubtotal,'foreignGstHstCents'=>$foreignGstHst,'foreignPstCents'=>$foreignPst,'foreignTaxCents'=>$foreignTax,'foreignTotalCents'=>$foreignTotal,'subtotalCents'=>$subtotal,'gstHstCents'=>$gstHst,'pstCents'=>$pst,'taxCents'=>$tax,'totalCents'=>$total,'taxEntryMode'=>$mode]],201);
}

/**
 * A matched statement row can still have persisted status=pending in the
 * legacy reconciliation workflow. Pending is therefore not proof that the
 * financial movement is unrecorded. Run under the statement row lock before
 * ANY voucher/payment/journal branch, including requests from cached clients.
 *
 * This containment does not identify an as-yet-unmatched opposite transfer
 * leg. Durable transfer pairing and its UI remain a separate 5.9.9 checkpoint.
 */
function bank_transaction_assert_unrecorded(array $company, array $transaction): void
{
    if (!db()->inTransaction()) {
        throw new LogicException('Recorded-bank-row validation requires the posting transaction.');
    }
    $companyId = (string)$company['id'];
    if ((string)($transaction['company_id'] ?? '') !== $companyId) {
        fail('This statement line is not available in the current company.', 403, 'company_forbidden');
    }
    $hasJournal = trim((string)($transaction['journal_entry_id'] ?? '')) !== '';
    // Locking read avoids an old transaction snapshot missing a match that
    // committed before this request acquired the bank-transaction row lock.
    $hasMatch = active_bank_match_for_bank_transaction($companyId, (string)$transaction['id'], true) !== null;
    if ($hasJournal || $hasMatch) {
        fail(
            'This statement line is already recorded or matched in the books. Review its existing entry in Bank Reconciliation instead of posting it again.',
            409,
            'bank_transaction_already_recorded'
        );
    }
}

/** Shared validated bank-posting service used by the manual UI and Ask Tegh.
 *  Posting/tax/control-account calculations are intentionally unchanged.
 */
function bank_transaction_post_service(array $user, array $company, array $decisions, string $source = 'manual', bool $manageTransaction = true): int
{
    if(!$manageTransaction)return bank_transaction_post_service_once($user,$company,$decisions,$source,false);
    $posted=db_transaction_retry(static fn():int=>bank_transaction_post_service_once($user,$company,$decisions,$source,false),4);
    $transactionIds=array_values(array_map(static fn(array $decision):string=>(string)($decision['id']??''),$decisions));
    if(function_exists('tegh_ai_observe_bank_post_batch'))tegh_ai_observe_bank_post_batch($user,$company,$transactionIds,$source);
    if($source==='ask_tegh'){
        audit_event($user,(string)$company['id'],'ai_agent.bank_batch_posted','bank_transaction_batch',hash('sha256',implode('|',$transactionIds)),[
            'count'=>$posted,'initiatedVia'=>'Ask Tegh','transactionIdsSha256'=>hash('sha256',implode('|',$transactionIds)),
        ]);
    }
    return $posted;
}

function bank_transaction_post_service_once(array $user, array $company, array $decisions, string $source = 'manual', bool $manageTransaction = true): int
{
    require_company_permission($company, 'banking.match');
    $companyId = (string)$company['id'];
    if (count($decisions) < 1 || count($decisions) > 100) fail('Select between 1 and 100 transactions.');
    $seen = [];
    if ($manageTransaction) db()->beginTransaction();
    try {
        $company=tegh_bank_reauthorize_mutation($user,$company);
        $posted = 0;
        foreach ($decisions as $decision) {
            if (!is_array($decision)) fail('A transaction decision is invalid.');
            $transactionId = clean_text($decision['id'] ?? '', 'Transaction', 64);
            if (isset($seen[$transactionId])) fail('The same transaction was selected more than once.');
            $seen[$transactionId] = true;
            $stmt = db()->prepare("SELECT bt.*, ba.ledger_account_id, ba.last_reconciled_date FROM bank_transactions bt JOIN bank_accounts ba ON ba.id = bt.bank_account_id WHERE bt.id = ? AND bt.company_id = ? AND bt.status = 'pending' FOR UPDATE");
            $stmt->execute([$transactionId, $companyId]);
            $transaction = $stmt->fetch();
            if (!$transaction) fail('A selected transaction is no longer available for posting.', 409, 'transaction_unavailable');
            bank_transaction_assert_unrecorded($company, $transaction);
            // Revalidate at the decision site, inside the same row-locking
            // transaction that will post the bank row.
            assert_period_open($companyId,(string)$transaction['transaction_date']);
            $amount = (int)$transaction['amount_cents'];
            if(function_exists('voucher_register_saved'))voucher_register_saved($user,$companyId,'BT','BS','bank_transaction',$transactionId,(string)$transaction['transaction_date'],'Bank statement · '.(string)$transaction['description'],abs($amount),null,false);
            $bankLedgerId = (string)$transaction['ledger_account_id'];
            $paymentId = trim((string)($decision['paymentId'] ?? ''));
            if ($paymentId !== '') {
                match_bank_transaction_to_posted_payment($user,$company,$transaction,clean_text($paymentId,'Payment',64));
                $posted++;
                continue;
            }
            $invoiceId = trim((string)($decision['invoiceId'] ?? ''));
            if ($invoiceId !== '') {
                if ($amount <= 0) fail('Only money-in transactions can be matched to an invoice.');
                $invoiceStmt = db()->prepare("SELECT i.id,i.customer_id,c.name AS party_name,i.number,i.issue_date,i.subtotal_cents,i.tax_cents,i.total_cents,i.balance_cents,
                    i.currency,i.foreign_subtotal_cents,i.foreign_tax_cents,i.foreign_total_cents,i.foreign_balance_cents,i.exchange_rate_micros,i.is_opening_document
                    FROM invoices i JOIN customers c ON c.id=i.customer_id
                    WHERE i.id = ? AND i.company_id = ? AND i.status = 'sent' FOR UPDATE");
                $invoiceStmt->execute([$invoiceId, $companyId]);
                $invoice = $invoiceStmt->fetch();
                if (!$invoice || (int)$invoice['balance_cents'] <= 0) fail('Choose an open issued invoice for this payment.');
                if ((string)$invoice['currency'] !== (string)$transaction['currency']) fail('The bank transaction and invoice currencies must match.');
                $foreignPayment = (int)$transaction['foreign_amount_cents'];
                if ($foreignPayment <= 0 || $foreignPayment > (int)$invoice['foreign_balance_cents']) fail('The payment exceeds the remaining invoice amount in its transaction currency.');
                $carryingReduction = $foreignPayment === (int)$invoice['foreign_balance_cents']
                    ? (int)$invoice['balance_cents']
                    : min((int)$invoice['balance_cents'], convert_to_base_cents($foreignPayment, (int)$invoice['exchange_rate_micros']));
                if (!empty($invoice['is_opening_document']) || (string)$company['accounting_basis'] === 'accrual') {
                    $decidedAccount = account_by_code($companyId, '1200');
                    $lines = [
                        ['accountId' => $bankLedgerId, 'debitCents' => $amount, 'creditCents' => 0],
                        ['accountId' => $decidedAccount, 'debitCents' => 0, 'creditCents' => $carryingReduction],
                    ];
                    $fxDifference = $amount - $carryingReduction;
                    if ($fxDifference > 0) $lines[] = ['accountId' => account_by_code($companyId, '6850'), 'debitCents' => 0, 'creditCents' => $fxDifference];
                    if ($fxDifference < 0) $lines[] = ['accountId' => account_by_code($companyId, '6850'), 'debitCents' => abs($fxDifference), 'creditCents' => 0];
                } else {
                    $taxPortion = (int)round(($amount * (int)$invoice['foreign_tax_cents']) / max(1, (int)$invoice['foreign_total_cents']));
                    $revenuePortion = $amount - $taxPortion;
                    $decidedAccount = account_by_code($companyId, '4000');
                    $lines = [
                        ['accountId' => $bankLedgerId, 'debitCents' => $amount, 'creditCents' => 0],
                        ['accountId' => $decidedAccount, 'debitCents' => 0, 'creditCents' => $revenuePortion],
                    ];
                    [$gstPart, $pstPart] = invoice_tax_parts($invoice, $taxPortion);
                    foreach (invoice_tax_credit_lines($companyId, $gstPart, $pstPart) as $taxLine) $lines[] = $taxLine;
                }
                $entryId = add_journal_entry($user, $companyId, (string)$transaction['transaction_date'], 'bank_transaction', $transactionId, 'Payment matched to ' . $invoice['number'], $lines);
                db()->prepare("UPDATE bank_transactions SET decided_account_id = ?, tax_code = 'NO_TAX', suggestion_source = 'manual', status = 'posted', journal_entry_id = ? WHERE id = ? AND status = 'pending'")
                    ->execute([$decidedAccount, $entryId, $transactionId]);
                $newBalance = (int)$invoice['balance_cents'] - $carryingReduction;
                $foreignReduction = $foreignPayment;
                db()->prepare("UPDATE invoices SET balance_cents = ?, foreign_balance_cents = GREATEST(0, foreign_balance_cents - ?),
                    status = CASE WHEN ? = 0 THEN 'paid' ELSE 'sent' END WHERE id = ? AND balance_cents >= ?")
                    ->execute([$newBalance, $foreignReduction, $newBalance, $invoiceId, $carryingReduction]);
                record_bank_party_payment($user,$companyId,'customer',$invoice,$transaction,$bankLedgerId,$entryId,$amount,$carryingReduction,$foreignPayment);
                audit_event($user, $companyId, 'invoice.payment_matched', 'invoice', $invoiceId, [
                    'transactionId' => $transactionId, 'amountCents' => $amount,
                    'accountingBasis' => (string)$company['accounting_basis'],
                ]);
                audit_event($user, $companyId, 'bank_transaction.posted', 'bank_transaction', $transactionId, ['invoiceId' => $invoiceId, 'accountId' => $decidedAccount]);
                $posted++;
                continue;
            }
            $billId = trim((string)($decision['billId'] ?? ''));
            if ($billId !== '') {
                if ($amount >= 0) fail('Only money-out transactions can be matched to a vendor bill.');
                $payment = abs($amount);
                $billStmt = db()->prepare("SELECT b.id,b.vendor_id,v.name AS party_name,b.number,b.bill_date,b.category_account_id,b.subtotal_cents,b.tax_cents,b.total_cents,
                    b.balance_cents,b.currency,b.foreign_subtotal_cents,b.foreign_tax_cents,b.foreign_total_cents,b.foreign_balance_cents,b.exchange_rate_micros,b.is_opening_document
                    FROM bills b JOIN vendors v ON v.id=b.vendor_id
                    WHERE b.id = ? AND b.company_id = ? AND b.status = 'open' FOR UPDATE");
                $billStmt->execute([$billId, $companyId]);
                $bill = $billStmt->fetch();
                if (!$bill || (int)$bill['balance_cents'] <= 0) fail('Choose an open vendor bill for this payment.');
                if ((string)$bill['currency'] !== (string)$transaction['currency']) fail('The bank transaction and vendor bill currencies must match.');
                $foreignPayment = abs((int)$transaction['foreign_amount_cents']);
                if ($foreignPayment <= 0 || $foreignPayment > (int)$bill['foreign_balance_cents']) fail('The payment exceeds the remaining vendor bill amount in its transaction currency.');
                $carryingReduction = $foreignPayment === (int)$bill['foreign_balance_cents']
                    ? (int)$bill['balance_cents']
                    : min((int)$bill['balance_cents'], convert_to_base_cents($foreignPayment, (int)$bill['exchange_rate_micros']));
                if (!empty($bill['is_opening_document']) || (string)$company['accounting_basis'] === 'accrual') {
                    $decidedAccount = account_by_code($companyId, '2050');
                    $lines = [
                        ['accountId' => $decidedAccount, 'debitCents' => $carryingReduction, 'creditCents' => 0],
                        ['accountId' => $bankLedgerId, 'debitCents' => 0, 'creditCents' => $payment],
                    ];
                    $fxDifference = $payment - $carryingReduction;
                    if ($fxDifference > 0) $lines[] = ['accountId' => account_by_code($companyId, '6850'), 'debitCents' => $fxDifference, 'creditCents' => 0];
                    if ($fxDifference < 0) $lines[] = ['accountId' => account_by_code($companyId, '6850'), 'debitCents' => 0, 'creditCents' => abs($fxDifference)];
                } else {
                    $taxPortion = (int)round(($payment * (int)$bill['foreign_tax_cents']) / max(1, (int)$bill['foreign_total_cents']));
                    $categoryPortion = $payment - $taxPortion;
                    $decidedAccount = (string)$bill['category_account_id'];
                    $lines = [['accountId' => $decidedAccount, 'debitCents' => $categoryPortion, 'creditCents' => 0]];
                    if ($taxPortion > 0) {
                        $lines[] = ['accountId' => account_by_code($companyId, '1100'), 'debitCents' => $taxPortion, 'creditCents' => 0];
                    }
                    $lines[] = ['accountId' => $bankLedgerId, 'debitCents' => 0, 'creditCents' => $payment];
                }
                $entryId = add_journal_entry($user, $companyId, (string)$transaction['transaction_date'], 'bank_transaction', $transactionId, 'Payment matched to bill ' . $bill['number'], $lines);
                db()->prepare("UPDATE bank_transactions SET decided_account_id = ?, tax_code = 'NO_TAX', suggestion_source = 'manual', status = 'posted', journal_entry_id = ? WHERE id = ? AND status = 'pending'")
                    ->execute([$decidedAccount, $entryId, $transactionId]);
                $newBalance = (int)$bill['balance_cents'] - $carryingReduction;
                $foreignReduction = $foreignPayment;
                db()->prepare("UPDATE bills SET balance_cents = ?, foreign_balance_cents = GREATEST(0, foreign_balance_cents - ?),
                    status = CASE WHEN ? = 0 THEN 'paid' ELSE 'open' END WHERE id = ? AND balance_cents >= ?")
                    ->execute([$newBalance, $foreignReduction, $newBalance, $billId, $carryingReduction]);
                record_bank_party_payment($user,$companyId,'vendor',$bill,$transaction,$bankLedgerId,$entryId,$payment,$carryingReduction,$foreignPayment);
                audit_event($user, $companyId, 'bill.payment_matched', 'bill', $billId, [
                    'transactionId' => $transactionId, 'amountCents' => $payment,
                    'accountingBasis' => (string)$company['accounting_basis'],
                ]);
                audit_event($user, $companyId, 'bank_transaction.posted', 'bank_transaction', $transactionId, ['billId' => $billId, 'accountId' => $decidedAccount]);
                $posted++;
                continue;
            }
            // R121: a sales tax remittance to (or refund from) the tax authority
            // settles the protected GST/HST or PST payable control account. It is
            // an explicit decision, never inferred from a chosen GL account, and
            // posts only Dr tax payable / Cr bank (or the reverse for a refund).
            $salesTaxSettlement=trim((string)($decision['salesTaxSettlement']??''));
            if($salesTaxSettlement!==''){
                if(!in_array($salesTaxSettlement,['gst_hst','pst'],true))fail('Choose GST/HST or PST for a sales tax remittance.',422,'sales_tax_settlement_invalid');
                if($salesTaxSettlement==='gst_hst'&&!(bool)$company['tax_registered'])fail('This company is not registered for GST/HST.',422,'sales_tax_settlement_unregistered');
                if($salesTaxSettlement==='pst'&&!(bool)($company['pst_registered']??false))fail('This company is not registered for PST.',422,'sales_tax_settlement_unregistered');
                if($amount===0)fail('A zero-value statement line cannot settle sales tax.',422,'sales_tax_settlement_zero');
                $taxAccountId=account_by_code($companyId,$salesTaxSettlement==='gst_hst'?'2100':'2110');
                $settled=abs($amount);$label=$salesTaxSettlement==='gst_hst'?'GST/HST':'PST';
                // R122: with a return period end, the input tax credits recorded to
                // that date are cleared too (Cr 1100/1110) and the payable account
                // takes the balancing amount, so a payment of collected tax less
                // ITCs settles both accounts.
                $itcCents=0;$itcAccountId=null;$periodEndRaw=trim((string)($decision['salesTaxPeriodEnd']??''));
                if($periodEndRaw!==''){
                    $periodEnd=safe_date($periodEndRaw,'Return period end');
                    if($periodEnd>(string)$transaction['transaction_date'])fail('The return period end must be on or before the bank transaction date.',422,'sales_tax_period_invalid');
                    if($salesTaxSettlement==='gst_hst'||(bool)($company['pst_recoverable']??false)){
                        $itcAccountId=account_by_code($companyId,$salesTaxSettlement==='gst_hst'?'1100':'1110');
                        $itcStmt=db()->prepare("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND jl.account_id=? AND je.status='posted' AND je.entry_date<=?");
                        $itcStmt->execute([$companyId,$itcAccountId,$periodEnd]);$toDate=(int)$itcStmt->fetchColumn();$itcStmt->execute([$companyId,$itcAccountId,'9999-12-31']);$remaining=(int)$itcStmt->fetchColumn();$itcCents=max(0,min($toDate,$remaining)); // never clear credits a prior settlement already cleared
                    }
                }
                $balancing=-$amount+$itcCents; // debit to the payable account (negative means credit)
                $lines=[];
                if($amount<0)$lines[]=['accountId'=>$bankLedgerId,'debitCents'=>0,'creditCents'=>$settled];else $lines[]=['accountId'=>$bankLedgerId,'debitCents'=>$settled,'creditCents'=>0];
                if($itcCents>0)$lines[]=['accountId'=>$itcAccountId,'debitCents'=>0,'creditCents'=>$itcCents,'memo'=>'Input tax credits to '.$periodEnd];
                if($balancing>0)$lines[]=['accountId'=>$taxAccountId,'debitCents'=>$balancing,'creditCents'=>0,'memo'=>$label.($amount<0?' remittance':' return')];
                elseif($balancing<0)$lines[]=['accountId'=>$taxAccountId,'debitCents'=>0,'creditCents'=>-$balancing,'memo'=>$label.' refund'];
                $remarks=mb_substr(trim((string)($decision['remarks']??'')),0,500);$journalMemo=$remarks!==''?$remarks:(string)$transaction['description'];
                $entryId=add_journal_entry($user,$companyId,(string)$transaction['transaction_date'],'bank_transaction',$transactionId,$journalMemo,$lines);
                db()->prepare("UPDATE bank_transactions SET decided_account_id=?, remarks=?, tax_code='NO_TAX', suggestion_source='manual', status='posted', journal_entry_id=? WHERE id=? AND company_id=? AND status='pending'")
                    ->execute([$taxAccountId,$remarks,$entryId,$transactionId,$companyId]);
                audit_event($user,$companyId,'bank_transaction.posted','bank_transaction',$transactionId,['accountId'=>$taxAccountId,'salesTaxSettlement'=>$salesTaxSettlement,'amountCents'=>$amount,'remarks'=>$remarks]);
                $posted++;
                continue;
            }
            $selectedAccountId=trim((string)($decision['accountId']??''));
            if($selectedAccountId!==''){
                $selectedCapability=tegh_account_capability_resolve($companyId,$selectedAccountId,true);
                if(in_array((string)$selectedCapability['kind'],['customer_control','vendor_control'],true)){
                    tegh_account_capability_assert_context($selectedCapability,['partyId'=>$decision['partyId']??'']);
                    $partnerPayment=record_bank_open_party_payment($user,$company,$transaction,$selectedCapability,$decision);
                    audit_event($user,$companyId,'bank_transaction.posted','bank_transaction',$transactionId,['accountId'=>$selectedAccountId,'partnerPayment'=>$partnerPayment,'remarks'=>mb_substr(trim((string)($decision['remarks']??'')),0,500)]);
                    $posted++;
                    continue;
                }
                if((string)$selectedCapability['kind']==='financial_account')$decision['transferBankAccountId']=(string)$selectedCapability['financialAccount']['id'];
                elseif((string)$selectedCapability['kind']!=='ordinary_gl')tegh_account_capability_assert_context($selectedCapability,$decision);
            }
            $isTransfer=trim((string)($decision['transferBankAccountId']??''))!=='';
            $transfer=null;
            if($isTransfer){
                $transfer=tegh_bank_resolve_transfer($company,$transaction,$decision);
                $accountId=$transfer['counterpartyLedger'];$category=company_account($companyId,$accountId);
                $decision['taxCode']='NO_TAX';$decision['applyGstHst']=false;$decision['applyPst']=false;
            }else{
                $accountId=clean_text($decision['accountId']??'','Bookkeeping account',64);
                $category=tegh_bank_category($companyId,$accountId,$amount,($decision['allowContra']??false)===true);
            }
            $lines = [];
            $taxCode = 'NO_TAX';
            if ($amount > 0) {
                if (!in_array($category['account_type'], ['income','equity','liability','asset'], true) && ($decision['allowContra']??false)!==true) fail('Money-in requires an income, equity, liability, or transfer account.');
                $total = $amount;
                $applyGstHst = !empty($decision['applyGstHst']) && (bool)$company['tax_registered'] && $category['account_type'] === 'income';
                $applyPst = !empty($decision['applyPst']) && (bool)($company['pst_registered'] ?? false) && $category['account_type'] === 'income';
                if ($applyGstHst || $applyPst) {
                    // Guided bookkeeping treats an imported deposit as the gross
                    // bank amount. Extract collected sales taxes so the income
                    // account receives net revenue while the bank still agrees
                    // exactly to the uploaded statement.
                    $parts = calculate_tax_components(
                        $total,
                        'inclusive',
                        $applyGstHst ? (int)$company['tax_rate_bps'] : 0,
                        $applyPst ? (int)($company['pst_rate_bps'] ?? 0) : 0
                    );
                    $gstHst = (int)$parts['gstHstCents'];
                    $pst = (int)$parts['pstCents'];
                    $taxCode = $gstHst > 0 && $pst > 0 ? 'GST_HST_PST' : ($gstHst > 0 ? 'GST_HST' : ($pst > 0 ? 'PST' : 'NO_TAX'));
                    $lines[] = ['accountId' => $bankLedgerId, 'debitCents' => $total, 'creditCents' => 0, 'memo' => 'Statement deposit'];
                    $lines[] = ['accountId' => (string)$category['id'], 'debitCents' => 0, 'creditCents' => (int)$parts['netCents'], 'memo' => 'Income net of collected sales tax'];
                    if ($gstHst > 0) $lines[] = ['accountId' => account_by_code($companyId, '2100'), 'debitCents' => 0, 'creditCents' => $gstHst, 'memo' => 'GST/HST collected'];
                    if ($pst > 0) $lines[] = ['accountId' => account_by_code($companyId, '2110'), 'debitCents' => 0, 'creditCents' => $pst, 'memo' => 'PST collected'];
                } else {
                    $lines = [
                        ['accountId' => $bankLedgerId, 'debitCents' => $amount, 'creditCents' => 0],
                        ['accountId' => (string)$category['id'], 'debitCents' => 0, 'creditCents' => $amount],
                    ];
                }
            } else {
                if (!in_array($category['account_type'], ['expense','asset','equity','liability'], true) && ($decision['allowContra']??false)!==true) fail('Money-out requires an expense, asset, equity, liability, or transfer account.');
                $total = abs($amount);
                $legacyTaxRequested = in_array((string)($decision['taxCode'] ?? ''), ['GST_HST','HST13'], true);
                $applyGstHst = ($legacyTaxRequested || !empty($decision['applyGstHst'])) && (bool)$company['tax_registered'] && in_array($category['account_type'], ['expense','asset'], true);
                $applyPst = !empty($decision['applyPst']) && (bool)($company['pst_registered'] ?? false) && in_array($category['account_type'], ['expense','asset'], true);
                $parts = calculate_tax_components(
                    $total,
                    ($applyGstHst || $applyPst) ? 'inclusive' : 'none',
                    $applyGstHst ? (int)$company['tax_rate_bps'] : 0,
                    $applyPst ? (int)($company['pst_rate_bps'] ?? 0) : 0
                );
                $gstHst = (int)$parts['gstHstCents'];
                $pst = (int)$parts['pstCents'];
                $pstRecoverable = (bool)($company['pst_recoverable'] ?? false);
                $categoryDebit = (int)$parts['netCents'] + ($pstRecoverable ? 0 : $pst);
                $taxCode = $gstHst > 0 && $pst > 0 ? 'GST_HST_PST' : ($gstHst > 0 ? 'GST_HST' : ($pst > 0 ? 'PST' : 'NO_TAX'));
                $lines[] = ['accountId' => (string)$category['id'], 'debitCents' => $categoryDebit, 'creditCents' => 0, 'memo' => $pst > 0 && !$pstRecoverable ? 'Expense net + non-recoverable PST' : 'Expense net'];
                if ($gstHst > 0) $lines[] = ['accountId' => account_by_code($companyId, '1100'), 'debitCents' => $gstHst, 'creditCents' => 0, 'memo' => 'GST/HST recoverable'];
                if ($pst > 0 && $pstRecoverable) $lines[] = ['accountId' => account_by_code($companyId, '1110'), 'debitCents' => $pst, 'creditCents' => 0, 'memo' => 'PST recoverable'];
                $lines[] = ['accountId' => $bankLedgerId, 'debitCents' => 0, 'creditCents' => $total];
            }
            $remarks=mb_substr(trim((string)($decision['remarks']??'')),0,500);$journalMemo=$remarks!==''?$remarks:(string)$transaction['description'];
            $entryId = add_journal_entry($user, $companyId, (string)$transaction['transaction_date'], 'bank_transaction', $transactionId, $journalMemo, $lines);
            if($isTransfer)$transfer=tegh_interbank_record_first_leg($user,$company,$transaction,$decision,$transfer,$entryId);
            db()->prepare("UPDATE bank_transactions SET decided_account_id = ?, remarks=?, tax_code = ?, suggestion_source = 'manual', status = 'posted', journal_entry_id = ? WHERE id = ? AND company_id=? AND status = 'pending'")
                ->execute([$category['id'],$remarks,$taxCode,$entryId,$transactionId,$companyId]);
            if (!$isTransfer && !(bool)$category['is_control'] && empty($decision['allowContra'])) {
                $merchant = normalize_merchant((string)$transaction['description']);
                if ($merchant !== '') {
                    db()->prepare("INSERT INTO category_rules (id, company_id, merchant_pattern, account_id, tax_code, use_count) VALUES (?, ?, ?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), tax_code = VALUES(tax_code), use_count = use_count + 1")
                        ->execute([new_id('rule'), $companyId, $merchant, $category['id'], $taxCode]);
                }
            }
            audit_event($user, $companyId, 'bank_transaction.posted', 'bank_transaction', $transactionId, ['accountId' => $category['id'], 'taxCode' => $taxCode, 'transfer'=>$transfer, 'remarks'=>$remarks, 'contraAcknowledged'=>($decision['allowContra']??false)===true]);
            $posted++;
        }
        if ($source === 'ask_tegh' && function_exists('tegh_ai_verify_bank_post')) {
            $verification=tegh_ai_verify_bank_post($company,array_keys($seen));
            if(empty($verification['ok'])) throw new RuntimeException('Ask Tegh post-verification failed; the posting transaction was rolled back.');
        }
        if ($manageTransaction) db()->commit();
    } catch (Throwable $error) {
        if ($manageTransaction && db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    return $posted;
}


/** Normalize legacy persisted tax labels to the canonical posting outcome. */
function bank_transaction_canonical_tax_code(string $taxCode): string
{
    return $taxCode === 'HST13' ? 'GST_HST' : $taxCode;
}

/**
 * Resolve the tax code that the posting service would actually persist for a
 * direct-GL decision. This mirrors the company registration, account-type,
 * configured-rate, and cent-rounding rules in the posting branch above.
 */
function bank_transaction_effective_tax_code(array $company,array $decision,int $amount,string $accountType): string
{
    $gstHstRate=0;$pstRate=0;
    if($amount>0){
        $gstRequested=!empty($decision['applyGstHst'])&&(bool)$company['tax_registered']&&$accountType==='income';
        $pstRequested=!empty($decision['applyPst'])&&(bool)($company['pst_registered']??false)&&$accountType==='income';
    }else{
        $taxCode=(string)($decision['taxCode']??'');
        $legacyGstRequested=in_array($taxCode,['GST_HST','HST13'],true);
        $taxEligible=in_array($accountType,['expense','asset'],true);
        $gstRequested=($legacyGstRequested||!empty($decision['applyGstHst']))&&(bool)$company['tax_registered']&&$taxEligible;
        $pstRequested=!empty($decision['applyPst'])&&(bool)($company['pst_registered']??false)&&$taxEligible;
    }
    if($gstRequested)$gstHstRate=(int)$company['tax_rate_bps'];
    if($pstRequested)$pstRate=(int)($company['pst_rate_bps']??0);
    $parts=calculate_tax_components(abs($amount),($gstHstRate||$pstRate)?'inclusive':'none',$gstHstRate,$pstRate);
    $gstHst=(int)$parts['gstHstCents'];$pst=(int)$parts['pstCents'];
    return $gstHst>0&&$pst>0?'GST_HST_PST':($gstHst>0?'GST_HST':($pst>0?'PST':'NO_TAX'));
}

/**
 * Return an idempotent success record when a bank transaction was already
 * posted using the same effective user decision. A different decision is a
 * conflict: retries may recover a lost response, but they may never silently
 * change the accounting treatment of a committed bank line.
 *
 * @return array<string,mixed>|null Null means the row is still pending.
 */
function bank_transaction_existing_post_result(array $company, array $decision): ?array
{
    $companyId=(string)$company['id'];
    $transactionId=clean_text($decision['id']??'','Transaction',64);
    $stmt=db()->prepare("SELECT bt.id,bt.status,bt.amount_cents,bt.bank_account_id,bt.currency,bt.decided_account_id,bt.journal_entry_id,bt.tax_code,
      a.account_type decided_account_type,je.source_type bank_journal_source_type,linked.id linked_payment_id
      FROM bank_transactions bt
      LEFT JOIN accounts a ON a.id=bt.decided_account_id AND a.company_id=bt.company_id
      LEFT JOIN journal_entries je ON je.id=bt.journal_entry_id AND je.company_id=bt.company_id
      LEFT JOIN party_payments linked ON linked.bank_transaction_id=bt.id AND linked.company_id=bt.company_id
      WHERE bt.id=? AND bt.company_id=? LIMIT 1");
    $stmt->execute([$transactionId,$companyId]);$transaction=$stmt->fetch();
    if(!$transaction)fail('A selected transaction is no longer available for posting.',409,'transaction_unavailable');
    if((string)$transaction['status']==='pending')return null;
    if((string)$transaction['status']!=='posted')fail('This bank transaction is not available for posting.',409,'transaction_unavailable');

    $paymentId=trim((string)($decision['paymentId']??''));
    $invoiceId=trim((string)($decision['invoiceId']??''));
    $billId=trim((string)($decision['billId']??''));
    $accountId=trim((string)($decision['accountId']??''));
    if(trim((string)($decision['transferBankAccountId']??''))!==''){
        $q=db()->prepare('SELECT ledger_account_id FROM bank_accounts WHERE company_id=? AND id=? AND id<>?');$q->execute([$companyId,(string)$decision['transferBankAccountId'],(string)$transaction['bank_account_id']]);$ledger=$q->fetchColumn();
        if($ledger===false)fail('The recorded transfer decision does not match this company.',409,'bank_post_decision_conflict');
        $accountId=(string)$ledger;$decision['taxCode']='NO_TAX';$decision['applyGstHst']=false;$decision['applyPst']=false;
    }
    $matches=false;$kind='';
    // A direct GL post has no linked party-payment source. A direct document
    // match creates its bank journal under bank_transaction; matching a payment
    // that was posted earlier retains that payment journal (or bank_payment_match).
    $linkedPaymentId=(string)($transaction['linked_payment_id']??'');
    $journalSource=(string)($transaction['bank_journal_source_type']??'');
    $storedKind=$linkedPaymentId===''?'gl':($journalSource==='bank_transaction'?'document':(in_array($journalSource,['customer_payment','vendor_payment','bank_payment_match'],true)?'payment':'unknown'));

    if($paymentId!==''){
        $q=db()->prepare("SELECT id FROM party_payments WHERE id=? AND company_id=? AND bank_transaction_id=? AND status='posted' LIMIT 1");
        $q->execute([$paymentId,$companyId,$transactionId]);$matches=$storedKind==='payment'&&$q->fetchColumn()!==false;$kind='payment';
    }elseif($invoiceId!==''||$billId!==''){
        $documentId=$invoiceId!==''?$invoiceId:$billId;$type=$invoiceId!==''?'customer':'vendor';
        $q=db()->prepare("SELECT id FROM party_payments WHERE company_id=? AND bank_transaction_id=? AND document_id=? AND payment_type=? AND status='posted' LIMIT 1");
        $q->execute([$companyId,$transactionId,$documentId,$type]);$matches=$storedKind==='document'&&$q->fetchColumn()!==false;$kind=$type.'_document';
    }elseif($accountId!==''){
        $matches=$storedKind==='gl'&&(string)($transaction['decided_account_id']??'')===$accountId;
        if($matches){
            $requestedTax=bank_transaction_effective_tax_code($company,$decision,(int)$transaction['amount_cents'],(string)($transaction['decided_account_type']??''));
            $existingTax=bank_transaction_canonical_tax_code((string)($transaction['tax_code']??'NO_TAX'));
            $matches=$requestedTax===$existingTax;
        }
        $kind='gl';
    }
    if(!$matches){
        fail('This bank transaction was already posted with a different bookkeeping decision. Open the posted transaction instead of posting it again.',409,'bank_post_decision_conflict');
    }
    return [
        'id'=>$transactionId,'status'=>'already_posted','kind'=>$kind,
        'journalEntryId'=>$transaction['journal_entry_id']!==null?(string)$transaction['journal_entry_id']:null,
        'accountId'=>$transaction['decided_account_id']!==null?(string)$transaction['decided_account_id']:null,
        'idempotent'=>true,
    ];
}

function handle_bank_transaction_post(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $input = request_json();
    $decisions = $input['decisions'] ?? null;
    if (!is_array($decisions) || count($decisions) < 1 || count($decisions) > 100) fail('Select between 1 and 100 transactions.');

    $seen=[];$pending=[];$results=[];$already=0;
    foreach($decisions as $decision){
        if(!is_array($decision))fail('A transaction decision is invalid.');
        $id=clean_text($decision['id']??'','Transaction',64);
        if(isset($seen[$id]))fail('The same transaction was selected more than once.');
        $seen[$id]=true;
        $existing=bank_transaction_existing_post_result($company,$decision);
        if($existing!==null){$results[]=$existing;$already++;}else{$pending[]=$decision;}
    }
    $posted=0;
    if($pending){
        $posted=bank_transaction_post_service($user,$company,$pending);
        foreach($pending as $decision){
            $id=(string)$decision['id'];
            $stmt=db()->prepare("SELECT journal_entry_id,decided_account_id FROM bank_transactions WHERE id=? AND company_id=? AND status='posted' LIMIT 1");
            $stmt->execute([$id,(string)$company['id']]);$row=$stmt->fetch();
            $results[]=['id'=>$id,'status'=>'posted','journalEntryId'=>$row&&$row['journal_entry_id']!==null?(string)$row['journal_entry_id']:null,'accountId'=>$row&&$row['decided_account_id']!==null?(string)$row['decided_account_id']:null,'idempotent'=>false];
        }
    }
    json_response(['posted'=>$posted,'alreadyPosted'=>$already,'failed'=>0,'results'=>$results]);
}


/**
 * Build one audit-safe reclassification journal for a bank line that was
 * previously posted to a non-control GL account. The original bank posting is
 * retained. Only its non-bank side is reversed and replaced, so the bank
 * balance is not posted twice and the correction has zero net bank impact.
 *
 * @param array<int,array{accountId:string,debitCents:int,creditCents:int,memo?:string}> $replacementLines
 * @return array<int,array{accountId:string,debitCents:int,creditCents:int,memo:string}>
 */
function bank_reassignment_lines(string $companyId, array $transaction, string $bankLedgerId, array $replacementLines): array
{
    $entryId = (string)($transaction['journal_entry_id'] ?? '');
    if ($entryId === '') fail('The posted bank transaction has no General Ledger entry to correct.', 409, 'bank_reassignment_journal_missing');
    $stmt = db()->prepare("SELECT je.source_type,je.source_id,jl.account_id,jl.debit_cents,jl.credit_cents,jl.memo
        FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id
        WHERE je.id=? AND je.company_id=? AND je.status='posted' ORDER BY jl.id");
    $stmt->execute([$entryId,$companyId]);
    $rows = $stmt->fetchAll();
    if (count($rows) < 2 || (string)$rows[0]['source_type'] !== 'bank_transaction'
        || (string)$rows[0]['source_id'] !== (string)$transaction['id']) {
        fail('Only a bank statement line posted directly to the General Ledger can be reassigned.', 409, 'bank_reassignment_not_direct_gl');
    }

    $net = [];
    $bankImpact = 0;
    foreach ($rows as $row) {
        $accountId = (string)$row['account_id'];
        $debit = (int)$row['debit_cents'];
        $credit = (int)$row['credit_cents'];
        if ($accountId === $bankLedgerId) {
            $bankImpact += $debit - $credit;
            continue;
        }
        // Reverse the former non-bank classification.
        $net[$accountId] = ($net[$accountId] ?? 0) + $credit - $debit;
    }
    if ($bankImpact !== (int)$transaction['amount_cents']) {
        fail('The original bank journal does not agree with the imported transaction amount.', 409, 'bank_reassignment_amount_mismatch');
    }
    foreach ($replacementLines as $line) {
        $accountId = (string)($line['accountId'] ?? '');
        if ($accountId === '' || $accountId === $bankLedgerId) continue;
        $net[$accountId] = ($net[$accountId] ?? 0) + (int)($line['debitCents'] ?? 0) - (int)($line['creditCents'] ?? 0);
    }

    $lines = [];
    foreach ($net as $accountId => $signed) {
        if ($signed === 0) continue;
        $lines[] = [
            'accountId' => (string)$accountId,
            'debitCents' => $signed > 0 ? $signed : 0,
            'creditCents' => $signed < 0 ? abs($signed) : 0,
            'memo' => 'Reclassify posted bank statement transaction',
        ];
    }
    // An empty set means the original non-control GL classification already
    // equals the cash-basis document posting. The document may still be linked
    // without creating a duplicate or zero-value journal.
    return $lines;
}

/**
 * Assign a bank statement transaction that was already posted to a generic GL
 * account to an open customer invoice or vendor bill. The original posting is
 * never deleted. A separate zero-bank-impact correction journal and a linked
 * party-payment record preserve the complete audit trail.
 */
function handle_bank_transaction_reassign(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    $transactionId = clean_text($input['id'] ?? '', 'Transaction', 64);
    $invoiceId = trim((string)($input['invoiceId'] ?? ''));
    $billId = trim((string)($input['billId'] ?? ''));
    if (($invoiceId === '') === ($billId === '')) fail('Choose exactly one open invoice or vendor bill.');

    db()->beginTransaction();
    try {
        $stmt = db()->prepare("SELECT bt.*,ba.ledger_account_id,decided.name AS decided_account_name,
            decided.is_control AS decided_account_is_control
            FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id
            LEFT JOIN accounts decided ON decided.id=bt.decided_account_id
            WHERE bt.id=? AND bt.company_id=? AND bt.status='posted' FOR UPDATE");
        $stmt->execute([$transactionId,$companyId]);
        $transaction = $stmt->fetch();
        if (!$transaction) fail('Choose an available posted bank transaction.', 409, 'posted_bank_transaction_unavailable');
        if ($transaction['journal_entry_id'] === null || (bool)$transaction['decided_account_is_control']) {
            fail('Only a bank transaction posted directly to a non-control GL account can be reassigned.', 409, 'bank_reassignment_not_direct_gl');
        }
        $linked = db()->prepare("SELECT id FROM party_payments WHERE company_id=? AND bank_transaction_id=? AND status='posted' LIMIT 1 FOR UPDATE");
        $linked->execute([$companyId,$transactionId]);
        if ($linked->fetchColumn() !== false) fail('This bank transaction is already assigned to a customer or vendor payment.', 409, 'bank_reassignment_already_matched');

        $amount = (int)$transaction['amount_cents'];
        $bankLedgerId = (string)$transaction['ledger_account_id'];
        $oldAccountId = $transaction['decided_account_id'] !== null ? (string)$transaction['decided_account_id'] : null;
        $correctionEntryId = null;
        $paymentJournalEntryId = (string)$transaction['journal_entry_id'];
        $paymentId = null;
        $documentNumber = '';
        $paymentType = '';
        $decidedAccount = '';

        if ($invoiceId !== '') {
            if ($amount <= 0) fail('Only money-in transactions can be assigned to a customer invoice.');
            $invoiceId = clean_text($invoiceId, 'Invoice', 64);
            $invoiceStmt = db()->prepare("SELECT i.id,i.customer_id,c.name AS party_name,i.number,i.issue_date,i.balance_cents,
                i.currency,i.foreign_tax_cents,i.foreign_total_cents,i.foreign_balance_cents,i.exchange_rate_micros,i.is_opening_document
                FROM invoices i JOIN customers c ON c.id=i.customer_id
                WHERE i.id=? AND i.company_id=? AND i.status='sent' FOR UPDATE");
            $invoiceStmt->execute([$invoiceId,$companyId]);
            $invoice = $invoiceStmt->fetch();
            if (!$invoice || (int)$invoice['balance_cents'] <= 0) fail('Choose an open issued invoice for this payment.');
            if ((string)$invoice['currency'] !== (string)$transaction['currency']) fail('The bank transaction and invoice currencies must match.');
            $foreignPayment = (int)$transaction['foreign_amount_cents'];
            if ($foreignPayment <= 0 || $foreignPayment > (int)$invoice['foreign_balance_cents']) fail('The payment exceeds the remaining invoice amount in its transaction currency.');
            $carryingReduction = $foreignPayment === (int)$invoice['foreign_balance_cents']
                ? (int)$invoice['balance_cents']
                : min((int)$invoice['balance_cents'], convert_to_base_cents($foreignPayment, (int)$invoice['exchange_rate_micros']));
            if (!empty($invoice['is_opening_document']) || (string)$company['accounting_basis'] === 'accrual') {
                $decidedAccount = account_by_code($companyId, '1200');
                $replacement = [
                    ['accountId'=>$bankLedgerId,'debitCents'=>$amount,'creditCents'=>0],
                    ['accountId'=>$decidedAccount,'debitCents'=>0,'creditCents'=>$carryingReduction],
                ];
                $fxDifference = $amount - $carryingReduction;
                if ($fxDifference > 0) $replacement[]=['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>0,'creditCents'=>$fxDifference];
                if ($fxDifference < 0) $replacement[]=['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>abs($fxDifference),'creditCents'=>0];
            } else {
                $taxPortion = (int)round(($amount * (int)$invoice['foreign_tax_cents']) / max(1,(int)$invoice['foreign_total_cents']));
                $revenuePortion = $amount - $taxPortion;
                $decidedAccount = account_by_code($companyId, '4000');
                $replacement = [
                    ['accountId'=>$bankLedgerId,'debitCents'=>$amount,'creditCents'=>0],
                    ['accountId'=>$decidedAccount,'debitCents'=>0,'creditCents'=>$revenuePortion],
                ];
                [$gstPart,$pstPart]=invoice_tax_parts($invoice,$taxPortion);foreach(invoice_tax_credit_lines($companyId,$gstPart,$pstPart) as $taxLine)$replacement[]=$taxLine;
            }
            $correctionLines = bank_reassignment_lines($companyId,$transaction,$bankLedgerId,$replacement);
            if (count($correctionLines) >= 2) {
                $correctionEntryId = add_journal_entry($user,$companyId,(string)$transaction['transaction_date'],
                    'bank_document_reassignment',$transactionId,'Reassign posted bank transaction to invoice '.$invoice['number'],
                    $correctionLines);
                $paymentJournalEntryId = $correctionEntryId;
            }
            $newBalance = (int)$invoice['balance_cents'] - $carryingReduction;
            db()->prepare("UPDATE invoices SET balance_cents=?,foreign_balance_cents=GREATEST(0,foreign_balance_cents-?),
                status=CASE WHEN ?=0 THEN 'paid' ELSE 'sent' END WHERE id=? AND balance_cents>=?")
                ->execute([$newBalance,$foreignPayment,$newBalance,$invoiceId,$carryingReduction]);
            $paymentId = record_bank_party_payment($user,$companyId,'customer',$invoice,$transaction,$bankLedgerId,
                $paymentJournalEntryId,$amount,$carryingReduction,$foreignPayment);
            $documentNumber = (string)$invoice['number'];
            $paymentType = 'customer';
            audit_event($user,$companyId,'invoice.payment_reassigned_from_gl','invoice',$invoiceId,[
                'transactionId'=>$transactionId,'paymentId'=>$paymentId,'correctionJournalEntryId'=>$correctionEntryId,'amountCents'=>$amount,
            ]);
        } else {
            if ($amount >= 0) fail('Only money-out transactions can be assigned to a vendor bill.');
            $billId = clean_text($billId, 'Vendor bill', 64);
            $payment = abs($amount);
            $billStmt = db()->prepare("SELECT b.id,b.vendor_id,v.name AS party_name,b.number,b.bill_date,b.category_account_id,b.balance_cents,
                b.currency,b.foreign_tax_cents,b.foreign_total_cents,b.foreign_balance_cents,b.exchange_rate_micros,b.is_opening_document
                FROM bills b JOIN vendors v ON v.id=b.vendor_id
                WHERE b.id=? AND b.company_id=? AND b.status='open' FOR UPDATE");
            $billStmt->execute([$billId,$companyId]);
            $bill = $billStmt->fetch();
            if (!$bill || (int)$bill['balance_cents'] <= 0) fail('Choose an open vendor bill for this payment.');
            if ((string)$bill['currency'] !== (string)$transaction['currency']) fail('The bank transaction and vendor bill currencies must match.');
            $foreignPayment = abs((int)$transaction['foreign_amount_cents']);
            if ($foreignPayment <= 0 || $foreignPayment > (int)$bill['foreign_balance_cents']) fail('The payment exceeds the remaining vendor bill amount in its transaction currency.');
            $carryingReduction = $foreignPayment === (int)$bill['foreign_balance_cents']
                ? (int)$bill['balance_cents']
                : min((int)$bill['balance_cents'], convert_to_base_cents($foreignPayment, (int)$bill['exchange_rate_micros']));
            if (!empty($bill['is_opening_document']) || (string)$company['accounting_basis'] === 'accrual') {
                $decidedAccount = account_by_code($companyId,'2050');
                $replacement = [
                    ['accountId'=>$decidedAccount,'debitCents'=>$carryingReduction,'creditCents'=>0],
                    ['accountId'=>$bankLedgerId,'debitCents'=>0,'creditCents'=>$payment],
                ];
                $fxDifference = $payment - $carryingReduction;
                if ($fxDifference > 0) $replacement[]=['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>$fxDifference,'creditCents'=>0];
                if ($fxDifference < 0) $replacement[]=['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>0,'creditCents'=>abs($fxDifference)];
            } else {
                $taxPortion = (int)round(($payment * (int)$bill['foreign_tax_cents']) / max(1,(int)$bill['foreign_total_cents']));
                $categoryPortion = $payment - $taxPortion;
                $decidedAccount = (string)$bill['category_account_id'];
                $replacement = [['accountId'=>$decidedAccount,'debitCents'=>$categoryPortion,'creditCents'=>0]];
                if ($taxPortion > 0) $replacement[]=['accountId'=>account_by_code($companyId,'1100'),'debitCents'=>$taxPortion,'creditCents'=>0];
                $replacement[]=['accountId'=>$bankLedgerId,'debitCents'=>0,'creditCents'=>$payment];
            }
            $correctionLines = bank_reassignment_lines($companyId,$transaction,$bankLedgerId,$replacement);
            if (count($correctionLines) >= 2) {
                $correctionEntryId = add_journal_entry($user,$companyId,(string)$transaction['transaction_date'],
                    'bank_document_reassignment',$transactionId,'Reassign posted bank transaction to bill '.$bill['number'],
                    $correctionLines);
                $paymentJournalEntryId = $correctionEntryId;
            }
            $newBalance = (int)$bill['balance_cents'] - $carryingReduction;
            db()->prepare("UPDATE bills SET balance_cents=?,foreign_balance_cents=GREATEST(0,foreign_balance_cents-?),
                status=CASE WHEN ?=0 THEN 'paid' ELSE 'open' END WHERE id=? AND balance_cents>=?")
                ->execute([$newBalance,$foreignPayment,$newBalance,$billId,$carryingReduction]);
            $paymentId = record_bank_party_payment($user,$companyId,'vendor',$bill,$transaction,$bankLedgerId,
                $paymentJournalEntryId,$payment,$carryingReduction,$foreignPayment);
            $documentNumber = (string)$bill['number'];
            $paymentType = 'vendor';
            audit_event($user,$companyId,'bill.payment_reassigned_from_gl','bill',$billId,[
                'transactionId'=>$transactionId,'paymentId'=>$paymentId,'correctionJournalEntryId'=>$correctionEntryId,'amountCents'=>$payment,
            ]);
        }

        db()->prepare("UPDATE bank_transactions SET decided_account_id=?,tax_code='NO_TAX',suggestion_source='manual'
            WHERE id=? AND company_id=? AND status='posted'")->execute([$decidedAccount,$transactionId,$companyId]);
        audit_event($user,$companyId,'bank_transaction.reassigned_from_gl','bank_transaction',$transactionId,[
            'paymentType'=>$paymentType,'paymentId'=>$paymentId,'documentNumber'=>$documentNumber,
            'oldAccountId'=>$oldAccountId,'newAccountId'=>$decidedAccount,'originalJournalEntryId'=>(string)$transaction['journal_entry_id'],
            'correctionJournalEntryId'=>$correctionEntryId,
        ]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['reassigned'=>true,'transactionId'=>$transactionId,'paymentId'=>$paymentId,
        'paymentType'=>$paymentType,'documentNumber'=>$documentNumber,'correctionJournalEntryId'=>$correctionEntryId]);
}

/** @return array<int,string> */
function requested_bank_transaction_ids(array $input): array
{
    // `ids` is the canonical contract. Keep accepting the v2.4.6 browser's
    // legacy `transactionIds` key so an already-cached portal cannot turn a
    // safe delete/exclude/restore request into a misleading validation 400.
    $source = $input['ids'] ?? ($input['transactionIds'] ?? null);
    if (!is_array($source) || count($source) < 1 || count($source) > 100) {
        fail('Select between 1 and 100 transactions.');
    }
    $ids = [];
    foreach ($source as $value) {
        $id = clean_text($value, 'Transaction', 64);
        if (isset($ids[$id])) fail('The same transaction was selected more than once.');
        $ids[$id] = true;
    }
    return array_keys($ids);
}

/** Shared validated bulk-categorisation service used by the manual UI and Ask Tegh. */
function bank_transaction_categorize_service(array $user, array $company, array $ids, string $accountId, ?bool $taxRequested = false, string $source = 'manual'): array
{
    require_company_permission($company, 'banking.match');
    $companyId=(string)$company['id'];
    if(count($ids)<1 || count($ids)>100) fail('Select between 1 and 100 transactions.');
    $category=company_account($companyId,$accountId);
    if(!$category) fail('Bulk categorisation requires an active non-control bookkeeping account.');
    $ids=array_values(array_unique(array_map(static fn($v):string=>clean_text($v,'Transaction',64),$ids)));
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    db()->beginTransaction();
    try{
        $stmt=db()->prepare("SELECT id,amount_cents,status,tax_code,transaction_date FROM bank_transactions WHERE company_id=? AND id IN ($placeholders) FOR UPDATE");
        $stmt->execute(array_merge([$companyId],$ids));$rows=$stmt->fetchAll();
        if(count($rows)!==count($ids)) fail('One or more selected transactions are unavailable.',409,'transaction_unavailable');
        foreach($rows as $row){
            if((string)$row['status']!=='pending') fail('Only pending transactions can be categorised.',409,'transaction_status_conflict');
            $amount=(int)$row['amount_cents'];
            tegh_bank_category($companyId,$accountId,$amount,false);
            assert_period_open($companyId,(string)($row['transaction_date']??''));
            if($amount>0 && !in_array((string)$category['account_type'],['income','equity','liability','asset'],true)) fail('The selected category is not valid for every money-in transaction.');
            if($amount<0 && !in_array((string)$category['account_type'],['expense','asset','equity','liability'],true)) fail('The selected category is not valid for every money-out transaction.');
        }
        $allExpensePayments=(string)$category['account_type']==='expense' && count(array_filter($rows,static fn(array $row):bool=>(int)$row['amount_cents']<0))===count($rows);
        $taxCode=$taxRequested===null?'PRESERVED':($taxRequested && $allExpensePayments && (bool)$company['tax_registered']?'GST_HST':'NO_TAX');
        if($taxRequested===null){
            $update=db()->prepare("UPDATE bank_transactions SET decided_account_id=?,suggestion_source='manual',confidence=100,ai_explanation=NULL WHERE company_id=? AND status='pending' AND id IN ($placeholders)");
            $update->execute(array_merge([$accountId,$companyId],$ids));
        }else{
            $update=db()->prepare("UPDATE bank_transactions SET decided_account_id=?,tax_code=?,suggestion_source='manual',confidence=100,ai_explanation=NULL WHERE company_id=? AND status='pending' AND id IN ($placeholders)");
            $update->execute(array_merge([$accountId,$taxCode,$companyId],$ids));
        }
        audit_event($user,$companyId,'bank_transactions.bulk_categorized','bank_transaction_batch',hash('sha256',implode('|',$ids)),[
            'count'=>count($ids),'accountId'=>$accountId,'taxCode'=>$taxCode,'transactionIdsSha256'=>hash('sha256',implode('|',$ids)),
            'initiatedVia'=>$source==='ask_tegh'?'Ask Tegh':($source==='native_ai'?'Tegh Native Intelligence':'Manual UI'),
        ]);
        db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    if(function_exists('tegh_ai_observe_bank_categorization'))tegh_ai_observe_bank_categorization($user,$company,$ids,$accountId,$source);
    return ['updated'=>count($ids),'accountId'=>$accountId,'taxCode'=>$taxCode];
}

function handle_bank_transaction_categorize(): never
{
    require_method('POST');require_csrf();$user=require_user();$company=require_company($user);require_company_role($company,'owner','bookkeeper');
    $input=request_json();$ids=requested_bank_transaction_ids($input);$accountId=clean_text($input['accountId']??'','Bookkeeping account',64);
    $taxRequested=in_array((string)($input['taxCode']??''),['GST_HST','HST13'],true);
    json_response(bank_transaction_categorize_service($user,$company,$ids,$accountId,$taxRequested));
}

/** Shared review-state service used by the manual Bank Review UI and Ask Tegh. */
function bank_transaction_review_state_service(array $user, array $company, array $ids, string $action = 'exclude', string $source = 'manual'): array
{
    require_company_permission($company, 'banking.match');
    if (!in_array($action, ['exclude','restore'], true)) fail('Bank review action is invalid.');
    if (count($ids) < 1 || count($ids) > 100) fail('Select between 1 and 100 transactions.');
    $companyId = (string)$company['id'];
    $fromStatus = $action === 'restore' ? 'excluded' : 'pending';
    $toStatus = $action === 'restore' ? 'pending' : 'excluded';
    $event = $action === 'restore' ? 'bank_transaction.restored' : 'bank_transaction.excluded';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    db()->beginTransaction();
    try {
        $stmt = db()->prepare("SELECT id, transaction_date, description, amount_cents, status FROM bank_transactions WHERE company_id = ? AND id IN ($placeholders) FOR UPDATE");
        $stmt->execute(array_merge([$companyId], $ids));
        $rows = $stmt->fetchAll();
        if (count($rows) !== count($ids)) fail('One or more selected transactions are unavailable.', 409, 'transaction_unavailable');
        foreach ($rows as $row) {
            if ((string)$row['status'] !== $fromStatus) {
                $message = $action === 'restore'
                    ? 'Only transactions previously removed from review can be restored.'
                    : 'Only unposted pending transactions can be removed. Posted entries must retain their audit trail.';
                fail($message, 409, 'transaction_status_conflict');
            }
        }
        $update = db()->prepare("UPDATE bank_transactions SET status = ? WHERE company_id = ? AND status = ? AND id IN ($placeholders)");
        $update->execute(array_merge([$toStatus, $companyId, $fromStatus], $ids));
        if ($update->rowCount() !== count($ids)) throw new RuntimeException('A transaction changed while its review status was being updated.');
        foreach ($rows as $row) {
            if (schema_table_exists('vouchers')) {
                if ($action === 'restore') {
                    db()->prepare("UPDATE vouchers SET status='draft',posted_by=NULL,posted_at=NULL WHERE company_id=? AND source_type='bank_transaction' AND source_id=? AND journal_entry_id IS NULL")
                        ->execute([$companyId,(string)$row['id']]);
                } elseif (function_exists('voucher_mark_void')) voucher_mark_void($user,$companyId,'bank_transaction',(string)$row['id']);
            }
            audit_event($user, $companyId, $event, 'bank_transaction', (string)$row['id'], [
                'transactionDate'=>(string)$row['transaction_date'],'description'=>(string)$row['description'],'amountCents'=>(int)$row['amount_cents'],
                'initiatedVia'=>$source === 'ask_tegh' ? 'Ask Tegh' : ($source === 'native_ai' ? 'Tegh Native Intelligence' : 'Manual UI'),
            ]);
        }
        db()->commit();
    } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
    return ['updated'=>count($ids),'status'=>$toStatus];
}

function handle_bank_transaction_review_state(string $action): never
{
    require_method('POST'); require_csrf(); $user=require_user(); $company=require_company($user); require_company_role($company,'owner','bookkeeper');
    $ids=requested_bank_transaction_ids(request_json());
    json_response(bank_transaction_review_state_service($user,$company,$ids,$action));
}

/** Shared protected delete service used by the manual Bank Review UI and Ask Tegh. */
function bank_transaction_delete_service(array $user, array $company, array $ids, string $source = 'manual'): array
{
    require_company_permission($company, 'banking.match');
    if (count($ids) < 1 || count($ids) > 100) fail('Select between 1 and 100 transactions.');
    $companyId=(string)$company['id']; $placeholders=implode(',',array_fill(0,count($ids),'?'));
    db()->beginTransaction();
    try {
        $stmt=db()->prepare("SELECT id, transaction_date, description, amount_cents, status, journal_entry_id, import_batch_id FROM bank_transactions WHERE company_id = ? AND id IN ($placeholders) FOR UPDATE");
        $stmt->execute(array_merge([$companyId],$ids)); $rows=$stmt->fetchAll();
        if(count($rows)!==count($ids)) fail('One or more selected transactions are unavailable.',409,'transaction_unavailable');
        foreach($rows as $row) if(!in_array((string)$row['status'],['pending','excluded'],true) || $row['journal_entry_id']!==null)
            fail('Only unposted pending or excluded transactions can be permanently deleted. Posted entries must retain their audit trail.',409,'transaction_delete_blocked');
        $delete=db()->prepare("DELETE FROM bank_transactions WHERE company_id = ? AND journal_entry_id IS NULL AND status IN ('pending','excluded') AND id IN ($placeholders)");
        $delete->execute(array_merge([$companyId],$ids));
        if($delete->rowCount()!==count($ids)) throw new RuntimeException('A transaction changed while the selected transactions were being deleted.');
        foreach($rows as $row){
            if(function_exists('voucher_mark_void')) voucher_mark_void($user,$companyId,'bank_transaction',(string)$row['id']);
            audit_event($user,$companyId,'bank_transaction.deleted','bank_transaction',(string)$row['id'],[
                'transactionDate'=>(string)$row['transaction_date'],'description'=>(string)$row['description'],'amountCents'=>(int)$row['amount_cents'],
                'previousStatus'=>(string)$row['status'],'importBatchId'=>$row['import_batch_id']!==null?(string)$row['import_batch_id']:null,'postedEntryDeleted'=>false,
                'initiatedVia'=>$source === 'ask_tegh' ? 'Ask Tegh' : ($source === 'native_ai' ? 'Tegh Native Intelligence' : 'Manual UI'),
            ]);
        }
        db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    return ['deleted'=>count($ids),'postedEntriesDeleted'=>0];
}

/**
 * R129: correct an imported bank line before it is used. Only a line that is
 * unposted (pending, no journal), not matched and not in a completed
 * reconciliation can change; dates in a locked period are refused. The
 * original import fingerprint is kept so the same statement row is still
 * recognised as a duplicate if it is imported again.
 */
function handle_bank_transaction_edit(): never
{
    require_method('POST'); require_csrf(); $user=require_user(); $company=require_company($user);
    require_company_role($company,'owner','bookkeeper'); require_company_permission($company,'banking.match');
    $companyId=(string)$company['id']; $input=request_json();
    $id=clean_text($input['transactionId'] ?? '','Bank transaction',64);
    $date=safe_date($input['date'] ?? '','Transaction date'); assert_not_future_date($date,'Transaction date');
    $description=trim((string)($input['description'] ?? ''));
    if($description==='' || mb_strlen($description)>2000) fail('Enter a description of up to 2,000 characters.',422,'bank_transaction_description_invalid');
    $reference=trim((string)($input['reference'] ?? '')); if(mb_strlen($reference)>120) fail('Keep the reference to 120 characters.',422,'bank_transaction_reference_invalid');
    $remarks=trim((string)($input['remarks'] ?? '')); if(mb_strlen($remarks)>500) fail('Keep the remarks to 500 characters.',422,'bank_transaction_remarks_invalid');
    $amountRaw=$input['amountCents'] ?? null;
    if(!is_int($amountRaw) && !(is_string($amountRaw) && preg_match('/^-?\d+$/',$amountRaw))) fail('Enter the amount.',422,'bank_transaction_amount_invalid');
    $amount=(int)$amountRaw; if($amount===0 || abs($amount)>99999999999) fail('Enter a non-zero amount.',422,'bank_transaction_amount_invalid');
    if(period_lock_for_date($companyId,$date)!==null) fail('That date is in a locked period.',409,'period_locked');
    db()->beginTransaction();
    try{
        $q=db()->prepare('SELECT * FROM bank_transactions WHERE company_id=? AND id=? FOR UPDATE'); $q->execute([$companyId,$id]); $row=$q->fetch();
        if(!$row) fail('Bank transaction not found.',404,'bank_transaction_not_found');
        if((string)$row['status']!=='pending' || $row['journal_entry_id']!==null) fail('Only unposted transactions can be edited. Posted lines keep their audit trail.',409,'bank_transaction_edit_blocked');
        $m=db()->prepare("SELECT COUNT(*) FROM bank_match_bank_items bi JOIN bank_match_groups g ON g.id=bi.match_group_id WHERE bi.bank_transaction_id=? AND g.status='matched'"); $m->execute([$id]);
        if((int)$m->fetchColumn()>0) fail('This transaction is matched. Unmatch it before editing.',409,'bank_transaction_edit_matched');
        if(schema_table_exists('reconciliation_items')){$r=db()->prepare("SELECT COUNT(*) FROM reconciliation_items ri JOIN reconciliations rc ON rc.id=ri.reconciliation_id WHERE ri.bank_transaction_id=? AND rc.company_id=? AND rc.status='complete'");$r->execute([$id,$companyId]);if((int)$r->fetchColumn()>0) fail('This transaction is part of a completed reconciliation.',409,'bank_transaction_edit_reconciled');}
        if(period_lock_for_date($companyId,(string)$row['transaction_date'])!==null) fail('The original date is in a locked period.',409,'period_locked');
        $full=null;if(schema_table_exists('bank_transaction_source_text')){$t=db()->prepare('SELECT full_description FROM bank_transaction_source_text WHERE company_id=? AND transaction_id=?');$t->execute([$companyId,$id]);$full=$t->fetchColumn();$full=$full===false?null:(string)$full;}
        $before=['date'=>(string)$row['transaction_date'],'description'=>$full??(string)$row['description'],'reference'=>(string)($row['reference']??''),'remarks'=>(string)($row['remarks']??''),'amountCents'=>(int)($row['foreign_amount_cents']??$row['amount_cents'])];
        $after=['date'=>$date,'description'=>$description,'reference'=>$reference,'remarks'=>$remarks,'amountCents'=>$amount];
        if($before===$after){db()->commit();json_response(['transaction'=>['id'=>$id]+$after,'changed'=>false]);}
        // A changed amount or description invalidates the automatic suggestion.
        $resetSuggestion=$before['amountCents']!==$amount || $before['description']!==$description;
        // Amount is entered in the account's currency; the base amount uses the imported rate.
        $rate=(int)($row['exchange_rate_micros']??0);$base=$rate>0&&function_exists('convert_to_base_cents')?convert_to_base_cents($amount,$rate):$amount;
        db()->prepare('UPDATE bank_transactions SET transaction_date=?,description=?,reference=?,remarks=?,amount_cents=?,foreign_amount_cents=?'.($resetSuggestion?",suggested_account_id=NULL,confidence=0,ai_explanation=NULL,normalized_merchant=NULL":'').' WHERE company_id=? AND id=? AND status=\'pending\' AND journal_entry_id IS NULL')
            ->execute([$date,mb_substr($description,0,500),$reference!==''?$reference:null,$remarks,$base,$amount,$companyId,$id]);
        if($full!==null&&$before['description']!==$description)db()->prepare('UPDATE bank_transaction_source_text SET full_description=? WHERE company_id=? AND transaction_id=?')->execute([$description,$companyId,$id]);
        $changed=array_keys(array_filter($after,static fn($value,$key)=>$before[$key]!==$value,ARRAY_FILTER_USE_BOTH));
        audit_event($user,$companyId,'bank_transaction.edited','bank_transaction',$id,['before'=>$before,'after'=>$after,'changedFields'=>$changed,'bankAccountId'=>(string)$row['bank_account_id'],'initiatedVia'=>'Manual UI']);
        db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    json_response(['transaction'=>['id'=>$id]+$after,'changed'=>true,'changedFields'=>$changed]);
}

function handle_bank_transaction_delete(): never
{
    require_method('POST'); require_csrf(); $user=require_user(); $company=require_company($user); require_company_role($company,'owner','bookkeeper');
    $ids=requested_bank_transaction_ids(request_json());
    json_response(bank_transaction_delete_service($user,$company,$ids));
}

function handle_reconciliations(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    $bankAccountId = clean_text($input['bankAccountId'] ?? '', 'Bank account', 64);
    $periodEnd = safe_date($input['periodEnd'] ?? '', 'Period end');
    $periodStart = trim((string)($input['periodStart'] ?? ''));
    $periodStart = $periodStart === '' ? substr($periodEnd,0,8).'01' : safe_date($periodStart,'Period start');
    if ($periodStart > $periodEnd) fail('Period start cannot be after period end.');
    assert_not_future_date($periodEnd, 'Period end');
    $status = (string)($input['status'] ?? 'complete');
    if (!in_array($status,['draft','complete'],true)) fail('Reconciliation status is invalid.');
    $notes = optional_text($input['notes'] ?? null,1000) ?? '';
    $stmt = db()->prepare("SELECT id, name, ledger_account_id, currency FROM bank_accounts WHERE id = ? AND company_id = ? AND account_type IN ('bank','credit_card') AND active = 1");
    $stmt->execute([$bankAccountId, $companyId]);
    $bank = $stmt->fetch();
    if (!$bank) fail('Choose a valid bank account.');
    if ((string)$bank['currency'] !== (string)$company['currency']) {
        fail('Complete a reviewed period-end currency adjustment before reconciling this account.', 409, 'foreign_bank_revaluation_required');
    }
    // R122: the bank-side balance is calculated from the opening balance and
    // every imported bank transaction; it is never typed in or read from a
    // statement import. Reconciling items (unposted imports, timing and
    // book-only entries) are reported; completion needs the unexplained
    // difference to be zero.
    require_once __DIR__ . '/reconciliation_position_r122.php';
    $position = tegh_recon_position_r122($companyId, $bank, $periodStart, $periodEnd);
    $statementBalance = (int)$position['bank']['closingCents'];
    $bookBalance = (int)$position['book']['closingCents'];
    $difference = (int)$position['unexplainedCents'];
    if ($status === 'complete' && $difference !== 0) fail('The bank and book balances differ by ' . number_format(abs($difference) / 100, 2) . ' ' . (string)$company['currency'] . ' that the reconciling items do not explain. Save it as a draft and review the transactions.', 409, 'reconciliation_unexplained_difference');
    $stmt = db()->prepare("SELECT COUNT(*) FROM bank_transactions bt JOIN reconciliation_items ri ON ri.bank_transaction_id=bt.id JOIN reconciliations prior ON prior.id=ri.reconciliation_id WHERE bt.bank_account_id=? AND bt.company_id=? AND bt.transaction_date BETWEEN ? AND ? AND prior.company_id=bt.company_id AND prior.status='complete'");
    $stmt->execute([$bankAccountId,$companyId,$periodStart,$periodEnd]);
    if($status==='complete' && (int)$stmt->fetchColumn()>0) fail('One or more bank-statement items in this period are already part of a completed reconciliation. Reopen the prior reconciliation instead of clearing them twice.',409,'bank_item_already_reconciled');
    db()->beginTransaction();
    try {
        $existingStmt=db()->prepare('SELECT id,status FROM reconciliations WHERE bank_account_id=? AND period_end=? FOR UPDATE');
        $existingStmt->execute([$bankAccountId,$periodEnd]);$existing=$existingStmt->fetch();
        if($existing){
            if((string)$existing['status']==='complete')fail('A completed reconciliation cannot be rewritten. Reopen the reconciliation first so the change is explicit and audited.',409,'reconciliation_reopen_required');
            $id=(string)$existing['id'];
            db()->prepare("UPDATE reconciliations SET period_start=?,statement_balance_cents=?,book_balance_cents=?,difference_cents=?,status=?,notes=?,prepared_by=?,completed_at=CASE WHEN ?='complete' THEN UTC_TIMESTAMP() ELSE NULL END,reopened_by=NULL,reopened_at=NULL,reopen_reason=NULL WHERE id=? AND company_id=?")
                ->execute([$periodStart,$statementBalance,$bookBalance,$difference,$status,$notes,$user['id'],$status,$id,$companyId]);
            db()->prepare('DELETE FROM reconciliation_items WHERE reconciliation_id=?')->execute([$id]);
        }else{
            $id=new_id('recon');
            db()->prepare("INSERT INTO reconciliations (id,company_id,bank_account_id,period_start,period_end,statement_balance_cents,book_balance_cents,difference_cents,status,notes,prepared_by,completed_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,CASE WHEN ?='complete' THEN UTC_TIMESTAMP() ELSE NULL END,?)")
                ->execute([$id,$companyId,$bankAccountId,$periodStart,$periodEnd,$statementBalance,$bookBalance,$difference,$status,$notes,$user['id'],$status,$user['id']]);
        }
        if($status==='complete'){
            db()->prepare('UPDATE bank_accounts SET statement_balance_cents = ?, last_reconciled_date = GREATEST(COALESCE(last_reconciled_date,?),?) WHERE id = ? AND company_id = ?')
                ->execute([$statementBalance,$periodEnd,$periodEnd,$bankAccountId,$companyId]);
            $stmt = db()->prepare("SELECT DISTINCT bt.id FROM bank_transactions bt JOIN journal_entries je ON je.company_id=bt.company_id AND je.status='posted' AND je.entry_date<=? AND (je.id=bt.journal_entry_id OR je.id IN (SELECT mi.journal_entry_id FROM bank_match_bank_items bi JOIN bank_match_groups g ON g.id=bi.match_group_id AND g.status='matched' JOIN bank_match_book_items mi ON mi.match_group_id=g.id WHERE bi.bank_transaction_id=bt.id))
                WHERE bt.bank_account_id=? AND bt.company_id=? AND bt.transaction_date BETWEEN ? AND ? AND bt.status='posted'
                AND NOT EXISTS(SELECT 1 FROM reconciliation_items pri JOIN reconciliations pr ON pr.id=pri.reconciliation_id AND pr.status='complete' AND pr.id<>? WHERE pri.bank_transaction_id=bt.id)");
            $stmt->execute([$periodEnd,$bankAccountId,$companyId,$periodStart,$periodEnd,$id]);
            $itemStmt = db()->prepare('INSERT INTO reconciliation_items (reconciliation_id, bank_transaction_id, cleared) VALUES (?, ?, 1)');
            foreach ($stmt->fetchAll() as $row) $itemStmt->execute([$id, $row['id']]);
            db()->prepare('DELETE FROM reconciliation_match_groups WHERE reconciliation_id=?')->execute([$id]);
            $link=db()->prepare("INSERT IGNORE INTO reconciliation_match_groups (reconciliation_id,match_group_id)
                SELECT DISTINCT ?,mg.id FROM bank_match_groups mg JOIN bank_match_bank_items mbi ON mbi.match_group_id=mg.id JOIN bank_transactions bt ON bt.id=mbi.bank_transaction_id
                WHERE mg.company_id=? AND mg.bank_account_id=? AND mg.status='matched' AND bt.transaction_date BETWEEN ? AND ?");
            $link->execute([$id,$companyId,$bankAccountId,$periodStart,$periodEnd]);
        }
        db()->prepare("INSERT INTO reconciliation_events (id,reconciliation_id,company_id,action,note,actor_user_id) VALUES (?,?,?,?,?,?)")
            ->execute([new_id('revent'),$id,$companyId,$status==='complete'?'completed':'created',$notes,$user['id']]);
        audit_event($user,$companyId,$status==='complete'?'reconciliation.completed':'reconciliation.saved_draft','reconciliation',$id,['periodStart'=>$periodStart,'periodEnd'=>$periodEnd,'statementBalanceCents'=>$statementBalance,'differenceCents'=>$difference]);
        db()->commit();
    } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
    json_response(['reconciliation'=>['id'=>$id,'status'=>$status,'differenceCents'=>$difference]],$existing?200:201);
}

function handle_attachments(): never
{
    $user = require_user();
    $company = require_company($user);
    $companyId = (string)$company['id'];
    if (request_method() === 'GET') {
        $expenseId = clean_text($_GET['expenseId'] ?? '', 'Expense', 70);
        $stmt = db()->prepare("SELECT receipt_path FROM expenses WHERE id = ? AND company_id = ? AND status = 'posted'");
        $stmt->execute([$expenseId, $companyId]);
        $path = $stmt->fetchColumn();
        if ($path === false || $path === null) fail('A receipt is not attached to this expense.', 404, 'receipt_not_found');
        stream_private_file((string)$path, 'receipt-' . $expenseId);
    }
    require_method('POST', 'DELETE');
    require_csrf();
    require_company_role($company, 'owner', 'bookkeeper');
    if (request_method() === 'POST') {
        $upload = save_private_upload($companyId, 'receipts', ['pdf','jpg','jpeg','png','webp'], [
            'application/pdf','image/jpeg','image/png','image/webp',
        ]);
        json_response(['key' => $upload['relativePath']], 201);
    }
    $input = request_json();
    $key = clean_text($input['key'] ?? '', 'Receipt reference', 500);
    if (!valid_private_storage_reference($companyId, $key, 'receipts')) fail('Receipt reference is invalid.');
    $stmt = db()->prepare('SELECT COUNT(*) FROM expenses WHERE company_id = ? AND receipt_path = ?');
    $stmt->execute([$companyId, $key]);
    if ((int)$stmt->fetchColumn() > 0) fail('A linked receipt cannot be deleted.');
    delete_private_file($key);
    json_response(['deleted' => true]);
}
