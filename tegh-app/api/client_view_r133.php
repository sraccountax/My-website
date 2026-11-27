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
    if (!schema_column_exists('client_view_links', 'customer_id')) {
        db()->exec('ALTER TABLE client_view_links ADD COLUMN customer_id VARCHAR(64) NULL AFTER client_email');
    }
    if (!schema_column_exists('client_view_links', 'capabilities_json')) {
        db()->exec("ALTER TABLE client_view_links ADD COLUMN capabilities_json TEXT NOT NULL AFTER reports_json");
        db()->exec("UPDATE client_view_links SET capabilities_json='[\"dashboard\",\"reports\"]' WHERE capabilities_json='' OR capabilities_json IS NULL");
    }
    db()->exec("CREATE TABLE IF NOT EXISTS client_portal_documents (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, link_id VARCHAR(64) NOT NULL,
      direction ENUM('client_to_team','team_to_client') NOT NULL,
      original_name VARCHAR(240) NOT NULL, storage_path VARCHAR(500) NOT NULL, mime_type VARCHAR(120) NOT NULL,
      size_bytes BIGINT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL,
      uploaded_by_user VARCHAR(64) NULL, uploaded_by_client TINYINT(1) NOT NULL DEFAULT 0,
      removed_at DATETIME NULL, removed_by VARCHAR(64) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY client_portal_documents_scope (company_id,link_id,created_at),
      CONSTRAINT client_portal_documents_link_fk FOREIGN KEY (link_id) REFERENCES client_view_links(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS client_portal_requests (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, link_id VARCHAR(64) NOT NULL,
      subject VARCHAR(180) NOT NULL, details TEXT NOT NULL,
      status ENUM('open','responded','closed') NOT NULL DEFAULT 'open', response TEXT NULL,
      created_by_client TINYINT(1) NOT NULL DEFAULT 1, created_by_user VARCHAR(64) NULL,
      responded_by VARCHAR(64) NULL, responded_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY client_portal_requests_scope (company_id,link_id,status,created_at),
      CONSTRAINT client_portal_requests_link_fk FOREIGN KEY (link_id) REFERENCES client_view_links(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS client_portal_messages (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, link_id VARCHAR(64) NOT NULL,
      sender_type ENUM('client','team') NOT NULL, sender_user_id VARCHAR(64) NULL, body TEXT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY client_portal_messages_scope (company_id,link_id,created_at),
      CONSTRAINT client_portal_messages_link_fk FOREIGN KEY (link_id) REFERENCES client_view_links(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS client_portal_approvals (
      id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, link_id VARCHAR(64) NOT NULL,
      title VARCHAR(180) NOT NULL, scope_text TEXT NOT NULL,
      consequence ENUM('acknowledgement','request_completion') NOT NULL DEFAULT 'acknowledgement',
      status ENUM('pending','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
      response_note TEXT NULL, created_by VARCHAR(64) NOT NULL, responded_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY client_portal_approvals_scope (company_id,link_id,status,created_at),
      CONSTRAINT client_portal_approvals_link_fk FOREIGN KEY (link_id) REFERENCES client_view_links(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS client_portal_approval_history (
      id VARCHAR(64) PRIMARY KEY, approval_id VARCHAR(64) NOT NULL, company_id VARCHAR(64) NOT NULL,
      link_id VARCHAR(64) NOT NULL, action ENUM('created','approved','declined','cancelled') NOT NULL,
      actor_type ENUM('client','team') NOT NULL, actor_user_id VARCHAR(64) NULL, note TEXT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY client_portal_approval_history_scope (company_id,link_id,created_at),
      CONSTRAINT client_portal_approval_history_fk FOREIGN KEY (approval_id) REFERENCES client_portal_approvals(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

function client_view_capability_catalog(): array
{
    return ['dashboard','reports','invoices','documents','requests','approvals','messages'];
}

function client_view_clean_capabilities(mixed $value): array
{
    if (!is_array($value)) return ['dashboard','reports'];
    $allowed = array_flip(client_view_capability_catalog()); $out = [];
    foreach ($value as $item) { $item = (string)$item; if (!isset($allowed[$item])) fail('One of the client dashboard capabilities is invalid.',422,'client_view_capability_invalid'); $out[$item] = true; }
    $out['dashboard'] = true;
    return array_keys($out);
}

function client_view_capabilities(array $link): array
{
    $value = json_decode((string)($link['capabilities_json'] ?? ''), true);
    return client_view_clean_capabilities(is_array($value) ? $value : ['dashboard','reports']);
}

function client_view_require_capability(array $link, string $capability): void
{
    if (!in_array($capability, client_view_capabilities($link), true)) fail('This client dashboard feature has not been shared with you.',403,'client_view_capability_forbidden');
}

function client_view_clean_customer_id(array $company, mixed $value, array $capabilities): ?string
{
    $id = trim((string)$value);
    if ($id === '') {
        if (in_array('invoices',$capabilities,true)) fail('Choose the customer whose invoices the client may open.',422,'client_view_customer_required');
        return null;
    }
    $stmt = db()->prepare('SELECT id FROM customers WHERE id=? AND company_id=? AND active=1');
    $stmt->execute([$id,(string)$company['id']]);
    if ($stmt->fetchColumn() === false) fail('The selected customer is unavailable.',404,'client_view_customer_not_found');
    return $id;
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
        'customerId' => $row['customer_id'] !== null ? (string)$row['customer_id'] : null,
        'reports' => is_array($reports) ? array_values($reports) : [],
        'capabilities' => client_view_capabilities($row),
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
        $capabilities = client_view_clean_capabilities($input['capabilities'] ?? ['dashboard','reports']);
        $reports = in_array('reports',$capabilities,true) ? client_view_clean_reports($input['reports'] ?? null) : [];
        $customerId = client_view_clean_customer_id($company,$input['customerId'] ?? null,$capabilities);
        $days = (int)($input['expiresInDays'] ?? 90);
        if (!in_array($days, [7, 30, 90, 180, 365], true)) fail('Choose how long the link works.', 422, 'client_view_expiry_invalid');
        $token = base64url_encode(random_bytes(32));
        $id = new_id('clientview');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        db()->prepare('INSERT INTO client_view_links (id, company_id, created_by, client_name, client_email, customer_id, token_hash, reports_json, capabilities_json, expires_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $companyId, $user['id'], $name, $email, $customerId, secret_hash($token), json_encode($reports), json_encode($capabilities), $expiresAt]);
        $url = client_view_url($token);
        $mail = !empty($input['sendEmail']) ? client_view_send_link_email($company, $user, $name, $email, $url, $expiresAt) : null;
        audit_event($user, $companyId, 'client_view.link_created', 'client_view_link', $id, ['clientEmail' => $email, 'reports' => $reports, 'capabilities'=>$capabilities,'customerId'=>$customerId,'expiresAt' => $expiresAt, 'emailed' => (bool)($mail['sent'] ?? false)]);
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
            $capabilities = client_view_clean_capabilities($input['capabilities'] ?? client_view_capabilities($link));
            $reports = in_array('reports',$capabilities,true) ? client_view_clean_reports($input['reports'] ?? json_decode((string)$link['reports_json'],true)) : [];
            $customerId = client_view_clean_customer_id($company,$input['customerId'] ?? $link['customer_id'],$capabilities);
            db()->prepare('UPDATE client_view_links SET reports_json = ?,capabilities_json=?,customer_id=? WHERE id = ?')->execute([json_encode($reports),json_encode($capabilities),$customerId,$id]);
            audit_event($user, $companyId, 'client_view.link_updated', 'client_view_link', $id, ['reports' => $reports,'capabilities'=>$capabilities,'customerId'=>$customerId]);
            json_response(['updated' => true, 'reports' => $reports,'capabilities'=>$capabilities,'customerId'=>$customerId]);
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

function client_portal_manage_link(array $company, mixed $id): array
{
    $id = clean_text($id ?? '', 'Client link', 64);
    $stmt = db()->prepare('SELECT * FROM client_view_links WHERE id=? AND company_id=?');
    $stmt->execute([$id,(string)$company['id']]);
    $link = $stmt->fetch();
    if (!$link) fail('Client dashboard link not found.',404,'client_view_link_not_found');
    return $link;
}

function client_portal_document_public(array $row): array
{
    return ['id'=>(string)$row['id'],'direction'=>(string)$row['direction'],'name'=>(string)$row['original_name'],
        'mime'=>(string)$row['mime_type'],'size'=>(int)$row['size_bytes'],'createdAt'=>(string)$row['created_at']];
}

function client_portal_request_public(array $row): array
{
    return ['id'=>(string)$row['id'],'subject'=>(string)$row['subject'],'details'=>(string)$row['details'],
        'status'=>(string)$row['status'],'response'=>$row['response'],'createdByClient'=>(bool)$row['created_by_client'],
        'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at'],'respondedAt'=>$row['responded_at']];
}

function client_portal_message_public(array $row): array
{
    return ['id'=>(string)$row['id'],'sender'=>(string)$row['sender_type'],'body'=>(string)$row['body'],'createdAt'=>(string)$row['created_at']];
}

function client_portal_approval_public(array $row): array
{
    return ['id'=>(string)$row['id'],'title'=>(string)$row['title'],'scope'=>(string)$row['scope_text'],
        'consequence'=>(string)$row['consequence'],'status'=>(string)$row['status'],'responseNote'=>$row['response_note'],
        'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at'],'respondedAt'=>$row['responded_at']];
}

function client_portal_snapshot(array $link): array
{
    $params=[(string)$link['company_id'],(string)$link['id']];
    $collections=[];
    foreach ([
        'documents'=>['SELECT * FROM client_portal_documents WHERE company_id=? AND link_id=? AND removed_at IS NULL ORDER BY created_at DESC LIMIT 200','client_portal_document_public'],
        'requests'=>['SELECT * FROM client_portal_requests WHERE company_id=? AND link_id=? ORDER BY created_at DESC LIMIT 200','client_portal_request_public'],
        'messages'=>['SELECT * FROM client_portal_messages WHERE company_id=? AND link_id=? ORDER BY created_at DESC LIMIT 300','client_portal_message_public'],
        'approvals'=>['SELECT * FROM client_portal_approvals WHERE company_id=? AND link_id=? ORDER BY created_at DESC LIMIT 200','client_portal_approval_public'],
    ] as $key=>[$sql,$mapper]) { $stmt=db()->prepare($sql);$stmt->execute($params);$collections[$key]=array_map($mapper,$stmt->fetchAll()); }
    return $collections;
}

function handle_client_portal_manage(string $action): never
{
    [$user,$company]=client_view_manage_context();
    $input=request_method()==='GET'?$_GET:($action==='manage/documents/upload'?$_POST:request_json());
    if(request_method()!=='GET')require_csrf();
    $link=client_portal_manage_link($company,$input['linkId']??'');
    $companyId=(string)$company['id'];$linkId=(string)$link['id'];
    if($action==='manage/portal'){require_method('GET');json_response(['link'=>client_view_public_link($link)]+client_portal_snapshot($link));}
    if($action==='manage/messages'){require_method('POST');client_view_require_capability($link,'messages');$body=clean_text($input['body']??'','Message',3000);$id=new_id('clientmsg');db()->prepare("INSERT INTO client_portal_messages (id,company_id,link_id,sender_type,sender_user_id,body) VALUES (?,?,?,'team',?,?)")->execute([$id,$companyId,$linkId,(string)$user['id'],$body]);audit_event($user,$companyId,'client_portal.message_sent','client_view_link',$linkId,['messageId'=>$id,'recipient'=>$link['client_email']]);json_response(['message'=>['id'=>$id]],201);}
    if($action==='manage/requests/respond'){require_method('POST');client_view_require_capability($link,'requests');$id=clean_text($input['requestId']??'','Request',64);$response=clean_text($input['response']??'','Response',3000);$status=(string)($input['status']??'responded');if(!in_array($status,['responded','closed'],true))fail('Choose a valid request status.',422,'client_portal_request_status_invalid');$stmt=db()->prepare('UPDATE client_portal_requests SET response=?,status=?,responded_by=?,responded_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND link_id=?');$stmt->execute([$response,$status,(string)$user['id'],$id,$companyId,$linkId]);if($stmt->rowCount()!==1)fail('Request not found.',404,'client_portal_request_not_found');audit_event($user,$companyId,'client_portal.request_responded','client_portal_request',$id,['status'=>$status,'linkId'=>$linkId]);json_response(['updated'=>true]);}
    if($action==='manage/approvals'){require_method('POST');client_view_require_capability($link,'approvals');$title=clean_text($input['title']??'','Approval title',180);$scope=clean_text($input['scope']??'','Approval scope',3000);$consequence=(string)($input['consequence']??'acknowledgement');if(!in_array($consequence,['acknowledgement','request_completion'],true))fail('This approval consequence is not permitted.',422,'client_portal_approval_consequence_invalid');$id=new_id('approval');db()->beginTransaction();try{db()->prepare('INSERT INTO client_portal_approvals (id,company_id,link_id,title,scope_text,consequence,created_by) VALUES (?,?,?,?,?,?,?)')->execute([$id,$companyId,$linkId,$title,$scope,$consequence,(string)$user['id']]);db()->prepare("INSERT INTO client_portal_approval_history (id,approval_id,company_id,link_id,action,actor_type,actor_user_id,note) VALUES (?,?,?,?, 'created','team',?,NULL)")->execute([new_id('approvalhist'),$id,$companyId,$linkId,(string)$user['id']]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}audit_event($user,$companyId,'client_portal.approval_requested','client_portal_approval',$id,['linkId'=>$linkId,'consequence'=>$consequence,'accountingWrites'=>0,'paymentAuthorization'=>false]);json_response(['approval'=>['id'=>$id]],201);}
    if($action==='manage/approvals/cancel'){require_method('POST');client_view_require_capability($link,'approvals');$id=clean_text($input['approvalId']??'','Approval',64);db()->beginTransaction();try{$stmt=db()->prepare("UPDATE client_portal_approvals SET status='cancelled',responded_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND link_id=? AND status='pending'");$stmt->execute([$id,$companyId,$linkId]);if($stmt->rowCount()!==1)fail('Pending approval not found.',404,'client_portal_approval_not_found');db()->prepare("INSERT INTO client_portal_approval_history (id,approval_id,company_id,link_id,action,actor_type,actor_user_id,note) VALUES (?,?,?,?, 'cancelled','team',?,NULL)")->execute([new_id('approvalhist'),$id,$companyId,$linkId,(string)$user['id']]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}audit_event($user,$companyId,'client_portal.approval_cancelled','client_portal_approval',$id,['linkId'=>$linkId]);json_response(['cancelled'=>true]);}
    if($action==='manage/documents/upload'){require_method('POST');client_view_require_capability($link,'documents');$saved=save_private_upload($companyId,'client-portal/'.$linkId,['pdf','png','jpg','jpeg','webp','csv','xlsx','txt'],['application/pdf','image/png','image/jpeg','image/webp','text/plain','text/csv','application/csv','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);$id=new_id('clientdoc');$sha=hash_file('sha256',$saved['absolutePath']);if(!is_string($sha)){delete_private_file($saved['relativePath']);fail('The uploaded document could not be verified.',500,'client_portal_document_integrity_unavailable');}try{db()->prepare("INSERT INTO client_portal_documents (id,company_id,link_id,direction,original_name,storage_path,mime_type,size_bytes,sha256,uploaded_by_user,uploaded_by_client) VALUES (?,?,?,'team_to_client',?,?,?,?,?,?,0)")->execute([$id,$companyId,$linkId,$saved['originalName'],$saved['relativePath'],$saved['mime'],$saved['size'],$sha,(string)$user['id']]);}catch(Throwable $e){delete_private_file($saved['relativePath']);throw $e;}audit_event($user,$companyId,'client_portal.document_shared','client_portal_document',$id,['linkId'=>$linkId,'sizeBytes'=>$saved['size'],'sha256'=>$sha]);json_response(['document'=>['id'=>$id]],201);}
    if($action==='manage/documents/download'){require_method('GET');client_view_require_capability($link,'documents');$id=clean_text($input['documentId']??'','Document',64);$stmt=db()->prepare('SELECT * FROM client_portal_documents WHERE id=? AND company_id=? AND link_id=? AND removed_at IS NULL');$stmt->execute([$id,$companyId,$linkId]);$row=$stmt->fetch();if(!$row||!valid_private_storage_reference($companyId,(string)$row['storage_path'],'client-portal/'.$linkId))fail('Document not found.',404,'client_portal_document_not_found');audit_event($user,$companyId,'client_portal.document_downloaded','client_portal_document',$id,['linkId'=>$linkId]);stream_private_file((string)$row['storage_path'],(string)$row['original_name']);}
    fail('Client dashboard management route not found.',404,'client_portal_manage_route_not_found');
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
    // Public client sessions bypass require_company()/session_record(). Apply
    // the same restore-deletion boundary before reading restored company data.
    foreach (['company' => (string)$link['company_id'], 'user' => (string)$link['created_by']] as $type => $id) {
        if (tegh_deletion_registered($type, $id)) {
            tegh_deletion_reappeared($type, $id);
            fail('This viewing link is no longer available. Ask your accountant for a new one.', 410, 'client_view_link_unavailable');
        }
    }
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

/** Atomically consume a still-valid code after its hash was checked. */
function client_view_consume_code(string $id): bool
{
    $stmt = db()->prepare('UPDATE client_view_codes SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL AND attempts < ? AND expires_at > UTC_TIMESTAMP()');
    $stmt->execute([$id, TEGH_CLIENT_VIEW_CODE_ATTEMPTS]);
    return $stmt->rowCount() === 1;
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
        if (!client_view_consume_code((string)$row['id'])) fail('This code has expired or was already used. Ask for a new code.', 410, 'client_view_code_expired');
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
            'capabilities' => client_view_capabilities($link),
            'reports' => array_map(static fn(string $key): array => ['key' => $key, 'title' => $catalog[$key], 'mode' => (string)(tegh_report_definitions_5980()[$key]['periodMode'] ?? 'range')], $allowed),
            'linkExpiresAt' => (string)$link['expires_at'],
        ]);
    }
    if ($action === 'invoices') {
        require_method('GET'); client_view_require_capability($link,'invoices');
        if (empty($link['customer_id'])) fail('No customer invoice scope is assigned to this dashboard.',403,'client_view_customer_scope_missing');
        $stmt=db()->prepare("SELECT id,number,issue_date,due_date,status,total_cents,balance_cents,currency,foreign_total_cents,foreign_balance_cents,purchase_order FROM invoices WHERE company_id=? AND customer_id=? AND status IN ('sent','paid') ORDER BY issue_date DESC,number DESC LIMIT 250");
        $stmt->execute([(string)$company['id'],(string)$link['customer_id']]);$rows=[];
        foreach($stmt->fetchAll() as $row)$rows[]=['id'=>(string)$row['id'],'number'=>(string)$row['number'],'issueDate'=>(string)$row['issue_date'],'dueDate'=>(string)$row['due_date'],'status'=>(string)$row['status'],'totalCents'=>(int)$row['total_cents'],'balanceCents'=>(int)$row['balance_cents'],'currency'=>(string)$row['currency'],'foreignTotalCents'=>(int)$row['foreign_total_cents'],'foreignBalanceCents'=>(int)$row['foreign_balance_cents'],'purchaseOrder'=>$row['purchase_order']];
        json_response(['invoices'=>$rows]);
    }
    if ($action === 'invoice') {
        require_method('GET'); client_view_require_capability($link,'invoices');
        $id=clean_text($_GET['invoiceId']??'','Invoice',64);
        $stmt=db()->prepare("SELECT i.id,i.number,i.issue_date,i.due_date,i.status,i.subtotal_cents,i.gst_hst_cents,i.pst_cents,i.tax_cents,i.total_cents,i.balance_cents,i.message,i.currency,i.foreign_subtotal_cents,i.foreign_tax_cents,i.foreign_total_cents,i.foreign_balance_cents,i.purchase_order,c.name customer_name FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.id=? AND i.company_id=? AND i.customer_id=? AND i.status IN ('sent','paid')");
        $stmt->execute([$id,(string)$company['id'],(string)$link['customer_id']]);$invoice=$stmt->fetch();if(!$invoice)fail('Invoice not found.',404,'client_view_invoice_not_found');
        $lines=db()->prepare('SELECT description,quantity_milli,unit_price_cents,amount_cents,tax_cents,foreign_unit_price_cents,foreign_amount_cents,foreign_tax_cents FROM invoice_lines WHERE invoice_id=? ORDER BY sort_order,id');$lines->execute([$id]);
        audit_event($actor,(string)$company['id'],'client_portal.invoice_viewed','invoice',$id,['viaClientView'=>true,'linkId'=>(string)$link['id']]);
        json_response(['invoice'=>$invoice,'lines'=>$lines->fetchAll()]);
    }
    if ($action === 'documents') {
        require_method('GET');client_view_require_capability($link,'documents');
        $stmt=db()->prepare('SELECT * FROM client_portal_documents WHERE company_id=? AND link_id=? AND removed_at IS NULL ORDER BY created_at DESC LIMIT 200');$stmt->execute([(string)$company['id'],(string)$link['id']]);json_response(['documents'=>array_map('client_portal_document_public',$stmt->fetchAll())]);
    }
    if ($action === 'documents/upload') {
        require_method('POST');assert_same_origin();client_view_require_capability($link,'documents');
        $companyId=(string)$company['id'];$linkId=(string)$link['id'];$saved=save_private_upload($companyId,'client-portal/'.$linkId,['pdf','png','jpg','jpeg','webp','csv','xlsx','txt'],['application/pdf','image/png','image/jpeg','image/webp','text/plain','text/csv','application/csv','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);$sha=hash_file('sha256',$saved['absolutePath']);if(!is_string($sha)){delete_private_file($saved['relativePath']);fail('The uploaded document could not be verified.',500,'client_portal_document_integrity_unavailable');}$id=new_id('clientdoc');
        try{db()->prepare("INSERT INTO client_portal_documents (id,company_id,link_id,direction,original_name,storage_path,mime_type,size_bytes,sha256,uploaded_by_client) VALUES (?,?,?,'client_to_team',?,?,?,?,?,1)")->execute([$id,$companyId,$linkId,$saved['originalName'],$saved['relativePath'],$saved['mime'],$saved['size'],$sha]);}catch(Throwable $e){delete_private_file($saved['relativePath']);throw $e;}
        audit_event($actor,$companyId,'client_portal.document_uploaded','client_portal_document',$id,['viaClientView'=>true,'linkId'=>$linkId,'sizeBytes'=>$saved['size'],'sha256'=>$sha]);json_response(['document'=>['id'=>$id]],201);
    }
    if ($action === 'documents/download') {
        require_method('GET');client_view_require_capability($link,'documents');$id=clean_text($_GET['documentId']??'','Document',64);$companyId=(string)$company['id'];$linkId=(string)$link['id'];$stmt=db()->prepare('SELECT * FROM client_portal_documents WHERE id=? AND company_id=? AND link_id=? AND removed_at IS NULL');$stmt->execute([$id,$companyId,$linkId]);$row=$stmt->fetch();if(!$row||!valid_private_storage_reference($companyId,(string)$row['storage_path'],'client-portal/'.$linkId))fail('Document not found.',404,'client_portal_document_not_found');audit_event($actor,$companyId,'client_portal.document_downloaded','client_portal_document',$id,['viaClientView'=>true,'linkId'=>$linkId]);stream_private_file((string)$row['storage_path'],(string)$row['original_name']);
    }
    if ($action === 'requests') {
        client_view_require_capability($link,'requests');$companyId=(string)$company['id'];$linkId=(string)$link['id'];
        if(request_method()==='GET'){$stmt=db()->prepare('SELECT * FROM client_portal_requests WHERE company_id=? AND link_id=? ORDER BY created_at DESC LIMIT 200');$stmt->execute([$companyId,$linkId]);json_response(['requests'=>array_map('client_portal_request_public',$stmt->fetchAll())]);}
        require_method('POST');assert_same_origin();$input=request_json();$subject=clean_text($input['subject']??'','Request subject',180);$details=clean_text($input['details']??'','Request details',3000);$id=new_id('clientrequest');db()->prepare('INSERT INTO client_portal_requests (id,company_id,link_id,subject,details,created_by_client) VALUES (?,?,?,?,?,1)')->execute([$id,$companyId,$linkId,$subject,$details]);audit_event($actor,$companyId,'client_portal.request_created','client_portal_request',$id,['viaClientView'=>true,'linkId'=>$linkId]);json_response(['request'=>['id'=>$id]],201);
    }
    if ($action === 'messages') {
        client_view_require_capability($link,'messages');$companyId=(string)$company['id'];$linkId=(string)$link['id'];
        if(request_method()==='GET'){$stmt=db()->prepare('SELECT * FROM client_portal_messages WHERE company_id=? AND link_id=? ORDER BY created_at DESC LIMIT 300');$stmt->execute([$companyId,$linkId]);json_response(['messages'=>array_map('client_portal_message_public',$stmt->fetchAll())]);}
        require_method('POST');assert_same_origin();$input=request_json();$body=clean_text($input['body']??'','Message',3000);$id=new_id('clientmsg');db()->prepare("INSERT INTO client_portal_messages (id,company_id,link_id,sender_type,body) VALUES (?,?,?,'client',?)")->execute([$id,$companyId,$linkId,$body]);audit_event($actor,$companyId,'client_portal.message_sent','client_view_link',$linkId,['viaClientView'=>true,'messageId'=>$id]);json_response(['message'=>['id'=>$id]],201);
    }
    if ($action === 'approvals') {
        require_method('GET');client_view_require_capability($link,'approvals');$stmt=db()->prepare('SELECT * FROM client_portal_approvals WHERE company_id=? AND link_id=? ORDER BY created_at DESC LIMIT 200');$stmt->execute([(string)$company['id'],(string)$link['id']]);json_response(['approvals'=>array_map('client_portal_approval_public',$stmt->fetchAll()),'notice'=>'Approvals acknowledge the stated scope only. They cannot post accounting entries or authorize payments.']);
    }
    if ($action === 'approvals/respond') {
        require_method('POST');assert_same_origin();client_view_require_capability($link,'approvals');$input=request_json();$id=clean_text($input['approvalId']??'','Approval',64);$decision=(string)($input['decision']??'');if(!in_array($decision,['approved','declined'],true))fail('Choose approve or decline.',422,'client_portal_approval_decision_invalid');$note=optional_text($input['note']??null,2000);$companyId=(string)$company['id'];$linkId=(string)$link['id'];db()->beginTransaction();try{$stmt=db()->prepare('UPDATE client_portal_approvals SET status=?,response_note=?,responded_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND link_id=? AND status=\'pending\'');$stmt->execute([$decision,$note,$id,$companyId,$linkId]);if($stmt->rowCount()!==1)fail('Pending approval not found.',404,'client_portal_approval_not_found');db()->prepare('INSERT INTO client_portal_approval_history (id,approval_id,company_id,link_id,action,actor_type,actor_user_id,note) VALUES (?,?,?,?,?,\'client\',NULL,?)')->execute([new_id('approvalhist'),$id,$companyId,$linkId,$decision,$note]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}audit_event($actor,$companyId,'client_portal.approval_'.$decision,'client_portal_approval',$id,['viaClientView'=>true,'linkId'=>$linkId,'accountingWrites'=>0,'paymentAuthorization'=>false]);json_response(['updated'=>true,'status'=>$decision]);
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
    if (str_starts_with($action, 'manage/')) handle_client_portal_manage($action);
    if (in_array($action, ['catalog', 'links', 'links/revoke', 'links/reissue', 'links/update'], true)) handle_client_view_manage($action);
    handle_client_view_public($action);
}
