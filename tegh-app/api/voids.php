<?php
declare(strict_types=1);

/**
 * Voiding posted transactions.
 *
 * A posted transaction is never removed from the database. Voiding sets its
 * journal entry to status='reversed', which every balance, ledger, trial
 * balance, financial statement and tax figure already excludes - each of those
 * queries filters on je.status='posted'. The rows stay exactly where they are,
 * so the Day Book and the audit trail can still show what was posted, by whom,
 * when it was voided and why.
 *
 * This is deliberately not a DELETE. Deleting posted rows would break the audit
 * hash chain, remove records that must be retained for six years, and silently
 * change figures behind reconciliations and filed tax periods. Voiding achieves
 * the same visible result - the transaction leaves the financial reports -
 * while the evidence survives.
 */

/** @return array<string,array{table:string,label:string,statusColumn:?string,voidStatus:?string}> */
function void_entity_map(): array
{
    return [
        'invoice' => ['table' => 'invoices', 'label' => 'Customer invoice', 'statusColumn' => 'status', 'voidStatus' => 'void'],
        'bill' => ['table' => 'bills', 'label' => 'Vendor bill', 'statusColumn' => 'status', 'voidStatus' => 'void'],
        'expense' => ['table' => 'expenses', 'label' => 'Expense', 'statusColumn' => 'status', 'voidStatus' => 'void'],
        'payment' => ['table' => 'party_payments', 'label' => 'Payment', 'statusColumn' => 'status', 'voidStatus' => 'reversed'],
        'journal' => ['table' => 'journal_entries', 'label' => 'Manual journal entry', 'statusColumn' => null, 'voidStatus' => null],
        'bank_transaction' => ['table' => 'bank_transactions', 'label' => 'Bank transaction', 'statusColumn' => 'status', 'voidStatus' => 'excluded'],
    ];
}

/** @return array<string,array<string,array<int,string>>> */
function transaction_status_transition_catalogue(): array
{
    return [
        'invoice'=>['draft'=>['sent','void'],'sent'=>['paid','void'],'paid'=>['sent'],'void'=>['sent']],
        'bill'=>['draft'=>['submitted_for_approval','open','void'],'submitted_for_approval'=>['approved','draft'],'approved'=>['open','draft'],'open'=>['paid','void'],'paid'=>['open'],'void'=>['open']],
        'payment'=>['posted'=>['reversed'],'reversed'=>['posted']],
        'bank_transaction'=>['pending'=>['posted','excluded','duplicate'],'excluded'=>['pending'],'posted'=>['pending','excluded']],
        'journal'=>['pending_post'=>['posted'],'posted'=>['reversed'],'reversed'=>['posted']],
        'expense'=>['posted'=>['void'],'void'=>['posted']],
    ];
}

function transaction_assert_status_transition(string $entityType,string $from,string $to): void
{
    $allowed=false;
    if(function_exists('schema_table_exists')&&schema_table_exists('transaction_status_transitions')){
        $stmt=db()->prepare('SELECT 1 FROM transaction_status_transitions WHERE entity_type=? AND from_status=? AND to_status=? AND active=1 LIMIT 1');
        $stmt->execute([$entityType,$from,$to]);$allowed=$stmt->fetchColumn()!==false;
    }else $allowed=in_array($to,transaction_status_transition_catalogue()[$entityType][$from]??[],true);
    if(!$allowed)fail('That transaction status change is not allowed. Refresh the record and use its available action.',409,'transaction_status_transition_invalid');
}

function void_transaction_amount(array $row,array $entries): int
{
    foreach(['total_cents','amount_cents'] as $field)if(isset($row[$field]))return abs((int)$row[$field]);
    if(!$entries)return 0;$ids=implode(',',array_fill(0,count($entries),'?'));
    $stmt=db()->prepare("SELECT COALESCE(MAX(total),0) FROM (SELECT journal_entry_id,SUM(debit_cents) total FROM journal_lines WHERE journal_entry_id IN ($ids) GROUP BY journal_entry_id) amounts");
    $stmt->execute(array_column($entries,'id'));return abs((int)$stmt->fetchColumn());
}

function void_assert_cycle_limit(string $companyId,string $entityType,string $entityId): void
{
    if(!schema_table_exists('audit_log'))return;
    $stmt=db()->prepare("SELECT COUNT(*) FROM audit_log WHERE company_id=? AND entity_type=? AND entity_id=? AND action IN ('transaction.voided','transaction.void_restored','invoice.voided','bill.voided') AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 DAY");
    $stmt->execute([$companyId,$entityType,$entityId]);
    if((int)$stmt->fetchColumn()>=2)fail('This transaction already completed a void/restore cycle in the last 24 hours. Wait for the cooldown or post a reviewed correcting entry.',409,'void_restore_cooldown');
}

/**
 * Journal entries belonging to a document: the original posting plus any
 * reversal already raised against it. Both are voided together so a document
 * that was previously reversed does not leave an orphan contra entry behind in
 * the Day Book with nothing to offset it.
 *
 * @return array<int,array{id:string,entry_date:string,status:string}>
 */
function void_journal_entries_for(string $companyId, string $sourceType, string $sourceId): array
{
    $stmt = db()->prepare("SELECT id, entry_date, status FROM journal_entries
        WHERE company_id = ? AND ((source_type = ? AND source_id = ?) OR reversal_of_id IN (
            SELECT id FROM (SELECT id FROM journal_entries WHERE company_id = ? AND source_type = ? AND source_id = ?) AS original
        ))
        ORDER BY entry_date, created_at, id");
    $stmt->execute([$companyId, $sourceType, $sourceId, $companyId, $sourceType, $sourceId]);
    return array_map(static fn(array $row): array => [
        'id' => (string)$row['id'],
        'entry_date' => (string)$row['entry_date'],
        'status' => (string)$row['status'],
    ], $stmt->fetchAll());
}

/** Journal entries recorded directly against a journal entry id. */
function void_journal_entries_by_id(string $companyId, string $entryId): array
{
    $stmt = db()->prepare("SELECT id, entry_date, status FROM journal_entries
        WHERE company_id = ? AND (id = ? OR reversal_of_id = ?) ORDER BY entry_date, created_at, id");
    $stmt->execute([$companyId, $entryId, $entryId]);
    return array_map(static fn(array $row): array => [
        'id' => (string)$row['id'],
        'entry_date' => (string)$row['entry_date'],
        'status' => (string)$row['status'],
    ], $stmt->fetchAll());
}

/** The per-document adjustment used when an opening document shares a cutover batch. */
function void_opening_adjustment(string $companyId,string $entityType,string $entityId): ?array
{
    if(!in_array($entityType,['invoice','bill'],true))return null;
    $stmt=db()->prepare('SELECT id,entry_date,status FROM journal_entries WHERE company_id=? AND source_type=? AND source_id=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$companyId,'opening_'.$entityType.'_void',$entityId]);
    $row=$stmt->fetch();
    return $row?['id'=>(string)$row['id'],'entry_date'=>(string)$row['entry_date'],'status'=>(string)$row['status']]:null;
}

/**
 * Reasons this transaction cannot be voided. Returned as plain sentences so the
 * interface can show the person what to undo first rather than a code.
 *
 * @return array<int,string>
 */
function void_blockers(string $companyId, string $entityType, array $row, array $entries): array
{
    $blockers = [];

    // A closed period must not change. The figures behind it may already have
    // been reported or filed.
    foreach ($entries as $entry) {
        if (function_exists('period_lock_for_date') && period_lock_for_date($companyId, $entry['entry_date']) !== null) {
            $blockers[] = 'This transaction falls in a closed period (' . $entry['entry_date'] . '). Reopen the period first, or post a correcting entry in the current period instead.';
            break;
        }
    }

    // A reconciled bank line must not disappear from under a saved reconciliation.
    $bankTransactionId = null;
    if ($entityType === 'bank_transaction') $bankTransactionId = (string)$row['id'];
    elseif (array_key_exists('bank_transaction_id', $row) && $row['bank_transaction_id'] !== null) $bankTransactionId = (string)$row['bank_transaction_id'];
    if ($bankTransactionId !== null && $bankTransactionId !== '' && schema_table_exists('reconciliation_items')) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM reconciliation_items ri
            JOIN reconciliations r ON r.id = ri.reconciliation_id
            WHERE ri.bank_transaction_id = ? AND r.company_id = ?");
        $stmt->execute([$bankTransactionId, $companyId]);
        if ((int)$stmt->fetchColumn() > 0) {
            $blockers[] = 'This bank transaction is part of a saved reconciliation. Reopen that reconciliation before voiding it.';
        }
    }

    // A current match group is an accounting relationship even before the
    // statement reconciliation has been completed. Require an explicit
    // unmatch so the audit history records that the relationship was removed.
    if ($bankTransactionId !== null && $bankTransactionId !== '' && function_exists('active_bank_match_for_bank_transaction')
        && active_bank_match_for_bank_transaction($companyId, $bankTransactionId) !== null) {
        $blockers[] = 'This bank transaction is matched in Bank Reconciliation. Unreconcile or unmatch it before voiding it.';
    }

    // Both statement rows of an interbank transfer share one journal. The
    // generic single-row void path must not reverse that journal from under the
    // other leg; transfer reversal/unmatch is an explicit relationship action.
    if ($bankTransactionId !== null && $bankTransactionId !== '' && schema_table_exists('interbank_transfer_legs')) {
        $stmt=db()->prepare("SELECT COUNT(*) FROM interbank_transfer_legs l JOIN interbank_transfers t ON t.id=l.transfer_id AND t.company_id=l.company_id WHERE l.company_id=? AND l.bank_transaction_id=? AND l.status='linked'");
        $stmt->execute([$companyId,$bankTransactionId]);
        if((int)$stmt->fetchColumn()>0)$blockers[]='This statement row belongs to an interbank transfer. Use the interbank transfer workflow to unmatch or reverse the complete relationship.';
    }

    // The same rule applies to book-side journals (customer/vendor payments,
    // expenses paid from bank, payroll payments and manual bank journals).
    if (function_exists('active_bank_match_for_journal')) {
        foreach ($entries as $entry) {
            if (active_bank_match_for_journal($companyId, (string)$entry['id']) !== null) {
                $blockers[] = 'This accounting entry is matched in Bank Reconciliation. Unreconcile or unmatch it before voiding or reversing the source transaction.';
                break;
            }
        }
    }

    // A document that has money applied to it must have the money undone first,
    // otherwise the payment would point at a document that no longer exists in
    // the reports.
    if (in_array($entityType, ['invoice', 'bill'], true) && schema_table_exists('party_payments')) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM party_payments WHERE company_id = ? AND document_id = ? AND status = 'posted'");
        $stmt->execute([$companyId, (string)$row['id']]);
        $applied = (int)$stmt->fetchColumn();
        if ($applied > 0) {
            $blockers[] = $applied . ' payment' . ($applied === 1 ? '' : 's') . ' still applied to this document. Void the payment' . ($applied === 1 ? '' : 's') . ' first, then void this.';
        }
    }

    // Payroll postings are voided through Payroll History so the run, its items
    // and its remittances stay consistent with each other.
    foreach ($entries as $entry) {
        $stmt = db()->prepare("SELECT source_type FROM journal_entries WHERE id = ? LIMIT 1");
        $stmt->execute([$entry['id']]);
        if (str_starts_with((string)$stmt->fetchColumn(), 'payroll')) {
            $blockers[] = 'Payroll postings are reversed from Payroll History so the pay run stays consistent with its remittances.';
            break;
        }
    }

    return array_values(array_unique($blockers));
}

/** Load the target row and the journal entries that carry it to the ledger. */
function void_load_target(string $companyId, string $entityType, string $entityId): array
{
    $map = void_entity_map();
    if (!isset($map[$entityType])) fail('Choose a transaction type that can be voided.', 422, 'void_entity_invalid');
    $spec = $map[$entityType];
    if (!schema_table_exists($spec['table'])) fail('That transaction type is not available on this installation.', 404, 'void_entity_unavailable');

    $stmt = db()->prepare('SELECT * FROM `' . $spec['table'] . '` WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$entityId, $companyId]);
    $row = $stmt->fetch();
    if (!$row) fail('That transaction is no longer available.', 404, 'void_entity_not_found');
    if (in_array($entityType,['invoice','bill'],true) && schema_table_exists('accounting_notes')) {
        $note=db()->prepare("SELECT id FROM accounting_notes WHERE company_id=? AND status IN ('draft','posted') AND ((source_type=? AND source_id=?) OR debit_document_id=?) LIMIT 1");
        $note->execute([$companyId,$entityType,$entityId,$entityId]);
        if($note->fetchColumn()!==false)fail('Void the linked debit or credit note through its note record before voiding this invoice.',409,'linked_note_void_required');
    }
    if ($entityType === 'invoice' && function_exists('tegh_invoice_assert_not_sending_r20')) tegh_invoice_assert_not_sending_r20($companyId,$entityId);

    $entries = $entityType === 'journal'
        ? void_journal_entries_by_id($companyId, $entityId)
        : void_journal_entries_for($companyId, $entityType, $entityId);

    return ['spec' => $spec, 'row' => $row, 'entries' => $entries];
}

/**
 * GET - explain what voiding this transaction would do, before anything is
 * changed, so the person can see the consequence and any blocker in advance.
 */
function void_preview(array $user, array $company): never
{
    $companyId = (string)$company['id'];
    $entityType = clean_text($_GET['entityType'] ?? '', 'Transaction type', 40);
    $entityId = clean_text($_GET['entityId'] ?? '', 'Transaction', 64);
    $target = void_load_target($companyId, $entityType, $entityId);
    $entries = $target['entries'];
    $live = array_values(array_filter($entries, static fn(array $e): bool => $e['status'] === 'posted'));

    $impact = [];
    if ($live !== []) {
        $ids = implode(',', array_fill(0, count($live), '?'));
        $stmt = db()->prepare("SELECT a.code, a.name, SUM(jl.debit_cents) AS debit_cents, SUM(jl.credit_cents) AS credit_cents
            FROM journal_lines jl
            JOIN accounts a ON a.id = jl.account_id
            WHERE jl.journal_entry_id IN ($ids)
            GROUP BY a.code, a.name ORDER BY a.code");
        $stmt->execute(array_map(static fn(array $e): string => $e['id'], $live));
        foreach ($stmt->fetchAll() as $line) {
            $impact[] = [
                'accountCode' => (string)$line['code'],
                'accountName' => (string)$line['name'],
                'debitCents' => (int)$line['debit_cents'],
                'creditCents' => (int)$line['credit_cents'],
            ];
        }
    }

    json_response([
        'entityType' => $entityType,
        'entityId' => $entityId,
        'label' => $target['spec']['label'],
        'alreadyVoided' => $live === [],
        'journalEntryCount' => count($entries),
        'liveJournalEntryCount' => count($live),
        'accountsAffected' => $impact,
        'blockers' => void_blockers($companyId, $entityType, $target['row'], $entries),
        'amountCents'=>void_transaction_amount($target['row'],$entries),
        'dualControlRequired'=>void_transaction_amount($target['row'],$entries)>company_materiality_threshold_cents($companyId),
        'materialityThresholdCents'=>company_materiality_threshold_cents($companyId),
        'explanation' => 'Voiding removes this transaction from the trial balance, the financial statements, the ledgers and the tax figures. The record itself is kept: it stays visible in the Day Book marked as voided, and the audit history keeps who posted it, who voided it and why.',
    ]);
}

/** POST - void the transaction. */
function void_apply(array $user, array $company): never
{
    require_method('POST');
    require_csrf();
    $companyId = (string)$company['id'];
    $input = request_json();
    $entityType = clean_text($input['entityType'] ?? '', 'Transaction type', 40);
    $entityId = clean_text($input['entityId'] ?? '', 'Transaction', 64);
    $permission = match($entityType){
        'invoice' => 'invoices.reverse',
        'bill' => 'bills.reverse',
        'payment' => 'payments.reverse',
        'bank_transaction' => 'banking.reverse',
        default => 'journals.reverse',
    };
    require_company_permission($company,$permission);
    $reason = clean_text($input['reason'] ?? '', 'Reason', 500);
    if (mb_strlen($reason) < 12) fail('Give a reason of at least 12 characters. It is kept with the audit record.', 422, 'void_reason_required');
    if (empty($input['confirmed'])) fail('Confirm that this transaction should be removed from the financial reports.', 409, 'void_confirmation_required');
    $voidDate=safe_date($input['voidDate']??canadian_today(),'Void date');assert_not_future_date($voidDate,'Void date');
    $approvalId=trim((string)($input['approvalId']??''));
    if($approvalId!=='')require_company_role($company,'owner');

    $result = db_transaction_retry(static function () use ($user, $companyId, $entityType, $entityId, $reason, $approvalId,$voidDate): array {
        $target = void_load_target($companyId, $entityType, $entityId);
        $spec = $target['spec'];
        $row = $target['row'];
        $entries = $target['entries'];
        void_assert_cycle_limit($companyId,$entityType,$entityId);

        $blockers = void_blockers($companyId, $entityType, $row, $entries);
        if ($blockers !== []) fail(implode(' ', $blockers), 409, 'void_blocked');

        $live = array_values(array_filter($entries, static fn(array $e): bool => $e['status'] === 'posted'));
        $openingDocument=in_array($entityType,['invoice','bill'],true)&&!empty($row['is_opening_document']);
        if ($live === [] && $entityType !== 'bank_transaction'&&!$openingDocument) {
            fail('That transaction is already out of the financial reports.', 409, 'void_already_applied');
        }

        $amount=void_transaction_amount($row,$entries);$approvalRow=null;
        if($amount>company_materiality_threshold_cents($companyId)){
            $payloadHash=hash('sha256',json_encode(['entityType'=>$entityType,'entityId'=>$entityId,'amountCents'=>$amount,'reason'=>$reason],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            if($approvalId===''){
                $existing=db()->prepare("SELECT id FROM journal_approvals WHERE company_id=? AND source_type=? AND source_id=? AND status='submitted' AND payload_hash=? LIMIT 1 FOR UPDATE");$existing->execute([$companyId,'void:'.$entityType,$entityId,$payloadHash]);$requestId=$existing->fetchColumn();
                if($requestId===false){$requestId=new_id('vapproval');db()->prepare("INSERT INTO journal_approvals (id,company_id,journal_entry_id,source_type,source_id,amount_cents,status,payload_hash,prepared_by,submitted_at,review_note) VALUES (?,?,?,?,?,?,'submitted',?,?,UTC_TIMESTAMP(),?)")
                    ->execute([$requestId,$companyId,$live[0]['id']??null,'void:'.$entityType,$entityId,$amount,$payloadHash,$user['id'],$reason]);}
                audit_event($user,$companyId,'transaction.void_submitted_for_approval',$entityType,$entityId,['approvalId'=>$requestId,'amountCents'=>$amount,'reason'=>$reason,'materialityThresholdCents'=>company_materiality_threshold_cents($companyId)]);
                return ['pendingApproval'=>true,'approvalId'=>(string)$requestId,'amountCents'=>$amount,'label'=>$spec['label'],'voidedEntryIds'=>[]];
            }
            $approval=db()->prepare("SELECT * FROM journal_approvals WHERE id=? AND company_id=? AND source_type=? AND source_id=? FOR UPDATE");$approval->execute([$approvalId,$companyId,'void:'.$entityType,$entityId]);$approvalRow=$approval->fetch();
            if(!$approvalRow||(string)$approvalRow['status']!=='submitted')fail('This void approval is no longer available.',409,'void_approval_unavailable');
            if(hash_equals((string)$approvalRow['prepared_by'],(string)$user['id']))fail('The person who requested this void cannot approve it.',409,'void_dual_control_required');
            if(!hash_equals((string)$approvalRow['payload_hash'],$payloadHash)||(int)$approvalRow['amount_cents']!==$amount)fail('The transaction or reason changed after approval was requested.',409,'void_approval_integrity_failed');
            db()->prepare("UPDATE journal_approvals SET status='approved',approved_by=?,approved_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND status='submitted'")->execute([$user['id'],$approvalId,$companyId]);
        }

        $openingAdjustmentEntryId=null;
        if($openingDocument&&$live===[]){
            $adjustment=void_opening_adjustment($companyId,$entityType,$entityId);
            if($adjustment===null){
                assert_period_open($companyId,$voidDate);
                $openingAdjustmentEntryId=add_opening_document_void_journal($user,$companyId,$entityType,$row,$voidDate);
            }elseif((string)$adjustment['status']==='reversed'){
                assert_period_open($companyId,(string)$adjustment['entry_date']);
                transaction_assert_status_transition('journal','reversed','posted');
                db()->prepare("UPDATE journal_entries SET status='posted',voided_at=NULL,voided_by=NULL WHERE id=? AND company_id=? AND status='reversed'")->execute([$adjustment['id'],$companyId]);
                $openingAdjustmentEntryId=(string)$adjustment['id'];
            }else fail('This opening document already has an active void adjustment.',409,'void_already_applied');
        }

        // Take the journal entries out of every financial figure. Every balance,
        // ledger and statement query filters on status='posted', so this is the
        // single switch that removes them - and the rows survive for the Day
        // Book and the audit trail.
        $voidedEntryIds = [];
        foreach ($live as $entry) {
            transaction_assert_status_transition('journal',(string)$entry['status'],'reversed');
            db()->prepare("UPDATE journal_entries SET status='reversed',voided_at=UTC_TIMESTAMP(),voided_by=? WHERE id=? AND company_id=?")
                ->execute([$user['id'],$entry['id'],$companyId]);
            $voidedEntryIds[] = $entry['id'];
        }

        // Mark the document itself so the registers show it as voided.
        if ($spec['statusColumn'] !== null && $spec['voidStatus'] !== null) {
            $from=(string)($row[$spec['statusColumn']]??'');
            if($from!==$spec['voidStatus'])transaction_assert_status_transition($entityType,$from,(string)$spec['voidStatus']);
            db()->prepare('UPDATE `' . $spec['table'] . '` SET `' . $spec['statusColumn'] . '` = ? WHERE id = ? AND company_id = ?')
                ->execute([$spec['voidStatus'], $entityId, $companyId]);
        }
        // A voided receivable or payable no longer sits in the ageing.
        if (in_array($entityType, ['invoice', 'bill'], true) && schema_column_exists($spec['table'], 'balance_cents')) {
            db()->prepare('UPDATE `' . $spec['table'] . '` SET balance_cents = 0 WHERE id = ? AND company_id = ?')
                ->execute([$entityId, $companyId]);
        }
        // A voided payment releases the bank line it was matched to, so the line
        // returns to Bank Review rather than being stranded as matched.
        if ($entityType === 'payment' && array_key_exists('bank_transaction_id', $row) && $row['bank_transaction_id'] !== null) {
            db()->prepare("UPDATE bank_transactions SET status = 'pending', journal_entry_id = NULL WHERE id = ? AND company_id = ? AND status = 'posted'")
                ->execute([(string)$row['bank_transaction_id'], $companyId]);
        }
        // Keep the voucher register in step with the ledger.
        if (schema_table_exists('vouchers') && $voidedEntryIds !== []) {
            $ids = implode(',', array_fill(0, count($voidedEntryIds), '?'));
            db()->prepare("UPDATE vouchers SET status = 'void' WHERE company_id = ? AND journal_entry_id IN ($ids)")
                ->execute(array_merge([$companyId], $voidedEntryIds));
        }

        audit_event($user, $companyId, 'transaction.voided', $entityType, $entityId, [
            'reason' => $reason,
            'journalEntriesVoided' => $voidedEntryIds,
            'documentStatus' => $spec['voidStatus'],
            'removedFromFinancialReports' => true,
            'recordRetained' => true,
            'approvalId'=>$approvalRow?$approvalId:null,'amountCents'=>$amount,
            'openingAdjustmentJournalEntryId'=>$openingAdjustmentEntryId,
        ]);

        if($approvalRow)db()->prepare("UPDATE journal_approvals SET status='posted',posted_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND status='approved'")->execute([$approvalId,$companyId]);

        return ['pendingApproval'=>false,'voidedEntryIds' => $voidedEntryIds, 'openingAdjustmentEntryId'=>$openingAdjustmentEntryId,'label' => $spec['label']];
    });

    if(!empty($result['pendingApproval']))json_response([
        'voided'=>false,'pendingApproval'=>true,'approvalId'=>$result['approvalId'],'entityType'=>$entityType,'entityId'=>$entityId,'amountCents'=>$result['amountCents'],
        'message'=>'A different Company Owner must approve this material void before it changes the books.',
    ],202);

    json_response([
        'voided' => true,
        'entityType' => $entityType,
        'entityId' => $entityId,
        'journalEntriesVoided' => count($result['voidedEntryIds']),
        'message' => $result['label'] . ' has been voided. It no longer appears in any financial report. The record is kept in the Day Book and the audit history.',
    ]);
}

/**
 * Restore a voided transaction. Voiding is reversible on purpose: an
 * irreversible action taken by mistake is worse than the mistake.
 */
function void_restore(array $user, array $company): never
{
    require_method('POST');
    require_csrf();
    $companyId = (string)$company['id'];
    $input = request_json();
    $entityType = clean_text($input['entityType'] ?? '', 'Transaction type', 40);
    $entityId = clean_text($input['entityId'] ?? '', 'Transaction', 64);
    $permission = match($entityType){
        'invoice' => 'invoices.reverse',
        'bill' => 'bills.reverse',
        'payment' => 'payments.reverse',
        'bank_transaction' => 'banking.reverse',
        default => 'journals.reverse',
    };
    require_company_permission($company,$permission);
    $reason = clean_text($input['reason'] ?? '', 'Reason', 500);
    if (mb_strlen($reason) < 12) fail('Give a reason of at least 12 characters. It is kept with the audit record.', 422, 'void_reason_required');

    $result = db_transaction_retry(static function () use ($user, $companyId, $entityType, $entityId, $reason): array {
        $target = void_load_target($companyId, $entityType, $entityId);
        $spec = $target['spec'];
        $row = $target['row'];
        $entries = $target['entries'];
        void_assert_cycle_limit($companyId,$entityType,$entityId);
        $voided = array_values(array_filter($entries, static fn(array $e): bool => $e['status'] === 'reversed'));
        if($voided===[]&&in_array($entityType,['invoice','bill'],true)&&!empty($row['is_opening_document'])){
            $adjustment=void_opening_adjustment($companyId,$entityType,$entityId);
            if($adjustment!==null&&(string)$adjustment['status']==='posted'){
                if(function_exists('period_lock_for_date')&&period_lock_for_date($companyId,(string)$adjustment['entry_date'])!==null)fail('That opening-document adjustment falls in a closed period. Reopen the period before restoring it.',409,'void_period_locked');
                transaction_assert_status_transition('journal','posted','reversed');
                db()->prepare("UPDATE journal_entries SET status='reversed',voided_at=UTC_TIMESTAMP(),voided_by=? WHERE id=? AND company_id=? AND status='posted'")->execute([$user['id'],$adjustment['id'],$companyId]);
                $voided[]=['id'=>(string)$adjustment['id'],'entry_date'=>(string)$adjustment['entry_date'],'status'=>'restored_opening_adjustment'];
            }
        }
        if ($voided === []) fail('That transaction is already in the financial reports.', 409, 'void_not_applied');

        foreach ($voided as $entry) {
            if((string)$entry['status']==='restored_opening_adjustment')continue;
            if (function_exists('period_lock_for_date') && period_lock_for_date($companyId, $entry['entry_date']) !== null) {
                fail('That transaction falls in a closed period. Reopen the period before restoring it.', 409, 'void_period_locked');
            }
        }
        $restored = [];
        foreach ($voided as $entry) {
            if((string)$entry['status']==='restored_opening_adjustment'){$restored[]=$entry['id'];continue;}
            transaction_assert_status_transition('journal',(string)$entry['status'],'posted');
            db()->prepare("UPDATE journal_entries SET status='posted',voided_at=NULL,voided_by=NULL WHERE id=? AND company_id=?")
                ->execute([$entry['id'],$companyId]);
            $restored[] = $entry['id'];
        }
        if ($spec['statusColumn'] !== null && $entityType === 'invoice') {
            $payment=db()->prepare("SELECT id,applied_cents FROM party_payments WHERE company_id=? AND payment_type='customer' AND document_id=? AND status='posted' FOR UPDATE");$payment->execute([$companyId,$entityId]);$applied=$payment->fetchAll();
            if($applied!==[]||array_sum(array_map(static fn(array $item):int=>(int)$item['applied_cents'],$applied))!==0)fail('This invoice has a payment applied after it was voided. Reverse or reallocate that payment before restoring the invoice.',409,'void_restore_payment_conflict');
            transaction_assert_status_transition('invoice',(string)$row['status'],'sent');
            db()->prepare("UPDATE invoices SET status='sent',balance_cents=total_cents,foreign_balance_cents=foreign_total_cents WHERE id=? AND company_id=? AND status='void'")->execute([$entityId,$companyId]);
        }
        if ($spec['statusColumn'] !== null && $entityType === 'bill') {
            $payment=db()->prepare("SELECT id,applied_cents FROM party_payments WHERE company_id=? AND payment_type='vendor' AND document_id=? AND status='posted' FOR UPDATE");$payment->execute([$companyId,$entityId]);$applied=$payment->fetchAll();
            if($applied!==[]||array_sum(array_map(static fn(array $item):int=>(int)$item['applied_cents'],$applied))!==0)fail('This vendor invoice has a payment applied after it was voided. Reverse or reallocate that payment before restoring it.',409,'void_restore_payment_conflict');
            transaction_assert_status_transition('bill',(string)$row['status'],'open');
            db()->prepare("UPDATE bills SET status='open',balance_cents=total_cents,foreign_balance_cents=foreign_total_cents WHERE id=? AND company_id=? AND status='void'")->execute([$entityId,$companyId]);
        }
        if ($entityType === 'expense') {
            transaction_assert_status_transition('expense',(string)$row['status'],'posted');
            db()->prepare("UPDATE expenses SET status = 'posted' WHERE id = ? AND company_id = ? AND status = 'void'")->execute([$entityId, $companyId]);
        }
        if ($entityType === 'payment') {
            transaction_assert_status_transition('payment',(string)$row['status'],'posted');
            db()->prepare("UPDATE party_payments SET status = 'posted' WHERE id = ? AND company_id = ? AND status = 'reversed'")->execute([$entityId, $companyId]);
        }

        audit_event($user, $companyId, 'transaction.void_restored', $entityType, $entityId, [
            'reason' => $reason, 'journalEntriesRestored' => $restored,
        ]);
        return ['restored' => $restored];
    });

    json_response([
        'restored' => true,
        'entityType' => $entityType,
        'entityId' => $entityId,
        'journalEntriesRestored' => count($result['restored']),
        'message' => 'The transaction is back in the financial reports.',
    ]);
}

/** A register of everything voided, for review and for the audit file. */
function void_register(array $user, array $company): never
{
    require_method('GET');
    $companyId = (string)$company['id'];
    $stmt = db()->prepare("SELECT je.id, je.entry_date, je.source_type, je.source_id, je.memo, je.created_at,
            (SELECT COALESCE(SUM(jl.debit_cents),0) FROM journal_lines jl WHERE jl.journal_entry_id = je.id) AS amount_cents
        FROM journal_entries je
        WHERE je.company_id = ? AND je.status = 'reversed'
        ORDER BY je.entry_date DESC, je.created_at DESC LIMIT 300");
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll();

    $audit = [];
    if (schema_table_exists('audit_log')) {
        $log = db()->prepare("SELECT entity_id, actor_email, metadata_json, created_at FROM audit_log
            WHERE company_id = ? AND action = 'transaction.voided' ORDER BY created_at DESC LIMIT 300");
        $log->execute([$companyId]);
        foreach ($log->fetchAll() as $entry) {
            $meta = [];
            try { $meta = json_decode((string)$entry['metadata_json'], true, 32, JSON_THROW_ON_ERROR); } catch (Throwable) {}
            foreach ((array)($meta['journalEntriesVoided'] ?? []) as $journalId) {
                $audit[(string)$journalId] = [
                    'voidedBy' => (string)$entry['actor_email'],
                    'voidedAt' => (string)$entry['created_at'],
                    'reason' => (string)($meta['reason'] ?? ''),
                ];
            }
        }
    }

    json_response(['voided' => array_map(static function (array $row) use ($audit): array {
        $detail = $audit[(string)$row['id']] ?? null;
        return [
            'journalEntryId' => (string)$row['id'],
            'entryDate' => (string)$row['entry_date'],
            'sourceType' => (string)$row['source_type'],
            'sourceId' => (string)$row['source_id'],
            'memo' => (string)$row['memo'],
            'amountCents' => (int)$row['amount_cents'],
            'voidedBy' => $detail['voidedBy'] ?? null,
            'voidedAt' => $detail['voidedAt'] ?? null,
            'reason' => $detail['reason'] ?? null,
        ];
    }, $rows)]);
}

function handle_voids(string $action): never
{
    $user = require_user();
    $company = require_company($user);
    match ($action) {
        '', 'preview' => void_preview($user, $company),
        'apply' => void_apply($user, $company),
        'restore' => void_restore($user, $company),
        'register' => void_register($user, $company),
        default => fail('Void route not found.', 404, 'route_not_found'),
    };
}
