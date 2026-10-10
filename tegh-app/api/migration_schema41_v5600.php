<?php
declare(strict_types=1);

/**
 * Tegh 5.6.0 / Schema 41
 *
 * Additive, resumable storage for reviewed vendor-recognition evidence. The
 * generic Schema 40 learned-rule table cannot express a unique normalized
 * vendor fingerprint, provenance revision, conflicts and append-only change
 * history without ambiguity, so the database marker advances only after both
 * canonical tables pass their complete structural and data contract.
 */

const TEGH_SCHEMA41_VERSION = 41;

/** @return array<string,string> */
function tegh_schema41_table_sql(): array
{
    return [
        'vendor_recognition_rules' => "CREATE TABLE IF NOT EXISTS vendor_recognition_rules (
          id VARCHAR(64) NOT NULL PRIMARY KEY,
          company_id VARCHAR(64) NOT NULL,
          vendor_id VARCHAR(64) NOT NULL,
          fingerprint_type ENUM('legal_name','trade_name','email_domain','business_identifier','address','layout') NOT NULL,
          fingerprint_hash CHAR(64) NOT NULL,
          display_hint VARCHAR(160) NULL,
          source_document_id VARCHAR(64) NULL,
          source_revision_hash CHAR(64) NOT NULL,
          positive_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
          negative_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
          conflict_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
          confidence_bps INT UNSIGNED NOT NULL DEFAULT 0,
          revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
          state ENUM('enabled','disabled','retired') NOT NULL DEFAULT 'enabled',
          created_by VARCHAR(64) NULL,
          updated_by VARCHAR(64) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY vendor_recognition_fingerprint_uq (company_id,fingerprint_type,fingerprint_hash),
          UNIQUE KEY vendor_recognition_company_id_uq (company_id,id),
          KEY vendor_recognition_vendor_idx (company_id,vendor_id,state),
          KEY vendor_recognition_source_idx (company_id,source_document_id),
          CONSTRAINT vendor_recognition_confidence_ck CHECK (confidence_bps <= 10000),
          CONSTRAINT vendor_recognition_company_fk FOREIGN KEY (company_id) REFERENCES companies(id),
          CONSTRAINT vendor_recognition_vendor_scope_fk FOREIGN KEY (company_id,vendor_id) REFERENCES vendors(company_id,id),
          CONSTRAINT vendor_recognition_document_scope_fk FOREIGN KEY (company_id,source_document_id) REFERENCES native_agent_documents(company_id,id),
          CONSTRAINT vendor_recognition_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
          CONSTRAINT vendor_recognition_updated_user_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'vendor_recognition_history' => "CREATE TABLE IF NOT EXISTS vendor_recognition_history (
          id VARCHAR(64) NOT NULL PRIMARY KEY,
          company_id VARCHAR(64) NOT NULL,
          rule_id VARCHAR(64) NOT NULL,
          vendor_id VARCHAR(64) NULL,
          document_id VARCHAR(64) NULL,
          event_type ENUM('accepted','corrected','negative','disabled','reset','retired') NOT NULL,
          rule_revision BIGINT UNSIGNED NOT NULL,
          before_json LONGTEXT NOT NULL,
          after_json LONGTEXT NOT NULL,
          actor_user_id VARCHAR(64) NULL,
          request_reference VARCHAR(80) NOT NULL,
          operation_key VARCHAR(120) NOT NULL,
          payload_hash CHAR(64) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY vendor_recognition_history_operation_uq (company_id,operation_key,rule_id,event_type),
          KEY vendor_recognition_history_rule_idx (company_id,rule_id,created_at),
          KEY vendor_recognition_history_vendor_idx (company_id,vendor_id,created_at),
          CONSTRAINT vendor_recognition_history_company_fk FOREIGN KEY (company_id) REFERENCES companies(id),
          CONSTRAINT vendor_recognition_history_rule_scope_fk FOREIGN KEY (company_id,rule_id) REFERENCES vendor_recognition_rules(company_id,id),
          CONSTRAINT vendor_recognition_history_vendor_scope_fk FOREIGN KEY (company_id,vendor_id) REFERENCES vendors(company_id,id),
          CONSTRAINT vendor_recognition_history_document_scope_fk FOREIGN KEY (company_id,document_id) REFERENCES native_agent_documents(company_id,id),
          CONSTRAINT vendor_recognition_history_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** @return array<string,array<string,string>> */
function tegh_schema41_column_ddl(): array
{
    return [
        'vendor_recognition_rules'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL','vendor_id'=>'VARCHAR(64) NOT NULL',
            'fingerprint_type'=>"ENUM('legal_name','trade_name','email_domain','business_identifier','address','layout') NOT NULL",
            'fingerprint_hash'=>'CHAR(64) NOT NULL','display_hint'=>'VARCHAR(160) NULL','source_document_id'=>'VARCHAR(64) NULL',
            'source_revision_hash'=>'CHAR(64) NOT NULL','positive_count'=>'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'negative_count'=>'BIGINT UNSIGNED NOT NULL DEFAULT 0','conflict_count'=>'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'confidence_bps'=>'INT UNSIGNED NOT NULL DEFAULT 0','revision'=>'BIGINT UNSIGNED NOT NULL DEFAULT 1',
            'state'=>"ENUM('enabled','disabled','retired') NOT NULL DEFAULT 'enabled'",'created_by'=>'VARCHAR(64) NULL',
            'updated_by'=>'VARCHAR(64) NULL','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'vendor_recognition_history'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL','rule_id'=>'VARCHAR(64) NOT NULL',
            'vendor_id'=>'VARCHAR(64) NULL','document_id'=>'VARCHAR(64) NULL',
            'event_type'=>"ENUM('accepted','corrected','negative','disabled','reset','retired') NOT NULL",
            'rule_revision'=>'BIGINT UNSIGNED NOT NULL','before_json'=>'LONGTEXT NOT NULL','after_json'=>'LONGTEXT NOT NULL',
            'actor_user_id'=>'VARCHAR(64) NULL','request_reference'=>'VARCHAR(80) NOT NULL','operation_key'=>'VARCHAR(120) NOT NULL',
            'payload_hash'=>'CHAR(64) NOT NULL','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ],
    ];
}

/** @return array<string,array<string,array{columns:array<int,string>,unique:bool}>> */
function tegh_schema41_index_contract(): array
{
    return [
        'vendor_recognition_rules'=>[
            'PRIMARY'=>['columns'=>['id'],'unique'=>true],
            'vendor_recognition_fingerprint_uq'=>['columns'=>['company_id','fingerprint_type','fingerprint_hash'],'unique'=>true],
            'vendor_recognition_company_id_uq'=>['columns'=>['company_id','id'],'unique'=>true],
            'vendor_recognition_vendor_idx'=>['columns'=>['company_id','vendor_id','state'],'unique'=>false],
            'vendor_recognition_source_idx'=>['columns'=>['company_id','source_document_id'],'unique'=>false],
        ],
        'vendor_recognition_history'=>[
            'PRIMARY'=>['columns'=>['id'],'unique'=>true],
            'vendor_recognition_history_operation_uq'=>['columns'=>['company_id','operation_key','rule_id','event_type'],'unique'=>true],
            'vendor_recognition_history_rule_idx'=>['columns'=>['company_id','rule_id','created_at'],'unique'=>false],
            'vendor_recognition_history_vendor_idx'=>['columns'=>['company_id','vendor_id','created_at'],'unique'=>false],
        ],
    ];
}

/** @return array<string,array<string,array{columns:array<int,string>,unique:bool}>> */
function tegh_schema41_supporting_index_contract(): array
{
    return [
        'vendors'=>['vendors_company_id_uq'=>['columns'=>['company_id','id'],'unique'=>true]],
        'native_agent_documents'=>['native_agent_documents_company_id_uq'=>['columns'=>['company_id','id'],'unique'=>true]],
    ];
}

/** @return array<string,array<string,array{columns:string,targetTable:string,targetColumns:string,updateRule:string,deleteRule:string}>> */
function tegh_schema41_foreign_key_contract(): array
{
    return [
        'vendor_recognition_rules'=>[
            'vendor_recognition_company_fk'=>['columns'=>'company_id','targetTable'=>'companies','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'RESTRICT'],
            'vendor_recognition_vendor_scope_fk'=>['columns'=>'company_id,vendor_id','targetTable'=>'vendors','targetColumns'=>'company_id,id','updateRule'=>'RESTRICT','deleteRule'=>'RESTRICT'],
            'vendor_recognition_document_scope_fk'=>['columns'=>'company_id,source_document_id','targetTable'=>'native_agent_documents','targetColumns'=>'company_id,id','updateRule'=>'RESTRICT','deleteRule'=>'RESTRICT'],
            'vendor_recognition_created_user_fk'=>['columns'=>'created_by','targetTable'=>'users','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'SET NULL'],
            'vendor_recognition_updated_user_fk'=>['columns'=>'updated_by','targetTable'=>'users','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'SET NULL'],
        ],
        'vendor_recognition_history'=>[
            'vendor_recognition_history_company_fk'=>['columns'=>'company_id','targetTable'=>'companies','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'RESTRICT'],
            'vendor_recognition_history_rule_scope_fk'=>['columns'=>'company_id,rule_id','targetTable'=>'vendor_recognition_rules','targetColumns'=>'company_id,id','updateRule'=>'RESTRICT','deleteRule'=>'RESTRICT'],
            'vendor_recognition_history_vendor_scope_fk'=>['columns'=>'company_id,vendor_id','targetTable'=>'vendors','targetColumns'=>'company_id,id','updateRule'=>'RESTRICT','deleteRule'=>'RESTRICT'],
            'vendor_recognition_history_document_scope_fk'=>['columns'=>'company_id,document_id','targetTable'=>'native_agent_documents','targetColumns'=>'company_id,id','updateRule'=>'RESTRICT','deleteRule'=>'RESTRICT'],
            'vendor_recognition_history_actor_fk'=>['columns'=>'actor_user_id','targetTable'=>'users','targetColumns'=>'id','updateRule'=>'RESTRICT','deleteRule'=>'SET NULL'],
        ],
    ];
}

/** @return array<string,array<string,string>> */
function tegh_schema41_check_contract(): array
{
    return ['vendor_recognition_rules'=>['vendor_recognition_confidence_ck'=>'confidence_bps <= 10000']];
}

function tegh_schema41_check_violation_candidates(string $table,string $expression): int
{
    if(!schema_table_exists($table))return 0;
    return (int)db()->query("SELECT COUNT(*) FROM `$table` WHERE NOT ($expression)")->fetchColumn();
}

/** @return array<string,mixed> */
function tegh_schema41_status(): array
{
    $issues=[];$present=0;$definitions=tegh_schema41_column_ddl();
    foreach(tegh_schema41_supporting_index_contract() as $table=>$indexes){
        if(!schema_table_exists($table)){$issues[]=['kind'=>'missing_prerequisite_table','object'=>$table,'expected'=>'Schema 40 prerequisite table','actual'=>'missing','repairable'=>false];continue;}
        foreach($indexes as $name=>$expected){$actual=tegh_schema_index_actual($table,$name);$duplicates=tegh_schema40_duplicate_candidates($table,$expected['columns']);if($actual===null)$issues[]=['kind'=>'missing_supporting_index','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>'missing','duplicateCandidates'=>$duplicates,'repairable'=>$duplicates===0];elseif(!tegh_schema_contract_equal($expected,$actual))$issues[]=['kind'=>'supporting_index_contract','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>$actual,'repairable'=>false];}
    }
    foreach($definitions as $table=>$columns){
        if(!schema_table_exists($table)){$issues[]=['kind'=>'missing_table','object'=>$table,'expected'=>'Schema 41 canonical table','actual'=>'missing','populated'=>false,'repairable'=>true];continue;}
        $present++;$rows=tegh_schema_table_rows($table);$p=db()->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');$p->execute([$table]);$properties=$p->fetch()?:[];
        if(strtoupper((string)($properties['ENGINE']??''))!=='INNODB'||strtolower((string)($properties['TABLE_COLLATION']??''))!=='utf8mb4_unicode_ci')$issues[]=['kind'=>'table_contract','object'=>$table,'expected'=>'InnoDB / utf8mb4_unicode_ci','actual'=>$properties,'populated'=>$rows>0,'repairable'=>$rows===0];
        foreach($columns as $column=>$definition){$actual=tegh_schema_column_actual($table,$column);$expected=tegh_schema40_expected_column($definition);if($actual===null){$repairable=$rows===0||($expected['nullable']??false)||($expected['default']??null)!==null;$issues[]=['kind'=>'missing_column','object'=>$table.'.'.$column,'expected'=>$expected,'actual'=>'missing','populated'=>$rows>0,'repairable'=>$repairable];}elseif(!tegh_schema_contract_equal($expected,$actual))$issues[]=['kind'=>'column_contract','object'=>$table.'.'.$column,'expected'=>$expected,'actual'=>$actual,'populated'=>$rows>0,'repairable'=>$rows===0];}
    }
    foreach(tegh_schema41_index_contract() as $table=>$indexes)if(schema_table_exists($table))foreach($indexes as $name=>$expected){$actual=tegh_schema_index_actual($table,$name);$duplicates=$expected['unique']?tegh_schema40_duplicate_candidates($table,$expected['columns']):0;if($actual===null)$issues[]=['kind'=>'missing_index','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>'missing','duplicateCandidates'=>$duplicates,'repairable'=>$duplicates===0];elseif(!tegh_schema_contract_equal($expected,$actual))$issues[]=['kind'=>'index_contract','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>$actual,'repairable'=>false];}
    foreach(tegh_schema41_foreign_key_contract() as $table=>$keys)if(schema_table_exists($table))foreach($keys as $name=>$expected){$actual=tegh_schema_foreign_key_actual($table,$name);$orphans=tegh_schema40_orphan_candidates($table,$expected);if($actual===null)$issues[]=['kind'=>'missing_foreign_key','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>'missing','orphanCandidates'=>$orphans,'repairable'=>$orphans===0];elseif(!tegh_schema_contract_equal($expected,$actual))$issues[]=['kind'=>'foreign_key_contract','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>$actual,'orphanCandidates'=>$orphans,'repairable'=>false];}
    foreach(tegh_schema41_check_contract() as $table=>$checks)if(schema_table_exists($table))foreach($checks as $name=>$expected){$actual=tegh_schema40_check_actual($table,$name);$violations=tegh_schema41_check_violation_candidates($table,$expected);if($actual===null)$issues[]=['kind'=>'missing_check','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>'missing','violationCandidates'=>$violations,'repairable'=>$violations===0];elseif(tegh_schema40_normalize_check($expected)!==tegh_schema40_normalize_check($actual))$issues[]=['kind'=>'check_contract','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>$actual,'repairable'=>false];elseif($violations>0)$issues[]=['kind'=>'check_data_violation','object'=>$table.'.'.$name,'expected'=>0,'actual'=>$violations,'repairable'=>false];}
    if(schema_table_exists('vendor_recognition_rules')){$cross=(int)db()->query('SELECT COUNT(*) FROM vendor_recognition_rules r LEFT JOIN vendors v ON v.id=r.vendor_id AND v.company_id=r.company_id WHERE v.id IS NULL')->fetchColumn();if($cross>0)$issues[]=['kind'=>'vendor_scope_violation','object'=>'vendor_recognition_rules','expected'=>0,'actual'=>$cross,'repairable'=>false];}
    return ['ready'=>$issues===[],'issues'=>$issues,'presentTableCount'=>$present,'expectedTableCount'=>count($definitions)];
}

function tegh_schema41_repair_supporting_index(string $table,string $name,array $expected): void
{
    if(!schema_table_exists($table))throw new RuntimeException('Schema 41 prerequisite table '.$table.' is unavailable.');
    $actual=tegh_schema_index_actual($table,$name);if($actual!==null){if(!tegh_schema_contract_equal($expected,$actual))throw new RuntimeException('Schema 41 supporting index '.$table.'.'.$name.' has a non-canonical contract.');return;}
    if(tegh_schema40_duplicate_candidates($table,$expected['columns'])>0)throw new RuntimeException('Duplicate populated values prevent Schema 41 supporting index '.$table.'.'.$name.'.');
    $columns=implode(',',array_map(static fn(string $column):string=>'`'.$column.'`',$expected['columns']));schema_add_index($table,$name,$columns,(bool)$expected['unique']);
}

function tegh_schema41_supporting_index_ready(string $table,string $name): bool
{
    $expected=tegh_schema41_supporting_index_contract()[$table][$name]??null;$actual=tegh_schema_index_actual($table,$name);return is_array($expected)&&is_array($actual)&&tegh_schema_contract_equal($expected,$actual);
}

function tegh_schema41_verify_completed_checksums(): void
{
    if(!schema_table_exists('database_migration_events'))return;
    $stmt=db()->query("SELECT migration_id,migration_checksum FROM database_migration_events WHERE migration_id LIKE 'schema41.%' AND state='completed' ORDER BY event_id");
    foreach($stmt->fetchAll() as $row){$migrationId=(string)$row['migration_id'];$actual=(string)$row['migration_checksum'];$expected=tegh_migration_checksum($migrationId);if(!hash_equals($expected,$actual))throw new RuntimeException('Migration checksum mismatch for '.$migrationId.'.');}
}

function tegh_schema41_repair_table(string $table): void
{
    $sql=tegh_schema41_table_sql()[$table]??null;$definitions=tegh_schema41_column_ddl()[$table]??null;if($sql===null||$definitions===null)throw new RuntimeException('Unknown Schema 41 table repair step.');
    if(!schema_table_exists($table)){db()->exec($sql);return;}
    $rows=tegh_schema_table_rows($table);$p=db()->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');$p->execute([$table]);$properties=$p->fetch()?:[];
    if(strtoupper((string)($properties['ENGINE']??''))!=='INNODB'||strtolower((string)($properties['TABLE_COLLATION']??''))!=='utf8mb4_unicode_ci'){if($rows>0)throw new RuntimeException('Populated Schema 41 table '.$table.' has a non-canonical engine or collation.');db()->exec("ALTER TABLE `$table` ENGINE=InnoDB");db()->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");}
    foreach($definitions as $column=>$definition){$actual=tegh_schema_column_actual($table,$column);$expected=tegh_schema40_expected_column($definition);if($actual===null){$repairable=$rows===0||($expected['nullable']??false)||($expected['default']??null)!==null;if(!$repairable)throw new RuntimeException('Populated Schema 41 table '.$table.' is missing required provenance column '.$column.'.');schema_add_column($table,$column,$definition);}elseif(!tegh_schema_contract_equal($expected,$actual)){if($rows>0)throw new RuntimeException('Populated Schema 41 column '.$table.'.'.$column.' has a non-canonical definition.');db()->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");}}
    foreach(tegh_schema41_index_contract()[$table]??[] as $name=>$expected){$actual=tegh_schema_index_actual($table,$name);if($actual!==null){if(!tegh_schema_contract_equal($expected,$actual))throw new RuntimeException('Schema 41 index '.$table.'.'.$name.' has a non-canonical contract.');continue;}$columns=implode(',',array_map(static fn(string $column):string=>'`'.$column.'`',$expected['columns']));if($expected['unique']&&tegh_schema40_duplicate_candidates($table,$expected['columns'])>0)throw new RuntimeException('Duplicate populated values prevent Schema 41 index '.$table.'.'.$name.'.');if($name==='PRIMARY')db()->exec("ALTER TABLE `$table` ADD PRIMARY KEY ($columns)");else schema_add_index($table,$name,$columns,(bool)$expected['unique']);}
    foreach(tegh_schema41_foreign_key_contract()[$table]??[] as $name=>$expected){$actual=tegh_schema_foreign_key_actual($table,$name);if($actual!==null){if(!tegh_schema_contract_equal($expected,$actual))throw new RuntimeException('Schema 41 foreign key '.$table.'.'.$name.' has a non-canonical contract.');continue;}if(tegh_schema40_orphan_candidates($table,$expected)>0)throw new RuntimeException('Orphaned rows prevent Schema 41 foreign key '.$table.'.'.$name.'.');$columns=implode(',',array_map(static fn(string $column):string=>'`'.$column.'`',explode(',',$expected['columns'])));$target=implode(',',array_map(static fn(string $column):string=>'`'.$column.'`',explode(',',$expected['targetColumns'])));db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY ($columns) REFERENCES `{$expected['targetTable']}` ($target) ON UPDATE {$expected['updateRule']} ON DELETE {$expected['deleteRule']}");}
    foreach(tegh_schema41_check_contract()[$table]??[] as $name=>$expected){$actual=tegh_schema40_check_actual($table,$name);if($actual!==null){if(tegh_schema40_normalize_check($expected)!==tegh_schema40_normalize_check($actual))throw new RuntimeException('Schema 41 check '.$table.'.'.$name.' has a non-canonical contract.');continue;}if(tegh_schema41_check_violation_candidates($table,$expected)>0)throw new RuntimeException('Populated rows violate Schema 41 check '.$table.'.'.$name.'.');db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK ($expected)");}
}

function tegh_schema41_table_ready(string $table): bool
{
    foreach(tegh_schema41_status()['issues'] as $issue)if((string)$issue['object']===$table||str_starts_with((string)$issue['object'],$table.'.'))return false;return true;
}

/** @return array<string,mixed> */
function tegh_schema41_preflight(): array
{
    $marker=current_database_schema_version();$schema40=tegh_schema40_status();$status=tegh_schema41_status();$state='unsupported_or_unsafe';$label='Unsupported or unsafe database state';$retry=false;$mutation=true;
    if($marker>41){$state='newer_schema';$label='Database is newer than this release';$mutation=false;}
    elseif(!$schema40['ready']){$state='schema40_prerequisite_incomplete';$label='Schema 40 prerequisite is incomplete';$retry=false;}
    elseif($marker===41&&$status['ready']){$state='complete_schema_41';$label='Database is already up to date';$retry=true;$mutation=false;}
    elseif($marker===41){$unsafe=array_filter($status['issues'],static fn(array $issue):bool=>empty($issue['repairable']));$state='marker_41_incomplete';$label=$unsafe?'Schema marker is 41 but vendor-learning storage is malformed':'Schema marker is 41 but vendor-learning storage is incomplete';$retry=$unsafe===[];}
    elseif($status['ready']){$state='schema_41_objects_stale_marker';$label='Schema 41 objects are complete but the marker is stale';$retry=true;}
    elseif($status['presentTableCount']===0){$state='complete_schema_40';$label='Complete Schema 40 is eligible for the Schema 41 additive upgrade';$retry=$marker===40;}
    else{$unsafe=array_filter($status['issues'],static fn(array $issue):bool=>empty($issue['repairable']));$state='partial_schema_41';$label=$unsafe?'Schema 41 contains a malformed partial contract':'Schema 41 was partially applied and can resume';$retry=$unsafe===[];}
    $capabilities=tegh_database_upgrade_capabilities();if($mutation&&(!$capabilities['privilegesReady']||!$capabilities['advisoryLockSupported']))$retry=false;
    $event=null;if(schema_table_exists('database_migration_events')){$q=db()->query("SELECT migration_id,state,request_reference,started_at,completed_at FROM database_migration_events WHERE migration_id LIKE 'schema41.%' ORDER BY event_id DESC LIMIT 1");$event=$q->fetch()?:null;}
    return ['state'=>$state,'stateLabel'=>$label,'markerVersion'=>$marker,'structuralVersion'=>$status['ready']?41:40,'expectedSchemaVersion'=>41,'ready'=>$state==='complete_schema_41','retrySafe'=>$retry,'mutationRequired'=>$mutation,'schema40Ready'=>(bool)$schema40['ready'],'contract'=>$status,'capabilities'=>$capabilities,'privateRuntime'=>tegh_private_runtime_status(),'lastMigrationEvent'=>$event,'build'=>SR_ACCOUNTAX_BUILD,'version'=>SR_ACCOUNTAX_VERSION,'requestReference'=>request_id(),'checkedAt'=>gmdate('c')];
}

/** @return array<string,mixed> */
function tegh_schema41_upgrade(array $user,bool $backupConfirmed): array
{
    $initial=tegh_schema41_preflight();$previous=(int)$initial['markerVersion'];$steps=[];if($initial['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'message'=>'Database is already up to date','previousSchema'=>41,'schemaVersion'=>41,'steps'=>[],'preflight'=>$initial];
    if(!$backupConfirmed)fail('Confirm the database, website files and private storage backup before starting the upgrade.',409,'database_backup_required');
    if(!$initial['schema40Ready'])fail('Complete and verify Schema 40 before applying the additive vendor-learning migration.',409,'schema40_prerequisite_incomplete');
    if(!$initial['retrySafe'])fail('The protected preflight found an unsupported or unsafe database state. View the diagnostic before retrying.',409,'schema_upgrade_unsafe');
    $lock=(int)db()->query("SELECT GET_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."',2)")->fetchColumn();if($lock!==1)fail('Another Tegh database upgrade is still running. Wait a moment and retry.',409,'schema_upgrade_busy');
    try{$fresh=tegh_schema41_preflight();if(!$fresh['retrySafe']&&!$fresh['ready'])throw new RuntimeException('Schema 41 preflight found an unsupported or unsafe partial contract.');if($fresh['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'message'=>'Database is already up to date','previousSchema'=>$previous,'schemaVersion'=>41,'steps'=>[],'preflight'=>$fresh];tegh_schema41_verify_completed_checksums();tegh_migration_ledger_ensure();
        foreach(tegh_schema41_supporting_index_contract() as $table=>$indexes)foreach($indexes as $name=>$expected)tegh_run_migration_step('schema41.5600.index.'.$name,static fn()=>tegh_schema41_repair_supporting_index($table,$name,$expected),static fn():bool=>tegh_schema41_supporting_index_ready($table,$name),$steps);
        foreach(tegh_schema41_table_sql() as $table=>$sql)tegh_run_migration_step('schema41.5600.table.'.$table,static fn()=>tegh_schema41_repair_table($table),static fn():bool=>tegh_schema41_table_ready($table),$steps);
        $status=tegh_schema41_status();if(!$status['ready'])throw new RuntimeException('Schema 41 post-migration verification is incomplete.');
        tegh_run_migration_step('schema41.5600.adopt_marker',static function():void{tegh_mark_schema_version(41);tegh_schema40_mark_checkpoint('schema_v41_data_ready','1');},static fn():bool=>current_database_schema_version()===41&&tegh_schema41_status()['ready'],$steps);
        system_incident_write_private_log(['requestId'=>request_id(),'occurredAt'=>gmdate('c'),'source'=>'schema41_vendor_learning','route'=>'startup/migrate','httpStatus'=>200,'errorCode'=>'schema_upgrade_completed','publicMessage'=>'Schema 41 upgrade completed.','context'=>system_incident_clean_context(['actorUserId'=>(string)$user['id'],'previousSchema'=>$previous,'resultingSchema'=>41,'steps'=>$steps,'accountingTransactionsPosted'=>0])]);
        return ['ok'=>true,'alreadyUpToDate'=>false,'message'=>'Upgrade successful','previousSchema'=>$previous,'schemaVersion'=>41,'steps'=>$steps,'preflight'=>tegh_schema41_preflight(),'accountingTransactionsPosted'=>0];
    }catch(Throwable $error){record_system_incident('Schema 41 upgrade could not be completed. Schema 40 core accounting remains available; reviewed vendor learning is unavailable.',500,'schema41_upgrade_failed',$error,['source'=>'schema41_vendor_learning','completedSteps'=>$steps,'initialPreflight'=>$initial]);throw $error;}finally{try{db()->query("SELECT RELEASE_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."')");}catch(Throwable){}}
}

function handle_tegh_schema41_preflight(): never
{
    require_method('GET');tegh_require_platform_owner();$result=tegh_schema41_preflight();system_incident_write_private_log(['requestId'=>request_id(),'occurredAt'=>gmdate('c'),'source'=>'schema41_preflight','route'=>'startup/migration-preflight','httpStatus'=>200,'errorCode'=>'schema41_preflight','publicMessage'=>(string)$result['stateLabel'],'context'=>system_incident_clean_context($result)]);json_response(['ok'=>true,'preflight'=>$result]);
}

function handle_tegh_schema41_diagnostic(): never
{
    require_method('GET');tegh_require_platform_owner();$result=tegh_schema41_preflight();$events=[];if(schema_table_exists('database_migration_events')){$stmt=db()->query('SELECT migration_id,migration_checksum,state,started_at,completed_at,request_reference,build_number,details_json FROM database_migration_events ORDER BY event_id DESC LIMIT 150');foreach($stmt->fetchAll() as $row){$row['details']=json_decode((string)$row['details_json'],true)?:[];unset($row['details_json']);$events[]=$row;}}json_response(['ok'=>true,'diagnostic'=>$result,'migrationEvents'=>$events]);
}

function handle_tegh_schema41_upgrade(): never
{
    require_method('POST');$user=tegh_require_platform_owner();require_csrf();$input=request_json();@ignore_user_abort(true);@set_time_limit(85);try{json_response(tegh_schema41_upgrade($user,!empty($input['backupConfirmed'])));}catch(Throwable $error){error_log('Tegh Schema 41 request='.request_id().' class='.$error::class.' message='.system_incident_redact_text($error->getMessage(),1000));fail('Database upgrade could not be completed. No accounting entries were changed. Structural preparation may be partial; Schema 40 core accounting remains available when separately verified. Give this request reference to the Platform Owner: '.request_id().'.',500,'schema_upgrade_failed',false);}
}
