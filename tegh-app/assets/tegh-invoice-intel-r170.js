/*
 * R170 Invoice Intelligence (browser side).
 *
 * 1. Engine: turns the pages read by TeghNativeOCR.readDocumentPages() into one or more invoices (a PDF may hold
 *    several), with every field's printed text, page, position and OCR confidence kept as evidence.
 * 2. Worker: this page claims reading jobs from the server with a lease, reads the file in this browser (no outside
 *    service), and submits the result. If the tab closes the lease runs out and the job is read again later.
 * 3. Screens: the queue (upload, filters, statuses) and the review screen (the PDF beside the editable invoice).
 *
 * Document text is data, never instructions: it is only shown (escaped) and compared, never executed or obeyed.
 */
(() => {
  'use strict';
  if (window.TeghInvoiceIntel) return;

  const OCR = () => {
    if (!window.TeghNativeOCR?.readDocumentPages) throw new Error('The local document reader did not load. Refresh the page.');
    return window.TeghNativeOCR;
  };
  const fold = value => String(value || '').normalize('NFD').replace(/[̀-ͯ]/g, '');
  const clamp = (n, lo, hi) => Math.max(lo, Math.min(hi, n));

  /* ------------------------------------------------------------------ engine */

  // Text rows of a page with their position (normalised box) and the mean OCR confidence of their words.
  function rowsOf(page) {
    const runs = (page.items || []).filter(item => String(item.str || '').trim()).map((item, index) => ({
      str: String(item.str).trim(), x: Number(item.transform?.[4] || 0), y: Number(item.transform?.[5] || 0), h: Math.abs(Number(item.height || 10)) || 10,
      box: item.box || null, conf: Number.isFinite(item.conf) ? item.conf : null, index}));
    const rows = [];
    for (const run of runs) {
      const tolerance = Math.max(2, Math.min(6, run.h * 0.35));
      let row = rows.find(r => Math.abs(r.y - run.y) <= tolerance);
      if (!row) { row = {y: run.y, h: run.h, runs: []}; rows.push(row); }
      row.runs.push(run);
    }
    rows.sort((a, b) => b.y - a.y);
    return rows.map(row => {
      row.runs.sort((a, b) => a.x - b.x || a.index - b.index);
      const boxes = row.runs.map(r => r.box).filter(Boolean);
      const box = boxes.length ? [Math.min(...boxes.map(b => b[0])), Math.min(...boxes.map(b => b[1])), 0, 0] : null;
      if (box) { box[2] = Math.max(...boxes.map(b => b[0] + b[2])) - box[0]; box[3] = Math.max(...boxes.map(b => b[1] + b[3])) - box[1]; }
      const confs = row.runs.map(r => r.conf).filter(c => c !== null);
      return {page: page.page, y: row.y, h: row.h, text: row.runs.map(r => r.str).join(' ').replace(/\s+/g, ' ').trim(), runs: row.runs, box,
        conf: confs.length ? Math.round(confs.reduce((a, b) => a + b, 0) / confs.length) : null};
    });
  }

  const moneyStrings = cents => {
    const abs = Math.abs(cents), whole = Math.floor(abs / 100), frac = String(abs % 100).padStart(2, '0');
    const grouped = (sep) => String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, sep);
    return [`${grouped(',')}.${frac}`, `${whole}.${frac}`, `${grouped(' ')},${frac}`, `${whole},${frac}`, `${grouped('.')},${frac}`, `${grouped(' ')},${frac}`];
  };

  // The row that holds a value, preferring one whose text (or the row above) carries the field's label.
  function findRow(rows, needles, labelRe) {
    let best = null;
    for (let i = 0; i < rows.length; i++) {
      const text = rows[i].text, folded = fold(text).toLowerCase();
      const hit = needles.find(n => n && (text.includes(n) || folded.includes(fold(n).toLowerCase())));
      if (!hit) continue;
      const labelled = !!labelRe && (labelRe.test(fold(text)) || (i > 0 && labelRe.test(fold(rows[i - 1].text)) && rows[i - 1].page === rows[i].page));
      const score = (labelled ? 2 : 0) + (text.length < 80 ? 1 : 0);
      if (!best || score > best.score) best = {row: rows[i], hit, labelled, score};
    }
    return best;
  }

  // OCR confidence of the words that carry the value (falls back to the row's mean).
  const valueConf = (row, hit) => {
    if (!row || row.conf === null) return null;
    const digits = String(hit || '').replace(/[^0-9A-Za-z]/g, '');
    const words = row.runs.filter(r => r.conf !== null && digits && r.str.replace(/[^0-9A-Za-z]/g, '').length && (digits.includes(r.str.replace(/[^0-9A-Za-z]/g, '')) || r.str.replace(/[^0-9A-Za-z]/g, '').includes(digits)));
    const list = words.length ? words : row.runs.filter(r => r.conf !== null);
    return list.length ? Math.round(list.reduce((a, r) => a + r.conf, 0) / list.length) : row.conf;
  };

  const LABELS = {
    invoiceNumber: /\b(invoice|facture|bill|inv|document|no\.?|number|num[eé]ro|#)/i,
    invoiceDate: /\b(date|dated|issued?|emis|emission)\b/i,
    dueDate: /\b(due|echeance|payable|pay\s+by)\b/i,
    totalCents: /\b(total|amount\s+(?:due|payable)|balance|montant|a\s+payer)\b/i,
    subtotalCents: /\b(sub|sous|before\s+tax|avant\s+taxes?|hors\s+taxes|net\s+amount)\b/i,
    taxCents: /\b(tax|taxes|gst|hst|pst|qst|tps|tvq|tvh)\b/i,
    balanceDueCents: /\b(due|balance|owing|a\s+payer|solde|exigible)\b/i,
    discountCents: /\b(discount|remise|escompte|rabais)\b/i,
    freightCents: /\b(freight|shipping|delivery|livraison|transport|port)\b/i,
    roundingCents: /\b(rounding|arrondi)\b/i,
    paidCents: /\b(paid|payment|deposit|pay[eé])\b/i,
    paymentTerms: /\b(terms|conditions)\b/i,
    poNumber: /\b(p\.?o\.?|purchase\s+order|commande)\b/i,
  };

  const TAX_ROW = /^(?:[^A-Za-z]{0,3})(GST\s*\/\s*HST|TPS\s*\/\s*TVH|HST|GST|PST|QST|RST|TPS|TVQ|TVH|TVP|VAT|sales\s+tax|tax(?:e)?)\b(.*)$/i;
  const normaliseTaxLabel = label => {
    const l = label.toUpperCase().replace(/\s+/g, '');
    return ({'TPS/TVH': 'GST/HST', 'TPS': 'GST', 'TVH': 'HST', 'TVQ': 'QST', 'TVP': 'PST', 'SALESTAX': 'TAX', 'TAXE': 'TAX'})[l] || l;
  };

  // Tax components printed in the totals area: label, rate and amount, kept separately (GST, HST, PST, QST…).
  function taxComponents(rows, french) {
    const out = [];
    for (const row of rows) {
      const folded = fold(row.text);
      const m = TAX_ROW.exec(folded);
      if (!m || /\b(total|exempt|registration|reg\.?|number|no\.?\s*:|#\s*\d{6})\b/i.test(folded) && !/\b\d+(?:[.,]\d+)?\s*%/.test(folded)) continue;
      const stripped = row.text.replace(/\b\d{9}\s*(RT|TQ|RR)\s*\d{4}\b|\b\d{10}\s*TQ\s*\d{4}\b/gi, ' ').replace(/\d+(?:[.,]\d+)?\s*%/g, ' ');
      const amounts = OCR().moneyIn(stripped, french);
      if (!amounts.length) continue;
      const rate = /(\d+(?:[.,]\d+)?)\s*%/.exec(row.text)?.[1];
      out.push({label: normaliseTaxLabel(m[1]), rateText: rate ? rate.replace(',', '.') + '%' : undefined, amountCents: amounts[amounts.length - 1],
        included: /\b(incl|included|incluses?|comprises?)\b/i.test(folded), page: row.page, row});
      if (out.length >= 8) break;
    }
    return out;
  }

  // A labelled amount on its own row (discount, freight, rounding, paid).
  function labelledAmount(rows, re, french, exclude) {
    for (const row of rows) {
      const folded = fold(row.text);
      if (!new RegExp(`^(?:[^A-Za-z]{0,3})(?:${re.source})`, 'i').test(folded) || (exclude && exclude.test(folded))) continue;
      const amounts = OCR().moneyIn(row.text, french);
      if (amounts.length) return {cents: amounts[amounts.length - 1], row};
    }
    return null;
  }

  function classify(text) {
    const t = fold(text).toLowerCase();
    if (/\b(credit\s*(?:note|memo)|note\s+de\s+credit|avoir|credit\s+invoice)\b/.test(t)) return 'credit_note';
    if (/\b(statement\s+of\s+account|account\s+statement|releve\s+de\s+compte)\b/.test(t) || (/\bstatement\b/.test(t) && /\b(balance\s+forward|previous\s+balance|aging|ageing|31\s*-\s*60|over\s+90)\b/.test(t))) return 'statement';
    const invoice = /\b(invoice|facture|tax\s+invoice|bill\s+(?:no|number|#)|invoice\s+(?:no|number|#))\b/.test(t);
    if (!invoice && /\b(receipt|recu|payment\s+received|paid\s+in\s+full|change\s+due|tender|thank\s+you\s+for\s+shopping)\b/.test(t)) return 'receipt';
    if (invoice || /\b(amount\s+due|balance\s+due|total\s+due|bill\s+to)\b/.test(t)) return 'invoice';
    return 'other';
  }

  // Several invoices in one PDF: a page starts a new invoice when it says "Page 1 of", when its invoice number differs
  // from the current invoice's, or when the current invoice already reached its total and this page has its own header.
  function segmentPages(pages) {
    const O = OCR(), out = [];
    let current = null;
    for (const page of pages) {
      const text = page.text || '';
      const marker = /\bpage\s*(\d+)\s*(?:of|\/|de|sur)\s*(\d+)\b/i.exec(fold(text));
      const c = O.candidateFromText(text);
      const number = c.documentNumber || '';
      const header = !!number && /\b(invoice|facture|credit|bill)\b/i.test(fold(text));
      const norm = s => String(s).toUpperCase().replace(/[^A-Z0-9]/g, '').replace(/^0+/, '');
      const startNew = !current
        || (marker && Number(marker[1]) === 1 && current.pages.length > 0)
        || (number && current.number && norm(number) !== norm(current.number) && !(marker && Number(marker[1]) > 1))
        || (current.hasTotal && header && !(marker && Number(marker[1]) > 1) && norm(number) !== norm(current.number || ''));
      if (startNew) { current = {pages: [], number: '', hasTotal: false}; out.push(current); }
      current.pages.push(page);
      if (number && !current.number) current.number = number;
      if (Number.isInteger(c.totalCents) || Number.isInteger(c.amountDueCents)) current.hasTotal = true;
    }
    return out.map(s => s.pages);
  }

  // 64-bit SimHash over three-word shingles: near-identical scans of one invoice give close fingerprints, so a
  // re-scan is caught without storing the document's text.
  function simhash(text) {
    const words = fold(text).toLowerCase().match(/[a-z0-9]{2,}/g) || [];
    if (words.length < 3) return null;
    const weights = new Array(64).fill(0);
    const prime = 0x100000001b3n, mask = (1n << 64n) - 1n;
    for (let i = 0; i + 2 < words.length; i++) {
      let h = 0xcbf29ce484222325n;
      for (const ch of words[i] + ' ' + words[i + 1] + ' ' + words[i + 2]) { h ^= BigInt(ch.charCodeAt(0)); h = (h * prime) & mask; }
      for (let b = 0; b < 64; b++) weights[b] += (h >> BigInt(b)) & 1n ? 1 : -1;
    }
    let out = 0n;
    for (let b = 0; b < 64; b++) if (weights[b] > 0) out |= 1n << BigInt(b);
    return out.toString(16).padStart(16, '0');
  }

  function extractSegment(segPages) {
    const O = OCR();
    const text = segPages.map(p => p.text || '').join('\n');
    const rows = segPages.flatMap(rowsOf);
    const french = /\b(TPS|TVQ|TVH|sous-?total|montant|facture|taxes\s+incluses)\b/i.test(fold(text)) && !/\b\d{1,3}(,\d{3})*\.\d{2}\b/.test(text);
    const pageRows = segPages.map(p => O.positionedRows(p.items));
    const c = O.candidateFromText(text, pageRows);
    const methodOf = pageNo => segPages.find(p => p.page === pageNo)?.method || 'text';
    const fields = {}, evidence = {};
    const put = (name, value, raw, row, labelled, extra = {}) => {
      if (value === undefined || value === null || value === '') return;
      const source = extra.source || (row ? (methodOf(row.page) === 'ocr' ? 'ocr' : 'text') : 'derived');
      const field = {value, raw: String(raw ?? '').slice(0, 200), source, label: !!labelled};
      if (source === 'ocr') field.ocrConf = valueConf(row, raw);
      if (row) { field.page = row.page; evidence[name] = {page: row.page, snippet: row.text.slice(0, 240), box: row.box || undefined}; }
      if (extra.alternatives) field.alternatives = extra.alternatives;
      fields[name] = field;
    };
    const locate = (name, needles, value, extra = {}) => {
      const found = findRow(rows, needles.filter(Boolean), LABELS[name]);
      put(name, value, found?.hit || needles[0] || '', found?.row || null, found?.labelled, found ? extra : {...extra, source: extra.source || 'derived'});
    };

    // Supplier identity
    if (c.partyName) {
      const found = findRow(rows, [c.partyName], /\b(vendor|supplier|seller|from|fournisseur|vendeur)\b/i);
      const dba = /^(.*?)\s+(?:o\/a|d\/b\/a|dba|operating\s+as|faisant\s+affaire\s+sous)\s+(.+)$/i.exec(c.partyName);
      put('supplierName', (dba ? dba[2] : c.partyName).trim(), c.partyName, found?.row || null, found?.labelled);
      if (dba) { put('supplierLegalName', dba[1].trim(), dba[1], found?.row || null, true); put('supplierTradingName', dba[2].trim(), dba[2], found?.row || null, true); }
      const legal = rows.find(r => /\b(?:legal\s+name|raison\s+sociale)\s*[:：]\s*(.+)$/i.test(fold(r.text)));
      if (legal) put('supplierLegalName', /[:：]\s*(.+)$/.exec(legal.text)[1].trim(), legal.text, legal, true);
      // Address: up to three rows under the supplier name that look like a street, a city with a postal code, or a unit.
      if (found?.row) {
        const start = rows.indexOf(found.row), parts = [];
        for (const r of rows.slice(start + 1, start + 5)) {
          if (r.page !== found.row.page) break;
          if (/\b\d+\s+[A-Za-zÀ-ÿ].*\b(st|street|rd|road|ave|avenue|blvd|boul|rue|ch|chemin|dr|drive|way|unit|suite|bureau|lane|ln|cres|court|ct|place|pl|highway|hwy)\b/i.test(r.text)
            || /\b[A-Z]\d[A-Z]\s?\d[A-Z]\d\b/.test(r.text) || /\b\d{5}(?:-\d{4})?\b/.test(r.text) && /\b[A-Z]{2}\b/.test(r.text)) parts.push(r.text.replace(/\s*(?:tel|phone|t[eé]l)\b.*$/i, '').trim());
          else if (parts.length) break;
        }
        if (parts.length) put('supplierAddress', parts.join(', ').slice(0, 500), parts[0], found.row, false);
      }
    }
    if (c.partyEmail) locate('supplierEmail', [c.partyEmail], c.partyEmail);
    const phoneRow = rows.find(r => /\b(?:tel|phone|t[eé]l[eé]phone|ph)\.?\s*[:#]?\s*\+?1?[\s.-]?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/i.test(r.text));
    if (phoneRow) { const m = /(\+?1?[\s.-]?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4})/.exec(phoneRow.text); put('supplierPhone', m[1].trim(), m[1], phoneRow, true); }
    const taxIds = [];
    for (const r of rows) {
      for (const m of r.text.matchAll(/\b(\d{9})\s*(RT)\s*(\d{4})\b/gi)) taxIds.push({text: `GST/HST ${m[1]} RT${m[3]}`, row: r});
      for (const m of r.text.matchAll(/\b(\d{10})\s*(TQ)\s*(\d{4})\b/gi)) taxIds.push({text: `QST ${m[1]} TQ${m[3]}`, row: r});
      const pst = /\b(?:PST|RST)\s*(?:#|no\.?|number|reg\.?)\s*[:#]?\s*([A-Z0-9-]{6,15})/i.exec(r.text);
      if (pst) taxIds.push({text: `PST ${pst[1]}`, row: r});
    }
    const uniqueIds = [...new Map(taxIds.map(t => [t.text, t])).values()];
    if (uniqueIds.length) put('supplierTaxNumbers', uniqueIds.map(t => t.text).join('; '), uniqueIds[0].text, uniqueIds[0].row, true);
    else if (c.businessIdentifier) locate('supplierTaxNumbers', [c.businessIdentifier], c.businessIdentifier);

    // Header
    if (c.documentNumber) locate('invoiceNumber', [c.documentNumber], c.documentNumber);
    if (c.documentDate) {
      const r = rows.find(row => O.datesIn(row.text).some(d => d.iso === c.documentDate) && LABELS.invoiceDate.test(fold(row.text)) && !LABELS.dueDate.test(fold(row.text)))
        || rows.find(row => O.datesIn(row.text).some(d => d.iso === c.documentDate));
      put('invoiceDate', c.documentDate, r ? r.text.slice(0, 80) : '', r || null, !!r && LABELS.invoiceDate.test(fold(r.text)));
    } else if ((c.alternatives || []).some(a => a.documentDate)) {
      const alts = c.alternatives.filter(a => a.documentDate).map(a => a.documentDate);
      const r = rows.find(row => O.datesIn(row.text).some(d => (d.alternatives || []).includes(alts[0])));
      fields.invoiceDate = {value: '', raw: r ? r.text.slice(0, 80) : '', source: r ? (methodOf(r.page) === 'ocr' ? 'ocr' : 'text') : 'text', label: true, alternatives: alts.slice(0, 2), page: r?.page};
      if (r) evidence.invoiceDate = {page: r.page, snippet: r.text.slice(0, 240), box: r.box || undefined};
    }
    if (c.dueDate) {
      const r = rows.find(row => O.datesIn(row.text).some(d => d.iso === c.dueDate));
      // A due date worked out from "Net 30" is not printed on the document: it is marked as calculated.
      if (r) put('dueDate', c.dueDate, r.text.slice(0, 80), r, LABELS.dueDate.test(fold(r.text)));
      else put('dueDate', c.dueDate, '', null, false, {source: 'derived'});
    }
    if (c.paymentTerms) locate('paymentTerms', [c.paymentTerms], c.paymentTerms);
    const explicitCurrency = /\b(?:currency|devise)\s*[:：]?\s*(CAD|USD|EUR|GBP)\b|\b(?:all\s+amounts\s+(?:are\s+)?in|amounts\s+in|montants\s+en)\s+(CAD|USD|EUR|GBP)\b|\b(CAD|USD|EUR|GBP)\s*\$?\s*\d/i.exec(text);
    if (explicitCurrency) { const code = (explicitCurrency[1] || explicitCurrency[2] || explicitCurrency[3]).toUpperCase(); locate('currency', [explicitCurrency[0]], code); fields.currency.label = true; }
    else if (/US\$/.test(text)) locate('currency', ['US$'], 'USD');
    else fields.currency = {value: c.currency || 'CAD', raw: '', source: 'default', label: false};
    const po = /\b(?:P\.?\s?O\.?|purchase\s+order|bon\s+de\s+commande)\s*(?:#|no\.?|number|n[°o])?\s*[:#]?\s*([A-Z0-9][A-Z0-9\-\/]{1,30})/i.exec(text);
    if (po && /\d/.test(po[1])) locate('poNumber', [po[1]], po[1]);
    const customer = /\b(?:customer|client|account)\s*(?:#|no\.?|number|n[°o]|id)\s*[:#]?\s*([A-Z0-9][A-Z0-9\-]{1,30})/i.exec(text);
    if (customer && /\d/.test(customer[1])) put('customerNumber', customer[1], customer[1], findRow(rows, [customer[1]], null)?.row || null, true);
    const block = (labelRe) => {
      const i = rows.findIndex(r => labelRe.test(fold(r.text)));
      if (i < 0) return null;
      const after = rows[i].text.replace(new RegExp('^.*?' + labelRe.source.replace(/\\b/g, '') + '\\s*[:：]?\\s*', 'i'), '').trim();
      const lines = [after, ...rows.slice(i + 1, i + 3).filter(r => r.page === rows[i].page && !/\b(invoice|date|total|description|qty|amount|terms)\b/i.test(r.text)).map(r => r.text)].filter(Boolean);
      return lines.length ? {value: lines.join(', ').slice(0, 300), row: rows[i]} : null;
    };
    const billTo = block(/\b(?:bill\s+to|sold\s+to|factur[eé]\s+[aà]|client\s*:)/i); if (billTo) put('billTo', billTo.value, billTo.value, billTo.row, true);
    const shipTo = block(/\b(?:ship\s+to|deliver\s+to|livr[eé]\s+[aà])/i); if (shipTo) put('shipTo', shipTo.value, shipTo.value, shipTo.row, true);
    const ship = rows.find(r => /\b(ship\s+via|carrier|tracking|transporteur|shipped\s+via)\b/i.test(r.text));
    if (ship) put('shippingInfo', ship.text.slice(0, 200), ship.text, ship, true);
    const original = /\b(?:original\s+invoice|invoice\s+ref(?:erence)?|re\s*:\s*invoice|applies\s+to\s+invoice|credit\s+for\s+invoice|ref(?:erence)?\s+facture|facture\s+d'origine)\s*(?:#|no\.?|number)?\s*[:#]?\s*([A-Z0-9][A-Z0-9\-\/]{1,30})/i.exec(fold(text));
    if (original && /\d/.test(original[1])) locate('originalInvoiceNumber', [original[1]], original[1]);
    if (/\b(?:prices?|amounts?|totals?)\s+(?:include|including|incl\.?)\s+(?:all\s+)?(?:gst|hst|pst|qst|tax|taxes)\b|\btax(?:es)?\s+included\b|\btaxes\s+(?:incluses|comprises)\b|\bTTC\b/i.test(fold(text)))
      fields.taxInclusive = {value: true, raw: 'tax included', source: 'text', label: true};

    // Amounts
    const moneyField = (name, cents) => { if (!Number.isInteger(cents)) return; locate(name, moneyStrings(cents), cents); };
    moneyField('totalCents', c.totalCents);
    moneyField('subtotalCents', c.subtotalCents);
    moneyField('taxCents', c.taxCents);
    if (Number.isInteger(c.amountDueCents)) moneyField('balanceDueCents', c.amountDueCents);
    const discount = labelledAmount(rows, /discount|remise|escompte|rabais/, french); if (discount) put('discountCents', Math.abs(discount.cents), discount.row.text, discount.row, true);
    const freight = labelledAmount(rows, /freight|shipping(?:\s+(?:and|&)\s+handling)?|delivery(?:\s+charge)?|frais\s+de\s+(?:livraison|transport)|livraison|transport/, french, /\bfree\b/i); if (freight) put('freightCents', freight.cents, freight.row.text, freight.row, true);
    const rounding = labelledAmount(rows, /rounding|arrondi/, french); if (rounding) put('roundingCents', rounding.cents, rounding.row.text, rounding.row, true);
    const paid = labelledAmount(rows, /amount\s+paid|paid|payments?(?:\s+received)?|deposit|less\s+payment|montant\s+pay[eé]/, french, /\b(to\s+be\s+paid|unpaid|payable|due)\b/i); if (paid) put('paidCents', Math.abs(paid.cents), paid.row.text, paid.row, true);
    const taxes = taxComponents(rows, french);
    taxes.forEach((t, i) => { evidence['tax.' + i] = {page: t.page, snippet: t.row.text.slice(0, 240), box: t.row.box || undefined}; });

    // Lines: the table reader works on text and OCR pages alike (both give positioned words).
    const lines = (c.lineItems || []).map(item => {
      let row = null;
      if (Number.isFinite(item.y)) row = rows.find(r => Math.abs(r.y - item.y) < 3 && moneyStrings(item.amountCents).some(s => r.text.includes(s))) || null;
      if (!row) row = findRow(rows, [item.description.slice(0, 30)], null)?.row || null;
      const line = {description: item.description, amountCents: item.amountCents, source: row && methodOf(row.page) === 'ocr' ? 'ocr' : 'text'};
      if (item.itemCode) line.itemCode = item.itemCode;
      if (item.quantity !== undefined) line.quantity = String(item.quantity);
      if (item.unit) line.unit = item.unit;
      if (Number.isInteger(item.unitCents)) line.unitPriceCents = item.unitCents;
      if (Number.isInteger(item.discountCents)) line.discountCents = item.discountCents;
      if (item.taxHint) line.taxLabel = item.taxHint;
      if (/\b(freight|shipping|delivery|livraison|transport|courier)\b/i.test(fold(item.description))) line.kind = 'freight';
      if (row) { line.page = row.page; if (line.source === 'ocr') line.ocrConf = row.conf; }
      return {line, row};
    });
    lines.forEach(({row}, i) => { if (row) evidence['line.' + i] = {page: row.page, snippet: row.text.slice(0, 240), box: row.box || undefined}; });
    // Freight printed in the totals area (not as a table line) becomes its own line so the draft carries it.
    if (fields.freightCents && fields.freightCents.value > 0 && !lines.some(l => l.line.kind === 'freight')) {
      lines.push({line: {description: 'Freight', amountCents: fields.freightCents.value, kind: 'freight', source: fields.freightCents.source, page: fields.freightCents.page}, row: null});
      if (evidence.freightCents) evidence['line.' + (lines.length - 1)] = evidence.freightCents;
    }

    const docClass = classify(text);
    const pagesInfo = segPages.map(p => ({page: p.page, rotation: p.rotation || 0, method: p.method, meanConf: p.meanConf}));
    return {docClass, pageFrom: segPages[0].page, pageTo: segPages[segPages.length - 1].page, fields,
      taxes: taxes.map(({row, ...t}) => t), lines: lines.map(l => l.line), evidence, simhash: simhash(text), pages: pagesInfo};
  }

  function extractDocument(read) {
    const segments = segmentPages(read.pages).map(extractSegment);
    return {method: read.method || 'pdf_text', pageCount: read.pageCount || read.pages.length, ocrPages: read.ocrPages || 0, segments};
  }

  /* ------------------------------------------------------------------ formatting */

  const money = (cents, currency = 'CAD') => {
    if (cents === null || cents === undefined || cents === '') return '—';
    const n = Number(cents) / 100;
    try { return n.toLocaleString('en-CA', {style: 'currency', currency: currency || 'CAD'}); } catch (_) { return n.toFixed(2); }
  };
  const centsText = cents => cents === null || cents === undefined || cents === '' ? '' : (Number(cents) / 100).toFixed(2);
  const parseCents = text => {
    const t = String(text ?? '').trim().replace(/[$\s,]/g, '');
    if (t === '') return null;
    if (!/^-?\d+(\.\d{1,2})?$/.test(t)) return NaN;
    const [w, f = ''] = t.replace('-', '').split('.');
    const cents = Number(w) * 100 + Number((f + '00').slice(0, 2));
    return t.startsWith('-') ? -cents : cents;
  };
  const STATE_LABELS = {uploaded: 'Uploaded', queued: 'Waiting to be read', processing: 'Reading', review_required: 'Review required', ready: 'Ready for review', blocked: 'Blocked',
    draft_created: 'Draft created', approved: 'Approved', posted: 'Posted', failed: 'Could not be read', cancelled: 'Cancelled'};
  const FAILURES = {pdf_password_required: 'The PDF is protected by a password.', pdf_password_wrong: 'The password did not open the PDF.', pdf_corrupt: 'The PDF is damaged and could not be opened.',
    pdf_too_many_pages: 'The file has too many pages. Split it and upload the parts.', ocr_failed: 'Text recognition failed on this file.', unreadable: 'No readable invoice was found in the file.',
    timeout: 'Reading took too long.', unsupported: 'This kind of file is not supported.', retry_limit: 'Reading failed three times. Retry when the file or the browser is fixed.', not_pdf: 'The file is not a PDF.', pdf_truncated: 'The PDF is incomplete (cut off during upload or saving).'};
  const CLASS_LABELS = {invoice: 'Supplier invoice', credit_note: 'Supplier credit note', statement: 'Statement of account', receipt: 'Receipt', other: 'Not an invoice', unknown: 'Unknown'};
  const LEVEL_LABEL = {high: 'High', medium: 'Medium', low: 'Low'};

  /* ------------------------------------------------------------------ worker */

  const worker = {active: false, status: '', listeners: new Set(), stopRequested: false};
  const notify = () => worker.listeners.forEach(fn => { try { fn(worker.status); } catch (_) {} });
  const NON_RETRYABLE = new Set(['pdf_password_required', 'pdf_password_wrong', 'pdf_corrupt', 'pdf_too_many_pages', 'not_pdf', 'unsupported', 'pdf_truncated']);

  async function askPassword(name, again) {
    return new Promise(resolve => {
      const dialog = document.createElement('dialog');
      dialog.className = 'ii-password-dialog';
      dialog.innerHTML = `<form method="dialog"><h3>${again ? 'That password did not work' : 'This PDF is protected'}</h3><p><b></b> needs a password to open. The password is used only in this browser to read the file. It is not sent to Tegh or saved.</p>
        <label>Password<input type="password" name="pw" autocomplete="off" data-ii-password></label><div class="ii-dialog-actions"><button type="button" class="srp-btn secondary" data-ii-skip>Skip this file</button><button type="submit" class="srp-btn" data-ii-open>Open</button></div></form>`;
      dialog.querySelector('b').textContent = name;
      document.body.append(dialog);
      const done = value => { dialog.close(); dialog.remove(); resolve(value); };
      dialog.querySelector('[data-ii-skip]').onclick = () => done(null);
      dialog.querySelector('form').onsubmit = event => { event.preventDefault(); const v = dialog.querySelector('input').value; done(v ? v : null); };
      dialog.addEventListener('cancel', () => done(null));
      dialog.showModal();
    });
  }

  async function processJob(ctx, job, token) {
    const {api} = ctx;
    let beat = null;
    const controller = new AbortController();
    try {
      worker.status = `Reading ${job.fileName}…`; notify();
      beat = setInterval(() => { api('invoice-intel/heartbeat', {method: 'POST', json: {jobId: job.id, leaseToken: token}}).catch(error => { if (/lease/.test(String(error?.code || error?.message))) controller.abort(); }); }, 60000);
      const response = await fetch(job.sourceUrl, {credentials: 'same-origin', headers: {'X-Company-Id': localStorage.getItem('sr-accountax-company') || ''}});
      if (!response.ok) throw Object.assign(new Error('The file could not be fetched.'), {code: 'unreadable', retryable: true});
      const blob = await response.blob();
      const file = new Blob([blob], {type: job.mimeType || blob.type || 'application/pdf'});
      const read = await OCR().readDocumentPages(file, {signal: controller.signal, maxPages: 30,
        onPassword: again => askPassword(job.fileName, again),
        onProgress: p => { worker.status = `Reading ${job.fileName}: ${p.detail || p.status}${p.progress ? ' ' + Math.round(p.progress * 100) + '%' : ''}`; notify(); }});
      const result = extractDocument(read);
      if (!result.segments.length) throw Object.assign(new Error('No invoice found.'), {code: 'unreadable'});
      await api('invoice-intel/submit', {method: 'POST', json: {jobId: job.id, leaseToken: token, result}});
    } catch (error) {
      const code = error?.code && FAILURES[error.code] ? error.code : (error?.name === 'AbortError' ? 'timeout' : 'ocr_failed');
      const retryable = error?.retryable === true || !NON_RETRYABLE.has(code);
      await api('invoice-intel/fail', {method: 'POST', json: {jobId: job.id, leaseToken: token, failureCode: code, retryable}}).catch(() => {});
    } finally {
      clearInterval(beat);
    }
  }

  // Reads queued files one at a time while the Invoice Intelligence page is open.
  async function runWorker(ctx, onChange) {
    if (worker.active) return;
    worker.active = true;
    try {
      for (let guard = 0; guard < 200; guard++) {
        if (!ctx.page?.isConnected) break;
        const claim = await ctx.api('invoice-intel/claim', {method: 'POST', json: {}});
        if (!claim?.claimed) {
          if (claim?.reason === 'busy') { worker.status = 'Other windows are reading invoices. Waiting…'; notify(); await new Promise(r => setTimeout(r, 8000)); continue; }
          break;
        }
        await processJob(ctx, claim.job, claim.leaseToken);
        onChange?.();
      }
    } catch (error) {
      worker.status = 'Reading stopped: ' + (error?.message || 'unknown error'); notify();
    } finally {
      worker.active = false;
      if (!/stopped/.test(worker.status)) worker.status = '';
      notify();
      onChange?.();
    }
  }

  /* ------------------------------------------------------------------ queue screen */

  const h = (esc, strings, ...values) => strings.reduce((out, s, i) => out + s + (i < values.length ? esc(values[i]) : ''), '');

  async function renderQueue(body, ctx) {
    const {api, esc, toast} = ctx;
    const [options, list] = await Promise.all([api('invoice-intel/options'), api('invoice-intel/jobs?' + new URLSearchParams({state: ctx.filter.state || 'open', ...(ctx.filter.review ? {review: ctx.filter.review} : {}), ...(ctx.filter.vendorId ? {vendorId: ctx.filter.vendorId} : {}), ...(ctx.filter.duplicates ? {duplicates: '1'} : {}), ...(ctx.filter.q ? {q: ctx.filter.q} : {})}))]);
    const filter = {state: 'open', ...ctx.filter};
    const counts = list.counts || {};
    const n = s => Number(counts[s] || 0);
    const chips = [['open', 'Open', n('queued') + n('processing') + n('review_required') + n('ready') + n('blocked') + n('failed')], ['ready', 'Ready', n('ready')], ['review_required', 'Review required', n('review_required')],
      ['blocked', 'Blocked', n('blocked')], ['processing', 'Reading', n('queued') + n('processing')], ['failed', 'Could not be read', n('failed')], ['done', 'Drafted', n('draft_created') + n('approved') + n('posted')]];
    const jobs = list.jobs || [];
    const ready = jobs.filter(j => j.state === 'ready');
    body.innerHTML = `<div class="ii-shell" data-ii-queue>
      <section class="srp-card ii-upload" data-ii-upload>
        <div class="ii-drop" data-ii-drop tabindex="0" role="button" aria-label="Upload supplier invoice PDFs">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 16V4m0 0-4 4m4-4 4 4M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <div><b>Drop supplier invoice PDFs here</b><span>or <u>choose files</u> · PDF, PNG, JPEG or WebP · up to 10 MB and 30 pages each · several files at once</span></div>
          <input type="file" multiple accept="application/pdf,image/png,image/jpeg,image/webp" data-ii-file hidden>
        </div>
        <div class="ii-upload-notes">
          <p class="ii-note-important" data-ii-vendor-note><b>Before you import:</b> each supplier must already be in your list of vendors. Tegh does not create suppliers from a document. <button type="button" class="ii-link" data-ii-open-vendors>Check Vendors</button></p>
          <p>Files are read in this browser with Tegh's own reader. Nothing is sent to an outside service. Keep this page open while files are read.</p>
        </div>
        <p class="ii-worker" data-ii-worker aria-live="polite"></p>
      </section>
      <nav class="ii-chips" aria-label="Filter by status">${chips.map(([key, label, count]) => `<button type="button" class="ii-chip${filter.state === key ? ' is-active' : ''}" data-ii-state="${key}" aria-pressed="${filter.state === key}">${esc(label)} <b>${count}</b></button>`).join('')}</nav>
      <form class="ii-filters" data-ii-filters>
        <label>Supplier<select name="vendorId"><option value="">All suppliers</option>${options.vendors.map(v => `<option value="${esc(v.id)}"${filter.vendorId === v.id ? ' selected' : ''}>${esc(v.name)}</option>`).join('')}</select></label>
        <label>Search<input name="q" type="search" placeholder="Invoice number or file" value="${esc(filter.q || '')}"></label>
        <label class="ii-check"><input type="checkbox" name="duplicates" value="1"${filter.duplicates ? ' checked' : ''}> Possible duplicates only</label>
        <button class="srp-btn secondary" type="submit">Apply</button>
        ${ready.length && options.canWrite ? `<button type="button" class="srp-btn" data-ii-draft-ready>Create ${ready.length} draft${ready.length === 1 ? '' : 's'} from ready invoices</button>` : ''}
      </form>
      <section class="srp-card ii-table-card">
        ${jobs.length ? `<div class="ii-table-wrap"><table class="srp-table ii-table" data-ii-table><thead><tr><th>File</th><th>Supplier</th><th>Invoice</th><th>Date</th><th class="num">Total</th><th>Status</th><th>Checks</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
          ${jobs.map(j => `<tr data-ii-row="${esc(j.id)}" data-tegh-route="invoice-intel:${esc(j.id)}" tabindex="0">
            <td><span class="ii-file">${esc(j.fileName)}</span>${j.segmentIndex > 0 || (j.pageFrom && j.pageTo && j.pageCount > 1) ? `<small>pages ${esc(j.pageFrom)}–${esc(j.pageTo)}</small>` : ''}</td>
            <td>${esc(j.vendorName || j.supplierName || '—')}${!j.vendorId && j.supplierName ? '<small class="ii-warn-text">not matched</small>' : ''}</td>
            <td>${esc(j.invoiceNumber || '—')}${j.docClass === 'credit_note' ? '<small>credit note</small>' : ''}</td>
            <td>${esc(j.invoiceDate || '—')}</td>
            <td class="num">${j.totalCents !== null ? esc(money(j.totalCents, j.currency)) : '—'}</td>
            <td><span class="ii-badge ii-state-${esc(j.state)}">${esc(STATE_LABELS[j.state] || j.state)}</span>${j.targetNumber ? `<small>${esc(j.targetNumber)}</small>` : ''}${j.failureCode ? `<small>${esc(FAILURES[j.failureCode] || j.failureCode)}</small>` : ''}</td>
            <td>${j.duplicateClass ? `<span class="ii-badge ii-dup-${esc(j.duplicateClass)}">${j.duplicateClass === 'exact' ? 'Duplicate' : j.duplicateClass === 'probable' ? 'Probable duplicate' : 'Check duplicate'}</span>` : ''}${j.flagCount ? `<span class="ii-flags">${j.flagCount} to check</span>` : ''}</td>
            <td class="ii-row-actions">${['review_required', 'ready', 'blocked', 'draft_created', 'approved', 'posted'].includes(j.state) ? `<button type="button" class="srp-btn secondary" data-ii-open="${esc(j.id)}" data-tegh-route="invoice-intel:${esc(j.id)}">${['draft_created', 'approved', 'posted'].includes(j.state) ? 'View' : 'Review'}</button>` : ''}${j.state === 'failed' && options.canWrite ? `<button type="button" class="srp-btn secondary" data-ii-retry="${esc(j.id)}">Retry</button>` : ''}${['queued', 'failed', 'review_required', 'ready', 'blocked'].includes(j.state) && options.canWrite ? `<button type="button" class="ii-link" data-ii-cancel="${esc(j.id)}">Cancel</button>` : ''}</td>
          </tr>`).join('')}</tbody></table></div>` : `<div class="ii-empty"><b>No invoices here.</b><p>${filter.state === 'open' ? 'Drop supplier invoice PDFs above to start.' : 'Choose another status.'}</p></div>`}
      </section></div>`;

    const reload = (patch = {}) => ctx.openQueue({...filter, ...patch});
    const workerLine = body.querySelector('[data-ii-worker]');
    const showStatus = status => { if (workerLine?.isConnected) { workerLine.textContent = status; workerLine.hidden = !status; } };
    worker.listeners.clear(); worker.listeners.add(showStatus); showStatus(worker.status);
    const startWorker = () => { if (options.canWrite) void runWorker(ctx, () => { if (ctx.page?.isConnected && body.querySelector('[data-ii-queue]')) refreshRows(); }); };
    let refreshTimer = null;
    const refreshRows = () => { clearTimeout(refreshTimer); refreshTimer = setTimeout(() => { if (ctx.page?.isConnected && !body.querySelector('dialog[open]')) reload(); }, 400); };

    body.querySelectorAll('[data-ii-state]').forEach(b => b.onclick = () => reload({state: b.dataset.iiState, review: '', duplicates: ''}));
    body.querySelector('[data-ii-filters]').onsubmit = event => { event.preventDefault(); const f = new FormData(event.currentTarget); reload({vendorId: f.get('vendorId') || '', q: f.get('q') || '', duplicates: f.get('duplicates') ? '1' : ''}); };
    body.querySelector('[data-ii-open-vendors]').onclick = () => ctx.openVendors();
    body.querySelectorAll('[data-ii-open]').forEach(b => b.onclick = event => { event.stopPropagation(); if (!event.ctrlKey && !event.metaKey && !event.shiftKey) ctx.openJob(b.dataset.iiOpen); });
    body.querySelectorAll('[data-ii-row]').forEach(tr => { tr.onclick = event => { if (event.target.closest('button') || event.ctrlKey || event.metaKey || event.shiftKey) return; const j = jobs.find(x => x.id === tr.dataset.iiRow); if (j && !['queued', 'processing', 'failed', 'cancelled'].includes(j.state)) ctx.openJob(j.id); }; tr.onkeydown = event => { if (event.key === 'Enter') tr.click(); }; });
    body.querySelectorAll('[data-ii-retry]').forEach(b => b.onclick = async event => { event.stopPropagation(); b.disabled = true; try { await api('invoice-intel/retry', {method: 'POST', json: {jobId: b.dataset.iiRetry}}); toast('Reading again', 'The file is back in the queue.', 'success'); reload(); startWorker(); } catch (error) { b.disabled = false; toast('Retry refused', error.message, 'error'); } });
    body.querySelectorAll('[data-ii-cancel]').forEach(b => b.onclick = async event => { event.stopPropagation(); if (!confirm('Cancel this invoice? Nothing is posted. The original file stays stored.')) return; try { await api('invoice-intel/cancel', {method: 'POST', json: {jobId: b.dataset.iiCancel}}); reload(); } catch (error) { toast('Cancel refused', error.message, 'error'); } });
    const draftReady = body.querySelector('[data-ii-draft-ready]');
    if (draftReady) draftReady.onclick = async () => {
      if (!confirm(`Create ${ready.length} draft vendor invoice${ready.length === 1 ? '' : 's'}? Drafts post nothing. You issue them from the Vendor Invoice & Note Register.`)) return;
      draftReady.disabled = true;
      let made = 0; const problems = [];
      for (const j of ready) { try { await api('invoice-intel/draft', {method: 'POST', json: {jobId: j.id}}); made++; } catch (error) { problems.push(`${j.fileName}: ${error.message}`); } }
      toast(`${made} draft${made === 1 ? '' : 's'} created`, problems.length ? problems.slice(0, 3).join(' · ') : 'Issue them from the Vendor Invoice & Note Register when checked.', problems.length ? 'warning' : 'success');
      reload();
    };

    // Upload: one request per file, all in one batch.
    const drop = body.querySelector('[data-ii-drop]'), input = body.querySelector('[data-ii-file]');
    const upload = async files => {
      const list = [...files];
      if (!list.length) return;
      if (!options.canWrite) { toast('Not allowed', 'Your company role cannot create vendor invoices.', 'error'); return; }
      const batch = 'batch-' + Array.from(crypto.getRandomValues(new Uint8Array(8)), b => b.toString(16).padStart(2, '0')).join('');
      let done = 0, duplicates = 0; const problems = [];
      for (const file of list) {
        showStatus(`Uploading ${done + 1} of ${list.length}: ${file.name}`);
        if (file.size > options.limits.maxFileBytes) { problems.push(`${file.name}: larger than 10 MB`); continue; }
        const form = new FormData(); form.append('file', file); form.append('batchId', batch);
        try { const r = await api('invoice-intel/upload', {method: 'POST', body: form}); done++; if (r.duplicateFile) duplicates++; }
        catch (error) { problems.push(`${file.name}: ${error.message}`); }
      }
      showStatus('');
      toast(`${done} file${done === 1 ? '' : 's'} uploaded`, [duplicates ? `${duplicates} already uploaded before: no second invoice was created.` : '', problems.slice(0, 3).join(' · '), 'Check each supplier is in Vendors.'].filter(Boolean).join(' '), problems.length ? 'warning' : 'success');
      reload({state: 'open'});
    };
    drop.onclick = () => input.click();
    drop.onkeydown = event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); input.click(); } };
    input.onchange = () => upload(input.files);
    ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, event => { event.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach(type => drop.addEventListener(type, () => drop.classList.remove('is-over')));
    drop.addEventListener('drop', event => { event.preventDefault(); upload(event.dataTransfer?.files || []); });
    if (ctx.upload) setTimeout(() => drop.focus(), 50);
    if ((n('queued') || n('processing')) && options.canWrite) startWorker();
  }

  /* ------------------------------------------------------------------ PDF viewer */

  async function mountViewer(container, job, esc) {
    container.innerHTML = `<div class="ii-viewer-bar"><button type="button" class="ii-icon" data-v-prev aria-label="Previous page">‹</button><span data-v-page>Page 1</span><button type="button" class="ii-icon" data-v-next aria-label="Next page">›</button>
      <span class="ii-viewer-gap"></span><button type="button" class="ii-icon" data-v-out aria-label="Zoom out">−</button><span data-v-zoom>100%</span><button type="button" class="ii-icon" data-v-in aria-label="Zoom in">+</button><button type="button" class="ii-link" data-v-fit>Fit width</button><a class="ii-link" data-v-open target="_blank" rel="noopener">Open original</a></div>
      <div class="ii-viewer-scroll" data-v-scroll><div class="ii-viewer-page" data-v-sheet><canvas data-v-canvas></canvas><div class="ii-highlight" data-v-mark hidden></div></div><p class="ii-viewer-msg" data-v-msg hidden></p></div>`;
    const $ = s => container.querySelector(s);
    $('[data-v-open]').href = job.sourceUrl;
    const rotations = Object.fromEntries((job.pages || []).map(p => [p.page, p.rotation || 0]));
    const state = {page: job.pageFrom || 1, zoom: 0, pdf: null, image: null, pages: 1, mark: null};
    const response = await fetch(job.sourceUrl, {credentials: 'same-origin', headers: {'X-Company-Id': localStorage.getItem('sr-accountax-company') || ''}});
    if (!response.ok) { $('[data-v-msg]').hidden = false; $('[data-v-msg]').textContent = 'The original file could not be loaded.'; return {highlight() {}}; }
    const bytes = new Uint8Array(await response.arrayBuffer());
    const canvas = $('[data-v-canvas]'), sheet = $('[data-v-sheet]'), scroll = $('[data-v-scroll]');
    const isPdf = /pdf/.test(job.mimeType || '') || new TextDecoder().decode(bytes.slice(0, 5)) === '%PDF-';
    let password;
    if (isPdf) {
      const runtime = await OCR().pdfRuntime();
      const open = async pw => runtime.getDocument({data: bytes.slice(), isEvalSupported: false, ...(pw ? {password: pw} : {})}).promise;
      try { state.pdf = await open(); }
      catch (error) {
        if (error?.name !== 'PasswordException') throw error;
        password = await askPassword(job.fileName, false);
        if (!password) { $('[data-v-msg]').hidden = false; $('[data-v-msg]').textContent = 'The PDF is protected. Open it to see the pages.'; return {highlight() {}}; }
        state.pdf = await open(password);
      }
      state.pages = state.pdf.numPages;
    } else {
      state.image = await createImageBitmap(new Blob([bytes], {type: job.mimeType}));
    }
    let renderTask = null;
    const draw = async () => {
      const rotation = rotations[state.page] || 0;
      const width = scroll.clientWidth - 24;
      if (state.pdf) {
        const page = await state.pdf.getPage(state.page);
        const base = page.getViewport({scale: 1, rotation: (page.rotate + rotation) % 360});
        const scale = (state.zoom || width / base.width) * (window.devicePixelRatio || 1);
        const view = page.getViewport({scale, rotation: (page.rotate + rotation) % 360});
        canvas.width = Math.floor(view.width); canvas.height = Math.floor(view.height);
        canvas.style.width = Math.floor(view.width / (window.devicePixelRatio || 1)) + 'px';
        renderTask?.cancel();
        renderTask = page.render({canvasContext: canvas.getContext('2d'), viewport: view});
        await renderTask.promise.catch(() => {});
      } else {
        const turned = rotation % 180 !== 0;
        const w = turned ? state.image.height : state.image.width, hgt = turned ? state.image.width : state.image.height;
        const scale = (state.zoom || width / w);
        canvas.width = Math.floor(w * scale); canvas.height = Math.floor(hgt * scale); canvas.style.width = canvas.width + 'px';
        const c = canvas.getContext('2d'); c.save(); c.translate(canvas.width / 2, canvas.height / 2); c.rotate(rotation * Math.PI / 180);
        c.drawImage(state.image, -state.image.width * scale / 2, -state.image.height * scale / 2, state.image.width * scale, state.image.height * scale); c.restore();
      }
      $('[data-v-page]').textContent = `Page ${state.page} of ${state.pages}`;
      $('[data-v-zoom]').textContent = state.zoom ? Math.round(state.zoom * 100) + '%' : 'Fit';
      placeMark();
    };
    const placeMark = () => {
      const mark = $('[data-v-mark]');
      if (!state.mark || state.mark.page !== state.page || !state.mark.box) { mark.hidden = true; return; }
      const cssW = canvas.getBoundingClientRect().width, cssH = canvas.getBoundingClientRect().height;
      const [x, y, w, hh] = state.mark.box;
      Object.assign(mark.style, {left: (x * cssW - 3) + 'px', top: (y * cssH - 3) + 'px', width: (w * cssW + 6) + 'px', height: (hh * cssH + 6) + 'px'});
      mark.hidden = false;
      mark.scrollIntoView({block: 'center', inline: 'nearest', behavior: 'smooth'});
    };
    $('[data-v-prev]').onclick = () => { if (state.page > 1) { state.page--; draw(); } };
    $('[data-v-next]').onclick = () => { if (state.page < state.pages) { state.page++; draw(); } };
    $('[data-v-in]').onclick = () => { const cur = state.zoom || (canvas.getBoundingClientRect().width / (state.pdf ? 612 : state.image.width)); state.zoom = clamp(cur * 1.25, 0.3, 4); draw(); };
    $('[data-v-out]').onclick = () => { const cur = state.zoom || (canvas.getBoundingClientRect().width / (state.pdf ? 612 : state.image.width)); state.zoom = clamp(cur / 1.25, 0.3, 4); draw(); };
    $('[data-v-fit]').onclick = () => { state.zoom = 0; draw(); };
    let resizeTimer = null;
    const observer = new ResizeObserver(() => { clearTimeout(resizeTimer); resizeTimer = setTimeout(() => { if (!state.zoom && container.isConnected) draw(); }, 150); });
    observer.observe(scroll);
    await draw();
    return {highlight(evidence) { if (!evidence) return; state.mark = evidence; if (evidence.page && evidence.page !== state.page) { state.page = evidence.page; draw(); } else placeMark(); }};
  }

  /* ------------------------------------------------------------------ review screen */

  const FIELD_GROUPS = [
    ['Invoice', [['invoiceNumber', 'Invoice number', 'text'], ['invoiceDate', 'Invoice date', 'date'], ['dueDate', 'Due date', 'date'], ['paymentTerms', 'Payment terms', 'text'], ['poNumber', 'PO / reference', 'text'], ['currency', 'Currency', 'currency'], ['originalInvoiceNumber', 'Original invoice (credit notes)', 'text']]],
    ['Totals printed on the document', [['subtotalCents', 'Subtotal', 'money'], ['discountCents', 'Discount', 'money'], ['freightCents', 'Freight / shipping', 'money'], ['taxCents', 'Tax total', 'money'], ['roundingCents', 'Rounding', 'money'], ['totalCents', 'Invoice total', 'money'], ['paidCents', 'Already paid', 'money'], ['balanceDueCents', 'Balance due', 'money']]],
    ['Supplier as printed', [['supplierName', 'Name', 'text'], ['supplierLegalName', 'Legal name', 'text'], ['supplierTradingName', 'Trading name', 'text'], ['supplierAddress', 'Address', 'text'], ['supplierPhone', 'Phone', 'text'], ['supplierEmail', 'Email', 'text'], ['supplierTaxNumbers', 'Tax registration', 'text'], ['customerNumber', 'Your account number', 'text'], ['billTo', 'Bill to', 'text'], ['shipTo', 'Ship to', 'text'], ['shippingInfo', 'Shipping', 'text']]],
  ];

  async function renderJob(body, ctx) {
    const {api, esc, toast} = ctx;
    const [options, detail] = await Promise.all([api('invoice-intel/options'), api('invoice-intel/job?jobId=' + encodeURIComponent(ctx.jobId))]);
    const job = detail.job;
    const analysis = job.analysis || {};
    const open = ['ready', 'review_required', 'blocked'].includes(job.state) && options.canWrite;
    const levels = analysis.confidence?.fields || {};
    const flags = (analysis.validations || []);
    const byField = {}; const byLine = {};
    flags.forEach(v => { if (v.field) (byField[v.field] ||= []).push(v); if (Number.isInteger(v.line)) (byLine[v.line] ||= []).push(v); });
    const accounts = options.accounts, codes = options.taxCodes;
    const supplier = analysis.supplier || {status: 'none', candidates: []};
    const classLabel = {ready: 'Ready for review', review: 'Review required', blocked: 'Blocked'}[job.reviewClass] || STATE_LABELS[job.state];
    const reviewFlags = flags.filter(v => v.severity === 'review' && v.code !== 'tegh_total_differs');
    const differs = flags.find(v => v.code === 'tegh_total_differs');
    const computed = analysis.computed;
    const fieldInput = ([name, label, type]) => {
      const f = job.fields[name] || {};
      const lv = levels[name];
      const value = type === 'money' ? centsText(f.value) : (f.value ?? '');
      const alts = (f.alternatives || []).map(d => `<button type="button" class="ii-alt" data-ii-alt="${esc(name)}" data-value="${esc(d)}">${esc(new Date(d + 'T12:00:00').toLocaleDateString('en-CA', {month: 'long', day: 'numeric', year: 'numeric'}))}</button>`).join('');
      const input = type === 'currency'
        ? `<select name="${esc(name)}" ${open ? '' : 'disabled'}>${options.currencies.map(c => `<option${c === value ? ' selected' : ''}>${esc(c)}</option>`).join('')}${value && !options.currencies.includes(value) ? `<option selected>${esc(value)}</option>` : ''}</select>`
        : `<input name="${esc(name)}" ${type === 'date' ? 'type="date"' : 'type="text"'} ${type === 'money' ? 'inputmode="decimal"' : ''} value="${esc(value)}" ${open ? '' : 'readonly'}>`;
      const issue = (byField[name] || [])[0];
      return `<div class="ii-field${lv ? ' ii-level-' + lv.level : ''}${issue ? ' has-issue' : ''}" data-ii-field="${esc(name)}">
        <label><span>${esc(label)}${lv ? `<i class="ii-dot" title="${esc(LEVEL_LABEL[lv.level] + ': ' + lv.reasons.join('; '))}" aria-label="Confidence ${esc(LEVEL_LABEL[lv.level])}"></i>` : ''}</span>${input}</label>
        ${alts ? `<div class="ii-alts">Could be: ${alts}</div>` : ''}
        ${job.evidence?.[name] ? `<button type="button" class="ii-source" data-ii-source="${esc(name)}" title="Show where this was read">Source</button>` : ''}
        ${f.source === 'derived' ? '<small class="ii-muted">Calculated, not printed</small>' : f.source === 'default' ? '<small class="ii-muted">Not printed: company currency assumed</small>' : ''}
        ${issue ? `<small class="ii-issue">${esc(issue.message)}</small>` : ''}</div>`;
    };
    const accountOptions = selected => `<option value="">Choose account</option>${accounts.map(a => `<option value="${esc(a.id)}"${a.id === selected ? ' selected' : ''}>${esc(a.code + ' · ' + a.name)}</option>`).join('')}`;
    const codeOptions = selected => `<option value="">No tax</option>${codes.map(c => `<option value="${esc(c.id)}"${c.id === selected ? ' selected' : ''}>${esc(c.code + ' · ' + (c.totalRateMpct / 1000) + '%')}</option>`).join('')}`;
    const lineRow = (l, i) => `<tr data-ii-line="${i}" class="${(byLine[i] || []).length ? 'has-issue' : ''}${l.source === 'ocr' && (l.ocrConf ?? 100) < 60 ? ' ii-level-low' : ''}">
      <td class="ii-line-no">${i + 1}${job.evidence?.['line.' + i] ? `<button type="button" class="ii-source" data-ii-source="line.${i}" aria-label="Show line ${i + 1} on the document">◎</button>` : ''}</td>
      <td><input name="itemCode" value="${esc(l.itemCode || '')}" ${open ? '' : 'readonly'} aria-label="Item code"></td>
      <td><input name="description" value="${esc(l.description || '')}" ${open ? '' : 'readonly'} aria-label="Description" class="ii-desc"></td>
      <td><input name="quantity" value="${esc(l.quantity || '')}" inputmode="decimal" ${open ? '' : 'readonly'} aria-label="Quantity" class="num"></td>
      <td><input name="unit" value="${esc(l.unit || '')}" ${open ? '' : 'readonly'} aria-label="Unit" class="ii-unit"></td>
      <td><input name="unitPriceCents" value="${esc(centsText(l.unitPriceCents))}" inputmode="decimal" ${open ? '' : 'readonly'} aria-label="Unit price" class="num"></td>
      <td><input name="discountCents" value="${esc(centsText(l.discountCents))}" inputmode="decimal" ${open ? '' : 'readonly'} aria-label="Discount" class="num"></td>
      <td><input name="amountCents" value="${esc(centsText(l.amountCents))}" inputmode="decimal" ${open ? '' : 'readonly'} aria-label="Amount" class="num"></td>
      <td><select name="accountId" ${open ? '' : 'disabled'} aria-label="GL account">${accountOptions(l.accountId)}</select>${l.accountReason ? `<button type="button" class="ii-why" data-ii-why="${i}" title="${esc(l.accountReason)}" aria-label="Why this account">?</button>` : ''}</td>
      <td>${options.taxMode === 'codes' ? `<select name="taxCodeId" ${open ? '' : 'disabled'} aria-label="Tax code">${codeOptions(l.taxCodeId)}</select>` : `<label class="ii-check"><input type="checkbox" name="applyGstHst" ${l.applyGstHst ? 'checked' : ''} ${open && options.legacyTax.gstHst ? '' : 'disabled'}> GST/HST</label><label class="ii-check"><input type="checkbox" name="applyPst" ${l.applyPst ? 'checked' : ''} ${open && options.legacyTax.pst ? '' : 'disabled'}> PST</label>`}</td>
      <td>${open ? `<button type="button" class="ii-icon" data-ii-remove="${i}" aria-label="Remove line ${i + 1}">×</button>` : ''}</td></tr>`;
    const draftInfo = job.targetId ? `<section class="ii-done" data-ii-drafted><b>${job.targetType === 'bill' ? 'Draft vendor invoice' : 'Draft supplier credit note'} ${esc(job.targetNumber || '')}</b> · ${esc((job.targetStatus || 'draft').replace(/_/g, ' '))}.
        ${job.targetType === 'bill' ? 'It posts nothing until it is issued. Issuing checks the period lock and sends large amounts for approval, as for any vendor invoice.' : 'It posts nothing until it is posted from the note register.'}
        <div class="ii-actions"><button type="button" class="srp-btn secondary" data-ii-open-target>${job.targetType === 'bill' ? 'Open in Vendor Invoice & Note Register' : 'Open the note'}</button>${job.targetType === 'bill' && job.targetStatus === 'draft' && options.canWrite ? '<button type="button" class="srp-btn" data-ii-issue>Issue (post) vendor invoice</button>' : ''}</div></section>` : '';
    body.innerHTML = `<div class="ii-review" data-ii-job="${esc(job.id)}">
      <header class="ii-review-head">
        <button type="button" class="ii-link" data-ii-back>← All invoices</button>
        <div class="ii-title"><h2>${esc(job.vendorName || job.supplierName || job.fileName)}${job.invoiceNumber ? ' · ' + esc(job.invoiceNumber) : ''}</h2><p>${esc(job.fileName)}${job.pageCount > 1 ? ` · pages ${esc(job.pageFrom)}–${esc(job.pageTo)} of ${esc(job.pageCount)}` : ''} · ${job.extractionMethod === 'ocr' ? 'scanned (read by OCR)' : job.extractionMethod === 'mixed' ? 'text and scanned pages' : 'text PDF'}</p></div>
        <span class="ii-badge ii-class-${esc(job.reviewClass || job.state)}" data-ii-class>${esc(job.targetId ? STATE_LABELS[job.state] : classLabel)}</span>
        ${analysis.confidence?.document ? `<span class="ii-badge ii-conf-${esc(analysis.confidence.document)}" title="${esc(analysis.confidence.method || '')}">Confidence: ${esc(LEVEL_LABEL[analysis.confidence.document])}</span>` : ''}
      </header>
      ${job.draftDeleted ? '<p class="ii-note-important">The earlier draft was deleted in the register. No vendor invoice exists for this document now; review it and create the draft again if needed.</p>' : ''}
      ${draftInfo}
      <div class="ii-split">
        <section class="srp-card ii-viewer" data-ii-viewer aria-label="Original document"></section>
        <section class="ii-form-col">
          <form class="srp-card ii-form" data-ii-form>
            <div class="ii-supplier" data-ii-supplier>
              <label><span>Supplier <em class="ii-match ii-match-${esc(supplier.status)}">${esc({matched: 'matched', uncertain: 'not certain', conflict: 'conflict', none: 'not found'}[supplier.status] || supplier.status)}</em></span>
                <select name="vendorId" ${open ? '' : 'disabled'} data-ii-vendor><option value="">Choose the supplier</option>${options.vendors.map(v => `<option value="${esc(v.id)}"${v.id === job.vendorId ? ' selected' : ''}>${esc(v.name)}</option>`).join('')}</select></label>
              <p class="ii-muted">${supplier.status === 'matched' ? 'Matched by: ' + esc((supplier.evidence || []).join(', ')) : supplier.candidates?.length ? 'Possible: ' + supplier.candidates.map(c => `${esc(c.vendorName)} (${esc(c.evidence.join(', '))})`).join('; ') : 'No supplier in Vendors matches what is printed.'}
                ${open ? ' <button type="button" class="ii-link" data-ii-new-vendor>Add a supplier in Vendors</button>' : ''}</p>
              <label><span>Document type</span><select name="docClass" ${open ? '' : 'disabled'}>${['invoice', 'credit_note', 'receipt'].map(k => `<option value="${k}"${job.docClass === k ? ' selected' : ''}>${esc(CLASS_LABELS[k])}</option>`).join('')}${['statement', 'other', 'unknown'].includes(job.docClass) ? `<option selected disabled>${esc(CLASS_LABELS[job.docClass])}</option>` : ''}</select></label>
              <label class="ii-check"><input type="checkbox" name="taxInclusive" ${job.fields.taxInclusive?.value ? 'checked' : ''} ${open ? '' : 'disabled'}> Prices include tax</label>
            </div>
            ${FIELD_GROUPS.map(([title, list], gi) => `<fieldset class="ii-group${gi === 2 ? ' ii-collapsible' : ''}"><legend>${esc(title)}</legend><div class="ii-grid">${list.filter(([n]) => gi < 2 || job.fields[n]).map(fieldInput).join('') || '<p class="ii-muted">Nothing printed.</p>'}</div></fieldset>`).join('')}
            ${(job.taxes || []).length ? `<div class="ii-taxes"><b>Taxes printed:</b> ${(job.taxes || []).map((t, i) => `<button type="button" class="ii-chip-sm" data-ii-source="tax.${i}">${esc(t.label)}${t.rateText ? ' ' + esc(t.rateText) : ''} ${esc(money(t.amountCents, job.currency))}${t.included ? ' (included)' : ''}</button>`).join(' ')}</div>` : ''}
            <fieldset class="ii-group"><legend>Lines</legend>
              <div class="ii-lines-wrap"><table class="ii-lines"><thead><tr><th>#</th><th>Item</th><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Price</th><th class="num">Disc.</th><th class="num">Amount</th><th>GL account</th><th>Tax</th><th></th></tr></thead><tbody data-ii-lines>${(job.lines || []).map(lineRow).join('')}</tbody></table></div>
              ${open ? `<div class="ii-actions"><button type="button" class="srp-btn secondary" data-ii-add-line>Add line</button>${job.fields.discountCents?.value ? '<button type="button" class="srp-btn secondary" data-ii-spread>Spread the invoice discount over the lines</button>' : ''}</div>` : ''}
            </fieldset>
            <div class="ii-compare" data-ii-compare>${computed ? `<div><span>Tegh calculates</span><b>${esc(money(computed.subtotalCents, computed.currency))}</b> + tax <b>${esc(money(computed.taxCents, computed.currency))}</b> = <b data-ii-tegh-total>${esc(money(computed.totalCents, computed.currency))}</b></div>` : '<div class="ii-muted">Tegh\'s own calculation appears once the supplier, number, date and every line\'s account are set.</div>'}
              <div><span>Document shows</span><b>${esc(money(job.fields.totalCents?.value, job.currency))}</b>${computed && job.fields.totalCents && computed.totalCents === job.fields.totalCents.value ? ' <span class="ii-ok">✓ matches</span>' : ''}</div></div>
            ${open ? `<label class="ii-reason"><span>Reason for corrections (optional, kept in the history)</span><input name="reason" maxlength="300"></label>` : ''}
            <section class="ii-checks" data-ii-checks><h3>Checks</h3>${flags.length ? `<ul>${flags.map(v => `<li class="ii-sev-${esc(v.severity)}" data-ii-check="${esc(v.code)}">${esc(v.message)}${v.duplicate && options.canOverride && open && v.severity !== 'warn' ? ` <button type="button" class="ii-link" data-ii-override="${esc(v.duplicate)}">Not a duplicate…</button>` : ''}</li>`).join('')}</ul>` : '<p class="ii-ok">All checks passed.</p>'}</section>
            ${(analysis.suggestions || []).length ? `<details class="ii-why-list"><summary>Why these accounts</summary><ul>${analysis.suggestions.map(s => `<li>Line ${s.index + 1}: ${esc(s.reason)}</li>`).join('')}</ul></details>` : ''}
            ${(detail.job.corrections || []).length ? `<details class="ii-history"><summary>Corrections (${detail.job.corrections.length})</summary><ul>${detail.job.corrections.map(c => `<li>${esc(c.at)} · ${esc(c.by || '')}: ${esc(c.field)}${c.lineIndex !== null ? ' ' + (c.lineIndex + 1) : ''}${c.reason ? ' — ' + esc(c.reason) : ''}</li>`).join('')}</ul></details>` : ''}
            ${open ? `<footer class="ii-footer">
              ${differs ? `<label class="ii-check ii-confirm"><input type="checkbox" name="acceptComputedTotal" data-ii-accept-total> Use Tegh's total (${esc(money(computed?.totalCents, job.currency))}) — the document shows ${esc(money(job.fields.totalCents?.value, job.currency))}</label>` : ''}
              ${reviewFlags.length || job.reviewClass !== 'ready' ? '<label class="ii-check ii-confirm"><input type="checkbox" name="confirmReviewed" data-ii-confirm> I checked the flagged fields against the document</label>' : ''}
              <div class="ii-actions"><button type="submit" class="srp-btn secondary" data-ii-save>Save corrections</button><button type="button" class="srp-btn secondary" data-ii-recalc>Recalculate</button><button type="button" class="ii-link" data-ii-retry>Read the file again</button><button type="button" class="ii-link" data-ii-cancel>Cancel invoice</button>
              <button type="button" class="srp-btn" data-ii-draft ${job.reviewClass === 'blocked' ? 'disabled' : ''}>Create draft ${job.docClass === 'credit_note' ? 'credit note' : 'vendor invoice'}</button></div>
              <p class="ii-muted">A draft posts nothing to the general ledger or to what you owe suppliers. It opens in the Vendor Invoice & Note Register, where it is issued through the normal approval workflow.</p></footer>` : ''}
          </form>
        </section>
      </div></div>`;

    const $ = s => body.querySelector(s), $$ = s => [...body.querySelectorAll(s)];
    const viewer = await mountViewer($('[data-ii-viewer]'), job, esc).catch(error => { $('[data-ii-viewer]').innerHTML = `<p class="ii-viewer-msg">${esc(error.message || 'The document could not be shown.')}</p>`; return {highlight() {}}; });
    $$('[data-ii-source]').forEach(b => b.onclick = () => { const e = job.evidence?.[b.dataset.iiSource]; if (e) { viewer.highlight(e); $$('.ii-source-active').forEach(x => x.classList.remove('ii-source-active')); b.classList.add('ii-source-active'); const tip = e.snippet ? `Read as: “${e.snippet}”` : ''; b.title = tip; toast('Source', tip || 'Shown on the document.', 'info'); } });
    $('[data-ii-back]').onclick = () => ctx.openQueue({});
    $('[data-ii-new-vendor]')?.addEventListener('click', () => ctx.openNewVendor());
    $('[data-ii-open-target]')?.addEventListener('click', () => job.targetType === 'bill' ? ctx.openRegisterAt(job.targetId) : ctx.openNote(job.targetId));
    $('[data-ii-issue]')?.addEventListener('click', async event => {
      const b = event.currentTarget;
      if (!confirm('Issue this vendor invoice now? It posts to the general ledger (expense, recoverable tax and accounts payable). Large amounts go to approval first.')) return;
      b.disabled = true;
      try { const r = await api('bills', {method: 'PATCH', json: {action: 'issue', billId: job.targetId}}); toast(r?.bill?.status === 'submitted_for_approval' ? 'Sent for approval' : 'Vendor invoice posted', r?.bill?.status === 'submitted_for_approval' ? 'It is above the materiality threshold, so another person must approve it before it posts.' : 'Posted through the normal vendor invoice workflow.', 'success'); ctx.openJob(job.id); }
      catch (error) { b.disabled = false; toast('Not posted', error.message, 'error'); }
    });
    $$('[data-ii-why]').forEach(b => b.onclick = () => toast('Why this account', b.title, 'info'));
    if (!open) return;

    const form = $('[data-ii-form]');
    $$('[data-ii-alt]').forEach(b => b.onclick = () => { form.elements.namedItem(b.dataset.iiAlt).value = b.dataset.value; });
    const tbody = $('[data-ii-lines]');
    const readLines = () => [...tbody.querySelectorAll('tr')].map(tr => {
      const get = n => tr.querySelector(`[name=${n}]`);
      const line = {description: get('description').value.trim(), itemCode: get('itemCode').value.trim(), quantity: get('quantity').value.trim(), unit: get('unit').value.trim()};
      for (const k of ['unitPriceCents', 'discountCents', 'amountCents']) { const c = parseCents(get(k).value); if (Number.isNaN(c)) throw new Error(`Line ${Number(tr.dataset.iiLine) + 1}: "${get(k).value}" is not an amount.`); if (c !== null) line[k] = c; }
      Object.keys(line).forEach(k => { if (line[k] === '') delete line[k]; });
      const kind = (job.lines[Number(tr.dataset.iiLine)] || {}).kind; if (kind) line.kind = kind;
      line.accountId = get('accountId').value || undefined;
      if (options.taxMode === 'codes') line.taxCodeId = get('taxCodeId').value; else { line.applyGstHst = !!get('applyGstHst')?.checked; line.applyPst = !!get('applyPst')?.checked; }
      return line;
    });
    const readFields = () => {
      const out = {};
      for (const [, list] of FIELD_GROUPS) for (const [name, , type] of list) {
        const el = form.elements.namedItem(name); if (!el) continue;
        const before = job.fields[name]?.value ?? null;
        let value = el.value.trim();
        if (type === 'money') { const c = parseCents(value); if (Number.isNaN(c)) throw new Error(`"${value}" is not an amount.`); value = c; }
        if ((value === '' || value === null) && (before === null || before === '')) continue;
        if (value !== before) out[name] = value === '' ? null : value;
      }
      const inclusive = form.elements.namedItem('taxInclusive').checked;
      if (inclusive !== !!job.fields.taxInclusive?.value) out.taxInclusive = inclusive;
      return out;
    };
    const save = async (extra = {}) => {
      const payload = {jobId: job.id, fields: readFields(), lines: readLines(), reason: form.elements.namedItem('reason').value.trim()};
      const vendorId = form.elements.namedItem('vendorId').value;
      if (vendorId !== (job.vendorId || '')) payload.vendorId = vendorId;
      const docClass = form.elements.namedItem('docClass').value;
      if (docClass && docClass !== job.docClass && ['invoice', 'credit_note', 'receipt'].includes(docClass)) payload.docClass = docClass;
      return api('invoice-intel/save', {method: 'POST', json: {...payload, ...extra}});
    };
    let dirty = false;
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('change', () => { dirty = true; });
    form.onsubmit = async event => { event.preventDefault(); const b = $('[data-ii-save]'); b.disabled = true; try { const r = await save(); toast('Saved', r.changed.length ? `${r.changed.length} change${r.changed.length === 1 ? '' : 's'} recorded. Checks updated.` : 'Nothing changed. Checks updated.', 'success'); ctx.openJob(job.id); } catch (error) { b.disabled = false; toast('Not saved', error.message, 'error'); } };
    $('[data-ii-recalc]').onclick = async () => { try { if (dirty) await save(); else await api('invoice-intel/recalculate', {method: 'POST', json: {jobId: job.id}}); ctx.openJob(job.id); } catch (error) { toast('Not recalculated', error.message, 'error'); } };
    $('[data-ii-retry]').onclick = async () => { if (!confirm('Read the file again? The fields and lines are read afresh; your corrections stay in the history but are not re-applied.')) return; try { await api('invoice-intel/retry', {method: 'POST', json: {jobId: job.id}}); ctx.openQueue({state: 'processing'}); } catch (error) { toast('Not retried', error.message, 'error'); } };
    $('[data-ii-cancel]').onclick = async () => { if (!confirm('Cancel this invoice? Nothing is posted. The original file stays stored.')) return; try { await api('invoice-intel/cancel', {method: 'POST', json: {jobId: job.id}}); ctx.openQueue({}); } catch (error) { toast('Not cancelled', error.message, 'error'); } };
    $('[data-ii-new-vendor]')?.addEventListener('click', () => ctx.openNewVendor());
    $('[data-ii-add-line]').onclick = () => { const i = tbody.querySelectorAll('tr').length; tbody.insertAdjacentHTML('beforeend', lineRow({description: '', source: 'user'}, i)); dirty = true; bindRemove(); };
    const bindRemove = () => $$('[data-ii-remove]').forEach(b => b.onclick = () => { b.closest('tr').remove(); [...tbody.querySelectorAll('tr')].forEach((tr, i) => { tr.dataset.iiLine = i; tr.querySelector('.ii-line-no').firstChild.textContent = i + 1; }); dirty = true; });
    bindRemove();
    // The invoice-level discount is spread only when the reviewer asks, by the lines' share (largest remainder), and
    // the change is saved as a correction like any other edit.
    $('[data-ii-spread]')?.addEventListener('click', () => {
      const rows = [...tbody.querySelectorAll('tr')], amounts = rows.map(tr => parseCents(tr.querySelector('[name=amountCents]').value) || 0);
      const total = amounts.reduce((a, b) => a + b, 0), discount = Math.abs(job.fields.discountCents.value);
      if (total <= 0 || discount >= total) { toast('Not spread', 'The lines must add up to more than the discount.', 'error'); return; }
      const shares = amounts.map(a => Math.floor(a * discount / total)); let left = discount - shares.reduce((a, b) => a + b, 0);
      amounts.map((a, i) => [a * discount / total - shares[i], i]).sort((x, y) => y[0] - x[0]).forEach(([, i]) => { if (left > 0) { shares[i]++; left--; } });
      rows.forEach((tr, i) => { tr.querySelector('[name=amountCents]').value = centsText(amounts[i] - shares[i]); tr.querySelector('[name=discountCents]').value = centsText(shares[i]); tr.querySelector('[name=unitPriceCents]').value = ''; });
      form.elements.namedItem('reason').value ||= 'Invoice discount spread over the lines';
      dirty = true; toast('Discount spread', 'Check the lines, then save.', 'info');
    });
    $$('[data-ii-override]').forEach(b => b.onclick = async () => {
      const reason = prompt('Why is this not a duplicate? (at least 10 characters; kept in the audit trail)');
      if (!reason) return;
      try { await api('invoice-intel/override', {method: 'POST', json: {jobId: job.id, duplicate: b.dataset.iiOverride, reason}}); ctx.openJob(job.id); } catch (error) { toast('Override refused', error.message, 'error'); }
    });
    $('[data-ii-draft]').onclick = async event => {
      const b = event.currentTarget;
      const accept = !!$('[data-ii-accept-total]')?.checked, confirmReviewed = !!$('[data-ii-confirm]')?.checked;
      b.disabled = true;
      try {
        if (dirty) await save();
        const r = await api('invoice-intel/draft', {method: 'POST', json: {jobId: job.id, acceptComputedTotal: accept, confirmReviewed}});
        toast(r.targetType === 'bill' ? 'Draft vendor invoice created' : 'Draft credit note created', `${r.number || ''} is a draft: nothing is posted until it is issued.`, 'success');
        if (r.targetType === 'bill') ctx.openRegisterAt(r.targetId); else ctx.openNote(r.targetId);
      } catch (error) { b.disabled = false; toast('Draft not created', error.message, 'error'); if (dirty) ctx.openJob(job.id); }
    };
  }

  /* ------------------------------------------------------------------ Tegh Assist */

  const QUESTION = /\b(?:import|upload|scan)\b.*\b(?:vendor|supplier)?\s*(?:invoices?|bills?|pdfs?)\b|\bmissing\b.*\b(?:gl|accounts?)\b|\bduplicat\w*\s+(?:vendor\s+|supplier\s+)?invoices?\b|\bpossible\s+duplicates?\b|\bwhy\b.*\b(?:select|choose|chose|pick|suggest)\w*\b|\b(?:invoices?|taxes?)\b.*\bmismatch|\bmismatch\w*\b.*\btax|\bneed\w*\s+(?:my\s+)?approval|\bwaiting\s+for\s+approval|\b(?:invoice\s+)?history\b.*\b(?:vendor|supplier|from|for)\b|\bvendor'?s?\s+invoice\s+history|\bprepare\b.*\b(?:invoices?|drafts?)\b.*\bdrafts?\b|\bprepare\s+these\s+invoices\b/i;

  function renderAnswer(result, answer, ctx) {
    const {esc} = ctx;
    result.innerHTML = `<section class="tegh-assist-answer ii-assist" data-ii-answer="${esc(answer.intent)}"><p>${esc(answer.text)}</p>
      ${(answer.rows || []).length ? `<ul class="ii-assist-list">${answer.rows.map(r => `<li><button type="button" class="ii-link" ${r.jobId ? `data-ii-a-job="${esc(r.jobId)}"` : r.billId ? `data-ii-a-bill="${esc(r.billId)}"` : ''}>${esc(r.label)}</button><span>${esc(r.detail || '')}</span></li>`).join('')}</ul>` : ''}
      <div class="ii-actions"><button type="button" class="srp-btn secondary" data-ii-a-open>Open Invoice Intelligence</button>${answer.action?.type === 'draft-batch' ? `<button type="button" class="srp-btn" data-ii-a-draft>${esc(answer.action.label)}</button>` : ''}</div>
      <p class="ii-muted">Answered from the invoices stored in Tegh. Text inside supplier documents is never followed as an instruction.</p></section>`;
    result.querySelectorAll('[data-ii-a-job]').forEach(b => b.onclick = () => ctx.openJob(b.dataset.iiAJob));
    result.querySelectorAll('[data-ii-a-bill]').forEach(b => b.onclick = () => ctx.openBill(b.dataset.iiABill));
    result.querySelector('[data-ii-a-open]').onclick = () => ctx.openQueue(answer.navigate || {});
    const draft = result.querySelector('[data-ii-a-draft]');
    if (draft) draft.onclick = async () => {
      if (!confirm(`${answer.action.label}? Drafts post nothing until issued.`)) return;
      draft.disabled = true; let made = 0; const problems = [];
      for (const id of answer.action.jobIds) { try { await ctx.api('invoice-intel/draft', {method: 'POST', json: {jobId: id}}); made++; } catch (error) { problems.push(error.message); } }
      ctx.toast(`${made} draft${made === 1 ? '' : 's'} created`, problems.slice(0, 2).join(' · ') || 'Issue them from the Vendor Invoice & Note Register.', problems.length ? 'warning' : 'success');
      ctx.openQueue({state: 'done'});
    };
  }

  window.TeghInvoiceIntel = Object.freeze({
    version: 'r170',
    engine: Object.freeze({rowsOf, segmentPages, classify, extractSegment, extractDocument, simhash}),
    async render(body, ctx) { return ctx.jobId ? renderJob(body, ctx) : renderQueue(body, ctx); },
    matchesQuestion: question => QUESTION.test(String(question || '')),
    renderAnswer,
    workerStatus: () => ({active: worker.active, status: worker.status}),
  });
})();
