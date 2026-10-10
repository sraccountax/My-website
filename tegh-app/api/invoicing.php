<?php
declare(strict_types=1);

function invoice_optional_text(mixed $value, int $max): ?string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim((string)$value));
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? '';
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? '';
    if ($text === '') return null;
    if (mb_strlen($text) > $max) fail('An invoice template value is too long.');
    return $text;
}

function map_invoice_template(array $row): array
{
    return [
        'id' => (string)$row['id'],
        'name' => (string)$row['name'],
        'isDefault' => (bool)$row['is_default'],
        'documentTitle' => (string)$row['document_title'],
        'accentColor' => (string)$row['accent_color'],
        'layoutStyle' => (string)($row['layout_style'] ?? 'classic'),
        'fontFamily' => (string)($row['font_family'] ?? 'Arial'),
        'businessName' => $row['business_name_override'] !== null ? (string)$row['business_name_override'] : null,
        'taxNumber' => $row['tax_number_override'] !== null ? (string)$row['tax_number_override'] : null,
        'logoData' => $row['logo_data'] !== null ? (string)$row['logo_data'] : null,
        'businessAddress' => $row['business_address'] !== null ? (string)$row['business_address'] : null,
        'businessEmail' => $row['business_email'] !== null ? (string)$row['business_email'] : null,
        'businessPhone' => $row['business_phone'] !== null ? (string)$row['business_phone'] : null,
        'paymentInstructions' => $row['payment_instructions'] !== null ? (string)$row['payment_instructions'] : null,
        'footer' => $row['footer'] !== null ? (string)$row['footer'] : null,
        'showTaxNumber' => (bool)$row['show_tax_number'],
        'showPaymentInstructions' => (bool)$row['show_payment_instructions'],
        'active' => (bool)$row['active'],
    ];
}

function invoice_templates_for_company(array $company): array
{
    ensure_default_invoice_templates();
    $stmt = db()->prepare('SELECT * FROM invoice_templates WHERE company_id = ? AND active = 1 ORDER BY is_default DESC, name');
    $stmt->execute([(string)$company['id']]);
    return array_map('map_invoice_template', $stmt->fetchAll());
}

function invoice_template_row(string $companyId, ?string $templateId = null): array
{
    if ($templateId !== null && $templateId !== '') {
        $stmt = db()->prepare('SELECT * FROM invoice_templates WHERE id = ? AND company_id = ? AND active = 1');
        $stmt->execute([$templateId, $companyId]);
    } else {
        $stmt = db()->prepare('SELECT * FROM invoice_templates WHERE company_id = ? AND active = 1 ORDER BY is_default DESC, created_at LIMIT 1');
        $stmt->execute([$companyId]);
    }
    $row = $stmt->fetch();
    if (!$row) fail('Choose an available invoice template.');
    return $row;
}

function invoice_template_snapshot(array $template, array $company): array
{
    return [
        'id' => (string)$template['id'],
        'name' => (string)$template['name'],
        'documentTitle' => (string)$template['document_title'],
        'accentColor' => (string)$template['accent_color'],
        'layoutStyle' => (string)($template['layout_style'] ?? 'classic'),
        'fontFamily' => (string)($template['font_family'] ?? 'Arial'),
        'businessName' => $template['business_name_override'] !== null && trim((string)$template['business_name_override']) !== '' ? (string)$template['business_name_override'] : (string)$company['name'],
        'legalName' => (string)$company['legal_name'],
        // R153: the company address (Company Details) unless the template has its own address.
        'businessAddress' => function_exists('r153_invoice_business_address') ? r153_invoice_business_address($template['business_address'] !== null ? (string)$template['business_address'] : null, $company)
            : ($template['business_address'] !== null ? (string)$template['business_address'] : ((string)$company['province'] . ', Canada')),
        'businessEmail' => $template['business_email'] !== null && trim((string)$template['business_email']) !== '' ? (string)$template['business_email'] : (trim((string)($company['contact_email'] ?? '')) !== '' ? (string)$company['contact_email'] : null),
        'businessPhone' => $template['business_phone'] !== null && trim((string)$template['business_phone']) !== '' ? (string)$template['business_phone'] : (trim((string)($company['phone'] ?? '')) !== '' ? (string)$company['phone'] : null),
        'taxNumber' => $template['tax_number_override'] !== null && trim((string)$template['tax_number_override']) !== '' ? (string)$template['tax_number_override'] : ($company['tax_number'] !== null ? (string)$company['tax_number'] : null),
        'logoData' => $template['logo_data'] !== null ? (string)$template['logo_data'] : null,
        'paymentInstructions' => $template['payment_instructions'] !== null ? (string)$template['payment_instructions'] : null,
        'footer' => $template['footer'] !== null ? (string)$template['footer'] : null,
        'showTaxNumber' => (bool)$template['show_tax_number'],
        'showPaymentInstructions' => (bool)$template['show_payment_instructions'],
    ];
}

function invoice_template_snapshot_from_mapped(array $template, array $company): array
{
    return [
        'id' => (string)$template['id'],
        'name' => (string)$template['name'],
        'documentTitle' => (string)$template['documentTitle'],
        'accentColor' => (string)$template['accentColor'],
        'layoutStyle' => (string)($template['layoutStyle'] ?? 'classic'),
        'fontFamily' => (string)($template['fontFamily'] ?? 'Arial'),
        'businessName' => !empty($template['businessName']) ? (string)$template['businessName'] : (string)$company['name'],
        'legalName' => (string)$company['legal_name'],
        'businessAddress' => function_exists('r153_invoice_business_address') ? r153_invoice_business_address(isset($template['businessAddress']) ? (string)$template['businessAddress'] : null, $company) : ($template['businessAddress'] ?? ((string)$company['province'] . ', Canada')),
        'businessEmail' => !empty($template['businessEmail']) ? (string)$template['businessEmail'] : (trim((string)($company['contact_email'] ?? '')) !== '' ? (string)$company['contact_email'] : null),
        'businessPhone' => !empty($template['businessPhone']) ? (string)$template['businessPhone'] : (trim((string)($company['phone'] ?? '')) !== '' ? (string)$company['phone'] : null),
        'taxNumber' => !empty($template['taxNumber']) ? (string)$template['taxNumber'] : ($company['tax_number'] !== null ? (string)$company['tax_number'] : null),
        'logoData' => $template['logoData'] ?? null,
        'paymentInstructions' => $template['paymentInstructions'] ?? null,
        'footer' => $template['footer'] ?? null,
        'showTaxNumber' => (bool)$template['showTaxNumber'],
        'showPaymentInstructions' => (bool)$template['showPaymentInstructions'],
    ];
}

function invoice_customer_snapshot(array $customer): array
{
    return [
        'name' => (string)$customer['name'],
        'email' => $customer['email'] !== null ? (string)$customer['email'] : null,
        'phone' => $customer['phone'] !== null ? (string)$customer['phone'] : null,
        'billingAddress' => $customer['billing_address'] !== null ? (string)$customer['billing_address'] : null,
        'province' => $customer['province'] !== null ? (string)$customer['province'] : null,
    ];
}

function decode_invoice_snapshot(mixed $json, array $fallback): array
{
    if (is_string($json) && $json !== '') {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) return $decoded;
        } catch (JsonException) {
            // A legacy or damaged snapshot is surfaced using safe current data.
        }
    }
    return $fallback;
}

function reserve_invoice_number(string $companyId): string
{
    return db_transaction_retry(function () use ($companyId): string {
        $stmt = db()->prepare("SELECT next_number FROM document_sequences
            WHERE company_id = ? AND document_type = 'invoice' FOR UPDATE");
        $stmt->execute([$companyId]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            // Defensive repair for an installation where the sequence row was
            // removed manually. Normal migrations and company creation already
            // provision this row.
            $maxStmt = db()->prepare("SELECT GREATEST(1001,
                COALESCE(MAX(CASE WHEN number LIKE 'INV-%' THEN CAST(SUBSTRING(number, 5) AS UNSIGNED) END), 1000) + 1)
                FROM invoices WHERE company_id = ?");
            $maxStmt->execute([$companyId]);
            $number = (int)$maxStmt->fetchColumn();
            db()->prepare("INSERT INTO document_sequences (company_id, document_type, next_number)
                VALUES (?, 'invoice', ?)")->execute([$companyId, $number + 1]);
        } else {
            $number = (int)$current;
            db()->prepare("UPDATE document_sequences SET next_number = ?
                WHERE company_id = ? AND document_type = 'invoice'")
                ->execute([$number + 1, $companyId]);
        }

        if ($number < 1) throw new RuntimeException('The invoice number sequence is unavailable.');
        return 'INV-' . $number;
    }, 6);
}

function handle_invoice_templates(): never
{
    require_method('POST', 'PUT');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = request_json();
    $updating = request_method() === 'PUT';
    $existing = null;
    if ($updating) {
        $templateId = clean_text($input['templateId'] ?? '', 'Invoice template', 64);
        $existing = invoice_template_row($companyId, $templateId);
    } else {
        $stmt = db()->prepare('SELECT COUNT(*) FROM invoice_templates WHERE company_id = ? AND active = 1');
        $stmt->execute([$companyId]);
        if ((int)$stmt->fetchColumn() >= 10) fail('A company can have up to 10 active invoice templates.');
        $templateId = new_id('invtemplate');
    }

    $name = clean_text($input['name'] ?? ($existing['name'] ?? ''), 'Template name', 100);
    $documentTitle = clean_text($input['documentTitle'] ?? ($existing['document_title'] ?? 'INVOICE'), 'Document title', 80);
    $accent = strtoupper(trim((string)($input['accentColor'] ?? ($existing['accent_color'] ?? '#C94F2D'))));
    if (!preg_match('/^#[0-9A-F]{6}$/', $accent)) fail('Accent colour must be a six-digit hexadecimal colour.');
    $layoutStyle = (string)($input['layoutStyle'] ?? ($existing['layout_style'] ?? 'classic'));
    if (!in_array($layoutStyle, ['classic','modern','minimal','professional','compact'], true)) fail('Invoice template style is invalid.');
    $fontFamily = (string)($input['fontFamily'] ?? ($existing['font_family'] ?? 'Arial'));
    if (!in_array($fontFamily, ['Arial','Inter','Georgia','Helvetica','Verdana'], true)) fail('Invoice template font is invalid.');
    $businessNameOverride = invoice_optional_text($input['businessName'] ?? ($existing['business_name_override'] ?? null), 160);
    $taxNumberOverride = invoice_optional_text($input['taxNumber'] ?? ($existing['tax_number_override'] ?? null), 40);
    $logoData = array_key_exists('logoData', $input) ? $input['logoData'] : ($existing['logo_data'] ?? null);
    if ($logoData !== null && $logoData !== '') {
        $logoData = trim((string)$logoData);
        if (!preg_match('#^data:image/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $logoData) || strlen($logoData) > 700000) {
            fail('Logo must be a PNG, JPEG or WebP image no larger than about 500 KB.');
        }
    } else $logoData = null;
    $businessAddress = invoice_optional_text($input['businessAddress'] ?? ($existing['business_address'] ?? null), 500);
    $emailRaw = trim((string)($input['businessEmail'] ?? ($existing['business_email'] ?? '')));
    $businessEmail = $emailRaw !== '' ? safe_email($emailRaw) : null;
    $businessPhone = invoice_optional_text($input['businessPhone'] ?? ($existing['business_phone'] ?? null), 60);
    $paymentInstructions = invoice_optional_text($input['paymentInstructions'] ?? ($existing['payment_instructions'] ?? null), 1000);
    $footer = invoice_optional_text($input['footer'] ?? ($existing['footer'] ?? null), 1000);
    $showTaxNumber = array_key_exists('showTaxNumber', $input) ? !empty($input['showTaxNumber']) : (bool)($existing['show_tax_number'] ?? true);
    $showPayment = array_key_exists('showPaymentInstructions', $input) ? !empty($input['showPaymentInstructions']) : (bool)($existing['show_payment_instructions'] ?? true);
    $isDefault = array_key_exists('isDefault', $input) ? !empty($input['isDefault']) : (bool)($existing['is_default'] ?? false);
    if ($updating && (bool)$existing['is_default']) $isDefault = true;
    if (!$updating && !$isDefault) {
        $defaultStmt = db()->prepare('SELECT COUNT(*) FROM invoice_templates WHERE company_id = ? AND is_default = 1 AND active = 1');
        $defaultStmt->execute([$companyId]);
        $isDefault = (int)$defaultStmt->fetchColumn() === 0;
    }

    db()->beginTransaction();
    try {
        if ($isDefault) {
            db()->prepare('UPDATE invoice_templates SET is_default = 0 WHERE company_id = ?')->execute([$companyId]);
        }
        if ($updating) {
            db()->prepare('UPDATE invoice_templates SET name = ?, is_default = ?, document_title = ?, accent_color = ?, layout_style = ?, font_family = ?,
                business_name_override = ?, tax_number_override = ?, logo_data = ?, business_address = ?, business_email = ?, business_phone = ?, payment_instructions = ?, footer = ?,
                show_tax_number = ?, show_payment_instructions = ? WHERE id = ? AND company_id = ?')
                ->execute([$name, $isDefault ? 1 : 0, $documentTitle, $accent, $layoutStyle, $fontFamily, $businessNameOverride, $taxNumberOverride, $logoData, $businessAddress, $businessEmail,
                    $businessPhone, $paymentInstructions, $footer, $showTaxNumber ? 1 : 0, $showPayment ? 1 : 0,
                    $templateId, $companyId]);
        } else {
            db()->prepare('INSERT INTO invoice_templates
                (id, company_id, name, is_default, document_title, accent_color, layout_style, font_family, business_name_override, tax_number_override, logo_data, business_address, business_email,
                 business_phone, payment_instructions, footer, show_tax_number, show_payment_instructions)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$templateId, $companyId, $name, $isDefault ? 1 : 0, $documentTitle, $accent, $layoutStyle, $fontFamily, $businessNameOverride, $taxNumberOverride, $logoData,
                    $businessAddress, $businessEmail, $businessPhone, $paymentInstructions, $footer,
                    $showTaxNumber ? 1 : 0, $showPayment ? 1 : 0]);
        }
        audit_event($user, $companyId, $updating ? 'invoice_template.updated' : 'invoice_template.created',
            'invoice_template', $templateId, ['name' => $name, 'isDefault' => $isDefault]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            fail('An invoice template with that name already exists.', 409, 'template_name_exists');
        }
        throw $error;
    }
    json_response(['template' => ['id' => $templateId]], $updating ? 200 : 201);
}
