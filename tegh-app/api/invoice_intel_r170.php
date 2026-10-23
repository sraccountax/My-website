<?php
declare(strict_types=1);

/*
 * R170 Invoice Intelligence: vendor invoice PDFs → reviewed, accounting-ready draft vendor invoices.
 *
 * The browser reads each file with the bundled pdf.js/Tesseract runtime (no server binaries, no outside service) and
 * acts as the worker: it claims a job with a lease, reads the file and submits the result. This file keeps the jobs,
 * checks every figure again, matches the supplier, suggests accounts and tax codes, finds duplicates, and creates a
 * draft only through create_bill_record() (never issued here) or r151_note_create_unlinked(). Posting stays in the
 * vendor invoice register's normal Issue and approval workflow.
 *
 * Document text is untrusted data. It is never interpreted as an instruction; the evidence snippets kept for review
 * are encrypted, and ordinary logs never receive document content.
 */

require_once __DIR__ . '/native_ap_ar_v5600.php';
require_once __DIR__ . '/intake_r152.php';
if (!function_exists('tegh_service_boundary')) require_once __DIR__ . '/release_v5980.php';

const R170_STATES = ['uploaded', 'queued', 'processing', 'review_required', 'ready', 'blocked', 'draft_created', 'approved', 'posted', 'failed', 'cancelled'];
const R170_DOC_CLASSES = ['invoice', 'credit_note', 'statement', 'receipt', 'other', 'unknown'];
const R170_LEASE_SECONDS = 180;
const R170_LEASE_MAX_SECONDS = 1200;
const R170_MAX_ATTEMPTS = 3;
const R170_MAX_ACTIVE_LEASES = 4;
const R170_MAX_PAGES = 30;
const R170_MAX_SEGMENTS = 20;
const R170_MAX_LINES = 100;
const R170_FAILURE_CODES = ['pdf_password_required', 'pdf_password_wrong', 'pdf_corrupt', 'pdf_too_many_pages', 'ocr_failed', 'unreadable', 'timeout', 'unsupported', 'retry_limit', 'not_pdf', 'pdf_truncated'];
const R170_TEXT_FIELDS = ['supplierName' => 160, 'supplierLegalName' => 160, 'supplierTradingName' => 160, 'supplierAddress' => 500, 'supplierPhone' => 60, 'supplierEmail' => 254,
    'supplierTaxNumbers' => 200, 'supplierIdentifier' => 80, 'invoiceNumber' => 60, 'paymentTerms' => 120, 'poNumber' => 80, 'shipTo' => 300, 'billTo' => 300, 'customerNumber' => 80,
    'originalInvoiceNumber' => 60, 'shippingInfo' => 200];
const R170_DATE_FIELDS = ['invoiceDate', 'dueDate'];
const R170_MONEY_FIELDS = ['subtotalCents', 'discountCents', 'freightCents', 'taxCents', 'roundingCents', 'totalCents', 'paidCents', 'balanceDueCents'];

function r170_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        r152_schema_ready();
        db()->exec("CREATE TABLE IF NOT EXISTS invoice_intel_jobs (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  document_id VARCHAR(64) NOT NULL,
  batch_id VARCHAR(64) NOT NULL,
  segment_index INT NOT NULL DEFAULT 0,
  page_from INT NULL,
  page_to INT NULL,
  state VARCHAR(24) NOT NULL DEFAULT 'queued',
  review_class VARCHAR(12) NULL,
  doc_class VARCHAR(16) NOT NULL DEFAULT 'unknown',
  attempts INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 3,
  lease_token CHAR(64) NULL,
  leased_by VARCHAR(64) NULL,
  lease_started_at DATETIME NULL,
  lease_expires_at DATETIME NULL,
  failure_code VARCHAR(80) NULL,
  extraction_method VARCHAR(16) NULL,
  page_count INT NULL,
  ocr_pages INT NULL,
  pdf_encrypted TINYINT(1) NOT NULL DEFAULT 0,
  fields_json MEDIUMTEXT NULL,
  lines_json MEDIUMTEXT NULL,
  evidence_enc MEDIUMTEXT NULL,
  analysis_json MEDIUMTEXT NULL,
  vendor_id VARCHAR(64) NULL,
  vendor_chosen_by VARCHAR(16) NULL,
  invoice_number VARCHAR(80) NULL,
  invoice_number_norm VARCHAR(80) NULL,
  invoice_date DATE NULL,
  total_cents BIGINT NULL,
  currency CHAR(3) NULL,
  content_simhash CHAR(16) NULL,
  target_type VARCHAR(20) NULL,
  target_id VARCHAR(64) NULL,
  target_status VARCHAR(30) NULL,
  accepted_computed_total TINYINT(1) NOT NULL DEFAULT 0,
  reviewed_by VARCHAR(64) NULL,
  reviewed_at DATETIME NULL,
  processed_at DATETIME NULL,
  created_by VARCHAR(64) NOT NULL,
  updated_by VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY invoice_intel_doc_segment_uq (company_id, document_id, segment_index),
  UNIQUE KEY invoice_intel_target_uq (company_id, target_type, target_id),
  KEY invoice_intel_state_idx (company_id, state, updated_at),
  KEY invoice_intel_batch_idx (company_id, batch_id),
  KEY invoice_intel_number_idx (company_id, invoice_number_norm),
  KEY invoice_intel_vendor_idx (company_id, vendor_id),
  KEY invoice_intel_simhash_idx (company_id, content_simhash),
  CONSTRAINT invoice_intel_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT invoice_intel_document_fk FOREIGN KEY (document_id) REFERENCES native_agent_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS invoice_intel_corrections (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  job_id VARCHAR(64) NOT NULL,
  field VARCHAR(80) NOT NULL,
  line_index INT NULL,
  before_json TEXT NULL,
  after_json TEXT NULL,
  reason VARCHAR(300) NULL,
  user_id VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY invoice_intel_corrections_job_idx (company_id, job_id, created_at),
  CONSTRAINT invoice_intel_corrections_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT invoice_intel_corrections_job_fk FOREIGN KEY (job_id) REFERENCES invoice_intel_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS invoice_intel_overrides (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  job_id VARCHAR(64) NOT NULL,
  duplicate_class VARCHAR(16) NOT NULL,
  duplicate_of VARCHAR(200) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY invoice_intel_overrides_job_idx (company_id, job_id),
  CONSTRAINT invoice_intel_overrides_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT invoice_intel_overrides_job_fk FOREIGN KEY (job_id) REFERENCES invoice_intel_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS invoice_intel_vendor_rules (
  id VARCHAR(64) PRIMARY KEY,
  company_id VARCHAR(64) NOT NULL,
  vendor_id VARCHAR(64) NOT NULL,
  keyword_key CHAR(64) NOT NULL,
  keywords VARCHAR(200) NOT NULL,
  account_id VARCHAR(64) NOT NULL,
  tax_code_id VARCHAR(64) NULL,
  uses INT NOT NULL DEFAULT 1,
  last_job_id VARCHAR(64) NULL,
  updated_by VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY invoice_intel_rule_uq (company_id, vendor_id, keyword_key),
  CONSTRAINT invoice_intel_rules_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT invoice_intel_rules_vendor_fk FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ready = schema_table_exists('invoice_intel_jobs') && schema_table_exists('invoice_intel_vendor_rules');
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function r170_require_schema(): void
{
    if (!r170_schema_ready()) fail('Invoice Intelligence is unavailable on this database.', 503, 'invoice_intel_unavailable');
    // Schema helpers used later inside transactions may run DDL on their first call, and DDL commits an open
    // transaction in MariaDB. Run them here, before any transaction starts.
    static $warm = false;
    if ($warm) return;
    $warm = true;
    if (function_exists('r151_schema_ready')) r151_schema_ready();
    if (function_exists('tegh_tax_codes_ready')) tegh_tax_codes_ready();
    if (function_exists('tegh_schema41_status')) tegh_schema41_status();
}

/* ---------- encryption of evidence (document text snippets) ---------- */

function r170_key(): ?string
{
    $secret = (string)config('app.secret');
    if (strlen($secret) < 16) return null;
    return hash_hkdf('sha256', $secret, 32, 'tegh.invoice-intel.evidence.v1');
}

function r170_encrypt(array $value, string $aad): ?string
{
    $key = r170_key();
    if ($key === null) return null;
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
    return $cipher === false ? null : 'v1:' . base64_encode($nonce . $tag . $cipher);
}

function r170_decrypt(?string $stored, string $aad): array
{
    if ($stored === null || !str_starts_with($stored, 'v1:')) return [];
    $key = r170_key();
    $raw = base64_decode(substr($stored, 3), true);
    if ($key === null || $raw === false || strlen($raw) < 29) return [];
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
    $value = $plain === false ? null : json_decode($plain, true);
    return is_array($value) ? $value : [];
}

/* ---------- small helpers ---------- */

function r170_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function r170_text(mixed $value, int $max): string
{
    $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', (string)$value) ?? '';
    return mb_substr(trim($text), 0, $max);
}

function r170_cents(mixed $value): ?int
{
    if ($value === null || $value === '') return null;
    if (is_int($value)) $int = $value;
    elseif (is_string($value) && preg_match('/^-?\d{1,13}$/', $value)) $int = (int)$value;
    elseif (is_float($value) && floor($value) === $value) $int = (int)$value;
    else return null;
    return abs($int) <= 100000000000 ? $int : null;
}

/** Invoice-number variants compared for duplicates: case, spaces and punctuation ignored, an "INV" prefix and leading zeros dropped. The printed number itself is kept as read. */
function r170_number_norm(string $number): string
{
    $n = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $number) ?? '');
    $n = preg_replace('/^(?:INVOICE|INV|NO|NUM|FACT|FACTURE|BILL)(?=[A-Z0-9]*\d)/', '', $n) ?? $n;
    $n = ltrim($n, '0');
    return mb_substr($n === '' ? '0' : $n, 0, 80);
}

function r170_simhash_distance(string $a, string $b): int
{
    if (!preg_match('/^[0-9a-f]{16}$/', $a) || !preg_match('/^[0-9a-f]{16}$/', $b)) return 64;
    $distance = 0;
    for ($i = 0; $i < 16; $i += 4) {
        $x = hexdec(substr($a, $i, 4)) ^ hexdec(substr($b, $i, 4));
        while ($x) { $distance += $x & 1; $x >>= 1; }
    }
    return $distance;
}

/** Same rounding as the vendor invoice service (r151_quantity_milli, then quantity × price). */
function r170_line_amount(string $quantity, int $unitCents): int
{
    return (int)round(((int)round((float)$quantity * 1000)) * $unitCents / 1000);
}

function r170_words(string $text): array
{
    return r152_words($text);
}

function r170_money(int $cents, string $currency = ''): string
{
    $sign = $cents < 0 ? '-' : '';
    $value = number_format(abs($cents) / 100, 2, '.', ',');
    return $sign . ($currency !== '' && $currency !== 'CAD' ? $currency . ' ' : '$') . $value;
}

/* ---------- reading and writing jobs ---------- */

function r170_job_row(array $company, string $id, bool $lock = false): array
{
    $stmt = db()->prepare('SELECT j.*,d.original_name,d.mime_type,d.size_bytes,d.sha256,d.storage_path,d.state document_state FROM invoice_intel_jobs j JOIN native_agent_documents d ON d.id=j.document_id AND d.company_id=j.company_id WHERE j.id=? AND j.company_id=? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id, (string)$company['id']]);
    $row = $stmt->fetch();
    if (!$row) fail('That invoice is not available in this company.', 404, 'invoice_intel_job_not_found');
    return $row;
}

function r170_decode(?string $json): array
{
    $value = $json === null ? null : json_decode($json, true);
    return is_array($value) ? $value : [];
}

function r170_vendor_name(string $companyId, ?string $vendorId): ?string
{
    if (!$vendorId) return null;
    $q = db()->prepare('SELECT name FROM vendors WHERE id=? AND company_id=? LIMIT 1');
    $q->execute([$vendorId, $companyId]);
    $name = $q->fetchColumn();
    return $name === false ? null : (string)$name;
}

/** Follows the draft into the normal workflow: draft, awaiting approval, approved, posted, void, or deleted. */
function r170_sync_target(array $company, array $row): array
{
    if (empty($row['target_id'])) return $row;
    $companyId = (string)$company['id'];
    if ($row['target_type'] === 'bill') {
        $q = db()->prepare('SELECT status,number FROM bills WHERE id=? AND company_id=? LIMIT 1');
    } else {
        $q = db()->prepare('SELECT status,number FROM accounting_notes WHERE id=? AND company_id=? LIMIT 1');
    }
    $q->execute([(string)$row['target_id'], $companyId]);
    $target = $q->fetch();
    if (!$target) {
        // The draft was deleted in the register. No payable exists, so the invoice can be reviewed and drafted again.
        db()->prepare("UPDATE invoice_intel_jobs SET target_type=NULL,target_id=NULL,target_status=NULL,state='review_required' WHERE id=? AND company_id=? AND target_id=?")
            ->execute([(string)$row['id'], $companyId, (string)$row['target_id']]);
        $row['target_type'] = $row['target_id'] = $row['target_status'] = null;
        $row['state'] = 'review_required';
        $row['draft_deleted'] = true;
        return $row;
    }
    $status = (string)$target['status'];
    $state = match ($status) { 'draft', 'submitted_for_approval' => 'draft_created', 'approved' => 'approved', default => 'posted' };
    if ($state !== (string)$row['state'] || $status !== (string)$row['target_status']) {
        db()->prepare('UPDATE invoice_intel_jobs SET state=?,target_status=? WHERE id=? AND company_id=?')->execute([$state, $status, (string)$row['id'], $companyId]);
        $row['state'] = $state;
        $row['target_status'] = $status;
    }
    $row['target_number'] = (string)$target['number'];
    return $row;
}

function r170_public_row(array $company, array $row): array
{
    $analysis = r170_decode($row['analysis_json'] ?? null);
    $fields = r170_decode($row['fields_json'] ?? null);
    $duplicates = $analysis['duplicates'] ?? [];
    $class = null;
    foreach ($duplicates as $d) { if (($d['class'] ?? '') === 'exact') { $class = 'exact'; break; } if (($d['class'] ?? '') === 'probable') $class = 'probable'; elseif ($class === null) $class = 'review'; }
    $flags = array_values(array_filter($analysis['validations'] ?? [], static fn($v) => in_array($v['severity'] ?? '', ['block', 'review'], true)));
    return [
        'id' => (string)$row['id'], 'documentId' => (string)$row['document_id'], 'batchId' => (string)$row['batch_id'], 'segmentIndex' => (int)$row['segment_index'],
        'pageFrom' => $row['page_from'] !== null ? (int)$row['page_from'] : null, 'pageTo' => $row['page_to'] !== null ? (int)$row['page_to'] : null,
        'fileName' => (string)$row['original_name'], 'mimeType' => (string)$row['mime_type'], 'sizeBytes' => (int)$row['size_bytes'],
        'state' => (string)$row['state'], 'reviewClass' => $row['review_class'], 'docClass' => (string)$row['doc_class'],
        'attempts' => (int)$row['attempts'], 'maxAttempts' => (int)$row['max_attempts'], 'failureCode' => $row['failure_code'],
        'extractionMethod' => $row['extraction_method'], 'pageCount' => $row['page_count'] !== null ? (int)$row['page_count'] : null, 'ocrPages' => $row['ocr_pages'] !== null ? (int)$row['ocr_pages'] : null,
        'pdfEncrypted' => (bool)$row['pdf_encrypted'],
        'vendorId' => $row['vendor_id'], 'vendorName' => r170_vendor_name((string)$company['id'], $row['vendor_id']),
        'supplierName' => (string)($fields['supplierName']['value'] ?? ''),
        'invoiceNumber' => $row['invoice_number'], 'invoiceDate' => $row['invoice_date'], 'totalCents' => $row['total_cents'] !== null ? (int)$row['total_cents'] : null, 'currency' => $row['currency'],
        'duplicateClass' => $class, 'flagCount' => count($flags), 'confidence' => $analysis['confidence']['document'] ?? null,
        'targetType' => $row['target_type'], 'targetId' => $row['target_id'], 'targetStatus' => $row['target_status'], 'targetNumber' => $row['target_number'] ?? null,
        'draftDeleted' => !empty($row['draft_deleted']),
        'sourceUrl' => '/api/index.php?route=native-ap-ar/document/file&documentId=' . rawurlencode((string)$row['document_id']),
        'createdAt' => (string)$row['created_at'], 'updatedAt' => (string)$row['updated_at'],
    ];
}

function r170_detail(array $company, array $row): array
{
    $row = r170_sync_target($company, $row);
    $out = r170_public_row($company, $row);
    $out['fields'] = r170_decode($row['fields_json'] ?? null);
    $out['lines'] = r170_decode($row['lines_json'] ?? null);
    $evidence = r170_decrypt($row['evidence_enc'] ?? null, (string)$company['id'] . '|' . (string)$row['id']);
    $out['taxes'] = $out['fields']['_taxes'] ?? [];
    $out['pages'] = $out['fields']['_pages'] ?? [];
    unset($out['fields']['_taxes'], $out['fields']['_pages']);
    $out['evidence'] = $evidence;
    $out['analysis'] = r170_decode($row['analysis_json'] ?? null);
    $out['acceptedComputedTotal'] = (bool)$row['accepted_computed_total'];
    $c = db()->prepare('SELECT c.field,c.line_index,c.before_json,c.after_json,c.reason,c.created_at,u.display_name FROM invoice_intel_corrections c LEFT JOIN users u ON u.id=c.user_id WHERE c.company_id=? AND c.job_id=? ORDER BY c.created_at,c.id LIMIT 200');
    $c->execute([(string)$company['id'], (string)$row['id']]);
    $out['corrections'] = array_map(static fn($r) => ['field' => (string)$r['field'], 'lineIndex' => $r['line_index'] !== null ? (int)$r['line_index'] : null, 'before' => json_decode((string)$r['before_json'], true), 'after' => json_decode((string)$r['after_json'], true), 'reason' => $r['reason'], 'by' => $r['display_name'], 'at' => (string)$r['created_at']], $c->fetchAll());
    $o = db()->prepare('SELECT o.duplicate_class,o.duplicate_of,o.reason,o.created_at,u.display_name FROM invoice_intel_overrides o LEFT JOIN users u ON u.id=o.user_id WHERE o.company_id=? AND o.job_id=? ORDER BY o.created_at');
    $o->execute([(string)$company['id'], (string)$row['id']]);
    $out['overrides'] = array_map(static fn($r) => ['class' => (string)$r['duplicate_class'], 'of' => (string)$r['duplicate_of'], 'reason' => (string)$r['reason'], 'by' => $r['display_name'], 'at' => (string)$r['created_at']], $o->fetchAll());
    return $out;
}

/* ---------- options for the review screen ---------- */

function r170_options(array $company): array
{
    $companyId = (string)$company['id'];
    $vendors = db()->prepare("SELECT id,name,email,default_currency,default_terms_days,default_expense_account_id FROM vendors WHERE company_id=? AND active=1 AND status='active' ORDER BY name,id LIMIT 2000");
    $vendors->execute([$companyId]);
    $accounts = db()->prepare("SELECT id,code,name,account_type FROM accounts WHERE company_id=? AND active=1 AND is_control=0 AND account_type IN ('expense','asset') ORDER BY code,name");
    $accounts->execute([$companyId]);
    $currencies = [];
    try { $q = db()->prepare('SELECT currency FROM company_currencies WHERE company_id=? ORDER BY currency'); $q->execute([$companyId]); $currencies = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) {}
    if (!in_array((string)$company['currency'], $currencies, true)) array_unshift($currencies, (string)$company['currency']);
    $codesMode = function_exists('tax_setup_mode') && tax_setup_mode($company) === 'codes';
    $codes = $codesMode ? array_map(static fn($c) => ['id' => $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'region' => $c['region'], 'totalRateMpct' => $c['totalRateMpct'],
        'components' => array_map(static fn($p) => ['name' => $p['name'], 'rateMpct' => $p['rateMpct'], 'recoverable' => $p['purchaseRecoverable']], $c['components'])], tax_codes_list($companyId)) : [];
    $home = $codesMode ? tax_code_home($company) : null;
    return [
        'vendors' => array_map(static fn($v) => ['id' => (string)$v['id'], 'name' => (string)$v['name'], 'currency' => (string)$v['default_currency'], 'termsDays' => (int)$v['default_terms_days'], 'defaultAccountId' => $v['default_expense_account_id']], $vendors->fetchAll()),
        'accounts' => array_map(static fn($a) => ['id' => (string)$a['id'], 'code' => (string)$a['code'], 'name' => (string)$a['name'], 'type' => (string)$a['account_type']], $accounts->fetchAll()),
        'taxMode' => $codesMode ? 'codes' : 'legacy', 'taxCodes' => $codes, 'homeTaxCodeId' => $home['id'] ?? null,
        'legacyTax' => ['gstHst' => (bool)$company['tax_registered'], 'pst' => (bool)($company['pst_registered'] ?? false)],
        'currencies' => $currencies, 'companyCurrency' => (string)$company['currency'], 'booksStartDate' => $company['books_start_date'] ?? null,
        'materialityCents' => company_materiality_threshold_cents($companyId),
        'canWrite' => company_role_can((string)$company['role'], 'bills.write'), 'canOverride' => company_role_can((string)$company['role'], 'bills.reverse'),
        'canCreditNote' => company_role_can((string)$company['role'], 'ap.credit_note.write'),
        'limits' => ['maxPages' => R170_MAX_PAGES, 'maxFileBytes' => 10 * 1024 * 1024, 'maxAttempts' => R170_MAX_ATTEMPTS],
    ];
}

/* ---------- upload ---------- */

/** File checks that need no PDF parser: signature, end marker, encryption marker and a rough page count. */
function r170_inspect_file(string $absolute, string $mime): array
{
    if ($mime !== 'application/pdf') return ['ok' => true, 'encrypted' => false, 'pages' => 1];
    $size = filesize($absolute);
    $handle = fopen($absolute, 'rb');
    if ($handle === false || $size === false) return ['ok' => false, 'code' => 'pdf_corrupt'];
    $head = (string)fread($handle, 1024);
    fseek($handle, max(0, $size - 4096));
    $tail = (string)fread($handle, 4096);
    fclose($handle);
    if (!str_contains($head, '%PDF-')) return ['ok' => false, 'code' => 'not_pdf'];
    if (!str_contains($tail, '%%EOF')) return ['ok' => false, 'code' => 'pdf_truncated'];
    $body = (string)file_get_contents($absolute);
    $encrypted = (bool)preg_match('/\/Encrypt\s+\d+\s+\d+\s+R|\/Encrypt\s*<</', $body);
    $pages = preg_match_all('/\/Type\s*\/Page(?![a-zA-Z])/', $body);
    return ['ok' => true, 'encrypted' => $encrypted, 'pages' => (int)$pages];
}

function r170_upload(array $user, array $company): array
{
    r170_require_schema();
    require_company_permission($company, 'attachments.write');
    require_company_permission($company, 'bills.write');
    $batch = trim((string)($_POST['batchId'] ?? ''));
    if (!preg_match('/^[a-z0-9-]{8,64}$/', $batch)) $batch = 'batch-' . bin2hex(random_bytes(8));
    $_POST['documentType'] = 'vendor_bill';
    $result = native_document_upload($user, $company);
    $documentId = (string)$result['document']['id'];
    $companyId = (string)$company['id'];
    $existing = db()->prepare('SELECT * FROM invoice_intel_jobs WHERE company_id=? AND document_id=? ORDER BY segment_index');
    $existing->execute([$companyId, $documentId]);
    $jobs = $existing->fetchAll();
    if ($jobs) {
        // The same file was uploaded before: no second job, so it cannot become a second payable.
        audit_event($user, $companyId, 'invoice_intel.duplicate_file', 'native_agent_document', $documentId, ['jobs' => count($jobs), 'sha256' => (string)$result['document']['sha256']]);
        $rows = array_map(static fn($j) => r170_public_row($company, r170_sync_target($company, r170_job_row($company, (string)$j['id']))), $jobs);
        return ['duplicateFile' => true, 'documentId' => $documentId, 'jobs' => $rows, 'accountingWrites' => 0];
    }
    $doc = native_document_row($company, $documentId);
    $absolute = private_absolute_path((string)$doc['storage_path']);
    $inspect = $absolute === null ? ['ok' => false, 'code' => 'pdf_corrupt'] : r170_inspect_file($absolute, (string)$doc['mime_type']);
    $state = 'queued';
    $failure = null;
    if (!$inspect['ok']) { $state = 'failed'; $failure = $inspect['code']; }
    elseif (($inspect['pages'] ?? 0) > R170_MAX_PAGES) { $state = 'failed'; $failure = 'pdf_too_many_pages'; }
    $id = new_id('iijob');
    db()->prepare('INSERT INTO invoice_intel_jobs (id,company_id,document_id,batch_id,segment_index,state,review_class,attempts,max_attempts,failure_code,pdf_encrypted,page_count,created_by,updated_by) VALUES (?,?,?,?,0,?,?,0,?,?,?,?,?,?)')
        ->execute([$id, $companyId, $documentId, $batch, $state, $state === 'failed' ? 'blocked' : null, R170_MAX_ATTEMPTS, $failure, !empty($inspect['encrypted']) ? 1 : 0, ($inspect['pages'] ?? 0) ?: null, (string)$user['id'], (string)$user['id']]);
    audit_event($user, $companyId, 'invoice_intel.uploaded', 'invoice_intel_job', $id, ['documentId' => $documentId, 'batchId' => $batch, 'state' => $state, 'failureCode' => $failure, 'sizeBytes' => (int)$doc['size_bytes'], 'accountingWrites' => 0]);
    return ['duplicateFile' => false, 'documentId' => $documentId, 'jobs' => [r170_public_row($company, r170_job_row($company, $id))], 'accountingWrites' => 0];
}

/* ---------- worker leases ---------- */

function r170_expire_leases(string $companyId): void
{
    // A lease that ran out: retry while attempts remain, otherwise stop with a clear reason.
    db()->prepare("UPDATE invoice_intel_jobs SET state='failed',review_class='blocked',failure_code='retry_limit',lease_token=NULL,leased_by=NULL,lease_expires_at=NULL WHERE company_id=? AND state='processing' AND lease_expires_at<UTC_TIMESTAMP() AND attempts>=max_attempts")->execute([$companyId]);
    db()->prepare("UPDATE invoice_intel_jobs SET state='queued',lease_token=NULL,leased_by=NULL,lease_expires_at=NULL WHERE company_id=? AND state='processing' AND lease_expires_at<UTC_TIMESTAMP() AND attempts<max_attempts")->execute([$companyId]);
}

function r170_claim(array $user, array $company, array $input): array
{
    r170_require_schema();
    require_company_permission($company, 'bills.write');
    $companyId = (string)$company['id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        r170_expire_leases($companyId);
        $active = $pdo->prepare("SELECT COUNT(*) FROM invoice_intel_jobs WHERE company_id=? AND state='processing' AND lease_expires_at>=UTC_TIMESTAMP()");
        $active->execute([$companyId]);
        if ((int)$active->fetchColumn() >= R170_MAX_ACTIVE_LEASES) { $pdo->commit(); return ['claimed' => false, 'reason' => 'busy']; }
        $wanted = trim((string)($input['jobId'] ?? ''));
        if ($wanted !== '') {
            $q = $pdo->prepare("SELECT id FROM invoice_intel_jobs WHERE id=? AND company_id=? AND segment_index=0 AND state='queued' FOR UPDATE");
            $q->execute([$wanted, $companyId]);
        } else {
            $q = $pdo->prepare("SELECT id FROM invoice_intel_jobs WHERE company_id=? AND segment_index=0 AND state='queued' ORDER BY created_at,id LIMIT 1 FOR UPDATE");
            $q->execute([$companyId]);
        }
        $id = $q->fetchColumn();
        if ($id === false) { $pdo->commit(); return ['claimed' => false, 'reason' => 'none']; }
        $token = bin2hex(random_bytes(32));
        $u = $pdo->prepare("UPDATE invoice_intel_jobs SET state='processing',lease_token=?,leased_by=?,lease_started_at=UTC_TIMESTAMP(),lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL " . R170_LEASE_SECONDS . " SECOND),attempts=attempts+1,failure_code=NULL WHERE id=? AND company_id=? AND state='queued'");
        $u->execute([$token, (string)$user['id'], (string)$id, $companyId]);
        if ($u->rowCount() !== 1) { $pdo->commit(); return ['claimed' => false, 'reason' => 'race']; }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $row = r170_job_row($company, (string)$id);
    return ['claimed' => true, 'job' => r170_public_row($company, $row), 'leaseToken' => $token, 'leaseSeconds' => R170_LEASE_SECONDS, 'maxPages' => R170_MAX_PAGES];
}

function r170_lease_row(array $company, array $input, bool $lock = true): array
{
    $id = clean_text($input['jobId'] ?? '', 'Invoice', 64);
    $token = (string)($input['leaseToken'] ?? '');
    $row = r170_job_row($company, $id, $lock);
    if (!preg_match('/^[a-f0-9]{64}$/', $token) || !hash_equals((string)($row['lease_token'] ?? ''), $token)) fail('This reading job is no longer held by this window.', 409, 'invoice_intel_lease_lost');
    return $row;
}

function r170_heartbeat(array $user, array $company, array $input): array
{
    require_company_permission($company, 'bills.write');
    $row = r170_lease_row($company, $input, false);
    if ((string)$row['state'] !== 'processing') fail('This reading job is no longer running.', 409, 'invoice_intel_lease_lost');
    $u = db()->prepare("UPDATE invoice_intel_jobs SET lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL " . R170_LEASE_SECONDS . " SECOND) WHERE id=? AND company_id=? AND state='processing' AND lease_token=? AND lease_started_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL " . R170_LEASE_MAX_SECONDS . " SECOND)");
    $u->execute([(string)$row['id'], (string)$company['id'], (string)$row['lease_token']]);
    if ($u->rowCount() !== 1) fail('This reading job ran past its time limit and will be retried.', 409, 'invoice_intel_lease_timeout');
    return ['ok' => true, 'leaseSeconds' => R170_LEASE_SECONDS];
}

function r170_worker_fail(array $user, array $company, array $input): array
{
    require_company_permission($company, 'bills.write');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = r170_lease_row($company, $input);
        $code = (string)($input['failureCode'] ?? 'unreadable');
        if (!in_array($code, R170_FAILURE_CODES, true)) $code = 'unreadable';
        $retry = ($input['retryable'] ?? false) === true && (int)$row['attempts'] < (int)$row['max_attempts'];
        $pdo->prepare('UPDATE invoice_intel_jobs SET state=?,review_class=?,failure_code=?,lease_token=NULL,leased_by=NULL,lease_expires_at=NULL,updated_by=? WHERE id=? AND company_id=?')
            ->execute([$retry ? 'queued' : 'failed', $retry ? null : 'blocked', $code, (string)$user['id'], (string)$row['id'], (string)$company['id']]);
        audit_event($user, (string)$company['id'], 'invoice_intel.read_failed', 'invoice_intel_job', (string)$row['id'], ['failureCode' => $code, 'willRetry' => $retry, 'attempt' => (int)$row['attempts'], 'accountingWrites' => 0]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['job' => r170_public_row($company, r170_job_row($company, (string)$row['id']))];
}

/* ---------- worker results: sanitising ---------- */

function r170_clean_field(mixed $raw, string $name): ?array
{
    if (!is_array($raw)) return null;
    $out = [];
    if (array_key_exists($name, R170_TEXT_FIELDS)) {
        $value = r170_text($raw['value'] ?? '', R170_TEXT_FIELDS[$name]);
        if ($value === '') return null;
        $out['value'] = $value;
    } elseif (in_array($name, R170_DATE_FIELDS, true)) {
        $value = (string)($raw['value'] ?? '');
        if ($value !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4)))) $value = '';
        $alternatives = array_values(array_filter(array_slice(is_array($raw['alternatives'] ?? null) ? $raw['alternatives'] : [], 0, 2), static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
        if ($value === '' && !$alternatives) return null;
        $out['value'] = $value;
        if ($alternatives) $out['alternatives'] = $alternatives;
    } elseif (in_array($name, R170_MONEY_FIELDS, true)) {
        $value = r170_cents($raw['value'] ?? null);
        if ($value === null) return null;
        $out['value'] = $value;
    } elseif ($name === 'currency') {
        $value = strtoupper((string)($raw['value'] ?? ''));
        if (!preg_match('/^[A-Z]{3}$/', $value)) return null;
        $out['value'] = $value;
    } elseif ($name === 'taxInclusive') {
        $out['value'] = ($raw['value'] ?? false) === true;
    } else {
        return null;
    }
    $out['raw'] = r170_text($raw['raw'] ?? '', 200);
    $source = (string)($raw['source'] ?? 'text');
    $out['source'] = in_array($source, ['text', 'ocr', 'derived', 'memory', 'user', 'default'], true) ? $source : 'text';
    $out['label'] = ($raw['label'] ?? false) === true;
    if (isset($raw['ocrConf']) && is_numeric($raw['ocrConf'])) $out['ocrConf'] = max(0, min(100, (int)$raw['ocrConf']));
    if (isset($raw['page']) && is_numeric($raw['page'])) $out['page'] = max(1, min(R170_MAX_PAGES, (int)$raw['page']));
    return $out;
}

function r170_clean_line(mixed $line): ?array
{
    if (!is_array($line)) return null;
    $description = r170_text($line['description'] ?? '', 300);
    $amount = r170_cents($line['amountCents'] ?? null);
    if ($description === '' && $amount === null) return null;
    $out = ['description' => $description, 'amountCents' => $amount];
    $code = r170_text($line['itemCode'] ?? '', 40); if ($code !== '') $out['itemCode'] = $code;
    $quantity = trim((string)($line['quantity'] ?? ''));
    if ($quantity !== '' && preg_match('/^\d{1,7}(\.\d{1,3})?$/', $quantity) && (float)$quantity > 0) $out['quantity'] = $quantity;
    $unit = r170_text($line['unit'] ?? '', 16); if ($unit !== '') $out['unit'] = $unit;
    foreach (['unitPriceCents', 'discountCents', 'taxCents'] as $key) { $v = r170_cents($line[$key] ?? null); if ($v !== null) $out[$key] = $v; }
    $taxLabel = r170_text($line['taxLabel'] ?? '', 16); if ($taxLabel !== '') $out['taxLabel'] = $taxLabel;
    $kind = (string)($line['kind'] ?? 'item');
    $out['kind'] = in_array($kind, ['item', 'freight', 'charge', 'discount'], true) ? $kind : 'item';
    $source = (string)($line['source'] ?? 'text');
    $out['source'] = in_array($source, ['text', 'ocr', 'user'], true) ? $source : 'text';
    if (isset($line['ocrConf']) && is_numeric($line['ocrConf'])) $out['ocrConf'] = max(0, min(100, (int)$line['ocrConf']));
    if (isset($line['page']) && is_numeric($line['page'])) $out['page'] = max(1, min(R170_MAX_PAGES, (int)$line['page']));
    foreach (['accountId', 'taxCodeId'] as $key) { $v = trim((string)($line[$key] ?? '')); if ($v !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $v)) $out[$key] = $v; }
    if (array_key_exists('applyGstHst', $line)) $out['applyGstHst'] = $line['applyGstHst'] === true;
    if (array_key_exists('applyPst', $line)) $out['applyPst'] = $line['applyPst'] === true;
    return $out;
}

function r170_clean_taxes(mixed $taxes): array
{
    $out = [];
    foreach (array_slice(is_array($taxes) ? $taxes : [], 0, 8) as $tax) {
        if (!is_array($tax)) continue;
        $label = strtoupper(r170_text($tax['label'] ?? '', 16));
        $amount = r170_cents($tax['amountCents'] ?? null);
        if ($label === '' || $amount === null) continue;
        $item = ['label' => $label, 'amountCents' => $amount, 'included' => ($tax['included'] ?? false) === true];
        $rate = r170_text($tax['rateText'] ?? '', 16); if ($rate !== '') $item['rateText'] = $rate;
        if (isset($tax['page']) && is_numeric($tax['page'])) $item['page'] = max(1, min(R170_MAX_PAGES, (int)$tax['page']));
        $out[] = $item;
    }
    return $out;
}

function r170_clean_evidence(mixed $evidence): array
{
    $out = [];
    foreach (is_array($evidence) ? $evidence : [] as $key => $item) {
        if (!is_array($item) || !preg_match('/^(?:[a-zA-Z]{2,40}|line\.\d{1,3}|tax\.\d)$/', (string)$key)) continue;
        $box = array_slice(is_array($item['box'] ?? null) ? $item['box'] : [], 0, 4);
        $clean = ['page' => max(1, min(R170_MAX_PAGES, (int)($item['page'] ?? 1))), 'snippet' => r170_text($item['snippet'] ?? '', 240)];
        if (count($box) === 4 && array_reduce($box, static fn($ok, $v) => $ok && is_numeric($v), true)) $clean['box'] = array_map(static fn($v) => round(max(0, min(1, (float)$v)), 4), $box);
        $out[(string)$key] = $clean;
        if (count($out) >= 200) break;
    }
    return $out;
}

function r170_clean_segment(array $segment): array
{
    $class = (string)($segment['docClass'] ?? 'unknown');
    $fields = [];
    foreach (array_merge(array_keys(R170_TEXT_FIELDS), R170_DATE_FIELDS, R170_MONEY_FIELDS, ['currency', 'taxInclusive']) as $name) {
        $field = r170_clean_field($segment['fields'][$name] ?? null, $name);
        if ($field !== null) $fields[$name] = $field;
    }
    $fields['_taxes'] = r170_clean_taxes($segment['taxes'] ?? []);
    $pages = [];
    foreach (array_slice(is_array($segment['pages'] ?? null) ? $segment['pages'] : [], 0, R170_MAX_PAGES) as $page) {
        if (!is_array($page)) continue;
        $rotation = (int)($page['rotation'] ?? 0);
        $pages[] = ['page' => max(1, min(R170_MAX_PAGES, (int)($page['page'] ?? 1))), 'rotation' => in_array($rotation, [0, 90, 180, 270], true) ? $rotation : 0,
            'method' => ($page['method'] ?? '') === 'ocr' ? 'ocr' : 'text', 'meanConf' => isset($page['meanConf']) && is_numeric($page['meanConf']) ? max(0, min(100, (int)$page['meanConf'])) : null];
    }
    $fields['_pages'] = $pages;
    $lines = [];
    foreach (array_slice(is_array($segment['lines'] ?? null) ? $segment['lines'] : [], 0, R170_MAX_LINES) as $line) { $l = r170_clean_line($line); if ($l) $lines[] = $l; }
    $simhash = strtolower((string)($segment['simhash'] ?? ''));
    return [
        'docClass' => in_array($class, R170_DOC_CLASSES, true) ? $class : 'unknown',
        'pageFrom' => max(1, min(R170_MAX_PAGES, (int)($segment['pageFrom'] ?? 1))), 'pageTo' => max(1, min(R170_MAX_PAGES, (int)($segment['pageTo'] ?? 1))),
        'fields' => $fields, 'lines' => $lines, 'evidence' => r170_clean_evidence($segment['evidence'] ?? []),
        'simhash' => preg_match('/^[0-9a-f]{16}$/', $simhash) ? $simhash : null,
    ];
}

function r170_submit(array $user, array $company, array $input): array
{
    r170_require_schema();
    require_company_permission($company, 'bills.write');
    $result = $input['result'] ?? null;
    if (!is_array($result)) fail('Send the reading result.', 422, 'invoice_intel_result_invalid');
    $segments = array_values(array_filter(array_slice(is_array($result['segments'] ?? null) ? $result['segments'] : [], 0, R170_MAX_SEGMENTS + 1), 'is_array'));
    if (!$segments) fail('The reading result has no invoice in it.', 422, 'invoice_intel_result_invalid');
    if (count($segments) > R170_MAX_SEGMENTS) fail('This file holds more than ' . R170_MAX_SEGMENTS . ' invoices. Split it before uploading.', 422, 'invoice_intel_too_many_segments');
    $method = (string)($result['method'] ?? 'pdf_text');
    if (!in_array($method, ['pdf_text', 'ocr', 'mixed'], true)) $method = 'pdf_text';
    $pageCount = max(1, min(R170_MAX_PAGES, (int)($result['pageCount'] ?? 1)));
    $ocrPages = max(0, min(R170_MAX_PAGES, (int)($result['ocrPages'] ?? 0)));
    $companyId = (string)$company['id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $id = clean_text($input['jobId'] ?? '', 'Invoice', 64);
        $row = r170_job_row($company, $id, true);
        $token = (string)($input['leaseToken'] ?? '');
        if ((string)$row['state'] !== 'processing') {
            // A repeated submit after success returns the stored result; nothing is created twice.
            if ($row['processed_at'] !== null && hash_equals((string)($row['lease_token'] ?? ''), $token)) { $pdo->commit(); return ['reused' => true, 'jobs' => r170_document_jobs($company, (string)$row['document_id'])]; }
            fail('This reading job is no longer held by this window.', 409, 'invoice_intel_lease_lost');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || !hash_equals((string)$row['lease_token'], $token)) fail('This reading job is no longer held by this window.', 409, 'invoice_intel_lease_lost');
        $documentId = (string)$row['document_id'];
        $pdo->prepare('DELETE FROM invoice_intel_jobs WHERE company_id=? AND document_id=? AND segment_index>0 AND target_id IS NULL')->execute([$companyId, $documentId]);
        $ids = [];
        foreach ($segments as $index => $raw) {
            $segment = r170_clean_segment($raw);
            if ($index === 0) {
                $jobId = (string)$row['id'];
            } else {
                $jobId = new_id('iijob');
                $pdo->prepare('INSERT INTO invoice_intel_jobs (id,company_id,document_id,batch_id,segment_index,state,attempts,max_attempts,created_by,updated_by) VALUES (?,?,?,?,?,\'processing\',?,?,?,?)')
                    ->execute([$jobId, $companyId, $documentId, (string)$row['batch_id'], $index, (int)$row['attempts'], R170_MAX_ATTEMPTS, (string)$user['id'], (string)$user['id']]);
            }
            $evidence = r170_encrypt($segment['evidence'], $companyId . '|' . $jobId);
            $pdo->prepare('UPDATE invoice_intel_jobs SET page_from=?,page_to=?,doc_class=?,extraction_method=?,page_count=?,ocr_pages=?,fields_json=?,lines_json=?,evidence_enc=?,content_simhash=?,processed_at=UTC_TIMESTAMP(),lease_expires_at=NULL,leased_by=NULL,failure_code=NULL,updated_by=? WHERE id=? AND company_id=?')
                ->execute([$segment['pageFrom'], $segment['pageTo'], $segment['docClass'], $method, $pageCount, $ocrPages, r170_json($segment['fields']), r170_json($segment['lines']), $evidence, $segment['simhash'], (string)$user['id'], $jobId, $companyId]);
            $ids[] = $jobId;
        }
        // Keep the token on job 0 so a repeated submit is recognised; it no longer grants anything (state is not processing).
        foreach ($ids as $jobId) r170_reanalyse($user, $company, $jobId, true);
        audit_event($user, $companyId, 'invoice_intel.read', 'native_agent_document', $documentId, ['jobs' => $ids, 'segments' => count($ids), 'method' => $method, 'pageCount' => $pageCount, 'ocrPages' => $ocrPages, 'rawTextStored' => false, 'accountingWrites' => 0, 'providerAttempts' => 0]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['reused' => false, 'jobs' => r170_document_jobs($company, $documentId)];
}

function r170_document_jobs(array $company, string $documentId): array
{
    $q = db()->prepare('SELECT id FROM invoice_intel_jobs WHERE company_id=? AND document_id=? ORDER BY segment_index');
    $q->execute([(string)$company['id'], $documentId]);
    return array_map(static fn($id) => r170_public_row($company, r170_job_row($company, (string)$id)), $q->fetchAll(PDO::FETCH_COLUMN));
}

/* ---------- analysis: supplier, suggestions, validation, duplicates, confidence ---------- */

function r170_supplier_match(array $company, array $fields, ?string $chosen, ?string $chosenBy): array
{
    $companyId = (string)$company['id'];
    if ($chosen && $chosenBy === 'user') {
        $q = db()->prepare("SELECT id,name FROM vendors WHERE id=? AND company_id=? AND active=1 AND status='active' LIMIT 1");
        $q->execute([$chosen, $companyId]);
        $vendor = $q->fetch();
        if ($vendor) return ['status' => 'matched', 'vendorId' => (string)$vendor['id'], 'vendorName' => (string)$vendor['name'], 'evidence' => ['Chosen by a reviewer'], 'candidates' => []];
    }
    $candidate = ['partyName' => (string)($fields['supplierName']['value'] ?? ''), 'partyEmail' => (string)($fields['supplierEmail']['value'] ?? ''),
        'businessIdentifier' => (string)($fields['supplierTaxNumbers']['value'] ?? ''), 'address' => (string)($fields['supplierAddress']['value'] ?? '')];
    $ranked = native_vendor_suggestions($company, ['document_type' => 'vendor_bill', 'candidate_json' => r170_json($candidate)]);
    $suggestions = $ranked['suggestions'] ?? [];
    // Extra evidence from the vendor record itself: phone digits and postal code, and a close (not exact) name.
    $vendors = db()->prepare("SELECT id,name,phone,postal_code FROM vendors WHERE company_id=? AND active=1 AND status='active' LIMIT 2000");
    $vendors->execute([$companyId]);
    $phone = preg_replace('/\D/', '', (string)($fields['supplierPhone']['value'] ?? '')) ?? '';
    $address = strtoupper(preg_replace('/\s/', '', (string)($fields['supplierAddress']['value'] ?? '')) ?? '');
    $words = r170_words((string)($fields['supplierName']['value'] ?? ''));
    $byId = [];
    foreach ($suggestions as $s) $byId[(string)$s['vendorId']] = $s;
    foreach ($vendors->fetchAll() as $v) {
        $id = (string)$v['id'];
        $evidence = [];
        $score = 0;
        $vp = preg_replace('/\D/', '', (string)$v['phone']) ?? '';
        if (strlen($phone) >= 10 && strlen($vp) >= 10 && substr($phone, -10) === substr($vp, -10)) { $evidence[] = 'Same phone number'; $score = max($score, 8600); }
        $pc = strtoupper(preg_replace('/\s/', '', (string)$v['postal_code']) ?? '');
        if ($pc !== '' && strlen($pc) >= 5 && $address !== '' && str_contains($address, $pc)) { $evidence[] = 'Postal code in the printed address'; $score = max($score, 6000); }
        $similarity = r152_similarity($words, r170_words((string)$v['name']));
        if ($similarity >= 0.8 && $words) { $evidence[] = 'Similar name'; $score = max($score, 7000); }
        if (!$evidence) continue;
        if (isset($byId[$id])) {
            $byId[$id]['scoreBps'] = max((int)$byId[$id]['scoreBps'], $score);
            $byId[$id]['evidence'] = array_values(array_unique(array_merge((array)($byId[$id]['evidence'] ?? []), $evidence)));
        } else {
            $byId[$id] = ['vendorId' => $id, 'vendorName' => (string)$v['name'], 'scoreBps' => $score, 'evidence' => $evidence];
        }
    }
    // Two independent pieces of evidence for one vendor are stronger than either alone.
    foreach ($byId as &$s) { $kinds = count(array_unique((array)($s['evidence'] ?? []))); if ($kinds >= 2) $s['scoreBps'] = min(10000, (int)$s['scoreBps'] + 800); }
    unset($s);
    $list = array_values($byId);
    usort($list, static fn($a, $b) => [(int)$b['scoreBps'], (string)$a['vendorName']] <=> [(int)$a['scoreBps'], (string)$b['vendorName']]);
    $list = array_slice(array_values(array_filter($list, static fn($s) => (int)$s['scoreBps'] >= 5000)), 0, 5);
    $candidates = array_map(static fn($s) => ['vendorId' => (string)$s['vendorId'], 'vendorName' => (string)$s['vendorName'], 'scoreBps' => (int)$s['scoreBps'], 'evidence' => array_values(array_unique((array)($s['evidence'] ?? [])))], $list);
    $top = $candidates[0] ?? null;
    $next = $candidates[1] ?? null;
    if (!$top) return ['status' => 'none', 'vendorId' => null, 'vendorName' => null, 'evidence' => [], 'candidates' => []];
    $strong = $top['scoreBps'] >= 8500;
    if ($strong && (!$next || $top['scoreBps'] - $next['scoreBps'] >= 1000)) return ['status' => 'matched', 'vendorId' => $top['vendorId'], 'vendorName' => $top['vendorName'], 'evidence' => $top['evidence'], 'candidates' => $candidates];
    if ($strong && $next && $next['scoreBps'] >= 8500) return ['status' => 'conflict', 'vendorId' => null, 'vendorName' => null, 'evidence' => [], 'candidates' => $candidates];
    return ['status' => 'uncertain', 'vendorId' => null, 'vendorName' => null, 'evidence' => [], 'candidates' => $candidates];
}

/** Learned rules from earlier approved drafts, then the R152 history matcher, then the vendor's default account. */
function r170_suggestions(array $company, ?string $vendorId, array $lines): array
{
    $companyId = (string)$company['id'];
    $out = [];
    if (!$vendorId) return $out;
    $rules = db()->prepare('SELECT keywords,account_id,tax_code_id,uses FROM invoice_intel_vendor_rules WHERE company_id=? AND vendor_id=? ORDER BY uses DESC,updated_at DESC LIMIT 300');
    $rules->execute([$companyId, $vendorId]);
    $rules = $rules->fetchAll();
    $usable = db()->prepare("SELECT id,name FROM accounts WHERE company_id=? AND active=1 AND is_control=0 AND account_type IN ('expense','asset')");
    $usable->execute([$companyId]);
    $accounts = [];
    foreach ($usable->fetchAll() as $a) $accounts[(string)$a['id']] = (string)$a['name'];
    $history = r152_coding_suggestions($company, ['document_type' => 'vendor_bill', 'id' => '', 'candidate_json' => r170_json(['partyId' => $vendorId, 'lineItems' => array_map(static fn($l) => ['description' => (string)($l['description'] ?? '')], $lines)])]);
    $byIndex = [];
    foreach ($history['lines'] ?? [] as $s) $byIndex[(int)$s['index']] = $s;
    $vendor = db()->prepare('SELECT default_expense_account_id FROM vendors WHERE id=? AND company_id=?');
    $vendor->execute([$vendorId, $companyId]);
    $vendorDefault = (string)($vendor->fetchColumn() ?: '');
    $freight = null;
    foreach ($accounts as $id => $name) if (preg_match('/freight|shipping|delivery|transport|courier/i', $name)) { $freight = $id; break; }
    foreach ($lines as $index => $line) {
        $words = r170_words((string)($line['description'] ?? ''));
        $best = null; $score = 0.0;
        foreach ($rules as $rule) { $sim = r152_similarity($words, explode(' ', (string)$rule['keywords'])); if ($sim > $score) { $score = $sim; $best = $rule; } }
        if ($best && $score >= 0.66 && isset($accounts[(string)$best['account_id']])) {
            $out[] = ['index' => $index, 'accountId' => (string)$best['account_id'], 'taxCodeId' => $best['tax_code_id'], 'source' => 'learned',
                'reason' => sprintf('You coded "%s" to %s on %d earlier invoice%s from this vendor', mb_substr((string)$best['keywords'], 0, 50), $accounts[(string)$best['account_id']], (int)$best['uses'], (int)$best['uses'] === 1 ? '' : 's')];
            continue;
        }
        if (($line['kind'] ?? '') === 'freight' && $freight) { $out[] = ['index' => $index, 'accountId' => $freight, 'taxCodeId' => null, 'source' => 'freight', 'reason' => 'Freight line; ' . $accounts[$freight] . ' is the company\'s freight account']; continue; }
        if (isset($byIndex[$index]) && isset($accounts[(string)$byIndex[$index]['accountId']])) {
            $s = $byIndex[$index];
            $out[] = ['index' => $index, 'accountId' => (string)$s['accountId'], 'taxCodeId' => $s['taxCodeId'] ?? null, 'source' => (string)$s['source'], 'reason' => (string)$s['reason'] . ' (' . $accounts[(string)$s['accountId']] . ')'];
            continue;
        }
        if ($vendorDefault !== '' && isset($accounts[$vendorDefault])) $out[] = ['index' => $index, 'accountId' => $vendorDefault, 'taxCodeId' => null, 'source' => 'vendor_setting', 'reason' => 'The vendor\'s default expense account (' . $accounts[$vendorDefault] . ') in Vendors'];
    }
    return $out;
}

/** The tax code whose rate matches the tax printed on the document, when only one does. */
function r170_tax_code_for_document(array $company, array $taxes, int $base): ?array
{
    if (!function_exists('tax_setup_mode') || tax_setup_mode($company) !== 'codes' || $base <= 0) return null;
    $printed = array_sum(array_map(static fn($t) => (int)$t['amountCents'], array_filter($taxes, static fn($t) => empty($t['included']))));
    $matches = [];
    foreach (tax_codes_list((string)$company['id']) as $code) {
        $calc = tax_code_compute($code, $base, 'exclusive');
        if ($printed > 0 && abs((int)$calc['tax'] - $printed) <= 2) $matches[] = $code;
        if ($printed === 0 && (int)$code['totalRateMpct'] === 0) $matches[] = $code;
    }
    return count($matches) === 1 ? $matches[0] : null;
}

function r170_add(array &$list, string $code, string $severity, string $message, array $extra = []): void
{
    $list[] = ['code' => $code, 'severity' => $severity, 'message' => $message] + $extra;
}

/** Tegh's own figures for the draft (same service as saving), without saving anything. */
function r170_dry_run(array $company, array $input): array
{
    try {
        return ['ok' => true, 'values' => tegh_service_boundary(static fn() => r151_bill_input_values($company, $input))];
    } catch (TeghServiceFailure $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'code' => $e->errorCode];
    }
}

function r170_bill_input(array $company, array $row, array $fields, array $lines): array
{
    $taxInclusive = !empty($fields['taxInclusive']['value']);
    $out = [];
    foreach ($lines as $line) {
        $amount = (int)($line['amountCents'] ?? 0);
        $qty = (string)($line['quantity'] ?? '');
        $unit = $line['unitPriceCents'] ?? null;
        $description = (string)($line['description'] ?? '') ?: 'Invoice line';
        // Quantity × price is kept when it gives the exact line amount; otherwise the amount is entered as one unit and
        // the printed quantity, price and discount are kept in the description. No amount is ever adjusted.
        if ($qty !== '' && $unit !== null && empty($line['discountCents']) && r170_line_amount($qty, (int)$unit) === $amount) {
            $entry = ['description' => $description, 'quantity' => $qty, 'unitPriceCents' => (int)$unit];
        } else {
            $detail = [];
            if ($qty !== '') $detail[] = 'qty ' . $qty;
            if ($unit !== null) $detail[] = '@ ' . number_format((int)$unit / 100, 2, '.', '');
            if (!empty($line['discountCents'])) $detail[] = 'less ' . number_format((int)$line['discountCents'] / 100, 2, '.', '') . ' discount';
            $entry = ['description' => mb_substr($description . ($detail ? ' (' . implode(' ', $detail) . ')' : ''), 0, 500), 'quantity' => '1', 'unitPriceCents' => max(0, $amount)];
        }
        $entry['accountId'] = (string)($line['accountId'] ?? '');
        if (!empty($line['itemCode'])) $entry['description'] = mb_substr('[' . $line['itemCode'] . '] ' . $entry['description'], 0, 500);
        if (function_exists('tax_setup_mode') && tax_setup_mode($company) === 'codes') $entry['taxCodeId'] = (string)($line['taxCodeId'] ?? '');
        else { $entry['applyGstHst'] = ($line['applyGstHst'] ?? false) === true; $entry['applyPst'] = ($line['applyPst'] ?? false) === true; }
        $out[] = $entry;
    }
    $memo = 'Invoice Intelligence · ' . mb_substr((string)$row['original_name'], 0, 120);
    if (!empty($fields['poNumber']['value'])) $memo .= ' · PO ' . $fields['poNumber']['value'];
    $input = ['vendorId' => (string)$row['vendor_id'], 'number' => (string)($fields['invoiceNumber']['value'] ?? ''), 'billDate' => (string)($fields['invoiceDate']['value'] ?? ''),
        'currency' => (string)($fields['currency']['value'] ?? $company['currency']), 'taxEntryMode' => $taxInclusive ? 'inclusive' : 'exclusive', 'lines' => $out, 'memo' => mb_substr($memo, 0, 500)];
    if (!empty($fields['dueDate']['value'])) $input['dueDate'] = (string)$fields['dueDate']['value'];
    elseif (preg_match('/\bnet\s*(\d{1,3})\b/i', (string)($fields['paymentTerms']['value'] ?? ''), $m)) $input['paymentTermsDays'] = (int)$m[1];
    elseif (preg_match('/\b(?:due\s+on\s+receipt|payable\s+[aà]\s+r[eé]ception)\b/iu', (string)($fields['paymentTerms']['value'] ?? ''))) $input['paymentTermsDays'] = 0;
    return $input;
}

function r170_analyse(array $user, array $company, array $row): array
{
    $companyId = (string)$company['id'];
    $fields = r170_decode($row['fields_json'] ?? null);
    $taxes = $fields['_taxes'] ?? [];
    $lines = r170_decode($row['lines_json'] ?? null);
    $class = (string)$row['doc_class'];
    $currency = (string)($fields['currency']['value'] ?? $company['currency']);
    $v = [];

    // Supplier
    $supplier = r170_supplier_match($company, $fields, $row['vendor_id'] ?? null, $row['vendor_chosen_by'] ?? null);
    $vendorId = $supplier['vendorId'];
    if ($supplier['status'] === 'none') r170_add($v, 'supplier_unknown', 'review', 'No supplier in Vendors matches this invoice. Add the supplier in Payables › Vendors first (Tegh does not create suppliers from a document), then choose it here.');
    elseif ($supplier['status'] === 'uncertain') r170_add($v, 'supplier_uncertain', 'review', 'The supplier match is not certain. Choose the supplier from the list.');
    elseif ($supplier['status'] === 'conflict') r170_add($v, 'supplier_conflict', 'review', 'More than one supplier matches this invoice. Choose the right one.');

    // Document class
    if ($class === 'statement') r170_add($v, 'not_invoice_statement', 'block', 'This looks like a statement of account, which lists invoices rather than billing for goods or services. Upload the invoices themselves.');
    elseif ($class === 'other' || $class === 'unknown') r170_add($v, 'not_invoice', 'block', 'This does not look like a supplier invoice or credit note. Check the file. If it is an invoice, change the document type and save.');
    elseif ($class === 'receipt') r170_add($v, 'receipt', 'review', 'This looks like a receipt (already paid). It can be entered as a vendor invoice and then recorded as paid.');

    // Header
    $number = (string)($fields['invoiceNumber']['value'] ?? '');
    if ($number === '') r170_add($v, 'number_missing', 'review', 'No invoice number was found. Enter it from the document.', ['field' => 'invoiceNumber']);
    $date = (string)($fields['invoiceDate']['value'] ?? '');
    if ($date === '' && !empty($fields['invoiceDate']['alternatives'])) r170_add($v, 'date_ambiguous', 'review', 'The invoice date could be read either way (' . implode(' or ', $fields['invoiceDate']['alternatives']) . '). Choose the right one.', ['field' => 'invoiceDate']);
    elseif ($date === '') r170_add($v, 'date_missing', 'review', 'No invoice date was found. Enter it from the document.', ['field' => 'invoiceDate']);
    else {
        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        if ($date > $today) r170_add($v, 'date_future', 'review', 'The invoice date is in the future. Check it.', ['field' => 'invoiceDate']);
        $booksStart = (string)($company['books_start_date'] ?? '');
        if ($booksStart !== '' && $date < $booksStart) r170_add($v, 'date_before_books', 'review', 'The invoice date is before the company\'s books start date. Enter it as an opening balance instead.', ['field' => 'invoiceDate']);
        if (function_exists('period_lock_for_date') && period_lock_for_date($companyId, $date) !== null) r170_add($v, 'period_locked', 'warn', 'The invoice date is in a locked period. A draft can be saved, but issuing it will be refused until the period is reopened.', ['field' => 'invoiceDate']);
        $due = (string)($fields['dueDate']['value'] ?? '');
        if ($due !== '' && $due < $date) r170_add($v, 'due_before_date', 'review', 'The due date is before the invoice date.', ['field' => 'dueDate']);
    }
    if (!company_currency($companyId, $currency)) r170_add($v, 'currency_unknown', 'review', "The invoice is in $currency, which is not set up for this company. Add the currency in Settings first.", ['field' => 'currency']);
    if ($class === 'credit_note' && empty($fields['originalInvoiceNumber']['value'])) r170_add($v, 'credit_reference_missing', 'review', 'A supplier credit note needs the number of the invoice it credits. Enter it from the document.', ['field' => 'originalInvoiceNumber']);

    // Lines and arithmetic (exact cents, never adjusted)
    if (!$lines) r170_add($v, 'lines_missing', 'review', 'No invoice lines were found. Add at least one line from the document.');
    $sum = 0;
    foreach ($lines as $i => $line) {
        $amount = $line['amountCents'] ?? null;
        if ($amount === null) { r170_add($v, 'line_amount_missing', 'review', 'Line ' . ($i + 1) . ' has no amount.', ['line' => $i]); continue; }
        $sum += (int)$amount;
        if (isset($line['quantity'], $line['unitPriceCents'])) {
            $expected = r170_line_amount((string)$line['quantity'], (int)$line['unitPriceCents']) - (int)($line['discountCents'] ?? 0);
            if (abs($expected - (int)$amount) > 1) r170_add($v, 'line_arithmetic', 'review', sprintf('Line %d: %s × %s%s = %s, but the line shows %s.', $i + 1, $line['quantity'], r170_money((int)$line['unitPriceCents'], $currency), !empty($line['discountCents']) ? ' less ' . r170_money((int)$line['discountCents'], $currency) : '', r170_money($expected, $currency), r170_money((int)$amount, $currency)), ['line' => $i]);
        }
        if ((int)$amount < 0 && $class !== 'credit_note') r170_add($v, 'line_negative', 'review', 'Line ' . ($i + 1) . ' is negative. Tegh vendor invoice lines must be positive: enter a discount by reducing the line amounts, or record a supplier credit note.', ['line' => $i]);
    }
    $sub = isset($fields['subtotalCents']) ? (int)$fields['subtotalCents']['value'] : null;
    $tax = isset($fields['taxCents']) ? (int)$fields['taxCents']['value'] : null;
    $total = isset($fields['totalCents']) ? (int)$fields['totalCents']['value'] : null;
    $discount = isset($fields['discountCents']) ? abs((int)$fields['discountCents']['value']) : 0;
    $freight = isset($fields['freightCents']) ? (int)$fields['freightCents']['value'] : 0;
    $rounding = isset($fields['roundingCents']) ? (int)$fields['roundingCents']['value'] : 0;
    $taxInclusive = !empty($fields['taxInclusive']['value']);
    $freightInLines = (bool)array_filter($lines, static fn($l) => ($l['kind'] ?? '') === 'freight');
    if ($total === null) r170_add($v, 'total_missing', 'review', 'No invoice total was found. Enter it from the document.', ['field' => 'totalCents']);
    if ($lines && $sub !== null && !$taxInclusive) {
        $candidates = [$sub, $sub - $discount, $sub + ($freightInLines ? 0 : 0)];
        $linesMatch = in_array($sum, $candidates, true) || ($freightInLines && in_array($sum - $freight, $candidates, true));
        if (!$linesMatch) r170_add($v, 'lines_vs_subtotal', 'review', sprintf('The lines add up to %s, but the subtotal shows %s.', r170_money($sum, $currency), r170_money($sub, $currency)), ['field' => 'subtotalCents']);
    }
    if ($taxes && $tax !== null) {
        $components = array_sum(array_map(static fn($t) => (int)$t['amountCents'], array_filter($taxes, static fn($t) => empty($t['included']))));
        if ($components > 0 && $components !== $tax) r170_add($v, 'tax_components', 'review', sprintf('The tax lines add up to %s, but the tax total shows %s.', r170_money($components, $currency), r170_money($tax, $currency)), ['field' => 'taxCents']);
    }
    if ($total !== null && $sub !== null && $tax !== null && !$taxInclusive) {
        $ok = false;
        foreach ([0, $freight] as $f) foreach ([0, $discount] as $d) foreach ([0, $rounding] as $r) if ($sub - $d + $f + $tax + $r === $total) $ok = true;
        if (!$ok) r170_add($v, 'document_arithmetic', 'block', sprintf('The document\'s own figures do not add up: subtotal %s + tax %s ≠ total %s. Check these three fields against the document.', r170_money($sub, $currency), r170_money($tax, $currency), r170_money($total, $currency)), ['field' => 'totalCents']);
    }
    if ($taxInclusive && $lines && $total !== null && $sum !== $total) r170_add($v, 'lines_vs_total_inclusive', 'review', sprintf('Prices include tax, so the lines should add up to the total: lines %s, total %s.', r170_money($sum, $currency), r170_money($total, $currency)), ['field' => 'totalCents']);
    if (isset($fields['paidCents'], $fields['balanceDueCents']) && $total !== null && $total - (int)$fields['paidCents']['value'] !== (int)$fields['balanceDueCents']['value'])
        r170_add($v, 'balance_due', 'warn', 'Total less amount paid does not equal the balance due shown on the document.', ['field' => 'balanceDueCents']);
    if (isset($fields['paidCents']) && (int)$fields['paidCents']['value'] > 0) r170_add($v, 'partly_paid', 'warn', 'The document shows ' . r170_money((int)$fields['paidCents']['value'], $currency) . ' already paid. The vendor invoice is drafted for the full total; record the payment separately.');

    // Coding: suggestions fill only lines without a reviewer's choice.
    $suggestions = r170_suggestions($company, $vendorId, $lines);
    $byIndex = [];
    foreach ($suggestions as $s) $byIndex[(int)$s['index']] = $s;
    $codesMode = function_exists('tax_setup_mode') && tax_setup_mode($company) === 'codes';
    $docCode = $codesMode ? r170_tax_code_for_document($company, $taxes, $taxInclusive ? 0 : max(0, $sub ?? $sum)) : null;
    $home = $codesMode ? tax_code_home($company) : null;
    $changedLines = false;
    foreach ($lines as $i => &$line) {
        if (empty($line['accountId']) && isset($byIndex[$i])) { $line['accountId'] = $byIndex[$i]['accountId']; $line['accountSource'] = $byIndex[$i]['source']; $line['accountReason'] = $byIndex[$i]['reason']; $changedLines = true; }
        if ($codesMode && empty($line['taxCodeId'])) {
            if (!empty($byIndex[$i]['taxCodeId'])) { $line['taxCodeId'] = $byIndex[$i]['taxCodeId']; $line['taxSource'] = 'history'; $line['taxReason'] = 'Tax code used for this vendor before'; $changedLines = true; }
            elseif ($docCode) { $line['taxCodeId'] = $docCode['id']; $line['taxSource'] = 'document_rate'; $line['taxReason'] = 'Its rate gives the tax printed on the invoice (' . $docCode['code'] . ')'; $changedLines = true; }
            elseif ($home && $taxes) { $line['taxCodeId'] = $home['id']; $line['taxSource'] = 'home'; $line['taxReason'] = 'The company\'s home tax code (' . $home['code'] . '); the printed tax did not identify one code'; $changedLines = true; }
        }
        if (!$codesMode && !array_key_exists('applyGstHst', $line)) {
            $labels = strtoupper(implode(' ', array_map(static fn($t) => (string)$t['label'], $taxes)));
            $line['applyGstHst'] = (bool)$company['tax_registered'] && (bool)preg_match('/GST|HST|TPS|TVH/', $labels);
            $line['applyPst'] = (bool)($company['pst_registered'] ?? false) && (bool)preg_match('/PST|QST|RST|TVQ|TVP/', $labels);
            $line['taxSource'] = 'document_labels'; $line['taxReason'] = 'From the taxes printed on the invoice';
            $changedLines = true;
        }
        if (empty($line['accountId'])) r170_add($v, 'account_missing', 'review', 'Line ' . ($i + 1) . ' needs a GL account.', ['line' => $i]);
        else {
            $account = company_account($companyId, (string)$line['accountId']);
            if (!$account || !in_array((string)$account['account_type'], ['expense', 'asset'], true) || (bool)$account['is_control'])
                r170_add($v, 'account_not_allowed', 'block', 'Line ' . ($i + 1) . ' uses an account that vendor invoices cannot use (inactive, a control account, or not an expense or asset). Choose another.', ['line' => $i]);
        }
        if ($codesMode && !empty($line['taxCodeId']) && !tax_code_get($companyId, (string)$line['taxCodeId'])) r170_add($v, 'tax_code_not_allowed', 'block', 'Line ' . ($i + 1) . ' uses a tax code that is not active. Choose another.', ['line' => $i]);
        if ($codesMode && empty($line['taxCodeId']) && $taxes) r170_add($v, 'tax_code_missing', 'review', 'Line ' . ($i + 1) . ' needs a tax code: the invoice charges tax.', ['line' => $i]);
    }
    unset($line);

    // Tegh's own figures for the draft against the document's.
    $computed = null;
    if ($vendorId && $number !== '' && $date !== '' && $lines && !array_filter($v, static fn($x) => in_array($x['code'], ['account_missing', 'account_not_allowed', 'tax_code_not_allowed', 'line_amount_missing', 'line_negative', 'currency_unknown'], true))) {
        $rowForInput = $row; $rowForInput['vendor_id'] = $vendorId;
        $dry = r170_dry_run($company, r170_bill_input($company, $rowForInput, $fields, $lines));
        if ($dry['ok']) {
            $values = $dry['values'];
            $computed = ['subtotalCents' => (int)$values['foreignSubtotal'], 'taxCents' => (int)$values['foreignGst'] + (int)$values['foreignPst'], 'totalCents' => (int)$values['foreignTotal'], 'baseTotalCents' => (int)$values['total'], 'currency' => (string)$values['currency']];
            if ($total !== null && $computed['totalCents'] !== $total && $class !== 'credit_note') {
                $difference = $computed['totalCents'] - $total;
                $rounding = abs($difference) <= max(2, count($lines));
                r170_add($v, 'tegh_total_differs', 'review', sprintf('%s Tegh calculates %s; the document shows %s (difference %s). %s', $rounding ? 'Rounding difference:' : 'Totals differ:', r170_money($computed['totalCents'], $currency), r170_money($total, $currency), r170_money($difference, $currency), $rounding ? 'Tegh rounds tax per line. Accept Tegh\'s total, or adjust a line to match the supplier.' : 'Check the tax code on each line, discounts and charges.'), ['field' => 'totalCents', 'differenceCents' => $difference, 'rounding' => $rounding]);
            }
            if ($tax !== null && $computed['taxCents'] !== $tax && !$taxInclusive && abs($computed['taxCents'] - $tax) > max(2, count($lines))) r170_add($v, 'tax_mismatch', 'review', sprintf('Tax does not match: Tegh calculates %s with the chosen tax codes; the document shows %s.', r170_money($computed['taxCents'], $currency), r170_money($tax, $currency)), ['field' => 'taxCents']);
        } else {
            r170_add($v, 'tegh_check_failed', 'review', 'Tegh could not prepare this vendor invoice yet: ' . $dry['message']);
        }
    }

    // Duplicates
    $duplicates = r170_duplicates($company, $row, $vendorId, $number, $total, $date, $currency, (string)($row['content_simhash'] ?? ''));
    $overridden = db()->prepare('SELECT duplicate_of FROM invoice_intel_overrides WHERE company_id=? AND job_id=?');
    $overridden->execute([$companyId, (string)$row['id']]);
    $overrides = array_flip(array_map('strval', $overridden->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($duplicates as &$d) {
        $key = $d['kind'] . ':' . $d['id'];
        $d['overridden'] = isset($overrides[$key]) && $d['reason'] !== 'same_file_drafted';
        if ($d['overridden']) continue;
        if ($d['class'] === 'exact') r170_add($v, 'duplicate_exact', 'block', $d['message'], ['duplicate' => $key]);
        elseif ($d['class'] === 'probable') r170_add($v, 'duplicate_probable', 'block', $d['message'], ['duplicate' => $key]);
        else r170_add($v, 'duplicate_review', 'review', $d['message'], ['duplicate' => $key]);
    }
    unset($d);

    // Confidence: stated rules, no invented percentages.
    $flagged = [];
    foreach ($v as $item) if (isset($item['field'])) $flagged[(string)$item['field']] = true;
    $levels = [];
    foreach ($fields as $name => $field) {
        if ($name === '_taxes' || $name === '_pages' || !is_array($field)) continue;
        $reasons = [];
        $source = (string)($field['source'] ?? 'text');
        $strong = $source === 'user' || $source === 'text' || ($source === 'ocr' && (int)($field['ocrConf'] ?? 0) >= 85);
        $weak = $source === 'ocr' && (int)($field['ocrConf'] ?? 0) < 60;
        if ($source === 'user') $reasons[] = 'Entered or confirmed by a reviewer';
        elseif ($source === 'text') $reasons[] = 'Read from the PDF\'s text layer';
        elseif ($source === 'ocr') $reasons[] = 'Read by OCR (word confidence ' . (int)($field['ocrConf'] ?? 0) . ' of 100)';
        elseif ($source === 'derived') $reasons[] = 'Calculated from other printed figures';
        elseif ($source === 'memory') $reasons[] = 'From what Tegh learned about this supplier';
        if (!empty($field['label'])) $reasons[] = 'Next to its printed label'; elseif ($source !== 'user') $reasons[] = 'No label found beside it';
        if (!empty($field['alternatives'])) $reasons[] = 'Can be read more than one way';
        if (isset($flagged[$name])) $reasons[] = 'A check below involves this field';
        $level = ($weak || !empty($field['alternatives']) || isset($flagged[$name])) ? 'low' : (($strong && (!empty($field['label']) || $source === 'user')) ? 'high' : 'medium');
        $levels[$name] = ['level' => $level, 'reasons' => $reasons];
    }
    $required = ['invoiceNumber', 'invoiceDate', 'totalCents'];
    $requiredLevels = array_map(static fn($n) => $levels[$n]['level'] ?? 'low', $required);
    $severities = array_column($v, 'severity');
    $reviewClass = in_array('block', $severities, true) ? 'blocked' : ((in_array('review', $severities, true) || in_array('low', $requiredLevels, true) || $supplier['status'] !== 'matched') ? 'review' : 'ready');
    $documentLevel = $reviewClass === 'ready' && !in_array('medium', $requiredLevels, true) ? 'high' : ($reviewClass === 'blocked' || in_array('low', $requiredLevels, true) ? 'low' : 'medium');

    return ['supplier' => $supplier, 'vendorId' => $vendorId, 'validations' => $v, 'duplicates' => $duplicates, 'suggestions' => $suggestions, 'computed' => $computed,
        'confidence' => ['fields' => $levels, 'document' => $documentLevel,
            'method' => 'High: read from the text layer, or by OCR with word confidence of at least 85, next to its printed label, and no check involves it. Low: OCR word confidence under 60, a value that can be read more than one way, or a failed check. Medium: everything else.'],
        'reviewClass' => $reviewClass, 'lines' => $changedLines ? $lines : null, 'taxMode' => $codesMode ? 'codes' : 'legacy'];
}

function r170_duplicates(array $company, array $row, ?string $vendorId, string $number, ?int $total, string $date, string $currency, string $simhash): array
{
    $companyId = (string)$company['id'];
    $norm = $number !== '' ? r170_number_norm($number) : '';
    $out = [];
    $near = static fn(string $a, string $b): bool => $a !== '' && $b !== '' && abs((strtotime($a) - strtotime($b)) / 86400) <= 7;
    // Saved vendor invoices
    $q = db()->prepare("SELECT b.id,b.number,b.bill_date,b.foreign_total_cents,b.currency,b.status,b.vendor_id,v.name vendor_name FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id WHERE b.company_id=? AND b.status<>'void' AND (" . ($vendorId ? 'b.vendor_id=? OR ' : '') . "b.number=? OR b.number LIKE ?) ORDER BY b.bill_date DESC LIMIT 500");
    $params = [$companyId];
    if ($vendorId) $params[] = $vendorId;
    $params[] = $number !== '' ? $number : '\0none';
    $params[] = $norm !== '' && $norm !== '0' ? '%' . $norm : '\0none';
    $q->execute($params);
    foreach ($q->fetchAll() as $bill) {
        if ((string)$bill['id'] === (string)($row['target_id'] ?? '')) continue;
        $sameVendor = $vendorId && (string)$bill['vendor_id'] === $vendorId;
        $sameNumber = $norm !== '' && r170_number_norm((string)$bill['number']) === $norm;
        $sameTotal = $total !== null && (int)$bill['foreign_total_cents'] === $total && (string)$bill['currency'] === $currency;
        $label = sprintf('vendor invoice %s from %s (%s, %s)', $bill['number'], $bill['vendor_name'], $bill['bill_date'], r170_money((int)$bill['foreign_total_cents'], (string)$bill['currency']));
        $item = ['kind' => 'bill', 'id' => (string)$bill['id'], 'number' => (string)$bill['number'], 'date' => (string)$bill['bill_date'], 'totalCents' => (int)$bill['foreign_total_cents'], 'status' => (string)$bill['status']];
        if ($sameVendor && $sameNumber && $sameTotal) $out[] = $item + ['class' => 'exact', 'reason' => 'vendor_number_total', 'message' => 'Already in Tegh: ' . $label . ' has the same supplier, number and total.'];
        elseif ($sameVendor && $sameNumber) $out[] = $item + ['class' => 'probable', 'reason' => 'vendor_number', 'message' => 'Probable duplicate: ' . $label . ' has the same supplier and invoice number.'];
        elseif ($sameVendor && $sameTotal && $near((string)$bill['bill_date'], $date)) $out[] = $item + ['class' => 'review', 'reason' => 'vendor_total_date', 'message' => 'Check: ' . $label . ' has the same supplier and total within 7 days.'];
        elseif (!$sameVendor && $number !== '' && (string)$bill['number'] === $number) $out[] = $item + ['class' => 'review', 'reason' => 'number_other_vendor', 'message' => 'Another supplier\'s invoice already uses number ' . $number . ' (' . $label . '). Tegh needs vendor invoice numbers to be unique, so add a supplier prefix to this number if it is a different invoice.'];
    }
    // Other invoices read by Invoice Intelligence
    $q = db()->prepare('SELECT j.id,j.document_id,j.segment_index,j.vendor_id,j.invoice_number,j.invoice_number_norm,j.invoice_date,j.total_cents,j.currency,j.content_simhash,j.target_id,j.state,d.sha256,d.original_name FROM invoice_intel_jobs j JOIN native_agent_documents d ON d.id=j.document_id AND d.company_id=j.company_id WHERE j.company_id=? AND j.id<>? AND j.state NOT IN (\'cancelled\',\'failed\') ORDER BY j.created_at DESC LIMIT 1000');
    $q->execute([$companyId, (string)$row['id']]);
    $mineSha = (string)($row['sha256'] ?? '');
    foreach ($q->fetchAll() as $other) {
        $label = sprintf('"%s"%s', $other['original_name'], $other['invoice_number'] ? ' (invoice ' . $other['invoice_number'] . ')' : '');
        $item = ['kind' => 'job', 'id' => (string)$other['id'], 'number' => (string)$other['invoice_number'], 'date' => (string)$other['invoice_date'], 'totalCents' => $other['total_cents'] !== null ? (int)$other['total_cents'] : null, 'status' => (string)$other['state']];
        $sameSegment = $mineSha !== '' && (string)$other['sha256'] === $mineSha && (int)$other['segment_index'] === (int)$row['segment_index'] && (string)$other['document_id'] !== (string)$row['document_id'];
        if ($sameSegment && $other['target_id']) { $out[] = $item + ['class' => 'exact', 'reason' => 'same_file_drafted', 'message' => 'The same file was already read and drafted: ' . $label . '.']; continue; }
        $sameVendor = $vendorId && (string)$other['vendor_id'] === $vendorId;
        $sameNumber = $norm !== '' && (string)$other['invoice_number_norm'] === $norm;
        $sameTotal = $total !== null && $other['total_cents'] !== null && (int)$other['total_cents'] === $total && (string)$other['currency'] === $currency;
        $similar = $simhash !== '' && (string)$other['content_simhash'] !== '' && r170_simhash_distance($simhash, (string)$other['content_simhash']) <= 3;
        if ($sameVendor && $sameNumber && $sameTotal) $out[] = $item + ['class' => $other['target_id'] ? 'exact' : 'probable', 'reason' => 'job_vendor_number_total', 'message' => 'The same invoice is also in Invoice Intelligence: ' . $label . '.'];
        elseif ($sameVendor && $sameNumber) $out[] = $item + ['class' => 'probable', 'reason' => 'job_vendor_number', 'message' => 'Same supplier and invoice number as ' . $label . ' in Invoice Intelligence.'];
        elseif ($similar && ($sameTotal || $sameNumber)) $out[] = $item + ['class' => 'probable', 'reason' => 'content_similar', 'message' => 'Almost the same content as ' . $label . ' (a re-scan or copy of the same invoice?).'];
        elseif ($sameVendor && $sameTotal && $near((string)$other['invoice_date'], $date)) $out[] = $item + ['class' => 'review', 'reason' => 'job_vendor_total_date', 'message' => 'Same supplier and total within 7 days as ' . $label . '.'];
    }
    return array_slice($out, 0, 10);
}

/** Re-run the analysis and store it with the derived state (inside the caller's transaction when $inTransaction). */
function r170_reanalyse(array $user, array $company, string $jobId, bool $inTransaction = false): array
{
    $row = r170_job_row($company, $jobId, $inTransaction);
    if (!empty($row['target_id']) || in_array((string)$row['state'], ['cancelled', 'queued', 'uploaded'], true)) return $row;
    if ((string)$row['state'] === 'failed' && $row['fields_json'] === null) return $row;
    $analysis = r170_analyse($user, $company, $row);
    $fields = r170_decode($row['fields_json'] ?? null);
    $lines = $analysis['lines'];
    unset($analysis['lines']);
    $state = match ($analysis['reviewClass']) { 'ready' => 'ready', 'blocked' => 'blocked', default => 'review_required' };
    $vendorId = $analysis['vendorId'];
    $chosenBy = $row['vendor_chosen_by'] === 'user' && $vendorId === $row['vendor_id'] ? 'user' : ($vendorId ? 'match' : null);
    $number = (string)($fields['invoiceNumber']['value'] ?? '');
    db()->prepare('UPDATE invoice_intel_jobs SET analysis_json=?,state=?,review_class=?,vendor_id=?,vendor_chosen_by=?,invoice_number=?,invoice_number_norm=?,invoice_date=?,total_cents=?,currency=?' . ($lines !== null ? ',lines_json=?' : '') . ' WHERE id=? AND company_id=?')
        ->execute(array_merge([r170_json($analysis), $state, $analysis['reviewClass'], $vendorId, $chosenBy, $number !== '' ? $number : null, $number !== '' ? r170_number_norm($number) : null,
            !empty($fields['invoiceDate']['value']) ? $fields['invoiceDate']['value'] : null, isset($fields['totalCents']) ? (int)$fields['totalCents']['value'] : null,
            (string)($fields['currency']['value'] ?? $company['currency'])], $lines !== null ? [r170_json($lines)] : [], [$jobId, (string)$company['id']]));
    return r170_job_row($company, $jobId, $inTransaction);
}

/* ---------- review: corrections, recalculation, overrides, retry, cancel ---------- */

function r170_open_for_review(array $row): void
{
    if (!empty($row['target_id'])) fail('A draft vendor invoice was already created from this invoice. Edit the draft in the register.', 409, 'invoice_intel_already_drafted');
    if (!in_array((string)$row['state'], ['ready', 'review_required', 'blocked'], true)) fail('This invoice is not open for review.', 409, 'invoice_intel_state_invalid');
}

function r170_save(array $user, array $company, array $input): array
{
    r170_require_schema();
    require_company_permission($company, 'bills.write');
    $companyId = (string)$company['id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = r170_job_row($company, clean_text($input['jobId'] ?? '', 'Invoice', 64), true);
        r170_open_for_review($row);
        $fields = r170_decode($row['fields_json'] ?? null);
        $lines = r170_decode($row['lines_json'] ?? null);
        $reason = r170_text($input['reason'] ?? '', 300) ?: null;
        $record = $pdo->prepare('INSERT INTO invoice_intel_corrections (id,company_id,job_id,field,line_index,before_json,after_json,reason,user_id) VALUES (?,?,?,?,?,?,?,?,?)');
        $changed = [];
        foreach (is_array($input['fields'] ?? null) ? $input['fields'] : [] as $name => $value) {
            $name = (string)$name;
            if (!array_key_exists($name, R170_TEXT_FIELDS) && !in_array($name, array_merge(R170_DATE_FIELDS, R170_MONEY_FIELDS, ['currency', 'taxInclusive']), true)) fail('Unknown invoice field: ' . mb_substr($name, 0, 40), 422, 'invoice_intel_field_invalid');
            $before = $fields[$name]['value'] ?? null;
            if ($value === null || $value === '') {
                if ($before === null) continue;
                unset($fields[$name]);
                $record->execute([new_id('iicorr'), $companyId, (string)$row['id'], $name, null, r170_json($before), r170_json(null), $reason, (string)$user['id']]);
                $changed[] = $name;
                continue;
            }
            $clean = r170_clean_field(['value' => $value, 'raw' => is_scalar($value) ? (string)$value : '', 'source' => 'user', 'label' => true], $name);
            if ($clean === null) fail('That value is not valid for ' . $name . '.', 422, 'invoice_intel_field_invalid');
            if ($before === $clean['value'] && ($fields[$name]['source'] ?? '') === 'user') continue;
            if ($before !== $clean['value']) $record->execute([new_id('iicorr'), $companyId, (string)$row['id'], $name, null, r170_json($before), r170_json($clean['value']), $reason, (string)$user['id']]);
            $clean['page'] = $fields[$name]['page'] ?? null;
            if ($clean['page'] === null) unset($clean['page']);
            $fields[$name] = $clean;
            $changed[] = $name;
        }
        if (array_key_exists('docClass', $input)) {
            $class = (string)$input['docClass'];
            if (!in_array($class, ['invoice', 'credit_note', 'receipt'], true)) fail('Choose invoice, credit note or receipt.', 422, 'invoice_intel_class_invalid');
            if ($class !== (string)$row['doc_class']) { $record->execute([new_id('iicorr'), $companyId, (string)$row['id'], 'docClass', null, r170_json($row['doc_class']), r170_json($class), $reason, (string)$user['id']]); $pdo->prepare('UPDATE invoice_intel_jobs SET doc_class=? WHERE id=? AND company_id=?')->execute([$class, (string)$row['id'], $companyId]); $changed[] = 'docClass'; }
        }
        if (array_key_exists('lines', $input)) {
            if (!is_array($input['lines']) || count($input['lines']) > R170_MAX_LINES) fail('Send up to ' . R170_MAX_LINES . ' lines.', 422, 'invoice_intel_lines_invalid');
            // The server decides each line's source: a line that differs from the stored one is the reviewer's.
            $meta = ['accountSource', 'accountReason', 'taxSource', 'taxReason', 'source', 'ocrConf', 'page'];
            $strip = static fn(?array $l): ?array => $l === null ? null : array_diff_key($l, array_flip($meta));
            $new = [];
            foreach (array_values($input['lines']) as $line) {
                if (!is_array($line)) continue;
                $clean = r170_clean_line(array_diff_key($line, array_flip($meta)));
                if ($clean !== null) { unset($clean['source']); $new[] = $clean; }
            }
            $count = max(count($lines), count($new));
            for ($i = 0; $i < $count; $i++) {
                $a = $strip($lines[$i] ?? null);
                $b = $strip($new[$i] ?? null);
                if ($b !== null && $a == $b) {
                    foreach ($meta as $k) if (isset($lines[$i][$k])) $new[$i][$k] = $lines[$i][$k];
                    continue;
                }
                if ($b !== null) {
                    $new[$i]['source'] = 'user';
                    if (isset($lines[$i]['page'])) $new[$i]['page'] = $lines[$i]['page'];
                    // A reviewer's account or tax code replaces the suggestion; an unchanged one keeps its reason.
                    foreach (['account' => 'accountId', 'tax' => 'taxCodeId'] as $prefix => $key) if (isset($lines[$i][$prefix . 'Source']) && ($lines[$i][$key] ?? null) === ($new[$i][$key] ?? null)) { $new[$i][$prefix . 'Source'] = $lines[$i][$prefix . 'Source']; $new[$i][$prefix . 'Reason'] = $lines[$i][$prefix . 'Reason'] ?? ''; }
                }
                $record->execute([new_id('iicorr'), $companyId, (string)$row['id'], 'line', $i, r170_json($a), r170_json($b), $reason, (string)$user['id']]);
                $changed[] = 'line' . ($i + 1);
            }
            $lines = $new;
        }
        $vendorChange = array_key_exists('vendorId', $input);
        if ($vendorChange) {
            $vendorId = trim((string)$input['vendorId']);
            if ($vendorId !== '') {
                $q = $pdo->prepare("SELECT id FROM vendors WHERE id=? AND company_id=? AND active=1 AND status='active'");
                $q->execute([$vendorId, $companyId]);
                if (!$q->fetchColumn()) fail('Choose an active supplier from this company.', 422, 'invoice_intel_vendor_invalid');
            }
            if ($vendorId !== (string)$row['vendor_id'] || $row['vendor_chosen_by'] !== 'user') {
                $record->execute([new_id('iicorr'), $companyId, (string)$row['id'], 'vendorId', null, r170_json($row['vendor_id']), r170_json($vendorId ?: null), $reason, (string)$user['id']]);
                $pdo->prepare('UPDATE invoice_intel_jobs SET vendor_id=?,vendor_chosen_by=? WHERE id=? AND company_id=?')->execute([$vendorId ?: null, $vendorId ? 'user' : null, (string)$row['id'], $companyId]);
                $changed[] = 'vendor';
            }
        }
        $pdo->prepare('UPDATE invoice_intel_jobs SET fields_json=?,lines_json=?,accepted_computed_total=0,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),updated_by=? WHERE id=? AND company_id=?')
            ->execute([r170_json($fields), r170_json($lines), (string)$user['id'], (string)$user['id'], (string)$row['id'], $companyId]);
        r170_reanalyse($user, $company, (string)$row['id'], true);
        if ($changed) audit_event($user, $companyId, 'invoice_intel.corrected', 'invoice_intel_job', (string)$row['id'], ['fields' => array_values(array_unique($changed)), 'accountingWrites' => 0]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['job' => r170_detail($company, r170_job_row($company, (string)$row['id'])), 'changed' => array_values(array_unique($changed))];
}

function r170_recalculate(array $user, array $company, array $input): array
{
    r170_require_schema();
    require_company_permission($company, 'bills.write');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = r170_job_row($company, clean_text($input['jobId'] ?? '', 'Invoice', 64), true);
        r170_open_for_review($row);
        r170_reanalyse($user, $company, (string)$row['id'], true);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['job' => r170_detail($company, r170_job_row($company, (string)$row['id']))];
}

function r170_override(array $user, array $company, array $input): array
{
    r170_require_schema();
    require_company_permission($company, 'bills.reverse');
    $reason = r170_text($input['reason'] ?? '', 500);
    if (mb_strlen($reason) < 10) fail('Explain in at least 10 characters why this is not a duplicate.', 422, 'invoice_intel_override_reason');
    $companyId = (string)$company['id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = r170_job_row($company, clean_text($input['jobId'] ?? '', 'Invoice', 64), true);
        r170_open_for_review($row);
        $analysis = r170_decode($row['analysis_json'] ?? null);
        $key = (string)($input['duplicate'] ?? '');
        $match = null;
        foreach ($analysis['duplicates'] ?? [] as $d) if ($d['kind'] . ':' . $d['id'] === $key) $match = $d;
        if (!$match) fail('That possible duplicate is no longer listed. Refresh the invoice.', 409, 'invoice_intel_override_stale');
        if ($match['reason'] === 'same_file_drafted') fail('This exact file was already drafted. It cannot be drafted again.', 409, 'invoice_intel_override_refused');
        $pdo->prepare('INSERT INTO invoice_intel_overrides (id,company_id,job_id,duplicate_class,duplicate_of,reason,user_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([new_id('iiover'), $companyId, (string)$row['id'], (string)$match['class'], $key, $reason, (string)$user['id']]);
        r170_reanalyse($user, $company, (string)$row['id'], true);
        audit_event($user, $companyId, 'invoice_intel.duplicate_overridden', 'invoice_intel_job', (string)$row['id'], ['duplicateClass' => (string)$match['class'], 'duplicateOf' => $key, 'reason' => $reason]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['job' => r170_detail($company, r170_job_row($company, (string)$row['id']))];
}

function r170_retry(array $user, array $company, array $input): array
{
    r170_require_schema();
    require_company_permission($company, 'bills.write');
    $companyId = (string)$company['id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = r170_job_row($company, clean_text($input['jobId'] ?? '', 'Invoice', 64), true);
        $documentId = (string)$row['document_id'];
        $drafted = $pdo->prepare('SELECT COUNT(*) FROM invoice_intel_jobs WHERE company_id=? AND document_id=? AND target_id IS NOT NULL');
        $drafted->execute([$companyId, $documentId]);
        if ((int)$drafted->fetchColumn() > 0) fail('Part of this file is already a draft vendor invoice, so the file cannot be read again. Delete that draft first if it is wrong.', 409, 'invoice_intel_retry_refused');
        if ((string)$row['state'] === 'processing' && $row['lease_expires_at'] !== null && strtotime((string)$row['lease_expires_at'] . ' UTC') > time()) fail('This file is being read right now.', 409, 'invoice_intel_busy');
        if (in_array((string)$row['failure_code'], ['not_pdf', 'pdf_truncated', 'pdf_too_many_pages'], true)) fail('This file cannot be read: fix the file and upload it again.', 409, 'invoice_intel_retry_refused');
        $pdo->prepare('DELETE FROM invoice_intel_jobs WHERE company_id=? AND document_id=? AND segment_index>0')->execute([$companyId, $documentId]);
        $pdo->prepare("UPDATE invoice_intel_jobs SET state='queued',review_class=NULL,attempts=0,failure_code=NULL,lease_token=NULL,leased_by=NULL,lease_expires_at=NULL,fields_json=NULL,lines_json=NULL,evidence_enc=NULL,analysis_json=NULL,vendor_id=NULL,vendor_chosen_by=NULL,processed_at=NULL,accepted_computed_total=0,updated_by=? WHERE company_id=? AND document_id=? AND segment_index=0")
            ->execute([(string)$user['id'], $companyId, $documentId]);
        audit_event($user, $companyId, 'invoice_intel.retry', 'native_agent_document', $documentId, ['previousState' => (string)$row['state'], 'previousFailure' => $row['failure_code'], 'accountingWrites' => 0]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['jobs' => r170_document_jobs($company, $documentId)];
}

function r170_cancel(array $user, array $company, array $input): array
{
    r170_require_schema();
    require_company_permission($company, 'bills.write');
    $companyId = (string)$company['id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = r170_job_row($company, clean_text($input['jobId'] ?? '', 'Invoice', 64), true);
        if (!empty($row['target_id'])) fail('A draft vendor invoice was already created. Delete or void it in the register instead.', 409, 'invoice_intel_already_drafted');
        if ((string)$row['state'] === 'cancelled') { $pdo->commit(); return ['job' => r170_public_row($company, $row)]; }
        $pdo->prepare("UPDATE invoice_intel_jobs SET state='cancelled',lease_token=NULL,leased_by=NULL,lease_expires_at=NULL,updated_by=? WHERE id=? AND company_id=?")->execute([(string)$user['id'], (string)$row['id'], $companyId]);
        audit_event($user, $companyId, 'invoice_intel.cancelled', 'invoice_intel_job', (string)$row['id'], ['previousState' => (string)$row['state'], 'accountingWrites' => 0]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['job' => r170_public_row($company, r170_job_row($company, (string)$row['id']))];
}

/* ---------- draft creation through the existing services ---------- */

function r170_learn(array $user, array $company, array $row, array $lines, string $vendorId): void
{
    $companyId = (string)$company['id'];
    $upsert = db()->prepare('INSERT INTO invoice_intel_vendor_rules (id,company_id,vendor_id,keyword_key,keywords,account_id,tax_code_id,uses,last_job_id,updated_by) VALUES (?,?,?,?,?,?,?,1,?,?)
        ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),tax_code_id=VALUES(tax_code_id),uses=IF(account_id=VALUES(account_id),uses+1,1),last_job_id=VALUES(last_job_id),updated_by=VALUES(updated_by)');
    foreach ($lines as $line) {
        $words = array_slice(r170_words((string)($line['description'] ?? '')), 0, 8);
        if (!$words || empty($line['accountId'])) continue;
        sort($words);
        $keywords = mb_substr(implode(' ', $words), 0, 200);
        $upsert->execute([new_id('iirule'), $companyId, $vendorId, hash('sha256', $keywords), $keywords, (string)$line['accountId'], !empty($line['taxCodeId']) ? (string)$line['taxCodeId'] : null, (string)$row['id'], (string)$user['id']]);
    }
}

function r170_create_draft(array $user, array $company, array $input): array
{
    r170_require_schema();
    require_company_permission($company, 'bills.write');
    $companyId = (string)$company['id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = r170_job_row($company, clean_text($input['jobId'] ?? '', 'Invoice', 64), true);
        if (!empty($row['target_id'])) {
            // Idempotent: the same request (or a double click) returns the draft already created.
            $pdo->commit();
            $row = r170_sync_target($company, r170_job_row($company, (string)$row['id']));
            if (!empty($row['target_id'])) return ['reused' => true, 'targetType' => $row['target_type'], 'targetId' => $row['target_id'], 'job' => r170_public_row($company, $row)];
            fail('The earlier draft was deleted. Review the invoice and create the draft again.', 409, 'invoice_intel_draft_deleted');
        }
        r170_open_for_review($row);
        $row = r170_reanalyse($user, $company, (string)$row['id'], true);
        $analysis = r170_decode($row['analysis_json'] ?? null);
        $fields = r170_decode($row['fields_json'] ?? null);
        $lines = r170_decode($row['lines_json'] ?? null);
        $blocking = array_values(array_filter($analysis['validations'] ?? [], static fn($v) => $v['severity'] === 'block'));
        if ($blocking) fail('This invoice cannot be drafted yet: ' . $blocking[0]['message'], 409, 'invoice_intel_blocked');
        if (empty($row['vendor_id'])) fail('Choose the supplier first. If it is not in the list, add it in Payables › Vendors.', 409, 'invoice_intel_vendor_required');
        $mustFix = ['number_missing', 'date_missing', 'date_ambiguous', 'currency_unknown', 'lines_missing', 'line_amount_missing', 'line_negative', 'account_missing', 'tax_code_missing', 'credit_reference_missing', 'tegh_check_failed', 'date_before_books', 'total_missing'];
        foreach ($analysis['validations'] ?? [] as $item) if (in_array($item['code'], $mustFix, true)) fail($item['message'], 409, 'invoice_intel_incomplete');
        $differs = array_values(array_filter($analysis['validations'] ?? [], static fn($v) => $v['code'] === 'tegh_total_differs'));
        $accept = ($input['acceptComputedTotal'] ?? false) === true;
        if ($differs && !$accept) fail($differs[0]['message'] . ' Confirm Tegh\'s total to continue, or correct the lines.', 409, 'invoice_intel_total_differs');
        $reviewFlags = array_values(array_filter($analysis['validations'] ?? [], static fn($v) => $v['severity'] === 'review' && $v['code'] !== 'tegh_total_differs'));
        if (($reviewFlags || ($analysis['reviewClass'] ?? '') !== 'ready') && ($input['confirmReviewed'] ?? false) !== true) fail('Some fields still need review. Check them against the document, then confirm.', 409, 'invoice_intel_review_required');
        $vendorId = (string)$row['vendor_id'];
        if ((string)$row['doc_class'] === 'credit_note') {
            require_company_permission($company, 'ap.credit_note.write');
            $noteLines = array_map(static function (array $l) use ($company) {
                $entry = ['description' => (string)($l['description'] ?? '') ?: 'Credit', 'quantity' => '1', 'unitPriceCents' => abs((int)($l['amountCents'] ?? 0)), 'accountId' => (string)($l['accountId'] ?? '')];
                if (function_exists('tax_setup_mode') && tax_setup_mode($company) === 'codes') $entry['taxCodeId'] = (string)($l['taxCodeId'] ?? '');
                else { $entry['applyGstHst'] = ($l['applyGstHst'] ?? false) === true; $entry['applyPst'] = ($l['applyPst'] ?? false) === true; }
                return $entry;
            }, $lines);
            $noteInput = ['kind' => 'vendor_credit', 'partyId' => $vendorId, 'referenceNumber' => (string)($fields['originalInvoiceNumber']['value'] ?? ''), 'date' => (string)$fields['invoiceDate']['value'],
                'currency' => (string)($fields['currency']['value'] ?? $company['currency']), 'lines' => $noteLines, 'memo' => mb_substr('Invoice Intelligence · credit note ' . ($fields['invoiceNumber']['value'] ?? '') . ' · ' . $row['original_name'], 0, 500)];
            $operationKey = 'intel-' . (string)$row['id'];
            $target = r151_note_create_unlinked($user, $company, $noteInput, $operationKey, hash('sha256', r170_json($noteInput)));
            $targetType = 'accounting_note';
        } else {
            $billInput = r170_bill_input($company, $row, $fields, $lines);
            $billInput['importReference'] = 'intel-' . (string)$row['id'];
            try {
                $target = tegh_service_boundary(static fn() => create_bill_record($user, $company, $billInput, 'invoice_intelligence'));
            } catch (TeghServiceFailure $e) {
                $message = $e->errorCode === 'duplicate_bill_number' ? 'Vendor invoice number ' . $billInput['number'] . ' is already used in this company (Tegh needs vendor invoice numbers to be unique). If this is a different invoice, add a supplier prefix to the number and save.' : $e->getMessage();
                fail($message, $e->httpStatus, $e->errorCode);
            }
            if (($target['status'] ?? '') !== 'draft') throw new RuntimeException('Invoice Intelligence must only create drafts.');
            $targetType = 'bill';
        }
        $pdo->prepare("UPDATE invoice_intel_jobs SET state='draft_created',target_type=?,target_id=?,target_status='draft',accepted_computed_total=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),updated_by=? WHERE id=? AND company_id=? AND target_id IS NULL")
            ->execute([$targetType, (string)$target['id'], $accept ? 1 : 0, (string)$user['id'], (string)$user['id'], (string)$row['id'], $companyId]);
        // The original file stays attached to the draft: link the intake record when it is not linked yet.
        if ($targetType === 'bill') $pdo->prepare("UPDATE native_agent_documents SET state='linked',linked_entity_type='bill',linked_entity_id=?,linked_by=?,linked_at=UTC_TIMESTAMP() WHERE id=? AND company_id=? AND state NOT IN ('linked')")
            ->execute([(string)$target['id'], (string)$user['id'], (string)$row['document_id'], $companyId]);
        r170_learn($user, $company, $row, $lines, $vendorId);
        if (function_exists('tegh_schema41_status') && tegh_schema41_status()['ready']) {
            $candidate = ['partyId' => $vendorId, 'partyName' => (string)($fields['supplierName']['value'] ?? ''), 'partyEmail' => (string)($fields['supplierEmail']['value'] ?? ''), 'businessIdentifier' => (string)($fields['supplierTaxNumbers']['value'] ?? ''), 'address' => (string)($fields['supplierAddress']['value'] ?? '')];
            $doc = native_document_row($company, (string)$row['document_id']);
            try { native_vendor_learning_accept($user, $company, $doc, $candidate, hash('sha256', 'intel|' . $row['id']), 'intel-' . $row['id']); } catch (Throwable $e) { /* learning is optional; the draft stands */ }
        }
        audit_event($user, $companyId, 'invoice_intel.draft_created', 'invoice_intel_job', (string)$row['id'], ['targetType' => $targetType, 'targetId' => (string)$target['id'], 'number' => (string)($target['number'] ?? ''), 'acceptedComputedTotal' => $accept, 'reviewFlagsConfirmed' => count($reviewFlags), 'journalEntries' => 0]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['reused' => false, 'targetType' => $targetType, 'targetId' => (string)$target['id'], 'number' => (string)($target['number'] ?? ''), 'job' => r170_public_row($company, r170_job_row($company, (string)$row['id']))];
}

/* ---------- lists, source lookup, Tegh Assist ---------- */

function r170_list(array $company, array $filters): array
{
    r170_require_schema();
    require_company_permission($company, 'bills.view');
    $companyId = (string)$company['id'];
    r170_expire_leases($companyId);
    $where = ['j.company_id=?'];
    $params = [$companyId];
    $state = (string)($filters['state'] ?? '');
    if ($state === 'open') $where[] = "j.state IN ('queued','processing','review_required','ready','blocked','failed')";
    elseif ($state === 'done') $where[] = "j.state IN ('draft_created','approved','posted')";
    elseif (in_array($state, R170_STATES, true)) { $where[] = 'j.state=?'; $params[] = $state; }
    $review = (string)($filters['review'] ?? '');
    if (in_array($review, ['ready', 'review', 'blocked'], true)) { $where[] = 'j.review_class=?'; $params[] = $review; }
    $vendor = trim((string)($filters['vendorId'] ?? ''));
    if ($vendor !== '') { $where[] = 'j.vendor_id=?'; $params[] = $vendor; }
    $batch = trim((string)($filters['batchId'] ?? ''));
    if ($batch !== '') { $where[] = 'j.batch_id=?'; $params[] = $batch; }
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') { $where[] = '(j.invoice_number LIKE ? OR d.original_name LIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
    $stmt = db()->prepare('SELECT j.*,d.original_name,d.mime_type,d.size_bytes,d.sha256,d.storage_path,d.state document_state FROM invoice_intel_jobs j JOIN native_agent_documents d ON d.id=j.document_id AND d.company_id=j.company_id WHERE ' . implode(' AND ', $where) . ' ORDER BY j.created_at DESC,j.segment_index LIMIT 500');
    $stmt->execute($params);
    $rows = array_map(static fn($r) => r170_public_row($company, r170_sync_target($company, $r)), $stmt->fetchAll());
    if (($filters['duplicates'] ?? '') === '1') $rows = array_values(array_filter($rows, static fn($r) => $r['duplicateClass'] !== null));
    $counts = db()->prepare('SELECT state,COUNT(*) n FROM invoice_intel_jobs WHERE company_id=? GROUP BY state');
    $counts->execute([$companyId]);
    return ['jobs' => $rows, 'counts' => array_map('intval', array_column($counts->fetchAll(), 'n', 'state'))];
}

function r170_source_for_bill(array $company, array $query): array
{
    r170_require_schema();
    require_company_permission($company, 'bills.view');
    $id = clean_text($query['targetId'] ?? '', 'Vendor invoice', 64);
    $q = db()->prepare('SELECT j.id,j.document_id,j.page_from,j.page_to,d.original_name FROM invoice_intel_jobs j JOIN native_agent_documents d ON d.id=j.document_id AND d.company_id=j.company_id WHERE j.company_id=? AND j.target_id=? LIMIT 1');
    $q->execute([(string)$company['id'], $id]);
    $row = $q->fetch();
    if (!$row) return ['found' => false];
    return ['found' => true, 'jobId' => (string)$row['id'], 'fileName' => (string)$row['original_name'], 'pageFrom' => $row['page_from'] !== null ? (int)$row['page_from'] : null, 'pageTo' => $row['page_to'] !== null ? (int)$row['page_to'] : null,
        'sourceUrl' => '/api/index.php?route=native-ap-ar/document/file&documentId=' . rawurlencode((string)$row['document_id'])];
}

/**
 * Tegh Assist questions about vendor invoices. Answers come from the stored job records and the books; document text
 * is never treated as an instruction, and nothing here changes records (preparing drafts returns a list to confirm).
 */
function r170_ask(array $user, array $company, array $input): array
{
    r170_require_schema();
    require_company_permission($company, 'bills.view');
    $question = r170_text($input['question'] ?? '', 300);
    $text = mb_strtolower($question);
    $jobId = trim((string)($input['jobId'] ?? ''));
    $companyId = (string)$company['id'];
    $rows = static fn(array $jobs): array => array_map(static fn($j) => ['jobId' => $j['id'], 'label' => trim(($j['vendorName'] ?: $j['supplierName'] ?: $j['fileName']) . ($j['invoiceNumber'] ? ' · ' . $j['invoiceNumber'] : '')), 'detail' => ($j['totalCents'] !== null ? r170_money((int)$j['totalCents'], (string)$j['currency']) . ' · ' : '') . str_replace('_', ' ', $j['state'])], $jobs);
    $open = static fn(): array => r170_list($company, ['state' => 'open'])['jobs'];
    $answer = static fn(string $intent, string $say, array $list = [], array $extra = []): array => ['answered' => true, 'intent' => $intent, 'text' => $say, 'rows' => $list, 'navigate' => ['route' => 'invoice-intel'] + ($extra['filter'] ?? [])] + $extra;

    if (preg_match('/\bwhy\b.*\b(?:select|choose|chose|pick|picked|suggest|use|code|coded)(?:ed|d)?\b\s+(.+?)\??$/u', $text, $m)) {
        $target = trim(preg_replace('/^(?:the\s+)?(?:account\s+)?/', '', $m[1]) ?? '', " ?.\"'");
        $stmt = db()->prepare('SELECT id FROM invoice_intel_jobs WHERE company_id=?' . ($jobId !== '' ? ' AND id=?' : '') . ' AND lines_json IS NOT NULL ORDER BY updated_at DESC LIMIT 50');
        $stmt->execute($jobId !== '' ? [$companyId, $jobId] : [$companyId]);
        $accounts = db()->prepare('SELECT id,name,code FROM accounts WHERE company_id=?');
        $accounts->execute([$companyId]);
        $names = [];
        foreach ($accounts->fetchAll() as $a) $names[(string)$a['id']] = (string)$a['name'];
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $job = r170_job_row($company, (string)$id);
            foreach (r170_decode($job['lines_json']) as $i => $line) {
                $name = $names[(string)($line['accountId'] ?? '')] ?? '';
                if ($name === '' || ($target !== '' && !str_contains(mb_strtolower($name), $target) && !str_contains($target, mb_strtolower($name)))) continue;
                $found[] = ['jobId' => (string)$id, 'label' => 'Line ' . ($i + 1) . ': ' . mb_substr((string)($line['description'] ?? ''), 0, 80), 'detail' => $name . ' — ' . ((string)($line['accountReason'] ?? '') ?: 'Chosen by a reviewer')];
                if (count($found) >= 8) break 2;
            }
        }
        if (!$found) return $answer('why_account', 'No invoice line in Invoice Intelligence is coded to "' . $target . '".');
        return $answer('why_account', 'Here is the evidence behind each line coded to ' . $target . '. Tegh suggests an account from your own earlier coding for the same supplier, then the supplier\'s default account; it never guesses without one of these.', $found);
    }
    if (preg_match('/\b(?:missing|without|no|need(?:s|ing)?)\b.*\b(?:gl|accounts?|coding)\b/u', $text)) {
        $list = array_values(array_filter($open(), static function ($j) use ($company) { $d = r170_detail($company, r170_job_row($company, $j['id'])); return (bool)array_filter($d['analysis']['validations'] ?? [], static fn($v) => $v['code'] === 'account_missing'); }));
        return $answer('missing_gl', $list ? count($list) . ' invoice' . (count($list) === 1 ? ' has' : 's have') . ' lines without a GL account.' : 'Every invoice line in review has a GL account.', $rows($list), ['filter' => ['flag' => 'account_missing']]);
    }
    if (preg_match('/\bduplicat/u', $text)) {
        $list = r170_list($company, ['duplicates' => '1'])['jobs'];
        $list = array_values(array_filter($list, static fn($j) => !in_array($j['state'], ['cancelled'], true)));
        return $answer('duplicates', $list ? count($list) . ' invoice' . (count($list) === 1 ? ' looks' : 's look') . ' like possible duplicates.' : 'No possible duplicates were found.', $rows($list), ['filter' => ['duplicates' => '1']]);
    }
    if (preg_match('/\btax(?:es)?\b.*\b(?:mismatch|wrong|differ|match)|\bmismatch\w*\b.*\btax/u', $text)) {
        $list = array_values(array_filter($open(), static function ($j) use ($company) { $d = r170_detail($company, r170_job_row($company, $j['id'])); return (bool)array_filter($d['analysis']['validations'] ?? [], static fn($v) => in_array($v['code'], ['tax_mismatch', 'tax_components', 'tegh_total_differs', 'tax_code_missing'], true)); }));
        return $answer('tax_mismatch', $list ? count($list) . ' invoice' . (count($list) === 1 ? ' has' : 's have') . ' tax that does not match Tegh\'s calculation or the tax lines.' : 'No invoice has a tax mismatch.', $rows($list), ['filter' => ['flag' => 'tax']]);
    }
    if (preg_match('/\bapprov/u', $text)) {
        $q = db()->prepare("SELECT b.id,b.number,b.total_cents,b.currency,v.name FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id WHERE b.company_id=? AND b.status='submitted_for_approval' ORDER BY b.bill_date LIMIT 50");
        $q->execute([$companyId]);
        $bills = array_map(static fn($b) => ['billId' => (string)$b['id'], 'label' => (string)$b['name'] . ' · ' . $b['number'], 'detail' => r170_money((int)$b['total_cents']) . ' · waiting for approval'], $q->fetchAll());
        $review = array_values(array_filter($open(), static fn($j) => in_array($j['state'], ['review_required', 'ready', 'blocked'], true)));
        $say = sprintf('%d vendor invoice%s %s waiting for approval above the materiality threshold, and %d invoice%s in Invoice Intelligence need%s review before drafting.', count($bills), count($bills) === 1 ? '' : 's', count($bills) === 1 ? 'is' : 'are', count($review), count($review) === 1 ? '' : 's', count($review) === 1 ? 's' : '');
        return $answer('approvals', $say, array_merge($bills, $rows($review)));
    }
    if (preg_match('/\b(?:history|invoices?)\b\s+(?:for|from|of)\s+(.+?)\??$|review\s+(.+?)(?:\'s|s\')?\s+(?:invoice\s+)?history/u', $text, $m)) {
        $name = trim(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''), " ?.'\"");
        $q = db()->prepare("SELECT id,name FROM vendors WHERE company_id=? AND active=1 AND LOWER(name) LIKE ? ORDER BY name LIMIT 2");
        $q->execute([$companyId, '%' . $name . '%']);
        $vendors = $q->fetchAll();
        if (count($vendors) !== 1) return $answer('vendor_history', count($vendors) ? 'More than one supplier matches "' . $name . '". Use more of the name.' : 'No supplier matches "' . $name . '".');
        $vendor = $vendors[0];
        $b = db()->prepare("SELECT id,number,bill_date,total_cents,currency,status FROM bills WHERE company_id=? AND vendor_id=? AND status<>'void' ORDER BY bill_date DESC LIMIT 12");
        $b->execute([$companyId, (string)$vendor['id']]);
        $list = array_map(static fn($r) => ['billId' => (string)$r['id'], 'label' => (string)$r['number'] . ' · ' . $r['bill_date'], 'detail' => r170_money((int)$r['total_cents'], (string)$r['currency']) . ' · ' . str_replace('_', ' ', (string)$r['status'])], $b->fetchAll());
        return $answer('vendor_history', 'Latest vendor invoices from ' . $vendor['name'] . ':', $list, ['filter' => ['vendorId' => (string)$vendor['id']]]);
    }
    if (preg_match('/\b(?:prepare|create|make|save)\b.*\bdrafts?\b|\bdrafts?\b.*\b(?:prepare|create)/u', $text)) {
        $ready = array_values(array_filter($open(), static fn($j) => $j['state'] === 'ready'));
        return $answer('prepare_drafts', $ready ? count($ready) . ' invoice' . (count($ready) === 1 ? ' is' : 's are') . ' ready to become draft vendor invoices. Drafts do not post anything; you issue them from the register.' : 'No invoice is ready yet. Invoices that need review must be checked first.', $rows($ready), ['action' => $ready ? ['type' => 'draft-batch', 'jobIds' => array_column($ready, 'id'), 'label' => 'Create ' . count($ready) . ' draft' . (count($ready) === 1 ? '' : 's')] : null, 'filter' => ['review' => 'ready']]);
    }
    if (preg_match('/\b(?:import|upload|read|scan|process)\b.*\b(?:invoices?|bills?|pdfs?)\b/u', $text)) {
        return $answer('import', 'Open Invoice Intelligence and drop the PDF invoices on the upload area. Each supplier must already be in Payables › Vendors.', [], ['filter' => ['upload' => '1']]);
    }
    return ['answered' => false];
}

/* ---------- routing ---------- */

function handle_invoice_intel(string $action): never
{
    $user = require_user();
    $company = require_company($user);
    r170_require_schema();
    if (request_method() === 'GET') {
        if ($action === 'jobs') json_response(r170_list($company, $_GET));
        if ($action === 'job') { require_company_permission($company, 'bills.view'); json_response(['job' => r170_detail($company, r170_job_row($company, clean_text($_GET['jobId'] ?? '', 'Invoice', 64)))]); }
        if ($action === 'options') { require_company_permission($company, 'bills.view'); json_response(r170_options($company)); }
        if ($action === 'source') json_response(r170_source_for_bill($company, $_GET));
        fail('Invoice Intelligence route not found.', 404, 'route_not_found');
    }
    require_method('POST');
    require_csrf();
    if ($action === 'upload') json_response(r170_upload($user, $company), 201);
    $input = request_json();
    match ($action) {
        'claim' => json_response(r170_claim($user, $company, $input)),
        'heartbeat' => json_response(r170_heartbeat($user, $company, $input)),
        'submit' => json_response(r170_submit($user, $company, $input)),
        'fail' => json_response(r170_worker_fail($user, $company, $input)),
        'save' => json_response(r170_save($user, $company, $input)),
        'recalculate' => json_response(r170_recalculate($user, $company, $input)),
        'override' => json_response(r170_override($user, $company, $input)),
        'retry' => json_response(r170_retry($user, $company, $input)),
        'cancel' => json_response(r170_cancel($user, $company, $input)),
        'draft' => json_response(r170_create_draft($user, $company, $input)),
        'ask' => json_response(r170_ask($user, $company, $input)),
        default => fail('Invoice Intelligence route not found.', 404, 'route_not_found'),
    };
}
