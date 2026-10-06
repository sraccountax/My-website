<?php
declare(strict_types=1);

/*
 * R153: company address and short name.
 *
 *  - Company setup and Company Details keep a street address, city, postal code, phone and business email
 *    (province/state and country were already there) and a short name such as "NWS" used to label report
 *    lines when several companies are viewed together.
 *  - Customer invoices show the company address unless the invoice template has its own address. The address
 *    is copied into the invoice when it is issued, as before, so issued invoices do not change later.
 *
 * Columns are additive and made on first use, outside any transaction.
 */

const R153_PROFILE_FIELDS = [
    'shortName' => ['short_name', 12],
    'addressLine1' => ['address_line1', 200],
    'addressLine2' => ['address_line2', 200],
    'city' => ['city', 100],
    'postalCode' => ['postal_code', 20],
    'phone' => ['phone', 40],
    'contactEmail' => ['contact_email', 254],
];

function r153_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (R153_PROFILE_FIELDS as [$column, $max]) schema_add_column('companies', $column, "VARCHAR($max) NULL");
        $ready = schema_column_exists('companies', 'short_name') && schema_column_exists('companies', 'contact_email');
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** Initials of the significant words ("Northwind Office Supply Ltd." → "NOS"); one word → its first four letters. */
function r153_short_name_auto(string $name): string
{
    $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $skip = ['inc', 'ltd', 'llc', 'llp', 'corp', 'corporation', 'co', 'company', 'limited', 'incorporated', 'the', 'and', 'of', 'ltee', 'ltée', 'enr', 'inc.', 'cie'];
    $words = array_values(array_filter($words, static fn(string $w): bool => !in_array(mb_strtolower($w), $skip, true)));
    if (!$words) return 'CO';
    if (count($words) === 1) return mb_strtoupper(mb_substr($words[0], 0, 4));
    return mb_strtoupper(mb_substr(implode('', array_map(static fn(string $w): string => mb_substr($w, 0, 1), $words)), 0, 5));
}

function r153_company_short_name(array $company): string
{
    $stored = trim((string)($company['short_name'] ?? ''));
    return $stored !== '' ? $stored : r153_short_name_auto((string)($company['name'] ?? ''));
}

/** The company's address as printed: street lines, "City, PROV POSTAL", country. Empty when no street is set. */
function r153_company_address_text(array $company): string
{
    $line1 = trim((string)($company['address_line1'] ?? ''));
    if ($line1 === '') return '';
    $cityLine = trim(implode(' ', array_filter([
        trim(implode(', ', array_filter([trim((string)($company['city'] ?? '')), trim((string)($company['province'] ?? ''))], 'strlen'))),
        trim((string)($company['postal_code'] ?? '')),
    ], 'strlen')));
    return implode("\n", array_values(array_filter([$line1, trim((string)($company['address_line2'] ?? '')), $cityLine, trim((string)($company['country'] ?? ''))], 'strlen')));
}

/** Validated profile fields from a create or update request. Only keys present in the request are returned. */
function r153_profile_input(array $input): array
{
    $out = [];
    foreach (R153_PROFILE_FIELDS as $key => [$column, $max]) {
        if (!array_key_exists($key, $input)) continue;
        $value = trim(preg_replace('/\s+/u', ' ', (string)$input[$key]) ?? '');
        if (mb_strlen($value) > $max) fail(sprintf('%s must be %d characters or fewer.', ['shortName' => 'Short name', 'addressLine1' => 'Street address', 'addressLine2' => 'Address line 2', 'city' => 'City', 'postalCode' => 'Postal or ZIP code', 'phone' => 'Phone', 'contactEmail' => 'Business email'][$key], $max), 422, 'company_profile_invalid');
        if ($key === 'contactEmail' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) fail('Enter a valid business email.', 422, 'company_profile_invalid');
        if ($key === 'shortName' && $value !== '' && !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} &.\-]*$/u', $value)) fail('The short name can use letters, numbers, spaces, "&", "." and "-".', 422, 'company_profile_invalid');
        if ($key === 'postalCode') $value = mb_strtoupper($value);
        $out[$column] = $value === '' ? null : $value;
    }
    return $out;
}

function r153_profile_save(string $companyId, array $columns): void
{
    if (!$columns || !r153_schema_ready()) return;
    $sets = implode(',', array_map(static fn(string $column): string => "`$column` = ?", array_keys($columns)));
    db()->prepare("UPDATE companies SET $sets WHERE id = ?")->execute([...array_values($columns), $companyId]);
}

/** Fields added to the company payloads (company list and workspace organization). */
function r153_profile_public(array $company): array
{
    return [
        'shortName' => r153_company_short_name($company),
        'shortNameSet' => trim((string)($company['short_name'] ?? '')) !== '',
        'addressLine1' => (string)($company['address_line1'] ?? ''),
        'addressLine2' => (string)($company['address_line2'] ?? ''),
        'city' => (string)($company['city'] ?? ''),
        'postalCode' => (string)($company['postal_code'] ?? ''),
        'phone' => (string)($company['phone'] ?? ''),
        'contactEmail' => (string)($company['contact_email'] ?? ''),
        'addressText' => r153_company_address_text($company),
    ];
}

/**
 * The address an invoice prints: the template's own address when it has one, otherwise the company address.
 * The address seeded into new templates ("ON, Canada") counts as "no address" once the company has a street.
 */
function r153_invoice_business_address(?string $templateAddress, array $company): string
{
    $template = trim((string)$templateAddress);
    $companyAddress = r153_company_address_text($company);
    $province = trim((string)($company['province'] ?? ''));
    $seeded = [$province . ', ' . trim((string)($company['country'] ?? 'Canada')), $province . ', Canada', trim((string)($company['country'] ?? ''))];
    if ($template !== '' && !in_array($template, $seeded, true)) return $template;
    if ($companyAddress !== '') return $companyAddress;
    return $template !== '' ? $template : ($province . ', Canada');
}
