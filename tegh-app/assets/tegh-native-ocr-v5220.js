(() => {
  'use strict';

  if (window.TeghNativeOCR && !window.TeghNativeOCR.deferred) return;

  const BUILD = '5220';
  const ROOT = '/assets/tegh-ocr/v5220';
  const PATHS = Object.freeze({
    manifest: `${ROOT}/manifest.json?v=${BUILD}`,
    pdf: `${ROOT}/pdfjs/pdf.min.mjs?v=${BUILD}`,
    pdfWorker: `${ROOT}/pdfjs/pdf.worker.min.mjs?v=${BUILD}`,
    // R145: the worker starts through a same-origin entry that adds Map/WeakMap upsert methods for browsers without them.
    pdfWorkerEntry: '/assets/tegh-pdf-worker-r145.mjs?v=5990-r145-tegh',
    upsert: '/assets/tegh-upsert-polyfill-r145.js?v=5990-r145-tegh',
    tesseract: `${ROOT}/tesseract/tesseract.min.js?v=${BUILD}`,
    worker: `${ROOT}/tesseract/worker.min.js?v=${BUILD}`,
    core: `${ROOT}/tesseract/tesseract-core-lstm.wasm.js?v=${BUILD}`,
    language: `${ROOT}/lang`,
  });
  const MANIFEST_SHA256 = '5664b221e0a2dedbe77fc0d3c14fb6ec9591f5f05420d7d6ef0b48d75321f29d';
  const MAX_PAGES = 8;
  const MAX_TEXT = 50000;
  let manifestPromise = null;
  const groupPromises = new Map();
  let pdfModulePromise = null;
  let tesseractPromise = null;

  const progress = (callback, status, value, detail = '') => {
    try { callback?.({ status, progress: Math.max(0, Math.min(1, value)), detail }); } catch (_) {}
  };

  const sha256 = async (bytes) => {
    if (!window.crypto?.subtle) throw new Error('This browser cannot verify the bundled OCR runtime.');
    const digest = await window.crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest), (value) => value.toString(16).padStart(2, '0')).join('');
  };

  const runtimeError = (code, message) => Object.assign(new Error(message), { code });

  function expectedMime(path) {
    if (path.endsWith('.wasm')) return ['application/wasm', 'application/octet-stream'];
    if (path.endsWith('.mjs') || path.endsWith('.js')) return ['text/javascript', 'application/javascript', 'application/x-javascript'];
    if (path.endsWith('.traineddata')) return ['application/octet-stream', 'application/gzip', 'binary/octet-stream'];
    return [];
  }

  async function loadManifest() {
    manifestPromise ||= (async () => {
      const response = await fetch(PATHS.manifest, { credentials: 'same-origin', cache: 'force-cache' });
      if (!response.ok) throw runtimeError('ocr_manifest_unavailable', 'The bundled OCR integrity manifest is unavailable.');
      const bytes = await response.arrayBuffer();
      if (!window.crypto?.subtle || !MANIFEST_SHA256 || await sha256(bytes) !== MANIFEST_SHA256) {
        throw runtimeError('ocr_manifest_integrity_failed', 'The bundled OCR integrity manifest did not pass verification.');
      }
      const manifest = JSON.parse(new TextDecoder().decode(bytes));
      if (manifest.release !== '5.2.2' || Number(manifest.build) !== 5220 || manifest.execution !== 'browser-only'
        || manifest.providerRequests !== 0 || manifest.rawTextPersistence !== 'none'
        || !manifest.runtimeGroups || !Array.isArray(manifest.runtimeAssets) || !Array.isArray(manifest.legalAssets)) {
        throw runtimeError('ocr_manifest_identity_failed', 'The bundled OCR integrity manifest has an unexpected safety identity.');
      }
      const runtimePaths = new Set();
      for (const item of manifest.runtimeAssets) {
        if (!item || typeof item.path !== 'string' || !item.path.startsWith(`${ROOT}/`)
          || !Number.isSafeInteger(item.sizeBytes) || !/^[a-f0-9]{64}$/.test(String(item.sha256 || ''))) {
          throw runtimeError('ocr_manifest_asset_invalid', 'The bundled OCR integrity manifest contains an invalid runtime record.');
        }
        runtimePaths.add(item.path);
      }
      for (const group of ['pdf_text', 'ocr']) {
        if (!Array.isArray(manifest.runtimeGroups[group]) || manifest.runtimeGroups[group].length === 0
          || manifest.runtimeGroups[group].some((path) => !runtimePaths.has(path))) {
          throw runtimeError('ocr_manifest_group_invalid', 'The bundled OCR integrity manifest has an invalid runtime group.');
        }
      }
      // Legal/package records are intentionally validated by the release audit,
      // never fetched by the browser. In particular, Apache continues to deny
      // public access to the packaged Tesseract LICENSE.md file.
      return Object.freeze(manifest);
    })().catch((error) => { manifestPromise = null; throw error; });
    return manifestPromise;
  }

  async function verifyAssets(group = 'all_runtime') {
    const groups = group === 'all_runtime' ? ['pdf_text', 'ocr'] : [String(group)];
    const manifest = await loadManifest();
    for (const name of groups) {
      if (!['pdf_text', 'ocr'].includes(name)) throw runtimeError('ocr_runtime_group_invalid', 'Choose a registered OCR runtime group.');
      if (!groupPromises.has(name)) {
        groupPromises.set(name, (async () => {
          const records = new Map(manifest.runtimeAssets.map((item) => [item.path, item]));
          for (const path of manifest.runtimeGroups[name]) {
            const item = records.get(path);
            const url = new URL(path, window.location.origin);
            if (url.origin !== window.location.origin) throw runtimeError('ocr_runtime_origin_invalid', 'A bundled OCR runtime path is not same-origin.');
            url.searchParams.set('v', BUILD);
            const assetResponse = await fetch(url, { credentials: 'same-origin', cache: 'force-cache' });
            if (!assetResponse.ok) throw runtimeError('ocr_runtime_asset_unavailable', `A bundled OCR runtime asset is unavailable: ${path}`);
            const allowedMime = expectedMime(path);
            const mime = String(assetResponse.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
            if (allowedMime.length && mime && !allowedMime.includes(mime)) {
              throw runtimeError('ocr_runtime_mime_invalid', `A bundled OCR runtime asset has an invalid content type: ${path}`);
            }
            const assetBytes = await assetResponse.arrayBuffer();
            if (assetBytes.byteLength !== item.sizeBytes || await sha256(assetBytes) !== item.sha256) {
              throw runtimeError('ocr_runtime_integrity_failed', `A bundled OCR runtime asset did not pass verification: ${path}`);
            }
          }
          return Object.freeze({ group: name, verified: true, assetCount: manifest.runtimeGroups[name].length });
        })().catch((error) => { groupPromises.delete(name); throw error; }));
      }
      await groupPromises.get(name);
    }
    return Object.freeze({ verified: true, groups });
  }

  const loadScript = (source) => new Promise((resolve, reject) => {
    const existing = document.querySelector(`script[data-tegh-native-ocr="${source}"]`);
    if (existing) {
      if (existing.dataset.loaded === '1') resolve();
      else existing.addEventListener('load', resolve, { once: true });
      return;
    }
    const script = document.createElement('script');
    script.src = source;
    script.async = true;
    script.dataset.teghNativeOcr = source;
    script.onload = () => { script.dataset.loaded = '1'; resolve(); };
    script.onerror = () => { script.remove(); reject(new Error('The bundled local OCR runtime could not be loaded.')); };
    document.head.appendChild(script);
  });

  async function pdfjs() {
    pdfModulePromise ||= verifyAssets('pdf_text').then(() => import(PATHS.upsert)).then(() => import(PATHS.pdf)).then((module) => {
      module.GlobalWorkerOptions.workerSrc = PATHS.pdfWorkerEntry;
      return module;
    }).catch(error => { pdfModulePromise = null; throw error; });
    return pdfModulePromise;
  }

  async function tesseract() {
    tesseractPromise ||= verifyAssets('ocr').then(() => loadScript(PATHS.tesseract)).then(() => {
      if (!window.Tesseract?.createWorker) throw new Error('The bundled local OCR runtime is unavailable.');
      return window.Tesseract;
    }).catch(error => { tesseractPromise = null; throw error; });
    return tesseractPromise;
  }

  const normalizeText = (value) => String(value || '')
    .replace(/\u0000/g, ' ')
    .replace(/[\t ]+/g, ' ')
    .replace(/\r\n?/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
    .slice(0, MAX_TEXT);

  // PDF.js returns positioned runs, often one run per column. Keep the visual
  // rows before parsing fields; joining every run with a space erases labels.
  function layoutTextFromItems(items) {
    const runs = (items || []).filter(item => typeof item.str === 'string' && item.str.trim()).map((item, index) => ({
      str: item.str.trim(), x: Number(item.transform?.[4]), y: Number(item.transform?.[5]),
      height: Math.abs(Number(item.height || item.transform?.[3] || 10)), index, end: !!item.hasEOL,
    }));
    if (!runs.length) return '';
    if (runs.some(item => !Number.isFinite(item.x) || !Number.isFinite(item.y))) {
      return normalizeText(runs.map(item => item.str + (item.end ? '\n' : ' ')).join(''));
    }
    const rows = [];
    for (const run of runs) {
      const tolerance = Math.max(2, Math.min(5, run.height * 0.3));
      let row = rows.find(row => Math.abs(row.y - run.y) <= tolerance);
      if (!row) { row = {y: run.y, first: run.index, runs: []}; rows.push(row); }
      row.runs.push(run);
    }
    rows.sort((a, b) => b.y - a.y || a.first - b.first);
    return normalizeText(rows.map(row => row.runs.sort((a, b) => a.x - b.x || a.index - b.index).map(item => item.str).join(' ')).join('\n'));
  }

  // R145: field reader for bills, invoices and receipts. Works on the text of a PDF or an OCR'd image, in English
  // and French (Québec). Labels are matched with their value on the same line or the line below. Numeric dates
  // that read both ways (03/04/2026) are never guessed: both readings go to alternatives for the reviewer.
  const MONTHS_EN = {jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};
  const MONTHS_FR = {janv:1,janvier:1,fevr:2,fevrier:2,fev:2,mars:3,avr:4,avril:4,mai:5,juin:6,juil:7,juillet:7,aout:8,sept:9,septembre:9,oct:10,octobre:10,nov:11,novembre:11,dec:12,decembre:12};
  const fold = value => String(value || '').normalize('NFD').replace(/[̀-ͯ]/g, '');
  const ymd = (year, month, day) => {
    const d = new Date(Date.UTC(Number(year), Number(month) - 1, Number(day)));
    return d.getUTCFullYear() === Number(year) && d.getUTCMonth() + 1 === Number(month) && d.getUTCDate() === Number(day)
      ? `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}` : '';
  };
  const EN_MONTH = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\\.?';
  const FR_MONTH = '(janv(?:ier)?|f[eé]v(?:r(?:ier)?)?|mars|avr(?:il)?|mai|juin|juil(?:let)?|ao[uû]t|sept(?:embre)?|oct(?:obre)?|nov(?:embre)?|d[eé]c(?:embre)?)\\.?';
  /** Every date in a piece of text: {iso, alternatives[], index}. Ambiguous numeric dates have iso '' and two alternatives. */
  function datesIn(value) {
    const raw = String(value || ''), found = [];
    const push = (index, iso, alternatives = []) => { if (iso || alternatives.length) found.push({index, iso, alternatives}); };
    for (const m of raw.matchAll(/\b(20\d{2})[-/.](0?[1-9]|1[0-2])[-/.](0?[1-9]|[12]\d|3[01])\b/g)) push(m.index, ymd(m[1], m[2], m[3]));
    for (const m of raw.matchAll(/\b(0?[1-9]|[12]\d|3[01])[-/.](0?[1-9]|[12]\d|3[01])[-/.](20\d{2})\b/g)) {
      const a = Number(m[1]), b = Number(m[2]);
      if (a > 12) push(m.index, ymd(m[3], b, a));                 // 25/03/2026 → day first
      else if (b > 12) push(m.index, ymd(m[3], a, b));            // 03/25/2026 → month first
      else if (a === b) push(m.index, ymd(m[3], a, b));
      else push(m.index, '', [ymd(m[3], a, b), ymd(m[3], b, a)].filter(Boolean)); // 03/04/2026: March 4 or 3 April
    }
    for (const m of raw.matchAll(new RegExp(`\\b${EN_MONTH}\\s+(\\d{1,2})(?:st|nd|rd|th)?,?\\s+(20\\d{2})\\b`, 'gi'))) push(m.index, ymd(m[3], MONTHS_EN[m[1].slice(0, 3).toLowerCase()], m[2]));
    for (const m of raw.matchAll(new RegExp(`\\b(\\d{1,2})(?:st|nd|rd|th|er)?\\s+${EN_MONTH},?\\s+(20\\d{2})\\b`, 'gi'))) push(m.index, ymd(m[3], MONTHS_EN[m[2].slice(0, 3).toLowerCase()], m[1]));
    for (const m of raw.matchAll(new RegExp(`\\b(\\d{1,2})(?:er)?\\s+${FR_MONTH}\\s+(20\\d{2})\\b`, 'gi'))) { const key = fold(m[2]).toLowerCase().replace(/\.$/, ''); const month = MONTHS_FR[key] ?? MONTHS_FR[key.slice(0, 4)] ?? MONTHS_FR[key.slice(0, 3)]; push(m.index, month ? ymd(m[3], month, m[1]) : ''); }
    return found.sort((x, y) => x.index - y.index);
  }
  const isoDate = value => datesIn(value).find(d => d.iso)?.iso || '';

  /** Money tokens on a line: "$1,234.56", "1 234,56 $" (French), "45.20-", "(12.50)". Returns signed cents. */
  function moneyIn(line, french) {
    const out = [], src = String(line || '');
    const re = french
      ? /(\(?-?\$?\s?)(\d{1,3}(?:[ \u00a0\u202f.]\d{3})*|\d+),(\d{2})(?!\d)(\s?\$)?(\)?-?)/g
      : /(\(?-?(?:CAD|USD|US\$|C\$|\$|€|£)?\s?)(\d{1,3}(?:,\d{3})*|\d+)\.(\d{2})(?!\d)(\s?(?:CAD|USD|\$))?(\)?-?)/g;
    for (const m of src.matchAll(re)) {
      const digits = m.index + m[1].length, before = src.slice(Math.max(0, digits - 1), digits);
      if (/[\d.,%]/.test(before) || /%/.test(src.slice(m.index + m[0].length, m.index + m[0].length + 1))) continue; // part of a longer number or a rate
      const whole = m[2].replace(/[ \u00a0\u202f.,]/g, ''), value = Number(whole) * 100 + Number(m[3]);
      const negative = /-/.test(m[1]) || /-$/.test(m[5]) || (/\(/.test(m[1]) && /\)/.test(m[5]));
      if (Number.isSafeInteger(value)) out.push(negative ? -value : value);
    }
    return out;
  }
  const cents = value => { const found = moneyIn(String(value || ''), false); return found.length ? found[found.length - 1] : null; };

  function candidateFromText(rawText) {
    const text = normalizeText(rawText), lines = text.split('\n').map(x => x.trim()).filter(Boolean), folded = lines.map(x => fold(x));
    const french = /\b(TPS|TVQ|TVH|sous-?total|montant|facture|re[cç]u|payer|taxes?\s+incluses)\b/i.test(fold(text)) && !/\b\d{1,3}(,\d{3})*\.\d{2}\b/.test(text);
    // A labelled amount: the label starts the line (or follows a short prefix), the amount is the last on that line,
    // or the next line holds only an amount.
    const labelled = (labels, exclude = null) => {
      const hits = [];
      folded.forEach((line, i) => {
        const re = new RegExp(`^(?:[^A-Za-z0-9]{0,3})(?:${labels})\\b`, 'i');
        if (!re.test(line) || (exclude && exclude.test(line))) return;
        let amounts = moneyIn(lines[i], french);
        if (!amounts.length && i + 1 < lines.length && /^[^A-Za-z]*$/.test(lines[i + 1].replace(/\b(CAD|USD)\b/g, ''))) amounts = moneyIn(lines[i + 1], french);
        if (amounts.length) hits.push({line: i, cents: amounts[amounts.length - 1]});
      });
      return hits;
    };
    const SUB = 'sub\\s*-?\\s*total|sous\\s*-?\\s*total|total\\s+before\\s+tax(?:es)?|net\\s+(?:amount|total)|total\\s+(?:hors\\s+taxes|HT)|montant\\s+avant\\s+taxes';
    const subtotalHits = labelled(SUB);
    const taxTotalHits = labelled('total\\s+tax(?:es)?|tax(?:es)?\\s+total|total\\s+des\\s+taxes|sales\\s+tax(?:es)?');
    const TAXWORD = /^(?:[^A-Za-z]{0,3})(GST\s*\/\s*HST|TPS\s*\/\s*TVH|HST|GST|PST|QST|RST|TPS|TVQ|TVH|TVP|VAT|tax(?:e)?)\b/i;
    const components = [];
    folded.forEach((line, i) => {
      if (!TAXWORD.test(line) || /\b(total|exempt)\b/i.test(line)) return;
      const included = /\b(incl|included|incluses?|comprises?)\b/i.test(line);
      // Registration numbers ("GST/HST # 123456789 RT0001", "TVQ 1234567890 TQ0001") are identity, not amounts.
      const stripped = lines[i].replace(/\b\d{9}\s*(RT|TQ|RR)\s*\d{4}\b|\b\d{10}\s*TQ\s*\d{4}\b/gi, ' ').replace(/\b(?:no|n°|#|number|reg(?:istration)?\.?)\s*[:#]?\s*[A-Z0-9 -]{6,}$/i, ' ');
      let amounts = moneyIn(stripped, french);
      if (!amounts.length && i + 1 < lines.length && /^[^A-Za-z]*$/.test(lines[i + 1])) amounts = moneyIn(lines[i + 1], french);
      if (amounts.length) components.push({line: i, included, label: TAXWORD.exec(line)[1].toUpperCase().replace(/\s+/g, ''), cents: amounts[amounts.length - 1]});
    });
    const totalHits = labelled('invoice\\s+total|grand\\s+total|total\\s+(?:amount|cad|usd|payable|ttc|a\\s+payer|due)|amount\\s+\\(?incl|montant\\s+total|total',
      /\b(sub|sous|before|avant|hors|HT\b|tax(es)?\s+total|total\s+tax|total\s+(gst|hst|pst|qst|tps|tvq|tvh)|des\s+taxes|items?|articles?|qty|quantit|savings|economies|discount|points|pts)\b/i);
    const dueHits = labelled('(?:total\\s+)?amount\\s+(?:due|payable)|balance\\s+(?:due|owing)|total\\s+due|montant\\s+(?:du|a\\s+payer|exigible)|solde\\s+du|please\\s+pay');
    const subtotal = subtotalHits.length ? subtotalHits[0].cents : null;
    // Tax printed as "included" counts only when no separate tax lines are printed.
    const separate = components.filter(c => !c.included), counted = separate.length ? separate : components;
    const componentSum = counted.length ? counted.reduce((sum, c) => sum + c.cents, 0) : null;
    let tax = taxTotalHits.length ? taxTotalHits[taxTotalHits.length - 1].cents : componentSum;
    // Several "total" lines (receipts print TOTAL, then the card tender): prefer the one that equals subtotal + tax,
    // then the largest positive one.
    let total = null;
    if (totalHits.length) {
      const exact = Number.isInteger(subtotal) && Number.isInteger(tax) ? totalHits.find(h => h.cents === subtotal + tax) : null;
      total = exact ? exact.cents : Math.max(...totalHits.map(h => h.cents));
    }
    const payable = dueHits.length ? dueHits[dueHits.length - 1].cents : null;
    // Labelled document date (same line or next line), then any date not on a due-date line.
    const DUE_RE = /\b(due|echeance|payable\s+by|pay\s+by|payment\s+due)\b/i;
    const beforeDue = (line, foldedLine) => { const m = DUE_RE.exec(foldedLine); return m ? line.slice(0, m.index) : line; };
    const dateAfterLabel = (labelRe, stopAtDue) => {
      for (let i = 0; i < lines.length; i++) {
        const m = labelRe.exec(folded[i]); if (!m || (stopAtDue && /(due|echeance|payment|pay\s+by)\s*$/i.test(folded[i].slice(0, m.index)))) continue;
        const start = m.index + m[0].length, restFolded = folded[i].slice(start);
        let rest = lines[i].slice(start); if (stopAtDue) rest = beforeDue(rest, restFolded);
        const here = datesIn(rest)[0] || (i + 1 < lines.length ? datesIn(stopAtDue ? beforeDue(lines[i + 1], folded[i + 1]) : lines[i + 1])[0] : null);
        if (here) return here;
      }
      return null;
    };
    const docDate = dateAfterLabel(/\b(?:invoice|bill|document|receipt|transaction|statement|issue[d]?|order)\s+date\b|\bdate\s+(?:de\s+(?:la\s+)?facture|d'emission|de\s+transaction)\b|\bdate(?:\s+of\s+(?:invoice|issue))?\s*[:：]|^date\b/i, true)
      || datesIn(lines.map((line, i) => beforeDue(line, folded[i])).join('\n'))[0] || null;
    const dueDate = dateAfterLabel(/\b(?:due\s+date|date\s+due|payment\s+due|payable\s+by|date\s+d'echeance|echeance)\b/i, false);
    const labelledName = text.match(/^(?:vendor|supplier|seller|from|fournisseur|vendeur)(?:\s+(?:name|legal name))?\s*[:：]\s*(.+)$/im)?.[1];
    // An unlabelled three-character OCR fragment is weak identity evidence; leave the party blank instead of guessing.
    // A heading row often carries the document type next to the business name ("Northwind Ltd.   INVOICE"); drop that word.
    const partyLine = labelledName || lines.map(line => line.replace(/\s*\b(?:tax\s+invoice|invoice|facture|receipt|re[cç]u|bill)\b\s*$/i, '').replace(/^\s*\b(?:tax\s+invoice|invoice|facture|receipt|re[cç]u)\b\s+/i, '').trim()).find(line => line.length >= 4 && !moneyIn(line, french).length && line.length <= 160 && /[a-z]{2}/i.test(line)
      && !/\b(invoice|facture|bill\s+to|ship\s+to|sold\s+to|factur[eé]\s+[aà]|statement|receipt|re[cç]u|total|date|tax|gst|hst|pst|qst|tps|tvq|registration|account\s+number|description|quantity|amount|payment|terms|page|thank|merci|welcome|bienvenue|customer\s+copy|copie)\b/i.test(line)
      && !/@|https?:|www\.|\.com\b|\.ca\b/i.test(line) && !/^\+?[\d\s().-]{7,}$/.test(line) && !/^\d+\s+\S+.*\b(st|street|rd|road|ave|avenue|blvd|boul|rue|ch|chemin|dr|drive|way|unit|suite)\b/i.test(line)) || '';
    const identifier = text.match(/\b\d{9}\s*RT\s*\d{4}\b/i)?.[0]
      || text.match(/\b(?:GST\s*\/?\s*HST|HST|GST|TPS(?:\s*\/\s*TVH)?|TVQ|QST|VAT|tax|business|registration|registeration)\s*(?:(?:registration|registeration|reg(?:istration)?\.?|number|no\.?|n°)\s*)?[:#]\s*([A-Z0-9][A-Z0-9 -]{4,35})/i)?.[1]?.trim() || '';
    const numberMatch = fold(text).match(/\b(?:invoice|bill|document|receipt|transaction|order|facture|recu)\s*(?:number|no\.?|n[o°º]\.?|#|num(?:ero)?\.?)\s*[:#-]?\s*([A-Z0-9][A-Z0-9._/-]{0,39})/i)
      || fold(text).match(/^(?:invoice|facture)\s*[:#]?\s*([A-Z]{0,6}-?\d[A-Z0-9._/-]{0,39})\b/im);
    const number = numberMatch && /\d/.test(numberMatch[1]) ? numberMatch[1] : '';
    const termsText = text.match(/\b(?:payment\s+terms|terms|conditions(?:\s+de\s+paiement)?)\s*[:：]\s*([^\n]{1,80})/i)?.[1] || (text.match(/\b(net\s*\d{1,3}(?:\s*days)?|due\s+on\s+receipt|payable\s+[aà]\s+r[eé]ception)\b/i)?.[1] || '');
    const candidate = {partyName: partyLine, partyEmail: text.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i)?.[0] || '', businessIdentifier: identifier, documentNumber: number,
      documentDate: docDate?.iso || '', dueDate: dueDate?.iso || '',
      currency: /\bUSD\b|US\$/i.test(text) ? 'USD' : /\bEUR\b|€/.test(text) ? 'EUR' : /\bGBP\b|£/.test(text) ? 'GBP' : 'CAD', paymentTerms: termsText.trim(), confidenceBps: 0, alternatives: [], lineItems: []};
    if (docDate && !docDate.iso) candidate.alternatives = docDate.alternatives.map(documentDate => ({documentDate}));
    // "Net 30" with a known date gives the due date by plain day arithmetic; the reviewer still confirms it.
    const net = /\bnet\s*(\d{1,3})\b/i.exec(candidate.paymentTerms);
    if (!candidate.dueDate && candidate.documentDate && net) candidate.dueDate = new Date(Date.parse(candidate.documentDate + 'T00:00:00Z') + Number(net[1]) * 86400000).toISOString().slice(0, 10);
    if (Number.isInteger(total) && Number.isInteger(subtotal) && tax === null && total >= subtotal) tax = total - subtotal;
    const finalTotal = total ?? (Number.isInteger(subtotal) && Number.isInteger(tax) ? subtotal + tax : payable);
    if (Number.isInteger(finalTotal) && finalTotal >= 0) candidate.totalCents = finalTotal;
    if (Number.isInteger(payable) && payable >= 0) candidate.amountDueCents = payable;
    if (Number.isInteger(tax) && tax >= 0) candidate.taxCents = tax;
    if (Number.isInteger(subtotal) && subtotal >= 0) candidate.subtotalCents = subtotal;
    // Derive only exact arithmetic; inconsistent printed totals remain for human review.
    if (Number.isInteger(candidate.totalCents) && Number.isInteger(candidate.taxCents) && candidate.subtotalCents === undefined && candidate.totalCents >= candidate.taxCents) candidate.subtotalCents = candidate.totalCents - candidate.taxCents;
    // Line items: rows under a heading that names a description and a money column.
    const heading = folded.findIndex(x => /^(?:description|item\s+description|items?|articles?|produits?)\b/i.test(x) || (/\b(description|item|article)\b/i.test(x) && /\b(amount|price|total|montant|prix|qty|quantity|quantite)\b/i.test(x)));
    if (heading >= 0) {
      for (const line of lines.slice(heading + 1)) {
        if (TAXWORD.test(fold(line)) || /^(sub\s*-?\s*total|sous\s*-?\s*total|grand\s+total|total|amount\s+(due|payable)|balance\s+due|payment|terms|montant)\b/i.test(fold(line))) break;
        const amounts = moneyIn(line, french);
        if (!amounts.length || !/[A-Za-z]{2}/.test(line)) continue;
        const description = line.replace(/(?:\s+(?:CAD|USD|\$)?\s*-?\(?\$?\d[\d ,.]*\)?\s*\$?)+\s*$/, '').replace(/^\d+(?:\.\d+)?\s*[x×]?\s+/, '').trim();
        if (description && amounts[amounts.length - 1] > 0) candidate.lineItems.push({description: description.slice(0, 300), amountCents: amounts[amounts.length - 1]});
        if (candidate.lineItems.length >= 100) break;
      }
    }
    if (candidate.lineItems.length) candidate.memo = candidate.lineItems.map(x => x.description).join('; ').slice(0, 500);
    const known = [candidate.partyName, candidate.documentNumber, candidate.documentDate, candidate.totalCents].filter(x => x !== '' && x !== undefined).length;
    candidate.confidenceBps = Math.min(9000, 2000 + known * 1500 + (identifier ? 500 : 0));
    if (Number.isInteger(candidate.subtotalCents) && Number.isInteger(candidate.taxCents) && Number.isInteger(candidate.totalCents)) {
      if (candidate.subtotalCents + candidate.taxCents !== candidate.totalCents) { candidate.confidenceBps = Math.min(candidate.confidenceBps, 4000); delete candidate.subtotalCents; }
      else candidate.confidenceBps = Math.min(9500, candidate.confidenceBps + 500); // the document's own arithmetic agrees
    }
    if (candidate.alternatives.length) candidate.confidenceBps = Math.min(candidate.confidenceBps, 6000);
    return candidate;
  }

  async function createOcrWorker(onProgress) {
    const runtime = await tesseract();
    return runtime.createWorker('eng', 1, {
      workerPath: PATHS.worker,
      corePath: PATHS.core,
      langPath: PATHS.language,
      gzip: false,
      cacheMethod: 'write',
      logger: (message) => progress(onProgress, message.status || 'ocr', Number(message.progress || 0), 'Local OCR'),
    });
  }

  // R145: OCR reads clean, upright, grey pages best. Photos are turned upright from their camera orientation, scaled so
  // text is large enough (small photos up, huge photos down), turned grey and contrast-stretched (1st–99th percentile).
  function enhanceCanvas(canvas) {
    const context = canvas.getContext('2d', { willReadFrequently: true });
    const image = context.getImageData(0, 0, canvas.width, canvas.height), data = image.data, histogram = new Uint32Array(256);
    for (let i = 0; i < data.length; i += 4) { const grey = (data[i] * 299 + data[i + 1] * 587 + data[i + 2] * 114) / 1000 | 0; data[i] = grey; histogram[grey]++; }
    const pixels = data.length / 4; let low = 0, high = 255, seen = 0;
    for (let v = 0; v < 256; v++) { seen += histogram[v]; if (seen >= pixels * 0.01) { low = v; break; } }
    seen = 0; for (let v = 255; v >= 0; v--) { seen += histogram[v]; if (seen >= pixels * 0.01) { high = v; break; } }
    // Stretch only a genuinely low-contrast page. On a clean page with little text both percentiles can sit on the white
    // background, and stretching would turn every slightly-off-white pixel black.
    const span = high - low;
    if (span >= 24 && span < 96) for (let i = 0; i < data.length; i += 4) { const v = Math.max(0, Math.min(255, Math.round((data[i] - low) * 255 / span))); data[i] = data[i + 1] = data[i + 2] = v; }
    context.putImageData(image, 0, 0);
    return canvas;
  }
  async function prepareImage(file) {
    if (typeof createImageBitmap !== 'function') return file;
    let bitmap;
    try { bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (_) { return file; }
    try {
      const longest = Math.max(bitmap.width, bitmap.height);
      const scale = longest < 1600 ? Math.min(3, 1600 / longest) : longest > 3200 ? 3200 / longest : 1;
      const canvas = window.document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(bitmap.width * scale)); canvas.height = Math.max(1, Math.round(bitmap.height * scale));
      const context = canvas.getContext('2d', { alpha: false, willReadFrequently: true });
      context.fillStyle = '#fff'; context.fillRect(0, 0, canvas.width, canvas.height);
      context.imageSmoothingQuality = 'high'; context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      return enhanceCanvas(canvas);
    } finally { bitmap.close?.(); }
  }

  async function recognize(worker, source) {
    const result = await worker.recognize(source, {}, { text: true });
    return normalizeText(result?.data?.text || '');
  }

  async function extractPdf(file, onProgress) {
    const module = await pdfjs();
    const bytes = new Uint8Array(await file.arrayBuffer());
    if (new TextDecoder().decode(bytes.slice(0, 5)) !== '%PDF-') throw new TypeError('This file is not a PDF.');
    const document = await module.getDocument({ data: bytes, isEvalSupported: false }).promise;
    const totalPages = document.numPages;
    const pages = Math.min(totalPages, MAX_PAGES);
    let worker = null;
    let text = '';
    let usedOcr = false;
    try {
      for (let index = 1; index <= pages; index += 1) {
        progress(onProgress, 'reading_pdf', (index - 1) / Math.max(1, pages), `Page ${index} of ${pages}`);
        const page = await document.getPage(index);
        const content = await page.getTextContent();
        let pageText = layoutTextFromItems(content.items);
        if (pageText.replace(/\s/g, '').length < 55 && !/(?:invoice|bill|document)\s*(?:number|no\.?|#)|(?:amount|balance)\s+due/i.test(pageText)) {
          worker ||= await createOcrWorker(onProgress);
          const viewport = page.getViewport({ scale: 2.5 });
          const canvas = window.document.createElement('canvas');
          canvas.width = Math.ceil(viewport.width);
          canvas.height = Math.ceil(viewport.height);
          const context = canvas.getContext('2d', { alpha: false });
          await page.render({ canvasContext: context, viewport }).promise;
          pageText = await recognize(worker, enhanceCanvas(canvas));
          canvas.width = 1;
          canvas.height = 1;
          usedOcr = true;
        }
        text = normalizeText(`${text}\n${pageText}`);
        page.cleanup();
      }
    } finally {
      await worker?.terminate().catch(() => {});
      await document.destroy().catch(() => {});
    }
    return { text, method: usedOcr ? 'ocr' : 'pdf_text', pageCount: pages, truncatedPages: totalPages > pages };
  }

  async function extractImage(file, onProgress) {
    const worker = await createOcrWorker(onProgress);
    try {
      const source = await prepareImage(file);
      const text = await recognize(worker, source);
      if (source !== file) { source.width = 1; source.height = 1; }
      return { text, method: 'ocr', pageCount: 1, truncatedPages: false };
    }
    finally { await worker.terminate().catch(() => {}); }
  }

  async function extract(file, options = {}) {
    if (!(file instanceof Blob)) throw new TypeError('Choose a PDF, PNG, JPEG or WebP document.');
    if (file.size <= 0 || file.size > 10 * 1024 * 1024) throw new RangeError('Documents must be between 1 byte and 10 MB.');
    const type = String(file.type || '').toLowerCase();
    if (!['application/pdf', 'image/png', 'image/jpeg', 'image/webp'].includes(type)) throw new TypeError('Choose a PDF, PNG, JPEG or WebP document.');
    progress(options.onProgress, 'starting', 0, 'Preparing local extraction');
    const result = type === 'application/pdf' ? await extractPdf(file, options.onProgress) : await extractImage(file, options.onProgress);
    const rawText = normalizeText(result.text);
    const candidate = candidateFromText(rawText);
    progress(options.onProgress, 'complete', 1, 'Ready for human review');
    return Object.freeze({
      candidate,
      extractionMethod: result.method,
      rawText,
      diagnostics: Object.freeze({ pageCount: result.pageCount, textLength: rawText.length, truncatedPages: result.truncatedPages, localOnly: true, providerAttempts: 0 }),
    });
  }

  // Banking requires all pages and original coordinates. The general document
  // extractor intentionally remains separately bounded and returns candidates.
  async function extractStatementPages(file, options = {}) {
    if (!(file instanceof Blob) || file.size < 1 || file.size > 10 * 1024 * 1024)
      throw new RangeError('Choose a PDF no larger than 10 MB.');
    const signature = new TextDecoder().decode(await file.slice(0, 5).arrayBuffer());
    if (signature !== '%PDF-') throw new TypeError('This file is not a PDF.');
    const signal = options.signal;
    const abortError = () => new DOMException('Statement reading cancelled.', 'AbortError');
    const check = () => { if (signal?.aborted) throw abortError(); };
    check();
    const runtime = await pdfjs(); check();
    const loading = runtime.getDocument({data:new Uint8Array(await file.arrayBuffer()),isEvalSupported:false});
    let pdf = null, worker = null, renderTask = null, timer = null;
    const cancel = () => { renderTask?.cancel(); void worker?.terminate().catch(() => {}); void loading.destroy().catch(() => {}); };
    signal?.addEventListener('abort', cancel, {once:true});
    loading.onPassword = async (update, reason) => {
      try {
        const password = await options.onPassword?.(reason === 2);
        if (password == null || signal?.aborted) { cancel(); return; }
        update(password);
      } catch (_) { cancel(); }
    };
    try {
      timer = setTimeout(cancel, 180000);
      pdf = await loading.promise; check();
      if (pdf.numPages > 30) throw new RangeError('PDF conversion supports up to 30 pages. Split this statement before uploading.');
      const pages = []; let ocrPages = 0, characters = 0;
      for (let index = 1; index <= pdf.numPages; index++) {
        check();
        progress(options.onProgress, 'reading_pdf', (index - 1) / pdf.numPages, `Page ${index} of ${pdf.numPages}`);
        const page = await pdf.getPage(index);
        try {
          const content = await page.getTextContent(); check();
          const items = content.items.filter(item => typeof item.str === 'string').map(item => ({str:item.str,transform:item.transform,width:item.width,height:item.height}));
          let text = layoutTextFromItems(items), method = 'Text';
          if (text.replace(/\s/g, '').length < 50) {
            if (++ocrPages > 8) throw new RangeError('Local OCR supports up to 8 scanned pages per upload. Split this statement and try again.');
            worker ||= await createOcrWorker(options.onProgress); check();
            const viewport = page.getViewport({scale:1.75});
            if (viewport.width * viewport.height > 16000000) throw new RangeError('A PDF page is too large for local OCR. Export a standard-size statement.');
            const canvas = document.createElement('canvas'); canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
            try {
              renderTask = page.render({canvasContext:canvas.getContext('2d',{alpha:false}),viewport});
              await renderTask.promise; check(); text = await recognize(worker, canvas); check(); method = 'OCR';
            } finally {renderTask = null; canvas.width = canvas.height = 1;}
          }
          characters += text.length;
          if (characters > 500000) throw new RangeError('This PDF contains too much text for one statement import. Split the file.');
          pages.push({page:index,items:method === 'Text' ? items : [],text,method});
        } finally {page.cleanup();}
      }
      progress(options.onProgress, 'complete', 1, 'Review extracted transactions');
      return {pages,pageCount:pdf.numPages,ocrPages,localOnly:true,providerAttempts:0};
    } catch (error) {
      if (signal?.aborted) throw abortError();
      throw error;
    } finally {
      clearTimeout(timer); signal?.removeEventListener('abort', cancel);
      await worker?.terminate().catch(() => {});
      if (pdf) await pdf.destroy().catch(() => {}); else await loading.destroy().catch(() => {});
    }
  }

  window.TeghNativeOCR = Object.freeze({
    version: '5.2.2',
    build: 5220,
    localOnly: true,
    paths: PATHS,
    manifestSha256: MANIFEST_SHA256,
    loadManifest,
    verifyAssets,
    verifyRuntimeGroups: () => verifyAssets('all_runtime'),
    extract,
    candidateFromText,
    layoutTextFromItems,
    extractStatementPages,
  });
})();
