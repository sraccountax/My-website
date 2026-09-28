<?php
declare(strict_types=1);

/** Tegh public portal, support, catalogue and reporting services. */

function portal_owner_id(): ?string
{
    if (!schema_column_exists('users','platform_role')) return null;
    $stmt=db()->query("SELECT id FROM users WHERE platform_role='platform_owner' AND active=1 ORDER BY created_at,id LIMIT 1");
    $id=$stmt->fetchColumn();
    return $id===false?null:(string)$id;
}

function portal_smtp_read($socket): string
{
    $out='';
    while (($line=fgets($socket,1024))!==false) {
        $out.=$line;
        if (strlen($line)<4 || $line[3]!=='-') break;
    }
    return $out;
}
final class PortalSmtpException extends RuntimeException
{
    public string $smtpStage;
    public int $replyCode;
    public ?string $enhancedCode;
    public bool $submitted;
    public string $diagnostic;
    public function __construct(string $stage,int $replyCode,?string $enhancedCode,bool $submitted,string $diagnostic)
    {
        parent::__construct('SMTP did not return an accepted response.');
        $this->smtpStage=$stage;$this->replyCode=$replyCode;$this->enhancedCode=$enhancedCode;
        $this->submitted=$submitted;$this->diagnostic=$diagnostic;
    }
}
function portal_mail_sanitize_diagnostic(string $value): string
{
    $value=preg_replace('/[\r\n\t]+/',' ',trim($value))??'';
    $value=preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/','[email]',$value)??$value;
    $value=preg_replace('/\b(?:AUTH|LOGIN|PLAIN)\s+[A-Za-z0-9+\/=._-]+/i','[auth redacted]',$value)??$value;
    return mb_substr($value,0,500);
}
function portal_smtp_expect($socket,array $codes,string $stage='greeting',bool $submitted=false): string
{
    $response=portal_smtp_read($socket);
    $code=(int)substr($response,0,3);
    if(!in_array($code,$codes,true)){
        preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/',$response,$enhanced);
        throw new PortalSmtpException($stage,$code,$enhanced[1]??null,$submitted,portal_mail_sanitize_diagnostic($response));
    }
    return $response;
}
function portal_smtp_command($socket,string $command,array $codes,string $stage='greeting',bool $submitted=false): string
{
    if(@fwrite($socket,$command."\r\n")===false)throw new PortalSmtpException($stage,0,null,$submitted,'The SMTP connection closed before the command completed.');
    return portal_smtp_expect($socket,$codes,$stage,$submitted);
}
function portal_mail_reserved_address(string $recipient): bool
{
    $domain=strtolower((string)substr(strrchr($recipient,'@')?:'@',1));
    return in_array($domain,['example.com','example.net','example.org'],true)
        || (bool)preg_match('/(?:^|\.)(?:test|example|invalid|localhost)$/',$domain);
}
function portal_mail_plain_message(string $stage,int $code,string $outcome): string
{
    if($stage==='authentication'&&$code===535)return 'SMTP authentication failed. A Platform Owner must verify the configured mailbox credentials.';
    if($stage==='mail_from'&&$code===550)return 'The mail server rejected the configured sender. Verify the From address and SMTP account in Email Delivery Health.';
    if($stage==='rcpt_to'&&$code===550)return 'The recipient address was rejected. Verify the customer’s email address.';
    if($outcome==='ambiguous_after_submission')return 'Tegh submitted the message but did not receive a final delivery decision. Automatic retry is disabled to prevent duplicate email.';
    if($outcome==='diagnostic_failure_after_acceptance')return 'The mail server accepted the message, but the closing SMTP diagnostic failed. The message remains Sent and must not be retried.';
    if($outcome==='temporary_preaccept_failure')return 'The mail server temporarily deferred the message before accepting it. Correct or review the issue, then authorize a new attempt.';
    if($stage==='connection')return 'Tegh could not connect to the configured mail server. Review Email Delivery Health before authorizing another attempt.';
    return $code>0?'The mail server rejected the message before accepting it at the '.$stage.' stage.':'Email delivery failed before the mail server confirmed acceptance.';
}
function portal_header_value(string $value): string
{
    return str_replace(["\r","\n"],' ',trim($value));
}
function portal_mime_header(string $value): string
{
    $value=portal_header_value($value);
    return preg_match('/[^\x20-\x7E]/',$value) ? '=?UTF-8?B?'.base64_encode($value).'?=' : $value;
}
function portal_base64_body(string $value): string
{
    return rtrim(chunk_split(base64_encode(str_replace(["\r\n","\r"],"\n",$value)),76,"\r\n"));
}

function sr_mail_send_legacy_v5210(?string $companyId,?string $createdBy,string $recipient,string $templateKey,string $subject,string $textBody,string $htmlBody='',array $attachments=[]): array
{
    $recipient=safe_email($recipient);
    $subject=portal_header_value(mb_substr($subject,0,240));
    $id=new_id('mail');
    if(schema_table_exists('outbound_emails')){
        db()->prepare("INSERT INTO outbound_emails (id,company_id,recipient,template_key,subject,status,created_by) VALUES (?,?,?,?,?,'pending',?)")
            ->execute([$id,$companyId,$recipient,$templateKey,$subject,$createdBy]);
    }
    $fromRaw=trim((string)(config('mail.from_email')??''));
    if($fromRaw===''){
        $message='Email delivery is not configured. Add a sender address and SMTP details in the server configuration.';
        if(schema_table_exists('outbound_emails')) db()->prepare("UPDATE outbound_emails SET status='failed',attempt_count=attempt_count+1,provider_message=? WHERE id=?")->execute([$message,$id]);
        record_system_incident('An outbound email could not be sent.',502,'outbound_email_failed',null,['source'=>'external_service_error','route'=>'mail/send','internalMessage'=>$message,'companyId'=>$companyId,'mailId'=>$id,'templateKey'=>$templateKey]);
        return ['id'=>$id,'sent'=>false,'status'=>'failed','message'=>$message];
    }
    try{$from=safe_email($fromRaw);}catch(Throwable){
        $message='The configured sender email address is invalid.';
        if(schema_table_exists('outbound_emails')) db()->prepare("UPDATE outbound_emails SET status='failed',attempt_count=attempt_count+1,provider_message=? WHERE id=?")->execute([$message,$id]);
        record_system_incident('An outbound email could not be sent.',502,'outbound_email_failed',null,['source'=>'external_service_error','route'=>'mail/send','internalMessage'=>$message,'companyId'=>$companyId,'mailId'=>$id,'templateKey'=>$templateKey]);
        return ['id'=>$id,'sent'=>false,'status'=>'failed','message'=>$message];
    }
    $fromName=portal_mime_header((string)(config('mail.from_name')??'Tegh'));
    $replyRaw=trim((string)(config('mail.reply_to')??$from));
    try{$replyTo=safe_email($replyRaw);}catch(Throwable){$replyTo=$from;}
    $senderDomain=strtolower((string)substr(strrchr($from,'@')?:'@tegh.local',1));
    $messageId='<'.bin2hex(random_bytes(12)).'@'.$senderDomain.'>';
    $boundary='tegh_alt_'.bin2hex(random_bytes(12));
    $headers=[
        'Date: '.date(DATE_RFC2822),'From: '.$fromName.' <'.$from.'>','To: <'.$recipient.'>',
        'Reply-To: <'.$replyTo.'>','Subject: '.portal_mime_header($subject),'Message-ID: '.$messageId,'MIME-Version: 1.0',
        'Auto-Submitted: auto-generated','X-Auto-Response-Suppress: All','X-Tegh-Message: '.$id,
    ];
    $normalizedAttachments=[];$attachmentBytes=0;
    foreach($attachments as $attachment){
        if(!is_array($attachment))continue;
        $data=(string)($attachment['data']??'');if($data==='')continue;
        $attachmentBytes+=strlen($data);
        if($attachmentBytes>2097152)throw new RuntimeException('Email attachments exceed the 2 MB delivery limit.');
        $filename=preg_replace('/[^A-Za-z0-9._-]/','_',basename((string)($attachment['filename']??'attachment.bin')))?:'attachment.bin';
        $mime=strtolower(trim((string)($attachment['mime']??'application/octet-stream')));
        if(!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#',$mime))$mime='application/octet-stream';
        $normalizedAttachments[]=['filename'=>substr($filename,0,120),'mime'=>$mime,'data'=>$data];
    }
    if($htmlBody!==''){
        $alternative="--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".portal_base64_body($textBody)."\r\n--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".portal_base64_body($htmlBody)."\r\n--$boundary--\r\n";
    }else{
        $alternative=portal_base64_body($textBody);
    }
    if($normalizedAttachments){
        $mixed='tegh_mix_'.bin2hex(random_bytes(12));
        $headers[]='Content-Type: multipart/mixed; boundary="'.$mixed.'"';
        if($htmlBody!==''){
            $body="--$mixed\r\nContent-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n".$alternative;
        }else{
            $body="--$mixed\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".$alternative."\r\n";
        }
        foreach($normalizedAttachments as $attachment){
            $body.="--$mixed\r\nContent-Type: {$attachment['mime']}; name=\"{$attachment['filename']}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$attachment['filename']}\"\r\n\r\n".rtrim(chunk_split(base64_encode($attachment['data']),76,"\r\n"))."\r\n";
        }
        $body.="--$mixed--\r\n";
    }elseif($htmlBody!==''){
        $headers[]='Content-Type: multipart/alternative; boundary="'.$boundary.'"';
        $body=$alternative;
    }else{
        $headers[]='Content-Type: text/plain; charset=UTF-8';
        $headers[]='Content-Transfer-Encoding: base64';
        $body=portal_base64_body($textBody);
    }
    $ok=false;$message='';
    try{
        $host=trim((string)(config('mail.smtp_host')??''));
        $username=(string)(config('mail.smtp_username')??'');
        $password=(string)(config('mail.smtp_password')??'');
        $port=(int)(config('mail.smtp_port')??587);
        $encryption=strtolower((string)(config('mail.smtp_encryption')??'tls'));
        if($host!==''){
            $transport=$encryption==='ssl'?'ssl://':'';
            $socket=@stream_socket_client($transport.$host.':'.$port,$errno,$errstr,12,STREAM_CLIENT_CONNECT);
            if(!is_resource($socket)) throw new RuntimeException('Mail server connection failed.');
            stream_set_timeout($socket,15);portal_smtp_expect($socket,[220]);
            $helo=parse_url((string)config('app.base_url'),PHP_URL_HOST)?:'localhost';
            portal_smtp_command($socket,'EHLO '.$helo,[250]);
            if($encryption==='tls'){
                portal_smtp_command($socket,'STARTTLS',[220]);
                if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Mail encryption could not start.');
                portal_smtp_command($socket,'EHLO '.$helo,[250]);
            }
            if($username!==''){
                portal_smtp_command($socket,'AUTH LOGIN',[334]);
                portal_smtp_command($socket,base64_encode($username),[334]);
                portal_smtp_command($socket,base64_encode($password),[235]);
            }
            portal_smtp_command($socket,'MAIL FROM:<'.$from.'>',[250]);
            portal_smtp_command($socket,'RCPT TO:<'.$recipient.'>',[250,251]);
            portal_smtp_command($socket,'DATA',[354]);
            $payload=implode("\r\n",$headers)."\r\n\r\n".$body;
            $payload=preg_replace('/(?m)^\./','..',$payload)??$payload;
            fwrite($socket,$payload."\r\n.\r\n");portal_smtp_expect($socket,[250]);
            @portal_smtp_command($socket,'QUIT',[221]);fclose($socket);$ok=true;$message='Sent through configured SMTP.';
        }elseif((bool)(config('mail.sendmail_fallback')??true)){
            $ok=@mail($recipient,$subject,$body,implode("\r\n",array_filter($headers,static fn(string $h):bool=>!str_starts_with($h,'Subject:')&&!str_starts_with($h,'To:'))));
            $message=$ok?'Sent through the server mail service.':'The server mail service did not accept the message.';
        }else throw new RuntimeException('Email delivery is not configured.');
    }catch(Throwable $e){$message=mb_substr($e->getMessage(),0,500);$ok=false;}
    if(schema_table_exists('outbound_emails')) db()->prepare("UPDATE outbound_emails SET status=?,attempt_count=attempt_count+1,provider_message=?,sent_at=? WHERE id=?")
        ->execute([$ok?'sent':'failed',$message,$ok?gmdate('Y-m-d H:i:s'):null,$id]);
    if(!$ok)record_system_incident('An outbound email could not be sent.',502,'outbound_email_failed',null,['source'=>'external_service_error','route'=>'mail/send','internalMessage'=>$message,'companyId'=>$companyId,'mailId'=>$id,'templateKey'=>$templateKey]);
    return ['id'=>$id,'sent'=>$ok,'status'=>$ok?'sent':'failed','message'=>$message];
}

/**
 * Structured Build 5220 mail boundary. A final DATA acceptance is the only
 * SMTP success authority. Ambiguous post-submission outcomes are never retried
 * automatically, and a QUIT diagnostic cannot turn an accepted message into a
 * failure.
 */
function sr_mail_send(?string $companyId,?string $createdBy,string $recipient,string $templateKey,string $subject,string $textBody,string $htmlBody='',array $attachments=[],array $context=[]): array
{
    $recipient=safe_email($recipient);$subject=portal_header_value(mb_substr($subject,0,240));
    if(!empty($context['reservedPreflight'])&&portal_mail_reserved_address($recipient))return [
        'id'=>null,'attemptId'=>null,'attemptCreated'=>false,'sent'=>false,'status'=>'blocked',
        'outcome'=>'definitive_preaccept_failure','acceptanceCertainty'=>'no','stage'=>'preflight','smtpCode'=>null,'enhancedCode'=>null,
        'message'=>'This is a sample or reserved email address and cannot receive real mail. Replace it with a real address before sending.',
    ];

    $id=new_id('mail');
    if(schema_table_exists('outbound_emails'))db()->prepare("INSERT INTO outbound_emails (id,company_id,recipient,template_key,subject,status,created_by) VALUES (?,?,?,?,?,'pending',?)")
        ->execute([$id,$companyId,$recipient,$templateKey,$subject,$createdBy]);

    $fromRaw=trim((string)(config('mail.from_email')??''));$from='';$configurationError='';
    if($fromRaw==='')$configurationError='Email delivery is not configured. Add a sender address and SMTP details in the private server configuration.';
    else try{$from=safe_email($fromRaw);}catch(Throwable){$configurationError='The configured sender email address is invalid.';}
    $fromName=portal_mime_header((string)(config('mail.from_name')??'Tegh'));
    $replyRaw=trim((string)(config('mail.reply_to')??$from));try{$replyTo=safe_email($replyRaw);}catch(Throwable){$replyTo=$from;}
    $senderDomain=strtolower((string)substr(strrchr($from,'@')?:'@tegh.local',1));
    $messageId='<'.bin2hex(random_bytes(12)).'@'.$senderDomain.'>';$boundary='tegh_alt_'.bin2hex(random_bytes(12));
    $headers=['Date: '.date(DATE_RFC2822),'From: '.$fromName.' <'.$from.'>','To: <'.$recipient.'>','Reply-To: <'.$replyTo.'>',
      'Subject: '.portal_mime_header($subject),'Message-ID: '.$messageId,'MIME-Version: 1.0','Auto-Submitted: auto-generated',
      'X-Auto-Response-Suppress: All','X-Tegh-Message: '.$id];
    $normalizedAttachments=[];$attachmentBytes=0;
    foreach($attachments as $attachment){
        if(!is_array($attachment))continue;$data=(string)($attachment['data']??'');if($data==='')continue;$attachmentBytes+=strlen($data);
        if($attachmentBytes>2097152)$configurationError='Email attachments exceed the 2 MB delivery limit.';
        $filename=preg_replace('/[^A-Za-z0-9._-]/','_',basename((string)($attachment['filename']??'attachment.bin')))?:'attachment.bin';
        $mime=strtolower(trim((string)($attachment['mime']??'application/octet-stream')));if(!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#',$mime))$mime='application/octet-stream';
        $normalizedAttachments[]=['filename'=>substr($filename,0,120),'mime'=>$mime,'data'=>$data];
    }
    $alternative=$htmlBody!==''
      ?"--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".portal_base64_body($textBody)."\r\n--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".portal_base64_body($htmlBody)."\r\n--$boundary--\r\n"
      :portal_base64_body($textBody);
    if($normalizedAttachments){
        $mixed='tegh_mix_'.bin2hex(random_bytes(12));$headers[]='Content-Type: multipart/mixed; boundary="'.$mixed.'"';
        $body=$htmlBody!==''?"--$mixed\r\nContent-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n".$alternative:"--$mixed\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".$alternative."\r\n";
        foreach($normalizedAttachments as $attachment)$body.="--$mixed\r\nContent-Type: {$attachment['mime']}; name=\"{$attachment['filename']}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$attachment['filename']}\"\r\n\r\n".rtrim(chunk_split(base64_encode($attachment['data']),76,"\r\n"))."\r\n";
        $body.="--$mixed--\r\n";
    }elseif($htmlBody!==''){$headers[]='Content-Type: multipart/alternative; boundary="'.$boundary.'"';$body=$alternative;}
    else{$headers[]='Content-Type: text/plain; charset=UTF-8';$headers[]='Content-Transfer-Encoding: base64';$body=portal_base64_body($textBody);}

    $attemptId=new_id('mailattempt');$attemptNumber=1;$operationKey=(string)($context['operationKey']??'');
    if(!preg_match('/^[a-f0-9]{64}$/',$operationKey))$operationKey=hash('sha256',$id.'|'.bin2hex(random_bytes(24)));
    $collectionDraftId=isset($context['collectionDraftId'])?mb_substr((string)$context['collectionDraftId'],0,64):null;
    $attemptReady=schema_table_exists('outbound_email_attempts');
    if($attemptReady){
        $stmt=db()->prepare('SELECT COALESCE(MAX(attempt_number),0)+1 FROM outbound_email_attempts WHERE outbound_email_id=?');$stmt->execute([$id]);$attemptNumber=max(1,(int)$stmt->fetchColumn());
        db()->prepare("INSERT INTO outbound_email_attempts (id,outbound_email_id,collection_draft_id,company_id,initiated_by,attempt_number,operation_key,stage,started_at) VALUES (?,?,?,?,?,?,?,'connection',UTC_TIMESTAMP())")
          ->execute([$attemptId,$id,$collectionDraftId,$companyId,$createdBy,$attemptNumber,$operationKey]);
    }else $attemptId=null;
    $setStage=static function(string $stage)use($attemptReady,$attemptId):void{if($attemptReady&&$attemptId)db()->prepare('UPDATE outbound_email_attempts SET stage=? WHERE id=? AND outcome IS NULL')->execute([$stage,$attemptId]);};

    $stage='connection';$code=0;$enhanced=null;$diagnostic='';$outcome='definitive_preaccept_failure';$certainty='no';$acceptedAt=null;$socket=null;
    try{
        if($configurationError!=='')throw new RuntimeException($configurationError);
        $host=trim((string)(config('mail.smtp_host')??''));$username=(string)(config('mail.smtp_username')??'');$password=(string)(config('mail.smtp_password')??'');
        $port=(int)(config('mail.smtp_port')??587);$encryption=strtolower((string)(config('mail.smtp_encryption')??'tls'));
        if($host!==''){
            $setStage($stage='connection');$transport=$encryption==='ssl'?'ssl://':'';
            $socket=@stream_socket_client($transport.$host.':'.$port,$errno,$errstr,12,STREAM_CLIENT_CONNECT);
            if(!is_resource($socket))throw new PortalSmtpException('connection',0,null,false,'The SMTP connection could not be established.');
            stream_set_timeout($socket,15);$setStage($stage='greeting');portal_smtp_expect($socket,[220],$stage);
            $helo=parse_url((string)config('app.base_url'),PHP_URL_HOST)?:'localhost';$setStage($stage='ehlo');portal_smtp_command($socket,'EHLO '.$helo,[250],$stage);
            if($encryption==='tls'){$setStage($stage='starttls');portal_smtp_command($socket,'STARTTLS',[220],$stage);if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new PortalSmtpException($stage,0,null,false,'TLS negotiation failed.');$setStage($stage='ehlo');portal_smtp_command($socket,'EHLO '.$helo,[250],$stage);}
            if($username!==''){$setStage($stage='authentication');portal_smtp_command($socket,'AUTH LOGIN',[334],$stage);portal_smtp_command($socket,base64_encode($username),[334],$stage);portal_smtp_command($socket,base64_encode($password),[235],$stage);}
            $setStage($stage='mail_from');portal_smtp_command($socket,'MAIL FROM:<'.$from.'>',[250],$stage);
            $setStage($stage='rcpt_to');portal_smtp_command($socket,'RCPT TO:<'.$recipient.'>',[250,251],$stage);
            $setStage($stage='data_initialization');portal_smtp_command($socket,'DATA',[354],$stage);
            $payload=implode("\r\n",$headers)."\r\n\r\n".$body;$payload=preg_replace('/(?m)^\./','..',$payload)??$payload;
            $setStage($stage='final_data_acceptance');if(@fwrite($socket,$payload."\r\n.\r\n")===false)throw new PortalSmtpException($stage,0,null,true,'The SMTP connection closed during submission.');
            $response=portal_smtp_expect($socket,[250],$stage,true);$code=250;preg_match('/\b(2\.\d{1,3}\.\d{1,3})\b/',$response,$match);$enhanced=$match[1]??null;
            $acceptedAt=gmdate('Y-m-d H:i:s');$outcome='accepted';$certainty='yes';$diagnostic='The configured SMTP server accepted the message after DATA.';
            try{portal_smtp_command($socket,'QUIT',[221],'quit',true);}catch(PortalSmtpException $quit){$stage='quit';$code=$quit->replyCode;$enhanced=$quit->enhancedCode;$diagnostic=$quit->diagnostic;$outcome='diagnostic_failure_after_acceptance';}
        }elseif((bool)(config('mail.sendmail_fallback')??true)){
            $setStage($stage='sendmail');$accepted=@mail($recipient,$subject,$body,implode("\r\n",array_filter($headers,static fn(string $h):bool=>!str_starts_with($h,'Subject:')&&!str_starts_with($h,'To:'))));
            if(!$accepted)throw new PortalSmtpException($stage,0,null,false,'The server mail service did not accept the message.');
            $acceptedAt=gmdate('Y-m-d H:i:s');$outcome='accepted';$certainty='yes';$diagnostic='The server mail service accepted the message.';
        }else throw new RuntimeException('Email delivery is not configured.');
    }catch(PortalSmtpException $error){
        $stage=$error->smtpStage;$code=$error->replyCode;$enhanced=$error->enhancedCode;$diagnostic=$error->diagnostic;
        if($acceptedAt!==null){$outcome='diagnostic_failure_after_acceptance';$certainty='yes';}
        elseif($error->submitted&&$code===0){$outcome='ambiguous_after_submission';$certainty='unknown';}
        elseif($code>=400&&$code<500){$outcome='temporary_preaccept_failure';$certainty='no';}
        else{$outcome='definitive_preaccept_failure';$certainty='no';}
    }catch(Throwable $error){
        $diagnostic=portal_mail_sanitize_diagnostic($error->getMessage());
        if($acceptedAt!==null){$outcome='diagnostic_failure_after_acceptance';$certainty='yes';}
        else{$outcome='definitive_preaccept_failure';$certainty='no';}
    }finally{if(is_resource($socket))@fclose($socket);}

    $sent=in_array($outcome,['accepted','diagnostic_failure_after_acceptance'],true);$message=portal_mail_plain_message($stage,$code,$outcome);
    $status=match($outcome){'accepted'=>'sent','diagnostic_failure_after_acceptance'=>'sent_warning','temporary_preaccept_failure'=>'deferred','ambiguous_after_submission'=>'manual_review',default=>'failed'};
    if($attemptReady&&$attemptId)db()->prepare('UPDATE outbound_email_attempts SET stage=?,smtp_reply_code=?,enhanced_code=?,provider_diagnostic=?,outcome=?,acceptance_certainty=?,accepted_at=?,completed_at=UTC_TIMESTAMP() WHERE id=? AND outcome IS NULL')
      ->execute([$stage,$code?:null,$enhanced,portal_mail_sanitize_diagnostic($diagnostic),$outcome,$certainty,$acceptedAt,$attemptId]);
    if(schema_table_exists('outbound_emails'))db()->prepare('UPDATE outbound_emails SET status=?,attempt_count=attempt_count+1,provider_message=?,sent_at=? WHERE id=?')
      ->execute([$status,$message,$sent?$acceptedAt:null,$id]);
    if(!$sent)record_system_incident('An outbound email could not be confirmed as sent.',502,'outbound_email_failed',null,['source'=>'external_service_error','route'=>'mail/send','internalMessage'=>$message,'companyId'=>$companyId,'mailId'=>$id,'templateKey'=>$templateKey,'stage'=>$stage,'outcome'=>$outcome,'acceptanceCertainty'=>$certainty]);
    return ['id'=>$id,'attemptId'=>$attemptId,'attemptCreated'=>true,'attemptNumber'=>$attemptNumber,'operationKey'=>$operationKey,
      'sent'=>$sent,'status'=>$status,'outcome'=>$outcome,'acceptanceCertainty'=>$certainty,'stage'=>$stage,'smtpCode'=>$code?:null,
      'enhancedCode'=>$enhanced,'acceptedAt'=>$acceptedAt,'diagnosticWarning'=>$outcome==='diagnostic_failure_after_acceptance','message'=>$message];
}


function create_product_service_record(array $user,array $company,array $input,string $source='manual'): array
{
    require_company_permission($company,'invoices.write');
    $companyId=(string)$company['id'];
    $kind=(string)($input['kind']??'service');if(!in_array($kind,['product','service'],true))fail('Choose product or service.');
    $code=optional_text($input['code']??null,60);$name=clean_text($input['name']??'','Name',180);$description=optional_text($input['description']??null,500);
    $unit=(int)($input['unitPriceCents']??0);if($unit<0||$unit>99999999999)fail('Unit price is invalid.');$taxable=!empty($input['taxable']);$active=array_key_exists('active',$input)?!empty($input['active']):true;
    $accountId=trim((string)($input['incomeAccountId']??''));if($accountId==='')$accountId=null;
    if($accountId!==null){$q=db()->prepare("SELECT 1 FROM accounts WHERE id=? AND company_id=? AND account_type='income' AND active=1 AND is_control=0");$q->execute([$accountId,$companyId]);if(!$q->fetchColumn())fail('Choose an active non-control income GL account.');}
    if($code!==null){$dup=db()->prepare('SELECT 1 FROM products_services WHERE company_id=? AND code=? LIMIT 1');$dup->execute([$companyId,$code]);if($dup->fetchColumn())fail('That item code already exists.',409,'duplicate_product_code');}
    $dup=db()->prepare('SELECT 1 FROM products_services WHERE company_id=? AND LOWER(name)=LOWER(?) LIMIT 1');$dup->execute([$companyId,$name]);if($dup->fetchColumn())fail('That product or service name already exists.',409,'duplicate_product_name');
    $id=new_id('item');
    db()->prepare('INSERT INTO products_services (id,company_id,kind,code,name,description,unit_price_cents,taxable,income_account_id,active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$companyId,$kind,$code,$name,$description,$unit,$taxable?1:0,$accountId,$active?1:0,$user['id']]);
    audit_event($user,$companyId,'catalogue.item_created','product_service',$id,['name'=>$name,'kind'=>$kind,'initiatedVia'=>$source]);
    return ['id'=>$id,'kind'=>$kind,'code'=>$code,'name'=>$name,'active'=>$active];
}

function portal_products(array $user,array $company): never
{
    $companyId=(string)$company['id'];
    if(request_method()==='GET'){
        $stmt=db()->prepare("SELECT ps.id,ps.kind,ps.code,ps.name,ps.description,ps.unit_price_cents,ps.taxable,ps.income_account_id,ps.active,a.code AS account_code,a.name AS account_name,
            (SELECT COUNT(*) FROM invoice_lines il JOIN invoices i ON i.id=il.invoice_id WHERE il.product_service_id=ps.id AND i.company_id=ps.company_id AND i.status<>'void') AS sales_line_count,
            (SELECT COALESCE(SUM(il.quantity_milli),0) FROM invoice_lines il JOIN invoices i ON i.id=il.invoice_id WHERE il.product_service_id=ps.id AND i.company_id=ps.company_id AND i.status<>'void') AS sales_quantity_milli,
            (SELECT COALESCE(SUM(il.amount_cents),0) FROM invoice_lines il JOIN invoices i ON i.id=il.invoice_id WHERE il.product_service_id=ps.id AND i.company_id=ps.company_id AND i.status<>'void') AS sales_value_cents,
            (SELECT COUNT(*) FROM bills b WHERE b.product_service_id=ps.id AND b.company_id=ps.company_id AND b.status<>'void') AS vendor_invoice_count,
            (SELECT COALESCE(SUM(b.quantity_milli),0) FROM bills b WHERE b.product_service_id=ps.id AND b.company_id=ps.company_id AND b.status<>'void') AS purchase_quantity_milli,
            (SELECT COALESCE(SUM(b.subtotal_cents),0) FROM bills b WHERE b.product_service_id=ps.id AND b.company_id=ps.company_id AND b.status<>'void') AS purchase_value_cents
            FROM products_services ps LEFT JOIN accounts a ON a.id=ps.income_account_id WHERE ps.company_id=? ORDER BY ps.active DESC,ps.kind,ps.name");
        $stmt->execute([$companyId]);
        json_response(['items'=>array_map(static fn(array $r):array=>[
            'id'=>(string)$r['id'],'kind'=>(string)$r['kind'],'code'=>$r['code'],'name'=>(string)$r['name'],'description'=>$r['description'],
            'unitPriceCents'=>(int)$r['unit_price_cents'],'taxable'=>(bool)$r['taxable'],'incomeAccountId'=>$r['income_account_id'],
            'incomeAccount'=>$r['account_code']?((string)$r['account_code'].' · '.(string)$r['account_name']):null,'active'=>(bool)$r['active'],
            'salesLineCount'=>(int)($r['sales_line_count']??0),'salesQuantityMilli'=>(int)($r['sales_quantity_milli']??0),'salesValueCents'=>(int)($r['sales_value_cents']??0),
            'vendorInvoiceCount'=>(int)($r['vendor_invoice_count']??0),'purchaseQuantityMilli'=>(int)($r['purchase_quantity_milli']??0),'purchaseValueCents'=>(int)($r['purchase_value_cents']??0),
        ],$stmt->fetchAll())]);
    }
    require_method('POST','PUT','DELETE');require_csrf();require_company_role($company,'owner','bookkeeper');$input=request_json();
    if(request_method()==='DELETE'||($input['action']??'')==='archive'){
        $id=clean_text($input['id']??'','Product or service',64);db()->prepare('UPDATE products_services SET active=0 WHERE id=? AND company_id=?')->execute([$id,$companyId]);
        audit_event($user,$companyId,'catalogue.item_archived','product_service',$id,[]);json_response(['archived'=>true]);
    }
    $id=trim((string)($input['id']??''));$kind=(string)($input['kind']??'service');if(!in_array($kind,['product','service'],true))fail('Choose product or service.');
    $code=optional_text($input['code']??null,60);$name=clean_text($input['name']??'','Name',180);$description=optional_text($input['description']??null,500);
    $unit=(int)($input['unitPriceCents']??0);if($unit<0||$unit>99999999999)fail('Unit price is invalid.');$taxable=!empty($input['taxable']);
    $accountId=trim((string)($input['incomeAccountId']??''));if($accountId==='')$accountId=null;
    if($accountId!==null){$q=db()->prepare("SELECT 1 FROM accounts WHERE id=? AND company_id=? AND account_type='income' AND active=1");$q->execute([$accountId,$companyId]);if(!$q->fetchColumn())fail('Choose an active income GL account.');}
    if($id!==''){
        db()->prepare('UPDATE products_services SET kind=?,code=?,name=?,description=?,unit_price_cents=?,taxable=?,income_account_id=?,active=1 WHERE id=? AND company_id=?')
            ->execute([$kind,$code,$name,$description,$unit,$taxable?1:0,$accountId,$id,$companyId]);$status=200;$action='catalogue.item_updated';
    }else{
        $item=create_product_service_record($user,$company,$input,'manual');
        json_response(['item'=>$item],201);
    }
    audit_event($user,$companyId,$action,'product_service',$id,['name'=>$name,'kind'=>$kind]);json_response(['item'=>['id'=>$id]],$status);
}

function portal_day_book(array $company): never
{
    require_method('GET');$companyId=(string)$company['id'];
    $start=safe_date($_GET['start']??date('Y-m-01'),'Start date');$end=safe_date($_GET['end']??date('Y-m-d'),'End date');if($start>$end)fail('Start date must not be after end date.');
    $module=strtoupper(trim((string)($_GET['module']??'')));if($module!==''&&!in_array($module,['AR','AP','PL','EX','BS','GL'],true))fail('Day Book module is invalid.');
    $sourceType=strtolower(trim((string)($_GET['sourceType']??'')));if($sourceType!==''&&!preg_match('/^[a-z0-9_]{1,60}$/',$sourceType))fail('Day Book source type is invalid.');
    $sourceId=trim((string)($_GET['sourceId']??''));if($sourceId!==''&&(mb_strlen($sourceId)>64||preg_match('/[\x00-\x1F]/',$sourceId)))fail('Day Book source reference is invalid.');
    $journalId=trim((string)($_GET['journalId']??''));if($journalId!==''&&(mb_strlen($journalId)>64||preg_match('/[^a-zA-Z0-9:_-]/',$journalId)))fail('Day Book journal reference is invalid.');
    $legacyExclude=in_array(strtolower(trim((string)($_GET['excludeVoided']??''))),['1','true','yes'],true);
    $status=strtolower(trim((string)($_GET['status']??($legacyExclude?'posted':'all'))));if($status==='voided')$status='void';
    if(!in_array($status,['all','posted','void','draft'],true))fail('Day Book status is invalid.');
    if(schema_table_exists('vouchers')){
        $sql="SELECT v.id,v.serial_number,v.voucher_number,v.module,v.source_type,v.source_id,v.voucher_date,v.description,v.amount_cents,v.status,v.journal_entry_id,v.created_at,je.memo,COALESCE(je.created_at,v.created_at) AS posted_at,u.display_name AS posted_by FROM vouchers v LEFT JOIN journal_entries je ON je.id=v.journal_entry_id LEFT JOIN users u ON u.id=v.posted_by WHERE v.company_id=? AND v.voucher_date BETWEEN ? AND ?";$params=[$companyId,$start,$end];
        if($status!=='all'){$sql.=' AND v.status=?';$params[]=$status;}
        if($module!==''){$sql.=' AND v.module=?';$params[]=$module;}if($sourceType!==''){$sql.=' AND v.source_type=?';$params[]=$sourceType;}if($sourceId!==''){$sql.=' AND v.source_id=?';$params[]=$sourceId;}if($journalId!==''){$sql.=' AND v.journal_entry_id=?';$params[]=$journalId;}$sql.=' ORDER BY v.voucher_date,v.serial_number,v.created_at LIMIT 5000';
    }else{
        $moduleCase="CASE WHEN je.source_type IN ('invoice','invoice_reversal','customer_payment','customer_payment_reversal','opening_customer_invoices') THEN 'AR' WHEN je.source_type IN ('bill','bill_reversal','vendor_payment','vendor_payment_reversal','opening_vendor_bills') THEN 'AP' WHEN je.source_type LIKE 'payroll%' THEN 'PL' WHEN je.source_type='expense' THEN 'EX' WHEN je.source_type IN ('bank_transaction','bank_payment_match') THEN 'BS' ELSE 'GL' END";
        $mappedStatus="CASE WHEN je.status='posted' THEN 'posted' ELSE 'void' END";
        $sql="SELECT je.id,0 AS serial_number,'View Day Book entry' AS voucher_number,$moduleCase AS module,je.source_type,je.source_id,je.entry_date AS voucher_date,je.memo AS description,COALESCE(SUM(jl.debit_cents),0) AS amount_cents,$mappedStatus AS status,je.id AS journal_entry_id,je.created_at,je.memo,je.created_at AS posted_at,u.display_name AS posted_by FROM journal_entries je LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id LEFT JOIN users u ON u.id=je.created_by WHERE je.company_id=? AND je.entry_date BETWEEN ? AND ?";$params=[$companyId,$start,$end];
        if($status==='posted')$sql.=" AND je.status='posted'";elseif($status==='void')$sql.=" AND je.status<>'posted'";elseif($status==='draft')$sql.=" AND 1=0";
        if($module!==''){$sql.=" AND $moduleCase=?";$params[]=$module;}if($sourceType!==''){$sql.=' AND je.source_type=?';$params[]=$sourceType;}if($sourceId!==''){$sql.=' AND je.source_id=?';$params[]=$sourceId;}if($journalId!==''){$sql.=' AND je.id=?';$params[]=$journalId;}$sql.=' GROUP BY je.id,je.entry_date,je.source_type,je.source_id,je.memo,je.status,je.created_at,u.display_name ORDER BY je.entry_date,je.created_at,je.id LIMIT 5000';
    }
    $stmt=db()->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
    if(schema_table_exists('vouchers')&&($module===''||$module==='GL')&&($status==='all'||$status==='posted'||$status==='void')){
        // Schema 38-41 depreciation journals created before Build 5710 can be
        // valid in the canonical ledger but absent from the later voucher
        // register. Include those journals read-only so Day Book and GL agree;
        // do not mutate historical accounting merely because it is viewed.
        $mappedStatus="CASE WHEN je.status='posted' THEN 'posted' ELSE 'void' END";
        $legacySql="SELECT je.id,0 AS serial_number,
            CONCAT('FA-',DATE_FORMAT(je.entry_date,'%Y%m%d'),'-',LEFT(je.id,10)) AS voucher_number,
            'GL' AS module,je.source_type,je.source_id,je.entry_date AS voucher_date,je.memo AS description,
            COALESCE(SUM(jl.debit_cents),0) AS amount_cents,$mappedStatus AS status,
            je.id AS journal_entry_id,je.created_at,je.memo,je.created_at AS posted_at,u.display_name AS posted_by
          FROM journal_entries je
          LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id
          LEFT JOIN users u ON u.id=je.created_by
          LEFT JOIN vouchers v ON v.company_id=je.company_id AND v.journal_entry_id=je.id
          WHERE je.company_id=? AND je.entry_date BETWEEN ? AND ? AND v.id IS NULL
            AND je.source_type IN ('asset_depreciation','asset_depreciation_duplicate_reversal')";
        $legacyParams=[$companyId,$start,$end];
        if($status==='posted')$legacySql.=" AND je.status='posted'";
        elseif($status==='void')$legacySql.=" AND je.status<>'posted'";
        if($sourceType!==''){$legacySql.=' AND je.source_type=?';$legacyParams[]=$sourceType;}
        if($sourceId!==''){$legacySql.=' AND je.source_id=?';$legacyParams[]=$sourceId;}
        if($journalId!==''){$legacySql.=' AND je.id=?';$legacyParams[]=$journalId;}
        $legacySql.=' GROUP BY je.id,je.entry_date,je.source_type,je.source_id,je.memo,je.status,je.created_at,u.display_name';
        $legacyStmt=db()->prepare($legacySql);$legacyStmt->execute($legacyParams);
        $rows=array_merge($rows,$legacyStmt->fetchAll());
        usort($rows,static fn(array $a,array $b):int=>[
            (string)$a['voucher_date'],(int)$a['serial_number'],(string)$a['created_at'],(string)$a['id']
        ]<=>[
            (string)$b['voucher_date'],(int)$b['serial_number'],(string)$b['created_at'],(string)$b['id']
        ]);
        if(count($rows)>5000)$rows=array_slice($rows,0,5000);
    }
    // Batch-load journal lines instead of issuing one query per Day Book row.
    // At the 5,000-row safety cap this avoids thousands of round trips while
    // preserving the exact row/line relationship and ordering.
    $linesByJournal=[];$journalIds=[];
    foreach($rows as $row)if(!empty($row['journal_entry_id']))$journalIds[]=(string)$row['journal_entry_id'];
    $journalIds=array_values(array_unique($journalIds));
    foreach(array_chunk($journalIds,400) as $chunk){
        $ph=implode(',',array_fill(0,count($chunk),'?'));
        $lineStmt=db()->prepare("SELECT jl.journal_entry_id,a.code,a.name,jl.memo AS description,jl.debit_cents,jl.credit_cents FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id IN ($ph) ORDER BY jl.journal_entry_id,jl.id");
        $lineStmt->execute($chunk);
        foreach($lineStmt->fetchAll() as $line){$jid=(string)$line['journal_entry_id'];$linesByJournal[$jid][]=['glCode'=>(string)$line['code'],'account'=>(string)$line['name'],'description'=>(string)$line['description'],'debitCents'=>(int)$line['debit_cents'],'creditCents'=>(int)$line['credit_cents']];}
    }
    $partnerByJournal=[];$remarksByJournal=[];
    foreach(array_chunk($journalIds,400) as $chunk){
        $ph=implode(',',array_fill(0,count($chunk),'?'));
        $partnerStmt=db()->prepare("SELECT pp.journal_entry_id,GROUP_CONCAT(DISTINCT CASE WHEN pp.payment_type='customer' THEN c.name ELSE v.name END ORDER BY CASE WHEN pp.payment_type='customer' THEN c.name ELSE v.name END SEPARATOR ', ') partner,GROUP_CONCAT(DISTINCT NULLIF(pp.memo,'') ORDER BY pp.memo SEPARATOR '; ') remarks FROM party_payments pp LEFT JOIN customers c ON pp.payment_type='customer' AND c.id=pp.party_id AND c.company_id=pp.company_id LEFT JOIN vendors v ON pp.payment_type='vendor' AND v.id=pp.party_id AND v.company_id=pp.company_id WHERE pp.company_id=? AND pp.journal_entry_id IN ($ph) GROUP BY pp.journal_entry_id");
        $partnerStmt->execute(array_merge([$companyId],$chunk));foreach($partnerStmt->fetchAll() as $context){$key=(string)$context['journal_entry_id'];$partnerByJournal[$key]=(string)($context['partner']??'');$remarksByJournal[$key]=(string)($context['remarks']??'');}
    }
    $bankRemarks=[];$bankIds=[];foreach($rows as $row)if((string)$row['source_type']==='bank_transaction'&&!empty($row['source_id']))$bankIds[]=(string)$row['source_id'];
    foreach(array_chunk(array_values(array_unique($bankIds)),400) as $chunk){$ph=implode(',',array_fill(0,count($chunk),'?'));$br=db()->prepare("SELECT id,remarks FROM bank_transactions WHERE company_id=? AND id IN ($ph)");$br->execute(array_merge([$companyId],$chunk));foreach($br->fetchAll() as $context)$bankRemarks[(string)$context['id']]=(string)($context['remarks']??'');}
    $sourcePartners=[];$invoiceIds=[];$billIds=[];
    foreach($rows as $row){$type=(string)$row['source_type'];$id=(string)($row['source_id']??'');if($id==='')continue;if(in_array($type,['invoice','invoice_reversal'],true))$invoiceIds[]=$id;if(in_array($type,['bill','bill_reversal'],true))$billIds[]=$id;}
    foreach(array_chunk(array_values(array_unique($invoiceIds)),400) as $chunk){$ph=implode(',',array_fill(0,count($chunk),'?'));$sp=db()->prepare("SELECT i.id,c.name FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.company_id=? AND i.id IN ($ph)");$sp->execute(array_merge([$companyId],$chunk));foreach($sp->fetchAll() as $context)$sourcePartners['invoice:'.(string)$context['id']]=(string)$context['name'];}
    foreach(array_chunk(array_values(array_unique($billIds)),400) as $chunk){$ph=implode(',',array_fill(0,count($chunk),'?'));$sp=db()->prepare("SELECT b.id,v.name FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id WHERE b.company_id=? AND b.id IN ($ph)");$sp->execute(array_merge([$companyId],$chunk));foreach($sp->fetchAll() as $context)$sourcePartners['bill:'.(string)$context['id']]=(string)$context['name'];}
    $voidAudit=[];
    if(schema_table_exists('audit_log')){
        $log=db()->prepare("SELECT actor_email,metadata_json,created_at FROM audit_log WHERE company_id=? AND action='transaction.voided' ORDER BY created_at DESC LIMIT 2000");$log->execute([$companyId]);
        foreach($log->fetchAll() as $entry){$meta=json_decode((string)$entry['metadata_json'],true);if(!is_array($meta))$meta=[];foreach((array)($meta['journalEntriesVoided']??[]) as $journalId){$key=(string)$journalId;if(isset($voidAudit[$key]))continue;$voidAudit[$key]=['voidedBy'=>(string)$entry['actor_email'],'voidedAt'=>(string)$entry['created_at'],'reason'=>(string)($meta['reason']??'')];}}
    }
    $depreciationContext=[];$depreciationLineIds=[];
    foreach($rows as $row)if((string)$row['source_type']==='asset_depreciation'&&!empty($row['source_id']))$depreciationLineIds[]=(string)$row['source_id'];
    foreach(array_chunk(array_values(array_unique($depreciationLineIds)),400) as $chunk){
        $ph=implode(',',array_fill(0,count($chunk),'?'));
        $contextStmt=db()->prepare("SELECT dl.id,dl.asset_id,dl.sequence_number,dl.period_end,dl.depreciation_cents,
            fa.name AS asset_name,'Primary book' AS depreciation_book,NULL AS component
            FROM asset_depreciation_lines dl JOIN fixed_assets fa ON fa.id=dl.asset_id
            WHERE fa.company_id=? AND dl.id IN ($ph)");
        $contextStmt->execute(array_merge([$companyId],$chunk));
        foreach($contextStmt->fetchAll() as $context)$depreciationContext[(string)$context['id']]=[
            'scheduleOccurrenceId'=>(string)$context['id'],'assetId'=>(string)$context['asset_id'],'assetName'=>(string)$context['asset_name'],
            'depreciationBook'=>(string)$context['depreciation_book'],'component'=>$context['component'],
            'sequenceNumber'=>(int)$context['sequence_number'],'periodEnd'=>(string)$context['period_end'],'amountCents'=>(int)$context['depreciation_cents'],
        ];
    }
    $relatedStmt=db()->prepare("SELECT id FROM journal_entries WHERE company_id=? AND source_id=? AND id<>? ORDER BY created_at DESC LIMIT 1");
    $payrollStates=[];$payrollIds=[];foreach($rows as $r)if((string)$r['source_type']==='payroll_run'&&!empty($r['source_id']))$payrollIds[]=(string)$r['source_id'];
    if($payrollIds!==[]){$payrollIds=array_values(array_unique($payrollIds));$ph=implode(',',array_fill(0,count($payrollIds),'?'));$ps=db()->prepare("SELECT id,status,gl_status FROM payroll_runs WHERE company_id=? AND id IN ($ph)");$ps->execute(array_merge([$companyId],$payrollIds));foreach($ps->fetchAll() as $pr)$payrollStates[(string)$pr['id']]=['status'=>(string)$pr['status'],'glStatus'=>(string)$pr['gl_status']];}
    $out=[];foreach($rows as $r){$lines=$r['journal_entry_id']?($linesByJournal[(string)$r['journal_entry_id']]??[]):[];
        $void=$r['journal_entry_id']?($voidAudit[(string)$r['journal_entry_id']]??null):null;$related=null;
        if((string)$r['status']==='void'&&$r['journal_entry_id']&&$r['source_id']){$relatedStmt->execute([$companyId,(string)$r['source_id'],(string)$r['journal_entry_id']]);$found=$relatedStmt->fetchColumn();$related=$found!==false?(string)$found:null;}
        $postingLabel=$r['journal_entry_id']?'Posted To General Ledger':'Not Posted To General Ledger';$accountingStatus=null;if((string)$r['source_type']==='payroll_run'&&isset($payrollStates[(string)$r['source_id']])){$accountingStatus=$payrollStates[(string)$r['source_id']];$postingLabel=match($accountingStatus['glStatus']){'ready_to_post'=>'Verified · Ready to Post','posted'=>'Posted To General Ledger','not_applicable'=>'GL posting disabled for this payroll run','reversed'=>'Reversed','not_ready'=>'Draft · Not Posted To General Ledger',default=>'Not Posted To General Ledger'};}
        $sourceContext=(string)$r['source_type']==='asset_depreciation'?($depreciationContext[(string)($r['source_id']??'')]??null):null;
        $jid=(string)($r['journal_entry_id']??'');$remarks=(string)($bankRemarks[(string)($r['source_id']??'')]??($remarksByJournal[$jid]??''));$sourceClass=str_starts_with((string)$r['source_type'],'invoice')?'invoice':(str_starts_with((string)$r['source_type'],'bill')?'bill':'');$partner=(string)($partnerByJournal[$jid]??($sourcePartners[$sourceClass.':'.(string)($r['source_id']??'')]??''));
        $out[]=['id'=>(string)$r['id'],'serial'=>(int)$r['serial_number'],'voucherNumber'=>(string)$r['voucher_number'],'module'=>(string)$r['module'],'sourceType'=>(string)$r['source_type'],'sourceId'=>(string)($r['source_id']??''),'sourceContext'=>$sourceContext,'date'=>(string)$r['voucher_date'],'description'=>(string)$r['description'],'partner'=>$partner,'remarks'=>$remarks,'amountCents'=>(int)$r['amount_cents'],'status'=>(string)$r['status'],'journalEntryId'=>$r['journal_entry_id'],'postingLabel'=>$postingLabel,'accountingStatus'=>$accountingStatus,'postedAt'=>$r['posted_at'],'postedBy'=>$r['posted_by'],'voidedAt'=>$void['voidedAt']??null,'voidedBy'=>$void['voidedBy']??null,'voidReason'=>$void['reason']??null,'relatedEntryId'=>$related,'lines'=>$lines];}
    json_response(['start'=>$start,'end'=>$end,'status'=>$status,'entries'=>$out,'count'=>count($out),'voidedCount'=>count(array_filter($out,static fn(array $e):bool=>($e['status']??'')==='void'))]);
}


function portal_audit_history(array $company): never
{
    require_method('GET');require_company_permission($company,'audit.view');$companyId=(string)$company['id'];
    $start=safe_date($_GET['start']??date('Y-m-d',strtotime('-90 days')),'Start date');$end=safe_date($_GET['end']??date('Y-m-d'),'End date');if($start>$end)fail('Start date must not be after end date.');
    $category=strtolower(trim((string)($_GET['category']??'all')));if(!in_array($category,['all','transactions','posting','voiding','reconciliation','users','accounting'],true))fail('Audit category is invalid.');
    $sql="SELECT actor_email,action,entity_type,metadata_json,created_at FROM audit_log WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ?";$params=[$companyId,$start,$end];
    if($category==='posting'){$sql.=" AND action LIKE '%post%'";}
    elseif($category==='voiding'){$sql.=" AND (action LIKE '%void%' OR action LIKE '%reverse%')";}
    elseif($category==='reconciliation'){$sql.=" AND action LIKE 'reconciliation.%'";}
    elseif($category==='users'){$sql.=" AND (action LIKE 'company.user%' OR action LIKE 'company.account_setup%' OR action LIKE '%role%' OR entity_type IN ('company_member','company_invitation'))";}
    elseif($category==='transactions'){$sql.=" AND entity_type NOT IN ('company_member','company_invitation','audit_integrity_run','support_request')";}
    elseif($category==='accounting'){$sql.=" AND (entity_type IN ('invoice','bill','expense','journal_entry','bank_transaction','party_payment','payroll_run','reconciliation','voucher') OR action LIKE 'integrity.%')";}
    $sql.=' ORDER BY created_at DESC,id DESC LIMIT 2000';$stmt=db()->prepare($sql);$stmt->execute($params);
    $events=array_map(static function(array $r):array{
        $action=(string)$r['action'];$meta=json_decode((string)($r['metadata_json']??'{}'),true);if(!is_array($meta))$meta=[];$detail=[];
        // Only expose accounting/user-facing audit context. Internal entity IDs,
        // request IDs, hashes, stack traces and arbitrary metadata remain private.
        if(in_array($action,['company.account_setup_created','company.user_invited'],true)){
            if(!empty($meta['email']))$detail[]='Invitee: '.(string)$meta['email'];
            if(!empty($meta['role']))$detail[]='Role: '.company_role_label((string)$meta['role']);
        }elseif($action==='company.account_setup_completed'){
            if(!empty($meta['role']))$detail[]='Accepted role: '.company_role_label((string)$meta['role']);
        }elseif($action==='company.member_role_changed'){
            if(!empty($meta['email']))$detail[]=(string)$meta['email'];
            if(!empty($meta['from'])||!empty($meta['to']))$detail[]='Role: '.company_role_label((string)($meta['from']??'')).' → '.company_role_label((string)($meta['to']??''));
        }elseif(in_array($action,['company.member_suspended','company.member_reactivated','company.member_removed'],true)){
            if(!empty($meta['email']))$detail[]=(string)$meta['email'];
            if(!empty($meta['role']))$detail[]='Role: '.company_role_label((string)$meta['role']);
        }elseif($action==='company.ownership_transferred'){
            if(!empty($meta['newOwnerEmail']))$detail[]='New owner: '.(string)$meta['newOwnerEmail'];
        }
        return ['actor'=>(string)$r['actor_email'],'action'=>$action,'recordType'=>(string)$r['entity_type'],'detail'=>implode(' · ',$detail),'createdAt'=>(string)$r['created_at']];
    },$stmt->fetchAll());
    json_response(['start'=>$start,'end'=>$end,'category'=>$category,'events'=>$events,'count'=>count($events)]);
}

function portal_gl_account_ledger(array $company): never
{
    require_method('GET');
    require_company_permission($company,'reports.view');
    $companyId=(string)$company['id'];
    $accountId=trim((string)($_GET['accountId']??''));
    if($accountId==='') fail('Choose a General Ledger account.');
    $start=safe_date($_GET['start']??date('Y-m-01'),'Start date');
    $end=safe_date($_GET['end']??date('Y-m-d'),'End date');
    if($start>$end) fail('Start date must not be after end date.');

    $stmt=db()->prepare('SELECT id,code,name,account_type,normal_balance,active FROM accounts WHERE id=? AND company_id=? LIMIT 1');
    $stmt->execute([$accountId,$companyId]);
    $account=$stmt->fetch();
    if(!$account) fail('The selected General Ledger account was not found.',404,'account_not_found');

    $stmt=db()->prepare("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0)
        FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id
        WHERE je.company_id=? AND je.status='posted' AND jl.account_id=? AND je.entry_date<?");
    $stmt->execute([$companyId,$accountId,$start]);
    $openingSigned=(int)$stmt->fetchColumn();

    $stmt=db()->prepare("SELECT jl.id,je.id AS entry_id,je.entry_date,je.memo,je.source_type,je.source_id,je.created_at,
        jl.debit_cents,jl.credit_cents,v.voucher_number AS transaction_number
        FROM journal_lines jl
        JOIN journal_entries je ON je.id=jl.journal_entry_id
        LEFT JOIN vouchers v ON v.company_id=je.company_id AND v.source_type=je.source_type AND v.source_id=je.source_id
        WHERE je.company_id=? AND je.status='posted' AND jl.account_id=? AND je.entry_date BETWEEN ? AND ?
        ORDER BY je.entry_date ASC,je.created_at ASC,jl.id ASC");
    $stmt->execute([$companyId,$accountId,$start,$end]);
    $running=$openingSigned;$periodDebit=0;$periodCredit=0;$entries=[];
    foreach($stmt->fetchAll() as $row){
        $debit=(int)$row['debit_cents'];$credit=(int)$row['credit_cents'];
        $periodDebit+=$debit;$periodCredit+=$credit;$running+=$debit-$credit;
        $entries[]=[
            'lineId'=>(string)$row['id'],'entryId'=>(string)$row['entry_id'],'date'=>(string)$row['entry_date'],
            'memo'=>(string)($row['memo']??''),'sourceType'=>(string)($row['source_type']??''),'sourceId'=>(string)($row['source_id']??''),
            'voucherNumber'=>$row['transaction_number']!==null?(string)$row['transaction_number']:null,
            'debitCents'=>$debit,'creditCents'=>$credit,
            'runningDebitCents'=>max(0,$running),'runningCreditCents'=>max(0,-$running),
        ];
    }
    $closingSigned=$openingSigned+$periodDebit-$periodCredit;
    json_response([
        'currency'=>'CAD','start'=>$start,'end'=>$end,
        'account'=>['id'=>(string)$account['id'],'code'=>(string)$account['code'],'name'=>(string)$account['name'],'type'=>(string)$account['account_type'],'normalBalance'=>(string)$account['normal_balance'],'active'=>(bool)$account['active']],
        'openingDebitCents'=>max(0,$openingSigned),'openingCreditCents'=>max(0,-$openingSigned),
        'periodDebitCents'=>$periodDebit,'periodCreditCents'=>$periodCredit,
        'closingDebitCents'=>max(0,$closingSigned),'closingCreditCents'=>max(0,-$closingSigned),
        'entries'=>$entries,'count'=>count($entries),
    ]);
}

function portal_trial_balance(array $company): never
{
    require_method('GET');
    $companyId = (string)$company['id'];
    $scope = strtolower(trim((string)($_GET['scope'] ?? 'general-ledger')));
    if (!in_array($scope, ['receivables','payables','general-ledger'], true)) fail('Trial balance scope is invalid.');
    $start = safe_date($_GET['start'] ?? date('Y-m-01'), 'Start date');
    $end = safe_date($_GET['end'] ?? date('Y-m-d'), 'End date');
    if ($start > $end) fail('Start date must not be after end date.');

    if ($scope === 'general-ledger') {
        $stmt = db()->prepare("SELECT a.id,a.code,a.name,a.account_type,a.normal_balance,
            COALESCE(SUM(CASE WHEN je.entry_date < ? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) AS opening_signed,
            COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.debit_cents ELSE 0 END),0) AS period_debit,
            COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.credit_cents ELSE 0 END),0) AS period_credit
          FROM accounts a
          LEFT JOIN journal_lines jl ON jl.account_id=a.id
          LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id AND je.status='posted' AND je.entry_date<=?
          WHERE a.company_id=?
          GROUP BY a.id,a.code,a.name,a.account_type,a.normal_balance
          ORDER BY a.code,a.name");
        $stmt->execute([$start,$start,$end,$start,$end,$end,$companyId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $opening = (int)$row['opening_signed'];
            $debit = (int)$row['period_debit'];
            $credit = (int)$row['period_credit'];
            $closing = $opening + $debit - $credit;
            if ($opening === 0 && $debit === 0 && $credit === 0 && $closing === 0) continue;
            $rows[] = [
                'id'=>(string)$row['id'],'code'=>(string)$row['code'],'name'=>(string)$row['name'],
                'type'=>(string)$row['account_type'],'normalBalance'=>(string)$row['normal_balance'],
                'openingDebitCents'=>max(0,$opening),'openingCreditCents'=>max(0,-$opening),
                'periodDebitCents'=>$debit,'periodCreditCents'=>$credit,
                'closingDebitCents'=>max(0,$closing),'closingCreditCents'=>max(0,-$closing),
            ];
        }
    } else {
        $receivable = $scope === 'receivables';
        if ($receivable) {
            $partyStmt = db()->prepare('SELECT id,name FROM customers WHERE company_id=? ORDER BY name');
            $docStmt = db()->prepare("SELECT i.id,i.customer_id AS party_id,i.issue_date AS document_date,i.total_cents,i.is_opening_document,i.original_paid_cents
                FROM invoices i WHERE i.company_id=? AND i.status NOT IN ('draft','void') AND i.issue_date<=?");
            $auditAction = 'invoice.payment_matched';
        } else {
            $partyStmt = db()->prepare('SELECT id,name FROM vendors WHERE company_id=? ORDER BY name');
            $docStmt = db()->prepare("SELECT b.id,b.vendor_id AS party_id,b.bill_date AS document_date,b.total_cents,b.is_opening_document,b.original_paid_cents
                FROM bills b WHERE b.company_id=? AND b.status NOT IN ('draft','void') AND b.bill_date<=?");
            $auditAction = 'bill.payment_matched';
        }
        $partyStmt->execute([$companyId]);
        $partyRows = $partyStmt->fetchAll();
        $balances = [];
        foreach ($partyRows as $party) {
            $balances[(string)$party['id']] = [
                'id'=>(string)$party['id'],'code'=>'','name'=>(string)$party['name'],
                'type'=>$receivable?'Customer':'Vendor','normalBalance'=>$receivable?'debit':'credit',
                'openingDebitCents'=>0,'openingCreditCents'=>0,'periodDebitCents'=>0,'periodCreditCents'=>0,
            ];
        }
        $docStmt->execute([$companyId,$end]);
        $documentParty = [];
        foreach ($docStmt->fetchAll() as $document) {
            $partyId = (string)$document['party_id'];
            if (!isset($balances[$partyId])) continue;
            $documentParty[(string)$document['id']] = $partyId;
            $opening = (string)$document['document_date'] < $start;
            $field = $receivable
                ? ($opening?'openingDebitCents':'periodDebitCents')
                : ($opening?'openingCreditCents':'periodCreditCents');
            $balances[$partyId][$field] += (int)$document['total_cents'];
            // Opening/cutover documents preserve the historical original total for
            // the party ledger, but only their outstanding amount was posted to
            // AR/AP at Start of Books. Mirror the pre-cutover paid portion here so
            // the receivables/payables subledger Trial Balance reconciles to GL.
            $priorPaid = !empty($document['is_opening_document']) ? max(0,(int)($document['original_paid_cents']??0)) : 0;
            if ($priorPaid > 0) {
                $priorField = $receivable
                    ? ($opening?'openingCreditCents':'periodCreditCents')
                    : ($opening?'openingDebitCents':'periodDebitCents');
                $balances[$partyId][$priorField] += $priorPaid;
            }
        }
        // The payment register is the authoritative source for both payments
        // entered before a statement import and payments created by a direct
        // invoice/bill match. Use applied_cents rather than the bank amount so
        // foreign-exchange differences do not distort the party subledger.
        $registeredBankTransactions = [];
        $paymentStmt = db()->prepare("SELECT pp.party_id,pp.payment_date,pp.applied_cents,pp.bank_transaction_id,
                pp.status,pp.reversed_at,rje.entry_date AS reversal_date
            FROM party_payments pp
            LEFT JOIN journal_entries rje ON rje.id=pp.reversal_journal_entry_id
            WHERE pp.company_id=? AND pp.payment_type=? AND pp.payment_date<=?
            ORDER BY pp.payment_date,pp.created_at,pp.id");
        $paymentStmt->execute([$companyId,$receivable?'customer':'vendor',$end]);
        foreach ($paymentStmt->fetchAll() as $payment) {
            $partyId = (string)$payment['party_id'];
            $amount = abs((int)$payment['applied_cents']);
            if (!isset($balances[$partyId]) || $amount <= 0) continue;
            $opening = (string)$payment['payment_date'] < $start;
            $field = $receivable
                ? ($opening?'openingCreditCents':'periodCreditCents')
                : ($opening?'openingDebitCents':'periodDebitCents');
            $balances[$partyId][$field] += $amount;
            if ((string)$payment['status']==='reversed' && $payment['reversed_at']!==null) {
                $reversalDate = (string)($payment['reversal_date'] ?: substr((string)$payment['reversed_at'],0,10));
                if ($reversalDate <= $end) {
                    $reversalOpening = $reversalDate < $start;
                    $reversalField = $receivable
                        ? ($reversalOpening?'openingDebitCents':'periodDebitCents')
                        : ($reversalOpening?'openingCreditCents':'periodCreditCents');
                    $balances[$partyId][$reversalField] += $amount;
                }
            }
            if ((string)$payment['status']==='posted' && $payment['bank_transaction_id'] !== null) {
                $registeredBankTransactions[(string)$payment['bank_transaction_id']] = true;
            }
        }

        // Compatibility path for bank matches posted before the v13 payment
        // register existed. Transactions represented above are skipped so a
        // current direct bank match can never be counted twice.
        $auditStmt = db()->prepare('SELECT entity_id,metadata_json FROM audit_log WHERE company_id=? AND action=? ORDER BY created_at,id');
        $auditStmt->execute([$companyId,$auditAction]);
        $legacyPayments = [];
        foreach ($auditStmt->fetchAll() as $event) {
            $partyId = $documentParty[(string)$event['entity_id']] ?? null;
            if ($partyId === null) continue;
            $metadata = json_decode((string)$event['metadata_json'], true);
            if (!is_array($metadata)) continue;
            $transactionId = trim((string)($metadata['transactionId'] ?? ''));
            $amount = abs((int)($metadata['amountCents'] ?? 0));
            if ($transactionId !== '' && $amount > 0 && !isset($registeredBankTransactions[$transactionId])) {
                $legacyPayments[] = [$partyId,$transactionId,$amount];
            }
        }
        if ($legacyPayments) {
            $transactionIds = array_values(array_unique(array_map(static fn(array $payment): string => $payment[1], $legacyPayments)));
            $placeholders = implode(',', array_fill(0,count($transactionIds),'?'));
            $dateStmt = db()->prepare("SELECT id,transaction_date FROM bank_transactions WHERE company_id=? AND id IN ($placeholders)");
            $dateStmt->execute([$companyId,...$transactionIds]);
            $dates = [];
            foreach ($dateStmt->fetchAll() as $transaction) $dates[(string)$transaction['id']] = (string)$transaction['transaction_date'];
            foreach ($legacyPayments as [$partyId,$transactionId,$amount]) {
                $paymentDate = $dates[$transactionId] ?? null;
                if ($paymentDate === null || $paymentDate > $end) continue;
                $opening = $paymentDate < $start;
                $field = $receivable
                    ? ($opening?'openingCreditCents':'periodCreditCents')
                    : ($opening?'openingDebitCents':'periodDebitCents');
                $balances[$partyId][$field] += $amount;
            }
        }
        $rows = [];
        foreach ($balances as $row) {
            $opening = $row['openingDebitCents']-$row['openingCreditCents'];
            $closing = $opening+$row['periodDebitCents']-$row['periodCreditCents'];
            $row['openingDebitCents']=max(0,$opening);$row['openingCreditCents']=max(0,-$opening);
            $row['closingDebitCents']=max(0,$closing);$row['closingCreditCents']=max(0,-$closing);
            if ($row['openingDebitCents']||$row['openingCreditCents']||$row['periodDebitCents']||$row['periodCreditCents']||$row['closingDebitCents']||$row['closingCreditCents']) $rows[]=$row;
        }
        usort($rows, static fn(array $a,array $b): int => strcasecmp($a['name'],$b['name']));
    }
    $totals = ['openingDebitCents'=>0,'openingCreditCents'=>0,'periodDebitCents'=>0,'periodCreditCents'=>0,'closingDebitCents'=>0,'closingCreditCents'=>0];
    foreach ($rows as $row) foreach ($totals as $key => $unused) $totals[$key] += (int)$row[$key];
    json_response(['scope'=>$scope,'start'=>$start,'end'=>$end,'rows'=>$rows,'totals'=>$totals,'currency'=>(string)$company['currency']]);
}

function portal_voucher_drafts(array $user, array $company): never
{
    $companyId = (string)$company['id'];
    $sourceType = strtolower(trim((string)($_GET['type'] ?? '')));
    if ($sourceType !== '' && !in_array($sourceType, ['expense','manual_journal'], true)) fail('Draft type is invalid.');
    if (request_method() === 'GET') {
        $sql = "SELECT d.*,v.voucher_number,v.voucher_date,v.description,v.amount_cents
            FROM voucher_draft_payloads d
            LEFT JOIN vouchers v ON v.company_id=d.company_id AND v.source_type=d.source_type AND v.source_id=d.source_id
            WHERE d.company_id=?";
        $params = [$companyId];
        if ($sourceType !== '') {$sql .= ' AND d.source_type=?';$params[]=$sourceType;}
        $sql .= ' ORDER BY d.updated_at DESC,d.id DESC LIMIT 250';
        $stmt = db()->prepare($sql);$stmt->execute($params);
        $drafts = [];
        foreach ($stmt->fetchAll() as $row) {
            $payload = json_decode((string)$row['payload_json'],true);
            if (!is_array($payload)) $payload=[];
            $drafts[] = [
                'id'=>(string)$row['id'],'sourceType'=>(string)$row['source_type'],'sourceId'=>(string)$row['source_id'],
                'voucherNumber'=>$row['voucher_number'],'date'=>$row['voucher_date'],'description'=>$row['description'],
                'amountCents'=>(int)($row['amount_cents']??0),'payload'=>$payload,'updatedAt'=>(string)$row['updated_at'],
            ];
        }
        json_response(['drafts'=>$drafts]);
    }
    require_csrf();require_company_role($company,'owner','bookkeeper');
    $input = request_json();
    if (request_method() === 'DELETE') {
        $sourceType = strtolower(clean_text($input['sourceType'] ?? '', 'Draft type', 60));
        if (!in_array($sourceType,['expense','manual_journal'],true)) fail('Draft type is invalid.');
        $sourceId = clean_text($input['sourceId'] ?? '', 'Draft', 64);
        $stmt = db()->prepare('DELETE FROM voucher_draft_payloads WHERE company_id=? AND source_type=? AND source_id=?');
        $stmt->execute([$companyId,$sourceType,$sourceId]);
        if ($stmt->rowCount() === 0) fail('Draft not found.',404,'voucher_draft_not_found');
        if (function_exists('voucher_mark_void')) voucher_mark_void($user,$companyId,$sourceType,$sourceId);
        audit_event($user,$companyId,'voucher.draft_deleted',$sourceType,$sourceId,[]);
        json_response(['deleted'=>true]);
    }
    require_method('POST');
    $sourceType = strtolower(clean_text($input['sourceType'] ?? '', 'Draft type', 60));
    if (!in_array($sourceType,['expense','manual_journal'],true)) fail('Draft type is invalid.');
    $sourceId = trim((string)($input['sourceId']??''));
    if ($sourceId === '') $sourceId = new_id($sourceType==='expense'?'expense':'manual');
    else $sourceId = clean_text($sourceId,'Draft',64);
    $payload = $input['payload']??null;
    if (!is_array($payload)) fail('Draft details are invalid.');
    $payloadJson = json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if (strlen($payloadJson)>50000) fail('The draft contains too much data.');
    $voucherDate = safe_date($input['date']??canadian_today(),'Voucher date');
    $description = clean_text($input['description']??($sourceType==='expense'?'Expense draft':'Manual journal draft'),'Description',500);
    $amount = safe_cents($input['amountCents']??0,'Draft amount',true);
    if ($amount < 0) fail('Draft amount cannot be negative.');
    db()->prepare('INSERT INTO voucher_draft_payloads (id,company_id,source_type,source_id,payload_json,created_by)
        VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),updated_at=CURRENT_TIMESTAMP')
        ->execute([new_id('vdraft'),$companyId,$sourceType,$sourceId,$payloadJson,$user['id']]);
    if (function_exists('voucher_register_saved')) {
        $prefix=$sourceType==='expense'?'EX':'GL';$module=$prefix;
        voucher_register_saved($user,$companyId,$prefix,$module,$sourceType,$sourceId,$voucherDate,$description,$amount,null,false);
        db()->prepare("UPDATE vouchers SET voucher_date=?,description=?,amount_cents=? WHERE company_id=? AND source_type=? AND source_id=? AND status='draft'")
            ->execute([$voucherDate,$description,$amount,$companyId,$sourceType,$sourceId]);
    }
    audit_event($user,$companyId,'voucher.draft_saved',$sourceType,$sourceId,['date'=>$voucherDate,'amountCents'=>$amount]);
    json_response(['draft'=>['sourceId'=>$sourceId,'status'=>'draft']],201);
}

function portal_aging_profile(string $companyId): array
{
    $stmt=db()->prepare('SELECT * FROM aging_profiles WHERE company_id=?');$stmt->execute([$companyId]);$r=$stmt->fetch();
    return $r?:['ar_cutoff_1'=>30,'ar_cutoff_2'=>60,'ar_cutoff_3'=>90,'ap_cutoff_1'=>30,'ap_cutoff_2'=>60,'ap_cutoff_3'=>90];
}
function portal_validate_cutoffs(array $values): array
{
    $v=array_map('intval',$values);if(count($v)!==3||$v[0]<1||$v[0]>365||$v[1]<=$v[0]||$v[2]<=$v[1]||$v[2]>730)fail('Ageing cut-offs must be increasing positive day values.');return $v;
}
function portal_aging(array $user,array $company): never
{
    $companyId=(string)$company['id'];$type=(string)($_GET['type']??'receivable');if(!in_array($type,['receivable','payable'],true))fail('Ageing report type is invalid.');
    if(request_method()==='POST'){
        require_csrf();require_company_role($company,'owner','bookkeeper');$input=request_json();$cut=portal_validate_cutoffs($input['cutoffs']??[]);$prefix=$type==='receivable'?'ar':'ap';
        db()->prepare("INSERT INTO aging_profiles (company_id,{$prefix}_cutoff_1,{$prefix}_cutoff_2,{$prefix}_cutoff_3,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE {$prefix}_cutoff_1=VALUES({$prefix}_cutoff_1),{$prefix}_cutoff_2=VALUES({$prefix}_cutoff_2),{$prefix}_cutoff_3=VALUES({$prefix}_cutoff_3),updated_by=VALUES(updated_by)")
            ->execute([$companyId,$cut[0],$cut[1],$cut[2],$user['id']]);audit_event($user,$companyId,'aging.profile_updated','aging_profile',$companyId,['type'=>$type,'cutoffs'=>$cut]);json_response(['cutoffs'=>$cut]);
    }
    require_method('GET');$asOf=safe_date($_GET['asOf']??date('Y-m-d'),'As-of date');$partyId=trim((string)($_GET['partyId']??''));$profile=portal_aging_profile($companyId);$prefix=$type==='receivable'?'ar':'ap';$cut=[(int)$profile[$prefix.'_cutoff_1'],(int)$profile[$prefix.'_cutoff_2'],(int)$profile[$prefix.'_cutoff_3']];
    if(isset($_GET['c1'],$_GET['c2'],$_GET['c3']))$cut=portal_validate_cutoffs([$_GET['c1'],$_GET['c2'],$_GET['c3']]);
    $partyTable=$type==='receivable'?'customers':'vendors';$directory=db()->prepare("SELECT id,name FROM {$partyTable} WHERE company_id=? ORDER BY name,id");$directory->execute([$companyId]);$parties=array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'name'=>(string)$r['name']],$directory->fetchAll());
    if($partyId!==''&&!array_filter($parties,static fn(array $p):bool=>$p['id']===$partyId))fail($type==='receivable'?'Customer not found.':'Vendor not found.',404,'party_not_found');
    $partyClause=$partyId!==''?($type==='receivable'?' AND i.customer_id=?':' AND b.vendor_id=?'):'';
    if($type==='receivable'){$sql="SELECT i.id,i.number,i.issue_date AS document_date,i.due_date,i.balance_cents,c.id AS party_id,c.name AS party_name FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.company_id=? AND i.balance_cents>0 AND i.status IN ('sent','paid') AND i.issue_date<=?{$partyClause}";}else{$sql="SELECT b.id,b.number,b.bill_date AS document_date,b.due_date,b.balance_cents,v.id AS party_id,v.name AS party_name FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id WHERE b.company_id=? AND b.balance_cents>0 AND b.status IN ('open','paid') AND b.bill_date<=?{$partyClause}";}
    $args=[$companyId,$asOf];if($partyId!=='')$args[]=$partyId;$stmt=db()->prepare($sql);$stmt->execute($args);$totals=array_fill(0,5,0);$rows=[];$labels=['Current','1–'.$cut[0],($cut[0]+1).'–'.$cut[1],($cut[1]+1).'–'.$cut[2],($cut[2]+1).'+'];
    foreach($stmt->fetchAll() as $r){$days=max(0,(int)floor((strtotime($asOf)-strtotime((string)$r['due_date']))/86400));$bucket=$days===0?0:($days<=$cut[0]?1:($days<=$cut[1]?2:($days<=$cut[2]?3:4)));$amount=(int)$r['balance_cents'];$totals[$bucket]+=$amount;$rows[]=['id'=>(string)$r['id'],'number'=>(string)$r['number'],'documentDate'=>(string)$r['document_date'],'dueDate'=>(string)$r['due_date'],'partyId'=>(string)$r['party_id'],'party'=>(string)$r['party_name'],'daysOverdue'=>$days,'bucket'=>$bucket,'bucketLabel'=>$labels[$bucket],'balanceCents'=>$amount];}
    if(schema_table_exists('party_opening_balances')){
        $partyType=$type==='receivable'?'customer':'vendor';$openSql="SELECT pob.id,pob.party_id,pob.effective_date,pob.amount_cents,p.name party_name FROM party_opening_balances pob JOIN {$partyTable} p ON p.id=pob.party_id AND p.company_id=pob.company_id WHERE pob.company_id=? AND pob.party_type=? AND pob.amount_cents>0 AND pob.effective_date<=?".($partyId!==''?' AND pob.party_id=?':'');
        $open=db()->prepare($openSql);$openArgs=[$companyId,$partyType,$asOf];if($partyId!=='')$openArgs[]=$partyId;$open->execute($openArgs);
        foreach($open->fetchAll() as $r){$days=max(0,(int)floor((strtotime($asOf)-strtotime((string)$r['effective_date']))/86400));$bucket=$days===0?0:($days<=$cut[0]?1:($days<=$cut[1]?2:($days<=$cut[2]?3:4)));$amount=(int)$r['amount_cents'];$totals[$bucket]+=$amount;$rows[]=['id'=>(string)$r['id'],'number'=>'Opening Balance','documentDate'=>(string)$r['effective_date'],'dueDate'=>(string)$r['effective_date'],'partyId'=>(string)$r['party_id'],'party'=>(string)$r['party_name'],'daysOverdue'=>$days,'bucket'=>$bucket,'bucketLabel'=>$labels[$bucket],'balanceCents'=>$amount,'openingBalance'=>true];}
    }
    usort($rows,static fn(array $a,array $b):int=>strcmp((string)$a['dueDate'],(string)$b['dueDate'])?:strcmp((string)$a['party'],(string)$b['party']));
    json_response(['type'=>$type,'asOf'=>$asOf,'partyId'=>$partyId,'parties'=>$parties,'cutoffs'=>$cut,'labels'=>$labels,'totals'=>$totals,'rows'=>$rows]);
}


/** Presentation-only bank/borrowing classification. Never changes posted journals or nets accounts. */
function portal_bank_presentation_rows(array $rows,array $banks): array
{
    $byLedger=[];$ambiguous=[];foreach($banks as $bank){$ledger=(string)$bank['ledger_account_id'];if(isset($byLedger[$ledger])&&((string)$byLedger[$ledger]['account_type']!==(string)$bank['account_type']||(string)($byLedger[$ledger]['profile_json']??'')!==(string)($bank['profile_json']??'')))$ambiguous[$ledger]=true;$byLedger[$ledger]=$bank;}
    foreach($rows as &$row){$type=(string)$row['type'];$row['presentationSection']=match($type){'asset'=>'Other assets','liability'=>'Other liabilities','equity'=>'Equity',default=>ucfirst($type)};
        $bank=$byLedger[(string)$row['id']]??null;if(!$bank)continue;if(isset($ambiguous[(string)$row['id']])){$row['presentationNotice']='Several bank profiles share this ledger account. Review their presentation before external reporting.';continue;}
        $profile=json_decode((string)($bank['profile_json']??''),true);$profile=is_array($profile)?$profile:[];
        $classification=(string)($profile['reportingClassification']??((string)$bank['account_type']==='bank'?'current_asset':'current_liability'));
        $allowed=['current_asset','non_current_asset','current_liability','non_current_liability'];
        if(!in_array($classification,$allowed,true)){$row['presentationNotice']='Bank classification needs review';continue;}
        $expected=str_ends_with($classification,'liability')?'liability':'asset';
        if($expected!==$type){$row['presentationNotice']='Stored bank profile and posted ledger type differ. Review the account mapping.';continue;}
        // A deposit credit balance is bank indebtedness; a card debit balance is a receivable.
        // Show each separately; a common institution does not permit financial-statement offsetting.
        if((int)$row['amountCents']<0){$row['postedAccountType']=$type;$row['presentationReclassification']=true;$row['amountCents']=-(int)$row['amountCents'];$row['type']=$type==='asset'?'liability':'asset';$classification=$type==='asset'?'current_liability':'current_asset';}
        $row['presentationSection']=match($classification){'current_asset'=>'Current assets','non_current_asset'=>'Non-current assets','current_liability'=>'Current liabilities','non_current_liability'=>'Non-current liabilities'};
        $row['bankAccountId']=(string)$bank['id'];$row['classificationSource']='bank_account_profile';
    }unset($row);
    $order=['Current assets'=>0,'Non-current assets'=>1,'Other assets'=>2,'Current liabilities'=>3,'Non-current liabilities'=>4,'Other liabilities'=>5,'Equity'=>6];
    usort($rows,static fn($a,$b)=>($order[$a['presentationSection']]??9)<=>($order[$b['presentationSection']]??9) ?: strcmp((string)$a['code'],(string)$b['code']));return $rows;
}

function portal_financial_report_data(array $company,string $kind,string $start,string $end): array
{
    $companyId=(string)$company['id'];
    $kind=strtolower(trim($kind));
    if(!in_array($kind,['profit-loss','balance-sheet','trial-balance'],true)) fail('Financial report type is invalid.');
    $start=safe_date($start,'Start date');
    $end=safe_date($end,'End date');
    if($start>$end) fail('Start date must not be after end date.');
    $currency=(string)$company['currency'];
    if($kind==='profit-loss'){
        $stmt=db()->prepare("SELECT a.id,a.code,a.name,a.account_type,a.normal_balance,
            COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) signed_amount
            FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id
            LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id AND je.status='posted'
            WHERE a.company_id=? AND a.account_type IN ('income','expense')
            GROUP BY a.id,a.code,a.name,a.account_type,a.normal_balance ORDER BY FIELD(a.account_type,'income','expense'),a.code");
        $stmt->execute([$start,$end,$companyId]);$rows=[];$income=0;$expenses=0;
        foreach($stmt->fetchAll() as $row){$signed=(int)$row['signed_amount'];$amount=$row['account_type']==='income'?-$signed:$signed;if($amount===0)continue;$rows[]=['id'=>(string)$row['id'],'code'=>(string)$row['code'],'name'=>(string)$row['name'],'type'=>(string)$row['account_type'],'amountCents'=>$amount];if($row['account_type']==='income')$income+=$amount;else$expenses+=$amount;}
        return ['kind'=>$kind,'start'=>$start,'end'=>$end,'currency'=>$currency,'rows'=>$rows,'totals'=>['incomeCents'=>$income,'expenseCents'=>$expenses,'netIncomeCents'=>$income-$expenses]];
    }
    if($kind==='balance-sheet'){
        $stmt=db()->prepare("SELECT a.id,a.code,a.name,a.account_type,a.normal_balance,
            COALESCE(SUM(CASE WHEN je.entry_date<=? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) signed_amount
            FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id
            LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id AND je.status='posted'
            WHERE a.company_id=? GROUP BY a.id,a.code,a.name,a.account_type,a.normal_balance ORDER BY FIELD(a.account_type,'asset','liability','equity','income','expense'),a.code");
        $stmt->execute([$end,$companyId]);$rows=[];$assets=0;$liabilities=0;$equity=0;$currentEarnings=0;
        foreach($stmt->fetchAll() as $row){$signed=(int)$row['signed_amount'];$type=(string)$row['account_type'];if($type==='asset'){$amount=$signed;$assets+=$amount;}elseif($type==='liability'){$amount=-$signed;$liabilities+=$amount;}elseif($type==='equity'){$amount=-$signed;$equity+=$amount;}elseif($type==='income'){$currentEarnings+=-$signed;continue;}elseif($type==='expense'){$currentEarnings-=$signed;continue;}else continue;if($amount===0)continue;$rows[]=['id'=>(string)$row['id'],'code'=>(string)$row['code'],'name'=>(string)$row['name'],'type'=>$type,'amountCents'=>$amount];}
        if($currentEarnings!==0)$rows[]=['id'=>'current-earnings','code'=>'','name'=>'Current earnings through '.$end,'type'=>'equity','amountCents'=>$currentEarnings,'calculated'=>true];
        $bankStmt=db()->prepare('SELECT * FROM bank_accounts WHERE company_id=?');$bankStmt->execute([$companyId]);$rows=portal_bank_presentation_rows($rows,$bankStmt->fetchAll());
        $assets=0;$liabilities=0;$equityTotal=0;foreach($rows as $presentationRow){if($presentationRow['type']==='asset')$assets+=(int)$presentationRow['amountCents'];elseif($presentationRow['type']==='liability')$liabilities+=(int)$presentationRow['amountCents'];elseif($presentationRow['type']==='equity')$equityTotal+=(int)$presentationRow['amountCents'];}
        return ['kind'=>$kind,'start'=>$start,'end'=>$end,'currency'=>$currency,'rows'=>$rows,'totals'=>['assetsCents'=>$assets,'liabilitiesCents'=>$liabilities,'equityCents'=>$equityTotal,'liabilitiesEquityCents'=>$liabilities+$equityTotal,'differenceCents'=>$assets-($liabilities+$equityTotal)]];
    }
    $stmt=db()->prepare("SELECT a.id,a.code,a.name,a.account_type,a.normal_balance,
        COALESCE(SUM(CASE WHEN je.entry_date<=? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) signed_amount
        FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id
        LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id AND je.status='posted'
        WHERE a.company_id=? GROUP BY a.id,a.code,a.name,a.account_type,a.normal_balance ORDER BY a.code");
    $stmt->execute([$end,$companyId]);$rows=[];$debits=0;$credits=0;
    foreach($stmt->fetchAll() as $row){$signed=(int)$row['signed_amount'];if($signed===0)continue;$debit=max(0,$signed);$credit=max(0,-$signed);$debits+=$debit;$credits+=$credit;$rows[]=['id'=>(string)$row['id'],'code'=>(string)$row['code'],'name'=>(string)$row['name'],'type'=>(string)$row['account_type'],'debitCents'=>$debit,'creditCents'=>$credit];}
    return ['kind'=>$kind,'start'=>$start,'end'=>$end,'currency'=>$currency,'rows'=>$rows,'totals'=>['debitCents'=>$debits,'creditCents'=>$credits,'differenceCents'=>$debits-$credits]];
}

function portal_financial_report(array $company): never
{
    require_method('GET');
    $kind=(string)($_GET['kind']??'profit-loss');
    $start=(string)($_GET['start']??date('Y-01-01'));
    $end=(string)($_GET['end']??date('Y-m-d'));
    json_response(portal_financial_report_data($company,$kind,$start,$end));
}

function portal_support(array $user,?array $company): never
{
    $platformRole=schema_column_exists('users','platform_role')?platform_role_for_user((string)$user['id']):'member';
    $scope=(string)($_GET['scope']??'company');
    if(request_method()==='GET'){
        if($scope==='platform'){
            if($platformRole!=='platform_owner')fail('Platform support access is required.',403,'platform_owner_required');
            $stmt=db()->prepare("SELECT sr.*,c.name AS company_name,u.email,u.display_name FROM support_requests sr JOIN companies c ON c.id=sr.company_id JOIN users u ON u.id=sr.requested_by ORDER BY FIELD(sr.status,'pending','in_progress','resolved','closed'),sr.created_at DESC LIMIT 250");$stmt->execute();
        }else{
            if(!$company)fail('Choose a company first.',409,'company_required');$stmt=db()->prepare("SELECT sr.*,c.name AS company_name,u.email,u.display_name FROM support_requests sr JOIN companies c ON c.id=sr.company_id JOIN users u ON u.id=sr.requested_by WHERE sr.company_id=? ORDER BY sr.created_at DESC LIMIT 100");$stmt->execute([$company['id']]);
        }
        json_response(['requests'=>array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'companyId'=>(string)$r['company_id'],'company'=>(string)$r['company_name'],'requestedBy'=>(string)$r['display_name'],'email'=>(string)$r['email'],'subject'=>(string)$r['subject'],'message'=>(string)$r['message'],'status'=>(string)$r['status'],'grantAccess'=>(bool)$r['grant_access'],'accessExpiresAt'=>$r['access_expires_at'],'resolutionNote'=>$r['resolution_note'],'createdAt'=>(string)$r['created_at']],$stmt->fetchAll()),'platformOwner'=>$platformRole==='platform_owner']);
    }
    require_method('POST');require_csrf();$input=request_json();$action=(string)($input['action']??'create');
    if($action==='create'){
        if(!$company)fail('Choose a company first.',409,'company_required');require_company_role($company,'owner','bookkeeper');$subject=clean_text($input['subject']??'','Subject',200);$message=clean_text($input['message']??'','Message',2000);$grant=!empty($input['grantAccess']);$ownerId=portal_owner_id();$expires=$grant?gmdate('Y-m-d H:i:s',time()+7*86400):null;$id=new_id('support');
        db()->prepare("INSERT INTO support_requests (id,company_id,requested_by,subject,message,grant_access,access_expires_at,assigned_to) VALUES (?,?,?,?,?,?,?,?)")->execute([$id,$company['id'],$user['id'],$subject,$message,$grant?1:0,$expires,$ownerId]);
        audit_event($user,(string)$company['id'],'support.request_created','support_request',$id,['grantAccess'=>$grant,'accessExpiresAt'=>$expires]);
        if($ownerId){$q=db()->prepare('SELECT email FROM users WHERE id=?');$q->execute([$ownerId]);$ownerEmail=$q->fetchColumn();if($ownerEmail)sr_mail_send((string)$company['id'],(string)$user['id'],(string)$ownerEmail,'support_request','New Tegh support request: '.$subject,"A support request was submitted for {$company['name']}.\n\n$message\n\nOpen the Tegh Support centre to respond.");}
        json_response(['request'=>['id'=>$id,'status'=>'pending','grantAccess'=>$grant,'accessExpiresAt'=>$expires]],201);
    }
    $id=clean_text($input['id']??'','Support request',64);
    if($platformRole==='platform_owner'&&in_array($action,['start','resolve','close'],true)){
        $status=$action==='start'?'in_progress':($action==='resolve'?'resolved':'closed');$note=optional_text($input['resolutionNote']??null,2000);
        db()->prepare('UPDATE support_requests SET status=?,resolution_note=?,resolved_at=? WHERE id=? AND assigned_to=?')->execute([$status,$note,in_array($status,['resolved','closed'],true)?gmdate('Y-m-d H:i:s'):null,$id,$user['id']]);json_response(['updated'=>true,'status'=>$status]);
    }
    if(!$company)fail('Choose a company first.',409,'company_required');require_company_role($company,'owner');
    if($action==='revoke_access'){db()->prepare("UPDATE support_requests SET grant_access=0,access_expires_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?")->execute([$id,$company['id']]);audit_event($user,(string)$company['id'],'support.access_revoked','support_request',$id,[]);json_response(['revoked'=>true]);}
    fail('Support action is invalid.');
}

function handle_portal(string $action): never
{
    $user=require_user();
    if($action==='support'){
        $company=null;try{$company=require_company($user);}catch(Throwable $e){if((string)($_GET['scope']??'')!=='platform')throw $e;}
        portal_support($user,$company);
    }
    $company=require_company($user);
    if($action==='products')portal_products($user,$company);
    if($action==='day-book')portal_day_book($company);
    if($action==='audit-history')portal_audit_history($company);
    if($action==='trial-balance')portal_trial_balance($company);
    if($action==='gl-account-ledger')portal_gl_account_ledger($company);
    if($action==='financial-report')portal_financial_report($company);
    if($action==='voucher-drafts')portal_voucher_drafts($user,$company);
    if($action==='aging')portal_aging($user,$company);
    fail('Portal route not found.',404,'route_not_found');
}
