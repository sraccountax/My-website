// W1–W3: customer, invoice, payment through the screens.
import {open,menu,pageEl,toast,selectByText,ids,confirm} from './base.mjs';import {sql,rec,check,save} from '../lib.mjs';
const A='E2E';const cid=ids().E2E;const {b,p,errs}=await open(cid);const q=s=>sql(s);
try{
 await menu(p,'','receivables:create-customer');const f=pageEl(p).locator('[data-customer-form]');
 await f.locator('[name=name]').fill('Maple Leaf Cafe');await f.locator('[name=email]').fill('cafe@example.test');await f.locator('[name=addressLine1]').fill('1 King St');await f.locator('[name=city]').fill('Toronto');await f.locator('[name=postalCode]').fill('M5H 1A1');
 await f.locator('[name=province]').selectOption('ON');await f.locator('[name=paymentTerms]').selectOption('30');
 await f.getByRole('button',{name:'Create Customer'}).click();await p.waitForTimeout(2500);
 check('W1-01',A,'New Customer form saves the customer with province and terms',q(`SELECT CONCAT(province,'|',default_terms_days) FROM customers WHERE company_id='${cid}' AND name='Maple Leaf Cafe'`),'ON|30');
}catch(e){rec('W1-01',A,'New Customer form saves the customer','FAIL',e.message.slice(0,200))}
try{
 // R151: the shared document form (details on top, line grid below).
 await menu(p,'Receivables','Customer Invoices',3500);const f=pageEl(p).locator('[data-r151-form]');
 await selectByText(f.locator('[name=partyId]'),'Maple Leaf Cafe');await p.waitForTimeout(800);
 await f.locator('[name=description]').first().fill('Catering for launch');await f.locator('[name=qty]').first().fill('2');await f.locator('[name=rate]').first().fill('500');await p.waitForTimeout(600);
 const shown=await f.evaluate(el=>el.textContent.match(/1,130\.00/)?'1130 shown':'total not shown');
 await f.locator('[data-r151-save=post]').click();await confirm(p);await p.waitForTimeout(2500);
 const inv=q(`SELECT CONCAT(number,'|',total_cents,'|',status) FROM invoices WHERE company_id='${cid}' ORDER BY created_at DESC LIMIT 1`);
 rec('W2-01',A,'New Invoice form: 2 × $500 to an Ontario customer issues for $1,130.00 (HST 13%)',/\|113000\|/.test(inv)?'PASS':'FAIL',inv+' / '+shown);
 const id=q(`SELECT id FROM invoices WHERE company_id='${cid}' ORDER BY created_at DESC LIMIT 1`);
 const j=q(`SELECT GROUP_CONCAT(CONCAT(a.code,':',x.n) ORDER BY a.code) FROM (SELECT jl.account_id,SUM(jl.debit_cents)-SUM(jl.credit_cents) n FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.source_id='${id}' GROUP BY jl.account_id) x JOIN accounts a ON a.id=x.account_id`);
 check('W2-02',A,'Issued invoice posts Dr 1200 $1,130 / Cr 4000 $1,000 / Cr 2100 $130',j,'1200:113000,2100:-13000,4000:-100000');
}catch(e){rec('W2-01',A,'New Invoice form issues the invoice','FAIL',e.message.slice(0,200))}
fs.writeFileSync('/srv/gate/ev/e2e-w1-errs.json',JSON.stringify(errs));
await p.screenshot({path:'/srv/gate/ev/shots/e2e-w1.png'});await b.close();save('e2e.json');
import fs from 'fs';
