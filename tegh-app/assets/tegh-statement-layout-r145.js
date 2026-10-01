// R145: column-aware bank and credit-card statement reader.
//
// Reads the positioned text lines of a text-based PDF statement (from BankStatementCore.groupTextItemsToLines):
// finds each page's column headings (date, description, money out, money in, amount, balance), places every
// amount in its column by position, carries dates down to lines that do not repeat them, joins wrapped
// descriptions, ignores side panels outside the table, works out years from the statement period, and checks
// the result against the statement's own opening, running and closing balances. Runs entirely in the browser.

const MONTHS = {jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,sept:9,oct:10,nov:11,dec:12,
  january:1,february:2,march:3,april:4,june:6,july:7,august:8,september:9,october:10,november:11,december:12};
const MONTH_RE = '(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';
const pad = n => String(n).padStart(2, '0');
const iso = (y, m, d) => `${y}-${pad(m)}-${pad(d)}`;
const validYmd = (y, m, d) => { const t = new Date(Date.UTC(y, m - 1, d)); return t.getUTCFullYear() === y && t.getUTCMonth() === m - 1 && t.getUTCDate() === d; };
const fullYear = y => (y < 100 ? 2000 + y : y);

/** "1,234.56", "$1,234.56", "-$500.00", "500.00-", "(12.50)", "12.50 CR" → signed number, else null. */
export function parseMoney(text) {
  let t = String(text || '').trim().replace(/\s+/g, ' ');
  if (!t) return null;
  let neg = false;
  if (/^\(.*\)$/.test(t)) { neg = true; t = t.slice(1, -1).trim(); }
  if (/\s?(CR)$/i.test(t)) { neg = !neg; t = t.replace(/\s?CR$/i, ''); }
  if (/\s?(DR)$/i.test(t)) t = t.replace(/\s?DR$/i, '');
  if (/-$/.test(t)) { neg = !neg; t = t.slice(0, -1); }
  if (/^-/.test(t)) { neg = !neg; t = t.slice(1); }
  t = t.replace(/^\$/, '').replace(/^-/, () => { neg = !neg; return ''; }).replace(/^\$/, '');
  if (!/^\d{1,3}(,\d{3})*\.\d{2}$|^\d+\.\d{2}$/.test(t)) return null;
  const v = Number(t.replace(/,/g, ''));
  return Number.isFinite(v) ? (neg ? -v : v) : null;
}

/** Month-day tokens without a year: "Jan 3", "SEP01", "DEC 9", "03 Jan". Returns {m,d} or null. */
export function parseMonthDay(text) {
  const t = String(text || '').trim().toLowerCase();
  let m = new RegExp(`^${MONTH_RE}\\.?\\s*(\\d{1,2})$`).exec(t);
  if (m) return {m: MONTHS[m[1]] ?? MONTHS[m[1].slice(0, 3)], d: Number(m[2])};
  m = new RegExp(`^(\\d{1,2})\\s*${MONTH_RE}\\.?$`).exec(t);
  if (m) return {m: MONTHS[m[2]] ?? MONTHS[m[2].slice(0, 3)], d: Number(m[1])};
  return null;
}

/** Dates that carry their own year: "2026-03-04", "Mar 4, 2026", "4 Mar 2026". Numeric d/m vs m/d is never guessed. */
export function parseFullDate(text) {
  const t = String(text || '').trim().toLowerCase();
  let m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(t);
  if (m && validYmd(+m[1], +m[2], +m[3])) return iso(+m[1], +m[2], +m[3]);
  m = new RegExp(`^${MONTH_RE}\\.?\\s*(\\d{1,2}),?\\s+(\\d{4})$`).exec(t);
  if (m) { const mo = MONTHS[m[1]] ?? MONTHS[m[1].slice(0, 3)]; if (validYmd(+m[3], mo, +m[2])) return iso(+m[3], mo, +m[2]); }
  m = new RegExp(`^(\\d{1,2})\\s*${MONTH_RE}\\.?,?\\s+(\\d{4})$`).exec(t);
  if (m) { const mo = MONTHS[m[2]] ?? MONTHS[m[2].slice(0, 3)]; if (validYmd(+m[3], mo, +m[1])) return iso(+m[3], mo, +m[1]); }
  return null;
}

/** Statement period from the header text. Returns {start, end, evidence} with ISO dates, or null. */
export function statementPeriod(text) {
  const src = String(text || '').slice(0, 20000);
  const monthOf = s => MONTHS[s.toLowerCase()] ?? MONTHS[s.toLowerCase().slice(0, 3)];
  let m;
  // "For Jan 1 to Jan 31, 2023" / "Jan 1, 2023 to Jan 31, 2023"
  m = new RegExp(`${MONTH_RE}\\.?\\s+(\\d{1,2})(?:,?\\s+(\\d{4}))?\\s+(?:to|-|–|through)\\s+${MONTH_RE}\\.?\\s+(\\d{1,2}),?\\s+(\\d{4})`, 'i').exec(src);
  if (m) {
    const y2 = Number(m[6]), m2 = monthOf(m[4]), d2 = Number(m[5]), m1 = monthOf(m[1]), d1 = Number(m[2]);
    const y1 = m[3] ? Number(m[3]) : (m1 > m2 ? y2 - 1 : y2);
    if (validYmd(y1, m1, d1) && validYmd(y2, m2, d2)) return {start: iso(y1, m1, d1), end: iso(y2, m2, d2), evidence: m[0]};
  }
  // TD: "AUG 31/23 - SEP 29/23"
  m = new RegExp(`${MONTH_RE}\\s*(\\d{1,2})\\s*/\\s*(\\d{2,4})\\s*[-–]\\s*${MONTH_RE}\\s*(\\d{1,2})\\s*/\\s*(\\d{2,4})`, 'i').exec(src);
  if (m) {
    const y1 = fullYear(Number(m[3])), y2 = fullYear(Number(m[6])), m1 = monthOf(m[1]), m2 = monthOf(m[4]);
    if (validYmd(y1, m1, Number(m[2])) && validYmd(y2, m2, Number(m[5]))) return {start: iso(y1, m1, Number(m[2])), end: iso(y2, m2, Number(m[5])), evidence: m[0]};
  }
  // Card statements: "STATEMENT DATE: January 06, 2025" with "PREVIOUS STATEMENT: December 05, 2024"
  const sd = new RegExp(`STATEMENT\\s+DATE\\s*:?\\s*${MONTH_RE}\\.?\\s+(\\d{1,2}),?\\s+(\\d{4})`, 'i').exec(src);
  const ps = new RegExp(`PREVIOUS\\s+STATEMENT(?:\\s+DATE)?\\s*:?\\s*${MONTH_RE}\\.?\\s+(\\d{1,2}),?\\s+(\\d{4})`, 'i').exec(src);
  if (sd) {
    const end = iso(Number(sd[3]), monthOf(sd[1]), Number(sd[2]));
    const start = ps ? iso(Number(ps[3]), monthOf(ps[1]), Number(ps[2])) : null;
    return {start, end, evidence: sd[0]};
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
    const lo = period.start ? new Date(Date.parse(period.start) - 40 * 86400000).toISOString().slice(0, 10) : new Date(Date.parse(period.end) - 400 * 86400000).toISOString().slice(0, 10);
    const hi = new Date(Date.parse(period.end) + 7 * 86400000).toISOString().slice(0, 10);
    if (d >= lo && d <= hi) return d;
  }
  return null;
}

const HEADER_WORDS = [
  ['postdate', /^posting(\s+date)?$/i],
  ['date', /^(transaction\s+)?date$|^date\s+posted$|^trans(action)?\.?\s*date$/i],
  ['desc', /^(activity\s+)?description$|^details$|^particulars$|^transaction(s)?(\s+details)?$|^activity$/i],
  ['debit', /^(withdrawals?|cheques?\s*\/\s*debits?|cheque\/debit|debits?|money\s+out|withdrawals?\s*\/\s*debits?|payments?\s+out|charges?)(\s*\(\$\))?$/i],
  ['credit', /^(deposits?|deposits?\s*\/\s*credits?|deposit\/credit|credits?|money\s+in|payments?\s*(&|and)\s*credits?)(\s*\(\$\))?$/i],
  ['amount', /^amount(\s*\(\$\))?(\(\$\))?$/i],
  ['balance', /^(running\s+)?balance(\s*\(\$\))?$/i],
];

/** Split a line's cells into header tokens (pdf.js often gives one cell per word). */
function headerColumns(line) {
  const cells = line.cells.map(c => ({text: c.text.trim(), x: c.x, r: c.x + (c.width || c.text.length * 4)}));
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
      const norm = part.replace(/\s+/g, ' ').trim();
      const hit = HEADER_WORDS.find(([, re]) => re.test(norm));
      if (hit) cols.push({role: hit[0], text: norm, x: start, r: end});
      else if (/^date\s+date$/i.test(norm)) { cols.push({role: 'date', text: 'DATE', x: start, r: start + (end - start) / 2}); cols.push({role: 'postdate', text: 'DATE', x: start + (end - start) / 2, r: end}); }
    }
  }
  return cols;
}

function isHeaderLine(line) {
  const cols = headerColumns(line);
  const roles = new Set(cols.map(c => c.role));
  const money = ['debit', 'credit', 'amount', 'balance'].filter(r => roles.has(r)).length;
  return money >= 1 && (roles.has('desc') || roles.has('date')) && cols.length >= 3 ? cols : null;
}

const OPENING_RE = /^(opening\s+balance|balance\s+forward|previous\s+(statement\s+)?balance|starting\s+balance|balance\s+brought\s+forward)\b/i;
const CLOSING_RE = /^(closing\s+balance|(total\s+)?new\s+balance|ending\s+balance|balance\s+carried\s+forward)\b/i;
const FOOTER_RE = /\bchq\s+enclosed\b|\bnext\s+statement\s+date\b|\bstatement\s+date\s+is\b/i;
const STOP_RE = /^(\(?continued(\s+on\s+next\s+page)?\)?$|sub-?total\b|monthly aver|next statement date|please ensure that you report|calculating your balance|interest charged|total\s+(withdrawals|deposits|debits|credits))/i;

/**
 * Parse positioned statement lines. Returns null when no table heading is found.
 * options.accountType: 'credit_card' flips the sign convention (charges are money out, payments money in).
 */
export function parseStatementLayout(lines, options = {}) {
  const text = lines.map(l => l.cells.map(c => c.text).join(' ')).join('\n');
  const period = statementPeriod(text);
  const isCard = options.accountType === 'credit_card' || /\b(credit\s+limit|minimum\s+payment|available\s+credit|previous\s+statement\s+balance)\b/i.test(text);
  let cols = null, tableLeft = 0, tableRight = Infinity, lastDate = null, current = null, inTable = false;
  const rows = [], checkpoints = [], notes = [];
  let opening = null, closing = null;
  const colFor = (x, r) => {
    // Amounts are right-aligned: compare the cell's right edge with each money column's span.
    let best = null, bestD = Infinity;
    for (const c of cols) {
      if (!['debit', 'credit', 'amount', 'balance'].includes(c.role)) continue;
      const centre = (c.x + c.r) / 2, d = Math.min(Math.abs(r - c.r), Math.abs(r - centre), Math.abs((x + r) / 2 - centre));
      if (d < bestD) { bestD = d; best = c; }
    }
    return bestD < 90 ? best : null;
  };
  const dateCols = () => cols.filter(c => c.role === 'date' || c.role === 'postdate');
  for (const line of lines) {
    const hdr = isHeaderLine(line);
    if (hdr) {
      cols = hdr.sort((a, b) => a.x - b.x); inTable = true; current = null;
      tableLeft = Math.min(...cols.map(c => c.x)) - 60; tableRight = Math.max(...cols.map(c => c.r)) + 45;
      continue;
    }
    if (!cols || !inTable) continue;
    const cells = line.cells.filter(c => c.x >= tableLeft && c.x <= tableRight).map(c => ({text: c.text.trim(), x: c.x, r: c.x + (c.width || c.text.length * 4)})).filter(c => c.text);
    if (!cells.length) continue;
    const joined = cells.map(c => c.text).join(' ').replace(/\s+/g, ' ').trim();
    // Totals, "Continued" and summary boxes end the table until the next heading (payment slips and summaries below it are not rows).
    if (STOP_RE.test(joined) || FOOTER_RE.test(joined)) { current = null; inTable = false; continue; }
    // Classify cells.
    const money = {}, dates = [], words = [];
    let collision = false;
    for (const c of cells) {
      const v = parseMoney(c.text);
      if (v !== null) {
        const col = colFor(c.x, c.r);
        if (col) { if (money[col.role] != null) collision = true; money[col.role] = v; continue; }
      }
      const inDateCol = dateCols().some(dc => c.x < dc.r + 30 && c.r > dc.x - 30);
      const full = inDateCol ? parseFullDate(c.text) : null;
      if (full) { dates.push({full, x: c.x}); continue; }
      const md = parseMonthDay(c.text);
      if (md && inDateCol) { dates.push({md, x: c.x}); continue; }
      // Month and day may arrive as separate cells ("DEC" "9").
      words.push(c);
    }
    // Rejoin split dates ("DEC" + "9") that sit in a date column.
    for (let i = 0; i < words.length - 1; i++) {
      const md = parseMonthDay(words[i].text + ' ' + words[i + 1].text);
      if (md && dateCols().some(dc => words[i].x < dc.r + 30 && words[i + 1].r > dc.x - 30)) { dates.push({md, x: words[i].x}); words.splice(i, 2); i--; }
    }
    // Two amounts in one column is a summary box or payment slip, never a transaction.
    if (collision) { current = null; notes.push(`Skipped a line on page ${line.page} with two amounts in one column.`); continue; }
    dates.sort((a, b) => a.x - b.x);
    // Description = remaining words that sit left of the first money column (or in the description column).
    const firstMoney = Math.min(...cols.filter(c => ['debit', 'credit', 'amount', 'balance'].includes(c.role)).map(c => c.x));
    const desc = words.filter(w => w.x < firstMoney - 4 || cols.some(c => c.role === 'desc' && w.x >= c.x - 10 && w.x < firstMoney)).map(w => w.text).join(' ').replace(/\s+/g, ' ').trim();
    const when = d => d ? (d.full || dateInPeriod(d.md, period)) : null;
    const rowDate = when(dates[0]), postDate = when(dates[1]);
    if (rowDate) lastDate = rowDate;
    const flow = money.debit != null || money.credit != null || money.amount != null;
    if (OPENING_RE.test(desc) && !flow || OPENING_RE.test(desc) && isCard && money.amount != null && money.balance == null) {
      const bal = money.balance ?? money.amount ?? null;
      if (bal != null) { const b = isCard ? -Math.abs(bal) * Math.sign(bal || 1) : bal; checkpoints.push({after: rows.length, balance: b, kind: 'forward'}); if (opening == null) opening = b; }
      current = null; continue;
    }
    if (CLOSING_RE.test(desc)) {
      const bal = money.balance ?? money.amount ?? null;
      if (bal != null) closing = isCard ? -bal : bal;
      current = null; continue;
    }
    if (flow) {
      let amount;
      if (money.amount != null) amount = isCard ? -money.amount : money.amount;
      else amount = (money.credit ?? 0) - Math.abs(money.debit ?? 0);
      if (isCard && money.amount == null) amount = (money.credit ?? 0) - Math.abs(money.debit ?? 0);
      const balance = money.balance != null ? (isCard ? -money.balance : money.balance) : null;
      current = {date: rowDate || lastDate, postedDate: postDate, description: desc, amount: Math.round(amount * 100) / 100, balance, page: line.page, y: line.y, raw: joined};
      rows.push(current);
      continue;
    }
    if (money.balance != null && current && current.balance == null && !desc) { current.balance = isCard ? -money.balance : money.balance; continue; }
    // Wrapped description: text only, directly under the previous transaction on the same page.
    if (current && desc && !dates.length && current.page === line.page && Math.abs(current.y - line.y) <= 18 && desc.length <= 80) {
      current.description = (current.description + ' · ' + desc).slice(0, 400); current.raw += ' ' + joined; current.y = line.y; continue;
    }
    if (desc) current = null;
  }
  if (!cols || !rows.length) return null;
  // Statement summary lines outside the table ("Opening balance on Jan 1, 2023 $31,742.11", "Previous Balance $15,652.93").
  const sum = (re) => { const m = re.exec(text); return m ? parseMoney(m[1]) : null; };
  if (opening == null) { const v = sum(/(?:opening\s+balance|previous\s+(?:statement\s+)?balance)[^\n$\d-]*?(-?\$?[\d,]+\.\d{2})/i); if (v != null) opening = isCard ? -v : v; }
  if (closing == null) { const v = sum(/(?:closing\s+balance|(?:total\s+)?new\s+balance)[^\n$\d-]*?(?:on\s+\w+\s+\d{1,2},?\s+\d{4}\s*)?(-?\$?[\d,]+\.\d{2})/i); if (v != null) closing = isCard ? -v : v; }
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
    netCents: net, columns: cols.map(c => c.role), notes,
  };
}
