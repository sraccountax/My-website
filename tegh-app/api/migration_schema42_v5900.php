<?php
declare(strict_types=1);

/**
 * Tegh 5.9.0 / Schema 42
 *
 * Additive transaction-control storage for journal integrity, dual control,
 * reconciliation evidence, and renewable Native Agent leases. The upgrade is
 * resumable and does not post, void, match, or otherwise alter accounting
 * transactions. Existing journal content hashes are backfilled from their
 * immutable entry date, memo, and line data before the column becomes NOT NULL.
 */

const TEGH_SCHEMA42_VERSION = 42;

/** @return array<string,string> */
function tegh_schema42_table_sql(): array
{
    return [
        'ai_redaction_log' => "CREATE TABLE IF NOT EXISTS ai_redaction_log (
          id VARCHAR(64) PRIMARY KEY,
          request_id VARCHAR(80) NOT NULL,
          hit_types_json LONGTEXT NOT NULL,
          hit_count INT NOT NULL,
          ip_hash CHAR(64) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY ai_redaction_log_created_idx (created_at),
          KEY ai_redaction_log_request_idx (request_id),
          CONSTRAINT ai_redaction_log_count_ck CHECK (hit_count > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'journal_approvals' => "CREATE TABLE IF NOT EXISTS journal_approvals (
          id VARCHAR(64) PRIMARY KEY,
          company_id VARCHAR(64) NOT NULL,
          journal_entry_id VARCHAR(64) NULL,
          source_type VARCHAR(60) NOT NULL,
          source_id VARCHAR(64) NOT NULL,
          amount_cents BIGINT NOT NULL,
          status ENUM('submitted','approved','posted','rejected','expired') NOT NULL DEFAULT 'submitted',
          payload_hash CHAR(64) NOT NULL,
          prepared_by VARCHAR(64) NOT NULL,
          approved_by VARCHAR(64) NULL,
          review_note VARCHAR(500) NOT NULL DEFAULT '',
          submitted_at DATETIME NOT NULL,
          approved_at DATETIME NULL,
          posted_at DATETIME NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY journal_approvals_company_status_idx (company_id,status,submitted_at),
          KEY journal_approvals_source_idx (company_id,source_type,source_id,submitted_at),
          KEY journal_approvals_journal_idx (journal_entry_id),
          CONSTRAINT journal_approvals_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
          CONSTRAINT journal_approvals_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
          CONSTRAINT journal_approvals_preparer_fk FOREIGN KEY (prepared_by) REFERENCES users(id),
          CONSTRAINT journal_approvals_approver_fk FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
          CONSTRAINT journal_approvals_amount_ck CHECK (amount_cents > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'transaction_status_transitions' => "CREATE TABLE IF NOT EXISTS transaction_status_transitions (
          entity_type VARCHAR(40) NOT NULL,
          from_status VARCHAR(40) NOT NULL,
          to_status VARCHAR(40) NOT NULL,
          transition_code VARCHAR(80) NOT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (entity_type,from_status,to_status),
          UNIQUE KEY transaction_status_transition_code_uq (transition_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'reconciliation_match_proposals' => "CREATE TABLE IF NOT EXISTS reconciliation_match_proposals (
          id VARCHAR(64) PRIMARY KEY,
          company_id VARCHAR(64) NOT NULL,
          bank_account_id VARCHAR(64) NOT NULL,
          period_start DATE NOT NULL,
          period_end DATE NOT NULL,
          proposal_hash CHAR(64) NOT NULL,
          match_kind VARCHAR(40) NOT NULL,
          score INT NOT NULL,
          score_tiers_json LONGTEXT NOT NULL,
          bank_transaction_ids_json LONGTEXT NOT NULL,
          journal_entry_ids_json LONGTEXT NOT NULL,
          bank_total_cents BIGINT NOT NULL,
          book_total_cents BIGINT NOT NULL,
          difference_cents BIGINT NOT NULL,
          reason VARCHAR(500) NOT NULL,
          evidence_json LONGTEXT NOT NULL,
          status ENUM('open','accepted','stale','dismissed') NOT NULL DEFAULT 'open',
          first_seen_at DATETIME NOT NULL,
          last_seen_at DATETIME NOT NULL,
          accepted_at DATETIME NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY reconciliation_proposals_hash_uq (proposal_hash),
          KEY reconciliation_proposals_company_status_idx (company_id,status,last_seen_at),
          KEY reconciliation_proposals_account_period_idx (bank_account_id,period_start,period_end,score),
          CONSTRAINT reconciliation_proposals_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
          CONSTRAINT reconciliation_proposals_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
          CONSTRAINT reconciliation_proposals_score_ck CHECK (score BETWEEN 0 AND 100)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'reconciliation_resume_log' => "CREATE TABLE IF NOT EXISTS reconciliation_resume_log (
          id VARCHAR(64) PRIMARY KEY,
          company_id VARCHAR(64) NOT NULL,
          user_id VARCHAR(64) NOT NULL,
          bank_account_id VARCHAR(64) NOT NULL,
          operation_key_hash CHAR(64) NOT NULL,
          attempt_kind ENUM('first_time','resume') NOT NULL,
          result_status ENUM('started','executed','recovered','blocked') NOT NULL DEFAULT 'started',
          decision_count INT NOT NULL,
          recovered_count INT NOT NULL DEFAULT 0,
          error_code VARCHAR(80) NULL,
          started_at DATETIME NOT NULL,
          completed_at DATETIME NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY reconciliation_resume_company_idx (company_id,attempt_kind,result_status,started_at),
          KEY reconciliation_resume_operation_idx (operation_key_hash,started_at),
          CONSTRAINT reconciliation_resume_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
          CONSTRAINT reconciliation_resume_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
          CONSTRAINT reconciliation_resume_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
          CONSTRAINT reconciliation_resume_counts_ck CHECK (decision_count > 0 AND recovered_count >= 0 AND recovered_count <= decision_count)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'native_agent_leases' => "CREATE TABLE IF NOT EXISTS native_agent_leases (
          lease_name VARCHAR(80) PRIMARY KEY,
          lease_owner VARCHAR(64) NOT NULL,
          lease_expires_at DATETIME NOT NULL,
          lease_renewed_at DATETIME NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY native_agent_leases_expiry_idx (lease_expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'native_agent_run_failures' => "CREATE TABLE IF NOT EXISTS native_agent_run_failures (
          id VARCHAR(64) PRIMARY KEY,
          company_id VARCHAR(64) NOT NULL,
          agent_type VARCHAR(40) NOT NULL,
          run_id VARCHAR(64) NULL,
          error_code VARCHAR(80) NOT NULL,
          error_hash CHAR(64) NOT NULL,
          retry_count INT NOT NULL DEFAULT 1,
          next_retry_at DATETIME NOT NULL,
          status ENUM('pending','resolved','abandoned') NOT NULL DEFAULT 'pending',
          first_failed_at DATETIME NOT NULL,
          last_failed_at DATETIME NOT NULL,
          resolved_at DATETIME NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY native_agent_failures_company_agent_uq (company_id,agent_type),
          KEY native_agent_failures_due_idx (status,next_retry_at),
          CONSTRAINT native_agent_failures_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
          CONSTRAINT native_agent_failures_run_fk FOREIGN KEY (run_id) REFERENCES native_agent_runs(id) ON DELETE SET NULL,
          CONSTRAINT native_agent_failures_retry_ck CHECK (retry_count >= 1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** @return array<string,array<int,string>> */
function tegh_schema42_table_columns(): array
{
    return [
        'ai_redaction_log'=>['id','request_id','hit_types_json','hit_count','ip_hash','created_at'],
        'journal_approvals'=>['id','company_id','journal_entry_id','source_type','source_id','amount_cents','status','payload_hash','prepared_by','approved_by','review_note','submitted_at','approved_at','posted_at','created_at'],
        'transaction_status_transitions'=>['entity_type','from_status','to_status','transition_code','active'],
        'reconciliation_match_proposals'=>['id','company_id','bank_account_id','period_start','period_end','proposal_hash','match_kind','score','score_tiers_json','bank_transaction_ids_json','journal_entry_ids_json','bank_total_cents','book_total_cents','difference_cents','reason','evidence_json','status','first_seen_at','last_seen_at','accepted_at','created_at'],
        'reconciliation_resume_log'=>['id','company_id','user_id','bank_account_id','operation_key_hash','attempt_kind','result_status','decision_count','recovered_count','error_code','started_at','completed_at','created_at'],
        'native_agent_leases'=>['lease_name','lease_owner','lease_expires_at','lease_renewed_at','created_at'],
        'native_agent_run_failures'=>['id','company_id','agent_type','run_id','error_code','error_hash','retry_count','next_retry_at','status','first_failed_at','last_failed_at','resolved_at','created_at','updated_at'],
    ];
}

/** @return array<string,array<string,string>> */
function tegh_schema42_existing_column_contract(): array
{
    return [
        'accounting_controls'=>['materiality_threshold_cents'=>'BIGINT NOT NULL DEFAULT 1000000'],
        'journal_entries'=>[
            'status'=>"ENUM('pending_post','posted','reversed') NOT NULL DEFAULT 'posted'",
            'content_hash'=>'CHAR(64) NOT NULL',
            'voided_at'=>'DATETIME NULL',
            'voided_by'=>'VARCHAR(64) NULL',
        ],
        'journal_lines'=>['fx_rounding_cents'=>'BIGINT NOT NULL DEFAULT 0'],
        'bills'=>['status'=>"ENUM('draft','submitted_for_approval','approved','open','paid','void') NOT NULL DEFAULT 'draft'"],
        'bank_match_groups'=>['unreconcile_reason_code'=>"ENUM('bank_error','duplicate','wrong_book_entry','wrong_period','reconciliation_reopened','other') NULL"],
        'native_agent_runs'=>['lease_renewed_at'=>'DATETIME NOT NULL'],
    ];
}

/** @return array<string,mixed> */
function tegh_schema42_contract_material(): array
{
    return [
        'version'=>TEGH_SCHEMA42_VERSION,
        'tables'=>array_map('hash',array_fill(0,count(tegh_schema42_table_sql()),'sha256'),array_values(tegh_schema42_table_sql())),
        'tableColumns'=>tegh_schema42_table_columns(),
        'existingColumns'=>tegh_schema42_existing_column_contract(),
        'journalSourceUnique'=>['company_id','source_type','source_id'],
        'transitionSeed'=>tegh_schema42_transition_seed(),
    ];
}

/** @return array<int,array{0:string,1:string,2:string,3:string}> */
function tegh_schema42_transition_seed(): array
{
    return [
        ['invoice','draft','sent','invoice.issue'],['invoice','draft','void','invoice.void_draft'],['invoice','sent','paid','invoice.pay'],['invoice','sent','void','invoice.void'],['invoice','paid','sent','invoice.payment_reverse'],['invoice','void','sent','invoice.restore'],
        ['bill','draft','submitted_for_approval','bill.submit'],['bill','submitted_for_approval','approved','bill.approve'],['bill','submitted_for_approval','draft','bill.return'],['bill','approved','open','bill.post'],['bill','approved','draft','bill.return_approved'],['bill','draft','open','bill.post_below_materiality'],['bill','draft','void','bill.void_draft'],['bill','open','paid','bill.pay'],['bill','open','void','bill.void'],['bill','paid','open','bill.payment_reverse'],['bill','void','open','bill.restore'],
        ['payment','posted','reversed','payment.reverse'],['payment','reversed','posted','payment.restore'],
        ['bank_transaction','pending','posted','bank.post'],['bank_transaction','pending','excluded','bank.exclude'],['bank_transaction','pending','duplicate','bank.duplicate'],['bank_transaction','excluded','pending','bank.restore'],['bank_transaction','posted','pending','bank.unpost'],['bank_transaction','posted','excluded','bank.void'],
        ['journal','pending_post','posted','journal.approve_post'],['journal','posted','reversed','journal.reverse'],['journal','reversed','posted','journal.restore'],
        ['expense','posted','void','expense.void'],['expense','void','posted','expense.restore'],
    ];
}

/**
 * A missing Schema 42 constraint is repairable only when the upgrader owns the
 * exact additive repair and existing rows cannot violate it.
 */
function tegh_schema42_constraint_repairable(string $table,string $constraint): bool
{
    if($table!=='journal_entries'||$constraint!=='journal_entries_voided_user_fk')return false;
    // Schema 41 does not have voided_by. Adding a nullable empty column and its
    // SET NULL foreign key is safe and is performed by repair_columns().
    if(!schema_column_exists('journal_entries','voided_by'))return true;
    if(!schema_table_exists('users'))return false;
    $orphans=(int)db()->query('SELECT COUNT(*) FROM journal_entries je LEFT JOIN users u ON u.id=je.voided_by WHERE je.voided_by IS NOT NULL AND u.id IS NULL')->fetchColumn();
    return $orphans===0;
}

/** @return array<string,mixed> */
function tegh_schema42_status(): array
{
    $issues=[];
    foreach(tegh_schema42_existing_column_contract() as $table=>$columns){
        if(!schema_table_exists($table)){
            $issues[]=['kind'=>'missing_prerequisite_table','object'=>$table,'repairable'=>false];
            continue;
        }
        foreach($columns as $column=>$definition){
            $actual=tegh_schema_column_actual($table,$column);
            if($actual===null){$issues[]=['kind'=>'missing_column','object'=>$table.'.'.$column,'expected'=>$definition,'actual'=>'missing','repairable'=>true];continue;}
            $expected=tegh_schema40_expected_column($definition);
            if(!tegh_schema_contract_equal($expected,$actual)){
                $repairable=($table==='journal_entries'&&$column==='content_hash'&&($actual['type']??'')==='char(64)')
                    ||($table==='journal_entries'&&$column==='status'&&str_contains((string)($actual['type']??''),"'posted','reversed'"))
                    ||($table==='bills'&&$column==='status'&&str_contains((string)($actual['type']??''),"'draft'"))
                    ||($table==='native_agent_runs'&&$column==='lease_renewed_at'&&($actual['type']??'')==='datetime');
                $issues[]=['kind'=>'column_contract','object'=>$table.'.'.$column,'expected'=>$expected,'actual'=>$actual,'repairable'=>$repairable];
            }
        }
    }
    foreach(tegh_schema42_table_columns() as $table=>$columns){
        if(!schema_table_exists($table)){$issues[]=['kind'=>'missing_table','object'=>$table,'repairable'=>true];continue;}
        $properties=db()->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');$properties->execute([$table]);$row=$properties->fetch()?:[];
        if(strtoupper((string)($row['ENGINE']??''))!=='INNODB'||strtolower((string)($row['TABLE_COLLATION']??''))!=='utf8mb4_unicode_ci')$issues[]=['kind'=>'table_contract','object'=>$table,'expected'=>'InnoDB / utf8mb4_unicode_ci','actual'=>$row,'repairable'=>tegh_schema_table_rows($table)===0];
        foreach($columns as $column)if(!schema_column_exists($table,$column))$issues[]=['kind'=>'missing_table_column','object'=>$table.'.'.$column,'repairable'=>false];
    }
    $indexes=[
        'ai_redaction_log'=>['PRIMARY','ai_redaction_log_created_idx','ai_redaction_log_request_idx'],
        'journal_approvals'=>['journal_approvals_company_status_idx','journal_approvals_source_idx','journal_approvals_journal_idx'],
        'transaction_status_transitions'=>['PRIMARY','transaction_status_transition_code_uq'],
        'reconciliation_match_proposals'=>['reconciliation_proposals_hash_uq','reconciliation_proposals_company_status_idx','reconciliation_proposals_account_period_idx'],
        'reconciliation_resume_log'=>['reconciliation_resume_company_idx','reconciliation_resume_operation_idx'],
        'native_agent_leases'=>['PRIMARY','native_agent_leases_expiry_idx'],
        'native_agent_run_failures'=>['native_agent_failures_company_agent_uq','native_agent_failures_due_idx'],
    ];
    foreach($indexes as $table=>$names)if(schema_table_exists($table))foreach($names as $name)if(!schema_index_exists($table,$name))$issues[]=['kind'=>'missing_index','object'=>$table.'.'.$name,'repairable'=>false];
    $constraints=[
        'journal_entries'=>['journal_entries_voided_user_fk'],
        'journal_approvals'=>['journal_approvals_company_fk','journal_approvals_journal_fk','journal_approvals_preparer_fk','journal_approvals_approver_fk'],
        'reconciliation_match_proposals'=>['reconciliation_proposals_company_fk','reconciliation_proposals_bank_fk'],
        'reconciliation_resume_log'=>['reconciliation_resume_company_fk','reconciliation_resume_user_fk','reconciliation_resume_bank_fk'],
        'native_agent_run_failures'=>['native_agent_failures_company_fk','native_agent_failures_run_fk'],
    ];
    foreach($constraints as $table=>$names)if(schema_table_exists($table))foreach($names as $name)if(!schema_constraint_exists($table,$name))$issues[]=['kind'=>'missing_constraint','object'=>$table.'.'.$name,'repairable'=>tegh_schema42_constraint_repairable($table,$name)];
    $sourceIndex=tegh_schema_index_actual('journal_entries','journal_entries_source_uq');
    $expectedSource=['columns'=>['company_id','source_type','source_id'],'unique'=>true];
    if($sourceIndex===null)$issues[]=['kind'=>'missing_index','object'=>'journal_entries.journal_entries_source_uq','repairable'=>tegh_schema_unique_duplicates('journal_entries',$expectedSource['columns'])===0];
    elseif(!tegh_schema_contract_equal($expectedSource,$sourceIndex))$issues[]=['kind'=>'index_contract','object'=>'journal_entries.journal_entries_source_uq','expected'=>$expectedSource,'actual'=>$sourceIndex,'repairable'=>false];
    if(schema_column_exists('journal_entries','content_hash')){
        $invalid=(int)db()->query("SELECT COUNT(*) FROM journal_entries WHERE content_hash IS NULL OR content_hash NOT REGEXP '^[0-9a-f]{64}$'")->fetchColumn();
        if($invalid>0)$issues[]=['kind'=>'journal_hash_backfill','object'=>'journal_entries.content_hash','actual'=>$invalid,'expected'=>0,'repairable'=>true];
    }
    if(schema_table_exists('transaction_status_transitions')&&!tegh_schema42_transitions_ready())$issues[]=['kind'=>'transition_seed','object'=>'transaction_status_transitions','actual'=>'incomplete','expected'=>count(tegh_schema42_transition_seed()),'repairable'=>true];
    return ['ready'=>$issues===[],'issues'=>$issues,'expectedSchemaVersion'=>42,'checkedAt'=>gmdate('c')];
}

function tegh_schema42_repair_columns(): void
{
    schema_add_column('accounting_controls','materiality_threshold_cents','BIGINT NOT NULL DEFAULT 1000000 AFTER closed_through_date');
    $journalStatus=tegh_schema_column_actual('journal_entries','status');
    if($journalStatus===null||!str_contains((string)$journalStatus['type'],"'pending_post'"))db()->exec("ALTER TABLE journal_entries MODIFY COLUMN status ENUM('pending_post','posted','reversed') NOT NULL DEFAULT 'posted'");
    if(!schema_column_exists('journal_entries','content_hash'))db()->exec('ALTER TABLE journal_entries ADD COLUMN content_hash CHAR(64) NULL AFTER reversal_of_id');
    schema_add_column('journal_entries','voided_at','DATETIME NULL AFTER content_hash');
    schema_add_column('journal_entries','voided_by','VARCHAR(64) NULL AFTER voided_at');
    schema_add_column('journal_lines','fx_rounding_cents','BIGINT NOT NULL DEFAULT 0 AFTER memo');
    $billStatus=tegh_schema_column_actual('bills','status');
    if($billStatus===null||!str_contains((string)$billStatus['type'],"'submitted_for_approval'"))db()->exec("ALTER TABLE bills MODIFY COLUMN status ENUM('draft','submitted_for_approval','approved','open','paid','void') NOT NULL DEFAULT 'draft'");
    schema_add_column('bank_match_groups','unreconcile_reason_code',"ENUM('bank_error','duplicate','wrong_book_entry','wrong_period','reconciliation_reopened','other') NULL AFTER unreconciled_at");
    if(!schema_column_exists('native_agent_runs','lease_renewed_at'))db()->exec('ALTER TABLE native_agent_runs ADD COLUMN lease_renewed_at DATETIME NULL AFTER lease_expires_at');
    db()->exec('UPDATE native_agent_runs SET lease_renewed_at=COALESCE(lease_renewed_at,started_at,created_at,UTC_TIMESTAMP()) WHERE lease_renewed_at IS NULL');
    if(schema_column_nullable('native_agent_runs','lease_renewed_at'))db()->exec('ALTER TABLE native_agent_runs MODIFY COLUMN lease_renewed_at DATETIME NOT NULL');
    if(!schema_index_exists('journal_entries','journal_entries_source_uq')){
        if(tegh_schema_unique_duplicates('journal_entries',['company_id','source_type','source_id'])>0)throw new RuntimeException('Duplicate journal source identities prevent the Schema 42 exactly-once index.');
        schema_add_index('journal_entries','journal_entries_source_uq','company_id,source_type,source_id',true);
    }
    if(!schema_constraint_exists('journal_entries','journal_entries_voided_user_fk'))db()->exec('ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_voided_user_fk FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL');
}

/** @param array<int,array<string,mixed>> $lines */
function tegh_schema42_journal_hash(string $date,string $memo,array $lines): string
{
    $canonical=array_map(static fn(array $line):array=>[
        'accountId'=>(string)$line['account_id'],
        'debitCents'=>(int)$line['debit_cents'],
        'creditCents'=>(int)$line['credit_cents'],
        'memo'=>mb_substr((string)$line['memo'],0,500),
        'fxRoundingCents'=>(int)($line['fx_rounding_cents']??0),
    ],$lines);
    usort($canonical,static fn(array $left,array $right):int=>strcmp(
        json_encode($left,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        json_encode($right,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
    ));
    return hash('sha256',json_encode(['entryDate'=>$date,'memo'=>mb_substr($memo,0,500),'lines'=>$canonical],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function tegh_schema42_backfill_journal_hashes(): void
{
    $entries=db()->prepare("SELECT id,entry_date,memo FROM journal_entries WHERE content_hash IS NULL OR content_hash NOT REGEXP '^[0-9a-f]{64}$' ORDER BY id LIMIT 500");
    $lines=db()->prepare('SELECT account_id,debit_cents,credit_cents,memo,fx_rounding_cents FROM journal_lines WHERE journal_entry_id=? ORDER BY id');
    $update=db()->prepare('UPDATE journal_entries SET content_hash=? WHERE id=?');
    do{
        $entries->execute();$batch=$entries->fetchAll();
        foreach($batch as $entry){$lines->execute([$entry['id']]);$update->execute([tegh_schema42_journal_hash((string)$entry['entry_date'],(string)$entry['memo'],$lines->fetchAll()),$entry['id']]);}
    }while(count($batch)===500);
    $invalid=(int)db()->query("SELECT COUNT(*) FROM journal_entries WHERE content_hash IS NULL OR content_hash NOT REGEXP '^[0-9a-f]{64}$'")->fetchColumn();
    if($invalid!==0)throw new RuntimeException('Journal content-hash backfill is incomplete.');
    if(schema_column_nullable('journal_entries','content_hash'))db()->exec('ALTER TABLE journal_entries MODIFY COLUMN content_hash CHAR(64) NOT NULL');
}

function tegh_schema42_create_tables(): void
{
    foreach(tegh_schema42_table_sql() as $sql)db()->exec($sql);
}

function tegh_schema42_seed_transitions(): void
{
    $stmt=db()->prepare('INSERT INTO transaction_status_transitions (entity_type,from_status,to_status,transition_code,active) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE transition_code=VALUES(transition_code),active=1');
    foreach(tegh_schema42_transition_seed() as $row)$stmt->execute($row);
}

function tegh_schema42_columns_prepared(): bool
{
    foreach([
        ['accounting_controls','materiality_threshold_cents'],['journal_entries','content_hash'],['journal_entries','voided_at'],['journal_entries','voided_by'],
        ['journal_lines','fx_rounding_cents'],['bank_match_groups','unreconcile_reason_code'],['native_agent_runs','lease_renewed_at'],
    ] as [$table,$column])if(!schema_column_exists($table,$column))return false;
    $journal=tegh_schema_column_actual('journal_entries','status');$bills=tegh_schema_column_actual('bills','status');
    if(!str_contains((string)($journal['type']??''),"'pending_post'")||!str_contains((string)($bills['type']??''),"'submitted_for_approval'"))return false;
    if(schema_column_nullable('native_agent_runs','lease_renewed_at'))return false;
    $source=tegh_schema_index_actual('journal_entries','journal_entries_source_uq');
    if($source===null||!tegh_schema_contract_equal(['columns'=>['company_id','source_type','source_id'],'unique'=>true],$source))return false;
    return schema_constraint_exists('journal_entries','journal_entries_voided_user_fk');
}

function tegh_schema42_hashes_ready(): bool
{
    if(!schema_column_exists('journal_entries','content_hash')||schema_column_nullable('journal_entries','content_hash'))return false;
    return (int)db()->query("SELECT COUNT(*) FROM journal_entries WHERE content_hash IS NULL OR content_hash NOT REGEXP '^[0-9a-f]{64}$'")->fetchColumn()===0;
}

function tegh_schema42_tables_ready(): bool
{
    foreach(tegh_schema42_table_columns() as $table=>$columns){
        if(!schema_table_exists($table))return false;
        foreach($columns as $column)if(!schema_column_exists($table,$column))return false;
    }
    return true;
}

function tegh_schema42_transitions_ready(): bool
{
    if(!schema_table_exists('transaction_status_transitions'))return false;
    $stmt=db()->prepare('SELECT transition_code,active FROM transaction_status_transitions WHERE entity_type=? AND from_status=? AND to_status=?');
    foreach(tegh_schema42_transition_seed() as [$entity,$from,$to,$code]){
        $stmt->execute([$entity,$from,$to]);$row=$stmt->fetch();
        if(!$row||!hash_equals($code,(string)$row['transition_code'])||(int)$row['active']!==1)return false;
    }
    return true;
}

function tegh_schema42_verify_completed_checksums(): void
{
    if(!schema_table_exists('database_migration_events'))return;
    $stmt=db()->query("SELECT migration_id,migration_checksum FROM database_migration_events WHERE migration_id LIKE 'schema42.%' AND state='completed' ORDER BY event_id");
    foreach($stmt->fetchAll() as $row){$expected=tegh_migration_checksum((string)$row['migration_id']);if(!hash_equals($expected,(string)$row['migration_checksum']))throw new RuntimeException('Migration checksum mismatch for '.(string)$row['migration_id'].'.');}
}

/** @return array<string,mixed> */
function tegh_schema42_preflight(): array
{
    $marker=current_database_schema_version();$schema41=tegh_schema41_status();$status=tegh_schema42_status();
    $state='unsupported_or_unsafe';$label='Unsupported or unsafe database state';$retry=false;$mutation=true;
    if($marker>42){$state='newer_schema';$label='Database is newer than this release';$mutation=false;}
    elseif(!$schema41['ready']){$state='schema41_prerequisite_incomplete';$label='Schema 41 prerequisite is incomplete';}
    elseif($status['ready']&&$marker===42){$state='complete_schema_42';$label='Database is already up to date';$retry=true;$mutation=false;}
    elseif($status['ready']){$state='schema_42_objects_stale_marker';$label='Schema 42 objects are complete but the marker is stale';$retry=true;}
    else{
        $unsafe=array_filter($status['issues'],static fn(array $issue):bool=>empty($issue['repairable']));
        $state=$marker===42?'marker_42_incomplete':'schema_41_ready_for_42';
        $label=$unsafe?'Schema 42 contains a malformed partial contract':'Schema 41 is eligible for the resumable Schema 42 upgrade';
        $retry=$unsafe===[]&&$marker>=41;
    }
    $capabilities=tegh_database_upgrade_capabilities();if($mutation&&(!$capabilities['privilegesReady']||!$capabilities['advisoryLockSupported']))$retry=false;
    $event=null;if(schema_table_exists('database_migration_events')){$stmt=db()->query("SELECT migration_id,state,request_reference,started_at,completed_at FROM database_migration_events WHERE migration_id LIKE 'schema42.%' ORDER BY event_id DESC LIMIT 1");$event=$stmt->fetch()?:null;}
    return ['state'=>$state,'stateLabel'=>$label,'markerVersion'=>$marker,'structuralVersion'=>$status['ready']?42:41,'expectedSchemaVersion'=>42,'ready'=>$state==='complete_schema_42','retrySafe'=>$retry,'mutationRequired'=>$mutation,'schema41Ready'=>(bool)$schema41['ready'],'contract'=>$status,'capabilities'=>$capabilities,'privateRuntime'=>tegh_private_runtime_status(),'lastMigrationEvent'=>$event,'build'=>SR_ACCOUNTAX_BUILD,'version'=>SR_ACCOUNTAX_VERSION,'requestReference'=>request_id(),'checkedAt'=>gmdate('c')];
}

/** @return array<string,mixed> */
function tegh_schema42_upgrade(array $user,bool $backupConfirmed): array
{
    $initial=tegh_schema42_preflight();$previous=(int)$initial['markerVersion'];$steps=[];
    if($initial['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'message'=>'Database is already up to date','previousSchema'=>42,'schemaVersion'=>42,'steps'=>[],'preflight'=>$initial];
    if(!$backupConfirmed)fail('Confirm the database, website files and private storage backup before starting the upgrade.',409,'database_backup_required');
    if(!$initial['schema41Ready'])fail('Complete and verify Schema 41 before applying Schema 42 transaction controls.',409,'schema41_prerequisite_incomplete');
    if(!$initial['retrySafe'])fail('The protected preflight found an unsupported or unsafe database state. View the diagnostic before retrying.',409,'schema_upgrade_unsafe');
    $maintenanceToken=tegh_maintenance_mode_begin('schema42_upgrade');
    if($maintenanceToken==='')fail('Another protected maintenance operation is active. Review its status before retrying.',409,'maintenance_mode_busy');
    $lock=0;
    try{
        $lock=(int)db()->query("SELECT GET_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."',2)")->fetchColumn();
        if($lock!==1)fail('Another Tegh database upgrade is still running. Wait a moment and retry.',409,'schema_upgrade_busy');
        $fresh=tegh_schema42_preflight();if(!$fresh['retrySafe']&&!$fresh['ready'])throw new RuntimeException('Schema 42 preflight found an unsupported partial contract.');
        if($fresh['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'message'=>'Database is already up to date','previousSchema'=>$previous,'schemaVersion'=>42,'steps'=>[],'preflight'=>$fresh];
        tegh_schema42_verify_completed_checksums();tegh_migration_ledger_ensure();
        tegh_run_migration_step('schema42.5900.columns',static fn()=>tegh_schema42_repair_columns(),static fn():bool=>tegh_schema42_columns_prepared(),$steps);
        tegh_run_migration_step('schema42.5900.journal_hash_backfill',static fn()=>tegh_schema42_backfill_journal_hashes(),static fn():bool=>tegh_schema42_hashes_ready(),$steps);
        tegh_run_migration_step('schema42.5900.tables',static fn()=>tegh_schema42_create_tables(),static fn():bool=>tegh_schema42_tables_ready(),$steps);
        tegh_run_migration_step('schema42.5900.transitions',static fn()=>tegh_schema42_seed_transitions(),static fn():bool=>tegh_schema42_transitions_ready(),$steps);
        $status=tegh_schema42_status();if(!$status['ready'])throw new RuntimeException('Schema 42 post-migration verification is incomplete.');
        tegh_run_migration_step('schema42.5900.adopt_marker',static fn()=>tegh_mark_schema_version(42),static fn():bool=>current_database_schema_version()===42&&tegh_schema42_status()['ready'],$steps);
        system_incident_write_private_log(['requestId'=>request_id(),'occurredAt'=>gmdate('c'),'source'=>'schema42_transaction_controls','route'=>'startup/migrate','httpStatus'=>200,'errorCode'=>'schema_upgrade_completed','publicMessage'=>'Schema 42 upgrade completed.','context'=>system_incident_clean_context(['actorUserId'=>(string)$user['id'],'previousSchema'=>$previous,'resultingSchema'=>42,'steps'=>$steps,'accountingTransactionsPosted'=>0])]);
        return ['ok'=>true,'alreadyUpToDate'=>false,'message'=>'Upgrade successful','previousSchema'=>$previous,'schemaVersion'=>42,'steps'=>$steps,'preflight'=>tegh_schema42_preflight(),'accountingTransactionsPosted'=>0];
    }catch(Throwable $error){record_system_incident('Schema 42 upgrade could not be completed. No accounting transaction was posted or changed.',500,'schema42_upgrade_failed',$error,['source'=>'schema42_transaction_controls','completedSteps'=>$steps,'initialPreflight'=>$initial]);throw $error;}
    finally{if($lock===1)try{db()->query("SELECT RELEASE_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."')");}catch(Throwable){}tegh_maintenance_mode_end($maintenanceToken);}
}

function handle_tegh_schema42_preflight(): never
{
    require_method('GET');tegh_require_platform_owner();$result=tegh_schema42_preflight();system_incident_write_private_log(['requestId'=>request_id(),'occurredAt'=>gmdate('c'),'source'=>'schema42_preflight','route'=>'startup/migration-preflight','httpStatus'=>200,'errorCode'=>'schema42_preflight','publicMessage'=>(string)$result['stateLabel'],'context'=>system_incident_clean_context($result)]);json_response(['ok'=>true,'preflight'=>$result]);
}

function handle_tegh_schema42_diagnostic(): never
{
    require_method('GET');tegh_require_platform_owner();$result=tegh_schema42_preflight();$events=[];if(schema_table_exists('database_migration_events')){$stmt=db()->query('SELECT migration_id,migration_checksum,state,started_at,completed_at,request_reference,build_number,details_json FROM database_migration_events ORDER BY event_id DESC LIMIT 150');foreach($stmt->fetchAll() as $row){$row['details']=json_decode((string)$row['details_json'],true)?:[];unset($row['details_json']);$events[]=$row;}}json_response(['ok'=>true,'diagnostic'=>$result,'migrationEvents'=>$events]);
}

function handle_tegh_schema42_upgrade(): never
{
    require_method('POST');$user=tegh_require_platform_owner();require_csrf();$input=request_json();@ignore_user_abort(true);@set_time_limit(180);try{json_response(tegh_schema42_upgrade($user,!empty($input['backupConfirmed'])));}catch(Throwable $error){error_log('Tegh Schema 42 request='.request_id().' class='.$error::class.' message='.system_incident_redact_text($error->getMessage(),1000));fail('Database upgrade could not be completed. No accounting transaction was changed. Structural preparation may be partial and is safe to retry after reviewing the diagnostic. Request reference: '.request_id().'.',500,'schema_upgrade_failed',false);}
}
