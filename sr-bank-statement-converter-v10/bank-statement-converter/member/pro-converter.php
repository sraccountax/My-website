<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$user = sr_require_full_converter();
$isOwner = sr_is_owner((int)$user['id']);
$usage = sr_usage_summary((int)$user['id']);
?>
<!doctype html>
<html lang="en-CA">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Convert searchable or scanned PDF bank statements into editable Excel, CSV, PDF, JSON, QIF, OFX and accounting-ready transaction files directly in your browser.">
  <meta name="theme-color" content="#0b2a3a">
  <meta name="robots" content="noindex, nofollow">
  <link rel="icon" href="../favicon.svg" type="image/svg+xml">
  <meta property="og:type" content="website">
  <meta property="og:title" content="PDF Bank Statement Converter | SR AccounTax">
  <meta property="og:description" content="Extract, review and export bank-statement transactions without uploading the statement to SR AccounTax.">
  <meta property="og:url" content="https://bankstatementconverter.sraccountax.ca/member/pro-converter.php">
  <meta property="og:image" content="https://bankstatementconverter.sraccountax.ca/social-preview.jpg">
  <meta name="twitter:card" content="summary_large_image">
  <title>Pro Converter | SR AccounTax Bank Statement Converter</title>
<link rel="stylesheet" href="../styles.css?v=20260908-1">
  <link rel="stylesheet" href="../bank-converter.css?v=20261001-10">
  <link rel="stylesheet" href="../utility.css?v=20260908-1">
  <link rel="stylesheet" href="member.css?v=20260908-1">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="member-header" id="top">
  <div class="member-wrap member-header-row">
    <a class="member-brand" href="../" aria-label="Bank Statement Converter home">
      <span class="brand-mark" aria-hidden="true">SR</span>
      <span><strong>Bank Statement Converter</strong><small>Account centre</small></span>
    </a>
    <button class="menu-toggle" type="button" aria-label="Open account navigation" aria-expanded="false" aria-controls="member-nav"><span></span><span></span><span></span></button>
    <nav class="nav member-nav" id="member-nav" aria-label="Account navigation">
      <a href="dashboard.php">Dashboard</a>
      <a href="pro-converter.php" aria-current="page">Converter</a>
      <a href="../membership.php">Plans</a>
      <a href="profile.php">Profile</a>
      <form class="nav-logout-form" method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= sr_e(sr_csrf_token()) ?>"><button class="member-signout nav-logout-button" type="submit">Sign out</button></form>
    </nav>
  </div>
</header>

<main id="main">
<section class="section compact-access-section"><div class="container"><div class="usage-strip">
<?php if ($isOwner): ?><strong>Owner access:</strong> Unlimited full conversions. Plan allowances and credits are not consumed.
<?php elseif (sr_is_pro_active((int)$user['id']) && !empty($usage['period'])): ?>
<strong>Plan usage:</strong> <?= (int)$usage['period']['statements_used'] ?>/<?= (int)$usage['period']['statement_limit'] ?> statements and <?= (int)$usage['period']['pages_used'] ?>/<?= (int)$usage['period']['page_limit'] ?> pages.
<?php elseif ((int)$usage['credits'] > 0): ?><strong>Conversion credits:</strong> <?= (int)$usage['credits'] ?> remaining. One credit is used for each statement file.
<?php endif; ?>
<?php if (!empty($usage['exhausted'])): ?> <a href="reset-plan.php">Reset allowance and renew now</a><?php endif; ?>
</div></div></section>
  <section class="converter-hero">
    <div class="container converter-hero-grid">
      <div>
        <p class="eyebrow">Bank Statement Converter Pro</p>
        <h1>Pro PDF Bank Statement Converter</h1>
        <p class="converter-lead">Extract bank transactions, correct them in an editable review table and export clean files for Excel, bookkeeping or recordkeeping.</p>
        <div class="format-pills" aria-label="Supported output formats">
          <span>XLSX</span><span>CSV</span><span>PDF report</span><span>JSON</span><span>QIF</span><span>OFX</span><span>QuickBooks CSV</span><span>TSV</span>
        </div>
      </div>
      <aside class="privacy-panel">
        <span class="privacy-icon" aria-hidden="true">⌂</span>
        <div><strong>Your statement stays in your browser</strong><p>The PDF is not uploaded to SR AccounTax. Text extraction, optional OCR, review and export occur on your device.</p></div>
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
          <input id="statement-files" type="file" accept="application/pdf,.pdf" multiple hidden>
          <span class="drop-icon" aria-hidden="true">PDF</span>
          <strong>Drop PDF statements here</strong>
          <p>or click to choose up to 12 files, maximum 25 MB each</p>
          <button class="button primary" type="button" onclick="document.getElementById('statement-files').click();event.stopPropagation();">Choose PDF files</button>
        </div>
        <ul id="selected-files" class="selected-files" hidden></ul>
        <div class="privacy-detail">
          <strong><?= $isOwner ? 'Your owner unlimited access is active.' : 'Your Pro membership is active.' ?></strong> Never enter online-banking passwords. Password-protected PDF statements can be unlocked locally when the browser asks for the PDF password.
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
          <label>Bank or institution ID <span class="optional">optional, OFX only</span>
            <input id="bank-id" type="text" maxlength="20" placeholder="Example: 003">
          </label>
          <label>Account identifier <span class="optional">optional, OFX only</span>
            <input id="account-id" type="text" maxlength="34" autocomplete="off" placeholder="Prefer last four digits only">
          </label>
          <label class="checkbox-setting">
            <input id="use-ocr" type="checkbox">
            <span><strong>Use English OCR when a page has no readable text</strong><small>Beta and slower. Self-hosted OCR engine files load only when needed; statement images remain in your browser.</small></span>
          </label>
        </div>
        <div class="converter-actions">
          <button id="extract-button" class="button primary" type="button">Extract and review transactions</button>
          <button id="reparse-button" class="button secondary" type="button">Re-run parser</button>
          <button id="cancel-button" class="button secondary" type="button" hidden>Cancel</button>
          <button id="reset-button" class="text-button" type="button">Clear all data</button>
        </div>
        <details class="preference-menu">
          <summary>Saved extraction preferences</summary>
          <div class="preference-actions">
            <button id="save-settings" class="button secondary compact-button" type="button">Save current settings</button>
            <button id="load-settings" class="button secondary compact-button" type="button">Load saved settings</button>
          </div>
        </details>
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
        <p class="export-group-title">Recommended formats</p>
        <div class="export-grid">
          <button type="button" data-export="xlsx"><strong>Excel workbook</strong><span>.xlsx with transactions, summary and extraction log</span></button>
          <button type="button" data-export="csv"><strong>Full CSV</strong><span>Detailed accounting transaction columns</span></button>
          <button type="button" data-export="quickbooks-csv"><strong>QuickBooks CSV</strong><span>Date, description and signed amount for mapping</span></button>
          <button type="button" data-export="pdf"><strong>PDF report</strong><span>Normalized, printable transaction table</span></button>
        </div>
        <details class="more-export-menu">
          <summary>More export formats</summary>
          <div class="export-grid">
            <button type="button" data-export="qbo"><strong>QuickBooks Web Connect (.qbo)</strong><span>Bank or credit card file for QuickBooks Online and Desktop; check the account after import</span></button><button type="button" data-export="ofx"><strong>OFX</strong><span>Generic bank import file; software compatibility varies</span></button>
            <button type="button" data-export="qif"><strong>QIF</strong><span>Legacy accounting and personal-finance import</span></button>
            <button type="button" data-export="json"><strong>JSON</strong><span>Structured data and extraction metadata</span></button>
            <button type="button" data-export="tsv"><strong>TSV</strong><span>Tab-separated file for spreadsheet applications</span></button>
            <button type="button" data-export="text"><strong>Extracted text</strong><span>Page-by-page text for troubleshooting</span></button>
            <button type="button" data-export="clipboard"><strong>Copy CSV</strong><span>Copy reviewed data to the clipboard</span></button>
          </div>
        </details>
      </section>
    </div>
  </section>

  <section class="section limitations-section">
    <div class="container limitations-grid">
      <div><p class="eyebrow">Designed for safe review</p><h2>What this utility does well</h2><ul class="check-list"><li>Combines multiple statement PDFs</li><li>Recognizes common Canadian date and amount formats</li><li>Supports separate debit, credit and running-balance layouts</li><li>Flags uncertain dates, inferred directions and possible duplicates</li><li>Lets you edit every field before export</li><li>Processes up to 150 pages in one browser session</li></ul></div>
      <div class="limitation-card"><h3>Important limitations</h3><ul><li>Bank layouts differ and can change without notice.</li><li>Scans, handwriting, complex tables and protected PDFs may reduce accuracy.</li><li>OCR is English-only beta and should be treated as a draft.</li><li>OFX, QIF and QuickBooks imports depend on the receiving software and its mapping requirements.</li><li>The PDF output is a transaction report, not a replacement for the original bank statement.</li><li>Keep the original statement and reconcile all totals before bookkeeping, tax, lending, legal or audit use.</li></ul></div>
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
    <div class="footer-links"><a href="https://sraccountax.ca/free-tools.html">Tax Calculators</a><a href="../privacy.html">Privacy</a><a href="../document-security.html">Document Safety</a><a href="../bank-converter-notices.html">Technology notices</a><a href="https://sraccountax.ca/#contact">Contact</a></div>
  </div>
  <div class="container disclaimer">This utility provides automated data extraction for convenience. Results must be reviewed against the original statement and are not accounting, tax, legal, lending or audit advice.</div>
</footer>
<script>
window.BSC_ACCESS_CONFIG = <?= json_encode([
  'endpoint' => sr_url('member/usage-api.php'),
  'csrf' => sr_csrf_token(),
  'requestedMode' => 'full',
  'loggedIn' => true,
  'verified' => true,
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="../script.js?v=20260805-1"></script>
<script src="../bank-converter-core.js?v=20261001-10"></script>
<script src="../bank-statement-layout.js?v=20261001-10"></script>
<script type="module" src="asset.php?name=pro-converter.js&amp;v=20261001-10"></script>
</body>
</html>
