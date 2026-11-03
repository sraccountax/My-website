// W11: link a bank line to a payment recorded by hand (DEF-11).
import {open,menu,pageEl,ids,confirm} from './base.mjs';import {sql,rec,check,save} from '../lib.mjs';
const A='E2E';const cid=ids().E2E;const {b,p,errs}=await open(cid);const q=sql;
try{for(const [line,party,doc,amt,tag] of [['E-TRANSFER MAPLE','Maple Leaf Cafe','INV-1001','565.00','a'],['CHEQUE 1042','Northern Office Supply','NOS-5521','226.00','b']]){
 await menu(p,'Banking','Match and Post Transactions',4500);const pg=pageEl(p);
 const jeBefore=q(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${cid}'`);
 await pg.locator('tr',{hasText:line}).locator('input[type=checkbox]').check({force:true});
 const box=pg.locator('[data-r143-recorded-payment]');await box.waitFor({timeout:8000});const txt=(await box.textContent()).replace(/\s+/g,' ');
 await p.screenshot({path:`/srv/gate/ev/shots/e2e-w11${tag}-found.png`});
 await box.locator('button',{hasText:'Link to this payment'}).first().click();await confirm(p);await p.waitForTimeout(3000);
 const like=line.replace(/ .*/,'');const tx=q(`SELECT CONCAT(status,'|',journal_entry_id) FROM bank_transactions WHERE company_id='${cid}' AND description LIKE '${line}%'`);
 const pay=q(`SELECT CONCAT(IFNULL(bank_transaction_id,'none'),'|',journal_entry_id) FROM party_payments WHERE company_id='${cid}' AND reference IN ('E-TRANSFER 7781','CHQ 1042') AND journal_entry_id='${tx.split('|')[1]}'`);
 rec(`W11-01${tag}`,A,`Selecting the $${amt} bank line offers the recorded payment (${party} · ${doc})`,txt.includes(party)&&txt.includes(doc)&&txt.includes(amt)?'PASS':'FAIL',txt.slice(0,160));
 rec(`W11-02${tag}`,A,`Link: bank line posted against the payment's own journal entry; payment linked`,tx.split('|')[0]==='posted'&&pay&&pay.split('|')[0]!=='none'?'PASS':'FAIL',tx+' / '+pay);
 check(`W11-03${tag}`,A,'Nothing posted twice: linking creates no journal entry',q(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${cid}'`),jeBefore);
}
 check('W11-04',A,'GL 1000 equals the bank statement closing balance ($282.50)',q(`SELECT SUM(jl.debit_cents-jl.credit_cents) FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE a.company_id='${cid}' AND a.code='1000'`),'28250');
}catch(e){rec('W11-01',A,'Link a bank line to a recorded payment','FAIL',e.message.split('\n')[0].slice(0,200));await p.screenshot({path:'/srv/gate/ev/shots/e2e-fail-W11.png'})}
rec('W11-JS',A,'No JavaScript errors',errs.length?'FAIL':'PASS',errs.join(' | ').slice(0,300));
await b.close();save('e2e.json');
