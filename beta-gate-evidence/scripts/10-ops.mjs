import {S,sql,rec,save} from './lib.mjs';import fs from 'fs';const ids=JSON.parse(fs.readFileSync('ids.json'));const A='OPS';
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');o.cid=ids.BC;
const time=async(route,n=5)=>{const t=[];for(let i=0;i<n;i++){const a=performance.now();const r=await o.call(route);t.push(performance.now()-a);if(r.s!==200)return {route,status:r.s}}t.sort((a,b)=>a-b);return {route,median:Math.round(t[Math.floor(n/2)]),max:Math.round(t[n-1])}};
const out=[];for(const r of ['auth/me','workspace/summary','portal/trial-balance?start=2026-01-01&end=2026-09-30','portal/aging?type=receivable&asOf=2026-09-30','professional-output/v5980/tax-summary?start=2026-01-01&end=2026-09-30','professional-output/v5980/general-ledger?start=2026-01-01&end=2026-09-30','reports/v5600/financial?kind=profit-loss&reportKey=profit_loss&start=2026-01-01&end=2026-09-30','tax-codes','payroll/workspace'])out.push(await time(r));
fs.writeFileSync('/srv/gate/ev/perf.json',JSON.stringify(out,null,1));
rec('PF-01',A,'API median latency under 1.5 s for dashboard, reports and tax codes (gate host, small data set)',out.every(x=>x.median<1500)?'PASS':'FAIL',out.map(x=>x.route.split('?')[0]+' '+(x.median??x.status)+'ms').join(' | '));
// burst: 20 concurrent summary loads
const a=performance.now();const rs=await Promise.all(Array.from({length:20},()=>o.call('workspace/summary')));const el=Math.round(performance.now()-a);
rec('PF-02',A,'20 concurrent dashboard loads all succeed',rs.every(x=>x.s===200)?'PASS':'FAIL',`statuses ok=${rs.filter(x=>x.s===200).length}/20 in ${el} ms`);
const inc=fs.readFileSync('/srv/gate/sr-accountax-private/system-incidents.ndjson','utf8');
const leaks=[/Gate!Owner#2026pw/,/Gate!Member#2026pw/,/"password"\s*:/i,/X-CSRF-Token/i,/046.?454.?286/,/tegh_session=/];
rec('LG-01',A,'Incident log (private, outside web root) contains no passwords, session cookies, CSRF tokens or SINs',leaks.every(r=>!r.test(inc))?'PASS':'FAIL',leaks.filter(r=>r.test(inc)).map(String).join(','));
const al=fs.readFileSync('/srv/gate/apache-access.log','utf8');
rec('LG-02',A,'Web access log contains no client-view tokens or invitation tokens (tokens travel in the fragment/body)',!/client-view\.html#|accountSetup=[A-Za-z0-9_-]{20}/.test(al)?'PASS':'FAIL','');
const ae=fs.readFileSync('/srv/gate/apache-error.log','utf8');rec('LG-03',A,'PHP error log: warnings recorded (see evidence)',/PHP (Fatal|Parse)/.test(ae)?'FAIL':'INFO',(ae.match(/PHP (Warning|Notice|Deprecated)[^\n]{0,140}/g)||[]).slice(0,4).join(' | '));
const sup=await o.call('portal/support');rec('SP-01',A,'In-app support route responds',sup.s===200?'PASS':'FAIL',sup.s+' '+JSON.stringify(sup.b).slice(0,160));
const c=fs.readFileSync('/srv/gate/www/contact.html','utf8');rec('SP-02',A,'Public contact page offers a working contact path (form posts to /api/marketing)',/api\/marketing|data-demo-form|<form/.test(c)||/marketing/.test(fs.readFileSync('/srv/gate/www/assets/'+(fs.readdirSync('/srv/gate/www/assets').find(f=>/tegh-marketing-v\d+\.js$/.test(f))||'x'),'utf8'))?'INFO':'FAIL',(c.match(/mailto:[^"]+/g)||['no mailto']).join(','));
save('ops.json');
