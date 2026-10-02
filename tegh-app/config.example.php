<?php
declare(strict_types=1);

/**
 * Tegh private configuration template.
 *
 * Copy this file to a directory OUTSIDE the public web root as:
 *   ../sr-accountax-private/config.php
 * or point SR_ACCOUNTAX_CONFIG at the private absolute path.
 *
 * Never package or commit the completed private configuration. Tegh reads the
 * OpenAI key on the server only and never returns it to the browser.
 */
return [
    'app' => [
        'base_url' => 'https://YOUR-TEGH-HOST.example',
        'secret' => 'REPLACE_WITH_AT_LEAST_48_RANDOM_CHARACTERS',
        'setup_key' => 'REPLACE_WITH_A_SEPARATE_ONE_TIME_SETUP_KEY',
        'session_cookie' => 'tegh_session',
        'session_hours' => 12,
        'public_signup_enabled' => false,
        // Keep false during normal operation. The protected Schema 44 upgrader
        // creates and removes its own private maintenance marker.
        'maintenance_mode' => false,
        'maintenance_flag_path' => '',
        'maintenance_stale_after_seconds' => 3600,
    ],
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4',
        'user' => 'YOUR_DATABASE_USER',
        'password' => 'YOUR_DATABASE_PASSWORD',
    ],
    'storage_path' => __DIR__ . '/storage',
    'incident_log_path' => __DIR__ . '/system-incidents.ndjson',
    'mail' => [
        'from_email' => 'no-reply@YOUR-TEGH-HOST.example',
        'from_name' => 'Tegh',
        'reply_to' => '',
        'marketing_to' => '',
        'smtp_host' => '',
        'smtp_port' => 587,
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_encryption' => 'tls',
        'sendmail_fallback' => false,
    ],
    'captcha' => [
        'enabled' => false,
        'provider' => 'turnstile',
        'site_key' => '',
        'secret_key' => '',
        'allowed_hostnames' => ['YOUR-TEGH-HOST.example'],
        'registration_required' => true,
        'login_mode' => 'adaptive',
        'login_failure_threshold' => 4,
        'login_email_failure_threshold' => 4,
        'login_ip_failure_threshold' => 12,
        'login_hard_pair_limit' => 20,
        'login_hard_ip_failure_limit' => 60,
        'login_hard_ip_total_limit' => 120,
    ],
    // Dormant configuration only. Build 5980 hard-disables Connected Intelligence
    // before API-key access or any provider/budget/network operation.
    'openai' => [
        // Keep this blank in templates. Set the real key only in the private
        // copy, or use SR_ACCOUNTAX_OPENAI_API_KEY on the host.
        'api_key' => '',

        // Leave the base model blank to use the role-specific economical
        // defaults. Interpretation is the frequent, latency-sensitive path.
        'model' => '',
        'interpretation_model' => 'gpt-5.6-luna',
        'analysis_model' => 'gpt-5.6-terra',
        'complex_reasoning_model' => 'gpt-5.6-sol',
        'planner_model' => 'gpt-5.6-luna',
        'accounting_model' => 'gpt-5.6-sol',
        'guide_model' => 'gpt-5.6-terra',
        'interpretation_reasoning_effort' => 'low',
        'analysis_reasoning_effort' => 'medium',
        'planner_reasoning_effort' => 'low',
        'accounting_reasoning_effort' => 'high',
        'guide_reasoning_effort' => 'medium',
        'reasoning_effort' => 'low',
        'timeout_seconds' => 45,
        'company_hourly_request_limit' => 120,
        'company_hourly_token_limit' => 250000,
        'connected_confidence_threshold' => 0.82,
        'connected_confidence_margin' => 0.08,
        'native_confidence_threshold' => 0.90,
        'native_confidence_margin' => 0.08,
        'research_enabled' => false,
        'research_mode' => 'standard',
        'research_hourly_limit' => 30,
    ],
    'ai' => [
        'native_ai_enabled' => true,
        'native_ai_embeddings_enabled' => true,
        'native_ai_learning_enabled' => true,
        'native_ai_reconciliation_enabled' => true,
        'journal_step_up_threshold_cents' => 1000000,
        'confirmation_windows_minutes' => [
            'bank.transactions.delete' => 5,
            'journal.post' => 5,
        ],
    ],
    'accounting' => [
        // $10,000 dual-control threshold and $5 reconciliation tolerance.
        'materiality_threshold_cents' => 1000000,
        'reconciliation_tolerance_cents' => 500,
    ],
    'native_agent' => [
        'scheduler_secret' => 'REPLACE_WITH_A_SEPARATE_RANDOM_SCHEDULER_SECRET',
    ],
    'qa_guardian' => [
        // Safe scans are read-only. Scheduled requests use a separate secret,
        // never the OpenAI key or the Native Agent scheduler secret.
        'enabled' => true,
        'scheduler_secret' => 'REPLACE_WITH_A_SEPARATE_RANDOM_QA_SECRET',
        'incident_lookback_hours' => 24,
        'synthetic_evidence_max_age_hours' => 168,
        'max_retained_runs' => 100,
        'model_review_enabled' => true,
        // GPT-5.6 Luna is the low-cost, fast default for this bounded review.
        'model' => 'gpt-5.6-luna',
        'reasoning_effort' => 'low',
    ],
    'knowledge' => [
        'manifest_path' => dirname(__DIR__) . '/knowledge/approved-sources-v1.json',
    ],
    'testing' => [
        'fail_after_authorized_bank_post' => false,
        'fail_after_payroll_recalculation_items' => false,
    ],
];
