// R154: with several companies selected, the General Ledger Account Report, the Bank General Ledger Report and Budgets
// run for every selected company, each line labelled with its company.
// Expected figures are worked out here from the documents this test creates, never with Tegh's code.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';import {execFileSync} from 'child_process';import {S,sql,rec,need,save} from './lib.mjs';
const A='R154';const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');const stamp=Date.now().toString(36);
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info||'')}catch(e){rec(id,A,title,'FAIL',e.message.replace(/\s+/g,' ').slice(0,300))}};
const as=co=>({headers:{'X-Company-Id':co.id},company:false});
const mkCo=async(name,shortName)=>need('co',await o.call('companies',{method:'POST',company:false,json:{name,shortName,province:'ON',country:'Canada',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'accounting',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',taxRegistered:false,coaMode:'default'}})).company;
const acct=(co,code)=>sql(`SELECT id FROM accounts WHERE company_id='${co.id}' AND code='${code}'`);

// ---------- Data (hand-worked figures) ----------
// GLA: invoice $1,000.00, customer pays $600.00 into its bank account (GL 1015); budget on 4000 planned $1,200.00.
// GLB: invoice $250.00, paid in full into its bank account (GL 1015); budget on 4000 planned $300.00.
// GL 4000 (income) closes at $1,000.00 CR (GLA) and $250.00 CR (GLB): combined $1,250.00 CR.
// GL 1015 (bank) closes at $600.00 DR (GLA) and $250.00 DR (GLB): combined $850.00 DR.
// Budget vs actual on 4000: GLA planned 1,200.00 actual 1,000.00; GLB planned 300.00 actual 250.00; planned together 1,500.00, actual 1,250.00.
// Only GLA has account 6990, so the report for 6990 lists GLA and says GLB has no such account.
const CA=await mkCo('Granite Lake Advisors '+stamp,'GLA'),CB=await mkCo('Golden Lynx Bakery '+stamp,'GLB');
const setup=async(co,invoiceCents,paidCents,plannedCents)=>{
  need('bank',await o.call('bank-accounts',{method:'POST',...as(co),json:{name:'Operating Chequing',currency:'CAD',accountType:'bank',type:'bank',ledgerCode:'1015',ledgerName:'Operating Chequing'}}));
  const c=need('cust',await o.call('customers',{method:'POST',...as(co),json:{name:'Client '+stamp,province:'ON',country:'Canada',paymentTerms:30}})).customer.id;
  const inv=need('inv',await o.call('invoices',{method:'POST',...as(co),json:{customerId:c,issueDate:'2026-09-10',dueDate:'2026-10-10',lines:[{description:'Services',quantity:1,unitPriceCents:invoiceCents}],issue:true}})).invoice;
  need('pay',await o.call('payments',{method:'POST',...as(co),json:{type:'customer',documentId:inv.id,paymentDate:'2026-09-20',reference:'EFT '+stamp,foreignAmountCents:paidCents,paymentAccountId:acct(co,'1015'),operationKey:'r154-'+co.id}}));
  need('budget',await o.call('advanced/budgets',{method:'POST',...as(co),json:{name:'FY2026 plan '+stamp,periodStart:'2026-01-01',periodEnd:'2026-12-31',lines:[{accountId:acct(co,'4000'),analyticAccountId:null,plannedCents}]}}));
};
await setup(CA,100000,60000,120000);await setup(CB,25000,25000,30000);
need('acct',await o.call('accounts',{method:'POST',...as(CA),json:{code:'6990',name:'R154 Only In GLA',type:'expense'}}));

const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true,acceptDownloads:true});const p=await ctx.newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,160)));
await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
const ready=()=>p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await ready();
const select=async ids=>{await p.evaluate(ids=>{localStorage.setItem('sr-accountax-company',ids[0]);localStorage.setItem('sr-accountax-companies',JSON.stringify(ids))},ids);await p.goto('https://gate.test/app.html');await ready();await p.waitForTimeout(800)};
// The combined report drawn below the screen (the authoritative report table): headings, the "Co." value per line, its text.
const output=async()=>{await p.waitForFunction(()=>document.querySelector('.srp-page [data-tegh-authoritative-report] table tbody tr, .srp-page [data-tegh-report-error], .srp-page .srp-report-error'),null,{timeout:30000}).catch(()=>{});await p.waitForTimeout(800);
  return p.evaluate(()=>{const host=document.querySelector('.srp-page [data-tegh-authoritative-report]');const tb=host?.querySelector('table');if(!tb)return {head:[],cos:[],text:host?.innerText||document.querySelector('.srp-page')?.innerText.slice(0,400)||''};
    const head=[...tb.querySelectorAll('thead th')].map(x=>x.textContent.replace(/[⋮⌄↑↓]/g,'').trim()),i=head.indexOf('Co.'),rows=[...tb.querySelectorAll('tbody tr')];
    return {head,cos:i<0?[]:rows.map(tr=>tr.cells[i]?.textContent.trim()||'').filter(c=>c&&!/^Total /.test(c)),text:host.innerText}})};
const pageText=()=>p.locator('.srp-page:visible').first().innerText();
const run=async(menu,item)=>{await p.evaluate(([m,i])=>TeghPortal.invokeMenuAction(m,i),[menu,item]);await p.waitForTimeout(2500)};

await select([CA.id,CB.id]);

await t('GL-01','GL Account Report with both companies: choosing account 4000 shows each company\'s balance and the combined closing balance of $1,250.00 CR',async()=>{
  await run('General ledger','General Ledger Account Report');const f=p.locator('[data-gl-ledger-filter]');await f.waitFor({timeout:20000});
  await f.locator('select[name=code]').selectOption('4000');await f.locator('select[name=preset]').selectOption('fiscal-ytd').catch(()=>{});await f.locator('input[name=start]').fill('2026-01-01');await f.locator('input[name=end]').fill('2026-12-31');await f.locator('button.srp-btn').click();
  await p.locator('[data-r154-company-summary]').waitFor({timeout:20000});
  const rows=await p.locator('[data-r154-company-summary] tbody tr').evaluateAll(trs=>trs.map(tr=>[...tr.cells].map(td=>td.textContent.trim())));
  const by=Object.fromEntries(rows.map(r=>[r[0]||r[1],r[5]]));const text=await pageText();
  await p.screenshot({path:'/srv/gate/ev/shots/r154-gl-account.png'});
  return {ok:/1,000\.00 CR/.test(by.GLA||'')&&/250\.00 CR/.test(by.GLB||'')&&/1,250\.00 CR/.test(by.Combined||'')&&!/Select one company/i.test(text),info:JSON.stringify(by)}});
await t('GL-02','The ledger below the summary lists both companies\' lines, each labelled GLA or GLB, grouped as "GLA · 4000 …" and "GLB · 4000 …"',async()=>{const r=await output();
  return {ok:r.head.includes('Co.')&&r.cos.includes('GLA')&&r.cos.includes('GLB')&&/GLA · 4000/.test(r.text)&&/GLB · 4000/.test(r.text),info:`${JSON.stringify([...new Set(r.cos)])} | ${(r.text.match(/GL[AB] · 4000[^\n]*/g)||[]).slice(0,2).join(' / ')}`}});
await t('GL-03','An account only one company has (6990) runs for that company and says the other has no such account',async()=>{
  const f=p.locator('[data-gl-ledger-filter]');await f.locator('select[name=code]').selectOption('6990');await f.locator('button.srp-btn').click();await p.waitForTimeout(2500);
  const text=await pageText(),rows=await p.locator('[data-r154-company-summary] tbody tr').count();
  return {ok:rows===2&&/Not in GLB/.test(text)&&/no account 6990/.test(text),info:`${rows} summary rows; ${(text.match(/Not in[^\n]*/)||[''])[0]}`}});

await t('BL-01','Bank General Ledger Report with both companies: one bank account per company; lines labelled GLA and GLB; the receipts of $600.00 and $250.00 both appear',async()=>{
  await run('Banking','Bank General Ledger Report');const f=p.locator('[data-bank-ledger-report-filter]');await f.waitFor({timeout:20000});
  const pickers=await f.locator('[data-r154-bank]').count();for(const co of [CA,CB])await f.locator(`[data-r154-bank="${co.id}"]`).selectOption({label:'Operating Chequing'});await f.locator('input[name=start]').fill('2026-01-01');await f.locator('input[name=end]').fill('2026-12-31');await f.locator('button.srp-btn').click();
  const r=await output();await p.screenshot({path:'/srv/gate/ev/shots/r154-bank-ledger.png'});
  return {ok:pickers===2&&r.cos.includes('GLA')&&r.cos.includes('GLB')&&/600\.00/.test(r.text)&&/250\.00/.test(r.text)&&!/Select one company/i.test(await pageText()),info:`${pickers} bank pickers; ${JSON.stringify([...new Set(r.cos)])}`}});
await t('BL-02','Leaving one company out ("Not included") runs the report for the other company only',async()=>{
  const f=p.locator('[data-bank-ledger-report-filter]');await f.locator(`[data-r154-bank="${CB.id}"]`).selectOption('');await f.locator('button.srp-btn').click();const r=await output();
  return {ok:r.cos.length>0&&r.cos.every(c=>c==='GLA'),info:JSON.stringify([...new Set(r.cos)])}});

await t('BU-01','Budgets with both companies: every company\'s budget is listed with its company; planned $1,500.00 and actual $1,250.00 together',async()=>{
  await run('Advanced accounting','Budgets');await p.locator('[data-r154-budget-list]').waitFor({timeout:20000});
  const list=await p.locator('[data-r154-budget-list] tbody tr').evaluateAll(trs=>trs.map(tr=>[...tr.cells].map(td=>td.textContent.trim())));
  const combined=list.find(r=>/^Combined/.test(r.join('').trim()))||[],budgets=list.filter(r=>r[0]==='GLA'||r[0]==='GLB');await p.screenshot({path:'/srv/gate/ev/shots/r154-budgets.png'});
  return {ok:budgets.length===2&&budgets.some(r=>r[0]==='GLA'&&/1,200\.00/.test(r.join(' ')))&&budgets.some(r=>r[0]==='GLB'&&/300\.00/.test(r.join(' ')))&&/1,500\.00/.test(combined.join(' '))&&/1,250\.00/.test(combined.join(' ')),info:`${budgets.map(r=>r.slice(0,2).join(':')).join(', ')} | ${combined.filter(Boolean).join(' | ')}`}});
await t('BU-02','The combined Budget versus Actual lists account 4000 once per company: GLA planned 1,200.00 / actual 1,000.00, GLB 300.00 / 250.00',async()=>{const r=await output();
  const lines=r.text.split('\n').filter(l=>/4000/.test(l));
  return {ok:r.cos.includes('GLA')&&r.cos.includes('GLB')&&lines.some(l=>/GLA/.test(l)&&/1,200\.00/.test(l)&&/1,000\.00/.test(l))&&lines.some(l=>/GLB/.test(l)&&/300\.00/.test(l)&&/250\.00/.test(l)),info:lines.slice(0,2).join(' / ').replace(/\t/g,' ')}});
await t('EX-01','The Excel export of the combined Budget versus Actual contains the Co. column and both companies\' lines',async()=>{let file='';
  await p.locator('button[aria-label="Table actions"]:visible').first().click();await p.locator('[role=menu] button:visible,[role=menuitem]:visible,.r23-menu button:visible',{hasText:/^Export/}).first().click();
  const dialog=p.locator('dialog[open]').last();await dialog.locator('select').first().selectOption('xlsx');
  const dl=p.waitForEvent('download',{timeout:20000});await dialog.locator('button',{hasText:/^Export$/}).click();const d=await dl;file='/tmp/r154-export.xlsx';await d.saveAs(file);
  const xml=execFileSync('unzip',['-p',file],{encoding:'latin1',maxBuffer:50e6});fs.unlinkSync(file);return {ok:/>Co\.</.test(xml)&&xml.includes('GLA')&&xml.includes('GLB'),info:`${d.suggestedFilename()}: Co. ${/>Co\.</.test(xml)}, GLA ${xml.includes('GLA')}, GLB ${xml.includes('GLB')}`}});
await t('BU-03','Creating or editing a budget still needs one company; the combined page says so and has no create form',async()=>{const text=await pageText(),form=await p.locator('#sra-budget-form').count();
  return {ok:/choose that company in the company selector/i.test(text)&&form===0,info:`create form ${form}`}});

await t('ONE-01','With one company selected the GL Account Report, Bank GL Report and Budgets are unchanged (no Co. column, no company pickers)',async()=>{await select([CA.id]);
  await run('General ledger','General Ledger Account Report');const gl=await p.locator('[data-gl-ledger-filter] select[name=accountId]').count();
  await run('Banking','Bank General Ledger Report');const bank=await p.locator('[data-r154-bank]').count();
  await run('Advanced accounting','Budgets');await p.waitForTimeout(1500);const list=await p.locator('[data-r154-budget-list]').count(),own=await p.locator('.srp-advanced-host').count();
  return {ok:gl===1&&bank===0&&list===0&&own===1,info:`GL account picker ${gl}; bank pickers ${bank}; combined budget list ${list}; budgets workspace ${own}`}});
rec('R154-JS',A,'No page errors during the R154 journeys',errs.length?'FAIL':'PASS',errs.slice(0,3).join(' | '));
await b.close();save('r154.json');
