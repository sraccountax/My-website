<?php
declare(strict_types=1);

/*
 * R122: bank reconciliation position calculated from the books themselves.
 *
 * The bank-side balance is not read from a statement import. It is the
 * account's opening balance (Opening Balances) plus every imported bank
 * transaction up to a date, excluding lines marked excluded or duplicate.
 * The book balance is the General Ledger balance of the bank account on the
 * same date. The difference is explained by reconciling items:
 *
 *   bank balance
 *     - imported bank transactions not yet posted to the books      (unposted)
 *     - bank transactions dated on/before the end, posted after it   (bankTiming)
 *     + book entries with no bank transaction (cheques not cleared,
 *       deposits in transit, manual entries)                        (bookOnly)
 *     + book entries on/before the end whose bank transaction is
 *       dated after it                                              (bookTiming)
 *   = book balance, and anything left is the unexplained difference.
 */

/** @return array<string,mixed> */
function tegh_recon_position_r122(string $companyId, array $bank, string $start, string $end, int $itemLimit = 400): array
{
    $bankId = (string)$bank['id'];
    $ledgerId = (string)$bank['ledger_account_id'];
    $dayBefore = (new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');

    $openingStmt = db()->prepare("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id
        WHERE je.company_id=? AND jl.account_id=? AND je.status='posted' AND je.source_type='opening_balance' AND je.entry_date<=?");
    $bankSumStmt = db()->prepare("SELECT COALESCE(SUM(amount_cents),0),COUNT(*) FROM bank_transactions WHERE company_id=? AND bank_account_id=? AND status NOT IN ('excluded','duplicate') AND transaction_date<=?");
    $bookStmt = db()->prepare("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id
        WHERE je.company_id=? AND jl.account_id=? AND je.status='posted' AND je.entry_date<=?");
    $balanceAt = static function (string $date) use ($companyId, $bankId, $ledgerId, $openingStmt, $bankSumStmt, $bookStmt): array {
        $openingStmt->execute([$companyId, $ledgerId, $date]);
        $opening = (int)$openingStmt->fetchColumn();
        $bankSumStmt->execute([$companyId, $bankId, $date]);
        [$imported, $count] = $bankSumStmt->fetch(PDO::FETCH_NUM);
        $bookStmt->execute([$companyId, $ledgerId, $date]);
        return ['opening' => $opening, 'bank' => $opening + (int)$imported, 'book' => (int)$bookStmt->fetchColumn(), 'count' => (int)$count];
    };
    // The period opens with everything before the start date, plus opening
    // balances dated on the start date itself (they describe the position at
    // the beginning of that day, not activity during the period).
    $atStart = $balanceAt($dayBefore);
    $openingStmt->execute([$companyId, $ledgerId, $start]);
    $openingOnStart = (int)$openingStmt->fetchColumn() - $atStart['opening'];
    if ($openingOnStart !== 0) { $atStart['opening'] += $openingOnStart; $atStart['bank'] += $openingOnStart; $atStart['book'] += $openingOnStart; }
    $atEnd = $balanceAt($end);

    // Bank transactions in the period, with a running bank balance.
    $txStmt = db()->prepare("SELECT bt.id,bt.transaction_date,bt.description,bt.reference,bt.amount_cents,bt.status,bt.journal_entry_id FROM bank_transactions bt
        WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.status NOT IN ('excluded','duplicate') AND bt.transaction_date BETWEEN ? AND ? ORDER BY bt.transaction_date,bt.created_at,bt.id");
    $txStmt->execute([$companyId, $bankId, $start, $end]);
    $running = $atStart['bank'];
    $deposits = 0;
    $withdrawals = 0;
    $lines = [];
    foreach ($txStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $amount = (int)$row['amount_cents'];
        $running += $amount;
        if ($amount >= 0) $deposits += $amount; else $withdrawals += -$amount;
        if (count($lines) < $itemLimit) {
            $lines[] = ['id' => (string)$row['id'], 'date' => (string)$row['transaction_date'], 'description' => (string)$row['description'], 'reference' => (string)($row['reference'] ?? ''),
                'amountCents' => $amount, 'status' => (string)$row['status'], 'runningBalanceCents' => $running];
        }
    }

    // Links between bank transactions and journal entries: the direct posting
    // journal of a bank line, plus journals matched to it in an active match group.
    $links = [];
    $direct = db()->prepare("SELECT bt.id bank_id,bt.transaction_date bank_date,bt.amount_cents,bt.status,je.id journal_id,je.entry_date FROM bank_transactions bt JOIN journal_entries je ON je.id=bt.journal_entry_id AND je.company_id=bt.company_id AND je.status='posted'
        WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.status NOT IN ('excluded','duplicate')");
    $direct->execute([$companyId, $bankId]);
    foreach ($direct->fetchAll(PDO::FETCH_ASSOC) as $row) $links[] = $row;
    $matched = db()->prepare("SELECT bt.id bank_id,bt.transaction_date bank_date,bt.amount_cents,bt.status,je.id journal_id,je.entry_date FROM bank_match_groups g
        JOIN bank_match_bank_items bi ON bi.match_group_id=g.id JOIN bank_transactions bt ON bt.id=bi.bank_transaction_id AND bt.company_id=g.company_id
        JOIN bank_match_book_items mi ON mi.match_group_id=g.id JOIN journal_entries je ON je.id=mi.journal_entry_id AND je.company_id=g.company_id AND je.status='posted'
        WHERE g.company_id=? AND g.bank_account_id=? AND g.status='matched' AND bt.status NOT IN ('excluded','duplicate')");
    $matched->execute([$companyId, $bankId]);
    foreach ($matched->fetchAll(PDO::FETCH_ASSOC) as $row) $links[] = $row;
    $journalBankDate = [];
    $bankJournalDate = [];
    foreach ($links as $row) {
        $jid = (string)$row['journal_id'];
        $bid = (string)$row['bank_id'];
        $journalBankDate[$jid] = isset($journalBankDate[$jid]) ? min($journalBankDate[$jid], (string)$row['bank_date']) : (string)$row['bank_date'];
        $bankJournalDate[$bid] = isset($bankJournalDate[$bid]) ? min($bankJournalDate[$bid], (string)$row['entry_date']) : (string)$row['entry_date'];
    }

    $groups = [
        'unposted' => ['label' => 'Imported bank transactions not yet posted to the books', 'effect' => -1, 'totalCents' => 0, 'items' => []],
        'bankTiming' => ['label' => 'On the bank by the period end, posted in the books after it', 'effect' => -1, 'totalCents' => 0, 'items' => []],
        'bookOnly' => ['label' => 'In the books, not on the bank (cheques not cleared, deposits in transit, manual entries)', 'effect' => 1, 'totalCents' => 0, 'items' => []],
        'bookTiming' => ['label' => 'In the books by the period end, on the bank after it', 'effect' => 1, 'totalCents' => 0, 'items' => []],
    ];
    $push = static function (string $group, array $item) use (&$groups, $itemLimit): void {
        $groups[$group]['totalCents'] += (int)$item['amountCents'];
        if (count($groups[$group]['items']) < $itemLimit) $groups[$group]['items'][] = $item;
    };

    $bankRows = db()->prepare("SELECT id,transaction_date,description,reference,amount_cents,status FROM bank_transactions
        WHERE company_id=? AND bank_account_id=? AND status NOT IN ('excluded','duplicate') AND transaction_date<=? ORDER BY transaction_date,id");
    $bankRows->execute([$companyId, $bankId, $end]);
    foreach ($bankRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $item = ['id' => (string)$row['id'], 'date' => (string)$row['transaction_date'], 'description' => (string)$row['description'], 'reference' => (string)($row['reference'] ?? ''), 'amountCents' => (int)$row['amount_cents']];
        $journalDate = $bankJournalDate[(string)$row['id']] ?? null;
        if ((string)$row['status'] === 'pending' || $journalDate === null) {
            $item['reason'] = (string)$row['status'] === 'pending' ? 'Not posted' : 'No posted journal';
            $push('unposted', $item);
        } elseif ($journalDate > $end) {
            $item['bookDate'] = $journalDate;
            $push('bankTiming', $item);
        }
    }

    $bookRows = db()->prepare("SELECT je.id,je.entry_date,je.memo,je.source_type,v.voucher_number,SUM(jl.debit_cents-jl.credit_cents) amount_cents FROM journal_entries je
        JOIN journal_lines jl ON jl.journal_entry_id=je.id AND jl.account_id=? LEFT JOIN vouchers v ON v.company_id=je.company_id AND v.source_type=je.source_type AND v.source_id=je.source_id
        WHERE je.company_id=? AND je.status='posted' AND je.entry_date<=? AND je.source_type<>'opening_balance'
        GROUP BY je.id,je.entry_date,je.memo,je.source_type,v.voucher_number HAVING SUM(jl.debit_cents-jl.credit_cents)<>0 ORDER BY je.entry_date,je.id");
    $bookRows->execute([$ledgerId, $companyId, $end]);
    foreach ($bookRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $jid = (string)$row['id'];
        $item = ['journalEntryId' => $jid, 'date' => (string)$row['entry_date'], 'description' => (string)($row['memo'] ?? ''), 'reference' => (string)($row['voucher_number'] ?? ''),
            'sourceType' => (string)$row['source_type'], 'amountCents' => (int)$row['amount_cents']];
        if (!isset($journalBankDate[$jid])) {
            $push('bookOnly', $item);
        } elseif ($journalBankDate[$jid] > $end) {
            $item['bankDate'] = $journalBankDate[$jid];
            $push('bookTiming', $item);
        }
    }

    $difference = $atEnd['bank'] - $atEnd['book'];
    $explained = $groups['unposted']['totalCents'] + $groups['bankTiming']['totalCents'] - $groups['bookOnly']['totalCents'] - $groups['bookTiming']['totalCents'];
    return [
        'account' => ['id' => $bankId, 'name' => (string)($bank['name'] ?? ''), 'currency' => (string)($bank['currency'] ?? '')],
        'period' => ['start' => $start, 'end' => $end],
        'openingBalanceCents' => $atEnd['opening'],
        'bank' => ['openingCents' => $atStart['bank'], 'depositsCents' => $deposits, 'withdrawalsCents' => $withdrawals, 'closingCents' => $atEnd['bank'], 'transactionCount' => count($lines)],
        'book' => ['openingCents' => $atStart['book'], 'closingCents' => $atEnd['book']],
        'differenceCents' => $difference,
        'explainedCents' => $explained,
        'unexplainedCents' => $difference - $explained,
        'reconcilingItems' => $groups,
        'transactions' => $lines,
    ];
}

function tegh_recon_bank_r122(string $companyId, string $bankId): array
{
    $stmt = db()->prepare("SELECT id,name,currency,ledger_account_id FROM bank_accounts WHERE id=? AND company_id=? AND account_type IN ('bank','credit_card')");
    $stmt->execute([$bankId, $companyId]);
    $bank = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bank) fail('Choose a valid bank or credit-card account.', 404, 'bank_account_unavailable');
    return $bank;
}

function operations_reconciliation_position_r122(array $company): never
{
    require_method('GET');
    require_company_permission($company, 'banking.view');
    $companyId = (string)$company['id'];
    $bank = tegh_recon_bank_r122($companyId, clean_text($_GET['bankAccountId'] ?? '', 'Bank account', 64));
    $end = safe_date($_GET['end'] ?? '', 'Period end');
    $startRaw = trim((string)($_GET['start'] ?? ''));
    $start = $startRaw === '' ? substr($end, 0, 8) . '01' : safe_date($startRaw, 'Period start');
    if ($start > $end) fail('Period start cannot be after period end.');
    json_response(['position' => tegh_recon_position_r122($companyId, $bank, $start, $end)]);
}

/*
 * R122: balances of the sales-tax and payroll control accounts as of a date,
 * used to prefill a CRA payroll remittance and to preview a GST/HST return
 * payment (collected tax less input tax credits). Read-only.
 */
function operations_control_balances_r122(array $company): never
{
    require_method('GET');
    require_company_permission($company, 'reports.view');
    $companyId = (string)$company['id'];
    $asOf = safe_date($_GET['asOf'] ?? '', 'As of date');
    $codes = ['1100', '1110', '1115', '2100', '2110', '2115', '2310', '2320', '2330', '2340'];
    $stmt = db()->prepare("SELECT a.code,a.name,COALESCE(SUM(CASE WHEN je.id IS NULL THEN 0 ELSE jl.debit_cents-jl.credit_cents END),0) balance FROM accounts a
        LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id AND je.status='posted' AND je.entry_date<=?
        WHERE a.company_id=? AND a.code IN (" . implode(',', array_fill(0, count($codes), '?')) . ") GROUP BY a.code,a.name");
    $stmt->execute(array_merge(['9999-12-31', $companyId], $codes));
    $current = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $current[(string)$row['code']] = (int)$row['balance'];
    $stmt->execute(array_merge([$asOf, $companyId], $codes));
    $accounts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $accounts[(string)$row['code']] = ['name' => (string)$row['name'], 'debitBalanceCents' => (int)$row['balance']];
    $credit = static fn(string $code): int => -(int)($accounts[$code]['debitBalanceCents'] ?? 0);
    $debit = static fn(string $code): int => (int)($accounts[$code]['debitBalanceCents'] ?? 0);
    // Credits that can still be cleared: never more than what remains on the account today.
    $clearable = static fn(string $code): int => max(0, min($debit($code), (int)($current[$code] ?? 0)));
    json_response(['asOf' => $asOf, 'accounts' => $accounts,
        'clearable' => ['gstHstItcCents' => $clearable('1100'), 'pstRecoverableCents' => $clearable('1110'), 'qstRecoverableCents' => $clearable('1115')],
        'salesTax' => ['gstHstCollectedCents' => $credit('2100'), 'gstHstItcCents' => $debit('1100'), 'gstHstNetCents' => $credit('2100') - $debit('1100'),
            'pstCollectedCents' => $credit('2110'), 'pstRecoverableCents' => $debit('1110'), 'pstNetCents' => $credit('2110') - $debit('1110'),
            'qstCollectedCents' => $credit('2115'), 'qstRecoverableCents' => $debit('1115'), 'qstNetCents' => $credit('2115') - $debit('1115')],
        'payroll' => ['incomeTaxCents' => $credit('2310'), 'cppCents' => $credit('2320'), 'eiCents' => $credit('2330'), 'otherDeductionsCents' => $credit('2340')]]);
}
