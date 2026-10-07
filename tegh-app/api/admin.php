<?php
declare(strict_types=1);

/** Platform administration that is deliberately independent of company access. */

function admin_require_platform_owner(array $user): void
{
    if (platform_role_for_user((string)$user['id']) !== 'platform_owner') {
        fail('Platform owner access is required.', 403, 'platform_owner_required');
    }
}

function platform_audit_event(array $user,string $action,string $targetType,string $targetId,array $metadata=[]): void
{
    if (!schema_table_exists('platform_audit_log')) return;
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    db()->prepare('INSERT INTO platform_audit_log
        (id,actor_user_id,actor_email,action,target_type,target_id,metadata_json,request_id,ip_hash,user_agent_hash)
        VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([
            new_id('platformaudit'),$user['id'],$user['email'],$action,$targetType,$targetId,$json,
            request_id(),client_ip_hash(),user_agent_hash(),
        ]);
}

function admin_require_destructive_confirmation(array $user,array $input,string $expected,string $label): string
{
    $typed=clean_text($input['confirmationText']??'',$label,254);
    if(!hash_equals($expected,$typed)) fail('Type '.$label.' exactly as shown.',409,'admin_confirmation_mismatch');
    operations_owner_password($user,(string)($input['password']??''));
    $reason=clean_text($input['reason']??'','Override reason',1000);
    if(mb_strlen($reason)<12) fail('Provide a clear override reason of at least 12 characters.',422,'override_reason_required');
    if(empty($input['overrideConfirmed'])||empty($input['dataLossAccepted'])) {
        fail('Confirm both the platform-owner override and permanent-data warning.',409,'override_confirmation_required');
    }
    return $reason;
}

function admin_company_directory(array $user): never
{
    admin_require_platform_owner($user);
    if(request_method()==='GET'){
        $stmt=db()->prepare("SELECT c.id,c.name,c.legal_name,c.business_type,c.province,c.currency,c.module_mode,c.test_mode,c.active,c.created_at,
            (SELECT COUNT(*) FROM company_members cm WHERE cm.company_id=c.id AND cm.status='active') AS member_count,
            (SELECT COUNT(*) FROM company_members cm WHERE cm.company_id=c.id AND cm.role='owner' AND cm.status='active') AS owner_count,
            (SELECT GROUP_CONCAT(u.email ORDER BY u.email SEPARATOR ', ') FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=c.id AND cm.role='owner' AND cm.status='active') AS owner_emails,
            (SELECT cm.role FROM company_members cm WHERE cm.company_id=c.id AND cm.user_id=? AND cm.status='active' LIMIT 1) AS access_role
            FROM companies c ORDER BY c.active DESC,c.created_at DESC,c.name");
        $stmt->execute([$user['id']]);
        $companies=array_map(static fn(array $r):array=>[
            'id'=>(string)$r['id'],'name'=>(string)$r['name'],'legalName'=>(string)$r['legal_name'],
            'businessType'=>(string)$r['business_type'],'province'=>(string)$r['province'],'currency'=>(string)$r['currency'],
            'moduleMode'=>(string)$r['module_mode'],'testMode'=>(bool)$r['test_mode'],'active'=>(bool)$r['active'],
            'memberCount'=>(int)$r['member_count'],'ownerCount'=>(int)$r['owner_count'],
            'ownerEmails'=>$r['owner_emails']!==null?(string)$r['owner_emails']:'',
            'accessRole'=>$r['access_role']!==null?(string)$r['access_role']:null,
            'createdAt'=>(string)$r['created_at'],
        ],$stmt->fetchAll());
        json_response(['companies'=>$companies,'accessPolicy'=>'directory_only']);
    }

    require_method('POST');require_csrf();$input=request_json();
    if((string)($input['action']??'')!=='delete_company_override') fail('Company administration action is invalid.',422,'admin_action_invalid');
    $companyId=clean_text($input['companyId']??'','Company',64);
    $lookup=db()->prepare('SELECT id,name FROM companies WHERE id=? LIMIT 1');$lookup->execute([$companyId]);
    $current=$lookup->fetch();if(!$current)fail('The selected company is already unavailable.',404,'company_not_found');
    $reason=admin_require_destructive_confirmation($user,$input,(string)$current['name'],'company name');
    $storage=private_storage_root().'/'.$companyId;
    operations_company_delete_assert_schema_safe($user,$companyId);

    $result=db_transaction_retry(function()use($user,$companyId,$current,$reason):array{
        $lock=db()->prepare('SELECT id,name FROM companies WHERE id=? FOR UPDATE');$lock->execute([$companyId]);
        $company=$lock->fetch();if(!$company)fail('The selected company is already unavailable.',409,'company_already_deleted');
        if(!hash_equals((string)$company['name'],(string)$current['name'])) fail('The company name changed. Reload the directory and confirm again.',409,'company_changed');
        $summary=operations_company_record_summary($companyId);
        $deletedRows=operations_delete_company_rows($companyId);
        $delete=db()->prepare('DELETE FROM companies WHERE id=?');$delete->execute([$companyId]);
        if($delete->rowCount()!==1)throw new RuntimeException('The company row was not deleted.');
        db()->prepare('INSERT INTO company_deletion_log
            (id,deleted_company_id,company_name,deleted_by,backup_confirmed,record_summary_json) VALUES (?,?,?,?,0,?)')->execute([
            new_id('companydelete'),$companyId,$company['name'],$user['id'],
            json_encode(['platformOwnerOverride'=>true,'reason'=>$reason,'recordsBeforeDeletion'=>$summary,'rowsDeletedExplicitly'=>$deletedRows,'requestId'=>request_id()],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
        ]);
        platform_audit_event($user,'platform.company_deleted_override','company',$companyId,[
            'companyName'=>$company['name'],'reason'=>$reason,'recordsBeforeDeletion'=>$summary,'permanentDataLossAccepted'=>true,
        ]);
        return ['summary'=>$summary];
    });
    $cleanup=operations_remove_company_storage($storage);
    json_response(tegh_deletion_transition_payload($user,[$companyId])+['deleted'=>true,'companyId'=>$companyId,'storageCleanup'=>$cleanup['status'],'storageCleanupNeedsAttention'=>$cleanup['status']==='pending','recordSummary'=>$result['summary']]);
}

function admin_users(array $user): never
{
    admin_require_platform_owner($user);
    if(request_method()==='GET'){
        $stmt=db()->query("SELECT u.id,u.email,u.display_name,u.active,u.platform_role,u.signup_source,u.created_at,u.last_login_at,u.deleted_at,
            COUNT(DISTINCT cm.company_id) company_count,
            COALESCE(SUM(cm.role='owner' AND cm.status='active'),0) owner_company_count,
            COALESCE(SUM(cm.role='admin' AND cm.status='active'),0) admin_company_count,
            COALESCE(SUM(cm.role='editor' AND cm.status='active'),0) editor_company_count,
            COALESCE(SUM(cm.role='viewer' AND cm.status='active'),0) viewer_company_count
            FROM users u LEFT JOIN company_members cm ON cm.user_id=u.id
            GROUP BY u.id,u.email,u.display_name,u.active,u.platform_role,u.signup_source,u.created_at,u.last_login_at,u.deleted_at
            ORDER BY (u.platform_role='platform_owner') DESC,u.active DESC,u.created_at DESC");
        $rows=array_map(static fn(array $r):array=>[
            'id'=>(string)$r['id'],'email'=>(string)$r['email'],'displayName'=>(string)$r['display_name'],'active'=>(bool)$r['active'],
            'platformRole'=>(string)$r['platform_role'],'signupSource'=>(string)$r['signup_source'],'createdAt'=>(string)$r['created_at'],
            'lastLoginAt'=>$r['last_login_at'],'deletedAt'=>$r['deleted_at'],'companyCount'=>(int)$r['company_count'],
            'ownerCompanyCount'=>(int)$r['owner_company_count'],'adminCompanyCount'=>(int)$r['admin_company_count'],'editorCompanyCount'=>(int)$r['editor_company_count'],'viewerCompanyCount'=>(int)$r['viewer_company_count'],
        ],$stmt->fetchAll());
        json_response(['users'=>$rows]);
    }

    require_method('POST');require_csrf();$input=request_json();$action=(string)($input['action']??'');
    $targetId=clean_text($input['userId']??'','User',64);
    if($targetId===(string)$user['id'])fail('Your own platform-owner login cannot be deactivated or deleted.',409,'self_admin_action_blocked');
    $lookup=db()->prepare('SELECT id,email,display_name,active,platform_role,deleted_at FROM users WHERE id=? LIMIT 1');$lookup->execute([$targetId]);
    $target=$lookup->fetch();if(!$target)fail('The selected user no longer exists.',404,'user_not_found');
    if((string)$target['platform_role']==='platform_owner')fail('Another platform-owner account cannot be changed here.',409,'platform_owner_protected');

    if($action==='deactivate'||$action==='reactivate'){
        $active=$action==='reactivate';
        if($active&&$target['deleted_at']!==null)fail('A deleted login cannot be reactivated. Create a new account setup for the person.',409,'deleted_login');
        db_transaction_retry(function()use($user,$targetId,$target,$active,$action):bool{
            db()->prepare('UPDATE users SET active=? WHERE id=?')->execute([$active?1:0,$targetId]);
            if(!$active)db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$targetId]);
            platform_audit_event($user,$active?'platform.user_reactivated':'platform.user_deactivated','user',$targetId,['email'=>$target['email']]);
            return true;
        });
        json_response(['updated'=>true,'active'=>$active]);
    }

    if(!in_array($action,['delete_login','delete_login_override'],true))fail('User administration action is invalid.',422,'admin_action_invalid');
    $reason=admin_require_destructive_confirmation($user,$input,(string)$target['email'],'email address');
    $override=$action==='delete_login_override';
    $ownedCountStmt=db()->prepare("SELECT COUNT(*) FROM company_members WHERE user_id=? AND role='owner' AND status='active'");$ownedCountStmt->execute([$targetId]);
    $ownedCount=(int)$ownedCountStmt->fetchColumn();
    if(!$override&&$ownedCount>0)fail('This login owns one or more companies. Use the audited platform-owner override if permanent deletion is intended.',409,'owned_companies_require_override');
    if($override&&$ownedCount>0){
        $ownedCompanyStmt=db()->prepare("SELECT company_id FROM company_members WHERE user_id=? AND role='owner' AND status='active' ORDER BY company_id LIMIT 1");
        $ownedCompanyStmt->execute([$targetId]);
        $ownedCompanyId=(string)($ownedCompanyStmt->fetchColumn()?:'');
        if($ownedCompanyId!=='')operations_company_delete_assert_schema_safe($user,$ownedCompanyId);
    }

    $result=db_transaction_retry(function()use($user,$targetId,$target,$reason,$override):array{
        $lock=db()->prepare('SELECT id,email,platform_role FROM users WHERE id=? FOR UPDATE');$lock->execute([$targetId]);
        $locked=$lock->fetch();if(!$locked)fail('The selected user no longer exists.',404,'user_not_found');
        if((string)$locked['platform_role']==='platform_owner')fail('A platform-owner account cannot be deleted here.',409,'platform_owner_protected');
        $deletedCompanies=[];$retainedCompanies=[];
        if($override){
            $owned=db()->prepare("SELECT c.id,c.name FROM companies c JOIN company_members cm ON cm.company_id=c.id WHERE cm.user_id=? AND cm.role='owner' AND cm.status='active' ORDER BY c.id FOR UPDATE");
            $owned->execute([$targetId]);
            foreach($owned->fetchAll() as $company){
                $otherOwners=db()->prepare("SELECT COUNT(*) FROM company_members WHERE company_id=? AND role='owner' AND status='active' AND user_id<>?");
                $otherOwners->execute([$company['id'],$targetId]);
                if((int)$otherOwners->fetchColumn()>0){
                    db()->prepare('DELETE FROM company_members WHERE company_id=? AND user_id=?')->execute([$company['id'],$targetId]);
                    $retainedCompanies[]=['id'=>(string)$company['id'],'name'=>(string)$company['name']];
                    continue;
                }
                $summary=operations_company_record_summary((string)$company['id']);
                operations_delete_company_rows((string)$company['id']);
                db()->prepare('DELETE FROM companies WHERE id=?')->execute([$company['id']]);
                db()->prepare('INSERT INTO company_deletion_log
                    (id,deleted_company_id,company_name,deleted_by,backup_confirmed,record_summary_json) VALUES (?,?,?,?,0,?)')->execute([
                    new_id('companydelete'),$company['id'],$company['name'],$user['id'],
                    json_encode(['platformOwnerLoginDeletionOverride'=>true,'deletedLoginId'=>$targetId,'reason'=>$reason,'recordsBeforeDeletion'=>$summary,'requestId'=>request_id()],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                ]);
                $deletedCompanies[]=['id'=>(string)$company['id'],'name'=>(string)$company['name'],'summary'=>$summary];
            }
        }
        db()->prepare('DELETE FROM company_members WHERE user_id=?')->execute([$targetId]);
        db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$targetId]);
        $anon='deleted+'.substr(hash('sha256',$targetId),0,24).'@invalid.local';
        $randomHash=password_hash(base64url_encode(random_bytes(32)),password_algorithm());
        if(!is_string($randomHash))throw new RuntimeException('Password hashing is unavailable.');
        db()->prepare("UPDATE users SET email=?,display_name='Deleted user',password_hash=?,active=0,deleted_at=UTC_TIMESTAMP(),deleted_by=? WHERE id=?")
            ->execute([$anon,$randomHash,$user['id'],$targetId]);
        // R156: a deletion request also removes the person's email from invitations, sent-email records, the platform log
        // and the incident log. Company audit history keeps it while that company exists: the email is part of each
        // entry's tamper-evident hash. Only a hash of the old address is kept as the record of the deletion.
        admin_scrub_deleted_email((string)$target['email'],$anon);
        platform_audit_event($user,$override?'platform.user_login_deleted_override':'platform.user_login_deleted','user',$targetId,[
            'previousEmailSha256'=>hash('sha256',strtolower(trim((string)$target['email']))),'reason'=>$reason,'override'=>$override,'deletedCompanies'=>array_map(static fn(array $c):array=>['id'=>$c['id'],'name'=>$c['name']],$deletedCompanies),
            'retainedCompanies'=>$retainedCompanies,'auditHistoryRetained'=>true,'permanentDataLossAccepted'=>true,
        ]);
        return ['deletedCompanies'=>$deletedCompanies,'retainedCompanies'=>$retainedCompanies];
    });
    $storageCleanup=[];
    foreach($result['deletedCompanies'] as $company){
        $cleanup=operations_remove_company_storage(private_storage_root().'/'.$company['id']);
        $storageCleanup[]=['companyId'=>$company['id'],'status'=>$cleanup['status']];
    }
    json_response(tegh_deletion_transition_payload($user,array_column($result['deletedCompanies'],'id'))+['deleted'=>true,'override'=>$override,'auditHistoryRetained'=>true,'deletedCompanies'=>$result['deletedCompanies'],'retainedCompanies'=>$result['retainedCompanies'],'storageCleanup'=>$storageCleanup]);
}

function admin_scrub_deleted_email(string $old,string $anon): void
{
    $old=trim($old);if($old==='')return;
    foreach([['account_invitations','email'],['company_invitations','email'],['outbound_emails','recipient'],['platform_audit_log','actor_email'],['platform_incident_log','user_email'],['native_agent_collection_drafts','recipient_email']] as [$table,$column]){
        if(!schema_table_exists($table)||!schema_column_exists($table,$column))continue;
        db()->prepare("UPDATE `$table` SET `$column`=? WHERE LOWER(`$column`)=LOWER(?)")->execute([$anon,$old]);
    }
    // Earlier platform-log entries can carry the address inside their details (for example an invitation that was sent).
    if(schema_table_exists('platform_audit_log'))db()->prepare('UPDATE platform_audit_log SET metadata_json=REPLACE(metadata_json,?,?) WHERE metadata_json LIKE ?')->execute([$old,$anon,'%'.$old.'%']);
    if(schema_table_exists('platform_incident_log'))db()->prepare('UPDATE platform_incident_log SET context_json=REPLACE(context_json,?,?) WHERE context_json LIKE ?')->execute([$old,$anon,'%'.$old.'%']);
}

function admin_incident_api_row(array $row, bool $detail = false): array
{
    $context = [];
    try {
        $decoded = json_decode((string)($row['context_json'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) $context = $decoded;
    } catch (Throwable) {}
    $item = [
        'id'=>(string)$row['id'],'occurredAt'=>(string)($row['occurred_at'] ?? $row['created_at'] ?? ''),
        'severity'=>(string)($row['severity'] ?? 'info'),'source'=>(string)($row['source'] ?? 'request_rejected'),
        'route'=>(string)($row['route'] ?? ''),'method'=>(string)($row['method'] ?? ''),
        'httpStatus'=>isset($row['http_status'])&&$row['http_status']!==null?(int)$row['http_status']:null,
        'errorCode'=>(string)($row['error_code'] ?? ''),'publicMessage'=>(string)($row['public_message'] ?? ''),
        'requestId'=>(string)($row['request_id'] ?? ''),'userId'=>$row['user_id']??null,'userEmail'=>$row['user_email']??null,
        'companyId'=>$row['company_id']??null,'companyName'=>$row['company_name']??null,
        'exceptionClass'=>$row['exception_class']??null,
    ];
    if ($detail) {
        $item += [
            'internalMessage'=>(string)($row['internal_message'] ?? ''),'exceptionFile'=>$row['exception_file']??null,
            'exceptionLine'=>isset($row['exception_line'])&&$row['exception_line']!==null?(int)$row['exception_line']:null,
            'stackTrace'=>(string)($row['stack_trace'] ?? ''),'ipHash'=>(string)($row['ip_hash'] ?? ''),
            'userAgentHash'=>(string)($row['user_agent_hash'] ?? ''),'context'=>$context,
            'immutable'=>true,
        ];
    }
    return $item;
}

function admin_legacy_client_incident(array $row): array
{
    $status = $row['status'] === null ? 0 : (int)$row['status'];
    $context = [
        'kind'=>(string)$row['kind'],'durationMs'=>$row['duration_ms']===null?null:(int)$row['duration_ms'],
        'pagePath'=>$row['page_path'],'legacyClientTelemetry'=>true,
    ];
    return [
        'id'=>(string)$row['id'],'occurred_at'=>(string)$row['occurred_at'],
        'severity'=>$status>=500?'error':'warning','source'=>'legacy_client_error','route'=>(string)($row['route']??''),
        'method'=>(string)($row['method']??''),'http_status'=>$row['status'],'error_code'=>(string)($row['code']??'client_error'),
        'public_message'=>'A browser-side application error was recorded.','internal_message'=>(string)$row['message'],
        'exception_class'=>null,'exception_file'=>null,'exception_line'=>null,'stack_trace'=>null,'request_id'=>(string)($row['request_id']??''),
        'user_id'=>$row['user_id'],'user_email'=>$row['user_email']??null,'company_id'=>$row['company_id'],'company_name'=>$row['company_name']??null,
        'ip_hash'=>'','user_agent_hash'=>'','context_json'=>json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}',
        'created_at'=>(string)$row['created_at'],
    ];
}

function admin_user_report_incident(array $row): array
{
    $severity = ['low'=>'info','medium'=>'warning','high'=>'error','critical'=>'critical'][(string)$row['severity']] ?? 'warning';
    $context = [];
    try {$decoded=json_decode((string)$row['context_json'],true,64,JSON_THROW_ON_ERROR);if(is_array($decoded))$context=$decoded;}catch(Throwable){}
    $context += [
        'reportStatus'=>(string)$row['status'],'reporterName'=>(string)($row['reporter_name']??''),
        'stepsToReproduce'=>(string)$row['steps_to_reproduce'],'expectedBehavior'=>(string)$row['expected_behavior'],
        'actualBehavior'=>(string)$row['actual_behavior'],'resolutionNote'=>(string)($row['resolution_note']??''),
        'errorCount'=>(int)$row['error_count'],'submittedIncident'=>true,
    ];
    return [
        'id'=>(string)$row['id'],'occurred_at'=>(string)$row['created_at'],'severity'=>$severity,'source'=>'user_report',
        'route'=>'agent/incidents','method'=>'POST','http_status'=>null,'error_code'=>'reported_'.(string)$row['status'],
        'public_message'=>(string)$row['title'],'internal_message'=>(string)$row['description'],
        'exception_class'=>null,'exception_file'=>null,'exception_line'=>null,'stack_trace'=>null,'request_id'=>'',
        'user_id'=>$row['user_id'],'user_email'=>$row['user_email']??null,'company_id'=>$row['company_id'],'company_name'=>$row['company_name']??null,
        'ip_hash'=>'','user_agent_hash'=>'','context_json'=>json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}',
        'created_at'=>(string)$row['created_at'],
    ];
}

function admin_collect_incidents(?string $incidentId = null): array
{
    $items = [];
    foreach (system_incident_private_events($incidentId === null ? 750 : 2000) as $row) {
        if ($incidentId === null || hash_equals($incidentId,(string)$row['id'])) $items[(string)$row['id']]=$row;
    }
    if (schema_table_exists('platform_incident_log')) {
        if ($incidentId === null) {
            $rows=db()->query('SELECT * FROM platform_incident_log ORDER BY occurred_at DESC,id DESC LIMIT 750')->fetchAll();
        } else {
            $stmt=db()->prepare('SELECT * FROM platform_incident_log WHERE id=? LIMIT 1');$stmt->execute([$incidentId]);$row=$stmt->fetch();$rows=$row?[$row]:[];
        }
        foreach($rows as $row)$items[(string)$row['id']]=$row;
    }
    if (schema_table_exists('client_error_events')) {
        $base="SELECT ce.*,u.email AS user_email,c.name AS company_name FROM client_error_events ce LEFT JOIN users u ON u.id=ce.user_id LEFT JOIN companies c ON c.id=ce.company_id";
        if($incidentId===null){$rows=db()->query($base.' ORDER BY ce.occurred_at DESC,ce.created_at DESC LIMIT 250')->fetchAll();}
        else{$stmt=db()->prepare($base.' WHERE ce.id=? LIMIT 1');$stmt->execute([$incidentId]);$row=$stmt->fetch();$rows=$row?[$row]:[];}
        foreach($rows as $row){$mapped=admin_legacy_client_incident($row);$items[(string)$mapped['id']]=$mapped;}
    }
    if (schema_table_exists('ai_agent_incidents')) {
        $base="SELECT i.*,u.email AS user_email,u.display_name AS reporter_name,c.name AS company_name FROM ai_agent_incidents i LEFT JOIN users u ON u.id=i.user_id LEFT JOIN companies c ON c.id=i.company_id";
        if($incidentId===null){$rows=db()->query($base.' ORDER BY i.created_at DESC LIMIT 250')->fetchAll();}
        else{$stmt=db()->prepare($base.' WHERE i.id=? LIMIT 1');$stmt->execute([$incidentId]);$row=$stmt->fetch();$rows=$row?[$row]:[];}
        foreach($rows as $row){$mapped=admin_user_report_incident($row);$items[(string)$mapped['id']]=$mapped;}
    }
    $rows=array_values($items);
    usort($rows,static fn(array $a,array $b):int=>[(string)($b['occurred_at']??''),(string)$b['id']] <=> [(string)($a['occurred_at']??''),(string)$a['id']]);
    return $rows;
}

function admin_system_incidents(array $user): never
{
    admin_require_platform_owner($user);
    require_method('GET');
    $incidentId=trim((string)($_GET['incidentId']??''));
    if($incidentId!==''){
        if(!preg_match('/^[A-Za-z0-9_-]{8,80}$/',$incidentId))fail('Incident reference is invalid.',422,'incident_reference_invalid');
        $rows=admin_collect_incidents($incidentId);
        if(!$rows)fail('The incident was not found.',404,'system_incident_not_found');
        json_response(['incident'=>admin_incident_api_row($rows[0],true),'accessPolicy'=>'platform_owner_only','immutable'=>true]);
    }
    $severity=(string)($_GET['severity']??'');if($severity!==''&&!in_array($severity,['info','warning','error','critical'],true))fail('Incident severity filter is invalid.',422,'incident_filter_invalid');
    $source=preg_replace('/[^a-z0-9_-]/i','',(string)($_GET['source']??''))?:'';
    $query=mb_strtolower(trim((string)($_GET['q']??'')));
    $limit=max(20,min(200,(int)($_GET['limit']??100)));
    $all=admin_collect_incidents();
    $counts=['all'=>count($all),'info'=>0,'warning'=>0,'error'=>0,'critical'=>0];$sources=[];
    foreach($all as $row){$level=(string)($row['severity']??'info');if(isset($counts[$level]))$counts[$level]++;$sources[(string)($row['source']??'unknown')]=true;}
    $filtered=array_values(array_filter($all,static function(array $row)use($severity,$source,$query):bool{
        if($severity!==''&&(string)$row['severity']!==$severity)return false;
        if($source!==''&&(string)$row['source']!==$source)return false;
        if($query==='')return true;
        $haystack=mb_strtolower(implode(' ',array_map(static fn($v):string=>is_scalar($v)?(string)$v:'',[
            $row['id']??'',$row['route']??'',$row['error_code']??'',$row['public_message']??'',$row['internal_message']??'',
            $row['request_id']??'',$row['user_email']??'',$row['company_name']??'',$row['exception_class']??'',
        ])));
        return str_contains($haystack,$query);
    }));
    $totalMatched=count($filtered);
    $filtered=array_slice($filtered,0,$limit);
    $sourceList=array_keys($sources);sort($sourceList,SORT_STRING);
    json_response([
        'incidents'=>array_map(static fn(array $row):array=>admin_incident_api_row($row),$filtered),
        'counts'=>$counts,'sources'=>$sourceList,'totalMatched'=>$totalMatched,'accessPolicy'=>'platform_owner_only','immutable'=>true,
    ]);
}

function handle_admin(string $action): never
{
    $user=require_user();
    admin_require_platform_owner($user);
    if($action==='users')admin_users($user);
    if($action==='companies')admin_company_directory($user);
    if($action==='incidents')admin_system_incidents($user);
    fail('Administration route not found.',404,'route_not_found');
}
