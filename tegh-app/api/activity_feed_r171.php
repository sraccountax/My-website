<?php
declare(strict_types=1);

/*
 * R171: Company feed. A short, plain-language stream of what is happening in the selected company, built from the
 * audit trail that every action already writes. Nothing new is recorded and nothing is changed here.
 *
 * - Only listed business events appear (invoices, payments, bank work, journals, closing, people, ...). Interface
 *   preferences, downloads, sign-ins and other technical entries never appear.
 * - Who did it is shown by display name (or the part of the address before "@"); emails, IP and browser fingerprints
 *   recorded in the audit trail are never returned.
 * - Readable by anyone who may read the company's audit history (audit.view). Payroll events need payroll.view and
 *   carry no amounts. Team-member names need users.view; otherwise the post says "a team member".
 * - Text from records (memo, bank description, names) is returned as data; the browser escapes it.
 */

const R171_PAGE_MAX = 50;

/** action => category. Categories are the feed's filters. */
function r171_actions(): array
{
    return [
        'invoice.issued' => 'sales', 'invoice.draft_created' => 'sales', 'invoice.voided' => 'sales',
        'customer.payment_posted' => 'sales', 'customer.created' => 'sales', 'customer.hold_placed' => 'sales', 'customer.hold_released' => 'sales',
        'bill.issued' => 'purchases', 'bill.draft_created' => 'purchases', 'bill.approved_posted' => 'purchases', 'bill.submitted_for_approval' => 'purchases',
        'vendor.payment_posted' => 'purchases', 'vendor.created' => 'purchases', 'expense.posted' => 'purchases',
        'invoice_intel.uploaded' => 'purchases', 'invoice_intel.duplicate_overridden' => 'purchases', 'native_document.uploaded' => 'purchases',
        'accounting_note.created' => 'notes', 'accounting_note.posted' => 'notes', 'accounting_note.voided' => 'notes',
        'bank_transaction.posted' => 'banking', 'statement.approved' => 'banking', 'reconciliation.completed' => 'banking',
        'guided.statement_month_reconciled' => 'banking', 'bank_account.created' => 'banking', 'transaction.voided' => 'banking',
        'interbank_transfer.origin_posted' => 'banking',
        'journal.manual_posted' => 'ledger', 'journal.approved_posted' => 'ledger', 'opening_trial_balance.posted' => 'ledger',
        'opening_balance.journal_posted' => 'ledger', 'period.locked' => 'ledger', 'period.unlocked' => 'ledger',
        'account.created' => 'ledger', 'tax_code.created' => 'ledger', 'budget.created' => 'ledger', 'asset.depreciation_posted' => 'ledger',
        'payroll.run_finalized' => 'payroll', 'payroll.gl_posted' => 'payroll',
        'company.created' => 'company', 'company.updated' => 'company', 'onboarding.completed' => 'company', 'backup.exported' => 'company',
        'company.member_role_changed' => 'company', 'company.member_removed' => 'company', 'company.member_suspended' => 'company',
        'company.member_reactivated' => 'company',
    ];
}

function r171_text(mixed $value, int $max = 120): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
    return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
}

function r171_cents(mixed $value): ?int
{
    return is_numeric($value) ? (int)$value : null;
}

/** Names of the records the posts mention, looked up in one query per table and limited to this company. */
function r171_lookup(string $companyId, string $sql, array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids, static fn($id) => is_string($id) && $id !== '')));
    if ($ids === []) return [];
    $stmt = db()->prepare(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))));
    $stmt->execute(array_merge([$companyId], $ids));
    $out = [];
    foreach ($stmt->fetchAll() as $row) $out[(string)$row['id']] = $row;
    return $out;
}

function r171_actor_name(array $row): string
{
    $name = trim((string)($row['display_name'] ?? ''));
    if ($name !== '') return r171_text($name, 60);
    $email = (string)($row['actor_email'] ?? '');
    $local = strstr($email, '@', true);
    return r171_text($local !== false && $local !== '' ? $local : 'Someone', 60);
}

/**
 * One feed post from one audit row, or null when the row is not shown. $ctx holds the looked-up names and the
 * reader's permissions.
 */
function r171_post(array $row, array $meta, array $ctx): ?array
{
    $action = (string)$row['action'];
    $category = r171_actions()[$action] ?? null;
    if ($category === null) return null;
    $entity = (string)$row['entity_id'];
    $money = static fn(?int $cents, string $currency = '') => $cents === null ? null : ['cents' => $cents, 'currency' => $currency !== '' ? $currency : $ctx['currency']];
    $inv = $ctx['invoices'][$entity] ?? null;
    $bill = $ctx['bills'][$entity] ?? null;
    $number = r171_text($meta['number'] ?? $meta['documentNumber'] ?? ($inv['number'] ?? $bill['number'] ?? ''), 40);
    $customer = r171_text($inv['party'] ?? '', 80);
    $vendor = r171_text($bill['party'] ?? '', 80);
    $party = r171_text($meta['partyName'] ?? '', 80);
    $text = ''; $detail = ''; $amount = null; $link = null; $group = '';
    switch ($action) {
        case 'invoice.issued':
            $text = 'issued invoice ' . $number . ($customer !== '' ? ' to ' . $customer : '');
            $amount = $money(r171_cents($meta['totalCents'] ?? null)); $link = ['invoice', $entity]; break;
        case 'invoice.draft_created':
            $text = 'started a draft invoice ' . $number . ($customer !== '' ? ' for ' . $customer : '');
            $amount = $money(r171_cents($meta['totalCents'] ?? null)); $link = ['invoice', $entity]; break;
        case 'invoice.voided':
            $text = 'voided invoice ' . $number . ($customer !== '' ? ' to ' . $customer : ''); $link = ['invoice', $entity]; break;
        case 'customer.payment_posted':
            $text = 'recorded a payment from ' . ($party !== '' ? $party : 'a customer') . ($number !== '' ? ' for ' . $number : '');
            $amount = $money(r171_cents($meta['amountCents'] ?? null)); $link = ['customer_payment', $entity]; $group = 'customer.payment_posted'; break;
        case 'customer.created':
            $text = 'added a new customer, ' . r171_text($meta['name'] ?? 'unnamed', 80); $group = 'customer.created'; break;
        case 'customer.hold_placed':
            $text = 'put a customer on credit hold'; break;
        case 'customer.hold_released':
            $text = 'released a customer from credit hold'; break;
        case 'bill.issued':
            $text = 'entered vendor invoice ' . $number . ($vendor !== '' ? ' from ' . $vendor : '');
            $amount = $money(r171_cents($meta['totalCents'] ?? null)); $link = ['bill', $entity]; break;
        case 'bill.draft_created':
            $text = 'prepared a draft vendor invoice ' . $number . ($vendor !== '' ? ' from ' . $vendor : '');
            if (($meta['initiatedVia'] ?? '') === 'invoice_intelligence') $detail = 'Read from the supplier’s PDF by Invoice Intelligence';
            $amount = $money(r171_cents($meta['totalCents'] ?? null)); $link = ['bill', $entity]; break;
        case 'bill.submitted_for_approval':
            $text = 'sent vendor invoice ' . $number . ' for approval'; $link = ['bill', $entity]; break;
        case 'bill.approved_posted':
            $text = 'approved and posted vendor invoice ' . $number . ($vendor !== '' ? ' from ' . $vendor : ''); $link = ['bill', $entity]; break;
        case 'vendor.payment_posted':
            $text = 'paid ' . ($party !== '' ? $party : 'a supplier') . ($number !== '' ? ' for ' . $number : '');
            $amount = $money(r171_cents($meta['amountCents'] ?? null)); $link = ['vendor_payment', $entity]; $group = 'vendor.payment_posted'; break;
        case 'vendor.created':
            $text = 'added a new supplier, ' . r171_text($meta['name'] ?? 'unnamed', 80); $group = 'vendor.created'; break;
        case 'expense.posted':
            $text = 'recorded an expense' . (($meta['vendor'] ?? '') !== '' ? ' at ' . r171_text($meta['vendor'], 80) : '');
            $amount = $money(r171_cents($meta['totalCents'] ?? null)); $link = ['expense', $entity]; break;
        case 'invoice_intel.uploaded':
            $text = 'uploaded a supplier invoice for Invoice Intelligence to read'; $link = ['invoice_intel_job', $entity]; $group = 'invoice_intel.uploaded'; break;
        case 'invoice_intel.duplicate_overridden':
            $text = 'overrode a possible-duplicate warning on a supplier invoice'; $detail = 'Reason kept in the audit history'; $link = ['invoice_intel_job', $entity]; break;
        case 'native_document.uploaded':
            if (isset($ctx['intelDocs'][$entity])) return null; // the Invoice Intelligence post already says it
            $text = 'uploaded a document to Document Intake'; $group = 'native_document.uploaded'; break;
        case 'accounting_note.created':
        case 'accounting_note.posted':
        case 'accounting_note.voided':
            $note = $ctx['notes'][$entity] ?? null;
            $kind = (string)($meta['kind'] ?? $note['note_kind'] ?? '');
            $label = ['customer_credit' => 'credit note', 'customer_debit' => 'debit note', 'vendor_credit' => 'supplier credit note', 'vendor_debit' => 'supplier debit note'][$kind] ?? 'note';
            $num = r171_text($meta['number'] ?? $note['number'] ?? '', 40);
            $verb = $action === 'accounting_note.created' ? 'prepared a draft ' : ($action === 'accounting_note.voided' ? 'voided ' : 'posted ');
            $text = $verb . $label . ($num !== '' ? ' ' . $num : '');
            $amount = $action === 'accounting_note.voided' ? null : $money(r171_cents($note['total_cents'] ?? null));
            $link = [str_starts_with($kind, 'vendor') ? 'vendor_accounting_note' : 'accounting_note', $entity]; break;
        case 'bank_transaction.posted':
            $tx = $ctx['bankTx'][$entity] ?? null;
            $text = 'posted a bank transaction' . ($tx && $tx['description'] !== '' ? ': ' . r171_text($tx['description'], 70) : '');
            $amount = $tx ? $money(r171_cents($tx['amount_cents'])) : null; $link = ['bank_transaction', $entity]; $group = 'bank_transaction.posted'; break;
        case 'statement.approved':
            $n = (int)($meta['selectedCount'] ?? 0);
            $text = 'imported ' . ($n > 0 ? $n . ' bank statement line' . ($n === 1 ? '' : 's') : 'a bank statement'); break;
        case 'reconciliation.completed':
            $text = 'reconciled the bank to ' . r171_text($meta['periodEnd'] ?? '', 10);
            $amount = $money(r171_cents($meta['statementBalanceCents'] ?? null)); $detail = 'Statement balance'; break;
        case 'guided.statement_month_reconciled':
            $text = 'reconciled the bank statement ending ' . r171_text($meta['periodEnd'] ?? '', 10);
            $amount = $money(r171_cents($meta['statementClosingCents'] ?? null)); $detail = 'Closing balance'; break;
        case 'bank_account.created':
            $text = 'added the bank account ' . r171_text($meta['name'] ?? '', 80); break;
        case 'transaction.voided':
            $text = 'voided a transaction'; $detail = r171_text($meta['reason'] ?? '', 120);
            $amount = $money(r171_cents($meta['amountCents'] ?? null)); break;
        case 'interbank_transfer.origin_posted':
            $text = 'recorded a transfer between the company’s own accounts'; break;
        case 'journal.manual_posted':
            $text = 'posted a journal entry' . (($meta['memo'] ?? '') !== '' ? ': ' . r171_text($meta['memo'], 80) : ''); $link = ['journal', $entity]; break;
        case 'journal.approved_posted':
            $text = 'approved and posted a journal entry'; $link = ['journal', $entity]; break;
        case 'opening_trial_balance.posted':
        case 'opening_balance.journal_posted':
            $text = 'entered opening balances' . (($meta['effectiveDate'] ?? '') !== '' ? ' as of ' . r171_text($meta['effectiveDate'], 10) : ''); break;
        case 'period.locked':
            $text = 'closed the books through ' . r171_text($meta['periodEnd'] ?? '', 10); $detail = 'Entries on or before that date are locked'; break;
        case 'period.unlocked':
            $text = 'reopened a closed period' . (($meta['periodEnd'] ?? '') !== '' ? ' (through ' . r171_text($meta['periodEnd'], 10) . ')' : ''); break;
        case 'account.created':
            $text = 'added account ' . r171_text(trim(($meta['code'] ?? '') . ' ' . ($meta['name'] ?? '')), 90); break;
        case 'tax_code.created':
            $text = 'added tax code ' . r171_text($meta['code'] ?? '', 30); break;
        case 'budget.created':
            $text = 'created the budget ' . r171_text($meta['name'] ?? '', 80); break;
        case 'asset.depreciation_posted':
            $text = 'posted depreciation'; break;
        case 'payroll.run_finalized':
            $text = 'finalized a pay run'; $link = ['payroll', $entity]; break;
        case 'payroll.gl_posted':
            $text = 'posted payroll to the books'; $link = ['payroll', $entity]; break;
        case 'company.created':
            $text = 'created the company'; break;
        case 'company.updated':
            $text = 'updated the company settings'; break;
        case 'onboarding.completed':
            $text = 'finished setting up the company'; break;
        case 'backup.exported':
            $text = 'downloaded a backup of the company'; break;
        case 'company.member_role_changed':
        case 'company.member_removed':
        case 'company.member_suspended':
        case 'company.member_reactivated':
            $who = 'a team member';
            if ($ctx['canSeeMembers']) { $n = $ctx['memberNames'][strtolower((string)($meta['email'] ?? ''))] ?? ''; if ($n !== '') $who = $n; }
            $text = match ($action) {
                'company.member_role_changed' => 'changed ' . $who . '’s role' . (($meta['to'] ?? '') !== '' ? ' to ' . r171_text($meta['to'], 20) : ''),
                'company.member_removed' => 'removed ' . $who . ' from the company',
                'company.member_suspended' => 'suspended ' . $who,
                default => 'reactivated ' . $who,
            };
            break;
    }
    if ($text === '') return null;
    return [
        'id' => (string)$row['id'],
        'at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$row['created_at'] . ' UTC')),
        'category' => $category,
        'action' => $action,
        'group' => $group,
        'actor' => ['name' => r171_actor_name($row), 'you' => (string)$row['actor_user_id'] === $ctx['userId']],
        'text' => $text,
        'detail' => $detail,
        'amount' => $amount,
        'link' => $link ? ['type' => $link[0], 'id' => $link[1]] : null,
    ];
}

function r171_feed(array $user, array $company, array $query): array
{
    require_company_permission($company, 'audit.view');
    $companyId = (string)$company['id'];
    $role = (string)($company['role'] ?? '');
    $actions = r171_actions();
    if (!company_role_can($role, 'payroll.view')) $actions = array_filter($actions, static fn($c) => $c !== 'payroll');
    $category = (string)($query['category'] ?? '');
    if ($category !== '' && $category !== 'all') {
        if (!in_array($category, ['sales', 'purchases', 'notes', 'banking', 'ledger', 'payroll', 'company'], true)) fail('Choose a feed filter from the list.', 422, 'feed_category_invalid');
        $actions = array_filter($actions, static fn($c) => $c === $category);
    }
    $limit = max(1, min(R171_PAGE_MAX, (int)($query['limit'] ?? 30)));
    $where = ['a.company_id = ?'];
    $params = [$companyId];
    if ($actions === []) return ['items' => [], 'next' => null, 'latest' => null];
    $where[] = 'a.action IN (' . implode(',', array_fill(0, count($actions), '?')) . ')';
    array_push($params, ...array_keys($actions));
    if (($query['who'] ?? '') === 'others') { $where[] = 'a.actor_user_id <> ?'; $params[] = (string)$user['id']; }
    // Cursors are "<UTC timestamp>|<audit id>"; "before" pages back, "after" fetches newer posts.
    $cursor = static function (string $value): ?array {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([A-Za-z0-9_-]{1,64})$/', $value, $m)) return null;
        return [$m[1], $m[2]];
    };
    $before = $cursor((string)($query['before'] ?? ''));
    $after = $cursor((string)($query['after'] ?? ''));
    if ($before) { $where[] = '(a.created_at < ? OR (a.created_at = ? AND a.id < ?))'; array_push($params, $before[0], $before[0], $before[1]); }
    if ($after) { $where[] = '(a.created_at > ? OR (a.created_at = ? AND a.id > ?))'; array_push($params, $after[0], $after[0], $after[1]); }
    $sql = 'SELECT a.id,a.actor_user_id,a.actor_email,a.action,a.entity_type,a.entity_id,a.metadata_json,a.created_at,u.display_name
            FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . ($limit + 1);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);

    $ids = static fn(array $types) => array_map(static fn($r) => (string)$r['entity_id'], array_filter($rows, static fn($r) => in_array((string)$r['entity_type'], $types, true)));
    $ctx = [
        'userId' => (string)$user['id'],
        'currency' => (string)($company['currency'] ?? 'CAD'),
        'invoices' => r171_lookup($companyId, 'SELECT i.id,i.number,c.name party FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.company_id=? AND i.id IN (%s)', $ids(['invoice'])),
        'bills' => r171_lookup($companyId, 'SELECT b.id,b.number,v.name party FROM bills b LEFT JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id WHERE b.company_id=? AND b.id IN (%s)', $ids(['bill'])),
        'notes' => schema_table_exists('accounting_notes') ? r171_lookup($companyId, 'SELECT id,number,note_kind,total_cents FROM accounting_notes WHERE company_id=? AND id IN (%s)', $ids(['accounting_note'])) : [],
        'bankTx' => r171_lookup($companyId, 'SELECT id,description,amount_cents FROM bank_transactions WHERE company_id=? AND id IN (%s)', $ids(['bank_transaction'])),
        'intelDocs' => schema_table_exists('invoice_intel_jobs') ? r171_lookup($companyId, 'SELECT DISTINCT document_id id FROM invoice_intel_jobs WHERE company_id=? AND document_id IN (%s)', $ids(['native_agent_document','native_document'])) : [],
        'canSeeMembers' => company_role_can($role, 'users.view'),
        'memberNames' => [],
    ];
    if ($ctx['canSeeMembers']) {
        $emails = [];
        foreach ($rows as $r) if (str_starts_with((string)$r['action'], 'company.member_')) { $m = json_decode((string)$r['metadata_json'], true); if (!empty($m['email'])) $emails[] = strtolower((string)$m['email']); }
        if ($emails !== []) {
            $emails = array_values(array_unique($emails));
            $q = db()->prepare('SELECT LOWER(email) email,display_name FROM users WHERE LOWER(email) IN (' . implode(',', array_fill(0, count($emails), '?')) . ')');
            $q->execute($emails);
            foreach ($q->fetchAll() as $u) $ctx['memberNames'][(string)$u['email']] = trim((string)$u['display_name']) !== '' ? r171_text($u['display_name'], 60) : r171_text(strstr((string)$u['email'], '@', true) ?: 'a team member', 60);
        }
    }
    $items = [];
    foreach ($rows as $row) {
        $meta = json_decode((string)$row['metadata_json'], true);
        $post = r171_post($row, is_array($meta) ? $meta : [], $ctx);
        if ($post) $items[] = $post;
    }
    $last = $rows === [] ? null : end($rows);
    $first = $rows[0] ?? null;
    return [
        'items' => $items,
        'next' => $more && $last ? (string)$last['created_at'] . '|' . (string)$last['id'] : null,
        'latest' => $first ? (string)$first['created_at'] . '|' . (string)$first['id'] : null,
        'serverTime' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
}

function handle_activity_feed(string $action): never
{
    require_method('GET');
    $user = require_user();
    $company = require_company($user);
    if ($action === '' || $action === 'items') json_response(r171_feed($user, $company, $_GET));
    fail('Company feed route not found.', 404, 'route_not_found');
}
