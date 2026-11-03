// W17–W18: Tegh Assist answers checked against the books; invoice PDF and report exports.
import {open,menu,pageEl,ids,confirm} from './base.mjs';import {sql,rec,check,save} from '../lib.mjs';import fs from 'fs';
const A='E2E';const cid=ids().E2E;const {b,p,errs}=await open(cid);const q=sql;
const gl=c=>+q(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE a.company_id='${cid}' AND a.code='${c}'`);
const profit=-(+q(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE a.company_id='${cid}' AND a.account_type IN ('income','expense')`));
const fmt=c=>(Math.abs(c)/100).toLocaleString('en-CA',{minimumFractionDigits:2,maximumFractionDigits:2});
const ask=async question=>{await p.evaluate(()=>document.querySelectorAll('.tegh-assist-scrim').forEach(n=>n.remove()));await p.locator('button').filter({hasText:/^Tegh Assist$/}).first().click();const inp=p.locator('.tegh-assist-drawer [data-command-free-text] input');await inp.waitFor({timeout:15000});await inp.fill(question);const t0=Date.now();await inp.press('Enter');await p.waitForTimeout(2500);
 const ans=await p.evaluate(()=>{const d=document.querySelector('.tegh-assist-drawer');return d?(d.querySelector('[data-command-result]')?.innerText||'').replace(/\s+/g,' '):'PAGE: '+(document.querySelector('.srp-page:last-of-type h1')?.textContent||'')});await p.keyboard.press('Escape').catch(()=>{});return ans};
try{
 const bank=gl('1000');let a=await ask('how much money is in the bank');
 const pageTxt=a.startsWith('PAGE:')?(await pageEl(p).textContent()).replace(/\s+/g,' '):a;
 rec('W17-01',A,`Assist "how much money is in the bank" shows $${fmt(bank)} (answer or the Bank Accounts page it opens)`,pageTxt.includes(fmt(bank))?'PASS':'FAIL',a.slice(0,60)+' … '+(pageTxt.match(/.{0,60}282\.50.{0,20}/)||[''])[0]);
 a=await ask('who owes me money');const ar=gl('1200');
 rec('W17-02',A,`Assist "who owes me money" reflects receivables of $${fmt(ar)}`,(ar===0?/(no one|nobody|\$0\.00|nothing|no customers|0\.00)/i.test(a):a.includes(fmt(ar)))?'PASS':'FAIL',a.slice(0,160));
 a=await ask('what is my profit this year');
 rec('W17-03',A,`Assist "what is my profit this year" answers ${profit<0?'a loss of':''} $${fmt(profit)}`,a.includes(fmt(profit))?'PASS':'FAIL',a.slice(0,160));
}catch(e){rec('W17-01',A,'Tegh Assist','FAIL',e.message.split('\n')[0].slice(0,200))}
try{
 await menu(p,'Receivables','Customer Invoice Register',3500);const pg=pageEl(p);
 await pg.locator('tbody tr').filter({has:p.locator('td:nth-child(2)',{hasText:/^\s*INV-1001\s*$/})}).locator('input[type=checkbox]').first().check({force:true});await p.waitForTimeout(500);
 await pg.locator('button:visible',{hasText:/^View Invoice$/}).first().click();await p.waitForTimeout(2500);
 const [dl]=await Promise.all([p.waitForEvent('download',{timeout:20000}),(async()=>{await p.getByText(/^Document\s*▾?$/).first().click();await p.waitForTimeout(500);await p.locator('button:visible',{hasText:'Invoice PDF'}).first().click()})()]);
 const path='/srv/gate/ev/e2e-INV-1001.pdf';await dl.saveAs(path);const head=fs.readFileSync(path).subarray(0,5).toString();
 const txt=(await import('child_process')).execFileSync('pdftotext',[path,'-'],{encoding:'utf8'}).replace(/\s+/g,' ');
 rec('W18-01',A,'Invoice PDF downloads and shows INV-1001, the customer, HST $130.00 and total $1,130.00',head==='%PDF-'&&/INV-1001/.test(txt)&&/Maple Leaf Cafe/.test(txt)&&/130\.00/.test(txt)&&/1,130\.00/.test(txt)?'PASS':'FAIL',head+' '+txt.slice(0,120));
}catch(e){rec('W18-01',A,'Invoice PDF','FAIL',e.message.split('\n')[0].slice(0,200))}
try{
 await menu(p,'Reports','Profit and Loss',4500);const pg=pageEl(p);
 await pg.locator('thead').getByText(/Actions/).first().click();await p.waitForTimeout(800);await p.locator('[role=menuitem]:visible',{hasText:'Export'}).first().click();await p.waitForTimeout(1000);
 const dlg=p.locator('[role=dialog]:visible,dialog[open]').last();const fmts=(await dlg.textContent()).replace(/\s+/g,' ');await dlg.locator('label',{hasText:'Format'}).locator('select').selectOption('csv');await p.waitForTimeout(300);
 const [dl]=await Promise.all([p.waitForEvent('download',{timeout:20000}),dlg.locator('button',{hasText:/^Export$/}).click()]);
 const path='/srv/gate/ev/e2e-pl.'+(dl.suggestedFilename().split('.').pop());await dl.saveAs(path);const body=fs.readFileSync(path,'utf8');console.log('EXPORT FORMATS:',fmts.slice(0,200));
 rec('W18-02',A,'Profit and Loss export downloads and contains Service Revenue 500.00 and Wages and Salaries 2,000.00',/Service Revenue/.test(body)&&/500\.00/.test(body)&&/Wages and Salaries/.test(body)&&/2,?000\.00/.test(body)?'PASS':'FAIL',dl.suggestedFilename());
}catch(e){rec('W18-02',A,'Report export','FAIL',e.message.split('\n')[0].slice(0,200))}
rec('W17-JS',A,'No JavaScript errors in Assist and exports',errs.length?'FAIL':'PASS',errs.join(' | ').slice(0,300));
await b.close();save('e2e.json');
