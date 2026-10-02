<?php
declare(strict_types=1);
require __DIR__ . '/member/bootstrap.php';
$user = sr_current_user();
$verified = $user && !empty($user['email_verified_at']);
$usage = $user ? sr_usage_summary((int)$user['id']) : null;
$turnstile = sr_turnstile_config();
?>
<!doctype html>
<html lang="en-CA">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Convert one searchable PDF bank statement into an editable CSV directly in your browser. Upgrade for OCR, batch processing, Excel, PDF, QIF and OFX.">
  <meta name="theme-color" content="#0b2a3a">
  <meta name="robots" content="index, follow">
  <link rel="canonical" href="https://bankstatementconverter.sraccountax.ca/free-converter.php">
  <link rel="icon" href="favicon.svg" type="image/svg+xml">
  <meta property="og:type" content="website">
  <meta property="og:title" content="Free PDF Bank Statement Converter | SR AccounTax">
  <meta property="og:description" content="Extract, review and export bank-statement transactions without uploading the statement to SR AccounTax.">
  <meta property="og:url" content="https://bankstatementconverter.sraccountax.ca/free-converter.php">
  <meta property="og:image" content="https://bankstatementconverter.sraccountax.ca/social-preview.jpg">
  <meta name="twitter:card" content="summary_large_image">
  <title>Free PDF Bank Statement Converter to CSV | SR AccounTax</title>
<link rel="stylesheet" href="styles.css?v=20260908-1">
  <link rel="stylesheet" href="bank-converter.css?v=20261001-10">
  <script type="application/ld+json">{"@context": "https://schema.org", "@type": "WebApplication", "name": "SR AccounTax Free PDF Bank Statement Converter", "url": "https://bankstatementconverter.sraccountax.ca/free-converter.php", "applicationCategory": "FinanceApplication", "operatingSystem": "Any modern web browser", "offers": {"@type": "Offer", "price": "0", "priceCurrency": "CAD"}, "featureList": ["One searchable PDF per conversion", "Up to 25 pages", "Editable review table", "CSV export for up to 25 transactions", "Clipboard-ready CSV", "Local browser processing"]}</script>
  <script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Converter overview","item":"https://bankstatementconverter.sraccountax.ca/"},{"@type":"ListItem","position":2,"name":"Free converter","item":"https://bankstatementconverter.sraccountax.ca/free-converter.php"}]}</script>
<link rel="stylesheet" href="utility.css?v=20260908-1"><?php if (sr_turnstile_enabled()): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
</head>
<body>
<?php sr_public_header('convert', $user); ?>

<?php if ($user): ?>
<div class="account-context-bar"><div class="container">
<span><strong><?= sr_e((string)$user['name']) ?></strong> · <?= $verified ? 'Verified account' : 'Email verification required' ?></span>
<a href="member/dashboard.php">Account and usage <span aria-hidden="true">→</span></a>
</div></div>
<?php endif; ?>


<main id="main">
<section class="section compact-access-section"><div class="container">
<?php if (!$user): ?><div class="access-gate"><div><p class="eyebrow">Verified account required</p><h2>Demo access is available now</h2><p>To process a real bank statement, create a free account and verify the email address. This prevents repeated incognito and multi-browser use while keeping the PDF in your browser.</p></div><div class="utility-actions"><a class="button primary" href="member/register.php">Create free account</a><a class="button secondary" href="member/login.php">Sign in</a></div></div>
<?php elseif (!$verified): ?><div class="access-gate"><div><p class="eyebrow">Email verification required</p><h2>Verify your account before converting</h2><p>Open the verification email or resend it from My Account. Demo data remains available.</p></div><a class="button primary" href="member/dashboard.php">Open My Account</a></div>
<?php else: ?><div class="usage-strip"><strong>Free allowance:</strong> <?= (int)$usage['free_remaining'] ?> of <?= (int)$usage['free_limit'] ?> conversion remaining in the current 30-day window. Free output is limited to <?= sr_usage_setting('free_max_transactions',25) ?> transactions.</div><?php endif; ?>
</div></section>
  <section class="converter-hero">
    <div class="container converter-hero-grid">
      <div>
        <p class="eyebrow">Free private browser utility</p>
        <h1>Free PDF Bank Statement Converter</h1>
        <p class="converter-lead">Convert one searchable bank statement into a reviewable CSV without uploading the PDF to SR AccounTax.</p>
        <div class="format-pills" aria-label="Free output formats"><span>CSV</span><span>Copy CSV</span><span>Editable review</span></div>
      </div>
      <aside class="privacy-panel">
        <span class="privacy-icon" aria-hidden="true">⌂</span>
        <div><strong>Your statement stays in your browser</strong><p>The PDF is not uploaded to SR AccounTax. Text extraction, review and CSV export occur on your device.</p></div>
      </aside>
    </div>
  </section>

  <section class="section converter-section">
    <div class="container converter-shell">
      <section class="converter-card upload-card" aria-labelledby="upload-heading">
        <div class="converter-card-heading">
          <div><span class="step-number">1</span><h2 id="upload-heading">Choose bank statement PDFs</h2></div>
          <button id="demo-button" class="control-link" type="button">Load demo data</button>
        </div>
        <div id="statement-drop-zone" class="statement-drop-zone" tabindex="0" role="button" aria-label="Choose or drop PDF bank statements">
          <input id="statement-files" type="file" accept="application/pdf,.pdf" <?= $verified ? '' : 'disabled' ?> hidden>
          <span class="drop-icon" aria-hidden="true">PDF</span>
          <strong>Drop PDF statements here</strong>
          <p>or click to choose one searchable PDF, maximum 10 MB and 25 pages</p>
          <button class="button primary" type="button" onclick="document.getElementById('statement-files').click();event.stopPropagation();">Choose PDF files</button>
        </div>
        <ul id="selected-files" class="selected-files" hidden></ul>
        <div class="privacy-detail">
          <strong>A verified free account is required for real statements; demo data does not require sign-in.</strong> Never enter online-banking passwords. Password-protected PDF statements can be unlocked locally when the browser asks for the PDF password.
        </div>
      </section>

      <section class="converter-card converter-settings" aria-labelledby="settings-heading">
        <div class="converter-card-heading"><div><span class="step-number">2</span><h2 id="settings-heading">Extraction settings</h2></div></div>
        <div class="settings-grid">
          <label>Statement year
            <input id="statement-year" type="number" min="2000" max="2100" inputmode="numeric">
            <small>Used when rows show only month and day.</small>
          </label>
          <label>Date order
            <select id="date-order">
              <option value="auto">Auto detect</option>
              <option value="mdy">Month / day / year</option>
              <option value="dmy">Day / month / year</option>
            </select>
          </label>
          <label>Transaction columns
            <select id="column-layout">
              <option value="auto">Auto detect</option>
              <option value="amount">Date + description + signed amount</option>
              <option value="amount-balance">Amount + running balance</option>
              <option value="debit-credit">Separate debit and credit</option>
              <option value="debit-credit-balance">Debit + credit + balance</option>
            </select>
          </label>
          <label>Account type
            <select id="account-type">
              <option value="">Auto detect</option>
              <option value="bank">Bank account (chequing / savings)</option>
              <option value="credit_card">Credit card or line of credit</option>
            </select>
            <small>Credit card statements: charges are money out, payments money in.</small>
          </label>
          <label>Decimal format
            <select id="decimal-separator">
              <option value="auto">Auto detect</option>
              <option value="dot">1,234.56</option>
              <option value="comma">1.234,56</option>
            </select>
          </label>
          <label>Currency
            <select id="statement-currency">
              <option value="CAD">CAD — Canadian dollar</option>
              <option value="USD">USD — US dollar</option>
              <option value="EUR">EUR — Euro</option>
              <option value="GBP">GBP — British pound</option>
            </select>
          </label>
          
        </div>
        <?php if ($verified && !empty($turnstile['require_for_free_conversion'])): ?>
<div class="turnstile-wrap"><strong>Human verification</strong><?php if (sr_turnstile_enabled()): ?><div class="cf-turnstile" data-sitekey="<?= sr_e((string)$turnstile['site_key']) ?>" data-action="free_conversion"></div><?php else: ?><p class="converter-status warning">Cloudflare Turnstile is not configured yet. Free conversion will use the account, statement, network and device limits instead.</p><?php endif; ?></div>
<?php endif; ?>
<div class="converter-actions">
          <button id="extract-button" class="button primary" type="button">Extract and review transactions</button>
          <button id="reparse-button" class="button secondary" type="button">Re-run parser with these settings</button>
          <button id="cancel-button" class="button secondary" type="button" hidden>Cancel</button>
          <button id="reset-button" class="text-button" type="button">Clear all data</button>
        </div>
        <div id="conversion-progress-wrap" class="conversion-progress-wrap" hidden>
          <progress id="conversion-progress" max="100" value="0"></progress>
          <span id="conversion-progress-text">Preparing…</span>
        </div>
        <p id="converter-status" class="converter-status" role="status" aria-live="polite" hidden></p>
      </section>
    </div>
  </section>

  <section id="review-section" class="section review-section" hidden>
    <div class="container">
      <div class="review-heading">
        <div><p class="eyebrow">Review before export</p><h2>Correct and approve the extracted transactions</h2><p>Automated extraction is not guaranteed. Compare dates, descriptions, debits, credits and balances with the original statement.</p></div>
        <div class="review-actions">
          <button class="button secondary compact-button" type="button" data-action="add-row">Add row</button>
          <button class="button secondary compact-button" type="button" data-action="exclude-duplicates">Exclude duplicates</button>
        </div>
      </div>

      <div class="summary-grid">
        <article><span>Included rows</span><strong id="included-count">0</strong></article>
        <article><span>Total debits</span><strong id="total-debits">$0.00</strong></article>
        <article><span>Total credits</span><strong id="total-credits">$0.00</strong></article>
        <article><span>Net movement</span><strong id="net-movement">$0.00</strong></article>
        <article><span>Last balance</span><strong id="closing-balance">Not detected</strong></article>
      </div>

      <div class="quality-panel">
        <strong>Quality checks</strong>
        <ul id="quality-warnings"></ul>
      </div>

      <div class="table-toolbar">
        <label class="search-field">Search transactions<input id="transaction-search" type="search" placeholder="Description, category, date or file…"></label>
        <label>Review filter<select id="confidence-filter"><option value="all">All rows</option><option value="review">Needs review</option><option value="low">Low confidence only</option></select></label>
        <label>Source file<select id="source-filter"><option value="all">All files</option></select></label>
        <div class="sort-buttons"><button type="button" data-action="sort-oldest">Oldest first</button><button type="button" data-action="sort-newest">Newest first</button></div>
      </div>

      <div class="transaction-table-wrap">
        <table class="transaction-table">
          <thead><tr>
            <th><input id="select-visible" type="checkbox" aria-label="Include all visible rows"></th>
            <th>Date</th><th>Posted</th><th>Description</th><th>Debit</th><th>Credit</th><th>Balance</th><th>Category</th><th>Reference</th><th>Confidence</th><th>Source</th><th></th>
          </tr></thead>
          <tbody id="transactions-body"></tbody>
        </table>
      </div>
      <div class="pagination"><button id="prev-page" type="button">Previous</button><span id="page-info">Page 1</span><button id="next-page" type="button">Next</button></div>

      <section class="export-card" aria-labelledby="export-heading">
        <div><span class="step-number">3</span><h2 id="export-heading">Export reviewed transactions</h2><p>Only checked rows are included. Text fields are protected against spreadsheet-formula injection.</p></div>
        <div class="export-grid">
          <button type="button" data-export="csv"><strong>CSV</strong><span>Exports the authorized free transaction sample</span></button>
          <button type="button" data-export="clipboard"><strong>Copy CSV</strong><span>Copy the authorized free transaction sample</span></button>
        </div>
      </section>
    </div>
  </section>

  <section class="section limitations-section">
    <div class="container limitations-grid">
      <div><p class="eyebrow">Designed for safe review</p><h2>What this utility does well</h2><ul class="check-list"><li>Processes one searchable PDF at a time</li><li>Recognizes common Canadian date and amount formats</li><li>Supports separate debit, credit and running-balance layouts</li><li>Flags uncertain dates, inferred directions and possible duplicates</li><li>Lets you edit every field before export</li><li>Processes up to 25 pages and exports up to 25 transactions</li></ul></div>
      <div class="limitation-card"><h3>Important limitations</h3><ul><li>Bank layouts differ and can change without notice.</li><li>Scans, handwriting, complex tables and protected PDFs may reduce accuracy.</li><li>Scanned-image PDFs require Bank Statement Converter Pro OCR.</li><li>OFX, QIF and QuickBooks imports depend on the receiving software and its mapping requirements.</li><li>The PDF output is a transaction report, not a replacement for the original bank statement.</li><li>Keep the original statement and reconcile all totals before bookkeeping, tax, lending, legal or audit use.</li></ul></div>
    </div>
  </section>


  <section class="section soft-section">
    <div class="container converter-cta">
      <div>
        <p class="eyebrow">Need complete conversion?</p>
        <h2>Upgrade to Bank Statement Converter Pro</h2>
        <p>Process up to 12 PDFs and 150 pages, use English OCR, and export complete files to Excel, PDF, QuickBooks CSV, JSON, OFX, QIF and TSV.</p>
      </div>
      <a class="button primary" href="membership.php">View Pro membership</a>
    </div>
  </section>

  <section class="section soft-section">
    <div class="container converter-cta"><div><p class="eyebrow">Need clean books?</p><h2>Statement conversion is only the first step</h2><p>SR AccounTax can assist with bookkeeping cleanup, reconciliations and financial-record organization after the scope and secure document process are confirmed.</p></div><a class="button primary" href="https://sraccountax.ca/#contact">Book a free 15-minute consultation</a></div>
  </section>
</main>

<footer>
  <div class="container footer-grid">
    <div class="brand footer-brand"><span class="brand-mark" aria-hidden="true">SR</span><span class="brand-text"><strong>SR AccounTax</strong><small>Accounting • Tax • Advisory</small></span></div>
    <p>© <span id="year">2026</span> SR AccounTax. All rights reserved.</p>
    <div class="footer-links"><a href="https://sraccountax.ca/free-tools.html">Tax Calculators</a><a href="privacy.html">Privacy</a><a href="document-security.html">Document Safety</a><a href="bank-converter-notices.html">Technology notices</a><a href="https://sraccountax.ca/#contact">Contact</a></div>
  </div>
  <div class="container disclaimer">This utility provides automated data extraction for convenience. Results must be reviewed against the original statement and are not accounting, tax, legal, lending or audit advice.</div>
</footer>
<script>
window.BSC_ACCESS_CONFIG = <?= json_encode([
  'endpoint' => sr_url('member/usage-api.php'),
  'csrf' => sr_csrf_token(),
  'requestedMode' => 'free',
  'loggedIn' => (bool)$user,
  'verified' => (bool)$verified,
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="script.js?v=20260805-1"></script>
<script src="bank-converter-core.js?v=20261001-10"></script>
<script src="bank-statement-layout.js?v=20261001-10"></script>
<script type="module" src="bank-statement-converter-free.js?v=20261001-10"></script>
</body>
</html>
