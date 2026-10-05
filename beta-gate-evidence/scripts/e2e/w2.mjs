// W3–W8: credit note, customer payment, vendor, vendor invoice, vendor payment, GL journal — through the screens.
import {open,menu,pageEl,selectByText,ids,confirm} from './base.mjs';import {sql,rec,check,save} from '../lib.mjs';import fs from 'fs';
const A='E2E';const cid=ids().E2E;const {b,p,errs}=await open(cid);const q=sql;
const post=src=>q(`SELECT IFNULL(GROUP_CONCAT(CONCAT(a.code,':',x.n) ORDER BY a.code),'none') FROM (SELECT jl.account_id,SUM(jl.debit_cents)-SUM(jl.credit_cents) n FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.source_id='${src}' OR je.id='${src}' GROUP BY jl.account_id) x JOIN accounts a ON a.id=x.account_id`);
const visForm=sel=>p.locator(sel).filter({visible:true}).last();
const step=async(id,title,fn)=>{try{await fn()}catch(e){rec(id,A,title,'FAIL',e.message.split('\n')[0].slice(0,220));await p.screenshot({path:`/srv/gate/ev/shots/e2e-fail-${id}.png`})}};
const inv=q(`SELECT id FROM invoices WHERE company_id='${cid}' AND number='INV-1001'`);
// W3 credit note: return 1 of 2 units
if(q(`SELECT COUNT(*) FROM accounting_notes WHERE company_id='${cid}'`)==='0')await step('W3-01','Credit note from the register: return 1 × $500 posts Dr 4000 $500, Dr 2100 $65, Cr 1200 $565',async()=>{
 await menu(p,'Receivables','Customer Invoice Register',3500);const pg=pageEl(p);
 await pg.locator('tbody input[type=checkbox]').first().check({force:true});await p.waitForTimeout(600);
 await pg.locator('button:visible',{hasText:/^Credit Note$/}).first().click();await p.waitForTimeout(3000);
 const f=visForm('[data-r151-form]');await f.locator('[data-r151-return]').click();await p.waitForTimeout(1200);
 await f.locator('[name=qty]').first().fill('1');await f.locator('[name=reason]').selectOption('Product return');await f.locator('[name=message]').fill('One tray returned');
 await f.locator('[data-r151-save=post]').click();await confirm(p);await p.waitForTimeout(2500);
 const n=q(`SELECT CONCAT(journal_entry_id,'|',total_cents) FROM accounting_notes WHERE company_id='${cid}' ORDER BY created_at DESC LIMIT 1`);const [nid,tot]=n.split('|');
 check('W3-01',A,'Credit note from the register: return 1 × $500 posts Dr 4000 $500, Dr 2100 $65, Cr 1200 $565',[tot,post(nid)],['56500','1200:-56500,2100:6500,4000:50000']);
 check('W3-02',A,'Invoice outstanding after credit note = $565.00',q(`SELECT balance_cents FROM invoices WHERE id='${inv}'`),'56500');
});
// W4 payment of the rest
await step('W4-01','Record Payment from the register settles the invoice',async()=>{
 await menu(p,'Receivables','Customer Invoice Register',3500);const pg=pageEl(p);
 await pg.locator('thead input[type=checkbox]').first().uncheck({force:true}).catch(()=>{});await p.waitForTimeout(300);for(const cb of await pg.locator('tbody input[type=checkbox]:checked').all())await cb.uncheck({force:true});
 await pg.locator('tbody tr').filter({has:p.locator('td:nth-child(2)',{hasText:/^\s*INV-1001\s*$/})}).locator('input[type=checkbox]').first().check({force:true});await p.waitForTimeout(600);
 await pg.locator('button:visible',{hasText:/^Record Payment$/}).first().click();await p.waitForTimeout(2500);
 const f=visForm('[data-payment-form]');const pre=await f.locator('[name=amount]').inputValue();await f.locator('[name=reference]').fill('E-TRANSFER 7781');
 await f.locator('button',{hasText:/^Record receipt$/}).click();await confirm(p);await p.waitForTimeout(2500);
 check('W4-01',A,'Payment form pre-fills the outstanding $565.00; saving settles the invoice (balance 0, status paid)',[pre,q(`SELECT CONCAT(balance_cents,'|',status) FROM invoices WHERE id='${inv}'`)],['565.00','0|paid']);
 const pid=q(`SELECT journal_entry_id FROM party_payments WHERE company_id='${cid}' ORDER BY created_at DESC LIMIT 1`);
 check('W4-02',A,'Receipt posts Dr 1000 $565 / Cr 1200 $565',post(pid),'1000:56500,1200:-56500');
});
// W5 vendor
if(q(`SELECT COUNT(*) FROM vendors WHERE company_id='${cid}'`)==='0')await step('W5-01','New Vendor form saves the vendor',async()=>{
 await menu(p,'','payables:create-vendor');const f=visForm('[data-vendor-form]');
 await f.locator('[name=name]').fill('Northern Office Supply');await f.locator('[name=email]').fill('ap@northern.example.test');await f.locator('[name=province]').selectOption('ON');await f.locator('[name=paymentTerms]').selectOption('30');
 await selectByText(f.locator('[name=defaultExpenseAccountId]'),'6400');
 await f.getByRole('button',{name:'Create Vendor'}).click();await p.waitForTimeout(2500);
 check('W5-01',A,'New Vendor form saves the vendor with province',q(`SELECT province FROM vendors WHERE company_id='${cid}' AND name='Northern Office Supply'`),'ON');
});
// W6 vendor invoice
await step('W6-01','New Vendor Invoice: $200 + HST posts Dr 6400 $200, Dr 1100 $26, Cr 2050 $226',async()=>{
 // R151: the shared document form; the vendor's default account (6400) fills the line's GL.
 await menu(p,'Payables','Vendor Invoices',3500);const f=visForm('[data-r151-form]');
 await selectByText(f.locator('[name=partyId]'),'Northern Office Supply');await p.waitForTimeout(1000);
 await f.locator('[name=number]').fill('NOS-5521');
 const cat=f.locator('[name=accountId]').first();if(!(await cat.inputValue()))await selectByText(cat,'6400');
 await f.locator('[name=description]').first().fill('Printer paper and toner');await f.locator('[name=qty]').first().fill('1');await f.locator('[name=rate]').first().fill('200');await p.waitForTimeout(500);
 await f.locator('[name=taxMode]').selectOption('exclusive');await p.waitForTimeout(300);
 await selectByText(f.locator('[name=taxKey]').first(),'ON ·');await p.waitForTimeout(600);
 await f.locator('[data-r151-save=post]').click();await confirm(p);await p.waitForTimeout(3000);
 const bl=q(`SELECT CONCAT(id,'|',total_cents,'|',status) FROM bills WHERE company_id='${cid}' ORDER BY created_at DESC LIMIT 1`);const [bid,tot,st]=bl.split('|');
 check('W6-01',A,'New Vendor Invoice: $200 + HST posts Dr 6400 $200, Dr 1100 $26, Cr 2050 $226',[tot,post(bid)],['22600','1100:2600,2050:-22600,6400:20000']);
 fs.writeFileSync('/srv/gate/t/e2e/state.json',JSON.stringify({bid}));
});
// W7 vendor payment
await step('W7-01','Record Payment on the vendor invoice register pays the bill',async()=>{
 const {bid}=JSON.parse(fs.readFileSync('/srv/gate/t/e2e/state.json'));
 await menu(p,'Payables','Vendor Invoice Register',3500);const pg=pageEl(p);
 await pg.locator('tbody tr').filter({has:p.locator('td:nth-child(2)',{hasText:/NOS-5521/})}).locator('input[type=checkbox]').first().check({force:true});await p.waitForTimeout(600);
 await pg.locator('button:visible',{hasText:/^Record Payment$/}).first().click();await p.waitForTimeout(2500);
 const f=visForm('[data-payment-form]');const pre=await f.locator('[name=amount]').inputValue();await f.locator('[name=reference]').fill('CHQ 1042');
 await f.locator('button',{hasText:/^Record (payment|vendor payment|disbursement)/i}).first().click();await confirm(p);await p.waitForTimeout(2500);
 check('W7-01',A,'Vendor payment pre-fills $226.00; saving pays the bill (balance 0)',[pre,q(`SELECT balance_cents FROM bills WHERE id='${bid}'`)],['226.00','0']);
 const pid=q(`SELECT journal_entry_id FROM party_payments WHERE company_id='${cid}' ORDER BY created_at DESC LIMIT 1`);
 check('W7-02',A,'Vendor payment posts Dr 2050 $226 / Cr 1000 $226',post(pid),'1000:-22600,2050:22600');
});
// W8 journal
await step('W8-01','GL Journal Entry: depreciation Dr 6810 $100 / Cr 1590 $100 posts',async()=>{
 await menu(p,'General ledger','Journal Entries',3000);const f=visForm('[data-journal-form]');
 await f.locator('[name=memo]').fill('Depreciation September');
 const accts=f.locator('select[data-account]');await selectByText(accts.nth(0),'6810');await selectByText(accts.nth(1),'1590');
 await f.locator('input[data-debit]').nth(0).fill('100');await f.locator('input[data-credit]').nth(1).fill('100');await p.waitForTimeout(400);
 await f.locator('button',{hasText:/^Record and Post$/}).click();await confirm(p);await p.waitForTimeout(2500);
 const je=q(`SELECT id FROM journal_entries WHERE company_id='${cid}' AND memo='Depreciation September' ORDER BY created_at DESC LIMIT 1`);
 check('W8-01',A,'GL Journal Entry: depreciation Dr 6810 $100 / Cr 1590 $100 posts',q(`SELECT GROUP_CONCAT(CONCAT(a.code,':',jl.debit_cents-jl.credit_cents) ORDER BY a.code) FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id='${je}'`),'1590:-10000,6810:10000');
});
rec('W-JS',A,'No JavaScript errors during W3–W8',errs.length?'FAIL':'PASS',errs.join(' | ').slice(0,300));
await b.close();save('e2e.json');
