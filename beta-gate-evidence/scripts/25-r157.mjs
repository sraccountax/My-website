// R157: company onboarding (steps, lock, sign-in redirect, chart template, tax code import, Settings entry), the
// Tegh Assist tour and the bank-statement call to action, company time zone and accounting date, Quick Actions that
// follow the person (with keyboard keys), and the Beta labels with the Document Intake section on vendor invoices.
// Expected values are worked out here (rates, codes, dates from the pinned clock), never with Tegh's code.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
import {S,sql,rec,need,save,mails} from './lib.mjs';
const A='R157';const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');const stamp=Date.now().toString(36);
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info||'')}catch(e){rec(id,A,title,'FAIL',e.message.replace(/\s+/g,' ').slice(0,300))}};
const as=id=>({headers:{'X-Company-Id':id},company:false});
const base={province:'ON',country:'Canada',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'accounting',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',taxRegistered:false};
const mkCo=async(name,extra={})=>need('co',await o.call('companies',{method:'POST',company:false,json:{name,...base,...extra}})).company;
const shift=Number(process.env.GATE_CLOCK_SHIFT_SECONDS||0)*1000||Number(process.env.GATE_CLOCK_SHIFT_DAYS||0)*86400000;
const serverNow=()=>new Date(Date.now()-shift);
const dateIn=(zone,d=serverNow())=>{const v=Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(d).map(x=>[x.type,x.value]));return `${v.year}-${v.month}-${v.day}`};
let inviteN=0;const invite=async(email,scope,assignments=[])=>{const before=mails().length;need('invite',await o.call('platform/invitations',{method:'POST',company:false,json:{action:'create_account',email,scope,assignments,operationKey:'gate-r157-'+stamp+'-'+(++inviteN)}}));
  const code=mails().slice(before).find(m=>m.t.includes(email))?.t.match(/invitation code:\s*([A-Z0-9]{5}-[A-Z0-9]{5})/)?.[1];const n=new S();const d=await n.call('platform/invite-details',{method:'POST',company:false,json:{email,code}});
  const a=await n.call('platform/invite-accept',{method:'POST',company:false,json:{email,code,password:PW,confirmPassword:PW,acceptTerms:true,termsVersion:d.b?.invitation?.termsVersion,privacyVersion:d.b?.invitation?.privacyVersion}});if(a.s!==200)throw new Error('accept '+a.s+' '+JSON.stringify(a.b).slice(0,120));return n};
const PW='R157!Onboard#2026pw';

// ---------- Onboarding through the API ----------
let OC;
await t('ON-01','A company created with onboarding starts locked, with only the company step complete; its time zone is saved',async()=>{
  const r=await o.call('companies',{method:'POST',company:false,json:{name:'R157 Onboard '+stamp,...base,coaMode:'manual',onboarding:true,timezone:'America/Vancouver'}});OC=r.b.company;
  const st=r.b.onboarding,steps=st?.steps||{};const pending=Object.entries(steps).filter(([,v])=>v.status!=='complete').map(([k])=>k);
  const tz=sql(`SELECT timezone FROM companies WHERE id='${OC.id}'`),accounts=sql(`SELECT COUNT(*) FROM accounts WHERE company_id='${OC.id}' AND code<>'9999'`);
  return {ok:r.s===201&&st.required&&st.locked&&!st.complete&&steps.company?.status==='complete'&&pending.join()==='chart,tax,banks,imports,team'&&tz==='America/Vancouver'&&OC.timezone==='America/Vancouver'&&accounts==='0',info:`${r.s}; locked ${st?.locked}; pending ${pending.join(',')}; time zone ${tz}; accounts besides 9999: ${accounts}`}});
await t('ON-02','A company created without onboarding (every company before R157, and API-created books) is never locked',async()=>{
  const c=await mkCo('R157 Plain '+stamp);const g=need('state',await o.call('onboarding',as(c.id))).onboarding;
  const old=sql(`SELECT id FROM companies WHERE name LIKE 'Alpine Ledger Partners%' LIMIT 1`),go=need('old',await o.call('onboarding',as(old))).onboarding;
  return {ok:!g.required&&!g.locked&&!go.required&&!go.locked,info:`new API company required ${g.required}, locked ${g.locked}; existing company required ${go.required}, locked ${go.locked}`}});
await t('ON-03','Chart of accounts from the standard template: the core accounts and two bank accounts are added; applying it twice is refused',async()=>{
  const r=await o.call('onboarding/chart-template',{method:'POST',...as(OC.id),json:{}});const again=await o.call('onboarding/chart-template',{method:'POST',...as(OC.id),json:{}});
  const codes=sql(`SELECT GROUP_CONCAT(code ORDER BY code) FROM accounts WHERE company_id='${OC.id}' AND code IN ('1000','1100','1200','2000','2100','2110','3000','4000')`);
  const banks=sql(`SELECT GROUP_CONCAT(CONCAT(b.account_type,':',a.code) ORDER BY a.code) FROM bank_accounts b JOIN accounts a ON a.id=b.ledger_account_id WHERE b.company_id='${OC.id}'`);
  return {ok:r.s===201&&Number(r.b.created)>=30&&codes.split(',').length>=7&&banks==='bank:1000,credit_card:2000'&&again.s===409&&again.b.code==='chart_not_empty',info:`${r.s}, ${r.b.created} accounts; core codes ${codes}; bank accounts ${banks}; second time ${again.s} ${again.b.code}`}});
await t('ON-04','Tax codes from a file: a row naming an account that is not in the chart is refused with its row number; a valid file creates each code with its taxes, rates and accounts',async()=>{
  const bad=await o.call('onboarding/tax-import',{method:'POST',...as(OC.id),json:{rows:[{code:'ON-HST',name:'Ontario HST',region:'ON',taxName:'HST',ratePercent:'13',salesAccountCode:'2100',purchaseAccountCode:'1100'},{code:'X',taxName:'X',ratePercent:'5',salesAccountCode:'2999'}]}});
  const rows=[{code:'ON-HST',name:'Ontario HST 13%',region:'ON',taxName:'HST',ratePercent:'13',salesAccountCode:'2100',purchaseAccountCode:'1100',recoverable:'Yes'},{code:'BC-GST-PST',name:'BC GST + PST',region:'BC',taxName:'GST',ratePercent:'5',salesAccountCode:'2100',purchaseAccountCode:'1100',recoverable:'Yes'},{code:'BC-GST-PST',name:'BC GST + PST',region:'BC',taxName:'PST',ratePercent:'7',salesAccountCode:'2110',purchaseAccountCode:'',recoverable:'No'}];
  const good=await o.call('onboarding/tax-import',{method:'POST',...as(OC.id),json:{rows}});
  // Hand-worked: ON-HST = one tax 13.000% (13000 thousandths), sales 2100, purchases 1100; BC = GST 5% (2100/1100) + PST 7% (2110, no purchase account, not recoverable).
  const got=sql(`SELECT GROUP_CONCAT(CONCAT(t.code,'/',c.name,'/',c.rate_mpct,'/',IFNULL(sa.code,'-'),'/',IFNULL(pa.code,'-'),'/',c.purchase_recoverable) ORDER BY t.code,c.sort_order SEPARATOR ' ') FROM tax_codes t JOIN tax_code_components c ON c.tax_code_id=t.id LEFT JOIN accounts sa ON sa.id=c.sales_account_id LEFT JOIN accounts pa ON pa.id=c.purchase_account_id WHERE t.company_id='${OC.id}'`);
  const want='BC-GST-PST/GST/5000/2100/1100/1 BC-GST-PST/PST/7000/2110/-/0 ON-HST/HST/13000/2100/1100/1';
  return {ok:bad.s===422&&/Row 3/.test(bad.b.error||'')&&/2999/.test(bad.b.error||'')&&good.s===201&&got===want,info:`bad file ${bad.s}: "${bad.b.error}"; good file ${good.s}; stored ${got}`}});
let viewer;
await t('ON-05','Only an owner or admin can mark steps; the company step cannot be reopened; other steps can; the last step finishes onboarding, which then cannot be reopened',async()=>{
  await invite(`r157-viewer-${stamp}@gate.test`,'company',[{companyId:OC.id,role:'viewer'}]);viewer=new S();await viewer.login(`r157-viewer-${stamp}@gate.test`,PW);
  const v=await viewer.call('onboarding/step',{method:'POST',...as(OC.id),json:{step:'chart',complete:true}});
  const co=await o.call('onboarding/step',{method:'POST',...as(OC.id),json:{step:'company',complete:false}});
  const done=[];for(const step of ['chart','tax','banks','imports']){const r=await o.call('onboarding/step',{method:'POST',...as(OC.id),json:{step,complete:true}});done.push(r.b.onboarding?.doneCount)}
  const reopen=await o.call('onboarding/step',{method:'POST',...as(OC.id),json:{step:'tax',complete:false}});
  const back=await o.call('onboarding/step',{method:'POST',...as(OC.id),json:{step:'tax',complete:true}});
  const fin=await o.call('onboarding/step',{method:'POST',...as(OC.id),json:{step:'team',complete:true}});
  const after=await o.call('onboarding/step',{method:'POST',...as(OC.id),json:{step:'tax',complete:false}});
  const audit=sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${OC.id}' AND action IN ('onboarding.step_completed','onboarding.completed')`);
  return {ok:v.s===403&&co.s===409&&done.join()==='2,3,4,5'&&reopen.b.onboarding.doneCount===4&&back.b.onboarding.doneCount===5&&fin.b.finished===true&&fin.b.onboarding.locked===false&&after.s===409&&after.b.code==='onboarding_finished'&&Number(audit)>=7,
    info:`viewer ${v.s}; company step reopen ${co.s}; done ${done.join(',')}; reopen tax → ${reopen.b.onboarding.doneCount}; finished ${fin.b.finished}, locked ${fin.b.onboarding.locked}; reopen after finish ${after.s} ${after.b.code}; audit entries ${audit}`}});

// ---------- Time zone and the accounting date ----------
await t('TZ-01','The company time zone: an unknown zone is refused; Company Details saves a new one',async()=>{
  const bad=await o.call('companies',{method:'POST',company:false,json:{name:'R157 Bad TZ '+stamp,...base,timezone:'Mars/Olympus'}});
  const c=await mkCo('R157 TZ '+stamp,{timezone:'America/Halifax'});const put=await o.call('settings',{method:'PUT',...as(c.id),json:{name:'R157 TZ '+stamp,legalName:'R157 TZ '+stamp,businessType:'corporation',province:'ON',country:'Canada',timezone:'America/Edmonton'}});
  const tz=sql(`SELECT timezone FROM companies WHERE id='${c.id}'`);
  return {ok:bad.s===422&&/time zone/i.test(bad.b.error||'')&&put.s<300&&tz==='America/Edmonton',info:`unknown zone ${bad.s} "${bad.b.error}"; update ${put.s} ${put.s>=300?JSON.stringify(put.b).slice(0,120):''}; stored ${tz}`}});
let KIR,TOR;
await t('TZ-02','The accounting date follows the company: on the same moment an invoice dated the UTC+14 company’s today is accepted there, and refused as a future date in a Toronto company when that day has not begun in Toronto or UTC',async()=>{
  KIR=await mkCo('R157 Kiritimati '+stamp,{timezone:'Pacific/Kiritimati'});TOR=await mkCo('R157 Toronto '+stamp,{timezone:'America/Toronto'});
  const day=dateIn('Pacific/Kiritimati'),tor=dateIn('America/Toronto'),utc=dateIn('UTC'),torAllows=day<=[tor,utc].sort().pop();
  const inv=async co=>{const c=need('cust',await o.call('customers',{method:'POST',...as(co.id),json:{name:'TZ Client '+stamp,province:'ON',country:'Canada',paymentTerms:30}})).customer.id;
    return o.call('invoices',{method:'POST',...as(co.id),json:{customerId:c,issueDate:day,dueDate:'2026-12-31',lines:[{description:'Time zone check',quantity:1,unitPriceCents:10000}],issue:true}})};
  const k=await inv(KIR),tt=await inv(TOR);
  return {ok:k.s<300&&(torAllows?tt.s<300:tt.s>=400&&/cannot be in the future/i.test(tt.b.error||'')),info:`server moment ${serverNow().toISOString()}: Kiritimati ${day}, Toronto ${tor}, UTC ${utc}; Kiritimati company ${k.s}; Toronto company ${tt.s} ${tt.b?.error||''} (expected ${torAllows?'accepted':'refused'})`}});

// ---------- Quick Actions follow the person ----------
await t('QA-01','Quick Actions chosen in one company are used in another company of the same person where none were chosen yet',async()=>{
  // A fresh person with two companies of their own, so no earlier choice of theirs exists anywhere.
  await invite(`r157-qa-${stamp}@gate.test`,'workspace');const u=new S();await u.login(`r157-qa-${stamp}@gate.test`,PW);const mk=async n=>need('co',await u.call('companies',{method:'POST',company:false,json:{name:n,...base}})).company;
  const a=await mk('R157 QA A '+stamp),b=await mk('R157 QA B '+stamp),ids=['report.profit_loss','nav.bank_import','nav.customers'];
  const before=need('get',await u.call('agent/interface-preferences',as(b.id))).quickActions?.full;
  need('put',await u.call('agent/interface-preferences',{method:'PUT',...as(a.id),json:{quickActionMode:'full',actionIds:ids}}));
  const g=need('get',await u.call('agent/interface-preferences',as(b.id))).quickActions?.full;
  return {ok:before?.source==='default'&&JSON.stringify(g?.actionIds)===JSON.stringify(ids)&&g.source==='user',info:`company B before: ${before?.source}; after choosing in A: ${JSON.stringify(g)}`}});

// ---------- Browser checks ----------
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const newPage=async(w=1440,h=900)=>{const ctx=await b.newContext({viewport:{width:w,height:h},ignoreHTTPSErrors:true});if(shift>0){await ctx.clock.install({time:Date.now()-shift});await ctx.clock.resume()}const p=await ctx.newPage();p.errs=[];p.on('pageerror',e=>p.errs.push(e.message.slice(0,160)));return p};
const signIn=async(p,email,pw)=>{await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]',email);await p.fill('#sr-login-form input[name=password]',pw);await p.click('#sr-login-form button[type=submit]')};
const pageId=p=>p.evaluate(()=>[...document.querySelectorAll('[data-srp-page]')].map(x=>x.dataset.srpPage).pop()||'');
const allErrs=[];
const email=`r157-new-${stamp}@gate.test`;await invite(email,'workspace');
let p=await newPage();
await t('ON-06','A new user’s first screen is onboarding step 1 (create the company) with all six steps shown; the company step asks for the time zone',async()=>{
  await signIn(p,email,PW);await p.waitForSelector('[data-r157-first-steps]',{timeout:40000});await p.waitForTimeout(800);
  const steps=await p.locator('[data-r157-first-steps] li').allInnerTexts();await p.screenshot({path:'/srv/gate/ev/shots/r157-first.png'});
  await p.click('text=Start Guided');await p.waitForSelector('[data-first-owner-company-form]');const tz=await p.locator('[data-first-owner-company-form] [name=timezone] option').count();
  return {ok:steps.length===6&&/Company/.test(steps[0])&&/Team/.test(steps[5])&&tz>=8,info:`${steps.map(s=>s.replace(/\s+/g,' ')).join(' | ')}; time zone choices ${tz}`}});
await t('ON-07','After the company is created the onboarding page opens; modules are locked (lock badges), a report opens the onboarding page instead, and a setup screen opens with a bar back to setup',async()=>{
  await p.fill('[data-first-owner-company-form] [name=name]','R157 Bakery '+stamp);await p.selectOption('[data-first-owner-company-form] [name=province]','ON');await p.selectOption('[data-first-owner-company-form] [name=timezone]','America/Winnipeg');
  await p.click('[data-first-owner-company-form] button[type=submit]');await p.waitForSelector('[data-r157-onboarding]',{timeout:60000});await p.waitForTimeout(1500);await p.screenshot({path:'/srv/gate/ev/shots/r157-onboarding.png',fullPage:true});
  const first=await pageId(p),locked=await p.evaluate(()=>document.documentElement.hasAttribute('data-r157-locked')),badges=await p.locator('.sidebar .r157-lock-badge').count(),steps=await p.locator('[data-onb-step]').count();
  const tz=sql(`SELECT c.timezone FROM companies c WHERE c.name='R157 Bakery ${stamp}'`),chart=sql(`SELECT COUNT(*) FROM accounts a JOIN companies c ON c.id=a.company_id WHERE c.name='R157 Bakery ${stamp}' AND a.code<>'9999'`);
  await p.evaluate(()=>TeghPortal.invokeMenuAction('Reports','Profit and Loss'));await p.waitForTimeout(2500);const blocked=(await p.locator('.r157-blocked').first().innerText().catch(()=>'')).replace(/\s+/g,' ');const afterReport=await pageId(p);
  await p.evaluate(()=>TeghPortal.openTaxCodes());await p.waitForTimeout(2500);const tax=await pageId(p),bar=(await p.locator('[data-r157-return]').innerText().catch(()=>'')).replace(/\s+/g,' ');await p.screenshot({path:'/srv/gate/ev/shots/r157-return-bar.png'});
  await p.click('[data-r157-back]');await p.waitForTimeout(2000);const back=await pageId(p);
  return {ok:first==='onboarding'&&locked&&badges>=4&&steps===6&&tz==='America/Winnipeg'&&chart==='0'&&afterReport==='onboarding'&&/Profit and Loss opens once setup is finished/.test(blocked)&&tax==='tax-codes'&&/Setting up · Step/.test(bar)&&back==='onboarding',
    info:`first page ${first}; locked ${locked}; lock badges ${badges}; steps ${steps}; time zone ${tz}; chart accounts ${chart}; P&L → ${afterReport} "${blocked.slice(0,70)}"; Tax Codes → ${tax}, bar "${bar.slice(0,60)}"; back → ${back}`}});
await t('ON-08','The chart of accounts step applies the template from the page and then shows what Tegh detects',async()=>{
  await p.click('[data-onb-step="chart"]');await p.waitForTimeout(600);await p.click('[data-onb-option="template"]');await p.waitForTimeout(3000);
  const det=(await p.locator('.r157-detected').innerText().catch(()=>'')).trim(),disabled=await p.locator('[data-onb-option="template"]').isDisabled();
  return {ok:/Tegh sees \d+ accounts/.test(det)&&disabled,info:`"${det}"; template now disabled ${disabled}`}});
await t('ON-09','Signing in again while onboarding is incomplete opens the onboarding page again',async()=>{
  const q=await newPage();await signIn(q,email,PW);await q.waitForSelector('[data-r157-onboarding]',{timeout:40000});const id=await pageId(q);allErrs.push(...q.errs);await q.context().close();return {ok:id==='onboarding',info:`after sign-in: ${id}`}});
await t('ON-10','Settings lists Onboarding and the Tegh tour',async()=>{const items=await p.evaluate(()=>TeghPortal.getModuleNavigationSections?Object.values(TeghPortal.getModuleNavigationSections('My account')||{}).flat().map(x=>x.name):[]);
  const reg=await p.evaluate(()=>['Onboarding','Take the Tegh Tour'].map(n=>{try{return typeof TeghPortal.invokeMenuAction==='function'}catch{return false}}));
  await p.evaluate(()=>TeghPortal.invokeMenuAction('My account','Onboarding'));await p.waitForTimeout(1800);const id=await pageId(p);return {ok:id==='onboarding'&&reg.every(Boolean),info:`Settings › Onboarding opens ${id}`}});
await t('ON-11','Marking the remaining steps complete on the page finishes onboarding and unlocks Tegh',async()=>{
  for(const step of ['chart','tax','banks','imports','team']){await p.click(`[data-onb-step="${step}"]`);await p.waitForTimeout(400);if(await p.locator('[data-onb-toggle="complete"]').count()){await p.click('[data-onb-toggle="complete"]');await p.waitForTimeout(1300)}}
  await p.waitForTimeout(800);await p.screenshot({path:'/srv/gate/ev/shots/r157-finished.png'});const fin=await p.locator('.r157-finished').count(),locked=await p.evaluate(()=>document.documentElement.hasAttribute('data-r157-locked')),badges=await p.locator('.sidebar .r157-lock-badge').count();
  const db=sql(`SELECT o.completed_at IS NOT NULL FROM company_onboarding o JOIN companies c ON c.id=o.company_id WHERE c.name='R157 Bakery ${stamp}'`);
  return {ok:fin===1&&!locked&&badges===0&&db==='1',info:`finished card ${fin}; locked ${locked}; lock badges ${badges}; completed in database ${db}`}});
await t('ON-12','The Tegh Assist tour spotlights the real menus step by step and ends with the bank statement call to action, which opens Upload Statement',async()=>{
  await p.click('[data-onb-tour]');await p.waitForSelector('[data-r157-tour] .r157-tour-card',{timeout:15000});await p.waitForTimeout(900);
  const stops=[];for(let i=0;i<14;i++){if(await p.locator('[data-tour-bank]').count())break;stops.push(await p.evaluate(()=>{const spot=document.querySelector('.r157-tour-spot');return (document.querySelector('#r157-tour-title')?.textContent||'')+(spot&&!spot.hidden?'*':'')}));if(i===2)await p.screenshot({path:'/srv/gate/ev/shots/r157-tour.png'});await p.click('[data-tour-next]');await p.waitForTimeout(600)}
  await p.waitForTimeout(600);await p.screenshot({path:'/srv/gate/ev/shots/r157-tour-final.png'});const final=(await p.locator('.r157-tour-final h2').innerText().catch(()=>''));
  await p.click('[data-tour-bank]');await p.waitForTimeout(3000);const id=await pageId(p);const tour=sql(`SELECT o.tour_done_at IS NOT NULL FROM company_onboarding o JOIN companies c ON c.id=o.company_id WHERE c.name='R157 Bakery ${stamp}'`);
  const spotlit=stops.filter(s=>s.endsWith('*')).length;
  return {ok:stops.length>=7&&spotlit>=6&&/bank statement/i.test(final)&&id==='bank-imports'&&tour==='1',info:`${stops.length} stops (${spotlit} spotlit): ${stops.join(' → ')}; final "${final}"; button opens ${id}; tour recorded ${tour}`}});
await t('ON-13','Signing in after onboarding opens Home (not onboarding), nothing is locked, and the animated “start with a bank statement import” card is shown while there are no bank lines',async()=>{
  const q=await newPage();await signIn(q,email,PW);await q.waitForFunction(()=>window.TeghPortal&&document.querySelector('[data-srp-page]'),null,{timeout:40000});await q.waitForTimeout(4500);
  const id=await pageId(q),locked=await q.evaluate(()=>document.documentElement.hasAttribute('data-r157-locked')),card=await q.locator('[data-r157-bank-start]').count();await q.screenshot({path:'/srv/gate/ev/shots/r157-home-bank-start.png'});
  allErrs.push(...q.errs);await q.context().close();return {ok:['dashboard','guided-bookkeeping'].includes(id)&&!locked&&card===1,info:`after sign-in ${id}; locked ${locked}; bank card ${card}`}});
allErrs.push(...p.errs);await p.context().close();

// Owner, existing books (not locked): accounting date in the browser, Quick Action keys, Beta labels, vendor invoice intake.
p=await newPage();await signIn(p,'owner@gate.test','Gate!Owner#2026pw');await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await p.waitForTimeout(2500);
const select=async id=>{await p.evaluate(id=>{localStorage.setItem('sr-accountax-company',id);localStorage.setItem('sr-accountax-companies',JSON.stringify([id]))},id);await p.goto('https://gate.test/app.html');await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await p.waitForTimeout(2500)};
await t('TZ-03','In the browser a company’s “today” (new invoice date) is its own date: the UTC+14 company shows its date, the Toronto company Toronto’s',async()=>{
  const want={k:dateIn('Pacific/Kiritimati'),t:dateIn('America/Toronto')};const read=async()=>{await p.evaluate(()=>TeghPortal.openDocumentEditor('customer',{kind:'invoice'}));await p.waitForSelector('[data-r151-form] [name=date]',{timeout:20000});await p.waitForTimeout(600);return p.inputValue('[data-r151-form] [name=date]')};
  await select(KIR.id);const k=await read();await select(TOR.id);const tt=await read();
  return {ok:k===want.k&&tt===want.t,info:`UTC+14 company ${k} (expected ${want.k}); Toronto company ${tt} (expected ${want.t})`}});
const QAC=await mkCo('R157 Keys '+stamp);
await t('QA-02','Each Home shortcut shows its keyboard keys, and Ctrl+Alt+2 opens the second shortcut from another page',async()=>{
  need('put',await o.call('agent/interface-preferences',{method:'PUT',...as(QAC.id),json:{quickActionMode:'full',actionIds:['report.profit_loss','nav.trial_balance','nav.customers']}}));
  await select(QAC.id);await p.evaluate(()=>TeghPortal.openDashboard());await p.waitForTimeout(3000);
  const keys=await p.locator('[data-sites-quick] .r157-quick-key').allInnerTexts();const labels=await p.locator('[data-sites-quick] .r157-quick-label strong').allInnerTexts();await p.locator('[data-dashboard-slot="quick-actions"]').screenshot({path:'/srv/gate/ev/shots/r157-shortcuts.png'}).catch(()=>{});
  await p.evaluate(()=>TeghPortal.openCustomers());await p.waitForTimeout(1500);await p.click('.topbar');await p.keyboard.press('Control+Alt+2');await p.waitForTimeout(3000);const id=await pageId(p),h1=await p.evaluate(()=>[...document.querySelectorAll('.srp-page-head h1')].pop()?.textContent||'');
  return {ok:keys.length===3&&keys.every((k,i)=>k.replace(/\s+/g,'').endsWith(String(i+1)))&&/Ctrl|⌃/.test(keys[0])&&/trial balance/i.test(h1),info:`keys ${keys.map(k=>k.replace(/\s+/g,'+')).join(', ')}; labels ${labels.join(', ')}; Ctrl+Alt+2 from Customers opened ${id} "${h1}"`}});
await t('BE-01','Document Intake and Upload Statement are marked Beta with a reminder to check what is read before relying on it',async()=>{
  const c=await mkCo('R157 Intake '+stamp);await select(c.id);const out=[];
  for(const [open,id] of [['openDocumentIntake','document-intake'],['openBankImport','bank-imports']]){await p.evaluate(f=>TeghPortal[f](),open);await p.waitForTimeout(3000);
    out.push({id,badge:await p.locator(`[data-srp-page="${id}"] .srp-page-head .r157-beta-badge`).count(),note:(await p.locator(`[data-srp-page="${id}"] [data-r157-beta-note]`).innerText().catch(()=>'')).replace(/\s+/g,' ')});await p.screenshot({path:`/srv/gate/ev/shots/r157-beta-${id}.png`})}
  return {ok:out.every(x=>x.badge===1&&/^Beta\. .*[Cc]heck/.test(x.note)),info:out.map(x=>`${x.id}: badge ${x.badge}, "${x.note.slice(0,80)}"`).join('; ')}});
await t('BE-02','A new vendor invoice has a Document Intake section marked Beta; choosing a file sends it through Document Intake, which reads it and offers “Create vendor invoice”',async()=>{
  const cid=await p.evaluate(()=>localStorage.getItem('sr-accountax-company'));const before=Number(sql(`SELECT COUNT(*) FROM native_agent_documents WHERE company_id='${cid}'`));
  await p.evaluate(()=>TeghPortal.openBills('new'));await p.waitForSelector('[data-r157-vendor-intake]',{timeout:20000});await p.waitForTimeout(600);
  const above=await p.evaluate(()=>{const s=document.querySelector('[data-r157-vendor-intake]'),f=document.querySelector('[data-r151-form]');return !!(s&&f&&(s.compareDocumentPosition(f)&Node.DOCUMENT_POSITION_FOLLOWING))});const beta=await p.locator('[data-r157-vendor-intake] .r157-beta-badge').count();await p.screenshot({path:'/srv/gate/ev/shots/r157-vendor-intake.png'});
  await p.setInputFiles('[data-r157-intake-file]','/srv/gate/t/conv/ix152/V1.pdf');let offered=0;for(let i=0;i<40&&!offered;i++){await p.waitForTimeout(1500);offered=await p.locator('text=/Create vendor invoice/i').count()}
  const after=Number(sql(`SELECT COUNT(*) FROM native_agent_documents WHERE company_id='${cid}'`)),id=await pageId(p);await p.screenshot({path:'/srv/gate/ev/shots/r157-vendor-intake-read.png'});
  return {ok:above&&beta===1&&after===before+1&&id==='document-intake'&&offered>0,info:`section above the form ${above}, Beta ${beta}; documents ${before} → ${after}; now on ${id}; "Create vendor invoice" offered ${offered>0}`}});
allErrs.push(...p.errs);await b.close();
rec('R157-JS',A,'No page errors during the R157 checks',allErrs.length?'FAIL':'PASS',allErrs.slice(0,3).join(' | '));
save('r157.json');
