<?php
declare(strict_types=1);

/*
 * R133: client viewing links.
 *
 * An accountant (company owner or admin) shares a read-only link with a
 * bookkeeping client. Opening the link emails a one-time six-digit code to the
 * client's address; entering it starts a short view session. The session can
 * read the company's live dashboard summary and only the reports the
 * accountant chose. It cannot change anything, and a forwarded link is useless
 * without access to the client's mailbox.
 *
 * Secrets are never stored in clear: link tokens, codes and session tokens are
 * kept as HMAC hashes. Tables are additive and created on first use.
 */

const TEGH_CLIENT_VIEW_CODE_TTL = 600;          // 10 minutes
const TEGH_CLIENT_VIEW_CODE_ATTEMPTS = 5;
const TEGH_CLIENT_VIEW_CODES_PER_HOUR = 5;
const TEGH_CLIENT_VIEW_SESSION_TTL = 43200;     // 12 hours

function client_view_report_catalog(): array
{
    // Payroll, audit history and working papers are deliberately not offered.
    return [
        'profit-loss' => 'Profit and Loss',
        'balance-sheet' => 'Balance Sheet',
        'cash-flow' => 'Cash Flow Statement',
        'trial-balance' => 'Trial Balance',
        'ar-ageing' => 'Accounts Receivable Ageing',
        'ap-ageing' => 'Accounts Payable Ageing',
        'tax-summary' => 'GST/HST Summary',
        'invoice-register' => 'Customer Invoice & Note Register',
        'bill-register' => 'Vendor Invoice & Note Register',
        'expense-register' => 'Expense Register',
        'general-ledger' => 'General Ledger Detail',
        'reconciliation-summary' => 'Bank Reconciliation Report',
    ];
}

function client_view_require_schema(): void
{
    static $ready = false;
    if ($ready) return;
    if (!schema_table_exists('client_view_links')) {
        db()->exec("CREATE TABLE IF NOT EXISTS client_view_links (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  created_by VARCHAR(64) NOT NULL,
  client_name VARCHAR(160) NOT NULL,
  client_email VARCHAR(254) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  reports_json TEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  revoked_by VARCHAR(64) NULL,
  last_viewed_at DATETIME NULL,
  view_count INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY client_view_links_token (token_hash),
  KEY client_view_links_company (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!schema_table_exists('client_view_codes')) {
        db()->exec("CREATE TABLE IF NOT EXISTS client_view_codes (
  id VARCHAR(64) PRIMARY KEY,
  link_id VARCHAR(64) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  used_at DATETIME NULL,
  ip_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY client_view_codes_link (link_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!schema_table_exists('client_view_sessions')) {
        db()->exec("CREATE TABLE IF NOT EXISTS client_view_sessions (
  id CHAR(64) PRIMARY KEY,
  link_id VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY client_view_sessions_link (link_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready = true;
}

function client_view_url(string $token): string
{
    // The token travels in the fragment, so it never reaches server logs or
    // Referer headers; the page posts it to the API explicitly.
    return rtrim((string)config('app.base_url'), '/') . '/client-view.html#t=' . rawurlencode($token);
}

function client_view_cookie_name(): string
{
    return (string)config('app.session_cookie') . '_client';
}

function client_view_set_cookie(string $value, int $expires): void
{
    setcookie(client_view_cookie_name(), $value, ['expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
}

function client_view_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $shown = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));
    return $shown . str_repeat('•', max(2, mb_strlen($local) - mb_strlen($shown))) . '@' . $domain;
}

function client_view_link_status(array $row): string
{
    if ($row['revoked_at'] !== null) return 'revoked';
    if (strtotime((string)$row['expires_at'] . ' UTC') <= time()) return 'expired';
    return 'active';
}

function client_view_public_link(array $row): array
{
    $reports = json_decode((string)$row['reports_json'], true);
    return [
        'id' => (string)$row['id'],
        'clientName' => (string)$row['client_name'],
        'clientEmail' => (string)$row['client_email'],
        'reports' => is_array($reports) ? array_values($reports) : [],
        'expiresAt' => (string)$row['expires_at'],
        'revokedAt' => $row['revoked_at'] !== null ? (string)$row['revoked_at'] : null,
        'lastViewedAt' => $row['last_viewed_at'] !== null ? (string)$row['last_viewed_at'] : null,
        'viewCount' => (int)$row['view_count'],
        'createdAt' => (string)$row['created_at'],
        'createdBy' => (string)($row['creator_name'] ?? ''),
        'status' => client_view_link_status($row),
    ];
}

function client_view_clean_reports(mixed $value): array
{
    if (!is_array($value)) fail('Choose at least one report.', 422, 'client_view_reports_required');
    $catalog = client_view_report_catalog();
    $reports = [];
    foreach ($value as $key) {
        $key = (string)$key;
        if (!isset($catalog[$key])) fail('One of the chosen reports cannot be shared.', 422, 'client_view_report_invalid');
        $reports[$key] = true;
    }
    if (!$reports) fail('Choose at least one report.', 422, 'client_view_reports_required');
    return array_keys($reports);
}

function client_view_send_link_email(array $company, array $user, string $clientName, string $clientEmail, string $url, string $expiresAt): array
{
    $firm = trim((string)($user['display_name'] ?? $user['displayName'] ?? '')) ?: (string)$user['email'];
    $companyName = (string)($company['name'] ?? 'your company');
    $expires = gmdate('M j, Y', strtotime($expiresAt . ' UTC'));
    $subject = $companyName . ': your view-only dashboard from ' . $firm;
    $text = "Hello " . $clientName . ",\n\n" . $firm . " has shared a view-only dashboard and reports for " . $companyName . " in Tegh.\n\nOpen it here:\n" . $url . "\n\nWhen you open the link we'll email you a 6-digit code to confirm it's you. The link works until " . $expires . ". You can view and download reports; nothing can be changed.\n";
    $html = '<!doctype html><html><body style="margin:0;background:#f2f6f7;color:#18333b;font-family:Arial,sans-serif"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:12px;border:1px solid #dbe6e2"><tr><td style="padding:28px">'
        . '<h1 style="margin:0 0 12px;font-size:20px">' . htmlspecialchars($companyName) . ' dashboard</h1>'
        . '<p style="margin:0 0 14px;line-height:1.5">Hello ' . htmlspecialchars($clientName) . ', ' . htmlspecialchars($firm) . ' has shared a view-only dashboard and reports with you.</p>'
        . '<p style="margin:0 0 18px"><a href="' . htmlspecialchars($url) . '" style="display:inline-block;background:#0f5c4b;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:bold">Open my dashboard</a></p>'
        . '<p style="margin:0;color:#5b6b65;font-size:13px;line-height:1.5">When you open it we\'ll email you a 6-digit code to confirm it\'s you. The link works until ' . htmlspecialchars($expires) . '. Nothing can be changed from this view.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
    return sr_mail_send((string)$company['id'], (string)$user['id'], $clientEmail, 'client_view_link', $subject, $text, $html);
}

/* ---------- Accountant side (signed-in owner or admin) ---------- */

function client_view_manage_context(): array
{
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner');
    client_view_require_schema();
    return [$user, $company];
}

function handle_client_view_manage(string $action): never
{
    [$user, $company] = client_view_manage_context();
    $companyId = (string)$company['id'];
    if ($action === 'catalog') {
        require_method('GET');
        $catalog = [];
        foreach (client_view_report_catalog() as $key => $title) $catalog[] = ['key' => $key, 'title' => $title];
        json_response(['reports' => $catalog]);
    }
    if ($action === 'links' && request_method() === 'GET') {
        $stmt = db()->prepare('SELECT l.*, COALESCE(u.display_name, u.email) creator_name FROM client_view_links l LEFT JOIN users u ON u.id = l.created_by WHERE l.company_id = ? ORDER BY l.created_at DESC LIMIT 200');
        $stmt->execute([$companyId]);
        json_response(['links' => array_map('client_view_public_link', $stmt->fetchAll())]);
    }
    if ($action === 'links' && request_method() === 'POST') {
        require_csrf();
        $input = request_json();
        $name = clean_text($input['clientName'] ?? '', 'Client name', 160);
        $email = safe_email($input['clientEmail'] ?? '');
        $reports = client_view_clean_reports($input['reports'] ?? null);
        $days = (int)($input['expiresInDays'] ?? 90);
        if (!in_array($days, [7, 30, 90, 180, 365], true)) fail('Choose how long the link works.', 422, 'client_view_expiry_invalid');
        $token = base64url_encode(random_bytes(32));
        $id = new_id('clientview');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        db()->prepare('INSERT INTO client_view_links (id, company_id, created_by, client_name, client_email, token_hash, reports_json, expires_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $companyId, $user['id'], $name, $email, secret_hash($token), json_encode($reports), $expiresAt]);
        $url = client_view_url($token);
        $mail = !empty($input['sendEmail']) ? client_view_send_link_email($company, $user, $name, $email, $url, $expiresAt) : null;
        audit_event($user, $companyId, 'client_view.link_created', 'client_view_link', $id, ['clientEmail' => $email, 'reports' => $reports, 'expiresAt' => $expiresAt, 'emailed' => (bool)($mail['sent'] ?? false)]);
        json_response(['link' => ['id' => $id, 'url' => $url, 'expiresAt' => $expiresAt], 'email' => $mail ? ['sent' => (bool)($mail['sent'] ?? false), 'message' => (string)($mail['message'] ?? '')] : null], 201);
    }
    if ($action === 'links/revoke' || $action === 'links/reissue' || $action === 'links/update') {
        require_method('POST');
        require_csrf();
        $input = request_json();
        $id = clean_text($input['linkId'] ?? '', 'Link', 64);
        $stmt = db()->prepare('SELECT * FROM client_view_links WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $companyId]);
        $link = $stmt->fetch();
        if (!$link) fail('Link not found.', 404, 'client_view_link_not_found');
        if ($action === 'links/revoke') {
            if ($link['revoked_at'] === null) {
                db()->prepare('UPDATE client_view_links SET revoked_at = UTC_TIMESTAMP(), revoked_by = ? WHERE id = ?')->execute([$user['id'], $id]);
                db()->prepare('DELETE FROM client_view_sessions WHERE link_id = ?')->execute([$id]);
                audit_event($user, $companyId, 'client_view.link_revoked', 'client_view_link', $id, ['clientEmail' => (string)$link['client_email']]);
            }
            json_response(['revoked' => true]);
        }
        if ($action === 'links/update') {
            $reports = client_view_clean_reports($input['reports'] ?? null);
            db()->prepare('UPDATE client_view_links SET reports_json = ? WHERE id = ?')->execute([json_encode($reports), $id]);
            audit_event($user, $companyId, 'client_view.link_updated', 'client_view_link', $id, ['reports' => $reports]);
            json_response(['updated' => true, 'reports' => $reports]);
        }
        // Reissue: new token (the old URL stops working), same client and reports.
        if ($link['revoked_at'] !== null) fail('This link was revoked. Create a new link instead.', 409, 'client_view_link_revoked');
        $days = (int)($input['expiresInDays'] ?? 90);
        if (!in_array($days, [7, 30, 90, 180, 365], true)) $days = 90;
        $token = base64url_encode(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        db()->prepare('UPDATE client_view_links SET token_hash = ?, expires_at = ? WHERE id = ?')->execute([secret_hash($token), $expiresAt, $id]);
        db()->prepare('DELETE FROM client_view_sessions WHERE link_id = ?')->execute([$id]);
        $url = client_view_url($token);
        $mail = !empty($input['sendEmail']) ? client_view_send_link_email($company, $user, (string)$link['client_name'], (string)$link['client_email'], $url, $expiresAt) : null;
        audit_event($user, $companyId, 'client_view.link_reissued', 'client_view_link', $id, ['clientEmail' => (string)$link['client_email'], 'expiresAt' => $expiresAt, 'emailed' => (bool)($mail['sent'] ?? false)]);
        json_response(['link' => ['id' => $id, 'url' => $url, 'expiresAt' => $expiresAt], 'email' => $mail ? ['sent' => (bool)($mail['sent'] ?? false), 'message' => (string)($mail['message'] ?? '')] : null]);
    }
    fail('Not found.', 404, 'route_not_found');
}

/* ---------- Client side (no Tegh account) ---------- */

function client_view_link_by_token(string $token): array
{
    if ($token === '' || strlen($token) > 200) fail('This viewing link is not valid.', 404, 'client_view_link_invalid');
    $stmt = db()->prepare('SELECT * FROM client_view_links WHERE token_hash = ?');
    $stmt->execute([secret_hash($token)]);
    $link = $stmt->fetch();
    if (!$link) fail('This viewing link is not valid. Ask your accountant for a new one.', 404, 'client_view_link_invalid');
    return client_view_assert_link_usable($link);
}

function client_view_assert_link_usable(array $link): array
{
    $status = client_view_link_status($link);
    if ($status === 'revoked') fail('This viewing link has been turned off. Ask your accountant for a new one.', 410, 'client_view_link_revoked');
    if ($status === 'expired') fail('This viewing link has expired. Ask your accountant for a new one.', 410, 'client_view_link_expired');
    // The person who shared the link must still be an active owner or admin
    // of the company; removing them from the company turns their links off.
    $stmt = db()->prepare("SELECT c.*, 'viewer' AS role FROM companies c JOIN company_members cm ON cm.company_id = c.id JOIN users u ON u.id = cm.user_id WHERE c.id = ? AND c.active = 1 AND cm.user_id = ? AND cm.role IN ('owner','admin') AND u.active = 1" . (schema_column_exists('company_members', 'status') ? " AND cm.status = 'active'" : '') . ' LIMIT 1');
    $stmt->execute([$link['company_id'], $link['created_by']]);
    $company = $stmt->fetch();
    if (!$company) fail('This viewing link is no longer available. Ask your accountant for a new one.', 410, 'client_view_link_unavailable');
    $link['__company'] = $company;
    return $link;
}

function client_view_session(): array
{
    client_view_require_schema();
    $raw = (string)($_COOKIE[client_view_cookie_name()] ?? '');
    if ($raw === '') fail('Please open your viewing link again.', 401, 'client_view_session_required');
    $stmt = db()->prepare('SELECT s.id session_id, s.expires_at session_expires, l.* FROM client_view_sessions s JOIN client_view_links l ON l.id = s.link_id WHERE s.id = ?');
    $stmt->execute([secret_hash($raw)]);
    $row = $stmt->fetch();
    if (!$row || strtotime((string)$row['session_expires'] . ' UTC') <= time()) {
        if ($row) db()->prepare('DELETE FROM client_view_sessions WHERE id = ?')->execute([$row['session_id']]);
        client_view_set_cookie('', time() - 3600);
        fail('Your viewing session has ended. Open your link again to get a new code.', 401, 'client_view_session_expired');
    }
    $link = client_view_assert_link_usable($row);
    db()->prepare('UPDATE client_view_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['session_id']]);
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$link['created_by']]);
    $creator = $stmt->fetch() ?: [];
    // Audit entries name the client; entitlement checks use the sharing
    // accountant's account, because the accountant is the one sharing.
    $actor = $creator;
    $actor['email'] = (string)$link['client_email'];
    $actor['display_name'] = (string)$link['client_name'] . ' (client view)';
    return [$link, $link['__company'], $actor, $creator];
}

function client_view_allowed_reports(array $link): array
{
    $reports = json_decode((string)$link['reports_json'], true);
    $catalog = client_view_report_catalog();
    return array_values(array_filter(is_array($reports) ? $reports : [], static fn($key): bool => isset($catalog[(string)$key])));
}

function handle_client_view_public(string $action): never
{
    client_view_require_schema();
    if ($action === 'start') {
        require_method('POST');
        assert_same_origin();
        $input = request_json();
        $link = client_view_link_by_token((string)($input['token'] ?? ''));
        $recent = db()->prepare('SELECT COUNT(*) FROM client_view_codes WHERE link_id = ? AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR)');
        $recent->execute([$link['id']]);
        if ((int)$recent->fetchColumn() >= TEGH_CLIENT_VIEW_CODES_PER_HOUR) fail('Too many codes were requested. Please wait an hour and try again.', 429, 'client_view_code_rate_limited');
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        db()->prepare('UPDATE client_view_codes SET used_at = UTC_TIMESTAMP() WHERE link_id = ? AND used_at IS NULL')->execute([$link['id']]);
        db()->prepare('INSERT INTO client_view_codes (id, link_id, code_hash, expires_at, ip_hash) VALUES (?,?,?,?,?)')
            ->execute([new_id('cvcode'), $link['id'], secret_hash($link['id'] . '|' . $code), gmdate('Y-m-d H:i:s', time() + TEGH_CLIENT_VIEW_CODE_TTL), client_ip_hash()]);
        $company = $link['__company'];
        $subject = 'Your Tegh viewing code: ' . $code;
        $text = "Your code to view " . $company['name'] . " is: " . $code . "\n\nIt expires in 10 minutes. If you did not open a viewing link, you can ignore this email.";
        $html = '<!doctype html><html><body style="margin:0;background:#f2f6f7;font-family:Arial,sans-serif;color:#18333b"><table role="presentation" width="100%"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="480" style="max-width:480px;background:#fff;border:1px solid #dbe6e2;border-radius:12px"><tr><td style="padding:28px"><p style="margin:0 0 10px">Your code to view <b>' . htmlspecialchars((string)$company['name']) . '</b>:</p><p style="margin:0 0 14px;font-size:30px;letter-spacing:6px;font-weight:bold">' . $code . '</p><p style="margin:0;color:#5b6b65;font-size:13px">It expires in 10 minutes. If you did not open a viewing link, you can ignore this email.</p></td></tr></table></td></tr></table></body></html>';
        $mail = sr_mail_send((string)$company['id'], (string)$link['created_by'], (string)$link['client_email'], 'client_view_code', $subject, $text, $html);
        if (empty($mail['sent'])) fail('We could not email your code right now. Please try again shortly or contact your accountant.', 502, 'client_view_code_not_sent');
        json_response(['sentTo' => client_view_mask_email((string)$link['client_email']), 'companyName' => (string)$company['name'], 'clientName' => (string)$link['client_name'], 'expiresInMinutes' => (int)(TEGH_CLIENT_VIEW_CODE_TTL / 60)]);
    }
    if ($action === 'verify') {
        require_method('POST');
        assert_same_origin();
        $input = request_json();
        $link = client_view_link_by_token((string)($input['token'] ?? ''));
        $code = preg_replace('/\D+/', '', (string)($input['code'] ?? '')) ?? '';
        $stmt = db()->prepare('SELECT * FROM client_view_codes WHERE link_id = ? AND used_at IS NULL ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$link['id']]);
        $row = $stmt->fetch();
        if (!$row || strtotime((string)$row['expires_at'] . ' UTC') <= time()) fail('This code has expired. Ask for a new code.', 410, 'client_view_code_expired');
        if ((int)$row['attempts'] >= TEGH_CLIENT_VIEW_CODE_ATTEMPTS) fail('Too many wrong codes. Ask for a new code.', 429, 'client_view_code_locked');
        if (strlen($code) !== 6 || !hash_equals((string)$row['code_hash'], secret_hash($link['id'] . '|' . $code))) {
            db()->prepare('UPDATE client_view_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
            $left = TEGH_CLIENT_VIEW_CODE_ATTEMPTS - (int)$row['attempts'] - 1;
            fail($left > 0 ? 'That code is not right. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.' : 'Too many wrong codes. Ask for a new code.', 422, 'client_view_code_invalid');
        }
        db()->prepare('UPDATE client_view_codes SET used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['id']]);
        $session = base64url_encode(random_bytes(32));
        $expires = time() + TEGH_CLIENT_VIEW_SESSION_TTL;
        db()->prepare('INSERT INTO client_view_sessions (id, link_id, expires_at) VALUES (?,?,?)')->execute([secret_hash($session), $link['id'], gmdate('Y-m-d H:i:s', $expires)]);
        db()->prepare('UPDATE client_view_links SET last_viewed_at = UTC_TIMESTAMP(), view_count = view_count + 1 WHERE id = ?')->execute([$link['id']]);
        client_view_set_cookie($session, $expires);
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$link['created_by']]);
        $actor = $stmt->fetch() ?: ['id' => $link['created_by'], 'email' => ''];
        $actor['email'] = (string)$link['client_email'];
        audit_event($actor, (string)$link['company_id'], 'client_view.signed_in', 'client_view_link', (string)$link['id'], ['viaClientView' => true, 'clientName' => (string)$link['client_name']]);
        json_response(['ok' => true]);
    }
    if ($action === 'signout') {
        require_method('POST');
        assert_same_origin();
        $raw = (string)($_COOKIE[client_view_cookie_name()] ?? '');
        if ($raw !== '') db()->prepare('DELETE FROM client_view_sessions WHERE id = ?')->execute([secret_hash($raw)]);
        client_view_set_cookie('', time() - 3600);
        json_response(['ok' => true]);
    }

    [$link, $company, $actor, $creator] = client_view_session();
    $allowed = client_view_allowed_reports($link);
    $catalog = client_view_report_catalog();
    if ($action === 'me') {
        require_method('GET');
        json_response([
            'clientName' => (string)$link['client_name'],
            'company' => ['name' => (string)$company['name'], 'legalName' => (string)($company['legal_name'] ?? $company['name']), 'currency' => (string)$company['currency']],
            'sharedBy' => trim((string)($creator['display_name'] ?? '')) ?: (string)($creator['email'] ?? ''),
            'reports' => array_map(static fn(string $key): array => ['key' => $key, 'title' => $catalog[$key], 'mode' => (string)(tegh_report_definitions_5980()[$key]['periodMode'] ?? 'range')], $allowed),
            'linkExpiresAt' => (string)$link['expires_at'],
        ]);
    }
    if ($action === 'dashboard') {
        require_method('GET');
        require_once __DIR__ . '/workspace_summary_v5610.php';
        $data = tegh_workspace_summary_data($company);
        $s = $data['summary'] ?? [];
        // Only the dashboard's summary figures leave the server.
        $keys = ['periodStart', 'periodEnd', 'bankBalanceCents', 'unpaidInvoicesCents', 'overdueInvoicesCents', 'openInvoiceCount', 'payableSubledgerCents', 'openBillCount', 'netIncomeYtdCents', 'incomeYtdCents', 'expensesYtdCents', 'taxSummary'];
        json_response(['summary' => array_intersect_key($s, array_flip($keys)), 'currency' => (string)$company['currency']]);
    }
    if ($action === 'report') {
        require_method('GET');
        $key = (string)($_GET['key'] ?? '');
        if (!in_array($key, $allowed, true)) fail('This report has not been shared with you.', 403, 'client_view_report_forbidden');
        $params = array_intersect_key($_GET, array_flip(['start', 'end', 'asOf', 'from', 'to']));
        // The report builder re-authorizes at the end through the request's company
        // header; for a client view that re-check confirms the sharing
        // accountant still has access to this company.
        $_SERVER['HTTP_X_COMPANY_ID'] = (string)$company['id'];
        try { $output = tegh_service_boundary(fn() => tegh_report_output_5980($actor, $company, $key, $params)); }
        catch (Throwable $error) { tegh_fail_service($error); }
        // R134: dashboard charts read the same shared reports; the sign-in already
        // records the visit, so chart loads are not logged one by one.
        if (($_GET['purpose'] ?? '') !== 'chart') audit_event($actor, (string)$company['id'], 'client_view.report_viewed', 'client_view_link', (string)$link['id'], ['viaClientView' => true, 'report' => $key, 'parameters' => $params]);
        json_response(['output' => $output]);
    }
    if ($action === 'download') {
        require_method('POST');
        assert_same_origin();
        $input = request_json();
        $key = (string)($input['key'] ?? '');
        $format = (string)($input['format'] ?? '');
        if (!in_array($key, $allowed, true) || !in_array($format, ['pdf', 'xlsx'], true)) fail('This download is not available.', 403, 'client_view_download_forbidden');
        audit_event($actor, (string)$company['id'], 'client_view.report_downloaded', 'client_view_link', (string)$link['id'], ['viaClientView' => true, 'report' => $key, 'format' => $format]);
        json_response(['ok' => true]);
    }
    fail('Not found.', 404, 'route_not_found');
}

function handle_client_view(string $route): never
{
    $action = trim(substr($route, strlen('client-view')), '/');
    if (in_array($action, ['catalog', 'links', 'links/revoke', 'links/reissue', 'links/update'], true)) handle_client_view_manage($action);
    handle_client_view_public($action);
}
