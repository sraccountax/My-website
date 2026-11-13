<?php
declare(strict_types=1);

/*
 * R182: correct an issued customer invoice.
 *
 * The invoice keeps its number. In an accrual company the current issue entry is reversed on its own date and the
 * corrected entry is posted on the (possibly new) invoice date; both periods must be open. Payments are never changed:
 * when the corrected total is below what was already applied, the excess is released from the payment's application
 * and stays on the payment as an unapplied customer credit (it is already a credit in receivables 1200), usable with
 * Apply Payment or a customer refund. Every correction is stored in invoice_revisions with before and after.
 *
 * Off by default: Site & Release Settings › Release › Edit issued invoices.
 */

function tegh_r182_edit_mode(): string
{
    if (!function_exists('r174_group')) return 'off';
    $mode = (string)(r174_group('release')['value']['editIssuedInvoices'] ?? 'off');
    return in_array($mode, ['off', 'owner', 'everyone'], true) ? $mode : 'off';
}

function tegh_r182_edit_available_for(?array $user): bool
{
    $mode = tegh_r182_edit_mode();
    if ($mode === 'everyone') return true;
    if ($mode === 'owner' && $user !== null && function_exists('platform_role_for_user')) return platform_role_for_user((string)$user['id']) === 'platform_owner';
    return false;
}

/** Created on first use, outside any transaction (DDL commits implicitly). No existing table changes. */
function tegh_r182_revisions_require(): void
{
    if (schema_table_exists('invoice_revisions')) return;
    db()->exec("CREATE TABLE IF NOT EXISTS invoice_revisions (
      id VARCHAR(64) PRIMARY KEY,
      company_id VARCHAR(64) NOT NULL,
      invoice_id VARCHAR(64) NOT NULL,
      revision_number INT NOT NULL,
      reason VARCHAR(500) NOT NULL,
      before_json LONGTEXT NOT NULL,
      after_json LONGTEXT NOT NULL,
      before_total_cents BIGINT NOT NULL,
      after_total_cents BIGINT NOT NULL,
      reversal_journal_entry_id VARCHAR(64) NULL,
      new_journal_entry_id VARCHAR(64) NULL,
      customer_credit_cents BIGINT NOT NULL DEFAULT 0,
      operation_id VARCHAR(64) NOT NULL,
      created_by VARCHAR(64) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY invoice_revisions_number_uq (company_id, invoice_id, revision_number),
      KEY invoice_revisions_invoice_idx (company_id, invoice_id, created_at),
      CONSTRAINT invoice_revisions_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
      CONSTRAINT invoice_revisions_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (function_exists('schema_cache_reset')) schema_cache_reset();
}

function tegh_r182_snapshot(string $companyId, array $invoice, array $lines): array
{
    $tax = function_exists('document_tax_rows_get') ? document_tax_rows_get($companyId, 'invoice', (string)$invoice['id']) : [];
    $keep = ['customer_id', 'issue_date', 'due_date', 'status', 'subtotal_cents', 'gst_hst_cents', 'pst_cents', 'tax_cents', 'total_cents', 'balance_cents',
        'currency', 'exchange_rate_micros', 'foreign_subtotal_cents', 'foreign_tax_cents', 'foreign_total_cents', 'foreign_balance_cents',
        'purchase_order', 'message', 'template_id', 'issued_journal_entry_id'];
    $out = [];
    foreach ($keep as $k) if (array_key_exists($k, $invoice)) $out[$k] = $invoice[$k];
    $out['lines'] = array_map(static fn(array $l): array => [
        'description' => $l['description'], 'quantityMilli' => (int)$l['quantity_milli'], 'unitPriceCents' => (int)$l['unit_price_cents'],
        'taxRateBps' => (int)$l['tax_rate_bps'], 'amountCents' => (int)$l['amount_cents'], 'taxCents' => (int)$l['tax_cents'],
        'foreignUnitPriceCents' => (int)$l['foreign_unit_price_cents'], 'foreignAmountCents' => (int)$l['foreign_amount_cents'],
        'incomeAccountId' => $l['income_account_id'], 'productServiceId' => $l['product_service_id'], 'taxCodeId' => $l['tax_code_id'] ?? null,
    ], $lines);
    $out['taxRows'] = $tax;
    return $out;
}

/** Posted applications of payments to this invoice, newest first. */
function tegh_r182_applications(string $companyId, string $invoiceId): array
{
    $q = db()->prepare("SELECT pa.*,pp.amount_cents payment_amount_cents,pp.currency payment_currency FROM party_payment_applications pa JOIN party_payments pp ON pp.id=pa.payment_id AND pp.company_id=pa.company_id
        WHERE pa.company_id=? AND pa.document_type='invoice' AND pa.document_id=? AND pa.status='posted' AND pp.status='posted' ORDER BY pa.application_date DESC,pa.created_at DESC,pa.id DESC FOR UPDATE");
    $q->execute([$companyId, $invoiceId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function tegh_r182_entry_lines(string $companyId, ?string $entryId): array
{
    if ($entryId === null || $entryId === '') return [];
    $q = db()->prepare('SELECT a.code,a.name,jl.debit_cents,jl.credit_cents,jl.memo FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id=? AND je.id=? ORDER BY (jl.debit_cents>0) DESC,a.code,jl.id');
    $q->execute([$companyId, $entryId]);
    return array_map(static fn(array $r): array => ['account' => $r['code'] . ' · ' . $r['name'], 'debitCents' => (int)$r['debit_cents'], 'creditCents' => (int)$r['credit_cents'], 'memo' => (string)$r['memo']], $q->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Runs the whole correction inside one transaction. With $dryRun the transaction is rolled back and the result
 * (entries, balances, credit, blockers) is returned as a preview, so the preview is exactly what saving would do.
 */
function tegh_r182_correct(array $user, array $company, array $input, bool $dryRun): array
{
    if (!tegh_r182_edit_available_for($user)) fail('Editing issued invoices is not turned on for this site.', 403, 'invoice_correction_unavailable');
    require_company_role($company, 'owner', 'bookkeeper');
    require_company_permission($company, 'invoices.write');
    require_company_permission($company, 'invoices.reverse');
    tegh_r182_revisions_require();
    $companyId = (string)$company['id'];
    $reason = trim((string)($input['reason'] ?? ''));
    if (!$dryRun && $reason === '') fail('Enter the reason for this correction. It is kept in the invoice history.', 422, 'invoice_correction_reason_required');
    if (mb_strlen($reason) > 500) fail('The reason must be 500 characters or fewer.', 422, 'invoice_correction_reason_invalid');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $i = tegh_invoice_row_r20($companyId, (string)($input['invoiceId'] ?? ''), true);
        $edit = array_intersect_key($input, array_flip(['customerId', 'issueDate', 'dueDate', 'currency', 'exchangeRateMicros', 'lines', 'templateId', 'purchaseOrder', 'message', 'taxOverrideReason']));
        $payload = ['invoiceId' => $i['id'], 'expectedRevision' => (string)($input['expectedRevision'] ?? ''), 'operationKey' => (string)($input['operationKey'] ?? ''), 'reason' => $reason] + $edit;
        if (!$dryRun && ($old = tegh_invoice_operation_replay_r20($i, $user, 'correct', $payload))) { $pdo->commit(); return $old; }
        $lines = tegh_invoice_lines_r20((string)$i['id']);
        tegh_invoice_assert_revision_r20($i, $lines, $input);

        $blockers = [];
        $block = static function (string $code, string $message, string $instead) use (&$blockers): void { $blockers[] = ['code' => $code, 'message' => $message, 'instead' => $instead]; };
        $accrual = (string)$company['accounting_basis'] === 'accrual';
        $status = (string)$i['status'];
        if ($status === 'draft') $block('invoice_correction_draft', 'This invoice is a draft.', 'Edit the draft directly; nothing has been posted yet.');
        if ($status === 'void') $block('invoice_void', 'A void invoice cannot be corrected.', 'Create a new invoice.');
        if (!empty($i['is_opening_document'])) $block('invoice_correction_opening', 'Opening-balance invoices are part of the opening balances.', 'Correct the opening balances instead.');
        if (schema_table_exists('invoice_document_operations')) {
            $q = $pdo->prepare("SELECT id FROM invoice_document_operations WHERE company_id=? AND invoice_id=? AND operation_type='send' AND status='pending' LIMIT 1");
            $q->execute([$companyId, $i['id']]);
            if ($q->fetchColumn()) $block('invoice_send_pending', 'An email of this invoice is in progress.', 'Check Delivery, then try again.');
        }
        if (schema_table_exists('accounting_notes')) {
            $q = $pdo->prepare("SELECT number FROM accounting_notes WHERE company_id=? AND ((source_type='invoice' AND source_id=?) OR debit_document_id=?) AND status IN ('draft','posted') LIMIT 1");
            $q->execute([$companyId, $i['id'], $i['id']]);
            if (($n = $q->fetchColumn()) !== false) $block('invoice_correction_linked_note', 'A credit or debit note' . ($n ? ' (' . $n . ')' : '') . ' is linked to this invoice, and its amounts depend on the current lines.', 'Void the note first, or issue another note for the change.');
        }
        if ($accrual && empty($i['issued_journal_entry_id']) && $status !== 'draft') $block('invoice_correction_entry_missing', 'The invoice\'s accounting entry could not be found.', 'Use a credit or debit note.');

        // Paid part, from the invoice and its payment applications (they must agree).
        $paid = (int)$i['total_cents'] - (int)$i['balance_cents'];
        $foreignPaid = (int)$i['foreign_total_cents'] - (int)$i['foreign_balance_cents'];
        $apps = tegh_r182_applications($companyId, (string)$i['id']);
        $appBase = array_sum(array_map(static fn($a) => (int)$a['document_carrying_cents'], $apps));
        $appForeign = array_sum(array_map(static fn($a) => (int)$a['foreign_amount_cents'], $apps));
        if ($paid !== $appBase || $foreignPaid !== $appForeign) $block('invoice_correction_payments_unreconciled', 'The payments on this invoice do not match its balance.', 'Use a credit or debit note.');

        $data = $edit + ['customerId' => $i['customer_id'], 'issueDate' => $i['issue_date'], 'dueDate' => $i['due_date'], 'currency' => $i['currency'], 'exchangeRateMicros' => (int)$i['exchange_rate_micros'], 'templateId' => $i['template_id'], 'purchaseOrder' => $i['purchase_order'], 'message' => $i['message']];
        if (!isset($data['lines'])) fail('Send the invoice lines with the correction.', 422, 'invoice_correction_lines_required');
        $data['issue'] = false; $data['importReference'] = $i['import_reference'];
        $v = prepare_invoice_record_r20($user, $company, $data, (string)$i['id']);
        $newDate = (string)$v['issueDate']; $oldDate = (string)$i['issue_date'];
        if ($newDate > canadian_today()) $block('invoice_date_future', 'The invoice date is in the future.', 'Use today or an earlier date.');
        if ((string)$v['customerId'] !== (string)$i['customer_id']) {
            try { assert_customer_may_issue_invoice($companyId, (string)$v['customerId']); } catch (Throwable $e) { $block('invoice_correction_customer', 'This customer cannot be invoiced.', 'Choose another customer.'); }
        }
        $customerChanged = (string)$v['customerId'] !== (string)$i['customer_id'];
        $currencyChanged = (string)$v['currency'] !== (string)$i['currency'] || (int)$v['exchangeRateMicros'] !== (int)$i['exchange_rate_micros'];
        if ($paid > 0 || $foreignPaid > 0) {
            if (!$accrual) $block('invoice_correction_cash_paid', 'This company uses cash-basis accounting and the invoice has a payment: its revenue and tax were recognised with each payment.', 'Use a credit or debit note.');
            if ($customerChanged) $block('invoice_correction_customer_paid', 'A payment from this customer is applied to the invoice.', 'Unapply the payment first, or keep the customer.');
            if ($currencyChanged) $block('invoice_correction_currency_paid', 'The payment and its exchange difference were recorded at the invoice\'s currency and rate.', 'Keep the currency and rate; amounts and lines can still be corrected.');
            $firstApplied = $apps ? min(array_map(static fn($a) => (string)$a['application_date'], $apps)) : null;
            if ($firstApplied !== null && $newDate > $firstApplied) $block('invoice_correction_date_after_payment', 'The new invoice date is after a payment applied to it (' . $firstApplied . ').', 'Use a date on or before ' . $firstApplied . '.');
            foreach ($apps as $a) if ($a['recognition_journal_entry_id'] !== null && (int)$v['foreignTotal'] < $foreignPaid) { $block('invoice_correction_fx_credit', 'Part of a payment with an exchange difference would have to be released.', 'Use a credit note.'); break; }
            if ((int)$v['foreignTotal'] < $foreignPaid && (string)$i['currency'] !== (string)$company['currency']) $block('invoice_correction_foreign_credit', 'The corrected total is below what was paid on a foreign-currency invoice.', 'Use a credit note for the difference.');
        }
        foreach (array_unique([$oldDate, $newDate]) as $d) if (period_lock_for_date($companyId, $d) !== null) $block('period_locked', 'The period of ' . $d . ' is locked.', 'Reopen the period in Period Locking, or use a credit or debit note dated in an open period.');
        $before = tegh_r182_snapshot($companyId, $i, $lines);
        $unchanged = (int)$v['total'] === (int)$i['total_cents'] && (int)$v['tax'] === (int)$i['tax_cents'] && $newDate === $oldDate && !$customerChanged && !$currencyChanged
            && json_encode(array_map(static fn($l) => [$l['description'], (int)$l['quantityMilli'], (int)$l['unitPriceCents'], (int)$l['taxRateBps'], (string)($l['incomeAccountId'] ?? '')], $v['calculatedLines']))
               === json_encode(array_map(static fn($l) => [$l['description'], (int)$l['quantity_milli'], (int)$l['unit_price_cents'], (int)$l['tax_rate_bps'], (string)($l['income_account_id'] ?? '')], $lines))
            && (string)($v['dueDate']) === (string)$i['due_date'] && (string)($data['message'] ?? '') === (string)$i['message'] && (string)($data['purchaseOrder'] ?? '') === (string)($i['purchase_order'] ?? '');
        if ($unchanged) $block('invoice_correction_no_change', 'Nothing has changed.', 'Change a line, amount, date or customer, or close the editor.');

        $newTotal = (int)$v['total']; $newForeign = (int)$v['foreignTotal'];
        $credit = max(0, $paid - $newTotal);
        $foreignCredit = max(0, $foreignPaid - $newForeign);
        $balance = max(0, $newTotal - $paid); $foreignBalance = max(0, $newForeign - $foreignPaid);
        if ($foreignBalance === 0) $balance = 0;
        if ($foreignBalance > 0 && $balance === 0) $block('invoice_correction_rounding', 'The open balance rounds to zero in ' . $company['currency'] . '.', 'Adjust the amount by a cent.');

        $result = [
            'invoiceId' => (string)$i['id'], 'number' => (string)$i['number'], 'currency' => (string)$v['currency'], 'baseCurrency' => (string)$company['currency'],
            'accountingBasis' => $accrual ? 'accrual' : 'cash',
            'before' => ['totalCents' => (int)$i['total_cents'], 'taxCents' => (int)$i['tax_cents'], 'subtotalCents' => (int)$i['subtotal_cents'], 'foreignTotalCents' => (int)$i['foreign_total_cents'], 'balanceCents' => (int)$i['balance_cents'], 'issueDate' => $oldDate, 'status' => $status],
            'after' => ['totalCents' => $newTotal, 'taxCents' => (int)$v['tax'], 'subtotalCents' => (int)$v['subtotal'], 'foreignTotalCents' => $newForeign, 'balanceCents' => $balance, 'issueDate' => $newDate, 'status' => $foreignBalance === 0 ? 'paid' : 'sent'],
            'paidCents' => $paid, 'foreignPaidCents' => $foreignPaid, 'customerCreditCents' => $credit, 'foreignCustomerCreditCents' => $foreignCredit,
            'periodNote' => $oldDate !== $newDate ? 'The original entry is reversed on ' . $oldDate . ' and the corrected one is posted on ' . $newDate . '.' : ($oldDate < substr(canadian_today(), 0, 7) . '-01' ? 'Both entries are dated ' . $oldDate . ', so the figures for that month change, including sales tax.' : null),
            'emailed' => false, 'blockers' => $blockers, 'canSave' => !$blockers,
        ];
        if (schema_table_exists('invoice_document_operations')) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM invoice_document_operations WHERE company_id=? AND invoice_id=? AND operation_type='send' AND status='completed'");
            $q->execute([$companyId, $i['id']]); $result['emailed'] = (int)$q->fetchColumn() > 0;
        }
        if ($blockers) {
            $pdo->rollBack();
            if ($dryRun) return $result + ['entries' => ['reversal' => [], 'corrected' => []]];
            fail($blockers[0]['message'] . ' ' . $blockers[0]['instead'], 409, $blockers[0]['code']);
        }

        $op = $dryRun ? 'preview' : tegh_invoice_operation_create_r20($i, $user, 'correct', $payload);
        // 1. Accounting: reverse the current entry on its own date; post the corrected one on the invoice date.
        // Journal sources are unique per (type, id), so each correction posts under its own revision id.
        $revId = new_id('invrev');
        $reversalId = null; $newEntryId = null;
        if ($accrual) {
            $reversalId = add_reversing_journal_entry($user, $companyId, (string)$i['issued_journal_entry_id'], $oldDate, 'invoice_correction_reversal', $revId, 'Invoice ' . $i['number'] . ' corrected: original entry reversed');
            $customer = $pdo->prepare('SELECT name FROM customers WHERE id=? AND company_id=?'); $customer->execute([$v['customerId'], $companyId]);
            $newEntryId = add_journal_entry($user, $companyId, $newDate, 'invoice_correction', $revId,
                invoice_posting_description((string)$customer->fetchColumn(), (string)$i['number'], $newDate, array_map(static fn($l) => (string)$l['description'], $v['calculatedLines'])) . ' (corrected)',
                invoice_posting_lines($companyId, (int)$v['subtotal'], (int)$v['tax'], $newTotal, $v['calculatedLines'], null, (int)($v['pst'] ?? 0), !empty($v['codesMode']) ? $v['taxRows'] : null));
        }
        // 2. The invoice, its lines and its tax rows (same storage as issuing).
        $message = optional_text($data['message'] ?? '', 1000) ?? ''; $po = optional_text($data['purchaseOrder'] ?? null, 80);
        $pdo->prepare("UPDATE invoices SET customer_id=?,issue_date=?,due_date=?,status=?,subtotal_cents=?,tax_cents=?,total_cents=?,balance_cents=?,message=?,currency=?,exchange_rate_micros=?,foreign_subtotal_cents=?,foreign_tax_cents=?,foreign_total_cents=?,foreign_balance_cents=?,purchase_order=?,template_id=?,template_snapshot_json=?,customer_snapshot_json=?,issued_journal_entry_id=COALESCE(?,issued_journal_entry_id),updated_at=CURRENT_TIMESTAMP WHERE company_id=? AND id=?")
            ->execute([$v['customerId'], $newDate, $v['dueDate'], $foreignBalance === 0 ? 'paid' : 'sent', $v['subtotal'], $v['tax'], $newTotal, $balance, $message, $v['currency'], $v['exchangeRateMicros'], $v['foreignSubtotal'], $v['foreignTax'], $newForeign, $foreignBalance, $po, $v['template']['id'],
                json_encode($v['templateSnapshot'], JSON_THROW_ON_ERROR), json_encode($v['customerSnapshot'], JSON_THROW_ON_ERROR), $newEntryId, $companyId, $i['id']]);
        if (function_exists('invoice_record_tax_split')) invoice_record_tax_split($companyId, (string)$i['id'], (int)$v['tax'], (int)($v['pst'] ?? 0));
        if (function_exists('document_tax_rows_save')) document_tax_rows_save($companyId, 'invoice', (string)$i['id'], !empty($v['codesMode']) ? $v['taxRows'] : []);
        $pdo->prepare('DELETE FROM invoice_lines WHERE invoice_id=?')->execute([$i['id']]);
        $s = $pdo->prepare('INSERT INTO invoice_lines(id,invoice_id,product_service_id,income_account_id,description,quantity_milli,unit_price_cents,tax_rate_bps,amount_cents,tax_cents,foreign_unit_price_cents,foreign_amount_cents,foreign_tax_cents,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($v['calculatedLines'] as $l) $s->execute([new_id('iline'), $i['id'], $l['productServiceId'], $l['incomeAccountId'], $l['description'], $l['quantityMilli'], $l['unitPriceCents'], $l['taxRateBps'], $l['amountCents'], $l['taxCents'], $l['foreignUnitPriceCents'], $l['foreignAmountCents'], $l['foreignTaxCents'], $l['sortOrder']]);
        if (!empty($v['codesMode'])) { $cs = $pdo->prepare('UPDATE invoice_lines SET tax_code_id=? WHERE invoice_id=? AND sort_order=?'); foreach ($v['calculatedLines'] as $l) if (!empty($l['taxCodeId'])) $cs->execute([$l['taxCodeId'], $i['id'], $l['sortOrder']]); }
        if (schema_table_exists('vouchers')) $pdo->prepare("UPDATE vouchers SET voucher_date=?,description=?,amount_cents=?,journal_entry_id=COALESCE(?,journal_entry_id) WHERE company_id=? AND source_type='invoice' AND source_id=?")
            ->execute([$newDate, mb_substr('Customer invoice ' . $i['number'], 0, 500), $newTotal, $newEntryId, $companyId, $i['id']]);

        // 3. Payments stay as received. Any amount applied beyond the new total is released from the newest
        //    applications and remains on the payment as an unapplied customer credit (no accounting entry: it is
        //    already a credit in receivables once the invoice's receivable is reduced).
        $released = [];
        $toRelease = $credit;
        if ($toRelease > 0) {
            $opStmt = $pdo->prepare("INSERT INTO party_payment_application_operations(id,company_id,user_id,operation_key,payload_hash,status,result_json) VALUES(?,?,?,?,?,'completed',?)");
            $touched = [];
            foreach ($apps as $a) {
                if ($toRelease <= 0) break;
                $take = min($toRelease, (int)$a['document_carrying_cents']);
                if ($take <= 0) continue;
                $keep = (int)$a['document_carrying_cents'] - $take;
                $releaseOp = new_id('payapplyop'); $hash = hash('sha256', $op . '|' . $a['id']);
                $opStmt->execute([$releaseOp, $companyId, $user['id'], 'r182:' . $op . ':' . $a['id'] . ':release', $hash, json_encode(['invoiceCorrection' => $op, 'releasedCents' => $take])]);
                $pdo->prepare("UPDATE party_payment_applications SET status='reversed',reversed_by=?,reversed_at=UTC_TIMESTAMP() WHERE company_id=? AND id=? AND status='posted'")->execute([$user['id'], $companyId, $a['id']]);
                $pdo->prepare("INSERT INTO party_payment_applications(id,company_id,payment_id,document_type,document_id,partner_type,partner_id,application_date,foreign_amount_cents,payment_carrying_cents,document_carrying_cents,recognition_journal_entry_id,status,reversal_of_id,operation_id,created_by,reversed_by,reversed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NULL,'reversed',?,?,?,?,UTC_TIMESTAMP())")
                    ->execute([new_id('payapply'), $companyId, $a['payment_id'], 'invoice', $i['id'], 'customer', $a['partner_id'], $a['application_date'], (int)$a['foreign_amount_cents'], (int)$a['payment_carrying_cents'], (int)$a['document_carrying_cents'], $a['id'], $releaseOp, $user['id'], $user['id']]);
                if ($keep > 0) {
                    $keepOp = new_id('payapplyop');
                    $opStmt->execute([$keepOp, $companyId, $user['id'], 'r182:' . $op . ':' . $a['id'] . ':keep', hash('sha256', $op . '|' . $a['id'] . '|keep'), json_encode(['invoiceCorrection' => $op, 'keptCents' => $keep])]);
                    $pdo->prepare("INSERT INTO party_payment_applications(id,company_id,payment_id,document_type,document_id,partner_type,partner_id,application_date,foreign_amount_cents,payment_carrying_cents,document_carrying_cents,recognition_journal_entry_id,status,operation_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,NULL,'posted',?,?)")
                        ->execute([new_id('payapply'), $companyId, $a['payment_id'], 'invoice', $i['id'], 'customer', $a['partner_id'], $a['application_date'], $keep, $keep, $keep, $keepOp, $user['id']]);
                }
                $released[] = ['paymentId' => (string)$a['payment_id'], 'releasedCents' => $take];
                $touched[(string)$a['payment_id']] = true;
                $toRelease -= $take;
            }
            if ($toRelease !== 0) throw new RuntimeException('The customer credit could not be released from the payments.');
            foreach (array_keys($touched) as $pid) $pdo->prepare("UPDATE party_payments SET applied_cents=(SELECT COALESCE(SUM(payment_carrying_cents),0) FROM party_payment_applications WHERE company_id=? AND payment_id=? AND status='posted') WHERE company_id=? AND id=?")->execute([$companyId, $pid, $companyId, $pid]);
        }

        // 4. Checks before anything is kept: entries balance and the invoice agrees with its payments.
        foreach (array_filter([$reversalId, $newEntryId]) as $e) {
            $q = $pdo->prepare('SELECT SUM(debit_cents)-SUM(credit_cents) FROM journal_lines WHERE journal_entry_id=?'); $q->execute([$e]);
            if ((int)$q->fetchColumn() !== 0) throw new RuntimeException('A correction entry does not balance.');
        }
        $fresh = tegh_invoice_row_r20($companyId, (string)$i['id']);
        $after = tegh_r182_snapshot($companyId, $fresh, tegh_invoice_lines_r20((string)$i['id']));
        $q = $pdo->prepare("SELECT COALESCE(SUM(document_carrying_cents),0) FROM party_payment_applications WHERE company_id=? AND document_type='invoice' AND document_id=? AND status='posted'"); $q->execute([$companyId, $i['id']]);
        if ((int)$fresh['total_cents'] - (int)$fresh['balance_cents'] !== (int)$q->fetchColumn()) throw new RuntimeException('The corrected invoice does not agree with its payments.');

        $result['entries'] = ['reversal' => tegh_r182_entry_lines($companyId, $reversalId), 'corrected' => tegh_r182_entry_lines($companyId, $newEntryId)];
        $result['releasedFromPayments'] = $released;
        if ($dryRun) { $pdo->rollBack(); return $result; }

        // 5. History and audit.
        $n = $pdo->prepare('SELECT COALESCE(MAX(revision_number),0)+1 FROM invoice_revisions WHERE company_id=? AND invoice_id=?'); $n->execute([$companyId, $i['id']]); $number = (int)$n->fetchColumn();
        $pdo->prepare('INSERT INTO invoice_revisions(id,company_id,invoice_id,revision_number,reason,before_json,after_json,before_total_cents,after_total_cents,reversal_journal_entry_id,new_journal_entry_id,customer_credit_cents,operation_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$revId, $companyId, $i['id'], $number, $reason, json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (int)$i['total_cents'], $newTotal, $reversalId, $newEntryId, $credit, $op, $user['id']]);
        audit_event($user, $companyId, 'invoice.corrected', 'invoice', (string)$i['id'], ['revisionId' => $revId, 'revisionNumber' => $number, 'reason' => $reason, 'beforeTotalCents' => (int)$i['total_cents'], 'afterTotalCents' => $newTotal,
            'beforeDate' => $oldDate, 'afterDate' => $newDate, 'reversalJournalEntryId' => $reversalId, 'newJournalEntryId' => $newEntryId, 'customerCreditCents' => $credit, 'releasedFromPayments' => $released, 'operationId' => $op]);
        $revision = tegh_invoice_revision_r20($fresh, tegh_invoice_lines_r20((string)$i['id']));
        $out = tegh_invoice_operation_complete_r20($op, $result + ['revisionId' => $revId, 'revisionNumber' => $number, 'revision' => $revision,
            'message' => 'Invoice ' . $i['number'] . ' corrected.' . ($credit > 0 ? ' The customer has a credit of the difference.' : '')]);
        $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tegh_r182_history(array $user, array $company, string $invoiceId): array
{
    require_company_permission($company, 'invoices.view');
    $companyId = (string)$company['id'];
    tegh_invoice_row_r20($companyId, $invoiceId);
    if (!schema_table_exists('invoice_revisions')) return ['revisions' => []];
    $q = db()->prepare('SELECT r.*,u.display_name user_name,u.email user_email FROM invoice_revisions r LEFT JOIN users u ON u.id=r.created_by WHERE r.company_id=? AND r.invoice_id=? ORDER BY r.revision_number DESC');
    $q->execute([$companyId, $invoiceId]);
    return ['revisions' => array_map(static fn(array $r): array => [
        'id' => $r['id'], 'number' => (int)$r['revision_number'], 'reason' => $r['reason'], 'at' => $r['created_at'], 'by' => (string)($r['user_name'] ?: $r['user_email'] ?: ''),
        'beforeTotalCents' => (int)$r['before_total_cents'], 'afterTotalCents' => (int)$r['after_total_cents'], 'customerCreditCents' => (int)$r['customer_credit_cents'],
        'before' => json_decode((string)$r['before_json'], true), 'after' => json_decode((string)$r['after_json'], true),
        'reversalJournalEntryId' => $r['reversal_journal_entry_id'], 'newJournalEntryId' => $r['new_journal_entry_id'],
    ], $q->fetchAll(PDO::FETCH_ASSOC))];
}

/** Invoice detail: whether this user can correct it, and how many corrections it has. Added only while the switch allows it. */
function tegh_r182_detail_extra(array $user, array $company, array $invoice, array $out): array
{
    if (!tegh_r182_edit_available_for($user)) return $out;
    $can = company_role_can((string)$company['role'], 'invoices.write') && company_role_can((string)$company['role'], 'invoices.reverse');
    $count = 0;
    if (schema_table_exists('invoice_revisions')) { $q = db()->prepare('SELECT COUNT(*) FROM invoice_revisions WHERE company_id=? AND invoice_id=?'); $q->execute([$company['id'], $invoice['id']]); $count = (int)$q->fetchColumn(); }
    $issued = in_array((string)$invoice['status'], ['sent', 'paid'], true) && empty($invoice['is_opening_document']);
    $out['capabilities']['correct'] = $can && $issued;
    $out['correction'] = ['available' => $issued, 'allowed' => $can, 'reason' => $can ? null : 'Correcting an issued invoice needs the permissions to create invoices and to reverse transactions.', 'count' => $count, 'accountingBasis' => (string)$company['accounting_basis']];
    $out['invoice']['correctionCount'] = $count;
    $out['invoice']['balanceCents'] = (int)$invoice['balance_cents'];
    return $out;
}

function handle_invoice_correction_r182(string $action): never
{
    $user = require_user();
    $company = require_company($user);
    if ($action === 'history') {
        require_method('GET');
        json_response(tegh_r182_history($user, $company, clean_text($_GET['invoiceId'] ?? '', 'Invoice', 64)));
    }
    require_method('POST');
    require_csrf();
    $input = request_json();
    if ($action === 'preview') json_response(['preview' => tegh_r182_correct($user, $company, $input, true)]);
    if ($action === '') { tegh_invoice_operation_key_r20($input); json_response(['correction' => tegh_r182_correct($user, $company, $input, false)]); }
    fail('Unknown invoice correction action.', 404, 'not_found');
}
