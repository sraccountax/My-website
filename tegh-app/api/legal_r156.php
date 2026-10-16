<?php
declare(strict_types=1);

/*
 * R156: who operates this Tegh installation, shown on the Terms and Privacy pages.
 *
 * The facts come from config.php ('operator' section) because they differ for every deployment. The legal pages show
 * a plain "incomplete" notice while any required field is blank, and invitations cannot be sent until they are filled
 * in: testers' names and emails are real personal information even when the accounting data is sample data.
 */

const TEGH_OPERATOR_FIELDS = [
    'legal_name' => 'Legal name of the business operating Tegh',
    'address' => 'Business address',
    'privacy_email' => 'Privacy contact email',
    'support_email' => 'Support email',
    'hosting_provider' => 'Hosting provider',
    'data_location' => 'Where the data is stored (country/region)',
    'backup_location' => 'Where the off-site backup copy is kept',
    'mail_provider' => 'Email delivery provider',
];

function tegh_operator_details(): array
{
    $cfg = config('operator');
    $cfg = is_array($cfg) ? $cfg : [];
    $out = [];
    foreach (array_keys(TEGH_OPERATOR_FIELDS) as $key) $out[$key] = mb_substr(trim((string)($cfg[$key] ?? '')), 0, 300);
    $out['backup_retention_days'] = max(0, min(3650, (int)($cfg['backup_retention_days'] ?? 14)));
    $out['deletion_response_days'] = max(1, min(90, (int)($cfg['deletion_response_days'] ?? 30)));
    return $out;
}

/** Required fields that are blank, as human labels. */
function tegh_operator_missing(): array
{
    $details = tegh_operator_details();
    $missing = [];
    foreach (TEGH_OPERATOR_FIELDS as $key => $label) if ($details[$key] === '') $missing[] = $label;
    return $missing;
}

function tegh_operator_require_complete(): void
{
    $missing = tegh_operator_missing();
    if ($missing) fail('Invitations are paused until the operator details on the Terms and Privacy pages are complete. Add them to the operator section of config.php: ' . implode(', ', $missing) . '.', 409, 'operator_details_missing');
}

function handle_public_operator(): never
{
    require_method('GET');
    header('Cache-Control: no-store');
    $missing = tegh_operator_missing();
    json_response(['operator' => tegh_operator_details(), 'complete' => !$missing, 'missing' => $missing]);
}
