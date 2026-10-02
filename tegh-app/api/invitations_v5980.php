<?php
declare(strict_types=1);

/** One invitation, one email, many explicitly authorized company-role assignments. */
function tegh_invitation_company_choices(array $user): array
{
    $platform=platform_role_for_user((string)$user['id'])==='platform_owner';
    if($platform){$s=db()->query("SELECT id,name,'platform_owner' AS inviter_role FROM companies WHERE active=1 ORDER BY name,id");}
    else{$s=db()->prepare("SELECT c.id,c.name,cm.role AS inviter_role FROM companies c JOIN company_members cm ON cm.company_id=c.id WHERE cm.user_id=? AND cm.status='active' AND c.active=1 ORDER BY c.name,c.id");$s->execute([$user['id']]);}
    $rows=[];foreach($s->fetchAll() as $r)if($platform||company_role_can((string)$r['inviter_role'],'users.invite'))$rows[]=['id'=>(string)$r['id'],'name'=>(string)$r['name']];return $rows;
}
function tegh_invitation_operation_key(mixed $key): string
{
    $key=trim((string)$key);if(!preg_match('/^[A-Za-z0-9_-]{16,120}$/D',$key))fail('A stable invitation operation key is required.',422,'invitation_operation_required');return $key;
}
function tegh_invitation_assignments(array $user,array $raw,string $scope,bool $lock=false,?string $email=null): array
{
    $u=db()->prepare('SELECT active,platform_role FROM users WHERE id=?'.($lock?' FOR UPDATE':''));$u->execute([$user['id']]);$actor=$u->fetch();
    if(!$actor||(int)$actor['active']!==1)fail('The invitation is no longer authorized.',403,'invitation_unavailable');$platform=$actor['platform_role']==='platform_owner';
    if($scope==='workspace'){if(!$platform||$raw!==[])fail('Independent workspace invitations require Platform Owner authorization and no company assignments.',403,'invitation_unavailable');return [];}
    if($scope!=='company'||count($raw)<1||count($raw)>50)fail('Choose between one and 50 authorized companies.',422,'invitation_assignments_invalid');
    $seen=[];$rows=[];
    foreach($raw as $item){
        if(!is_array($item))fail('The company assignment is invalid.',422,'invitation_assignments_invalid');
        $id=clean_text($item['companyId']??'','Company',64);$role=(string)($item['role']??'');
        if(isset($seen[$id])||!in_array($role,['admin','editor','viewer'],true))fail('Choose one Company Admin, Editor or Viewer role for each company. Protected roles cannot be assigned.',422,'invitation_role_invalid');
        $seen[$id]=true;$rows[]=['companyId'=>$id,'role'=>$role];
    }
    usort($rows,static fn($a,$b)=>strcmp($a['companyId'],$b['companyId']));
    foreach($rows as &$row){
        $s=db()->prepare('SELECT id,name,active FROM companies WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute([$row['companyId']]);$c=$s->fetch();
        if(!$c||(int)$c['active']!==1)fail('One or more selected companies are unavailable or unauthorized.',403,'invitation_unavailable');
        if(!$platform){$s=db()->prepare("SELECT role,status FROM company_members WHERE company_id=? AND user_id=?".($lock?' FOR UPDATE':''));$s->execute([$row['companyId'],$user['id']]);$m=$s->fetch();if(!$m||$m['status']!=='active'||!company_role_can((string)$m['role'],'users.invite'))fail('One or more selected companies are unavailable or unauthorized.',403,'invitation_unavailable');}
        if($email!==null){$s=db()->prepare('SELECT cm.role,cm.status FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? AND u.email=?'.($lock?' FOR UPDATE':''));$s->execute([$row['companyId'],$email]);$m=$s->fetch();if($m&&($m['role']==='owner'||in_array($m['status'],['active','suspended'],true)))fail('An existing or protected membership prevents this invitation. Use the authorized membership workflow.',409,'invitation_membership_conflict');}
        $row['companyName']=(string)$c['name'];$row['roleLabel']=company_role_label($row['role']);
    }unset($row);return $rows;
}
function tegh_invitation_cipher(string $value,bool $decrypt=false): string
{
    $key=hash('sha256','tegh.invitation.outbox.v1|'.(string)config('app.secret'),true);
    if(!$decrypt){$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'Tegh invitation token',16);if($cipher===false)throw new RuntimeException('Invitation encryption unavailable.');return base64_encode($iv.$tag.$cipher);}
    $raw=base64_decode($value,true);if($raw===false||strlen($raw)<29)throw new RuntimeException('Invalid encrypted invitation record.');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'Tegh invitation token');if($plain===false)throw new RuntimeException('Invitation outbox could not be decrypted.');return $plain;
}
function tegh_invitation_queue(string $parentId,string $operationKey): string
{
    // Called inside the parent transaction. Existing usable tokens remain valid until a replacement is sent.
    $token=base64url_encode(random_bytes(32));$tokenId=new_id('invtoken');$mailId=new_id('invmail');$expires=gmdate('Y-m-d H:i:s',time()+72*3600);
    db()->prepare("INSERT INTO account_invitation_tokens (id,invitation_id,token_hash,expires_at) VALUES (?,?,?,?)")->execute([$tokenId,$parentId,secret_hash($token),$expires]);
    db()->prepare('INSERT INTO account_invitation_mail (id,invitation_id,token_id,token_cipher,operation_key) VALUES (?,?,?,?,?)')->execute([$mailId,$parentId,$tokenId,tegh_invitation_cipher($token),$operationKey]);
    db()->prepare('UPDATE account_invitations SET expires_at=? WHERE id=?')->execute([$expires,$parentId]);return $mailId;
}
function tegh_invitation_mail_payload(array $row): array
{
    $labels=['sent'=>'Sent','sent_warning'=>'Sent with warning','deferred'=>'Deferred','manual_review'=>'Manual review','failed'=>'Failed','sending'=>'Sending email'];
    $recorded=(string)($row['status']??'deferred');$status=isset($labels[$recorded])?$recorded:'manual_review';
    $stored=json_decode((string)($row['diagnostic_json']??'{}'),true);$diagnostic=[];
    // Return only documented, sanitized transport fields; never relay an arbitrary stored object.
    foreach(['stage','smtpCode','reference','message','nextAction'] as $key){
        $value=is_array($stored)?($stored[$key]??null):null;
        if(is_scalar($value))$diagnostic[$key]=portal_mail_sanitize_diagnostic(substr((string)$value,0,1200));
    }
    $updated=strtotime((string)($row['updated_at']??'').' UTC');
    if($status==='sending'&&($updated===false||$updated<time()-900)){
        // A process may have died after SMTP acceptance. Do not turn uncertainty into a resend.
        $status='manual_review';$diagnostic['stage']='outcome_unknown';
        $diagnostic['message']='The delivery attempt did not return a final receipt. Email may already have been sent.';
        $diagnostic['nextAction']='Review Email Delivery Health and the controlled inbox before issuing another link.';
    }
    return ['deliveryStatus'=>$status,'deliveryLabel'=>$labels[$status],'recordedDeliveryStatus'=>$recorded,'diagnostic'=>$diagnostic,'message'=>$diagnostic['message']??($status==='deferred'?'Email is durably queued. Use Send queued email to attempt delivery.':($status==='sending'?'Delivery is in progress. Check the receipt before resending.':'Review the recorded delivery result.')),'mailReference'=>$row['id']??null];
}
function tegh_invitation_send(array $user,string $mailId): array
{
    $row=tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$mailId):array{
        $s=db()->prepare('SELECT m.*,i.email,i.scope,i.invited_by,i.status invitation_status FROM account_invitation_mail m JOIN account_invitations i ON i.id=m.invitation_id WHERE m.id=? FOR UPDATE');$s->execute([$mailId]);$r=$s->fetch();
        if(!$r||($r['invited_by']!==$user['id']&&platform_role_for_user((string)$user['id'])!=='platform_owner'))fail('Invitation delivery is unavailable.',403,'invitation_unavailable');
        if($r['invitation_status']!=='pending')fail('This invitation is no longer pending.',409,'invitation_unavailable');
        if($r['status']!=='deferred')return $r;
        db()->prepare("UPDATE account_invitation_mail SET status='sending',attempt_count=attempt_count+1,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$mailId]);$r['claimed']=true;return $r;
    }));
    if(empty($row['claimed']))return tegh_invitation_mail_payload($row);
    try{
        $base=rtrim((string)config('app.base_url'),'/');$url=parse_url($base);
        $encryption=strtolower((string)(config('mail.smtp_encryption')??'tls'));
        if(($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment']))throw new TeghServiceFailure('Configure a valid HTTPS app.base_url before sending invitations.',503,'invitation_base_url_invalid');
        if(!in_array($encryption,['tls','ssl'],true)||trim((string)config('mail.smtp_host'))===''||trim((string)config('mail.smtp_username'))===''||trim((string)config('mail.smtp_password'))==='')throw new TeghServiceFailure('Configure authenticated SMTP with TLS or STARTTLS before sending invitations.',503,'invitation_smtp_configuration');
        $s=db()->prepare('SELECT a.company_id,a.role,c.name FROM account_invitation_companies a LEFT JOIN companies c ON c.id=a.company_id WHERE a.invitation_id=? ORDER BY a.company_id');$s->execute([$row['invitation_id']]);$assignments=$s->fetchAll();
        $actor=['id'=>$row['invited_by']];tegh_service_boundary(fn()=>tegh_invitation_assignments($actor,array_map(static fn($r)=>['companyId'=>$r['company_id'],'role'=>$r['role']],$assignments),(string)$row['scope']));
        $summary=$row['scope']==='workspace'?'an independent Tegh workspace':implode('; ',array_map(static fn($r)=>$r['name'].' ('.company_role_label($r['role']).')',$assignments));
        $link=$base.'/app.html#accountSetup='.rawurlencode(tegh_invitation_cipher($row['token_cipher'],true));
        $text="You have been invited to $summary.\n\nOpen this single-use password-setup link:\n$link\n\nThe link expires in 72 hours. An existing Tegh account must confirm its current password; an invitation never replaces an existing password. If you did not expect this invitation, ignore this email.";
        $html='<p>'.htmlspecialchars("You have been invited to $summary.",ENT_QUOTES,'UTF-8').'</p><p><a href="'.htmlspecialchars($link,ENT_QUOTES,'UTF-8').'">Set Up My Password</a></p><p>The link is single-use and expires in 72 hours. Existing users must confirm their current password.</p>';
        $mail=sr_mail_send(null,(string)$user['id'],(string)$row['email'],'account_invitation_5980','Set up your Tegh account',$text,$html,[],['operationKey'=>hash('sha256','invitation-mail|'.$mailId)]);
        $status=in_array(($mail['status']??''),['sent','sent_warning','deferred','manual_review','failed'],true)?$mail['status']:'manual_review';
        $diagnostic=['stage'=>(string)($mail['stage']??'unknown'),'smtpCode'=>$mail['smtpCode']??null,'reference'=>request_id(),'message'=>portal_mail_sanitize_diagnostic((string)($mail['message']??'Review Email Delivery Health.')),'nextAction'=>in_array($status,['sent','sent_warning'],true)?'Recipient can use the new link.':'Check Email Delivery Health. A previously sent link remains valid until a replacement is confirmed sent.'];
    }catch(Throwable $error){
        $status=$error instanceof TeghServiceFailure?'failed':'manual_review';$mail=['id'=>null];$diagnostic=['stage'=>$error instanceof TeghServiceFailure?'configuration':'outcome_unknown','smtpCode'=>null,'reference'=>request_id(),'message'=>$error instanceof TeghServiceFailure?$error->getMessage():'Delivery outcome could not be verified. Review the email receipt before resending.','nextAction'=>'Review Email Delivery Health. Do not repeatedly resend an unknown outcome.'];
    }
    db_transaction_retry(function()use($mailId,$row,$status,$mail,$diagnostic):void{
        db()->prepare('UPDATE account_invitation_mail SET status=?,outbound_email_id=?,diagnostic_json=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$status,$mail['id']??null,tegh_json_canonical($diagnostic),$mailId]);
        if(in_array($status,['sent','sent_warning'],true))db()->prepare("UPDATE account_invitation_tokens SET status='revoked' WHERE invitation_id=? AND id<>? AND status='active'")->execute([$row['invitation_id'],$row['token_id']]);
    });
    return tegh_invitation_mail_payload(['id'=>$mailId,'status'=>$status,'diagnostic_json'=>tegh_json_canonical($diagnostic)]);
}
function tegh_invitation_parent_for_admin(array $user,string $id,bool $lock=false): array
{
    $s=db()->prepare('SELECT * FROM account_invitations WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute([$id]);$row=$s->fetch();
    if(!$row||($row['invited_by']!==$user['id']&&platform_role_for_user((string)$user['id'])!=='platform_owner'))fail('The invitation is unavailable.',403,'invitation_unavailable');
    $s=db()->prepare('SELECT company_id,role FROM account_invitation_companies WHERE invitation_id=? ORDER BY company_id');$s->execute([$id]);$rows=$s->fetchAll();
    if(count($rows)!==(int)$row['assignment_count'])fail('An invited company is no longer available.',409,'invitation_unavailable');
    tegh_invitation_assignments($user,array_map(static fn($r)=>['companyId'=>$r['company_id'],'role'=>$r['role']],$rows),(string)$row['scope'],$lock);return $row;
}
function tegh_handle_invitations(): never
{
    require_method('GET','POST');$user=require_user();tegh_schema44_require();header('Cache-Control: no-store');
    if(request_method()==='GET'){
        $platform=platform_role_for_user((string)$user['id'])==='platform_owner';$s=db()->prepare('SELECT i.*,u.display_name FROM account_invitations i JOIN users u ON u.id=i.invited_by'.($platform?'':' WHERE i.invited_by=?').' ORDER BY i.created_at DESC,i.id DESC LIMIT 100');$s->execute($platform?[]:[$user['id']]);$rows=[];
        foreach($s->fetchAll() as $r){
            if(!$platform){try{tegh_service_boundary(fn()=>tegh_invitation_parent_for_admin($user,(string)$r['id']));}catch(TeghServiceFailure $error){if(in_array($error->httpStatus,[403,404,409],true))continue;throw $error;}}
            $m=db()->prepare('SELECT * FROM account_invitation_mail WHERE invitation_id=? ORDER BY created_at DESC,id DESC LIMIT 1');$m->execute([$r['id']]);$delivery=tegh_invitation_mail_payload($m->fetch()?:['status'=>'manual_review']);$a=db()->prepare('SELECT a.company_id,a.role,c.name company_name FROM account_invitation_companies a LEFT JOIN companies c ON c.id=a.company_id WHERE a.invitation_id=? ORDER BY a.company_id');$a->execute([$r['id']]);$assignments=$a->fetchAll();$rows[]=['id'=>$r['id'],'email'=>$r['email'],'scope'=>$r['scope'],'role'=>$assignments[0]['role']??'viewer','status'=>$r['status'],'createdAt'=>$r['created_at'],'expiresAt'=>$r['expires_at'],'acceptedAt'=>$r['accepted_at'],'invitedBy'=>$r['display_name'],'assignments'=>$assignments]+$delivery;}
        json_response(['invitations'=>$rows,'companies'=>tegh_invitation_company_choices($user),'independentWorkspaceAllowed'=>$platform]);
    }
    require_csrf();$input=request_json();$action=(string)($input['action']??'create_account');
    try{
        if($action==='send'){$id=clean_text($input['id']??'','Invitation',64);tegh_invitation_parent_for_admin($user,$id);$s=db()->prepare("SELECT id FROM account_invitation_mail WHERE invitation_id=? ORDER BY created_at DESC,id DESC LIMIT 1");$s->execute([$id]);$mailId=$s->fetchColumn();if(!$mailId)fail('No queued delivery was found.',409,'invitation_mail_unavailable');json_response(tegh_invitation_send($user,(string)$mailId));}
        if($action==='revoke'){
            tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$input):void{$id=clean_text($input['id']??'','Invitation',64);$r=tegh_invitation_parent_for_admin($user,$id,true);if($r['status']!=='pending')fail('This invitation is no longer pending.',409,'invitation_unavailable');db()->prepare("UPDATE account_invitations SET status='revoked' WHERE id=?")->execute([$id]);db()->prepare("UPDATE account_invitation_tokens SET status='revoked' WHERE invitation_id=? AND status='active'")->execute([$id]);platform_audit_event($user,'platform.invitation_revoked','invitation',$id,[]);}));json_response(['revoked'=>true]);
        }
        $key=tegh_invitation_operation_key($input['operationKey']??'');
        if($action==='resend'){
            $result=tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$input,$key):array{
                $id=clean_text($input['id']??'','Invitation',64);$r=tegh_invitation_parent_for_admin($user,$id,true);if($r['status']!=='pending')fail('This invitation is no longer pending.',409,'invitation_unavailable');
                $s=db()->prepare('SELECT id FROM account_invitation_mail WHERE invitation_id=? AND operation_key=?');$s->execute([$id,$key]);if($old=$s->fetchColumn())return ['id'=>$id,'mailId'=>(string)$old,'idempotentReplay'=>true];
                $s=db()->prepare('SELECT COUNT(*) FROM account_invitation_mail WHERE invitation_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR');$s->execute([$id]);if((int)$s->fetchColumn()>=3)fail('Resend is limited to three emails per hour for this invitation.',429,'invitation_resend_limited');
                $mail=tegh_invitation_queue($id,$key);platform_audit_event($user,'platform.invitation_resent','invitation',$id,['previousLinkValidUntilReplacementSent'=>true]);return ['id'=>$id,'mailId'=>$mail,'idempotentReplay'=>false];
            }));
        }else{
            if(!in_array($action,['create','create_account'],true))fail('Unsupported invitation action.',422,'invitation_action_invalid');
            $email=safe_email($input['email']??'');$scope=(string)($input['scope']??'company');$raw=$input['assignments']??[];
            if(!is_array($raw))fail('Company assignments are invalid.',422,'invitation_assignments_invalid');
            if($raw===[]&&$scope==='company'){$cid=trim((string)($_SERVER['HTTP_X_COMPANY_ID']??$_GET['companyId']??''));$raw=[['companyId'=>$cid,'role'=>$input['role']??'']];}
            $assignments=tegh_invitation_assignments($user,$raw,$scope,false,null);$canonical=array_map(static fn($r)=>['companyId'=>$r['companyId'],'role'=>$r['role']],$assignments);$hash=hash('sha256',tegh_json_canonical([$email,$scope,$canonical]));
            $result=tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$email,$scope,$canonical,$hash,$key):array{
                $actorLock=db()->prepare('SELECT active FROM users WHERE id=? FOR UPDATE');$actorLock->execute([$user['id']]);if((int)$actorLock->fetchColumn()!==1)fail('Invitation creation is unavailable.',403,'invitation_unavailable');
                $s=db()->prepare('SELECT id,payload_hash FROM account_invitations WHERE invited_by=? AND operation_key=? FOR UPDATE');$s->execute([$user['id'],$key]);if($old=$s->fetch()){if(!hash_equals($old['payload_hash'],$hash))fail('This operation key was used for another invitation.',409,'invitation_operation_conflict');$m=db()->prepare('SELECT id FROM account_invitation_mail WHERE invitation_id=? AND operation_key=?');$m->execute([$old['id'],$key]);return ['id'=>$old['id'],'mailId'=>$m->fetchColumn(),'idempotentReplay'=>true];}
                $count=db()->prepare('SELECT COUNT(*) FROM account_invitations WHERE invited_by=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR');$count->execute([$user['id']]);if((int)$count->fetchColumn()>=30)fail('Invitation creation is rate limited. Try later.',429,'invitation_rate_limited');
                $assignments=tegh_invitation_assignments($user,$canonical,$scope,true,$email);$id=new_id('invitation');
                db()->prepare('INSERT INTO account_invitations (id,email,scope,assignment_count,invited_by,operation_key,payload_hash,expires_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$email,$scope,count($assignments),$user['id'],$key,$hash,gmdate('Y-m-d H:i:s',time()+72*3600)]);
                foreach($assignments as $a)db()->prepare('INSERT INTO account_invitation_companies (invitation_id,company_id,role) VALUES (?,?,?)')->execute([$id,$a['companyId'],$a['role']]);
                $mailId=tegh_invitation_queue($id,$key);platform_audit_event($user,'platform.invitation_created','invitation',$id,['scope'=>$scope,'assignments'=>$canonical,'recipientHash'=>secret_hash($email)]);return ['id'=>$id,'mailId'=>$mailId,'idempotentReplay'=>false];
            }));
        }
        if(!empty($result['idempotentReplay'])){$saved=db()->prepare('SELECT * FROM account_invitation_mail WHERE id=?');$saved->execute([$result['mailId']]);$delivery=tegh_invitation_mail_payload($saved->fetch()?:[]);}else{$delivery=tegh_invitation_send($user,(string)$result['mailId']);}json_response(['accountSetup'=>$result+$delivery,'idempotentReplay'=>$result['idempotentReplay']]);
    }catch(Throwable $error){tegh_fail_service($error);}
}
function tegh_invitation_validate_token(string $token,bool $lock=false): array
{
    if(!preg_match('/^[A-Za-z0-9_-]{32,180}$/D',$token))fail('This invitation is unavailable or expired.',410,'invitation_unavailable');
    $s=db()->prepare("SELECT i.*,t.id token_id,t.expires_at token_expires FROM account_invitation_tokens t JOIN account_invitations i ON i.id=t.invitation_id WHERE t.token_hash=? AND t.status='active' AND i.status='pending' AND t.expires_at>UTC_TIMESTAMP()".($lock?' FOR UPDATE':''));$s->execute([secret_hash($token)]);$invite=$s->fetch();
    if(!$invite)fail('This invitation is unavailable or expired.',410,'invitation_unavailable');
    $a=db()->prepare('SELECT company_id,role FROM account_invitation_companies WHERE invitation_id=? ORDER BY company_id');$a->execute([$invite['id']]);$raw=$a->fetchAll();
    if(count($raw)!==(int)$invite['assignment_count'])fail('This invitation is unavailable or expired.',410,'invitation_unavailable');
    $invite['assignments']=tegh_invitation_assignments(['id'=>$invite['invited_by']],array_map(static fn($r)=>['companyId'=>$r['company_id'],'role'=>$r['role']],$raw),(string)$invite['scope'],$lock);
    return $invite;
}
function tegh_invitation_accept_rate_limit(string $token): void
{
    $tokenHash=secret_hash($token);$ip=client_ip_hash();$s=db()->prepare('SELECT COUNT(*) FROM invitation_attempts WHERE created_at>UTC_TIMESTAMP()-INTERVAL 15 MINUTE AND (token_hash=? OR ip_hash=?)');$s->execute([$tokenHash,$ip]);if((int)$s->fetchColumn()>=10)fail('Too many invitation attempts. Try again later.',429,'invitation_rate_limited');
    db()->prepare('INSERT INTO invitation_attempts (token_hash,ip_hash) VALUES (?,?)')->execute([$tokenHash,$ip]);
}
function tegh_handle_invite_details(): never
{
    require_method('GET','POST');tegh_schema44_require();if(request_method()==='POST')assert_same_origin();$token=trim((string)(request_method()==='POST'?(request_json()['token']??''):($_GET['token']??'')));
    try{$invite=tegh_service_boundary(fn()=>tegh_invitation_validate_token($token));}catch(Throwable $error){if($error instanceof TeghServiceFailure)fail('This invitation is unavailable or expired.',410,'invitation_unavailable');throw $error;}
    $s=db()->prepare('SELECT id,active,deleted_at FROM users WHERE email=?');$s->execute([$invite['email']]);$u=$s->fetch();if($u&&((int)$u['active']!==1||$u['deleted_at']!==null))fail('This invitation is unavailable or expired.',410,'invitation_unavailable');
    header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');json_response(['invitation'=>['email'=>$invite['email'],'scope'=>$invite['scope'],'existingUser'=>(bool)$u,'suggestedName'=>$u?'':platform_default_display_name((string)$invite['email']),'assignments'=>$invite['assignments'],'termsVersion'=>TEGH_TERMS_VERSION,'privacyVersion'=>TEGH_PRIVACY_VERSION]]);
}
function tegh_handle_invite_accept(): never
{
    require_method('POST');assert_same_origin();tegh_schema44_require();$input=request_json();$token=trim((string)($input['token']??''));tegh_invitation_accept_rate_limit($token);
    if(empty($input['acceptTerms'])||($input['termsVersion']??'')!==TEGH_TERMS_VERSION||($input['privacyVersion']??'')!==TEGH_PRIVACY_VERSION)fail('Read and accept the current Terms and Privacy Notice.',422,'terms_required');
    try{$user=tegh_service_boundary(fn()=>db_transaction_retry(function()use($input,$token):array{
        $invite=tegh_invitation_validate_token($token,true);$password=(string)($input['password']??'');
        $s=db()->prepare('SELECT * FROM users WHERE email=? FOR UPDATE');$s->execute([$invite['email']]);$member=$s->fetch();
        if($member){
            if((int)$member['active']!==1||$member['deleted_at']!==null||!password_verify($password,(string)$member['password_hash']))fail('This invitation could not be accepted. Confirm your current sign-in password.',403,'invitation_confirmation_failed');
            $id=(string)$member['id'];$name=(string)$member['display_name'];
        }else{
            if(!hash_equals($password,(string)($input['confirmPassword']??'')))fail('The passwords do not match.',422,'password_mismatch');validate_new_password($password);
            $hash=password_hash($password,password_algorithm());if(!is_string($hash))throw new RuntimeException('Password hashing unavailable.');$id=new_id('user');
            // R148: the name the person typed on the Create account tab; the email-derived name only when it is left empty.
            $typed=trim(preg_replace('/\s+/u',' ',(string)($input['displayName']??''))??'');if(mb_strlen($typed)>160)fail('Your name can be at most 160 characters.',422,'display_name_too_long');if($typed!==''&&preg_match('/[\x00-\x1F\x7F<>]/u',$typed))fail('Enter your name without special control characters or < >.',422,'display_name_invalid');
            $name=$typed!==''?$typed:platform_default_display_name((string)$invite['email']);
            db()->prepare("INSERT INTO users (id,email,password_hash,display_name,platform_role,account_plan,signup_source,terms_accepted_at) VALUES (?,?,?,?,'member','free_preview','invitation',UTC_TIMESTAMP())")->execute([$id,$invite['email'],$hash,$name]);
        }
        foreach($invite['assignments'] as $assignment){
            $s=db()->prepare('SELECT role,status FROM company_members WHERE company_id=? AND user_id=? FOR UPDATE');$s->execute([$assignment['companyId'],$id]);$old=$s->fetch();
            if($old&&($old['role']==='owner'||in_array($old['status'],['active','suspended'],true)))fail('Membership changed after this invitation was sent. Ask the administrator to review it.',409,'invitation_membership_conflict');
            db()->prepare("INSERT INTO company_members (company_id,user_id,role,status,updated_by) VALUES (?,?,?,'active',?) ON DUPLICATE KEY UPDATE role=VALUES(role),status='active',status_reason=NULL,suspended_at=NULL,revoked_at=NULL,updated_by=VALUES(updated_by)")->execute([$assignment['companyId'],$id,$assignment['role'],$invite['invited_by']]);
        }
        tegh_record_terms_acceptance($id,(string)$invite['id']);db()->prepare("UPDATE account_invitations SET status='accepted',accepted_by=?,accepted_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'")->execute([$id,$invite['id']]);db()->prepare("UPDATE account_invitation_tokens SET status='consumed' WHERE invitation_id=? AND status='active'")->execute([$invite['id']]);
        $actor=['id'=>$id,'email'=>$invite['email'],'displayName'=>$name];platform_audit_event($actor,'platform.invitation_accepted','invitation',(string)$invite['id'],['companyCount'=>count($invite['assignments']),'existingAccount'=>(bool)$member,'termsVersion'=>TEGH_TERMS_VERSION,'privacyVersion'=>TEGH_PRIVACY_VERSION]);return $actor;
    }));}catch(Throwable $error){tegh_fail_service($error);}
    $session=create_session((string)$user['id']);json_response(['accepted'=>true,'auth'=>auth_payload($user,$session)]);
}
function tegh_handle_email_health(): never
{
    require_method('GET');$user=require_user();admin_require_platform_owner($user);tegh_schema44_require();
    $base=parse_url((string)config('app.base_url'));$from=trim((string)config('mail.from_email'));$reply=trim((string)(config('mail.reply_to')??$from));$host=trim((string)config('mail.smtp_host'));$tls=strtolower((string)(config('mail.smtp_encryption')??''));$domain=strtolower((string)substr(strrchr($from,'@')?:'@',1));
    $spf=$domain!==''&&count(array_filter(platform_dns_txt($domain),static fn($x)=>str_starts_with(strtolower($x),'v=spf1')))===1;$dmarc=$domain!==''&&count(array_filter(platform_dns_txt('_dmarc.'.$domain),static fn($x)=>str_starts_with(strtolower($x),'v=dmarc1')))===1;
    $rows=db()->query('SELECT id,invitation_id,status,diagnostic_json,attempt_count,created_at,updated_at FROM account_invitation_mail ORDER BY created_at DESC,id DESC LIMIT 100')->fetchAll();
    json_response(['httpsBaseUrl'=>($base['scheme']??'')==='https','baseHost'=>$base['host']??null,'authenticatedTlsConfigured'=>$host!==''&&in_array($tls,['ssl','tls'],true)&&trim((string)config('mail.smtp_username'))!==''&&trim((string)config('mail.smtp_password'))!=='','senderDomain'=>$domain,'replyToDomainAligned'=>$domain!==''&&strtolower((string)substr(strrchr($reply,'@')?:'@',1))===$domain,'dns'=>['spfObserved'=>$spf,'dkimObserved'=>platform_ionos_dkim_ready($domain),'dmarcObserved'=>$dmarc,'qualification'=>'DNS presence is not proof of delivery or alignment. Verify an actual controlled-inbox message and Authentication-Results headers.'],'deliveries'=>array_map(static fn($r)=>tegh_invitation_mail_payload($r)+['invitationId'=>$r['invitation_id'],'attempts'=>(int)$r['attempt_count'],'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at']],$rows)]);
}
