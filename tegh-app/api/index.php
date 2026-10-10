<?php
declare(strict_types=1);

try {
    require_once __DIR__ . '/bootstrap.php';

    $route = trim((string)($_GET['route'] ?? ''), '/');
    if ($route === '' && isset($_SERVER['PATH_INFO'])) {
        $route = trim((string)$_SERVER['PATH_INFO'], '/');
    }

    // Health and authentication are intentionally dispatched before loading
    // accounting modules. A reporting/module problem must never hide sign-in.
    if ($route === 'health') {
        require_method('GET');
        json_response(['ok' => true, 'service' => 'Tegh', 'version' => SR_ACCOUNTAX_VERSION, 'build' => SR_ACCOUNTAX_BUILD, 'schemaVersion' => SR_ACCOUNTAX_SCHEMA_VERSION]);
    }

    // R156: operator details for the public Terms and Privacy pages (no sign-in needed).
    require_once __DIR__ . '/legal_r156.php';
    if ($route === 'public/operator') handle_public_operator();

    require_once __DIR__ . '/migrations.php';
    require_once __DIR__ . '/captcha.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/company_profile_r153.php';
    require_once __DIR__ . '/migration_recovery_v5600.php';
    require_once __DIR__ . '/entitlements_v5610.php';
    require_once __DIR__ . '/migration_schema41_v5600.php';
    require_once __DIR__ . '/migration_schema42_v5900.php';
    require_once __DIR__ . '/migration_schema43_v5930.php';
    require_once __DIR__ . '/startup_schema43_v5930.php';
    require_once __DIR__ . '/migration_schema44_v5980.php';
    require_once __DIR__ . '/migration_schema45_v5990.php';
    require_once __DIR__ . '/migration_schema46_r20.php';
    require_once __DIR__ . '/migration_schema46_notes_r67.php';

    if ($route === 'auth/feature-catalog') handle_public_feature_catalog();

    if ($route === 'auth/security') handle_auth_security();
    if ($route === 'auth/setup') handle_setup();
    if ($route === 'auth/login') handle_login();
    if ($route === 'auth/register') handle_register();
    if ($route === 'auth/me') handle_me();
    if ($route === 'auth/client-incident') handle_client_incident();
    if ($route === 'auth/logout') handle_logout();
    if ($route === 'auth/profile') handle_update_profile();
    if ($route === 'auth/change-password') handle_change_password();

    if ($route === 'startup/migration-preflight') handle_tegh_notes_r67_preflight();
    if ($route === 'startup/migration-diagnostic') handle_tegh_notes_r67_diagnostic();
    if ($route === 'startup/migrate') handle_tegh_notes_r67_upgrade();
    if ($route === 'startup/schema46-migrate') handle_tegh_schema46_upgrade();

    // Startup access must never make an established accounting workspace unavailable
    // merely because additive AI schema maintenance is pending. Normal sign-in performs
    // a read-only readiness check. Schema mutation is explicit through startup/migrate.
    if ($route === 'startup/status' || $route === 'startup/prepare') {
        require_method($route === 'startup/status' ? 'GET' : 'POST');
        $user = require_user();
        if ($route === 'startup/prepare') require_csrf();
        $schemaVersion = function_exists('current_database_schema_version') ? current_database_schema_version() : 0;
        $coreReady = function_exists('core_accounting_schema_ready') && core_accounting_schema_ready();
        $aiReady = function_exists('ai_additive_schema_ready') && ai_additive_schema_ready();
        $deliveryReviewReady = function_exists('tegh_schema39_contract_status') && tegh_schema39_contract_status()['ready'];
        $nativeAgentReady = function_exists('native_agent_schema_status') && native_agent_schema_status()['ready']
            && function_exists('native_ap_ar_schema_status') && native_ap_ar_schema_status()['ready']
            && $deliveryReviewReady;
        $financialAnalysisReady = function_exists('financial_analysis_schema_status') && financial_analysis_schema_status()['ready'];
        $payrollTaxAgentReady = function_exists('payroll_tax_autonomy_schema_status') && payroll_tax_autonomy_schema_status()['ready'];
        $entitlementReady = $schemaVersion >= 40 && function_exists('tegh_schema40_status') && tegh_schema40_status()['ready'];
        $vendorLearningReady = $schemaVersion >= 41 && function_exists('tegh_schema41_status') && tegh_schema41_status()['ready'];
        $transactionControlsReady = $schemaVersion >= 42 && function_exists('tegh_schema42_status') && tegh_schema42_status()['ready'];
        if ($schemaVersion > SR_ACCOUNTAX_SCHEMA_VERSION) fail('This database requires a newer Tegh release. Restore compatible application files before opening the workspace.',503,'newer_schema_unsupported');
        $schema44Ready=$schemaVersion>=44&&tegh_schema44_status()['ready'];
        $schema45Ready=$schemaVersion>=45&&tegh_schema45_status()['ready'];
        $schema46Ready=$schemaVersion>=46&&tegh_schema46_status()['ready'];
        $notesReady=tegh_notes_r67_ready() && tegh_notes_r69_invoice_tax_columns_ready() && tegh_notes_r70_settlements_ready()
            && tegh_notes_r69_unclassified_invoice_tax_count()===0;
        $taxPresetsReady=tegh_tax_r71_presets_ready();
        $schema43Contract = tegh_schema43_status();
        $schema43Ledger = tegh_schema43_ledger_status($schemaVersion,$schema43Contract);
        $bankAccountProfilesReady = $schemaVersion >= 43 && $schema43Contract['ready'] && $schema43Ledger['safe'] && !$schema43Ledger['pending'];
        if (!$coreReady) {
            fail('Tegh cannot safely open this accounting database yet. A Platform Owner must run the database upgrade.', 503, 'core_schema_upgrade_required');
        }
        $degraded = $schemaVersion < SR_ACCOUNTAX_SCHEMA_VERSION || !$aiReady || !$nativeAgentReady || !$financialAnalysisReady || !$payrollTaxAgentReady || !$entitlementReady || !$vendorLearningReady || !$transactionControlsReady || !$bankAccountProfilesReady || !$schema44Ready || !$schema45Ready || !$schema46Ready || !$notesReady || !$taxPresetsReady;
        $warningCode = $schemaVersion < SR_ACCOUNTAX_SCHEMA_VERSION ? 'schema_upgrade_pending' : (!$aiReady ? 'ai_storage_maintenance_required' : (!$nativeAgentReady?'native_agent_storage_maintenance_required':(!$financialAnalysisReady?'financial_analysis_storage_maintenance_required':(!$payrollTaxAgentReady?'payroll_tax_agent_storage_maintenance_required':null))));
        if (!$bankAccountProfilesReady && $warningCode === null) $warningCode='schema_upgrade_pending';
        json_response([
            'ok' => true,
            'version' => SR_ACCOUNTAX_VERSION,
            'build' => SR_ACCOUNTAX_BUILD,
            'schemaVersion' => $schemaVersion,
            'expectedSchemaVersion' => SR_ACCOUNTAX_SCHEMA_VERSION,
            'userId' => (string)$user['id'],
            'coreReady' => true,
            'aiReady' => $aiReady,
            'nativeAgentReady'=>$nativeAgentReady,
            'deliveryReviewReady'=>$deliveryReviewReady,
            'financialAnalysisReady'=>$financialAnalysisReady,
            'payrollTaxAgentReady'=>$payrollTaxAgentReady,
            'entitlementReady'=>$entitlementReady,
            'vendorLearningReady'=>$vendorLearningReady,
            'transactionControlsReady'=>$transactionControlsReady,
            'bankAccountProfilesReady'=>$bankAccountProfilesReady,'releaseStorageReady'=>$schema44Ready,
            'accountingFoundationReady'=>$schema45Ready,
            'invoiceDocumentsReady'=>$schema46Ready,
            'accountingNotesReady'=>$notesReady,
            'companyTaxPresetsReady'=>$taxPresetsReady,
            'accountingAvailable' => true,
            'degraded' => $degraded,
            'warningCode' => $warningCode,
        ]);
    }

    if ($route === 'startup/ai-storage') {
        require_method('GET','POST');
        $user=require_user();$status=ai_additive_schema_status();$coreReady=core_accounting_schema_ready();
        if(request_method()==='GET'){
            json_response(['ok'=>true,'coreReady'=>$coreReady,'aiReady'=>$status['ready'],'accountingUnaffected'=>$coreReady,'status'=>$status,'schemaVersion'=>current_database_schema_version(),'expectedSchemaVersion'=>SR_ACCOUNTAX_SCHEMA_VERSION]);
        }
        require_csrf();
        $ownerStmt=db()->prepare("SELECT platform_role FROM users WHERE id=? AND active=1 LIMIT 1");$ownerStmt->execute([(string)$user['id']]);
        if((string)($ownerStmt->fetchColumn()?:'')!=='platform_owner')fail('Only the Platform Owner can prepare Tegh AI storage.',403,'platform_owner_required');
        if(!$coreReady)fail('Core accounting storage is not safe enough for AI-only maintenance. Run the protected database upgrade first.',503,'core_schema_upgrade_required');
        $lockAcquired=null;
        try{
            try{$lockStmt=db()->query("SELECT GET_LOCK('tegh_schema34_ai_storage_repair', 2)");$lockAcquired=(int)$lockStmt->fetchColumn();}catch(Throwable $ignored){}
            if($lockAcquired===0)fail('Another Tegh AI storage repair is still running. Retry shortly.',409,'ai_storage_repair_busy');
            $result=ensure_schema34_ai_storage($user);$after=$result['after'];
            if(empty($after['ready']))fail('Tegh AI storage repair is incomplete. Accounting remains available; review the reported missing AI structures.',503,'ai_storage_repair_incomplete');
            json_response(['ok'=>true,'coreReady'=>true,'aiReady'=>true,'accountingUnaffected'=>true,'repair'=>$result]);
        } finally {
            if($lockAcquired===1){try{db()->query("SELECT RELEASE_LOCK('tegh_schema34_ai_storage_repair')");}catch(Throwable $ignored){}}
        }
    }

    // The authenticated home screen has a deliberately small dependency and
    // query surface. Dispatch it before loading unrelated accounting, Payroll,
    // OCR, AI, export, and administration handlers.
    if ($route === 'workspace/summary') {
        require_once __DIR__ . '/workspace_summary_v5610.php';
        handle_tegh_workspace_summary();
    }
    if ($route === 'bank-transactions/list') {
        require_once __DIR__ . '/bank_register_v5910.php';
        handle_tegh_bank_register();
    }

    require_once __DIR__ . '/companies.php';
    require_once __DIR__ . '/imports.php';
    require_once __DIR__ . '/statement_xlsx_v5990.php';
    require_once __DIR__ . '/invoicing.php';
    require_once __DIR__ . '/master_data.php';
    require_once __DIR__ . '/accounting.php';
    require_once __DIR__ . '/bank_operations_v5980.php';
    require_once __DIR__ . '/interbank_v5990.php';
    require_once __DIR__ . '/bank_register_v5910.php';
    require_once __DIR__ . '/bank_categories_v5980.php';
    require_once __DIR__ . '/payments.php';
    require_once __DIR__ . '/advanced.php';
    require_once __DIR__ . '/financial_analysis_v5600.php';
    require_once __DIR__ . '/report_periods_v5600.php';
    require_once __DIR__ . '/backup.php';
    require_once __DIR__ . '/books.php';
    require_once __DIR__ . '/payroll_rates.php';
    require_once __DIR__ . '/payroll.php';
    require_once __DIR__ . '/operations.php';
    require_once __DIR__ . '/voids.php';
    require_once __DIR__ . '/ai_provider.php';
    require_once __DIR__ . '/ai.php';
    require_once __DIR__ . '/ai_agent.php';
    require_once __DIR__ . '/ai_research.php';
    require_once __DIR__ . '/portal.php';
    require_once __DIR__ . '/marketing.php';
    require_once __DIR__ . '/data_imports.php';
    require_once __DIR__ . '/platform.php';
    require_once __DIR__ . '/native_agents.php';
    require_once __DIR__ . '/native_ap_ar_v5600.php';
    require_once __DIR__ . '/payroll_tax_agent.php';
    require_once __DIR__ . '/admin.php';
    require_once __DIR__ . '/command_centre_v5600.php';
    require_once __DIR__ . '/contextual_assistance_v5700.php';
    require_once __DIR__ . '/authorized_reconciliation_v5500.php';
    require_once __DIR__ . '/professional_output_v5710.php';
    require_once __DIR__ . '/professional_output_v5800.php';
    require_once __DIR__ . '/bank_preview_output_v5980.php';
require_once __DIR__ . '/report_output_v5980.php';
require_once __DIR__ . '/report_loaders_v5980.php';
require_once __DIR__ . '/report_subledgers_v5980.php';
require_once __DIR__ . '/report_comparison_r20.php';
    require_once __DIR__ . '/invoice_documents_r20.php';
    require_once __DIR__ . '/accounting_notes.php';
    require_once __DIR__ . '/documents_r151.php';
    require_once __DIR__ . '/intake_r152.php';
    require_once __DIR__ . '/tax_presets.php';
    require_once __DIR__ . '/tax_codes_r137.php';
    require_once __DIR__ . '/regions_r141.php';
    require_once __DIR__ . '/qa_guardian_v5820.php';

    // Password reset uses the shared Tegh mailer, so it is dispatched after
    // portal.php is loaded while remaining unauthenticated by design.
    // The scheduler is deliberately outside session authentication but is not
    // public: its handler requires HTTPS plus a long secret held in Tegh's
    // private configuration outside the deployment web root.
    if ($route === 'native-agent-scheduler') handle_native_agent_scheduler();
    if ($route === 'qa-guardian-scheduler') handle_qa_guardian_scheduler();
    if ($route === 'auth/password-reset-request') handle_password_reset_request();
    if ($route === 'auth/password-reset-details') handle_password_reset_details();
    if ($route === 'auth/password-reset-complete') handle_password_reset_complete();
    // R133: client viewing links. Management routes require an owner/admin
    // session; the client routes use their own emailed-code session.
    if ($route === 'client-view' || str_starts_with($route, 'client-view/')) {
        require_once __DIR__ . '/client_view_r133.php';
        handle_client_view($route);
    }

    // R137: tax code tables/columns are created on first use (outside any transaction).
    if ($route !== '' && !str_starts_with($route, 'startup/') && !str_starts_with($route, 'auth/')) { try { tegh_tax_codes_ready(); } catch (Throwable $e) { error_log('Tegh R137 tax code setup: ' . $e->getMessage()); } }
    // R141: companies.country and wider province/state columns, created on first use.
    if ($route !== '' && !str_starts_with($route, 'startup/') && !str_starts_with($route, 'auth/')) { try { tegh_regions_ready(); } catch (Throwable $e) { error_log('Tegh R141 region setup: ' . $e->getMessage()); } }
    // R157: company onboarding (state, steps, chart template, tax code import, welcome tour).
    require_once __DIR__ . '/onboarding_r157.php';
    if ($route === 'onboarding' || str_starts_with($route, 'onboarding/')) handle_onboarding(trim(substr($route, strlen('onboarding')), '/'));
    if ($route === 'tax-codes') handle_tax_codes();
    if ($route === 'dashboard-mappings') { require_once __DIR__ . '/dashboard_mappings_r141.php'; handle_dashboard_mappings(); }
    if ($route === 'insights' || str_starts_with($route, 'insights/')) { require_once __DIR__ . '/insights_r144.php'; handle_insights(trim(substr($route, strlen('insights')), '/')); }
    // R163: Guided bank statements by month (keyword groups, posting, automatic reconciliation) and Connect with an accountant.
    if (str_starts_with($route, 'guided/')) { require_once __DIR__ . '/workspace_summary_v5610.php'; require_once __DIR__ . '/guided_r163.php'; handle_guided_r163(substr($route, 7)); }
    // R159: archive, restore and deletion requests (outside the company context: an archived company cannot be selected).
    require_once __DIR__ . '/company_lifecycle_r159.php';
    if (str_starts_with($route, 'companies/') && in_array(substr($route, 10), ['archived', 'archive', 'restore', 'deletion-request', 'deletion-request/cancel'], true)) handle_company_lifecycle(substr($route, 10));
    if ($route === 'companies') handle_companies();
    if ($route === 'workspace') handle_workspace();
    if ($route === 'customers') handle_customers();
    if ($route === 'party-opening-balance') handle_party_opening_balance();
    if (str_starts_with($route,'invoices/r20/')) handle_invoice_documents_r20(substr($route,strlen('invoices/r20/')));
    if ($route === 'invoices') handle_invoices();
    if ($route === 'accounting-notes') handle_accounting_notes();
    if ($route === 'tax-presets') handle_company_invoice_tax_presets();
    if ($route === 'invoice-templates') handle_invoice_templates();
    if ($route === 'expenses') handle_expenses();
    if ($route === 'vendors') handle_vendors();
    if ($route === 'bills') handle_bills();
    if ($route === 'payments') handle_party_payments();
    if ($route === 'currencies') handle_currencies();
    if ($route === 'journals') handle_manual_journals();
    if ($route === 'journals/bulk') handle_bulk_manual_journals();
    if ($route === 'setup/opening-balances') handle_opening_balances();
    if ($route === 'period-close') handle_period_close();
    if ($route === 'imports') handle_imports();
    if ($route === 'imports/upload-start') handle_import_upload_start();
    if ($route === 'imports/upload-chunk') handle_import_upload_chunk();
    if ($route === 'imports/upload-finish') handle_import_upload_finish();
    if ($route === 'imports/delete') handle_import_delete();
    if ($route === 'requested-downloads') { require_once __DIR__.'/requested_downloads.php'; handle_requested_downloads(); }
    if ($route === 'attachments') handle_attachments();
    if (str_starts_with($route,'bank-post-operations/')) handle_tegh_bank_operations(substr($route,strlen('bank-post-operations/')));
    if (str_starts_with($route,'interbank-v5990/')) handle_tegh_interbank_v5990(substr($route,strlen('interbank-v5990/')));
    if ($route === 'bank-transactions/post') handle_bank_transaction_post();
    if ($route === 'bank-transactions/reassign') handle_bank_transaction_reassign();
    if ($route === 'bank-transactions/categorize') handle_bank_transaction_categorize();
    if ($route === 'bank-transactions/exclude') handle_bank_transaction_review_state('exclude');
    if ($route === 'bank-transactions/restore') handle_bank_transaction_review_state('restore');
    if ($route === 'bank-transactions/delete') handle_bank_transaction_delete();
    if ($route === 'bank-transactions/edit') handle_bank_transaction_edit();
    if ($route === 'reconciliations') handle_reconciliations();
    if ($route === 'settings') handle_settings();
    if ($route === 'accounts') handle_accounts();
    if ($route === 'bank-accounts') handle_bank_accounts();
    if ($route === 'payroll' || str_starts_with($route, 'payroll/')) handle_payroll(trim(substr($route, strlen('payroll')), '/'));
    if ($route === 'ai/categorize') handle_ai_categorize();
    if ($route === 'ai-research' || str_starts_with($route, 'ai-research/')) handle_ai_research(trim(substr($route, strlen('ai-research')), '/'));
    if ($route === 'agent' || str_starts_with($route, 'agent/')) handle_ai_agent(trim(substr($route, strlen('agent')), '/'));
    if ($route === 'native-agent' || str_starts_with($route, 'native-agent/')) handle_native_agent(trim(substr($route, strlen('native-agent')), '/'));
    if ($route === 'native-ap-ar' || str_starts_with($route, 'native-ap-ar/')) handle_native_ap_ar(trim(substr($route, strlen('native-ap-ar')), '/'));
    if ($route === 'financial-analysis' || str_starts_with($route, 'financial-analysis/')) handle_financial_analysis(trim(substr($route, strlen('financial-analysis')), '/'));
    if ($route === 'reports/v5600' || str_starts_with($route, 'reports/v5600/')) handle_report_periods_v5600(trim(substr($route, strlen('reports/v5600')), '/'));
    if ($route === 'payroll-tax-agent' || str_starts_with($route, 'payroll-tax-agent/')) handle_payroll_tax_agent(trim(substr($route, strlen('payroll-tax-agent')), '/'));
    if ($route === 'backup/export') handle_backup_export();
    if ($route === 'backup/restore') handle_backup_restore();
    if ($route === 'portal' || str_starts_with($route,'portal/')) handle_portal(trim(substr($route,strlen('portal')),'/'));
    if ($route === 'marketing' || str_starts_with($route,'marketing/')) handle_marketing(trim(substr($route,strlen('marketing')),'/'));
    if (str_starts_with($route,'data-imports/bank-transaction-categories/')) handle_tegh_bank_categories(substr($route,strlen('data-imports/bank-transaction-categories/')));
    if ($route === 'data-imports' || str_starts_with($route,'data-imports/')) handle_data_imports(trim(substr($route,strlen('data-imports')),'/'));
    if ($route === 'platform' || str_starts_with($route,'platform/')) handle_platform(trim(substr($route,strlen('platform')),'/'));
    if ($route === 'admin' || str_starts_with($route,'admin/')) handle_admin(trim(substr($route,strlen('admin')),'/'));
    if ($route === 'entitlements' || str_starts_with($route,'entitlements/')) handle_entitlements(trim(substr($route,strlen('entitlements')),'/'));
    if ($route === 'command-centre' || str_starts_with($route,'command-centre/')) handle_command_centre(trim(substr($route,strlen('command-centre')),'/'));
    if ($route === 'contextual-assistance' || str_starts_with($route,'contextual-assistance/')) handle_contextual_assistance(trim(substr($route,strlen('contextual-assistance')),'/'));
    if ($route === 'operations/authorized-match-post' || str_starts_with($route,'operations/authorized-match-post/')) handle_authorized_reconciliation(trim(substr($route,strlen('operations/authorized-match-post')),'/'));
    // A cached 5970 client uses the same authoritative current definition, never an old loader.
    if (str_starts_with($route,'professional-output/v5970/')) $route='professional-output/v5980/'.substr($route,strlen('professional-output/v5970/'));
    if (str_starts_with($route,'professional-output/v5990/')) $route='professional-output/v5980/'.substr($route,strlen('professional-output/v5990/'));
    if ($route === 'professional-output/v5980/statement-preview') handle_bank_preview_output_v5980();
    if (str_starts_with($route,'professional-output/v5980/')) handle_report_output_5980(substr($route,strlen('professional-output/v5980/')));
    if ($route === 'professional-output/v5710' || str_starts_with($route,'professional-output/v5710/')) handle_professional_output_v5710(trim(substr($route,strlen('professional-output/v5710')),'/'));
    if ($route === 'professional-output/v5800' || str_starts_with($route,'professional-output/v5800/')) handle_professional_output_v5800(trim(substr($route,strlen('professional-output/v5800')),'/'));
    if ($route === 'qa-guardian' || str_starts_with($route,'qa-guardian/')) handle_qa_guardian(trim(substr($route,strlen('qa-guardian')),'/'));
    if ($route === 'advanced' || str_starts_with($route, 'advanced/')) handle_advanced(trim(substr($route, strlen('advanced')), '/'));
    if ($route === 'voids' || str_starts_with($route, 'voids/')) handle_voids(trim(substr($route, strlen('voids')), '/'));
    if ($route === 'operations' || str_starts_with($route, 'operations/')) handle_operations(trim(substr($route, strlen('operations')), '/'));
    fail('API route not found.', 404, 'route_not_found');
} catch (Throwable $error) {
    $incidentId = function_exists('record_system_incident')
        ? record_system_incident(
            'The server could not complete this request. No accounting changes were confirmed.',
            500,
            'server_error',
            $error,
            ['source'=>'uncaught_exception'],
        )
        : 'unavailable';
    error_log('Tegh API error incident=' . $incidentId . ' request=' . (function_exists('request_id') ? request_id() : 'startup') . ' ' . $error::class . ': ' . $error->getMessage());
    if (!headers_sent() && function_exists('security_headers')) security_headers();
    if (function_exists('fail')) fail('The server could not complete this request. No accounting changes were confirmed.', 500, 'server_error', false);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"The server could not start.","code":"server_start_failed"}';
    exit;
}
