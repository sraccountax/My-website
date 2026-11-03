import {S,sql,rec,check,save,need,mails} from './lib.mjs';import fs from 'fs';
const A='H-MAIL';const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
const mk=async(name,province)=>{const r=need('company',await o.call('companies',{method:'POST',json:{name,province,businessType:'corporation',taxRegistered:true,taxNumber:'123456789RT0001',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',chartTemplate:'default'},company:false}));return r.company?.id||r.companyId||r.id};
const ids={};for(const [k,n,p] of [['BC','Gate Test BC Ltd','BC'],['QC','Gate Test QC Inc','QC'],['ON','Gate Test ON Corp','ON'],['X','Gate Other Firm Ltd','AB']])ids[k]=await mk(n,p);
fs.writeFileSync('/srv/gate/t/ids.json',JSON.stringify(ids));console.log(ids);
// mail delivery test
o.cid=ids.BC;let r=await o.call('platform/mail-status',{method:'POST',json:{recipient:'owner@gate.test'}});
const m1=mails().at(-1);
rec('H-M01',A,'SMTP delivery test (STARTTLS + AUTH) reaches sandbox',r.s===200&&r.b.delivery?.sent&&m1&&/X-Sandbox-Tls: True/.test(m1.t)&&/X-Sandbox-Auth: True/.test(m1.t)?'PASS':'FAIL',r.s+' sent='+r.b.delivery?.sent+' outcome='+r.b.delivery?.outcome+' mailfile='+(m1?.f||'none')+' '+(m1?m1.t.split('\n').slice(0,3).join(' / '):''));
r=await o.call('platform/mail-status',{method:'POST',json:{recipient:'someone@else.test'}});check('H-M02',A,'Test email to non-owner address refused',r.s,403);
save('install.json');
