SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS app_meta (
  meta_key VARCHAR(100) PRIMARY KEY,
  meta_value TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS database_migration_events (
  event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  migration_id VARCHAR(160) NOT NULL,
  migration_checksum CHAR(64) NOT NULL,
  state ENUM('started','completed','failed') NOT NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  request_reference VARCHAR(80) NOT NULL,
  build_number INT NOT NULL,
  details_json LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY database_migration_id_state_idx (migration_id,state,event_id),
  KEY database_migration_request_idx (request_reference,event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id VARCHAR(64) PRIMARY KEY,
  email VARCHAR(254) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  platform_role ENUM('platform_owner','member') NOT NULL DEFAULT 'member',
  account_plan ENUM('free_preview') NOT NULL DEFAULT 'free_preview',
  signup_source ENUM('initial_setup','public_signup','invitation') NOT NULL DEFAULT 'public_signup',
  terms_accepted_at DATETIME NULL,
  last_login_at DATETIME NULL,
  deleted_at DATETIME NULL,
  deleted_by VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY users_email_uq (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  successful TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY login_attempts_lookup_idx (email_hash, ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(64) PRIMARY KEY,
  user_id VARCHAR(64) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  csrf_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  user_agent_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY sessions_token_uq (token_hash),
  KEY sessions_user_expiry_idx (user_id, expires_at),
  CONSTRAINT sessions_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_requests (
  id VARCHAR(64) PRIMARY KEY,
  user_id VARCHAR(64) NULL,
  email_hash CHAR(64) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  requested_ip_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY password_reset_token_uq (token_hash),
  KEY password_reset_user_created_idx (user_id, created_at),
  KEY password_reset_email_created_idx (email_hash, created_at),
  KEY password_reset_expiry_idx (expires_at, used_at),
  CONSTRAINT password_reset_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies (
  id VARCHAR(64) PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  legal_name VARCHAR(200) NOT NULL,
  business_type ENUM('sole_proprietor','corporation','partnership','non_profit') NOT NULL DEFAULT 'corporation',
  province CHAR(2) NOT NULL DEFAULT 'ON',
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  accounting_basis ENUM('accrual','cash') NOT NULL DEFAULT 'accrual',
  module_mode ENUM('accounting','payroll','both') NOT NULL DEFAULT 'both',
  payroll_posting_mode ENUM('automatic','draft','none') NOT NULL DEFAULT 'draft',
  tax_reporting_profile ENUM('gifi','t2125','none') NOT NULL DEFAULT 'none',
  reporting_framework ENUM('not_set','aspe','ifrs','other') NOT NULL DEFAULT 'not_set',
  fiscal_year_end CHAR(5) NOT NULL DEFAULT '12-31',
  fiscal_year_end_date DATE NULL,
  books_start_date DATE NULL,
  tax_registered TINYINT(1) NOT NULL DEFAULT 0,
  tax_number VARCHAR(40) NULL,
  tax_rate_bps INT NOT NULL DEFAULT 1300,
  pst_registered TINYINT(1) NOT NULL DEFAULT 0,
  pst_rate_bps INT NOT NULL DEFAULT 0,
  pst_recoverable TINYINT(1) NOT NULL DEFAULT 0,
  test_mode TINYINT(1) NOT NULL DEFAULT 0,
  test_expires_at DATETIME NULL,
  test_created_by VARCHAR(64) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY companies_name_idx (name),
  CONSTRAINT companies_tax_rate_ck CHECK (tax_rate_bps >= 0 AND tax_rate_bps <= 2500),
  CONSTRAINT companies_pst_rate_ck CHECK (pst_rate_bps >= 0 AND pst_rate_bps <= 2500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_deletion_log (
  id VARCHAR(64) PRIMARY KEY,
  deleted_company_id VARCHAR(64) NOT NULL,
  company_name VARCHAR(160) NOT NULL,
  deleted_by VARCHAR(64) NOT NULL,
  backup_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  record_summary_json LONGTEXT NOT NULL,
  deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY company_deletion_log_user_idx (deleted_by, deleted_at),
  CONSTRAINT company_deletion_log_user_fk FOREIGN KEY (deleted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_audit_log (
  id VARCHAR(64) PRIMARY KEY,
  actor_user_id VARCHAR(64) NOT NULL,
  actor_email VARCHAR(254) NOT NULL,
  action VARCHAR(120) NOT NULL,
  target_type VARCHAR(80) NOT NULL,
  target_id VARCHAR(64) NOT NULL,
  metadata_json LONGTEXT NOT NULL,
  request_id VARCHAR(80) NOT NULL DEFAULT '',
  ip_hash CHAR(64) NOT NULL DEFAULT '',
  user_agent_hash CHAR(64) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY platform_audit_actor_created_idx (actor_user_id, created_at),
  KEY platform_audit_target_created_idx (target_type, target_id, created_at),
  CONSTRAINT platform_audit_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_incident_log (
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
  KEY platform_incident_severity_idx (severity, occurred_at),
  KEY platform_incident_route_idx (route, occurred_at),
  KEY platform_incident_request_idx (request_id),
  KEY platform_incident_user_idx (user_id, occurred_at),
  KEY platform_incident_company_idx (company_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_members (
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  role ENUM('owner','admin','editor','viewer') NOT NULL DEFAULT 'owner',
  status ENUM('active','suspended','revoked') NOT NULL DEFAULT 'active',
  status_reason VARCHAR(500) NULL,
  updated_by VARCHAR(64) NULL,
  suspended_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, user_id),
  KEY company_members_user_status_idx (user_id, status, company_id),
  CONSTRAINT company_members_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT company_members_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_sequences (
  company_id VARCHAR(64) NOT NULL,
  document_type VARCHAR(40) NOT NULL,
  next_number BIGINT UNSIGNED NOT NULL DEFAULT 1001,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, document_type),
  CONSTRAINT document_sequences_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT document_sequences_next_ck CHECK (next_number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL,
  account_type ENUM('asset','liability','equity','income','expense') NOT NULL,
  normal_balance ENUM('debit','credit') NOT NULL,
  is_control TINYINT(1) NOT NULL DEFAULT 0,
  gifi_code VARCHAR(10) NULL,
  t2125_line VARCHAR(30) NULL,
  reporting_group VARCHAR(120) NULL,
  expense_category VARCHAR(120) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY accounts_company_code_uq (company_id, code),
  KEY accounts_company_type_idx (company_id, account_type),
  CONSTRAINT accounts_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_system_accounts (
  company_id VARCHAR(64) NOT NULL,
  system_key VARCHAR(80) NOT NULL,
  configured_code VARCHAR(20) NOT NULL,
  account_id VARCHAR(64) NULL,
  status ENUM('active','conflict') NOT NULL DEFAULT 'active',
  conflict_message VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, system_key),
  KEY company_system_account_id_idx (company_id, account_id),
  CONSTRAINT company_system_accounts_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT company_system_accounts_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opening_balance_drafts (
  company_id VARCHAR(64) NOT NULL,
  account_id VARCHAR(64) NOT NULL,
  amount_cents BIGINT NOT NULL DEFAULT 0,
  updated_by VARCHAR(64) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, account_id),
  CONSTRAINT opening_balance_drafts_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT opening_balance_drafts_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT opening_balance_drafts_user_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  contact_name VARCHAR(160) NULL,
  email VARCHAR(254) NULL,
  phone VARCHAR(60) NULL,
  billing_address VARCHAR(500) NULL,
  address_line1 VARCHAR(180) NULL,
  address_line2 VARCHAR(180) NULL,
  city VARCHAR(100) NULL,
  province CHAR(2) NULL,
  postal_code VARCHAR(20) NULL,
  country VARCHAR(80) NOT NULL DEFAULT 'Canada',
  default_terms_days INT NOT NULL DEFAULT 30,
  notes VARCHAR(2000) NULL,
  status ENUM('active','on_hold','inactive') NOT NULL DEFAULT 'active',
  hold_remarks VARCHAR(1000) NULL,
  hold_at DATETIME NULL,
  hold_by VARCHAR(64) NULL,
  release_remarks VARCHAR(1000) NULL,
  released_at DATETIME NULL,
  released_by VARCHAR(64) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY customers_company_name_idx (company_id, name),
  KEY customers_company_status_idx (company_id, status, name),
  CONSTRAINT customers_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT customers_hold_user_fk FOREIGN KEY (hold_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT customers_release_user_fk FOREIGN KEY (released_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT customers_terms_ck CHECK (default_terms_days >= 0 AND default_terms_days <= 3650)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_templates (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  name VARCHAR(100) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  document_title VARCHAR(80) NOT NULL DEFAULT 'INVOICE',
  accent_color CHAR(7) NOT NULL DEFAULT '#C94F2D',
  layout_style VARCHAR(30) NOT NULL DEFAULT 'classic',
  font_family VARCHAR(40) NOT NULL DEFAULT 'Arial',
  business_name_override VARCHAR(160) NULL,
  tax_number_override VARCHAR(40) NULL,
  logo_data MEDIUMTEXT NULL,
  business_address VARCHAR(500) NULL,
  business_email VARCHAR(254) NULL,
  business_phone VARCHAR(60) NULL,
  payment_instructions VARCHAR(1000) NULL,
  footer VARCHAR(1000) NULL,
  show_tax_number TINYINT(1) NOT NULL DEFAULT 1,
  show_payment_instructions TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY invoice_templates_company_name_uq (company_id, name),
  KEY invoice_templates_company_default_idx (company_id, is_default, active),
  CONSTRAINT invoice_templates_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  customer_id VARCHAR(64) NOT NULL,
  number VARCHAR(40) NOT NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  status ENUM('draft','sent','paid','void') NOT NULL DEFAULT 'draft',
  subtotal_cents BIGINT NOT NULL,
  gst_hst_cents BIGINT NOT NULL DEFAULT 0,
  pst_cents BIGINT NOT NULL DEFAULT 0,
  tax_cents BIGINT NOT NULL,
  tax_entry_mode ENUM('none','exclusive','inclusive') NOT NULL DEFAULT 'none',
  total_cents BIGINT NOT NULL,
  balance_cents BIGINT NOT NULL,
  message TEXT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  exchange_rate_micros BIGINT NOT NULL DEFAULT 1000000,
  foreign_subtotal_cents BIGINT NOT NULL DEFAULT 0,
  foreign_tax_cents BIGINT NOT NULL DEFAULT 0,
  foreign_total_cents BIGINT NOT NULL DEFAULT 0,
  foreign_balance_cents BIGINT NOT NULL DEFAULT 0,
  purchase_order VARCHAR(80) NULL,
  import_reference VARCHAR(120) NULL,
  template_id VARCHAR(64) NULL,
  template_snapshot_json LONGTEXT NULL,
  customer_snapshot_json LONGTEXT NULL,
  issued_journal_entry_id VARCHAR(64) NULL,
  is_recurring TINYINT(1) NOT NULL DEFAULT 0,
  is_opening_document TINYINT(1) NOT NULL DEFAULT 0,
  opening_import_id VARCHAR(64) NULL,
  original_paid_cents BIGINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY invoices_company_number_uq (company_id, number),
  UNIQUE KEY invoices_company_import_ref_uq (company_id, import_reference),
  KEY invoices_company_status_idx (company_id, status),
  KEY invoices_company_opening_import_idx (company_id, opening_import_id),
  CONSTRAINT invoices_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT invoices_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT invoices_template_fk FOREIGN KEY (template_id) REFERENCES invoice_templates(id) ON DELETE SET NULL,
  CONSTRAINT invoices_amounts_ck CHECK (subtotal_cents >= 0 AND tax_cents >= 0 AND total_cents = subtotal_cents + tax_cents AND balance_cents >= 0 AND balance_cents <= total_cents),
  CONSTRAINT invoices_foreign_amounts_ck CHECK (exchange_rate_micros > 0 AND foreign_subtotal_cents >= 0 AND foreign_tax_cents >= 0 AND foreign_total_cents = foreign_subtotal_cents + foreign_tax_cents AND foreign_balance_cents >= 0 AND foreign_balance_cents <= foreign_total_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_lines (
  id VARCHAR(64) PRIMARY KEY,
  invoice_id VARCHAR(64) NOT NULL,
  product_service_id VARCHAR(64) NULL,
  income_account_id VARCHAR(64) NULL,
  description VARCHAR(500) NOT NULL,
  quantity_milli INT NOT NULL DEFAULT 1000,
  unit_price_cents BIGINT NOT NULL,
  tax_rate_bps INT NOT NULL DEFAULT 0,
  amount_cents BIGINT NOT NULL,
  tax_cents BIGINT NOT NULL,
  foreign_unit_price_cents BIGINT NOT NULL DEFAULT 0,
  foreign_amount_cents BIGINT NOT NULL DEFAULT 0,
  foreign_tax_cents BIGINT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  KEY invoice_lines_invoice_idx (invoice_id),
  KEY invoice_lines_product_idx (product_service_id),
  KEY invoice_lines_income_account_idx (income_account_id),
  CONSTRAINT invoice_lines_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_accounts (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  ledger_account_id VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  account_type ENUM('bank','credit_card') NOT NULL,
  profile_json LONGTEXT NULL COMMENT 'Schema 43: versioned operational subtype and reporting terms; legacy posting family is unchanged',
  masked_number VARCHAR(30) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  statement_balance_cents BIGINT NOT NULL DEFAULT 0,
  last_reconciled_date DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY bank_accounts_company_idx (company_id),
  CONSTRAINT bank_accounts_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT bank_accounts_ledger_fk FOREIGN KEY (ledger_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  bank_account_id VARCHAR(64) NOT NULL,
  filename VARCHAR(240) NOT NULL,
  file_type VARCHAR(120) NOT NULL,
  source_path VARCHAR(500) NOT NULL,
  preview_id VARCHAR(64) NULL,
  status ENUM('uploaded','extracted','needs_review','failed') NOT NULL,
  row_count INT NOT NULL DEFAULT 0,
  duplicate_count INT NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  exchange_rate_micros BIGINT NOT NULL DEFAULT 1000000,
  opening_balance_cents BIGINT NULL,
  closing_balance_cents BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY import_batches_company_created_idx (company_id, created_at),
  CONSTRAINT import_batches_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT import_batches_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_transactions (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  bank_account_id VARCHAR(64) NOT NULL,
  import_batch_id VARCHAR(64) NULL,
  transaction_date DATE NOT NULL,
  description VARCHAR(500) NOT NULL,
  reference VARCHAR(120) NULL,
  remarks VARCHAR(500) NOT NULL DEFAULT '',
  normalized_merchant VARCHAR(200) NULL,
  amount_cents BIGINT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  foreign_amount_cents BIGINT NOT NULL DEFAULT 0,
  exchange_rate_micros BIGINT NOT NULL DEFAULT 1000000,
  suggested_account_id VARCHAR(64) NULL,
  decided_account_id VARCHAR(64) NULL,
  tax_code VARCHAR(30) NOT NULL DEFAULT 'NO_TAX',
  confidence INT NOT NULL DEFAULT 0,
  suggestion_source ENUM('rule','history','ai','manual') NOT NULL DEFAULT 'rule',
  ai_explanation VARCHAR(500) NULL,
  source_hash CHAR(64) NOT NULL,
  source_row_number INT NULL,
  source_sequence BIGINT NULL,
  source_running_balance_cents BIGINT NULL,
  status ENUM('pending','posted','duplicate','excluded') NOT NULL DEFAULT 'pending',
  journal_entry_id VARCHAR(64) NULL,
  conversation_id VARCHAR(64) NULL,
  plan_id VARCHAR(64) NULL,
  task_id VARCHAR(64) NULL,
  operation_key CHAR(64) NULL,
  result_status ENUM('none','completed','needs_review','failed') NOT NULL DEFAULT 'none',
  result_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY bank_transactions_company_hash_uq (company_id, source_hash),
  KEY bank_transactions_company_status_idx (company_id, status),
  KEY bank_transactions_bank_date_idx (bank_account_id, transaction_date),
  CONSTRAINT bank_transactions_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT bank_transactions_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
  CONSTRAINT bank_transactions_batch_fk FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
  CONSTRAINT bank_transactions_suggested_fk FOREIGN KEY (suggested_account_id) REFERENCES accounts(id),
  CONSTRAINT bank_transactions_decided_fk FOREIGN KEY (decided_account_id) REFERENCES accounts(id),
  CONSTRAINT bank_transactions_amount_ck CHECK (amount_cents <> 0),
  CONSTRAINT bank_transactions_fx_ck CHECK (foreign_amount_cents <> 0 AND exchange_rate_micros > 0),
  CONSTRAINT bank_transactions_confidence_ck CHECK (confidence >= 0 AND confidence <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_entries (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  entry_date DATE NOT NULL,
  source_type VARCHAR(60) NOT NULL,
  source_id VARCHAR(64) NOT NULL,
  memo VARCHAR(500) NOT NULL,
  status ENUM('pending_post','posted','reversed') NOT NULL DEFAULT 'posted',
  reversal_of_id VARCHAR(64) NULL,
  content_hash CHAR(64) NOT NULL,
  voided_at DATETIME NULL,
  voided_by VARCHAR(64) NULL,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY journal_entries_source_uq (company_id, source_type, source_id),
  KEY journal_entries_company_date_idx (company_id, entry_date),
  CONSTRAINT journal_entries_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT journal_entries_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT journal_entries_voided_user_fk FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_lines (
  id VARCHAR(64) PRIMARY KEY,
  journal_entry_id VARCHAR(64) NULL,
  account_id VARCHAR(64) NOT NULL,
  debit_cents BIGINT NOT NULL DEFAULT 0,
  credit_cents BIGINT NOT NULL DEFAULT 0,
  memo VARCHAR(500) NOT NULL DEFAULT '',
  -- Signed residual assigned to this line when independently converted FX
  -- components differ from the exact converted gross by one or more cents.
  fx_rounding_cents BIGINT NOT NULL DEFAULT 0,
  KEY journal_lines_entry_idx (journal_entry_id),
  KEY journal_lines_account_idx (account_id),
  CONSTRAINT journal_lines_entry_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  CONSTRAINT journal_lines_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT journal_lines_sided_ck CHECK (debit_cents >= 0 AND credit_cents >= 0 AND ((debit_cents > 0 AND credit_cents = 0) OR (credit_cents > 0 AND debit_cents = 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_approvals (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_status_transitions (
  entity_type VARCHAR(40) NOT NULL,
  from_status VARCHAR(40) NOT NULL,
  to_status VARCHAR(40) NOT NULL,
  transition_code VARCHAR(80) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (entity_type,from_status,to_status),
  UNIQUE KEY transaction_status_transition_code_uq (transition_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO transaction_status_transitions (entity_type,from_status,to_status,transition_code) VALUES
('invoice','draft','sent','invoice.issue'),('invoice','draft','void','invoice.void_draft'),('invoice','sent','paid','invoice.pay'),('invoice','sent','void','invoice.void'),('invoice','paid','sent','invoice.payment_reverse'),('invoice','void','sent','invoice.restore'),
('bill','draft','submitted_for_approval','bill.submit'),('bill','submitted_for_approval','approved','bill.approve'),('bill','submitted_for_approval','draft','bill.return'),('bill','approved','open','bill.post'),('bill','approved','draft','bill.return_approved'),('bill','draft','open','bill.post_below_materiality'),('bill','draft','void','bill.void_draft'),('bill','open','paid','bill.pay'),('bill','open','void','bill.void'),('bill','paid','open','bill.payment_reverse'),('bill','void','open','bill.restore'),
('payment','posted','reversed','payment.reverse'),('payment','reversed','posted','payment.restore'),
('bank_transaction','pending','posted','bank.post'),('bank_transaction','pending','excluded','bank.exclude'),('bank_transaction','pending','duplicate','bank.duplicate'),('bank_transaction','excluded','pending','bank.restore'),('bank_transaction','posted','pending','bank.unpost'),('bank_transaction','posted','excluded','bank.void'),
('journal','pending_post','posted','journal.approve_post'),('journal','posted','reversed','journal.reverse'),('journal','reversed','posted','journal.restore'),
('expense','posted','void','expense.void'),('expense','void','posted','expense.restore');

CREATE TABLE IF NOT EXISTS company_currencies (
  company_id VARCHAR(64) NOT NULL,
  currency_code CHAR(3) NOT NULL,
  rate_to_base_micros BIGINT NOT NULL DEFAULT 1000000,
  rate_date DATE NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, currency_code),
  CONSTRAINT company_currencies_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT company_currencies_rate_ck CHECK (rate_to_base_micros > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_controls (
  company_id VARCHAR(64) PRIMARY KEY,
  closed_through_date DATE NULL,
  materiality_threshold_cents BIGINT NOT NULL DEFAULT 1000000,
  updated_by VARCHAR(64) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT accounting_controls_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT accounting_controls_user_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendors (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  name VARCHAR(200) NOT NULL,
  contact_name VARCHAR(160) NULL,
  email VARCHAR(254) NULL,
  phone VARCHAR(60) NULL,
  address VARCHAR(500) NULL,
  address_line1 VARCHAR(180) NULL,
  address_line2 VARCHAR(180) NULL,
  city VARCHAR(100) NULL,
  province CHAR(2) NULL,
  postal_code VARCHAR(20) NULL,
  country VARCHAR(80) NOT NULL DEFAULT 'Canada',
  default_terms_days INT NOT NULL DEFAULT 30,
  default_expense_account_id VARCHAR(64) NULL,
  default_currency CHAR(3) NOT NULL DEFAULT 'CAD',
  notes VARCHAR(2000) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY vendors_company_name_idx (company_id, name),
  KEY vendors_company_status_idx (company_id, status, name),
  UNIQUE KEY vendors_company_id_uq (company_id, id),
  CONSTRAINT vendors_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT vendors_expense_fk FOREIGN KEY (default_expense_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bills (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  vendor_id VARCHAR(64) NOT NULL,
  product_service_id VARCHAR(64) NULL,
  quantity_milli BIGINT NOT NULL DEFAULT 1000,
  number VARCHAR(60) NOT NULL,
  bill_date DATE NOT NULL,
  due_date DATE NOT NULL,
  status ENUM('draft','submitted_for_approval','approved','open','paid','void') NOT NULL DEFAULT 'draft',
  category_account_id VARCHAR(64) NOT NULL,
  payment_terms_days INT NOT NULL DEFAULT 30,
  subtotal_cents BIGINT NOT NULL,
  gst_hst_cents BIGINT NOT NULL DEFAULT 0,
  pst_cents BIGINT NOT NULL DEFAULT 0,
  tax_cents BIGINT NOT NULL,
  tax_entry_mode ENUM('none','exclusive','inclusive') NOT NULL DEFAULT 'none',
  total_cents BIGINT NOT NULL,
  balance_cents BIGINT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  exchange_rate_micros BIGINT NOT NULL DEFAULT 1000000,
  foreign_subtotal_cents BIGINT NOT NULL,
  foreign_gst_hst_cents BIGINT NOT NULL DEFAULT 0,
  foreign_pst_cents BIGINT NOT NULL DEFAULT 0,
  foreign_tax_cents BIGINT NOT NULL,
  foreign_total_cents BIGINT NOT NULL,
  foreign_balance_cents BIGINT NOT NULL,
  memo VARCHAR(500) NOT NULL DEFAULT '',
  import_reference VARCHAR(120) NULL,
  issued_journal_entry_id VARCHAR(64) NULL,
  is_recurring TINYINT(1) NOT NULL DEFAULT 0,
  is_opening_document TINYINT(1) NOT NULL DEFAULT 0,
  opening_import_id VARCHAR(64) NULL,
  original_paid_cents BIGINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY bills_company_number_uq (company_id, number),
  UNIQUE KEY bills_company_import_ref_uq (company_id, import_reference),
  KEY bills_company_status_due_idx (company_id, status, due_date),
  KEY bills_company_opening_import_idx (company_id, opening_import_id),
  KEY bills_product_service_idx (company_id, product_service_id),
  CONSTRAINT bills_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT bills_vendor_fk FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT bills_category_fk FOREIGN KEY (category_account_id) REFERENCES accounts(id),
  CONSTRAINT bills_terms_ck CHECK (payment_terms_days >= 0 AND payment_terms_days <= 3650),
  CONSTRAINT bills_amounts_ck CHECK (subtotal_cents >= 0 AND tax_cents >= 0 AND total_cents = subtotal_cents + tax_cents AND balance_cents >= 0),
  CONSTRAINT bills_foreign_amounts_ck CHECK (exchange_rate_micros > 0 AND foreign_subtotal_cents >= 0 AND foreign_tax_cents >= 0 AND foreign_total_cents = foreign_subtotal_cents + foreign_tax_cents AND foreign_balance_cents >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS party_payments (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  payment_type ENUM('customer','vendor') NOT NULL,
  flow_kind ENUM('customer_receipt','vendor_payment','customer_refund','vendor_refund') NOT NULL DEFAULT 'customer_receipt',
  party_id VARCHAR(64) NOT NULL,
  document_id VARCHAR(64) NULL,
  payment_date DATE NOT NULL,
  reference VARCHAR(120) NOT NULL,
  memo VARCHAR(500) NOT NULL DEFAULT '',
  amount_cents BIGINT NOT NULL,
  applied_cents BIGINT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  foreign_amount_cents BIGINT NOT NULL,
  exchange_rate_micros BIGINT NOT NULL DEFAULT 1000000,
  payment_account_id VARCHAR(64) NOT NULL,
  journal_entry_id VARCHAR(64) NULL,
  bank_transaction_id VARCHAR(64) NULL,
  status ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
  reversal_journal_entry_id VARCHAR(64) NULL,
  created_by VARCHAR(64) NOT NULL,
  reversed_by VARCHAR(64) NULL,
  reversed_at DATETIME NULL,
  matched_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY party_payments_bank_transaction_uq (bank_transaction_id),
  KEY party_payments_company_type_date_idx (company_id, payment_type, payment_date),
  KEY party_payments_company_document_idx (company_id, document_id, status),
  KEY party_payments_company_match_idx (company_id, status, bank_transaction_id),
  CONSTRAINT party_payments_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT party_payments_account_fk FOREIGN KEY (payment_account_id) REFERENCES accounts(id),
  CONSTRAINT party_payments_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
  CONSTRAINT party_payments_bank_transaction_fk FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL,
  CONSTRAINT party_payments_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT party_payments_reversed_user_fk FOREIGN KEY (reversed_by) REFERENCES users(id),
  CONSTRAINT party_payments_amount_ck CHECK (amount_cents > 0 AND applied_cents >= 0 AND applied_cents <= amount_cents AND foreign_amount_cents > 0 AND exchange_rate_micros > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS party_payment_application_operations (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  operation_key VARCHAR(120) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  status ENUM('active','completed','failed') NOT NULL DEFAULT 'active',
  result_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY payment_application_operation_uq (company_id,user_id,operation_key),
  KEY payment_application_operation_company_status (company_id,status,created_at),
  CONSTRAINT payment_application_operation_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT payment_application_operation_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS party_payment_applications (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  payment_id VARCHAR(64) NOT NULL,
  document_type ENUM('invoice','bill') NOT NULL,
  document_id VARCHAR(64) NOT NULL,
  partner_type ENUM('customer','vendor') NOT NULL,
  partner_id VARCHAR(64) NOT NULL,
  application_date DATE NOT NULL,
  foreign_amount_cents BIGINT NOT NULL,
  payment_carrying_cents BIGINT NOT NULL,
  document_carrying_cents BIGINT NOT NULL,
  recognition_journal_entry_id VARCHAR(64) NULL,
  status ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
  reversal_of_id VARCHAR(64) NULL,
  operation_id VARCHAR(64) NOT NULL,
  created_by VARCHAR(64) NOT NULL,
  reversed_by VARCHAR(64) NULL,
  reversed_at DATETIME NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_legacy_mapping_reviews (
  payment_id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  reason_code VARCHAR(80) NOT NULL,
  evidence_json LONGTEXT NOT NULL,
  status ENUM('review_required','resolved') NOT NULL DEFAULT 'review_required',
  resolved_by VARCHAR(64) NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY payment_legacy_review_company_status (company_id,status,created_at),
  CONSTRAINT payment_legacy_review_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT payment_legacy_review_payment_fk FOREIGN KEY (payment_id) REFERENCES party_payments(id) ON DELETE CASCADE,
  CONSTRAINT payment_legacy_review_user_fk FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS interbank_transfers (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  source_bank_account_id VARCHAR(64) NOT NULL,
  destination_bank_account_id VARCHAR(64) NOT NULL,
  currency CHAR(3) NOT NULL,
  amount_cents BIGINT NOT NULL,
  journal_entry_id VARCHAR(64) NOT NULL,
  operation_key VARCHAR(120) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  remarks VARCHAR(500) NOT NULL DEFAULT '',
  status ENUM('awaiting_counterpart','matched','reversed','needs_review') NOT NULL DEFAULT 'awaiting_counterpart',
  reversal_journal_entry_id VARCHAR(64) NULL,
  reversed_by VARCHAR(64) NULL,
  reversed_at DATETIME NULL,
  reversal_reason VARCHAR(500) NULL,
  created_by VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS interbank_transfer_legs (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  transfer_id VARCHAR(64) NOT NULL,
  bank_transaction_id VARCHAR(64) NOT NULL,
  bank_account_id VARCHAR(64) NOT NULL,
  journal_line_id VARCHAR(64) NOT NULL,
  link_kind ENUM('posted_origin','matched_counterpart') NOT NULL DEFAULT 'posted_origin',
  operation_key VARCHAR(120) NULL,
  payload_hash CHAR(64) NULL,
  leg_role ENUM('source','destination') NOT NULL,
  status ENUM('linked','unmatched') NOT NULL DEFAULT 'linked',
  linked_by VARCHAR(64) NOT NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unmatched_by VARCHAR(64) NULL,
  unmatched_at DATETIME NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS interbank_transfer_operations (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  transfer_id VARCHAR(64) NULL,
  initiated_by VARCHAR(64) NOT NULL,
  operation_key VARCHAR(120) NOT NULL,
  operation_type ENUM('adopt','unmatch','reverse') NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  status ENUM('pending_approval','completed') NOT NULL DEFAULT 'completed',
  result_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY interbank_operation_key_uq (company_id, operation_key),
  KEY interbank_operation_transfer_status (company_id, transfer_id, operation_type, status),
  CONSTRAINT interbank_operation_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT interbank_operation_transfer_fk FOREIGN KEY (transfer_id) REFERENCES interbank_transfers(id) ON DELETE SET NULL,
  CONSTRAINT interbank_operation_user_fk FOREIGN KEY (initiated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_statement_balance_anchors (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  bank_account_id VARCHAR(64) NOT NULL,
  anchor_date DATE NOT NULL,
  balance_cents BIGINT NOT NULL,
  currency CHAR(3) NOT NULL,
  provenance ENUM('source_file','source_metadata','prior_statement','user_statement') NOT NULL,
  source_checksum CHAR(64) NOT NULL,
  evidence_json LONGTEXT NOT NULL,
  revision BIGINT UNSIGNED NOT NULL,
  status ENUM('active','superseded','disputed') NOT NULL DEFAULT 'active',
  created_by VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY statement_anchor_revision_uq (company_id,bank_account_id,revision),
  KEY statement_anchor_account_date (company_id,bank_account_id,anchor_date,status),
  CONSTRAINT statement_anchor_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT statement_anchor_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
  CONSTRAINT statement_anchor_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_import_control_receipts (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  preview_id VARCHAR(64) NULL,
  import_batch_id VARCHAR(64) NULL,
  bank_account_id VARCHAR(64) NOT NULL,
  operation_key VARCHAR(120) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  source_checksum CHAR(64) NOT NULL,
  coverage_start DATE NULL,
  coverage_end DATE NULL,
  currency CHAR(3) NOT NULL,
  parsed_count INT NOT NULL,
  selected_count INT NOT NULL,
  existing_count INT NOT NULL,
  invalid_count INT NOT NULL,
  omitted_count INT NOT NULL,
  money_in_cents BIGINT NOT NULL,
  money_out_cents BIGINT NOT NULL,
  source_opening_cents BIGINT NULL,
  calculated_closing_cents BIGINT NULL,
  source_closing_cents BIGINT NULL,
  difference_cents BIGINT NULL,
  prior_statement_balance_cents BIGINT NULL,
  projected_statement_balance_cents BIGINT NULL,
  book_balance_before_cents BIGINT NOT NULL,
  book_balance_after_cents BIGINT NOT NULL,
  completeness_state ENUM('checks_passed_review_required','difference_found','partial_import','history_gap','balance_check_unavailable') NOT NULL,
  result_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY bank_import_receipt_operation_uq (company_id,user_id,operation_key),
  UNIQUE KEY bank_import_receipt_batch_uq (company_id,import_batch_id),
  KEY bank_import_receipt_account_time (company_id,bank_account_id,created_at),
  CONSTRAINT bank_import_receipt_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT bank_import_receipt_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT bank_import_receipt_batch_fk FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
  CONSTRAINT bank_import_receipt_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opening_balance_imports (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  effective_date DATE NOT NULL,
  filename VARCHAR(240) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  row_count INT NOT NULL,
  total_debit_cents BIGINT NOT NULL,
  total_credit_cents BIGINT NOT NULL,
  journal_entry_id VARCHAR(64) NOT NULL,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY opening_balance_imports_company_hash_uq (company_id, source_hash),
  KEY opening_balance_imports_company_date_idx (company_id, effective_date),
  CONSTRAINT opening_balance_imports_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT opening_balance_imports_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
  CONSTRAINT opening_balance_imports_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT opening_balance_imports_balanced_ck CHECK (total_debit_cents = total_credit_cents AND total_debit_cents > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opening_document_imports (
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
  CONSTRAINT opening_document_imports_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT opening_document_imports_amount_ck CHECK (document_count > 0 AND original_total_cents > 0 AND previously_paid_cents >= 0 AND outstanding_total_cents > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_settings (
  company_id VARCHAR(64) PRIMARY KEY,
  payroll_account_number VARCHAR(40) NULL,
  default_frequency ENUM('weekly','biweekly','semimonthly','monthly') NOT NULL DEFAULT 'biweekly',
  remitter_type ENUM('regular','quarterly','accelerated_1','accelerated_2') NOT NULL DEFAULT 'regular',
  wages_account_id VARCHAR(64) NOT NULL,
  employer_expense_account_id VARCHAR(64) NOT NULL,
  net_pay_account_id VARCHAR(64) NOT NULL,
  tax_payable_account_id VARCHAR(64) NOT NULL,
  cpp_payable_account_id VARCHAR(64) NOT NULL,
  ei_payable_account_id VARCHAR(64) NOT NULL,
  other_payable_account_id VARCHAR(64) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT payroll_settings_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT payroll_settings_wages_fk FOREIGN KEY (wages_account_id) REFERENCES accounts(id),
  CONSTRAINT payroll_settings_employer_expense_fk FOREIGN KEY (employer_expense_account_id) REFERENCES accounts(id),
  CONSTRAINT payroll_settings_net_pay_fk FOREIGN KEY (net_pay_account_id) REFERENCES accounts(id),
  CONSTRAINT payroll_settings_tax_fk FOREIGN KEY (tax_payable_account_id) REFERENCES accounts(id),
  CONSTRAINT payroll_settings_cpp_fk FOREIGN KEY (cpp_payable_account_id) REFERENCES accounts(id),
  CONSTRAINT payroll_settings_ei_fk FOREIGN KEY (ei_payable_account_id) REFERENCES accounts(id),
  CONSTRAINT payroll_settings_other_fk FOREIGN KEY (other_payable_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_employees (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  employee_number VARCHAR(30) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(254) NULL,
  sin_ciphertext TEXT NULL,
  sin_last_four CHAR(4) NULL,
  province_of_employment CHAR(2) NOT NULL,
  province_of_residence CHAR(2) NOT NULL,
  date_of_birth DATE NULL,
  hire_date DATE NOT NULL,
  termination_date DATE NULL,
  pay_frequency ENUM('weekly','biweekly','semimonthly','monthly') NOT NULL DEFAULT 'biweekly',
  pay_type ENUM('salary','hourly') NOT NULL,
  annual_salary_cents BIGINT NOT NULL DEFAULT 0,
  hourly_rate_cents BIGINT NOT NULL DEFAULT 0,
  standard_hours_milli INT NOT NULL DEFAULT 0,
  vacation_rate_bps INT NOT NULL DEFAULT 400,
  vacation_paid_each_pay TINYINT(1) NOT NULL DEFAULT 0,
  federal_td1_cents BIGINT NULL,
  provincial_td1_cents BIGINT NULL,
  additional_tax_cents BIGINT NOT NULL DEFAULT 0,
  cpp_exempt TINYINT(1) NOT NULL DEFAULT 0,
  ei_exempt TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY payroll_employees_company_number_uq (company_id, employee_number),
  KEY payroll_employees_company_active_idx (company_id, active, last_name),
  CONSTRAINT payroll_employees_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT payroll_employees_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT payroll_employees_pay_ck CHECK ((pay_type = 'salary' AND annual_salary_cents > 0) OR (pay_type = 'hourly' AND hourly_rate_cents > 0)),
  CONSTRAINT payroll_employees_vacation_ck CHECK (vacation_rate_bps >= 0 AND vacation_rate_bps <= 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_runs (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  pay_date DATE NOT NULL,
  frequency ENUM('weekly','biweekly','semimonthly','monthly') NOT NULL,
  run_sequence INT NOT NULL DEFAULT 1,
  status ENUM('draft','verified','posted','paid','reversed') NOT NULL DEFAULT 'draft',
  gl_status ENUM('not_ready','ready_to_post','posted','not_applicable','reversed') NOT NULL DEFAULT 'not_ready',
  calculation_version VARCHAR(100) NOT NULL,
  rate_snapshot_json LONGTEXT NULL,
  verification_reference VARCHAR(200) NULL,
  gross_pay_cents BIGINT NOT NULL DEFAULT 0,
  employee_cpp_cents BIGINT NOT NULL DEFAULT 0,
  employee_cpp2_cents BIGINT NOT NULL DEFAULT 0,
  employee_ei_cents BIGINT NOT NULL DEFAULT 0,
  income_tax_cents BIGINT NOT NULL DEFAULT 0,
  other_deductions_cents BIGINT NOT NULL DEFAULT 0,
  net_pay_cents BIGINT NOT NULL DEFAULT 0,
  employer_cpp_cents BIGINT NOT NULL DEFAULT 0,
  employer_cpp2_cents BIGINT NOT NULL DEFAULT 0,
  employer_ei_cents BIGINT NOT NULL DEFAULT 0,
  employer_levy_cents BIGINT NOT NULL DEFAULT 0,
  accrual_journal_entry_id VARCHAR(64) NULL,
  payment_journal_entry_id VARCHAR(64) NULL,
  payment_date DATE NULL,
  created_by VARCHAR(64) NOT NULL,
  create_operation_key VARCHAR(64) NULL,
  create_request_hash CHAR(64) NULL,
  approved_by VARCHAR(64) NULL,
  approved_at DATETIME NULL,
  gl_posted_by VARCHAR(64) NULL,
  gl_posted_at DATETIME NULL,
  reversed_by VARCHAR(64) NULL,
  reversed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY payroll_runs_company_period_sequence_uq (company_id, period_start, period_end, frequency, run_sequence),
  UNIQUE KEY payroll_runs_create_operation_uq (company_id, created_by, create_operation_key),
  KEY payroll_runs_company_paydate_idx (company_id, pay_date, status),
  CONSTRAINT payroll_runs_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT payroll_runs_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT payroll_runs_approved_user_fk FOREIGN KEY (approved_by) REFERENCES users(id),
  CONSTRAINT payroll_runs_gl_posted_user_fk FOREIGN KEY (gl_posted_by) REFERENCES users(id),
  CONSTRAINT payroll_runs_reversed_user_fk FOREIGN KEY (reversed_by) REFERENCES users(id),
  CONSTRAINT payroll_runs_dates_ck CHECK (period_start <= period_end AND period_end <= pay_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_items (
  id VARCHAR(64) PRIMARY KEY,
  payroll_run_id VARCHAR(64) NOT NULL,
  employee_id VARCHAR(64) NOT NULL,
  regular_hours_milli INT NOT NULL DEFAULT 0,
  overtime_hours_milli INT NOT NULL DEFAULT 0,
  regular_pay_cents BIGINT NOT NULL DEFAULT 0,
  overtime_pay_cents BIGINT NOT NULL DEFAULT 0,
  additional_pay_cents BIGINT NOT NULL DEFAULT 0,
  vacation_pay_cents BIGINT NOT NULL DEFAULT 0,
  taxable_benefits_cents BIGINT NOT NULL DEFAULT 0,
  gross_pay_cents BIGINT NOT NULL DEFAULT 0,
  pensionable_pay_cents BIGINT NOT NULL DEFAULT 0,
  insurable_pay_cents BIGINT NOT NULL DEFAULT 0,
  employee_cpp_cents BIGINT NOT NULL DEFAULT 0,
  employee_cpp2_cents BIGINT NOT NULL DEFAULT 0,
  employee_ei_cents BIGINT NOT NULL DEFAULT 0,
  estimated_income_tax_cents BIGINT NOT NULL DEFAULT 0,
  verified_income_tax_cents BIGINT NULL,
  verification_status ENUM('unverified','formula_verified','official_verified','difference','manual_override') NOT NULL DEFAULT 'unverified',
  other_deductions_cents BIGINT NOT NULL DEFAULT 0,
  net_pay_cents BIGINT NOT NULL DEFAULT 0,
  employer_cpp_cents BIGINT NOT NULL DEFAULT 0,
  employer_cpp2_cents BIGINT NOT NULL DEFAULT 0,
  employer_ei_cents BIGINT NOT NULL DEFAULT 0,
  calculation_json LONGTEXT NOT NULL,
  locked_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY payroll_run_items_employee_uq (payroll_run_id, employee_id),
  KEY payroll_run_items_employee_idx (employee_id, payroll_run_id),
  CONSTRAINT payroll_run_items_run_fk FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
  CONSTRAINT payroll_run_items_employee_fk FOREIGN KEY (employee_id) REFERENCES payroll_employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_remittances (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  period_end DATE NOT NULL,
  payment_date DATE NOT NULL,
  employee_tax_cents BIGINT NOT NULL,
  cpp_cents BIGINT NOT NULL,
  ei_cents BIGINT NOT NULL,
  total_cents BIGINT NOT NULL,
  status ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
  bank_account_id VARCHAR(64) NOT NULL,
  bank_transaction_id VARCHAR(64) NULL,
  journal_entry_id VARCHAR(64) NULL,
  reversal_journal_entry_id VARCHAR(64) NULL,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY payroll_remittances_company_period_idx (company_id, period_end),
  UNIQUE KEY payroll_remittances_bank_transaction_uq (bank_transaction_id),
  CONSTRAINT payroll_remittances_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT payroll_remittances_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
  CONSTRAINT payroll_remittances_bank_transaction_fk FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL,
  CONSTRAINT payroll_remittances_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT payroll_remittances_total_ck CHECK (total_cents = employee_tax_cents + cpp_cents + ei_cents AND total_cents > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  vendor VARCHAR(200) NOT NULL,
  expense_date DATE NOT NULL,
  category_account_id VARCHAR(64) NOT NULL,
  paid_from_account_id VARCHAR(64) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  exchange_rate_micros BIGINT NOT NULL DEFAULT 1000000,
  foreign_subtotal_cents BIGINT NOT NULL DEFAULT 0,
  foreign_gst_hst_cents BIGINT NOT NULL DEFAULT 0,
  foreign_pst_cents BIGINT NOT NULL DEFAULT 0,
  foreign_tax_cents BIGINT NOT NULL DEFAULT 0,
  foreign_total_cents BIGINT NOT NULL DEFAULT 0,
  subtotal_cents BIGINT NOT NULL,
  gst_hst_cents BIGINT NOT NULL DEFAULT 0,
  pst_cents BIGINT NOT NULL DEFAULT 0,
  tax_cents BIGINT NOT NULL,
  tax_entry_mode ENUM('none','exclusive','inclusive') NOT NULL DEFAULT 'none',
  total_cents BIGINT NOT NULL,
  tax_code VARCHAR(30) NOT NULL DEFAULT 'NO_TAX',
  receipt_path VARCHAR(500) NULL,
  status ENUM('posted','void') NOT NULL DEFAULT 'posted',
  journal_entry_id VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY expenses_company_date_idx (company_id, expense_date),
  CONSTRAINT expenses_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT expenses_category_fk FOREIGN KEY (category_account_id) REFERENCES accounts(id),
  CONSTRAINT expenses_paid_from_fk FOREIGN KEY (paid_from_account_id) REFERENCES accounts(id),
  CONSTRAINT expenses_amounts_ck CHECK (subtotal_cents >= 0 AND tax_cents >= 0 AND total_cents = subtotal_cents + tax_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reconciliations (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  bank_account_id VARCHAR(64) NOT NULL,
  period_start DATE NULL,
  period_end DATE NOT NULL,
  statement_balance_cents BIGINT NOT NULL,
  book_balance_cents BIGINT NOT NULL,
  difference_cents BIGINT NOT NULL,
  notes VARCHAR(1000) NOT NULL DEFAULT '',
  status ENUM('draft','complete') NOT NULL,
  prepared_by VARCHAR(64) NULL,
  reviewed_by VARCHAR(64) NULL,
  reviewed_at DATETIME NULL,
  reopened_by VARCHAR(64) NULL,
  reopened_at DATETIME NULL,
  reopen_reason VARCHAR(1000) NULL,
  completed_at DATETIME NULL,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY reconciliations_bank_period_uq (bank_account_id, period_end),
  CONSTRAINT reconciliations_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT reconciliations_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
  CONSTRAINT reconciliations_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT reconciliations_balance_ck CHECK (status <> 'complete' OR difference_cents = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reconciliation_items (
  reconciliation_id VARCHAR(64) NOT NULL,
  bank_transaction_id VARCHAR(64) NOT NULL,
  cleared TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (reconciliation_id, bank_transaction_id),
  CONSTRAINT reconciliation_items_reconciliation_fk FOREIGN KEY (reconciliation_id) REFERENCES reconciliations(id) ON DELETE CASCADE,
  CONSTRAINT reconciliation_items_transaction_fk FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS category_rules (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  merchant_pattern VARCHAR(200) NOT NULL,
  account_id VARCHAR(64) NOT NULL,
  tax_code VARCHAR(30) NOT NULL DEFAULT 'NO_TAX',
  use_count INT NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY category_rules_company_pattern_uq (company_id, merchant_pattern),
  CONSTRAINT category_rules_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT category_rules_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_runs (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  model VARCHAR(100) NOT NULL,
  purpose VARCHAR(80) NOT NULL,
  input_count INT NOT NULL DEFAULT 0,
  output_count INT NOT NULL DEFAULT 0,
  status ENUM('completed','failed') NOT NULL,
  error_code VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ai_runs_company_created_idx (company_id, created_at),
  CONSTRAINT ai_runs_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_runs_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contains metadata only. Sensitive values and their hashes are never stored.
CREATE TABLE IF NOT EXISTS ai_redaction_log (
  id VARCHAR(64) PRIMARY KEY,
  request_id VARCHAR(80) NOT NULL,
  hit_types_json LONGTEXT NOT NULL,
  hit_count INT NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ai_redaction_log_created_idx (created_at),
  KEY ai_redaction_log_request_idx (request_id),
  CONSTRAINT ai_redaction_log_count_ck CHECK (hit_count > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS ai_agent_incidents (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  title VARCHAR(200) NOT NULL,
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status ENUM('open','investigating','resolved','dismissed') NOT NULL DEFAULT 'open',
  description LONGTEXT NOT NULL,
  steps_to_reproduce LONGTEXT NOT NULL,
  expected_behavior LONGTEXT NOT NULL,
  actual_behavior LONGTEXT NOT NULL,
  context_json LONGTEXT NOT NULL,
  error_count INT NOT NULL DEFAULT 0,
  resolution_note TEXT NULL,
  resolved_at DATETIME NULL,
  resolved_by VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ai_agent_incidents_company_created_idx (company_id, created_at),
  KEY ai_agent_incidents_company_status_idx (company_id, status),
  CONSTRAINT ai_agent_incidents_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_agent_incidents_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_action_authorizations (
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
  conversation_id VARCHAR(64) NULL,
  plan_id VARCHAR(64) NULL,
  task_id VARCHAR(64) NULL,
  operation_key CHAR(64) NULL,
  result_status ENUM('none','completed','needs_review','failed') NOT NULL DEFAULT 'none',
  result_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ai_action_company_user_idx (company_id,user_id,created_at),
  KEY ai_action_status_idx (status,expires_at),
  KEY ai_action_operation_key_idx (operation_key),
  KEY ai_action_conversation_idx (company_id,user_id,conversation_id,created_at),
  CONSTRAINT ai_action_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_action_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_preferences (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_workflow_sessions (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  workflow_key VARCHAR(80) NOT NULL,
  current_step INT NOT NULL DEFAULT 0,
  total_steps INT NOT NULL DEFAULT 0,
  status ENUM('active','completed','abandoned') NOT NULL DEFAULT 'active',
  context_json LONGTEXT NOT NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ai_agent_workflows_company_user_idx (company_id, user_id, updated_at),
  CONSTRAINT ai_agent_workflows_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_agent_workflows_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  actor_user_id VARCHAR(64) NOT NULL,
  actor_email VARCHAR(254) NOT NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id VARCHAR(64) NOT NULL,
  metadata_json LONGTEXT NOT NULL,
  previous_hash CHAR(64) NOT NULL DEFAULT '',
  entry_hash CHAR(64) NOT NULL DEFAULT '',
  request_id VARCHAR(80) NOT NULL DEFAULT '',
  ip_hash CHAR(64) NOT NULL DEFAULT '',
  user_agent_hash CHAR(64) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY audit_log_company_created_idx (company_id, created_at),
  CONSTRAINT audit_log_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT audit_log_user_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS ai_agent_result_sets (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  kind VARCHAR(40) NOT NULL,
  query_json LONGTEXT NOT NULL,
  record_ids_json LONGTEXT NOT NULL,
  selected_ids_json LONGTEXT NOT NULL,
  status_snapshot_json LONGTEXT NOT NULL,
  conversation_id VARCHAR(64) NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ai_result_company_user_idx (company_id,user_id,updated_at),
  KEY ai_result_expiry_idx (expires_at),
  CONSTRAINT ai_result_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_result_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_tasks (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  action_id VARCHAR(120) NOT NULL,
  intent VARCHAR(500) NOT NULL,
  module VARCHAR(80) NOT NULL DEFAULT '',
  workflow_state VARCHAR(60) NOT NULL DEFAULT 'active',
  collected_json LONGTEXT NOT NULL,
  missing_json LONGTEXT NOT NULL,
  result_set_id VARCHAR(64) NULL,
  pending_confirmation_id VARCHAR(64) NULL,
  conversation_id VARCHAR(64) NULL,
  plan_id VARCHAR(64) NULL,
  status ENUM('active','waiting_input','waiting_confirmation','suspended','cancelled','completed') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ai_task_company_user_idx (company_id,user_id,updated_at),
  KEY ai_task_status_idx (status,updated_at),
  CONSTRAINT ai_task_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_task_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT ai_task_result_fk FOREIGN KEY (result_set_id) REFERENCES ai_agent_result_sets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytic_accounts (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(160) NOT NULL,
  parent_id VARCHAR(64) NULL,
  customer_id VARCHAR(64) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY analytic_accounts_company_code_uq (company_id, code),
  KEY analytic_accounts_company_name_idx (company_id, name),
  CONSTRAINT analytic_accounts_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT analytic_accounts_parent_fk FOREIGN KEY (parent_id) REFERENCES analytic_accounts(id) ON DELETE SET NULL,
  CONSTRAINT analytic_accounts_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytic_allocations (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  journal_line_id VARCHAR(64) NOT NULL,
  analytic_account_id VARCHAR(64) NOT NULL,
  percentage_bps INT NOT NULL,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY analytic_allocations_line_account_uq (journal_line_id, analytic_account_id),
  KEY analytic_allocations_company_account_idx (company_id, analytic_account_id),
  CONSTRAINT analytic_allocations_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT analytic_allocations_line_fk FOREIGN KEY (journal_line_id) REFERENCES journal_lines(id) ON DELETE CASCADE,
  CONSTRAINT analytic_allocations_account_fk FOREIGN KEY (analytic_account_id) REFERENCES analytic_accounts(id) ON DELETE CASCADE,
  CONSTRAINT analytic_allocations_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT analytic_allocations_percent_ck CHECK (percentage_bps > 0 AND percentage_bps <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budgets (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('draft','active','closed') NOT NULL DEFAULT 'active',
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY budgets_company_period_idx (company_id, period_start, period_end),
  CONSTRAINT budgets_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT budgets_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT budgets_dates_ck CHECK (period_start <= period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budget_lines (
  id VARCHAR(64) PRIMARY KEY,
  budget_id VARCHAR(64) NOT NULL,
  account_id VARCHAR(64) NOT NULL,
  analytic_account_id VARCHAR(64) NULL,
  planned_cents BIGINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY budget_lines_scope_uq (budget_id, account_id, analytic_account_id),
  KEY budget_lines_account_idx (account_id),
  CONSTRAINT budget_lines_budget_fk FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
  CONSTRAINT budget_lines_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT budget_lines_analytic_fk FOREIGN KEY (analytic_account_id) REFERENCES analytic_accounts(id) ON DELETE SET NULL,
  CONSTRAINT budget_lines_amount_ck CHECK (planned_cents >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fixed_assets (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  name VARCHAR(200) NOT NULL,
  acquisition_date DATE NOT NULL,
  in_service_date DATE NOT NULL,
  original_cost_cents BIGINT NOT NULL,
  salvage_value_cents BIGINT NOT NULL DEFAULT 0,
  useful_life_months INT NOT NULL,
  method ENUM('straight_line') NOT NULL DEFAULT 'straight_line',
  asset_account_id VARCHAR(64) NOT NULL,
  depreciation_expense_account_id VARCHAR(64) NOT NULL,
  accumulated_depreciation_account_id VARCHAR(64) NOT NULL,
  status ENUM('draft','active','fully_depreciated','disposed') NOT NULL DEFAULT 'active',
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY fixed_assets_company_status_idx (company_id, status),
  CONSTRAINT fixed_assets_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fixed_assets_asset_account_fk FOREIGN KEY (asset_account_id) REFERENCES accounts(id),
  CONSTRAINT fixed_assets_expense_account_fk FOREIGN KEY (depreciation_expense_account_id) REFERENCES accounts(id),
  CONSTRAINT fixed_assets_accum_account_fk FOREIGN KEY (accumulated_depreciation_account_id) REFERENCES accounts(id),
  CONSTRAINT fixed_assets_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fixed_assets_values_ck CHECK (original_cost_cents > 0 AND salvage_value_cents >= 0 AND salvage_value_cents < original_cost_cents AND useful_life_months BETWEEN 1 AND 1200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_depreciation_lines (
  id VARCHAR(64) PRIMARY KEY,
  asset_id VARCHAR(64) NOT NULL,
  sequence_number INT NOT NULL,
  period_end DATE NOT NULL,
  depreciation_cents BIGINT NOT NULL,
  status ENUM('planned','posted','reversed') NOT NULL DEFAULT 'planned',
  journal_entry_id VARCHAR(64) NULL,
  posted_by VARCHAR(64) NULL,
  posted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY asset_depreciation_asset_sequence_uq (asset_id, sequence_number),
  UNIQUE KEY asset_depreciation_asset_period_uq (asset_id, period_end),
  CONSTRAINT asset_depreciation_asset_fk FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE,
  CONSTRAINT asset_depreciation_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
  CONSTRAINT asset_depreciation_user_fk FOREIGN KEY (posted_by) REFERENCES users(id),
  CONSTRAINT asset_depreciation_amount_ck CHECK (depreciation_cents > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_journal_templates (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  memo VARCHAR(500) NOT NULL DEFAULT '',
  frequency ENUM('monthly','quarterly','yearly') NOT NULL,
  anchor_day TINYINT UNSIGNED NOT NULL,
  next_run_date DATE NOT NULL,
  end_date DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY recurring_journals_company_due_idx (company_id, active, next_run_date),
  CONSTRAINT recurring_journals_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT recurring_journals_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT recurring_journals_dates_ck CHECK (end_date IS NULL OR next_run_date <= end_date),
  CONSTRAINT recurring_journals_anchor_ck CHECK (anchor_day BETWEEN 1 AND 31)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_journal_lines (
  id VARCHAR(64) PRIMARY KEY,
  template_id VARCHAR(64) NOT NULL,
  account_id VARCHAR(64) NOT NULL,
  debit_cents BIGINT NOT NULL DEFAULT 0,
  credit_cents BIGINT NOT NULL DEFAULT 0,
  memo VARCHAR(500) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  KEY recurring_journal_lines_template_idx (template_id, sort_order),
  CONSTRAINT recurring_journal_lines_template_fk FOREIGN KEY (template_id) REFERENCES recurring_journal_templates(id) ON DELETE CASCADE,
  CONSTRAINT recurring_journal_lines_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT recurring_journal_lines_sided_ck CHECK (debit_cents >= 0 AND credit_cents >= 0 AND ((debit_cents > 0 AND credit_cents = 0) OR (credit_cents > 0 AND debit_cents = 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_invoice_profiles (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  customer_id VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  frequency ENUM('monthly','quarterly','yearly') NOT NULL,
  anchor_day TINYINT UNSIGNED NOT NULL,
  next_invoice_date DATE NOT NULL,
  end_date DATE NULL,
  payment_terms_days INT NOT NULL DEFAULT 30,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  template_id VARCHAR(64) NULL,
  message VARCHAR(1000) NOT NULL DEFAULT '',
  issue_automatically TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY recurring_invoices_company_due_idx (company_id, active, next_invoice_date),
  CONSTRAINT recurring_invoices_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT recurring_invoices_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT recurring_invoices_template_fk FOREIGN KEY (template_id) REFERENCES invoice_templates(id) ON DELETE SET NULL,
  CONSTRAINT recurring_invoices_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT recurring_invoices_terms_ck CHECK (payment_terms_days BETWEEN 0 AND 3650),
  CONSTRAINT recurring_invoices_dates_ck CHECK (end_date IS NULL OR next_invoice_date <= end_date),
  CONSTRAINT recurring_invoices_anchor_ck CHECK (anchor_day BETWEEN 1 AND 31)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_invoice_lines (
  id VARCHAR(64) PRIMARY KEY,
  profile_id VARCHAR(64) NOT NULL,
  description VARCHAR(500) NOT NULL,
  quantity_milli INT NOT NULL DEFAULT 1000,
  foreign_unit_price_cents BIGINT NOT NULL,
  taxable TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  KEY recurring_invoice_lines_profile_idx (profile_id, sort_order),
  CONSTRAINT recurring_invoice_lines_profile_fk FOREIGN KEY (profile_id) REFERENCES recurring_invoice_profiles(id) ON DELETE CASCADE,
  CONSTRAINT recurring_invoice_lines_values_ck CHECK (quantity_milli > 0 AND foreign_unit_price_cents > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_bill_profiles (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  vendor_id VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  frequency ENUM('monthly','quarterly','yearly') NOT NULL,
  anchor_day TINYINT UNSIGNED NOT NULL,
  next_bill_date DATE NOT NULL,
  end_date DATE NULL,
  payment_terms_days INT NOT NULL DEFAULT 30,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  category_account_id VARCHAR(64) NOT NULL,
  foreign_amount_cents BIGINT NOT NULL,
  apply_gst_hst TINYINT(1) NOT NULL DEFAULT 0,
  apply_pst TINYINT(1) NOT NULL DEFAULT 0,
  tax_entry_mode ENUM('none','exclusive','inclusive') NOT NULL DEFAULT 'none',
  memo VARCHAR(500) NOT NULL DEFAULT '',
  issue_automatically TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY recurring_bills_company_due_idx (company_id, active, next_bill_date),
  CONSTRAINT recurring_bills_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT recurring_bills_vendor_fk FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT recurring_bills_account_fk FOREIGN KEY (category_account_id) REFERENCES accounts(id),
  CONSTRAINT recurring_bills_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT recurring_bills_terms_ck CHECK (payment_terms_days BETWEEN 0 AND 3650),
  CONSTRAINT recurring_bills_amount_ck CHECK (foreign_amount_cents > 0),
  CONSTRAINT recurring_bills_dates_ck CHECK (end_date IS NULL OR next_bill_date <= end_date),
  CONSTRAINT recurring_bills_anchor_ck CHECK (anchor_day BETWEEN 1 AND 31)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_followups (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  invoice_id VARCHAR(64) NOT NULL,
  action_date DATE NOT NULL,
  level ENUM('friendly','firm','final') NOT NULL DEFAULT 'friendly',
  channel ENUM('email','phone','letter','other') NOT NULL DEFAULT 'email',
  note VARCHAR(1000) NOT NULL DEFAULT '',
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY invoice_followups_company_date_idx (company_id, action_date),
  KEY invoice_followups_invoice_idx (invoice_id, action_date),
  CONSTRAINT invoice_followups_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT invoice_followups_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT invoice_followups_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_flow_mappings (
  company_id VARCHAR(64) NOT NULL,
  account_id VARCHAR(64) NOT NULL,
  activity ENUM('operating','investing','financing','exchange_effects') NOT NULL,
  updated_by VARCHAR(64) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, account_id),
  CONSTRAINT cash_flow_mappings_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT cash_flow_mappings_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT cash_flow_mappings_user_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Schema 6: bank approval, manual period controls, effective-dated rates,
-- employer levies, payroll verification and tax-reporting mappings.
CREATE TABLE IF NOT EXISTS statement_previews (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  bank_account_id VARCHAR(64) NOT NULL,
  filename VARCHAR(240) NOT NULL,
  file_type VARCHAR(120) NOT NULL,
  source_path VARCHAR(500) NOT NULL,
  source_sha256 CHAR(64) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  exchange_rate_micros BIGINT NOT NULL DEFAULT 1000000,
  opening_balance_cents BIGINT NULL,
  closing_balance_cents BIGINT NULL,
  first_transaction_date DATE NULL,
  last_transaction_date DATE NULL,
  row_count INT NOT NULL DEFAULT 0,
  excluded_count INT NOT NULL DEFAULT 0,
  rows_json LONGTEXT NOT NULL,
  status ENUM('draft','approved','rejected') NOT NULL DEFAULT 'draft',
  approved_batch_id VARCHAR(64) NULL,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY statement_previews_company_hash_uq (company_id, bank_account_id, source_sha256),
  KEY statement_previews_company_status_idx (company_id, status, created_at),
  CONSTRAINT statement_previews_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT statement_previews_bank_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
  CONSTRAINT statement_previews_batch_fk FOREIGN KEY (approved_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
  CONSTRAINT statement_previews_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS period_locks (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  locked TINYINT(1) NOT NULL DEFAULT 1,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  updated_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY period_locks_company_period_uq (company_id, period_start, period_end),
  KEY period_locks_company_status_idx (company_id, locked, period_end),
  CONSTRAINT period_locks_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT period_locks_user_fk FOREIGN KEY (updated_by) REFERENCES users(id),
  CONSTRAINT period_locks_dates_ck CHECK (period_start <= period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reconciliation_events (
  id VARCHAR(64) PRIMARY KEY,
  reconciliation_id VARCHAR(64) NOT NULL,
  company_id VARCHAR(64) NOT NULL,
  action ENUM('created','completed','reopened','reviewed','note') NOT NULL,
  note VARCHAR(1000) NOT NULL DEFAULT '',
  actor_user_id VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY reconciliation_events_recon_idx (reconciliation_id, created_at),
  CONSTRAINT reconciliation_events_recon_fk FOREIGN KEY (reconciliation_id) REFERENCES reconciliations(id) ON DELETE CASCADE,
  CONSTRAINT reconciliation_events_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT reconciliation_events_user_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema 19: explicit bank-side/book-side match groups. A match group can
-- contain several imported bank rows and several posted book journals, which
-- supports one-to-one, one-to-many, many-to-one and controlled adjustments
-- without inferring relationships from text descriptions.
CREATE TABLE IF NOT EXISTS bank_match_groups (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  bank_account_id VARCHAR(64) NOT NULL,
  status ENUM('matched','unreconciled') NOT NULL DEFAULT 'matched',
  bank_amount_cents BIGINT NOT NULL,
  book_amount_cents BIGINT NOT NULL,
  adjustment_journal_entry_id VARCHAR(64) NULL,
  adjustment_reversal_journal_entry_id VARCHAR(64) NULL,
  matched_by VARCHAR(64) NOT NULL,
  matched_at DATETIME NOT NULL,
  unreconciled_by VARCHAR(64) NULL,
  unreconciled_at DATETIME NULL,
  unreconcile_reason_code ENUM('bank_error','duplicate','wrong_book_entry','wrong_period','reconciliation_reopened','other') NULL,
  unreconcile_reason VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY bank_match_groups_account_idx (company_id,bank_account_id,status,matched_at),
  CONSTRAINT bank_match_groups_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT bank_match_groups_account_fk FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
  CONSTRAINT bank_match_groups_adjustment_fk FOREIGN KEY (adjustment_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
  CONSTRAINT bank_match_groups_adjustment_reversal_fk FOREIGN KEY (adjustment_reversal_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
  CONSTRAINT bank_match_groups_matched_user_fk FOREIGN KEY (matched_by) REFERENCES users(id),
  CONSTRAINT bank_match_groups_unreconciled_user_fk FOREIGN KEY (unreconciled_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT bank_match_groups_balanced_ck CHECK (bank_amount_cents = book_amount_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_match_bank_items (
  match_group_id VARCHAR(64) NOT NULL,
  bank_transaction_id VARCHAR(64) NOT NULL,
  amount_cents BIGINT NOT NULL,
  PRIMARY KEY (match_group_id,bank_transaction_id),
  KEY bank_match_bank_items_tx_idx (bank_transaction_id),
  CONSTRAINT bank_match_bank_items_group_fk FOREIGN KEY (match_group_id) REFERENCES bank_match_groups(id) ON DELETE CASCADE,
  CONSTRAINT bank_match_bank_items_tx_fk FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_match_book_items (
  match_group_id VARCHAR(64) NOT NULL,
  journal_entry_id VARCHAR(64) NOT NULL,
  amount_cents BIGINT NOT NULL,
  PRIMARY KEY (match_group_id,journal_entry_id),
  KEY bank_match_book_items_journal_idx (journal_entry_id),
  CONSTRAINT bank_match_book_items_group_fk FOREIGN KEY (match_group_id) REFERENCES bank_match_groups(id) ON DELETE CASCADE,
  CONSTRAINT bank_match_book_items_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reconciliation_match_groups (
  reconciliation_id VARCHAR(64) NOT NULL,
  match_group_id VARCHAR(64) NOT NULL,
  PRIMARY KEY (reconciliation_id,match_group_id),
  CONSTRAINT reconciliation_match_groups_reconciliation_fk FOREIGN KEY (reconciliation_id) REFERENCES reconciliations(id) ON DELETE CASCADE,
  CONSTRAINT reconciliation_match_groups_match_fk FOREIGN KEY (match_group_id) REFERENCES bank_match_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reconciliation_match_proposals (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reconciliation_resume_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS statutory_rates (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  jurisdiction VARCHAR(20) NOT NULL DEFAULT 'CA',
  person_type VARCHAR(40) NOT NULL DEFAULT 'employee',
  rate_key VARCHAR(120) NOT NULL,
  label VARCHAR(200) NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  value_decimal DECIMAL(18,8) NULL,
  value_cents BIGINT NULL,
  threshold_min_cents BIGINT NULL,
  threshold_max_cents BIGINT NULL,
  metadata_json LONGTEXT NOT NULL,
  source_url VARCHAR(1000) NULL,
  source_label VARCHAR(200) NULL,
  status ENUM('draft','active','superseded') NOT NULL DEFAULT 'active',
  created_by VARCHAR(64) NOT NULL,
  approved_by VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY statutory_rates_company_key_date_uq (company_id, jurisdiction, person_type, rate_key, effective_from),
  KEY statutory_rates_effective_idx (company_id, effective_from, effective_to, status),
  CONSTRAINT statutory_rates_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT statutory_rates_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT statutory_rates_approved_user_fk FOREIGN KEY (approved_by) REFERENCES users(id),
  CONSTRAINT statutory_rates_dates_ck CHECK (effective_to IS NULL OR effective_from <= effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employer_levy_profiles (
  company_id VARCHAR(64) PRIMARY KEY,
  jurisdiction CHAR(2) NOT NULL,
  applicability ENUM('jurisdiction_default','custom','not_applicable') NOT NULL DEFAULT 'not_applicable',
  posting_mode ENUM('automatic','draft','report_only','none') NOT NULL DEFAULT 'report_only',
  eligible_for_exemption TINYINT(1) NOT NULL DEFAULT 1,
  associated_group_payroll_cents BIGINT NOT NULL DEFAULT 0,
  expense_account_id VARCHAR(64) NULL,
  payable_account_id VARCHAR(64) NULL,
  updated_by VARCHAR(64) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT employer_levy_profiles_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT employer_levy_profiles_expense_fk FOREIGN KEY (expense_account_id) REFERENCES accounts(id),
  CONSTRAINT employer_levy_profiles_payable_fk FOREIGN KEY (payable_account_id) REFERENCES accounts(id),
  CONSTRAINT employer_levy_profiles_user_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employer_levy_rates (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  jurisdiction CHAR(2) NOT NULL,
  label VARCHAR(200) NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  payroll_min_cents BIGINT NOT NULL DEFAULT 0,
  payroll_max_cents BIGINT NULL,
  exemption_cents BIGINT NOT NULL DEFAULT 0,
  exemption_threshold_cents BIGINT NULL,
  rate_bps INT NOT NULL,
  rate_decimal DECIMAL(12,8) NULL,
  source_url VARCHAR(1000) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY employer_levy_rates_company_date_band_uq (company_id, jurisdiction, effective_from, payroll_min_cents),
  KEY employer_levy_rates_effective_idx (company_id, jurisdiction, effective_from, effective_to, active),
  CONSTRAINT employer_levy_rates_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT employer_levy_rates_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT employer_levy_rates_dates_ck CHECK (effective_to IS NULL OR effective_from <= effective_to),
  CONSTRAINT employer_levy_rates_rate_ck CHECK (rate_bps >= 0 AND rate_bps <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS payroll_journal_drafts (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  payroll_run_id VARCHAR(64) NOT NULL,
  entry_date DATE NOT NULL,
  memo VARCHAR(500) NOT NULL,
  lines_json LONGTEXT NOT NULL,
  status ENUM('draft','posted','discarded') NOT NULL DEFAULT 'draft',
  journal_entry_id VARCHAR(64) NULL,
  created_by VARCHAR(64) NOT NULL,
  posted_by VARCHAR(64) NULL,
  posted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY payroll_journal_drafts_run_uq (payroll_run_id),
  CONSTRAINT payroll_journal_drafts_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT payroll_journal_drafts_run_fk FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
  CONSTRAINT payroll_journal_drafts_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
  CONSTRAINT payroll_journal_drafts_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT payroll_journal_drafts_posted_user_fk FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_verifications (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  payroll_run_id VARCHAR(64) NOT NULL,
  payroll_run_item_id VARCHAR(64) NULL,
  method ENUM('formula','official_calculator') NOT NULL,
  calculator_url VARCHAR(1000) NULL,
  employee_cpp_cents BIGINT NULL,
  employee_cpp2_cents BIGINT NULL,
  employee_ei_cents BIGINT NULL,
  income_tax_cents BIGINT NULL,
  difference_cents BIGINT NOT NULL DEFAULT 0,
  evidence_path VARCHAR(500) NULL,
  note VARCHAR(1000) NOT NULL DEFAULT '',
  verified_by VARCHAR(64) NOT NULL,
  verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY payroll_verifications_run_idx (payroll_run_id, verified_at),
  CONSTRAINT payroll_verifications_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT payroll_verifications_run_fk FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
  CONSTRAINT payroll_verifications_item_fk FOREIGN KEY (payroll_run_item_id) REFERENCES payroll_run_items(id) ON DELETE CASCADE,
  CONSTRAINT payroll_verifications_user_fk FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




-- Schema 9: platform controls, portable backups, invitations, vouchers, notifications and tamper-evident audit history.
CREATE TABLE IF NOT EXISTS voucher_sequences (
  company_id VARCHAR(64) PRIMARY KEY,
  next_serial BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT voucher_sequences_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT voucher_sequences_next_ck CHECK (next_serial > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Schema 19 keeps legacy voucher_sequences intact for compatibility but new
-- human-readable transaction numbers use an atomic company + module prefix
-- sequence. Historical voucher numbers are never rewritten or reused.
CREATE TABLE IF NOT EXISTS transaction_sequences (
  company_id VARCHAR(64) NOT NULL,
  prefix VARCHAR(4) NOT NULL,
  next_serial BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id,prefix),
  CONSTRAINT transaction_sequences_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT transaction_sequences_next_ck CHECK (next_serial > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vouchers (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  serial_number BIGINT UNSIGNED NOT NULL,
  voucher_number VARCHAR(40) NOT NULL,
  prefix VARCHAR(4) NOT NULL,
  module VARCHAR(20) NOT NULL,
  source_type VARCHAR(60) NOT NULL,
  source_id VARCHAR(64) NOT NULL,
  voucher_date DATE NOT NULL,
  description VARCHAR(500) NOT NULL,
  amount_cents BIGINT NOT NULL DEFAULT 0,
  status ENUM('draft','posted','void') NOT NULL DEFAULT 'draft',
  journal_entry_id VARCHAR(64) NULL,
  created_by VARCHAR(64) NOT NULL,
  posted_by VARCHAR(64) NULL,
  posted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY vouchers_company_serial_uq (company_id, serial_number),
  UNIQUE KEY vouchers_company_number_uq (company_id, voucher_number),
  UNIQUE KEY vouchers_company_source_uq (company_id, source_type, source_id),
  KEY vouchers_company_date_idx (company_id, voucher_date, serial_number),
  CONSTRAINT vouchers_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT vouchers_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
  CONSTRAINT vouchers_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT vouchers_posted_user_fk FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voucher_draft_payloads (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  source_type VARCHAR(60) NOT NULL,
  source_id VARCHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY voucher_draft_payloads_source_uq (company_id, source_type, source_id),
  KEY voucher_draft_payloads_company_idx (company_id, updated_at),
  CONSTRAINT voucher_draft_payloads_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT voucher_draft_payloads_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NULL,
  type VARCHAR(60) NOT NULL,
  title VARCHAR(200) NOT NULL,
  message VARCHAR(1000) NOT NULL,
  route VARCHAR(160) NULL,
  severity ENUM('info','success','warning','critical') NOT NULL DEFAULT 'info',
  action_label VARCHAR(120) NULL,
  unique_key VARCHAR(160) NULL,
  read_at DATETIME NULL,
  dismissed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY notifications_company_user_idx (company_id, user_id, dismissed_at, created_at),
  KEY notifications_unique_idx (company_id, unique_key),
  CONSTRAINT notifications_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT notifications_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_invitations (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  email VARCHAR(254) NOT NULL,
  scope ENUM('workspace','company') NOT NULL DEFAULT 'company',
  role ENUM('owner','admin','editor','viewer') NOT NULL DEFAULT 'editor',
  token_hash CHAR(64) NOT NULL,
  status ENUM('pending','accepted','revoked','expired') NOT NULL DEFAULT 'pending',
  invited_by VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  accepted_by VARCHAR(64) NULL,
  accepted_at DATETIME NULL,
  outbound_email_id VARCHAR(64) NULL,
  delivery_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  delivery_error VARCHAR(500) NULL,
  last_sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY company_invitations_token_uq (token_hash),
  KEY company_invitations_company_idx (company_id, status, created_at),
  CONSTRAINT company_invitations_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT company_invitations_inviter_fk FOREIGN KEY (invited_by) REFERENCES users(id),
  CONSTRAINT company_invitations_acceptor_fk FOREIGN KEY (accepted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_error_events (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  kind VARCHAR(40) NOT NULL,
  route VARCHAR(180) NULL,
  method VARCHAR(12) NULL,
  status INT NULL,
  code VARCHAR(100) NULL,
  message VARCHAR(1000) NOT NULL,
  request_id VARCHAR(100) NULL,
  duration_ms INT NULL,
  page_path VARCHAR(500) NULL,
  occurred_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY client_error_events_company_idx (company_id, created_at),
  KEY client_error_events_user_idx (user_id, created_at),
  CONSTRAINT client_error_events_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT client_error_events_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS registration_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  successful TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY registration_attempts_ip_idx (ip_hash, attempted_at),
  KEY registration_attempts_email_idx (email_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outbound_emails (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NULL,
  recipient VARCHAR(254) NOT NULL,
  template_key VARCHAR(80) NOT NULL,
  subject VARCHAR(240) NOT NULL,
  status ENUM('pending','sent','failed','deferred','manual_review','sent_warning') NOT NULL DEFAULT 'pending',
  attempt_count INT NOT NULL DEFAULT 0,
  provider_message VARCHAR(500) NULL,
  created_by VARCHAR(64) NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY outbound_emails_company_idx (company_id, created_at),
  KEY outbound_emails_recipient_idx (recipient, created_at),
  CONSTRAINT outbound_emails_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  CONSTRAINT outbound_emails_user_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_requests (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  requested_by VARCHAR(64) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  message VARCHAR(2000) NOT NULL,
  status ENUM('pending','in_progress','resolved','closed') NOT NULL DEFAULT 'pending',
  grant_access TINYINT(1) NOT NULL DEFAULT 0,
  access_expires_at DATETIME NULL,
  assigned_to VARCHAR(64) NULL,
  resolution_note VARCHAR(2000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  KEY support_requests_company_idx (company_id, status, created_at),
  KEY support_requests_assignee_idx (assigned_to, status),
  CONSTRAINT support_requests_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT support_requests_requester_fk FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT support_requests_assignee_fk FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products_services (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  kind ENUM('product','service') NOT NULL DEFAULT 'service',
  code VARCHAR(60) NULL,
  name VARCHAR(180) NOT NULL,
  description VARCHAR(500) NULL,
  unit_price_cents BIGINT NOT NULL DEFAULT 0,
  taxable TINYINT(1) NOT NULL DEFAULT 1,
  income_account_id VARCHAR(64) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY products_services_company_code_uq (company_id, code),
  KEY products_services_company_idx (company_id, active, name),
  CONSTRAINT products_services_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT products_services_income_fk FOREIGN KEY (income_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT products_services_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS party_opening_balances (
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
  UNIQUE KEY party_opening_company_party_uq (company_id, party_type, party_id),
  KEY party_opening_company_date_idx (company_id, party_type, effective_date),
  CONSTRAINT party_opening_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT party_opening_offset_fk FOREIGN KEY (offset_account_id) REFERENCES accounts(id),
  CONSTRAINT party_opening_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
  CONSTRAINT party_opening_voucher_fk FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE SET NULL,
  CONSTRAINT party_opening_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_import_previews (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  import_type ENUM('customers','vendors','products_services','customer_invoices','vendor_invoices','chart_of_accounts','opening_balances','coa_opening_balances','opening_customer_invoices','opening_vendor_bills') NOT NULL,
  filename VARCHAR(240) NOT NULL DEFAULT '',
  rows_json LONGTEXT NOT NULL,
  validation_json LONGTEXT NOT NULL,
  status ENUM('validated','committing','imported','failed','cancelled') NOT NULL DEFAULT 'validated',
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY data_import_company_user_idx (company_id, user_id, status, created_at),
  KEY data_import_expiry_idx (expires_at),
  CONSTRAINT data_import_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT data_import_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aging_profiles (
  company_id VARCHAR(64) PRIMARY KEY,
  ar_cutoff_1 INT NOT NULL DEFAULT 30,
  ar_cutoff_2 INT NOT NULL DEFAULT 60,
  ar_cutoff_3 INT NOT NULL DEFAULT 90,
  ap_cutoff_1 INT NOT NULL DEFAULT 30,
  ap_cutoff_2 INT NOT NULL DEFAULT 60,
  ap_cutoff_3 INT NOT NULL DEFAULT 90,
  updated_by VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT aging_profiles_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT aging_profiles_user_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS official_rate_releases (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  source_key VARCHAR(80) NOT NULL,
  source_url VARCHAR(1000) NOT NULL,
  release_label VARCHAR(200) NULL,
  effective_date DATE NULL,
  content_hash CHAR(64) NOT NULL,
  status ENUM('unchanged','detected','applied','review_required','failed') NOT NULL,
  records_applied INT NOT NULL DEFAULT 0,
  details_json LONGTEXT NOT NULL,
  checked_by VARCHAR(64) NULL,
  checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY official_rate_releases_company_idx (company_id, source_key, checked_at),
  CONSTRAINT official_rate_releases_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT official_rate_releases_user_fk FOREIGN KEY (checked_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_integrity_runs (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  run_by VARCHAR(64) NOT NULL,
  result_json LONGTEXT NOT NULL,
  status ENUM('passed','attention') NOT NULL,
  last_audit_hash CHAR(64) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY audit_integrity_runs_company_idx (company_id, created_at),
  CONSTRAINT audit_integrity_runs_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT audit_integrity_runs_user_fk FOREIGN KEY (run_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_restore_log (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  backup_id VARCHAR(64) NOT NULL,
  action ENUM('export','restore') NOT NULL,
  filename VARCHAR(240) NOT NULL,
  payload_sha256 CHAR(64) NOT NULL,
  performed_by VARCHAR(64) NOT NULL,
  details_json LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY backup_restore_log_company_idx (company_id, created_at),
  CONSTRAINT backup_restore_log_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT backup_restore_log_user_fk FOREIGN KEY (performed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key, meta_value) VALUES
  ('schema_version', '43'),
  ('schema_v4_data_ready', '1'),
  ('schema_v5_data_ready', '1'),
  ('schema_v6_data_ready', '1'),
  ('schema_v7_data_ready', '1'),
  ('schema_v8_data_ready', '1'),
  ('schema_v9_data_ready', '1'),
  ('schema_v10_data_ready', '1'),
  ('schema_v11_data_ready', '1'),
  ('schema_v12_data_ready', '1'),
  ('schema_v13_data_ready', '1'),
  ('schema_v14_data_ready', '1'),
  ('schema_v15_data_ready', '1'),
  ('schema_v16_data_ready', '1'),
  ('schema_v17_data_ready', '1'),
  ('schema_v18_data_ready', '1'),
  ('schema_v19_data_ready', '1'),
  ('schema_v20_data_ready', '1'),
  ('schema_v21_data_ready', '1'),
  ('schema_v22_data_ready', '1'),
  ('schema_v23_data_ready', '1'),
  ('schema_v24_data_ready', '1'),
  ('schema_v25_data_ready', '1'),
  ('schema_v26_data_ready', '1'),
  ('schema_v27_data_ready', '1'),
  ('schema_v28_data_ready', '1'),
  ('schema_v29_data_ready', '1'),
  ('schema_v30_data_ready', '1'),
  ('schema_v31_data_ready', '1'),
  ('schema_v32_data_ready', '1'),
  ('schema_v33_data_ready', '1'),
  ('schema_v34_data_ready', '1'),
  ('schema_v35_data_ready', '1'),
  ('schema_v36_data_ready', '1'),
  ('schema_v37_data_ready', '1'),
  ('schema_v38_data_ready', '1'),
  ('schema_v39_data_ready', '1'),
  ('schema_v40_data_ready', '1'),
  ('schema_v41_data_ready', '1'),
  ('schema_v42_data_ready', '1'),
  ('entitlement_mode', 'enforced'),
  ('release_checkpoint_5400', 'passed'),
  ('release_checkpoint_5410', 'passed'),
  ('release_checkpoint_5420', 'passed'),
  ('release_checkpoint_5500', 'passed')
ON DUPLICATE KEY UPDATE meta_value = IF(
  meta_key = 'schema_version',
  CAST(GREATEST(CAST(meta_value AS UNSIGNED), CAST(VALUES(meta_value) AS UNSIGNED)) AS CHAR),
  VALUES(meta_value)
);


-- Schema 33 — Tegh AI controlled learning, evaluation and self-improvement.
CREATE TABLE IF NOT EXISTS ai_agent_learning_events (
  id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL,
  event_type VARCHAR(50) NOT NULL, module VARCHAR(80) NULL, action_id VARCHAR(120) NULL, intent_category VARCHAR(50) NULL,
  confidence_bps INT NOT NULL DEFAULT 0, `signal` SMALLINT NOT NULL DEFAULT 0, outcome VARCHAR(30) NOT NULL DEFAULT 'observed',
  command_fingerprint CHAR(64) NULL, command_sample VARCHAR(300) NULL, context_json LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ai_learning_company_created_idx (company_id,created_at), KEY ai_learning_company_action_idx (company_id,action_id(96),created_at), KEY ai_learning_company_fingerprint_idx (company_id,command_fingerprint,created_at),
  CONSTRAINT ai_learning_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_learning_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_learned_rules (
  id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NULL,
  scope_type ENUM('company','user','bank_account','vendor','description') NOT NULL DEFAULT 'company', scope_key VARCHAR(128) NOT NULL DEFAULT '',
  pattern_type VARCHAR(40) NOT NULL, pattern_value VARCHAR(255) NOT NULL, suggestion_type VARCHAR(40) NOT NULL, suggestion_json LONGTEXT NOT NULL,
  positive_count INT NOT NULL DEFAULT 0, negative_count INT NOT NULL DEFAULT 0, confidence_bps INT NOT NULL DEFAULT 0,
  status ENUM('enabled','disabled','retired') NOT NULL DEFAULT 'enabled', source ENUM('inferred','explicit','correction','system') NOT NULL DEFAULT 'inferred',
  last_used_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ai_rules_company_status_idx (company_id,status,confidence_bps), KEY ai_rules_company_pattern_idx (company_id,pattern_type,pattern_value(64)),
  CONSTRAINT ai_rules_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_rules_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_improvements (
  id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, created_by VARCHAR(64) NOT NULL, category VARCHAR(60) NOT NULL,
  title VARCHAR(220) NOT NULL, problem_summary VARCHAR(1000) NOT NULL, current_behavior VARCHAR(1000) NOT NULL DEFAULT '', proposed_change_json LONGTEXT NOT NULL,
  evidence_count INT NOT NULL DEFAULT 0, evidence_fingerprint CHAR(64) NOT NULL, baseline_metrics_json LONGTEXT NOT NULL, candidate_metrics_json LONGTEXT NOT NULL,
  regression_json LONGTEXT NOT NULL, safety_status ENUM('pending','passed','failed','protected_change') NOT NULL DEFAULT 'pending',
  status ENUM('proposed','evaluated','enabled','rejected','rolled_back','review_required') NOT NULL DEFAULT 'proposed', approved_by VARCHAR(64) NULL, approved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ai_improvements_company_fingerprint_uq (company_id,evidence_fingerprint), KEY ai_improvements_company_status_idx (company_id,status,created_at),
  CONSTRAINT ai_improvements_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_improvements_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id), CONSTRAINT ai_improvements_approved_user_fk FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_behavior_versions (
  id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, version_number INT NOT NULL, config_json LONGTEXT NOT NULL,
  source_improvement_id VARCHAR(64) NULL, metrics_json LONGTEXT NOT NULL, active TINYINT(1) NOT NULL DEFAULT 0, created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY ai_behavior_company_version_uq (company_id,version_number), KEY ai_behavior_company_active_idx (company_id,active,version_number),
  CONSTRAINT ai_behavior_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_behavior_improvement_fk FOREIGN KEY (source_improvement_id) REFERENCES ai_agent_improvements(id) ON DELETE SET NULL,
  CONSTRAINT ai_behavior_user_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_review_runs (
  id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, run_by VARCHAR(64) NOT NULL, run_mode ENUM('manual','automatic') NOT NULL DEFAULT 'manual',
  event_count INT NOT NULL DEFAULT 0, cluster_count INT NOT NULL DEFAULT 0, candidate_count INT NOT NULL DEFAULT 0, status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
  summary_json LONGTEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY ai_review_company_created_idx (company_id,created_at),
  CONSTRAINT ai_review_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE, CONSTRAINT ai_review_user_fk FOREIGN KEY (run_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema 34 — Ask Tegh durable conversation memory, validated plans, provenance and operation recovery.
CREATE TABLE IF NOT EXISTS ai_agent_conversations (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  status ENUM('active','suspended','completed','cancelled') NOT NULL DEFAULT 'active',
  bookkeeping_mode ENUM('guided','full') NOT NULL DEFAULT 'guided',
  active_task_id VARCHAR(64) NULL,
  last_intent VARCHAR(500) NOT NULL DEFAULT '',
  summary_json LONGTEXT NOT NULL,
  verified_context_json LONGTEXT NOT NULL,
  revision INT NOT NULL DEFAULT 1,
  expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ai_conversation_company_user_idx (company_id,user_id,status,updated_at),
  KEY ai_conversation_expiry_idx (expires_at),
  CONSTRAINT ai_conversation_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_conversation_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_memories (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NULL,
  memory_type ENUM('working','conversation','user_preference','company_semantic','episodic','procedural','system_improvement') NOT NULL,
  scope_type ENUM('company','user','bank_account','customer','vendor','description','workflow','system') NOT NULL DEFAULT 'company',
  scope_key VARCHAR(128) NOT NULL DEFAULT '',
  normalized_key VARCHAR(180) NOT NULL,
  value_json LONGTEXT NOT NULL,
  source ENUM('explicit','observed','inferred','system') NOT NULL DEFAULT 'observed',
  evidence_count INT NOT NULL DEFAULT 1,
  evidence_json LONGTEXT NOT NULL,
  confidence_bps INT NOT NULL DEFAULT 0,
  status ENUM('enabled','conflict','disabled','retired') NOT NULL DEFAULT 'enabled',
  reason VARCHAR(500) NOT NULL DEFAULT '',
  version INT NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  review_after DATETIME NULL,
  expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ai_memory_company_type_idx (company_id,memory_type,status,confidence_bps),
  KEY ai_memory_company_key_idx (company_id,normalized_key(64),scope_type,scope_key(32)),
  KEY ai_memory_user_idx (company_id,user_id,memory_type,status),
  CONSTRAINT ai_memory_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_memory_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_memory_history (
  id VARCHAR(64) PRIMARY KEY,
  memory_id VARCHAR(64) NOT NULL,
  company_id VARCHAR(64) NOT NULL,
  changed_by VARCHAR(64) NULL,
  change_type ENUM('created','updated','corrected','conflict','enabled','disabled','retired','forgotten','decayed') NOT NULL,
  before_json LONGTEXT NULL,
  after_json LONGTEXT NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ai_memory_history_memory_idx (memory_id,created_at),
  KEY ai_memory_history_company_idx (company_id,created_at),
  CONSTRAINT ai_memory_history_memory_fk FOREIGN KEY (memory_id) REFERENCES ai_agent_memories(id) ON DELETE CASCADE,
  CONSTRAINT ai_memory_history_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_memory_history_user_fk FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_agent_plans (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  conversation_id VARCHAR(64) NOT NULL,
  task_id VARCHAR(64) NULL,
  user_goal VARCHAR(500) NOT NULL,
  risk_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  confidence_bps INT NOT NULL DEFAULT 0,
  plan_json LONGTEXT NOT NULL,
  plan_hash CHAR(64) NOT NULL,
  status ENUM('proposed','waiting_input','waiting_confirmation','executing','completed','failed','cancelled','superseded','needs_review') NOT NULL DEFAULT 'proposed',
  requires_confirmation TINYINT(1) NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ai_plan_company_user_idx (company_id,user_id,status,updated_at),
  KEY ai_plan_conversation_idx (conversation_id,created_at),
  KEY ai_plan_expiry_idx (expires_at),
  CONSTRAINT ai_plan_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT ai_plan_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT ai_plan_conversation_fk FOREIGN KEY (conversation_id) REFERENCES ai_agent_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema 35 — company-scoped Native Agent policies, bounded scan runs and durable findings.
CREATE TABLE IF NOT EXISTS native_agent_policies (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  supervisor_enabled TINYINT(1) NOT NULL DEFAULT 1,
  bookkeeping_enabled TINYINT(1) NOT NULL DEFAULT 1,
  reconciliation_enabled TINYINT(1) NOT NULL DEFAULT 1,
  close_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ap_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ar_enabled TINYINT(1) NOT NULL DEFAULT 1,
  financial_analyst_enabled TINYINT(1) NOT NULL DEFAULT 1,
  payroll_tax_agent_enabled TINYINT(1) NOT NULL DEFAULT 1,
  cadence ENUM('manual','daily','weekly') NOT NULL DEFAULT 'manual',
  opportunistic_enabled TINYINT(1) NOT NULL DEFAULT 1,
  materiality_cents BIGINT NOT NULL DEFAULT 10000,
  stale_days INT NOT NULL DEFAULT 30,
  confidence_review_bps INT NOT NULL DEFAULT 8000,
  notification_min_severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  connected_enabled TINYINT(1) NOT NULL DEFAULT 0,
  thresholds_json LONGTEXT NOT NULL,
  revision INT NOT NULL DEFAULT 1,
  policy_hash CHAR(64) NOT NULL,
  created_by VARCHAR(64) NULL,
  updated_by VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY native_agent_policy_company_uq (company_id),
  KEY native_agent_policy_updated_idx (updated_at),
  CONSTRAINT native_agent_policy_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT native_agent_policy_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_policy_updated_user_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_policy_thresholds_ck CHECK (materiality_cents >= 0 AND stale_days BETWEEN 1 AND 3650 AND confidence_review_bps BETWEEN 0 AND 10000 AND revision >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema 36 — private document-intake metadata and explicit collections workflow.
-- Source files live outside the public web root. Raw OCR text is never stored.
CREATE TABLE IF NOT EXISTS native_agent_documents (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  document_type ENUM('vendor_bill','customer_invoice') NOT NULL,
  original_name VARCHAR(240) NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_extension VARCHAR(10) NOT NULL,
  size_bytes BIGINT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  state ENUM('uploaded','extracting','review','ready','prepared','linked','dismissed','stale','failed') NOT NULL DEFAULT 'uploaded',
  extraction_method ENUM('none','pdf_text','ocr','manual') NOT NULL DEFAULT 'none',
  candidate_json LONGTEXT NOT NULL,
  candidate_hash CHAR(64) NOT NULL,
  source_revision_hash CHAR(64) NOT NULL,
  proposed_action_id VARCHAR(120) NOT NULL,
  linked_entity_type ENUM('bill','invoice') NULL,
  linked_entity_id VARCHAR(64) NULL,
  uploaded_by VARCHAR(64) NOT NULL,
  reviewed_by VARCHAR(64) NULL,
  reviewed_at DATETIME NULL,
  prepared_task_id VARCHAR(64) NULL,
  prepared_by VARCHAR(64) NULL,
  prepared_at DATETIME NULL,
  linked_by VARCHAR(64) NULL,
  linked_at DATETIME NULL,
  dismissed_by VARCHAR(64) NULL,
  dismissed_at DATETIME NULL,
  failure_code VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY native_agent_document_company_sha_type_uq (company_id,sha256,document_type),
  UNIQUE KEY native_agent_documents_company_id_uq (company_id,id),
  KEY native_agent_document_company_state_idx (company_id,state,updated_at),
  KEY native_agent_document_link_idx (company_id,linked_entity_type,linked_entity_id),
  KEY native_agent_document_task_idx (prepared_task_id),
  CONSTRAINT native_agent_document_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT native_agent_document_uploaded_user_fk FOREIGN KEY (uploaded_by) REFERENCES users(id),
  CONSTRAINT native_agent_document_reviewed_user_fk FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_document_prepared_task_fk FOREIGN KEY (prepared_task_id) REFERENCES ai_agent_tasks(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_document_prepared_user_fk FOREIGN KEY (prepared_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_document_linked_user_fk FOREIGN KEY (linked_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_document_dismissed_user_fk FOREIGN KEY (dismissed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_document_size_ck CHECK (size_bytes > 0 AND size_bytes <= 10485760)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS native_agent_collection_drafts (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  invoice_id VARCHAR(64) NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  level ENUM('friendly','firm','final') NOT NULL DEFAULT 'friendly',
  message_length ENUM('concise','standard','detailed') NOT NULL DEFAULT 'standard',
  channel ENUM('email') NOT NULL DEFAULT 'email',
  recipient_email VARCHAR(254) NOT NULL,
  subject VARCHAR(240) NOT NULL,
  body_text TEXT NOT NULL,
  source_revision_hash CHAR(64) NOT NULL,
  content_hash CHAR(64) NOT NULL,
  template_id VARCHAR(64) NULL,
  template_revision INT NULL,
  template_hash CHAR(64) NULL,
  template_snapshot_json LONGTEXT NULL,
  source_facts_json LONGTEXT NULL,
  state ENUM('draft','approved','sending','sent','failed','stale','dismissed') NOT NULL DEFAULT 'draft',
  created_by VARCHAR(64) NOT NULL,
  updated_by VARCHAR(64) NOT NULL,
  approved_by VARCHAR(64) NULL,
  approved_at DATETIME NULL,
  sending_started_at DATETIME NULL,
  sent_at DATETIME NULL,
  failed_at DATETIME NULL,
  failure_message VARCHAR(500) NULL,
  outbound_email_id VARCHAR(64) NULL,
  delivery_attempt_id VARCHAR(64) NULL,
  delivery_outcome ENUM('none','accepted','failed','deferred','manual_review','diagnostic_warning') NOT NULL DEFAULT 'none',
  recovery_status ENUM('none','confirmed_sent_external','confirmed_not_received') NOT NULL DEFAULT 'none',
  recovery_reason VARCHAR(500) NULL,
  recovered_by VARCHAR(64) NULL,
  recovered_at DATETIME NULL,
  followup_id VARCHAR(64) NULL,
  dismissed_by VARCHAR(64) NULL,
  dismissed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY native_agent_collection_company_fingerprint_uq (company_id,fingerprint),
  KEY native_agent_collection_company_state_idx (company_id,state,updated_at),
  KEY native_agent_collection_invoice_idx (company_id,invoice_id,state),
  KEY native_agent_collection_mail_idx (outbound_email_id),
  KEY native_agent_collection_attempt_idx (delivery_attempt_id),
  CONSTRAINT native_agent_collection_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT native_agent_collection_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT native_agent_collection_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT native_agent_collection_updated_user_fk FOREIGN KEY (updated_by) REFERENCES users(id),
  CONSTRAINT native_agent_collection_approved_user_fk FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_collection_outbound_email_fk FOREIGN KEY (outbound_email_id) REFERENCES outbound_emails(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_collection_followup_fk FOREIGN KEY (followup_id) REFERENCES invoice_followups(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_collection_dismissed_user_fk FOREIGN KEY (dismissed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_collection_recovered_user_fk FOREIGN KEY (recovered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema 39 — append-oriented delivery evidence, revisioned collection copy,
-- and signed Month-End review attestations. These structures never post to the
-- ledger, send automatically, or lock a period.
CREATE TABLE IF NOT EXISTS outbound_email_attempts (
  id VARCHAR(64) PRIMARY KEY,
  outbound_email_id VARCHAR(64) NOT NULL,
  collection_draft_id VARCHAR(64) NULL,
  company_id VARCHAR(64) NULL,
  initiated_by VARCHAR(64) NULL,
  attempt_number INT NOT NULL,
  operation_key CHAR(64) NOT NULL,
  stage ENUM('connection','greeting','ehlo','starttls','authentication','mail_from','rcpt_to','data_initialization','final_data_acceptance','quit','sendmail') NOT NULL DEFAULT 'connection',
  smtp_reply_code INT NULL,
  enhanced_code VARCHAR(20) NULL,
  provider_diagnostic VARCHAR(500) NOT NULL DEFAULT '',
  outcome ENUM('accepted','definitive_preaccept_failure','temporary_preaccept_failure','ambiguous_after_submission','diagnostic_failure_after_acceptance') NULL,
  acceptance_certainty ENUM('yes','no','unknown') NOT NULL DEFAULT 'unknown',
  started_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY outbound_email_attempt_operation_uq (operation_key),
  UNIQUE KEY outbound_email_attempt_number_uq (outbound_email_id,attempt_number),
  KEY outbound_email_attempt_company_outcome_idx (company_id,outcome,started_at),
  KEY outbound_email_attempt_collection_idx (collection_draft_id,started_at),
  CONSTRAINT outbound_email_attempt_mail_fk FOREIGN KEY (outbound_email_id) REFERENCES outbound_emails(id) ON DELETE CASCADE,
  CONSTRAINT outbound_email_attempt_collection_fk FOREIGN KEY (collection_draft_id) REFERENCES native_agent_collection_drafts(id) ON DELETE SET NULL,
  CONSTRAINT outbound_email_attempt_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  CONSTRAINT outbound_email_attempt_user_fk FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT outbound_email_attempt_number_ck CHECK (attempt_number >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collection_message_templates (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  tone ENUM('friendly','firm','final') NOT NULL,
  message_length ENUM('concise','standard','detailed') NOT NULL DEFAULT 'standard',
  subject_template VARCHAR(240) NOT NULL,
  body_template TEXT NOT NULL,
  signature_block VARCHAR(1000) NOT NULL DEFAULT '',
  payment_instructions VARCHAR(1000) NOT NULL DEFAULT '',
  revision INT NOT NULL,
  template_hash CHAR(64) NOT NULL,
  change_note VARCHAR(500) NOT NULL DEFAULT '',
  created_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY collection_template_company_tone_length_revision_uq (company_id,tone,message_length,revision),
  KEY collection_template_company_current_idx (company_id,tone,message_length,created_at),
  CONSTRAINT collection_template_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT collection_template_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT collection_template_revision_ck CHECK (revision >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS month_end_attestations (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  evidence_hash CHAR(64) NOT NULL,
  evidence_revision VARCHAR(120) NOT NULL,
  attestation_version INT NOT NULL DEFAULT 1,
  attestation_text VARCHAR(500) NOT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  attested_by VARCHAR(64) NOT NULL,
  attested_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY month_end_attestation_identity_uq (company_id,period_start,evidence_hash,attested_by,attestation_version),
  KEY month_end_attestation_company_period_idx (company_id,period_start,period_end,attested_at),
  CONSTRAINT month_end_attestation_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT month_end_attestation_user_fk FOREIGN KEY (attested_by) REFERENCES users(id),
  CONSTRAINT month_end_attestation_period_ck CHECK (period_start <= period_end AND attestation_version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema 37 — saved Financial Analyst assumptions. Forecast results are
-- recomputed from posted books, open items and existing budgets; they are not
-- duplicated into planning tables.
CREATE TABLE IF NOT EXISTS financial_scenarios (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(1000) NOT NULL DEFAULT '',
  horizon_start DATE NOT NULL,
  horizon_end DATE NOT NULL,
  base_kind ENUM('open_items','budget') NOT NULL DEFAULT 'open_items',
  source_budget_id VARCHAR(64) NULL,
  status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  assumptions_json LONGTEXT NOT NULL,
  revision INT NOT NULL DEFAULT 1,
  scenario_hash CHAR(64) NOT NULL,
  created_by VARCHAR(64) NOT NULL,
  updated_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY financial_scenario_company_state_idx (company_id,status,updated_at),
  KEY financial_scenario_budget_idx (company_id,source_budget_id),
  CONSTRAINT financial_scenario_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT financial_scenario_budget_fk FOREIGN KEY (source_budget_id) REFERENCES budgets(id) ON DELETE RESTRICT,
  CONSTRAINT financial_scenario_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT financial_scenario_updated_user_fk FOREIGN KEY (updated_by) REFERENCES users(id),
  CONSTRAINT financial_scenario_horizon_ck CHECK (horizon_start <= horizon_end AND DATEDIFF(horizon_end,horizon_start) BETWEEN 0 AND 365),
  CONSTRAINT financial_scenario_revision_ck CHECK (revision >= 1),
  CONSTRAINT financial_scenario_budget_base_ck CHECK ((base_kind='budget' AND source_budget_id IS NOT NULL) OR (base_kind='open_items' AND source_budget_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_scenario_adjustments (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  scenario_id VARCHAR(64) NOT NULL,
  adjustment_date DATE NOT NULL,
  direction ENUM('inflow','outflow') NOT NULL,
  amount_cents BIGINT NOT NULL,
  activity ENUM('operating','investing','financing') NOT NULL DEFAULT 'operating',
  description VARCHAR(500) NOT NULL,
  probability_bps INT NOT NULL DEFAULT 10000,
  created_by VARCHAR(64) NOT NULL,
  updated_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY financial_adjustment_company_scenario_date_idx (company_id,scenario_id,adjustment_date),
  CONSTRAINT financial_adjustment_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT financial_adjustment_scenario_fk FOREIGN KEY (scenario_id) REFERENCES financial_scenarios(id) ON DELETE CASCADE,
  CONSTRAINT financial_adjustment_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT financial_adjustment_updated_user_fk FOREIGN KEY (updated_by) REFERENCES users(id),
  CONSTRAINT financial_adjustment_amount_ck CHECK (amount_cents > 0),
  CONSTRAINT financial_adjustment_probability_ck CHECK (probability_bps BETWEEN 0 AND 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema 38 — Payroll/Tax Agent and deny-by-default workflow-metadata
-- autonomy. These tables never store payroll calculations, tax returns,
-- filings, bank payments or journal data.
CREATE TABLE IF NOT EXISTS native_agent_autonomy_policies (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  action_id VARCHAR(120) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  max_severity ENUM('info','warning') NOT NULL DEFAULT 'info',
  daily_limit INT NOT NULL DEFAULT 0,
  cooldown_minutes INT NOT NULL DEFAULT 60,
  max_snooze_days INT NOT NULL DEFAULT 7,
  constraints_json LONGTEXT NOT NULL,
  revision INT NOT NULL DEFAULT 1,
  policy_hash CHAR(64) NOT NULL,
  suspended_at DATETIME NULL,
  suspended_by VARCHAR(64) NULL,
  suspension_reason VARCHAR(500) NOT NULL DEFAULT '',
  created_by VARCHAR(64) NOT NULL,
  updated_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY native_agent_autonomy_company_action_uq (company_id,action_id),
  KEY native_agent_autonomy_company_enabled_idx (company_id,enabled,suspended_at),
  CONSTRAINT native_agent_autonomy_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT native_agent_autonomy_created_user_fk FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT native_agent_autonomy_updated_user_fk FOREIGN KEY (updated_by) REFERENCES users(id),
  CONSTRAINT native_agent_autonomy_suspended_user_fk FOREIGN KEY (suspended_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_autonomy_limits_ck CHECK (daily_limit BETWEEN 0 AND 100 AND cooldown_minutes BETWEEN 1 AND 10080 AND max_snooze_days BETWEEN 1 AND 30 AND revision >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS native_agent_runs (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  agent_type VARCHAR(40) NOT NULL,
  trigger_type ENUM('manual','scheduled','opportunistic') NOT NULL DEFAULT 'manual',
  initiated_by VARCHAR(64) NULL,
  status ENUM('running','completed','partial','failed','skipped','interrupted') NOT NULL DEFAULT 'running',
  idempotency_key CHAR(64) NOT NULL,
  policy_revision INT NOT NULL,
  policy_hash CHAR(64) NOT NULL,
  source_revision_hash CHAR(64) NOT NULL,
  lease_owner VARCHAR(64) NOT NULL,
  lease_expires_at DATETIME NOT NULL,
  lease_renewed_at DATETIME NOT NULL,
  cursor_json LONGTEXT NOT NULL,
  summary_json LONGTEXT NOT NULL,
  processed_count INT NOT NULL DEFAULT 0,
  finding_count INT NOT NULL DEFAULT 0,
  notification_count INT NOT NULL DEFAULT 0,
  error_code VARCHAR(80) NULL,
  error_message VARCHAR(500) NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY native_agent_run_identity_uq (company_id,agent_type,idempotency_key),
  KEY native_agent_run_company_status_idx (company_id,status,started_at),
  KEY native_agent_run_lease_idx (status,lease_expires_at),
  CONSTRAINT native_agent_run_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT native_agent_run_user_fk FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_run_counts_ck CHECK (processed_count >= 0 AND finding_count >= 0 AND notification_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS native_agent_leases (
  lease_name VARCHAR(80) PRIMARY KEY,
  lease_owner VARCHAR(64) NOT NULL,
  lease_expires_at DATETIME NOT NULL,
  lease_renewed_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY native_agent_leases_expiry_idx (lease_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS native_agent_run_failures (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS native_agent_findings (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  last_seen_run_id VARCHAR(64) NULL,
  agent_type VARCHAR(40) NOT NULL,
  finding_type VARCHAR(80) NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  confidence_bps INT NOT NULL DEFAULT 10000,
  title VARCHAR(220) NOT NULL,
  explanation VARCHAR(1200) NOT NULL,
  evidence_json LONGTEXT NOT NULL,
  evidence_hash CHAR(64) NOT NULL,
  source_revision_hash CHAR(64) NOT NULL,
  source_date DATETIME NULL,
  policy_revision INT NOT NULL,
  affected_count INT NOT NULL DEFAULT 1,
  proposed_action_id VARCHAR(120) NULL,
  proposed_inputs_json LONGTEXT NOT NULL,
  execution_class ENUM('immediate','prepared','authorized') NOT NULL DEFAULT 'immediate',
  state ENUM('open','snoozed','dismissed','resolved','superseded') NOT NULL DEFAULT 'open',
  assigned_user_id VARCHAR(64) NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  snoozed_until DATETIME NULL,
  snoozed_by VARCHAR(64) NULL,
  dismissed_at DATETIME NULL,
  dismissed_by VARCHAR(64) NULL,
  accepted_task_id VARCHAR(64) NULL,
  accepted_at DATETIME NULL,
  accepted_by VARCHAR(64) NULL,
  resolved_at DATETIME NULL,
  resolved_by VARCHAR(64) NULL,
  resolution_note VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY native_agent_finding_company_fingerprint_uq (company_id,fingerprint),
  KEY native_agent_finding_company_state_idx (company_id,state,severity,last_seen_at),
  KEY native_agent_finding_agent_state_idx (company_id,agent_type,state,last_seen_at),
  KEY native_agent_finding_assignee_idx (company_id,assigned_user_id,state),
  KEY native_agent_finding_run_idx (last_seen_run_id),
  CONSTRAINT native_agent_finding_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT native_agent_finding_run_fk FOREIGN KEY (last_seen_run_id) REFERENCES native_agent_runs(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_finding_assignee_fk FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_finding_snooze_user_fk FOREIGN KEY (snoozed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_finding_dismiss_user_fk FOREIGN KEY (dismissed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_finding_accepted_task_fk FOREIGN KEY (accepted_task_id) REFERENCES ai_agent_tasks(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_finding_accepted_user_fk FOREIGN KEY (accepted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_finding_resolve_user_fk FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_finding_confidence_ck CHECK (confidence_bps BETWEEN 0 AND 10000 AND affected_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS native_agent_autonomy_runs (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  policy_id VARCHAR(64) NOT NULL,
  finding_id VARCHAR(64) NULL,
  requested_by VARCHAR(64) NULL,
  action_id VARCHAR(120) NOT NULL,
  idempotency_key CHAR(64) NOT NULL,
  policy_revision INT NOT NULL,
  policy_hash CHAR(64) NOT NULL,
  source_revision_hash CHAR(64) NOT NULL,
  status ENUM('running','completed','denied','needs_review','failed') NOT NULL DEFAULT 'running',
  result_json LONGTEXT NOT NULL,
  error_code VARCHAR(80) NULL,
  error_message VARCHAR(500) NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY native_agent_autonomy_run_identity_uq (company_id,idempotency_key),
  KEY native_agent_autonomy_run_company_status_idx (company_id,status,started_at),
  KEY native_agent_autonomy_run_policy_idx (policy_id,started_at),
  KEY native_agent_autonomy_run_finding_idx (finding_id,started_at),
  CONSTRAINT native_agent_autonomy_run_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT native_agent_autonomy_run_policy_fk FOREIGN KEY (policy_id) REFERENCES native_agent_autonomy_policies(id) ON DELETE RESTRICT,
  CONSTRAINT native_agent_autonomy_run_finding_fk FOREIGN KEY (finding_id) REFERENCES native_agent_findings(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_autonomy_run_user_fk FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT native_agent_autonomy_run_policy_revision_ck CHECK (policy_revision >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema 40 — stable feature catalogue and entitlement authority. Prices,
-- billing cycles and payment-provider state are intentionally absent.
CREATE TABLE IF NOT EXISTS feature_catalog (
  feature_key VARCHAR(120) NOT NULL PRIMARY KEY,
  display_name VARCHAR(180) NOT NULL,
  description VARCHAR(1000) NOT NULL,
  kind ENUM('module','capability','ui_option') NOT NULL,
  permitted_scope ENUM('account','company','company_user') NOT NULL,
  default_decision ENUM('enabled','disabled') NOT NULL DEFAULT 'disabled',
  operational_state ENUM('active','suspended','retired') NOT NULL DEFAULT 'active',
  billable TINYINT(1) NOT NULL DEFAULT 0,
  metered TINYINT(1) NOT NULL DEFAULT 0,
  display_order INT NOT NULL DEFAULT 0,
  metadata_json LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY feature_catalog_state_order_idx (operational_state,display_order,feature_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feature_dependencies (
  feature_key VARCHAR(120) NOT NULL,
  related_feature_key VARCHAR(120) NOT NULL,
  relation_type ENUM('requires','conflicts') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (feature_key,related_feature_key,relation_type),
  CONSTRAINT feature_dependency_feature_fk FOREIGN KEY (feature_key) REFERENCES feature_catalog(feature_key),
  CONSTRAINT feature_dependency_related_fk FOREIGN KEY (related_feature_key) REFERENCES feature_catalog(feature_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entitlement_subjects (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  subject_type ENUM('account','company','company_user') NOT NULL,
  account_user_id VARCHAR(64) NULL,
  company_id VARCHAR(64) NULL,
  user_id VARCHAR(64) NULL,
  identity_key CHAR(64) NOT NULL,
  subject_label VARCHAR(240) NOT NULL DEFAULT '',
  retired_at DATETIME NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feature_entitlements (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  subject_id VARCHAR(64) NOT NULL,
  feature_key VARCHAR(120) NOT NULL,
  decision ENUM('enabled','disabled') NOT NULL,
  source ENUM('migration','signup','request','platform_owner','trial','subscription','system') NOT NULL,
  valid_from DATETIME NULL,
  valid_until DATETIME NULL,
  revision BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(1000) NOT NULL DEFAULT '',
  actor_user_id VARCHAR(64) NULL,
  request_reference VARCHAR(80) NOT NULL,
  operation_key VARCHAR(120) NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feature_entitlement_history (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  entitlement_id VARCHAR(64) NOT NULL,
  subject_id VARCHAR(64) NOT NULL,
  feature_key VARCHAR(120) NOT NULL,
  entitlement_revision BIGINT UNSIGNED NOT NULL,
  before_json LONGTEXT NOT NULL,
  after_json LONGTEXT NOT NULL,
  actor_user_id VARCHAR(64) NULL,
  source VARCHAR(40) NOT NULL,
  request_reference VARCHAR(80) NOT NULL,
  source_reference VARCHAR(120) NULL,
  operation_key VARCHAR(120) NULL,
  reason VARCHAR(1000) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  user_agent_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY feature_entitlement_history_revision_uq (entitlement_id,entitlement_revision),
  UNIQUE KEY feature_entitlement_history_operation_uq (operation_key),
  KEY feature_entitlement_history_subject_idx (subject_id,feature_key,created_at),
  CONSTRAINT feature_entitlement_history_entitlement_fk FOREIGN KEY (entitlement_id) REFERENCES feature_entitlements(id),
  CONSTRAINT feature_entitlement_history_subject_fk FOREIGN KEY (subject_id) REFERENCES entitlement_subjects(id),
  CONSTRAINT feature_entitlement_history_feature_fk FOREIGN KEY (feature_key) REFERENCES feature_catalog(feature_key),
  CONSTRAINT feature_entitlement_history_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entitlement_requests (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  requester_user_id VARCHAR(64) NOT NULL,
  company_id VARCHAR(64) NULL,
  target_scope ENUM('account','company','company_user') NOT NULL,
  target_user_id VARCHAR(64) NULL,
  message VARCHAR(1000) NOT NULL DEFAULT '',
  state ENUM('pending','approved','declined','cancelled','fulfilled') NOT NULL DEFAULT 'pending',
  endorsement_state ENUM('not_required','required','endorsed','rejected') NOT NULL DEFAULT 'not_required',
  endorsed_by VARCHAR(64) NULL,
  reviewed_by VARCHAR(64) NULL,
  decision_reason VARCHAR(1000) NULL,
  fulfilled_revision BIGINT UNSIGNED NULL,
  deduplication_key CHAR(64) NOT NULL,
  pending_dedup_key CHAR(64) NULL,
  request_reference VARCHAR(80) NOT NULL,
  retired_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entitlement_request_items (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  request_id VARCHAR(64) NOT NULL,
  feature_key VARCHAR(120) NOT NULL,
  requested_decision ENUM('enabled','disabled') NOT NULL,
  item_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY entitlement_request_item_feature_uq (request_id,feature_key),
  CONSTRAINT entitlement_request_item_request_fk FOREIGN KEY (request_id) REFERENCES entitlement_requests(id) ON DELETE CASCADE,
  CONSTRAINT entitlement_request_item_feature_fk FOREIGN KEY (feature_key) REFERENCES feature_catalog(feature_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entitlement_revisions (
  subject_id VARCHAR(64) NOT NULL PRIMARY KEY,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT entitlement_revision_subject_fk FOREIGN KEY (subject_id) REFERENCES entitlement_subjects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feature_usage_daily (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id VARCHAR(64) NULL,
  feature_key VARCHAR(120) NOT NULL,
  usage_date DATE NOT NULL,
  success_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  failure_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  unit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY feature_usage_company_feature_day_uq (company_id,feature_key,usage_date),
  CONSTRAINT feature_usage_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  CONSTRAINT feature_usage_feature_fk FOREIGN KEY (feature_key) REFERENCES feature_catalog(feature_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signup_feature_intents (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  user_id VARCHAR(64) NOT NULL,
  feature_key VARCHAR(120) NOT NULL,
  state ENUM('pending','attached','cancelled') NOT NULL DEFAULT 'pending',
  company_id VARCHAR(64) NULL,
  attached_request_id VARCHAR(64) NULL,
  request_reference VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  attached_at DATETIME NULL,
  UNIQUE KEY signup_feature_intent_user_feature_uq (user_id,feature_key,state),
  KEY signup_feature_intent_company_idx (company_id,state),
  CONSTRAINT signup_feature_intent_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT signup_feature_intent_feature_fk FOREIGN KEY (feature_key) REFERENCES feature_catalog(feature_key),
  CONSTRAINT signup_feature_intent_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  CONSTRAINT signup_feature_intent_request_fk FOREIGN KEY (attached_request_id) REFERENCES entitlement_requests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO feature_catalog
  (feature_key,display_name,description,kind,permitted_scope,default_decision,operational_state,billable,metered,display_order,metadata_json)
VALUES
  ('core.authentication','Authentication & Security','Secure sign-in, sessions and account protection.','capability','account','enabled','active',0,0,10,'{}'),
  ('core.accounting','Core Accounting','Company books, source documents, ledgers and financial statements.','module','company','enabled','active',0,0,20,'{}'),
  ('ui.navigation','Navigation Preferences','Top/side navigation and keyboard navigation preferences.','ui_option','company_user','enabled','active',0,0,30,'{}'),
  ('ui.accessibility','Accessibility Settings','Text size, reflow, reduced motion and accessible interaction settings.','ui_option','company_user','enabled','active',0,0,40,'{}'),
  ('ui.accounting_mode','Accounting Presentation Mode','Guided or Full Accounting presentation preference.','ui_option','company_user','enabled','active',0,0,50,'{}'),
  ('audit.basic','Audit & Data Protection','Basic audit history and data-protection controls.','capability','company','enabled','active',0,0,60,'{}'),
  ('banking.manual_reconciliation','Manual Reconciliation','Manual bank review, matching and statement reconciliation.','capability','company','enabled','active',0,0,70,'{}'),
  ('tegh.ask.base','Ask Tegh','Provider-optional help and deterministic accounting guidance.','capability','company','enabled','active',0,0,80,'{}'),
  ('module.payroll','Payroll','Employee records, draft Pay Runs, verification and controlled posting.','module','company','disabled','active',1,0,200,'{}'),
  ('module.document_intake','Document Intake / OCR','Private document intake and browser-local/native extraction.','module','company','disabled','active',1,1,210,'{}'),
  ('module.collections','Collections','Collection monitoring, drafts and delivery review.','module','company','disabled','active',1,1,220,'{}'),
  ('module.financial_analysis','Financial Analysis','Deterministic forecasts, scenarios and management analysis.','module','company','disabled','active',1,1,230,'{}'),
  ('tegh.ai.advanced','Tegh AI Advanced','Optional provider-backed advanced assistance with deterministic fallbacks.','capability','company','disabled','active',1,1,240,'{}'),
  ('tegh.command_centre','Tegh Command Centre','Structured commands, pins, history and prepared-work queue.','capability','company','disabled','active',1,1,250,'{}'),
  ('banking.reconciliation.advanced','Advanced Reconciliation Suggestions','Deterministic advanced proposal analysis and evidence.','capability','company','disabled','active',1,1,260,'{}'),
  ('banking.reconciliation.bulk_match','Bulk Matching','Review and confirm multiple eligible matches together.','capability','company','disabled','active',1,1,270,'{}'),
  ('banking.reconciliation.match_post','Authorized Match & Post','One-review, one-confirm atomic bank Match or Post & Match.','capability','company','disabled','active',1,1,280,'{}')
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),description=VALUES(description),kind=VALUES(kind),permitted_scope=VALUES(permitted_scope),default_decision=VALUES(default_decision),billable=VALUES(billable),metered=VALUES(metered),display_order=VALUES(display_order);

INSERT INTO feature_dependencies (feature_key,related_feature_key,relation_type) VALUES
  ('module.payroll','core.accounting','requires'),
  ('module.document_intake','core.accounting','requires'),
  ('module.collections','core.accounting','requires'),
  ('module.financial_analysis','core.accounting','requires'),
  ('tegh.ai.advanced','tegh.ask.base','requires'),
  ('tegh.command_centre','tegh.ask.base','requires'),
  ('banking.reconciliation.advanced','banking.manual_reconciliation','requires'),
  ('banking.reconciliation.bulk_match','banking.reconciliation.advanced','requires'),
  ('banking.reconciliation.match_post','banking.reconciliation.advanced','requires')
ON DUPLICATE KEY UPDATE relation_type=VALUES(relation_type);

-- Schema 41: privacy-minimized, reviewed vendor-recognition evidence. These
-- tables contain normalized hashes and safe display hints only; raw OCR text,
-- document contents and transaction values are deliberately excluded.
CREATE TABLE IF NOT EXISTS vendor_recognition_rules (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_recognition_history (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Schema 44 structures; data initialization is performed by protected migration.
CREATE TABLE IF NOT EXISTS platform_release_settings (
 setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
 value_json VARCHAR(20) NOT NULL, revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_by VARCHAR(64) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_release_setting_history (
 id VARCHAR(64) NOT NULL PRIMARY KEY, setting_key VARCHAR(120) NOT NULL,
 old_value VARCHAR(20) NOT NULL, new_value VARCHAR(20) NOT NULL, actor_user_id VARCHAR(64) NOT NULL,
 reason VARCHAR(1000) NOT NULL, request_reference VARCHAR(80) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY setting_history_key_time (setting_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_invitations (
 id VARCHAR(64) NOT NULL PRIMARY KEY, email VARCHAR(254) NOT NULL,
 scope VARCHAR(20) NOT NULL, assignment_count INT NOT NULL DEFAULT 0,
 invited_by VARCHAR(64) NOT NULL, operation_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'pending', accepted_by VARCHAR(64) NULL, accepted_at DATETIME NULL,
 expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY invitation_operation_uq (invited_by,operation_key), KEY invitation_email (email,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_invitation_companies (
 invitation_id VARCHAR(64) NOT NULL, company_id VARCHAR(64) NOT NULL,
 role VARCHAR(20) NOT NULL, PRIMARY KEY (invitation_id,company_id), KEY invitation_company (company_id),
 CONSTRAINT invitation_assignment_parent_fk FOREIGN KEY (invitation_id) REFERENCES account_invitations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_invitation_tokens (
 id VARCHAR(64) NOT NULL PRIMARY KEY, invitation_id VARCHAR(64) NOT NULL,
 token_hash CHAR(64) NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'active', expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY invitation_token_hash_uq (token_hash), KEY invitation_tokens_parent (invitation_id),
 CONSTRAINT invitation_token_parent_fk FOREIGN KEY (invitation_id) REFERENCES account_invitations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_invitation_mail (
 id VARCHAR(64) NOT NULL PRIMARY KEY, invitation_id VARCHAR(64) NOT NULL,
 token_id VARCHAR(64) NOT NULL, token_cipher LONGTEXT NOT NULL,
 operation_key VARCHAR(120) NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'deferred',
 outbound_email_id VARCHAR(64) NULL, diagnostic_json TEXT NULL, attempt_count INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY invitation_mail_operation_uq (invitation_id,operation_key),
 CONSTRAINT invitation_mail_parent_fk FOREIGN KEY (invitation_id) REFERENCES account_invitations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invitation_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 token_hash CHAR(64) NOT NULL, ip_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY invitation_attempt_token (token_hash,created_at), KEY invitation_attempt_ip (ip_hash,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS terms_acceptances (
 id VARCHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(64) NOT NULL,
 invitation_id VARCHAR(64) NULL, terms_version VARCHAR(40) NOT NULL, privacy_version VARCHAR(40) NOT NULL,
 request_reference VARCHAR(80) NOT NULL, accepted_at DATETIME NOT NULL,
 KEY terms_user_time (user_id,accepted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_bulk_operations (
 id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL,
 user_id VARCHAR(64) NOT NULL, operation_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 selected_count INT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY bank_bulk_operation_uq (company_id,user_id,operation_key),
 CONSTRAINT bank_bulk_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_bulk_operation_rows (
 operation_id VARCHAR(64) NOT NULL, transaction_id VARCHAR(64) NOT NULL,
 ordinal INT NOT NULL, decision_json TEXT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'pending',
 result_json TEXT NULL, attempts INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (operation_id,transaction_id), UNIQUE KEY bank_bulk_ordinal_uq (operation_id,ordinal),
 CONSTRAINT bank_bulk_row_parent_fk FOREIGN KEY (operation_id) REFERENCES bank_bulk_operations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_category_commits (
 id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL,
 user_id VARCHAR(64) NOT NULL, import_type VARCHAR(64) NOT NULL DEFAULT 'bank_transaction_categories',
 operation_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, preview_hash CHAR(64) NOT NULL,
 result_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY bank_category_operation_uq (company_id,user_id,operation_key),
 CONSTRAINT bank_category_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS release_data_retirements (
 source_type VARCHAR(80) NOT NULL, source_id VARCHAR(64) NOT NULL,
 reason VARCHAR(500) NOT NULL, retired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (source_type,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_transaction_source_text (
 transaction_id VARCHAR(64) NOT NULL PRIMARY KEY, company_id VARCHAR(64) NOT NULL,
 source_fingerprint CHAR(64) NOT NULL, full_description MEDIUMTEXT NOT NULL,
 evidence_version INT NOT NULL DEFAULT 1,
 KEY bank_source_company (company_id),
 CONSTRAINT bank_source_transaction_fk FOREIGN KEY (transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE,
 CONSTRAINT bank_source_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Schema 46: invoice document operations (R20)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema 46 additive R67: linked customer/vendor debit and credit notes.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
