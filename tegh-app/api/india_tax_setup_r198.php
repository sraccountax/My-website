<?php
declare(strict_types=1);

/*
 * R198 (India only): Tax Set-up, laid out like the R197 payroll set-up: a list of tax heads, then who or what each applies to.
 * - GST rates: the usual slabs (R196) plus the company's own rates, each with a heading the person can rename.
 * - TDS sections: the common sections with Tegh's suggested rate and limits (each editable before it is confirmed), plus the
 *   company's own sections with their own heading. Ticking "parties without a PAN" adds <section>-NOPAN at that rate (206AA).
 * - TCS sections: the company's own sections only (TCS on sale of goods, 206C(1H), ended on 31 March 2025).
 * - Vendors and customers: PAN and TDS / TCS section, from a date. Products and services: HSN/SAC and GST rate, from a date.
 *   These are the same India profiles India Masters keeps (effective dated); other profile fields are kept as they are.
 * Rules are saved and approved through r196_confirm_rule (Owner or Admin), so a figure that was used is never rewritten.
 * Canadian companies never reach this file (404 india_only).
 */

require_once __DIR__ . '/india_setup_r196.php';

/** Common TDS sections, as suggestions only (figures from 1 April 2025). "single" = one invoice above; "yearly" = a party's year above. */
const R198_TDS_SUGGESTIONS = [
    ['code' => '194C-IND', 'section' => '194C', 'label' => 'Contractors: individual or HUF', 'rate' => '1', 'single' => 30000, 'yearly' => 100000, 'checked' => true],
    ['code' => '194C', 'section' => '194C', 'label' => 'Contractors: company, firm or others', 'rate' => '2', 'single' => 30000, 'yearly' => 100000, 'checked' => true],
    ['code' => '194J', 'section' => '194J', 'label' => 'Professional fees', 'rate' => '10', 'single' => 50000, 'yearly' => 50000, 'checked' => true],
    ['code' => '194J-TECH', 'section' => '194J', 'label' => 'Technical services, call centres, film royalty', 'rate' => '2', 'single' => 50000, 'yearly' => 50000, 'checked' => false],
    ['code' => '194H', 'section' => '194H', 'label' => 'Commission or brokerage', 'rate' => '2', 'single' => 20000, 'yearly' => 20000, 'checked' => false],
    ['code' => '194I', 'section' => '194I', 'label' => 'Rent: land, building or furniture (limit is per month)', 'rate' => '10', 'single' => 50000, 'yearly' => null, 'checked' => true],
    ['code' => '194I-PM', 'section' => '194I', 'label' => 'Rent: plant, machinery or equipment (limit is per month)', 'rate' => '2', 'single' => 50000, 'yearly' => null, 'checked' => false],
];
const R198_NOPAN_PERCENT = '20';

function r198_fixed_codes(): array { return array_column(R198_TDS_SUGGESTIONS, 'code'); }

/** Latest non-superseded version of each section code of a kind (without the -NOPAN companions). */
function r198_sections(string $companyId, string $kind): array
{
    $q = db()->prepare("SELECT rate_key, label, value_decimal, threshold_min_cents, threshold_max_cents, effective_from, status FROM statutory_rates
        WHERE company_id=? AND jurisdiction='IN' AND person_type=? AND status IN ('active','draft') ORDER BY rate_key, effective_from DESC");
    $q->execute([$companyId, $kind === 'tds' ? 'tds_section' : 'tcs_section']);
    $out = []; $noPan = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $code = (string)$r['rate_key'];
        if (str_ends_with($code, '-NOPAN')) { $noPan[substr($code, 0, -6)] ??= rtrim(rtrim((string)$r['value_decimal'], '0'), '.'); continue; }
        if (isset($out[$code])) continue;
        $out[$code] = ['code' => $code, 'label' => (string)$r['label'], 'ratePercent' => rtrim(rtrim((string)$r['value_decimal'], '0'), '.') ?: '0',
            'singleRupees' => $r['threshold_min_cents'] === null ? null : intdiv((int)$r['threshold_min_cents'], 100),
            'yearlyRupees' => $r['threshold_max_cents'] === null ? null : intdiv((int)$r['threshold_max_cents'], 100),
            'effectiveFrom' => (string)$r['effective_from'], 'status' => (string)$r['status'], 'fixed' => $kind === 'tds' && in_array($code, r198_fixed_codes(), true)];
    }
    foreach ($out as $c => &$s) $s['noPanPercent'] = $noPan[$c] ?? null;
    return array_values($out);
}

function r198_parties(string $companyId, string $entity): array
{
    $table = $entity === 'vendor' ? 'vendors' : 'customers'; $key = $entity === 'vendor' ? 'tdsSection' : 'tcsSection';
    $q = db()->prepare("SELECT id, name FROM $table WHERE company_id=? AND COALESCE(active,1)=1 ORDER BY name LIMIT 1000"); $q->execute([$companyId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $cur = in2_profile_current($companyId, $entity, (string)$p['id']);
        $out[] = ['id' => (string)$p['id'], 'name' => (string)$p['name'], 'pan' => (string)($cur['data']['pan'] ?? ''), 'section' => (string)($cur['data'][$key] ?? ''), 'effectiveFrom' => $cur['effectiveFrom']];
    }
    return $out;
}

function r198_items(string $companyId): array
{
    $q = db()->prepare('SELECT id, name, kind FROM products_services WHERE company_id=? AND COALESCE(active,1)=1 ORDER BY name LIMIT 1000'); $q->execute([$companyId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $cur = in2_profile_current($companyId, 'item', (string)$p['id']);
        $out[] = ['id' => (string)$p['id'], 'name' => (string)$p['name'], 'kind' => (string)$p['kind'], 'hsnSac' => (string)($cur['data']['hsnSac'] ?? ''), 'gstRate' => (string)($cur['data']['gstRate'] ?? ''), 'effectiveFrom' => $cur['effectiveFrom']];
    }
    return $out;
}

function r198_state(array $company): array
{
    $companyId = (string)$company['id']; in2_schema_ready(); in3_schema_ready();
    $role = (string)$company['role'];
    return ['gst' => r196_gst_state($company),
        'tds' => ['sections' => r198_sections($companyId, 'tds'), 'suggestions' => R198_TDS_SUGGESTIONS, 'noPanPercent' => R198_NOPAN_PERCENT],
        'tcs' => ['sections' => r198_sections($companyId, 'tcs')],
        'vendors' => r198_parties($companyId, 'vendor'), 'customers' => r198_parties($companyId, 'customer'), 'items' => r198_items($companyId),
        'effectiveFrom' => r196_default_from($company), 'today' => canadian_today(),
        'canSetUp' => in_array($role, ['owner', 'admin'], true), 'canManage' => in_array($role, ['owner', 'admin', 'editor'], true)];
}

/** Optional whole rupees (blank = no limit), as paise or ''. */
function r198_limit(mixed $v, string $label): string
{
    $t = str_replace([',', ' ', '₹'], '', trim((string)$v));
    return $t === '' ? '' : (string)r196_rupees($t, $label, true);
}

/** Save and approve the ticked TDS or TCS sections (and their -NOPAN companions). */
function r198_sections_apply(array $user, array $company, array $in): array
{
    r196_require_rules_role($company, 'TDS and TCS sections');
    $kind = (string)($in['kind'] ?? ''); if (!in_array($kind, ['tds', 'tcs'], true)) fail('Choose TDS or TCS.', 422, 'india_setup_kind');
    if (empty($in['confirmed'])) fail('Tick "I have checked these rates and limits" to confirm them.', 422, 'india_setup_confirm');
    $from = safe_date((string)($in['effectiveFrom'] ?? r196_default_from($company)), 'Effective from');
    $companyId = (string)$company['id'];
    $fixed = []; if ($kind === 'tds') foreach (R198_TDS_SUGGESTIONS as $s) $fixed[$s['code']] = $s;
    $existing = []; foreach (r198_sections($companyId, $kind) as $s) $existing[$s['code']] = $s;
    $rows = [];
    foreach ((array)($in['rows'] ?? []) as $r) {
        if (!is_array($r)) continue;
        $code = strtoupper(trim((string)($r['code'] ?? '')));
        if (!preg_match('/^[0-9A-Z][0-9A-Z.()\/-]{1,29}$/', $code) || str_ends_with($code, '-NOPAN')) fail('A section code uses letters, digits and . ( ) / - (for example 194A).', 422, 'india_setup_section_code');
        if (isset($rows[$code])) fail('Section ' . $code . ' is listed twice.', 422, 'india_setup_section_twice');
        // A fixed section keeps Tegh's heading; the person's own section keeps the heading it was created with (rename it separately).
        $label = isset($fixed[$code]) ? $fixed[$code]['label'] : ($existing[$code]['label'] ?? in2_text($r['label'] ?? '', 'Heading of section ' . $code, 120));
        $rows[$code] = ['category' => $kind . '_section', 'code' => $code, 'label' => $label, 'state' => '', 'effectiveFrom' => $from,
            'ratePercent' => r196_percent($r['ratePercent'] ?? '', 'The rate of ' . $code), 'amountCents' => '',
            'thresholdMinCents' => r198_limit($r['singleRupees'] ?? '', 'The invoice limit of ' . $code), 'thresholdMaxCents' => r198_limit($r['yearlyRupees'] ?? '', 'The yearly limit of ' . $code)];
    }
    if (!$rows) fail('Tick at least one section.', 422, 'india_setup_no_sections');
    $noPan = trim((string)($in['noPanPercent'] ?? '')); $noPan = $noPan === '' ? null : r196_percent($noPan, 'The rate for parties without a PAN');
    $source = 'Confirmed by ' . (string)$user['email'] . ' in Tax set-up' . ($kind === 'tds' ? ' (suggested by Tegh)' : '');
    $out = [];
    foreach ($rows as $code => $r) {
        $saved = r196_confirm_rule($user, $company, $r, $source);
        $np = null;
        if ($noPan !== null) $np = r196_confirm_rule($user, $company, ['label' => $r['label'] . ': party without PAN (206' . ($kind === 'tds' ? 'AA' : 'CC') . ')', 'code' => $code . '-NOPAN', 'ratePercent' => $noPan] + $r, $source);
        $out[] = ['code' => $code, 'rule' => $saved, 'noPan' => $np];
    }
    audit_event($user, $companyId, 'india.tax_sections_confirmed', 'company', $companyId, ['kind' => $kind, 'effectiveFrom' => $from, 'codes' => array_keys($rows), 'noPanPercent' => $noPan]);
    return ['sections' => $out, 'state' => r198_state($company)];
}

/** Rename the heading of the person's own GST rate or section (every version; the figures are not touched). */
function r198_label_save(array $user, array $company, array $in): array
{
    r196_require_rules_role($company, 'tax headings');
    $companyId = (string)$company['id'];
    $cat = (string)($in['category'] ?? ''); if (!in_array($cat, ['gst_rate', 'tds_section', 'tcs_section'], true)) fail('Choose a GST rate or a section.', 422, 'india_setup_kind');
    $code = trim((string)($in['code'] ?? ''));
    $label = in2_text($in['label'] ?? '', 'Heading', 120);
    if ($cat === 'tds_section' && in_array($code, r198_fixed_codes(), true)) fail('Standard sections keep their heading. Add your own section to use another heading.', 409, 'india_setup_fixed_heading');
    if ($cat === 'gst_rate' && in_array(substr($code, 3), array_column(R196_GST_SUGGESTIONS, 'rate'), true)) fail('Standard GST rates keep their heading.', 409, 'india_setup_fixed_heading');
    $q = db()->prepare("SELECT id, label FROM statutory_rates WHERE company_id=? AND jurisdiction='IN' AND person_type=? AND (rate_key=? OR rate_key=?)");
    $q->execute([$companyId, $cat, $code, $code . '-NOPAN']); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) fail('That rate or section is not set up.', 404, 'india_setup_heading_missing');
    $before = '';
    db()->beginTransaction();
    try {
        foreach ($rows as $r) {
            $isNoPan = str_ends_with((string)$r['label'], ')') && str_contains((string)$r['label'], ': party without PAN');
            if (!$isNoPan) $before = (string)$r['label'];
            db()->prepare('UPDATE statutory_rates SET label=? WHERE id=?')->execute([$isNoPan ? $label . substr((string)$r['label'], strrpos((string)$r['label'], ': party without PAN')) : $label, $r['id']]);
        }
        audit_event($user, $companyId, 'india.tax_heading_renamed', 'statutory_rate', $code, ['category' => $cat, 'before' => $before, 'after' => $label]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['code' => $code, 'label' => $label];
}

/** A profile change from a date, keeping every other field of the profile in force on that date. */
function r198_profile_merge(array $user, array $company, string $entity, string $id, string $from, array $set): array
{
    $cur = in2_profile_current((string)$company['id'], $entity, $id, $from)['data'];
    foreach ($set as $k => $v) { if ($v === '' || $v === null) unset($cur[$k]); else $cur[$k] = $v; }
    return in2_profile_save($user, $company, ['entity' => $entity, 'entityId' => $id, 'effectiveFrom' => $from, 'data' => $cur]);
}

function r198_party_save(array $user, array $company, array $in): array
{
    if (!in_array((string)$company['role'], ['owner', 'admin', 'editor'], true)) fail('Your role can view tax details but not change them.', 403, 'india_write_forbidden');
    $entity = (string)($in['entity'] ?? ''); if (!in_array($entity, ['vendor', 'customer'], true)) fail('Choose a vendor or a customer.', 422, 'india_setup_entity');
    $id = clean_text($in['id'] ?? '', 'Record', 64);
    $from = safe_date((string)($in['effectiveFrom'] ?? canadian_today()), 'From');
    $pan = strtoupper(trim((string)($in['pan'] ?? '')));
    $saved = r198_profile_merge($user, $company, $entity, $id, $from, ['pan' => $pan, $entity === 'vendor' ? 'tdsSection' : 'tcsSection' => trim((string)($in['section'] ?? ''))]);
    return ['saved' => $saved, 'state' => r198_state($company)];
}

function r198_item_save(array $user, array $company, array $in): array
{
    if (!in_array((string)$company['role'], ['owner', 'admin', 'editor'], true)) fail('Your role can view tax details but not change them.', 403, 'india_write_forbidden');
    $id = clean_text($in['id'] ?? '', 'Product or service', 64);
    $from = safe_date((string)($in['effectiveFrom'] ?? canadian_today()), 'From');
    $saved = r198_profile_merge($user, $company, 'item', $id, $from, ['hsnSac' => strtoupper(trim((string)($in['hsnSac'] ?? ''))), 'gstRate' => trim((string)($in['gstRate'] ?? ''))]);
    return ['saved' => $saved, 'state' => r198_state($company)];
}
