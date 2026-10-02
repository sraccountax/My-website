-- Schema 44 / 5980: additive structures only. Run through protected startup/migrate.
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
