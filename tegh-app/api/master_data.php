<?php
declare(strict_types=1);

/** Shared master-data helpers for Tegh 3.6.0. */

function tegh_system_account(string $companyId, string $systemKey, bool $required = true): ?array
{
    if (!schema_table_exists('company_system_accounts')) {
        if ($required) fail('System account configuration is not available. Run the Tegh database upgrade first.', 409, 'system_account_schema_required');
        return null;
    }
    $stmt = db()->prepare("SELECT csa.system_key,csa.configured_code,csa.status,csa.conflict_message,a.id,a.code,a.name,a.account_type,a.normal_balance,a.is_control,a.active
        FROM company_system_accounts csa
        LEFT JOIN accounts a ON a.id=csa.account_id AND a.company_id=csa.company_id
        WHERE csa.company_id=? AND csa.system_key=? LIMIT 1");
    $stmt->execute([$companyId,$systemKey]);
    $row=$stmt->fetch();
    if(!$row || (string)$row['status']!=='active' || empty($row['id']) || !(bool)$row['active'] || !(bool)$row['is_control']){
        if($required){
            $message=trim((string)($row['conflict_message']??''));
            fail($message!==''?$message:'The required Tegh system control account is not configured for this company.',409,'system_account_conflict');
        }
        return null;
    }
    return $row;
}

function opening_balance_control_account(string $companyId, bool $required = true): ?array
{
    return tegh_system_account($companyId,'opening_balance_control',$required);
}

function tegh_account_is_system_control(string $companyId, string $accountId): bool
{
    if(!schema_table_exists('company_system_accounts')) return false;
    $stmt=db()->prepare("SELECT 1 FROM company_system_accounts WHERE company_id=? AND account_id=? AND status='active' LIMIT 1");
    $stmt->execute([$companyId,$accountId]);
    return $stmt->fetchColumn()!==false;
}

function tegh_account_is_opening_balance_control(string $companyId, string $accountId): bool
{
    if(!schema_table_exists('company_system_accounts')) return false;
    $stmt=db()->prepare("SELECT 1 FROM company_system_accounts WHERE company_id=? AND account_id=? AND system_key='opening_balance_control' AND status='active' LIMIT 1");
    $stmt->execute([$companyId,$accountId]);
    return $stmt->fetchColumn()!==false;
}

function tegh_opening_balance_account_allowed(string $companyId, array $account): array
{
    $code=strtoupper(trim((string)($account['code']??'')));
    if($code==='9999') return [false,'Opening Balance Control account 9999 cannot be supplied directly. Tegh uses it only as the balancing offset.'];
    if(in_array($code,['1200','2050'],true)) return [false,$code==='1200'?'Accounts Receivable opening balances must be loaded through Customer setup/import so the customer subledger remains synchronized.':'Accounts Payable opening balances must be loaded through Vendor setup/import so the vendor subledger remains synchronized.'];
    if(!(bool)($account['active']??false)) return [false,'The account is inactive.'];
    if(!(bool)($account['is_control']??false)) return [true,''];
    // A linked bank/credit-card ledger is the one control-account category that may
    // legitimately need a company opening balance without a separate subledger import.
    $stmt=db()->prepare('SELECT 1 FROM bank_accounts WHERE company_id=? AND ledger_account_id=? LIMIT 1');
    $stmt->execute([$companyId,(string)$account['id']]);
    if($stmt->fetchColumn()!==false) return [true,''];
    return [false,'Protected control accounts cannot be loaded directly through Opening Balances. Use the source-module setup/import designed for that control account.'];
}

function tegh_province_codes(): array
{
    return ['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'];
}

function tegh_party_status(mixed $value, string $label = 'Status'): string
{
    $status = strtolower(trim((string)$value));
    $status = str_replace([' ', '-'], '_', $status);
    if ($status === '') $status = 'active';
    if ($status === 'hold') $status = 'on_hold';
    if (!in_array($status, ['active','on_hold','inactive'], true)) {
        fail($label . ' must be Active, On Hold, or Inactive.');
    }
    return $status;
}

function tegh_terms_days(mixed $value, mixed $customDays = null, int $default = 30): int
{
    $raw = trim((string)$value);
    if ($raw === '') return $default;
    $key = strtolower(preg_replace('/\s+/', ' ', $raw) ?? $raw);
    $known = [
        'due on receipt'=>0,'dueonreceipt'=>0,'receipt'=>0,
        'net 7'=>7,'net7'=>7,'7'=>7,
        'net 15'=>15,'net15'=>15,'15'=>15,
        'net 30'=>30,'net30'=>30,'30'=>30,
        'net 45'=>45,'net45'=>45,'45'=>45,
        'net 60'=>60,'net60'=>60,'60'=>60,
    ];
    if (array_key_exists($key, $known)) return $known[$key];
    if ($key === 'custom') {
        $raw = trim((string)$customDays);
        if ($raw === '') fail('Enter the number of days for Custom payment terms.');
    }
    if (!preg_match('/^\d{1,4}$/', $raw)) fail('Choose valid payment terms.');
    $days = (int)$raw;
    if ($days < 0 || $days > 3650) fail('Payment terms must be between 0 and 3,650 days.');
    return $days;
}

function tegh_terms_label(int $days): string
{
    return $days === 0 ? 'Due on Receipt' : 'Net ' . $days;
}

function tegh_structured_address(array $input, string $legacyKey = 'billingAddress'): array
{
    $line1 = optional_text($input['addressLine1'] ?? null, 180);
    $line2 = optional_text($input['addressLine2'] ?? null, 180);
    $city = optional_text($input['city'] ?? null, 100);
    $postal = optional_text($input['postalCode'] ?? null, 20);
    $country = optional_text($input['country'] ?? null, 80) ?? 'Canada';
    $legacy = optional_text($input[$legacyKey] ?? ($input['address'] ?? null), 500);
    if ($line1 === null && $line2 === null && $city === null && $postal === null && $legacy !== null) {
        $line1 = $legacy;
    }
    $parts = array_values(array_filter([$line1,$line2,$city,$postal,$country], static fn($v): bool => $v !== null && $v !== ''));
    return [
        'addressLine1'=>$line1,'addressLine2'=>$line2,'city'=>$city,'postalCode'=>$postal,'country'=>$country,
        'displayAddress'=>$parts ? implode(', ', $parts) : null,
    ];
}

function tegh_signed_opening_balance_cents(mixed $value, string $label = 'Opening balance'): int
{
    if ($value === null || trim((string)$value) === '') return 0;
    if (is_int($value)) return $value;
    $raw = trim((string)$value);
    if (preg_match('/^-?\d+$/', $raw)) return (int)$raw;
    fail($label . ' must be supplied in cents by the application.');
}

function party_opening_balance_row(string $companyId, string $partyType, string $partyId): ?array
{
    if (!schema_table_exists('party_opening_balances')) return null;
    $stmt = db()->prepare('SELECT pob.*,v.voucher_number FROM party_opening_balances pob LEFT JOIN vouchers v ON v.id=pob.voucher_id WHERE pob.company_id=? AND pob.party_type=? AND pob.party_id=? LIMIT 1');
    $stmt->execute([$companyId,$partyType,$partyId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Post one party-specific opening balance through the existing journal + voucher engines.
 * Positive customer amount = amount receivable. Positive vendor amount = amount payable.
 */
function post_party_opening_balance(array $user, array $company, string $partyType, string $partyId, string $partyName, int $amountCents, string $date, string $source = 'manual'): ?array
{
    if ($amountCents === 0) return null;
    if (!in_array($partyType, ['customer','vendor'], true)) fail('Opening-balance party type is invalid.');
    $companyId = (string)$company['id'];
    $permission = $partyType === 'customer' ? 'customers.write' : 'vendors.write';
    require_company_permission($company, $permission);
    $date = safe_date($date, 'Opening balance date');
    assert_not_future_date($date, 'Opening balance date');
    if (party_opening_balance_row($companyId,$partyType,$partyId)) {
        fail(ucfirst($partyType).' opening balance has already been recorded. Use an adjusting entry rather than rewriting historical opening data.',409,'party_opening_balance_exists');
    }
    $controlCode = $partyType === 'customer' ? '1200' : '2050';
    $controlId = account_by_code($companyId,$controlCode);
    $offset = opening_balance_control_account($companyId);
    $offsetId = (string)$offset['id'];
    $abs = abs($amountCents);
    if ($partyType === 'customer') {
        $lines = $amountCents > 0
            ? [['accountId'=>$controlId,'debitCents'=>$abs,'creditCents'=>0],['accountId'=>$offsetId,'debitCents'=>0,'creditCents'=>$abs]]
            : [['accountId'=>$offsetId,'debitCents'=>$abs,'creditCents'=>0],['accountId'=>$controlId,'debitCents'=>0,'creditCents'=>$abs]];
        $prefix='AROB';$module='AR';$sourceType='customer_opening_balance';
    } else {
        $lines = $amountCents > 0
            ? [['accountId'=>$offsetId,'debitCents'=>$abs,'creditCents'=>0],['accountId'=>$controlId,'debitCents'=>0,'creditCents'=>$abs]]
            : [['accountId'=>$controlId,'debitCents'=>$abs,'creditCents'=>0],['accountId'=>$offsetId,'debitCents'=>0,'creditCents'=>$abs]];
        $prefix='APOB';$module='AP';$sourceType='vendor_opening_balance';
    }
    $id = new_id('partyopen');
    $memo = ucfirst($partyType).' opening balance · '.$partyName;
    $entryId = add_journal_entry($user,$companyId,$date,$sourceType,$id,$memo,$lines);
    $voucherId = function_exists('voucher_register_saved')
        ? voucher_register_saved($user,$companyId,$prefix,$module,$sourceType,$id,$date,$memo,$abs,$entryId,true)
        : '';
    db()->prepare('INSERT INTO party_opening_balances (id,company_id,party_type,party_id,effective_date,amount_cents,offset_account_id,journal_entry_id,voucher_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$companyId,$partyType,$partyId,$date,$amountCents,$offsetId,$entryId,$voucherId!==''?$voucherId:null,$user['id']]);
    audit_event($user,$companyId,$sourceType.'.posted',$partyType,$partyId,[
        'openingBalanceId'=>$id,'amountCents'=>$amountCents,'effectiveDate'=>$date,'journalEntryId'=>$entryId,
        'voucherId'=>$voucherId!==''?$voucherId:null,'initiatedVia'=>$source,
    ]);
    return ['id'=>$id,'amountCents'=>$amountCents,'effectiveDate'=>$date,'journalEntryId'=>$entryId,'voucherId'=>$voucherId!==''?$voucherId:null];
}

function tegh_customer_hold_state(array $user, array $company, string $customerId, string $status, ?string $remarks, ?string $releaseRemarks = null): array
{
    require_company_permission($company,'customers.write');
    $companyId=(string)$company['id'];
    $status=tegh_party_status($status,'Customer status');
    $stmt=db()->prepare('SELECT id,name,status,hold_remarks FROM customers WHERE id=? AND company_id=? LIMIT 1');
    $stmt->execute([$customerId,$companyId]);$customer=$stmt->fetch();
    if(!$customer)fail('Customer not found.',404,'customer_not_found');
    $old=(string)($customer['status']??'active');
    if($status==='on_hold'){
        $remarks=optional_text($remarks,1000);
        if($remarks===null)fail('Enter Hold Remarks before placing the customer on hold.',422,'hold_remarks_required');
        db()->prepare("UPDATE customers SET status='on_hold',active=1,hold_remarks=?,hold_at=UTC_TIMESTAMP(),hold_by=?,release_remarks=NULL,released_at=NULL,released_by=NULL WHERE id=? AND company_id=?")
            ->execute([$remarks,$user['id'],$customerId,$companyId]);
        audit_event($user,$companyId,'customer.hold_placed','customer',$customerId,['previousStatus'=>$old,'remarks'=>$remarks]);
    } elseif($status==='active'){
        $releaseRemarks=optional_text($releaseRemarks,1000);
        db()->prepare("UPDATE customers SET status='active',active=1,release_remarks=?,released_at=CASE WHEN status='on_hold' THEN UTC_TIMESTAMP() ELSE released_at END,released_by=CASE WHEN status='on_hold' THEN ? ELSE released_by END WHERE id=? AND company_id=?")
            ->execute([$releaseRemarks,$user['id'],$customerId,$companyId]);
        if($old==='on_hold')audit_event($user,$companyId,'customer.hold_released','customer',$customerId,['previousStatus'=>$old,'remarks'=>$releaseRemarks]);
    } else {
        db()->prepare("UPDATE customers SET status='inactive',active=0 WHERE id=? AND company_id=?")->execute([$customerId,$companyId]);
        audit_event($user,$companyId,'customer.inactivated','customer',$customerId,['previousStatus'=>$old]);
    }
    return ['id'=>$customerId,'status'=>$status];
}

function assert_customer_may_issue_invoice(string $companyId, string $customerId): void
{
    $stmt=db()->prepare('SELECT name,status,hold_remarks,active FROM customers WHERE id=? AND company_id=? LIMIT 1');
    $stmt->execute([$customerId,$companyId]);$customer=$stmt->fetch();
    if(!$customer || !(bool)$customer['active'] || (string)($customer['status']??'active')==='inactive')fail('Choose an active customer.',409,'customer_inactive');
    if((string)($customer['status']??'active')==='on_hold'){
        $reason=trim((string)($customer['hold_remarks']??''));
        fail('Customer Is On Hold. This invoice cannot be posted while the customer is on hold.'.($reason!==''?' Hold Reason: '.$reason:''),409,'customer_on_hold');
    }
}
