import './tegh-bank-converter-core-v5930.js';

const Core = globalThis.BankStatementCore;
export const ENGINE = 'tegh-statement-converter-v5930';
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
export function convertPages(pages, options) {
  if (!validDate(options.periodStart) || !validDate(options.periodEnd) || options.periodStart > options.periodEnd)
    throw new Error('Choose a valid statement start and end date.');
  if ((Date.parse(options.periodEnd) - Date.parse(options.periodStart)) / 86400000 > 366)
    throw new Error('Convert no more than one year of activity at a time.');
  if (!['mdy', 'dmy'].includes(options.dateOrder)) throw new Error('Confirm the numeric date order.');
  const lines = pages.flatMap(page => page.method === 'Text'
    ? Core.groupTextItemsToLines(page.items, page.page)
    : Core.plainTextToLines(page.text, page.page));
  const parsed = Core.parseStatement(lines, {...options, year:Number(options.periodStart.slice(0,4))});
  if (parsed.blocksExamined !== parsed.rows.length) throw new Error('Some dated statement lines could not be read safely. Use the bank’s CSV/OFX download or correct the PDF source before importing.');
  parsed.rows = parsed.rows.map((row, index) => ({...row,sourceRow:index+1,originalDate:row.date,originalAmountCents:moneyCents(row.amount.toFixed(2)),originalDescription:row.description,userReviewed:false,reviewedCategory:false,reviewedAccountId:null}));
  return parsed;
}
export function updateReviewedRow(row, field, value) {
  // Review is a distinct state. Editing never changes the extraction heuristic
  // or silently replaces the user's chosen destination with a merchant guess.
  if (field === 'amount') { row.amount = moneyCents(value) / 100; row.debit = row.amount < 0 ? -row.amount : 0; row.credit = row.amount > 0 ? row.amount : 0; }
  else if (field === 'category') {row.reviewedAccountId = value || null; row.reviewedCategory = true;}
  else if (field === 'included') row.included = !!value;
  else if (['date','description','reference'].includes(field)) row[field] = String(value);
  row.userReviewed = true;
  return row;
}
export function reviewedExtraction(parsed, options, pageCount) {
  if (!parsed.rows.length || parsed.rows.length > 5000) throw new Error('Convert between 1 and 5,000 rows.');
  let included = 0;
  const rows = parsed.rows.map(row => {
    if (!validDate(row.date) || row.date < options.periodStart || row.date > options.periodEnd)
      throw new Error(`Check the date in row ${row.sourceRow}. It must be inside the confirmed statement period.`);
    const amountCents = moneyCents(Number(row.amount).toFixed(2));
    if (!amountCents || !row.description.trim()) throw new Error(`Row ${row.sourceRow} needs a description and a non-zero amount.`);
    if (Array.from(row.description.trim()).length > 4000) throw new Error(`Description in row ${row.sourceRow} is longer than 4,000 characters. Check for text from more than one transaction.`);
    if (Array.from(String(row.originalDescription||'')).length > 4000 || Array.from(String(row.raw||'')).length > 8000) throw new Error(`Original text in row ${row.sourceRow} is too long. Check the statement layout or use the bank’s CSV/OFX file.`);
    if (row.included) included++;
    return {date:row.date,description:row.description.trim(),reference:row.reference || '',amountCents,
      sourcePage:row.page,sourceRow:row.sourceRow,sourceText:row.raw,originalDate:row.originalDate,
      originalDescription:row.originalDescription,originalAmountCents:row.originalAmountCents,
      sourceBalanceCents:row.balance == null ? null : moneyCents(Number(row.balance).toFixed(2)),
      categoryHint:row.category,extractionMethod:row.extractionMethod,extractionScore:row.confidence,
      extractionIssues:row.issues || '',userReviewed:true,userExcluded:!row.included,
      reviewedCategory:row.reviewedCategory,reviewedAccountId:row.reviewedAccountId};
  });
  if (!included) throw new Error('Select at least one transaction.');
  return {engine:ENGINE,format:'reviewed-pdf',pageCount,accountLastFour:null,excludedRows:0,
    periodStart:options.periodStart,periodEnd:options.periodEnd,dateOrder:options.dateOrder,
    reviewConfirmed:true,openingBalanceCents:parsed.metadata.openingBalance == null ? null : moneyCents(parsed.metadata.openingBalance.toFixed(2)),
    closingBalanceCents:parsed.metadata.closingBalance == null ? null : moneyCents(parsed.metadata.closingBalance.toFixed(2)),rows};
}
function installStyles() {
  if (document.querySelector('[data-tegh-converter-styles]')) return;
  const link = document.createElement('link'); link.rel = 'stylesheet'; link.href = '/assets/tegh-bank-converter-v5930.css'; link.dataset.teghConverterStyles = ''; document.head.append(link);
}
export async function extractAndReview(file, {bank, accounts = [], onProgress = () => {}, isCurrent = () => true} = {}) {
  installStyles();
  if (!Core?.parseStatement) throw new Error('The statement converter did not load. Refresh and try again.');
  const abort = new AbortController(), previousFocus = document.activeElement;
  const modal = document.createElement('div'); modal.className = 'tegh-converter-scrim'; modal.setAttribute('role','dialog'); modal.setAttribute('aria-modal','true'); modal.setAttribute('aria-labelledby','tegh-converter-title');
  modal.innerHTML = `<section class="tegh-converter-panel"><header><div><h2 id="tegh-converter-title">Review your PDF statement</h2><p>${esc(file.name)} · ${esc(bank?.name || 'Selected account')} · ${esc(bank?.currency || 'CAD')}</p></div><button type="button" data-converter-cancel aria-label="Cancel statement conversion">×</button></header><p>Reading stays in this browser. Check the dates, amounts and category suggestions before Tegh validates your selection.</p><p role="status" data-converter-status>Preparing local PDF reader…</p><form data-converter-options hidden><div class="tegh-converter-options"><label>Statement starts<input type="date" name="periodStart" required></label><label>Statement ends<input type="date" name="periodEnd" required></label><label>Numeric dates<select name="dateOrder" required><option value="">Choose month/day order</option><option value="mdy">Month / day / year</option><option value="dmy">Day / month / year</option></select></label><label>Statement columns<select name="layout"><option value="auto">Detect columns</option><option value="amount">Signed amount</option><option value="amount-balance">Amount and balance</option><option value="debit-credit">Money out and money in</option><option value="debit-credit-balance">Money out, money in and balance</option></select></label><button type="submit">Read transactions</button></div></form><div data-converter-review></div><p data-converter-error role="alert"></p><footer><button type="button" data-converter-cancel>Cancel</button><button type="button" data-converter-continue disabled>Validate reviewed rows</button></footer></section>`;
  document.body.append(modal); previousFocus?.blur();
  const status = modal.querySelector('[data-converter-status]'), errorNode = modal.querySelector('[data-converter-error]'), review = modal.querySelector('[data-converter-review]'), form = modal.querySelector('form'), next = modal.querySelector('[data-converter-continue]');
  let settled = false, pageData = null, parsed = null, options = null, page = 0, specialised = null;
  const invalidFields = new Set();
  let resolveDone, rejectDone;
  const done = new Promise((resolve,reject) => {resolveDone=resolve;rejectDone=reject;});
  // Attach immediately because the user can cancel while a runtime is loading.
  done.catch(() => {});
  const finish = (result, error) => {if(settled)return;settled=true;abort.abort();clearInterval(contextWatch);modal.remove();document.removeEventListener('keydown',keyboard,true);if(previousFocus?.isConnected)previousFocus.focus();error?rejectDone(error):resolveDone(result);};
  const cancel = () => finish(null,cancelled());
  const contextWatch = setInterval(() => {if(!isCurrent())cancel();},300);
  modal.querySelectorAll('[data-converter-cancel]').forEach(button => button.onclick = cancel);
  function keyboard(event) {
    if(event.key === 'Escape') {event.preventDefault();event.stopImmediatePropagation();cancel();return;}
    if(event.key === 'Tab') {
      const nodes = [...modal.querySelectorAll('button:not(:disabled),input,select,a[href]')].filter(node=>!node.closest('[hidden]'));
      if(!nodes.length)return;const first=nodes[0],last=nodes.at(-1);
      if(event.shiftKey&&(document.activeElement===first||!modal.contains(document.activeElement))){event.preventDefault();last.focus();}
      else if(!event.shiftKey&&(document.activeElement===last||!modal.contains(document.activeElement))){event.preventDefault();first.focus();}
    }
  }
  document.addEventListener('keydown',keyboard,true);modal.querySelector('button').focus();
  const permittedAccounts = accounts.filter(account => account.active !== false && !account.isControl && String(account.id)!==String(bank?.ledgerAccountId));
  const categoryOptions = selected => `<option value="">Choose later in Bank Review</option>${permittedAccounts.map(account=>`<option value="${esc(account.id)}" ${account.id===selected?'selected':''}>${esc(account.code)} · ${esc(account.name)}</option>`).join('')}`;
  function renderRows() {
    const subset = parsed.rows.slice(page*25,page*25+25);
    review.innerHTML = `<p>${parsed.rows.length} rows found. Suggestions are rules to review, not confirmed accounting decisions. Amount: positive = money in; negative = money out. For a credit account, verify every purchase, refund and repayment direction.</p><div class="tegh-converter-scroll" tabindex="0" aria-label="Extracted statement rows"><table><thead><tr><th>Include</th><th>Source</th><th>Date</th><th>Description</th><th>Amount (${esc(bank?.currency||'CAD')})</th><th>Source balance</th><th>Suggested category / your choice</th><th>Check</th></tr></thead><tbody>${subset.map(row=>`<tr data-converter-row="${row.sourceRow}"><td><input aria-label="Include row ${row.sourceRow}" type="checkbox" data-field="included" ${row.included?'checked':''}></td><td>Page ${row.page}, row ${row.sourceRow}<details><summary>Original text</summary><p>${esc(row.raw)}</p></details></td><td><input aria-label="Date row ${row.sourceRow}" type="date" data-field="date" value="${esc(row.date)}"></td><td><input aria-label="Description row ${row.sourceRow}" data-field="description" maxlength="8000" value="${esc(row.description)}"><small>Full wording is kept with the statement. The register shows up to 500 characters.</small></td><td><input aria-label="Amount row ${row.sourceRow}" data-field="amount" type="text" inputmode="decimal" value="${esc(displayAmount(moneyCents(row.amount.toFixed(2))))}"></td><td class="tegh-converter-money">${row.balance===null?'Not supplied':esc(row.balance.toFixed(2))}</td><td><span>${esc(row.category)}</span><select aria-label="Category row ${row.sourceRow}" data-field="category">${categoryOptions(row.reviewedAccountId)}</select><small>${row.reviewedCategory?'Your choice retained for Bank Review.':'Company rules take priority unless you choose an account.'}</small></td><td><strong>${row.userReviewed?'Edited for review':esc(row.extractionMethod)}</strong><p>${esc(row.issues || 'Compare with your statement.')}${row.duplicate?' Similar row found; it may be a separate genuine purchase.':''}</p></td></tr>`).join('')}</tbody></table></div><nav aria-label="Statement review pages"><button type="button" data-prev ${page===0?'disabled':''}>Previous</button><span>Page ${page+1} of ${Math.ceil(parsed.rows.length/25)}</span><button type="button" data-next ${(page+1)*25>=parsed.rows.length?'disabled':''}>Next</button></nav><label class="tegh-converter-confirm"><input type="checkbox" data-reviewed-confirm> I checked the selected rows against the statement, including dates, signs and suggested categories.</label>`;
    review.querySelector('[data-prev]').onclick=()=>{page--;renderRows();};review.querySelector('[data-next]').onclick=()=>{page++;renderRows();};
    review.querySelector('[data-reviewed-confirm]').onchange=event=>{next.disabled=!event.target.checked;}; next.disabled=true;
    review.querySelectorAll('[data-field]').forEach(input=>input.addEventListener('change',()=>{
      try {const row=parsed.rows[Number(input.closest('tr').dataset.converterRow)-1];invalidFields.delete(`${row.sourceRow}:${input.dataset.field}`);updateReviewedRow(row,input.dataset.field,input.type==='checkbox'?input.checked:input.value);errorNode.textContent='';input.closest('tr').querySelector('td:last-child strong').textContent='Edited for review';if(input.dataset.field==='category')input.closest('td').querySelector('small').textContent='Your choice retained for Bank Review.';next.disabled=true;review.querySelector('[data-reviewed-confirm]').checked=false;}
      catch(error){invalidFields.add(`${input.closest('tr').dataset.converterRow}:${input.dataset.field}`);errorNode.textContent=error.message;next.disabled=true;}
    }));
  }
  form.onsubmit=event=>{
    event.preventDefault();if(!form.reportValidity())return;
    try {options=Object.fromEntries(new FormData(form));parsed=convertPages(specialised?[]:pageData.pages,options);if(specialised){parsed.rows=specialised.rows.map((row,index)=>({date:row.date,description:row.description,reference:row.reference||'',amount:row.amountCents/100,balance:null,category:Core.categorize(row.description,row.amountCents/100),sourceRow:index+1,page:row.page||1,raw:`${row.date} ${row.description} ${displayAmount(row.amountCents)}`,originalDate:row.date,originalDescription:row.description,originalAmountCents:row.amountCents,confidence:0,issues:'Verified by the existing statement-specific totals check; category still needs review.',included:true,duplicate:false,extractionMethod:'Text',userReviewed:false,reviewedCategory:false,reviewedAccountId:null}));parsed.metadata={openingBalance:null,closingBalance:null};}invalidFields.clear();if(!parsed.rows.length)throw new Error('No transactions were detected. Check the period and column layout, or upload a CSV exported by your bank.');page=0;renderRows();status.textContent=`${parsed.rows.length} transactions ready to review. Nothing has been uploaded.`;errorNode.textContent='';}
    catch(error){errorNode.textContent=error.message;next.disabled=true;}
  };
  form.onchange=()=>{parsed=null;review.innerHTML='';next.disabled=true;};
  next.onclick=()=>{try{if(!isCurrent())throw cancelled();if(invalidFields.size)throw new Error('Correct the highlighted row fields before continuing.');const result=reviewedExtraction(parsed,options,pageData.pageCount);result.accountLastFour=pageData.accountLastFour||null;finish(result);}catch(error){errorNode.textContent=error.message;}};
  void (async()=>{
    try {
      if(globalThis.TeghLoadFeature) await globalThis.TeghLoadFeature('ocr');
      else await import('./tegh-native-ocr-v5220.js');
      if(settled)return;
      pageData=await globalThis.TeghNativeOCR.extractStatementPages(file,{signal:abort.signal,
        onPassword:retry=>window.prompt(retry?'That PDF password did not work. Try again:':'Enter this PDF’s password. It stays in this browser.'),
        onProgress:progress=>{if(settled)return;status.textContent=progress.detail||'Reading statement…';onProgress(progress.detail||'Reading statement…',8+Math.round(progress.progress*70));}});
      if(settled)return;
      const documentText=pageData.pages.map(page=>page.text).join('\n');
      const identifiers=[...documentText.matchAll(/\bAccount(?:\s*(?:Number|No\.?|#))\s*:?\s*([\dXx* -]{4,25})/gi)].map(match=>match[1].replace(/\D/g,'')).filter(number=>number.length>=4).map(number=>number.slice(-4));
      const accountIds=[...new Set(identifiers)];
      if(accountIds.length>1)throw new Error('This PDF contains more than one account. Split it into one statement per selected account.');
      pageData.accountLastFour=accountIds[0]||null;
      const selectedLastFour=String(bank?.maskedNumber||'').replace(/\D/g,'').slice(-4);
      if(pageData.accountLastFour&&selectedLastFour.length===4&&pageData.accountLastFour!==selectedLastFour)throw new Error('The statement account number does not match the selected account.');
      if((/Business Account/i.test(documentText)&&/Withdrawals\/Debits/i.test(documentText)&&/Deposits\/Credits/i.test(documentText))||/ScotiaLine\s*for business/i.test(documentText)) {
        const legacy=await import('./pdfStatementImport-BTobSMtl-v211.js?v=4600');
        specialised=await legacy.extractPdfStatement(file,onProgress);
        if(!specialised.summaryVerified)throw new Error('The statement-specific totals could not be verified. Use the bank’s CSV/OFX download.');
        if(specialised.accountType&&specialised.accountType!==bank?.accountType)throw new Error('This statement does not match the selected bank or credit-account type.');
        pageData.accountLastFour=specialised.accountLastFour||pageData.accountLastFour;
      }
      if(settled)return;form.hidden=false;status.textContent=`${pageData.pageCount} pages read locally. Confirm the statement period and date order.`;form.elements.periodStart.focus();
    } catch(error) {if(settled)return;errorNode.textContent=error.name==='AbortError'?'Conversion cancelled.':error.message;status.textContent='No transactions were imported. Close and try the file again.';}
  })();
  return done;
}
