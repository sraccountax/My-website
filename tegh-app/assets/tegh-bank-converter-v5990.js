import './tegh-bank-converter-core-v5990.js?v=5990-r62-statement-account-columns';
import {parseStatementLayout, splitStatements, statementAccountIds} from './tegh-statement-layout-r152.js?v=5990-r152-tegh';

const Core = globalThis.BankStatementCore;
export const ENGINE = 'tegh-statement-converter-v5990';
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const cancelled = () => new DOMException('Statement conversion cancelled. No rows imported.', 'AbortError');
export function moneyCents(value) {
  const text = String(value).trim();
  if (!/^-?\d+(?:\.\d{1,2})?$/.test(text)) throw new Error('Enter an amount with no more than two decimal places.');
  const [whole, fraction = ''] = text.replace(/^-/, '').split('.');
  const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
  if (!Number.isSafeInteger(cents) || cents > 100000000000) throw new Error('This amount is outside the supported range.');
  return text.startsWith('-') ? -cents : cents;
}
const displayAmount = cents => `${cents < 0 ? '-' : ''}${Math.floor(Math.abs(cents) / 100)}.${String(Math.abs(cents) % 100).padStart(2, '0')}`;
const validDate = value => /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(`${value}T00:00:00Z`)) && new Date(`${value}T00:00:00Z`).toISOString().slice(0,10) === value;
const DATE_EVIDENCE = /(?:\d{4}[-/.]\d{1,2}[-/.]\d{1,2}|(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember|t)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2}(?:,?\s+\d{4})?|\d{1,2}\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember|t)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)(?:\s+\d{4})?|\d{1,2}[-/.]\d{1,2}(?:[-/.]\d{2,4})?)/gi;
const hasYear = token => /^\d{4}[-/.]/.test(token) || /[-/.]\d{1,2}[-/.]\d{2,4}$/.test(token) || /\b(?:19|20)\d{2}\b/.test(token);
export const displayDate = iso => validDate(iso) ? new Intl.DateTimeFormat('en-CA',{month:'short',day:'numeric',year:'numeric',timeZone:'UTC'}).format(new Date(`${iso}T12:00:00Z`)) : 'Date correction required';
export function statementAccountLastFour(text) {
  const ids=new Set();
  const lines=String(text||'').split(/\r?\n/);
  for(let index=0;index<lines.length;index++){
    // Only an explicit account-number label identifies the statement account.
    // Summary tables can put an account label beside balances on the next line.
    const label=/\bAccount[ \t]*(?:Number|No\.?|#)[ \t]*:?[ \t]*/gi;
    for(const match of lines[index].matchAll(label)){
      let rest=lines[index].slice(match.index+match[0].length).trimStart();
      if(!rest&&index+1<lines.length)rest=lines[index+1].trimStart();
      // A balance printed beside the identifier must never supply extra digits.
      rest=rest.split(/[ \t]+(?=\$?\d{1,3}(?:,\d{3})+(?:\.\d{2})?\b|\$?\d+\.\d{2}\b)/)[0];
      const candidate=rest.match(/^([\dXx*•]+(?:[ \t-]+[\dXx*•]+)*)/)?.[1]||'';
      const digits=candidate.replace(/\D/g,'');
      if(digits.length>=4&&digits.length<=25)ids.add(digits.slice(-4));
    }
  }
  if(ids.size>1)throw new Error('This PDF contains more than one account. Split it into one statement per selected account.');
  return ids.values().next().value||null;
}
export function detectDateEvidence(lines, chosenOrder='auto') {
  const tokens=lines.flatMap(line=>{const lead=Core.extractLeadingDates(line.text,{year:2000,dateOrder:'auto'});return lead?[lead.firstToken]:[]});
  const headers=lines.slice(0,100).filter(line=>/statement\s+(?:period|from|for)|\bperiod\b|\bfrom\b.*\bto\b/i.test(line.text));
  const headerTokens=headers.flatMap(line=>line.text.match(DATE_EVIDENCE)||[]);
  const signs=new Set();for(const token of [...tokens,...headerTokens]){const m=token.match(/^(\d{1,2})[-/.](\d{1,2})(?:[-/.]\d{2,4})?$/);if(m){if(+m[1]>12&&+m[2]<=12)signs.add('dmy');if(+m[2]>12&&+m[1]<=12)signs.add('mdy')}}
  let dateOrder=chosenOrder||'auto';if(dateOrder==='auto'&&signs.size===1)dateOrder=[...signs][0];
  if(signs.size>1&&dateOrder==='auto')throw new Error('Numeric dates use conflicting orders. Select the Date Format and correct any exceptional row.');
  const periods=[];
  for(const line of headers){const ts=line.text.match(DATE_EVIDENCE)||[];if(ts.length!==2)continue;const p=ts.map(t=>Core.parseDateToken(t,{year:2000,dateOrder}));if(p.some(x=>!x.valid||x.ambiguous))continue;
    const years=ts.map((t,i)=>hasYear(t)?Number(p[i].iso.slice(0,4)):null),anchor=years.find(y=>y!==null);if(!anchor)continue;
    const possibilities=years.map(y=>y===null?[anchor-1,anchor,anchor+1]:[y]);const pairs=[];
    for(const y0 of possibilities[0])for(const y1 of possibilities[1]){const start=`${y0}${p[0].iso.slice(4)}`,end=`${y1}${p[1].iso.slice(4)}`,span=(Date.parse(end)-Date.parse(start))/86400000;if(validDate(start)&&validDate(end)&&span>=0&&span<=366)pairs.push({periodStart:start,periodEnd:end,periodEvidence:line.text})}
    if(pairs.length===1)periods.push(pairs[0]);
  }
  // Some monthly statements print only "For the period ending July 31, 2025".
  // Its year applies to yearless rows only when every observed row is in that
  // same named month; otherwise leave the dates for explicit correction.
  for(const line of headers){
    if(!/\bperiod\s+ending\b/i.test(line.text))continue;
    const dated=(line.text.match(DATE_EVIDENCE)||[]).filter(hasYear);
    if(dated.length!==1)continue;
    const end=Core.parseDateToken(dated[0],{year:2000,dateOrder});
    if(!end.valid||end.ambiguous)continue;
    const year=Number(end.iso.slice(0,4)),month=end.iso.slice(5,7);
    const datedRows=tokens.map(token=>Core.parseDateToken(token,{year,dateOrder})).filter(parsed=>parsed.valid);
    if(!datedRows.length||!datedRows.every(parsed=>!parsed.ambiguous&&parsed.iso.slice(5,7)===month&&parsed.iso<=end.iso))continue;
    periods.push({periodStart:`${year}-${month}-01`,periodEnd:end.iso,periodEvidence:line.text});
  }
  // A heading naming one complete calendar month is also explicit period evidence.
  // Do not use an isolated year, download timestamp or an ordinary transaction line.
  for(const line of headers){
    const match=line.text.trim().match(/^(?:statement\s+(?:period|for)|period)\s*:?\s*(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)\s+((?:19|20)\d{2})[. ]*$/i);
    if(!match)continue;
    const start=Core.parseDateToken(`${match[1]} 1 ${match[2]}`,{dateOrder});
    if(!start.valid)continue;
    const month=Number(start.iso.slice(5,7)),year=Number(match[2]);
    const end=new Date(Date.UTC(year,month,0)).toISOString().slice(0,10);
    periods.push({periodStart:start.iso,periodEnd:end,periodEvidence:line.text});
  }
  const unique=new Map(periods.map(p=>[p.periodStart+'|'+p.periodEnd,p]));
  const period=unique.size===1?[...unique.values()][0]:{};
  return {dateOrder,...period,strictDates:true};
}
const signedText = cents => `${cents < 0 ? '-' : ''}$${(Math.abs(cents) / 100).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
/** R145: rows from the column-aware reader, in the review shape. Returns null when that reader found no table. */
export function convertWithLayout(lines, options={}) {
  const layout = parseStatementLayout(lines, {accountType: options.accountType, dateOrder: options.dateOrder});
  if (!layout || !layout.rows.length || layout.undated) return null;
  const balanced = layout.reconciled === true, pagesOk = layout.pageChecks.every(check => check.ok);
  const rows = layout.rows.map((row, index) => {
    const amountCents = Math.round(row.amount * 100), issues = [];
    if (row.balanceMismatch != null) issues.push(`The statement's running balance on this line differs from the rows above it by ${signedText(Math.round(row.balanceMismatch * 100))}. Check for a missing or misread line just above.`);
    return {date: row.date, dateAmbiguous: false, sourceDateToken: row.date, description: row.description || '(no description on statement)', reference: '', amount: amountCents / 100,
      debit: amountCents < 0 ? -amountCents / 100 : 0, credit: amountCents > 0 ? amountCents / 100 : 0, balance: row.balance, category: Core.categorize(row.description, amountCents / 100),
      sourceRow: index + 1, page: row.page, raw: row.raw, originalDate: row.date, originalDateAmbiguous: false, dateCorrected: false, originalDescription: row.description, originalAmountCents: amountCents,
      confidence: balanced && pagesOk && row.balanceMismatch == null ? 98 : 80, issues: issues.join(' '), included: true, duplicate: false,
      extractionMethod: balanced ? 'Read by column · balances agree' : 'Read by column', needsManualAmount: false, userReviewed: false, reviewedCategory: false, reviewedAccountId: null};
  });
  return {rows, layout, balanced, pagesOk};
}
const isoPeriod = period => period?.end ? `${period.start || '…'} to ${period.end}` : '';
/** R152: plain label for one statement inside a PDF that holds several. */
export function statementLabel(statement) {
  const period = statement.period?.end ? `${displayDate(statement.period.start) === 'Date correction required' ? '…' : displayDate(statement.period.start)} – ${displayDate(statement.period.end)}` : `pages ${statement.pages.join(', ')}`;
  return `Statement ${statement.index + 1}: ${period}${statement.account ? ` · account …${statement.account}` : ''} · ${statement.rows} rows${statement.balanced ? ' · balances agree' : ''}`;
}
/** R152: several statements of one account read together; each must start at the previous closing balance. */
function combineSections(parts) {
  const rows = [];
  parts.forEach(part => part.rows.forEach(row => rows.push({...row, sourceRow: rows.length + 1})));
  const first = parts[0], last = parts.at(-1), checks = parts.map(part => part.layoutCheck), c = v => Math.round(v * 100);
  const chain = [];
  for (let i = 1; i < parts.length; i++) {
    const before = parts[i - 1].metadata?.closingBalance, after = parts[i].metadata?.openingBalance;
    chain.push({from: i, to: i + 1, ok: before != null && after != null && c(before) === c(after)});
  }
  const out = {rows, metadata: {openingBalance: first.metadata?.openingBalance ?? null, closingBalance: last.metadata?.closingBalance ?? null}, blocksExamined: rows.length, unparsedBlocks: [],
    warnings: parts.flatMap(part => part.warnings || []), format: first.format, dateEvidence: first.dateEvidence};
  if (checks.every(Boolean)) {
    const chainOk = chain.every(link => link.ok), sum = key => checks.reduce((n, check) => n + (check[key] || 0), 0);
    out.layoutCheck = {...checks[0], balanced: checks.every(check => check.balanced) && chainOk, pagesOk: checks.every(check => check.pagesOk), reconciled: checks.every(check => check.reconciled) && chainOk,
      openingBalance: out.metadata.openingBalance, closingBalance: out.metadata.closingBalance, closingFromLastRow: last.layoutCheck.closingFromLastRow, netCents: sum('netCents'), balanceChecks: sum('balanceChecks'),
      balanceMismatches: sum('balanceMismatches'), pageChecks: checks.flatMap(check => check.pageChecks || []), notes: checks.flatMap(check => check.notes || []), statementCount: parts.length, chain};
  }
  return out;
}
/**
 * Read the statement pages. R152: a PDF holding several statements (more months, or more accounts) is split; the rows
 * returned are the chosen statement's (options.section: 'all', 'acct:1234' or a statement number from 0), and
 * parsed.statements lists every statement found so the review screen can offer the others.
 */
export function convertPages(pages, options={}) {
  const lines = pages.flatMap(page => page.method === 'Text' ? Core.groupTextItemsToLines(page.items,page.page) : Core.plainTextToLines(page.text,page.page));
  const sections = splitStatements(lines);
  if (sections.length < 2) return convertSection(lines, options);
  const read = sections.map(section => { try { return convertSection(section.lines, options); } catch (error) { return {rows: [], metadata: {}, error: error.message}; } });
  const statements = sections.map((section, index) => ({index, account: section.account, period: section.period ? {start: section.period.start, end: section.period.end} : null, pages: section.pages,
    rows: read[index].rows.length, balanced: !!read[index].layoutCheck?.balanced, error: read[index].error || null}));
  const accounts = [...new Set(statements.map(statement => statement.account))];
  let pick = String(options.section || 'auto');
  if (pick === 'auto') pick = options.accountLastFour && accounts.includes(options.accountLastFour) ? `acct:${options.accountLastFour}` : accounts.length === 1 ? 'all' : '0';
  const chosen = pick === 'all' ? statements : pick.startsWith('acct:') ? statements.filter(statement => statement.account === pick.slice(5)) : statements.filter(statement => String(statement.index) === pick);
  if (!chosen.length) throw new Error('Choose one of the statements found in this PDF.');
  const usable = chosen.filter(statement => read[statement.index].rows.length);
  if (!usable.length) throw new Error(read[chosen[0].index].error || 'No transactions were detected in the chosen statement.');
  const parsed = usable.length === 1 ? read[usable[0].index] : combineSections(usable.map(statement => read[statement.index]));
  const chosenAccounts = [...new Set(usable.map(statement => statement.account).filter(Boolean))];
  parsed.statements = statements; parsed.section = pick; parsed.accountLastFour = chosenAccounts.length === 1 ? chosenAccounts[0] : null;
  if (parsed.dateEvidence && usable.length > 1) parsed.dateEvidence = {...parsed.dateEvidence, periodEvidence: `Statements ${usable.map(statement => statement.index + 1).join(', ')}: ${usable.map(statement => isoPeriod(statement.period) || 'row dates').join('; ')}`};
  return parsed;
}
function convertSection(lines, options={}) {
  const columnRead = !options.layout || options.layout === 'auto' ? convertWithLayout(lines, options) : null;
  if (columnRead?.balanced || (columnRead && options.preferColumns)) return layoutParsed(columnRead, options.accountType);
  const evidence=detectDateEvidence(lines,options.dateOrder||'auto');
  const parsed=Core.parseStatement(lines,{...options,...evidence,year:null});
  if(parsed.blocksExamined>5000)throw new Error('Convert no more than 5,000 dated rows at a time.');
  parsed.dateEvidence=evidence;
  const unresolved=new Map(parsed.unparsedBlocks.map(block=>[block.index,block]));
  let parsedIndex=0;
  parsed.rows=Array.from({length:parsed.blocksExamined},(_,index)=>{
    const block=unresolved.get(index),row=block?null:parsed.rows[parsedIndex++];
    if(row)return {...row,date:row.dateAmbiguous?'':row.date,sourceRow:index+1,originalDate:row.dateAmbiguous?null:row.date,originalDateAmbiguous:!!row.dateAmbiguous,dateCorrected:false,originalAmountCents:row.needsManualAmount?null:moneyCents(row.amount.toFixed(2)),originalDescription:row.description,userReviewed:false,reviewedCategory:false,reviewedAccountId:null};
    const leading=Core.extractLeadingDates(block.firstLine,{...evidence,dateOrder:options.dateOrder||evidence.dateOrder,year:null}),sourceDate=leading?.date?.valid?leading.date.iso:'',description=String(leading?.remainder||'').trim();
    return {date:sourceDate&&!leading.date.ambiguous?sourceDate:'',dateAmbiguous:!sourceDate||!!leading?.date?.ambiguous,sourceDateToken:leading?.firstToken||'',description,reference:'',amount:0,debit:0,credit:0,balance:null,category:'Needs review',sourceRow:index+1,page:block.page,raw:block.raw,originalDate:sourceDate||null,originalDateAmbiguous:!sourceDate||!!leading?.date?.ambiguous,dateCorrected:false,originalDescription:description,originalAmountCents:null,confidence:0,issues:'This dated line was not read as a transaction. Enter the signed amount and check its description and date, or exclude it if it is a statement note.',included:true,duplicate:false,extractionMethod:'Needs correction',needsManualAmount:true,userReviewed:false,reviewedCategory:false,reviewedAccountId:null};
  });
  // The column read did not balance; still use it when the general reader left more rows needing manual amounts or found fewer rows.
  if (columnRead) {
    const coreGood = parsed.rows.filter(row => !row.needsManualAmount && row.date).length;
    if (parsed.rows.some(row => row.needsManualAmount) || columnRead.rows.length >= coreGood) return layoutParsed(columnRead, options.accountType);
  }
  return parsed;
}
function layoutParsed({rows, layout, balanced, pagesOk}, accountType = '') {
  const period = layout.period;
  return {rows, metadata: {openingBalance: layout.openingBalance, closingBalance: layout.closingBalance}, blocksExamined: rows.length, unparsedBlocks: [], warnings: [], format: 'column-layout-r145',
    dateEvidence: {dateOrder: 'mdy', strictDates: true, periodEvidence: period ? `Statement period ${period.start || '…'} to ${period.end}` : 'Row dates'},
    layoutCheck: {accountType, balanced, pagesOk, reconciled: layout.reconciled, isCard: layout.isCard, openingBalance: layout.openingBalance, closingBalance: layout.closingBalance, closingFromLastRow: layout.closingFromLastRow,
      netCents: layout.netCents, balanceChecks: layout.balanceChecks, balanceMismatches: layout.balanceMismatches, pageChecks: layout.pageChecks, columns: layout.columns, notes: layout.notes}};
}
/** Plain-language summary of the statement's own checks, for the review screen. */
export function balanceCheckText(check) {
  if (!check) return '';
  const c = v => Math.round(v * 100), parts = [];
  if (check.openingBalance != null && check.closingBalance != null) {
    const show = v => signedText(check.isCard ? -c(v) : c(v)), from = check.isCard ? `Balance owed ${show(check.openingBalance)}` : `Opening balance ${show(check.openingBalance)}`;
    const to = check.isCard ? `the new balance owed of ${show(check.closingBalance)}` : `the closing balance of ${show(check.closingBalance)}`;
    parts.push(check.reconciled ? `${from}, with these rows, comes to ${to} printed on the statement ✓` : `These rows do not add up: ${from}, with these rows, comes to ${show(check.isCard ? check.openingBalance + check.netCents / 100 : check.openingBalance + check.netCents / 100)}, not ${to}. Check for a missing or misread line.`);
  } else parts.push('The statement shows no opening and closing balance to check against. Compare the totals with the statement.');
  if (check.balanceChecks) parts.push(check.balanceMismatches ? `${check.balanceMismatches} of ${check.balanceChecks} running balances differ (marked on the rows).` : `All ${check.balanceChecks} running balances agree.`);
  if (check.isCard && check.accountType === 'bank') parts.unshift('This looks like a credit card statement, but a bank account is selected. Choose the credit card account if that is the right one.');
  if (check.statementCount > 1) {
    const broken = (check.chain || []).filter(link => !link.ok);
    parts.unshift(`${check.statementCount} statements from this PDF are read together.`);
    parts.push(broken.length ? `Statement ${broken.map(link => link.to).join(', ')} does not start at the closing balance of the statement before it. Check for a missing statement.` : 'Each statement starts at the closing balance of the one before it ✓');
  }
  if (check.pageChecks?.length) { const bad = check.pageChecks.filter(p => !p.ok).length; parts.push(bad ? `${bad} of ${check.pageChecks.length} page totals differ.` : `All ${check.pageChecks.length} page totals agree.`); }
  return parts.join(' ');
}
export function updateReviewedRow(row, field, value) {
  // Review is a distinct state. Editing never changes the extraction heuristic
  // or silently replaces the user's chosen destination with a merchant guess.
  if (field === 'amount') { row.amount = moneyCents(value) / 100; row.debit = row.amount < 0 ? -row.amount : 0; row.credit = row.amount > 0 ? row.amount : 0; if(row.amount)row.needsManualAmount=false; }
  else if (field === 'category') {row.reviewedAccountId = value || null; row.reviewedCategory = true;}
  else if (field === 'included') row.included = !!value;
  else if (['date','description','reference'].includes(field)) {row[field]=String(value);if(field==='date')row.dateCorrected=true;}
  row.userReviewed = true;
  return row;
}
export function reviewedExtraction(parsed, options, pageCount) {
  if (!parsed.rows.length || parsed.rows.length > 5000) throw new Error('Convert between 1 and 5,000 rows.');
  let included = 0;
  const rows = parsed.rows.filter(row=>row.included).map(row => {
    if (!validDate(row.date)) throw new Error(`Correct the date in row ${row.sourceRow}; no year or numeric date order will be guessed.`);
    if(row.date>new Date().toLocaleDateString('en-CA',{timeZone:'America/Toronto'}))throw new Error(`Row ${row.sourceRow} has a future date.`);
    if(row.originalDateAmbiguous&&!row.dateCorrected)throw new Error(`Confirm the corrected date in row ${row.sourceRow}.`);
    const amountCents = moneyCents(Number(row.amount).toFixed(2));
    if(row.needsManualAmount)throw new Error(`Enter and verify the signed amount in row ${row.sourceRow}, or exclude that statement note.`);
    if (!amountCents || !row.description.trim()) throw new Error(`Row ${row.sourceRow} needs a description and a non-zero amount.`);
    if (Array.from(row.description.trim()).length > 4000) throw new Error(`Description in row ${row.sourceRow} is longer than 4,000 characters. Check for text from more than one transaction.`);
    if (Array.from(String(row.originalDescription||'')).length > 4000 || Array.from(String(row.raw||'')).length > 8000) throw new Error(`Original text in row ${row.sourceRow} is too long. Check the statement layout or use the bank’s CSV or XLSX file.`);
    included++;
    return {date:row.date,description:row.description.trim(),reference:row.reference || '',amountCents,
      sourcePage:row.page,sourceRow:row.sourceRow,sourceText:row.raw,originalDate:row.originalDate,
      originalDescription:row.originalDescription,originalAmountCents:row.originalAmountCents,originalDateAmbiguous:!!row.originalDateAmbiguous,dateCorrected:!!row.dateCorrected,sourceDateToken:row.sourceDateToken||'',
      sourceBalanceCents:row.balance == null ? null : moneyCents(Number(row.balance).toFixed(2)),
      categoryHint:row.category,extractionMethod:row.extractionMethod,extractionScore:row.confidence,
      extractionIssues:row.issues || '',userReviewed:true,userExcluded:false,
      reviewedCategory:row.reviewedCategory,reviewedAccountId:row.reviewedAccountId};
  });
  if (!included) throw new Error('Select at least one transaction.');
  const dates=rows.map(row=>row.date).sort();if((Date.parse(dates.at(-1))-Date.parse(dates[0]))/86400000>366)throw new Error('The validated rows span more than 366 days. Split the statement.');
  return {engine:ENGINE,format:'reviewed-pdf',pageCount,accountLastFour:null,excludedRows:parsed.rows.length-included,
    firstTransactionDate:dates[0],lastTransactionDate:dates.at(-1),dateOrder:parsed.dateEvidence?.dateOrder||options.dateOrder||'auto',dateEvidence:parsed.dateEvidence?.periodEvidence||'Explicit row dates / user corrections',
    reviewConfirmed:true,openingBalanceCents:parsed.metadata.openingBalance == null ? null : moneyCents(parsed.metadata.openingBalance.toFixed(2)),
    closingBalanceCents:parsed.metadata.closingBalance == null ? null : moneyCents(parsed.metadata.closingBalance.toFixed(2)),rows};
}
function installStyles() {
  if(document.querySelector('[data-tegh-converter-styles="5990"]'))return;
  const link=document.createElement('link');link.rel='stylesheet';link.href='/assets/tegh-bank-converter-v5990.css?v=5990-r145-tegh';link.dataset.teghConverterStyles='5990';document.head.append(link);
}
export async function extractAndReview(file,{bank,accounts=[],onProgress=()=>{},isCurrent=()=>true}={}) {
  installStyles();if(!Core?.parseStatement)throw new Error('The statement converter did not load. Refresh and try again.');
  const abort=new AbortController(),previousFocus=document.activeElement,modal=document.createElement('div');
  modal.className='tegh-converter-scrim';modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.setAttribute('aria-labelledby','tegh-converter-title');
  modal.innerHTML=`<section class="tegh-converter-panel"><header><div><h2 id="tegh-converter-title">Review your PDF statement</h2><p>${esc(file.name)} · ${esc(bank?.name||'Selected account')} · ${esc(bank?.currency||'CAD')}</p></div><button type="button" data-converter-cancel aria-label="Cancel statement conversion">×</button></header><p role="status" aria-live="polite" data-converter-status>Preparing local PDF reader…</p><form data-converter-options hidden><div class="tegh-converter-options"><label>Date Format<select name="dateOrder"><option value="auto">Use unambiguous statement evidence</option><option value="mdy">Month / day / year</option><option value="dmy">Day / month / year</option></select></label><label>Statement columns<select name="layout"><option value="auto">Detect columns</option><option value="amount">Signed amount</option><option value="amount-balance">Amount and balance</option><option value="debit-credit">Money out and money in</option><option value="debit-credit-balance">Money out, money in and balance</option></select></label><label data-converter-section-label hidden>Statement in this PDF<select name="section"><option value="auto">Detect</option></select></label><button type="submit">Read transactions</button></div></form><div data-converter-review></div><p data-converter-error role="alert"></p><footer><label class="tegh-converter-confirm"><input type="checkbox" data-reviewed-confirm disabled> I checked the selected rows against the statement, including dates and money-in/out direction.</label><div><button type="button" data-converter-cancel>Cancel</button><button type="button" data-converter-continue disabled>Validate reviewed rows</button></div></footer></section>`;
  document.body.append(modal);previousFocus?.blur();
  const panel=modal.querySelector('.tegh-converter-panel'),status=modal.querySelector('[data-converter-status]'),errorNode=modal.querySelector('[data-converter-error]'),review=modal.querySelector('[data-converter-review]'),form=modal.querySelector('form'),next=modal.querySelector('[data-converter-continue]'),confirmReview=modal.querySelector('[data-reviewed-confirm]');
  let settled=false,pageData=null,parsed=null,options={},specialised=null,observer=null,filtered=[],mounted=0,query='';const invalidFields=new Set();let resolveDone,rejectDone;
  const done=new Promise((resolve,reject)=>{resolveDone=resolve;rejectDone=reject});done.catch(()=>{});
  const finish=(result,error)=>{if(settled)return;settled=true;abort.abort();clearInterval(contextWatch);observer?.disconnect();resizeObserver?.disconnect();modal.remove();document.removeEventListener('keydown',keyboard,true);if(previousFocus?.isConnected)previousFocus.focus();error?rejectDone(error):resolveDone(result)};
  const cancel=()=>finish(null,cancelled()),contextWatch=setInterval(()=>{if(!isCurrent())cancel()},300);
  modal.querySelectorAll('[data-converter-cancel]').forEach(button=>button.onclick=cancel);
  const measure=()=>panel.style.setProperty('--converter-header-height',`${Math.ceil(panel.querySelector('header').getBoundingClientRect().height)}px`),resizeObserver=new ResizeObserver(measure);resizeObserver.observe(panel.querySelector('header'));measure();
  function keyboard(event){if(event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();cancel();return}if(event.key==='Tab'){const nodes=[...modal.querySelectorAll('button:not(:disabled),input:not(:disabled),select,textarea,a[href],summary')].filter(node=>node.getClientRects().length&&!node.closest('[hidden]'));if(!nodes.length)return;const first=nodes[0],last=nodes.at(-1);if(event.shiftKey&&(document.activeElement===first||!modal.contains(document.activeElement))){event.preventDefault();last.focus()}else if(!event.shiftKey&&(document.activeElement===last||!modal.contains(document.activeElement))){event.preventDefault();first.focus()}}}
  document.addEventListener('keydown',keyboard,true);modal.querySelector('button').focus();
  const categoryOptions=selected=>`<option value="">Choose later in Bank Review</option>${accounts.map(account=>{const unavailable=account.active===false||account.isControl||account.systemControl||account.linkedBankId||String(account.id)===String(bank?.ledgerAccountId)||String(account.code)==='9999';return `<option value="${esc(account.id)}" ${String(account.id)===String(selected)?'selected':''} ${unavailable?'disabled':''}>${esc(account.code)} · ${esc(account.name)}${unavailable?' — unavailable':''}</option>`}).join('')}`;
  const invalidate=()=>{confirmReview.checked=false;next.disabled=true;confirmReview.disabled=!parsed?.rows.length};
  function totals(){if(!parsed)return;const selected=parsed.rows.filter(row=>row.included),out=selected.reduce((n,row)=>n+Math.max(0,-moneyCents(row.amount.toFixed(2))),0),income=selected.reduce((n,row)=>n+Math.max(0,moneyCents(row.amount.toFixed(2))),0);const summary=review.querySelector('[data-converter-totals]');if(summary)summary.textContent=`${selected.length} selected / ${parsed.rows.length} rows · Money out ${displayAmount(out)} · Money in ${displayAmount(income)} ${bank?.currency||'CAD'} · ${filtered.length} search results`}
  function rowMarkup(row){return `<tr data-converter-row="${row.sourceRow}"><td data-label="Include"><input aria-label="Include row ${row.sourceRow}" type="checkbox" data-field="included" ${row.included?'checked':''}></td><td data-label="Date"><span data-date-display>${esc(displayDate(row.date))}</span><details ${!row.date?'open':''}><summary>${row.date?'Correct date':'Date required'}</summary><input aria-label="Correct date in row ${row.sourceRow}" type="date" data-field="date" value="${esc(row.date)}"><small>Statement text: ${esc(row.sourceDateToken||row.originalDate||'See source')}</small></details><small>Page ${row.page} · Row ${row.sourceRow}</small></td><td data-label="Description / reference"><textarea rows="2" aria-label="Description row ${row.sourceRow}" data-field="description" maxlength="4000">${esc(row.description)}</textarea><input aria-label="Reference row ${row.sourceRow}" data-field="reference" maxlength="160" value="${esc(row.reference||'')}" placeholder="Reference"><details><summary>Original statement text</summary><p>${esc(row.raw)}</p></details></td><td data-label="Signed amount"><input aria-label="Signed amount row ${row.sourceRow}" data-field="amount" type="text" inputmode="decimal" value="${esc(displayAmount(moneyCents(row.amount.toFixed(2))))}"><small>${row.amount<0?'Money out':'Money in'}</small></td><td class="tegh-converter-money" data-label="Source balance">${row.balance===null?'Not supplied':esc(displayAmount(moneyCents(row.balance.toFixed(2))))}</td><td data-label="Category"><select aria-label="Category row ${row.sourceRow}" data-field="category">${categoryOptions(row.reviewedAccountId)}</select><small>${esc(row.category||'')}</small></td><td data-label="Validation"><strong data-row-check>${row.userReviewed?'Edited':esc(row.extractionMethod)}</strong><p>${esc(row.issues||'Compare with statement.')}${row.duplicate?' Possible duplicate.':''}</p></td></tr>`}
  function appendRows(){if(!parsed||mounted>=filtered.length)return;const subset=filtered.slice(mounted,mounted+100);review.querySelector('tbody').insertAdjacentHTML('beforeend',subset.map(rowMarkup).join(''));mounted+=subset.length;const sentinel=review.querySelector('[data-converter-more]');if(sentinel){sentinel.hidden=mounted>=filtered.length;sentinel.textContent=mounted<filtered.length?`Showing ${mounted} of ${filtered.length} matches. More rows load as you scroll.`:''}}
  function renderRows(){observer?.disconnect();filtered=parsed.rows.filter(row=>!query||[row.date,displayDate(row.date),row.description,row.reference,row.amount.toFixed(2),row.category,row.raw].join(' ').toLocaleLowerCase().includes(query));mounted=0;
    const lc=parsed.layoutCheck;review.innerHTML=`${lc?`<p class="tegh-converter-check" data-converter-balance-check data-state="${lc.balanced&&lc.pagesOk&&!lc.balanceMismatches?'ok':'warn'}">${esc(balanceCheckText(lc))}</p>`:''}<div class="tegh-converter-selection"><strong data-converter-totals role="status" aria-live="polite"></strong><div><button type="button" data-converter-all>Select all</button><button type="button" data-converter-filtered>Select search results</button><button type="button" data-converter-clear>Clear selection</button></div><label>Search every transaction<input type="search" maxlength="200" data-converter-search value="${esc(query)}" placeholder="Description, date, reference or amount"></label></div><div class="tegh-converter-scroll"><table><colgroup><col style="width:4%"><col style="width:15%"><col style="width:30%"><col style="width:12%"><col style="width:11%"><col style="width:18%"><col style="width:10%"></colgroup><thead><tr><th>Include</th><th>Date / Source</th><th>Description / Reference</th><th>Amount (${esc(bank?.currency||'CAD')})</th><th>Source Balance</th><th>Category</th><th>Check</th></tr></thead><tbody></tbody></table><p data-converter-more role="status"></p></div>`;
    appendRows();totals();const sentinel=review.querySelector('[data-converter-more]');observer=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting)){appendRows()}},{root:panel,rootMargin:'500px'});observer.observe(sentinel);
    const setSelected=(rows,value)=>{rows.forEach(row=>row.included=value);invalidate();renderRows()};review.querySelector('[data-converter-all]').onclick=()=>setSelected(parsed.rows,true);review.querySelector('[data-converter-filtered]').onclick=()=>setSelected(filtered,true);review.querySelector('[data-converter-clear]').onclick=()=>setSelected(parsed.rows,false);
    let debounce;review.querySelector('[data-converter-search]').oninput=event=>{const input=event.target,value=input.value;clearTimeout(debounce);debounce=setTimeout(()=>{if(settled)return;query=value.toLocaleLowerCase();renderRows();const restored=review.querySelector('[data-converter-search]');restored.focus();restored.setSelectionRange?.(value.length,value.length)},160)};
  }
  review.addEventListener('change',event=>{const input=event.target.closest('[data-field]');if(!input||!parsed)return;const tr=input.closest('[data-converter-row]'),row=parsed.rows[Number(tr.dataset.converterRow)-1];try{updateReviewedRow(row,input.dataset.field,input.type==='checkbox'?input.checked:input.value);if(input.dataset.field==='date'&&!validDate(row.date))throw Error(`Row ${row.sourceRow} needs a valid date.`);invalidFields.delete(`${row.sourceRow}:${input.dataset.field}`);input.removeAttribute('aria-invalid');tr.querySelector('[data-row-check]').textContent='Edited';tr.querySelector('[data-date-display]').textContent=displayDate(row.date);errorNode.textContent='';totals()}catch(error){invalidFields.add(`${row.sourceRow}:${input.dataset.field}`);input.setAttribute('aria-invalid','true');errorNode.textContent=error.message}invalidate()});
  const selectedLastFour=()=>{const digits=String(bank?.maskedNumber||'').replace(/\D/g,'').slice(-4);return digits.length===4?digits:''};
  // R152: when the PDF holds several statements, list them so the user can choose which to read (or all of one account).
  function sectionChoices(){const label=form.querySelector('[data-converter-section-label]'),select=form.elements.section;if(!parsed?.statements?.length){label.hidden=true;return}
    const accounts=[...new Set(parsed.statements.map(statement=>statement.account))],choices=[];
    if(accounts.length===1)choices.push(['all',`All ${parsed.statements.length} statements${accounts[0]?` for account …${accounts[0]}`:''}, read together`]);
    else for(const account of accounts.filter(Boolean)){const count=parsed.statements.filter(statement=>statement.account===account).length;if(count>1)choices.push([`acct:${account}`,`All ${count} statements for account …${account}, read together`])}
    for(const statement of parsed.statements)choices.push([String(statement.index),statementLabel(statement)]);
    select.innerHTML=choices.map(([value,text])=>`<option value="${esc(value)}">${esc(text)}</option>`).join('');select.value=parsed.section;if(select.value!==parsed.section&&parsed.statements.length)select.value=choices[0][0];label.hidden=false;
    status.dataset.statements=String(parsed.statements.length)}
  const hasIncludedInvalid=()=>[...invalidFields].some(key=>parsed?.rows[Number(key.split(':')[0])-1]?.included);
  confirmReview.onchange=()=>{next.disabled=!confirmReview.checked||!parsed?.rows.some(row=>row.included)||hasIncludedInvalid()};
  function readRows(){try{options=Object.fromEntries(new FormData(form));parsed=convertPages(specialised?[]:pageData.pages,{...options,accountType:bank?.accountType,accountLastFour:selectedLastFour()||null});if(specialised){parsed.rows=specialised.rows.map((row,index)=>({date:row.date,description:row.description,reference:row.reference||'',amount:row.amountCents/100,balance:null,category:Core.categorize(row.description,row.amountCents/100),sourceRow:index+1,page:row.page||1,raw:`${row.date} ${row.description} ${displayAmount(row.amountCents)}`,originalDate:row.date,sourceDateToken:row.date,originalDateAmbiguous:false,dateCorrected:false,originalDescription:row.description,originalAmountCents:row.amountCents,confidence:0,issues:'Statement-specific totals verified; review category and direction.',included:true,duplicate:false,extractionMethod:'Text',userReviewed:false,reviewedCategory:false,reviewedAccountId:null}));parsed.metadata={openingBalance:null,closingBalance:null}}
      sectionChoices();
      if(!parsed.rows.length)throw Error('No transactions were detected. Check the column layout, or use your bank’s CSV or XLSX download.');invalidFields.clear();query='';invalidate();renderRows();const ambiguous=parsed.rows.filter(row=>!row.date).length,unresolved=parsed.rows.filter(row=>row.needsManualAmount).length;status.textContent=`${parsed.statements?.length?`This PDF holds ${parsed.statements.length} statements; choose which to read under Statement in this PDF. `:''}${parsed.rows.length} dated rows · Nothing imported or posted.${unresolved?` ${unresolved} need an amount correction or exclusion.`:''}${ambiguous?` ${ambiguous} dates need a Date Format choice or individual correction.`:''}`;errorNode.textContent='';
    }catch(error){parsed=null;review.innerHTML='';invalidate();errorNode.textContent=error.message}}
  form.onsubmit=event=>{event.preventDefault();if(form.reportValidity())readRows()};form.onchange=()=>{parsed=null;review.innerHTML='';invalidate();status.textContent='Date/layout settings changed. Read transactions again before confirming.'};
  next.onclick=()=>{try{if(!isCurrent())throw cancelled();if(!confirmReview.checked)throw Error('Confirm the statement review before continuing.');if(hasIncludedInvalid())throw Error('Correct invalid selected row fields before continuing.');const result=reviewedExtraction(parsed,options,pageData.pageCount);result.accountLastFour=parsed.accountLastFour||pageData.accountLastFour||null;const selected=selectedLastFour();if(result.accountLastFour&&selected&&result.accountLastFour!==selected)throw Error('The chosen statement is for account …'+result.accountLastFour+', not the selected account. Choose the matching statement.');finish(result)}catch(error){errorNode.textContent=error.message}};
  void(async()=>{try{
    if(globalThis.TeghLoadFeature)await globalThis.TeghLoadFeature('ocr');else await import('./tegh-native-ocr-v5220.js?v=5990-r145-tegh');if(settled)return;
    pageData=await globalThis.TeghNativeOCR.extractStatementPages(file,{signal:abort.signal,onPassword:retry=>window.TeghPrompt?window.TeghPrompt(retry?'That PDF password did not work. Try again:':'Enter this PDF’s password. It stays in this browser.'):window.prompt(retry?'That PDF password did not work. Try again:':'Enter this PDF’s password. It stays in this browser.'),onProgress:progress=>{if(settled)return;status.textContent=progress.detail||'Reading statement…';onProgress(progress.detail||'Reading statement…',8+Math.round(progress.progress*70))}});if(settled)return;
    const text=pageData.pages.map(page=>page.text).join('\n');const ids=statementAccountIds(text);pageData.accountIds=ids;pageData.accountLastFour=ids.length===1?ids[0]:null;const selected=selectedLastFour();if(pageData.accountLastFour&&selected&&pageData.accountLastFour!==selected)throw Error('The statement account number does not match the selected account.');
    if((/Business Account/i.test(text)&&/Withdrawals\/Debits/i.test(text)&&/Deposits\/Credits/i.test(text))||/ScotiaLine\s*for business/i.test(text)){const legacy=await import('./pdfStatementImport-BTobSMtl-v211.js?v=4600');specialised=await legacy.extractPdfStatement(file,onProgress);if(!specialised.summaryVerified)throw Error('Statement-specific totals could not be verified. Use the bank’s CSV or XLSX download.');if(specialised.accountType&&specialised.accountType!==bank?.accountType)throw Error('This statement does not match the selected bank or credit-account type.');pageData.accountLastFour=specialised.accountLastFour||pageData.accountLastFour}
    if(settled)return;form.hidden=false;readRows();
  }catch(error){if(settled)return;errorNode.textContent=error.name==='AbortError'?'Conversion cancelled.':error.message;status.textContent='No transactions were imported. Close and try the file again.'}})();
  return done;
}
