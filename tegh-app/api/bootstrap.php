<?php
declare(strict_types=1);

const SR_ACCOUNTAX_VERSION = '5.9.9';
const SR_ACCOUNTAX_BUILD = 5990;
const SR_ACCOUNTAX_SCHEMA_VERSION = 46;
require_once __DIR__ . '/release_v5980.php';
require_once __DIR__ . '/bank_safety_v5980.php';
require_once __DIR__ . '/account_capabilities_v5990.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function load_config(): array
{
    $configured = getenv('SR_ACCOUNTAX_CONFIG');
    $paths = array_values(array_filter([
        $configured !== false ? $configured : null,
        dirname(__DIR__, 2) . '/sr-accountax-private/config.php',
        __DIR__ . '/config.local.php',
    ]));
    foreach ($paths as $path) {
        if (is_file($path)) {
            $config = require $path;
            if (!is_array($config)) {
                throw new RuntimeException('The secure configuration file is invalid.');
            }
            return $config;
        }
    }
    throw new RuntimeException('Secure configuration is missing. Copy config.example.php outside the public web root.');
}

function config(?string $key = null): mixed
{
    static $config;
    if ($config === null) {
        $config = load_config();
        $secret = (string)($config['app']['secret'] ?? '');
        if (strlen($secret) < 48 || str_contains($secret, 'REPLACE_WITH')) {
            throw new RuntimeException('Set a unique application secret of at least 48 characters.');
        }
    }
    if ($key === null) {
        return $config;
    }
    if ($key === 'openai.api_key') {
        foreach (['SR_ACCOUNTAX_OPENAI_API_KEY', 'OPENAI_API_KEY'] as $environmentKey) {
            $runtimeValue = getenv($environmentKey);
            if ($runtimeValue !== false && trim($runtimeValue) !== '') {
                return trim($runtimeValue);
            }
        }
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

function tegh_maintenance_flag_path(): string
{
    $configured=trim((string)(config('app.maintenance_flag_path')??''));
    return $configured!==''?$configured:rtrim((string)config('storage_path'),'/').'/runtime/maintenance.json';
}

function tegh_maintenance_mode_active(): bool
{
    return (bool)(config('app.maintenance_mode')??false)||is_file(tegh_maintenance_flag_path());
}

function tegh_maintenance_mode_begin(string $reason='database_upgrade'): string
{
    $token=bin2hex(random_bytes(24));$path=tegh_maintenance_flag_path();$directory=dirname($path);
    if(!is_dir($directory)&&!@mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Maintenance-mode storage is unavailable.');
    if(is_file($path)){
        $existing=json_decode((string)@file_get_contents($path),true);$started=is_array($existing)?strtotime((string)($existing['startedAt']??'')):false;
        $staleAfter=max(600,min(86400,(int)(config('app.maintenance_stale_after_seconds')??3600)));
        if($started===false||time()-$started<=$staleAfter)return '';
        // A crashed upgrade must be recoverable. Rename preserves forensic
        // evidence; the database advisory lock still serializes any live run.
        if(!@rename($path,$path.'.stale.'.gmdate('YmdHis').'.'.substr($token,0,8)))return '';
    }
    $payload=json_encode(['token'=>$token,'reason'=>$reason,'startedAt'=>gmdate('c')],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $handle=@fopen($path,'xb');if($handle===false){if(is_file($path))return '';throw new RuntimeException('Maintenance-mode marker could not be created.');}
    try{if(fwrite($handle,$payload)!==strlen($payload))throw new RuntimeException('Maintenance-mode marker could not be written.');fflush($handle);if(function_exists('fsync'))fsync($handle);}finally{fclose($handle);}
    return $token;
}

function tegh_maintenance_mode_end(string $token): void
{
    if($token==='')return;
    $path=tegh_maintenance_flag_path();$payload=is_file($path)?json_decode((string)file_get_contents($path),true):null;
    if(is_array($payload)&&isset($payload['token'])&&hash_equals((string)$payload['token'],$token))@unlink($path);
}

function tegh_assert_not_in_maintenance_mode(): void
{
    if(!tegh_maintenance_mode_active())return;
    $route=trim((string)($_GET['route']??($_SERVER['PATH_INFO']??'')),'/');
    if(in_array($route,['startup/migrate','startup/migration-preflight','startup/migration-diagnostic'],true))return;
    // R156: a host backup (beta-ops/tegh-backup.sh) pauses changes the same way; say which it is.
    $marker=@json_decode((string)@file_get_contents(tegh_maintenance_flag_path()),true);
    if(is_array($marker)&&($marker['reason']??'')==='backup')fail('Tegh is making a backup. No request was processed; try again in a minute.',503,'maintenance_backup',false);
    fail('Tegh is completing a protected database upgrade. No accounting request was processed; try again shortly.',503,'maintenance_mode',false);
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO(
        (string)config('db.dsn'),
        (string)config('db.user'),
        (string)config('db.password'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 8,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'",
        ],
    );
    try {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 8');
        $pdo->exec('SET SESSION lock_wait_timeout = 8');
    } catch (Throwable $ignored) {
        // Some shared-hosting database accounts do not permit session tuning.
    }
    return $pdo;
}


function db_driver_error_code(Throwable $error): ?int
{
    if (!$error instanceof PDOException) return null;
    $info = $error->errorInfo;
    if (is_array($info) && isset($info[1]) && is_numeric($info[1])) {
        return (int)$info[1];
    }
    if (preg_match('/\b(1205|1213)\b/', $error->getMessage(), $match)) {
        return (int)$match[1];
    }
    return null;
}

function is_transient_database_error(Throwable $error): bool
{
    if (!$error instanceof PDOException) return false;
    $sqlState = strtoupper((string)$error->getCode());
    $driverCode = db_driver_error_code($error);
    return $sqlState === '40001' || in_array($driverCode, [1205, 1213], true);
}

function db_transaction_retry(callable $callback, int $maxAttempts = 4): mixed
{
    if (db()->inTransaction()) {
        return $callback();
    }
    $attempt = 0;
    while (true) {
        $attempt++;
        db()->beginTransaction();
        try {
            $result = $callback();
            db()->commit();
            return $result;
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            if ($attempt >= $maxAttempts || !is_transient_database_error($error)) {
                throw $error;
            }
            usleep(random_int(20_000, 70_000) * $attempt);
        }
    }
}

function request_id(): string
{
    static $requestId;
    if ($requestId === null) {
        $incoming = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        $requestId = preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $incoming)
            ? $incoming
            : bin2hex(random_bytes(8));
    }
    return $requestId;
}

function security_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-SR-Request-ID: ' . request_id());
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Strict-Transport-Security: max-age=31536000');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
}

function json_response(mixed $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function fail(string $message, int $status = 400, string $code = 'request_error', bool $recordIncident = true): never
{
    if (!empty($GLOBALS['tegh_service_exception_boundary'])) throw new TeghServiceFailure($message, $status, $code);
    $routineControlFlow = in_array($code, ['authentication_required','setup_required'], true);
    if ($recordIncident && !$routineControlFlow && function_exists('record_system_incident')) {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
        record_system_incident($message, $status, $code, null, [
            'source' => $status >= 500 ? 'server_error' : 'request_rejected',
            'originFile' => (string)($caller['file'] ?? ''),
            'originLine' => (int)($caller['line'] ?? 0),
        ]);
    }
    if ($code === 'authentication_required') header('X-Tegh-Auth-State: required');
    if ($code === 'session_expired') header('X-Tegh-Auth-State: expired');
    $body = ['error' => $message, 'code' => $code];
    if ($status >= 500) $body['requestId'] = request_id();
    json_response($body, $status);
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > 2_000_000) {
        fail('The request is too large.', 413, 'request_too_large');
    }
    try {
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('The request body is not valid JSON.');
    }
    if (!is_array($decoded)) {
        fail('The request body must be an object.');
    }
    return $decoded;
}

function request_method(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function require_method(string ...$allowed): void
{
    if (!in_array(request_method(), $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        fail('Method not allowed.', 405, 'method_not_allowed');
    }
}

function assert_same_origin(): void
{
    if (in_array(request_method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return;
    }
    $base = rtrim((string)config('app.base_url'), '/');
    if (!hash_equals(strtolower($base), strtolower($origin))) {
        fail('Cross-site requests are not allowed.', 403, 'origin_rejected');
    }
}

function base64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function new_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(16));
}

function secret_hash(string $value): string
{
    return hash_hmac('sha256', $value, (string)config('app.secret'));
}

function client_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return secret_hash($ip);
}

function user_agent_hash(): string
{
    return secret_hash(substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 500));
}

/**
 * Remove credentials and authentication material before diagnostic text is
 * written to the private owner-only incident trail. Request bodies are never
 * collected by this subsystem.
 */
function system_incident_redact_text(mixed $value, int $limit = 20000): string
{
    $text = str_replace("\0", '', (string)$value);
    $patterns = [
        '/\b(Bearer)\s+[A-Za-z0-9._~+\/=:-]+/i' => '$1 [REDACTED]',
        '/\bsk-[A-Za-z0-9_-]{12,}\b/' => '[REDACTED_KEY]',
        '/\b(authorization|cookie|set-cookie|password|passwd|token|secret|api[_-]?key|csrf)\b\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;&]+)/i' => '$1=[REDACTED]',
        '/([?&](?:token|key|secret|password|code)=)[^&\s]+/i' => '$1[REDACTED]',
    ];
    foreach ($patterns as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text) ?? $text;
    }
    return mb_substr($text, 0, max(0, $limit));
}

function system_incident_clean_context(mixed $value, int $depth = 0): mixed
{
    if ($depth >= 5) return '[DEPTH_LIMIT]';
    if (is_array($value)) {
        $clean = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count++ >= 80) {
                $clean['_truncated'] = true;
                break;
            }
            $name = mb_substr((string)$key, 0, 120);
            if (preg_match('/password|passwd|token|secret|api[_-]?key|authorization|cookie|csrf/i', $name)) {
                $clean[$name] = '[REDACTED]';
            } else {
                $clean[$name] = system_incident_clean_context($item, $depth + 1);
            }
        }
        return $clean;
    }
    if (is_string($value)) return system_incident_redact_text($value, 5000);
    if (is_int($value) || is_float($value) || is_bool($value) || $value === null) return $value;
    return system_incident_redact_text((string)$value, 1000);
}

/** Return an append-only log path that is guaranteed to be outside webroot. */
function system_incident_log_path(): ?string
{
    static $resolved = false;
    static $path = null;
    if ($resolved) return $path;
    $resolved = true;
    try {
        $configured = trim((string)(config('incident_log_path') ?? ''));
    } catch (Throwable) {
        $configured = '';
    }
    $candidate = $configured !== ''
        ? $configured
        : dirname(__DIR__, 2) . '/sr-accountax-private/system-incidents.ndjson';
    if ($candidate === '' || $candidate[0] !== '/') return null;
    $directory = dirname($candidate);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return null;
    $realDirectory = realpath($directory);
    $webroot = realpath(dirname(__DIR__));
    if ($realDirectory === false || $webroot === false) return null;
    if ($realDirectory === $webroot || str_starts_with($realDirectory, $webroot . DIRECTORY_SEPARATOR)) return null;
    $path = $realDirectory . DIRECTORY_SEPARATOR . basename($candidate);
    return $path;
}

function system_incident_write_private_log(array $event): void
{
    $path = system_incident_log_path();
    if ($path === null) return;
    try {
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('Tegh could not append the private system incident log.');
            return;
        }
        @chmod($path, 0600);
    } catch (Throwable $error) {
        error_log('Tegh could not encode the private system incident log: ' . $error->getMessage());
    }
}

/** Read only the newest private fallback records; callers must enforce owner access. */
function system_incident_private_events(int $limit = 500): array
{
    $path = system_incident_log_path();
    if ($path === null || !is_file($path) || !is_readable($path)) return [];
    $handle = @fopen($path, 'rb');
    if ($handle === false) return [];
    try {
        $size = @filesize($path);
        $window = 8 * 1024 * 1024;
        if (is_int($size) && $size > $window) {
            @fseek($handle, -$window, SEEK_END);
            @fgets($handle); // discard a partial first line
        }
        $rows = [];
        while (($line = fgets($handle)) !== false) {
            try {
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($row) || empty($row['id'])) continue;
                $rows[] = $row;
                if (count($rows) > $limit) array_shift($rows);
            } catch (Throwable) {
                // A torn final line must not make the owner audit unavailable.
            }
        }
        return $rows;
    } finally {
        fclose($handle);
    }
}

function system_incident_severity(int $status, ?Throwable $exception, array $context): string
{
    $override = (string)($context['severity'] ?? '');
    if (in_array($override, ['info','warning','error','critical'], true)) return $override;
    if ($exception !== null) return 'critical';
    if ($status >= 500) return 'error';
    if (in_array($status, [401,403,429], true)) return 'warning';
    return 'info';
}

/**
 * Persist a diagnostic incident without ever changing the response outcome.
 * The private NDJSON file captures database/transaction failures; the database
 * copy powers the platform-owner audit UI when it is safe to insert one.
 */
function record_system_incident(
    string $publicMessage,
    int $status,
    string $code,
    ?Throwable $exception = null,
    array $context = [],
): string {
    static $recording = false;
    try {
        $id = 'sysincident_' . bin2hex(random_bytes(16));
    } catch (Throwable) {
        $id = 'sysincident_' . hash('sha256', microtime(true) . '|' . request_id());
    }
    if ($recording) return $id;
    $recording = true;
    try {
        $route = system_incident_redact_text((string)($context['route'] ?? ($_GET['route'] ?? ($_SERVER['PATH_INFO'] ?? 'startup'))), 180);
        $method = strtoupper(mb_substr((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 12));
        $requestId = system_incident_redact_text((string)($context['requestId'] ?? request_id()), 80);
        $companyId = trim((string)($context['companyId'] ?? ($_SERVER['HTTP_X_COMPANY_ID'] ?? ($_GET['companyId'] ?? ''))));
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $companyId)) $companyId = '';
        $userId = '';
        $userEmail = '';
        try {
            if (function_exists('session_record')) {
                $session = session_record();
                if (is_array($session)) {
                    $userId = (string)($session['user_id'] ?? '');
                    $userEmail = (string)($session['email'] ?? '');
                }
            }
        } catch (Throwable) {}
        try { $ipHash = client_ip_hash(); } catch (Throwable) { $ipHash = ''; }
        try { $agentHash = user_agent_hash(); } catch (Throwable) { $agentHash = ''; }
        $source = preg_replace('/[^a-z0-9_-]/i', '', (string)($context['source'] ?? ($exception ? 'uncaught_exception' : 'request_rejected'))) ?: 'request_rejected';
        $source = mb_substr($source, 0, 40);
        $internalMessage = system_incident_redact_text((string)($context['internalMessage'] ?? ($exception?->getMessage() ?? $publicMessage)), 20000);
        $exceptionClass = system_incident_redact_text((string)($exception ? $exception::class : ($context['exceptionClass'] ?? '')), 200);
        $exceptionFile = system_incident_redact_text((string)($exception?->getFile() ?? ($context['originFile'] ?? '')), 1000);
        $exceptionLine = (int)($exception?->getLine() ?? ($context['originLine'] ?? 0));
        $stackTrace = system_incident_redact_text((string)($context['stackTrace'] ?? ($exception?->getTraceAsString() ?? '')), 30000);
        $cleanContext = system_incident_clean_context(array_diff_key($context, array_flip([
            'source','severity','route','requestId','internalMessage','exceptionClass','originFile','originLine','stackTrace',
        ])));
        $contextJson = json_encode($cleanContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $occurredAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $event = [
            'id'=>$id,'occurred_at'=>$occurredAt,'severity'=>system_incident_severity($status,$exception,$context),
            'source'=>$source,'route'=>$route,'method'=>$method,'http_status'=>$status > 0 ? $status : null,
            'error_code'=>mb_substr($code,0,120),'public_message'=>system_incident_redact_text($publicMessage,1000),
            'internal_message'=>$internalMessage,'exception_class'=>$exceptionClass,'exception_file'=>$exceptionFile,
            'exception_line'=>$exceptionLine > 0 ? $exceptionLine : null,'stack_trace'=>$stackTrace,
            'request_id'=>$requestId,'user_id'=>$userId !== '' ? $userId : null,'user_email'=>$userEmail !== '' ? $userEmail : null,
            'company_id'=>$companyId !== '' ? $companyId : null,'ip_hash'=>$ipHash,'user_agent_hash'=>$agentHash,
            'context_json'=>$contextJson,'created_at'=>$occurredAt,
        ];
        system_incident_write_private_log($event);
        try {
            if (function_exists('schema_table_exists') && schema_table_exists('platform_incident_log')) {
                $pdo = db();
                if (!$pdo->inTransaction()) {
                    $pdo->prepare('INSERT INTO platform_incident_log
                        (id,occurred_at,severity,source,route,method,http_status,error_code,public_message,internal_message,exception_class,exception_file,exception_line,stack_trace,request_id,user_id,user_email,company_id,ip_hash,user_agent_hash,context_json)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                        $event['id'],$event['occurred_at'],$event['severity'],$event['source'],$event['route'],$event['method'],$event['http_status'],
                        $event['error_code'],$event['public_message'],$event['internal_message'],$event['exception_class'] ?: null,
                        $event['exception_file'] ?: null,$event['exception_line'],$event['stack_trace'] ?: null,$event['request_id'],
                        $event['user_id'],$event['user_email'],$event['company_id'],$event['ip_hash'],$event['user_agent_hash'],$event['context_json'],
                    ]);
                }
            }
        } catch (Throwable $storageError) {
            error_log('Tegh incident database copy failed request=' . $requestId . ' ' . $storageError->getMessage());
        }
    } catch (Throwable $loggingError) {
        error_log('Tegh incident recording failed: ' . $loggingError->getMessage());
    } finally {
        $recording = false;
    }
    return $id;
}

function clean_text(mixed $value, string $label, int $max = 160): string
{
    $text = preg_replace('/\s+/u', ' ', trim((string)$value)) ?? '';
    if ($text === '') {
        fail($label . ' is required.');
    }
    if (mb_strlen($text) > $max) {
        fail($label . ' must be ' . $max . ' characters or fewer.');
    }
    return $text;
}

function optional_text(mixed $value, int $max = 500): ?string
{
    $text = preg_replace('/\s+/u', ' ', trim((string)$value)) ?? '';
    if ($text === '') {
        return null;
    }
    if (mb_strlen($text) > $max) {
        fail('A text value is too long.');
    }
    return $text;
}

function safe_email(mixed $value): string
{
    $email = strtolower(trim((string)$value));
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        fail('Enter a valid email address.');
    }
    return $email;
}

function safe_date(mixed $value, string $label = 'Date'): string
{
    $text = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $text) {
        fail($label . ' must use YYYY-MM-DD.');
    }
    return $text;
}

function safe_cents(mixed $value, string $label, bool $allowZero = false): int
{
    if (is_int($value)) {
        $amount = $value;
    } elseif (is_numeric($value) && (string)(int)$value === trim((string)$value)) {
        $amount = (int)$value;
    } else {
        fail($label . ' must be an integer number of cents.');
    }
    if ((!$allowZero && $amount === 0) || abs($amount) > 100_000_000_000) {
        fail($label . ' is outside the supported range.');
    }
    return $amount;
}

function province_rate_bps(string $province): int
{
    return match (strtoupper($province)) {
        'ON' => 1300,
        'NS' => 1400,
        'NB', 'NL', 'PE' => 1500,
        default => 500,
    };
}

/* R136: the PST/QST/RST rate is stored exactly in thousandths of a percent
   (companies.pst_rate_mpct: 7% = 7000, QST 9.975% = 9975). The older whole
   basis-point column pst_rate_bps cannot hold 9.975% and is kept, rounded,
   only for older readers. The column is added on first write; until then the
   rate falls back to pst_rate_bps x 10, which is exact for every whole-bps rate. */
function company_pst_rate_mpct(array $company): int
{
    if (array_key_exists('pst_rate_mpct', $company) && $company['pst_rate_mpct'] !== null) return max(0, (int)$company['pst_rate_mpct']);
    return max(0, (int)($company['pst_rate_bps'] ?? 0) * 10);
}

function tegh_pst_rate_precision_ready(): bool
{
    static $ready = null;
    if ($ready === true) return true;
    if (!function_exists('schema_column_exists')) return false;
    if (!schema_column_exists('companies', 'pst_rate_mpct')) {
        if (db()->inTransaction()) return false; // DDL would commit an open transaction
        db()->exec('ALTER TABLE `companies` ADD COLUMN `pst_rate_mpct` INT NULL DEFAULT NULL AFTER `pst_rate_bps`');
        db()->exec('UPDATE companies SET pst_rate_mpct = pst_rate_bps * 10 WHERE pst_rate_mpct IS NULL');
    }
    return $ready = true;
}

/** Parse a PST rate from input: pstRateMpct (preferred), pstRatePercent, or legacy pstRateBps. */
function pst_rate_mpct_from_input(array $input, ?int $fallback = null): int
{
    if (isset($input['pstRateMpct']) && $input['pstRateMpct'] !== '') $v = filter_var($input['pstRateMpct'], FILTER_VALIDATE_INT);
    elseif (isset($input['pstRatePercent']) && $input['pstRatePercent'] !== '') $v = is_numeric($input['pstRatePercent']) ? (int)round((float)$input['pstRatePercent'] * 1000) : false;
    elseif (isset($input['pstRateBps']) && $input['pstRateBps'] !== '') $v = is_numeric($input['pstRateBps']) ? (int)round((float)$input['pstRateBps'] * 10) : false;
    else $v = $fallback ?? 0;
    if ($v === false || $v < 0 || $v > 25000) fail('PST rate must be between 0% and 25% (up to three decimals, for example 9.975).', 422, 'pst_rate_invalid');
    return (int)$v;
}

function audit_event(array $user, string $companyId, string $action, string $entityType, string $entityId, array $metadata = []): void
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        // The company voucher-sequence row is also the company-level write lock for
        // audit ordering. It prevents concurrent requests from creating two audit
        // entries that both point to the same previous hash.
        if (function_exists('schema_table_exists') && schema_table_exists('voucher_sequences')) {
            // INSERT IGNORE can leave concurrent duplicate-key callers holding
            // shared record locks before both try to upgrade with FOR UPDATE.
            // A no-op duplicate update obtains the company row's exclusive lock
            // immediately, so audit writers queue in one order instead of
            // deadlocking during the subsequent lock read.
            $pdo->prepare('INSERT INTO voucher_sequences (company_id,next_serial) VALUES (?,1) ON DUPLICATE KEY UPDATE next_serial=next_serial')->execute([$companyId]);
            $lock = $pdo->prepare('SELECT next_serial FROM voucher_sequences WHERE company_id=? FOR UPDATE');
            $lock->execute([$companyId]);
            $lock->fetchColumn();
        }

        $id = new_id('audit');
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (function_exists('schema_column_exists') && schema_column_exists('audit_log', 'entry_hash')) {
            $createdAt = gmdate('Y-m-d H:i:s');
            $stmt = $pdo->prepare('SELECT entry_hash FROM audit_log WHERE company_id=? ORDER BY created_at DESC,id DESC LIMIT 1');
            $stmt->execute([$companyId]);
            $previous = (string)($stmt->fetchColumn() ?: '');
            $rid = request_id();
            $entry = hash('sha256', implode('|', [$companyId,$previous,$id,(string)$user['id'],(string)$user['email'],$action,$entityType,$entityId,$json,$rid,$createdAt]));
            $pdo->prepare('INSERT INTO audit_log (id,company_id,actor_user_id,actor_email,action,entity_type,entity_id,metadata_json,previous_hash,entry_hash,request_id,ip_hash,user_agent_hash,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id,$companyId,$user['id'],$user['email'],$action,$entityType,$entityId,$json,$previous,$entry,$rid,client_ip_hash(),user_agent_hash(),$createdAt]);
        } else {
            $pdo->prepare('INSERT INTO audit_log (id,company_id,actor_user_id,actor_email,action,entity_type,entity_id,metadata_json) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id,$companyId,$user['id'],$user['email'],$action,$entityType,$entityId,$json]);
        }

        if (function_exists('platform_voucher_from_audit')) {
            platform_voucher_from_audit($user,$companyId,$action,$entityType,$entityId,$metadata);
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function password_algorithm(): string|int|null
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function validate_new_password(string $password): void
{
    if (strlen($password) < 12 || strlen($password) > 200) {
        fail('Password must contain between 12 and 200 characters.');
    }
    if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
        fail('Password must include upper-case, lower-case, and numeric characters.');
    }
}

function set_session_cookie(string $token, int $expires): void
{
    setcookie((string)config('app.session_cookie'), $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function set_csrf_cookie(string $token, int $expires): void
{
    setcookie((string)config('app.session_cookie') . '_csrf', $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => true,
        'httponly' => false,
        'samesite' => 'Strict',
    ]);
}

function clear_session_cookie(): void
{
    setcookie((string)config('app.session_cookie'), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    setcookie((string)config('app.session_cookie') . '_csrf', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => false,
        'samesite' => 'Strict',
    ]);
}

function create_session(string $userId): array
{
    $token = base64url_encode(random_bytes(32));
    $csrf = base64url_encode(random_bytes(32));
    $hours = max(1, min(72, (int)(config('app.session_hours') ?? 12)));
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $hours . ' hours');
    $stmt = db()->prepare('INSERT INTO sessions (id, user_id, token_hash, csrf_hash, ip_hash, user_agent_hash, expires_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
    $stmt->execute([
        new_id('session'), $userId, secret_hash($token), secret_hash($csrf),
        client_ip_hash(), user_agent_hash(), $expiresAt->format('Y-m-d H:i:s'),
    ]);
    set_session_cookie($token, $expiresAt->getTimestamp());
    set_csrf_cookie($csrf, $expiresAt->getTimestamp());
    return ['csrfToken' => $csrf, 'expiresAt' => $expiresAt->format(DateTimeInterface::ATOM)];
}

function csrf_cookie_value(): string
{
    return trim((string)($_COOKIE[(string)config('app.session_cookie') . '_csrf'] ?? ''));
}

function session_record(): ?array
{
    static $loaded = false;
    static $record = null;
    if ($loaded) {
        return $record;
    }
    $loaded = true;
    $token = trim((string)($_COOKIE[(string)config('app.session_cookie')] ?? ''));
    if ($token === '') {
        return null;
    }
    $stmt = db()->prepare("SELECT s.id AS session_id, s.user_id, s.csrf_hash, s.ip_hash, s.user_agent_hash, s.expires_at, s.created_at AS authenticated_at, u.email, u.display_name
        FROM sessions s JOIN users u ON u.id = s.user_id
        WHERE s.token_hash = ? AND s.expires_at > UTC_TIMESTAMP() AND u.active = 1 LIMIT 1");
    $stmt->execute([secret_hash($token)]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals((string)$row['user_agent_hash'], user_agent_hash())) {
        clear_session_cookie();
        return null;
    }
    $record = $row;
    db()->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['session_id']]);
    return $record;
}

function require_user(): array
{
    $session = session_record();
    if (!$session) {
        fail('Sign in is required.', 401, 'authentication_required');
    }
    return [
        'id' => (string)$session['user_id'],
        'email' => (string)$session['email'],
        'displayName' => (string)$session['display_name'],
        'sessionId' => (string)$session['session_id'],
    ];
}

function require_csrf(): void
{
    if (in_array(request_method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    $session = session_record();
    if (!$session) {
        fail('Sign in is required.', 401, 'authentication_required');
    }
    $provided = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($provided === '' || !hash_equals((string)$session['csrf_hash'], secret_hash($provided))) {
        fail('The security token is missing or expired. Refresh and try again.', 403, 'csrf_rejected');
    }
}

function company_role_permissions(string $role): array
{
    $catalog = [
        'viewer' => [
            'company.view','customers.view','vendors.view','invoices.view','bills.view','banking.view',
            'journals.view','reports.view','attachments.view','audit.view',
        ],
        'editor' => [
            'company.view','customers.view','customers.write','vendors.view','vendors.write',
            'invoices.view','invoices.write','bills.view','bills.write','payments.write',
            'ar.credit_note.write','ar.debit_note.write','ap.credit_note.write','ap.debit_note.write',
            'banking.view','banking.import','banking.match','banking.reconcile',
            'journals.view','journals.write','reports.view','reports.export','attachments.view','attachments.write','audit.view','payroll.view','payroll.manage',
        ],
        'admin' => [
            'company.view','company.settings','customers.view','customers.write','vendors.view','vendors.write',
            'invoices.view','invoices.write','invoices.reverse','bills.view','bills.write','bills.reverse','payments.write','payments.reverse',
            'ar.credit_note.write','ar.debit_note.write','ap.credit_note.write','ap.debit_note.write',
            'banking.view','banking.import','banking.match','banking.reconcile','banking.reverse',
            'journals.view','journals.write','journals.reverse','reports.view','reports.export',
            'attachments.view','attachments.write','audit.view','users.view','users.invite','users.manage',
            'period.lock','period.unlock','payroll.view','payroll.manage','integrity.run',
        ],
        'owner' => ['*'],
        'support' => [
            'company.view','customers.view','vendors.view','invoices.view','bills.view','banking.view',
            'journals.view','reports.view','attachments.view','audit.view',
        ],
    ];
    return $catalog[$role] ?? [];
}

function company_role_can(string $role,string $permission): bool
{
    $permissions=company_role_permissions($role);
    return in_array('*',$permissions,true)||in_array($permission,$permissions,true);
}

function require_company_permission(array $company,string $permission): void
{
    if(!company_role_can((string)$company['role'],$permission)){
        fail('Your company role does not permit this action.',403,'permission_forbidden');
    }
    if(function_exists('tegh_enforce_current_route_feature'))tegh_enforce_current_route_feature($company);
}

function company_role_label(string $role): string
{
    return match($role){
        'owner'=>'Company Owner','admin'=>'Company Admin','editor'=>'Editor','viewer'=>'Viewer','support'=>'Support',default=>ucfirst($role),
    };
}

/** R157: the time zone of the company this request works on; Tegh's default is Toronto. */
function tegh_set_accounting_timezone(string $zone): void
{
    $GLOBALS['tegh_accounting_timezone'] = ($zone !== '' && in_array($zone, timezone_identifiers_list(), true)) ? $zone : 'America/Toronto';
}

function tegh_accounting_timezone(): string
{
    return (string)($GLOBALS['tegh_accounting_timezone'] ?? 'America/Toronto');
}

function require_company(array $user): array
{
    $companyId = trim((string)($_SERVER['HTTP_X_COMPANY_ID'] ?? $_GET['companyId'] ?? ''));
    if ($companyId === '') {
        fail('Choose a company first.', 409, 'company_required');
    }
    $statusClause = schema_column_exists('company_members','status') ? " AND cm.status='active'" : '';
    $stmt = db()->prepare("SELECT c.*, cm.role FROM companies c
        JOIN company_members cm ON cm.company_id = c.id
        WHERE c.id = ? AND cm.user_id = ? AND c.active = 1".$statusClause." LIMIT 1");
    $stmt->execute([$companyId, $user['id']]);
    $company = $stmt->fetch();
    // Platform ownership grants administration controls, not implicit access
    // to another tenant's books. Company data requires membership or a current,
    // explicit support grant below.
    if (!$company && schema_table_exists('support_requests') && schema_column_exists('users','platform_role')) {
        $support = db()->prepare("SELECT c.*,'support' AS role FROM companies c JOIN support_requests sr ON sr.company_id=c.id JOIN users u ON u.id=sr.assigned_to WHERE c.id=? AND sr.assigned_to=? AND u.platform_role='platform_owner' AND sr.grant_access=1 AND sr.status IN ('pending','in_progress') AND (sr.access_expires_at IS NULL OR sr.access_expires_at>UTC_TIMESTAMP()) AND c.active=1 ORDER BY sr.created_at DESC LIMIT 1");
        $support->execute([$companyId,$user['id']]);
        $company=$support->fetch();
    }
    if (!$company) fail('The selected company is unavailable.',403,'company_forbidden');
    // R157: the company's accounting date follows its time zone (Toronto when none is set).
    tegh_set_accounting_timezone((string)($company['timezone'] ?? ''));
    if ((string)($_SERVER['HTTP_X_SR_TEST_RUN'] ?? '') === '1') {
        $isCurrentTest = !empty($company['test_mode'])
            && ($company['test_expires_at'] === null || strtotime((string)$company['test_expires_at'].' UTC') > time());
        if (!$isCurrentTest) {
            fail('The intensive test request is restricted to an active Test Mode company.', 403, 'isolated_test_company_required');
        }
        $roleStmt = db()->prepare("SELECT platform_role FROM users WHERE id=? AND active=1 LIMIT 1");
        $roleStmt->execute([$user['id']]);
        if ((string)$roleStmt->fetchColumn() !== 'platform_owner') {
            fail('The intensive test request is restricted to the Tegh platform owner.', 403, 'test_owner_required');
        }
    }
    return $company;
}

function require_company_role(array $company, string ...$roles): void
{
    // Backward-compatible role gate used by existing accounting handlers.
    // Legacy "bookkeeper" means operational write access, now provided by Editor.
    // A request for owner-level administration also permits Admin, except where a
    // handler explicitly calls require_company_permission() for owner-only controls.
    $role=(string)$company['role'];
    // Temporary platform support grants are intentionally read-only. Support
    // may satisfy legacy viewer gates, but must never inherit bookkeeper/editor
    // write access through this compatibility helper.
    $allowed=($role==='support'&&in_array('viewer',$roles,true))
      ||in_array($role,$roles,true)
      ||($role==='admin'&&(in_array('owner',$roles,true)||in_array('bookkeeper',$roles,true)||in_array('viewer',$roles,true)))
      ||($role==='editor'&&(in_array('bookkeeper',$roles,true)||in_array('viewer',$roles,true)))
      ||($role==='viewer'&&in_array('viewer',$roles,true));
    if(!$allowed)fail('Your company role does not permit this action.', 403, 'role_forbidden');
    if(function_exists('tegh_enforce_current_route_feature'))tegh_enforce_current_route_feature($company);
}

function setup_required(): bool
{
    if (!schema_table_exists('users')) return true;
    $count = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    return $count === 0;
}

function initialize_schema(): void
{
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('The database schema file is missing.');
    }
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    // MySQL and MariaDB implicitly commit many DDL statements. Running the
    // schema in an explicit PDO transaction would therefore fail on some
    // IONOS database versions when PDO later tries to commit it.
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            db()->exec($statement);
        }
    }
}

function cleanup_security_state(): void
{
    if (random_int(1, 100) !== 1) {
        return;
    }
    db()->exec("DELETE FROM sessions WHERE expires_at <= UTC_TIMESTAMP()");
    db()->exec("DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 2 DAY");
}

tegh_assert_not_in_maintenance_mode();
security_headers();
assert_same_origin();
