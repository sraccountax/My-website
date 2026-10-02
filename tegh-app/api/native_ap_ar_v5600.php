<?php
declare(strict_types=1);

/**
 * Tegh 5.6.0 Native AP/AR, company-bound private intake, draft import and
 * reviewed vendor-recognition learning.
 *
 * The specialist collectors only read accounting tables. This service writes
 * agent/document/draft metadata, existing task/audit records and, after one
 * explicit human send, the existing outbound-email and invoice-followup rows.
 * It never creates, posts, issues, pays or changes an accounting document.
 */

const NATIVE_DOCUMENT_TYPES = ['vendor_bill','customer_invoice'];
const NATIVE_DOCUMENT_STATES = ['uploaded','extracting','review','ready','prepared','linked','dismissed','stale','failed'];
const NATIVE_COLLECTION_STATES = ['draft','approved','sending','sent','failed','stale','dismissed'];
const NATIVE_COLLECTION_TONES = ['friendly','firm','final'];
const NATIVE_COLLECTION_LENGTHS = ['concise','standard','detailed'];
const NATIVE_COLLECTION_PLACEHOLDERS = ['customer_contact','customer_name','invoice_number','invoice_date','due_date','days_overdue','open_balance','currency','company_name','payment_instructions','business_email','business_phone'];
const NATIVE_DOCUMENT_FAILURE_CODES = ['ocr_manifest_unavailable','ocr_manifest_integrity_failed','ocr_manifest_identity_failed','ocr_manifest_asset_invalid','ocr_manifest_group_invalid','ocr_runtime_group_invalid','ocr_runtime_origin_invalid','ocr_runtime_asset_unavailable','ocr_runtime_mime_invalid','ocr_runtime_integrity_failed','ocr_runtime_unavailable','ocr_extraction_failed'];

function native_ap_ar_cursor(string $cursor): array
{
    if($cursor==='')return [];
    $decoded=json_decode($cursor,true);
    return is_array($decoded)&&count($decoded)===2?array_values($decoded):[];
}

function native_ap_ar_days_between(string $from,string $to): int
{
    $left=new DateTimeImmutable(substr($from,0,10));$right=new DateTimeImmutable(substr($to,0,10));
    return (int)$left->diff($right)->format('%r%a');
}

function native_ap_ar_records(array ...$records): array
{
    return array_values(array_filter($records,static fn(array $record):bool=>(string)($record['id']??'')!==''));
}

function native_agent_accounts_payable_collect(array $company,array $policy,string $cursor,string $sourceRevision): array
{
    $companyId=(string)$company['id'];$limit=(int)$policy['thresholds']['batchSize'];$cursorValue=native_ap_ar_cursor($cursor);$params=[$companyId];
    $where='b.company_id=?';if($cursorValue){$where.=' AND (b.updated_at>? OR (b.updated_at=? AND b.id>?))';$params[]=(string)$cursorValue[0];$params[]=(string)$cursorValue[0];$params[]=(string)$cursorValue[1];}
    $stmt=db()->prepare("SELECT b.id,b.vendor_id,b.number,b.bill_date,b.due_date,b.status,b.category_account_id,b.total_cents,b.balance_cents,b.currency,b.import_reference,b.memo,b.created_at,b.updated_at,
        v.name vendor_name,v.status vendor_status,v.active vendor_active,v.email vendor_email,
        a.code category_code,a.name category_name,a.active category_active,a.is_control category_control
      FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id
      LEFT JOIN accounts a ON a.id=b.category_account_id AND a.company_id=b.company_id
      WHERE $where ORDER BY b.updated_at,b.id LIMIT ".($limit+1));
    $stmt->execute($params);$rows=$stmt->fetchAll();$hasMore=count($rows)>$limit;if($hasMore)$rows=array_slice($rows,0,$limit);
    $today=canadian_today();$upcoming=(new DateTimeImmutable($today))->modify('+'.(int)$policy['thresholds']['apUpcomingDays'].' days')->format('Y-m-d');$findings=[];$duplicateGroups=[];
    foreach($rows as $row){
        $records=native_ap_ar_records(['type'=>'bill','id'=>(string)$row['id']],['type'=>'vendor','id'=>(string)$row['vendor_id']]);
        $evidence=['records'=>$records,'affectedCount'=>1,'billId'=>(string)$row['id'],'vendorId'=>(string)$row['vendor_id'],'number'=>(string)$row['number'],'billDate'=>(string)$row['bill_date'],'dueDate'=>(string)$row['due_date'],'status'=>(string)$row['status'],'totalCents'=>(int)$row['total_cents'],'balanceCents'=>(int)$row['balance_cents'],'currency'=>(string)$row['currency'],'accountingWrites'=>0,'providerAttempts'=>0];
        if((string)$row['status']==='draft'){
            $age=max(0,native_ap_ar_days_between((string)$row['updated_at'],$today));
            if($age>=(int)$policy['staleDays'])$findings[]=native_agent_finding_spec('accounts_payable','stale_draft_bill',(string)$row['id'],'warning',10000,'Vendor bill draft needs review','Bill '.(string)$row['number'].' for '.(string)$row['vendor_name'].' has remained a draft for '.$age.' days. Review it in the normal Vendor Invoice workspace; Tegh did not post it.',array_merge($evidence,['ageDays'=>$age]),'nav.vendor_invoices',[],$row['updated_at']);
        }
        if((string)$row['status']==='open'&&(int)$row['balance_cents']>0){
            if((string)$row['due_date']<$today){
                $overdue=max(1,native_ap_ar_days_between((string)$row['due_date'],$today));$stale=$overdue>=(int)$policy['staleDays'];
                $severity=$stale||((int)$row['balance_cents']>=(int)$policy['materialityCents'])?'critical':'warning';
                $findings[]=native_agent_finding_spec('accounts_payable',$stale?'stale_unpaid_bill':'overdue_bill',(string)$row['id'],$severity,10000,($stale?'Stale unpaid':'Overdue').' vendor bill','Bill '.(string)$row['number'].' for '.(string)$row['vendor_name'].' is '.$overdue.' day'.($overdue===1?'':'s').' overdue with an open balance. Review payment readiness; Tegh did not create a payment.',array_merge($evidence,['overdueDays'=>$overdue]),'nav.vendor_payments',[],$row['due_date'].' 23:59:59');
            }elseif((string)$row['due_date']<=$upcoming){
                $days=max(0,native_ap_ar_days_between($today,(string)$row['due_date']));$severity=(int)$row['balance_cents']>=(int)$policy['materialityCents']?'warning':'info';
                $findings[]=native_agent_finding_spec('accounts_payable','upcoming_bill',(string)$row['id'],$severity,10000,'Vendor bill due soon','Bill '.(string)$row['number'].' for '.(string)$row['vendor_name'].' is due in '.$days.' day'.($days===1?'':'s').'. Review the verified bill; Tegh did not schedule or create a payment.',array_merge($evidence,['daysUntilDue'=>$days]),'nav.vendor_invoices',[],$row['due_date'].' 23:59:59');
            }
        }
        $codingIssues=[];if((string)$row['vendor_status']!=='active'||!(bool)$row['vendor_active'])$codingIssues[]='vendor is not active';if($row['category_account_id']===null||$row['category_code']===null)$codingIssues[]='category account is unavailable';elseif(!(bool)$row['category_active']||(bool)$row['category_control'])$codingIssues[]='category account is inactive or protected';
        if($codingIssues)$findings[]=native_agent_finding_spec('accounts_payable','payable_coding_exception',(string)$row['id'],'warning',10000,'Vendor bill coding needs review','Bill '.(string)$row['number'].' has a verified master-data or coding exception: '.implode('; ',$codingIssues).'. Review the existing bill; Tegh made no change.',array_merge($evidence,['issues'=>$codingIssues]),'nav.vendor_invoices',[],$row['updated_at']);
        if(!in_array((string)$row['status'],['void','paid'],true))$duplicateGroups[(string)$row['vendor_id'].'|'.(string)$row['bill_date'].'|'.(string)$row['total_cents']][]=$row;
    }
    foreach($duplicateGroups as $key=>$group){if(count($group)<2)continue;$ids=array_map(static fn(array $row):string=>(string)$row['id'],$group);sort($ids,SORT_STRING);$records=array_map(static fn(string $id):array=>['type'=>'bill','id'=>$id],$ids);$first=$group[0];$findings[]=native_agent_finding_spec('accounts_payable','possible_duplicate_bill',implode('|',$ids),'warning',9000,'Possible duplicate vendor bills','Two or more non-final vendor bills share the same vendor, bill date and exact total. Review the source evidence before keeping or posting either record.',['records'=>$records,'affectedCount'=>count($ids),'vendorId'=>(string)$first['vendor_id'],'billDate'=>(string)$first['bill_date'],'totalCents'=>(int)$first['total_cents'],'rule'=>'same vendor + bill date + exact total','accountingWrites'=>0,'providerAttempts'=>0],'nav.vendor_invoices',[],$first['updated_at']);}
    $documentCount=0;if(!$cursorValue){$docs=db()->prepare("SELECT id,original_name,state,updated_at FROM native_agent_documents WHERE company_id=? AND document_type='vendor_bill' AND state IN ('uploaded','review','ready','prepared') ORDER BY updated_at,id LIMIT 100");$docs->execute([$companyId]);foreach($docs->fetchAll() as $doc){$documentCount++;$findings[]=native_agent_finding_spec('accounts_payable','vendor_document_review',(string)$doc['id'],in_array((string)$doc['state'],['uploaded','review'],true)?'warning':'info',10000,'Vendor document waiting for review',(string)$doc['original_name'].' is in '.(string)$doc['state'].' state in the private Document Intake workspace. Local extraction never posts a bill.',['records'=>[['type'=>'native_agent_document','id'=>(string)$doc['id']]],'affectedCount'=>1,'documentState'=>(string)$doc['state'],'accountingWrites'=>0,'providerAttempts'=>0],'nav.document_intake',[],$doc['updated_at']);}}
    $last=$rows?end($rows):null;$next=$hasMore&&is_array($last)?[(string)$last['updated_at'],(string)$last['id']]:[];
    return ['sourceRevisionHash'=>$sourceRevision,'findings'=>$findings,'processedCount'=>count($rows)+$documentCount,'cursor'=>['current'=>$cursor,'next'=>$next?native_agent_json($next):'','cycleComplete'=>!$hasMore],'summary'=>['billsChecked'=>count($rows),'documentsChecked'=>$documentCount,'findings'=>count($findings),'accountingWrites'=>0,'providerAttempts'=>0]];
}

function native_ap_ar_collection_level(int $overdueDays,array $policy): string
{
    if($overdueDays>=(int)$policy['thresholds']['collectionFinalDays'])return 'final';
    if($overdueDays>=(int)$policy['thresholds']['collectionFirmDays'])return 'firm';
    return 'friendly';
}

function native_agent_accounts_receivable_collect(array $company,array $policy,string $cursor,string $sourceRevision): array
{
    $companyId=(string)$company['id'];$limit=(int)$policy['thresholds']['batchSize'];$cursorValue=native_ap_ar_cursor($cursor);$params=[$companyId];$where='i.company_id=?';
    if($cursorValue){$where.=' AND (i.updated_at>? OR (i.updated_at=? AND i.id>?))';$params[]=(string)$cursorValue[0];$params[]=(string)$cursorValue[0];$params[]=(string)$cursorValue[1];}
    $stmt=db()->prepare("SELECT i.id,i.customer_id,i.number,i.issue_date,i.due_date,i.status,i.total_cents,i.balance_cents,i.currency,i.import_reference,i.message,i.created_at,i.updated_at,
        c.name customer_name,c.email customer_email,c.status customer_status,c.active customer_active,c.hold_remarks,
        (SELECT MAX(f.action_date) FROM invoice_followups f WHERE f.company_id=i.company_id AND f.invoice_id=i.id) last_followup_date,
        (SELECT d.id FROM native_agent_collection_drafts d WHERE d.company_id=i.company_id AND d.invoice_id=i.id AND d.state IN ('draft','approved','sending','sent') ORDER BY d.updated_at DESC LIMIT 1) collection_draft_id
      FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id
      WHERE $where ORDER BY i.updated_at,i.id LIMIT ".($limit+1));
    $stmt->execute($params);$rows=$stmt->fetchAll();$hasMore=count($rows)>$limit;if($hasMore)$rows=array_slice($rows,0,$limit);$today=canadian_today();$findings=[];$duplicateGroups=[];
    foreach($rows as $row){
        $records=native_ap_ar_records(['type'=>'invoice','id'=>(string)$row['id']],['type'=>'customer','id'=>(string)$row['customer_id']],['type'=>'collection_draft','id'=>(string)($row['collection_draft_id']??'')]);
        $evidence=['records'=>$records,'affectedCount'=>1,'invoiceId'=>(string)$row['id'],'customerId'=>(string)$row['customer_id'],'number'=>(string)$row['number'],'issueDate'=>(string)$row['issue_date'],'dueDate'=>(string)$row['due_date'],'status'=>(string)$row['status'],'totalCents'=>(int)$row['total_cents'],'balanceCents'=>(int)$row['balance_cents'],'currency'=>(string)$row['currency'],'lastFollowupDate'=>$row['last_followup_date'],'accountingWrites'=>0,'providerAttempts'=>0];
        if((string)$row['status']==='draft'){$age=max(0,native_ap_ar_days_between((string)$row['updated_at'],$today));if($age>=(int)$policy['staleDays'])$findings[]=native_agent_finding_spec('accounts_receivable','stale_draft_invoice',(string)$row['id'],'warning',10000,'Customer invoice draft needs review','Invoice '.(string)$row['number'].' for '.(string)$row['customer_name'].' has remained a draft for '.$age.' days. Review it in the normal Customer Invoice workspace; Tegh did not issue it.',array_merge($evidence,['ageDays'=>$age]),'nav.customer_invoices',[],$row['updated_at']);}
        if((string)$row['status']==='sent'&&(int)$row['balance_cents']>0&&(string)$row['due_date']<$today){
            $overdue=max(1,native_ap_ar_days_between((string)$row['due_date'],$today));$stale=$overdue>=(int)$policy['staleDays'];$severity=$stale||((int)$row['balance_cents']>=(int)$policy['materialityCents'])?'critical':'warning';
            $findings[]=native_agent_finding_spec('accounts_receivable',$stale?'stale_receivable':'overdue_invoice',(string)$row['id'],$severity,10000,($stale?'Stale':'Overdue').' customer balance','Invoice '.(string)$row['number'].' for '.(string)$row['customer_name'].' is '.$overdue.' day'.($overdue===1?'':'s').' overdue with an open balance. Tegh did not alter or settle the invoice.',array_merge($evidence,['overdueDays'=>$overdue]),'nav.customer_invoices',[],$row['due_date'].' 23:59:59');
            if((string)$row['customer_status']==='on_hold'||!(bool)$row['customer_active'])$findings[]=native_agent_finding_spec('accounts_receivable','customer_status_exception',(string)$row['id'],'warning',10000,'Customer status needs review','The overdue invoice belongs to a customer that is on hold or inactive. Review the customer before any follow-up.',$evidence,'nav.customers',[],$row['updated_at']);
            elseif(trim((string)($row['customer_email']??''))==='')$findings[]=native_agent_finding_spec('accounts_receivable','missing_collection_contact',(string)$row['id'],'warning',10000,'Collection contact is missing','Invoice '.(string)$row['number'].' is overdue, but '.(string)$row['customer_name'].' has no verified email address. Add or verify contact details before preparing a message.',$evidence,'nav.customers',[],$row['updated_at']);
            else{$level=native_ap_ar_collection_level($overdue,$policy);$findings[]=native_agent_finding_spec('accounts_receivable','collection_ready',(string)$row['id'],'warning',10000,'Collection draft ready to prepare','Verified invoice and customer facts support a '.$level.' follow-up draft. Preparation creates reviewable metadata only; a separate approval and exact Send confirmation are required.',array_merge($evidence,['overdueDays'=>$overdue,'recommendedLevel'=>$level,'recipient'=>(string)$row['customer_email']]),'collections.prepare',['invoice'=>['id'=>(string)$row['id'],'number'=>(string)$row['number']],'level'=>$level],$row['due_date'].' 23:59:59');}
        }
        if(!in_array((string)$row['status'],['void','paid'],true))$duplicateGroups[(string)$row['customer_id'].'|'.(string)$row['issue_date'].'|'.(string)$row['total_cents']][]=$row;
    }
    foreach($duplicateGroups as $group){if(count($group)<2)continue;$ids=array_map(static fn(array $row):string=>(string)$row['id'],$group);sort($ids,SORT_STRING);$records=array_map(static fn(string $id):array=>['type'=>'invoice','id'=>$id],$ids);$first=$group[0];$findings[]=native_agent_finding_spec('accounts_receivable','possible_duplicate_invoice',implode('|',$ids),'warning',9000,'Possible duplicate customer invoices','Two or more non-final customer invoices share the same customer, issue date and exact total. Review them before issuing or collecting.',['records'=>$records,'affectedCount'=>count($ids),'customerId'=>(string)$first['customer_id'],'issueDate'=>(string)$first['issue_date'],'totalCents'=>(int)$first['total_cents'],'rule'=>'same customer + issue date + exact total','accountingWrites'=>0,'providerAttempts'=>0],'nav.customer_invoices',[],$first['updated_at']);}
    $documentCount=0;if(!$cursorValue){$docs=db()->prepare("SELECT id,original_name,state,updated_at FROM native_agent_documents WHERE company_id=? AND document_type='customer_invoice' AND state IN ('uploaded','review','ready','prepared') ORDER BY updated_at,id LIMIT 100");$docs->execute([$companyId]);foreach($docs->fetchAll() as $doc){$documentCount++;$findings[]=native_agent_finding_spec('accounts_receivable','customer_document_review',(string)$doc['id'],in_array((string)$doc['state'],['uploaded','review'],true)?'warning':'info',10000,'Customer document waiting for review',(string)$doc['original_name'].' is in '.(string)$doc['state'].' state in the private Document Intake workspace. Local extraction never creates or issues an invoice.',['records'=>[['type'=>'native_agent_document','id'=>(string)$doc['id']]],'affectedCount'=>1,'documentState'=>(string)$doc['state'],'accountingWrites'=>0,'providerAttempts'=>0],'nav.document_intake',[],$doc['updated_at']);}}
    $last=$rows?end($rows):null;$next=$hasMore&&is_array($last)?[(string)$last['updated_at'],(string)$last['id']]:[];
    return ['sourceRevisionHash'=>$sourceRevision,'findings'=>$findings,'processedCount'=>count($rows)+$documentCount,'cursor'=>['current'=>$cursor,'next'=>$next?native_agent_json($next):'','cycleComplete'=>!$hasMore],'summary'=>['invoicesChecked'=>count($rows),'documentsChecked'=>$documentCount,'findings'=>count($findings),'accountingWrites'=>0,'providerAttempts'=>0]];
}

function native_document_action(string $documentType): string
{
    return $documentType==='vendor_bill'?'bill.create':'invoice.create';
}

function native_document_permission(string $documentType,bool $write=false): string
{
    return $documentType==='vendor_bill'?($write?'bills.write':'bills.view'):($write?'invoices.write':'invoices.view');
}

function native_document_row(array $company,string $id,bool $lock=false): array
{
    $sql='SELECT d.*,u.display_name uploaded_by_name FROM native_agent_documents d LEFT JOIN users u ON u.id=d.uploaded_by WHERE d.id=? AND d.company_id=? LIMIT 1'.($lock?' FOR UPDATE':'');
    $stmt=db()->prepare($sql);$stmt->execute([$id,(string)$company['id']]);$row=$stmt->fetch();if(!$row)fail('That private document is not available in this company.',404,'native_document_not_found');
    require_company_permission($company,native_document_permission((string)$row['document_type'],false));return $row;
}

function native_document_public(array $row): array
{
    $candidate=json_decode((string)$row['candidate_json'],true);if(!is_array($candidate))$candidate=[];
    return ['id'=>(string)$row['id'],'documentType'=>(string)$row['document_type'],'originalName'=>(string)$row['original_name'],'mimeType'=>(string)$row['mime_type'],'extension'=>(string)$row['file_extension'],'sizeBytes'=>(int)$row['size_bytes'],'sha256'=>(string)$row['sha256'],'state'=>(string)$row['state'],'extractionMethod'=>(string)$row['extraction_method'],'candidate'=>$candidate,'candidateHash'=>(string)$row['candidate_hash'],'sourceRevisionHash'=>(string)$row['source_revision_hash'],'proposedActionId'=>(string)$row['proposed_action_id'],'linkedEntityType'=>$row['linked_entity_type'],'linkedEntityId'=>$row['linked_entity_id'],'uploadedBy'=>(string)$row['uploaded_by'],'uploadedByName'=>$row['uploaded_by_name']??null,'reviewedBy'=>$row['reviewed_by'],'reviewedAt'=>$row['reviewed_at'],'preparedTaskId'=>$row['prepared_task_id'],'preparedAt'=>$row['prepared_at'],'linkedAt'=>$row['linked_at'],'failureCode'=>$row['failure_code'],'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at'],'sourceUrl'=>'/api/index.php?route=native-ap-ar/document/file&documentId='.rawurlencode((string)$row['id'])];
}

function native_document_source_revision(array $row,string $candidateHash): string
{
    return native_agent_hash(['companyId'=>(string)$row['company_id'],'documentId'=>(string)$row['id'],'documentType'=>(string)$row['document_type'],'sha256'=>(string)$row['sha256'],'candidateHash'=>$candidateHash,'proposedActionId'=>native_document_action((string)$row['document_type'])]);
}

function native_document_verify_file(array $row): string
{
    $companyId=(string)$row['company_id'];$relative=(string)$row['storage_path'];if(!valid_private_storage_reference($companyId,$relative,'native-documents'))fail('The private document source is unavailable.',409,'native_document_source_missing');
    $absolute=private_absolute_path($relative);$actual=$absolute===null?false:hash_file('sha256',$absolute);if($absolute===null||!is_string($actual)||!hash_equals((string)$row['sha256'],$actual))fail('The private document source changed after upload.',409,'native_document_source_changed');return $absolute;
}

function native_document_candidate(array $input): array
{
    $candidate=$input['candidate']??null;if(!is_array($candidate))fail('Provide the reviewed document candidate.',422,'native_document_candidate_invalid');
    $allowed=['partyId','partyName','partyEmail','businessIdentifier','address','layoutFingerprint','paymentTerms','documentNumber','documentDate','dueDate','subtotalCents','taxCents','totalCents','amountDueCents','currency','memo','confidenceBps','alternatives','lineItems'];
    foreach(array_keys($candidate) as $key)if(!in_array((string)$key,$allowed,true))fail('The document candidate contains an unsupported field.',422,'native_document_candidate_invalid');
    $out=[];
    foreach(['partyId'=>64,'partyName'=>160,'partyEmail'=>254,'businessIdentifier'=>120,'address'=>500,'layoutFingerprint'=>128,'paymentTerms'=>120,'documentNumber'=>80,'memo'=>500] as $key=>$max){$value=trim((string)($candidate[$key]??''));if($value!=='')$out[$key]=mb_substr($value,0,$max);}
    foreach(['documentDate','dueDate'] as $key){$value=trim((string)($candidate[$key]??''));if($value!=='')$out[$key]=safe_date($value,$key==='documentDate'?'Document date':'Due date');}
    foreach(['subtotalCents','taxCents','totalCents','amountDueCents'] as $key){if(!array_key_exists($key,$candidate)||$candidate[$key]===''||$candidate[$key]===null)continue;$raw=(string)$candidate[$key];if(!preg_match('/^-?\d+$/',$raw))fail('Document amounts must use exact whole cents.',422,'native_document_candidate_invalid');$value=(int)$raw;if($value<0||$value>100000000000)fail('A document amount is outside the supported range.',422,'native_document_candidate_invalid');$out[$key]=$value;}
    $currency=strtoupper(trim((string)($candidate['currency']??'CAD')));if(!preg_match('/^[A-Z]{3}$/',$currency))fail('Use a three-letter document currency.',422,'native_document_candidate_invalid');$out['currency']=$currency;
    $confidence=(int)($candidate['confidenceBps']??0);$out['confidenceBps']=max(0,min(10000,$confidence));
    $alternatives=[];foreach(array_slice(is_array($candidate['alternatives']??null)?$candidate['alternatives']:[],0,5) as $alternative){if(!is_array($alternative))continue;$item=[];foreach(['partyName'=>160,'documentNumber'=>80,'documentDate'=>10,'totalCents'=>20] as $key=>$max){if(!isset($alternative[$key]))continue;$value=mb_substr(trim((string)$alternative[$key]),0,$max);if($value!=='')$item[$key]=$value;}if($item)$alternatives[]=$item;}$out['alternatives']=$alternatives;
    $lines=[];foreach(array_slice(is_array($candidate['lineItems']??null)?$candidate['lineItems']:[],0,100) as $line){if(!is_array($line))continue;$description=mb_substr(trim((string)($line['description']??'')),0,300);$amount=(string)($line['amountCents']??'');if($description===''||!preg_match('/^\d+$/',$amount))continue;$lines[]=['description'=>$description,'amountCents'=>(int)$amount];}$out['lineItems']=$lines;
    if(isset($out['documentDate'],$out['dueDate'])&&$out['dueDate']<$out['documentDate'])fail('Due date cannot be before the vendor invoice date.',422,'native_document_date_order_invalid');
    if(isset($out['subtotalCents'],$out['taxCents'],$out['totalCents'])&&$out['subtotalCents']+$out['taxCents']!==$out['totalCents'])fail('Subtotal plus tax must equal the exact document total.',422,'native_document_amounts_unbalanced');
    if(strlen(native_agent_json($out))>50000)fail('The reviewed candidate is too large.',422,'native_document_candidate_invalid');return $out;
}

function native_document_upload(array $user,array $company): array
{
    require_company_permission($company,'attachments.write');$documentType=(string)($_POST['documentType']??'');if(!in_array($documentType,NATIVE_DOCUMENT_TYPES,true))fail('Choose vendor bill or customer invoice.',422,'native_document_type_invalid');require_company_permission($company,native_document_permission($documentType,true));
    $saved=save_private_upload((string)$company['id'],'native-documents',['pdf','png','jpg','jpeg','webp'],['application/pdf','image/png','image/jpeg','image/webp']);$sha=hash_file('sha256',$saved['absolutePath']);if(!is_string($sha)){delete_private_file((string)$saved['relativePath']);fail('Tegh could not verify the private document after upload.',500,'native_document_integrity_unavailable');}$existing=db()->prepare('SELECT * FROM native_agent_documents WHERE company_id=? AND sha256=? AND document_type=? LIMIT 1');$existing->execute([(string)$company['id'],$sha,$documentType]);if($row=$existing->fetch()){delete_private_file((string)$saved['relativePath']);$row['uploaded_by_name']=$user['display_name']??null;return ['document'=>native_document_public($row),'reused'=>true,'accountingWrites'=>0,'providerAttempts'=>0];}
    $id=new_id('nadoc');$empty=[];$candidateHash=native_agent_hash($empty);$stub=['company_id'=>(string)$company['id'],'id'=>$id,'document_type'=>$documentType,'sha256'=>$sha];$sourceRevision=native_document_source_revision($stub,$candidateHash);$action=native_document_action($documentType);
    try{db()->prepare("INSERT INTO native_agent_documents (id,company_id,document_type,original_name,storage_path,mime_type,file_extension,size_bytes,sha256,state,extraction_method,candidate_json,candidate_hash,source_revision_hash,proposed_action_id,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,'uploaded','none',?,?,?,?,?)")->execute([$id,(string)$company['id'],$documentType,$saved['originalName'],$saved['relativePath'],$saved['mime'],$saved['extension'],$saved['size'],$sha,native_agent_json($empty),$candidateHash,$sourceRevision,$action,(string)$user['id']]);}catch(PDOException $error){delete_private_file((string)$saved['relativePath']);if((string)$error->getCode()!=='23000')throw $error;$existing->execute([(string)$company['id'],$sha,$documentType]);$row=$existing->fetch();if(!$row)throw $error;$row['uploaded_by_name']=$user['display_name']??null;return ['document'=>native_document_public($row),'reused'=>true,'accountingWrites'=>0,'providerAttempts'=>0];}
    audit_event($user,(string)$company['id'],'native_document.uploaded','native_agent_document',$id,['documentType'=>$documentType,'sha256'=>$sha,'sizeBytes'=>(int)$saved['size'],'accountingWrites'=>0,'providerAttempts'=>0]);$row=native_document_row($company,$id);return ['document'=>native_document_public($row),'reused'=>false,'accountingWrites'=>0,'providerAttempts'=>0];
}

function native_document_review(array $user,array $company,array $input): array
{
    require_company_permission($company,'attachments.write');$id=clean_text($input['documentId']??'','Document',64);$method=(string)($input['extractionMethod']??'manual');if(!in_array($method,['pdf_text','ocr','manual'],true))fail('Choose a supported local extraction method.',422,'native_document_extraction_invalid');$candidate=native_document_candidate($input);$verified=($input['verified']??false)===true;
    $pdo=db();$pdo->beginTransaction();try{$row=native_document_row($company,$id,true);require_company_permission($company,native_document_permission((string)$row['document_type'],true));if(in_array((string)$row['state'],['linked','dismissed'],true))fail('This document is no longer open for review.',409,'native_document_state_invalid');native_document_verify_file($row);$ready=$verified&&trim((string)($candidate['partyName']??''))!==''&&!empty($candidate['documentDate'])&&(int)($candidate['totalCents']??0)>0;if($verified&&!$ready)fail('Verify a party, document date and positive exact-cent total before marking this candidate ready.',422,'native_document_review_incomplete');if($verified){$booksStart=(string)($company['books_start_date']??'');if($booksStart!==''&&(string)$candidate['documentDate']<$booksStart)fail('This document predates the company books-start date. Use the protected opening-document workflow.',409,'native_document_before_books_start');$controls=db()->prepare('SELECT closed_through_date FROM accounting_controls WHERE company_id=?');$controls->execute([$company['id']]);$closed=(string)($controls->fetchColumn()?:'');if($closed!==''&&(string)$candidate['documentDate']<=$closed)fail('This document falls in a locked accounting period.',409,'period_locked');}
        $candidateHash=native_agent_hash($candidate);$sourceRevision=native_document_source_revision($row,$candidateHash);$state=$ready?'ready':'review';db()->prepare('UPDATE native_agent_documents SET state=?,extraction_method=?,candidate_json=?,candidate_hash=?,source_revision_hash=?,proposed_action_id=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),prepared_task_id=NULL,prepared_by=NULL,prepared_at=NULL,failure_code=NULL WHERE id=? AND company_id=?')->execute([$state,$method,native_agent_json($candidate),$candidateHash,$sourceRevision,native_document_action((string)$row['document_type']),(string)$user['id'],$id,(string)$company['id']]);$learning=[];if($ready&&(string)$row['document_type']==='vendor_bill'&&!empty($candidate['partyId'])&&function_exists('tegh_schema41_status')&&tegh_schema41_status()['ready'])$learning=native_vendor_learning_accept($user,$company,$row,$candidate,$sourceRevision,'document-review-'.$id.'-'.substr($candidateHash,0,24));audit_event($user,(string)$company['id'],'native_document.reviewed','native_agent_document',$id,['state'=>$state,'extractionMethod'=>$method,'candidateHash'=>$candidateHash,'sourceRevisionHash'=>$sourceRevision,'vendorLearningRules'=>count($learning),'rawTextStored'=>false,'accountingWrites'=>0,'providerAttempts'=>0]);$pdo->commit();$fresh=native_document_row($company,$id);return ['document'=>native_document_public($fresh),'vendorLearning'=>$learning,'accountingWrites'=>0,'providerAttempts'=>0];}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_document_failure(array $user,array $company,array $input): array
{
    require_company_permission($company,'attachments.write');$id=clean_text($input['documentId']??'','Document',64);
    $code=trim((string)($input['failureCode']??'ocr_extraction_failed'));if(!in_array($code,NATIVE_DOCUMENT_FAILURE_CODES,true))$code='ocr_extraction_failed';
    $pdo=db();$pdo->beginTransaction();try{$row=native_document_row($company,$id,true);require_company_permission($company,native_document_permission((string)$row['document_type'],true));
        if(in_array((string)$row['state'],['linked','dismissed'],true))fail('This document is no longer open for extraction review.',409,'native_document_state_invalid');
        native_document_verify_file($row);db()->prepare("UPDATE native_agent_documents SET state='failed',failure_code=?,extraction_method='none',reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?")->execute([$code,(string)$user['id'],$id,(string)$company['id']]);
        audit_event($user,(string)$company['id'],'native_document.extraction_failed','native_agent_document',$id,['failureCode'=>$code,'sourcePreserved'=>true,'rawPathExposed'=>false,'rawTextStored'=>false,'accountingWrites'=>0,'providerAttempts'=>0]);
        $pdo->commit();return ['document'=>native_document_public(native_document_row($company,$id)),'accountingWrites'=>0,'providerAttempts'=>0];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_document_resolve_party(array $company,array $row,array $candidate): array
{
    $vendor=(string)$row['document_type']==='vendor_bill';$table=$vendor?'vendors':'customers';$permission=$vendor?'vendors.view':'customers.view';require_company_permission($company,$permission);$partyId=trim((string)($candidate['partyId']??''));$params=[];
    if($partyId!==''){$sql="SELECT id,name,status,active FROM $table WHERE id=? AND company_id=? LIMIT 2";$params=[$partyId,(string)$company['id']];}
    else{$name=trim((string)($candidate['partyName']??''));$sql="SELECT id,name,status,active FROM $table WHERE company_id=? AND LOWER(name)=LOWER(?) ORDER BY id LIMIT 2";$params=[(string)$company['id'],$name];}
    $stmt=db()->prepare($sql);$stmt->execute($params);$matches=$stmt->fetchAll();if(count($matches)!==1)fail(count($matches)>1?'More than one current-company party matches this document. Choose one before preparing.':'The reviewed party is not an active current-company record.',409,'native_document_party_unresolved');$party=$matches[0];if(!(bool)$party['active']||(string)$party['status']!=='active')fail('The reviewed party is not active. Review the master record before preparing.',409,'native_document_party_inactive');return $party;
}

function native_document_prepare(array $user,array $company,array $input): array
{
    $id=clean_text($input['documentId']??'','Document',64);$expected=clean_text($input['sourceRevisionHash']??'','Source revision',64);$pdo=db();$pdo->beginTransaction();try{$row=native_document_row($company,$id,true);require_company_permission($company,native_document_permission((string)$row['document_type'],true));if((string)$row['state']==='prepared'&&!empty($row['prepared_task_id'])){$pdo->commit();return ['ok'=>true,'reused'=>true,'documentId'=>$id,'taskId'=>(string)$row['prepared_task_id'],'navigation'=>((string)$row['document_type']==='vendor_bill'?'bills':'customer-invoice'),'accountingWrites'=>0,'providerAttempts'=>0];}if((string)$row['state']!=='ready')fail('Review and verify this document before preparing the normal workflow.',409,'native_document_not_ready');if(!hash_equals((string)$row['source_revision_hash'],$expected))fail('The reviewed document changed. Refresh before preparing.',409,'native_document_source_changed');native_document_verify_file($row);$candidate=json_decode((string)$row['candidate_json'],true);if(!is_array($candidate)||!hash_equals((string)$row['candidate_hash'],native_agent_hash($candidate)))fail('The reviewed candidate integrity check failed.',409,'native_document_candidate_changed');$actionId=native_document_action((string)$row['document_type']);$action=tegh_action_get($actionId);tegh_action_assert_permission($company,$action,$user);if(tegh_action_execution_class($action)!=='prepared')fail('The document hand-off action changed safety class.',409,'native_document_action_changed');$party=native_document_resolve_party($company,$row,$candidate);$base=['documentId'=>$id,'sourceRevisionHash'=>$expected,'documentNumber'=>$candidate['documentNumber']??'','documentDate'=>$candidate['documentDate']??'','dueDate'=>$candidate['dueDate']??'','totalCents'=>(int)($candidate['totalCents']??0),'subtotalCents'=>(int)($candidate['subtotalCents']??0),'taxCents'=>(int)($candidate['taxCents']??0),'currency'=>(string)($candidate['currency']??$company['currency']??'CAD'),'memo'=>(string)($candidate['memo']??'')];
        if((string)$row['document_type']==='vendor_bill'){$inputs=['vendor'=>['id'=>(string)$party['id'],'name'=>(string)$party['name']],'billDetails'=>$base,'amount'=>(int)$base['totalCents'],'date'=>(string)$base['documentDate'],'tax'=>['taxCents'=>(int)$base['taxCents']]];$navigation='bills';$workflowContext=array_merge(['mode'=>'new','vendorId'=>(string)$party['id'],'vendorName'=>(string)$party['name']],$base);}else{$inputs=['customer'=>['id'=>(string)$party['id'],'name'=>(string)$party['name']],'invoiceDetails'=>$base,'date'=>(string)$base['documentDate'],'tax'=>['taxCents'=>(int)$base['taxCents']]];$navigation='customer-invoice';$workflowContext=array_merge(['customerId'=>(string)$party['id'],'customerName'=>(string)$party['name']],$base);}
        $task=tegh_agent_task($user,$company,$actionId,'Prepared from reviewed private document '.$id,'document_prepared',$inputs,[],null,null,false);$update=db()->prepare("UPDATE native_agent_documents SET state='prepared',prepared_task_id=?,prepared_by=?,prepared_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND state='ready' AND source_revision_hash=?");$update->execute([(string)$task['id'],(string)$user['id'],$id,(string)$company['id'],$expected]);if($update->rowCount()!==1)fail('The document changed while it was being prepared.',409,'native_document_source_changed');audit_event($user,(string)$company['id'],'native_document.prepared','native_agent_document',$id,['actionId'=>$actionId,'taskId'=>(string)$task['id'],'sourceRevisionHash'=>$expected,'accountingWrites'=>0,'providerAttempts'=>0]);$pdo->commit();return ['ok'=>true,'reused'=>false,'documentId'=>$id,'task'=>$task,'navigation'=>$navigation,'workflowContext'=>$workflowContext,'accountingWrites'=>0,'providerAttempts'=>0];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_document_link(array $user,array $company,array $input): array
{
    $id=clean_text($input['documentId']??'','Document',64);$targetId=clean_text($input['targetId']??'','Saved record',64);$expected=clean_text($input['sourceRevisionHash']??'','Source revision',64);$pdo=db();$pdo->beginTransaction();try{$row=native_document_row($company,$id,true);require_company_permission($company,native_document_permission((string)$row['document_type'],true));$type=(string)$row['document_type']==='vendor_bill'?'bill':'invoice';if((string)$row['state']==='linked'){if((string)$row['linked_entity_type']===$type&&(string)$row['linked_entity_id']===$targetId){$pdo->commit();return ['ok'=>true,'reused'=>true,'documentId'=>$id,'targetId'=>$targetId,'accountingWrites'=>0];}fail('This document is already linked to another saved record.',409,'native_document_already_linked');}if((string)$row['state']!=='prepared'||!hash_equals((string)$row['source_revision_hash'],$expected))fail('The prepared document changed before linking.',409,'native_document_source_changed');native_document_verify_file($row);$candidate=json_decode((string)$row['candidate_json'],true);if(!is_array($candidate)||!hash_equals((string)$row['candidate_hash'],native_agent_hash($candidate)))fail('The reviewed candidate integrity check failed.',409,'native_document_candidate_changed');$party=native_document_resolve_party($company,$row,$candidate);$table=$type==='bill'?'bills':'invoices';$partyColumn=$type==='bill'?'vendor_id':'customer_id';$check=db()->prepare("SELECT id,$partyColumn party_id FROM $table WHERE id=? AND company_id=? LIMIT 1");$check->execute([$targetId,(string)$company['id']]);$target=$check->fetch();if(!$target)fail('The saved current-company record is not available.',404,'native_document_target_not_found');if(!hash_equals((string)$party['id'],(string)$target['party_id']))fail('The saved record belongs to a different party than the reviewed document.',409,'native_document_target_party_changed');$stmt=db()->prepare("UPDATE native_agent_documents SET state='linked',linked_entity_type=?,linked_entity_id=?,linked_by=?,linked_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND state='prepared' AND source_revision_hash=?");$stmt->execute([$type,$targetId,(string)$user['id'],$id,(string)$company['id'],$expected]);if($stmt->rowCount()!==1)fail('The prepared document changed before linking.',409,'native_document_source_changed');audit_event($user,(string)$company['id'],'native_document.linked','native_agent_document',$id,['targetType'=>$type,'targetId'=>$targetId,'sourceRevisionHash'=>$expected,'accountingWrites'=>0]);$pdo->commit();return ['ok'=>true,'reused'=>false,'documentId'=>$id,'targetType'=>$type,'targetId'=>$targetId,'accountingWrites'=>0];}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_document_dismiss(array $user,array $company,array $input): array
{
    require_company_permission($company,'attachments.write');$id=clean_text($input['documentId']??'','Document',64);$stmt=db()->prepare("UPDATE native_agent_documents SET state='dismissed',dismissed_by=?,dismissed_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND state NOT IN ('linked','dismissed')");$stmt->execute([(string)$user['id'],$id,(string)$company['id']]);if($stmt->rowCount()!==1){$row=native_document_row($company,$id);if((string)$row['state']!=='dismissed')fail('A linked document cannot be dismissed.',409,'native_document_state_invalid');}audit_event($user,(string)$company['id'],'native_document.dismissed','native_agent_document',$id,['accountingWrites'=>0]);return ['ok'=>true,'documentId'=>$id,'accountingWrites'=>0];
}

function native_collection_snapshot(array $company,string $invoiceId,bool $lock=false): array
{
    $sql="SELECT i.id,i.customer_id,i.number,i.issue_date,i.due_date,i.status,i.total_cents,i.balance_cents,i.currency,i.template_snapshot_json,i.updated_at,c.name customer_name,c.contact_name,c.email customer_email,c.status customer_status,c.active customer_active,c.updated_at customer_updated_at FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.id=? AND i.company_id=? LIMIT 1".($lock?' FOR UPDATE':'');$stmt=db()->prepare($sql);$stmt->execute([$invoiceId,(string)$company['id']]);$row=$stmt->fetch();if(!$row)fail('That customer invoice is not available in this company.',404,'native_collection_invoice_not_found');return $row;
}

function native_collection_source_revision(array $company,array $invoice): string
{
    return native_agent_hash(['companyId'=>(string)$company['id'],'invoiceId'=>(string)$invoice['id'],'customerId'=>(string)$invoice['customer_id'],'number'=>(string)$invoice['number'],'issueDate'=>(string)$invoice['issue_date'],'dueDate'=>(string)$invoice['due_date'],'status'=>(string)$invoice['status'],'totalCents'=>(int)$invoice['total_cents'],'balanceCents'=>(int)$invoice['balance_cents'],'currency'=>(string)$invoice['currency'],'templateSnapshotHash'=>hash('sha256',(string)($invoice['template_snapshot_json']??'')),'invoiceUpdatedAt'=>(string)$invoice['updated_at'],'customerName'=>(string)$invoice['customer_name'],'customerEmail'=>(string)$invoice['customer_email'],'customerStatus'=>(string)$invoice['customer_status'],'customerActive'=>(bool)$invoice['customer_active'],'customerUpdatedAt'=>(string)$invoice['customer_updated_at']]);
}

function native_collection_content_hash(string $level,string $recipient,string $subject,string $body,string $length='standard',array $snapshot=[]): string
{
    $content=['level'=>$level,'length'=>$length,'channel'=>'email','recipient'=>$recipient,'subject'=>$subject,'body'=>$body];
    if($snapshot)$content['snapshot']=$snapshot;return native_agent_hash($content);
}

function native_collection_legacy_content_hash(string $level,string $recipient,string $subject,string $body): string
{
    return native_agent_hash(['level'=>$level,'channel'=>'email','recipient'=>$recipient,'subject'=>$subject,'body'=>$body]);
}

function native_collection_body(mixed $value): string
{
    $body=trim(str_replace(["\r\n","\r"],"\n",(string)$value));
    if($body==='')fail('Message is required.',422,'native_collection_content_invalid');
    if(mb_strlen($body)>4000)fail('Message must be 4000 characters or fewer.',422,'native_collection_content_invalid');
    return $body;
}

function native_collection_default_template(string $tone,string $length): array
{
    if(!in_array($tone,NATIVE_COLLECTION_TONES,true)||!in_array($length,NATIVE_COLLECTION_LENGTHS,true))fail('Choose a supported collection tone and length.',422,'native_collection_template_invalid');
    $subject=match($tone){'firm'=>'Action requested: Invoice {{invoice_number}}','final'=>'Final payment reminder: Invoice {{invoice_number}}',default=>'Friendly reminder: Invoice {{invoice_number}}'};
    $opening="Hello {{customer_contact}},\n\n";
    $facts="This message concerns invoice {{invoice_number}}, dated {{invoice_date}}. It was due on {{due_date}} and is now {{days_overdue}} days overdue. The exact open balance is {{currency}} {{open_balance}}.";
    $request=match($tone){
      'firm'=>"Please arrange payment promptly or reply with the expected payment date. If there is a dispute or information we should review, tell us now so the account can be resolved accurately.",
      'final'=>"Please give this overdue balance your immediate attention and reply with the expected payment date. If you dispute the invoice or need supporting information, contact us promptly so we can review it with you.",
      default=>"When convenient, please reply with the expected payment date. If payment has already been made, thank you—please send the payment date or reference so we can update our review.",
    };
    $contact="If any invoice detail differs from your records, please contact us before paying. We want to resolve questions accurately and keep your account information current.";
    $payment="Payment instructions: {{payment_instructions}}";
    $signature="Thank you,\n{{company_name}}\n{{business_email}}\n{{business_phone}}";
    $body=match($length){
      'concise'=>$opening.$facts."\n\n".$request."\n\n".$payment."\n\n".$signature,
      'detailed'=>$opening.$facts."\n\n".$request."\n\n".$contact." We would appreciate a reply even if the payment date is still being confirmed. This helps us keep the receivable record accurate without making assumptions about your circumstances.\n\n".$payment."\n\n".$signature,
      default=>$opening.$facts."\n\n".$request."\n\n".$contact."\n\n".$payment."\n\n".$signature,
    };
    return ['id'=>null,'tone'=>$tone,'messageLength'=>$length,'subjectTemplate'=>$subject,'bodyTemplate'=>$body,'signatureBlock'=>'','paymentInstructions'=>'','revision'=>0,'templateHash'=>native_agent_hash([$tone,$length,$subject,$body]),'changeNote'=>'Tegh protected default','isDefault'=>true];
}

function native_collection_template_validate(string $subject,string $body,string $signature='',string $payment=''): void
{
    if(trim($subject)===''||mb_strlen($subject)>240)fail('Template subject is required and must be 240 characters or fewer.',422,'native_collection_template_invalid');
    if(trim($body)===''||mb_strlen($body)>6000)fail('Template body is required and must be 6000 characters or fewer.',422,'native_collection_template_invalid');
    foreach([$subject,$body,$signature,$payment] as $text){preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i',$text,$matches);foreach($matches[1]??[] as $name)if(!in_array(strtolower((string)$name),NATIVE_COLLECTION_PLACEHOLDERS,true))fail('Unknown collection placeholder: '.mb_substr((string)$name,0,80).'.',422,'native_collection_placeholder_invalid');}
}

function native_collection_template_public(array $row): array
{
    return ['id'=>$row['id']??null,'tone'=>(string)$row['tone'],'messageLength'=>(string)$row['message_length'],'subjectTemplate'=>(string)$row['subject_template'],'bodyTemplate'=>(string)$row['body_template'],'signatureBlock'=>(string)($row['signature_block']??''),'paymentInstructions'=>(string)($row['payment_instructions']??''),'revision'=>(int)$row['revision'],'templateHash'=>(string)$row['template_hash'],'changeNote'=>(string)($row['change_note']??''),'createdAt'=>$row['created_at']??null,'isDefault'=>false];
}

function native_collection_template_current(array $company,string $tone,string $length): array
{
    $stmt=db()->prepare('SELECT * FROM collection_message_templates WHERE company_id=? AND tone=? AND message_length=? ORDER BY revision DESC LIMIT 1');$stmt->execute([(string)$company['id'],$tone,$length]);
    $row=$stmt->fetch();if($row)return native_collection_template_public($row);return native_collection_default_template($tone,$length);
}

function native_collection_templates(array $company): array
{
    require_company_permission($company,'invoices.view');$templates=[];foreach(NATIVE_COLLECTION_TONES as $tone)foreach(NATIVE_COLLECTION_LENGTHS as $length)$templates[]=native_collection_template_current($company,$tone,$length);
    $stmt=db()->prepare('SELECT * FROM collection_message_templates WHERE company_id=? ORDER BY created_at DESC,id DESC LIMIT 100');$stmt->execute([(string)$company['id']]);
    return ['templates'=>$templates,'history'=>array_map('native_collection_template_public',$stmt->fetchAll()),'placeholders'=>NATIVE_COLLECTION_PLACEHOLDERS];
}

function native_collection_template_save(array $user,array $company,array $input): array
{
    require_company_permission($company,'company.settings');$tone=(string)($input['tone']??'');$length=(string)($input['messageLength']??'standard');
    if(!in_array($tone,NATIVE_COLLECTION_TONES,true)||!in_array($length,NATIVE_COLLECTION_LENGTHS,true))fail('Choose a supported collection tone and length.',422,'native_collection_template_invalid');
    $restore=(string)($input['action']??'save')==='restore';$default=native_collection_default_template($tone,$length);
    $subject=trim((string)($restore?$default['subjectTemplate']:($input['subjectTemplate']??'')));$body=native_collection_body($restore?$default['bodyTemplate']:($input['bodyTemplate']??''));
    $signature=trim((string)($restore?'':($input['signatureBlock']??'')));$payment=trim((string)($restore?'':($input['paymentInstructions']??'')));
    if(mb_strlen($signature)>1000||mb_strlen($payment)>1000)fail('Signature and payment instructions must each be 1000 characters or fewer.',422,'native_collection_template_invalid');native_collection_template_validate($subject,$body,$signature,$payment);
    $pdo=db();$pdo->beginTransaction();try{$stmt=$pdo->prepare('SELECT revision FROM collection_message_templates WHERE company_id=? AND tone=? AND message_length=? ORDER BY revision DESC LIMIT 1 FOR UPDATE');$stmt->execute([(string)$company['id'],$tone,$length]);$current=(int)($stmt->fetchColumn()?:0);
        if(isset($input['expectedRevision'])&&(int)$input['expectedRevision']!==$current)fail('The collection template changed. Review the latest revision before saving.',409,'native_collection_template_stale');
        $revision=$current+1;$hash=native_agent_hash(['companyId'=>(string)$company['id'],'tone'=>$tone,'length'=>$length,'subject'=>$subject,'body'=>$body,'signature'=>$signature,'paymentInstructions'=>$payment,'revision'=>$revision]);$id=new_id('collectiontemplate');$note=mb_substr(trim((string)($input['changeNote']??($restore?'Restored protected default':'Updated by authorized user'))),0,500);
        $pdo->prepare('INSERT INTO collection_message_templates (id,company_id,tone,message_length,subject_template,body_template,signature_block,payment_instructions,revision,template_hash,change_note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,(string)$company['id'],$tone,$length,$subject,$body,$signature,$payment,$revision,$hash,$note,(string)$user['id']]);
        audit_event($user,(string)$company['id'],$restore?'native_collection.template_restored':'native_collection.template_revised','collection_message_template',$id,['tone'=>$tone,'messageLength'=>$length,'revision'=>$revision,'templateHash'=>$hash]);$pdo->commit();
        $row=$pdo->prepare('SELECT * FROM collection_message_templates WHERE id=? AND company_id=?');$row->execute([$id,(string)$company['id']]);return ['template'=>native_collection_template_public($row->fetch()),'restored'=>$restore];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_collection_source_facts(array $company,array $invoice,array $template): array
{
    $snapshot=json_decode((string)($invoice['template_snapshot_json']??''),true);if(!is_array($snapshot))$snapshot=[];$days=max(1,native_ap_ar_days_between((string)$invoice['due_date'],canadian_today()));
    return ['customer_contact'=>trim((string)($invoice['contact_name']??''))?:trim((string)$invoice['customer_name']),'customer_name'=>(string)$invoice['customer_name'],'invoice_number'=>(string)$invoice['number'],'invoice_date'=>(string)$invoice['issue_date'],'due_date'=>(string)$invoice['due_date'],'days_overdue'=>(string)$days,'open_balance'=>number_format((int)$invoice['balance_cents']/100,2,'.',','),'currency'=>(string)$invoice['currency'],'company_name'=>trim((string)($company['name']??'Company')),'payment_instructions'=>trim((string)($template['paymentInstructions']??''))?:trim((string)($snapshot['paymentInstructions']??'')),'business_email'=>trim((string)($snapshot['businessEmail']??'')),'business_phone'=>trim((string)($snapshot['businessPhone']??''))];
}

function native_collection_render_template(string $text,array $facts,array &$missing): string
{
    $lines=preg_split('/\R/u',$text)?:[];$rendered=[];foreach($lines as $line){preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i',$line,$matches);$omit=false;foreach($matches[1]??[] as $name){$key=strtolower((string)$name);if(trim((string)($facts[$key]??''))===''){$missing[$key]=true;$omit=true;}}if($omit)continue;$line=preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i',static fn(array $match):string=>(string)($facts[strtolower((string)$match[1])]??''),$line)??$line;$rendered[]=rtrim($line);}
    return trim(preg_replace('/\n{3,}/',"\n\n",implode("\n",$rendered))??'');
}

function native_collection_copy(array $company,array $invoice,string $level,string $length='standard',?array $template=null): array
{
    $template??=native_collection_template_current($company,$level,$length);$facts=native_collection_source_facts($company,$invoice,$template);$missing=[];
    $subject=native_collection_render_template((string)$template['subjectTemplate'],$facts,$missing);$bodyTemplate=(string)$template['bodyTemplate'];if(trim((string)($template['signatureBlock']??''))!=='')$bodyTemplate.="\n\n".(string)$template['signatureBlock'];
    $body=native_collection_render_template($bodyTemplate,$facts,$missing);$snapshot=['templateId'=>$template['id']??null,'templateRevision'=>(int)($template['revision']??0),'templateHash'=>(string)$template['templateHash'],'tone'=>$level,'messageLength'=>$length,'subjectTemplate'=>(string)$template['subjectTemplate'],'bodyTemplate'=>(string)$template['bodyTemplate'],'signatureBlock'=>(string)($template['signatureBlock']??''),'paymentInstructions'=>(string)($template['paymentInstructions']??'')];
    return ['recipient'=>safe_email((string)$invoice['customer_email']),'subject'=>mb_substr($subject,0,240),'body'=>mb_substr($body,0,4000),'facts'=>$facts,'template'=>$snapshot,'missingFields'=>array_keys($missing),'wordCount'=>str_word_count($body)];
}

function native_collection_assert_eligible(array $invoice): void
{
    if((string)$invoice['status']!=='sent'||(int)$invoice['balance_cents']<=0)fail('Choose an issued invoice with an open balance.',409,'native_collection_invoice_closed');if((string)$invoice['due_date']>=canadian_today())fail('Collection drafts are available only after the invoice due date.',409,'native_collection_not_overdue');if((string)$invoice['customer_status']!=='active'||!(bool)$invoice['customer_active'])fail('Review the customer hold or inactive status before preparing a collection draft.',409,'native_collection_customer_inactive');if(trim((string)$invoice['customer_email'])==='')fail('Add and verify the customer email before preparing a collection draft.',409,'native_collection_recipient_missing');
}

function native_collection_confirmation(array $draft): string
{
    return 'SEND '.(string)$draft['invoice_number'].' TO '.(string)$draft['recipient_email'];
}

function native_collection_public(array $row): array
{
    $confirmation=native_collection_confirmation($row);$template=json_decode((string)($row['template_snapshot_json']??''),true);if(!is_array($template))$template=[];$facts=json_decode((string)($row['source_facts_json']??''),true);if(!is_array($facts))$facts=[];
    return ['id'=>(string)$row['id'],'invoiceId'=>(string)$row['invoice_id'],'invoiceNumber'=>(string)$row['invoice_number'],'customerId'=>(string)$row['customer_id'],'customerName'=>(string)$row['customer_name'],'dueDate'=>(string)$row['due_date'],'balanceCents'=>(int)$row['balance_cents'],'currency'=>(string)$row['currency'],'level'=>(string)$row['level'],'messageLength'=>(string)($row['message_length']??'standard'),'channel'=>(string)$row['channel'],'recipientEmail'=>(string)$row['recipient_email'],'subject'=>(string)$row['subject'],'body'=>(string)$row['body_text'],'wordCount'=>str_word_count((string)$row['body_text']),'sourceFacts'=>$facts,'templateSnapshot'=>$template,'templateId'=>$row['template_id']??null,'templateRevision'=>$row['template_revision']!==null?(int)$row['template_revision']:null,'templateHash'=>$row['template_hash']??null,'sourceRevisionHash'=>(string)$row['source_revision_hash'],'contentHash'=>(string)$row['content_hash'],'state'=>(string)$row['state'],'confirmationText'=>$confirmation,'approvedBy'=>$row['approved_by'],'approvedAt'=>$row['approved_at'],'sendingStartedAt'=>$row['sending_started_at'],'sentAt'=>$row['sent_at'],'failureMessage'=>$row['failure_message'],'outboundEmailId'=>$row['outbound_email_id'],'deliveryAttemptId'=>$row['delivery_attempt_id']??null,'deliveryOutcome'=>(string)($row['delivery_outcome']??'none'),'recoveryStatus'=>(string)($row['recovery_status']??'none'),'recoveryReason'=>$row['recovery_reason']??null,'recoveredAt'=>$row['recovered_at']??null,'followupId'=>$row['followup_id'],'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at']];
}

function native_collection_row(array $company,string $id,bool $lock=false): array
{
    $sql="SELECT d.*,i.number invoice_number,i.customer_id,i.due_date,i.balance_cents,i.currency,c.name customer_name FROM native_agent_collection_drafts d JOIN invoices i ON i.id=d.invoice_id AND i.company_id=d.company_id JOIN customers c ON c.id=i.customer_id AND c.company_id=d.company_id WHERE d.id=? AND d.company_id=? LIMIT 1".($lock?' FOR UPDATE':'');$stmt=db()->prepare($sql);$stmt->execute([$id,(string)$company['id']]);$row=$stmt->fetch();if(!$row)fail('That collection draft is not available in this company.',404,'native_collection_not_found');return $row;
}

function native_collection_prepare(array $user,array $company,array $input): array
{
    require_company_permission($company,'invoices.write');$invoiceId=clean_text(trim((string)($input['invoiceId']??($input['invoice']['id']??''))),'Invoice',64);
    $level=(string)($input['level']??'');$length=(string)($input['messageLength']??'standard');$invoice=native_collection_snapshot($company,$invoiceId);native_collection_assert_eligible($invoice);
    $overdue=max(1,native_ap_ar_days_between((string)$invoice['due_date'],canadian_today()));if($level==='')$level=native_ap_ar_collection_level($overdue,native_agent_policy_get($company,$user));
    if(!in_array($level,NATIVE_COLLECTION_TONES,true))fail('Choose friendly, firm or final.',422,'native_collection_level_invalid');if(!in_array($length,NATIVE_COLLECTION_LENGTHS,true))fail('Choose concise, standard or detailed.',422,'native_collection_length_invalid');
    $source=native_collection_source_revision($company,$invoice);$template=native_collection_template_current($company,$level,$length);$copy=native_collection_copy($company,$invoice,$level,$length,$template);
    $snapshot=['template'=>$copy['template'],'sourceFactsHash'=>native_agent_hash($copy['facts']),'sourceRevisionHash'=>$source];
    $content=native_collection_content_hash($level,$copy['recipient'],$copy['subject'],$copy['body'],$length,$snapshot);
    $fingerprint=hash('sha256',implode('|',[(string)$company['id'],$invoiceId,$source,$level,$length,(string)$template['templateHash']]));
    $existing=db()->prepare('SELECT id FROM native_agent_collection_drafts WHERE company_id=? AND fingerprint=? LIMIT 1');$existing->execute([(string)$company['id'],$fingerprint]);
    if($existingId=$existing->fetchColumn())return ['draft'=>native_collection_public(native_collection_row($company,(string)$existingId)),'reused'=>true,'missingFields'=>$copy['missingFields'],'accountingWrites'=>0,'providerAttempts'=>0];
    $id=new_id('nacollection');$templateJson=native_agent_json($copy['template']);$factsJson=native_agent_json($copy['facts']);
    try{db()->prepare("INSERT INTO native_agent_collection_drafts (id,company_id,invoice_id,fingerprint,level,message_length,channel,recipient_email,subject,body_text,source_revision_hash,content_hash,template_id,template_revision,template_hash,template_snapshot_json,source_facts_json,state,created_by,updated_by) VALUES (?,?,?,?,?,?,'email',?,?,?,?,?,?,?,?,?,?,'draft',?,?)")
        ->execute([$id,(string)$company['id'],$invoiceId,$fingerprint,$level,$length,$copy['recipient'],$copy['subject'],$copy['body'],$source,$content,$template['id']??null,(int)($template['revision']??0),(string)$template['templateHash'],$templateJson,$factsJson,(string)$user['id'],(string)$user['id']]);}
    catch(PDOException $error){if((string)$error->getCode()!=='23000')throw $error;$existing->execute([(string)$company['id'],$fingerprint]);$id=(string)$existing->fetchColumn();if($id==='')throw $error;return ['draft'=>native_collection_public(native_collection_row($company,$id)),'reused'=>true,'missingFields'=>$copy['missingFields'],'accountingWrites'=>0,'providerAttempts'=>0];}
    audit_event($user,(string)$company['id'],'native_collection.prepared','native_agent_collection_draft',$id,['invoiceId'=>$invoiceId,'level'=>$level,'messageLength'=>$length,'templateRevision'=>(int)($template['revision']??0),'templateHash'=>(string)$template['templateHash'],'sourceRevisionHash'=>$source,'contentHash'=>$content,'missingFields'=>$copy['missingFields'],'accountingWrites'=>0,'providerAttempts'=>0]);
    return ['draft'=>native_collection_public(native_collection_row($company,$id)),'reused'=>false,'missingFields'=>$copy['missingFields'],'accountingWrites'=>0,'providerAttempts'=>0];
}

function native_collection_revalidate(array $company,array $draft): array
{
    $invoice=native_collection_snapshot($company,(string)$draft['invoice_id'],true);native_collection_assert_eligible($invoice);$source=native_collection_source_revision($company,$invoice);if(!hash_equals((string)$draft['source_revision_hash'],$source)){$stmt=db()->prepare("UPDATE native_agent_collection_drafts SET state='stale' WHERE id=? AND company_id=? AND state NOT IN ('sent','dismissed')");$stmt->execute([(string)$draft['id'],(string)$company['id']]);if(db()->inTransaction())db()->commit();fail('The invoice or customer changed. Prepare a fresh collection draft.',409,'native_collection_source_changed');}
    $snapshot=[];if(!empty($draft['template_hash'])){$template=json_decode((string)($draft['template_snapshot_json']??''),true);$facts=json_decode((string)($draft['source_facts_json']??''),true);if(!is_array($template)||!is_array($facts))fail('The collection template snapshot is unavailable.',409,'native_collection_content_changed');$snapshot=['template'=>$template,'sourceFactsHash'=>native_agent_hash($facts),'sourceRevisionHash'=>$source];}
    $content=!empty($draft['template_hash'])
        ? native_collection_content_hash((string)$draft['level'],(string)$draft['recipient_email'],(string)$draft['subject'],(string)$draft['body_text'],(string)($draft['message_length']??'standard'),$snapshot)
        : native_collection_legacy_content_hash((string)$draft['level'],(string)$draft['recipient_email'],(string)$draft['subject'],(string)$draft['body_text']);
    if(!hash_equals((string)$draft['content_hash'],$content))fail('The collection content integrity check failed.',409,'native_collection_content_changed');return $invoice;
}

function native_collection_update(array $user,array $company,array $input): array
{
    require_company_permission($company,'invoices.write');$id=clean_text($input['draftId']??'','Collection draft',64);$pdo=db();$pdo->beginTransaction();try{$draft=native_collection_row($company,$id,true);if(!in_array((string)$draft['state'],['draft','approved','failed'],true))fail('Only a current unsent draft can be edited.',409,'native_collection_state_invalid');native_collection_revalidate($company,$draft);
        $level=(string)($input['level']??$draft['level']);$length=(string)($input['messageLength']??($draft['message_length']??'standard'));if(!in_array($level,NATIVE_COLLECTION_TONES,true))fail('Choose friendly, firm or final.',422,'native_collection_level_invalid');if(!in_array($length,NATIVE_COLLECTION_LENGTHS,true))fail('Choose concise, standard or detailed.',422,'native_collection_length_invalid');
        $recipient=safe_email($input['recipientEmail']??$draft['recipient_email']);$subject=clean_text($input['subject']??$draft['subject'],'Subject',240);$body=native_collection_body($input['body']??$draft['body_text']);$template=json_decode((string)($draft['template_snapshot_json']??''),true);$facts=json_decode((string)($draft['source_facts_json']??''),true);$snapshot=[];
        if(is_array($template)&&is_array($facts)&&!empty($draft['template_hash']))$snapshot=['template'=>$template,'sourceFactsHash'=>native_agent_hash($facts),'sourceRevisionHash'=>(string)$draft['source_revision_hash']];
        $content=!empty($draft['template_hash'])?native_collection_content_hash($level,$recipient,$subject,$body,$length,$snapshot):native_collection_legacy_content_hash($level,$recipient,$subject,$body);db()->prepare("UPDATE native_agent_collection_drafts SET level=?,message_length=?,recipient_email=?,subject=?,body_text=?,content_hash=?,state='draft',updated_by=?,approved_by=NULL,approved_at=NULL,failed_at=NULL,failure_message=NULL,delivery_outcome='none' WHERE id=? AND company_id=?")->execute([$level,$length,$recipient,$subject,$body,$content,(string)$user['id'],$id,(string)$company['id']]);
        audit_event($user,(string)$company['id'],'native_collection.edited','native_agent_collection_draft',$id,['contentHash'=>$content,'level'=>$level,'messageLength'=>$length,'approvalInvalidated'=>true,'accountingWrites'=>0]);$pdo->commit();return ['draft'=>native_collection_public(native_collection_row($company,$id)),'accountingWrites'=>0];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_collection_approve(array $user,array $company,array $input): array
{
    require_company_permission($company,'invoices.write');$id=clean_text($input['draftId']??'','Collection draft',64);$expected=clean_text($input['contentHash']??'','Content hash',64);$pdo=db();$pdo->beginTransaction();try{$draft=native_collection_row($company,$id,true);if((string)$draft['state']==='approved'&&hash_equals((string)$draft['content_hash'],$expected)){native_collection_revalidate($company,$draft);$pdo->commit();return ['draft'=>native_collection_public($draft),'reused'=>true,'accountingWrites'=>0];}if(!in_array((string)$draft['state'],['draft','failed'],true))fail('Only a current draft can be approved.',409,'native_collection_state_invalid');if(!hash_equals((string)$draft['content_hash'],$expected))fail('The draft changed. Review the latest content before approving.',409,'native_collection_content_changed');native_collection_revalidate($company,$draft);db()->prepare("UPDATE native_agent_collection_drafts SET state='approved',approved_by=?,approved_at=UTC_TIMESTAMP(),updated_by=?,failure_message=NULL,failed_at=NULL WHERE id=? AND company_id=? AND state IN ('draft','failed')")->execute([(string)$user['id'],(string)$user['id'],$id,(string)$company['id']]);audit_event($user,(string)$company['id'],'native_collection.approved','native_agent_collection_draft',$id,['contentHash'=>$expected,'accountingWrites'=>0]);$pdo->commit();return ['draft'=>native_collection_public(native_collection_row($company,$id)),'reused'=>false,'accountingWrites'=>0];}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_collection_send(array $user,array $company,array $input): array
{
    require_company_permission($company,'invoices.write');$id=clean_text($input['draftId']??'','Collection draft',64);$confirmation=(string)($input['confirmation']??'');$pdo=db();$pdo->beginTransaction();
    try{$draft=native_collection_row($company,$id,true);if((string)$draft['state']==='sent'){$pdo->commit();return ['ok'=>true,'reused'=>true,'draft'=>native_collection_public($draft),'accountingWrites'=>0,'providerAttempts'=>0];}
        if((string)$draft['state']==='sending')fail('Delivery may already have occurred. Review the outbound record; Tegh will not automatically resend an ambiguous delivery.',409,'native_collection_send_recovery_required');
        if((string)$draft['state']!=='approved')fail('Approve the current draft before sending.',409,'native_collection_not_approved');$expected=native_collection_confirmation($draft);
        if($confirmation===''||!hash_equals($expected,$confirmation))fail('Type the exact Send confirmation shown in the preview.',422,'native_collection_confirmation_required');native_collection_revalidate($company,$draft);
        if(portal_mail_reserved_address((string)$draft['recipient_email']))fail('This is a sample or reserved email address and cannot receive real mail. Replace it with a real address before sending.',422,'native_collection_reserved_recipient');
        $sending=db()->prepare("UPDATE native_agent_collection_drafts SET state='sending',sending_started_at=UTC_TIMESTAMP(),updated_by=?,failure_message=NULL,delivery_outcome='none',delivery_attempt_id=NULL WHERE id=? AND company_id=? AND state='approved'");$sending->execute([(string)$user['id'],$id,(string)$company['id']]);
        if($sending->rowCount()!==1)fail('The draft changed before sending.',409,'native_collection_state_changed');audit_event($user,(string)$company['id'],'native_collection.sending','native_agent_collection_draft',$id,['contentHash'=>(string)$draft['content_hash'],'sourceRevisionHash'=>(string)$draft['source_revision_hash'],'exactConfirmation'=>true,'accountingWrites'=>0]);$pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    $html='<div style="font-family:system-ui,sans-serif;white-space:pre-line">'.nl2br(htmlspecialchars((string)$draft['body_text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</div>';
    $delivery=sr_mail_send((string)$company['id'],(string)$user['id'],(string)$draft['recipient_email'],'native_collection_'.(string)$draft['level'].'_'.(string)($draft['message_length']??'standard'),(string)$draft['subject'],(string)$draft['body_text'],$html,[],['reservedPreflight'=>true,'collectionDraftId'=>$id,'operationKey'=>hash('sha256','collection-send|'.$id.'|'.(string)$draft['content_hash'].'|'.bin2hex(random_bytes(24)))]);
    if(empty($delivery['sent'])){
        $certainty=(string)($delivery['acceptanceCertainty']??'unknown');$outcome=(string)($delivery['outcome']??'ambiguous_after_submission');$conclusive=$certainty==='no';$draftOutcome=$conclusive?($outcome==='temporary_preaccept_failure'?'deferred':'failed'):'manual_review';$nextState=$conclusive?'failed':'sending';
        db()->prepare('UPDATE native_agent_collection_drafts SET state=?,outbound_email_id=?,delivery_attempt_id=?,delivery_outcome=?,failure_message=?,failed_at=? WHERE id=? AND company_id=? AND state=\'sending\'')->execute([$nextState,$delivery['id']??null,$delivery['attemptId']??null,$draftOutcome,mb_substr((string)($delivery['message']??'Delivery outcome is unknown.'),0,500),$conclusive?gmdate('Y-m-d H:i:s'):null,$id,(string)$company['id']]);
        audit_event($user,(string)$company['id'],$conclusive?'native_collection.delivery_failed':'native_collection.delivery_requires_review','native_agent_collection_draft',$id,['outboundEmailId'=>$delivery['id']??null,'attemptId'=>$delivery['attemptId']??null,'stage'=>$delivery['stage']??null,'smtpCode'=>$delivery['smtpCode']??null,'outcome'=>$outcome,'acceptanceCertainty'=>$certainty,'automaticRetry'=>false,'accountingWrites'=>0]);
        fail((string)($delivery['message']??'Delivery did not return a confirmed result.'),502,$conclusive?'native_collection_delivery_failed':'native_collection_send_recovery_required');
    }
    $pdo=db();$pdo->beginTransaction();try{$locked=native_collection_row($company,$id,true);if((string)$locked['state']==='sent'){$pdo->commit();return ['ok'=>true,'reused'=>true,'draft'=>native_collection_public($locked),'accountingWrites'=>0,'providerAttempts'=>0];}if((string)$locked['state']!=='sending')fail('The collection delivery state changed unexpectedly. Review the outbound record.',409,'native_collection_send_recovery_required');
        $followupId=new_id('followup');db()->prepare("INSERT INTO invoice_followups (id,company_id,invoice_id,action_date,level,channel,note,created_by) VALUES (?,?,?,?,?,'email',?,?)")->execute([$followupId,(string)$company['id'],(string)$locked['invoice_id'],canadian_today(),(string)$locked['level'],mb_substr('Sent from approved Tegh collection draft '.$id.'.',0,1000),(string)$user['id']]);
        $draftOutcome=!empty($delivery['diagnosticWarning'])?'diagnostic_warning':'accepted';$sent=db()->prepare("UPDATE native_agent_collection_drafts SET state='sent',sent_at=?,outbound_email_id=?,delivery_attempt_id=?,delivery_outcome=?,followup_id=?,updated_by=?,failure_message=? WHERE id=? AND company_id=? AND state='sending'");
        $sent->execute([$delivery['acceptedAt']??gmdate('Y-m-d H:i:s'),(string)$delivery['id'],$delivery['attemptId']??null,$draftOutcome,$followupId,(string)$user['id'],!empty($delivery['diagnosticWarning'])?(string)$delivery['message']:null,$id,(string)$company['id']]);if($sent->rowCount()!==1)fail('The sent message needs manual state review. Tegh will not resend.',409,'native_collection_send_recovery_required');
        audit_event($user,(string)$company['id'],'native_collection.sent','native_agent_collection_draft',$id,['outboundEmailId'=>(string)$delivery['id'],'attemptId'=>$delivery['attemptId']??null,'followupId'=>$followupId,'deliveryOutcome'=>$draftOutcome,'acceptanceCertainty'=>'yes','accountingWrites'=>0,'invoiceChanged'=>false]);$pdo->commit();
        return ['ok'=>true,'reused'=>false,'draft'=>native_collection_public(native_collection_row($company,$id)),'delivery'=>$delivery,'accountingWrites'=>0,'providerAttempts'=>1];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_collection_delivery_record(array $company,string $draftId): array
{
    require_company_permission($company,'invoices.view');$draft=native_collection_row($company,$draftId);$attempt=null;
    if(!empty($draft['delivery_attempt_id'])){$stmt=db()->prepare('SELECT id,outbound_email_id,attempt_number,stage,smtp_reply_code,enhanced_code,provider_diagnostic,outcome,acceptance_certainty,started_at,accepted_at,completed_at FROM outbound_email_attempts WHERE id=? AND company_id=? LIMIT 1');$stmt->execute([(string)$draft['delivery_attempt_id'],(string)$company['id']]);$attempt=$stmt->fetch()?:null;}
    return ['draft'=>native_collection_public($draft),'attempt'=>$attempt?['id'=>(string)$attempt['id'],'outboundEmailId'=>(string)$attempt['outbound_email_id'],'attemptNumber'=>(int)$attempt['attempt_number'],'stage'=>(string)$attempt['stage'],'smtpCode'=>$attempt['smtp_reply_code']!==null?(int)$attempt['smtp_reply_code']:null,'enhancedCode'=>$attempt['enhanced_code'],'diagnostic'=>mb_substr((string)$attempt['provider_diagnostic'],0,500),'outcome'=>$attempt['outcome'],'acceptanceCertainty'=>(string)$attempt['acceptance_certainty'],'startedAt'=>(string)$attempt['started_at'],'acceptedAt'=>$attempt['accepted_at'],'completedAt'=>$attempt['completed_at']]:null];
}

function native_collection_recover(array $user,array $company,array $input): array
{
    require_company_permission($company,'invoices.write');$id=clean_text($input['draftId']??'','Collection draft',64);$action=(string)($input['action']??'');$reason=trim((string)($input['reason']??''));
    if(mb_strlen($reason)<10||mb_strlen($reason)>500)fail('Provide a recovery reason between 10 and 500 characters.',422,'native_collection_recovery_reason_required');
    $expected=match($action){'confirm_sent_external'=>'CONFIRM SENT EXTERNALLY','confirm_not_received'=>'CONFIRM NOT RECEIVED AND PREPARE NEW DELIVERY',default=>''};if($expected===''||!hash_equals($expected,(string)($input['acknowledgement']??'')))fail('Type the exact recovery acknowledgement shown.',422,'native_collection_recovery_confirmation_required');
    $pdo=db();$pdo->beginTransaction();try{$draft=native_collection_row($company,$id,true);if((string)$draft['state']!=='sending'||(string)$draft['delivery_outcome']!=='manual_review'||empty($draft['delivery_attempt_id']))fail('This delivery is not eligible for ambiguous-outcome recovery.',409,'native_collection_recovery_unavailable');
        $stmt=$pdo->prepare('SELECT * FROM outbound_email_attempts WHERE id=? AND company_id=? FOR UPDATE');$stmt->execute([(string)$draft['delivery_attempt_id'],(string)$company['id']]);$attempt=$stmt->fetch();if(!$attempt||(string)$attempt['outcome']!=='ambiguous_after_submission'||(string)$attempt['acceptance_certainty']!=='unknown')fail('Stored delivery evidence does not support ambiguous-outcome recovery.',409,'native_collection_recovery_evidence_invalid');
        if($action==='confirm_sent_external'){$followupId=new_id('followup');$pdo->prepare("INSERT INTO invoice_followups (id,company_id,invoice_id,action_date,level,channel,note,created_by) VALUES (?,?,?,?,?,'email',?,?)")->execute([$followupId,(string)$company['id'],(string)$draft['invoice_id'],canadian_today(),(string)$draft['level'],mb_substr('Delivery confirmed externally for Tegh collection draft '.$id.'. Reason: '.$reason,0,1000),(string)$user['id']]);
            $pdo->prepare("UPDATE native_agent_collection_drafts SET state='sent',sent_at=UTC_TIMESTAMP(),followup_id=?,recovery_status='confirmed_sent_external',recovery_reason=?,recovered_by=?,recovered_at=UTC_TIMESTAMP(),updated_by=? WHERE id=? AND company_id=? AND state='sending'")->execute([$followupId,$reason,(string)$user['id'],(string)$user['id'],$id,(string)$company['id']]);
            audit_event($user,(string)$company['id'],'native_collection.recovery_confirmed_sent','native_agent_collection_draft',$id,['attemptId'=>(string)$attempt['id'],'outboundEmailId'=>(string)$attempt['outbound_email_id'],'reason'=>$reason,'exactAcknowledgement'=>true,'automaticRetry'=>false]);$pdo->commit();return ['ok'=>true,'draft'=>native_collection_public(native_collection_row($company,$id))];}
        $newId=new_id('nacollection');$fingerprint=hash('sha256',(string)$draft['fingerprint'].'|recovery|'.$newId);$pdo->prepare("INSERT INTO native_agent_collection_drafts (id,company_id,invoice_id,fingerprint,level,message_length,channel,recipient_email,subject,body_text,source_revision_hash,content_hash,template_id,template_revision,template_hash,template_snapshot_json,source_facts_json,state,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?,?)")
          ->execute([$newId,(string)$company['id'],(string)$draft['invoice_id'],$fingerprint,(string)$draft['level'],(string)$draft['message_length'],(string)$draft['channel'],(string)$draft['recipient_email'],(string)$draft['subject'],(string)$draft['body_text'],(string)$draft['source_revision_hash'],(string)$draft['content_hash'],$draft['template_id'],$draft['template_revision'],$draft['template_hash'],$draft['template_snapshot_json'],$draft['source_facts_json'],(string)$user['id'],(string)$user['id']]);
        $pdo->prepare("UPDATE native_agent_collection_drafts SET recovery_status='confirmed_not_received',recovery_reason=?,recovered_by=?,recovered_at=UTC_TIMESTAMP(),updated_by=? WHERE id=? AND company_id=? AND state='sending'")->execute([$reason,(string)$user['id'],(string)$user['id'],$id,(string)$company['id']]);
        audit_event($user,(string)$company['id'],'native_collection.recovery_new_draft','native_agent_collection_draft',$id,['attemptId'=>(string)$attempt['id'],'newDraftId'=>$newId,'reason'=>$reason,'exactAcknowledgement'=>true,'duplicateDeliveryWarningAccepted'=>true,'automaticRetry'=>false]);$pdo->commit();return ['ok'=>true,'ambiguousDraft'=>native_collection_public(native_collection_row($company,$id)),'newDraft'=>native_collection_public(native_collection_row($company,$newId))];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_collection_dismiss(array $user,array $company,array $input): array
{
    require_company_permission($company,'invoices.write');$id=clean_text($input['draftId']??'','Collection draft',64);$stmt=db()->prepare("UPDATE native_agent_collection_drafts SET state='dismissed',dismissed_by=?,dismissed_at=UTC_TIMESTAMP(),updated_by=? WHERE id=? AND company_id=? AND state IN ('draft','approved','failed','stale')");$stmt->execute([(string)$user['id'],(string)$user['id'],$id,(string)$company['id']]);if($stmt->rowCount()!==1){$row=native_collection_row($company,$id);if((string)$row['state']!=='dismissed')fail('A sending or sent draft cannot be dismissed.',409,'native_collection_state_invalid');}audit_event($user,(string)$company['id'],'native_collection.dismissed','native_agent_collection_draft',$id,['accountingWrites'=>0]);return ['ok'=>true,'draftId'=>$id,'accountingWrites'=>0];
}

function native_vendor_normalize(string $value): string
{
    $value=mb_strtolower(trim($value));$value=preg_replace('/[^\pL\pN]+/u','',$value)??'';return mb_substr($value,0,500);
}

/** @return array<int,array{type:string,hash:string,hint:?string}> */
function native_vendor_fingerprints(array $candidate): array
{
    $items=[];$add=static function(string $type,string $value,?string $hint=null)use(&$items):void{$normalized=native_vendor_normalize($value);if($normalized==='')return;$items[]=['type'=>$type,'hash'=>hash('sha256','tegh-vendor-fingerprint-v1|'.$type.'|'.$normalized),'hint'=>$hint];};
    $name=trim((string)($candidate['partyName']??''));if($name!=='')$add('legal_name',$name,mb_substr($name,0,160));
    $email=strtolower(trim((string)($candidate['partyEmail']??'')));if($email!==''&&str_contains($email,'@')){$domain=substr(strrchr($email,'@'),1);if($domain!==''&&filter_var('x@'.$domain,FILTER_VALIDATE_EMAIL)!==false)$add('email_domain',$domain,mb_substr($domain,0,160));}
    $identifier=trim((string)($candidate['businessIdentifier']??''));if($identifier!=='')$add('business_identifier',$identifier,'Confirmed business identifier');
    $address=trim((string)($candidate['address']??''));if($address!=='')$add('address',$address,'Confirmed address pattern');
    $layout=trim((string)($candidate['layoutFingerprint']??''));if($layout!=='')$add('layout',$layout,'Confirmed invoice layout');
    $unique=[];foreach($items as $item)$unique[$item['type'].'|'.$item['hash']]=$item;return array_values($unique);
}

function native_vendor_learning_snapshot(array $row): array
{
    return ['id'=>(string)$row['id'],'vendorId'=>(string)$row['vendor_id'],'fingerprintType'=>(string)$row['fingerprint_type'],'positiveCount'=>(int)$row['positive_count'],'negativeCount'=>(int)$row['negative_count'],'conflictCount'=>(int)$row['conflict_count'],'confidenceBps'=>(int)$row['confidence_bps'],'revision'=>(int)$row['revision'],'state'=>(string)$row['state']];
}

/** @return array<int,array<string,mixed>> */
function native_vendor_learning_accept(array $user,array $company,array $document,array $candidate,string $sourceRevision,string $operationBase): array
{
    $vendorId=clean_text($candidate['partyId']??'','Vendor',64);$vendor=db()->prepare("SELECT id,name FROM vendors WHERE id=? AND company_id=? AND active=1 AND status='active' LIMIT 1");$vendor->execute([$vendorId,$company['id']]);$vendorRow=$vendor->fetch();if(!$vendorRow)fail('Choose an active vendor from this company before confirming the association.',409,'native_document_vendor_unavailable',false);
    $fingerprints=native_vendor_fingerprints($candidate);$results=[];
    foreach($fingerprints as $fingerprint){$operationKey=mb_substr($operationBase.'-'.$fingerprint['type'],0,120);$payloadHash=hash('sha256',native_agent_json(['companyId'=>$company['id'],'documentId'=>$document['id'],'sourceRevisionHash'=>$sourceRevision,'vendorId'=>$vendorId,'fingerprintType'=>$fingerprint['type'],'fingerprintHash'=>$fingerprint['hash']]));
        $prior=db()->prepare('SELECT payload_hash FROM vendor_recognition_history WHERE company_id=? AND operation_key=? ORDER BY created_at DESC,id DESC LIMIT 1');$prior->execute([$company['id'],$operationKey]);$priorHash=$prior->fetchColumn();if($priorHash!==false){if(!hash_equals((string)$priorHash,$payloadHash))fail('That reviewed-learning operation key was already used for different evidence.',409,'vendor_learning_operation_conflict',false);continue;}
        $lock=db()->prepare('SELECT * FROM vendor_recognition_rules WHERE company_id=? AND fingerprint_type=? AND fingerprint_hash=? FOR UPDATE');$lock->execute([$company['id'],$fingerprint['type'],$fingerprint['hash']]);$existing=$lock->fetch();$before=$existing?native_vendor_learning_snapshot($existing):[];$event='accepted';
        if(!$existing){$id=new_id('vendorlearn');$positive=1;$negative=0;$conflicts=0;$confidence=8000;$revision=1;db()->prepare("INSERT INTO vendor_recognition_rules (id,company_id,vendor_id,fingerprint_type,fingerprint_hash,display_hint,source_document_id,source_revision_hash,positive_count,negative_count,conflict_count,confidence_bps,revision,state,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,? ,1,'enabled',?,?)")->execute([$id,$company['id'],$vendorId,$fingerprint['type'],$fingerprint['hash'],$fingerprint['hint'],$document['id'],$sourceRevision,$positive,$negative,$conflicts,$confidence,$user['id'],$user['id']]);}
        else{$id=(string)$existing['id'];$revision=(int)$existing['revision']+1;if(hash_equals((string)$existing['vendor_id'],$vendorId)){$positive=(int)$existing['positive_count']+1;$negative=(int)$existing['negative_count'];$conflicts=(int)$existing['conflict_count'];$confidence=max(0,min(9800,7500+$positive*400-$negative*1200-$conflicts*500));}
            else{$event='corrected';$positive=1;$negative=(int)$existing['negative_count']+1;$conflicts=(int)$existing['conflict_count']+1;$confidence=max(6000,8200-$negative*600-$conflicts*400);}
            db()->prepare("UPDATE vendor_recognition_rules SET vendor_id=?,display_hint=?,source_document_id=?,source_revision_hash=?,positive_count=?,negative_count=?,conflict_count=?,confidence_bps=?,revision=?,state='enabled',updated_by=? WHERE id=? AND company_id=?")->execute([$vendorId,$fingerprint['hint'],$document['id'],$sourceRevision,$positive,$negative,$conflicts,$confidence,$revision,$user['id'],$id,$company['id']]);}
        $afterRow=$lock;$afterRow->execute([$company['id'],$fingerprint['type'],$fingerprint['hash']]);$after=$afterRow->fetch();$snapshot=native_vendor_learning_snapshot($after);db()->prepare('INSERT INTO vendor_recognition_history (id,company_id,rule_id,vendor_id,document_id,event_type,rule_revision,before_json,after_json,actor_user_id,request_reference,operation_key,payload_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([new_id('vendorlearnhist'),$company['id'],$id,$vendorId,$document['id'],$event,$revision,native_agent_json($before),native_agent_json($snapshot),$user['id'],request_id(),$operationKey,$payloadHash]);$results[]=$snapshot+['eventType'=>$event,'evidence'=>$fingerprint['hint']];
    }
    return $results;
}

/** @return array<string,mixed> */
function native_vendor_suggestions(array $company,array $document): array
{
    if((string)$document['document_type']!=='vendor_bill')return ['suggestions'=>[],'preselectedVendorId'=>null,'thresholdBps'=>8500,'providerAttempts'=>0];$candidate=json_decode((string)$document['candidate_json'],true);if(!is_array($candidate))$candidate=[];$fingerprints=native_vendor_fingerprints($candidate);$scores=[];
    $stmt=db()->prepare("SELECT id,name,email,address,status,active FROM vendors WHERE company_id=? AND active=1 AND status='active' ORDER BY name,id");$stmt->execute([$company['id']]);foreach($stmt->fetchAll() as $vendor){$id=(string)$vendor['id'];$scores[$id]=['vendorId'=>$id,'vendorName'=>(string)$vendor['name'],'scoreBps'=>0,'evidence'=>[],'source'=>'master_data'];$candidateName=native_vendor_normalize((string)($candidate['partyName']??''));$vendorName=native_vendor_normalize((string)$vendor['name']);if($candidateName!==''&&hash_equals($candidateName,$vendorName)){$scores[$id]['scoreBps']=10000;$scores[$id]['evidence'][]='Exact normalized vendor name';}
        $candidateEmail=strtolower(trim((string)($candidate['partyEmail']??'')));$vendorEmail=strtolower(trim((string)($vendor['email']??'')));if(str_contains($candidateEmail,'@')&&str_contains($vendorEmail,'@')&&hash_equals(substr(strrchr($candidateEmail,'@'),1),substr(strrchr($vendorEmail,'@'),1))){$scores[$id]['scoreBps']=max($scores[$id]['scoreBps'],9200);$scores[$id]['evidence'][]='Exact email domain';}
        if(!empty($candidate['address'])&&!empty($vendor['address'])&&hash_equals(native_vendor_normalize((string)$candidate['address']),native_vendor_normalize((string)$vendor['address']))){$scores[$id]['scoreBps']=max($scores[$id]['scoreBps'],8700);$scores[$id]['evidence'][]='Exact normalized address';}}
    if(function_exists('tegh_schema41_status')&&tegh_schema41_status()['ready']&&$fingerprints){$lookup=db()->prepare("SELECT r.*,v.name vendor_name FROM vendor_recognition_rules r JOIN vendors v ON v.id=r.vendor_id AND v.company_id=r.company_id AND v.active=1 AND v.status='active' WHERE r.company_id=? AND r.fingerprint_type=? AND r.fingerprint_hash=? AND r.state='enabled' LIMIT 2");foreach($fingerprints as $fingerprint){$lookup->execute([$company['id'],$fingerprint['type'],$fingerprint['hash']]);foreach($lookup->fetchAll() as $rule){$id=(string)$rule['vendor_id'];if(!isset($scores[$id]))$scores[$id]=['vendorId'=>$id,'vendorName'=>(string)$rule['vendor_name'],'scoreBps'=>0,'evidence'=>[],'source'=>'confirmed_pattern'];$scores[$id]['scoreBps']=max((int)$scores[$id]['scoreBps'],(int)$rule['confidence_bps']);$scores[$id]['source']='confirmed_pattern';$scores[$id]['evidence'][]='Confirmed prior '.str_replace('_',' ',(string)$rule['fingerprint_type']);}}}
    $suggestions=array_values(array_filter($scores,static fn(array $row):bool=>(int)$row['scoreBps']>=5000));usort($suggestions,static fn(array $a,array $b):int=>[$b['scoreBps'],$a['vendorName'],$a['vendorId']]<=>[$a['scoreBps'],$b['vendorName'],$b['vendorId']]);$top=$suggestions[0]??null;$next=$suggestions[1]??null;$preselected=$top&&(int)$top['scoreBps']>=8500&&(!$next||(int)$top['scoreBps']-(int)$next['scoreBps']>=1000)?(string)$top['vendorId']:null;foreach($suggestions as &$suggestion){$suggestion['preselected']=$preselected!==null&&hash_equals($preselected,(string)$suggestion['vendorId']);$suggestion['reason']=$suggestion['evidence']?implode('; ',array_values(array_unique($suggestion['evidence']))):'Weaker current-company candidate';}$suggestions=array_slice($suggestions,0,8);return ['suggestions'=>$suggestions,'preselectedVendorId'=>$preselected,'thresholdBps'=>8500,'ambiguous'=>$top!==null&&$preselected===null,'providerAttempts'=>0,'accountingWrites'=>0];
}

function native_document_operation_key(mixed $value): string
{
    $key=strtolower(trim((string)$value));if(!preg_match('/^[0-9a-f]{64}$/',$key))fail('Provide a stable operation key for this document action.',422,'operation_key_invalid');return $key;
}

function native_document_receipt(array $user,array $company,string $actionType,string $operationKey,string $payloadHash): ?array
{
    $stmt=db()->prepare('SELECT payload_hash,status,result_json FROM ai_agent_action_authorizations WHERE company_id=? AND user_id=? AND action_type=? AND operation_key=? ORDER BY created_at DESC,id DESC LIMIT 1');$stmt->execute([$company['id'],$user['id'],$actionType,$operationKey]);$row=$stmt->fetch();if(!$row)return null;if(!hash_equals((string)$row['payload_hash'],$payloadHash))fail('That operation key was already used for different document details.',409,'operation_key_conflict',false);$result=json_decode((string)($row['result_json']??''),true);if((string)$row['status']!=='completed'||!is_array($result))fail('The earlier document action is unresolved. Refresh Document Intake before retrying.',409,'document_operation_unresolved',false);$result['replayed']=true;return $result;
}

function native_document_store_receipt(array $user,array $company,string $actionType,string $operationKey,string $payloadHash,array $result): void
{
    db()->prepare("INSERT INTO ai_agent_action_authorizations (id,company_id,user_id,action_type,payload_json,payload_hash,status,expires_at,authorized_at,completed_at,operation_key,result_status,result_json) VALUES (?,?,?,?,?,?,'completed',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,'completed',?)")->execute([new_id('docreceipt'),$company['id'],$user['id'],$actionType,native_agent_json(['payloadHash'=>$payloadHash]),$payloadHash,$operationKey,native_agent_json($result)]);
}

function native_document_create_vendor(array $user,array $company,array $input): array
{
    require_company_permission($company,'vendors.write');$id=clean_text($input['documentId']??'','Document',64);$expected=clean_text($input['sourceRevisionHash']??'','Source revision',64);$operationKey=native_document_operation_key($input['operationKey']??'');$vendorInput=$input['vendor']??null;if(!is_array($vendorInput))fail('Review the extracted vendor details before creating the vendor.',422,'vendor_details_invalid');$payloadHash=hash('sha256',native_agent_json(['documentId'=>$id,'sourceRevisionHash'=>$expected,'vendor'=>$vendorInput]));$actionType='app:document.vendor_create';$pdo=db();$pdo->beginTransaction();try{$companyLock=db()->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');$companyLock->execute([$company['id']]);if($replay=native_document_receipt($user,$company,$actionType,$operationKey,$payloadHash)){$pdo->commit();return $replay;}$row=native_document_row($company,$id,true);if((string)$row['document_type']!=='vendor_bill'||!in_array((string)$row['state'],['review','ready','failed'],true))fail('This document is not available for vendor creation.',409,'native_document_state_invalid');if(!hash_equals((string)$row['source_revision_hash'],$expected))fail('The document review changed. Refresh before creating the vendor.',409,'native_document_source_changed');native_document_verify_file($row);$candidate=json_decode((string)$row['candidate_json'],true);if(!is_array($candidate))$candidate=[];$vendorInput=array_merge(['name'=>(string)($candidate['partyName']??''),'email'=>(string)($candidate['partyEmail']??''),'address'=>(string)($candidate['address']??''),'currency'=>(string)($candidate['currency']??$company['currency']??'CAD'),'paymentTerms'=>(string)($candidate['paymentTerms']??'30')],$vendorInput,['openingBalanceCents'=>0]);$vendor=create_vendor_record($user,$company,$vendorInput,'document_intake');$candidate['partyId']=$vendor['id'];$candidate['partyName']=$vendor['name'];$candidateHash=native_agent_hash($candidate);$sourceRevision=native_document_source_revision($row,$candidateHash);$ready=!empty($candidate['documentDate'])&&(int)($candidate['totalCents']??0)>0;db()->prepare('UPDATE native_agent_documents SET state=?,candidate_json=?,candidate_hash=?,source_revision_hash=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?')->execute([$ready?'ready':'review',native_agent_json($candidate),$candidateHash,$sourceRevision,$user['id'],$id,$company['id']]);$learning=function_exists('tegh_schema41_status')&&tegh_schema41_status()['ready']?native_vendor_learning_accept($user,$company,$row,$candidate,$sourceRevision,'vendor-create-'.$id.'-'.substr($candidateHash,0,24)):[];$result=['ok'=>true,'vendor'=>$vendor,'documentId'=>$id,'sourceRevisionHash'=>$sourceRevision,'vendorLearning'=>$learning,'replayed'=>false,'accountingWrites'=>0,'providerAttempts'=>0];native_document_store_receipt($user,$company,$actionType,$operationKey,$payloadHash,$result);audit_event($user,(string)$company['id'],'native_document.vendor_created','native_agent_document',$id,['vendorId'=>$vendor['id'],'sourceRevisionHash'=>$sourceRevision,'accountingWrites'=>0]);$pdo->commit();return $result;}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_document_import_draft(array $user,array $company,array $input): array
{
    require_company_permission($company,'bills.write');$id=clean_text($input['documentId']??'','Document',64);$expected=clean_text($input['sourceRevisionHash']??'','Source revision',64);$operationKey=native_document_operation_key($input['operationKey']??'');$payloadHash=hash('sha256',native_agent_json(['documentId'=>$id,'sourceRevisionHash'=>$expected,'categoryAccountId'=>$input['categoryAccountId']??'','taxEntryMode'=>$input['taxEntryMode']??'none','applyGstHst'=>!empty($input['applyGstHst']),'applyPst'=>!empty($input['applyPst'])]));$actionType='app:document.vendor_invoice_draft';$pdo=db();$pdo->beginTransaction();try{$companyLock=db()->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');$companyLock->execute([$company['id']]);if($replay=native_document_receipt($user,$company,$actionType,$operationKey,$payloadHash)){$pdo->commit();return $replay;}$row=native_document_row($company,$id,true);if((string)$row['document_type']!=='vendor_bill'||(string)$row['state']!=='ready')fail('Complete and verify this vendor invoice before importing a draft.',409,'native_document_not_ready');if(!hash_equals((string)$row['source_revision_hash'],$expected))fail('The reviewed document changed. Refresh before importing.',409,'native_document_source_changed');native_document_verify_file($row);$candidate=json_decode((string)$row['candidate_json'],true);if(!is_array($candidate)||!hash_equals((string)$row['candidate_hash'],native_agent_hash($candidate)))fail('The reviewed candidate integrity check failed.',409,'native_document_candidate_changed');$vendor=native_document_resolve_party($company,$row,$candidate);$mode=(string)($input['taxEntryMode']??'none');if(!in_array($mode,['none','exclusive','inclusive'],true))fail('Choose the reviewed tax-entry mode.',422,'tax_entry_mode_invalid');$amount=$mode==='exclusive'?(int)($candidate['subtotalCents']??0):(int)($candidate['totalCents']??0);$billInput=['vendorId'=>$vendor['id'],'number'=>(string)($candidate['documentNumber']??''),'billDate'=>(string)($candidate['documentDate']??''),'dueDate'=>(string)($candidate['dueDate']??''),'categoryAccountId'=>$input['categoryAccountId']??'','paymentTermsDays'=>$input['paymentTermsDays']??30,'currency'=>(string)($candidate['currency']??$company['currency']??'CAD'),'exchangeRateMicros'=>$input['exchangeRateMicros']??1000000,'foreignAmountCents'=>$amount,'taxEntryMode'=>$mode,'applyGstHst'=>!empty($input['applyGstHst']),'applyPst'=>!empty($input['applyPst']),'memo'=>(string)($candidate['memo']??''),'importReference'=>'native-document:'.$id.':'.substr((string)$row['sha256'],0,40),'issue'=>false];$validated=bill_input_values($company,$billInput);if(isset($candidate['subtotalCents'])&&(int)$candidate['subtotalCents']!==(int)$validated['foreignSubtotal'])fail('The chosen tax treatment does not reproduce the reviewed subtotal.',422,'native_document_tax_mismatch');if(isset($candidate['taxCents'])&&(int)$candidate['taxCents']!==((int)$validated['foreignGst']+(int)$validated['foreignPst']))fail('The chosen tax treatment does not reproduce the reviewed tax.',422,'native_document_tax_mismatch');if((int)$candidate['totalCents']!==(int)$validated['foreignTotal'])fail('The chosen tax treatment does not reproduce the reviewed total.',422,'native_document_tax_mismatch');$bill=create_bill_record($user,$company,$billInput,'document_intake');if($bill['journalEntryId']!==null||(string)$bill['status']!=='draft')throw new RuntimeException('Document Intake must create a draft vendor invoice only.');db()->prepare("UPDATE native_agent_documents SET state='linked',linked_entity_type='bill',linked_entity_id=?,linked_by=?,linked_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND state='ready'")->execute([$bill['id'],$user['id'],$id,$company['id']]);$result=['ok'=>true,'bill'=>$bill,'documentId'=>$id,'linked'=>true,'replayed'=>false,'accountingWrites'=>0,'paymentsCreated'=>0,'providerAttempts'=>0];native_document_store_receipt($user,$company,$actionType,$operationKey,$payloadHash,$result);audit_event($user,(string)$company['id'],'native_document.draft_vendor_invoice_created','native_agent_document',$id,['billId'=>$bill['id'],'sourceRevisionHash'=>$expected,'sourceSha256'=>(string)$row['sha256'],'accountingWrites'=>0,'paymentWrites'=>0]);$pdo->commit();return $result;}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function native_vendor_learning_manage(array $user,array $company,array $input): array
{
    require_company_role($company,'owner');if(!function_exists('tegh_schema41_status')||!tegh_schema41_status()['ready'])fail('Reviewed vendor learning is unavailable until Schema 41 is complete.',503,'vendor_learning_schema_unavailable');$ruleId=clean_text($input['ruleId']??'','Vendor-learning rule',64);$action=(string)($input['action']??'');if(!in_array($action,['disable','reset','retire'],true))fail('Choose disable, reset or retire.',422,'vendor_learning_action_invalid');$reason=mb_substr(trim((string)($input['reason']??'')),0,500);if(mb_strlen($reason)<8)fail('Provide a reason of at least 8 characters.',422,'vendor_learning_reason_required');$operationKey=native_document_operation_key($input['operationKey']??'');$payloadHash=hash('sha256',native_agent_json([$ruleId,$action,$reason]));$pdo=db();$pdo->beginTransaction();try{$prior=$pdo->prepare('SELECT payload_hash,after_json FROM vendor_recognition_history WHERE company_id=? AND operation_key=? ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE');$prior->execute([$company['id'],$operationKey]);$priorRow=$prior->fetch();if($priorRow){if(!hash_equals((string)$priorRow['payload_hash'],$payloadHash))fail('That vendor-learning operation key was already used for different instructions.',409,'vendor_learning_operation_conflict',false);$stored=json_decode((string)$priorRow['after_json'],true);if(!is_array($stored))throw new RuntimeException('Stored vendor-learning replay evidence is malformed.');$pdo->commit();return ['ok'=>true,'rule'=>$stored,'replayed'=>true];}$lock=db()->prepare('SELECT * FROM vendor_recognition_rules WHERE id=? AND company_id=? FOR UPDATE');$lock->execute([$ruleId,$company['id']]);$row=$lock->fetch();if(!$row)fail('That learned association is unavailable.',404,'vendor_learning_not_found',false);$before=native_vendor_learning_snapshot($row);$revision=(int)$row['revision']+1;$state=$action==='disable'?'disabled':($action==='retire'?'retired':'enabled');$positive=$action==='reset'?0:(int)$row['positive_count'];$negative=$action==='reset'?0:(int)$row['negative_count'];$conflict=$action==='reset'?0:(int)$row['conflict_count'];$confidence=$action==='reset'?0:(int)$row['confidence_bps'];db()->prepare('UPDATE vendor_recognition_rules SET state=?,positive_count=?,negative_count=?,conflict_count=?,confidence_bps=?,revision=?,updated_by=? WHERE id=? AND company_id=?')->execute([$state,$positive,$negative,$conflict,$confidence,$revision,$user['id'],$ruleId,$company['id']]);$lock->execute([$ruleId,$company['id']]);$after=native_vendor_learning_snapshot($lock->fetch());$event=$action==='disable'?'disabled':($action==='retire'?'retired':'reset');db()->prepare('INSERT INTO vendor_recognition_history (id,company_id,rule_id,vendor_id,document_id,event_type,rule_revision,before_json,after_json,actor_user_id,request_reference,operation_key,payload_hash) VALUES (?,?,?,?,NULL,?,?,?,?,?,?,?,?)')->execute([new_id('vendorlearnhist'),$company['id'],$ruleId,$row['vendor_id'],$event,$revision,native_agent_json($before),native_agent_json($after),$user['id'],request_id(),$operationKey,$payloadHash]);audit_event($user,(string)$company['id'],'vendor_recognition.'.$action,'vendor_recognition_rule',$ruleId,['reason'=>$reason,'revision'=>$revision]);$pdo->commit();return ['ok'=>true,'rule'=>$after,'replayed'=>false];}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();if($error instanceof PDOException&&(string)$error->getCode()==='23000')fail('That vendor-learning operation was already completed or conflicted.',409,'vendor_learning_operation_conflict',false);throw $error;}
}

function native_vendor_learning_list(array $company): array
{
    require_company_role($company,'owner');if(!function_exists('tegh_schema41_status')||!tegh_schema41_status()['ready'])return ['rules'=>[],'schemaReady'=>false];$stmt=db()->prepare('SELECT r.*,v.name vendor_name FROM vendor_recognition_rules r JOIN vendors v ON v.id=r.vendor_id AND v.company_id=r.company_id WHERE r.company_id=? ORDER BY r.state,r.updated_at DESC,r.id DESC LIMIT 500');$stmt->execute([$company['id']]);$rules=[];foreach($stmt->fetchAll() as $row)$rules[]=native_vendor_learning_snapshot($row)+['vendorName'=>(string)$row['vendor_name'],'displayHint'=>$row['display_hint'],'updatedAt'=>(string)$row['updated_at']];return ['rules'=>$rules,'schemaReady'=>true,'rawOcrStored'=>false];
}

function native_documents_list(array $company,array $filters=[]): array
{
    require_company_permission($company,'attachments.view');$params=[(string)$company['id']];$where=['d.company_id=?'];$type=(string)($filters['documentType']??'');if($type!==''&&!in_array($type,NATIVE_DOCUMENT_TYPES,true))fail('Choose a supported document type.',422,'native_document_filter_invalid');if($type!==''){$where[]='d.document_type=?';$params[]=$type;}$state=(string)($filters['state']??'');if($state!==''&&!in_array($state,NATIVE_DOCUMENT_STATES,true))fail('Choose a supported document state.',422,'native_document_filter_invalid');if($state!==''){$where[]='d.state=?';$params[]=$state;}$stmt=db()->prepare('SELECT d.*,u.display_name uploaded_by_name FROM native_agent_documents d LEFT JOIN users u ON u.id=d.uploaded_by WHERE '.implode(' AND ',$where).' ORDER BY d.updated_at DESC,d.id DESC LIMIT 250');$stmt->execute($params);$rows=[];foreach($stmt->fetchAll() as $row){if(company_role_can((string)$company['role'],native_document_permission((string)$row['document_type'],false)))$rows[]=native_document_public($row);}return ['documents'=>$rows,'count'=>count($rows),'accountingWrites'=>0,'providerAttempts'=>0];
}

function native_collections_list(array $company,array $filters=[]): array
{
    require_company_permission($company,'invoices.view');$params=[(string)$company['id']];$where=['d.company_id=?'];$state=(string)($filters['state']??'');if($state!==''&&!in_array($state,NATIVE_COLLECTION_STATES,true))fail('Choose a supported collection state.',422,'native_collection_filter_invalid');if($state!==''){$where[]='d.state=?';$params[]=$state;}$stmt=db()->prepare("SELECT d.*,i.number invoice_number,i.customer_id,i.due_date,i.balance_cents,i.currency,c.name customer_name FROM native_agent_collection_drafts d JOIN invoices i ON i.id=d.invoice_id AND i.company_id=d.company_id JOIN customers c ON c.id=i.customer_id AND c.company_id=d.company_id WHERE ".implode(' AND ',$where).' ORDER BY d.updated_at DESC,d.id DESC LIMIT 250');$stmt->execute($params);$rows=array_map('native_collection_public',$stmt->fetchAll());return ['drafts'=>$rows,'count'=>count($rows),'accountingWrites'=>0,'providerAttempts'=>0];
}

function handle_native_ap_ar(string $action): never
{
    $user=require_user();$company=require_company($user);$action=trim($action,'/');
    if($action===''||$action==='overview'){require_method('GET');json_response(['documents'=>native_documents_list($company),'collections'=>native_collections_list($company),'nativeOnly'=>true,'providerAttempts'=>0,'accountingWrites'=>0]);}
    if($action==='documents'){require_method('GET');json_response(native_documents_list($company,$_GET));}
    if($action==='document/upload'){require_method('POST');require_csrf();json_response(native_document_upload($user,$company),201);}
    if($action==='document/file'){require_method('GET');$row=native_document_row($company,clean_text($_GET['documentId']??'','Document',64));require_company_permission($company,'attachments.view');if(!valid_private_storage_reference((string)$company['id'],(string)$row['storage_path'],'native-documents'))fail('The private document source is unavailable.',404,'native_document_source_missing');stream_private_file((string)$row['storage_path'],(string)$row['original_name']);}
    if($action==='document/review'){require_method('POST');require_csrf();json_response(native_document_review($user,$company,request_json()));}
    if($action==='document/vendor-suggestions'){require_method('GET');$row=native_document_row($company,clean_text($_GET['documentId']??'','Document',64));json_response(native_vendor_suggestions($company,$row));}
    if($action==='document/create-vendor'){require_method('POST');require_csrf();json_response(native_document_create_vendor($user,$company,request_json()),201);}
    if($action==='document/import-draft'){require_method('POST');require_csrf();json_response(native_document_import_draft($user,$company,request_json()),201);}
    if($action==='document/extraction-failure'){require_method('POST');require_csrf();json_response(native_document_failure($user,$company,request_json()));}
    if($action==='document/prepare'){require_method('POST');require_csrf();json_response(native_document_prepare($user,$company,request_json()));}
    if($action==='document/link'){require_method('POST');require_csrf();json_response(native_document_link($user,$company,request_json()));}
    if($action==='document/dismiss'){require_method('POST');require_csrf();json_response(native_document_dismiss($user,$company,request_json()));}
    if($action==='vendor-learning'){if(request_method()==='GET')json_response(native_vendor_learning_list($company));require_method('POST');require_csrf();json_response(native_vendor_learning_manage($user,$company,request_json()));}
    if($action==='collections'){require_method('GET');json_response(native_collections_list($company,$_GET));}
    if($action==='collections/templates'){
        if(request_method()==='GET')json_response(native_collection_templates($company));
        require_method('POST');require_csrf();json_response(native_collection_template_save($user,$company,request_json()),201);
    }
    if($action==='collections/prepare'){require_method('POST');require_csrf();json_response(native_collection_prepare($user,$company,request_json()),201);}
    if($action==='collections/draft'){require_method('PUT');require_csrf();json_response(native_collection_update($user,$company,request_json()));}
    if($action==='collections/approve'){require_method('POST');require_csrf();json_response(native_collection_approve($user,$company,request_json()));}
    if($action==='collections/send'){require_method('POST');require_csrf();json_response(native_collection_send($user,$company,request_json()));}
    if($action==='collections/delivery'){require_method('GET');json_response(native_collection_delivery_record($company,clean_text($_GET['draftId']??'','Collection draft',64)));}
    if($action==='collections/recovery'){require_method('POST');require_csrf();json_response(native_collection_recover($user,$company,request_json()));}
    if($action==='collections/dismiss'){require_method('POST');require_csrf();json_response(native_collection_dismiss($user,$company,request_json()));}
    fail('Native AP/AR route not found.',404,'route_not_found');
}
