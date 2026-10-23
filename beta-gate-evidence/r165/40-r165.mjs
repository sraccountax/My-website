// R165 (browser): report row actions open the record they name. "View" on a vendor invoice or a credit/debit note used
// to re-open the register with that row highlighted (it only grew taller). Each check compares the page with the database.
import {open} from './e2e/base.mjs';import {sql,rec,save} from './lib.mjs';
const A='R165';
const CID=sql("SELECT id FROM companies WHERE name LIKE 'R151 Documents Inc%' ORDER BY created_at DESC LIMIT 1");
if(!CID)throw new Error('run 19-r151.mjs and 20-r151ui.mjs first');
const one=q=>sql(q).split('\n')[0]||'';
const money=c=>'$'+(Number(c)/100).toLocaleString('en-CA',{minimumFractionDigits:2,maximumFractionDigits:2});
const BILL=one(`SELECT CONCAT_WS('|',id,number,foreign_total_cents,foreign_balance_cents) FROM bills WHERE company_id='${CID}' AND status='open' ORDER BY number LIMIT 1`).split('|');
const SCN=one(`SELECT CONCAT_WS('|',id,number,foreign_total_cents) FROM accounting_notes WHERE company_id='${CID}' AND note_kind='vendor_credit' AND status='posted' ORDER BY number LIMIT 1`).split('|');
const CN=one(`SELECT CONCAT_WS('|',id,number,foreign_total_cents) FROM accounting_notes WHERE company_id='${CID}' AND note_kind='customer_credit' AND status='posted' ORDER BY number LIMIT 1`).split('|');
const {b,p,errs}=await open(CID,{w:1440,h:900});
const shots='/srv/gate/ev/shots';
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL',e.message.slice(0,300))}};
const report=async name=>{await p.evaluate(n=>TeghPortal.openReport(n),name);await p.waitForSelector('[data-r23-table] tbody tr',{timeout:25000});await p.waitForTimeout(500)};
const rowMenu=async(text)=>{const row=p.locator('[data-r23-table] tbody tr',{hasText:text}).first();await row.locator('button',{hasText:/^Actions$/}).click();await p.waitForSelector('.r23-menu');return p.$$eval('.r23-menu button',ns=>ns.map(n=>n.textContent.trim()))};
const view=async()=>{await p.waitForSelector('[data-srp-page="document-view"] [data-doc-lines]',{timeout:15000});await p.waitForTimeout(300);return p.evaluate(()=>({h2:document.querySelector('.r19-detail-heading h2')?.textContent.replace(/\s+/g,' ').trim(),lines:document.querySelectorAll('[data-doc-lines] tbody tr').length,total:document.querySelector('.r19-line-totals .r19-grand-total dd')?.textContent.trim(),outstanding:document.querySelector('.r19-detail-summary .r19-outstanding dd')?.textContent.trim(),actions:[...document.querySelectorAll('.r19-detail-actions button')].map(b=>b.textContent.trim())}))};

await t('RA-01','Vendor Invoice & Note Register: row Actions › View vendor invoice opens the invoice (lines, total, open balance from the database)',async()=>{
  await report('Vendor Invoice & Note Register');const items=await rowMenu(BILL[1]);await p.locator('.r23-menu').getByText('View vendor invoice',{exact:true}).click();const v=await view();await p.screenshot({path:shots+'/r165-view-vendor-invoice.png'});
  return {ok:v.h2.includes(BILL[1])&&v.lines>0&&v.total===money(BILL[2])&&v.outstanding===money(BILL[3])&&!items.includes('View'),info:`menu ${items.join(', ')} · ${JSON.stringify(v)} · db total ${BILL[2]} balance ${BILL[3]}`}});
await t('RA-02','Supplier credit note row: Actions › View note opens the note itself, not the register with the row highlighted',async()=>{
  await report('Vendor Invoice & Note Register');const items=await rowMenu(SCN[1]);await p.locator('.r23-menu').getByText('View note',{exact:true}).click();const v=await view();await p.screenshot({path:shots+'/r165-view-scn.png'});
  return {ok:v.h2.includes(SCN[1])&&/Supplier Credit Note/.test(v.h2)&&v.total===money(SCN[2])&&items.includes('Open in note register'),info:`menu ${items.join(', ')} · ${JSON.stringify(v)} · db total ${SCN[2]}`}});
await t('RA-03','Customer credit note row: Actions › View note opens the note',async()=>{
  await report('Customer Invoice & Note Register');await rowMenu(CN[1]);await p.locator('.r23-menu').getByText('View note',{exact:true}).click();const v=await view();
  return {ok:v.h2.includes(CN[1])&&/Customer Credit Note/.test(v.h2)&&v.total===money(CN[2]),info:JSON.stringify(v)}});
await t('RA-04','Clicking the note number in the register opens the note',async()=>{
  await report('Vendor Invoice & Note Register');await p.locator('[data-r23-table] tbody tr',{hasText:SCN[1]}).first().locator('.r23-record-link').click();const v=await view();return {ok:v.h2.includes(SCN[1]),info:v.h2}});
await t('RA-05','The note view links to its original vendor invoice, which opens',async()=>{
  await p.locator('[data-doc-original]').click();const v=await view();return {ok:/Vendor Invoice/.test(v.h2),info:v.h2}});
await t('RA-06','Selecting one vendor invoice row: the selection bar\'s View vendor invoice opens it',async()=>{
  await report('Vendor Invoice & Note Register');const row=p.locator('[data-r23-table] tbody tr',{hasText:BILL[1]}).first();await row.locator('[data-r23-select]').first().check();
  const bar=p.locator('[data-r23-selection-actions] button',{hasText:/^View vendor invoice$/i}).first();await bar.click({timeout:8000});const v=await view();return {ok:v.h2.includes(BILL[1]),info:v.h2}});
await t('RA-07','Supplier Credit & Debit Notes screen: each note has a View button that opens the note',async()=>{
  await p.evaluate(()=>TeghPortal.openAccountingNotes('vendor'));await p.waitForSelector('[data-note-view]',{state:'attached',timeout:20000});const n=await p.locator('[data-note-view]').count();
  await p.evaluate(id=>document.querySelector(`[data-note-view="${id}"]`).click(),SCN[0]);const v=await view();return {ok:n>0&&v.h2.includes(SCN[1]),info:`${n} View buttons · ${v.h2}`}});
await t('RA-08','Day Book / ledger "open source" for a note or a vendor invoice opens the document',async()=>{
  await p.evaluate(id=>TeghPortal.openSourceRecord('accounting_note_vendor',id),SCN[0]);const a=await view();await p.evaluate(id=>TeghPortal.openSourceRecord('bill',id),BILL[0]);const c=await view();
  return {ok:a.h2.includes(SCN[1])&&c.h2.includes(BILL[1]),info:`${a.h2} · ${c.h2}`}});
await t('RA-09','Back from the document returns to the register',async()=>{
  await report('Vendor Invoice & Note Register');await rowMenu(BILL[1]);await p.locator('.r23-menu').getByText('View vendor invoice',{exact:true}).click();await view();
  await p.locator('.srp-page-head button').first().click();await p.waitForTimeout(1500);const page=await p.evaluate(()=>document.querySelector('.srp-page')?.dataset.srpPage);return {ok:page==='bills',info:page}});
await t('RA-10','Phone width: the note view fits without sideways scrolling',async()=>{
  await p.setViewportSize({width:390,height:844});await p.evaluate(id=>TeghPortal.openDocumentView('note',id,{side:'vendor'}),SCN[0]);await view();
  const o=await p.evaluate(()=>({doc:document.scrollingElement.scrollWidth,vw:innerWidth}));await p.screenshot({path:shots+'/r165-view-scn-phone.png'});await p.setViewportSize({width:1440,height:900});
  return {ok:o.doc<=o.vw+1,info:JSON.stringify(o)}});
await t('RA-11','No browser errors during these checks',async()=>({ok:errs.length===0,info:errs.join(' | ')||'none'}));
await b.close();save('r165.json');
