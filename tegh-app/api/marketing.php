<?php
declare(strict_types=1);

/** Public Tegh marketing-site inquiry and privacy-conscious conversion endpoints. No accounting mutations. */

function marketing_nonce_path(string $nonce): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tegh_marketing_nonce_' . hash('sha256', $nonce) . '.json';
}

function marketing_contact_token(): never
{
    require_method('GET');
    $nonce = base64url_encode(random_bytes(18));
    $issuedAt = time();
    $signature = secret_hash('marketing-contact|' . $nonce . '|' . $issuedAt);
    $path = marketing_nonce_path($nonce);
    $written = @file_put_contents($path, json_encode(['issuedAt' => $issuedAt, 'used' => false], JSON_THROW_ON_ERROR), LOCK_EX);
    if ($written === false) fail('The secure form could not initialize. Please try again.', 503, 'marketing_token_unavailable', false);
    @chmod($path, 0600);
    json_response(['nonce' => $nonce, 'issuedAt' => $issuedAt, 'signature' => $signature]);
}

function marketing_consume_nonce(string $nonce, int $issuedAt): void
{
    $path = marketing_nonce_path($nonce);
    $handle = @fopen($path, 'c+');
    if ($handle === false) fail('The form session is invalid. Reload the page and try again.', 400, 'marketing_token_invalid', false);
    try {
        if (!flock($handle, LOCK_EX)) fail('The form session could not be verified. Please try again.', 503, 'marketing_token_unavailable', false);
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (!is_array($state) || (int)($state['issuedAt'] ?? 0) !== $issuedAt || (bool)($state['used'] ?? true)) {
            fail('This form session has already been used or is invalid. Reload the page and try again.', 409, 'marketing_token_replayed', false);
        }
        $state['used'] = true;
        $state['usedAt'] = time();
        ftruncate($handle, 0); rewind($handle); fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)); fflush($handle);
    } finally {
        @flock($handle, LOCK_UN); @fclose($handle);
    }
}

function marketing_rate_limit_check(): void
{
    $key = client_ip_hash();
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tegh_marketing_' . hash('sha256', $key) . '.json';
    $now = time(); $windowStart = $now - 3600; $attempts = [];
    $handle = @fopen($path, 'c+');
    if ($handle === false) return;
    try {
        if (!flock($handle, LOCK_EX)) return;
        rewind($handle); $raw = stream_get_contents($handle);
        if (is_string($raw) && trim($raw) !== '') { $decoded = json_decode($raw, true); if (is_array($decoded)) $attempts = $decoded; }
        $attempts = array_values(array_filter($attempts, static fn($ts): bool => is_int($ts) && $ts >= $windowStart));
        if (count($attempts) >= 5) fail('Too many requests were submitted from this connection. Please try again later.', 429, 'marketing_rate_limited', false);
        $attempts[] = $now; ftruncate($handle, 0); rewind($handle); fwrite($handle, json_encode($attempts, JSON_THROW_ON_ERROR)); fflush($handle);
    } finally { @flock($handle, LOCK_UN); @fclose($handle); }
}

function marketing_contact_submit(): never
{
    require_method('POST'); assert_same_origin(); $body = request_json();
    if (trim((string)($body['website'] ?? '')) !== '') json_response(['ok' => true, 'accepted' => true], 202);
    $nonce = trim((string)($body['nonce'] ?? '')); $issuedAt = filter_var($body['issuedAt'] ?? null, FILTER_VALIDATE_INT); $signature = trim((string)($body['signature'] ?? ''));
    if ($nonce === '' || $issuedAt === false || $signature === '') fail('The form session is invalid. Reload the page and try again.', 400, 'marketing_token_invalid', false);
    $expected = secret_hash('marketing-contact|' . $nonce . '|' . $issuedAt);
    if (!hash_equals($expected, $signature)) fail('The form session is invalid. Reload the page and try again.', 400, 'marketing_token_invalid', false);
    $age = time() - (int)$issuedAt;
    if ($age < 2) fail('The form was submitted too quickly. Please try again.', 400, 'marketing_submission_too_fast', false);
    if ($age > 3600 || $age < 0) fail('The form session expired. Reload the page and try again.', 400, 'marketing_token_expired', false);
    marketing_rate_limit_check();
    $name = required_text($body['name'] ?? '', 'Name', 120); $email = safe_email($body['email'] ?? '');
    $company = optional_text($body['company'] ?? '', 160) ?? ''; $phone = optional_text($body['phone'] ?? '', 40) ?? '';
    $help = required_text($body['help'] ?? '', 'What can we help with?', 120); $message = required_text($body['message'] ?? '', 'Message', 2000);
    marketing_consume_nonce($nonce, (int)$issuedAt);
    $recipientRaw = trim((string)(config('mail.marketing_to') ?? config('mail.reply_to') ?? config('mail.from_email') ?? ''));
    if ($recipientRaw === '') fail('The contact form is temporarily unavailable. Please try again later.', 503, 'marketing_mail_unconfigured', false);
    try { $recipient = safe_email($recipientRaw); } catch (Throwable) { fail('The contact form is temporarily unavailable. Please try again later.', 503, 'marketing_mail_unconfigured', false); }
    $subject = 'Tegh website inquiry — ' . $help;
    $lines = ['A new inquiry was submitted through the Tegh public website.','','Name: '.$name,'Email: '.$email,'Company: '.($company !== '' ? $company : 'Not provided'),'Phone: '.($phone !== '' ? $phone : 'Not provided'),'Topic: '.$help,'','Message:',$message];
    $text = implode("\n", $lines);
    $html = '<p>A new inquiry was submitted through the Tegh public website.</p><p><strong>Name:</strong> '.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'<br><strong>Email:</strong> '.htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'<br><strong>Company:</strong> '.htmlspecialchars($company !== '' ? $company : 'Not provided', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'<br><strong>Phone:</strong> '.htmlspecialchars($phone !== '' ? $phone : 'Not provided', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'<br><strong>Topic:</strong> '.htmlspecialchars($help, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p><p><strong>Message:</strong><br>'.nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>';
    $delivery = sr_mail_send(null, null, $recipient, 'marketing_inquiry', $subject, $text, $html);
    if (!(bool)($delivery['sent'] ?? false)) fail('Your request could not be delivered right now. Please try again later.', 503, 'marketing_mail_failed', false);
    json_response(['ok' => true, 'accepted' => true], 202);
}

function marketing_event_collect(): never
{
    require_method('POST'); assert_same_origin(); $body = request_json();
    $event = trim((string)($body['event'] ?? ''));
    $allowed = ['join_beta_click','signup_started','signup_completed','request_demo_view','request_demo_started','request_demo_success','ask_tegh_demo_click','pricing_view','migration_view'];
    if (!in_array($event, $allowed, true)) fail('Unknown marketing event.', 400, 'marketing_event_invalid', false);
    // Aggregate counts only: no email, name, user id, raw IP, cookies or conversation data.
    $day = gmdate('Y-m-d');
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tegh_marketing_events_' . $day . '.json';
    $handle = @fopen($path, 'c+');
    if ($handle !== false) {
        try {
            if (flock($handle, LOCK_EX)) {
                rewind($handle); $raw = stream_get_contents($handle); $counts = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
                if (!is_array($counts)) $counts = [];
                $counts[$event] = max(0, (int)($counts[$event] ?? 0)) + 1;
                ftruncate($handle, 0); rewind($handle); fwrite($handle, json_encode($counts, JSON_THROW_ON_ERROR)); fflush($handle);
            }
        } finally { @flock($handle, LOCK_UN); @fclose($handle); }
    }
    json_response(['ok' => true], 202);
}

function handle_marketing(string $subroute): never
{
    $subroute = trim($subroute, '/');
    if ($subroute === 'contact-token') marketing_contact_token();
    if ($subroute === 'contact') marketing_contact_submit();
    if ($subroute === 'event') marketing_event_collect();
    fail('Marketing route not found.', 404, 'route_not_found', false);
}
