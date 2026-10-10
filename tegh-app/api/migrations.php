<?php
declare(strict_types=1);

function schema_table_exists(string $table): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function schema_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function schema_add_column(string $table, string $column, string $definition): void
{
    if (!schema_column_exists($table, $column)) {
        db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function schema_column_nullable(string $table, string $column): bool
{
    $stmt = db()->prepare('SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return strtoupper((string)$stmt->fetchColumn()) === 'YES';
}

function schema_constraint_exists(string $table, string $constraint): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?');
    $stmt->execute([$table, $constraint]);
    return (int)$stmt->fetchColumn() > 0;
}

function schema_index_exists(string $table,string $index): bool
{
    $stmt=db()->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
    $stmt->execute([$table,$index]);
    return (int)$stmt->fetchColumn()>0;
}

function schema_add_index(string $table,string $index,string $columns,bool $unique=false): void
{
    if(!schema_index_exists($table,$index)){
        $kind=$unique?'UNIQUE INDEX':'INDEX';
        db()->exec("ALTER TABLE `$table` ADD $kind `$index` ($columns)");
    }
}

/** Best-effort CHECK constraint for shared-hosting MySQL/MariaDB compatibility. */
function schema_try_add_check(string $table, string $constraint, string $expression): void
{
    if (schema_constraint_exists($table, $constraint)) return;
    try {
        db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$constraint` CHECK ($expression)");
    } catch (Throwable $ignored) {
        // Older MySQL/MariaDB variants may not support/enforce named CHECK constraints.
        // Application validation remains authoritative; a missing optional CHECK must not
        // make the accounting workspace unavailable.
    }
}

function ai_additive_schema_requirements(): array
{
    return [
        'tables'=>[
            'ai_agent_action_authorizations','ai_agent_result_sets','ai_agent_tasks',
            'ai_agent_learning_events','ai_agent_learned_rules','ai_agent_improvements','ai_agent_behavior_versions','ai_agent_review_runs',
            'ai_agent_conversations','ai_agent_memories','ai_agent_memory_history','ai_agent_plans',
        ],
        'columns'=>[
            'ai_agent_action_authorizations'=>['conversation_id','plan_id','task_id','operation_key','result_status','result_json'],
            'ai_agent_tasks'=>['conversation_id','plan_id'],
            'ai_agent_result_sets'=>['conversation_id'],
        ],
        'indexes'=>[
            'ai_agent_action_authorizations'=>['ai_action_company_user_idx','ai_action_status_idx','ai_action_operation_key_idx','ai_action_conversation_idx'],
            'ai_agent_result_sets'=>['ai_result_company_user_idx','ai_result_expiry_idx'],
            'ai_agent_tasks'=>['ai_task_company_user_idx','ai_task_status_idx'],
            'ai_agent_learning_events'=>['ai_learning_company_created_idx','ai_learning_company_action_idx','ai_learning_company_fingerprint_idx'],
            'ai_agent_learned_rules'=>['ai_rules_company_status_idx','ai_rules_company_pattern_idx'],
            'ai_agent_improvements'=>['ai_improvements_company_fingerprint_uq','ai_improvements_company_status_idx'],
            'ai_agent_behavior_versions'=>['ai_behavior_company_version_uq','ai_behavior_company_active_idx'],
            'ai_agent_review_runs'=>['ai_review_company_created_idx'],
            'ai_agent_conversations'=>['ai_conversation_company_user_idx','ai_conversation_expiry_idx'],
            'ai_agent_memories'=>['ai_memory_company_type_idx','ai_memory_company_key_idx','ai_memory_user_idx'],
            'ai_agent_memory_history'=>['ai_memory_history_memory_idx','ai_memory_history_company_idx'],
            'ai_agent_plans'=>['ai_plan_company_user_idx','ai_plan_conversation_idx','ai_plan_expiry_idx'],
        ],
    ];
}

function ai_additive_schema_status(): array
{
    $requirements=ai_additive_schema_requirements();$missingTables=[];$missingColumns=[];$missingIndexes=[];
    foreach($requirements['tables'] as $table)if(!schema_table_exists($table))$missingTables[]=$table;
    foreach($requirements['columns'] as $table=>$columns){
        if(!schema_table_exists($table))continue;
        foreach($columns as $column)if(!schema_column_exists($table,$column))$missingColumns[]=$table.'.'.$column;
    }
    foreach($requirements['indexes'] as $table=>$indexes){
        if(!schema_table_exists($table))continue;
        foreach($indexes as $index)if(!schema_index_exists($table,$index))$missingIndexes[]=$table.'.'.$index;
    }
    return [
        'ready'=>!$missingTables&&!$missingColumns&&!$missingIndexes,
        'missingTables'=>$missingTables,'missingColumns'=>$missingColumns,'missingIndexes'=>$missingIndexes,
    ];
}

function ai_additive_schema_ready(): bool
{
    return (bool)ai_additive_schema_status()['ready'];
}

/**
 * Repair only the additive Tegh AI structures already defined by Schema 34.
 * This intentionally does not change the schema version marker or mutate any
 * accounting, bank, journal, invoice, bill, customer or vendor table.
 */
function ensure_schema34_ai_storage(?array $actor=null): array
{
    if(!schema_table_exists('companies')||!schema_table_exists('users')){
        throw new RuntimeException('Core company/user tables are unavailable; AI-only repair cannot run safely.');
    }
    $before=ai_additive_schema_status();

    // Schema 27/29 AI foundations are additive too. Re-create them only when a
    // production database carries a Schema 34 marker but missed those objects.
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_action_authorizations (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL,
      action_type VARCHAR(60) NOT NULL, payload_json LONGTEXT NOT NULL, payload_hash CHAR(64) NOT NULL,
      status ENUM('pending','authorized','completed','expired','cancelled') NOT NULL DEFAULT 'pending',
      expires_at DATETIME NOT NULL, authorized_at DATETIME NULL, completed_at DATETIME NULL, journal_entry_id VARCHAR(64) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ai_action_company_user_idx (company_id,user_id,created_at), KEY ai_action_status_idx (status,expires_at),
      CONSTRAINT ai_action_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_action_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_result_sets (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL, kind VARCHAR(40) NOT NULL,
      query_json LONGTEXT NOT NULL, record_ids_json LONGTEXT NOT NULL, selected_ids_json LONGTEXT NOT NULL, status_snapshot_json LONGTEXT NOT NULL,
      expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY ai_result_company_user_idx (company_id,user_id,updated_at), KEY ai_result_expiry_idx (expires_at),
      CONSTRAINT ai_result_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_result_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_tasks (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL, action_id VARCHAR(120) NOT NULL, intent VARCHAR(500) NOT NULL,
      module VARCHAR(80) NOT NULL DEFAULT '', workflow_state VARCHAR(60) NOT NULL DEFAULT 'active', collected_json LONGTEXT NOT NULL, missing_json LONGTEXT NOT NULL,
      result_set_id VARCHAR(64) NULL, pending_confirmation_id VARCHAR(64) NULL,
      status ENUM('active','waiting_input','waiting_confirmation','suspended','cancelled','completed') NOT NULL DEFAULT 'active',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY ai_task_company_user_idx (company_id,user_id,updated_at), KEY ai_task_status_idx (status,updated_at),
      CONSTRAINT ai_task_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_task_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT ai_task_result_fk FOREIGN KEY (result_set_id) REFERENCES ai_agent_result_sets(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensure_v33_ai_learning_schema();
    ensure_v34_human_agent_schema();

    // CREATE TABLE IF NOT EXISTS does not repair indexes on partially-created
    // tables, so explicitly ensure every Schema-34 AI index used by the app.
    $indexSpecs=[
      ['ai_agent_action_authorizations','ai_action_company_user_idx','company_id,user_id,created_at',false],
      ['ai_agent_action_authorizations','ai_action_status_idx','status,expires_at',false],
      ['ai_agent_action_authorizations','ai_action_operation_key_idx','operation_key',false],
      ['ai_agent_action_authorizations','ai_action_conversation_idx','company_id,user_id,conversation_id,created_at',false],
      ['ai_agent_result_sets','ai_result_company_user_idx','company_id,user_id,updated_at',false],['ai_agent_result_sets','ai_result_expiry_idx','expires_at',false],
      ['ai_agent_tasks','ai_task_company_user_idx','company_id,user_id,updated_at',false],['ai_agent_tasks','ai_task_status_idx','status,updated_at',false],
      ['ai_agent_learning_events','ai_learning_company_created_idx','company_id,created_at',false],
      ['ai_agent_learning_events','ai_learning_company_action_idx','company_id,action_id(96),created_at',false],
      ['ai_agent_learning_events','ai_learning_company_fingerprint_idx','company_id,command_fingerprint,created_at',false],
      ['ai_agent_learned_rules','ai_rules_company_status_idx','company_id,status,confidence_bps',false],
      ['ai_agent_learned_rules','ai_rules_company_pattern_idx','company_id,pattern_type,pattern_value(64)',false],
      ['ai_agent_improvements','ai_improvements_company_fingerprint_uq','company_id,evidence_fingerprint',true],
      ['ai_agent_improvements','ai_improvements_company_status_idx','company_id,status,created_at',false],
      ['ai_agent_behavior_versions','ai_behavior_company_version_uq','company_id,version_number',true],
      ['ai_agent_behavior_versions','ai_behavior_company_active_idx','company_id,active,version_number',false],
      ['ai_agent_review_runs','ai_review_company_created_idx','company_id,created_at',false],
      ['ai_agent_conversations','ai_conversation_company_user_idx','company_id,user_id,status,updated_at',false],['ai_agent_conversations','ai_conversation_expiry_idx','expires_at',false],
      ['ai_agent_memories','ai_memory_company_type_idx','company_id,memory_type,status,confidence_bps',false],
      ['ai_agent_memories','ai_memory_company_key_idx','company_id,normalized_key(64),scope_type,scope_key(32)',false],
      ['ai_agent_memories','ai_memory_user_idx','company_id,user_id,memory_type,status',false],
      ['ai_agent_memory_history','ai_memory_history_memory_idx','memory_id,created_at',false],['ai_agent_memory_history','ai_memory_history_company_idx','company_id,created_at',false],
      ['ai_agent_plans','ai_plan_company_user_idx','company_id,user_id,status,updated_at',false],['ai_agent_plans','ai_plan_conversation_idx','conversation_id,created_at',false],['ai_agent_plans','ai_plan_expiry_idx','expires_at',false],
    ];
    foreach($indexSpecs as [$table,$index,$columns,$unique])if(schema_table_exists($table))schema_add_index($table,$index,$columns,$unique);

    $after=ai_additive_schema_status();$stamp=gmdate('c');
    try{
        if(schema_table_exists('app_meta')){
            $value=json_encode(['at'=>$stamp,'actorUserId'=>$actor['id']??null,'ready'=>$after['ready'],'before'=>$before,'after'=>$after],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            db()->prepare("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v34_ai_storage_repair_last',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute([$value]);
        }
    }catch(Throwable $logError){error_log('Tegh AI storage repair metadata log failed: '.$logError->getMessage());}
    error_log('Tegh Schema 34 AI storage repair '.($after['ready']?'completed':'incomplete').' at '.$stamp);
    return ['before'=>$before,'after'=>$after,'schemaVersion'=>current_database_schema_version(),'accountingDataAffected'=>false];
}

function ensure_v18_system_incident_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS platform_incident_log (
      id VARCHAR(64) PRIMARY KEY,
      occurred_at DATETIME(6) NOT NULL,
      severity ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
      source VARCHAR(40) NOT NULL,
      route VARCHAR(180) NOT NULL,
      method VARCHAR(12) NOT NULL,
      http_status INT NULL,
      error_code VARCHAR(120) NOT NULL,
      public_message VARCHAR(1000) NOT NULL,
      internal_message LONGTEXT NOT NULL,
      exception_class VARCHAR(200) NULL,
      exception_file VARCHAR(1000) NULL,
      exception_line INT NULL,
      stack_trace LONGTEXT NULL,
      request_id VARCHAR(80) NOT NULL,
      user_id VARCHAR(64) NULL,
      user_email VARCHAR(254) NULL,
      company_id VARCHAR(64) NULL,
      ip_hash CHAR(64) NOT NULL DEFAULT '',
      user_agent_hash CHAR(64) NOT NULL DEFAULT '',
      context_json LONGTEXT NOT NULL,
      created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
      KEY platform_incident_occurred_idx (occurred_at),
      KEY platform_incident_severity_idx (severity,occurred_at),
      KEY platform_incident_route_idx (route,occurred_at),
      KEY platform_incident_request_idx (request_id),
      KEY platform_incident_user_idx (user_id,occurred_at),
      KEY platform_incident_company_idx (company_id,occurred_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensure_fx_accounts(): void
{
    if (!schema_table_exists('companies') || !schema_table_exists('accounts')) return;
    $companies = db()->query('SELECT id FROM companies')->fetchAll();
    $exists = db()->prepare("SELECT COUNT(*) FROM accounts WHERE company_id = ? AND code = '6850'");
    $insert = db()->prepare("INSERT INTO accounts (id, company_id, code, name, account_type, normal_balance, is_control)
        VALUES (?, ?, '6850', 'Foreign Exchange Gain / Loss', 'expense', 'debit', 0)");
    foreach ($companies as $company) {
        $companyId = (string)$company['id'];
        $exists->execute([$companyId]);
        if ((int)$exists->fetchColumn() === 0) $insert->execute([new_id('account'), $companyId]);
    }
}

function ensure_default_invoice_templates(): void
{
    if (!schema_table_exists('invoice_templates')) return;
    $companies = db()->query('SELECT id, province FROM companies')->fetchAll();
    $presets = [
        ['Tegh Standard', 'classic', '#0D6B57', 'INVOICE', 'Thank you for your business.'],
        ['Tegh Modern', 'modern', '#1F5EFF', 'INVOICE', 'Thank you. We appreciate your business.'],
        ['Tegh Minimal', 'minimal', '#111827', 'INVOICE', 'Thank you for your business.'],
        ['Tegh Professional', 'professional', '#0B4F6C', 'TAX INVOICE', 'We appreciate your prompt payment.'],
        ['Tegh Compact', 'compact', '#6B4EFF', 'INVOICE', 'Thank you.'],
    ];
    $defaultCount = db()->prepare('SELECT COUNT(*) FROM invoice_templates WHERE company_id = ? AND is_default = 1 AND active = 1');
    $find = db()->prepare('SELECT id FROM invoice_templates WHERE company_id = ? AND name = ? LIMIT 1');
    // Older installations can still carry the pre-Tegh "SR Books Standard"
    // template. Normalize it without losing a customized logo/address/footer.
    // If Tegh Standard was already seeded by a prior build, a legacy default is
    // merged into Tegh Standard and then deactivated; historical invoices keep
    // their frozen snapshots and template foreign key.
    $legacyFind = db()->prepare("SELECT * FROM invoice_templates WHERE company_id = ? AND name = 'SR Books Standard' LIMIT 1");
    $teghFind = db()->prepare("SELECT * FROM invoice_templates WHERE company_id = ? AND name = 'Tegh Standard' LIMIT 1");
    $mergeLegacy = db()->prepare("UPDATE invoice_templates SET document_title=?,accent_color=?,layout_style=?,font_family=?,business_name_override=?,tax_number_override=?,logo_data=?,business_address=?,business_email=?,business_phone=?,payment_instructions=?,footer=?,show_tax_number=?,show_payment_instructions=?,is_default=1 WHERE id=? AND company_id=?");
    $insert = db()->prepare("INSERT INTO invoice_templates
        (id, company_id, name, is_default, document_title, accent_color, layout_style, business_address, payment_instructions, footer, show_tax_number, show_payment_instructions)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Payment is due by the date shown above.', ?, 1, 1)");
    foreach ($companies as $company) {
        $companyId = (string)$company['id'];
        $legacyFind->execute([$companyId]);
        $legacy = $legacyFind->fetch();
        if ($legacy) {
            $teghFind->execute([$companyId]);
            $tegh = $teghFind->fetch();
            if (!$tegh) {
                db()->prepare("UPDATE invoice_templates SET name='Tegh Standard' WHERE id=? AND company_id=?")
                    ->execute([(string)$legacy['id'],$companyId]);
            } elseif ((bool)$legacy['is_default']) {
                db()->prepare('UPDATE invoice_templates SET is_default=0 WHERE company_id=?')->execute([$companyId]);
                $mergeLegacy->execute([
                    (string)$legacy['document_title'],(string)$legacy['accent_color'],(string)($legacy['layout_style']??'classic'),(string)($legacy['font_family']??'Arial'),
                    $legacy['business_name_override']??null,$legacy['tax_number_override']??null,$legacy['logo_data']??null,$legacy['business_address']??null,
                    $legacy['business_email']??null,$legacy['business_phone']??null,$legacy['payment_instructions']??null,$legacy['footer']??null,
                    (int)($legacy['show_tax_number']??1),(int)($legacy['show_payment_instructions']??1),(string)$tegh['id'],$companyId,
                ]);
                db()->prepare('UPDATE invoice_templates SET active=0,is_default=0 WHERE id=? AND company_id=?')->execute([(string)$legacy['id'],$companyId]);
            } else {
                $previousName='Previous Invoice Template '.substr((string)$legacy['id'],-6);
                db()->prepare('UPDATE invoice_templates SET name=? WHERE id=? AND company_id=?')->execute([$previousName,(string)$legacy['id'],$companyId]);
            }
        }
        $defaultCount->execute([$companyId]);
        $needsDefault = (int)$defaultCount->fetchColumn() === 0;
        $address = (string)$company['province'] . ', Canada';
        foreach ($presets as $index => [$name,$style,$accent,$title,$footer]) {
            $find->execute([$companyId,$name]);
            if ($find->fetchColumn() !== false) continue;
            try {
                $insert->execute([new_id('invtemplate'),$companyId,$name,($needsDefault && $index===0)?1:0,$title,$accent,$style,$address,$footer]);
                if ($needsDefault && $index===0) $needsDefault=false;
            } catch (PDOException $error) {
                if ((string)$error->getCode() !== '23000') throw $error;
            }
        }
    }
}

function ensure_v22_invoice_template_and_payroll_match_schema(): void
{
    if (schema_table_exists('invoice_templates')) {
        schema_add_column('invoice_templates','layout_style',"VARCHAR(30) NOT NULL DEFAULT 'classic' AFTER `accent_color`");
        schema_add_column('invoice_templates','business_name_override','VARCHAR(160) NULL AFTER `layout_style`');
        schema_add_column('invoice_templates','tax_number_override','VARCHAR(40) NULL AFTER `business_name_override`');
        schema_add_column('invoice_templates','logo_data','MEDIUMTEXT NULL AFTER `tax_number_override`');
    }
    if (schema_table_exists('payroll_remittances')) {
        // Schema 21 required a journal for every remittance. Schema 22 also
        // supports payroll-record-only mode, so the journal relationship must
        // be nullable on upgraded databases as it is on a fresh schema.
        if (schema_column_exists('payroll_remittances','journal_entry_id') && !schema_column_nullable('payroll_remittances','journal_entry_id')) {
            db()->exec('ALTER TABLE payroll_remittances MODIFY journal_entry_id VARCHAR(64) NULL');
        }
        schema_add_column('payroll_remittances','bank_transaction_id','VARCHAR(64) NULL AFTER `bank_account_id`');
        if (!schema_index_exists('payroll_remittances','payroll_remittances_bank_transaction_uq')) {
            db()->exec('ALTER TABLE payroll_remittances ADD UNIQUE KEY payroll_remittances_bank_transaction_uq (bank_transaction_id)');
        }
        if (!schema_constraint_exists('payroll_remittances','payroll_remittances_bank_transaction_fk')) {
            db()->exec('ALTER TABLE payroll_remittances ADD CONSTRAINT payroll_remittances_bank_transaction_fk FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL');
        }
    }
}

function ensure_v23_invoice_template_font_schema(): void
{
    if (!schema_table_exists('invoice_templates')) return;
    schema_add_column('invoice_templates','font_family',"VARCHAR(40) NOT NULL DEFAULT 'Arial' AFTER `layout_style`");
}

function ensure_v24_bill_product_service_schema(): void
{
    if (!schema_table_exists('bills') || !schema_table_exists('products_services')) return;
    schema_add_column('bills','product_service_id','VARCHAR(64) NULL AFTER `vendor_id`');
    if (!schema_index_exists('bills','bills_product_service_idx')) {
        db()->exec('ALTER TABLE bills ADD KEY bills_product_service_idx (company_id, product_service_id)');
    }
    if (!schema_constraint_exists('bills','bills_product_service_fk')) {
        db()->exec('ALTER TABLE bills ADD CONSTRAINT bills_product_service_fk FOREIGN KEY (product_service_id) REFERENCES products_services(id) ON DELETE SET NULL');
    }
}


function ensure_v25_bill_quantity_schema(): void
{
    if (!schema_table_exists('bills')) return;
    schema_add_column('bills','quantity_milli','BIGINT NOT NULL DEFAULT 1000 AFTER `product_service_id`');
    db()->exec('UPDATE bills SET quantity_milli=1000 WHERE quantity_milli IS NULL OR quantity_milli<=0');
}

function ensure_v26_expense_tax_schema(): void
{
    if (!schema_table_exists('expenses')) return;
    schema_add_column('expenses','gst_hst_cents','BIGINT NOT NULL DEFAULT 0 AFTER `subtotal_cents`');
    schema_add_column('expenses','pst_cents','BIGINT NOT NULL DEFAULT 0 AFTER `gst_hst_cents`');
    schema_add_column('expenses','tax_entry_mode',"ENUM('none','exclusive','inclusive') NOT NULL DEFAULT 'none' AFTER `tax_cents`");
    db()->exec("UPDATE expenses SET gst_hst_cents=tax_cents, pst_cents=0, tax_entry_mode=IF(tax_cents>0,'exclusive','none') WHERE gst_hst_cents=0 AND pst_cents=0");
}

function ensure_v27_controls_currency_ai_schema(): void
{
    if (schema_table_exists('expenses')) {
        schema_add_column('expenses','currency',"CHAR(3) NOT NULL DEFAULT 'CAD' AFTER `paid_from_account_id`");
        schema_add_column('expenses','exchange_rate_micros','BIGINT NOT NULL DEFAULT 1000000 AFTER `currency`');
        schema_add_column('expenses','foreign_subtotal_cents','BIGINT NOT NULL DEFAULT 0 AFTER `exchange_rate_micros`');
        schema_add_column('expenses','foreign_gst_hst_cents','BIGINT NOT NULL DEFAULT 0 AFTER `foreign_subtotal_cents`');
        schema_add_column('expenses','foreign_pst_cents','BIGINT NOT NULL DEFAULT 0 AFTER `foreign_gst_hst_cents`');
        schema_add_column('expenses','foreign_tax_cents','BIGINT NOT NULL DEFAULT 0 AFTER `foreign_pst_cents`');
        schema_add_column('expenses','foreign_total_cents','BIGINT NOT NULL DEFAULT 0 AFTER `foreign_tax_cents`');
        db()->exec("UPDATE expenses e JOIN companies c ON c.id=e.company_id SET e.currency=c.currency,e.exchange_rate_micros=1000000,e.foreign_subtotal_cents=e.subtotal_cents,e.foreign_gst_hst_cents=e.gst_hst_cents,e.foreign_pst_cents=e.pst_cents,e.foreign_tax_cents=e.tax_cents,e.foreign_total_cents=e.total_cents WHERE e.foreign_total_cents=0");
    }
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_action_authorizations (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      user_id VARCHAR(64) NOT NULL,
      action_type VARCHAR(60) NOT NULL,
      payload_json LONGTEXT NOT NULL,
      payload_hash CHAR(64) NOT NULL,
      status ENUM('pending','authorized','completed','expired','cancelled') NOT NULL DEFAULT 'pending',
      expires_at DATETIME NOT NULL,
      authorized_at DATETIME NULL,
      completed_at DATETIME NULL,
      journal_entry_id VARCHAR(64) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ai_action_company_user_idx (company_id,user_id,created_at),
      KEY ai_action_status_idx (status,expires_at),
      CONSTRAINT ai_action_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_action_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_preferences (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      user_id VARCHAR(64) NOT NULL,
      preference_key VARCHAR(120) NOT NULL,
      preference_json LONGTEXT NOT NULL,
      source VARCHAR(40) NOT NULL DEFAULT 'user_approved',
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY ai_pref_company_user_key_uq (company_id,user_id,preference_key),
      CONSTRAINT ai_pref_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_pref_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensure_advanced_accounts(): void
{
    if (!schema_table_exists('companies') || !schema_table_exists('accounts')) return;
    $companies = db()->query('SELECT id FROM companies')->fetchAll();
    $specs = [
        ['6810', 'Depreciation Expense', 'expense', 'debit', 0],
        ['2350', 'Employer Payroll Levy Payable', 'liability', 'credit', 1],
        ['7020', 'Employer Payroll Levy Expense', 'expense', 'debit', 0],
    ];
    $exists = db()->prepare('SELECT COUNT(*) FROM accounts WHERE company_id = ? AND code = ?');
    $insert = db()->prepare('INSERT INTO accounts (id, company_id, code, name, account_type, normal_balance, is_control) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($companies as $company) {
        $companyId = (string)$company['id'];
        foreach ($specs as [$code, $name, $type, $normal, $control]) {
            $exists->execute([$companyId, $code]);
            if ((int)$exists->fetchColumn() === 0) {
                $insert->execute([new_id('account'), $companyId, $code, $name, $type, $normal, $control]);
            }
        }
    }
}

function backfill_invoice_v4_snapshots(): void
{
    if (!schema_column_exists('invoices', 'template_snapshot_json')) return;
    $stmt = db()->query("SELECT i.id, i.template_id, i.template_snapshot_json, i.customer_snapshot_json,
        c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
        c.billing_address AS customer_address, c.province AS customer_province,
        co.name AS company_name, co.legal_name, co.province AS company_province, co.tax_number,
        t.name AS template_name, t.document_title, t.accent_color, t.business_address,
        t.business_email, t.business_phone, t.payment_instructions, t.footer,
        t.show_tax_number, t.show_payment_instructions
        FROM invoices i
        JOIN customers c ON c.id = i.customer_id
        JOIN companies co ON co.id = i.company_id
        LEFT JOIN invoice_templates t ON t.id = i.template_id
        WHERE i.template_snapshot_json IS NULL OR i.customer_snapshot_json IS NULL");
    $update = db()->prepare('UPDATE invoices SET template_snapshot_json = COALESCE(template_snapshot_json, ?),
        customer_snapshot_json = COALESCE(customer_snapshot_json, ?) WHERE id = ?');
    foreach ($stmt->fetchAll() as $row) {
        $template = [
            'id' => $row['template_id'] !== null ? (string)$row['template_id'] : null,
            'name' => (string)($row['template_name'] ?? 'Tegh Standard'),
            'documentTitle' => (string)($row['document_title'] ?? 'INVOICE'),
            'accentColor' => (string)($row['accent_color'] ?? '#C94F2D'),
            'businessName' => (string)$row['company_name'],
            'legalName' => (string)$row['legal_name'],
            'businessAddress' => (string)($row['business_address'] ?? ((string)$row['company_province'] . ', Canada')),
            'businessEmail' => $row['business_email'] !== null ? (string)$row['business_email'] : null,
            'businessPhone' => $row['business_phone'] !== null ? (string)$row['business_phone'] : null,
            'taxNumber' => $row['tax_number'] !== null ? (string)$row['tax_number'] : null,
            'paymentInstructions' => $row['payment_instructions'] !== null ? (string)$row['payment_instructions'] : null,
            'footer' => $row['footer'] !== null ? (string)$row['footer'] : null,
            'showTaxNumber' => (bool)($row['show_tax_number'] ?? true),
            'showPaymentInstructions' => (bool)($row['show_payment_instructions'] ?? true),
        ];
        $customer = [
            'name' => (string)$row['customer_name'],
            'email' => $row['customer_email'] !== null ? (string)$row['customer_email'] : null,
            'phone' => $row['customer_phone'] !== null ? (string)$row['customer_phone'] : null,
            'billingAddress' => $row['customer_address'] !== null ? (string)$row['customer_address'] : null,
            'province' => $row['customer_province'] !== null ? (string)$row['customer_province'] : null,
        ];
        $update->execute([
            json_encode($template, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($customer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            (string)$row['id'],
        ]);
    }
}


function ensure_v6_seed_data(?string $onlyCompanyId = null): void
{
    if (!schema_table_exists('companies')) return;
    if ($onlyCompanyId === null) {
        // Full-install migration path: existing companies may still require the
        // advanced accounts and legacy period-lock conversion.
        ensure_advanced_accounts();
        if (schema_table_exists('period_locks') && schema_table_exists('accounting_controls')) {
            $legacy = db()->query("SELECT ac.company_id, ac.closed_through_date,
                (SELECT cm.user_id FROM company_members cm WHERE cm.company_id=ac.company_id AND cm.role='owner' ORDER BY cm.created_at LIMIT 1) AS owner_id
                FROM accounting_controls ac WHERE ac.closed_through_date IS NOT NULL")->fetchAll();
            $insertLegacy = db()->prepare("INSERT INTO period_locks (id,company_id,period_start,period_end,locked,reason,updated_by)
                VALUES (?,?,'1900-01-01',?,1,'Carried forward from the previous period control',?)
                ON DUPLICATE KEY UPDATE locked=VALUES(locked),reason=VALUES(reason),updated_by=VALUES(updated_by)");
            foreach ($legacy as $row) {
                if ($row['owner_id'] !== null) $insertLegacy->execute([new_id('periodlock'),$row['company_id'],$row['closed_through_date'],$row['owner_id']]);
            }
            db()->exec('UPDATE accounting_controls SET closed_through_date = NULL WHERE closed_through_date IS NOT NULL');
        }
        $companies = db()->query('SELECT id, province, business_type FROM companies')->fetchAll();
    } else {
        // New-company path: do not rescan/reseed every tenant while the company
        // creation transaction is open. The default chart already contains the
        // v6 control accounts, so only this company's levy/rate rows are needed.
        $companyStmt = db()->prepare('SELECT id, province, business_type FROM companies WHERE id = ? LIMIT 1');
        $companyStmt->execute([$onlyCompanyId]);
        $companies = $companyStmt->fetchAll();
    }
    $profileInsert = db()->prepare("INSERT INTO employer_levy_profiles
        (company_id, jurisdiction, applicability, posting_mode, eligible_for_exemption, expense_account_id, payable_account_id, updated_by)
        SELECT ?, ?, ?, 'report_only', 1,
          (SELECT id FROM accounts WHERE company_id=? AND code='7020' LIMIT 1),
          (SELECT id FROM accounts WHERE company_id=? AND code='2350' LIMIT 1),
          cm.user_id FROM company_members cm
        WHERE cm.company_id = ? AND cm.role = 'owner' ORDER BY cm.created_at LIMIT 1
        ON DUPLICATE KEY UPDATE jurisdiction=VALUES(jurisdiction),
          expense_account_id=COALESCE(employer_levy_profiles.expense_account_id,VALUES(expense_account_id)),
          payable_account_id=COALESCE(employer_levy_profiles.payable_account_id,VALUES(payable_account_id))");
    $rateExists = db()->prepare('SELECT COUNT(*) FROM employer_levy_rates WHERE company_id = ? AND jurisdiction = ? AND effective_from = ?');
    $rateInsert = db()->prepare('INSERT INTO employer_levy_rates
        (id, company_id, jurisdiction, label, effective_from, payroll_min_cents, payroll_max_cents, exemption_cents, exemption_threshold_cents, rate_bps, rate_decimal, source_url, active, created_by)
        SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, cm.user_id FROM company_members cm
        WHERE cm.company_id = ? AND cm.role = \'owner\' ORDER BY cm.created_at LIMIT 1');
    $statExists = db()->prepare('SELECT COUNT(*) FROM statutory_rates WHERE company_id = ? AND rate_key = ? AND effective_from = ?');
    $statInsert = db()->prepare('INSERT INTO statutory_rates
        (id, company_id, jurisdiction, person_type, rate_key, label, effective_from, value_decimal, value_cents, metadata_json, source_url, source_label, status, created_by, approved_by)
        SELECT ?, ?, ?, \'employee\', ?, ?, ?, ?, ?, \'{}\', ?, ?, \'active\', cm.user_id, cm.user_id FROM company_members cm
        WHERE cm.company_id = ? AND cm.role = \'owner\' ORDER BY cm.created_at LIMIT 1');
    foreach ($companies as $company) {
        $companyId = (string)$company['id'];
        $province = (string)$company['province'];
        $applicability = $province === 'ON' ? 'jurisdiction_default' : 'not_applicable';
        $profileInsert->execute([$companyId, $province, $applicability, $companyId, $companyId, $companyId]);
        if ($province === 'ON') {
            $bands = [
                [0,20000000,98,0.0098],[20000001,23000000,110,0.01101],[23000001,26000000,122,0.01223],
                [26000001,29000000,134,0.01344],[29000001,32000000,147,0.01465],[32000001,35000000,159,0.01586],
                [35000001,38000000,171,0.01708],[38000001,40000000,183,0.01829],[40000001,null,195,0.0195],
            ];
            $rateExists->execute([$companyId, 'ON', '2026-01-01']);
            if ((int)$rateExists->fetchColumn() === 0) {
                foreach ($bands as [$min,$max,$bps,$decimal]) {
                    $rateInsert->execute([new_id('levyrate'),$companyId,'ON','Ontario employer health tax','2026-01-01',$min,$max,100000000,500000000,$bps,$decimal,
                        'https://www.ontario.ca/document/employer-health-tax-eht',$companyId]);
                }
            }
        }
        $defaults = [
            ['CA','payroll.releaseYear','Approved payroll rate release year','2026-01-01',2026.0,null],
            ['CA','payroll.releaseComplete','Approved payroll rate release completeness marker','2026-01-01',1.0,null],
            ['CA','cpp.rate','CPP employee contribution rate','2026-01-01',0.0595,null],
            ['CA','cpp.baseRate','CPP base contribution rate','2026-01-01',0.0495,null],
            ['CA','cpp.ympe','CPP maximum pensionable earnings','2026-01-01',null,7460000],
            ['CA','cpp.yampe','CPP second earnings ceiling','2026-01-01',null,8500000],
            ['CA','cpp.basicExemption','CPP annual basic exemption','2026-01-01',null,350000],
            ['CA','cpp.max','CPP maximum employee contribution','2026-01-01',null,423045],
            ['CA','cpp.cpp2Rate','CPP second contribution rate','2026-01-01',0.04,null],
            ['CA','cpp.cpp2Max','CPP second maximum contribution','2026-01-01',null,41600],
            ['CA','ei.employeeRate','EI employee premium rate','2026-01-01',0.0163,null],
            ['CA','ei.employeeMax','EI maximum employee premium','2026-01-01',null,112307],
            ['CA','ei.employerRate','EI employer premium rate','2026-01-01',0.02282,null],
            ['CA','ei.mie','EI maximum insurable earnings','2026-01-01',null,6890000],
        ];
        foreach ($defaults as [$jur,$key,$label,$date,$decimal,$cents]) {
            $statExists->execute([$companyId,$key,$date]);
            if ((int)$statExists->fetchColumn() === 0) {
                $statInsert->execute([new_id('rate'),$companyId,$jur,$key,$label,$date,$decimal,$cents,
                    'https://www.canada.ca/en/revenue-agency/services/forms-publications/payroll/t4127-payroll-deductions-formulas.html',
                    'Official payroll deductions formulas',$companyId]);
            }
        }
    }
}


function backfill_audit_hash_chain(): void
{
    if (!schema_table_exists('audit_log') || !schema_column_exists('audit_log','entry_hash')) return;
    $companies = db()->query("SELECT DISTINCT company_id FROM audit_log ORDER BY company_id")->fetchAll();
    $select = db()->prepare('SELECT id,actor_user_id,actor_email,action,entity_type,entity_id,metadata_json,request_id,created_at FROM audit_log WHERE company_id=? ORDER BY created_at,id');
    $update = db()->prepare('UPDATE audit_log SET previous_hash=?,entry_hash=?,request_id=CASE WHEN request_id=\'\' THEN ? ELSE request_id END WHERE id=?');
    foreach ($companies as $company) {
        $companyId=(string)$company['company_id'];$previous='';$select->execute([$companyId]);
        foreach($select->fetchAll() as $row){$rid=(string)($row['request_id']?:'legacy');$created=(string)$row['created_at'];$entry=hash('sha256',implode('|',[$companyId,$previous,(string)$row['id'],(string)$row['actor_user_id'],(string)$row['actor_email'],(string)$row['action'],(string)$row['entity_type'],(string)$row['entity_id'],(string)$row['metadata_json'],$rid,$created]));$update->execute([$previous,$entry,$rid,(string)$row['id']]);$previous=$entry;}
    }
}

function seed_voucher_sequence_rows(): void
{
    if (!schema_table_exists('voucher_sequences') || !schema_table_exists('companies')) return;
    db()->exec("INSERT INTO voucher_sequences (company_id,next_serial) SELECT id,1 FROM companies ON DUPLICATE KEY UPDATE company_id=VALUES(company_id)");
}


function backfill_existing_vouchers(): void
{
    if (!schema_table_exists('vouchers') || !schema_table_exists('voucher_sequences') || !function_exists('voucher_register_saved')) return;
    $companies=db()->query('SELECT id FROM companies ORDER BY created_at,id')->fetchAll();
    foreach($companies as $company){
        $companyId=(string)$company['id'];
        $owner=db()->prepare("SELECT u.id,u.email FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? ORDER BY (cm.role='owner') DESC,cm.created_at LIMIT 1");
        $owner->execute([$companyId]);$user=$owner->fetch();if(!$user)continue;
        $items=[];
        $queries=[
            ['invoice','AR','AR',"SELECT id,issue_date d,created_at,CONCAT('Customer invoice ',number) descr,total_cents amount,status,issued_journal_entry_id journal_id FROM invoices WHERE company_id=?"],
            ['bill','AP','AP',"SELECT id,bill_date d,created_at,CONCAT('Vendor bill ',number) descr,total_cents amount,status,issued_journal_entry_id journal_id FROM bills WHERE company_id=?"],
            ['expense','EX','EX',"SELECT id,expense_date d,created_at,CONCAT('Expense · ',vendor) descr,total_cents amount,'posted' status,journal_entry_id journal_id FROM expenses WHERE company_id=?"],
            ['bank_transaction','BS','BS',"SELECT id,transaction_date d,created_at,CONCAT('Bank statement · ',description) descr,ABS(amount_cents) amount,status,journal_entry_id journal_id FROM bank_transactions WHERE company_id=? AND status<>'duplicate'"],
            ['payroll_run','PL','PL',"SELECT id,pay_date d,created_at,CONCAT('Payroll through ',period_end) descr,gross_pay_cents amount,status,accrual_journal_entry_id journal_id FROM payroll_runs WHERE company_id=?"],
            ['manual_journal','GL','GL',"SELECT je.source_id id,je.entry_date d,je.created_at,CONCAT('Manual journal · ',je.memo) descr,COALESCE(SUM(jl.debit_cents),0) amount,je.status,je.id journal_id FROM journal_entries je LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id=? AND je.source_type='manual_journal' GROUP BY je.id,je.source_id,je.entry_date,je.created_at,je.memo,je.status"],
        ];
        if (schema_table_exists('party_payments')) {
            $queries[]=['customer_payment','AR','AR',"SELECT id,payment_date d,created_at,CONCAT('Customer payment · ',reference) descr,amount_cents amount,status,journal_entry_id journal_id FROM party_payments WHERE company_id=? AND payment_type='customer'"];
            $queries[]=['vendor_payment','AP','AP',"SELECT id,payment_date d,created_at,CONCAT('Vendor payment · ',reference) descr,amount_cents amount,status,journal_entry_id journal_id FROM party_payments WHERE company_id=? AND payment_type='vendor'"];
        }
        foreach($queries as [$source,$prefix,$module,$sql]){$q=db()->prepare($sql);$q->execute([$companyId]);foreach($q->fetchAll() as $row){$row['_source']=$source;$row['_prefix']=$prefix;$row['_module']=$module;$items[]=$row;}}
        usort($items,static function(array $a,array $b):int{return [(string)$a['d'],(string)$a['created_at'],(string)$a['_source'],(string)$a['id']] <=> [(string)$b['d'],(string)$b['created_at'],(string)$b['_source'],(string)$b['id']];});
        db()->beginTransaction();
        try{
            foreach($items as $row){
                $status=(string)$row['status'];
                $posted=match((string)$row['_source']){
                    'invoice'=>$status!=='draft'&&$status!=='void',
                    'bill'=>$status!=='draft',
                    'bank_transaction'=>$status==='posted',
                    'payroll_run'=>in_array($status,['posted','paid'],true),
                    'manual_journal'=>$status==='posted',
                    default=>true,
                };
                voucher_register_saved($user,$companyId,(string)$row['_prefix'],(string)$row['_module'],(string)$row['_source'],(string)$row['id'],(string)$row['d'],(string)$row['descr'],abs((int)$row['amount']),$row['journal_id']!==null?(string)$row['journal_id']:null,$posted);
                if(in_array($status,['void','reversed','excluded'],true))voucher_mark_void($user,$companyId,(string)$row['_source'],(string)$row['id']);
            }
            db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    }
}

/**
 * Schema 19 is an accounting-integrity upgrade. It adds only additive
 * relationships/metadata and deliberately does not rewrite posted journals,
 * voucher numbers or source documents.
 */
function ensure_v19_accounting_integrity_schema(): void
{
    schema_add_column('companies','pst_registered','TINYINT(1) NOT NULL DEFAULT 0 AFTER `tax_rate_bps`');
    schema_add_column('companies','pst_rate_bps','INT NOT NULL DEFAULT 0 AFTER `pst_registered`');
    schema_add_column('companies','pst_recoverable','TINYINT(1) NOT NULL DEFAULT 0 AFTER `pst_rate_bps`');
    schema_add_column('bank_transactions','reference','VARCHAR(120) NULL AFTER `description`');

    if (schema_table_exists('accounts')) {
        $exists=db()->prepare('SELECT COUNT(*) FROM accounts WHERE company_id=? AND code=?');
        $insert=db()->prepare('INSERT INTO accounts (id,company_id,code,name,account_type,normal_balance,is_control) VALUES (?,?,?,?,?,?,?)');
        foreach(db()->query('SELECT id FROM companies')->fetchAll() as $co){
            foreach([['1110','PST Recoverable','asset','debit',1],['2110','PST Payable','liability','credit',1]] as $spec){
                [$code,$name,$type,$normal,$control]=$spec;$exists->execute([(string)$co['id'],$code]);
                if((int)$exists->fetchColumn()===0)$insert->execute([new_id('account'),(string)$co['id'],$code,$name,$type,$normal,$control]);
            }
        }
    }

    schema_add_column('bills','gst_hst_cents','BIGINT NOT NULL DEFAULT 0 AFTER `subtotal_cents`');
    schema_add_column('bills','pst_cents','BIGINT NOT NULL DEFAULT 0 AFTER `gst_hst_cents`');
    schema_add_column('bills','tax_entry_mode',"ENUM('none','exclusive','inclusive') NOT NULL DEFAULT 'none' AFTER `tax_cents`");
    schema_add_column('bills','foreign_gst_hst_cents','BIGINT NOT NULL DEFAULT 0 AFTER `foreign_subtotal_cents`');
    schema_add_column('bills','foreign_pst_cents','BIGINT NOT NULL DEFAULT 0 AFTER `foreign_gst_hst_cents`');
    // Preserve historical totals exactly. Existing tax was the application's
    // GST/HST bucket; it is classified, not recalculated.
    db()->exec("UPDATE bills SET gst_hst_cents=tax_cents, foreign_gst_hst_cents=foreign_tax_cents, tax_entry_mode=IF(tax_cents>0,'exclusive','none') WHERE pst_cents=0 AND foreign_pst_cents=0 AND gst_hst_cents=0 AND foreign_gst_hst_cents=0");

    if (schema_table_exists('payroll_runs')) {
        db()->exec("ALTER TABLE payroll_runs MODIFY status ENUM('draft','verified','posted','paid','reversed') NOT NULL DEFAULT 'draft'");
        schema_add_column('payroll_runs','gl_status',"ENUM('not_ready','ready_to_post','posted','not_applicable','reversed') NOT NULL DEFAULT 'not_ready' AFTER `status`");
        schema_add_column('payroll_runs','gl_posted_by','VARCHAR(64) NULL AFTER `approved_at`');
        schema_add_column('payroll_runs','gl_posted_at','DATETIME NULL AFTER `gl_posted_by`');
        if (!schema_constraint_exists('payroll_runs','payroll_runs_gl_posted_user_fk')) {
            db()->exec('ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_gl_posted_user_fk FOREIGN KEY (gl_posted_by) REFERENCES users(id) ON DELETE SET NULL');
        }
        // Classify legacy runs from immutable posting evidence. No journal or
        // source-document amounts are changed by this backfill.
        db()->exec("UPDATE payroll_runs pr
            LEFT JOIN payroll_journal_drafts pjd ON pjd.payroll_run_id=pr.id
            SET pr.gl_status=CASE
                WHEN pr.status='reversed' THEN 'reversed'
                WHEN pr.accrual_journal_entry_id IS NOT NULL THEN 'posted'
                WHEN pjd.status='draft' THEN 'ready_to_post'
                WHEN pr.status='draft' THEN 'not_ready'
                ELSE 'not_applicable'
            END,
            pr.gl_posted_by=CASE WHEN pr.accrual_journal_entry_id IS NOT NULL THEN pr.approved_by ELSE NULL END,
            pr.gl_posted_at=CASE WHEN pr.accrual_journal_entry_id IS NOT NULL THEN pr.approved_at ELSE NULL END");
        db()->exec("UPDATE payroll_runs SET status='verified' WHERE status='posted' AND gl_status='ready_to_post' AND accrual_journal_entry_id IS NULL");
    }

    db()->exec("CREATE TABLE IF NOT EXISTS transaction_sequences (
      company_id VARCHAR(64) NOT NULL, prefix VARCHAR(4) NOT NULL,
      next_serial BIGINT UNSIGNED NOT NULL DEFAULT 1,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (company_id,prefix),
      CONSTRAINT transaction_sequences_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT transaction_sequences_next_ck CHECK (next_serial > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed next module numbers above any existing matching voucher numbers.
    // This never renumbers or mutates historical vouchers.
    db()->exec("INSERT INTO transaction_sequences (company_id,prefix,next_serial)
      SELECT company_id,prefix,COALESCE(MAX(CASE WHEN voucher_number REGEXP '^[A-Z]{2,4}-[0-9]+$' THEN CAST(SUBSTRING_INDEX(voucher_number,'-',-1) AS UNSIGNED) ELSE 0 END),0)+1
      FROM vouchers WHERE prefix REGEXP '^[A-Z]{2,4}$' GROUP BY company_id,prefix
      ON DUPLICATE KEY UPDATE next_serial=GREATEST(next_serial,VALUES(next_serial))");

    db()->exec("CREATE TABLE IF NOT EXISTS bank_match_groups (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, bank_account_id VARCHAR(64) NOT NULL,
      status ENUM('matched','unreconciled') NOT NULL DEFAULT 'matched',
      bank_amount_cents BIGINT NOT NULL, book_amount_cents BIGINT NOT NULL,
      adjustment_journal_entry_id VARCHAR(64) NULL, adjustment_reversal_journal_entry_id VARCHAR(64) NULL, matched_by VARCHAR(64) NOT NULL, matched_at DATETIME NOT NULL,
      unreconciled_by VARCHAR(64) NULL, unreconciled_at DATETIME NULL, unreconcile_reason VARCHAR(1000) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY bank_match_groups_account_idx (company_id,bank_account_id,status,matched_at),
      CONSTRAINT bank_match_groups_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT bank_match_groups_account_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
      CONSTRAINT bank_match_groups_adjustment_fk FOREIGN KEY (adjustment_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
      CONSTRAINT bank_match_groups_adjustment_reversal_fk FOREIGN KEY (adjustment_reversal_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
      CONSTRAINT bank_match_groups_matched_user_fk FOREIGN KEY (matched_by) REFERENCES users(id),
      CONSTRAINT bank_match_groups_unreconciled_user_fk FOREIGN KEY (unreconciled_by) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT bank_match_groups_balanced_ck CHECK (bank_amount_cents=book_amount_cents)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!schema_column_exists('bank_match_groups','adjustment_reversal_journal_entry_id')) {
        db()->exec("ALTER TABLE bank_match_groups ADD COLUMN adjustment_reversal_journal_entry_id VARCHAR(64) NULL AFTER adjustment_journal_entry_id");
    }
    if (!schema_constraint_exists('bank_match_groups','bank_match_groups_adjustment_reversal_fk')) {
        db()->exec("ALTER TABLE bank_match_groups ADD CONSTRAINT bank_match_groups_adjustment_reversal_fk FOREIGN KEY (adjustment_reversal_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL");
    }

    db()->exec("CREATE TABLE IF NOT EXISTS bank_match_bank_items (
      match_group_id VARCHAR(64) NOT NULL, bank_transaction_id VARCHAR(64) NOT NULL, amount_cents BIGINT NOT NULL,
      PRIMARY KEY(match_group_id,bank_transaction_id), KEY bank_match_bank_items_tx_idx(bank_transaction_id),
      CONSTRAINT bank_match_bank_items_group_fk FOREIGN KEY(match_group_id) REFERENCES bank_match_groups(id) ON DELETE CASCADE,
      CONSTRAINT bank_match_bank_items_tx_fk FOREIGN KEY(bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS bank_match_book_items (
      match_group_id VARCHAR(64) NOT NULL, journal_entry_id VARCHAR(64) NOT NULL, amount_cents BIGINT NOT NULL,
      PRIMARY KEY(match_group_id,journal_entry_id), KEY bank_match_book_items_journal_idx(journal_entry_id),
      CONSTRAINT bank_match_book_items_group_fk FOREIGN KEY(match_group_id) REFERENCES bank_match_groups(id) ON DELETE CASCADE,
      CONSTRAINT bank_match_book_items_journal_fk FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS reconciliation_match_groups (
      reconciliation_id VARCHAR(64) NOT NULL, match_group_id VARCHAR(64) NOT NULL,
      PRIMARY KEY(reconciliation_id,match_group_id),
      CONSTRAINT reconciliation_match_groups_reconciliation_fk FOREIGN KEY(reconciliation_id) REFERENCES reconciliations(id) ON DELETE CASCADE,
      CONSTRAINT reconciliation_match_groups_match_fk FOREIGN KEY(match_group_id) REFERENCES bank_match_groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Schema 20 is a release-security upgrade. Password reset tokens are stored
 * only as hashes, expire quickly and are never exposed through authenticated
 * accounting APIs. This migration is additive and does not touch accounting
 * records, journals or historical sessions.
 */
function ensure_v20_release_audit_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
      id VARCHAR(64) PRIMARY KEY,
      user_id VARCHAR(64) NULL,
      email_hash CHAR(64) NOT NULL,
      token_hash CHAR(64) NOT NULL,
      requested_ip_hash CHAR(64) NOT NULL,
      expires_at DATETIME NOT NULL,
      used_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY password_reset_token_uq (token_hash),
      KEY password_reset_user_created_idx (user_id,created_at),
      KEY password_reset_email_created_idx (email_hash,created_at),
      KEY password_reset_expiry_idx (expires_at,used_at),
      CONSTRAINT password_reset_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Schema 21 records whether an invoice belongs to a recurring workflow.
 * The flags are additive, default to false for historical one-off documents,
 * and do not modify balances, journals, payments, or reconciliation records.
 */
function ensure_v21_recurring_document_flags(): void
{
    schema_add_column('invoices', 'is_recurring', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `issued_journal_entry_id`');
    schema_add_column('bills', 'is_recurring', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `issued_journal_entry_id`');

    // Recurring vendor invoices have always used an RB- number. Preserve that
    // deterministic history when upgrading an existing installation.
    db()->exec("UPDATE bills SET is_recurring = 1 WHERE is_recurring = 0 AND number LIKE 'RB-%'");
}


function ensure_v28_reporting_framework_schema(): void
{
    if (!schema_table_exists('companies')) return;
    schema_add_column('companies','reporting_framework',"ENUM('not_set','aspe','ifrs','other') NOT NULL DEFAULT 'not_set' AFTER `tax_reporting_profile`");
}


function ensure_v29_ai_agent_schema(): void
{
    if (!schema_table_exists('companies') || !schema_table_exists('users')) return;
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_result_sets (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL, kind VARCHAR(40) NOT NULL,
      query_json LONGTEXT NOT NULL, record_ids_json LONGTEXT NOT NULL, selected_ids_json LONGTEXT NOT NULL, status_snapshot_json LONGTEXT NOT NULL,
      expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY ai_result_company_user_idx (company_id,user_id,updated_at), KEY ai_result_expiry_idx (expires_at),
      CONSTRAINT ai_result_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_result_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_tasks (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL, action_id VARCHAR(120) NOT NULL, intent VARCHAR(500) NOT NULL,
      module VARCHAR(80) NOT NULL DEFAULT '', workflow_state VARCHAR(60) NOT NULL DEFAULT 'active', collected_json LONGTEXT NOT NULL, missing_json LONGTEXT NOT NULL,
      result_set_id VARCHAR(64) NULL, pending_confirmation_id VARCHAR(64) NULL,
      status ENUM('active','waiting_input','waiting_confirmation','suspended','cancelled','completed') NOT NULL DEFAULT 'active',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY ai_task_company_user_idx (company_id,user_id,updated_at), KEY ai_task_status_idx (status,updated_at),
      CONSTRAINT ai_task_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_task_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT ai_task_result_fk FOREIGN KEY (result_set_id) REFERENCES ai_agent_result_sets(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensure_v30_master_import_schema(): void
{
    if (!schema_table_exists('customers') || !schema_table_exists('vendors')) return;
    schema_add_column('customers','contact_name','VARCHAR(160) NULL AFTER `name`');
    schema_add_column('customers','address_line1','VARCHAR(180) NULL AFTER `billing_address`');
    schema_add_column('customers','address_line2','VARCHAR(180) NULL AFTER `address_line1`');
    schema_add_column('customers','city','VARCHAR(100) NULL AFTER `address_line2`');
    schema_add_column('customers','postal_code','VARCHAR(20) NULL AFTER `province`');
    schema_add_column('customers','country',"VARCHAR(80) NOT NULL DEFAULT 'Canada' AFTER `postal_code`");
    schema_add_column('customers','default_terms_days','INT NOT NULL DEFAULT 30 AFTER `country`');
    schema_add_column('customers','notes','VARCHAR(2000) NULL AFTER `default_terms_days`');
    schema_add_column('customers','status',"ENUM('active','on_hold','inactive') NOT NULL DEFAULT 'active' AFTER `notes`");
    schema_add_column('customers','hold_remarks','VARCHAR(1000) NULL AFTER `status`');
    schema_add_column('customers','hold_at','DATETIME NULL AFTER `hold_remarks`');
    schema_add_column('customers','hold_by','VARCHAR(64) NULL AFTER `hold_at`');
    schema_add_column('customers','release_remarks','VARCHAR(1000) NULL AFTER `hold_by`');
    schema_add_column('customers','released_at','DATETIME NULL AFTER `release_remarks`');
    schema_add_column('customers','released_by','VARCHAR(64) NULL AFTER `released_at`');
    schema_add_column('customers','updated_at','TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');
    schema_add_index('customers','customers_company_status_idx','company_id,status,name');

    schema_add_column('vendors','contact_name','VARCHAR(160) NULL AFTER `name`');
    schema_add_column('vendors','phone','VARCHAR(60) NULL AFTER `email`');
    schema_add_column('vendors','address_line1','VARCHAR(180) NULL AFTER `address`');
    schema_add_column('vendors','address_line2','VARCHAR(180) NULL AFTER `address_line1`');
    schema_add_column('vendors','city','VARCHAR(100) NULL AFTER `address_line2`');
    schema_add_column('vendors','province','CHAR(2) NULL AFTER `city`');
    schema_add_column('vendors','postal_code','VARCHAR(20) NULL AFTER `province`');
    schema_add_column('vendors','country',"VARCHAR(80) NOT NULL DEFAULT 'Canada' AFTER `postal_code`");
    schema_add_column('vendors','notes','VARCHAR(2000) NULL AFTER `default_currency`');
    schema_add_column('vendors','status',"ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER `notes`");
    schema_add_index('vendors','vendors_company_status_idx','company_id,status,name');
    schema_add_column('invoices','import_reference','VARCHAR(120) NULL AFTER `purchase_order`');
    schema_add_index('invoices','invoices_company_import_ref_uq','company_id,import_reference',true);
    schema_add_column('bills','import_reference','VARCHAR(120) NULL AFTER `memo`');
    schema_add_index('bills','bills_company_import_ref_uq','company_id,import_reference',true);

    db()->exec("UPDATE customers SET status=CASE WHEN active=1 THEN 'active' ELSE 'inactive' END WHERE status IS NULL OR status=''");
    db()->exec("UPDATE vendors SET status=CASE WHEN active=1 THEN 'active' ELSE 'inactive' END WHERE status IS NULL OR status=''");

    db()->exec("CREATE TABLE IF NOT EXISTS party_opening_balances (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      party_type ENUM('customer','vendor') NOT NULL,
      party_id VARCHAR(64) NOT NULL,
      effective_date DATE NOT NULL,
      amount_cents BIGINT NOT NULL,
      offset_account_id VARCHAR(64) NOT NULL,
      journal_entry_id VARCHAR(64) NOT NULL,
      voucher_id VARCHAR(64) NULL,
      created_by VARCHAR(64) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY party_opening_company_party_uq (company_id,party_type,party_id),
      KEY party_opening_company_date_idx (company_id,party_type,effective_date),
      CONSTRAINT party_opening_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT party_opening_offset_fk FOREIGN KEY (offset_account_id) REFERENCES accounts(id),
      CONSTRAINT party_opening_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
      CONSTRAINT party_opening_voucher_fk FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE SET NULL,
      CONSTRAINT party_opening_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS data_import_previews (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      user_id VARCHAR(64) NOT NULL,
      import_type ENUM('customers','vendors','products_services','customer_invoices','vendor_invoices') NOT NULL,
      filename VARCHAR(240) NOT NULL DEFAULT '',
      rows_json LONGTEXT NOT NULL,
      validation_json LONGTEXT NOT NULL,
      status ENUM('validated','committing','imported','failed','cancelled') NOT NULL DEFAULT 'validated',
      expires_at DATETIME NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY data_import_company_user_idx (company_id,user_id,status,created_at),
      KEY data_import_expiry_idx (expires_at),
      CONSTRAINT data_import_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT data_import_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Keep preview commits one-use even if schema 30 was partially installed before this build.
    db()->exec("ALTER TABLE data_import_previews MODIFY status ENUM('validated','committing','imported','failed','cancelled') NOT NULL DEFAULT 'validated'");
}


function ensure_v31_accounting_import_schema(?array $migrationActor = null): void
{
    if (!schema_table_exists('accounts')) return;
    schema_add_column('accounts','description','VARCHAR(500) NULL AFTER `name`');
    db()->exec("CREATE TABLE IF NOT EXISTS company_system_accounts (
      company_id VARCHAR(64) NOT NULL,
      system_key VARCHAR(80) NOT NULL,
      configured_code VARCHAR(20) NOT NULL,
      account_id VARCHAR(64) NULL,
      status ENUM('active','conflict') NOT NULL DEFAULT 'active',
      conflict_message VARCHAR(1000) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (company_id,system_key),
      KEY company_system_account_id_idx (company_id,account_id),
      CONSTRAINT company_system_accounts_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT company_system_accounts_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if(schema_table_exists('data_import_previews')){
        db()->exec("ALTER TABLE data_import_previews MODIFY import_type ENUM('customers','vendors','products_services','customer_invoices','vendor_invoices','chart_of_accounts','opening_balances','coa_opening_balances') NOT NULL");
    }
    $companies=db()->query('SELECT id FROM companies')->fetchAll();
    $lookup=db()->prepare('SELECT id,name,account_type,normal_balance,is_control,active FROM accounts WHERE company_id=? AND code=\'9999\' LIMIT 1');
    $insertAccount=db()->prepare('INSERT INTO accounts (id,company_id,code,name,description,account_type,normal_balance,is_control,active) VALUES (?,?,\'9999\',\'Opening Balance Control\',\'Tegh system control account used only by authorized opening-balance services.\',\'equity\',\'credit\',1,1)');
    $map=db()->prepare("INSERT INTO company_system_accounts (company_id,system_key,configured_code,account_id,status,conflict_message) VALUES (?, 'opening_balance_control','9999',?,?,?) ON DUPLICATE KEY UPDATE configured_code=VALUES(configured_code),account_id=VALUES(account_id),status=VALUES(status),conflict_message=VALUES(conflict_message)");
    foreach($companies as $company){
        $companyId=(string)$company['id'];$lookup->execute([$companyId]);$account=$lookup->fetch();
        if(!$account){$id=new_id('account');$insertAccount->execute([$id,$companyId]);$map->execute([$companyId,$id,'active',null]);continue;}
        $compatible=(string)$account['name']==='Opening Balance Control' && (string)$account['account_type']==='equity' && (string)$account['normal_balance']==='credit' && (bool)$account['is_control'] && (bool)$account['active'];
        if($compatible){$map->execute([$companyId,(string)$account['id'],'active',null]);}
        else{$message='Account code 9999 already exists as an ordinary or incompatible account. Tegh did not overwrite it. Opening-balance commits are blocked until the 9999 conflict is resolved safely.';$map->execute([$companyId,null,'conflict',$message]);if($migrationActor){audit_event($migrationActor,$companyId,'opening_balance_control.conflict','company_system_account','opening_balance_control',['configuredCode'=>'9999','message'=>$message,'migration'=>'30_to_31']);}}
    }
}

function ensure_v32_opening_document_schema(): void
{
    if (!schema_table_exists('invoices') || !schema_table_exists('bills')) return;
    schema_add_column('invoices','is_opening_document','TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_recurring`');
    schema_add_column('invoices','opening_import_id','VARCHAR(64) NULL AFTER `is_opening_document`');
    schema_add_column('invoices','original_paid_cents','BIGINT NOT NULL DEFAULT 0 AFTER `opening_import_id`');
    schema_add_column('bills','is_opening_document','TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_recurring`');
    schema_add_column('bills','opening_import_id','VARCHAR(64) NULL AFTER `is_opening_document`');
    schema_add_column('bills','original_paid_cents','BIGINT NOT NULL DEFAULT 0 AFTER `opening_import_id`');
    if(!schema_index_exists('invoices','invoices_company_opening_import_idx')) db()->exec('ALTER TABLE invoices ADD KEY invoices_company_opening_import_idx (company_id,opening_import_id)');
    if(!schema_index_exists('bills','bills_company_opening_import_idx')) db()->exec('ALTER TABLE bills ADD KEY bills_company_opening_import_idx (company_id,opening_import_id)');
    db()->exec("CREATE TABLE IF NOT EXISTS opening_document_imports (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      document_type ENUM('customer_invoice','vendor_bill') NOT NULL,
      cutover_date DATE NOT NULL,
      filename VARCHAR(240) NOT NULL DEFAULT '',
      source_hash CHAR(64) NOT NULL,
      document_count INT NOT NULL,
      original_total_cents BIGINT NOT NULL,
      previously_paid_cents BIGINT NOT NULL DEFAULT 0,
      outstanding_total_cents BIGINT NOT NULL,
      journal_entry_id VARCHAR(64) NOT NULL,
      created_by VARCHAR(64) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY opening_document_imports_company_hash_uq (company_id,source_hash),
      KEY opening_document_imports_company_date_idx (company_id,document_type,cutover_date),
      CONSTRAINT opening_document_imports_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT opening_document_imports_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
      CONSTRAINT opening_document_imports_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if(schema_table_exists('data_import_previews')) db()->exec("ALTER TABLE data_import_previews MODIFY import_type ENUM('customers','vendors','products_services','customer_invoices','vendor_invoices','chart_of_accounts','opening_balances','coa_opening_balances','opening_customer_invoices','opening_vendor_bills') NOT NULL");
}


/**
 * Build 4140 data-only repair. Build 4130's client-side standard GIFI helper
 * could save GIFI 3700 (Dividends declared) onto the three standard equity
 * accounts. Company creation already used the correct mappings, so this repair
 * only corrects the exact legacy standard account code/name combinations and
 * never overwrites customized accounts. No schema change is required.
 */
function ensure_v4140_gifi_mapping_repair(): void
{
    if (!schema_table_exists('accounts') || !schema_table_exists('app_meta') || !schema_column_exists('accounts','gifi_code')) return;
    $check=db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key='release_4140_gifi_repair' LIMIT 1");
    $check->execute();
    if ((string)($check->fetchColumn() ?: '') === '1') return;

    $repairs=[
        ['3500','3000','Owner Capital / Share Capital'],
        ['3600','3100','Owner Draws / Shareholder Advances'],
        ['3600','3200','Retained Earnings'],
    ];
    foreach($repairs as [$gifi,$code,$name]){
        $stmt=db()->prepare("UPDATE accounts SET gifi_code=? WHERE code=? AND name=? AND gifi_code='3700'");
        $stmt->execute([$gifi,$code,$name]);
    }
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('release_4140_gifi_repair','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
}


/** Schema 33: tenant-scoped Tegh AI learning, evaluation and behavior versioning. */
function ensure_v33_ai_learning_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_learning_events (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      user_id VARCHAR(64) NOT NULL,
      event_type VARCHAR(50) NOT NULL,
      module VARCHAR(80) NULL,
      action_id VARCHAR(120) NULL,
      intent_category VARCHAR(50) NULL,
      confidence_bps INT NOT NULL DEFAULT 0,
      `signal` SMALLINT NOT NULL DEFAULT 0,
      outcome VARCHAR(30) NOT NULL DEFAULT 'observed',
      command_fingerprint CHAR(64) NULL,
      command_sample VARCHAR(300) NULL,
      context_json LONGTEXT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ai_learning_company_created_idx (company_id,created_at),
      KEY ai_learning_company_action_idx (company_id,action_id(96),created_at),
      KEY ai_learning_company_fingerprint_idx (company_id,command_fingerprint,created_at),
      CONSTRAINT ai_learning_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_learning_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_learned_rules (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      user_id VARCHAR(64) NULL,
      scope_type ENUM('company','user','bank_account','vendor','description') NOT NULL DEFAULT 'company',
      scope_key VARCHAR(128) NOT NULL DEFAULT '',
      pattern_type VARCHAR(40) NOT NULL,
      pattern_value VARCHAR(255) NOT NULL,
      suggestion_type VARCHAR(40) NOT NULL,
      suggestion_json LONGTEXT NOT NULL,
      positive_count INT NOT NULL DEFAULT 0,
      negative_count INT NOT NULL DEFAULT 0,
      confidence_bps INT NOT NULL DEFAULT 0,
      status ENUM('enabled','disabled','retired') NOT NULL DEFAULT 'enabled',
      source ENUM('inferred','explicit','correction','system') NOT NULL DEFAULT 'inferred',
      last_used_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY ai_rules_company_status_idx (company_id,status,confidence_bps),
      KEY ai_rules_company_pattern_idx (company_id,pattern_type,pattern_value(64)),
      CONSTRAINT ai_rules_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_rules_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_improvements (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      created_by VARCHAR(64) NOT NULL,
      category VARCHAR(60) NOT NULL,
      title VARCHAR(220) NOT NULL,
      problem_summary VARCHAR(1000) NOT NULL,
      current_behavior VARCHAR(1000) NOT NULL DEFAULT '',
      proposed_change_json LONGTEXT NOT NULL,
      evidence_count INT NOT NULL DEFAULT 0,
      evidence_fingerprint CHAR(64) NOT NULL,
      baseline_metrics_json LONGTEXT NOT NULL,
      candidate_metrics_json LONGTEXT NOT NULL,
      regression_json LONGTEXT NOT NULL,
      safety_status ENUM('pending','passed','failed','protected_change') NOT NULL DEFAULT 'pending',
      status ENUM('proposed','evaluated','enabled','rejected','rolled_back','review_required') NOT NULL DEFAULT 'proposed',
      approved_by VARCHAR(64) NULL,
      approved_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY ai_improvements_company_fingerprint_uq (company_id,evidence_fingerprint),
      KEY ai_improvements_company_status_idx (company_id,status,created_at),
      CONSTRAINT ai_improvements_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_improvements_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
      CONSTRAINT ai_improvements_approved_user_fk FOREIGN KEY (approved_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_behavior_versions (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      version_number INT NOT NULL,
      config_json LONGTEXT NOT NULL,
      source_improvement_id VARCHAR(64) NULL,
      metrics_json LONGTEXT NOT NULL,
      active TINYINT(1) NOT NULL DEFAULT 0,
      created_by VARCHAR(64) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY ai_behavior_company_version_uq (company_id,version_number),
      KEY ai_behavior_company_active_idx (company_id,active,version_number),
      CONSTRAINT ai_behavior_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_behavior_improvement_fk FOREIGN KEY (source_improvement_id) REFERENCES ai_agent_improvements(id) ON DELETE SET NULL,
      CONSTRAINT ai_behavior_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_review_runs (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      run_by VARCHAR(64) NOT NULL,
      run_mode ENUM('manual','automatic') NOT NULL DEFAULT 'manual',
      event_count INT NOT NULL DEFAULT 0,
      cluster_count INT NOT NULL DEFAULT 0,
      candidate_count INT NOT NULL DEFAULT 0,
      status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
      summary_json LONGTEXT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ai_review_company_created_idx (company_id,created_at),
      CONSTRAINT ai_review_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_review_user_fk FOREIGN KEY (run_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    schema_try_add_check('ai_agent_learning_events','ai_learning_confidence_ck','confidence_bps BETWEEN 0 AND 10000');
    schema_try_add_check('ai_agent_learning_events','ai_learning_signal_ck','`signal` BETWEEN -1 AND 1');
    schema_try_add_check('ai_agent_learned_rules','ai_rules_counts_ck','positive_count >= 0 AND negative_count >= 0 AND confidence_bps BETWEEN 0 AND 10000');

}

/**
 * Upgrade owner installations in place. Every operation is independently
 * idempotent because IONOS MySQL/MariaDB DDL implicitly commits.
 */

function ensure_v34_human_agent_schema(): void
{
    if (!schema_table_exists('companies') || !schema_table_exists('users')) return;
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_conversations (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL,
      status ENUM('active','suspended','completed','cancelled') NOT NULL DEFAULT 'active', bookkeeping_mode ENUM('guided','full') NOT NULL DEFAULT 'guided',
      active_task_id VARCHAR(64) NULL, last_intent VARCHAR(500) NOT NULL DEFAULT '', summary_json LONGTEXT NOT NULL, verified_context_json LONGTEXT NOT NULL,
      revision INT NOT NULL DEFAULT 1, expires_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY ai_conversation_company_user_idx (company_id,user_id,status,updated_at), KEY ai_conversation_expiry_idx (expires_at),
      CONSTRAINT ai_conversation_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_conversation_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_memories (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NULL,
      memory_type ENUM('working','conversation','user_preference','company_semantic','episodic','procedural','system_improvement') NOT NULL,
      scope_type ENUM('company','user','bank_account','customer','vendor','description','workflow','system') NOT NULL DEFAULT 'company', scope_key VARCHAR(128) NOT NULL DEFAULT '',
      normalized_key VARCHAR(180) NOT NULL, value_json LONGTEXT NOT NULL, source ENUM('explicit','observed','inferred','system') NOT NULL DEFAULT 'observed',
      evidence_count INT NOT NULL DEFAULT 1, evidence_json LONGTEXT NOT NULL, confidence_bps INT NOT NULL DEFAULT 0,
      status ENUM('enabled','conflict','disabled','retired') NOT NULL DEFAULT 'enabled', reason VARCHAR(500) NOT NULL DEFAULT '', version INT NOT NULL DEFAULT 1,
      last_used_at DATETIME NULL, review_after DATETIME NULL, expires_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY ai_memory_company_type_idx (company_id,memory_type,status,confidence_bps), KEY ai_memory_company_key_idx (company_id,normalized_key(64),scope_type,scope_key(32)),
      KEY ai_memory_user_idx (company_id,user_id,memory_type,status),
      CONSTRAINT ai_memory_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_memory_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_memory_history (
      id VARCHAR(64) PRIMARY KEY, memory_id VARCHAR(64) NOT NULL, company_id VARCHAR(64) NOT NULL, changed_by VARCHAR(64) NULL,
      change_type ENUM('created','updated','corrected','conflict','enabled','disabled','retired','forgotten','decayed') NOT NULL,
      before_json LONGTEXT NULL, after_json LONGTEXT NULL, reason VARCHAR(500) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ai_memory_history_memory_idx (memory_id,created_at), KEY ai_memory_history_company_idx (company_id,created_at),
      CONSTRAINT ai_memory_history_memory_fk FOREIGN KEY (memory_id) REFERENCES ai_agent_memories(id) ON DELETE CASCADE,
      CONSTRAINT ai_memory_history_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_memory_history_user_fk FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS ai_agent_plans (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL, conversation_id VARCHAR(64) NOT NULL, task_id VARCHAR(64) NULL,
      user_goal VARCHAR(500) NOT NULL, risk_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low', confidence_bps INT NOT NULL DEFAULT 0,
      plan_json LONGTEXT NOT NULL, plan_hash CHAR(64) NOT NULL, status ENUM('proposed','waiting_input','waiting_confirmation','executing','completed','failed','cancelled','superseded','needs_review') NOT NULL DEFAULT 'proposed',
      requires_confirmation TINYINT(1) NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY ai_plan_company_user_idx (company_id,user_id,status,updated_at), KEY ai_plan_conversation_idx (conversation_id,created_at), KEY ai_plan_expiry_idx (expires_at),
      CONSTRAINT ai_plan_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT ai_plan_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT ai_plan_conversation_fk FOREIGN KEY (conversation_id) REFERENCES ai_agent_conversations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (schema_table_exists('ai_agent_action_authorizations')) {
        schema_add_column('ai_agent_action_authorizations','conversation_id','VARCHAR(64) NULL AFTER `journal_entry_id`');
        schema_add_column('ai_agent_action_authorizations','plan_id','VARCHAR(64) NULL AFTER `conversation_id`');
        schema_add_column('ai_agent_action_authorizations','task_id','VARCHAR(64) NULL AFTER `plan_id`');
        schema_add_column('ai_agent_action_authorizations','operation_key','CHAR(64) NULL AFTER `task_id`');
        schema_add_column('ai_agent_action_authorizations','result_status',"ENUM('none','completed','needs_review','failed') NOT NULL DEFAULT 'none' AFTER `operation_key`");
        schema_add_column('ai_agent_action_authorizations','result_json','LONGTEXT NULL AFTER `result_status`');
        schema_add_index('ai_agent_action_authorizations','ai_action_operation_key_idx','operation_key');
        schema_add_index('ai_agent_action_authorizations','ai_action_conversation_idx','company_id,user_id,conversation_id,created_at');
    }
    if (schema_table_exists('ai_agent_tasks')) {
        schema_add_column('ai_agent_tasks','conversation_id','VARCHAR(64) NULL AFTER `pending_confirmation_id`');
        schema_add_column('ai_agent_tasks','plan_id','VARCHAR(64) NULL AFTER `conversation_id`');
    }
    if (schema_table_exists('ai_agent_result_sets')) schema_add_column('ai_agent_result_sets','conversation_id','VARCHAR(64) NULL AFTER `status_snapshot_json`');

    schema_try_add_check('ai_agent_memories','ai_memory_confidence_ck','confidence_bps BETWEEN 0 AND 10000');
    schema_try_add_check('ai_agent_memories','ai_memory_evidence_ck','evidence_count >= 0');
    schema_try_add_check('ai_agent_plans','ai_plan_confidence_ck','confidence_bps BETWEEN 0 AND 10000');

    // Existing Schema 33 learned rules remain authoritative recommendations. Link them into durable memory
    // without duplicating raw transaction text or changing their accounting effect.
    if (schema_table_exists('ai_agent_learned_rules')) {
        try {
            $rows=db()->query("SELECT * FROM ai_agent_learned_rules WHERE status IN ('enabled','disabled','retired')")->fetchAll();
            $exists=db()->prepare("SELECT id FROM ai_agent_memories WHERE company_id=? AND normalized_key=? AND scope_type=? AND scope_key=? LIMIT 1");
            $insert=db()->prepare("INSERT INTO ai_agent_memories (id,company_id,user_id,memory_type,scope_type,scope_key,normalized_key,value_json,source,evidence_count,evidence_json,confidence_bps,status,reason,last_used_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach($rows as $row){
                $key='learned_rule:'.(string)$row['id'];$exists->execute([(string)$row['company_id'],$key,(string)$row['scope_type'],(string)$row['scope_key']]);if($exists->fetchColumn())continue;
                $value=json_encode(['legacyRuleId'=>(string)$row['id'],'patternType'=>(string)$row['pattern_type'],'patternValue'=>(string)$row['pattern_value'],'suggestionType'=>(string)$row['suggestion_type'],'suggestion'=>json_decode((string)$row['suggestion_json'],true)?:[]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $status=(string)$row['status']==='enabled'?'enabled':((string)$row['status']==='disabled'?'disabled':'retired');
                $insert->execute([new_id('aimem'),(string)$row['company_id'],$row['user_id']?:null,'company_semantic',(string)$row['scope_type'],(string)$row['scope_key'],$key,$value,(string)$row['source']==='inferred'?'inferred':'explicit',(int)$row['positive_count']+(int)$row['negative_count'],json_encode(['legacyRuleId'=>(string)$row['id']],JSON_THROW_ON_ERROR),(int)$row['confidence_bps'],$status,'Migrated from Tegh 4.2 learned rule.', $row['last_used_at']]);
            }
        } catch (Throwable $legacyMemoryError) {
            // Legacy-rule copying is recoverable because Schema 33 learned rules remain
            // readable in their original table. Do not block the core accounting workspace.
            error_log('Tegh legacy AI memory copy deferred: '.$legacyMemoryError->getMessage());
        }
    }
}

function native_agent_schema_requirements(): array
{
    $columns=native_agent_schema_column_definitions();
    return [
        'tables'=>['native_agent_policies','native_agent_runs','native_agent_findings'],
        'columns'=>array_map('array_keys',$columns),
        'indexes'=>[
            'native_agent_policies'=>['PRIMARY','native_agent_policy_company_uq','native_agent_policy_updated_idx'],
            'native_agent_runs'=>['PRIMARY','native_agent_run_identity_uq','native_agent_run_company_status_idx','native_agent_run_lease_idx'],
            'native_agent_findings'=>['PRIMARY','native_agent_finding_company_fingerprint_uq','native_agent_finding_company_state_idx','native_agent_finding_agent_state_idx','native_agent_finding_assignee_idx','native_agent_finding_run_idx'],
        ],
        'constraints'=>array_map('array_keys',native_agent_schema_foreign_keys()),
    ];
}

/** Final Schema 35 column definitions used by both readiness and repair. */
function native_agent_schema_column_definitions(): array
{
    return [
        'native_agent_policies'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL',
            'supervisor_enabled'=>'TINYINT(1) NOT NULL DEFAULT 1','bookkeeping_enabled'=>'TINYINT(1) NOT NULL DEFAULT 1',
            'reconciliation_enabled'=>'TINYINT(1) NOT NULL DEFAULT 1','close_enabled'=>'TINYINT(1) NOT NULL DEFAULT 1',
            'cadence'=>"ENUM('manual','daily','weekly') NOT NULL DEFAULT 'manual'",'opportunistic_enabled'=>'TINYINT(1) NOT NULL DEFAULT 1',
            'materiality_cents'=>'BIGINT NOT NULL DEFAULT 10000','stale_days'=>'INT NOT NULL DEFAULT 30',
            'confidence_review_bps'=>'INT NOT NULL DEFAULT 8000',
            'notification_min_severity'=>"ENUM('info','warning','critical') NOT NULL DEFAULT 'warning'",'connected_enabled'=>'TINYINT(1) NOT NULL DEFAULT 0',
            'thresholds_json'=>'LONGTEXT NOT NULL','revision'=>'INT NOT NULL DEFAULT 1','policy_hash'=>'CHAR(64) NOT NULL',
            'created_by'=>'VARCHAR(64) NULL','updated_by'=>'VARCHAR(64) NULL',
            'created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP','updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'native_agent_runs'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL','agent_type'=>'VARCHAR(40) NOT NULL',
            'trigger_type'=>"ENUM('manual','scheduled','opportunistic') NOT NULL DEFAULT 'manual'",'initiated_by'=>'VARCHAR(64) NULL',
            'status'=>"ENUM('running','completed','partial','failed','skipped','interrupted') NOT NULL DEFAULT 'running'",'idempotency_key'=>'CHAR(64) NOT NULL',
            'policy_revision'=>'INT NOT NULL','policy_hash'=>'CHAR(64) NOT NULL','source_revision_hash'=>'CHAR(64) NOT NULL',
            'lease_owner'=>'VARCHAR(64) NOT NULL','lease_expires_at'=>'DATETIME NOT NULL','cursor_json'=>'LONGTEXT NOT NULL','summary_json'=>'LONGTEXT NOT NULL',
            'processed_count'=>'INT NOT NULL DEFAULT 0','finding_count'=>'INT NOT NULL DEFAULT 0','notification_count'=>'INT NOT NULL DEFAULT 0',
            'error_code'=>'VARCHAR(80) NULL','error_message'=>'VARCHAR(500) NULL','started_at'=>'DATETIME NOT NULL','completed_at'=>'DATETIME NULL',
            'created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP','updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'native_agent_findings'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL','last_seen_run_id'=>'VARCHAR(64) NULL',
            'agent_type'=>'VARCHAR(40) NOT NULL','finding_type'=>'VARCHAR(80) NOT NULL','fingerprint'=>'CHAR(64) NOT NULL',
            'severity'=>"ENUM('info','warning','critical') NOT NULL DEFAULT 'warning'",'confidence_bps'=>'INT NOT NULL DEFAULT 10000',
            'title'=>'VARCHAR(220) NOT NULL','explanation'=>'VARCHAR(1200) NOT NULL','evidence_json'=>'LONGTEXT NOT NULL','evidence_hash'=>'CHAR(64) NOT NULL',
            'source_revision_hash'=>'CHAR(64) NOT NULL','source_date'=>'DATETIME NULL','policy_revision'=>'INT NOT NULL','affected_count'=>'INT NOT NULL DEFAULT 1',
            'proposed_action_id'=>'VARCHAR(120) NULL','proposed_inputs_json'=>'LONGTEXT NOT NULL',
            'execution_class'=>"ENUM('immediate','prepared','authorized') NOT NULL DEFAULT 'immediate'",
            'state'=>"ENUM('open','snoozed','dismissed','resolved','superseded') NOT NULL DEFAULT 'open'",'assigned_user_id'=>'VARCHAR(64) NULL',
            'first_seen_at'=>'DATETIME NOT NULL','last_seen_at'=>'DATETIME NOT NULL','snoozed_until'=>'DATETIME NULL','snoozed_by'=>'VARCHAR(64) NULL',
            'dismissed_at'=>'DATETIME NULL','dismissed_by'=>'VARCHAR(64) NULL','accepted_task_id'=>'VARCHAR(64) NULL','accepted_at'=>'DATETIME NULL',
            'accepted_by'=>'VARCHAR(64) NULL','resolved_at'=>'DATETIME NULL','resolved_by'=>'VARCHAR(64) NULL',
            'resolution_note'=>"VARCHAR(500) NOT NULL DEFAULT ''",'created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
    ];
}

function native_agent_schema_foreign_keys(): array
{
    return [
        'native_agent_policies'=>[
            'native_agent_policy_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
            'native_agent_policy_created_user_fk'=>'FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
            'native_agent_policy_updated_user_fk'=>'FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL',
        ],
        'native_agent_runs'=>[
            'native_agent_run_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
            'native_agent_run_user_fk'=>'FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL',
        ],
        'native_agent_findings'=>[
            'native_agent_finding_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
            'native_agent_finding_run_fk'=>'FOREIGN KEY (last_seen_run_id) REFERENCES native_agent_runs(id) ON DELETE SET NULL',
            'native_agent_finding_assignee_fk'=>'FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL',
            'native_agent_finding_snooze_user_fk'=>'FOREIGN KEY (snoozed_by) REFERENCES users(id) ON DELETE SET NULL',
            'native_agent_finding_dismiss_user_fk'=>'FOREIGN KEY (dismissed_by) REFERENCES users(id) ON DELETE SET NULL',
            'native_agent_finding_accepted_task_fk'=>'FOREIGN KEY (accepted_task_id) REFERENCES ai_agent_tasks(id) ON DELETE SET NULL',
            'native_agent_finding_accepted_user_fk'=>'FOREIGN KEY (accepted_by) REFERENCES users(id) ON DELETE SET NULL',
            'native_agent_finding_resolve_user_fk'=>'FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL',
        ],
    ];
}

function native_agent_schema_status(): array
{
    $requirements=native_agent_schema_requirements();$missingTables=[];$missingColumns=[];$missingIndexes=[];$missingConstraints=[];
    foreach($requirements['tables'] as $table)if(!schema_table_exists($table))$missingTables[]=$table;
    foreach($requirements['columns'] as $table=>$columns){
        if(!schema_table_exists($table))continue;
        foreach($columns as $column)if(!schema_column_exists($table,$column))$missingColumns[]=$table.'.'.$column;
    }
    foreach($requirements['indexes'] as $table=>$indexes){
        if(!schema_table_exists($table))continue;
        foreach($indexes as $index)if(!schema_index_exists($table,$index))$missingIndexes[]=$table.'.'.$index;
    }
    foreach($requirements['constraints'] as $table=>$constraints){
        if(!schema_table_exists($table))continue;
        foreach($constraints as $constraint)if(!schema_constraint_exists($table,$constraint))$missingConstraints[]=$table.'.'.$constraint;
    }
    return ['ready'=>!$missingTables&&!$missingColumns&&!$missingIndexes&&!$missingConstraints,'missingTables'=>$missingTables,'missingColumns'=>$missingColumns,'missingIndexes'=>$missingIndexes,'missingConstraints'=>$missingConstraints];
}

/** Schema 35 adds only company-scoped Native Agent metadata. */
function ensure_v35_native_agent_schema(): void
{
    if(!schema_table_exists('companies')||!schema_table_exists('users'))return;
    db()->exec("CREATE TABLE IF NOT EXISTS native_agent_policies (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL,
      supervisor_enabled TINYINT(1) NOT NULL DEFAULT 1, bookkeeping_enabled TINYINT(1) NOT NULL DEFAULT 1,
      reconciliation_enabled TINYINT(1) NOT NULL DEFAULT 1, close_enabled TINYINT(1) NOT NULL DEFAULT 1,
      cadence ENUM('manual','daily','weekly') NOT NULL DEFAULT 'manual', opportunistic_enabled TINYINT(1) NOT NULL DEFAULT 1,
      materiality_cents BIGINT NOT NULL DEFAULT 10000, stale_days INT NOT NULL DEFAULT 30, confidence_review_bps INT NOT NULL DEFAULT 8000,
      notification_min_severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning', connected_enabled TINYINT(1) NOT NULL DEFAULT 0,
      thresholds_json LONGTEXT NOT NULL, revision INT NOT NULL DEFAULT 1, policy_hash CHAR(64) NOT NULL,
      created_by VARCHAR(64) NULL, updated_by VARCHAR(64) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY native_agent_policy_company_uq (company_id), KEY native_agent_policy_updated_idx (updated_at),
      CONSTRAINT native_agent_policy_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT native_agent_policy_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_policy_updated_user_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS native_agent_runs (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, agent_type VARCHAR(40) NOT NULL,
      trigger_type ENUM('manual','scheduled','opportunistic') NOT NULL DEFAULT 'manual', initiated_by VARCHAR(64) NULL,
      status ENUM('running','completed','partial','failed','skipped','interrupted') NOT NULL DEFAULT 'running', idempotency_key CHAR(64) NOT NULL,
      policy_revision INT NOT NULL, policy_hash CHAR(64) NOT NULL, source_revision_hash CHAR(64) NOT NULL,
      lease_owner VARCHAR(64) NOT NULL, lease_expires_at DATETIME NOT NULL, cursor_json LONGTEXT NOT NULL, summary_json LONGTEXT NOT NULL,
      processed_count INT NOT NULL DEFAULT 0, finding_count INT NOT NULL DEFAULT 0, notification_count INT NOT NULL DEFAULT 0,
      error_code VARCHAR(80) NULL, error_message VARCHAR(500) NULL, started_at DATETIME NOT NULL, completed_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY native_agent_run_identity_uq (company_id,agent_type,idempotency_key),
      KEY native_agent_run_company_status_idx (company_id,status,started_at), KEY native_agent_run_lease_idx (status,lease_expires_at),
      CONSTRAINT native_agent_run_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT native_agent_run_user_fk FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS native_agent_findings (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, last_seen_run_id VARCHAR(64) NULL,
      agent_type VARCHAR(40) NOT NULL, finding_type VARCHAR(80) NOT NULL, fingerprint CHAR(64) NOT NULL,
      severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning', confidence_bps INT NOT NULL DEFAULT 10000,
      title VARCHAR(220) NOT NULL, explanation VARCHAR(1200) NOT NULL, evidence_json LONGTEXT NOT NULL, evidence_hash CHAR(64) NOT NULL,
      source_revision_hash CHAR(64) NOT NULL, source_date DATETIME NULL, policy_revision INT NOT NULL, affected_count INT NOT NULL DEFAULT 1,
      proposed_action_id VARCHAR(120) NULL, proposed_inputs_json LONGTEXT NOT NULL,
      execution_class ENUM('immediate','prepared','authorized') NOT NULL DEFAULT 'immediate',
      state ENUM('open','snoozed','dismissed','resolved','superseded') NOT NULL DEFAULT 'open', assigned_user_id VARCHAR(64) NULL,
      first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, snoozed_until DATETIME NULL, snoozed_by VARCHAR(64) NULL,
      dismissed_at DATETIME NULL, dismissed_by VARCHAR(64) NULL, resolved_at DATETIME NULL, resolved_by VARCHAR(64) NULL,
      accepted_task_id VARCHAR(64) NULL, accepted_at DATETIME NULL, accepted_by VARCHAR(64) NULL,
      resolution_note VARCHAR(500) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY native_agent_finding_company_fingerprint_uq (company_id,fingerprint),
      KEY native_agent_finding_company_state_idx (company_id,state,severity,last_seen_at),
      KEY native_agent_finding_agent_state_idx (company_id,agent_type,state,last_seen_at),
      KEY native_agent_finding_assignee_idx (company_id,assigned_user_id,state), KEY native_agent_finding_run_idx (last_seen_run_id),
      CONSTRAINT native_agent_finding_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT native_agent_finding_run_fk FOREIGN KEY (last_seen_run_id) REFERENCES native_agent_runs(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_finding_assignee_fk FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_finding_snooze_user_fk FOREIGN KEY (snoozed_by) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_finding_dismiss_user_fk FOREIGN KEY (dismissed_by) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_finding_accepted_task_fk FOREIGN KEY (accepted_task_id) REFERENCES ai_agent_tasks(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_finding_accepted_user_fk FOREIGN KEY (accepted_by) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_finding_resolve_user_fk FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // CREATE TABLE IF NOT EXISTS cannot repair an interrupted CREATE/ALTER that
    // left an incomplete table. Add missing columns only when the partial table
    // is empty. A populated table with a missing column indicates structural
    // loss; inventing policy, provenance or finding evidence would be unsafe.
    foreach(native_agent_schema_column_definitions() as $table=>$columns){
        $missing=array_values(array_filter(array_keys($columns),static fn(string $column):bool=>!schema_column_exists($table,$column)));
        if(!$missing)continue;
        $rowCount=(int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if($rowCount>0)throw new RuntimeException('Schema 35 repair stopped because '.$table.' contains rows but is missing: '.implode(', ',$missing).'. Restore the structure from a verified backup; Tegh will not fabricate Native Agent metadata.');
        foreach($missing as $column)schema_add_column($table,$column,$columns[$column]);
    }

    foreach(['native_agent_policies','native_agent_runs','native_agent_findings'] as $table){
        if(!schema_index_exists($table,'PRIMARY'))db()->exec("ALTER TABLE `$table` ADD PRIMARY KEY (`id`)");
    }
    foreach([
      ['native_agent_policies','native_agent_policy_company_uq','company_id',true],['native_agent_policies','native_agent_policy_updated_idx','updated_at',false],
      ['native_agent_runs','native_agent_run_identity_uq','company_id,agent_type,idempotency_key',true],['native_agent_runs','native_agent_run_company_status_idx','company_id,status,started_at',false],['native_agent_runs','native_agent_run_lease_idx','status,lease_expires_at',false],
      ['native_agent_findings','native_agent_finding_company_fingerprint_uq','company_id,fingerprint',true],['native_agent_findings','native_agent_finding_company_state_idx','company_id,state,severity,last_seen_at',false],['native_agent_findings','native_agent_finding_agent_state_idx','company_id,agent_type,state,last_seen_at',false],['native_agent_findings','native_agent_finding_assignee_idx','company_id,assigned_user_id,state',false],['native_agent_findings','native_agent_finding_run_idx','last_seen_run_id',false],
    ] as [$table,$index,$columns,$unique])schema_add_index($table,$index,$columns,$unique);
    foreach(native_agent_schema_foreign_keys() as $table=>$constraints){
        foreach($constraints as $name=>$definition){
            if(!schema_constraint_exists($table,$name))db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` $definition");
        }
    }
    schema_try_add_check('native_agent_policies','native_agent_policy_thresholds_ck','materiality_cents >= 0 AND stale_days BETWEEN 1 AND 3650 AND confidence_review_bps BETWEEN 0 AND 10000 AND revision >= 1');
    schema_try_add_check('native_agent_runs','native_agent_run_counts_ck','processed_count >= 0 AND finding_count >= 0 AND notification_count >= 0');
    schema_try_add_check('native_agent_findings','native_agent_finding_confidence_ck','confidence_bps BETWEEN 0 AND 10000 AND affected_count >= 0');
}

/** Final additive Schema 36 structure for AP/AR, document intake and collections. */
function native_ap_ar_schema_column_definitions(): array
{
    return [
        'native_agent_policies'=>[
            'ap_enabled'=>'TINYINT(1) NOT NULL DEFAULT 1',
            'ar_enabled'=>'TINYINT(1) NOT NULL DEFAULT 1',
        ],
        'native_agent_documents'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL',
            'document_type'=>"ENUM('vendor_bill','customer_invoice') NOT NULL",'original_name'=>'VARCHAR(240) NOT NULL',
            'storage_path'=>'VARCHAR(500) NOT NULL','mime_type'=>'VARCHAR(100) NOT NULL','file_extension'=>'VARCHAR(10) NOT NULL',
            'size_bytes'=>'BIGINT NOT NULL','sha256'=>'CHAR(64) NOT NULL',
            'state'=>"ENUM('uploaded','extracting','review','ready','prepared','linked','dismissed','stale','failed') NOT NULL DEFAULT 'uploaded'",
            'extraction_method'=>"ENUM('none','pdf_text','ocr','manual') NOT NULL DEFAULT 'none'",
            'candidate_json'=>'LONGTEXT NOT NULL','candidate_hash'=>'CHAR(64) NOT NULL','source_revision_hash'=>'CHAR(64) NOT NULL',
            'proposed_action_id'=>'VARCHAR(120) NOT NULL','linked_entity_type'=>"ENUM('bill','invoice') NULL",'linked_entity_id'=>'VARCHAR(64) NULL',
            'uploaded_by'=>'VARCHAR(64) NOT NULL','reviewed_by'=>'VARCHAR(64) NULL','reviewed_at'=>'DATETIME NULL',
            'prepared_task_id'=>'VARCHAR(64) NULL','prepared_by'=>'VARCHAR(64) NULL','prepared_at'=>'DATETIME NULL',
            'linked_by'=>'VARCHAR(64) NULL','linked_at'=>'DATETIME NULL','dismissed_by'=>'VARCHAR(64) NULL','dismissed_at'=>'DATETIME NULL',
            'failure_code'=>'VARCHAR(80) NULL','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'native_agent_collection_drafts'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL','invoice_id'=>'VARCHAR(64) NOT NULL','fingerprint'=>'CHAR(64) NOT NULL',
            'level'=>"ENUM('friendly','firm','final') NOT NULL DEFAULT 'friendly'",'channel'=>"ENUM('email') NOT NULL DEFAULT 'email'",
            'recipient_email'=>'VARCHAR(254) NOT NULL','subject'=>'VARCHAR(240) NOT NULL','body_text'=>'TEXT NOT NULL',
            'source_revision_hash'=>'CHAR(64) NOT NULL','content_hash'=>'CHAR(64) NOT NULL',
            'state'=>"ENUM('draft','approved','sending','sent','failed','stale','dismissed') NOT NULL DEFAULT 'draft'",
            'created_by'=>'VARCHAR(64) NOT NULL','updated_by'=>'VARCHAR(64) NOT NULL','approved_by'=>'VARCHAR(64) NULL','approved_at'=>'DATETIME NULL',
            'sending_started_at'=>'DATETIME NULL','sent_at'=>'DATETIME NULL','failed_at'=>'DATETIME NULL','failure_message'=>'VARCHAR(500) NULL',
            'outbound_email_id'=>'VARCHAR(64) NULL','followup_id'=>'VARCHAR(64) NULL','dismissed_by'=>'VARCHAR(64) NULL','dismissed_at'=>'DATETIME NULL',
            'created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP','updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
    ];
}

function native_ap_ar_schema_foreign_keys(): array
{
    return [
        'native_agent_documents'=>[
            'native_agent_document_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
            'native_agent_document_uploaded_user_fk'=>'FOREIGN KEY (uploaded_by) REFERENCES users(id)',
            'native_agent_document_reviewed_user_fk'=>'FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL',
            'native_agent_document_prepared_task_fk'=>'FOREIGN KEY (prepared_task_id) REFERENCES ai_agent_tasks(id) ON DELETE SET NULL',
            'native_agent_document_prepared_user_fk'=>'FOREIGN KEY (prepared_by) REFERENCES users(id) ON DELETE SET NULL',
            'native_agent_document_linked_user_fk'=>'FOREIGN KEY (linked_by) REFERENCES users(id) ON DELETE SET NULL',
            'native_agent_document_dismissed_user_fk'=>'FOREIGN KEY (dismissed_by) REFERENCES users(id) ON DELETE SET NULL',
        ],
        'native_agent_collection_drafts'=>[
            'native_agent_collection_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
            'native_agent_collection_invoice_fk'=>'FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE',
            'native_agent_collection_created_user_fk'=>'FOREIGN KEY (created_by) REFERENCES users(id)',
            'native_agent_collection_updated_user_fk'=>'FOREIGN KEY (updated_by) REFERENCES users(id)',
            'native_agent_collection_approved_user_fk'=>'FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL',
            'native_agent_collection_outbound_email_fk'=>'FOREIGN KEY (outbound_email_id) REFERENCES outbound_emails(id) ON DELETE SET NULL',
            'native_agent_collection_followup_fk'=>'FOREIGN KEY (followup_id) REFERENCES invoice_followups(id) ON DELETE SET NULL',
            'native_agent_collection_dismissed_user_fk'=>'FOREIGN KEY (dismissed_by) REFERENCES users(id) ON DELETE SET NULL',
        ],
    ];
}

function native_ap_ar_schema_requirements(): array
{
    $columns=native_ap_ar_schema_column_definitions();
    return [
        'tables'=>['native_agent_documents','native_agent_collection_drafts'],
        'columns'=>array_map('array_keys',$columns),
        'indexes'=>[
            'native_agent_documents'=>['PRIMARY','native_agent_document_company_sha_type_uq','native_agent_document_company_state_idx','native_agent_document_link_idx','native_agent_document_task_idx'],
            'native_agent_collection_drafts'=>['PRIMARY','native_agent_collection_company_fingerprint_uq','native_agent_collection_company_state_idx','native_agent_collection_invoice_idx','native_agent_collection_mail_idx'],
        ],
        'constraints'=>array_map('array_keys',native_ap_ar_schema_foreign_keys()),
    ];
}

function native_ap_ar_schema_status(): array
{
    $requirements=native_ap_ar_schema_requirements();$missingTables=[];$missingColumns=[];$missingIndexes=[];$missingConstraints=[];
    foreach($requirements['tables'] as $table)if(!schema_table_exists($table))$missingTables[]=$table;
    foreach($requirements['columns'] as $table=>$columns){if(!schema_table_exists($table))continue;foreach($columns as $column)if(!schema_column_exists($table,$column))$missingColumns[]=$table.'.'.$column;}
    foreach($requirements['indexes'] as $table=>$indexes){if(!schema_table_exists($table))continue;foreach($indexes as $index)if(!schema_index_exists($table,$index))$missingIndexes[]=$table.'.'.$index;}
    foreach($requirements['constraints'] as $table=>$constraints){if(!schema_table_exists($table))continue;foreach($constraints as $constraint)if(!schema_constraint_exists($table,$constraint))$missingConstraints[]=$table.'.'.$constraint;}
    foreach(['ap_enabled','ar_enabled'] as $column)if(!schema_column_exists('native_agent_policies',$column))$missingColumns[]='native_agent_policies.'.$column;
    return ['ready'=>!$missingTables&&!$missingColumns&&!$missingIndexes&&!$missingConstraints,'missingTables'=>$missingTables,'missingColumns'=>$missingColumns,'missingIndexes'=>$missingIndexes,'missingConstraints'=>$missingConstraints];
}

/** Schema 36 is additive. It never changes accounting records or invents provenance. */
function ensure_v36_native_ap_ar_schema(): void
{
    ensure_v35_native_agent_schema();
    if(!schema_table_exists('native_agent_policies')||!schema_table_exists('invoices')||!schema_table_exists('outbound_emails')||!schema_table_exists('invoice_followups'))return;
    foreach(['ap_enabled','ar_enabled'] as $column){
        if(!schema_column_exists('native_agent_policies',$column))schema_add_column('native_agent_policies',$column,'TINYINT(1) NOT NULL DEFAULT 1');
    }
    db()->exec("CREATE TABLE IF NOT EXISTS native_agent_documents (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL,
      document_type ENUM('vendor_bill','customer_invoice') NOT NULL, original_name VARCHAR(240) NOT NULL,
      storage_path VARCHAR(500) NOT NULL, mime_type VARCHAR(100) NOT NULL, file_extension VARCHAR(10) NOT NULL,
      size_bytes BIGINT NOT NULL, sha256 CHAR(64) NOT NULL,
      state ENUM('uploaded','extracting','review','ready','prepared','linked','dismissed','stale','failed') NOT NULL DEFAULT 'uploaded',
      extraction_method ENUM('none','pdf_text','ocr','manual') NOT NULL DEFAULT 'none', candidate_json LONGTEXT NOT NULL,
      candidate_hash CHAR(64) NOT NULL, source_revision_hash CHAR(64) NOT NULL, proposed_action_id VARCHAR(120) NOT NULL,
      linked_entity_type ENUM('bill','invoice') NULL, linked_entity_id VARCHAR(64) NULL,
      uploaded_by VARCHAR(64) NOT NULL, reviewed_by VARCHAR(64) NULL, reviewed_at DATETIME NULL,
      prepared_task_id VARCHAR(64) NULL, prepared_by VARCHAR(64) NULL, prepared_at DATETIME NULL,
      linked_by VARCHAR(64) NULL, linked_at DATETIME NULL, dismissed_by VARCHAR(64) NULL, dismissed_at DATETIME NULL,
      failure_code VARCHAR(80) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY native_agent_document_company_sha_type_uq (company_id,sha256,document_type),
      KEY native_agent_document_company_state_idx (company_id,state,updated_at),
      KEY native_agent_document_link_idx (company_id,linked_entity_type,linked_entity_id), KEY native_agent_document_task_idx (prepared_task_id),
      CONSTRAINT native_agent_document_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT native_agent_document_uploaded_user_fk FOREIGN KEY (uploaded_by) REFERENCES users(id),
      CONSTRAINT native_agent_document_reviewed_user_fk FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_document_prepared_task_fk FOREIGN KEY (prepared_task_id) REFERENCES ai_agent_tasks(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_document_prepared_user_fk FOREIGN KEY (prepared_by) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_document_linked_user_fk FOREIGN KEY (linked_by) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_document_dismissed_user_fk FOREIGN KEY (dismissed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS native_agent_collection_drafts (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, invoice_id VARCHAR(64) NOT NULL, fingerprint CHAR(64) NOT NULL,
      level ENUM('friendly','firm','final') NOT NULL DEFAULT 'friendly', channel ENUM('email') NOT NULL DEFAULT 'email',
      recipient_email VARCHAR(254) NOT NULL, subject VARCHAR(240) NOT NULL, body_text TEXT NOT NULL,
      source_revision_hash CHAR(64) NOT NULL, content_hash CHAR(64) NOT NULL,
      state ENUM('draft','approved','sending','sent','failed','stale','dismissed') NOT NULL DEFAULT 'draft',
      created_by VARCHAR(64) NOT NULL, updated_by VARCHAR(64) NOT NULL, approved_by VARCHAR(64) NULL, approved_at DATETIME NULL,
      sending_started_at DATETIME NULL, sent_at DATETIME NULL, failed_at DATETIME NULL, failure_message VARCHAR(500) NULL,
      outbound_email_id VARCHAR(64) NULL, followup_id VARCHAR(64) NULL, dismissed_by VARCHAR(64) NULL, dismissed_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY native_agent_collection_company_fingerprint_uq (company_id,fingerprint),
      KEY native_agent_collection_company_state_idx (company_id,state,updated_at),
      KEY native_agent_collection_invoice_idx (company_id,invoice_id,state), KEY native_agent_collection_mail_idx (outbound_email_id),
      CONSTRAINT native_agent_collection_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT native_agent_collection_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
      CONSTRAINT native_agent_collection_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
      CONSTRAINT native_agent_collection_updated_user_fk FOREIGN KEY (updated_by) REFERENCES users(id),
      CONSTRAINT native_agent_collection_approved_user_fk FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_collection_outbound_email_fk FOREIGN KEY (outbound_email_id) REFERENCES outbound_emails(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_collection_followup_fk FOREIGN KEY (followup_id) REFERENCES invoice_followups(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_collection_dismissed_user_fk FOREIGN KEY (dismissed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(native_ap_ar_schema_column_definitions() as $table=>$columns){
        if($table==='native_agent_policies')continue;
        $missing=array_values(array_filter(array_keys($columns),static fn(string $column):bool=>!schema_column_exists($table,$column)));
        if(!$missing)continue;
        $rowCount=(int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if($rowCount>0)throw new RuntimeException('Schema 36 repair stopped because '.$table.' contains rows but is missing: '.implode(', ',$missing).'. Restore verified structure; Tegh will not fabricate review or approval provenance.');
        foreach($missing as $column)schema_add_column($table,$column,$columns[$column]);
    }
    foreach(['native_agent_documents','native_agent_collection_drafts'] as $table)if(!schema_index_exists($table,'PRIMARY'))db()->exec("ALTER TABLE `$table` ADD PRIMARY KEY (`id`)");
    foreach([
      ['native_agent_documents','native_agent_document_company_sha_type_uq','company_id,sha256,document_type',true],
      ['native_agent_documents','native_agent_document_company_state_idx','company_id,state,updated_at',false],
      ['native_agent_documents','native_agent_document_link_idx','company_id,linked_entity_type,linked_entity_id',false],
      ['native_agent_documents','native_agent_document_task_idx','prepared_task_id',false],
      ['native_agent_collection_drafts','native_agent_collection_company_fingerprint_uq','company_id,fingerprint',true],
      ['native_agent_collection_drafts','native_agent_collection_company_state_idx','company_id,state,updated_at',false],
      ['native_agent_collection_drafts','native_agent_collection_invoice_idx','company_id,invoice_id,state',false],
      ['native_agent_collection_drafts','native_agent_collection_mail_idx','outbound_email_id',false],
    ] as [$table,$index,$columns,$unique])schema_add_index($table,$index,$columns,$unique);
    foreach(native_ap_ar_schema_foreign_keys() as $table=>$constraints)foreach($constraints as $name=>$definition)if(!schema_constraint_exists($table,$name))db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` $definition");
    schema_try_add_check('native_agent_documents','native_agent_document_size_ck','size_bytes > 0 AND size_bytes <= 10485760');
}

/** Final additive Schema 37 structure for saved Financial Analyst assumptions. */
function financial_analysis_schema_column_definitions(): array
{
    return [
        'native_agent_policies'=>[
            'financial_analyst_enabled'=>'TINYINT(1) NOT NULL DEFAULT 1',
        ],
        'financial_scenarios'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL','name'=>'VARCHAR(160) NOT NULL',
            'description'=>"VARCHAR(1000) NOT NULL DEFAULT ''",'horizon_start'=>'DATE NOT NULL','horizon_end'=>'DATE NOT NULL',
            'base_kind'=>"ENUM('open_items','budget') NOT NULL DEFAULT 'open_items'",'source_budget_id'=>'VARCHAR(64) NULL',
            'status'=>"ENUM('draft','active','archived') NOT NULL DEFAULT 'draft'",'assumptions_json'=>'LONGTEXT NOT NULL',
            'revision'=>'INT NOT NULL DEFAULT 1','scenario_hash'=>'CHAR(64) NOT NULL','created_by'=>'VARCHAR(64) NOT NULL',
            'updated_by'=>'VARCHAR(64) NOT NULL','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'financial_scenario_adjustments'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL','scenario_id'=>'VARCHAR(64) NOT NULL',
            'adjustment_date'=>'DATE NOT NULL','direction'=>"ENUM('inflow','outflow') NOT NULL",'amount_cents'=>'BIGINT NOT NULL',
            'activity'=>"ENUM('operating','investing','financing') NOT NULL DEFAULT 'operating'",'description'=>'VARCHAR(500) NOT NULL',
            'probability_bps'=>'INT NOT NULL DEFAULT 10000','created_by'=>'VARCHAR(64) NOT NULL','updated_by'=>'VARCHAR(64) NOT NULL',
            'created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP','updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
    ];
}

function financial_analysis_schema_foreign_keys(): array
{
    return [
        'financial_scenarios'=>[
            'financial_scenario_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
            'financial_scenario_budget_fk'=>'FOREIGN KEY (source_budget_id) REFERENCES budgets(id) ON DELETE RESTRICT',
            'financial_scenario_created_user_fk'=>'FOREIGN KEY (created_by) REFERENCES users(id)',
            'financial_scenario_updated_user_fk'=>'FOREIGN KEY (updated_by) REFERENCES users(id)',
        ],
        'financial_scenario_adjustments'=>[
            'financial_adjustment_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
            'financial_adjustment_scenario_fk'=>'FOREIGN KEY (scenario_id) REFERENCES financial_scenarios(id) ON DELETE CASCADE',
            'financial_adjustment_created_user_fk'=>'FOREIGN KEY (created_by) REFERENCES users(id)',
            'financial_adjustment_updated_user_fk'=>'FOREIGN KEY (updated_by) REFERENCES users(id)',
        ],
    ];
}

function financial_analysis_schema_requirements(): array
{
    $columns=financial_analysis_schema_column_definitions();
    return [
        'tables'=>['financial_scenarios','financial_scenario_adjustments'],
        'columns'=>array_map('array_keys',$columns),
        'indexes'=>[
            'financial_scenarios'=>['PRIMARY','financial_scenario_company_state_idx','financial_scenario_budget_idx'],
            'financial_scenario_adjustments'=>['PRIMARY','financial_adjustment_company_scenario_date_idx'],
        ],
        'constraints'=>array_map('array_keys',financial_analysis_schema_foreign_keys()),
    ];
}

function financial_analysis_schema_status(): array
{
    $requirements=financial_analysis_schema_requirements();$missingTables=[];$missingColumns=[];$missingIndexes=[];$missingConstraints=[];
    foreach($requirements['tables'] as $table)if(!schema_table_exists($table))$missingTables[]=$table;
    foreach($requirements['columns'] as $table=>$columns){if(!schema_table_exists($table))continue;foreach($columns as $column)if(!schema_column_exists($table,$column))$missingColumns[]=$table.'.'.$column;}
    foreach($requirements['indexes'] as $table=>$indexes){if(!schema_table_exists($table))continue;foreach($indexes as $index)if(!schema_index_exists($table,$index))$missingIndexes[]=$table.'.'.$index;}
    foreach($requirements['constraints'] as $table=>$constraints){if(!schema_table_exists($table))continue;foreach($constraints as $constraint)if(!schema_constraint_exists($table,$constraint))$missingConstraints[]=$table.'.'.$constraint;}
    if(!schema_column_exists('native_agent_policies','financial_analyst_enabled'))$missingColumns[]='native_agent_policies.financial_analyst_enabled';
    return ['ready'=>!$missingTables&&!$missingColumns&&!$missingIndexes&&!$missingConstraints,'missingTables'=>$missingTables,'missingColumns'=>$missingColumns,'missingIndexes'=>$missingIndexes,'missingConstraints'=>$missingConstraints];
}

/** Schema 37 stores assumptions only and never changes accounting records. */
function ensure_v37_financial_analysis_schema(): void
{
    ensure_v36_native_ap_ar_schema();
    if(!schema_table_exists('native_agent_policies')||!schema_table_exists('budgets')||!schema_table_exists('users'))return;
    if(!schema_column_exists('native_agent_policies','financial_analyst_enabled'))schema_add_column('native_agent_policies','financial_analyst_enabled','TINYINT(1) NOT NULL DEFAULT 1');
    db()->exec("CREATE TABLE IF NOT EXISTS financial_scenarios (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL,
      description VARCHAR(1000) NOT NULL DEFAULT '', horizon_start DATE NOT NULL, horizon_end DATE NOT NULL,
      base_kind ENUM('open_items','budget') NOT NULL DEFAULT 'open_items', source_budget_id VARCHAR(64) NULL,
      status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft', assumptions_json LONGTEXT NOT NULL,
      revision INT NOT NULL DEFAULT 1, scenario_hash CHAR(64) NOT NULL, created_by VARCHAR(64) NOT NULL, updated_by VARCHAR(64) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY financial_scenario_company_state_idx (company_id,status,updated_at), KEY financial_scenario_budget_idx (company_id,source_budget_id),
      CONSTRAINT financial_scenario_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT financial_scenario_budget_fk FOREIGN KEY (source_budget_id) REFERENCES budgets(id) ON DELETE RESTRICT,
      CONSTRAINT financial_scenario_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
      CONSTRAINT financial_scenario_updated_user_fk FOREIGN KEY (updated_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS financial_scenario_adjustments (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, scenario_id VARCHAR(64) NOT NULL,
      adjustment_date DATE NOT NULL, direction ENUM('inflow','outflow') NOT NULL, amount_cents BIGINT NOT NULL,
      activity ENUM('operating','investing','financing') NOT NULL DEFAULT 'operating', description VARCHAR(500) NOT NULL,
      probability_bps INT NOT NULL DEFAULT 10000, created_by VARCHAR(64) NOT NULL, updated_by VARCHAR(64) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY financial_adjustment_company_scenario_date_idx (company_id,scenario_id,adjustment_date),
      CONSTRAINT financial_adjustment_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT financial_adjustment_scenario_fk FOREIGN KEY (scenario_id) REFERENCES financial_scenarios(id) ON DELETE CASCADE,
      CONSTRAINT financial_adjustment_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
      CONSTRAINT financial_adjustment_updated_user_fk FOREIGN KEY (updated_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(financial_analysis_schema_column_definitions() as $table=>$columns){
        if($table==='native_agent_policies')continue;$missing=array_values(array_filter(array_keys($columns),static fn(string $column):bool=>!schema_column_exists($table,$column)));if(!$missing)continue;
        $rowCount=(int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();if($rowCount>0)throw new RuntimeException('Schema 37 repair stopped because '.$table.' contains rows but is missing: '.implode(', ',$missing).'. Restore verified structure; Tegh will not fabricate scenario provenance.');foreach($missing as $column)schema_add_column($table,$column,$columns[$column]);
    }
    foreach(['financial_scenarios','financial_scenario_adjustments'] as $table)if(!schema_index_exists($table,'PRIMARY'))db()->exec("ALTER TABLE `$table` ADD PRIMARY KEY (`id`)");
    foreach([
      ['financial_scenarios','financial_scenario_company_state_idx','company_id,status,updated_at',false],
      ['financial_scenarios','financial_scenario_budget_idx','company_id,source_budget_id',false],
      ['financial_scenario_adjustments','financial_adjustment_company_scenario_date_idx','company_id,scenario_id,adjustment_date',false],
    ] as [$table,$index,$columns,$unique])schema_add_index($table,$index,$columns,$unique);
    foreach(financial_analysis_schema_foreign_keys() as $table=>$constraints)foreach($constraints as $name=>$definition)if(!schema_constraint_exists($table,$name))db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` $definition");
    schema_try_add_check('financial_scenarios','financial_scenario_horizon_ck','horizon_start <= horizon_end AND DATEDIFF(horizon_end,horizon_start) BETWEEN 0 AND 365');
    schema_try_add_check('financial_scenarios','financial_scenario_revision_ck','revision >= 1');
    schema_try_add_check('financial_scenarios','financial_scenario_budget_base_ck',"(base_kind='budget' AND source_budget_id IS NOT NULL) OR (base_kind='open_items' AND source_budget_id IS NULL)");
    schema_try_add_check('financial_scenario_adjustments','financial_adjustment_amount_ck','amount_cents > 0');
    schema_try_add_check('financial_scenario_adjustments','financial_adjustment_probability_ck','probability_bps BETWEEN 0 AND 10000');
}

function payroll_tax_autonomy_schema_column_definitions(): array
{
    return [
        'native_agent_autonomy_policies'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL','action_id'=>'VARCHAR(120) NOT NULL',
            'enabled'=>'TINYINT(1) NOT NULL DEFAULT 0','max_severity'=>"ENUM('info','warning') NOT NULL DEFAULT 'info'",
            'daily_limit'=>'INT NOT NULL DEFAULT 0','cooldown_minutes'=>'INT NOT NULL DEFAULT 60','max_snooze_days'=>'INT NOT NULL DEFAULT 7',
            'constraints_json'=>'LONGTEXT NOT NULL','revision'=>'INT NOT NULL DEFAULT 1','policy_hash'=>'CHAR(64) NOT NULL',
            'suspended_at'=>'DATETIME NULL','suspended_by'=>'VARCHAR(64) NULL','suspension_reason'=>"VARCHAR(500) NOT NULL DEFAULT ''",
            'created_by'=>'VARCHAR(64) NOT NULL','updated_by'=>'VARCHAR(64) NOT NULL','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'native_agent_autonomy_runs'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL','policy_id'=>'VARCHAR(64) NOT NULL','finding_id'=>'VARCHAR(64) NULL',
            'requested_by'=>'VARCHAR(64) NULL','action_id'=>'VARCHAR(120) NOT NULL','idempotency_key'=>'CHAR(64) NOT NULL',
            'policy_revision'=>'INT NOT NULL','policy_hash'=>'CHAR(64) NOT NULL','source_revision_hash'=>'CHAR(64) NOT NULL',
            'status'=>"ENUM('running','completed','denied','needs_review','failed') NOT NULL DEFAULT 'running'",'result_json'=>'LONGTEXT NOT NULL',
            'error_code'=>'VARCHAR(80) NULL','error_message'=>'VARCHAR(500) NULL','started_at'=>'DATETIME NOT NULL','completed_at'=>'DATETIME NULL',
            'created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP','updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
    ];
}

function payroll_tax_autonomy_schema_foreign_keys(): array
{
    return [
        'native_agent_autonomy_policies'=>[
            'native_agent_autonomy_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
            'native_agent_autonomy_created_user_fk'=>'FOREIGN KEY (created_by) REFERENCES users(id)',
            'native_agent_autonomy_updated_user_fk'=>'FOREIGN KEY (updated_by) REFERENCES users(id)',
            'native_agent_autonomy_suspended_user_fk'=>'FOREIGN KEY (suspended_by) REFERENCES users(id) ON DELETE SET NULL',
        ],
        'native_agent_autonomy_runs'=>[
            'native_agent_autonomy_run_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
            'native_agent_autonomy_run_policy_fk'=>'FOREIGN KEY (policy_id) REFERENCES native_agent_autonomy_policies(id) ON DELETE RESTRICT',
            'native_agent_autonomy_run_finding_fk'=>'FOREIGN KEY (finding_id) REFERENCES native_agent_findings(id) ON DELETE SET NULL',
            'native_agent_autonomy_run_user_fk'=>'FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL',
        ],
    ];
}

function payroll_tax_autonomy_schema_status(): array
{
    $tables=['native_agent_autonomy_policies','native_agent_autonomy_runs'];$columns=payroll_tax_autonomy_schema_column_definitions();$foreign=payroll_tax_autonomy_schema_foreign_keys();
    $indexes=[
        'native_agent_autonomy_policies'=>['PRIMARY','native_agent_autonomy_company_action_uq','native_agent_autonomy_company_enabled_idx'],
        'native_agent_autonomy_runs'=>['PRIMARY','native_agent_autonomy_run_identity_uq','native_agent_autonomy_run_company_status_idx','native_agent_autonomy_run_policy_idx','native_agent_autonomy_run_finding_idx'],
    ];$missingTables=[];$missingColumns=[];$missingIndexes=[];$missingConstraints=[];
    foreach($tables as $table)if(!schema_table_exists($table))$missingTables[]=$table;
    foreach($columns as $table=>$definitions)if(schema_table_exists($table))foreach(array_keys($definitions) as $column)if(!schema_column_exists($table,$column))$missingColumns[]=$table.'.'.$column;
    foreach($indexes as $table=>$names)if(schema_table_exists($table))foreach($names as $name)if(!schema_index_exists($table,$name))$missingIndexes[]=$table.'.'.$name;
    foreach($foreign as $table=>$definitions)if(schema_table_exists($table))foreach(array_keys($definitions) as $name)if(!schema_constraint_exists($table,$name))$missingConstraints[]=$table.'.'.$name;
    if(!schema_column_exists('native_agent_policies','payroll_tax_agent_enabled'))$missingColumns[]='native_agent_policies.payroll_tax_agent_enabled';
    return ['ready'=>!$missingTables&&!$missingColumns&&!$missingIndexes&&!$missingConstraints,'missingTables'=>$missingTables,'missingColumns'=>$missingColumns,'missingIndexes'=>$missingIndexes,'missingConstraints'=>$missingConstraints];
}

/** Schema 38 adds only Payroll/Tax specialist policy and autonomy metadata. */
function ensure_v38_payroll_tax_autonomy_schema(): void
{
    ensure_v37_financial_analysis_schema();
    if(!schema_table_exists('native_agent_policies')||!schema_table_exists('native_agent_findings')||!schema_table_exists('users'))return;
    if(!schema_column_exists('native_agent_policies','payroll_tax_agent_enabled'))schema_add_column('native_agent_policies','payroll_tax_agent_enabled','TINYINT(1) NOT NULL DEFAULT 1');
    db()->exec("CREATE TABLE IF NOT EXISTS native_agent_autonomy_policies (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, action_id VARCHAR(120) NOT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 0, max_severity ENUM('info','warning') NOT NULL DEFAULT 'info',
      daily_limit INT NOT NULL DEFAULT 0, cooldown_minutes INT NOT NULL DEFAULT 60, max_snooze_days INT NOT NULL DEFAULT 7,
      constraints_json LONGTEXT NOT NULL, revision INT NOT NULL DEFAULT 1, policy_hash CHAR(64) NOT NULL,
      suspended_at DATETIME NULL, suspended_by VARCHAR(64) NULL, suspension_reason VARCHAR(500) NOT NULL DEFAULT '',
      created_by VARCHAR(64) NOT NULL, updated_by VARCHAR(64) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY native_agent_autonomy_company_action_uq (company_id,action_id),
      KEY native_agent_autonomy_company_enabled_idx (company_id,enabled,suspended_at),
      CONSTRAINT native_agent_autonomy_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT native_agent_autonomy_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
      CONSTRAINT native_agent_autonomy_updated_user_fk FOREIGN KEY (updated_by) REFERENCES users(id),
      CONSTRAINT native_agent_autonomy_suspended_user_fk FOREIGN KEY (suspended_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS native_agent_autonomy_runs (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, policy_id VARCHAR(64) NOT NULL, finding_id VARCHAR(64) NULL,
      requested_by VARCHAR(64) NULL, action_id VARCHAR(120) NOT NULL, idempotency_key CHAR(64) NOT NULL,
      policy_revision INT NOT NULL, policy_hash CHAR(64) NOT NULL, source_revision_hash CHAR(64) NOT NULL,
      status ENUM('running','completed','denied','needs_review','failed') NOT NULL DEFAULT 'running', result_json LONGTEXT NOT NULL,
      error_code VARCHAR(80) NULL, error_message VARCHAR(500) NULL, started_at DATETIME NOT NULL, completed_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY native_agent_autonomy_run_identity_uq (company_id,idempotency_key),
      KEY native_agent_autonomy_run_company_status_idx (company_id,status,started_at), KEY native_agent_autonomy_run_policy_idx (policy_id,started_at),
      KEY native_agent_autonomy_run_finding_idx (finding_id,started_at),
      CONSTRAINT native_agent_autonomy_run_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT native_agent_autonomy_run_policy_fk FOREIGN KEY (policy_id) REFERENCES native_agent_autonomy_policies(id) ON DELETE RESTRICT,
      CONSTRAINT native_agent_autonomy_run_finding_fk FOREIGN KEY (finding_id) REFERENCES native_agent_findings(id) ON DELETE SET NULL,
      CONSTRAINT native_agent_autonomy_run_user_fk FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(payroll_tax_autonomy_schema_column_definitions() as $table=>$definitions){$missing=array_values(array_filter(array_keys($definitions),static fn(string $column):bool=>!schema_column_exists($table,$column)));if(!$missing)continue;$count=(int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();if($count>0)throw new RuntimeException('Schema 38 repair stopped because '.$table.' contains rows but is missing: '.implode(', ',$missing).'. Restore verified structure; Tegh will not fabricate autonomy provenance.');foreach($missing as $column)schema_add_column($table,$column,$definitions[$column]);}
    foreach(['native_agent_autonomy_policies','native_agent_autonomy_runs'] as $table)if(!schema_index_exists($table,'PRIMARY'))db()->exec("ALTER TABLE `$table` ADD PRIMARY KEY (`id`)");
    foreach([
      ['native_agent_autonomy_policies','native_agent_autonomy_company_action_uq','company_id,action_id',true],
      ['native_agent_autonomy_policies','native_agent_autonomy_company_enabled_idx','company_id,enabled,suspended_at',false],
      ['native_agent_autonomy_runs','native_agent_autonomy_run_identity_uq','company_id,idempotency_key',true],
      ['native_agent_autonomy_runs','native_agent_autonomy_run_company_status_idx','company_id,status,started_at',false],
      ['native_agent_autonomy_runs','native_agent_autonomy_run_policy_idx','policy_id,started_at',false],
      ['native_agent_autonomy_runs','native_agent_autonomy_run_finding_idx','finding_id,started_at',false],
    ] as [$table,$name,$columns,$unique])schema_add_index($table,$name,$columns,$unique);
    foreach(payroll_tax_autonomy_schema_foreign_keys() as $table=>$definitions)foreach($definitions as $name=>$definition)if(!schema_constraint_exists($table,$name))db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` $definition");
    schema_try_add_check('native_agent_autonomy_policies','native_agent_autonomy_limits_ck','daily_limit BETWEEN 0 AND 100 AND cooldown_minutes BETWEEN 1 AND 10080 AND max_snooze_days BETWEEN 1 AND 30 AND revision >= 1');
    schema_try_add_check('native_agent_autonomy_runs','native_agent_autonomy_run_policy_revision_ck','policy_revision >= 1');
}

/** Final additive Schema 39 structure for delivery evidence and review records. */
function release_v39_schema_column_definitions(): array
{
    return [
        'payroll_runs'=>[
            'create_operation_key'=>'VARCHAR(64) NULL','create_request_hash'=>'CHAR(64) NULL',
        ],
        'native_agent_collection_drafts'=>[
            'message_length'=>"ENUM('concise','standard','detailed') NOT NULL DEFAULT 'standard'",
            'template_id'=>'VARCHAR(64) NULL','template_revision'=>'INT NULL','template_hash'=>'CHAR(64) NULL',
            'template_snapshot_json'=>'LONGTEXT NULL','source_facts_json'=>'LONGTEXT NULL','delivery_attempt_id'=>'VARCHAR(64) NULL',
            'delivery_outcome'=>"ENUM('none','accepted','failed','deferred','manual_review','diagnostic_warning') NOT NULL DEFAULT 'none'",
            'recovery_status'=>"ENUM('none','confirmed_sent_external','confirmed_not_received') NOT NULL DEFAULT 'none'",
            'recovery_reason'=>'VARCHAR(500) NULL','recovered_by'=>'VARCHAR(64) NULL','recovered_at'=>'DATETIME NULL',
        ],
        'outbound_email_attempts'=>[
            'id'=>'VARCHAR(64) NOT NULL','outbound_email_id'=>'VARCHAR(64) NOT NULL','collection_draft_id'=>'VARCHAR(64) NULL',
            'company_id'=>'VARCHAR(64) NULL','initiated_by'=>'VARCHAR(64) NULL','attempt_number'=>'INT NOT NULL',
            'operation_key'=>'CHAR(64) NOT NULL',
            'stage'=>"ENUM('connection','greeting','ehlo','starttls','authentication','mail_from','rcpt_to','data_initialization','final_data_acceptance','quit','sendmail') NOT NULL DEFAULT 'connection'",
            'smtp_reply_code'=>'INT NULL','enhanced_code'=>'VARCHAR(20) NULL',
            'provider_diagnostic'=>"VARCHAR(500) NOT NULL DEFAULT ''",
            'outcome'=>"ENUM('accepted','definitive_preaccept_failure','temporary_preaccept_failure','ambiguous_after_submission','diagnostic_failure_after_acceptance') NULL",
            'acceptance_certainty'=>"ENUM('yes','no','unknown') NOT NULL DEFAULT 'unknown'",
            'started_at'=>'DATETIME NOT NULL','accepted_at'=>'DATETIME NULL','completed_at'=>'DATETIME NULL',
            'created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ],
        'collection_message_templates'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL','tone'=>"ENUM('friendly','firm','final') NOT NULL",
            'message_length'=>"ENUM('concise','standard','detailed') NOT NULL DEFAULT 'standard'",
            'subject_template'=>'VARCHAR(240) NOT NULL','body_template'=>'TEXT NOT NULL',
            'signature_block'=>"VARCHAR(1000) NOT NULL DEFAULT ''",'payment_instructions'=>"VARCHAR(1000) NOT NULL DEFAULT ''",
            'revision'=>'INT NOT NULL','template_hash'=>'CHAR(64) NOT NULL','change_note'=>"VARCHAR(500) NOT NULL DEFAULT ''",
            'created_by'=>'VARCHAR(64) NOT NULL','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ],
        'month_end_attestations'=>[
            'id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NOT NULL','period_start'=>'DATE NOT NULL','period_end'=>'DATE NOT NULL',
            'evidence_hash'=>'CHAR(64) NOT NULL','evidence_revision'=>'VARCHAR(120) NOT NULL','attestation_version'=>'INT NOT NULL DEFAULT 1',
            'attestation_text'=>'VARCHAR(500) NOT NULL','note'=>"VARCHAR(500) NOT NULL DEFAULT ''",'attested_by'=>'VARCHAR(64) NOT NULL',
            'attested_at'=>'DATETIME NOT NULL','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ],
    ];
}

function release_v39_schema_foreign_keys(): array
{
    return [
        'native_agent_collection_drafts'=>[
            'native_agent_collection_recovered_user_fk'=>'FOREIGN KEY (recovered_by) REFERENCES users(id) ON DELETE SET NULL',
        ],
        'outbound_email_attempts'=>[
            'outbound_email_attempt_mail_fk'=>'FOREIGN KEY (outbound_email_id) REFERENCES outbound_emails(id) ON DELETE CASCADE',
            'outbound_email_attempt_collection_fk'=>'FOREIGN KEY (collection_draft_id) REFERENCES native_agent_collection_drafts(id) ON DELETE SET NULL',
            'outbound_email_attempt_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL',
            'outbound_email_attempt_user_fk'=>'FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL',
        ],
        'collection_message_templates'=>[
            'collection_template_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
            'collection_template_user_fk'=>'FOREIGN KEY (created_by) REFERENCES users(id)',
        ],
        'month_end_attestations'=>[
            'month_end_attestation_company_fk'=>'FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
            'month_end_attestation_user_fk'=>'FOREIGN KEY (attested_by) REFERENCES users(id)',
        ],
    ];
}

function release_v39_schema_requirements(): array
{
    return [
        'tables'=>['payroll_runs','outbound_email_attempts','collection_message_templates','month_end_attestations'],
        'columns'=>array_map('array_keys',release_v39_schema_column_definitions()),
        'indexes'=>[
            'payroll_runs'=>['payroll_runs_create_operation_uq'],
            'native_agent_collection_drafts'=>['native_agent_collection_attempt_idx'],
            'outbound_email_attempts'=>['PRIMARY','outbound_email_attempt_operation_uq','outbound_email_attempt_number_uq','outbound_email_attempt_company_outcome_idx','outbound_email_attempt_collection_idx'],
            'collection_message_templates'=>['PRIMARY','collection_template_company_tone_length_revision_uq','collection_template_company_current_idx'],
            'month_end_attestations'=>['PRIMARY','month_end_attestation_identity_uq','month_end_attestation_company_period_idx'],
        ],
        'constraints'=>array_map('array_keys',release_v39_schema_foreign_keys()),
    ];
}

function release_v39_schema_status(): array
{
    $requirements=release_v39_schema_requirements();$missingTables=[];$missingColumns=[];$missingIndexes=[];$missingConstraints=[];$invalidTableProperties=[];
    foreach($requirements['tables'] as $table)if(!schema_table_exists($table))$missingTables[]=$table;
    foreach($requirements['tables'] as $table){
        if(!schema_table_exists($table))continue;
        $stmt=db()->prepare('SELECT engine,table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$stmt->execute([$table]);$properties=$stmt->fetch();
        if(strtoupper((string)($properties['engine']??''))!=='INNODB'||strtolower((string)($properties['table_collation']??''))!=='utf8mb4_unicode_ci')$invalidTableProperties[]=$table.'.engine_or_collation';
    }
    foreach($requirements['columns'] as $table=>$columns){if(!schema_table_exists($table))continue;foreach($columns as $column)if(!schema_column_exists($table,$column))$missingColumns[]=$table.'.'.$column;}
    foreach($requirements['indexes'] as $table=>$indexes){if(!schema_table_exists($table))continue;foreach($indexes as $index)if(!schema_index_exists($table,$index))$missingIndexes[]=$table.'.'.$index;}
    foreach($requirements['constraints'] as $table=>$constraints){if(!schema_table_exists($table))continue;foreach($constraints as $constraint)if(!schema_constraint_exists($table,$constraint))$missingConstraints[]=$table.'.'.$constraint;}
    if(schema_table_exists('outbound_emails')){
        $stmt=db()->prepare("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='outbound_emails' AND column_name='status'");$stmt->execute();
        if(!str_contains(strtolower((string)$stmt->fetchColumn()),'manual_review'))$missingColumns[]='outbound_emails.status:structured';
    }
    return ['ready'=>!$missingTables&&!$missingColumns&&!$missingIndexes&&!$missingConstraints&&!$invalidTableProperties,'missingTables'=>$missingTables,'missingColumns'=>$missingColumns,'missingIndexes'=>$missingIndexes,'missingConstraints'=>$missingConstraints,'invalidTableProperties'=>$invalidTableProperties];
}

function ensure_v39_delivery_collection_close_schema(): void
{
    ensure_v38_payroll_tax_autonomy_schema();
    foreach(['companies','users','payroll_runs','outbound_emails','native_agent_collection_drafts'] as $table)if(!schema_table_exists($table))return;

    foreach(['payroll_runs','native_agent_collection_drafts'] as $table)foreach(release_v39_schema_column_definitions()[$table] as $column=>$definition)schema_add_column($table,$column,$definition);
    $stmt=db()->prepare("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='outbound_emails' AND column_name='status'");$stmt->execute();
    if(!str_contains(strtolower((string)$stmt->fetchColumn()),'manual_review'))db()->exec("ALTER TABLE outbound_emails MODIFY COLUMN status ENUM('pending','sent','failed','deferred','manual_review','sent_warning') NOT NULL DEFAULT 'pending'");

    db()->exec("CREATE TABLE IF NOT EXISTS collection_message_templates (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, tone ENUM('friendly','firm','final') NOT NULL,
      message_length ENUM('concise','standard','detailed') NOT NULL DEFAULT 'standard', subject_template VARCHAR(240) NOT NULL,
      body_template TEXT NOT NULL, signature_block VARCHAR(1000) NOT NULL DEFAULT '', payment_instructions VARCHAR(1000) NOT NULL DEFAULT '',
      revision INT NOT NULL, template_hash CHAR(64) NOT NULL, change_note VARCHAR(500) NOT NULL DEFAULT '', created_by VARCHAR(64) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY collection_template_company_tone_length_revision_uq (company_id,tone,message_length,revision),
      KEY collection_template_company_current_idx (company_id,tone,message_length,created_at),
      CONSTRAINT collection_template_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT collection_template_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS outbound_email_attempts (
      id VARCHAR(64) PRIMARY KEY, outbound_email_id VARCHAR(64) NOT NULL, collection_draft_id VARCHAR(64) NULL,
      company_id VARCHAR(64) NULL, initiated_by VARCHAR(64) NULL, attempt_number INT NOT NULL, operation_key CHAR(64) NOT NULL,
      stage ENUM('connection','greeting','ehlo','starttls','authentication','mail_from','rcpt_to','data_initialization','final_data_acceptance','quit','sendmail') NOT NULL DEFAULT 'connection',
      smtp_reply_code INT NULL, enhanced_code VARCHAR(20) NULL, provider_diagnostic VARCHAR(500) NOT NULL DEFAULT '',
      outcome ENUM('accepted','definitive_preaccept_failure','temporary_preaccept_failure','ambiguous_after_submission','diagnostic_failure_after_acceptance') NULL,
      acceptance_certainty ENUM('yes','no','unknown') NOT NULL DEFAULT 'unknown', started_at DATETIME NOT NULL,
      accepted_at DATETIME NULL, completed_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY outbound_email_attempt_operation_uq (operation_key),
      UNIQUE KEY outbound_email_attempt_number_uq (outbound_email_id,attempt_number),
      KEY outbound_email_attempt_company_outcome_idx (company_id,outcome,started_at),
      KEY outbound_email_attempt_collection_idx (collection_draft_id,started_at),
      CONSTRAINT outbound_email_attempt_mail_fk FOREIGN KEY (outbound_email_id) REFERENCES outbound_emails(id) ON DELETE CASCADE,
      CONSTRAINT outbound_email_attempt_collection_fk FOREIGN KEY (collection_draft_id) REFERENCES native_agent_collection_drafts(id) ON DELETE SET NULL,
      CONSTRAINT outbound_email_attempt_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
      CONSTRAINT outbound_email_attempt_user_fk FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS month_end_attestations (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL,
      evidence_hash CHAR(64) NOT NULL, evidence_revision VARCHAR(120) NOT NULL, attestation_version INT NOT NULL DEFAULT 1,
      attestation_text VARCHAR(500) NOT NULL, note VARCHAR(500) NOT NULL DEFAULT '', attested_by VARCHAR(64) NOT NULL,
      attested_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY month_end_attestation_identity_uq (company_id,period_start,evidence_hash,attested_by,attestation_version),
      KEY month_end_attestation_company_period_idx (company_id,period_start,period_end,attested_at),
      CONSTRAINT month_end_attestation_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT month_end_attestation_user_fk FOREIGN KEY (attested_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $definitions=release_v39_schema_column_definitions();
    foreach(['outbound_email_attempts','collection_message_templates','month_end_attestations'] as $table){
        $missing=array_values(array_filter(array_keys($definitions[$table]),static fn(string $column):bool=>!schema_column_exists($table,$column)));
        if(!$missing)continue;$rowCount=(int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if($rowCount>0)throw new RuntimeException('Schema 39 repair stopped because '.$table.' contains rows but is missing: '.implode(', ',$missing).'. Restore the verified structure; Tegh will not fabricate delivery or review provenance.');
        foreach($missing as $column)schema_add_column($table,$column,$definitions[$table][$column]);
    }
    // Foreign-key text columns must have the same character set and collation
    // as their Schema 38 parents. An interrupted empty CREATE can be normalized
    // safely; doing so on a populated partial could rewrite unverified evidence,
    // so that case remains a hard failure.
    foreach(['outbound_email_attempts','collection_message_templates','month_end_attestations'] as $table){
        $stmt=db()->prepare('SELECT engine,table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$stmt->execute([$table]);$properties=$stmt->fetch();
        if(strtoupper((string)($properties['engine']??''))==='INNODB'&&strtolower((string)($properties['table_collation']??''))==='utf8mb4_unicode_ci')continue;
        $rowCount=(int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if($rowCount>0)throw new RuntimeException('Schema 39 repair stopped because '.$table.' contains rows but has an incompatible engine or collation. Restore the verified structure; Tegh will not rewrite delivery or review provenance.');
        db()->exec("ALTER TABLE `$table` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    foreach(['outbound_email_attempts','collection_message_templates','month_end_attestations'] as $table)if(!schema_index_exists($table,'PRIMARY'))db()->exec("ALTER TABLE `$table` ADD PRIMARY KEY (`id`)");
    foreach([
      ['payroll_runs','payroll_runs_create_operation_uq','company_id,created_by,create_operation_key',true],
      ['native_agent_collection_drafts','native_agent_collection_attempt_idx','delivery_attempt_id',false],
      ['outbound_email_attempts','outbound_email_attempt_operation_uq','operation_key',true],
      ['outbound_email_attempts','outbound_email_attempt_number_uq','outbound_email_id,attempt_number',true],
      ['outbound_email_attempts','outbound_email_attempt_company_outcome_idx','company_id,outcome,started_at',false],
      ['outbound_email_attempts','outbound_email_attempt_collection_idx','collection_draft_id,started_at',false],
      ['collection_message_templates','collection_template_company_tone_length_revision_uq','company_id,tone,message_length,revision',true],
      ['collection_message_templates','collection_template_company_current_idx','company_id,tone,message_length,created_at',false],
      ['month_end_attestations','month_end_attestation_identity_uq','company_id,period_start,evidence_hash,attested_by,attestation_version',true],
      ['month_end_attestations','month_end_attestation_company_period_idx','company_id,period_start,period_end,attested_at',false],
    ] as [$table,$name,$columns,$unique])schema_add_index($table,$name,$columns,$unique);
    foreach(release_v39_schema_foreign_keys() as $table=>$constraints)foreach($constraints as $name=>$definition)if(!schema_constraint_exists($table,$name))db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` $definition");
    schema_try_add_check('outbound_email_attempts','outbound_email_attempt_number_ck','attempt_number >= 1');
    schema_try_add_check('collection_message_templates','collection_template_revision_ck','revision >= 1');
    schema_try_add_check('month_end_attestations','month_end_attestation_period_ck','period_start <= period_end AND attestation_version >= 1');

    // A predecessor `sending` row has no conclusive acceptance evidence. Mark
    // only the new additive outcome field; never infer Sent or Failed.
    db()->exec("UPDATE native_agent_collection_drafts SET delivery_outcome='manual_review' WHERE state='sending' AND delivery_outcome='none'");
    db()->exec("UPDATE outbound_emails e JOIN native_agent_collection_drafts d ON d.outbound_email_id=e.id SET e.status='manual_review' WHERE d.state='sending' AND e.status='pending'");
}

function run_nonblocking_startup_maintenance(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        // Release/data-maintenance repairs must never make an otherwise compatible
        // accounting database unavailable at sign-in. The exception is still
        // recorded for Platform Owner review.
        try {
            if (function_exists('record_system_incident')) {
                record_system_incident(
                    'A non-critical Tegh startup maintenance task could not be completed.',
                    500,
                    $code,
                    $error,
                    ['source'=>'startup_optional_maintenance'],
                );
            } else {
                error_log('Tegh optional startup maintenance failed ['.$code.']: '.$error->getMessage());
            }
        } catch (Throwable $loggingError) {
            error_log('Tegh optional startup maintenance failed ['.$code.']: '.$error->getMessage());
        }
    }
}

function core_accounting_schema_ready(): bool
{
    // Conservative readiness check used only to keep normal sign-in available
    // when an additive AI/maintenance migration fails. It does not claim that
    // every optional Tegh feature is ready.
    foreach (['app_meta','users','sessions','companies','company_members','accounts','journal_entries','journal_lines','bank_transactions','customers','vendors','invoices','bills'] as $table) {
        if (!schema_table_exists($table)) return false;
    }
    return schema_column_exists('bank_transactions','suggested_account_id')
        && schema_column_exists('invoices','is_opening_document')
        && schema_column_exists('bills','is_opening_document');
}

function current_database_schema_version(): int
{
    if (!schema_table_exists('app_meta')) return 0;
    $stmt=db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key='schema_version' LIMIT 1");
    $stmt->execute();
    return (int)($stmt->fetchColumn() ?: 0);
}

function ensure_current_schema(?array $migrationActor = null): void
{
    if (!schema_table_exists('app_meta')) return;
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_version' LIMIT 1");
    $stmt->execute();
    $version = (int)($stmt->fetchColumn() ?: 0);
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v4_data_ready' LIMIT 1");
    $stmt->execute();
    $v4DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v5_data_ready' LIMIT 1");
    $stmt->execute();
    $v5DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v6_data_ready' LIMIT 1");
    $stmt->execute();
    $v6DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v7_data_ready' LIMIT 1");
    $stmt->execute();
    $v7DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v8_data_ready' LIMIT 1");
    $stmt->execute();
    $v8DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v9_data_ready' LIMIT 1");
    $stmt->execute();
    $v9DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v10_data_ready' LIMIT 1");
    $stmt->execute();
    $v10DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v11_data_ready' LIMIT 1");
    $stmt->execute();
    $v11DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v12_data_ready' LIMIT 1");
    $stmt->execute();
    $v12DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v13_data_ready' LIMIT 1");
    $stmt->execute();
    $v13DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v14_data_ready' LIMIT 1");
    $stmt->execute();
    $v14DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v15_data_ready' LIMIT 1");
    $stmt->execute();
    $v15DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v16_data_ready' LIMIT 1");
    $stmt->execute();
    $v16DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v17_data_ready' LIMIT 1");
    $stmt->execute();
    $v17DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v18_data_ready' LIMIT 1");
    $stmt->execute();
    $v18DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v19_data_ready' LIMIT 1");
    $stmt->execute();
    $v19DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v20_data_ready' LIMIT 1");
    $stmt->execute();
    $v20DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v21_data_ready' LIMIT 1");
    $stmt->execute();
    $v21DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v34_data_ready' LIMIT 1");
    $stmt->execute();
    $v34DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v35_data_ready' LIMIT 1");
    $stmt->execute();
    $v35DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v36_data_ready' LIMIT 1");
    $stmt->execute();
    $v36DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v37_data_ready' LIMIT 1");
    $stmt->execute();
    $v37DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v38_data_ready' LIMIT 1");
    $stmt->execute();
    $v38DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $stmt = db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_v39_data_ready' LIMIT 1");
    $stmt->execute();
    $v39DataReady = (string)($stmt->fetchColumn() ?: '') === '1';
    $v4Ready = schema_table_exists('invoice_templates')
        && schema_table_exists('document_sequences')
        && schema_column_exists('customers', 'billing_address')
        && schema_column_exists('invoices', 'template_snapshot_json')
        && schema_column_exists('invoice_lines', 'foreign_unit_price_cents');
    $v5Ready = schema_table_exists('analytic_accounts')
        && schema_table_exists('budgets')
        && schema_table_exists('fixed_assets')
        && schema_table_exists('recurring_journal_templates')
        && schema_column_exists('recurring_journal_templates', 'anchor_day')
        && schema_table_exists('recurring_invoice_profiles')
        && schema_column_exists('recurring_invoice_profiles', 'anchor_day')
        && schema_table_exists('recurring_bill_profiles')
        && schema_column_exists('recurring_bill_profiles', 'anchor_day')
        && schema_table_exists('invoice_followups')
        && schema_table_exists('cash_flow_mappings');
    $v6Ready = schema_table_exists('statement_previews')
        && schema_table_exists('period_locks')
        && schema_table_exists('statutory_rates')
        && schema_table_exists('employer_levy_profiles')
        && schema_table_exists('payroll_verifications')
        && schema_table_exists('payroll_journal_drafts')
        && schema_column_exists('companies', 'module_mode')
        && schema_column_exists('accounts', 'gifi_code')
        && schema_column_exists('reconciliations', 'period_start')
        && schema_column_exists('employer_levy_rates', 'rate_decimal')
        && schema_column_exists('import_batches', 'preview_id')
        && schema_table_exists('company_deletion_log');
    $v7Ready = schema_table_exists('ai_agent_incidents')
        && schema_table_exists('ai_agent_workflow_sessions');
    $v8Ready = schema_column_exists('companies', 'test_mode')
        && schema_column_exists('companies', 'test_expires_at')
        && schema_column_exists('companies', 'test_created_by');
    $v9Ready = schema_table_exists('vouchers') && schema_table_exists('voucher_sequences')
        && schema_table_exists('notifications') && schema_table_exists('company_invitations')
        && schema_table_exists('official_rate_releases') && schema_table_exists('audit_integrity_runs')
        && schema_table_exists('backup_restore_log') && schema_column_exists('audit_log','entry_hash');
    $v10Ready = schema_table_exists('registration_attempts') && schema_table_exists('outbound_emails') && schema_table_exists('support_requests')
        && schema_table_exists('products_services') && schema_table_exists('aging_profiles')
        && schema_column_exists('users','platform_role') && schema_column_exists('users','account_plan')
        && schema_column_exists('company_invitations','delivery_status')
        && schema_column_exists('invoice_lines','product_service_id');
    $v11Ready = schema_column_exists('companies','fiscal_year_end_date')
        && schema_column_exists('companies','books_start_date')
        && schema_column_exists('company_invitations','scope')
        && schema_table_exists('opening_balance_drafts')
        && schema_table_exists('client_error_events');
    $v12Ready = schema_column_exists('vendors','address')
        && schema_column_exists('vendors','default_terms_days')
        && schema_column_exists('bills','payment_terms_days')
        && schema_table_exists('voucher_draft_payloads');
    $v13Ready = schema_table_exists('party_payments')
        && schema_column_exists('party_payments','applied_cents');
    $v14Ready = schema_column_exists('ai_agent_incidents','resolution_note')
        && schema_column_exists('ai_agent_incidents','resolved_at')
        && schema_column_exists('ai_agent_incidents','resolved_by');
    $v15Ready = schema_column_exists('users','deleted_at')
        && schema_column_exists('users','deleted_by');
    $v16Ready = schema_table_exists('platform_audit_log');
    $v17Ready = schema_column_exists('company_members','status')
        && schema_column_exists('company_members','updated_by')
        && schema_column_exists('company_members','revoked_at');
    $v18Ready = schema_table_exists('platform_incident_log');
    $v19Ready = schema_table_exists('transaction_sequences')
        && schema_table_exists('bank_match_groups') && schema_table_exists('bank_match_bank_items')
        && schema_table_exists('bank_match_book_items') && schema_table_exists('reconciliation_match_groups')
        && schema_column_exists('companies','pst_registered') && schema_column_exists('bills','gst_hst_cents')
        && schema_column_exists('bills','pst_cents') && schema_column_exists('payroll_runs','gl_status')
        && schema_column_exists('bank_transactions','reference')
        && schema_column_exists('bank_match_groups','adjustment_reversal_journal_entry_id');
    $v20Ready = schema_table_exists('password_reset_requests');
    $v21Ready = schema_column_exists('invoices', 'is_recurring')
        && schema_column_exists('bills', 'is_recurring');
    $v22Ready = schema_column_exists('invoice_templates','layout_style')
        && schema_column_exists('invoice_templates','business_name_override')
        && schema_column_exists('invoice_templates','tax_number_override')
        && schema_column_exists('invoice_templates','logo_data')
        && schema_column_exists('payroll_remittances','bank_transaction_id')
        && schema_column_nullable('payroll_remittances','journal_entry_id');
    $v23Ready = schema_column_exists('invoice_templates','font_family');
    $v24Ready = schema_column_exists('bills','product_service_id')
        && schema_index_exists('bills','bills_product_service_idx')
        && schema_constraint_exists('bills','bills_product_service_fk');
    $v25Ready = schema_column_exists('bills','quantity_milli');
    $v26Ready = schema_column_exists('expenses','gst_hst_cents')
        && schema_column_exists('expenses','pst_cents')
        && schema_column_exists('expenses','tax_entry_mode');
    $v27Ready = schema_column_exists('expenses','currency')
        && schema_column_exists('expenses','exchange_rate_micros')
        && schema_column_exists('expenses','foreign_total_cents')
        && schema_table_exists('ai_agent_action_authorizations')
        && schema_table_exists('ai_agent_preferences');
    $v28Ready = schema_column_exists('companies','reporting_framework');
    $v29Ready = schema_table_exists('ai_agent_result_sets') && schema_table_exists('ai_agent_tasks');
    $v30Ready = schema_column_exists('customers','contact_name') && schema_column_exists('customers','status')
        && schema_column_exists('vendors','contact_name') && schema_column_exists('vendors','status')
        && schema_column_exists('invoices','import_reference') && schema_index_exists('invoices','invoices_company_import_ref_uq')
        && schema_column_exists('bills','import_reference') && schema_index_exists('bills','bills_company_import_ref_uq')
        && schema_table_exists('party_opening_balances') && schema_table_exists('data_import_previews');
    $v31Ready = schema_column_exists('accounts','description') && schema_table_exists('company_system_accounts');
    $v32Ready = schema_column_exists('invoices','is_opening_document') && schema_column_exists('bills','is_opening_document') && schema_table_exists('opening_document_imports');
    $v33Ready = schema_table_exists('ai_agent_learning_events') && schema_table_exists('ai_agent_learned_rules') && schema_table_exists('ai_agent_improvements') && schema_table_exists('ai_agent_behavior_versions') && schema_table_exists('ai_agent_review_runs');
    $v34Ready = schema_table_exists('ai_agent_conversations')
        && schema_table_exists('ai_agent_memories')
        && schema_table_exists('ai_agent_memory_history')
        && schema_table_exists('ai_agent_plans')
        && schema_column_exists('ai_agent_action_authorizations','conversation_id')
        && schema_column_exists('ai_agent_action_authorizations','operation_key')
        && schema_column_exists('ai_agent_tasks','conversation_id')
        && schema_column_exists('ai_agent_result_sets','conversation_id');
    $v35Ready = native_agent_schema_status()['ready'];
    $v36Ready = native_ap_ar_schema_status()['ready'];
    $v37Ready = financial_analysis_schema_status()['ready'];
    $v38Ready = payroll_tax_autonomy_schema_status()['ready'];
    $v39Ready = release_v39_schema_status()['ready'];
    $throughV17Ready = $v4Ready && $v4DataReady && $v5Ready && $v5DataReady
        && $v6Ready && $v6DataReady && $v7Ready && $v7DataReady
        && $v8Ready && $v8DataReady && $v9Ready && $v9DataReady
        && $v10Ready && $v10DataReady && $v11Ready && $v11DataReady
        && $v12Ready && $v12DataReady && $v13Ready && $v13DataReady
        && $v14Ready && $v14DataReady && $v15Ready && $v15DataReady
        && $v16Ready && $v16DataReady && $v17Ready && $v17DataReady;
    if ($version >= 39 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready && $v30Ready && $v31Ready && $v32Ready && $v33Ready && $v34Ready && $v35Ready && $v36Ready && $v37Ready && $v38Ready) {
        if($v39Ready){
        if (!$v34DataReady || !$v35DataReady || !$v36DataReady || !$v37DataReady || !$v38DataReady || !$v39DataReady) {
            db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v34_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
            db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v35_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
            db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v36_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
            db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v37_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
            db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v38_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
            db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v39_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        }
        run_nonblocking_startup_maintenance('ensure_v4140_gifi_mapping_repair','startup_gifi_repair_failed');
        run_nonblocking_startup_maintenance('ensure_default_invoice_templates','startup_invoice_template_seed_failed');
        return;
        }
    }
    if ($version >= 38 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready && $v30Ready && $v31Ready && $v32Ready && $v33Ready && $v34Ready && $v35Ready && $v36Ready && $v37Ready && $v38Ready) {
        ensure_v39_delivery_collection_close_schema();
        $v39Status=release_v39_schema_status();
        if(!$v39Status['ready'])throw new RuntimeException('Schema 39 delivery and review storage is incomplete: '.json_encode($v39Status,JSON_UNESCAPED_SLASHES));
        foreach(['34','35','36','37','38','39'] as $readyVersion)db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v{$readyVersion}_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','39') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        run_nonblocking_startup_maintenance('ensure_v4140_gifi_mapping_repair','startup_gifi_repair_failed');
        run_nonblocking_startup_maintenance('ensure_default_invoice_templates','startup_invoice_template_seed_failed');
        return;
    }
    if ($version >= 37 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready && $v30Ready && $v31Ready && $v32Ready && $v33Ready && $v34Ready && $v35Ready && $v36Ready && $v37Ready) {
        ensure_v38_payroll_tax_autonomy_schema();
        $v38Status=payroll_tax_autonomy_schema_status();
        if(!$v38Status['ready'])throw new RuntimeException('Schema 38 Payroll/Tax Agent storage is incomplete: '.json_encode($v38Status,JSON_UNESCAPED_SLASHES));
        foreach(['34','35','36','37','38'] as $readyVersion)db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v{$readyVersion}_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','38') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        run_nonblocking_startup_maintenance('ensure_v4140_gifi_mapping_repair','startup_gifi_repair_failed');
        run_nonblocking_startup_maintenance('ensure_default_invoice_templates','startup_invoice_template_seed_failed');
        return;
    }
    if ($version >= 36 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready && $v30Ready && $v31Ready && $v32Ready && $v33Ready && $v34Ready && $v35Ready && $v36Ready) {
        ensure_v37_financial_analysis_schema();
        $v37Status=financial_analysis_schema_status();
        if(!$v37Status['ready'])throw new RuntimeException('Schema 37 Financial Analyst storage is incomplete: '.json_encode($v37Status,JSON_UNESCAPED_SLASHES));
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v34_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v35_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v36_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v37_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','37') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        run_nonblocking_startup_maintenance('ensure_v4140_gifi_mapping_repair','startup_gifi_repair_failed');
        run_nonblocking_startup_maintenance('ensure_default_invoice_templates','startup_invoice_template_seed_failed');
        return;
    }
    if ($version >= 36 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready && $v30Ready && $v31Ready && $v32Ready && $v33Ready && $v34Ready && $v35Ready && $v36Ready) {
        if (!$v34DataReady || !$v35DataReady || !$v36DataReady) {
            db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v34_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
            db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v35_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
            db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v36_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        }
        run_nonblocking_startup_maintenance('ensure_v4140_gifi_mapping_repair','startup_gifi_repair_failed');
        run_nonblocking_startup_maintenance('ensure_default_invoice_templates','startup_invoice_template_seed_failed');
        return;
    }
    if ($version >= 35 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready && $v30Ready && $v31Ready && $v32Ready && $v33Ready && $v34Ready && $v35Ready) {
        ensure_v36_native_ap_ar_schema();
        $v36Status=native_ap_ar_schema_status();
        if(!$v36Status['ready'])throw new RuntimeException('Schema 36 AP/AR storage is incomplete: '.json_encode($v36Status,JSON_UNESCAPED_SLASHES));
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v34_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v35_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v36_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','36') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        run_nonblocking_startup_maintenance('ensure_v4140_gifi_mapping_repair','startup_gifi_repair_failed');
        run_nonblocking_startup_maintenance('ensure_default_invoice_templates','startup_invoice_template_seed_failed');
        return;
    }
    if ($version >= 34 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready && $v30Ready && $v31Ready && $v32Ready && $v33Ready && $v34Ready) {
        ensure_v35_native_agent_schema();
        $v35Status=native_agent_schema_status();
        if(!$v35Status['ready'])throw new RuntimeException('Schema 35 native-agent storage is incomplete: '.json_encode($v35Status,JSON_UNESCAPED_SLASHES));
        ensure_v36_native_ap_ar_schema();
        $v36Status=native_ap_ar_schema_status();
        if(!$v36Status['ready'])throw new RuntimeException('Schema 36 AP/AR storage is incomplete: '.json_encode($v36Status,JSON_UNESCAPED_SLASHES));
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v34_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v35_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v36_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','36') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        run_nonblocking_startup_maintenance('ensure_v4140_gifi_mapping_repair','startup_gifi_repair_failed');
        run_nonblocking_startup_maintenance('ensure_default_invoice_templates','startup_invoice_template_seed_failed');
        return;
    }
    if ($version >= 33 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready && $v30Ready && $v31Ready && $v32Ready && $v33Ready) {
        // Tegh 4.3 introduced Schema 34. Earlier builds incorrectly returned from
        // the Schema 33 fast path before creating the durable human-agent tables.
        // Repair that upgrade deterministically and idempotently here.
        ensure_v34_human_agent_schema();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','34') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v34_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        run_nonblocking_startup_maintenance('ensure_v4140_gifi_mapping_repair','startup_gifi_repair_failed');
        run_nonblocking_startup_maintenance('ensure_default_invoice_templates','startup_invoice_template_seed_failed');
        return;
    }
    if ($version >= 32 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready && $v30Ready && $v31Ready && $v32Ready) {
        ensure_v33_ai_learning_schema();
        ensure_v4140_gifi_mapping_repair();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','33') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v33_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 31 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready && $v30Ready && $v31Ready) {
        ensure_v32_opening_document_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','32') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v32_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 30 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready && $v30Ready) {
        ensure_v31_accounting_import_schema($migrationActor);
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','31') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v31_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 29 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready && $v29Ready) {
        ensure_v30_master_import_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','30') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v30_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 28 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready && $v28Ready) {
        ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 27 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready && $v27Ready) {
        ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v28_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 26 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready && $v26Ready) {
        ensure_v27_controls_currency_ai_schema();
    ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v27_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 25 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready && $v25Ready) {
        ensure_v26_expense_tax_schema();
        ensure_v27_controls_currency_ai_schema();
    ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
            db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v26_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 24 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready && $v24Ready) {
        ensure_v25_bill_quantity_schema();
        ensure_v26_expense_tax_schema();
        ensure_v27_controls_currency_ai_schema();
    ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v25_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v26_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 23 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready && $v23Ready) {
        ensure_v24_bill_product_service_schema();
        ensure_v25_bill_quantity_schema();
        ensure_v26_expense_tax_schema();
        ensure_v27_controls_currency_ai_schema();
    ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v24_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 22 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady && $v22Ready) {
        ensure_v23_invoice_template_font_schema();
        ensure_v24_bill_product_service_schema();
        ensure_v25_bill_quantity_schema();
        ensure_v26_expense_tax_schema();
        ensure_v27_controls_currency_ai_schema();
    ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v23_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v24_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 21 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady
        && $v20Ready && $v20DataReady && $v21Ready && $v21DataReady) {
        ensure_v22_invoice_template_and_payroll_match_schema();
        ensure_v23_invoice_template_font_schema();
        ensure_v24_bill_product_service_schema();
        ensure_v25_bill_quantity_schema();
        ensure_v26_expense_tax_schema();
        ensure_v27_controls_currency_ai_schema();
    ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v22_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v23_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v24_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 20 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady && $v20Ready && $v20DataReady) {
        ensure_v21_recurring_document_flags();
        ensure_v22_invoice_template_and_payroll_match_schema();
        ensure_v23_invoice_template_font_schema();
        ensure_v24_bill_product_service_schema();
        ensure_v25_bill_quantity_schema();
        ensure_v26_expense_tax_schema();
        ensure_v27_controls_currency_ai_schema();
    ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v21_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 19 && $throughV17Ready && $v18Ready && $v18DataReady && $v19Ready && $v19DataReady) {
        ensure_v20_release_audit_schema();
        ensure_v21_recurring_document_flags();
        ensure_v22_invoice_template_and_payroll_match_schema();
        ensure_v23_invoice_template_font_schema();
        ensure_v24_bill_product_service_schema();
        ensure_v25_bill_quantity_schema();
        ensure_v26_expense_tax_schema();
        ensure_v27_controls_currency_ai_schema();
    ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v20_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v21_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 18 && $throughV17Ready && $v18Ready && $v18DataReady) {
        ensure_v19_accounting_integrity_schema();
        ensure_v20_release_audit_schema();
        ensure_v21_recurring_document_flags();
        ensure_v22_invoice_template_and_payroll_match_schema();
        ensure_v23_invoice_template_font_schema();
        ensure_v24_bill_product_service_schema();
        ensure_v25_bill_quantity_schema();
        ensure_v26_expense_tax_schema();
        ensure_v27_controls_currency_ai_schema();
    ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v19_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v20_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v21_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    if ($version >= 17 && $throughV17Ready) {
        ensure_v18_system_incident_schema();
        ensure_v19_accounting_integrity_schema();
        ensure_v20_release_audit_schema();
        ensure_v21_recurring_document_flags();
        ensure_v22_invoice_template_and_payroll_match_schema();
        ensure_v23_invoice_template_font_schema();
        ensure_v24_bill_product_service_schema();
        ensure_v25_bill_quantity_schema();
        ensure_v26_expense_tax_schema();
        ensure_v27_controls_currency_ai_schema();
    ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
        ensure_default_invoice_templates();
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','29') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v18_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v19_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v20_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v21_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        return;
    }
    // Schema 17: company-level multi-user access. Existing bookkeeper roles
    // become Editor. Membership rows are retained when access is suspended or
    // revoked so historical accounting attribution remains intact.
    if (schema_table_exists('company_members')) {
        db()->exec("ALTER TABLE company_members MODIFY role ENUM('owner','admin','editor','bookkeeper','viewer') NOT NULL DEFAULT 'owner'");
        db()->exec("UPDATE company_members SET role='editor' WHERE role='bookkeeper'");
        db()->exec("ALTER TABLE company_members MODIFY role ENUM('owner','admin','editor','viewer') NOT NULL DEFAULT 'owner'");
        schema_add_column('company_members','status',"ENUM('active','suspended','revoked') NOT NULL DEFAULT 'active' AFTER `role`");
        schema_add_column('company_members','status_reason','VARCHAR(500) NULL AFTER `status`');
        schema_add_column('company_members','updated_by','VARCHAR(64) NULL AFTER `status_reason`');
        schema_add_column('company_members','suspended_at','DATETIME NULL AFTER `updated_by`');
        schema_add_column('company_members','revoked_at','DATETIME NULL AFTER `suspended_at`');
        schema_add_column('company_members','updated_at','TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');
        schema_add_index('company_members','company_members_user_status_idx','user_id,status,company_id');
    }
    if (schema_table_exists('company_invitations')) {
        db()->exec("ALTER TABLE company_invitations MODIFY role ENUM('owner','admin','editor','bookkeeper','viewer') NOT NULL DEFAULT 'editor'");
        db()->exec("UPDATE company_invitations SET role='editor' WHERE role='bookkeeper'");
        db()->exec("ALTER TABLE company_invitations MODIFY role ENUM('owner','admin','editor','viewer') NOT NULL DEFAULT 'editor'");
        if (schema_column_exists('company_invitations','scope')) db()->exec("ALTER TABLE company_invitations MODIFY scope ENUM('workspace','company') NOT NULL DEFAULT 'company'");
    }

    // Schema 10: public self-service accounts, direct email delivery,
    // support access, products/services and configurable ageing.
    schema_add_column('users', 'platform_role', "ENUM('platform_owner','member') NOT NULL DEFAULT 'member' AFTER `active`");
    schema_add_column('users', 'deleted_at', "DATETIME NULL AFTER `last_login_at`");
    schema_add_column('users', 'deleted_by', "VARCHAR(64) NULL AFTER `deleted_at`");
    schema_add_column('users', 'account_plan', "ENUM('free_preview') NOT NULL DEFAULT 'free_preview' AFTER `platform_role`");
    schema_add_column('users', 'signup_source', "ENUM('initial_setup','public_signup','invitation') NOT NULL DEFAULT 'public_signup' AFTER `account_plan`");
    schema_add_column('users', 'terms_accepted_at', 'DATETIME NULL AFTER `signup_source`');
    schema_add_column('company_invitations', 'outbound_email_id', 'VARCHAR(64) NULL AFTER `accepted_at`');
    schema_add_column('company_invitations', 'delivery_status', "ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending' AFTER `outbound_email_id`");
    schema_add_column('company_invitations', 'delivery_error', 'VARCHAR(500) NULL AFTER `delivery_status`');
    schema_add_column('company_invitations', 'last_sent_at', 'DATETIME NULL AFTER `delivery_error`');
    schema_add_column('company_invitations', 'scope', "ENUM('workspace','company') NOT NULL DEFAULT 'company' AFTER `email`");
    schema_add_column('companies', 'fiscal_year_end_date', 'DATE NULL AFTER `fiscal_year_end`');
    schema_add_column('companies', 'books_start_date', 'DATE NULL AFTER `fiscal_year_end_date`');
    schema_add_column('vendors', 'address', 'VARCHAR(500) NULL AFTER `email`');
    schema_add_column('vendors', 'default_terms_days', 'INT NOT NULL DEFAULT 30 AFTER `address`');
    schema_add_column('bills', 'payment_terms_days', 'INT NOT NULL DEFAULT 30 AFTER `category_account_id`');
    schema_add_column('invoice_lines', 'product_service_id', 'VARCHAR(64) NULL AFTER `invoice_id`');
    schema_add_column('invoice_lines', 'income_account_id', 'VARCHAR(64) NULL AFTER `product_service_id`');

    db()->exec("CREATE TABLE IF NOT EXISTS registration_attempts (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      email_hash CHAR(64) NOT NULL, ip_hash CHAR(64) NOT NULL,
      successful TINYINT(1) NOT NULL DEFAULT 0,
      attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY registration_attempts_ip_idx (ip_hash,attempted_at),
      KEY registration_attempts_email_idx (email_hash,attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS outbound_emails (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NULL, recipient VARCHAR(254) NOT NULL,
      template_key VARCHAR(80) NOT NULL, subject VARCHAR(240) NOT NULL,
      status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending', attempt_count INT NOT NULL DEFAULT 0,
      provider_message VARCHAR(500) NULL, created_by VARCHAR(64) NULL, sent_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY outbound_emails_company_idx (company_id,created_at), KEY outbound_emails_recipient_idx (recipient,created_at),
      CONSTRAINT outbound_emails_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
      CONSTRAINT outbound_emails_user_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS support_requests (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, requested_by VARCHAR(64) NOT NULL,
      subject VARCHAR(200) NOT NULL, message VARCHAR(2000) NOT NULL,
      status ENUM('pending','in_progress','resolved','closed') NOT NULL DEFAULT 'pending',
      grant_access TINYINT(1) NOT NULL DEFAULT 0, access_expires_at DATETIME NULL, assigned_to VARCHAR(64) NULL,
      resolution_note VARCHAR(2000) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, resolved_at DATETIME NULL,
      KEY support_requests_company_idx (company_id,status,created_at), KEY support_requests_assignee_idx (assigned_to,status),
      CONSTRAINT support_requests_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT support_requests_requester_fk FOREIGN KEY (requested_by) REFERENCES users(id),
      CONSTRAINT support_requests_assignee_fk FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS products_services (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, kind ENUM('product','service') NOT NULL DEFAULT 'service',
      code VARCHAR(60) NULL, name VARCHAR(180) NOT NULL, description VARCHAR(500) NULL,
      unit_price_cents BIGINT NOT NULL DEFAULT 0, taxable TINYINT(1) NOT NULL DEFAULT 1,
      income_account_id VARCHAR(64) NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(64) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY products_services_company_code_uq (company_id,code), KEY products_services_company_idx (company_id,active,name),
      CONSTRAINT products_services_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT products_services_income_fk FOREIGN KEY (income_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
      CONSTRAINT products_services_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS aging_profiles (
      company_id VARCHAR(64) PRIMARY KEY, ar_cutoff_1 INT NOT NULL DEFAULT 30, ar_cutoff_2 INT NOT NULL DEFAULT 60, ar_cutoff_3 INT NOT NULL DEFAULT 90,
      ap_cutoff_1 INT NOT NULL DEFAULT 30, ap_cutoff_2 INT NOT NULL DEFAULT 60, ap_cutoff_3 INT NOT NULL DEFAULT 90,
      updated_by VARCHAR(64) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT aging_profiles_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT aging_profiles_user_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS opening_balance_drafts (
      company_id VARCHAR(64) NOT NULL, account_id VARCHAR(64) NOT NULL,
      amount_cents BIGINT NOT NULL DEFAULT 0, updated_by VARCHAR(64) NOT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (company_id,account_id),
      CONSTRAINT opening_balance_drafts_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT opening_balance_drafts_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
      CONSTRAINT opening_balance_drafts_user_fk FOREIGN KEY (updated_by) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS client_error_events (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL,
      kind VARCHAR(40) NOT NULL, route VARCHAR(180) NULL, method VARCHAR(12) NULL,
      status INT NULL, code VARCHAR(100) NULL, message VARCHAR(1000) NOT NULL,
      request_id VARCHAR(100) NULL, duration_ms INT NULL, page_path VARCHAR(500) NULL,
      occurred_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY client_error_events_company_idx (company_id,created_at),
      KEY client_error_events_user_idx (user_id,created_at),
      CONSTRAINT client_error_events_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT client_error_events_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("UPDATE users SET platform_role='platform_owner', signup_source='initial_setup' WHERE id=(SELECT id FROM (SELECT id FROM users ORDER BY created_at,id LIMIT 1) x) AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE platform_role='platform_owner' LIMIT 1) y)");

    // Invitations sent before v1.9.3 did not distinguish an independent
    // workspace from access to the sender's company. Preserve owners and
    // active collaborators, but detach unused invitation-created test users.
    if (!$v11DataReady) {
        db()->exec("UPDATE company_invitations ci
            LEFT JOIN users u ON u.id=ci.accepted_by
            SET ci.scope=CASE
              WHEN ci.role='owner' THEN 'company'
              WHEN u.id IS NOT NULL AND u.signup_source<>'invitation' THEN 'company'
              WHEN EXISTS (
                SELECT 1 FROM audit_log al
                WHERE al.company_id=ci.company_id AND al.actor_user_id=ci.accepted_by
                  AND al.action<>'company.invitation_accepted'
              ) THEN 'company'
              ELSE 'workspace'
            END");
        db()->exec("DELETE cm FROM company_members cm
            JOIN company_invitations ci ON ci.company_id=cm.company_id AND ci.accepted_by=cm.user_id
            JOIN users u ON u.id=cm.user_id
            JOIN (
              SELECT user_id,COUNT(*) AS membership_count
              FROM company_members GROUP BY user_id
            ) membership_totals ON membership_totals.user_id=cm.user_id
            WHERE ci.scope='workspace' AND ci.status='accepted'
              AND u.signup_source='invitation' AND cm.role<>'owner'
              AND membership_totals.membership_count=1
              AND NOT EXISTS (
                SELECT 1 FROM audit_log al
                WHERE al.company_id=cm.company_id AND al.actor_user_id=cm.user_id
                  AND al.action<>'company.invitation_accepted'
              )");
    }

    schema_add_column('companies', 'accounting_basis', "ENUM('accrual','cash') NOT NULL DEFAULT 'accrual' AFTER `currency`");

    schema_add_column('invoices', 'currency', "CHAR(3) NOT NULL DEFAULT 'CAD' AFTER `message`");
    schema_add_column('invoices', 'exchange_rate_micros', 'BIGINT NOT NULL DEFAULT 1000000 AFTER `currency`');
    schema_add_column('invoices', 'foreign_subtotal_cents', 'BIGINT NOT NULL DEFAULT 0 AFTER `exchange_rate_micros`');
    schema_add_column('invoices', 'foreign_tax_cents', 'BIGINT NOT NULL DEFAULT 0 AFTER `foreign_subtotal_cents`');
    schema_add_column('invoices', 'foreign_total_cents', 'BIGINT NOT NULL DEFAULT 0 AFTER `foreign_tax_cents`');
    schema_add_column('invoices', 'foreign_balance_cents', 'BIGINT NOT NULL DEFAULT 0 AFTER `foreign_total_cents`');

    schema_add_column('import_batches', 'currency', "CHAR(3) NOT NULL DEFAULT 'CAD' AFTER `duplicate_count`");
    schema_add_column('import_batches', 'exchange_rate_micros', 'BIGINT NOT NULL DEFAULT 1000000 AFTER `currency`');

    schema_add_column('bank_transactions', 'currency', "CHAR(3) NOT NULL DEFAULT 'CAD' AFTER `amount_cents`");
    schema_add_column('bank_transactions', 'foreign_amount_cents', 'BIGINT NOT NULL DEFAULT 0 AFTER `currency`');
    schema_add_column('bank_transactions', 'exchange_rate_micros', 'BIGINT NOT NULL DEFAULT 1000000 AFTER `foreign_amount_cents`');

    db()->exec("UPDATE invoices i JOIN companies c ON c.id = i.company_id
        SET i.currency = c.currency, i.exchange_rate_micros = 1000000,
            i.foreign_subtotal_cents = i.subtotal_cents, i.foreign_tax_cents = i.tax_cents,
            i.foreign_total_cents = i.total_cents, i.foreign_balance_cents = i.balance_cents
        WHERE i.foreign_total_cents = 0");
    db()->exec("UPDATE import_batches ib JOIN bank_accounts ba ON ba.id = ib.bank_account_id
        SET ib.currency = ba.currency, ib.exchange_rate_micros = 1000000");
    db()->exec("UPDATE bank_transactions bt JOIN bank_accounts ba ON ba.id = bt.bank_account_id
        SET bt.currency = ba.currency, bt.foreign_amount_cents = bt.amount_cents, bt.exchange_rate_micros = 1000000
        WHERE bt.foreign_amount_cents = 0");

    schema_add_column('companies', 'module_mode', "ENUM('accounting','payroll','both') NOT NULL DEFAULT 'both' AFTER `accounting_basis`");
    schema_add_column('companies', 'payroll_posting_mode', "ENUM('automatic','draft','none') NOT NULL DEFAULT 'draft' AFTER `module_mode`");
    schema_add_column('companies', 'tax_reporting_profile', "ENUM('gifi','t2125','none') NOT NULL DEFAULT 'none' AFTER `payroll_posting_mode`");
    schema_add_column('companies', 'test_mode', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `tax_rate_bps`');
    schema_add_column('companies', 'test_expires_at', 'DATETIME NULL AFTER `test_mode`');
    schema_add_column('companies', 'test_created_by', 'VARCHAR(64) NULL AFTER `test_expires_at`');
    schema_add_column('accounts', 'gifi_code', 'VARCHAR(10) NULL AFTER `is_control`');
    schema_add_column('accounts', 't2125_line', 'VARCHAR(30) NULL AFTER `gifi_code`');
    schema_add_column('accounts', 'reporting_group', 'VARCHAR(120) NULL AFTER `t2125_line`');
    schema_add_column('accounts', 'expense_category', 'VARCHAR(120) NULL AFTER `reporting_group`');
    schema_add_column('import_batches', 'preview_id', 'VARCHAR(64) NULL AFTER `source_path`');
    schema_add_column('reconciliations', 'period_start', 'DATE NULL AFTER `bank_account_id`');
    schema_add_column('reconciliations', 'notes', "VARCHAR(1000) NOT NULL DEFAULT '' AFTER `difference_cents`");
    schema_add_column('reconciliations', 'prepared_by', 'VARCHAR(64) NULL AFTER `status`');
    schema_add_column('reconciliations', 'reviewed_by', 'VARCHAR(64) NULL AFTER `prepared_by`');
    schema_add_column('reconciliations', 'reviewed_at', 'DATETIME NULL AFTER `reviewed_by`');
    schema_add_column('reconciliations', 'reopened_by', 'VARCHAR(64) NULL AFTER `reviewed_at`');
    schema_add_column('reconciliations', 'reopened_at', 'DATETIME NULL AFTER `reopened_by`');
    schema_add_column('reconciliations', 'reopen_reason', 'VARCHAR(1000) NULL AFTER `reopened_at`');
    schema_add_column('payroll_runs', 'rate_snapshot_json', 'LONGTEXT NULL AFTER `calculation_version`');
    schema_add_column('payroll_runs', 'employer_levy_cents', 'BIGINT NOT NULL DEFAULT 0 AFTER `employer_ei_cents`');
    schema_add_column('payroll_run_items', 'verification_status', "ENUM('unverified','formula_verified','official_verified','difference','manual_override') NOT NULL DEFAULT 'unverified' AFTER `verified_income_tax_cents`");

    // Create the new schema-6 tables only after all referenced legacy columns exist.
    // CREATE TABLE IF NOT EXISTS does not modify existing tables, so ALTERs remain below.
    schema_add_column('audit_log','previous_hash',"CHAR(64) NOT NULL DEFAULT '' AFTER `metadata_json`");
    schema_add_column('audit_log','entry_hash',"CHAR(64) NOT NULL DEFAULT '' AFTER `previous_hash`");
    schema_add_column('audit_log','request_id',"VARCHAR(80) NOT NULL DEFAULT '' AFTER `entry_hash`");
    schema_add_column('audit_log','ip_hash',"CHAR(64) NOT NULL DEFAULT '' AFTER `request_id`");
    schema_add_column('audit_log','user_agent_hash',"CHAR(64) NOT NULL DEFAULT '' AFTER `ip_hash`");
    initialize_schema();
    schema_add_column('ai_agent_incidents', 'resolution_note', 'TEXT NULL AFTER `error_count`');
    schema_add_column('ai_agent_incidents', 'resolved_at', 'DATETIME NULL AFTER `resolution_note`');
    schema_add_column('ai_agent_incidents', 'resolved_by', 'VARCHAR(64) NULL AFTER `resolved_at`');
    schema_add_column('party_payments', 'applied_cents', 'BIGINT NOT NULL DEFAULT 0 AFTER `amount_cents`');
    db()->exec("UPDATE party_payments SET applied_cents=amount_cents WHERE applied_cents=0");
    if (!schema_column_nullable('party_payments', 'document_id')) {
        db()->exec('ALTER TABLE party_payments MODIFY document_id VARCHAR(64) NULL');
    }
    schema_add_column('employer_levy_rates', 'rate_decimal', 'DECIMAL(12,8) NULL AFTER `rate_bps`');
    if (schema_column_exists('payroll_remittances','journal_entry_id') && !schema_column_nullable('payroll_remittances','journal_entry_id')) {
        db()->exec('ALTER TABLE payroll_remittances MODIFY journal_entry_id VARCHAR(64) NULL');
    }

    schema_add_column('recurring_journal_templates', 'anchor_day', "TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `frequency`");
    schema_add_column('recurring_invoice_profiles', 'anchor_day', "TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `frequency`");
    db()->exec("UPDATE recurring_journal_templates SET anchor_day = DAY(next_run_date) WHERE anchor_day = 1 AND DAY(next_run_date) <> 1");
    db()->exec("UPDATE recurring_invoice_profiles SET anchor_day = DAY(next_invoice_date) WHERE anchor_day = 1 AND DAY(next_invoice_date) <> 1");

    schema_add_column('customers', 'phone', 'VARCHAR(60) NULL AFTER `email`');
    schema_add_column('customers', 'billing_address', 'VARCHAR(500) NULL AFTER `phone`');
    schema_add_column('invoices', 'purchase_order', 'VARCHAR(80) NULL AFTER `foreign_balance_cents`');
    schema_add_column('invoices', 'template_id', 'VARCHAR(64) NULL AFTER `purchase_order`');
    schema_add_column('invoices', 'template_snapshot_json', 'LONGTEXT NULL AFTER `template_id`');
    schema_add_column('invoices', 'customer_snapshot_json', 'LONGTEXT NULL AFTER `template_snapshot_json`');
    schema_add_column('invoice_lines', 'foreign_unit_price_cents', 'BIGINT NOT NULL DEFAULT 0 AFTER `tax_cents`');
    schema_add_column('invoice_lines', 'foreign_amount_cents', 'BIGINT NOT NULL DEFAULT 0 AFTER `foreign_unit_price_cents`');
    schema_add_column('invoice_lines', 'foreign_tax_cents', 'BIGINT NOT NULL DEFAULT 0 AFTER `foreign_amount_cents`');
    schema_add_column('invoice_lines', 'sort_order', 'INT NOT NULL DEFAULT 0 AFTER `foreign_tax_cents`');

    if (!schema_constraint_exists('invoices', 'invoices_template_fk')) {
        db()->exec('ALTER TABLE invoices ADD CONSTRAINT invoices_template_fk
            FOREIGN KEY (template_id) REFERENCES invoice_templates(id) ON DELETE SET NULL');
    }

    db()->exec("UPDATE invoice_lines il JOIN invoices i ON i.id = il.invoice_id
        SET il.foreign_unit_price_cents = ROUND(il.unit_price_cents * 1000000 / GREATEST(i.exchange_rate_micros, 1)),
            il.foreign_amount_cents = CASE WHEN i.subtotal_cents = 0 THEN 0 ELSE ROUND(i.foreign_subtotal_cents * il.amount_cents / i.subtotal_cents) END,
            il.foreign_tax_cents = CASE WHEN i.tax_cents = 0 THEN 0 ELSE ROUND(i.foreign_tax_cents * il.tax_cents / i.tax_cents) END
        WHERE il.foreign_unit_price_cents = 0 AND il.amount_cents > 0");

    ensure_v22_invoice_template_and_payroll_match_schema();
    ensure_v23_invoice_template_font_schema();
    ensure_v24_bill_product_service_schema();
    ensure_v25_bill_quantity_schema();
    ensure_v26_expense_tax_schema();
    ensure_default_invoice_templates();
    db()->exec("UPDATE invoices i
        JOIN invoice_templates t ON t.company_id = i.company_id AND t.is_default = 1 AND t.active = 1
        SET i.template_id = t.id WHERE i.template_id IS NULL");
    backfill_invoice_v4_snapshots();

    db()->exec("INSERT INTO document_sequences (company_id, document_type, next_number)
        SELECT c.id, 'invoice', GREATEST(1001, COALESCE(MAX(CASE WHEN i.number LIKE 'INV-%' THEN CAST(SUBSTRING(i.number, 5) AS UNSIGNED) END), 1000) + 1)
        FROM companies c LEFT JOIN invoices i ON i.company_id = c.id GROUP BY c.id
        ON DUPLICATE KEY UPDATE next_number = GREATEST(document_sequences.next_number, VALUES(next_number))");

    db()->exec("INSERT INTO company_currencies (company_id, currency_code, rate_to_base_micros, rate_date, active)
        SELECT id, currency, 1000000, CURRENT_DATE, 1 FROM companies
        ON DUPLICATE KEY UPDATE rate_to_base_micros = IF(currency_code = VALUES(currency_code), 1000000, rate_to_base_micros), active = 1");
    db()->exec("INSERT INTO accounting_controls (company_id)
        SELECT id FROM companies ON DUPLICATE KEY UPDATE company_id = VALUES(company_id)");
    ensure_fx_accounts();
    ensure_advanced_accounts();
    ensure_v6_seed_data();
    seed_voucher_sequence_rows();
    backfill_existing_vouchers();
    backfill_audit_hash_chain();
    db()->exec("UPDATE companies SET fiscal_year_end_date=STR_TO_DATE(CONCAT(YEAR(CURRENT_DATE),'-',fiscal_year_end),'%Y-%m-%d')
        WHERE fiscal_year_end_date IS NULL");
    if (schema_table_exists('ai_agent_incidents')) {
        $knownIncident = db()->prepare("UPDATE ai_agent_incidents
            SET status='resolved', resolution_note=?, resolved_at=UTC_TIMESTAMP(), resolved_by=NULL
            WHERE status IN ('open','investigating') AND context_json LIKE ?");
        $knownIncident->execute([
            'Fixed in Tegh 2.0.0. Tegh AI now checks that form controls exist before changing their disabled state, captures browser stacks, and suppresses duplicate error bursts.',
            "%Cannot set properties of null (setting 'disabled')%",
        ]);
    }
    ensure_v18_system_incident_schema();
    ensure_v19_accounting_integrity_schema();
    ensure_v20_release_audit_schema();
    ensure_v21_recurring_document_flags();
    ensure_v28_reporting_framework_schema();
    ensure_v29_ai_agent_schema();
    ensure_v30_master_import_schema();
    ensure_v31_accounting_import_schema($migrationActor);
    ensure_v32_opening_document_schema();
    ensure_v33_ai_learning_schema();
    ensure_v34_human_agent_schema();
    ensure_v35_native_agent_schema();
    ensure_v36_native_ap_ar_schema();
    ensure_v37_financial_analysis_schema();
    ensure_v38_payroll_tax_autonomy_schema();
    ensure_v39_delivery_collection_close_schema();
    ensure_v4140_gifi_mapping_repair();
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v22_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v23_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v24_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v25_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v26_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v27_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v28_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v29_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v30_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v31_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v32_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v33_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v34_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v35_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v36_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v37_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v38_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_v39_data_ready','1') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_version', '39')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v4_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v5_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v6_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v7_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v8_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v9_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v10_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v11_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v12_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v13_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v14_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v15_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v16_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v17_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v18_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v19_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v20_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    db()->exec("INSERT INTO app_meta (meta_key, meta_value) VALUES ('schema_v21_data_ready', '1')
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
}
