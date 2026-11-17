<?php
declare(strict_types=1);

/**
 * R182: bank transactions entered by hand (no statement), optionally repeated monthly, and the check that a later
 * statement line may be the same transaction.
 *
 * A hand-entered line is an ordinary unposted bank transaction: it is reviewed, matched, posted and reconciled like
 * an imported one. It is marked by source_hash "manual:…" and suggestion_source "manual", and it carries no import
 * batch.
 */

const R182_MANUAL_DUPLICATE_DAYS = 5;

/** Same day of the month, n months after $start (the month's last day when that month is shorter). */
function r182_month_step(string $start, int $months): string
{
    $d = new DateTimeImmutable($start);
    $day = (int)$d->format('j');
    $first = $d->modify('first day of this month')->modify('+' . $months . ' months');
    $last = (int)$first->format('t');
    return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($day, $last))->format('Y-m-d');
}

function handle_bank_transaction_add(): never
{
    require_method('POST'); require_csrf(); $user = require_user(); $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper'); require_company_permission($company, 'banking.match');
    $companyId = (string)$company['id']; $input = request_json();
    $bankId = clean_text($input['bankAccountId'] ?? '', 'Bank or card account', 64);
    $date = safe_date($input['date'] ?? '', 'Transaction date');
    $description = trim((string)($input['description'] ?? ''));
    if ($description === '' || mb_strlen($description) > 500) fail('Enter a description of up to 500 characters.', 422, 'bank_transaction_description_invalid');
    $reference = trim((string)($input['reference'] ?? '')); if (mb_strlen($reference) > 120) fail('Keep the reference to 120 characters.', 422, 'bank_transaction_reference_invalid');
    $remarks = trim((string)($input['remarks'] ?? '')); if (mb_strlen($remarks) > 500) fail('Keep the remarks to 500 characters.', 422, 'bank_transaction_remarks_invalid');
    $amountRaw = $input['amountCents'] ?? null;
    if (!is_int($amountRaw) && !(is_string($amountRaw) && preg_match('/^-?\d+$/', $amountRaw))) fail('Enter the amount.', 422, 'bank_transaction_amount_invalid');
    $amount = (int)$amountRaw; if ($amount === 0 || abs($amount) > 99999999999) fail('Enter a non-zero amount (money in is positive, money out negative).', 422, 'bank_transaction_amount_invalid');
    $repeat = (int)($input['repeatMonths'] ?? 1);
    if ($repeat < 1 || $repeat > 24) fail('Repeat for 1 to 24 months.', 422, 'bank_transaction_repeat_invalid');
    $operationKey = clean_text($input['operationKey'] ?? '', 'Operation key', 120);

    $b = db()->prepare('SELECT id, name, currency FROM bank_accounts WHERE id = ? AND company_id = ? AND active = 1');
    $b->execute([$bankId, $companyId]); $bank = $b->fetch();
    if (!$bank) fail('Choose an active bank or credit-card account.', 422, 'bank_account_invalid');
    $currency = strtoupper((string)($bank['currency'] ?: $company['currency']));

    $dates = [];
    for ($i = 0; $i < $repeat; $i++) $dates[] = r182_month_step($date, $i);
    $latestAllowed = max(canadian_today(), gmdate('Y-m-d'));
    $future = array_values(array_filter($dates, static fn(string $d): bool => $d > $latestAllowed));
    if ($future) fail('Dates after today cannot be entered (' . $future[0] . '). Repeat for fewer months, or add later months when they happen.', 422, 'bank_transaction_future');
    foreach ($dates as $d) if (period_lock_for_date($companyId, $d) !== null) fail('The date ' . $d . ' is in a locked period.', 409, 'period_locked');

    // Each line's base amount: the account's own currency is the base, or the rate for that date (R181 rule).
    $rates = [];
    if ($currency !== (string)$company['currency']) {
        $currencyRow = company_currency($companyId, $currency);
        if (!$currencyRow) fail('Set an exchange rate for ' . $currency . ' under Settings › Currency Exchange Rates first.', 422, 'exchange_rate_required');
        foreach ($dates as $d) $rates[$d] = function_exists('tegh_r181_document_rate')
            ? tegh_r181_document_rate($company, $currency, $d, $input['exchangeRateMicros'] ?? null, $currencyRow, $input, 'bank transaction')
            : safe_exchange_rate_micros($input['exchangeRateMicros'] ?? $currencyRow['rate_to_base_micros'], $currency, (string)$company['currency']);
    }

    $created = [];
    db()->beginTransaction();
    try {
        $ins = db()->prepare("INSERT INTO bank_transactions (id, company_id, bank_account_id, import_batch_id, transaction_date, description, reference, remarks, amount_cents, currency, foreign_amount_cents, exchange_rate_micros, suggestion_source, source_hash, status)
            VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, 'pending')");
        $seen = db()->prepare('SELECT id FROM bank_transactions WHERE company_id = ? AND source_hash = ?');
        foreach ($dates as $i => $d) {
            // Repeat-safe: the same operation key creates each line once.
            $hash = 'manual:' . substr(hash('sha256', $companyId . '|' . $operationKey . '|' . $i), 0, 57); // source_hash is 64 characters
            $seen->execute([$companyId, $hash]);
            if ($id = $seen->fetchColumn()) { $created[] = ['id' => (string)$id, 'date' => $d, 'replayed' => true]; continue; }
            $rate = $rates[$d] ?? 1_000_000;
            $base = $rate === 1_000_000 ? $amount : convert_to_base_cents($amount, $rate);
            $id = new_id('btx');
            $ins->execute([$id, $companyId, $bankId, $d, $description, $reference !== '' ? $reference : null, $remarks, $base, $currency, $amount, $rate, $hash]);
            if (function_exists('voucher_register_saved')) voucher_register_saved($user, $companyId, 'BT', 'BS', 'bank_transaction', $id, $d, 'Entered by hand · ' . $description, abs($base), null, false);
            $created[] = ['id' => $id, 'date' => $d, 'replayed' => false];
        }
        $new = array_values(array_filter($created, static fn(array $c): bool => !$c['replayed']));
        if ($new) audit_event($user, $companyId, 'bank_transaction.added_manually', 'bank_account', $bankId, [
            'transactionIds' => array_column($new, 'id'), 'dates' => array_column($new, 'date'), 'amountCents' => $amount, 'currency' => $currency,
            'description' => $description, 'reference' => $reference, 'repeatMonths' => $repeat, 'initiatedVia' => 'Manual UI',
        ]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    json_response(['transactions' => $created, 'bankAccount' => ['id' => $bankId, 'name' => (string)$bank['name'], 'currency' => $currency]], 201);
}

/**
 * Statement import: a line on the same account with the same amount within 5 days of a transaction entered by hand
 * may be the same transaction. It is left unselected until the person confirms it is separate.
 */
function r182_flag_manual_duplicates(string $companyId, array $rows): array
{
    $byBank = [];
    foreach ($rows as $row) {
        if (!empty($row['duplicate']) || !empty($row['requiresDuplicateReview']) || empty($row['sourceBankAccountId']) || empty($row['date'])) continue;
        $byBank[(string)$row['sourceBankAccountId']][] = (string)$row['date'];
    }
    if (!$byBank) return $rows;
    $manual = [];
    foreach ($byBank as $bankId => $dates) {
        $from = (new DateTimeImmutable(min($dates)))->modify('-' . R182_MANUAL_DUPLICATE_DAYS . ' days')->format('Y-m-d');
        $to = (new DateTimeImmutable(max($dates)))->modify('+' . R182_MANUAL_DUPLICATE_DAYS . ' days')->format('Y-m-d');
        $q = db()->prepare("SELECT id, transaction_date, description, foreign_amount_cents FROM bank_transactions
            WHERE company_id = ? AND bank_account_id = ? AND source_hash LIKE 'manual:%' AND status <> 'deleted' AND transaction_date BETWEEN ? AND ?");
        $q->execute([$companyId, $bankId, $from, $to]);
        foreach ($q->fetchAll() as $m) $manual[$bankId][(int)$m['foreign_amount_cents']][] = $m;
    }
    foreach ($rows as &$row) {
        if (!empty($row['duplicate']) || !empty($row['requiresDuplicateReview'])) continue;
        $hits = [];
        foreach ($manual[(string)($row['sourceBankAccountId'] ?? '')][(int)($row['foreignAmountCents'] ?? 0)] ?? [] as $m) {
            $days = abs((strtotime((string)$m['transaction_date']) - strtotime((string)$row['date'])) / 86400);
            if ($days <= R182_MANUAL_DUPLICATE_DAYS) $hits[] = $m;
        }
        if (!$hits) continue;
        $row['requiresDuplicateReview'] = true;
        $row['manualDuplicateIds'] = array_column($hits, 'id');
        $row['legacyDuplicateIds'] = array_values(array_unique(array_merge($row['legacyDuplicateIds'] ?? [], array_column($hits, 'id'))));
        $row['validationReason'] = 'May duplicate a transaction entered by hand on ' . (string)$hits[0]['transaction_date'] . ' ("' . mb_substr((string)$hits[0]['description'], 0, 60)
            . '"). Keep only one: leave this line unselected, or confirm it is a separate transaction.';
    }
    unset($row);
    return $rows;
}
