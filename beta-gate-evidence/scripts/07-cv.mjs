import {S,sql,rec,check,save,mails} from './lib.mjs';import fs from 'fs';const ids=JSON.parse(fs.readFileSync('ids.json'));const A='CVL';
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');o.cid=ids.BC;
let r=await o.call('client-view/links',{method:'POST',json:{clientName:'Test Client Person',clientEmail:'client@gate.test',reports:['profit-loss','balance-sheet'],expiresInDays:7,sendEmail:true}});
const url=r.b?.link?.url||'';const token=url.split('#')[1]?.replace(/^.*?=/,'')||url.split('#')[1];const linkId=r.b?.link?.id;
const lm=mails().at(-1);
rec('CV-01',A,'Create viewing link; token only in URL fragment; link email delivered to client test inbox',r.s===201&&url.includes('#')&&/client@gate.test/.test(lm?.t||'')?'PASS':'FAIL',r.s+' url=https://gate.test/client-view.html#<token> email='+(r.b?.email?.sent));
const c=new S();
const code=()=>{const m=mails().filter(x=>/X-Rcpt: client@gate.test/.test(x.t)&&/viewing code/i.test(x.t)).at(-1);return m?.t.match(/is: (\d{6})/)?.[1]};
r=await c.call('client-view/start',{method:'POST',json:{token},company:false});const c1=code();
rec('CV-02',A,'Request code: code emailed to client address (masked in response)',r.s===200&&c1&&!JSON.stringify(r.b).includes('client@gate.test')?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,120));
r=await c.call('client-view/verify',{method:'POST',json:{token,code:c1==='000000'?'000001':'000000'},company:false});rec('CV-03',A,'Wrong code refused with tries left',r.s===422?'PASS':'FAIL',r.s+' '+r.b?.error);
r=await c.call('client-view/verify',{method:'POST',json:{token,code:c1},company:false});rec('CV-04',A,'Correct code signs in (Secure HttpOnly cookie)',r.s===200?'PASS':'FAIL',String(r.s));
r=await c.call('client-view/dashboard',{company:false});rec('CV-05',A,'Client dashboard loads summary only',r.s===200&&r.b.summary&&!('ledgerDebitsCents' in r.b.summary)?'PASS':'FAIL',r.s+' keys='+Object.keys(r.b.summary||{}).join(','));
r=await c.call('client-view/report?key=profit-loss&start=2026-01-01&end=2026-09-30',{company:false});rec('CV-06',A,'Shared report (P&L) loads',r.s===200?'PASS':'FAIL',String(r.s));
for(const k of ['general-ledger','trial-balance','payroll-runs','audit-history','ar-ageing']){r=await c.call('client-view/report?key='+k,{company:false});rec('CV-07-'+k,A,`Unshared report ${k} refused`,r.s===403?'PASS':'FAIL',String(r.s));}
r=await c.call('workspace/summary',{headers:{'X-Company-Id':ids.BC},company:false});rec('CV-08',A,'Client session cannot call the accountant API',r.s===401?'PASS':'FAIL',String(r.s));
r=await new S().call('client-view/verify',{method:'POST',json:{token,code:c1},company:false});rec('CV-09',A,'Used code cannot be reused',r.s>=400?'PASS':'FAIL',String(r.s));
// guessing: new code, 5 wrong attempts then lock
const g=new S();await g.call('client-view/start',{method:'POST',json:{token},company:false});const c2=code();let st=[];for(let i=0;i<6;i++){const w=String((+c2+1+i)%1000000).padStart(6,'0');st.push((await g.call('client-view/verify',{method:'POST',json:{token,code:w},company:false})).s)}
r=await g.call('client-view/verify',{method:'POST',json:{token,code:c2},company:false});rec('CV-10',A,'Guessing: after 5 wrong codes even the right code is refused',r.s===429?'PASS':'FAIL',st.join(',')+' then correct='+r.s);
let sc=[];for(let i=0;i<5;i++)sc.push((await g.call('client-view/start',{method:'POST',json:{token},company:false})).s);rec('CV-11',A,'Code requests limited to 5 per hour per link',sc.includes(429)?'PASS':'FAIL',sc.join(','));
// expiry of code
sql(`DELETE FROM client_view_codes WHERE link_id='${linkId}'`);const e=new S();await e.call('client-view/start',{method:'POST',json:{token},company:false});const c3=code();sql(`UPDATE client_view_codes SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 MINUTE WHERE link_id='${linkId}'`);
r=await e.call('client-view/verify',{method:'POST',json:{token,code:c3},company:false});rec('CV-12',A,'Expired code (10 min TTL) refused',r.s===410?'PASS':'FAIL',String(r.s));
// session expiry
sql(`UPDATE client_view_sessions SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 MINUTE WHERE link_id='${linkId}'`);r=await c.call('client-view/dashboard',{company:false});rec('CV-13',A,'Expired client session refused',r.s===401?'PASS':'FAIL',String(r.s));
// revoke
sql(`DELETE FROM client_view_codes WHERE link_id='${linkId}'`);const v=new S();await v.call('client-view/start',{method:'POST',json:{token},company:false});await v.call('client-view/verify',{method:'POST',json:{token,code:code()},company:false});const pre=(await v.call('client-view/dashboard',{company:false})).s;
await o.call('client-view/links/revoke',{method:'POST',json:{linkId}});r=await v.call('client-view/dashboard',{company:false});const r2=await new S().call('client-view/start',{method:'POST',json:{token},company:false});
rec('CV-14',A,'Revoking the link ends active sessions and blocks new codes',pre===200&&r.s===401&&r2.s>=400?'PASS':'FAIL',`before ${pre} after ${r.s} start ${r2.s}`);
r=await new S().call('client-view/start',{method:'POST',json:{token:'A'.repeat(43)},company:false});rec('CV-15',A,'Guessed/random token refused without revealing company',r.s>=400&&!/Gate/.test(JSON.stringify(r.b))?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,80));
// link expiry
r=await o.call('client-view/links',{method:'POST',json:{clientName:'Exp',clientEmail:'client@gate.test',reports:['profit-loss'],expiresInDays:7}});const t2=r.b.link.url.split('#')[1].replace(/^.*?=/,'');sql(`UPDATE client_view_links SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 MINUTE WHERE id='${r.b.link.id}'`);
r=await new S().call('client-view/start',{method:'POST',json:{token:t2},company:false});rec('CV-16',A,'Expired link refused',r.s>=400?'PASS':'FAIL',String(r.s));
save('sec.json');
