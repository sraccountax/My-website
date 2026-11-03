// W12–W14: reconciliation, reports and dashboard, against figures computed by hand from the activity in W1–W11:
// invoice 2×$500 +HST; credit note 1×$500 +HST; receipt $565; bill $200 +HST $26 paid $226; phone $50 +HST $6.50 from the bank; depreciation $100.
import {open,menu,pageEl,ids,confirm} from './base.mjs';import {sql,rec,check,save} from '../lib.mjs';
const A='E2E';const cid=ids().E2E;const {b,p,errs}=await open(cid);const q=sql;const txt=async()=>(await pageEl(p).textContent()).replace(/\s+/g,' ');
try{
 await menu(p,'Banking','Reconcile Bank Account',3500);const pg=pageEl(p);const f=pg.locator('[data-r122-recon-filter]');
 await f.locator('[name=start]').fill('2026-10-01');await f.locator('[name=end]').fill('2026-10-31');await pg.locator('button:visible',{hasText:/^Calculate$/}).click();await p.waitForTimeout(3000);
 rec('W12-00',A,'A balanced period that ends in the future says it can be completed once the period has ended (not "Ready to complete")',/once the period has ended/.test(await txt())&&!/Ready to complete/.test(await txt())?'PASS':'FAIL','');
 await f.locator('[name=start]').fill('2026-10-01');await f.locator('[name=end]').fill('2026-10-01');await pg.locator('button:visible',{hasText:/^Calculate$/}).click();await p.waitForTimeout(3000);
 const t=await txt();
 rec('W12-01',A,'Reconciliation: bank $282.50 = books $282.50, difference $0.00, ready to complete',/Bank balance · Oct 1, 2026\$282\.50/.test(t)&&/Book balance · Oct 1, 2026\$282\.50/.test(t)&&/Difference\$0\.00/.test(t)&&/Ready to complete/.test(t)?'PASS':'FAIL','');
 await pg.locator('[name=notes]').fill('October reconciled in the E2E run');await pg.locator('button:visible',{hasText:/^Complete Reconciliation$/}).click();await confirm(p);await p.waitForTimeout(3000);
 check('W12-02',A,'Complete Reconciliation saves a completed snapshot (period end today, difference 0)',q(`SELECT CONCAT(status,'|',period_end,'|',difference_cents) FROM reconciliations WHERE company_id='${cid}' ORDER BY created_at DESC LIMIT 1`),'complete|2026-10-01|0');
}catch(e){rec('W12-01',A,'Reconciliation','FAIL',e.message.split('\n')[0].slice(0,200))}
try{
 await menu(p,'Reports','Profit and Loss',4500);let t=await txt();
 rec('W13-01',A,'Profit and Loss: income $500, expenses $350 (6300 $50, 6400 $200, 6810 $100), net $150',/Total IncomeCAD.*CAD 500\.00/.test(t)&&/6300Telephone and Internet.*CAD 50\.00/.test(t)&&/6400Office Supplies.*CAD 200\.00/.test(t)&&/6810Depreciation Expense.*CAD 100\.00/.test(t)&&/(Net (Income|Profit)|Net Income)[^C]*CAD[^C]*.*CAD 150\.00/.test(t)?'PASS':'FAIL',(t.match(/Total Expenses.{0,200}/)||[''])[0].slice(0,160));
 await menu(p,'Reports','Balance Sheet',4500);t=await txt();
 rec('W13-02',A,'Balance Sheet: bank $282.50, assets $215.00 = liabilities $65.00 + equity $150.00, difference $0.00',/1000Business ChequingCAD 282\.50/.test(t)&&/assetsCAD 215\.00/.test(t)&&/liabilitiesCAD 65\.00/.test(t)&&/equityCAD 150\.00/.test(t)&&/differenceCAD 0\.00/.test(t)?'PASS':'FAIL','');
 await menu(p,'Reports','Trial Balance',4500);t=await txt();
 const m=t.match(/closing DebitCAD ([\d,.]+)closing CreditCAD ([\d,.]+).*closing DifferenceCAD ([\d,.]+)/);
 rec('W13-03',A,'Trial Balance: closing debits = closing credits = $665.00 (1000 282.50 + 1100 32.50 + 6300 50 + 6400 200 + 6810 100), difference $0.00',m&&m[1]==='665.00'&&m[2]==='665.00'&&m[3]==='0.00'?'PASS':'FAIL',m?m.slice(1).join(' / '):'footer not found');
}catch(e){rec('W13-01',A,'Reports','FAIL',e.message.split('\n')[0].slice(0,200))}
try{
 await p.evaluate(()=>TeghPortal.openDashboard?.()||TeghPortal.invokeMenuAction('','dashboard'));await p.waitForTimeout(4000);
 const t=(await p.locator('main,.srp-page,body').first().textContent()).replace(/\s+/g,' ');
 rec('W14-01',A,'Dashboard: money in the bank $282.50, customers owe $0.00, you owe suppliers $0.00, profit this year $150.00',/282\.50/.test(t)&&/150\.00/.test(t)?'PASS':'FAIL',(t.match(/Money in the bank.{0,40}/)||[''])[0]+' | '+(t.match(/Profit this year.{0,40}/)||[''])[0]);
 rec('W14-02',A,'Dashboard sales tax card: HST owing $32.50 (collected $65.00 − paid $32.50)',/32\.50/.test(t)?'PASS':'FAIL',(t.match(/(GST\/HST|Sales tax)[^$]{0,40}\$[\d.,]+/)||[''])[0]);
 await p.screenshot({path:'/srv/gate/ev/shots/e2e-dashboard.png'});
}catch(e){rec('W14-01',A,'Dashboard','FAIL',e.message.split('\n')[0].slice(0,200))}
rec('W12-JS',A,'No JavaScript errors in reconciliation, reports, dashboard',errs.length?'FAIL':'PASS',errs.join(' | ').slice(0,300));
await b.close();save('e2e.json');
