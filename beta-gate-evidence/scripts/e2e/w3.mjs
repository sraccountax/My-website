// W9: statement upload and import through the screens.
import {open,menu,pageEl,ids,confirm} from './base.mjs';import {sql,rec,check,save} from '../lib.mjs';
const A='E2E';const cid=ids().E2E;const {b,p,errs}=await open(cid);const q=sql;
try{
 await menu(p,'Banking','Upload Statement',3000);const f=p.locator('[data-bank-preview-form]').filter({visible:true}).last();
 await f.locator('[name=file]').setInputFiles('/srv/gate/t/e2e/stmt.csv');await f.locator('button',{hasText:/Upload and Preview/}).click();
 const go=p.locator('button:visible',{hasText:'Continue with import'});await go.waitFor({timeout:15000});
 const summary=await p.locator('text=/selected \\/ 3 rows/').first().textContent().catch(()=>'');
 const already=q(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${cid}'`);
 if(already==='0'){await go.click();await p.waitForTimeout(1500);await confirm(p,3000);await p.waitForTimeout(2500);
  const n=q(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${cid}' AND status='pending'`);
  rec('W9-01',A,'Upload Statement → preview (3 rows, money in $565.00, money out $282.50) → Continue imports 3 unposted bank lines',n==='3'&&/Money out \$282\.50/.test(summary)&&/Money in \$565\.00/.test(summary)?'PASS':'FAIL',`rows=${n} summary=${summary}`);
  check('W9-02',A,'Import creates no General Ledger entries',q(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${cid}' AND source_type LIKE 'bank%'`),'0');
 }
 // Duplicate import (DEF-10 regression, beta step 7): upload the same statement again on a fresh screen.
 {await menu(p,'Banking','Upload Statement',3000);
  // A preview left open earlier reopens over the upload screen (the DEF-10 fix); cancel it first, as a user would.
  for(let i=0;i<5&&await p.locator('button:visible',{hasText:'Cancel import'}).count();i++){await p.locator('button:visible',{hasText:'Cancel import'}).last().click();await p.waitForTimeout(1500);await confirm(p,1500)}
  const f2=p.locator('[data-bank-preview-form]').filter({visible:true}).last();
  await f2.locator('[name=file]').setInputFiles('/srv/gate/t/e2e/stmt.csv');await f2.locator('button',{hasText:/Upload and Preview/}).click();
  // The second preview opens as a dialog over the upload screen; use the dialog's own buttons (the topmost ones).
  const go=p.locator('button:visible',{hasText:'Continue with import'}).last();await go.waitFor({timeout:15000});await p.waitForTimeout(800);
  const summary=await p.locator('text=/selected \\/ 3 rows/').first().textContent().catch(()=>'');
  const dup=await p.locator('td',{hasText:/^\s*Duplicate\s*$/}).count();const disabled=await go.isDisabled();
  rec('W9-03',A,'Uploading the same statement again: every row marked Duplicate, nothing selected, import disabled',dup===3&&disabled&&/^0 selected/.test(summary.trim())?'PASS':'FAIL',`dup=${dup} disabled=${disabled} ${summary}`);
  await p.locator('button:visible',{hasText:'Cancel import'}).last().click();await p.waitForTimeout(1500);
  check('W9-04',A,'Cancel import removes the draft preview',q(`SELECT COUNT(*) FROM statement_previews WHERE company_id='${cid}' AND status='draft'`),'0');
 }
}catch(e){rec('W9-01',A,'Statement upload and import','FAIL',e.message.split('\n')[0].slice(0,200));await p.screenshot({path:'/srv/gate/ev/shots/e2e-fail-W9.png'})}
await p.screenshot({path:'/srv/gate/ev/shots/e2e-w9.png'});await b.close();save('e2e.json');
