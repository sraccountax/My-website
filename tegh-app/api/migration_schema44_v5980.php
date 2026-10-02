<?php
declare(strict_types=1);

function tegh_schema44_table_sql(): array
{
    return [
        'bank_transaction_source_text' => <<<'SQL'
CREATE TABLE IF NOT EXISTS bank_transaction_source_text (
 transaction_id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL,
 source_fingerprint CHAR(64) NOT NULL, full_description MEDIUMTEXT NOT NULL,
 evidence_version INT NOT NULL DEFAULT 1,
 KEY bank_source_company (company_id),
 CONSTRAINT bank_source_transaction_fk FOREIGN KEY (transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE,
 CONSTRAINT bank_source_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'platform_release_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS platform_release_settings (
 setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
 value_json VARCHAR(20) NOT NULL, revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_by VARCHAR(64) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'platform_release_setting_history' => <<<'SQL'
CREATE TABLE IF NOT EXISTS platform_release_setting_history (
 id VARCHAR(64) NOT NULL PRIMARY KEY, setting_key VARCHAR(120) NOT NULL,
 old_value VARCHAR(20) NOT NULL, new_value VARCHAR(20) NOT NULL, actor_user_id VARCHAR(64) NOT NULL,
 reason VARCHAR(1000) NOT NULL, request_reference VARCHAR(80) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY setting_history_key_time (setting_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'account_invitations' => <<<'SQL'
CREATE TABLE IF NOT EXISTS account_invitations (
 id VARCHAR(64) NOT NULL PRIMARY KEY, email VARCHAR(254) NOT NULL,
 scope VARCHAR(20) NOT NULL, assignment_count INT NOT NULL DEFAULT 0,
 invited_by VARCHAR(64) NOT NULL, operation_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'pending', accepted_by VARCHAR(64) NULL, accepted_at DATETIME NULL,
 expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY invitation_operation_uq (invited_by,operation_key), KEY invitation_email (email,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'account_invitation_companies' => <<<'SQL'
CREATE TABLE IF NOT EXISTS account_invitation_companies (
 invitation_id VARCHAR(64) NOT NULL, company_id VARCHAR(64) NOT NULL,
 role VARCHAR(20) NOT NULL, PRIMARY KEY (invitation_id,company_id), KEY invitation_company (company_id),
 CONSTRAINT invitation_assignment_parent_fk FOREIGN KEY (invitation_id) REFERENCES account_invitations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'account_invitation_tokens' => <<<'SQL'
CREATE TABLE IF NOT EXISTS account_invitation_tokens (
 id VARCHAR(64) NOT NULL PRIMARY KEY, invitation_id VARCHAR(64) NOT NULL,
 token_hash CHAR(64) NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'active', expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY invitation_token_hash_uq (token_hash), KEY invitation_tokens_parent (invitation_id),
 CONSTRAINT invitation_token_parent_fk FOREIGN KEY (invitation_id) REFERENCES account_invitations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'account_invitation_mail' => <<<'SQL'
CREATE TABLE IF NOT EXISTS account_invitation_mail (
 id VARCHAR(64) NOT NULL PRIMARY KEY, invitation_id VARCHAR(64) NOT NULL,
 token_id VARCHAR(64) NOT NULL, token_cipher LONGTEXT NOT NULL,
 operation_key VARCHAR(120) NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'deferred',
 outbound_email_id VARCHAR(64) NULL, diagnostic_json TEXT NULL, attempt_count INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY invitation_mail_operation_uq (invitation_id,operation_key),
 CONSTRAINT invitation_mail_parent_fk FOREIGN KEY (invitation_id) REFERENCES account_invitations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'invitation_attempts' => <<<'SQL'
CREATE TABLE IF NOT EXISTS invitation_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 token_hash CHAR(64) NOT NULL, ip_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY invitation_attempt_token (token_hash,created_at), KEY invitation_attempt_ip (ip_hash,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'terms_acceptances' => <<<'SQL'
CREATE TABLE IF NOT EXISTS terms_acceptances (
 id VARCHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(64) NOT NULL,
 invitation_id VARCHAR(64) NULL, terms_version VARCHAR(40) NOT NULL, privacy_version VARCHAR(40) NOT NULL,
 request_reference VARCHAR(80) NOT NULL, accepted_at DATETIME NOT NULL,
 KEY terms_user_time (user_id,accepted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'bank_bulk_operations' => <<<'SQL'
CREATE TABLE IF NOT EXISTS bank_bulk_operations (
 id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL,
 user_id VARCHAR(64) NOT NULL, operation_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 selected_count INT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY bank_bulk_operation_uq (company_id,user_id,operation_key),
 CONSTRAINT bank_bulk_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'bank_bulk_operation_rows' => <<<'SQL'
CREATE TABLE IF NOT EXISTS bank_bulk_operation_rows (
 operation_id VARCHAR(64) NOT NULL, transaction_id VARCHAR(64) NOT NULL,
 ordinal INT NOT NULL, decision_json TEXT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'pending',
 result_json TEXT NULL, attempts INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (operation_id,transaction_id), UNIQUE KEY bank_bulk_ordinal_uq (operation_id,ordinal),
 CONSTRAINT bank_bulk_row_parent_fk FOREIGN KEY (operation_id) REFERENCES bank_bulk_operations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'bank_category_commits' => <<<'SQL'
CREATE TABLE IF NOT EXISTS bank_category_commits (
 id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL,
 user_id VARCHAR(64) NOT NULL, import_type VARCHAR(64) NOT NULL DEFAULT 'bank_transaction_categories',
 operation_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, preview_hash CHAR(64) NOT NULL,
 result_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY bank_category_operation_uq (company_id,user_id,operation_key),
 CONSTRAINT bank_category_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'release_data_retirements' => <<<'SQL'
CREATE TABLE IF NOT EXISTS release_data_retirements (
 source_type VARCHAR(80) NOT NULL, source_id VARCHAR(64) NOT NULL,
 reason VARCHAR(500) NOT NULL, retired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (source_type,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];
}

/** Existing Schema 44 ledger IDs are immutable across application build changes. */
function tegh_schema44_index_contract(string $sql): array
{
    $out=[];
    if(preg_match('/PRIMARY\s+KEY\s*\(([^)]+)\)/i',$sql,$m))$out['PRIMARY']=['unique'=>true,'columns'=>array_map('trim',explode(',',$m[1]))];
    elseif(preg_match('/(?:\(|,)\s*([a-z_]+)\s+[^,\n]*?PRIMARY\s+KEY/i',$sql,$m))$out['PRIMARY']=['unique'=>true,'columns'=>[$m[1]]];
    preg_match_all('/\b(UNIQUE\s+)?KEY\s+([a-z_0-9]+)\s*\(([^)]+)\)/i',$sql,$matches,PREG_SET_ORDER);
    foreach($matches as $m)$out[$m[2]]=['unique'=>trim($m[1])!=='','columns'=>array_map('trim',explode(',',$m[3]))];
    return $out;
}
function tegh_schema44_status(): array
{
    $issues=[];$pdo=db();
    foreach(tegh_schema44_table_sql() as $table=>$sql){
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
function tegh_schema44_require(): void
{
    if(current_database_schema_version()<44||!tegh_schema44_status()['ready'])fail('A Platform Owner must complete the protected Schema 44 upgrade before this workflow can be used.',503,'schema44_required');
}
function tegh_schema44_preflight(): array
{
    $marker=current_database_schema_version();$contract=tegh_schema44_status();$prior=tegh_schema43_status();$issues=$contract['issues'];
    $safe=$marker<=44&&$marker>=43&&$prior['ready']&&array_filter($issues,static fn($x)=>empty($x['repairable']))===[];
    $dataReady=false;
    if(schema_table_exists('database_migration_events')){
        try{$dataReady=tegh_migration_completed('schema44.5970.native_data');foreach(['schema44.5970.tables','schema44.5970.native_data','schema44.5970.adopt_marker'] as $id)tegh_migration_completed($id);}
        catch(Throwable){$safe=false;$issues[]=['object'=>'database_migration_events','code'=>'completed_checksum_mismatch','repairable'=>false];}
    }
    $ready=$marker===44&&$contract['ready']&&$prior['ready']&&$dataReady&&$safe;
    return ['ready'=>$ready,'retrySafe'=>$safe,'mutationRequired'=>!$ready,'state'=>$ready?'complete_schema_44':($safe?'schema44_preparation_required':'schema44_diagnostic_required'),'stateLabel'=>$ready?'Database is up to date':'Complete native release storage and data migration','markerVersion'=>$marker,'structuralVersion'=>$contract['ready']?44:($prior['ready']?43:0),'expectedSchemaVersion'=>44,'contract'=>['ready'=>$ready,'issues'=>$issues],'build'=>SR_ACCOUNTAX_BUILD,'version'=>SR_ACCOUNTAX_VERSION,'requestReference'=>request_id(),'checkedAt'=>gmdate('c')];
}
function tegh_schema44_data(array $actor): void
{
    require_once __DIR__.'/native_agents.php';
    db_transaction_retry(function()use($actor):void{
        db()->exec("INSERT IGNORE INTO platform_release_settings (setting_key,value_json,revision) VALUES ('public_registration','false',1)");
        $s=db()->query("SELECT feature_key FROM feature_catalog WHERE operational_state='active' AND permitted_scope='company' ORDER BY feature_key");
        foreach($s->fetchAll(PDO::FETCH_COLUMN) as $key)if(!tegh_provider_feature((string)$key))db()->prepare("UPDATE feature_catalog SET default_decision='enabled',billable=0,metered=0 WHERE feature_key=? AND operational_state='active'")->execute([$key]);
        foreach(db()->query('SELECT id,name FROM companies WHERE active=1 ORDER BY id')->fetchAll() as $company){tegh_native_package_initialize((string)$company['id'],(string)$company['name'],$actor);tegh_disable_stale_connected_policy($company,$actor);}
        if(schema_table_exists('company_invitations')){
            db()->exec("INSERT IGNORE INTO account_invitations (id,email,scope,assignment_count,invited_by,operation_key,payload_hash,status,accepted_by,accepted_at,expires_at,created_at) SELECT id,email,scope,CASE WHEN scope='company' THEN 1 ELSE 0 END,invited_by,CONCAT('legacy-',id),SHA2(CONCAT('legacy:',id),256),status,accepted_by,accepted_at,expires_at,created_at FROM company_invitations");
            db()->exec("INSERT IGNORE INTO account_invitation_companies (invitation_id,company_id,role) SELECT id,company_id,role FROM company_invitations WHERE scope='company'");
            db()->exec("INSERT IGNORE INTO account_invitation_tokens (id,invitation_id,token_hash,status,expires_at,created_at) SELECT SHA2(CONCAT('legacy-token:',id),256),id,token_hash,CASE WHEN status='pending' THEN 'active' ELSE 'revoked' END,expires_at,created_at FROM company_invitations");
        }
        $last='';
        do{
            $page=db()->prepare("SELECT sp.id,sp.company_id,sp.rows_json,sp.approved_batch_id FROM statement_previews sp WHERE sp.status='approved' AND sp.id>? ORDER BY sp.id LIMIT 50");$page->execute([$last]);$previews=$page->fetchAll(PDO::FETCH_ASSOC);
            foreach($previews as $preview){$last=(string)$preview['id'];$source=json_decode((string)$preview['rows_json'],true);if(!is_array($source))continue;
                $find=db()->prepare('SELECT id,description FROM bank_transactions WHERE company_id=? AND import_batch_id=? AND source_hash=?');
                $save=db()->prepare('INSERT IGNORE INTO bank_transaction_source_text(transaction_id,company_id,source_fingerprint,full_description) VALUES(?,?,?,?)');
                foreach($source as $row){if(!is_array($row)||empty($row['sourceFingerprint'])||!isset($row['fullDescription']))continue;$find->execute([$preview['company_id'],$preview['approved_batch_id'],$row['sourceFingerprint']]);$tx=$find->fetch(PDO::FETCH_ASSOC);if(!$tx||mb_substr((string)$row['fullDescription'],0,500)!==(string)$tx['description'])continue;$save->execute([$tx['id'],$preview['company_id'],$row['sourceFingerprint'],$row['fullDescription']]);}
            }
        }while(count($previews)===50);
        // Retire, do not delete/rewrite, original signup-intent history. No active path consumes it.
        if(schema_table_exists('signup_feature_intents'))db()->exec("INSERT IGNORE INTO release_data_retirements (source_type,source_id,reason) SELECT 'signup_feature_intents',id,'Native package included; pending signup selection retired in Schema 44.' FROM signup_feature_intents WHERE state='pending'");
    });
}
function tegh_schema44_upgrade(array $user,bool $backupConfirmed): array
{
    if(!$backupConfirmed)fail('Confirm a verified database, application and private-storage backup.',409,'database_backup_required');
    $marker=current_database_schema_version();
    if($marker<43)tegh_schema43_upgrade($user,true);
    $initial=tegh_schema44_preflight();if($initial['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'schemaVersion'=>44,'steps'=>[],'preflight'=>$initial,'accountingTransactionsPosted'=>0];
    if(!$initial['retrySafe'])fail('Review the Schema 44 diagnostic before upgrading.',409,'schema_upgrade_unsafe');
    $maintenance=tegh_maintenance_mode_begin('schema44_upgrade');if($maintenance==='')fail('Another maintenance operation is active.',409,'maintenance_mode_busy');$lock=0;$steps=[];
    try{
        $lock=(int)db()->query("SELECT GET_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."',2)")->fetchColumn();if($lock!==1)throw new RuntimeException('Database upgrade lock is busy.');
        if(!tegh_schema44_preflight()['retrySafe'])throw new RuntimeException('The database changed before the upgrade lock was acquired.');
        tegh_migration_ledger_ensure();
        tegh_run_migration_step('schema44.5970.tables',static function():void{foreach(tegh_schema44_table_sql() as $sql)db()->exec($sql);},static fn():bool=>tegh_schema44_status()['ready'],$steps);
        tegh_run_migration_step('schema44.5970.native_data',static fn()=>tegh_schema44_data($user),static function():bool{$s=db()->query("SELECT COUNT(*) FROM platform_release_settings WHERE setting_key='public_registration'");return (int)$s->fetchColumn()===1;},$steps);
        tegh_run_schema_marker_step('schema44.5970.adopt_marker',44,static fn():bool=>tegh_schema44_status()['ready'],$steps);
        return ['ok'=>true,'alreadyUpToDate'=>false,'schemaVersion'=>44,'previousSchema'=>$marker,'steps'=>$steps,'preflight'=>tegh_schema44_preflight(),'accountingTransactionsPosted'=>0];
    }finally{if($lock===1)db()->query("SELECT RELEASE_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."')");tegh_maintenance_mode_end($maintenance);}
}
function handle_tegh_schema44_preflight(): never {require_method('GET');tegh_require_platform_owner();json_response(['ok'=>true,'preflight'=>tegh_schema44_preflight()]);}
function handle_tegh_schema44_diagnostic(): never {require_method('GET');tegh_require_platform_owner();json_response(['ok'=>true,'diagnostic'=>tegh_schema44_preflight()]);}
function handle_tegh_schema44_upgrade(): never
{
    require_method('POST');$user=tegh_require_platform_owner();require_csrf();$input=request_json();@ignore_user_abort(true);@set_time_limit(180);
    try{json_response(tegh_schema44_upgrade($user,!empty($input['backupConfirmed'])));}catch(Throwable $error){record_system_incident('Schema 44 upgrade did not finish. No accounting transaction was posted.',500,'schema44_upgrade_failed',$error);fail('The upgrade did not finish. Review the diagnostic. Reference: '.request_id(),500,'schema_upgrade_failed',false);}
}
