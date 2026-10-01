<?php
declare(strict_types=1);
require_once __DIR__ . '/invitations_v5980.php';

/** Tegh 2.1 platform services: notifications, invitations, vouchers, ledgers, integrity and rate monitoring. */

function platform_notification_create(string $companyId, ?string $userId, string $type, string $title, string $message, ?string $route = null, string $severity = 'info', ?string $uniqueKey = null, ?string $actionLabel = null): string
{
    if (!schema_table_exists('notifications')) return '';
    if (!in_array($severity, ['info','success','warning','critical'], true)) $severity = 'info';
    if ($uniqueKey !== null && $uniqueKey !== '') {
        $stmt = db()->prepare("SELECT id FROM notifications WHERE company_id=? AND COALESCE(user_id,'')=COALESCE(?,'') AND unique_key=? AND dismissed_at IS NULL LIMIT 1");
        $stmt->execute([$companyId,$userId,$uniqueKey]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            db()->prepare('UPDATE notifications SET title=?,message=?,route=?,severity=?,action_label=?,read_at=NULL,created_at=CURRENT_TIMESTAMP WHERE id=?')
                ->execute([$title,$message,$route,$severity,$actionLabel,(string)$existing]);
            return (string)$existing;
        }
    }
    $id = new_id('notice');
    db()->prepare('INSERT INTO notifications (id,company_id,user_id,type,title,message,route,severity,action_label,unique_key) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$companyId,$userId,$type,$title,$message,$route,$severity,$actionLabel,$uniqueKey]);
    return $id;
}

function platform_notification_rows(array $user, array $company): array
{
    $companyId=(string)$company['id'];
    $rows=[];
    if (schema_table_exists('notifications')) {
        $stmt=db()->prepare("SELECT id,type,title,message,route,severity,action_label,read_at,created_at FROM notifications
            WHERE company_id=? AND (user_id IS NULL OR user_id=?) AND dismissed_at IS NULL ORDER BY read_at IS NULL DESC,created_at DESC LIMIT 30");
        $stmt->execute([$companyId,$user['id']]);
        foreach($stmt->fetchAll() as $r)$rows[]=[
            'id'=>(string)$r['id'],'type'=>(string)$r['type'],'title'=>(string)$r['title'],'message'=>(string)$r['message'],
            'route'=>$r['route'],'severity'=>(string)$r['severity'],'actionLabel'=>$r['action_label'],'read'=>(bool)$r['read_at'],'createdAt'=>(string)$r['created_at'],
        ];
    }
    // Computed pending actions remain current without creating duplicate database rows.
    $computed=[];
    $q=db()->prepare("SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND status='pending'");$q->execute([$companyId]);$pending=(int)$q->fetchColumn();
    if($pending>0)$computed[]=['id'=>'computed-bank','type'=>'pending_bank','title'=>$pending.' bank transaction'.($pending===1?'':'s').' need review','message'=>'Assign a GL code or match the transaction, then post it.','route'=>'nav:transaction-review','severity'=>'warning','actionLabel'=>'Review bank transactions','read'=>false,'createdAt'=>gmdate('c')];
    $q=db()->prepare("SELECT COUNT(*) FROM invoices WHERE company_id=? AND status='sent' AND balance_cents>0 AND due_date<CURRENT_DATE");$q->execute([$companyId]);$overdue=(int)$q->fetchColumn();
    if($overdue>0)$computed[]=['id'=>'computed-ar','type'=>'overdue_ar','title'=>$overdue.' overdue customer invoice'.($overdue===1?'':'s'),'message'=>'Review the customer ledger and collection status.','route'=>'nav:customer','severity'=>'warning','actionLabel'=>'Open receivables','read'=>false,'createdAt'=>gmdate('c')];
    $q=db()->prepare("SELECT COUNT(*) FROM bills WHERE company_id=? AND status='open' AND balance_cents>0 AND due_date<CURRENT_DATE");$q->execute([$companyId]);$overdueBills=(int)$q->fetchColumn();
    if($overdueBills>0)$computed[]=['id'=>'computed-ap','type'=>'overdue_ap','title'=>$overdueBills.' overdue vendor invoice'.($overdueBills===1?'':'s'),'message'=>'Review the vendor ledger and payment status.','route'=>'nav:payables','severity'=>'warning','actionLabel'=>'Open payables','read'=>false,'createdAt'=>gmdate('c')];
    if(!empty($company['test_mode']) && !empty($company['test_expires_at'])){
        $seconds=strtotime((string)$company['test_expires_at'].' UTC')-time();
        if($seconds<86400)$computed[]=['id'=>'computed-test-expiry','type'=>'test_expiry','title'=>'Test company expires soon','message'=>'This test company will be removed automatically after its expiry time.','route'=>'action:test-mode','severity'=>'info','actionLabel'=>'View Test Mode','read'=>false,'createdAt'=>gmdate('c')];
    }
    return array_merge($computed,$rows);
}

function platform_notifications(array $user,array $company): never
{
    if(request_method()==='GET'){
        $rows=platform_notification_rows($user,$company);
        json_response(['notifications'=>$rows,'unreadCount'=>count(array_filter($rows,static fn(array $r):bool=>empty($r['read'])))]);
    }
    require_method('POST');require_csrf();$input=request_json();$action=(string)($input['action']??'read');
    // Clearing removes every stored notice this person can see. Live to-do items
    // (bank lines awaiting review, overdue invoices and bills) are recalculated
    // from the books on every request, so they are reported back separately
    // rather than being silently "cleared" while the work is still outstanding.
    if($action==='clear'){
        $cleared=0;
        if(schema_table_exists('notifications')){
            $stmt=db()->prepare("UPDATE notifications SET dismissed_at=UTC_TIMESTAMP(),read_at=COALESCE(read_at,UTC_TIMESTAMP())
                WHERE company_id=? AND (user_id IS NULL OR user_id=?) AND dismissed_at IS NULL");
            $stmt->execute([$company['id'],$user['id']]);
            $cleared=$stmt->rowCount();
        }
        $remaining=array_values(array_filter(platform_notification_rows($user,$company),static fn(array $r):bool=>str_starts_with((string)$r['id'],'computed-')));
        audit_event($user,(string)$company['id'],'platform.notifications_cleared','notification','all',['clearedCount'=>$cleared]);
        json_response(['ok'=>true,'clearedCount'=>$cleared,'remainingCount'=>count($remaining),'remaining'=>$remaining]);
    }
    $id=clean_text($input['id']??'','Notification',64);
    if(str_starts_with($id,'computed-'))json_response(['ok'=>true]);
    $field=$action==='dismiss'?'dismissed_at':'read_at';
    db()->prepare("UPDATE notifications SET `$field`=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND (user_id IS NULL OR user_id=?)")
        ->execute([$id,$company['id'],$user['id']]);
    json_response(['ok'=>true]);
}

function platform_invitation_base_url(string $token): string
{
    return rtrim((string)config('app.base_url'),'/').'/app.html#accountSetup='.rawurlencode($token);
}

function platform_default_display_name(string $email): string
{
    $local=explode('@',$email,2)[0]??'';
    $candidate=trim((string)(preg_replace('/[._+\-]+/',' ',$local)??''));
    if($candidate==='')return 'Tegh User';
    return substr(ucwords(strtolower($candidate)),0,120);
}

function platform_send_invitation_email(string $email,string $companyName,string $role,string $url,string $scope='workspace',?string $companyId=null,?string $createdBy=null): array
{
    $roleLabel=['owner'=>'Company Owner','admin'=>'Company Admin','editor'=>'Editor','viewer'=>'Viewer'][$role]??ucfirst($role);
    $workspace=$scope!=='company';
    $subject='Set up your Tegh account';
    $intro=$workspace
        ? 'A Tegh account has been prepared for this email address. Your companies and client records will remain separate from the sender.'
        : "A Tegh account has been prepared for this email address with $roleLabel access to $companyName.";
    $instructions='Open Tegh and enter a password to finish setting up the account. If this email already has a Tegh account, enter its current password to confirm the new access.';
    $text="$intro\n\nSet up your password:\n$url\n\n$instructions\n\nThe link expires in 72 hours and works once. If you were not expecting this email, you can ignore it.";
    $html='<!doctype html><html><body style="margin:0;background:#f2f6f7;color:#18333b"><div style="display:none;max-height:0;overflow:hidden">'.$intro.'</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f6f7"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #d9e5e7;border-radius:16px"><tr><td style="padding:32px;font-family:Arial,sans-serif"><div style="font-size:13px;font-weight:700;letter-spacing:.08em;color:#0d6670">TEGH</div><h1 style="font-size:26px;line-height:1.25;margin:14px 0;color:#173740">Set up your Tegh account</h1><p style="font-size:16px;line-height:1.6;color:#455e65">'.htmlspecialchars($intro,ENT_QUOTES,'UTF-8').'</p><p style="margin:28px 0"><a style="display:inline-block;padding:13px 20px;background:#0d6670;color:#ffffff;text-decoration:none;border-radius:9px;font-weight:700" href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'">Set Up My Password</a></p><p style="font-size:14px;line-height:1.55;color:#5f747a">'.htmlspecialchars($instructions,ENT_QUOTES,'UTF-8').'</p><p style="font-size:14px;line-height:1.55;color:#5f747a">The link expires in 72 hours and works once.</p><hr style="border:0;border-top:1px solid #e2ebed;margin:26px 0"><p style="font-size:12px;line-height:1.5;color:#768a90">If you were not expecting this account setup email, you can safely ignore it.</p></td></tr></table></td></tr></table></body></html>';
    return sr_mail_send($companyId,$createdBy,$email,$workspace?'workspace_account_setup':'company_account_setup',$subject,$text,$html);
}

function platform_dns_txt(string $name): array
{
    if (!function_exists('dns_get_record')) return [];
    try {
        $rows=@dns_get_record($name,DNS_TXT);
        if(!is_array($rows))return [];
        return array_values(array_filter(array_map(static fn(array $row):string=>(string)($row['txt']??''),$rows)));
    } catch(Throwable) {
        return [];
    }
}
function platform_ionos_dkim_ready(string $domain): bool
{
    if ($domain==='' || !function_exists('dns_get_record')) return false;
    foreach(['s1-ionos','s2-ionos','s42582890'] as $selector){
        try{
            $rows=@dns_get_record($selector.'._domainkey.'.$domain,DNS_CNAME|DNS_TXT);
            if(is_array($rows)&&$rows!==[])return true;
        }catch(Throwable){}
    }
    return false;
}

function platform_mail_status(array $user, array $company): never
{
    require_method('GET', 'POST');
    if (platform_role_for_user((string)$user['id']) !== 'platform_owner') fail('Email delivery diagnostics are restricted to the Tegh platform owner.',403,'platform_owner_required');
    $companyId = (string)$company['id'];
    $fromRaw = trim((string)(config('mail.from_email') ?? ''));
    $fromEmail = '';
    if ($fromRaw !== '') {
        try {
            $fromEmail = safe_email($fromRaw);
        } catch (Throwable) {
            $fromEmail = '';
        }
    }
    $host = trim((string)(config('mail.smtp_host') ?? ''));
    $port = (int)(config('mail.smtp_port') ?? 587);
    $encryption = strtolower(trim((string)(config('mail.smtp_encryption') ?? 'tls')));
    $fallback = (bool)(config('mail.sendmail_fallback') ?? true);
    $configured = $fromEmail !== '' && ($host !== '' || $fallback);
    $transport = $host !== ''
        ? 'SMTP · '.$host.':'.$port.' · '.($encryption === 'ssl' ? 'SSL/TLS' : 'STARTTLS')
        : ($fallback ? 'Server mail service' : 'Not configured');

    $sent = 0;
    $failed = 0;
    $pending = 0;
    $manualReview = 0;
    $lastError = null;
    $lastErrorStage = null;
    $lastSuccessfulTest = null;
    if (schema_table_exists('outbound_emails')) {
        $stmt = db()->prepare("SELECT
            COALESCE(SUM(status='sent'),0) AS sent_count,
            COALESCE(SUM(status='failed'),0) AS failed_count,
            COALESCE(SUM(status IN ('pending','deferred')),0) AS pending_count,
            COALESCE(SUM(status='manual_review'),0) AS manual_review_count
            FROM outbound_emails
            WHERE company_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 30 DAY");
        $stmt->execute([$companyId]);
        $counts = $stmt->fetch() ?: [];
        $sent = (int)($counts['sent_count'] ?? 0);
        $failed = (int)($counts['failed_count'] ?? 0);
        $pending = (int)($counts['pending_count'] ?? 0);
        $manualReview = (int)($counts['manual_review_count'] ?? 0);
        $stmt = db()->prepare("SELECT provider_message FROM outbound_emails
            WHERE company_id=? AND status='failed' AND provider_message IS NOT NULL
            ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$companyId]);
        $error = $stmt->fetchColumn();
        $lastError = $error === false ? null : mb_substr((string)$error, 0, 300);
        $stmt=db()->prepare("SELECT sent_at FROM outbound_emails WHERE company_id=? AND template_key='delivery_test' AND status IN ('sent','sent_warning') ORDER BY sent_at DESC LIMIT 1");
        $stmt->execute([$companyId]);$success=$stmt->fetchColumn();$lastSuccessfulTest=$success===false?null:(string)$success;
    }
    if(schema_table_exists('outbound_email_attempts')){
        $stmt=db()->prepare("SELECT stage,provider_diagnostic FROM outbound_email_attempts WHERE company_id=? AND outcome IN ('definitive_preaccept_failure','temporary_preaccept_failure','ambiguous_after_submission') ORDER BY started_at DESC LIMIT 1");
        $stmt->execute([$companyId]);if($attempt=$stmt->fetch()){$lastErrorStage=(string)$attempt['stage'];if($lastError===null&&trim((string)$attempt['provider_diagnostic'])!=='')$lastError=mb_substr((string)$attempt['provider_diagnostic'],0,300);}
    }

    $senderDomain=$fromEmail!==''?strtolower((string)substr(strrchr($fromEmail,'@')?:'@',1)):'';
    $siteDomain=strtolower((string)(parse_url((string)config('app.base_url'),PHP_URL_HOST)?:''));
    $username=trim((string)(config('mail.smtp_username')??''));
    $usernameDomain=str_contains($username,'@')?strtolower((string)substr(strrchr($username,'@'),1)):'';
    $spf=$senderDomain!==''?platform_dns_txt($senderDomain):[];
    $dmarc=$senderDomain!==''?platform_dns_txt('_dmarc.'.$senderDomain):[];
    $spfReady=(bool)array_filter($spf,static fn(string $row):bool=>str_starts_with(strtolower(trim($row)),'v=spf1'));
    $dmarcReady=(bool)array_filter($dmarc,static fn(string $row):bool=>str_starts_with(strtolower(trim($row)),'v=dmarc1'));
    $dkimReady=platform_ionos_dkim_ready($senderDomain);
    $senderAligned=$senderDomain!==''&&$siteDomain!==''&&($siteDomain===$senderDomain||str_ends_with($siteDomain,'.'.$senderDomain));
    $smtpAligned=$usernameDomain===''||$usernameDomain===$senderDomain;
    $status = [
        'configured' => $configured,
        'transport' => $transport,
        'host'=>$host,
        'port'=>$port,
        'encryption'=>$encryption==='ssl'?'SSL/TLS':($encryption==='tls'?'STARTTLS':'None'),
        'authenticationConfigured'=>$host!==''&&$username!=='',
        'maskedUsername'=>$username===''?'':(str_contains($username,'@')?mb_substr($username,0,1).'•••@'.substr(strrchr($username,'@'),1):mb_substr($username,0,2).'•••'),
        'fromEmail' => $fromEmail,
        'replyTo'=>trim((string)(config('mail.reply_to')??$fromEmail)),
        'sentLast30Days' => $sent,
        'failedLast30Days' => $failed,
        'pendingLast30Days' => $pending,
        'manualReviewLast30Days'=>$manualReview,
        'lastSuccessfulTest'=>$lastSuccessfulTest,
        'lastError' => $lastError,
        'lastErrorStage'=>$lastErrorStage,
        'checkedAt'=>gmdate('c'),
        'limitations'=>'DNS checks confirm published records only; they do not guarantee inbox placement or recipient-server acceptance.',
        'deliverability'=>[
            'authenticatedSmtp'=>$host!==''&&$username!=='',
            'senderAligned'=>$senderAligned,
            'smtpSenderAligned'=>$smtpAligned,
            'spf'=>$spfReady?'pass':($senderDomain!==''?'missing':'unknown'),
            'dkim'=>$dkimReady?'pass':($senderDomain!==''?'missing':'unknown'),
            'dmarc'=>$dmarcReady?'pass':($senderDomain!==''?'missing':'unknown'),
            'senderDomain'=>$senderDomain,
        ],
    ];
    if (request_method() === 'GET') {
        json_response(['mail' => $status]);
    }

    require_csrf();
    if (!$configured) {
        fail('Email delivery is not configured. Add the sender and SMTP settings in the private server configuration.', 503, 'mail_not_configured');
    }
    $input = request_json();
    $recipient = safe_email($input['recipient'] ?? $user['email']);
    $ownerEmail=safe_email((string)$user['email']);
    if(!hash_equals(strtolower($ownerEmail),strtolower($recipient)))fail('Test email can be sent only to the signed-in Platform Owner address.',403,'mail_test_recipient_restricted');
    if(schema_table_exists('outbound_emails')){$limit=db()->prepare("SELECT COUNT(*) FROM outbound_emails WHERE created_by=? AND template_key='delivery_test' AND created_at>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE");$limit->execute([(string)$user['id']]);if((int)$limit->fetchColumn()>0)fail('Wait five minutes before sending another test email.',429,'mail_test_rate_limited');}
    $companyName = (string)($company['name'] ?? 'your company');
    $text = "This is a Tegh email delivery test for $companyName.\n\nIf you received it, invitation emails can be sent from this server.";
    $html = '<div style="font-family:Arial,sans-serif;max-width:620px"><h2>Email delivery test</h2><p>Tegh successfully sent this message for <strong>'.htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8').'</strong>.</p><p>Invitation email delivery is ready.</p></div>';
    $delivery = sr_mail_send($companyId, (string)$user['id'], $recipient, 'delivery_test', 'Tegh email delivery test', $text, $html,[],['operationKey'=>hash('sha256','mail-test|'.$companyId.'|'.(string)$user['id'].'|'.gmdate('Y-m-d H:i'))]);
    audit_event($user, $companyId, 'email.delivery_test', 'outbound_email', (string)$delivery['id'], [
        'recipient' => $recipient,
        'sent' => (bool)$delivery['sent'],'outcome'=>$delivery['outcome']??null,'stage'=>$delivery['stage']??null,
    ]);
    json_response(['delivery' => $delivery, 'mail' => $status]);
}

function platform_invitations(array $user,array $company): never
{
    require_company_permission($company,'users.invite');$companyId=(string)$company['id'];
    if(request_method()==='GET'){
        $stmt=db()->prepare("SELECT ci.id,ci.email,ci.scope,ci.role,ci.status,ci.expires_at,ci.accepted_at,ci.created_at,ci.delivery_status,ci.delivery_error,ci.last_sent_at,u.display_name AS invited_by_name FROM company_invitations ci JOIN users u ON u.id=ci.invited_by WHERE ci.company_id=? ORDER BY ci.created_at DESC LIMIT 100");
        $stmt->execute([$companyId]);
        json_response(['invitations'=>array_map(static fn(array $r):array=>[
            'id'=>(string)$r['id'],'email'=>(string)$r['email'],'scope'=>(string)$r['scope'],'role'=>(string)$r['role'],'roleLabel'=>company_role_label((string)$r['role']),'status'=>(string)$r['status'],'expiresAt'=>(string)$r['expires_at'],
            'acceptedAt'=>$r['accepted_at'],'createdAt'=>(string)$r['created_at'],'invitedBy'=>(string)$r['invited_by_name'],
            'deliveryStatus'=>(string)$r['delivery_status'],'deliveryError'=>$r['delivery_error'],'lastSentAt'=>$r['last_sent_at'],
        ],$stmt->fetchAll())]);
    }
    require_method('POST');require_csrf();$input=request_json();$action=(string)($input['action']??'create');
    if($action==='revoke'){
        $inviteId=clean_text($input['id']??'','Account setup',64);$stmt=db()->prepare("UPDATE company_invitations SET status='revoked' WHERE id=? AND company_id=? AND status='pending'");$stmt->execute([$inviteId,$companyId]);
        if($stmt->rowCount()<1)fail('That pending account setup is no longer available.',409,'invitation_unavailable');audit_event($user,$companyId,'company.account_setup_cancelled','company_invitation',$inviteId,[]);json_response(['revoked'=>true]);
    }
    if($action==='resend'){
        $inviteId=clean_text($input['id']??'','Account setup',64);$stmt=db()->prepare("SELECT * FROM company_invitations WHERE id=? AND company_id=? AND status='pending' FOR UPDATE");$stmt->execute([$inviteId,$companyId]);$invite=$stmt->fetch();if(!$invite)fail('That pending account setup is no longer available.',409,'invitation_unavailable');
        $token=base64url_encode(random_bytes(32));$expires=gmdate('Y-m-d H:i:s',time()+72*3600);db()->prepare('UPDATE company_invitations SET token_hash=?,expires_at=?,delivery_status=\'pending\',delivery_error=NULL WHERE id=?')->execute([secret_hash($token),$expires,$inviteId]);
        $mail=platform_send_invitation_email((string)$invite['email'],(string)$company['name'],(string)$invite['role'],platform_invitation_base_url($token),(string)($invite['scope']??'workspace'),$companyId,(string)$user['id']);
        $invitationDeliveryStatus=!empty($mail['sent'])?'sent':'failed';
        db()->prepare('UPDATE company_invitations SET outbound_email_id=?,delivery_status=?,delivery_error=?,last_sent_at=UTC_TIMESTAMP() WHERE id=?')->execute([$mail['id'],$invitationDeliveryStatus,$mail['sent']?null:$mail['message'],$inviteId]);
        audit_event($user,$companyId,'company.account_setup_resent','company_invitation',$inviteId,['emailSent'=>$mail['sent'],'structuredOutcome'=>$mail['outcome']??null]);json_response(['deliveryStatus'=>$invitationDeliveryStatus,'structuredOutcome'=>$mail['outcome']??null,'message'=>$mail['message']]);
    }
    if(!in_array($action,['create','create_account'],true))fail('Account setup action is invalid.',422,'account_setup_action_invalid');
    $simpleAccountSetup=$action==='create_account';
    $email=safe_email($input['email']??'');$scope=$simpleAccountSetup?'company':(string)($input['scope']??'company');if(!in_array($scope,['workspace','company'],true))fail('Account setup type is invalid.');
    $platformOwner=platform_role_for_user((string)$user['id'])==='platform_owner';
    if($scope==='workspace'&&!$platformOwner)fail('Only the Tegh platform owner can invite a separate independent workspace.',403,'platform_owner_required');
    $role=(string)($input['role']??'');
    if($scope==='workspace'){$role='viewer';}
    else{
        if($role==='')fail('Choose Company Admin, Editor or Viewer before sending the invitation.',422,'company_role_required');
        if(!in_array($role,['admin','editor','viewer'],true))fail('Choose Company Admin, Editor or Viewer for company access. Company Owner and Platform Owner are protected roles and cannot be assigned here.',422,'company_role_invalid');
    }
    if($scope==='company'){
        if(!$simpleAccountSetup&&empty($input['confirmCompanyAccess']))fail('Confirm that this person may access the selected company before sending an account setup email.',422,'company_access_confirmation_required');
        $existing=db()->prepare("SELECT cm.status FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? AND u.email=? LIMIT 1");$existing->execute([$companyId,$email]);$existingStatus=$existing->fetchColumn();
        if($existingStatus!==false&&in_array((string)$existingStatus,['active','suspended'],true))fail('That user already has access to this company.',409,'member_exists');
    }
    $token=base64url_encode(random_bytes(32));$id=new_id('invite');$expires=gmdate('Y-m-d H:i:s',time()+72*3600);
    db()->prepare("UPDATE company_invitations SET status='revoked' WHERE company_id=? AND email=? AND status='pending'")->execute([$companyId,$email]);
    db()->prepare("INSERT INTO company_invitations (id,company_id,email,scope,role,token_hash,invited_by,expires_at,status,delivery_status) VALUES (?,?,?,?,?,?,?,?,'pending','pending')")->execute([$id,$companyId,$email,$scope,$role,secret_hash($token),$user['id'],$expires]);
    $mail=platform_send_invitation_email($email,(string)$company['name'],$role,platform_invitation_base_url($token),$scope,$companyId,(string)$user['id']);
    $invitationDeliveryStatus=!empty($mail['sent'])?'sent':'failed';
    db()->prepare('UPDATE company_invitations SET outbound_email_id=?,delivery_status=?,delivery_error=?,last_sent_at=UTC_TIMESTAMP() WHERE id=?')->execute([$mail['id'],$invitationDeliveryStatus,$mail['sent']?null:$mail['message'],$id]);
    $result=['id'=>$id,'email'=>$email,'scope'=>$scope,'role'=>$role,'expiresAt'=>$expires,'deliveryStatus'=>$invitationDeliveryStatus,'structuredOutcome'=>$mail['outcome']??null,'deliveryMessage'=>$mail['message']];
    audit_event($user,$companyId,$simpleAccountSetup?'company.account_setup_created':'company.user_invited','company_invitation',$id,['email'=>$email,'scope'=>$scope,'role'=>$role,'passwordOnlySetup'=>$simpleAccountSetup,'emailSent'=>$mail['sent']]);
    json_response([($simpleAccountSetup?'accountSetup':'invitation')=>$result],201);
}

function platform_members(array $user,array $company): never
{
    require_company_permission($company,'users.view');
    $companyId=(string)$company['id'];$actorRole=(string)$company['role'];
    if(request_method()==='GET'){
        $stmt=db()->prepare("SELECT u.id,u.email,u.display_name,cm.role,cm.status,cm.status_reason,cm.created_at,cm.updated_at,
            (SELECT MAX(al.created_at) FROM audit_log al WHERE al.company_id=cm.company_id AND al.actor_user_id=u.id) AS last_activity
            FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? ORDER BY FIELD(cm.status,'active','suspended','revoked'),FIELD(cm.role,'owner','admin','editor','viewer'),u.display_name,u.email");
        $stmt->execute([$companyId]);
        json_response(['members'=>array_map(static fn(array $r):array=>[
            'id'=>(string)$r['id'],'email'=>(string)$r['email'],'displayName'=>(string)$r['display_name'],'role'=>(string)$r['role'],'roleLabel'=>company_role_label((string)$r['role']),
            'status'=>(string)$r['status'],'statusReason'=>$r['status_reason'],'permissions'=>company_role_permissions((string)$r['role']),
            'addedAt'=>(string)$r['created_at'],'updatedAt'=>(string)$r['updated_at'],'lastActivity'=>$r['last_activity'],
        ],$stmt->fetchAll())]);
    }
    require_company_permission($company,'users.manage');require_method('POST');require_csrf();$input=request_json();$action=(string)($input['action']??'change_role');
    $memberId=clean_text($input['memberId']??'','User',64);
    if($memberId===(string)$user['id']&&in_array($action,['change_role','suspend','remove','transfer_ownership'],true))fail('You cannot change your own company access through this action.',409,'self_membership_change_blocked');
    $stmt=db()->prepare('SELECT cm.role,cm.status,u.email,u.display_name FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? AND cm.user_id=? FOR UPDATE');
    db()->beginTransaction();
    try{
        $stmt->execute([$companyId,$memberId]);$member=$stmt->fetch();if(!$member)fail('That user no longer has a company membership record.',404,'member_not_found');
        $owners=db()->prepare("SELECT COUNT(*) FROM company_members WHERE company_id=? AND role='owner' AND status='active'");$owners->execute([$companyId]);$ownerCount=(int)$owners->fetchColumn();
        $targetIsOwner=(string)$member['role']==='owner';$targetStatus=(string)$member['status'];
        if($actorRole!=='owner'&&$targetIsOwner)fail('Only the Company Owner can change Owner access.',403,'owner_protected');

        if($action==='transfer_ownership'){
            if($actorRole!=='owner')fail('Only the current Company Owner can transfer ownership.',403,'owner_transfer_forbidden');
            if($targetStatus!=='active')fail('Ownership can be transferred only to an active company user.',409,'owner_transfer_target_inactive');
            if($targetIsOwner)fail('That user is already a Company Owner.',409,'owner_transfer_target_owner');
            $confirmation=trim((string)($input['confirmationText']??''));
            if(!hash_equals((string)$member['email'],$confirmation))fail('Type the new owner email address exactly to confirm the transfer.',409,'owner_transfer_confirmation_failed');
            $password=(string)($input['password']??'');
            $pw=db()->prepare('SELECT password_hash FROM users WHERE id=? AND active=1 LIMIT 1');$pw->execute([$user['id']]);$passwordHash=$pw->fetchColumn();
            if($passwordHash===false||!password_verify($password,(string)$passwordHash))fail('Your current password is incorrect.',403,'password_incorrect');
            db()->prepare("UPDATE company_members SET role='owner',status='active',status_reason=NULL,suspended_at=NULL,revoked_at=NULL,updated_by=? WHERE company_id=? AND user_id=?")->execute([$user['id'],$companyId,$memberId]);
            db()->prepare("UPDATE company_members SET role='admin',updated_by=? WHERE company_id=? AND user_id=? AND role='owner'")->execute([$user['id'],$companyId,$user['id']]);
            audit_event($user,$companyId,'company.ownership_transferred','company_member',$memberId,['newOwnerEmail'=>$member['email'],'newOwnerUserId'=>$memberId,'previousOwnerUserId'=>$user['id'],'previousOwnerNewRole'=>'admin']);
            db()->commit();json_response(['transferred'=>true,'newOwnerId'=>$memberId,'previousOwnerRole'=>'admin']);
        }
        if($action==='change_role'){
            if($targetStatus!=='active')fail('Reactivate a suspended user, or send a new account setup email to a removed user, before changing the role.',409,'member_not_active');
            $role=(string)($input['role']??'viewer');if(!in_array($role,['admin','editor','viewer'],true))fail('Choose Admin, Editor or Viewer. Use Transfer Ownership to change the Company Owner.');
            if($targetIsOwner&&$ownerCount<=1)fail('Transfer ownership before changing the last owner.',409,'last_owner_required');
            db()->prepare('UPDATE company_members SET role=?,updated_by=? WHERE company_id=? AND user_id=?')->execute([$role,$user['id'],$companyId,$memberId]);
            audit_event($user,$companyId,'company.member_role_changed','company_member',$memberId,['email'=>$member['email'],'from'=>$member['role'],'to'=>$role]);
            db()->commit();json_response(['updated'=>true,'role'=>$role]);
        }
        if($action==='suspend'){
            if($targetStatus!=='active')fail('Only an active user can be suspended.',409,'member_not_active');
            if($targetIsOwner&&$ownerCount<=1)fail('The last active owner cannot be suspended.',409,'last_owner_required');
            $reason=clean_text($input['reason']??'Access suspended by company administrator','Suspension reason',500);
            db()->prepare("UPDATE company_members SET status='suspended',status_reason=?,suspended_at=UTC_TIMESTAMP(),revoked_at=NULL,updated_by=? WHERE company_id=? AND user_id=?")->execute([$reason,$user['id'],$companyId,$memberId]);
            audit_event($user,$companyId,'company.member_suspended','company_member',$memberId,['email'=>$member['email'],'role'=>$member['role'],'reason'=>$reason]);
            db()->commit();json_response(['suspended'=>true]);
        }
        if($action==='reactivate'){
            if($targetStatus!=='suspended')fail('Only a suspended user can be reactivated. Removed users need a new account setup email.',409,'member_not_suspended');
            db()->prepare("UPDATE company_members SET status='active',status_reason=NULL,suspended_at=NULL,revoked_at=NULL,updated_by=? WHERE company_id=? AND user_id=?")->execute([$user['id'],$companyId,$memberId]);
            audit_event($user,$companyId,'company.member_reactivated','company_member',$memberId,['email'=>$member['email'],'role'=>$member['role']]);
            db()->commit();json_response(['reactivated'=>true]);
        }
        if($action==='remove'){
            if($targetStatus==='revoked')fail('That user has already been removed.',409,'member_already_revoked');
            if($targetIsOwner&&$ownerCount<=1)fail('The last owner cannot be removed.',409,'last_owner_required');
            $reason=clean_text($input['reason']??'Access removed by company administrator','Removal reason',500);
            db()->prepare("UPDATE company_members SET status='revoked',status_reason=?,revoked_at=UTC_TIMESTAMP(),suspended_at=NULL,updated_by=? WHERE company_id=? AND user_id=?")->execute([$reason,$user['id'],$companyId,$memberId]);
            audit_event($user,$companyId,'company.member_removed','company_member',$memberId,['email'=>$member['email'],'role'=>$member['role'],'reason'=>$reason,'membershipRecordRetained'=>true]);
            db()->commit();json_response(['removed'=>true]);
        }
        fail('Member action is invalid.');
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}

function platform_require_current_invitation_schema(): void
{
    // Public invitation links must never trigger database DDL/migrations.
    // Deployment/startup upgrades the schema through an authenticated owner flow.
    try {
        $stmt=db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key='schema_version' LIMIT 1");
        $stmt->execute();
        $version=(int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $version=0;
    }
    if($version < 21){
        fail('Tegh is being upgraded. Please try this account setup link again after the administrator completes the upgrade.',503,'upgrade_required');
    }
}

function platform_invite_details(): never
{
    require_method('GET');platform_require_current_invitation_schema();$token=trim((string)($_GET['token']??''));if(strlen($token)<32)fail('Account setup link is invalid.',404,'invitation_invalid');
    $stmt=db()->prepare("SELECT ci.id,ci.email,ci.scope,ci.role,ci.expires_at,u.id AS existing_user_id,u.display_name FROM company_invitations ci JOIN companies c ON c.id=ci.company_id LEFT JOIN users u ON u.email=ci.email
        WHERE ci.token_hash=? AND ci.status='pending' AND ci.expires_at>UTC_TIMESTAMP() LIMIT 1");$stmt->execute([secret_hash($token)]);$row=$stmt->fetch();
    if(!$row)fail('This account setup link is unavailable or expired.',410,'invitation_expired');
    json_response(['invitation'=>['existingUser'=>$row['existing_user_id']!==null]]);
}

function platform_invite_accept(): never
{
    require_method('POST');assert_same_origin();platform_require_current_invitation_schema();$input=request_json();$token=trim((string)($input['token']??''));if(strlen($token)<32)fail('Account setup link is invalid.',404,'invitation_invalid');
    $password=(string)($input['password']??'');
    db_transaction_retry(function()use($token,$password):void{
        $stmt=db()->prepare("SELECT ci.*,c.name AS company_name FROM company_invitations ci JOIN companies c ON c.id=ci.company_id
            WHERE ci.token_hash=? AND ci.status='pending' AND ci.expires_at>UTC_TIMESTAMP() FOR UPDATE");$stmt->execute([secret_hash($token)]);$invite=$stmt->fetch();
        if(!$invite)fail('This account setup link is unavailable or expired.',410,'invitation_expired');
        $stmt=db()->prepare('SELECT * FROM users WHERE email=? FOR UPDATE');$stmt->execute([$invite['email']]);$member=$stmt->fetch();
        if($member){
            if($member['deleted_at']!==null)fail('This login was deleted and cannot be set up again.',409,'account_deleted');
            if((int)$member['active']!==1)fail('This login is deactivated. Ask the platform owner to reactivate it first.',409,'account_deactivated');
            if(!password_verify($password,(string)$member['password_hash']))fail('The login password is incorrect.',403,'password_incorrect');
            $userId=(string)$member['id'];
        }else{
            validate_new_password($password);$hash=password_hash($password,password_algorithm());if(!is_string($hash))throw new RuntimeException('Password hashing is unavailable.');
            $displayName=platform_default_display_name((string)$invite['email']);
            $userId=new_id('user');db()->prepare("INSERT INTO users (id,email,password_hash,display_name,platform_role,account_plan,signup_source,terms_accepted_at) VALUES (?,?,?,?,'member','free_preview','invitation',NULL)")->execute([$userId,$invite['email'],$hash,$displayName]);
        }
        if((string)($invite['scope']??'workspace')==='company'){
            db()->prepare("INSERT INTO company_members (company_id,user_id,role,status,status_reason,suspended_at,revoked_at,updated_by) VALUES (?,?,?,'active',NULL,NULL,NULL,?) ON DUPLICATE KEY UPDATE role=VALUES(role),status='active',status_reason=NULL,suspended_at=NULL,revoked_at=NULL,updated_by=VALUES(updated_by)")
                ->execute([$invite['company_id'],$userId,$invite['role'],$userId]);
        }
        db()->prepare("UPDATE company_invitations SET status='accepted',accepted_at=UTC_TIMESTAMP(),accepted_by=? WHERE id=?")->execute([$userId,$invite['id']]);
        $actor=['id'=>$userId,'email'=>$invite['email']];audit_event($actor,(string)$invite['company_id'],'company.account_setup_completed','company_invitation',(string)$invite['id'],['scope'=>$invite['scope']??'workspace','role'=>$invite['role'],'existingAccount'=>$member!==false]);
    });
    $stmt=db()->prepare('SELECT id,email,display_name FROM users WHERE email=(SELECT email FROM company_invitations WHERE token_hash=? LIMIT 1) AND active=1 LIMIT 1');
    $stmt->execute([secret_hash($token)]);$acceptedUser=$stmt->fetch();
    if(!$acceptedUser) fail('The account was set up, but it could not be opened. Sign in using the email that received the setup link.',500,'invitation_session_unavailable');
    $session=create_session((string)$acceptedUser['id']);
    $user=['id'=>(string)$acceptedUser['id'],'email'=>(string)$acceptedUser['email'],'displayName'=>(string)$acceptedUser['display_name']];
    json_response(['accepted'=>true,'auth'=>auth_payload($user,$session)]);
}


function platform_voucher_from_audit(array $user,string $companyId,string $action,string $entityType,string $entityId,array $metadata): void
{
    if(!schema_table_exists('vouchers'))return;
    try{
        if(in_array($action,['invoice.draft_created','invoice.issued','invoice.voided'],true)){
            $q=db()->prepare('SELECT issue_date,number,total_cents,status,issued_journal_entry_id FROM invoices WHERE id=? AND company_id=?');$q->execute([$entityId,$companyId]);$r=$q->fetch();if(!$r)return;
            voucher_register_saved($user,$companyId,'CI','AR','invoice',$entityId,(string)$r['issue_date'],'Customer invoice '.(string)$r['number'],(int)$r['total_cents'],$r['issued_journal_entry_id'],$r['status']!=='draft');if($action==='invoice.voided')voucher_mark_void($user,$companyId,'invoice',$entityId);return;
        }
        if(in_array($action,['bill.draft_created','bill.issued'],true)){
            $q=db()->prepare('SELECT bill_date,number,total_cents,status,issued_journal_entry_id FROM bills WHERE id=? AND company_id=?');$q->execute([$entityId,$companyId]);$r=$q->fetch();if(!$r)return;
            voucher_register_saved($user,$companyId,'VI','AP','bill',$entityId,(string)$r['bill_date'],'Vendor invoice '.(string)$r['number'],(int)$r['total_cents'],$r['issued_journal_entry_id'],$r['status']!=='draft');return;
        }
        if($action==='expense.posted'){
            $q=db()->prepare('SELECT expense_date,vendor,total_cents,journal_entry_id FROM expenses WHERE id=? AND company_id=?');$q->execute([$entityId,$companyId]);$r=$q->fetch();if($r)voucher_register_saved($user,$companyId,'EX','EX','expense',$entityId,(string)$r['expense_date'],'Expense · '.(string)$r['vendor'],(int)$r['total_cents'],(string)$r['journal_entry_id'],true);return;
        }
        if($action==='journal.manual_posted'){
            $q=db()->prepare('SELECT entry_date,memo,source_id FROM journal_entries WHERE id=? AND company_id=?');$q->execute([$entityId,$companyId]);$r=$q->fetch();if(!$r)return;$sourceId=(string)$r['source_id'];$a=db()->prepare('SELECT COALESCE(SUM(debit_cents),0) FROM journal_lines WHERE journal_entry_id=?');$a->execute([$entityId]);voucher_register_saved($user,$companyId,'GJ','GL','manual_journal',$sourceId,(string)$r['entry_date'],'Manual journal · '.(string)$r['memo'],(int)$a->fetchColumn(),$entityId,true);return;
        }
        if($action==='bank_transaction.posted'){
            $q=db()->prepare('SELECT transaction_date,description,amount_cents,journal_entry_id FROM bank_transactions WHERE id=? AND company_id=?');$q->execute([$entityId,$companyId]);$r=$q->fetch();if($r)voucher_register_saved($user,$companyId,'BT','BS','bank_transaction',$entityId,(string)$r['transaction_date'],'Bank statement · '.(string)$r['description'],abs((int)$r['amount_cents']),$r['journal_entry_id'],true);return;
        }
        if(in_array($action,['payroll.run_created','payroll.run_finalized','payroll.run_reversed','payroll.draft_discarded'],true)){
            $q=db()->prepare('SELECT pay_date,period_end,status,gl_status,gross_pay_cents,accrual_journal_entry_id FROM payroll_runs WHERE id=? AND company_id=?');$q->execute([$entityId,$companyId]);$r=$q->fetch();
            if($r){voucher_register_saved($user,$companyId,'PR','PL','payroll_run',$entityId,(string)$r['pay_date'],'Payroll through '.(string)$r['period_end'],(int)$r['gross_pay_cents'],$r['accrual_journal_entry_id'],(string)($r['gl_status']??'')==='posted');if($action==='payroll.run_reversed')voucher_mark_void($user,$companyId,'payroll_run',$entityId);}elseif($action==='payroll.draft_discarded')voucher_mark_void($user,$companyId,'payroll_run',$entityId);return;
        }
    }catch(Throwable $e){record_system_incident('A voucher-register background update did not complete.',500,'voucher_background_update_failed',$e,['source'=>'background_error','route'=>'platform/vouchers','companyId'=>$companyId,'auditAction'=>$action,'entityType'=>$entityType,'entityId'=>$entityId]);error_log('Tegh voucher warning request='.request_id().' '.$e->getMessage());}
}

function platform_reserve_voucher(string $companyId,string $prefix): array
{
    $prefix=strtoupper($prefix);if(!preg_match('/^[A-Z]{2,4}$/',$prefix))throw new InvalidArgumentException('Transaction prefix is invalid.');
    // Allocate both the legacy global serial and the user-facing module serial
    // with atomic InnoDB UPDATEs. LAST_INSERT_ID(expr) is connection-local, so
    // this remains concurrency-safe even when a caller is not already inside
    // a wider accounting transaction. Voided/deleted numbers are never reused.
    db()->prepare("INSERT INTO voucher_sequences (company_id,next_serial)
        SELECT ?,COALESCE(MAX(serial_number),0)+1 FROM vouchers WHERE company_id=?
        ON DUPLICATE KEY UPDATE next_serial=GREATEST(next_serial,VALUES(next_serial))")
      ->execute([$companyId,$companyId]);
    db()->prepare('UPDATE voucher_sequences SET next_serial=LAST_INSERT_ID(next_serial+1) WHERE company_id=?')->execute([$companyId]);
    $serial=max(1,(int)db()->query('SELECT LAST_INSERT_ID()')->fetchColumn()-1);

    db()->prepare("INSERT INTO transaction_sequences (company_id,prefix,next_serial)
        SELECT ?,?,COALESCE(MAX(CASE WHEN voucher_number REGEXP '^[A-Z]{2,4}-[0-9]+$' THEN CAST(SUBSTRING_INDEX(voucher_number,'-',-1) AS UNSIGNED) ELSE 0 END),0)+1
        FROM vouchers WHERE company_id=? AND prefix=?
        ON DUPLICATE KEY UPDATE next_serial=GREATEST(next_serial,VALUES(next_serial))")
      ->execute([$companyId,$prefix,$companyId,$prefix]);
    db()->prepare('UPDATE transaction_sequences SET next_serial=LAST_INSERT_ID(next_serial+1) WHERE company_id=? AND prefix=?')->execute([$companyId,$prefix]);
    $moduleSerial=max(1,(int)db()->query('SELECT LAST_INSERT_ID()')->fetchColumn()-1);
    return ['serial'=>$serial,'moduleSerial'=>$moduleSerial,'number'=>$prefix.'-'.str_pad((string)$moduleSerial,3,'0',STR_PAD_LEFT)];
}

function voucher_register_saved(array $user,string $companyId,string $prefix,string $module,string $sourceType,string $sourceId,string $date,string $description,int $totalCents,?string $journalEntryId=null,bool $posted=false): string
{
    if(!schema_table_exists('vouchers'))return '';
    $find=db()->prepare('SELECT id FROM vouchers WHERE company_id=? AND source_type=? AND source_id=? LIMIT 1');$find->execute([$companyId,$sourceType,$sourceId]);$existing=$find->fetchColumn();
    if($existing!==false){if($posted) voucher_mark_posted($user,$companyId,$sourceType,$sourceId,$journalEntryId);return (string)$existing;}
    $number=platform_reserve_voucher($companyId,$prefix);$id=new_id('voucher');
    db()->prepare("INSERT INTO vouchers (id,company_id,serial_number,voucher_number,prefix,module,source_type,source_id,voucher_date,description,amount_cents,status,journal_entry_id,created_by,posted_by,posted_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$id,$companyId,$number['serial'],$number['number'],$prefix,$module,$sourceType,$sourceId,$date,mb_substr($description,0,500),$totalCents,$posted?'posted':'draft',$journalEntryId,$user['id'],$posted?$user['id']:null,$posted?gmdate('Y-m-d H:i:s'):null]);
    return $id;
}


function voucher_update_saved(string $companyId,string $sourceType,string $sourceId,string $date,string $description,int $totalCents): void
{
    if(!schema_table_exists('vouchers'))return;
    // Only draft vouchers may follow edits to their draft source document.
    db()->prepare("UPDATE vouchers SET voucher_date=?,description=?,amount_cents=? WHERE company_id=? AND source_type=? AND source_id=? AND status='draft'")
        ->execute([$date,mb_substr($description,0,500),$totalCents,$companyId,$sourceType,$sourceId]);
}

function voucher_mark_posted(array $user,string $companyId,string $sourceType,string $sourceId,?string $journalEntryId=null): void
{
    if(!schema_table_exists('vouchers'))return;
    db()->prepare("UPDATE vouchers SET status='posted',journal_entry_id=COALESCE(?,journal_entry_id),posted_by=?,posted_at=COALESCE(posted_at,UTC_TIMESTAMP()) WHERE company_id=? AND source_type=? AND source_id=?")
        ->execute([$journalEntryId,$user['id'],$companyId,$sourceType,$sourceId]);
}
function voucher_mark_void(array $user,string $companyId,string $sourceType,string $sourceId): void
{
    if(!schema_table_exists('vouchers'))return;db()->prepare("UPDATE vouchers SET status='void',posted_by=COALESCE(posted_by,?),posted_at=COALESCE(posted_at,UTC_TIMESTAMP()) WHERE company_id=? AND source_type=? AND source_id=?")
        ->execute([$user['id'],$companyId,$sourceType,$sourceId]);
}

function platform_vouchers(array $company): never
{
    require_method('GET');$limit=max(1,min(500,(int)($_GET['limit']??200)));$stmt=db()->prepare("SELECT v.*,u.display_name AS created_name,p.display_name AS posted_name FROM vouchers v JOIN users u ON u.id=v.created_by LEFT JOIN users p ON p.id=v.posted_by WHERE v.company_id=? ORDER BY v.serial_number DESC LIMIT $limit");$stmt->execute([$company['id']]);
    json_response(['vouchers'=>array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'serialNumber'=>(int)$r['serial_number'],'voucherNumber'=>(string)$r['voucher_number'],'module'=>(string)$r['module'],'sourceType'=>(string)$r['source_type'],'sourceId'=>(string)$r['source_id'],'status'=>(string)$r['status'],'date'=>(string)$r['voucher_date'],'description'=>(string)$r['description'],'totalCents'=>(int)$r['amount_cents'],'journalEntryId'=>$r['journal_entry_id'],'createdBy'=>(string)$r['created_name'],'postedBy'=>$r['posted_name'],'createdAt'=>(string)$r['created_at'],'postedAt'=>$r['posted_at']],$stmt->fetchAll())]);
}

function platform_customer_ledger(array $company): never
{
    require_method('GET');$id=clean_text($_GET['customerId']??'','Customer',64);$stmt=db()->prepare('SELECT id,name,email FROM customers WHERE id=? AND company_id=?');$stmt->execute([$id,$company['id']]);$customer=$stmt->fetch();if(!$customer)fail('Customer not found.',404,'customer_not_found');
    $stmt=db()->prepare("SELECT i.id,i.number,i.issue_date,i.due_date,i.status,i.total_cents,i.balance_cents,i.is_opening_document,i.original_paid_cents,COALESCE(v.voucher_number,ov.voucher_number) AS voucher_number FROM invoices i LEFT JOIN vouchers v ON v.company_id=i.company_id AND v.source_type='invoice' AND v.source_id=i.id LEFT JOIN vouchers ov ON ov.company_id=i.company_id AND ov.source_type='opening_customer_invoices' AND ov.source_id=i.opening_import_id WHERE i.company_id=? AND i.customer_id=? ORDER BY i.issue_date,i.number");$stmt->execute([$company['id'],$id]);$rows=$stmt->fetchAll();
    $entries=[];$recordedByDocument=[];$openingBalance=0;
    if(schema_table_exists('party_opening_balances')){$oq=db()->prepare("SELECT pob.effective_date,pob.amount_cents,v.voucher_number FROM party_opening_balances pob LEFT JOIN vouchers v ON v.id=pob.voucher_id WHERE pob.company_id=? AND pob.party_type='customer' AND pob.party_id=? LIMIT 1");$oq->execute([$company['id'],$id]);if($op=$oq->fetch()){$openingBalance=(int)$op['amount_cents'];$entries[]=['date'=>(string)$op['effective_date'],'sortOrder'=>5,'type'=>'opening_balance','reference'=>'Opening Balance','voucherNumber'=>$op['voucher_number'],'debitCents'=>max(0,$openingBalance),'creditCents'=>max(0,-$openingBalance),'status'=>'posted','openCents'=>$openingBalance];}}
    $stmt=db()->prepare("SELECT pp.*,v.voucher_number,rje.entry_date AS reversal_date FROM party_payments pp
      LEFT JOIN vouchers v ON v.company_id=pp.company_id AND v.source_type='customer_payment' AND v.source_id=pp.id
      LEFT JOIN journal_entries rje ON rje.id=pp.reversal_journal_entry_id
      WHERE pp.company_id=? AND pp.payment_type='customer' AND pp.party_id=?
      ORDER BY pp.payment_date,pp.created_at");
    $stmt->execute([$company['id'],$id]);$payments=$stmt->fetchAll();
    foreach($payments as $payment){
        $documentId=(string)$payment['document_id'];$applied=(int)$payment['applied_cents'];
        if((string)$payment['status']==='posted')$recordedByDocument[$documentId]=($recordedByDocument[$documentId]??0)+$applied;
        $entries[]=['date'=>(string)$payment['payment_date'],'sortOrder'=>20,'type'=>'payment','reference'=>(string)$payment['reference'],'voucherNumber'=>$payment['voucher_number'],'debitCents'=>0,'creditCents'=>$applied,'status'=>(string)$payment['status'],'openCents'=>0];
        if((string)$payment['status']==='reversed'){
            $entries[]=['date'=>(string)($payment['reversal_date']?:substr((string)($payment['reversed_at']?:$payment['payment_date']),0,10)),'sortOrder'=>30,'type'=>'payment_reversal','reference'=>'Reversal · '.(string)$payment['reference'],'voucherNumber'=>$payment['voucher_number'],'debitCents'=>$applied,'creditCents'=>0,'status'=>'reversed','openCents'=>0];
        }
    }
    foreach($rows as $r){
        $entries[]=['date'=>(string)$r['issue_date'],'sortOrder'=>10,'type'=>!empty($r['is_opening_document'])?'opening_invoice':'invoice','reference'=>(string)$r['number'],'voucherNumber'=>$r['voucher_number'],'debitCents'=>(int)$r['total_cents'],'creditCents'=>0,'status'=>(string)$r['status'],'openCents'=>(int)$r['balance_cents'],'originalPaidCents'=>(int)($r['original_paid_cents']??0)];
        $legacyPaid=max(0,(int)$r['total_cents']-(int)$r['balance_cents']-(int)($recordedByDocument[(string)$r['id']]??0));
        if($legacyPaid>0)$entries[]=['date'=>(string)$r['issue_date'],'sortOrder'=>25,'type'=>'payment','reference'=>'Earlier Payments Applied to '.(string)$r['number'],'voucherNumber'=>null,'debitCents'=>0,'creditCents'=>$legacyPaid,'status'=>'applied','openCents'=>0];
    }
    usort($entries,static fn(array $a,array $b):int=>[$a['date'],$a['sortOrder'],$a['reference']]<=>[$b['date'],$b['sortOrder'],$b['reference']]);
    $running=0;foreach($entries as &$entry){$running+=(int)$entry['debitCents']-(int)$entry['creditCents'];$entry['balanceCents']=$running;unset($entry['sortOrder']);}unset($entry);
    json_response(['ledger'=>['party'=>['id'=>(string)$customer['id'],'name'=>(string)$customer['name'],'email'=>$customer['email']],'entries'=>$entries,'balanceCents'=>$openingBalance+array_sum(array_map(static fn(array $r):int=>(int)$r['balance_cents'],$rows))]]);
}
function platform_vendor_ledger(array $company): never
{
    require_method('GET');$id=clean_text($_GET['vendorId']??'','Vendor',64);$stmt=db()->prepare('SELECT id,name,email FROM vendors WHERE id=? AND company_id=?');$stmt->execute([$id,$company['id']]);$vendor=$stmt->fetch();if(!$vendor)fail('Vendor not found.',404,'vendor_not_found');
    $stmt=db()->prepare("SELECT b.id,b.number,b.bill_date,b.due_date,b.status,b.total_cents,b.balance_cents,b.is_opening_document,b.original_paid_cents,COALESCE(v.voucher_number,ov.voucher_number) AS voucher_number FROM bills b LEFT JOIN vouchers v ON v.company_id=b.company_id AND v.source_type='bill' AND v.source_id=b.id LEFT JOIN vouchers ov ON ov.company_id=b.company_id AND ov.source_type='opening_vendor_bills' AND ov.source_id=b.opening_import_id WHERE b.company_id=? AND b.vendor_id=? ORDER BY b.bill_date,b.number");$stmt->execute([$company['id'],$id]);$rows=$stmt->fetchAll();
    $entries=[];$recordedByDocument=[];$openingBalance=0;
    if(schema_table_exists('party_opening_balances')){$oq=db()->prepare("SELECT pob.effective_date,pob.amount_cents,v.voucher_number FROM party_opening_balances pob LEFT JOIN vouchers v ON v.id=pob.voucher_id WHERE pob.company_id=? AND pob.party_type='vendor' AND pob.party_id=? LIMIT 1");$oq->execute([$company['id'],$id]);if($op=$oq->fetch()){$openingBalance=(int)$op['amount_cents'];$entries[]=['date'=>(string)$op['effective_date'],'sortOrder'=>5,'type'=>'opening_balance','reference'=>'Opening Balance','voucherNumber'=>$op['voucher_number'],'debitCents'=>max(0,-$openingBalance),'creditCents'=>max(0,$openingBalance),'status'=>'posted','openCents'=>$openingBalance];}}
    $stmt=db()->prepare("SELECT pp.*,v.voucher_number,rje.entry_date AS reversal_date FROM party_payments pp
      LEFT JOIN vouchers v ON v.company_id=pp.company_id AND v.source_type='vendor_payment' AND v.source_id=pp.id
      LEFT JOIN journal_entries rje ON rje.id=pp.reversal_journal_entry_id
      WHERE pp.company_id=? AND pp.payment_type='vendor' AND pp.party_id=?
      ORDER BY pp.payment_date,pp.created_at");
    $stmt->execute([$company['id'],$id]);$payments=$stmt->fetchAll();
    foreach($payments as $payment){
        $documentId=(string)$payment['document_id'];$applied=(int)$payment['applied_cents'];
        if((string)$payment['status']==='posted')$recordedByDocument[$documentId]=($recordedByDocument[$documentId]??0)+$applied;
        $entries[]=['date'=>(string)$payment['payment_date'],'sortOrder'=>20,'type'=>'payment','reference'=>(string)$payment['reference'],'voucherNumber'=>$payment['voucher_number'],'debitCents'=>$applied,'creditCents'=>0,'status'=>(string)$payment['status'],'openCents'=>0];
        if((string)$payment['status']==='reversed'){
            $entries[]=['date'=>(string)($payment['reversal_date']?:substr((string)($payment['reversed_at']?:$payment['payment_date']),0,10)),'sortOrder'=>30,'type'=>'payment_reversal','reference'=>'Reversal · '.(string)$payment['reference'],'voucherNumber'=>$payment['voucher_number'],'debitCents'=>0,'creditCents'=>$applied,'status'=>'reversed','openCents'=>0];
        }
    }
    foreach($rows as $r){
        $entries[]=['date'=>(string)$r['bill_date'],'sortOrder'=>10,'type'=>!empty($r['is_opening_document'])?'opening_bill':'bill','reference'=>(string)$r['number'],'voucherNumber'=>$r['voucher_number'],'debitCents'=>0,'creditCents'=>(int)$r['total_cents'],'status'=>(string)$r['status'],'openCents'=>(int)$r['balance_cents'],'originalPaidCents'=>(int)($r['original_paid_cents']??0)];
        $legacyPaid=max(0,(int)$r['total_cents']-(int)$r['balance_cents']-(int)($recordedByDocument[(string)$r['id']]??0));
        if($legacyPaid>0)$entries[]=['date'=>(string)$r['bill_date'],'sortOrder'=>25,'type'=>'payment','reference'=>'Earlier Payments Applied to '.(string)$r['number'],'voucherNumber'=>null,'debitCents'=>$legacyPaid,'creditCents'=>0,'status'=>'applied','openCents'=>0];
    }
    usort($entries,static fn(array $a,array $b):int=>[$a['date'],$a['sortOrder'],$a['reference']]<=>[$b['date'],$b['sortOrder'],$b['reference']]);
    $running=0;foreach($entries as &$entry){$running+=(int)$entry['creditCents']-(int)$entry['debitCents'];$entry['balanceCents']=$running;unset($entry['sortOrder']);}unset($entry);
    json_response(['ledger'=>['party'=>['id'=>(string)$vendor['id'],'name'=>(string)$vendor['name'],'email'=>$vendor['email']],'entries'=>$entries,'balanceCents'=>$openingBalance+array_sum(array_map(static fn(array $r):int=>(int)$r['balance_cents'],$rows))]]);
}

function platform_audit_chain_verify(string $companyId): array
{
    $stmt=db()->prepare('SELECT id,previous_hash,entry_hash,actor_user_id,actor_email,action,entity_type,entity_id,metadata_json,request_id,created_at FROM audit_log WHERE company_id=? ORDER BY created_at,id');$stmt->execute([$companyId]);$previous='';$checked=0;$issues=[];
    foreach($stmt->fetchAll() as $row){$checked++;$expected=hash('sha256',implode('|',[$companyId,$previous,(string)$row['id'],(string)$row['actor_user_id'],(string)$row['actor_email'],(string)$row['action'],(string)$row['entity_type'],(string)$row['entity_id'],(string)$row['metadata_json'],(string)($row['request_id']??''),(string)$row['created_at']]));
        if((string)($row['previous_hash']??'')!==$previous||!hash_equals((string)($row['entry_hash']??''),$expected))$issues[]=['auditId'=>(string)$row['id'],'action'=>(string)$row['action']];$previous=(string)($row['entry_hash']??'');}
    return ['checked'=>$checked,'issues'=>$issues,'valid'=>$issues===[],'lastHash'=>$previous];
}
function platform_integrity(array $user,array $company): never
{
    require_method('GET','POST');require_company_permission($company,'audit.view');if(request_method()==='POST')require_company_permission($company,'integrity.run');$companyId=(string)$company['id'];$chain=platform_audit_chain_verify($companyId);
    $stmt=db()->prepare("SELECT COALESCE(SUM(jl.debit_cents),0) d,COALESCE(SUM(jl.credit_cents),0) c FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.status='posted'");$stmt->execute([$companyId]);$balance=$stmt->fetch();
    $stmt=db()->prepare("SELECT COUNT(*) FROM journal_entries je WHERE je.company_id=? AND je.status='posted' AND NOT EXISTS(SELECT 1 FROM journal_lines jl WHERE jl.journal_entry_id=je.id)");$stmt->execute([$companyId]);$empty=(int)$stmt->fetchColumn();
    $stmt=db()->prepare("SELECT COUNT(*) FROM vouchers v WHERE v.company_id=? AND v.status='posted' AND v.journal_entry_id IS NULL
      AND (v.module NOT IN ('AR','AP','PL') OR v.source_type IN ('customer_payment','vendor_payment'))");$stmt->execute([$companyId]);$voucherIssues=(int)$stmt->fetchColumn();
    $missingSql="SELECT
      (SELECT COUNT(*) FROM invoices i WHERE i.company_id=? AND COALESCE(i.is_opening_document,0)=0 AND NOT EXISTS(SELECT 1 FROM vouchers v WHERE v.company_id=i.company_id AND v.source_type='invoice' AND v.source_id=i.id))+
      (SELECT COUNT(*) FROM bills b WHERE b.company_id=? AND COALESCE(b.is_opening_document,0)=0 AND NOT EXISTS(SELECT 1 FROM vouchers v WHERE v.company_id=b.company_id AND v.source_type='bill' AND v.source_id=b.id))+
      (SELECT COUNT(*) FROM expenses e WHERE e.company_id=? AND NOT EXISTS(SELECT 1 FROM vouchers v WHERE v.company_id=e.company_id AND v.source_type='expense' AND v.source_id=e.id))+
      (SELECT COUNT(*) FROM bank_transactions bt WHERE bt.company_id=? AND bt.status<>'duplicate' AND NOT EXISTS(SELECT 1 FROM vouchers v WHERE v.company_id=bt.company_id AND v.source_type='bank_transaction' AND v.source_id=bt.id))+
      (SELECT COUNT(*) FROM payroll_runs pr WHERE pr.company_id=? AND NOT EXISTS(SELECT 1 FROM vouchers v WHERE v.company_id=pr.company_id AND v.source_type='payroll_run' AND v.source_id=pr.id))+
      (SELECT COUNT(*) FROM party_payments pp WHERE pp.company_id=? AND NOT EXISTS(SELECT 1 FROM vouchers v WHERE v.company_id=pp.company_id AND v.source_type=CONCAT(pp.payment_type,'_payment') AND v.source_id=pp.id))+
      (SELECT COUNT(*) FROM journal_entries je WHERE je.company_id=? AND je.source_type='manual_journal' AND NOT EXISTS(SELECT 1 FROM vouchers v WHERE v.company_id=je.company_id AND v.source_type='manual_journal' AND v.source_id=je.source_id)) missing";
    $stmt=db()->prepare($missingSql);$stmt->execute(array_fill(0,7,$companyId));$missingVouchers=(int)$stmt->fetchColumn();
    $stmt=db()->prepare("SELECT COUNT(*) total,SUM(role='owner') owners,SUM(role='admin') admins,SUM(role='editor') editors,SUM(role='viewer') viewers FROM company_members WHERE company_id=? AND status='active'");$stmt->execute([$companyId]);$members=$stmt->fetch();
    $stmt=db()->prepare("SELECT SUM(status='pending' AND expires_at>UTC_TIMESTAMP()) pending,SUM(status='pending' AND expires_at<=UTC_TIMESTAMP()) expired FROM company_invitations WHERE company_id=?");$stmt->execute([$companyId]);$invites=$stmt->fetch();
    $stmt=db()->prepare("SELECT filename,created_at FROM backup_restore_log WHERE company_id=? AND action='export' ORDER BY created_at DESC LIMIT 1");$stmt->execute([$companyId]);$lastBackup=$stmt->fetch()?:null;
    $backupAgeDays=$lastBackup===null?null:(int)floor((time()-strtotime((string)$lastBackup['created_at'].' UTC'))/86400);
    $stmt=db()->prepare("SELECT COUNT(*) FROM period_locks WHERE company_id=? AND locked=1");$stmt->execute([$companyId]);$lockedPeriods=(int)$stmt->fetchColumn();
    $stmt=db()->prepare("SELECT COUNT(*) FROM reconciliation_events WHERE company_id=? AND action='reopened' AND created_at>=UTC_TIMESTAMP()-INTERVAL 90 DAY");$stmt->execute([$companyId]);$reopened=(int)$stmt->fetchColumn();
    $stmt=db()->prepare("SELECT status,effective_date,checked_at,records_applied FROM official_rate_releases WHERE company_id=? ORDER BY checked_at DESC LIMIT 1");$stmt->execute([$companyId]);$rateRelease=$stmt->fetch()?:null;
    $failedEmails=0;$activeSupport=0;$catalogueItems=0;
    if(schema_table_exists('outbound_emails')){$stmt=db()->prepare("SELECT COUNT(*) FROM outbound_emails WHERE company_id=? AND status='failed' AND created_at>=UTC_TIMESTAMP()-INTERVAL 30 DAY");$stmt->execute([$companyId]);$failedEmails=(int)$stmt->fetchColumn();}
    if(schema_table_exists('support_requests')){$stmt=db()->prepare("SELECT COUNT(*) FROM support_requests WHERE company_id=? AND grant_access=1 AND status IN ('pending','in_progress') AND (access_expires_at IS NULL OR access_expires_at>UTC_TIMESTAMP())");$stmt->execute([$companyId]);$activeSupport=(int)$stmt->fetchColumn();}
    if(schema_table_exists('products_services')){$stmt=db()->prepare("SELECT COUNT(*) FROM products_services WHERE company_id=? AND active=1");$stmt->execute([$companyId]);$catalogueItems=(int)$stmt->fetchColumn();}
    $passed=$chain['valid']&&(int)$balance['d']===(int)$balance['c']&&$empty===0&&$voucherIssues===0&&$missingVouchers===0&&(int)($members['owners']??0)>0;
    $result=[
      'auditChain'=>['checked'=>(int)$chain['checked'],'valid'=>(bool)$chain['valid'],'issueCount'=>count($chain['issues'])],
      'ledger'=>['debitsCents'=>(int)$balance['d'],'creditsCents'=>(int)$balance['c'],'balanced'=>(int)$balance['d']===(int)$balance['c'],'emptyPostedJournals'=>$empty],
      'vouchers'=>['postedWithoutJournal'=>$voucherIssues,'sourceDocumentsWithoutVoucher'=>$missingVouchers],
      'access'=>['members'=>(int)($members['total']??0),'owners'=>(int)($members['owners']??0),'admins'=>(int)($members['admins']??0),'editors'=>(int)($members['editors']??0),'viewers'=>(int)($members['viewers']??0),'pendingInvitations'=>(int)($invites['pending']??0),'expiredInvitations'=>(int)($invites['expired']??0)],
      'recovery'=>['lastBackupAt'=>$lastBackup['created_at']??null,'lastBackupFilename'=>$lastBackup['filename']??null,'backupAgeDays'=>$backupAgeDays,'backupRecommended'=>$lastBackup===null||$backupAgeDays>30],
      'operations'=>['lockedPeriods'=>$lockedPeriods,'reopenedReconciliationsLast90Days'=>$reopened],
      'portal'=>['publicSignupEnabled'=>public_signup_enabled(),'failedEmailsLast30Days'=>$failedEmails,'activeSupportAccess'=>$activeSupport,'activeProductsServices'=>$catalogueItems],
      'rates'=>$rateRelease===null?null:['status'=>(string)$rateRelease['status'],'effectiveDate'=>$rateRelease['effective_date'],'checkedAt'=>(string)$rateRelease['checked_at'],'recordsApplied'=>(int)$rateRelease['records_applied']],
      'passed'=>$passed,'generatedAt'=>gmdate('c')
    ];
    if(request_method()==='POST'){
        require_csrf();$id=new_id('integrity');db()->prepare('INSERT INTO audit_integrity_runs (id,company_id,run_by,result_json,status,last_audit_hash) VALUES (?,?,?,?,?,?)')
            ->execute([$id,$companyId,$user['id'],json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$passed?'passed':'attention',$chain['lastHash']]);
        audit_event($user,$companyId,'integrity.check_completed','audit_integrity_run',$id,['passed'=>$passed,'missingVouchers'=>$missingVouchers,'backupRecommended'=>$result['recovery']['backupRecommended']]);
    }
    json_response(['integrity'=>$result]);
}

function platform_http_html(string $url): string
{
    $errors=[];
    if(function_exists('curl_init')){
        $c=curl_init($url);
        if($c!==false){
            curl_setopt_array($c,[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_FOLLOWLOCATION=>true,
                CURLOPT_MAXREDIRS=>5,
                CURLOPT_CONNECTTIMEOUT=>8,
                CURLOPT_TIMEOUT=>20,
                CURLOPT_ENCODING=>'',
                CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0 Safari/537.36 Tegh/2.8.30',
                CURLOPT_HTTPHEADER=>[
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-CA,en;q=0.9',
                    'Cache-Control: no-cache',
                ],
            ]);
            $raw=curl_exec($c);
            $status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);
            $error=curl_error($c);
            curl_close($c);
            if(is_string($raw)&&$raw!==''&&$status>=200&&$status<300)return $raw;
            $errors[]='curl '.($status?:'transport').($error!==''?' '.$error:'');
        }
    }
    if((bool)ini_get('allow_url_fopen')){
        $context=stream_context_create(['http'=>[
            'method'=>'GET',
            'timeout'=>20,
            'follow_location'=>1,
            'max_redirects'=>5,
            'ignore_errors'=>true,
            'header'=>"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/151.0 Safari/537.36 Tegh/2.8.30\r\nAccept: text/html,application/xhtml+xml\r\nAccept-Language: en-CA,en;q=0.9\r\n",
        ]]);
        $raw=@file_get_contents($url,false,$context);
        $status=0;
        foreach(($http_response_header??[]) as $header){if(preg_match('/^HTTP\/\S+\s+(\d{3})/i',$header,$m))$status=(int)$m[1];}
        if(is_string($raw)&&$raw!==''&&($status===0||($status>=200&&$status<300)))return $raw;
        $errors[]='stream '.($status?:'transport');
    }
    throw new RuntimeException('The CRA payroll release page could not be reached from this server.');
}

function platform_plain_text(string $html): string
{
    return trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
}

function platform_canada_absolute_url(string $href,string $baseUrl): ?string
{
    $href=trim(html_entity_decode($href,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if($href===''||str_starts_with($href,'#')||str_starts_with(strtolower($href),'javascript:'))return null;
    if(preg_match('#^https?://#i',$href)){
        $host=strtolower((string)parse_url($href,PHP_URL_HOST));
        return ($host==='canada.ca'||str_ends_with($host,'.canada.ca'))?$href:null;
    }
    $parts=parse_url($baseUrl);$scheme=(string)($parts['scheme']??'https');$host=(string)($parts['host']??'www.canada.ca');
    if($href[0]==='/')return $scheme.'://'.$host.$href;
    $path=(string)($parts['path']??'/');$dir=rtrim(str_replace('\\','/',dirname($path)),'/');
    $dir=$dir===''?'':'/'.ltrim($dir,'/');
    return $scheme.'://'.$host.$dir.'/'.ltrim($href,'/');
}

/** @return array{effectiveDate:string,releaseLabel:string,sourceUrl:string,contentHash:string} */
function platform_t4127_release_from_index(string $html,string $indexUrl): array
{
    $candidates=[];
    if(preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu',$html,$matches,PREG_SET_ORDER)){
        foreach($matches as $match){
            $text=platform_plain_text((string)$match[2]);
            if(stripos($text,'Payroll Deductions Formulas')===false)continue;
            if(!preg_match('/Effective\s+(January|July)\s+1(?:st)?\s*,?\s*(20\d{2})/iu',$text,$dateMatch))continue;
            $month=strcasecmp((string)$dateMatch[1],'July')===0?'07':'01';$effective=(string)$dateMatch[2].'-'.$month.'-01';
            $url=platform_canada_absolute_url((string)$match[1],$indexUrl)??$indexUrl;
            $label=$text;
            if(preg_match('/(\d{2,3})(?:st|nd|rd|th)\s+Edition/iu',$text,$edition))$label='Payroll Deductions Formulas '.$edition[1].ordinal_suffix((int)$edition[1]).' Edition';
            $candidates[]=['effectiveDate'=>$effective,'releaseLabel'=>$label,'sourceUrl'=>$url];
        }
    }
    if($candidates===[]){
        $plain=platform_plain_text($html);
        if(stripos($plain,'T4127 Payroll Deductions Formulas')===false&&stripos($plain,'Payroll Deductions Formulas')===false)throw new RuntimeException('The CRA payroll release page could not be verified.');
        if(!preg_match_all('/Effective\s+(January|July)\s+1(?:st)?\s*,?\s*(20\d{2})/iu',$plain,$dates,PREG_SET_ORDER))throw new RuntimeException('The CRA payroll release effective date could not be verified.');
        foreach($dates as $dateMatch){$month=strcasecmp((string)$dateMatch[1],'July')===0?'07':'01';$candidates[]=['effectiveDate'=>(string)$dateMatch[2].'-'.$month.'-01','releaseLabel'=>'T4127 Payroll Deductions Formulas','sourceUrl'=>$indexUrl];}
    }
    usort($candidates,static fn(array $a,array $b):int=>strcmp($b['effectiveDate'],$a['effectiveDate']));
    $release=$candidates[0];
    $release['contentHash']=hash('sha256',implode('|',[$release['effectiveDate'],$release['releaseLabel'],$release['sourceUrl']]));
    return $release;
}

function ordinal_suffix(int $number): string
{
    $mod100=$number%100;if($mod100>=11&&$mod100<=13)return 'th';
    return match($number%10){1=>'st',2=>'nd',3=>'rd',default=>'th'};
}

/** @return array{effectiveDate:string,releaseLabel:string,sourceUrl:string,contentHash:string}|null */
function platform_bundled_verified_t4127_release(): ?array
{
    $year=(int)gmdate('Y');
    if($year!==2026)return null;
    $effective='2026-07-01';
    $label='Payroll Deductions Formulas 123rd Edition';
    $url='https://www.canada.ca/en/revenue-agency/services/forms-publications/payroll/t4127-payroll-deductions-formulas/t4127-jul/t4127-jul-payroll-deductions-formulas.html';
    return ['effectiveDate'=>$effective,'releaseLabel'=>$label,'sourceUrl'=>$url,'contentHash'=>hash('sha256','bundled-verified|'.$effective.'|'.$label.'|'.$url)];
}

/** Older routes now delegate to the central owner service; no tenant rate upserts. */
function platform_check_official_rates(array $user,array $company,bool $force=false): array
{
    payroll_rate_require_owner($user);
    if (!$force) return ['status'=>'not_due','message'=>'Use Platform Owner → Payroll updates to check the central catalogue.','ratesChanged'=>false];
    $state=payroll_rate_check($user);
    return ($state['lastCheck'] ?? ['status'=>'unavailable']) + ['ratesChanged'=>false,'recordsApplied'=>0];
}

function platform_rate_check(array $user,array $company): never
{
    require_method('POST');require_csrf();
    $result=platform_check_official_rates($user,$company,true);
    json_response(['rateCheck'=>$result]);
}

function platform_background_maintenance(): void
{
    // Company sessions must not approve or apply platform-wide statutory rates.
    // The explicit Platform Owner check records discoveries without accounting writes.
}


function platform_maintenance_pulse(array $user, array $company): never
{
    require_method('POST');
    require_csrf();

    $purged = [];
    if (function_exists('purge_expired_test_companies')) {
        try {
            $purged = purge_expired_test_companies((string)$user['id']);
        } catch (Throwable $error) {
            record_system_incident('Expired Test Mode company cleanup did not complete.',500,'test_cleanup_failed',$error,['source'=>'background_error','route'=>'platform/maintenance','companyId'=>$company['id']]);
            error_log('Tegh test cleanup warning request=' . request_id() . ' ' . $error->getMessage());
        }
    }

    $rateResult = ['status' => 'not_applicable'];
    $role = (string)($company['role'] ?? '');
    $mode = (string)($company['module_mode'] ?? $company['moduleMode'] ?? 'both');
    if (platform_role_for_user((string)$user['id']) === 'platform_owner' && in_array($mode, ['payroll', 'both'], true)) {
        try {
            $rateResult = platform_check_official_rates($user, $company, false);
        } catch (Throwable $error) {
            $rateResult = ['status' => 'unavailable'];
            record_system_incident('A background payroll-rate check did not complete.',502,'background_rate_check_failed',$error,['source'=>'background_error','route'=>'platform/maintenance','companyId'=>$company['id']]);
            error_log('Tegh background rate warning request=' . request_id() . ' ' . $error->getMessage());
        }
    }

    json_response([
        'ok' => true,
        'expiredTestCompaniesRemoved' => count($purged),
        'companyDeletionTransition' => $purged ? ['deleted'=>true,'notice'=>'Expired Test Mode company removed. Fresh authorization is required.'] + tegh_deletion_transition_payload($user,array_map(static fn($r)=>is_array($r)?(string)($r['id']??$r['companyId']??''):(string)$r,$purged)) : null,
        'rateCheck' => $rateResult,
    ]);
}

function handle_platform(string $action): never
{
    if($action==='invite-details')tegh_handle_invite_details();
    if($action==='invite-accept')tegh_handle_invite_accept();
    if($action==='invitations')tegh_handle_invitations();
    if($action==='registration')tegh_handle_registration_control();
    if($action==='email-health')tegh_handle_email_health();
    if ($action==='payroll-rates' || str_starts_with($action,'payroll-rates/')) handle_platform_payroll_rates($action);
    $user=require_user();$company=require_company($user);
    if($action==='notifications')platform_notifications($user,$company);
    if($action==='invitations')platform_invitations($user,$company);
    if($action==='members')platform_members($user,$company);
    if($action==='mail-status')platform_mail_status($user,$company);
    if($action==='vouchers')platform_vouchers($company);
    if($action==='customer-ledger')platform_customer_ledger($company);
    if($action==='vendor-ledger')platform_vendor_ledger($company);
    if($action==='integrity')platform_integrity($user,$company);
    if($action==='rate-check')platform_rate_check($user,$company);
    if($action==='maintenance')platform_maintenance_pulse($user,$company);
    fail('Platform route not found.',404,'route_not_found');
}
