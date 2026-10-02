// R142: owner-approved wording (sales tax guide, starter-code note, Product Activity, payroll outside Quebec)
// and the CRA rate tables shown on the payroll screen. Expected texts are written out by hand here.
import {S,sql,rec,check,save,need} from './lib.mjs';import fs from 'fs';
const ids=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));const A='R142';const www='/srv/gate/www/';
const read=f=>fs.readFileSync(www+f,'utf8');
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
// ---------- starter-code note ----------
let r={b:need('co',await o.call('companies',{method:'POST',company:false,json:{name:'R142 Ontario Ltd',province:'ON',country:'Canada',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',taxRegistered:true,taxNumber:'123456789RT0001',coaMode:'default'}}))};
const ON=r.b?.company?.id;
const note=c=>sql(`SELECT description FROM tax_codes WHERE company_id='${ON}' AND code='${c}'`);
check('WR-01',A,'Ontario company, BC starter code note (exact owner-approved text)',note('BC'),'GST only. Charge British Columbia PST only if you are registered with British Columbia. Businesses outside British Columbia may have to register if they sell to customers there. Check with the province. Once registered, edit this code and add PST 7%.');
check('WR-02',A,'Ontario company, QC starter code note (exact text)',note('QC'),'GST only. Charge Quebec QST only if you are registered with Quebec. Businesses outside Quebec may have to register if they sell to customers there. Check with the province. Once registered, edit this code and add QST 9.975%.');
check('WR-03',A,'Ontario company, BC and QC starter codes still GST only',[sql(`SELECT GROUP_CONCAT(c.name) FROM tax_codes t JOIN tax_code_components c ON c.tax_code_id=t.id WHERE t.company_id='${ON}' AND t.code='BC'`),sql(`SELECT GROUP_CONCAT(c.name) FROM tax_codes t JOIN tax_code_components c ON c.tax_code_id=t.id WHERE t.company_id='${ON}' AND t.code='QC'`)],['GST','GST']);
// ---------- sales tax guide wording (shipped portal file) ----------
const portal=read('assets/tegh-portal-v5990.js');
const has=[
 "Tegh picks the tax code from the customer's province (or state and country). That is usually the right place for the sale, but for goods shipped elsewhere or for some services, change the line's tax code.",
 "Some sales are <b>zero-rated</b> (taxed at 0%, for example basic groceries and prescription drugs), so you still claim input tax credits on related purchases. Others are <b>exempt</b> (for example most health and dental services and residential rent), and you can't claim input tax credits for them.",
 "Small suppliers (total taxable sales of $30,000 or less over the last four calendar quarters) don't have to register",
 "If you're not registered, tax you pay on purchases becomes part of the cost.",
 "Keep your receipts, because the CRA can ask for them.",
 "Tegh doesn't check foreign tax rates or rules. Confirm them with a local adviser.",
];
const missing=has.filter(t=>!portal.includes(t));
rec('WR-04',A,'Sales tax guide carries the six approved sentences',missing.length===0?'PASS':'FAIL',missing.map(t=>t.slice(0,40)).join(' | '));
rec('WR-05',A,'Old guide sentences removed ("works out GST/HST from the customer\'s province", "Some items are zero-rated or exempt")',!portal.includes("Tegh works out GST/HST from the customer's province")&&!portal.includes('Some items are zero-rated or exempt')?'PASS':'FAIL','');
// ---------- Product Activity ----------
const agent=read('api/ai_agent.php');
const pa=['specific identification','LIFO is not allowed','Tegh does not track stock quantities or cost layers','count your stock at period end','Ask your accountant if you hold significant stock'];
rec('WR-06',A,'Product Activity note: specific identification, no LIFO, count-based steps, no "yet"',pa.every(t=>portal.includes(t))&&!/cost layers yet/.test(portal)?'PASS':'FAIL',pa.filter(t=>!portal.includes(t)).join(' | '));
rec('WR-07',A,'Ask Tegh Product Activity answer matches (no "does not track yet")',/LIFO is not allowed/.test(agent)&&!/does not track yet/.test(agent)?'PASS':'FAIL','');
// ---------- payroll outside Quebec ----------
const notice='Tegh Payroll Support is for Canadian payroll outside Quebec. It follows the CRA payroll deduction tables (T4127) and does not handle Quebec payroll (Revenu Québec, QPP, QPIP).';
rec('WR-08',A,'Payroll notice (both bundles) says outside Quebec; old "provinces and territories" claim gone',portal.includes(notice)&&read('assets/index-BsxPiq85-v2817.js').includes(notice)&&!portal.includes('CRA rules for Canadian provinces and territories')&&!read('assets/index-BsxPiq85-v2817.js').includes('CRA rules for Canadian provinces and territories')?'PASS':'FAIL','');
rec('WR-09',A,'Product and Subscriptions pages say Canada, excluding Quebec; no "Canada only"',/Canada, excluding Quebec/.test(read('product.html'))&&/Payroll Support Tool \(Canada, excluding Quebec\)/.test(read('subscriptions.html'))&&!/Canada only/.test(read('product.html')+read('subscriptions.html'))?'PASS':'FAIL','');
o.cid=ON;
r=await o.call('payroll/setup',{method:'POST',json:{payrollAccountNumber:'123456789RP0001',remitterType:'regular',defaultFrequency:'biweekly'}});
r=await o.call('payroll/employees',{method:'POST',json:{employeeNumber:'EMP-0001',firstName:'Quebec',lastName:'Worker',provinceOfEmployment:'QC',provinceOfResidence:'QC',hireDate:'2026-01-05',payFrequency:'biweekly',payType:'salary',annualSalaryCents:5200000,standardHours:80,vacationRateBps:400}});
rec('WR-10',A,'The claim matches the code: a Quebec employee is refused',r.s===422&&/quebec/i.test(JSON.stringify(r.b))?'PASS':'FAIL',r.s+' '+(r.b?.code||r.b?.error||''));
// ---------- CRA rate tables ----------
r=await o.call('payroll');const t=r.b?.payroll?.rateTables||[];
check('WR-11',A,'Payroll workspace lists the loaded CRA T4127 tables (label from date)',t.map(x=>`${x.label}|${x.effectiveFrom}`).join(' ; '),'CRA T4127 — January 2026|2026-01-01 ; CRA T4127 — July 2026|2026-07-01');
// A synthetic company on the pre-R137 rules, so the browser run can render the full Company Details guide.
o.cid=undefined;r=await o.call('companies',{method:'POST',company:false,json:{name:'R142 Legacy BC',province:'BC',country:'Canada',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'accounting',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',taxRegistered:true,taxNumber:'123456789RT0001',pstRegistered:true,pstRatePercent:7,coaMode:'manual'}});
const LEG=r.b?.company?.id;sql(`UPDATE companies SET tax_setup_mode='legacy' WHERE id='${LEG}'`);
fs.writeFileSync('/srv/gate/t/ids.json',JSON.stringify({...ids,ON142:ON,LEG142:LEG}));
save('r142.json');
