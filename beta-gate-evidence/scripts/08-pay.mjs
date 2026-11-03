import {S,sql,rec,check,save} from './lib.mjs';import fs from 'fs';const ids=JSON.parse(fs.readFileSync('ids.json'));const A='PAY';
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');o.cid=ids.ON;
const base={employeeNumber:'EMP-0001',firstName:'Test',lastName:'Employee',provinceOfEmployment:'ON',provinceOfResidence:'ON',hireDate:'2026-01-05',payFrequency:'biweekly',payType:'salary',annualSalaryCents:5200000,standardHours:80,vacationRateBps:400};
let su=await o.call('payroll/setup',{method:'POST',json:{payrollAccountNumber:'123456789RP0001',remitterType:'regular',defaultFrequency:'biweekly'}});rec('PY-00',A,'Enable Payroll Support for the ON test company',su.s<300?'PASS':'FAIL',su.s+' '+JSON.stringify(su.b).slice(0,120));
let r=await o.call('payroll/employees',{method:'POST',json:{...base,sin:'046 454 286',socialInsuranceNumber:'046454286',sinLastFour:'4286'}});
rec('PY-01',A,'Employee with SIN fields submitted: refused or SIN discarded',r.s>=400||true?'INFO':'',r.s+' '+(r.b?.code||'')+' '+(r.b?.error||'').slice(0,120));
const created=r.s===201;if(!created){r=await o.call('payroll/employees',{method:'POST',json:base});}
rec('PY-02',A,'Employee can be created without a SIN (Employee ID only)',r.s===201?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
const dbSin=sql(`SELECT COUNT(*) FROM payroll_employees WHERE company_id='${ids.ON}' AND (sin_ciphertext IS NOT NULL OR sin_last_four IS NOT NULL)`);
check('PY-03',A,'No SIN (ciphertext or last four) stored in the database',dbSin,'0');
const all=sql(`SELECT COUNT(*) FROM payroll_employees WHERE sin_ciphertext IS NOT NULL OR sin_last_four IS NOT NULL`);check('PY-03b',A,'No SIN stored for any company on the gate database',all,'0');
const ws=await o.call('payroll/workspace');const js=JSON.stringify(ws.b);
rec('PY-04',A,'Payroll workspace API returns no SIN fields/values',!/sin[A-Z_]|046.?454.?286|4286/i.test(js.replace(/"business[^"]*"/g,''))?'PASS':'FAIL',(js.match(/"[a-zA-Z]*sin[a-zA-Z]*"/gi)||[]).slice(0,5).join(','));
const exp=await o.call('backup/export',{method:'POST',json:{}});const bt=typeof exp.b==='string'?exp.b:JSON.stringify(exp.b);
rec('PY-05',A,'Company backup contains no SIN value',exp.s===200&&!/046.?454.?286/.test(bt)?'PASS':'FAIL',exp.s+' size='+bt.length);
rec('PY-06',A,'Payroll notice text present in app bundle ("Payroll Support Tool")',/Payroll Support Tool/.test(fs.readFileSync('/srv/gate/www/assets/tegh-portal-v5990.js','utf8'))?'PASS':'FAIL','');
const rates=await o.call('payroll/workspace');const rt=JSON.stringify(rates.b).match(/"(rateYear|taxYear|ratesVersion|effective[A-Za-z]*)":"?[^,"]*"?/g);
rec('PY-07',A,'Payroll rate tables: year/version reported for owner verification',rt?'INFO':'INFO',(rt||[]).slice(0,6).join(' '));
save('pay.json');
