<?php
declare(strict_types=1);

/** Durable orchestration, never an alternative journal engine. Each row and
 * its exact accounting result commit in ONE transaction through the existing
 * bank_transaction_post_service. A lost HTTP response is a receipt lookup. */
function tegh_bank_operation_key(mixed $value): string
{
    if(!is_string($value)||!preg_match('/^[A-Za-z0-9_-]{16,120}$/D',$value))fail('A stable operation key is required.',422,'operation_key_invalid');return $value;
}
function tegh_bank_decision_contract(mixed $value): array
{
    if(!is_array($value)||array_is_list($value))fail('Invalid posting decision.',422,'bank_decision_invalid');
    $allowed=['id','accountId','transferBankAccountId','transferOperationKey','remarks','paymentId','invoiceId','billId','taxCode','applyGstHst','applyPst','allowContra','salesTaxSettlement','salesTaxPeriodEnd','taxCodeId'];
    if(array_diff(array_keys($value),$allowed))fail('Unsupported posting decision field.',422,'bank_decision_invalid');
    $out=['id'=>clean_text($value['id']??'','Transaction',64)];
    foreach(['accountId','transferBankAccountId','paymentId','invoiceId','billId'] as $key)if(isset($value[$key])&&trim((string)$value[$key])!=='')$out[$key]=clean_text($value[$key],$key,64);
    if(isset($value['salesTaxSettlement'])&&trim((string)$value['salesTaxSettlement'])!==''){$settlement=(string)$value['salesTaxSettlement'];if(!in_array($settlement,['gst_hst','pst'],true))fail('Choose GST/HST or PST for a sales tax remittance.',422,'sales_tax_settlement_invalid');$out['salesTaxSettlement']=$settlement;if(trim((string)($value['salesTaxPeriodEnd']??''))!=='')$out['salesTaxPeriodEnd']=safe_date($value['salesTaxPeriodEnd'],'Return period end');$out['remarks']=mb_substr(trim((string)($value['remarks']??'')),0,500);}
    $kinds=array_intersect(array_keys($out),['accountId','transferBankAccountId','paymentId','invoiceId','billId','salesTaxSettlement']);
    if(count($kinds)!==1)fail('Choose exactly one posting or matching instruction.',422,'bank_decision_conflict');
    $tax=(string)($value['taxCode']??'NO_TAX');if(!in_array($tax,['NO_TAX','GST_HST','HST13','PST','GST_HST_PST','CODE'],true))fail('Choose a valid tax treatment.',422,'bank_tax_invalid');
    // R138: a tax code chosen in tax-code mode travels as taxCode=CODE plus taxCodeId (empty = no tax).
    if(array_key_exists('taxCodeId',$value)&&$value['taxCodeId']!==null){$tcid=trim((string)$value['taxCodeId']);if($tcid!==''&&!preg_match('/^[A-Za-z0-9_\-]{1,64}$/',$tcid))fail('Choose a valid tax code.',422,'bank_tax_invalid');$out['taxCodeId']=$tcid;}
    if($tax==='CODE'&&($out['taxCodeId']??'')==='')fail('Choose a tax code.',422,'bank_tax_invalid');
    $out['taxCode']=$tax==='HST13'?'GST_HST':$tax;
    foreach(['applyGstHst','applyPst','allowContra'] as $key){if(isset($value[$key])&&!is_bool($value[$key]))fail('Posting flags must be explicit booleans.',422,'bank_decision_invalid');$out[$key]=$value[$key]??false;}
    if(isset($out['transferBankAccountId'])){$out['transferOperationKey']=tegh_bank_operation_key($value['transferOperationKey']??null);$out['remarks']=mb_substr(trim((string)($value['remarks']??'')),0,500);$out['taxCode']='NO_TAX';$out['applyGstHst']=false;$out['applyPst']=false;$out['allowContra']=false;unset($out['taxCodeId']);}
    ksort($out);return $out;
}
function tegh_bank_operation_load(array $user,array $company,string $key,bool $lock=false): ?array
{
    $q=db()->prepare('SELECT * FROM bank_bulk_operations WHERE company_id=? AND user_id=? AND operation_key=?'.($lock?' FOR UPDATE':''));$q->execute([(string)$company['id'],(string)$user['id'],$key]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function tegh_bank_operation_receipt(array $operation): array
{
    $q=db()->prepare("SELECT r.*,bt.description FROM bank_bulk_operation_rows r LEFT JOIN bank_transactions bt ON bt.id=r.transaction_id AND bt.company_id=? WHERE r.operation_id=? ORDER BY r.ordinal");$q->execute([$operation['company_id'],$operation['id']]);$rows=[];$counts=['selected'=>(int)$operation['selected_count'],'posted'=>0,'alreadyPosted'=>0,'processing'=>0,'pending'=>0,'failed'=>0,'verifying'=>0];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$state=(string)$r['status'];$key=match($state){'posted'=>'posted','already_posted'=>'alreadyPosted','failed'=>'failed','processing'=>'processing','verifying'=>'verifying',default=>'pending'};$counts[$key]++;
        $rows[]=['transactionId'=>(string)$r['transaction_id'],'ordinal'=>(int)$r['ordinal'],'description'=>(string)($r['description']??'Transaction unavailable'),'status'=>$state,'attempts'=>(int)$r['attempts'],'result'=>$r['result_json']===null?null:json_decode((string)$r['result_json'],true,512,JSON_THROW_ON_ERROR)];}
    $counts['verified']=$counts['posted']+$counts['alreadyPosted'];
    return ['operationId'=>(string)$operation['id'],'operationKey'=>(string)$operation['operation_key'],'companyId'=>(string)$operation['company_id'],'status'=>(string)$operation['status'],'payloadHash'=>(string)$operation['payload_hash'],'counts'=>$counts,'rows'=>$rows,'verifiedAt'=>gmdate('c'),'complete'=>$counts['pending']+$counts['processing']+$counts['verifying']===0,'accountingService'=>'bank_transaction_post_service'];
}
function tegh_bank_operation_state_hash(array $tx): string
{
    $state=[];foreach(['id','status','decided_account_id','suggested_account_id','tax_code','amount_cents','bank_account_id','source_hash','updated_at'] as $key)$state[$key]=$tx[$key]??null;
    return hash('sha256',tegh_json_canonical($state));
}
function tegh_bank_operation_start(array $user,array $company,array $input): array
{
    if(($input['confirmed']??false)!==true)fail('Confirm the selected posting operation.',422,'confirmation_required');
    $key=tegh_bank_operation_key($input['operationKey']??null);$decisions=$input['decisions']??null;
    if(!is_array($decisions)||!array_is_list($decisions)||count($decisions)<1||count($decisions)>100)fail('Select between 1 and 100 transactions.',422,'bank_selection_limit');
    $decisions=array_map('tegh_bank_decision_contract',$decisions);$ids=array_column($decisions,'id');if(count(array_unique($ids))!==count($ids))fail('Duplicate transaction IDs are not allowed.',422,'bank_selection_duplicate');
    $hash=hash('sha256',tegh_json_canonical(['companyId'=>$company['id'],'decisions'=>$decisions]));
    $out=tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$company,$key,$hash,$decisions,$ids){
        $fresh=tegh_bank_reauthorize_mutation($user,$company);$operation=tegh_bank_operation_load($user,$fresh,$key,true);
        if($operation){if(!hash_equals((string)$operation['payload_hash'],$hash))fail('This operation key belongs to different decisions.',409,'idempotency_payload_conflict');return ['operation'=>$operation,'replay'=>true];}
        $sorted=$ids;sort($sorted,SORT_STRING);$q=db()->prepare('SELECT id,status,decided_account_id,suggested_account_id,tax_code,amount_cents,bank_account_id,source_hash,updated_at FROM bank_transactions WHERE company_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id FOR UPDATE');$q->execute([(string)$fresh['id'],...$sorted]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==count($ids))fail('A selected transaction is unavailable.',409,'transaction_unavailable');
        $states=array_column($rows,null,'id');foreach($rows as $r)if(!in_array((string)$r['status'],['pending','posted'],true))fail('Only pending transactions or exact posted retries are permitted.',409,'transaction_status_conflict');
        $id=new_id('bankop');db()->prepare("INSERT INTO bank_bulk_operations(id,company_id,user_id,operation_key,payload_hash,selected_count,status) VALUES(?,?,?,?,?,?,'active')")->execute([$id,$fresh['id'],$user['id'],$key,$hash,count($ids)]);
        $q=db()->prepare("INSERT INTO bank_bulk_operation_rows(operation_id,transaction_id,ordinal,decision_json,status) VALUES(?,?,?,?,'pending')");foreach($decisions as $ordinal=>$d){$d['_expectedStateHash']=tegh_bank_operation_state_hash($states[$d['id']]);$q->execute([$id,$d['id'],$ordinal,tegh_json_canonical($d)]);}
        audit_event($user,(string)$fresh['id'],'bank_post_operation.started','bank_bulk_operation',$id,['selectedCount'=>count($ids),'decisionsHash'=>$hash]);
        return ['operation'=>tegh_bank_operation_load($user,$fresh,$key,true),'replay'=>false];
    }));
    return tegh_bank_operation_receipt($out['operation'])+['idempotentReplay'=>$out['replay']];
}
function tegh_bank_operation_step(array $user,array $company,array $input): array
{
    $key=tegh_bank_operation_key($input['operationKey']??null);$claimedId=null;
    try{
        $operation=tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$company,$key,&$claimedId){
            $fresh=tegh_bank_reauthorize_mutation($user,$company);$op=tegh_bank_operation_load($user,$fresh,$key,true);if(!$op)fail('Posting operation not found.',404,'operation_not_found');
            $q=db()->prepare("SELECT * FROM bank_bulk_operation_rows WHERE operation_id=? AND status='pending' ORDER BY ordinal LIMIT 1 FOR UPDATE");$q->execute([$op['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)return $op;
            $claimedId=(string)$row['transaction_id'];$decision=json_decode((string)$row['decision_json'],true,512,JSON_THROW_ON_ERROR);
            $q=db()->prepare('SELECT * FROM bank_transactions WHERE company_id=? AND id=? FOR UPDATE');$q->execute([$fresh['id'],$claimedId]);if(!($lockedTx=$q->fetch(PDO::FETCH_ASSOC)))fail('The transaction is no longer available.',409,'transaction_unavailable');
            $existing=bank_transaction_existing_post_result($fresh,$decision);
            if($existing!==null){$result=$existing;$status='already_posted';}
            else{
                if(!isset($decision['_expectedStateHash'])||!hash_equals($decision['_expectedStateHash'],tegh_bank_operation_state_hash($lockedTx)))fail('The transaction changed after this operation was confirmed. Review it before starting a new operation.',409,'bank_post_state_stale');
                bank_transaction_post_service($user,$fresh,[$decision],'manual',false);
                $result=bank_transaction_existing_post_result($fresh,$decision);if($result===null||empty($result['journalEntryId']))throw new RuntimeException('The posting service did not return a verifiable journal.');
                $result['status']='posted';$result['idempotent']=false;$status='posted';
            }
            $result['operationKey']=$key;db()->prepare('UPDATE bank_bulk_operation_rows SET status=?,result_json=?,attempts=attempts+1,updated_at=UTC_TIMESTAMP() WHERE operation_id=? AND transaction_id=?')->execute([$status,tegh_json_canonical($result),$op['id'],$claimedId]);
            $q=db()->prepare("SELECT COUNT(*) FROM bank_bulk_operation_rows WHERE operation_id=? AND status='pending'");$q->execute([$op['id']]);$remaining=(int)$q->fetchColumn();
            $q=db()->prepare("SELECT COUNT(*) FROM bank_bulk_operation_rows WHERE operation_id=? AND status='failed'");$q->execute([$op['id']]);$failed=(int)$q->fetchColumn();
            db()->prepare('UPDATE bank_bulk_operations SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$remaining?'active':($failed?'completed_with_issues':'completed'),$op['id']]);
            if(!$remaining)audit_event($user,(string)$fresh['id'],'bank_post_operation.completed','bank_bulk_operation',(string)$op['id'],['decisionsHash'=>$op['payload_hash']]);
            return tegh_bank_operation_load($user,$fresh,$key,true);
        }));
    }catch(TeghServiceFailure $e){
        // Permission/scope failures never alter a receipt. A deterministic row
        // failure is persisted only after the accounting transaction rolled back.
        if($claimedId===null||in_array($e->httpStatus,[401,403,503],true))throw $e;
        $operation=tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$company,$key,$claimedId,$e){
            $fresh=tegh_bank_reauthorize_mutation($user,$company);$op=tegh_bank_operation_load($user,$fresh,$key,true);if(!$op)throw $e;
            $q=db()->prepare("UPDATE bank_bulk_operation_rows SET status='failed',result_json=?,attempts=attempts+1,updated_at=UTC_TIMESTAMP() WHERE operation_id=? AND transaction_id=? AND status='pending'");
            $q->execute([tegh_json_canonical(['status'=>'failed','code'=>$e->errorCode,'message'=>$e->getMessage(),'accountingCommitted'=>false]),$op['id'],$claimedId]);
            $q=db()->prepare("SELECT COUNT(*) FROM bank_bulk_operation_rows WHERE operation_id=? AND status='pending'");$q->execute([$op['id']]);$remaining=(int)$q->fetchColumn();
            db()->prepare('UPDATE bank_bulk_operations SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$remaining?'active':'completed_with_issues',$op['id']]);return tegh_bank_operation_load($user,$fresh,$key,true);
        }));
    }
    return tegh_bank_operation_receipt($operation);
}
function tegh_bank_operation_retry(array $user,array $company,array $input): array
{
    if(($input['confirmed']??false)!==true)fail('Confirm retry of failed decisions only.',422,'confirmation_required');$key=tegh_bank_operation_key($input['operationKey']??null);
    $op=tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$company,$key){$fresh=tegh_bank_reauthorize_mutation($user,$company);$op=tegh_bank_operation_load($user,$fresh,$key,true);if(!$op)fail('Posting operation not found.',404,'operation_not_found');
        db()->prepare("UPDATE bank_bulk_operation_rows SET status='pending',result_json=NULL,updated_at=UTC_TIMESTAMP() WHERE operation_id=? AND status='failed'")->execute([$op['id']]);
        db()->prepare("UPDATE bank_bulk_operations SET status='active',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$op['id']]);audit_event($user,(string)$fresh['id'],'bank_post_operation.retry_requested','bank_bulk_operation',(string)$op['id'],['decisionsHash'=>$op['payload_hash']]);return tegh_bank_operation_load($user,$fresh,$key,true);}));return tegh_bank_operation_receipt($op);
}
function handle_tegh_bank_operations(string $action): never
{
    $user=require_user();$company=require_company($user);require_company_permission($company,'banking.match');tegh_schema44_require();header('Cache-Control: private, no-store');
    if($action==='status'){require_method('GET');$key=tegh_bank_operation_key($_GET['operationKey']??null);$op=tegh_bank_operation_load($user,$company,$key);if(!$op)fail('Posting operation not found.',404,'operation_not_found');json_response(tegh_bank_operation_receipt($op));}
    require_method('POST');require_csrf();$input=request_json();
    try{$result=match($action){'start'=>tegh_bank_operation_start($user,$company,$input),'step'=>tegh_bank_operation_step($user,$company,$input),'retry-failed'=>tegh_bank_operation_retry($user,$company,$input),default=>throw new TeghServiceFailure('Posting operation action not found.',404,'route_not_found')};}catch(TeghServiceFailure $e){tegh_fail_service($e);}json_response($result);
}
