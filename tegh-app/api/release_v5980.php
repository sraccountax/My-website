<?php
declare(strict_types=1);

/** Release controls are deliberately not customer-configurable. */
function tegh_connected_release_enabled(): bool { return false; }
const TEGH_BANK_READY_CONFIDENCE = 90;
const TEGH_CATEGORY_WORKBOOK_LIMIT = 5000;
const TEGH_REPORT_OUTPUT_LIMIT = 25000;
const TEGH_TERMS_VERSION = '2026-09-09';
const TEGH_PRIVACY_VERSION = '2026-09-09';

final class TeghServiceFailure extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus=422, public readonly string $errorCode='validation_failed') { parent::__construct($message); }
}
function tegh_service_boundary(callable $work): mixed
{
    $previous=$GLOBALS['tegh_service_exception_boundary']??false;
    $GLOBALS['tegh_service_exception_boundary']=true;
    try { return $work(); } finally { $GLOBALS['tegh_service_exception_boundary']=$previous; }
}
function tegh_fail_service(Throwable $error): never
{
    if($error instanceof TeghServiceFailure)fail($error->getMessage(),$error->httpStatus,$error->errorCode);
    throw $error;
}
function tegh_json_canonical(mixed $value): string
{
    $sort=function(mixed $v)use(&$sort):mixed { if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as &$x)$x=$sort($x);unset($x);return $v; };
    return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function tegh_provider_feature(string $key): bool
{
    return $key==='tegh.ai.advanced'||preg_match('/(?:^|\.)(?:connected|provider|research)(?:\.|$)/i',$key)===1;
}
function tegh_effective_public_registration(): bool
{
    // An absent/unreadable deployment setting is an emergency force-off.
    if(config('app.public_signup_enabled')!==true)return false;
    try {
        $s=db()->prepare("SELECT value_json FROM platform_release_settings WHERE setting_key='public_registration'");$s->execute();
        return $s->fetchColumn()==='true';
    } catch(Throwable) { return false; }
}
function tegh_release_registration_payload(): array
{
    $setting=false;$revision=0;
    $s=db()->query("SELECT value_json,revision FROM platform_release_settings WHERE setting_key='public_registration'");
    if($r=$s->fetch()){$setting=$r['value_json']==='true';$revision=(int)$r['revision'];}
    $deployment=config('app.public_signup_enabled')===true;
    return ['publicSignupEnabled'=>$deployment&&$setting,'ownerSettingEnabled'=>$setting,'deploymentAllowsPublicSignup'=>$deployment,'revision'=>$revision,'mode'=>$deployment&&$setting?'Public account creation enabled':'Invitation only'];
}
function tegh_handle_registration_control(): never
{
    require_method('GET','POST');$user=require_user();admin_require_platform_owner($user);tegh_schema44_require();
    if(request_method()==='GET'){header('Cache-Control: no-store');json_response(tegh_release_registration_payload());}
    require_csrf();$input=request_json();
    if(!array_key_exists('enabled',$input)||!is_bool($input['enabled']))fail('Choose a registration mode.',422,'registration_mode_invalid');
    if(($input['confirmation']??'')!=='CHANGE REGISTRATION')fail('Confirm the registration change.',409,'confirmation_required');
    $reason=trim(clean_text($input['reason']??'','Reason',1000));if(strlen($reason)<8)fail('Provide a reason of at least 8 characters.',422,'reason_required');
    $expected=(int)($input['revision']??-1);$desired=$input['enabled']?'true':'false';
    try { $result=tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$expected,$desired,$reason):array{
        $s=db()->query("SELECT value_json,revision FROM platform_release_settings WHERE setting_key='public_registration' FOR UPDATE");$r=$s->fetch();
        if(!$r||(int)$r['revision']!==$expected)fail('The registration setting changed. Reload and review it.',409,'registration_revision_conflict');
        if($r['value_json']!==$desired){
            db()->prepare("UPDATE platform_release_settings SET value_json=?,revision=revision+1,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE setting_key='public_registration'")->execute([$desired,$user['id']]);
            db()->prepare('INSERT INTO platform_release_setting_history (id,setting_key,old_value,new_value,actor_user_id,reason,request_reference) VALUES (?,?,?,?,?,?,?)')->execute([new_id('settinghistory'),'public_registration',$r['value_json'],$desired,$user['id'],$reason,request_id()]);
            platform_audit_event($user,'platform.registration_changed','release_setting','public_registration',['old'=>$r['value_json']==='true','new'=>$desired==='true','reason'=>$reason,'revision'=>$expected+1]);
        }
        return tegh_release_registration_payload();
    })); } catch(Throwable $error) { tegh_fail_service($error); }
    json_response($result);
}
function tegh_record_terms_acceptance(string $userId,?string $invitationId): void
{
    // The invitation contract requires Schema 44. Public registration is closed before that schema exists.
    db()->prepare('INSERT INTO terms_acceptances (id,user_id,invitation_id,terms_version,privacy_version,request_reference,accepted_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')
        ->execute([new_id('terms'),$userId,$invitationId,TEGH_TERMS_VERSION,TEGH_PRIVACY_VERSION,request_id()]);
    db()->prepare('UPDATE users SET terms_accepted_at=COALESCE(terms_accepted_at,UTC_TIMESTAMP()) WHERE id=?')->execute([$userId]);
}
function tegh_native_package_initialize(string $companyId,string $companyName,?array $actor=null): array
{
    $subject=tegh_entitlement_subject('company',null,$companyId,null,$companyName);$changed=0;$preserved=0;
    $features=db()->query("SELECT feature_key FROM feature_catalog WHERE operational_state='active' AND permitted_scope='company' ORDER BY feature_key")->fetchAll(PDO::FETCH_COLUMN);
    foreach($features as $key){
        if(tegh_provider_feature((string)$key))continue;
        $s=db()->prepare('SELECT decision,source,reason,valid_from,valid_until FROM feature_entitlements WHERE subject_id=? AND feature_key=? FOR UPDATE');$s->execute([$subject['id'],$key]);$old=$s->fetch();
        if($old&&(($old['decision']==='enabled'&&$old['valid_from']===null&&$old['valid_until']===null)||$old['source']==='platform_owner'||str_starts_with((string)$old['reason'],'Suspended:'))){$preserved++;continue;}
        tegh_entitlement_set_locked($subject,(string)$key,'enabled','migration',$actor,'Included native package. Existing role, membership, approval and period controls remain authoritative.',hash('sha256','schema44-native|'.$companyId.'|'.$key),'schema44-native-package');$changed++;
    }
    return ['changed'=>$changed,'preserved'=>$preserved];
}
function tegh_disable_stale_connected_policy(array $company,?array $actor=null): void
{
    if(!schema_table_exists('native_agent_policies'))return;
    $owns=!db()->inTransaction();if($owns)db()->beginTransaction();
    try{
        $s=db()->prepare('SELECT * FROM native_agent_policies WHERE company_id=? FOR UPDATE');$s->execute([$company['id']]);$row=$s->fetch();
        if($row&&(int)$row['connected_enabled']!==0){
            $row['connected_enabled']=0;$policy=native_agent_policy_from_row($row);$hash=native_agent_policy_hash($policy);
            db()->prepare('UPDATE native_agent_policies SET connected_enabled=0,policy_hash=?,revision=revision+1 WHERE id=?')->execute([$hash,$row['id']]);
            if($actor)audit_event($actor,(string)$company['id'],'native_agent.connected_disabled_release','native_agent_policy',(string)$row['id'],['beforeEnabled'=>true,'afterEnabled'=>false,'policyHash'=>$hash,'release'=>5980]);
        }
        if($owns)db()->commit();
    }catch(Throwable $error){if($owns&&db()->inTransaction())db()->rollBack();throw $error;}
}
function tegh_deletion_transition_payload(array $user,array $deletedIds): array
{
    $deletedIds=array_values(array_unique(array_map('strval',$deletedIds)));
    $s=db()->prepare("SELECT c.id,c.name FROM company_members cm JOIN companies c ON c.id=cm.company_id WHERE cm.user_id=? AND cm.status='active' AND c.active=1 ORDER BY c.created_at,c.id");$s->execute([$user['id']]);$replacement=null;
    foreach($s->fetchAll() as $r)if(!in_array((string)$r['id'],$deletedIds,true)){$replacement=$r;break;}
    return ['deletedCompanyIds'=>$deletedIds,'replacementCompanyId'=>$replacement?(string)$replacement['id']:null,'replacementCompanyName'=>$replacement?(string)$replacement['name']:null,'freshAuthorizationRequired'=>true];
}
function tegh_spreadsheet_text(mixed $value): string
{
    $text=(string)($value??'');return preg_match('/^[\s\x{FEFF}]*[=+\-@]/u',$text)?"'".$text:$text;
}
function tegh_iso_display_date(string $date): string
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
    if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Invalid ISO date.');
    return $d->format('M j, Y');
}
