// Adversarial security tests for auth/session, client-viewing links and payroll. Each fires a real attack at the
// isolated gate host and asserts Tegh refuses it. These complement the happy-path coverage in 06-sec/07-cv/08-pay.
import {S,sql,need,rec,save,mails} from './lib.mjs';
import {execFileSync} from 'child_process';
const A='SEC2';
execFileSync('mysql',['tegh_gate','-e','DELETE FROM login_attempts']);
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL','error: '+String(e.message).slice(0,300))}};
const PW='Gate!Owner#2026pw',EMAIL='owner@gate.test';

/* ---------------- auth / session ---------------- */
await t('AS-01','The session cookie is set HttpOnly + Secure + SameSite=Strict (not stealable by JS, not sent cross-site)',async()=>{
  const s=new S();const r=await s.call('auth/login',{method:'POST',json:{email:EMAIL,password:PW},company:false,raw:true});
  const cookies=(r.headers.getSetCookie?.()||[]);const sess=cookies.find(c=>/tegh_session=/.test(c)&&!/_csrf=/.test(c))||'';
  const csrf=cookies.find(c=>/_csrf=/.test(c))||'';
  const ok=/HttpOnly/i.test(sess)&&/Secure/i.test(sess)&&/SameSite=Strict/i.test(sess)&&/Secure/i.test(csrf)&&/SameSite=Strict/i.test(csrf);
  return {ok,info:`session "${sess.replace(/=[^;]+/,'=<v>').slice(0,80)}"; csrf flags secure=${/Secure/i.test(csrf)} strict=${/SameSite=Strict/i.test(csrf)}`}});

await t('AS-02','A wrong password is refused and sets no session cookie (no partial auth)',async()=>{
  const s=new S();const r=await s.call('auth/login',{method:'POST',json:{email:'nobody-'+Date.now()+'@gate.test',password:'wrong'},company:false,raw:true});
  const gotCookie=(r.headers.getSetCookie?.()||[]).some(c=>/tegh_session=[^;]+/.test(c)&&!/tegh_session=;/.test(c));
  return {ok:[401,403,422,429].includes(r.status)&&!gotCookie,info:`status ${r.status}; session cookie issued ${gotCookie}`}});

await t('AS-03','An authenticated write with a forged/garbage session cookie is unauthenticated (401), not honoured',async()=>{
  const s=new S();s.c['tegh_session']='forged'+'a'.repeat(40);s.c['tegh_session_csrf']='forged';s.csrf='forged';
  const r=await s.call('companies',{method:'GET'});
  return {ok:r.s===401,info:'status '+r.s}});

await t('AS-04','Logout invalidates the session server-side: the same cookie no longer works (401)',async()=>{
  const s=new S();await s.login(EMAIL,PW);const before=(await s.call('companies')).s;
  await s.call('auth/logout',{method:'POST',json:{}});const after=(await s.call('companies')).s;
  return {ok:before===200&&after===401,info:`before ${before}; after logout ${after}`}});

await t('AS-05','Session and CSRF tokens are stored hashed at rest: the raw cookie value is not found in the sessions table',async()=>{
  const s=new S();await s.login(EMAIL,PW);const raw=s.c['tegh_session'];
  const hit=sql(`SELECT COUNT(*) FROM sessions WHERE token_hash='${raw.replace(/'/g,"")}' OR csrf_hash='${raw.replace(/'/g,"")}'`);
  return {ok:hit==='0'&&!!raw,info:`raw cookie present ${!!raw}; rows matching raw value in sessions ${hit} (0 = stored hashed)`}});

/* ---------------- client-viewing links ---------------- */
const o=new S();await o.login(EMAIL,PW);
o.cid=sql("SELECT id FROM companies WHERE name LIKE 'R163SEC Victim%' OR name LIKE '%Guided%' ORDER BY created_at DESC LIMIT 1");
const link=await o.call('client-view/links',{method:'POST',json:{clientName:'Sec Client',clientEmail:'secclient@gate.test',reports:['profit-loss'],expiresInDays:7,sendEmail:true}});
const token=new URLSearchParams(String(link.b?.link?.url||'').split('#')[1]||'').get('t')||'';
const readCode=()=>{const m=mails().filter(x=>/secclient@gate.test/.test(x.t)&&/\d{6}/.test(x.t)).at(-1);return m?.t.match(/(\d{6})/)?.[1]};

await t('CV2-01','A client cannot pull a report outside the link\'s allowlist (shared: profit-loss; requested: balance-sheet) -> 403',async()=>{
  const c=new S();await c.call('client-view/start',{method:'POST',json:{token},company:false});const code=readCode();
  const v=await c.call('client-view/verify',{method:'POST',json:{token,code},company:false});
  const shared=await c.call('client-view/report?key=profit-loss',{company:false});
  const forbidden=await c.call('client-view/report?key=balance-sheet',{company:false});
  const payroll=await c.call('client-view/report?key=payroll-register',{company:false});
  return {ok:v.s===200&&shared.s===200&&forbidden.s===403&&payroll.s===403,info:`signin ${v.s}; shared ${shared.s}; not-shared ${forbidden.s}; payroll ${payroll.s}`}});

await t('CV2-02','Five wrong 6-digit codes lock the code (429); a 6-digit code cannot be brute-forced',async()=>{
  const c=new S();await c.call('client-view/start',{method:'POST',json:{token},company:false});const real=readCode();
  const wrong=real==='000000'?'000001':'000000';const codes=[];
  for(let i=0;i<6;i++)codes.push((await c.call('client-view/verify',{method:'POST',json:{token,code:wrong},company:false})).s);
  // 5 wrong tries are refused (422 with tries-left), the 6th is locked out (429). 5 guesses / 10 min against 10^6 codes.
  return {ok:codes.slice(0,5).every(s=>s===422)&&codes[5]===429,info:codes.join(',')}});

await t('CV2-03','A client-view session cookie cannot call an authenticated company API (it is not a user session) -> 401',async()=>{
  const c=new S();await c.call('client-view/start',{method:'POST',json:{token},company:false});const code=readCode();
  await c.call('client-view/verify',{method:'POST',json:{token,code},company:false});
  c.cid=o.cid;const r=await c.call('guided/mapping?monthId=anything');const w=await c.call('workspace/summary');
  return {ok:[401,403].includes(r.s)&&[401,403].includes(w.s),info:`guided/mapping ${r.s}; workspace ${w.s}`}});

await t('CV2-04','A revoked link cannot start a sign-in (410)',async()=>{
  const l2=await o.call('client-view/links',{method:'POST',json:{clientName:'Revoke Me',clientEmail:'revoke@gate.test',reports:['profit-loss'],expiresInDays:7,sendEmail:false}});
  const tok2=new URLSearchParams(String(l2.b?.link?.url||'').split('#')[1]||'').get('t')||'';const id2=l2.b?.link?.id||sql(`SELECT id FROM client_view_links WHERE client_email='revoke@gate.test' ORDER BY created_at DESC LIMIT 1`);
  await o.call('client-view/links/revoke',{method:'POST',json:{linkId:id2}});
  const r=await new S().call('client-view/start',{method:'POST',json:{token:tok2},company:false});
  return {ok:r.s===410,info:'start on revoked link '+r.s}});

/* ---------------- payroll ---------------- */
const P=new S();await P.login(EMAIL,PW);
const pc=need('pc',await P.call('companies',{method:'POST',json:{name:'SEC2 Payroll '+Date.now().toString(36),province:'ON',businessType:'corporation',taxRegistered:true,taxNumber:'123456789RT0001',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',chartTemplate:'default'},company:false}));
P.cid=pc.company?.id||pc.id;
await P.call('payroll/setup',{method:'POST',json:{payrollAccountNumber:'123456789RP0001',remitterType:'regular',defaultFrequency:'biweekly'}});

await t('PY2-01','A real SIN in an employee record is refused server-side (422), even disguised across fields',async()=>{
  const base={employeeNumber:'EMP-9001',firstName:'Sec',lastName:'Test',provinceOfEmployment:'ON',provinceOfResidence:'ON',hireDate:'2026-01-05',payFrequency:'biweekly',payType:'salary',annualSalaryCents:5200000,standardHours:80,vacationRateBps:400};
  const a=await P.call('payroll/employees',{method:'POST',json:{...base,sin:'046 454 286'}});
  const b=await P.call('payroll/employees',{method:'POST',json:{...base,employeeNumber:'046454286'}}); // SIN-shaped id
  return {ok:a.s===422&&a.b.code==='sin_not_accepted'&&b.s===422,info:`explicit SIN ${a.s}/${a.b.code}; SIN-shaped id ${b.s}/${b.b.code||''}`}});

await t('PY2-02','Finalizing a pay run without the server-checked review confirmation is refused (422), not just hidden in the UI',async()=>{
  const r=await P.call('payroll/finalize',{method:'POST',json:{runId:'anything',reviewConfirmed:false,verificationReference:'x'}});
  return {ok:r.s===422&&r.b.code==='payroll_review_confirmation_required',info:r.s+' '+(r.b.code||'')}});

await t('PY2-03','A pay run from another company cannot be finalized from this company (not found), even with review confirmed',async()=>{
  const foreign=sql(`SELECT id FROM payroll_runs ORDER BY created_at DESC LIMIT 1`)||'none';
  const r=await P.call('payroll/finalize',{method:'POST',json:{runId:foreign,reviewConfirmed:true,verificationReference:'ref'}});
  const posted=foreign==='none'?'n/a':sql(`SELECT status FROM payroll_runs WHERE id='${foreign}'`);
  return {ok:[404,409].includes(r.s)&&posted!=='verified'&&posted!=='approved',info:`foreign run ${foreign.slice(0,16)}; finalize ${r.s} ${r.b.code||''}; its status ${posted}`}});

save('sec2.json');
const results=(await import('./lib.mjs')).results.filter(r=>r.area===A);
console.log(`\n${results.filter(r=>r.status==='PASS').length}/${results.length} auth/client-view/payroll security checks passed`);
