<?php
declare(strict_types=1);

/** Invoice operations never infer tenant access from a document ID. */
function tegh_invoice_row_r20(string $companyId,string $invoiceId,bool $lock=false): array
{
    $q=db()->prepare('SELECT * FROM invoices WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$companyId,$invoiceId]);$row=$q->fetch();
    if(!$row)fail('That invoice is not available in this company.',404,'invoice_unavailable');
    return $row;
}
function tegh_invoice_lines_r20(string $invoiceId): array
{
    $q=db()->prepare('SELECT * FROM invoice_lines WHERE invoice_id=? ORDER BY sort_order,id');$q->execute([$invoiceId]);return $q->fetchAll();
}
function tegh_invoice_canonical_r20(mixed $v): mixed
{
    if(!is_array($v))return $v;
    if(!array_is_list($v))ksort($v,SORT_STRING);
    return array_map('tegh_invoice_canonical_r20',$v);
}
function tegh_invoice_hash_r20(array $v): string
{
    return hash('sha256',json_encode(tegh_invoice_canonical_r20($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
function tegh_invoice_revision_r20(array $invoice,array $lines): string
{
    return tegh_invoice_hash_r20(['invoice'=>$invoice,'lines'=>$lines]);
}
function tegh_invoice_assert_revision_r20(array $invoice,array $lines,array $input): void
{
    $expected=(string)($input['expectedRevision']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||!hash_equals(tegh_invoice_revision_r20($invoice,$lines),$expected))
        fail('This invoice changed. Refresh its details before applying your changes; your form has been preserved.',409,'invoice_revision_conflict');
}
function tegh_invoice_operation_key_r20(array $input): string
{
    $key=(string)($input['operationKey']??'');
    if(!preg_match('/^[A-Za-z0-9_-]{16,120}$/D',$key))fail('A valid operation key is required.',422,'invoice_operation_key_required');
    return $key;
}
function tegh_invoice_assert_not_sending_r20(string $companyId,string $invoiceId): void
{
    if(!schema_table_exists('invoice_document_operations'))return;
    $q=db()->prepare("SELECT id FROM invoice_document_operations WHERE company_id=? AND invoice_id=? AND operation_type='send' AND status='pending' LIMIT 1");$q->execute([$companyId,$invoiceId]);
    if($q->fetchColumn())fail('An invoice email is in progress or awaiting its result. Check Delivery before changing or sending this invoice again.',409,'invoice_send_pending');
}
function tegh_invoice_operation_replay_r20(array $invoice,array $user,string $kind,array $payload): ?array
{
    $key=tegh_invoice_operation_key_r20($payload);
    $q=db()->prepare('SELECT * FROM invoice_document_operations WHERE company_id=? AND actor_id=? AND operation_key=? FOR UPDATE');$q->execute([$invoice['company_id'],$user['id'],$key]);$old=$q->fetch();
    if(!$old)return null;
    if($old['invoice_id']!==$invoice['id']||$old['operation_type']!==$kind||!hash_equals($old['payload_hash'],tegh_invoice_hash_r20($payload)))
        fail('This operation key was already used for different details. Review the earlier operation.',409,'invoice_operation_conflict');
    $result=json_decode((string)($old['result_json']??''),true);
    return ['ok'=>true,'replayed'=>true,'operationId'=>$old['id'],'status'=>$old['status']]+(is_array($result)?$result:['message'=>'Outcome pending. Check Delivery; do not send again.']);
}
function tegh_invoice_operation_create_r20(array $invoice,array $user,string $kind,array $payload): string
{
    $id=new_id('invop');
    db()->prepare('INSERT INTO invoice_document_operations(id,company_id,invoice_id,actor_id,operation_type,operation_key,payload_hash,source_revision) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$id,$invoice['company_id'],$invoice['id'],$user['id'],$kind,tegh_invoice_operation_key_r20($payload),tegh_invoice_hash_r20($payload),$payload['expectedRevision']]);
    return $id;
}
function tegh_invoice_operation_complete_r20(string $id,array $result,string $status='completed',?string $emailId=null): array
{
    db()->prepare('UPDATE invoice_document_operations SET status=?,result_json=?,outbound_email_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
        ->execute([$status,json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$emailId,$id]);
    return ['ok'=>true,'operationId'=>$id,'status'=>$status]+$result;
}
function tegh_invoice_attachment_rows_r20(string $companyId,string $invoiceId): array
{
    $q=db()->prepare('SELECT id,original_name,mime,size_bytes,sha256,created_at FROM invoice_attachments WHERE company_id=? AND invoice_id=? AND removed_at IS NULL ORDER BY created_at,id');$q->execute([$companyId,$invoiceId]);
    return array_map(static fn($r)=>['id'=>$r['id'],'name'=>$r['original_name'],'mime'=>$r['mime'],'sizeBytes'=>(int)$r['size_bytes'],'sha256'=>$r['sha256'],'createdAt'=>$r['created_at']],$q->fetchAll());
}
function tegh_invoice_detail_r20(array $user,array $company,string $id): array
{
    $pdo=db();$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
    try {
        $i=tegh_invoice_row_r20($company['id'],$id);$lines=tegh_invoice_lines_r20($id);
        $write=company_role_can($company['role'],'invoices.write');$attachView=company_role_can($company['role'],'attachments.view');
        $draft=$i['status']==='draft'&&empty($i['issued_journal_entry_id'])&&empty($i['is_opening_document']);
        $history=[];$q=$pdo->prepare("SELECT id,operation_type,status,result_json,source_revision,created_at,updated_at FROM invoice_document_operations WHERE company_id=? AND invoice_id=? AND operation_type='send' ORDER BY created_at DESC,id DESC LIMIT 100");$q->execute([$company['id'],$id]);
        foreach($q->fetchAll() as $r){$result=json_decode((string)$r['result_json'],true)?:[];$history[]=['id'=>$r['id'],'status'=>$r['status'],'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at'],'revision'=>$r['source_revision'],'recipient'=>$result['recipient']??null,'transportStatus'=>$result['transportStatus']??null,'acceptanceCertainty'=>$result['acceptanceCertainty']??null,'message'=>$result['message']??'Result pending. No automatic resend.'];}
        $out=['ok'=>true,'invoice'=>['id'=>$i['id'],'number'=>$i['number'],'status'=>$i['status'],'customerId'=>$i['customer_id'],'issueDate'=>$i['issue_date'],'dueDate'=>$i['due_date'],'currency'=>$i['currency'],'exchangeRateMicros'=>(int)$i['exchange_rate_micros'],'purchaseOrder'=>$i['purchase_order'],'message'=>$i['message'],'templateId'=>$i['template_id'],'importReference'=>$i['import_reference'],'isRecurring'=>(bool)$i['is_recurring'],'totalCents'=>(int)$i['total_cents'],'foreignTotalCents'=>(int)$i['foreign_total_cents']],
            'revision'=>tegh_invoice_revision_r20($i,$lines),'capabilities'=>['edit'=>$write&&$i['status']!=='void','editFinancial'=>$write&&$draft,'send'=>$write&&in_array($i['status'],['sent','paid'],true),'viewAttachments'=>$attachView,'writeAttachments'=>$write&&company_role_can($company['role'],'attachments.write')&&$i['status']!=='void'],
            'lines'=>array_map(static fn($l)=>['id'=>$l['id'],'description'=>$l['description'],'quantity'=>(int)$l['quantity_milli']/1000,'unitPriceCents'=>($i['currency']===$company['currency']&&(int)$l['foreign_unit_price_cents']===0&&(int)$l['unit_price_cents']!==0?(int)$l['unit_price_cents']:(int)$l['foreign_unit_price_cents']),'taxable'=>(int)$l['tax_rate_bps']>0,'taxRateBps'=>(int)$l['tax_rate_bps'],'productServiceId'=>$l['product_service_id'],'incomeAccountId'=>$l['income_account_id']],$lines),
            'attachments'=>$attachView?tegh_invoice_attachment_rows_r20($company['id'],$id):[],'delivery'=>$history,'deliveryLimit'=>100,
            'limits'=>['fileBytes'=>10485760,'totalBytes'=>104857600,'files'=>20,'emailAttachmentBytes'=>2097152],
            'editPolicy'=>$draft?'Draft lines and totals are recalculated on the server.':'Issued financial values are locked. Edit due date, purchase order and message only; use the existing correction workflow for accounting changes.'];
        $pdo->commit();return $out;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function tegh_invoice_edit_policy_r20(array $invoice,array $input): bool
{
    if($invoice['status']==='void')fail('A void invoice cannot be edited.',409,'invoice_void');
    $financial=$invoice['status']==='draft'&&empty($invoice['issued_journal_entry_id'])&&empty($invoice['is_opening_document']);
    $allowed=['invoiceId','expectedRevision','operationKey','dueDate','purchaseOrder','message'];
    if($financial)$allowed=array_merge($allowed,['customerId','issueDate','currency','exchangeRateMicros','lines','templateId','taxOverrideReason']);
    if(array_diff(array_keys($input),$allowed))fail($financial?'An unsupported invoice field was supplied.':'Issued amounts, customer, currency and invoice date cannot be changed here. Use the accounting correction workflow.',422,'invoice_edit_fields_forbidden');
    return $financial;
}
function tegh_invoice_edit_r20(array $user,array $company,array $input): array
{
    require_company_permission($company,'invoices.write');$pdo=db();$pdo->beginTransaction();
    try {
        $i=tegh_invoice_row_r20($company['id'],(string)($input['invoiceId']??''),true);
        if($old=tegh_invoice_operation_replay_r20($i,$user,'edit',$input)){$pdo->commit();return $old;}
        tegh_invoice_assert_not_sending_r20($company['id'],$i['id']);$lines=tegh_invoice_lines_r20($i['id']);tegh_invoice_assert_revision_r20($i,$lines,$input);
        $financial=tegh_invoice_edit_policy_r20($i,$input);assert_period_open($company['id'],$i['issue_date']);
        $due=safe_date($input['dueDate']??$i['due_date'],'Due date');$message=optional_text($input['message']??$i['message'],1000)??'';$po=optional_text($input['purchaseOrder']??$i['purchase_order'],80);
        if($due<($financial?($input['issueDate']??$i['issue_date']):$i['issue_date']))fail('Due date cannot precede the invoice date.',422,'invoice_due_date_invalid');
        $op=tegh_invoice_operation_create_r20($i,$user,'edit',$input);
        if($financial){
            $data=$input+['importReference'=>$i['import_reference'],'issue'=>false];$data['message']=$message;
            $v=prepare_invoice_record_r20($user,$company,$data,$i['id']);assert_period_open($company['id'],$v['issueDate']);
            $pdo->prepare('UPDATE invoices SET customer_id=?,issue_date=?,due_date=?,subtotal_cents=?,tax_cents=?,total_cents=?,balance_cents=?,message=?,currency=?,exchange_rate_micros=?,foreign_subtotal_cents=?,foreign_tax_cents=?,foreign_total_cents=?,foreign_balance_cents=?,purchase_order=?,template_id=?,template_snapshot_json=?,customer_snapshot_json=?,updated_at=CURRENT_TIMESTAMP WHERE company_id=? AND id=?')
                ->execute([$v['customerId'],$v['issueDate'],$v['dueDate'],$v['subtotal'],$v['tax'],$v['total'],$v['total'],$message,$v['currency'],$v['exchangeRateMicros'],$v['foreignSubtotal'],$v['foreignTax'],$v['foreignTotal'],$v['foreignTotal'],$po,$v['template']['id'],json_encode($v['templateSnapshot'],JSON_THROW_ON_ERROR),json_encode($v['customerSnapshot'],JSON_THROW_ON_ERROR),$company['id'],$i['id']]);
            $pdo->prepare('DELETE FROM invoice_lines WHERE invoice_id=?')->execute([$i['id']]);
            $s=$pdo->prepare('INSERT INTO invoice_lines(id,invoice_id,product_service_id,income_account_id,description,quantity_milli,unit_price_cents,tax_rate_bps,amount_cents,tax_cents,foreign_unit_price_cents,foreign_amount_cents,foreign_tax_cents,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach($v['calculatedLines'] as $l)$s->execute([new_id('iline'),$i['id'],$l['productServiceId'],$l['incomeAccountId'],$l['description'],$l['quantityMilli'],$l['unitPriceCents'],$l['taxRateBps'],$l['amountCents'],$l['taxCents'],$l['foreignUnitPriceCents'],$l['foreignAmountCents'],$l['foreignTaxCents'],$l['sortOrder']]);
            if(function_exists('voucher_register_saved'))voucher_register_saved($user,$company['id'],'CI','AR','invoice',$i['id'],$v['issueDate'],'Customer invoice '.$i['number'],$v['total'],null,false);
            if(function_exists('voucher_update_saved'))voucher_update_saved($company['id'],'invoice',$i['id'],$v['issueDate'],'Customer invoice '.$i['number'],$v['total']);
        }else $pdo->prepare('UPDATE invoices SET due_date=?,purchase_order=?,message=?,updated_at=CURRENT_TIMESTAMP WHERE company_id=? AND id=?')->execute([$due,$po,$message,$company['id'],$i['id']]);
        $fresh=tegh_invoice_row_r20($company['id'],$i['id']);$revision=tegh_invoice_revision_r20($fresh,tegh_invoice_lines_r20($i['id']));
        audit_event($user,$company['id'],'invoice.edited','invoice',$i['id'],['operationId'=>$op,'previousRevision'=>$input['expectedRevision'],'revision'=>$revision,'financialDraftEdit'=>$financial,'beforeTotalCents'=>(int)$i['total_cents'],'afterTotalCents'=>(int)$fresh['total_cents'],'changedFields'=>array_values(array_diff(array_keys($input),['invoiceId','expectedRevision','operationKey'])),'taxOverrides'=>$v['taxOverrides']??[],'journalsCreated'=>0]);
        $result=tegh_invoice_operation_complete_r20($op,['invoiceId'=>$i['id'],'revision'=>$revision,'message'=>'Invoice saved. No accounting entry was posted.','journalsCreated'=>0]);$pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function tegh_invoice_validate_upload_r20(array $file): array
{
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))fail('Choose a complete uploaded file.',422,'invoice_attachment_upload_invalid');
    $path=$file['tmp_name'];$size=filesize($path);
    if($size===false||$size<1||$size>10485760||$size!==(int)($file['size']??0))fail('Attachments must be between 1 byte and 10 MB.',422,'invoice_attachment_size');
    $name=basename(str_replace('\\','/',(string)($file['name']??'')));$name=preg_replace('/[\x00-\x1f\x7f]/u','',$name)??'';
    if($name===''||mb_strlen($name)>200)fail('Use a filename of 1–200 characters.',422,'invoice_attachment_name');
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    $pairs=['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'];
    if(!isset($pairs[$ext])||$pairs[$ext]!==$mime)fail('Only genuine PDF, PNG, JPEG or WebP files are accepted. The extension must match the contents.',422,'invoice_attachment_type');
    if($ext==='pdf'){if(file_get_contents($path,false,null,0,5)!=='%PDF-')fail('The PDF signature is invalid.',422,'invoice_attachment_signature');}
    else { $info=@getimagesize($path);if(!$info||($info['mime']??'')!==$mime||$info[0]*$info[1]>40000000)fail('The image is invalid or exceeds 40 megapixels.',422,'invoice_attachment_image'); }
    return ['name'=>$name,'extension'=>$ext,'mime'=>$mime,'size'=>$size,'sha256'=>hash_file('sha256',$path),'tmp'=>$path];
}
function tegh_invoice_upload_r20(array $user,array $company,array $input,array $file): array
{
    require_company_permission($company,'invoices.write');require_company_permission($company,'attachments.write');$f=tegh_invoice_validate_upload_r20($file);
    $input+=['fileSha256'=>$f['sha256'],'fileName'=>$f['name'],'fileSize'=>$f['size']];
    // Never trust a client-supplied file hash/name in the idempotency receipt.
    $input['fileSha256']=$f['sha256'];$input['fileName']=$f['name'];$input['fileSize']=$f['size'];
    $pdo=db();$saved=null;$pdo->beginTransaction();
    try{
        $i=tegh_invoice_row_r20($company['id'],(string)($input['invoiceId']??''),true);
        if($old=tegh_invoice_operation_replay_r20($i,$user,'attach',$input)){$pdo->commit();return $old;}
        if($i['status']==='void')fail('Attachments cannot be added to a void invoice.',409,'invoice_void');
        tegh_invoice_assert_revision_r20($i,tegh_invoice_lines_r20($i['id']),$input);
        $q=$pdo->prepare('SELECT COALESCE(SUM(CASE WHEN removed_at IS NULL THEN 1 ELSE 0 END),0),COALESCE(SUM(size_bytes),0) FROM invoice_attachments WHERE company_id=? AND invoice_id=?');$q->execute([$company['id'],$i['id']]);$usage=$q->fetch(PDO::FETCH_NUM);
        if((int)$usage[0]>=20||(int)$usage[1]+$f['size']>104857600)fail('This invoice allows 20 active attachments and 100 MB total retained evidence.',422,'invoice_attachment_limit');
        $op=tegh_invoice_operation_create_r20($i,$user,'attach',$input);$id=new_id('invatt');
        $relative=$company['id'].'/invoice-attachments/'.bin2hex(random_bytes(24)).'.'.$f['extension'];$saved=private_storage_root().'/'.$relative;$dir=dirname($saved);
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Private attachment directory is unavailable.');
        if(!move_uploaded_file($f['tmp'],$saved))throw new RuntimeException('Private attachment storage failed.');chmod($saved,0600);
        $pdo->prepare('INSERT INTO invoice_attachments(id,company_id,invoice_id,original_name,storage_path,mime,size_bytes,sha256,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$company['id'],$i['id'],$f['name'],$relative,$f['mime'],$f['size'],$f['sha256'],$user['id']]);
        audit_event($user,$company['id'],'invoice.attachment_added','invoice',$i['id'],['attachmentId'=>$id,'sha256'=>$f['sha256'],'sizeBytes'=>$f['size'],'operationId'=>$op]);
        $result=tegh_invoice_operation_complete_r20($op,['attachmentId'=>$id,'message'=>'Attachment stored privately. It has not been emailed.']);$pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($saved!==null&&is_file($saved))@unlink($saved);throw $e;}
}
function tegh_invoice_remove_attachment_r20(array $user,array $company,array $input): array
{
    require_company_permission($company,'invoices.write');require_company_permission($company,'attachments.write');$pdo=db();$pdo->beginTransaction();
    try{
        $i=tegh_invoice_row_r20($company['id'],(string)($input['invoiceId']??''),true);
        if($old=tegh_invoice_operation_replay_r20($i,$user,'remove',$input)){$pdo->commit();return $old;}
        tegh_invoice_assert_not_sending_r20($company['id'],$i['id']);tegh_invoice_assert_revision_r20($i,tegh_invoice_lines_r20($i['id']),$input);
        if($i['status']==='void')fail('Attachments on a void invoice are retained.',409,'invoice_void');
        $q=$pdo->prepare('SELECT * FROM invoice_attachments WHERE company_id=? AND invoice_id=? AND id=? AND removed_at IS NULL FOR UPDATE');$q->execute([$company['id'],$i['id'],(string)($input['attachmentId']??'')]);$a=$q->fetch();if(!$a)fail('That attachment is not available.',404,'invoice_attachment_unavailable');
        $op=tegh_invoice_operation_create_r20($i,$user,'remove',$input);
        $pdo->prepare('UPDATE invoice_attachments SET removed_by=?,removed_at=CURRENT_TIMESTAMP WHERE company_id=? AND id=?')->execute([$user['id'],$company['id'],$a['id']]);
        audit_event($user,$company['id'],'invoice.attachment_removed','invoice',$i['id'],['attachmentId'=>$a['id'],'operationId'=>$op,'retainedForAudit'=>true]);
        $result=tegh_invoice_operation_complete_r20($op,['message'=>'Attachment removed from active use; its audit evidence is retained.']);$pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function tegh_invoice_attachment_file_r20(array $company,string $invoiceId,string $attachmentId): array
{
    require_company_permission($company,'attachments.view');tegh_invoice_row_r20($company['id'],$invoiceId);
    $q=db()->prepare('SELECT * FROM invoice_attachments WHERE company_id=? AND invoice_id=? AND id=? AND removed_at IS NULL');$q->execute([$company['id'],$invoiceId,$attachmentId]);$a=$q->fetch();
    if(!$a)fail('That attachment is not available.',404,'invoice_attachment_unavailable');
    if(!str_starts_with($a['storage_path'],$company['id'].'/invoice-attachments/'))fail('Attachment ownership cannot be verified.',409,'invoice_attachment_scope');
    $path=private_absolute_path($a['storage_path']);
    if(!$path||!is_file($path)||filesize($path)!==(int)$a['size_bytes']||!hash_equals($a['sha256'],hash_file('sha256',$path)))fail('Attachment integrity could not be verified. Restore or replace the evidence before using it.',409,'invoice_attachment_integrity');
    return $a+['absolutePath'=>$path];
}
function tegh_invoice_send_fields_r20(array $input): array
{
    $recipient=trim((string)($input['recipient']??''));$subject=trim((string)($input['subject']??''));$message=trim((string)($input['message']??''));
    if(preg_match('/[\r\n\x00]/',$recipient.$subject)||!filter_var($recipient,FILTER_VALIDATE_EMAIL)||strlen($recipient)>254)fail('Enter one valid recipient email address.',422,'invoice_email_invalid');
    if($subject===''||mb_strlen($subject)>180||mb_strlen($message)>2000)fail('Use a subject of 1–180 characters and a message of at most 2,000 characters.',422,'invoice_email_text_invalid');
    $ids=$input['attachmentIds']??[];if(!is_array($ids)||count($ids)>20||array_filter($ids,static fn($x)=>!is_string($x)||strlen($x)>64))fail('Choose valid invoice attachments.',422,'invoice_email_attachments_invalid');
    $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);
    return ['recipient'=>$recipient,'subject'=>$subject,'message'=>$message,'attachmentIds'=>$ids];
}
function tegh_invoice_email_body_r20(array $m,string $message): array
{
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $money=static fn($c)=>$m['currency'].' '.number_format((int)$c/100,2,'.',',');$d=$m['document'];$lines='';$text=[];
    foreach($m['rows'] as $l){$lines.='<tr><td style="padding:9px;border-bottom:1px solid #ddd">'.$e($l['description']).'</td><td style="padding:9px;text-align:right">'.$e($l['quantity']).'</td><td style="padding:9px;text-align:right">'.$e($money($l['unitPriceCents'])).'</td><td style="padding:9px;text-align:right">'.$e($money($l['lineTotalCents'])).'</td></tr>';$text[]=$l['description'].' | Qty '.$l['quantity'].' | '.$money($l['lineTotalCents']);}
    $plain=$m['issuer']['businessName']."\nInvoice ".$d['number']."\nBill to: ".$m['billTo']['name']."\nInvoice date: ".$d['issueDate']."\nDue: ".$d['dueDate']."\n\n".$message."\n\n".implode("\n",$text)."\nSubtotal: ".$money($m['totals']['subtotalCents'])."\nTax: ".$money($m['totals']['taxCents'])."\nTotal: ".$money($m['totals']['totalCents'])."\nPaid / credited: ".$money($m['totals']['paymentsCreditsCents'])."\nBalance due: ".$money($m['totals']['balanceDueCents'])."\n\n".($d['message']??'')."\n".($m['terms']??'');
    $identity=implode(' · ',array_filter([$m['issuer']['address']??null,$m['issuer']['email']??null,$m['issuer']['taxNumber']??null]));
    $plain.="\nIssuer: ".$identity."\nCustomer address: ".($m['billTo']['address']??'')."\nPurchase order: ".($d['purchaseOrder']??'')."\n".($m['notes']??'');
    $html='<!doctype html><html><body style="font:15px Arial,sans-serif;color:#173b34;line-height:1.5"><main style="max-width:720px;margin:auto;padding:24px"><h1>'.$e($m['issuer']['businessName']).'</h1><p>'.nl2br($e($identity)).'</p><h2>Invoice '.$e($d['number']).'</h2><p>Bill to: '.$e($m['billTo']['name']).'<br>'.nl2br($e($m['billTo']['address']??'')).'<br>Purchase order: '.$e($d['purchaseOrder']??'—').'<br>Invoice date: '.$e($d['issueDate']).'<br>Due date: '.$e($d['dueDate']).'</p><p>'.nl2br($e($message)).'</p><table style="width:100%;border-collapse:collapse"><thead><tr><th align="left">Description</th><th align="right">Quantity</th><th align="right">Rate</th><th align="right">Total incl. tax</th></tr></thead><tbody>'.$lines.'</tbody></table><p style="text-align:right">Subtotal: '.$e($money($m['totals']['subtotalCents'])).'<br>Tax: '.$e($money($m['totals']['taxCents'])).'<br><strong>Total: '.$e($money($m['totals']['totalCents'])).'</strong><br>Paid / credited: '.$e($money($m['totals']['paymentsCreditsCents'])).'<br><strong>Balance due: '.$e($money($m['totals']['balanceDueCents'])).'</strong></p><p>'.nl2br($e($d['message']??'')).'</p><p>'.nl2br($e($m['terms']??'')).'</p><p>'.nl2br($e($m['notes']??'')).'</p></main></body></html>';
    return ['text'=>$plain,'html'=>$html];
}
function tegh_invoice_send_preview_r20(array $user,array $company,array $input): array
{
    require_company_permission($company,'invoices.write');$f=tegh_invoice_send_fields_r20($input);$pdo=db();$pdo->beginTransaction();
    try{
        $i=tegh_invoice_row_r20($company['id'],(string)($input['invoiceId']??''),true);$lines=tegh_invoice_lines_r20($i['id']);tegh_invoice_assert_revision_r20($i,$lines,$input);
        if(!in_array($i['status'],['sent','paid'],true))fail('Issue a draft through the existing invoice workflow before sending. A void invoice cannot be sent.',409,'invoice_not_issued');
        $model=tegh_output_invoice_model($user,$company,$i['id']);$files=[];$size=0;
        foreach($f['attachmentIds'] as $id){$a=tegh_invoice_attachment_file_r20($company,$i['id'],$id);$size+=(int)$a['size_bytes'];$files[]=['id'=>$a['id'],'name'=>$a['original_name'],'sizeBytes'=>(int)$a['size_bytes'],'sha256'=>$a['sha256']];}
        if($size>2097152)fail('Selected email attachments exceed 2 MB. Send fewer files.',422,'invoice_email_attachment_limit');
        $body=tegh_invoice_email_body_r20($model,$f['message']);
        $previewHash=tegh_invoice_hash_r20(['revision'=>$input['expectedRevision'],'fields'=>$f,'files'=>$files,'text'=>$body['text'],'html'=>$body['html']]);
        $pdo->commit();return ['ok'=>true,'fields'=>$f,'files'=>$files,'html'=>$body['html'],'text'=>$body['text'],'previewHash'=>$previewHash,'deliveryFormat'=>'Complete invoice in the email body; selected supporting attachments only.','invoiceNumber'=>$i['number']];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function tegh_invoice_send_r20(array $user,array $company,array $input): array
{
    require_company_permission($company,'invoices.write');tegh_invoice_operation_key_r20($input);
    if(($input['confirmed']??false)!==true)fail('Preview the invoice and explicitly confirm Send.',422,'invoice_send_confirmation_required');
    // Serialize this workflow through an advisory lock; pending receipt protects existing issue/void too.
    $lock='tegh.inv.'.substr(hash('sha256',$company['id'].':'.($input['invoiceId']??'')),0,48);$q=db()->prepare('SELECT GET_LOCK(?,2)');$q->execute([$lock]);
    if((int)$q->fetchColumn()!==1)fail('Another operation is using this invoice. Check its status before retrying.',409,'invoice_busy');
    $pdo=db();$op=null;$transport=null;
    try{
        $pdo->beginTransaction();$i=tegh_invoice_row_r20($company['id'],(string)($input['invoiceId']??''),true);
        if($old=tegh_invoice_operation_replay_r20($i,$user,'send',$input)){$pdo->commit();return $old;}
        tegh_invoice_assert_not_sending_r20($company['id'],$i['id']);$pdo->commit();
        $preview=tegh_invoice_send_preview_r20($user,$company,$input);
        if(!hash_equals($preview['previewHash'],(string)($input['previewHash']??'')))fail('The invoice preview or attachments changed. Review a new preview before sending.',409,'invoice_email_preview_stale');
        $pdo->beginTransaction();$i=tegh_invoice_row_r20($company['id'],$i['id'],true);tegh_invoice_assert_revision_r20($i,tegh_invoice_lines_r20($i['id']),$input);tegh_invoice_assert_not_sending_r20($company['id'],$i['id']);
        $attachments=[];
        foreach($preview['files'] as $f){$a=tegh_invoice_attachment_file_r20($company,$i['id'],$f['id']);if(!hash_equals($f['sha256'],$a['sha256']))fail('An attachment changed; preview again.',409,'invoice_email_preview_stale');$bytes=file_get_contents($a['absolutePath']);if($bytes===false)throw new RuntimeException('Attachment cannot be read.');$attachments[]=['data'=>$bytes,'filename'=>$a['original_name'],'mime'=>$a['mime']];}
        $op=tegh_invoice_operation_create_r20($i,$user,'send',$input);
        // Durable intent before any network activity. Failure after this point must never be blindly retried.
        $pdo->prepare('UPDATE invoice_document_operations SET result_json=? WHERE id=?')->execute([json_encode(['recipient'=>$preview['fields']['recipient'],'message'=>'Submission started. Check delivery status before retrying.'],JSON_THROW_ON_ERROR),$op]);
        audit_event($user,$company['id'],'invoice.email_requested','invoice',$i['id'],['operationId'=>$op,'recipient'=>$preview['fields']['recipient'],'previewHash'=>$preview['previewHash'],'attachmentIds'=>$preview['fields']['attachmentIds'],'revision'=>$input['expectedRevision'],'journalsCreated'=>0]);$pdo->commit();
        @ignore_user_abort(true);
        $transport=sr_mail_send($company['id'],$user['id'],$preview['fields']['recipient'],'invoice_r20',$preview['fields']['subject'],$preview['text'],$preview['html'],$attachments,['operationKey'=>hash('sha256',$op),'reservedPreflight'=>true]);
        $state=!empty($transport['sent'])?'completed':(in_array($transport['status']??'',['manual_review','pending'],true)?'manual_review':'failed');
        $result=['recipient'=>$preview['fields']['recipient'],'transportStatus'=>$transport['status']??'manual_review','acceptanceCertainty'=>$transport['acceptanceCertainty']??'unknown','acceptedAt'=>$transport['acceptedAt']??null,'message'=>!empty($transport['sent'])?'Accepted by the outgoing mail service. Inbox delivery is not confirmed.':($state==='manual_review'?'Submission outcome is uncertain. Review the mail log; no automatic resend.':($transport['message']??'Mail was not accepted. Review configuration before a new attempt.')),'journalsCreated'=>0];
        return tegh_invoice_operation_complete_r20($op,$result,$state,$transport['id']??null);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($op!==null){try{tegh_invoice_operation_complete_r20($op,['message'=>'Submission outcome requires review. Do not resend automatically.','transportStatus'=>'manual_review','acceptanceCertainty'=>$transport['acceptanceCertainty']??'unknown'],'manual_review',$transport['id']??null);}catch(Throwable){} }
        throw $e;
    }finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
}
function tegh_invoice_recheck_r20(array $user,array $company,array $input): array
{
    require_company_permission($company,'invoices.write');$i=tegh_invoice_row_r20($company['id'],(string)($input['invoiceId']??''));
    $q=db()->prepare("SELECT *,TIMESTAMPDIFF(SECOND,created_at,CURRENT_TIMESTAMP) AS age_seconds FROM invoice_document_operations WHERE company_id=? AND invoice_id=? AND id=? AND operation_type='send'");$q->execute([$company['id'],$i['id'],(string)($input['operationId']??'')]);$op=$q->fetch();if(!$op)fail('Delivery record not found.',404,'invoice_delivery_unavailable');
    if($op['status']!=='pending')return ['ok'=>true,'status'=>$op['status'],'message'=>'Recorded status is unchanged; no message was resent.'];
    // Wait out a still-running SMTP request. Recovery is observational, never transport replay.
    if((int)$op['age_seconds']<600)return ['ok'=>true,'status'=>'pending','message'=>'Submission may still be running. Wait and check again; no message was resent.'];
    $q=db()->prepare('SELECT oe.* FROM outbound_email_attempts a JOIN outbound_emails oe ON oe.id=a.outbound_email_id WHERE a.company_id=? AND a.operation_key=? ORDER BY a.started_at DESC LIMIT 1');$q->execute([$company['id'],hash('sha256',$op['id'])]);$email=$q->fetch();
    $accepted=$email&&in_array($email['status'],['sent','sent_warning'],true);
    $result=tegh_invoice_operation_complete_r20($op['id'],['message'=>$accepted?'Outgoing service acceptance recorded; inbox delivery is not confirmed.':'Prior submission requires manual review. No message was resent.','transportStatus'=>$email['status']??'manual_review','acceptanceCertainty'=>$accepted?'accepted':'unknown'],$accepted?'completed':'manual_review',$email['id']??null);
    audit_event($user,$company['id'],'invoice.email_status_reviewed','invoice',$i['id'],['operationId'=>$op['id'],'status'=>$result['status'],'resent'=>false]);return $result;
}
function handle_invoice_documents_r20(string $action): never
{
    $reads=['detail','attachment-download'];require_method(...(in_array($action,$reads,true)?['GET']:['POST']));
    if(!in_array($action,$reads,true))require_csrf();$user=require_user();$company=require_company($user);require_company_permission($company,'invoices.view');tegh_schema46_require();
    $input=in_array($action,$reads,true)?$_GET:($action==='attachment-upload'?$_POST:request_json());
    if($action==='attachment-download'){$a=tegh_invoice_attachment_file_r20($company,(string)($input['invoiceId']??''),(string)($input['attachmentId']??''));header('X-Content-Type-Options: nosniff');header('Content-Security-Policy: sandbox');stream_private_file($a['storage_path'],$a['original_name']);}
    $result=match($action){
        'detail'=>tegh_invoice_detail_r20($user,$company,(string)($input['invoiceId']??'')),
        'edit'=>tegh_invoice_edit_r20($user,$company,$input),
        'send-preview'=>tegh_invoice_send_preview_r20($user,$company,$input),
        'send'=>tegh_invoice_send_r20($user,$company,$input),
        'delivery-check'=>tegh_invoice_recheck_r20($user,$company,$input),
        'attachment-upload'=>tegh_invoice_upload_r20($user,$company,$input,$_FILES['file']??[]),
        'attachment-remove'=>tegh_invoice_remove_attachment_r20($user,$company,$input),
        default=>fail('Invoice action not found.',404,'route_not_found')
    };json_response($result);
}
