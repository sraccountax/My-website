<?php
declare(strict_types=1);

/** @return array<int,string> */
function supported_currency_codes(): array
{
    return ['CAD', 'USD', 'EUR', 'GBP', 'AUD', 'NZD', 'INR'];
}

function safe_currency_code(mixed $value, string $label = 'Currency'): string
{
    $code = strtoupper(trim((string)$value));
    if (!in_array($code, supported_currency_codes(), true)) {
        fail($label . ' is not supported.');
    }
    return $code;
}

function safe_exchange_rate_micros(mixed $value, string $currency, string $baseCurrency): int
{
    if ($currency === $baseCurrency) return 1_000_000;
    if (!is_int($value) && !(is_numeric($value) && (string)(int)$value === trim((string)$value))) {
        fail('Exchange rate must be supplied as an integer number of millionths.');
    }
    $rate = (int)$value;
    if ($rate < 1 || $rate > 1_000_000_000_000) {
        fail('Exchange rate is outside the supported range.');
    }
    return $rate;
}

function convert_to_base_cents(int $foreignCents, int $rateMicros): int
{
    $converted = (int)round(($foreignCents * $rateMicros) / 1_000_000);
    if ($foreignCents !== 0 && $converted === 0) {
        fail('The exchange rate converts this amount to zero in the base currency.');
    }
    if (abs($converted) > 100_000_000_000) {
        fail('The converted amount is outside the supported range.');
    }
    return $converted;
}

function company_currency(string $companyId, string $currency): ?array
{
    $stmt = db()->prepare('SELECT currency_code, rate_to_base_micros, rate_date, active
        FROM company_currencies WHERE company_id = ? AND currency_code = ? AND active = 1');
    $stmt->execute([$companyId, $currency]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function period_lock_for_date(string $companyId, string $date): ?array
{
    if (schema_table_exists('period_locks')) {
        $stmt = db()->prepare('SELECT id, period_start, period_end, reason FROM period_locks
            WHERE company_id = ? AND locked = 1 AND ? BETWEEN period_start AND period_end
            ORDER BY period_end DESC LIMIT 1');
        $stmt->execute([$companyId, $date]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    $stmt = db()->prepare('SELECT closed_through_date FROM accounting_controls WHERE company_id = ?');
    $stmt->execute([$companyId]);
    $closedThrough = $stmt->fetchColumn();
    if ($closedThrough !== false && $closedThrough !== null && $date <= (string)$closedThrough) {
        return ['id' => 'legacy_close', 'period_start' => '1900-01-01', 'period_end' => (string)$closedThrough, 'reason' => 'Legacy close-through control'];
    }
    return null;
}

function company_bank_balance_through(string $companyId, string $date): int
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0)
        FROM journal_lines jl
        JOIN journal_entries je ON je.id=jl.journal_entry_id
        JOIN bank_accounts ba ON ba.ledger_account_id=jl.account_id AND ba.company_id=je.company_id AND ba.active=1
        WHERE je.company_id=? AND je.status='posted' AND je.entry_date<=?");
    $stmt->execute([$companyId,$date]);
    return (int)$stmt->fetchColumn();
}

/** @param array<int,array{accountId:string,debitCents:int,creditCents:int,memo?:string}> $lines */
function bank_impact_for_lines(string $companyId, array $lines): int
{
    if ($lines === []) return 0;
    $stmt = db()->prepare('SELECT ledger_account_id FROM bank_accounts WHERE company_id = ? AND active = 1');
    $stmt->execute([$companyId]);
    $bankAccountIds = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    $impact = 0;
    foreach ($lines as $line) {
        $accountId = (string)($line['accountId'] ?? '');
        if (!isset($bankAccountIds[$accountId])) continue;
        $impact += (int)($line['debitCents'] ?? 0) - (int)($line['creditCents'] ?? 0);
    }
    return $impact;
}

/** @param array<int,array{accountId:string,debitCents:int,creditCents:int,memo?:string}> $lines */
function assert_period_open(string $companyId, string $date, array $lines = []): void
{
    $lock = period_lock_for_date($companyId, $date);
    if ($lock === null) return;
    // A locked period is a hard accounting control. No role, header or UI
    // confirmation can bypass it; an owner/admin must explicitly reopen the
    // period first. This keeps every posting module subject to the same rule.
    fail(
        'This date is in a locked period. Reopen the period in Settings → Period Locking before posting.',
        409,
        'period_locked',
        false
    );
}

function assert_vendor_invoice_on_or_after_books_start(array $company, string $billDate): void
{
    $booksStart=trim((string)($company['books_start_date']??''));
    if($booksStart!==''&&$billDate<$booksStart){
        fail(
            'Vendor invoices dated before '.$booksStart.' must be entered through Data Import → Opening Vendor Bills.',
            409,
            'bill_before_books_start',
            false
        );
    }
}

function company_has_accounting_activity(string $companyId): bool
{
    $stmt = db()->prepare("SELECT
        (SELECT COUNT(*) FROM journal_entries WHERE company_id = ?)
        + (SELECT COUNT(*) FROM invoices WHERE company_id = ?)
        + (SELECT COUNT(*) FROM import_batches WHERE company_id = ?)
        + (SELECT COUNT(*) FROM vendors WHERE company_id = ?)
        + (SELECT COUNT(*) FROM bills WHERE company_id = ?)
        + (SELECT COUNT(*) FROM payroll_settings WHERE company_id = ?)
        + GREATEST((SELECT COUNT(*) FROM bank_accounts WHERE company_id = ?) - 2, 0)
        + GREATEST((SELECT COUNT(*) FROM company_currencies WHERE company_id = ? AND active = 1) - 1, 0)");
    $stmt->execute([$companyId, $companyId, $companyId, $companyId, $companyId, $companyId, $companyId, $companyId]);
    return (int)$stmt->fetchColumn() > 0;
}

function handle_currencies(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_permission($company, 'company.settings');
    $companyId = (string)$company['id'];
    $baseCurrency = (string)$company['currency'];
    $input = request_json();
    $currency = safe_currency_code($input['currency'] ?? '');
    $rate = safe_exchange_rate_micros($input['rateToBaseMicros'] ?? null, $currency, $baseCurrency);
    $rateDate = safe_date($input['rateDate'] ?? canadian_today(), 'Rate date');
    assert_not_future_date($rateDate, 'Rate date');
    db()->prepare("INSERT INTO company_currencies (company_id, currency_code, rate_to_base_micros, rate_date, active)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE rate_to_base_micros = VALUES(rate_to_base_micros), rate_date = VALUES(rate_date), active = 1")
        ->execute([$companyId, $currency, $rate, $rateDate]);
    audit_event($user, $companyId, 'currency.rate_updated', 'company_currency', $currency, [
        'rateToBaseMicros' => $rate, 'rateDate' => $rateDate, 'baseCurrency' => $baseCurrency,
    ]);
    json_response(['currency' => ['code' => $currency, 'rateToBaseMicros' => $rate, 'rateDate' => $rateDate]], 201);
}

/** @return array<string,mixed> */
function vendor_record_values(array $company, array $input): array
{
    $companyId=(string)$company['id'];
    $name=clean_text($input['name']??'','Vendor name',200);
    $contactName=optional_text($input['contactName']??null,160);
    $emailRaw=trim((string)($input['email']??''));$email=$emailRaw!==''?safe_email($emailRaw):null;
    $phone=optional_text($input['phone']??null,60);
    $address=tegh_structured_address($input,'address');
    $provinceRaw=strtoupper(trim((string)($input['province']??'')));
    $province=$provinceRaw!==''?clean_text($provinceRaw,'Vendor province or territory',2):null;
    if($province!==null&&!in_array($province,tegh_province_codes(),true))fail('Vendor province or territory is invalid.');
    $termsDays=tegh_terms_days($input['paymentTerms']??($input['defaultTermsDays']??30),$input['customTermsDays']??null,30);
    $currency=safe_currency_code($input['currency']??$company['currency']);if(!company_currency($companyId,$currency))fail('Add that currency to the company before assigning it to a vendor.');
    $accountId=optional_text($input['defaultExpenseAccountId']??null,64);
    if($accountId!==null){$account=company_account($companyId,$accountId);if(!$account||!in_array((string)$account['account_type'],['expense','asset'],true)||(bool)$account['is_control'])fail('Choose a valid non-control expense or asset account.');}
    $notes=optional_text($input['notes']??null,2000);
    $status=tegh_party_status($input['status']??'active','Vendor status');if($status==='on_hold')fail('Vendor status supports Active or Inactive.');
    $openingBalanceCents=tegh_signed_opening_balance_cents($input['openingBalanceCents']??0);
    $openingBalanceDate=trim((string)($input['openingBalanceDate']??''));
    if($openingBalanceCents!==0){if($openingBalanceDate==='')fail('Enter an Opening Balance Date for a non-zero vendor opening balance.');$openingBalanceDate=safe_date($openingBalanceDate,'Opening balance date');}else$openingBalanceDate=null;
    return ['name'=>$name,'contactName'=>$contactName,'email'=>$email,'phone'=>$phone,'address'=>$address['displayAddress'],'addressLine1'=>$address['addressLine1'],'addressLine2'=>$address['addressLine2'],'city'=>$address['city'],'province'=>$province,'postalCode'=>$address['postalCode'],'country'=>$address['country'],'termsDays'=>$termsDays,'currency'=>$currency,'accountId'=>$accountId,'notes'=>$notes,'status'=>$status,'openingBalanceCents'=>$openingBalanceCents,'openingBalanceDate'=>$openingBalanceDate];
}

/** Shared vendor-create service used by the manual UI, imports and Ask Tegh. */
function create_vendor_record(array $user, array $company, array $input, string $source = 'manual'): array
{
    require_company_permission($company,'vendors.write');$companyId=(string)$company['id'];$v=vendor_record_values($company,$input);
    $dupe=db()->prepare('SELECT id FROM vendors WHERE company_id=? AND name=? LIMIT 1');$dupe->execute([$companyId,$v['name']]);if($dupe->fetchColumn()!==false)fail('A vendor with that name already exists in this company.',409,'duplicate_vendor');
    $id=new_id('vendor');$ownsTransaction=!db()->inTransaction();if($ownsTransaction)db()->beginTransaction();
    try{
        $active=$v['status']==='inactive'?0:1;
        db()->prepare('INSERT INTO vendors (id,company_id,name,contact_name,email,phone,address,address_line1,address_line2,city,province,postal_code,country,default_terms_days,default_expense_account_id,default_currency,notes,status,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$id,$companyId,$v['name'],$v['contactName'],$v['email'],$v['phone'],$v['address'],$v['addressLine1'],$v['addressLine2'],$v['city'],$v['province'],$v['postalCode'],$v['country'],$v['termsDays'],$v['accountId'],$v['currency'],$v['notes'],$v['status'],$active]);
        $opening=null;if($v['openingBalanceCents']!==0)$opening=post_party_opening_balance($user,$company,'vendor',$id,$v['name'],$v['openingBalanceCents'],(string)$v['openingBalanceDate'],$source);
        audit_event($user,$companyId,'vendor.created','vendor',$id,['name'=>$v['name'],'currency'=>$v['currency'],'defaultTermsDays'=>$v['termsDays'],'status'=>$v['status'],'openingBalanceCents'=>$v['openingBalanceCents'],'initiatedVia'=>$source==='ask_tegh'?'Ask Tegh':($source==='import'?'Data Import':'Manual UI')]);if($ownsTransaction)db()->commit();
    }catch(Throwable $e){if($ownsTransaction&&db()->inTransaction())db()->rollBack();throw $e;}
    return ['id'=>$id,'name'=>$v['name'],'currency'=>$v['currency'],'status'=>$v['status'],'defaultTermsDays'=>$v['termsDays'],'openingBalance'=>$opening];
}

function handle_vendors(): never
{
    require_method('POST','PUT','DELETE');require_csrf();$user=require_user();$company=require_company($user);require_company_permission($company,'vendors.write');$companyId=(string)$company['id'];$input=request_json();
    if(request_method()==='DELETE'){
        $id=clean_text($input['vendorId']??'','Vendor',64);$stmt=db()->prepare('SELECT name FROM vendors WHERE id=? AND company_id=?');$stmt->execute([$id,$companyId]);$vendor=$stmt->fetch();if(!$vendor)fail('Vendor not found.',404,'vendor_not_found');
        $used=db()->prepare('SELECT (SELECT COUNT(*) FROM bills WHERE vendor_id=? AND company_id=?)+(SELECT COUNT(*) FROM party_opening_balances WHERE party_type=\'vendor\' AND party_id=? AND company_id=?)');$used->execute([$id,$companyId,$id,$companyId]);
        if((int)$used->fetchColumn()>0){db()->prepare("UPDATE vendors SET active=0,status='inactive' WHERE id=? AND company_id=?")->execute([$id,$companyId]);$result='archived';}else{db()->prepare('DELETE FROM vendors WHERE id=? AND company_id=?')->execute([$id,$companyId]);$result='deleted';}
        audit_event($user,$companyId,'vendor.'.$result,'vendor',$id,['name'=>(string)$vendor['name']]);json_response(['vendor'=>['id'=>$id,'status'=>$result]]);
    }
    if(request_method()==='POST'){json_response(['vendor'=>create_vendor_record($user,$company,$input)],201);}
    $v=vendor_record_values($company,$input);$id=clean_text($input['vendorId']??'','Vendor',64);$existingStmt=db()->prepare('SELECT * FROM vendors WHERE id=? AND company_id=? LIMIT 1');$existingStmt->execute([$id,$companyId]);$existing=$existingStmt->fetch();if(!$existing)fail('Vendor not found.',404,'vendor_not_found');
    $used=db()->prepare('SELECT COUNT(*) FROM bills WHERE vendor_id=? AND company_id=?');$used->execute([$id,$companyId]);$locked=(int)$used->fetchColumn()>0;
    if($locked&&(string)$existing['name']!==$v['name'])fail('Vendor name is locked after vendor-invoice use. Contact details, terms, notes and status can still be updated.',409,'vendor_legal_fields_locked');
    if($v['openingBalanceCents']!==0&&!party_opening_balance_row($companyId,'vendor',$id))fail('Opening balance can only be recorded when the vendor is first created or through Data Import. Use an adjusting entry for later corrections.',409,'opening_balance_edit_blocked');
    $active=$v['status']==='inactive'?0:1;
    db()->prepare('UPDATE vendors SET name=?,contact_name=?,email=?,phone=?,address=?,address_line1=?,address_line2=?,city=?,province=?,postal_code=?,country=?,default_terms_days=?,default_expense_account_id=?,default_currency=?,notes=?,status=?,active=? WHERE id=? AND company_id=?')
      ->execute([$locked?(string)$existing['name']:$v['name'],$v['contactName'],$v['email'],$v['phone'],$v['address'],$v['addressLine1'],$v['addressLine2'],$v['city'],$v['province'],$v['postalCode'],$v['country'],$v['termsDays'],$v['accountId'],$v['currency'],$v['notes'],$v['status'],$active,$id,$companyId]);
    audit_event($user,$companyId,'vendor.updated','vendor',$id,['name'=>$locked?(string)$existing['name']:$v['name'],'currency'=>$v['currency'],'defaultTermsDays'=>$v['termsDays'],'status'=>$v['status']]);json_response(['vendor'=>['id'=>$id,'status'=>$v['status']]],200);
}

function bill_posting_lines(string $companyId, string $categoryAccountId, int $subtotal, int $gstHst, int $pst, int $total, bool $pstRecoverable = false, int $fxRoundingCents = 0): array
{
    // Provincial sales tax is normally part of the acquired cost unless the
    // company has explicitly configured it as recoverable. GST/HST is posted
    // to the existing recoverable control account.
    $categoryDebit = $subtotal + ($pstRecoverable ? 0 : $pst);
    $categoryCarriesRounding=$pst>0&&!$pstRecoverable;
    $lines = [['accountId' => $categoryAccountId, 'debitCents' => $categoryDebit, 'creditCents' => 0, 'memo'=>'Vendor invoice net'.($pstRecoverable?'':' + non-recoverable PST'),'fxRoundingCents'=>$categoryCarriesRounding?$fxRoundingCents:0]];
    if ($gstHst > 0) $lines[] = ['accountId' => account_by_code($companyId, '1100'), 'debitCents' => $gstHst, 'creditCents' => 0, 'memo'=>'GST/HST recoverable','fxRoundingCents'=>$pst===0?$fxRoundingCents:0];
    if ($pst > 0 && $pstRecoverable) $lines[] = ['accountId' => account_by_code($companyId, '1110'), 'debitCents' => $pst, 'creditCents' => 0, 'memo'=>'PST recoverable','fxRoundingCents'=>$fxRoundingCents];
    $lines[] = ['accountId' => account_by_code($companyId, '2050'), 'debitCents' => 0, 'creditCents' => $total, 'memo'=>'Accounts payable'];
    return $lines;
}

function bill_fx_rounding_cents(int $foreignSubtotal,int $foreignGst,int $foreignPst,int $rateMicros,int $convertedTotal): int
{
    $independent=convert_to_base_cents($foreignSubtotal,$rateMicros)
        +($foreignGst!==0?convert_to_base_cents($foreignGst,$rateMicros):0)
        +($foreignPst!==0?convert_to_base_cents($foreignPst,$rateMicros):0);
    return $convertedTotal-$independent;
}

function bill_approval_payload_hash(array $bill): string
{
    $fields=[];
    foreach(['id','company_id','vendor_id','number','bill_date','due_date','category_account_id','subtotal_cents','gst_hst_cents','pst_cents','tax_cents','total_cents','currency','exchange_rate_micros','foreign_total_cents','memo'] as $field)$fields[$field]=$bill[$field]??null;
    return hash('sha256',json_encode($fields,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function bill_submit_for_approval(array $user,string $companyId,array $bill): string
{
    $amount=(int)$bill['total_cents'];
    if($amount<=company_materiality_threshold_cents($companyId))throw new InvalidArgumentException('A bill below materiality does not require dual-control approval.');
    $id=new_id('bapproval');
    db()->prepare("INSERT INTO journal_approvals (id,company_id,journal_entry_id,source_type,source_id,amount_cents,status,payload_hash,prepared_by,submitted_at) VALUES (?,?,NULL,'bill',?,?,'submitted',?,?,UTC_TIMESTAMP())")
        ->execute([$id,$companyId,$bill['id'],$amount,bill_approval_payload_hash($bill),$user['id']]);
    db()->prepare("UPDATE bills SET status='submitted_for_approval' WHERE id=? AND company_id=? AND status='draft'")->execute([$bill['id'],$companyId]);
    return $id;
}

function bill_posting_description(string $vendorName, string $number, string $billDate, string $categoryName): string
{
    return 'Vendor ' . $vendorName . ' · Invoice ' . $number . ' · ' . $billDate . ' · ' . $categoryName;
}

/** @return array<string,mixed> */
function bill_input_values(array $company, array $input): array
{
    $companyId=(string)$company['id'];
    $vendorId=clean_text($input['vendorId']??'', 'Vendor',64);
    $stmt=db()->prepare('SELECT id,name,default_expense_account_id,default_currency,default_terms_days FROM vendors WHERE id=? AND company_id=? AND active=1');
    $stmt->execute([$vendorId,$companyId]);$vendor=$stmt->fetch();if(!$vendor)fail('Choose an active vendor.');
    $productServiceId=trim((string)($input['productServiceId']??''));$productService=null;
    if($productServiceId!==''){
        $productServiceId=clean_text($productServiceId,'Product or service',64);
        $itemStmt=db()->prepare('SELECT id,kind,name,description FROM products_services WHERE id=? AND company_id=? AND active=1');
        $itemStmt->execute([$productServiceId,$companyId]);$productService=$itemStmt->fetch();
        if(!$productService)fail('Choose an active product or service from this company.');
    }else $productServiceId=null;
    $quantityRaw=$input['quantity']??1;
    if(!is_numeric($quantityRaw)) fail('Quantity must be a number.');
    $quantity=(float)$quantityRaw;
    if($quantity<=0||$quantity>1000000) fail('Quantity must be greater than zero and no more than 1,000,000.');
    $quantityMilli=(int)round($quantity*1000);
    $number=clean_text($input['number']??'', 'Vendor invoice number',60);
    $billDate=safe_date($input['billDate']??'', 'Vendor invoice date');assert_not_future_date($billDate,'Vendor invoice date');assert_vendor_invoice_on_or_after_books_start($company,$billDate);
    $termsDays=filter_var($input['paymentTermsDays']??$vendor['default_terms_days'],FILTER_VALIDATE_INT);
    if($termsDays===false||$termsDays<0||$termsDays>3650)fail('Payment terms must be between 0 and 3,650 days.');
    $dueDate=trim((string)($input['dueDate']??''));
    $dueDate=$dueDate===''?(new DateTimeImmutable($billDate))->modify('+'.$termsDays.' days')->format('Y-m-d'):safe_date($dueDate,'Due date');
    if($dueDate<$billDate)fail('Due date cannot be before the vendor invoice date.');
    $categoryId=clean_text($input['categoryAccountId']??$vendor['default_expense_account_id']??'','Expense or asset account',64);
    $category=company_account($companyId,$categoryId);
    if(!$category||!in_array((string)$category['account_type'],['expense','asset'],true)||(bool)$category['is_control'])fail('Choose a valid non-control expense or asset account.');
    $currency=safe_currency_code($input['currency']??$vendor['default_currency']??$company['currency']);
    $currencyRow=company_currency($companyId,$currency);if(!$currencyRow)fail('Add that currency to the company before using it on a vendor invoice.');
    $rate=safe_exchange_rate_micros($input['exchangeRateMicros']??$currencyRow['rate_to_base_micros'],$currency,(string)$company['currency']);

    $legacyTaxable=!empty($input['taxable']);
    $mode=(string)($input['taxEntryMode']??($legacyTaxable?'exclusive':'none'));
    $gstEnabled=array_key_exists('applyGstHst',$input)?!empty($input['applyGstHst']):$legacyTaxable;
    $pstEnabled=!empty($input['applyPst']);
    if($gstEnabled&&!(bool)$company['tax_registered'])fail('GST/HST is not enabled in this company tax setup.',409,'gst_hst_not_configured');
    if($pstEnabled&&(!(bool)($company['pst_registered']??false)||company_pst_rate_mpct($company)<=0))fail('PST is not enabled in this company tax setup.',409,'pst_not_configured');
    $gstRate=$gstEnabled?(int)$company['tax_rate_bps']:0;
    $pstRate=$pstEnabled?company_pst_rate_mpct($company):0; // thousandths of a percent
    if(!$gstEnabled&&!$pstEnabled)$mode='none';
    $foreignInput=safe_cents($input['foreignAmountCents']??$input['foreignSubtotalCents']??0,'Vendor invoice amount');
    if($foreignInput<0)fail('Vendor invoice amount must be positive.');
    $tax=calculate_tax_components($foreignInput,$mode,$gstRate,$pstRate);
    $foreignSubtotal=(int)$tax['netCents'];$foreignGst=(int)$tax['gstHstCents'];$foreignPst=(int)$tax['pstCents'];$foreignTotal=(int)$tax['grossCents'];
    $subtotal=convert_to_base_cents($foreignSubtotal,$rate);
    $total=convert_to_base_cents($foreignTotal,$rate);
    // Convert the components while preserving the exact converted gross. Any
    // one-cent FX rounding remainder is assigned only to a tax that actually
    // exists on the source transaction; it must never manufacture PST/GST.
    if($foreignPst>0){
        $gst=$foreignGst>0?convert_to_base_cents($foreignGst,$rate):0;
        $pst=$total-$subtotal-$gst;
    }elseif($foreignGst>0){
        $pst=0;$gst=$total-$subtotal;
    }else{
        $gst=0;$pst=0;$subtotal=$total;
    }
    if($gst<0||$pst<0)fail('Tax conversion rounding produced an invalid component. Review the exchange rate.');
    $taxTotal=$gst+$pst;
    $fxRounding=bill_fx_rounding_cents($foreignSubtotal,$foreignGst,$foreignPst,$rate,$total);
    return compact('vendorId','vendor','productServiceId','productService','quantityMilli','number','billDate','termsDays','dueDate','categoryId','category','currency','rate','mode','gstRate','pstRate','foreignSubtotal','foreignGst','foreignPst','foreignTotal','subtotal','gst','pst','taxTotal','total','fxRounding')+['memo'=>optional_text($input['memo']??null,500)??''];
}

function create_bill_record(array $user,array $company,array $input,string $source='manual'): array
{
    require_company_permission($company,'bills.write');
    $companyId=(string)$company['id'];
$v=bill_input_values($company,$input);$issue=!empty($input['issue']);$isRecurring=!empty($input['isRecurring']);
$importReference=optional_text($input['importReference']??null,120);
if($importReference!==null){$dup=db()->prepare('SELECT 1 FROM bills WHERE company_id=? AND import_reference=? LIMIT 1');$dup->execute([$companyId,$importReference]);if($dup->fetchColumn())fail('That vendor invoice import reference has already been used.',409,'duplicate_bill_import_reference');}
if($issue)assert_period_open($companyId,$v['billDate']);$id=new_id('bill');$requiresApproval=$issue&&$v['total']>company_materiality_threshold_cents($companyId);$requestedStatus=$requiresApproval?'draft':($issue?'open':'draft');$ownsTransaction=!db()->inTransaction();if($ownsTransaction)db()->beginTransaction();
try{
    if(function_exists('voucher_register_saved'))voucher_register_saved($user,$companyId,'VI','AP','bill',$id,$v['billDate'],'Vendor invoice '.$v['number'],$v['total'],null,false);
    $entryId=null;if($issue&&!$requiresApproval&&(string)$company['accounting_basis']==='accrual')$entryId=add_journal_entry($user,$companyId,$v['billDate'],'bill',$id,bill_posting_description((string)$v['vendor']['name'],$v['number'],$v['billDate'],(string)$v['category']['name']),bill_posting_lines($companyId,$v['categoryId'],$v['subtotal'],$v['gst'],$v['pst'],$v['total'],(bool)($company['pst_recoverable']??false),(int)$v['fxRounding']));
    db()->prepare("INSERT INTO bills (id,company_id,vendor_id,product_service_id,quantity_milli,number,bill_date,due_date,status,category_account_id,payment_terms_days,subtotal_cents,gst_hst_cents,pst_cents,tax_cents,tax_entry_mode,total_cents,balance_cents,currency,exchange_rate_micros,foreign_subtotal_cents,foreign_gst_hst_cents,foreign_pst_cents,foreign_tax_cents,foreign_total_cents,foreign_balance_cents,memo,import_reference,issued_journal_entry_id,is_recurring) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$id,$companyId,$v['vendorId'],$v['productServiceId'],$v['quantityMilli'],$v['number'],$v['billDate'],$v['dueDate'],$requestedStatus,$v['categoryId'],$v['termsDays'],$v['subtotal'],$v['gst'],$v['pst'],$v['taxTotal'],$v['mode'],$v['total'],$v['total'],$v['currency'],$v['rate'],$v['foreignSubtotal'],$v['foreignGst'],$v['foreignPst'],$v['foreignGst']+$v['foreignPst'],$v['foreignTotal'],$v['foreignTotal'],$v['memo'],$importReference,$entryId,$isRecurring?1:0]);
    $approvalId=null;
    if($requiresApproval){$billRow=['id'=>$id,'company_id'=>$companyId,'vendor_id'=>$v['vendorId'],'number'=>$v['number'],'bill_date'=>$v['billDate'],'due_date'=>$v['dueDate'],'category_account_id'=>$v['categoryId'],'subtotal_cents'=>$v['subtotal'],'gst_hst_cents'=>$v['gst'],'pst_cents'=>$v['pst'],'tax_cents'=>$v['taxTotal'],'total_cents'=>$v['total'],'currency'=>$v['currency'],'exchange_rate_micros'=>$v['rate'],'foreign_total_cents'=>$v['foreignTotal'],'memo'=>$v['memo']];$approvalId=bill_submit_for_approval($user,$companyId,$billRow);}
    if($issue&&!$requiresApproval&&function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,'bill',$id,$entryId);
    audit_event($user,$companyId,$requiresApproval?'bill.submitted_for_approval':($issue?'bill.issued':'bill.draft_created'),'bill',$id,['number'=>$v['number'],'totalCents'=>$v['total'],'gstHstCents'=>$v['gst'],'pstCents'=>$v['pst'],'taxEntryMode'=>$v['mode'],'journalEntryId'=>$entryId,'approvalId'=>$approvalId,'approvalRequired'=>$requiresApproval,'materialityThresholdCents'=>company_materiality_threshold_cents($companyId),'isRecurring'=>$isRecurring,'initiatedVia'=>$source]);if($ownsTransaction)db()->commit();
}catch(Throwable $e){if($ownsTransaction&&db()->inTransaction())db()->rollBack();if($e instanceof PDOException&&(string)$e->getCode()==='23000')fail('That vendor invoice number already exists.',409,'duplicate_bill_number');throw $e;}
return ['id'=>$id,'number'=>$v['number'],'status'=>$requiresApproval?'submitted_for_approval':($issue?'open':'draft'),'totalCents'=>$v['total'],'gstHstCents'=>$v['gst'],'pstCents'=>$v['pst'],'journalEntryId'=>$entryId,'approvalId'=>$approvalId,'approvalRequired'=>$requiresApproval,'isRecurring'=>$isRecurring,'importReference'=>$importReference];
}

function bill_approval_transition(array $user,array $company,string $billId,string $action,array $input): array
{
    $companyId=(string)$company['id'];
    if(!in_array($action,['approve','post'],true))throw new InvalidArgumentException('Unsupported bill approval transition.');
    if($action==='approve')require_company_role($company,'owner');
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT b.*,v.name vendor_name,a.name category_name,ja.id approval_id,ja.status approval_status,ja.payload_hash,ja.prepared_by,ja.approved_by FROM bills b JOIN vendors v ON v.id=b.vendor_id JOIN accounts a ON a.id=b.category_account_id JOIN journal_approvals ja ON ja.company_id=b.company_id AND ja.source_type='bill' AND ja.source_id=b.id WHERE b.id=? AND b.company_id=? ORDER BY ja.submitted_at DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([$billId,$companyId]);$bill=$stmt->fetch();
        if(!$bill)fail('Vendor invoice approval not found.',404,'bill_approval_not_found');
        if(!hash_equals((string)$bill['payload_hash'],bill_approval_payload_hash($bill)))fail('The vendor invoice changed after submission. Return it to draft and submit it again.',409,'bill_approval_integrity_failed');
        if($action==='approve'){
            if((string)$bill['status']!=='submitted_for_approval'||(string)$bill['approval_status']!=='submitted')fail('This vendor invoice is no longer waiting for approval.',409,'bill_approval_unavailable');
            if(hash_equals((string)$bill['prepared_by'],(string)$user['id']))fail('The person who prepared this vendor invoice cannot approve it.',409,'bill_dual_control_required');
            $pdo->prepare("UPDATE bills SET status='approved' WHERE id=? AND company_id=? AND status='submitted_for_approval'")->execute([$billId,$companyId]);
            $pdo->prepare("UPDATE journal_approvals SET status='approved',approved_by=?,approved_at=UTC_TIMESTAMP(),review_note=? WHERE id=? AND company_id=? AND status='submitted'")
                ->execute([$user['id'],optional_text($input['reviewNote']??null,500)??'Approved after review.',$bill['approval_id'],$companyId]);
            audit_event($user,$companyId,'bill.approved','bill',$billId,['approvalId'=>(string)$bill['approval_id'],'amountCents'=>(int)$bill['total_cents'],'preparedBy'=>(string)$bill['prepared_by'],'approvedBy'=>(string)$user['id']]);
            $pdo->commit();return ['id'=>$billId,'status'=>'approved','approvalId'=>(string)$bill['approval_id']];
        }
        if((string)$bill['status']!=='approved'||(string)$bill['approval_status']!=='approved')fail('Approve this vendor invoice before posting it.',409,'bill_approval_required');
        if(!hash_equals((string)$bill['approved_by'],(string)$user['id']))fail('The approving user must complete the final posting step.',409,'bill_approver_post_required');
        assert_not_future_date((string)$bill['bill_date'],'Vendor invoice date');assert_vendor_invoice_on_or_after_books_start($company,(string)$bill['bill_date']);assert_period_open($companyId,(string)$bill['bill_date']);
        $fxRounding=bill_fx_rounding_cents((int)$bill['foreign_subtotal_cents'],(int)$bill['foreign_gst_hst_cents'],(int)$bill['foreign_pst_cents'],(int)$bill['exchange_rate_micros'],(int)$bill['total_cents']);
        $entryId=null;if((string)$company['accounting_basis']==='accrual')$entryId=add_journal_entry($user,$companyId,(string)$bill['bill_date'],'bill',$billId,bill_posting_description((string)$bill['vendor_name'],(string)$bill['number'],(string)$bill['bill_date'],(string)$bill['category_name']),bill_posting_lines($companyId,(string)$bill['category_account_id'],(int)$bill['subtotal_cents'],(int)$bill['gst_hst_cents'],(int)$bill['pst_cents'],(int)$bill['total_cents'],(bool)($company['pst_recoverable']??false),$fxRounding));
        $updated=$pdo->prepare("UPDATE bills SET status='open',issued_journal_entry_id=? WHERE id=? AND company_id=? AND status='approved'");$updated->execute([$entryId,$billId,$companyId]);
        if($updated->rowCount()!==1)fail('The vendor invoice changed during posting.',409,'bill_post_conflict');
        $pdo->prepare("UPDATE journal_approvals SET status='posted',journal_entry_id=?,posted_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND status='approved'")->execute([$entryId,$bill['approval_id'],$companyId]);
        if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,'bill',$billId,$entryId);
        audit_event($user,$companyId,'bill.approved_posted','bill',$billId,['approvalId'=>(string)$bill['approval_id'],'amountCents'=>(int)$bill['total_cents'],'journalEntryId'=>$entryId,'accountingBasis'=>(string)$company['accounting_basis']]);
        $pdo->commit();return ['id'=>$billId,'status'=>'open','approvalId'=>(string)$bill['approval_id'],'journalEntryId'=>$entryId];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function handle_bills(): never
{
    require_method('POST','PATCH','DELETE');require_csrf();$user=require_user();$company=require_company($user);require_company_role($company,'owner','bookkeeper');$companyId=(string)$company['id'];$input=request_json();
    if(request_method()==='DELETE'){
        $billId=clean_text($input['billId']??'','Vendor invoice',64);db()->beginTransaction();
        try{
            $q=db()->prepare("SELECT number,status FROM bills WHERE id=? AND company_id=? FOR UPDATE");$q->execute([$billId,$companyId]);$bill=$q->fetch();if(!$bill)fail('Vendor invoice not found.',404,'bill_not_found');
            if((string)$bill['status']!=='draft')fail('Only a draft vendor invoice can be deleted. Posted accounting history must be voided or reversed.',409,'posted_bill_delete_blocked');
            db()->prepare("DELETE FROM vouchers WHERE company_id=? AND source_type='bill' AND source_id=? AND status='draft'")->execute([$companyId,$billId]);
            db()->prepare("DELETE FROM bills WHERE id=? AND company_id=? AND status='draft'")->execute([$billId,$companyId]);
            audit_event($user,$companyId,'bill.draft_deleted','bill',$billId,['number'=>(string)$bill['number']]);db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        json_response(['deleted'=>true]);
    }
    if(request_method()==='PATCH'){
        $action=(string)($input['action']??'');$billId=clean_text($input['billId']??'','Vendor invoice',64);
        if(in_array($action,['approve','post'],true))json_response(['bill'=>bill_approval_transition($user,$company,$billId,$action,$input)]);
        if($action==='update'){
            $v=bill_input_values($company,$input);db()->beginTransaction();
            try{
                $q=db()->prepare("SELECT status FROM bills WHERE id=? AND company_id=? FOR UPDATE");$q->execute([$billId,$companyId]);$status=$q->fetchColumn();
                if($status===false)fail('Vendor invoice not found.',404,'bill_not_found');
                if((string)$status!=='draft')fail('Posted vendor invoices cannot be silently rewritten. Void/reverse the posting and enter the corrected transaction.',409,'posted_bill_edit_blocked');
                db()->prepare("UPDATE bills SET vendor_id=?,product_service_id=?,quantity_milli=?,number=?,bill_date=?,due_date=?,category_account_id=?,payment_terms_days=?,subtotal_cents=?,gst_hst_cents=?,pst_cents=?,tax_cents=?,tax_entry_mode=?,total_cents=?,balance_cents=?,currency=?,exchange_rate_micros=?,foreign_subtotal_cents=?,foreign_gst_hst_cents=?,foreign_pst_cents=?,foreign_tax_cents=?,foreign_total_cents=?,foreign_balance_cents=?,memo=? WHERE id=? AND company_id=? AND status='draft'")
                  ->execute([$v['vendorId'],$v['productServiceId'],$v['quantityMilli'],$v['number'],$v['billDate'],$v['dueDate'],$v['categoryId'],$v['termsDays'],$v['subtotal'],$v['gst'],$v['pst'],$v['taxTotal'],$v['mode'],$v['total'],$v['total'],$v['currency'],$v['rate'],$v['foreignSubtotal'],$v['foreignGst'],$v['foreignPst'],$v['foreignGst']+$v['foreignPst'],$v['foreignTotal'],$v['foreignTotal'],$v['memo'],$billId,$companyId]);
                if(function_exists('voucher_update_saved'))voucher_update_saved($companyId,'bill',$billId,$v['billDate'],'Vendor invoice '.$v['number'],$v['total']);
                audit_event($user,$companyId,'bill.draft_updated','bill',$billId,['number'=>$v['number'],'totalCents'=>$v['total'],'taxEntryMode'=>$v['mode'],'gstHstCents'=>$v['gst'],'pstCents'=>$v['pst']]);db()->commit();
            }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if($e instanceof PDOException&&(string)$e->getCode()==='23000')fail('That vendor invoice number already exists.',409,'duplicate_bill_number');throw $e;}
            json_response(['bill'=>['id'=>$billId,'status'=>'draft','totalCents'=>$v['total']]]);
        }
        if($action!=='issue')fail('Vendor invoice action is invalid.');
        db()->beginTransaction();try{
            $stmt=db()->prepare("SELECT b.*,v.name vendor_name,a.name category_name FROM bills b JOIN vendors v ON v.id=b.vendor_id JOIN accounts a ON a.id=b.category_account_id WHERE b.id=? AND b.company_id=? AND b.status='draft' FOR UPDATE");$stmt->execute([$billId,$companyId]);$bill=$stmt->fetch();if(!$bill)fail('Choose an available draft vendor invoice.',409,'bill_unavailable');assert_not_future_date((string)$bill['bill_date'],'Vendor invoice date');assert_vendor_invoice_on_or_after_books_start($company,(string)$bill['bill_date']);assert_period_open($companyId,(string)$bill['bill_date']);
            if((int)$bill['total_cents']>company_materiality_threshold_cents($companyId)){
                $approvalId=bill_submit_for_approval($user,$companyId,$bill);
                audit_event($user,$companyId,'bill.submitted_for_approval','bill',$billId,['number'=>(string)$bill['number'],'totalCents'=>(int)$bill['total_cents'],'approvalId'=>$approvalId,'materialityThresholdCents'=>company_materiality_threshold_cents($companyId)]);
                db()->commit();json_response(['bill'=>['id'=>$billId,'status'=>'submitted_for_approval','approvalId'=>$approvalId,'approvalRequired'=>true]],202);
            }
            $fxRounding=bill_fx_rounding_cents((int)$bill['foreign_subtotal_cents'],(int)$bill['foreign_gst_hst_cents'],(int)$bill['foreign_pst_cents'],(int)$bill['exchange_rate_micros'],(int)$bill['total_cents']);
            $entryId=null;if((string)$company['accounting_basis']==='accrual')$entryId=add_journal_entry($user,$companyId,(string)$bill['bill_date'],'bill',$billId,bill_posting_description((string)$bill['vendor_name'],(string)$bill['number'],(string)$bill['bill_date'],(string)$bill['category_name']),bill_posting_lines($companyId,(string)$bill['category_account_id'],(int)$bill['subtotal_cents'],(int)$bill['gst_hst_cents'],(int)$bill['pst_cents'],(int)$bill['total_cents'],(bool)($company['pst_recoverable']??false),$fxRounding));
            db()->prepare("UPDATE bills SET status='open',issued_journal_entry_id=? WHERE id=? AND status='draft'")->execute([$entryId,$billId]);if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,'bill',$billId,$entryId);
            audit_event($user,$companyId,'bill.issued','bill',$billId,['number'=>(string)$bill['number'],'totalCents'=>(int)$bill['total_cents'],'journalEntryId'=>$entryId,'accountingBasis'=>(string)$company['accounting_basis']]);db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        json_response(['bill'=>['id'=>$billId,'status'=>'open','journalEntryId'=>$entryId]]);
    }

    $bill=create_bill_record($user,$company,$input,'manual');
    json_response(['bill'=>$bill],201);
}

/** Shared manual-journal service used by the normal form and an authorized Ask Tegh confirmation. */
function manual_journal_post_service(array $user,array $company,array $input,string $initiatedVia='manual',string $idempotencyKey=''): array
{
    require_company_permission($company,'journals.write');
    $companyId = (string)$company['id'];
    $date = safe_date($input['date'] ?? '', 'Journal date');
    assert_not_future_date($date, 'Journal date');
    $memo = clean_text($input['memo'] ?? '', 'Journal memo', 500);
    $sourceLines = $input['lines'] ?? null;
    if (!is_array($sourceLines) || count($sourceLines) < 2 || count($sourceLines) > 100) {
        fail('A manual journal requires between 2 and 100 lines.');
    }
    $lines = [];
    foreach ($sourceLines as $lineInput) {
        if (!is_array($lineInput)) fail('A journal line is invalid.');
        $lines[] = [
            'accountId' => clean_text($lineInput['accountId'] ?? '', 'Journal account', 64),
            'debitCents' => safe_cents($lineInput['debitCents'] ?? 0, 'Journal debit', true),
            'creditCents' => safe_cents($lineInput['creditCents'] ?? 0, 'Journal credit', true),
            'memo' => optional_text($lineInput['memo'] ?? null, 500) ?? '',
        ];
    }
    $sourceId=$idempotencyKey!==''?'ask_'.substr(hash('sha256',$idempotencyKey),0,48):trim((string)($input['draftSourceId'] ?? ''));
    if($idempotencyKey!==''){
        $prior=db()->prepare("SELECT id,status FROM journal_entries WHERE company_id=? AND source_type='manual_journal' AND source_id=? LIMIT 1");$prior->execute([$companyId,$sourceId]);$existing=$prior->fetch();if($existing)return ['journalEntry'=>['id'=>(string)$existing['id'],'status'=>(string)$existing['status']],'idempotent'=>true];
    }elseif ($sourceId !== '') {
        $sourceId = clean_text($sourceId, 'Draft', 64);
        $draftStmt = db()->prepare("SELECT id FROM voucher_draft_payloads WHERE company_id=? AND source_type='manual_journal' AND source_id=?");
        $draftStmt->execute([$companyId,$sourceId]);
        if (!$draftStmt->fetch()) fail('The manual-journal draft is no longer available.',409,'voucher_draft_unavailable');
    } else {
        $sourceId = new_id('manual');
    }
    db()->beginTransaction();
    try {
        $voucherTotal=array_sum(array_map(static fn(array $line):int=>(int)$line['debitCents'],$lines));
        if(function_exists('voucher_register_saved'))voucher_register_saved($user,$companyId,'GJ','GL','manual_journal',$sourceId,$date,'Manual journal · '.$memo,$voucherTotal,null,false);
        $entryId = add_journal_entry($user, $companyId, $date, 'manual_journal', $sourceId, $memo, $lines, null, true);
        $statusStmt=db()->prepare('SELECT status FROM journal_entries WHERE id=? AND company_id=?');$statusStmt->execute([$entryId,$companyId]);$entryStatus=(string)$statusStmt->fetchColumn();
        if($entryStatus==='posted'&&function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,'manual_journal',$sourceId,$entryId);
        db()->prepare("DELETE FROM voucher_draft_payloads WHERE company_id=? AND source_type='manual_journal' AND source_id=?")->execute([$companyId,$sourceId]);
        audit_event($user, $companyId, $entryStatus==='posted'?'journal.manual_posted':'journal.manual_submitted_for_approval', 'journal_entry', $entryId, [
            'date' => $date, 'memo' => $memo, 'lineCount' => count($lines),'initiatedVia'=>$initiatedVia==='ask_tegh'?'Ask Tegh':'Manual UI','status'=>$entryStatus,'materialityThresholdCents'=>company_materiality_threshold_cents($companyId),
        ]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    $approvalId=null;if($entryStatus==='pending_post'){$approval=db()->prepare("SELECT id FROM journal_approvals WHERE company_id=? AND journal_entry_id=? AND status='submitted' LIMIT 1");$approval->execute([$companyId,$entryId]);$approvalId=$approval->fetchColumn()?:null;}
    return ['journalEntry'=>['id'=>$entryId,'status'=>$entryStatus],'approvalId'=>$approvalId,'approvalRequired'=>$entryStatus==='pending_post','idempotent'=>false];
}

function manual_journal_approve_and_post(array $user,array $company,array $input): array
{
    require_company_role($company,'owner');require_company_permission($company,'journals.write');
    $companyId=(string)$company['id'];$approvalId=clean_text($input['approvalId']??'','Journal approval',64);
    $reason=clean_text($input['reviewNote']??'Reviewed supporting evidence and approved for posting.','Review note',500);
    $pdo=db();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT ja.*,je.entry_date,je.memo,je.content_hash,je.status journal_status FROM journal_approvals ja JOIN journal_entries je ON je.id=ja.journal_entry_id AND je.company_id=ja.company_id WHERE ja.id=? AND ja.company_id=? FOR UPDATE");
        $stmt->execute([$approvalId,$companyId]);$approval=$stmt->fetch();
        if(!$approval)fail('Journal approval not found.',404,'journal_approval_not_found');
        if((string)$approval['status']==='posted'&&(string)$approval['journal_status']==='posted'){$pdo->commit();return ['journalEntry'=>['id'=>(string)$approval['journal_entry_id'],'status'=>'posted'],'approvalId'=>$approvalId,'idempotent'=>true];}
        if((string)$approval['status']!=='submitted'||(string)$approval['journal_status']!=='pending_post')fail('This journal is no longer waiting for approval.',409,'journal_approval_unavailable');
        if(hash_equals((string)$approval['prepared_by'],(string)$user['id']))fail('The person who prepared this journal cannot approve it.',409,'journal_dual_control_required');
        assert_period_open($companyId,(string)$approval['entry_date']);
        $lines=$pdo->prepare('SELECT account_id,debit_cents,credit_cents,memo,fx_rounding_cents FROM journal_lines WHERE journal_entry_id=? ORDER BY id FOR UPDATE');$lines->execute([(string)$approval['journal_entry_id']]);
        $actualHash=journal_entry_content_hash((string)$approval['entry_date'],(string)$approval['memo'],$lines->fetchAll());
        if(!hash_equals((string)$approval['payload_hash'],$actualHash)||!hash_equals((string)$approval['content_hash'],$actualHash))fail('The journal changed after it was submitted. Prepare and review it again.',409,'journal_approval_integrity_failed');
        $updated=$pdo->prepare("UPDATE journal_entries SET status='posted' WHERE id=? AND company_id=? AND status='pending_post'");$updated->execute([$approval['journal_entry_id'],$companyId]);
        if($updated->rowCount()!==1)fail('The journal status changed during approval.',409,'journal_approval_conflict');
        $pdo->prepare("UPDATE journal_approvals SET status='posted',approved_by=?,approved_at=UTC_TIMESTAMP(),posted_at=UTC_TIMESTAMP(),review_note=? WHERE id=? AND company_id=? AND status='submitted'")
            ->execute([$user['id'],$reason,$approvalId,$companyId]);
        if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,(string)$approval['source_type'],(string)$approval['source_id'],(string)$approval['journal_entry_id']);
        audit_event($user,$companyId,'journal.approved_posted','journal_entry',(string)$approval['journal_entry_id'],['approvalId'=>$approvalId,'amountCents'=>(int)$approval['amount_cents'],'preparedBy'=>(string)$approval['prepared_by'],'approvedBy'=>(string)$user['id'],'reviewNote'=>$reason]);
        $pdo->commit();
        return ['journalEntry'=>['id'=>(string)$approval['journal_entry_id'],'status'=>'posted'],'approvalId'=>$approvalId,'idempotent'=>false];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function handle_manual_journals(): never
{
    require_method('POST','PATCH');require_csrf();$user=require_user();$company=require_company($user);require_company_role($company,'owner','bookkeeper');$input=request_json();
    if(request_method()==='PATCH')json_response(manual_journal_approve_and_post($user,$company,$input));
    $result=manual_journal_post_service($user,$company,$input);json_response($result,!empty($result['approvalRequired'])?202:201);
}


function handle_bulk_manual_journals(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    $entries = $input['entries'] ?? null;
    if (!is_array($entries) || count($entries) < 1 || count($entries) > 250) {
        fail('Bulk import requires between 1 and 250 journals.', 422, 'bulk_journal_count_invalid');
    }
    $accountStmt = db()->prepare('SELECT id,code,is_control FROM accounts WHERE company_id=? AND active=1');
    $accountStmt->execute([$companyId]);
    $accountByCode=[];
    foreach($accountStmt->fetchAll() as $account) $accountByCode[strtoupper((string)$account['code'])]=['id'=>(string)$account['id'],'isControl'=>(bool)$account['is_control']];
    $prepared=[];$lineCount=0;$references=[];
    foreach($entries as $index=>$source){
        if(!is_array($source)) fail('Bulk journal '.($index+1).' is invalid.',422,'bulk_journal_invalid');
        $date=safe_date($source['date']??'','Journal date');assert_not_future_date($date,'Journal date');
        $memo=clean_text($source['memo']??'','Journal memo',500);
        $sourceLines=$source['lines']??null;
        if(!is_array($sourceLines)||count($sourceLines)<2||count($sourceLines)>100) fail('Bulk journal '.($index+1).' requires between 2 and 100 lines.',422,'bulk_journal_lines_invalid');
        $lines=[];
        foreach($sourceLines as $lineIndex=>$sourceLine){
            if(!is_array($sourceLine)) fail('A line in bulk journal '.($index+1).' is invalid.',422,'bulk_journal_line_invalid');
            $accountId=trim((string)($sourceLine['accountId']??''));
            if($accountId===''){
                $code=strtoupper(trim((string)($sourceLine['accountCode']??'')));
                $account=$accountByCode[$code]??null;
                if(!$account) fail('Account code '.($code!==''?$code:'(blank)').' in bulk journal '.($index+1).' was not found.',422,'bulk_journal_account_not_found');
                if(!empty($account['isControl']))fail('Account code '.$code.' is a protected control account and cannot be used in Journal Import. Use its source module instead.',409,'bulk_journal_control_account_protected');
                $accountId=(string)$account['id'];
            }
            $lines[]=[
                'accountId'=>clean_text($accountId,'Journal account',64),
                'debitCents'=>safe_cents($sourceLine['debitCents']??0,'Journal debit',true),
                'creditCents'=>safe_cents($sourceLine['creditCents']??0,'Journal credit',true),
                'memo'=>optional_text($sourceLine['memo']??null,500)??'',
            ];
            $lineCount++;
        }
        if($lineCount>5000) fail('Bulk import is limited to 5,000 journal lines.',422,'bulk_journal_line_limit');
        $reference=optional_text($source['reference']??null,100)??'';if($reference==='')fail('Every imported journal requires a Journal ID.',422,'bulk_journal_reference_required');$referenceKey=mb_strtolower($reference);if(isset($references[$referenceKey]))fail('Journal ID '.$reference.' is duplicated in the selected batch.',422,'bulk_journal_reference_duplicate');$references[$referenceKey]=true;$contentHash=hash('sha256',json_encode([$companyId,$date,$memo,$reference,$lines],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$prepared[]=['date'=>$date,'memo'=>$memo,'reference'=>$reference,'lines'=>$lines,'sourceId'=>'journalimport_'.substr($contentHash,0,48)];
    }
    $batchId=new_id('journalbatch');$posted=[];$totalDebit=0;
    db()->beginTransaction();
    try{
        // Audit ordering already uses the company voucher-sequence row as the
        // company write lock. Acquire it before any journal/voucher reads so a
        // parallel bulk request cannot invert the lock order between that row
        // and the vouchers source range. Once the first request commits, the
        // waiting request's duplicate preflight observes the committed sources.
        $companyWriteLock=db()->prepare('SELECT next_serial FROM voucher_sequences WHERE company_id=? FOR UPDATE');
        $companyWriteLock->execute([$companyId]);
        if($companyWriteLock->fetchColumn()===false)throw new RuntimeException('The company voucher sequence is unavailable.');
        foreach($prepared as $number=>$entry){
            $sourceId=(string)$entry['sourceId'];$prior=db()->prepare("SELECT id FROM journal_entries WHERE company_id=? AND source_type='manual_journal' AND source_id=? AND status='posted' LIMIT 1");$prior->execute([$companyId,$sourceId]);if($prior->fetchColumn()!==false)fail('Journal ID '.(string)$entry['reference'].' with the same content was already imported.',409,'bulk_journal_duplicate');
            $debits=array_sum(array_map(static fn(array $line):int=>(int)$line['debitCents'],$entry['lines']));
            $credits=array_sum(array_map(static fn(array $line):int=>(int)$line['creditCents'],$entry['lines']));
            if($debits<=0||$debits!==$credits) fail('Bulk journal '.($number+1).' is not balanced.',422,'bulk_journal_not_balanced');
            $memo=$entry['reference']!==''?$entry['memo'].' · '.$entry['reference']:$entry['memo'];
            if(function_exists('voucher_register_saved')) voucher_register_saved($user,$companyId,'GJ','GL','manual_journal',$sourceId,$entry['date'],'Manual journal · '.$memo,$debits,null,false);
            $entryId=add_journal_entry($user,$companyId,$entry['date'],'manual_journal',$sourceId,$memo,$entry['lines'],null,true);
            $statusStmt=db()->prepare('SELECT status FROM journal_entries WHERE id=? AND company_id=?');$statusStmt->execute([$entryId,$companyId]);$entryStatus=(string)$statusStmt->fetchColumn();
            if($entryStatus==='posted'&&function_exists('voucher_mark_posted')) voucher_mark_posted($user,$companyId,'manual_journal',$sourceId,$entryId);
            audit_event($user,$companyId,$entryStatus==='posted'?'journal.manual_posted':'journal.manual_submitted_for_approval','journal_entry',$entryId,['date'=>$entry['date'],'memo'=>$memo,'lineCount'=>count($entry['lines']),'bulkBatchId'=>$batchId,'status'=>$entryStatus]);
            $posted[]=['id'=>$entryId,'date'=>$entry['date'],'memo'=>$memo,'debitCents'=>$debits,'status'=>$entryStatus];$totalDebit+=$debits;
        }
        audit_event($user,$companyId,'journal.bulk_import_posted','journal_batch',$batchId,['journalCount'=>count($posted),'lineCount'=>$lineCount,'totalDebitCents'=>$totalDebit]);
        db()->commit();
    }catch(Throwable $error){
        if(db()->inTransaction())db()->rollBack();
        // The unique journal/voucher source keys are the final concurrency
        // guard. A parallel duplicate can pass the preflight SELECT while the
        // first request is still uncommitted, then block and lose the insert
        // race. Translate only that proven duplicate into the same stable 409
        // contract used by a normal replay; unrelated integrity failures stay
        // visible to the incident handler.
        if($error instanceof PDOException&&(string)$error->getCode()==='23000'){
            $sourceIds=array_values(array_unique(array_map(static fn(array $entry):string=>(string)$entry['sourceId'],$prepared)));
            if($sourceIds!==[]){$placeholders=implode(',',array_fill(0,count($sourceIds),'?'));$duplicate=db()->prepare("SELECT source_id FROM journal_entries WHERE company_id=? AND source_type='manual_journal' AND source_id IN ($placeholders) LIMIT 1");$duplicate->execute(array_merge([$companyId],$sourceIds));if($duplicate->fetchColumn()!==false)fail('One or more journals in this batch were imported concurrently. Refresh before retrying.',409,'bulk_journal_duplicate',false);}
        }
        throw $error;
    }
    $pendingCount=count(array_filter($posted,static fn(array $entry):bool=>$entry['status']==='pending_post'));
    json_response(['batchId'=>$batchId,'postedCount'=>count($posted)-$pendingCount,'submittedForApprovalCount'=>$pendingCount,'lineCount'=>$lineCount,'totalDebitCents'=>$totalDebit,'journals'=>$posted],$pendingCount?202:201);
}

/** @return array{code:string,name:string,type:string,normal:string} */
function validated_import_account(array $source): array
{
    $code = strtoupper(clean_text($source['code'] ?? '', 'Account code', 20));
    if (!preg_match('/^[A-Z0-9.-]+$/', $code)) fail('Account code may contain letters, numbers, dots, and hyphens only.');
    $name = clean_text($source['name'] ?? '', 'Account name', 160);
    $type = strtolower((string)($source['type'] ?? $source['accountType'] ?? ''));
    if (!in_array($type, ['asset', 'liability', 'equity', 'income', 'expense'], true)) fail('An imported account type is invalid.');
    $normal = (string)($source['normalBalance'] ?? (in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit'));
    if (!in_array($normal, ['debit', 'credit'], true)) fail('An imported account normal balance is invalid.');
    return ['code' => $code, 'name' => $name, 'type' => $type, 'normal' => $normal,
        'gifiCode' => optional_text($source['gifiCode'] ?? $source['gifi_code'] ?? null, 10),
        't2125Line' => optional_text($source['t2125Line'] ?? $source['t2125_line'] ?? null, 30),
        'reportingGroup' => optional_text($source['reportingGroup'] ?? $source['reporting_group'] ?? null, 120),
        'expenseCategory' => optional_text($source['expenseCategory'] ?? $source['expense_category'] ?? null, 120),
    ];
}

function import_or_resolve_account(string $companyId, array $source, PDOStatement $insert): array
{
    $validated = validated_import_account($source);
    $stmt = db()->prepare('SELECT id, account_type, normal_balance, is_control FROM accounts WHERE company_id = ? AND code = ?');
    $stmt->execute([$companyId, $validated['code']]);
    $existing = $stmt->fetch();
    if ($existing) {
        if ((string)$existing['account_type'] !== $validated['type'] || (string)$existing['normal_balance'] !== $validated['normal']) {
            fail('Account ' . $validated['code'] . ' already exists with a different type or normal balance.', 409, 'account_import_conflict');
        }
        db()->prepare('UPDATE accounts SET name=?, gifi_code=COALESCE(?,gifi_code), t2125_line=COALESCE(?,t2125_line), reporting_group=COALESCE(?,reporting_group), expense_category=COALESCE(?,expense_category) WHERE id=? AND company_id=?')
            ->execute([$validated['name'],$validated['gifiCode'],$validated['t2125Line'],$validated['reportingGroup'],$validated['expenseCategory'],$existing['id'],$companyId]);
        return ['id' => (string)$existing['id'], ...$validated, 'created' => false];
    }
    $id = new_id('account');
    $insert->execute([$id, $companyId, $validated['code'], $validated['name'], $validated['type'], $validated['normal'], $validated['gifiCode'], $validated['t2125Line'], $validated['reportingGroup'], $validated['expenseCategory']]);
    return ['id' => $id, ...$validated, 'created' => true];
}

function repair_opening_balance_vouchers(array $user, string $companyId): int
{
    if (!schema_table_exists('vouchers')) return 0;
    $stmt = db()->prepare("SELECT obi.id,obi.effective_date,obi.filename,obi.total_debit_cents,obi.journal_entry_id
        FROM opening_balance_imports obi
        WHERE obi.company_id=? AND obi.journal_entry_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.company_id=obi.company_id AND v.source_id=obi.id AND v.source_type IN ('opening_balance','opening_balance_import'))
        ORDER BY obi.created_at ASC");
    $stmt->execute([$companyId]);
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (!function_exists('voucher_register_saved')) continue;
        voucher_register_saved($user,$companyId,'OB','GL','opening_balance',(string)$row['id'],(string)$row['effective_date'],
            'Opening trial balance · '.((string)$row['filename'] ?: 'Opening balances'),(int)$row['total_debit_cents'],(string)$row['journal_entry_id'],true);
        $count++;
    }
    if ($count > 0) audit_event($user,$companyId,'opening_balances.vouchers_repaired','opening_balance','historical',['count'=>$count]);
    return $count;
}

function handle_opening_balances(): never
{
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];

    if (request_method() === 'GET') {
        repair_opening_balance_vouchers($user,$companyId);
        $stmt = db()->prepare("SELECT a.id,a.code,a.name,a.account_type,a.normal_balance,a.is_control,a.active,
            COALESCE(ob.amount_cents,0) AS opening_amount_cents,ob.updated_at
            FROM accounts a
            LEFT JOIN opening_balance_drafts ob ON ob.company_id=a.company_id AND ob.account_id=a.id
            WHERE a.company_id=? AND a.active=1 ORDER BY a.code");
        $stmt->execute([$companyId]);
        $accounts = array_map(fn(array $row): array => [
            'id'=>(string)$row['id'],'code'=>(string)$row['code'],'name'=>(string)$row['name'],
            'type'=>(string)$row['account_type'],'normalBalance'=>(string)$row['normal_balance'],
            'isControl'=>(bool)$row['is_control'],'systemControl'=>((string)$row['code']==='9999' && (bool)$row['is_control']),'openingBalanceAllowed'=>function_exists('tegh_opening_balance_account_allowed') ? (bool)(tegh_opening_balance_account_allowed($companyId,$row)[0]??false) : !(in_array((string)$row['code'],['9999','1200','2050'],true) || (bool)$row['is_control']), 'openingBalanceCents'=>(int)$row['opening_amount_cents'],
            'updatedAt'=>$row['updated_at'],
        ], $stmt->fetchAll());
        $posted = db()->prepare("SELECT obi.id,obi.effective_date,obi.filename,obi.total_debit_cents,obi.total_credit_cents,obi.journal_entry_id,obi.created_at,v.voucher_number
            FROM opening_balance_imports obi LEFT JOIN vouchers v ON v.company_id=obi.company_id AND v.source_id=obi.id AND v.source_type IN ('opening_balance','opening_balance_import')
            WHERE obi.company_id=? ORDER BY obi.created_at DESC LIMIT 1");
        $posted->execute([$companyId]);
        $lastPosted = $posted->fetch();
        if ($lastPosted) {
            $postedLines = db()->prepare('SELECT account_id,debit_cents,credit_cents FROM journal_lines WHERE journal_entry_id=?');
            $postedLines->execute([(string)$lastPosted['journal_entry_id']]);
            $postedByAccount = [];
            foreach ($postedLines->fetchAll() as $line) {
                $postedByAccount[(string)$line['account_id']] = (int)$line['debit_cents'] > 0 ? (int)$line['debit_cents'] : -((int)$line['credit_cents']);
            }
            foreach ($accounts as &$account) $account['postedOpeningBalanceCents'] = $postedByAccount[(string)$account['id']] ?? 0;
            unset($account);
        } else {
            foreach ($accounts as &$account) $account['postedOpeningBalanceCents'] = 0;
            unset($account);
        }
        $controlState = ['status'=>'missing','code'=>'9999','message'=>'Opening Balance Control is not configured.'];
        try {
            $controlStmt = db()->prepare("SELECT configured_code,account_id,status,conflict_message FROM company_system_accounts WHERE company_id=? AND system_key='opening_balance_control' LIMIT 1");
            $controlStmt->execute([$companyId]);
            if ($controlRow = $controlStmt->fetch()) $controlState = ['status'=>(string)$controlRow['status'],'code'=>(string)$controlRow['configured_code'],'accountId'=>$controlRow['account_id']!==null?(string)$controlRow['account_id']:null,'message'=>$controlRow['conflict_message']!==null?(string)$controlRow['conflict_message']:null];
        } catch (Throwable $ignored) {}
        json_response([
            'booksStartDate'=>$company['books_start_date'] !== null ? (string)$company['books_start_date'] : null,
            'openingBalanceControl'=>$controlState,
            'fiscalYearEndDate'=>$company['fiscal_year_end_date'] !== null ? (string)$company['fiscal_year_end_date'] : null,
            'currency'=>(string)$company['currency'],
            'canEdit'=>in_array((string)$company['role'], ['owner','admin','editor'], true),
            'canPost'=>in_array((string)$company['role'], ['owner','admin'], true),
            'accounts'=>$accounts,
            'lastPosted'=>$lastPosted ? [
                'id'=>(string)$lastPosted['id'],'effectiveDate'=>(string)$lastPosted['effective_date'],
                'filename'=>(string)$lastPosted['filename'],'totalDebitCents'=>(int)$lastPosted['total_debit_cents'],
                'totalCreditCents'=>(int)$lastPosted['total_credit_cents'],'journalEntryId'=>(string)$lastPosted['journal_entry_id'],
                'createdAt'=>(string)$lastPosted['created_at'],'voucherNumber'=>$lastPosted['voucher_number']!==null?(string)$lastPosted['voucher_number']:null,
            ] : null,
        ]);
    }

    require_csrf();
    $input = request_json();
    if (request_method() === 'PUT') {
        require_company_role($company, 'owner', 'bookkeeper');
        // File imports are centralized under Settings → Data Import. This endpoint accepts manual per-account edits only.
        $accountId = clean_text($input['accountId'] ?? '', 'Account', 64);
        $amount = safe_cents($input['amountCents'] ?? 0, 'Opening balance', true);
        $stmt = db()->prepare('SELECT id,code,is_control,active FROM accounts WHERE id=? AND company_id=? LIMIT 1');
        $stmt->execute([$accountId,$companyId]);$manualAccount=$stmt->fetch();
        if (!$manualAccount || !(bool)$manualAccount['active']) fail('The selected GL account is unavailable.',404,'account_not_found');
        if (function_exists('tegh_opening_balance_account_allowed')) {
            $allowed = tegh_opening_balance_account_allowed($companyId,$manualAccount);
            if (!(bool)($allowed[0]??false)) fail((string)($allowed[1]??'This account cannot receive a manual opening balance.'),409,'opening_balance_account_protected');
        } else {
            if ((string)$manualAccount['code']==='9999') fail('Opening Balance Control account 9999 is managed automatically and cannot be entered directly.',409,'system_account_protected');
            if (in_array((string)$manualAccount['code'],['1200','2050'],true)) fail((string)$manualAccount['code']==='1200'?'Enter customer opening balances through Customer setup/import so Accounts Receivable agrees to the customer subledger.':'Enter vendor opening balances through Vendor setup/import so Accounts Payable agrees to the vendor subledger.',409,'subledger_opening_required');
        }
        if ($amount === 0) {
            db()->prepare('DELETE FROM opening_balance_drafts WHERE company_id=? AND account_id=?')->execute([$companyId,$accountId]);
        } else {
            db()->prepare('INSERT INTO opening_balance_drafts (company_id,account_id,amount_cents,updated_by)
                VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE amount_cents=VALUES(amount_cents),updated_by=VALUES(updated_by)')
                ->execute([$companyId,$accountId,$amount,$user['id']]);
        }
        json_response(['saved'=>true,'accountId'=>$accountId,'amountCents'=>$amount]);
    }

    require_method('POST');
    require_company_role($company, 'owner');
    $action = strtolower(trim((string)($input['action'] ?? '')));
    if ($action !== 'post') fail('Choose Post Opening Trial Balance to post the saved balances.',422,'opening_balance_action_required');
    $date = $company['books_start_date'] !== null ? (string)$company['books_start_date'] : '';
    if ($date === '') fail('Set the Start of books date in Company details before posting opening balances.',422,'books_start_date_required');
    $date = safe_date($date, 'Start of books');
    assert_not_future_date($date, 'Start of books');
    $stmt = db()->prepare("SELECT a.id,a.code,a.normal_balance,a.is_control,a.active,ob.amount_cents
        FROM opening_balance_drafts ob JOIN accounts a ON a.id=ob.account_id
        WHERE ob.company_id=? AND a.company_id=? AND a.active=1 AND a.code<>'9999' AND ob.amount_cents<>0 ORDER BY a.code FOR UPDATE");
    db()->beginTransaction();
    try {
        $stmt->execute([$companyId,$companyId]);
        $rows = $stmt->fetchAll();
        if (count($rows) < 1) fail('Enter at least one non-zero opening balance.');
        $lines=[];$debits=0;$credits=0;$canonical=[];
        foreach ($rows as $row) {
            // Revalidate every saved draft at commit time. This prevents a
            // protected account, including AR/AP or a newly designated system
            // control, from being posted through a stale browser draft.
            if (function_exists('tegh_opening_balance_account_allowed')) {
                [$allowed,$reason] = tegh_opening_balance_account_allowed($companyId,$row);
                if (!$allowed) fail((string)$reason,409,'opening_balance_account_protected');
            } elseif ((bool)$row['is_control'] || in_array((string)$row['code'],['9999','1200','2050'],true)) {
                fail('A protected or subledger-controlled account is present in the saved opening balances. Remove it before posting.',409,'opening_balance_account_protected');
            }
            // Canonical opening-balance sign convention used by the UI/importer:
            // positive amount = debit, negative amount = credit. The account's
            // normal balance is descriptive only and must not reverse the sign.
            $amount=(int)$row['amount_cents'];
            $debitCents=$amount>0?$amount:0;
            $creditCents=$amount<0?-$amount:0;
            $debits+=$debitCents;$credits+=$creditCents;
            $lines[]=['accountId'=>(string)$row['id'],'debitCents'=>$debitCents,'creditCents'=>$creditCents,'memo'=>'Opening balance'];
            $canonical[]=[(string)$row['code'],$amount];
        }
        if ($debits<=0 && $credits<=0) fail('Enter at least one non-zero opening balance.',422,'opening_balance_empty');
        $difference=$debits-$credits;$offsetCents=abs($difference);
        if($difference!==0){$control=opening_balance_control_account($companyId);$lines[]=['accountId'=>(string)$control['id'],'debitCents'=>$difference<0?$offsetCents:0,'creditCents'=>$difference>0?$offsetCents:0,'memo'=>'Automatic opening-balance offset'];if($difference>0)$credits+=$offsetCents;else$debits+=$offsetCents;}
        $sourceHash=hash('sha256',$companyId.'|'.$date.'|'.json_encode($canonical,JSON_THROW_ON_ERROR));
        $dupe=db()->prepare('SELECT id FROM opening_balance_imports WHERE company_id=? AND source_hash=?');
        $dupe->execute([$companyId,$sourceHash]);
        if($dupe->fetchColumn()!==false)fail('These opening balances were already posted.',409,'opening_balance_duplicate');
        $importId=new_id('opening');
        $entryId=add_journal_entry($user,$companyId,$date,'opening_balance',$importId,'Opening trial balance · Manual entry',$lines);
        $voucherId=function_exists('voucher_register_saved')?voucher_register_saved($user,$companyId,'OB','GL','opening_balance',$importId,$date,'Opening trial balance',$debits,$entryId,true):'';
        db()->prepare('INSERT INTO opening_balance_imports (id,company_id,effective_date,filename,source_hash,row_count,total_debit_cents,total_credit_cents,journal_entry_id,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$importId,$companyId,$date,'Manual opening balances',$sourceHash,count($lines),$debits,$credits,$entryId,$user['id']]);
        db()->prepare('DELETE FROM opening_balance_drafts WHERE company_id=?')->execute([$companyId]);
        audit_event($user,$companyId,'opening_trial_balance.posted','opening_balance_import',$importId,[
            'effectiveDate'=>$date,'rowCount'=>count($lines),'totalDebitCents'=>$debits,'totalCreditCents'=>$credits,'offsetCents'=>$offsetCents,
        ]);
        db()->commit();
        $voucherNumber=null;if($voucherId!==''){$vq=db()->prepare('SELECT voucher_number FROM vouchers WHERE id=?');$vq->execute([$voucherId]);$vn=$vq->fetchColumn();if($vn!==false)$voucherNumber=(string)$vn;}
        json_response(['posted'=>true,'openingBalanceImport'=>['id'=>$importId,'journalEntryId'=>$entryId,'effectiveDate'=>$date,'voucherNumber'=>$voucherNumber]],201);
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
}

function handle_period_close(): never
{
    // Compatibility bridge for the original close-through form. New releases
    // store all locks in period_locks so there is no hidden secondary lock.
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    $companyId = (string)$company['id'];
    $input = request_json();
    $closedThrough = trim((string)($input['closedThroughDate'] ?? ''));
    require_company_permission($company, $closedThrough === '' ? 'period.unlock' : 'period.lock');
    $periodStart = '1900-01-01';

    db()->beginTransaction();
    try {
        db()->prepare("UPDATE period_locks SET locked=0,reason='Close-through control replaced or reopened',updated_by=? WHERE company_id=? AND period_start=?")
            ->execute([$user['id'],$companyId,$periodStart]);
        if ($closedThrough !== '') {
            $closedThrough = safe_date($closedThrough, 'Close-through date');
            assert_not_future_date($closedThrough, 'Close-through date');
            $stmt = db()->prepare("SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND status='pending' AND transaction_date<=?");
            $stmt->execute([$companyId,$closedThrough]);
            if ((int)$stmt->fetchColumn() > 0) fail('Review all pending bank transactions through the lock date first.');
            $id = new_id('periodlock');
            db()->prepare("INSERT INTO period_locks (id,company_id,period_start,period_end,locked,reason,updated_by) VALUES (?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE locked=1,reason=VALUES(reason),updated_by=VALUES(updated_by)")
                ->execute([$id,$companyId,$periodStart,$closedThrough,'Close-through period control',$user['id']]);
        } else {
            $closedThrough = null;
        }
        db()->prepare("INSERT INTO accounting_controls (company_id,closed_through_date,updated_by) VALUES (?,NULL,?) ON DUPLICATE KEY UPDATE closed_through_date=NULL,updated_by=VALUES(updated_by)")
            ->execute([$companyId,$user['id']]);
        audit_event($user,$companyId,$closedThrough===null?'period.unlocked':'period.locked','period_lock',$companyId,[
            'periodStart'=>$periodStart,'periodEnd'=>$closedThrough,'source'=>'close_through_control',
        ]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['closedThroughDate'=>$closedThrough]);
}

function aging_bucket(string $dueDate, string $today): string
{
    if ($dueDate >= $today) return 'current';
    $days = (int)((new DateTimeImmutable($dueDate))->diff(new DateTimeImmutable($today))->days ?? 0);
    if ($days <= 30) return 'days1To30';
    if ($days <= 60) return 'days31To60';
    if ($days <= 90) return 'days61To90';
    return 'daysOver90';
}

function books_workspace_data(array $company): array
{
    $companyId = (string)$company['id'];
    $today = canadian_today();
    $stmt = db()->prepare('SELECT currency_code, rate_to_base_micros, rate_date, active
        FROM company_currencies WHERE company_id = ? AND active = 1 ORDER BY currency_code');
    $stmt->execute([$companyId]);
    $currencies = array_map(static fn(array $row): array => [
        'code' => (string)$row['currency_code'], 'rateToBaseMicros' => (int)$row['rate_to_base_micros'],
        'rateDate' => (string)$row['rate_date'], 'active' => (bool)$row['active'],
    ], $stmt->fetchAll());

    $stmt = db()->prepare('SELECT v.id,v.name,v.contact_name,v.email,v.phone,v.address,v.address_line1,v.address_line2,v.city,v.province,v.postal_code,v.country,v.default_terms_days,v.default_expense_account_id,v.default_currency,v.notes,v.status,v.active,
        pob.amount_cents AS opening_balance_cents,pob.effective_date AS opening_balance_date,vx.voucher_number AS opening_voucher_number,
        EXISTS(SELECT 1 FROM bills b WHERE b.company_id=v.company_id AND b.vendor_id=v.id) AS locked
        FROM vendors v
        LEFT JOIN party_opening_balances pob ON pob.company_id=v.company_id AND pob.party_type=\'vendor\' AND pob.party_id=v.id
        LEFT JOIN vouchers vx ON vx.id=pob.voucher_id
        WHERE v.company_id=? ORDER BY CASE v.status WHEN \'active\' THEN 0 ELSE 1 END,v.name');
    $stmt->execute([$companyId]);
    $vendors = array_map(static fn(array $row): array => [
        'id'=>(string)$row['id'],'name'=>(string)$row['name'],'contactName'=>$row['contact_name']!==null?(string)$row['contact_name']:null,
        'email'=>$row['email']!==null?(string)$row['email']:null,'phone'=>$row['phone']!==null?(string)$row['phone']:null,'address'=>$row['address']!==null?(string)$row['address']:null,
        'addressLine1'=>$row['address_line1']!==null?(string)$row['address_line1']:null,'addressLine2'=>$row['address_line2']!==null?(string)$row['address_line2']:null,'city'=>$row['city']!==null?(string)$row['city']:null,
        'province'=>$row['province']!==null?(string)$row['province']:null,'postalCode'=>$row['postal_code']!==null?(string)$row['postal_code']:null,'country'=>(string)($row['country']??'Canada'),
        'defaultTermsDays'=>(int)$row['default_terms_days'],'paymentTerms'=>tegh_terms_label((int)$row['default_terms_days']),
        'defaultExpenseAccountId'=>$row['default_expense_account_id']!==null?(string)$row['default_expense_account_id']:null,'currency'=>(string)$row['default_currency'],
        'notes'=>$row['notes']!==null?(string)$row['notes']:null,'status'=>(string)($row['status']??((bool)$row['active']?'active':'inactive')),'active'=>(bool)$row['active'],
        'openingBalanceCents'=>(int)($row['opening_balance_cents']??0),'openingBalanceDate'=>$row['opening_balance_date']!==null?(string)$row['opening_balance_date']:null,
        'openingVoucherNumber'=>$row['opening_voucher_number']!==null?(string)$row['opening_voucher_number']:null,'locked'=>(bool)$row['locked'],
    ], $stmt->fetchAll());

    $stmt = db()->prepare("SELECT b.*, v.name AS vendor_name, a.name AS category_name, ps.name AS product_service_name, ps.kind AS product_service_kind, COALESCE(vx.voucher_number,ovx.voucher_number) AS voucher_number, odi.journal_entry_id AS opening_journal_entry_id
        FROM bills b JOIN vendors v ON v.id = b.vendor_id JOIN accounts a ON a.id = b.category_account_id
        LEFT JOIN products_services ps ON ps.id=b.product_service_id AND ps.company_id=b.company_id
        LEFT JOIN vouchers vx ON vx.company_id=b.company_id AND vx.source_type='bill' AND vx.source_id=b.id
        LEFT JOIN opening_document_imports odi ON odi.id=b.opening_import_id AND odi.company_id=b.company_id
        LEFT JOIN vouchers ovx ON ovx.company_id=b.company_id AND ovx.source_type='opening_vendor_bills' AND ovx.source_id=b.opening_import_id
        WHERE b.company_id = ? ORDER BY b.bill_date DESC, b.created_at DESC");
    $stmt->execute([$companyId]);
    $bills = array_map(static function (array $row) use ($today): array {
        $status = (string)$row['status'];
        $display = $status === 'open' && (string)$row['due_date'] < $today && (int)$row['balance_cents'] > 0
            ? 'Overdue' : ($status === 'draft' ? 'Draft' : ($status === 'open' ? 'Open' : ($status === 'paid' ? 'Paid' : 'Void')));
        return [
            'id' => (string)$row['id'], 'vendorId' => (string)$row['vendor_id'], 'vendorName' => (string)$row['vendor_name'],
            'productServiceId' => $row['product_service_id'] !== null ? (string)$row['product_service_id'] : null,
            'productServiceName' => $row['product_service_name'] !== null ? (string)$row['product_service_name'] : null,
            'productServiceKind' => $row['product_service_kind'] !== null ? (string)$row['product_service_kind'] : null,
            'quantityMilli' => (int)($row['quantity_milli']??1000),
            'number' => (string)$row['number'], 'billDate' => (string)$row['bill_date'], 'dueDate' => (string)$row['due_date'],
            'status' => $status, 'displayStatus' => $display, 'categoryAccountId' => (string)$row['category_account_id'],
            'paymentTermsDays' => (int)$row['payment_terms_days'],
            'categoryName' => (string)$row['category_name'], 'subtotalCents' => (int)$row['subtotal_cents'],
            'gstHstCents'=>(int)($row['gst_hst_cents']??$row['tax_cents']), 'pstCents'=>(int)($row['pst_cents']??0), 'taxEntryMode'=>(string)($row['tax_entry_mode']??'exclusive'),
            'taxCents' => (int)$row['tax_cents'], 'totalCents' => (int)$row['total_cents'], 'balanceCents' => (int)$row['balance_cents'],
            'currency' => (string)$row['currency'], 'exchangeRateMicros' => (int)$row['exchange_rate_micros'],
            'foreignSubtotalCents' => (int)$row['foreign_subtotal_cents'], 'foreignGstHstCents'=>(int)($row['foreign_gst_hst_cents']??$row['foreign_tax_cents']), 'foreignPstCents'=>(int)($row['foreign_pst_cents']??0), 'foreignTaxCents' => (int)$row['foreign_tax_cents'],
            'foreignTotalCents' => (int)$row['foreign_total_cents'], 'foreignBalanceCents' => (int)$row['foreign_balance_cents'],
            'memo' => (string)$row['memo'],
            'journalEntryId' => $row['issued_journal_entry_id'] !== null ? (string)$row['issued_journal_entry_id'] : ($row['opening_journal_entry_id']!==null?(string)$row['opening_journal_entry_id']:null),
            'isOpeningDocument'=>(bool)($row['is_opening_document']??false),'openingImportId'=>$row['opening_import_id']!==null?(string)$row['opening_import_id']:null,'originalPaidCents'=>(int)($row['original_paid_cents']??0),
            'isRecurring' => (bool)($row['is_recurring'] ?? false),
            'transactionNumber'=>$row['voucher_number']!==null?(string)$row['voucher_number']:null,
        ];
    }, $stmt->fetchAll());

    $payableAging = ['current' => 0, 'days1To30' => 0, 'days31To60' => 0, 'days61To90' => 0, 'daysOver90' => 0];
    foreach ($bills as $bill) {
        if ($bill['status'] === 'open' && $bill['balanceCents'] > 0) {
            $payableAging[aging_bucket($bill['dueDate'], $today)] += $bill['balanceCents'];
        }
    }
    $vendorOpeningTotal=0;
    if(schema_table_exists('party_opening_balances')){
        $pob=db()->prepare("SELECT effective_date,amount_cents FROM party_opening_balances WHERE company_id=? AND party_type='vendor'");$pob->execute([$companyId]);
        foreach($pob->fetchAll() as $opening){$amount=(int)$opening['amount_cents'];$vendorOpeningTotal+=$amount;if($amount>0)$payableAging[aging_bucket((string)$opening['effective_date'],$today)]+=$amount;elseif($amount<0)$payableAging['current']+=$amount;}
    }
    $stmt = db()->prepare('SELECT closed_through_date FROM accounting_controls WHERE company_id = ?');
    $stmt->execute([$companyId]);
    $closedThrough = $stmt->fetchColumn();
    return [
        'companyCurrencies' => $currencies,
        'vendors' => $vendors,
        'bills' => $bills,
        'accountingControls' => ['closedThroughDate' => $closedThrough !== false && $closedThrough !== null ? (string)$closedThrough : null],
        'payableAging' => $payableAging,
        'vendorOpeningTotalCents'=>$vendorOpeningTotal,
    ];
}
