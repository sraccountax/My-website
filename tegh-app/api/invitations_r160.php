<?php
declare(strict_types=1);

/**
 * R160: who an invitation is for decides what it can do.
 *
 *  - Not registered: an invitation to set up their own companies, to work in the inviter's companies, or both.
 *  - Registered and active: only access to more of the inviter's companies (they accept with their current password;
 *    any Tegh login can already create its own companies).
 *  - Registered before, now deactivated or deleted: no invitation. The platform owner restores the old login instead,
 *    and Tegh emails the person that their earlier login was restored, with a link to set a new password.
 *
 * Tegh does not keep a deleted login's email address. It keeps the anonymised login record and, in the platform audit
 * log, a SHA-256 fingerprint of the old address (R156). An address typed again is recognised by that fingerprint.
 */

const TEGH_R160_RESTORE_HOURS = 72;

function tegh_r160_email_fingerprint(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

/** The deleted login an address belonged to, found by the fingerprint kept when it was deleted. */
function tegh_r160_deleted_login(string $email): ?array
{
    if (!schema_table_exists('platform_audit_log')) return null;
    $q = db()->prepare("SELECT target_id, created_at FROM platform_audit_log
        WHERE action IN ('platform.user_login_deleted','platform.user_login_deleted_override') AND target_type='user'
          AND metadata_json LIKE ? ORDER BY created_at DESC, id DESC LIMIT 1");
    $q->execute(['%"previousEmailSha256":"' . tegh_r160_email_fingerprint($email) . '"%']);
    $row = $q->fetch();
    if (!$row) return null;
    $u = db()->prepare("SELECT id, deleted_at FROM users WHERE id=? AND deleted_at IS NOT NULL AND email LIKE 'deleted+%@invalid.local'");
    $u->execute([(string)$row['target_id']]);
    $user = $u->fetch();
    return $user ? ['id' => (string)$user['id'], 'deletedAt' => (string)$user['deleted_at']] : null;
}

/**
 * What Tegh knows about an address: 'new', 'registered', 'deactivated' or 'deleted'.
 * $forPlatformOwner adds details (name, dates, which of the inviter's companies the person already has).
 */
function tegh_r160_email_status(array $inviter, string $email, bool $forPlatformOwner): array
{
    $q = db()->prepare('SELECT id, display_name, active, deleted_at, created_at FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1');
    $q->execute([$email]);
    $u = $q->fetch();
    if ($u && (int)$u['active'] === 1 && $u['deleted_at'] === null) {
        $out = ['status' => 'registered'];
        if ($forPlatformOwner) {
            $m = db()->prepare("SELECT company_id FROM company_members WHERE user_id=? AND (role='owner' OR status IN ('active','suspended'))");
            $m->execute([(string)$u['id']]);
            $out += ['displayName' => (string)$u['display_name'], 'registeredAt' => (string)$u['created_at'],
                'memberOfCompanyIds' => array_map('strval', $m->fetchAll(PDO::FETCH_COLUMN))];
        }
        return $out;
    }
    if ($u) {
        $out = ['status' => 'deactivated'];
        if ($forPlatformOwner) {
            $when = null;
            if (schema_table_exists('platform_audit_log')) {
                $a = db()->prepare("SELECT created_at FROM platform_audit_log WHERE action='platform.user_deactivated' AND target_id=? ORDER BY created_at DESC LIMIT 1");
                $a->execute([(string)$u['id']]);
                $when = $a->fetchColumn() ?: null;
            }
            $out += ['displayName' => (string)$u['display_name'], 'registeredAt' => (string)$u['created_at'], 'deactivatedAt' => $when];
        }
        return $out;
    }
    $deleted = tegh_r160_deleted_login($email);
    if ($deleted) return ['status' => 'deleted'] + ($forPlatformOwner ? ['deletedAt' => $deleted['deletedAt']] : []);
    return ['status' => 'new'];
}

/** Refuses an invitation that does not fit the person (called inside the invitation's transaction). */
function tegh_r160_assert_invitation_fits(array $inviter, string $email, string $scope): void
{
    $platform = platform_role_for_user((string)$inviter['id']) === 'platform_owner';
    $state = tegh_r160_email_status($inviter, $email, false)['status'];
    if ($state === 'deactivated') {
        fail($platform
            ? 'This email belongs to a deactivated Tegh login. Use “Restore login” to reactivate it and send the person a password reset email.'
            : 'This email belongs to a Tegh login that is not active. Ask Tegh support to restore it, then invite it again.', 409, 'invitation_user_deactivated');
    }
    if ($state === 'deleted') {
        fail($platform
            ? 'This email was registered before and its login was deleted. Use “Restore login” to bring the old login back and send the person a password reset email.'
            : 'This email had a Tegh login that was removed. Ask Tegh support to restore it, then invite it again.', 409, 'invitation_user_deleted');
    }
    if ($state === 'registered' && $scope === 'workspace') {
        fail('This person already has a Tegh account and can already create their own companies. Choose the companies to give them access to instead.', 409, 'invitation_user_exists');
    }
}

function tegh_r160_restore_email(string $email, string $was, string $token): array
{
    $url = password_reset_url($token);
    $hours = TEGH_R160_RESTORE_HOURS;
    $what = $was === 'deleted' ? 'deleted' : 'deactivated';
    $access = $was === 'deleted'
        ? 'The company access it had before it was deleted is not restored. The person who invited you can give you access to their companies again.'
        : 'It has the same company access it had before.';
    $subject = 'Your Tegh login has been restored';
    $text = "Your Tegh login for $email was previously $what. Tegh support has restored it.\n\nChoose a new password to sign in:\n$url\n\nThis link works once and expires in $hours hours. $access\n\nIf you did not expect this, you can ignore this email and nobody can sign in until a new password is set.";
    $html = '<!doctype html><html><body style="margin:0;background:#f2f6f7;color:#18333b"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f6f7"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #d9e5e7;border-radius:16px"><tr><td style="padding:32px;font-family:Arial,sans-serif"><div style="font-size:13px;font-weight:700;letter-spacing:.08em;color:#0d6670">TEGH</div><h1 style="font-size:26px;line-height:1.25;margin:14px 0;color:#173740">Your Tegh login has been restored</h1><p style="font-size:16px;line-height:1.6;color:#455e65">Your Tegh login for <b>' . htmlspecialchars($email) . '</b> was previously ' . $what . '. Tegh support has restored it.</p><p style="margin:28px 0"><a style="display:inline-block;padding:13px 20px;background:#0d6670;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700" href="' . htmlspecialchars($url) . '">Choose a new password</a></p><p style="font-size:14px;line-height:1.6;color:#455e65">This link works once and expires in ' . $hours . ' hours. ' . htmlspecialchars($access) . '</p><p style="font-size:13px;line-height:1.6;color:#6b8288">If you did not expect this, you can ignore this email; nobody can sign in until a new password is set.</p></td></tr></table></td></tr></table></body></html>';
    return sr_mail_send(null, null, $email, 'login_restored', $subject, $text, $html);
}

/** POST platform/invitations {action:'lookup', email}: platform owner only. */
function tegh_r160_handle_lookup(array $user, array $input): never
{
    admin_require_platform_owner($user);
    $email = safe_email($input['email'] ?? '');
    $state = tegh_r160_email_status($user, $email, true);
    $p = db()->prepare("SELECT id, created_at FROM account_invitations WHERE LOWER(email)=LOWER(?) AND status='pending' AND expires_at>UTC_TIMESTAMP() ORDER BY created_at DESC LIMIT 1");
    $p->execute([$email]);
    $pending = $p->fetch();
    json_response(['email' => $email] + $state + ['pendingInvitation' => $pending ? ['id' => (string)$pending['id'], 'createdAt' => (string)$pending['created_at']] : null]);
}

/** POST platform/invitations {action:'restore_login', email}: platform owner only. Reactivates or restores, then emails a password link. */
function tegh_r160_handle_restore(array $user, array $input): never
{
    admin_require_platform_owner($user);
    $email = safe_email($input['email'] ?? '');
    $token = base64url_encode(random_bytes(32));
    $result = db_transaction_retry(static function () use ($user, $email, $token): array {
        $state = tegh_r160_email_status($user, $email, true);
        if ($state['status'] === 'registered') fail('This email already has an active Tegh login. Give it access to companies instead.', 409, 'login_already_active');
        if ($state['status'] === 'new') fail('Tegh has no earlier login for this email. Send an invitation instead.', 409, 'login_not_found');
        if ($state['status'] === 'deactivated') {
            $q = db()->prepare('SELECT id, platform_role FROM users WHERE LOWER(email)=LOWER(?) FOR UPDATE');
            $q->execute([$email]);
            $u = $q->fetch();
            if (!$u || (string)$u['platform_role'] === 'platform_owner') fail('This login cannot be restored here.', 409, 'login_restore_unavailable');
            db()->prepare('UPDATE users SET active=1 WHERE id=?')->execute([(string)$u['id']]);
        } else {
            $deleted = tegh_r160_deleted_login($email);
            if (!$deleted) fail('The earlier login for this email was not found.', 409, 'login_not_found');
            $taken = db()->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email)=LOWER(?) FOR UPDATE');
            $taken->execute([$email]);
            if ((int)$taken->fetchColumn() > 0) fail('Another login already uses this email.', 409, 'login_email_taken');
            $u = ['id' => $deleted['id']];
            db()->prepare('UPDATE users SET email=?, display_name=?, active=1, deleted_at=NULL, deleted_by=NULL WHERE id=?')
                ->execute([$email, platform_default_display_name($email), $deleted['id']]);
        }
        $id = (string)$u['id'];
        // Only this new link can set the password; any older reset link stops working.
        db()->prepare('UPDATE password_reset_requests SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$id]);
        db()->prepare('INSERT INTO password_reset_requests (id,user_id,email_hash,token_hash,requested_ip_hash,expires_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP()+INTERVAL ' . TEGH_R160_RESTORE_HOURS . ' HOUR)')
            ->execute([new_id('reset'), $id, secret_hash($email), secret_hash($token), client_ip_hash()]);
        db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$id]);
        platform_audit_event($user, 'platform.user_login_restored', 'user', $id, ['previousState' => $state['status'], 'emailSha256' => tegh_r160_email_fingerprint($email)]);
        return ['userId' => $id, 'was' => $state['status']];
    });
    $delivery = [];
    try { $delivery = tegh_r160_restore_email($email, $result['was'], $token); }
    catch (Throwable $e) { error_log('Tegh R160 restore email: ' . $e->getMessage()); $delivery = ['sent' => false, 'status' => 'failed', 'message' => 'The login was restored, but the email could not be sent.']; }
    json_response(['restored' => true, 'email' => $email, 'was' => $result['was'], 'status' => 'registered',
        'emailSent' => !empty($delivery['sent']), 'deliveryStatus' => (string)($delivery['status'] ?? ''), 'deliveryMessage' => (string)($delivery['message'] ?? ''),
        'linkValidHours' => TEGH_R160_RESTORE_HOURS]);
}
