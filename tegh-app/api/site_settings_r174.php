<?php
declare(strict_types=1);

/*
 * R174: Site & Release Settings — what the platform owner can change without a new release.
 *
 *  - release stage (beta or production), the top-bar notice, the version label and the "Beta" feature labels;
 *  - business (operator) details shown on the policy pages, over the config.php values;
 *  - wording on the public pages that mentions the beta;
 *  - published versions of the Privacy Policy, Terms, Security Statement, Cookies, DPA and Subprocessors pages.
 *
 * Every change is platform-owner only, needs a recent sign-in, a reason and the revision the owner saw, and is kept
 * in site_setting_history and the platform audit log. Public sign-up stays in platform_release_settings (R159/R160).
 * Policy text is stored as plain text with a small Markdown subset; browsers render it as text, never as HTML.
 */

const R174_POLICY_KEYS = [
    'privacy' => ['title' => 'Privacy Policy', 'page' => '/privacy.html'],
    'terms' => ['title' => 'Terms of Use', 'page' => '/terms.html'],
    'security' => ['title' => 'Security Statement', 'page' => '/security.html'],
    'cookies' => ['title' => 'Cookies & Privacy Choices', 'page' => '/cookies.html'],
    'dpa' => ['title' => 'Data Processing Addendum', 'page' => '/dpa.html'],
    'subprocessors' => ['title' => 'Subprocessors', 'page' => '/subprocessors.html'],
];

/* Wording on the public pages that mentions the beta. The default is the text in the page itself; an owner value
   replaces it, and "hidden" removes the element. Pages carry data-site-text="<key>". */
const R174_SITE_TEXT = [
    'footer_note' => ['label' => 'Footer line under the TEGH name (every page)', 'default' => 'Free during beta · no automatic paid conversion', 'max' => 200],
    'footer_legal' => ['label' => 'Footer policy note (every page)', 'default' => 'Policy drafts v2026-10-21-beta.1 · owner/legal review required before production publication', 'max' => 200],
    'terms_link' => ['label' => 'Footer link to the Terms', 'default' => 'Beta terms', 'max' => 60],
    'home_note' => ['label' => 'Home page: line under the buttons', 'default' => 'Free during beta · express choice required for future paid plans', 'max' => 200],
    'pricing_eyebrow' => ['label' => 'Pricing card: small label', 'default' => 'Beta pricing', 'max' => 60],
    'pricing_title' => ['label' => 'Pricing: heading', 'default' => 'Free during beta', 'max' => 120],
    'pricing_lead' => ['label' => 'Pricing page: line under the heading', 'default' => 'Simple now, explicit later. No automatic paid conversion.', 'max' => 300],
    'pricing_body' => ['label' => 'Pricing card: paragraphs (one per line)', 'default' => "TEGH is currently in beta and free for users.\nAt least 30 days’ notice will be provided before introducing paid plans.\nA customer must expressly choose a paid subscription. There is no automatic charging or automatic paid conversion, and no beta end date has been set.", 'max' => 2000, 'multiline' => true],
    'pricing_terms_button' => ['label' => 'Pricing card: Terms button', 'default' => 'Read beta terms', 'max' => 60],
    'faq_cost_q' => ['label' => 'FAQ: cost question', 'default' => 'What does beta cost?', 'max' => 160],
    'faq_cost_a' => ['label' => 'FAQ: cost answer', 'default' => 'TEGH is currently in beta and free for users. At least 30 days’ notice will be provided before paid plans. You must expressly choose a paid subscription.', 'max' => 600],
    'company_lead' => ['label' => 'Company page: line under the heading', 'default' => 'A worldwide-access beta with a Canadian accounting focus.', 'max' => 300],
    'guides_access_link' => ['label' => 'Accounting guides: "Beta access" links', 'default' => 'Beta access', 'max' => 60],
    'guides_footer_tag' => ['label' => 'Accounting guides: footer line', 'default' => 'Canadian accounting & payroll · Private beta', 'max' => 200],
    'guides_footer_note' => ['label' => 'Accounting guides: footer note', 'default' => 'Private beta. Access by invitation. No certifications implied.', 'max' => 200],
];

const R174_GROUPS = ['release', 'labels', 'operator', 'site_text'];

function r174_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        // Creating a table commits any open transaction in MySQL. Sign-up and invitation acceptance read the policy
        // versions inside their transactions, so inside a transaction only look; create the tables outside one.
        $exists = db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('site_settings','site_setting_history','site_policy_versions')")->fetchColumn();
        if ((int)$exists === 3) return $ready = true;
        if (db()->inTransaction()) return false;   // not cached: a later call outside the transaction creates them
        db()->exec("CREATE TABLE IF NOT EXISTS site_settings (
 setting_group VARCHAR(40) NOT NULL PRIMARY KEY,
 value_json MEDIUMTEXT NOT NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_by VARCHAR(64) NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS site_setting_history (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 setting_group VARCHAR(40) NOT NULL,
 old_json MEDIUMTEXT NULL,
 new_json MEDIUMTEXT NOT NULL,
 actor_user_id VARCHAR(64) NOT NULL,
 reason VARCHAR(1000) NOT NULL,
 request_reference VARCHAR(80) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY site_setting_history_group_time (setting_group, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS site_policy_versions (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 policy_key VARCHAR(32) NOT NULL,
 version VARCHAR(40) NOT NULL,
 title VARCHAR(160) NOT NULL,
 lead_text VARCHAR(400) NOT NULL DEFAULT '',
 effective_date DATE NOT NULL,
 body_text MEDIUMTEXT NOT NULL,
 review_banner TINYINT(1) NOT NULL DEFAULT 0,
 banner_text VARCHAR(300) NOT NULL DEFAULT '',
 status VARCHAR(16) NOT NULL DEFAULT 'draft',
 created_by VARCHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 published_by VARCHAR(64) NULL,
 published_at DATETIME NULL,
 publish_reason VARCHAR(1000) NULL,
 UNIQUE KEY site_policy_key_version (policy_key, version),
 KEY site_policy_key_status (policy_key, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ready = true;
    } catch (Throwable) {
        $ready = false;
    }
    return $ready;
}

/* ---------- defaults and reading ---------- */

function r174_defaults(string $group): array
{
    if ($group === 'release') return ['stage' => 'beta'];
    if ($group === 'labels') {
        $notice = mb_substr(trim((string)(config('app.beta_notice') ?? '')), 0, 300);
        return ['noticeEnabled' => $notice !== '', 'noticeLabel' => 'Private beta · sample data only', 'noticeText' => $notice,
            'showVersion' => true, 'showBetaLabels' => true];
    }
    if ($group === 'operator') return [];   // empty fields fall back to config.php
    return [];                              // site_text: empty means the page's own wording
}

/** A group's stored value merged over its defaults, with the revision (0 when never saved). */
function r174_group(string $group): array
{
    $cache = &$GLOBALS['r174_group_cache'];
    if (!is_array($cache)) $cache = [];
    if (isset($cache[$group])) return $cache[$group];
    $value = r174_defaults($group);
    $revision = 0;
    if (r174_schema_ready()) {
        try {
            $s = db()->prepare('SELECT value_json, revision FROM site_settings WHERE setting_group=?');
            $s->execute([$group]);
            if ($r = $s->fetch()) {
                $stored = json_decode((string)$r['value_json'], true);
                if (is_array($stored)) $value = array_replace($value, $stored);
                $revision = (int)$r['revision'];
            }
        } catch (Throwable) {
        }
    }
    return $cache[$group] = ['value' => $value, 'revision' => $revision];
}

function r174_release_stage(): string
{
    return r174_group('release')['value']['stage'] === 'production' ? 'production' : 'beta';
}

/** Flags the signed-in app needs (auth/me). */
function r174_app_flags(): array
{
    $l = r174_group('labels')['value'];
    $enabled = !empty($l['noticeEnabled']) && trim((string)$l['noticeText']) !== '';
    return [
        'stage' => r174_release_stage(),
        'notice' => ['enabled' => $enabled, 'label' => (string)$l['noticeLabel'], 'text' => $enabled ? (string)$l['noticeText'] : ''],
        'showVersion' => !empty($l['showVersion']),
        'showBetaLabels' => !empty($l['showBetaLabels']),
    ];
}

/** Owner values for operator fields; blank fields fall back to config.php (legal_r156). */
function r174_operator_overrides(): array
{
    return r174_group('operator')['value'];
}

/** Wording overrides for the public pages: key => ['text' => ..., 'hidden' => bool]. */
function r174_site_text(): array
{
    $out = [];
    foreach (r174_group('site_text')['value'] as $key => $item) {
        if (!isset(R174_SITE_TEXT[$key]) || !is_array($item)) continue;
        $text = (string)($item['text'] ?? '');
        $hidden = !empty($item['hidden']);
        if ($text === '' && !$hidden) continue;
        $out[$key] = ['text' => $text, 'hidden' => $hidden];
    }
    return $out;
}

/* ---------- policies ---------- */

function r174_forget_cache(): void
{
    $GLOBALS['r174_group_cache'] = [];
}

function r174_published_policy(string $key): ?array
{
    if (!isset(R174_POLICY_KEYS[$key]) || !r174_schema_ready()) return null;
    try {
        $s = db()->prepare("SELECT * FROM site_policy_versions WHERE policy_key=? AND status='published' ORDER BY published_at DESC, id DESC LIMIT 1");
        $s->execute([$key]);
        $r = $s->fetch();
        return $r ?: null;
    } catch (Throwable) {
        return null;
    }
}

/** The Terms and Privacy versions a person accepts: the published version, or the built-in pages' version. */
function tegh_terms_version(): string
{
    return (string)(r174_published_policy('terms')['version'] ?? TEGH_TERMS_VERSION);
}

function tegh_privacy_version(): string
{
    return (string)(r174_published_policy('privacy')['version'] ?? TEGH_PRIVACY_VERSION);
}

/** {{placeholders}} in policy text, filled from the operator details. Unknown names are left as written. */
function r174_fill_placeholders(string $text): string
{
    $details = function_exists('tegh_operator_details') ? tegh_operator_details() : [];
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($details): string {
        $v = $details[$m[1]] ?? null;
        return $v === null ? $m[0] : (string)$v;
    }, $text) ?? $text;
}

function r174_policy_public_payload(array $row): array
{
    return [
        'key' => (string)$row['policy_key'],
        'version' => (string)$row['version'],
        'title' => r174_fill_placeholders((string)$row['title']),
        'lead' => r174_fill_placeholders((string)$row['lead_text']),
        'effectiveDate' => (string)$row['effective_date'],
        'body' => r174_fill_placeholders((string)$row['body_text']),
        'reviewBanner' => (bool)$row['review_banner'],
        'bannerText' => (string)$row['banner_text'],
    ];
}

function r174_policy_owner_row(array $row): array
{
    return [
        'id' => (string)$row['id'], 'key' => (string)$row['policy_key'], 'version' => (string)$row['version'],
        'title' => (string)$row['title'], 'lead' => (string)$row['lead_text'], 'effectiveDate' => (string)$row['effective_date'],
        'body' => (string)$row['body_text'], 'reviewBanner' => (bool)$row['review_banner'], 'bannerText' => (string)$row['banner_text'],
        'status' => (string)$row['status'], 'createdAt' => (string)$row['created_at'], 'updatedAt' => (string)$row['updated_at'],
        'publishedAt' => $row['published_at'] === null ? null : (string)$row['published_at'], 'publishReason' => (string)($row['publish_reason'] ?? ''),
    ];
}

/* ---------- public routes (no sign-in) ---------- */

function handle_site_public(): never
{
    require_method('GET');
    header('Cache-Control: no-store');
    $policies = [];
    foreach (array_keys(R174_POLICY_KEYS) as $key) {
        $p = r174_published_policy($key);
        if ($p) $policies[$key] = ['version' => (string)$p['version'], 'effectiveDate' => (string)$p['effective_date']];
    }
    json_response(['stage' => r174_release_stage(), 'siteText' => r174_site_text(), 'policies' => $policies]);
}

function handle_site_policy_public(): never
{
    require_method('GET');
    header('Cache-Control: no-store');
    $key = (string)($_GET['key'] ?? '');
    if (!isset(R174_POLICY_KEYS[$key])) fail('Unknown policy.', 404, 'policy_not_found', false);
    $p = r174_published_policy($key);
    json_response(['policy' => $p ? r174_policy_public_payload($p) : null]);
}

/* ---------- owner routes ---------- */

function r174_validate_group(string $group, mixed $value): array
{
    if (!is_array($value)) fail('Settings must be an object.', 422, 'site_settings_invalid');
    $bool = static function (mixed $v, string $label): bool {
        if (!is_bool($v)) fail($label . ' must be on or off.', 422, 'site_settings_invalid');
        return $v;
    };
    $text = static function (mixed $v, string $label, int $max, bool $multiline = false): string {
        if (!is_string($v) && $v !== null) fail($label . ' must be text.', 422, 'site_settings_invalid');
        $t = str_replace(["\r\n", "\r"], "\n", (string)$v);
        $t = $multiline ? trim(preg_replace('/[ \t]+/u', ' ', $t) ?? '') : trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $t)) fail($label . ' contains control characters.', 422, 'site_settings_invalid');
        if (mb_strlen($t) > $max) fail($label . ' must be ' . $max . ' characters or fewer.', 422, 'site_settings_invalid');
        return $t;
    };
    if ($group === 'release') {
        $stage = $value['stage'] ?? '';
        if (!in_array($stage, ['beta', 'production'], true)) fail('Choose Beta or Production.', 422, 'site_settings_invalid');
        return ['stage' => $stage];
    }
    if ($group === 'labels') {
        $out = [
            'noticeEnabled' => $bool($value['noticeEnabled'] ?? null, 'The top-bar notice'),
            'noticeLabel' => $text($value['noticeLabel'] ?? '', 'The notice label', 60),
            'noticeText' => $text($value['noticeText'] ?? '', 'The notice text', 300),
            'showVersion' => $bool($value['showVersion'] ?? null, 'The version label'),
            'showBetaLabels' => $bool($value['showBetaLabels'] ?? null, 'The Beta labels'),
        ];
        if ($out['noticeEnabled'] && ($out['noticeLabel'] === '' || $out['noticeText'] === '')) fail('Enter the notice label and text, or turn the notice off.', 422, 'site_settings_invalid');
        return $out;
    }
    if ($group === 'operator') {
        $out = [];
        foreach (array_keys(TEGH_OPERATOR_FIELDS) as $key) {
            $t = $text($value[$key] ?? '', TEGH_OPERATOR_FIELDS[$key], 300);
            if ($t !== '' && str_ends_with($key, '_email') && !filter_var($t, FILTER_VALIDATE_EMAIL)) fail(TEGH_OPERATOR_FIELDS[$key] . ' is not a valid email address.', 422, 'site_settings_invalid');
            $out[$key] = $t;
        }
        foreach (['backup_retention_days' => [0, 3650], 'deletion_response_days' => [1, 90]] as $key => [$min, $max]) {
            $v = $value[$key] ?? null;
            if ($v === null || $v === '') continue;
            if (!is_int($v) || $v < $min || $v > $max) fail("The number of days must be between $min and $max.", 422, 'site_settings_invalid');
            $out[$key] = $v;
        }
        return $out;
    }
    if ($group === 'site_text') {
        $out = [];
        foreach ($value as $key => $item) {
            if (!isset(R174_SITE_TEXT[$key])) fail('Unknown page wording: ' . mb_substr((string)$key, 0, 40) . '.', 422, 'site_settings_invalid');
            if (!is_array($item)) fail('Page wording must be an object.', 422, 'site_settings_invalid');
            $def = R174_SITE_TEXT[$key];
            $t = $text($item['text'] ?? '', $def['label'], (int)$def['max'], !empty($def['multiline']));
            $h = $bool($item['hidden'] ?? false, $def['label'] . ' (hidden)');
            if ($t !== '' || $h) $out[$key] = ['text' => $t, 'hidden' => $h];
        }
        return $out;
    }
    fail('Unknown settings group.', 404, 'site_settings_group_unknown');
}

function r174_owner_state(): array
{
    $groups = [];
    foreach (R174_GROUPS as $g) $groups[$g] = r174_group($g);
    $config = config('operator');
    $config = is_array($config) ? $config : [];
    $configOperator = [];
    foreach (array_keys(TEGH_OPERATOR_FIELDS) as $k) $configOperator[$k] = mb_substr(trim((string)($config[$k] ?? '')), 0, 300);
    $history = [];
    if (r174_schema_ready()) {
        $s = db()->query('SELECT h.setting_group, h.reason, h.created_at, COALESCE(u.display_name, u.email) actor FROM site_setting_history h LEFT JOIN users u ON u.id=h.actor_user_id ORDER BY h.created_at DESC, h.id DESC LIMIT 30');
        foreach ($s->fetchAll() as $r) $history[] = ['group' => (string)$r['setting_group'], 'reason' => (string)$r['reason'], 'at' => (string)$r['created_at'], 'by' => (string)($r['actor'] ?? '')];
    }
    $policies = [];
    if (r174_schema_ready()) {
        $s = db()->query('SELECT * FROM site_policy_versions ORDER BY policy_key, created_at DESC, id DESC');
        foreach ($s->fetchAll() as $r) $policies[] = r174_policy_owner_row($r);
    }
    $siteText = [];
    foreach (R174_SITE_TEXT as $k => $d) $siteText[] = ['key' => $k, 'label' => $d['label'], 'default' => $d['default'], 'max' => $d['max'], 'multiline' => !empty($d['multiline'])];
    return [
        'groups' => $groups,
        'configOperator' => $configOperator,
        'operatorFields' => TEGH_OPERATOR_FIELDS,
        'operatorEffective' => tegh_operator_details(),
        'operatorMissing' => tegh_operator_missing(),
        'registration' => tegh_release_registration_payload(),
        'policyKeys' => R174_POLICY_KEYS,
        'policies' => $policies,
        'siteTextFields' => $siteText,
        'history' => $history,
        'acceptedVersions' => ['terms' => tegh_terms_version(), 'privacy' => tegh_privacy_version()],
        'versionLabel' => SR_ACCOUNTAX_VERSION,
    ];
}

/** Save one group inside a transaction; returns the new revision. */
function r174_save_group(array $user, string $group, array $value, int $expected, string $reason): int
{
    $s = db()->prepare('SELECT value_json, revision FROM site_settings WHERE setting_group=? FOR UPDATE');
    $s->execute([$group]);
    $row = $s->fetch();
    $current = $row ? (int)$row['revision'] : 0;
    if ($current !== $expected) fail('These settings were changed by someone else. Reload and review them.', 409, 'site_settings_revision_conflict');
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($row && $row['value_json'] === $json) return $current;
    if ($row) {
        db()->prepare('UPDATE site_settings SET value_json=?, revision=revision+1, updated_by=?, updated_at=UTC_TIMESTAMP() WHERE setting_group=?')->execute([$json, $user['id'], $group]);
    } else {
        db()->prepare('INSERT INTO site_settings (setting_group, value_json, revision, updated_by, updated_at) VALUES (?,?,1,?,UTC_TIMESTAMP())')->execute([$group, $json, $user['id']]);
    }
    db()->prepare('INSERT INTO site_setting_history (id, setting_group, old_json, new_json, actor_user_id, reason, request_reference) VALUES (?,?,?,?,?,?,?)')
        ->execute([new_id('sitehistory'), $group, $row['value_json'] ?? null, $json, $user['id'], $reason, request_id()]);
    platform_audit_event($user, 'platform.site_settings_changed', 'site_settings', $group, ['reason' => $reason, 'revision' => $current + 1]);
    return $current + 1;
}

function r174_reason(array $input): string
{
    $reason = trim(preg_replace('/\s+/u', ' ', (string)($input['reason'] ?? '')) ?? '');
    if (mb_strlen($reason) < 8) fail('Give a reason of at least 8 characters. It is kept in the change history.', 422, 'reason_required');
    if (mb_strlen($reason) > 1000) fail('The reason must be 1000 characters or fewer.', 422, 'reason_required');
    return $reason;
}

function r174_validate_policy(array $input): array
{
    $key = (string)($input['key'] ?? '');
    if (!isset(R174_POLICY_KEYS[$key])) fail('Choose a policy.', 422, 'policy_invalid');
    $version = trim((string)($input['version'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/', $version)) fail('Use a version of letters, numbers, dots, dashes or underscores, up to 40 characters (for example 2026-11-01 or 1.0).', 422, 'policy_invalid');
    $date = (string)($input['effectiveDate'] ?? '');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) fail('Enter the effective date.', 422, 'policy_invalid');
    $title = trim(preg_replace('/\s+/u', ' ', (string)($input['title'] ?? '')) ?? '');
    if ($title === '' || mb_strlen($title) > 160) fail('Enter a title of up to 160 characters.', 422, 'policy_invalid');
    $lead = trim(preg_replace('/\s+/u', ' ', (string)($input['lead'] ?? '')) ?? '');
    if (mb_strlen($lead) > 400) fail('The line under the title must be 400 characters or fewer.', 422, 'policy_invalid');
    $body = str_replace(["\r\n", "\r"], "\n", (string)($input['body'] ?? ''));
    $body = trim($body);
    if ($body === '') fail('The policy text is empty.', 422, 'policy_invalid');
    if (mb_strlen($body) > 60000) fail('The policy text must be 60,000 characters or fewer.', 422, 'policy_invalid');
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) fail('The policy text contains control characters.', 422, 'policy_invalid');
    $banner = !empty($input['reviewBanner']);
    $bannerText = trim(preg_replace('/\s+/u', ' ', (string)($input['bannerText'] ?? '')) ?? '');
    if (mb_strlen($bannerText) > 300) fail('The banner text must be 300 characters or fewer.', 422, 'policy_invalid');
    return compact('key', 'version', 'date', 'title', 'lead', 'body', 'banner', 'bannerText');
}

function handle_site_admin(string $action): never
{
    require_method('GET', 'POST');
    $user = require_user();
    admin_require_platform_owner($user);
    if (!r174_schema_ready()) fail('The settings tables could not be created. Check the database user can create tables.', 503, 'site_settings_unavailable');
    if (request_method() === 'GET') {
        header('Cache-Control: no-store');
        json_response(r174_owner_state());
    }
    require_csrf();
    $input = request_json();

    if ($action === 'policy-draft' || $action === 'policy-delete') {
        // Drafts are not public; saving or deleting one needs no recent sign-in or reason.
        if ($action === 'policy-delete') {
            $id = (string)($input['id'] ?? '');
            $s = db()->prepare("DELETE FROM site_policy_versions WHERE id=? AND status='draft'");
            $s->execute([$id]);
            if ($s->rowCount() !== 1) fail('Only an unpublished draft can be deleted.', 409, 'policy_not_draft');
            platform_audit_event($user, 'platform.policy_draft_deleted', 'site_policy', $id, []);
            json_response(r174_owner_state());
        }
        $p = r174_validate_policy($input);
        $id = (string)($input['id'] ?? '');
        try {
            if ($id !== '') {
                $s = db()->prepare("UPDATE site_policy_versions SET policy_key=?, version=?, title=?, lead_text=?, effective_date=?, body_text=?, review_banner=?, banner_text=?, updated_at=UTC_TIMESTAMP() WHERE id=? AND status='draft'");
                $s->execute([$p['key'], $p['version'], $p['title'], $p['lead'], $p['date'], $p['body'], $p['banner'] ? 1 : 0, $p['bannerText'], $id]);
                if ($s->rowCount() === 0) {
                    $chk = db()->prepare("SELECT status FROM site_policy_versions WHERE id=?");
                    $chk->execute([$id]);
                    $st = $chk->fetchColumn();
                    if ($st === false) fail('That draft no longer exists.', 404, 'policy_not_found');
                    if ($st !== 'draft') fail('A published version cannot be changed. Save it as a new version.', 409, 'policy_not_draft');
                }
            } else {
                $id = new_id('policy');
                db()->prepare('INSERT INTO site_policy_versions (id, policy_key, version, title, lead_text, effective_date, body_text, review_banner, banner_text, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$id, $p['key'], $p['version'], $p['title'], $p['lead'], $p['date'], $p['body'], $p['banner'] ? 1 : 0, $p['bannerText'], 'draft', $user['id']]);
            }
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) fail('That version already exists for this policy. Choose a new version.', 409, 'policy_version_exists');
            throw $e;
        }
        platform_audit_event($user, 'platform.policy_draft_saved', 'site_policy', $id, ['policy' => $p['key'], 'version' => $p['version']]);
        $state = r174_owner_state();
        $state['savedId'] = $id;
        json_response($state);
    }

    tegh_require_recent_auth($user, $input);
    $reason = r174_reason($input);

    if ($action === 'policy-publish') {
        $id = (string)($input['id'] ?? '');
        try { $result = tegh_service_boundary(fn() => db_transaction_retry(function () use ($user, $id, $reason): array {
            $s = db()->prepare('SELECT * FROM site_policy_versions WHERE id=? FOR UPDATE');
            $s->execute([$id]);
            $row = $s->fetch();
            if (!$row) fail('That draft no longer exists.', 404, 'policy_not_found');
            if ($row['status'] !== 'draft') fail('Only a draft can be published.', 409, 'policy_not_draft');
            db()->prepare("UPDATE site_policy_versions SET status='archived' WHERE policy_key=? AND status='published'")->execute([$row['policy_key']]);
            db()->prepare("UPDATE site_policy_versions SET status='published', published_by=?, published_at=UTC_TIMESTAMP(), publish_reason=? WHERE id=?")->execute([$user['id'], $reason, $id]);
            platform_audit_event($user, 'platform.policy_published', 'site_policy', $id, ['policy' => (string)$row['policy_key'], 'version' => (string)$row['version'], 'reason' => $reason]);
            return ['key' => (string)$row['policy_key']];
        })); } catch (Throwable $error) { tegh_fail_service($error); }
        $state = r174_owner_state();
        $state['published'] = $result['key'];
        json_response($state);
    }

    if ($action === 'policy-unpublish') {
        // Back to the page's built-in text; the version history is kept.
        $key = (string)($input['key'] ?? '');
        if (!isset(R174_POLICY_KEYS[$key])) fail('Choose a policy.', 422, 'policy_invalid');
        $s = db()->prepare("UPDATE site_policy_versions SET status='archived' WHERE policy_key=? AND status='published'");
        $s->execute([$key]);
        if ($s->rowCount() === 0) fail('This policy has no published version.', 409, 'policy_not_published');
        platform_audit_event($user, 'platform.policy_unpublished', 'site_policy', $key, ['reason' => $reason]);
        json_response(r174_owner_state());
    }

    if ($action === 'save') {
        $groups = $input['groups'] ?? null;
        if (!is_array($groups) || !$groups) fail('Nothing to save.', 422, 'site_settings_invalid');
        $clean = [];
        foreach ($groups as $group => $payload) {
            if (!in_array($group, R174_GROUPS, true)) fail('Unknown settings group.', 404, 'site_settings_group_unknown');
            if (!is_array($payload) || !array_key_exists('value', $payload) || !is_int($payload['revision'] ?? null)) fail('Each group needs its value and revision.', 422, 'site_settings_invalid');
            $clean[$group] = [r174_validate_group($group, $payload['value']), $payload['revision']];
        }
        try { tegh_service_boundary(fn() => db_transaction_retry(function () use ($user, $clean, $reason): void {
            foreach ($clean as $group => [$value, $revision]) r174_save_group($user, $group, $value, $revision, $reason);
        })); } catch (Throwable $error) { tegh_fail_service($error); }
        r174_forget_cache();
        json_response(r174_owner_state());
    }

    fail('Site settings route not found.', 404, 'route_not_found');
}
