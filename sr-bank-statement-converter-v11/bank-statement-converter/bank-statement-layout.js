/*! SR AccounTax Bank Statement Converter — column-aware statement reader and statement checks (v11).
 * Runs only in the browser. Reads the positioned text lines of a text-based PDF statement (from
 * BankStatementCore.groupTextItemsToLines), finds the column headings in English or French (also headings printed
 * over two lines), places every amount in its column by position, carries dates down, joins wrapped descriptions,
 * ignores side panels, works out years and the numeric day/month order from the statement period, splits a PDF
 * that holds several statements, and checks the rows against the statement's own opening, running and closing
 * balances and page totals. v11 uses the statement reader of Tegh R152.
 */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.BankStatementLayout = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  /** Accent-folded text with every kind of space made plain (French amounts use narrow no-break spaces). */
  const fold = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[     ]/g, ' ').replace(/[‘’ʼ]/g, "'").replace(/−/g, '-');

  const MONTHS = {jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, sept: 9, oct: 10, nov: 11, dec: 12,
    january: 1, february: 2, march: 3, april: 4, june: 6, july: 7, august: 8, september: 9, october: 10, november: 11, december: 12,
    // French, accent-folded ("févr." → fevr, "août" → aout, "déc." → dec).
    janv: 1, janvier: 1, fev: 2, fevr: 2, fevrier: 2, mars: 3, avr: 4, avril: 4, mai: 5, juin: 6, juil: 7, juillet: 7, aout: 8,
    septembre: 9, octobre: 10, novembre: 11, decembre: 12};
  const MONTH_RE = `(${Object.keys(MONTHS).sort((a, b) => b.length - a.length).join('|')})`;
  const monthOf = s => MONTHS[String(s).toLowerCase()];
  const pad = n => String(n).padStart(2, '0');
  const iso = (y, m, d) => `${y}-${pad(m)}-${pad(d)}`;
  const validYmd = (y, m, d) => { const t = new Date(Date.UTC(y, m - 1, d)); return m >= 1 && m <= 12 && t.getUTCFullYear() === y && t.getUTCMonth() === m - 1 && t.getUTCDate() === d; };
  const fullYear = y => (y < 100 ? 2000 + y : y);
  const addDays = (isoDate, n) => new Date(Date.parse(isoDate) + n * 86400000).toISOString().slice(0, 10);

  /** Signed amount and its suffix: "1,234.56", "$1,234.56", "-$500.00", "500.00-", "(12.50)", "12.50 CR", "250.00 OD",
   *  and French "1 500,00", "125,00 $", "1.500,00". Returns {value, suffix} or null. */
  function parseMoneyInfo(text) {
    let t = fold(text).trim().replace(/\s+/g, ' ');
    if (!t || t.length > 24) return null;
    let neg = false, suffix = '';
    if (/^\(.*\)$/.test(t)) { neg = true; t = t.slice(1, -1).trim(); }
    const sx = /\s?(CR|DR|OD|DB)\.?$/i.exec(t);
    if (sx) { suffix = sx[1].toUpperCase(); if (suffix === 'CR' || suffix === 'OD') neg = !neg; t = t.slice(0, sx.index).trim(); }
    if (/-$/.test(t)) { neg = !neg; t = t.slice(0, -1).trim(); }
    if (/^-/.test(t)) { neg = !neg; t = t.slice(1).trim(); }
    t = t.replace(/^\$\s?/, '').replace(/\s?\$$/, '');
    if (/^-/.test(t)) { neg = !neg; t = t.slice(1).trim(); }
    let v = null;
    if (/^\d{1,3}(,\d{3})*\.\d{2}$|^\d+\.\d{2}$/.test(t)) v = Number(t.replace(/,/g, ''));
    else if (/^\d{1,3}( \d{3})+\.\d{2}$/.test(t)) v = Number(t.replace(/ /g, ''));
    else if (/^\d{1,3}( \d{3})+,\d{2}$|^\d+,\d{2}$|^\d{1,3}(\.\d{3})+,\d{2}$/.test(t)) v = Number(t.replace(/[ .]/g, '').replace(',', '.'));
    if (v === null || !Number.isFinite(v)) return null;
    return {value: neg ? -v : v, suffix};
  }
  function parseMoney(text) { const m = parseMoneyInfo(text); return m ? m.value : null; }

  /** Month-day tokens without a year: "Jan 3", "SEP01", "DEC 9", "03 Jan", "02 janv.", "1er févr.". Returns {m,d} or null. */
  function parseMonthDay(text) {
    const t = fold(text).trim().toLowerCase();
    let m = new RegExp(`^${MONTH_RE}\\.?[\\s-]*(\\d{1,2})$`).exec(t);
    if (m) return validYmd(2000, monthOf(m[1]), Number(m[2])) ? {m: monthOf(m[1]), d: Number(m[2])} : null;
    m = new RegExp(`^(\\d{1,2})(?:er)?[\\s-]*${MONTH_RE}\\.?$`).exec(t);
    if (m) return validYmd(2000, monthOf(m[2]), Number(m[1])) ? {m: monthOf(m[2]), d: Number(m[1])} : null;
    return null;
  }

  /** Numeric day-month without a year ("01/15", "15/01", "15.01"): the order is decided for the whole statement. */
  function parseNumericDate(text) {
    const t = String(text || '').trim();
    let m = /^(\d{1,2})[/.-](\d{1,2})$/.exec(t);
    if (m) return {a: Number(m[1]), b: Number(m[2]), y: null};
    m = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4}|\d{2})$/.exec(t);
    if (m) return {a: Number(m[1]), b: Number(m[2]), y: fullYear(Number(m[3]))};
    return null;
  }

  /** Dates that carry their own year and need no order choice: "2026-03-04", "2026/03/04", "Mar 4, 2026", "4 Mar 2026",
   *  "1er janvier 2026", "4-Mar-2026". Numeric d/m/y vs m/d/y goes through parseNumericDate instead. */
  function parseFullDate(text) {
    const t = fold(text).trim().toLowerCase();
    let m = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/.exec(t);
    if (m && validYmd(+m[1], +m[2], +m[3])) return iso(+m[1], +m[2], +m[3]);
    m = new RegExp(`^${MONTH_RE}\\.?[\\s-]*(\\d{1,2})(?:er)?,?[\\s-]+(\\d{4})$`).exec(t);
    if (m) { const mo = monthOf(m[1]); if (validYmd(+m[3], mo, +m[2])) return iso(+m[3], mo, +m[2]); }
    m = new RegExp(`^(\\d{1,2})(?:er)?[\\s-]*${MONTH_RE}\\.?,?[\\s-]+(\\d{4})$`).exec(t);
    if (m) { const mo = monthOf(m[2]); if (validYmd(+m[3], mo, +m[1])) return iso(+m[3], mo, +m[1]); }
    return null;
  }

  /** Statement period from the header text. Returns {start, end, evidence} with ISO dates, or null. */
  function statementPeriod(text) {
    const src = fold(String(text || '').slice(0, 20000)).toLowerCase();
    let m;
    // Two dates with years joined by "to", "au", "-", "through": "January 1, 2026 to January 31, 2026",
    // "Du 1er janvier 2026 au 31 janvier 2026", "Période du 2026-01-01 au 2026-01-31", "01/01/2026 - 01/31/2026".
    const TOKEN = new RegExp(`\\b(?:\\d{4}[-/.]\\d{1,2}[-/.]\\d{1,2}|${MONTH_RE}\\.?\\s+\\d{1,2}(?:er)?,?\\s+\\d{4}|\\d{1,2}(?:er)?\\s+${MONTH_RE}\\.?,?\\s+\\d{4}|\\d{1,2}[/.-]\\d{1,2}[/.-]\\d{4})\\b`, 'g');
    const tokens = [...src.matchAll(TOKEN)].map(x => ({text: x[0], at: x.index, end: x.index + x[0].length}));
    const choices = tok => {
      const full = parseFullDate(tok);
      if (full) return [full];
      const n = parseNumericDate(tok); if (!n || !n.y) return [];
      return [...new Set([[n.a, n.b], [n.b, n.a]].filter(([mo, d]) => validYmd(n.y, mo, d)).map(([mo, d]) => iso(n.y, mo, d)))];
    };
    for (let i = 0; i + 1 < tokens.length; i++) {
      const between = src.slice(tokens[i].end, tokens[i + 1].at);
      if (!/^\s*(?:to|au|a|-|–|—|through|thru|until|jusqu'au)\s*$/.test(between)) continue;
      const pairs = [];
      for (const s of choices(tokens[i].text)) for (const e of choices(tokens[i + 1].text)) { const span = (Date.parse(e) - Date.parse(s)) / 86400000; if (span >= 0 && span <= 400) pairs.push({start: s, end: e}); }
      const unique = [...new Map(pairs.map(p => [p.start + p.end, p])).values()];
      if (unique.length === 1) return {...unique[0], evidence: src.slice(tokens[i].at, tokens[i + 1].end)};
    }
    // "For Jan 1 to Jan 31, 2023" (the first date without its year)
    m = new RegExp(`${MONTH_RE}\\.?\\s+(\\d{1,2})(?:er)?(?:,?\\s+(\\d{4}))?\\s+(?:to|au|-|–|through)\\s+${MONTH_RE}\\.?\\s+(\\d{1,2}),?\\s+(\\d{4})`).exec(src);
    if (m) {
      const y2 = Number(m[6]), m2 = monthOf(m[4]), d2 = Number(m[5]), m1 = monthOf(m[1]), d1 = Number(m[2]);
      const y1 = m[3] ? Number(m[3]) : (m1 > m2 ? y2 - 1 : y2);
      if (validYmd(y1, m1, d1) && validYmd(y2, m2, d2)) return {start: iso(y1, m1, d1), end: iso(y2, m2, d2), evidence: m[0]};
    }
    // "Du 1er au 31 janvier 2026"
    m = new RegExp(`\\bdu\\s+(\\d{1,2})(?:er)?\\s+au\\s+(\\d{1,2})\\s+${MONTH_RE}\\.?\\s+(\\d{4})`).exec(src);
    if (m) { const mo = monthOf(m[3]), y = Number(m[4]); if (validYmd(y, mo, +m[1]) && validYmd(y, mo, +m[2])) return {start: iso(y, mo, +m[1]), end: iso(y, mo, +m[2]), evidence: m[0]}; }
    // TD: "AUG 31/23 - SEP 29/23"
    m = new RegExp(`${MONTH_RE}\\s*(\\d{1,2})\\s*/\\s*(\\d{2,4})\\s*[-–]\\s*${MONTH_RE}\\s*(\\d{1,2})\\s*/\\s*(\\d{2,4})`).exec(src);
    if (m) {
      const y1 = fullYear(Number(m[3])), y2 = fullYear(Number(m[6])), m1 = monthOf(m[1]), m2 = monthOf(m[4]);
      if (validYmd(y1, m1, Number(m[2])) && validYmd(y2, m2, Number(m[5]))) return {start: iso(y1, m1, Number(m[2])), end: iso(y2, m2, Number(m[5])), evidence: m[0]};
    }
    // Card statements: "STATEMENT DATE: January 06, 2025" with "PREVIOUS STATEMENT: December 05, 2024" (or the French equivalents).
    const one = `(${MONTH_RE}\\.?\\s+\\d{1,2},?\\s+\\d{4}|\\d{1,2}(?:er)?\\s+${MONTH_RE}\\.?\\s+\\d{4}|\\d{4}-\\d{2}-\\d{2})`;
    const sd = new RegExp(`(?:statement\\s+date|date\\s+du\\s+releve)\\s*:?\\s*${one}`).exec(src);
    const ps = new RegExp(`(?:previous\\s+statement(?:\\s+date)?|releve\\s+precedent|date\\s+du\\s+releve\\s+precedent)\\s*:?\\s*${one}`).exec(src);
    if (sd) {
      const end = parseFullDate(sd[1]), start = ps ? parseFullDate(ps[1]) : null;
      if (end) return {start, end, evidence: sd[0]};
    }
    return null;
  }

  /** Put a month-day inside the statement period (handles December → January statements). */
  function dateInPeriod(md, period) {
    if (!md || !period?.end) return null;
    const endY = Number(period.end.slice(0, 4));
    for (const y of [endY, endY - 1, endY + 1]) {
      if (!validYmd(y, md.m, md.d)) continue;
      const d = iso(y, md.m, md.d);
      const lo = period.start ? addDays(period.start, -40) : addDays(period.end, -400);
      if (d >= lo && d <= addDays(period.end, 7)) return d;
    }
    return null;
  }

  // Column headings, matched on accent-folded lower-case text with any "($)" / "(CAD)" unit removed.
  const OUT_WORDS = "withdrawals?|withdrawn|cheques?|checks?|debits?|money out|payments? out|charges?|fees|amounts? (?:deducted|withdrawn|paid out)(?: from(?: your)?(?: account)?)?|retraits?|sorties?|frais|cheques? et retraits?|paiements? et retraits?";
  const IN_WORDS = "deposits?|deposited|credits?|money in|payments?|amounts? (?:added|deposited|paid in)(?: to(?: your)?(?: account)?)?|depots?|entrees?|versements?|paiements?";
  const joined = words => new RegExp(`^(?:${words})(?:\\s*(?:/|&|and|et|,)\\s*(?:${words}))*$`);
  const HEADER_WORDS = [
    ['postdate', /^(?:posting|post(?:ed)?)(?:\s+date)?$|^date\s+(?:de\s+report|d'inscription|de\s+comptabilisation|d'enregistrement|of\s+posting)$/],
    ['date', /^(?:transaction\s+)?date$|^date\s+posted$|^trans(?:action)?\.?\s*date$|^date\s+(?:de\s+(?:la\s+)?transaction|de\s+l'operation|d'operation|de\s+l'achat)$|^jour$/],
    ['code', /^(?:code|code\s+de\s+transaction|trans(?:action)?\s+code)$/],
    ['desc', /^(?:activity\s+|transaction\s+)?description$|^details?$|^particulars$|^transactions?(?:\s+details)?$|^activity$|^description\s+de\s+(?:la\s+)?transaction$|^libelle$|^details?\s+de\s+(?:la\s+)?transaction$|^operations?$|^merchant(?:\s+name)?$|^nature\s+de\s+l'operation$/],
    ['drcr', /^(?:dr|db|d)\s*\/\s*(?:cr|c)$|^(?:cr)\s*\/\s*(?:dr|db)$/],
    ['debit', joined(OUT_WORDS)],
    ['credit', joined(IN_WORDS)],
    ['amount', /^(?:transaction\s+)?amount$|^montant(?:\s+de\s+la\s+transaction)?$|^amount\s*\/\s*montant$/],
    ['balance', /^(?:running\s+|account\s+|daily\s+)?balance$|^solde(?:\s+courant|\s+du\s+compte)?$/],
  ];
  const MONEY_ROLES = ['debit', 'credit', 'amount', 'balance'];
  const headingText = text => fold(text).toLowerCase().replace(/\s+/g, ' ').trim().replace(/\s*\((?:\$|cad|\$\s*cad|en\s+\$|en\s+dollars)\)$/, '').replace(/\s*\$$/, '').trim();

  /** Split a line's cells into header tokens (pdf.js often gives one cell per word). */
  function headerColumns(cellsIn) {
    const cells = cellsIn.map(c => ({text: c.text.trim(), x: c.x, r: c.x + (c.width || c.text.length * 4)}));
    // Merge adjacent words into phrases when the gap is small, so "Withdrawals ($)" or "ACTIVITY DESCRIPTION" stay one heading.
    const phrases = [];
    for (const c of cells) {
      const prev = phrases[phrases.length - 1];
      if (prev && c.x - prev.r < 6) { prev.text += ' ' + c.text; prev.r = c.r; } else phrases.push({...c});
    }
    const cols = [];
    for (const ph of phrases) {
      // A phrase may still hold several headings ("DATE DATE ACTIVITY DESCRIPTION") when the PDF uses one text run.
      const parts = ph.text.split(/\s{2,}/);
      const width = ph.r - ph.x, total = ph.text.length || 1; let offset = 0;
      for (const part of parts) {
        const start = ph.x + width * (ph.text.indexOf(part, offset) / total), end = start + width * (part.length / total); offset = ph.text.indexOf(part, offset) + part.length;
        const norm = headingText(part);
        const hit = HEADER_WORDS.find(([, re]) => re.test(norm));
        if (hit) cols.push({role: hit[0], text: norm, x: start, r: end});
        else if (/^date\s+date$/.test(norm)) { cols.push({role: 'date', text: 'date', x: start, r: start + (end - start) / 2}); cols.push({role: 'postdate', text: 'date', x: start + (end - start) / 2, r: end}); }
      }
    }
    // Two date columns: the first is the transaction date, the second the posting date.
    const dates = cols.filter(c => c.role === 'date');
    if (dates.length === 2 && !cols.some(c => c.role === 'postdate')) dates.sort((a, b) => a.x - b.x)[1].role = 'postdate';
    return cols;
  }

  function headerFrom(cells) {
    const cols = headerColumns(cells);
    const roles = new Set(cols.map(c => c.role));
    const money = MONEY_ROLES.filter(r => roles.has(r)).length;
    return money >= 1 && (roles.has('desc') || roles.has('date')) && cols.length >= 3 ? cols : null;
  }

  /** Cells of two heading lines merged column by column ("Amounts deducted" over "from your account ($)"). */
  function mergeHeadingLines(a, b) {
    const out = a.cells.map(c => ({...c, text: c.text, r: c.x + (c.width || c.text.length * 4)}));
    for (const c of b.cells) {
      const r = c.x + (c.width || c.text.length * 4);
      const hit = out.find(o => c.x < o.r + 4 && r > o.x - 4);
      if (hit) { hit.text = `${hit.text} ${c.text}`; hit.r = Math.max(hit.r, r); hit.width = hit.r - hit.x; }
      else out.push({...c});
    }
    return out.sort((p, q) => p.x - q.x);
  }

  const isWordOnly = line => line.cells.every(c => parseMoneyInfo(c.text) === null && !parseFullDate(c.text) && !parseMonthDay(c.text) && !parseNumericDate(c.text));

  const OPENING_RE = /^(opening\s+balance|balance\s+forward|previous\s+(statement\s+)?balance|starting\s+balance|balance\s+brought\s+forward|solde\s+(d'ouverture|precedent|anterieur|reporte|de\s+debut|initial|au\s+debut)|ancien\s+solde|report\s+du\s+solde)\b/;
  const CLOSING_RE = /^(closing\s+balance|closing\s+totals?|(total\s+)?new\s+balance|ending\s+balance|balance\s+carried\s+forward|solde\s+(de\s+cloture|de\s+fermeture|final|de\s+fin|a\s+la\s+fin)|nouveau\s+solde)\b/;
  const FOOTER_RE = /\bchq\s+enclosed\b|\bnext\s+statement\s+date\b|\bstatement\s+date\s+is\b|\bdate\s+du\s+prochain\s+releve\b/;
  const STOP_RE = /^(\(?continued(\s+on\s+next\s+page)?\)?$|\(?suite\s+(a\s+la\s+page\s+suivante|au\s+verso)\)?$|a\s+suivre$|sub-?total\b|sous-?total\b|monthly aver|next statement date|please ensure that you report|calculating your balance|interest charged|total\s+(withdrawals|deposits|debits|credits)|total\s+des\s+(retraits|depots|debits|credits))/;
  const OPENS_RE = /\b(opening\s+balance|balance\s+forward|previous\s+(statement\s+)?balance|starting\s+balance|solde\s+(d'ouverture|precedent|anterieur|reporte)|ancien\s+solde)\b/;
  const CARD_RE = /\b(credit\s+limit|minimum\s+payment|available\s+credit|previous\s+statement\s+balance|limite\s+de\s+credit|paiement\s+minimum|credit\s+disponible)\b/;

  /** Cells cleaned up for reading: split amounts ("1" "500,00"), a separate "CR"/"OD"/"-" or "$" joined back to their amount. */
  function readyCells(cells) {
    const out = [];
    for (const c0 of cells) {
      const c = {text: c0.text.trim(), x: c0.x, r: c0.x + (c0.width || c0.text.length * 4)};
      if (!c.text) continue;
      const prev = out[out.length - 1], gap = prev ? c.x - prev.r : Infinity;
      if (prev && gap < 8 && /^\$?\d{1,3}$/.test(fold(prev.text)) && /^\d{3}(?:[ .,]\d{3})*[.,]\d{2}(?:\s?(?:CR|DR|OD|\$))?$/i.test(fold(c.text)) && parseMoneyInfo(`${prev.text} ${c.text}`)) { prev.text += ' ' + c.text; prev.r = c.r; continue; }
      if (prev && gap < 14 && /^(?:CR|DR|OD|-|\$)$/i.test(c.text) && parseMoneyInfo(prev.text) && parseMoneyInfo(`${prev.text}${c.text === '-' ? '' : ' '}${c.text}`)) { prev.text += (c.text === '-' ? '' : ' ') + c.text; prev.r = c.r; continue; }
      if (prev && gap < 8 && /^-?\$$/.test(prev.text) && parseMoneyInfo(`${prev.text}${c.text}`)) { prev.text += c.text; prev.r = c.r; continue; }
      out.push(c);
    }
    return out;
  }

  /** One pass over the lines with a fixed numeric date order. */
  function readSection(lines, options, order) {
    const text = lines.map(l => l.cells.map(c => c.text).join(' ')).join('\n');
    const folded = fold(text).toLowerCase();
    const year = Number(options.fallbackYear) || 0;
    const period = options.period || statementPeriod(text) || (year >= 2000 && year <= 2100 ? {start: `${year}-01-01`, end: `${year}-12-31`, evidence: 'Statement year setting'} : null);
    const isCard = options.accountType === 'credit_card' || CARD_RE.test(folded);
    const stats = {numeric: 0, invalid: 0, outside: 0};
    let cols = null, tableLeft = 0, tableRight = Infinity, lastDate = null, current = null, inTable = false, headerLine = null, pending = null;
    const rows = [], checkpoints = [], notes = [];
    let opening = null, closing = null;
    const colFor = (x, r) => {
      // Amounts are right-aligned. A heading owns the space up to the next heading, so an amount whose right edge falls
      // there belongs to it (left-aligned headings over right-aligned figures); otherwise the nearest money column wins.
      const owner = cols.find((c, i) => MONEY_ROLES.includes(c.role) && r > c.x - 2 && (i + 1 >= cols.length ? r <= tableRight : r < cols[i + 1].x - 1) && x < (cols[i + 1]?.x ?? Infinity));
      if (owner) return owner;
      let best = null, bestD = Infinity;
      for (const c of cols) {
        if (!MONEY_ROLES.includes(c.role)) continue;
        const centre = (c.x + c.r) / 2, d = Math.min(Math.abs(r - c.r), Math.abs(r - centre), Math.abs((x + r) / 2 - centre));
        if (d < bestD) { bestD = d; best = c; }
      }
      return bestD < 90 ? best : null;
    };
    const dateCols = () => cols.filter(c => c.role === 'date' || c.role === 'postdate');
    const inCol = (c, role, slack = 30) => cols.some(dc => dc.role === role && c.x < dc.r + slack && c.r > dc.x - slack);
    const numericDate = n => {
      stats.numeric++;
      const [mo, d] = order === 'dmy' ? [n.b, n.a] : [n.a, n.b];
      if (n.y) return validYmd(n.y, mo, d) ? iso(n.y, mo, d) : null;
      return validYmd(2000, mo, d) ? dateInPeriod({m: mo, d}, period) : null;
    };
    const when = d => {
      if (!d) return null;
      const out = d.full || (d.md ? dateInPeriod(d.md, period) : numericDate(d.num));
      if (!out) stats.invalid++;
      else if (period?.start && period?.end && (out < period.start || out > period.end)) stats.outside++;
      return out;
    };
    for (let li = 0; li < lines.length; li++) {
      const line = lines[li];
      let hdr = headerFrom(line.cells);
      const next = lines[li + 1];
      const nextIsHeadingTail = next && next.page === line.page && Math.abs(next.y - line.y) <= 16 && isWordOnly(next);
      if (!hdr && nextIsHeadingTail && isWordOnly(line)) { const merged = headerFrom(mergeHeadingLines(line, next)); if (merged) { hdr = merged; li++; } }
      else if (hdr && nextIsHeadingTail) {
        // The heading's second line ("from your account ($)") widens the columns above it and is not a row.
        const merged = headerFrom(mergeHeadingLines(line, next));
        if (merged && merged.length >= hdr.length) hdr = merged;
        li++;
      }
      if (hdr) {
        cols = hdr.sort((a, b) => a.x - b.x); inTable = true; current = null; pending = null; headerLine = line;
        tableLeft = Math.min(...cols.map(c => c.x)) - 60; tableRight = Math.max(...cols.map(c => c.r)) + 45;
        continue;
      }
      if (!cols || !inTable) continue;
      const cells = readyCells(line.cells.filter(c => c.x >= tableLeft && c.x <= tableRight));
      if (!cells.length) continue;
      const joinedText = cells.map(c => c.text).join(' ').replace(/\s+/g, ' ').trim(), foldedLine = fold(joinedText).toLowerCase();
      // Totals, "Continued" and summary boxes end the table until the next heading (payment slips and summaries below it are not rows).
      if (STOP_RE.test(foldedLine) || FOOTER_RE.test(foldedLine)) { current = null; pending = null; inTable = false; continue; }
      // Classify cells.
      const money = {}, dates = [], words = [];
      let collision = false, sign = 0;
      for (const c of cells) {
        const info = parseMoneyInfo(c.text);
        if (info !== null) {
          const col = colFor(c.x, c.r);
          if (col) {
            let v = info.value;
            // A bank balance marked DR is overdrawn.
            if (col.role === 'balance' && info.suffix === 'DR' && !isCard) v = -Math.abs(v);
            if (money[col.role] != null) collision = true; money[col.role] = v; continue;
          }
        }
        if (inCol(c, 'drcr', 12) && /^(dr|db|d|cr|c)\.?$/i.test(c.text)) { sign = /^c/i.test(c.text) ? 1 : -1; continue; }
        const inDateCol = dateCols().some(dc => c.x < dc.r + 30 && c.r > dc.x - 30);
        if (inDateCol) {
          const full = parseFullDate(c.text);
          if (full) { dates.push({full, x: c.x}); continue; }
          const md = parseMonthDay(c.text);
          if (md) { dates.push({md, x: c.x}); continue; }
          const num = parseNumericDate(c.text);
          if (num) { dates.push({num, x: c.x}); continue; }
        }
        // Month and day may arrive as separate cells ("DEC" "9").
        words.push(c);
      }
      // Rejoin split dates ("DEC" + "9", "02" + "janv.") that sit in a date column.
      for (let i = 0; i < words.length - 1; i++) {
        const md = parseMonthDay(words[i].text + ' ' + words[i + 1].text);
        if (md && dateCols().some(dc => words[i].x < dc.r + 30 && words[i + 1].r > dc.x - 30)) { dates.push({md, x: words[i].x}); words.splice(i, 2); i--; }
      }
      // Two amounts in one column is a summary box or payment slip, never a transaction.
      if (collision) { current = null; pending = null; notes.push(`Skipped a line on page ${line.page} with two amounts in one column.`); continue; }
      dates.sort((a, b) => a.x - b.x);
      // Description = remaining words that sit left of the first money column (or in the description column), outside a transaction-code column.
      const firstMoney = Math.min(...cols.filter(c => MONEY_ROLES.includes(c.role)).map(c => c.x));
      const descWords = words.filter(w => !inCol(w, 'code', 4) && (w.x < firstMoney - 4 || cols.some(c => c.role === 'desc' && w.x >= c.x - 10 && w.x < firstMoney)));
      const desc = descWords.map(w => w.text).join(' ').replace(/\s+/g, ' ').trim(), descKey = fold(desc).toLowerCase();
      const rowDate = when(dates[0]), postDate = dates[1] ? when(dates[1]) : null;
      if (rowDate) lastDate = rowDate;
      const flow = money.debit != null || money.credit != null || money.amount != null;
      if (OPENING_RE.test(descKey) && !flow || OPENING_RE.test(descKey) && isCard && money.amount != null && money.balance == null) {
        const bal = money.balance ?? money.amount ?? null;
        if (bal != null) { const b = isCard ? -bal : bal; checkpoints.push({after: rows.length, balance: b, kind: 'forward'}); if (opening == null) opening = b; }
        current = null; pending = null; continue;
      }
      if (CLOSING_RE.test(descKey)) {
        const bal = money.balance ?? money.amount ?? null;
        if (bal != null) closing = isCard ? -bal : bal;
        current = null; pending = null; continue;
      }
      if (flow) {
        let amount;
        if (sign && money.amount != null) amount = sign * Math.abs(money.amount);
        else if (money.amount != null) amount = isCard ? -money.amount : money.amount;
        else amount = (money.credit ?? 0) - Math.abs(money.debit ?? 0);
        const balance = money.balance != null ? (isCard ? -money.balance : money.balance) : null;
        // A dated description line just above with no amount (the amount sits on the next line) starts this transaction.
        let description = desc, date = rowDate || lastDate, raw = joinedText;
        if (pending && !dates.length && pending.page === line.page && Math.abs(pending.y - line.y) <= 18) { description = [pending.desc, desc].filter(Boolean).join(' · '); date = pending.date || date; raw = pending.raw + ' ' + joinedText; }
        pending = null;
        current = {date, postedDate: postDate, description, amount: Math.round(amount * 100) / 100, balance, page: line.page, y: line.y, raw};
        rows.push(current);
        continue;
      }
      if (money.balance != null && current && current.balance == null && !desc) { current.balance = isCard ? -money.balance : money.balance; continue; }
      // Wrapped description: text only, directly under the previous transaction on the same page.
      if (current && desc && !dates.length && current.page === line.page && Math.abs(current.y - line.y) <= 18 && desc.length <= 80) {
        current.description = (current.description + ' · ' + desc).slice(0, 400); current.raw += ' ' + joinedText; current.y = line.y; continue;
      }
      if (desc && dates.length && !Object.keys(money).length) { pending = {date: rowDate, desc, page: line.page, y: line.y, raw: joinedText}; current = null; continue; }
      if (desc) { current = null; pending = null; }
    }
    if (!cols || !rows.length) return null;
    // Statement summary lines outside the table ("Opening balance on Jan 1, 2023 $31,742.11", "Solde précédent 987,65").
    const AMT = '(-?\\$?\\s?\\d{1,3}(?:[, .]\\d{3})*[.,]\\d{2}(?:\\s?\\$)?(?:\\s?(?:CR|DR|OD))?-?)';
    const sum = (re) => { const m = re.exec(fold(text)); return m ? parseMoneyInfo(m[1]) : null; };
    if (opening == null) { const v = sum(new RegExp(`(?:opening\\s+balance|previous\\s+(?:statement\\s+)?balance|solde\\s+(?:d'ouverture|precedent|anterieur))[^\\n$\\d-]*?${AMT}`, 'i')); if (v) opening = isCard ? -v.value : (v.suffix === 'DR' ? -Math.abs(v.value) : v.value); }
    if (closing == null) { const v = sum(new RegExp(`(?:closing\\s+balance|(?:total\\s+)?new\\s+balance|nouveau\\s+solde|solde\\s+de\\s+(?:cloture|fermeture))[^\\n$\\d-]*?(?:on\\s+\\w+\\s+\\d{1,2},?\\s+\\d{4}\\s*)?${AMT}`, 'i')); if (v) closing = isCard ? -v.value : (v.suffix === 'DR' ? -Math.abs(v.value) : v.value); }
    // Without a closing line (TD chequing prints its last balance on the last row), the last printed balance is the closing balance.
    let closingFromLastRow = false;
    if (closing == null) { const last = [...rows].reverse().find(r => r.balance != null); if (last && last === rows[rows.length - 1]) { closing = last.balance; closingFromLastRow = true; } }
    // Page totals ("Debits 6 1,382.85" / "Credits 0 0.00") printed by some banks: compare count and total with that page's rows.
    const pageChecks = [];
    for (const line of lines) {
      const t = line.cells.map(c => c.text).join(' ').replace(/\s+/g, ' ').trim();
      const m = /(?:^|\s)(Debits|Credits)\s+(\d{1,4})\s+([\d,]+\.\d{2})(?=\s|$)/i.exec(t);
      if (!m) continue;
      const debit = /^debits$/i.test(m[1]), pageRows = rows.filter(r => r.page === line.page && (debit ? r.amount < 0 : r.amount > 0));
      const total = pageRows.reduce((s2, r) => s2 + Math.round(Math.abs(r.amount) * 100), 0);
      pageChecks.push({page: line.page, kind: debit ? 'debits' : 'credits', count: Number(m[2]), total: parseMoney(m[3]), foundCount: pageRows.length, foundTotal: total / 100, ok: pageRows.length === Number(m[2]) && total === Math.round(parseMoney(m[3]) * 100)});
    }
    // Running-balance check: every row that prints a balance must equal the opening plus the rows so far.
    const cents = v => Math.round(v * 100);
    let running = opening != null ? cents(opening) : null, mismatches = 0, checked = 0;
    rows.forEach((row, i) => {
      if (running == null && row.balance != null) { running = cents(row.balance); return; }
      if (running != null) running += cents(row.amount);
      for (const cp of checkpoints.filter(c => c.after === i + 1)) { if (running != null) { checked++; if (running !== cents(cp.balance)) { mismatches++; running = cents(cp.balance); } } }
      if (row.balance != null && running != null) { checked++; if (running !== cents(row.balance)) { row.balanceMismatch = (running - cents(row.balance)) / 100; mismatches++; running = cents(row.balance); } }
    });
    const net = rows.reduce((s, r) => s + cents(r.amount), 0);
    const reconciled = opening != null && closing != null ? cents(opening) + net === cents(closing) : null;
    const undated = rows.filter(r => !r.date).length;
    return {
      rows, period, isCard, openingBalance: opening, closingBalance: closing, closingFromLastRow, reconciled, balanceChecks: checked, balanceMismatches: mismatches, undated, pageChecks,
      netCents: net, columns: cols.map(c => c.role), notes, stats, dateOrder: stats.numeric ? order : null,
    };
  }

  /**
   * Parse positioned statement lines. Returns null when no table heading is found.
   * options.accountType: 'credit_card' flips the sign convention (charges are money out, payments money in).
   * options.dateOrder: 'mdy' | 'dmy' | 'auto' — numeric dates without month names follow it; 'auto' picks the
   * only order under which every date is real and inside the statement period, and leaves the rows undated otherwise.
   */
  function parseStatementLayout(lines, options = {}) {
    const chosen = options.dateOrder === 'mdy' || options.dateOrder === 'dmy' ? options.dateOrder : null;
    const first = readSection(lines, options, chosen || 'mdy');
    if (!first || chosen || !first.stats.numeric) return first;
    const second = readSection(lines, options, 'dmy');
    const score = r => r ? r.stats.invalid * 1000 + r.stats.outside + r.undated * 1000 : Infinity;
    const a = score(first), b = score(second);
    if (a !== b) return a < b ? first : second;
    // Both orders read every date the same way (all days ≤ 12 and the same results) — nothing to choose.
    if (first.rows.every((row, i) => row.date === second.rows[i]?.date)) return first;
    first.rows.forEach(row => { row.date = null; });
    first.undated = first.rows.length; first.dateOrderAmbiguous = true;
    first.notes.push('Numeric dates read correctly as both month/day and day/month. Choose the Date Format.');
    return first;
  }

  /** Account numbers printed with an explicit label ("Account number:", "Compte :", "Card number"), last four digits each. */
  function statementAccountIds(text) {
    const ids = [];
    const lines = fold(text).split(/\r?\n/);
    for (let index = 0; index < lines.length; index++) {
      const label = /\b(?:Account[ \t]*(?:Number|No\.?|#)|Card[ \t]*(?:Number|No\.?|#)|(?:N[o°º]\.?|Numero)[ \t]+de[ \t]+(?:compte|carte)|Compte[ \t]*(?=:)|Carte[ \t]*(?=:))[ \t]*:?[ \t]*/gi;
      for (const match of lines[index].matchAll(label)) {
        let rest = lines[index].slice(match.index + match[0].length).trimStart();
        if (!rest && index + 1 < lines.length) rest = lines[index + 1].trimStart();
        // A balance printed beside the identifier must never supply extra digits.
        rest = rest.split(/[ \t]+(?=\$?\d{1,3}(?:,\d{3})+(?:\.\d{2})?\b|\$?\d+\.\d{2}\b)/)[0];
        const candidate = rest.match(/^([\dXx*•]+(?:[ \t-]+[\dXx*•]+)*)/)?.[1] || '';
        const digits = candidate.replace(/\D/g, '');
        if (digits.length >= 4 && digits.length <= 25 && !ids.includes(digits.slice(-4))) ids.push(digits.slice(-4));
      }
    }
    return ids;
  }

  /**
   * Split the lines of one PDF into statements. A new statement starts on a page that says "Page 1 of …" after the
   * first page, whose labelled account number differs from the statement before it, or whose statement period differs
   * and which shows an opening (or previous) balance.
   * Returns [{lines, pages, period, account}] — one entry for an ordinary statement.
   */
  function splitStatements(lines) {
    const byPage = new Map();
    for (const line of lines) { if (!byPage.has(line.page)) byPage.set(line.page, []); byPage.get(line.page).push(line); }
    const sections = [];
    for (const [page, pageLines] of byPage) {
      const text = pageLines.map(l => l.cells.map(c => c.text).join(' ')).join('\n');
      const head = fold(pageLines.slice(0, 25).map(l => l.cells.map(c => c.text).join(' ')).join('\n')).toLowerCase();
      const pageNo = /\bpage\s+(\d{1,3})\s*(?:of|de|\/|sur)\s*(\d{1,3})\b/.exec(head);
      const period = statementPeriod(pageLines.slice(0, 25).map(l => l.cells.map(c => c.text).join(' ')).join('\n'));
      const account = statementAccountIds(text)[0] || null;
      const cur = sections[sections.length - 1];
      const fresh = !cur || (pageNo && Number(pageNo[1]) === 1 && cur.pages.length > 0)
        // A different period alone is weak evidence (summary boxes and interest notes print date ranges too): the page must also open a statement.
        || (period?.start && cur.period?.start && (period.start !== cur.period.start || period.end !== cur.period.end) && OPENS_RE.test(fold(text).toLowerCase()))
        || (account && cur.account && account !== cur.account);
      if (fresh) sections.push({lines: [], pages: [], period: period || null, account});
      const s = sections[sections.length - 1];
      s.lines.push(...pageLines); s.pages.push(page);
      if (!s.period && period) s.period = period;
      if (!s.account && account) s.account = account;
    }
    return sections.length ? sections : [{lines, pages: [], period: null, account: null}];
  }

  const money2 = cents => `${cents < 0 ? '-' : ''}$${(Math.abs(cents) / 100).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

  /** Rows in the converter's own shape, plus the statement check. Returns null when no table heading was found. */
  function convert(lines, options = {}) {
    const result = parseStatementLayout(lines, options);
    if (!result || !result.rows.length || result.undated) return null;
    const categorize = options.categorize || (() => 'Uncategorized');
    const pagesOk = result.pageChecks.every(check => check.ok);
    const balanced = result.reconciled === true;
    const rows = result.rows.map((row, index) => {
      const cents = Math.round(row.amount * 100), issues = [];
      if (row.balanceMismatch != null) issues.push(`The statement's running balance on this line differs from the rows above it by ${money2(Math.round(row.balanceMismatch * 100))}. Check for a missing or misread line just above.`);
      return {
        id: `L${index + 1}-${row.page}-${Math.round(row.y || 0)}`,
        date: row.date, postedDate: row.postedDate || '', description: row.description || '(no description on statement)',
        debit: cents < 0 ? -cents / 100 : 0, credit: cents > 0 ? cents / 100 : 0, amount: cents / 100, balance: row.balance,
        category: categorize(row.description, cents / 100), reference: '', page: row.page,
        confidence: balanced && pagesOk && row.balanceMismatch == null ? 99 : row.balanceMismatch != null ? 55 : 85,
        issues: issues.join(' '), raw: row.raw, included: true, duplicate: false,
        extractionMethod: balanced ? 'Column layout · balances agree' : 'Column layout',
      };
    });
    return {rows, check: {
      balanced, pagesOk, reconciled: result.reconciled, isCard: result.isCard, period: result.period,
      openingBalance: result.openingBalance, closingBalance: result.closingBalance, closingFromLastRow: result.closingFromLastRow,
      netCents: result.netCents, balanceChecks: result.balanceChecks, balanceMismatches: result.balanceMismatches,
      pageChecks: result.pageChecks, columns: result.columns, notes: result.notes, accountType: options.accountType || '',
    }};
  }

  /** Plain-language statement check. Returns {state:'ok'|'warn'|'info', text}. */
  function checkText(check) {
    if (!check) return {state: 'info', text: 'Read with the general reader. This statement prints no balances to check against, so compare the totals with the statement.'};
    const c = v => Math.round(v * 100), parts = [];
    if (check.isCard && check.accountType === 'bank') parts.push('This looks like a credit card statement, but a bank account type is selected.');
    if (check.openingBalance != null && check.closingBalance != null) {
      const show = v => money2(check.isCard ? -c(v) : c(v));
      const from = check.isCard ? `Balance owed ${show(check.openingBalance)}` : `Opening balance ${show(check.openingBalance)}`;
      const to = check.isCard ? `the new balance owed of ${show(check.closingBalance)}` : `the closing balance of ${show(check.closingBalance)}`;
      parts.push(check.reconciled ? `${from}, with these rows, comes to ${to} printed on the statement ✓` : `These rows do not add up: ${from}, with these rows, comes to ${show(check.openingBalance + check.netCents / 100)}, not ${to}. Check for a missing or misread line.`);
    } else parts.push('The statement shows no opening and closing balance to check against. Compare the totals with the statement.');
    if (check.balanceChecks) parts.push(check.balanceMismatches ? `${check.balanceMismatches} of ${check.balanceChecks} running balance${check.balanceChecks === 1 ? '' : 's'} differ (marked on the rows).` : check.balanceChecks === 1 ? 'The running balance agrees.' : `All ${check.balanceChecks} running balances agree.`);
    if (check.pageChecks && check.pageChecks.length) { const bad = check.pageChecks.filter(p => !p.ok).length; parts.push(bad ? `${bad} of ${check.pageChecks.length} page totals differ.` : check.pageChecks.length === 1 ? 'The page total agrees.' : `All ${check.pageChecks.length} page totals agree.`); }
    const ok = check.balanced && check.pagesOk && !check.balanceMismatches && !(check.isCard && check.accountType === 'bank');
    return {state: ok ? 'ok' : (check.openingBalance == null || check.closingBalance == null) && !check.balanceMismatches ? 'info' : 'warn', text: parts.join(' ')};
  }

  /** Consecutive statements of one account: each closing balance should be the next opening balance. */
  function chainCheck(documents) {
    const docs = documents.filter(d => d.check && d.check.period && d.check.period.end && d.check.openingBalance != null && d.check.closingBalance != null)
      .sort((a, b) => a.check.period.end.localeCompare(b.check.period.end));
    const links = [], last = new Map();
    // Only statements of the same account (same last four digits, same kind) are chained. v11: statements of other
    // accounts in between (several accounts in one upload) no longer break the chain.
    for (const next of docs) {
      if (!next.account) continue;
      const key = `${next.check.isCard ? 'card' : 'bank'}:${next.account}`, prev = last.get(key);
      last.set(key, next);
      if (!prev) continue;
      const ok = Math.round(prev.check.closingBalance * 100) === Math.round(next.check.openingBalance * 100);
      links.push({from: prev.fileName, to: next.fileName, ok, closing: prev.check.closingBalance, opening: next.check.openingBalance});
    }
    return links;
  }

  return {parseMoney, parseMonthDay, parseNumericDate, parseFullDate, statementPeriod, parseStatementLayout, statementAccountIds, splitStatements, convert, checkText, chainCheck, version: '11.0.0'};
});
