// R160: invitations follow who the person is. New → own workspace and/or access to companies; registered → access to
// more companies only; deactivated or deleted → no invitation, the platform owner restores the old login and Tegh
// emails a password link. And the Invitations & Access page in the browser. Results read from the database and mail.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import {S,sql,rec,need,save,mails} from './lib.mjs';
const A='R160';const OWNER='owner@gate.test',OPW='Gate!Owner#2026pw',PW='R160!Person#2026pw',PW2='R160!Restored#2026pw';const o=new S();await o.login(OWNER,OPW);const stamp=Date.now().toString(36);
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info||'')}catch(e){rec(id,A,title,'FAIL',e.message.replace(/\s+/g,' ').slice(0,300))}};
sql(`UPDATE account_invitations SET created_at=created_at-INTERVAL 3 HOUR`);sql('DELETE FROM invitation_attempts');
let n=0;const call=(json,s=o)=>s.call('platform/invitations',{method:'POST',company:false,json});
const inv=async(email,scope,assignments=[],s=o)=>{const before=mails().length;const r=await call({action:'create_account',email,scope,assignments,operationKey:'gate-r160-'+stamp+'-'+(++n)},s);return {r,code:mails().slice(before).find(m=>m.t.includes(email))?.t.match(/invitation code:\s*([A-Z0-9]{5}-[A-Z0-9]{5})/)?.[1]}};
const accept=async(email,code,pw=PW)=>{const x=new S();const d=await x.call('platform/invite-details',{method:'POST',company:false,json:{email,code}});const a=await x.call('platform/invite-accept',{method:'POST',company:false,json:{email,code,password:pw,confirmPassword:pw,acceptTerms:true,termsVersion:d.b?.invitation?.termsVersion,privacyVersion:d.b?.invitation?.privacyVersion}});return {d,a}};
const look=async(e,s=o)=>(await call({action:'lookup',email:e},s));
const uid=e=>sql(`SELECT id FROM users WHERE email='${e}'`);
const CO1=sql(`SELECT id FROM companies WHERE name LIKE 'Alpine Ledger Partners%' LIMIT 1`),CO2=sql(`SELECT id FROM companies WHERE name LIKE 'Bayview Trading%' LIMIT 1`);
const member=(e,c)=>sql(`SELECT CONCAT(role,'/',status) FROM company_members WHERE user_id='${uid(e)}' AND company_id='${c}'`);
const E1=`r160-new-${stamp}@gate.test`,E2=`r160-deact-${stamp}@gate.test`,E3=`r160-del-${stamp}@gate.test`;

await t('IN-01','A new email: the check says “new”; one invitation gives both their own workspace and access to a company; after accepting they have that access and can create their own company',async()=>{
  const l=await look(E1);const x=await inv(E1,'workspace',[{companyId:CO1,role:'viewer'}]);const acc=await accept(E1,x.code);
  const u=new S();await u.login(E1,PW);const own=await u.call('companies',{method:'POST',company:false,json:{name:'R160 Own '+stamp,province:'ON',country:'Canada',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'accounting',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',taxRegistered:false,onboarding:true}});
  return {ok:l.s===200&&l.b.status==='new'&&x.r.s===200&&!!x.code&&acc.a.s===200&&member(E1,CO1)==='viewer/active'&&own.s===201&&/own Tegh workspace/.test(mails().find(m=>m.t.includes(E1))?.t||''),info:`check ${l.b.status}; invitation ${x.r.s}; accepted ${acc.a.s}; access ${member(E1,CO1)}; own company ${own.s}`}});
await t('IN-02','A registered email: the check says “registered” with the companies they already have; an own-workspace invitation is refused; access to more companies works and is accepted with the current password',async()=>{
  const l=await look(E1);const ws=await inv(E1,'workspace');const dup=await inv(E1,'company',[{companyId:CO1,role:'editor'}]);const x=await inv(E1,'company',[{companyId:CO2,role:'editor'}]);const acc=await accept(E1,x.code);
  return {ok:l.b.status==='registered'&&l.b.memberOfCompanyIds?.includes(CO1)&&ws.r.s===409&&ws.r.b.code==='invitation_user_exists'&&dup.r.s===409&&x.r.s===200&&acc.d.b?.invitation?.existingUser===true&&acc.a.s===200&&member(E1,CO2)==='editor/active',
    info:`check ${l.b.status}, has company ${l.b.memberOfCompanyIds?.includes(CO1)}; own workspace ${ws.r.s} ${ws.r.b.code}; company they already have ${dup.r.s}; new company ${x.r.s}, accepted with current password ${acc.a.s}, access ${member(E1,CO2)}`}});
await t('IN-03','A deactivated login: the check says “deactivated” with the date; inviting is refused; Restore reactivates it with its company access and emails that it was previously deactivated, with a link that sets a new password',async()=>{
  const x=await inv(E2,'company',[{companyId:CO1,role:'viewer'}]);await accept(E2,x.code);await o.call('admin/users',{method:'POST',company:false,json:{action:'deactivate',userId:uid(E2)}});
  const l=await look(E2);const again=await inv(E2,'company',[{companyId:CO2,role:'viewer'}]);const before=mails().length;const r=await call({action:'restore_login',email:E2});
  const m=mails().slice(before).find(m=>m.t.includes(E2))?.t||'';const tok=m.match(/passwordReset=([A-Za-z0-9_-]+)/)?.[1];
  const set=await new S().call('auth/password-reset-complete',{method:'POST',company:false,json:{token:tok,newPassword:PW2}});const u=new S();const login=await u.login(E2,PW2).then(()=>'ok',e=>e.message.slice(0,60));
  return {ok:l.b.status==='deactivated'&&!!l.b.deactivatedAt&&again.r.s===409&&again.r.b.code==='invitation_user_deactivated'&&!again.code&&r.s===200&&r.b.was==='deactivated'&&/previously deactivated/.test(m)&&set.s===200&&login==='ok'&&member(E2,CO1)==='viewer/active',
    info:`check ${l.b.status} (${l.b.deactivatedAt}); invite ${again.r.s} ${again.r.b.code}; restore ${r.s}; email "previously deactivated" ${/previously deactivated/.test(m)}; new password ${set.s}; sign-in ${login}; access kept ${member(E2,CO1)}`}});
await t('IN-04','A deleted login: the check recognises the address from its fingerprint (“deleted”, with the date); inviting is refused; Restore puts the same login back (same id), emails that it was previously deleted, and the link sets a new password',async()=>{
  const x=await inv(E3,'workspace');await accept(E3,x.code);const oldId=uid(E3);
  await o.call('admin/users',{method:'POST',company:false,json:{action:'delete_login',userId:oldId,confirmationText:E3,password:OPW,reason:'Gate R160 delete then restore',overrideConfirmed:true,dataLossAccepted:true}});
  const gone=sql(`SELECT COUNT(*) FROM users WHERE email='${E3}'`);const l=await look(E3);const again=await inv(E3,'workspace');
  const before=mails().length;const r=await call({action:'restore_login',email:E3});const m=mails().slice(before).find(m=>m.t.includes(E3))?.t||'';const tok=m.match(/passwordReset=([A-Za-z0-9_-]+)/)?.[1];
  const set=await new S().call('auth/password-reset-complete',{method:'POST',company:false,json:{token:tok,newPassword:PW2}});const login=await new S().login(E3,PW2).then(()=>'ok',e=>e.message.slice(0,60));
  return {ok:gone==='0'&&l.b.status==='deleted'&&!!l.b.deletedAt&&again.r.s===409&&again.r.b.code==='invitation_user_deleted'&&r.s===200&&r.b.was==='deleted'&&uid(E3)===oldId&&/previously deleted/.test(m)&&set.s===200&&login==='ok',
    info:`address kept after deletion ${gone}; check ${l.b.status} (${l.b.deletedAt}); invite ${again.r.s} ${again.r.b.code}; restore ${r.s}; same login ${uid(E3)===oldId}; email "previously deleted" ${/previously deleted/.test(m)}; new password ${set.s}; sign-in ${login}`}});
await t('IN-05','Only the platform owner can check an email or restore a login; a company admin inviting a deleted address is told to ask Tegh support',async()=>{
  const adm=new S();await adm.login(E1,PW);const own=sql(`SELECT id FROM companies WHERE name='R160 Own ${stamp}'`);
  const l=await look(E3,adm),r=await call({action:'restore_login',email:E3},adm);
  const E4=`r160-del2-${stamp}@gate.test`;let x=await inv(E4,'workspace');await accept(E4,x.code);await o.call('admin/users',{method:'POST',company:false,json:{action:'delete_login',userId:uid(E4),confirmationText:E4,password:OPW,reason:'Gate R160 delete for admin check',overrideConfirmed:true,dataLossAccepted:true}});
  x=await inv(E4,'company',[{companyId:own,role:'viewer'}],adm);
  return {ok:l.s===403&&r.s===403&&x.r.s===409&&x.r.b.code==='invitation_user_deleted'&&/Tegh support/.test(x.r.b.error),info:`admin check ${l.s}; admin restore ${r.s}; admin invites a deleted address ${x.r.s} "${x.r.b.error}"`}});

// ---------- The page ----------
const shift=Number(process.env.GATE_CLOCK_SHIFT_SECONDS||0)*1000;
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true});if(shift>0){await ctx.clock.install({time:Date.now()-shift});await ctx.clock.resume()}
const p=await ctx.newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,160)));
await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]',OWNER);await p.fill('#sr-login-form input[name=password]',OPW);await p.click('#sr-login-form button[type=submit]');
await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await p.waitForTimeout(2500);
const check=async email=>{await p.fill('[data-r160-email] [name=email]',email);await p.click('[data-r160-email] button');await p.waitForSelector('[data-r160-result] .r160-state',{timeout:20000});await p.waitForTimeout(400);return (await p.locator('[data-r160-result]').innerText()).replace(/\s+/g,' ')};
await t('UI-01','Invitations & Access opens from Platform Owner Home with three tabs; a new email shows the two choices, and the company list opens only for “Work in my companies”; sending shows the result',async()=>{
  await p.evaluate(()=>TeghPortal.openPlatformAdmin());await p.getByRole('button',{name:'Invitations & Access'}).first().click();await p.waitForSelector('[data-r160-email]',{timeout:20000});
  const tabs=await p.$$eval('[data-r160-tab]',x=>x.map(b=>b.textContent.trim().replace(/\s+\d+$/,'')));
  const E5=`r160-ui-${stamp}@gate.test`;const text=await check(E5);const listHidden=await p.locator('[data-r160-share-list]').isHidden(),sendOff=await p.locator('[data-r160-send]').isDisabled();
  await p.check('[data-r160-own]');await p.check('[data-r160-share]');const listShown=await p.locator('[data-r160-share-list]').isVisible();
  await p.locator(`[data-invite-company][value="${CO1}"]`).check();await p.screenshot({path:'/srv/gate/ev/shots/r160-new.png'});
  await p.click('[data-r160-send]');await p.waitForSelector('.r160-done',{timeout:30000});const done=(await p.locator('.r160-done').innerText()).replace(/\s+/g,' ');
  const row=sql(`SELECT CONCAT(i.scope,'/',(SELECT GROUP_CONCAT(role) FROM account_invitation_companies WHERE invitation_id=i.id)) FROM account_invitations i WHERE i.email='${E5}'`);
  return {ok:tabs.join('|').toLowerCase()==='invite someone|sent invitations|sign-up & email'&&/New to Tegh/.test(text)&&listHidden&&sendOff&&listShown&&/Invitation sent/.test(done)&&row==='workspace/viewer',info:`tabs ${tabs.join(' | ')}; "${text.slice(0,60)}"; list hidden until chosen ${listHidden}, send off until a choice ${sendOff}; after sending "${done.slice(0,50)}"; stored ${row}`}});
await t('UI-02','A registered email shows “Already on Tegh”, no own-workspace choice, and marks the companies they already have',async()=>{
  await p.click('[data-r160-again]');const text=await check(E1);const own=await p.locator('[data-r160-own]').count(),has=await p.locator(`[data-invite-company][value="${CO1}"]`).isDisabled();
  await p.screenshot({path:'/srv/gate/ev/shots/r160-registered.png'});
  return {ok:/Already on Tegh/.test(text)&&own===0&&has&&/Already has access/.test(text),info:`"${text.slice(0,90)}"; own-workspace choice shown ${own}; company they have disabled ${has}`}});
await t('UI-03','A deleted email shows “Registered before · login deleted” and only Restore; Restore works from the page and offers to give access next',async()=>{
  const E6=`r160-uidel-${stamp}@gate.test`;let x=await inv(E6,'workspace');await accept(E6,x.code);await o.call('admin/users',{method:'POST',company:false,json:{action:'delete_login',userId:uid(E6),confirmationText:E6,password:OPW,reason:'Gate R160 page restore',overrideConfirmed:true,dataLossAccepted:true}});
  await p.evaluate(()=>TeghPortal.openPlatformAdmin());await p.getByRole('button',{name:'Invitations & Access'}).first().click();await p.waitForSelector('[data-r160-email]',{timeout:20000});
  const text=await check(E6);const sendCount=await p.locator('[data-r160-send]').count();await p.screenshot({path:'/srv/gate/ev/shots/r160-deleted.png'});
  await p.click('[data-r160-restore]');const cont=p.getByRole('button',{name:'Restore',exact:true});await cont.waitFor({timeout:10000});await cont.click();
  await p.waitForSelector('.r160-done',{timeout:30000});const done=(await p.locator('.r160-done').innerText()).replace(/\s+/g,' ');await p.screenshot({path:'/srv/gate/ev/shots/r160-restored.png'});
  await p.click('[data-r160-share-now]');await p.waitForSelector('[data-r160-result] .r160-state',{timeout:20000});const next=(await p.locator('[data-r160-result] .r160-state').innerText()).replace(/\s+/g,' ');
  return {ok:/login deleted/i.test(text)&&sendCount===0&&/Login restored/.test(done)&&/previously deleted/.test(done)&&/Already on Tegh/.test(next)&&sql(`SELECT active FROM users WHERE email='${E6}'`)==='1',info:`"${text.slice(0,70)}"; invitation button ${sendCount}; "${done.slice(0,90)}"; then "${next.slice(0,40)}"`}});
await t('UI-04','Sent invitations and Sign-up & email tabs show the list and the settings; no page errors on a phone width',async()=>{
  await p.click('[data-r160-tab="sent"]');await p.waitForTimeout(400);const rows=await p.locator('.r160-sent-row').count();await p.screenshot({path:'/srv/gate/ev/shots/r160-sent.png'});
  await p.click('[data-r160-tab="settings"]');await p.waitForTimeout(400);const reg=await p.locator('[data-registration-form]').isVisible();
  await p.setViewportSize({width:390,height:844});await p.click('[data-r160-tab="invite"]');await p.waitForTimeout(600);const overflow=await p.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);await p.screenshot({path:'/srv/gate/ev/shots/r160-phone.png'});
  return {ok:rows>=3&&reg&&overflow<=1&&!errs.length,info:`sent rows ${rows}; settings visible ${reg}; phone overflow ${overflow}px; page errors ${errs.length?errs.join(' | '):'none'}`}});
await b.close();
save('r160.json');
