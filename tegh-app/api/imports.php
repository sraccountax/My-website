<?php
declare(strict_types=1);

function private_storage_root(): string
{
    $configured = trim((string)(config('storage_path') ?? ''));
    if ($configured === '') {
        throw new RuntimeException('Secure storage is not configured.');
    }
    if (!is_dir($configured) && !mkdir($configured, 0700, true) && !is_dir($configured)) {
        throw new RuntimeException('Secure storage could not be created.');
    }
    $root = realpath($configured);
    if ($root === false) {
        throw new RuntimeException('Secure storage path is invalid.');
    }
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== false && ($root === $documentRoot || str_starts_with($root . DIRECTORY_SEPARATOR, $documentRoot . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Secure storage must be outside the public web root.');
    }
    return rtrim($root, DIRECTORY_SEPARATOR);
}

function safe_upload_extension(string $name): string
{
    return strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
}

/** @return array{relativePath:string,absolutePath:string,originalName:string,mime:string,extension:string,size:int} */
function save_private_upload(string $companyId, string $kind, array $allowedExtensions, array $allowedMimes): array
{
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        fail('Choose a file to upload.');
    }
    $file = $_FILES['file'];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $message = $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE ? 'The file exceeds the server upload limit.' : 'The file upload did not complete.';
        fail($message, 400, 'upload_failed');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        fail('Files must be between 1 byte and 10 MB.', 413, 'file_size_invalid');
    }
    $original = trim((string)($file['name'] ?? 'upload'));
    $extension = safe_upload_extension($original);
    if (!in_array($extension, $allowedExtensions, true)) {
        fail('This file extension is not supported.');
    }
    $temporary = (string)($file['tmp_name'] ?? '');
    if ($temporary === '' || !is_uploaded_file($temporary)) {
        fail('The uploaded file could not be verified.', 400, 'upload_unverified');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($temporary);
    if (!in_array($mime, $allowedMimes, true)) {
        fail('The file contents do not match an allowed format.', 415, 'file_type_invalid');
    }
    $root = private_storage_root();
    $relativeDirectory = $companyId . '/' . $kind;
    $directory = $root . '/' . $relativeDirectory;
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The secure upload directory could not be created.');
    }
    $relative = $relativeDirectory . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
    $absolute = $root . '/' . $relative;
    if (!move_uploaded_file($temporary, $absolute)) {
        throw new RuntimeException('The uploaded file could not be moved into secure storage.');
    }
    chmod($absolute, 0600);
    return [
        'relativePath' => $relative, 'absolutePath' => $absolute,
        'originalName' => mb_substr($original !== '' ? $original : ('upload.' . $extension), 0, 240),
        'mime' => $mime, 'extension' => $extension, 'size' => $size,
    ];
}

function private_absolute_path(string $relative): ?string
{
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..') || str_starts_with($relative, '/')) {
        return null;
    }
    $root = private_storage_root();
    $resolved = realpath($root . '/' . $relative);
    if ($resolved === false || !is_file($resolved) || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $resolved;
}

function valid_private_storage_reference(string $companyId, string $relative, string $kind): bool
{
    $prefix = $companyId . '/' . $kind . '/';
    return str_starts_with($relative, $prefix) && private_absolute_path($relative) !== null;
}

function delete_private_file(string $relative): void
{
    $absolute = private_absolute_path($relative);
    if ($absolute !== null) {
        unlink($absolute);
    }
}

function stream_private_file(string $relative, string $downloadName): never
{
    $absolute = private_absolute_path($relative);
    if ($absolute === null) {
        fail('The stored source file is unavailable.', 404, 'stored_file_missing');
    }
    $extension = safe_upload_extension($relative);
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $downloadName) ?: 'download';
    if ($extension !== '' && !str_ends_with(strtolower($safeName), '.' . $extension)) {
        $safeName .= '.' . $extension;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolute) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($absolute));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store, max-age=0');
    $stream = fopen($absolute, 'rb');
    if ($stream === false) {
        fail('The stored source file could not be opened.', 500, 'stored_file_unreadable');
    }
    fpassthru($stream);
    fclose($stream);
    exit;
}

function normalize_merchant(string $description): string
{
    $value = mb_strtoupper(html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $value = preg_replace('/\b(POS|DEBIT|PURCHASE|PAYMENT|ONLINE|E-?TRANSFER|INTERAC|VISA|MC)\b/u', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d{5,}\b/u', ' ', $value) ?? $value;
    $value = preg_replace('/[^A-Z0-9& .-]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    return mb_substr($value, 0, 200);
}

function statement_non_transaction_reason(string $description): ?string
{
    $value = mb_strtoupper(trim($description), 'UTF-8');
    $value = preg_replace('/\bBALANCE\s+B\s*\/?\s*F\b/u', 'BALANCE FORWARD', $value) ?? $value;
    $value = preg_replace('/\bB\s*\/?\s*F\s+BALANCE\b/u', 'BALANCE FORWARD', $value) ?? $value;
    $value = preg_replace('/\bB\s*\/?\s*F\b/u', 'BALANCE FORWARD', $value) ?? $value;
    $value = preg_replace('/[^A-Z0-9]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    if ($value === '') return null;
    $groups = [
        'balance' => [
            'BALANCE FORWARD', 'BALANCE FORWARD BALANCE', 'BALANCE BROUGHT FORWARD',
            'BROUGHT FORWARD BALANCE', 'BALANCE CARRIED FORWARD', 'CARRIED FORWARD BALANCE',
            'OPENING BALANCE', 'BEGINNING BALANCE', 'PREVIOUS BALANCE', 'CLOSING BALANCE',
            'ENDING BALANCE', 'NEW BALANCE', 'ACCOUNT BALANCE', 'CURRENT BALANCE',
            'AVAILABLE BALANCE', 'DAILY CLOSING BALANCE',
        ],
        'summary' => [
            'ACCOUNT SUMMARY', 'STATEMENT SUMMARY', 'TOTAL DEBITS', 'TOTAL CREDITS',
            'TOTAL WITHDRAWALS', 'TOTAL DEPOSITS', 'TOTAL PAYMENTS', 'TOTAL PURCHASES',
            'SUBTOTAL', 'TOTAL',
        ],
    ];
    foreach ($groups as $reason => $labels) {
        foreach ($labels as $label) {
            if ($value === $label) return $reason;
            if (!str_starts_with($value, $label . ' ')) continue;
            // Balance-forward/opening/closing labels are statement metadata,
            // even when the bank appends a date, reference, account suffix, or
            // test/import identifier. They must never become ledger activity.
            if ($reason === 'balance') return $reason;
            $tail = trim(substr($value, strlen($label)));
            $tail = preg_replace('/^FROM (?:THE )?(?:PREVIOUS|PRIOR|LAST) STATEMENT(?:\s+|$)/', '', $tail) ?? $tail;
            $tail = preg_replace('/^(?:AS OF|AT|ON)\s+/', '', $tail) ?? $tail;
            $tail = preg_replace('/\b(?:CAD|USD|JAN(?:UARY)?|FEB(?:RUARY)?|MAR(?:CH)?|APR(?:IL)?|MAY|JUN(?:E)?|JUL(?:Y)?|AUG(?:UST)?|SEP(?:TEMBER)?|OCT(?:OBER)?|NOV(?:EMBER)?|DEC(?:EMBER)?)\b/u', '', $tail) ?? $tail;
            $tail = preg_replace('/\d+|\s+/u', '', $tail) ?? $tail;
            if ($tail === '') return $reason;
        }
    }
    return null;
}

function is_statement_non_transaction_row(string $description): bool
{
    return statement_non_transaction_reason($description) !== null;
}

function parse_statement_date(string $raw): string
{
    $value = trim($raw, " \t\n\r\0\x0B\"");
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $match)) {
        $value = $match[1] . '-' . $match[2] . '-' . $match[3];
    }
    $formats = ['!Y-m-d', '!Y/m/d', '!m/d/Y', '!m-d-Y', '!d-M-Y', '!M d Y'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        if ($date !== false && $date->format(str_replace('!', '', $format)) === $value) {
            return $date->format('Y-m-d');
        }
    }
    fail('A statement row contains an unsupported date: ' . mb_substr($value, 0, 30));
}

function parse_statement_amount(string $raw, bool $allowZero = false): int
{
    $value=trim(str_replace(["\xC2\xA0","\xE2\x80\xAF"],' ',$raw));$negative=(str_starts_with($value,'(')&&str_ends_with($value,')'))||preg_match('/\bDR\b/i',$value)===1||str_starts_with(ltrim($value),'-');
    $value=preg_replace('/[\sA-Za-z$£€¥()\-+]/u','',$value)??'';if($value===''||preg_match('/[^0-9.,]/',$value))fail('A statement row contains an invalid amount.');
    $dot=strrpos($value,'.');$comma=strrpos($value,',');$decimal=null;
    if($dot!==false&&$comma!==false)$decimal=$dot>$comma?'.':',';
    elseif($dot!==false){$digits=strlen($value)-$dot-1;$decimal=$digits<=2?'.':null;}
    elseif($comma!==false){$digits=strlen($value)-$comma-1;$decimal=$digits<=2?',':null;}
    if($decimal!==null){$parts=explode($decimal,$value);if(count($parts)!==2||strlen($parts[1])>2)fail('A statement row contains an ambiguous amount.');$whole=str_replace($decimal==='.'?',':'.','',$parts[0]);$fraction=str_pad($parts[1],2,'0');}
    else{$whole=str_replace([',','.'],'',$value);$fraction='00';}
    if($whole===''||!ctype_digit($whole)||!ctype_digit($fraction))fail('A statement row contains an invalid amount.');$whole=ltrim($whole,'0');$whole=$whole===''?'0':$whole;if(strlen($whole)>12)fail('A statement amount is outside the supported range.');$amount=(int)$whole*100+(int)$fraction;if($negative)$amount=-$amount;
    if ((!$allowZero && $amount === 0) || abs($amount) > 100_000_000_000) {
        fail('A statement amount is zero or outside the supported range.');
    }
    return $amount;
}

function normalized_header(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? trim($value);
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
}

function find_header_index(array $headers, array $candidates): ?int
{
    foreach ($candidates as $candidate) {
        $index = array_search($candidate, $headers, true);
        if ($index !== false) return (int)$index;
    }
    return null;
}

/** @return array<int,array{date:string,description:string,amountCents:int}> */
function parse_csv_statement(string $path, ?int &$excludedCount = null): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('The CSV statement could not be opened.');
    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        fail('The CSV statement is empty.');
    }
    $delimiters = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
    arsort($delimiters);
    $delimiter = (string)array_key_first($delimiters);
    rewind($handle);
    $headerRow = fgetcsv($handle, 0, $delimiter);
    if (!is_array($headerRow)) {
        fclose($handle);
        fail('The CSV header could not be read.');
    }
    $headers = array_map('normalized_header', $headerRow);
    $dateIndex = find_header_index($headers, ['date','transactiondate','posteddate','postingdate']);
    $descriptionIndex = find_header_index($headers, ['description','memo','details','payee','name','transactiondescription']);
    $amountIndex = find_header_index($headers, ['amount','transactionamount','value']);
    $debitIndex = find_header_index($headers, ['debit','withdrawal','withdrawals','moneyout']);
    $creditIndex = find_header_index($headers, ['credit','deposit','deposits','moneyin']);
    $referenceIndex = find_header_index($headers, ['reference','ref','transactionreference','chequenumber','checknumber']);
    $currencyIndex = find_header_index($headers, ['currency','currencycode']);
    $runningBalanceIndex = find_header_index($headers, ['runningbalance','balance','accountbalance']);
    if ($dateIndex === null || $descriptionIndex === null || ($amountIndex === null && $debitIndex === null && $creditIndex === null)) {
        fclose($handle);
        fail('CSV columns must include Date, Description, and Amount (or Debit/Credit).');
    }
    $rows = [];
    $excluded = 0;
    $rowNumber = 1;
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNumber++;
        if (count($rows) >= 5000) {
            fclose($handle);
            fail('A single import may contain at most 5,000 transactions.');
        }
        if (count(array_filter($row, static fn($value): bool => trim((string)$value) !== '')) === 0) continue;
        $description = preg_replace('/\s+/u', ' ', trim((string)($row[$descriptionIndex] ?? ''))) ?? '';
        if ($amountIndex !== null && trim((string)($row[$amountIndex] ?? '')) !== '') {
            $amount = parse_statement_amount((string)$row[$amountIndex], true);
        } else {
            $debitRaw = $debitIndex !== null ? trim((string)($row[$debitIndex] ?? '')) : '';
            $creditRaw = $creditIndex !== null ? trim((string)($row[$creditIndex] ?? '')) : '';
            $debit = $debitRaw !== '' ? parse_statement_amount($debitRaw, true) : 0;
            $credit = $creditRaw !== '' ? parse_statement_amount($creditRaw, true) : 0;
            if ($debit !== 0 && $credit !== 0) {
                fclose($handle);
                fail('CSV row ' . $rowNumber . ' contains both a debit and a credit.');
            }
            $amount = $debit !== 0 ? -abs($debit) : abs($credit);
        }
        if ($amount === 0) {
            $excluded++;
            continue;
        }
        if ($description === '') {
            fclose($handle);
            fail('CSV row ' . $rowNumber . ' has a non-zero amount but is missing a description.');
        }
        if (is_statement_non_transaction_row($description)) {
            $excluded++;
            continue;
        }
        $date = parse_statement_date((string)($row[$dateIndex] ?? ''));
        assert_not_future_date($date, 'Statement transaction date');
        $reference=$referenceIndex!==null?optional_text($row[$referenceIndex]??null,120):null;
        $rowCurrency=$currencyIndex!==null?strtoupper(trim((string)($row[$currencyIndex]??''))):'';
        if($rowCurrency!==''&&!preg_match('/^[A-Z]{3}$/',$rowCurrency)){fclose($handle);fail('CSV row '.$rowNumber.' has an invalid Currency code.');}
        $runningBalanceRaw=$runningBalanceIndex!==null?trim((string)($row[$runningBalanceIndex]??'')):'';
        $sourceRunningBalance=$runningBalanceRaw!==''?parse_statement_amount($runningBalanceRaw,true):null;
        $rows[] = ['date' => $date, 'reference'=>$reference, 'currency'=>$rowCurrency!==''?$rowCurrency:null, 'amountCents' => $amount, 'sourceRunningBalanceCents'=>$sourceRunningBalance] + statement_description_fields($description, 'Description in CSV row '.$rowNumber);
    }
    fclose($handle);
    $excludedCount = $excluded;
    return $rows;
}

function ofx_tag(string $block, string $tag): string
{
    if (preg_match('/<' . preg_quote($tag, '/') . '>([^<\r\n]*)/i', $block, $match) !== 1) return '';
    return trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** @return array<int,array{date:string,description:string,amountCents:int}> */
function parse_ofx_statement(string $path, ?int &$excludedCount = null): array
{
    $content = file_get_contents($path);
    if ($content === false || $content === '') fail('The OFX/QFX statement is empty.');
    if (strlen($content) > 10 * 1024 * 1024) fail('The statement is too large.', 413);
    preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>|<\/BANKTRANLIST>))/is', $content, $matches);
    $rows = [];
    $excluded = 0;
    foreach ($matches[1] ?? [] as $block) {
        if (count($rows) >= 5000) fail('A single import may contain at most 5,000 transactions.');
        $name = ofx_tag((string)$block, 'NAME');
        $memo = ofx_tag((string)$block, 'MEMO');
        $description = trim($name . ($memo !== '' && stripos($name, $memo) === false ? ' · ' . $memo : ''));
        if ($description === '') $description = 'Bank transaction';
        if (is_statement_non_transaction_row($description)) {
            $excluded++;
            continue;
        }
        $date = parse_statement_date(ofx_tag((string)$block, 'DTPOSTED'));
        assert_not_future_date($date, 'Statement transaction date');
        $amount = parse_statement_amount(ofx_tag((string)$block, 'TRNAMT'));
        $rows[] = ['date' => $date, 'amountCents' => $amount] + statement_description_fields($description, 'Description in OFX row '.(count($rows)+1));
    }
    $excludedCount = $excluded;
    return $rows;
}

/**
 * Validate transaction rows extracted in the authenticated browser.
 *
 * PDF and Excel parsers run locally so IONOS does not need native document
 * conversion tools. The original source is still uploaded and retained, while
 * every derived row is revalidated here before it can enter the review queue.
 *
 * @return array{engine:string,format:string,pageCount:int,accountLastFour:?string,excludedRows:int,rows:array<int,array{date:string,description:string,amountCents:int}>}
 */
function statement_description_fields(mixed $value, string $label = 'Statement description'): array
{
    // The register keeps its existing VARCHAR(500). The full reviewed wording
    // lives in the retained preview JSON, alongside the original source file.
    $full = clean_text($value, $label, 4000);
    return ['description'=>mb_substr($full,0,500),'fullDescription'=>$full,'descriptionShortened'=>mb_strlen($full)>500];
}

function statement_description_metadata(array $row): array
{
    $fields = statement_description_fields($row['fullDescription'] ?? $row['description'] ?? '');
    if ($fields['description'] !== (string)($row['description'] ?? ''))
        fail('The statement description changed. Review this row again.',422,'statement_description_changed');
    return ['fullDescription'=>$fields['fullDescription'],'descriptionShortened'=>$fields['descriptionShortened']];
}

function statement_converter_row_metadata(array $row, int $pageCount = 30): array
{
    $page = safe_cents($row['sourcePage'] ?? null, 'Source page');
    $sourceRow = safe_cents($row['sourceRow'] ?? null, 'Source row');
    $score = safe_cents($row['extractionScore'] ?? 0, 'Extraction score', true);
    if ($page < 1 || $page > $pageCount || $sourceRow < 1 || $sourceRow > 5000 || $score < 0 || $score > 100)
        fail('The statement source location or extraction score is invalid.',422,'statement_provenance_invalid');
    if (($row['userReviewed'] ?? null) !== true) fail('Review every imported transaction first.',422,'statement_review_required');
    foreach (['userExcluded','reviewedCategory'] as $flag) if (isset($row[$flag]) && !is_bool($row[$flag])) fail('An invalid statement review flag was supplied.',422,'statement_review_invalid');
    $method = (string)($row['extractionMethod'] ?? '');
    if (!in_array($method,['Text','OCR'],true)) fail('The extraction method is invalid.',422,'statement_provenance_invalid');
    $ambiguous=($row['originalDateAmbiguous']??false)===true;
    if($ambiguous&&(($row['dateCorrected']??false)!==true||trim((string)($row['sourceDateToken']??''))===''))fail('The original ambiguous date requires an explicit row correction.',422,'statement_date_correction_required');
    return [
        'sourcePage'=>$page,'sourceRow'=>$sourceRow,'sourceText'=>clean_text($row['sourceText']??'','Source text',8000),
        'originalDate'=>$ambiguous?null:safe_date($row['originalDate']??$row['date']??'','Original date'),
        'originalDateAmbiguous'=>$ambiguous,'dateCorrected'=>($row['dateCorrected']??false)===true,'sourceDateToken'=>optional_text($row['sourceDateToken']??'',80)??'',
        'originalDescription'=>clean_text($row['originalDescription']??$row['description']??'','Original description',4000),
        'originalAmountCents'=>safe_cents($row['originalAmountCents']??null,'Original amount'),
        'sourceBalanceCents'=>($row['sourceBalanceCents']??null)===null?null:safe_cents($row['sourceBalanceCents'],'Source balance',true),
        'categoryHint'=>clean_text($row['categoryHint']??'Needs review','Category suggestion',160),
        'extractionMethod'=>$method,'extractionScore'=>$score,'extractionIssues'=>optional_text($row['extractionIssues']??'',1000)??'',
        'userReviewed'=>true,'userExcluded'=>($row['userExcluded']??false)===true,
        'reviewedCategory'=>($row['reviewedCategory']??false)===true,
        'reviewedAccountId'=>($row['reviewedAccountId']??null)===null?null:clean_text($row['reviewedAccountId'],'Suggested account',64),
        'reference'=>optional_text($row['reference']??'',160)??'',
    ];
}

function statement_import_row_suggestion(string $companyId, array $row, int $baseAmount, array $accountCodes): array
{
    if (!empty($row['reviewedCategory'])) {
        $accountId = $row['reviewedAccountId'] ?? null;
        if ($accountId !== null && $accountId !== '') {
            $query = db()->prepare('SELECT id FROM accounts WHERE id=? AND company_id=? AND active=1 AND is_control=0');
            $query->execute([$accountId,$companyId]);
            if (!$query->fetchColumn()) fail('The chosen category is unavailable for this company. Review the statement again.',422,'statement_category_invalid');
        } else $accountId = null;
        // User review is a manual suggestion, not a calibrated confidence score
        // and never a posted/decided account or tax approval.
        return ['accountId'=>$accountId,'taxCode'=>'NO_TAX','confidence'=>0,'source'=>'manual','merchant'=>normalize_merchant((string)$row['description'])];
    }
    $suggestion = suggest_import_account($companyId,(string)$row['description'],$baseAmount,$accountCodes);
    // An explicit/company suggestion wins. Only a small, unambiguous donor
    // category may fill an otherwise empty suggestion; borrowing, transfers,
    // remittances, rent/mortgage and inflows remain a human decision.
    if ($suggestion['accountId'] === null && preg_match('/LOAN|FINANCING|LINE OF CREDIT|OVERDRAFT|MORTGAGE|PAYROLL|REMITTANCE|TRANSFER|SHAREHOLDER|CREDIT CARD/', strtoupper((string)$row['description'])) === 1) return $suggestion;
    $codes = ['Bank Fees'=>'6800','Utilities & Telecom'=>'6300','Fuel & Vehicle'=>'6900','Meals & Entertainment'=>'6600','Office & Supplies'=>'6400','Insurance'=>'6950'];
    $code = $codes[(string)($row['categoryHint'] ?? '')] ?? null;
    if ($suggestion['accountId'] === null && $baseAmount < 0 && $code !== null && isset($accountCodes[$code])) {
        $query = db()->prepare('SELECT id FROM accounts WHERE id=? AND company_id=? AND active=1 AND is_control=0');
        $query->execute([$accountCodes[$code],$companyId]);
        if ($query->fetchColumn()) $suggestion = ['accountId'=>$accountCodes[$code],'taxCode'=>'NO_TAX','confidence'=>0,'source'=>'rule','merchant'=>$suggestion['merchant']];
    }
    return $suggestion;
}

function parse_client_statement_extraction(string $raw): array
{
    if ($raw === '' || strlen($raw) > 32 * 1024 * 1024) {
        fail('The browser extraction result is missing or too large. Refresh the app and try again.', 400, 'client_extraction_missing');
    }
    try {
        $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('The browser extraction result is not valid JSON.', 400, 'client_extraction_invalid');
    }
    if (!is_array($payload)) {
        fail('The browser extraction result must be an object.', 400, 'client_extraction_invalid');
    }
    $engine = clean_text($payload['engine'] ?? '', 'Extraction engine', 80);
    $format = clean_text($payload['format'] ?? '', 'Statement format', 80);
    $allowed = [
        'sr-bank-converter-v12.3-integrated' => ['scotia-business-account', 'scotia-line-business', 'generic-coordinate'],
        'sr-spreadsheet-v1.1' => ['spreadsheet'],
        'tegh-statement-converter-v5930' => ['reviewed-pdf'],
        'tegh-statement-converter-v5980' => ['reviewed-pdf'],
    ];
    if (!isset($allowed[$engine]) || !in_array($format, $allowed[$engine], true)) {
        fail('This browser extraction engine or statement format is not supported. Refresh the app and try again.', 400, 'client_extraction_unsupported');
    }
    $pageCount = safe_cents($payload['pageCount'] ?? null, 'Statement page or sheet count');
    if ($pageCount < 1 || $pageCount > 100) {
        fail('A statement may contain at most 100 pages or sheets.', 400, 'client_extraction_too_large');
    }
    if (in_array($format, ['scotia-business-account', 'scotia-line-business'], true)
        && ($payload['summaryVerified'] ?? null) !== true) {
        fail('The Scotia statement totals were not verified, so no transactions were imported.', 422, 'statement_totals_unverified');
    }
    $converter = in_array($engine,['tegh-statement-converter-v5930','tegh-statement-converter-v5980'],true);
    $periodStart = $periodEnd = null;
    if ($converter) {
        if (($payload['reviewConfirmed'] ?? null) !== true || !in_array($payload['dateOrder'] ?? 'auto', ['auto','mdy','dmy'], true))
            fail('Review and correct ambiguous statement dates before continuing.',422,'statement_review_required');
        if ($pageCount > 30) fail('PDF conversion supports up to 30 pages per upload.',422,'statement_page_limit');
    }
    $accountLastFour = $payload['accountLastFour'] ?? null;
    if ($accountLastFour !== null && preg_match('/^\d{4}$/', (string)$accountLastFour) !== 1) {
        fail('The extracted account identifier is invalid.', 400, 'client_account_identifier_invalid');
    }
    $sourceRows = $payload['rows'] ?? null;
    if (!is_array($sourceRows) || $sourceRows === [] || count($sourceRows) > 5000) {
        fail('The extraction must contain between 1 and 5,000 transaction rows.', 400, 'client_rows_invalid');
    }
    $reportedExcludedRows = safe_cents($payload['excludedRows'] ?? 0, 'Excluded statement row count', true);
    if ($reportedExcludedRows < 0 || $reportedExcludedRows > 5000) {
        fail('The excluded statement row count is invalid.', 400, 'client_rows_invalid');
    }
    $rows = [];
    $excludedRows = $reportedExcludedRows;
    foreach ($sourceRows as $index => $row) {
        if (!is_array($row)) {
            fail('An extracted transaction row is invalid.', 400, 'client_row_invalid');
        }
        $descriptionFields = statement_description_fields($row['description'] ?? '', 'Description in statement row '.($index+1));
        $description = $descriptionFields['description'];
        if (is_statement_non_transaction_row($description)) {
            $excludedRows++;
            continue;
        }
        $date = safe_date($row['date'] ?? '', 'Extracted transaction date');
        assert_not_future_date($date, 'Extracted transaction date');
        $amount = safe_cents($row['amountCents'] ?? null, 'Extracted transaction amount');

        if ($converter && $amount === 0) fail('A transaction must have a non-zero amount.',422,'statement_amount_zero');
        $extra = $converter ? statement_converter_row_metadata($row, $pageCount) : [];
        $rows[] = ['date' => $date, 'description' => $description, 'amountCents' => $amount] + $descriptionFields + $extra;
    }
    if ($rows === []) {
        fail('The file contained only balance or statement-summary rows, not transactions.', 422, 'no_transaction_rows');
    }
    $dates=array_column($rows,'date');$periodStart=min($dates);$periodEnd=max($dates);
    if((strtotime($periodEnd)-strtotime($periodStart))>366*86400)fail('Validated transactions span more than 366 days. Split the statement.',422,'statement_period_invalid');
    return [
        'engine' => $engine, 'format' => $format, 'pageCount' => $pageCount,
        'accountLastFour' => $accountLastFour === null ? null : (string)$accountLastFour,
        'excludedRows' => $excludedRows,'firstTransactionDate'=>$periodStart,'lastTransactionDate'=>$periodEnd,
        'rows' => $rows,
    ];
}

function account_code_map(string $companyId): array
{
    $stmt = db()->prepare('SELECT id, code FROM accounts WHERE company_id = ? AND active = 1 AND is_control = 0');
    $stmt->execute([$companyId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) $map[(string)$row['code']] = (string)$row['id'];
    return $map;
}

function suggest_import_account(string $companyId, string $description, int $amount, array $accountCodes): array
{
    $merchant = normalize_merchant($description);
    if ($merchant !== '') {
        // Tegh AI learned rules are recommendations only. A learned pattern may
        // pre-fill an import suggestion when it has enough evidence, but it can
        // never post the transaction or bypass Bank Review.
        if(schema_table_exists('ai_agent_learned_rules')){
            $learned=db()->prepare("SELECT pattern_value,suggestion_json,confidence_bps FROM ai_agent_learned_rules WHERE company_id=? AND status='enabled' AND suggestion_type='account_category' AND pattern_type='merchant_contains' AND confidence_bps>=6000 AND ? LIKE CONCAT('%',pattern_value,'%') ORDER BY CHAR_LENGTH(pattern_value) DESC,confidence_bps DESC,positive_count DESC LIMIT 10");
            $learned->execute([$companyId,$merchant]);
            foreach($learned->fetchAll() as $candidate){$suggestion=json_decode((string)$candidate['suggestion_json'],true)?:[];$accountId=trim((string)($suggestion['accountId']??''));if($accountId==='')continue;$valid=db()->prepare('SELECT id FROM accounts WHERE id=? AND company_id=? AND active=1 AND is_control=0 LIMIT 1');$valid->execute([$accountId,$companyId]);if(!$valid->fetchColumn())continue;$taxCode=(string)($suggestion['taxCode']??'NO_TAX');$confidence=max(60,min(98,(int)round(((int)$candidate['confidence_bps'])/100)));return ['accountId'=>$accountId,'taxCode'=>$taxCode,'confidence'=>$confidence,'source'=>'history','merchant'=>$merchant];}
        }
        $stmt = db()->prepare("SELECT cr.account_id, cr.tax_code FROM category_rules cr JOIN accounts a ON a.id = cr.account_id WHERE cr.company_id = ? AND a.company_id = cr.company_id AND a.active=1 AND a.is_control=0 AND cr.active = 1 AND ? LIKE CONCAT('%', cr.merchant_pattern, '%') ORDER BY CHAR_LENGTH(cr.merchant_pattern) DESC, cr.use_count DESC LIMIT 1");
        $stmt->execute([$companyId, $merchant]);
        $rule = $stmt->fetch();
        if ($rule) return ['accountId' => (string)$rule['account_id'], 'taxCode' => (string)$rule['tax_code'], 'confidence' => 96, 'source' => 'history', 'merchant' => $merchant];
        $stmt = db()->prepare("SELECT decided_account_id, tax_code, COUNT(*) AS uses FROM bank_transactions WHERE company_id = ? AND normalized_merchant = ? AND status = 'posted' AND decided_account_id IS NOT NULL GROUP BY decided_account_id, tax_code ORDER BY uses DESC LIMIT 1");
        $stmt->execute([$companyId, $merchant]);
        $history = $stmt->fetch();
        if ($history && !in_array((string)$history['decided_account_id'], array_values($accountCodes), true)) $history = false;
        if ($history) return ['accountId' => (string)$history['decided_account_id'], 'taxCode' => (string)$history['tax_code'], 'confidence' => 92, 'source' => 'history', 'merchant' => $merchant];
    }
    $value = mb_strtoupper($description);
    // Ambiguous deposits, debt, transfers and remittances are not revenue.
    // Explicit company rules/history above still take precedence.
    if (preg_match('/LOAN|FINANCING|LINE OF CREDIT|OVERDRAFT.*(?:ADVANCE|REPAYMENT)|SHAREHOLDER|PAYROLL REMITTANCE|(?:CRA|REVENUE CANADA).*PAYROLL|PAYROLL.*(?:CRA|REVENUE CANADA)/', $value) === 1)
        return ['accountId'=>null,'taxCode'=>'NO_TAX','confidence'=>0,'source'=>'rule','merchant'=>$merchant];
    $choice = [null, 'NO_TAX', 0];
    $rules = [
        // R163: money-in payouts first; the money-out rules below still apply to a software charge from the same company.
        ['/STRIPE PAYOUT|SQUARE (?:PAYOUT|DEPOSIT)|SHOPIFY PAYOUT|CLIENT DEPOSIT/', ['4000','NO_TAX',72]],
        ['/ADOBE|MICROSOFT|GOOGLE|DROPBOX|OPENAI/', ['6200','GST_HST',94]],
        ['/\\bZOOM\\b|SLACK|SHOPIFY|INTUIT|QUICKBOOKS|CANVA|GODADDY|SQUARESPACE|\\bWIX\\b|GITHUB|AMAZON WEB SERVICES|\\bAWS\\b|NOTION/', ['6200','GST_HST',88]],
        ['/VIRGIN (?:PLUS|MOBILE)|KOODO|\\bSHAW\\b|COGECO|VIDEOTRON|FREEDOM MOBILE|PUBLIC MOBILE/', ['6300','GST_HST',88]],
        ['/GRAND (?:&|AND) TOY|CANADA POST|PUROLATOR|FEDEX/', ['6400','GST_HST',84]],
        ['/STARBUCKS|MCDONALD|SUBWAY|\\bA&W\\b/', ['6600','NO_TAX',82]],
        ['/IMPARK|GREEN P|\\bPARKING\\b|PRESTO|407 ETR|MARRIOTT|HILTON|AIRBNB|\\bHOTEL\\b/', ['6500','GST_HST',80]],
        ['/HUSKY|ULTRAMAR|\\bIRVING\\b|PIONEER|CANADIAN TIRE GAS/', ['6900','GST_HST',84]],
        ['/INTACT INS|AVIVA|WAWANESA|BELAIR/', ['6950','NO_TAX',84]],
        ['/NSF FEE|OVERDRAFT FEE|ACCOUNT FEE|PLAN FEE|ANNUAL FEE|WIRE FEE/', ['6800','NO_TAX',90]],
        ['/WESTERN IT GROUP|IT SUPPORT|COMPUTER SUPPORT/', ['6200','GST_HST',90]],
        ['/\\b(?:BELL|ROGERS|TELUS|FIDO)\\b/', ['6300','GST_HST',90]],
        ['/STAPLES|OFFICE DEPOT/', ['6400','GST_HST',91]],
        ['/UBER\s*EATS|UBEREATS|TIM HORTONS|RAJDHANI|RESTAURANT|CAFE|COFFEE|DOORDASH|SKIP(?:\s+THE)?\s+DISHES/', ['6600','NO_TAX',86]],
        ['/\bUBER\b|LYFT|VIA RAIL|GO TRANSIT|AIR CANADA|WESTJET/', ['6500','GST_HST',80]],
        ['/\bPETRO(?:-?CANADA)?\b|\bESSO\b|\bSHELL\b|GAS STATION|FUEL/', ['6900','GST_HST',88]],
        ['/BANK FEE|SERVICE CHARGE|MONTHLY FEE|INTEREST/', ['6800','NO_TAX',91]],
        ['/INSURANCE/', ['6950','NO_TAX',86]],
        ['/OWNER CONTRIBUTION|FROM PERSONAL/', ['3000','NO_TAX',84]],
        ['/MERCHANT DEPOSIT/', ['4000','NO_TAX',72]],
    ];
    foreach ($rules as [$pattern, $candidate]) {
        // R163: owner (3000) and revenue (4000) rules apply to money in only; expense rules to money out only.
        $moneyInRule = in_array($candidate[0], ['3000','4000'], true);
        if (preg_match($pattern, $value) === 1 && ($moneyInRule ? $amount > 0 : $amount < 0)) {
            $choice = $candidate;
            break;
        }
    }
    $accountId = $choice[0] === null ? null : ($accountCodes[$choice[0]] ?? null);
    return ['accountId' => $accountId, 'taxCode' => $choice[1], 'confidence' => $choice[2], 'source' => 'rule', 'merchant' => $merchant];
}

function statement_allowed_extensions(): array
{
    return ['csv','xlsx'];
}

function statement_allowed_mimes(): array
{
    return [
        'text/plain','text/csv','application/csv','application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip','application/octet-stream',
    ];
}

function import_json_request(int $limitBytes = 5_000_000): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    if (strlen($raw) > $limitBytes) {
        fail('The statement upload request is too large.', 413, 'import_request_too_large');
    }
    try {
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('The statement upload request is not valid JSON.', 400, 'import_request_invalid');
    }
    if (!is_array($decoded)) fail('The statement upload request must be an object.', 400, 'import_request_invalid');
    return $decoded;
}

function import_staging_directory(string $companyId): string
{
    $root = private_storage_root();
    $directory = $root . '/' . $companyId . '/statement-upload-staging';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The secure statement staging directory could not be created.');
    }
    return $directory;
}

function cleanup_import_staging(string $companyId): void
{
    $directory = import_staging_directory($companyId);
    $cutoff = time() - 2 * 3600;
    foreach (glob($directory . '/*.{part,json}', GLOB_BRACE) ?: [] as $path) {
        $modified = @filemtime($path);
        if ($modified !== false && $modified < $cutoff) @unlink($path);
    }
}

function import_upload_paths(string $companyId, string $uploadId): array
{
    if (preg_match('/^[a-f0-9]{48}$/', $uploadId) !== 1) {
        fail('The secure statement upload reference is invalid.', 400, 'upload_reference_invalid');
    }
    $directory = import_staging_directory($companyId);
    return [$directory . '/' . $uploadId . '.part', $directory . '/' . $uploadId . '.json'];
}

function import_upload_metadata(string $companyId, string $uploadId): array
{
    [$part, $meta] = import_upload_paths($companyId, $uploadId);
    if (!is_file($meta) || !is_file($part)) {
        fail('The secure statement upload has expired. Start the import again.', 410, 'upload_staging_expired');
    }
    $raw = file_get_contents($meta);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data) || (string)($data['companyId'] ?? '') !== $companyId) {
        fail('The secure statement upload reference is invalid.', 400, 'upload_reference_invalid');
    }
    if ((int)($data['createdAt'] ?? 0) < time() - 2 * 3600) {
        @unlink($part); @unlink($meta);
        fail('The secure statement upload has expired. Start the import again.', 410, 'upload_staging_expired');
    }
    return [$data, $part, $meta];
}

function statement_import_context(array $company, string $bankAccountId, mixed $exchangeRateInput): array
{
    $companyId = (string)$company['id'];
    $bankAccountId = clean_text($bankAccountId, 'Bank account', 64);
    $stmt = db()->prepare('SELECT id, account_type, masked_number, currency FROM bank_accounts WHERE id = ? AND company_id = ? AND active = 1');
    $stmt->execute([$bankAccountId, $companyId]);
    $bankAccount = $stmt->fetch();
    if (!$bankAccount) fail('Choose a valid bank or credit-card account.');
    $currency = safe_currency_code($bankAccount['currency'] ?? $company['currency'], 'Bank account currency');
    $currencyRow = company_currency($companyId, $currency);
    if (!$currencyRow) fail('The selected account currency is not active for this company.');
    $exchangeRateMicros = safe_exchange_rate_micros(
        $exchangeRateInput ?? $currencyRow['rate_to_base_micros'],
        $currency,
        (string)$company['currency']
    );
    return compact('bankAccountId','bankAccount','currency','exchangeRateMicros');
}

function adopt_staged_statement_upload(string $companyId, string $uploadId): array
{
    [$meta, $partPath, $metaPath] = import_upload_metadata($companyId, $uploadId);
    $expectedSize = (int)($meta['size'] ?? 0);
    $actualSize = (int)(@filesize($partPath) ?: 0);
    if ($expectedSize <= 0 || $expectedSize > 10 * 1024 * 1024 || $actualSize !== $expectedSize) {
        fail('The statement upload is incomplete. Retry the import.', 409, 'upload_incomplete');
    }
    $original = trim((string)($meta['filename'] ?? 'statement'));
    $extension = safe_upload_extension($original);
    if (!in_array($extension, statement_allowed_extensions(), true)) {
        fail('This statement file extension is not supported.', 415, 'file_extension_invalid');
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($partPath);
    if (!in_array($mime, statement_allowed_mimes(), true)) {
        fail('The statement contents do not match an allowed format.', 415, 'file_type_invalid');
    }
    $root = private_storage_root();
    $relativeDirectory = $companyId . '/statements';
    $directory = $root . '/' . $relativeDirectory;
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The secure statement directory could not be created.');
    }
    $relative = $relativeDirectory . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
    $absolute = $root . '/' . $relative;
    if (!rename($partPath, $absolute)) {
        throw new RuntimeException('The completed statement upload could not be moved into secure storage.');
    }
    @chmod($absolute, 0600);
    @unlink($metaPath);
    return [
        'relativePath'=>$relative,'absolutePath'=>$absolute,'originalName'=>mb_substr($original,0,240),
        'mime'=>$mime,'extension'=>$extension,'size'=>$actualSize,
    ];
}

function process_statement_upload(array $user, array $company, array $context, array $upload, string $clientExtraction, string $uploadTransport = 'chunked-json-v1'): never
{
    $companyId = (string)$company['id'];
    $bankAccountId = (string)$context['bankAccountId'];
    $bankAccount = $context['bankAccount'];
    $currency = (string)$context['currency'];
    $exchangeRateMicros = (int)$context['exchangeRateMicros'];
    try {
        $extraction = null;
        $excludedCount = 0;
        if ($upload['extension'] === 'csv') {
            $rows = parse_csv_statement($upload['absolutePath'], $excludedCount);
        } else $rows=parse_xlsx_statement_v5990($upload['absolutePath'],$excludedCount);
        if ($rows === []) fail('No transaction rows were found in the statement.');
        foreach ($rows as $row) {
            if (!empty($row['currency']) && strtoupper((string)$row['currency']) !== $currency) {
                fail('A CSV Currency value does not match the selected financial account currency.',422,'statement_currency_mismatch');
            }
        }
        $batchId = new_id('import');
        $accountCodes = account_code_map($companyId);
        $inserted = 0;
        $duplicates = 0;
        db()->beginTransaction();
        $bankLock=db()->prepare('SELECT id FROM bank_accounts WHERE id=? AND company_id=? AND active=1 FOR UPDATE');$bankLock->execute([$bankAccountId,$companyId]);if(!$bankLock->fetchColumn())fail('Choose an active financial account.',409,'bank_account_unavailable');
        $identityRows=[];foreach($rows as $row)$identityRows[]=$row+['foreignAmountCents'=>(int)$row['amountCents']];
        $rows=operations_statement_mark_duplicates($companyId,operations_statement_rows_with_keys($companyId,$bankAccountId,$currency,$identityRows));
        foreach($rows as $row)if(!empty($row['requiresDuplicateReview']))fail('Review this statement using the import preview. An older compact description may refer to the same transaction and needs an explicit separate-transaction confirmation.',409,'statement_duplicate_review_required');
        $batchStatus = 'extracted';
        db()->prepare('INSERT INTO import_batches (id, company_id, bank_account_id, filename, file_type, source_path, status, row_count, duplicate_count, currency, exchange_rate_micros) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)')
            ->execute([$batchId, $companyId, $bankAccountId, $upload['originalName'], $upload['mime'], $upload['relativePath'], $batchStatus, $currency, $exchangeRateMicros]);
        $duplicateStmt = db()->prepare('SELECT id FROM bank_transactions WHERE company_id = ? AND source_hash IN (?, ?) LIMIT 1');
        $insertStmt = db()->prepare("INSERT INTO bank_transactions (id, company_id, bank_account_id, import_batch_id, transaction_date, description, reference, normalized_merchant, amount_cents, currency, foreign_amount_cents, exchange_rate_micros, suggested_account_id, tax_code, confidence, suggestion_source, source_hash, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        foreach ($rows as $row) {
            if(!empty($row['duplicate'])){$duplicates++;continue;}
            $foreignAmount = (int)$row['amountCents'];
            $baseAmount = convert_to_base_cents($foreignAmount, $exchangeRateMicros);
            $fingerprint=(string)$row['sourceFingerprint'];$legacyFingerprint=(string)$row['legacySourceFingerprint'];
            $duplicateStmt->execute([$companyId, $fingerprint, $legacyFingerprint]);
            if ($duplicateStmt->fetchColumn() !== false) { $duplicates++; continue; }
            $suggestion = suggest_import_account($companyId, $row['description'], $baseAmount, $accountCodes);
            $transactionId = new_id('btx');
            $insertStmt->execute([
                $transactionId, $companyId, $bankAccountId, $batchId, $row['date'], $row['description'], $row['reference']??null, $suggestion['merchant'],
                $baseAmount, $currency, $foreignAmount, $exchangeRateMicros,
                $suggestion['accountId'], $suggestion['taxCode'], $suggestion['confidence'], $suggestion['source'], $fingerprint,
            ]);
            if (function_exists('voucher_register_saved')) {
                voucher_register_saved($user,$companyId,'BT','BS','bank_transaction',$transactionId,(string)$row['date'],'Bank statement · '.(string)$row['description'],abs($baseAmount),null,false);
            }
            $inserted++;
        }
        if ($inserted === 0 && $duplicates > 0) {
            fail('This statement has already been imported. No duplicate batch was created.', 409, 'statement_duplicate');
        }
        $retainedPreviewId=null;
        if(array_filter($rows,static fn(array $row):bool=>!empty($row['descriptionShortened']))){
            // Legacy auto-import keeps its batch response while preserving the
            // reviewed full wording in the same transaction as pending rows.
            $retainedPreviewId=new_id('preview');$running=0;$retainedRows=[];$dates=[];
            foreach($rows as $index=>$row){$running+=(int)$row['foreignAmountCents'];$dates[]=(string)$row['date'];$retainedRows[]=array_replace($row,['sequence'=>$index+1,'amountCents'=>convert_to_base_cents((int)$row['foreignAmountCents'],$exchangeRateMicros),'runningBalanceCents'=>$running]);}
            $sourceHash=hash_file('sha256',$upload['absolutePath']);if($sourceHash===false)throw new RuntimeException('The retained statement source could not be verified.');
            db()->prepare("INSERT INTO statement_previews (id,company_id,bank_account_id,filename,file_type,source_path,source_sha256,currency,exchange_rate_micros,opening_balance_cents,closing_balance_cents,first_transaction_date,last_transaction_date,row_count,excluded_count,rows_json,status,approved_batch_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,NULL,NULL,?,?,?,?,?,'approved',?,?)")
                ->execute([$retainedPreviewId,$companyId,$bankAccountId,$upload['originalName'],$upload['mime'],$upload['relativePath'],hash('sha256',$sourceHash.'|approved|'.$retainedPreviewId),$currency,$exchangeRateMicros,min($dates),max($dates),count($rows),$excludedCount,json_encode($retainedRows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$batchId,$user['id']]);
            db()->prepare('UPDATE import_batches SET preview_id=? WHERE id=? AND company_id=?')->execute([$retainedPreviewId,$batchId,$companyId]);
        }
        $finalStatus = $inserted > 0 ? 'needs_review' : 'extracted';
        db()->prepare('UPDATE import_batches SET status = ?, row_count = ?, duplicate_count = ? WHERE id = ?')
            ->execute([$finalStatus, $inserted, $duplicates, $batchId]);
        audit_event($user, $companyId, 'statement.imported', 'import_batch', $batchId, [
            'filename'=>$upload['originalName'],'insertedCount'=>$inserted,'duplicateCount'=>$duplicates,
            'nonTransactionRowsExcluded'=>$excludedCount,'parseSupported'=>true,
            'extractionEngine'=>$extraction['engine'] ?? 'server','statementFormat'=>$extraction['format'] ?? $upload['extension'],
            'currency'=>$currency,'exchangeRateMicros'=>$exchangeRateMicros,'uploadTransport'=>$uploadTransport,'retainedPreviewId'=>$retainedPreviewId,
        ]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        delete_private_file($upload['relativePath']);
        throw $error;
    }
    json_response(['batch'=>[
        'id'=>$batchId,'status'=>$finalStatus,'rowCount'=>$inserted,'duplicateCount'=>$duplicates,
        'excludedCount'=>$excludedCount,'format'=>$extraction['format'] ?? $upload['extension'],
    ]],201);
}

function handle_import_upload_start(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = import_json_request(200_000);
    $filename = clean_text($input['filename'] ?? '', 'Statement filename', 240);
    $size = (int)($input['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) fail('Files must be between 1 byte and 10 MB.', 413, 'file_size_invalid');
    if (!in_array(safe_upload_extension($filename), statement_allowed_extensions(), true)) {
        fail('This statement file extension is not supported.', 415, 'file_extension_invalid');
    }
    cleanup_import_staging($companyId);
    $uploadId = bin2hex(random_bytes(24));
    [$partPath, $metaPath] = import_upload_paths($companyId, $uploadId);
    if (file_put_contents($partPath, '', LOCK_EX) === false) throw new RuntimeException('The secure statement upload could not be started.');
    @chmod($partPath, 0600);
    $meta = ['companyId'=>$companyId,'filename'=>$filename,'size'=>$size,'createdAt'=>time()];
    if (file_put_contents($metaPath, json_encode($meta, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        @unlink($partPath);
        throw new RuntimeException('The secure statement upload metadata could not be saved.');
    }
    @chmod($metaPath, 0600);
    json_response(['uploadId'=>$uploadId,'chunkSize'=>393216],201);
}

function handle_import_upload_chunk(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = import_json_request(1_200_000);
    $uploadId = clean_text($input['uploadId'] ?? '', 'Upload reference', 64);
    [$meta, $partPath] = import_upload_metadata($companyId, $uploadId);
    $offset = (int)($input['offset'] ?? -1);
    $encoded = (string)($input['data'] ?? '');
    $chunk = base64_decode($encoded, true);
    if ($offset < 0 || $chunk === false || $chunk === '' || strlen($chunk) > 512 * 1024) {
        fail('The statement upload chunk is invalid.', 400, 'upload_chunk_invalid');
    }
    $currentSize = (int)(@filesize($partPath) ?: 0);
    clearstatcache(true, $partPath);
    $currentSize = (int)(@filesize($partPath) ?: 0);
    if ($offset !== $currentSize) fail('The statement upload chunks arrived out of order. Retry the import.',409,'upload_chunk_order');
    $expectedSize = (int)$meta['size'];
    if ($currentSize + strlen($chunk) > $expectedSize) fail('The statement upload exceeds its declared size.',413,'upload_size_mismatch');
    $written = file_put_contents($partPath, $chunk, FILE_APPEND | LOCK_EX);
    if ($written === false || $written !== strlen($chunk)) throw new RuntimeException('A statement upload chunk could not be stored.');
    json_response(['uploadId'=>$uploadId,'received'=>$currentSize + $written]);
}

function handle_import_upload_finish(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner', 'bookkeeper');
    $companyId = (string)$company['id'];
    $input = import_json_request(8_000_000);
    $uploadId = clean_text($input['uploadId'] ?? '', 'Upload reference', 64);
    $context = statement_import_context($company, (string)($input['bankAccountId'] ?? ''), $input['exchangeRateMicros'] ?? null);
    $upload = adopt_staged_statement_upload($companyId, $uploadId);
    if ((string)($input['mode'] ?? '') === 'preview') {
        $openingRaw=trim((string)($input['openingBalanceCents']??''));$closingRaw=trim((string)($input['closingBalanceCents']??''));
        $opening=$openingRaw===''?null:safe_cents($openingRaw,'Opening balance',true);$closing=$closingRaw===''?null:safe_cents($closingRaw,'Closing balance',true);
        $preview=operations_statement_preview_from_upload($user,$company,$context['bankAccount'],$upload,(string)($input['clientExtraction']??''),(string)$context['currency'],(int)$context['exchangeRateMicros'],$opening,$closing,'chunked-json-v1');
        json_response(['preview'=>$preview],201);
    }
    delete_private_file($upload['relativePath']);fail('Validate the server-created statement preview before importing. No rows were imported.',422,'statement_preview_required');
}

function handle_imports(): never
{
    $user = require_user();
    $company = require_company($user);
    $companyId = (string)$company['id'];
    if (request_method() === 'GET') {
        $batchId = clean_text($_GET['batchId'] ?? '', 'Import batch', 70);
        $stmt = db()->prepare('SELECT filename, source_path FROM import_batches WHERE id = ? AND company_id = ?');
        $stmt->execute([$batchId, $companyId]);
        $batch = $stmt->fetch();
        if (!$batch) fail('The imported source could not be found.', 404, 'import_not_found');
        stream_private_file((string)$batch['source_path'], (string)$batch['filename']);
    }
    require_method('POST');
    require_csrf();
    require_company_role($company, 'owner', 'bookkeeper');
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $contentLength = max(0, (int)($_SERVER['CONTENT_LENGTH'] ?? 0));
    if (str_contains($contentType, 'multipart/form-data') && $contentLength > 0 && !isset($_FILES['file']) && $_POST === []) {
        fail('The multipart upload was rejected before Tegh could read it. Refresh to the current Tegh build and retry; the current importer uses protected chunked uploads.',413,'legacy_multipart_rejected');
    }
    $context = statement_import_context($company, (string)($_POST['bankAccountId'] ?? ''), $_POST['exchangeRateMicros'] ?? null);
    $upload = save_private_upload($companyId, 'statements', statement_allowed_extensions(), statement_allowed_mimes());
    if ((string)($_POST['mode'] ?? '') === 'preview') {
        $openingRaw=trim((string)($_POST['openingBalanceCents']??''));$closingRaw=trim((string)($_POST['closingBalanceCents']??''));
        $opening=$openingRaw===''?null:safe_cents($openingRaw,'Opening balance',true);$closing=$closingRaw===''?null:safe_cents($closingRaw,'Closing balance',true);
        $preview=operations_statement_preview_from_upload($user,$company,$context['bankAccount'],$upload,(string)($_POST['clientExtraction']??''),(string)$context['currency'],(int)$context['exchangeRateMicros'],$opening,$closing,'legacy-multipart');
        json_response(['preview'=>$preview],201);
    }
    delete_private_file($upload['relativePath']);fail('Validate the server-created statement preview before importing. No rows were imported.',422,'statement_preview_required');
}

function handle_import_delete(): never
{
    require_method('POST');
    require_csrf();
    $user = require_user();
    $company = require_company($user);
    require_company_role($company, 'owner');
    $companyId = (string)$company['id'];
    $input = request_json();
    $batchId = clean_text($input['batchId'] ?? '', 'Import batch', 64);
    $quarantinedSource = null;
    $originalSource = null;
    $filename = '';
    $transactionCount = 0;
    $sourceWasPresent = false;

    db()->beginTransaction();
    try {
        $stmt = db()->prepare('SELECT id, filename, source_path, row_count, duplicate_count
            FROM import_batches WHERE id = ? AND company_id = ? FOR UPDATE');
        $stmt->execute([$batchId, $companyId]);
        $batch = $stmt->fetch();
        if (!$batch) {
            fail('The imported statement could not be found.', 404, 'import_not_found');
        }
        $filename = (string)$batch['filename'];
        $sourcePath = (string)$batch['source_path'];
        if (!str_starts_with($sourcePath, $companyId . '/statements/')
            || str_contains($sourcePath, '..') || str_contains($sourcePath, "\0")) {
            throw new RuntimeException('The statement source path is invalid.');
        }

        $stmt = db()->prepare('SELECT id, status, journal_entry_id
            FROM bank_transactions WHERE company_id = ? AND import_batch_id = ? FOR UPDATE');
        $stmt->execute([$companyId, $batchId]);
        $transactions = $stmt->fetchAll();
        $transactionCount = count($transactions);
        foreach ($transactions as $transaction) {
            if ((string)$transaction['status'] === 'posted' || $transaction['journal_entry_id'] !== null) {
                fail('This statement contains posted transactions and must remain as accounting evidence. Reverse or correct the posted entries instead.', 409, 'statement_has_posted_transactions');
            }
            if (!in_array((string)$transaction['status'], ['pending', 'excluded', 'duplicate'], true)) {
                fail('This statement contains a transaction state that cannot be safely deleted.', 409, 'statement_delete_blocked');
            }
        }

        $originalSource = private_absolute_path($sourcePath);
        if ($originalSource !== null) {
            $sourceWasPresent = true;
            $quarantinedSource = $originalSource . '.deleting-' . bin2hex(random_bytes(8));
            if (!rename($originalSource, $quarantinedSource)) {
                throw new RuntimeException('The stored statement source could not be prepared for deletion.');
            }
        }

        $deleteTransactions = db()->prepare('DELETE FROM bank_transactions
            WHERE company_id = ? AND import_batch_id = ? AND status IN (\'pending\',\'excluded\',\'duplicate\') AND journal_entry_id IS NULL');
        $deleteTransactions->execute([$companyId, $batchId]);
        if ($deleteTransactions->rowCount() !== $transactionCount) {
            throw new RuntimeException('A statement transaction changed while the statement was being deleted.');
        }

        $deleteBatch = db()->prepare('DELETE FROM import_batches WHERE id = ? AND company_id = ?');
        $deleteBatch->execute([$batchId, $companyId]);
        if ($deleteBatch->rowCount() !== 1) {
            throw new RuntimeException('The statement changed while it was being deleted.');
        }
        audit_event($user, $companyId, 'statement.deleted', 'import_batch', $batchId, [
            'filename' => $filename,
            'transactionCount' => $transactionCount,
            'reportedRowCount' => (int)$batch['row_count'],
            'duplicateCount' => (int)$batch['duplicate_count'],
            'sourceWasPresent' => $sourceWasPresent,
            'postedTransactionsDeleted' => 0,
        ]);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        if ($quarantinedSource !== null && $originalSource !== null && is_file($quarantinedSource)
            && !rename($quarantinedSource, $originalSource)) {
            record_system_incident('A quarantined statement could not be restored after a failed deletion.',500,'statement_quarantine_restore_failed',null,[
                'source'=>'background_error','route'=>'imports/delete','companyId'=>$companyId,'batchId'=>$batchId,'filenameHash'=>hash('sha256',$filename),
            ]);
            error_log('Tegh could not restore a quarantined statement after a failed deletion: ' . $filename);
        }
        throw $error;
    }

    $sourceDeleted = true;
    if ($quarantinedSource !== null && is_file($quarantinedSource) && !unlink($quarantinedSource)) {
        $sourceDeleted = false;
        record_system_incident('Statement records were deleted, but the quarantined source file still requires cleanup.',500,'statement_source_cleanup_failed',null,[
            'source'=>'background_error','route'=>'imports/delete','companyId'=>$companyId,'batchId'=>$batchId,'filenameHash'=>hash('sha256',$filename),
        ]);
        error_log('Tegh deleted the import records but could not erase the quarantined source: ' . $filename);
    }
    json_response([
        'deleted' => true,
        'batchId' => $batchId,
        'transactionCount' => $transactionCount,
        'sourceDeleted' => $sourceDeleted,
    ]);
}
