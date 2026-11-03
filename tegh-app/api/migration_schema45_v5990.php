<?php
declare(strict_types=1);

const TEGH_SCHEMA45_VERSION = 45;

function tegh_schema45_table_sql(): array
{
    return [
        'party_payment_application_operations'=><<<'SQL'
CREATE TABLE IF NOT EXISTS party_payment_application_operations (
 id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL,
 operation_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 status ENUM('active','completed','failed') NOT NULL DEFAULT 'active', result_json LONGTEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY payment_application_operation_uq (company_id,user_id,operation_key),
 KEY payment_application_operation_company_status (company_id,status,created_at),
 CONSTRAINT payment_application_operation_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT payment_application_operation_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'party_payment_applications'=><<<'SQL'
CREATE TABLE IF NOT EXISTS party_payment_applications (
 id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, payment_id VARCHAR(64) NOT NULL,
 document_type ENUM('invoice','bill') NOT NULL, document_id VARCHAR(64) NOT NULL,
 partner_type ENUM('customer','vendor') NOT NULL, partner_id VARCHAR(64) NOT NULL,
 application_date DATE NOT NULL, foreign_amount_cents BIGINT NOT NULL,
 payment_carrying_cents BIGINT NOT NULL, document_carrying_cents BIGINT NOT NULL,
 recognition_journal_entry_id VARCHAR(64) NULL, status ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
 reversal_of_id VARCHAR(64) NULL, operation_id VARCHAR(64) NOT NULL,
 created_by VARCHAR(64) NOT NULL, reversed_by VARCHAR(64) NULL, reversed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY payment_applications_payment_status (company_id,payment_id,status,application_date),
 KEY payment_applications_document_status (company_id,document_type,document_id,status,application_date),
 KEY payment_applications_partner_status (company_id,partner_type,partner_id,status,application_date),
 UNIQUE KEY payment_application_operation_row_uq (operation_id,payment_id,document_type,document_id),
 CONSTRAINT payment_applications_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT payment_applications_payment_fk FOREIGN KEY (payment_id) REFERENCES party_payments(id) ON DELETE CASCADE,
 CONSTRAINT payment_applications_journal_fk FOREIGN KEY (recognition_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
 CONSTRAINT payment_applications_operation_fk FOREIGN KEY (operation_id) REFERENCES party_payment_application_operations(id),
 CONSTRAINT payment_applications_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
 CONSTRAINT payment_applications_reversed_user_fk FOREIGN KEY (reversed_by) REFERENCES users(id),
 CONSTRAINT payment_applications_reversal_fk FOREIGN KEY (reversal_of_id) REFERENCES party_payment_applications(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'payment_legacy_mapping_reviews'=><<<'SQL'
CREATE TABLE IF NOT EXISTS payment_legacy_mapping_reviews (
 payment_id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL,
 reason_code VARCHAR(80) NOT NULL, evidence_json LONGTEXT NOT NULL,
 status ENUM('review_required','resolved') NOT NULL DEFAULT 'review_required', resolved_by VARCHAR(64) NULL,
 resolved_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY payment_legacy_review_company_status (company_id,status,created_at),
 CONSTRAINT payment_legacy_review_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT payment_legacy_review_payment_fk FOREIGN KEY (payment_id) REFERENCES party_payments(id) ON DELETE CASCADE,
 CONSTRAINT payment_legacy_review_user_fk FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'interbank_transfers'=><<<'SQL'
CREATE TABLE IF NOT EXISTS interbank_transfers (
 id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL,
 source_bank_account_id VARCHAR(64) NOT NULL, destination_bank_account_id VARCHAR(64) NOT NULL,
 currency CHAR(3) NOT NULL, amount_cents BIGINT NOT NULL, journal_entry_id VARCHAR(64) NOT NULL,
 operation_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, remarks VARCHAR(500) NOT NULL DEFAULT '',
 status ENUM('awaiting_counterpart','matched','reversed','needs_review') NOT NULL DEFAULT 'awaiting_counterpart',
 reversal_journal_entry_id VARCHAR(64) NULL, reversed_by VARCHAR(64) NULL, reversed_at DATETIME NULL, reversal_reason VARCHAR(500) NULL,
 created_by VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY interbank_transfer_operation_uq (company_id,created_by,operation_key),
 UNIQUE KEY interbank_transfer_journal_uq (company_id,journal_entry_id),
 KEY interbank_transfer_candidates (company_id,source_bank_account_id,destination_bank_account_id,currency,amount_cents,status),
 CONSTRAINT interbank_transfer_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT interbank_transfer_source_bank_fk FOREIGN KEY (source_bank_account_id) REFERENCES bank_accounts(id),
 CONSTRAINT interbank_transfer_destination_bank_fk FOREIGN KEY (destination_bank_account_id) REFERENCES bank_accounts(id),
 CONSTRAINT interbank_transfer_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
 CONSTRAINT interbank_transfer_reversal_journal_fk FOREIGN KEY (reversal_journal_entry_id) REFERENCES journal_entries(id),
 CONSTRAINT interbank_transfer_reversed_user_fk FOREIGN KEY (reversed_by) REFERENCES users(id),
 CONSTRAINT interbank_transfer_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'interbank_transfer_legs'=><<<'SQL'
CREATE TABLE IF NOT EXISTS interbank_transfer_legs (
 id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, transfer_id VARCHAR(64) NOT NULL,
 bank_transaction_id VARCHAR(64) NOT NULL, bank_account_id VARCHAR(64) NOT NULL, journal_line_id VARCHAR(64) NOT NULL,
 link_kind ENUM('posted_origin','matched_counterpart') NOT NULL DEFAULT 'posted_origin', operation_key VARCHAR(120) NULL, payload_hash CHAR(64) NULL,
 leg_role ENUM('source','destination') NOT NULL, status ENUM('linked','unmatched') NOT NULL DEFAULT 'linked',
 linked_by VARCHAR(64) NOT NULL, linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, unmatched_by VARCHAR(64) NULL, unmatched_at DATETIME NULL,
 UNIQUE KEY interbank_transfer_bank_transaction_uq (company_id,bank_transaction_id),
 UNIQUE KEY interbank_transfer_account_leg_uq (transfer_id,bank_account_id),
 UNIQUE KEY interbank_transfer_journal_line_uq (company_id,journal_line_id),
 UNIQUE KEY interbank_transfer_leg_operation_uq (company_id,linked_by,operation_key),
 KEY interbank_transfer_legs_transfer_status (company_id,transfer_id,status),
 CONSTRAINT interbank_transfer_legs_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT interbank_transfer_legs_transfer_fk FOREIGN KEY (transfer_id) REFERENCES interbank_transfers(id) ON DELETE CASCADE,
 CONSTRAINT interbank_transfer_legs_transaction_fk FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id),
 CONSTRAINT interbank_transfer_legs_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
 CONSTRAINT interbank_transfer_legs_line_fk FOREIGN KEY (journal_line_id) REFERENCES journal_lines(id),
 CONSTRAINT interbank_transfer_legs_linked_user_fk FOREIGN KEY (linked_by) REFERENCES users(id),
 CONSTRAINT interbank_transfer_legs_unmatched_user_fk FOREIGN KEY (unmatched_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'interbank_transfer_operations'=><<<'SQL'
CREATE TABLE IF NOT EXISTS interbank_transfer_operations (
 id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, transfer_id VARCHAR(64) NULL,
 initiated_by VARCHAR(64) NOT NULL, operation_key VARCHAR(120) NOT NULL,
 operation_type ENUM('adopt','unmatch','reverse') NOT NULL, payload_hash CHAR(64) NOT NULL,
 status ENUM('pending_approval','completed') NOT NULL DEFAULT 'completed', result_json LONGTEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY interbank_operation_key_uq (company_id,operation_key),
 KEY interbank_operation_transfer_status (company_id,transfer_id,operation_type,status),
 CONSTRAINT interbank_operation_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT interbank_operation_transfer_fk FOREIGN KEY (transfer_id) REFERENCES interbank_transfers(id) ON DELETE SET NULL,
 CONSTRAINT interbank_operation_user_fk FOREIGN KEY (initiated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'bank_statement_balance_anchors'=><<<'SQL'
CREATE TABLE IF NOT EXISTS bank_statement_balance_anchors (
 id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, bank_account_id VARCHAR(64) NOT NULL,
 anchor_date DATE NOT NULL, balance_cents BIGINT NOT NULL, currency CHAR(3) NOT NULL,
 provenance ENUM('source_file','source_metadata','prior_statement','user_statement') NOT NULL,
 source_checksum CHAR(64) NOT NULL, evidence_json LONGTEXT NOT NULL, revision BIGINT UNSIGNED NOT NULL,
 status ENUM('active','superseded','disputed') NOT NULL DEFAULT 'active', created_by VARCHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY statement_anchor_revision_uq (company_id,bank_account_id,revision),
 KEY statement_anchor_account_date (company_id,bank_account_id,anchor_date,status),
 CONSTRAINT statement_anchor_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT statement_anchor_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
 CONSTRAINT statement_anchor_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'bank_import_control_receipts'=><<<'SQL'
CREATE TABLE IF NOT EXISTS bank_import_control_receipts (
 id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL,
 preview_id VARCHAR(64) NULL, import_batch_id VARCHAR(64) NULL, bank_account_id VARCHAR(64) NOT NULL,
 operation_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, source_checksum CHAR(64) NOT NULL,
 coverage_start DATE NULL, coverage_end DATE NULL, currency CHAR(3) NOT NULL,
 parsed_count INT NOT NULL, selected_count INT NOT NULL, existing_count INT NOT NULL, invalid_count INT NOT NULL, omitted_count INT NOT NULL,
 money_in_cents BIGINT NOT NULL, money_out_cents BIGINT NOT NULL,
 source_opening_cents BIGINT NULL, calculated_closing_cents BIGINT NULL, source_closing_cents BIGINT NULL, difference_cents BIGINT NULL,
 prior_statement_balance_cents BIGINT NULL, projected_statement_balance_cents BIGINT NULL,
 book_balance_before_cents BIGINT NOT NULL, book_balance_after_cents BIGINT NOT NULL,
 completeness_state ENUM('checks_passed_review_required','difference_found','partial_import','history_gap','balance_check_unavailable') NOT NULL,
 result_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY bank_import_receipt_operation_uq (company_id,user_id,operation_key),
 UNIQUE KEY bank_import_receipt_batch_uq (company_id,import_batch_id),
 KEY bank_import_receipt_account_time (company_id,bank_account_id,created_at),
 CONSTRAINT bank_import_receipt_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT bank_import_receipt_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
 CONSTRAINT bank_import_receipt_batch_fk FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
 CONSTRAINT bank_import_receipt_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];
}

function tegh_schema45_column_ddl(): array
{
    return [
        'party_payments'=>[
            'flow_kind'=>"ENUM('customer_receipt','vendor_payment','customer_refund','vendor_refund') NOT NULL DEFAULT 'customer_receipt' AFTER payment_type",
        ],
        'bank_transactions'=>[
            'remarks'=>"VARCHAR(500) NOT NULL DEFAULT '' AFTER reference",
            'source_row_number'=>'INT NULL AFTER source_hash',
            'source_sequence'=>'BIGINT NULL AFTER source_row_number',
            'source_running_balance_cents'=>'BIGINT NULL AFTER source_sequence',
        ],
        'interbank_transfers'=>[
            'reversal_journal_entry_id'=>'VARCHAR(64) NULL AFTER status',
            'reversed_by'=>'VARCHAR(64) NULL AFTER reversal_journal_entry_id',
            'reversed_at'=>'DATETIME NULL AFTER reversed_by',
            'reversal_reason'=>'VARCHAR(500) NULL AFTER reversed_at',
        ],
        'interbank_transfer_legs'=>[
            'link_kind'=>"ENUM('posted_origin','matched_counterpart') NOT NULL DEFAULT 'posted_origin' AFTER journal_line_id",
            'operation_key'=>'VARCHAR(120) NULL AFTER link_kind',
            'payload_hash'=>'CHAR(64) NULL AFTER operation_key',
        ],
    ];
}

/** Frozen R2 material keeps already-recorded HOLD checkpoint receipts valid. */
function tegh_schema45_v1_table_sql(): array
{
    $tables=tegh_schema45_table_sql();
    unset($tables['interbank_transfer_operations']);
    $tables['interbank_transfers']=str_replace([
        " reversal_journal_entry_id VARCHAR(64) NULL, reversed_by VARCHAR(64) NULL, reversed_at DATETIME NULL, reversal_reason VARCHAR(500) NULL,\n",
        " CONSTRAINT interbank_transfer_reversal_journal_fk FOREIGN KEY (reversal_journal_entry_id) REFERENCES journal_entries(id),\n",
        " CONSTRAINT interbank_transfer_reversed_user_fk FOREIGN KEY (reversed_by) REFERENCES users(id),\n",
    ],'',$tables['interbank_transfers']);
    $tables['interbank_transfer_legs']=str_replace([
        " link_kind ENUM('posted_origin','matched_counterpart') NOT NULL DEFAULT 'posted_origin', operation_key VARCHAR(120) NULL, payload_hash CHAR(64) NULL,\n",
        " UNIQUE KEY interbank_transfer_leg_operation_uq (company_id,linked_by,operation_key),\n",
    ],'',$tables['interbank_transfer_legs']);
    return $tables;
}

function tegh_schema45_v1_column_ddl(): array
{
    $columns=tegh_schema45_column_ddl();unset($columns['interbank_transfers'],$columns['interbank_transfer_legs']);return $columns;
}

function tegh_schema45_v1_expected_indexes(): array
{
    $indexes=tegh_schema45_v2_expected_indexes();
    $indexes['interbank_transfer_legs']=array_values(array_filter($indexes['interbank_transfer_legs'],static fn(string $name):bool=>$name!=='interbank_transfer_leg_operation_uq'));
    return $indexes;
}

/** Frozen R3 material keeps lifecycle-v2 receipts valid after R4 storage. */
function tegh_schema45_v2_table_sql(): array
{
    $tables=tegh_schema45_table_sql();unset($tables['interbank_transfer_operations']);return $tables;
}

function tegh_schema45_v2_column_ddl(): array { return tegh_schema45_column_ddl(); }

function tegh_schema45_v2_expected_indexes(): array
{
    $indexes=tegh_schema45_expected_indexes();unset($indexes['interbank_transfer_operations']);return $indexes;
}

function tegh_schema45_expected_indexes(): array
{
    return [
        'party_payment_application_operations'=>['payment_application_operation_uq','payment_application_operation_company_status'],
        'party_payment_applications'=>['payment_applications_payment_status','payment_applications_document_status','payment_applications_partner_status','payment_application_operation_row_uq'],
        'payment_legacy_mapping_reviews'=>['payment_legacy_review_company_status'],
        'interbank_transfers'=>['interbank_transfer_operation_uq','interbank_transfer_journal_uq','interbank_transfer_candidates'],
        'interbank_transfer_legs'=>['interbank_transfer_bank_transaction_uq','interbank_transfer_account_leg_uq','interbank_transfer_journal_line_uq','interbank_transfer_leg_operation_uq','interbank_transfer_legs_transfer_status'],
        'interbank_transfer_operations'=>['interbank_operation_key_uq','interbank_operation_transfer_status'],
        'bank_statement_balance_anchors'=>['statement_anchor_revision_uq','statement_anchor_account_date'],
        'bank_import_control_receipts'=>['bank_import_receipt_operation_uq','bank_import_receipt_batch_uq','bank_import_receipt_account_time'],
    ];
}

function tegh_schema45_status(): array
{
    $issues=[];
    foreach(tegh_schema45_table_sql() as $table=>$sql){
        if(!schema_table_exists($table)){$issues[]=['object'=>$table,'code'=>'missing_table','repairable'=>true];continue;}
        foreach(tegh_schema45_expected_indexes()[$table]??[] as $index){if(tegh_schema_index_actual($table,$index)===null)$issues[]=['object'=>$table.'.'.$index,'code'=>'missing_index','repairable'=>($table==='interbank_transfer_legs'&&$index==='interbank_transfer_leg_operation_uq')||$table==='interbank_transfer_operations'];}
    }
    foreach(tegh_schema45_column_ddl() as $table=>$columns)foreach($columns as $column=>$ddl)if(!schema_column_exists($table,$column))$issues[]=['object'=>$table.'.'.$column,'code'=>'missing_column','repairable'=>true];
    return ['ready'=>$issues===[],'issues'=>$issues];
}

function tegh_schema45_v1_status(): array
{
    $issues=[];
    foreach(tegh_schema45_v1_table_sql() as $table=>$sql){
        if(!schema_table_exists($table)){$issues[]=['object'=>$table,'code'=>'missing_table'];continue;}
        foreach(tegh_schema45_v1_expected_indexes()[$table]??[] as $index)if(tegh_schema_index_actual($table,$index)===null)$issues[]=['object'=>$table.'.'.$index,'code'=>'missing_index'];
    }
    foreach(tegh_schema45_v1_column_ddl() as $table=>$columns)foreach($columns as $column=>$ddl)if(!schema_column_exists($table,$column))$issues[]=['object'=>$table.'.'.$column,'code'=>'missing_column'];
    return ['ready'=>$issues===[],'issues'=>$issues];
}

function tegh_schema45_v2_status(): array
{
    $issues=[];
    foreach(tegh_schema45_v2_table_sql() as $table=>$sql){
        if(!schema_table_exists($table)){$issues[]=['object'=>$table,'code'=>'missing_table'];continue;}
        foreach(tegh_schema45_v2_expected_indexes()[$table]??[] as $index)if(tegh_schema_index_actual($table,$index)===null)$issues[]=['object'=>$table.'.'.$index,'code'=>'missing_index'];
    }
    foreach(tegh_schema45_v2_column_ddl() as $table=>$columns)foreach($columns as $column=>$ddl)if(!schema_column_exists($table,$column))$issues[]=['object'=>$table.'.'.$column,'code'=>'missing_column'];
    return ['ready'=>$issues===[],'issues'=>$issues];
}

function tegh_schema45_prepare(): void
{
    foreach(tegh_schema45_table_sql() as $sql)db()->exec($sql);
    foreach(tegh_schema45_column_ddl() as $table=>$columns)foreach($columns as $column=>$ddl)schema_add_column($table,$column,$ddl);
    schema_add_index('interbank_transfer_legs','interbank_transfer_leg_operation_uq','company_id,linked_by,operation_key',true);
    schema_add_index('interbank_transfer_operations','interbank_operation_key_uq','company_id,operation_key',true);
    schema_add_index('interbank_transfer_operations','interbank_operation_transfer_status','company_id,transfer_id,operation_type,status');
}

function tegh_schema45_prepare_v1(): void
{
    foreach(tegh_schema45_v1_table_sql() as $sql)db()->exec($sql);
    foreach(tegh_schema45_v1_column_ddl() as $table=>$columns)foreach($columns as $column=>$ddl)schema_add_column($table,$column,$ddl);
}

function tegh_schema45_prepare_v2(): void
{
    foreach(tegh_schema45_v2_table_sql() as $sql)db()->exec($sql);
    foreach(tegh_schema45_v2_column_ddl() as $table=>$columns)foreach($columns as $column=>$ddl)schema_add_column($table,$column,$ddl);
    schema_add_index('interbank_transfer_legs','interbank_transfer_leg_operation_uq','company_id,linked_by,operation_key',true);
}

function tegh_schema45_prepare_payment_check(): void
{
    $q=db()->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='party_payments' AND CONSTRAINT_NAME='party_payments_amount_ck' AND CONSTRAINT_TYPE='CHECK'");$q->execute();
    if((int)$q->fetchColumn()>0){
        try{db()->exec('ALTER TABLE party_payments DROP CHECK party_payments_amount_ck');}
        catch(Throwable $first){db()->exec('ALTER TABLE party_payments DROP CONSTRAINT party_payments_amount_ck');}
    }
    // MySQL versions that parse but do not enforce CHECK constraints are still
    // protected by the posting/application services. The diagnostic reports
    // runtime enforcement separately during the real-database gate.
    db()->exec('ALTER TABLE party_payments ADD CONSTRAINT party_payments_amount_ck CHECK (amount_cents > 0 AND applied_cents >= 0 AND applied_cents <= amount_cents AND foreign_amount_cents > 0 AND exchange_rate_micros > 0)');
}

function tegh_schema45_map_controls_and_legacy(): void
{
    // Establish authoritative AR/AP mappings only for the unique active
    // control account created by the legacy company template. Conflicts stay
    // explicit and are never guessed at runtime.
    foreach([['accounts_receivable','1200'],['accounts_payable','2050']] as [$key,$code]){
        $companies=db()->query("SELECT c.id FROM companies c LEFT JOIN company_system_accounts cs ON cs.company_id=c.id AND cs.system_key='".$key."' WHERE c.active=1 AND cs.company_id IS NULL ORDER BY c.id")->fetchAll(PDO::FETCH_COLUMN);
        foreach($companies as $companyId){
            $q=db()->prepare('SELECT id FROM accounts WHERE company_id=? AND code=? AND active=1 AND is_control=1 ORDER BY id');$q->execute([(string)$companyId,$code]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
            $accountId=count($ids)===1?(string)$ids[0]:null;$status=$accountId===null?'conflict':'active';$message=$accountId===null?'A unique active legacy control account could not be proved. Configure this mapping before partner posting.':null;
            db()->prepare('INSERT INTO company_system_accounts(company_id,system_key,configured_code,account_id,status,conflict_message) VALUES(?,?,?,?,?,?)')->execute([(string)$companyId,$key,$code,$accountId,$status,$message]);
        }
    }
    // Do not fabricate application dates for legacy single-document payments.
    // Retain their original linkage and place them in an explicit review set.
    db()->exec("INSERT IGNORE INTO payment_legacy_mapping_reviews(payment_id,company_id,reason_code,evidence_json)
      SELECT pp.id,pp.company_id,'application_date_unproven',JSON_OBJECT('documentId',pp.document_id,'appliedCents',pp.applied_cents,'paymentDate',pp.payment_date,'status',pp.status)
      FROM party_payments pp WHERE pp.document_id IS NOT NULL AND pp.applied_cents>0");
    db()->exec("UPDATE party_payments SET flow_kind=CASE WHEN payment_type='vendor' THEN 'vendor_payment' ELSE 'customer_receipt' END");
}

function tegh_schema45_preflight(): array
{
    $marker=current_database_schema_version();$prior=tegh_schema44_status();$contract=tegh_schema45_status();
    $priorSafe=$marker>=44?$prior['ready']:(($marker>=43)&&tegh_schema44_preflight()['retrySafe']);
    $safe=$marker>=43&&$marker<=45&&$priorSafe&&array_filter($contract['issues'],static fn(array $issue):bool=>empty($issue['repairable']))===[];
    $dataReady=false;$interbankLifecycleReady=false;$interbankOperationsReady=false;if(schema_table_exists('database_migration_events'))try{$dataReady=tegh_migration_completed('schema45.5990.data');$interbankLifecycleReady=tegh_migration_completed('schema45.5990.interbank_lifecycle_v2');$interbankOperationsReady=tegh_migration_completed('schema45.5990.interbank_lifecycle_v3');}catch(Throwable){$safe=false;}
    $ready=$marker===45&&$contract['ready']&&$dataReady&&$interbankLifecycleReady&&$interbankOperationsReady&&$safe;
    return ['ready'=>$ready,'retrySafe'=>$safe,'mutationRequired'=>!$ready,
        'state'=>$ready?'complete_schema_45':($safe?'schema45_preparation_required':'schema45_diagnostic_required'),
        'stateLabel'=>$ready?'Database is up to date':'Complete payment, transfer and statement-control storage',
        'markerVersion'=>$marker,'structuralVersion'=>$contract['ready']?45:($prior['ready']?44:0),'expectedSchemaVersion'=>45,
        'contract'=>$contract,'interbankLifecycleReady'=>$interbankLifecycleReady,'interbankOperationsReady'=>$interbankOperationsReady,'build'=>SR_ACCOUNTAX_BUILD,'version'=>SR_ACCOUNTAX_VERSION,'requestReference'=>request_id(),'checkedAt'=>gmdate('c')];
}

function tegh_schema45_require(): void
{
    if(current_database_schema_version()<45||!tegh_schema45_status()['ready'])fail('A Platform Owner must complete the protected Schema 45 upgrade before this workflow can be used.',503,'schema45_required');
}

function tegh_schema45_upgrade(array $user,bool $backupConfirmed): array
{
    if(!$backupConfirmed)fail('Confirm a verified database, application and private-storage backup.',409,'database_backup_required');
    $marker=current_database_schema_version();if($marker<44)tegh_schema44_upgrade($user,true);
    $initial=tegh_schema45_preflight();if($initial['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'schemaVersion'=>45,'steps'=>[],'preflight'=>$initial,'accountingTransactionsPosted'=>0];
    if(!$initial['retrySafe'])fail('Review the Schema 45 diagnostic before upgrading.',409,'schema_upgrade_unsafe');
    $maintenance=tegh_maintenance_mode_begin('schema45_upgrade');if($maintenance==='')fail('Another maintenance operation is active.',409,'maintenance_mode_busy');$lock=0;$steps=[];
    try{
        $lock=(int)db()->query("SELECT GET_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."',2)")->fetchColumn();if($lock!==1)throw new RuntimeException('Database upgrade lock is busy.');
        if(!tegh_schema45_preflight()['retrySafe'])throw new RuntimeException('The database changed before the upgrade lock was acquired.');
        tegh_migration_ledger_ensure();
        tegh_run_migration_step('schema45.5990.tables_columns',static fn()=>tegh_schema45_prepare_v1(),static fn():bool=>tegh_schema45_v1_status()['ready'],$steps);
        tegh_run_migration_step('schema45.5990.payment_check',static fn()=>tegh_schema45_prepare_payment_check(),static function():bool{$q=db()->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='party_payments' AND CONSTRAINT_NAME='party_payments_amount_ck'");$q->execute();return (int)$q->fetchColumn()===1;},$steps);
        tegh_run_migration_step('schema45.5990.data',static fn()=>tegh_schema45_map_controls_and_legacy(),static function():bool{$q=db()->query("SELECT COUNT(*) FROM party_payments pp LEFT JOIN payment_legacy_mapping_reviews r ON r.payment_id=pp.id WHERE pp.document_id IS NOT NULL AND pp.applied_cents>0 AND r.payment_id IS NULL");return (int)$q->fetchColumn()===0;},$steps);
        tegh_run_migration_step('schema45.5990.interbank_lifecycle_v2',static fn()=>tegh_schema45_prepare_v2(),static fn():bool=>tegh_schema45_v2_status()['ready'],$steps);
        tegh_run_migration_step('schema45.5990.interbank_lifecycle_v3',static fn()=>tegh_schema45_prepare(),static fn():bool=>tegh_schema45_status()['ready'],$steps);
        tegh_run_schema_marker_step('schema45.5990.adopt_marker',45,static fn():bool=>tegh_schema45_status()['ready'],$steps);
        return ['ok'=>true,'alreadyUpToDate'=>false,'schemaVersion'=>45,'previousSchema'=>$marker,'steps'=>$steps,'preflight'=>tegh_schema45_preflight(),'accountingTransactionsPosted'=>0];
    }finally{if($lock===1)db()->query("SELECT RELEASE_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."')");tegh_maintenance_mode_end($maintenance);}
}

function handle_tegh_schema45_preflight(): never {require_method('GET');tegh_require_platform_owner();json_response(['ok'=>true,'preflight'=>tegh_schema45_preflight()]);}
function handle_tegh_schema45_diagnostic(): never {require_method('GET');tegh_require_platform_owner();json_response(['ok'=>true,'diagnostic'=>tegh_schema45_preflight()]);}
function handle_tegh_schema45_upgrade(): never
{
    require_method('POST');$user=tegh_require_platform_owner();require_csrf();$input=request_json();@ignore_user_abort(true);@set_time_limit(180);
    try{json_response(tegh_schema45_upgrade($user,!empty($input['backupConfirmed'])));}catch(Throwable $error){record_system_incident('Schema 45 upgrade did not finish. No accounting transaction was posted.',500,'schema45_upgrade_failed',$error);fail('The upgrade did not finish. Review the diagnostic. Reference: '.request_id(),500,'schema_upgrade_failed',false);}
}
