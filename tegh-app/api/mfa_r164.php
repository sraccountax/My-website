<?php
declare(strict_types=1);

/*
 * R164: multi-factor authentication (TOTP) for privileged accounts.
 *
 * Scope and policy
 *  - MFA is REQUIRED for the platform owner (administrator) and for any user who holds the
 *    `owner` or `admin` role on at least one company (the people who can post, void, approve
 *    or manage users — the "accountant" accounts). Pure viewer/editor-only and client-view
 *    users are not forced to enrol.
 *  - Enrolment is mandatory and hard-gated: a required user is signed in with an
 *    unverified session and can reach only the MFA routes until a factor is confirmed.
 *
 * Design
 *  - Time-based one-time passwords (RFC 6238): HMAC-SHA1, 30-second step, 6 digits, a +/-1
 *    step window, implemented in pure PHP (no external service, no outside AI).
 *  - The shared secret is stored encrypted at rest with AES-256-GCM. The key is derived from
 *    app.secret by HKDF, so the secret is useless without the server key and is tied to the
 *    same key that already protects sessions and reset tokens. app.secret must therefore not
 *    be rotated without re-enrolling MFA (documented in the operator checklist).
 *  - One-time recovery codes are generated at enrolment, shown once, and stored only as
 *    HMAC-SHA256 hashes (secret_hash), compared in constant time, single-use.
 *  - Login becomes two-step for enrolled users: a correct password creates an *unverified*
 *    session (mfa_verified = 0); the second step verifies a TOTP or recovery code and marks
 *    the session verified. The main API router blocks unverified sessions from every route
 *    except the MFA endpoints, logout and the identity probe.
 *
 * All schema changes are additive and made on first use, outside any transaction, matching
 * the pattern used by the other R1xx modules.
 */

const TEGH_MFA_DIGITS = 6;
const TEGH_MFA_STEP_SECONDS = 30;
const TEGH_MFA_WINDOW = 1;            // accept the current step and one either side
const TEGH_MFA_MAX_FAILURES = 5;      // before a temporary lock
const TEGH_MFA_LOCK_MINUTES = 15;
const TEGH_MFA_RECOVERY_CODES = 10;
const TEGH_MFA_CHALLENGE_MINUTES = 10;

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
        if (schema_table_exists('sessions') && !schema_column_exists('sessions', 'mfa_verified')) {
            // Existing sessions predate MFA; default them verified so they are not locked out
            // mid-session. New sessions set the value explicitly at creation.
            schema_add_column('sessions', 'mfa_verified', "TINYINT(1) NOT NULL DEFAULT 1");
        }
        $ready = schema_table_exists('user_mfa') && schema_table_exists('user_mfa_recovery_codes')
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

/** True when this user must have MFA: platform owner, or owner/admin on any company. */
function mfa_required_for_user(string $userId): bool
{
    if (schema_column_exists('users', 'platform_role')) {
        $stmt = db()->prepare("SELECT 1 FROM users WHERE id=? AND platform_role='platform_owner' LIMIT 1");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() !== false) return true;
    }
    if (schema_table_exists('company_members')) {
        $statusClause = schema_column_exists('company_members', 'status') ? " AND status='active'" : '';
        $stmt = db()->prepare("SELECT 1 FROM company_members WHERE user_id=? AND role IN ('owner','admin')$statusClause LIMIT 1");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() !== false) return true;
    }
    return false;
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
    $allowed = in_array($route, ['auth/me', 'auth/logout', 'auth/mfa/verify', 'auth/mfa/enroll-begin',
        'auth/mfa/enroll-confirm', 'auth/mfa/status'], true);
    if ($allowed) return;
    $userId = (string)$session['user_id'];
    if (mfa_is_enrolled($userId)) {
        fail('Enter your authentication code to continue.', 403, 'mfa_verification_required', false);
    }
    fail('Set up two-step verification to continue.', 403, 'mfa_enrollment_required', false);
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

    // Confirming enrolment with a valid code also clears the second factor for this session.
    mfa_mark_session_verified((string)$user['sessionId']);
    mfa_audit($user, '', 'mfa.enabled', 'user', (string)$user['id'], ['method' => 'totp', 'recoveryCodesIssued' => TEGH_MFA_RECOVERY_CODES]);
    $payload = auth_payload($user);
    $payload['mfa']['verified'] = true;
    $payload['enabled'] = true;
    $payload['recoveryCodes'] = $result['recoveryCodes'];
    $payload['message'] = 'Two-step verification is on. Save these one-time recovery codes somewhere safe.';
    json_response($payload);
}

/** Second login step: verify a TOTP or recovery code and mark the session verified. */
function handle_mfa_verify(): never
{
    require_method('POST');
    require_csrf();
    if (!mfa_schema_ready()) fail('Two-step verification is not available on this installation.', 503, 'mfa_unavailable', false);
    $user = require_user();
    $input = request_json();
    $code = trim((string)($input['code'] ?? ''));
    $isRecovery = !empty($input['recovery']);

    $state = mfa_state((string)$user['id']);
    if (!$state['confirmed']) fail('Two-step verification is not set up for this account.', 409, 'mfa_not_enrolled');
    if ($state['locked']) fail('Too many incorrect codes. Try again later or use a recovery code.', 429, 'mfa_locked', false);

    $ok = false;
    if ($isRecovery) {
        $ok = mfa_consume_recovery_code((string)$user['id'], $code);
    } else {
        $row = $state['row'];
        $secret = mfa_decrypt_secret((string)$row['secret_cipher'], (string)$row['secret_nonce'], (string)$row['secret_tag']);
        $step = mfa_totp_match($secret, $code, $row['last_used_step'] !== null ? (int)$row['last_used_step'] : null);
        if ($step !== null) {
            db()->prepare('UPDATE user_mfa SET last_used_step=? WHERE user_id=?')->execute([$step, (string)$user['id']]);
            $ok = true;
        }
    }

    if (!$ok) {
        mfa_register_failure((string)$user['id']);
        mfa_audit($user, '', 'mfa.verify_failed', 'user', (string)$user['id'], ['method' => $isRecovery ? 'recovery' : 'totp']);
        fail('That code is not correct.', 422, 'mfa_code_invalid');
    }
    mfa_clear_failures((string)$user['id']);
    mfa_mark_session_verified((string)$user['sessionId']);
    // A recovery code usually means a lost or exposed device: end the account's other sessions.
    if ($isRecovery) tegh_end_user_sessions((string)$user['id'], (string)$user['sessionId']);
    mfa_audit($user, '', 'mfa.verified', 'user', (string)$user['id'], ['method' => $isRecovery ? 'recovery' : 'totp']);
    // Hand back the full identity so the client can enter the app. session_record() is memoized
    // within this request and still reflects the pre-verification row, so set verified explicitly.
    $payload = auth_payload($user);
    $payload['mfa']['verified'] = true;
    $payload['verified'] = true;
    $payload['recoveryCodeUsed'] = $isRecovery;
    $payload['recoveryCodesRemaining'] = mfa_recovery_remaining((string)$user['id']);
    json_response($payload);
}

/** Turn MFA off (step-up by password). Only allowed when the account no longer requires it. */
function handle_mfa_disable(): never
{
    require_method('POST');
    require_csrf();
    if (!mfa_schema_ready()) fail('Two-step verification is not available on this installation.', 503, 'mfa_unavailable', false);
    $user = require_user();
    $input = request_json();
    mfa_require_password($user, (string)($input['password'] ?? ''));
    if (mfa_required_for_user((string)$user['id'])) {
        fail('This account must keep two-step verification on. Ask the administrator to change the account role first.', 409, 'mfa_required_for_role');
    }
    db()->prepare('DELETE FROM user_mfa WHERE user_id=?')->execute([(string)$user['id']]);
    db()->prepare('DELETE FROM user_mfa_recovery_codes WHERE user_id=?')->execute([(string)$user['id']]);
    tegh_end_user_sessions((string)$user['id'], (string)$user['sessionId']); // R164: a weaker sign-in method ends other sessions
    mfa_audit($user, '', 'mfa.disabled', 'user', (string)$user['id'], []);
    json_response(['enabled' => false, 'message' => 'Two-step verification is off for this account.']);
}

/** Regenerate recovery codes (step-up by password); invalidates the old set. */
function handle_mfa_recovery_regenerate(): never
{
    require_method('POST');
    require_csrf();
    if (!mfa_schema_ready()) fail('Two-step verification is not available on this installation.', 503, 'mfa_unavailable', false);
    $user = require_user();
    $input = request_json();
    mfa_require_password($user, (string)($input['password'] ?? ''));
    if (!mfa_is_enrolled((string)$user['id'])) fail('Two-step verification is not set up for this account.', 409, 'mfa_not_enrolled');
    $codes = mfa_issue_recovery_codes((string)$user['id']);
    mfa_audit($user, '', 'mfa.recovery_codes_regenerated', 'user', (string)$user['id'], ['count' => TEGH_MFA_RECOVERY_CODES]);
    json_response(['recoveryCodes' => $codes, 'message' => 'New recovery codes generated. The previous codes no longer work.']);
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
