<?php
declare(strict_types=1);

/** Bounded bank-register reads. This module contains no accounting mutations. */
function tegh_bank_list_filters(array $input): array
{
    $allowed=['route','v','companyId','status','q','from','to','direction','minCents','maxCents','bankAccountId','categoryId','limit','cursor','focusId','ids','confidence','batchId'];
    foreach($input as $key=>$value)if(!in_array($key,$allowed,true)||!is_scalar($value))throw new InvalidArgumentException('An unsupported bank filter was supplied.');
    $f=['confidence'=>(string)($input['confidence']??''),'batchId'=>(string)($input['batchId']??''),'status'=>(string)($input['status']??'all'),'q'=>trim((string)($input['q']??'')),'from'=>(string)($input['from']??''),'to'=>(string)($input['to']??''),'direction'=>(string)($input['direction']??'all'),'minCents'=>null,'maxCents'=>null,'bankAccountId'=>(string)($input['bankAccountId']??''),'categoryId'=>(string)($input['categoryId']??''),'ids'=>[]];
    if(!in_array($f['status'],['all','pending','ready','posted','excluded','duplicate'],true)||!in_array($f['direction'],['all','debit','credit'],true))throw new InvalidArgumentException('Choose a valid status and direction.');
    if(!preg_match('//u',$f['q'])||preg_match_all('/./us',$f['q'])>200)throw new InvalidArgumentException('Search must contain at most 200 characters.');
    foreach(['from','to'] as $key){$value=$f[$key];if($value!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)||!checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4))))throw new InvalidArgumentException('Choose valid dates.');}
    if($f['from']!==''&&$f['to']!==''&&$f['from']>$f['to'])throw new InvalidArgumentException('From must be on or before To.');
    foreach(['minCents','maxCents'] as $key){$value=(string)($input[$key]??'');if($value!==''){if(!preg_match('/^\d{1,15}$/D',$value)||(float)$value>9007199254740991)throw new InvalidArgumentException('Amounts must be non-negative whole cents.');$f[$key]=(int)$value;}}
    if($f['minCents']!==null&&$f['maxCents']!==null&&$f['minCents']>$f['maxCents'])throw new InvalidArgumentException('Minimum amount must not exceed maximum amount.');
    if(!in_array($f['confidence'],['','high'],true))throw new InvalidArgumentException('Choose a valid confidence filter.');
    if($f['status']==='ready'){$f['status']='pending';$f['confidence']='high';}
    foreach(['bankAccountId','categoryId','batchId'] as $key)if($f[$key]!==''&&!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$f[$key]))throw new InvalidArgumentException('Choose a valid account.');
    $ids=trim((string)($input['ids']??''));$focus=trim((string)($input['focusId']??''));if($focus!=='')$ids=$focus;
    if($ids!==''){foreach(explode(',',$ids) as $id){$id=trim($id);if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id))throw new InvalidArgumentException('Choose valid bank transactions.');$f['ids'][]=$id;}$f['ids']=array_values(array_unique($f['ids']));if(count($f['ids'])>100)throw new InvalidArgumentException('Choose at most 100 bank transactions.');sort($f['ids']);}
    $limit=(string)($input['limit']??'50');if(!preg_match('/^\d{1,3}$/D',$limit)||(int)$limit<1||(int)$limit>100)throw new InvalidArgumentException('Page size must be between 1 and 100.');
    return ['filters'=>$f,'limit'=>(int)$limit,'cursor'=>(string)($input['cursor']??'')];
}

/** A Ready row has one effective category that direct-GL posting can still
 * accept. Transfers remain an explicit review decision because their
 * destination is a protected control account. */
function tegh_bank_ready_predicate(): string
{
    return "bt.status = 'pending'
      AND review_account.id IS NOT NULL
      AND review_account.active = 1
      AND review_account.is_control = 0
      AND review_account.code NOT IN ('9999','1050','1100','1110','1200','2050','2100','2110','2300','2310','2320','2330','2340','2350','3200')
      AND bt.confidence >= ".TEGH_BANK_READY_CONFIDENCE."
      AND NOT EXISTS(SELECT 1 FROM bank_accounts linked_bank WHERE linked_bank.company_id=bt.company_id AND linked_bank.ledger_account_id=review_account.id)
      AND NOT EXISTS(SELECT 1 FROM company_system_accounts cs WHERE cs.company_id=bt.company_id AND cs.account_id=review_account.id AND cs.status='active')
      AND bank_ledger.id IS NOT NULL
      AND bank_ledger.active = 1
      AND bank_ledger.code <> '9999'
      AND ((bt.amount_cents > 0 AND review_account.account_type IN ('income','asset','liability','equity'))
        OR (bt.amount_cents < 0 AND review_account.account_type IN ('expense','asset','liability','equity')))
      AND (review_company.tax_registered = 0 OR review_company.tax_rate_bps = 0
        OR bt.tax_code NOT IN ('GST_HST','HST13','GST_HST_PST')
        OR NOT ((bt.amount_cents > 0 AND review_account.account_type = 'income')
          OR (bt.amount_cents < 0 AND review_account.account_type IN ('expense','asset')))
        OR (bt.amount_cents > 0 AND gst_collected.id IS NOT NULL AND gst_collected.active = 1)
        OR (bt.amount_cents < 0 AND gst_recoverable.id IS NOT NULL AND gst_recoverable.active = 1))
      AND (review_company.pst_registered = 0 OR review_company.pst_rate_bps = 0
        OR bt.tax_code NOT IN ('PST','GST_HST_PST')
        OR NOT ((bt.amount_cents > 0 AND review_account.account_type = 'income')
          OR (bt.amount_cents < 0 AND review_account.account_type IN ('expense','asset')))
        OR (bt.amount_cents > 0 AND pst_collected.id IS NOT NULL AND pst_collected.active = 1)
        OR (bt.amount_cents < 0 AND (review_company.pst_recoverable = 0
          OR (pst_recoverable.id IS NOT NULL AND pst_recoverable.active = 1))))";
}

function tegh_bank_list_where(string $companyId,array $f): array
{
    $where=['bt.company_id = ?'];$params=[$companyId];
    if($f['confidence']==='high')$where[]=tegh_bank_ready_predicate();
    if($f['batchId']!==''){$where[]='bt.import_batch_id = ?';$params[]=$f['batchId'];}
    if($f['status']==='ready')$where[]=tegh_bank_ready_predicate();
    elseif($f['status']!==''&&$f['status']!=='all'){$where[]='bt.status = ?';$params[]=$f['status'];}
    if($f['bankAccountId']!==''){$where[]='bt.bank_account_id = ?';$params[]=$f['bankAccountId'];}
    if($f['categoryId']!==''){$where[]='COALESCE(bt.decided_account_id,bt.suggested_account_id) = ?';$params[]=$f['categoryId'];}
    foreach(['from'=>'>=','to'=>'<='] as $key=>$operator)if($f[$key]!==''){$where[]="bt.transaction_date $operator ?";$params[]=$f[$key];}
    if($f['direction']!=='all')$where[]=$f['direction']==='debit'?'bt.amount_cents < 0':'bt.amount_cents > 0';
    foreach(['minCents'=>'>=','maxCents'=>'<='] as $key=>$operator)if($f[$key]!==null){$where[]="ABS(bt.amount_cents) $operator ?";$params[]=$f[$key];}
    if($f['ids']){$where[]='bt.id IN ('.implode(',',array_fill(0,count($f['ids']),'?')).')';array_push($params,...$f['ids']);}
    if($f['q']!==''){
        $like='%'.strtr($f['q'],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
        $textColumns=['bt.description','bt.normalized_merchant','bt.reference','bt.id','ba.name','ib.filename','suggested.name','suggested.code','decided.name','decided.code'];
        $alternatives=[];foreach($textColumns as $column){$alternatives[]="$column LIKE ? ESCAPE '!'";$params[]=$like;}
        $alternatives[]="EXISTS(SELECT 1 FROM vouchers sv WHERE sv.company_id=bt.company_id AND sv.source_type='bank_transaction' AND sv.source_id=bt.id AND sv.voucher_number LIKE ? ESCAPE '!')";$params[]=$like;
        if(schema_table_exists('bank_transaction_source_text')){$alternatives[]="EXISTS(SELECT 1 FROM bank_transaction_source_text st WHERE st.company_id=bt.company_id AND st.transaction_id=bt.id AND st.full_description LIKE ? ESCAPE '!')";$params[]=$like;}
        $typed=tegh_bank_search_typed($f['q']);
        if($typed['date']!==null){$alternatives[]='bt.transaction_date = ?';$params[]=$typed['date'];}
        if($typed['amountCents']!==null){$alternatives[]=$typed['signed']?'bt.amount_cents = ?':'ABS(bt.amount_cents) = ?';$params[]=$typed['amountCents'];}
        $where[]='('.implode(' OR ',$alternatives).')';
    }
    return [implode(' AND ',$where),$params];
}

function tegh_bank_list_cursor(array $row,string $scope): string
{
    return rtrim(strtr(base64_encode(json_encode(['scope'=>$scope,'date'=>(string)$row['transaction_date'],'created'=>(string)$row['created_at'],'id'=>(string)$row['id']],JSON_THROW_ON_ERROR)),'+/','-_'),'=');
}

function tegh_bank_list_decode_cursor(string $token,string $scope): ?array
{
    if($token==='')return null;
    if(strlen($token)>1024||!preg_match('/^[A-Za-z0-9_-]+$/D',$token))throw new InvalidArgumentException('The page link is invalid. Refresh the list.');
    $raw=base64_decode(strtr($token,'-_','+/'),true);$c=$raw===false?null:json_decode($raw,true);
    if(!is_array($c)||!hash_equals($scope,(string)($c['scope']??''))||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)($c['date']??''))||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D',(string)($c['created']??''))||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)($c['id']??'')))throw new InvalidArgumentException('The page link does not match this company and its filters. Refresh the list.');
    return $c;
}

function tegh_bank_list_execute(PDOStatement $statement,array $params): void
{
    foreach(array_values($params) as $index=>$value)$statement->bindValue($index+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $statement->execute();
}

function tegh_bank_register_data(array $company,array $input): array
{
    $started=hrtime(true);$query=tegh_bank_list_filters($input);$f=$query['filters'];$limit=$query['limit'];$companyId=(string)$company['id'];
    $scope=hash('sha256',json_encode([$companyId,$f,$limit],JSON_THROW_ON_ERROR));$cursor=tegh_bank_list_decode_cursor($query['cursor'],$scope);
    $joins=' FROM bank_transactions bt JOIN companies review_company ON review_company.id=bt.company_id JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id LEFT JOIN import_batches ib ON ib.id=bt.import_batch_id AND ib.company_id=bt.company_id LEFT JOIN accounts suggested ON suggested.id=bt.suggested_account_id AND suggested.company_id=bt.company_id LEFT JOIN accounts decided ON decided.id=bt.decided_account_id AND decided.company_id=bt.company_id LEFT JOIN accounts review_account ON review_account.id=COALESCE(bt.decided_account_id,bt.suggested_account_id) AND review_account.company_id=bt.company_id LEFT JOIN accounts bank_ledger ON bank_ledger.id=ba.ledger_account_id AND bank_ledger.company_id=bt.company_id LEFT JOIN accounts gst_recoverable ON gst_recoverable.company_id=bt.company_id AND gst_recoverable.code=\'1100\' LEFT JOIN accounts pst_recoverable ON pst_recoverable.company_id=bt.company_id AND pst_recoverable.code=\'1110\' LEFT JOIN accounts gst_collected ON gst_collected.company_id=bt.company_id AND gst_collected.code=\'2100\' LEFT JOIN accounts pst_collected ON pst_collected.company_id=bt.company_id AND pst_collected.code=\'2110\'';
    [$where,$params]=tegh_bank_list_where($companyId,$f);$pdo=db();$ownTransaction=!$pdo->inTransaction();
    if($ownTransaction){if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();}
    try{
        $readyPredicate=tegh_bank_ready_predicate();$countFilters=$f;$countFilters['status']='all';[$countWhere,$countParams]=tegh_bank_list_where($companyId,$countFilters);
        $stmt=$pdo->prepare("SELECT bt.status,COUNT(*) row_count,COALESCE(SUM(CASE WHEN $readyPredicate THEN 1 ELSE 0 END),0) ready_count".$joins.' WHERE '.$countWhere.' GROUP BY bt.status');tegh_bank_list_execute($stmt,$countParams);$counts=['all'=>0,'pending'=>0,'ready'=>0,'posted'=>0,'excluded'=>0,'duplicate'=>0];foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){$counts[$row['status']]=(int)$row['row_count'];$counts['ready']+=(int)$row['ready_count'];$counts['all']+=(int)$row['row_count'];}
        $stmt=$pdo->prepare('SELECT COUNT(*)'.$joins.' WHERE '.$where);tegh_bank_list_execute($stmt,$params);$filteredCount=(int)$stmt->fetchColumn();
        $pageWhere=$where;$pageParams=$params;
        if($cursor){$pageWhere.=' AND (bt.transaction_date < ? OR (bt.transaction_date = ? AND bt.created_at < ?) OR (bt.transaction_date = ? AND bt.created_at = ? AND bt.id < ?))';array_push($pageParams,$cursor['date'],$cursor['date'],$cursor['created'],$cursor['date'],$cursor['created'],$cursor['id']);}
        $sql="SELECT bt.id,bt.bank_account_id,bt.import_batch_id,bt.transaction_date,bt.created_at,bt.description,bt.reference,bt.remarks,bt.source_row_number,bt.source_sequence,bt.source_running_balance_cents,bt.amount_cents,bt.foreign_amount_cents,bt.exchange_rate_micros,bt.currency,bt.status,bt.journal_entry_id,bt.decided_account_id,bt.suggested_account_id,bt.tax_code,bt.confidence,bt.ai_explanation,bt.suggestion_source,CASE WHEN $readyPredicate THEN 1 ELSE 0 END review_ready,ib.filename import_filename,ib.file_type import_file_type,ib.created_at imported_at,suggested.name suggested_account_name,decided.name decided_account_name,ba.name bank_account_name,(SELECT MAX(v.voucher_number) FROM vouchers v WHERE v.company_id=bt.company_id AND v.source_type='bank_transaction' AND v.source_id=bt.id) transaction_number".$joins.' WHERE '.$pageWhere.' ORDER BY bt.transaction_date DESC,bt.created_at DESC,bt.id DESC LIMIT '.($limit+1);
        $stmt=$pdo->prepare($sql);tegh_bank_list_execute($stmt,$pageParams);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);$next=$hasMore?tegh_bank_list_cursor($rows[count($rows)-1],$scope):null;
        $stmt=$pdo->prepare('SELECT id,name,masked_number,currency,active,account_type,ledger_account_id FROM bank_accounts WHERE company_id=? ORDER BY name,id');$stmt->execute([$companyId]);$banks=array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'name'=>(string)$r['name'],'maskedNumber'=>(string)($r['masked_number']??''),'currency'=>(string)$r['currency'],'active'=>(bool)$r['active'],'accountType'=>(string)$r['account_type'],'ledgerAccountId'=>(string)$r['ledger_account_id']],$stmt->fetchAll(PDO::FETCH_ASSOC));
        $catalogue=tegh_bank_category_catalogue($companyId);$accounts=array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'code'=>(string)$r['code'],'name'=>(string)$r['name'],'type'=>(string)$r['account_type'],'active'=>(bool)$r['active'],'isControl'=>(bool)$r['is_control'],'linkedBankId'=>$r['linked_bank_id'],'systemControl'=>(bool)$r['system_control'],'postingCapability'=>(string)($r['posting_capability']??'ordinary_gl'),'postingAllowed'=>(bool)($r['posting_allowed']??false),'requiredContext'=>array_values((array)($r['required_context']??[])),'capabilityReason'=>(string)($r['capability_reason']??''),'incomingReason'=>tegh_bank_account_reason($r,1),'outgoingReason'=>tegh_bank_account_reason($r,-1),'generalReason'=>tegh_bank_account_reason($r,1,true)],$catalogue);
        if($ownTransaction)$pdo->commit();
    }catch(Throwable $error){if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
    $transactions=array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'bankAccountId'=>(string)$r['bank_account_id'],'bankAccountName'=>(string)$r['bank_account_name'],'importBatchId'=>$r['import_batch_id'],'importFilename'=>$r['import_filename'],'importFileType'=>$r['import_file_type'],'importedAt'=>$r['imported_at'],'transactionDate'=>(string)$r['transaction_date'],'description'=>(string)$r['description'],'reference'=>$r['reference'],'remarks'=>(string)($r['remarks']??''),'sourceRowNumber'=>$r['source_row_number']===null?null:(int)$r['source_row_number'],'sourceSequence'=>$r['source_sequence']===null?null:(int)$r['source_sequence'],'runningBalanceCents'=>$r['source_running_balance_cents']===null?null:(int)$r['source_running_balance_cents'],'runningBalanceSource'=>$r['source_running_balance_cents']===null?'unavailable':'source','amountCents'=>(int)$r['amount_cents'],'foreignAmountCents'=>(int)$r['foreign_amount_cents'],'exchangeRateMicros'=>(int)$r['exchange_rate_micros'],'currency'=>(string)$r['currency'],'status'=>(string)$r['status'],'reviewState'=>(string)$r['status'],'highConfidenceEligible'=>(bool)$r['review_ready'],'journalEntryId'=>$r['journal_entry_id'],'transactionNumber'=>$r['transaction_number'],'decidedAccountId'=>$r['decided_account_id'],'suggestedAccountId'=>$r['suggested_account_id'],'decidedAccountName'=>$r['decided_account_name'],'suggestedAccountName'=>$r['suggested_account_name'],'taxCode'=>(string)$r['tax_code'],'confidence'=>(int)$r['confidence'],'aiExplanation'=>$r['ai_explanation'],'suggestionSource'=>(string)$r['suggestion_source']],$rows);
    return ['organization'=>['id'=>$companyId,'currency'=>(string)$company['currency']],'bankTransactions'=>$transactions,'bankAccounts'=>$banks,'accounts'=>$accounts,'counts'=>$counts,'readyConfidenceThreshold'=>TEGH_BANK_READY_CONFIDENCE,'pagination'=>['limit'=>$limit,'returned'=>count($rows),'filteredCount'=>$filteredCount,'overallCount'=>$counts['all'],'hasMore'=>$hasMore,'nextCursor'=>$next,'order'=>'transactionDate DESC, createdAt DESC, id DESC','scope'=>'All matching company bank transactions','liveResults'=>true],'filters'=>$f,'verifiedAt'=>gmdate('c'),'amountFilterCurrency'=>(string)$company['currency'],'accountingWrites'=>0,'performance'=>['queryCount'=>5,'durationMs'=>round((hrtime(true)-$started)/1e6,2)]];
}

function handle_tegh_bank_register(): never
{
    require_method('GET');$user=require_user();$company=require_company($user);require_company_permission($company,'banking.view');
    try{$result=tegh_bank_register_data($company,$_GET);}catch(InvalidArgumentException $error){fail($error->getMessage(),422,'bank_filter_invalid');}
    header('Cache-Control: private, no-store');json_response($result);
}
