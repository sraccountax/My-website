import {S,sql} from '../lib.mjs';import fs from 'fs';
const ids=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
const r=await o.call('companies',{method:'POST',company:false,json:{name:'E2E Workflow Ltd',province:'ON',country:'Canada',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',taxRegistered:true,taxNumber:'123456789RT0001',coaMode:'default'}});
const id=r.b?.company?.id;console.log(r.s,id);fs.writeFileSync('/srv/gate/t/ids.json',JSON.stringify({...ids,E2E:id}));
