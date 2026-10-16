<?php
declare(strict_types=1);

/** Additive AR/AP note storage and the invoice tax fields required by debit notes. */
function tegh_notes_r67_sql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS accounting_notes (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 company_id VARCHAR(64) NOT NULL,
 source_type ENUM('invoice','bill') NOT NULL,
 source_id VARCHAR(64) NOT NULL,
 party_id VARCHAR(64) NOT NULL,
 note_kind ENUM('customer_credit','customer_debit','vendor_credit','vendor_debit') NOT NULL,
 number VARCHAR(40) NOT NULL,
 note_date DATE NOT NULL,
 status ENUM('draft','posted','void') NOT NULL DEFAULT 'draft',
 currency CHAR(3) NOT NULL,
 exchange_rate_micros BIGINT NOT NULL,
 foreign_subtotal_cents BIGINT NOT NULL,
 foreign_tax_cents BIGINT NOT NULL,
 foreign_total_cents BIGINT NOT NULL,
 subtotal_cents BIGINT NOT NULL,
 tax_cents BIGINT NOT NULL,
 total_cents BIGINT NOT NULL,
 applied_cents BIGINT NOT NULL DEFAULT 0,
 debit_document_id VARCHAR(64) NULL,
 journal_entry_id VARCHAR(64) NULL,
 reversal_journal_entry_id VARCHAR(64) NULL,
 void_date DATE NULL,
 memo VARCHAR(500) NOT NULL DEFAULT '',
 operation_key VARCHAR(120) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 created_by VARCHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY accounting_notes_company_number_uq (company_id,number),
 UNIQUE KEY accounting_notes_operation_key_uq (company_id,operation_key),
 UNIQUE KEY accounting_notes_debit_document_uq (debit_document_id),
 KEY accounting_notes_source_idx (company_id,source_type,source_id,status),
 KEY accounting_notes_party_idx (company_id,party_id,note_date),
 CONSTRAINT accounting_notes_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT accounting_notes_actor_fk FOREIGN KEY (created_by) REFERENCES users(id),
 CONSTRAINT accounting_notes_amount_ck CHECK (foreign_subtotal_cents > 0 AND foreign_tax_cents >= 0 AND foreign_total_cents = foreign_subtotal_cents + foreign_tax_cents AND subtotal_cents > 0 AND tax_cents >= 0 AND total_cents = subtotal_cents + tax_cents AND applied_cents >= 0 AND exchange_rate_micros > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
}

/** Dated, immutable uses of a posted note. Existing autoapplied R67 notes are
 * represented by their retained applied_cents and need no destructive backfill. */
function tegh_notes_r70_settlement_sql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS accounting_note_settlements (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 company_id VARCHAR(64) NOT NULL,
 note_id VARCHAR(64) NOT NULL,
 settlement_kind ENUM('application','refund') NOT NULL,
 document_id VARCHAR(64) NULL,
 payment_id VARCHAR(64) NULL,
 settlement_date DATE NOT NULL,
 foreign_amount_cents BIGINT NOT NULL,
 amount_cents BIGINT NOT NULL,
 status ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
 reversal_date DATE NULL,
 operation_key VARCHAR(120) NOT NULL,
 created_by VARCHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY note_settlement_operation_uq (company_id,operation_key),
 UNIQUE KEY note_settlement_refund_payment_uq (payment_id),
 KEY note_settlement_note_date_idx (company_id,note_id,settlement_date),
 KEY note_settlement_document_idx (company_id,document_id,settlement_date),
 CONSTRAINT note_settlement_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT note_settlement_note_fk FOREIGN KEY (note_id) REFERENCES accounting_notes(id) ON DELETE RESTRICT,
 CONSTRAINT note_settlement_payment_fk FOREIGN KEY (payment_id) REFERENCES party_payments(id) ON DELETE RESTRICT,
 CONSTRAINT note_settlement_actor_fk FOREIGN KEY (created_by) REFERENCES users(id),
 CONSTRAINT note_settlement_amount_ck CHECK (foreign_amount_cents > 0 AND amount_cents > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
}

function tegh_notes_r70_settlements_ready(): bool
{
    if(!schema_table_exists('accounting_note_settlements'))return false;
    foreach(['id','company_id','note_id','settlement_kind','document_id','payment_id','settlement_date','foreign_amount_cents','amount_cents','status','reversal_date','operation_key','created_by'] as $column)
        if(!schema_column_exists('accounting_note_settlements',$column))return false;
    return schema_index_exists('accounting_note_settlements','note_settlement_operation_uq')
        && schema_index_exists('accounting_note_settlements','note_settlement_refund_payment_uq');
}

function tegh_tax_r71_preset_sql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS company_invoice_tax_presets (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 company_id VARCHAR(64) NOT NULL,
 label VARCHAR(80) NOT NULL,
 province CHAR(2) NOT NULL,
 rate_bps INT NOT NULL,
 reason VARCHAR(500) NOT NULL,
 effective_from DATE NOT NULL,
 status ENUM('active','retired') NOT NULL DEFAULT 'active',
 created_by VARCHAR(64) NOT NULL,
 updated_by VARCHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY company_invoice_tax_presets_lookup (company_id,status,province,effective_from),
 CONSTRAINT company_invoice_tax_presets_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT company_invoice_tax_presets_created_fk FOREIGN KEY (created_by) REFERENCES users(id),
 CONSTRAINT company_invoice_tax_presets_updated_fk FOREIGN KEY (updated_by) REFERENCES users(id),
 CONSTRAINT company_invoice_tax_presets_rate_ck CHECK (rate_bps >= 0 AND rate_bps <= 3000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
}

function tegh_tax_r71_presets_ready(): bool
{
    if(!schema_table_exists('company_invoice_tax_presets'))return false;
    foreach(['id','company_id','label','province','rate_bps','reason','effective_from','status','created_by','updated_by'] as $column)
        if(!schema_column_exists('company_invoice_tax_presets',$column))return false;
    return schema_index_exists('company_invoice_tax_presets','company_invoice_tax_presets_lookup');
}

function tegh_notes_r67_ready(): bool
{
    $q=db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_notes' AND COLUMN_NAME IN ('id','company_id','source_type','source_id','note_kind','status','foreign_total_cents','total_cents','applied_cents','debit_document_id','journal_entry_id','operation_key','payload_hash')");
    if((int)$q->fetchColumn()!==13)return false;
    $indexes=db()->query("SELECT INDEX_NAME,NON_UNIQUE,COUNT(*) parts FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounting_notes' GROUP BY INDEX_NAME,NON_UNIQUE");
    $found=[];foreach($indexes->fetchAll() as $row)$found[(string)$row['INDEX_NAME']]=[(int)$row['NON_UNIQUE'],(int)$row['parts']];
    return ($found['accounting_notes_company_number_uq']??null)===[0,2]
        &&($found['accounting_notes_operation_key_uq']??null)===[0,2]
        &&($found['accounting_notes_debit_document_uq']??null)===[0,1]
        &&isset($found['accounting_notes_source_idx'],$found['accounting_notes_party_idx']);
}

function tegh_notes_r69_invoice_tax_columns_ready(): bool
{
    return schema_column_exists('invoices','gst_hst_cents')
        && schema_column_exists('invoices','pst_cents')
        && schema_column_exists('invoices','tax_entry_mode');
}

function tegh_notes_r69_unclassified_invoice_tax_count(): int
{
    if(!tegh_notes_r69_invoice_tax_columns_ready())return -1;
    // R119: invoices issued after the upgrade were saved without a split. The
    // backfill is deterministic (from each invoice's own posting) and
    // idempotent, so heal them here instead of blocking every sign-in.
    tegh_notes_r69_backfill_invoice_tax_split();
    $q=db()->query("SELECT COUNT(*) FROM invoices i JOIN companies c ON c.id=i.company_id WHERE c.accounting_basis='accrual' AND i.status IN ('sent','paid') AND i.tax_cents>0 AND i.gst_hst_cents+i.pst_cents<>i.tax_cents");
    return (int)$q->fetchColumn();
}

function tegh_notes_r69_repair_invoice_tax_columns(): void
{
    schema_add_column('invoices','gst_hst_cents','BIGINT NOT NULL DEFAULT 0 AFTER `subtotal_cents`');
    schema_add_column('invoices','pst_cents','BIGINT NOT NULL DEFAULT 0 AFTER `gst_hst_cents`');
    schema_add_column('invoices','tax_entry_mode',"ENUM('none','exclusive','inclusive') NOT NULL DEFAULT 'none' AFTER `tax_cents`");
    tegh_notes_r69_backfill_invoice_tax_split();
}

function tegh_notes_r69_backfill_invoice_tax_split(): void
{
    // Recover the historic split from each issued invoice's own posting. Do not
    // guess a province or silently classify an unknown PST amount as GST/HST.
    $pending=db()->query("SELECT 1 FROM invoices WHERE tax_cents>0 AND gst_hst_cents=0 AND pst_cents=0 AND issued_journal_entry_id IS NOT NULL LIMIT 1")->fetchColumn();
    if($pending===false)return;
    db()->exec("UPDATE invoices i JOIN (
        SELECT jl.journal_entry_id,
            SUM(CASE WHEN a.code='2100' THEN jl.credit_cents-jl.debit_cents ELSE 0 END) gst,
            SUM(CASE WHEN a.code='2110' THEN jl.credit_cents-jl.debit_cents ELSE 0 END) pst
        FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id
        WHERE a.code IN ('2100','2110') GROUP BY jl.journal_entry_id
    ) posted ON posted.journal_entry_id=i.issued_journal_entry_id
    SET i.gst_hst_cents=posted.gst,i.pst_cents=posted.pst,
        i.tax_entry_mode=IF(i.tax_entry_mode='none','exclusive',i.tax_entry_mode)
    WHERE i.tax_cents>0 AND i.gst_hst_cents=0 AND i.pst_cents=0
      AND posted.gst>=0 AND posted.pst>=0 AND posted.gst+posted.pst=i.tax_cents");
}

function tegh_notes_r67_require(): void
{
    if(current_database_schema_version()<46 || !tegh_notes_r67_ready() || !tegh_notes_r69_invoice_tax_columns_ready() || !tegh_notes_r70_settlements_ready())
        fail('A Platform Owner must complete the protected Schema 46 notes upgrade before debit and credit notes can be used.',503,'notes_r67_required');
}

function tegh_notes_r67_preflight(): array
{
    $marker=current_database_schema_version();$prior=$marker<=46?($marker===46?tegh_schema46_preflight()['ready']:tegh_schema46_preflight()['retrySafe']):false;
    $notes=tegh_notes_r67_ready();$invoiceColumns=tegh_notes_r69_invoice_tax_columns_ready();$settlements=tegh_notes_r70_settlements_ready();$taxPresets=tegh_tax_r71_presets_ready();
    $unclassified=$invoiceColumns?tegh_notes_r69_unclassified_invoice_tax_count():-1;
    $retrySafe=$prior&&($notes||!schema_table_exists('accounting_notes'))
        &&($settlements||!schema_table_exists('accounting_note_settlements'))
        &&($taxPresets||!schema_table_exists('company_invoice_tax_presets'))&&$unclassified<=0;
    $ready=$prior&&$notes&&$invoiceColumns&&$settlements&&$taxPresets&&$unclassified===0;
    $issues=[];
    if(!$notes)$issues[]=['object'=>'accounting_notes','code'=>'missing_or_incomplete_table','repairable'=>!schema_table_exists('accounting_notes')];
    if(!$invoiceColumns)$issues[]=['object'=>'invoices','code'=>'missing_note_tax_columns','repairable'=>true];
    if(!$settlements)$issues[]=['object'=>'accounting_note_settlements','code'=>'missing_or_incomplete_settlements','repairable'=>!schema_table_exists('accounting_note_settlements')];
    if(!$taxPresets)$issues[]=['object'=>'company_invoice_tax_presets','code'=>'missing_or_incomplete_tax_presets','repairable'=>!schema_table_exists('company_invoice_tax_presets')];
    if($unclassified>0)$issues[]=['object'=>'invoices','code'=>'unclassified_issued_tax','count'=>$unclassified,'repairable'=>false];
    return ['ready'=>$ready,'retrySafe'=>$retrySafe,'mutationRequired'=>!$ready,
        'state'=>$ready?'complete_notes_r67':($retrySafe?'notes_r67_preparation_required':'notes_r67_diagnostic_required'),
        'stateLabel'=>$ready?'Database is up to date':'Repair accounting note prerequisites',
        'markerVersion'=>$marker,'structuralVersion'=>$notes&&$invoiceColumns&&$settlements&&$taxPresets?46:($prior?46:0),'expectedSchemaVersion'=>46,
        'contract'=>['ready'=>$notes&&$invoiceColumns&&$settlements&&$taxPresets&&$unclassified===0,'issues'=>$issues],
        'build'=>SR_ACCOUNTAX_BUILD,'version'=>SR_ACCOUNTAX_VERSION,'requestReference'=>request_id(),'checkedAt'=>gmdate('c')];
}

function handle_tegh_notes_r67_preflight(): never
{
    require_method('GET');tegh_require_platform_owner();
    json_response(['ok'=>true,'preflight'=>tegh_notes_r67_preflight()]);
}

function handle_tegh_notes_r67_diagnostic(): never
{
    require_method('GET');tegh_require_platform_owner();
    json_response(['ok'=>true,'diagnostic'=>tegh_notes_r67_preflight()]);
}

function handle_tegh_notes_r67_upgrade(): never
{
    require_method('POST');$user=tegh_require_platform_owner();require_csrf();$input=request_json();
    if(empty($input['backupConfirmed']))fail('Confirm a verified database, application and private-storage backup.',409,'database_backup_required');
    $marker=current_database_schema_version();
    if($marker<46 || ($marker===46&&!tegh_schema46_preflight()['ready'])){
        tegh_schema46_upgrade($user,true);
        $marker=current_database_schema_version();
    }
    if($marker===46 && !tegh_schema46_preflight()['ready'])fail('Complete the protected Schema 46 upgrade first.',409,'schema46_required');
    if($marker<46 || !tegh_schema46_status()['ready'])fail('Complete the protected Schema 46 upgrade first.',409,'schema46_required');
    if($marker>46)fail('The database requires a newer Tegh release.',503,'newer_schema_unsupported');
    if(!tegh_notes_r67_preflight()['retrySafe'])fail('The accounting notes table is incomplete. Review the diagnostic before upgrading.',409,'notes_r67_diagnostic_required');
    $maintenance=tegh_maintenance_mode_begin('notes_r67_upgrade');
    if($maintenance==='')fail('Another maintenance operation is active.',409,'maintenance_mode_busy');
    // json_response() and fail() exit PHP immediately. Shutdown cleanup also
    // covers those paths, while the finally block handles ordinary returns.
    register_shutdown_function(static function() use ($maintenance):void{tegh_maintenance_mode_end($maintenance);});
    $lock=0;$steps=[];
    try {
        $lock=(int)db()->query("SELECT GET_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."',2)")->fetchColumn();
        if($lock!==1)fail('Another database upgrade is running.',409,'schema_upgrade_busy');
        tegh_migration_ledger_ensure();
        tegh_run_migration_step('schema46.5990.accounting_notes_r67',static function():void{db()->exec(tegh_notes_r67_sql());},static fn():bool=>tegh_notes_r67_ready(),$steps);
        tegh_run_migration_step('schema46.5990.invoice_note_tax_columns_r69',static function():void{tegh_notes_r69_repair_invoice_tax_columns();},static fn():bool=>tegh_notes_r69_invoice_tax_columns_ready(),$steps);
        tegh_run_migration_step('schema46.5990.accounting_note_settlements_r70',static function():void{db()->exec(tegh_notes_r70_settlement_sql());},static fn():bool=>tegh_notes_r70_settlements_ready(),$steps);
        tegh_run_migration_step('schema46.5990.company_invoice_tax_presets_r71',static function():void{db()->exec(tegh_tax_r71_preset_sql());},static fn():bool=>tegh_tax_r71_presets_ready(),$steps);
        // Recheck legacy tax allocations even if the structural migration was already recorded.
        tegh_notes_r69_repair_invoice_tax_columns();
        $preflight=tegh_notes_r67_preflight();
        $result=['ok'=>true,'schemaVersion'=>46,'previousSchema'=>$marker,'steps'=>$steps,'notesReady'=>$preflight['ready'],'accountingTransactionsPosted'=>0,'preflight'=>$preflight];
    } finally {
        try{if($lock===1)db()->query("SELECT RELEASE_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."')");}
        finally{tegh_maintenance_mode_end($maintenance);}
    }
    json_response($result);
}
