// W10: Match and Post — post an expense with a tax code, match the receipt and the cheque to existing payments.
import {open,menu,pageEl,ids,confirm,selectByText} from './base.mjs';import {sql,rec,check,save} from '../lib.mjs';
const A='E2E';const cid=ids().E2E;const {b,p,errs}=await open(cid);const q=sql;
const post=je=>q(`SELECT IFNULL(GROUP_CONCAT(CONCAT(a.code,':',x.n) ORDER BY a.code),'none') FROM (SELECT jl.account_id,SUM(jl.debit_cents)-SUM(jl.credit_cents) n FROM journal_lines jl WHERE jl.journal_entry_id='${je}' GROUP BY jl.account_id) x JOIN accounts a ON a.id=x.account_id`);
const bt=d=>q(`SELECT CONCAT(IFNULL(journal_entry_id,''),'|',status) FROM bank_transactions WHERE company_id='${cid}' AND description LIKE '${d}%'`);
await menu(p,'Banking','Match and Post Transactions',4500);let pg=pageEl(p);
const row=t=>pg.locator('tr',{hasText:t});
try{ if(!/posted/.test(bt('ROGERS'))){
 await row('ROGERS WIRELESS').locator('input[type=checkbox]').check({force:true});await p.waitForTimeout(1200);
 await selectByText(pg.locator('select[name=accountId]').filter({visible:true}),'6300');await selectByText(pg.locator('select[name=taxCode]').filter({visible:true}),'ON');await p.waitForTimeout(800);
 const preview=(await pg.textContent()).replace(/\s+/g,' ');
 await pg.locator('button:visible',{hasText:/^Post Transaction$/}).click();await confirm(p);await p.waitForTimeout(3000);
 const [je,st]=bt('ROGERS').split('|');
 check('W10-01',A,'Post a $56.50 phone bill to 6300 with the ON code: Dr 6300 $50.00, Dr 1100 $6.50, Cr 1000 $56.50',[st,post(je)],['posted','1000:-5650,1100:650,6300:5000']);
 rec('W10-02',A,'Posting preview shows the HST split before posting',/6\.50/.test(preview)&&/50\.00/.test(preview)?'PASS':'FAIL','');
}}catch(e){rec('W10-01',A,'Post a bank line with a tax code','FAIL',e.message.split('\n')[0].slice(0,200));await p.screenshot({path:'/srv/gate/ev/shots/e2e-fail-W10-01.png'})}
rec('W10-JS',A,'No JavaScript errors in Match and Post',errs.length?'FAIL':'PASS',errs.join(' | ').slice(0,300));
await b.close();save('e2e.json');
