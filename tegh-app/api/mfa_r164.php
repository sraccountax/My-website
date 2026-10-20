<?php
declare(strict_types=1);

/*
 * R164: two-step sign-in.
 *
 * Policy (owner decision, R164 revision)
 *  - Every sign-in needs a second step. The default second step is a 6-digit code emailed to the account's
 *    registered address: nothing to set up, it is on for everyone.
 *  - An authenticator app (TOTP, RFC 6238) is optional. A person who sets one up (Account › Two-step
 *    verification, with a QR code) may use it instead of the email code. Recovery codes come with it.
 *  - "Trust this device for 30 days": after a successful second step the browser can be remembered; that
 *    browser then signs in with the password alone until the 30 days end or the trust is withdrawn (password
 *    change or reset, administrator MFA reset, deactivation or deletion, or "Forget trusted devices").
 *  - Sessions created where the email address was just proven (accepting an emailed invitation) or by the
 *    setup key (first platform owner) are already verified.
 *
 * Design
 *  - Email codes: 6 random digits, stored only as a keyed hash, single use, valid 10 minutes, bound to the
 *    session that asked for it, at most 5 wrong tries per code, at most 5 codes per 15 minutes per account.
 *  - TOTP: HMAC-SHA1, 30-second step, +/-1 step, replay guard; the secret is encrypted with AES-256-GCM under a
 *    key derived from app.secret (app.secret must not be rotated without re-enrolling authenticators).
 *  - Trusted devices: a random 256-bit token in an HttpOnly, Secure, SameSite=Strict cookie; only its keyed hash
 *    is stored, with the browser's user-agent hash; expiry is checked on the database clock.
 *  - Until the second step completes the session is UNVERIFIED and the router allows only the sign-in routes.
 *
 * All schema changes are additive and made on first use, matching the other R1xx modules.
 */

const TEGH_MFA_DIGITS = 6;
const TEGH_MFA_STEP_SECONDS = 30;
const TEGH_MFA_WINDOW = 1;            // accept the current step and one either side
const TEGH_MFA_MAX_FAILURES = 5;      // before a temporary lock
const TEGH_MFA_LOCK_MINUTES = 15;
const TEGH_MFA_RECOVERY_CODES = 10;
const TEGH_MFA_CHALLENGE_MINUTES = 10;
const TEGH_EMAIL_OTP_MINUTES = 10;       // an emailed sign-in code is valid this long
const TEGH_EMAIL_OTP_MAX_TRIES = 5;      // wrong entries per code before it is withdrawn
const TEGH_EMAIL_OTP_MAX_PER_WINDOW = 10; // codes per account per 15 minutes
const TEGH_EMAIL_OTP_RESEND_SECONDS = 30;
const TEGH_TRUSTED_DEVICE_DAYS = 30;
const TEGH_TRUSTED_DEVICE_COOKIE = 'tegh_td';

/**
 * MFA events are account-level, not company-level, so they go to the platform audit log
 * (platform_audit_log) rather than the company audit_log, whose company_id is NOT NULL with a
 * foreign key. The signature mirrors audit_event() so call sites read consistently; the second
 * argument (company) is intentionally ignored.
 */
function mfa_audit(array $user, string $companyIgnored, string $action, string $targetType, string $targetId, array $metadata = []): void
{
    if (function_exists('platform_audit_event')) { platform_audit_event($user, $action, $targetType, $targetId, $metadata); return; }
    if (!function_exists('schema_table_exists') || !schema_table_exists('platform_audit_log')) return;
    try {
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        db()->prepare('INSERT INTO platform_audit_log
            (id,actor_user_id,actor_email,action,target_type,target_id,metadata_json,request_id,ip_hash,user_agent_hash)
            VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([new_id('platformaudit'), $user['id'], $user['email'], $action, $targetType, $targetId, $json,
                request_id(), client_ip_hash(), user_agent_hash()]);
    } catch (Throwable $e) {
        error_log('Tegh R164 MFA audit: ' . $e->getMessage());
    }
}

function mfa_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        if (!schema_table_exists('user_mfa')) {
            db()->exec("CREATE TABLE IF NOT EXISTS user_mfa (
 user_id VARCHAR(64) NOT NULL PRIMARY KEY,
 secret_cipher VARBINARY(255) NOT NULL,
 secret_nonce VARBINARY(32) NOT NULL,
 secret_tag VARBINARY(32) NOT NULL,
 confirmed TINYINT(1) NOT NULL DEFAULT 0,
 enabled_at DATETIME NULL,
 last_used_step BIGINT NULL,
 failed_count INT NOT NULL DEFAULT 0,
 locked_until DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT user_mfa_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!schema_table_exists('user_mfa_recovery_codes')) {
            db()->exec("CREATE TABLE IF NOT EXISTS user_mfa_recovery_codes (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 user_id VARCHAR(64) NOT NULL,
 code_hash CHAR(64) NOT NULL,
 used_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY user_mfa_recovery_user_idx (user_id),
 CONSTRAINT user_mfa_recovery_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!schema_table_exists('user_email_otp')) {
            db()->exec("CREATE TABLE IF NOT EXISTS user_email_otp (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 user_id VARCHAR(64) NOT NULL,
 session_id VARCHAR(64) NOT NULL,
 code_hash CHAR(64) NOT NULL,
 tries INT NOT NULL DEFAULT 0,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY user_email_otp_user_idx (user_id, created_at),
 KEY user_email_otp_session_idx (session_id),
 CONSTRAINT user_email_otp_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!schema_table_exists('user_trusted_devices')) {
            db()->exec("CREATE TABLE IF NOT EXISTS user_trusted_devices (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 user_id VARCHAR(64) NOT NULL,
 token_hash CHAR(64) NOT NULL,
 user_agent_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 last_used_at DATETIME NULL,
 revoked_at DATETIME NULL,
 KEY user_trusted_devices_user_idx (user_id),
 CONSTRAINT user_trusted_devices_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (schema_table_exists('sessions') && !schema_column_exists('sessions', 'mfa_verified')) {
            // Existing sessions predate MFA; default them verified so they are not locked out
            // mid-session. New sessions set the value explicitly at creation.
            schema_add_column('sessions', 'mfa_verified', "TINYINT(1) NOT NULL DEFAULT 1");
        }
        $ready = schema_table_exists('user_mfa') && schema_table_exists('user_mfa_recovery_codes')
            && schema_table_exists('user_email_otp') && schema_table_exists('user_trusted_devices')
            && schema_column_exists('sessions', 'mfa_verified');
    } catch (Throwable $e) {
        error_log('Tegh R164 MFA setup: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/* --------------------------------------------------------------------- secret encryption */

/** 32-byte key derived from app.secret, domain-separated for MFA. */
function mfa_encryption_key(): string
{
    $secret = (string)config('app.secret');
    if ($secret === '' || strlen($secret) < 16) {
        throw new RuntimeException('app.secret is not configured; MFA secrets cannot be protected.');
    }
    // HKDF-SHA256 with a fixed info string so this key is independent of secret_hash().
    return hash_hkdf('sha256', $secret, 32, 'tegh.mfa.totp.v1');
}

/** @return array{cipher:string,nonce:string,tag:string} */
function mfa_encrypt_secret(string $base32Secret): array
{
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($base32Secret, 'aes-256-gcm', mfa_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) throw new RuntimeException('MFA secret encryption failed.');
    return ['cipher' => $cipher, 'nonce' => $nonce, 'tag' => $tag];
}

function mfa_decrypt_secret(string $cipher, string $nonce, string $tag): string
{
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', mfa_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plain === false) throw new RuntimeException('MFA secret could not be decrypted (key changed or data corrupt).');
    return $plain;
}

/* --------------------------------------------------------------------- base32 + TOTP */

function mfa_base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $buffer = 0;
    $bitsLeft = 0;
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        $buffer = ($buffer << 8) | ord($bytes[$i]);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $bitsLeft -= 5;
            $out .= $alphabet[($buffer >> $bitsLeft) & 31];
        }
    }
    if ($bitsLeft > 0) {
        $out .= $alphabet[($buffer << (5 - $bitsLeft)) & 31];
    }
    return $out;
}

function mfa_base32_decode(string $value): string
{
    $map = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
    $value = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $value) ?? '');
    $buffer = 0;
    $bitsLeft = 0;
    $out = '';
    for ($i = 0, $n = strlen($value); $i < $n; $i++) {
        if (!isset($map[$value[$i]])) continue;
        $buffer = ($buffer << 5) | $map[$value[$i]];
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $out .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $out;
}

/** The TOTP code for a given step counter. */
function mfa_totp_at(string $base32Secret, int $step): string
{
    $key = mfa_base32_decode($base32Secret);
    $binStep = pack('N*', 0) . pack('N*', $step); // 64-bit big-endian counter
    $hash = hash_hmac('sha1', $binStep, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % (10 ** TEGH_MFA_DIGITS);
    return str_pad((string)$part, TEGH_MFA_DIGITS, '0', STR_PAD_LEFT);
}

function mfa_current_step(?int $now = null): int
{
    return (int)floor(($now ?? time()) / TEGH_MFA_STEP_SECONDS);
}

/**
 * Verify a submitted code against the window. Returns the accepted step, or null.
 * The caller must enforce replay protection (last_used_step) with the returned step.
 */
function mfa_totp_match(string $base32Secret, string $code, ?int $lastUsedStep = null, ?int $now = null): ?int
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== TEGH_MFA_DIGITS) return null;
    $current = mfa_current_step($now);
    for ($delta = -TEGH_MFA_WINDOW; $delta <= TEGH_MFA_WINDOW; $delta++) {
        $step = $current + $delta;
        if ($lastUsedStep !== null && $step <= $lastUsedStep) continue; // no replay of a used/earlier step
        if (hash_equals(mfa_totp_at($base32Secret, $step), $code)) return $step;
    }
    return null;
}

/* --------------------------------------------------------------------- policy + status */

/**
 * True when this user needs a second sign-in step. Since the R164 revision every account does: the emailed code
 * is always available, and an authenticator app is an optional alternative.
 */
function mfa_required_for_user(string $userId): bool
{
    return $userId !== '';
}

/** @return array{confirmed:bool,locked:bool,lockedUntil:?string,row:?array} */
function mfa_state(string $userId): array
{
    if (!mfa_schema_ready()) return ['confirmed' => false, 'locked' => false, 'lockedUntil' => null, 'row' => null];
    $stmt = db()->prepare('SELECT * FROM user_mfa WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: null;
    $locked = false;
    $lockedUntil = null;
    if ($row && $row['locked_until'] !== null) {
        $locked = strtotime((string)$row['locked_until'] . ' UTC') > time();
        $lockedUntil = $locked ? (string)$row['locked_until'] . 'Z' : null;
    }
    return [
        'confirmed' => (bool)($row['confirmed'] ?? false),
        'locked' => $locked,
        'lockedUntil' => $lockedUntil,
        'row' => $row,
    ];
}

function mfa_is_enrolled(string $userId): bool
{
    return mfa_state($userId)['confirmed'];
}

/** Record a failed factor attempt and apply a temporary lock after the threshold. */
function mfa_register_failure(string $userId): void
{
    db()->prepare("UPDATE user_mfa SET failed_count=failed_count+1,
        locked_until = IF(failed_count+1 >= ?, UTC_TIMESTAMP()+INTERVAL ? MINUTE, locked_until)
        WHERE user_id=?")->execute([TEGH_MFA_MAX_FAILURES, TEGH_MFA_LOCK_MINUTES, $userId]);
}

function mfa_clear_failures(string $userId): void
{
    db()->prepare('UPDATE user_mfa SET failed_count=0, locked_until=NULL WHERE user_id=?')->execute([$userId]);
}

/* --------------------------------------------------------------------- recovery codes */

/** Generate, store (hashed) and return N plaintext recovery codes. Replaces any existing set. */
function mfa_issue_recovery_codes(string $userId): array
{
    db()->prepare('DELETE FROM user_mfa_recovery_codes WHERE user_id=?')->execute([$userId]);
    $codes = [];
    $insert = db()->prepare('INSERT INTO user_mfa_recovery_codes (id,user_id,code_hash) VALUES (?,?,?)');
    for ($i = 0; $i < TEGH_MFA_RECOVERY_CODES; $i++) {
        // 10 hex chars grouped as XXXXX-XXXXX, ~40 bits of entropy each.
        $raw = bin2hex(random_bytes(5));
        $display = strtoupper(substr($raw, 0, 5) . '-' . substr($raw, 5, 5));
        $codes[] = $display;
        $insert->execute([new_id('mfarec'), $userId, secret_hash(mfa_normalize_recovery($display))]);
    }
    return $codes;
}

function mfa_normalize_recovery(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

/** Consume a recovery code (single use, constant-time). Returns true on success. */
function mfa_consume_recovery_code(string $userId, string $code): bool
{
    $normalized = mfa_normalize_recovery($code);
    if ($normalized === '') return false;
    // code_hash is HMAC-SHA256(code, app.secret); an attacker cannot compute it without the
    // server key, so matching the stored hash directly in SQL leaks nothing about the code.
    $hash = secret_hash($normalized);
    $find = db()->prepare('SELECT id FROM user_mfa_recovery_codes WHERE user_id=? AND code_hash=? AND used_at IS NULL LIMIT 1 FOR UPDATE');
    $find->execute([$userId, $hash]);
    $matchId = $find->fetchColumn();
    if ($matchId === false) return false;
    db()->prepare('UPDATE user_mfa_recovery_codes SET used_at=UTC_TIMESTAMP() WHERE id=? AND used_at IS NULL')->execute([$matchId]);
    return true;
}

function mfa_recovery_remaining(string $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM user_mfa_recovery_codes WHERE user_id=? AND used_at IS NULL');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/* --------------------------------------------------------------------- emailed sign-in codes */

/** "o***@example.com": enough for the person to recognise the address, not enough to learn it. */
function mfa_email_hint(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($local, 0, 1) . str_repeat('*', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain;
}

/**
 * Create and email a sign-in code for this session. Returns ['sent'=>bool,'message'=>string]. A code is never
 * returned to the caller. Earlier unused codes for the session stop working.
 */
function mfa_send_email_code(array $user, string $sessionId): array
{
    $userId = (string)$user['id'];
    // The account-wide cap limits abuse; the short wait applies only to resending within the same sign-in, so a
    // second sign-in (another tab, a mistyped password) always gets its own code.
    $recent = db()->prepare('SELECT COUNT(*), TIMESTAMPDIFF(SECOND, MAX(IF(session_id=?, created_at, NULL)), UTC_TIMESTAMP()) FROM user_email_otp WHERE user_id=? AND created_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE');
    $recent->execute([$sessionId, $userId]);
    [$count, $sinceLast] = $recent->fetch(PDO::FETCH_NUM) ?: [0, null];
    if ((int)$count >= TEGH_EMAIL_OTP_MAX_PER_WINDOW) {
        return ['sent' => false, 'code' => 'mfa_email_rate_limited', 'message' => 'Too many codes were requested. Wait 15 minutes, or use your authenticator app or a recovery code.'];
    }
    if ($sinceLast !== null && (int)$sinceLast < TEGH_EMAIL_OTP_RESEND_SECONDS) {
        return ['sent' => false, 'code' => 'mfa_email_wait', 'message' => 'A code was just sent. Wait a few seconds before asking for another.'];
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db()->prepare('UPDATE user_email_otp SET used_at=UTC_TIMESTAMP() WHERE session_id=? AND used_at IS NULL')->execute([$sessionId]);
    db()->prepare('INSERT INTO user_email_otp (id,user_id,session_id,code_hash,expires_at) VALUES (?,?,?,?,UTC_TIMESTAMP()+INTERVAL ? MINUTE)')
        ->execute([new_id('emailotp'), $userId, $sessionId, secret_hash('tegh.email-otp|' . $sessionId . '|' . $code), TEGH_EMAIL_OTP_MINUTES]);
    $subject = 'Your Tegh sign-in code: ' . $code;
    $text = "Your Tegh sign-in code is $code\n\nIt works once and expires in " . TEGH_EMAIL_OTP_MINUTES . " minutes.\n\nIf you did not try to sign in, someone may know your password: change it now and tell your administrator.";
    $html = '<!doctype html><html><body style="margin:0;background:#f2f6f7;color:#18333b"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f6f7"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border:1px solid #d9e5e7;border-radius:16px"><tr><td style="padding:32px;font-family:Arial,sans-serif"><div style="font-size:13px;font-weight:700;letter-spacing:.08em;color:#0d6670">TEGH</div><h1 style="font-size:22px;margin:14px 0;color:#173740">Your sign-in code</h1><p style="font:700 34px/1.2 Consolas,Menlo,monospace;letter-spacing:6px;margin:18px 0;color:#0d6670">' . $code . '</p><p style="font-size:14px;line-height:1.55;color:#5f747a">It works once and expires in ' . TEGH_EMAIL_OTP_MINUTES . ' minutes.</p><hr style="border:0;border-top:1px solid #e2ebed;margin:24px 0"><p style="font-size:12px;line-height:1.5;color:#768a90">If you did not try to sign in, someone may know your password. Change it now and tell your administrator.</p></td></tr></table></td></tr></table></body></html>';
    try {
        if (!function_exists('sr_mail_send') && is_file(__DIR__ . '/portal.php')) require_once __DIR__ . '/portal.php';
        $result = sr_mail_send(null, $userId, (string)$user['email'], 'login_code', $subject, $text, $html);
        if (empty($result['sent'])) throw new RuntimeException((string)($result['status'] ?? 'not sent'));
    } catch (Throwable $error) {
        error_log('Tegh sign-in code email failed request=' . request_id() . ' class=' . $error::class);
        // Everyone signs in with an emailed code, so a mail outage locks people out: alert the operator (throttled).
        if (!function_exists('tegh_monitor_alert') && is_file(__DIR__ . '/monitoring_r164.php')) require_once __DIR__ . '/monitoring_r164.php';
        if (function_exists('tegh_monitor_alert')) tegh_monitor_alert('login_email_failed', 'A sign-in code could not be emailed. Check the SMTP settings; people without an authenticator app cannot sign in until email works.', ['Time (UTC)' => gmdate('Y-m-d H:i'), 'Request reference' => request_id()]);
        return ['sent' => false, 'code' => 'mfa_email_failed', 'message' => 'The sign-in code could not be emailed. Try again shortly, or use your authenticator app or a recovery code.'];
    }
    mfa_audit($user, '', 'mfa.email_code_sent', 'user', $userId, []);
    return ['sent' => true, 'code' => 'mfa_email_sent', 'message' => 'We emailed a 6-digit code to ' . mfa_email_hint((string)$user['email']) . '.'];
}

/** Check an emailed code for this session. Wrong tries are counted per code; the 5th withdraws it. */
function mfa_check_email_code(string $userId, string $sessionId, string $code): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    $find = db()->prepare('SELECT id,code_hash,tries FROM user_email_otp WHERE user_id=? AND session_id=? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY created_at DESC LIMIT 1 FOR UPDATE');
    $find->execute([$userId, $sessionId]);
    $row = $find->fetch();
    if (!$row) return false;
    if (strlen($code) === 6 && hash_equals((string)$row['code_hash'], secret_hash('tegh.email-otp|' . $sessionId . '|' . $code))) {
        db()->prepare('UPDATE user_email_otp SET used_at=UTC_TIMESTAMP() WHERE id=?')->execute([$row['id']]);
        return true;
    }
    $tries = (int)$row['tries'] + 1;
    db()->prepare('UPDATE user_email_otp SET tries=?, used_at=IF(?>=?,UTC_TIMESTAMP(),used_at) WHERE id=?')->execute([$tries, $tries, TEGH_EMAIL_OTP_MAX_TRIES, $row['id']]);
    return false;
}

/* --------------------------------------------------------------------- trusted devices */

/** True when this browser carries a valid, unexpired, unrevoked trust for this user. */
function mfa_trusted_device_valid(string $userId): bool
{
    if (!mfa_schema_ready()) return false;
    $raw = (string)($_COOKIE[TEGH_TRUSTED_DEVICE_COOKIE] ?? '');
    if (!preg_match('/^([A-Za-z0-9_]{1,64})\.([A-Za-z0-9_-]{40,64})$/', $raw, $m)) return false;
    $stmt = db()->prepare('SELECT token_hash,user_agent_hash FROM user_trusted_devices WHERE id=? AND user_id=? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([$m[1], $userId]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals((string)$row['token_hash'], secret_hash('tegh.trusted-device|' . $m[2]))) return false;
    if (!hash_equals((string)$row['user_agent_hash'], user_agent_hash())) return false;
    db()->prepare('UPDATE user_trusted_devices SET last_used_at=UTC_TIMESTAMP() WHERE id=?')->execute([$m[1]]);
    return true;
}

function mfa_trust_this_device(array $user): void
{
    $id = new_id('tdev');
    $token = base64url_encode(random_bytes(32));
    db()->prepare('INSERT INTO user_trusted_devices (id,user_id,token_hash,user_agent_hash,expires_at) VALUES (?,?,?,?,UTC_TIMESTAMP()+INTERVAL ? DAY)')
        ->execute([$id, (string)$user['id'], secret_hash('tegh.trusted-device|' . $token), user_agent_hash(), TEGH_TRUSTED_DEVICE_DAYS]);
    setcookie(TEGH_TRUSTED_DEVICE_COOKIE, $id . '.' . $token, [
        'expires' => time() + TEGH_TRUSTED_DEVICE_DAYS * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
    ]);
    mfa_audit($user, '', 'mfa.device_trusted', 'user', (string)$user['id'], ['days' => TEGH_TRUSTED_DEVICE_DAYS]);
}

/** Withdraw every trusted device of a user (password change or reset, MFA reset, deactivation, deletion, or on request). */
function mfa_revoke_trusted_devices(string $userId): int
{
    if (!function_exists('schema_table_exists') || !schema_table_exists('user_trusted_devices')) return 0;
    $stmt = db()->prepare('UPDATE user_trusted_devices SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL');
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

function mfa_trusted_device_count(string $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM user_trusted_devices WHERE user_id=? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Called by handle_login after a correct password. A trusted browser gets a verified session at once.
 * Otherwise the session is unverified, a code is emailed, and the client is told which methods it may use.
 */
function mfa_login_second_step(array $user): never
{
    $userId = (string)$user['id'];
    if (!mfa_schema_ready()) json_response(auth_payload($user, create_session($userId, true)));
    if (mfa_trusted_device_valid($userId)) {
        $session = create_session($userId, true);
        mfa_audit($user, '', 'mfa.trusted_device_used', 'user', $userId, []);
        json_response(auth_payload($user, $session));
    }
    $session = create_session($userId, false);
    $send = mfa_send_email_code($user, (string)$session['sessionId']);
    $totp = mfa_is_enrolled($userId);
    json_response([
        'mfaRequired' => true, 'mfaEnrolled' => $totp, 'methods' => $totp ? ['email', 'totp', 'recovery'] : ['email'],
        'emailHint' => mfa_email_hint((string)$user['email']), 'emailSent' => $send['sent'], 'emailStatus' => $send['code'],
        'trustDays' => TEGH_TRUSTED_DEVICE_DAYS, 'csrfToken' => $session['csrfToken'], 'message' => $send['message'],
    ]);
}

/** Resend the emailed code for the current (unverified) session. */
function handle_mfa_email_send(): never
{
    require_method('POST');
    require_csrf();
    if (!mfa_schema_ready()) fail('Two-step verification is not available on this installation.', 503, 'mfa_unavailable', false);
    $user = require_user();
    $result = mfa_send_email_code($user, (string)$user['sessionId']);
    if (!$result['sent']) fail($result['message'], $result['code'] === 'mfa_email_failed' ? 503 : 429, $result['code'], false);
    json_response(['sent' => true, 'emailHint' => mfa_email_hint((string)$user['email']), 'message' => $result['message']]);
}

/** Forget every trusted device of the signed-in user (password required). */
function handle_mfa_devices(): never
{
    require_method('GET', 'POST');
    $user = require_user();
    mfa_require_verified_session();
    if (request_method() === 'GET') json_response(['trustedDevices' => mfa_trusted_device_count((string)$user['id']), 'trustDays' => TEGH_TRUSTED_DEVICE_DAYS]);
    require_csrf();
    $input = request_json();
    mfa_require_password($user, (string)($input['password'] ?? ''));
    $n = mfa_revoke_trusted_devices((string)$user['id']);
    setcookie(TEGH_TRUSTED_DEVICE_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    mfa_audit($user, '', 'mfa.trusted_devices_forgotten', 'user', (string)$user['id'], ['count' => $n]);
    json_response(['forgotten' => $n, 'message' => $n === 1 ? 'One trusted device was forgotten.' : "$n trusted devices were forgotten."]);
}

/* --------------------------------------------------------------------- session verification flag */

function mfa_mark_session_verified(string $sessionId): void
{
    if (schema_column_exists('sessions', 'mfa_verified')) {
        db()->prepare('UPDATE sessions SET mfa_verified=1 WHERE id=?')->execute([$sessionId]);
    }
}

function mfa_session_is_verified(?array $session): bool
{
    if (!$session) return false;
    if (!schema_column_exists('sessions', 'mfa_verified')) return true;
    return (int)($session['mfa_verified'] ?? 1) === 1;
}

/**
 * The router gate. For an authenticated-but-unverified session, allow only the MFA
 * endpoints, the identity probe and logout; refuse everything else with a code the
 * client uses to show the right screen (verify vs enrol).
 */
function mfa_gate(string $route): void
{
    if (!mfa_schema_ready()) return;
    $session = session_record();
    if (!$session) return; // unauthenticated requests are handled by the normal auth gate
    if (mfa_session_is_verified($session)) return;
    $allowed = in_array($route, ['auth/me', 'auth/logout', 'auth/mfa/verify', 'auth/mfa/email-send', 'auth/mfa/status'], true);
    if ($allowed) return;
    fail('Enter the sign-in code we emailed you to continue.', 403, 'mfa_verification_required', false);
}

/* --------------------------------------------------------------------- handlers */

/** Shape returned to the client so it can render the right state. */
function mfa_status_payload(array $user, ?array $session = null): array
{
    $userId = (string)$user['id'];
    $state = mfa_state($userId);
    return [
        'required' => mfa_required_for_user($userId),
        'enrolled' => $state['confirmed'],
        'verified' => mfa_session_is_verified($session ?? session_record()),
        'locked' => $state['locked'],
        'lockedUntil' => $state['lockedUntil'],
        'recoveryCodesRemaining' => $state['confirmed'] ? mfa_recovery_remaining($userId) : 0,
        'methods' => $state['confirmed'] ? ['email', 'totp', 'recovery'] : ['email'],
        'emailHint' => mfa_email_hint((string)($user['email'] ?? '')),
        'trustedDevices' => mfa_schema_ready() ? mfa_trusted_device_count($userId) : 0,
        'trustDays' => TEGH_TRUSTED_DEVICE_DAYS,
    ];
}

function handle_mfa_status(): never
{
    require_method('GET');
    $user = require_user();
    json_response(mfa_status_payload($user));
}

/**
 * Begin (or restart) enrolment: requires the current password (step-up), generates a new
 * unconfirmed secret and returns the otpauth URI + the manual key. The secret is not active
 * until confirmed with a valid code.
 */
function handle_mfa_enroll_begin(): never
{
    require_method('POST');
    require_csrf();
    if (!mfa_schema_ready()) fail('Two-step verification is not available on this installation.', 503, 'mfa_unavailable', false);
    $user = require_user();
    mfa_require_verified_session();
    $input = request_json();
    mfa_require_password($user, (string)($input['password'] ?? ''));
    $state = mfa_state((string)$user['id']);
    if ($state['confirmed']) fail('Two-step verification is already set up. Turn it off first to re-enrol.', 409, 'mfa_already_enrolled');

    $secret = mfa_base32_encode(random_bytes(20)); // 160-bit secret
    $enc = mfa_encrypt_secret($secret);
    db()->prepare('INSERT INTO user_mfa (user_id,secret_cipher,secret_nonce,secret_tag,confirmed,last_used_step,failed_count,locked_until)
        VALUES (?,?,?,?,0,NULL,0,NULL)
        ON DUPLICATE KEY UPDATE secret_cipher=VALUES(secret_cipher),secret_nonce=VALUES(secret_nonce),secret_tag=VALUES(secret_tag),confirmed=0,enabled_at=NULL,last_used_step=NULL,failed_count=0,locked_until=NULL')
        ->execute([(string)$user['id'], $enc['cipher'], $enc['nonce'], $enc['tag']]);

    $issuer = rawurlencode((string)(config('app.mfa_issuer') ?? 'Tegh'));
    $label = rawurlencode((string)(config('app.mfa_issuer') ?? 'Tegh') . ':' . (string)$user['email']);
    $otpauth = "otpauth://totp/$label?secret=$secret&issuer=$issuer&algorithm=SHA1&digits=" . TEGH_MFA_DIGITS . '&period=' . TEGH_MFA_STEP_SECONDS;
    mfa_audit($user, '', 'mfa.enrollment_started', 'user', (string)$user['id'], ['method' => 'totp']);
    json_response(['secret' => $secret, 'otpauthUri' => $otpauth, 'digits' => TEGH_MFA_DIGITS, 'period' => TEGH_MFA_STEP_SECONDS]);
}

/** Confirm enrolment with a valid code; activates MFA and returns one-time recovery codes. */
function handle_mfa_enroll_confirm(): never
{
    require_method('POST');
    require_csrf();
    if (!mfa_schema_ready()) fail('Two-step verification is not available on this installation.', 503, 'mfa_unavailable', false);
    $user = require_user();
    mfa_require_verified_session();
    $input = request_json();
    $code = (string)($input['code'] ?? '');

    $result = db_transaction_retry(static function () use ($user, $code): array {
        $stmt = db()->prepare('SELECT * FROM user_mfa WHERE user_id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([(string)$user['id']]);
        $row = $stmt->fetch();
        if (!$row) fail('Start two-step verification setup first.', 409, 'mfa_not_started');
        if ((int)$row['confirmed'] === 1) fail('Two-step verification is already set up.', 409, 'mfa_already_enrolled');
        $secret = mfa_decrypt_secret((string)$row['secret_cipher'], (string)$row['secret_nonce'], (string)$row['secret_tag']);
        $step = mfa_totp_match($secret, $code, $row['last_used_step'] !== null ? (int)$row['last_used_step'] : null);
        if ($step === null) {
            mfa_audit($user, '', 'mfa.enrollment_code_rejected', 'user', (string)$user['id'], []);
            fail('That code is not correct. Check your authenticator app and try again.', 422, 'mfa_code_invalid');
        }
        db()->prepare('UPDATE user_mfa SET confirmed=1, enabled_at=UTC_TIMESTAMP(), last_used_step=?, failed_count=0, locked_until=NULL WHERE user_id=?')
            ->execute([$step, (string)$user['id']]);
        $codes = mfa_issue_recovery_codes((string)$user['id']);
        return ['recoveryCodes' => $codes];
    });

    // The session was already verified (mfa_require_verified_session); setting up an app never verifies a session.
    mfa_audit($user, '', 'mfa.enabled', 'user', (string)$user['id'], ['method' => 'totp', 'recoveryCodesIssued' => TEGH_MFA_RECOVERY_CODES]);
    $payload = auth_payload($user);
    $payload['mfa']['verified'] = true;
    $payload['enabled'] = true;
    $payload['recoveryCodes'] = $result['recoveryCodes'];
    $payload['message'] = 'Two-step verification is on. Save these one-time recovery codes somewhere safe.';
    json_response($payload);
}

/** Second sign-in step: an emailed code, an authenticator code or a recovery code; optionally trust this browser. */
function handle_mfa_verify(): never
{
    require_method('POST');
    require_csrf();
    if (!mfa_schema_ready()) fail('Two-step verification is not available on this installation.', 503, 'mfa_unavailable', false);
    $user = require_user();
    $input = request_json();
    $code = trim((string)($input['code'] ?? ''));
    $method = (string)($input['method'] ?? (!empty($input['recovery']) ? 'recovery' : 'email'));
    if (!in_array($method, ['email', 'totp', 'recovery'], true)) fail('Choose how to verify.', 422, 'mfa_method_invalid');
    $userId = (string)$user['id'];

    $state = mfa_state($userId);
    if ($method !== 'email') {
        if (!$state['confirmed']) fail('No authenticator app is set up for this account. Use the emailed code.', 409, 'mfa_not_enrolled');
        if ($state['locked']) fail('Too many incorrect codes. Try again later or use the emailed code.', 429, 'mfa_locked', false);
    }

    $ok = db_transaction_retry(static function () use ($method, $userId, $user, $code, $state): bool {
        if ($method === 'email') return mfa_check_email_code($userId, (string)$user['sessionId'], $code);
        if ($method === 'recovery') return mfa_consume_recovery_code($userId, $code);
        $row = $state['row'];
        $secret = mfa_decrypt_secret((string)$row['secret_cipher'], (string)$row['secret_nonce'], (string)$row['secret_tag']);
        $step = mfa_totp_match($secret, $code, $row['last_used_step'] !== null ? (int)$row['last_used_step'] : null);
        if ($step === null) return false;
        db()->prepare('UPDATE user_mfa SET last_used_step=? WHERE user_id=?')->execute([$step, $userId]);
        return true;
    });

    if (!$ok) {
        if ($method !== 'email') mfa_register_failure($userId);
        mfa_audit($user, '', 'mfa.verify_failed', 'user', $userId, ['method' => $method]);
        fail($method === 'email' ? 'That code is not correct or has expired. Check the latest email, or ask for a new code.' : 'That code is not correct.', 422, 'mfa_code_invalid');
    }
    if ($method !== 'email' && $state['confirmed']) mfa_clear_failures($userId);
    mfa_mark_session_verified((string)$user['sessionId']);
    // A recovery code usually means a lost or exposed device: end the account's other sessions and trusted devices.
    if ($method === 'recovery') { tegh_end_user_sessions($userId, (string)$user['sessionId']); mfa_revoke_trusted_devices($userId); }
    elseif (!empty($input['rememberDevice'])) mfa_trust_this_device($user);
    mfa_audit($user, '', 'mfa.verified', 'user', $userId, ['method' => $method, 'deviceTrusted' => $method !== 'recovery' && !empty($input['rememberDevice'])]);
    // session_record() is memoized within this request and still reflects the pre-verification row.
    $payload = auth_payload($user);
    $payload['mfa']['verified'] = true;
    $payload['verified'] = true;
    $payload['recoveryCodeUsed'] = $method === 'recovery';
    $payload['recoveryCodesRemaining'] = $state['confirmed'] ? mfa_recovery_remaining($userId) : 0;
    json_response($payload);
}

/** Remove the authenticator app (step-up by password). Emailed codes remain the second step. */
function handle_mfa_disable(): never
{
    require_method('POST');
    require_csrf();
    if (!mfa_schema_ready()) fail('Two-step verification is not available on this installation.', 503, 'mfa_unavailable', false);
    $user = require_user();
    mfa_require_verified_session();
    $input = request_json();
    mfa_require_password($user, (string)($input['password'] ?? ''));
    db()->prepare('DELETE FROM user_mfa WHERE user_id=?')->execute([(string)$user['id']]);
    db()->prepare('DELETE FROM user_mfa_recovery_codes WHERE user_id=?')->execute([(string)$user['id']]);
    tegh_end_user_sessions((string)$user['id'], (string)$user['sessionId']); // R164: a weaker sign-in method ends other sessions
    mfa_audit($user, '', 'mfa.disabled', 'user', (string)$user['id'], ['method' => 'totp']);
    json_response(['enabled' => false, 'message' => 'The authenticator app is removed. Sign-in codes will come by email.']);
}

/** Regenerate recovery codes (step-up by password); invalidates the old set. */
function handle_mfa_recovery_regenerate(): never
{
    require_method('POST');
    require_csrf();
    if (!mfa_schema_ready()) fail('Two-step verification is not available on this installation.', 503, 'mfa_unavailable', false);
    $user = require_user();
    mfa_require_verified_session();
    $input = request_json();
    mfa_require_password($user, (string)($input['password'] ?? ''));
    if (!mfa_is_enrolled((string)$user['id'])) fail('Two-step verification is not set up for this account.', 409, 'mfa_not_enrolled');
    $codes = mfa_issue_recovery_codes((string)$user['id']);
    mfa_audit($user, '', 'mfa.recovery_codes_regenerated', 'user', (string)$user['id'], ['count' => TEGH_MFA_RECOVERY_CODES]);
    json_response(['recoveryCodes' => $codes, 'message' => 'New recovery codes generated. The previous codes no longer work.']);
}

/**
 * Managing sign-in methods (authenticator setup or removal, recovery codes, trusted devices) needs a session that has
 * already passed the second step. Otherwise someone holding only the password could add their own authenticator and
 * skip the emailed code. These routes are dispatched before the router's MFA gate, so each handler checks itself.
 */
function mfa_require_verified_session(): void
{
    if (!mfa_session_is_verified(session_record())) {
        fail('Enter the sign-in code we emailed you first.', 403, 'mfa_verification_required', false);
    }
}

/** Step-up: confirm the signed-in user's current password before a sensitive MFA change. */
function mfa_require_password(array $user, string $password): void
{
    if ($password === '') fail('Re-enter your password to make this change.', 401, 'password_confirmation_required', false);
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=? AND active=1 LIMIT 1');
    $stmt->execute([(string)$user['id']]);
    $hash = $stmt->fetchColumn();
    if ($hash === false || !password_verify($password, (string)$hash)) {
        usleep(400000);
        fail('That password is not correct.', 401, 'password_confirmation_invalid', false);
    }
}

/* --------------------------------------------------------------------- R164 item 3: step-up + session invalidation */

/**
 * Renewed authentication for sensitive administrative changes. Accepted when this session signed in
 * within the last $windowSeconds, or when the request carries the signed-in user's current password.
 * Otherwise the change is refused with a code the client can act on (sign in again, or re-enter password).
 */
function tegh_require_recent_auth(array $user, array $input, int $windowSeconds = 900): void
{
    // Session age is measured on the database clock, the same clock that stamped created_at.
    $age = db()->prepare('SELECT TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) FROM sessions WHERE id=? AND user_id=? LIMIT 1');
    $age->execute([(string)($user['sessionId'] ?? ''), (string)$user['id']]);
    $seconds = $age->fetchColumn();
    if ($seconds !== false && $seconds !== null && (int)$seconds >= 0 && (int)$seconds <= $windowSeconds) return;
    $password = (string)($input['password'] ?? '');
    if ($password === '') {
        fail('For security, confirm your password (or sign out and sign in again) before making this change.', 403, 'recent_authentication_required', false);
    }
    mfa_require_password($user, $password);
}

/** End every session of a user (e.g. after their access or role changed) so the next sign-in applies the new rules, including MFA. */
function tegh_end_user_sessions(string $userId, ?string $exceptSessionId = null): int
{
    if ($exceptSessionId !== null) {
        $stmt = db()->prepare('DELETE FROM sessions WHERE user_id=? AND id<>?');
        $stmt->execute([$userId, $exceptSessionId]);
    } else {
        $stmt = db()->prepare('DELETE FROM sessions WHERE user_id=?');
        $stmt->execute([$userId]);
    }
    return $stmt->rowCount();
}
