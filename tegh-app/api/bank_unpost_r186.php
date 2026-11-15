<?php
declare(strict_types=1);

/**
 * R186: unpost a statement row from Match and Post.
 *
 * Unposting undoes what posting did and returns the row to Unposted, so it can be posted again. It never deletes an
 * accounting record. Each kind of posting is undone through the path Tegh already uses for it:
 *
 *  - account:         posted straight to an account. The journal is voided (status 'reversed'), exactly as Void
 *                     does, and the row is released as Restore does.
 *  - document:        posted as a receipt or payment of an invoice or bill. The allocation is unapplied and the
 *                     payment reversed (payment_unapply_service, party_payment_reverse_core), dated on the statement
 *                     date, so the invoice or bill is open again and every period nets to zero.
 *  - on_account:      posted as an on-account receipt or payment. The payment is reversed the same way.
 *  - matched_payment: matched to a payment posted earlier. Only the link is removed (and the Undeposited Funds
 *                     transfer journal, when there was one). The payment itself stays posted.
 *
 * The same checks as Void apply first: closed period, saved reconciliation, Bank Reconciliation match, interbank
 * transfer, payroll. A material amount needs a second Company Owner, as Void does.
 */

function r186_unpost_lookup(string $companyId, string $transactionId, bool $lock): array
{
    $sfx = $lock ? ' FOR UPDATE' : '';
    $q = db()->prepare('SELECT * FROM bank_transactions WHERE company_id=? AND id=? LIMIT 1' . $sfx);
    $q->execute([$companyId, $transactionId]);
    $tx = $q->fetch(PDO::FETCH_ASSOC);
    if (!$tx) fail('That bank transaction is no longer available.', 404, 'bank_transaction_not_found');
    $out = ['tx' => $tx, 'kind' => 'none', 'journal' => null, 'payment' => null, 'entries' => [], 'blockers' => [], 'initialApplication' => null, 'document' => null];
    if ((string)$tx['status'] !== 'posted' || $tx['journal_entry_id'] === null) {
        $out['blockers'][] = (string)$tx['status'] === 'posted' ? 'This line has no accounting entry to undo.' : 'Only a posted line can be unposted.';
        return $out;
    }
    $q = db()->prepare('SELECT id,entry_date,status,source_type,source_id FROM journal_entries WHERE company_id=? AND id=? LIMIT 1' . $sfx);
    $q->execute([$companyId, (string)$tx['journal_entry_id']]);
    $journal = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $out['journal'] = $journal;
    if (!$journal) { $out['blockers'][] = 'The accounting entry for this line is unavailable.'; return $out; }
    $payment = null;
    if (schema_table_exists('party_payments')) {
        $q = db()->prepare("SELECT * FROM party_payments WHERE company_id=? AND bank_transaction_id=? AND status='posted' LIMIT 1" . $sfx);
        $q->execute([$companyId, (string)$tx['id']]);
        $payment = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $out['payment'] = $payment;
    $ownJournal = (string)$journal['source_type'] === 'bank_transaction' && (string)$journal['source_id'] === (string)$tx['id'];
    if ($payment === null) {
        if ($ownJournal) $out['kind'] = 'account';
    } elseif ($ownJournal && (string)$payment['journal_entry_id'] === (string)$journal['id']) {
        $out['kind'] = $payment['document_id'] !== null ? 'document' : 'on_account';
    } elseif (in_array((string)$journal['source_type'], ['customer_payment', 'vendor_payment'], true) && (string)$payment['journal_entry_id'] === (string)$journal['id']) {
        $out['kind'] = 'matched_payment';
    } elseif ((string)$journal['source_type'] === 'bank_payment_match' && (string)$journal['source_id'] === (string)$tx['id']) {
        $out['kind'] = 'matched_payment';
    }
    if ($out['kind'] === 'none') {
        $out['blockers'][] = 'This line was recorded by another part of Tegh (' . str_replace('_', ' ', (string)$journal['source_type']) . '). Open the transaction and correct it there.';
        return $out;
    }

    // The journals whose removal is checked against closed periods, reconciliations and matches.
    if ($out['kind'] === 'account') $out['entries'] = void_journal_entries_for($companyId, 'bank_transaction', (string)$tx['id']);
    elseif ($out['kind'] === 'matched_payment') $out['entries'] = (string)$journal['source_type'] === 'bank_payment_match' ? void_journal_entries_by_id($companyId, (string)$journal['id']) : [];
    else $out['entries'] = void_journal_entries_by_id($companyId, (string)$journal['id']);
    $out['blockers'] = void_blockers($companyId, 'bank_transaction', $tx, $out['entries'], true);
    if ($out['kind'] === 'matched_payment' && $out['entries'] === [] && function_exists('period_lock_for_date') && period_lock_for_date($companyId, (string)$tx['transaction_date']) !== null)
        $out['blockers'][] = 'This transaction falls in a closed period (' . (string)$tx['transaction_date'] . '). Reopen the period first.';

    if (in_array($out['kind'], ['document', 'on_account'], true) && schema_table_exists('party_payment_applications')) {
        $q = db()->prepare("SELECT pa.*,op.operation_key FROM party_payment_applications pa JOIN party_payment_application_operations op ON op.id=pa.operation_id AND op.company_id=pa.company_id
            WHERE pa.company_id=? AND pa.payment_id=? AND pa.status='posted' ORDER BY pa.application_date,pa.id" . $sfx);
        $q->execute([$companyId, (string)$payment['id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $application) {
            if ((string)$application['operation_key'] === 'initial:' . (string)$payment['id']) $out['initialApplication'] = $application;
            else { $out['blockers'][] = 'After this line was posted, its ' . ((string)$payment['payment_type'] === 'customer' ? 'receipt' : 'payment') . ' was applied to another invoice or bill. Unapply that allocation first (Payments), then unpost.'; break; }
        }
        if ($payment['document_id'] !== null) {
            $table = (string)$payment['payment_type'] === 'customer' ? 'invoices' : 'bills';
            $party = (string)$payment['payment_type'] === 'customer' ? 'customers' : 'vendors';
            $partyCol = (string)$payment['payment_type'] === 'customer' ? 'customer_id' : 'vendor_id';
            $q = db()->prepare("SELECT d.id,d.number,d.balance_cents,d.total_cents,p.name party_name FROM `$table` d LEFT JOIN `$party` p ON p.id=d.$partyCol AND p.company_id=d.company_id WHERE d.company_id=? AND d.id=? LIMIT 1");
            $q->execute([$companyId, (string)$payment['document_id']]);
            $out['document'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    $out['blockers'] = array_values(array_unique($out['blockers']));
    return $out;
}

/** Plain-language list of what unposting will do, shown before anything changes. */
function r186_unpost_effects(array $info): array
{
    $tx = $info['tx']; $p = $info['payment']; $customer = $p !== null && (string)$p['payment_type'] === 'customer';
    $money = static fn(int $c): string => '$' . number_format($c / 100, 2);
    $back = 'The line returns to Unposted in Match and Post, so you can post it again.';
    return match ($info['kind']) {
        'account' => ['The General Ledger entry for this line is voided and leaves every report: the trial balance, statements, ledgers and tax figures.', $back],
        'document' => [
            ($customer ? 'The customer receipt' : 'The vendor payment') . ' recorded from this line is reversed on ' . (string)$tx['transaction_date'] . '.',
            $info['document'] ? (($customer ? 'Invoice ' : 'Bill ') . (string)$info['document']['number'] . ' (' . (string)($info['document']['party_name'] ?? '') . ') is open again for ' . $money((int)($info['initialApplication']['document_carrying_cents'] ?? 0)) . ' more.') : 'The invoice or bill it paid is open again.',
            $back,
        ],
        'on_account' => [($customer ? 'The on-account customer receipt' : 'The on-account vendor payment') . ' recorded from this line is reversed on ' . (string)$tx['transaction_date'] . '.', $back],
        'matched_payment' => [
            'The link to the ' . ($customer ? 'customer receipt' : 'vendor payment') . ' "' . (string)($p['reference'] ?? '') . '" is removed. That payment stays posted and can be matched again.',
            ...((string)($info['journal']['source_type'] ?? '') === 'bank_payment_match' ? ['The transfer from Undeposited Funds to the bank recorded by the match is voided.'] : []),
            $back,
        ],
        default => [],
    };
}

function r186_unpost_accounts(string $companyId, array $info): array
{
    $ids = array_values(array_map(static fn(array $e): string => (string)$e['id'], array_filter($info['entries'], static fn(array $e): bool => $e['status'] === 'posted')));
    if ($ids === [] && in_array($info['kind'], ['document', 'on_account'], true) && $info['journal']) $ids = [(string)$info['journal']['id']];
    if ($ids === []) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = db()->prepare("SELECT a.code,a.name,SUM(jl.debit_cents) d,SUM(jl.credit_cents) c FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id JOIN journal_entries je ON je.id=jl.journal_entry_id
        WHERE je.company_id=? AND jl.journal_entry_id IN ($in) GROUP BY a.code,a.name ORDER BY a.code");
    $q->execute(array_merge([$companyId], $ids));
    return array_map(static fn(array $r): array => ['accountCode' => (string)$r['code'], 'accountName' => (string)$r['name'], 'debitCents' => (int)$r['d'], 'creditCents' => (int)$r['c']], $q->fetchAll(PDO::FETCH_ASSOC));
}

function r186_unpost_pending_approval(string $companyId, string $transactionId): ?array
{
    $q = db()->prepare("SELECT ja.id,ja.prepared_by,ja.review_note,ja.amount_cents,u.display_name FROM journal_approvals ja LEFT JOIN users u ON u.id=ja.prepared_by
        WHERE ja.company_id=? AND ja.source_type='unpost:bank_transaction' AND ja.source_id=? AND ja.status='submitted' ORDER BY ja.submitted_at DESC LIMIT 1");
    $q->execute([$companyId, $transactionId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function r186_unpost_preview(array $user, array $company): never
{
    require_method('GET');
    $companyId = (string)$company['id'];
    $id = clean_text($_GET['transactionId'] ?? '', 'Bank transaction', 64);
    $info = r186_unpost_lookup($companyId, $id, false);
    $amount = abs((int)$info['tx']['amount_cents']); $threshold = company_materiality_threshold_cents($companyId);
    $pending = r186_unpost_pending_approval($companyId, $id);
    $role = (string)($company['role'] ?? '');
    json_response([
        'transactionId' => $id,
        'kind' => $info['kind'],
        'blockers' => $info['blockers'],
        'effects' => r186_unpost_effects($info),
        'accountsAffected' => r186_unpost_accounts($companyId, $info),
        'amountCents' => $amount,
        'dualControlRequired' => $amount > $threshold,
        'materialityThresholdCents' => $threshold,
        'pendingApproval' => $pending ? [
            'approvalId' => (string)$pending['id'],
            'preparedByMe' => hash_equals((string)$pending['prepared_by'], (string)$user['id']),
            'preparedByName' => (string)($pending['display_name'] ?? ''),
            'reason' => (string)($pending['review_note'] ?? ''),
            'canApprove' => in_array($role, ['owner', 'admin'], true) && !hash_equals((string)$pending['prepared_by'], (string)$user['id']),
        ] : null,
    ]);
}

function r186_unpost_apply(array $user, array $company): never
{
    require_method('POST'); require_csrf();
    require_company_role($company, 'owner', 'bookkeeper'); require_company_permission($company, 'banking.reverse');
    $companyId = (string)$company['id']; $input = request_json();
    $id = clean_text($input['transactionId'] ?? '', 'Bank transaction', 64);
    $operationKey = clean_text($input['operationKey'] ?? '', 'Operation key', 120);
    if (strlen($operationKey) < 16) fail('This request is missing its operation key. Reload and try again.', 422, 'operation_key_required');
    $approvalId = trim((string)($input['approvalId'] ?? ''));
    if ($approvalId !== '') require_company_role($company, 'owner');
    $reason = trim((string)($input['reason'] ?? ''));
    if ($approvalId === '') {
        $reason = clean_text($reason, 'Reason', 500);
        if (mb_strlen($reason) < 12) fail('Give a reason of at least 12 characters. It is kept with the audit record.', 422, 'unpost_reason_required');
        if (empty($input['confirmed'])) fail('Confirm that this line should be unposted.', 409, 'unpost_confirmation_required');
    }

    $result = db_transaction_retry(static function () use ($user, $company, $companyId, $id, $operationKey, $approvalId, $reason): array {
        $company = tegh_bank_reauthorize_mutation($user, $company, 'banking.reverse');
        // A retried request that already succeeded returns the first result.
        $q = db()->prepare("SELECT metadata_json FROM audit_log WHERE company_id=? AND action='bank_transaction.unposted' AND entity_id=? AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.operationKey'))=? LIMIT 1");
        $q->execute([$companyId, $id, $operationKey]);
        $prior = $q->fetchColumn();
        if ($prior !== false) { $m = json_decode((string)$prior, true) ?: []; return ['replay' => true, 'kind' => (string)($m['kind'] ?? ''), 'pendingApproval' => false]; }

        $info = r186_unpost_lookup($companyId, $id, true);
        if ($info['blockers'] !== []) fail(implode(' ', $info['blockers']), 409, 'bank_unpost_blocked');
        $tx = $info['tx']; $kind = $info['kind']; $journal = $info['journal']; $payment = $info['payment'];
        $date = (string)$tx['transaction_date'];
        $amount = abs((int)$tx['amount_cents']); $approvalRow = null;

        if ($amount > company_materiality_threshold_cents($companyId)) {
            if ($approvalId === '') {
                $hash = hash('sha256', json_encode(['transactionId' => $id, 'amountCents' => $amount, 'kind' => $kind, 'reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $existing = r186_unpost_pending_approval($companyId, $id);
                $requestId = $existing ? (string)$existing['id'] : new_id('uapproval');
                if (!$existing) db()->prepare("INSERT INTO journal_approvals (id,company_id,journal_entry_id,source_type,source_id,amount_cents,status,payload_hash,prepared_by,submitted_at,review_note) VALUES (?,?,?,?,?,?,'submitted',?,?,UTC_TIMESTAMP(),?)")
                    ->execute([$requestId, $companyId, (string)$journal['id'], 'unpost:bank_transaction', $id, $amount, $hash, $user['id'], $reason]);
                audit_event($user, $companyId, 'bank_transaction.unpost_submitted_for_approval', 'bank_transaction', $id, ['approvalId' => $requestId, 'amountCents' => $amount, 'reason' => $reason, 'kind' => $kind, 'materialityThresholdCents' => company_materiality_threshold_cents($companyId)]);
                return ['pendingApproval' => true, 'approvalId' => $requestId, 'amountCents' => $amount, 'kind' => $kind];
            }
            $q = db()->prepare("SELECT * FROM journal_approvals WHERE id=? AND company_id=? AND source_type='unpost:bank_transaction' AND source_id=? FOR UPDATE");
            $q->execute([$approvalId, $companyId, $id]); $approvalRow = $q->fetch(PDO::FETCH_ASSOC);
            if (!$approvalRow || (string)$approvalRow['status'] !== 'submitted') fail('This unpost approval is no longer available.', 409, 'unpost_approval_unavailable');
            if (hash_equals((string)$approvalRow['prepared_by'], (string)$user['id'])) fail('The person who asked to unpost this line cannot approve it.', 409, 'unpost_dual_control_required');
            if ((int)$approvalRow['amount_cents'] !== $amount) fail('The line changed after approval was requested.', 409, 'unpost_approval_integrity_failed');
            $reason = (string)$approvalRow['review_note'];
            $hash = hash('sha256', json_encode(['transactionId' => $id, 'amountCents' => $amount, 'kind' => $kind, 'reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (!hash_equals((string)$approvalRow['payload_hash'], $hash)) fail('The line changed after approval was requested.', 409, 'unpost_approval_integrity_failed');
            db()->prepare("UPDATE journal_approvals SET status='approved',approved_by=?,approved_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND status='submitted'")->execute([$user['id'], $approvalId, $companyId]);
        } elseif ($approvalId !== '') fail('This line does not need approval.', 409, 'unpost_approval_unexpected');

        $voided = []; $created = [];
        $flip = static function (string $entryId) use ($user, $companyId, &$voided): void {
            transaction_assert_status_transition('journal', 'posted', 'reversed');
            $u = db()->prepare("UPDATE journal_entries SET status='reversed',voided_at=UTC_TIMESTAMP(),voided_by=? WHERE id=? AND company_id=? AND status='posted'");
            $u->execute([$user['id'], $entryId, $companyId]);
            if ($u->rowCount() !== 1) throw new RuntimeException('A journal changed while the line was being unposted.');
            $voided[] = $entryId;
        };
        // Frees the (source_type, source_id) key so the line can be posted again; the journal itself is unchanged.
        $retire = static function (string $entryId, string $retiredType) use ($companyId, $id): void {
            $n = db()->prepare('SELECT COUNT(*) FROM journal_entries WHERE company_id=? AND source_type=? AND source_id LIKE ?');
            $n->execute([$companyId, $retiredType, $id . '~%']);
            db()->prepare('UPDATE journal_entries SET source_type=?,source_id=? WHERE id=? AND company_id=?')
                ->execute([$retiredType, $id . '~' . ((int)$n->fetchColumn() + 1), $entryId, $companyId]);
        };

        transaction_assert_status_transition('bank_transaction', 'posted', 'pending');
        if ($kind === 'account') {
            foreach ($info['entries'] as $entry) if ($entry['status'] === 'posted') $flip((string)$entry['id']);
            db()->prepare("UPDATE bank_transactions SET status='pending' WHERE company_id=? AND id=? AND status='posted'")->execute([$companyId, $id]);
            bank_transaction_release_voided_posting($companyId, $id, (string)$journal['id']);
        } elseif ($kind === 'document' || $kind === 'on_account') {
            $pid = (string)$payment['id'];
            if ($info['initialApplication'] !== null) {
                $r = payment_unapply_service($user, $company, ['applicationId' => (string)$info['initialApplication']['id'], 'reversalDate' => $date, 'operationKey' => 'unpost:' . (string)$info['initialApplication']['id']], false);
                if (!empty($r['recognitionReversalJournalEntryId'])) $created[] = (string)$r['recognitionReversalJournalEntryId'];
            }
            $q = db()->prepare("SELECT * FROM party_payments WHERE id=? AND company_id=? AND status='posted' FOR UPDATE");
            $q->execute([$pid, $companyId]); $fresh = $q->fetch(PDO::FETCH_ASSOC);
            if (!$fresh) throw new RuntimeException('The payment changed while the line was being unposted.');
            $created[] = party_payment_reverse_core($user, $companyId, $pid, $fresh, $date, true);
            $retire((string)$journal['id'], 'bank_transaction_voided');
            db()->prepare('UPDATE party_payments SET bank_transaction_id=NULL WHERE id=? AND company_id=?')->execute([$pid, $companyId]);
            db()->prepare("UPDATE bank_transactions SET status='pending',journal_entry_id=NULL,decided_account_id=NULL WHERE company_id=? AND id=? AND status='posted'")->execute([$companyId, $id]);
        } else { // matched_payment
            if ((string)$journal['source_type'] === 'bank_payment_match') { $flip((string)$journal['id']); $retire((string)$journal['id'], 'bank_payment_match_voided'); }
            db()->prepare('UPDATE party_payments SET bank_transaction_id=NULL,matched_at=NULL WHERE id=? AND company_id=?')->execute([(string)$payment['id'], $companyId]);
            db()->prepare("UPDATE bank_transactions SET status='pending',journal_entry_id=NULL,decided_account_id=NULL WHERE company_id=? AND id=? AND status='posted'")->execute([$companyId, $id]);
        }
        $check = db()->prepare('SELECT status,journal_entry_id FROM bank_transactions WHERE company_id=? AND id=?'); $check->execute([$companyId, $id]); $after = $check->fetch(PDO::FETCH_ASSOC);
        if (!$after || (string)$after['status'] !== 'pending' || $after['journal_entry_id'] !== null) throw new RuntimeException('The bank line did not return to Unposted.');
        if (schema_table_exists('vouchers')) db()->prepare("UPDATE vouchers SET status='draft',journal_entry_id=NULL,posted_by=NULL,posted_at=NULL WHERE company_id=? AND source_type='bank_transaction' AND source_id=?")->execute([$companyId, $id]);

        audit_event($user, $companyId, 'bank_transaction.unposted', 'bank_transaction', $id, [
            'kind' => $kind, 'reason' => $reason, 'operationKey' => $operationKey, 'transactionDate' => $date, 'amountCents' => (int)$tx['amount_cents'],
            'originalJournalEntryId' => (string)$journal['id'], 'journalEntriesVoided' => $voided, 'reversalJournalEntryIds' => $created,
            'paymentId' => $payment ? (string)$payment['id'] : null, 'documentId' => $payment ? $payment['document_id'] : null,
            'approvalId' => $approvalRow ? $approvalId : null, 'recordRetained' => true, 'initiatedVia' => 'Manual UI',
        ]);
        if ($approvalRow) db()->prepare("UPDATE journal_approvals SET status='posted',posted_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND status='approved'")->execute([$approvalId, $companyId]);
        return ['pendingApproval' => false, 'kind' => $kind, 'journalEntriesVoided' => count($voided), 'reversalEntries' => count($created)];
    });

    if (!empty($result['pendingApproval'])) json_response([
        'unposted' => false, 'pendingApproval' => true, 'approvalId' => $result['approvalId'], 'transactionId' => $id, 'amountCents' => $result['amountCents'],
        'message' => 'A different Company Owner must approve unposting this material amount before the books change.',
    ], 202);
    json_response([
        'unposted' => true, 'transactionId' => $id, 'kind' => $result['kind'], 'replay' => !empty($result['replay']),
        'journalEntriesVoided' => (int)($result['journalEntriesVoided'] ?? 0), 'reversalEntries' => (int)($result['reversalEntries'] ?? 0),
        'message' => 'The line is back in Unposted. The original entry is kept in the Day Book and the audit history.',
    ]);
}

function handle_bank_transaction_unpost_r186(string $action): never
{
    $user = require_user(); $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper', 'viewer');
    if ($action === 'preview') { require_company_permission($company, 'banking.view'); r186_unpost_preview($user, $company); }
    if ($action === 'apply') r186_unpost_apply($user, $company);
    fail('Unknown unpost action.', 404, 'not_found');
}
