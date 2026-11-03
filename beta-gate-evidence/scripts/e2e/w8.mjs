// W16: payroll verification → verify (finalize) pay run with review tick → post to GL.
// Expected (computed by hand from CRA T4127 July 2026 rates, Ontario, $52,000/yr biweekly, basic claims):
// gross 2000.00, CPP 110.99, EI 32.60, tax ≈ 254.83 (Tegh 254.82), net 1601.59.
import {open,menu,pageEl,ids,confirm} from './base.mjs';import {sql,rec,check,save} from '../lib.mjs';
const A='E2E';const cid=ids().E2E;const {b,p,errs}=await open(cid);const q=sql;
const run=()=>q(`SELECT CONCAT(id,'|',status,'|',gl_status,'|',gross_pay_cents,'|',employee_cpp_cents,'|',employee_ei_cents,'|',income_tax_cents,'|',net_pay_cents,'|',IFNULL(accrual_journal_entry_id,'')) FROM payroll_runs WHERE company_id='${cid}' ORDER BY created_at DESC LIMIT 1`);
let r=run().split('|');
check('W16-01',A,'Draft pay run figures: gross $2,000.00, CPP $110.99, EI $32.60 (independent), net = gross − deductions',[r[3],r[4],r[5],String(+r[3]-r[4]-r[5]-r[6])],['200000','11099','3260',r[7]]);
rec('W16-02',A,'Income tax within $0.05 of the hand calculation ($254.83)',Math.abs(+r[6]-25483)<=5?'PASS':'FAIL','Tegh '+r[6]);
try{
 let pg;if(q(`SELECT COUNT(*) FROM payroll_verifications WHERE company_id='${cid}'`)==='0'){await menu(p,'Payroll','Payroll Verification',3500);pg=pageEl(p);await pg.locator('button:visible',{hasText:/Verify Payroll/}).first().click();await p.waitForTimeout(2500);pg=pageEl(p);
 await pg.locator('select').filter({visible:true}).first().selectOption('formula');await pg.locator('label',{hasText:'Evidence Note'}).locator('input,textarea').first().fill('Checked against CRA T4127 July 2026 formulas by hand in the E2E run');
 const v=pg.locator('tr',{hasText:'Priya Sharma'}).getByText(/^Verify$/).first();
 if(!(await v.isVisible().catch(()=>false))){await pg.locator('tr',{hasText:'Priya Sharma'}).getByText('Actions').first().click();await p.waitForTimeout(600)}
 await p.getByText(/^Verify$/).filter({visible:true}).first().click();await confirm(p);await p.waitForTimeout(2500);
 }
 check('W16-03',A,'Employee verified (formula source, evidence note stored)',q(`SELECT COUNT(*) FROM payroll_verifications WHERE company_id='${cid}'`)>'0'?'1':'0','1');
 if(run().split('|')[1]==='draft'){
 await menu(p,'Payroll','Pay Run Register',3500);pg=pageEl(p);await pg.locator('tbody tr').first().getByText('Actions').first().click();await p.waitForTimeout(800);
 await p.getByRole('menuitem',{name:'Verify Pay Run',exact:true}).click();await p.waitForTimeout(2000);
 const dlg=p.locator('dialog[open],[role=dialog]').filter({visible:true}).last();const dtext=(await dlg.textContent()).replace(/\s+/g,' ');
 const finalBtn=dlg.locator('button',{hasText:/Verify|Finalize|Confirm|Complete/}).last();const disabledBefore=await finalBtn.isDisabled();
 await dlg.locator('[name=reference]').fill('T4127 formula check 2026-10-01 · E2E');await p.waitForTimeout(300);const disabledWithoutTick=await finalBtn.isDisabled();await dlg.locator('input[name=reviewed]').check({force:true});await p.waitForTimeout(400);const enabledAfter=await finalBtn.isEnabled();
 rec('W16-04',A,'Verify Pay Run dialog: button stays disabled until a reference is entered AND the "I have reviewed and verified…" box is ticked',disabledBefore&&disabledWithoutTick&&enabledAfter&&/reviewed and verified/i.test(dtext)?'PASS':'FAIL',`empty=${disabledBefore} refOnly=${disabledWithoutTick} ticked=${enabledAfter}`);
 await finalBtn.click();await confirm(p);await p.waitForTimeout(3000);
 }
 r=run().split('|');rec('W16-05',A,'Pay run verified (status no longer draft)',r[1]!=='draft'?'PASS':'FAIL',r[1]+' / gl '+r[2]);
 await menu(p,'Payroll','Pay Run Register',3500);pg=pageEl(p);await pg.locator('tbody tr').first().getByText('Actions').first().click();await p.waitForTimeout(800);
 await p.getByRole('menuitem',{name:'Post to GL',exact:true}).click();await confirm(p);await p.waitForTimeout(3000);
 r=run().split('|');
 const j=q(`SELECT GROUP_CONCAT(CONCAT(a.code,':',x.n) ORDER BY a.code) FROM (SELECT jl.account_id,SUM(jl.debit_cents)-SUM(jl.credit_cents) n FROM journal_lines jl WHERE jl.journal_entry_id='${r[8]}' GROUP BY jl.account_id) x JOIN accounts a ON a.id=x.account_id`);
 const er=q(`SELECT CONCAT(employer_cpp_cents,'|',employer_ei_cents) FROM payroll_runs WHERE id='${r[0]}'`).split('|');
 const expect=`2300:-${r[7]},2310:-${r[6]},2320:-${+r[4]+ +er[0]},2330:-${+r[5]+ +er[1]},7000:${r[3]},7010:${+er[0]+ +er[1]}`;
 rec('W16-06',A,'Post to GL: Dr 7000 wages $2,000, Dr 7010 employer CPP+EI; Cr 2300 net, 2310 tax, 2320 CPP (both), 2330 EI (both); balanced',r[2]==='posted'&&j===expect?'PASS':'FAIL',`gl=${r[2]} got ${j} want ${expect}`);
 check('W16-07',A,'Employer CPP = employee CPP ($110.99); employer EI = 1.4 × employee EI ($45.64)',er,[r[4],String(Math.round(r[5]*1.4))]);
}catch(e){rec('W16-03',A,'Payroll verify/finalize/post','FAIL',e.message.split('\n')[0].slice(0,220));await p.screenshot({path:'/srv/gate/ev/shots/e2e-fail-W16.png'})}
rec('W16-JS',A,'No JavaScript errors in payroll',errs.length?'FAIL':'PASS',errs.join(' | ').slice(0,300));
await b.close();save('e2e.json');
