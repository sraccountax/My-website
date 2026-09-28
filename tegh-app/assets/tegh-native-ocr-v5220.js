(() => {
  'use strict';

  if (window.TeghNativeOCR && !window.TeghNativeOCR.deferred) return;

  const BUILD = '5220';
  const ROOT = '/assets/tegh-ocr/v5220';
  const PATHS = Object.freeze({
    manifest: `${ROOT}/manifest.json?v=${BUILD}`,
    pdf: `${ROOT}/pdfjs/pdf.min.mjs?v=${BUILD}`,
    pdfWorker: `${ROOT}/pdfjs/pdf.worker.min.mjs?v=${BUILD}`,
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
    pdfModulePromise ||= verifyAssets('pdf_text').then(() => import(PATHS.pdf)).then((module) => {
      module.GlobalWorkerOptions.workerSrc = PATHS.pdfWorker;
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

  const isoDate = (value) => {
    const raw = String(value || '').trim();
    const valid = (year, month, day) => {
      const d = new Date(Date.UTC(Number(year), Number(month) - 1, Number(day)));
      return d.getUTCFullYear() === Number(year) && d.getUTCMonth() + 1 === Number(month) && d.getUTCDate() === Number(day)
        ? `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}` : '';
    };
    let match = raw.match(/\b(20\d{2})[-/.](0?[1-9]|1[0-2])[-/.](0?[1-9]|[12]\d|3[01])\b/);
    if (match) return valid(match[1], match[2], match[3]);
    match = raw.match(/\b(0?[1-9]|[12]\d|3[01])[-/.](0?[1-9]|1[0-2])[-/.](20\d{2})\b/);
    if (match) return valid(match[3], match[2], match[1]);
    const months = {jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};
    match = raw.match(/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2})(?:st|nd|rd|th)?[,]?[\s]+(20\d{2})\b/i);
    if (match) return valid(match[3], months[match[1].slice(0,3).toLowerCase()], match[2]);
    match = raw.match(/\b(\d{1,2})(?:st|nd|rd|th)?\s+(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+[,]?(20\d{2})\b/i);
    if (match) return valid(match[3], months[match[2].slice(0,3).toLowerCase()], match[1]);
    return '';
  };

  const cents = (value) => {
    const cleaned = String(value || '').replace(/[^0-9.,-]/g, '').replace(/,/g, '');
    if (!/^-?\d+(?:\.\d{1,2})?$/.test(cleaned)) return null;
    const amount = Number(cleaned);
    return Number.isFinite(amount) ? Math.round(amount * 100) : null;
  };

  function candidateFromText(rawText) {
    const text=normalizeText(rawText),lines=text.split('\n').map(x=>x.trim()).filter(Boolean);
    const moneyPattern='(?:CAD|USD|EUR|GBP|US\\$|C\\$|\\$|€|£)?\\s*([0-9][0-9,]*(?:\\.[0-9]{1,2})?)';
    const amountFor=labels=>{for(const label of labels){const re=new RegExp('^\\s*(?:'+label+')\\s*(?:\\([^)]*\\))?\\s*[:=–-]?\\s*'+moneyPattern+'\\s*(?:CAD|USD|EUR|GBP)?\\s*$','im'),match=text.match(re);if(match)return cents(match[1]);}return null;};
    const invoiceTotal=amountFor(['invoice\\s+total','grand\\s+total','total\\s+(?:amount|including\\s+tax)','total']);
    const payable=amountFor(['total\\s+amount\\s+(?:due|payable)','amount\\s+(?:due|payable)','balance\\s+due','total\\s+due']);
    const subtotal=amountFor(['sub\\s*total','net\\s+(?:amount|total)','total\\s+before\\s+tax']);
    let tax=amountFor(['tax\\s+total','total\\s+tax','tax']);
    if(tax===null){const taxLines=lines.filter(x=>/^(?:GST\s*\/?\s*HST|HST|GST|PST|QST|VAT)\b/i.test(x)&&!/(?:registration|register|number|\bno\b|#|RT\d{4})/i.test(x));const values=taxLines.map(x=>{const m=x.match(/(?:^|\s|[:$])([0-9][0-9,]*\.\d{2})\s*(?:CAD|USD)?$/i);return m?cents(m[1]):null;}).filter(Number.isInteger);if(values.length)tax=values.reduce((a,b)=>a+b,0);}
    const labelledName=text.match(/^(?:vendor|supplier|seller|from)(?:\s+(?:name|legal name))?\s*[:：]\s*(.+)$/im)?.[1];
    // An unlabelled three-character OCR fragment is weak identity evidence;
    // leave the party blank for human review instead of proposing a false match.
    const partyLine=labelledName||lines.find(line=>line.length>=4&&line.length<=160&&/[a-z]/i.test(line)&&! /\b(invoice|bill to|ship to|statement|receipt|total|date|tax|gst|hst|pst|registration|account number|description|quantity|amount|payment|terms)\b/i.test(line)&&!/@|https?:|www\./i.test(line))||'';
    const identifier=text.match(/\b(?:GST\s*\/?\s*HST|HST|GST|VAT|tax|business|registration|registeration)\s*(?:(?:registration|registeration|reg(?:istration)?\.?|number|no\.?)\s*)?[:#]\s*([A-Z0-9][A-Z0-9 -]{4,35})/i)?.[1]?.trim()||text.match(/\b\d{9}\s*RT\s*\d{4}\b/i)?.[0]||'';
    const number=text.match(/\b(?:invoice|bill|document)\s*(?:number|no\.?|#)\s*[:#-]?\s*([A-Z0-9][A-Z0-9._/-]{1,39})/i)?.[1]||'';
    const documentDate=text.match(/^(?:invoice|bill|document)\s*date\s*[:#-]?\s*([^\n]{6,24})/im)||text.match(/^date\s*[:#-]?\s*([^\n]{6,24})/im),due=text.match(/^due\s*date\s*[:#-]?\s*([^\n]{6,24})/im);
    const candidate={partyName:partyLine,partyEmail:text.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i)?.[0]||'',businessIdentifier:identifier,documentNumber:number,documentDate:isoDate(documentDate?.[1]||''),dueDate:isoDate(due?.[1]||''),currency:/\bUSD\b|US\$/i.test(text)?'USD':/\bEUR\b|€/i.test(text)?'EUR':/\bGBP\b|£/i.test(text)?'GBP':'CAD',paymentTerms:text.match(/\b(?:payment\s+terms|terms)\s*[:：]\s*([^\n]{1,80})/i)?.[1]||'',confidenceBps:0,alternatives:[],lineItems:[]};
    const total=invoiceTotal??(Number.isInteger(subtotal)&&Number.isInteger(tax)?subtotal+tax:payable);
    if(Number.isInteger(total))candidate.totalCents=total;
    if(Number.isInteger(payable))candidate.amountDueCents=payable;
    if(Number.isInteger(tax))candidate.taxCents=tax;
    if(Number.isInteger(subtotal))candidate.subtotalCents=subtotal;
    // Derive only exact arithmetic; inconsistent printed totals remain for human review.
    if(Number.isInteger(total)&&Number.isInteger(tax)&&subtotal===null&&total>=tax)candidate.subtotalCents=total-tax;
    if(Number.isInteger(total)&&Number.isInteger(subtotal)&&tax===null&&total>=subtotal)candidate.taxCents=total-subtotal;
    const heading=lines.findIndex(x=>/^(?:description|item\s+description)\b/i.test(x)||/^(?:services|products|items)\b.*\b(?:amount|price|quantity|qty|total)\b/i.test(x));
    if(heading>=0){for(const line of lines.slice(heading+1)){if(/^(sub\s*total|tax|GST|HST|PST|VAT|grand\s+total|total|amount\s+(due|payable)|balance\s+due|payment|terms)\b/i.test(line))break;const m=line.match(/^(.+?[A-Za-z].*?)\s+(?:CAD\s*|USD\s*|\$\s*)?([0-9][0-9,]*\.\d{2})\s*$/);if(m)candidate.lineItems.push({description:m[1].trim().slice(0,300),amountCents:cents(m[2])});if(candidate.lineItems.length>=100)break;}}
    if(candidate.lineItems.length)candidate.memo=candidate.lineItems.map(x=>x.description).join('; ').slice(0,500);
    const known=[candidate.partyName,candidate.documentNumber,candidate.documentDate,candidate.totalCents].filter(x=>x!==''&&x!==undefined).length;
    candidate.confidenceBps=Math.min(9000,2000+known*1500+(identifier?500:0));
    if(Number.isInteger(candidate.subtotalCents)&&Number.isInteger(candidate.taxCents)&&Number.isInteger(total)&&candidate.subtotalCents+candidate.taxCents!==total){candidate.confidenceBps=Math.min(candidate.confidenceBps,4000);delete candidate.subtotalCents;}
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
          const viewport = page.getViewport({ scale: 1.75 });
          const canvas = window.document.createElement('canvas');
          canvas.width = Math.ceil(viewport.width);
          canvas.height = Math.ceil(viewport.height);
          const context = canvas.getContext('2d', { alpha: false });
          await page.render({ canvasContext: context, viewport }).promise;
          pageText = await recognize(worker, canvas);
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
    try { return { text: await recognize(worker, file), method: 'ocr', pageCount: 1, truncatedPages: false }; }
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
