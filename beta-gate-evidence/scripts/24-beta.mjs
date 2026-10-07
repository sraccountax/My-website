// Beta readiness: checks requested for the invitation-only beta that earlier suites did not cover directly.
// Security (files of another company, sign-up closed, removed member, revoked invitation, password reset, terms version),
// recovery (uploaded files restored byte for byte), accounting (repeated requests and clicks, every posted entry balances)
// and multi-company with different currencies. Expected values are worked out here, never with Tegh's code.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';import crypto from 'crypto';import {execFileSync} from 'child_process';
import {S,sql,rec,need,save,mails} from './lib.mjs';
const A='BETA';const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');const stamp=Date.now().toString(36);
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info||'')}catch(e){rec(id,A,title,'FAIL',e.message.replace(/\s+/g,' ').slice(0,300))}};
const as=id=>({headers:{'X-Company-Id':id},company:false});
const sha=buf=>crypto.createHash('sha256').update(buf).digest('hex');
const mkCo=async(name,extra={})=>need('co',await o.call('companies',{method:'POST',company:false,json:{name,province:'ON',country:'Canada',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'accounting',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',taxRegistered:false,coaMode:'default',...extra}})).company;
const acct=(cid,code)=>sql(`SELECT id FROM accounts WHERE company_id='${cid}' AND code='${code}'`);
const CA=await mkCo('Beta Alpha Studio '+stamp,{shortName:'BAS'}),CB=await mkCo('Beta Birch Works '+stamp,{shortName:'BBW'});
const pdf=fs.readFileSync('/srv/gate/t/conv/ix152/V1.pdf'),pdfSha=sha(pdf);
const upload=async(s,cid,route,fields,name='receipt.pdf')=>{const fd=new FormData();fd.append('file',new Blob([pdf],{type:'application/pdf'}),name);for(const [k,v] of Object.entries(fields))fd.append(k,v);return s.call(route,{method:'POST',body:fd,...as(cid)})};
const getBytes=async(s,cid,route)=>{const r=await s.call(route,{...as(cid),raw:true});return {s:r.status,buf:Buffer.from(await r.arrayBuffer())}};
// A member invited and signed up through the normal invitation flow (code read from the test mail sandbox).
let inviteN=0;const invite=async(cid,role,email)=>{const before=mails().length;const r=need('invite',await o.call('platform/invitations',{method:'POST',company:false,json:{action:'create_account',email,scope:'company',assignments:[{companyId:cid,role}],operationKey:'gate-beta-'+stamp+'-'+(++inviteN)}}));
  const mail=mails().slice(before).find(m=>m.t.includes(email));const code=mail?.t.match(/invitation code:\s*([A-Z0-9]{5}-[A-Z0-9]{5})/)?.[1];return {r,code}};
const accept=async(email,code,password)=>{const n=new S();const d=await n.call('platform/invite-details',{method:'POST',company:false,json:{email,code}});
  return {d,a:await n.call('platform/invite-accept',{method:'POST',company:false,json:{email,code,password,confirmPassword:password,acceptTerms:true,termsVersion:d.b?.invitation?.termsVersion,privacyVersion:d.b?.invitation?.privacyVersion}})}};
const PW='Beta!Tester#2026pw',PW2='Beta!Reset#2026pw';

// ---------- Security ----------
await t('BS-01','Public account creation is closed: registering without an invitation is refused',async()=>{const n=new S();
  const r=await n.call('auth/register',{method:'POST',company:false,json:{email:`walkin-${stamp}@gate.test`,displayName:'Walk In',password:PW,acceptTerms:true}});
  return {ok:r.s===403&&r.b?.code==='registration_closed'&&sql(`SELECT COUNT(*) FROM users WHERE email='walkin-${stamp}@gate.test'`)==='0',info:`${r.s} ${r.b?.code} "${r.b?.error}"`}});
let DOC,INV;
await t('BS-02','A Document Intake file of one company cannot be downloaded through another company or by a user without access; its own company gets the same bytes',async()=>{
  DOC=need('doc',await upload(o,CA.id,'native-ap-ar/document/upload',{documentType:'vendor_bill'},'beta-bill.pdf')).document.id;
  const own=await getBytes(o,CA.id,`native-ap-ar/document/file&documentId=${DOC}`),cross=await getBytes(o,CB.id,`native-ap-ar/document/file&documentId=${DOC}`);
  const other=new S();await other.login('otherowner@gate.test','Gate!Member#2026pw');const outsider=await getBytes(other,CA.id,`native-ap-ar/document/file&documentId=${DOC}`);
  return {ok:own.s===200&&sha(own.buf)===pdfSha&&cross.s>=400&&!cross.buf.includes(pdf.subarray(0,64))&&outsider.s>=400,info:`own ${own.s} same bytes ${sha(own.buf)===pdfSha}; via other company ${cross.s}; user without access ${outsider.s}`}});
await t('BS-03','An invoice attachment of one company cannot be downloaded through another company',async()=>{
  const c=need('cust',await o.call('customers',{method:'POST',...as(CA.id),json:{name:'Attach Client '+stamp,province:'ON',country:'Canada',paymentTerms:30}})).customer.id;
  INV=need('inv',await o.call('invoices',{method:'POST',...as(CA.id),json:{customerId:c,issueDate:'2026-09-10',dueDate:'2026-10-10',lines:[{description:'Design work',quantity:1,unitPriceCents:40000}],issue:true}})).invoice.id;
  const detail=need('detail',await o.call(`invoices/r20/detail&invoiceId=${INV}`,as(CA.id)));const revision=detail.revision??detail.invoice?.revision??0;
  const up=need('attach',await upload(o,CA.id,'invoices/r20/attachment-upload',{invoiceId:INV,expectedRevision:String(revision),operationKey:'beta-attach-'+stamp},'signed-quote.pdf'));
  const list=(await o.call(`invoices/r20/detail&invoiceId=${INV}`,as(CA.id))).b;const att=(list.attachments||list.invoice?.attachments||[])[0]?.id||up.attachment?.id;
  const own=await getBytes(o,CA.id,`invoices/r20/attachment-download&invoiceId=${INV}&attachmentId=${att}`),cross=await getBytes(o,CB.id,`invoices/r20/attachment-download&invoiceId=${INV}&attachmentId=${att}`);
  return {ok:!!att&&own.s===200&&sha(own.buf)===pdfSha&&cross.s>=400,info:`attachment ${att?'saved':'missing'}; own ${own.s} same bytes ${sha(own.buf)===pdfSha}; via other company ${cross.s}`}});
const memberEmail=`beta-member-${stamp}@gate.test`;let member;
await t('BS-04','A removed member loses access at once: the signed-in session is refused and signing in again gives no access to the company',async()=>{
  const {code}=await invite(CA.id,'editor',memberEmail);const {a}=await accept(memberEmail,code,PW);need('accept',a);
  member=new S();await member.login(memberEmail,PW);const before=await member.call('workspace/summary',as(CA.id));
  const mid=sql(`SELECT m.user_id FROM company_members m JOIN users u ON u.id=m.user_id WHERE u.email='${memberEmail}' AND m.company_id='${CA.id}'`);
  const rm=await o.call('platform/members',{method:'POST',...as(CA.id),json:{action:'remove',memberId:mid,operationKey:'beta-remove-'+stamp,confirmationText:'REMOVE'}});
  const after=await member.call('workspace/summary',as(CA.id));const again=new S();await again.login(memberEmail,PW);const later=await again.call('workspace/summary',as(CA.id));
  return {ok:before.s===200&&rm.s<300&&after.s>=400&&later.s>=400,info:`before ${before.s}; remove ${rm.s} ${rm.s>=300?JSON.stringify(rm.b).slice(0,120):''}; same session after ${after.s}; new sign-in ${later.s}`}});
await t('BS-05','A revoked invitation cannot be accepted',async()=>{const email=`beta-revoked-${stamp}@gate.test`;const {r,code}=await invite(CA.id,'viewer',email);
  const id=r.invitation?.id||r.accountSetup?.invitationId||sql(`SELECT id FROM account_invitations WHERE email='${email}' ORDER BY created_at DESC LIMIT 1`);
  const rv=await o.call('platform/invitations',{method:'POST',company:false,json:{action:'revoke',id,operationKey:'beta-revoke-'+stamp}});const {a}=await accept(email,code,PW);
  return {ok:rv.s<300&&a.s>=400&&sql(`SELECT COUNT(*) FROM users WHERE email='${email}'`)==='0',info:`revoke ${rv.s}; accept after revoke ${a.s} "${a.b?.error||''}"`}});
await t('BS-06','Password reset: the email carries a one-time link; the new password works, the old one and the link are refused afterwards, and earlier sessions end',async()=>{
  const email=`beta-reset-${stamp}@gate.test`;const {code}=await invite(CA.id,'viewer',email);need('accept',(await accept(email,code,PW)).a);
  const old=new S();await old.login(email,PW);const before=mails().length;
  const req=await new S().call('auth/password-reset-request',{method:'POST',company:false,json:{email}});const mail=mails().slice(before).find(m=>m.t.includes(email));
  const token=decodeURIComponent(mail?.t.match(/#passwordReset=([A-Za-z0-9%_.-]+)/)?.[1]||'');
  const done=await new S().call('auth/password-reset-complete',{method:'POST',company:false,json:{token,newPassword:PW2,confirmPassword:PW2}});
  const reuse=await new S().call('auth/password-reset-complete',{method:'POST',company:false,json:{token,newPassword:PW,confirmPassword:PW}});
  let newOk=false,oldOk=true;try{await new S().login(email,PW2);newOk=true}catch{}try{await new S().login(email,PW)}catch{oldOk=false}
  const session=await old.call('auth/me',{company:false});
  return {ok:req.s===200&&!!token&&done.s===200&&reuse.s>=400&&newOk&&!oldOk&&session.s===401,info:`request ${req.s}; link ${token?'in email':'missing'}; reset ${done.s}; reuse ${reuse.s}; new password ${newOk}; old password ${oldOk?'still works':'refused'}; earlier session ${session.s}`}});
await t('BS-07','Accepting an invitation records the terms and privacy versions shown on the published Terms and Privacy pages',async()=>{
  const page=async f=>(await (await fetch('https://gate.test/'+f)).text()).match(/document version:\s*(\d{4}-\d\d-\d\d)/i)?.[1];
  const terms=await page('terms.html'),privacy=await page('privacy.html');
  const row=sql(`SELECT CONCAT(ta.terms_version,'|',ta.privacy_version) FROM terms_acceptances ta JOIN users u ON u.id=ta.user_id WHERE u.email='${memberEmail}' ORDER BY ta.accepted_at DESC LIMIT 1`);
  return {ok:!!terms&&row===`${terms}|${privacy}`,info:`pages: terms ${terms}, privacy ${privacy}; recorded ${row}`}});

await t('BS-08','A deletion request ("Delete login"): the person can no longer sign in, and their email address is gone from the account, invitations, sent-email records and the platform log',async()=>{
  const email=`beta-erase-${stamp}@gate.test`;const {code}=await invite(CA.id,'viewer',email);need('accept',(await accept(email,code,PW)).a);
  const uid=sql(`SELECT id FROM users WHERE email='${email}'`);const before=sql(`SELECT (SELECT COUNT(*) FROM account_invitations WHERE email='${email}')+(SELECT COUNT(*) FROM outbound_emails WHERE recipient='${email}')+(SELECT COUNT(*) FROM platform_audit_log WHERE actor_email='${email}' OR metadata_json LIKE '%${email}%')`);
  const del=await o.call('admin/users',{method:'POST',company:false,json:{action:'delete_login',userId:uid,confirmationText:email,password:'Gate!Owner#2026pw',reason:'Beta tester asked for account deletion',overrideConfirmed:true,dataLossAccepted:true}});
  const left=sql(`SELECT (SELECT COUNT(*) FROM users WHERE email='${email}')+(SELECT COUNT(*) FROM account_invitations WHERE email='${email}')+(SELECT COUNT(*) FROM company_invitations WHERE email='${email}')+(SELECT COUNT(*) FROM outbound_emails WHERE recipient='${email}')+(SELECT COUNT(*) FROM platform_audit_log WHERE actor_email='${email}' OR metadata_json LIKE '%${email}%')+(SELECT COUNT(*) FROM platform_incident_log WHERE user_email='${email}')`);
  const row=sql(`SELECT CONCAT(display_name,'|',active,'|',deleted_at IS NOT NULL) FROM users WHERE id='${uid}'`),record=sql(`SELECT COUNT(*) FROM platform_audit_log WHERE action LIKE 'platform.user_login_deleted%' AND target_id='${uid}' AND metadata_json LIKE '%previousEmailSha256%'`);
  let signIn='refused';try{await new S().login(email,PW);signIn='still works'}catch{}
  return {ok:del.s===200&&Number(before)>0&&left==='0'&&row==='Deleted user|0|1'&&record==='1'&&signIn==='refused',info:`delete ${del.s}${del.s!==200?' '+JSON.stringify(del.b).slice(0,120):''}; records with the address before ${before}, after ${left}; account ${row}; deletion record keeps a hash only ${record==='1'}; sign-in ${signIn}`}});
await t('BS-10','Invitations are refused while the operator details are incomplete (a config without them reports every missing field)',async()=>{
  const cfg=fs.readFileSync('/srv/gate/sr-accountax-private/config.php','utf8').replace(/\n    'operator' => \[[^\n]*\n/,'\n');const tmp='/tmp/beta-no-operator-config.php';fs.writeFileSync(tmp,cfg);
  const out=execFileSync('php',['-r','require "/srv/gate/www/api/bootstrap.php"; require "/srv/gate/www/api/legal_r156.php"; echo json_encode(tegh_operator_missing());'],{encoding:'utf8',env:{...process.env,SR_ACCOUNTAX_CONFIG:tmp}});fs.unlinkSync(tmp);
  const missing=JSON.parse(out.trim().split('\n').pop());const src=fs.readFileSync('/srv/gate/www/api/invitations_v5980.php','utf8')+fs.readFileSync('/srv/gate/www/api/platform.php','utf8');const guarded=(src.match(/tegh_operator_require_complete\(\)/g)||[]).length;
  const live=await (await fetch('https://gate.test/api/index.php?route=public/operator')).json();
  return {ok:missing.length===8&&guarded>=2&&live.complete===true,info:`without operator config: ${missing.length} fields missing; invitation paths guarded ${guarded}; this host complete ${live.complete}`}});
// ---------- Recovery ----------
await t('BR-10','A company backup carries its uploaded files: restored into a new company, the Document Intake file and the invoice attachment come back byte for byte',async()=>{
  const res=await o.call('backup/export',{method:'POST',json:{},raw:true,...as(CA.id)});const buf=Buffer.from(await res.arrayBuffer());
  const fd=new FormData();fd.append('backup',new Blob([buf]),'beta.tegh');for(const [k,v] of [['companyName','Beta Alpha Restored '+stamp],['confirm','RESTORE'],['confirmation','RESTORE'],['mode','new']])fd.append(k,v);
  const r=await o.call('backup/restore',{method:'POST',body:fd,...as(CA.id)});const nid=r.b?.companyId;
  const doc=sql(`SELECT id FROM native_agent_documents WHERE company_id='${nid}' LIMIT 1`),inv=sql(`SELECT invoice_id,id FROM invoice_attachments WHERE company_id='${nid}' LIMIT 1`).split('\t');
  const f1=doc?await getBytes(o,nid,`native-ap-ar/document/file&documentId=${doc}`):{s:0,buf:Buffer.alloc(0)},f2=inv[1]?await getBytes(o,nid,`invoices/r20/attachment-download&invoiceId=${inv[0]}&attachmentId=${inv[1]}`):{s:0,buf:Buffer.alloc(0)};
  return {ok:res.status===200&&r.s<300&&f1.s===200&&sha(f1.buf)===pdfSha&&f2.s===200&&sha(f2.buf)===pdfSha,info:`export ${res.status} (${buf.length} bytes); restore ${r.s}; document ${f1.s} same ${sha(f1.buf)===pdfSha}; attachment ${f2.s} same ${sha(f2.buf)===pdfSha}`}});

// ---------- Accounting ----------
await t('BA-01','A payment sent twice with the same operation key (a repeated click or retry) is recorded once',async()=>{
  const bank=acct(CA.id,'1000');const body={type:'customer',documentId:INV,paymentDate:'2026-09-20',reference:'EFT twice',foreignAmountCents:10000,paymentAccountId:bank,operationKey:'beta-pay-'+stamp};
  const [a,b2]=await Promise.all([o.call('payments',{method:'POST',...as(CA.id),json:body}),o.call('payments',{method:'POST',...as(CA.id),json:body})]);const c=await o.call('payments',{method:'POST',...as(CA.id),json:body});
  const n=sql(`SELECT COUNT(*) FROM party_payments WHERE company_id='${CA.id}' AND reference='EFT twice'`),bal=sql(`SELECT balance_cents FROM invoices WHERE id='${INV}'`);
  return {ok:n==='1'&&bal==='30000',info:`responses ${a.s}/${b2.s}/${c.s}; payments recorded ${n}; invoice balance ${bal} (400.00 − 100.00 = 300.00)`}});
await t('BA-02','Every posted journal entry on the gate database balances (debits = credits), and each company\'s trial balance nets to zero',async()=>{
  const bad=sql(`SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.status='posted' GROUP BY je.id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) x`);
  const off=sql(`SELECT COUNT(*) FROM (SELECT je.company_id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.status='posted' GROUP BY je.company_id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) x`);
  const entries=sql(`SELECT COUNT(*) FROM journal_entries WHERE status='posted'`),companies=sql(`SELECT COUNT(DISTINCT company_id) FROM journal_entries WHERE status='posted'`);
  return {ok:bad==='0'&&off==='0'&&Number(entries)>0,info:`${entries} posted entries in ${companies} companies; unbalanced entries ${bad}; companies out of balance ${off}`}});

// ---------- Browser checks ----------
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true});const p=await ctx.newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,160)));
await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
const ready=()=>p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await ready();
const select=async ids=>{await p.evaluate(ids=>{localStorage.setItem('sr-accountax-company',ids[0]);localStorage.setItem('sr-accountax-companies',JSON.stringify(ids))},ids);await p.goto('https://gate.test/app.html');await ready();await p.waitForTimeout(800)};
await t('BA-03','Double-clicking Save and post on a new customer invoice creates one invoice',async()=>{await select([CB.id]);
  const c=need('cust',await o.call('customers',{method:'POST',...as(CB.id),json:{name:'Double Click Client '+stamp,province:'ON',country:'Canada',paymentTerms:30}})).customer.id;
  const before=Number(sql(`SELECT COUNT(*) FROM invoices WHERE company_id='${CB.id}'`));
  await p.evaluate(()=>TeghPortal.openDocumentEditor('customer',{kind:'invoice'}));await p.waitForSelector('[data-r151-form]',{timeout:20000});await p.waitForTimeout(800);
  await p.selectOption('[name=partyId]',c);await p.fill('[name=date]','2026-09-25');const row=p.locator('tr[data-r151-line]').first();await row.locator('[name=description]').fill('Double click test');await row.locator('[name=qty]').fill('1');await row.locator('[name=rate]').fill('123.45');
  await row.locator('[name=accountId]').selectOption(acct(CB.id,'4000')).catch(()=>{});await p.locator('[data-r151-save=post]').dblclick();await p.waitForTimeout(4500);await p.screenshot({path:'/srv/gate/ev/shots/beta-double-click.png'});
  const after=Number(sql(`SELECT COUNT(*) FROM invoices WHERE company_id='${CB.id}'`)),posted=sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${CB.id}' AND source_type='invoice' AND status='posted'`);
  return {ok:after-before===1&&posted==='1',info:`invoices created by a double-click: ${after-before}; posted invoice journals ${posted}`}});
await t('BM-01','Currencies: a company cannot be created with a non-CAD ledger; a USD invoice shows in USD on its line, and the combined totals add its CAD amount (USD 1,000.00 × 1.35 = CAD 1,350.00), never the USD figure',async()=>{
  const us=await o.call('companies',{method:'POST',company:false,json:{name:'Beta Cedar US '+stamp,province:'NY',country:'United States',businessType:'corporation',currency:'USD',accountingBasis:'accrual',moduleMode:'accounting',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',taxRegistered:false,coaMode:'default'}});
  const c=need('cust',await o.call('customers',{method:'POST',...as(CB.id),json:{name:'Boston Client '+stamp,province:'NY',country:'United States',paymentTerms:30}})).customer.id;
  need('usd',await o.call('currencies',{method:'POST',...as(CB.id),json:{currency:'USD',rateToBaseMicros:1350000,rateDate:'2026-09-15'}}));
  // USD 1,000.00 at 1.35 = CAD 1,350.00 (no tax for a customer outside Canada).
  need('usd invoice',await o.call('invoices',{method:'POST',...as(CB.id),json:{customerId:c,issueDate:'2026-09-15',dueDate:'2026-10-15',currency:'USD',exchangeRateMicros:1350000,issue:true,lines:[{description:'Export consulting',quantity:1,unitPriceCents:100000}]}}));
  await select([CA.id,CB.id]);await p.evaluate(()=>TeghPortal.openReport('Customer Invoice & Note Register'));await p.waitForTimeout(4500);
  const line=await p.evaluate(()=>{const tr=[...document.querySelectorAll('.srp-page [data-tegh-authoritative-report] tbody tr')].find(r=>/Boston Client/.test(r.innerText));return tr?tr.innerText.replace(/\s+/g,' '):''});
  const foot=await p.evaluate(()=>(document.querySelector('.srp-page:not([hidden])')?.innerText.match(/TOTAL\s+CAD\s+[\d,]+\.\d\d/g)||[]).map(x=>x.replace(/\s+/g,' ')).join(' / '));
  // Hand-worked: BAS invoice CAD 400.00 + the BBW double-click invoice CAD 123.45 (if BA-03 created it) + USD 1,000.00 × 1.35 = CAD 1,350.00.
  const extra=Number(sql(`SELECT COUNT(*) FROM invoices WHERE company_id='${CB.id}' AND total_cents=12345`))*123.45,want=(400+extra+1350).toLocaleString('en-CA',{minimumFractionDigits:2,maximumFractionDigits:2});
  return {ok:us.s===422&&/CAD/.test(us.b?.error||'')&&/BBW/.test(line)&&/USD 1,000\.00/.test(line)&&foot.includes(`TOTAL CAD ${want}`),info:`USD-ledger company refused (${us.s}); line: ${line.slice(0,120)}; totals: ${foot} (expected TOTAL CAD ${want})`}});
await t('BN-01','The private beta notice (config app.beta_notice) is visible in the top bar for every signed-in user (not covered by anything), readable in light and dark, and shows the full text when tapped',async()=>{await select([CA.id]);
  const note=p.locator('[data-r155-beta-notice]');await note.waitFor({timeout:15000});
  const look=()=>note.evaluate(el=>{const r=el.getBoundingClientRect(),pts=[[.5,.5],[.12,.5],[.88,.5]].map(([fx,fy])=>document.elementFromPoint(r.x+r.width*fx,r.y+r.height*fy)),top=pts.every(x=>x&&(x===el||el.contains(x)))?el:pts.find(x=>!(x===el||el.contains(x)));const c=s=>s.match(/\d+/g).slice(0,3).map(Number),L=([r,g,b])=>{const f=v=>(v/=255)<=.03928?v/12.92:((v+.055)/1.055)**2.4;return .2126*f(r)+.7152*f(g)+.0722*f(b)},cs=getComputedStyle(el),a=L(c(cs.color)),b=L(c(cs.backgroundColor));
    return {onTop:!!top&&(top===el||el.contains(top)),shown:el.innerText.trim(),full:el.getAttribute('aria-label'),contrast:(Math.max(a,b)+.05)/(Math.min(a,b)+.05),inView:r.width>20&&r.height>20&&r.y>=0&&r.y<80}});
  const light=await look();await p.screenshot({path:'/srv/gate/ev/shots/beta-notice-light.png'});await note.click();await p.waitForTimeout(600);const toastText=await p.evaluate(()=>[...document.querySelectorAll('.srp-toast,[role=status],[role=alert]')].map(x=>x.innerText).join(' | '));
  await p.locator('[data-theme-choice="dark"]').first().click();await p.waitForTimeout(800);const dark=await look();await p.screenshot({path:'/srv/gate/ev/shots/beta-notice-dark.png'});await p.locator('[data-theme-choice="light"]').first().click();await p.waitForTimeout(500);
  await p.setViewportSize({width:390,height:844});await p.waitForTimeout(800);const phone=await look();await p.screenshot({path:'/srv/gate/ev/shots/beta-notice-phone.png'});await p.setViewportSize({width:1440,height:900});
  const ok=[light,dark,phone].every(x=>x.onTop&&x.inView&&x.contrast>=4.5)&&/^Private beta: use sample data only\./.test(light.full)&&/may be reset/.test(toastText)&&/Private beta/.test(light.shown)&&phone.shown==='Beta';
  return {ok,info:`desktop "${light.shown}" visible ${light.onTop}; phone "${phone.shown}" visible ${phone.onTop}; contrast ${light.contrast.toFixed(1)}/${dark.contrast.toFixed(1)}:1; tap shows: ${/may be reset/.test(toastText)}`}});
await t('BS-09','Terms and Privacy pages: the operator, contacts, hosting, beta conditions, deletion procedure and first-party measurement are shown; an incomplete configuration shows a warning',async()=>{
  const read=async f=>{await p.goto('https://gate.test/'+f);await p.waitForTimeout(1500);return p.locator('main').innerText()};
  const terms=await read('terms.html');const privacy=await read('privacy.html');await p.screenshot({path:'/srv/gate/ev/shots/beta-privacy.png',fullPage:true});
  const needT=['Gate Test Operator Inc.','support@gate.test','Private beta','Invitation only','Free','Sample data only','Data may be reset','No guarantee of availability','Not for real filings or payroll','2026-10-07'];
  const needP=['Gate Test Operator Inc.','privacy@gate.test','Gate test host (local)','Test environment','Local SMTP sandbox','14 days','Access, correction and deletion','30 days','nine actions','no third-party analytics','2026-10-07'];
  const missT=needT.filter(x=>!terms.includes(x)),missP=needP.filter(x=>!privacy.toLowerCase().includes(x.toLowerCase()));
  await p.route(/route=public\/operator/,r=>r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({operator:{legal_name:'',address:'',privacy_email:'',support_email:'',hosting_provider:'',data_location:'',backup_location:'',mail_provider:'',backup_retention_days:14,deletion_response_days:30},complete:false,missing:['Legal name of the business operating Tegh','Privacy contact email']})}));
  await p.goto('https://gate.test/privacy.html');await p.waitForTimeout(1500);const banner=await p.locator('[data-operator-status]').isVisible()?await p.locator('[data-operator-status]').innerText():'';await p.unroute(/route=public\/operator/);
  await p.goto('https://gate.test/app.html');await ready();
  return {ok:!missT.length&&!missP.length&&/incomplete/.test(banner)&&/Legal name/.test(banner),info:`terms missing: ${missT.join(', ')||'none'}; privacy missing: ${missP.join(', ')||'none'}; incomplete warning: "${banner.slice(0,110)}"`}});
rec('BETA-JS',A,'No page errors during the beta checks',errs.length?'FAIL':'PASS',errs.slice(0,3).join(' | '));
await b.close();save('beta.json');
