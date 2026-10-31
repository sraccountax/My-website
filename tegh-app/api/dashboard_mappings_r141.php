<?php
declare(strict_types=1);

/**
 * R141: choose which GL accounts feed each dashboard figure.
 *
 * Each card can stay "Automatic" (Tegh's standard calculation) or use GL
 * accounts the owner chooses. Only the figure shown on the dashboard (and the
 * client viewing dashboard) changes; control checks such as AR/AP against
 * their subledgers keep using the standard figures.
 */

function tegh_dashboard_cards(): array
{
    return [
        'bank' => ['label' => 'Money in the bank', 'module' => 'Banking', 'lists' => ['plus' => 'Bank and cash accounts to add up'], 'sign' => 'debit', 'types' => ['asset', 'liability']],
        'receivables' => ['label' => 'Customers owe you', 'module' => 'Receivables', 'lists' => ['plus' => 'Receivable accounts to add up'], 'sign' => 'debit', 'types' => ['asset']],
        'payables' => ['label' => 'You owe suppliers', 'module' => 'Payables', 'lists' => ['plus' => 'Payable accounts to add up'], 'sign' => 'credit', 'types' => ['liability']],
        'profit' => ['label' => 'Profit this year', 'module' => 'General Ledger', 'lists' => ['plus' => 'Income accounts', 'minus' => 'Expense accounts (subtracted)'], 'sign' => 'profit', 'types' => ['income', 'expense']],
        'tax' => ['label' => 'Sales tax you owe', 'module' => 'Tax', 'lists' => ['plus' => 'Tax collected accounts', 'minus' => 'Tax paid / recoverable accounts (subtracted)'], 'sign' => 'tax', 'types' => ['liability', 'asset']],
    ];
}

function tegh_dashboard_mappings_ready(): bool
{
    static $ready = null;
    if ($ready === true) return true;
    if (schema_table_exists('company_dashboard_mappings')) return $ready = true;
    if (db()->inTransaction()) return false;
    db()->exec("CREATE TABLE IF NOT EXISTS company_dashboard_mappings (
      company_id VARCHAR(64) NOT NULL,
      card_key VARCHAR(32) NOT NULL,
      mapping_json TEXT NOT NULL,
      updated_by VARCHAR(64) NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (company_id, card_key),
      CONSTRAINT company_dashboard_mappings_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $ready = true;
}

/** @return array<string,array{plus:string[],minus:string[]}> */
function tegh_dashboard_mappings(string $companyId): array
{
    if (!schema_table_exists('company_dashboard_mappings')) return [];
    $q = db()->prepare('SELECT card_key, mapping_json FROM company_dashboard_mappings WHERE company_id=?');
    $q->execute([$companyId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = json_decode((string)$r['mapping_json'], true);
        if (!is_array($m)) continue;
        $out[(string)$r['card_key']] = ['plus' => array_values(array_map('strval', (array)($m['plus'] ?? []))), 'minus' => array_values(array_map('strval', (array)($m['minus'] ?? [])))];
    }
    return $out;
}

/** Apply saved mappings to a workspace summary payload. Balances are debit-minus-credit by account id. */
function tegh_dashboard_apply_mappings(array $company, array $result, array $signedById, array $periodSignedById): array
{
    $maps = tegh_dashboard_mappings((string)$company['id']);
    if (!$maps || !isset($result['summary']) || !is_array($result['summary'])) return $result;
    $sum = static function (array $ids, array $balances): int { $t = 0; foreach ($ids as $id) $t += (int)($balances[$id] ?? 0); return $t; };
    $s = &$result['summary'];
    $mapped = [];
    foreach ($maps as $card => $m) {
        if (!$m['plus'] && !$m['minus']) continue;
        if ($card === 'bank') { $s['bankBalanceCents'] = $sum($m['plus'], $signedById); $mapped[] = $card; }
        elseif ($card === 'receivables') { $s['unpaidInvoicesCents'] = $sum($m['plus'], $signedById); $mapped[] = $card; }
        elseif ($card === 'payables') { $s['payableSubledgerCents'] = -$sum($m['plus'], $signedById); $mapped[] = $card; }
        elseif ($card === 'profit') {
            $income = -$sum($m['plus'], $periodSignedById); $expenses = $sum($m['minus'], $periodSignedById);
            $s['incomeYtdCents'] = $income; $s['expensesYtdCents'] = $expenses; $s['netIncomeYtdCents'] = $income - $expenses; $mapped[] = $card;
        } elseif ($card === 'tax') {
            $collected = -$sum($m['plus'], $signedById); $paid = $sum($m['minus'], $signedById);
            $s['taxSummary'] = ['gstHstCollectedCents' => $collected, 'gstHstRecoverableCents' => $paid, 'gstHstNetCents' => $collected - $paid, 'pstPayableCents' => 0, 'pstRecoverableCents' => 0, 'qstPayableCents' => 0, 'qstRecoverableCents' => 0, 'otherTaxNetCents' => 0, 'otherTaxAccountCount' => 0];
            $mapped[] = $card;
        }
    }
    $s['customMappedCards'] = $mapped;
    return $result;
}

function handle_dashboard_mappings(): never
{
    require_method('GET', 'PUT');
    $user = require_user(); $company = require_company($user); $companyId = (string)$company['id'];
    tegh_dashboard_mappings_ready();
    header('Cache-Control: private, no-store');
    if (request_method() === 'GET') {
        json_response(['cards' => tegh_dashboard_cards(), 'mappings' => (object)tegh_dashboard_mappings($companyId)]);
    }
    require_csrf();
    require_company_role($company, 'owner', 'admin');
    $input = request_json();
    $cards = tegh_dashboard_cards();
    $card = (string)($input['card'] ?? '');
    if (!isset($cards[$card])) fail('Choose a dashboard figure.', 422, 'dashboard_card_invalid');
    $types = $cards[$card]['types'];
    $clean = ['plus' => [], 'minus' => []];
    foreach (['plus', 'minus'] as $side) {
        if (!isset($cards[$card]['lists'][$side])) continue;
        $ids = $input[$side] ?? [];
        if (!is_array($ids) || count($ids) > 200) fail('Too many accounts.', 422, 'dashboard_accounts_invalid');
        foreach ($ids as $id) {
            $id = clean_text($id, 'GL account', 64);
            $q = db()->prepare('SELECT account_type FROM accounts WHERE id=? AND company_id=? AND active=1'); $q->execute([$id, $companyId]);
            $type = $q->fetchColumn();
            if ($type === false) fail('A chosen GL account is not available in this company.', 422, 'dashboard_account_unavailable');
            if ($card === 'profit' && (($side === 'plus' && $type !== 'income') || ($side === 'minus' && $type !== 'expense'))) fail('Profit uses income accounts and expense accounts only.', 422, 'dashboard_account_type');
            if (!in_array((string)$type, $types, true)) fail('That kind of account cannot feed this figure.', 422, 'dashboard_account_type');
            $clean[$side][] = $id;
        }
        $clean[$side] = array_values(array_unique($clean[$side]));
    }
    if (!empty($input['reset']) || (!$clean['plus'] && !$clean['minus'])) {
        db()->prepare('DELETE FROM company_dashboard_mappings WHERE company_id=? AND card_key=?')->execute([$companyId, $card]);
        audit_event($user, $companyId, 'dashboard.mapping_reset', 'company', $companyId, ['card' => $card]);
        json_response(['card' => $card, 'mapping' => null]);
    }
    if ($card === 'profit' || $card === 'tax') {
        if (!$clean['plus']) fail('Choose at least one account in the first list.', 422, 'dashboard_accounts_required');
    }
    db()->prepare('INSERT INTO company_dashboard_mappings (company_id, card_key, mapping_json, updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE mapping_json=VALUES(mapping_json), updated_by=VALUES(updated_by)')
        ->execute([$companyId, $card, json_encode($clean), $user['id']]);
    audit_event($user, $companyId, 'dashboard.mapping_saved', 'company', $companyId, ['card' => $card, 'mapping' => $clean]);
    json_response(['card' => $card, 'mapping' => $clean]);
}
