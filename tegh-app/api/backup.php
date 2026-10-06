<?php
declare(strict_types=1);

/** Backups are written as 'tegh-backup'. Archives made before the product was
 *  renamed carry the former format name and are still accepted for restore. */
function tegh_backup_format_supported(mixed $format): bool
{
    return in_array((string)$format, ['tegh-backup', 'srbooks-backup'], true);
}

/** Schema revision recorded in every backup manifest. Keep in step with migrations.php. */
const SR_BACKUP_SCHEMA_VERSION = 46;

function backup_rows(string $sql, string $companyId): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}

function backup_evidence_item(string $kind, string $recordId, ?string $relativePath): ?array
{
    if ($relativePath === null || $relativePath === '') return null;
    $absolute = private_absolute_path($relativePath);
    return [
        'kind' => $kind,
        'recordId' => $recordId,
        'relativePath' => $relativePath,
        'status' => $absolute !== null ? 'present' : 'missing',
        'sizeBytes' => $absolute !== null ? (int)filesize($absolute) : null,
        'sha256' => $absolute !== null ? hash_file('sha256', $absolute) : null,
    ];
}

function backup_record_queries(): array
{
    $queries = [
        'documentSequences' => 'SELECT * FROM document_sequences WHERE company_id = ? ORDER BY document_type',
        'accounts' => 'SELECT * FROM accounts WHERE company_id = ? ORDER BY code',
        'companySystemAccounts' => 'SELECT * FROM company_system_accounts WHERE company_id = ? ORDER BY system_key',
        'openingBalanceDrafts' => 'SELECT * FROM opening_balance_drafts WHERE company_id = ? ORDER BY account_id',
        'partyOpeningBalances' => 'SELECT * FROM party_opening_balances WHERE company_id = ? ORDER BY effective_date, id',
        'recurringBillProfiles' => 'SELECT * FROM recurring_bill_profiles WHERE company_id = ? ORDER BY name, id',
        'customers' => 'SELECT * FROM customers WHERE company_id = ? ORDER BY name, id',
        'invoiceTemplates' => 'SELECT * FROM invoice_templates WHERE company_id = ? ORDER BY name, id',
        'invoices' => 'SELECT * FROM invoices WHERE company_id = ? ORDER BY issue_date, number, id',
        'invoiceLines' => 'SELECT il.* FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id WHERE i.company_id = ? ORDER BY il.invoice_id, il.sort_order, il.id',
        'bankAccounts' => 'SELECT * FROM bank_accounts WHERE company_id = ? ORDER BY name, id',
        'importBatches' => 'SELECT * FROM import_batches WHERE company_id = ? ORDER BY created_at, id',
        'bankTransactions' => 'SELECT * FROM bank_transactions WHERE company_id = ? ORDER BY transaction_date, created_at, id',
        'journalEntries' => 'SELECT * FROM journal_entries WHERE company_id = ? ORDER BY entry_date, created_at, id',
        'journalLines' => 'SELECT jl.* FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id WHERE je.company_id = ? ORDER BY jl.journal_entry_id, jl.id',
        'journalApprovals' => 'SELECT * FROM journal_approvals WHERE company_id = ? ORDER BY submitted_at, id',
        'companyCurrencies' => 'SELECT * FROM company_currencies WHERE company_id = ? ORDER BY currency_code',
        'accountingControls' => 'SELECT * FROM accounting_controls WHERE company_id = ?',
        'vendors' => 'SELECT * FROM vendors WHERE company_id = ? ORDER BY name, id',
        'bills' => 'SELECT * FROM bills WHERE company_id = ? ORDER BY bill_date, number, id',
        'partyPayments' => 'SELECT * FROM party_payments WHERE company_id = ? ORDER BY payment_date, created_at, id',
        'partyPaymentApplicationOperations' => 'SELECT * FROM party_payment_application_operations WHERE company_id = ? ORDER BY created_at, id',
        'partyPaymentApplications' => 'SELECT * FROM party_payment_applications WHERE company_id = ? ORDER BY application_date, created_at, id',
        'paymentLegacyMappingReviews' => 'SELECT * FROM payment_legacy_mapping_reviews WHERE company_id = ? ORDER BY created_at, payment_id',
        'interbankTransfers' => 'SELECT * FROM interbank_transfers WHERE company_id = ? ORDER BY created_at, id',
        'interbankTransferLegs' => 'SELECT * FROM interbank_transfer_legs WHERE company_id = ? ORDER BY linked_at, id',
        'interbankTransferOperations' => 'SELECT * FROM interbank_transfer_operations WHERE company_id = ? ORDER BY created_at, id',
        'bankStatementBalanceAnchors' => 'SELECT * FROM bank_statement_balance_anchors WHERE company_id = ? ORDER BY bank_account_id, revision, id',
        'bankImportControlReceipts' => 'SELECT * FROM bank_import_control_receipts WHERE company_id = ? ORDER BY created_at, id',
        'openingBalanceImports' => 'SELECT * FROM opening_balance_imports WHERE company_id = ? ORDER BY effective_date, created_at, id',
        'openingDocumentImports' => 'SELECT * FROM opening_document_imports WHERE company_id = ? ORDER BY cutover_date, created_at, id',
        'payrollSettings' => 'SELECT * FROM payroll_settings WHERE company_id = ?',
        'payrollEmployees' => 'SELECT * FROM payroll_employees WHERE company_id = ? ORDER BY employee_number, id',
        'payrollRuns' => 'SELECT * FROM payroll_runs WHERE company_id = ? ORDER BY pay_date, run_sequence, id',
        'payrollRunItems' => 'SELECT pri.* FROM payroll_run_items pri JOIN payroll_runs pr ON pr.id = pri.payroll_run_id WHERE pr.company_id = ? ORDER BY pri.payroll_run_id, pri.id',
        'payrollRemittances' => 'SELECT * FROM payroll_remittances WHERE company_id = ? ORDER BY payment_date, id',
        'expenses' => 'SELECT * FROM expenses WHERE company_id = ? ORDER BY expense_date, created_at, id',
        'statementPreviews' => 'SELECT * FROM statement_previews WHERE company_id = ? ORDER BY created_at, id',
        'periodLocks' => 'SELECT * FROM period_locks WHERE company_id = ? ORDER BY period_end, id',
        'reconciliations' => 'SELECT * FROM reconciliations WHERE company_id = ? ORDER BY period_end, id',
        'reconciliationEvents' => 'SELECT * FROM reconciliation_events WHERE company_id = ? ORDER BY created_at, id',
        'reconciliationItems' => 'SELECT ri.* FROM reconciliation_items ri JOIN reconciliations r ON r.id = ri.reconciliation_id WHERE r.company_id = ? ORDER BY ri.reconciliation_id, ri.bank_transaction_id',
        'bankMatchGroups' => 'SELECT * FROM bank_match_groups WHERE company_id = ? ORDER BY matched_at, id',
        'bankMatchBankItems' => 'SELECT bmbi.* FROM bank_match_bank_items bmbi JOIN bank_match_groups bmg ON bmg.id=bmbi.match_group_id WHERE bmg.company_id = ? ORDER BY bmbi.match_group_id,bmbi.bank_transaction_id',
        'bankMatchBookItems' => 'SELECT bmbi.* FROM bank_match_book_items bmbi JOIN bank_match_groups bmg ON bmg.id=bmbi.match_group_id WHERE bmg.company_id = ? ORDER BY bmbi.match_group_id,bmbi.journal_entry_id',
        'reconciliationMatchGroups' => 'SELECT rmg.* FROM reconciliation_match_groups rmg JOIN reconciliations r ON r.id=rmg.reconciliation_id WHERE r.company_id = ? ORDER BY rmg.reconciliation_id,rmg.match_group_id',
        'reconciliationMatchProposals' => 'SELECT * FROM reconciliation_match_proposals WHERE company_id = ? ORDER BY period_end, last_seen_at, id',
        'reconciliationResumeLog' => 'SELECT * FROM reconciliation_resume_log WHERE company_id = ? ORDER BY started_at, id',
        'categoryRules' => 'SELECT * FROM category_rules WHERE company_id = ? ORDER BY merchant_pattern, id',
        'aiRuns' => 'SELECT * FROM ai_runs WHERE company_id = ? ORDER BY created_at, id',
        'aiAgentIncidents' => 'SELECT * FROM ai_agent_incidents WHERE company_id = ? ORDER BY created_at, id',
        'aiAgentWorkflowSessions' => 'SELECT * FROM ai_agent_workflow_sessions WHERE company_id = ? ORDER BY created_at, id',
        'aiAgentPreferences' => 'SELECT * FROM ai_agent_preferences WHERE company_id = ? ORDER BY created_at, id',
        'aiAgentLearningEvents' => 'SELECT * FROM ai_agent_learning_events WHERE company_id = ? ORDER BY created_at, id',
        'aiAgentLearnedRules' => 'SELECT * FROM ai_agent_learned_rules WHERE company_id = ? ORDER BY created_at, id',
        'aiAgentImprovements' => 'SELECT * FROM ai_agent_improvements WHERE company_id = ? ORDER BY created_at, id',
        'aiAgentBehaviorVersions' => 'SELECT * FROM ai_agent_behavior_versions WHERE company_id = ? ORDER BY version_number, id',
        'aiAgentReviewRuns' => 'SELECT * FROM ai_agent_review_runs WHERE company_id = ? ORDER BY created_at, id',
        'aiAgentConversations' => 'SELECT * FROM ai_agent_conversations WHERE company_id = ? ORDER BY created_at, id',
        'aiAgentMemories' => "SELECT * FROM ai_agent_memories WHERE company_id = ? AND memory_type <> 'working' ORDER BY created_at, id",
        'aiAgentMemoryHistory' => "SELECT h.* FROM ai_agent_memory_history h JOIN ai_agent_memories m ON m.id=h.memory_id WHERE h.company_id = ? AND m.company_id=h.company_id AND m.memory_type <> 'working' ORDER BY h.created_at, h.id",
        'aiAgentPlans' => "SELECT * FROM ai_agent_plans WHERE company_id = ? AND status IN ('completed','failed','cancelled','superseded','needs_review') ORDER BY created_at, id",
        'nativeAgentPolicies' => 'SELECT * FROM native_agent_policies WHERE company_id = ? ORDER BY created_at, id',
        'nativeAgentAutonomyPolicies' => 'SELECT * FROM native_agent_autonomy_policies WHERE company_id = ? ORDER BY created_at, id',
        'nativeAgentRuns' => 'SELECT * FROM native_agent_runs WHERE company_id = ? ORDER BY started_at, id',
        'nativeAgentRunFailures' => 'SELECT * FROM native_agent_run_failures WHERE company_id = ? ORDER BY first_failed_at, id',
        'nativeAgentFindings' => 'SELECT * FROM native_agent_findings WHERE company_id = ? ORDER BY first_seen_at, id',
        'nativeAgentAutonomyRuns' => 'SELECT * FROM native_agent_autonomy_runs WHERE company_id = ? ORDER BY started_at, id',
        'nativeAgentDocuments' => 'SELECT * FROM native_agent_documents WHERE company_id = ? ORDER BY created_at, id',
        'vendorRecognitionRules' => 'SELECT * FROM vendor_recognition_rules WHERE company_id = ? ORDER BY created_at, id',
        'vendorRecognitionHistory' => 'SELECT * FROM vendor_recognition_history WHERE company_id = ? ORDER BY created_at, id',
        'nativeAgentCollectionDrafts' => 'SELECT * FROM native_agent_collection_drafts WHERE company_id = ? ORDER BY created_at, id',
        'outboundEmailAttempts' => 'SELECT * FROM outbound_email_attempts WHERE company_id = ? ORDER BY created_at, id',
        'collectionMessageTemplates' => 'SELECT * FROM collection_message_templates WHERE company_id = ? ORDER BY tone, message_length, revision, id',
        'monthEndAttestations' => 'SELECT * FROM month_end_attestations WHERE company_id = ? ORDER BY period_end, attested_at, id',
        'analyticAccounts' => 'SELECT * FROM analytic_accounts WHERE company_id = ? ORDER BY code, id',
        'analyticAllocations' => 'SELECT * FROM analytic_allocations WHERE company_id = ? ORDER BY created_at, id',
        'budgets' => 'SELECT * FROM budgets WHERE company_id = ? ORDER BY period_start, id',
        'budgetLines' => 'SELECT bl.* FROM budget_lines bl JOIN budgets b ON b.id = bl.budget_id WHERE b.company_id = ? ORDER BY bl.budget_id, bl.id',
        'financialScenarios' => 'SELECT * FROM financial_scenarios WHERE company_id = ? ORDER BY created_at, id',
        'financialScenarioAdjustments' => 'SELECT * FROM financial_scenario_adjustments WHERE company_id = ? ORDER BY scenario_id, adjustment_date, id',
        'fixedAssets' => 'SELECT * FROM fixed_assets WHERE company_id = ? ORDER BY in_service_date, id',
        'assetDepreciationLines' => 'SELECT dl.* FROM asset_depreciation_lines dl JOIN fixed_assets fa ON fa.id = dl.asset_id WHERE fa.company_id = ? ORDER BY dl.asset_id, dl.sequence_number',
        'recurringJournalTemplates' => 'SELECT * FROM recurring_journal_templates WHERE company_id = ? ORDER BY name, id',
        'recurringJournalLines' => 'SELECT rjl.* FROM recurring_journal_lines rjl JOIN recurring_journal_templates rjt ON rjt.id = rjl.template_id WHERE rjt.company_id = ? ORDER BY rjl.template_id, rjl.sort_order, rjl.id',
        'recurringInvoiceProfiles' => 'SELECT * FROM recurring_invoice_profiles WHERE company_id = ? ORDER BY name, id',
        'recurringInvoiceLines' => 'SELECT ril.* FROM recurring_invoice_lines ril JOIN recurring_invoice_profiles rip ON rip.id = ril.profile_id WHERE rip.company_id = ? ORDER BY ril.profile_id, ril.sort_order, ril.id',
        'invoiceFollowups' => 'SELECT * FROM invoice_followups WHERE company_id = ? ORDER BY action_date, created_at, id',
        'cashFlowMappings' => 'SELECT * FROM cash_flow_mappings WHERE company_id = ? ORDER BY account_id',
        'statutoryRates' => 'SELECT * FROM statutory_rates WHERE company_id = ? ORDER BY effective_from, rate_key, id',
        'employerLevyProfiles' => 'SELECT * FROM employer_levy_profiles WHERE company_id = ?',
        'employerLevyRates' => 'SELECT * FROM employer_levy_rates WHERE company_id = ? ORDER BY effective_from, payroll_min_cents, id',
        'payrollJournalDrafts' => 'SELECT * FROM payroll_journal_drafts WHERE company_id = ? ORDER BY created_at, id',
        'payrollVerifications' => 'SELECT * FROM payroll_verifications WHERE company_id = ? ORDER BY verified_at, id',
        'voucherSequences' => 'SELECT * FROM voucher_sequences WHERE company_id = ?',
        'transactionSequences' => 'SELECT * FROM transaction_sequences WHERE company_id = ? ORDER BY prefix',
        'vouchers' => 'SELECT * FROM vouchers WHERE company_id = ? ORDER BY serial_number, id',
        'voucherDraftPayloads' => 'SELECT * FROM voucher_draft_payloads WHERE company_id = ? ORDER BY updated_at, id',
        'notifications' => 'SELECT * FROM notifications WHERE company_id = ? ORDER BY created_at, id',
        'companyInvitations' => 'SELECT * FROM company_invitations WHERE company_id = ? ORDER BY created_at, id',
        'productsServices' => 'SELECT * FROM products_services WHERE company_id = ? ORDER BY name, id',
        'agingProfiles' => 'SELECT * FROM aging_profiles WHERE company_id = ?',
        'outboundEmails' => 'SELECT * FROM outbound_emails WHERE company_id = ? ORDER BY created_at, id',
        'supportRequests' => 'SELECT * FROM support_requests WHERE company_id = ? ORDER BY created_at, id',
        'officialRateReleases' => 'SELECT * FROM official_rate_releases WHERE company_id = ? ORDER BY checked_at, id',
        'auditIntegrityRuns' => 'SELECT * FROM audit_integrity_runs WHERE company_id = ? ORDER BY created_at, id',
        'backupRestoreLog' => 'SELECT * FROM backup_restore_log WHERE company_id = ? ORDER BY created_at, id',
        'auditLog' => 'SELECT * FROM audit_log WHERE company_id = ? ORDER BY created_at, id',
    ];
    foreach(['invoiceDocumentOperations'=>'invoice_document_operations','invoiceAttachments'=>'invoice_attachments'] as $key=>$table)
        if(current_database_schema_version()>=46||schema_table_exists($table))$queries[$key]='SELECT * FROM '.$table.' WHERE company_id = ? ORDER BY created_at,id';
    if(schema_table_exists('accounting_notes'))$queries['accountingNotes']='SELECT * FROM accounting_notes WHERE company_id = ? ORDER BY created_at,id';
    if(schema_table_exists('accounting_note_settlements'))$queries['accountingNoteSettlements']='SELECT * FROM accounting_note_settlements WHERE company_id = ? ORDER BY settlement_date,id';
    if(schema_table_exists('company_invoice_tax_presets'))$queries['companyInvoiceTaxPresets']='SELECT * FROM company_invoice_tax_presets WHERE company_id = ? ORDER BY province,effective_from,id';
    // R137: tax codes, their taxes, and the tax detail saved on each document.
    if(schema_table_exists('tax_codes'))$queries['taxCodes']='SELECT id,company_id,code,name,region,description,status,created_by,updated_by,created_at,updated_at FROM tax_codes WHERE company_id = ? ORDER BY code,id';
    if(schema_table_exists('tax_code_components'))$queries['taxCodeComponents']='SELECT * FROM tax_code_components WHERE company_id = ? ORDER BY tax_code_id,sort_order,id';
    if(schema_table_exists('document_tax_lines'))$queries['documentTaxLines']='SELECT * FROM document_tax_lines WHERE company_id = ? ORDER BY document_type,document_id,sort_order,id';
    // R151: itemized vendor invoice lines and credit/debit note lines.
    if(schema_table_exists('bill_lines'))$queries['billLines']='SELECT * FROM bill_lines WHERE company_id = ? ORDER BY bill_id,sort_order,id';
    if(schema_table_exists('accounting_note_lines'))$queries['accountingNoteLines']='SELECT * FROM accounting_note_lines WHERE company_id = ? ORDER BY note_id,sort_order,id';
    // R152: supplier memory learned from verified vendor invoices.
    if(schema_table_exists('vendor_document_memory'))$queries['vendorDocumentMemory']='SELECT * FROM vendor_document_memory WHERE company_id = ? ORDER BY vendor_id';
    return $queries;
}

function backup_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('backup_canonicalize', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = backup_canonicalize($item);
    return $value;
}

function backup_canonical_json(mixed $value): string
{
    return json_encode(
        backup_canonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );
}

function backup_signature(string $payloadSha256): string
{
    return hash_hmac('sha256', $payloadSha256, (string)config('app.secret'));
}

function backup_slug(string $name): string
{
    $slug=strtolower((string)(preg_replace('/[^A-Za-z0-9]+/','-',$name)??'company'));
    return trim($slug,'-')?:'company';
}

function handle_backup_export(): never
{
    // R151: line tables are created on first use; make sure they exist before export/restore.
    if(function_exists('note_lines_ready'))note_lines_ready();if(function_exists('r151_schema_ready'))r151_schema_ready();if(function_exists('r152_schema_ready'))r152_schema_ready();
    require_method('POST');require_csrf();$user=require_user();$company=require_company($user);require_company_permission($company,'company.backup');
    if(!class_exists('ZipArchive'))fail('The server ZIP extension is required for Tegh backups.',503,'zip_unavailable');
    $snapshot=backup_capture_snapshot($company);$backupId=new_id('backup');$companyId=(string)$company['id'];
    $evidence=backup_collect_evidence($snapshot['records'],$companyId);
    $payload=['format'=>'tegh-backup','formatVersion'=>2,'applicationVersion'=>SR_ACCOUNTAX_VERSION,'schemaTarget'=>SR_BACKUP_SCHEMA_VERSION,'backupId'=>$backupId,'createdAtUtc'=>gmdate('c'),'createdBy'=>['userId'=>(string)$user['id'],'email'=>(string)$user['email']],'evidenceManifest'=>$evidence,'excludedCollections'=>backup_exclusions(),'recoveryRequirements'=>['The original protected installation configuration/app.secret is required to verify this archive and decrypt protected payroll fields. It is deliberately not embedded.','Restore creates a separate company and pauses automations. Review its users and policies.']]+$snapshot;
    $tmp=tempnam(sys_get_temp_dir(),'tegh_backup_');if($tmp===false)throw new RuntimeException('A private backup temporary file could not be created.');chmod($tmp,0600);
    register_shutdown_function(static function()use($tmp):void{if(is_file($tmp))@unlink($tmp);});
    try{
        $manifest=backup_write_archive($payload,$tmp);$sha=$manifest['payloadSha256'];$filename='tegh-'.backup_slug((string)$company['name']).'-'.gmdate('Ymd-His').'Z.tegh';
        db()->prepare('INSERT INTO backup_restore_log (id,company_id,backup_id,action,filename,payload_sha256,performed_by,details_json) VALUES (?,?,?,?,?,?,?,?)')->execute([new_id('backuplog'),$companyId,$backupId,'export',$filename,$sha,$user['id'],json_encode(['recordCounts'=>$snapshot['recordCounts'],'evidenceCount'=>count($evidence),'excludedCollections'=>backup_exclusions()],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        audit_event($user,$companyId,'backup.exported','company_backup',$backupId,['format'=>'tegh-backup','formatVersion'=>2,'payloadSha256'=>$sha]);
        clearstatcache(true,$tmp);$size=filesize($tmp);if($size===false||$size<4)throw new RuntimeException('The backup file could not be verified.');
        header('Content-Type: application/vnd.tegh.backup');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.$size);header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');if(readfile($tmp)!==$size)throw new RuntimeException('The backup download was interrupted.');
    }finally{@unlink($tmp);}exit;
}

function backup_restore_map(): array
{
    $map = [
      'documentSequences'=>'document_sequences','accounts'=>'accounts','customers'=>'customers','invoiceTemplates'=>'invoice_templates','invoices'=>'invoices','invoiceLines'=>'invoice_lines','bankAccounts'=>'bank_accounts','importBatches'=>'import_batches','bankTransactions'=>'bank_transactions','journalEntries'=>'journal_entries','journalLines'=>'journal_lines','journalApprovals'=>'journal_approvals','companyCurrencies'=>'company_currencies','accountingControls'=>'accounting_controls','vendors'=>'vendors','bills'=>'bills','partyPayments'=>'party_payments','openingBalanceImports'=>'opening_balance_imports','openingDocumentImports'=>'opening_document_imports','payrollSettings'=>'payroll_settings','payrollEmployees'=>'payroll_employees','payrollRuns'=>'payroll_runs','payrollRunItems'=>'payroll_run_items','payrollRemittances'=>'payroll_remittances','expenses'=>'expenses','statementPreviews'=>'statement_previews','periodLocks'=>'period_locks','reconciliations'=>'reconciliations','reconciliationEvents'=>'reconciliation_events','reconciliationItems'=>'reconciliation_items','bankMatchGroups'=>'bank_match_groups','bankMatchBankItems'=>'bank_match_bank_items','bankMatchBookItems'=>'bank_match_book_items','reconciliationMatchGroups'=>'reconciliation_match_groups','reconciliationMatchProposals'=>'reconciliation_match_proposals','reconciliationResumeLog'=>'reconciliation_resume_log','categoryRules'=>'category_rules','aiRuns'=>'ai_runs','aiAgentIncidents'=>'ai_agent_incidents','aiAgentWorkflowSessions'=>'ai_agent_workflow_sessions','aiAgentPreferences'=>'ai_agent_preferences','aiAgentLearningEvents'=>'ai_agent_learning_events','aiAgentLearnedRules'=>'ai_agent_learned_rules','aiAgentImprovements'=>'ai_agent_improvements','aiAgentBehaviorVersions'=>'ai_agent_behavior_versions','aiAgentReviewRuns'=>'ai_agent_review_runs','aiAgentConversations'=>'ai_agent_conversations','aiAgentMemories'=>'ai_agent_memories','aiAgentMemoryHistory'=>'ai_agent_memory_history','aiAgentPlans'=>'ai_agent_plans','analyticAccounts'=>'analytic_accounts','analyticAllocations'=>'analytic_allocations','budgets'=>'budgets','budgetLines'=>'budget_lines','financialScenarios'=>'financial_scenarios','financialScenarioAdjustments'=>'financial_scenario_adjustments','fixedAssets'=>'fixed_assets','assetDepreciationLines'=>'asset_depreciation_lines','recurringJournalTemplates'=>'recurring_journal_templates','recurringJournalLines'=>'recurring_journal_lines','recurringInvoiceProfiles'=>'recurring_invoice_profiles','recurringInvoiceLines'=>'recurring_invoice_lines','invoiceFollowups'=>'invoice_followups','cashFlowMappings'=>'cash_flow_mappings','statutoryRates'=>'statutory_rates','employerLevyProfiles'=>'employer_levy_profiles','employerLevyRates'=>'employer_levy_rates','payrollJournalDrafts'=>'payroll_journal_drafts','payrollVerifications'=>'payroll_verifications','voucherSequences'=>'voucher_sequences','transactionSequences'=>'transaction_sequences','vouchers'=>'vouchers','voucherDraftPayloads'=>'voucher_draft_payloads','notifications'=>'notifications','companyInvitations'=>'company_invitations','productsServices'=>'products_services','agingProfiles'=>'aging_profiles','outboundEmails'=>'outbound_emails','nativeAgentPolicies'=>'native_agent_policies','nativeAgentAutonomyPolicies'=>'native_agent_autonomy_policies','nativeAgentRuns'=>'native_agent_runs','nativeAgentRunFailures'=>'native_agent_run_failures','nativeAgentFindings'=>'native_agent_findings','nativeAgentAutonomyRuns'=>'native_agent_autonomy_runs','nativeAgentDocuments'=>'native_agent_documents','vendorRecognitionRules'=>'vendor_recognition_rules','vendorRecognitionHistory'=>'vendor_recognition_history','nativeAgentCollectionDrafts'=>'native_agent_collection_drafts','supportRequests'=>'support_requests','officialRateReleases'=>'official_rate_releases','auditIntegrityRuns'=>'audit_integrity_runs','backupRestoreLog'=>'backup_restore_log','auditLog'=>'audit_log'
    ];
    $map['outboundEmailAttempts'] = 'outbound_email_attempts';
    $map['collectionMessageTemplates'] = 'collection_message_templates';
    $map['monthEndAttestations'] = 'month_end_attestations';
    $map['companySystemAccounts']='company_system_accounts';
    $map['openingBalanceDrafts']='opening_balance_drafts';
    $map['partyOpeningBalances']='party_opening_balances';
    $map['recurringBillProfiles']='recurring_bill_profiles';
    $map['partyPaymentApplicationOperations']='party_payment_application_operations';
    $map['partyPaymentApplications']='party_payment_applications';
    $map['paymentLegacyMappingReviews']='payment_legacy_mapping_reviews';
    $map['interbankTransfers']='interbank_transfers';
    $map['interbankTransferLegs']='interbank_transfer_legs';
    $map['interbankTransferOperations']='interbank_transfer_operations';
    $map['bankStatementBalanceAnchors']='bank_statement_balance_anchors';
    $map['bankImportControlReceipts']='bank_import_control_receipts';
    foreach(['invoiceDocumentOperations'=>'invoice_document_operations','invoiceAttachments'=>'invoice_attachments'] as $key=>$table)
        if(current_database_schema_version()>=46||schema_table_exists($table))$map[$key]=$table;
    if(schema_table_exists('accounting_notes'))$map['accountingNotes']='accounting_notes';
    if(schema_table_exists('accounting_note_settlements'))$map['accountingNoteSettlements']='accounting_note_settlements';
    if(schema_table_exists('company_invoice_tax_presets'))$map['companyInvoiceTaxPresets']='company_invoice_tax_presets';
    if(schema_table_exists('tax_codes'))$map['taxCodes']='tax_codes';
    if(schema_table_exists('tax_code_components'))$map['taxCodeComponents']='tax_code_components';
    if(schema_table_exists('document_tax_lines'))$map['documentTaxLines']='document_tax_lines';
    if(schema_table_exists('bill_lines'))$map['billLines']='bill_lines';
    if(schema_table_exists('accounting_note_lines'))$map['accountingNoteLines']='accounting_note_lines';
    if(schema_table_exists('vendor_document_memory'))$map['vendorDocumentMemory']='vendor_document_memory';
    return $map;
}

function backup_insert_row(string $table,array $row,string $companyId,string $userId): void
{
    if($row===[])return;$available=backup_table_columns($table);if($available===[])throw new RuntimeException('Restore target table is missing: '.$table);
    if($table==='journal_entries'&&isset($available['content_hash'])&&!array_key_exists('content_hash',$row))$row['content_hash']=str_repeat('0',64);
    $userColumns=['user_id','created_by','updated_by','approved_by','verified_by','run_by','initiated_by','invited_by','accepted_by','posted_by','actor_user_id','prepared_by','reviewed_by','reopened_by','locked_by','unlocked_by','reversed_by','gl_posted_by','matched_by','unreconciled_by','test_created_by','checked_by','performed_by','requested_by','assigned_to','assigned_user_id','changed_by','uploaded_by','actor_id','removed_by','linked_by','dismissed_by','snoozed_by','resolved_by','recovered_by','attested_by','voided_by','hold_by','released_by','suspended_by'];
    if($table==='payroll_employees'){unset($row['sin_ciphertext'],$row['sin_last_four']);} // R130: never restore SINs from older backups.
    if(array_key_exists('company_id',$row))$row['company_id']=$companyId;
    foreach($userColumns as $column)if(array_key_exists($column,$row)&&$row[$column]!==null&&$row[$column]!=='')$row[$column]=$userId;
    if(array_key_exists('actor_email',$row)){$stmt=db()->prepare('SELECT email FROM users WHERE id=?');$stmt->execute([$userId]);$row['actor_email']=(string)$stmt->fetchColumn();}
    $unknown=array_diff_key($row,$available);if($unknown!==[])throw new RuntimeException('Restore would discard unsupported columns in '.$table.': '.implode(', ',array_keys($unknown)));
    $columns=array_keys($row);$sql='INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';db()->prepare($sql)->execute(array_values($row));
}

function backup_prepare_native_restore_row(string $key,array $row): array
{
    if($key==='invoiceDocumentOperations' && $row['operation_type']==='send'){$row['status']='manual_review';$row['result_json']=json_encode(['status'=>'manual_review','message'=>'Restored copy: original delivery must be reviewed; never retried automatically.']);}
    if($key==='aiAgentConversations'){$row['active_task_id']=null;if(($row['status']??'')==='active')$row['status']='suspended';}
    if($key==='aiAgentPlans')$row['task_id']=null;
    if($key==='outboundEmails'&&in_array((string)($row['status']??''),['pending','deferred'],true)){$row['status']='manual_review';$row['provider_message']='Restored copy: review manually; no delivery was retried.';}
    if($key==='nativeAgentPolicies'){$row['cadence']='manual';$row['opportunistic_enabled']=0;$row['connected_enabled']=0;}
    if($key==='nativeAgentAutonomyPolicies'){$row['enabled']=0;$row['suspension_reason']='Restored copy: review and enable manually.';}
    if(in_array($key,['recurringBillProfiles','recurringInvoiceProfiles','recurringJournalTemplates'],true)&&array_key_exists('active',$row))$row['active']=0;
    if($key==='supportRequests'){$row['grant_access']=0;$row['access_expires_at']=null;}

    if($key==='nativeAgentRuns'&&(string)($row['status']??'')==='running'){
        $row['status']='interrupted';$row['lease_owner']='restored';$row['lease_expires_at']=gmdate('Y-m-d H:i:s');$row['completed_at']=$row['completed_at']??gmdate('Y-m-d H:i:s');$row['error_code']='restored_interrupted';$row['error_message']='An in-progress Native Agent run was restored conservatively.';
    }
    if($key==='nativeAgentRuns'&&empty($row['lease_renewed_at']))$row['lease_renewed_at']=$row['started_at']??$row['created_at']??gmdate('Y-m-d H:i:s');
    if($key==='nativeAgentFindings'){
        $row['accepted_task_id']=null;$row['accepted_at']=null;$row['accepted_by']=null;
    }
    if($key==='nativeAgentDocuments'){
        $row['prepared_task_id']=null;
        if((string)($row['state']??'')==='prepared'){$row['state']='ready';$row['prepared_by']=null;$row['prepared_at']=null;}
    }
    if($key==='nativeAgentCollectionDrafts'){
        if((string)($row['state']??'')==='approved'){$row['state']='draft';$row['approved_by']=null;$row['approved_at']=null;}
        elseif((string)($row['state']??'')==='sending'){$row['state']='stale';$row['failure_message']='Restored from an ambiguous in-flight delivery. Review the outbound email evidence; this draft cannot be resent.';$row['approved_by']=null;$row['approved_at']=null;}
    }
    return $row;
}

function backup_rehash_journal_entries(string $companyId): void
{
    if(!schema_column_exists('journal_entries','content_hash')||!function_exists('journal_entry_content_hash'))return;
    $entries=db()->prepare('SELECT id,entry_date,memo FROM journal_entries WHERE company_id=? ORDER BY id');$entries->execute([$companyId]);
    $lines=db()->prepare('SELECT account_id,debit_cents,credit_cents,memo,fx_rounding_cents FROM journal_lines WHERE journal_entry_id=? ORDER BY id');
    $update=db()->prepare('UPDATE journal_entries SET content_hash=? WHERE id=? AND company_id=?');
    foreach($entries->fetchAll() as $entry){$lines->execute([(string)$entry['id']]);$update->execute([journal_entry_content_hash((string)$entry['entry_date'],(string)$entry['memo'],$lines->fetchAll()),(string)$entry['id'],$companyId]);}
}

function handle_backup_restore(): never
{
    // R151: line tables are created on first use; make sure they exist before export/restore.
    if(function_exists('note_lines_ready'))note_lines_ready();if(function_exists('r151_schema_ready'))r151_schema_ready();if(function_exists('r152_schema_ready'))r152_schema_ready();
    require_method('POST');require_csrf();$user=require_user();
    $selectedCompanyId=trim((string)($_SERVER['HTTP_X_COMPANY_ID']??$_GET['companyId']??''));
    if($selectedCompanyId!==''){$membership=db()->prepare("SELECT role FROM company_members WHERE company_id=? AND user_id=? AND status='active'");$membership->execute([$selectedCompanyId,$user['id']]);if($membership->fetchColumn()!=='owner')fail('Only the Company Owner can restore a Tegh backup.',403,'role_forbidden');}
    if(!class_exists('ZipArchive'))fail('The server ZIP extension is required for Tegh restore.',503,'zip_unavailable');
    if(!isset($_FILES['backup'])||!is_array($_FILES['backup']))fail('Choose a Tegh backup file.');$file=$_FILES['backup'];$name=(string)($file['name']??'');
    if(!in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),['tegh','srbooks'],true))fail('Choose a .tegh backup file.',415,'backup_extension_invalid');
    if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)$file['tmp_name']))fail('The backup upload did not complete.',400,'backup_upload_failed');
    if((int)($file['size']??0)<=0||(int)$file['size']>250*1024*1024)fail('The backup file must be smaller than 250 MB.',413,'backup_size_invalid');
    $zip=new ZipArchive();if($zip->open((string)$file['tmp_name'],ZipArchive::CHECKCONS)!==true)fail('The selected file is not a valid Tegh backup.',415,'backup_archive_invalid');
    try{
        try{$verified=backup_verify_archive($zip);}catch(RuntimeException){fail('The backup failed its integrity check. Use the original signed .tegh archive from this installation and try again.',422,'backup_integrity_invalid',false);}$payload=$verified['payload'];$sourceId=(string)$payload['company']['id'];
        $exists=db()->prepare('SELECT id FROM companies WHERE id=?');$exists->execute([$sourceId]);
        if($exists->fetchColumn()!==false){$owner=db()->prepare("SELECT role FROM company_members WHERE company_id=? AND user_id=? AND status='active'");$owner->execute([$sourceId,$user['id']]);if($owner->fetchColumn()!=='owner'){$zip->close();fail('You must own the source company to restore a copy.',403,'role_forbidden');}}
        else{$ownerEmails=[];foreach($payload['companyMembers']??[] as $member)if(($member['role']??'')==='owner')$ownerEmails[]=strtolower((string)($member['email']??''));if($ownerEmails===[]&&!empty($payload['createdBy']['email']))$ownerEmails[]=strtolower((string)$payload['createdBy']['email']);if(!in_array(strtolower((string)$user['email']),$ownerEmails,true)){$zip->close();fail('Sign in as an owner recorded in this backup to recover the company.',403,'backup_owner_required');}}
        $result=backup_restore_verified_payload($payload,$user,$zip);
    }catch(Throwable $error){$zip->close();throw $error;}
    $zip->close();json_response($result,201);
}

/** Records deliberately excluded from a portable company copy. Never restore live access grants. */
function backup_exclusions(): array
{
    return [
        'sessions/password_reset_tokens/app_config'=>'Authentication sessions, reset links and installation secrets are never exported.',
        'ai_agent_action_authorizations/ai_agent_result_sets/ai_agent_tasks/data_import_previews'=>'Temporary previews, execution confirmations and in-flight tasks must be prepared again.',
        'feature_entitlements/entitlement_requests/entitlement_subjects/signup_feature_intents/feature_usage_daily'=>'Platform access policy and usage remain owned by the installation and are not cloned.',
        'platform_incident_log/client_error_events'=>'Installation diagnostics are retained in the separate server recovery backup.',
        'companyMembers'=>'The historical member list is evidence only; the restored company initially grants access only to the restoring owner.',
    ];
}

function backup_table_columns(string $table): array
{
    static $cache=[];$cacheKey=spl_object_id(db()).':'.$table;if(isset($cache[$cacheKey]))return $cache[$cacheKey];
    // Generated columns (for example tax_codes.active_region) are computed by the database and are never restored.
    $stmt=db()->prepare("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND (EXTRA IS NULL OR (EXTRA NOT LIKE '%GENERATED%' AND EXTRA NOT LIKE '%VIRTUAL%' AND EXTRA NOT LIKE '%STORED%' AND EXTRA NOT LIKE '%PERSISTENT%'))");
    $stmt->execute([$table]);return $cache[$cacheKey]=array_fill_keys(array_map(static fn(array $row):string=>(string)($row['COLUMN_NAME']??$row['column_name']),$stmt->fetchAll()),true);
}

/** A consistent InnoDB snapshot; a missing collection is an error, never an empty success. */
function backup_capture_snapshot(array $company): array
{
    $pdo=db();if($pdo->inTransaction())throw new RuntimeException('A backup cannot start inside another operation.');
    $tables=backup_restore_map();foreach($tables as $table)if(!schema_table_exists($table))throw new RuntimeException('Backup needs the database capability '.$table.'. Complete the protected database upgrade before retrying.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$company['id']]);$companyRow=$stmt->fetch();if(!$companyRow)throw new RuntimeException('The company is no longer available.');
        $records=[];$counts=[];foreach(backup_record_queries() as $key=>$sql){$records[$key]=backup_rows($sql,(string)$company['id']);$counts[$key]=count($records[$key]);}
        // R130: backups never carry Social Insurance Numbers.
        foreach($records['payrollEmployees']??[] as $i=>$row){unset($records['payrollEmployees'][$i]['sin_ciphertext'],$records['payrollEmployees'][$i]['sin_last_four']);}
        $members=backup_rows("SELECT cm.company_id,cm.user_id,cm.role,cm.status,cm.created_at,u.email,u.display_name FROM company_members cm JOIN users u ON u.id=cm.user_id WHERE cm.company_id=? AND cm.status='active' ORDER BY u.email",(string)$company['id']);
        $schema=current_database_schema_version();$pdo->commit();return ['company'=>$companyRow,'records'=>$records,'recordCounts'=>$counts,'companyMembers'=>$members,'schemaVersion'=>$schema];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function backup_evidence_specs(): array
{
    return [['importBatches','source_path','bank_statement'],['statementPreviews','source_path','statement_preview'],['expenses','receipt_path','expense_receipt'],['nativeAgentDocuments','storage_path','native_document'],['payrollVerifications','evidence_path','payroll_verification'],['invoiceAttachments','storage_path','invoice_attachment']];
}

/** Only company-owned files are eligible. Failure is explicit before an archive is downloaded. */
function backup_collect_evidence(array $records,string $companyId): array
{
    $evidence=[];$seen=[];
    foreach(backup_evidence_specs() as [$key,$column,$kind])foreach($records[$key]??[] as $row){
        $relative=(string)($row[$column]??'');if($relative==='')continue;
        if(!backup_safe_evidence_path($relative,$companyId))throw new RuntimeException('A supporting file has an invalid company reference. Backup was stopped.');
        if(isset($seen[$relative]))continue;$seen[$relative]=true;
        $item=backup_evidence_item($kind,(string)$row['id'],$relative);
        if(!$item||$item['status']!=='present'||!is_int($item['sizeBytes'])||!is_string($item['sha256']))throw new RuntimeException('A required supporting file is missing or unreadable ('.$kind.', record '.(string)$row['id'].'). Restore the file before creating a complete backup.');
        $item['archivePath']='evidence/'.count($evidence).'/'.basename($relative);$evidence[]=$item;
    }
    return $evidence;
}

function backup_safe_evidence_path(string $relative,string $companyId): bool
{
    if($relative===''||str_contains($relative,"\0")||str_contains($relative,'\\')||str_contains($relative,'..'))return false;
    if(!str_starts_with($relative,$companyId.'/'))return false;
    return in_array(strtolower(pathinfo($relative,PATHINFO_EXTENSION)),['csv','txt','ofx','qfx','pdf','xls','xlsx','jpg','jpeg','png','webp'],true);
}

function backup_write_archive(array $payload,string $path): array
{
    $sha=hash('sha256',backup_canonical_json($payload));$evidence=$payload['evidenceManifest']??[];
    $manifest=['format'=>'tegh-backup','formatVersion'=>2,'applicationVersion'=>SR_ACCOUNTAX_VERSION,'schemaVersion'=>$payload['schemaVersion'],'schemaTarget'=>SR_BACKUP_SCHEMA_VERSION,'backupId'=>$payload['backupId'],'createdAtUtc'=>$payload['createdAtUtc'],'payloadFile'=>'payload.json','payloadSha256'=>$sha,'signatureAlgorithm'=>'HMAC-SHA256','signature'=>backup_signature($sha),'companyId'=>$payload['company']['id'],'companyName'=>$payload['company']['name'],'evidenceCount'=>count($evidence),'completeness'=>'company-records-and-referenced-files','excludedCollections'=>backup_exclusions()];
    $zip=new ZipArchive();$opened=false;
    try{
        if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Backup archive could not be created. Check temporary disk space.');$opened=true;
        foreach(['manifest.json'=>$manifest,'payload.json'=>$payload] as $name=>$value)if(!$zip->addFromString($name,json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)))throw new RuntimeException('Backup data could not be added to the archive.');
        foreach($evidence as $item){$absolute=private_absolute_path((string)$item['relativePath']);if($absolute===null||!$zip->addFile($absolute,(string)$item['archivePath']))throw new RuntimeException('A required supporting file could not be archived.');}
        if(!$zip->close())throw new RuntimeException('The backup archive could not be completed. Check temporary disk space.');$opened=false;
        $check=new ZipArchive();if($check->open($path,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('The written backup archive could not be verified.');
        try{backup_verify_archive($check);}finally{$check->close();}
        return $manifest;
    }catch(Throwable $error){if($opened)@$zip->close();@unlink($path);throw $error;}
}

/** Verify signed records, counts, ownership and every evidence byte before writes begin. */
function backup_verify_archive(ZipArchive $zip): array
{
    $uncompressed=0;$names=[];for($i=0;$i<$zip->numFiles;$i++){$stat=$zip->statIndex($i);if(!$stat)throw new RuntimeException('A backup member could not be inspected.');$name=(string)$stat['name'];if(isset($names[$name]))throw new RuntimeException('The backup contains duplicate archive paths.');$names[$name]=true;$uncompressed+=(int)$stat['size'];if($uncompressed>750*1024*1024)throw new RuntimeException('The expanded backup exceeds the supported 750 MB limit.');}
    foreach(['manifest.json'=>1024*1024,'payload.json'=>250*1024*1024] as $name=>$limit){$stat=$zip->statName($name);if(!$stat||(int)$stat['size']>$limit)throw new RuntimeException('The backup manifest or payload is missing or exceeds its size limit.');}
    // R139: a damaged archive must be reported as a failed integrity check, not a server error.
    try{$manifest=json_decode((string)$zip->getFromName('manifest.json'),true,128,JSON_THROW_ON_ERROR);$payload=json_decode((string)$zip->getFromName('payload.json'),true,512,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('The backup manifest or payload is damaged.');}
    if(!is_array($manifest)||!is_array($payload)||!tegh_backup_format_supported($manifest['format']??'')||(int)($manifest['formatVersion']??0)!==2||!tegh_backup_format_supported($payload['format']??'')||(int)($payload['formatVersion']??0)!==2)throw new RuntimeException('This is not a supported Tegh backup.');
    $sha=hash('sha256',backup_canonical_json($payload));if(!hash_equals((string)($manifest['payloadSha256']??''),$sha)||!hash_equals((string)($manifest['signature']??''),backup_signature($sha)))throw new RuntimeException('Backup signature verification failed. The file was changed or belongs to a different installation.');
    $company=$payload['company']??[];$sourceId=(string)($company['id']??'');if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$sourceId)||empty($company['name']))throw new RuntimeException('The company identity is invalid.');
    if((string)($manifest['companyId']??'')!==$sourceId||($manifest['backupId']??null)!==($payload['backupId']??null)||(int)($manifest['schemaVersion']??0)!==(int)($payload['schemaVersion']??0))throw new RuntimeException('The backup manifest does not agree with its signed payload.');
    if(!is_array($payload['records']??null)||!is_array($payload['recordCounts']??null)||!is_array($payload['evidenceManifest']??null))throw new RuntimeException('The backup records or file manifest are invalid.');
    $map=backup_restore_map();foreach($payload['records'] as $key=>$rows){if(!isset($map[$key])||!is_array($rows)||!array_is_list($rows))throw new RuntimeException('The backup contains an unsupported collection: '.(string)$key);if(!array_key_exists($key,$payload['recordCounts'])||(int)$payload['recordCounts'][$key]!==count($rows))throw new RuntimeException('Record count verification failed for '.$key);foreach($rows as $row){if(!is_array($row))throw new RuntimeException('A record is invalid in '.$key);if(array_key_exists('company_id',$row)&&(string)$row['company_id']!==$sourceId)throw new RuntimeException('The backup includes a record belonging to another company.');}}
    foreach($payload['recordCounts'] as $key=>$count)if(!array_key_exists($key,$payload['records'])||!is_int($count)||$count<0)throw new RuntimeException('The backup count manifest is inconsistent.');
    $files=[];foreach($payload['evidenceManifest'] as $item){if(!is_array($item)||($item['status']??'')!=='present')throw new RuntimeException('The backup records a missing required supporting file.');$relative=(string)($item['relativePath']??'');$archive=(string)($item['archivePath']??'');if(!backup_safe_evidence_path($relative,$sourceId)||!str_starts_with($archive,'evidence/')||str_contains($archive,'..')||str_contains($archive,'\\')||isset($files[$relative]))throw new RuntimeException('The supporting file manifest contains an unsafe or duplicate path.');$contents=$zip->getFromName($archive);if(!is_string($contents)||strlen($contents)!==(int)($item['sizeBytes']??-1)||!hash_equals((string)($item['sha256']??''),hash('sha256',$contents)))throw new RuntimeException('A supporting file is missing, truncated or corrupted.');$files[$relative]=true;}
    if(count($payload['evidenceManifest'])!==(int)($manifest['evidenceCount']??-1))throw new RuntimeException('The supporting file count does not agree with its manifest.');
    foreach(backup_evidence_specs() as [$key,$column,$kind])foreach($payload['records'][$key]??[] as $row){$relative=(string)($row[$column]??'');if($relative!==''&&!isset($files[$relative]))throw new RuntimeException('A referenced '.$kind.' file is absent from the backup manifest.');}
    return ['manifest'=>$manifest,'payload'=>$payload,'payloadSha256'=>$sha];
}

function backup_restore_foreign_keys(): array
{
    $rows=db()->query('SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION')->fetchAll();$keys=[];
    foreach($rows as $row){$table=(string)$row['TABLE_NAME'];$name=(string)$row['CONSTRAINT_NAME'];$keys[$table][$name]['table']=(string)$row['REFERENCED_TABLE_NAME'];$keys[$table][$name]['columns'][(string)$row['COLUMN_NAME']]=(string)$row['REFERENCED_COLUMN_NAME'];}return $keys;
}

function backup_fk_values(array $row,array $reference): ?array
{
    $values=[];foreach($reference['columns'] as $column=>$target){if(!array_key_exists($column,$row)||$row[$column]===null)return null;$values[$target]=(string)$row[$column];}return $values;
}

/** Topological ordering preserves foreign keys; never disable foreign_key_checks. */
function backup_restore_order(array $records,array $foreignKeys): array
{
    $map=backup_restore_map();$pending=[];foreach($map as $key=>$table)if(!empty($records[$key]))$pending[$table]=$key;$ordered=[];
    while($pending!==[]){$progress=false;foreach($pending as $table=>$key){$blocked=false;foreach($foreignKeys[$table]??[] as $ref){if($ref['table']===$table||!isset($pending[$ref['table']]))continue;foreach($records[$key] as $row)if(backup_fk_values($row,$ref)!==null){$blocked=true;break 2;}}if($blocked)continue;$ordered[$key]=$table;unset($pending[$table]);$progress=true;}if(!$progress)throw new RuntimeException('Restore found a circular record dependency. No company was created; ask support to inspect the backup.');}
    return $ordered;
}

function backup_clone_payload(array $payload,string $newCompanyId): array
{
    $sourceId=(string)$payload['company']['id'];$ids=[$sourceId=>$newCompanyId];
    foreach($payload['records'] as $rows)foreach($rows as $row){foreach(['id','source_id'] as $column){$id=$row[$column]??null;if(is_string($id)&&$id!==''&&!isset($ids[$id]))$ids[$id]=new_id('rest');}}
    $remap=function(mixed $value)use(&$remap,$ids,$sourceId,$newCompanyId):mixed{if(is_array($value)){foreach($value as $key=>$item)$value[$key]=$remap($item);return $value;}if(!is_string($value))return $value;if(isset($ids[$value]))return $ids[$value];if(str_starts_with($value,$sourceId.'/'))return $newCompanyId.substr($value,strlen($sourceId));return $value;};
    $clone=$payload;$clone['company']=$remap($payload['company']);$clone['company']['name']=mb_substr((string)$payload['company']['name'].' (Restored copy)',0,160);
    foreach($payload['records'] as $key=>$rows)foreach($rows as $index=>$row){
        $original=$row;foreach($row as $column=>$value)if($column==='id'||str_ends_with((string)$column,'_id')||str_ends_with((string)$column,'_path'))$row[$column]=$remap($value);foreach($row as $column=>$value)if(is_string($value)&&str_ends_with((string)$column,'_json')&&!str_ends_with((string)$column,'snapshot_json')){try{$decoded=json_decode($value,true,512,JSON_THROW_ON_ERROR);$mapped=$remap($decoded);if($mapped!==$decoded)$row[$column]=json_encode($mapped,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}catch(JsonException){/* Preserve historic non-JSON payloads; schema validation still applies. */}}
        if($key==='outboundEmailAttempts')$row['operation_key']=hash('sha256',$newCompanyId.'|'.(string)$original['operation_key']);
        if($key==='reconciliationMatchProposals')$row['proposal_hash']=hash('sha256',$newCompanyId.'|'.(string)$original['proposal_hash']);
        if($key==='companyInvitations'){$row['token_hash']=hash('sha256',random_bytes(32));$row['status']='revoked';}
        if($key==='auditLog'){$meta=json_decode((string)($row['metadata_json']??'{}'),true);$meta=is_array($meta)?$meta:[];$meta['_restoreSource']=['companyId'=>$sourceId,'recordId'=>$original['id']??null,'actorUserId'=>$original['actor_user_id']??null,'actorEmail'=>$original['actor_email']??null,'entryHash'=>$original['entry_hash']??null];$row['metadata_json']=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$row['entry_hash']='';$row['previous_hash']='';$row['request_id']='restored';}
        $clone['records'][$key][$index]=backup_prepare_native_restore_row($key,$row);
    }
    foreach($payload['evidenceManifest'] as $index=>$item){$clone['evidenceManifest'][$index]=$item;$clone['evidenceManifest'][$index]['relativePath']=$remap($item['relativePath']);$clone['evidenceManifest'][$index]['recordId']=$remap($item['recordId']);}
    return $clone;
}

function backup_validate_restore_records(array $payload,array $foreignKeys): void
{
    $schema=(int)($payload['schemaVersion']??0);if($schema<1||$schema>current_database_schema_version()||$schema>SR_BACKUP_SCHEMA_VERSION)throw new RuntimeException('The backup needs a newer database schema. Upgrade this installation before restoring.');
    $map=backup_restore_map();$index=[];$indexedColumns=[];foreach($foreignKeys as $constraints)foreach($constraints as $reference)foreach($reference['columns'] as $target)$indexedColumns[$reference['table']][$target]=true;$sourceId=(string)$payload['company']['id'];$index['companies']['id'][$sourceId]=true;
    foreach($payload['records'] as $key=>$rows){$table=$map[$key];if($rows===[])continue;$columns=backup_table_columns($table);if($columns===[])throw new RuntimeException('The restore target is missing '.$table.'. No records were restored.');foreach($rows as $row){foreach($row as $column=>$value){if(!isset($columns[$column]))throw new RuntimeException('The restore target is missing '.$table.'.'.$column.'. No records were restored.');if(isset($indexedColumns[$table][$column])&&(is_string($value)||is_int($value)))$index[$table][$column][(string)$value]=true;}}}
    foreach($payload['records'] as $key=>$rows)foreach($rows as $row)foreach($foreignKeys[$map[$key]]??[] as $reference){$values=backup_fk_values(backup_prepare_native_restore_row($key,$row),$reference);if($values===null||$reference['table']==='users')continue;foreach($values as $column=>$value)if(!isset($index[$reference['table']][$column][$value]))throw new RuntimeException('A required related record is missing from '.$key.' ('.$column.'). Restore was stopped before any writes.');}

    $journals=[];foreach($payload['records']['journalEntries']??[] as $row)$journals[(string)$row['id']]=[0,0];foreach($payload['records']['journalLines']??[] as $line){$id=(string)$line['journal_entry_id'];if(!isset($journals[$id]))throw new RuntimeException('A journal line has no journal entry.');$debit=(int)$line['debit_cents'];$credit=(int)$line['credit_cents'];if($debit<0||$credit<0||($debit>0&&$credit>0))throw new RuntimeException('A journal line has invalid debit and credit values.');$journals[$id][0]+=$debit;$journals[$id][1]+=$credit;}foreach($journals as $totals)if($totals[0]!==$totals[1])throw new RuntimeException('An unbalanced journal was found. Restore was stopped before any writes.');
    backup_restore_order($payload['records'],$foreignKeys);
}

function backup_rehash_audit_company(string $companyId): void
{
    if(!schema_column_exists('audit_log','entry_hash'))return;$stmt=db()->prepare('SELECT * FROM audit_log WHERE company_id=? ORDER BY created_at,id');$stmt->execute([$companyId]);$previous='';$update=db()->prepare('UPDATE audit_log SET previous_hash=?,entry_hash=? WHERE id=? AND company_id=?');
    foreach($stmt->fetchAll() as $row){$hash=hash('sha256',implode('|',[$companyId,$previous,$row['id'],$row['actor_user_id'],$row['actor_email'],$row['action'],$row['entity_type'],$row['entity_id'],$row['metadata_json'],$row['request_id'],$row['created_at']]));$update->execute([$previous,$hash,$row['id'],$companyId]);$previous=$hash;}
}

/** Shared restore service used by HTTP and the isolated round-trip fixture. */
function backup_restore_verified_payload(array $payload,array $user,ZipArchive $zip): array
{
    $foreignKeys=backup_restore_foreign_keys();backup_validate_restore_records($payload,$foreignKeys);
    $companyId=new_id('company');$clone=backup_clone_payload($payload,$companyId);$writtenFiles=[];$createdDirectories=[];$order=backup_restore_order($clone['records'],$foreignKeys);$pdo=db();if($pdo->inTransaction())throw new RuntimeException('Restore cannot start inside another operation.');
    $pdo->beginTransaction();try{
        backup_insert_row('companies',$clone['company'],$companyId,(string)$user['id']);$pdo->prepare("INSERT INTO company_members (company_id,user_id,role) VALUES (?,?,'owner')")->execute([$companyId,$user['id']]);
        foreach($order as $key=>$table)foreach($clone['records'][$key] as $row)backup_insert_row($table,$row,$companyId,(string)$user['id']);
        backup_rehash_journal_entries($companyId);backup_rehash_audit_company($companyId);
        foreach($clone['evidenceManifest'] as $item){$contents=$zip->getFromName((string)$item['archivePath']);if(!is_string($contents)||!hash_equals((string)$item['sha256'],hash('sha256',$contents)))throw new RuntimeException('Stored evidence verification failed.');$root=private_storage_root();$dest=$root.'/'.$item['relativePath'];$dir=dirname($dest);if(file_exists($dest))throw new RuntimeException('A restored supporting file already exists.');if(!is_dir($dir)){if(!is_dir($root.'/'.$companyId))$createdDirectories[]=$root.'/'.$companyId;if(!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Stored evidence directory could not be created.');$createdDirectories[]=$dir;}$handle=@fopen($dest,'xb');if($handle===false)throw new RuntimeException('A supporting file could not be created.');$writtenFiles[]=$dest;try{$offset=0;$length=strlen($contents);while($offset<$length){$written=fwrite($handle,substr($contents,$offset));if($written===false||$written===0)throw new RuntimeException('A supporting file could not be written completely.');$offset+=$written;}if(!fflush($handle)||(function_exists('fsync')&&!fsync($handle)))throw new RuntimeException('A supporting file could not be safely flushed.');}finally{fclose($handle);}if(!chmod($dest,0600))throw new RuntimeException('A supporting file could not be protected.');}
        if(function_exists('tegh_company_entitlements_initialize'))tegh_company_entitlements_initialize($user,$companyId,(string)$clone['company']['name'],false);
        $sha=hash('sha256',backup_canonical_json($payload));$details=['sourceCompanyId'=>$payload['company']['id'],'sourceVersion'=>$payload['applicationVersion']??null,'recordCounts'=>$payload['recordCounts'],'restoredMemberCount'=>1,'previousMembersNeedReview'=>count($payload['companyMembers']??[]),'safetyChanges'=>'New company identity; owner access only; invitations revoked; automation paused; pending email requires manual review; original actor evidence retained in signed archive.'];
        $pdo->prepare('INSERT INTO backup_restore_log (id,company_id,backup_id,action,filename,payload_sha256,performed_by,details_json) VALUES (?,?,?,?,?,?,?,?)')->execute([new_id('restorelog'),$companyId,$payload['backupId'],'restore','verified-backup.tegh',$sha,$user['id'],json_encode($details,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        audit_event($user,$companyId,'backup.restored','company_backup',(string)$payload['backupId'],['payloadSha256'=>$sha,'filename'=>'verified-backup.tegh','restoredMemberCount'=>1,'membersNeedingInvitation'=>count($payload['companyMembers']??[]),'sourceCompanyId'=>$payload['company']['id']]);$pdo->commit();
        return ['restored'=>true,'companyId'=>$companyId,'companyName'=>$clone['company']['name'],'payloadSha256'=>$sha,'restoredMemberCount'=>1,'membersNeedingInvitation'=>count($payload['companyMembers']??[]),'warnings'=>['Review company users, recurring work and automation settings before using this restored copy.']];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();foreach(array_reverse($writtenFiles) as $file)@unlink($file);foreach(array_reverse($createdDirectories) as $directory)@rmdir($directory);throw $error;}
}
