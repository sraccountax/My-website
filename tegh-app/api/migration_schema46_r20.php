<?php
declare(strict_types=1);

/** R20: additive storage only. All DDL is behind the protected upgrade. */
function tegh_schema46_table_sql(): array
{
    return [
'invoice_document_operations'=><<<'SQL'
CREATE TABLE IF NOT EXISTS invoice_document_operations (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 company_id VARCHAR(64) NOT NULL,
 invoice_id VARCHAR(64) NOT NULL,
 actor_id VARCHAR(64) NOT NULL,
 operation_type VARCHAR(20) NOT NULL,
 operation_key VARCHAR(120) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 source_revision CHAR(64) NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'pending',
 result_json LONGTEXT NULL,
 outbound_email_id VARCHAR(64) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY invoice_operation_key_uq (company_id,actor_id,operation_key),
 KEY invoice_operation_history (company_id,invoice_id,operation_type,created_at),
 CONSTRAINT invoice_operation_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT invoice_operation_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
 CONSTRAINT invoice_operation_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT invoice_operation_email_fk FOREIGN KEY (outbound_email_id) REFERENCES outbound_emails(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
'invoice_attachments'=><<<'SQL'
CREATE TABLE IF NOT EXISTS invoice_attachments (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 company_id VARCHAR(64) NOT NULL,
 invoice_id VARCHAR(64) NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 storage_path VARCHAR(500) NOT NULL,
 mime VARCHAR(100) NOT NULL,
 size_bytes BIGINT NOT NULL,
 sha256 CHAR(64) NOT NULL,
 uploaded_by VARCHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 removed_by VARCHAR(64) NULL,
 removed_at DATETIME NULL,
 KEY invoice_attachment_owner (company_id,invoice_id,removed_at),
 CONSTRAINT invoice_attachment_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT invoice_attachment_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
 CONSTRAINT invoice_attachment_actor_fk FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT invoice_attachment_remove_actor_fk FOREIGN KEY (removed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    ];
}
function tegh_schema46_status(): array
{
    $issues=[];$pdo=db();
    foreach(tegh_schema46_table_sql() as $table=>$sql){
        $s=$pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);$actual=[];foreach($s->fetchAll() as $row)$actual[$row['COLUMN_NAME']]=$row;
        if(!$actual){$issues[]=['object'=>$table,'code'=>'missing_table','repairable'=>true];continue;}
        preg_match_all('/(?:\(|,)\s*([a-z_]+)\s+(VARCHAR|CHAR|INT|BIGINT|MEDIUMTEXT|LONGTEXT|TEXT|DATETIME)\b(?:\((\d+)\))?([^,\n]*)/i',$sql,$columns,PREG_SET_ORDER);
        foreach($columns as $column){
            $name=$column[1];$type=strtolower($column[2]);$expectedSize=$column[3]??'';$got=$actual[$name]??null;
            if(!$got){$issues[]=['object'=>"$table.$name",'code'=>'missing_column','repairable'=>false];continue;}
            $nullable=!str_contains(strtoupper($column[4]),'NOT NULL')&&!str_contains(strtoupper($column[4]),'PRIMARY KEY');
            if(strtolower($got['DATA_TYPE'])!==$type||($expectedSize!==''&&!str_starts_with(strtolower($got['COLUMN_TYPE']),"$type($expectedSize)"))||(!$nullable&&$got['IS_NULLABLE']!=='NO')||(str_contains(strtoupper($column[4]),'UNSIGNED')&&!str_contains(strtolower($got['COLUMN_TYPE']),'unsigned')))$issues[]=['object'=>"$table.$name",'code'=>'column_contract_mismatch','repairable'=>false];
        }
        $idx=$pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');$idx->execute([$table]);$indexes=[];
        foreach($idx->fetchAll() as $row){$key=(string)$row['INDEX_NAME'];$indexes[$key]['unique']=(int)$row['NON_UNIQUE']===0;$indexes[$key]['columns'][]=(string)$row['COLUMN_NAME'];}
        foreach(tegh_schema44_index_contract($sql) as $key=>$expected){
            if(!isset($indexes[$key]))$issues[]=['object'=>"$table.$key",'code'=>'missing_index','repairable'=>false];
            elseif($indexes[$key]!==$expected)$issues[]=['object'=>"$table.$key",'code'=>'index_contract_mismatch','repairable'=>false];
        }
        preg_match_all('/CONSTRAINT\s+([a-z_0-9]+)\s+FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+([a-z_0-9]+)\s*\(([^)]+)\)\s+ON\s+DELETE\s+(CASCADE|RESTRICT|SET NULL|NO ACTION)/i',$sql,$fks,PREG_SET_ORDER);
        if($fks){
            $fk=$pdo->prepare('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=?');$fk->execute([$table]);$actualFk=[];
            foreach($fk->fetchAll() as $row)$actualFk[$row['CONSTRAINT_NAME']]=$row;
            foreach($fks as $m){$got=$actualFk[$m[1]]??null;if(!$got||$got['COLUMN_NAME']!==trim($m[2])||$got['REFERENCED_TABLE_NAME']!==$m[3]||$got['REFERENCED_COLUMN_NAME']!==trim($m[4])||strtoupper($got['DELETE_RULE'])!==strtoupper($m[5]))$issues[]=['object'=>$table.'.'.$m[1],'code'=>'foreign_key_contract_mismatch','repairable'=>false];}
        }
    }
    return ['ready'=>$issues===[],'issues'=>$issues];
}

function tegh_schema46_require(): void
{
    if(current_database_schema_version()<46 || !tegh_schema46_status()['ready'])
        fail('A Platform Owner must complete the protected Schema 46 upgrade before invoice editing, sending or attachments can be used.',503,'schema46_required');
}
function tegh_schema46_preflight(): array
{
    $marker=current_database_schema_version(); $contract=tegh_schema46_status();
    $prior=$marker>=45?(tegh_schema44_status()['ready']&&tegh_schema45_status()['ready']):($marker>=43 && tegh_schema45_preflight()['retrySafe']);
    if($marker>=45){
        try{foreach(['schema45.5990.data','schema45.5990.interbank_lifecycle_v2','schema45.5990.interbank_lifecycle_v3'] as $receipt)if(!tegh_migration_completed($receipt))$prior=false;}
        catch(Throwable){$prior=false;}
    }
    $safe=$marker>=43 && $marker<=46 && $prior && array_filter($contract['issues'],static fn($i)=>empty($i['repairable']))===[];
    $ledger=false;
    try {$ledger=tegh_migration_completed('schema46.5990.invoice_documents');}
    catch(Throwable){$safe=false; $contract['issues'][]=['object'=>'database_migration_events','code'=>'completed_checksum_mismatch','repairable'=>false];}
    $ready=$marker===46 && $contract['ready'] && $prior && $ledger && $safe;
    return ['ready'=>$ready,'retrySafe'=>$safe,'mutationRequired'=>!$ready,
        'state'=>$ready?'complete_schema_46':($safe?'schema46_preparation_required':'schema46_diagnostic_required'),
        'stateLabel'=>$ready?'Database is up to date':'Prepare protected invoice documents and delivery receipts',
        'markerVersion'=>$marker,'structuralVersion'=>$contract['ready']?46:($prior?45:0),
        'expectedSchemaVersion'=>46,'contract'=>$contract,'build'=>SR_ACCOUNTAX_BUILD,'version'=>SR_ACCOUNTAX_VERSION,
        'requestReference'=>request_id(),'checkedAt'=>gmdate('c')];
}
function tegh_schema46_upgrade(array $user,bool $backupConfirmed): array
{
    if(!$backupConfirmed)fail('Confirm a verified database, application and private-storage backup.',409,'database_backup_required');
    $marker=current_database_schema_version();
    if($marker<45)tegh_schema45_upgrade($user,true);
    elseif($marker===45 && !tegh_schema45_preflight()['ready'])tegh_schema45_upgrade($user,true);
    $initial=tegh_schema46_preflight();
    if($initial['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'schemaVersion'=>46,'steps'=>[],'preflight'=>$initial,'accountingTransactionsPosted'=>0];
    if(!$initial['retrySafe'])fail('Review the Schema 46 diagnostic before upgrading.',409,'schema_upgrade_unsafe');
    $maintenance=tegh_maintenance_mode_begin('schema46_upgrade');
    if($maintenance==='')fail('Another maintenance operation is active.',409,'maintenance_mode_busy');
    $lock=0;$steps=[];
    try {
        $lock=(int)db()->query("SELECT GET_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."',2)")->fetchColumn();
        if($lock!==1)throw new RuntimeException('Database upgrade lock is busy.');
        if(!tegh_schema46_preflight()['retrySafe'])throw new RuntimeException('Database state changed before the upgrade lock.');
        tegh_migration_ledger_ensure();
        tegh_run_migration_step('schema46.5990.invoice_documents',static function():void{
            foreach(tegh_schema46_table_sql() as $sql)db()->exec($sql);
        },static fn():bool=>tegh_schema46_status()['ready'],$steps);
        tegh_run_schema_marker_step('schema46.5990.adopt_marker',46,static fn():bool=>tegh_schema46_status()['ready'],$steps);
        return ['ok'=>true,'schemaVersion'=>46,'previousSchema'=>$marker,'steps'=>$steps,'preflight'=>tegh_schema46_preflight(),'accountingTransactionsPosted'=>0];
    } finally {
        if($lock===1)db()->query("SELECT RELEASE_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."')");
        tegh_maintenance_mode_end($maintenance);
    }
}
function handle_tegh_schema46_preflight(): never {require_method('GET');tegh_require_platform_owner();json_response(['ok'=>true,'preflight'=>tegh_schema46_preflight()]);}
function handle_tegh_schema46_diagnostic(): never {require_method('GET');tegh_require_platform_owner();json_response(['ok'=>true,'diagnostic'=>tegh_schema46_preflight()]);}
function handle_tegh_schema46_upgrade(): never
{
    require_method('POST');$user=tegh_require_platform_owner();require_csrf();$input=request_json();@ignore_user_abort(true);@set_time_limit(180);
    try{json_response(tegh_schema46_upgrade($user,!empty($input['backupConfirmed'])));}
    catch(Throwable $error){record_system_incident('Schema 46 upgrade did not finish. No accounting transaction was posted.',500,'schema46_upgrade_failed',$error);fail('The upgrade did not finish. Review the diagnostic. Reference: '.request_id(),500,'schema_upgrade_failed',false);}
}
