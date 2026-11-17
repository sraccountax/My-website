<?php
declare(strict_types=1);

/**
 * R181: books in any currency, with exchange rates recorded by date.
 *
 * - The base (General Ledger) currency of a company outside Canada can be any ISO 4217 currency that Tegh's amount
 *   storage represents exactly (2 decimal places) and whose amounts fit Tegh's limits. Canadian companies stay in CAD
 *   (GST/HST returns and Canadian payroll are in CAD).
 * - Transaction currencies: every current ISO 4217 currency with 0 or 2 decimal places.
 * - A company whose rate rule is "dated" converts each foreign-currency document at the rate recorded for the
 *   document's date (or the latest earlier rate within the company's allowed age). Without such a rate the document is
 *   not saved; a different rate typed on the document needs confirmation and is written to the audit trail.
 * - A company whose rule is "current" (every company that existed before R181) works exactly as in R180.
 * - Availability is controlled by Site & Release Settings › Release › Worldwide currencies (off, owner, everyone).
 */

require_once __DIR__ . '/currency_data_r181.php';

const TEGH_R181_LEGACY_CURRENCIES = ['CAD', 'USD', 'EUR', 'GBP', 'AUD', 'NZD', 'INR'];
/** 2-decimal currencies where Tegh's limit of 1,000,000,000 units per amount is too small for ordinary business use. */
const TEGH_R181_HIGH_DENOMINATION = ['ARS', 'CDF', 'COP', 'IDR', 'IRR', 'KHR', 'LAK', 'LBP', 'MGA', 'MMK', 'MNT', 'MWK', 'NGN', 'SSP', 'SYP', 'TZS', 'UZS'];
/** Rates are stored in millionths; below 5,000 millionths (0.005) the rounding error would exceed 0.01 %. */
const TEGH_R181_MIN_RATE_MICROS = 5000;
const TEGH_R181_DEFAULT_MAX_AGE_DAYS = 7;

/* ---------- availability ---------- */

/** off, owner (platform owner only) or everyone. */
function tegh_r181_mode(): string
{
    if (!function_exists('r174_group')) return 'off';
    $mode = (string)(r174_group('release')['value']['worldCurrencies'] ?? 'off');
    return in_array($mode, ['off', 'owner', 'everyone'], true) ? $mode : 'off';
}

function tegh_r181_available_for(?array $user): bool
{
    $mode = tegh_r181_mode();
    if ($mode === 'everyone') return true;
    if ($mode === 'owner' && $user !== null && function_exists('platform_role_for_user')) return platform_role_for_user((string)$user['id']) === 'platform_owner';
    return false;
}

/* ---------- currencies ---------- */

function tegh_r181_currency(string $code): ?array
{
    $t = tegh_r181_currency_table();
    $code = strtoupper($code);
    return isset($t[$code]) ? ['code' => $code, 'name' => $t[$code][0], 'minorUnits' => $t[$code][1]] : null;
}

/** Why a currency cannot be a company's base currency in R181, or null when it can. */
function tegh_r181_base_currency_problem(string $code): ?string
{
    $c = tegh_r181_currency($code);
    if ($c === null) return 'This is not a current ISO 4217 currency code.';
    if ($c['minorUnits'] === 0) return $c['name'] . ' has no decimal places. Tegh does not yet keep books in currencies without cents; it can still be used for foreign-currency documents.';
    if ($c['minorUnits'] === 3) return $c['name'] . ' has three decimal places, which Tegh cannot yet store exactly.';
    if (in_array($c['code'], TEGH_R181_HIGH_DENOMINATION, true)) return 'Ordinary amounts in ' . $c['name'] . ' exceed Tegh\'s limit of 1,000,000,000 per amount, so it is not yet available as a base currency.';
    return null;
}

/** Why a currency cannot be used on documents, or null when it can. */
function tegh_r181_transaction_currency_problem(string $code): ?string
{
    $c = tegh_r181_currency($code);
    if ($c === null) return 'This is not a current ISO 4217 currency code.';
    if ($c['minorUnits'] === 3) return $c['name'] . ' has three decimal places, which Tegh cannot yet store exactly.';
    return null;
}

/** The usual currency of a country (ISO alpha-2 or English name), or null. */
function tegh_r181_country_currency(?string $country): ?string
{
    $code = function_exists('tegh_country_code') ? tegh_country_code((string)$country) : null;
    if ($code === null) return null;
    return tegh_r181_country_currency_table()[$code] ?? null;
}

/** The currency list for the browser: every current ISO 4217 currency with what it can be used for. */
function tegh_r181_catalog(): array
{
    $out = [];
    foreach (tegh_r181_currency_table() as $code => [$name, $minor]) {
        $base = tegh_r181_base_currency_problem($code);
        $doc = tegh_r181_transaction_currency_problem($code);
        $out[] = ['code' => $code, 'name' => $name, 'minorUnits' => $minor, 'base' => $base === null, 'baseProblem' => $base, 'documents' => $doc === null, 'documentsProblem' => $doc];
    }
    return $out;
}

/* ---------- schema (added on first use, outside any transaction; same pattern as R137, R141, R153 and R174) ---------- */

function tegh_r181_schema_ready(): bool
{
    static $ready = null;
    if ($ready === true) return true;
    try {
        $probe = (int)db()->query("SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='company_exchange_rates')
            + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='companies' AND column_name IN ('fx_rate_policy','fx_rate_max_age_days'))")->fetchColumn();
        if ($probe === 3) return $ready = true;
        if (db()->inTransaction()) return $ready = false;
        db()->exec("CREATE TABLE IF NOT EXISTS company_exchange_rates (
          id VARCHAR(64) NOT NULL PRIMARY KEY,
          company_id VARCHAR(64) NOT NULL,
          currency_code CHAR(3) NOT NULL,
          rate_date DATE NOT NULL,
          rate_to_base_micros BIGINT NOT NULL,
          base_currency CHAR(3) NOT NULL,
          source VARCHAR(20) NOT NULL DEFAULT 'manual',
          note VARCHAR(200) NULL,
          created_by VARCHAR(64) NULL,
          updated_by VARCHAR(64) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY company_exchange_rates_day (company_id, currency_code, rate_date),
          KEY company_exchange_rates_lookup (company_id, currency_code, rate_date),
          CONSTRAINT company_exchange_rates_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
          CONSTRAINT company_exchange_rates_rate_ck CHECK (rate_to_base_micros > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!schema_column_exists('companies', 'fx_rate_policy')) db()->exec("ALTER TABLE companies ADD COLUMN fx_rate_policy VARCHAR(10) NOT NULL DEFAULT 'current'");
        if (!schema_column_exists('companies', 'fx_rate_max_age_days')) db()->exec('ALTER TABLE companies ADD COLUMN fx_rate_max_age_days TINYINT UNSIGNED NOT NULL DEFAULT ' . TEGH_R181_DEFAULT_MAX_AGE_DAYS);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Tegh R181 schema not ready: ' . $e->getMessage());
        return $ready = false;
    }
}

/** 'dated' or 'current' (R180 behaviour). */
function tegh_r181_policy(array $company): string
{
    return (string)($company['fx_rate_policy'] ?? 'current') === 'dated' ? 'dated' : 'current';
}

function tegh_r181_max_age_days(array $company): int
{
    return max(0, min(31, (int)($company['fx_rate_max_age_days'] ?? TEGH_R181_DEFAULT_MAX_AGE_DAYS)));
}

/** Today in the company's time zone (R157: the company's chosen zone, Toronto when none). */
function tegh_r181_company_today(array $company): string
{
    return canadian_today();
}

/* ---------- rates ---------- */

/** The recorded rate that applies on $date: that day's, else the latest earlier one within the allowed age. */
function tegh_r181_recorded_rate(array $company, string $currency, string $date): ?array
{
    if (!tegh_r181_schema_ready()) return null;
    $oldest = (new DateTimeImmutable($date))->modify('-' . tegh_r181_max_age_days($company) . ' days')->format('Y-m-d');
    $s = db()->prepare('SELECT currency_code, rate_date, rate_to_base_micros, source FROM company_exchange_rates
        WHERE company_id = ? AND currency_code = ? AND rate_date <= ? AND rate_date >= ? ORDER BY rate_date DESC LIMIT 1');
    $s->execute([(string)$company['id'], $currency, $date, $oldest]);
    $r = $s->fetch();
    return $r ? ['currency' => (string)$r['currency_code'], 'rateDate' => (string)$r['rate_date'], 'rateToBaseMicros' => (int)$r['rate_to_base_micros'], 'source' => (string)$r['source']] : null;
}

function tegh_r181_rate_text(int $micros): string
{
    return number_format($micros / 1_000_000, 6, '.', ',');
}

function tegh_r181_date_text(string $date): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d ? $d->format('j M Y') : $date;
}

/**
 * The exchange rate for one foreign-currency document. Every place that converts a document goes through here.
 *
 * Under the R180 rule ('current') this is exactly the old calculation: the rate sent with the document, or else the
 * company's current rate for the currency.
 * Under the 'dated' rule the recorded rate for the date is used. The browser's default (the company's current rate) is
 * treated as "no rate typed". A different rate needs $input['exchangeRateConfirmed'] === true.
 */
function tegh_r181_document_rate(array $company, string $currency, string $date, mixed $inputRate, ?array $currencyRow, array $input = [], string $label = 'document'): int
{
    $base = (string)$company['currency'];
    if ($currency === $base) return 1_000_000;
    if (tegh_r181_policy($company) !== 'dated') {
        return safe_exchange_rate_micros($inputRate ?? ($currencyRow['rate_to_base_micros'] ?? null), $currency, $base);
    }
    $recorded = tegh_r181_recorded_rate($company, $currency, $date);
    if ($recorded === null) {
        $age = tegh_r181_max_age_days($company);
        fail('There is no ' . $currency . ' exchange rate recorded for ' . tegh_r181_date_text($date) . ($age > 0 ? ' or the ' . $age . ' days before it' : '')
            . '. Record the rate under Settings › Currency Exchange Rates, then save the ' . $label . ' again.', 422, 'fx_rate_missing', false);
    }
    $GLOBALS['tegh_r181_last_rate'] = $recorded + ['used' => $recorded['rateToBaseMicros'], 'entered' => false];
    if ($inputRate === null || $inputRate === '') return $recorded['rateToBaseMicros'];
    $typed = safe_exchange_rate_micros($inputRate, $currency, $base);
    $current = (int)($currencyRow['rate_to_base_micros'] ?? 0);
    if ($typed === $recorded['rateToBaseMicros'] || ($typed === $current && empty($input['exchangeRateTyped']))) return $recorded['rateToBaseMicros'];
    if (($input['exchangeRateConfirmed'] ?? false) !== true) {
        fail('The ' . $currency . ' rate recorded for ' . tegh_r181_date_text($recorded['rateDate']) . ' is ' . tegh_r181_rate_text($recorded['rateToBaseMicros']) . ' ' . $base
            . '. This ' . $label . ' uses ' . tegh_r181_rate_text($typed) . ' ' . $base . '. Use the recorded rate, or confirm the rate on the ' . $label . '.', 409, 'fx_rate_differs', false);
    }
    if ($typed < TEGH_R181_MIN_RATE_MICROS) fail(tegh_r181_precision_message($currency, $base), 422, 'fx_rate_precision', false);
    $GLOBALS['tegh_r181_last_rate'] = $recorded + ['used' => $typed, 'entered' => true];
    // The person confirmed a rate other than the recorded one: keep both in the audit trail.
    audit_event(require_user(), (string)$company['id'], 'currency.rate_override_confirmed', 'company', (string)$company['id'], [
        'document' => $label, 'currency' => $currency, 'documentDate' => $date, 'recordedRateMicros' => $recorded['rateToBaseMicros'],
        'recordedRateDate' => $recorded['rateDate'], 'usedRateMicros' => $typed, 'baseCurrency' => $base,
    ]);
    return $typed;
}

/** Audit details for the rate used by the last tegh_r181_document_rate() call (empty under the R180 rule). */
function tegh_r181_rate_audit(): array
{
    $r = $GLOBALS['tegh_r181_last_rate'] ?? null;
    if (!is_array($r)) return [];
    return ['fxRate' => ['used' => $r['used'], 'recorded' => $r['rateToBaseMicros'], 'recordedDate' => $r['rateDate'], 'entered' => $r['entered']]];
}

function tegh_r181_precision_message(string $currency, string $base): string
{
    return '1 ' . $currency . ' is worth less than 0.005 ' . $base . '. Tegh stores rates to 6 decimal places, so this rate would lose accuracy. '
        . 'This currency pair is not yet supported.';
}

/** Validate one rate entry. Returns [currency, date, micros]. */
function tegh_r181_rate_input(array $company, mixed $currency, mixed $date, mixed $micros): array
{
    $base = (string)$company['currency'];
    $code = strtoupper(trim((string)$currency));
    if ($problem = tegh_r181_transaction_currency_problem($code)) fail($code . ': ' . $problem, 422, 'currency_unsupported');
    if ($code === $base) fail($code . ' is the base currency; its rate is always 1.', 422, 'currency_is_base');
    $day = safe_date($date, 'Rate date');
    if ($day > tegh_r181_company_today($company)) fail('A rate date cannot be in the future (' . $day . ').', 422, 'rate_date_future');
    $rate = safe_exchange_rate_micros($micros, $code, $base);
    if ($rate < TEGH_R181_MIN_RATE_MICROS) fail(tegh_r181_precision_message($code, $base), 422, 'fx_rate_precision');
    return [$code, $day, $rate];
}

/** Record (or correct) rates. Keeps company_currencies' "current rate" = the latest recorded one. Inside a transaction. */
function tegh_r181_save_rates(array $user, array $company, array $rows, string $source = 'manual'): array
{
    $companyId = (string)$company['id'];
    $base = (string)$company['currency'];
    $find = db()->prepare('SELECT id, rate_to_base_micros FROM company_exchange_rates WHERE company_id=? AND currency_code=? AND rate_date=?');
    $ins = db()->prepare('INSERT INTO company_exchange_rates (id, company_id, currency_code, rate_date, rate_to_base_micros, base_currency, source, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?,?)');
    $upd = db()->prepare('UPDATE company_exchange_rates SET rate_to_base_micros=?, source=?, updated_by=? WHERE id=?');
    $saved = []; $touched = [];
    foreach ($rows as [$code, $day, $rate]) {
        $find->execute([$companyId, $code, $day]);
        $old = $find->fetch();
        if ($old) {
            if ((int)$old['rate_to_base_micros'] !== $rate) {
                $upd->execute([$rate, $source, (string)$user['id'], (string)$old['id']]);
                audit_event($user, $companyId, 'currency.rate_corrected', 'company_exchange_rate', (string)$old['id'], ['currency' => $code, 'rateDate' => $day, 'from' => (int)$old['rate_to_base_micros'], 'to' => $rate, 'baseCurrency' => $base]);
            }
        } else {
            $id = new_id('fxrate');
            $ins->execute([$id, $companyId, $code, $day, $rate, $base, $source, (string)$user['id'], (string)$user['id']]);
            audit_event($user, $companyId, 'currency.rate_recorded', 'company_exchange_rate', $id, ['currency' => $code, 'rateDate' => $day, 'rateToBaseMicros' => $rate, 'baseCurrency' => $base, 'source' => $source]);
        }
        $touched[$code] = true;
        $saved[] = ['currency' => $code, 'rateDate' => $day, 'rateToBaseMicros' => $rate];
    }
    $latest = db()->prepare('SELECT rate_date, rate_to_base_micros FROM company_exchange_rates WHERE company_id=? AND currency_code=? ORDER BY rate_date DESC LIMIT 1');
    $cur = db()->prepare("INSERT INTO company_currencies (company_id, currency_code, rate_to_base_micros, rate_date, active) VALUES (?,?,?,?,1)
        ON DUPLICATE KEY UPDATE rate_to_base_micros=VALUES(rate_to_base_micros), rate_date=VALUES(rate_date), active=1");
    foreach (array_keys($touched) as $code) {
        $latest->execute([$companyId, $code]);
        if ($l = $latest->fetch()) $cur->execute([$companyId, $code, (int)$l['rate_to_base_micros'], (string)$l['rate_date']]);
    }
    return $saved;
}

function tegh_r181_rates_list(array $company, ?string $currency, int $limit = 400): array
{
    if (!tegh_r181_schema_ready()) return [];
    $sql = 'SELECT currency_code, rate_date, rate_to_base_micros, source, updated_at FROM company_exchange_rates WHERE company_id=?';
    $args = [(string)$company['id']];
    if ($currency) { $sql .= ' AND currency_code=?'; $args[] = $currency; }
    $s = db()->prepare($sql . ' ORDER BY rate_date DESC, currency_code LIMIT ' . max(1, min(2000, $limit)));
    $s->execute($args);
    return array_map(static fn(array $r): array => ['currency' => (string)$r['currency_code'], 'rateDate' => (string)$r['rate_date'], 'rateToBaseMicros' => (int)$r['rate_to_base_micros'], 'source' => (string)$r['source'], 'updatedAt' => (string)$r['updated_at']], $s->fetchAll());
}

/** Settings for the browser (workspace payload). */
function tegh_r181_company_settings(array $company, ?array $user): array
{
    return [
        'available' => tegh_r181_available_for($user),
        'policy' => tegh_r181_policy($company),
        'maxAgeDays' => tegh_r181_max_age_days($company),
        'today' => tegh_r181_company_today($company),
    ];
}

/* ---------- route: currencies/* ---------- */

function handle_currencies_r181(string $sub): never
{
    $user = require_user();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($sub === 'catalog' && $method === 'GET') {
        // Needed before a company exists (creating the first company), so no company is required.
        json_response(['currencies' => tegh_r181_catalog(), 'available' => tegh_r181_available_for($user), 'countryCurrency' => tegh_r181_country_currency_table(), 'legacy' => TEGH_R181_LEGACY_CURRENCIES]);
    }
    $company = require_company($user);
    if (!tegh_r181_schema_ready()) fail('Exchange rate history is not available on this server yet (the database could not be updated).', 503, 'fx_schema_unavailable');
    $company = require_company($user); // re-read with the R181 columns
    if ($sub === 'rate' && $method === 'GET') {
        $currency = strtoupper(trim((string)($_GET['currency'] ?? '')));
        $date = safe_date($_GET['date'] ?? '', 'Date');
        $settings = tegh_r181_company_settings($company, $user);
        if ($currency === (string)$company['currency']) json_response(['found' => true, 'rateToBaseMicros' => 1_000_000, 'rateDate' => $date, 'policy' => $settings['policy']]);
        if ($settings['policy'] !== 'dated') {
            $row = company_currency((string)$company['id'], $currency);
            json_response(['found' => (bool)$row, 'rateToBaseMicros' => $row ? (int)$row['rate_to_base_micros'] : null, 'rateDate' => $row ? (string)$row['rate_date'] : null, 'policy' => 'current']);
        }
        $r = tegh_r181_recorded_rate($company, $currency, $date);
        json_response(['found' => $r !== null, 'rateToBaseMicros' => $r['rateToBaseMicros'] ?? null, 'rateDate' => $r['rateDate'] ?? null, 'policy' => 'dated', 'maxAgeDays' => $settings['maxAgeDays']]);
    }
    if ($sub === 'rates' && $method === 'GET') {
        $currency = trim((string)($_GET['currency'] ?? '')) ?: null;
        json_response(['rates' => tegh_r181_rates_list($company, $currency ? strtoupper($currency) : null), 'settings' => tegh_r181_company_settings($company, $user), 'baseCurrency' => (string)$company['currency']]);
    }
    require_method('POST');
    require_csrf();
    require_company_permission($company, 'company.settings');
    $input = request_json();
    if ($sub === 'rates') {
        // Several rates at once: [{currency, rateDate, rateToBaseMicros}]
        $rows = $input['rates'] ?? null;
        if (!is_array($rows) || !$rows) fail('Send at least one rate.', 422, 'rates_required');
        if (count($rows) > 1000) fail('Send at most 1,000 rates at a time.', 422, 'rates_too_many');
        $clean = [];
        foreach (array_values($rows) as $i => $r) {
            if (!is_array($r)) fail('Rate ' . ($i + 1) . ' is not valid.', 422, 'rate_invalid');
            $clean[] = tegh_r181_rate_input($company, $r['currency'] ?? '', $r['rateDate'] ?? '', $r['rateToBaseMicros'] ?? null);
        }
        if (tegh_r181_policy($company) !== 'dated') fail('Turn on "Use the rate recorded for each date" before entering rates by date.', 409, 'fx_policy_current');
        foreach ($clean as [$code]) if (!tegh_r181_available_for($user) && !in_array($code, TEGH_R181_LEGACY_CURRENCIES, true)) fail($code . ' is not available yet.', 403, 'currency_not_available');
        $saved = db_transaction_retry(static fn(): array => tegh_r181_save_rates($user, $company, $clean, (string)($input['source'] ?? 'manual') === 'paste' ? 'paste' : 'manual'));
        json_response(['saved' => count($saved), 'rates' => $saved], 201);
    }
    if ($sub === 'policy') {
        $policy = (string)($input['policy'] ?? '');
        if (!in_array($policy, ['current', 'dated'], true)) fail('Choose how rates are applied.', 422, 'fx_policy_invalid');
        if ($policy === 'dated' && !tegh_r181_available_for($user) && tegh_r181_policy($company) !== 'dated') fail('Rates by date are not available yet.', 403, 'fx_not_available');
        $age = (int)($input['maxAgeDays'] ?? tegh_r181_max_age_days($company));
        if ($age < 0 || $age > 31) fail('Choose between 0 and 31 days.', 422, 'fx_age_invalid');
        $was = tegh_r181_policy($company);
        db_transaction_retry(static function () use ($user, $company, $policy, $age, $was): void {
            $companyId = (string)$company['id'];
            db()->prepare('UPDATE companies SET fx_rate_policy=?, fx_rate_max_age_days=? WHERE id=?')->execute([$policy, $age, $companyId]);
            $seeded = 0;
            if ($policy === 'dated' && $was !== 'dated') {
                // Keep each currency's current rate as a dated rate, so documents on or shortly after that date convert as before.
                $s = db()->prepare('SELECT currency_code, rate_to_base_micros, rate_date FROM company_currencies WHERE company_id=? AND active=1 AND currency_code<>?');
                $s->execute([$companyId, (string)$company['currency']]);
                $ins = db()->prepare('INSERT IGNORE INTO company_exchange_rates (id, company_id, currency_code, rate_date, rate_to_base_micros, base_currency, source, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?,?)');
                foreach ($s->fetchAll() as $r) { $ins->execute([new_id('fxrate'), $companyId, (string)$r['currency_code'], (string)$r['rate_date'], (int)$r['rate_to_base_micros'], (string)$company['currency'], 'current-rate', (string)$user['id'], (string)$user['id']]); $seeded += $ins->rowCount(); }
            }
            audit_event($user, $companyId, 'currency.policy_changed', 'company', $companyId, ['from' => $was, 'to' => $policy, 'maxAgeDays' => $age, 'seededRates' => $seeded]);
        });
        json_response(['settings' => tegh_r181_company_settings(require_company($user), $user)]);
    }
    fail('Unknown currency request.', 404, 'not_found');
}

/* ---------- exact conversion ---------- */

/** foreign × rate / 1,000,000 rounded half away from zero, without integer overflow. Same result as R180 where R180 did not overflow. */
function tegh_r181_convert_exact(int $foreignCents, int $rateMicros): int
{
    $sign = ($foreignCents < 0) !== ($rateMicros < 0) ? -1 : 1;
    $a = abs($foreignCents); $r = abs($rateMicros);
    $hi = intdiv($a, 1_000_000); $lo = $a % 1_000_000;
    $whole = $hi * $r;                       // a = hi·10^6 + lo  ⇒  a·r/10^6 = hi·r + lo·r/10^6
    $rest = $lo * $r;                        // lo < 10^6 and r ≤ 10^12, so this stays below 2^63
    $q = intdiv($rest, 1_000_000); $rem = $rest % 1_000_000;
    if ($rem * 2 >= 1_000_000) $q++;
    return $sign * ($whole + $q);
}

/* ---------- bank statements: one rate per transaction date (dated rule only) ---------- */

/**
 * Called once per statement. Under the R180 rule, or when the person typed and confirmed one rate for the whole
 * statement, every row uses $legacyRate as before and null is returned. Otherwise each row is converted at the rate
 * recorded for its own date (see tegh_r181_statement_row_rate) and the rate to store on the statement is returned.
 */
function tegh_r181_statement_begin(array $company, string $currency, mixed $inputRate, ?array $currencyRow, array $input, int $legacyRate): ?int
{
    $GLOBALS['tegh_r181_statement'] = null;
    if ($currency === (string)$company['currency'] || tegh_r181_policy($company) !== 'dated') return null;
    $current = (int)($currencyRow['rate_to_base_micros'] ?? 0);
    $typed = ($inputRate === null || $inputRate === '') ? null : safe_exchange_rate_micros($inputRate, $currency, (string)$company['currency']);
    $confirmed = in_array($input['exchangeRateConfirmed'] ?? false, [true, 'true', '1', 1], true);
    if ($typed !== null && $typed !== $current && $confirmed) {
        if ($typed < TEGH_R181_MIN_RATE_MICROS) fail(tegh_r181_precision_message($currency, (string)$company['currency']), 422, 'fx_rate_precision', false);
        return null; // one confirmed rate for the whole statement, as typed
    }
    if ($typed !== null && $typed !== $current && !$confirmed) {
        fail('This ' . $currency . ' statement uses one rate (' . tegh_r181_rate_text($typed) . ' ' . $company['currency'] . ') for every transaction. '
            . 'Tegh converts each transaction at the rate recorded for its date. Clear the rate to use the recorded rates, or confirm the single rate.', 409, 'fx_rate_differs', false);
    }
    $GLOBALS['tegh_r181_statement'] = ['company' => $company, 'currency' => $currency, 'cache' => []];
    return $current > 0 ? $current : $legacyRate;
}

/** For a statement preview being edited: per-row rates again if it was created that way. */
function tegh_r181_statement_resume(array $company, string $currency, array $rows): void
{
    $GLOBALS['tegh_r181_statement'] = null;
    $first = $rows[0] ?? null;
    if (is_array($first) && array_key_exists('exchangeRateMicros', $first) && tegh_r181_policy($company) === 'dated' && $currency !== (string)$company['currency']) {
        $GLOBALS['tegh_r181_statement'] = ['company' => $company, 'currency' => $currency, 'cache' => []];
    }
}

function tegh_r181_statement_active(): bool
{
    return is_array($GLOBALS['tegh_r181_statement'] ?? null);
}

/** The rate for one statement row: the statement's rate, or (dated rule) the rate recorded for the row's date. */
function tegh_r181_statement_row_rate(string $date, int $statementRate): int
{
    $st = &$GLOBALS['tegh_r181_statement'];
    if (!is_array($st)) return $statementRate;
    if (!isset($st['cache'][$date])) {
        $r = tegh_r181_recorded_rate($st['company'], $st['currency'], $date);
        if ($r === null) {
            $age = tegh_r181_max_age_days($st['company']);
            fail('There is no ' . $st['currency'] . ' exchange rate recorded for ' . tegh_r181_date_text($date) . ($age > 0 ? ' or the ' . $age . ' days before it' : '')
                . ', which this statement needs for a transaction on that date. Record the rate under Settings › Currency Exchange Rates, then upload the statement again.', 422, 'fx_rate_missing', false);
        }
        $st['cache'][$date] = $r['rateToBaseMicros'];
    }
    return $st['cache'][$date];
}

/** Extra row fields under per-row rates (none otherwise, so R180 rows are unchanged). */
function tegh_r181_statement_row_fields(string $date, int $statementRate): array
{
    return tegh_r181_statement_active() ? ['exchangeRateMicros' => tegh_r181_statement_row_rate($date, $statementRate)] : [];
}

/** Workspace "organization" fields. Nothing while Worldwide currencies is off and the company is on the R180 rule. */
function tegh_r181_workspace_fields(array $company): array
{
    if (tegh_r181_mode() === 'off' && tegh_r181_policy($company) !== 'dated') return [];
    $c = tegh_r181_currency((string)$company['currency']);
    return ['fx' => ['policy' => tegh_r181_policy($company), 'maxAgeDays' => tegh_r181_max_age_days($company), 'today' => tegh_r181_company_today($company),
        'baseCurrencyName' => $c['name'] ?? (string)$company['currency'], 'timezone' => (string)($company['timezone'] ?? '')]];
}
