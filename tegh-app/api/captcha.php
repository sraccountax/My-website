<?php
declare(strict_types=1);

/**
 * Tegh 4.4.5 authentication bot protection.
 * Cloudflare Turnstile is the only supported provider in this release.
 * The public site key may reach the browser; the secret key never may.
 */
function captcha_settings(): array
{
    $provider = strtolower(trim((string)(config('captcha.provider') ?? 'turnstile')));
    $siteKey = trim((string)(config('captcha.site_key') ?? ''));
    $secretKey = trim((string)(config('captcha.secret_key') ?? ''));
    $configured = $provider === 'turnstile' && $siteKey !== '' && $secretKey !== '';
    $enabledValue = config('captcha.enabled');
    // Fail safe: bot protection is enabled by default. If keys are not yet
    // configured, public registration is blocked rather than silently unprotected.
    $enabled = $enabledValue === null ? true : (bool)$enabledValue;
    $registrationRequired = config('captcha.registration_required');
    $registrationRequired = $registrationRequired === null ? true : (bool)$registrationRequired;
    $loginMode = strtolower(trim((string)(config('captcha.login_mode') ?? 'adaptive')));
    if (!in_array($loginMode, ['adaptive','always','off'], true)) $loginMode = 'adaptive';
    $threshold = max(1, min(5, (int)(config('captcha.login_failure_threshold') ?? 2)));
    $ipThreshold = max($threshold, min(25, (int)(config('captcha.login_ip_failure_threshold') ?? 8)));
    $emailThreshold = max($threshold, min(20, (int)(config('captcha.login_email_failure_threshold') ?? 6)));
    $hardPairLimit = max($threshold + 3, min(50, (int)(config('captcha.login_hard_pair_limit') ?? 12)));
    $hardIpFailureLimit = max($ipThreshold + 5, min(200, (int)(config('captcha.login_hard_ip_failure_limit') ?? 60)));
    $hardIpTotalLimit = max($hardIpFailureLimit, min(400, (int)(config('captcha.login_hard_ip_total_limit') ?? 150)));
    $allowedHostnames = config('captcha.allowed_hostnames');
    if (!is_array($allowedHostnames)) $allowedHostnames = [];
    $allowedHostnames = array_values(array_unique(array_filter(array_map(static fn($v): string => strtolower(rtrim(trim((string)$v), '.')), $allowedHostnames))));
    if (!$allowedHostnames) {
        $host = strtolower((string)(parse_url((string)(config('app.base_url') ?? ''), PHP_URL_HOST) ?: ''));
        if ($host !== '') $allowedHostnames[] = $host;
    }
    return [
        'provider'=>$provider,
        'siteKey'=>$siteKey,
        'secretKey'=>$secretKey,
        'configured'=>$configured,
        'enabled'=>$enabled,
        'registrationRequired'=>$registrationRequired,
        'loginMode'=>$loginMode,
        'loginFailureThreshold'=>$threshold,
        'loginIpFailureThreshold'=>$ipThreshold,
        'loginEmailFailureThreshold'=>$emailThreshold,
        'loginHardPairLimit'=>$hardPairLimit,
        'loginHardIpFailureLimit'=>$hardIpFailureLimit,
        'loginHardIpTotalLimit'=>$hardIpTotalLimit,
        'allowedHostnames'=>$allowedHostnames,
    ];
}

function captcha_public_profile(): array
{
    $cfg = captcha_settings();
    return [
        'enabled'=>(bool)$cfg['enabled'],
        'configured'=>(bool)$cfg['configured'],
        'provider'=>(string)$cfg['provider'],
        'siteKey'=>(bool)$cfg['enabled'] && (bool)$cfg['configured'] ? (string)$cfg['siteKey'] : '',
        'registrationRequired'=>(bool)$cfg['enabled'] && (bool)$cfg['registrationRequired'],
        'loginMode'=>(bool)$cfg['enabled'] ? (string)$cfg['loginMode'] : 'off',
        'loginFailureThreshold'=>(int)$cfg['loginFailureThreshold'],
    ];
}

function captcha_failed_login_counts(string $email): array
{
    if (!function_exists('schema_table_exists') || !schema_table_exists('login_attempts')) return ['pair'=>0,'ip'=>0,'email'=>0,'totalIp'=>0];
    $ipHash = client_ip_hash();
    $emailHash = secret_hash($email);
    $stmt = db()->prepare("SELECT
      SUM(CASE WHEN email_hash=? AND ip_hash=? AND successful=0 THEN 1 ELSE 0 END) pair_fail,
      SUM(CASE WHEN ip_hash=? AND successful=0 THEN 1 ELSE 0 END) ip_fail,
      SUM(CASE WHEN email_hash=? AND successful=0 THEN 1 ELSE 0 END) email_fail,
      SUM(CASE WHEN ip_hash=? THEN 1 ELSE 0 END) total_ip
      FROM login_attempts WHERE attempted_at>=UTC_TIMESTAMP()-INTERVAL 15 MINUTE");
    $stmt->execute([$emailHash,$ipHash,$ipHash,$emailHash,$ipHash]);
    $row = $stmt->fetch() ?: [];
    return [
        'pair'=>(int)($row['pair_fail'] ?? 0),
        'ip'=>(int)($row['ip_fail'] ?? 0),
        'email'=>(int)($row['email_fail'] ?? 0),
        'totalIp'=>(int)($row['total_ip'] ?? 0),
    ];
}

function captcha_login_policy_from_counts(array $counts, array $cfg): array
{
    $enabled = (bool)($cfg['enabled'] ?? true);
    $mode = (string)($cfg['loginMode'] ?? 'adaptive');
    $challenge = false;
    if ($enabled && $mode !== 'off') {
        $challenge = $mode === 'always'
            || (int)($counts['pair'] ?? 0) >= (int)($cfg['loginFailureThreshold'] ?? 2)
            || (int)($counts['ip'] ?? 0) >= (int)($cfg['loginIpFailureThreshold'] ?? 8)
            || (int)($counts['email'] ?? 0) >= (int)($cfg['loginEmailFailureThreshold'] ?? 6);
    }
    $hardLimited = (int)($counts['pair'] ?? 0) >= (int)($cfg['loginHardPairLimit'] ?? 12)
        || (int)($counts['ip'] ?? 0) >= (int)($cfg['loginHardIpFailureLimit'] ?? 60)
        || (int)($counts['totalIp'] ?? 0) >= (int)($cfg['loginHardIpTotalLimit'] ?? 150);
    return ['challenge'=>$challenge,'hardLimited'=>$hardLimited];
}

function captcha_login_required(string $email): bool
{
    $cfg = captcha_settings();
    $counts = captcha_failed_login_counts($email);
    return (bool)captcha_login_policy_from_counts($counts, $cfg)['challenge'];
}

function captcha_ip_challenge_now(): bool
{
    $cfg = captcha_settings();
    if (!(bool)$cfg['enabled'] || (string)$cfg['loginMode'] === 'off') return false;
    if ((string)$cfg['loginMode'] === 'always') return true;
    if (!function_exists('schema_table_exists') || !schema_table_exists('login_attempts')) return false;
    $stmt = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_hash=? AND successful=0 AND attempted_at>=UTC_TIMESTAMP()-INTERVAL 15 MINUTE");
    $stmt->execute([client_ip_hash()]);
    return (int)$stmt->fetchColumn() >= (int)$cfg['loginIpFailureThreshold'];
}

function captcha_http_siteverify(array $payload): array
{
    $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $encoded = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('CAPTCHA verification transport is unavailable.');
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$encoded,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>4,
            CURLOPT_TIMEOUT=>8,
            CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $status < 200 || $status >= 300) throw new RuntimeException('CAPTCHA verification provider did not answer safely.'.($error!==''?'':''));
    } else {
        $context = stream_context_create([
            'http'=>[
                'method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nConnection: close\r\n",
                'content'=>$encoded,'timeout'=>8,'ignore_errors'=>false,
            ],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body)) throw new RuntimeException('CAPTCHA verification transport is unavailable.');
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) throw new RuntimeException('CAPTCHA verification response was invalid.');
    return $decoded;
}

function captcha_validate_token(string $token, string $expectedAction): bool
{
    $cfg = captcha_settings();
    if (!(bool)$cfg['enabled']) return true;
    if (!(bool)$cfg['configured']) fail('Bot protection is not configured on this server. Please contact the Tegh administrator.',503,'captcha_configuration_required');
    $token = trim($token);
    if ($token === '' || strlen($token) > 2048) return false;
    $payload = ['secret'=>(string)$cfg['secretKey'],'response'=>$token];
    $remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteIp !== '') $payload['remoteip'] = $remoteIp;
    try { $result = captcha_http_siteverify($payload); }
    catch (Throwable $e) { fail('The security check could not be verified. Please try again.',503,'captcha_provider_unavailable',false); }
    if (empty($result['success'])) return false;
    $action = trim((string)($result['action'] ?? ''));
    if ($action === '' || !hash_equals($expectedAction, $action)) return false;
    $hostname = strtolower(rtrim(trim((string)($result['hostname'] ?? '')), '.'));
    $allowed = (array)$cfg['allowedHostnames'];
    if ($allowed && ($hostname === '' || !in_array($hostname, $allowed, true))) return false;
    return true;
}

function captcha_require(array $input, string $action): void
{
    $cfg = captcha_settings();
    if (!(bool)$cfg['enabled']) return;
    if (!(bool)$cfg['configured']) fail('Bot protection is not configured on this server. Please contact the Tegh administrator.',503,'captcha_configuration_required');
    $token = trim((string)($input['captchaToken'] ?? ''));
    if ($token === '') fail('Complete the security check and try again.',403,'captcha_required',false);
    if (!captcha_validate_token($token, $action)) fail('The security check could not be verified. Please try again.',403,'captcha_failed',false);
}

function handle_auth_security(): never
{
    require_method('GET');
    $profile = captcha_public_profile();
    $profile['publicSignupEnabled'] = tegh_effective_public_registration();
    $profile['registrationMode'] = $profile['publicSignupEnabled'] ? 'Public account creation enabled' : 'Invitation only';
    header('Cache-Control: no-store');
    $profile['loginChallengeNow'] = false;
    if ($profile['enabled'] && $profile['configured'] && $profile['loginMode'] !== 'off') {
        try { $profile['loginChallengeNow'] = captcha_ip_challenge_now(); } catch (Throwable) { $profile['loginChallengeNow'] = false; }
    }
    json_response($profile);
}
