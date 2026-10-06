<?php
declare(strict_types=1);


function public_signup_enabled(): bool
{
    return tegh_effective_public_registration();
}

function platform_role_for_user(string $userId): string
{
    if (!schema_column_exists('users', 'platform_role')) return 'member';
    $stmt = db()->prepare('SELECT platform_role FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    return (string)($stmt->fetchColumn() ?: 'member');
}

function handle_register(): never
{
    require_method('POST');
    assert_same_origin();
    if (!public_signup_enabled()) fail('New account registration is currently closed.', 403, 'registration_closed');
    if (!schema_column_exists('users','platform_role') || !schema_table_exists('registration_attempts')) {
        fail('The Tegh database must be upgraded by the platform owner before public registration can begin.', 503, 'upgrade_required');
    }
    $input = request_json();
    $email = safe_email($input['email'] ?? '');
    $displayName = clean_text($input['displayName'] ?? '', 'Name', 160);
    $ipHash = client_ip_hash();
    $emailHash = secret_hash($email);
    $stmt = db()->prepare("SELECT COUNT(*) FROM registration_attempts WHERE ip_hash=? AND attempted_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR");
    $stmt->execute([$ipHash]);
    if ((int)$stmt->fetchColumn() >= 10) fail('Too many account requests from this connection. Try again later.', 429, 'registration_rate_limited');
    $stmt = db()->prepare("SELECT COUNT(*) FROM registration_attempts WHERE email_hash=? AND attempted_at>=UTC_TIMESTAMP()-INTERVAL 1 DAY");
    $stmt->execute([$emailHash]);
    if ((int)$stmt->fetchColumn() >= 5) fail('Too many account requests for this email. Try again tomorrow or sign in.', 429, 'registration_email_limited');
    db()->prepare('INSERT INTO registration_attempts (email_hash,ip_hash,successful) VALUES (?,?,0)')->execute([$emailHash,$ipHash]);
    $attemptId=(int)db()->lastInsertId();

    // Public registration is always CAPTCHA-protected when bot protection is enabled.
    // Verify before checking whether the email already exists so automated callers
    // cannot use the registration endpoint as an account-enumeration oracle cheaply.
    if (trim((string)($input['website'] ?? '')) !== '') fail('The security check could not be verified. Please try again.',403,'captcha_failed',false);
    $cfg = captcha_settings();
    if ((bool)$cfg['enabled'] && (bool)$cfg['registrationRequired']) captcha_require($input, 'register');

    $password = (string)($input['password'] ?? '');
    validate_new_password($password);
    if (empty($input['acceptTerms'])) fail('Accept the terms of use and privacy notice to continue.', 422, 'terms_required');
    $stmt = db()->prepare('SELECT 1 FROM users WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) fail('An account already exists for this email. Sign in instead.', 409, 'account_exists');
    $hash = password_hash($password, password_algorithm());
    if (!is_string($hash)) throw new RuntimeException('Password hashing is unavailable.');
    $userId = new_id('user');
    db()->beginTransaction();
    try {
        db()->prepare("INSERT INTO users (id,email,display_name,password_hash,platform_role,account_plan,signup_source,terms_accepted_at) VALUES (?,?,?,?,'member','free_preview','public_signup',UTC_TIMESTAMP())")
            ->execute([$userId,$email,$displayName,$hash]);
        // Legacy featureKeys are deliberately ignored; signup creates no feature intents.
        tegh_record_terms_acceptance($userId, null);
        db()->prepare('UPDATE registration_attempts SET successful=1 WHERE id=?')->execute([$attemptId]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        if($error instanceof InvalidArgumentException)fail($error->getMessage(),422,'signup_feature_selection_invalid',false);
        throw $error;
    }
    $session = create_session($userId);
    $user = ['id'=>$userId,'email'=>$email,'displayName'=>$displayName];
    json_response(auth_payload($user,$session),201);
}

function companies_for_user(string $userId): array
{
    static $companyColumns = null;
    if ($companyColumns === null) {
        $columnStmt = db()->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='companies'");
        $companyColumns = array_fill_keys(array_map('strval', $columnStmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }
    $column = static function(string $name,string $fallback,string $alias) use($companyColumns): string {
        return isset($companyColumns[$name]) ? "c.`$name` AS `$alias`" : "$fallback AS `$alias`";
    };
    $select = [
        'c.id','c.name','c.legal_name','c.business_type','c.province','c.currency',
        $column('accounting_basis',"'accrual'",'accounting_basis'),$column('module_mode',"'both'",'module_mode'),
        $column('payroll_posting_mode',"'draft'",'payroll_posting_mode'),$column('tax_reporting_profile',"'none'",'tax_reporting_profile'),$column('reporting_framework',"'not_set'",'reporting_framework'),
        'c.fiscal_year_end',$column('fiscal_year_end_date','NULL','fiscal_year_end_date'),
        $column('books_start_date','NULL','books_start_date'),'c.tax_registered','c.tax_number','c.tax_rate_bps',
        $column('test_mode','0','test_mode'),$column('test_expires_at','NULL','test_expires_at'),$column('country',"'Canada'",'country'),
        // R153: address and short name.
        $column('short_name','NULL','short_name'),$column('address_line1','NULL','address_line1'),$column('address_line2','NULL','address_line2'),$column('city','NULL','city'),
        $column('postal_code','NULL','postal_code'),$column('phone','NULL','phone'),$column('contact_email','NULL','contact_email'),
    ];
    // Platform administration and company-book access are separate trust
    // boundaries. Even a platform owner only sees companies where they are an
    // explicit company member in the normal company switcher. A temporary
    // support grant is checked by require_company() when support is opened; it
    // never turns the normal switcher into a global tenant directory.
    $sql='SELECT '.implode(', ',$select).',cm.role AS role,0 AS platform_master_access
        FROM companies c
        JOIN company_members cm ON cm.company_id=c.id
        WHERE cm.user_id=? AND c.active=1'.(schema_column_exists('company_members','status')?" AND cm.status='active'":'');
    $params=[$userId];
    $sql.=' ORDER BY name';
    $stmt=db()->prepare($sql);$stmt->execute($params);
    return array_map(static fn(array $row):array=>[
        'id'=>(string)$row['id'],'name'=>(string)$row['name'],'legalName'=>(string)$row['legal_name'],
        'businessType'=>(string)$row['business_type'],'province'=>(string)$row['province'],'country'=>(string)($row['country']??'Canada'),'currency'=>(string)$row['currency'],
        'accountingBasis'=>(string)$row['accounting_basis'],'moduleMode'=>(string)$row['module_mode'],
        'payrollPostingMode'=>(string)$row['payroll_posting_mode'],'taxReportingProfile'=>(string)$row['tax_reporting_profile'],'reportingFramework'=>(string)$row['reporting_framework'],
        'fiscalYearEnd'=>(string)$row['fiscal_year_end'],
        'fiscalYearEndDate'=>$row['fiscal_year_end_date']!==null?(string)$row['fiscal_year_end_date']:null,
        'booksStartDate'=>$row['books_start_date']!==null?(string)$row['books_start_date']:null,
        'taxRegistered'=>(bool)$row['tax_registered'],
        'taxNumber'=>$row['tax_number']!==null?(string)$row['tax_number']:null,'taxRateBps'=>(int)$row['tax_rate_bps'],
        'isTestMode'=>(bool)$row['test_mode'],'testExpiresAt'=>$row['test_expires_at']!==null?(string)$row['test_expires_at'].'Z':null,
        'role'=>(string)$row['role'],
        'roleLabel'=>company_role_label((string)$row['role']),
        'permissions'=>company_role_permissions((string)$row['role']),
        'platformMasterAccess'=>!empty($row['platform_master_access']),
    ]+(function_exists('r153_profile_public')?r153_profile_public($row):[]),$stmt->fetchAll());
}

function auth_payload(array $user, ?array $session = null): array
{
    $csrf = $session['csrfToken'] ?? csrf_cookie_value();
    return [
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'displayName' => $user['displayName'],
            'platformRole' => platform_role_for_user((string)$user['id']),
            'accountPlan' => 'standard',
        ],
        'csrfToken' => $csrf,
        'sessionExpiresAt' => $session['expiresAt'] ?? null,
        'companies' => companies_for_user((string)$user['id']),
        'version' => SR_ACCOUNTAX_VERSION,
        'build' => SR_ACCOUNTAX_BUILD,
        'schemaVersion' => SR_ACCOUNTAX_SCHEMA_VERSION,
        'aiConfigured' => false, // Release is native-only; do not expose provider configuration.
        'publicSignupEnabled' => public_signup_enabled(),
        'freePreview' => false,
    ];
}

function handle_setup(): never
{
    require_method('POST');
    assert_same_origin();
    $input = request_json();
    $providedKey = (string)($input['setupKey'] ?? '');
    $expectedKey = (string)(config('app.setup_key') ?? '');
    if (strlen($expectedKey) < 24 || str_contains($expectedKey, 'REPLACE_WITH')) {
        fail('Set a strong secure setup key before continuing.', 503, 'setup_key_missing');
    }
    if (!hash_equals($expectedKey, $providedKey)) {
        usleep(500000);
        fail('The setup key is invalid.', 403, 'setup_key_invalid');
    }
    initialize_schema();
    if (!setup_required()) {
        fail('Initial setup is already complete.', 409, 'setup_locked');
    }
    $email = safe_email($input['email'] ?? '');
    $displayName = clean_text($input['displayName'] ?? '', 'Owner name', 160);
    $password = (string)($input['password'] ?? '');
    validate_new_password($password);
    $userId = new_id('user');
    $hash = password_hash($password, password_algorithm());
    if (!is_string($hash)) {
        throw new RuntimeException('Password hashing is unavailable.');
    }
    $stmt = db()->prepare("INSERT INTO users (id,email,display_name,password_hash,platform_role,account_plan,signup_source,terms_accepted_at) VALUES (?,?,?,?,'platform_owner','free_preview','initial_setup',UTC_TIMESTAMP())");
    $stmt->execute([$userId,$email,$displayName,$hash]);
    $session = create_session($userId);
    $user = ['id' => $userId, 'email' => $email, 'displayName' => $displayName];
    json_response(auth_payload($user, $session), 201);
}

function record_login_attempt(string $email, bool $successful): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts (email_hash, ip_hash, successful) VALUES (?, ?, ?)');
    $stmt->execute([secret_hash($email), client_ip_hash(), $successful ? 1 : 0]);
}

function assert_login_rate_limit(string $email): void
{
    $counts = captcha_failed_login_counts($email);
    $cfg = captcha_settings();
    $policy = captcha_login_policy_from_counts($counts, $cfg);
    if ((bool)$policy['hardLimited']) {
        fail('Too many sign-in attempts. Wait 15 minutes and try again.', 429, 'login_rate_limited', false);
    }
}

function clear_login_failures_after_success(string $email): void
{
    if (!function_exists('schema_table_exists') || !schema_table_exists('login_attempts')) return;
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE email_hash=? AND ip_hash=? AND successful=0');
    $stmt->execute([secret_hash($email), client_ip_hash()]);
}

function handle_login(): never
{
    require_method('POST');
    assert_same_origin();
    if (setup_required()) {
        fail('Initial owner setup is required.', 428, 'setup_required');
    }
    $input = request_json();
    $email = safe_email($input['email'] ?? '');
    $password = (string)($input['password'] ?? '');

    // Adaptive CAPTCHA is the intermediate control between ordinary password
    // mistakes and a hard lockout. Missing/failed CAPTCHA submissions are not
    // password attempts and therefore must never accelerate the credential
    // failure counter.
    if (captcha_login_required($email)) {
        $cfg = captcha_settings();
        if (!(bool)$cfg['configured']) {
            fail('Security verification is required, but Turnstile is not configured on this Tegh server. Ask the administrator to add the Turnstile site and secret keys.',503,'captcha_configuration_required',false);
        }
        $captchaToken = trim((string)($input['captchaToken'] ?? ''));
        if ($captchaToken === '') {
            fail('Complete the security check and try again.',403,'captcha_required',false);
        }
        if (!captcha_validate_token($captchaToken, 'login')) {
            fail('The security check could not be verified. Please try again.',403,'captcha_failed',false);
        }
    }

    // Hard lockout is reserved for sustained abuse after the adaptive CAPTCHA
    // stage has already been reached.
    assert_login_rate_limit($email);
    $stmt = db()->prepare('SELECT id, email, display_name, password_hash FROM users WHERE email = ? AND active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    $valid = $row && password_verify($password, (string)$row['password_hash']);
    record_login_attempt($email, (bool)$valid);
    if (!$valid) {
        usleep(600000);
        fail('Email or password is incorrect.', 401, 'invalid_credentials');
    }
    clear_login_failures_after_success($email);
    if (password_needs_rehash((string)$row['password_hash'], password_algorithm())) {
        $newHash = password_hash($password, password_algorithm());
        if (is_string($newHash)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $row['id']]);
        }
    }
    db()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['id']]);
    db()->prepare('DELETE FROM sessions WHERE user_id = ? AND expires_at <= UTC_TIMESTAMP()')->execute([$row['id']]);
    $session = create_session((string)$row['id']);
    $user = ['id' => (string)$row['id'], 'email' => (string)$row['email'], 'displayName' => (string)$row['display_name']];
    json_response(auth_payload($user, $session));
}

function handle_me(): never
{
    require_method('GET');
    if (setup_required()) {
        fail('Initial owner setup is required.', 428, 'setup_required');
    }
    $user = require_user();
    $csrf = csrf_cookie_value();
    $session = session_record();
    if ($csrf === '' || !$session || !hash_equals((string)$session['csrf_hash'], secret_hash($csrf))) {
        db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$user['sessionId']]);
        clear_session_cookie();
        fail('Your session security token expired. Sign in again.', 401, 'session_expired');
    }
    json_response(auth_payload($user));
}

function handle_client_incident(): never
{
    require_method('POST');
    $user = require_user();
    require_csrf();
    $input = request_json();
    if (schema_table_exists('platform_incident_log')) {
        $rate = db()->prepare("SELECT COUNT(*) FROM platform_incident_log WHERE user_id=? AND source='client_error' AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR");
        $rate->execute([$user['id']]);
        if ((int)$rate->fetchColumn() >= 120) {
            fail('Browser diagnostics are temporarily rate limited.', 429, 'client_incident_rate_limited', false);
        }
    }
    $kind = preg_replace('/[^a-z0-9_-]/i', '', (string)($input['kind'] ?? 'client_error')) ?: 'client_error';
    $kind = mb_substr($kind, 0, 40);
    $route = system_incident_redact_text((string)($input['route'] ?? 'frontend'), 180);
    $status = max(0, min(599, (int)($input['status'] ?? 0)));
    $code = preg_replace('/[^a-z0-9_.:-]/i', '', (string)($input['code'] ?? 'client_error')) ?: 'client_error';
    $message = system_incident_redact_text((string)($input['message'] ?? 'A browser-side error occurred.'), 3000);
    $stack = system_incident_redact_text((string)($input['stack'] ?? ''), 30000);
    $pagePath = system_incident_redact_text((string)($input['pagePath'] ?? ''), 500);
    $clientRequestId = system_incident_redact_text((string)($input['requestId'] ?? ''), 80);
    record_system_incident('A browser-side application error was recorded.', $status, mb_substr($code,0,120), null, [
        'source'=>'client_error',
        'severity'=>in_array($kind,['window_error','unhandled_rejection','frontend_module_failed'],true)?'error':'warning',
        'route'=>$route,
        'requestId'=>$clientRequestId !== '' ? $clientRequestId : request_id(),
        'internalMessage'=>$message,
        'stackTrace'=>$stack,
        'kind'=>$kind,
        'pagePath'=>$pagePath,
        'clientVersion'=>system_incident_redact_text((string)($input['version'] ?? ''),40),
    ]);
    json_response(['recorded'=>true], 201);
}

function handle_logout(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$user['sessionId']]);
    clear_session_cookie();
    json_response(['ok' => true]);
}

function handle_update_profile(): never
{
    require_method('PUT');
    require_csrf();
    $user = require_user();
    $input = request_json();
    $displayName = clean_text($input['displayName'] ?? '', 'Display name', 160);
    db()->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$displayName, $user['id']]);
    json_response([
        'user' => [
            'id' => (string)$user['id'],
            'email' => (string)$user['email'],
            'displayName' => $displayName,
        ],
    ]);
}

function handle_change_password(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $input = request_json();
    $current = (string)($input['currentPassword'] ?? '');
    $next = (string)($input['newPassword'] ?? '');
    validate_new_password($next);
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $hash = (string)$stmt->fetchColumn();
    if (!password_verify($current, $hash)) {
        fail('The current password is incorrect.', 403, 'password_incorrect');
    }
    $newHash = password_hash($next, password_algorithm());
    if (!is_string($newHash)) {
        throw new RuntimeException('Password hashing is unavailable.');
    }
    db()->beginTransaction();
    try {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $user['id']]);
        db()->prepare('DELETE FROM sessions WHERE user_id = ? AND id <> ?')->execute([$user['id'], $user['sessionId']]);
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
    json_response(['ok' => true]);
}

/**
 * Self-service password reset. The raw reset token is sent only by email;
 * Tegh stores an HMAC hash and never writes the token into audit/incident data.
 */
function password_reset_require_schema(): void
{
    if (!schema_table_exists('password_reset_requests')) {
        fail('Password reset is temporarily unavailable until the Tegh database upgrade is completed.', 503, 'upgrade_required');
    }
}

function password_reset_url(string $token): string
{
    return rtrim((string)config('app.base_url'), '/') . '/app.html#passwordReset=' . rawurlencode($token);
}

function send_password_reset_email(string $email, string $token): void
{
    $url = password_reset_url($token);
    $subject = 'Reset your Tegh password';
    $text = "A password reset was requested for your Tegh account.\n\nReset your password:\n$url\n\nThis link expires in 60 minutes and works once. If you did not request a password reset, you can ignore this email.";
    $html = '<!doctype html><html><body style="margin:0;background:#f2f6f7;color:#18333b"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f6f7"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #d9e5e7;border-radius:16px"><tr><td style="padding:32px;font-family:Arial,sans-serif"><div style="font-size:13px;font-weight:700;letter-spacing:.08em;color:#0d6670">TEGH</div><h1 style="font-size:26px;line-height:1.25;margin:14px 0;color:#173740">Reset your Tegh password</h1><p style="font-size:16px;line-height:1.6;color:#455e65">A password reset was requested for your Tegh account.</p><p style="margin:28px 0"><a style="display:inline-block;padding:13px 20px;background:#0d6670;color:#ffffff;text-decoration:none;border-radius:9px;font-weight:700" href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">Reset Password</a></p><p style="font-size:14px;line-height:1.55;color:#5f747a">This link expires in 60 minutes and works once.</p><hr style="border:0;border-top:1px solid #e2ebed;margin:26px 0"><p style="font-size:12px;line-height:1.5;color:#768a90">If you did not request a password reset, you can safely ignore this email.</p></td></tr></table></td></tr></table></body></html>';
    sr_mail_send(null, null, $email, 'password_reset', $subject, $text, $html);
}

function handle_password_reset_request(): never
{
    require_method('POST');
    assert_same_origin();
    password_reset_require_schema();
    $input = request_json();
    $email = safe_email($input['email'] ?? '');
    $emailHash = secret_hash($email);
    $ipHash = client_ip_hash();

    // Clean old attempts without retaining a long-lived reset-request history.
    db()->exec("DELETE FROM password_reset_requests WHERE created_at < UTC_TIMESTAMP() - INTERVAL 2 DAY");
    $rate = db()->prepare("SELECT COUNT(*) FROM password_reset_requests WHERE requested_ip_hash=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR");
    $rate->execute([$ipHash]);
    if ((int)$rate->fetchColumn() >= 15) fail('Too many password reset requests. Try again later.', 429, 'password_reset_rate_limited');
    $rate = db()->prepare("SELECT COUNT(*) FROM password_reset_requests WHERE email_hash=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR");
    $rate->execute([$emailHash]);
    if ((int)$rate->fetchColumn() >= 5) fail('Too many password reset requests. Try again later.', 429, 'password_reset_rate_limited');

    $userStmt = db()->prepare('SELECT id,email FROM users WHERE email=? AND active=1 LIMIT 1');
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();
    $token = base64url_encode(random_bytes(32));
    $requestId = new_id('reset');
    $userId = $user ? (string)$user['id'] : null;

    db()->beginTransaction();
    try {
        // Only the newest request for a real account remains usable.
        if ($userId !== null) {
            db()->prepare('UPDATE password_reset_requests SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
        }
        db()->prepare("INSERT INTO password_reset_requests (id,user_id,email_hash,token_hash,requested_ip_hash,expires_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP()+INTERVAL 60 MINUTE)")
            ->execute([$requestId,$userId,$emailHash,secret_hash($token),$ipHash]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }

    // Deliberately return the same response whether or not the address exists.
    if ($user) send_password_reset_email((string)$user['email'], $token);
    json_response(['ok'=>true,'message'=>'If an active Tegh account exists for that email, a password reset link has been sent.']);
}

function handle_password_reset_details(): never
{
    require_method('GET','POST');
    password_reset_require_schema();
    // R141: the browser sends the token in a POST body so it never appears in access logs.
    if (request_method() === 'POST') assert_same_origin();
    $token = trim((string)(request_method() === 'POST' ? (request_json()['token'] ?? '') : ($_GET['token'] ?? '')));
    if (!preg_match('/^[A-Za-z0-9_-]{40,100}$/', $token)) fail('This password reset link is invalid or expired.',410,'password_reset_invalid');
    $stmt = db()->prepare("SELECT pr.id FROM password_reset_requests pr JOIN users u ON u.id=pr.user_id AND u.active=1 WHERE pr.token_hash=? AND pr.used_at IS NULL AND pr.expires_at>UTC_TIMESTAMP() LIMIT 1");
    $stmt->execute([secret_hash($token)]);
    if (!$stmt->fetchColumn()) fail('This password reset link is invalid or expired.',410,'password_reset_invalid');
    json_response(['valid'=>true,'expiresWithinMinutes'=>60]);
}

function handle_password_reset_complete(): never
{
    require_method('POST');
    assert_same_origin();
    password_reset_require_schema();
    $input = request_json();
    $token = trim((string)($input['token'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{40,100}$/', $token)) fail('This password reset link is invalid or expired.',410,'password_reset_invalid');
    $password = (string)($input['newPassword'] ?? '');
    validate_new_password($password);
    $newHash = password_hash($password, password_algorithm());
    if (!is_string($newHash)) throw new RuntimeException('Password hashing is unavailable.');

    $resetUser = null;
    db()->beginTransaction();
    try {
        $stmt = db()->prepare("SELECT pr.id,pr.user_id,u.email,u.display_name FROM password_reset_requests pr JOIN users u ON u.id=pr.user_id AND u.active=1 WHERE pr.token_hash=? AND pr.used_at IS NULL AND pr.expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE");
        $stmt->execute([secret_hash($token)]);
        $row = $stmt->fetch();
        if (!$row) {
            db()->rollBack();
            fail('This password reset link is invalid or expired.',410,'password_reset_invalid');
        }
        $userId = (string)$row['user_id'];
        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$newHash,$userId]);
        db()->prepare('UPDATE password_reset_requests SET used_at=UTC_TIMESTAMP() WHERE id=?')->execute([(string)$row['id']]);
        db()->prepare('UPDATE password_reset_requests SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
        // A credential reset invalidates every browser session, including a
        // potentially stolen one. The user signs in again with the new secret.
        db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$userId]);
        db()->commit();
        $resetUser=['id'=>$userId,'email'=>(string)$row['email'],'displayName'=>(string)$row['display_name']];
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
    if ($resetUser && function_exists('platform_audit_event')) {
        platform_audit_event($resetUser,'auth.password_reset_completed','user',(string)$resetUser['id'],['selfService'=>true,'sessionsRevoked'=>true]);
    }
    clear_session_cookie();
    json_response(['ok'=>true,'message'=>'Your password has been reset. Sign in again with your new password.']);
}
