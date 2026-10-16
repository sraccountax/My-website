// R164 MFA (TOTP) adversarial + functional suite. Drives the real two-step login, enrolment,
// verification, recovery codes, the unverified-session gate and tokens-at-rest against the gate host.
import {S,sql,rec,save,need} from './lib.mjs';
import crypto from 'crypto';
import {execSync} from 'child_process';
const A='MFA';
// The gate php-fpm pool runs under libfaketime; TOTP on the server uses that clock, so mirror it.
let CLOCK_OFFSET=0;
try{const m=execSync("grep -hE 'env\\[FAKETIME\\]' /etc/php/8.3/fpm/pool.d/*.conf 2>/dev/null | grep -oE '\\-?[0-9]+' | head -1",{encoding:'utf8'}).trim();if(m)CLOCK_OFFSET=Number(m)}catch{}
console.log('clock offset (server-real seconds):',CLOCK_OFFSET);
const EMAIL='owner@gate.test', PW='Gate!Owner#2026pw';
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL','error: '+String(e.message).slice(0,300))}};

// --- TOTP (RFC 6238) in JS, to mirror the server and drive the flow ---
function b32decode(s){const al='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';s=s.toUpperCase().replace(/[^A-Z2-7]/g,'');let bits=0,val=0,out=[];for(const ch of s){val=(val<<5)|al.indexOf(ch);bits+=5;if(bits>=8){bits-=8;out.push((val>>bits)&0xff)}}return Buffer.from(out)}
function totp(secret,step){const key=b32decode(secret);const buf=Buffer.alloc(8);buf.writeBigUInt64BE(BigInt(step));const hmac=crypto.createHmac('sha1',key).update(buf).digest();const off=hmac[hmac.length-1]&0x0f;const bin=((hmac[off]&0x7f)<<24)|((hmac[off+1]&0xff)<<16)|((hmac[off+2]&0xff)<<8)|(hmac[off+3]&0xff);return String(bin%1000000).padStart(6,'0')}
const curStep=()=>Math.floor((Date.now()/1000+CLOCK_OFFSET)/30);
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
// The server enforces replay protection (a TOTP step is single-use). Each success-expecting
// verification must therefore use a step the server has not seen yet, so wait past the last one.
let lastTotpStep=-1;
async function freshTotp(secret){while(curStep()<=lastTotpStep)await sleep(1000);const st=curStep();lastTotpStep=st;return totp(secret,st)}

// Fresh login that tolerates the MFA-challenge response shape.
async function freshLogin(){const s=new S();const r=await s.call('auth/login',{method:'POST',json:{email:EMAIL,password:PW},company:false});s.csrf=r.b?.csrfToken||'';return {s,r}}

let secret='';

await t('M-01','A privileged login with a correct password returns an MFA challenge, not a usable session (enrolment required on first run)',async()=>{
  const {r}=await freshLogin();
  return {ok:r.s===200&&r.b.mfaRequired===true&&r.b.mfaEnrollmentRequired===true&&!r.b.companies,info:`status ${r.s}; mfaRequired ${r.b.mfaRequired}; enrollmentRequired ${r.b.mfaEnrollmentRequired}; companies present ${!!r.b.companies}`}});

await t('M-02','An unverified session is blocked from protected routes with mfa_enrollment_required (403)',async()=>{
  const {s}=await freshLogin();const r=await s.call('companies',{company:false});
  return {ok:r.s===403&&r.b.code==='mfa_enrollment_required',info:`status ${r.s}; code ${r.b.code}`}});

await t('M-03','Enrolment begins only with the current password (step-up) and returns an otpauth secret',async()=>{
  const {s}=await freshLogin();
  const wrong=await s.call('auth/mfa/enroll-begin',{method:'POST',json:{password:'nope'},company:false});
  const ok=await s.call('auth/mfa/enroll-begin',{method:'POST',json:{password:PW},company:false});
  secret=ok.b?.secret||'';
  return {ok:wrong.s===401&&ok.s===200&&/^[A-Z2-7]{32}$/.test(secret)&&/^otpauth:\/\/totp\//.test(ok.b.otpauthUri||''),info:`wrong-pw ${wrong.s}; begin ${ok.s}; secret len ${secret.length}; uri ${(ok.b.otpauthUri||'').slice(0,40)}`}});

await t('M-04','Enrolment rejects an incorrect code (422 mfa_code_invalid)',async()=>{
  const {s}=await freshLogin();await s.call('auth/mfa/enroll-begin',{method:'POST',json:{password:PW},company:false});
  const r=await s.call('auth/mfa/enroll-confirm',{method:'POST',json:{code:'000000'},company:false});
  return {ok:r.s===422&&r.b.code==='mfa_code_invalid',info:`status ${r.s}; code ${r.b.code}`}});

let recoveryCodes=[];
await t('M-05','A correct code confirms enrolment, verifies the session and returns 10 one-time recovery codes + the full identity',async()=>{
  const {s}=await freshLogin();const beg=await s.call('auth/mfa/enroll-begin',{method:'POST',json:{password:PW},company:false});const sec=beg.b.secret;
  const r=await s.call('auth/mfa/enroll-confirm',{method:'POST',json:{code:await freshTotp(sec)},company:false});
  recoveryCodes=r.b?.recoveryCodes||[];secret=sec;
  const prot=await s.call('companies',{company:false});
  return {ok:r.s===200&&r.b.enabled===true&&recoveryCodes.length===10&&Array.isArray(r.b.companies)&&prot.s===200,
    info:`confirm ${r.s}; enabled ${r.b.enabled}; recovery ${recoveryCodes.length}; identity ${!!r.b.companies}; protected-after ${prot.s}`}});

await t('M-06','After enrolment, login is a two-step challenge for an *enrolled* account (mfaEnrolled true)',async()=>{
  const {r}=await freshLogin();
  return {ok:r.s===200&&r.b.mfaRequired===true&&r.b.mfaEnrolled===true,info:`status ${r.s}; mfaRequired ${r.b.mfaRequired}; enrolled ${r.b.mfaEnrolled}`}});

await t('M-07','The second step rejects a wrong TOTP (422) and blocks the app until a correct code (verification_required)',async()=>{
  const {s}=await freshLogin();
  const bad=await s.call('auth/mfa/verify',{method:'POST',json:{code:'111111'},company:false});
  const blocked=await s.call('companies',{company:false});
  return {ok:bad.s===422&&bad.b.code==='mfa_code_invalid'&&blocked.s===403&&blocked.b.code==='mfa_verification_required',
    info:`wrong-code ${bad.s}/${bad.b.code}; protected-while-unverified ${blocked.s}/${blocked.b.code}`}});

await t('M-08','A correct TOTP completes the second step and unlocks the app',async()=>{
  const {s}=await freshLogin();
  const v=await s.call('auth/mfa/verify',{method:'POST',json:{code:await freshTotp(secret)},company:false});
  const prot=await s.call('companies',{company:false});
  return {ok:v.s===200&&v.b.verified===true&&Array.isArray(v.b.companies)&&prot.s===200,info:`verify ${v.s}; verified ${v.b.verified}; protected ${prot.s}`}});

await t('M-09','A recovery code works as the second factor and is single-use (reuse is refused)',async()=>{
  const code=recoveryCodes[0];
  const a=await freshLogin();const v1=await a.s.call('auth/mfa/verify',{method:'POST',json:{code,recovery:true},company:false});
  const b=await freshLogin();const v2=await b.s.call('auth/mfa/verify',{method:'POST',json:{code,recovery:true},company:false});
  return {ok:v1.s===200&&v1.b.verified===true&&v2.s===422,info:`first-use ${v1.s}; reuse ${v2.s} (422 = single-use enforced)`}});

await t('M-10','TOTP replay is refused: the same code cannot be used for a second verification in the same step',async()=>{
  const code=await freshTotp(secret);const step=lastTotpStep;
  const a=await freshLogin();const v1=await a.s.call('auth/mfa/verify',{method:'POST',json:{code},company:false});
  const b=await freshLogin();const v2=await b.s.call('auth/mfa/verify',{method:'POST',json:{code},company:false});
  const stillSameStep=curStep()===step;
  return {ok:v1.s===200&&(stillSameStep?v2.s===422:true),info:`first ${v1.s}; replay ${v2.s}; same-step ${stillSameStep} (422 expected when same step)`}});

await t('M-11','Secrets at rest: the TOTP secret is stored encrypted (not the plaintext base32) and recovery codes are hashed',async()=>{
  const hit=sql(`SELECT COUNT(*) FROM user_mfa WHERE user_id=(SELECT id FROM users WHERE email='${EMAIL}') AND HEX(secret_cipher) LIKE '%${Buffer.from(secret).toString('hex').toUpperCase()}%'`);
  const plainRecovery=sql(`SELECT COUNT(*) FROM user_mfa_recovery_codes WHERE code_hash='${(recoveryCodes[1]||'x').replace(/[^A-Za-z0-9]/g,'').toUpperCase()}'`);
  const cipherPresent=sql(`SELECT LENGTH(secret_cipher) FROM user_mfa WHERE user_id=(SELECT id FROM users WHERE email='${EMAIL}')`);
  return {ok:hit==='0'&&plainRecovery==='0'&&Number(cipherPresent)>0,info:`plaintext secret in cipher column ${hit}; plaintext recovery stored ${plainRecovery}; cipher bytes ${cipherPresent} (0/0 = encrypted & hashed)`}});

await t('M-12','An enrolled account cannot turn MFA off while its role still requires it (409)',async()=>{
  const {s}=await freshLogin();await s.call('auth/mfa/verify',{method:'POST',json:{code:await freshTotp(secret)},company:false});
  const r=await s.call('auth/mfa/disable',{method:'POST',json:{password:PW},company:false});
  return {ok:r.s===409&&r.b.code==='mfa_required_for_role',info:`disable ${r.s}/${r.b.code} (must stay on for privileged role)`}});

save('mfa.json');
const results=(await import('./lib.mjs')).results.filter(r=>r.area===A);
console.log(`\n${results.filter(r=>r.status==='PASS').length}/${results.length} MFA checks passed`);
