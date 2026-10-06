import { sha256Hex, authorizeConversion, completeAuthorization } from '/converter-access.js?v=20260908-1';
const Core = globalThis.BankStatementCore;
if (!Core) throw new Error('Bank statement parser failed to load.');

const state = {
  files: [],
  extractedDocuments: [],
  rows: [],
  filteredIndices: [],
  page: 1,
  pageSize: 100,
  sortDirection: 'source',
  cancelled: false,
  busy: false,
  pdfjs: null,
  ocrWorker: null,
  settingsUsed: null,
  accessRowLimit: null,
  checks: [],
};

const CONVERTER_BUILD = '11.0.0';
const MAX_FILES = 1;
const MAX_FILE_SIZE = 10 * 1024 * 1024;
const MAX_TOTAL_PAGES = 25;
const MAX_OCR_PAGES = 0;
const ACCESS = globalThis.BSC_ACCESS_CONFIG || {};

const $ = id => document.getElementById(id);
const els = {
  fileInput: $('statement-files'),
  dropZone: $('statement-drop-zone'),
  fileList: $('selected-files'),
  extractButton: $('extract-button'),
  cancelButton: $('cancel-button'),
  resetButton: $('reset-button'),
  reparseButton: $('reparse-button'),
  demoButton: $('demo-button'),
  progressWrap: $('conversion-progress-wrap'),
  progress: $('conversion-progress'),
  progressText: $('conversion-progress-text'),
  status: $('converter-status'),
  reviewSection: $('review-section'),
  tableBody: $('transactions-body'),
  tableHeadSelect: $('select-visible'),
  search: $('transaction-search'),
  confidenceFilter: $('confidence-filter'),
  sourceFilter: $('source-filter'),
  pageInfo: $('page-info'),
  prevPage: $('prev-page'),
  nextPage: $('next-page'),
  includedCount: $('included-count'),
  totalDebits: $('total-debits'),
  totalCredits: $('total-credits'),
  netMovement: $('net-movement'),
  closingBalance: $('closing-balance'),
  warnings: $('quality-warnings'),
  exportButtons: document.querySelectorAll('[data-export]'),
};

// ---- v10/v11: column-aware reader, statement checks and statement chain ----
const Layout = globalThis.BankStatementLayout || null;
function parseDocument(documentData, settings) {
  const core = Core.parseStatement(documentData.lines, settings);
  const read = Layout && settings.layout === 'auto'
    ? Layout.convert(documentData.lines, {accountType: settings.accountType, fallbackYear: settings.year, dateOrder: settings.dateOrder, categorize: Core.categorize})
    : null;
  if (read) {
    const coreGood = core.rows.filter(row => row.date && row.confidence >= 65).length;
    // Use the column read when the statement's own balances confirm it, or when it finds at least as many good rows.
    if (read.check.balanced || read.rows.length >= coreGood) return {rows: read.rows, warnings: [], check: read.check, reader: 'column'};
  }
  return {rows: core.rows, warnings: core.warnings, check: null, reader: 'general'};
}
// v11: a PDF that holds several statements (more months, or more accounts) is read one statement at a time, so each
// statement gets its own check and consecutive statements of one account are chained.
function statementsIn(documentData, settings) {
  const text = (documentData.rawPageText || []).join('\n');
  const accountOf = () => (Layout && Layout.statementAccountIds ? Layout.statementAccountIds(text) : [])[0] || '';
  if (!Layout || !Layout.splitStatements || settings.layout !== 'auto') return [{ ...documentData, account: accountOf() }];
  const sections = Layout.splitStatements(documentData.lines);
  if (sections.length < 2) return [{ ...documentData, account: sections[0] && sections[0].account || accountOf() }];
  return sections.map((section, index) => {
    const account = section.account ? `, account …${section.account}` : '';
    return { ...documentData, lines: section.lines, account: section.account || '', fileName: `${documentData.fileName} (statement ${index + 1} of ${sections.length}${account})` };
  });
}
function statementCheckBox() {
  let box = document.getElementById('statement-check');
  if (!box) {
    const grid = document.querySelector('#review-section .summary-grid') || document.querySelector('.summary-grid');
    box = document.createElement('section');
    box.id = 'statement-check';
    box.className = 'statement-check';
    box.setAttribute('aria-live', 'polite');
    if (grid) grid.parentNode.insertBefore(box, grid); else els.reviewSection.prepend(box);
  }
  return box;
}
function renderStatementChecks(note = '') {
  const box = statementCheckBox();
  const checks = state.checks || [];
  if (!checks.length || !Layout) { box.hidden = true; return; }
  const items = checks.map(item => {
    const result = Layout.checkText(item.check);
    const period = item.check && item.check.period && item.check.period.end ? ` · ${item.check.period.start || '…'} to ${item.check.period.end}` : '';
    return `<li data-state="${result.state}"><strong>${escapeHtml(item.fileName)}${escapeHtml(period)}</strong><span>${escapeHtml(result.text)}</span></li>`;
  });
  const chain = Layout.chainCheck(checks).map(link => `<li data-state="${link.ok ? 'ok' : 'warn'}"><strong>${escapeHtml(link.from)} → ${escapeHtml(link.to)}</strong><span>${link.ok ? `Closing balance carries forward as the next opening balance (${escapeHtml(formatMoney(link.closing))}) ✓` : `Closing balance ${escapeHtml(formatMoney(link.closing))} does not match the next opening balance ${escapeHtml(formatMoney(link.opening))}. A statement may be missing between them.`}</span></li>`);
  const allOk = checks.every(item => Layout.checkText(item.check).state === 'ok') && Layout.chainCheck(checks).every(link => link.ok);
  box.hidden = false;
  box.dataset.state = allOk ? 'ok' : 'warn';
  box.innerHTML = `<h3>${allOk ? 'Statement check passed' : 'Statement check'}</h3><ul>${items.join('')}${chain.join('')}</ul>${note ? `<p>${escapeHtml(note)}</p>` : ''}`;
}
function statementCheckRows() {
  return (state.checks || []).map(item => {
    const c = item.check || {};
    const result = Layout ? Layout.checkText(item.check) : {state: 'info', text: ''};
    return {File: safeSpreadsheetText(item.fileName), Reader: item.reader === 'column' ? 'Column layout' : 'General', 'Period start': c.period ? c.period.start || '' : '', 'Period end': c.period ? c.period.end || '' : '',
      'Opening balance': c.openingBalance ?? '', 'Closing balance': c.closingBalance ?? '', 'Rows net': c.netCents == null ? '' : c.netCents / 100,
      Balanced: c.reconciled === true ? 'Yes' : c.reconciled === false ? 'No' : 'Not printed', 'Running balances checked': c.balanceChecks || 0, 'Running balances differing': c.balanceMismatches || 0,
      'Page totals': c.pageChecks ? `${c.pageChecks.filter(p => p.ok).length}/${c.pageChecks.length}` : '', Result: safeSpreadsheetText(result.text)};
  });
}

function getSettings() {
  return {
    year: Number($('statement-year').value) || new Date().getFullYear(),
    dateOrder: $('date-order').value,
    layout: $('column-layout').value,
    accountType: ($('account-type') && $('account-type').value) || '',
    decimalSeparator: $('decimal-separator').value,
    useOcr: false,
    currency: $('statement-currency').value,
    bankId: '',
    accountId: '',
  };
}

function setStatus(message, type = 'info') {
  els.status.textContent = message;
  els.status.className = `converter-status ${type}`;
  els.status.hidden = !message;
}

function setProgress(percent, message) {
  els.progressWrap.hidden = false;
  els.progress.value = Math.max(0, Math.min(100, percent));
  els.progressText.textContent = message;
}

function setBusy(busy) {
  state.busy = busy;
  els.extractButton.disabled = busy || !state.files.length;
  els.cancelButton.hidden = !busy;
  els.fileInput.disabled = busy || !ACCESS.loggedIn || !ACCESS.verified;
  document.querySelectorAll('.converter-settings input, .converter-settings select').forEach(control => { control.disabled = busy; });
}

function requestPdfPassword(message) {
  return new Promise(resolve => {
    const dialog = document.createElement('dialog');
    dialog.className = 'pdf-password-dialog';
    dialog.innerHTML = `<form method="dialog" class="pdf-password-form">
      <h2>Unlock PDF locally</h2>
      <p>${escapeHtml(message)}</p>
      <label>PDF password<input type="password" name="password" autocomplete="off" spellcheck="false" required></label>
      <p class="privacy-detail">The password is used only in this browser tab and is not sent to SR AccounTax.</p>
      <div class="converter-actions"><button class="button primary" value="unlock">Unlock PDF</button><button class="button secondary" value="cancel" formnovalidate>Cancel</button></div>
    </form>`;
    document.body.appendChild(dialog);
    const input = dialog.querySelector('input[name="password"]');
    dialog.addEventListener('close', () => {
      const password = dialog.returnValue === 'unlock' ? input.value : null;
      input.value = '';
      dialog.remove();
      resolve(password);
    }, { once: true });
    dialog.addEventListener('cancel', event => {
      event.preventDefault();
      dialog.close('cancel');
    });
    dialog.showModal();
    input.focus();
  });
}

function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
}

function addFiles(fileList) {
  const errors = [];
  for (const file of [...fileList]) {
    if (state.files.length >= MAX_FILES) { errors.push(`Maximum ${MAX_FILES} files.`); break; }
    if (file.type !== 'application/pdf' && !/\.pdf$/i.test(file.name)) { errors.push(`${file.name}: PDF files only.`); continue; }
    if (file.size > MAX_FILE_SIZE) { errors.push(`${file.name}: exceeds the 10 MB free-plan limit.`); continue; }
    const duplicate = state.files.some(existing => existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified);
    if (!duplicate) state.files.push(file);
  }
  renderFileList();
  els.extractButton.disabled = !state.files.length;
  if (errors.length) setStatus(errors.join(' '), 'warning');
  else if (state.files.length) setStatus(`${state.files.length} PDF file${state.files.length === 1 ? '' : 's'} ready.`, 'success');
}

function renderFileList() {
  els.fileList.innerHTML = '';
  state.files.forEach((file, index) => {
    const item = document.createElement('li');
    item.innerHTML = `<span><strong>${escapeHtml(file.name)}</strong><small>${formatBytes(file.size)}</small></span><button type="button" data-remove-file="${index}" aria-label="Remove ${escapeHtml(file.name)}">Remove</button>`;
    els.fileList.appendChild(item);
  });
  els.fileList.hidden = !state.files.length;
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
}

function loadScript(src, test) {
  if (test && test()) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const existing = document.querySelector(`script[data-loader-src="${CSS.escape(src)}"]`);
    if (existing) {
      existing.addEventListener('load', resolve, { once: true });
      existing.addEventListener('error', reject, { once: true });
      return;
    }
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.dataset.loaderSrc = src;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error(`Could not load ${src}`));
    document.head.appendChild(script);
  });
}

async function getPdfJs() {
  if (state.pdfjs) return state.pdfjs;
  await import('/pdf-upsert-polyfill.js?v=20261001-10');
  const pdfjs = await import('/vendor/pdfjs/pdf.min.mjs');
  pdfjs.GlobalWorkerOptions.workerSrc = '/pdf-worker-entry.mjs?v=20261001-10';
  state.pdfjs = pdfjs;
  return pdfjs;
}

async function getOcrWorker(progressCallback) {
  if (state.ocrWorker) return state.ocrWorker;
  await loadScript('/vendor/tesseract/tesseract.min.js', () => Boolean(globalThis.Tesseract));
  state.ocrWorker = await globalThis.Tesseract.createWorker('eng', 1, {
    workerPath: '/vendor/tesseract/worker.min.js',
    corePath: '/vendor/tesseract-core',
    langPath: '/vendor/tessdata/',
    logger: data => {
      if (data && data.status && typeof data.progress === 'number') progressCallback(data.status, data.progress);
    },
  });
  return state.ocrWorker;
}

async function extractPageWithOcr(page, pageNumber, fileName, overallProgress) {
  const scale = 2.5;
  const viewport = page.getViewport({ scale });
  const canvas = document.createElement('canvas');
  const context = canvas.getContext('2d', { alpha: false, willReadFrequently: true });
  canvas.width = Math.ceil(viewport.width);
  canvas.height = Math.ceil(viewport.height);
  await page.render({ canvasContext: context, viewport }).promise;
  // v10: grey and contrast-stretch the page (1st–99th percentile) so faint scans read better.
  try {
    const image = context.getImageData(0, 0, canvas.width, canvas.height), data = image.data, histogram = new Uint32Array(256);
    for (let i = 0; i < data.length; i += 4) { const grey = (data[i] * 299 + data[i + 1] * 587 + data[i + 2] * 114) / 1000 | 0; data[i] = grey; histogram[grey] += 1; }
    const pixels = data.length / 4; let low = 0, high = 255, seen = 0;
    for (let v = 0; v < 256; v += 1) { seen += histogram[v]; if (seen >= pixels * 0.01) { low = v; break; } }
    seen = 0; for (let v = 255; v >= 0; v -= 1) { seen += histogram[v]; if (seen >= pixels * 0.01) { high = v; break; } }
    // Stretch only when the page really is low-contrast; on a clean page the percentiles can both sit on the white background.
    const span = high - low;
    for (let i = 0; i < data.length; i += 4) { const v = span >= 96 || span < 24 ? data[i] : Math.max(0, Math.min(255, Math.round((data[i] - low) * 255 / span))); data[i] = data[i + 1] = data[i + 2] = v; }
    context.putImageData(image, 0, 0);
  } catch (error) { console.warn('OCR image clean-up skipped:', error); }
  const worker = await getOcrWorker((status, progress) => {
    setProgress(overallProgress(), `OCR page ${pageNumber} of ${escapeText(fileName)} — ${status} ${Math.round(progress * 100)}%`);
  });
  const result = await worker.recognize(canvas, {}, { text: true, blocks: true });
  const pageHeight = canvas.height / scale;
  canvas.width = 1;
  canvas.height = 1;
  // v10: keep each word's position (in PDF points, origin at the bottom like pdf.js) so the column reader can see the
  // statement's columns. Words on one OCR line share that line's baseline.
  const items = [];
  for (const block of (result.data && result.data.blocks) || []) {
    for (const paragraph of block.paragraphs || []) {
      for (const line of paragraph.lines || []) {
        const base = line.baseline && Number.isFinite(line.baseline.y0) ? (line.baseline.y0 + line.baseline.y1) / 2 : line.bbox.y1;
        for (const word of line.words || []) {
          if (!word.text || !word.text.trim() || !word.bbox) continue;
          items.push({ str: word.text, transform: [1, 0, 0, 1, word.bbox.x0 / scale, pageHeight - base / scale], width: (word.bbox.x1 - word.bbox.x0) / scale, height: (word.bbox.y1 - word.bbox.y0) / scale });
        }
      }
    }
  }
  return items.length ? Core.groupTextItemsToLines(items, pageNumber) : Core.plainTextToLines((result.data && result.data.text) || '', pageNumber);
}

function escapeText(value) {
  return String(value || '').replace(/[<>]/g, '');
}

async function safelyReleasePdf(loadingTask, pdf) {
  // PDF.js 6.x exposes destroy() on PDFDocumentLoadingTask. The document proxy
  // exposes cleanup(), but cleanup failures must never discard extracted rows.
  try {
    if (pdf && typeof pdf.cleanup === 'function') {
      await pdf.cleanup();
    }
  } catch (error) {
    console.warn('PDF document cleanup warning:', error);
  }

  try {
    if (loadingTask && typeof loadingTask.destroy === 'function' && !loadingTask.destroyed) {
      await loadingTask.destroy();
    }
  } catch (error) {
    console.warn('PDF loading-task cleanup warning:', error);
  }
}

async function extractPdf(file, settings, counters) {
  const pdfjs = await getPdfJs();
  const bytes = new Uint8Array(await file.arrayBuffer());
  // Hash before getDocument(): PDF.js may transfer and detach bytes.buffer.
  const fingerprint = await sha256Hex(bytes);
  const loadingTask = pdfjs.getDocument({ data: bytes, useSystemFonts: true, isEvalSupported: false });
  let pdf = null;
  let access = null;

  loadingTask.onPassword = async (updatePassword, reason) => {
    const message = reason === pdfjs.PasswordResponses.INCORRECT_PASSWORD
      ? `The password for ${file.name} was incorrect. Try again.`
      : `${file.name} is password protected.`;
    const password = await requestPdfPassword(message);
    if (password === null) {
      state.cancelled = true;
      if (typeof loadingTask.destroy === 'function') {
        void loadingTask.destroy().catch(() => {});
      }
    } else updatePassword(password);
  };

  try {
    pdf = await loadingTask.promise;
    const pageCount = pdf.numPages;
    if (counters.totalPages + pageCount > MAX_TOTAL_PAGES) {
      throw new Error(`Total PDF pages exceed the ${MAX_TOTAL_PAGES}-page browser limit.`);
    }
    access = await authorizeConversion({ file, pageCount, fingerprint });
    if (Number.isFinite(Number(access.row_limit)) && Number(access.row_limit) > 0) {
      state.accessRowLimit = Number(access.row_limit);
    }

    counters.totalPages += pageCount;
    const lines = [];
    let ocrPages = 0;
    let textPages = 0;
    const rawPageText = [];

    for (let pageNumber = 1; pageNumber <= pageCount; pageNumber += 1) {
      if (state.cancelled) throw new Error('Conversion cancelled.');
      const page = await pdf.getPage(pageNumber);

      try {
        const fraction = (counters.completedPages + pageNumber - 1) / Math.max(1, counters.totalPages);
        setProgress(fraction * 100, `Reading ${file.name} — page ${pageNumber} of ${pageCount}`);
        const content = await page.getTextContent({ disableNormalization: false, includeMarkedContent: false });
        let pageLines = Core.groupTextItemsToLines(content.items, pageNumber);
        const pageText = pageLines.map(line => line.text).join('\n');
        const usefulCharacters = pageText.replace(/\s/g, '').length;

        if (usefulCharacters < 30 && settings.useOcr) {
          if (counters.ocrPages >= MAX_OCR_PAGES) {
            throw new Error(`OCR is limited to ${MAX_OCR_PAGES} pages per conversion for browser performance.`);
          }
          pageLines = await extractPageWithOcr(page, pageNumber, file.name, () => fraction * 100);
          ocrPages += 1;
          counters.ocrPages += 1;
        } else if (usefulCharacters >= 30) {
          textPages += 1;
        }

        rawPageText.push(pageLines.map(line => line.text).join('\n'));
        pageLines.forEach(line => lines.push({ ...line, sourceFile: file.name }));
      } finally {
        try {
          if (typeof page.cleanup === 'function') page.cleanup();
        } catch (error) {
          console.warn(`Page ${pageNumber} cleanup warning:`, error);
        }
      }
    }

    counters.completedPages += pageCount;
    await completeAuthorization(access.token, 'completed');
    return { fileName: file.name, lines, rawPageText, pages: pageCount, ocrPages, textPages, accessType: access.access_type };
  } catch (error) {
    if (access && access.token) await completeAuthorization(access.token, 'failed');
    throw error;
  } finally {
    await safelyReleasePdf(loadingTask, pdf);
    if (bytes.byteLength) bytes.fill(0);
  }
}

async function extractAll() {
  if (!state.files.length || state.busy) return;
  state.cancelled = false;
  state.extractedDocuments = [];
  state.rows = [];
  state.page = 1;
  state.settingsUsed = getSettings();
  setBusy(true);
  setStatus('', 'info');
  setProgress(1, 'Loading secure browser extraction engine…');
  const counters = { totalPages: 0, completedPages: 0, ocrPages: 0 };
  const errors = [];

  try {
    for (const file of state.files) {
      if (state.cancelled) break;
      try {
        const documentData = await extractPdf(file, state.settingsUsed, counters);
        state.extractedDocuments.push(documentData);
      } catch (error) {
        errors.push(`${file.name}: ${error.message || error}`);
      }
    }
    if (state.cancelled) throw new Error('Conversion cancelled.');
    if (!state.extractedDocuments.length) throw new Error(errors.join(' ') || 'No statement could be read.');
    reparseDocuments();
    const totalRows = state.rows.length;
    setProgress(100, `Completed — ${totalRows} transaction${totalRows === 1 ? '' : 's'} detected.`);
    const errorText = errors.length ? ` ${errors.join(' ')}` : '';
    setStatus(`Review every row before export. Automated statement extraction can make errors.${errorText}`, errors.length ? 'warning' : 'success');
  } catch (error) {
    setStatus(error.message || String(error), 'error');
  } finally {
    setBusy(false);
    setTimeout(() => { if (!state.busy) els.progressWrap.hidden = true; }, 1800);
  }
}

function reparseDocuments() {
  const settings = getSettings();
  state.settingsUsed = settings;
  const combined = [];
  const warnings = [];
  state.checks = [];
  for (const documentData of state.extractedDocuments) {
    for (const statement of statementsIn(documentData, settings)) {
      const parsed = parseDocument(statement, settings);
      parsed.rows.forEach(row => combined.push({ ...row, sourceFile: statement.fileName }));
      warnings.push(...parsed.warnings.map(warning => `${statement.fileName}: ${warning}`));
      state.checks.push({ fileName: statement.fileName, check: parsed.check, reader: parsed.reader, rows: parsed.rows.length, account: statement.account });
    }
  }
  const detectedCount = combined.length;
  const rowLimit = Number(state.accessRowLimit || 25);
  state.rows = combined.slice(0, rowLimit);
  if (detectedCount > state.rows.length) {
    warnings.push(`${detectedCount - state.rows.length} additional transactions require a conversion credit or Pro plan.`);
  }
  Core.markDuplicates(state.rows);
  state.page = 1;
  renderReview(warnings);
  renderStatementChecks(detectedCount > state.rows.length ? `Checked on all ${detectedCount} transactions in the statement; the free sample shows ${state.rows.length}.` : '');
}

function loadDemoData() {
  state.files = [];
  state.checks = [];
  renderStatementChecks();
  renderFileList();
  state.rows = [
    { date: '2026-06-01', postedDate: '', description: 'Opening balance adjustment', debit: 0, credit: 0, amount: 0, balance: 4250.00, category: 'Other Income', reference: '', page: 1, confidence: 95, issues: '', raw: 'Demo', included: false, duplicate: false, extractionMethod: 'Demo', sourceFile: 'demo-statement.pdf', id: 'demo1' },
    { date: '2026-06-02', postedDate: '', description: 'PAYROLL DIRECT DEPOSIT', debit: 0, credit: 2850.00, amount: 2850.00, balance: 7100.00, category: 'Payroll & Income', reference: '', page: 1, confidence: 98, issues: '', raw: 'Demo', included: true, duplicate: false, extractionMethod: 'Demo', sourceFile: 'demo-statement.pdf', id: 'demo2' },
    { date: '2026-06-03', postedDate: '', description: 'MONTHLY MORTGAGE PAYMENT', debit: 2180.00, credit: 0, amount: -2180.00, balance: 4920.00, category: 'Rent & Mortgage', reference: '', page: 1, confidence: 98, issues: '', raw: 'Demo', included: true, duplicate: false, extractionMethod: 'Demo', sourceFile: 'demo-statement.pdf', id: 'demo3' },
    { date: '2026-06-04', postedDate: '', description: 'E-TRANSFER RECEIVED CLIENT PAYMENT', debit: 0, credit: 975.50, amount: 975.50, balance: 5895.50, category: 'Transfers', reference: '', page: 1, confidence: 78, issues: 'Direction checked against running balance.', raw: 'Demo', included: true, duplicate: false, extractionMethod: 'Demo', sourceFile: 'demo-statement.pdf', id: 'demo4' },
    { date: '2026-06-05', postedDate: '', description: 'SERVICE CHARGE', debit: 16.95, credit: 0, amount: -16.95, balance: 5878.55, category: 'Bank Fees', reference: '', page: 1, confidence: 90, issues: '', raw: 'Demo', included: true, duplicate: false, extractionMethod: 'Demo', sourceFile: 'demo-statement.pdf', id: 'demo5' },
  ];
  renderReview(['Demo data only — upload a PDF for actual extraction.']);
  setStatus('Demo transactions loaded. Nothing was uploaded.', 'success');
}

function renderReview(extraWarnings = []) {
  els.reviewSection.hidden = false;
  populateSourceFilter();
  applyFilters();
  renderSummary();
  renderWarnings(extraWarnings);
  els.reviewSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function populateSourceFilter() {
  const current = els.sourceFilter.value;
  const sources = [...new Set(state.rows.map(row => row.sourceFile))].sort();
  els.sourceFilter.innerHTML = '<option value="all">All files</option>' + sources.map(source => `<option value="${escapeHtml(source)}">${escapeHtml(source)}</option>`).join('');
  if (sources.includes(current)) els.sourceFilter.value = current;
}

function applyFilters() {
  const query = els.search.value.trim().toLowerCase();
  const confidence = els.confidenceFilter.value;
  const source = els.sourceFilter.value;
  state.filteredIndices = state.rows.map((row, index) => ({ row, index })).filter(({ row }) => {
    const textMatch = !query || [row.date, row.postedDate, row.description, row.category, row.reference, row.sourceFile].join(' ').toLowerCase().includes(query);
    const confidenceMatch = confidence === 'all' || (confidence === 'low' && row.confidence < 65) || (confidence === 'review' && (row.confidence < 80 || row.duplicate || row.issues));
    const sourceMatch = source === 'all' || row.sourceFile === source;
    return textMatch && confidenceMatch && sourceMatch;
  }).map(item => item.index);
  state.page = Math.min(state.page, Math.max(1, Math.ceil(state.filteredIndices.length / state.pageSize)));
  renderTable();
}

function renderTable() {
  const start = (state.page - 1) * state.pageSize;
  const indices = state.filteredIndices.slice(start, start + state.pageSize);
  els.tableBody.innerHTML = indices.map(index => rowHtml(state.rows[index], index)).join('');
  const pages = Math.max(1, Math.ceil(state.filteredIndices.length / state.pageSize));
  els.pageInfo.textContent = `Page ${state.page} of ${pages} • ${state.filteredIndices.length} matching rows`;
  els.prevPage.disabled = state.page <= 1;
  els.nextPage.disabled = state.page >= pages;
  els.tableHeadSelect.checked = indices.length > 0 && indices.every(index => state.rows[index].included);
  els.tableHeadSelect.indeterminate = indices.some(index => state.rows[index].included) && !els.tableHeadSelect.checked;
}

function rowHtml(row, index) {
  const rowClass = [row.confidence < 65 ? 'low-confidence' : '', row.duplicate ? 'duplicate-row' : ''].filter(Boolean).join(' ');
  return `<tr class="${rowClass}" data-row-index="${index}">
    <td><input type="checkbox" data-field="included" ${row.included ? 'checked' : ''} aria-label="Include transaction"></td>
    <td><input type="date" data-field="date" value="${escapeHtml(row.date)}"></td>
    <td><input type="date" data-field="postedDate" value="${escapeHtml(row.postedDate)}" aria-label="Posted date"></td>
    <td class="description-cell"><input type="text" data-field="description" value="${escapeHtml(row.description)}"></td>
    <td><input class="money-input" type="number" step="0.01" data-field="debit" value="${row.debit || ''}"></td>
    <td><input class="money-input" type="number" step="0.01" data-field="credit" value="${row.credit || ''}"></td>
    <td><input class="money-input" type="number" step="0.01" data-field="balance" value="${row.balance === null ? '' : row.balance}"></td>
    <td><select data-field="category">${categoryOptions(row.category)}</select></td>
    <td><input type="text" data-field="reference" value="${escapeHtml(row.reference)}"></td>
    <td><span class="confidence-badge ${row.confidence < 65 ? 'low' : row.confidence < 80 ? 'medium' : 'high'}" title="${escapeHtml(row.issues || 'No automated warning')}">${row.confidence}%</span>${row.duplicate ? '<span class="duplicate-badge">Duplicate?</span>' : ''}</td>
    <td><span class="source-label" title="Page ${row.page}; ${escapeHtml(row.extractionMethod)}">${escapeHtml(row.sourceFile)}</span></td>
    <td><button type="button" class="icon-button" data-delete-row="${index}" aria-label="Delete row">×</button></td>
  </tr>`;
}

function categoryOptions(selected) {
  const categories = ['Bank Fees', 'Payroll & Income', 'Sales & Revenue', 'Transfers', 'Rent & Mortgage', 'Utilities & Telecom', 'Fuel & Vehicle', 'Meals & Entertainment', 'Office & Supplies', 'Tax Payments', 'Insurance', 'Cash & ATM', 'Credit Card', 'Loan & Interest', 'Other Income', 'Other Expense', 'Uncategorized'];
  if (selected && !categories.includes(selected)) categories.push(selected);
  return categories.map(category => `<option ${category === selected ? 'selected' : ''}>${escapeHtml(category)}</option>`).join('');
}

function updateRow(index, field, value) {
  const row = state.rows[index];
  if (!row) return;
  if (field === 'included') row.included = Boolean(value);
  else if (['debit', 'credit'].includes(field)) {
    row[field] = Math.max(0, Number(value) || 0);
    row.amount = Number((row.credit - row.debit).toFixed(2));
    row.category = Core.categorize(row.description, row.amount);
  } else if (field === 'balance') row.balance = value === '' ? null : Number(value);
  else row[field] = value;
  row.confidence = field === 'included' ? row.confidence : Math.max(row.confidence, 90);
  renderSummary();
}

function includedRows() {
  return state.rows.filter(row => row.included);
}

function renderSummary() {
  const rows = includedRows();
  const debit = rows.reduce((sum, row) => sum + (Number(row.debit) || 0), 0);
  const credit = rows.reduce((sum, row) => sum + (Number(row.credit) || 0), 0);
  const lastBalanceRow = [...rows].reverse().find(row => row.balance !== null);
  els.includedCount.textContent = String(rows.length);
  els.totalDebits.textContent = formatMoney(debit);
  els.totalCredits.textContent = formatMoney(credit);
  els.netMovement.textContent = formatMoney(credit - debit);
  els.closingBalance.textContent = lastBalanceRow ? formatMoney(lastBalanceRow.balance) : 'Not detected';
}

function renderWarnings(extra = []) {
  const low = state.rows.filter(row => row.confidence < 65).length;
  const review = state.rows.filter(row => row.issues).length;
  const duplicates = state.rows.filter(row => row.duplicate).length;
  const warnings = [
    ...extra,
    low ? `${low} row${low === 1 ? '' : 's'} have low extraction confidence.` : '',
    review ? `${review} row${review === 1 ? '' : 's'} contain an automated review note.` : '',
    duplicates ? `${duplicates} possible duplicate transaction${duplicates === 1 ? '' : 's'} detected.` : '',
    'Compare transaction totals and running balances with the original statement before accounting, tax, lending or audit use.',
  ].filter(Boolean);
  els.warnings.innerHTML = warnings.map(warning => `<li>${escapeHtml(warning)}</li>`).join('');
}

function formatMoney(value) {
  const currency = $('statement-currency').value || 'CAD';
  try { return new Intl.NumberFormat('en-CA', { style: 'currency', currency }).format(Number(value) || 0); }
  catch { return (Number(value) || 0).toFixed(2); }
}

function addBlankRow() {
  state.rows.unshift({
    date: '', postedDate: '', description: '', debit: 0, credit: 0, amount: 0, balance: null,
    category: 'Uncategorized', reference: '', page: 0, confidence: 100, issues: 'Manually added row.', raw: '',
    included: true, duplicate: false, extractionMethod: 'Manual', sourceFile: 'manual-entry', id: `manual-${Date.now()}`,
  });
  state.page = 1;
  populateSourceFilter();
  applyFilters();
  renderSummary();
}

function excludeDuplicateRows() {
  const seen = new Set();
  let count = 0;
  state.rows.forEach(row => {
    const key = Core.transactionKey(row);
    if (seen.has(key)) { row.included = false; row.duplicate = true; count += 1; }
    else seen.add(key);
  });
  renderTable();
  renderSummary();
  setStatus(`${count} duplicate row${count === 1 ? '' : 's'} excluded. Review before export.`, count ? 'success' : 'info');
}

function sortRows(direction) {
  const sign = direction === 'newest' ? -1 : 1;
  state.rows.sort((a, b) => {
    const dateCompare = String(a.date).localeCompare(String(b.date));
    if (dateCompare) return dateCompare * sign;
    return String(a.description).localeCompare(String(b.description)) * sign;
  });
  state.sortDirection = direction;
  state.page = 1;
  applyFilters();
}

function safeSpreadsheetText(value) {
  const text = String(value ?? '');
  return /^[=+\-@\t\r]/.test(text) ? `'${text}` : text;
}

function exportRows() {
  return includedRows().map((row, index) => ({
    'Transaction #': index + 1,
    Date: row.date,
    'Posted Date': row.postedDate,
    Description: safeSpreadsheetText(row.description),
    Debit: Number(row.debit || 0),
    Credit: Number(row.credit || 0),
    Amount: Number(row.amount || 0),
    Balance: row.balance === null ? '' : Number(row.balance),
    Category: safeSpreadsheetText(row.category),
    Reference: safeSpreadsheetText(row.reference),
    'Source File': safeSpreadsheetText(row.sourceFile),
    Page: row.page || '',
    'Extraction Method': row.extractionMethod,
    Confidence: row.confidence,
    'Review Note': safeSpreadsheetText(row.issues),
  }));
}

function baseFileName() {
  const date = new Date().toISOString().slice(0, 10).replace(/-/g, '');
  return `bank-transactions-${date}`;
}

function downloadBlob(content, type, name) {
  const blob = content instanceof Blob ? content : new Blob([content], { type });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = name;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1200);
}

function csvEscape(value) {
  const text = String(value ?? '');
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function exportCsv(quickBooks = false, tsv = false) {
  const rows = exportRows();
  const delimiter = tsv ? '\t' : ',';
  let headers;
  let bodyRows;
  if (quickBooks) {
    headers = ['Date', 'Description', 'Amount'];
    bodyRows = rows.map(row => [row.Date, row.Description, row.Amount.toFixed(2)]);
  } else {
    headers = Object.keys(rows[0] || { Date: '', Description: '', Debit: '', Credit: '', Amount: '', Balance: '' });
    bodyRows = rows.map(row => headers.map(header => row[header]));
  }
  const lines = [headers, ...bodyRows].map(row => row.map(value => tsv ? String(value ?? '').replace(/[\t\r\n]/g, ' ') : csvEscape(value)).join(delimiter));
  const suffix = quickBooks ? '-quickbooks' : tsv ? '' : '';
  downloadBlob(`\ufeff${lines.join('\r\n')}`, tsv ? 'text/tab-separated-values;charset=utf-8' : 'text/csv;charset=utf-8', `${baseFileName()}${suffix}.${tsv ? 'tsv' : 'csv'}`);
}

async function exportXlsx() {
  await loadScript('/vendor/sheetjs/xlsx.full.min.js', () => Boolean(globalThis.XLSX));
  const XLSX = globalThis.XLSX;
  const rows = exportRows();
  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.json_to_sheet(rows);
  ws['!cols'] = [8, 13, 13, 42, 14, 14, 14, 14, 24, 18, 30, 8, 16, 12, 48].map(width => ({ wch: width }));
  XLSX.utils.book_append_sheet(wb, ws, 'Transactions');
  const selected = includedRows();
  const summary = [
    ['SR AccounTax Bank Statement Converter'],
    ['Generated', new Date().toISOString()],
    ['Currency', $('statement-currency').value],
    ['Transactions', selected.length],
    ['Total debits', selected.reduce((sum, row) => sum + row.debit, 0)],
    ['Total credits', selected.reduce((sum, row) => sum + row.credit, 0)],
    ['Net movement', selected.reduce((sum, row) => sum + row.amount, 0)],
    ['Important', 'Automated extraction. Verify every row against the original PDF.'],
  ];
  const summarySheet = XLSX.utils.aoa_to_sheet(summary);
  summarySheet['!cols'] = [{ wch: 22 }, { wch: 80 }];
  XLSX.utils.book_append_sheet(wb, summarySheet, 'Summary');
  const checkRows = statementCheckRows();
  if (checkRows.length) { const checkSheet = XLSX.utils.json_to_sheet(checkRows); checkSheet['!cols'] = [30, 14, 12, 12, 16, 16, 14, 12, 12, 12, 12, 90].map(width => ({ wch: width })); XLSX.utils.book_append_sheet(wb, checkSheet, 'Statement Check'); }
  const logRows = state.extractedDocuments.map(doc => ({ File: safeSpreadsheetText(doc.fileName), Pages: doc.pages, 'Text pages': doc.textPages, 'OCR pages': doc.ocrPages }));
  const logSheet = XLSX.utils.json_to_sheet(logRows);
  XLSX.utils.book_append_sheet(wb, logSheet, 'Extraction Log');
  XLSX.writeFile(wb, `${baseFileName()}.xlsx`, { compression: true });
}

function exportJson() {
  const payload = {
    generatedAt: new Date().toISOString(),
    tool: 'SR AccounTax Bank Statement Converter',
    settings: { ...state.settingsUsed, bankId: undefined, accountId: state.settingsUsed && state.settingsUsed.accountId ? `***${state.settingsUsed.accountId.slice(-4)}` : '' },
    summary: {
      transactionCount: includedRows().length,
      totalDebits: includedRows().reduce((sum, row) => sum + row.debit, 0),
      totalCredits: includedRows().reduce((sum, row) => sum + row.credit, 0),
      netMovement: includedRows().reduce((sum, row) => sum + row.amount, 0),
    },
    statementChecks: statementCheckRows(),
    transactions: exportRows(),
    warning: 'Automated extraction. Verify all data against the original statement.',
  };
  downloadBlob(JSON.stringify(payload, null, 2), 'application/json;charset=utf-8', `${baseFileName()}.json`);
}

function exportText() {
  const sections = state.extractedDocuments.map(doc => `===== ${doc.fileName} =====\n${doc.rawPageText.map((text, index) => `--- Page ${index + 1} ---\n${text}`).join('\n\n')}`);
  downloadBlob(sections.join('\n\n'), 'text/plain;charset=utf-8', `${baseFileName()}-extracted-text.txt`);
}

function qifDate(iso) {
  const [year, month, day] = String(iso).split('-');
  return `${month}/${day}/${year}`;
}

function exportQif() {
  const lines = ['!Type:Bank'];
  includedRows().forEach(row => {
    lines.push(`D${qifDate(row.date)}`);
    lines.push(`T${Number(row.amount).toFixed(2)}`);
    lines.push(`P${row.description.replace(/[\r\n]/g, ' ')}`);
    if (row.category) lines.push(`L${row.category}`);
    if (row.reference) lines.push(`N${row.reference}`);
    lines.push('^');
  });
  downloadBlob(lines.join('\r\n'), 'application/qif;charset=utf-8', `${baseFileName()}.qif`);
}

function ofxEscape(value) {
  return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/[\r\n]/g, ' ');
}

function exportOfx() {
  const rows = includedRows();
  if (!rows.length) throw new Error('No included transactions to export.');
  const settings = getSettings();
  const dates = rows.map(row => row.date).filter(Boolean).sort();
  const start = `${dates[0].replace(/-/g, '')}000000`;
  const end = `${dates[dates.length - 1].replace(/-/g, '')}235959`;
  const accountId = settings.accountId || '0000';
  const bankId = settings.bankId || '00000';
  const transactions = rows.map((row, index) => {
    const fitid = Core.simpleHash([row.date, row.description, row.amount, index].join('|'));
    return `<STMTTRN><TRNTYPE>${row.amount < 0 ? 'DEBIT' : 'CREDIT'}<DTPOSTED>${row.date.replace(/-/g, '')}120000<TRNAMT>${row.amount.toFixed(2)}<FITID>${fitid}<NAME>${ofxEscape(row.description).slice(0, 64)}${row.reference ? `<MEMO>${ofxEscape(row.reference)}` : ''}</STMTTRN>`;
  }).join('');
  const closing = [...rows].reverse().find(row => row.balance !== null);
  const content = `OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nSECURITY:NONE\nENCODING:USASCII\nCHARSET:1252\nCOMPRESSION:NONE\nOLDFILEUID:NONE\nNEWFILEUID:NONE\n\n<OFX><SIGNONMSGSRSV1><SONRS><STATUS><CODE>0<SEVERITY>INFO</STATUS><DTSERVER>${end}<LANGUAGE>ENG</SONRS></SIGNONMSGSRSV1><BANKMSGSRSV1><STMTTRNRS><TRNUID>${Date.now()}<STATUS><CODE>0<SEVERITY>INFO</STATUS><STMTRS><CURDEF>${settings.currency}<BANKACCTFROM><BANKID>${ofxEscape(bankId)}<ACCTID>${ofxEscape(accountId)}<ACCTTYPE>CHECKING</BANKACCTFROM><BANKTRANLIST><DTSTART>${start}<DTEND>${end}${transactions}</BANKTRANLIST><LEDGERBAL><BALAMT>${closing ? closing.balance.toFixed(2) : rows.reduce((sum, row) => sum + row.amount, 0).toFixed(2)}<DTASOF>${end}</LEDGERBAL></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>`;
  downloadBlob(content, 'application/x-ofx;charset=windows-1252', `${baseFileName()}.ofx`);
}

async function exportPdf() {
  await loadScript('/vendor/jspdf/jspdf.umd.min.js', () => Boolean(globalThis.jspdf));
  await loadScript('/vendor/jspdf/jspdf.plugin.autotable.min.js', () => Boolean(globalThis.jspdf && globalThis.jspdf.jsPDF && globalThis.jspdf.jsPDF.API.autoTable));
  const { jsPDF } = globalThis.jspdf;
  const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'letter', compress: true });
  const rows = includedRows();
  doc.setFontSize(18);
  doc.text('Bank Statement Transaction Report', 36, 38);
  doc.setFontSize(9);
  doc.text(`Generated by SR AccounTax browser utility • ${new Date().toLocaleString('en-CA')}`, 36, 55);
  doc.text(`Transactions: ${rows.length}   Debits: ${formatMoney(rows.reduce((s, r) => s + r.debit, 0))}   Credits: ${formatMoney(rows.reduce((s, r) => s + r.credit, 0))}`, 36, 70);
  const checkLine = (state.checks || []).map(item => `${item.fileName}: ${Layout ? Layout.checkText(item.check).text : ''}`).join('  |  ').slice(0, 400);
  if (checkLine) { doc.setTextColor(20, 90, 60); doc.text(doc.splitTextToSize(`Statement check — ${checkLine}`, 720)[0], 36, 92); }
  doc.setTextColor(150, 70, 0);
  doc.text('Automated extraction — verify every row against the original statement before use.', 36, 85);
  doc.setTextColor(0, 0, 0);
  doc.autoTable({
    startY: 98,
    head: [['Date', 'Description', 'Debit', 'Credit', 'Balance', 'Category', 'Source']],
    body: rows.map(row => [row.date, row.description, row.debit ? row.debit.toFixed(2) : '', row.credit ? row.credit.toFixed(2) : '', row.balance === null ? '' : row.balance.toFixed(2), row.category, row.sourceFile]),
    styles: { fontSize: 7, cellPadding: 3, overflow: 'linebreak' },
    headStyles: { fillColor: [11, 42, 58] },
    columnStyles: { 0: { cellWidth: 58 }, 1: { cellWidth: 230 }, 2: { halign: 'right', cellWidth: 58 }, 3: { halign: 'right', cellWidth: 58 }, 4: { halign: 'right', cellWidth: 62 }, 5: { cellWidth: 100 }, 6: { cellWidth: 100 } },
    didDrawPage: data => {
      const pageNumber = doc.internal.getNumberOfPages();
      doc.setFontSize(7);
      doc.text(`Page ${pageNumber}`, data.settings.margin.left, doc.internal.pageSize.height - 18);
    },
  });
  doc.save(`${baseFileName()}.pdf`);
}

async function copyCsvToClipboard() {
  const rows = exportRows();
  const headers = Object.keys(rows[0] || {});
  const text = [headers, ...rows.map(row => headers.map(header => row[header]))].map(row => row.map(csvEscape).join(',')).join('\r\n');
  await navigator.clipboard.writeText(text);
  setStatus('CSV data copied to the clipboard.', 'success');
}

async function handleExport(format) {
  if (!includedRows().length) { setStatus('Include at least one transaction before exporting.', 'warning'); return; }
  if (!['csv', 'clipboard'].includes(format)) {
    setStatus('This export is available with a conversion credit or Pro plan.', 'warning');
    return;
  }
  if (includedRows().length > 50) {
    setStatus('The free plan exports the authorized free transaction sample. Upgrade for complete files.', 'warning');
  }
  try {
    setStatus(`Preparing ${format.toUpperCase()} export…`, 'info');
    if (format === 'xlsx') await exportXlsx();
    else if (format === 'csv') exportCsv();
    else if (format === 'quickbooks-csv') exportCsv(true);
    else if (format === 'tsv') exportCsv(false, true);
    else if (format === 'json') exportJson();
    else if (format === 'qif') exportQif();
    else if (format === 'ofx') exportOfx();
    else if (format === 'pdf') await exportPdf();
    else if (format === 'text') exportText();
    else if (format === 'clipboard') await copyCsvToClipboard();
    setStatus(`${format.toUpperCase()} export prepared. Review the downloaded file before importing it elsewhere.`, 'success');
  } catch (error) {
    setStatus(`Export failed: ${error.message || error}`, 'error');
  }
}

async function resetAll() {
  state.cancelled = true;
  if (state.ocrWorker && typeof state.ocrWorker.terminate === 'function') {
    try { await state.ocrWorker.terminate(); } catch {}
  }
  state.ocrWorker = null;
  state.files = [];
  state.extractedDocuments = [];
  state.rows = [];
  state.checks = [];
  state.filteredIndices = [];
  state.page = 1;
  els.fileInput.value = '';
  els.reviewSection.hidden = true;
  els.progressWrap.hidden = true;
  renderFileList();
  setStatus('All files and extracted data were cleared from this browser page.', 'success');
  els.extractButton.disabled = true;
}

els.fileInput.addEventListener('change', event => addFiles(event.target.files));
els.dropZone.addEventListener('dragover', event => { event.preventDefault(); els.dropZone.classList.add('dragging'); });
els.dropZone.addEventListener('dragleave', () => els.dropZone.classList.remove('dragging'));
els.dropZone.addEventListener('drop', event => { event.preventDefault(); els.dropZone.classList.remove('dragging'); addFiles(event.dataTransfer.files); });
els.dropZone.addEventListener('click', event => { if (!event.target.closest('button, input')) els.fileInput.click(); });
els.dropZone.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); els.fileInput.click(); } });
els.fileList.addEventListener('click', event => {
  const button = event.target.closest('[data-remove-file]');
  if (!button) return;
  state.files.splice(Number(button.dataset.removeFile), 1);
  renderFileList();
  els.extractButton.disabled = !state.files.length;
});
els.extractButton.addEventListener('click', extractAll);
els.cancelButton.addEventListener('click', () => { state.cancelled = true; setStatus('Cancelling after the current page…', 'warning'); });
els.resetButton.addEventListener('click', () => { void resetAll(); });
els.reparseButton.addEventListener('click', () => {
  if (!state.extractedDocuments.length) return setStatus('Extract a PDF before re-running the parser.', 'warning');
  reparseDocuments();
  setStatus('Transactions were re-parsed using the current settings.', 'success');
});
els.demoButton.addEventListener('click', loadDemoData);
els.search.addEventListener('input', applyFilters);
els.confidenceFilter.addEventListener('change', applyFilters);
els.sourceFilter.addEventListener('change', applyFilters);
els.prevPage.addEventListener('click', () => { if (state.page > 1) { state.page -= 1; renderTable(); } });
els.nextPage.addEventListener('click', () => { const pages = Math.ceil(state.filteredIndices.length / state.pageSize); if (state.page < pages) { state.page += 1; renderTable(); } });
els.tableHeadSelect.addEventListener('change', event => {
  const start = (state.page - 1) * state.pageSize;
  state.filteredIndices.slice(start, start + state.pageSize).forEach(index => { state.rows[index].included = event.target.checked; });
  renderTable(); renderSummary();
});
els.tableBody.addEventListener('input', event => {
  const rowElement = event.target.closest('[data-row-index]');
  const field = event.target.dataset.field;
  if (!rowElement || !field) return;
  updateRow(Number(rowElement.dataset.rowIndex), field, event.target.type === 'checkbox' ? event.target.checked : event.target.value);
});
els.tableBody.addEventListener('change', event => {
  const rowElement = event.target.closest('[data-row-index]');
  const field = event.target.dataset.field;
  if (!rowElement || !field) return;
  updateRow(Number(rowElement.dataset.rowIndex), field, event.target.type === 'checkbox' ? event.target.checked : event.target.value);
});
els.tableBody.addEventListener('click', event => {
  const button = event.target.closest('[data-delete-row]');
  if (!button) return;
  state.rows.splice(Number(button.dataset.deleteRow), 1);
  Core.markDuplicates(state.rows);
  populateSourceFilter(); applyFilters(); renderSummary();
});
document.querySelector('[data-action="add-row"]').addEventListener('click', addBlankRow);
document.querySelector('[data-action="exclude-duplicates"]').addEventListener('click', excludeDuplicateRows);
document.querySelector('[data-action="sort-oldest"]').addEventListener('click', () => sortRows('oldest'));
document.querySelector('[data-action="sort-newest"]').addEventListener('click', () => sortRows('newest'));
els.exportButtons.forEach(button => button.addEventListener('click', () => handleExport(button.dataset.export)));
window.addEventListener('pagehide', () => {
  state.files = [];
  state.extractedDocuments = [];
  state.rows = [];
  if (state.ocrWorker && typeof state.ocrWorker.terminate === 'function') void state.ocrWorker.terminate();
  state.ocrWorker = null;
});

$('statement-year').value = String(new Date().getFullYear());
setBusy(false);
els.extractButton.disabled = true;
