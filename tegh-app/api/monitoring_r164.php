<?php
declare(strict_types=1);

/*
 * R164 operator monitoring.
 *
 * Sends short alert emails to the operator for: a failed or missing daily backup, serious server errors and
 * suspicious sign-in activity. Alerts carry only kinds, counts, times and request references: never passwords,
 * tokens, email addresses of the people involved, company names or accounting figures.
 *
 * Flood prevention: one email per alert kind per throttle window (default 60 minutes). Occurrences inside the
 * window are counted and reported in the next email for that kind.
 *
 * The checks run from cron (api/monitor-cron.php, every 15-60 minutes) and, as a fallback when cron is not set
 * up, at most once every 15 minutes at the end of an ordinary request (after the response was sent).
 */

const TEGH_MONITOR_KINDS = [
    'backup_failed'   => 'The last backup failed',
    'backup_missing'  => 'No successful backup recently',
    'server_error'    => 'Serious server errors',
    'auth_suspicious' => 'Suspicious sign-in activity',
    'security_config' => 'Security settings changed',
    'deleted_reappeared' => 'Deleted logins or companies are present again',
    'login_email_failed' => 'Sign-in codes could not be emailed',
];

// Settings whose change is security relevant. Only the names of changed settings are recorded and sent, never values;
// values are compared as keyed hashes so secrets are never stored in the monitoring state.
const TEGH_MONITOR_SECURITY_SETTINGS = [
    'app.secret', 'app.base_url', 'app.session_hours', 'app.public_signup_enabled', 'app.maintenance_mode',
    'captcha.enabled', 'captcha.registration_required', 'captcha.login_mode', 'captcha.site_key', 'captcha.secret_key',
    'security.trusted_proxy', 'security.trusted_proxy_ranges', 'storage_path', 'incident_log_path',
    'mail.from_email', 'mail.smtp_host', 'monitoring.alert_email', 'db.dsn', 'db.user', 'db.password',
];

function tegh_monitor_setting(string $key, mixed $default): mixed
{
    $value = config('monitoring.' . $key);
    return $value === null || $value === '' ? $default : $value;
}

function tegh_monitor_recipient(): string
{
    foreach ([config('monitoring.alert_email'), config('operator.support_email')] as $candidate) {
        $candidate = trim((string)($candidate ?? ''));
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) return $candidate;
    }
    return '';
}

function tegh_monitor_runtime_dir(): ?string
{
    $root = trim((string)(config('storage_path') ?? ''));
    if ($root === '') return null;
    $dir = rtrim($root, '/') . '/runtime';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
    return $dir;
}

/** Read-modify-write the alert state under an exclusive lock. */
function tegh_monitor_state(callable $change): mixed
{
    $dir = tegh_monitor_runtime_dir();
    if ($dir === null) return null;
    $handle = @fopen($dir . '/monitor-state.json', 'c+');
    if ($handle === false) return null;
    try {
        if (!flock($handle, LOCK_EX)) return null;
        $raw = stream_get_contents($handle);
        $state = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
        if (!is_array($state)) $state = [];
        $result = $change($state);
        ftruncate($handle, 0); rewind($handle);
        fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES));
        fflush($handle);
        @chmod($dir . '/monitor-state.json', 0600);
        return $result;
    } finally {
        flock($handle, LOCK_UN); fclose($handle);
    }
}

/**
 * Note one occurrence of an alert condition. Returns the email to send now (or null when throttled).
 * $facts must contain only non-sensitive values (counts, times, request ids, status codes).
 */
function tegh_monitor_note(string $kind, string $summary, array $facts = []): ?array
{
    if (!isset(TEGH_MONITOR_KINDS[$kind])) return null;
    $throttle = max(5, (int)tegh_monitor_setting('alert_throttle_minutes', 60)) * 60;
    $now = time();
    return tegh_monitor_state(static function (array &$state) use ($kind, $summary, $facts, $throttle, $now): ?array {
        $entry = $state[$kind] ?? ['lastSentAt' => 0, 'suppressed' => 0, 'firstSuppressedAt' => null];
        if ($now - (int)$entry['lastSentAt'] < $throttle) {
            $entry['suppressed'] = (int)$entry['suppressed'] + 1;
            $entry['firstSuppressedAt'] ??= gmdate('c', $now);
            $state[$kind] = $entry;
            return null;
        }
        $mail = ['kind' => $kind, 'summary' => $summary, 'facts' => $facts,
            'suppressed' => (int)$entry['suppressed'], 'suppressedSince' => $entry['firstSuppressedAt']];
        $state[$kind] = ['lastSentAt' => $now, 'suppressed' => 0, 'firstSuppressedAt' => null];
        return $mail;
    });
}

function tegh_monitor_send(array $mail): bool
{
    $to = tegh_monitor_recipient();
    $host = parse_url((string)(config('app.base_url') ?? ''), PHP_URL_HOST) ?: 'Tegh';
    $title = TEGH_MONITOR_KINDS[$mail['kind']] ?? 'Tegh alert';
    $lines = ["Tegh monitoring alert on $host", '', $title . ': ' . $mail['summary'], ''];
    foreach ($mail['facts'] as $label => $value) $lines[] = '- ' . $label . ': ' . (is_scalar($value) ? (string)$value : json_encode($value));
    if ($mail['suppressed'] > 0) $lines[] = '- Further occurrences since the previous alert: ' . $mail['suppressed'] . ' (since ' . $mail['suppressedSince'] . ')';
    $lines[] = '';
    $lines[] = 'Details are in Platform administration > System incidents. This email deliberately contains no personal or financial data.';
    $text = system_incident_redact_text(implode("\n", $lines), 4000);
    // The alert is also kept as an incident, so it is visible even when email delivery is not configured.
    record_system_incident($title, 0, 'monitor_' . $mail['kind'], null, [
        'source' => 'monitor', 'severity' => $mail['kind'] === 'server_error' ? 'error' : 'warning',
        'internalMessage' => $text, 'route' => 'monitor',
    ]);
    if ($to === '') return false;
    // Early routes exit before the mail functions are loaded; portal.php only declares functions.
    if (!function_exists('schema_table_exists')) require_once __DIR__ . '/migrations.php';
    if (!function_exists('sr_mail_send') && is_file(__DIR__ . '/portal.php')) require_once __DIR__ . '/portal.php';
    if (!function_exists('sr_mail_send')) return false;
    try {
        $result = sr_mail_send(null, null, $to, 'monitor_alert', '[Tegh alert] ' . $title, $text);
        return !empty($result['sent']);
    } catch (Throwable $error) {
        error_log('Tegh monitor alert email failed kind=' . $mail['kind'] . ' class=' . $error::class);
        return false;
    }
}

function tegh_monitor_alert(string $kind, string $summary, array $facts = []): void
{
    $mail = tegh_monitor_note($kind, $summary, $facts);
    if ($mail !== null) tegh_monitor_send($mail);
}

/** Called from record_system_incident for serious errors; the email (if any) goes out after the response. */
function tegh_monitor_serious_error(string $severity, string $code, int $status, string $requestId): void
{
    static $queued = false;
    if ($queued || !in_array($severity, ['error', 'critical'], true) || str_starts_with($code, 'monitor_')) return;
    $queued = true;
    register_shutdown_function(static function () use ($severity, $code, $status, $requestId): void {
        try {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            tegh_monitor_alert('server_error', 'A request failed with a server error.', [
                'Severity' => $severity, 'Error code' => $code, 'HTTP status' => $status ?: 'n/a',
                'Request reference' => $requestId, 'Time (UTC)' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {}
    });
}

/** Backup status written by beta-ops/tegh-backup.sh into <storage>/runtime/backup-status.json. */
function tegh_monitor_check_backups(): array
{
    $maxAgeHours = max(1, (int)tegh_monitor_setting('backup_max_age_hours', 26));
    $expect = (bool)tegh_monitor_setting('expect_daily_backup', true);
    $dir = tegh_monitor_runtime_dir();
    $path = trim((string)tegh_monitor_setting('backup_status_path', '')) ?: ($dir !== null ? $dir . '/backup-status.json' : '');
    $status = $path !== '' && is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    $lastOk = is_array($status) ? strtotime((string)($status['lastSuccessAt'] ?? '')) : false;
    $lastRun = is_array($status) ? (string)($status['lastRunStatus'] ?? '') : '';
    $report = ['lastSuccessAt' => $lastOk ? gmdate('c', $lastOk) : null, 'lastRunStatus' => $lastRun ?: null, 'alerts' => []];
    if ($lastRun === 'failed') {
        $report['alerts'][] = 'backup_failed';
        tegh_monitor_alert('backup_failed', 'The most recent backup run did not complete.', [
            'Run finished (UTC)' => (string)($status['lastRunAt'] ?? 'unknown'),
            'Reason' => mb_substr(system_incident_redact_text((string)($status['lastRunMessage'] ?? ''), 300), 0, 300),
            'Last successful backup (UTC)' => $lastOk ? gmdate('Y-m-d H:i', $lastOk) : 'none recorded',
        ]);
    }
    if ($expect && (!$lastOk || $lastOk < time() - $maxAgeHours * 3600)) {
        $report['alerts'][] = 'backup_missing';
        tegh_monitor_alert('backup_missing', "No successful backup in the last $maxAgeHours hours.", [
            'Last successful backup (UTC)' => $lastOk ? gmdate('Y-m-d H:i', $lastOk) : 'none recorded',
            'Status file found' => $status !== null ? 'yes' : 'no (is the daily backup cron job set up?)',
        ]);
    }
    return $report;
}

/** Counts from the last window; thresholds are configurable. Only aggregate counts leave this function. */
function tegh_monitor_check_auth(): array
{
    $window = max(5, (int)tegh_monitor_setting('auth_window_minutes', 15));
    $report = ['windowMinutes' => $window, 'alerts' => []];
    $facts = [];
    if (schema_table_exists('login_attempts')) {
        $q = db()->prepare('SELECT COUNT(*) failed, COUNT(DISTINCT email_hash) accounts, COUNT(DISTINCT ip_hash) sources FROM login_attempts WHERE successful=0 AND attempted_at>=UTC_TIMESTAMP()-INTERVAL ? MINUTE');
        $q->execute([$window]);
        $row = $q->fetch() ?: ['failed' => 0, 'accounts' => 0, 'sources' => 0];
        $q = db()->prepare('SELECT COALESCE(MAX(n),0) FROM (SELECT COUNT(DISTINCT email_hash) n FROM login_attempts WHERE successful=0 AND attempted_at>=UTC_TIMESTAMP()-INTERVAL ? MINUTE GROUP BY ip_hash) t');
        $q->execute([$window]);
        $spray = (int)$q->fetchColumn();
        $report += ['failedLogins' => (int)$row['failed'], 'accountsTargeted' => (int)$row['accounts'], 'sources' => (int)$row['sources'], 'maxAccountsFromOneSource' => $spray];
        if ((int)$row['failed'] >= (int)tegh_monitor_setting('failed_login_threshold', 30)) $facts['Failed sign-ins'] = (int)$row['failed'] . ' (' . (int)$row['accounts'] . ' accounts, ' . (int)$row['sources'] . ' sources)';
        if ($spray >= (int)tegh_monitor_setting('spray_threshold', 8)) $facts['Most accounts tried from one source'] = $spray;
    }
    if (schema_table_exists('user_mfa')) {
        $locked = (int)db()->query('SELECT COUNT(*) FROM user_mfa WHERE locked_until > UTC_TIMESTAMP()')->fetchColumn();
        $report['mfaLockedAccounts'] = $locked;
        if ($locked >= (int)tegh_monitor_setting('mfa_lock_threshold', 1)) $facts['Accounts locked after wrong verification codes'] = $locked;
    }
    if (schema_table_exists('password_reset_requests')) {
        $q = db()->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE created_at>=UTC_TIMESTAMP()-INTERVAL ? MINUTE');
        $q->execute([$window]);
        $resets = (int)$q->fetchColumn();
        $report['resetRequests'] = $resets;
        if ($resets >= (int)tegh_monitor_setting('reset_request_threshold', 20)) $facts['Password reset requests'] = $resets;
    }
    if ($facts) {
        $report['alerts'][] = 'auth_suspicious';
        tegh_monitor_alert('auth_suspicious', "Unusual sign-in activity in the last $window minutes.", $facts + ['Window ends (UTC)' => gmdate('Y-m-d H:i')]);
    }
    return $report;
}

/**
 * Configuration lives in a private file, so the app cannot know who edited it. The sweep notices a change to a
 * security-relevant setting, writes a platform audit record naming the settings (not their values) and alerts.
 */
function tegh_monitor_check_config(): array
{
    $current = [];
    foreach (TEGH_MONITOR_SECURITY_SETTINGS as $key) {
        $value = config($key);
        $current[$key] = substr(secret_hash('tegh.monitor.config|' . $key . '|' . json_encode($value)), 0, 32);
    }
    $changed = tegh_monitor_state(static function (array &$state) use ($current): array {
        $previous = $state['configFingerprint'] ?? null;
        $state['configFingerprint'] = $current;
        if (!is_array($previous)) return [];
        return array_values(array_keys(array_filter($current, static fn($hash, $key) => ($previous[$key] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH)));
    }) ?? [];
    $report = ['changedSettings' => $changed, 'alerts' => []];
    if (!$changed) return $report;
    try {
        if (schema_table_exists('platform_audit_log')) {
            $owner = db()->query("SELECT id,email FROM users WHERE platform_role='platform_owner' ORDER BY created_at,id LIMIT 1")->fetch();
            if ($owner) {
                db()->prepare('INSERT INTO platform_audit_log (id,actor_user_id,actor_email,action,target_type,target_id,metadata_json,request_id,ip_hash,user_agent_hash) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([new_id('platformaudit'), (string)$owner['id'], 'system:configuration-monitor', 'platform.security_settings_changed', 'configuration', 'config.php',
                        json_encode(['changedSettings' => $changed, 'detectedBy' => 'monitoring sweep', 'note' => 'Edited in the private configuration file; the editor is not known to the application.'], JSON_UNESCAPED_SLASHES),
                        request_id(), '', '']);
            }
        }
    } catch (Throwable $error) { error_log('Tegh monitor config audit failed: ' . $error::class); }
    $report['alerts'][] = 'security_config';
    tegh_monitor_alert('security_config', 'Security-relevant settings in the configuration file changed. If you did not make this change, investigate.', [
        'Changed settings' => implode(', ', $changed), 'Detected (UTC)' => gmdate('Y-m-d H:i'),
    ]);
    return $report;
}

/** After a restore of an older backup: logins or companies on the deletion register exist again in the database. */
function tegh_monitor_check_deletions(): array
{
    $register = tegh_deletion_register(true);
    $report = ['registered' => ['user' => count($register['user']), 'company' => count($register['company'])], 'reappeared' => ['user' => 0, 'company' => 0], 'alerts' => []];
    foreach ([['user', 'SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND id IN '], ['company', 'SELECT COUNT(*) FROM companies WHERE id IN ']] as [$type, $sql]) {
        foreach (array_chunk(array_keys($register[$type]), 500) as $ids) {
            $q = db()->prepare($sql . '(' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $q->execute($ids);
            $report['reappeared'][$type] += (int)$q->fetchColumn();
        }
    }
    if ($report['reappeared']['user'] + $report['reappeared']['company'] > 0) {
        $report['alerts'][] = 'deleted_reappeared';
        tegh_monitor_alert('deleted_reappeared', 'Items deleted earlier are in the database again, usually because an older backup was restored. They are blocked from use; delete them again from Platform administration.', [
            'Logins' => $report['reappeared']['user'], 'Companies' => $report['reappeared']['company'],
        ]);
    }
    return $report;
}

function tegh_monitor_sweep(string $trigger): array
{
    $report = ['trigger' => $trigger, 'ranAt' => gmdate('c'), 'recipientConfigured' => tegh_monitor_recipient() !== ''];
    foreach (['backups' => 'tegh_monitor_check_backups', 'auth' => 'tegh_monitor_check_auth', 'config' => 'tegh_monitor_check_config', 'deletions' => 'tegh_monitor_check_deletions'] as $name => $check) {
        try { $report[$name] = $check(); }
        catch (Throwable $error) { $report[$name] = ['error' => $error::class]; error_log('Tegh monitor ' . $name . ' check failed: ' . $error::class); }
    }
    tegh_monitor_state(static function (array &$state) use ($report): void { $state['lastSweep'] = ['at' => $report['ranAt'], 'trigger' => $report['trigger']]; });
    return $report;
}

/** Fallback when cron is not set up: at most one sweep per 15 minutes, after the response has been sent. */
function tegh_monitor_opportunistic(): void
{
    if (!(bool)tegh_monitor_setting('opportunistic', true)) return;
    $dir = tegh_monitor_runtime_dir();
    if ($dir === null) return;
    $stamp = $dir . '/monitor-sweep.stamp';
    $last = @filemtime($stamp);
    if ($last !== false && $last > time() - 900) return;
    if (@touch($stamp) === false) return;
    register_shutdown_function(static function (): void {
        try {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            tegh_monitor_sweep('request');
        } catch (Throwable) {}
    });
}
