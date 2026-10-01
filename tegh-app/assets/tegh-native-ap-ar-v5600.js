(() => {
  'use strict';

  if (window.TeghNativeAPAR) return;

  const VERSION = '5.6.0';
  const BUILD = 5600;
  const CLOSED_DOCUMENT_STATES = new Set(['linked', 'dismissed']);

  const amountValue = (value) => value === undefined || value === null || value === ''
    ? ''
    : (Number(value) / 100).toFixed(2);

  const candidateFromForm = (form, existing = {}) => {
    const formData = new FormData(form);
    const candidate = {
      partyId: String(formData.get('partyId') || ''),
      partyName: String(formData.get('partyName') || '').trim(),
      partyEmail: String(formData.get('partyEmail') || '').trim(),
      businessIdentifier: String(formData.get('businessIdentifier') || '').trim(),
      address: String(formData.get('address') || '').trim(),
      layoutFingerprint: String(formData.get('layoutFingerprint') || existing.layoutFingerprint || '').trim(),
      paymentTerms: String(formData.get('paymentTerms') || '').trim(),
      documentNumber: String(formData.get('documentNumber') || '').trim(),
      documentDate: String(formData.get('documentDate') || ''),
      dueDate: String(formData.get('dueDate') || ''),
      currency: String(formData.get('currency') || 'CAD').trim().toUpperCase(),
      memo: String(formData.get('memo') || '').trim(),
      confidenceBps: Math.max(0, Math.min(10000, Math.round(Number(formData.get('confidence') || 0) * 100))),
      alternatives: Array.isArray(existing.alternatives) ? existing.alternatives.slice(0, 5) : [],
      lineItems: Array.isArray(existing.lineItems) ? existing.lineItems.slice(0, 250) : [],
    };
    for (const key of ['subtotalCents', 'taxCents', 'totalCents', 'amountDueCents']) {
      const value = String(formData.get(key) || '').trim();
      if (value !== '') candidate[key] = Math.round(Number(value) * 100);
    }
    const reviewedLines=[...form.querySelectorAll('[data-intake-line]')];if(reviewedLines.length)candidate.lineItems=reviewedLines.map(row=>({description:row.querySelector('[data-line-description]').value.trim(),amountCents:Math.round(Number(row.querySelector('[data-line-amount]').value)*100)})).filter(x=>x.description&&Number.isInteger(x.amountCents)&&x.amountCents>=0);
    return candidate;
  };

  function openDocumentIntake(adapter, options = {}) {
    const {$, $$, api, esc, money, toast, userFacingError, workspace, showPage, moduleDashboard, openBills, openCustomerInvoice} = adapter;
    showPage('document-intake', 'Document Intake', 'Private AP/AR source documents with fully local browser extraction and human-reviewed workflow handoff.', async (body) => {
      const session = await adapter.auth();
      if (!adapter.companyId()) {
        const companies = Array.isArray(session.companies) ? session.companies : [];
        body.innerHTML = `<section class="srp-card tegh-document-company-picker"><h2>Choose a company</h2><p>Document Intake keeps every source, vendor match and learned association inside one company. Choose the company before uploading or reviewing.</p>${companies.length ? `<div class="srp-actions">${companies.map((company) => `<button type="button" class="srp-btn secondary" data-document-company="${esc(company.id)}">${esc(company.name)}</button>`).join('')}</div>` : '<div class="srp-empty">You do not have an active company membership. Ask a Company Owner to grant access.</div>'}</section>`;
        $$('[data-document-company]', body).forEach((button) => button.onclick = () => { adapter.selectCompany(button.dataset.documentCompany);openDocumentIntake(adapter, options); });
        return;
      }
      if (adapter.isConsolidated()) {
        body.innerHTML = '<section class="srp-card"><div class="srp-empty">Select one company before uploading or reviewing a private document.</div></section>';
        return;
      }
      const currentAccess = (session.companies || []).find((company) => String(company.id) === String(adapter.companyId()));
      const canManageLearning = String(currentAccess?.role || '') === 'owner';
      body.innerHTML = `<section class="tegh-native-boundary"><div><span>Native document boundary</span><h2>Your source stays private.</h2><p>PDF text and OCR run in this browser from bundled assets. Raw extracted text is never saved or sent to a provider. Tegh stores only the source file, its integrity hash and the candidate you review.</p></div><dl><div><dt>Provider requests</dt><dd>0</dd></div><div><dt>Automatic postings</dt><dd>0</dd></div><div><dt>Maximum file</dt><dd>10 MB</dd></div></dl></section>
        <section class="srp-card"><div class="srp-section-title"><div><small>PDF · PNG · JPEG · WebP</small><h2>Add a private document</h2><p>The file is stored outside the public web root. Extraction covers up to eight PDF pages and remains local to this browser.</p></div></div><form class="srp-form tegh-document-upload" data-native-document-upload><label>Document type<select name="documentType" required><option value="vendor_bill">Vendor bill</option><option value="customer_invoice">Customer invoice</option></select></label><label class="grow">Source file<input name="file" type="file" accept=".pdf,.png,.jpg,.jpeg,.webp,application/pdf,image/png,image/jpeg,image/webp" required></label><button class="srp-btn" type="submit">Upload and Extract Locally</button></form><div class="tegh-native-progress" data-native-document-progress hidden role="status" aria-live="polite"><span></span><b>Preparing local extraction…</b></div></section>
        <section data-native-document-list aria-live="polite" aria-busy="true"><div class="srp-card srp-empty">Loading private documents…</div></section>${canManageLearning ? '<section class="srp-card tegh-vendor-learning-owner"><h2>Reviewed Vendor Recognition</h2><p>Inspect, disable or reset company-only associations learned from explicit human review. Raw OCR text is never stored here.</p><button type="button" class="srp-btn secondary" data-manage-vendor-learning>Manage Learned Associations</button></section>' : ''}`;
      const list = $('[data-native-document-list]', body);
      // R145 review aids: pick one reading of an ambiguous date; check that subtotal + tax equals the total as you type.
      const amountCheck = (form) => {
        const out = form.querySelector('[data-amount-check]'); if (!out) return;
        const value = (name) => { const raw = String(form.elements.namedItem(name)?.value || '').trim(); return raw === '' ? null : Math.round(Number(raw) * 100); };
        const sub = value('subtotalCents'), tax = value('taxCents'), total = value('totalCents');
        if (sub === null || tax === null || total === null || [sub, tax, total].some((v) => !Number.isFinite(v))) { out.textContent = ''; out.dataset.state = ''; return; }
        const gap = total - sub - tax;
        out.dataset.state = gap === 0 ? 'ok' : 'warn';
        out.textContent = gap === 0 ? 'Subtotal + tax = total ✓' : `Subtotal + tax is ${gap > 0 ? 'less' : 'more'} than the total by ${(Math.abs(gap) / 100).toFixed(2)}. Check the amounts against the source.`;
      };
      list.addEventListener('click', (event) => {
        const choice = event.target.closest('[data-date-choice]'); if (!choice) return;
        const form = choice.closest('form'); form.elements.namedItem('documentDate').value = choice.dataset.dateChoice;
        choice.closest('[data-date-choices]').querySelectorAll('[data-date-choice]').forEach((button) => button.setAttribute('aria-pressed', String(button === choice)));
      });
      list.addEventListener('input', (event) => { const form = event.target.closest('[data-document-review]'); if (form && /^(subtotalCents|taxCents|totalCents)$/.test(event.target.name)) amountCheck(form); });
      new MutationObserver(() => list.querySelectorAll('[data-document-review]').forEach((form) => { if (!form.dataset.amountChecked) { form.dataset.amountChecked = '1'; amountCheck(form); } })).observe(list, {childList: true, subtree: true});
      const uploadForm = $('[data-native-document-upload]', body);
      const progress = $('[data-native-document-progress]', body);
      let companyWorkspace = {};
      const newOperationKey = () => Array.from(crypto.getRandomValues(new Uint8Array(32)), (value) => value.toString(16).padStart(2, '0')).join('');
      const openPrivateSource = async (document) => {
        // Reserve the tab during the click. Opening it after the private file
        // request resolves is treated as an unsolicited pop-up by browsers.
        const opened = window.open('about:blank', '_blank');
        if (opened) { opened.opener = null; opened.document.title = 'Loading private source…'; }
        try {
          const blob = await api('native-ap-ar/document/file', {params: {documentId: document.id}});
          if (!opened || opened.closed) { toast('Source ready', 'Your browser blocked the new tab. Allow pop-ups for Tegh and try again.', 'error'); return; }
          const objectUrl = URL.createObjectURL(blob);
          opened.location.replace(objectUrl);
          setTimeout(() => URL.revokeObjectURL(objectUrl), 300000);
        } catch (error) { opened?.close(); toast('Private source unavailable', userFacingError(error), 'error'); }
      };
      $('[data-manage-vendor-learning]', body)?.addEventListener('click', async (event) => {
        const trigger = event.currentTarget;trigger.disabled = true;try { const payload = await api('native-ap-ar/vendor-learning');const scrim = window.document.createElement('div');scrim.className = 'srp-modal-scrim tegh-vendor-learning-scrim';const rules = payload.rules || [];scrim.innerHTML = `<section class="srp-modal" role="dialog" aria-modal="true" aria-labelledby="vendor-learning-title"><header><h2 id="vendor-learning-title">Reviewed Vendor Associations</h2><button type="button" data-learning-close aria-label="Close">×</button></header><div class="tegh-vendor-learning-list">${rules.length ? rules.map((rule) => `<article data-learning-rule="${esc(rule.id)}"><div><b>${esc(rule.vendorName)}</b><span>${esc(String(rule.fingerprintType || '').replaceAll('_', ' '))} · ${esc(rule.displayHint || 'Privacy-minimized pattern')}</span><small>${(Number(rule.confidenceBps || 0) / 100).toFixed(0)}% · ${esc(rule.state)} · revision ${Number(rule.revision || 0)}</small></div><div class="srp-actions"><button type="button" class="srp-btn secondary" data-learning-action="disable" ${rule.state !== 'enabled' ? 'disabled' : ''}>Disable</button><button type="button" class="srp-btn secondary" data-learning-action="reset">Reset</button></div></article>`).join('') : '<p class="srp-empty">No reviewed associations have been learned yet.</p>'}</div></section>`;window.document.body.append(scrim);window.document.body.classList.add('srp-modal-open');const close = () => { scrim.remove();window.document.body.classList.remove('srp-modal-open');trigger.disabled = false;trigger.focus(); };$('[data-learning-close]', scrim).onclick = close;const onKey = (keyEvent) => { if (keyEvent.key === 'Escape') { window.document.removeEventListener('keydown', onKey);close(); } };window.document.addEventListener('keydown', onKey);$$('[data-learning-action]', scrim).forEach((button) => button.onclick = async () => { const row = button.closest('[data-learning-rule]'), reason = window.prompt(`Reason to ${button.dataset.learningAction} this learned association (at least 8 characters):`, 'Reviewed by Company Owner');if (reason === null) return;button.disabled = true;try { await api('native-ap-ar/vendor-learning', {method: 'POST', json: {ruleId: row.dataset.learningRule, action: button.dataset.learningAction, reason, operationKey: newOperationKey()}});toast('Association updated', 'The reviewed vendor-learning revision and history were updated.', 'success');close(); } catch (error) { toast('Association not updated', userFacingError(error), 'error');button.disabled = false; } });requestAnimationFrame(() => $('[data-learning-close]', scrim).focus()); } catch (error) { toast('Learned associations unavailable', userFacingError(error), 'error');trigger.disabled = false; }
      });

      const partyOptions = (document, candidate) => {
        const source = document.documentType === 'vendor_bill' ? (companyWorkspace.vendors || []) : (companyWorkspace.customers || []);
        const parties = source.filter((party) => party.active !== false && party.status !== 'inactive');
        const selected = String(candidate.partyId || document.suggestedPartyId || '');
        return `<option value="">Choose or create a vendor</option>${parties.map((party) => `<option value="${esc(party.id)}" ${selected === String(party.id) ? 'selected' : ''}>${esc(party.name)}</option>`).join('')}`;
      };

      const documentCard = (document) => {
        const candidate = document.candidate || {};
        const editable = !CLOSED_DOCUMENT_STATES.has(document.state);
        const verified = ['ready', 'prepared', 'linked'].includes(document.state);
        const actionLabel = document.documentType === 'vendor_bill' ? 'Vendor Invoice' : 'Customer Invoice';
        const suggestions = Array.isArray(document.vendorSuggestions) ? document.vendorSuggestions : [];
        const suggestionMarkup = document.documentType === 'vendor_bill' ? `<section class="tegh-vendor-suggestions" aria-label="Vendor suggestions"><h3>Vendor recognition</h3>${suggestions.length ? suggestions.map((item) => `<button type="button" data-vendor-suggestion="${esc(item.vendorId)}" class="${item.preselected ? 'is-preselected' : ''}"><b>${esc(item.vendorName)}</b><span>${(Number(item.scoreBps || 0) / 100).toFixed(0)}% · ${esc(item.reason || 'Current-company candidate')}</span></button>`).join('') : '<p>No unambiguous current-company vendor was found. Review the extracted identity or create the vendor.</p>'}<small>Amount and date are never vendor-match evidence. Only accepted or corrected associations are learned.</small></section>` : '';
        const accounts = (companyWorkspace.accounts || []).filter((account) => ['expense', 'asset'].includes(String(account.type || account.accountType)) && !account.isControl && account.active !== false);
        return `<article class="srp-card tegh-document-card state-${esc(document.state)}" data-native-document="${esc(document.id)}"><header><div><span class="srp-status ${esc(document.state)}">${esc(adapter.titleCasePhrase(document.state))}</span><h2>${esc(document.originalName)}</h2><p>${esc(actionLabel)} · ${(Number(document.sizeBytes || 0) / 1024).toFixed(1)} KB · SHA-256 ${esc(String(document.sha256 || '').slice(0, 12))}…</p></div><button class="srp-btn secondary" type="button" data-document-view-source>View Private Source</button></header>
          ${document.failureCode ? `<section class="tegh-ocr-recovery" role="status"><div><h3>Local extraction is temporarily unavailable</h3><p>Your source was saved securely for review. No accounting entry was created. You can enter the information manually or try extraction again.</p></div><div class="srp-actions"><button class="srp-btn" type="button" data-document-manual>Enter Fields Manually</button><button class="srp-btn secondary" type="button" data-document-retry-extraction>Try Extraction Again</button><button class="srp-btn secondary" type="button" data-document-view-source>View Source</button></div></section>` : ''}
          ${suggestionMarkup}
          ${editable ? `<form class="srp-form tegh-document-review" data-document-review><label>Verified current-company vendor<select name="partyId">${partyOptions(document, candidate)}</select></label><label>Supplier legal or trade name<input name="partyName" maxlength="160" value="${esc(candidate.partyName || '')}" required></label><label>Supplier email<input name="partyEmail" type="email" maxlength="254" value="${esc(candidate.partyEmail || '')}"></label><label>Business / tax identifier<input name="businessIdentifier" maxlength="120" value="${esc(candidate.businessIdentifier || '')}"></label><label class="full">Supplier address<input name="address" maxlength="500" value="${esc(candidate.address || '')}"></label><input name="layoutFingerprint" type="hidden" value="${esc(candidate.layoutFingerprint || '')}"><label>Payment terms<input name="paymentTerms" maxlength="120" value="${esc(candidate.paymentTerms || '')}"></label><label>Document number<input name="documentNumber" maxlength="80" value="${esc(candidate.documentNumber || '')}"></label><label>Document date<input name="documentDate" type="date" value="${esc(candidate.documentDate || '')}" required></label>${!candidate.documentDate && Array.isArray(candidate.alternatives) && candidate.alternatives.some((alt) => alt.documentDate) ? `<p class="full tegh-date-choices" data-date-choices><b>The date on this document can be read two ways.</b> Check the source and choose one: ${candidate.alternatives.filter((alt) => /^\d{4}-\d{2}-\d{2}$/.test(String(alt.documentDate || ''))).map((alt) => `<button type="button" class="srp-btn secondary" data-date-choice="${esc(alt.documentDate)}">${esc(new Intl.DateTimeFormat('en-CA', {month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC'}).format(new Date(alt.documentDate + 'T12:00:00Z')))}</button>`).join(' ')}</p>` : ''}<label>Due date<input name="dueDate" type="date" value="${esc(candidate.dueDate || '')}"></label><label>Subtotal<input name="subtotalCents" type="number" min="0" step="0.01" value="${esc(amountValue(candidate.subtotalCents))}"></label><label>Tax<input name="taxCents" type="number" min="0" step="0.01" value="${esc(amountValue(candidate.taxCents))}"></label><label>Total<input name="totalCents" type="number" min="0.01" step="0.01" value="${esc(amountValue(candidate.totalCents))}" required></label><p class="full tegh-amount-check" data-amount-check aria-live="polite"></p><label>Amount payable<input name="amountDueCents" type="number" min="0" step="0.01" value="${esc(amountValue(candidate.amountDueCents))}"></label><label>Currency<input name="currency" pattern="[A-Za-z]{3}" maxlength="3" value="${esc(candidate.currency || 'CAD')}" required></label><label>Local extraction confidence %<input name="confidence" type="number" min="0" max="100" step="1" value="${Math.round(Number(candidate.confidenceBps || 0) / 100)}"></label><label class="full">Memo<textarea name="memo" maxlength="500" rows="2">${esc(candidate.memo || '')}</textarea></label>${candidate.lineItems?.length?`<fieldset class="full"><legend>Products and services</legend>${candidate.lineItems.map(line=>`<div class="srp-form" data-intake-line><label>Description<input data-line-description value="${esc(line.description)}" maxlength="300"></label><label>Amount<input data-line-amount type="number" step="0.01" min="0" value="${esc(amountValue(line.amountCents))}"></label></div>`).join('')}</fieldset>`:''}<label class="full tegh-native-verify"><input name="verified" type="checkbox" ${verified ? 'checked' : ''}><span><b>I compared these fields with the private source.</b><small>Verification is required before Tegh can create a draft. It never posts, approves, pays or reconciles the invoice.</small></span></label><div class="full srp-actions"><button class="srp-btn secondary" type="submit">Save Human Review</button>${document.documentType === 'vendor_bill' ? '<button class="srp-btn secondary" type="button" data-document-create-vendor>Create Vendor</button>' : ''}${document.state === 'ready' && document.documentType !== 'vendor_bill' ? '<button class="srp-btn" type="button" data-document-prepare>Prepare Normal Workflow</button>' : ''}<button class="srp-btn danger" type="button" data-document-dismiss>Dismiss</button></div></form>` : ''}
          ${document.state === 'ready' && document.documentType === 'vendor_bill' ? `<form class="srp-form tegh-document-import" data-document-import-draft><h3 class="full">Create draft vendor invoice</h3><p class="full">Choose the accounting coding and tax treatment. Tegh must reproduce the reviewed exact subtotal, tax and total before creating one draft.</p><label class="grow">Expense or asset account<select name="categoryAccountId" required><option value="">Choose account</option>${accounts.map((account) => `<option value="${esc(account.id)}">${esc(account.code || '')} · ${esc(account.name)}</option>`).join('')}</select></label><label>Tax entry mode<select name="taxEntryMode"><option value="none">No tax</option><option value="exclusive">Tax added to subtotal</option><option value="inclusive">Tax included in total</option></select></label><label class="tegh-native-verify"><input type="checkbox" name="applyGstHst"><span>Apply GST/HST</span></label><label class="tegh-native-verify"><input type="checkbox" name="applyPst"><span>Apply PST</span></label><button class="srp-btn" type="submit">Create Draft Vendor Invoice</button></form>` : ''}
          ${document.state === 'prepared' ? `<section class="tegh-document-handoff"><div><h3>Workflow prepared — nothing saved to the books</h3><p>Open the prefilled protected workflow, review its account and tax treatment, then save there. After saving, link its current-company record ID for provenance.</p></div><button class="srp-btn" type="button" data-document-open-workflow>Open Prepared Workflow</button><form data-document-link><label>Saved ${document.documentType === 'vendor_bill' ? 'bill' : 'invoice'} ID<input name="targetId" maxlength="64" required></label><button class="srp-btn secondary" type="submit">Link Saved Record</button></form></section>` : ''}
          ${document.state === 'linked' ? `<p class="tegh-native-success"><b>Linked with provenance.</b> ${esc(adapter.titleCasePhrase(document.linkedEntityType || 'record'))} ${esc(document.linkedEntityId || '')} is connected to this private source.</p>` : ''}</article>`;
      };

      const openPreparedWorkflow = (document, prepared = null) => {
        const context = prepared?.workflowContext || {
          ...(document.candidate || {}),
          documentId: document.id,
          sourceRevisionHash: document.sourceRevisionHash,
          vendorId: document.candidate?.partyId || '',
          customerId: document.candidate?.partyId || '',
          vendorName: document.candidate?.partyName || '',
          customerName: document.candidate?.partyName || '',
        };
        if (document.documentType === 'vendor_bill') {
          openBills('new', '', {...context, number: context.documentNumber || '', billDate: context.documentDate || '', amountCents: Number(context.subtotalCents || context.totalCents || 0)});
        } else {
          openCustomerInvoice({...context, line: {description: context.memo || `Document ${context.documentNumber || ''}`.trim(), quantity: 1, unitPriceCents: Number(context.subtotalCents || context.totalCents || 0)}});
        }
      };

      const openCreateVendorDialog = (document, reviewForm) => {
        const candidate = candidateFromForm(reviewForm, document.candidate || {}), operationKey = newOperationKey(), priorFocus = window.document.activeElement;
        const scrim = window.document.createElement('div');scrim.className = 'srp-modal-scrim tegh-document-vendor-scrim';
        scrim.innerHTML = `<section class="srp-modal tegh-document-vendor-modal" role="dialog" aria-modal="true" aria-labelledby="tegh-document-vendor-title"><header><div><small>Current company only</small><h2 id="tegh-document-vendor-title">Create Vendor</h2></div><button type="button" data-vendor-close aria-label="Close">×</button></header><p>Review the extracted identity. Tegh will validate duplicates and return the new vendor to this same document. No invoice or accounting entry is created here.</p><form class="srp-form"><label>Vendor name<input name="name" maxlength="200" value="${esc(candidate.partyName || '')}" required></label><label>Email<input name="email" type="email" maxlength="254" value="${esc(candidate.partyEmail || '')}"></label><label class="full">Address<input name="address" maxlength="500" value="${esc(candidate.address || '')}"></label><label>Payment terms days<input name="defaultTermsDays" type="number" min="0" max="3650" value="30"></label><label>Currency<input name="currency" maxlength="3" pattern="[A-Za-z]{3}" value="${esc(candidate.currency || 'CAD')}" required></label><div class="full srp-actions"><button type="button" class="srp-btn secondary" data-vendor-close>Cancel</button><button type="submit" class="srp-btn">Create Vendor</button></div></form></section>`;
        window.document.body.append(scrim);window.document.body.classList.add('srp-modal-open');const modal = $('.srp-modal', scrim), vendorForm = $('form', scrim);
        const focusables = () => $$('button,input,select,textarea,[href]', modal).filter((element) => !element.disabled && !element.hidden);
        const close = () => { scrim.remove();window.document.body.classList.remove('srp-modal-open');window.document.removeEventListener('keydown', onKey);priorFocus?.focus?.(); };
        const onKey = (event) => { if (event.key === 'Escape') { event.preventDefault();close();return; }if (event.key === 'Tab') { const items = focusables();if (!items.length) return;const first = items[0], last = items.at(-1);if (event.shiftKey && window.document.activeElement === first) { event.preventDefault();last.focus(); } else if (!event.shiftKey && window.document.activeElement === last) { event.preventDefault();first.focus(); } } };
        window.document.addEventListener('keydown', onKey);$$('[data-vendor-close]', scrim).forEach((button) => button.onclick = close);scrim.onclick = (event) => { if (event.target === scrim) close(); };
        vendorForm.onsubmit = async (event) => { event.preventDefault();if (!vendorForm.reportValidity()) return;const button = event.submitter;button.disabled = true;try { const vendor = Object.fromEntries(new FormData(vendorForm));vendor.defaultTermsDays = Number(vendor.defaultTermsDays || 30);const result = await api('native-ap-ar/document/create-vendor', {method: 'POST', json: {documentId: document.id, sourceRevisionHash: document.sourceRevisionHash, operationKey, vendor}});toast('Vendor created', `${result.vendor.name} is selected for this reviewed document.`, 'success');close();await load(); } catch (error) { toast('Vendor not created', userFacingError(error), 'error');button.disabled = false; } };
        requestAnimationFrame(() => vendorForm.elements.namedItem('name')?.focus());
      };

      const wireDocuments = (documents) => {
        $$('[data-native-document]', list).forEach((card) => {
          const document = documents.find((item) => item.id === card.dataset.nativeDocument);
          if (!document) return;
          $$('[data-document-view-source]', card).forEach((button) => button.onclick = () => openPrivateSource(document));
          const form = $('[data-document-review]', card);
          if (form) {
            const party = form.elements.namedItem('partyId');
            party?.addEventListener('change', () => {
              const source = document.documentType === 'vendor_bill' ? (companyWorkspace.vendors || []) : (companyWorkspace.customers || []);
              const selected = source.find((item) => String(item.id) === String(party.value));
              if (selected) form.elements.namedItem('partyName').value = selected.name || '';
            });
            $$('[data-vendor-suggestion]', card).forEach((button) => button.onclick = () => { party.value = button.dataset.vendorSuggestion;party.dispatchEvent(new Event('change', {bubbles: true}));party.focus(); });
            $('[data-document-create-vendor]', card)?.addEventListener('click', () => openCreateVendorDialog(document, form));
            form.onsubmit = async (event) => {
              event.preventDefault();
              if (!form.reportValidity()) return;
              const button = event.submitter;
              button.disabled = true;
              try {
                await api('native-ap-ar/document/review', {method: 'POST', json: {documentId: document.id, extractionMethod: document.extractionMethod === 'none' ? 'manual' : document.extractionMethod, candidate: candidateFromForm(form, document.candidate || {}), verified: form.elements.namedItem('verified').checked}});
                toast('Human review saved', 'Only the reviewed candidate was stored. No raw OCR text or accounting record was saved.', 'success');
                await load();
              } catch (error) {
                toast('Review not saved', userFacingError(error), 'error');
                button.disabled = false;
              }
            };
          }
          $('[data-document-manual]', card)?.addEventListener('click', () => {
            const field = form?.elements.namedItem('partyName');
            field?.focus();
            field?.scrollIntoView({block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
          });
          $('[data-document-retry-extraction]', card)?.addEventListener('click', async (event) => {
            const button = event.currentTarget;button.disabled = true;
            try {
              const blob = await api('native-ap-ar/document/file', {params: {documentId: document.id}});
              const file = new File([blob], document.originalName, {type: document.mimeType || blob.type});
              const extraction = await window.TeghNativeOCR.extract(file);
              await api('native-ap-ar/document/review', {method: 'POST', json: {documentId: document.id, extractionMethod: extraction.extractionMethod, candidate: extraction.candidate, verified: false}});
              toast('Local extraction complete', 'Review every candidate field against the source. No raw extracted text was stored.', 'success');
              await load();
            } catch (error) {
              const failureCode = /^ocr_[a-z0-9_]+$/.test(String(error?.code || '')) ? String(error.code) : 'ocr_extraction_failed';
              try { await api('native-ap-ar/document/extraction-failure', {method: 'POST', json: {documentId: document.id, failureCode}}); } catch (_) {}
              toast('Local extraction is temporarily unavailable', 'Your source remains saved securely for manual review. No accounting entry was created.', 'error');
              button.disabled = false;
            }
          });
          $('[data-document-prepare]', card)?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
              const result = await api('native-ap-ar/document/prepare', {method: 'POST', json: {documentId: document.id, sourceRevisionHash: document.sourceRevisionHash}});
              toast('Protected workflow prepared', 'The source was revalidated and a normal editable workflow was prepared. Nothing was posted.', 'success');
              openPreparedWorkflow(document, result);
            } catch (error) {
              toast('Workflow not prepared', userFacingError(error), 'error');
              button.disabled = false;
            }
          });
          const importForm = $('[data-document-import-draft]', card);
          if (importForm) {
            const operationKey = newOperationKey();
            importForm.onsubmit = async (event) => { event.preventDefault();if (!importForm.reportValidity()) return;const button = event.submitter;button.disabled = true;const values = Object.fromEntries(new FormData(importForm));try { const result = await api('native-ap-ar/document/import-draft', {method: 'POST', json: {documentId: document.id, sourceRevisionHash: document.sourceRevisionHash, operationKey, categoryAccountId: values.categoryAccountId, taxEntryMode: values.taxEntryMode, applyGstHst: !!importForm.elements.namedItem('applyGstHst').checked, applyPst: !!importForm.elements.namedItem('applyPst').checked}});toast('Draft vendor invoice created', `${result.bill.number} was created as one editable draft. Nothing was posted or paid.`, 'success');await load(); } catch (error) { toast('Draft not created', userFacingError(error), 'error');button.disabled = false; } };
          }
          $('[data-document-open-workflow]', card)?.addEventListener('click', () => openPreparedWorkflow(document));
          $('[data-document-link]', card)?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const button = $('button', form);
            button.disabled = true;
            try {
              await api('native-ap-ar/document/link', {method: 'POST', json: {documentId: document.id, targetId: form.elements.namedItem('targetId').value, sourceRevisionHash: document.sourceRevisionHash}});
              toast('Source linked', 'The saved record is now linked to this private source without changing its accounting values.', 'success');
              await load();
            } catch (error) {
              toast('Source not linked', userFacingError(error), 'error');
              button.disabled = false;
            }
          });
          $('[data-document-dismiss]', card)?.addEventListener('click', async (event) => {
            if (!confirm('Dismiss this private document? The stored source remains governed by the company retention policy, and no accounting record will change.')) return;
            event.currentTarget.disabled = true;
            try {
              await api('native-ap-ar/document/dismiss', {method: 'POST', json: {documentId: document.id}});
              await load();
            } catch (error) {
              toast('Document not dismissed', userFacingError(error), 'error');
              event.currentTarget.disabled = false;
            }
          });
        });
      };

      const load = async () => {
        list.setAttribute('aria-busy', 'true');
        try {
          const [response, currentWorkspace] = await Promise.all([api('native-ap-ar/documents'), workspace()]);
          companyWorkspace = currentWorkspace || {};
          const documents = response.documents || [];
          await Promise.all(documents.filter((document) => document.documentType === 'vendor_bill' && !CLOSED_DOCUMENT_STATES.has(document.state)).map(async (document) => {
            try { const ranked = await api('native-ap-ar/document/vendor-suggestions', {params: {documentId: document.id}});document.vendorSuggestions = ranked.suggestions || [];document.suggestedPartyId = ranked.preselectedVendorId || ''; }
            catch (_) { document.vendorSuggestions = [];document.suggestedPartyId = ''; }
          }));
          list.innerHTML = documents.length ? documents.map(documentCard).join('') : '<section class="srp-card srp-empty"><b>No private documents yet.</b><p>Upload a vendor bill or customer invoice to begin a local, human-reviewed intake.</p></section>';
          list.setAttribute('aria-busy', 'false');
          wireDocuments(documents);
          const focus = options.documentId ? list.querySelector(`[data-native-document="${CSS.escape(String(options.documentId))}"]`) : null;
          if(focus){if(window.TeghActivityShell){focus.tabIndex=-1;focus.focus({preventScroll:true});}else focus.scrollIntoView({block:'center'});}
        } catch (error) {
          list.setAttribute('aria-busy', 'false');
          list.innerHTML = `<section class="srp-card tegh-agent-error" role="alert"><h2>Private documents unavailable</h2><p>${esc(userFacingError(error))}</p><button class="srp-btn secondary" data-document-retry>Retry</button></section>`;
          $('[data-document-retry]', list).onclick = load;
        }
      };

      uploadForm.onsubmit = async (event) => {
        event.preventDefault();
        if (!uploadForm.reportValidity()) return;
        const file = uploadForm.elements.namedItem('file').files?.[0];
        const button = $('button[type="submit"]', uploadForm);
        button.disabled = true;
        progress.hidden = false;
        const setProgress = ({status, progress: value, detail}) => {
          $('span', progress).style.setProperty('--tegh-progress', `${Math.round(Number(value || 0) * 100)}%`);
          $('b', progress).textContent = detail || adapter.titleCasePhrase(status || 'working');
        };
        let uploaded = null;
        try {
          const payload = new FormData();
          payload.append('documentType', uploadForm.elements.namedItem('documentType').value);
          payload.append('file', file, file.name);
          $('b', progress).textContent = 'Saving the private source…';
          uploaded = await api('native-ap-ar/document/upload', {method: 'POST', body: payload});
          let extraction = null;
          try {
            if (!window.TeghNativeOCR?.extract) throw new Error('The bundled local extraction runtime is not ready.');
            extraction = await window.TeghNativeOCR.extract(file, {onProgress: setProgress});
            await api('native-ap-ar/document/review', {method: 'POST', json: {documentId: uploaded.document.id, extractionMethod: extraction.extractionMethod, candidate: extraction.candidate, verified: false}});
            extraction = null;
            toast('Local extraction complete', 'Review every candidate field against the source. Raw extracted text was discarded from browser memory and never sent.', 'success');
          } catch (error) {
            const failureCode = /^ocr_[a-z0-9_]+$/.test(String(error?.code || '')) ? String(error.code) : 'ocr_extraction_failed';
            try { await api('native-ap-ar/document/extraction-failure', {method: 'POST', json: {documentId: uploaded.document.id, failureCode}}); } catch (_) {}
            toast('Local extraction is temporarily unavailable', 'Your source was saved securely for review. No accounting entry was created. You can enter the information manually or try extraction again.', 'error');
          }
          uploadForm.reset();
          await load();
        } catch (error) {
          toast('Document not uploaded', userFacingError(error), 'error');
        } finally {
          button.disabled = false;
          progress.hidden = true;
        }
      };
      await load();
    }, {module: 'Payables', back: () => moduleDashboard('Payables'), route: 'document-intake'});
  }

  function openCollectionTemplates(adapter, options = {}) {
    const {$, $$, api, esc, toast, userFacingError, showPage} = adapter;
    showPage('collection-message-templates', 'Collection Message Templates', 'Company-controlled, revisioned message copy for Friendly, Firm, and Final collection drafts.', async (body) => {
      let payload = await api('native-ap-ar/collections/templates');
      let tone = String(options.tone || 'friendly'), length = String(options.messageLength || 'standard');
      const current = () => (payload.templates || []).find((item) => item.tone === tone && item.messageLength === length);
      const sample = {customer_contact: 'Maya Singh', customer_name: 'Northstar Design Studio Inc.', invoice_number: 'INV-1042', invoice_date: '2026-07-01', due_date: '2026-07-31', days_overdue: '30', open_balance: '1,250.00', currency: 'CAD', company_name: 'Your Company', payment_instructions: 'Use the approved payment instructions shown on the invoice.', business_email: 'accounts@yourcompany.ca', business_phone: '416-555-0100'};
      const preview = (text) => String(text || '').split(/\r?\n/).filter((line) => { const names = [...line.matchAll(/\{\{\s*([a-z_]+)\s*\}\}/gi)].map((match) => match[1].toLowerCase());return !names.some((name) => !String(sample[name] || '').trim()); }).join('\n').replace(/\{\{\s*([a-z_]+)\s*\}\}/gi, (_, name) => sample[String(name).toLowerCase()] || '').replace(/\n{3,}/g, '\n\n').trim();
      const render = () => {
        const template = current();if (!template) return;
        body.innerHTML = `<section class="tegh-template-hero"><div><span class="srp-sites-eyebrow">Sales & Purchases</span><h2>Professional reminders, controlled by your company.</h2><p>Every save creates a new immutable revision. Existing drafts retain their exact template snapshot.</p></div><button class="srp-btn secondary" type="button" data-template-back>Back to Collection Drafts</button></section><section class="srp-card"><form class="srp-form tegh-template-editor" data-template-form><label>Tone<select name="tone"><option value="friendly" ${tone === 'friendly' ? 'selected' : ''}>Friendly</option><option value="firm" ${tone === 'firm' ? 'selected' : ''}>Firm</option><option value="final" ${tone === 'final' ? 'selected' : ''}>Final</option></select></label><label>Length<select name="messageLength"><option value="concise" ${length === 'concise' ? 'selected' : ''}>Concise</option><option value="standard" ${length === 'standard' ? 'selected' : ''}>Standard</option><option value="detailed" ${length === 'detailed' ? 'selected' : ''}>Detailed</option></select></label><span class="srp-status active">Revision ${Number(template.revision || 0) || 'protected default'}</span><label class="full">Subject<input name="subjectTemplate" maxlength="240" value="${esc(template.subjectTemplate)}" required></label><label class="full">Body <span data-template-word-count></span><textarea name="bodyTemplate" rows="14" maxlength="6000" required>${esc(template.bodyTemplate)}</textarea></label><label class="full">Signature / contact block<textarea name="signatureBlock" rows="3" maxlength="1000">${esc(template.signatureBlock || '')}</textarea></label><label class="full">Approved payment instructions<textarea name="paymentInstructions" rows="3" maxlength="1000">${esc(template.paymentInstructions || '')}</textarea></label><label class="full">Revision note<input name="changeNote" maxlength="500" placeholder="What changed and why"></label><fieldset class="full tegh-placeholder-picker"><legend>Insert an allowlisted placeholder</legend>${(payload.placeholders || []).map((name) => `<button type="button" data-placeholder="${esc(name)}">${esc(name.replaceAll('_', ' '))}</button>`).join('')}</fieldset><div class="full srp-actions"><button class="srp-btn" type="submit">Save New Revision</button><button class="srp-btn secondary" type="button" data-template-restore>Restore Protected Default</button></div></form></section><section class="srp-card tegh-template-preview"><div class="srp-section-title"><div><small>Live sample</small><h2>Subject and Body Preview</h2><p>Sample facts demonstrate placeholder behavior; a real draft always uses its current invoice snapshot.</p></div></div><h3 data-template-preview-subject></h3><pre data-template-preview-body></pre><p data-template-preview-warning></p></section><section class="srp-card"><div class="srp-section-title"><div><small>Immutable company history</small><h2>Recent Revisions</h2></div></div><div class="tegh-template-history">${(payload.history || []).filter((item) => item.tone === tone && item.messageLength === length).map((item) => `<article><b>Revision ${Number(item.revision)}</b><span>${esc(item.changeNote || 'Updated')}</span><small>${esc(item.createdAt || '')}</small></article>`).join('') || '<p>No company revisions yet. The protected Tegh default is active.</p>'}</div></section>`;
        const form = $('[data-template-form]', body), updatePreview = () => { const subject = preview(form.subjectTemplate.value), message = preview(form.bodyTemplate.value + (form.signatureBlock.value.trim() ? `\n\n${form.signatureBlock.value}` : ''));$('[data-template-preview-subject]', body).textContent = subject;$('[data-template-preview-body]', body).textContent = message;const words = message.split(/\s+/).filter(Boolean).length;$('[data-template-word-count]', body).textContent = `${words} preview words`;$('[data-template-preview-warning]', body).textContent = length === 'standard' && (words < 100 || words > 170) ? 'Standard messages should normally contain 100–170 words when facts are available.' : 'All sample placeholders are available.'; };
        ['input','change'].forEach((name) => form.addEventListener(name, updatePreview));form.tone.onchange = () => {tone = form.tone.value;render();};form.messageLength.onchange = () => {length = form.messageLength.value;render();};
        $$('[data-placeholder]', form).forEach((button) => button.onclick = () => {const target = document.activeElement?.form === form && /input|textarea/i.test(document.activeElement.tagName) ? document.activeElement : form.bodyTemplate;const token = `{{${button.dataset.placeholder}}}`;const start = target.selectionStart ?? target.value.length;target.setRangeText(token, start, target.selectionEnd ?? start, 'end');target.focus();updatePreview();});
        form.onsubmit = async (event) => {event.preventDefault();if (!form.reportValidity()) return;const button = event.submitter;button.disabled = true;try {await api('native-ap-ar/collections/templates', {method: 'POST', json: {...Object.fromEntries(new FormData(form)), expectedRevision: Number(template.revision || 0)}});payload = await api('native-ap-ar/collections/templates');toast('Collection template revised', 'The new company revision is active. Existing drafts were not changed.', 'success');render();} catch (error) {toast('Template not saved', userFacingError(error), 'error');button.disabled = false;}};
        $('[data-template-restore]', body).onclick = async (event) => {if (!confirm('Create a new revision from the protected Tegh default for this tone and length?')) return;event.currentTarget.disabled = true;try {await api('native-ap-ar/collections/templates', {method: 'POST', json: {action: 'restore', tone, messageLength: length, expectedRevision: Number(template.revision || 0)}});payload = await api('native-ap-ar/collections/templates');toast('Protected default restored', 'A new immutable revision was created. Existing drafts were not changed.', 'success');render();} catch (error) {toast('Default not restored', userFacingError(error), 'error');event.currentTarget.disabled = false;}};
        $('[data-template-back]', body).onclick = () => openCollectionDrafts(adapter);updatePreview();
      };render();
    }, {module: 'Settings', back: () => openCollectionDrafts(adapter), route: 'collection-message-templates'});
  }

  function openCollectionDrafts(adapter, options = {}) {
    const {$, $$, api, esc, money, date, toast, userFacingError, workspace, showPage, moduleDashboard, openInvoices} = adapter;
    showPage('collection-drafts', 'Collection Drafts', 'Review, edit, approve and explicitly send deterministic customer follow-ups.', async (body) => {
      if (adapter.isConsolidated()) {
        body.innerHTML = '<section class="srp-card"><div class="srp-empty">Select one company before preparing or sending a collection draft.</div></section>';
        return;
      }
      body.innerHTML = `<section class="tegh-native-boundary collections"><div><span>Explicit-send boundary</span><h2>Draft first. Review exact content. Send only when you say so.</h2><p>Messages use current invoice facts and company-controlled templates. Any content or source change invalidates approval. Ambiguous delivery is never retried automatically.</p></div><dl><div><dt>Automatic sends</dt><dd>0</dd></div><div><dt>Invoice writes</dt><dd>0</dd></div><div><dt>Channel</dt><dd>Email</dd></div></dl></section><section class="srp-card"><div class="srp-section-title"><div><small>Prepare from authoritative invoice facts</small><h2>New Collection Draft</h2></div><button class="srp-btn secondary" type="button" data-collection-templates>Collection Message Templates</button></div><form class="srp-form tegh-collection-prepare" data-collection-prepare><label class="grow">Overdue customer invoice<select name="invoiceId" required><option value="">Loading eligible invoices…</option></select></label><label>Tone<select name="level"><option value="">Policy recommendation</option><option value="friendly">Friendly</option><option value="firm">Firm</option><option value="final">Final</option></select></label><label>Length<select name="messageLength"><option value="concise">Concise</option><option value="standard" selected>Standard</option><option value="detailed">Detailed</option></select></label><button class="srp-btn" type="submit">Prepare Draft</button></form><p class="srp-note">Standard is the recommended 100–170 word message when source facts are available. Preparation never sends mail or changes the invoice.</p></section><nav class="tegh-collection-tabs" aria-label="Collection draft status"><button type="button" data-collection-tab="review">Needs Review</button><button type="button" data-collection-tab="approved">Approved</button><button type="button" data-collection-tab="delivery">Delivery Review</button><button type="button" data-collection-tab="sent">Sent</button><button type="button" data-collection-tab="dismissed">Dismissed</button></nav><section data-collection-list aria-live="polite" aria-busy="true"><div class="srp-card srp-empty">Loading collection drafts…</div></section>`;
      const list = $('[data-collection-list]', body);
      const prepareForm = $('[data-collection-prepare]', body);
      let activeTab = String(options.tab || 'review');

      const draftCard = (draft) => {
        const editable = ['draft', 'failed'].includes(draft.state);
        const dismissible = ['draft', 'approved', 'failed', 'stale'].includes(draft.state);
        const facts = draft.sourceFacts || {};
        const missing = [['payment_instructions','payment instructions'],['business_email','business email'],['business_phone','business phone']].filter(([key]) => !String(facts[key] || '').trim()).map(([,label]) => label);
        return `<article class="srp-card tegh-collection-card state-${esc(draft.state)}" data-collection-draft="${esc(draft.id)}"><header><div><span class="srp-status ${esc(draft.state)}">${esc(adapter.titleCasePhrase(draft.state))}</span><h2>${esc(draft.customerName)} · ${esc(draft.invoiceNumber)}</h2><p>Due ${date(draft.dueDate)} · ${money(draft.balanceCents, draft.currency)} open · ${esc(adapter.titleCasePhrase(draft.level))}</p></div><button class="srp-btn secondary" type="button" data-collection-invoice>Open Invoice</button></header>
          <section class="tegh-collection-facts"><div><b>Source facts</b><span>Invoice ${esc(draft.invoiceNumber)}</span><span>${esc(String(facts.days_overdue || '—'))} days overdue</span><span>${esc(draft.currency)} ${esc(String(facts.open_balance || (Number(draft.balanceCents || 0) / 100).toFixed(2)))}</span></div><div><b>Template snapshot</b><span>${esc(adapter.titleCasePhrase(draft.level))} · ${esc(adapter.titleCasePhrase(draft.messageLength || 'standard'))}</span><span>Revision ${Number(draft.templateRevision || 0) || 'protected default'}</span><span>${Number(draft.wordCount || 0)} words</span></div></section>
          ${missing.length ? `<p class="tegh-native-warning"><b>Optional source fields omitted:</b> ${esc(missing.join(', '))}. Empty optional lines were removed from the message.</p>` : ''}
          ${editable ? `<form class="srp-form tegh-collection-editor" data-collection-edit><label>Tone<select name="level"><option value="friendly" ${draft.level === 'friendly' ? 'selected' : ''}>Friendly</option><option value="firm" ${draft.level === 'firm' ? 'selected' : ''}>Firm</option><option value="final" ${draft.level === 'final' ? 'selected' : ''}>Final</option></select></label><label>Length<select name="messageLength"><option value="concise" ${draft.messageLength === 'concise' ? 'selected' : ''}>Concise</option><option value="standard" ${draft.messageLength === 'standard' ? 'selected' : ''}>Standard</option><option value="detailed" ${draft.messageLength === 'detailed' ? 'selected' : ''}>Detailed</option></select></label><label>Recipient<input name="recipientEmail" type="email" maxlength="254" value="${esc(draft.recipientEmail)}" required></label><label class="full">Subject<input name="subject" maxlength="240" value="${esc(draft.subject)}" required></label><label class="full">Exact message <span class="tegh-word-count">${Number(draft.wordCount || 0)} words</span><textarea name="body" maxlength="4000" rows="12" required>${esc(draft.body)}</textarea></label><div class="full srp-actions"><button class="srp-btn secondary" type="submit">Save Draft</button><button class="srp-btn" type="button" data-collection-approve>Approve Exact Content</button>${dismissible ? '<button class="srp-btn danger" type="button" data-collection-dismiss>Dismiss</button>' : ''}</div></form>` : ''}
          ${draft.state === 'approved' ? `<section class="tegh-collection-send"><div><h3>Approved content is frozen</h3><p>Type this exact confirmation:</p><code>${esc(draft.confirmationText)}</code></div><form data-collection-send><label>Exact confirmation<input name="confirmation" autocomplete="off" required></label><button class="srp-btn danger" type="submit">Send Approved Email</button><button class="srp-btn secondary" type="button" data-collection-dismiss>Dismiss Instead</button></form></section>` : ''}
          ${draft.state === 'sending' ? `<section class="tegh-delivery-review"><p class="tegh-native-warning"><b>Delivery needs manual review.</b> Tegh submitted the message but did not receive a final delivery decision. Automatic retry is disabled to prevent duplicate email.</p><div class="srp-actions"><button class="srp-btn secondary" type="button" data-delivery-record>Review Outbound Record</button></div><div data-delivery-details></div><details><summary>Confirm the external outcome</summary><p>Check the recipient mailbox or provider records first. Preparing another delivery may cause a duplicate.</p><form class="srp-form" data-delivery-recovery="confirm_sent_external"><label class="grow">Reason / evidence<input name="reason" minlength="10" maxlength="500" required></label><label>Type <b>CONFIRM SENT EXTERNALLY</b><input name="acknowledgement" autocomplete="off" required></label><button class="srp-btn" type="submit">Confirm Sent Externally</button></form><form class="srp-form" data-delivery-recovery="confirm_not_received"><label class="grow">Reason / evidence<input name="reason" minlength="10" maxlength="500" required></label><label>Type <b>CONFIRM NOT RECEIVED AND PREPARE NEW DELIVERY</b><input name="acknowledgement" autocomplete="off" required></label><button class="srp-btn danger" type="submit">Prepare Separate New Delivery</button></form></details></section>` : ''}
          ${draft.state === 'sent' ? `<p class="tegh-native-success"><b>${draft.deliveryOutcome === 'diagnostic_warning' ? 'Sent with a diagnostic warning.' : 'Confirmed sent.'}</b> Accepted ${date(draft.sentAt)}. Tegh will not retry this message. The invoice itself was not changed.</p>` : ''}
          ${draft.state === 'stale' ? `<p class="tegh-native-warning"><b>Source changed.</b> The invoice or customer changed after this draft was built. Prepare a fresh draft; this copy cannot be approved or sent.</p>${dismissible ? '<button class="srp-btn secondary" type="button" data-collection-dismiss>Dismiss Stale Draft</button>' : ''}` : ''}
          ${draft.failureMessage ? `<p class="tegh-native-warning">${esc(draft.failureMessage)}</p>` : ''}</article>`;
      };

      const wireDrafts = (drafts) => {
        $$('[data-collection-draft]', list).forEach((card) => {
          const draft = drafts.find((item) => item.id === card.dataset.collectionDraft);
          if (!draft) return;
          $('[data-collection-invoice]', card).onclick = () => openInvoices({focusId: draft.invoiceId});
          const editForm = $('[data-collection-edit]', card);
          if (editForm) {
            const messageField = editForm.elements.namedItem('body');
            messageField?.addEventListener('input', () => {
              const count = String(messageField.value || '').trim().split(/\s+/).filter(Boolean).length;
              $('.tegh-word-count', editForm).textContent = `${count} words`;
            });
            editForm.onsubmit = async (event) => {
              event.preventDefault();
              if (!editForm.reportValidity()) return;
              const button = event.submitter;
              button.disabled = true;
              const data = Object.fromEntries(new FormData(editForm));
              try {
                await api('native-ap-ar/collections/draft', {method: 'PUT', json: {draftId: draft.id, level: data.level, messageLength: data.messageLength, recipientEmail: data.recipientEmail, subject: data.subject, body: data.body}});
                toast('Collection draft saved', 'Approval is cleared whenever content changes. Nothing was sent.', 'success');
                await load();
              } catch (error) {
                toast('Draft not saved', userFacingError(error), 'error');
                button.disabled = false;
              }
            };
            $('[data-collection-approve]', card).onclick = async (event) => {
              if (!confirm('Approve this exact recipient, subject and message for a later explicit Send action?')) return;
              event.currentTarget.disabled = true;
              try {
                await api('native-ap-ar/collections/approve', {method: 'POST', json: {draftId: draft.id, contentHash: draft.contentHash}});
                toast('Exact content approved', 'The email is still unsent. Type the displayed confirmation to send it.', 'success');
                await load();
              } catch (error) {
                toast('Draft not approved', userFacingError(error), 'error');
                event.currentTarget.disabled = false;
              }
            };
          }
          $('[data-collection-send]', card)?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            if (!form.reportValidity()) return;
            if (!confirm('Send this approved email now? Tegh will record the delivery outcome and will not retry an ambiguous result.')) return;
            const button = $('button[type="submit"]', form);
            button.disabled = true;
            try {
              await api('native-ap-ar/collections/send', {method: 'POST', json: {draftId: draft.id, confirmation: form.elements.namedItem('confirmation').value}});
              toast('Collection email sent', 'The confirmed outbound email and follow-up were recorded. The invoice was not changed.', 'success');
              await load();
            } catch (error) {
              toast('Send requires review', userFacingError(error), 'error');
              await load();
            }
          });
          $('[data-delivery-record]', card)?.addEventListener('click', async (event) => {
            const button = event.currentTarget;button.disabled = true;const host = $('[data-delivery-details]', card);
            try {
              const result = await api('native-ap-ar/collections/delivery', {params: {draftId: draft.id}});const attempt = result.attempt || {};
              host.innerHTML = `<dl class="tegh-delivery-evidence"><div><dt>Outcome</dt><dd>${esc(adapter.titleCasePhrase(attempt.outcome || 'unknown'))}</dd></div><div><dt>Acceptance certainty</dt><dd>${esc(adapter.titleCasePhrase(attempt.acceptanceCertainty || 'unknown'))}</dd></div><div><dt>SMTP stage</dt><dd>${esc(adapter.titleCasePhrase(attempt.stage || 'unknown'))}</dd></div><div><dt>Reply</dt><dd>${attempt.smtpCode ? esc(String(attempt.smtpCode)) : 'No conclusive reply'}${attempt.enhancedCode ? ` · ${esc(attempt.enhancedCode)}` : ''}</dd></div><div class="full"><dt>Sanitized diagnostic</dt><dd>${esc(attempt.diagnostic || 'No additional diagnostic was retained.')}</dd></div></dl>`;
            } catch (error) { host.innerHTML = `<p class="tegh-native-warning">${esc(userFacingError(error))}</p>`; }
            finally { button.disabled = false; }
          });
          $$('[data-delivery-recovery]', card).forEach((form) => form.addEventListener('submit', async (event) => {
            event.preventDefault();if (!form.reportValidity()) return;const action = form.dataset.deliveryRecovery;
            if (action === 'confirm_not_received' && !confirm('Preparing another delivery may duplicate the message. Continue only after checking external records.')) return;
            const button = $('button[type="submit"]', form);button.disabled = true;const data = Object.fromEntries(new FormData(form));
            try { const result = await api('native-ap-ar/collections/recovery', {method: 'POST', json: {draftId: draft.id, action, reason: data.reason, acknowledgement: data.acknowledgement}});toast(action === 'confirm_sent_external' ? 'External delivery confirmed' : 'Separate draft prepared', action === 'confirm_sent_external' ? 'The manual confirmation and evidence were audited. No retry was made.' : 'The ambiguous record remains preserved. Review and approve the separate draft before any new send.', 'success');options = {...options, draftId: result.newDraft?.id || draft.id, tab: action === 'confirm_sent_external' ? 'sent' : 'review'};activeTab = options.tab;await load(); }
            catch (error) { toast('Recovery not recorded', userFacingError(error), 'error');button.disabled = false; }
          }));
          $$('[data-collection-dismiss]', card).forEach((button) => button.onclick = async () => {
            if (!confirm('Dismiss this collection draft? No email will be sent and the invoice will not change.')) return;
            button.disabled = true;
            try {
              await api('native-ap-ar/collections/dismiss', {method: 'POST', json: {draftId: draft.id}});
              await load();
            } catch (error) {
              toast('Draft not dismissed', userFacingError(error), 'error');
              button.disabled = false;
            }
          });
        });
      };

      const load = async () => {
        list.setAttribute('aria-busy', 'true');
        try {
          const [response, currentWorkspace] = await Promise.all([api('native-ap-ar/collections'), workspace()]);
          const drafts = response.drafts || [];
          const tabStates = {review: ['draft','failed','stale'], approved: ['approved'], delivery: ['sending'], sent: ['sent'], dismissed: ['dismissed']};
          if (!tabStates[activeTab]) activeTab = 'review';
          $$('[data-collection-tab]', body).forEach((button) => { const count = drafts.filter((draft) => (tabStates[button.dataset.collectionTab] || []).includes(draft.state)).length;button.textContent = `${button.dataset.collectionTab === 'review' ? 'Needs Review' : button.dataset.collectionTab === 'delivery' ? 'Delivery Review' : adapter.titleCasePhrase(button.dataset.collectionTab)} (${count})`;button.setAttribute('aria-selected', String(button.dataset.collectionTab === activeTab));button.classList.toggle('active', button.dataset.collectionTab === activeTab); });
          const visibleDrafts = drafts.filter((draft) => tabStates[activeTab].includes(draft.state));
          const invoices = (currentWorkspace.invoices || []).filter((invoice) => invoice.status === 'sent' && Number(invoice.balanceCents || 0) > 0 && String(invoice.dueDate || '') < adapter.todayIso());
          prepareForm.elements.namedItem('invoiceId').innerHTML = `<option value="">Choose an overdue invoice</option>${invoices.map((invoice) => `<option value="${esc(invoice.id)}" ${String(options.invoiceId || '') === String(invoice.id) ? 'selected' : ''}>${esc(invoice.customerName)} · ${esc(invoice.number)} · ${money(invoice.foreignBalanceCents || invoice.balanceCents, invoice.currency)}</option>`).join('')}`;
          prepareForm.elements.namedItem('level').value = String(options.level || '');
          prepareForm.elements.namedItem('messageLength').value = String(options.messageLength || 'standard');
          list.innerHTML = visibleDrafts.length ? visibleDrafts.map(draftCard).join('') : `<section class="srp-card srp-empty"><b>No ${esc(activeTab === 'review' ? 'drafts need review' : adapter.titleCasePhrase(activeTab).toLowerCase() + ' drafts')}.</b><p>Use the status tabs to review another stage. Preparing a draft never sends an email.</p></section>`;
          list.setAttribute('aria-busy', 'false');
          wireDrafts(visibleDrafts);
          const focus = options.draftId ? list.querySelector(`[data-collection-draft="${CSS.escape(String(options.draftId))}"]`) : null;
          if(focus){if(window.TeghActivityShell){focus.tabIndex=-1;focus.focus({preventScroll:true});}else focus.scrollIntoView({block:'center'});}
        } catch (error) {
          list.setAttribute('aria-busy', 'false');
          list.innerHTML = `<section class="srp-card tegh-agent-error" role="alert"><h2>Collection drafts unavailable</h2><p>${esc(userFacingError(error))}</p><button class="srp-btn secondary" data-collection-retry>Retry</button></section>`;
          $('[data-collection-retry]', list).onclick = load;
        }
      };

      $$('[data-collection-tab]', body).forEach((button) => button.addEventListener('click', () => { activeTab = button.dataset.collectionTab;options = {...options, tab: activeTab};void load(); }));
      $('[data-collection-templates]', body).onclick = () => openCollectionTemplates(adapter);

      prepareForm.onsubmit = async (event) => {
        event.preventDefault();
        if (!prepareForm.reportValidity()) return;
        const button = $('button[type="submit"]', prepareForm);
        const data = Object.fromEntries(new FormData(prepareForm));
        button.disabled = true;
        try {
          const result = await api('native-ap-ar/collections/prepare', {method: 'POST', json: {invoiceId: data.invoiceId, level: data.level, messageLength: data.messageLength}});
          options = {...options, draftId: result.draft?.id || ''};
          activeTab = 'review';
          toast('Collection draft prepared', `${result.draft?.wordCount || 0} words are ready for exact review. No email was sent and no invoice was changed.`, 'success');
          await load();
        } catch (error) {
          toast('Draft not prepared', userFacingError(error), 'error');
        } finally {
          button.disabled = false;
        }
      };
      await load();
    }, {module: 'Receivables', back: () => moduleDashboard('Receivables'), route: 'collection-drafts'});
  }

  window.TeghNativeAPAR = Object.freeze({version: VERSION, build: BUILD, localOnly: true, openDocumentIntake, openCollectionDrafts, openCollectionTemplates});
})();
