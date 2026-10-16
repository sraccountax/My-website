<?php
declare(strict_types=1);

/**
 * R159: archiving a company and asking Tegh support to delete it permanently.
 *
 * Archive. The company owner archives a company: it disappears from the company switcher, the company lists, reports
 * across companies and the client viewing links of every member (companies.active = 0, which every company lookup
 * already requires), and nothing can be opened or changed in it. Its records are kept unchanged. The owner can restore
 * it from Account & Access › Archived companies at any time.
 *
 * Permanent deletion. A company owner no longer deletes a company directly. The owner sends a deletion request (company
 * name typed, password, final-backup confirmation, reason); the company is archived at once and Tegh support (the
 * platform owner) is notified. The platform owner approves the request (the company is then deleted with the same
 * checks and deletion log as before) or rejects it (the company stays archived and can be restored). The owner can
 * cancel a pending request. Requests are kept after the company is deleted, as the record of who asked and who decided.
 *
 * Tables, created on first use: companies.archived_at and companies.archived_by (nullable columns), and
 * company_deletion_requests. The request table names the company in target_company_id, not company_id, so that the
 * deletion of the company never removes its own request history.
 */

function tegh_lifecycle_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        if (!schema_column_exists('companies', 'archived_at')) db()->exec('ALTER TABLE companies ADD COLUMN archived_at DATETIME NULL DEFAULT NULL');
        if (!schema_column_exists('companies', 'archived_by')) db()->exec('ALTER TABLE companies ADD COLUMN archived_by VARCHAR(64) NULL DEFAULT NULL');
        if (!schema_table_exists('company_deletion_requests')) {
            db()->exec("CREATE TABLE IF NOT EXISTS company_deletion_requests (
                id VARCHAR(64) NOT NULL PRIMARY KEY,
                target_company_id VARCHAR(64) NOT NULL,
                company_name VARCHAR(160) NOT NULL,
                requested_by VARCHAR(64) NOT NULL,
                requested_by_email VARCHAR(254) NOT NULL,
                reason VARCHAR(1000) NOT NULL,
                backup_confirmed TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
                was_active TINYINT(1) NOT NULL DEFAULT 1,
                decided_by VARCHAR(64) NULL DEFAULT NULL,
                decision_note VARCHAR(1000) NULL DEFAULT NULL,
                decided_at DATETIME NULL DEFAULT NULL,
                record_summary_json MEDIUMTEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY company_deletion_requests_target (target_company_id, status),
                KEY company_deletion_requests_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $ready = schema_column_exists('companies', 'archived_at') && schema_table_exists('company_deletion_requests');
    } catch (Throwable $e) {
        error_log('Tegh R159 company lifecycle setup: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

function tegh_lifecycle_require_ready(): void
{
    if (!tegh_lifecycle_ready()) fail('Archiving is not available until the database allows Tegh to add its archive columns. Ask your host administrator.', 503, 'company_lifecycle_unavailable');
}

/** The company, active or archived, when the user is its owner; otherwise 404 (never revealing other companies). */
function tegh_lifecycle_owned_company(array $user, string $companyId, bool $forUpdate = false): array
{
    $statusClause = schema_column_exists('company_members', 'status') ? " AND cm.status='active'" : '';
    $q = db()->prepare("SELECT c.*, cm.role FROM companies c JOIN company_members cm ON cm.company_id=c.id
        WHERE c.id=? AND cm.user_id=? AND c.test_mode=0" . $statusClause . ' LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $q->execute([$companyId, (string)$user['id']]);
    $company = $q->fetch();
    if (!$company) fail('Company not found.', 404, 'company_not_found');
    if ((string)$company['role'] !== 'owner') fail('Only the company owner can archive, restore or ask to delete a company.', 403, 'permission_forbidden');
    return $company;
}

function tegh_lifecycle_request_row(array $r, bool $platform = false): array
{
    $out = [
        'id' => (string)$r['id'], 'companyId' => (string)$r['target_company_id'], 'companyName' => (string)$r['company_name'],
        'status' => (string)$r['status'], 'reason' => (string)$r['reason'], 'createdAt' => (string)$r['created_at'],
        'decidedAt' => $r['decided_at'] !== null ? (string)$r['decided_at'] : null,
        'decisionNote' => $r['decision_note'] !== null ? (string)$r['decision_note'] : null,
    ];
    if ($platform) {
        $out['requestedByEmail'] = (string)$r['requested_by_email'];
        $out['backupConfirmed'] = (bool)$r['backup_confirmed'];
        $out['companyExists'] = isset($r['company_exists']) ? (bool)$r['company_exists'] : null;
        $out['recordCounts'] = isset($r['record_counts']) ? $r['record_counts'] : null;
    }
    return $out;
}

/** Notifies Tegh support of a new deletion request: the operator's support address and every platform owner. */
function tegh_lifecycle_notify_support(array $request): void
{
    $recipients = [];
    $support = (string)(config('operator')['support_email'] ?? '');
    if ($support !== '' && filter_var($support, FILTER_VALIDATE_EMAIL)) $recipients[strtolower($support)] = $support;
    foreach (db()->query("SELECT email FROM users WHERE platform_role='platform_owner' AND active=1")->fetchAll() as $row) $recipients[strtolower((string)$row['email'])] = (string)$row['email'];
    $subject = 'Tegh: request to delete a company permanently';
    $text = "A company owner asked Tegh support to delete a company permanently.\n\nCompany: {$request['company_name']}\nRequested by: {$request['requested_by_email']}\nReason: {$request['reason']}\n\nThe company is archived until you decide. Open Tegh › Platform Owner Home › Company deletion requests to approve or reject it.";
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#18333b"><h2 style="margin:0 0 12px">Request to delete a company permanently</h2><p>A company owner asked Tegh support to delete a company permanently.</p><table cellpadding="4"><tr><td><b>Company</b></td><td>' . htmlspecialchars((string)$request['company_name']) . '</td></tr><tr><td><b>Requested by</b></td><td>' . htmlspecialchars((string)$request['requested_by_email']) . '</td></tr><tr><td><b>Reason</b></td><td>' . htmlspecialchars((string)$request['reason']) . '</td></tr></table><p>The company is archived until you decide. Open Tegh › Platform Owner Home › <b>Company deletion requests</b> to approve or reject it.</p></body></html>';
    foreach ($recipients as $email) {
        try { sr_mail_send(null, (string)$request['requested_by'], $email, 'company_deletion_request', $subject, $text, $html); }
        catch (Throwable $e) { error_log('Tegh R159 deletion request email: ' . $e->getMessage()); }
    }
}

function tegh_lifecycle_notify_requester(array $request, string $decision, string $note): void
{
    $subject = $decision === 'approved' ? 'Tegh: your company was deleted' : 'Tegh: your company deletion request was not approved';
    $body = $decision === 'approved'
        ? "Tegh support deleted {$request['company_name']} permanently, as you asked. Its records and files are removed; a deletion log is kept."
        : "Tegh support did not approve the request to delete {$request['company_name']}. The company stays archived; you can restore it in Account & Access › Archived companies.";
    if ($note !== '') $body .= "\n\nNote from Tegh support: $note";
    try { sr_mail_send(null, null, (string)$request['requested_by_email'], 'company_deletion_decision', $subject, $body, '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#18333b"><p>' . nl2br(htmlspecialchars($body)) . '</p></body></html>'); }
    catch (Throwable $e) { error_log('Tegh R159 deletion decision email: ' . $e->getMessage()); }
}

function tegh_lifecycle_platform_audit(array $user, string $action, string $companyId, array $meta): void
{
    if (function_exists('platform_audit_event')) platform_audit_event($user, $action, 'company', $companyId, $meta);
}

function handle_company_lifecycle(string $action): never
{
    $user = require_user();
    tegh_lifecycle_require_ready();

    // GET companies/archived: the owner's archived companies, with the state of any deletion request.
    if ($action === 'archived') {
        $statusClause = schema_column_exists('company_members', 'status') ? " AND cm.status='active'" : '';
        $q = db()->prepare("SELECT c.id, c.name, c.short_name, c.archived_at, u.email AS archived_by_email,
                (SELECT r.id FROM company_deletion_requests r WHERE r.target_company_id=c.id ORDER BY r.created_at DESC, r.id DESC LIMIT 1) AS request_id
            FROM companies c JOIN company_members cm ON cm.company_id=c.id LEFT JOIN users u ON u.id=c.archived_by
            WHERE cm.user_id=? AND cm.role='owner' AND c.active=0 AND c.test_mode=0" . $statusClause . ' ORDER BY c.archived_at DESC, c.name');
        $q->execute([(string)$user['id']]);
        $out = [];
        foreach ($q->fetchAll() as $row) {
            $request = null;
            if ($row['request_id']) {
                $r = db()->prepare('SELECT * FROM company_deletion_requests WHERE id=?');$r->execute([$row['request_id']]);$req = $r->fetch();
                if ($req) $request = tegh_lifecycle_request_row($req);
            }
            $out[] = ['id' => (string)$row['id'], 'name' => (string)$row['name'], 'shortName' => $row['short_name'] !== null ? (string)$row['short_name'] : null,
                'archivedAt' => $row['archived_at'] !== null ? (string)$row['archived_at'] : null, 'archivedByEmail' => $row['archived_by_email'] !== null ? (string)$row['archived_by_email'] : null,
                'deletionRequest' => $request];
        }
        json_response(['companies' => $out]);
    }

    require_method('POST');
    require_csrf();
    $input = request_json();

    // POST companies/archive {companyId}
    if ($action === 'archive') {
        $companyId = clean_text($input['companyId'] ?? '', 'Company', 64);
        $result = db_transaction_retry(static function () use ($user, $companyId): array {
            $company = tegh_lifecycle_owned_company($user, $companyId, true);
            if (!(bool)$company['active']) fail('This company is already archived.', 409, 'company_already_archived');
            db()->prepare('UPDATE companies SET active=0, archived_at=UTC_TIMESTAMP(), archived_by=? WHERE id=?')->execute([(string)$user['id'], $companyId]);
            audit_event($user, $companyId, 'company.archived', 'company', $companyId, ['name' => (string)$company['name']]);
            return ['name' => (string)$company['name']];
        });
        json_response(['archived' => true, 'companyId' => $companyId, 'companyName' => $result['name']] + tegh_deletion_transition_payload($user, [$companyId]));
    }

    // POST companies/restore {companyId}: back in the lists; a pending deletion request is cancelled.
    if ($action === 'restore') {
        $companyId = clean_text($input['companyId'] ?? '', 'Company', 64);
        $result = db_transaction_retry(static function () use ($user, $companyId): array {
            $company = tegh_lifecycle_owned_company($user, $companyId, true);
            if ((bool)$company['active']) fail('This company is not archived.', 409, 'company_not_archived');
            $cancel = db()->prepare("UPDATE company_deletion_requests SET status='cancelled', decided_by=?, decided_at=UTC_TIMESTAMP(), decision_note='Cancelled: the owner restored the company.' WHERE target_company_id=? AND status='pending'");
            $cancel->execute([(string)$user['id'], $companyId]);
            db()->prepare('UPDATE companies SET active=1, archived_at=NULL, archived_by=NULL WHERE id=?')->execute([$companyId]);
            audit_event($user, $companyId, 'company.restored', 'company', $companyId, ['name' => (string)$company['name'], 'deletionRequestsCancelled' => $cancel->rowCount()]);
            return ['name' => (string)$company['name'], 'cancelled' => $cancel->rowCount()];
        });
        json_response(['restored' => true, 'companyId' => $companyId, 'companyName' => $result['name'], 'deletionRequestsCancelled' => $result['cancelled']]);
    }

    // POST companies/deletion-request {companyId, companyName, password, backupConfirmed, reason}
    if ($action === 'deletion-request') {
        $companyId = clean_text($input['companyId'] ?? '', 'Company', 64);
        $company = tegh_lifecycle_owned_company($user, $companyId);
        $typed = clean_text($input['companyName'] ?? '', 'Company name', 160);
        if (!hash_equals((string)$company['name'], $typed)) fail('Type the company name exactly as shown.', 409, 'company_name_mismatch');
        operations_owner_password($user, (string)($input['password'] ?? ''));
        if (empty($input['backupConfirmed'])) fail('Confirm that a final company backup has been downloaded.', 409, 'company_backup_required');
        $reason = clean_text($input['reason'] ?? '', 'Reason', 1000);
        if (mb_strlen($reason) < 5) fail('Tell Tegh support briefly why the company should be deleted.', 422, 'deletion_reason_required');
        $request = db_transaction_retry(static function () use ($user, $companyId, $reason): array {
            $company = tegh_lifecycle_owned_company($user, $companyId, true);
            $pending = db()->prepare("SELECT id FROM company_deletion_requests WHERE target_company_id=? AND status='pending' LIMIT 1");
            $pending->execute([$companyId]);
            if ($pending->fetchColumn()) fail('A deletion request for this company is already waiting for Tegh support.', 409, 'deletion_request_pending');
            $id = new_id('companydelreq');
            $row = ['id' => $id, 'target_company_id' => $companyId, 'company_name' => (string)$company['name'], 'requested_by' => (string)$user['id'],
                'requested_by_email' => (string)$user['email'], 'reason' => $reason];
            db()->prepare('INSERT INTO company_deletion_requests (id, target_company_id, company_name, requested_by, requested_by_email, reason, backup_confirmed, was_active) VALUES (?,?,?,?,?,?,1,?)')
                ->execute([$id, $companyId, $row['company_name'], $row['requested_by'], $row['requested_by_email'], $reason, (int)(bool)$company['active']]);
            // The company is archived while the request waits, so nobody keeps working in books that are about to go.
            if ((bool)$company['active']) db()->prepare('UPDATE companies SET active=0, archived_at=UTC_TIMESTAMP(), archived_by=? WHERE id=?')->execute([(string)$user['id'], $companyId]);
            audit_event($user, $companyId, 'company.deletion_requested', 'company', $companyId, ['requestId' => $id, 'reason' => $reason]);
            return $row;
        });
        tegh_lifecycle_notify_support($request);
        json_response(['requested' => true, 'requestId' => $request['id'], 'companyId' => $companyId, 'companyName' => $request['company_name'], 'archived' => true] + tegh_deletion_transition_payload($user, [$companyId]), 201);
    }

    // POST companies/deletion-request/cancel {requestId}: the company stays archived (restore it to use it again).
    if ($action === 'deletion-request/cancel') {
        $requestId = clean_text($input['requestId'] ?? '', 'Request', 64);
        $r = db()->prepare("SELECT * FROM company_deletion_requests WHERE id=? AND status='pending'");$r->execute([$requestId]);$req = $r->fetch();
        if (!$req) fail('No pending deletion request was found.', 404, 'deletion_request_not_found');
        tegh_lifecycle_owned_company($user, (string)$req['target_company_id']);
        db()->prepare("UPDATE company_deletion_requests SET status='cancelled', decided_by=?, decided_at=UTC_TIMESTAMP(), decision_note='Cancelled by the owner.' WHERE id=? AND status='pending'")->execute([(string)$user['id'], $requestId]);
        audit_event($user, (string)$req['target_company_id'], 'company.deletion_request_cancelled', 'company', (string)$req['target_company_id'], ['requestId' => $requestId]);
        json_response(['cancelled' => true, 'requestId' => $requestId]);
    }

    fail('Route not found.', 404, 'route_not_found');
}

/** Platform owner: GET admin/deletion-requests, POST admin/deletion-requests {action: approve|reject, requestId, ...}. */
function admin_company_deletion_requests(array $user): never
{
    admin_require_platform_owner($user);
    tegh_lifecycle_require_ready();
    if (request_method() === 'GET') {
        $rows = db()->query("SELECT r.*, (c.id IS NOT NULL) AS company_exists FROM company_deletion_requests r LEFT JOIN companies c ON c.id=r.target_company_id
            ORDER BY (r.status='pending') DESC, r.created_at DESC LIMIT 200")->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            // The platform owner sees how much would be removed, never the records themselves.
            if ($row['status'] === 'pending' && $row['company_exists']) {
                $counts = operations_company_record_summary((string)$row['target_company_id']);
                $row['record_counts'] = ['tables' => count(array_filter($counts)), 'rows' => array_sum($counts),
                    'invoices' => (int)($counts['invoices'] ?? 0), 'bills' => (int)($counts['bills'] ?? 0), 'journalEntries' => (int)($counts['journal_entries'] ?? 0)];
            } elseif ($row['record_summary_json']) {
                $s = json_decode((string)$row['record_summary_json'], true) ?: [];
                $row['record_counts'] = ['tables' => count(array_filter($s)), 'rows' => array_sum(array_map('intval', $s)),
                    'invoices' => (int)($s['invoices'] ?? 0), 'bills' => (int)($s['bills'] ?? 0), 'journalEntries' => (int)($s['journal_entries'] ?? 0)];
            }
            $out[] = tegh_lifecycle_request_row($row, true);
        }
        json_response(['requests' => $out, 'pending' => count(array_filter($out, static fn($r) => $r['status'] === 'pending'))]);
    }
    require_method('POST');
    require_csrf();
    $input = request_json();
    $requestId = clean_text($input['requestId'] ?? '', 'Request', 64);
    $decision = (string)($input['action'] ?? '');
    if (!in_array($decision, ['approve', 'reject'], true)) fail('Choose approve or reject.', 422, 'deletion_decision_invalid');
    $note = trim((string)($input['note'] ?? ''));
    if ($note !== '') $note = clean_text($note, 'Note', 1000);
    $r = db()->prepare('SELECT * FROM company_deletion_requests WHERE id=?');$r->execute([$requestId]);$req = $r->fetch();
    if (!$req) fail('Deletion request not found.', 404, 'deletion_request_not_found');
    if ((string)$req['status'] !== 'pending') fail('This request was already ' . $req['status'] . '.', 409, 'deletion_request_decided');
    $companyId = (string)$req['target_company_id'];

    if ($decision === 'reject') {
        if (mb_strlen($note) < 5) fail('Tell the owner briefly why the request is not approved.', 422, 'deletion_note_required');
        db()->prepare("UPDATE company_deletion_requests SET status='rejected', decided_by=?, decided_at=UTC_TIMESTAMP(), decision_note=? WHERE id=? AND status='pending'")->execute([(string)$user['id'], $note, $requestId]);
        tegh_lifecycle_platform_audit($user, 'platform.company_deletion_rejected', $companyId, ['requestId' => $requestId, 'companyName' => (string)$req['company_name'], 'note' => $note]);
        tegh_lifecycle_notify_requester($req, 'rejected', $note);
        json_response(['rejected' => true, 'requestId' => $requestId]);
    }

    // Approve: the platform owner types the company name and their own password; the deletion is the R158 deletion.
    $typed = clean_text($input['companyName'] ?? '', 'Company name', 160);
    if (!hash_equals((string)$req['company_name'], $typed)) fail('Type the company name exactly as shown.', 409, 'company_name_mismatch');
    operations_owner_password($user, (string)($input['password'] ?? ''));
    $exists = db()->prepare('SELECT name FROM companies WHERE id=?');$exists->execute([$companyId]);
    if ($exists->fetchColumn() === false) fail('The company no longer exists.', 409, 'company_already_deleted');
    $result = operations_company_delete_execute($user, $companyId, (string)$req['company_name'], true, [
        'deletionRequestId' => $requestId, 'requestedBy' => (string)$req['requested_by_email'], 'reason' => (string)$req['reason'], 'approvedByPlatformOwner' => true,
    ], static function () use ($user, $requestId, $note): void {
        db()->prepare("UPDATE company_deletion_requests SET status='approved', decided_by=?, decided_at=UTC_TIMESTAMP(), decision_note=? WHERE id=? AND status='pending'")
            ->execute([(string)$user['id'], $note !== '' ? $note : null, $requestId]);
    });
    db()->prepare('UPDATE company_deletion_requests SET record_summary_json=? WHERE id=?')->execute([json_encode($result['summary'], JSON_UNESCAPED_SLASHES), $requestId]);
    tegh_lifecycle_platform_audit($user, 'platform.company_deletion_approved', $companyId, ['requestId' => $requestId, 'companyName' => (string)$req['company_name'], 'requestedBy' => (string)$req['requested_by_email'], 'recordsBeforeDeletion' => $result['summary']]);
    tegh_lifecycle_notify_requester($req, 'approved', $note);
    json_response(['approved' => true, 'deleted' => true, 'requestId' => $requestId, 'companyId' => $companyId, 'storageCleanup' => $result['storageCleanup'], 'recordSummary' => $result['summary']]);
}
