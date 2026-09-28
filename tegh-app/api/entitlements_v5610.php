<?php
declare(strict_types=1);

/**
 * Tegh Schema 40 feature catalogue and authoritative entitlement service.
 *
 * The stable feature keys in this file are deliberately independent of plan
 * names and prices. Existing account_plan/module_mode values are exposed only
 * as compatibility evidence; neither value grants authorization.
 */

const TEGH_ENTITLEMENT_MODE_SHADOW = 'shadow';
const TEGH_ENTITLEMENT_MODE_ENFORCED = 'enforced';

/** @return array<string,array<string,mixed>> */
function tegh_feature_seed_catalog(): array
{
    return [
        'core.authentication'=>['Authentication & Security','Secure sign-in, sessions and account protection.','capability','account','enabled',false,false,10],
        'core.accounting'=>['Core Accounting','Company books, source documents, ledgers and financial statements.','module','company','enabled',false,false,20],
        'ui.navigation'=>['Navigation Preferences','Top/side navigation and keyboard navigation preferences.','ui_option','company_user','enabled',false,false,30],
        'ui.accessibility'=>['Accessibility Settings','Text size, reflow, reduced motion and accessible interaction settings.','ui_option','company_user','enabled',false,false,40],
        'ui.accounting_mode'=>['Accounting Presentation Mode','Guided or Full Accounting presentation preference.','ui_option','company_user','enabled',false,false,50],
        'audit.basic'=>['Audit & Data Protection','Basic audit history and data-protection controls.','capability','company','enabled',false,false,60],
        'banking.manual_reconciliation'=>['Manual Reconciliation','Manual bank review, matching and statement reconciliation.','capability','company','enabled',false,false,70],
        'tegh.ask.base'=>['Ask Tegh','Provider-optional help and deterministic accounting guidance.','capability','company','enabled',false,false,80],
        'module.payroll'=>['Payroll','Employee records, draft Pay Runs, verification and controlled posting.','module','company','disabled',true,false,200],
        'module.document_intake'=>['Document Intake / OCR','Private document intake and browser-local/native extraction.','module','company','disabled',true,true,210],
        'module.collections'=>['Collections','Collection monitoring, drafts and delivery review.','module','company','disabled',true,true,220],
        'module.financial_analysis'=>['Financial Analysis','Deterministic forecasts, scenarios and management analysis.','module','company','disabled',true,true,230],
        'tegh.ai.advanced'=>['Tegh AI Advanced','Optional provider-backed advanced assistance with deterministic fallbacks.','capability','company','disabled',true,true,240],
        'tegh.command_centre'=>['Tegh Command Centre','Structured commands, pins, history and prepared-work queue.','capability','company','disabled',true,true,250],
        'banking.reconciliation.advanced'=>['Advanced Reconciliation Suggestions','Deterministic advanced proposal analysis and evidence.','capability','company','disabled',true,true,260],
        'banking.reconciliation.bulk_match'=>['Bulk Matching','Review and confirm multiple eligible matches together.','capability','company','disabled',true,true,270],
        'banking.reconciliation.match_post'=>['Authorized Match & Post','One-review, one-confirm atomic bank Match or Post & Match.','capability','company','disabled',true,true,280],
    ];
}

/** @return array<int,array{0:string,1:string,2:string}> */
function tegh_feature_seed_dependencies(): array
{
    return [
        ['module.payroll','core.accounting','requires'],
        ['module.document_intake','core.accounting','requires'],
        ['module.collections','core.accounting','requires'],
        ['module.financial_analysis','core.accounting','requires'],
        ['tegh.ai.advanced','tegh.ask.base','requires'],
        ['tegh.command_centre','tegh.ask.base','requires'],
        ['banking.reconciliation.advanced','banking.manual_reconciliation','requires'],
        ['banking.reconciliation.bulk_match','banking.reconciliation.advanced','requires'],
        ['banking.reconciliation.match_post','banking.reconciliation.advanced','requires'],
    ];
}

/** @return array<string,string> */
function tegh_schema40_table_sql(): array
{
    return [
        'feature_catalog'=>"CREATE TABLE IF NOT EXISTS feature_catalog (
          feature_key VARCHAR(120) NOT NULL PRIMARY KEY, display_name VARCHAR(180) NOT NULL,
          description VARCHAR(1000) NOT NULL, kind ENUM('module','capability','ui_option') NOT NULL,
          permitted_scope ENUM('account','company','company_user') NOT NULL,
          default_decision ENUM('enabled','disabled') NOT NULL DEFAULT 'disabled',
          operational_state ENUM('active','suspended','retired') NOT NULL DEFAULT 'active',
          billable TINYINT(1) NOT NULL DEFAULT 0, metered TINYINT(1) NOT NULL DEFAULT 0,
          display_order INT NOT NULL DEFAULT 0, metadata_json LONGTEXT NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY feature_catalog_state_order_idx (operational_state,display_order,feature_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'feature_dependencies'=>"CREATE TABLE IF NOT EXISTS feature_dependencies (
          feature_key VARCHAR(120) NOT NULL, related_feature_key VARCHAR(120) NOT NULL,
          relation_type ENUM('requires','conflicts') NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (feature_key,related_feature_key,relation_type),
          CONSTRAINT feature_dependency_feature_fk FOREIGN KEY (feature_key) REFERENCES feature_catalog(feature_key),
          CONSTRAINT feature_dependency_related_fk FOREIGN KEY (related_feature_key) REFERENCES feature_catalog(feature_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'entitlement_subjects'=>"CREATE TABLE IF NOT EXISTS entitlement_subjects (
          id VARCHAR(64) NOT NULL PRIMARY KEY, subject_type ENUM('account','company','company_user') NOT NULL,
          account_user_id VARCHAR(64) NULL, company_id VARCHAR(64) NULL, user_id VARCHAR(64) NULL,
          identity_key CHAR(64) NOT NULL, subject_label VARCHAR(240) NOT NULL DEFAULT '', retired_at DATETIME NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY entitlement_subject_identity_uq (identity_key),
          KEY entitlement_subject_company_user_idx (company_id,user_id,subject_type),
          CONSTRAINT entitlement_subject_scope_ck CHECK (
            (subject_type='account' AND account_user_id IS NOT NULL AND company_id IS NULL AND user_id IS NULL) OR
            (subject_type='company' AND account_user_id IS NULL AND company_id IS NOT NULL AND user_id IS NULL) OR
            (subject_type='company_user' AND account_user_id IS NULL AND company_id IS NOT NULL AND user_id IS NOT NULL) OR
            (retired_at IS NOT NULL AND account_user_id IS NULL AND company_id IS NULL AND user_id IS NULL)
          ),
          CONSTRAINT entitlement_subject_account_user_fk FOREIGN KEY (account_user_id) REFERENCES users(id),
          CONSTRAINT entitlement_subject_company_fk FOREIGN KEY (company_id) REFERENCES companies(id),
          CONSTRAINT entitlement_subject_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'feature_entitlements'=>"CREATE TABLE IF NOT EXISTS feature_entitlements (
          id VARCHAR(64) NOT NULL PRIMARY KEY, subject_id VARCHAR(64) NOT NULL, feature_key VARCHAR(120) NOT NULL,
          decision ENUM('enabled','disabled') NOT NULL, source ENUM('migration','signup','request','platform_owner','trial','subscription','system') NOT NULL,
          valid_from DATETIME NULL, valid_until DATETIME NULL, revision BIGINT UNSIGNED NOT NULL,
          reason VARCHAR(1000) NOT NULL DEFAULT '', actor_user_id VARCHAR(64) NULL,
          request_reference VARCHAR(80) NOT NULL, operation_key VARCHAR(120) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY feature_entitlement_subject_feature_uq (subject_id,feature_key),
          UNIQUE KEY feature_entitlement_operation_uq (operation_key),
          KEY feature_entitlement_feature_decision_idx (feature_key,decision,valid_until),
          CONSTRAINT feature_entitlement_validity_ck CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until>valid_from),
          CONSTRAINT feature_entitlement_revision_ck CHECK (revision>0),
          CONSTRAINT feature_entitlement_subject_fk FOREIGN KEY (subject_id) REFERENCES entitlement_subjects(id),
          CONSTRAINT feature_entitlement_feature_fk FOREIGN KEY (feature_key) REFERENCES feature_catalog(feature_key),
          CONSTRAINT feature_entitlement_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'feature_entitlement_history'=>"CREATE TABLE IF NOT EXISTS feature_entitlement_history (
          id VARCHAR(64) NOT NULL PRIMARY KEY, entitlement_id VARCHAR(64) NOT NULL,
          subject_id VARCHAR(64) NOT NULL, feature_key VARCHAR(120) NOT NULL, entitlement_revision BIGINT UNSIGNED NOT NULL,
          before_json LONGTEXT NOT NULL, after_json LONGTEXT NOT NULL, actor_user_id VARCHAR(64) NULL,
          source VARCHAR(40) NOT NULL, request_reference VARCHAR(80) NOT NULL, source_reference VARCHAR(120) NULL,
          operation_key VARCHAR(120) NULL, reason VARCHAR(1000) NOT NULL, ip_hash CHAR(64) NOT NULL, user_agent_hash CHAR(64) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY feature_entitlement_history_revision_uq (entitlement_id,entitlement_revision),
          UNIQUE KEY feature_entitlement_history_operation_uq (operation_key),
          KEY feature_entitlement_history_subject_idx (subject_id,feature_key,created_at),
          CONSTRAINT feature_entitlement_history_entitlement_fk FOREIGN KEY (entitlement_id) REFERENCES feature_entitlements(id),
          CONSTRAINT feature_entitlement_history_subject_fk FOREIGN KEY (subject_id) REFERENCES entitlement_subjects(id),
          CONSTRAINT feature_entitlement_history_feature_fk FOREIGN KEY (feature_key) REFERENCES feature_catalog(feature_key),
          CONSTRAINT feature_entitlement_history_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'entitlement_requests'=>"CREATE TABLE IF NOT EXISTS entitlement_requests (
          id VARCHAR(64) NOT NULL PRIMARY KEY, requester_user_id VARCHAR(64) NOT NULL,
          company_id VARCHAR(64) NULL, target_scope ENUM('account','company','company_user') NOT NULL,
          target_user_id VARCHAR(64) NULL, message VARCHAR(1000) NOT NULL DEFAULT '',
          state ENUM('pending','approved','declined','cancelled','fulfilled') NOT NULL DEFAULT 'pending',
          endorsement_state ENUM('not_required','required','endorsed','rejected') NOT NULL DEFAULT 'not_required',
          endorsed_by VARCHAR(64) NULL, reviewed_by VARCHAR(64) NULL, decision_reason VARCHAR(1000) NULL,
          fulfilled_revision BIGINT UNSIGNED NULL, deduplication_key CHAR(64) NOT NULL, pending_dedup_key CHAR(64) NULL,
          request_reference VARCHAR(80) NOT NULL, retired_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY entitlement_request_pending_dedup_uq (pending_dedup_key),
          KEY entitlement_request_inbox_idx (state,company_id,created_at),
          CONSTRAINT entitlement_request_scope_ck CHECK (
            (target_scope='account' AND company_id IS NULL AND target_user_id IS NULL) OR
            (target_scope='company' AND company_id IS NOT NULL AND target_user_id IS NULL) OR
            (target_scope='company_user' AND company_id IS NOT NULL AND target_user_id IS NOT NULL) OR
            (retired_at IS NOT NULL AND company_id IS NULL)
          ),
          CONSTRAINT entitlement_request_requester_fk FOREIGN KEY (requester_user_id) REFERENCES users(id),
          CONSTRAINT entitlement_request_company_fk FOREIGN KEY (company_id) REFERENCES companies(id),
          CONSTRAINT entitlement_request_target_user_fk FOREIGN KEY (target_user_id) REFERENCES users(id),
          CONSTRAINT entitlement_request_endorser_fk FOREIGN KEY (endorsed_by) REFERENCES users(id) ON DELETE SET NULL,
          CONSTRAINT entitlement_request_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'entitlement_request_items'=>"CREATE TABLE IF NOT EXISTS entitlement_request_items (
          id VARCHAR(64) NOT NULL PRIMARY KEY, request_id VARCHAR(64) NOT NULL, feature_key VARCHAR(120) NOT NULL,
          requested_decision ENUM('enabled','disabled') NOT NULL, item_order INT NOT NULL DEFAULT 0,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY entitlement_request_item_feature_uq (request_id,feature_key),
          CONSTRAINT entitlement_request_item_request_fk FOREIGN KEY (request_id) REFERENCES entitlement_requests(id) ON DELETE CASCADE,
          CONSTRAINT entitlement_request_item_feature_fk FOREIGN KEY (feature_key) REFERENCES feature_catalog(feature_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'entitlement_revisions'=>"CREATE TABLE IF NOT EXISTS entitlement_revisions (
          subject_id VARCHAR(64) NOT NULL PRIMARY KEY, revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          CONSTRAINT entitlement_revision_subject_fk FOREIGN KEY (subject_id) REFERENCES entitlement_subjects(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'feature_usage_daily'=>"CREATE TABLE IF NOT EXISTS feature_usage_daily (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          company_id VARCHAR(64) NULL, feature_key VARCHAR(120) NOT NULL, usage_date DATE NOT NULL,
          success_count BIGINT UNSIGNED NOT NULL DEFAULT 0, failure_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
          unit_count BIGINT UNSIGNED NOT NULL DEFAULT 0, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY feature_usage_company_feature_day_uq (company_id,feature_key,usage_date),
          CONSTRAINT feature_usage_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
          CONSTRAINT feature_usage_feature_fk FOREIGN KEY (feature_key) REFERENCES feature_catalog(feature_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'signup_feature_intents'=>"CREATE TABLE IF NOT EXISTS signup_feature_intents (
          id VARCHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(64) NOT NULL, feature_key VARCHAR(120) NOT NULL,
          state ENUM('pending','attached','cancelled') NOT NULL DEFAULT 'pending', company_id VARCHAR(64) NULL,
          attached_request_id VARCHAR(64) NULL, request_reference VARCHAR(80) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, attached_at DATETIME NULL,
          UNIQUE KEY signup_feature_intent_user_feature_uq (user_id,feature_key,state),
          KEY signup_feature_intent_company_idx (company_id,state),
          CONSTRAINT signup_feature_intent_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
          CONSTRAINT signup_feature_intent_feature_fk FOREIGN KEY (feature_key) REFERENCES feature_catalog(feature_key),
          CONSTRAINT signup_feature_intent_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
          CONSTRAINT signup_feature_intent_request_fk FOREIGN KEY (attached_request_id) REFERENCES entitlement_requests(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** @return array<string,array<int,string>> */
function tegh_schema40_required_columns(): array
{
    return array_map('array_keys',tegh_schema40_column_ddl());
}

/** Canonical additive definitions used by both preflight and partial repair. */
function tegh_schema40_column_ddl(): array
{
    return [
      'feature_catalog'=>[
        'feature_key'=>'VARCHAR(120) NOT NULL','display_name'=>'VARCHAR(180) NOT NULL','description'=>'VARCHAR(1000) NOT NULL',
        'kind'=>"ENUM('module','capability','ui_option') NOT NULL",'permitted_scope'=>"ENUM('account','company','company_user') NOT NULL",
        'default_decision'=>"ENUM('enabled','disabled') NOT NULL DEFAULT 'disabled'",'operational_state'=>"ENUM('active','suspended','retired') NOT NULL DEFAULT 'active'",
        'billable'=>'TINYINT(1) NOT NULL DEFAULT 0','metered'=>'TINYINT(1) NOT NULL DEFAULT 0','display_order'=>'INT NOT NULL DEFAULT 0','metadata_json'=>'LONGTEXT NOT NULL',
        'created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP','updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
      'feature_dependencies'=>[
        'feature_key'=>'VARCHAR(120) NOT NULL','related_feature_key'=>'VARCHAR(120) NOT NULL','relation_type'=>"ENUM('requires','conflicts') NOT NULL",'created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'],
      'entitlement_subjects'=>[
        'id'=>'VARCHAR(64) NOT NULL','subject_type'=>"ENUM('account','company','company_user') NOT NULL",'account_user_id'=>'VARCHAR(64) NULL','company_id'=>'VARCHAR(64) NULL','user_id'=>'VARCHAR(64) NULL',
        'identity_key'=>'CHAR(64) NOT NULL','subject_label'=>"VARCHAR(240) NOT NULL DEFAULT ''",'retired_at'=>'DATETIME NULL','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'],
      'feature_entitlements'=>[
        'id'=>'VARCHAR(64) NOT NULL','subject_id'=>'VARCHAR(64) NOT NULL','feature_key'=>'VARCHAR(120) NOT NULL','decision'=>"ENUM('enabled','disabled') NOT NULL",
        'source'=>"ENUM('migration','signup','request','platform_owner','trial','subscription','system') NOT NULL",'valid_from'=>'DATETIME NULL','valid_until'=>'DATETIME NULL','revision'=>'BIGINT UNSIGNED NOT NULL',
        'reason'=>"VARCHAR(1000) NOT NULL DEFAULT ''",'actor_user_id'=>'VARCHAR(64) NULL','request_reference'=>'VARCHAR(80) NOT NULL','operation_key'=>'VARCHAR(120) NULL',
        'created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP','updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
      'feature_entitlement_history'=>[
        'id'=>'VARCHAR(64) NOT NULL','entitlement_id'=>'VARCHAR(64) NOT NULL','subject_id'=>'VARCHAR(64) NOT NULL','feature_key'=>'VARCHAR(120) NOT NULL','entitlement_revision'=>'BIGINT UNSIGNED NOT NULL',
        'before_json'=>'LONGTEXT NOT NULL','after_json'=>'LONGTEXT NOT NULL','actor_user_id'=>'VARCHAR(64) NULL','source'=>'VARCHAR(40) NOT NULL','request_reference'=>'VARCHAR(80) NOT NULL','source_reference'=>'VARCHAR(120) NULL','operation_key'=>'VARCHAR(120) NULL',
        'reason'=>'VARCHAR(1000) NOT NULL','ip_hash'=>'CHAR(64) NOT NULL','user_agent_hash'=>'CHAR(64) NOT NULL','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'],
      'entitlement_requests'=>[
        'id'=>'VARCHAR(64) NOT NULL','requester_user_id'=>'VARCHAR(64) NOT NULL','company_id'=>'VARCHAR(64) NULL','target_scope'=>"ENUM('account','company','company_user') NOT NULL",'target_user_id'=>'VARCHAR(64) NULL',
        'message'=>"VARCHAR(1000) NOT NULL DEFAULT ''",'state'=>"ENUM('pending','approved','declined','cancelled','fulfilled') NOT NULL DEFAULT 'pending'",'endorsement_state'=>"ENUM('not_required','required','endorsed','rejected') NOT NULL DEFAULT 'not_required'",
        'endorsed_by'=>'VARCHAR(64) NULL','reviewed_by'=>'VARCHAR(64) NULL','decision_reason'=>'VARCHAR(1000) NULL','fulfilled_revision'=>'BIGINT UNSIGNED NULL','deduplication_key'=>'CHAR(64) NOT NULL','pending_dedup_key'=>'CHAR(64) NULL',
        'request_reference'=>'VARCHAR(80) NOT NULL','retired_at'=>'DATETIME NULL','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP','updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
      'entitlement_request_items'=>[
        'id'=>'VARCHAR(64) NOT NULL','request_id'=>'VARCHAR(64) NOT NULL','feature_key'=>'VARCHAR(120) NOT NULL','requested_decision'=>"ENUM('enabled','disabled') NOT NULL",'item_order'=>'INT NOT NULL DEFAULT 0','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'],
      'entitlement_revisions'=>[
        'subject_id'=>'VARCHAR(64) NOT NULL','revision'=>'BIGINT UNSIGNED NOT NULL DEFAULT 0','updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
      'feature_usage_daily'=>[
        'id'=>'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT','company_id'=>'VARCHAR(64) NULL','feature_key'=>'VARCHAR(120) NOT NULL','usage_date'=>'DATE NOT NULL','success_count'=>'BIGINT UNSIGNED NOT NULL DEFAULT 0','failure_count'=>'BIGINT UNSIGNED NOT NULL DEFAULT 0','unit_count'=>'BIGINT UNSIGNED NOT NULL DEFAULT 0','updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
      'signup_feature_intents'=>[
        'id'=>'VARCHAR(64) NOT NULL','user_id'=>'VARCHAR(64) NOT NULL','feature_key'=>'VARCHAR(120) NOT NULL','state'=>"ENUM('pending','attached','cancelled') NOT NULL DEFAULT 'pending'",'company_id'=>'VARCHAR(64) NULL','attached_request_id'=>'VARCHAR(64) NULL','request_reference'=>'VARCHAR(80) NOT NULL','created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP','attached_at'=>'DATETIME NULL'],
    ];
}

/** @return array<string,array<string,array{unique:bool,columns:array<int,string>}>> */
function tegh_schema40_index_contract(): array
{
    return [
      'feature_catalog'=>['PRIMARY'=>['unique'=>true,'columns'=>['feature_key']],'feature_catalog_state_order_idx'=>['unique'=>false,'columns'=>['operational_state','display_order','feature_key']]],
      'feature_dependencies'=>['PRIMARY'=>['unique'=>true,'columns'=>['feature_key','related_feature_key','relation_type']]],
      'entitlement_subjects'=>['PRIMARY'=>['unique'=>true,'columns'=>['id']],'entitlement_subject_identity_uq'=>['unique'=>true,'columns'=>['identity_key']],'entitlement_subject_company_user_idx'=>['unique'=>false,'columns'=>['company_id','user_id','subject_type']]],
      'feature_entitlements'=>['PRIMARY'=>['unique'=>true,'columns'=>['id']],'feature_entitlement_subject_feature_uq'=>['unique'=>true,'columns'=>['subject_id','feature_key']],'feature_entitlement_operation_uq'=>['unique'=>true,'columns'=>['operation_key']],'feature_entitlement_feature_decision_idx'=>['unique'=>false,'columns'=>['feature_key','decision','valid_until']]],
      'feature_entitlement_history'=>['PRIMARY'=>['unique'=>true,'columns'=>['id']],'feature_entitlement_history_revision_uq'=>['unique'=>true,'columns'=>['entitlement_id','entitlement_revision']],'feature_entitlement_history_operation_uq'=>['unique'=>true,'columns'=>['operation_key']],'feature_entitlement_history_subject_idx'=>['unique'=>false,'columns'=>['subject_id','feature_key','created_at']]],
      'entitlement_requests'=>['PRIMARY'=>['unique'=>true,'columns'=>['id']],'entitlement_request_pending_dedup_uq'=>['unique'=>true,'columns'=>['pending_dedup_key']],'entitlement_request_inbox_idx'=>['unique'=>false,'columns'=>['state','company_id','created_at']]],
      'entitlement_request_items'=>['PRIMARY'=>['unique'=>true,'columns'=>['id']],'entitlement_request_item_feature_uq'=>['unique'=>true,'columns'=>['request_id','feature_key']]],
      'entitlement_revisions'=>['PRIMARY'=>['unique'=>true,'columns'=>['subject_id']]],
      'feature_usage_daily'=>['PRIMARY'=>['unique'=>true,'columns'=>['id']],'feature_usage_company_feature_day_uq'=>['unique'=>true,'columns'=>['company_id','feature_key','usage_date']]],
      'signup_feature_intents'=>['PRIMARY'=>['unique'=>true,'columns'=>['id']],'signup_feature_intent_user_feature_uq'=>['unique'=>true,'columns'=>['user_id','feature_key','state']],'signup_feature_intent_company_idx'=>['unique'=>false,'columns'=>['company_id','state']]],
    ];
}

/** @return array<string,array<string,array<string,string>>> */
function tegh_schema40_foreign_key_contract(): array
{
    $fk=static fn(string $columns,string $target,string $targetColumns='id',string $delete='RESTRICT'):array=>['columns'=>$columns,'targetTable'=>$target,'targetColumns'=>$targetColumns,'updateRule'=>'RESTRICT','deleteRule'=>$delete];
    return [
      'feature_dependencies'=>['feature_dependency_feature_fk'=>$fk('feature_key','feature_catalog','feature_key'),'feature_dependency_related_fk'=>$fk('related_feature_key','feature_catalog','feature_key')],
      // MariaDB rejects CHECK constraints that reference a column governed by
      // ON DELETE SET NULL. These scope columns therefore use RESTRICT, while
      // the audited company-deletion service retires and de-scopes the rows
      // before deleting the company. This preserves both the scope CHECK and
      // immutable commercial history on MySQL and MariaDB.
      'entitlement_subjects'=>['entitlement_subject_account_user_fk'=>$fk('account_user_id','users'),'entitlement_subject_company_fk'=>$fk('company_id','companies'),'entitlement_subject_user_fk'=>$fk('user_id','users')],
      'feature_entitlements'=>['feature_entitlement_subject_fk'=>$fk('subject_id','entitlement_subjects'),'feature_entitlement_feature_fk'=>$fk('feature_key','feature_catalog','feature_key'),'feature_entitlement_actor_fk'=>$fk('actor_user_id','users','id','SET NULL')],
      'feature_entitlement_history'=>['feature_entitlement_history_entitlement_fk'=>$fk('entitlement_id','feature_entitlements'),'feature_entitlement_history_subject_fk'=>$fk('subject_id','entitlement_subjects'),'feature_entitlement_history_feature_fk'=>$fk('feature_key','feature_catalog','feature_key'),'feature_entitlement_history_actor_fk'=>$fk('actor_user_id','users','id','SET NULL')],
      'entitlement_requests'=>['entitlement_request_requester_fk'=>$fk('requester_user_id','users'),'entitlement_request_company_fk'=>$fk('company_id','companies'),'entitlement_request_target_user_fk'=>$fk('target_user_id','users'),'entitlement_request_endorser_fk'=>$fk('endorsed_by','users','id','SET NULL'),'entitlement_request_reviewer_fk'=>$fk('reviewed_by','users','id','SET NULL')],
      'entitlement_request_items'=>['entitlement_request_item_request_fk'=>$fk('request_id','entitlement_requests','id','CASCADE'),'entitlement_request_item_feature_fk'=>$fk('feature_key','feature_catalog','feature_key')],
      'entitlement_revisions'=>['entitlement_revision_subject_fk'=>$fk('subject_id','entitlement_subjects')],
      'feature_usage_daily'=>['feature_usage_company_fk'=>$fk('company_id','companies','id','SET NULL'),'feature_usage_feature_fk'=>$fk('feature_key','feature_catalog','feature_key')],
      'signup_feature_intents'=>['signup_feature_intent_user_fk'=>$fk('user_id','users','id','CASCADE'),'signup_feature_intent_feature_fk'=>$fk('feature_key','feature_catalog','feature_key'),'signup_feature_intent_company_fk'=>$fk('company_id','companies','id','SET NULL'),'signup_feature_intent_request_fk'=>$fk('attached_request_id','entitlement_requests','id','SET NULL')],
    ];
}

/** @return array<string,array<string,string>> */
function tegh_schema40_check_contract(): array
{
    return [
      'entitlement_subjects'=>[
        'entitlement_subject_scope_ck'=>"(subject_type='account' AND account_user_id IS NOT NULL AND company_id IS NULL AND user_id IS NULL) OR (subject_type='company' AND account_user_id IS NULL AND company_id IS NOT NULL AND user_id IS NULL) OR (subject_type='company_user' AND account_user_id IS NULL AND company_id IS NOT NULL AND user_id IS NOT NULL) OR (retired_at IS NOT NULL AND account_user_id IS NULL AND company_id IS NULL AND user_id IS NULL)",
      ],
      'feature_entitlements'=>[
        'feature_entitlement_validity_ck'=>'valid_until IS NULL OR valid_from IS NULL OR valid_until>valid_from',
        'feature_entitlement_revision_ck'=>'revision>0',
      ],
      'entitlement_requests'=>[
        'entitlement_request_scope_ck'=>"(target_scope='account' AND company_id IS NULL AND target_user_id IS NULL) OR (target_scope='company' AND company_id IS NOT NULL AND target_user_id IS NULL) OR (target_scope='company_user' AND company_id IS NOT NULL AND target_user_id IS NOT NULL) OR (retired_at IS NOT NULL AND company_id IS NULL)",
      ],
    ];
}

function tegh_schema40_normalize_check(string $clause): string
{
    $clause=strtolower($clause);
    // IONOS MariaDB can expose CHECK_CLAUSE string literals as
    // _utf8mb4\'value\' instead of _utf8mb4'value'.  Both representations
    // describe the same server-created constraint.  Unescape only the quote
    // representation before removing character-set introducers so a valid,
    // empty table is not falsely classified as a malformed partial schema.
    $clause=str_replace("\\'", "'", $clause);
    $clause=(string)preg_replace('/_[a-z0-9]+(?=\')/','',$clause);
    return str_replace(['`','(',')',' '],['','','',''],(string)preg_replace('/\s+/',' ',$clause));
}

function tegh_schema40_check_actual(string $table,string $constraint): ?string
{
    try{
        $stmt=db()->prepare('SELECT cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.TABLE_NAME=? AND tc.CONSTRAINT_NAME=? AND tc.CONSTRAINT_TYPE=\'CHECK\' LIMIT 1');
        $stmt->execute([$table,$constraint]);$clause=$stmt->fetchColumn();return $clause===false?null:(string)$clause;
    }catch(PDOException){return null;}
}

function tegh_schema40_check_violation_candidates(string $table,string $clause): int
{
    try{return (int)db()->query('SELECT COUNT(*) FROM `'.$table.'` WHERE NOT ('.$clause.')')->fetchColumn();}
    catch(PDOException){return tegh_schema_table_rows($table);}
}

/** @return array<string,mixed> */
function tegh_schema40_expected_column(string $definition): array
{
    if(!preg_match('/^(.+?)\s+(NOT\s+NULL|NULL)\b/i',$definition,$match))throw new RuntimeException('Schema 40 column definition cannot be inspected.');
    $type=tegh_schema_normalize_type($match[1]);
    $expected=['type'=>$type,'nullable'=>strtoupper(str_replace(' ','',$match[2]))!=='NOTNULL','default'=>null];
    // MariaDB exposes integer display widths while MySQL 8 generally omits
    // them. They are storage-equivalent contracts and must not make a valid
    // fresh install or resumable migration appear malformed.
    if($type==='int')$expected['typeAlternatives']=['int(11)'];
    elseif($type==='intunsigned')$expected['typeAlternatives']=['int(10) unsigned','int(11) unsigned'];
    elseif($type==='bigint')$expected['typeAlternatives']=['bigint(20)'];
    elseif($type==='bigintunsigned')$expected['typeAlternatives']=['bigint(20) unsigned'];
    if(preg_match('/\bDEFAULT\s+(CURRENT_TIMESTAMP|[-]?[0-9]+|\'[^\']*\')/i',$definition,$default)){$value=tegh_schema_normalize_default($default[1]);$expected['default']=$value;if($value==='current_timestamp')$expected['defaultAlternatives']=['current_timestamp()'];}
    if(str_contains(strtoupper($definition),'AUTO_INCREMENT'))$expected['extra']='auto_increment';
    elseif(str_contains(strtoupper($definition),'ON UPDATE CURRENT_TIMESTAMP'))$expected['extra']='on update current_timestamp';
    return $expected;
}

function tegh_schema40_missing_column_repairable(string $definition,int $rows): bool
{
    if($rows===0)return true;
    $expected=tegh_schema40_expected_column($definition);
    return ($expected['nullable']??false)===true||($expected['default']??null)!==null;
}

function tegh_schema40_duplicate_candidates(string $table,array $columns): int
{
    foreach($columns as $column)if(!schema_column_exists($table,$column))return 0;
    $quoted=array_map(static fn(string $column):string=>'`'.$column.'`',$columns);$nonnull=implode(' AND ',array_map(static fn(string $column):string=>'`'.$column.'` IS NOT NULL',$columns));
    $sql='SELECT 1 FROM `'.$table.'`'.($nonnull!==''?' WHERE '.$nonnull:'').' GROUP BY '.implode(',',$quoted).' HAVING COUNT(*)>1 LIMIT 1';return db()->query($sql)->fetchColumn()===false?0:1;
}

function tegh_schema40_orphan_candidates(string $table,array $contract): int
{
    $columns=explode(',',$contract['columns']);$targets=explode(',',$contract['targetColumns']);$join=[];$nonnull=[];
    foreach($columns as $column)if(!schema_column_exists($table,$column))return 0;
    foreach($columns as $column)$nonnull[]='c.`'.$column.'` IS NOT NULL';
    if(!schema_table_exists($contract['targetTable']))return (int)db()->query('SELECT COUNT(*) FROM `'.$table.'` c WHERE '.implode(' AND ',$nonnull))->fetchColumn();
    foreach($targets as $column)if(!schema_column_exists($contract['targetTable'],$column))return (int)db()->query('SELECT COUNT(*) FROM `'.$table.'` c WHERE '.implode(' AND ',$nonnull))->fetchColumn();
    foreach($columns as $index=>$column)$join[]='c.`'.$column.'`=p.`'.$targets[$index].'`';
    $targetFirst=$targets[0];$sql='SELECT COUNT(*) FROM `'.$table.'` c LEFT JOIN `'.$contract['targetTable'].'` p ON '.implode(' AND ',$join).' WHERE '.implode(' AND ',$nonnull).' AND p.`'.$targetFirst.'` IS NULL';return (int)db()->query($sql)->fetchColumn();
}

/** @return array<string,mixed> */
function tegh_schema40_status(): array
{
    $issues=[];$present=0;
    $definitions=tegh_schema40_column_ddl();
    foreach(tegh_schema40_required_columns() as $table=>$columns){
        if(!schema_table_exists($table)){
            $issues[]=['kind'=>'missing_table','object'=>$table,'expected'=>'Schema 40 canonical table','actual'=>'missing','populated'=>false,'repairable'=>true];
            continue;
        }
        $present++;$rows=tegh_schema_table_rows($table);
        $stmt=db()->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
        $stmt->execute([$table]);$properties=$stmt->fetch()?:[];
        if(strtoupper((string)($properties['ENGINE']??''))!=='INNODB'||strtolower((string)($properties['TABLE_COLLATION']??''))!=='utf8mb4_unicode_ci'){
            $issues[]=['kind'=>'table_contract','object'=>$table,'expected'=>'InnoDB / utf8mb4_unicode_ci','actual'=>$properties,'populated'=>$rows>0,'repairable'=>$rows===0];
        }
        foreach($columns as $column){$definition=$definitions[$table][$column];$actual=tegh_schema_column_actual($table,$column);$expected=tegh_schema40_expected_column($definition);
            if($actual===null){$repairable=tegh_schema40_missing_column_repairable($definition,$rows);$issues[]=['kind'=>'missing_column','object'=>$table.'.'.$column,'expected'=>$expected,'actual'=>'missing','populated'=>$rows>0,'repairable'=>$repairable];}
            elseif(!tegh_schema_contract_equal($expected,$actual))$issues[]=['kind'=>'column_contract','object'=>$table.'.'.$column,'expected'=>$expected,'actual'=>$actual,'populated'=>$rows>0,'repairable'=>$rows===0];
        }
    }
    foreach(tegh_schema40_index_contract() as $table=>$indexes)if(schema_table_exists($table))foreach($indexes as $index=>$expected){$actual=tegh_schema_index_actual($table,$index);$duplicates=$expected['unique']?tegh_schema40_duplicate_candidates($table,$expected['columns']):0;
        if($actual===null)$issues[]=['kind'=>'missing_index','object'=>$table.'.'.$index,'expected'=>$expected,'actual'=>'missing','duplicateCandidates'=>$duplicates,'repairable'=>$duplicates===0];
        elseif(!tegh_schema_contract_equal($expected,$actual))$issues[]=['kind'=>'index_contract','object'=>$table.'.'.$index,'expected'=>$expected,'actual'=>$actual,'repairable'=>false];
    }
    foreach(tegh_schema40_foreign_key_contract() as $table=>$foreignKeys)if(schema_table_exists($table))foreach($foreignKeys as $name=>$expected){$actual=tegh_schema_foreign_key_actual($table,$name);$orphans=tegh_schema40_orphan_candidates($table,$expected);
        if($actual===null)$issues[]=['kind'=>'missing_foreign_key','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>'missing','orphanCandidates'=>$orphans,'repairable'=>$orphans===0];
        elseif(!tegh_schema_contract_equal($expected,$actual))$issues[]=['kind'=>'foreign_key_contract','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>$actual,'orphanCandidates'=>$orphans,'repairable'=>false];
    }
    foreach(tegh_schema40_check_contract() as $table=>$checks)if(schema_table_exists($table))foreach($checks as $name=>$expected){$actual=tegh_schema40_check_actual($table,$name);$violations=tegh_schema40_check_violation_candidates($table,$expected);
        if($actual===null)$issues[]=['kind'=>'missing_check','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>'missing','violationCandidates'=>$violations,'repairable'=>$violations===0];
        elseif(tegh_schema40_normalize_check($expected)!==tegh_schema40_normalize_check($actual))$issues[]=['kind'=>'check_contract','object'=>$table.'.'.$name,'expected'=>$expected,'actual'=>$actual,'violationCandidates'=>$violations,'repairable'=>false];
        elseif($violations>0)$issues[]=['kind'=>'check_data_violation','object'=>$table.'.'.$name,'expected'=>0,'actual'=>$violations,'repairable'=>false];
    }
    if(schema_table_exists('entitlement_subjects')){
        $invalid=(int)db()->query("SELECT COUNT(*) FROM entitlement_subjects WHERE
          (retired_at IS NULL AND subject_type='account' AND (account_user_id IS NULL OR company_id IS NOT NULL OR user_id IS NOT NULL)) OR
          (retired_at IS NULL AND subject_type='company' AND (company_id IS NULL OR account_user_id IS NOT NULL OR user_id IS NOT NULL)) OR
          (retired_at IS NULL AND subject_type='company_user' AND (company_id IS NULL OR user_id IS NULL OR account_user_id IS NOT NULL)) OR
          (retired_at IS NOT NULL AND (account_user_id IS NOT NULL OR company_id IS NOT NULL OR user_id IS NOT NULL))")->fetchColumn();
        if($invalid>0)$issues[]=['kind'=>'subject_scope_leakage','object'=>'entitlement_subjects','expected'=>0,'actual'=>$invalid,'repairable'=>false];
    }
    $cycle=tegh_feature_dependency_cycle();
    if($cycle!==[])$issues[]=['kind'=>'dependency_cycle','object'=>'feature_dependencies','expected'=>'acyclic requires graph','actual'=>$cycle,'repairable'=>false];
    if(schema_table_exists('feature_catalog')){
        $catalogStmt=db()->prepare('SELECT 1 FROM feature_catalog WHERE feature_key=? LIMIT 1');
        foreach(array_keys(tegh_feature_seed_catalog()) as $featureKey){$catalogStmt->execute([$featureKey]);if(!$catalogStmt->fetchColumn())$issues[]=['kind'=>'missing_catalog_seed','object'=>'feature_catalog.'.$featureKey,'expected'=>'registered','actual'=>'missing','repairable'=>true];}
    }
    if(schema_table_exists('feature_dependencies')&&schema_table_exists('feature_catalog')){
        $dependencyStmt=db()->prepare('SELECT 1 FROM feature_dependencies WHERE feature_key=? AND related_feature_key=? AND relation_type=? LIMIT 1');
        foreach(tegh_feature_seed_dependencies() as [$feature,$related,$relation]){$dependencyStmt->execute([$feature,$related,$relation]);if(!$dependencyStmt->fetchColumn())$issues[]=['kind'=>'missing_dependency_seed','object'=>$feature.' '.$relation.' '.$related,'expected'=>'registered','actual'=>'missing','repairable'=>true];}
    }
    if(schema_table_exists('feature_entitlements')&&schema_table_exists('entitlement_subjects')&&schema_table_exists('companies')){
        $base=['core.accounting','audit.basic','banking.manual_reconciliation','tegh.ask.base'];
        $legacyPreserved=['module.payroll','module.document_intake','module.collections','module.financial_analysis'];
        $missingProjection=0;
        foreach(db()->query('SELECT id FROM companies WHERE active=1')->fetchAll() as $company){
            foreach($base as $feature){$q=db()->prepare("SELECT 1 FROM entitlement_subjects s JOIN feature_entitlements e ON e.subject_id=s.id WHERE s.subject_type='company' AND s.company_id=? AND e.feature_key=? AND e.decision='enabled' LIMIT 1");$q->execute([$company['id'],$feature]);if(!$q->fetchColumn())$missingProjection++;}
            $migrated=db()->prepare("SELECT 1 FROM entitlement_subjects s JOIN feature_entitlements e ON e.subject_id=s.id WHERE s.subject_type='company' AND s.company_id=? AND e.source='migration' LIMIT 1");$migrated->execute([$company['id']]);
            if($migrated->fetchColumn())foreach($legacyPreserved as $feature){$q=db()->prepare("SELECT 1 FROM entitlement_subjects s JOIN feature_entitlements e ON e.subject_id=s.id WHERE s.subject_type='company' AND s.company_id=? AND e.feature_key=? AND e.decision='enabled' LIMIT 1");$q->execute([$company['id'],$feature]);if(!$q->fetchColumn())$missingProjection++;}
        }
        if($missingProjection>0)$issues[]=['kind'=>'compatibility_projection_missing','object'=>'feature_entitlements','expected'=>0,'actual'=>$missingProjection,'repairable'=>true];
    }
    return ['ready'=>$issues===[],'issues'=>$issues,'presentTableCount'=>$present,'expectedTableCount'=>count(tegh_schema40_required_columns()),'dependencyCycle'=>$cycle,
        'entitlementMode'=>tegh_entitlement_mode(),'enforcementActivated'=>tegh_entitlement_mode()===TEGH_ENTITLEMENT_MODE_ENFORCED];
}

/** @return array<int,string> */
function tegh_feature_dependency_cycle(): array
{
    if(!schema_table_exists('feature_dependencies'))return [];
    $graph=[];
    foreach(db()->query("SELECT feature_key,related_feature_key FROM feature_dependencies WHERE relation_type='requires'")->fetchAll() as $row)$graph[(string)$row['feature_key']][]=(string)$row['related_feature_key'];
    $visiting=[];$visited=[];$path=[];
    $walk=function(string $node)use(&$walk,&$graph,&$visiting,&$visited,&$path):array{
        if(isset($visiting[$node])){$offset=array_search($node,$path,true);return array_merge(array_slice($path,$offset===false?0:$offset),[$node]);}
        if(isset($visited[$node]))return [];
        $visiting[$node]=true;$path[]=$node;
        foreach($graph[$node]??[] as $next){$cycle=$walk($next);if($cycle!==[])return $cycle;}
        array_pop($path);unset($visiting[$node]);$visited[$node]=true;return [];
    };
    foreach(array_keys($graph) as $node){$cycle=$walk($node);if($cycle!==[])return $cycle;}
    return [];
}

function tegh_schema40_ensure_tables(): void
{
    foreach(tegh_schema40_table_sql() as $sql)db()->exec($sql);
}

function tegh_schema40_repair_table(string $table): void
{
    $sql=tegh_schema40_table_sql()[$table]??null;$definitions=tegh_schema40_column_ddl()[$table]??null;
    if($sql===null||$definitions===null)throw new RuntimeException('Unknown Schema 40 table repair step.');
    if(!schema_table_exists($table)){db()->exec($sql);return;}
    $rows=tegh_schema_table_rows($table);$properties=db()->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');$properties->execute([$table]);$actualProperties=$properties->fetch()?:[];
    if(strtoupper((string)($actualProperties['ENGINE']??''))!=='INNODB'||strtolower((string)($actualProperties['TABLE_COLLATION']??''))!=='utf8mb4_unicode_ci'){
        if($rows>0)throw new RuntimeException('Populated Schema 40 table '.$table.' has a non-canonical engine or collation.');
        db()->exec("ALTER TABLE `$table` ENGINE=InnoDB");db()->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    $deferredAuto=[];
    foreach($definitions as $column=>$definition){$actual=tegh_schema_column_actual($table,$column);$expected=tegh_schema40_expected_column($definition);
        if($actual===null){
            if(!tegh_schema40_missing_column_repairable($definition,$rows))throw new RuntimeException('Populated Schema 40 table '.$table.' is missing required provenance column '.$column.'.');
            if(str_contains(strtoupper($definition),'AUTO_INCREMENT')){$without=preg_replace('/\s+AUTO_INCREMENT/i','',$definition);schema_add_column($table,$column,(string)$without);$deferredAuto[$column]=$definition;}
            else schema_add_column($table,$column,$definition);
        }elseif(!tegh_schema_contract_equal($expected,$actual)){
            if($rows>0)throw new RuntimeException('Populated Schema 40 column '.$table.'.'.$column.' has a non-canonical definition.');
            if(str_contains(strtoupper($definition),'AUTO_INCREMENT'))$deferredAuto[$column]=$definition;
            else db()->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
        }
    }
    foreach(tegh_schema40_index_contract()[$table]??[] as $index=>$expected){$actual=tegh_schema_index_actual($table,$index);if($actual!==null){if(!tegh_schema_contract_equal($expected,$actual))throw new RuntimeException('Schema 40 index '.$table.'.'.$index.' has a non-canonical contract.');continue;}
        if($expected['unique']&&tegh_schema40_duplicate_candidates($table,$expected['columns'])>0)throw new RuntimeException('Duplicate populated values prevent the required Schema 40 index '.$table.'.'.$index.'.');
        $columns=implode(',',array_map(static fn(string $column):string=>'`'.$column.'`',$expected['columns']));if($index==='PRIMARY')db()->exec("ALTER TABLE `$table` ADD PRIMARY KEY ($columns)");else schema_add_index($table,$index,$columns,(bool)$expected['unique']);
    }
    foreach($deferredAuto as $column=>$definition)db()->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
    foreach(tegh_schema40_foreign_key_contract()[$table]??[] as $name=>$expected){$actual=tegh_schema_foreign_key_actual($table,$name);if($actual!==null){if(!tegh_schema_contract_equal($expected,$actual))throw new RuntimeException('Schema 40 foreign key '.$table.'.'.$name.' has a non-canonical contract.');continue;}
        $orphans=tegh_schema40_orphan_candidates($table,$expected);if($orphans>0)throw new RuntimeException('Orphaned populated rows prevent the required Schema 40 foreign key '.$table.'.'.$name.'.');
        $columns=implode(',',array_map(static fn(string $column):string=>'`'.$column.'`',explode(',',$expected['columns'])));$targetColumns=implode(',',array_map(static fn(string $column):string=>'`'.$column.'`',explode(',',$expected['targetColumns'])));
        db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY ($columns) REFERENCES `{$expected['targetTable']}` ($targetColumns) ON UPDATE {$expected['updateRule']} ON DELETE {$expected['deleteRule']}");
    }
    foreach(tegh_schema40_check_contract()[$table]??[] as $name=>$expected){$actual=tegh_schema40_check_actual($table,$name);if($actual!==null){if(tegh_schema40_normalize_check($expected)!==tegh_schema40_normalize_check($actual))throw new RuntimeException('Schema 40 check '.$table.'.'.$name.' has a non-canonical contract.');continue;}
        $violations=tegh_schema40_check_violation_candidates($table,$expected);if($violations>0)throw new RuntimeException('Populated rows violate the required Schema 40 check '.$table.'.'.$name.'.');
        db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK ($expected)");
    }
}

function tegh_schema40_table_contract_ready(string $table): bool
{
    $structural=['missing_table','table_contract','missing_column','column_contract','missing_index','index_contract','missing_foreign_key','foreign_key_contract','missing_check','check_contract','check_data_violation'];
    foreach(tegh_schema40_status()['issues'] as $issue)if(in_array((string)$issue['kind'],$structural,true)&&((string)$issue['object']===$table||str_starts_with((string)$issue['object'],$table.'.')))return false;
    return true;
}

function tegh_feature_seed(): void
{
    $stmt=db()->prepare("INSERT INTO feature_catalog
      (feature_key,display_name,description,kind,permitted_scope,default_decision,operational_state,billable,metered,display_order,metadata_json)
      VALUES (?,?,?,?,?,?,'active',?,?,?,'{}')
      ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),description=VALUES(description),kind=VALUES(kind),
        permitted_scope=VALUES(permitted_scope),default_decision=VALUES(default_decision),billable=VALUES(billable),metered=VALUES(metered),display_order=VALUES(display_order)");
    foreach(tegh_feature_seed_catalog() as $key=>[$name,$description,$kind,$scope,$default,$billable,$metered,$order]){
        $stmt->execute([$key,$name,$description,$kind,$scope,$default,$billable?1:0,$metered?1:0,$order]);
    }
    $dependency=db()->prepare('INSERT INTO feature_dependencies (feature_key,related_feature_key,relation_type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE relation_type=VALUES(relation_type)');
    foreach(tegh_feature_seed_dependencies() as [$feature,$related,$relation])$dependency->execute([$feature,$related,$relation]);
    $cycle=tegh_feature_dependency_cycle();if($cycle!==[])throw new RuntimeException('Feature dependency cycle detected: '.implode(' -> ',$cycle));
}

function tegh_entitlement_identity(string $type,?string $accountUserId,?string $companyId,?string $userId): string
{
    return hash('sha256',implode('|',['tegh-entitlement-subject-v1',$type,$accountUserId??'',$companyId??'',$userId??'']));
}

/** @return array<string,mixed> */
function tegh_entitlement_subject(string $type,?string $accountUserId,?string $companyId,?string $userId,string $label=''): array
{
    $valid=($type==='account'&&$accountUserId!==null&&$companyId===null&&$userId===null)
      ||($type==='company'&&$accountUserId===null&&$companyId!==null&&$userId===null)
      ||($type==='company_user'&&$accountUserId===null&&$companyId!==null&&$userId!==null);
    if(!$valid)throw new InvalidArgumentException('Entitlement subject scope is invalid.');
    $identity=tegh_entitlement_identity($type,$accountUserId,$companyId,$userId);
    $stmt=db()->prepare('SELECT * FROM entitlement_subjects WHERE identity_key=? LIMIT 1');$stmt->execute([$identity]);$subject=$stmt->fetch();
    if($subject)return $subject;
    $id=new_id('entsubject');
    db()->prepare('INSERT INTO entitlement_subjects (id,subject_type,account_user_id,company_id,user_id,identity_key,subject_label) VALUES (?,?,?,?,?,?,?)')
      ->execute([$id,$type,$accountUserId,$companyId,$userId,$identity,mb_substr($label,0,240)]);
    db()->prepare('INSERT INTO entitlement_revisions (subject_id,revision) VALUES (?,0)')->execute([$id]);
    $stmt->execute([$identity]);return $stmt->fetch()?:throw new RuntimeException('Entitlement subject was not created.');
}

function tegh_entitlement_current_revision(string $subjectId,bool $lock=false): int
{
    $stmt=db()->prepare('SELECT revision FROM entitlement_revisions WHERE subject_id=?'.($lock?' FOR UPDATE':''));$stmt->execute([$subjectId]);
    $value=$stmt->fetchColumn();if($value===false){db()->prepare('INSERT INTO entitlement_revisions (subject_id,revision) VALUES (?,0)')->execute([$subjectId]);return 0;}
    return (int)$value;
}

/** @return array<string,mixed> */
function tegh_entitlement_set_locked(array $subject,string $featureKey,string $decision,string $source,?array $actor,string $reason,string $operationKey,?string $sourceReference=null,?string $validFrom=null,?string $validUntil=null): array
{
    if(!in_array($decision,['enabled','disabled'],true))throw new InvalidArgumentException('Entitlement decision is invalid.');
    $catalog=db()->prepare('SELECT * FROM feature_catalog WHERE feature_key=? FOR UPDATE');$catalog->execute([$featureKey]);$feature=$catalog->fetch();
    if(!$feature)throw new InvalidArgumentException('Feature key is not registered.');
    $permittedScope=(string)$feature['permitted_scope'];$subjectType=(string)$subject['subject_type'];
    if($permittedScope!==$subjectType&&!($permittedScope==='company'&&$subjectType==='company_user'))throw new InvalidArgumentException('Feature scope is incompatible with the entitlement subject.');
    if(strlen($operationKey)>120)$operationKey=hash('sha256',$operationKey);
    if($operationKey!==''){
        $history=db()->prepare('SELECT subject_id,feature_key,after_json FROM feature_entitlement_history WHERE operation_key=? LIMIT 1');$history->execute([$operationKey]);$priorHistory=$history->fetch();
        if($priorHistory){$after=json_decode((string)$priorHistory['after_json'],true);if(!is_array($after)||(string)$priorHistory['subject_id']!==(string)$subject['id']||(string)$priorHistory['feature_key']!==$featureKey||(string)($after['decision']??'')!==$decision)throw new DomainException('operation_key_conflict');return ['entitlement'=>$after,'revision'=>(int)($after['revision']??0),'idempotentReplay'=>true];}
        $prior=db()->prepare('SELECT * FROM feature_entitlements WHERE operation_key=? LIMIT 1');$prior->execute([$operationKey]);$existing=$prior->fetch();
        if($existing){if((string)$existing['subject_id']!==(string)$subject['id']||(string)$existing['feature_key']!==$featureKey||(string)$existing['decision']!==$decision)throw new DomainException('operation_key_conflict');return ['entitlement'=>$existing,'revision'=>(int)$existing['revision'],'idempotentReplay'=>true];}
    }
    $currentStmt=db()->prepare('SELECT * FROM feature_entitlements WHERE subject_id=? AND feature_key=? FOR UPDATE');$currentStmt->execute([$subject['id'],$featureKey]);$before=$currentStmt->fetch()?:null;
    $currentRevision=tegh_entitlement_current_revision((string)$subject['id'],true);$revision=$currentRevision+1;
    db()->prepare('UPDATE entitlement_revisions SET revision=? WHERE subject_id=?')->execute([$revision,$subject['id']]);
    $id=$before?(string)$before['id']:new_id('entitlement');
    $requestReference=request_id();
    $validFrom=$validFrom??gmdate('Y-m-d H:i:s');
    db()->prepare("INSERT INTO feature_entitlements
      (id,subject_id,feature_key,decision,source,valid_from,valid_until,revision,reason,actor_user_id,request_reference,operation_key)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE decision=VALUES(decision),source=VALUES(source),valid_from=VALUES(valid_from),valid_until=VALUES(valid_until),
        revision=VALUES(revision),reason=VALUES(reason),actor_user_id=VALUES(actor_user_id),request_reference=VALUES(request_reference),operation_key=VALUES(operation_key)")
      ->execute([$id,$subject['id'],$featureKey,$decision,$source,$validFrom,$validUntil,$revision,$reason,$actor['id']??null,$requestReference,$operationKey!==''?$operationKey:null]);
    $after=['id'=>$id,'subjectId'=>(string)$subject['id'],'featureKey'=>$featureKey,'decision'=>$decision,'source'=>$source,'revision'=>$revision,'reason'=>$reason,'validFrom'=>$validFrom,'validUntil'=>$validUntil];
    db()->prepare('INSERT INTO feature_entitlement_history
      (id,entitlement_id,subject_id,feature_key,entitlement_revision,before_json,after_json,actor_user_id,source,request_reference,source_reference,operation_key,reason,ip_hash,user_agent_hash)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        new_id('enthistory'),$id,$subject['id'],$featureKey,$revision,
        json_encode($before?:[],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),json_encode($after,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
        $actor['id']??null,$source,$requestReference,$sourceReference,$operationKey!==''?$operationKey:null,$reason,client_ip_hash(),user_agent_hash(),
      ]);
    return ['entitlement'=>$after,'revision'=>$revision,'idempotentReplay'=>false];
}

function tegh_schema40_seed_existing_access(): void
{
    $enabled=['core.accounting','audit.basic','banking.manual_reconciliation','tegh.ask.base','module.payroll','module.document_intake','module.collections','module.financial_analysis'];
    $disabled=['tegh.ai.advanced','tegh.command_centre','banking.reconciliation.advanced','banking.reconciliation.bulk_match','banking.reconciliation.match_post'];
    $ownsTransaction=!db()->inTransaction();if($ownsTransaction)db()->beginTransaction();
    try{
        $companies=db()->query('SELECT id,name FROM companies WHERE active=1 ORDER BY id')->fetchAll();
        foreach($companies as $company){
            $subject=tegh_entitlement_subject('company',null,(string)$company['id'],null,(string)$company['name']);
            foreach(array_merge($enabled,$disabled) as $feature){
                $decision=in_array($feature,$enabled,true)?'enabled':'disabled';
                $key=hash('sha256','schema40-migration|'.$company['id'].'|'.$feature.'|'.$decision);
                tegh_entitlement_set_locked($subject,$feature,$decision,'migration',null,'Schema 40 compatibility migration preserves existing reachability and disables new advanced capabilities.',$key,'schema40-migration');
            }
        }
        if($ownsTransaction)db()->commit();
    }catch(Throwable $error){if($ownsTransaction&&db()->inTransaction())db()->rollBack();throw $error;}
}

/** @return array<string,mixed> */
function tegh_entitlement_shadow_parity(): array
{
    $missing=[];$base=['core.accounting','audit.basic','banking.manual_reconciliation','tegh.ask.base'];$legacyPreserved=['module.payroll','module.document_intake','module.collections','module.financial_analysis'];
    if(schema_table_exists('companies')){
        $stmt=db()->query('SELECT id,name FROM companies WHERE active=1 ORDER BY id');
        foreach($stmt->fetchAll() as $company){
            $subject=tegh_entitlement_subject_existing('company',null,(string)$company['id'],null);if(!$subject){$missing[]=['companyId'=>(string)$company['id'],'featureKey'=>'company_subject'];continue;}
            $migrated=db()->prepare("SELECT 1 FROM feature_entitlements WHERE subject_id=? AND source='migration' LIMIT 1");$migrated->execute([$subject['id']]);$required=$migrated->fetchColumn()?array_merge($base,$legacyPreserved):$base;
            foreach($required as $feature){$q=db()->prepare("SELECT 1 FROM feature_entitlements WHERE subject_id=? AND feature_key=? AND decision='enabled' LIMIT 1");$q->execute([$subject['id'],$feature]);if(!$q->fetchColumn())$missing[]=['companyId'=>(string)$company['id'],'featureKey'=>$feature];}
        }
    }
    return ['passed'=>$missing===[],'mismatches'=>$missing,'legacyModuleModeAuthoritative'=>false,'checkedAt'=>gmdate('c')];
}

function tegh_schema40_set_mode(string $mode): void
{
    if(!in_array($mode,[TEGH_ENTITLEMENT_MODE_SHADOW,TEGH_ENTITLEMENT_MODE_ENFORCED],true))throw new InvalidArgumentException('Entitlement mode is invalid.');
    db()->prepare("INSERT INTO app_meta (meta_key,meta_value) VALUES ('entitlement_mode',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute([$mode]);
}

function tegh_entitlement_mode(): string
{
    if(!schema_table_exists('app_meta'))return TEGH_ENTITLEMENT_MODE_SHADOW;
    $stmt=db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key='entitlement_mode' LIMIT 1");$stmt->execute();
    $mode=(string)($stmt->fetchColumn()?:TEGH_ENTITLEMENT_MODE_SHADOW);return $mode===TEGH_ENTITLEMENT_MODE_ENFORCED?$mode:TEGH_ENTITLEMENT_MODE_SHADOW;
}

/** @return array<string,mixed>|null */
function tegh_entitlement_subject_existing(string $type,?string $accountUserId,?string $companyId,?string $userId): ?array
{
    if(!schema_table_exists('entitlement_subjects'))return null;
    $identity=tegh_entitlement_identity($type,$accountUserId,$companyId,$userId);
    $stmt=db()->prepare('SELECT * FROM entitlement_subjects WHERE identity_key=? LIMIT 1');$stmt->execute([$identity]);return $stmt->fetch()?:null;
}

/** @return array<string,mixed>|null */
function tegh_entitlement_projection(?array $subject,string $featureKey): ?array
{
    if(!$subject)return null;
    $stmt=db()->prepare("SELECT * FROM feature_entitlements WHERE subject_id=? AND feature_key=?
      AND (valid_from IS NULL OR valid_from<=UTC_TIMESTAMP()) AND (valid_until IS NULL OR valid_until>UTC_TIMESTAMP()) LIMIT 1");
    $stmt->execute([$subject['id'],$featureKey]);return $stmt->fetch()?:null;
}

/** @return array<string,mixed> */
function tegh_resolve_feature(array $user,array $company,string $featureKey,array $trail=[]): array
{
    if(tegh_provider_feature($featureKey)&&!tegh_connected_release_enabled())return ['enabled'=>false,'allowed'=>false,'featureKey'=>$featureKey,'reason'=>'connected_unavailable_in_release','decision'=>'disabled','revision'=>0,'mode'=>TEGH_ENTITLEMENT_MODE_ENFORCED,'dependencies'=>[],'wouldAllow'=>false,'billable'=>false];
    if(!schema_table_exists('feature_catalog'))return ['featureKey'=>$featureKey,'allowed'=>false,'decision'=>'disabled','reason'=>'schema40_unavailable','revision'=>0,'mode'=>TEGH_ENTITLEMENT_MODE_SHADOW,'dependencies'=>[]];
    if(in_array($featureKey,$trail,true))return ['featureKey'=>$featureKey,'allowed'=>false,'decision'=>'disabled','reason'=>'dependency_cycle','revision'=>0,'mode'=>tegh_entitlement_mode(),'dependencies'=>[]];
    $stmt=db()->prepare('SELECT * FROM feature_catalog WHERE feature_key=? LIMIT 1');$stmt->execute([$featureKey]);$feature=$stmt->fetch();
    if(!$feature)return ['featureKey'=>$featureKey,'allowed'=>false,'decision'=>'disabled','reason'=>'unknown_feature','revision'=>0,'mode'=>tegh_entitlement_mode(),'dependencies'=>[]];
    $account=tegh_entitlement_subject_existing('account',(string)$user['id'],null,null);
    $companySubject=tegh_entitlement_subject_existing('company',null,(string)$company['id'],null);
    $companyUser=tegh_entitlement_subject_existing('company_user',null,(string)$company['id'],(string)$user['id']);
    $accountProjection=tegh_entitlement_projection($account,$featureKey);$companyProjection=tegh_entitlement_projection($companySubject,$featureKey);$userProjection=tegh_entitlement_projection($companyUser,$featureKey);
    $decision=(string)$feature['default_decision'];$reason='catalog_default';$scope=(string)$feature['permitted_scope'];
    if($scope==='account'){
        if($accountProjection){$decision=(string)$accountProjection['decision'];$reason=str_starts_with((string)$accountProjection['reason'],'Suspended:')?'account_suspended':'account_projection';}
    }else{
        // The catalogue default is only a fallback, not an account-level
        // denial. An explicit company projection can therefore enable an
        // advanced company feature whose safe default is disabled.
        if($companyProjection){$decision=(string)$companyProjection['decision'];$reason=$decision==='disabled'?(str_starts_with((string)$companyProjection['reason'],'Suspended:')?'company_suspended':'company_deny_precedence'):'company_projection';}
        // A company-user projection may narrow an effective company grant,
        // but can never elevate a disabled company decision.
        if($decision==='enabled'&&$userProjection){$decision=(string)$userProjection['decision'];$reason=$decision==='disabled'?(str_starts_with((string)$userProjection['reason'],'Suspended:')?'company_user_suspended':'company_user_narrowing'):'company_user_projection';}
    }
    if((string)$feature['operational_state']!=='active'){$decision='disabled';$reason='platform_feature_'.(string)$feature['operational_state'];}
    $dependencies=[];
    if($decision==='enabled'){
        $dependencyStmt=db()->prepare("SELECT related_feature_key,relation_type FROM feature_dependencies WHERE feature_key=? ORDER BY relation_type,related_feature_key");$dependencyStmt->execute([$featureKey]);
        foreach($dependencyStmt->fetchAll() as $dependency){
            $related=tegh_resolve_feature($user,$company,(string)$dependency['related_feature_key'],array_merge($trail,[$featureKey]));
            $dependencies[]=['featureKey'=>(string)$dependency['related_feature_key'],'relation'=>(string)$dependency['relation_type'],'allowed'=>(bool)$related['allowed']];
            if(($dependency['relation_type']==='requires'&&!$related['allowed'])||($dependency['relation_type']==='conflicts'&&$related['allowed'])){$decision='disabled';$reason='dependency_not_satisfied';break;}
        }
    }
    $revisions=[];foreach([$account,$companySubject,$companyUser] as $subject)if($subject)$revisions[]=tegh_entitlement_current_revision((string)$subject['id']);
    $mode=tegh_entitlement_mode();$effective=$decision==='enabled';
    return ['featureKey'=>$featureKey,'name'=>(string)$feature['display_name'],'allowed'=>$effective,
        'wouldAllow'=>$effective,'decision'=>$decision,'reason'=>$reason,'revision'=>$revisions?max($revisions):0,'mode'=>$mode,
        'billable'=>(bool)$feature['billable'],'metered'=>(bool)$feature['metered'],'operationalState'=>(string)$feature['operational_state'],'dependencies'=>$dependencies];
}

function tegh_require_feature(array $user,array $company,string $featureKey): array
{
    $resolved=tegh_resolve_feature($user,$company,$featureKey);
    if(!$resolved['allowed'])json_response([
        'error'=>'This feature is unavailable or has been suspended by the Platform Owner.',
        'code'=>'feature_not_entitled',
        'feature'=>['featureKey'=>$featureKey,'name'=>(string)($resolved['name']??$featureKey),'state'=>(string)($resolved['reason']??'disabled')],
        'requestAccess'=>['available'=>false],
        'entitlementRevision'=>(int)($resolved['revision']??0),'requestId'=>request_id(),
    ],403);
    return $resolved;
}

/** @return array<string,string> */
function tegh_route_feature_map(): array
{
    return [
        'payroll'=>'module.payroll','payroll-tax-agent'=>'module.payroll',
        'native-agent/documents'=>'module.document_intake','native-agent/collections'=>'module.collections',
        'native-ap-ar/document'=>'module.document_intake','native-ap-ar/vendor-learning'=>'module.document_intake','native-ap-ar/collections'=>'module.collections',
        'financial-analysis'=>'module.financial_analysis','ai/categorize'=>'tegh.ai.advanced','ai-research'=>'tegh.ai.advanced',
        'command-centre'=>'tegh.command_centre','operations/authorized-match-post'=>'banking.reconciliation.match_post',
        'operations/reconciliation-suggestions'=>'banking.reconciliation.advanced',
        'operations/review-payment-suggestions'=>'banking.reconciliation.advanced',
        'operations/reconciliation-match-bulk'=>'banking.reconciliation.bulk_match',
    ];
}

function tegh_feature_for_current_route(): ?string
{
    $route=trim((string)($_GET['route']??''),'/');$best='';$feature=null;
    foreach(tegh_route_feature_map() as $prefix=>$candidate)if(($route===$prefix||str_starts_with($route,$prefix.'/'))&&strlen($prefix)>strlen($best)){$best=$prefix;$feature=$candidate;}
    return $feature;
}

function tegh_disabled_feature_history_read_allowed(): bool
{
    if(!in_array(request_method(),['GET','HEAD'],true))return false;
    $route=trim((string)($_GET['route']??''),'/');
    if(in_array($route,[
        'payroll','payroll/workspace','payroll/remittances','payroll/t4-working-paper',
        'payroll-tax-agent','payroll-tax-agent/overview','payroll-tax-agent/runs',
        'native-ap-ar','native-ap-ar/overview','native-ap-ar/documents','native-ap-ar/document/file',
        'native-ap-ar/collections','native-ap-ar/collections/templates','native-ap-ar/collections/delivery',
        'financial-analysis/scenarios','financial-analysis/scenario',
    ],true))return true;
    return false;
}

function tegh_enforce_current_route_feature(array $company): void
{
    $feature=tegh_feature_for_current_route();if($feature===null)return;
    // Posted/history records remain readable only through explicit historical
    // routes. New calculations, suggestions and preparation remain gated even
    // when their transport is GET.
    if(tegh_disabled_feature_history_read_allowed())return;
    $user=require_user();tegh_require_feature($user,$company,$feature);
}

function tegh_usage_record(string $companyId,string $featureKey,bool $success,int $units=1): void
{
    if(!schema_table_exists('feature_usage_daily')||$units<0)return;
    $successCount=$success?1:0;$failureCount=$success?0:1;
    db()->prepare('INSERT INTO feature_usage_daily (company_id,feature_key,usage_date,success_count,failure_count,unit_count)
      VALUES (?,?,UTC_DATE(),?,?,?) ON DUPLICATE KEY UPDATE success_count=success_count+VALUES(success_count),failure_count=failure_count+VALUES(failure_count),unit_count=unit_count+VALUES(unit_count)')
      ->execute([$companyId,$featureKey,$successCount,$failureCount,$units]);
}

/** Authoritative company-only resolution for schedulers and zero-write agents. */
function tegh_background_feature_allowed(string $companyId,string $featureKey,array $trail=[]): bool
{
    if(in_array($featureKey,$trail,true)||!schema_table_exists('feature_catalog'))return false;
    $featureStmt=db()->prepare('SELECT default_decision,operational_state FROM feature_catalog WHERE feature_key=? LIMIT 1');$featureStmt->execute([$featureKey]);$feature=$featureStmt->fetch();
    if(!$feature||(string)$feature['operational_state']!=='active')return false;
    $subject=tegh_entitlement_subject_existing('company',null,$companyId,null);$projection=tegh_entitlement_projection($subject,$featureKey);
    $enabled=$projection?(string)$projection['decision']==='enabled':(string)$feature['default_decision']==='enabled';if(!$enabled)return false;
    $dependency=db()->prepare('SELECT related_feature_key,relation_type FROM feature_dependencies WHERE feature_key=? ORDER BY relation_type,related_feature_key');$dependency->execute([$featureKey]);
    foreach($dependency->fetchAll() as $row){$related=tegh_background_feature_allowed($companyId,(string)$row['related_feature_key'],array_merge($trail,[$featureKey]));if(($row['relation_type']==='requires'&&!$related)||($row['relation_type']==='conflicts'&&$related))return false;}
    return true;
}

function tegh_background_feature_for_agent(string $agent): string
{
    return match($agent){
        'reconciliation'=>'banking.reconciliation.advanced',
        'accounts_receivable'=>'module.collections',
        'financial_analyst'=>'module.financial_analysis',
        'payroll_tax'=>'module.payroll',
        default=>'core.accounting',
    };
}

/** @return array<string,mixed> */
function tegh_schema40_preflight(): array
{
    $marker=current_database_schema_version();$levels=tegh_schema_structural_levels();$schema39=(bool)($levels[39]??false);$status=tegh_schema40_status();
    $state='unsupported_or_unsafe';$label='Unsupported or unsafe database state';$retry=false;$mutation=true;$schema39Preflight=null;
    if($marker>40){$state='newer_schema';$label='Database is newer than this release';$mutation=false;}
    elseif(!$schema39){
        $schema39Preflight=tegh_schema39_preflight();$state='schema39_'.$schema39Preflight['state'];$label='Schema 39 prerequisite: '.$schema39Preflight['stateLabel'];$retry=(bool)$schema39Preflight['retrySafe'];
    }elseif($marker===40&&$status['ready']){$state='complete_schema_40';$label='Database is already up to date';$retry=true;$mutation=false;}
    elseif($marker===40){$unsafe=array_filter($status['issues'],static fn(array $issue):bool=>empty($issue['repairable']));$state='marker_40_incomplete';$label=$unsafe?'Schema marker is 40 but the entitlement contract is malformed':'Schema marker is 40 but entitlement structures are incomplete';$retry=$unsafe===[];}
    elseif($status['ready']){$state='schema_40_objects_stale_marker';$label='Schema 40 objects are complete but the marker is stale';$retry=true;}
    elseif($status['presentTableCount']===0){$state='complete_schema_39';$label='Complete Schema 39 is eligible for the Schema 40 cumulative upgrade';$retry=true;}
    else{
        $unsafe=array_filter($status['issues'],static fn(array $issue):bool=>empty($issue['repairable']));
        $state='partial_schema_40';$label=$unsafe?'Schema 40 contains a malformed partial contract':'Schema 40 was partially applied and can resume';$retry=$unsafe===[];
    }
    $capabilities=tegh_database_upgrade_capabilities();if($mutation&&(!$capabilities['privilegesReady']||!$capabilities['advisoryLockSupported']))$retry=false;
    $lastStep=null;if(schema_table_exists('database_migration_events')){$q=db()->query("SELECT migration_id,state,request_reference,started_at,completed_at FROM database_migration_events WHERE migration_id LIKE 'schema40.%' ORDER BY event_id DESC LIMIT 1");$lastStep=$q->fetch()?:null;}
    $structuralVersion=$status['ready']?40:($schema39?39:(int)($schema39Preflight['structuralVersion']??0));
    return ['state'=>$state,'stateLabel'=>$label,'markerVersion'=>$marker,'structuralVersion'=>$structuralVersion,'expectedSchemaVersion'=>40,
        'ready'=>$state==='complete_schema_40','retrySafe'=>$retry,'mutationRequired'=>$mutation,'schema39Ready'=>$schema39,
        'contract'=>$status,'capabilities'=>$capabilities,'privateRuntime'=>tegh_private_runtime_status(),'lastMigrationEvent'=>$lastStep,
        'entitlementMode'=>$status['ready']?tegh_entitlement_mode():TEGH_ENTITLEMENT_MODE_SHADOW,
        'build'=>SR_ACCOUNTAX_BUILD,'version'=>SR_ACCOUNTAX_VERSION,'requestReference'=>request_id(),'checkedAt'=>gmdate('c')];
}

function tegh_schema40_private_diagnostic(array $preflight): void
{
    $event=['requestId'=>request_id(),'occurredAt'=>gmdate('c'),'source'=>'schema40_preflight','route'=>'startup/schema40-preflight','httpStatus'=>200,
        'errorCode'=>'schema40_preflight','publicMessage'=>(string)$preflight['stateLabel'],'context'=>system_incident_clean_context($preflight)];
    system_incident_write_private_log($event);
}

function tegh_schema40_mark_checkpoint(string $key,string $value): void
{
    db()->prepare('INSERT INTO app_meta (meta_key,meta_value) VALUES (?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)')->execute([$key,$value]);
}

function tegh_schema40_meta_value(string $key): ?string
{
    $stmt=db()->prepare('SELECT meta_value FROM app_meta WHERE meta_key=? LIMIT 1');$stmt->execute([$key]);$value=$stmt->fetchColumn();return $value===false?null:(string)$value;
}

/** @return array<string,mixed> */
function tegh_schema40_upgrade(array $user,bool $backupConfirmed): array
{
    $initial=tegh_schema40_preflight();$previous=(int)$initial['markerVersion'];$steps=[];
    if($initial['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'message'=>'Database is already up to date','previousSchema'=>40,'schemaVersion'=>40,'steps'=>[],'preflight'=>$initial,
        'entitlementMode'=>tegh_entitlement_mode(),'enforcementActivationRequired'=>tegh_entitlement_mode()!==TEGH_ENTITLEMENT_MODE_ENFORCED];
    if(!$backupConfirmed)fail('Confirm the database, website files and private storage backup before starting the upgrade.',409,'database_backup_required');
    if(!$initial['retrySafe'])fail('The protected preflight found an unsupported or unsafe database state. View the diagnostic before retrying.',409,'schema_upgrade_unsafe');
    if(!$initial['schema39Ready']){
        $schema39=tegh_schema39_upgrade($user,true);$steps=array_merge($steps,(array)($schema39['steps']??[]));
    }
    $lock=(int)db()->query("SELECT GET_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."',2)")->fetchColumn();
    if($lock!==1)fail('Another Tegh database upgrade is still running. Wait a moment and retry.',409,'schema_upgrade_busy');
    try{
        $fresh=tegh_schema40_preflight();if(!$fresh['retrySafe']&&!$fresh['ready'])throw new RuntimeException('Schema 40 preflight found an unsupported or unsafe partial contract.');
        if($fresh['ready'])return ['ok'=>true,'alreadyUpToDate'=>true,'message'=>'Database is already up to date','previousSchema'=>$previous,'schemaVersion'=>40,'steps'=>$steps,'preflight'=>$fresh,
            'entitlementMode'=>tegh_entitlement_mode(),'enforcementActivationRequired'=>tegh_entitlement_mode()!==TEGH_ENTITLEMENT_MODE_ENFORCED];
        tegh_migration_ledger_ensure();
        foreach(tegh_schema40_table_sql() as $table=>$sql){
            tegh_run_migration_step('schema40.5400.table.'.$table,static fn()=>tegh_schema40_repair_table($table),static fn():bool=>tegh_schema40_table_contract_ready($table),$steps);
        }
        tegh_run_migration_step('schema40.5400.feature_catalog',static fn()=>tegh_feature_seed(),static fn():bool=>(int)db()->query('SELECT COUNT(*) FROM feature_catalog')->fetchColumn()>=count(tegh_feature_seed_catalog())&&tegh_feature_dependency_cycle()===[],$steps);
        tegh_run_migration_step('schema40.5400.compatibility_projection',static fn()=>tegh_schema40_seed_existing_access(),static fn():bool=>tegh_entitlement_shadow_parity()['passed'],$steps);
        tegh_run_migration_step('schema40.5400.shadow_mode',static function():void{tegh_schema40_set_mode(TEGH_ENTITLEMENT_MODE_SHADOW);tegh_schema40_mark_checkpoint('release_checkpoint_5400','passed');},static fn():bool=>tegh_entitlement_mode()===TEGH_ENTITLEMENT_MODE_SHADOW,$steps);
        $status=tegh_schema40_status();if(!$status['ready'])throw new RuntimeException('Schema 40 post-migration verification is incomplete.');
        tegh_run_migration_step('schema40.adopt_marker',static function():void{tegh_mark_schema_version(40);tegh_schema40_mark_checkpoint('schema_v40_data_ready','1');},static fn():bool=>current_database_schema_version()===40&&tegh_schema40_status()['ready'],$steps);
        system_incident_write_private_log(['requestId'=>request_id(),'occurredAt'=>gmdate('c'),'source'=>'schema40_entitlements','route'=>'startup/migrate','httpStatus'=>200,'errorCode'=>'schema_upgrade_completed','publicMessage'=>'Schema 40 upgrade completed.','context'=>system_incident_clean_context(['actorUserId'=>(string)$user['id'],'previousSchema'=>$previous,'resultingSchema'=>40,'steps'=>$steps,'accountingTransactionsPosted'=>0])]);
        $after=tegh_schema40_preflight();return ['ok'=>true,'alreadyUpToDate'=>false,'message'=>'Upgrade successful','previousSchema'=>$previous,'schemaVersion'=>40,'steps'=>$steps,'preflight'=>$after,
            'entitlementMode'=>TEGH_ENTITLEMENT_MODE_SHADOW,'enforcementActivationRequired'=>true,
            'nextAction'=>'Review the protected shadow parity matrix, then explicitly activate enforcement with recent authentication.'];
    }catch(Throwable $error){
        record_system_incident('Schema 40 upgrade could not be completed. Schema 39 core accounting remains available; entitlement functionality is unavailable.',500,'schema40_upgrade_failed',$error,['source'=>'schema40_entitlements','completedSteps'=>$steps,'initialPreflight'=>$initial]);throw $error;
    }finally{try{db()->query("SELECT RELEASE_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."')");}catch(Throwable){}}
}

function handle_tegh_schema40_preflight(): never
{
    require_method('GET');tegh_require_platform_owner();$result=tegh_schema40_preflight();tegh_schema40_private_diagnostic($result);json_response(['ok'=>true,'preflight'=>$result]);
}

function handle_tegh_schema40_diagnostic(): never
{
    require_method('GET');tegh_require_platform_owner();$result=tegh_schema40_preflight();tegh_schema40_private_diagnostic($result);$events=[];
    if(schema_table_exists('database_migration_events')){$stmt=db()->query('SELECT migration_id,migration_checksum,state,started_at,completed_at,request_reference,build_number,details_json FROM database_migration_events ORDER BY event_id DESC LIMIT 150');foreach($stmt->fetchAll() as $row){$row['details']=json_decode((string)$row['details_json'],true)?:[];unset($row['details_json']);$events[]=$row;}}
    json_response(['ok'=>true,'diagnostic'=>$result,'migrationEvents'=>$events]);
}

function handle_tegh_schema40_upgrade(): never
{
    require_method('POST');$user=tegh_require_platform_owner();require_csrf();$input=request_json();@ignore_user_abort(true);@set_time_limit(85);
    try{json_response(tegh_schema40_upgrade($user,!empty($input['backupConfirmed'])));}catch(Throwable $error){
        error_log('Tegh Schema 40 request='.request_id().' class='.$error::class.' message='.system_incident_redact_text($error->getMessage(),1000));
        fail('Database upgrade could not be completed. No accounting entries were changed. Structural preparation may be partial; Schema 39 core accounting remains available when separately verified. Give this request reference to the Platform Owner: '.request_id().'.',500,'schema_upgrade_failed',false);
    }
}

function tegh_entitlement_schema_required(): void
{
    if(current_database_schema_version()<40||!tegh_schema40_status()['ready'])fail('Feature access controls are unavailable until the protected Schema 40 upgrade is complete.',503,'entitlement_schema_unavailable');
}

function tegh_signup_intents_store(string $userId,mixed $rawFeatureKeys): void
{
    if(!is_array($rawFeatureKeys)||$rawFeatureKeys===[])return;
    if(!schema_table_exists('signup_feature_intents')||!schema_table_exists('feature_catalog'))throw new InvalidArgumentException('Optional feature selection is temporarily unavailable while Tegh is being upgraded.');
    $keys=array_values(array_unique(array_filter(array_map(static fn($value):string=>trim((string)$value),$rawFeatureKeys))));if(count($keys)>20)throw new InvalidArgumentException('Choose no more than 20 optional features.');
    $lookup=db()->prepare("SELECT feature_key FROM feature_catalog WHERE feature_key=? AND operational_state='active' AND default_decision='disabled' LIMIT 1");
    $insert=db()->prepare("INSERT INTO signup_feature_intents (id,user_id,feature_key,state,request_reference) VALUES (?,?,?,'pending',?) ON DUPLICATE KEY UPDATE request_reference=VALUES(request_reference)");
    foreach($keys as $key){$lookup->execute([$key]);if(!$lookup->fetchColumn())throw new InvalidArgumentException('A selected optional feature is unavailable.');$insert->execute([new_id('signupintent'),$userId,$key,request_id()]);}
}

function tegh_company_entitlements_initialize(array $user,string $companyId,string $companyName,bool $attachSignupIntent=true): void
{
    if(!schema_table_exists('feature_entitlements')||current_database_schema_version()<40)return;
    tegh_native_package_initialize($companyId,$companyName,$user);
}

/** @return array<int,array<string,mixed>> */
function tegh_feature_catalog_rows(): array
{
    $dependencies=[];foreach(db()->query('SELECT feature_key,related_feature_key,relation_type FROM feature_dependencies ORDER BY feature_key,relation_type,related_feature_key')->fetchAll() as $row)$dependencies[(string)$row['feature_key']][]=['featureKey'=>(string)$row['related_feature_key'],'relation'=>(string)$row['relation_type']];
    return array_map(static fn(array $row):array=>[
        'featureKey'=>(string)$row['feature_key'],'name'=>(string)$row['display_name'],'description'=>(string)$row['description'],
        'kind'=>(string)$row['kind'],'scope'=>(string)$row['permitted_scope'],'defaultDecision'=>(string)$row['default_decision'],
        'operationalState'=>(string)$row['operational_state'],'billable'=>(bool)$row['billable'],'metered'=>(bool)$row['metered'],
        'displayOrder'=>(int)$row['display_order'],'dependencies'=>$dependencies[(string)$row['feature_key']]??[],
    ],db()->query('SELECT * FROM feature_catalog ORDER BY display_order,feature_key')->fetchAll());
}

/** @return array<int,array<string,mixed>> */
function tegh_entitlement_request_rows(string $where='1=1',array $params=[]): array
{
    $stmt=db()->prepare("SELECT r.*,c.name company_name,ru.email requester_email,ru.display_name requester_name,tu.email target_email
      FROM entitlement_requests r LEFT JOIN companies c ON c.id=r.company_id
      JOIN users ru ON ru.id=r.requester_user_id LEFT JOIN users tu ON tu.id=r.target_user_id
      WHERE $where ORDER BY r.created_at DESC LIMIT 250");$stmt->execute($params);$rows=$stmt->fetchAll();
    if(!$rows)return [];$ids=array_column($rows,'id');$ph=implode(',',array_fill(0,count($ids),'?'));$items=[];
    $itemStmt=db()->prepare("SELECT i.*,f.display_name FROM entitlement_request_items i JOIN feature_catalog f ON f.feature_key=i.feature_key WHERE i.request_id IN ($ph) ORDER BY i.request_id,i.item_order");$itemStmt->execute($ids);
    foreach($itemStmt->fetchAll() as $item)$items[(string)$item['request_id']][]=['featureKey'=>(string)$item['feature_key'],'name'=>(string)$item['display_name'],'decision'=>(string)$item['requested_decision']];
    return array_map(static fn(array $row):array=>[
        'id'=>(string)$row['id'],'companyId'=>$row['company_id']!==null?(string)$row['company_id']:null,'companyName'=>(string)($row['company_name']??$row['company_id']??'Deleted company'),
        'requesterUserId'=>(string)$row['requester_user_id'],'requesterName'=>(string)$row['requester_name'],'requesterEmail'=>(string)$row['requester_email'],
        'targetScope'=>(string)$row['target_scope'],'targetUserId'=>$row['target_user_id']!==null?(string)$row['target_user_id']:null,'targetEmail'=>$row['target_email']!==null?(string)$row['target_email']:null,
        'message'=>(string)$row['message'],'state'=>(string)$row['state'],'endorsementState'=>(string)$row['endorsement_state'],
        'decisionReason'=>$row['decision_reason']!==null?(string)$row['decision_reason']:null,'fulfilledRevision'=>$row['fulfilled_revision']!==null?(int)$row['fulfilled_revision']:null,
        'requestReference'=>(string)$row['request_reference'],'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at'],'items'=>$items[(string)$row['id']]??[],
    ],$rows);
}

/** @return array<string,mixed> */
function tegh_entitlement_my_payload(array $user,array $company): array
{
    $features=[];foreach(tegh_feature_catalog_rows() as $catalog){$features[]=array_merge($catalog,['effective'=>tegh_resolve_feature($user,$company,(string)$catalog['featureKey'])]);}
    $subjects=[];foreach([
        tegh_entitlement_subject_existing('account',(string)$user['id'],null,null),
        tegh_entitlement_subject_existing('company',null,(string)$company['id'],null),
        tegh_entitlement_subject_existing('company_user',null,(string)$company['id'],(string)$user['id']),
    ] as $subject)if($subject)$subjects[]=(string)$subject['id'];
    $revision=0;foreach($subjects as $id)$revision=max($revision,tegh_entitlement_current_revision($id));
    return ['features'=>$features,'requests'=>tegh_entitlement_request_rows('r.company_id=? AND (r.requester_user_id=? OR r.target_user_id=?)',[(string)$company['id'],(string)$user['id'],(string)$user['id']]),
        'revision'=>$revision,'mode'=>tegh_entitlement_mode(),'legacyCompatibility'=>['moduleMode'=>(string)($company['module_mode']??''),'accountPlan'=>'informational_only']];
}

function tegh_entitlement_request_create(array $user,array $company): never
{
    require_method('POST');require_csrf();$input=request_json();
    $rate=db()->prepare('SELECT COUNT(*) FROM entitlement_requests WHERE requester_user_id=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR');$rate->execute([$user['id']]);
    if((int)$rate->fetchColumn()>=10)fail('Too many access requests were created recently. Try again later.',429,'entitlement_request_rate_limited');
    $scope=(string)($input['scope']??'company_user');if(!in_array($scope,['company','company_user'],true))fail('Access request scope is invalid.',422,'entitlement_scope_invalid');
    $role=(string)$company['role'];$isAdmin=in_array($role,['owner','admin'],true);$targetUserId=$scope==='company_user'?(string)($input['targetUserId']??$user['id']):null;
    if($scope==='company_user'&&!$isAdmin&&!hash_equals((string)$user['id'],$targetUserId))fail('You can request personal access only for yourself.',403,'entitlement_target_forbidden');
    if($scope==='company_user'){$member=db()->prepare("SELECT 1 FROM company_members WHERE company_id=? AND user_id=? AND status='active' LIMIT 1");$member->execute([$company['id'],$targetUserId]);if(!$member->fetchColumn())fail('The selected company user is unavailable.',403,'entitlement_target_forbidden',false);}
    $rawItems=$input['items']??[];if(!is_array($rawItems)||count($rawItems)<1||count($rawItems)>20)fail('Choose between 1 and 20 feature changes.',422,'entitlement_items_invalid');
    $items=[];foreach($rawItems as $index=>$raw){if(!is_array($raw))fail('A feature request item is invalid.',422,'entitlement_item_invalid');$key=(string)($raw['featureKey']??'');$decision=(string)($raw['decision']??'enabled');if(!in_array($decision,['enabled','disabled'],true))fail('A requested feature decision is invalid.',422,'entitlement_decision_invalid');$f=db()->prepare('SELECT permitted_scope,operational_state FROM feature_catalog WHERE feature_key=? LIMIT 1');$f->execute([$key]);$feature=$f->fetch();if(!$feature||$feature['operational_state']==='retired')fail('A requested feature is unavailable.',422,'feature_unavailable');if(!in_array((string)$feature['permitted_scope'],[$scope,'company'],true))fail('A requested feature does not support this scope.',422,'feature_scope_invalid');$items[$key]=['featureKey'=>$key,'decision'=>$decision,'order'=>$index];}
    ksort($items,SORT_STRING);$dedup=hash('sha256',json_encode(['companyId'=>$company['id'],'scope'=>$scope,'targetUserId'=>$targetUserId,'items'=>$items],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $message=mb_substr(trim((string)($input['message']??'')),0,1000);$endorsement=$scope==='company'&&!$isAdmin?'required':'not_required';$id=new_id('entrequest');
    // Keep the statement separate and explicit: some shared hosts report
    // duplicate-key SQL differently, and equivalent pending requests must be
    // returned rather than multiplied.
    try{
        db()->beginTransaction();
        db()->prepare("INSERT INTO entitlement_requests (id,requester_user_id,company_id,target_scope,target_user_id,message,state,endorsement_state,deduplication_key,pending_dedup_key,request_reference)
          VALUES (?,?,?,?,?,?,'pending',?,?,?,?)")->execute([$id,$user['id'],$company['id'],$scope,$targetUserId,$message,$endorsement,$dedup,$dedup,request_id()]);
        $insert=db()->prepare('INSERT INTO entitlement_request_items (id,request_id,feature_key,requested_decision,item_order) VALUES (?,?,?,?,?)');foreach($items as $item)$insert->execute([new_id('entrequestitem'),$id,$item['featureKey'],$item['decision'],$item['order']]);
        audit_event($user,(string)$company['id'],'entitlement.request_created','entitlement_request',$id,['scope'=>$scope,'featureKeys'=>array_keys($items),'endorsementRequired'=>$endorsement==='required']);
        db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();if($error instanceof PDOException&&(string)$error->getCode()==='23000'){$existing=db()->prepare("SELECT id FROM entitlement_requests WHERE pending_dedup_key=? AND state='pending' LIMIT 1");$existing->execute([$dedup]);$existingId=$existing->fetchColumn();if($existingId!==false)json_response(['request'=>tegh_entitlement_request_rows('r.id=?',[(string)$existingId])[0]??null,'idempotentReplay'=>true]);}throw $error;}
    json_response(['request'=>tegh_entitlement_request_rows('r.id=?',[$id])[0]??null],201);
}

function tegh_entitlement_request_cancel(array $user,array $company): never
{
    require_method('POST');require_csrf();$input=request_json();$id=clean_text($input['requestId']??'','Access request',64);
    $idempotent=false;
    try{
        db()->beginTransaction();
        $lock=db()->prepare('SELECT state FROM entitlement_requests WHERE id=? AND company_id=? AND requester_user_id=? FOR UPDATE');$lock->execute([$id,$company['id'],$user['id']]);$state=$lock->fetchColumn();
        if($state===false)throw new DomainException('request_unavailable');
        if($state==='cancelled')$idempotent=true;
        elseif($state!=='pending')throw new DomainException('request_changed');
        else{
            db()->prepare("UPDATE entitlement_requests SET state='cancelled',pending_dedup_key=NULL,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);
            audit_event($user,(string)$company['id'],'entitlement.request_cancelled','entitlement_request',$id,[]);
        }
        db()->commit();
    }catch(DomainException $error){if(db()->inTransaction())db()->rollBack();fail('The pending access request is unavailable.',409,'entitlement_request_changed',false);}catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    json_response(['request'=>tegh_entitlement_request_rows('r.id=?',[$id])[0]??null,'idempotentReplay'=>$idempotent]);
}

function tegh_entitlement_request_endorse(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_permission($company,'users.manage');$input=request_json();$id=clean_text($input['requestId']??'','Access request',64);$approve=!empty($input['endorse']);
    $newState=$approve?'endorsed':'rejected';$idempotent=false;
    try{
        db()->beginTransaction();
        $lock=db()->prepare('SELECT state,endorsement_state FROM entitlement_requests WHERE id=? AND company_id=? FOR UPDATE');$lock->execute([$id,$company['id']]);$request=$lock->fetch();
        if(!$request||$request['state']!=='pending')throw new DomainException('request_changed');
        if($request['endorsement_state']===$newState)$idempotent=true;
        elseif($request['endorsement_state']!=='required')throw new DomainException('request_changed');
        else{
            db()->prepare('UPDATE entitlement_requests SET endorsement_state=?,endorsed_by=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$newState,$user['id'],$id]);
            audit_event($user,(string)$company['id'],$approve?'entitlement.request_endorsed':'entitlement.request_endorsement_rejected','entitlement_request',$id,[]);
        }
        db()->commit();
    }catch(DomainException $error){if(db()->inTransaction())db()->rollBack();fail('The access request is no longer awaiting endorsement.',409,'entitlement_request_changed',false);}catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    json_response(['request'=>tegh_entitlement_request_rows('r.id=?',[$id])[0]??null,'idempotentReplay'=>$idempotent]);
}

function tegh_entitlement_recent_auth_required(): void
{
    $session=session_record();$authenticated=strtotime((string)($session['authenticated_at']??'').' UTC');
    if(!$authenticated||$authenticated<time()-900)fail('Sign in again before changing feature access.',401,'recent_authentication_required',false);
}

/** Platform Owner-only checkpoint that follows a separately reviewed shadow period. */
function tegh_platform_entitlement_enforcement_status(): array
{
    return [
        'mode'=>tegh_entitlement_mode(),'schemaReady'=>current_database_schema_version()>=40&&tegh_schema40_status()['ready'],
        'shadowParity'=>tegh_entitlement_shadow_parity(),
        'checkpoints'=>[
            '5400'=>tegh_schema40_meta_value('release_checkpoint_5400'),
            '5410'=>tegh_schema40_meta_value('release_checkpoint_5410'),
            '5420'=>tegh_schema40_meta_value('release_checkpoint_5420'),
            '5500'=>tegh_schema40_meta_value('release_checkpoint_5500'),
        ],
        'advancedFeaturesRemainProjectionControlled'=>true,'bookAccessGranted'=>false,
    ];
}

function tegh_platform_entitlement_enforce(array $user): never
{
    require_method('POST');require_csrf();admin_require_platform_owner($user);tegh_entitlement_recent_auth_required();$input=request_json();
    if(empty($input['compatibilityConfirmed']))fail('Confirm that the protected shadow compatibility matrix was reviewed before enforcement.',409,'entitlement_shadow_review_required',false);
    $reason=mb_substr(trim((string)($input['reason']??'')),0,1000);if(mb_strlen($reason)<12)fail('Record why shadow compatibility is ready for enforcement.',422,'entitlement_reason_required',false);
    if(current_database_schema_version()<40||!tegh_schema40_status()['ready'])fail('Complete and verify Schema 40 before activating enforcement.',409,'entitlement_schema_unavailable',false);
    $parity=tegh_entitlement_shadow_parity();if(!$parity['passed'])fail('Shadow compatibility has unresolved mismatches. Enforcement remains off.',409,'entitlement_shadow_mismatch',false);
    if(tegh_entitlement_mode()===TEGH_ENTITLEMENT_MODE_ENFORCED)json_response(['ok'=>true,'idempotentReplay'=>true,'enforcement'=>tegh_platform_entitlement_enforcement_status()]);
    $lock=(int)db()->query("SELECT GET_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."',2)")->fetchColumn();if($lock!==1)fail('Another Tegh database upgrade or enforcement activation is running.',409,'schema_upgrade_busy',false);
    $steps=[];$response=[];
    try{
        db()->beginTransaction();
        tegh_run_migration_step('schema40.5410.enforcement_gate',static function():void{tegh_schema40_set_mode(TEGH_ENTITLEMENT_MODE_ENFORCED);tegh_schema40_mark_checkpoint('release_checkpoint_5410','activated_after_shadow_review');},static fn():bool=>tegh_entitlement_mode()===TEGH_ENTITLEMENT_MODE_ENFORCED&&tegh_entitlement_shadow_parity()['passed'],$steps);
        tegh_run_migration_step('schema40.5420.command_centre_checkpoint',static fn()=>tegh_schema40_mark_checkpoint('release_checkpoint_5420','available_under_entitlement'),static fn():bool=>tegh_schema40_meta_value('release_checkpoint_5420')==='available_under_entitlement',$steps);
        tegh_run_migration_step('schema40.5500.advanced_reconciliation_checkpoint',static fn()=>tegh_schema40_mark_checkpoint('release_checkpoint_5500','available_under_entitlement'),static fn():bool=>tegh_schema40_meta_value('release_checkpoint_5500')==='available_under_entitlement',$steps);
        tegh_schema40_mark_checkpoint('entitlement_enforcement_reason_hash',hash('sha256',$reason));
        platform_audit_event($user,'platform.entitlement_enforcement_activated','entitlement_mode','schema40',['reason'=>$reason,'shadowParity'=>$parity,'steps'=>$steps,'bookAccessGranted'=>false]);
        db()->commit();$response=['ok'=>true,'idempotentReplay'=>false,'steps'=>$steps,'enforcement'=>tegh_platform_entitlement_enforcement_status()];
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    finally{try{db()->query("SELECT RELEASE_LOCK('".TEGH_DATABASE_UPGRADE_LOCK."')");}catch(Throwable){}}
    json_response($response);
}

function tegh_subject_feature_enabled(array $subject,string $featureKey): bool
{
    $catalog=db()->prepare('SELECT default_decision,operational_state FROM feature_catalog WHERE feature_key=? LIMIT 1');$catalog->execute([$featureKey]);$feature=$catalog->fetch();if(!$feature||$feature['operational_state']!=='active')return false;
    $projection=tegh_entitlement_projection($subject,$featureKey);return $projection?(string)$projection['decision']==='enabled':(string)$feature['default_decision']==='enabled';
}

/** @return array{requires:array<int,string>,dependants:array<int,string>,conflicts:array<int,string>} */
function tegh_entitlement_dependency_impact(array $subject,string $featureKey,string $decision): array
{
    $requires=[];$dependants=[];$conflicts=[];
    if($decision==='enabled'){
        $stmt=db()->prepare('SELECT related_feature_key,relation_type FROM feature_dependencies WHERE feature_key=?');$stmt->execute([$featureKey]);
        foreach($stmt->fetchAll() as $row){$related=(string)$row['related_feature_key'];if($row['relation_type']==='requires'&&!tegh_subject_feature_enabled($subject,$related))$requires[]=$related;if($row['relation_type']==='conflicts'&&tegh_subject_feature_enabled($subject,$related))$conflicts[]=$related;}
    }else{
        $stmt=db()->prepare("SELECT feature_key FROM feature_dependencies WHERE related_feature_key=? AND relation_type='requires'");$stmt->execute([$featureKey]);
        foreach($stmt->fetchAll() as $row){$dependant=(string)$row['feature_key'];if(tegh_subject_feature_enabled($subject,$dependant))$dependants[]=$dependant;}
    }
    return ['requires'=>array_values(array_unique($requires)),'dependants'=>array_values(array_unique($dependants)),'conflicts'=>array_values(array_unique($conflicts))];
}

/** @return array<int,array<string,mixed>> */
function tegh_entitlement_apply_cascade_locked(array $subject,string $featureKey,string $decision,array $actor,string $reason,string $operationKey,bool $cascade,?string $sourceReference=null,?string $validFrom=null,?string $validUntil=null,array $trail=[]): array
{
    if(in_array($featureKey,$trail,true))throw new DomainException('dependency_cycle');$trail[]=$featureKey;$impact=tegh_entitlement_dependency_impact($subject,$featureKey,$decision);$changes=[];
    if(($impact['requires']||$impact['dependants']||$impact['conflicts'])&&!$cascade)throw new DomainException('cascade_confirmation_required:'.json_encode($impact,JSON_UNESCAPED_SLASHES));
    if($decision==='enabled'){
        foreach($impact['requires'] as $dependency)$changes=array_merge($changes,tegh_entitlement_apply_cascade_locked($subject,$dependency,'enabled',$actor,'Dependency enabled atomically: '.$reason,$operationKey.'|requires|'.$dependency,true,$sourceReference,$validFrom,$validUntil,$trail));
        foreach($impact['conflicts'] as $conflict)$changes=array_merge($changes,tegh_entitlement_apply_cascade_locked($subject,$conflict,'disabled',$actor,'Conflicting feature disabled atomically: '.$reason,$operationKey.'|conflict|'.$conflict,true,$sourceReference,$validFrom,$validUntil,$trail));
    }else foreach($impact['dependants'] as $dependant)$changes=array_merge($changes,tegh_entitlement_apply_cascade_locked($subject,$dependant,'disabled',$actor,'Dependency cascade disabled atomically: '.$reason,$operationKey.'|dependant|'.$dependant,true,$sourceReference,$validFrom,$validUntil,$trail));
    $changes[]=tegh_entitlement_set_locked($subject,$featureKey,$decision,'platform_owner',$actor,$reason,$operationKey.'|'.$featureKey,$sourceReference,$validFrom,$validUntil);return $changes;
}

/** @return array<string,mixed> */
function tegh_entitlement_subject_for_target(string $scope,string $companyId,?string $targetUserId): array
{
    $companyStmt=db()->prepare('SELECT id,name,active FROM companies WHERE id=? LIMIT 1');$companyStmt->execute([$companyId]);$company=$companyStmt->fetch();if(!$company||!(bool)$company['active'])throw new DomainException('target_company_unavailable');
    if($scope==='company')return tegh_entitlement_subject('company',null,$companyId,null,(string)$company['name']);
    if($scope==='company_user'&&$targetUserId!==null){$member=db()->prepare("SELECT u.email FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? AND cm.user_id=? AND cm.status='active' LIMIT 1");$member->execute([$companyId,$targetUserId]);$email=$member->fetchColumn();if($email===false)throw new DomainException('target_company_user_unavailable');return tegh_entitlement_subject('company_user',null,$companyId,$targetUserId,(string)$company['name'].' / '.(string)$email);}
    throw new DomainException('target_scope_invalid');
}

function tegh_entitlement_notify_decision(string $companyId,string $requesterId,string $title,string $message,string $uniqueKey): void
{
    platform_notification_create($companyId,$requesterId,'entitlement',$title,$message,'modules-access','info',$uniqueKey,'View Modules & Access');
    $stmt=db()->prepare("SELECT user_id FROM company_members WHERE company_id=? AND role IN ('owner','admin') AND status='active'");$stmt->execute([$companyId]);
    foreach($stmt->fetchAll() as $row)if(!hash_equals($requesterId,(string)$row['user_id']))platform_notification_create($companyId,(string)$row['user_id'],'entitlement',$title,$message,'modules-access','info',$uniqueKey.'-'.(string)$row['user_id'],'View Modules & Access');
}

/** @return array<string,mixed>|null */
function tegh_platform_entitlement_receipt(string $companyId,string $actorUserId,string $operationKey,string $payloadHash): ?array
{
    $stmt=db()->prepare("SELECT payload_hash,status,result_json FROM ai_agent_action_authorizations
      WHERE company_id=? AND user_id=? AND action_type='platform:entitlement' AND operation_key=?
      ORDER BY created_at DESC,id DESC LIMIT 1");
    $stmt->execute([$companyId,$actorUserId,$operationKey]);$row=$stmt->fetch();if(!$row)return null;
    if(!hash_equals((string)$row['payload_hash'],$payloadHash))throw new DomainException('operation_key_conflict');
    $result=json_decode((string)($row['result_json']??''),true);
    if((string)$row['status']!=='completed'||!is_array($result))throw new DomainException('operation_unresolved');
    $result['idempotentReplay']=true;return $result;
}

/** @param array<string,mixed> $payload @param array<string,mixed> $result */
function tegh_platform_entitlement_store_receipt(string $companyId,string $actorUserId,string $operationKey,string $payloadHash,array $payload,array $result): void
{
    db()->prepare("INSERT INTO ai_agent_action_authorizations
      (id,company_id,user_id,action_type,payload_json,payload_hash,status,expires_at,authorized_at,completed_at,operation_key,result_status,result_json)
      VALUES (?,?,?,?,?,?,'completed',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 365 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,'completed',?)")
      ->execute([new_id('platformauth'),$companyId,$actorUserId,'platform:entitlement',tegh_json($payload),$payloadHash,$operationKey,tegh_json($result)]);
}

/** @return array<string,mixed> */
function tegh_platform_entitlement_expire_override(array $subject,string $featureKey,array $actor,string $reason,string $operationKey): array
{
    return tegh_entitlement_set_locked($subject,$featureKey,'disabled','platform_owner',$actor,
      'Returned to company inheritance: '.$reason,$operationKey,'platform-owner-inherit','2000-01-01 00:00:00','2000-01-02 00:00:00');
}

function tegh_platform_entitlement_apply(array $user): never
{
    require_method('POST');require_csrf();admin_require_platform_owner($user);tegh_entitlement_recent_auth_required();$input=request_json();$action=(string)($input['action']??'');
    if(!in_array($action,['approve_request','decline_request','override','suspend','restore','enable_company_for_user'],true))fail('Feature-control action is invalid.',422,'entitlement_action_invalid');
    $reason=mb_substr(trim((string)($input['reason']??'')),0,1000);if(mb_strlen($reason)<8)fail('Provide a clear reason of at least 8 characters.',422,'entitlement_reason_required');
    $cascade=!empty($input['cascadeConfirmed']);$operationKey=trim((string)($input['operationKey']??''));
    if(!preg_match('/^[A-Za-z0-9._:-]{8,120}$/',$operationKey))fail('Provide a stable operation key for this feature change.',422,'operation_key_invalid');
    if(strlen($operationKey)>64)$operationKey=hash('sha256',$operationKey);
    $effectiveDate=trim((string)($input['effectiveDate']??''));$validFrom=$effectiveDate!==''?safe_date($effectiveDate,'Effective date').' 00:00:00':gmdate('Y-m-d H:i:s');
    $untilDate=trim((string)($input['validUntil']??''));$validUntil=$untilDate!==''?safe_date($untilDate,'Valid until').' 23:59:59':null;
    if($validUntil!==null&&$validUntil<=$validFrom)fail('Valid until must be after the effective date.',422,'entitlement_validity_invalid');

    $requestId=in_array($action,['approve_request','decline_request'],true)?clean_text($input['requestId']??'','Access request',64):'';
    $companyId=in_array($action,['approve_request','decline_request'],true)?(function()use($requestId):string{$q=db()->prepare('SELECT company_id FROM entitlement_requests WHERE id=? LIMIT 1');$q->execute([$requestId]);$value=$q->fetchColumn();if($value===false)throw new DomainException('request_unavailable');return (string)$value;})():clean_text($input['companyId']??'','Company',64);
    $scope=(string)($input['scope']??'company');$targetUserId=trim((string)($input['targetUserId']??''));$featureKey=trim((string)($input['featureKey']??''));
    $decision=$action==='suspend'?'disabled':($action==='restore'?'enabled':(string)($input['decision']??''));
    $payload=['action'=>$action,'requestId'=>$requestId?:null,'companyId'=>$companyId,'scope'=>$scope,'targetUserId'=>$targetUserId?:null,'featureKey'=>$featureKey?:null,
      'decision'=>$decision?:null,'userDecision'=>(string)($input['userDecision']??''),'reason'=>$reason,'cascadeConfirmed'=>$cascade,
      'expectedCompanyRevision'=>$input['expectedCompanyRevision']??null,'expectedUserRevision'=>$input['expectedUserRevision']??null,
      'effectiveDate'=>$effectiveDate?:null,'validUntil'=>$untilDate?:null];
    $payloadHash=hash('sha256',tegh_json($payload));$lockName='tegh-platform-entitlement:'.substr(hash('sha256',$user['id'].'|'.$operationKey),0,32);
    $lockStmt=db()->prepare('SELECT GET_LOCK(?,5)');$lockStmt->execute([$lockName]);if((int)$lockStmt->fetchColumn()!==1)fail('Another feature change with this operation key is still running.',409,'entitlement_operation_busy',false);
    $response=null;$domainError=null;$fatalError=null;
    try{
        $receipt=tegh_platform_entitlement_receipt($companyId,(string)$user['id'],$operationKey,$payloadHash);if($receipt!==null){$response=$receipt;}
        else{
            db()->beginTransaction();$changes=[];$requesterId='';$targetId='';
            if(in_array($action,['approve_request','decline_request'],true)){
                $lock=db()->prepare('SELECT * FROM entitlement_requests WHERE id=? FOR UPDATE');$lock->execute([$requestId]);$request=$lock->fetch();if(!$request||(string)$request['company_id']!==$companyId)throw new DomainException('request_unavailable');
                if((string)$request['state']!=='pending')throw new DomainException('request_changed');
                if($request['endorsement_state']==='required')throw new DomainException('owner_endorsement_required');if($request['endorsement_state']==='rejected')throw new DomainException('owner_endorsement_rejected');
                $companySubject=tegh_entitlement_subject_existing('company',null,$companyId,null);if(!$companySubject)throw new DomainException('target_company_unavailable');
                $companyRevision=tegh_entitlement_current_revision((string)$companySubject['id'],true);$expectedCompanyRevision=filter_var($input['expectedCompanyRevision']??null,FILTER_VALIDATE_INT);
                if($expectedCompanyRevision===false||$expectedCompanyRevision===null||(int)$expectedCompanyRevision!==$companyRevision)throw new DomainException('stale_revision');
                if((string)$request['target_scope']==='company_user'){
                    $requestUserId=(string)($request['target_user_id']??'');$requestUserSubject=tegh_entitlement_subject_existing('company_user',null,$companyId,$requestUserId);$requestUserRevision=$requestUserSubject?tegh_entitlement_current_revision((string)$requestUserSubject['id'],true):0;
                    $expectedUserRevision=filter_var($input['expectedUserRevision']??null,FILTER_VALIDATE_INT);if($expectedUserRevision===false||$expectedUserRevision===null||(int)$expectedUserRevision!==$requestUserRevision)throw new DomainException('stale_revision');
                }
                $requesterId=(string)$request['requester_user_id'];$targetId=$requestId;
                if($action==='decline_request')db()->prepare("UPDATE entitlement_requests SET state='declined',pending_dedup_key=NULL,reviewed_by=?,decision_reason=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$user['id'],$reason,$requestId]);
                else{
                    $subject=tegh_entitlement_subject_for_target((string)$request['target_scope'],$companyId,$request['target_user_id']!==null?(string)$request['target_user_id']:null);
                    $items=db()->prepare('SELECT feature_key,requested_decision FROM entitlement_request_items WHERE request_id=? ORDER BY item_order,id');$items->execute([$requestId]);$maxRevision=0;
                    foreach($items->fetchAll() as $item){$applied=tegh_entitlement_apply_cascade_locked($subject,(string)$item['feature_key'],(string)$item['requested_decision'],$user,$reason,$operationKey.'|'.$requestId.'|'.(string)$item['feature_key'],$cascade,$requestId,$validFrom,$validUntil);$changes=array_merge($changes,$applied);foreach($applied as $change)$maxRevision=max($maxRevision,(int)($change['revision']??0));}
                    db()->prepare("UPDATE entitlement_requests SET state='fulfilled',pending_dedup_key=NULL,reviewed_by=?,decision_reason=?,fulfilled_revision=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$user['id'],$reason,$maxRevision,$requestId]);
                }
                tegh_entitlement_notify_decision($companyId,$requesterId,$action==='decline_request'?'Feature request declined':'Feature request approved',$action==='decline_request'?$reason:'The approved feature access is now reflected in Modules & Access.','entitlement-decision-'.$operationKey);
            }else{
                if(!in_array($scope,['company','company_user'],true))throw new DomainException('target_scope_invalid');
                $featureKey=clean_text($featureKey,'Feature key',120);if(in_array($featureKey,['core.accounting','audit.basic','banking.manual_reconciliation','ui.navigation','ui.accessibility','ui.accounting_mode'],true))throw new DomainException('policy_read_only');
                $companySubject=tegh_entitlement_subject_existing('company',null,$companyId,null);if(!$companySubject)throw new DomainException('target_company_unavailable');
                $companyRevision=tegh_entitlement_current_revision((string)$companySubject['id'],true);$expectedCompanyRevision=filter_var($input['expectedCompanyRevision']??null,FILTER_VALIDATE_INT);
                if($expectedCompanyRevision===false||$expectedCompanyRevision===null||(int)$expectedCompanyRevision!==$companyRevision)throw new DomainException('stale_revision');
                $targetId=(string)$companySubject['id'];
                if($action==='enable_company_for_user'){
                    $targetUserId=clean_text($targetUserId,'Company user',64);$userSubject=tegh_entitlement_subject_for_target('company_user',$companyId,$targetUserId);
                    $memberLock=db()->prepare("SELECT cm.status,u.active,u.deleted_at FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? AND cm.user_id=? FOR UPDATE");$memberLock->execute([$companyId,$targetUserId]);$member=$memberLock->fetch();
                    if(!$member||(string)$member['status']!=='active'||!(bool)$member['active']||$member['deleted_at']!==null)throw new DomainException('target_inactive');
                    $userRevision=tegh_entitlement_current_revision((string)$userSubject['id'],true);$expectedUserRevision=filter_var($input['expectedUserRevision']??null,FILTER_VALIDATE_INT);
                    if($expectedUserRevision===false||$expectedUserRevision===null||(int)$expectedUserRevision!==$userRevision)throw new DomainException('stale_revision');
                    $changes=tegh_entitlement_apply_cascade_locked($companySubject,$featureKey,'enabled',$user,$reason,$operationKey.'|company',$cascade,$action,$validFrom,$validUntil);
                    $userDecision=(string)($input['userDecision']??'inherit');
                    if($userDecision==='inherit')$changes[]=tegh_platform_entitlement_expire_override($userSubject,$featureKey,$user,$reason,$operationKey.'|user|inherit');
                    elseif($userDecision==='enabled')$changes=array_merge($changes,tegh_entitlement_apply_cascade_locked($userSubject,$featureKey,'enabled',$user,$reason,$operationKey.'|user',$cascade,$action,$validFrom,$validUntil));
                    else throw new DomainException('decision_invalid');
                    $targetId=(string)$userSubject['id'];tegh_entitlement_notify_decision($companyId,$targetUserId,'Company feature enabled','A Platform Owner enabled the company feature and applied the reviewed user assignment. Reason: '.$reason,'platform-feature-'.$operationKey);
                }else{
                    if(!in_array($decision,['enabled','disabled','inherit'],true))throw new DomainException('decision_invalid');
                    $subject=$scope==='company'?$companySubject:tegh_entitlement_subject_for_target('company_user',$companyId,clean_text($targetUserId,'Company user',64));
                    if($scope==='company_user'){
                        $memberLock=db()->prepare("SELECT cm.status,u.active,u.deleted_at FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? AND cm.user_id=? FOR UPDATE");$memberLock->execute([$companyId,$targetUserId]);$member=$memberLock->fetch();
                        if(!$member||(string)$member['status']!=='active'||!(bool)$member['active']||$member['deleted_at']!==null)throw new DomainException('target_inactive');
                        $userRevision=tegh_entitlement_current_revision((string)$subject['id'],true);$expectedUserRevision=filter_var($input['expectedUserRevision']??null,FILTER_VALIDATE_INT);
                        if($expectedUserRevision===false||$expectedUserRevision===null||(int)$expectedUserRevision!==$userRevision)throw new DomainException('stale_revision');
                        if($decision==='enabled'&&!tegh_subject_feature_enabled($companySubject,$featureKey))throw new DomainException('company_feature_not_enabled');
                    }elseif($decision==='inherit')throw new DomainException('decision_invalid');
                    $changeReason=$action==='suspend'?'Suspended: '.$reason:($action==='restore'?'Restored: '.$reason:$reason);
                    $changes=$decision==='inherit'?[tegh_platform_entitlement_expire_override($subject,$featureKey,$user,$reason,$operationKey.'|'.$featureKey)]:tegh_entitlement_apply_cascade_locked($subject,$featureKey,$decision,$user,$changeReason,$operationKey,$cascade,$action,$validFrom,$validUntil);
                    $targetId=(string)$subject['id'];
                    if($scope==='company_user')tegh_entitlement_notify_decision($companyId,$targetUserId,'Feature access updated','A Platform Owner updated your feature assignment. Reason: '.$reason,'platform-feature-'.$operationKey);
                    else{$owners=db()->prepare("SELECT user_id FROM company_members WHERE company_id=? AND role IN ('owner','admin') AND status='active'");$owners->execute([$companyId]);foreach($owners->fetchAll() as $owner)platform_notification_create($companyId,(string)$owner['user_id'],'entitlement','Company feature access updated','A Platform Owner updated company feature access. Reason: '.$reason,'modules-access','info','platform-feature-'.$operationKey.'-'.(string)$owner['user_id'],'View Modules & Access');}
                }
            }
            platform_audit_event($user,'platform.entitlement_'.$action,'entitlement',$targetId,['companyId'=>$companyId,'reason'=>$reason,'cascadeConfirmed'=>$cascade,'changes'=>$changes,'effectiveDate'=>$effectiveDate?:null,'validUntil'=>$untilDate?:null]);
            $response=['ok'=>true,'action'=>$action,'changes'=>$changes,'idempotentReplay'=>false,'request'=>$requestId!==''?(tegh_entitlement_request_rows('r.id=?',[$requestId])[0]??null):null];
            tegh_platform_entitlement_store_receipt($companyId,(string)$user['id'],$operationKey,$payloadHash,$payload,$response);db()->commit();
        }
    }catch(DomainException $error){if(db()->inTransaction())db()->rollBack();$domainError=$error;}catch(Throwable $error){if(db()->inTransaction())db()->rollBack();$fatalError=$error;}finally{$release=db()->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
    if($fatalError)throw $fatalError;
    if($domainError){$key=$domainError->getMessage();$message=match(true){str_starts_with($key,'cascade_confirmation_required')=>'This change affects required, dependent or conflicting features. Review the impact and explicitly confirm the atomic cascade.',$key==='owner_endorsement_required'=>'A Company Owner or Admin must endorse this company-wide request first.',$key==='owner_endorsement_rejected'=>'The Company Owner or Admin rejected this request.',$key==='request_changed'=>'The request changed after this preview. Refresh before deciding.',$key==='operation_key_conflict'=>'That operation key was already used for a different feature change.',$key==='operation_unresolved'=>'The earlier feature change is still unresolved. Refresh before retrying.',$key==='stale_revision'=>'Feature access changed after this preview. Refresh and review the current revision.',$key==='company_feature_not_enabled'=>'Enable this feature for the company first.',$key==='policy_read_only'=>'This always-included feature is read-only.',$key==='target_inactive'=>'Inactive users and memberships are read-only.',default=>'The requested feature-control target is unavailable.'};$code=match($key){'request_changed'=>'entitlement_request_changed','operation_key_conflict'=>'operation_key_conflict','operation_unresolved'=>'entitlement_operation_unresolved','stale_revision'=>'entitlement_revision_stale','company_feature_not_enabled'=>'company_feature_not_enabled','policy_read_only'=>'entitlement_policy_read_only','target_inactive'=>'entitlement_target_inactive',default=>'entitlement_dependency_confirmation_required'};fail($message,409,$code,false);}
    json_response($response??['ok'=>false]);
}

function tegh_company_user_entitlement_assign(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_permission($company,'users.manage');$input=request_json();$target=clean_text($input['targetUserId']??'','Company user',64);$feature=clean_text($input['featureKey']??'','Feature key',120);$decision=(string)($input['decision']??'enabled');if(!in_array($decision,['enabled','disabled'],true))fail('Feature assignment decision is invalid.',422,'entitlement_decision_invalid');
    $reason=mb_substr(trim((string)($input['reason']??'Company user assignment.')),0,1000);$operationKey=trim((string)($input['operationKey']??''));if(!preg_match('/^[A-Za-z0-9._:-]{8,120}$/',$operationKey))fail('Provide a stable operation key for this assignment.',422,'operation_key_invalid');
    try{
        db()->beginTransaction();
        $companySubject=tegh_entitlement_subject_existing('company',null,(string)$company['id'],null);if(!$companySubject)throw new DomainException('company_feature_not_enabled');
        $featureLock=db()->prepare('SELECT id FROM feature_entitlements WHERE subject_id=? AND feature_key=? FOR UPDATE');$featureLock->execute([$companySubject['id'],$feature]);$featureLock->fetchColumn();
        if(!tegh_subject_feature_enabled($companySubject,$feature))throw new DomainException('company_feature_not_enabled');
        $subject=tegh_entitlement_subject_for_target('company_user',(string)$company['id'],$target);
        $change=tegh_entitlement_set_locked($subject,$feature,$decision,'request',$user,$reason,$operationKey,'company-admin-assignment');
        if(empty($change['idempotentReplay']))audit_event($user,(string)$company['id'],'entitlement.company_user_assigned','entitlement_subject',(string)$subject['id'],['featureKey'=>$feature,'decision'=>$decision,'revision'=>$change['revision']]);
        db()->commit();
    }catch(DomainException $error){if(db()->inTransaction())db()->rollBack();if($error->getMessage()==='operation_key_conflict')fail('That operation key was already used for a different assignment.',409,'operation_key_conflict',false);if($error->getMessage()==='company_feature_not_enabled')fail('Company users can only be assigned features already enabled for the company.',409,'company_feature_not_enabled',false);fail('The selected company user is unavailable.',409,'entitlement_target_unavailable',false);}catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    json_response(['assignment'=>$change]);
}

/** @return array<string,mixed> */
function tegh_platform_commercial_status(): array
{
    // Schema 40 deliberately contains no prices, subscriptions, invoices or
    // payment records. Never infer commercial state from legacy account_plan,
    // module_mode, signup intent or an enabled entitlement.
    return [
        'accessStage'=>'private_beta','accessStageLabel'=>'Private Beta',
        'billing'=>'not_implemented','billingLabel'=>'Not implemented',
        'amountPaidCents'=>null,'currency'=>null,'paymentStatus'=>null,
        'paidAt'=>null,'paymentReference'=>null,'authoritativePaymentRecord'=>false,
    ];
}

/** @return array<int,array<string,mixed>> */
function tegh_platform_memberships_for_user(string $userId): array
{
    $stmt=db()->prepare("SELECT cm.company_id,cm.role,cm.status,cm.created_at,cm.updated_at,c.name company_name,c.active company_active
      FROM company_members cm JOIN companies c ON c.id=cm.company_id WHERE cm.user_id=? ORDER BY c.name,c.id");
    $stmt->execute([$userId]);
    return array_map(static fn(array $row):array=>[
        'companyId'=>(string)$row['company_id'],'companyName'=>(string)$row['company_name'],
        'companyActive'=>(bool)$row['company_active'],'role'=>(string)$row['role'],'status'=>(string)$row['status'],
        'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at'],
    ],$stmt->fetchAll());
}

/** @return array<int,array<string,mixed>> */
function tegh_platform_signup_intents_for_user(string $userId): array
{
    $stmt=db()->prepare("SELECT i.feature_key,i.state,i.company_id,i.attached_request_id,i.request_reference,i.created_at,i.attached_at,
      f.display_name,c.name company_name,r.state request_state,r.decision_reason
      FROM signup_feature_intents i JOIN feature_catalog f ON f.feature_key=i.feature_key
      LEFT JOIN companies c ON c.id=i.company_id LEFT JOIN entitlement_requests r ON r.id=i.attached_request_id
      WHERE i.user_id=? ORDER BY i.created_at,i.id");$stmt->execute([$userId]);
    return array_map(static fn(array $row):array=>[
        'featureKey'=>(string)$row['feature_key'],'featureName'=>(string)$row['display_name'],'intentState'=>(string)$row['state'],
        'companyId'=>$row['company_id']!==null?(string)$row['company_id']:null,'companyName'=>$row['company_name']!==null?(string)$row['company_name']:null,
        'requestId'=>$row['attached_request_id']!==null?(string)$row['attached_request_id']:null,'requestState'=>$row['request_state']!==null?(string)$row['request_state']:null,
        'decisionReason'=>$row['decision_reason']!==null?(string)$row['decision_reason']:null,'requestReference'=>(string)$row['request_reference'],
        'createdAt'=>(string)$row['created_at'],'attachedAt'=>$row['attached_at']!==null?(string)$row['attached_at']:null,
    ],$stmt->fetchAll());
}

function tegh_platform_registered_users(): never
{
    require_method('GET');
    $query=mb_substr(trim((string)($_GET['q']??'')),0,120);$status=(string)($_GET['status']??'all');$signupSource=(string)($_GET['signupSource']??'all');$companyId=trim((string)($_GET['companyId']??''));
    if(!in_array($status,['all','active','inactive','deleted'],true))fail('User status filter is invalid.',422,'platform_user_filter_invalid');
    if(!in_array($signupSource,['all','initial_setup','public_signup','invitation'],true))fail('Signup source filter is invalid.',422,'platform_user_filter_invalid');
    if($companyId!==''&&!preg_match('/^[A-Za-z0-9._:-]{1,64}$/',$companyId))fail('Company filter is invalid.',422,'platform_user_filter_invalid');
    $page=max(1,min(100000,(int)($_GET['page']??1)));$limit=max(10,min(100,(int)($_GET['limit']??25)));$offset=($page-1)*$limit;
    $where=['1=1'];$params=[];
    if($query!==''){$where[]='(u.display_name LIKE ? OR u.email LIKE ?)';$params[]='%'.$query.'%';$params[]='%'.$query.'%';}
    if($status==='active')$where[]='u.active=1 AND u.deleted_at IS NULL';
    elseif($status==='inactive')$where[]='u.active=0 AND u.deleted_at IS NULL';
    elseif($status==='deleted')$where[]='u.deleted_at IS NOT NULL';
    if($signupSource!=='all'){$where[]='u.signup_source=?';$params[]=$signupSource;}
    if($companyId!==''){$where[]='EXISTS (SELECT 1 FROM company_members cmf WHERE cmf.user_id=u.id AND cmf.company_id=?)';$params[]=$companyId;}
    $whereSql=implode(' AND ',$where);$count=db()->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");$count->execute($params);$total=(int)$count->fetchColumn();
    $stmt=db()->prepare("SELECT u.id,u.email,u.display_name,u.active,u.signup_source,u.last_login_at,u.deleted_at,u.created_at
      FROM users u WHERE $whereSql ORDER BY u.created_at DESC,u.id DESC LIMIT $limit OFFSET $offset");$stmt->execute($params);$users=[];
    foreach($stmt->fetchAll() as $row){$memberships=tegh_platform_memberships_for_user((string)$row['id']);$roles=[];foreach($memberships as $membership)$roles[]=$membership['companyName'].' · '.$membership['role'].' · '.$membership['status'];$intents=tegh_platform_signup_intents_for_user((string)$row['id']);
        $users[]=['id'=>(string)$row['id'],'name'=>(string)$row['display_name'],'email'=>(string)$row['email'],
          'state'=>$row['deleted_at']!==null?'deleted':((bool)$row['active']?'active':'inactive'),'signupSource'=>(string)$row['signup_source'],
          'signupDate'=>(string)$row['created_at'],'lastLoginAt'=>$row['last_login_at']!==null?(string)$row['last_login_at']:null,
          'membershipCount'=>count($memberships),'membershipRoles'=>$roles,'signupIntents'=>$intents,'commercial'=>tegh_platform_commercial_status()];
    }
    json_response(['users'=>$users,'pagination'=>['page'=>$page,'limit'=>$limit,'total'=>$total,'pages'=>(int)ceil($total/$limit)],
      'commercialPolicy'=>tegh_platform_commercial_status(),'bookAccessGranted'=>false,'metadataOnly'=>true]);
}

function tegh_platform_feature_group(string $featureKey,string $kind): string
{
    if(in_array($featureKey,['core.accounting','audit.basic','banking.manual_reconciliation','ui.navigation','ui.accessibility','ui.accounting_mode'],true))return 'Included foundations';
    if(str_starts_with($featureKey,'tegh.')||str_contains($featureKey,'command_centre'))return 'Tegh & Automation';
    if(str_starts_with($featureKey,'banking.'))return 'Banking and reconciliation';
    return $kind==='ui_option'?'Included foundations':'Modules';
}

function tegh_platform_effective_reason_label(string $reason): string
{
    return match($reason){
      'company_projection'=>'Enabled for the company','company_deny_precedence'=>'Disabled for the company',
      'company_suspended'=>'Company access is suspended','company_user_projection'=>'Enabled for this user',
      'company_user_narrowing'=>'Disabled for this user','company_user_suspended'=>'This user assignment is suspended',
      'catalog_default'=>'Included by product policy','dependency_not_satisfied'=>'A required feature is unavailable',
      'platform_feature_suspended'=>'Suspended platform-wide','platform_feature_retired'=>'Retired platform-wide',
      default=>'Resolved by the current feature policy',
    };
}

/** @return array<string,mixed> */
function tegh_platform_feature_detail_for_membership(array $targetUser,array $membership,array $feature): array
{
    $company=['id'=>$membership['companyId'],'name'=>$membership['companyName']];$key=(string)$feature['featureKey'];
    $companySubject=tegh_entitlement_subject_existing('company',null,(string)$membership['companyId'],null);
    $userSubject=tegh_entitlement_subject_existing('company_user',null,(string)$membership['companyId'],(string)$targetUser['id']);
    $companyProjection=tegh_entitlement_projection($companySubject,$key);$userProjection=tegh_entitlement_projection($userSubject,$key);$companyStored=null;$userStored=null;
    if($companySubject){$stored=db()->prepare('SELECT * FROM feature_entitlements WHERE subject_id=? AND feature_key=? LIMIT 1');$stored->execute([$companySubject['id'],$key]);$companyStored=$stored->fetch()?:null;}
    if($userSubject){$stored=db()->prepare('SELECT * FROM feature_entitlements WHERE subject_id=? AND feature_key=? LIMIT 1');$stored->execute([$userSubject['id'],$key]);$userStored=$stored->fetch()?:null;}
    $effective=tegh_resolve_feature($targetUser,$company,$key);$protected=in_array($key,['core.accounting','audit.basic','banking.manual_reconciliation','ui.navigation','ui.accessibility','ui.accounting_mode'],true);
    $parentDecision=$companyProjection?(string)$companyProjection['decision']:(string)$feature['defaultDecision'];
    $now=gmdate('Y-m-d H:i:s');$companyExpired=$companyStored&&$companyStored['valid_until']!==null&&(string)$companyStored['valid_until']<=$now;
    $companyAvailability=$protected?'Included':((string)$feature['operationalState']==='suspended'?'Suspended':((string)$feature['operationalState']==='retired'?'Retired':($companyExpired?'Expired':($parentDecision==='enabled'?'Enabled':'Disabled'))));
    $userAssignment=$userProjection?((string)$userProjection['decision']==='enabled'?'Enabled for this user':'Disabled for this user'):'Inherit company';
    $effectiveOutcome=!empty($effective['allowed'])?'Enabled':'Disabled';$last=$userStored?:$companyStored;
    return array_merge($feature,[
        'effective'=>$effective,'checked'=>(bool)$effective['allowed'],'readOnly'=>$protected||$membership['status']!=='active'||empty($targetUser['active']),
        'readOnlyReason'=>$protected?'Always included by product policy':($membership['status']!=='active'?'Inactive membership':(empty($targetUser['active'])?'Inactive user':null)),
        'group'=>tegh_platform_feature_group($key,(string)$feature['kind']),'companyAvailability'=>$companyAvailability,'userAssignment'=>$userAssignment,
        'effectiveOutcome'=>$effectiveOutcome,'effectiveReasonLabel'=>tegh_platform_effective_reason_label((string)($effective['reason']??'')),
        'parentCompanyDecision'=>$parentDecision,'companyProjection'=>$companyProjection?:null,'userProjection'=>$userProjection?:null,
        'companyRevision'=>$companySubject?tegh_entitlement_current_revision((string)$companySubject['id']):0,
        'userRevision'=>$userSubject?tegh_entitlement_current_revision((string)$userSubject['id']):0,
        'canPersonalEnable'=>$parentDecision==='enabled'&&(string)$feature['operationalState']==='active','canEnableCompany'=>!$protected&&(string)$feature['operationalState']==='active'&&!empty($membership['companyActive']),
        'lastDecision'=>$last?['scope'=>$userStored?'company_user':'company','decision'=>(string)$last['decision'],'source'=>(string)$last['source'],'reason'=>(string)$last['reason'],'revision'=>(int)$last['revision'],'validFrom'=>$last['valid_from'],'validUntil'=>$last['valid_until']]:null,
    ]);
}

function tegh_platform_registered_user_detail(): never
{
    require_method('GET');$userId=clean_text($_GET['userId']??'','User',64);
    $stmt=db()->prepare('SELECT id,email,display_name,active,signup_source,last_login_at,deleted_at,created_at,updated_at FROM users WHERE id=? LIMIT 1');$stmt->execute([$userId]);$target=$stmt->fetch();
    if(!$target)fail('The registered user is unavailable.',404,'platform_user_unavailable',false);
    $target['active']=(bool)$target['active'];$memberships=tegh_platform_memberships_for_user($userId);$catalog=tegh_feature_catalog_rows();$membershipDetails=[];
    foreach($memberships as $membership){$features=[];foreach($catalog as $feature)if(in_array((string)$feature['scope'],['company','company_user'],true))$features[]=tegh_platform_feature_detail_for_membership($target,$membership,$feature);$membershipDetails[]=array_merge($membership,['features'=>$features]);}
    json_response(['user'=>['id'=>(string)$target['id'],'name'=>(string)$target['display_name'],'email'=>(string)$target['email'],
      'state'=>$target['deleted_at']!==null?'deleted':($target['active']?'active':'inactive'),'active'=>$target['active'],'signupSource'=>(string)$target['signup_source'],
      'signupDate'=>(string)$target['created_at'],'lastLoginAt'=>$target['last_login_at']!==null?(string)$target['last_login_at']:null,
      'updatedAt'=>(string)$target['updated_at'],'commercial'=>tegh_platform_commercial_status(),'signupIntents'=>tegh_platform_signup_intents_for_user($userId),
      'memberships'=>$membershipDetails],'metadataOnly'=>true,'bookAccessGranted'=>false]);
}

function tegh_platform_entitlement_impact_preview(array $actor): never
{
    require_method('POST');require_csrf();$input=request_json();$requestId=trim((string)($input['requestId']??''));
    if($requestId!==''){
        $requestId=clean_text($requestId,'Access request',64);$reviewDecision=(string)($input['requestDecision']??'approve');if(!in_array($reviewDecision,['approve','decline'],true))fail('Request decision is invalid.',422,'entitlement_decision_invalid');
        $request=tegh_entitlement_request_rows('r.id=?',[$requestId])[0]??null;if(!$request||(string)$request['state']!=='pending'||in_array((string)$request['endorsementState'],['required','rejected'],true))fail('The access request is no longer available for review.',409,'request_changed',false);
        $companyId=(string)$request['companyId'];$companySubject=tegh_entitlement_subject_existing('company',null,$companyId,null);if(!$companySubject)fail('The company feature subject is unavailable.',409,'entitlement_target_unavailable',false);
        $scope=(string)$request['targetScope'];$targetUserId=(string)($request['targetUserId']??'');$subject=$companySubject;$userRevision=0;
        if($scope==='company_user'){
            $member=db()->prepare("SELECT cm.status,u.active,u.deleted_at FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? AND cm.user_id=? LIMIT 1");$member->execute([$companyId,$targetUserId]);$memberRow=$member->fetch();if(!$memberRow||(string)$memberRow['status']!=='active'||!(bool)$memberRow['active']||$memberRow['deleted_at']!==null)fail('The request target is no longer active.',409,'entitlement_target_inactive',false);
            $userSubject=tegh_entitlement_subject_existing('company_user',null,$companyId,$targetUserId);if($userSubject){$subject=$userSubject;$userRevision=tegh_entitlement_current_revision((string)$userSubject['id']);}
        }
        $requires=[];$dependants=[];$conflicts=[];$changes=[];
        foreach($request['items'] as $item){
            $featureKey=(string)$item['featureKey'];$requested=(string)$item['decision'];$current=tegh_subject_feature_enabled($subject,$featureKey)?'enabled':'disabled';
            $impact=$reviewDecision==='approve'?tegh_entitlement_dependency_impact($subject,$featureKey,$requested):['requires'=>[],'dependants'=>[],'conflicts'=>[]];
            $requires=array_merge($requires,$impact['requires']);$dependants=array_merge($dependants,$impact['dependants']);$conflicts=array_merge($conflicts,$impact['conflicts']);
            $changes[]=['featureKey'=>$featureKey,'featureName'=>(string)$item['name'],'oldDecision'=>$current,'newDecision'=>$reviewDecision==='approve'?$requested:$current];
        }
        $requires=array_values(array_unique($requires));$dependants=array_values(array_unique($dependants));$conflicts=array_values(array_unique($conflicts));
        $affected=db()->prepare("SELECT COUNT(*) FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? AND cm.status='active' AND u.active=1 AND u.deleted_at IS NULL");$affected->execute([$companyId]);
        json_response(['impact'=>['requestId'=>$requestId,'requestDecision'=>$reviewDecision,'companyId'=>$companyId,'targetUserId'=>$targetUserId,'featureKey'=>implode(', ',array_column($changes,'featureKey')),'featureName'=>count($changes).' requested feature change'.(count($changes)===1?'':'s'),'scope'=>$scope,'composite'=>count($changes)>1,
          'oldEffectiveDecision'=>'pending request','newEffectiveDecision'=>$reviewDecision==='approve'?'approved access':'declined request','oldCompanyDecision'=>'unchanged until confirmation','newCompanyDecision'=>$reviewDecision==='approve'?'per approved request':'unchanged','oldUserAssignment'=>'unchanged until confirmation','newUserAssignment'=>$reviewDecision==='approve'?'per approved request':'unchanged',
          'companyRevision'=>tegh_entitlement_current_revision((string)$companySubject['id']),'userRevision'=>$userRevision,'parentCompanyDecision'=>'request review','requires'=>$requires,'dependants'=>$dependants,'conflicts'=>$conflicts,'cascadeRequired'=>$reviewDecision==='approve'&&($requires||$dependants||$conflicts),'affectedActiveUsers'=>$scope==='company'?(int)$affected->fetchColumn():1,
          'changes'=>$changes,'roleUnchanged'=>true,'membershipUnchanged'=>true,'bookAccessGranted'=>false],'readOnly'=>true]);
    }
    $companyId=clean_text($input['companyId']??'','Company',64);$featureKey=clean_text($input['featureKey']??'','Feature key',120);$scope=(string)($input['scope']??'company_user');$decision=(string)($input['decision']??'');$userDecision=(string)($input['userDecision']??'');
    if(!in_array($scope,['company','company_user'],true)||!in_array($decision,['enabled','disabled','inherit'],true)||($scope==='company'&&$decision==='inherit'))fail('Feature decision is invalid.',422,'entitlement_decision_invalid');
    if($userDecision!==''&&!in_array($userDecision,['inherit','enabled'],true))fail('User assignment is invalid.',422,'entitlement_decision_invalid');
    $catalogRows=array_values(array_filter(tegh_feature_catalog_rows(),static fn(array $feature):bool=>(string)$feature['featureKey']===$featureKey));$feature=$catalogRows[0]??null;if(!$feature)fail('The feature is unavailable.',404,'entitlement_target_unavailable',false);
    $protected=in_array($featureKey,['core.accounting','audit.basic','banking.manual_reconciliation','ui.navigation','ui.accessibility','ui.accounting_mode'],true);if($protected)fail('This always-included feature is read-only.',409,'entitlement_policy_read_only',false);
    $companySubject=tegh_entitlement_subject_existing('company',null,$companyId,null);if(!$companySubject)fail('The company feature subject is unavailable.',409,'entitlement_target_unavailable',false);
    $targetUserId=trim((string)($input['targetUserId']??''));$detail=null;$userSubject=null;
    if($scope==='company_user'||$userDecision!==''){
        $targetUserId=clean_text($targetUserId,'Company user',64);
        $member=db()->prepare("SELECT u.id,u.active,u.deleted_at,c.id company_id,c.name company_name,c.active company_active,cm.role,cm.status
          FROM company_members cm JOIN users u ON u.id=cm.user_id JOIN companies c ON c.id=cm.company_id WHERE cm.company_id=? AND cm.user_id=? LIMIT 1");$member->execute([$companyId,$targetUserId]);$row=$member->fetch();
        if(!$row)fail('The feature-control target is unavailable.',404,'entitlement_target_unavailable',false);
        $target=['id'=>(string)$row['id'],'active'=>(bool)$row['active']];$membership=['companyId'=>$companyId,'companyName'=>(string)$row['company_name'],'companyActive'=>(bool)$row['company_active'],'role'=>(string)$row['role'],'status'=>(string)$row['status']];$detail=tegh_platform_feature_detail_for_membership($target,$membership,$feature);
        if($membership['status']!=='active'||!$target['active']||$row['deleted_at']!==null)fail('Inactive users or memberships are read-only.',409,'entitlement_target_inactive',false);
        if($scope==='company_user'&&$decision==='enabled'&&!$detail['canPersonalEnable'])fail('Enable this feature for the company first.',409,'company_feature_not_enabled',false);
        $userSubject=tegh_entitlement_subject_existing('company_user',null,$companyId,$targetUserId);
    }else{
        $companyCheck=db()->prepare('SELECT active FROM companies WHERE id=? LIMIT 1');$companyCheck->execute([$companyId]);$active=$companyCheck->fetchColumn();if($active===false||!(bool)$active)fail('The company feature target is unavailable.',404,'entitlement_target_unavailable',false);
        $companyEnabled=tegh_subject_feature_enabled($companySubject,$featureKey);$detail=['effective'=>['allowed'=>$companyEnabled],'parentCompanyDecision'=>$companyEnabled?'enabled':'disabled','userAssignment'=>'Not applicable','companyRevision'=>tegh_entitlement_current_revision((string)$companySubject['id']),'userRevision'=>0];
    }
    // Preflight/impact preview is strictly read-only. If the company-user
    // subject has never needed an override, evaluate dependency impact against
    // the authoritative company subject rather than creating metadata here.
    $subject=$scope==='company'||!$userSubject?$companySubject:$userSubject;
    $impactDecision=$decision==='inherit'?(string)$detail['parentCompanyDecision']:$decision;$impact=tegh_entitlement_dependency_impact($subject,$featureKey,$impactDecision);
    $newEffective=$scope==='company_user'?($decision==='inherit'?(string)$detail['parentCompanyDecision']:$decision):($decision==='disabled'?'disabled':($userDecision==='enabled'||$detail['userAssignment']!=='Disabled for this user'?'enabled':'disabled'));
    $affected=db()->prepare("SELECT COUNT(*) FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? AND cm.status='active' AND u.active=1 AND u.deleted_at IS NULL");$affected->execute([$companyId]);
    json_response(['impact'=>['companyId'=>$companyId,'targetUserId'=>$targetUserId,'featureKey'=>$featureKey,
      'featureName'=>(string)$feature['name'],'scope'=>$scope,'composite'=>$scope==='company'&&$userDecision!=='','userDecision'=>$userDecision?:null,
      'oldEffectiveDecision'=>$detail['effective']['allowed']?'enabled':'disabled','newEffectiveDecision'=>$newEffective,
      'oldCompanyDecision'=>$detail['parentCompanyDecision'],'newCompanyDecision'=>$scope==='company'?$decision:$detail['parentCompanyDecision'],
      'oldUserAssignment'=>$detail['userAssignment'],'newUserAssignment'=>$scope==='company'?($userDecision===''?'Not applicable':($userDecision==='enabled'?'Enabled for this user':'Inherit company')):($decision==='inherit'?'Inherit company':($decision==='enabled'?'Enabled for this user':'Disabled for this user')),
      'companyRevision'=>(int)$detail['companyRevision'],'userRevision'=>(int)$detail['userRevision'],'parentCompanyDecision'=>$detail['parentCompanyDecision'],
      'requires'=>$impact['requires'],'dependants'=>$impact['dependants'],'conflicts'=>$impact['conflicts'],'cascadeRequired'=>($impact['requires']||$impact['dependants']||$impact['conflicts']),
      'affectedActiveUsers'=>$scope==='company'?(int)$affected->fetchColumn():1,
      'roleUnchanged'=>true,'membershipUnchanged'=>true,'bookAccessGranted'=>false],'readOnly'=>true]);
}

function handle_entitlements(string $action): never
{
    tegh_entitlement_schema_required();$user=require_user();
    if(str_starts_with($action,'platform/')){
        admin_require_platform_owner($user);
        if($action==='platform/enforcement-status'){require_method('GET');json_response(tegh_platform_entitlement_enforcement_status());}
        if($action==='platform/enforce')tegh_platform_entitlement_enforce($user);
        if($action==='platform/inbox'){require_method('GET');json_response(['requests'=>tegh_entitlement_request_rows("r.state='pending' AND r.endorsement_state IN ('not_required','endorsed')"),'waitingCompanyRequests'=>tegh_entitlement_request_rows("r.state='pending' AND r.endorsement_state='required'")]);}
        if($action==='platform/users')tegh_platform_registered_users();
        if($action==='platform/user-detail')tegh_platform_registered_user_detail();
        if($action==='platform/impact-preview')tegh_platform_entitlement_impact_preview($user);
        if($action==='platform/matrix'){
            require_method('GET');$search=mb_substr(trim((string)($_GET['q']??'')),0,120);$sql="SELECT c.id,c.name,s.id subject_id,er.revision FROM companies c LEFT JOIN entitlement_subjects s ON s.company_id=c.id AND s.subject_type='company' LEFT JOIN entitlement_revisions er ON er.subject_id=s.id WHERE c.active=1";$params=[];if($search!==''){$sql.=' AND c.name LIKE ?';$params[]='%'.$search.'%';}$sql.=' ORDER BY c.name LIMIT 250';$stmt=db()->prepare($sql);$stmt->execute($params);$companies=[];foreach($stmt->fetchAll() as $row){$entitlements=[];if($row['subject_id']!==null){$e=db()->prepare('SELECT feature_key,decision,source,valid_from,valid_until,revision,reason FROM feature_entitlements WHERE subject_id=? ORDER BY feature_key');$e->execute([$row['subject_id']]);$entitlements=$e->fetchAll();}$companies[]=['id'=>(string)$row['id'],'name'=>(string)$row['name'],'revision'=>(int)($row['revision']??0),'entitlements'=>$entitlements];}json_response(['companies'=>$companies,'catalog'=>tegh_feature_catalog_rows(),'accessPolicy'=>'feature_metadata_only']);
        }
        if($action==='platform/history'){
            require_method('GET');$companyId=clean_text($_GET['companyId']??'','Company',64);$feature=trim((string)($_GET['featureKey']??''));$params=[$companyId];$featureSql='';if($feature!==''){$feature=clean_text($feature,'Feature key',120);$featureSql=' AND h.feature_key=?';$params[]=$feature;}
            $stmt=db()->prepare("SELECT h.id,h.feature_key,h.entitlement_revision,h.before_json,h.after_json,h.source,h.request_reference,h.source_reference,h.reason,h.created_at,u.display_name actor_name,u.email actor_email
              FROM feature_entitlement_history h JOIN entitlement_subjects s ON s.id=h.subject_id LEFT JOIN users u ON u.id=h.actor_user_id
              WHERE s.company_id=?$featureSql ORDER BY h.created_at DESC,h.id DESC LIMIT 500");$stmt->execute($params);$history=[];foreach($stmt->fetchAll() as $row){$history[]=['id'=>(string)$row['id'],'featureKey'=>(string)$row['feature_key'],'revision'=>(int)$row['entitlement_revision'],'before'=>json_decode((string)$row['before_json'],true)?:[],'after'=>json_decode((string)$row['after_json'],true)?:[],'source'=>(string)$row['source'],'requestReference'=>(string)$row['request_reference'],'sourceReference'=>$row['source_reference'],'reason'=>(string)$row['reason'],'actor'=>(string)($row['actor_name']?:$row['actor_email']?:'System'),'createdAt'=>(string)$row['created_at']];}json_response(['history'=>$history,'companyId'=>$companyId]);
        }
        if($action==='platform/company-users'){
            require_method('GET');$companyId=clean_text($_GET['companyId']??'','Company',64);$stmt=db()->prepare("SELECT cm.user_id,cm.role,cm.status,u.display_name,u.email FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? ORDER BY cm.status='active' DESC,u.display_name,u.email");$stmt->execute([$companyId]);json_response(['companyId'=>$companyId,'users'=>array_map(static fn(array $row):array=>['userId'=>(string)$row['user_id'],'name'=>(string)$row['display_name'],'email'=>(string)$row['email'],'role'=>(string)$row['role'],'status'=>(string)$row['status']],$stmt->fetchAll()),'bookAccessGranted'=>false]);
        }
        if($action==='platform/apply')tegh_platform_entitlement_apply($user);
        fail('Feature-control route not found.',404,'route_not_found');
    }
    $company=require_company($user);
    if($action===''||$action==='my'){require_method('GET');json_response(tegh_entitlement_my_payload($user,$company));}
    if($action==='request')tegh_entitlement_request_create($user,$company);
    if($action==='cancel')tegh_entitlement_request_cancel($user,$company);
    if($action==='endorse')tegh_entitlement_request_endorse($user,$company);
    if($action==='assign')tegh_company_user_entitlement_assign($user,$company);
    if($action==='assignments'){
        require_method('GET');require_company_permission($company,'users.manage');$members=db()->prepare("SELECT cm.user_id,cm.role,u.display_name,u.email FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? AND cm.status='active' AND u.active=1 ORDER BY u.display_name,u.email");$members->execute([$company['id']]);$users=[];
        foreach($members->fetchAll() as $member){$subject=tegh_entitlement_subject_existing('company_user',null,(string)$company['id'],(string)$member['user_id']);$assignments=[];if($subject){$q=db()->prepare('SELECT feature_key,decision,revision,reason,valid_from,valid_until FROM feature_entitlements WHERE subject_id=? ORDER BY feature_key');$q->execute([$subject['id']]);$assignments=$q->fetchAll();}$users[]=['userId'=>(string)$member['user_id'],'name'=>(string)$member['display_name'],'email'=>(string)$member['email'],'role'=>(string)$member['role'],'assignments'=>$assignments];}
        json_response(['users'=>$users,'companyId'=>(string)$company['id']]);
    }
    if($action==='company-requests'){require_method('GET');require_company_permission($company,'users.manage');json_response(['requests'=>tegh_entitlement_request_rows("r.company_id=? AND r.state='pending'",[(string)$company['id']])]);}
    fail('Entitlement route not found.',404,'route_not_found');
}

function handle_public_feature_catalog(): never
{
    require_method('GET');$features=[];
    if(schema_table_exists('feature_catalog')){
        $stmt=db()->query("SELECT feature_key,display_name,description,kind,billable,metered,display_order FROM feature_catalog WHERE operational_state='active' AND permitted_scope='company' AND default_decision='disabled' ORDER BY display_order,feature_key");
        foreach($stmt->fetchAll() as $row)$features[]=['featureKey'=>(string)$row['feature_key'],'name'=>(string)$row['display_name'],'description'=>(string)$row['description'],'kind'=>(string)$row['kind'],'billable'=>(bool)$row['billable'],'metered'=>(bool)$row['metered']];
    }
    json_response(['baseAccountingIncluded'=>true,'advancedSelectionsBecomeRequests'=>true,'features'=>$features]);
}
