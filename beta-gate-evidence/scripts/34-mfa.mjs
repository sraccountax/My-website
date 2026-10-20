// R164 (revised) two-step sign-in suite: emailed codes for everyone, "trust this device for 30 days", the optional
// authenticator app (QR code, recovery codes), the unverified-session gate, and secrets at rest. Synthetic users only.
import {S,sql,rec,save,need,mails,waitMail} from './lib.mjs';
import crypto from 'crypto';
import {execFileSync,execSync} from 'child_process';
const A='MFA';
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL','error: '+String(e.message).slice(0,300))}};
const q=v=>"'"+String(v).replace(/'/g,"''")+"'";
const tag=Date.now().toString(36);
const PW='R164!TwoStep#2026pw';
let OFF=0;try{OFF=Number(execSync("grep -hE 'env\\[FAKETIME\\]' /etc/php/8.3/fpm/pool.d/*.conf 2>/dev/null | grep -oE '\\-?[0-9]+' | head -1",{encoding:'utf8'}).trim()||0)}catch{}
function b32(s){const al='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';s=s.toUpperCase().replace(/[^A-Z2-7]/g,'');let bits=0,val=0,out=[];for(const ch of s){val=(val<<5)|al.indexOf(ch);bits+=5;if(bits>=8){bits-=8;out.push((val>>bits)&0xff)}}return Buffer.from(out)}
const step=()=>Math.floor((Date.now()/1000+OFF)/30);
function totp(secret,st=step()){const buf=Buffer.alloc(8);buf.writeBigUInt64BE(BigInt(st));const h=crypto.createHmac('sha1',b32(secret)).update(buf).digest();const o=h[h.length-1]&15;return String((((h[o]&127)<<24)|(h[o+1]<<16)|(h[o+2]<<8)|h[o+3])%1e6).padStart(6,'0')}
let lastStep=-1;const freshTotp=async s=>{while(step()<=lastStep)await new Promise(r=>setTimeout(r,1000));lastStep=step();return totp(s,lastStep)};
const phpHash=pw=>execFileSync('php',['-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',pw],{encoding:'utf8'}).trim();

function mkUser(label){
  const email=`r164-2s-${label}-${tag}@gate.test`,id='user_r164s'+label+tag;
  sql(`INSERT INTO users (id,email,display_name,password_hash,platform_role,account_plan,signup_source,terms_accepted_at,active) VALUES (${q(id)},${q(email)},'R164 two-step',${q(phpHash(PW))},'member','free_preview','public_signup',UTC_TIMESTAMP(),1)`);
  return {email,id};
}
// A raw sign-in (password only) returning the session and the emailed code, without completing the second step.
async function start(u,s=new S(),pw=PW){const since=mails().length;const r=await s.call('auth/login',{method:'POST',json:{email:u.email,password:pw},company:false});s.csrf=r.b?.csrfToken||s.csrf;
  const m=r.b?.mfaRequired&&r.b.emailSent?await waitMail(x=>x.t.includes(u.email)&&/sign-in code is \d{6}/.test(x.t),since):null;return {s,r,code:m?.t.match(/sign-in code is (\d{6})/)?.[1]||''}}
const verify=(s,body)=>s.call('auth/mfa/verify',{method:'POST',json:body,company:false});
const tdOf=s=>s.c.tegh_td;
async function trustedClient(u){const {s,code}=await start(u);await verify(s,{method:'email',code,rememberDevice:true});return tdOf(s)}
const loginWith=async(u,td,ua,pw=PW)=>{const s=new S();if(td)s.c.tegh_td=td;return (await s.call('auth/login',{method:'POST',json:{email:u.email,password:pw},company:false,headers:ua?{'User-Agent':ua}:{}})).b};

sql('DELETE FROM login_attempts');

await t('E-01','Every sign-in asks for a second step: the password alone gives an unverified session, a 6-digit code is emailed to a masked address, and only the email method is offered without an authenticator',async()=>{
  const u=mkUser('a');const {s,r,code}=await start(u);const blocked=await s.call('companies',{company:false});
  return {ok:r.s===200&&r.b.mfaRequired===true&&JSON.stringify(r.b.methods)==='["email"]'&&/^r\*+@gate\.test$/.test(r.b.emailHint)&&r.b.emailSent===true&&/^\d{6}$/.test(code)&&blocked.s===403&&blocked.b.code==='mfa_verification_required'&&!r.b.companies,
    info:`login ${r.s}; methods ${JSON.stringify(r.b.methods)}; hint ${r.b.emailHint}; code emailed ${/^\d{6}$/.test(code)}; app before code ${blocked.s} ${blocked.b.code}`}});

await t('E-02','The emailed code opens the session; it works once and only for the sign-in that asked for it',async()=>{
  const u=mkUser('b');const a=await start(u);const b=await start(u);
  const cross=await verify(b.s,{method:'email',code:a.code});
  const ok=await verify(a.s,{method:'email',code:a.code});const after=await a.s.call('companies',{company:false});
  const reuse=await verify(b.s,{method:'email',code:a.code});
  return {ok:cross.s===422&&ok.s===200&&after.s===200&&reuse.s===422,info:`code in another sign-in ${cross.s}; own sign-in ${ok.s}; app after ${after.s}; reuse ${reuse.s}`}});

await t('E-03','Wrong codes: five wrong entries withdraw the code (the right code then fails); a new code can be requested and works',async()=>{
  const u=mkUser('c');const {s,code}=await start(u);const wrong=String((Number(code)+1)%1e6).padStart(6,'0');const st=[];
  for(let i=0;i<5;i++)st.push((await verify(s,{method:'email',code:wrong})).s);
  const right=await verify(s,{method:'email',code});
  sql(`UPDATE user_email_otp SET created_at=created_at-INTERVAL 1 MINUTE WHERE user_id=${q(u.id)}`); // past the 30 s resend wait
  const since=mails().length;const re=await s.call('auth/mfa/email-send',{method:'POST',json:{},company:false});
  const m=await waitMail(x=>x.t.includes(u.email)&&/sign-in code is \d{6}/.test(x.t),since);const code2=m?.t.match(/sign-in code is (\d{6})/)?.[1];
  const ok=await verify(s,{method:'email',code:code2});
  return {ok:st.every(x=>x===422)&&right.s===422&&re.s===200&&ok.s===200,info:`wrong ${st.join(',')}; right code after 5 wrong ${right.s}; resend ${re.s}; new code ${ok.s}`}});

await t('E-04','An expired code (over 10 minutes) is refused',async()=>{
  const u=mkUser('d');const {s,code}=await start(u);sql(`UPDATE user_email_otp SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE user_id=${q(u.id)}`);
  const r=await verify(s,{method:'email',code});return {ok:r.s===422,info:`expired code ${r.s}`}});

await t('E-05','Resending within 30 seconds in the same sign-in waits; a second sign-in gets its own code at once; at most 10 codes per 15 minutes',async()=>{
  const u=mkUser('e');const a=await start(u);const quick=await a.s.call('auth/mfa/email-send',{method:'POST',json:{},company:false});
  const b=await start(u);
  for(let i=0;i<8;i++){const x=new S();await x.call('auth/login',{method:'POST',json:{email:u.email,password:PW},company:false})}
  const c=await new S().call('auth/login',{method:'POST',json:{email:u.email,password:PW},company:false});
  return {ok:quick.s===429&&quick.b.code==='mfa_email_wait'&&/^\d{6}$/.test(b.code)&&c.b.emailSent===false&&c.b.emailStatus==='mfa_email_rate_limited',
    info:`resend at once ${quick.s} ${quick.b.code}; second sign-in code ${/^\d{6}$/.test(b.code)}; 11th code ${c.b.emailStatus}`}});

await t('E-06','The code is stored only as a keyed hash',async()=>{
  const u=mkUser('f');const {code}=await start(u);const rows=sql(`SELECT code_hash FROM user_email_otp WHERE user_id=${q(u.id)}`);
  return {ok:rows.length>0&&!rows.includes(code)&&/^[a-f0-9]{64}$/.test(rows.split('\n')[0]),info:`stored ${rows.split('\n')[0].slice(0,12)}… (plain code present false)`}});

await t('T-01','"Trust this device": after a code with the box ticked, that browser signs in without a code; the cookie is HttpOnly, Secure, SameSite=Strict, 30 days; other browsers still get a code',async()=>{
  const u=mkUser('g');const {s,code}=await start(u);
  const raw=await fetch('https://gate.test/api/auth/mfa/verify',{method:'POST',headers:{'Content-Type':'application/json','Origin':'https://gate.test','Cookie':s.ck(),'X-CSRF-Token':s.csrf},body:JSON.stringify({method:'email',code,rememberDevice:true})});
  const sc=(raw.headers.getSetCookie?.()||[]).find(x=>x.startsWith('tegh_td='))||'';const td=sc.split(';')[0].slice(8);
  const r2=await loginWith(u,td),r3=await loginWith(u,null);
  const maxAge=Number(sc.match(/Max-Age=(\d+)/i)?.[1]||0);
  return {ok:raw.status===200&&/HttpOnly/i.test(sc)&&/Secure/i.test(sc)&&/SameSite=Strict/i.test(sc)&&Math.abs(maxAge-30*86400)<300&&!r2.mfaRequired&&!!r2.user&&r3.mfaRequired===true,
    info:`verify ${raw.status}; flags ${['HttpOnly','Secure','SameSite=Strict'].filter(f=>new RegExp(f,'i').test(sc)).join(',')}; max-age ${maxAge}s; trusted browser asked ${!!r2.mfaRequired}; other browser asked ${!!r3.mfaRequired}`}});

await t('T-02','A trust token does not work from another browser (user agent), for another account, after expiry, or when tampered with',async()=>{
  const u=mkUser('h'),v=mkUser('i');const td=await trustedClient(u);
  const otherUa=await loginWith(u,td,'Mozilla/5.0 other'),otherUser=await loginWith(v,td),tampered=await loginWith(u,td.slice(0,-2)+'xx');
  sql(`UPDATE user_trusted_devices SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE user_id=${q(u.id)}`);const expired=await loginWith(u,td);
  return {ok:[otherUa,otherUser,tampered,expired].every(b=>b.mfaRequired===true),info:`asked for a code: other browser ${otherUa.mfaRequired}, other account ${otherUser.mfaRequired}, tampered ${tampered.mfaRequired}, expired ${expired.mfaRequired}`}});

await t('T-03','Trust is withdrawn by a password change, an administrator MFA reset and "Forget trusted devices" (password needed)',async()=>{
  const u=mkUser('j');let td=await trustedClient(u);
  const s=new S();s.c.tegh_td=td;s.csrf=(await s.call('auth/login',{method:'POST',json:{email:u.email,password:PW},company:false})).b.csrfToken;
  const ch=await s.call('auth/change-password',{method:'POST',json:{currentPassword:PW,newPassword:PW+'x'},company:false});
  const afterPw=(await loginWith(u,td,null,PW+'x')).mfaRequired;sql(`UPDATE users SET password_hash=${q(phpHash(PW))} WHERE id=${q(u.id)}`);
  td=await trustedClient(u);const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
  const reset=await o.call('admin/users',{method:'POST',json:{action:'reset_mfa',userId:u.id,password:'Gate!Owner#2026pw'},company:false});const afterReset=(await loginWith(u,td)).mfaRequired;
  td=await trustedClient(u);const w=new S();w.c.tegh_td=td;w.csrf=(await w.call('auth/login',{method:'POST',json:{email:u.email,password:PW},company:false})).b.csrfToken;
  const noPw=await w.call('auth/mfa/devices',{method:'POST',json:{},company:false}),forget=await w.call('auth/mfa/devices',{method:'POST',json:{password:PW},company:false});
  const afterForget=(await loginWith(u,td)).mfaRequired;
  return {ok:ch.s===200&&afterPw===true&&reset.s===200&&afterReset===true&&noPw.s===401&&forget.s===200&&afterForget===true,info:`password change ${ch.s}, asked after ${afterPw}; admin reset ${reset.s}, asked ${afterReset}; forget without password ${noPw.s}, with ${forget.s}, asked ${afterForget}`}});

let secret='',authUser=null,authSession=null;
await t('A-01','The authenticator app is optional: setup needs the password and returns a QR-ready otpauth link; a correct code turns it on and returns 10 recovery codes',async()=>{
  authUser=mkUser('k');const {s,code}=await start(authUser);await verify(s,{method:'email',code});authSession=s;
  const st=need('st',await s.call('auth/mfa/status',{company:false}));
  const noPw=await s.call('auth/mfa/enroll-begin',{method:'POST',json:{},company:false});
  const beg=need('beg',await s.call('auth/mfa/enroll-begin',{method:'POST',json:{password:PW},company:false}));secret=beg.secret;
  const cf=need('cf',await s.call('auth/mfa/enroll-confirm',{method:'POST',json:{code:await freshTotp(secret)},company:false}));
  return {ok:st.enrolled===false&&JSON.stringify(st.methods)==='["email"]'&&noPw.s===401&&/^otpauth:\/\/totp\/Tegh%3A.+\?secret=[A-Z2-7]{32}&issuer=Tegh/.test(beg.otpauthUri)&&cf.recoveryCodes?.length===10,
    info:`before: enrolled ${st.enrolled}, methods ${JSON.stringify(st.methods)}; setup without password ${noPw.s}; otpauth ok ${/^otpauth:/.test(beg.otpauthUri)}; recovery codes ${cf.recoveryCodes?.length}`}});
await t('A-02','With an authenticator, sign-in offers email, authenticator and recovery; the authenticator code works once (replay refused)',async()=>{
  const {s,r}=await start(authUser);const c=await freshTotp(secret);const ok=await verify(s,{method:'totp',code:c});
  const {s:s2}=await start(authUser);const replay=await verify(s2,{method:'totp',code:c});
  return {ok:JSON.stringify(r.b.methods)==='["email","totp","recovery"]'&&ok.s===200&&replay.s===422,info:`methods ${JSON.stringify(r.b.methods)}; totp ${ok.s}; replay ${replay.s}`}});
await t('A-03','A recovery code works once and ends the account\'s other sessions and trusted devices',async()=>{
  const rc=need('rc',await authSession.call('auth/mfa/recovery-codes',{method:'POST',json:{password:PW},company:false})).recoveryCodes;
  const other=new S();const o=await start(authUser,other);await verify(other,{method:'email',code:o.code,rememberDevice:true});const td=tdOf(other);
  const {s}=await start(authUser);const ok=await verify(s,{method:'recovery',code:rc[0]});
  const otherAlive=(await other.call('auth/me',{company:false})).s;const {s:s2}=await start(authUser);const again=await verify(s2,{method:'recovery',code:rc[0]});
  const trustedAfter=(await loginWith(authUser,td)).mfaRequired;
  return {ok:ok.s===200&&otherAlive===401&&again.s===422&&trustedAfter===true,info:`recovery ${ok.s}; other session ${otherAlive}; reuse ${again.s}; trusted device asked again ${trustedAfter}`}});
await t('A-04','The person can remove the authenticator (password needed); sign-in then offers the emailed code only',async()=>{
  const {s,code}=await start(authUser);await verify(s,{method:'email',code});
  const noPw=await s.call('auth/mfa/disable',{method:'POST',json:{},company:false}),off=await s.call('auth/mfa/disable',{method:'POST',json:{password:PW},company:false});
  const {r}=await start(authUser);
  return {ok:noPw.s===401&&off.s===200&&JSON.stringify(r.b.methods)==='["email"]',info:`without password ${noPw.s}; remove ${off.s}; methods after ${JSON.stringify(r.b.methods)}`}});
await t('A-05','The authenticator secret is stored encrypted (the base32 key is not in the database)',async()=>{
  const u=mkUser('l');const {s,code}=await start(u);await verify(s,{method:'email',code});const beg=need('b',await s.call('auth/mfa/enroll-begin',{method:'POST',json:{password:PW},company:false}));
  const hex=sql(`SELECT HEX(secret_cipher) FROM user_mfa WHERE user_id=${q(u.id)}`);
  return {ok:hex.length>0&&!Buffer.from(hex,'hex').toString('latin1').includes(beg.secret),info:`cipher ${hex.length/2} bytes; plain key present false`}});

await t('G-01','Before the second step only sign-in routes answer; accounting, admin and settings routes are refused',async()=>{
  const u=mkUser('m');const {s}=await start(u);const routes=['companies','workspace','admin/users','auth/mfa/devices','platform/invitations'];const out=[];
  for(const r of routes)out.push((await s.call(r,{company:false})).s);const me=await s.call('auth/me',{company:false}),st=await s.call('auth/mfa/status',{company:false});
  return {ok:out.every(x=>x===403)&&me.s===200&&me.b.mfa?.verified===false&&st.s===200,info:`${routes.map((r,i)=>r+' '+out[i]).join(', ')}; me ${me.s} (verified ${me.b.mfa?.verified}); status ${st.s}`}});

await t('G-02','With only the password, adding an authenticator cannot replace the emailed code (setup, removal, recovery codes and trusted devices need a verified session)',async()=>{
  const u=mkUser('o');const {s}=await start(u);
  const beg=await s.call('auth/mfa/enroll-begin',{method:'POST',json:{password:PW},company:false});
  const cf=await s.call('auth/mfa/enroll-confirm',{method:'POST',json:{code:'123456'},company:false});
  const dis=await s.call('auth/mfa/disable',{method:'POST',json:{password:PW},company:false});
  const rc=await s.call('auth/mfa/recovery-codes',{method:'POST',json:{password:PW},company:false});
  const dv=await s.call('auth/mfa/devices',{method:'POST',json:{password:PW},company:false});
  const still=await s.call('companies',{company:false});const rows=sql(`SELECT COUNT(*) FROM user_mfa WHERE user_id=${q(u.id)}`);
  return {ok:[beg,cf,dis,rc,dv].every(r=>r.s===403&&r.b.code==='mfa_verification_required')&&still.s===403&&rows==='0',info:`setup ${beg.s}, confirm ${cf.s}, remove ${dis.s}, recovery codes ${rc.s}, devices ${dv.s}; app still blocked ${still.s}; authenticator rows ${rows}`}});

await t('U-01','Browser: the sign-in screen says "Check your email" with the masked address and the trust box; Account & Access shows a QR code for the optional authenticator',async()=>{
  const {chromium}=await import('/opt/node22/lib/node_modules/playwright/index.mjs');const shift=Number(process.env.GATE_CLOCK_SHIFT_SECONDS||0)*1000;
  const u=mkUser('n');sql(`INSERT INTO company_members (company_id,user_id,role,status) SELECT id,${q(u.id)},'viewer','active' FROM companies WHERE active=1 ORDER BY created_at LIMIT 1`);
  const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
  try{const ctx=await b.newContext({viewport:{width:1280,height:900},ignoreHTTPSErrors:true});if(shift>0){await ctx.clock.install({time:Date.now()-shift});await ctx.clock.resume()}
    const p=await ctx.newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,140)));
    globalThis.__teghMfaManual=true; // this check reads the screen and types the emailed code itself
    await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]',u.email);await p.fill('#sr-login-form input[name=password]',PW);
    const since=mails().length;await p.click('#sr-login-form button[type=submit]');await p.waitForSelector('#sr-mfa-form:not([hidden])',{timeout:30000});
    const title=(await p.textContent('#sr-mfa-form [data-mfa-title]')).trim(),hint=(await p.textContent('#sr-mfa-form [data-mfa-email-hint]')).trim(),trust=await p.isVisible('#sr-mfa-form input[name=rememberDevice]');
    const m=await waitMail(x=>x.t.includes(u.email)&&/sign-in code is \d{6}/.test(x.t),since);
    await p.fill('#sr-mfa-form input[name=code]',m.t.match(/sign-in code is (\d{6})/)[1]);await p.check('#sr-mfa-form input[name=rememberDevice]');await p.click('#sr-mfa-form [data-mfa-verify]');
    await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:60000});
    const trusted=(await ctx.cookies()).some(c=>c.name==='tegh_td'&&c.httpOnly&&c.secure);
    await p.waitForFunction(()=>document.querySelector('[data-srp-page]'),null,{timeout:60000});await p.waitForTimeout(2000);
    await p.evaluate(()=>TeghPortal.openAccount());
    try{await p.waitForSelector('[data-mfa-setup]',{timeout:30000})}catch(e){const d=await p.evaluate(()=>({card:document.querySelector('[data-mfa-card]')?.innerText?.slice(0,300)||'(no card)',h1:document.querySelector('h1')?.textContent}));throw new Error('no setup button: '+JSON.stringify(d))}await p.click('[data-mfa-setup]');
    await p.waitForSelector('dialog.tegh-stepup[open]',{timeout:10000});await p.fill('dialog.tegh-stepup input[name=password]',PW);await p.click('dialog.tegh-stepup button[type=submit]');
    await p.waitForSelector('[data-mfa-qr] svg path',{timeout:20000});
    const qr=await p.evaluate(()=>{const svg=document.querySelector('[data-mfa-qr] svg');return {box:svg.getAttribute('viewBox'),d:svg.querySelector('path').getAttribute('d').length,key:document.querySelector('[data-mfa-key]').textContent}});
    await p.locator('[data-mfa-card]').screenshot({path:'/srv/gate/ev/shots/r164-authenticator-qr.png'});
    // Decode the QR exactly as rendered on screen (rasterised in the page) and compare with the setup key.
    const img=await p.evaluate(async()=>{const svg=document.querySelector('[data-mfa-qr] svg');const r=svg.getBoundingClientRect();const xml=new XMLSerializer().serializeToString(svg);
      const im=new Image();im.src='data:image/svg+xml;charset=utf-8,'+encodeURIComponent(xml);await im.decode();const c=document.createElement('canvas');c.width=400;c.height=400;const g=c.getContext('2d');g.fillStyle='#fff';g.fillRect(0,0,400,400);g.drawImage(im,0,0,400,400);
      return {w:Math.round(r.width),h:Math.round(r.height),data:Array.from(g.getImageData(0,0,400,400).data)}});
    const {createRequire}=await import('module');const jsQR=createRequire(import.meta.url)('/srv/gate/t/node-test-deps/jsqr/dist/jsQR.js');
    const decoded=jsQR(Uint8ClampedArray.from(img.data),400,400)?.data||'';
    globalThis.__teghMfaManual=false;
    return {ok:title==='Check your email'&&/^r\*+@gate\.test$/.test(hint)&&trust&&trusted&&img.w>=180&&decoded.startsWith('otpauth://totp/Tegh')&&decoded.includes('secret='+qr.key.replace(/\s/g,''))&&/^[A-Z2-7 ]{32,}$/.test(qr.key)&&errs.length===0,
      info:`title "${title}"; hint ${hint}; trust box ${trust}; trusted-device cookie set ${trusted}; QR on screen ${img.w}x${img.h}px, decodes to the setup link with this key ${decoded.includes('secret='+qr.key.replace(/\s/g,''))}; key ${qr.key.slice(0,9)}…; page errors ${errs.length} ${errs[0]||''}`}}
  finally{await b.close()}});

save('mfa.json');
