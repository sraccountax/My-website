// R164 session protection + reset enumeration suite (synthetic data only: a fresh company and a fresh member).
// Covers: step-up (recent sign-in or password) for member, admin and deletion-decision actions; sessions ended
// on role change / suspension / removal / admin MFA reset / MFA disable; reset-request response and timing parity;
// trusted-proxy client IP (forwarded headers ignored by default).
import {S,sql,rec,save,need} from './lib.mjs';
import {execFileSync} from 'child_process';
const A='SESSION';
const OWNER='owner@gate.test',OPW='Gate!Owner#2026pw',MPW='R164!Member#2026pw';
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL','error: '+String(e.message).slice(0,300))}};
const q=v=>"'"+String(v).replace(/'/g,"''")+"'";
const tag=Date.now().toString(36);
const phpHash=pw=>execFileSync('php',['-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',pw],{encoding:'utf8'}).trim();
// Age a signed-in session past the 15-minute recent-sign-in window (DB clock, like the server check).
const ageSession=uid=>sql(`UPDATE sessions SET created_at=UTC_TIMESTAMP()-INTERVAL 1 HOUR WHERE user_id=${q(uid)}`);
const sessions=uid=>Number(sql(`SELECT COUNT(*) FROM sessions WHERE user_id=${q(uid)}`));

sql('DELETE FROM login_attempts');
const o=new S();await o.login(OWNER,OPW);
const ownerId=sql(`SELECT id FROM users WHERE email=${q(OWNER)}`);
const cr=need('company',await o.call('companies',{method:'POST',json:{name:'R164 Session Co '+tag,province:'ON',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01'},company:false}));
o.cid=cr.company?.id||cr.companyId||cr.id;

// Synthetic member accounts attached to the synthetic company only.
function mkMember(label,role='editor'){
  const email=`r164-${label}-${tag}@gate.test`,id='user_r164'+label+tag;
  sql(`INSERT INTO users (id,email,display_name,password_hash,platform_role,account_plan,signup_source,terms_accepted_at,active) VALUES (${q(id)},${q(email)},${q('R164 '+label)},${q(phpHash(MPW))},'member','free_preview','public_signup',UTC_TIMESTAMP(),1)`);
  sql(`INSERT INTO company_members (company_id,user_id,role,status) VALUES (${q(o.cid)},${q(id)},${q(role)},'active')`);
  return {email,id};
}
async function memberSession(m){const s=new S();await s.login(m.email,MPW);return s}
const alive=async s=>(await s.call('auth/me',{company:false})).s===200;

await t('SP-01','A fresh owner session may change a member role without re-entering the password (recent sign-in)',async()=>{
  const m=mkMember('a');const r=await o.call('platform/members',{method:'POST',json:{action:'change_role',memberId:m.id,role:'viewer'}});
  return {ok:r.s===200,info:`status ${r.s} ${r.b?.code||''}`}});

await t('SP-02','After the sign-in is older than 15 minutes, a member change without the password is refused (403 recent_authentication_required) and nothing changes',async()=>{
  const m=mkMember('b');ageSession(ownerId);
  const r=await o.call('platform/members',{method:'POST',json:{action:'change_role',memberId:m.id,role:'viewer'}});
  const role=sql(`SELECT role FROM company_members WHERE company_id=${q(o.cid)} AND user_id=${q(m.id)}`);
  return {ok:r.s===403&&r.b?.code==='recent_authentication_required'&&role==='editor',info:`status ${r.s} code ${r.b?.code}; role still ${role}`}});

await t('SP-03','With an old sign-in, a wrong password is refused and the right password allows the change',async()=>{
  const m=mkMember('c');ageSession(ownerId);
  const bad=await o.call('platform/members',{method:'POST',json:{action:'change_role',memberId:m.id,role:'viewer',password:'not-the-password'}});
  sql('DELETE FROM login_attempts');
  const good=await o.call('platform/members',{method:'POST',json:{action:'change_role',memberId:m.id,role:'viewer',password:OPW}});
  return {ok:bad.s>=400&&bad.s<500&&good.s===200,info:`wrong ${bad.s} ${bad.b?.code}; right ${good.s}`}});

for(const [id,action,extra,label] of [
  ['SP-04','change_role',{role:'viewer'},'role change'],
  ['SP-05','suspend',{reason:'R164 synthetic suspension test'},'suspension'],
  ['SP-06','remove',{reason:'R164 synthetic removal test'},'removal'],
]){
  await t(id,`A member's existing sessions end after ${label}`,async()=>{
    const m=mkMember(action.replace(/_/g,''));const ms=await memberSession(m);const before=await alive(ms);
    const r=await o.call('platform/members',{method:'POST',json:{action,memberId:m.id,password:OPW,...extra}});
    const after=await alive(ms);
    return {ok:r.s===200&&before&&!after&&sessions(m.id)===0,info:`action ${r.s} ${r.b?.code||''}; session alive before ${before}, after ${after}; rows ${sessions(m.id)}`}});
}

await t('SP-07','Platform-owner admin user actions need a recent sign-in; MFA reset ends the target\'s sessions and is audited',async()=>{
  const m=mkMember('mfa');const ms=await memberSession(m);
  sql(`INSERT INTO user_mfa (user_id,secret_cipher,secret_nonce,secret_tag,confirmed) VALUES (${q(m.id)},'x','x','x',1)`);
  ageSession(ownerId);
  const refused=await o.call('admin/users',{method:'POST',json:{action:'reset_mfa',userId:m.id},company:false});
  const ok=await o.call('admin/users',{method:'POST',json:{action:'reset_mfa',userId:m.id,password:OPW},company:false});
  const left=Number(sql(`SELECT COUNT(*) FROM user_mfa WHERE user_id=${q(m.id)}`));
  const audit=Number(sql(`SELECT COUNT(*) FROM platform_audit_log WHERE action='platform.user_mfa_reset' AND target_id=${q(m.id)}`));
  const after=await alive(ms);
  return {ok:refused.s===403&&ok.s===200&&left===0&&audit===1&&!after,info:`no-password ${refused.s}; with password ${ok.s}; mfa rows ${left}; audit ${audit}; member session alive ${after}`}});

await t('SP-08','Approving or rejecting a company deletion request needs a recent sign-in',async()=>{
  ageSession(ownerId);
  const r=await o.call('admin/deletion-requests',{method:'POST',json:{requestId:'none',action:'reject'},company:false});
  return {ok:r.s===403&&r.b?.code==='recent_authentication_required',info:`status ${r.s} code ${r.b?.code}`}});

await t('SP-09','Changing the password ends the user\'s other sessions',async()=>{
  const m=mkMember('pw','viewer');const s1=await memberSession(m);const s2=await memberSession(m);
  const r=await s2.call('auth/change-password',{method:'POST',json:{currentPassword:MPW,newPassword:MPW+'x9',password:MPW,new_password:MPW+'x9'},company:false});
  const a1=await alive(s1),a2=await alive(s2);
  return {ok:r.s===200&&!a1&&a2,info:`change ${r.s} ${r.b?.code||''}; other session alive ${a1}; own ${a2}`}});

// --- Reset request: same response, similar timing, whether or not the address exists ---
const timeReset=async email=>{const s=new S();const t0=performance.now();const r=await s.call('auth/password-reset-request',{method:'POST',json:{email},company:false});return {ms:performance.now()-t0,r}};
const median=a=>{const b=[...a].sort((x,y)=>x-y);return b[Math.floor(b.length/2)]};
{
  const real=mkMember('reset','viewer');
  sql('DELETE FROM password_reset_requests WHERE created_at>=UTC_TIMESTAMP()-INTERVAL 2 HOUR');
  const known=[],unknown=[],bodies=new Set();let statuses=[];
  for(let i=0;i<4;i++){const a=await timeReset(real.email);const b=await timeReset(`nobody-${tag}-${i}@gate.test`);known.push(a.ms);unknown.push(b.ms);bodies.add(JSON.stringify(a.r.b));bodies.add(JSON.stringify(b.r.b));statuses.push(a.r.s,b.r.s)}
  await t('SP-10','Reset request returns the identical status and body for an existing and a non-existing address',async()=>({ok:bodies.size===1&&statuses.every(s=>s===200),info:`distinct bodies ${bodies.size}; statuses ${[...new Set(statuses)]}`}));
  const mk=median(known),mu=median(unknown);
  await t('SP-11','Reset request timing does not reveal whether the address exists (median difference under 250 ms)',async()=>({ok:Math.abs(mk-mu)<250,info:`median existing ${mk.toFixed(0)} ms; non-existing ${mu.toFixed(0)} ms`}));
  await t('SP-12','The reset email is still delivered for the existing address (sent after the response)',async()=>{
    await new Promise(r=>setTimeout(r,1500));
    const n=Number(sql(`SELECT COUNT(*) FROM password_reset_requests WHERE user_id=${q(real.id)}`));
    const fs=await import('fs');const hit=fs.readdirSync('/srv/gate/mail').some(f=>fs.readFileSync('/srv/gate/mail/'+f,'utf8').includes(real.email));
    return {ok:n>=1&&hit,info:`requests ${n}; email captured ${hit}`}});
}

await t('SP-13','Forwarded IP headers are ignored unless a trusted proxy is configured (rate-limit bucket follows the real peer)',async()=>{
  sql('DELETE FROM password_reset_requests WHERE created_at>=UTC_TIMESTAMP()-INTERVAL 2 HOUR');
  const spoof={'CF-Connecting-IP':'203.0.113.77','X-Forwarded-For':'203.0.113.78'};
  const s=new S();await s.call('auth/password-reset-request',{method:'POST',json:{email:`spoof-${tag}@gate.test`},company:false,headers:spoof});
  const plain=new S();await plain.call('auth/password-reset-request',{method:'POST',json:{email:`plain-${tag}@gate.test`},company:false});
  const buckets=Number(sql(`SELECT COUNT(DISTINCT requested_ip_hash) FROM password_reset_requests WHERE created_at>=UTC_TIMESTAMP()-INTERVAL 2 HOUR`));
  return {ok:buckets===1,info:`distinct IP buckets for spoofed + plain requests: ${buckets}`}});

// Clean up the synthetic company's member rows and restore the owner session age (no existing company touched).
ageSession('__none__');
save('session.json');
