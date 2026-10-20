<?php
declare(strict_types=1);

// R164: operator monitoring sweep (backups, suspicious sign-ins). Run from cron every 15-60 minutes, e.g.
//   */30 * * * * /usr/bin/php /path/to/tegh/api/monitor-cron.php >> /path/to/private/monitor.log 2>&1
// Prints a one-line summary with counts only. Command line only.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/portal.php';
require_once __DIR__ . '/monitoring_r164.php';
try {
    $report = tegh_monitor_sweep('cron');
    $alerts = array_merge($report['backups']['alerts'] ?? [], $report['auth']['alerts'] ?? [], $report['config']['alerts'] ?? []);
    echo gmdate('c'), ' monitor ', $alerts ? 'ALERT ' . implode(',', $alerts) : 'ok',
        ' lastBackup=', $report['backups']['lastSuccessAt'] ?? 'none',
        ' failedLogins=', $report['auth']['failedLogins'] ?? 'n/a',
        ' recipient=', $report['recipientConfigured'] ? 'set' : 'MISSING', PHP_EOL;
    exit($alerts ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, gmdate('c') . ' monitor failed: ' . $error::class . PHP_EOL);
    exit(1);
}
