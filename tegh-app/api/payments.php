<?php
declare(strict_types=1);

/**
 * Customer receipts and vendor payments are source records in their own
 * right. They may later be matched to an imported bank line without posting
 * the income, expense, receivable, or payable a second time.
 */
function handle_party_payments(): never
{
    require_method('GET', 'POST', 'PATCH', 'DELETE');
    $user = require_user();
    $company = require_company($user);
    $companyId = (string)$company['id'];
    tegh_schema45_require();

    if (request_method() === 'GET') {
        $type = strtolower(trim((string)($_GET['type'] ?? '')));
        if ($type !== '' && !in_array($type, ['customer','vendor'], true)) fail('Payment type is invalid.');
        $unmatched = (string)($_GET['unmatched'] ?? '') === '1';
        $where = ['pp.company_id=?'];
        $params = [$companyId];
        if ($type !== '') {
            $where[] = 'pp.payment_type=?';
            $params[] = $type;
        }
        if ($unmatched) {
            $where[] = "pp.status='posted'";
            $where[] = 'pp.bank_transaction_id IS NULL';
        }
        $stmt = db()->prepare("SELECT pp.*,a.code AS payment_account_code,a.name AS payment_account_name,
            COALESCE((SELECT SUM(pa.payment_carrying_cents) FROM party_payment_applications pa WHERE pa.company_id=pp.company_id AND pa.payment_id=pp.id AND pa.status='posted'),0) AS active_applied_cents,
            COALESCE((SELECT SUM(pa.foreign_amount_cents) FROM party_payment_applications pa WHERE pa.company_id=pp.company_id AND pa.payment_id=pp.id AND pa.status='posted'),0) AS active_foreign_applied_cents,
            (SELECT COUNT(*) FROM party_payment_applications pa WHERE pa.company_id=pp.company_id AND pa.payment_id=pp.id AND pa.status='posted') AS active_application_count,
            CASE WHEN pp.payment_type='customer' THEN c.name ELSE v.name END AS party_name,
            CASE WHEN pp.payment_type='customer' THEN i.number ELSE b.number END AS document_number,
            vx.voucher_number
          FROM party_payments pp
          JOIN accounts a ON a.id=pp.payment_account_id
          LEFT JOIN customers c ON pp.payment_type='customer' AND c.id=pp.party_id
          LEFT JOIN invoices i ON pp.payment_type='customer' AND i.id=pp.document_id
          LEFT JOIN vendors v ON pp.payment_type='vendor' AND v.id=pp.party_id
          LEFT JOIN bills b ON pp.payment_type='vendor' AND b.id=pp.document_id
          LEFT JOIN vouchers vx ON vx.company_id=pp.company_id
            AND vx.source_type=CONCAT(pp.payment_type,'_payment') AND vx.source_id=pp.id
          WHERE " . implode(' AND ', $where) . '
          ORDER BY pp.payment_date DESC,pp.created_at DESC LIMIT 1000');
        $stmt->execute($params);
        $payments = array_map(static fn(array $row): array => payment_response_row($row), $stmt->fetchAll());

        $documents = [];
        if ($type !== 'vendor') {
            $stmt = db()->prepare("SELECT i.id,i.customer_id AS party_id,c.name AS party_name,i.number,i.issue_date AS document_date,
                i.due_date,i.currency,i.exchange_rate_micros,i.balance_cents,i.foreign_balance_cents
              FROM invoices i JOIN customers c ON c.id=i.customer_id
              WHERE i.company_id=? AND i.status='sent' AND i.balance_cents>0 AND i.foreign_balance_cents>0
              ORDER BY i.due_date,i.number");
            $stmt->execute([$companyId]);
            foreach ($stmt->fetchAll() as $row) $documents[] = payment_document_row('customer', $row);
        }
        if ($type !== 'customer') {
            $stmt = db()->prepare("SELECT b.id,b.vendor_id AS party_id,v.name AS party_name,b.number,b.bill_date AS document_date,
                b.due_date,b.currency,b.exchange_rate_micros,b.balance_cents,b.foreign_balance_cents
              FROM bills b JOIN vendors v ON v.id=b.vendor_id
              WHERE b.company_id=? AND b.status='open' AND b.balance_cents>0 AND b.foreign_balance_cents>0
              ORDER BY b.due_date,b.number");
            $stmt->execute([$companyId]);
            foreach ($stmt->fetchAll() as $row) $documents[] = payment_document_row('vendor', $row);
        }

        $stmt = db()->prepare("SELECT a.id,a.code,a.name,a.account_type,ba.id AS bank_account_id,ba.currency
          FROM accounts a
          LEFT JOIN bank_accounts ba ON ba.company_id=a.company_id AND ba.ledger_account_id=a.id AND ba.active=1
          WHERE a.company_id=? AND a.active=1 AND (ba.id IS NOT NULL OR a.code='1050')
          ORDER BY CASE WHEN a.code='1050' THEN 1 ELSE 0 END,a.code");
        $stmt->execute([$companyId]);
        $accounts = array_map(static fn(array $row): array => [
            'id'=>(string)$row['id'],'code'=>(string)$row['code'],'name'=>(string)$row['name'],
            'bankAccountId'=>$row['bank_account_id'],'currency'=>(string)($row['currency'] ?: $company['currency']),
            'undeposited'=>(string)$row['code']==='1050',
        ], $stmt->fetchAll());
        if ($type === 'vendor') {
            $stmt = db()->prepare('SELECT id,name,default_currency AS currency FROM vendors WHERE company_id=? AND active=1 ORDER BY name');
        } else {
            $stmt = db()->prepare('SELECT id,name,? AS currency FROM customers WHERE company_id=? AND active=1 ORDER BY name');
        }
        $stmt->execute($type === 'vendor' ? [$companyId] : [(string)$company['currency'],$companyId]);
        $parties = array_map(static fn(array $row): array => ['id'=>(string)$row['id'],'name'=>(string)$row['name'],'currency'=>(string)$row['currency']], $stmt->fetchAll());
        $applicationWhere=$type!==''?' AND pp.payment_type=?':'';$applicationParams=$type!==''?[$companyId,$type]:[$companyId];
        $stmt=db()->prepare("SELECT pa.id,pa.payment_id,pa.document_type,pa.document_id,pa.application_date,pa.foreign_amount_cents,pa.payment_carrying_cents,pa.document_carrying_cents,pa.recognition_journal_entry_id,pa.status,pa.reversal_of_id,pa.created_at,pp.payment_type,CASE WHEN pa.document_type='invoice' THEN i.number ELSE b.number END document_number FROM party_payment_applications pa JOIN party_payments pp ON pp.id=pa.payment_id AND pp.company_id=pa.company_id LEFT JOIN invoices i ON pa.document_type='invoice' AND i.id=pa.document_id AND i.company_id=pa.company_id LEFT JOIN bills b ON pa.document_type='bill' AND b.id=pa.document_id AND b.company_id=pa.company_id WHERE pa.company_id=?$applicationWhere ORDER BY pa.application_date DESC,pa.created_at DESC,pa.id");
        $stmt->execute($applicationParams);$applications=array_map(static fn(array $row):array=>[
            'id'=>(string)$row['id'],'paymentId'=>(string)$row['payment_id'],'documentType'=>(string)$row['document_type'],'documentId'=>(string)$row['document_id'],'documentNumber'=>(string)($row['document_number']??''),'applicationDate'=>(string)$row['application_date'],'foreignAmountCents'=>(int)$row['foreign_amount_cents'],'paymentCarryingCents'=>(int)$row['payment_carrying_cents'],'documentCarryingCents'=>(int)$row['document_carrying_cents'],'recognitionJournalEntryId'=>$row['recognition_journal_entry_id']!==null?(string)$row['recognition_journal_entry_id']:null,'status'=>(string)$row['status'],'reversalOfId'=>$row['reversal_of_id']!==null?(string)$row['reversal_of_id']:null,'createdAt'=>(string)$row['created_at']],$stmt->fetchAll(PDO::FETCH_ASSOC));
        json_response(['payments'=>$payments,'applications'=>$applications,'openDocuments'=>$documents,'paymentAccounts'=>$accounts,'parties'=>$parties]);
    }

    require_csrf();
    if (request_method() === 'DELETE') require_company_role($company, 'owner');
    else require_company_role($company, 'owner', 'bookkeeper');
    $input = request_json();
    if (request_method() === 'PATCH') handle_payment_applications_v5990($user, $company, $input);
    if (request_method() === 'DELETE' || (string)($input['action'] ?? '') === 'reverse') {
        reverse_party_payment($user, $company, $input);
    }
    post_party_payment($user, $company, $input);
}

function payment_document_row(string $type, array $row): array
{
    return [
        'type'=>$type,'id'=>(string)$row['id'],'partyId'=>(string)$row['party_id'],
        'partyName'=>(string)$row['party_name'],'number'=>(string)$row['number'],
        'documentDate'=>(string)$row['document_date'],'dueDate'=>(string)$row['due_date'],
        'currency'=>(string)$row['currency'],'exchangeRateMicros'=>(int)$row['exchange_rate_micros'],
        'balanceCents'=>(int)$row['balance_cents'],'foreignBalanceCents'=>(int)$row['foreign_balance_cents'],
    ];
}

function payment_response_row(array $row): array
{
    $applicationCount=(int)($row['active_application_count']??0);
    $applied=$applicationCount>0?(int)$row['active_applied_cents']:(($row['document_id']??null)!==null?(int)$row['applied_cents']:0);
    $foreignApplied=$applicationCount>0?(int)$row['active_foreign_applied_cents']:(($row['document_id']??null)!==null?(int)$row['foreign_amount_cents']:0);
    $flowKind=(string)($row['flow_kind']??((string)$row['payment_type']==='vendor'?'vendor_payment':'customer_receipt'));
    $allocatable=in_array($flowKind,['customer_receipt','vendor_payment'],true);
    return [
        'id'=>(string)$row['id'],'type'=>(string)$row['payment_type'],'flowKind'=>$flowKind,'partyId'=>(string)$row['party_id'],
        'partyName'=>(string)($row['party_name'] ?? ''),'documentId'=>$row['document_id'] !== null ? (string)$row['document_id'] : null,
        'documentNumber'=>$row['document_id'] !== null ? (string)($row['document_number'] ?? '') : ($allocatable?'Open payment':'On-account refund'),'unapplied'=>$allocatable&&max(0,(int)$row['amount_cents']-$applied)>0,'date'=>(string)$row['payment_date'],
        'reference'=>(string)$row['reference'],'memo'=>(string)$row['memo'],
        'amountCents'=>(int)$row['amount_cents'],'appliedCents'=>$applied,'remainingCents'=>$allocatable?max(0,(int)$row['amount_cents']-$applied):0,
        'currency'=>(string)$row['currency'],'foreignAmountCents'=>(int)$row['foreign_amount_cents'],
        'foreignAppliedCents'=>$foreignApplied,'foreignRemainingCents'=>$allocatable?max(0,(int)$row['foreign_amount_cents']-$foreignApplied):0,'applicationCount'=>$applicationCount,
        'exchangeRateMicros'=>(int)$row['exchange_rate_micros'],'paymentAccountId'=>(string)$row['payment_account_id'],
        'paymentAccount'=>(string)($row['payment_account_code'] ?? '') . ' · ' . (string)($row['payment_account_name'] ?? ''),
        'journalEntryId'=>$row['journal_entry_id'],'transactionNumber'=>(string)($row['voucher_number'] ?? ''),'bankTransactionId'=>$row['bank_transaction_id'],
        'matched'=>$row['bank_transaction_id'] !== null,'status'=>(string)$row['status'],
        'createdAt'=>(string)$row['created_at'],
    ];
}

function payment_application_payload_hash(string $companyId,string $userId,string $applicationDate,array $allocations): string
{
    $normalized=[];foreach($allocations as $row)$normalized[]=['paymentId'=>(string)$row['paymentId'],'documentId'=>(string)$row['documentId'],'foreignAmountCents'=>(int)$row['foreignAmountCents']];
    return hash('sha256',json_encode(['companyId'=>$companyId,'userId'=>$userId,'applicationDate'=>$applicationDate,'allocations'=>$normalized],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

function payment_application_operation_begin(array $user,string $companyId,string $operationKey,string $payloadHash): array
{
    db()->prepare("INSERT IGNORE INTO party_payment_application_operations(id,company_id,user_id,operation_key,payload_hash,status) VALUES(?,?,?,?,?,'active')")
        ->execute([new_id('payapplyop'),$companyId,(string)$user['id'],$operationKey,$payloadHash]);
    $q=db()->prepare('SELECT * FROM party_payment_application_operations WHERE company_id=? AND user_id=? AND operation_key=? FOR UPDATE');$q->execute([$companyId,(string)$user['id'],$operationKey]);$operation=$q->fetch();
    if(!$operation)throw new RuntimeException('The payment application operation could not be locked.');
    if(!hash_equals((string)$operation['payload_hash'],$payloadHash))fail('This operation key was already used for a different payment application.',409,'payment_application_operation_conflict');
    if((string)$operation['status']==='completed')return ['replay'=>true,'operation'=>$operation,'result'=>json_decode((string)$operation['result_json'],true,512,JSON_THROW_ON_ERROR)];
    return ['replay'=>false,'operation'=>$operation];
}

function payment_application_register(
    array $user,string $companyId,array $payment,array $document,string $applicationDate,
    int $foreignAmount,int $paymentCarrying,int $documentCarrying,string $operationId,?string $recognitionJournalId=null
): string {
    $type=(string)$payment['payment_type'];$applicationId=new_id('payapply');
    db()->prepare("INSERT INTO party_payment_applications
      (id,company_id,payment_id,document_type,document_id,partner_type,partner_id,application_date,foreign_amount_cents,payment_carrying_cents,document_carrying_cents,recognition_journal_entry_id,status,operation_id,created_by)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'posted',?,?)")
      ->execute([$applicationId,$companyId,$payment['id'],$type==='customer'?'invoice':'bill',$document['id'],$type,$payment['party_id'],$applicationDate,$foreignAmount,$paymentCarrying,$documentCarrying,$recognitionJournalId,$operationId,$user['id']]);
    return $applicationId;
}

function payment_apply_service(array $user,array $company,array $input,bool $manageTransaction=true): array
{
    tegh_schema45_require();$companyId=(string)$company['id'];
    $date=safe_date($input['applicationDate']??canadian_today(),'Application date');assert_not_future_date($date,'Application date');assert_period_open($companyId,$date);
    $operationKey=clean_text($input['operationKey']??'','Operation key',120);
    $allocations=$input['allocations']??null;
    if(!is_array($allocations))$allocations=[['paymentId'=>$input['paymentId']??'','documentId'=>$input['documentId']??'','foreignAmountCents'=>$input['foreignAmountCents']??0]];
    if(count($allocations)<1||count($allocations)>100)fail('Choose between 1 and 100 payment applications.');
    $prepared=[];$seen=[];
    foreach($allocations as $row){
        if(!is_array($row))fail('A payment application is invalid.');$paymentId=clean_text($row['paymentId']??'','Payment',64);$documentId=clean_text($row['documentId']??'','Invoice or bill',64);$amount=safe_cents($row['foreignAmountCents']??0,'Application amount');if($amount<=0)fail('Application amount must be positive.');
        $key=$paymentId.'|'.$documentId;if(isset($seen[$key]))fail('The same payment and document were selected more than once.');$seen[$key]=true;$prepared[]=['paymentId'=>$paymentId,'documentId'=>$documentId,'foreignAmountCents'=>$amount];
    }
    usort($prepared,static fn(array $left,array $right):int=>[$left['paymentId'],$left['documentId']]<=>[$right['paymentId'],$right['documentId']]);
    $hash=payment_application_payload_hash($companyId,(string)$user['id'],$date,$prepared);
    if($manageTransaction)db()->beginTransaction();
    try{
        $company=tegh_bank_reauthorize_mutation($user,$company,'');$operation=payment_application_operation_begin($user,$companyId,$operationKey,$hash);if($operation['replay']){if($manageTransaction)db()->commit();return $operation['result'];}
        $paymentIds=array_values(array_unique(array_column($prepared,'paymentId')));sort($paymentIds,SORT_STRING);$payments=[];
        foreach($paymentIds as $paymentId){$q=db()->prepare("SELECT * FROM party_payments WHERE id=? AND company_id=? AND status='posted' FOR UPDATE");$q->execute([$paymentId,$companyId]);$payment=$q->fetch();if(!$payment)fail('A selected payment is no longer available.',409,'payment_application_payment_unavailable');$payments[$paymentId]=$payment;}
        $results=[];
        foreach($prepared as $row){
            $payment=$payments[$row['paymentId']];$type=(string)$payment['payment_type'];$permission=$type==='customer'?'invoices.write':'bills.write';require_company_permission($company,$permission);
            $table=$type==='customer'?'invoices':'bills';$partyColumn=$type==='customer'?'customer_id':'vendor_id';$openStatus=$type==='customer'?'sent':'open';$dateColumn=$type==='customer'?'issue_date':'bill_date';
            $q=db()->prepare("SELECT * FROM `$table` WHERE id=? AND company_id=? AND `$partyColumn`=? AND status=? FOR UPDATE");$q->execute([$row['documentId'],$companyId,$payment['party_id'],$openStatus]);$document=$q->fetch();
            if(!$document)fail('Choose an open document for the same customer or vendor.',409,'payment_application_document_unavailable');
            if((string)$document['currency']!==(string)$payment['currency'])fail('The payment and document currencies must match.',409,'payment_application_currency_mismatch');
            if($date<(string)$payment['payment_date']||$date<(string)$document[$dateColumn])fail('Application date cannot be before the payment or document date.');
            $usedQuery=db()->prepare("SELECT foreign_amount_cents,payment_carrying_cents FROM party_payment_applications WHERE company_id=? AND payment_id=? AND status='posted' ORDER BY id FOR UPDATE");$usedQuery->execute([$companyId,$payment['id']]);$foreignApplied=0;$carryingApplied=0;foreach($usedQuery->fetchAll() as $application){$foreignApplied+=(int)$application['foreign_amount_cents'];$carryingApplied+=(int)$application['payment_carrying_cents'];}
            $foreignRemaining=(int)$payment['foreign_amount_cents']-$foreignApplied;$carryingRemaining=(int)$payment['amount_cents']-$carryingApplied;$foreign=(int)$row['foreignAmountCents'];
            if($foreign>$foreignRemaining||$foreign>(int)$document['foreign_balance_cents'])fail('The application exceeds the remaining payment or document balance.',409,'payment_application_overapplied');
            $paymentCarrying=$foreign===$foreignRemaining?$carryingRemaining:min($carryingRemaining,convert_to_base_cents($foreign,(int)$payment['exchange_rate_micros']));
            $documentCarrying=$foreign===(int)$document['foreign_balance_cents']?(int)$document['balance_cents']:min((int)$document['balance_cents'],convert_to_base_cents($foreign,(int)$document['exchange_rate_micros']));
            $recognitionJournalId=null;$recognitionLines=[];
            if(!empty($document['is_opening_document'])||(string)$company['accounting_basis']==='accrual'){
                $difference=$paymentCarrying-$documentCarrying;
                if($type==='customer'&&$difference>0)$recognitionLines=[['accountId'=>account_by_code($companyId,'1200'),'debitCents'=>$difference,'creditCents'=>0,'memo'=>'Apply customer payment'],['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>0,'creditCents'=>$difference,'memo'=>'Realized exchange difference']];
                if($type==='customer'&&$difference<0)$recognitionLines=[['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>abs($difference),'creditCents'=>0,'memo'=>'Realized exchange difference'],['accountId'=>account_by_code($companyId,'1200'),'debitCents'=>0,'creditCents'=>abs($difference),'memo'=>'Apply customer payment']];
                if($type==='vendor'&&$difference>0)$recognitionLines=[['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>$difference,'creditCents'=>0,'memo'=>'Realized exchange difference'],['accountId'=>account_by_code($companyId,'2050'),'debitCents'=>0,'creditCents'=>$difference,'memo'=>'Apply vendor payment']];
                if($type==='vendor'&&$difference<0)$recognitionLines=[['accountId'=>account_by_code($companyId,'2050'),'debitCents'=>abs($difference),'creditCents'=>0,'memo'=>'Apply vendor payment'],['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>0,'creditCents'=>abs($difference),'memo'=>'Realized exchange difference']];
            }elseif($type==='customer'){
                $tax=(int)round(($paymentCarrying*(int)$document['foreign_tax_cents'])/max(1,(int)$document['foreign_total_cents']));$recognitionLines=[['accountId'=>account_by_code($companyId,'1200'),'debitCents'=>$paymentCarrying,'creditCents'=>0,'memo'=>'Apply customer payment'],['accountId'=>account_by_code($companyId,'4000'),'debitCents'=>0,'creditCents'=>$paymentCarrying-$tax,'memo'=>(string)$document['number']]];[$gstPart,$pstPart]=invoice_tax_parts($document,$tax);foreach(invoice_tax_credit_lines($companyId,$gstPart,$pstPart) as $taxLine)$recognitionLines[]=$taxLine;
            }else{
                $foreignTotal=max(1,(int)$document['foreign_total_cents']);$gst=(int)round(($paymentCarrying*(int)($document['foreign_gst_hst_cents']??$document['foreign_tax_cents']??0))/$foreignTotal);$pst=(int)round(($paymentCarrying*(int)($document['foreign_pst_cents']??0))/$foreignTotal);$recoverablePst=!empty($company['pst_recoverable'])?$pst:0;$cost=$paymentCarrying-$gst-$recoverablePst;if($cost<0)fail('Vendor payment tax allocation exceeds the applied amount.',409,'vendor_payment_tax_allocation_invalid');$recognitionLines=[['accountId'=>(string)$document['category_account_id'],'debitCents'=>$cost,'creditCents'=>0,'memo'=>(string)$document['number']]];if($gst>0)$recognitionLines[]=['accountId'=>account_by_code($companyId,'1100'),'debitCents'=>$gst,'creditCents'=>0,'memo'=>'GST/HST recoverable'];if($recoverablePst>0)$recognitionLines[]=['accountId'=>account_by_code($companyId,'1110'),'debitCents'=>$recoverablePst,'creditCents'=>0,'memo'=>'PST recoverable'];$recognitionLines[]=['accountId'=>account_by_code($companyId,'2050'),'debitCents'=>0,'creditCents'=>$paymentCarrying,'memo'=>'Apply vendor payment'];
            }
            if($recognitionLines)$recognitionJournalId=add_journal_entry($user,$companyId,$date,$type.'_payment_application',(string)$payment['id'],'Apply payment to '.(string)$document['number'],$recognitionLines);
            $newBalance=(int)$document['balance_cents']-$documentCarrying;$update=db()->prepare("UPDATE `$table` SET balance_cents=?,foreign_balance_cents=foreign_balance_cents-?,status=CASE WHEN ?=0 THEN 'paid' ELSE ? END WHERE id=? AND company_id=? AND status=? AND balance_cents>=? AND foreign_balance_cents>=?");
            $update->execute([$newBalance,$foreign,$newBalance,$openStatus,$document['id'],$companyId,$openStatus,$documentCarrying,$foreign]);if($update->rowCount()!==1)fail('The document changed before the payment was applied.',409,'payment_application_balance_conflict');
            $applicationId=payment_application_register($user,$companyId,$payment,$document,$date,$foreign,$paymentCarrying,$documentCarrying,(string)$operation['operation']['id'],$recognitionJournalId);
            db()->prepare('UPDATE party_payments SET applied_cents=(SELECT COALESCE(SUM(payment_carrying_cents),0) FROM party_payment_applications WHERE company_id=? AND payment_id=? AND status=\'posted\') WHERE id=? AND company_id=?')->execute([$companyId,$payment['id'],$payment['id'],$companyId]);
            $results[]=['applicationId'=>$applicationId,'paymentId'=>(string)$payment['id'],'documentId'=>(string)$document['id'],'foreignAmountCents'=>$foreign,'paymentCarryingCents'=>$paymentCarrying,'documentCarryingCents'=>$documentCarrying,'recognitionJournalEntryId'=>$recognitionJournalId];
        }
        $result=['operationId'=>(string)$operation['operation']['id'],'status'=>'completed','applications'=>$results,'accountingEntriesCreated'=>count(array_filter(array_column($results,'recognitionJournalEntryId')))];$json=json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        db()->prepare("UPDATE party_payment_application_operations SET status='completed',result_json=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='active'")->execute([$json,$operation['operation']['id']]);
        audit_event($user,$companyId,'payment.applications_posted','payment_application_operation',(string)$operation['operation']['id'],['applicationCount'=>count($results),'payloadHash'=>$hash,'accountingEntriesCreated'=>$result['accountingEntriesCreated']]);
        if($manageTransaction)db()->commit();return $result;
    }catch(Throwable $error){if($manageTransaction&&db()->inTransaction())db()->rollBack();throw $error;}
}

function handle_payment_applications_v5990(array $user,array $company,array $input): never
{
    $action=strtolower(trim((string)($input['action']??'apply')));
    if($action==='unapply')json_response(['applicationResult'=>payment_unapply_service($user,$company,$input,true)]);
    if($action!=='apply')fail('The payment application action is invalid.',422,'payment_application_action_invalid');
    json_response(['applicationResult'=>payment_apply_service($user,$company,$input,true)]);
}

function payment_unapply_service(array $user,array $company,array $input,bool $manageTransaction=true): array
{
    tegh_schema45_require();$companyId=(string)$company['id'];
    $applicationId=clean_text($input['applicationId']??'','Payment application',64);
    $date=safe_date($input['reversalDate']??canadian_today(),'Unapply date');assert_not_future_date($date,'Unapply date');assert_period_open($companyId,$date);
    $operationKey=clean_text($input['operationKey']??'','Operation key',120);
    $hash=hash('sha256',tegh_json_canonical(['companyId'=>$companyId,'userId'=>(string)$user['id'],'action'=>'unapply','applicationId'=>$applicationId,'reversalDate'=>$date]));
    if($manageTransaction)db()->beginTransaction();
    try{
        $company=tegh_bank_reauthorize_mutation($user,$company,'');
        $operation=payment_application_operation_begin($user,$companyId,$operationKey,$hash);if($operation['replay']){if($manageTransaction)db()->commit();return $operation['result'];}
        $q=db()->prepare("SELECT pa.*,pp.payment_type,pp.party_id,pp.status payment_status FROM party_payment_applications pa JOIN party_payments pp ON pp.id=pa.payment_id AND pp.company_id=pa.company_id WHERE pa.company_id=? AND pa.id=? FOR UPDATE");$q->execute([$companyId,$applicationId]);$application=$q->fetch(PDO::FETCH_ASSOC);
        if(!$application||(string)$application['status']!=='posted'||(string)$application['payment_status']!=='posted')fail('This payment application is no longer available to unapply.',409,'payment_application_unavailable');
        $type=(string)$application['payment_type'];require_company_permission($company,$type==='customer'?'invoices.write':'bills.write');
        if($date<(string)$application['application_date'])fail('The unapply date cannot precede the application date.',422,'payment_unapply_date_invalid');
        $table=$type==='customer'?'invoices':'bills';$openStatus=$type==='customer'?'sent':'open';
        $q=db()->prepare("SELECT * FROM `$table` WHERE company_id=? AND id=? FOR UPDATE");$q->execute([$companyId,(string)$application['document_id']]);$document=$q->fetch(PDO::FETCH_ASSOC);
        if(!$document)fail('The linked document is unavailable.',409,'payment_application_document_unavailable');
        $recognitionReversalId=null;
        if($application['recognition_journal_entry_id']!==null)$recognitionReversalId=add_reversing_journal_entry($user,$companyId,(string)$application['recognition_journal_entry_id'],$date,$type.'_payment_application_reversal',$applicationId,'Unapply payment allocation');
        $restoreBase=(int)$application['document_carrying_cents'];$restoreForeign=(int)$application['foreign_amount_cents'];
        db()->prepare("UPDATE `$table` SET balance_cents=LEAST(total_cents,balance_cents+?),foreign_balance_cents=LEAST(foreign_total_cents,foreign_balance_cents+?),status=? WHERE company_id=? AND id=?")
            ->execute([$restoreBase,$restoreForeign,$openStatus,$companyId,(string)$document['id']]);
        db()->prepare("UPDATE party_payment_applications SET status='reversed',reversed_by=?,reversed_at=UTC_TIMESTAMP() WHERE company_id=? AND id=? AND status='posted'")
            ->execute([(string)$user['id'],$companyId,$applicationId]);
        $reversalId=new_id('payapply');
        db()->prepare("INSERT INTO party_payment_applications(id,company_id,payment_id,document_type,document_id,partner_type,partner_id,application_date,foreign_amount_cents,payment_carrying_cents,document_carrying_cents,recognition_journal_entry_id,status,reversal_of_id,operation_id,created_by,reversed_by,reversed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'reversed',?,?,?,?,?,UTC_TIMESTAMP())")
            ->execute([$reversalId,$companyId,(string)$application['payment_id'],(string)$application['document_type'],(string)$application['document_id'],(string)$application['partner_type'],(string)$application['partner_id'],$date,$restoreForeign,(int)$application['payment_carrying_cents'],$restoreBase,$recognitionReversalId,$applicationId,(string)$operation['operation']['id'],(string)$user['id'],(string)$user['id']]);
        db()->prepare("UPDATE party_payments SET applied_cents=(SELECT COALESCE(SUM(payment_carrying_cents),0) FROM party_payment_applications WHERE company_id=? AND payment_id=? AND status='posted') WHERE company_id=? AND id=?")
            ->execute([$companyId,(string)$application['payment_id'],$companyId,(string)$application['payment_id']]);
        $result=['operationId'=>(string)$operation['operation']['id'],'status'=>'completed','action'=>'unapply','applicationId'=>$applicationId,'reversalApplicationId'=>$reversalId,'paymentId'=>(string)$application['payment_id'],'documentId'=>(string)$application['document_id'],'foreignAmountCents'=>$restoreForeign,'documentCarryingCents'=>$restoreBase,'recognitionReversalJournalEntryId'=>$recognitionReversalId,'accountingEntriesCreated'=>$recognitionReversalId?1:0];
        db()->prepare("UPDATE party_payment_application_operations SET status='completed',result_json=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='active'")->execute([json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(string)$operation['operation']['id']]);
        audit_event($user,$companyId,'payment.application_unapplied','payment_application',$applicationId,$result);if($manageTransaction)db()->commit();return $result;
    }catch(Throwable $error){if($manageTransaction&&db()->inTransaction())db()->rollBack();throw $error;}
}

function party_payment_invariant_values(int $debits,int $credits,int $priorBalance,int $currentBalance,int $appliedCents): bool
{
    return $debits===$credits&&$priorBalance-$currentBalance===$appliedCents;
}

function assert_party_payment_posting_invariants(string $companyId,string $paymentId,?int $priorDocumentBalance): void
{
    $stmt=db()->prepare("SELECT pp.payment_type,pp.document_id,pp.applied_cents,pp.journal_entry_id,COALESCE(SUM(jl.debit_cents),0) debits,COALESCE(SUM(jl.credit_cents),0) credits FROM party_payments pp LEFT JOIN journal_lines jl ON jl.journal_entry_id=pp.journal_entry_id WHERE pp.id=? AND pp.company_id=? GROUP BY pp.id,pp.payment_type,pp.document_id,pp.applied_cents,pp.journal_entry_id");
    $stmt->execute([$paymentId,$companyId]);$payment=$stmt->fetch();
    if(!$payment||(int)$payment['debits']!==(int)$payment['credits'])throw new RuntimeException('The payment journal failed its double-entry invariant.');
    if($payment['document_id']===null||$priorDocumentBalance===null)return;
    $table=(string)$payment['payment_type']==='customer'?'invoices':'bills';
    $balance=db()->prepare("SELECT balance_cents FROM `$table` WHERE id=? AND company_id=?");$balance->execute([$payment['document_id'],$companyId]);$current=$balance->fetchColumn();
    if($current===false||!party_payment_invariant_values((int)$payment['debits'],(int)$payment['credits'],$priorDocumentBalance,(int)$current,(int)$payment['applied_cents']))throw new RuntimeException('The payment amount does not equal the document balance reduction.');
}

function post_party_refund(array $user,array $company,array $input,string $flow): never
{
    $companyId=(string)$company['id'];$type=$flow==='customer_refund'?'customer':'vendor';require_company_permission($company,$type==='customer'?'invoices.write':'bills.write');
    $partyId=clean_text($input['partyId']??'',$type==='customer'?'Customer':'Vendor',64);$date=safe_date($input['paymentDate']??'','Refund date');assert_not_future_date($date,'Refund date');assert_period_open($companyId,$date);$reference=clean_text($input['reference']??'','Refund reference',120);$memo=optional_text($input['memo']??null,500)??'';$foreign=safe_cents($input['foreignAmountCents']??0,'Refund amount');if($foreign<=0)fail('Refund amount must be positive.');$accountId=clean_text($input['paymentAccountId']??'','Financial account',64);$operationKey=clean_text($input['operationKey']??'','Operation key',120);$noteId=optional_text($input['noteId']??null,64);
    $hash=hash('sha256',tegh_json_canonical(['companyId'=>$companyId,'userId'=>(string)$user['id'],'flowKind'=>$flow,'partyId'=>$partyId,'paymentDate'=>$date,'reference'=>$reference,'memo'=>$memo,'foreignAmountCents'=>$foreign,'currency'=>strtoupper((string)($input['currency']??'')),'exchangeRateMicros'=>$input['exchangeRateMicros']??null,'paymentAccountId'=>$accountId,'noteId'=>$noteId]));
    db()->beginTransaction();try{
        $company=tegh_bank_reauthorize_mutation($user,$company,'');$operation=payment_application_operation_begin($user,$companyId,$operationKey,$hash);if($operation['replay']){db()->commit();json_response(['payment'=>$operation['result']],200);}
        $partyTable=$type==='customer'?'customers':'vendors';$partySql=$type==='customer'?'SELECT id,name,NULL default_currency FROM customers WHERE id=? AND company_id=? AND active=1 FOR UPDATE':'SELECT id,name,default_currency FROM vendors WHERE id=? AND company_id=? AND active=1 FOR UPDATE';$q=db()->prepare($partySql);$q->execute([$partyId,$companyId]);$party=$q->fetch();if(!$party)fail($type==='customer'?'Choose an active customer.':'Choose an active vendor.',422,'partner_unavailable');
        $account=tegh_account_capability_resolve($companyId,$accountId,true);if((string)$account['kind']!=='financial_account'||empty($account['postingAllowed']))fail('Choose an active bank or credit account for the refund.',422,'refund_financial_account_required');
        $currency=safe_currency_code($input['currency']??($party['default_currency']?:$company['currency']),'Refund currency');if((string)$account['financialAccount']['currency']!==$currency)fail('The refund currency must match the selected financial account.',409,'refund_currency_mismatch');$currencyRow=company_currency($companyId,$currency);if(!$currencyRow)fail('The refund currency is not active for this company.');$rate=safe_exchange_rate_micros($input['exchangeRateMicros']??$currencyRow['rate_to_base_micros'],$currency,(string)$company['currency']);$amount=convert_to_base_cents($foreign,$rate);
        $linkedNote=null;
        if($noteId!==null){
            tegh_notes_r67_require();
            $n=db()->prepare("SELECT * FROM accounting_notes WHERE id=? AND company_id=? AND status='posted' FOR UPDATE");$n->execute([$noteId,$companyId]);$linkedNote=$n->fetch();
            if(!$linkedNote||!in_array((string)$linkedNote['note_kind'],$type==='customer'?['customer_credit']:['vendor_credit','vendor_debit'],true)
                ||(string)$linkedNote['party_id']!==$partyId||(string)$linkedNote['currency']!==$currency
                ||(int)$linkedNote['exchange_rate_micros']!==$rate||$date<(string)$linkedNote['note_date'])
                fail('This refund must use an open note for the same party, currency and exchange rate.',409,'note_refund_ineligible');
            require_company_permission($company,note_kind_spec((string)$linkedNote['note_kind'])['permission']);
            $remaining=note_remaining_amounts($companyId,$linkedNote);
            note_assert_settlement_date_order($companyId,$noteId,$date);
            if($foreign>$remaining['foreignRemainingCents']||$amount>$remaining['remainingCents']
                ||($foreign===$remaining['foreignRemainingCents']&&$amount!==$remaining['remainingCents']))
                fail('The refund exceeds the note credit or has a carrying-value difference.',409,'note_refund_balance_conflict');
        }
        $controlId=note_control_account_id($companyId,$type==='customer');
        $lines=$type==='customer'?[['accountId'=>$controlId,'debitCents'=>$amount,'creditCents'=>0,'memo'=>'Customer refund'],['accountId'=>$accountId,'debitCents'=>0,'creditCents'=>$amount,'memo'=>(string)$party['name']]]:[['accountId'=>$accountId,'debitCents'=>$amount,'creditCents'=>0,'memo'=>(string)$party['name']],['accountId'=>$controlId,'debitCents'=>0,'creditCents'=>$amount,'memo'=>'Vendor refund']];
        $paymentId=new_id('payment');$description=($type==='customer'?'Customer refund · ':'Vendor refund · ').(string)$party['name'].' · '.$reference;$sourceType=$type.'_payment';if(function_exists('voucher_register_saved'))voucher_register_saved($user,$companyId,$type==='customer'?'BR':'BP',$type==='customer'?'AR':'AP',$sourceType,$paymentId,$date,$description,$amount,null,false);$journalId=add_journal_entry($user,$companyId,$date,$sourceType,$paymentId,$description,$lines);
        db()->prepare("INSERT INTO party_payments(id,company_id,payment_type,flow_kind,party_id,document_id,payment_date,reference,memo,amount_cents,applied_cents,currency,foreign_amount_cents,exchange_rate_micros,payment_account_id,journal_entry_id,status,created_by) VALUES(?,?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,'posted',?)")->execute([$paymentId,$companyId,$type,$flow,$partyId,$date,$reference,$memo,$amount,0,$currency,$foreign,$rate,$accountId,$journalId,$user['id']]);if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,$sourceType,$paymentId,$journalId);
        if($linkedNote){
            $settlementId=new_id('noterefund');
            db()->prepare("INSERT INTO accounting_note_settlements(id,company_id,note_id,settlement_kind,payment_id,settlement_date,foreign_amount_cents,amount_cents,operation_key,created_by) VALUES(?,?,?,'refund',?,?,?,?,?,?)")
                ->execute([$settlementId,$companyId,$noteId,$paymentId,$date,$foreign,$amount,$operationKey,$user['id']]);
            audit_event($user,$companyId,'accounting_note.refunded','accounting_note',$noteId,['settlementId'=>$settlementId,'paymentId'=>$paymentId,'date'=>$date,'foreignAmountCents'=>$foreign,'amountCents'=>$amount]);
        }
        $voucherQ=db()->prepare('SELECT voucher_number FROM vouchers WHERE company_id=? AND source_type=? AND source_id=? ORDER BY serial_number LIMIT 1');$voucherQ->execute([$companyId,$sourceType,$paymentId]);$result=['id'=>$paymentId,'type'=>$type,'flowKind'=>$flow,'status'=>'posted','partyId'=>$partyId,'partyName'=>(string)$party['name'],'amountCents'=>$amount,'foreignAmountCents'=>$foreign,'remainingCents'=>0,'currency'=>$currency,'paymentAccountId'=>$accountId,'unapplied'=>false,'journalEntryId'=>$journalId,'noteId'=>$noteId,'publicVoucher'=>(string)($voucherQ->fetchColumn()?:'')];$json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);db()->prepare("UPDATE party_payment_application_operations SET status='completed',result_json=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='active'")->execute([$json,$operation['operation']['id']]);audit_event($user,$companyId,$type.'.refund_posted',$sourceType,$paymentId,['partyId'=>$partyId,'amountCents'=>$amount,'foreignAmountCents'=>$foreign,'currency'=>$currency,'journalEntryId'=>$journalId,'noteId'=>$noteId]);db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    json_response(['payment'=>$result],201);
}

function post_party_payment(array $user, array $company, array $input): never
{
    $companyId = (string)$company['id'];
    $type = strtolower(trim((string)($input['type'] ?? '')));
    if (!in_array($type, ['customer','vendor'], true)) fail('Choose a customer receipt or vendor payment.');
    $flow=strtolower(trim((string)($input['flowKind']??'')));if(in_array($flow,['customer_refund','vendor_refund'],true)){if(!str_starts_with($flow,$type.'_'))fail('The refund flow does not match the selected partner type.',422,'payment_flow_conflict');post_party_refund($user,$company,$input,$flow);}if($flow!==''&&!in_array($flow,['customer_receipt','vendor_payment'],true))fail('The payment flow is invalid.',422,'payment_flow_invalid');
    $documentId = optional_text($input['documentId'] ?? null, 64);
    $unapplied = $documentId === null;
    $paymentDate = safe_date($input['paymentDate'] ?? '', 'Payment date');
    assert_not_future_date($paymentDate, 'Payment date');
    $reference = clean_text($input['reference'] ?? '', 'Payment reference', 120);
    $memo = optional_text($input['memo'] ?? null, 500) ?? '';
    $foreignAmount = safe_cents($input['foreignAmountCents'] ?? 0, 'Payment amount');
    if ($foreignAmount <= 0) fail('Payment amount must be positive.');
    $paymentAccountId = clean_text($input['paymentAccountId'] ?? '', 'Payment account', 64);
    $operationKey=clean_text($input['operationKey']??'','Operation key',120);$operationHash=hash('sha256',tegh_json_canonical(['companyId'=>$companyId,'userId'=>(string)$user['id'],'type'=>$type,'flowKind'=>$flow?:($type==='customer'?'customer_receipt':'vendor_payment'),'documentId'=>$documentId,'partyId'=>$input['partyId']??null,'paymentDate'=>$paymentDate,'reference'=>$reference,'memo'=>$memo,'foreignAmountCents'=>$foreignAmount,'currency'=>$input['currency']??null,'exchangeRateMicros'=>$input['exchangeRateMicros']??null,'paymentAccountId'=>$paymentAccountId,'bankTransactionId'=>$input['bankTransactionId']??null]));

    db()->beginTransaction();
    try {
        $company=tegh_bank_reauthorize_mutation($user,$company,'');$operation=payment_application_operation_begin($user,$companyId,$operationKey,$operationHash);if($operation['replay']){db()->commit();json_response(['payment'=>$operation['result']],200);}
        if (!$unapplied && $type === 'customer') {
            $stmt = db()->prepare("SELECT i.*,c.name AS party_name FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=? AND i.company_id=? AND i.status='sent' FOR UPDATE");
            $stmt->execute([$documentId,$companyId]);
            $document = $stmt->fetch();
        } elseif (!$unapplied) {
            $stmt = db()->prepare("SELECT b.*,v.name AS party_name FROM bills b JOIN vendors v ON v.id=b.vendor_id WHERE b.id=? AND b.company_id=? AND b.status='open' FOR UPDATE");
            $stmt->execute([$documentId,$companyId]);
            $document = $stmt->fetch();
        } else {
            $partyId = clean_text($input['partyId'] ?? '', $type === 'customer' ? 'Customer' : 'Vendor', 64);
            $stmt = $type === 'customer'
                ? db()->prepare('SELECT id,name,NULL AS default_currency FROM customers WHERE id=? AND company_id=? AND active=1 FOR UPDATE')
                : db()->prepare('SELECT id,name,default_currency FROM vendors WHERE id=? AND company_id=? AND active=1 FOR UPDATE');
            $stmt->execute([$partyId,$companyId]);
            $party = $stmt->fetch();
            if (!$party) fail($type === 'customer' ? 'Choose an active customer.' : 'Choose an active vendor.');
            $document = null;
        }
        if (!$unapplied && (!$document || (int)$document['balance_cents'] <= 0 || (int)$document['foreign_balance_cents'] <= 0)) {
            fail($type === 'customer' ? 'Choose an open issued invoice.' : 'Choose an open vendor invoice.', 409, 'payment_document_unavailable');
        }
        $documentDate = $unapplied ? null : (string)($type === 'customer' ? $document['issue_date'] : $document['bill_date']);
        $isAdvancePayment = $unapplied || $paymentDate < $documentDate;
        if (!$unapplied && $foreignAmount > (int)$document['foreign_balance_cents']) fail('Payment cannot exceed the document balance.');

        $account = company_account($companyId, $paymentAccountId);
        if (!$account) fail('Choose an active payment account.');
        $bankStmt = db()->prepare('SELECT id,currency FROM bank_accounts WHERE company_id=? AND ledger_account_id=? AND active=1 LIMIT 1');
        $bankStmt->execute([$companyId,$paymentAccountId]);
        $bank = $bankStmt->fetch();
        $isUndeposited = (string)$account['code'] === '1050';
        if (!$bank && !($type === 'customer' && $isUndeposited)) {
            fail($type === 'customer'
                ? 'Choose an active bank account or Undeposited Funds.'
                : 'Choose the bank or credit-card account used for the vendor payment.');
        }
        $currency = $unapplied
            ? safe_currency_code($input['currency'] ?? ($party['default_currency'] ?: $company['currency']), 'Advance currency')
            : (string)$document['currency'];
        if ($bank && (string)$bank['currency'] !== $currency) fail('The payment account and document currencies must match.');
        if ($isUndeposited && $currency !== (string)$company['currency']) {
            fail('Foreign-currency receipts must be recorded directly to a bank account in the same currency.');
        }
        $currencyRow = company_currency($companyId,$currency);
        if (!$currencyRow) fail('The payment currency is not active for this company.');
        $rate = safe_exchange_rate_micros($input['exchangeRateMicros'] ?? $currencyRow['rate_to_base_micros'], $currency, (string)$company['currency']);
        $actualAmount = convert_to_base_cents($foreignAmount,$rate);
        $carryingReduction = $unapplied ? $actualAmount : ($foreignAmount === (int)$document['foreign_balance_cents']
            ? (int)$document['balance_cents']
            : min((int)$document['balance_cents'],convert_to_base_cents($foreignAmount,(int)$document['exchange_rate_micros'])));
        $paymentId = new_id('payment');
        $documentNumber = $unapplied ? 'Advance / Unapplied' : (string)$document['number'];
        $partyName = $unapplied ? (string)$party['name'] : (string)$document['party_name'];
        $partyId = $unapplied ? (string)$party['id'] : (string)($type === 'customer' ? $document['customer_id'] : $document['vendor_id']);

        if ($unapplied) {
            $lines = $type === 'customer' ? [
                ['accountId'=>$paymentAccountId,'debitCents'=>$actualAmount,'creditCents'=>0,'memo'=>$partyName],
                ['accountId'=>account_by_code($companyId,'1200'),'debitCents'=>0,'creditCents'=>$actualAmount,'memo'=>'Customer advance'],
            ] : [
                ['accountId'=>account_by_code($companyId,'2050'),'debitCents'=>$actualAmount,'creditCents'=>0,'memo'=>'Vendor advance'],
                ['accountId'=>$paymentAccountId,'debitCents'=>0,'creditCents'=>$actualAmount,'memo'=>$partyName],
            ];
        } elseif ((!$unapplied && !empty($document['is_opening_document'])) || (string)$company['accounting_basis'] === 'accrual') {
            if ($type === 'customer') {
                $lines = [
                    ['accountId'=>$paymentAccountId,'debitCents'=>$actualAmount,'creditCents'=>0,'memo'=>$partyName],
                    ['accountId'=>account_by_code($companyId,'1200'),'debitCents'=>0,'creditCents'=>$carryingReduction,'memo'=>$documentNumber],
                ];
                $difference = $actualAmount-$carryingReduction;
                if ($difference > 0) $lines[]=['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>0,'creditCents'=>$difference,'memo'=>'Realized exchange difference'];
                if ($difference < 0) $lines[]=['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>abs($difference),'creditCents'=>0,'memo'=>'Realized exchange difference'];
            } else {
                $lines = [
                    ['accountId'=>account_by_code($companyId,'2050'),'debitCents'=>$carryingReduction,'creditCents'=>0,'memo'=>$documentNumber],
                    ['accountId'=>$paymentAccountId,'debitCents'=>0,'creditCents'=>$actualAmount,'memo'=>$partyName],
                ];
                $difference = $actualAmount-$carryingReduction;
                if ($difference > 0) $lines[]=['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>$difference,'creditCents'=>0,'memo'=>'Realized exchange difference'];
                if ($difference < 0) $lines[]=['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>0,'creditCents'=>abs($difference),'memo'=>'Realized exchange difference'];
            }
        } elseif ($type === 'customer') {
            $taxPortion = (int)round(($actualAmount*(int)$document['foreign_tax_cents'])/max(1,(int)$document['foreign_total_cents']));
            $lines = [
                ['accountId'=>$paymentAccountId,'debitCents'=>$actualAmount,'creditCents'=>0,'memo'=>$partyName],
                ['accountId'=>account_by_code($companyId,'4000'),'debitCents'=>0,'creditCents'=>$actualAmount-$taxPortion,'memo'=>$documentNumber],
            ];
            [$gstPart,$pstPart]=invoice_tax_parts($document,$taxPortion);foreach(invoice_tax_credit_lines($companyId,$gstPart,$pstPart) as $taxLine)$lines[]=$taxLine;
        } else {
            // Cash-basis vendor payments recognise the vendor-invoice tax when
            // cash leaves. Keep GST/HST and PST distinct: non-recoverable PST
            // remains part of cost; explicitly recoverable PST uses 1110.
            $foreignTotal=max(1,(int)$document['foreign_total_cents']);
            $foreignGst=(int)($document['foreign_gst_hst_cents']??$document['foreign_tax_cents']??0);
            $foreignPst=(int)($document['foreign_pst_cents']??0);
            $gstPortion=$foreignGst>0?(int)round(($actualAmount*$foreignGst)/$foreignTotal):0;
            $pstPortion=$foreignPst>0?(int)round(($actualAmount*$foreignPst)/$foreignTotal):0;
            $pstRecoverable=(bool)($company['pst_recoverable']??false);
            $recoverablePst=$pstRecoverable?$pstPortion:0;
            if($gstPortion+$recoverablePst>$actualAmount)fail('Vendor payment tax allocation exceeds the payment amount. Review the vendor invoice tax setup.',409,'vendor_payment_tax_allocation_invalid');
            $categoryDebit=$actualAmount-$gstPortion-$recoverablePst;
            $lines = [['accountId'=>(string)$document['category_account_id'],'debitCents'=>$categoryDebit,'creditCents'=>0,'memo'=>$documentNumber]];
            if ($gstPortion > 0) $lines[]=['accountId'=>account_by_code($companyId,'1100'),'debitCents'=>$gstPortion,'creditCents'=>0,'memo'=>'GST/HST recoverable'];
            if ($recoverablePst > 0) $lines[]=['accountId'=>account_by_code($companyId,'1110'),'debitCents'=>$recoverablePst,'creditCents'=>0,'memo'=>'PST recoverable'];
            $lines[]=['accountId'=>$paymentAccountId,'debitCents'=>0,'creditCents'=>$actualAmount,'memo'=>$partyName];
        }

        $sourceType = $type === 'customer' ? 'customer_payment' : 'vendor_payment';
        $description = $unapplied
            ? ($type === 'customer' ? 'Customer advance receipt · ' : 'Vendor advance payment · ') . $partyName . ' · ' . $reference
            : ($type === 'customer' ? 'Customer receipt · '.$partyName.' · Customer Invoice '.$documentNumber.' · '.$reference : 'Vendor payment · '.$partyName.' · Vendor Invoice '.$documentNumber.' · '.$reference);
        if (function_exists('voucher_register_saved')) {
            voucher_register_saved($user,$companyId,$type === 'customer' ? 'BR' : 'BP',$type === 'customer' ? 'AR' : 'AP',
                $sourceType,$paymentId,$paymentDate,$description,$actualAmount,null,false);
        }
        $entryId = add_journal_entry($user,$companyId,$paymentDate,$sourceType,$paymentId,$description,$lines);
        if (!$unapplied && $type === 'customer') {
            $newBalance = (int)$document['balance_cents']-$carryingReduction;
            $updated = db()->prepare("UPDATE invoices SET balance_cents=?,foreign_balance_cents=foreign_balance_cents-?,
              status=CASE WHEN ?=0 THEN 'paid' ELSE 'sent' END
              WHERE id=? AND company_id=? AND status='sent' AND balance_cents>=? AND foreign_balance_cents>=?");
        } elseif (!$unapplied) {
            $newBalance = (int)$document['balance_cents']-$carryingReduction;
            $updated = db()->prepare("UPDATE bills SET balance_cents=?,foreign_balance_cents=foreign_balance_cents-?,
              status=CASE WHEN ?=0 THEN 'paid' ELSE 'open' END
              WHERE id=? AND company_id=? AND status='open' AND balance_cents>=? AND foreign_balance_cents>=?");
        }
        if (!$unapplied) {
            $updated->execute([$newBalance,$foreignAmount,$newBalance,$documentId,$companyId,$carryingReduction,$foreignAmount]);
            if ($updated->rowCount() !== 1) fail('The document balance changed before this payment was posted.',409,'payment_balance_conflict');
        }
        db()->prepare("INSERT INTO party_payments
          (id,company_id,payment_type,flow_kind,party_id,document_id,payment_date,reference,memo,amount_cents,applied_cents,currency,
           foreign_amount_cents,exchange_rate_micros,payment_account_id,journal_entry_id,status,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'posted',?)")
            ->execute([$paymentId,$companyId,$type,$type==='customer'?'customer_receipt':'vendor_payment',$partyId,$documentId,$paymentDate,$reference,$memo,$actualAmount,$unapplied?0:$carryingReduction,
                $currency,$foreignAmount,$rate,$paymentAccountId,$entryId,$user['id']]);
        if(!$unapplied){
            $operationId=new_id('payapplyop');$operationKey='initial:'.$paymentId;$payloadHash=payment_application_payload_hash($companyId,(string)$user['id'],$paymentDate,[['paymentId'=>$paymentId,'documentId'=>$documentId,'foreignAmountCents'=>$foreignAmount]]);
            db()->prepare("INSERT INTO party_payment_application_operations(id,company_id,user_id,operation_key,payload_hash,status) VALUES(?,?,?,?,?,'active')")->execute([$operationId,$companyId,$user['id'],$operationKey,$payloadHash]);
            $applicationId=payment_application_register($user,$companyId,['id'=>$paymentId,'payment_type'=>$type,'party_id'=>$partyId],$document,$paymentDate,$foreignAmount,$actualAmount,$carryingReduction,$operationId);
            $result=json_encode(['operationId'=>$operationId,'status'=>'completed','applications'=>[['applicationId'=>$applicationId,'paymentId'=>$paymentId,'documentId'=>$documentId,'foreignAmountCents'=>$foreignAmount,'paymentCarryingCents'=>$actualAmount,'documentCarryingCents'=>$carryingReduction]],'accountingEntriesCreated'=>0],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            db()->prepare("UPDATE party_payment_application_operations SET status='completed',result_json=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$result,$operationId]);
        }
        assert_party_payment_posting_invariants($companyId,$paymentId,$unapplied?null:(int)$document['balance_cents']);
        if (function_exists('voucher_mark_posted')) voucher_mark_posted($user,$companyId,$sourceType,$paymentId,$entryId);
        audit_event($user,$companyId,$type.'.payment_posted',$sourceType,$paymentId,[
            'documentId'=>$documentId,'documentNumber'=>$documentNumber,'partyId'=>$partyId,'partyName'=>$partyName,
            'amountCents'=>$actualAmount,'foreignAmountCents'=>$foreignAmount,'currency'=>$currency,'reference'=>$reference,
            'advancePayment'=>$isAdvancePayment,'unapplied'=>$unapplied,
        ]);
        $voucherQ=db()->prepare('SELECT voucher_number FROM vouchers WHERE company_id=? AND source_type=? AND source_id=? ORDER BY serial_number LIMIT 1');$voucherQ->execute([$companyId,$sourceType,$paymentId]);$result=['id'=>$paymentId,'type'=>$type,'flowKind'=>$type==='customer'?'customer_receipt':'vendor_payment','status'=>'posted','partyId'=>$partyId,'partyName'=>$partyName,'amountCents'=>$actualAmount,'remainingCents'=>$unapplied?$actualAmount:0,'currency'=>$currency,'foreignAmountCents'=>$foreignAmount,'foreignRemainingCents'=>$unapplied?$foreignAmount:0,'paymentAccountId'=>$paymentAccountId,'unapplied'=>$unapplied,'journalEntryId'=>$entryId,'publicVoucher'=>(string)($voucherQ->fetchColumn()?:'')];db()->prepare("UPDATE party_payment_application_operations SET status='completed',result_json=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='active'")->execute([json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(string)$operation['operation']['id']]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['payment'=>$result],201);
}

function apply_advance_to_document(array $user, array $company, array $input): never
{
    $companyId = (string)$company['id'];
    $paymentId = clean_text($input['paymentId'] ?? '', 'Advance payment', 64);
    $documentId = clean_text($input['documentId'] ?? '', 'Invoice', 64);
    $applicationDate = safe_date($input['applicationDate'] ?? canadian_today(), 'Application date');
    assert_not_future_date($applicationDate, 'Application date');
    db()->beginTransaction();
    try {
        $stmt = db()->prepare("SELECT * FROM party_payments WHERE id=? AND company_id=? AND status='posted' AND document_id IS NULL FOR UPDATE");
        $stmt->execute([$paymentId,$companyId]);
        $payment = $stmt->fetch();
        if (!$payment) fail('Choose an available unapplied advance.',409,'advance_unavailable');
        $type = (string)$payment['payment_type'];
        if ($type === 'customer') {
            $stmt = db()->prepare("SELECT i.*,c.name AS party_name FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=? AND i.company_id=? AND i.customer_id=? AND i.status='sent' FOR UPDATE");
        } else {
            $stmt = db()->prepare("SELECT b.*,v.name AS party_name FROM bills b JOIN vendors v ON v.id=b.vendor_id WHERE b.id=? AND b.company_id=? AND b.vendor_id=? AND b.status='open' FOR UPDATE");
        }
        $stmt->execute([$documentId,$companyId,$payment['party_id']]);
        $document = $stmt->fetch();
        if (!$document || (int)$document['balance_cents'] <= 0 || (int)$document['foreign_balance_cents'] <= 0) fail('Choose an open invoice for the same customer or vendor.',409,'advance_document_unavailable');
        if ((string)$document['currency'] !== (string)$payment['currency']) fail('The advance and invoice currencies must match.',409,'advance_currency_mismatch');
        if ((int)$payment['foreign_amount_cents'] > (int)$document['foreign_balance_cents']) fail('This advance exceeds the selected invoice balance. Choose an invoice with enough open balance.',409,'advance_exceeds_balance');
        $documentDate = (string)($type === 'customer' ? $document['issue_date'] : $document['bill_date']);
        if ($applicationDate < $documentDate || $applicationDate < (string)$payment['payment_date']) fail('Application date cannot be before the payment or invoice date.');
        $foreignAmount = (int)$payment['foreign_amount_cents'];
        $actualAmount = (int)$payment['amount_cents'];
        $carryingReduction = $foreignAmount === (int)$document['foreign_balance_cents']
            ? (int)$document['balance_cents']
            : min((int)$document['balance_cents'],convert_to_base_cents($foreignAmount,(int)$document['exchange_rate_micros']));
        $lines = [];
        if (!empty($document['is_opening_document']) || (string)$company['accounting_basis'] === 'accrual') {
            $difference = $actualAmount - $carryingReduction;
            if ($type === 'customer' && $difference > 0) $lines = [
                ['accountId'=>account_by_code($companyId,'1200'),'debitCents'=>$difference,'creditCents'=>0,'memo'=>'Apply customer advance'],
                ['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>0,'creditCents'=>$difference,'memo'=>'Realized exchange difference'],
            ];
            if ($type === 'customer' && $difference < 0) $lines = [
                ['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>abs($difference),'creditCents'=>0,'memo'=>'Realized exchange difference'],
                ['accountId'=>account_by_code($companyId,'1200'),'debitCents'=>0,'creditCents'=>abs($difference),'memo'=>'Apply customer advance'],
            ];
            if ($type === 'vendor' && $difference > 0) $lines = [
                ['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>$difference,'creditCents'=>0,'memo'=>'Realized exchange difference'],
                ['accountId'=>account_by_code($companyId,'2050'),'debitCents'=>0,'creditCents'=>$difference,'memo'=>'Apply vendor advance'],
            ];
            if ($type === 'vendor' && $difference < 0) $lines = [
                ['accountId'=>account_by_code($companyId,'2050'),'debitCents'=>abs($difference),'creditCents'=>0,'memo'=>'Apply vendor advance'],
                ['accountId'=>account_by_code($companyId,'6850'),'debitCents'=>0,'creditCents'=>abs($difference),'memo'=>'Realized exchange difference'],
            ];
        } elseif ($type === 'customer') {
            $taxPortion = (int)round(($actualAmount*(int)$document['foreign_tax_cents'])/max(1,(int)$document['foreign_total_cents']));
            $lines = [
                ['accountId'=>account_by_code($companyId,'1200'),'debitCents'=>$actualAmount,'creditCents'=>0,'memo'=>'Apply customer advance'],
                ['accountId'=>account_by_code($companyId,'4000'),'debitCents'=>0,'creditCents'=>$actualAmount-$taxPortion,'memo'=>(string)$document['number']],
            ];
            [$gstPart,$pstPart]=invoice_tax_parts($document,$taxPortion);foreach(invoice_tax_credit_lines($companyId,$gstPart,$pstPart) as $taxLine)$lines[]=$taxLine;
        } else {
            $foreignTotal=max(1,(int)$document['foreign_total_cents']);
            $foreignGst=(int)($document['foreign_gst_hst_cents']??$document['foreign_tax_cents']??0);
            $foreignPst=(int)($document['foreign_pst_cents']??0);
            $gstPortion=$foreignGst>0?(int)round(($actualAmount*$foreignGst)/$foreignTotal):0;
            $pstPortion=$foreignPst>0?(int)round(($actualAmount*$foreignPst)/$foreignTotal):0;
            $recoverablePst=(bool)($company['pst_recoverable']??false)?$pstPortion:0;
            $categoryDebit=$actualAmount-$gstPortion-$recoverablePst;
            $lines=[['accountId'=>(string)$document['category_account_id'],'debitCents'=>$categoryDebit,'creditCents'=>0,'memo'=>(string)$document['number']]];
            if($gstPortion>0)$lines[]=['accountId'=>account_by_code($companyId,'1100'),'debitCents'=>$gstPortion,'creditCents'=>0,'memo'=>'GST/HST recoverable'];
            if($recoverablePst>0)$lines[]=['accountId'=>account_by_code($companyId,'1110'),'debitCents'=>$recoverablePst,'creditCents'=>0,'memo'=>'PST recoverable'];
            $lines[]=['accountId'=>account_by_code($companyId,'2050'),'debitCents'=>0,'creditCents'=>$actualAmount,'memo'=>'Apply vendor advance'];
        }
        $applicationJournalId = count($lines) ? add_journal_entry($user,$companyId,$applicationDate,$type.'_advance_application',$paymentId,'Apply advance to '.(string)$document['number'],$lines) : null;
        $newBalance = (int)$document['balance_cents']-$carryingReduction;
        if ($type === 'customer') {
            $updated = db()->prepare("UPDATE invoices SET balance_cents=?,foreign_balance_cents=foreign_balance_cents-?,status=CASE WHEN ?=0 THEN 'paid' ELSE 'sent' END WHERE id=? AND company_id=? AND status='sent' AND balance_cents>=? AND foreign_balance_cents>=?");
        } else {
            $updated = db()->prepare("UPDATE bills SET balance_cents=?,foreign_balance_cents=foreign_balance_cents-?,status=CASE WHEN ?=0 THEN 'paid' ELSE 'open' END WHERE id=? AND company_id=? AND status='open' AND balance_cents>=? AND foreign_balance_cents>=?");
        }
        $updated->execute([$newBalance,$foreignAmount,$newBalance,$documentId,$companyId,$carryingReduction,$foreignAmount]);
        if ($updated->rowCount() !== 1) fail('The invoice balance changed before the advance was applied.',409,'advance_balance_conflict');
        db()->prepare('UPDATE party_payments SET document_id=?,applied_cents=? WHERE id=? AND company_id=? AND document_id IS NULL')->execute([$documentId,$carryingReduction,$paymentId,$companyId]);
        audit_event($user,$companyId,$type.'.advance_applied',$type.'_payment',$paymentId,['documentId'=>$documentId,'documentNumber'=>(string)$document['number'],'applicationDate'=>$applicationDate,'amountCents'=>$actualAmount,'carryingReductionCents'=>$carryingReduction,'applicationJournalEntryId'=>$applicationJournalId]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['payment'=>['id'=>$paymentId,'documentId'=>$documentId,'unapplied'=>false],'applicationJournalEntryId'=>$applicationJournalId]);
}

function reverse_party_payment(array $user, array $company, array $input): never
{
    $companyId = (string)$company['id'];
    $paymentId = clean_text($input['paymentId'] ?? '', 'Payment', 64);
    $date = safe_date($input['reversalDate'] ?? canadian_today(),'Reversal date');
    assert_not_future_date($date,'Reversal date');
    db()->beginTransaction();
    try {
        $stmt = db()->prepare("SELECT * FROM party_payments WHERE id=? AND company_id=? AND status='posted' FOR UPDATE");
        $stmt->execute([$paymentId,$companyId]);
        $payment = $stmt->fetch();
        if (!$payment) fail('Choose an available posted payment.',409,'payment_unavailable');
        $linkedNoteRefund=null;
        if(schema_table_exists('accounting_note_settlements')){
            $linked=db()->prepare("SELECT s.* FROM accounting_note_settlements s JOIN accounting_notes n ON n.id=s.note_id AND n.company_id=s.company_id WHERE s.company_id=? AND s.payment_id=? AND s.settlement_kind='refund' AND s.status='posted' FOR UPDATE");
            $linked->execute([$companyId,$paymentId]);$linkedNoteRefund=$linked->fetch();
            if($linkedNoteRefund && $date<(string)$linkedNoteRefund['settlement_date'])fail('Reversal date cannot precede the linked note refund.',409,'note_refund_reversal_date_invalid');
            if($linkedNoteRefund)note_assert_settlement_date_order($companyId,(string)$linkedNoteRefund['note_id'],$date);
        }
        if ($payment['bank_transaction_id'] !== null) {
            fail('This payment is matched to a bank statement. Undo the bank match before reversing the payment.',409,'payment_is_bank_matched');
        }
        $activeApplications=db()->prepare("SELECT COUNT(*) FROM party_payment_applications WHERE company_id=? AND payment_id=? AND status='posted' FOR UPDATE");
        $activeApplications->execute([$companyId,$paymentId]);
        if((int)$activeApplications->fetchColumn()>0)fail('Unapply every active invoice or bill allocation before reversing this payment.',409,'payment_has_active_applications');
        if ($date < (string)$payment['payment_date']) fail('Reversal date cannot precede the payment date.');
        if ($payment['journal_entry_id'] === null) throw new RuntimeException('The payment journal is missing.');
        require_journal_unmatched_before_reversal($companyId, (string)$payment['journal_entry_id'], (string)$payment['payment_type'].' payment');
        $applicationStmt = db()->prepare("SELECT id FROM journal_entries WHERE company_id=? AND source_type=? AND source_id=? AND status='posted' ORDER BY created_at DESC LIMIT 1 FOR UPDATE");
        $applicationStmt->execute([$companyId,(string)$payment['payment_type'].'_advance_application',$paymentId]);
        $applicationJournalId = $applicationStmt->fetchColumn();
        $applicationReversalId = $applicationJournalId
            ? add_reversing_journal_entry($user,$companyId,(string)$applicationJournalId,$date,(string)$payment['payment_type'].'_advance_application_reversal',$paymentId,'Reverse advance application '.$payment['reference'])
            : null;
        $reversalId = add_reversing_journal_entry($user,$companyId,(string)$payment['journal_entry_id'],$date,
            (string)$payment['payment_type'].'_payment_reversal',$paymentId,'Reverse payment '.$payment['reference']);
        if ($payment['document_id'] !== null && (string)$payment['payment_type'] === 'customer') {
            db()->prepare("UPDATE invoices SET balance_cents=LEAST(total_cents,balance_cents+?),
              foreign_balance_cents=LEAST(foreign_total_cents,foreign_balance_cents+?),
              status='sent' WHERE id=? AND company_id=?")
                ->execute([(int)$payment['applied_cents'],(int)$payment['foreign_amount_cents'],$payment['document_id'],$companyId]);
        } elseif ($payment['document_id'] !== null) {
            db()->prepare("UPDATE bills SET balance_cents=LEAST(total_cents,balance_cents+?),
              foreign_balance_cents=LEAST(foreign_total_cents,foreign_balance_cents+?),
              status='open' WHERE id=? AND company_id=?")
                ->execute([(int)$payment['applied_cents'],(int)$payment['foreign_amount_cents'],$payment['document_id'],$companyId]);
        }
        db()->prepare("UPDATE party_payments SET status='reversed',reversal_journal_entry_id=?,reversed_by=?,reversed_at=UTC_TIMESTAMP()
          WHERE id=? AND company_id=? AND status='posted'")->execute([$reversalId,$user['id'],$paymentId,$companyId]);
        if($linkedNoteRefund){
            db()->prepare("UPDATE accounting_note_settlements SET status='reversed',reversal_date=? WHERE company_id=? AND id=? AND status='posted'")
                ->execute([$date,$companyId,$linkedNoteRefund['id']]);
            audit_event($user,$companyId,'accounting_note.refund_reversed','accounting_note',(string)$linkedNoteRefund['note_id'],['settlementId'=>$linkedNoteRefund['id'],'paymentId'=>$paymentId,'reversalDate'=>$date,'reversalJournalEntryId'=>$reversalId]);
        }
        if (function_exists('voucher_mark_void')) {
            voucher_mark_void($user,$companyId,(string)$payment['payment_type'].'_payment',$paymentId);
        }
        audit_event($user,$companyId,(string)$payment['payment_type'].'.payment_reversed',
            (string)$payment['payment_type'].'_payment',$paymentId,['reversalJournalEntryId'=>$reversalId,'applicationReversalJournalEntryId'=>$applicationReversalId,'reversalDate'=>$date]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    json_response(['payment'=>['id'=>$paymentId,'status'=>'reversed']]);
}

/**
 * Record an on-account AR/AP movement directly from one imported statement
 * row. The caller owns the surrounding transaction. This service creates one
 * journal and one partner-payment source, then binds the bank evidence to it.
 */
function record_bank_open_party_payment(array $user,array $company,array $transaction,array $capability,array $decision): array
{
    if(!db()->inTransaction())throw new LogicException('Bank-origin partner payment requires the posting transaction.');
    $companyId=(string)$company['id'];$kind=(string)$capability['kind'];$type=$kind==='customer_control'?'customer':($kind==='vendor_control'?'vendor':'');
    if($type==='')fail('Choose the configured Accounts Receivable or Accounts Payable control.',422,'partner_control_required');
    $partyId=clean_text($decision['partyId']??'',$type==='customer'?'Customer':'Vendor',64);$table=$type==='customer'?'customers':'vendors';
    $q=db()->prepare("SELECT id,name".($type==='vendor'?',default_currency':'')." FROM `$table` WHERE company_id=? AND id=? AND active=1 FOR UPDATE");$q->execute([$companyId,$partyId]);$party=$q->fetch(PDO::FETCH_ASSOC);
    if(!$party)fail($type==='customer'?'Choose an active customer.':'Choose an active vendor.',422,'partner_unavailable');
    $signed=(int)$transaction['amount_cents'];$foreignSigned=(int)$transaction['foreign_amount_cents'];if($signed===0||$foreignSigned===0)fail('A zero-value statement row cannot create a partner payment.',422,'payment_amount_invalid');
    $flow=$type==='customer'?($signed>0?'customer_receipt':'customer_refund'):($signed<0?'vendor_payment':'vendor_refund');
    $requested=trim((string)($decision['flowKind']??''));if($requested!==''&&!hash_equals($flow,$requested))fail('The selected partner-payment direction does not agree with the normalized statement movement.',409,'payment_flow_conflict');
    $controlId=(string)$capability['account']['id'];$bankLedgerId=(string)$transaction['ledger_account_id'];$amount=abs($signed);$foreign=abs($foreignSigned);$memo=mb_substr(trim((string)($decision['remarks']??'')),0,500);
    $lines=match($flow){
        'customer_receipt'=>[['accountId'=>$bankLedgerId,'debitCents'=>$amount,'creditCents'=>0,'memo'=>(string)$party['name']],['accountId'=>$controlId,'debitCents'=>0,'creditCents'=>$amount,'memo'=>'Open customer receipt']],
        'customer_refund'=>[['accountId'=>$controlId,'debitCents'=>$amount,'creditCents'=>0,'memo'=>'Customer refund'],['accountId'=>$bankLedgerId,'debitCents'=>0,'creditCents'=>$amount,'memo'=>(string)$party['name']]],
        'vendor_payment'=>[['accountId'=>$controlId,'debitCents'=>$amount,'creditCents'=>0,'memo'=>'Open vendor payment'],['accountId'=>$bankLedgerId,'debitCents'=>0,'creditCents'=>$amount,'memo'=>(string)$party['name']]],
        'vendor_refund'=>[['accountId'=>$bankLedgerId,'debitCents'=>$amount,'creditCents'=>0,'memo'=>(string)$party['name']],['accountId'=>$controlId,'debitCents'=>0,'creditCents'=>$amount,'memo'=>'Vendor refund']],
    };
    $paymentId=new_id('payment');$description=match($flow){'customer_receipt'=>'Customer receipt','customer_refund'=>'Customer refund','vendor_payment'=>'Vendor payment','vendor_refund'=>'Vendor refund'}.' · '.(string)$party['name'].($memo!==''?' · '.$memo:'');
    $journalId=add_journal_entry($user,$companyId,(string)$transaction['transaction_date'],'bank_transaction',(string)$transaction['id'],$description,$lines);
    $reference=mb_substr((string)($transaction['reference']?:$transaction['description']),0,120);
    db()->prepare("INSERT INTO party_payments(id,company_id,payment_type,flow_kind,party_id,document_id,payment_date,reference,memo,amount_cents,applied_cents,currency,foreign_amount_cents,exchange_rate_micros,payment_account_id,journal_entry_id,bank_transaction_id,status,created_by,matched_at) VALUES(?,?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,?,'posted',?,UTC_TIMESTAMP())")
        ->execute([$paymentId,$companyId,$type,$flow,$partyId,(string)$transaction['transaction_date'],$reference,$memo,$amount,0,(string)$transaction['currency'],$foreign,(int)$transaction['exchange_rate_micros'],$bankLedgerId,$journalId,(string)$transaction['id'],(string)$user['id']]);
    $update=db()->prepare("UPDATE bank_transactions SET decided_account_id=?,remarks=?,tax_code='NO_TAX',suggestion_source='manual',status='posted',journal_entry_id=? WHERE company_id=? AND id=? AND status='pending' AND journal_entry_id IS NULL");
    $update->execute([$controlId,$memo,$journalId,$companyId,(string)$transaction['id']]);if($update->rowCount()!==1)fail('The bank transaction changed before the partner payment could be recorded.',409,'payment_bank_row_conflict');
    if(function_exists('voucher_mark_posted'))voucher_mark_posted($user,$companyId,'bank_transaction',(string)$transaction['id'],$journalId);
    audit_event($user,$companyId,$type.'.open_payment_recorded_from_bank',$type.'_payment',$paymentId,['bankTransactionId'=>(string)$transaction['id'],'flowKind'=>$flow,'partyId'=>$partyId,'amountCents'=>$amount,'journalEntryId'=>$journalId]);
    return ['paymentId'=>$paymentId,'flowKind'=>$flow,'partnerName'=>(string)$party['name'],'journalEntryId'=>$journalId,'amountCents'=>$amount,'foreignAmountCents'=>$foreign,'remainingCents'=>in_array($flow,['customer_receipt','vendor_payment'],true)?$amount:0,'journalsCreated'=>1];
}

/**
 * Register a direct invoice/bill match from an imported statement as a
 * payment source record so it appears in party ledgers and payment history.
 */
function record_bank_party_payment(
    array $user,
    string $companyId,
    string $type,
    array $document,
    array $transaction,
    string $bankLedgerId,
    string $journalEntryId,
    int $actualAmount,
    int $appliedAmount,
    int $foreignAmount
): string {
    $paymentId = new_id('payment');
    $partyId = (string)($type === 'customer' ? $document['customer_id'] : $document['vendor_id']);
    $reference = mb_substr('Bank statement · '.(string)$transaction['description'],0,120);
    db()->prepare("INSERT INTO party_payments
      (id,company_id,payment_type,flow_kind,party_id,document_id,payment_date,reference,memo,amount_cents,applied_cents,currency,
       foreign_amount_cents,exchange_rate_micros,payment_account_id,journal_entry_id,bank_transaction_id,status,created_by,matched_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'posted',?,UTC_TIMESTAMP())")
        ->execute([$paymentId,$companyId,$type,$type==='customer'?'customer_receipt':'vendor_payment',$partyId,$document['id'],$transaction['transaction_date'],$reference,'',
            $actualAmount,$appliedAmount,$transaction['currency'],$foreignAmount,$transaction['exchange_rate_micros'],
            $bankLedgerId,$journalEntryId,$transaction['id'],$user['id']]);
    $operationId=new_id('payapplyop');$operationKey='initial:'.$paymentId;$payloadHash=payment_application_payload_hash($companyId,(string)$user['id'],(string)$transaction['transaction_date'],[['paymentId'=>$paymentId,'documentId'=>(string)$document['id'],'foreignAmountCents'=>$foreignAmount]]);
    db()->prepare("INSERT INTO party_payment_application_operations(id,company_id,user_id,operation_key,payload_hash,status) VALUES(?,?,?,?,?,'active')")->execute([$operationId,$companyId,$user['id'],$operationKey,$payloadHash]);
    $applicationId=payment_application_register($user,$companyId,['id'=>$paymentId,'payment_type'=>$type,'party_id'=>$partyId],$document,(string)$transaction['transaction_date'],$foreignAmount,$actualAmount,$appliedAmount,$operationId);
    db()->prepare("UPDATE party_payment_application_operations SET status='completed',result_json=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([json_encode(['operationId'=>$operationId,'status'=>'completed','applications'=>[['applicationId'=>$applicationId,'paymentId'=>$paymentId,'documentId'=>(string)$document['id'],'foreignAmountCents'=>$foreignAmount,'paymentCarryingCents'=>$actualAmount,'documentCarryingCents'=>$appliedAmount]],'accountingEntriesCreated'=>0],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$operationId]);
    assert_party_payment_posting_invariants($companyId,$paymentId,(int)$document['balance_cents']);
    $sourceType = $type.'_payment';
    if (function_exists('voucher_register_saved')) {
        $description = ($type === 'customer' ? 'Customer receipt · ' : 'Vendor payment · ')
            .(string)$document['party_name'].' · '.(string)$document['number'];
        voucher_register_saved($user,$companyId,$type === 'customer' ? 'BR' : 'BP',$type === 'customer' ? 'AR' : 'AP',
            $sourceType,$paymentId,(string)$transaction['transaction_date'],$description,$actualAmount,$journalEntryId,true);
    }
    audit_event($user,$companyId,$type.'.payment_recorded_from_bank',$sourceType,$paymentId,[
        'documentId'=>(string)$document['id'],'bankTransactionId'=>(string)$transaction['id'],'amountCents'=>$actualAmount,
    ]);
    return $paymentId;
}

/**
 * Match an imported line with a payment that was already posted. This never
 * reposts the receivable/payable. Customer receipts held in Undeposited Funds
 * receive only the bank-transfer journal needed to clear that control account.
 */
function match_bank_transaction_to_posted_payment(array $user, array $company, array $transaction, string $paymentId): array
{
    $companyId = (string)$company['id'];
    $stmt = db()->prepare("SELECT pp.*,a.code AS payment_account_code
      FROM party_payments pp JOIN accounts a ON a.id=pp.payment_account_id
      WHERE pp.id=? AND pp.company_id=? AND pp.status='posted' AND pp.bank_transaction_id IS NULL FOR UPDATE");
    $stmt->execute([$paymentId,$companyId]);
    $payment = $stmt->fetch();
    if (!$payment) fail('Choose an unmatched posted customer or vendor payment.',409,'payment_match_unavailable');
    $amount = (int)$transaction['amount_cents'];
    if ((string)$transaction['transaction_date'] < (string)$payment['payment_date']) {
        fail('The bank transaction date cannot precede the posted payment date.');
    }
    if ((string)$payment['payment_type'] === 'customer' && $amount <= 0) fail('A customer receipt can only match money coming into the bank.');
    if ((string)$payment['payment_type'] === 'vendor' && $amount >= 0) fail('A vendor payment can only match money leaving the bank.');
    if ((string)$payment['currency'] !== (string)$transaction['currency']) fail('The bank transaction and posted payment currencies must match.');
    if ((int)$payment['foreign_amount_cents'] !== abs((int)$transaction['foreign_amount_cents'])
        || (int)$payment['amount_cents'] !== abs($amount)) {
        fail('The bank transaction amount must exactly equal the posted payment. Split or correct the payment before matching.');
    }

    $bankLedgerId = (string)$transaction['ledger_account_id'];
    $entryId = (string)$payment['journal_entry_id'];
    $counterpartId = (string)$payment['payment_account_id'];
    if ((string)$payment['payment_account_id'] !== $bankLedgerId) {
        if ((string)$payment['payment_type'] !== 'customer' || (string)$payment['payment_account_code'] !== '1050') {
            fail('This payment was posted to a different bank account and cannot be matched here.');
        }
        $entryId = add_journal_entry($user,$companyId,(string)$transaction['transaction_date'],'bank_payment_match',
            (string)$transaction['id'],'Deposit posted customer receipt '.$payment['reference'],[
                ['accountId'=>$bankLedgerId,'debitCents'=>abs($amount),'creditCents'=>0,'memo'=>'Bank deposit'],
                ['accountId'=>(string)$payment['payment_account_id'],'debitCents'=>0,'creditCents'=>abs($amount),'memo'=>'Clear Undeposited Funds'],
            ]);
    } else {
        $counterpartId = (string)$payment['payment_type'] === 'customer'
            ? account_by_code($companyId,'1200')
            : account_by_code($companyId,'2050');
    }
    db()->prepare("UPDATE bank_transactions SET decided_account_id=?,tax_code='NO_TAX',suggestion_source='manual',
      status='posted',journal_entry_id=? WHERE id=? AND company_id=? AND status='pending'")
        ->execute([$counterpartId,$entryId,$transaction['id'],$companyId]);
    db()->prepare("UPDATE party_payments SET bank_transaction_id=?,matched_at=UTC_TIMESTAMP()
      WHERE id=? AND company_id=? AND status='posted' AND bank_transaction_id IS NULL")
        ->execute([$transaction['id'],$paymentId,$companyId]);
    if (function_exists('voucher_mark_posted')) voucher_mark_posted($user,$companyId,'bank_transaction',(string)$transaction['id'],$entryId);
    audit_event($user,$companyId,'payment.bank_matched',(string)$payment['payment_type'].'_payment',$paymentId,[
        'bankTransactionId'=>(string)$transaction['id'],'journalEntryId'=>$entryId,'amountCents'=>abs($amount),
    ]);
    audit_event($user,$companyId,'bank_transaction.posted','bank_transaction',(string)$transaction['id'],[
        'paymentId'=>$paymentId,'paymentType'=>(string)$payment['payment_type'],'accountId'=>$counterpartId,
    ]);
    return ['paymentId'=>$paymentId,'journalEntryId'=>$entryId,'accountId'=>$counterpartId];
}
