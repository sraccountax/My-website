<?php
declare(strict_types=1);

/**
 * Tegh 5.3.1 cumulative Schema 39 recovery controller.
 *
 * This file deliberately sits beside the historical migration library. The
 * historical functions remain available for their version-specific additive
 * work, while this controller owns classification, locking, resumable events,
 * exact contract verification and the protected Platform Owner routes.
 */

const TEGH_DATABASE_UPGRADE_LOCK = 'tegh_database_upgrade';
const TEGH_SCHEMA39_RECOVERY_BUILD = 5310;

/** @return array<string,array<string,mixed>> */
function tegh_schema39_column_contract(): array
{
    return [
        'payroll_runs'=>[
            'create_operation_key'=>['type'=>'varchar(64)','nullable'=>true,'default'=>null],
            'create_request_hash'=>['type'=>'char(64)','nullable'=>true,'default'=>null],
        ],
        'payroll_journal_drafts'=>[
            'posted_by'=>['type'=>'varchar(64)','nullable'=>true,'default'=>null],
            'posted_at'=>['type'=>'datetime','nullable'=>true,'default'=>null],
        ],
        'native_agent_collection_drafts'=>[
            'message_length'=>['type'=>"enum('concise','standard','detailed')",'nullable'=>false,'default'=>'standard'],
            'template_id'=>['type'=>'varchar(64)','nullable'=>true,'default'=>null],
            'template_revision'=>['type'=>'int(11)','typeAlternatives'=>['int'],'nullable'=>true,'default'=>null],
            'template_hash'=>['type'=>'char(64)','nullable'=>true,'default'=>null],
            'template_snapshot_json'=>['type'=>'longtext','nullable'=>true,'default'=>null],
            'source_facts_json'=>['type'=>'longtext','nullable'=>true,'default'=>null],
            'delivery_attempt_id'=>['type'=>'varchar(64)','nullable'=>true,'default'=>null],
            'delivery_outcome'=>['type'=>"enum('none','accepted','failed','deferred','manual_review','diagnostic_warning')",'nullable'=>false,'default'=>'none'],
            'recovery_status'=>['type'=>"enum('none','confirmed_sent_external','confirmed_not_received')",'nullable'=>false,'default'=>'none'],
            'recovery_reason'=>['type'=>'varchar(500)','nullable'=>true,'default'=>null],
            'recovered_by'=>['type'=>'varchar(64)','nullable'=>true,'default'=>null],
            'recovered_at'=>['type'=>'datetime','nullable'=>true,'default'=>null],
        ],
        'outbound_emails'=>[
            'status'=>['type'=>"enum('pending','sent','failed','deferred','manual_review','sent_warning')",'nullable'=>false,'default'=>'pending'],
        ],
        'outbound_email_attempts'=>[
            'id'=>['type'=>'varchar(64)','nullable'=>false,'default'=>null],
            'outbound_email_id'=>['type'=>'varchar(64)','nullable'=>false,'default'=>null],
            'collection_draft_id'=>['type'=>'varchar(64)','nullable'=>true,'default'=>null],
            'company_id'=>['type'=>'varchar(64)','nullable'=>true,'default'=>null],
            'initiated_by'=>['type'=>'varchar(64)','nullable'=>true,'default'=>null],
            'attempt_number'=>['type'=>'int(11)','typeAlternatives'=>['int'],'nullable'=>false,'default'=>null],
            'operation_key'=>['type'=>'char(64)','nullable'=>false,'default'=>null],
            'stage'=>['type'=>"enum('connection','greeting','ehlo','starttls','authentication','mail_from','rcpt_to','data_initialization','final_data_acceptance','quit','sendmail')",'nullable'=>false,'default'=>'connection'],
            'smtp_reply_code'=>['type'=>'int(11)','typeAlternatives'=>['int'],'nullable'=>true,'default'=>null],
            'enhanced_code'=>['type'=>'varchar(20)','nullable'=>true,'default'=>null],
            'provider_diagnostic'=>['type'=>'varchar(500)','nullable'=>false,'default'=>''],
            'outcome'=>['type'=>"enum('accepted','definitive_preaccept_failure','temporary_preaccept_failure','ambiguous_after_submission','diagnostic_failure_after_acceptance')",'nullable'=>true,'default'=>null],
            'acceptance_certainty'=>['type'=>"enum('yes','no','unknown')",'nullable'=>false,'default'=>'unknown'],
            'started_at'=>['type'=>'datetime','nullable'=>false,'default'=>null],
            'accepted_at'=>['type'=>'datetime','nullable'=>true,'default'=>null],
            'completed_at'=>['type'=>'datetime','nullable'=>true,'default'=>null],
            'created_at'=>['type'=>'timestamp','nullable'=>false,'default'=>'current_timestamp','defaultAlternatives'=>['current_timestamp()'],'extra'=>''],
        ],
        'collection_message_templates'=>[
            'id'=>['type'=>'varchar(64)','nullable'=>false,'default'=>null],
            'company_id'=>['type'=>'varchar(64)','nullable'=>false,'default'=>null],
            'tone'=>['type'=>"enum('friendly','firm','final')",'nullable'=>false,'default'=>null],
            'message_length'=>['type'=>"enum('concise','standard','detailed')",'nullable'=>false,'default'=>'standard'],
            'subject_template'=>['type'=>'varchar(240)','nullable'=>false,'default'=>null],
            'body_template'=>['type'=>'text','nullable'=>false,'default'=>null],
            'signature_block'=>['type'=>'varchar(1000)','nullable'=>false,'default'=>''],
            'payment_instructions'=>['type'=>'varchar(1000)','nullable'=>false,'default'=>''],
            'revision'=>['type'=>'int(11)','typeAlternatives'=>['int'],'nullable'=>false,'default'=>null],
            'template_hash'=>['type'=>'char(64)','nullable'=>false,'default'=>null],
            'change_note'=>['type'=>'varchar(500)','nullable'=>false,'default'=>''],
            'created_by'=>['type'=>'varchar(64)','nullable'=>false,'default'=>null],
            'created_at'=>['type'=>'timestamp','nullable'=>false,'default'=>'current_timestamp','defaultAlternatives'=>['current_timestamp()'],'extra'=>''],
        ],
        'month_end_attestations'=>[
            'id'=>['type'=>'varchar(64)','nullable'=>false,'default'=>null],
            'company_id'=>['type'=>'varchar(64)','nullable'=>false,'default'=>null],
            'period_start'=>['type'=>'date','nullable'=>false,'default'=>null],
            'period_end'=>['type'=>'date','nullable'=>false,'default'=>null],
            'evidence_hash'=>['type'=>'char(64)','nullable'=>false,'default'=>null],
            'evidence_revision'=>['type'=>'varchar(120)','nullable'=>false,'default'=>null],
            'attestation_version'=>['type'=>'int(11)','typeAlternatives'=>['int'],'nullable'=>false,'default'=>'1'],
            'attestation_text'=>['type'=>'varchar(500)','nullable'=>false,'default'=>null],
            'note'=>['type'=>'varchar(500)','nullable'=>false,'default'=>''],
            'attested_by'=>['type'=>'varchar(64)','nullable'=>false,'default'=>null],
            'attested_at'=>['type'=>'datetime','nullable'=>false,'default'=>null],
            'created_at'=>['type'=>'timestamp','nullable'=>false,'default'=>'current_timestamp','defaultAlternatives'=>['current_timestamp()'],'extra'=>''],
        ],
    ];
}

/** @return array<string,array<string,array<string,mixed>>> */
function tegh_schema39_index_contract(): array
{
    return [
        'payroll_runs'=>[
            'payroll_runs_create_operation_uq'=>['unique'=>true,'columns'=>['company_id','created_by','create_operation_key']],
        ],
        'native_agent_collection_drafts'=>[
            'native_agent_collection_attempt_idx'=>['unique'=>false,'columns'=>['delivery_attempt_id']],
        ],
        'outbound_email_attempts'=>[
            'PRIMARY'=>['unique'=>true,'columns'=>['id']],
            'outbound_email_attempt_operation_uq'=>['unique'=>true,'columns'=>['operation_key']],
            'outbound_email_attempt_number_uq'=>['unique'=>true,'columns'=>['outbound_email_id','attempt_number']],
            'outbound_email_attempt_company_outcome_idx'=>['unique'=>false,'columns'=>['company_id','outcome','started_at']],
            'outbound_email_attempt_collection_idx'=>['unique'=>false,'columns'=>['collection_draft_id','started_at']],
        ],
        'collection_message_templates'=>[
            'PRIMARY'=>['unique'=>true,'columns'=>['id']],
            'collection_template_company_tone_length_revision_uq'=>['unique'=>true,'columns'=>['company_id','tone','message_length','revision']],
            'collection_template_company_current_idx'=>['unique'=>false,'columns'=>['company_id','tone','message_length','created_at']],
        ],
        'month_end_attestations'=>[
            'PRIMARY'=>['unique'=>true,'columns'=>['id']],
            'month_end_attestation_identity_uq'=>['unique'=>true,'columns'=>['company_id','period_start','evidence_hash','attested_by','attestation_version']],
            'month_end_attestation_company_period_idx'=>['unique'=>false,'columns'=>['company_id','period_start','period_end','attested_at']],
        ],
    ];
}

/** @return array<string,array<string,array<string,string>>> */
function tegh_schema39_foreign_key_contract(): array
{
    return [
        'payroll_journal_drafts'=>[
            'payroll_journal_drafts_posted_user_fk'=>['columns'=>'posted_by','targetTable'=>'users','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'SET NULL'],
        ],
        'native_agent_collection_drafts'=>[
            'native_agent_collection_recovered_user_fk'=>['columns'=>'recovered_by','targetTable'=>'users','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'SET NULL'],
        ],
        'outbound_email_attempts'=>[
            'outbound_email_attempt_mail_fk'=>['columns'=>'outbound_email_id','targetTable'=>'outbound_emails','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'CASCADE'],
            'outbound_email_attempt_collection_fk'=>['columns'=>'collection_draft_id','targetTable'=>'native_agent_collection_drafts','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'SET NULL'],
            'outbound_email_attempt_company_fk'=>['columns'=>'company_id','targetTable'=>'companies','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'SET NULL'],
            'outbound_email_attempt_user_fk'=>['columns'=>'initiated_by','targetTable'=>'users','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'SET NULL'],
        ],
        'collection_message_templates'=>[
            'collection_template_company_fk'=>['columns'=>'company_id','targetTable'=>'companies','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'CASCADE'],
            'collection_template_user_fk'=>['columns'=>'created_by','targetTable'=>'users','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'RESTRICT'],
        ],
        'month_end_attestations'=>[
            'month_end_attestation_company_fk'=>['columns'=>'company_id','targetTable'=>'companies','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'CASCADE'],
            'month_end_attestation_user_fk'=>['columns'=>'attested_by','targetTable'=>'users','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'RESTRICT'],
        ],
    ];
}

function tegh_schema_normalize_type(mixed $value): string
{
    $type=strtolower(trim((string)$value));
    $type=(string)preg_replace('/\s+/', '', $type);
    return str_replace('integer','int',$type);
}

function tegh_schema_normalize_default(mixed $value): ?string
{
    if($value===null)return null;
    $default=strtolower(trim((string)$value));
    if($default==='null')return null;
    return trim($default,"'");
}

/** @return array<string,mixed>|null */
function tegh_schema_column_actual(string $table,string $column): ?array
{
    $stmt=db()->prepare('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
    $stmt->execute([$table,$column]);$row=$stmt->fetch();if(!$row)return null;
    return [
        'type'=>tegh_schema_normalize_type($row['COLUMN_TYPE']),
        'nullable'=>strtoupper((string)$row['IS_NULLABLE'])==='YES',
        'default'=>tegh_schema_normalize_default($row['COLUMN_DEFAULT']),
        'extra'=>trim((string)preg_replace('/\bdefault_generated\b\s*/','',str_replace('current_timestamp()','current_timestamp',strtolower(trim((string)$row['EXTRA']))))),
        'charset'=>$row['CHARACTER_SET_NAME']!==null?strtolower((string)$row['CHARACTER_SET_NAME']):null,
        'collation'=>$row['COLLATION_NAME']!==null?strtolower((string)$row['COLLATION_NAME']):null,
    ];
}

/** @return array<string,mixed>|null */
function tegh_schema_index_actual(string $table,string $index): ?array
{
    $stmt=db()->prepare('SELECT NON_UNIQUE,COLUMN_NAME,SUB_PART,COLLATION
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX');
    $stmt->execute([$table,$index]);$rows=$stmt->fetchAll();if(!$rows)return null;
    return [
        'unique'=>(int)$rows[0]['NON_UNIQUE']===0,
        'columns'=>array_map(static fn(array $row):string=>(string)$row['COLUMN_NAME'],$rows),
        'prefixes'=>array_map(static fn(array $row):?int=>$row['SUB_PART']!==null?(int)$row['SUB_PART']:null,$rows),
    ];
}

/** @return array<string,mixed>|null */
function tegh_schema_foreign_key_actual(string $table,string $constraint): ?array
{
    $stmt=db()->prepare('SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,rc.UPDATE_RULE,rc.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
          ON rc.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND rc.TABLE_NAME=k.TABLE_NAME AND rc.CONSTRAINT_NAME=k.CONSTRAINT_NAME
        WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.CONSTRAINT_NAME=?
        ORDER BY k.ORDINAL_POSITION');
    $stmt->execute([$table,$constraint]);$rows=$stmt->fetchAll();if(!$rows)return null;
    $updateRule=strtoupper((string)$rows[0]['UPDATE_RULE']);$deleteRule=strtoupper((string)$rows[0]['DELETE_RULE']);
    // MySQL/MariaDB may expose the default RESTRICT action as NO ACTION.
    if($updateRule==='NO ACTION')$updateRule='RESTRICT';if($deleteRule==='NO ACTION')$deleteRule='RESTRICT';
    return [
        'columns'=>implode(',',array_map(static fn(array $row):string=>(string)$row['COLUMN_NAME'],$rows)),
        'targetTable'=>(string)$rows[0]['REFERENCED_TABLE_NAME'],
        'targetColumns'=>implode(',',array_map(static fn(array $row):string=>(string)$row['REFERENCED_COLUMN_NAME'],$rows)),
        'updateRule'=>$updateRule,
        'deleteRule'=>$deleteRule,
    ];
}

function tegh_schema_contract_equal(array $expected,array $actual): bool
{
    foreach($expected as $key=>$value){
        if($key==='typeAlternatives'||$key==='defaultAlternatives')continue;
        if($key==='type'){
            $allowed=array_map('tegh_schema_normalize_type',array_merge([(string)$value],(array)($expected['typeAlternatives']??[])));
            if(!in_array(tegh_schema_normalize_type($actual['type']??''),$allowed,true))return false;
            continue;
        }
        if($key==='default'){
            $allowed=array_map('tegh_schema_normalize_default',array_merge([$value],(array)($expected['defaultAlternatives']??[])));
            if(!in_array(tegh_schema_normalize_default($actual['default']??null),$allowed,true))return false;
            continue;
        }
        if(($actual[$key]??null)!==$value)return false;
    }
    return true;
}

function tegh_schema_table_rows(string $table): int
{
    if(!schema_table_exists($table))return 0;
    return (int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

/** @return array<string,mixed> */
function tegh_schema39_contract_status(): array
{
    $issues=[];$v39Evidence=0;$unsafe=false;$columnContract=tegh_schema39_column_contract();
    $newTables=['outbound_email_attempts','collection_message_templates','month_end_attestations'];
    $affectedTables=['payroll_runs','payroll_journal_drafts','native_agent_collection_drafts','outbound_emails',...$newTables];
    foreach($affectedTables as $table){
        if(!schema_table_exists($table)){
            $issues[]=['kind'=>'missing_table','object'=>$table,'expected'=>'InnoDB / utf8mb4_unicode_ci','actual'=>'missing','populated'=>false];
            continue;
        }
        if(in_array($table,$newTables,true))$v39Evidence++;
        $stmt=db()->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);$actual=$stmt->fetch()?:[];$rows=tegh_schema_table_rows($table);
        if(strtoupper((string)($actual['ENGINE']??''))!=='INNODB'||strtolower((string)($actual['TABLE_COLLATION']??''))!=='utf8mb4_unicode_ci'){
            $issues[]=['kind'=>'table_properties','object'=>$table,'expected'=>'InnoDB / utf8mb4_unicode_ci','actual'=>[(string)($actual['ENGINE']??''),(string)($actual['TABLE_COLLATION']??'')],'populated'=>$rows>0];
            if($rows>0)$unsafe=true;
        }
    }
    foreach($columnContract as $table=>$columns){
        if(!schema_table_exists($table))continue;
        $rows=tegh_schema_table_rows($table);
        foreach($columns as $column=>$expected){
            $actual=tegh_schema_column_actual($table,$column);
            // Only Schema 39-specific additions are evidence of a partial
            // Schema 39 attempt. outbound_emails.status exists in Schema 38;
            // it becomes Schema 39 evidence only after the new enum member is
            // present. Counting every pre-existing column misclassified a
            // clean Schema 38 database as partial Schema 39.
            if($actual!==null){
                if(in_array($table,['payroll_runs','payroll_journal_drafts','native_agent_collection_drafts'],true))$v39Evidence++;
                elseif(in_array($table,$newTables,true))$v39Evidence++;
                elseif($table==='outbound_emails'&&$column==='status'&&str_contains((string)($actual['type']??''),'manual_review'))$v39Evidence++;
            }
            if($actual===null||!tegh_schema_contract_equal($expected,$actual)){
                $issues[]=['kind'=>$actual===null?'missing_column':'column_contract','object'=>$table.'.'.$column,'expected'=>$expected,'actual'=>$actual??'missing','populated'=>$rows>0];
                if($rows>0&&in_array($table,$newTables,true))$unsafe=true;
                if($actual!==null&&$table==='native_agent_collection_drafts')$unsafe=true;
                if($actual===null&&$table==='outbound_emails')$unsafe=true;
                if($actual!==null&&$table==='outbound_emails'){
                    $allowed=['pending','sent','failed','deferred','manual_review','sent_warning'];$quoted=implode(',',array_fill(0,count($allowed),'?'));
                    $valueCheck=db()->prepare("SELECT COUNT(*) FROM outbound_emails WHERE status NOT IN ($quoted)");$valueCheck->execute($allowed);if((int)$valueCheck->fetchColumn()>0)$unsafe=true;
                }
            }
        }
    }
    foreach(tegh_schema39_index_contract() as $table=>$indexes){
        if(!schema_table_exists($table))continue;
        foreach($indexes as $index=>$expected){
            $actual=tegh_schema_index_actual($table,$index);
            if($actual!==null&&($table==='payroll_journal_drafts'||$table==='native_agent_collection_drafts'||in_array($table,$newTables,true)))$v39Evidence++;
            $duplicates=$actual===null&&$expected['unique']?tegh_schema_unique_duplicates($table,$expected['columns']):0;
            if($actual===null||$actual['unique']!==$expected['unique']||$actual['columns']!==$expected['columns']||array_filter($actual['prefixes'],static fn($value):bool=>$value!==null)){
                $issues[]=['kind'=>$actual===null?'missing_index':'index_contract','object'=>$table.'.'.$index,'expected'=>$expected,'actual'=>$actual??'missing','populated'=>tegh_schema_table_rows($table)>0,'duplicateCandidates'=>$duplicates];
                if($actual!==null||$duplicates>0)$unsafe=true;
            }
        }
    }
    foreach(tegh_schema39_foreign_key_contract() as $table=>$constraints){
        if(!schema_table_exists($table))continue;
        foreach($constraints as $name=>$expected){
            $actual=tegh_schema_foreign_key_actual($table,$name);
            if($actual!==null&&($table==='native_agent_collection_drafts'||in_array($table,$newTables,true)))$v39Evidence++;
            $orphans=$actual===null?tegh_schema39_orphan_count($table,$expected):0;
            if($actual===null||$actual!==$expected){
                $issues[]=['kind'=>$actual===null?'missing_foreign_key':'foreign_key_contract','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>$actual??'missing','populated'=>tegh_schema_table_rows($table)>0,'orphanCandidates'=>$orphans];
                if($actual!==null||$orphans>0)$unsafe=true;
            }
        }
    }
    return ['ready'=>$issues===[],'issues'=>$issues,'presentObjectCount'=>$v39Evidence,'unsafeMalformedPopulated'=>$unsafe];
}

function tegh_schema34_structural_ready(): bool
{
    return core_accounting_schema_ready()&&ai_additive_schema_ready()
        &&schema_table_exists('ai_agent_conversations')&&schema_table_exists('ai_agent_memories')
        &&schema_table_exists('ai_agent_memory_history')&&schema_table_exists('ai_agent_plans')
        &&schema_column_exists('ai_agent_action_authorizations','conversation_id')
        &&schema_column_exists('ai_agent_action_authorizations','operation_key')
        &&schema_column_exists('ai_agent_tasks','conversation_id')&&schema_column_exists('ai_agent_result_sets','conversation_id');
}

/** @return array<int,bool> */
function tegh_schema_structural_levels(): array
{
    $levels=[34=>tegh_schema34_structural_ready()];
    $levels[35]=$levels[34]&&native_agent_schema_status()['ready'];
    $levels[36]=$levels[35]&&native_ap_ar_schema_status()['ready'];
    $levels[37]=$levels[36]&&financial_analysis_schema_status()['ready'];
    $levels[38]=$levels[37]&&payroll_tax_autonomy_schema_status()['ready'];
    $levels[39]=$levels[38]&&tegh_schema39_contract_status()['ready'];
    return $levels;
}

/** @return array<string,mixed> */
function tegh_database_upgrade_capabilities(): array
{
    $grants=[];$grantReadable=true;
    try{foreach(db()->query('SHOW GRANTS')->fetchAll(PDO::FETCH_NUM) as $row)$grants[]=strtoupper((string)($row[0]??''));}
    catch(Throwable){$grantReadable=false;}
    $grantText=implode("\n",$grants);$all=str_contains($grantText,'ALL PRIVILEGES');$required=[];
    foreach(['CREATE','ALTER','INDEX','REFERENCES'] as $privilege)$required[$privilege]=$all||preg_match('/(?:^|[, ]+)'.preg_quote($privilege,'/').'(?:[, ]+|ON)/',$grantText)===1;
    $lockSupported=false;$lockFree=false;
    try{
        $stmt=db()->query("SELECT GET_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."',0)");$value=$stmt->fetchColumn();
        $lockSupported=$value!==false&&$value!==null;$lockFree=(int)$value===1;
        if($lockFree)db()->query("SELECT RELEASE_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."')");
    }catch(Throwable){$lockSupported=false;$lockFree=false;}
    return ['grantInspectionAvailable'=>$grantReadable,'requiredPrivileges'=>$required,'privilegesReady'=>!in_array(false,$required,true),'advisoryLockSupported'=>$lockSupported,'advisoryLockAvailable'=>$lockFree];
}

/** @return array<string,bool> */
function tegh_private_runtime_status(): array
{
    $mailHost=trim((string)(config('mail.smtp_host')??''));$mailFrom=trim((string)(config('mail.from_email')??''));
    // Do not call private_storage_root() here: that helper may create the
    // directory, while migration preflight must remain non-mutating.
    $configuredStorage=trim((string)(config('storage_path')??''));
    $storage=$configuredStorage!==''?(realpath($configuredStorage)?:''):'';
    return [
        'privateConfigurationLoaded'=>true,
        'databaseConfigurationPresent'=>trim((string)(config('db.dsn')??''))!==''&&trim((string)(config('db.user')??''))!=='',
        'applicationSecretPresent'=>strlen((string)(config('app.secret')??''))>=48,
        'mailConfigurationPresent'=>$mailHost!==''&&$mailFrom!=='',
        'privateStoragePresent'=>$storage!==''&&is_dir($storage),
        'privateStorageWritable'=>$storage!==''&&is_dir($storage)&&is_writable($storage),
        'privateIncidentFallbackAvailable'=>system_incident_log_path()!==null,
    ];
}

/** @return array<string,mixed> */
function tegh_schema39_preflight(): array
{
    $marker=current_database_schema_version();$levels=tegh_schema_structural_levels();$contract=tegh_schema39_contract_status();
    $highest=0;foreach($levels as $version=>$ready)if($ready)$highest=$version;
    $state='unsupported_or_unsafe';$label='Unsupported or unsafe database state';$retry=false;$needsMutation=false;
    if($marker>39){$state='newer_schema';$label='Database is newer than this release';}
    elseif($marker<34){$state='unsupported_older_schema';$label='Schema '.$marker.' is not supported by this cumulative recovery package';}
    elseif($levels[39]&&$marker===39){$state='complete_schema_39';$label='Database is already up to date';$retry=true;}
    elseif($levels[39]&&$marker<39){$state='schema_39_objects_stale_marker';$label='Schema 39 objects are complete but the version marker is stale';$retry=true;$needsMutation=true;}
    elseif($marker===39){$state='marker_39_incomplete';$label='Schema marker is 39 but required structures are incomplete';$retry=!$contract['unsafeMalformedPopulated']&&($levels[34]??false);$needsMutation=true;}
    elseif($contract['presentObjectCount']>0){$state='partial_schema_39';$label='Schema 39 was partially applied';$retry=!$contract['unsafeMalformedPopulated']&&($levels[38]??false);$needsMutation=true;}
    elseif($marker>=34&&$marker<=38&&($levels[$marker]??false)){
        if($highest>$marker){$state='supported_objects_stale_marker';$label='Complete Schema '.$highest.' structures have a stale Schema '.$marker.' marker';}
        else{$state='complete_schema_'.$marker;$label='Complete Schema '.$marker.' is eligible for cumulative upgrade';}
        $retry=true;$needsMutation=true;
    }
    $capabilities=tegh_database_upgrade_capabilities();$runtime=tegh_private_runtime_status();
    if($needsMutation&&(!$capabilities['privilegesReady']||!$capabilities['advisoryLockSupported']))$retry=false;
    $lastStep=null;
    if(schema_table_exists('database_migration_events')){
        $stmt=db()->query("SELECT migration_id,state,request_reference,started_at,completed_at FROM database_migration_events ORDER BY event_id DESC LIMIT 1");
        $lastStep=$stmt->fetch()?:null;
    }
    return [
        'state'=>$state,'stateLabel'=>$label,'markerVersion'=>$marker,'structuralVersion'=>$highest,
        'expectedSchemaVersion'=>39,'ready'=>$state==='complete_schema_39','retrySafe'=>$retry,
        'mutationRequired'=>$needsMutation,'contract'=>$contract,'structuralLevels'=>$levels,
        'capabilities'=>$capabilities,'privateRuntime'=>$runtime,'lastMigrationEvent'=>$lastStep,
        'build'=>SR_ACCOUNTAX_BUILD,'version'=>SR_ACCOUNTAX_VERSION,'requestReference'=>request_id(),'checkedAt'=>gmdate('c'),
    ];
}

function tegh_schema39_private_diagnostic(array $preflight): void
{
    $event=['requestId'=>request_id(),'occurredAt'=>gmdate('c'),'source'=>'schema39_preflight','route'=>'startup/migration-preflight','httpStatus'=>200,
        'errorCode'=>'schema_preflight','publicMessage'=>(string)$preflight['stateLabel'],'context'=>system_incident_clean_context($preflight)];
    system_incident_write_private_log($event);
}

function tegh_require_platform_owner(): array
{
    $user=require_user();
    if(platform_role_for_user((string)$user['id'])!=='platform_owner')fail('Only the Platform Owner can manage a database upgrade.',403,'platform_owner_required');
    return $user;
}

function tegh_migration_ledger_ensure(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS database_migration_events (
      event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      migration_id VARCHAR(160) NOT NULL, migration_checksum CHAR(64) NOT NULL,
      state ENUM('started','completed','failed') NOT NULL,
      started_at DATETIME NOT NULL, completed_at DATETIME NULL,
      request_reference VARCHAR(80) NOT NULL, build_number INT NOT NULL,
      details_json LONGTEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY database_migration_id_state_idx (migration_id,state,event_id),
      KEY database_migration_request_idx (request_reference,event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tegh_migration_checksum(string $migrationId): string
{
    $material=['migrationId'=>$migrationId,'contractVersion'=>'schema39-recovery-5310-v3',
        'columns'=>tegh_schema39_column_contract(),'indexes'=>tegh_schema39_index_contract(),'foreignKeys'=>tegh_schema39_foreign_key_contract()];
    if(str_starts_with($migrationId,'schema40.')&&function_exists('tegh_schema40_column_ddl')){
        $material=['migrationId'=>$migrationId,'contractVersion'=>'schema40-entitlements-5500-v1',
            'columns'=>tegh_schema40_column_ddl(),'indexes'=>tegh_schema40_index_contract(),'foreignKeys'=>tegh_schema40_foreign_key_contract(),
            'checks'=>tegh_schema40_check_contract(),'catalog'=>tegh_feature_seed_catalog(),'dependencies'=>tegh_feature_seed_dependencies()];
    }
    if(str_starts_with($migrationId,'schema41.')&&function_exists('tegh_schema41_column_ddl')){
        $material=['migrationId'=>$migrationId,'contractVersion'=>'schema41-vendor-recognition-5600-v2',
            'columns'=>tegh_schema41_column_ddl(),'indexes'=>tegh_schema41_index_contract(),
            'supportingIndexes'=>tegh_schema41_supporting_index_contract(),
            'foreignKeys'=>tegh_schema41_foreign_key_contract(),'checks'=>tegh_schema41_check_contract()];
    }
    if(str_starts_with($migrationId,'schema42.')&&function_exists('tegh_schema42_contract_material')){
        $material=['migrationId'=>$migrationId,'contractVersion'=>'schema42-transaction-controls-5900-v1']+tegh_schema42_contract_material();
    }
    if(str_starts_with($migrationId,'schema43.')&&function_exists('tegh_schema43_contract_material')){
        $material=['migrationId'=>$migrationId,'contractVersion'=>'schema43-bank-profile-5930-v1']+tegh_schema43_contract_material();
    }
    if(str_starts_with($migrationId,'schema44.')&&function_exists('tegh_schema44_table_sql'))$material=['migrationId'=>$migrationId,'contractVersion'=>'schema44-native-5970-v1','tables'=>tegh_schema44_table_sql(),'dataPolicy'=>'native-enabled-preserve-owner-user-deny-registration-false-connected-false-v1'];
    if(str_starts_with($migrationId,'schema45.')&&function_exists('tegh_schema45_table_sql')){
        $v2=$migrationId==='schema45.5990.interbank_lifecycle_v2';$v3=$migrationId==='schema45.5990.interbank_lifecycle_v3';
        $material=['migrationId'=>$migrationId,'contractVersion'=>$v3?'schema45-interbank-lifecycle-5990-v3':($v2?'schema45-interbank-lifecycle-5990-v2':'schema45-accounting-foundation-5990-v1'),
            'tables'=>$v3?tegh_schema45_table_sql():($v2?tegh_schema45_v2_table_sql():tegh_schema45_v1_table_sql()),
            'columns'=>$v3?tegh_schema45_column_ddl():($v2?tegh_schema45_v2_column_ddl():tegh_schema45_v1_column_ddl()),
            'indexes'=>$v3?tegh_schema45_expected_indexes():($v2?tegh_schema45_v2_expected_indexes():tegh_schema45_v1_expected_indexes()),
            'dataPolicy'=>'map-only-provable-controls-legacy-applications-review-required-no-accounting-writes-v1'];
    }
    if(str_starts_with($migrationId,'schema46.')&&function_exists('tegh_schema46_table_sql'))$material=['migrationId'=>$migrationId,'contractVersion'=>'schema46-invoice-documents-r20-v1','tables'=>tegh_schema46_table_sql(),'dataPolicy'=>'additive-no-postings-no-delivery'];
    return hash('sha256',json_encode($material,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function tegh_migration_event(string $migrationId,string $state,array $details=[]): void
{
    $started=$state==='started'?gmdate('Y-m-d H:i:s'):(string)($details['startedAt']??gmdate('Y-m-d H:i:s'));
    $completed=$state==='started'?null:gmdate('Y-m-d H:i:s');
    db()->prepare('INSERT INTO database_migration_events
        (migration_id,migration_checksum,state,started_at,completed_at,request_reference,build_number,details_json)
        VALUES (?,?,?,?,?,?,?,?)')->execute([$migrationId,tegh_migration_checksum($migrationId),$state,$started,$completed,request_id(),SR_ACCOUNTAX_BUILD,
            json_encode(system_incident_clean_context($details),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}

function tegh_migration_completed(string $migrationId): bool
{
    if(!schema_table_exists('database_migration_events'))return false;
    $stmt=db()->prepare("SELECT migration_checksum FROM database_migration_events WHERE migration_id=? AND state='completed' ORDER BY event_id DESC LIMIT 1");
    $stmt->execute([$migrationId]);$checksum=$stmt->fetchColumn();
    if($checksum===false)return false;
    if(!hash_equals(tegh_migration_checksum($migrationId),(string)$checksum))throw new RuntimeException('Migration checksum mismatch for '.$migrationId.'.');
    return true;
}

function tegh_run_migration_step(string $migrationId,callable $mutation,callable $verification,array &$completed): void
{
    if(tegh_migration_completed($migrationId)){
        if(!$verification())throw new RuntimeException('A completed migration event no longer matches the database contract: '.$migrationId.'.');
        $completed[]=['id'=>$migrationId,'state'=>'already_completed'];return;
    }
    tegh_migration_event($migrationId,'started');
    try{
        $mutation();
        if(!$verification())throw new RuntimeException('Post-step verification failed for '.$migrationId.'.');
        tegh_migration_event($migrationId,'completed');$completed[]=['id'=>$migrationId,'state'=>'completed'];
    }catch(Throwable $error){
        try{tegh_migration_event($migrationId,'failed',['errorClass'=>$error::class,'errorMessage'=>$error->getMessage()]);}catch(Throwable){}
        throw $error;
    }
}

function tegh_mark_schema_version(int $version): void
{
    db()->prepare("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v".$version."_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute();
    db()->prepare("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute([(string)$version]);
}

/**
 * Complete a schema-marker adoption without weakening the append-only ledger.
 *
 * A retained database can contain a valid completed adoption receipt while its
 * mutable app_meta marker is stale (for example after a selective metadata
 * restore). Re-running tegh_run_migration_step() would reject that state before
 * its idempotent marker mutation can execute. Reassert only the marker, only
 * after the immutable receipt checksum and current structural contract both
 * verify, and append a separate recovery receipt for every repair attempt.
 */
function tegh_run_schema_marker_step(string $migrationId,int $targetVersion,callable $contractVerification,array &$completed): void
{
    if(!tegh_migration_completed($migrationId)){
        tegh_run_migration_step($migrationId,static fn()=>tegh_mark_schema_version($targetVersion),static fn():bool=>current_database_schema_version()===$targetVersion&&$contractVerification(),$completed);
        return;
    }
    $current=current_database_schema_version();
    if($current>=$targetVersion){
        if(!$contractVerification())throw new RuntimeException('A completed schema-marker event no longer matches the database contract: '.$migrationId.'.');
        $completed[]=['id'=>$migrationId,'state'=>'already_completed'];
        return;
    }
    if(!$contractVerification())throw new RuntimeException('A stale schema marker cannot be repaired because the database contract is incomplete: '.$migrationId.'.');
    $repairId=$migrationId.'.marker_reassertion';
    $details=['previousSchema'=>$current,'targetSchema'=>$targetVersion,'completedMigrationId'=>$migrationId];
    tegh_migration_event($repairId,'started',$details);
    try{
        tegh_mark_schema_version($targetVersion);
        if(current_database_schema_version()!==$targetVersion||!$contractVerification())throw new RuntimeException('Post-step verification failed for '.$repairId.'.');
        tegh_migration_event($repairId,'completed',$details);
        $completed[]=['id'=>$migrationId,'state'=>'marker_reasserted','repairId'=>$repairId,'previousSchema'=>$current,'schemaVersion'=>$targetVersion];
    }catch(Throwable $error){
        try{tegh_migration_event($repairId,'failed',$details+['errorClass'=>$error::class,'errorMessage'=>$error->getMessage()]);}catch(Throwable){}
        throw $error;
    }
}

function tegh_schema39_create_table(string $table): void
{
    $sql=match($table){
        'outbound_email_attempts'=>"CREATE TABLE IF NOT EXISTS outbound_email_attempts (
          id VARCHAR(64) PRIMARY KEY, outbound_email_id VARCHAR(64) NOT NULL, collection_draft_id VARCHAR(64) NULL,
          company_id VARCHAR(64) NULL, initiated_by VARCHAR(64) NULL, attempt_number INT NOT NULL, operation_key CHAR(64) NOT NULL,
          stage ENUM('connection','greeting','ehlo','starttls','authentication','mail_from','rcpt_to','data_initialization','final_data_acceptance','quit','sendmail') NOT NULL DEFAULT 'connection',
          smtp_reply_code INT NULL, enhanced_code VARCHAR(20) NULL, provider_diagnostic VARCHAR(500) NOT NULL DEFAULT '',
          outcome ENUM('accepted','definitive_preaccept_failure','temporary_preaccept_failure','ambiguous_after_submission','diagnostic_failure_after_acceptance') NULL,
          acceptance_certainty ENUM('yes','no','unknown') NOT NULL DEFAULT 'unknown', started_at DATETIME NOT NULL,
          accepted_at DATETIME NULL, completed_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'collection_message_templates'=>"CREATE TABLE IF NOT EXISTS collection_message_templates (
          id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, tone ENUM('friendly','firm','final') NOT NULL,
          message_length ENUM('concise','standard','detailed') NOT NULL DEFAULT 'standard', subject_template VARCHAR(240) NOT NULL,
          body_template TEXT NOT NULL, signature_block VARCHAR(1000) NOT NULL DEFAULT '', payment_instructions VARCHAR(1000) NOT NULL DEFAULT '',
          revision INT NOT NULL, template_hash CHAR(64) NOT NULL, change_note VARCHAR(500) NOT NULL DEFAULT '', created_by VARCHAR(64) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'month_end_attestations'=>"CREATE TABLE IF NOT EXISTS month_end_attestations (
          id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL,
          evidence_hash CHAR(64) NOT NULL, evidence_revision VARCHAR(120) NOT NULL, attestation_version INT NOT NULL DEFAULT 1,
          attestation_text VARCHAR(500) NOT NULL, note VARCHAR(500) NOT NULL DEFAULT '', attested_by VARCHAR(64) NOT NULL,
          attested_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        default=>throw new InvalidArgumentException('Unknown Schema 39 table.'),
    };
    db()->exec($sql);
}

function tegh_schema39_repair_table_properties(): void
{
    foreach(['payroll_runs','payroll_journal_drafts','native_agent_collection_drafts','outbound_emails','outbound_email_attempts','collection_message_templates','month_end_attestations'] as $table){
        if(!schema_table_exists($table))throw new RuntimeException('Required table is missing: '.$table.'.');
        $stmt=db()->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
        $stmt->execute([$table]);$actual=$stmt->fetch()?:[];
        $engineOk=strtoupper((string)($actual['ENGINE']??''))==='INNODB';
        $collationOk=strtolower((string)($actual['TABLE_COLLATION']??''))==='utf8mb4_unicode_ci';
        if($engineOk&&$collationOk)continue;
        if(tegh_schema_table_rows($table)>0)throw new RuntimeException('A populated Schema 39 table has incompatible engine or collation: '.$table.'.');
        if(!$engineOk)db()->exec("ALTER TABLE `$table` ENGINE=InnoDB");
        if(!$collationOk)db()->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}

/** @return array<string,array<string,string>> */
function tegh_schema39_recovery_column_definitions(): array
{
    $definitions=release_v39_schema_column_definitions();
    $definitions['payroll_journal_drafts']=[
        'posted_by'=>'VARCHAR(64) NULL',
        'posted_at'=>'DATETIME NULL',
    ];
    return $definitions;
}

function tegh_schema39_repair_columns(): void
{
    $definitions=tegh_schema39_recovery_column_definitions();
    foreach($definitions as $table=>$columns){
        if(!schema_table_exists($table))continue;$rows=tegh_schema_table_rows($table);
        foreach($columns as $column=>$definition){
            $actual=tegh_schema_column_actual($table,$column);
            if($actual===null){
                if($rows>0&&in_array($table,['outbound_email_attempts','collection_message_templates','month_end_attestations'],true))throw new RuntimeException('Populated partial table '.$table.' is missing '.$column.'.');
                schema_add_column($table,$column,$definition);continue;
            }
            $expected=tegh_schema39_column_contract()[$table][$column]??null;
            if($expected!==null&&!tegh_schema_contract_equal($expected,$actual)){
                if($rows===0&&in_array($table,['outbound_email_attempts','collection_message_templates','month_end_attestations'],true)){
                    db()->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
                    if(!tegh_schema_contract_equal($expected,tegh_schema_column_actual($table,$column)??[]))throw new RuntimeException('Empty-table column repair did not produce the required contract: '.$table.'.'.$column.'.');
                    continue;
                }
                throw new RuntimeException('Existing column contract is incompatible: '.$table.'.'.$column.'.');
            }
        }
    }
    $allowed=['pending','sent','failed','deferred','manual_review','sent_warning'];
    if(schema_table_exists('outbound_emails')){
        $actual=tegh_schema_column_actual('outbound_emails','status');$expected=tegh_schema39_column_contract()['outbound_emails']['status'];
        if($actual===null)throw new RuntimeException('outbound_emails.status is missing.');
        if(!tegh_schema_contract_equal($expected,$actual)){
            $quoted=implode(',',array_fill(0,count($allowed),'?'));$stmt=db()->prepare("SELECT COUNT(*) FROM outbound_emails WHERE status NOT IN ($quoted)");$stmt->execute($allowed);
            if((int)$stmt->fetchColumn()>0)throw new RuntimeException('outbound_emails contains an unsupported status value.');
            db()->exec("ALTER TABLE outbound_emails MODIFY COLUMN status ENUM('pending','sent','failed','deferred','manual_review','sent_warning') NOT NULL DEFAULT 'pending'");
        }
    }
}

function tegh_schema_unique_duplicates(string $table,array $columns): int
{
    $rows=tegh_schema_table_rows($table);
    $columnContract=tegh_schema39_column_contract();
    foreach($columns as $column){
        if(schema_column_exists($table,$column))continue;
        if($rows===0)continue;
        $expected=$columnContract[$table][$column]??null;
        // A missing nullable Schema 39 column is added as NULL before the
        // index step. MySQL/MariaDB UNIQUE indexes permit multiple NULLs, so
        // pre-existing rows are not duplicate candidates. Treating every row
        // as a duplicate here made safe, resumable partial upgrades fail
        // closed immediately after the first DDL checkpoint.
        if(!is_array($expected)||($expected['nullable']??false)!==true)return $rows;
    }
    foreach($columns as $column)if(!schema_column_exists($table,$column))return 0;
    $quoted=implode(',',array_map(static fn(string $column):string=>"`$column`",$columns));
    $where=implode(' AND ',array_map(static fn(string $column):string=>"`$column` IS NOT NULL",$columns));
    return (int)db()->query("SELECT COUNT(*) FROM (SELECT $quoted FROM `$table` WHERE $where GROUP BY $quoted HAVING COUNT(*)>1) duplicate_groups")->fetchColumn();
}

function tegh_schema39_ensure_indexes(): void
{
    foreach(tegh_schema39_index_contract() as $table=>$indexes){
        if(!schema_table_exists($table))throw new RuntimeException('Required table is missing: '.$table.'.');
        foreach($indexes as $name=>$expected){
            $actual=tegh_schema_index_actual($table,$name);
            if($actual!==null){
                if($actual['unique']!==$expected['unique']||$actual['columns']!==$expected['columns']||array_filter($actual['prefixes'],static fn($value):bool=>$value!==null))throw new RuntimeException('Existing index contract is incompatible: '.$table.'.'.$name.'.');
                continue;
            }
            if($expected['unique']&&tegh_schema_unique_duplicates($table,$expected['columns'])>0)throw new RuntimeException('Duplicate values prevent required unique index '.$table.'.'.$name.'.');
            $columns=implode(',',array_map(static fn(string $column):string=>"`$column`",$expected['columns']));
            if($name==='PRIMARY')db()->exec("ALTER TABLE `$table` ADD PRIMARY KEY ($columns)");
            else db()->exec("ALTER TABLE `$table` ADD ".($expected['unique']?'UNIQUE ':'')."INDEX `$name` ($columns)");
        }
    }
}

function tegh_schema39_foreign_key_sql(string $table,string $name): string
{
    if($table==='payroll_journal_drafts'&&$name==='payroll_journal_drafts_posted_user_fk')return 'FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL';
    $map=release_v39_schema_foreign_keys();
    if(!isset($map[$table][$name]))throw new InvalidArgumentException('Unknown Schema 39 foreign key.');
    return (string)$map[$table][$name];
}

function tegh_schema39_orphan_count(string $table,array $expected): int
{
    $columns=explode(',',$expected['columns']);$targets=explode(',',$expected['targetColumns']);$join=[];$notNull=[];
    foreach($columns as $column)if(!schema_column_exists($table,$column))return 0;
    foreach($columns as $column)$notNull[]="child.`$column` IS NOT NULL";
    if(!schema_table_exists($expected['targetTable']))return (int)db()->query("SELECT COUNT(*) FROM `$table` child WHERE ".implode(' AND ',$notNull))->fetchColumn();
    foreach($targets as $column)if(!schema_column_exists($expected['targetTable'],$column))return (int)db()->query("SELECT COUNT(*) FROM `$table` child WHERE ".implode(' AND ',$notNull))->fetchColumn();
    foreach($columns as $index=>$column){$target=$targets[$index]??'';$join[]="child.`$column`=parent.`$target`";}
    $sql="SELECT COUNT(*) FROM `$table` child LEFT JOIN `{$expected['targetTable']}` parent ON ".implode(' AND ',$join)." WHERE ".implode(' AND ',$notNull)." AND parent.`{$targets[0]}` IS NULL";
    return (int)db()->query($sql)->fetchColumn();
}

function tegh_schema39_ensure_foreign_keys(): void
{
    foreach(tegh_schema39_foreign_key_contract() as $table=>$constraints){
        foreach($constraints as $name=>$expected){
            $actual=tegh_schema_foreign_key_actual($table,$name);
            if($actual!==null){if($actual!==$expected)throw new RuntimeException('Existing foreign-key contract is incompatible: '.$table.'.'.$name.'.');continue;}
            if(tegh_schema39_orphan_count($table,$expected)>0)throw new RuntimeException('Orphaned rows prevent required foreign key '.$table.'.'.$name.'.');
            db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` ".tegh_schema39_foreign_key_sql($table,$name));
        }
    }
}

function tegh_schema39_write_upgrade_audit(array $user,int $previousSchema,array $completed): void
{
    // The migration ledger is the authoritative append-only migration audit.
    // Also mirror a sanitized completion event to the private incident stream
    // so Platform Owner diagnostics still have evidence if application audit
    // tables are damaged or unavailable.
    system_incident_write_private_log([
        'requestId'=>request_id(),'occurredAt'=>gmdate('c'),'source'=>'schema39_recovery',
        'route'=>'startup/migrate','httpStatus'=>200,'errorCode'=>'schema_upgrade_completed',
        'publicMessage'=>'Schema 39 upgrade completed.',
        'context'=>system_incident_clean_context([
            'actorUserId'=>(string)$user['id'],'previousSchema'=>$previousSchema,
            'resultingSchema'=>39,'steps'=>$completed,'accountingTransactionsPosted'=>0,
        ]),
    ]);
}

/** @return array<string,mixed> */
function tegh_schema39_upgrade(array $user,bool $backupConfirmed): array
{
    $before=tegh_schema39_preflight();
    if($before['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'message'=>'Database is already up to date','previousSchema'=>39,'schemaVersion'=>39,'steps'=>[],'preflight'=>$before];
    if(!$backupConfirmed)fail('Confirm the database, website files and private storage backup before starting the upgrade.',409,'database_backup_required');
    if(!$before['retrySafe'])fail('The protected preflight found an unsupported or unsafe database state. View the diagnostic before retrying.',409,'schema_upgrade_unsafe');
    $maintenanceToken=tegh_maintenance_mode_begin('schema39_upgrade');$lock=0;$completed=[];$startedMarker=(int)$before['markerVersion'];
    try{
        $lock=(int)db()->query("SELECT GET_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."',2)")->fetchColumn();
        if($lock!==1)fail('Another Tegh database upgrade is still running. Wait a moment and retry.',409,'schema_upgrade_busy');
        $fresh=tegh_schema39_preflight();if(!$fresh['retrySafe']&&(!$fresh['ready']))throw new RuntimeException('Database state changed after preflight.');
        if($fresh['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'message'=>'Database is already up to date','previousSchema'=>$startedMarker,'schemaVersion'=>39,'steps'=>[],'preflight'=>$fresh];
        tegh_migration_ledger_ensure();
        $levels=tegh_schema_structural_levels();
        if(!$levels[34])throw new RuntimeException('The complete Schema 34 base contract is required.');
        $steps=[
            35=>['schema35.native_agent',static fn()=>ensure_v35_native_agent_schema(),static fn():bool=>native_agent_schema_status()['ready']],
            36=>['schema36.native_ap_ar',static fn()=>ensure_v36_native_ap_ar_schema(),static fn():bool=>native_ap_ar_schema_status()['ready']],
            37=>['schema37.financial_analysis',static fn()=>ensure_v37_financial_analysis_schema(),static fn():bool=>financial_analysis_schema_status()['ready']],
            38=>['schema38.payroll_tax',static fn()=>ensure_v38_payroll_tax_autonomy_schema(),static fn():bool=>payroll_tax_autonomy_schema_status()['ready']],
        ];
        foreach($steps as $version=>[$id,$mutation,$verification]){
            $levels=tegh_schema_structural_levels();
            if($levels[$version]){
                if(current_database_schema_version()<$version){
                    tegh_run_migration_step('schema'.$version.'.adopt_existing_contract',static fn()=>tegh_mark_schema_version($version),static fn():bool=>current_database_schema_version()>=$version,$completed);
                }
                continue;
            }
            tegh_run_migration_step($id,$mutation,$verification,$completed);tegh_mark_schema_version($version);
        }
        tegh_run_migration_step('schema39.create.delivery_attempts',static fn()=>tegh_schema39_create_table('outbound_email_attempts'),static fn():bool=>schema_table_exists('outbound_email_attempts'),$completed);
        tegh_run_migration_step('schema39.create.collection_templates',static fn()=>tegh_schema39_create_table('collection_message_templates'),static fn():bool=>schema_table_exists('collection_message_templates'),$completed);
        tegh_run_migration_step('schema39.create.month_end_attestations',static fn()=>tegh_schema39_create_table('month_end_attestations'),static fn():bool=>schema_table_exists('month_end_attestations'),$completed);
        tegh_run_migration_step('schema39.table_properties',static fn()=>tegh_schema39_repair_table_properties(),static function():bool{
            $status=tegh_schema39_contract_status();foreach($status['issues'] as $issue)if($issue['kind']==='table_properties')return false;return true;
        },$completed);
        tegh_run_migration_step('schema39.columns_and_status',static fn()=>tegh_schema39_repair_columns(),static function():bool{
            $status=tegh_schema39_contract_status();foreach($status['issues'] as $issue)if(in_array($issue['kind'],['missing_column','column_contract','missing_table','table_properties'],true))return false;return true;
        },$completed);
        tegh_run_migration_step('schema39.indexes',static fn()=>tegh_schema39_ensure_indexes(),static function():bool{
            $status=tegh_schema39_contract_status();foreach($status['issues'] as $issue)if(str_contains((string)$issue['kind'],'index'))return false;return true;
        },$completed);
        tegh_run_migration_step('schema39.foreign_keys',static fn()=>tegh_schema39_ensure_foreign_keys(),static function():bool{
            $status=tegh_schema39_contract_status();foreach($status['issues'] as $issue)if(str_contains((string)$issue['kind'],'foreign_key'))return false;return true;
        },$completed);
        tegh_run_migration_step('schema39.delivery_state_recovery',static function():void{
            db()->exec("UPDATE native_agent_collection_drafts SET delivery_outcome='manual_review' WHERE state='sending' AND delivery_outcome='none'");
            db()->exec("UPDATE outbound_emails e JOIN native_agent_collection_drafts d ON d.outbound_email_id=e.id SET e.status='manual_review' WHERE d.state='sending' AND e.status='pending'");
        },static fn():bool=>true,$completed);
        $final=tegh_schema39_contract_status();if(!$final['ready'])throw new RuntimeException('Schema 39 post-migration contract is incomplete.');
        tegh_run_migration_step('schema39.adopt_marker',static fn()=>tegh_mark_schema_version(39),static fn():bool=>current_database_schema_version()===39&&tegh_schema39_contract_status()['ready'],$completed);
        tegh_schema39_write_upgrade_audit($user,$startedMarker,$completed);
        $after=tegh_schema39_preflight();
        return ['ok'=>true,'alreadyUpToDate'=>false,'message'=>'Upgrade successful','previousSchema'=>$startedMarker,'schemaVersion'=>39,'steps'=>$completed,'preflight'=>$after];
    }catch(Throwable $error){
        record_system_incident('Database upgrade could not be completed. No accounting entries were changed; structural preparation may be partial.',500,'schema_upgrade_failed',$error,[
            'source'=>'schema39_recovery','stage'=>$completed?end($completed)['id']:'preflight','preflight'=>$before,'completedSteps'=>$completed,
        ]);
        throw $error;
    }finally{
        if($lock===1)try{db()->query("SELECT RELEASE_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."')");}catch(Throwable){}
        tegh_maintenance_mode_end($maintenanceToken);
    }
}

function handle_tegh_schema39_preflight(): never
{
    require_method('GET');tegh_require_platform_owner();$result=tegh_schema39_preflight();tegh_schema39_private_diagnostic($result);json_response(['ok'=>true,'preflight'=>$result]);
}

function handle_tegh_schema39_diagnostic(): never
{
    require_method('GET');tegh_require_platform_owner();$result=tegh_schema39_preflight();tegh_schema39_private_diagnostic($result);
    $events=[];if(schema_table_exists('database_migration_events')){$stmt=db()->query('SELECT migration_id,migration_checksum,state,started_at,completed_at,request_reference,build_number,details_json FROM database_migration_events ORDER BY event_id DESC LIMIT 100');foreach($stmt->fetchAll() as $row){$row['details']=json_decode((string)$row['details_json'],true)?:[];unset($row['details_json']);$events[]=$row;}}
    json_response(['ok'=>true,'diagnostic'=>$result,'migrationEvents'=>$events]);
}

function handle_tegh_schema39_upgrade(): never
{
    require_method('POST');$user=tegh_require_platform_owner();require_csrf();$input=request_json();
    @ignore_user_abort(true);@set_time_limit(85);
    try{json_response(tegh_schema39_upgrade($user,!empty($input['backupConfirmed'])));}
    catch(Throwable $error){
        error_log('Tegh Schema 39 recovery request='.request_id().' class='.$error::class.' message='.system_incident_redact_text($error->getMessage(),1000));
        fail('Database upgrade could not be completed. No accounting entries were changed. Structural preparation may be partial; the protected upgrader can resume after the reported issue is corrected. Give this request reference to the Platform Owner: '.request_id().'.',500,'schema_upgrade_failed',false);
    }
}
