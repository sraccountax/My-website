import {S,sql,rec,save} from './lib.mjs';import fs from 'fs';const ids=JSON.parse(fs.readFileSync('ids.json'));const A='SEC';const pw='Gate!Member#2026pw';
const other=new S();await other.login('otherowner@gate.test',pw);
let r=await other.call('workspace/summary',{headers:{'X-Company-Id':ids.BC},company:false});
rec('SI-01',A,'Other-company admin: workspace summary with X-Company-Id of BC refused',r.s===403&&!/Gate Test BC/.test(JSON.stringify(r.b))?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await new S().call('workspace/summary',{headers:{'X-Company-Id':ids.BC},company:false});rec('SI-13',A,'Anonymous API request refused (401)',r.s===401?'PASS':'FAIL',String(r.s));
const s2=new S();await s2.login('editor@gate.test',pw);s2.cid=ids.BC;const before=await s2.call('workspace/summary');const saved=s2.ck();
await s2.call('auth/logout',{method:'POST',json:{}});const s3=new S();s3.c=Object.fromEntries(saved.split('; ').map(x=>x.split('=')));s3.cid=ids.BC;
r=await s3.call('workspace/summary');rec('SE-01',A,'After logout the old session cookie is rejected',before.s===200&&r.s===401?'PASS':'FAIL',before.s+' -> '+r.s);
save('sec.json');
