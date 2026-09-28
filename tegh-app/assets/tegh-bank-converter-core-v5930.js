/* TEGH adapter source: SR AccounTax bank-converter-core.js, recovered 2026-09-08.
   Original SHA256 e89feb4327c8c1813102cf8b8ab5bb969edf6e28b6893c1adda997d1e6b4d9dd.
   Review-only heuristics: never authorization or a calibrated category probability. */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.BankStatementCore = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  const MONTHS = {
    jan: 1, january: 1, feb: 2, february: 2, mar: 3, march: 3,
    apr: 4, april: 4, may: 5, jun: 6, june: 6, jul: 7, july: 7,
    aug: 8, august: 8, sep: 9, sept: 9, september: 9,
    oct: 10, october: 10, nov: 11, november: 11, dec: 12, december: 12,
  };

  const MONEY_PATTERN = /(?:\(\s*)?(?:CAD|USD|C\$|US\$|\$)?\s*[+-]?(?:\d{1,3}(?:[,\s]\d{3})+|\d+)(?:[.,]\d{2})(?:\s*\))?-?\s*(?:CR|DR)?/gi;
  const DATE_PATTERNS = [
    /^\s*(\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2})\b/i,
    /^\s*(\d{1,2}[-\/.]\d{1,2}(?:[-\/.]\d{2,4})?)\b/i,
    /^\s*((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2}(?:,?\s+\d{2,4})?)\b/i,
    /^\s*(\d{1,2}\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)(?:\s+\d{2,4})?)\b/i,
  ];

  const HEADER_TERMS = /\b(date|description|details|transaction|withdrawal|withdrawals|debit|debits|deposit|deposits|credit|credits|amount|balance)\b/i;
  const SUMMARY_TERMS = /\b(opening balance|closing balance|previous balance|balance forward|carried forward|total withdrawals?|total deposits?|account summary|statement period|account number|page \d+|continued on|daily closing balance|important information)\b/i;
  const FOOTER_TERMS = /\b(member fdic|cdic|deposit insurance|contact us|customer service|terms and conditions|www\.|telephone|privacy|page \d+ of \d+)\b/i;

  const DEBIT_WORDS = /\b(purchase|payment|withdrawal|debit|fee|charge|cheque|check|bill|pos|atm|interac purchase|e-transfer sent|transfer out|pre-authorized|preauthorized|mortgage|rent|insurance|tax payment|cash withdrawal)\b/i;
  const CREDIT_WORDS = /\b(payroll|salary|deposit|credit|refund|interest paid|e-transfer received|transfer in|direct deposit|government payment|benefit|reimbursement|sale|revenue)\b/i;

  function normalizeWhitespace(value) {
    return String(value || '').replace(/[\u00a0\u2007\u202f]/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function pad2(value) {
    return String(value).padStart(2, '0');
  }

  function normalizeYear(rawYear, fallbackYear) {
    let year = Number(rawYear || fallbackYear || new Date().getFullYear());
    if (year < 100) year += year >= 70 ? 1900 : 2000;
    return year;
  }

  function findLeadingDate(text) {
    for (const regex of DATE_PATTERNS) {
      const match = String(text || '').match(regex);
      if (match) return { token: normalizeWhitespace(match[1]), length: match[0].length };
    }
    return null;
  }

  function parseDateToken(token, options) {
    const opts = options || {};
    const fallbackYear = Number(opts.year || new Date().getFullYear());
    const order = opts.dateOrder || 'auto';
    let raw = normalizeWhitespace(token).replace(/,/g, '');
    let year = fallbackYear;
    let month;
    let day;
    let ambiguous = false;

    let match = raw.match(/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/);
    if (match) {
      year = Number(match[1]); month = Number(match[2]); day = Number(match[3]);
    } else if ((match = raw.match(/^(\d{1,2})[-\/.](\d{1,2})(?:[-\/.](\d{2,4}))?$/))) {
      const first = Number(match[1]);
      const second = Number(match[2]);
      if (match[3]) year = normalizeYear(match[3], fallbackYear);
      let actualOrder = order;
      if (actualOrder === 'auto') {
        if (first > 12 && second <= 12) actualOrder = 'dmy';
        else if (second > 12 && first <= 12) actualOrder = 'mdy';
        else { actualOrder = 'mdy'; ambiguous = true; }
      }
      if (actualOrder === 'dmy') { day = first; month = second; }
      else { month = first; day = second; }
    } else if ((match = raw.match(/^([A-Za-z]+)\s+(\d{1,2})(?:\s+(\d{2,4}))?$/))) {
      month = MONTHS[match[1].toLowerCase()];
      day = Number(match[2]);
      if (match[3]) year = normalizeYear(match[3], fallbackYear);
    } else if ((match = raw.match(/^(\d{1,2})\s+([A-Za-z]+)(?:\s+(\d{2,4}))?$/))) {
      day = Number(match[1]);
      month = MONTHS[match[2].toLowerCase()];
      if (match[3]) year = normalizeYear(match[3], fallbackYear);
    }

    // A statement can span December/January; do not assign every yearless row
    // to the document's most frequent year. Only one in-period year is safe.
    const explicitYear = /^\d{4}[-/.]/.test(raw) || /[-/.]\d{1,2}[-/.]\d{2,4}$/.test(raw)
      || /[A-Za-z].*\d{1,2}\s+\d{2,4}$/.test(raw) || /^\d{1,2}\s+[A-Za-z]+\s+\d{2,4}$/.test(raw);
    if (!explicitYear && opts.periodStart && opts.periodEnd && month && day) {
      const candidates = [];
      for (let y = Number(opts.periodStart.slice(0, 4)); y <= Number(opts.periodEnd.slice(0, 4)); y++) {
        const iso = `${y}-${pad2(month)}-${pad2(day)}`;
        if (iso >= opts.periodStart && iso <= opts.periodEnd) candidates.push(y);
      }
      if (candidates.length === 1) year = candidates[0];
      else ambiguous = true;
    } else if (!explicitYear && !opts.year) ambiguous = true;

    if (!year || !month || !day || month > 12 || day > 31) {
      return { iso: '', valid: false, ambiguous: true, raw: token };
    }
    const date = new Date(Date.UTC(year, month - 1, day));
    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
      return { iso: '', valid: false, ambiguous: true, raw: token };
    }
    return { iso: `${year}-${pad2(month)}-${pad2(day)}`, valid: true, ambiguous, raw: token };
  }

  function extractLeadingDates(text, options) {
    let remainder = normalizeWhitespace(text);
    const first = findLeadingDate(remainder);
    if (!first) return null;
    const date = parseDateToken(first.token, options);
    remainder = normalizeWhitespace(remainder.slice(first.length));
    const second = findLeadingDate(remainder);
    let postedDate = null;
    if (second) {
      const parsedSecond = parseDateToken(second.token, options);
      if (parsedSecond.valid) {
        postedDate = parsedSecond;
        remainder = normalizeWhitespace(remainder.slice(second.length));
      }
    }
    return { date, postedDate, remainder, firstToken: first.token };
  }

  function parseMoney(raw, decimalSeparator) {
    let value = normalizeWhitespace(raw).toUpperCase();
    if (!value) return null;
    const signValue = value.replace(/\b(?:CAD|USD)\b/g, '').replace(/(?:C\$|US\$|\$)/g, '').trim();
    const negative = /\([^)]*\)/.test(signValue) || /^-/.test(signValue) || /-(?:\s*(?:CR|DR))?$/.test(signValue) || /\bDR$/.test(signValue);
    const positiveMarker = /\bCR$/.test(value);
    value = value
      .replace(/\b(?:CAD|USD)\b/g, '')
      .replace(/(?:C\$|US\$|\$)/g, '')
      .replace(/\b(?:CR|DR)\b/g, '')
      .replace(/[()\s+-]/g, '');

    const mode = decimalSeparator || 'auto';
    if (mode === 'comma') {
      value = value.replace(/\./g, '').replace(',', '.');
    } else if (mode === 'dot') {
      value = value.replace(/,/g, '');
    } else {
      const lastComma = value.lastIndexOf(',');
      const lastDot = value.lastIndexOf('.');
      if (lastComma > lastDot && /,\d{2}$/.test(value)) value = value.replace(/\./g, '').replace(',', '.');
      else value = value.replace(/,/g, '');
    }
    const number = Number(value);
    if (!Number.isFinite(number)) return null;
    return {
      value: negative ? -Math.abs(number) : Math.abs(number),
      explicitSign: negative || positiveMarker || /^[+-]/.test(signValue),
      marker: negative ? 'debit' : positiveMarker ? 'credit' : '',
      raw: normalizeWhitespace(raw),
    };
  }

  function extractMoneyTokens(text, options) {
    const tokens = [];
    const source = String(text || '');
    MONEY_PATTERN.lastIndex = 0;
    let match;
    while ((match = MONEY_PATTERN.exec(source)) !== null) {
      const parsed = parseMoney(match[0], options && options.decimalSeparator);
      if (parsed) tokens.push({ ...parsed, index: match.index, end: match.index + match[0].length });
      if (match.index === MONEY_PATTERN.lastIndex) MONEY_PATTERN.lastIndex += 1;
    }
    return tokens;
  }

  function extractTrailingMoneyTokens(text, options) {
    const source = String(text || '');
    const all = extractMoneyTokens(source, options);
    if (!all.length) return [];
    const selected = [];
    let cursor = source.length;
    for (let i = all.length - 1; i >= 0; i -= 1) {
      const token = all[i];
      const gap = source.slice(token.end, cursor);
      if (selected.length === 0) {
        if (!/^\s*$/.test(gap)) break;
      } else if (!/^[\s|]*$/.test(gap)) break;
      selected.unshift(token);
      cursor = token.index;
    }
    return selected;
  }

  function groupTextItemsToLines(items, pageNumber) {
    const clean = (items || [])
      .filter(item => item && typeof item.str === 'string' && normalizeWhitespace(item.str))
      .map(item => ({
        text: normalizeWhitespace(item.str),
        x: Number(item.transform && item.transform[4]) || 0,
        y: Number(item.transform && item.transform[5]) || 0,
        width: Number(item.width) || 0,
        height: Math.abs(Number(item.height) || Number(item.transform && item.transform[3]) || 8),
      }))
      .sort((a, b) => Math.abs(b.y - a.y) > 2.5 ? b.y - a.y : a.x - b.x);

    const lines = [];
    const tolerance = 2.8;
    for (const item of clean) {
      let line = lines.find(candidate => Math.abs(candidate.y - item.y) <= tolerance);
      if (!line) {
        line = { page: pageNumber, y: item.y, cells: [] };
        lines.push(line);
      }
      line.cells.push(item);
      line.y = (line.y * (line.cells.length - 1) + item.y) / line.cells.length;
    }

    return lines
      .sort((a, b) => b.y - a.y)
      .map(line => {
        line.cells.sort((a, b) => a.x - b.x);
        let text = '';
        let previousEnd = null;
        line.cells.forEach(cell => {
          if (previousEnd !== null) {
            const gap = cell.x - previousEnd;
            text += gap > 24 ? '   ' : gap > 4 ? ' ' : '';
          }
          text += cell.text;
          previousEnd = cell.x + cell.width;
        });
        return { ...line, text: normalizeWhitespace(text) };
      })
      .filter(line => line.text);
  }

  function plainTextToLines(text, pageNumber) {
    return String(text || '').split(/\r?\n/)
      .map((line, index) => ({ page: pageNumber, y: 10000 - index, cells: [], text: normalizeWhitespace(line), ocr: true }))
      .filter(line => line.text);
  }

  function isHeaderLike(line) {
    const text = normalizeWhitespace(typeof line === 'string' ? line : line.text);
    if (!text) return true;
    if (FOOTER_TERMS.test(text)) return true;
    const date = findLeadingDate(text);
    if (!date && SUMMARY_TERMS.test(text)) return true;
    if (!date && HEADER_TERMS.test(text) && /\b(date|transaction|description|details)\b/i.test(text)) return true;
    return false;
  }

  function findKeywordAnchor(line, terms) {
    for (const cell of line.cells || []) {
      if (terms.test(cell.text)) return cell.x;
    }
    return null;
  }

  function clusterNumbers(values, gap) {
    const sorted = [...values].filter(Number.isFinite).sort((a, b) => a - b);
    const clusters = [];
    sorted.forEach(value => {
      const last = clusters[clusters.length - 1];
      if (!last || value - last[last.length - 1] > gap) clusters.push([value]);
      else last.push(value);
    });
    return clusters.map(cluster => cluster.reduce((a, b) => a + b, 0) / cluster.length);
  }

  function moneyCellsForLine(line, options) {
    const results = [];
    for (const cell of line.cells || []) {
      const matches = extractMoneyTokens(cell.text, options);
      for (const token of matches) {
        const ratio = cell.text.length ? token.index / cell.text.length : 0;
        results.push({ ...token, x: cell.x + ratio * cell.width });
      }
    }
    return results;
  }

  function detectLayout(lines, options) {
    const explicit = options && options.layout && options.layout !== 'auto' ? options.layout : null;
    const candidates = [];
    for (const line of lines || []) {
      if (!HEADER_TERMS.test(line.text) || findLeadingDate(line.text) || SUMMARY_TERMS.test(line.text)) continue;
      const anchors = {
        debit: findKeywordAnchor(line, /withdraw|debit|money out|payments?/i),
        credit: findKeywordAnchor(line, /deposit|credit|money in/i),
        balance: findKeywordAnchor(line, /balance/i),
        amount: findKeywordAnchor(line, /amount/i),
      };
      const count = Object.values(anchors).filter(Number.isFinite).length;
      if (count) candidates.push({ line, anchors, count });
    }
    candidates.sort((a, b) => b.count - a.count);
    const best = candidates[0];
    let anchors = best ? best.anchors : { debit: null, credit: null, balance: null, amount: null };

    const amountX = [];
    let maxAmountsPerRow = 0;
    for (const line of lines || []) {
      if (!findLeadingDate(line.text)) continue;
      const amounts = moneyCellsForLine(line, options);
      maxAmountsPerRow = Math.max(maxAmountsPerRow, amounts.length);
      amounts.forEach(token => amountX.push(token.x));
    }
    let clusters = clusterNumbers(amountX, 26);
    if (clusters.length > 3) clusters = clusters.slice(-3);

    let mode = explicit;
    if (!mode) {
      if (Number.isFinite(anchors.debit) && Number.isFinite(anchors.credit) && Number.isFinite(anchors.balance)) mode = 'debit-credit-balance';
      else if (Number.isFinite(anchors.debit) && Number.isFinite(anchors.credit)) mode = 'debit-credit';
      else if (maxAmountsPerRow >= 2 && (Number.isFinite(anchors.amount) || clusters.length >= 1) && Number.isFinite(anchors.balance)) mode = 'amount-balance';
      else if (clusters.length >= 3 && maxAmountsPerRow >= 3) mode = 'debit-credit-balance';
      else if (clusters.length >= 2 && maxAmountsPerRow >= 2) mode = 'amount-balance';
      else mode = 'amount';
    }

    if (!Number.isFinite(anchors.debit) && !Number.isFinite(anchors.credit) && !Number.isFinite(anchors.balance) && clusters.length) {
      if (mode === 'debit-credit-balance' && clusters.length >= 3) [anchors.debit, anchors.credit, anchors.balance] = clusters.slice(-3);
      else if (mode === 'debit-credit' && clusters.length >= 2) [anchors.debit, anchors.credit] = clusters.slice(-2);
      else if (mode === 'amount-balance' && clusters.length >= 2) [anchors.amount, anchors.balance] = clusters.slice(-2);
      else anchors.amount = clusters[clusters.length - 1];
    }

    return { mode, anchors, clusters, header: best ? best.line.text : '' };
  }

  function assignByAnchor(tokens, layout) {
    const result = {};
    const anchors = Object.entries(layout.anchors || {}).filter(([, x]) => Number.isFinite(x));
    for (const token of tokens) {
      if (!Number.isFinite(token.x) || !anchors.length) continue;
      let closest = anchors[0];
      for (const anchor of anchors) {
        if (Math.abs(anchor[1] - token.x) < Math.abs(closest[1] - token.x)) closest = anchor;
      }
      result[closest[0]] = token;
    }
    return result;
  }

  function stripTrailingAmounts(text, options) {
    const tokens = extractTrailingMoneyTokens(text, options);
    if (!tokens.length) return normalizeWhitespace(text);
    return normalizeWhitespace(String(text).slice(0, tokens[0].index));
  }

  function categorize(description, amount) {
    const value = normalizeWhitespace(description).toLowerCase();
    const rules = [
      ['Payroll remittance — review liability', /payroll remittance|(?:cra|revenue canada).*(?:payroll|remit)|(?:payroll|remit).*(?:cra|revenue canada)/],
      ['Loan proceeds / principal — review borrowing', /loan|financing|line of credit|loc advance|mortgage principal/],
      ['Owner transfer — review equity or loan', /shareholder|owner contribution|from personal/],
      ['Bank Fees', /\bfee\b|service charge|monthly charge|overdraft fee|\bnsf\b/],
      ['Payroll — review payment or receipt', /payroll|salary/],
      ['Sales & Revenue', /customer payment|merchant deposit|sale|revenue|stripe|square/],
      ['Transfers', /e-?transfer|transfer|etransfer|wire/],
      ['Rent & Mortgage', /rent|mortgage/],
      ['Utilities & Telecom', /hydro|electric|gas bill|water|internet|phone|mobile|rogers|bell|telus/],
      ['Fuel & Vehicle', /fuel|gas station|petro|esso|shell|parking|407 etr|auto|vehicle/],
      ['Meals & Entertainment', /restaurant|cafe|coffee|uber eats|doordash|skip the dishes/],
      ['Office & Supplies', /office|staples|software|subscription|amazon|supplies/],
      ['Tax Payments', /cra|revenue canada|tax payment|hst|gst|payroll remittance/],
      ['Insurance', /insurance|premium/],
      ['Cash & ATM', /atm|cash withdrawal/],
      ['Credit Card', /visa|mastercard|amex|credit card/],
      ['Loan & Interest', /loan|interest|financing/],
    ];
    for (const [name, regex] of rules) if (regex.test(value)) return name;
    return 'Needs review';
  }

  function inferDirection(description) {
    if (CREDIT_WORDS.test(description) && !DEBIT_WORDS.test(description)) return 'credit';
    if (DEBIT_WORDS.test(description) && !CREDIT_WORDS.test(description)) return 'debit';
    return '';
  }

  function simpleHash(value) {
    let hash = 2166136261;
    const text = String(value || '');
    for (let i = 0; i < text.length; i += 1) {
      hash ^= text.charCodeAt(i);
      hash = Math.imul(hash, 16777619);
    }
    return (hash >>> 0).toString(16).padStart(8, '0');
  }

  function buildBlocks(lines) {
    const blocks = [];
    let current = null;
    for (const line of lines || []) {
      const leading = findLeadingDate(line.text);
      if (leading) {
        if (current) blocks.push(current);
        current = { page: line.page, lines: [line] };
        continue;
      }
      if (!current) continue;
      if (line.page !== current.page || isHeaderLike(line)) {
        blocks.push(current);
        current = null;
        continue;
      }
      if (SUMMARY_TERMS.test(line.text) || FOOTER_TERMS.test(line.text)) {
        blocks.push(current);
        current = null;
        continue;
      }
      if (line.text.length <= 160) current.lines.push(line);
    }
    if (current) blocks.push(current);
    return blocks;
  }

  function parseBlock(block, layout, options) {
    const firstLine = block.lines[0];
    const dates = extractLeadingDates(firstLine.text, options);
    if (!dates || !dates.date.valid) return null;

    const coordinateTokens = moneyCellsForLine(firstLine, options);
    let trailing = extractTrailingMoneyTokens(firstLine.text, options);
    if (!trailing.length && block.lines.length > 1) trailing = extractTrailingMoneyTokens(block.lines.map(line => line.text).join(' '), options);
    const assigned = assignByAnchor(coordinateTokens, layout);

    let debit = 0;
    let credit = 0;
    let balance = null;
    let rawAmount = null;
    let explicitDirection = false;
    let inferred = false;

    const take = token => token && Number.isFinite(token.value) ? token : null;
    const absolute = token => Math.abs(token.value);

    if (layout.mode === 'debit-credit-balance') {
      if (take(assigned.debit)) debit = absolute(assigned.debit);
      if (take(assigned.credit)) credit = absolute(assigned.credit);
      if (take(assigned.balance)) balance = assigned.balance.value;
      if (!debit && !credit && trailing.length) {
        const values = trailing.map(token => token);
        if (values.length >= 3) { debit = absolute(values[0]); credit = absolute(values[1]); balance = values[2].value; }
        else if (values.length === 2) { rawAmount = values[0]; balance = values[1].value; }
        else rawAmount = values[0];
      }
      explicitDirection = Boolean(debit || credit);
    } else if (layout.mode === 'debit-credit') {
      if (take(assigned.debit)) debit = absolute(assigned.debit);
      if (take(assigned.credit)) credit = absolute(assigned.credit);
      if (!debit && !credit && trailing.length >= 2) { debit = absolute(trailing[0]); credit = absolute(trailing[1]); }
      else if (!debit && !credit && trailing.length === 1) rawAmount = trailing[0];
      explicitDirection = Boolean(debit || credit);
    } else if (layout.mode === 'amount-balance') {
      rawAmount = take(assigned.amount) || trailing[0] || null;
      const balanceToken = take(assigned.balance) || (trailing.length >= 2 ? trailing[trailing.length - 1] : null);
      if (balanceToken && rawAmount !== balanceToken) balance = balanceToken.value;
      if (rawAmount && rawAmount.explicitSign) {
        if (rawAmount.value < 0 || rawAmount.marker === 'debit') debit = Math.abs(rawAmount.value);
        else credit = Math.abs(rawAmount.value);
        explicitDirection = true;
      }
    } else {
      rawAmount = take(assigned.amount) || trailing[trailing.length - 1] || null;
      if (rawAmount && rawAmount.explicitSign) {
        if (rawAmount.value < 0 || rawAmount.marker === 'debit') debit = Math.abs(rawAmount.value);
        else credit = Math.abs(rawAmount.value);
        explicitDirection = true;
      }
    }

    const firstDescription = stripTrailingAmounts(dates.remainder, options);
    const continuation = block.lines.slice(1)
      .map(line => stripTrailingAmounts(line.text, options))
      .filter(text => text && !isHeaderLike(text) && !SUMMARY_TERMS.test(text));
    let description = normalizeWhitespace([firstDescription, ...continuation].join(' '));
    description = description.replace(/^[-|:]+|[-|:]+$/g, '').trim();

    if (!description || SUMMARY_TERMS.test(description)) return null;
    if (!debit && !credit && rawAmount) {
      const direction = inferDirection(description);
      if (direction === 'debit') debit = Math.abs(rawAmount.value);
      else if (direction === 'credit') credit = Math.abs(rawAmount.value);
      else if (rawAmount.value < 0) debit = Math.abs(rawAmount.value);
      else credit = Math.abs(rawAmount.value);
      inferred = true;
    }

    if (!debit && !credit && balance === null) return null;
    const amount = Number((credit - debit).toFixed(2));
    let confidence = 35;
    if (dates.date.valid) confidence += 15;
    if (description.length >= 4) confidence += 10;
    if (explicitDirection) confidence += 25;
    else if (inferred) confidence += 5;
    if (balance !== null) confidence += 10;
    if (dates.date.ambiguous) confidence -= 12;
    confidence = Math.max(15, Math.min(100, confidence));

    const issues = [];
    if (dates.date.ambiguous) issues.push('Ambiguous numeric date; confirm month/day order.');
    if (inferred && !explicitDirection) issues.push('Debit/credit direction was inferred; verify it.');
    if (block.lines.some(line => line.ocr)) issues.push('Extracted with OCR; compare against the statement.');

    const row = {
      date: dates.date.iso,
      postedDate: dates.postedDate && dates.postedDate.valid ? dates.postedDate.iso : '',
      description,
      debit: Number(debit.toFixed(2)),
      credit: Number(credit.toFixed(2)),
      amount,
      balance: balance === null ? null : Number(balance.toFixed(2)),
      category: categorize(description, amount),
      reference: '',
      page: block.page,
      confidence,
      issues: issues.join(' '),
      raw: block.lines.map(line => line.text).join(' | '),
      included: true,
      duplicate: false,
      extractionMethod: block.lines.some(line => line.ocr) ? 'OCR' : 'Text',
    };
    row.id = simpleHash([row.date, row.postedDate, row.description, row.amount, row.balance, row.page].join('|'));
    return row;
  }

  function inferBalances(rows, metadata) {
    let previousBalance = metadata && Number.isFinite(metadata.openingBalance) ? metadata.openingBalance : null;
    for (const row of rows) {
      if (row.balance !== null && previousBalance !== null) {
        const deltaCents = Math.round(row.balance * 100) - Math.round(previousBalance * 100);
        if (deltaCents !== Math.round(row.amount * 100)) {
          row.issues = [row.issues, 'Running balance difference: compare the original amount and direction; no cents were changed.'].filter(Boolean).join(' ');
          row.balanceDifferenceCents = deltaCents - Math.round(row.amount * 100);
        }
      }
      if (row.balance !== null) previousBalance = row.balance;
    }
  }

  function extractMetadata(lines, options) {
    const texts = (lines || []).map(line => line.text);
    const years = [];
    texts.slice(0, 80).forEach(text => {
      const matches = text.match(/\b20\d{2}\b/g) || [];
      matches.forEach(year => years.push(Number(year)));
    });
    const yearCounts = years.reduce((map, year) => map.set(year, (map.get(year) || 0) + 1), new Map());
    const detectedYear = [...yearCounts.entries()].sort((a, b) => b[1] - a[1])[0];
    const metadata = {
      detectedYear: detectedYear ? detectedYear[0] : Number(options && options.year) || new Date().getFullYear(),
      openingBalance: null,
      closingBalance: null,
    };
    for (const line of lines || []) {
      const lower = line.text.toLowerCase();
      const tokens = extractTrailingMoneyTokens(line.text, options);
      if (!tokens.length) continue;
      if (/opening balance|previous balance|balance forward/.test(lower)) metadata.openingBalance = tokens[tokens.length - 1].value;
      if (/closing balance|ending balance/.test(lower)) metadata.closingBalance = tokens[tokens.length - 1].value;
    }
    return metadata;
  }

  function markDuplicates(rows) {
    const seen = new Set();
    rows.forEach(row => {
      const key = [row.date, normalizeWhitespace(row.description).toLowerCase(), row.debit.toFixed(2), row.credit.toFixed(2), row.balance === null ? '' : row.balance.toFixed(2)].join('|');
      row.duplicate = seen.has(key);
      if (!seen.has(key)) seen.add(key);
    });
  }

  function parseStatement(lines, options) {
    const opts = { year: new Date().getFullYear(), dateOrder: 'auto', layout: 'auto', decimalSeparator: 'auto', ...(options || {}) };
    const metadata = extractMetadata(lines, opts);
    if (!options || !options.year) opts.year = metadata.detectedYear;
    const layout = detectLayout(lines, opts);
    const blocks = buildBlocks(lines);
    const rows = blocks.map(block => parseBlock(block, layout, opts)).filter(Boolean);
    inferBalances(rows, metadata);
    markDuplicates(rows);
    const warnings = [];
    if (!rows.length) warnings.push('No transaction rows were detected. Try a different column layout, date order, or OCR mode.');
    if (rows.some(row => row.confidence < 65)) warnings.push('Some rows have low confidence and require review.');
    if (rows.some(row => row.duplicate)) warnings.push('Possible duplicate transactions were detected.');
    return { rows, layout, metadata, blocksExamined: blocks.length, warnings };
  }

  function transactionKey(row) {
    return [row.date, normalizeWhitespace(row.description).toLowerCase(), Number(row.amount || 0).toFixed(2), row.balance === null ? '' : Number(row.balance).toFixed(2)].join('|');
  }

  return {
    normalizeWhitespace,
    parseMoney,
    parseDateToken,
    extractLeadingDates,
    extractMoneyTokens,
    extractTrailingMoneyTokens,
    groupTextItemsToLines,
    plainTextToLines,
    detectLayout,
    parseStatement,
    markDuplicates,
    categorize,
    simpleHash,
    transactionKey,
  };
});
