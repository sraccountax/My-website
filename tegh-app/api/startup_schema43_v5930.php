<?php
declare(strict_types=1);

/** The current startup dispatcher; older migration contracts remain immutable. */
function tegh_schema43_classify(int $marker, array $schema41, array $schema42, array $schema43): array
{
    $base=['ready'=>false,'retrySafe'=>false,'mutationRequired'=>true];
    if($marker>43)return array_replace($base,['state'=>'newer_schema','stateLabel'=>'This database needs a newer Tegh release','mutationRequired'=>false]);
    if($marker<41||empty($schema41['ready']))return $base+['state'=>'unsupported_prerequisite','stateLabel'=>'Verify the retained Schema 41 contract before this upgrade'];
    if(empty($schema42['ready'])){
        $unsafe=array_filter($schema42['issues']??[],static fn(array $row):bool=>empty($row['repairable']));
        return array_replace($base,['state'=>'schema42_prerequisite_incomplete','stateLabel'=>$unsafe!==[]?'Schema 42 needs a diagnostic review':'Resume the protected Schema 42 preparation first','retrySafe'=>!empty($schema42['issues'])&&$unsafe===[]&&$marker<=42]);
    }
    if(empty($schema43['ready'])){
        $unsafe=array_filter($schema43['issues']??[],static fn(array $row):bool=>empty($row['repairable']));
        return array_replace($base,['state'=>$marker===43?'marker_43_incomplete':'schema43_preparation_required','stateLabel'=>$unsafe!==[]?'Bank account storage needs a diagnostic review':'Add bank account details storage','retrySafe'=>!empty($schema43['issues'])&&$unsafe===[]]);
    }
    return ['state'=>$marker===43?'complete_schema_43':'schema43_objects_stale_marker','stateLabel'=>$marker===43?'Database is up to date':'Verify and finish the database upgrade','ready'=>$marker===43,'retrySafe'=>true,'mutationRequired'=>$marker!==43];
}

/** Read-only ledger checks: a previously completed step may never be silently repaired. */
function tegh_schema43_classify_ledger(array $events,int $marker,array $contract,callable $checksumFor): array
{
    $known=['schema43.5930.bank_profile','schema43.5930.adopt_marker'];$completed=[];$issues=[];
    foreach($events as $event){
        $id=(string)($event['migration_id']??'');$state=(string)($event['state']??'');
        if(!in_array($id,$known,true)||!in_array($state,['started','completed','failed'],true)){
            $issues[]=['object'=>$id?:'database_migration_events','code'=>'schema43_unknown_migration_event','repairable'=>false];continue;
        }
        if($state!=='completed')continue;
        $completed[$id]=true;
        if(!hash_equals((string)$checksumFor($id),(string)($event['migration_checksum']??'')))
            $issues[]=['object'=>$id,'code'=>'schema43_completed_checksum_mismatch','repairable'=>false];
        if(empty($contract['ready'])||($id==='schema43.5930.adopt_marker'&&$marker!==43))
            $issues[]=['object'=>$id,'code'=>'schema43_completed_contract_drift','repairable'=>false];
    }
    return ['safe'=>$issues===[],'pending'=>$events!==[]&&count($completed)<count($known),'issues'=>$issues];
}

function tegh_schema43_ledger_status(int $marker, array $schema43): array
{
    $ledger=['safe'=>true,'pending'=>false,'issues'=>[]];
    if($marker<=43&&schema_table_exists('database_migration_events')){
        try{
            tegh_schema42_verify_completed_checksums();
            $events=db()->query("SELECT migration_id,migration_checksum,state FROM database_migration_events WHERE migration_id LIKE 'schema43.%' ORDER BY event_id")->fetchAll();
            $ledger=tegh_schema43_classify_ledger($events,$marker,$schema43,'tegh_migration_checksum');
        }catch(Throwable $error){$ledger=['safe'=>false,'pending'=>false,'issues'=>[['object'=>'database_migration_events','code'=>'migration_ledger_unverifiable','repairable'=>false]]];}
    }
    return $ledger;
}

function tegh_schema43_preflight(): array
{
    $marker=current_database_schema_version();$s41=tegh_schema41_status();$s42=tegh_schema42_status();$s43=tegh_schema43_status();
    $state=tegh_schema43_classify($marker,$s41,$s42,$s43);$capabilities=tegh_database_upgrade_capabilities();
    if($state['mutationRequired']&&(!$capabilities['privilegesReady']||!$capabilities['advisoryLockSupported']))$state['retrySafe']=false;
    $event=null;
    if(schema_table_exists('database_migration_events')){
        $stmt=db()->query('SELECT migration_id,state,request_reference,started_at,completed_at FROM database_migration_events ORDER BY event_id DESC LIMIT 1');$event=$stmt->fetch()?:null;
    }
    $ledger=tegh_schema43_ledger_status($marker,$s43);
    if(!$ledger['safe'])$state=array_replace($state,['ready'=>false,'retrySafe'=>false,'state'=>'migration_ledger_review_required','stateLabel'=>'A completed migration needs a diagnostic review']);
    elseif($ledger['pending']&&!empty($state['ready']))$state=array_replace($state,['ready'=>false,'mutationRequired'=>true,'retrySafe'=>(bool)$capabilities['privilegesReady']&&(bool)$capabilities['advisoryLockSupported'],'state'=>'schema43_verification_pending','stateLabel'=>'Verify and complete the interrupted upgrade record']);
    return $state+['markerVersion'=>$marker,'structuralVersion'=>!empty($s41['ready'])?(!empty($s42['ready'])?(!empty($s43['ready'])?43:42):41):0,
        'expectedSchemaVersion'=>43,'schema41Ready'=>(bool)$s41['ready'],'schema42Ready'=>(bool)$s42['ready'],
        'contract'=>['ready'=>$s41['ready']&&$s42['ready']&&$s43['ready']&&$ledger['safe']&&!$ledger['pending'],'issues'=>array_merge($s41['issues']??[],$s42['issues']??[],$s43['issues']??[],$ledger['issues'])],
        'migrationLedger'=>$ledger,'capabilities'=>$capabilities,'privateRuntime'=>tegh_private_runtime_status(),'lastMigrationEvent'=>$event,
        'build'=>SR_ACCOUNTAX_BUILD,'version'=>SR_ACCOUNTAX_VERSION,'requestReference'=>request_id(),'checkedAt'=>gmdate('c')];
}

function tegh_schema43_upgrade(array $user,bool $backupConfirmed): array
{
    $initial=tegh_schema43_preflight();$previous=(int)$initial['markerVersion'];$steps=[];
    if($initial['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'schemaVersion'=>43,'previousSchema'=>$previous,'steps'=>[],'preflight'=>$initial,'accountingTransactionsPosted'=>0];
    if(!$backupConfirmed)fail('Confirm that the database, website and private documents have been backed up.',409,'database_backup_required');
    if(!$initial['retrySafe'])fail('The database needs a diagnostic review before it can be upgraded.',409,'schema_upgrade_unsafe');
    // Reuse the verified resumable predecessor; it acquires/releases the same
    // global upgrade lock and records its own immutable migration checksums.
    if($previous<=42&&($previous<42||empty($initial['schema42Ready']))){
        $prerequisite=tegh_schema42_upgrade($user,true);$steps=$prerequisite['steps']??[];
    }
    $maintenanceToken=tegh_maintenance_mode_begin('schema43_upgrade');
    if($maintenanceToken==='')fail('Another maintenance operation is active. Wait and retry.',409,'maintenance_mode_busy');
    $lock=0;
    try{
        $lock=(int)db()->query("SELECT GET_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."',2)")->fetchColumn();
        if($lock!==1)throw new RuntimeException('The database upgrade lock is busy.');
        $fresh=tegh_schema43_preflight();
        if($fresh['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'schemaVersion'=>43,'previousSchema'=>$previous,'steps'=>$steps,'preflight'=>$fresh,'accountingTransactionsPosted'=>0];
        if(!$fresh['retrySafe']||empty($fresh['schema42Ready']))throw new RuntimeException('Database structure changed before the upgrade lock was acquired.');
        tegh_schema42_verify_completed_checksums();tegh_migration_ledger_ensure();
        $events=db()->query("SELECT migration_id,migration_checksum FROM database_migration_events WHERE migration_id LIKE 'schema43.%' AND state='completed'");
        foreach($events->fetchAll() as $event)if(!hash_equals(tegh_migration_checksum((string)$event['migration_id']),(string)$event['migration_checksum']))throw new RuntimeException('A completed Schema 43 migration checksum differs.');
        tegh_run_migration_step('schema43.5930.bank_profile',static fn()=>tegh_schema43_prepare_columns(),static fn():bool=>tegh_schema43_status()['ready'],$steps);
        if(!tegh_schema42_status()['ready']||!tegh_schema43_status()['ready'])throw new RuntimeException('The resulting database contract could not be verified.');
        tegh_run_migration_step('schema43.5930.adopt_marker',static fn()=>tegh_mark_schema_version(43),static fn():bool=>current_database_schema_version()===43&&tegh_schema43_status()['ready'],$steps);
        system_incident_write_private_log(['requestId'=>request_id(),'occurredAt'=>gmdate('c'),'source'=>'schema43_bank_accounts','route'=>'startup/migrate','httpStatus'=>200,'errorCode'=>'schema_upgrade_completed','publicMessage'=>'Bank account storage is ready.','context'=>system_incident_clean_context(['actorUserId'=>(string)$user['id'],'previousSchema'=>$previous,'resultingSchema'=>43,'steps'=>$steps,'accountingTransactionsPosted'=>0])]);
        return ['ok'=>true,'alreadyUpToDate'=>false,'message'=>'Upgrade successful','previousSchema'=>$previous,'schemaVersion'=>43,'steps'=>$steps,'preflight'=>tegh_schema43_preflight(),'accountingTransactionsPosted'=>0];
    }catch(Throwable $error){record_system_incident('The database upgrade needs another check. No accounting transaction was posted.',500,'schema43_upgrade_failed',$error,['source'=>'schema43_bank_accounts','completedSteps'=>$steps]);throw $error;}
    finally{if($lock===1)try{db()->query("SELECT RELEASE_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."')");}catch(Throwable){}tegh_maintenance_mode_end($maintenanceToken);}
}

function handle_tegh_schema43_preflight(): never
{
    require_method('GET');tegh_require_platform_owner();json_response(['ok'=>true,'preflight'=>tegh_schema43_preflight()]);
}
function handle_tegh_schema43_diagnostic(): never
{
    require_method('GET');tegh_require_platform_owner();$events=[];
    if(schema_table_exists('database_migration_events')){
        $stmt=db()->query('SELECT migration_id,migration_checksum,state,started_at,completed_at,request_reference,build_number FROM database_migration_events ORDER BY event_id DESC LIMIT 150');$events=$stmt->fetchAll();
    }
    json_response(['ok'=>true,'diagnostic'=>tegh_schema43_preflight(),'migrationEvents'=>$events]);
}
function handle_tegh_schema43_upgrade(): never
{
    require_method('POST');$user=tegh_require_platform_owner();require_csrf();$input=request_json();@ignore_user_abort(true);@set_time_limit(180);
    try{json_response(tegh_schema43_upgrade($user,!empty($input['backupConfirmed'])));}
    catch(Throwable $error){error_log('Tegh Schema 43 request='.request_id().' '.system_incident_redact_text($error->getMessage(),1000));fail('The upgrade did not finish. Review the diagnostic before retrying. No accounting transaction was posted. Reference: '.request_id(),500,'schema_upgrade_failed',false);}
}
